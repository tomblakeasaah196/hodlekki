<?php

namespace HOD\Mail\Team;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use Throwable;

/**
 * Key alerts to a person's confirmed personal email (e.g. a pastor's Gmail), for anyone who ticked them on
 * My mail settings (docs/MAIL.md §7e). The address is the one the person confirmed for the church
 * (member_confirmed_email, the Campaigns plan); the mailbox never confirms addresses itself.
 *
 * Rules: sender and subject only, never the body or attachments (lead); sent in the email foundation's `alert` lane
 * (email_send_doc, so it shares the domain's hourly budget); never to a hodlekki.org address (it would loop); at most
 * one per conversation an hour and 20 a day per person (mail_alert_log). Best effort: a failure is logged, never
 * thrown.
 */
final class PersonalAlerts
{
    /** @return bool queued */
    public static function send(int $userId, string $kind, string $title, string $line, string $link, ?int $threadId = null): bool
    {
        try {
            if (!isset(Prefs::PERSONAL[$kind]) || empty(Prefs::general($userId)['personal'][$kind])) {
                return false;
            }
            $pdo = Db::pdo();
            if (!self::campaigns()) {
                return false;
            }
            $to = (string) (member_confirmed_email($pdo, $userId) ?? '');
            if ($to === '' || str_ends_with(strtolower($to), '@' . strtolower((string) Config::get('domain', 'hodlekki.org')))) {
                return false;
            }
            if (!self::allowed($userId, $threadId)) {
                return false;
            }
            $person = email_person($pdo, $userId);
            if ($person === null) {
                return false;
            }
            $queued = email_send_doc($pdo, self::doc($title, $line, self::absolute($link)), $person, [
                'lane' => 'alert', 'purpose' => 'account', 'flow' => 'mail_alert', 'to_email' => $to, 'now' => true,
            ]);
            if (empty($queued['ok'])) {
                error_log('Mail: key alert not queued for user ' . $userId . ': ' . ($queued['code'] ?? 'unknown'));

                return false;
            }
            Db::insert('mail_alert_log', ['user_id' => $userId, 'kind' => $kind, 'thread_id' => $threadId, 'sent_at' => Clock::sql()]);

            return true;
        } catch (Throwable $e) {
            error_log('Mail: key alert failed for user ' . $userId . ': ' . $e->getMessage());

            return false;
        }
    }

    /** The person's confirmed personal email, or null (My mail settings shows it) */
    public static function address(int $userId): ?string
    {
        try {
            return self::campaigns() ? member_confirmed_email(Db::pdo(), $userId) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The limits: one per conversation per hour, 20 a day */
    public static function allowed(int $userId, ?int $threadId): bool
    {
        $day = (int) Db::value('SELECT COUNT(*) FROM mail_alert_log WHERE user_id = ? AND sent_at > ?', [$userId, Clock::sql(-86400)]);
        if ($day >= (int) MailTeam::config('personal_per_day', 20)) {
            return false;
        }
        if ($threadId) {
            $minutes = (int) MailTeam::config('personal_per_thread_minutes', 60);

            return !Db::value('SELECT 1 FROM mail_alert_log WHERE user_id = ? AND thread_id = ? AND sent_at > ? LIMIT 1', [$userId, $threadId, Clock::sql(-60 * $minutes)]);
        }

        return true;
    }

    /** The email: a heading, the one line (sender and subject, or counts), a button into the app, how to stop them */
    public static function doc(string $title, string $line, string $link): array
    {
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => mb_substr($title, 0, 120)],
            email_doc_paragraphs('Hi {{first_name}},', mb_substr($line, 0, 400)),
        ];
        if (str_starts_with($link, 'https://')) {
            $blocks[] = ['type' => 'button', 'label' => 'Open in the app', 'href' => $link];
        }
        $settings = self::absolute('/modules/mail/settings.php');
        $blocks[] = email_doc_paragraphs('You chose these alerts. Change them on My mail settings: ' . $settings);
        $blocks[] = ['type' => 'footer'];

        return ['v' => 1, 'subject' => mb_substr($title, 0, 150), 'preheader' => mb_substr($line, 0, 140), 'blocks' => $blocks];
    }

    private static function absolute(string $path): string
    {
        return function_exists('site_url') ? site_url($path) : $path;
    }

    private static function campaigns(): bool
    {
        if (!function_exists('member_confirmed_email')) {
            $file = dirname(__DIR__, 2) . '/campaigns/bootstrap.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        return function_exists('member_confirmed_email') && function_exists('email_send_doc') && function_exists('email_person');
    }
}
