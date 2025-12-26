<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

use TYPO3\CMS\Core\Http\RequestFactory;

final class GenericOAuth2Provider implements OAuthProviderInterface
{
    public function __construct(private readonly RequestFactory $requestFactory)
    {
    }

    public function getProviderType(): string
    {
        return 'generic_oauth2';
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    private function getMeta(array $clientRow): array
    {
        $meta = (string)($clientRow['meta'] ?? '');
        $data = $meta !== '' ? json_decode($meta, true) : [];
        return is_array($data) ? $data : [];
    }

    public function buildAuthorizationUrl(array $clientRow, string $redirectUri, string $state, array $scopes): string
    {
        $meta = $this->getMeta($clientRow);
        $authEndpoint = (string)($meta['authorization_endpoint'] ?? '');
        if ($authEndpoint === '') {
            throw new \RuntimeException('Missing authorization_endpoint in client.meta JSON');
        }

        $params = [
            'response_type' => 'code',
            'client_id' => (string)$clientRow['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }

        return $authEndpoint . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(array $clientRow, string $code, string $redirectUri): array
    {
        $meta = $this->getMeta($clientRow);
        $tokenEndpoint = (string)($meta['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new \RuntimeException('Missing token_endpoint in client.meta JSON');
        }

        $resp = $this->requestFactory->request($tokenEndpoint, 'POST', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => (string)$clientRow['client_id'],
                'client_secret' => (string)$clientRow['client_secret_plain'],
            ],
        ]);

        $data = json_decode((string)$resp->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid token response from provider');
        }
        return $data;
    }

    public function refreshToken(array $clientRow, string $refreshToken): array
    {
        $meta = $this->getMeta($clientRow);
        $tokenEndpoint = (string)($meta['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new \RuntimeException('Missing token_endpoint in client.meta JSON');
        }

        $resp = $this->requestFactory->request($tokenEndpoint, 'POST', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => (string)$clientRow['client_id'],
                'client_secret' => (string)$clientRow['client_secret_plain'],
            ],
        ]);

        $data = json_decode((string)$resp->getBody()->getContents(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid refresh response from provider');
        }
        return $data;
    }
}
