<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Crypto;

final class CryptoService
{
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private function getKey(): string
    {
        $encKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($encKey === '') {
            throw new \RuntimeException('Missing TYPO3 SYS/encryptionKey.');
        }

        // Derive a fixed 32-byte key required by sodium_crypto_secretbox
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
