<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Registry;

final class ClientDefinition
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $providerType,
        public readonly string $callbackRoute = 'oauthsvc_callback',
        public readonly array $defaultScopes = [],
    ) {}
}
