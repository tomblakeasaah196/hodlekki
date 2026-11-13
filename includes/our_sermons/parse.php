<?php
// /includes/our_sermons/parse.php
//
// Pure functions (no database, no network), pinned by tests/our_sermons/run.php.
//
//   our_sermons_parse_title()   "Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026"
//                               → title, preacher, date preached, service (and a series guess)
//   our_sermons_find_date()     the date written in a piece of text ("30th September 2026", "9TH APRIL, 2025", …)
//   our_sermons_parse_link()    a Spotify episode or YouTube video link → provider + id + canonical URL
//   our_sermons_duration()      "1:02:03", "62:03", "3723" → seconds
//   our_sermons_match_key()     a title folded for matching a Spotify title to a feed item
//   our_sermons_guess_sunday()  the Sunday on or before a day (a provisional date preached)

const OUR_SERMONS_MONTHS = [
    'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
    'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
    'september' => 9, 'sept' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
    'december' => 12, 'dec' => 12,
];

// Words a preacher's name starts with, and how they are written once tidied.
const OUR_SERMONS_HONORIFICS = [
    'senior pastor' => 'Senior Pastor', 'associate pastor' => 'Associate Pastor', 'assoc pastor' => 'Associate Pastor',
    'resident pastor' => 'Resident Pastor', 'guest minister' => 'Guest Minister', 'archbishop' => 'Archbishop',
    'pastor' => 'Pastor', 'pst' => 'Pastor', 'ps' => 'Pastor', 'reverend' => 'Rev.', 'rev' => 'Rev.',
    'bishop' => 'Bishop', 'apostle' => 'Apostle', 'dr' => 'Dr.', 'doctor' => 'Dr.', 'evangelist' => 'Evangelist',
    'evang' => 'Evangelist', 'minister' => 'Minister', 'min' => 'Minister', 'deaconess' => 'Deaconess',
    'deacon' => 'Deacon', 'elder' => 'Elder', 'prophetess' => 'Prophetess', 'prophet' => 'Prophet',
    'brother' => 'Brother', 'bro' => 'Brother', 'sister' => 'Sister', 'sis' => 'Sister', 'mrs' => 'Mrs', 'mr' => 'Mr',
    'ms' => 'Ms',
];

const OUR_SERMONS_SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or',
    'the', 'to', 'with'];

/**
 * Read an episode title the way Envision writes them: parts separated by "|"
 * (or, failing that, " - " when a part is clearly a name or a date), in any
 * order. A part that starts with a title (Pastor, Rev., …) is the preacher; a
 * part holding a date is the date preached; "Midweek Service", "Exousia",
 * "Sunday 2nd Service", "Thanksgiving Service" are the service; the first
 * other part is the sermon's title, a second one a series guess. Titles in
 * capitals are put in title case, preachers' names are tidied ("UZo-Peters"
 * → "Uzo-Peters"). Nothing is invented: a missing part is null, except the
 * service, which follows the day when the title names none (Sunday →
 * 'sunday', Wednesday → 'midweek').
 *
 * @return array{title:string, preacher:?string, date:?string, service:?string, series:?string}
 */
function our_sermons_parse_title(string $raw): array
{
    $raw = our_sermons_clean_title($raw);
    $out = ['title' => $raw, 'preacher' => null, 'date' => null, 'service' => null, 'series' => null];
    if ($raw === '') {
        return $out;
    }

    $parts = our_sermons_title_parts($raw);
    $rest = [];
    $services = [];
    foreach ($parts as $part) {
        $found = our_sermons_find_date($part);
        if ($found !== null) {
            $out['date'] ??= $found['date'];
            $part = trim((string) preg_replace('/^[\s,;:.\-–—]+|[\s,;:.\-–—]+$/u', '', $found['rest']));
            if ($part === '') {
                continue;
            }
        }
        $preacher = our_sermons_preacher_of($part);
        if ($preacher !== null) {
            $out['preacher'] ??= $preacher;
            continue;
        }
        $service = our_sermons_service_of($part);
        if ($service !== null) {
            $services[] = $service;
            continue;
        }
        $rest[] = $part;
    }

    if ($rest !== []) {
        $out['title'] = our_sermons_title_case($rest[0]);
        if (isset($rest[1]) && mb_strlen($rest[1]) <= 60 && mb_strtolower($rest[1]) !== mb_strtolower($rest[0])) {
            $out['series'] = our_sermons_title_case($rest[1]);
        }
    } elseif ($parts !== []) {
        $out['title'] = our_sermons_title_case($raw);
    }

    // The most specific service wins: a numbered Sunday service, then midweek, Sunday, special.
    foreach (['sunday_1', 'sunday_2', 'sunday_3', 'midweek', 'sunday', 'special'] as $key) {
        if (in_array($key, $services, true)) {
            $out['service'] = $key;
            break;
        }
    }
    if ($out['service'] === null && $out['date'] !== null) {
        $dow = (int) (new DateTimeImmutable($out['date']))->format('w');
        $out['service'] = [0 => 'sunday', 3 => 'midweek'][$dow] ?? null;
    }
    return $out;
}

/** Entities decoded, fancy spaces and bars made plain, one line. */
function our_sermons_clean_title(string $raw): string
{
    $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}", "\u{FF5C}", "\u{2502}", '||'], [' ', ' ', ' ', '|', '|', '|'], $s);
    $s = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** The parts of a title: "|" first; " - " / " – " only when that reveals a preacher or a date. */
function our_sermons_title_parts(string $raw): array
{
    $split = static fn (string $sep): array => array_values(array_filter(array_map('trim', explode($sep, $raw)), static fn ($p) => $p !== ''));
    if (str_contains($raw, '|')) {
        return $split('|');
    }
    foreach ([' – ', ' — ', ' - ', ' :: ', ' // '] as $sep) {
        if (!str_contains($raw, $sep)) {
            continue;
        }
        $parts = $split($sep);
        foreach ($parts as $i => $p) {
            if ($i > 0 && (our_sermons_preacher_of($p) !== null || our_sermons_find_date($p) !== null)) {
                return $parts;
            }
        }
    }
    return [$raw];
}

/**
 * The first date written in $text, and the text without it (and without a
 * weekday next to it). Day-first for numbers (8/11/2026 is 8 November), as
 * written in Nigeria.
 *
 * @return array{date:string, rest:string}|null
 */
function our_sermons_find_date(string $text): ?array
{
    $months = implode('|', array_map('preg_quote', array_keys(OUR_SERMONS_MONTHS)));
    $day = '(\d{1,2})(?:st|nd|rd|th)?';
    $weekday = '(?:(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(?:day|nesday|sday|urday)?\.?,?\s+)?';
    $patterns = [
        // November 8th, 2026 · Nov 8 2026 · NOVEMBER 8TH 2026
        ['/\b' . $weekday . '(' . $months . ')\.?\s+' . $day . '(?:\s*,\s*|\s+)(\d{4})\b/iu', 'mdy'],
        // 30th September 2026 · 9TH APRIL, 2025 · 8th of November, 2026
        ['/\b' . $weekday . $day . '\s+(?:of\s+)?(' . $months . ')\.?,?\s+(\d{4})\b/iu', 'dmy'],
        // 2026-11-08
        ['/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u', 'iso'],
        // 8/11/2026 · 8.11.2026 · 08-11-2026 · 8/11/26
        ['/\b' . $weekday . '(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/iu', 'num'],
    ];
    foreach ($patterns as [$re, $kind]) {
        if (!preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        [$y, $mo, $d] = match ($kind) {
            'mdy' => [(int) $m[3][0], OUR_SERMONS_MONTHS[strtolower($m[1][0])] ?? 0, (int) $m[2][0]],
            'dmy' => [(int) $m[3][0], OUR_SERMONS_MONTHS[strtolower($m[2][0])] ?? 0, (int) $m[1][0]],
            'iso' => [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0]],
            'num' => [strlen($m[3][0]) === 2 ? 2000 + (int) $m[3][0] : (int) $m[3][0], (int) $m[2][0], (int) $m[1][0]],
        };
        if ($y < 1990 || $y > 2100 || !checkdate($mo, $d, $y)) {
            continue;
        }
        $rest = substr($text, 0, $m[0][1]) . ' ' . substr($text, $m[0][1] + strlen($m[0][0]));
        return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'rest' => trim((string) preg_replace('/\s+/u', ' ', $rest))];
    }
    return null;
}

/** "PASTOR EBELE UZO-PETERS" → "Pastor Ebele Uzo-Peters"; null when the part is not a preacher. */
function our_sermons_preacher_of(string $part): ?string
{
    $part = trim((string) preg_replace('/^(?:ministering|minister(?:ing)?\s*:|preacher|speaker|by|with)\s*:?\s+/iu', '', trim($part)));
    $keys = array_keys(OUR_SERMONS_HONORIFICS);
    usort($keys, static fn ($a, $b) => strlen($b) <=> strlen($a));
    $alt = implode('|', array_map(static fn ($k) => str_replace(' ', '\.?\s+', preg_quote($k, '/')), $keys));
    if (!preg_match('/^(' . $alt . ')\.?(\s*\((?:mrs|dr|mr)\.?\))?\s+([\p{L}][\p{L}\'’.\- ]{1,80})$/iu', $part, $m)) {
        return null;
    }
    $key = strtolower((string) preg_replace('/[.\s]+/', ' ', $m[1]));
    $honorific = OUR_SERMONS_HONORIFICS[trim($key)] ?? ucfirst(trim($key));
    $extra = '';
    if (!empty($m[2])) {
        $extra = ' (' . ucfirst(strtolower(trim($m[2], " ().\t"))) . ')';
    }
    $name = our_sermons_name_case(trim($m[3]));
    // A name is a few words, not a sentence: "Pastor's Corner", "Pastor and the flock" are titles.
    $words = preg_split('/\s+/u', $name) ?: [];
    if (count($words) > 5 || preg_match('/^(and|of|the|in|is|on)\b/i', $name)) {
        return null;
    }
    return mb_substr($honorific . $extra . ' ' . $name, 0, 120);
}

/** "UZo-Peters" → "Uzo-Peters", "EBELE" → "Ebele"; "McDonald" and "O'Neil" stay. */
function our_sermons_name_case(string $name): string
{
    $words = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    foreach ($words as $i => $w) {
        if (trim($w) === '') {
            continue;
        }
        $pieces = preg_split('/([\-\'’])/u', $w, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($pieces as $j => $p) {
            if (preg_match('/^\p{Lu}{2,}\p{Ll}*$/u', $p) || preg_match('/^\p{Ll}/u', $p)) {
                $pieces[$j] = mb_strtoupper(mb_substr($p, 0, 1)) . mb_strtolower(mb_substr($p, 1));
            }
        }
        $words[$i] = implode('', $pieces);
    }
    return implode('', $words);
}

/** A service key for a part of a title, or null when the part is not a service. */
function our_sermons_service_of(string $part): ?string
{
    $p = mb_strtolower(trim($part));
    if (preg_match('/\bexousia\b|\bmid-?\s?week\b/u', $p)) {
        return 'midweek';
    }
    if (preg_match('/\b(1st|first)\s+service\b/u', $p)) {
        return 'sunday_1';
    }
    if (preg_match('/\b(2nd|second)\s+service\b/u', $p)) {
        return 'sunday_2';
    }
    if (preg_match('/\b(3rd|third)\s+service\b/u', $p)) {
        return 'sunday_3';
    }
    if (preg_match('/^sunday(\s+(morning\s+)?service)?$/u', $p) || preg_match('/^sunday\s+service\b/u', $p)) {
        return 'sunday';
    }
    $words = preg_split('/\s+/u', $p) ?: [];
    if (count($words) <= 5 && preg_match('/\bservice\b/u', $p)) {
        return 'special';
    }
    return null;
}

/** ALL-CAPS titles become Title Case; anything already mixed is left as typed. */
function our_sermons_title_case(string $s): string
{
    $s = trim($s);
    if ($s === '' || preg_match('/\p{Ll}/u', $s) || !preg_match('/\p{Lu}.*\p{Lu}/u', $s)) {
        return $s;
    }
    $words = preg_split('/(\s+)/u', mb_strtolower($s), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $first = true;
    foreach ($words as $i => $w) {
        if (trim($w) === '') {
            continue;
        }
        if ($first || !in_array($w, OUR_SERMONS_SMALL_WORDS, true)) {
            $words[$i] = (string) preg_replace_callback('/(^|[\-\'’(])(\p{Ll})/u',
                static fn ($m) => $m[1] . mb_strtoupper($m[2]), $w);
        }
        $first = false;
    }
    return implode('', $words);
}

/**
 * A Spotify episode or YouTube video link, made canonical.
 * @return array{provider:'spotify'|'youtube', id:string, url:string}|null
 */
function our_sermons_parse_link(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) {
        return null;
    }
    if (preg_match('/^spotify:episode:([A-Za-z0-9]{22})$/', $url, $m)) {
        return ['provider' => 'spotify', 'id' => $m[1], 'url' => 'https://open.spotify.com/episode/' . $m[1]];
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return null;
    }
    $host = strtolower((string) $p['host']);
    $path = (string) ($p['path'] ?? '');
    parse_str((string) ($p['query'] ?? ''), $q);
    if ($host === 'open.spotify.com'
        && preg_match('#^/(?:intl-[a-z]{2}(?:-[A-Za-z]{2})?/)?(?:embed/)?episode/([A-Za-z0-9]{22})/?$#', $path, $m)) {
        return ['provider' => 'spotify', 'id' => $m[1], 'url' => 'https://open.spotify.com/episode/' . $m[1]];
    }
    $yt = null;
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $yt = explode('/', ltrim($path, '/'))[0] ?? '';
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'www.youtube-nocookie.com',
                               'youtube-nocookie.com'], true)) {
        if ($path === '/watch') {
            $yt = is_string($q['v'] ?? null) ? $q['v'] : '';
        } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $yt = $m[2];
        }
    }
    if ($yt !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
        return ['provider' => 'youtube', 'id' => $yt, 'url' => 'https://www.youtube.com/watch?v=' . $yt];
    }
    return null;
}

/** itunes:duration ("1:02:03", "62:03", "3723", "3723.5") → seconds, or null. */
function our_sermons_duration($value): ?int
{
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
        $s = (int) round((float) $v);
    } elseif (preg_match('/^(?:(\d{1,3}):)?(\d{1,3}):(\d{1,2})(?:\.\d+)?$/', $v, $m)) {
        $s = (int) ($m[1] ?: 0) * 3600 + (int) $m[2] * 60 + (int) $m[3];
    } else {
        return null;
    }
    return $s > 0 && $s < 86400 ? $s : null;
}

/**
 * A title folded for matching (Spotify's title against the feed's): lower
 * case, accents and punctuation gone, ordinals kept as written, one space.
 */
function our_sermons_match_key(string $title): string
{
    $s = mb_strtolower(our_sermons_clean_title($title), 'UTF-8');
    $s = strtr($s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '&' => ' and ']);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') {
            $s = $t;
        }
    }
    $s = (string) preg_replace("/'/", '', $s);
    $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

/** The Sunday on or before $ymd: where a sermon published without a date most likely was preached. */
function our_sermons_guess_sunday(string $ymd): string
{
    $d = new DateTimeImmutable(substr($ymd, 0, 10), new DateTimeZone('Africa/Lagos'));
    $dow = (int) $d->format('w');
    return $d->modify('-' . $dow . ' days')->format('Y-m-d');
}
