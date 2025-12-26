<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

interface OAuthProviderInterface
{
    public function getProviderType(): string;

    public function supportsRefresh(): bool;

    public function buildAuthorizationUrl(array $clientRow, string $redirectUri, string $state, array $scopes): string;

    /** @return array{access_token:string, refresh_token?:string, token_type?:string, expires_in?:int, scope?:string} */
    public function exchangeCodeForToken(array $clientRow, string $code, string $redirectUri): array;

    /** @return array{access_token:string, refresh_token?:string, token_type?:string, expires_in?:int, scope?:string} */
    public function refreshToken(array $clientRow, string $refreshToken): array;
}
