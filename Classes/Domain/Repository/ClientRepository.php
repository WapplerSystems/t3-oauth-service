<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ClientRepository
{
    private const TABLE = 'tx_oauthsvc_client';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function findByUid(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    public function findByIdentifier(string $identifier): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function findAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)->orderBy('title')->executeQuery()->fetchAllAssociative();
    }

    public function upsertByIdentifier(string $identifier, array $fields): int
    {
        $existing = $this->findByIdentifier($identifier);
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $fields['updated_at'] = time();

        if (!$existing) {
            $fields['identifier'] = $identifier;
            $fields['created_at'] = time();
            $conn->insert(self::TABLE, $fields);
            return (int)$conn->lastInsertId(self::TABLE);
        }

        $conn->update(self::TABLE, $fields, ['uid' => (int)$existing['uid']]);
        return (int)$existing['uid'];
    }
}
