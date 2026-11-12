<?php

namespace HOD\Mail\Team;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailNotices;
use HOD\Mail\People;
use HOD\Mail\Read\Format;
use HOD\Mail\Store;

/**
 * Teamwork on shared mailboxes (docs/MAIL.md §7e), ported from Courrier's PR 4: who a conversation is assigned to,
 * its status, and what happened to it. No internal notes (owner).
 *
 *   assign      needs the Assign level; the assignee must be able to reply (Read and send); they get a bell (and so
 *               a push) and, if they chose it, a key alert to their personal email
 *   status      Open → Waiting by itself when a reply leaves from the ERP; Waiting or Done → Open by itself when the
 *               other side writes; Done is a person's choice (Read and send)
 *   activity    one line each: assigned, status, replied, merged (mail_thread_activity)
 */
final class MailTeam
{
    public const STATUSES = ['open' => 'Open', 'waiting' => 'Waiting', 'done' => 'Done'];

    private static ?array $config = null;

    public static function config(string $key, mixed $default = null): mixed
    {
        self::$config ??= require __DIR__ . '/team_config.php';

        return self::$config[$key] ?? $default;
    }

    /** Tests: change settings (null puts them back) */
    public static function configure(?array $values): void
    {
        self::$config = $values === null ? null : array_merge(require __DIR__ . '/team_config.php', $values);
    }

    // ------------------------------------------------------------------ who

    public static function canSee(int $userId, int $threadId): bool
    {
        $thread = $threadId > 0 ? Store::thread($threadId) : null;
        $account = $thread ? Store::account($thread['account_id']) : null;

        return $account !== null && Store::isShared($account) && MailAccess::can($userId, $account, 'read');
    }

    /** People who can be given a conversation: those who can reply from the mailbox. @return list<array{id: int, name: string}> */
    public static function assignees(array $account): array
    {
        if (!Store::isShared($account)) {
            return [];
        }
        $out = [];
        foreach (array_keys(MailAccess::readers($account)) as $id) {
            if (MailAccess::can($id, $account, 'send')) {
                $out[] = ['id' => $id, 'name' => People::name($id)];
            }
        }
        usort($out, fn ($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return $out;
    }

    // ------------------------------------------------------------------ assignment and status

    /** @return bool changed */
    public static function assign(int $byId, int $threadId, ?int $toId): bool
    {
        [$thread, $account] = self::shared($byId, $threadId, 'assign');
        $toId = $toId ?: null;
        if ($toId === $thread['assigned_to']) {
            return false;
        }
        if ($toId !== null && !in_array($toId, array_column(self::assignees($account), 'id'), true)) {
            throw MailError::field('assigned_to', "That person cannot reply from {$account['email']}. Pick someone with Read and send.");
        }
        $now = Clock::sql();
        Db::update('mail_threads', [
            'assigned_to' => $toId,
            'assigned_by' => $toId ? $byId : null,
            'assigned_at' => $toId ? $now : null,
            'updated_at' => $now,
        ], $threadId);
        self::log($threadId, $byId, $toId ? 'assigned' : 'unassigned', array_filter(['to' => $toId, 'from' => $thread['assigned_to']]));
        if ($toId && $toId !== $byId) {
            $title = 'Conversation assigned to you';
            $line = People::name($byId) . ' assigned you “' . self::subject($thread) . "” in {$account['email']}.";
            $link = MailNotices::inboxUrl($account['id'], $threadId);
            MailNotices::send([$toId], $title, $line, $link);
            PersonalAlerts::send($toId, 'assigned', $title, $line, $link, $threadId);
        }

        return true;
    }

    /** A person sets Open, Waiting or Done. @return bool changed */
    public static function setStatus(int $byId, int $threadId, string $status): bool
    {
        [$thread] = self::shared($byId, $threadId, 'send');
        if (!isset(self::STATUSES[$status])) {
            throw MailError::invalid('Pick Open, Waiting or Done.');
        }

        return self::changeStatus($thread, $status, $byId);
    }

    /** The other side wrote: Waiting or Done → Open */
    public static function onReceived(int $threadId, int $messageId): bool
    {
        $thread = Store::thread($threadId);
        if (!$thread || $thread['status'] === 'open') {
            return false;
        }

        return self::changeStatus($thread, 'open', null, ['reason' => 'reply', 'message_id' => $messageId]);
    }

    /** A reply left from the ERP: Open → Waiting (Done stays Done) */
    public static function onSent(int $threadId, int $userId): bool
    {
        $thread = Store::thread($threadId);
        if (!$thread || $thread['status'] !== 'open') {
            return false;
        }

        return self::changeStatus($thread, 'waiting', null, ['reason' => 'sent', 'by' => $userId]);
    }

    // ------------------------------------------------------------------ activity

    /** @return list<array{type: string, text: string, at: string}> newest first */
    public static function activity(int $userId, int $threadId, ?int $limit = null): array
    {
        self::shared($userId, $threadId, 'read');
        $limit = max(1, min(200, $limit ?? (int) self::config('activity_limit', 30)));
        $rows = Db::all(
            "SELECT id, user_id, type, data, created_at FROM mail_thread_activity WHERE thread_id = ? ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            [$threadId],
        );

        return array_map(fn ($r) => [
            'type' => $r['type'],
            'text' => self::sentence($r['type'], $r['user_id'] !== null ? (int) $r['user_id'] : null, Db::decode($r['data']) ?? [], $userId),
            'at' => Format::when($r['created_at'], true),
        ], $rows);
    }

    public static function log(int $threadId, ?int $userId, string $type, array $data = []): void
    {
        Db::insert('mail_thread_activity', [
            'thread_id' => $threadId,
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 30),
            'data' => $data ? Db::json($data) : null,
            'created_at' => Clock::sql(),
        ]);
    }

    /** mail.threads_merged: one line on the conversation that stays */
    public static function merged(int $intoId, array $fromIds): void
    {
        if (Db::value('SELECT 1 FROM mail_threads WHERE id = ?', [$intoId])) {
            self::log($intoId, null, 'merged', ['from' => array_values(array_map('intval', $fromIds))]);
        }
    }

    // ------------------------------------------------------------------ the team bar

    public static function state(int $userId, int $threadId, bool $withActivity = false): array
    {
        [$thread, $account] = self::shared($userId, $threadId, 'read');
        $canSend = MailAccess::can($userId, $account, 'send');
        $canAssign = MailAccess::can($userId, $account, 'assign');
        $assignees = self::assignees($account);

        return [
            'threadId' => $threadId,
            'accountId' => $account['id'],
            'status' => $thread['status'],
            'statusBy' => $thread['status_by'] ? People::firstName($thread['status_by']) : null,
            'assignee' => $thread['assigned_to'] ? self::person($thread['assigned_to'], $userId) : null,
            'assignees' => $canAssign ? array_map(fn ($p) => $p + ['me' => $p['id'] === $userId], $assignees) : [],
            'can' => ['status' => $canSend, 'assign' => $canAssign],
            'presence' => MailPresence::others($userId, $threadId),
            'activity' => $withActivity ? self::activity($userId, $threadId) : null,
            'poll' => (int) self::config('poll_seconds', 20),
        ];
    }

    // ------------------------------------------------------------------ helpers

    public static function subject(array $thread): string
    {
        $subject = trim((string) preg_replace('/\s+/u', ' ', (string) ($thread['subject'] ?? '')));

        return mb_strimwidth($subject !== '' ? $subject : '(no subject)', 0, 80, '…');
    }

    public static function person(int $id, int $me): array
    {
        $name = People::name($id);

        return ['id' => $id, 'name' => $name, 'firstName' => People::firstName($id), 'initials' => Format::initials($name), 'me' => $id === $me];
    }

    private static function changeStatus(array $thread, string $status, ?int $byId, array $data = []): bool
    {
        if ($thread['status'] === $status) {
            return false;
        }
        $now = Clock::sql();
        $done = Db::exec(
            'UPDATE mail_threads SET status = ?, status_by = ?, status_at = ?, updated_at = ? WHERE id = ? AND status = ?',
            [$status, $byId, $now, $now, $thread['id'], $thread['status']],
        );
        if ($done) {
            self::log($thread['id'], $byId, 'status', ['to' => $status, 'from' => $thread['status']] + $data);
        }

        return $done > 0;
    }

    /** @return array{0: array, 1: array} thread and mailbox, after the checks */
    private static function shared(int $userId, int $threadId, string $ability): array
    {
        $thread = $threadId > 0 ? Store::thread($threadId) : null;
        $account = $thread ? Store::account($thread['account_id']) : null;
        if (!$thread || !$account || MailAccess::level($userId, $account) === null) {
            throw MailError::notFound('That conversation is gone.');
        }
        if (!Store::isShared($account)) {
            throw MailError::notFound('Teamwork is for shared mailboxes only.');
        }
        if (!MailAccess::can($userId, $account, $ability)) {
            throw MailError::denied(match ($ability) {
                'assign' => "Assigning needs the Assign level on {$account['email']}. Ask a mail admin.",
                'send' => "Changing the status needs Read and send on {$account['email']}. Ask a mail admin.",
                default => 'This mailbox is not open to you.',
            });
        }

        return [$thread, $account];
    }

    private static function sentence(string $type, ?int $by, array $data, int $me): string
    {
        $mine = $by !== null && $by === $me;
        $who = $mine ? 'You' : ($by !== null ? People::name($by) : 'The ERP');
        $to = isset($data['to']) && is_numeric($data['to']) ? (int) $data['to'] : null;

        return match ($type) {
            'assigned' => match (true) {
                $by !== null && $to === $by => $mine ? 'You took it' : "{$who} took it",
                $to === $me => "{$who} assigned it to you",
                default => "{$who} assigned it to " . People::name($to),
            },
            'unassigned' => "{$who} removed the assignment",
            'status' => match ($data['reason'] ?? null) {
                'reply' => 'Back to Open: a new message came in',
                'sent' => 'Waiting: a reply went out',
                default => "{$who} set it to " . (self::STATUSES[$data['to'] ?? ''] ?? (string) ($data['to'] ?? '?')),
            },
            'sent' => $mine ? 'You replied' : "{$who} replied",
            'merged' => 'Joined with another conversation of the same exchange',
            default => $who . ': ' . str_replace('_', ' ', $type),
        };
    }
}
