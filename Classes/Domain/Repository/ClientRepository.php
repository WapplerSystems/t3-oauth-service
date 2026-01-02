<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\Repository;

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
}
