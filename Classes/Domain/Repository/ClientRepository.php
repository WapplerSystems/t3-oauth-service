<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\Repository;
use WapplerSystems\OauthService\Domain\Model\Client;

final class ClientRepository extends Repository
{


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
     */
    public function findActiveByProvider(string $provider): ?Client
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->matching(
            $query->logicalAnd(
                $query->equals('provider', $provider),
                $query->equals('isActive', true)
            )
        );
        $query->setLimit(1);
        /** @var Client|null $client */
        $client = $query->execute()->getFirst();
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
