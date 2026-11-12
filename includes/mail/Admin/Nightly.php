<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailNotices;
use HOD\Mail\Store;
use Throwable;

/**
 * cron/mail_daily.php (06:45 Lagos). Each step stands alone: one failing never stops the others.
 *
 *   reconcile  disk use and the 80 % warnings, mailboxes gone from the server
 *   access     the resolved readers of every mailbox against last night's snapshot: anyone who lost access to a
 *              shared mailbox -> a new password (defence in depth: nobody is ever shown one); a personal holder who
 *              lost mail.personal or whose account is no longer active -> the "holder left" path
 *   removals   leaving mailboxes past their date -> removed, with a forwarder to info@
 *   exports    downloads past their window closed
 *   prune      presence, old outbox rows, finished jobs
 */
final class Nightly
{
    /** @return array<string, mixed> what each step did */
    public static function run(): array
    {
        $out = [];
        foreach (['reconcile', 'access', 'removals', 'exports', 'prune', 'summary'] as $step) {
            try {
                $out[$step] = self::$step();
            } catch (Throwable $e) {
                error_log("Mail nightly {$step} failed: " . $e->getMessage());
                $out[$step] = 'error: ' . $e->getMessage();
            }
        }

        return $out;
    }

    /** The daily summary (PR 4): a bell, and the personal email for those who chose it */
    public static function summary(): array
    {
        return \HOD\Mail\Team\DailySummary::run();
    }

    public static function reconcile(): array
    {
        $snap = Quota::refresh();
        $warned = 0;
        $missing = 0;
        $warnAt = (int) Config::get('disk.warn_percent', 80);
        foreach (Store::accounts("status IN ('active', 'needs_reset', 'suspended', 'leaving')") as $a) {
            $disk = $snap['disk'][$a['email']] ?? null;
            if ($disk === null) {
                Db::update('mail_accounts', ['status' => 'failed', 'last_error' => 'This mailbox is no longer on the server.', 'updated_at' => Clock::sql()], $a['id']);
                $missing++;
                continue;
            }
            $quota = $disk['quota_mb'] ?: $a['disk_quota_mb'];
            $fill = ['disk_used_mb' => $disk['used_mb'], 'disk_checked_at' => Clock::sql()];
            $percent = $quota > 0 ? (int) floor(100 * $disk['used_mb'] / $quota) : 0;
            $lastWarn = Clock::ageSeconds($a['disk_warned_at']);
            if ($percent >= $warnAt && ($lastWarn === null || $lastWarn > 7 * 86400)) {
                MailNotices::storage($a, $percent);
                $fill['disk_warned_at'] = Clock::sql();
                $warned++;
            } elseif ($percent < $warnAt && $a['disk_warned_at']) {
                $fill['disk_warned_at'] = null;
            }
            Db::update('mail_accounts', $fill, $a['id']);
        }

        return ['warned' => $warned, 'missing' => $missing];
    }

    public static function access(): array
    {
        MailAccess::flush();
        $resets = 0;
        $leaving = 0;
        foreach (Store::accounts("status IN ('active', 'needs_reset', 'suspended')") as $a) {
            if (!Store::isShared($a)) {
                $owner = (int) ($a['owner_user_id'] ?? 0);
                if ($a['status'] !== 'suspended' && MailAccess::level($owner, $a) === null) {
                    Lifecycle::startLeaving($a);
                    $leaving++;
                }
                continue;
            }
            $now = MailAccess::readers($a);
            $before = [];
            foreach (Db::all('SELECT user_id, level FROM mail_access_snapshot WHERE account_id = ?', [$a['id']]) as $r) {
                $before[(int) $r['user_id']] = $r['level'];
            }
            $lost = array_diff_key($before, $now);
            if ($lost && $a['status'] !== 'suspended'
                && !Db::value("SELECT 1 FROM mail_admin_jobs WHERE account_id = ? AND action = 'reset' AND status IN ('queued', 'running')", [$a['id']])) {
                Jobs::queue('reset', $a, ['reason' => 'access_lost', 'users' => array_keys($lost)], null);
                Audit::log('mail_access_lost', $a['email'] . ': users ' . implode(',', array_keys($lost)), null);
                $resets++;
            }
            Db::tx(function () use ($a, $now) {
                Db::exec('DELETE FROM mail_access_snapshot WHERE account_id = ?', [$a['id']]);
                $seen = Clock::sql();
                Db::insertMany('mail_access_snapshot', array_map(fn ($uid, $level) => [
                    'account_id' => (int) $a['id'], 'user_id' => (int) $uid, 'level' => $level, 'seen_at' => $seen,
                ], array_keys($now), $now));
            });
        }

        return ['resets' => $resets, 'leaving' => $leaving];
    }

    public static function removals(): array
    {
        $n = 0;
        $forward = Config::get('leaving.forward_to', 'info') . '@' . Addresses::domain();
        foreach (Store::accounts("status = 'leaving' AND remove_after IS NOT NULL AND remove_after <= ?", [Clock::sql()]) as $a) {
            if (Db::value("SELECT 1 FROM mail_admin_jobs WHERE account_id = ? AND action = 'remove' AND status IN ('queued', 'running')", [$a['id']])) {
                continue;
            }
            // the hold for an export has run out too: an unsettled request ends here
            foreach (Db::all("SELECT id FROM mail_export_requests WHERE account_id = ? AND status IN ('pending', 'approved', 'ready')", [$a['id']]) as $r) {
                Db::update('mail_export_requests', ['status' => 'cancelled', 'updated_at' => Clock::sql()], (int) $r['id']);
                Db::insert('mail_export_events', ['request_id' => (int) $r['id'], 'event' => 'cancelled', 'detail' => 'The mailbox reached its removal date.', 'created_at' => Clock::sql()]);
            }
            Jobs::queue('remove', $a, ['forward_to' => $forward], null);
            $n++;
        }

        return ['queued' => $n];
    }

    public static function exports(): array
    {
        return ['expired' => Exports::expireDue()];
    }

    public static function prune(): array
    {
        return [
            'presence' => Db::exec('DELETE FROM mail_presence WHERE expires_at < ?', [Clock::sql()]),
            'outbox' => Db::exec("DELETE FROM mail_outbox WHERE status IN ('sent', 'cancelled') AND updated_at < ?", [Clock::sql(-30 * 86400)]),
            'jobs' => Db::exec("DELETE FROM mail_admin_jobs WHERE status IN ('done', 'cancelled') AND finished_at < ?", [Clock::sql(-90 * 86400)]),
            // drafts opened and never written in (the page was left), once writing has its tables
            'untouched_drafts' => function_exists('mail_compose_ready') && mail_compose_ready() ? \HOD\Mail\Compose\Outbox::make()->pruneUntouched() : 0,
        ];
    }
}
