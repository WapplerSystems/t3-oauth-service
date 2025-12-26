<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Provider\ProviderResolver;
use WapplerSystems\OauthService\Repository\ClientRepository;
use WapplerSystems\OauthService\Repository\ConnectionRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class MonitoringService
{
    public function __construct(
        private readonly ConnectionRepository $connectionRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ProviderResolver $providerResolver,
        private readonly CryptoService $cryptoService,
        private readonly NotificationService $notificationService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function run(): void
    {
        $extConf = $this->extensionConfiguration->get('oauth_service') ?? [];
        $threshold = (int)($extConf['thresholdSeconds'] ?? 300);
        $debounceMinutes = (int)($extConf['debounceMinutes'] ?? 360);

        $now = time();
        $connections = $this->connectionRepository->findAllForMonitoring();

        foreach ($connections as $conn) {
            $this->checkOne($conn, $now, $threshold, $debounceMinutes);
        }
    }

    private function checkOne(array $conn, int $now, int $threshold, int $debounceMinutes): void
    {
        $uid = (int)$conn['uid'];
        $this->connectionRepository->update($uid, ['last_check_at' => $now]);

        $client = $this->clientRepository->findByUid((int)$conn['client_uid']);
        if (!$client || (int)$client['is_active'] !== 1) {
            return;
        }

        $expiresAt = (int)($conn['expires_at'] ?? 0);
        $shouldRefresh = $expiresAt > 0 && $expiresAt <= ($now + $threshold);

        // Falls bereits abgelaufen: auch refresh versuchen
        if ($expiresAt > 0 && $expiresAt <= $now) {
            $shouldRefresh = true;
        }

        if (!$shouldRefresh) {
            return;
        }

        try {
            $refreshToken = $this->cryptoService->decrypt($conn['refresh_token_enc'] ?? null) ?? '';
            if ($refreshToken === '') {
                $this->markFailure($client, $conn, 'missing_refresh_token', 'No refresh token available.', $debounceMinutes);
                return;
            }

            $provider = $this->providerResolver->resolve((string)$client['provider_type']);
            if (!$provider->supportsRefresh()) {
                $this->markFailure($client, $conn, 'refresh_not_supported', 'Provider does not support refresh.', $debounceMinutes);
                return;
            }

            $clientSecretPlain = $this->cryptoService->decrypt($client['client_secret_enc'] ?? null) ?? '';
            $clientRowForProvider = $client;
            $clientRowForProvider['client_secret_plain'] = $clientSecretPlain;

            $token = $provider->refreshToken($clientRowForProvider, $refreshToken);

            $newExpiresAt = 0;
            if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
                $newExpiresAt = time() + (int)$token['expires_in'];
            }

            $this->connectionRepository->update((int)$conn['uid'], [
                'status' => 'connected',
                'access_token_enc' => $this->cryptoService->encrypt((string)$token['access_token']),
                'refresh_token_enc' => $this->cryptoService->encrypt((string)($token['refresh_token'] ?? $refreshToken)),
                'token_type' => (string)($token['token_type'] ?? ''),
                'expires_at' => $newExpiresAt,
                'last_refresh_at' => time(),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        } catch (\Throwable $e) {
            $this->markFailure($client, $conn, 'refresh_failed', $e->getMessage(), $debounceMinutes);
        }
    }

    private function markFailure(array $client, array $conn, string $code, string $message, int $debounceMinutes): void
    {
        $uid = (int)$conn['uid'];
        $this->connectionRepository->update($uid, [
            'status' => 'error',
            'last_error_code' => $code,
            'last_error_message' => $message,
        ]);

        $to = (string)($client['notify_email'] ?? '');
        if (trim($to) === '') {
            return;
        }

        $lastNotified = (int)($conn['last_notified_at'] ?? 0);
        $debounceSeconds = max(1, $debounceMinutes) * 60;

        if ($lastNotified > 0 && (time() - $lastNotified) < $debounceSeconds) {
            return;
        }

        $subject = '[TYPO3 OAuth] Connection failure: ' . ($conn['label'] ?: ('#' . $uid));
        $body = implode("\n", [
            'Client: ' . (string)($client['title'] ?? ''),
            'Identifier: ' . (string)($client['identifier'] ?? ''),
            'Connection: ' . (string)($conn['label'] ?? ''),
            'UID: ' . $uid,
            'Error: ' . $code,
            'Message: ' . $message,
            'Time: ' . date('c'),
        ]);

        $this->notificationService->sendFailureMail($to, $subject, $body);

        $this->connectionRepository->update($uid, [
            'last_notified_at' => time(),
        ]);
    }
}
