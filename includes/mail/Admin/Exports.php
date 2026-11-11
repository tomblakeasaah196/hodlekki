<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailNotices;
use HOD\Mail\MailServer;
use HOD\Mail\People;
use HOD\Mail\Store;

/**
 * A departed holder's mail (lead, 11 Nov 2026): nobody reads it, except through a logged request approved by TWO
 * DIFFERENT people, one holding mail.export_admin (Super Admin in the matrix) and one holding mail.export_pastoral
 * (the Resident Pastor; break-glass does not count for this side). Once both approve, a job lifts the login
 * suspension, the requester downloads each folder as mbox, streamed from the server and never stored here, for
 * leaving.export_open_days; then a job suspends the login again. Every step goes to mail_export_events and
 * security_audit_log. The mailbox is not removed while a request is open (at most leaving.export_hold_days more).
 */
final class Exports
{
    public const OPEN = ['pending', 'approved', 'ready'];

    public static function request(int $actorId, int $accountId, string $reason): int
    {
        if (!MailAccess::allows($actorId, 'admin') && !MailAccess::allows($actorId, 'export_admin') && !MailAccess::allows($actorId, 'export_pastoral')) {
            throw MailError::denied('Export requests are made by mail admins, Super Admins or the Resident Pastor.');
        }
        $account = Store::account($accountId) ?? throw MailError::notFound();
        if ($account['kind'] !== 'personal' || $account['status'] !== 'leaving') {
            throw MailError::invalid('Only the mailbox of someone who has left can be exported.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 15) {
            throw MailError::field('reason', 'Say why the mail is needed (at least a sentence). Both approvers will read it.');
        }
        if (self::openFor($accountId)) {
            throw MailError::invalid('A request for this mailbox is already open.');
        }
        $now = Clock::sql();
        $id = Db::insert('mail_export_requests', [
            'account_id' => $accountId, 'email' => $account['email'], 'requested_by' => $actorId, 'reason' => mb_substr($reason, 0, 2000),
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::event($id, $actorId, 'requested', null);
        // hold the removal while the request is open (at most export_hold_days beyond the planned date)
        Db::exec('UPDATE mail_accounts SET remove_after = GREATEST(COALESCE(remove_after, ?), ?) WHERE id = ?',
            [$now, Clock::sql(86400 * (int) Config::get('leaving.export_hold_days', 30)), $accountId]);
        $approvers = array_merge(
            function_exists('perm_holders') ? perm_holders(Db::pdo(), 'mail.export_admin', 'approve', null, true) : [],
            function_exists('perm_holders') ? perm_holders(Db::pdo(), 'mail.export_pastoral', 'approve') : [],
        );
        MailNotices::exportWaiting(array_values(array_diff(array_unique($approvers), [$actorId])), $account['email'], People::name($actorId));

        return $id;
    }

    /** $side: 'admin' or 'pastoral' */
    public static function approve(int $actorId, int $requestId, string $side): array
    {
        $r = self::find($requestId) ?? throw MailError::notFound();
        if ($r['status'] !== 'pending') {
            throw MailError::invalid('This request is no longer waiting for approval.');
        }
        $ability = $side === 'pastoral' ? 'export_pastoral' : 'export_admin';
        if (!in_array($side, ['admin', 'pastoral'], true) || !MailAccess::allows($actorId, $ability)) {
            throw MailError::denied('You cannot give this approval.');
        }
        $mine = $side === 'admin' ? 'admin_approved_by' : 'pastor_approved_by';
        $other = $side === 'admin' ? 'pastor_approved_by' : 'admin_approved_by';
        if ($r[$mine] !== null) {
            throw MailError::invalid('This approval has already been given.');
        }
        if ($r[$other] === $actorId) {
            throw MailError::invalid('The two approvals must come from two different people.');
        }
        $now = Clock::sql();
        Db::update('mail_export_requests', [$mine => $actorId, str_replace('_by', '_at', $mine) => $now, 'updated_at' => $now], $requestId);
        self::event($requestId, $actorId, 'approved_' . $side, null);
        $r = self::find($requestId);
        if ($r['admin_approved_by'] !== null && $r['pastor_approved_by'] !== null) {
            Db::update('mail_export_requests', ['status' => 'approved', 'updated_at' => $now], $requestId);
            $account = Store::account((int) $r['account_id']) ?? throw MailError::notFound();
            Jobs::queue('export_open', $account, ['request_id' => $requestId], $actorId);
        }

        return self::find($requestId);
    }

    public static function refuse(int $actorId, int $requestId): void
    {
        $r = self::find($requestId) ?? throw MailError::notFound();
        if (!MailAccess::allows($actorId, 'export_admin') && !MailAccess::allows($actorId, 'export_pastoral')) {
            throw MailError::denied('You cannot refuse this request.');
        }
        if ($r['status'] !== 'pending') {
            throw MailError::invalid('This request is no longer waiting for approval.');
        }
        Db::update('mail_export_requests', ['status' => 'refused', 'refused_by' => $actorId, 'refused_at' => Clock::sql(), 'updated_at' => Clock::sql()], $requestId);
        self::event($requestId, $actorId, 'refused', null);
    }

    /** The export_open job finished: downloads are open */
    public static function opened(int $requestId): void
    {
        $r = self::find($requestId);
        if (!$r || $r['status'] !== 'approved') {
            return;
        }
        $now = Clock::sql();
        Db::update('mail_export_requests', ['status' => 'ready', 'ready_at' => $now, 'expires_at' => Clock::sql(86400 * (int) Config::get('leaving.export_open_days', 7)), 'updated_at' => $now], $requestId);
        self::event($requestId, null, 'ready', null);
        MailNotices::exportReady((int) $r['requested_by'], $r['email']);
    }

    /** Nightly: close what has run out, and suspend the login again */
    public static function expireDue(): int
    {
        $n = 0;
        foreach (Db::all("SELECT id, account_id FROM mail_export_requests WHERE status = 'ready' AND expires_at < ?", [Clock::sql()]) as $r) {
            Db::update('mail_export_requests', ['status' => 'expired', 'updated_at' => Clock::sql()], (int) $r['id']);
            self::event((int) $r['id'], null, 'expired', null);
            $account = $r['account_id'] ? Store::account((int) $r['account_id']) : null;
            if ($account) {
                Jobs::queue('export_close', $account, ['request_id' => (int) $r['id']], null);
            }
            $n++;
        }

        return $n;
    }

    public static function openFor(int $accountId): bool
    {
        return (bool) Db::value('SELECT 1 FROM mail_export_requests WHERE account_id = ? AND status IN (' . Db::marks(count(self::OPEN)) . ') LIMIT 1',
            array_merge([$accountId], self::OPEN));
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM mail_export_requests WHERE id = ?', [$id]);
        if (!$r) {
            return null;
        }
        foreach (['id', 'account_id', 'requested_by', 'admin_approved_by', 'pastor_approved_by', 'refused_by'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }

        return $r;
    }

    /** @return list<array> requests a person may see (admins and approvers: all; others: none) */
    public static function visibleTo(int $userId): array
    {
        if (!MailAccess::allows($userId, 'admin') && !MailAccess::allows($userId, 'export_admin') && !MailAccess::allows($userId, 'export_pastoral')) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT id FROM mail_export_requests ORDER BY id DESC LIMIT 50') as $row) {
            $r = self::find((int) $row['id']);
            $r['requested_by_name'] = People::name($r['requested_by']);
            $r['admin_approved_by_name'] = $r['admin_approved_by'] ? People::name($r['admin_approved_by']) : null;
            $r['pastor_approved_by_name'] = $r['pastor_approved_by'] ? People::name($r['pastor_approved_by']) : null;
            $r['can_approve_admin'] = $r['status'] === 'pending' && $r['admin_approved_by'] === null && $r['pastor_approved_by'] !== $userId && MailAccess::allows($userId, 'export_admin');
            $r['can_approve_pastoral'] = $r['status'] === 'pending' && $r['pastor_approved_by'] === null && $r['admin_approved_by'] !== $userId && MailAccess::allows($userId, 'export_pastoral');
            $r['can_download'] = $r['status'] === 'ready' && $r['requested_by'] === $userId;
            $out[] = $r;
        }

        return $out;
    }

    /** @return list<string> folder paths the requester can download */
    public static function folders(int $userId, int $requestId): array
    {
        $r = self::readyFor($userId, $requestId);
        $session = MailServer::make()->open(Store::account((int) $r['account_id']));
        try {
            return array_values(array_map(fn ($f) => $f['path'], array_filter($session->folders(), fn ($f) => !in_array('\\noselect', array_map('strtolower', $f['attributes'] ?? []), true))));
        } finally {
            $session->logout();
        }
    }

    /** Stream one folder as mbox to the browser (the endpoint has sent the headers). */
    public static function stream(int $userId, int $requestId, string $path, callable $write): int
    {
        $r = self::readyFor($userId, $requestId);
        $account = Store::account((int) $r['account_id']) ?? throw MailError::notFound();
        $session = MailServer::make()->open($account);
        $count = 0;
        try {
            $session->examine($path);
            foreach (array_chunk($session->search('ALL'), 20) as $uids) {
                foreach ($session->fetchSectionsBatch($uids, ['']) as $sections) {
                    $raw = str_replace("\r\n", "\n", (string) ($sections[''] ?? ''));
                    $raw = preg_replace('/^(>*From )/m', '>$1', $raw) ?? $raw;
                    $write('From MAILER-DAEMON ' . gmdate('D M j H:i:s Y') . "\n" . rtrim($raw, "\n") . "\n\n");
                    $count++;
                }
            }
        } finally {
            $session->logout();
        }
        self::event($requestId, $userId, 'downloaded', mb_substr($path, 0, 200) . " ({$count} messages)");

        return $count;
    }

    private static function readyFor(int $userId, int $requestId): array
    {
        $r = self::find($requestId) ?? throw MailError::notFound();
        if ($r['requested_by'] !== $userId) {
            throw MailError::denied('Only the person who asked for this export can download it.');
        }
        if ($r['status'] !== 'ready' || ($r['expires_at'] && $r['expires_at'] < Clock::sql())) {
            throw MailError::invalid('This export is not open for download.');
        }

        return $r;
    }

    private static function event(int $requestId, ?int $userId, string $event, ?string $detail): void
    {
        Db::insert('mail_export_events', ['request_id' => $requestId, 'user_id' => $userId, 'event' => $event, 'detail' => $detail, 'created_at' => Clock::sql()]);
        $r = Db::one('SELECT email FROM mail_export_requests WHERE id = ?', [$requestId]);
        Audit::log('mail_export_' . $event, ($r['email'] ?? '') . ($detail ? ': ' . $detail : ''), $userId);
    }
}
