<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Provider;

final readonly class ProviderDefinition
{
    public function __construct(
        public string $identifier,
        public string $title,
        public string $type,
        public string $authorizationUrl = '',
        public string $tokenUrl = '',
        public array  $defaultScopes = [],
        public string $metadataUrl = '',
        public string $issuer = '',
    ) {}

    /**
     * Returns the effective metadata URL.
     * Priority: explicit metadataUrl > issuer-derived well-known URL > empty
     */
    public function getEffectiveMetadataUrl(): string
    {
        if ($this->metadataUrl !== '') {
            return $this->metadataUrl;
        }
        if ($this->issuer !== '') {
            return rtrim($this->issuer, '/') . '/.well-known/oauth-authorization-server';
        }
        return '';
    }

    /**
     * Whether this provider can attempt metadata discovery.
     */
    public function hasMetadataDiscovery(): bool
    {
        return $this->getEffectiveMetadataUrl() !== '';
    }
}