<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ConnectionRepository
{
    private const TABLE = 'tx_oauthsvc_connection';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function findByUid(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function findByClientUid(int $clientUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid, Connection::PARAM_INT)))
            ->orderBy('label')
            ->executeQuery()->fetchAllAssociative();
    }

    public function findByStateHash(string $stateHash): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('state_hash', $qb->createNamedParameter($stateHash)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function findAllForMonitoring(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->where(
                $qb->expr()->in('status', $qb->createNamedParameter(
                    ['connected', 'expired', 'error'],
                    Connection::PARAM_STR_ARRAY
                ))
            )
            ->executeQuery()->fetchAllAssociative();
    }

    public function insert(array $fields): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $fields['created_at'] = time();
        $fields['updated_at'] = time();
        $conn->insert(self::TABLE, $fields);
        return (int)$conn->lastInsertId(self::TABLE);
    }

    public function update(int $uid, array $fields): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $fields['updated_at'] = time();
        $conn->update(self::TABLE, $fields, ['uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }
}
