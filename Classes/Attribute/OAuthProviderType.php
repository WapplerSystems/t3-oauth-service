<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class OAuthProviderType
{
    public const TAG_NAME = 'oauth_service.providertype';

    public function __construct(
        public string $identifier
    ) {}
}
