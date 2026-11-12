<?php

namespace HOD\Mail\Read;

use HOD\Mail\Clock;
use HOD\Mail\Db;

/**
 * Senders whose remote pictures a person always loads ("Always show", migration 20261201090000_mail_read). Per
 * person, never shared: one person trusting a newsletter changes nothing for anyone else.
 *
 * The code reaches the server before its migration runs: until the table exists, nobody trusts anyone (pictures
 * stay hidden) and "Always show" answers false, so reading still works.
 */
final class ImageTrust
{
    /** @param list<?string> $senders @return list<string> those the person trusts, lower case */
    public static function trusted(int $userId, array $senders): array
    {
        $senders = array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), $senders))));
        if (!$senders || $userId <= 0) {
            return [];
        }
        try {
            return array_map('strval', Db::column(
                'SELECT sender FROM mail_image_trust WHERE user_id = ? AND sender IN (' . Db::marks(count($senders)) . ')',
                array_merge([$userId], $senders),
            ));
        } catch (\PDOException $e) {
            error_log('Mail: image trust not ready: ' . $e->getMessage());

            return [];
        }
    }

    public static function trust(int $userId, string $sender): bool
    {
        $sender = strtolower(trim($sender));
        if ($userId <= 0 || $sender === '' || strlen($sender) > 190 || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            Db::exec('INSERT IGNORE INTO mail_image_trust (user_id, sender, created_at) VALUES (?, ?, ?)', [$userId, $sender, Clock::sql()]);
        } catch (\PDOException $e) {
            error_log('Mail: image trust not ready: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
