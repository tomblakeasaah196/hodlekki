<?php

namespace HOD\Mail;

use RuntimeException;

/**
 * Mailbox passwords at rest (docs/MAIL.md, "Vault").
 *
 *   MAIL_VAULT_KEY            64 hex characters in .env, its own key (not SMS_VAULT_KEY), so neither rotation
 *                             breaks the other. Generate on the server: php -r "echo bin2hex(random_bytes(32));"
 *   MAIL_VAULT_KEY_PREVIOUS   the old key while bin/mail_rekey.php moves every row to a new one
 *
 * AES-256-GCM through openssl (the web PHP has no sodium). Stored as "v1:<key id>:<base64(iv . tag . ciphertext)>",
 * where the key id is the first 8 hex of sha256(key): a changed key is recognised, not mistaken for damage.
 * The associated data is "hod-mail:<mailbox id>:<address>", so a ciphertext copied onto another row will not
 * decrypt.
 *
 * Passwords are made only by the cron's admin jobs, never shown to anyone (mail is read in the ERP only),
 * never put in JSON, a log, an exception, the session or the audit log. Only MailServer and the jobs decrypt.
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
        return self::bytes(self::currentHex()) !== null;
    }

    /** @throws RuntimeException when the key is missing (nothing is stored in the clear, ever) */
    public static function encrypt(string $plain, int $accountId, string $email): string
    {
        $key = self::bytes(self::currentHex());
        if ($key === null) {
            throw new RuntimeException('MAIL_VAULT_KEY is missing or is not 64 hex characters.');
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($accountId, $email), self::TAG_LEN);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::VERSION . ':' . self::keyId($key) . ':' . base64_encode($iv . $tag . $cipher);
    }

    /** The password, or null when it cannot be read (wrong key, another row's value, damaged). Never throws. */
    public static function decrypt(?string $stored, int $accountId, string $email): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $parts = explode(':', $stored, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }
        [, $keyId, $payload] = $parts;
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        foreach ([self::currentHex(), self::previousHex()] as $hex) {
            $key = self::bytes($hex);
            if ($key === null || !hash_equals(self::keyId($key), $keyId)) {
                continue;
            }
            $plain = openssl_decrypt(
                substr($raw, self::IV_LEN + self::TAG_LEN),
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                substr($raw, 0, self::IV_LEN),
                substr($raw, self::IV_LEN, self::TAG_LEN),
                self::aad($accountId, $email),
            );

            return $plain === false ? null : $plain;
        }

        return null;
    }

    /** Whether a stored value was made with the current key (bin/mail_rekey.php re-encrypts the others) */
    public static function isCurrent(?string $stored): bool
    {
        $key = self::bytes(self::currentHex());
        $parts = explode(':', (string) $stored, 3);

        return $key !== null && count($parts) === 3 && hash_equals(self::keyId($key), $parts[1]);
    }

    /** A new mailbox password: 24 characters, every class present (cPanel's strength meter scores it 100) */
    public static function newPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_.!@#%+='];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < 24) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function aad(int $accountId, string $email): string
    {
        return 'hod-mail:' . $accountId . ':' . strtolower(trim($email));
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    private static function bytes(?string $hex): ?string
    {
        $hex = trim((string) $hex);

        return strlen($hex) === 64 && ctype_xdigit($hex) ? hex2bin($hex) : null;
    }

    private static function currentHex(): ?string
    {
        return self::$keys !== null ? self::$keys[0] : (function_exists('site_env') ? site_env('MAIL_VAULT_KEY') : null);
    }

    private static function previousHex(): ?string
    {
        return self::$keys !== null ? self::$keys[1] : (function_exists('site_env') ? site_env('MAIL_VAULT_KEY_PREVIOUS') : null);
    }
}
