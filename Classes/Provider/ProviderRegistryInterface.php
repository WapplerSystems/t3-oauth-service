<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

interface ProviderRegistryInterface
{
    public function register(ProviderDefinition $definition): void;

    /** @return ProviderDefinition[] */
    public function all(): array;

    public function get(string $identifier): ?ProviderDefinition;
}
