<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:list-clients',
    description: 'List all configured OAuth clients with provider, active state, metadata summary and cached-token status.',
)]
final class ListClientsCommand extends Command
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::CLIENT_TABLE);
        $rows = $qb
            ->select('uid', 'provider', 'client_id', 'is_active', 'metadata', 'notify_email')
            ->from(self::CLIENT_TABLE)
            ->orderBy('provider', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            $io->warning('No OAuth clients configured. Use oauth-service:create-client or the System > OAuth Services backend module to add one.');
            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $provider = (string)($row['provider'] ?? '');
            $status = $this->tokenAcquisitionService->getCachedTokenStatus($provider);
            $tokenStatus = $status === null
                ? '<comment>not cached</comment>'
                : sprintf('<info>valid until %s</info>', date('H:i:s', (int)$status['expiresAt']));

            $tableRows[] = [
                (int)$row['uid'],
                $provider,
                (int)$row['is_active'] === 1 ? '<info>yes</info>' : '<comment>no</comment>',
                $this->truncate((string)($row['client_id'] ?? ''), 24),
                $this->summarizeMetadata((string)($row['metadata'] ?? '')),
                $tokenStatus,
            ];
        }

        $io->table(
            ['UID', 'Provider', 'Active', 'Client ID', 'Metadata', 'Token cache'],
            $tableRows
        );

        return Command::SUCCESS;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
    }

    private function summarizeMetadata(string $raw): string
    {
        if ($raw === '') {
            return '<comment>—</comment>';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '<error>invalid JSON</error>';
        }
        $keys = array_keys($decoded);
        return implode(', ', array_map(static fn ($k) => (string)$k, $keys));
    }
}
