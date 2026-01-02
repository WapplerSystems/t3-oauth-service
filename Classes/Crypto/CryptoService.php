<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Crypto;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class CryptoService
{

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    private function getKey(): string
    {
        $extConf = $this->extensionConfiguration->get('oauth_service') ?? [];
        $keyB64 = (string)($extConf['cryptoKey'] ?? '');

        if ($keyB64 !== '') {
            $key = base64_decode($keyB64, true);
            if (is_string($key) && strlen($key) === self::KEY_BYTES) {
                return $key;
            }
        }

        // Fallback: TYPO3 encryptionKey (nicht ideal, aber praktikabel)
        $encKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($encKey === '') {
            throw new \RuntimeException('Missing TYPO3 SYS/encryptionKey and oauth_service.cryptoKey.');
        }

        // Derive 32 bytes key deterministisch
        return hash('sha256', $encKey, true);
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        $key = $this->getKey();
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        // store as base64(nonce|cipher)
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(?string $cipherText): ?string
    {
        if ($cipherText === null || $cipherText === '') {
            return $cipherText;
        }
        $raw = base64_decode($cipherText, true);
        if (!is_string($raw) || strlen($raw) < self::NONCE_BYTES + 1) {
            return null;
        }
        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $cipher = substr($raw, self::NONCE_BYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->getKey());
        return $plain === false ? null : $plain;
    }
}
