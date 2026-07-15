<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticated encryption for operational secrets stored in the database.
 *
 * APP_KEY remains outside the database and is used only as input key material.
 * A field-specific AAD value prevents a valid ciphertext from being moved to a
 * different setting column. The persisted format is:
 *
 *     v1:<base64url(12-byte nonce || 16-byte tag || ciphertext)>
 */
final class SecretCipher
{
    private const VERSION = 'v1';
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;
    private const KEY_INFO = 'dormitory/integration-settings/aes-256-gcm/v1';
    private const AAD_PREFIX = "dormitory\0integration_settings\0v1\0";

    private readonly string $key;

    public function __construct(Config $config)
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('The OpenSSL extension is required to protect integration secrets');
        }
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM is not available in this PHP runtime');
        }

        $this->key = hash_hkdf('sha256', $config->appKey(), self::KEY_BYTES, self::KEY_INFO);
        if (strlen($this->key) !== self::KEY_BYTES) {
            throw new RuntimeException('Unable to derive the integration secret encryption key');
        }
    }

    public function encrypt(string $plaintext, string $field): string
    {
        $aad = $this->aad($field);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES,
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt the integration secret');
        }

        return self::VERSION . ':' . $this->base64UrlEncode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $payload, string $field): string
    {
        $aad = $this->aad($field);
        $prefix = self::VERSION . ':';
        if (!str_starts_with($payload, $prefix)) {
            throw $this->invalidCiphertext();
        }

        $encoded = substr($payload, strlen($prefix));
        $packed = $this->base64UrlDecode($encoded);
        if ($packed === null || strlen($packed) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw $this->invalidCiphertext();
        }

        $nonce = substr($packed, 0, self::NONCE_BYTES);
        $tag = substr($packed, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($packed, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );
        if ($plaintext === false) {
            throw $this->invalidCiphertext();
        }

        return $plaintext;
    }

    private function aad(string $field): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field)) {
            throw new InvalidArgumentException('Secret field name is invalid');
        }
        return self::AAD_PREFIX . $field;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value) || strlen($value) % 4 === 1) {
            return null;
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false || !hash_equals($this->base64UrlEncode($decoded), $value)) {
            return null;
        }
        return $decoded;
    }

    private function invalidCiphertext(): RuntimeException
    {
        // Deliberately avoid distinguishing bad versions, malformed payloads,
        // wrong fields, wrong keys and failed authentication.
        return new RuntimeException('Encrypted integration setting is invalid or has been tampered with');
    }
}
