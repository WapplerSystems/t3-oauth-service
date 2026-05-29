<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider\Type;

use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\OauthService\Attribute\OAuthProviderType;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Model\Connection;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistry;
use WapplerSystems\OauthService\Service\MetadataDiscoveryService;

#[OAuthProviderType('generic_oauth2')]
final class GenericOAuth2ProviderType implements OAuthProviderTypeInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ProviderRegistry $providerRegistry,
        private readonly MetadataDiscoveryService $metadataDiscoveryService,
    ) {
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function buildAuthorizationUrl(Client $client, string $providerAuthorizationUrl, string $redirectUri, string $state, array $scopes = [], ?string $codeChallenge = null, string $codeChallengeMethod = 'S256'): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $client->getClientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = $codeChallengeMethod;
        }
        return $providerAuthorizationUrl . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(ProviderDefinition $providerDefinition, Client $client, string $clientSecret, string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $formParams = [
            'client_id' => $client->getClientId(),
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if ($codeVerifier !== null) {
            $formParams['code_verifier'] = $codeVerifier;
        }

        $tokenUrl = $this->metadataDiscoveryService->resolveTokenUrl($providerDefinition);
        if ($tokenUrl === '') {
            throw new \RuntimeException('No token endpoint configured or discoverable for provider: ' . $providerDefinition->identifier);
        }

        $response = $this->requestFactory->request(
            $tokenUrl,
            'POST',
            [
                'form_params' => $formParams,
                'timeout' => 5,
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid token response from provider');
        }
        return $data;
    }

    public function refreshToken(Client $client, Connection $connection, string $refreshToken): array
    {

        $providerKey = $client->getProvider();
        $provider = $this->providerRegistry->get((string)$providerKey);

        $tokenEndpoint = $this->metadataDiscoveryService->resolveTokenUrl($provider);
        if ($tokenEndpoint === '') {
            throw new \RuntimeException('Missing token endpoint for provider: ' . $providerKey);
        }

        $resp = $this->requestFactory->request($tokenEndpoint, 'POST', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $client->getClientId(),
                'client_secret' => $client->getClientSecret(),
            ],
        ]);

        $data = json_decode($resp->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid refresh response from provider');
        }
        return $data;
    }

    public function supportsClientCredentials(): bool
    {
        return true;
    }

    public function fetchClientCredentialsToken(
        ProviderDefinition $providerDefinition,
        Client $client,
        string $clientSecret,
        array $scopes = []
    ): array {
        $tokenUrl = $this->metadataDiscoveryService->resolveTokenUrl($providerDefinition);
        if ($tokenUrl === '') {
            throw new \RuntimeException('No token endpoint configured or discoverable for provider: ' . $providerDefinition->identifier);
        }

        $formParams = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $client->getClientId(),
            'client_secret' => $clientSecret,
        ];
        if ($scopes !== []) {
            $formParams['scope'] = implode(' ', $scopes);
        }

        $response = $this->requestFactory->request($tokenUrl, 'POST', [
            'form_params' => $formParams,
            'timeout' => 10,
        ]);
        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid token response from provider');
        }
        return $data;
    }
}
