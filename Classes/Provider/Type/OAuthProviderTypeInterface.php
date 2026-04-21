<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider\Type;

use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Model\Connection;
use WapplerSystems\OauthService\Provider\ProviderDefinition;

interface OAuthProviderTypeInterface
{
    public function supportsRefresh(): bool;

    public function buildAuthorizationUrl(Client $client, string $providerAuthorizationUrl, string $redirectUri, string $state, array $scopes, ?string $codeChallenge = null, string $codeChallengeMethod = 'S256'): string;

    /** @return array{access_token:string, refresh_token?:string, token_type?:string, expires_in?:int, scope?:string} */
    public function exchangeCodeForToken(ProviderDefinition $providerDefinition, Client $client, string $clientSecret, string $code, string $redirectUri, ?string $codeVerifier = null): array;

    /** @return array{access_token:string, refresh_token?:string, token_type?:string, expires_in?:int, scope?:string} */
    public function refreshToken(Client $client, Connection $connection, string $refreshToken): array;
}
