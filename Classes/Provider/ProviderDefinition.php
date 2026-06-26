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
        /**
         * Optional external URL the admin must open to register an OAuth client
         * with the provider (e.g. Keycloak admin console, Google Cloud Console).
         * Rendered as a button on the wizard's "Enter Client ID / Secret" step.
         */
        public string $setupGuideUrl = '',
        /**
         * Optional path to an HTML snippet whose content is rendered above the
         * Client ID / Secret form on the wizard. Use EXT:syntax, e.g.:
         *   EXT:linear_keycloak_manager/Resources/Private/SetupInstructions/keycloak_admin.html
         * The snippet is rendered as-is (f:format.raw), so the providing
         * extension is responsible for safe HTML.
         */
        public string $setupInstructionsPath = '',
        /**
         * Optional resource endpoint used to verify that an acquired token
         * actually works against the provider's API (not just that it is
         * unexpired). The backend module performs a GET against this URL with
         * the bearer token; a 2xx response counts as healthy, any other status
         * surfaces the provider's error message (e.g. CleverReach's
         * "v2 token on higher version" for an API-version mismatch).
         * Leave empty to disable the live health probe for this provider.
         */
        public string $healthCheckUrl = '',
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