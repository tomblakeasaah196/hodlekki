<?php

namespace HOD\Mail\Team;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailNotices;
use HOD\Mail\Store;
use Throwable;

/**
 * The daily summary (cron/mail_daily.php, 06:45 Lagos; docs/MAIL.md §7e), only when there is something to say:
 *
 *   "info@: 4 unread, 2 open over a day. Assigned to you: 3 open (oldest 2 days). Waiting for replies: 5."
 *
 * One bell (which also pushes), unless the person turned the summary off; and to their personal email when they
 * ticked it (PersonalAlerts). Counts only: never a subject or a sender.
 */
final class DailySummary
{
    /** @return array{people: int, bells: int} */
    public static function run(): array
    {
        $people = [];
        foreach (Store::accounts("status IN ('active', 'needs_reset')") as $account) {
            foreach (array_keys(MailAccess::readers($account)) as $uid) {
                $people[$uid] = true;
            }
        }
        $bells = 0;
        foreach (array_keys($people) as $uid) {
            try {
                $line = self::line((int) $uid);
                if ($line === null) {
                    continue;
                }
                $prefs = Prefs::general((int) $uid);
                if ($prefs['summary']) {
                    MailNotices::send([(int) $uid], 'Mail today', $line, '/modules/mail/index.php');
                    $bells++;
                }
                PersonalAlerts::send((int) $uid, 'summary', 'Your mail today', $line, '/modules/mail/index.php');
            } catch (Throwable $e) {
                error_log('Mail: daily summary for user ' . $uid . ' failed: ' . $e->getMessage());
            }
        }

        return ['people' => count($people), 'bells' => $bells];
    }

    /** The person's line, or null when there is nothing to say */
    public static function line(int $userId): ?string
    {
        $parts = [];
        $openSince = Clock::sql(-3600 * (int) MailTeam::config('summary_open_hours', 24));
        $shared = [];
        foreach (MailAccess::accounts($userId, 'read') as $a) {
            $inbox = Store::folderByRole((int) $a['id'], 'inbox');
            $unread = $inbox ? (int) Db::value(
                'SELECT COUNT(DISTINCT thread_id) FROM mail_messages WHERE folder_id = ? AND is_seen = 0 AND thread_id IS NOT NULL',
                [$inbox['id']],
            ) : 0;
            $bits = [];
            if ($unread > 0) {
                $bits[] = "{$unread} unread";
            }
            if (Store::isShared($a)) {
                $shared[] = (int) $a['id'];
                $old = (int) Db::value("SELECT COUNT(*) FROM mail_threads WHERE account_id = ? AND status = 'open' AND last_message_at < ?", [$a['id'], $openSince]);
                if ($old > 0) {
                    $bits[] = "{$old} open over a day";
                }
            }
            if ($bits) {
                $parts[] = $a['email'] . ': ' . implode(', ', $bits);
            }
        }
        if ($shared) {
            $marks = Db::marks(count($shared));
            $mine = Db::one(
                "SELECT COUNT(*) AS n, MIN(assigned_at) AS oldest FROM mail_threads WHERE assigned_to = ? AND status = 'open' AND account_id IN ({$marks})",
                array_merge([$userId], $shared),
            );
            if ((int) ($mine['n'] ?? 0) > 0) {
                $days = (int) floor((Clock::ageSeconds($mine['oldest']) ?? 0) / 86400);
                $parts[] = 'Assigned to you: ' . (int) $mine['n'] . ' open' . ($days >= 1 ? ' (oldest ' . $days . ' day' . ($days === 1 ? '' : 's') . ')' : '');
            }
            $waiting = (int) Db::value("SELECT COUNT(*) FROM mail_threads WHERE status = 'waiting' AND account_id IN ({$marks})", $shared);
            if ($waiting > 0) {
                $parts[] = "Waiting for replies: {$waiting}";
            }
        }

        return $parts ? implode('. ', $parts) . '.' : null;
    }
}
