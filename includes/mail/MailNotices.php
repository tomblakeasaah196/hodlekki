<?php

namespace HOD\Mail;

use Throwable;

/**
 * Bell notifications about mailboxes themselves (PR 1): a password that needs a reset, storage nearly full, a
 * mailbox issued to someone, an admin job that failed, an export request waiting or ready. New-mail alerts are
 * PR 4's, and go to push directly, not to the bell.
 *
 * Rows go into system_notifications (user_id, title, message, link_url). Every such row is already a push
 * notification (push_fanout); never add a push call next to one. Links are relative and same-site.
 */
final class MailNotices
{
    public static function needsReset(array $account): void
    {
        self::attention(
            'Mail: a mailbox needs a new password',
            "{$account['email']} stopped updating: the mail server refused its stored password. Open Mailboxes and choose Reset password.",
            self::mailboxesUrl((int) $account['id']),
        );
    }

    public static function storage(array $account, int $percent): void
    {
        self::attention(
            'Mail: a mailbox is nearly full',
            "{$account['email']} is {$percent}% full. Ask its users to empty Trash and old attachments, or raise its limit on the Mailboxes screen.",
            self::mailboxesUrl((int) $account['id']),
        );
    }

    public static function issued(array $account, int $personId): void
    {
        self::send(
            [$personId],
            'Your church email is ready',
            "{$account['email']} is yours. Open Mail in the app to read and send from it.",
            self::inboxUrl((int) $account['id']),
        );
    }

    public static function jobFailed(array $job, string $what): void
    {
        if (empty($job['requested_by'])) {
            return;
        }
        self::send(
            [(int) $job['requested_by']],
            'Mail: an action could not be done',
            $what . ($job['email'] ? " ({$job['email']})" : '') . ': ' . ($job['error'] ?? 'the server refused it') . ' Try again from the Mailboxes screen.',
            self::mailboxesUrl($job['account_id'] ? (int) $job['account_id'] : null),
        );
    }

    /** @param list<int> $userIds */
    public static function exportWaiting(array $userIds, string $email, string $requester): void
    {
        self::send(
            $userIds,
            'Mail: an export request needs your approval',
            "{$requester} asked to export the mail of {$email}, a mailbox whose holder has left. Open Mailboxes, Export requests, to approve or refuse it.",
            self::mailboxesUrl() . '#tab=exports',
        );
    }

    public static function exportReady(int $userId, string $email): void
    {
        self::send(
            [$userId],
            'Mail: your export is ready',
            "Both approvals are in. You can download the mail of {$email} from Mailboxes, Export requests, for the next 7 days.",
            self::mailboxesUrl() . '#tab=exports',
        );
    }

    /** @param list<int> $userIds */
    /** A bell to every mail admin, and the key alert to those who ticked "a mailbox I manage needs attention" */
    private static function attention(string $title, string $message, string $link): void
    {
        $admins = MailAccess::mailAdmins();
        self::send($admins, $title, $message, $link);
        foreach ($admins as $id) {
            \HOD\Mail\Team\PersonalAlerts::send((int) $id, 'attention', $title, $message, $link);
        }
    }

    public static function send(array $userIds, string $title, string $message, ?string $link): void
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', $userIds)))) as $id) {
            try {
                Db::insert('system_notifications', [
                    'user_id' => $id,
                    'title' => mb_substr($title, 0, 190),
                    'message' => $message,
                    'link_url' => $link !== null ? mb_substr($link, 0, 255) : null,
                ]);
            } catch (Throwable $e) {
                error_log('Mail: notification not saved: ' . $e->getMessage());
            }
        }
    }

    /** /modules/mail/index.php?box=…&thread=… */
    public static function inboxUrl(int $accountId, ?int $threadId = null, ?string $folder = null): string
    {
        $q = ['box' => $accountId];
        if ($folder !== null) {
            $q['folder'] = $folder;
        }
        if ($threadId !== null) {
            $q['thread'] = $threadId;
        }

        return '/modules/mail/?' . http_build_query($q);
    }

    public static function mailboxesUrl(?int $accountId = null): string
    {
        return '/modules/mail/mailboxes' . ($accountId ? '?box=' . $accountId : '');
    }
}
