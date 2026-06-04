<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<ProviderDefinition> $taggedDefinitions Providers contributed via DI
     *     under tag 'oauth_service.provider_definition'. Populating the registry through
     *     DI (instead of imperative register() calls from ext_localconf.php) is the only
     *     way for providers to be available in the TYPO3 Install Tool's failsafe
     *     bootstrap, which does not execute ext_localconf.php files.
     */
    public function __construct(iterable $taggedDefinitions = [])
    {
        foreach ($taggedDefinitions as $definition) {
            $this->register($definition);
        }
    }

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
