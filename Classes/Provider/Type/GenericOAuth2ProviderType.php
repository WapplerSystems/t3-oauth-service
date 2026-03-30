<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider\Type;

use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\OauthService\Attribute\OAuthProviderType;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Model\Connection;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistry;

#[OAuthProviderType('generic_oauth2')]
final class GenericOAuth2ProviderType implements OAuthProviderTypeInterface
{
    public function __construct(private readonly RequestFactory $requestFactory, private readonly ProviderRegistry $providerRegistry)
    {
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    private function getMeta(Client $client): array
    {
        $meta = $client->getMeta() ?? '';
        $data = $meta !== '' ? json_decode($meta, true) : [];
        return is_array($data) ? $data : [];
    }

    public function buildAuthorizationUrl(Client $client, string $providerAuthorizationUrl, string $redirectUri, string $state, array $scopes = []): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $client->getClientId(),
            'redirect_uri' => $redirectUri,
        ];
        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }
        return $providerAuthorizationUrl . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(ProviderDefinition $providerDefinition, Client $client, string $clientSecret, string $code, string $redirectUri): array
    {
        $response = $this->requestFactory->request(
            $providerDefinition->tokenUrl,
            'POST',
            [
                'form_params' => [
                    'client_id' => $client->getClientId(),
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ],
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

        $tokenEndpoint = $provider->tokenUrl;
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
}
