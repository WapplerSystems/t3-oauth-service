<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class OAuthClientService
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Liefert aktive OAuth-Clients eines Providers als Select-Optionen.
     * Label-Format (übersetzbar): "%1$s (%2$s)" → "{title} ({clientId})"
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getActiveClientsAsOptions(string $provider): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CLIENT_TABLE);
        $clients = $qb
            ->select('uid', 'client_id')
            ->from(self::CLIENT_TABLE)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('is_active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $labelFormat = LocalizationUtility::translate(
            'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:client.selectLabel'
        ) ?? '%1$s (%2$s)';

        $options = [['value' => '', 'label' => '---']];
        foreach ($clients as $client) {
            $options[] = [
                'value' => (string)$client['uid'],
                'label' => sprintf($labelFormat, $provider, $client['client_id']),
            ];
        }

        return $options;
    }
}
