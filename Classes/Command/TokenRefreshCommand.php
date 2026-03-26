<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Provider\ProviderRegistry;
use WapplerSystems\OauthService\Provider\Type\ProviderTypeResolver;
use WapplerSystems\OauthService\Service\NotificationService;

#[AsCommand(
    name: 'oauth-service:refresh-tokens',
    description: 'Erneuert ablaufende oder abgelaufene OAuth Access-Tokens über den Refresh-Token-Flow.',
)]
final class TokenRefreshCommand extends Command
{
    public function __construct(
        private readonly ConnectionRepository $connectionRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderTypeResolver $providerTypeResolver,
        private readonly CryptoService $cryptoService,
        private readonly NotificationService $notificationService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_OPTIONAL,
                'Tokens refreshen die in weniger als X Sekunden ablaufen (0 = nur bereits abgelaufene).',
                null,
            )
            ->addOption(
                'uid',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nur eine bestimmte Verbindung refreshen (UID).',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Alle Verbindungen refreshen, unabhängig vom Ablaufzeitpunkt.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $extConf         = $this->extensionConfiguration->get('oauth_service') ?? [];
        $warningEmail    = trim((string)($extConf['warningEmail'] ?? ''));
        $debounceMinutes = max(1, (int)($extConf['debounceMinutes'] ?? 360));

        $force = (bool)$input->getOption('force');
        $uid   = $input->getOption('uid') !== null ? (int)$input->getOption('uid') : null;

        if ($input->getOption('threshold') !== null) {
            $thresholdSeconds = (int)$input->getOption('threshold');
        } else {
            $thresholdSeconds = $force
                ? PHP_INT_MAX
                : (int)($extConf['thresholdSeconds'] ?? 300);
        }

        $connections = $this->connectionRepository->findForRefresh($thresholdSeconds, $uid);

        if (empty($connections)) {
            $io->success('Keine Verbindungen gefunden, die einen Refresh benötigen.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>%d Verbindung(en) werden geprüft …</info>', count($connections)));

        $refreshed = 0;
        $failed    = 0;

        foreach ($connections as $conn) {
            $connUid  = (int)$conn['uid'];
            $label    = ($conn['label'] ?? '') ?: ('#' . $connUid);

            /** @var \WapplerSystems\OauthService\Domain\Model\Client|null $client */
            $client = $this->clientRepository->findByUid((int)$conn['client']);

            if ($client === null || !$client->getIsActive()) {
                $io->writeln(sprintf('  <comment>→ "%s": Client nicht gefunden oder inaktiv, übersprungen.</comment>', $label));
                continue;
            }

            $refreshToken = $this->cryptoService->decrypt($conn['refresh_token'] ?? '') ?? '';
            if ($refreshToken === '') {
                $io->writeln(sprintf('  <comment>→ "%s": Kein Refresh-Token vorhanden, übersprungen.</comment>', $label));
                continue;
            }

            try {
                $providerDefinition = $this->providerRegistry->get((string)$client->getProvider());
                $providerType       = $this->providerTypeResolver->resolve($providerDefinition->type);

                if (!$providerType->supportsRefresh()) {
                    $io->writeln(sprintf('  <comment>→ "%s": Provider unterstützt kein Refresh, übersprungen.</comment>', $label));
                    continue;
                }

                $token = $providerType->refreshToken($client, $refreshToken);

                $newExpiresAt = 0;
                if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
                    $newExpiresAt = time() + (int)$token['expires_in'];
                }

                $this->connectionRepository->update($connUid, [
                    'status'               => 'connected',
                    'access_token'         => $this->cryptoService->encrypt((string)$token['access_token']),
                    'refresh_token'        => $this->cryptoService->encrypt((string)($token['refresh_token'] ?? $refreshToken)),
                    'token_type'           => (string)($token['token_type'] ?? ''),
                    'access_token_expires_at' => $newExpiresAt,
                    'last_refresh_at'      => time(),
                    'last_error_code'      => '',
                    'last_error_message'   => '',
                ]);

                $expiresStr = $newExpiresAt > 0 ? date('d.m.Y H:i', $newExpiresAt) : '—';
                $io->writeln(sprintf('  <info>✓ "%s": Token erfolgreich erneuert (läuft ab: %s).</info>', $label, $expiresStr));
                $refreshed++;

            } catch (\Throwable $e) {
                $this->connectionRepository->update($connUid, [
                    'status'             => 'error',
                    'last_error_code'    => 'refresh_failed',
                    'last_error_message' => $e->getMessage(),
                ]);

                $io->writeln(sprintf('  <error>✗ "%s": Refresh fehlgeschlagen – %s</error>', $label, $e->getMessage()));
                $failed++;

                $this->sendFailureNotification(
                    $warningEmail,
                    $client->getTitle() ?? '',
                    $label,
                    $connUid,
                    $e->getMessage(),
                    $debounceMinutes,
                    (int)($conn['last_notified_at'] ?? 0),
                );
            }
        }

        $io->newLine();
        $io->success(sprintf('%d erneuert, %d fehlgeschlagen.', $refreshed, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function sendFailureNotification(
        string $email,
        string $clientTitle,
        string $connectionLabel,
        int $connectionUid,
        string $errorMessage,
        int $debounceMinutes,
        int $lastNotifiedAt,
    ): void {
        if ($email === '') {
            return;
        }

        $debounceSeconds = $debounceMinutes * 60;
        if ($lastNotifiedAt > 0 && (time() - $lastNotifiedAt) < $debounceSeconds) {
            return;
        }

        $subject = sprintf('[OAuth] Token-Refresh fehlgeschlagen: %s', $connectionLabel);
        $body    = implode("\n", [
            'Beim automatischen Token-Refresh ist ein Fehler aufgetreten.',
            '',
            'Client:      ' . $clientTitle,
            'Verbindung:  ' . $connectionLabel,
            'UID:         ' . $connectionUid,
            'Fehler:      ' . $errorMessage,
            'Zeit:        ' . date('d.m.Y H:i:s'),
        ]);

        $this->notificationService->sendFailureMail($email, $subject, $body);

        $this->connectionRepository->update($connectionUid, ['last_notified_at' => time()]);
    }
}