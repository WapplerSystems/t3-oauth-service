<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider\Type;

use Symfony\Component\DependencyInjection\ServiceLocator;

final class ProviderTypeResolver
{
    /**
     * @param ServiceLocator $providerTypes
     */
    public function __construct(readonly ServiceLocator $providerTypes)
    {
    }

    public function resolve(string $type): OAuthProviderTypeInterface
    {
        if ($this->providerTypes->has($type)) {
            return $this->providerTypes->get($type);
        }
        throw new \RuntimeException('No provider type registered for: ' . $type);
    }
}
