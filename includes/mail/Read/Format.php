<?php

namespace HOD\Mail\Read;

use HOD\Mail\Clock;

/**
 * How the Mail page writes dates, people and sizes, without intl (the server's web PHP has none):
 * "10:42" today, "3 Nov" this year, "3 Nov 2025" before; full dates "3 Nov 2026, 10:42".
 */
final class Format
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** A list row's date; $time adds the time to dates before today */
    public static function when(?string $datetime, bool $time = false): string
    {
        $d = Clock::parse($datetime);
        if (!$d) {
            return '';
        }
        $now = Clock::now();
        if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
            return $d->format('H:i');
        }
        $day = (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1];
        if ($d->format('Y') === $now->format('Y')) {
            return $day . ($time ? ', ' . $d->format('H:i') : '');
        }

        return $day . ' ' . $d->format('Y');
    }

    /** "3 Nov 2026, 10:42" */
    public static function full(?string $datetime): string
    {
        $d = Clock::parse($datetime);

        return $d ? (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y, H:i') : '';
    }

    /** Names for a list row: the people other than the mailbox itself, at most $max */
    public static function people(array $participants, string $mailbox = '', int $max = 3): string
    {
        $mailbox = strtolower($mailbox);
        $names = [];
        foreach ($participants as $p) {
            $email = strtolower((string) ($p['email'] ?? ''));
            if ($email === '' || $email === $mailbox) {
                continue;
            }
            $names[] = trim((string) ($p['name'] ?? '')) ?: $email;
            if (count($names) >= $max) {
                break;
            }
        }

        return implode(', ', $names);
    }

    /** "Ada Obi <ada@example.org>, media@hodlekki.org" */
    public static function addresses(array $people): string
    {
        $out = [];
        foreach ($people as $p) {
            $email = (string) ($p['email'] ?? '');
            $name = trim((string) ($p['name'] ?? ''));
            if ($email === '' && $name === '') {
                continue;
            }
            $out[] = $name !== '' && $email !== '' && strcasecmp($name, $email) !== 0 ? "{$name} <{$email}>" : ($email ?: $name);
        }

        return implode(', ', $out);
    }

    /** "1 conversation", "3 conversations" */
    public static function count(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    /** "12 KB", "1.4 MB" */
    public static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
    }

    /** An icon from the shared sprite (docs/UI.md), hidden from screen readers: the control says what it does */
    public static function icon(string $name, string $class = 'ui-icon--sm'): string
    {
        return function_exists('ui_icon') ? ui_icon($name, $class) : '';
    }

    /** Two letters for an avatar, from a name or an address */
    public static function initials(string $name): string
    {
        $name = trim(preg_replace('/@.*$/', '', $name) ?? '');
        $name = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name) ?? '';
        $parts = array_values(array_filter(preg_split('/[\s._-]+/u', $name) ?: []));
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }

        return $out !== '' ? $out : '@';
    }
}
