<?php

namespace HOD\Mail;

use Throwable;

/**
 * The unread count on Mail in both shells (the nav registry's badge): unread Inbox conversations in every
 * mailbox the person can open, from the ERP's copy. Kept 30 seconds in the person's session so a page load costs
 * one query at most; after changing what the current person has unread, call MailNav::forget(). Never throws:
 * no count beats a broken page.
 */
final class MailNav
{
    private const TTL = 30;

    private const KEY = 'hod_mail_unread';

    public static function unread(?int $userId): int
    {
        if (!$userId || !mail_ready()) {
            return 0;
        }
        $cached = $_SESSION[self::KEY] ?? null;
        if (is_array($cached) && ($cached['u'] ?? 0) === $userId && (time() - (int) ($cached['t'] ?? 0)) < self::TTL) {
            return (int) $cached['n'];
        }
        try {
            $ids = MailAccess::accountIds($userId);
            $n = $ids ? (int) Db::value(
                "SELECT COUNT(DISTINCT m.thread_id) FROM mail_messages m
                   JOIN mail_folders f ON f.id = m.folder_id AND f.role = 'inbox'
                  WHERE m.is_seen = 0 AND m.account_id IN (" . Db::marks(count($ids)) . ')',
                $ids,
            ) : 0;
        } catch (Throwable $e) {
            error_log('Mail: unread count: ' . $e->getMessage());
            $n = 0;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::KEY] = ['u' => $userId, 't' => time(), 'n' => $n];
        }

        return $n;
    }

    public static function forget(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
