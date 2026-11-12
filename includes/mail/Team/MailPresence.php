<?php

namespace HOD\Mail\Team;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\People;
use HOD\Mail\Read\Format;
use HOD\Mail\Store;

/**
 * "Someone is replying" (docs/MAIL.md §7e): presence rows of about a minute on shared mailboxes (mail_presence).
 * The open conversation renews its mark every 20 seconds while the tab is visible and the person active
 * (assets/ui/js/pages/mail-team.js); the composer turns it into "replying". Plus the send-time guard: a teammate's
 * reply that left after you opened the composer stops Send once, with "Send anyway".
 */
final class MailPresence
{
    public const STATES = ['viewing', 'replying'];

    public static function touch(int $userId, int $threadId, string $state = 'viewing'): bool
    {
        if (!in_array($state, self::STATES, true) || !MailTeam::canSee($userId, $threadId)) {
            return false;
        }
        $now = Clock::sql();
        // Viewing never downgrades a live "replying" mark: the reader and the composer renew it apart
        if ($state === 'viewing' && Db::value(
            "SELECT 1 FROM mail_presence WHERE thread_id = ? AND user_id = ? AND state = 'replying' AND expires_at > ?",
            [$threadId, $userId, $now],
        )) {
            $state = 'replying';
        }
        Db::exec(
            'INSERT INTO mail_presence (thread_id, user_id, state, expires_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)',
            [$threadId, $userId, $state, Clock::sql((int) MailTeam::config('presence_seconds', 60)), $now],
        );

        return true;
    }

    public static function stopReplying(int $userId, int $threadId): void
    {
        Db::exec("UPDATE mail_presence SET state = 'viewing' WHERE thread_id = ? AND user_id = ?", [$threadId, $userId]);
    }

    public static function leave(int $userId, int $threadId): void
    {
        Db::exec('DELETE FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$threadId, $userId]);
    }

    /** Teammates on this conversation now (replying first). @return list<array{userId: int, name: string, firstName: string, initials: string, state: string}> */
    public static function others(int $userId, int $threadId): array
    {
        $thread = Store::thread($threadId);
        $account = $thread ? Store::account($thread['account_id']) : null;
        if (!$account || !Store::isShared($account) || !MailAccess::can($userId, $account, 'read')) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT user_id, state FROM mail_presence WHERE thread_id = ? AND user_id <> ? AND expires_at > ?', [$threadId, $userId, Clock::sql()]) as $r) {
            $id = (int) $r['user_id'];
            if (!MailAccess::can($id, $account, 'read')) {
                continue;
            }
            $name = People::name($id);
            $out[] = ['userId' => $id, 'name' => $name, 'firstName' => People::firstName($id), 'initials' => Format::initials($name), 'state' => $r['state']];
        }
        usort($out, fn ($a, $b) => [$a['state'] === 'replying' ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['state'] === 'replying' ? 0 : 1, mb_strtolower($b['name'])]);

        return $out;
    }

    /**
     * The send guard: a reply someone else sent in this conversation after $since (when the composer opened).
     * @return array{name: string, at: string}|null
     */
    public static function repliedSince(int $userId, int $threadId, ?string $since): ?array
    {
        if (!$since || !MailTeam::canSee($userId, $threadId)) {
            return null;
        }
        $row = Db::one(
            "SELECT user_id, created_at FROM mail_thread_activity
              WHERE thread_id = ? AND type = 'sent' AND user_id IS NOT NULL AND user_id <> ? AND created_at > ?
           ORDER BY created_at DESC LIMIT 1",
            [$threadId, $userId, $since],
        );
        if (!$row) {
            return null;
        }

        return ['name' => People::firstName((int) $row['user_id']), 'at' => Format::when($row['created_at'], true)];
    }
}
