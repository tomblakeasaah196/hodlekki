<?php

namespace HOD\Integrations;

use RuntimeException;

/**
 * Integration secrets at rest (docs/INTEGRATIONS.md), the same design as Mail's vault (includes/mail/Vault.php) with
 * its own key, so rotating one never breaks the other.
 *
 *   INTEGRATIONS_VAULT_KEY            64 hex characters in .env. On the server: php -r "echo bin2hex(random_bytes(32));"
 *   INTEGRATIONS_VAULT_KEY_PREVIOUS   the old key during a rotation (values made with it still read)
 *
 * AES-256-GCM through openssl. Stored as "v1:<key id>:<base64(iv . tag . ciphertext)>", the key id being the first
 * 8 hex of sha256(key). The associated data is "hod-integrations:<setting key>", so a value copied onto another
 * setting will not decrypt. A lost key costs one thing: the admin presses Connect again.
 */
final class Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /** Tests: [current hex, previous hex] instead of .env */
    private static ?array $keys = null;

    public static function useKeys(?string $current, ?string $previous = null): void
    {
        self::$keys = $current === null ? null : [$current, $previous];
    }

    public static function ready(): bool
    {
        return self::bytes(self::hex(0)) !== null;
    }

    public static function encrypt(string $plain, string $setting): string
    {
        $key = self::bytes(self::hex(0));
        if ($key === null) {
            throw new RuntimeException('INTEGRATIONS_VAULT_KEY is missing or is not 64 hex characters.');
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($setting), self::TAG_LEN);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::VERSION . ':' . self::keyId($key) . ':' . base64_encode($iv . $tag . $cipher);
    }

    /** The secret, or null when it cannot be read. Never throws. */
    public static function decrypt(?string $stored, string $setting): ?string
    {
        $parts = explode(':', (string) $stored, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }
        $raw = base64_decode($parts[2], true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        foreach ([self::hex(0), self::hex(1)] as $hex) {
            $key = self::bytes($hex);
            if ($key === null || !hash_equals(self::keyId($key), $parts[1])) {
                continue;
            }
            $plain = openssl_decrypt(substr($raw, self::IV_LEN + self::TAG_LEN), self::CIPHER, $key, OPENSSL_RAW_DATA,
                substr($raw, 0, self::IV_LEN), substr($raw, self::IV_LEN, self::TAG_LEN), self::aad($setting));

            return $plain === false ? null : $plain;
        }

        return null;
    }

    private static function aad(string $setting): string
    {
        return 'hod-integrations:' . $setting;
    }

    private static function hex(int $which): string
    {
        if (self::$keys !== null) {
            return (string) (self::$keys[$which] ?? '');
        }

        return trim(site_env($which === 0 ? 'INTEGRATIONS_VAULT_KEY' : 'INTEGRATIONS_VAULT_KEY_PREVIOUS'));
    }

    private static function bytes(string $hex): ?string
    {
        return preg_match('/^[0-9a-fA-F]{64}$/', $hex) ? (string) hex2bin($hex) : null;
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }
}
