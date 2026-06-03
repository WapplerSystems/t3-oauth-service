<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:set-secret',
    description: 'Rotate the client_secret of an OAuth client. Prompts for the new secret without echoing it to the terminal.',
)]
final class SetSecretCommand extends Command
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CryptoService $cryptoService,
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uid', InputArgument::REQUIRED, 'Client UID')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'New client secret (use only in CI; otherwise prefer the interactive prompt to avoid leaking the secret to shell history)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getArgument('uid');

        $conn = $this->connectionPool->getConnectionForTable(self::CLIENT_TABLE);
        $row = $conn->select(['uid', 'provider', 'client_id'], self::CLIENT_TABLE, ['uid' => $uid])->fetchAssociative();
        if (!is_array($row)) {
            $io->error(sprintf('Client #%d not found.', $uid));
            return Command::FAILURE;
        }

        $secret = $input->getOption('secret');
        if (!is_string($secret) || $secret === '') {
            $question = new Question(sprintf('New client_secret for client #%d (%s):', $uid, $row['provider']));
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $secret = $io->askQuestion($question);
        }
        if (!is_string($secret) || $secret === '') {
            $io->error('No secret provided. Aborted.');
            return Command::FAILURE;
        }

        $encrypted = $this->cryptoService->encrypt($secret) ?? $secret;
        $conn->update(
            self::CLIENT_TABLE,
            ['client_secret' => $encrypted, 'updated_at' => time()],
            ['uid' => $uid],
            ['client_secret' => ParameterType::STRING, 'updated_at' => ParameterType::INTEGER]
        );

        $this->tokenAcquisitionService->invalidate((string)$row['provider']);
        $io->success(sprintf('Secret for client #%d (%s) updated. Cached token invalidated.', $uid, $row['provider']));
        return Command::SUCCESS;
    }
}
