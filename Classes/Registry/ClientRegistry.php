<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Registry;

final class ClientRegistry implements ClientRegistryInterface
{
    /** @var array<string, ClientDefinition> */
    private array $definitions = [];

    public function register(ClientDefinition $definition): void
    {
        $this->definitions[$definition->identifier] = $definition;
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $identifier): ?ClientDefinition
    {
        return $this->definitions[$identifier] ?? null;
    }
}
