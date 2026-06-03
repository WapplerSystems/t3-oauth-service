<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:set-metadata',
    description: 'Replace the metadata JSON of an OAuth client. Use --merge to merge into the existing object instead of overwriting.',
)]
final class SetMetadataCommand extends Command
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
            ->addArgument('json', InputArgument::REQUIRED, 'Metadata as a JSON object — e.g. {"tenant_id":"…","sender_upn":"…"}. Pass {} to clear.')
            ->addOption('merge', null, null, 'Merge into the existing metadata object instead of replacing it (top-level keys only).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getArgument('uid');
        $raw = (string)$input->getArgument('json');
        $merge = (bool)$input->getOption('merge');

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $io->error('Argument is not a valid JSON object: ' . (json_last_error_msg() ?: 'unknown error'));
            return Command::FAILURE;
        }

        $conn = $this->connectionPool->getConnectionForTable(self::CLIENT_TABLE);
        $row = $conn->select(['uid', 'provider', 'metadata'], self::CLIENT_TABLE, ['uid' => $uid])->fetchAssociative();
        if (!is_array($row)) {
            $io->error(sprintf('Client #%d not found.', $uid));
            return Command::FAILURE;
        }

        if ($merge) {
            $existing = [];
            if (!empty($row['metadata'])) {
                $existingDecoded = json_decode((string)$row['metadata'], true);
                if (is_array($existingDecoded)) {
                    $existing = $existingDecoded;
                }
            }
            $decoded = array_replace($existing, $decoded);
        }

        $normalized = (string)json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $conn->update(
            self::CLIENT_TABLE,
            ['metadata' => $normalized === '[]' ? null : $normalized, 'updated_at' => time()],
            ['uid' => $uid],
            ['metadata' => ParameterType::STRING, 'updated_at' => ParameterType::INTEGER]
        );

        $this->tokenAcquisitionService->invalidate((string)$row['provider']);
        $io->success(sprintf('Metadata for client #%d (%s) updated. Cached token invalidated.', $uid, $row['provider']));
        $io->writeln('New metadata: ' . $normalized);
        return Command::SUCCESS;
    }
}
