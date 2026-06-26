<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

/**
 * Removes a single OAuth connection while keeping its client (the headless
 * counterpart of the backend module's "Disconnect" button). Use delete-client
 * instead to remove the client and all of its connections.
 */
#[AsCommand(
    name: 'oauth-service:disconnect',
    description: 'Remove a single OAuth connection (keeps the client). Asks for confirmation unless --force is given.',
)]
final class DisconnectCommand extends Command
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';
    private const CONNECTION_TABLE = 'tx_oauthsvc_connection';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection UID to remove')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt (e.g. for automation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connectionUid = (int)$input->getOption('connection');
        if ($connectionUid <= 0) {
            $io->error('Please provide the connection UID via --connection <uid>.');
            return Command::FAILURE;
        }

        $connDb = $this->connectionPool->getConnectionForTable(self::CONNECTION_TABLE);
        $row = $connDb->select(['uid', 'client', 'status'], self::CONNECTION_TABLE, ['uid' => $connectionUid])->fetchAssociative();
        if (!is_array($row)) {
            $io->error(sprintf('Connection #%d not found.', $connectionUid));
            return Command::FAILURE;
        }

        $clientUid = (int)$row['client'];
        $clientDb = $this->connectionPool->getConnectionForTable(self::CLIENT_TABLE);
        $client = $clientDb->select(['provider', 'client_id'], self::CLIENT_TABLE, ['uid' => $clientUid])->fetchAssociative();
        $provider = is_array($client) ? (string)$client['provider'] : '';

        $io->writeln(sprintf(
            'Will remove connection #%d (status=%s) of client #%d (provider=%s). The client itself is kept.',
            $connectionUid,
            (string)$row['status'],
            $clientUid,
            $provider !== '' ? $provider : '?'
        ));

        if (!$input->getOption('force') && !$io->confirm('Continue?', false)) {
            $io->note('Aborted.');
            return Command::SUCCESS;
        }

        $connDb->delete(self::CONNECTION_TABLE, ['uid' => $connectionUid]);

        if ($provider !== '') {
            $this->tokenAcquisitionService->invalidate($provider);
        }

        $io->success(sprintf('Connection #%d removed.', $connectionUid));
        return Command::SUCCESS;
    }
}
