<?php
// /includes/bible/api_bible.php
//
// NKJV, NIV and AMP come from API.Bible (American Bible Society). They are
// copyrighted, so the rules here are not optional:
//
//   * Text is cached for at most BIBLE_CACHE_DAYS (29) days. API.Bible's
//     terms require cached content to be refreshed at least every 30 days. A
//     row past that age is never served: we refetch it, and if that fails we
//     DELETE it and tell the member, rather than show stale text.
//   * Chapters are requested with fums-version=3 and the response's
//     meta.fumsToken is stored with the cache row. The member's browser
//     reports that token every time the chapter is displayed (cache hit or
//     not) — see assets/js/bible/core.js, bibleFums().
//   * The copyright line API.Bible returns is shown under every chapter, and
//     Bible.copyright/info on the translation's About sheet (Terms §7).
//   * The free Starter plan allows ~5,000 calls a month. Every request is
//     counted in bible_api_usage and refused past BIBLE_API_MONTHLY_CAP
//     (default 4,500), so we degrade before API.Bible cuts us off.
//
// Configuration (.env): BIBLE_API_KEY (required), BIBLE_API_BASE (default
// https://rest.api.bible/v1), BIBLE_API_MONTHLY_CAP, and optionally
// BIBLE_API_ID_NKJV / _NIV / _AMP to pin a Bible id instead of discovering it.

const BIBLE_CACHE_DAYS = 29;
const BIBLE_CATALOG_DAYS = 7;
/** Searches of NKJV/NIV/AMP per member per day (each is an API.Bible call). */
const BIBLE_LICENSED_SEARCHES_PER_DAY = 40;

/**
 * Which API.Bible entry is which of our translations. abbreviationLocal is
 * what API.Bible shows people ("NIV"); abbreviation is its internal code
 * ("NIV11"). Amplified Classic (AMPC) is deliberately not matched.
 */
const BIBLE_API_MATCH = [
    'NKJV' => ['abbr' => ['NKJV'], 'name' => '/^new king james/i'],
    'NIV'  => ['abbr' => ['NIV', 'NIV11', 'NIV2011', 'NIVUK'], 'name' => '/^new international version/i'],
    'AMP'  => ['abbr' => ['AMP'], 'name' => '/^amplified bible(?!.*classic)/i'],
];

function bible_api_key(): string
{
    return bible_env('BIBLE_API_KEY');
}

function bible_api_base(): string
{
    $base = rtrim(bible_env('BIBLE_API_BASE', 'https://rest.api.bible/v1'), '/');

    // The key travels in a header on every request; never send it in clear.
    return str_starts_with($base, 'https://') ? $base : 'https://rest.api.bible/v1';
}

function bible_api_cap(): int
{
    $cap = (int) bible_env('BIBLE_API_MONTHLY_CAP', '4500');

    return $cap > 0 ? $cap : 4500;
}

/**
 * Tests replace the network with a fake: fn(string $url, array $headers): array{status:int, body:string}.
 */
function bible_api_set_transport(?callable $transport): void
{
    $GLOBALS['__bible_api_transport'] = $transport;
}

// ---------------------------------------------------------------------------
// Usage counter
// ---------------------------------------------------------------------------

function bible_api_usage(?PDO $pdo): int
{
    if ($pdo === null || !bible_table_exists($pdo, 'bible_api_usage')) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT calls FROM bible_api_usage WHERE month = ?');
        $stmt->execute([bible_month()]);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('Bible api/usage: ' . $e->getMessage());
        return 0;
    }
}

function bible_api_count_call(?PDO $pdo): void
{
    if ($pdo === null || !bible_table_exists($pdo, 'bible_api_usage')) {
        return;
    }
    $month = bible_month();
    $now   = bible_now();
    try {
        $up = $pdo->prepare('UPDATE bible_api_usage SET calls = calls + 1, updated_at = ? WHERE month = ?');
        $up->execute([$now, $month]);
        if ($up->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO bible_api_usage (month, calls, updated_at) VALUES (?, 1, ?)')
                    ->execute([$month, $now]);
            } catch (PDOException $e) {
                // Another request inserted the month first.
                $pdo->prepare('UPDATE bible_api_usage SET calls = calls + 1, updated_at = ? WHERE month = ?')
                    ->execute([$now, $month]);
            }
        }
    } catch (Throwable $e) {
        error_log('Bible api/count: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * One GET to API.Bible.
 *
 * @return array{ok:bool, status:int, json:?array, error:string}
 */
function bible_api_get(?PDO $pdo, string $path, array $query = []): array
{
    $key = bible_api_key();
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'NO_KEY'];
    }
    if (bible_api_usage($pdo) >= bible_api_cap()) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'QUOTA'];
    }

    $url = bible_api_base() . $path . ($query === [] ? '' : '?' . http_build_query($query));
    $headers = ['api-key: ' . $key, 'Accept: application/json'];

    bible_api_count_call($pdo);

    $transport = $GLOBALS['__bible_api_transport'] ?? null;
    if (is_callable($transport)) {
        $res = $transport($url, $headers);
        $status = (int) ($res['status'] ?? 0);
        $body   = (string) ($res['body'] ?? '');
    } else {
        [$status, $body] = bible_api_curl($url, $headers);
    }

    if ($status !== 200) {
        if ($status !== 404) {
            // Never log the URL's query or the key; the path is enough.
            error_log(sprintf('Bible api: HTTP %d for %s', $status, $path));
        }
        return ['ok' => false, 'status' => $status, 'json' => null, 'error' => $status === 0 ? 'UNREACHABLE' : 'HTTP_' . $status];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'json' => null, 'error' => 'BAD_JSON'];
    }

    return ['ok' => true, 'status' => 200, 'json' => $json, 'error' => ''];
}

/**
 * @return array{0:int, 1:string}
 */
function bible_api_curl(string $url, array $headers): array
{
    if (!function_exists('curl_init')) {
        return [0, ''];
    }
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            // A redirect would resend the api-key header to whatever host
            // Location names; API.Bible never redirects in normal operation.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'HODLekki-Bible/1.0',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? $body : ''];
    } catch (Throwable $e) {
        error_log('Bible api/curl: ' . $e->getMessage());
        return [0, ''];
    }
}

// ---------------------------------------------------------------------------
// Catalogue: which API.Bible id is NIV, NKJV, AMP for this key
// ---------------------------------------------------------------------------

/**
 * Pick the API.Bible entry for one of our codes from a /bibles listing.
 */
function bible_api_match(array $bibles, string $code): ?array
{
    $rule = BIBLE_API_MATCH[$code] ?? null;
    if ($rule === null) {
        return null;
    }

    foreach (['abbreviationLocal', 'abbreviation'] as $field) {
        foreach ($bibles as $b) {
            $abbr = strtoupper(str_replace(' ', '', (string) ($b[$field] ?? '')));
            if ($abbr !== '' && in_array($abbr, $rule['abbr'], true)) {
                return $b;
            }
        }
    }
    foreach ($bibles as $b) {
        if (preg_match($rule['name'], (string) ($b['name'] ?? ''))) {
            return $b;
        }
    }

    return null;
}

function bible_config_get(?PDO $pdo, string $name): ?string
{
    if ($pdo === null || !bible_table_exists($pdo, 'bible_config')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT value FROM bible_config WHERE name = ?');
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    } catch (Throwable $e) {
        error_log('Bible config/get: ' . $e->getMessage());
        return null;
    }
}

function bible_config_set(?PDO $pdo, string $name, string $value): void
{
    if ($pdo === null || !bible_table_exists($pdo, 'bible_config')) {
        return;
    }
    $now = bible_now();
    try {
        $up = $pdo->prepare('UPDATE bible_config SET value = ?, updated_at = ? WHERE name = ?');
        $up->execute([$value, $now, $name]);
        if ($up->rowCount() === 0 && bible_config_get($pdo, $name) === null) {
            $pdo->prepare('INSERT INTO bible_config (name, value, updated_at) VALUES (?, ?, ?)')
                ->execute([$name, $value, $now]);
        }
    } catch (Throwable $e) {
        error_log('Bible config/set: ' . $e->getMessage());
    }
}

/**
 * The English Bibles this key can read, fetched at most weekly.
 *
 * @return array{fetched_at:string, bibles:list<array>, error:string}|null
 */
function bible_api_catalog(?PDO $pdo, bool $refresh = false): ?array
{
    $cached = json_decode((string) bible_config_get($pdo, 'api_catalog'), true);
    $fresh  = is_array($cached)
        && isset($cached['fetched_at'])
        && strtotime((string) $cached['fetched_at']) > time() - BIBLE_CATALOG_DAYS * 86400;

    if ($fresh && !$refresh) {
        return $cached;
    }
    if (bible_api_key() === '' || $pdo === null) {
        return is_array($cached) ? $cached : null;
    }

    $res = bible_api_get($pdo, '/bibles', ['language' => 'eng', 'include-full-details' => 'true']);
    if (!$res['ok'] || !isset($res['json']['data']) || !is_array($res['json']['data'])) {
        // Keep using the last good catalogue; remember the failure so the
        // admin status card can say why.
        $keep = is_array($cached) ? $cached : ['fetched_at' => '', 'bibles' => []];
        $keep['error'] = $res['error'];
        return $keep;
    }

    $bibles = [];
    foreach ($res['json']['data'] as $b) {
        if (!is_array($b) || !isset($b['id'])) {
            continue;
        }
        $bibles[] = [
            'id'                => (string) $b['id'],
            'abbreviation'      => (string) ($b['abbreviation'] ?? ''),
            'abbreviationLocal' => (string) ($b['abbreviationLocal'] ?? ''),
            'name'              => (string) ($b['name'] ?? ''),
            'copyright'         => bible_api_plain((string) ($b['copyright'] ?? '')),
            'info'              => bible_api_plain((string) ($b['info'] ?? '')),
        ];
    }
    $catalog = ['fetched_at' => bible_now(), 'bibles' => $bibles, 'error' => ''];
    bible_config_set($pdo, 'api_catalog', (string) json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $catalog;
}

/**
 * The API.Bible id (and the copyright to show) for one of our codes.
 *
 * @return array{id:string, name:string, copyright:string, info:string}|null
 */
function bible_api_resolve(?PDO $pdo, string $code, bool $allowFetch = true): ?array
{
    $pinned = bible_env('BIBLE_API_ID_' . $code);
    $catalog = null;
    if ($allowFetch) {
        $catalog = bible_api_catalog($pdo);
    } else {
        $catalog = json_decode((string) bible_config_get($pdo, 'api_catalog'), true);
        $catalog = is_array($catalog) ? $catalog : null;
    }
    $bibles = $catalog['bibles'] ?? [];

    if ($pinned !== '') {
        foreach ($bibles as $b) {
            if ($b['id'] === $pinned) {
                return ['id' => $pinned, 'name' => $b['name'], 'copyright' => $b['copyright'], 'info' => $b['info']];
            }
        }
        return ['id' => $pinned, 'name' => BIBLE_TRANSLATIONS[$code]['name'] ?? $code, 'copyright' => '', 'info' => ''];
    }

    $hit = bible_api_match($bibles, $code);

    return $hit === null ? null : [
        'id'        => $hit['id'],
        'name'      => $hit['name'],
        'copyright' => $hit['copyright'] ?? '',
        'info'      => $hit['info'] ?? '',
    ];
}

/**
 * Can members read this licensed translation right now? Never spends an API
 * call: it only looks at the key, the stored catalogue and the month's usage.
 *
 * @return array{available:bool, reason:string, copyright:string}
 */
function bible_api_translation_status(?PDO $pdo, string $code): array
{
    if (bible_api_key() === '') {
        return ['available' => false, 'reason' => 'Not switched on yet — the church’s API.Bible key has not been added.', 'copyright' => ''];
    }
    if ($pdo === null || !bible_table_exists($pdo, 'bible_chapter_cache')) {
        return ['available' => false, 'reason' => 'Being set up — try again after the next update.', 'copyright' => ''];
    }

    $catalogRaw = bible_config_get($pdo, 'api_catalog');
    if ($catalogRaw !== null || bible_env('BIBLE_API_ID_' . $code) !== '') {
        $resolved = bible_api_resolve($pdo, $code, false);
        if ($resolved === null) {
            return ['available' => false, 'reason' => 'Not enabled on the church’s API.Bible account.', 'copyright' => ''];
        }
        if (bible_api_usage($pdo) >= bible_api_cap()) {
            return ['available' => true, 'reason' => 'Only chapters read recently are available until next month.', 'copyright' => $resolved['copyright']];
        }
        return ['available' => true, 'reason' => '', 'copyright' => $resolved['copyright']];
    }

    // Key present but the catalogue has never been fetched: the first chapter
    // request will discover it.
    return ['available' => true, 'reason' => '', 'copyright' => ''];
}

// ---------------------------------------------------------------------------
// Chapters
// ---------------------------------------------------------------------------

function bible_cache_cutoff(): string
{
    return date('Y-m-d H:i:s', time() - BIBLE_CACHE_DAYS * 86400);
}

/**
 * Verses of a licensed chapter from the cache, if fresh. Never fetches.
 *
 * @return list<array>|null
 */
function bible_api_cached_verses(?PDO $pdo, string $code, int $book, int $chapter): ?array
{
    $row = bible_api_cache_row($pdo, $code, $book, $chapter);
    if ($row === null || $row['fetched_at'] < bible_cache_cutoff()) {
        return null;
    }
    $verses = json_decode((string) $row['payload'], true);

    return is_array($verses) ? $verses : null;
}

function bible_api_cache_row(?PDO $pdo, string $code, int $book, int $chapter): ?array
{
    if ($pdo === null || !bible_table_exists($pdo, 'bible_chapter_cache')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT payload, copyright, fums_token, fetched_at FROM bible_chapter_cache WHERE translation = ? AND book = ? AND chapter = ?');
        $stmt->execute([$code, $book, $chapter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Bible cache/read: ' . $e->getMessage());
        return null;
    }
}

/**
 * A licensed chapter: from the cache when fresh, otherwise from API.Bible.
 *
 * @return array{ok:bool, code?:string, message?:string, verses?:list<array>, copyright?:string, fums_token?:?string, source?:string}
 */
function bible_api_chapter(?PDO $pdo, string $code, int $book, int $chapter): array
{
    if (bible_api_key() === '') {
        return ['ok' => false, 'code' => 'TRANSLATION_OFF', 'message' => BIBLE_TRANSLATIONS[$code]['name'] . ' is not switched on yet. The church office needs to add its API.Bible key.'];
    }
    if ($pdo === null || !bible_table_exists($pdo, 'bible_chapter_cache')) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'This translation is still being set up. Please read in the KJV for now.'];
    }

    $row = bible_api_cache_row($pdo, $code, $book, $chapter);
    if ($row !== null && $row['fetched_at'] >= bible_cache_cutoff()) {
        $verses = json_decode((string) $row['payload'], true);
        if (is_array($verses) && $verses !== []) {
            return [
                'ok'         => true,
                'verses'     => $verses,
                'copyright'  => (string) ($row['copyright'] ?? ''),
                'fums_token' => $row['fums_token'] !== null && $row['fums_token'] !== '' ? (string) $row['fums_token'] : null,
                'source'     => 'cache',
            ];
        }
    }

    $resolved = bible_api_resolve($pdo, $code);
    if ($resolved === null) {
        bible_api_drop_stale($pdo, $code, $book, $chapter, $row);
        return ['ok' => false, 'code' => 'TRANSLATION_OFF', 'message' => BIBLE_TRANSLATIONS[$code]['name'] . ' is not enabled on the church’s API.Bible account yet.'];
    }

    $meta = bible_book($book);
    $res = bible_api_get($pdo, sprintf('/bibles/%s/chapters/%s.%d', rawurlencode($resolved['id']), $meta['usfm'], $chapter), [
        'content-type'           => 'html',
        'include-notes'          => 'false',
        'include-titles'         => 'true',
        'include-chapter-numbers' => 'false',
        'include-verse-numbers'  => 'true',
        'include-verse-spans'    => 'false',
        'fums-version'           => '3',
    ]);

    if (!$res['ok']) {
        bible_api_drop_stale($pdo, $code, $book, $chapter, $row);
        $msg = match ($res['error']) {
            'QUOTA'       => 'We have reached this month’s limit for ' . $code . '. Chapters you opened recently still work; for the rest, please read in the KJV until next month.',
            'HTTP_401', 'HTTP_403' => $code . ' is not available on the church’s API.Bible account. Please read in the KJV for now.',
            'HTTP_404'    => 'This chapter is not available in the ' . $code . '.',
            default       => 'We could not reach the ' . $code . ' right now. Check your connection, or read this chapter in the KJV.',
        };
        return ['ok' => false, 'code' => $res['error'] === 'QUOTA' ? 'QUOTA' : 'SOURCE_UNREACHABLE', 'message' => $msg];
    }

    $data      = $res['json']['data'] ?? [];
    $html      = (string) ($data['content'] ?? '');
    $copyright = bible_api_plain((string) ($data['copyright'] ?? ''));
    if ($copyright === '') {
        $copyright = $resolved['copyright'];
    }
    $fums = $res['json']['meta']['fumsToken'] ?? null;
    $fums = is_string($fums) && $fums !== '' ? substr($fums, 0, 512) : null;

    $verses = bible_api_parse_html($html);
    if ($verses === []) {
        bible_api_drop_stale($pdo, $code, $book, $chapter, $row);
        return ['ok' => false, 'code' => 'SOURCE_UNREACHABLE', 'message' => 'This chapter came back empty from the ' . $code . '. Please try again later.'];
    }

    bible_api_cache_store($pdo, $code, $book, $chapter, $resolved['id'], $verses, $copyright, $fums);

    return ['ok' => true, 'verses' => $verses, 'copyright' => $copyright, 'fums_token' => $fums, 'source' => 'api'];
}

function bible_api_cache_store(PDO $pdo, string $code, int $book, int $chapter, string $bibleId, array $verses, string $copyright, ?string $fums): void
{
    $payload = (string) json_encode($verses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = bible_now();
    try {
        $up = $pdo->prepare('UPDATE bible_chapter_cache SET api_bible_id = ?, payload = ?, copyright = ?, fums_token = ?, fetched_at = ? WHERE translation = ? AND book = ? AND chapter = ?');
        $up->execute([$bibleId, $payload, $copyright, $fums, $now, $code, $book, $chapter]);
        if ($up->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO bible_chapter_cache (translation, book, chapter, api_bible_id, payload, copyright, fums_token, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$code, $book, $chapter, $bibleId, $payload, $copyright, $fums, $now]);
            } catch (PDOException $e) {
                // Stored by a parallel request a moment ago; nothing to do.
            }
        }
        // Housekeeping: about one request in twenty clears every expired row,
        // so nothing older than the licence allows lingers in the database.
        if (random_int(1, 20) === 1) {
            $pdo->prepare('DELETE FROM bible_chapter_cache WHERE fetched_at < ?')->execute([bible_cache_cutoff()]);
        }
    } catch (Throwable $e) {
        error_log('Bible cache/store: ' . $e->getMessage());
    }
}

/**
 * A stale cache row we could not refresh must go: never serve licensed text
 * older than the licence allows.
 */
function bible_api_drop_stale(PDO $pdo, string $code, int $book, int $chapter, ?array $row): void
{
    if ($row === null || $row['fetched_at'] >= bible_cache_cutoff()) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM bible_chapter_cache WHERE translation = ? AND book = ? AND chapter = ?')
            ->execute([$code, $book, $chapter]);
    } catch (Throwable $e) {
        error_log('Bible cache/drop: ' . $e->getMessage());
    }
}

/**
 * Plain text from an API.Bible copyright/info field (which may hold HTML).
 */
function bible_api_plain(string $html): string
{
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

/**
 * Keyword search in a licensed translation. Each call costs one API call.
 *
 * @return array{ok:bool, code?:string, message?:string, total?:int, results?:list<array>, fums_token?:?string}
 */
function bible_api_search(?PDO $pdo, string $code, string $query, string $scope, int $limit, int $offset): array
{
    if (bible_api_key() === '' || $pdo === null || !bible_table_exists($pdo, 'bible_api_usage')) {
        return ['ok' => false, 'code' => 'TRANSLATION_OFF', 'message' => 'Search in ' . $code . ' is not switched on yet.'];
    }
    $resolved = bible_api_resolve($pdo, $code);
    if ($resolved === null) {
        return ['ok' => false, 'code' => 'TRANSLATION_OFF', 'message' => $code . ' is not enabled on the church’s API.Bible account.'];
    }

    $params = [
        'query'        => $query,
        'limit'        => max(1, min(50, $limit)),
        'offset'       => max(0, $offset),
        'sort'         => 'canonical',
        'fums-version' => '3',
    ];
    [$from, $to] = bible_search_scope($scope);
    if ($from === $to) {
        $params['range'] = bible_book($from)['usfm'];
    } elseif ($from === 1 && $to === 39) {
        $params['range'] = 'GEN-MAL';
    } elseif ($from === 40 && $to === 66) {
        $params['range'] = 'MAT-REV';
    }

    $res = bible_api_get($pdo, sprintf('/bibles/%s/search', rawurlencode($resolved['id'])), $params);
    if (!$res['ok']) {
        return ['ok' => false, 'code' => $res['error'] === 'QUOTA' ? 'QUOTA' : 'SOURCE_UNREACHABLE', 'message' => $res['error'] === 'QUOTA'
            ? 'Search in ' . $code . ' has reached this month’s limit. Search in the KJV still works.'
            : 'We could not reach the ' . $code . ' search right now.'];
    }

    $data    = $res['json']['data'] ?? [];
    $results = [];
    foreach (($data['verses'] ?? []) as $v) {
        $parts = explode('.', (string) ($v['id'] ?? ''));
        $book  = bible_book_by_usfm($parts[0] ?? '');
        if ($book === null || count($parts) < 3) {
            continue;
        }
        $results[] = [
            'book'    => $book['id'],
            'chapter' => (int) $parts[1],
            'verse'   => (int) $parts[2],
            'ref'     => bible_format_ref($book['id'], (int) $parts[1], (int) $parts[2]),
            'text'    => trim(preg_replace('/\s+/u', ' ', (string) ($v['text'] ?? '')) ?? ''),
        ];
    }
    $fums = $res['json']['meta']['fumsToken'] ?? null;

    return [
        'ok'         => true,
        'total'      => (int) ($data['total'] ?? count($results)),
        'results'    => $results,
        'fums_token' => is_string($fums) && $fums !== '' ? $fums : null,
    ];
}

// ---------------------------------------------------------------------------
// Chapter HTML → verses
// ---------------------------------------------------------------------------

/** Paragraph classes that are headings printed above the next verse. */
const BIBLE_HTML_HEADINGS = ['s', 's1', 's2', 's3', 's4', 'ms', 'ms1', 'ms2', 'ms3', 'mt', 'mt1', 'mt2', 'sr', 'sp', 'qa'];
/** Paragraph classes we drop: cross-reference lines and chapter labels. */
const BIBLE_HTML_SKIP_BLOCKS = ['r', 'mr', 'c', 'cl', 'cp', 'cd', 'rem', 'restore', 'toc1', 'toc2', 'toc3', 'h', 'ide'];
/** Inline classes whose whole subtree is dropped: footnotes, cross references. */
const BIBLE_HTML_SKIP_SPANS = ['f', 'fe', 'x', 'fr', 'ft', 'fq', 'fqa', 'fk', 'fl', 'fp', 'xo', 'xt', 'xq', 'note', 'notemark', 'notecaller'];

/**
 * Parse API.Bible chapter HTML (content-type=html, verse numbers on, notes
 * off) into the shared verse shape. Verse markers are
 * <span class="v" data-number="16">16</span>; every text node after a marker
 * belongs to that verse until the next marker, across paragraph boundaries.
 *
 * @return list<array>
 */
function bible_api_parse_html(string $html): array
{
    if (trim($html) === '') {
        return [];
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="bible-root">' . $html . '</div>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $state = (object) [
        'verses'   => [],     // number => builder
        'order'    => [],
        'current'  => null,
        'headings' => [],
        'title'    => null,
        'block'    => 'p',    // what the next verse opens: 'p' paragraph, 'q' poetry line, null none
        'pending'  => true,   // a block began and no text has followed yet
    ];

    $root = $doc->getElementById('bible-root') ?? $doc->documentElement;
    if ($root === null) {
        return [];
    }
    bible_api_walk($root, $state, ['w' => false, 'i' => false, 'n' => false]);

    $out = [];
    foreach ($state->order as $n) {
        $b = $state->verses[$n];
        $chars = $b['chars'];
        $flags = $b['flags'];
        while ($chars !== [] && $chars[count($chars) - 1] === ' ') {
            array_pop($chars);
            array_pop($flags);
        }
        if ($chars === []) {
            continue;
        }
        $verse = ['v' => $n, 't' => implode('', $chars)];
        if ($b['p']) {
            $verse['p'] = 1;
        }
        if ($b['q']) {
            $verse['q'] = 1;
        }
        if ($b['d'] !== null) {
            $verse['d'] = $b['d'];
        }
        if ($b['h'] !== []) {
            $verse['h'] = $b['h'];
        }
        foreach (['w', 'i', 'n'] as $k) {
            $col = array_map(static fn(array $f): bool => $f[$k], $flags);
            $ranges = bible_ranges_from_flags($chars, $col);
            if ($ranges !== []) {
                $verse[$k] = $ranges;
            }
        }
        $len = count($chars);
        $br = array_values(array_unique(array_filter($b['br'], static fn(int $o): bool => $o > 0 && $o < $len)));
        if ($br !== []) {
            $verse['br'] = $br;
        }
        $out[] = $verse;
    }

    return $out;
}

/**
 * Flag column → flat [start, end, …] ranges, trimmed of edge spaces.
 *
 * @param list<string> $chars
 * @param list<bool>   $flags
 * @return list<int>
 */
function bible_ranges_from_flags(array $chars, array $flags): array
{
    $out = [];
    $n = count($flags);
    $i = 0;
    while ($i < $n) {
        if (!$flags[$i]) {
            $i++;
            continue;
        }
        $start = $i;
        while ($i < $n && $flags[$i]) {
            $i++;
        }
        $end = $i;
        while ($start < $end && $chars[$start] === ' ') {
            $start++;
        }
        while ($end > $start && $chars[$end - 1] === ' ') {
            $end--;
        }
        if ($end > $start) {
            $out[] = $start;
            $out[] = $end;
        }
    }

    return $out;
}

function bible_html_classes(DOMElement $el): array
{
    $cls = trim((string) $el->getAttribute('class'));

    return $cls === '' ? [] : preg_split('/\s+/', strtolower($cls));
}

function bible_api_walk(DOMNode $node, object $state, array $marks): void
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            bible_api_text($state, $child->nodeValue ?? '', $marks);
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        $cls = bible_html_classes($child);

        if ($tag === 'p' || $tag === 'div') {
            if (array_intersect($cls, BIBLE_HTML_SKIP_BLOCKS)) {
                continue;
            }
            if (array_intersect($cls, BIBLE_HTML_HEADINGS)) {
                $text = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');
                if ($text !== '') {
                    $state->headings[] = $text;
                }
                $state->block = 'p';
                $state->pending = true;
                continue;
            }
            if (in_array('d', $cls, true)) {
                $text = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');
                if ($text !== '') {
                    $state->title = $text;
                }
                $state->block = 'p';
                $state->pending = true;
                continue;
            }
            if (in_array('b', $cls, true)) {
                $state->block = 'p';
                $state->pending = true;
                continue;
            }
            $isPoetry = (bool) preg_grep('/^q[0-9a-z]*$/', $cls);
            $state->block = $isPoetry ? 'q' : 'p';
            $state->pending = true;
            bible_api_walk($child, $state, $marks);
            continue;
        }

        if ($tag === 'span' || $tag === 'sup') {
            if (array_intersect($cls, BIBLE_HTML_SKIP_SPANS)) {
                continue;
            }
            if (in_array('v', $cls, true)) {
                bible_api_verse_start($state, (int) $child->getAttribute('data-number'));
                continue;
            }
            $next = $marks;
            if (in_array('wj', $cls, true)) {
                $next['w'] = true;
            }
            if (array_intersect($cls, ['add', 'it', 'tl'])) {
                $next['i'] = true;
            }
            if (array_intersect($cls, ['nd', 'sc'])) {
                $next['n'] = true;
            }
            bible_api_walk($child, $state, $next);
            continue;
        }

        if ($tag === 'br') {
            if ($state->current !== null) {
                $state->verses[$state->current]['br'][] = count($state->verses[$state->current]['chars']);
            }
            continue;
        }

        $next = $marks;
        if ($tag === 'i' || $tag === 'em') {
            $next['i'] = true;
        }
        bible_api_walk($child, $state, $next);
    }
}

function bible_api_verse_start(object $state, int $n): void
{
    if ($n < 1) {
        return;
    }
    if (!isset($state->verses[$n])) {
        $state->verses[$n] = ['chars' => [], 'flags' => [], 'p' => false, 'q' => false, 'd' => null, 'h' => [], 'br' => []];
        $state->order[] = $n;
    }
    $b = &$state->verses[$n];
    if ($state->pending) {
        if ($state->block === 'q') {
            $b['q'] = true;
        }
        $b['p'] = true;
    }
    if ($state->headings !== []) {
        $b['h'] = array_merge($b['h'], $state->headings);
        $state->headings = [];
    }
    if ($state->title !== null) {
        $b['d'] = $state->title;
        $state->title = null;
    }
    unset($b);
    $state->pending = false;
    $state->current = $n;
}

function bible_api_text(object $state, string $text, array $marks): void
{
    if ($state->current === null) {
        return;
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if ($text === '') {
        return;
    }
    $b = &$state->verses[$state->current];

    // A paragraph or poetry line that starts in the middle of a verse.
    if ($state->pending && trim($text) !== '') {
        if ($b['chars'] !== []) {
            $b['br'][] = count($b['chars']);
            if ($b['chars'][count($b['chars']) - 1] !== ' ') {
                $b['chars'][] = ' ';
                $b['flags'][] = ['w' => false, 'i' => false, 'n' => false];
                $b['br'][count($b['br']) - 1] = count($b['chars']);
            }
        }
        $state->pending = false;
    }

    foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
        $last = $b['chars'] === [] ? null : $b['chars'][count($b['chars']) - 1];
        if ($ch === ' ' && ($last === null || $last === ' ')) {
            continue;
        }
        $b['chars'][] = $ch;
        $b['flags'][] = $marks;
    }
    unset($b);
}
