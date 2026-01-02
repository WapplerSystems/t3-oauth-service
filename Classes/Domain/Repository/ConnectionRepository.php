<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Repository;

final class ConnectionRepository extends Repository
{


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



    public function findByClientIdentifier(string $identifier)
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('client_identifier', $qb->createNamedParameter($identifier, Connection::PARAM_STR)))
            ->orderBy('label')
            ->executeQuery()->fetchAllAssociative();
    }
}
