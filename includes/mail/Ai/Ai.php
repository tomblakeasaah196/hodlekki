<?php

namespace HOD\Mail\Ai;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;
use HOD\Mail\People;
use HOD\Mail\Store;

/**
 * Who may use AI where (docs/MAIL.md §7e; owner and lead decision 5). AI only ever summarises or drafts, on press;
 * it never sends. It is available on a mailbox only when ALL of these hold:
 *
 *   1. the owner confirmed the Gemini project is on a paid plan: a mail admin records it on the Mailboxes screen
 *      (mail_settings `ai.paid_plan_confirmed`, who and when); until then AI is unavailable everywhere;
 *   2. GEMINI_API_KEY is set;
 *   3. a mail admin switched it on for that mailbox (mail_accounts.ai_enabled, off by default); never offered, and
 *      refused, on personal mailboxes and on mailboxes marked pastoral;
 *   4. the person may read the mailbox and holds `mail.ai` in the permissions matrix (mail_can('ai')).
 *
 * This sends mail content to Google, which the house rule forbids for other modules; it is a written exception,
 * limited as above. Phone numbers and email addresses are replaced by placeholders first (Privacy).
 */
final class Ai
{
    public const PLAN_KEY = 'ai.paid_plan_confirmed';

    private static ?array $config = null;

    public static function config(string $key, mixed $default = null): mixed
    {
        self::$config ??= require __DIR__ . '/ai_config.php';
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public static function key(): string
    {
        return function_exists('site_env') ? site_env('GEMINI_API_KEY') : (string) ($_ENV['GEMINI_API_KEY'] ?? '');
    }

    /** @return array{by: int, at: string}|null */
    public static function plan(): ?array
    {
        $p = MailSettings::get(self::PLAN_KEY);

        return is_array($p) && !empty($p['at']) ? ['by' => (int) ($p['by'] ?? 0), 'at' => (string) $p['at']] : null;
    }

    /** A mail admin records (or withdraws) the owner's confirmation of the paid plan */
    public static function setPlan(int $byUserId, bool $confirmed): void
    {
        if (!MailAccess::allows($byUserId, 'admin')) {
            throw MailError::denied('Only mail admins record this.');
        }
        $confirmed ? MailSettings::set(self::PLAN_KEY, ['by' => $byUserId, 'at' => Clock::sql()], $byUserId) : MailSettings::forget(self::PLAN_KEY);
    }

    /** Could AI ever be switched on for this mailbox? (not personal, not pastoral) */
    public static function allowedFor(array $account): bool
    {
        return ($account['kind'] ?? '') !== 'personal' && empty($account['is_pastoral']);
    }

    /** A mail admin switches AI on or off for a mailbox */
    public static function setMailbox(int $byUserId, array $account, bool $on): void
    {
        if (!MailAccess::allows($byUserId, 'admin')) {
            throw MailError::denied('Only mail admins switch AI on.');
        }
        if ($on && !self::allowedFor($account)) {
            throw MailError::invalid('AI is never used on personal or pastoral mailboxes.');
        }
        if ($on && !self::plan()) {
            throw MailError::invalid('Record the paid Gemini plan first.');
        }
        Db::update('mail_accounts', ['ai_enabled' => $on ? 1 : 0], (int) $account['id']);
    }

    /** AI is on for this mailbox (gates 1 to 3) */
    public static function on(array $account): bool
    {
        return self::plan() !== null && self::key() !== '' && !empty($account['ai_enabled']) && self::allowedFor($account);
    }

    /** This person may use AI on this mailbox (all four gates) */
    public static function available(int $userId, ?array $account): bool
    {
        return $account !== null && self::on($account) && MailAccess::can($userId, $account, 'read')
            && MailAccess::allows($userId, 'ai');
    }

    /** Throws the reason AI cannot be used here */
    public static function require(int $userId, ?array $account, ?string $field = null): void
    {
        if (!self::available($userId, $account)) {
            throw new MailError('AI is not available on this mailbox.', $field, 403);
        }
    }

    /** "Ada" for "Ada Obi"; '' when unknown */
    public static function firstName(?string $name): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{M}\s\'-]/u', ' ', (string) $name));
        $first = trim(explode(' ', $name)[0] ?? '');

        return mb_strlen($first) >= 2 ? mb_convert_case($first, MB_CASE_TITLE) : '';
    }

    /** The person's own first name, for the prompt */
    public static function writer(int $userId): string
    {
        return People::firstName($userId);
    }

    /** The mailbox's team name for "God bless you," (its display name, e.g. "HOD Lekki Embrace Team") */
    public static function team(array $account): string
    {
        $name = trim((string) ($account['display_name'] ?? ''));

        return $name !== '' ? $name : (string) Store::account((int) $account['id'])['email'];
    }
}
