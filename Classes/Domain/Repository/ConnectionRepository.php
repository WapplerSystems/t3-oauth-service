<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Repository;
use WapplerSystems\OauthService\Domain\Model\Connection;

final class ConnectionRepository extends Repository
{
    private const string TABLE = 'tx_oauthsvc_connection';


    public function __construct(readonly ConnectionPool $connectionPool) {
        parent::__construct();
    }

    public function findOneByStateHash(string $stateHash): ?Connection
    {
        return $this->findOneBy(['stateHash' => $stateHash]);
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
        return $qb->select('uid', 'status', 'client', 'access_token_expires_at', 'last_notified_at')
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

    public function findActiveConnectionByClientUid(int $clientUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $qb
            ->select('uid', 'access_token', 'status', 'metadata')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('client', $qb->createNamedParameter($clientUid, ParameterType::INTEGER)),
                $qb->expr()->eq('status', $qb->createNamedParameter('connected'))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $result ?: null;
    }

    public function findActiveByProvider(string $provider): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb
            ->select('conn.uid', 'conn.access_token', 'conn.status', 'conn.metadata')
            ->from(self::TABLE, 'conn')
            ->innerJoin('conn', 'tx_oauthsvc_client', 'client', 'conn.client = client.uid')
            ->where(
                $qb->expr()->eq('conn.status', $qb->createNamedParameter('connected')),
                $qb->expr()->eq('client.provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('client.is_active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->orderBy('conn.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findFirstActiveConnectionByProvider(string $provider): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $qb
            ->select('conn.uid', 'conn.access_token', 'conn.status', 'conn.metadata')
            ->from(self::TABLE, 'conn')
            ->innerJoin('conn', 'tx_oauthsvc_client', 'client', 'conn.client = client.uid')
            ->where(
                $qb->expr()->eq('conn.status', $qb->createNamedParameter('connected')),
                $qb->expr()->eq('client.provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('client.is_active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->orderBy('conn.uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $result ?: null;
    }

    public function updateFields(int $uid, array $data): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $data, ['uid' => $uid]);
    }
}
