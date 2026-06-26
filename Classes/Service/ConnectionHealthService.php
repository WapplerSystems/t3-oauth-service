<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Verifies that an OAuth access token actually works against a provider's API,
 * and decodes JWT claims for display in the backend module.
 *
 * The {@see ConnectionMonitorCommand} only checks token expiry; an unexpired
 * but otherwise unusable token (wrong API version, revoked scope, …) still
 * reports as "connected". This service adds a live probe: a GET against the
 * provider's configured healthCheckUrl with the bearer token. A 2xx response
 * means the token is genuinely usable; any other status surfaces the provider's
 * own error message so the cause is visible in the module.
 */
final class ConnectionHealthService
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Performs a GET against $url with the given bearer token.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function probe(string $url, string $token): array
    {
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'No health check URL configured for this provider.'];
        }
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'No access token available.'];
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                // Capture 4xx/5xx instead of throwing, so we can surface the
                // provider's error message rather than a generic exception.
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'message' => $e->getMessage()];
        }

        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status < 300;

        $message = '';
        if (!$ok) {
            $body = (string)$response->getBody();
            $decoded = json_decode($body, true);
            // Most providers (incl. CleverReach) return {"error":{"message": "..."}}.
            if (is_array($decoded)) {
                $message = (string)(
                    $decoded['error']['message']
                    ?? $decoded['error_description']
                    ?? $decoded['message']
                    ?? ''
                );
            }
            if ($message === '') {
                $message = trim(substr($body, 0, 200));
            }
        }

        return ['ok' => $ok, 'status' => $status, 'message' => $message];
    }

    /**
     * Decodes the payload (claim set) of a JWT without verifying the signature.
     * Returns an empty array for non-JWT / opaque tokens.
     *
     * @return array<string, mixed>
     */
    public static function decodeJwtClaims(?string $token): array
    {
        if ($token === null || $token === '') {
            return [];
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return [];
        }
        $claims = json_decode($payload, true);
        return is_array($claims) ? $claims : [];
    }

    /**
     * Reduces a JWT claim set to the few fields worth showing in the module.
     *
     * @param array<string, mixed> $claims
     * @return array{scopes: string, expiresAt: ?int, issuer: string, subject: string}
     */
    public static function summarizeClaims(array $claims): array
    {
        $scopes = $claims['scopes'] ?? $claims['scope'] ?? '';
        if (is_array($scopes)) {
            $scopes = implode(' ', $scopes);
        }

        $subject = (string)(
            $claims['sub']
            ?? $claims['login']
            ?? $claims['user_id']
            ?? $claims['client_id']
            ?? ''
        );

        return [
            'scopes' => (string)$scopes,
            'expiresAt' => isset($claims['exp']) ? (int)$claims['exp'] : null,
            'issuer' => (string)($claims['iss'] ?? ''),
            'subject' => $subject,
        ];
    }
}
