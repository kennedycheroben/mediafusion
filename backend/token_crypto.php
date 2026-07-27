<?php
declare(strict_types=1);

/**
 * backend/token_crypto.php
 *
 * Authenticated encryption for OAuth tokens at rest.
 * Uses AES-256-GCM (authenticated encryption with associated data).
 *
 * Design:
 *  - Encryption key loaded from ENCRYPTION_KEY constant (set via .env)
 *  - Each encrypted value includes: version byte + 12-byte IV + ciphertext + 16-byte auth tag
 *  - Prefix 'enc:' distinguishes encrypted values from legacy plaintext
 *  - Supports future key rotation via version byte
 *  - Decrypt never trusts ciphertext without authentication
 *  - Plaintext kept in memory only for the shortest possible time
 */

class TokenCrypto {
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const PREFIX = 'enc:';
    private const KEY_VERSION = 1;

    private string $key;

    public function __construct() {
        $rawKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
        if ($rawKey === '' || $rawKey === 'CHANGE_ME') {
            throw new RuntimeException('ENCRYPTION_KEY is not configured. Set it in your .env file.');
        }
        $this->key = hex2bin($rawKey);
        if ($this->key === false || strlen($this->key) !== 32) {
            throw new RuntimeException('ENCRYPTION_KEY must be a 64-character hex string (32 bytes).');
        }
    }

    /**
     * Encrypt a plaintext string. Returns prefixed ciphertext.
     */
    public function encrypt(string $plaintext): string {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Token encryption failed.');
        }
        // Format: enc:version:base64(iv + tag + ciphertext)
        $packed = $iv . $tag . $ciphertext;
        return self::PREFIX . self::KEY_VERSION . ':' . base64_encode($packed);
    }

    /**
     * Decrypt an encrypted string back to plaintext.
     * Returns plaintext or throws RuntimeException on failure.
     */
    public function decrypt(string $encoded): string {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Value is not encrypted (missing prefix).');
        }
        $body = substr($encoded, strlen(self::PREFIX));
        $colonPos = strpos($body, ':');
        if ($colonPos === false) {
            throw new RuntimeException('Invalid encrypted format.');
        }
        $version = (int)substr($body, 0, $colonPos);
        if ($version !== self::KEY_VERSION) {
            throw new RuntimeException("Unsupported encryption version: {$version}");
        }
        $packed = base64_decode(substr($body, $colonPos + 1), true);
        if ($packed === false || strlen($packed) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Invalid encrypted payload.');
        }
        $iv = substr($packed, 0, self::IV_LENGTH);
        $tag = substr($packed, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($packed, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('Token decryption failed (authentication error).');
        }
        return $plaintext;
    }

    /**
     * Check if a value is encrypted (has our prefix).
     */
    public static function isEncrypted(string $value): bool {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Encrypt a value only if it is not already encrypted.
     * Safe for migration: existing encrypted values pass through unchanged.
     */
    public function encryptIfNeeded(string $value): string {
        if ($value === '' || self::isEncrypted($value)) {
            return $value;
        }
        return $this->encrypt($value);
    }

    /**
     * Decrypt a value if it is encrypted, or return as-is if plaintext.
     * WARNING: Prefer decrypt() for new code. This exists only for migration compatibility.
     */
    public function decryptIfNeeded(string $value): string {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        return $this->decrypt($value);
    }
}

/**
 * Get or create a singleton TokenCrypto instance.
 * Throws if ENCRYPTION_KEY is not configured.
 */
function getTokenCrypto(): TokenCrypto {
    static $instance = null;
    if ($instance === null) {
        $instance = new TokenCrypto();
    }
    return $instance;
}
