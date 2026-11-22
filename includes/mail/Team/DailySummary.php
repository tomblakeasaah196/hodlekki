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
 *
 * Owner, 22 Nov 2026: "notification of unread and waiting should come from inbox only… if no mail entered info we
 * don't need to receive a notification in the morning". So:
 *   - nothing at all unless new mail reached the Inbox of one of the person's mailboxes in the last day (since the
 *     last summary): a quiet morning stays quiet;
 *   - every count is of conversations with mail in the Inbox (a thread we started, still only in Sent, is not
 *     "open" or "waiting" for the summary).
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
                    MailNotices::send([(int) $uid], 'Mail today', $line, '/modules/mail/');
                    $bells++;
                }
                PersonalAlerts::send((int) $uid, 'summary', 'Your mail today', $line, '/modules/mail/');
            } catch (Throwable $e) {
                error_log('Mail: daily summary for user ' . $uid . ' failed: ' . $e->getMessage());
            }
        }

        return ['people' => count($people), 'bells' => $bells];
    }

    /** "Has mail in the Inbox": the condition every count shares (owner: Inbox only, never Sent). */
    private const IN_INBOX = 'EXISTS (SELECT 1 FROM mail_messages m WHERE m.thread_id = t.id AND m.folder_id IN (%s))';

    /**
     * The person's line, or null when there is nothing to say: no new mail reached any of their Inboxes in the last
     * day (owner: a quiet morning stays quiet), or nothing to count.
     */
    public static function line(int $userId): ?string
    {
        $parts = [];
        $openSince = Clock::sql(-3600 * (int) MailTeam::config('summary_open_hours', 24));
        $newSince = Clock::sql(-86400);   // since the last summary (it runs once a day)
        $fresh = false;
        $shared = [];
        $sharedInboxes = [];
        foreach (MailAccess::accounts($userId, 'read') as $a) {
            $inbox = Store::folderByRole((int) $a['id'], 'inbox');
            if (!$inbox) {
                continue;
            }
            $inboxId = (int) $inbox['id'];
            $fresh = $fresh || (int) Db::value(
                'SELECT COUNT(*) FROM mail_messages WHERE folder_id = ? AND is_outbound = 0 AND received_at >= ?',
                [$inboxId, $newSince],
            ) > 0;
            $unread = (int) Db::value(
                'SELECT COUNT(DISTINCT thread_id) FROM mail_messages WHERE folder_id = ? AND is_seen = 0 AND thread_id IS NOT NULL',
                [$inboxId],
            );
            $bits = [];
            if ($unread > 0) {
                $bits[] = "{$unread} unread";
            }
            if (Store::isShared($a)) {
                $shared[] = (int) $a['id'];
                $sharedInboxes[] = $inboxId;
                $old = (int) Db::value(
                    "SELECT COUNT(*) FROM mail_threads t WHERE t.account_id = ? AND t.status = 'open' AND t.last_message_at < ? AND "
                    . sprintf(self::IN_INBOX, '?'),
                    [$a['id'], $openSince, $inboxId],
                );
                if ($old > 0) {
                    $bits[] = "{$old} open over a day";
                }
            }
            if ($bits) {
                $parts[] = $a['email'] . ': ' . implode(', ', $bits);
            }
        }
        if (!$fresh) {
            return null;   // no new mail since yesterday: no summary, however much is still open
        }
        if ($shared) {
            $marks = Db::marks(count($shared));
            $inInbox = sprintf(self::IN_INBOX, Db::marks(count($sharedInboxes)));
            $mine = Db::one(
                "SELECT COUNT(*) AS n, MIN(t.assigned_at) AS oldest FROM mail_threads t
                  WHERE t.assigned_to = ? AND t.status = 'open' AND t.account_id IN ({$marks}) AND {$inInbox}",
                array_merge([$userId], $shared, $sharedInboxes),
            );
            if ((int) ($mine['n'] ?? 0) > 0) {
                $days = (int) floor((Clock::ageSeconds($mine['oldest']) ?? 0) / 86400);
                $parts[] = 'Assigned to you: ' . (int) $mine['n'] . ' open' . ($days >= 1 ? ' (oldest ' . $days . ' day' . ($days === 1 ? '' : 's') . ')' : '');
            }
            $waiting = (int) Db::value(
                "SELECT COUNT(*) FROM mail_threads t WHERE t.status = 'waiting' AND t.account_id IN ({$marks}) AND {$inInbox}",
                array_merge($shared, $sharedInboxes),
            );
            if ($waiting > 0) {
                $parts[] = "Waiting for replies: {$waiting}";
            }
        }

        return $parts ? implode('. ', $parts) . '.' : null;
    }
}
