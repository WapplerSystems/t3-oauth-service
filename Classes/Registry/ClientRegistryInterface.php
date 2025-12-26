<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Registry;

interface ClientRegistryInterface
{
    public function register(ClientDefinition $definition): void;

    /** @return ClientDefinition[] */
    public function all(): array;

    public function get(string $identifier): ?ClientDefinition;
}
