<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;

/**
 * Mailbox addresses. Personal ones are firstname.lastname@ (owner, 11 Nov 2026). The local part is lower case
 * ASCII letters, digits, dots and hyphens, 3 to 40 characters, no dot at either end and no two together; names are
 * folded to ASCII here by a table, because the web PHP has no intl (no Normalizer, no Transliterator).
 */
final class Addresses
{
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ẹ' => 'e', 'ẹ́' => 'e', 'ẹ̀' => 'e', 'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
        'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i', 'ị' => 'i', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ṅ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'ọ' => 'o', 'œ' => 'oe',
        'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ṣ' => 's', 'ß' => 'ss', 'ť' => 't', 'ţ' => 't', 'ù' => 'u', 'ú' => 'u',
        'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ụ' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z',
        'ż' => 'z', 'ž' => 'z',
    ];

    public static function domain(): string
    {
        return strtolower((string) Config::get('domain'));
    }

    /** A name to ASCII letters, digits and hyphens */
    public static function fold(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = preg_replace('/[\x{0300}-\x{036F}]/u', '', $s) ?? $s;   // combining marks (Yoruba tones typed separately)
        $s = strtr($s, self::FOLD);
        $s = preg_replace('/[\s_]+/', '-', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9-]/', '', $s) ?? '';

        return trim(preg_replace('/-{2,}/', '-', $s) ?? $s, '-');
    }

    /** firstname.lastname from a person's names (the first word of each) */
    public static function suggest(string $firstName, string $lastName): string
    {
        $first = explode('-', self::fold(explode(' ', trim($firstName))[0] ?? ''))[0] ?? '';
        $last = self::fold(preg_replace('/\s+/', '-', trim($lastName)) ?? '');
        $local = trim($first . '.' . $last, '.');

        return substr($local, 0, 40);
    }

    /** @throws MailError on the local_part field when the address cannot be used */
    public static function validate(string $localPart, array $taken = []): string
    {
        $local = strtolower(trim($localPart));
        if (str_contains($local, '@')) {
            [$local, $domain] = explode('@', $local, 2);
            if ($domain !== self::domain()) {
                throw MailError::field('local_part', 'Addresses here end in @' . self::domain() . '.');
            }
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]{1,38})[a-z0-9]$/', $local) || str_contains($local, '..')) {
            throw MailError::field('local_part', 'Use 3 to 40 lower-case letters, digits, dots or hyphens, starting and ending with a letter or digit.');
        }
        if (in_array($local, (array) Config::get('reserved_local_parts', []), true)) {
            throw MailError::field('local_part', "{$local}@ is reserved. Choose another address.");
        }
        $email = $local . '@' . self::domain();
        if (in_array($email, array_map('strtolower', $taken), true)
            || Db::value('SELECT 1 FROM mail_accounts WHERE email = ?', [$email])) {
            throw MailError::field('local_part', "{$email} already exists. Add a middle initial or choose another address.");
        }

        return $local;
    }

    /** Addresses on the server (mailboxes and forwarders) as last read by the cron @return list<string> */
    public static function onServer(): array
    {
        $snap = (array) MailSettings::get('quota.server', []);

        return array_values(array_unique(array_merge((array) ($snap['addresses'] ?? []), (array) ($snap['forwarders'] ?? []))));
    }
}
