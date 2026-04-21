<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Model\Connection;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Provider\ProviderRegistry;
use WapplerSystems\OauthService\Provider\Type\ProviderTypeResolver;

final class OAuthFlowService
{
    public function __construct(
        private readonly ClientRepository     $clientRepository,
        private readonly ConnectionRepository $connectionRepository,
        private readonly CryptoService        $cryptoService,
        private readonly ProviderTypeResolver $providerTypeResolver,
        private readonly BackendUriBuilder    $backendUriBuilder,
        protected PersistenceManager          $persistenceManager,
        protected ProviderRegistry            $providerRegistry,
    )
    {
    }


    /**
     * Startet den OAuth-Flow. Gibt die Provider-Auth-URL zurück (Controller macht Redirect).
     */
    public function startAuthorization(int $clientUid, ServerRequestInterface $request, ?int $connectionUid = null, ?string $label = null): string
    {
        /** @var ?Client $client */
        $client = $this->clientRepository->findByUid($clientUid);

        if ($client === null || !$client->getIsActive()) {
            throw new \RuntimeException('Client not found or inactive.');
        }

        $state = bin2hex(random_bytes(24));
        $stateHash = hash('sha256', $state);

        $codeVerifier = bin2hex(random_bytes(32));
        $encryptedVerifier = $this->cryptoService->encrypt($codeVerifier);

        if ($connectionUid === null) {
            $connection = new Connection();
            $connection->setPid($client->getPid());
            $connection->setClient($client);
            $connection->setStatus(Connection::DISCONNECTED);
            $connection->setStateHash($stateHash);
            $connection->setStateCreatedAt(time());
            $connection->setCodeVerifier($encryptedVerifier);

            $this->connectionRepository->add($connection);

        } else {
            /** @var Connection $conn */
            $connection = $this->connectionRepository->findByUid($connectionUid);
            if (!$connection || (int)$connection->getClient()->getUid() !== $client->getUid()) {
                throw new \RuntimeException('Connection not found for client.');
            }
            $connection->setStateHash($stateHash);
            $connection->setStateCreatedAt(time());
            $connection->setCodeVerifier($encryptedVerifier);
            $this->connectionRepository->update($connection);
        }

        $this->persistenceManager->persistAll();

        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        return $this->buildAuthorizationUrl($client, $request, $state, $codeChallenge);
    }

    public function handleCallback(ServerRequestInterface $request, string $code, string $state): Connection
    {
        $stateHash = hash('sha256', $state);
        /** @var Connection $conn */
        $conn = $this->connectionRepository->findOneByStateHash($stateHash);
        if (!$conn) {
            throw new \RuntimeException('Unknown state (no matching connection).');
        }

        // optional: state timeout (z.B. 10 Minuten)
        $createdAt = $conn->getStateCreatedAt() ?? 0;
        if ($createdAt > 0 && (time() - $createdAt) > 600) {
            $conn->setStatus(Connection::ERROR);
            $conn->setLastErrorCode('state_expired');
            $conn->setLastErrorMessage('State expired.');
            $this->connectionRepository->update($conn);
            $this->persistenceManager->persistAll();
            //throw new \RuntimeException('State expired.');
        }

        $client = $conn->getClient();
        if (!$client || !$client->getIsActive()) {
            throw new \RuntimeException('Client not found or inactive.');
        }

        $provider = $this->providerRegistry->get($client->getProvider());
        $providerType = $this->providerTypeResolver->resolve($provider->type);

        $redirectUri = $this->backendUriBuilder->buildUriFromRoute('oauthsvc_callback', [
            'state' => $state,
        ])->withHost($request->getUri()->getHost())->withScheme($request->getUri()->getScheme())->__toString();

        $clientSecretPlain = $this->cryptoService->decrypt($client->getClientSecret()) ?? $client->getClientSecret() ?? '';

        $codeVerifier = null;
        if ($conn->getCodeVerifier()) {
            $codeVerifier = $this->cryptoService->decrypt($conn->getCodeVerifier());
        }

        try {
            $token = $providerType->exchangeCodeForToken($provider, $client, $clientSecretPlain, $code, $redirectUri, $codeVerifier);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $conn->setStatus(Connection::ERROR);
            $conn->setLastErrorCode((string)$e->getCode());
            $conn->setLastErrorMessage($e->getMessage());
            $this->connectionRepository->update($conn);
            $this->persistenceManager->persistAll();
            throw new \RuntimeException('Error exchanging code for token: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        $expiresAt = 0;
        if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
            $expiresAt = time() + (int)$token['expires_in'];
        }

        $conn->setStatus(Connection::CONNECTED);
        $conn->setAccessToken($this->cryptoService->encrypt((string)$token['access_token']));
        $conn->setRefreshToken($this->cryptoService->encrypt((string)($token['refresh_token'] ?? '')));
        $conn->setTokenType((string)($token['token_type'] ?? ''));
        $conn->setAccessTokenExpiresAt(\DateTimeImmutable::createFromTimestamp($expiresAt));
        $conn->setLastRefreshAt(new \DateTimeImmutable('now'));
        $conn->setLastCheckAt(new \DateTimeImmutable('now'));
        $conn->setLastErrorCode('');
        $conn->setLastErrorMessage('');
        // delete state and PKCE verifier
        $conn->setStateHash('');
        $conn->setStateCreatedAt(0);
        $conn->setCodeVerifier(null);
        $this->connectionRepository->update($conn);
        $this->persistenceManager->persistAll();

        return $conn;
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


    private function buildAuthorizationUrl(Client $client, ServerRequestInterface $request, string $state, ?string $codeChallenge = null): string
    {
        $provider = $this->providerRegistry->get($client->getProvider());

        $providerType = $this->providerTypeResolver->resolve($provider->type);

        $redirectUri = $this->backendUriBuilder->buildUriFromRoute('oauthsvc_callback', [
            'state' => $state,
        ])->withHost($request->getUri()->getHost())->withScheme($request->getUri()->getScheme())->__toString();

        return $providerType->buildAuthorizationUrl(client: $client, providerAuthorizationUrl: $provider->authorizationUrl, redirectUri: $redirectUri, state: $state, codeChallenge: $codeChallenge);
    }

    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

}
