<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:delete-client',
    description: 'Delete an OAuth client (and its connections). Asks for confirmation unless --force is given.',
)]
final class DeleteClientCommand extends Command
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
            ->addArgument('uid', InputArgument::REQUIRED, 'Client UID to delete')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt (e.g. for automation)');
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

        $io->writeln(sprintf('Will delete client #%d (provider=%s, client_id=%s) and all its connections.', $uid, $row['provider'], $row['client_id']));

        if (!$input->getOption('force')) {
            if (!$io->confirm('Continue?', false)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
        }

        $connectionConn = $this->connectionPool->getConnectionForTable(self::CONNECTION_TABLE);
        $connectionConn->delete(self::CONNECTION_TABLE, ['client' => $uid]);
        $conn->delete(self::CLIENT_TABLE, ['uid' => $uid]);

        $this->tokenAcquisitionService->invalidate((string)$row['provider']);
        $io->success(sprintf('Client #%d deleted, cached token cleared.', $uid));
        return Command::SUCCESS;
    }
}
