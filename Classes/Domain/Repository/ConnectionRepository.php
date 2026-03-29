<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Repository;

final class ConnectionRepository extends Repository
{
    private const string TABLE = 'tx_oauthsvc_connection';

    private ConnectionPool $connectionPool;

    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    public function findAllForMonitoring(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->neq('status', $qb->createNamedParameter('disconnected')))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Liefert alle Verbindungen deren access_token_expires_at innerhalb von $withinSeconds liegt.
     */
    public function findExpiringWithinSeconds(int $withinSeconds): array
    {
        $now = time();
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('uid', 'label', 'status', 'client', 'access_token_expires_at', 'last_notified_at')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gt('access_token_expires_at', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->lte('access_token_expires_at', $qb->createNamedParameter($now + $withinSeconds, ParameterType::INTEGER))
            )
            ->orderBy('access_token_expires_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Liefert Verbindungen die einen Refresh-Token haben und deren Access-Token
     * innerhalb von $withinSeconds abläuft (oder bereits abgelaufen ist).
     * Mit $uid kann eine einzelne Verbindung gezielt angesprochen werden.
     */
    public function findForRefresh(int $withinSeconds, ?int $uid = null): array
    {
        $now = time();
        $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $conditions = [
            $qb->expr()->neq('refresh_token', $qb->createNamedParameter('')),
            $qb->expr()->neq('status', $qb->createNamedParameter('disconnected')),
            $qb->expr()->lte(
                'access_token_expires_at',
                $qb->createNamedParameter($now + $withinSeconds, ParameterType::INTEGER)
            ),
        ];

        if ($uid !== null) {
            $conditions[] = $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER));
        }

        return $qb->select('*')
            ->from(self::TABLE)
            ->where(...$conditions)
            ->orderBy('access_token_expires_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function updateFields(int $uid, array $data): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $data, ['uid' => $uid]);
    }
}