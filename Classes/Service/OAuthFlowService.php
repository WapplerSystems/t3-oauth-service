<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Provider\ProviderResolver;
use WapplerSystems\OauthService\Repository\ClientRepository;
use WapplerSystems\OauthService\Repository\ConnectionRepository;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;

final class OAuthFlowService
{
    public function __construct(
        private readonly ClientRepository     $clientRepository,
        private readonly ConnectionRepository $connectionRepository,
        private readonly CryptoService        $cryptoService,
        private readonly ProviderResolver     $providerResolver,
        private readonly BackendUriBuilder    $backendUriBuilder,
    )
    {
    }

    public function buildRedirectUri(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('oauthsvc_callback');
    }

    /**
     * Startet den OAuth-Flow. Gibt die Provider-Auth-URL zurück (Controller macht Redirect).
     */
    public function startAuthorization(int $clientUid, ?int $connectionUid = null, ?string $label = null): string
    {
        $client = $this->clientRepository->findByUid($clientUid);
        if (!$client || (int)$client['is_active'] !== 1) {
            throw new \RuntimeException('Client not found or inactive.');
        }

        $redirectUri = $this->buildRedirectUri();

        // Connection anlegen oder laden
        if ($connectionUid === null) {
            $connectionUid = $this->connectionRepository->insert([
                'pid' => (int)$client['pid'],
                'client_uid' => $clientUid,
                'label' => $label ?: ('Connection ' . date('Y-m-d H:i')),
                'status' => 'disconnected',
            ]);
        } else {
            $conn = $this->connectionRepository->findByUid($connectionUid);
            if (!$conn || (int)$conn['client_uid'] !== $clientUid) {
                throw new \RuntimeException('Connection not found for client.');
            }
        }

        // state erstellen: raw speichern wir nicht, nur hash.
        $state = bin2hex(random_bytes(24));
        $stateHash = hash('sha256', $state);

        $this->connectionRepository->update($connectionUid, [
            'state_hash' => $stateHash,
            'state_created_at' => time(),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);

        $scopes = $this->normalizeScopes($client['scopes'] ?? '');
        $provider = $this->providerResolver->resolve((string)$client['provider_type']);

        return $provider->buildAuthorizationUrl($client, $redirectUri, $state, $scopes);
    }

    public function handleCallback(string $code, string $state): int
    {
        $stateHash = hash('sha256', $state);
        $conn = $this->connectionRepository->findByStateHash($stateHash);
        if (!$conn) {
            throw new \RuntimeException('Unknown state (no matching connection).');
        }

        // optional: state timeout (z.B. 10 Minuten)
        $createdAt = (int)($conn['state_created_at'] ?? 0);
        if ($createdAt > 0 && (time() - $createdAt) > 600) {
            $this->connectionRepository->update((int)$conn['uid'], [
                'status' => 'error',
                'last_error_code' => 'state_expired',
                'last_error_message' => 'State expired.',
            ]);
            throw new \RuntimeException('State expired.');
        }

        $client = $this->clientRepository->findByUid((int)$conn['client_uid']);
        if (!$client || (int)$client['is_active'] !== 1) {
            throw new \RuntimeException('Client not found or inactive.');
        }

        $provider = $this->providerResolver->resolve((string)$client['provider_type']);
        $redirectUri = $this->buildRedirectUri();

        $clientSecretPlain = $this->cryptoService->decrypt($client['client_secret_enc'] ?? null) ?? '';
        $clientRowForProvider = $client;
        $clientRowForProvider['client_secret_plain'] = $clientSecretPlain;

        $token = $provider->exchangeCodeForToken($clientRowForProvider, $code, $redirectUri);

        $expiresAt = 0;
        if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
            $expiresAt = time() + (int)$token['expires_in'];
        }

        $this->connectionRepository->update((int)$conn['uid'], [
            'status' => 'connected',
            'access_token_enc' => $this->cryptoService->encrypt((string)$token['access_token']),
            'refresh_token_enc' => $this->cryptoService->encrypt((string)($token['refresh_token'] ?? '')),
            'token_type' => (string)($token['token_type'] ?? ''),
            'expires_at' => $expiresAt,
            'last_refresh_at' => time(),
            'last_check_at' => time(),
            'last_error_code' => '',
            'last_error_message' => '',
            // state löschen
            'state_hash' => '',
            'state_created_at' => 0,
        ]);

        return (int)$conn['uid'];
    }

    private function normalizeScopes(string $scopesField): array
    {
        $scopesField = trim($scopesField);
        if ($scopesField === '') {
            return [];
        }

        // akzeptiere JSON oder space/comma
        if (str_starts_with($scopesField, '[')) {
            $arr = json_decode($scopesField, true);
            return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
        }

        $scopesField = str_replace(',', ' ', $scopesField);
        $parts = preg_split('/\s+/', $scopesField) ?: [];
        return array_values(array_filter($parts));
    }
}
