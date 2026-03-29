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
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
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
            ->setDescription($this->translate('refresh.description'))
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_OPTIONAL,
                $this->translate('refresh.option.threshold'),
                null,
            )
            ->addOption(
                'uid',
                null,
                InputOption::VALUE_OPTIONAL,
                $this->translate('refresh.option.uid'),
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                $this->translate('refresh.option.force'),
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
            $io->success($this->translate('refresh.success.noConnections'));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>%s</info>', $this->translate('refresh.info.checking', [count($connections)])));

        $refreshed = 0;
        $failed    = 0;

        foreach ($connections as $conn) {
            $connUid  = (int)$conn['uid'];
            $label    = ($conn['label'] ?? '') ?: ('#' . $connUid);

            /** @var \WapplerSystems\OauthService\Domain\Model\Client|null $client */
            $client = $this->clientRepository->findByUid((int)$conn['client']);

            if ($client === null || !$client->getIsActive()) {
                $io->writeln(sprintf('  <comment>%s</comment>', $this->translate('refresh.skip.clientInactive', [$label])));
                continue;
            }

            $expiresAt = (int)($conn['access_token_expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= time()) {
                $this->connectionRepository->updateFields($connUid, ['status' => 'expired']);
            }

            $refreshToken = $this->cryptoService->decrypt($conn['refresh_token'] ?? '') ?? '';
            if ($refreshToken === '') {
                $io->writeln(sprintf('  <comment>%s</comment>', $this->translate('refresh.skip.noRefreshToken', [$label])));
                continue;
            }

            try {
                $providerDefinition = $this->providerRegistry->get((string)$client->getProvider());
                $providerType       = $this->providerTypeResolver->resolve($providerDefinition->type);

                if (!$providerType->supportsRefresh()) {
                    $io->writeln(sprintf('  <comment>%s</comment>', $this->translate('refresh.skip.noRefreshSupport', [$label])));
                    continue;
                }

                $token = $providerType->refreshToken($client, $refreshToken);

                $newExpiresAt = 0;
                if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
                    $newExpiresAt = time() + (int)$token['expires_in'];
                }

                $this->connectionRepository->updateFields($connUid, [
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
                $io->writeln(sprintf('  <info>%s</info>', $this->translate('refresh.success.renewed', [$label, $expiresStr])));
                $refreshed++;

            } catch (\Throwable $e) {
                $this->connectionRepository->updateFields($connUid, [
                    'status'             => 'error',
                    'last_error_code'    => 'refresh_failed',
                    'last_error_message' => $e->getMessage(),
                ]);

                $io->writeln(sprintf('  <error>%s</error>', $this->translate('refresh.error.failed', [$label, $e->getMessage()])));
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
        $io->success($this->translate('refresh.success.summary', [$refreshed, $failed]));

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

        $subject = $this->translate('refresh.email.subject', [$connectionLabel]);
        $body    = implode("\n", [
            $this->translate('refresh.email.intro'),
            '',
            $this->translate('refresh.email.label.client') . $clientTitle,
            $this->translate('refresh.email.label.connection') . $connectionLabel,
            $this->translate('refresh.email.label.uid') . $connectionUid,
            $this->translate('refresh.email.label.error') . $errorMessage,
            $this->translate('refresh.email.label.time') . date('d.m.Y H:i:s'),
        ]);

        $this->notificationService->sendFailureMail($email, $subject, $body);

        $this->connectionRepository->updateFields($connectionUid, ['last_notified_at' => time()]);
    }

    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate(
            'LLL:EXT:oauth_service/Resources/Private/Language/locallang_cmd.xlf:' . $key,
            'OauthService',
            $arguments !== [] ? $arguments : null,
        ) ?? $key;
    }
}