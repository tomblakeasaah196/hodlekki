<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailNotices;
use HOD\Mail\Store;
use Throwable;

/**
 * The queue of cPanel actions (mail_admin_jobs). Pages only write a job; cron/mail_minute.php runs them first in
 * each minute, one at a time and in order (one per mailbox: a later action on the same mailbox waits for the
 * earlier one). params never holds a password: a job that needs one makes it (Lifecycle).
 *
 * A job left 'running' for jobs.stuck_minutes (the process died) is retried once; handlers check cPanel's real
 * state first, so a retry never makes a mailbox twice.
 */
final class Jobs
{
    public const LABELS = [
        'create' => 'Create the mailbox', 'bring_in' => 'Bring the mailbox into the ERP', 'reset' => 'Reset the password',
        'suspend' => 'Suspend', 'resume' => 'Resume', 'set_quota' => 'Change the storage limit',
        'auto_reply_on' => 'Turn the automatic reply on', 'auto_reply_off' => 'Turn the automatic reply off',
        'leave' => 'Close the mailbox of someone who left', 'remove' => 'Remove the mailbox',
        'forwarder_add' => 'Add a forwarder', 'forwarder_remove' => 'Remove a forwarder',
        'export_open' => 'Open the mailbox for an approved export', 'export_close' => 'Close the mailbox after an export',
    ];

    public static function queue(string $action, ?array $account, array $params, ?int $actorId, ?string $email = null): int
    {
        if (!isset(self::LABELS[$action])) {
            throw new \InvalidArgumentException('Unknown mail job ' . $action);
        }

        return Db::insert('mail_admin_jobs', [
            'action' => $action,
            'account_id' => $account ? (int) $account['id'] : null,
            'email' => $email ?? ($account['email'] ?? null),
            'params' => $params,
            'requested_by' => $actorId,
            'status' => 'queued',
            'created_at' => Clock::sql(),
        ]);
    }

    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM mail_admin_jobs WHERE id = ?', [$id]);

        return $row ? self::row($row) : null;
    }

    /** @return list<array> jobs still waiting or running, oldest first */
    public static function open(): array
    {
        return array_map([self::class, 'row'], Db::all("SELECT * FROM mail_admin_jobs WHERE status IN ('queued', 'running') ORDER BY id"));
    }

    /** @return list<array> the latest jobs, newest first */
    public static function recent(int $limit = 30): array
    {
        return array_map([self::class, 'row'], Db::all('SELECT * FROM mail_admin_jobs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))));
    }

    /**
     * Run what is due (cron only). @return array{done: int, failed: int, busy?: bool}
     */
    public static function run(?int $limit = null): array
    {
        $out = ['done' => 0, 'failed' => 0];
        if (!Db::lock('hod-mail-jobs')) {
            return $out + ['busy' => true];
        }
        try {
            self::recoverStuck();
            $limit ??= (int) Config::get('jobs.per_run', 10);
            for ($i = 0; $i < $limit; $i++) {
                $job = self::next();
                if ($job === null) {
                    break;
                }
                self::runOne($job) ? $out['done']++ : $out['failed']++;
            }
        } finally {
            Db::release('hod-mail-jobs');
        }

        return $out;
    }

    private static function next(): ?array
    {
        foreach (Db::all("SELECT * FROM mail_admin_jobs WHERE status = 'queued' ORDER BY id LIMIT 50") as $row) {
            // one per mailbox, in order: wait while an earlier job of the same mailbox is still open
            if ($row['account_id'] !== null && Db::value(
                "SELECT 1 FROM mail_admin_jobs WHERE account_id = ? AND id < ? AND status IN ('queued', 'running') LIMIT 1",
                [$row['account_id'], $row['id']],
            )) {
                continue;
            }
            $claimed = Db::exec(
                "UPDATE mail_admin_jobs SET status = 'running', attempts = attempts + 1, started_at = ? WHERE id = ? AND status = 'queued'",
                [Clock::sql(), $row['id']],
            );
            if ($claimed === 1) {
                return self::find((int) $row['id']);
            }
        }

        return null;
    }

    private static function runOne(array $job): bool
    {
        try {
            $result = Lifecycle::handle($job);
            Db::update('mail_admin_jobs', ['status' => 'done', 'result' => $result ?: null, 'error' => null, 'finished_at' => Clock::sql()], $job['id']);
            Audit::log('mail_job_done', $job['action'] . ' ' . ($job['email'] ?? ''), $job['requested_by']);

            return true;
        } catch (Throwable $e) {
            $message = ($e instanceof UapiException || $e instanceof \HOD\Mail\MailError) ? $e->getMessage()
                : 'The server refused it.';
            if (!($e instanceof UapiException || $e instanceof \HOD\Mail\MailError)) {
                error_log('Mail job ' . $job['id'] . ' (' . $job['action'] . ') failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            }
            Db::update('mail_admin_jobs', ['status' => 'failed', 'error' => mb_substr($message, 0, 500), 'finished_at' => Clock::sql()], $job['id']);
            Lifecycle::failed($job, $message);
            $job['error'] = $message;
            MailNotices::jobFailed($job, self::LABELS[$job['action']] ?? $job['action']);
            Audit::log('mail_job_failed', $job['action'] . ' ' . ($job['email'] ?? '') . ': ' . $message, $job['requested_by']);

            return false;
        }
    }

    private static function recoverStuck(): void
    {
        $before = Clock::sql(-60 * (int) Config::get('jobs.stuck_minutes', 10));
        foreach (Db::all("SELECT id, attempts FROM mail_admin_jobs WHERE status = 'running' AND started_at < ?", [$before]) as $row) {
            if ((int) $row['attempts'] < 2) {
                Db::exec("UPDATE mail_admin_jobs SET status = 'queued' WHERE id = ? AND status = 'running'", [$row['id']]);
            } else {
                Db::exec(
                    "UPDATE mail_admin_jobs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status = 'running'",
                    ['It stopped half way twice. Check the mailbox on the Mailboxes screen and try again.', Clock::sql(), $row['id']],
                );
            }
        }
    }

    private static function row(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['account_id'] = $r['account_id'] !== null ? (int) $r['account_id'] : null;
        $r['requested_by'] = $r['requested_by'] !== null ? (int) $r['requested_by'] : null;
        $r['params'] = Db::decode($r['params']) ?? [];
        $r['result'] = Db::decode($r['result']);
        $r['label'] = self::LABELS[$r['action']] ?? $r['action'];

        return $r;
    }
}
