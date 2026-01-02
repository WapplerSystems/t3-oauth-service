<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

final readonly class ProviderDefinition
{
    public function __construct(
        public string $identifier,
        public string $title,
        public string $type,
        public string $authorizationUrl,
        public string $tokenUrl,
        public array  $defaultScopes = [],
    ) {}
}
