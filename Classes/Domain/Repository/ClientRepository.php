<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Repository;
use WapplerSystems\OauthService\Domain\Model\Client;

final class ClientRepository extends Repository
{

    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function findByProviderClientIdAndClientSecret(string $provider, string $clientId, string $clientSecret)
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('provider', $provider),
                $query->equals('clientId', $clientId),
                $query->equals('clientSecret', $clientSecret)
            )
        );
        return $query->execute();
    }

    /**
     * Returns the first active client for the given provider, or null when none exists.
     *
     * Uses a raw DBAL query instead of Extbase persistence so this method works
     * in the TYPO3 Install Tool's failsafe bootstrap (which does not load TCA),
     * where consumer code such as GraphTransport may run during "Test Mail Setup".
     */
    public function findActiveByProvider(string $provider): ?Client
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENT_TABLE);
        $qb = $connection->createQueryBuilder();
        $row = $qb
            ->select('*')
            ->from(self::CLIENT_TABLE)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('is_active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        $client = new Client();
        $client->_setProperty('uid', (int)$row['uid']);
        $client->setPid((int)($row['pid'] ?? 0));
        $client->setProvider((string)($row['provider'] ?? ''));
        $client->setClientId((string)($row['client_id'] ?? ''));
        $client->setClientSecret((string)($row['client_secret'] ?? ''));
        $client->setIsActive((bool)($row['is_active'] ?? false));
        $client->setScopes((string)($row['scopes'] ?? ''));
        $client->setNotifyEmail((string)($row['notify_email'] ?? ''));
        $client->setMetadata((string)($row['metadata'] ?? ''));

        return $client;
    }

    /**
     * Returns all active clients for the given provider.
     *
     * @return Client[]
     */
    public function findAllActiveByProvider(string $provider): array
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->matching(
            $query->logicalAnd(
                $query->equals('provider', $provider),
                $query->equals('isActive', true)
            )
        );
        /** @var Client[] $clients */
        $clients = $query->execute()->toArray();
        return $clients;
    }
}
