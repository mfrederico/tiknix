<?php
/**
 * Encryption Service
 * Secure symmetric encryption for sensitive data (API keys, PATs, OAuth tokens)
 * using libsodium. Ported from myctobot; key sourced from conf/config.ini [security] app_key.
 */

namespace app;

use \Flight as Flight;

class EncryptionService {

    /**
     * Encrypt a plaintext string.
     * Returns base64-encoded ciphertext with the nonce prepended.
     *
     * @throws \Exception If encryption fails
     */
    public static function encrypt(string $plaintext): string {
        $key = self::getKey();

        // Random 24-byte nonce for XSalsa20
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $encrypted = base64_encode($nonce . $ciphertext);

        sodium_memzero($key);
        return $encrypted;
    }

    /**
     * Decrypt a ciphertext string produced by encrypt().
     *
     * @throws \Exception If decryption fails
     */
    public static function decrypt(string $encrypted): string {
        $key = self::getKey();

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new \Exception('Invalid encrypted data: base64 decode failed');
        }

        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
        if (strlen($decoded) < $minLength) {
            throw new \Exception('Invalid encrypted data: too short');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        sodium_memzero($key);

        if ($plaintext === false) {
            throw new \Exception('Decryption failed: invalid key or corrupted data');
        }

        return $plaintext;
    }

    /**
     * True if a string looks like valid encrypted data (base64 of at least nonce+MAC+1).
     */
    public static function isEncrypted(string $encrypted): bool {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return false;
        }
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
        return strlen($decoded) >= $minLength;
    }

    /**
     * The binary encryption key from conf/config.ini [security] app_key (64 hex chars = 32 bytes).
     *
     * @throws \Exception If key is missing or malformed
     */
    private static function getKey(): string {
        // Bootstrap flattens config into Flight as "section.key" (there is no nested
        // 'config' array). Read the flattened key; fall back to a nested array if present.
        $hexKey = Flight::get('security.app_key');
        if (empty($hexKey)) { $c = Flight::get('config'); $hexKey = is_array($c) ? ($c['security']['app_key'] ?? null) : null; }

        if (empty($hexKey)) {
            throw new \Exception('Encryption key not configured. Add app_key to the [security] section of conf/config.ini (see EncryptionService::generateKey()).');
        }
        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \Exception('Invalid encryption key format. [security] app_key must be 64 hex characters (32 bytes).');
        }

        return hex2bin($hexKey);
    }

    /**
     * Generate a new 64-hex-char key suitable for conf/config.ini [security] app_key.
     */
    public static function generateKey(): string {
        return bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Constant-time comparison for sensitive values.
     */
    public static function constantTimeCompare(string $a, string $b): bool {
        return hash_equals($a, $b);
    }

    /**
     * One-way hash for values that never need decrypting (e.g. webhook secrets).
     */
    public static function hash(string $value): string {
        return sodium_crypto_generichash($value, '', SODIUM_CRYPTO_GENERICHASH_BYTES);
    }

    public static function hashHex(string $value): string {
        return bin2hex(self::hash($value));
    }

    /**
     * Mask a secret for display — shows a short prefix/suffix only.
     */
    public static function mask(string $secret): string {
        if ($secret === '') return '(empty)';
        $len = strlen($secret);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($secret, 0, 4) . '…' . substr($secret, -4);
    }
}
