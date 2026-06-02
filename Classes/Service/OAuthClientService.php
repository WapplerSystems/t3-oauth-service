<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;

final class OAuthClientService
{
    private const CLIENT_TABLE = 'tx_oauthsvc_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConnectionRepository $connectionRepository,
        private readonly CryptoService $cryptoService,
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

    /**
     * Liefert die aktive Verbindung eines bestimmten Clients mit entschlüsseltem Access-Token.
     * Gibt null zurück, wenn keine aktive Verbindung existiert.
     *
     * @return array{uid: int, access_token: string, status: string}|null
     */
    public function getActiveConnectionByClientUid(int $clientUid): ?array
    {
        $connection = $this->connectionRepository->findActiveConnectionByClientUid($clientUid);
        if ($connection === null) {
            return null;
        }

        $connection['access_token'] = $this->cryptoService->decrypt($connection['access_token']) ?? '';
        return $connection;
    }

    /**
     * Liefert die erste aktive Verbindung für einen Provider mit entschlüsseltem Access-Token.
     * Gibt null zurück, wenn keine aktive Verbindung existiert.
     *
     * @return array{uid: int, access_token: string, status: string, metadata: string}|null
     */
    public function getActiveConnectionByProvider(string $provider): ?array
    {
        $connection = $this->connectionRepository->findFirstActiveConnectionByProvider($provider);
        if ($connection === null) {
            return null;
        }

        $connection['access_token'] = $this->cryptoService->decrypt($connection['access_token']) ?? '';
        return $connection;
    }

    /**
     * Returns a specific metadata value from a connection's stored metadata JSON.
     * Supports dot-notation for nested keys (e.g. 'login.login_email').
     */
    public function getConnectionMetadataValue(array $connection, string $key, mixed $default = null): mixed
    {
        return $this->readMetadataValue($connection['metadata'] ?? '', $key, $default);
    }

    /**
     * Returns the metadata JSON of the active client for the given provider as a
     * decoded array. Use when consumer extensions need static client-side
     * configuration (e.g. tenant id, sender identifier) alongside the OAuth
     * credentials. Returns an empty array if no active client exists or the
     * metadata column is empty / not valid JSON.
     *
     * @return array<string, mixed>
     */
    public function getActiveClientMetadataByProvider(string $provider): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CLIENT_TABLE);
        $row = $qb
            ->select('metadata')
            ->from(self::CLIENT_TABLE)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('is_active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || ($row['metadata'] ?? '') === '') {
            return [];
        }

        $decoded = json_decode((string)$row['metadata'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns a single value from the active client's metadata JSON for the
     * given provider, with dot-notation for nested keys.
     */
    public function getActiveClientMetadataValueByProvider(string $provider, string $key, mixed $default = null): mixed
    {
        return $this->lookupMetadataKey($this->getActiveClientMetadataByProvider($provider), $key, $default);
    }

    private function readMetadataValue(string $rawJson, string $key, mixed $default): mixed
    {
        if ($rawJson === '') {
            return $default;
        }

        $data = json_decode($rawJson, true);
        if (!is_array($data)) {
            return $default;
        }

        return $this->lookupMetadataKey($data, $key, $default);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function lookupMetadataKey(array $data, string $key, mixed $default): mixed
    {
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
