<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Provider\ProviderRegistry;

#[AsCommand(
    name: 'oauth-service:create-client',
    description: 'Create a new OAuth client headlessly. Use --secret-stdin to keep the secret out of shell history.',
)]
final class CreateClientCommand extends Command
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ProviderRegistry $providerRegistry,
        private readonly CryptoService $cryptoService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider identifier (must be registered, e.g. microsoft_graph)')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Client ID from the provider')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Client secret (prefer --secret-stdin or the interactive prompt)')
            ->addOption('secret-stdin', null, InputOption::VALUE_NONE, 'Read client secret from STDIN (one line)')
            ->addOption('metadata', null, InputOption::VALUE_REQUIRED, 'Metadata JSON object', '')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Space- or comma-separated scopes', '')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Create as inactive (default: active)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $provider = (string)$input->getOption('provider');
        $clientId = (string)$input->getOption('client-id');
        $metadataRaw = (string)$input->getOption('metadata');
        $scopes = (string)$input->getOption('scopes');
        $isActive = !$input->getOption('inactive');

        if ($provider === '' || $clientId === '') {
            $io->error('--provider and --client-id are required.');
            return Command::FAILURE;
        }
        if ($this->providerRegistry->get($provider) === null) {
            $io->error(sprintf('Provider "%s" is not registered. Make sure the providing extension is installed and active.', $provider));
            return Command::FAILURE;
        }

        if ($metadataRaw !== '') {
            $decoded = json_decode($metadataRaw, true);
            if (!is_array($decoded)) {
                $io->error('--metadata is not valid JSON: ' . (json_last_error_msg() ?: 'unknown'));
                return Command::FAILURE;
            }
        }

        $secret = (string)$input->getOption('secret');
        if ($input->getOption('secret-stdin')) {
            $stdin = trim((string)fgets(STDIN));
            if ($stdin !== '') {
                $secret = $stdin;
            }
        }
        if ($secret === '') {
            $question = new Question(sprintf('client_secret for new %s client:', $provider));
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $secret = (string)$io->askQuestion($question);
        }
        if ($secret === '') {
            $io->error('No secret provided. Aborted.');
            return Command::FAILURE;
        }

        $encrypted = $this->cryptoService->encrypt($secret) ?? $secret;
        $now = time();
        $conn = $this->connectionPool->getConnectionForTable(self::CLIENT_TABLE);
        $conn->insert(
            self::CLIENT_TABLE,
            [
                'pid' => 0,
                'provider' => $provider,
                'client_id' => $clientId,
                'client_secret' => $encrypted,
                'scopes' => $scopes,
                'metadata' => $metadataRaw !== '' ? $metadataRaw : null,
                'is_active' => $isActive ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'pid' => ParameterType::INTEGER,
                'is_active' => ParameterType::INTEGER,
                'created_at' => ParameterType::INTEGER,
                'updated_at' => ParameterType::INTEGER,
            ]
        );
        $uid = (int)$conn->lastInsertId();

        $io->success(sprintf('Client #%d (%s, %s) created%s.', $uid, $provider, $clientId, $isActive ? '' : ' (inactive)'));
        $io->writeln('Use "typo3 oauth-service:test-connection ' . $provider . '" to verify the credentials work.');
        return Command::SUCCESS;
    }
}
