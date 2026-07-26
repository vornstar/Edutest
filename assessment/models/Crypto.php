<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * AES-256-GCM encryption for sensitive fields at rest (mark schemes, model
 * answers, Graph tokens). Ciphertext layout: [12-byte nonce][16-byte tag][ciphertext].
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $raw = (string) config('encryption_key', '');
        if ($raw === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
        }
        $key = str_starts_with($raw, 'base64:')
            ? base64_decode(substr($raw, 7), true)
            : $raw;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must decode to 32 bytes for AES-256.');
        }
        return $key;
    }

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null) {
            return null;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return $nonce . $tag . $ciphertext;
    }

    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        $nonce = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ciphertext = substr($blob, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed - data may be corrupt or key mismatch.');
        }
        return $plaintext;
    }
}
