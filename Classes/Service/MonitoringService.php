<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use WapplerSystems\OauthService\Crypto\CryptoService;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Provider\Type\ProviderTypeResolver;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class MonitoringService
{
    public function __construct(
        private readonly ConnectionRepository   $connectionRepository,
        private readonly ClientRepository       $clientRepository,
        private readonly CryptoService          $cryptoService,
        private readonly ProviderTypeResolver   $providerTypeResolver,
        private readonly NotificationService    $notificationService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    )
    {
    }

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
        $this->connectionRepository->updateFields($uid, ['last_check_at' => $now]);

        /** @var Client|null $client */
        $client = $this->clientRepository->findByUid((int)$conn['client']);
        if ($client === null || !$client->getIsActive()) {
            return;
        }

        $expiresAt = (int)($conn['access_token_expires_at'] ?? 0);
        $shouldRefresh = $expiresAt > 0 && $expiresAt <= ($now + $threshold);

        // Falls bereits abgelaufen: Status auf expired setzen und refresh versuchen
        if ($expiresAt > 0 && $expiresAt <= $now) {
            $shouldRefresh = true;
            $this->connectionRepository->updateFields($uid, ['status' => 'expired']);
        }

        if (!$shouldRefresh) {
            return;
        }

        try {
            $refreshToken = $this->cryptoService->decrypt($conn['refresh_token'] ?? null) ?? '';
            if ($refreshToken === '') {
                $this->markFailure($client, $conn, 'missing_refresh_token', 'No refresh token available.', $debounceMinutes);
                return;
            }

            $providerType = $this->providerTypeResolver->resolve((string)$client->getProvider());
            if (!$providerType->supportsRefresh()) {
                $this->markFailure($client, $conn, 'refresh_not_supported', 'Provider does not support refresh.', $debounceMinutes);
                return;
            }

            $token = $providerType->refreshToken($client, $refreshToken);

            $newExpiresAt = 0;
            if (!empty($token['expires_in']) && is_numeric($token['expires_in'])) {
                $newExpiresAt = time() + (int)$token['expires_in'];
            }

            $this->connectionRepository->updateFields((int)$conn['uid'], [
                'status' => 'connected',
                'access_token' => $this->cryptoService->encrypt((string)$token['access_token']),
                'refresh_token' => $this->cryptoService->encrypt((string)($token['refresh_token'] ?? $refreshToken)),
                'token_type' => (string)($token['token_type'] ?? ''),
                'access_token_expires_at' => $newExpiresAt,
                'last_refresh_at' => time(),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        } catch (\Throwable $e) {
            $this->markFailure($client, $conn, 'refresh_failed', $e->getMessage(), $debounceMinutes);
        }
    }

    private function markFailure(Client $client, array $conn, string $code, string $message, int $debounceMinutes): void
    {
        $uid = (int)$conn['uid'];
        $this->connectionRepository->updateFields($uid, [
            'status' => 'error',
            'last_error_code' => $code,
            'last_error_message' => $message,
        ]);

        $to = (string)($client->getNotifyEmail() ?? '');
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
            'Client: ' . ($client->getTitle() ?? ''),
            'Connection: ' . (string)($conn['label'] ?? ''),
            'UID: ' . $uid,
            'Error: ' . $code,
            'Message: ' . $message,
            'Time: ' . date('c'),
        ]);

        $this->notificationService->sendFailureMail($to, $subject, $body);

        $this->connectionRepository->updateFields($uid, [
            'last_notified_at' => time(),
        ]);
    }
}