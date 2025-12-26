<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

final class ProviderResolver
{
    /** @param iterable<OAuthProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function resolve(string $providerType): OAuthProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getProviderType() === $providerType) {
                return $provider;
            }
        }
        throw new \RuntimeException('No provider registered for type: ' . $providerType);
    }
}
