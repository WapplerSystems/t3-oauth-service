<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Provider\ProviderRegistry;
use WapplerSystems\OauthService\Provider\Type\ProviderTypeResolver;

/**
 * High-level token acquisition for consumer extensions.
 *
 * Today: OAuth 2.0 Client Credentials Grant — fetches a service-account access
 * token for a provider that has an active client row in tx_oauthsvc_client.
 * Caches tokens until expires_in - safety_margin so consumers do not have to
 * implement their own caching.
 *
 * Authorization-Code-Flow tokens are handled by OAuthClientService (which works
 * against tx_oauthsvc_connection); this service is dedicated to flows that have
 * no associated user connection.
 */
final class TokenAcquisitionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CACHE_IDENTIFIER = 'oauth_service';
    private const TOKEN_SAFETY_MARGIN = 30;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderTypeResolver $providerTypeResolver,
        private readonly CryptoService $cryptoService,
        private readonly CacheManager $cacheManager,
    ) {
        $this->logger = new NullLogger();
    }

    /**
     * Returns a valid client_credentials access token for the given provider,
     * using a cached one when possible.
     *
     * @param string[] $scopes Optional scope list (rare for client_credentials)
     * @return string|null Token, or null when no active client is configured
     * @throws \RuntimeException when the provider type does not support client_credentials
     *                           or the token endpoint is missing
     */
    public function getClientCredentialsToken(string $providerIdentifier, array $scopes = []): ?string
    {
        $cache = $this->getCache();
        $cacheKey = $this->buildCacheKey($providerIdentifier, $scopes);

        $cached = $cache->get($cacheKey);
        if (is_array($cached)
            && isset($cached['token'], $cached['expiresAt'])
            && time() < (int)$cached['expiresAt'] - self::TOKEN_SAFETY_MARGIN
        ) {
            return (string)$cached['token'];
        }

        $client = $this->clientRepository->findActiveByProvider($providerIdentifier);
        if ($client === null) {
            $this->logger->debug('No active client for provider', ['provider' => $providerIdentifier]);
            return null;
        }

        $definition = $this->providerRegistry->get($client->getProvider());
        if ($definition === null) {
            throw new \RuntimeException(
                'Provider not registered: ' . $client->getProvider()
                . '. Make sure the providing extension registers a ProviderDefinition with this identifier.'
            );
        }

        $type = $this->providerTypeResolver->resolve($definition->type);
        if (!$type->supportsClientCredentials()) {
            throw new \RuntimeException(sprintf(
                'Provider type "%s" does not support client_credentials grant.',
                $definition->type
            ));
        }

        $secret = $this->cryptoService->decrypt($client->getClientSecret())
            ?? (string)$client->getClientSecret();

        $token = $type->fetchClientCredentialsToken($definition, $client, $secret, $scopes);
        $expiresIn = (int)($token['expires_in'] ?? 60);

        $ttl = max(0, $expiresIn - self::TOKEN_SAFETY_MARGIN);
        if ($ttl > 0) {
            $cache->set(
                $cacheKey,
                [
                    'token' => (string)$token['access_token'],
                    'expiresAt' => time() + $expiresIn,
                ],
                [self::tagFor($providerIdentifier)],
                $ttl
            );
        }

        $this->logger->info('Acquired client_credentials token', [
            'provider' => $providerIdentifier,
            'expires_in' => $expiresIn,
        ]);

        return (string)$token['access_token'];
    }

    /**
     * Drop cached tokens for one provider (e.g. after credentials rotation).
     */
    public function invalidate(string $providerIdentifier): void
    {
        $this->getCache()->flushByTag(self::tagFor($providerIdentifier));
    }

    /**
     * Returns metadata about the currently cached client_credentials token for
     * the given provider (expires_at unix timestamp, derived roles claim from
     * the JWT), or null when no token is cached. Never returns the token
     * itself — callers should use getClientCredentialsToken() for that.
     *
     * Used by the OAuth Services BE module to render a status pill ("token
     * valid until HH:MM") next to client_credentials providers without
     * forcing a re-fetch on every page load.
     *
     * @return array{expiresAt: int, roles: list<string>}|null
     */
    public function getCachedTokenStatus(string $providerIdentifier, array $scopes = []): ?array
    {
        $cache = $this->getCache();
        $cached = $cache->get($this->buildCacheKey($providerIdentifier, $scopes));
        if (!is_array($cached) || !isset($cached['token'], $cached['expiresAt'])) {
            return null;
        }

        return [
            'expiresAt' => (int)$cached['expiresAt'],
            'roles' => $this->extractRolesFromJwt((string)$cached['token']),
        ];
    }

    /**
     * Best-effort decode of the JWT payload's "roles" claim. Returns [] when
     * the token is not a JWT or contains no roles.
     *
     * @return list<string>
     */
    private function extractRolesFromJwt(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) < 2) {
            return [];
        }
        $padded = $segments[1] . str_repeat('=', (4 - strlen($segments[1]) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            return [];
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || !isset($payload['roles']) || !is_array($payload['roles'])) {
            return [];
        }
        return array_values(array_map('strval', $payload['roles']));
    }

    private function getCache(): FrontendInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }
        return $this->cache;
    }

    /**
     * @param string[] $scopes
     */
    private function buildCacheKey(string $providerIdentifier, array $scopes): string
    {
        return 'cc_' . sha1($providerIdentifier . '|' . implode(' ', $scopes));
    }

    private static function tagFor(string $providerIdentifier): string
    {
        return 'provider_' . sha1($providerIdentifier);
    }
}
