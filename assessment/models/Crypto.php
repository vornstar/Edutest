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

    /**
     * The key is always base64 in .env.php - there's no practical way to
     * paste 32 raw binary bytes into a text file. A "base64:" prefix is
     * accepted (and stripped) if present for readability, but isn't
     * required: a bare base64 string like the one `php -r "echo
     * base64_encode(random_bytes(32));"` prints works directly, rather
     * than being silently treated as 44 raw (and therefore wrong-length)
     * key bytes.
     */
    private static function key(): string
    {
        $raw = trim((string) config('encryption_key', ''));
        if ($raw === '') {
            throw new RuntimeException('ASSESSMENT_ENCRYPTION_KEY is not configured.');
        }
        $encoded = str_starts_with($raw, 'base64:') ? substr($raw, 7) : $raw;
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('ASSESSMENT_ENCRYPTION_KEY must be base64 for a 32-byte AES-256 key (generate with: php -r "echo base64_encode(random_bytes(32));").');
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

    /**
     * A deterministic, keyed hash for exact-match lookups (e.g. "does a
     * user with this email already exist?") on a column that's otherwise
     * encrypted with encrypt() - AES-256-GCM's random nonce means the same
     * plaintext never produces the same ciphertext twice, so ciphertext
     * itself can't be looked up or uniqueness-constrained in SQL. Keyed
     * with the same encryption key (HMAC, not a plain hash) so this can't
     * be reversed via a dictionary/rainbow-table attack against predictable
     * values like school email addresses by anyone without that key.
     */
    public static function searchHash(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }
}
