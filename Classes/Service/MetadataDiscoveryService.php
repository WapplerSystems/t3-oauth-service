<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Provider\ProviderDefinition;

/**
 * Fetches and caches OAuth 2.0 Authorization Server Metadata.
 *
 * Supports two modes:
 *   1. Public discovery (RFC 8414): unauthenticated fetch from well-known URL
 *   2. Authenticated metadata: fetch with access token (e.g. Mailchimp OAuth2 metadata)
 *
 * Discovery document is expected at:
 *   - explicit metadataUrl, or
 *   - {issuer}/.well-known/oauth-authorization-server
 *
 * Manual URLs in ProviderDefinition always take precedence for endpoint resolution.
 */
final class MetadataDiscoveryService
{
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly FrontendInterface $cache,
    ) {}

    /**
     * Resolves the authorization endpoint for a provider.
     * Priority: manual authorizationUrl > per-client baseUrl + authorizationPath > metadata discovery
     *
     * The per-client step supports self-hosted providers (Mautic, Keycloak, ...)
     * where the host differs per installation: the admin sets a "baseUrl" key in
     * the client's metadata JSON (tx_oauthsvc_client.metadata), and the provider
     * definition supplies the fixed path via $authorizationPath.
     */
    public function resolveAuthorizationUrl(ProviderDefinition $provider, ?Client $client = null): string
    {
        if ($provider->authorizationUrl !== '') {
            return $provider->authorizationUrl;
        }

        $perClientUrl = $this->resolveFromClientBaseUrl($provider, $client, $provider->authorizationPath);
        if ($perClientUrl !== '') {
            return $perClientUrl;
        }

        $metadata = $this->fetchMetadata($provider);
        return $metadata['authorization_endpoint'] ?? '';
    }

    /**
     * Resolves the token endpoint for a provider.
     * Priority: manual tokenUrl > per-client baseUrl + tokenPath > metadata discovery
     * See resolveAuthorizationUrl() for the per-client mechanism.
     */
    public function resolveTokenUrl(ProviderDefinition $provider, ?Client $client = null): string
    {
        if ($provider->tokenUrl !== '') {
            return $provider->tokenUrl;
        }

        $perClientUrl = $this->resolveFromClientBaseUrl($provider, $client, $provider->tokenPath);
        if ($perClientUrl !== '') {
            return $perClientUrl;
        }

        $metadata = $this->fetchMetadata($provider);
        return $metadata['token_endpoint'] ?? '';
    }

    /**
     * Combines the client's "baseUrl" metadata entry with the given provider
     * path into a full endpoint URL. Returns '' when either half is missing,
     * so callers can fall through to metadata discovery unchanged.
     */
    private function resolveFromClientBaseUrl(ProviderDefinition $provider, ?Client $client, string $path): string
    {
        if ($client === null || $path === '') {
            return '';
        }

        $baseUrl = (string)$client->getMetadataValue('baseUrl', '');
        if ($baseUrl === '') {
            return '';
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Returns the full metadata document for a provider via public discovery (cached).
     * Returns empty array if discovery is not available or fails.
     */
    public function fetchMetadata(ProviderDefinition $provider): array
    {
        $metadataUrl = $provider->getEffectiveMetadataUrl();
        if ($metadataUrl === '') {
            return [];
        }

        $cacheIdentifier = 'oauth_metadata_' . sha1($metadataUrl);

        $cached = $this->cache->get($cacheIdentifier);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->requestFactory->request($metadataUrl, 'GET', [
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            $this->cache->set($cacheIdentifier, $data, [], self::CACHE_LIFETIME);

            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Fetches metadata from the provider's metadata URL using an access token.
     *
     * Some providers (e.g. Mailchimp) require authentication to retrieve metadata
     * such as account info, data center, or API endpoint after login.
     * The access token is sent via "Authorization: OAuth <token>" header.
     *
     * Returns empty array if no metadataUrl is configured or the request fails.
     */
    public function fetchAuthenticatedMetadata(ProviderDefinition $provider, string $accessToken): array
    {
        $metadataUrl = $provider->getEffectiveMetadataUrl();
        if ($metadataUrl === '' || $accessToken === '') {
            return [];
        }

        try {
            $response = $this->requestFactory->request($metadataUrl, 'GET', [
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'OAuth ' . $accessToken,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            return is_array($data) ? $data : [];
        } catch (\Exception) {
            return [];
        }
    }
}