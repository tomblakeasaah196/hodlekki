<?php

namespace HOD\Mail\Team;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailError;
use Throwable;

/**
 * A person's alert choices (mail_user_prefs; My mail settings, modules/mail/settings.php).
 *
 *   push(user, mailbox)   new mail in that mailbox: 'all' (default), 'mine' (only conversations assigned to me), 'off'
 *   general(user)         'summary' (the daily summary in the app, default on) and 'personal' => which key alerts
 *                         go to the confirmed personal email (all off until ticked): personal_mail, assigned,
 *                         summary, attention
 *
 * Until the table exists (the code reaches the server before its migration) everyone has the defaults.
 */
final class Prefs
{
    public const PUSH = ['all' => 'All new mail', 'mine' => 'Assigned to me', 'off' => 'Off'];

    public const PERSONAL = [
        'personal_mail' => 'New mail in my personal mailbox',
        'assigned' => 'A conversation assigned to me',
        'summary' => 'The daily summary',
        'attention' => 'A mailbox I manage needs attention',
    ];

    private static array $cache = [];

    public static function push(int $userId, int $accountId): string
    {
        $row = self::row($userId, $accountId);

        return isset(self::PUSH[$row['push'] ?? '']) ? (string) $row['push'] : 'all';
    }

    /** @return array{summary: bool, personal: array<string, bool>} */
    public static function general(int $userId): array
    {
        $data = Db::decode(self::row($userId, 0)['data'] ?? null) ?? [];
        $personal = [];
        foreach (array_keys(self::PERSONAL) as $k) {
            $personal[$k] = !empty($data['personal'][$k]);
        }

        return ['summary' => !array_key_exists('summary', $data) || !empty($data['summary']), 'personal' => $personal];
    }

    public static function setPush(int $userId, int $accountId, string $push): void
    {
        if ($accountId <= 0 || !isset(self::PUSH[$push])) {
            throw MailError::invalid('Pick All, Assigned to me or Off.');
        }
        self::write($userId, $accountId, ['push' => $push]);
    }

    /** @param array{summary?: bool, personal?: array<string, bool>} $values */
    public static function setGeneral(int $userId, array $values): void
    {
        $now = self::general($userId);
        $data = [
            'summary' => array_key_exists('summary', $values) ? (bool) $values['summary'] : $now['summary'],
            'personal' => $now['personal'],
        ];
        foreach ((array) ($values['personal'] ?? []) as $k => $on) {
            if (isset(self::PERSONAL[$k])) {
                $data['personal'][$k] = (bool) $on;
            }
        }
        self::write($userId, 0, ['data' => Db::json($data)]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function row(int $userId, int $accountId): array
    {
        $key = $userId . ':' . $accountId;
        if (!array_key_exists($key, self::$cache)) {
            try {
                self::$cache[$key] = Db::one('SELECT push, data FROM mail_user_prefs WHERE user_id = ? AND account_id = ?', [$userId, $accountId]) ?? [];
            } catch (Throwable) {
                self::$cache[$key] = [];
            }
        }

        return self::$cache[$key];
    }

    private static function write(int $userId, int $accountId, array $set): void
    {
        $now = Clock::sql();
        $push = $set['push'] ?? self::row($userId, $accountId)['push'] ?? null;
        $data = $set['data'] ?? self::row($userId, $accountId)['data'] ?? null;
        Db::exec(
            'INSERT INTO mail_user_prefs (user_id, account_id, push, data, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE push = VALUES(push), data = VALUES(data), updated_at = VALUES(updated_at)',
            [$userId, $accountId, $push, is_array($data) ? Db::json($data) : $data, $now],
        );
        unset(self::$cache[$userId . ':' . $accountId]);
    }
}
