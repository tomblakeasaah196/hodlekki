<?php
// /includes/special_events/bible.php
//
// KJV lookup with a permanent cache (guide §15.9).
//
// This is NOT AI. The model may suggest a reference (§15.7); the words of
// Scripture are always fetched from a Bible service and stored verbatim, so
// nothing a model invented can ever reach a welcome card.
//
// Used at authoring time only — in the Studio, never during the live event.
// If the service is unreachable the Studio falls back to pasting the text by
// hand, which is marked `manual` and needs a second crew approval.

/** Books of the Bible with the abbreviations people actually type. */
const SE_BIBLE_BOOKS = [
    'genesis' => ['gen', 'ge', 'gn'],
    'exodus' => ['exo', 'ex', 'exod'],
    'leviticus' => ['lev', 'le', 'lv'],
    'numbers' => ['num', 'nu', 'nm', 'nb'],
    'deuteronomy' => ['deut', 'dt', 'de'],
    'joshua' => ['josh', 'jos', 'jsh'],
    'judges' => ['judg', 'jdg', 'jg'],
    'ruth' => ['rth', 'ru'],
    '1 samuel' => ['1 sam', '1sam', '1 sa', '1sa', 'i samuel'],
    '2 samuel' => ['2 sam', '2sam', '2 sa', '2sa', 'ii samuel'],
    '1 kings' => ['1 kgs', '1kgs', '1 ki', '1ki', 'i kings'],
    '2 kings' => ['2 kgs', '2kgs', '2 ki', '2ki', 'ii kings'],
    '1 chronicles' => ['1 chr', '1chr', '1 ch', '1ch'],
    '2 chronicles' => ['2 chr', '2chr', '2 ch', '2ch'],
    'ezra' => ['ezr'],
    'nehemiah' => ['neh', 'ne'],
    'esther' => ['est', 'es'],
    'job' => ['jb'],
    'psalms' => ['psalm', 'ps', 'psa', 'pss'],
    'proverbs' => ['prov', 'pro', 'pr', 'prv'],
    'ecclesiastes' => ['eccl', 'ecc', 'ec', 'qoh'],
    'song of solomon' => ['song', 'sos', 'canticles', 'song of songs'],
    'isaiah' => ['isa', 'is'],
    'jeremiah' => ['jer', 'je'],
    'lamentations' => ['lam', 'la'],
    'ezekiel' => ['ezek', 'eze', 'ezk'],
    'daniel' => ['dan', 'da', 'dn'],
    'hosea' => ['hos', 'ho'],
    'joel' => ['jl'],
    'amos' => ['am'],
    'obadiah' => ['obad', 'ob'],
    'jonah' => ['jon', 'jnh'],
    'micah' => ['mic', 'mc'],
    'nahum' => ['nah', 'na'],
    'habakkuk' => ['hab', 'hb'],
    'zephaniah' => ['zeph', 'zep', 'zp'],
    'haggai' => ['hag', 'hg'],
    'zechariah' => ['zech', 'zec', 'zc'],
    'malachi' => ['mal', 'ml'],
    'matthew' => ['matt', 'mat', 'mt'],
    'mark' => ['mrk', 'mk', 'mr'],
    'luke' => ['luk', 'lk'],
    'john' => ['joh', 'jhn', 'jn'],
    'acts' => ['act', 'ac'],
    'romans' => ['rom', 'ro', 'rm'],
    '1 corinthians' => ['1 cor', '1cor', '1 co', '1co'],
    '2 corinthians' => ['2 cor', '2cor', '2 co', '2co'],
    'galatians' => ['gal', 'ga'],
    'ephesians' => ['eph', 'ep'],
    'philippians' => ['phil', 'php', 'pp'],
    'colossians' => ['col', 'co'],
    '1 thessalonians' => ['1 thess', '1thess', '1 th', '1th'],
    '2 thessalonians' => ['2 thess', '2thess', '2 th', '2th'],
    '1 timothy' => ['1 tim', '1tim', '1 ti', '1ti'],
    '2 timothy' => ['2 tim', '2tim', '2 ti', '2ti'],
    'titus' => ['tit', 'ti'],
    'philemon' => ['philem', 'phm', 'pm'],
    'hebrews' => ['heb', 'hb'],
    'james' => ['jas', 'jm'],
    '1 peter' => ['1 pet', '1pet', '1 pe', '1pe'],
    '2 peter' => ['2 pet', '2pet', '2 pe', '2pe'],
    '1 john' => ['1 jn', '1jn', '1 jo', '1jo'],
    '2 john' => ['2 jn', '2jn', '2 jo', '2jo'],
    '3 john' => ['3 jn', '3jn', '3 jo', '3jo'],
    'jude' => ['jud', 'jd'],
    'revelation' => ['rev', 're', 'apocalypse', 'revelations'],
];

/**
 * Canonicalise a reference typed by a human.
 *
 * Returns `['book' => 'john', 'ref_norm' => 'john 3:16-17', 'display' =>
 * 'John 3:16-17']`, or null when the book is not one of the 66.
 */
function se_bible_ref_normalize(string $raw): ?array
{
    $text = trim(mb_strtolower($raw, 'UTF-8'));
    $text = str_replace(['–', '—', '.'], ['-', '-', ''], $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    // "1st John", "ii Kings" → "1 john", "2 kings".
    $text = preg_replace('/\b(1|2|3)(st|nd|rd|th)\b/', '$1', $text) ?? $text;
    $text = preg_replace_callback('/^(i{1,3})\s+/', static fn(array $m): string => strlen($m[1]) . ' ', $text) ?? $text;

    if (!preg_match('/^(.+?)\s*(\d+)(?::\s*(\d+)(?:\s*-\s*(\d+))?)?$/', $text, $m)) {
        return null;
    }

    $bookRaw = trim($m[1]);
    $book    = null;

    foreach (SE_BIBLE_BOOKS as $canonical => $aliases) {
        if ($bookRaw === $canonical || in_array($bookRaw, $aliases, true)) {
            $book = $canonical;
            break;
        }
    }
    if ($book === null) {
        // "psalm 100" is the common singular; try a prefix match before
        // giving up, so "revelatio" or "corinthian" still resolve.
        foreach (SE_BIBLE_BOOKS as $canonical => $aliases) {
            if ($bookRaw !== '' && str_starts_with($canonical, $bookRaw)) {
                $book = $canonical;
                break;
            }
        }
    }
    if ($book === null) {
        return null;
    }

    $chapter = (int) $m[2];
    $verse   = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
    $through = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;

    $norm = $book . ' ' . $chapter;
    if ($verse !== null) {
        $norm .= ':' . $verse . ($through !== null && $through > $verse ? '-' . $through : '');
    }

    $display = se_bible_title_case($book) . ' ' . $chapter;
    if ($verse !== null) {
        $display .= ':' . $verse . ($through !== null && $through > $verse ? '-' . $through : '');
    }

    return ['book' => $book, 'ref_norm' => mb_substr($norm, 0, 60, 'UTF-8'), 'display' => mb_substr($display, 0, 60, 'UTF-8')];
}

/** "1 corinthians" → "1 Corinthians"; "song of solomon" → "Song of Solomon". */
function se_bible_title_case(string $book): string
{
    $small = ['of'];
    $parts = explode(' ', $book);

    foreach ($parts as $i => $part) {
        if ($i > 0 && in_array($part, $small, true)) {
            continue;
        }
        $parts[$i] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
    }

    return implode(' ', $parts);
}

/**
 * Look a reference up, cache-first (§15.9).
 *
 * @return array{ref_display: string, ref_norm: string, text: string, source: string}|null
 */
function se_bible_lookup(PDO $pdo, string $ref, string $translation = 'KJV'): ?array
{
    $parsed = se_bible_ref_normalize($ref);
    if ($parsed === null) {
        return null;
    }

    $translation = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $translation) ?: 'KJV', 0, 10));

    if (se_table_exists($pdo, 'se_bible_cache')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT ref_display, text FROM se_bible_cache WHERE translation = ? AND ref_norm = ?"
            );
            $stmt->execute([$translation, $parsed['ref_norm']]);
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'ref_display' => (string) $row['ref_display'],
                    'ref_norm'    => $parsed['ref_norm'],
                    'text'        => (string) $row['text'],
                    'source'      => 'cache',
                ];
            }
        } catch (Throwable $e) {
            error_log('SE bible/cache: ' . $e->getMessage());
        }
    }

    $fetched = se_bible_fetch($parsed['ref_norm'], $translation);
    if ($fetched === null) {
        return null;
    }

    $display = $fetched['ref_display'] !== '' ? $fetched['ref_display'] : $parsed['display'];

    if (se_table_exists($pdo, 'se_bible_cache')) {
        try {
            $pdo->prepare(
                "INSERT INTO se_bible_cache (translation, ref_norm, ref_display, text)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ref_display = VALUES(ref_display), text = VALUES(text), fetched_at = NOW()"
            )->execute([$translation, $parsed['ref_norm'], mb_substr($display, 0, 60, 'UTF-8'), $fetched['text']]);
        } catch (Throwable $e) {
            error_log('SE bible/cache write: ' . $e->getMessage());
        }
    }

    return [
        'ref_display' => mb_substr($display, 0, 60, 'UTF-8'),
        'ref_norm'    => $parsed['ref_norm'],
        'text'        => $fetched['text'],
        'source'      => 'lookup',
    ];
}

/**
 * One HTTP call to the Bible service. Five-second timeout, no retries: the
 * Studio has a manual fallback and a stuck request helps nobody.
 *
 * @return array{ref_display: string, text: string}|null
 */
function se_bible_fetch(string $refNorm, string $translation): ?array
{
    $base = rtrim((string) ($_ENV['SE_BIBLE_API_BASE'] ?? 'https://bible-api.com'), '/');
    $url  = $base . '/' . rawurlencode($refNorm) . '?translation=' . rawurlencode(strtolower($translation));

    $body = null;

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_USERAGENT      => 'HODLekki-SpecialEvents/1.0',
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($status !== 200) {
                $body = null;
            }
        } else {
            $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $context);
        }
    } catch (Throwable $e) {
        error_log('SE bible/fetch: ' . $e->getMessage());
        return null;
    }

    if (!is_string($body) || $body === '') {
        return null;
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['text'])) {
        return null;
    }

    $text = preg_replace('/\s+/u', ' ', (string) $json['text']) ?? '';
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    return [
        'ref_display' => trim((string) ($json['reference'] ?? '')),
        'text'        => mb_substr($text, 0, 2000, 'UTF-8'),
    ];
}
