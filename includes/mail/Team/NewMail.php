<?php

namespace HOD\Mail\Team;

use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailNotices;
use HOD\Mail\Store;
use Throwable;

/**
 * mail.received (docs/MAIL.md §7e): what new mail sets off.
 *
 *   push       each reader of the mailbox, minus whoever sent it from the ERP or from their own address, filtered
 *              by their choice for that mailbox (all / assigned to me / off); push_enqueue() directly (source
 *              'mail'), never a bell: a bell per email would bury every other alert. Quiet hours honoured by
 *              push_enqueue. One tag per conversation; more than 3 for one person in one run become one push.
 *   status     a shared conversation goes back to Open
 *   AI         a stored summary is cleared (it no longer covers the conversation)
 *   key alert  the holder of a personal mailbox, if they ticked it: sender and subject to their personal email
 */
final class NewMail
{
    /** @return int pushes queued */
    public static function received(int $accountId, array $messageIds): int
    {
        $account = $accountId > 0 ? Store::account($accountId) : null;
        $ids = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$account || !$ids) {
            return 0;
        }
        $messages = array_values(array_filter(Store::messages($ids), fn ($m) => $m['account_id'] === $account['id'] && !$m['is_outbound']));
        if (!$messages) {
            return 0;
        }
        $shared = Store::isShared($account);
        $threads = array_values(array_unique(array_filter(array_map(fn ($m) => (int) $m['thread_id'], $messages))));
        if ($threads) {
            Db::exec('UPDATE mail_threads SET ai_summary = NULL, ai_summary_at = NULL WHERE id IN (' . Db::marks(count($threads)) . ')', $threads);
        }

        $readers = array_map('intval', array_keys(MailAccess::readers($account)));
        $writers = self::writers(array_column($messages, 'message_id_hash'));
        $own = self::ownAddresses($readers);
        $byPerson = [];
        foreach ($messages as $m) {
            try {
                if ($shared && $m['thread_id']) {
                    MailTeam::onReceived((int) $m['thread_id'], (int) $m['id']);
                }
                $skip = array_merge(
                    $m['sent_by'] ? [(int) $m['sent_by']] : [],
                    $writers[$m['message_id_hash'] ?? ''] ?? [],
                    $own[strtolower(trim((string) $m['from_email']))] ?? [],
                );
                $thread = $m['thread_id'] ? Store::thread((int) $m['thread_id']) : null;
                foreach (array_diff($readers, $skip) as $uid) {
                    $choice = Prefs::push($uid, $account['id']);
                    if ($choice === 'off' || ($choice === 'mine' && (int) ($thread['assigned_to'] ?? 0) !== $uid)) {
                        continue;
                    }
                    $byPerson[$uid][] = $m;
                }
                if (!$shared && $account['owner_user_id'] && !in_array((int) $account['owner_user_id'], $skip, true)) {
                    PersonalAlerts::send((int) $account['owner_user_id'], 'personal_mail', 'New email in ' . $account['email'],
                        self::line($m), MailNotices::inboxUrl($account['id'], $m['thread_id']), $m['thread_id'] ? (int) $m['thread_id'] : null);
                }
            } catch (Throwable $e) {
                error_log("Mail: new-mail alert for message {$m['id']} failed: " . $e->getMessage());
            }
        }

        return self::push($account, $byPerson);
    }

    /** "From Ada Obi · Thank you" (sender and subject only) */
    public static function line(array $m): string
    {
        $from = trim((string) ($m['from_name'] ?: $m['from_email'])) ?: 'unknown sender';
        $subject = trim((string) preg_replace('/\s+/u', ' ', (string) $m['subject'])) ?: '(no subject)';

        return 'From ' . mb_strimwidth($from, 0, 80, '…') . ' · ' . mb_strimwidth($subject, 0, 120, '…');
    }

    /** @param array<int, list<array>> $byPerson */
    private static function push(array $account, array $byPerson): int
    {
        if (!$byPerson || !self::pushReady()) {
            return 0;
        }
        $pdo = Db::pdo();
        $group = (int) MailTeam::config('push_group', 3);
        $queued = 0;
        foreach ($byPerson as $uid => $list) {
            try {
                if (count($list) > $group) {
                    $ok = push_enqueue($pdo, (int) $uid, [
                        'title' => count($list) . ' new emails',
                        'body' => 'In ' . $account['email'],
                        'url' => MailNotices::inboxUrl($account['id']),
                        'tag' => 'mail-' . $account['id'],
                    ], null, 'mail');
                    $queued += $ok ? 1 : 0;
                    continue;
                }
                foreach ($list as $m) {
                    $from = trim((string) ($m['from_name'] ?: $m['from_email'])) ?: 'New email';
                    $ok = push_enqueue($pdo, (int) $uid, [
                        'title' => mb_strimwidth($from, 0, 80, '…'),
                        'body' => mb_strimwidth(trim((string) $m['subject']) ?: '(no subject)', 0, 140, '…') . (Store::isShared($account) ? ' · ' . $account['email'] : ''),
                        'url' => MailNotices::inboxUrl($account['id'], $m['thread_id']),
                        'tag' => 'mail-' . $account['id'] . '-' . (int) $m['thread_id'],
                    ], null, 'mail');
                    $queued += $ok ? 1 : 0;
                }
            } catch (Throwable $e) {
                error_log('Mail: push for user ' . $uid . ' failed: ' . $e->getMessage());
            }
        }

        return $queued;
    }

    private static function pushReady(): bool
    {
        if (!function_exists('push_enqueue')) {
            $file = dirname(__DIR__, 2) . '/push_helpers.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        return function_exists('push_enqueue');
    }

    /** message_id_hash => people who wrote it in the ERP */
    private static function writers(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if (!$hashes) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT message_id_hash, user_id FROM mail_outbox WHERE message_id_hash IN (' . Db::marks(count($hashes)) . ')', $hashes) as $r) {
            $out[$r['message_id_hash']][] = (int) $r['user_id'];
        }

        return $out;
    }

    /** address => readers it belongs to (their sign-in address, their personal mailbox) */
    private static function ownAddresses(array $readers): array
    {
        if (!$readers) {
            return [];
        }
        $marks = Db::marks(count($readers));
        $rows = array_merge(
            Db::all("SELECT id AS user_id, email FROM users WHERE id IN ({$marks})", $readers),
            Db::all("SELECT owner_user_id AS user_id, email FROM mail_accounts WHERE kind = 'personal' AND owner_user_id IN ({$marks})", $readers),
        );
        $out = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string) $r['email']));
            if ($email !== '') {
                $out[$email][] = (int) $r['user_id'];
            }
        }

        return $out;
    }
}
