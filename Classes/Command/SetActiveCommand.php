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

/**
 * Reversibly enables or disables an OAuth client (sets is_active) without
 * deleting it. Disabling excludes the client from provider-based token lookups
 * (e.g. getActiveConnectionByProvider) — useful to retire an old client during
 * a credential switch while keeping it for rollback.
 */
#[AsCommand(
    name: 'oauth-service:set-active',
    description: 'Enable (--on) or disable (--off) an OAuth client without deleting it.',
)]
final class SetActiveCommand extends Command
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uid', InputArgument::REQUIRED, 'Client UID')
            ->addOption('on', null, InputOption::VALUE_NONE, 'Activate the client')
            ->addOption('off', null, InputOption::VALUE_NONE, 'Deactivate the client');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getArgument('uid');

        $on = (bool)$input->getOption('on');
        $off = (bool)$input->getOption('off');
        if ($on === $off) {
            $io->error('Provide exactly one of --on or --off.');
            return Command::FAILURE;
        }
        $active = $on ? 1 : 0;

        $db = $this->connectionPool->getConnectionForTable(self::CLIENT_TABLE);
        $row = $db->select(['uid', 'provider', 'client_id', 'is_active'], self::CLIENT_TABLE, ['uid' => $uid])->fetchAssociative();
        if (!is_array($row)) {
            $io->error(sprintf('Client #%d not found.', $uid));
            return Command::FAILURE;
        }

        if ((int)$row['is_active'] === $active) {
            $io->note(sprintf('Client #%d is already %s. Nothing to do.', $uid, $active ? 'active' : 'inactive'));
            return Command::SUCCESS;
        }

        $db->update(
            self::CLIENT_TABLE,
            ['is_active' => $active, 'updated_at' => time()],
            ['uid' => $uid]
        );

        // A disabled client must not keep serving a cached client_credentials token.
        if ($active === 0) {
            $this->tokenAcquisitionService->invalidate((string)$row['provider']);
        }

        $io->success(sprintf(
            'Client #%d (provider=%s, client_id=%s) is now %s.',
            $uid,
            (string)$row['provider'],
            (string)$row['client_id'],
            $active ? 'ACTIVE' : 'INACTIVE'
        ));
        return Command::SUCCESS;
    }
}
