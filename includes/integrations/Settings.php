<?php

namespace HOD\Integrations;

use PDO;
use Throwable;

/**
 * integration_settings, one row per setting (db/migrations/20261210090000_integrations_google.sql). Values are
 * strings; secrets go through secret()/setSecret(), which encrypt with the Vault, bound to the setting's key.
 * Reads are cached for the request. Tests: Settings::usePdo($pdo).
 */
final class Settings
{
    private static ?PDO $pdo = null;
    /** @var array<string, ?string>|null */
    private static ?array $cache = null;

    public static function usePdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$cache = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
            require_once dirname(__DIR__) . '/db.php';
        }

        return $GLOBALS['pdo'];
    }

    /** Whether the table exists (the code reaches the server before the migration runs) */
    public static function ready(): bool
    {
        try {
            self::pdo()->query('SELECT setting_key FROM integration_settings WHERE 1 = 0');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function get(string $key): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (self::pdo()->query('SELECT setting_key, setting_value FROM integration_settings')->fetchAll(PDO::FETCH_NUM) as [$k, $v]) {
                self::$cache[(string) $k] = $v === null ? null : (string) $v;
            }
        }

        return self::$cache[$key] ?? null;
    }

    /** Null deletes the row */
    public static function set(string $key, ?string $value, ?int $by = null): void
    {
        $pdo = self::pdo();
        if ($value === null) {
            $pdo->prepare('DELETE FROM integration_settings WHERE setting_key = ?')->execute([$key]);
        } else {
            $now = date('Y-m-d H:i:s');
            $up = $pdo->prepare('UPDATE integration_settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE setting_key = ?');
            $up->execute([$value, $by, $now, $key]);
            if ($up->rowCount() === 0 && self::get($key) === null) {
                $pdo->prepare('INSERT INTO integration_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$key, $value, $by, $now]);
            }
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /** A secret, decrypted; null when absent or unreadable (wrong key, damaged, another row's value) */
    public static function secret(string $key): ?string
    {
        return Vault::decrypt(self::get($key), $key);
    }

    /** @throws \RuntimeException when INTEGRATIONS_VAULT_KEY is missing: nothing is ever stored in the clear */
    public static function setSecret(string $key, ?string $plain, ?int $by = null): void
    {
        self::set($key, $plain === null ? null : Vault::encrypt($plain, $key), $by);
    }

    /** When a setting last changed, and by whom: [at, user id] */
    public static function changed(string $key): ?array
    {
        $st = self::pdo()->prepare('SELECT updated_at, updated_by FROM integration_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_NUM);

        return $row ? [(string) $row[0], $row[1] === null ? null : (int) $row[1]] : null;
    }
}
