<?php

namespace HOD\Mail;

/**
 * Mail's own settings (mail_settings, key => JSON value): quota.reserved_personal (slots kept for personal
 * mailboxes), quota.server (the last count read from cPanel), ai.paid_plan_confirmed (PR 4), signature.* (PR 3),
 * the default grants of a new department mailbox (grants.department_template).
 */
final class MailSettings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = Db::decode(Db::value('SELECT setting_value FROM mail_settings WHERE setting_key = ?', [$key]));
        }

        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        Db::exec(
            'INSERT INTO mail_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$key, Db::json($value), $userId, Clock::sql()],
        );
        self::$cache[$key] = $value;
    }

    public static function forget(string $key): void
    {
        Db::exec('DELETE FROM mail_settings WHERE setting_key = ?', [$key]);
        unset(self::$cache[$key]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
