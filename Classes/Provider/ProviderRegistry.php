<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    public function register(ProviderDefinition $definition): void
    {
        $this->definitions[$definition->identifier] = $definition;
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @param string $identifier
     * @return ProviderDefinition
     * @throws \RuntimeException
     */
    public function get(string $identifier): ProviderDefinition
    {
        if (!isset($this->definitions[$identifier])) {
            throw new \RuntimeException('No provider registered for identifier: ' . $identifier);
        }
        return $this->definitions[$identifier];
    }
}
