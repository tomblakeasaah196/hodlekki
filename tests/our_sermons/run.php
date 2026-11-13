<?php
// /tests/our_sermons/run.php
//
// Our sermons, PR S1 (OUR-SERMONS-PLAN.md), on SQLite built from the REAL
// migrations (the module's tables and the permission matrix), with a fake
// internet (no network): the shared table contract for S2 / S3, the
// episode-title parser, links, durations, the RSS reader's hardening, the
// safe fetcher, the poller (drafts, dedupe by guid, the cap, conditional GET,
// pasted episodes completed from the feed, who is told), Paste link (Spotify
// with its feed episode, YouTube), checking and publishing (everyone told
// once, batched), the members' list / filters / calendar, every can()
// decision of the API, and the wiring (cron hook, strict pages, CSP).
//
//   php tests/our_sermons/run.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

date_default_timezone_set('Africa/Lagos');
$root = dirname(__DIR__, 2);
require_once $root . '/tests/permissions/install.php';    // the permission engine and the day-one matrix
require_once $root . '/includes/our_sermons/bootstrap.php';

$passed = 0;
$failed = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}
function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function section(string $name): void
{
    echo "\n-- {$name}\n";
}

/** A migration's CREATE TABLEs, translated to SQLite (as the other suites do). */
function os_schema_sql(string $file): array
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/' . $file));
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
    $out = [];
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '' || ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line))) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY \w+ \((.+)\)$/', $line, $m)) {
                $cols[] = 'UNIQUE (' . $m[1] . ')';
                continue;
            }
            if (preg_match('/^KEY (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE INDEX {$m[1]} ON {$name} ({$m[2]})";
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = (string) preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(JSON|LONGTEXT|MEDIUMTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        array_push($out, ...$indexes);
    }
    return $out;
}

/** A church: people, roles, an Envision department, the matrix, the module's tables. */
function os_db(bool $withTables = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach ([
        "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password_hash TEXT,
            account_status TEXT DEFAULT 'active', attendance_status TEXT, created_at TEXT)",
        'CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0)',
        'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE user_departments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, department_id INTEGER,
            is_active INTEGER DEFAULT 1, role_in_dept TEXT)',
        'CREATE TABLE system_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, message TEXT, link_url TEXT)',
    ] as $stmt) {
        $pdo->exec($stmt);
    }
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor')");
    $pdo->exec("INSERT INTO departments (id, name) VALUES (3, 'Envision (Media)'), (6, 'Sanitation')");
    $people = [
        // id, name, password?, account, attendance
        [1, 'Super', true, 'active', 'Active'], [2, 'Pastor', true, 'active', 'Active'], [3, 'Envy', true, 'active', 'Active'],
        [4, 'Member', true, 'active', 'Active'], [5, 'NoLogin', false, 'active', 'Active'], [6, 'Moved', true, 'active', 'Relocated'],
        [7, 'Stopped', true, 'suspended', 'Active'], [8, 'Sweeper', true, 'active', 'Active'],
    ];
    foreach ($people as [$id, $name, $pw, $acct, $att]) {
        $pdo->prepare("INSERT INTO users (id, first_name, last_name, password_hash, account_status, attendance_status, created_at)
                       VALUES (?, ?, 'Test', ?, ?, ?, '2026-01-01 10:00:00')")->execute([$id, $name, $pw ? 'hash' : null, $acct, $att]);
    }
    $pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');
    $pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (3, 3, 1, 'Worker'), (8, 6, 1, 'Worker')");
    perm_test_install($pdo);
    $pdo->exec(perm_test_migration('20261213090100_perm_our_sermons.sql'));
    if ($withTables) {
        foreach (os_schema_sql('20261213090000_our_sermons_core.sql') as $stmt) {
            $pdo->exec($stmt);
        }
    }
    perm_reset();
    return $pdo;
}

function os_as(int $uid): void
{
    $_SESSION = ['user_id' => $uid];
    perm_reset();
}

function act(PDO $pdo, int $uid, string $action, array $in = []): array
{
    os_as($uid);
    return our_sermons_action($pdo, $action, $in, $uid);
}

/** A small real PNG (getimagesizefromstring reads it; no GD needed). */
function os_png(int $w = 24, int $h = 24): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0" . str_repeat("\x1d\x35\x6a", $w);
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
}

/** An RSS feed like the Spotify for Creators one. */
function os_feed(array $items, string $title = 'Household Of David Lekki Centre'): string
{
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
        . '<title>' . htmlspecialchars($title) . '</title><link>https://podcasters.spotify.com/pod/show/hodlekki</link>'
        . '<itunes:image href="https://d3t3ozftmdmh3i.cloudfront.net/show.jpg"/>';
    foreach ($items as $it) {
        $x .= '<item><title><![CDATA[' . $it['title'] . ']]></title><guid isPermaLink="false">' . htmlspecialchars($it['guid']) . '</guid>'
            . '<pubDate>' . ($it['pub'] ?? 'Wed, 11 Nov 2026 09:00:00 GMT') . '</pubDate>'
            . (array_key_exists('audio', $it) ? ($it['audio'] === null ? '' : '<enclosure url="' . htmlspecialchars($it['audio']) . '" length="1" type="' . ($it['type'] ?? 'audio/mpeg') . '"/>')
                : '<enclosure url="https://anchor.fm/s/abc12345/podcast/play/' . crc32($it['guid']) . '/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F'
                    . rawurlencode($it['guid']) . '.mp3" length="1" type="audio/mpeg"/>')
            . '<itunes:duration>' . ($it['dur'] ?? '00:52:10') . '</itunes:duration>'
            . (isset($it['image']) ? '<itunes:image href="' . $it['image'] . '"/>' : '')
            . '</item>';
    }
    return $x . '</channel></rss>';
}

// The fake internet: URL => response, or a callable. Every request is logged.
$GLOBALS['os_routes'] = [];
$GLOBALS['os_log'] = [];
$GLOBALS['our_sermons_http'] = static function (string $url, array $o): array {
    $GLOBALS['os_log'][] = ['url' => $url, 'headers' => $o['headers'] ?? []];
    foreach ($GLOBALS['os_routes'] as $prefix => $resp) {
        if ($url === $prefix || (str_ends_with($prefix, '*') && str_starts_with($url, substr($prefix, 0, -1)))) {
            $r = is_callable($resp) ? $resp($url, $o) : $resp;
            return $r + ['status' => 200, 'body' => '', 'headers' => [], 'error' => ''];
        }
    }
    return ['status' => 404, 'body' => '', 'headers' => [], 'error' => ''];
};
$GLOBALS['our_sermons_no_feed_cache'] = true;
$artDir = sys_get_temp_dir() . '/os_art_' . bin2hex(random_bytes(4));
$GLOBALS['our_sermons_artwork_dir'] = $artDir;
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-13 10:00:00', new DateTimeZone('Africa/Lagos'));
$png = os_png();

// ===========================================================================
section('The shared table contract (S2 and S3 build on these names)');
// ===========================================================================
$pdo = os_db();
$cols = static fn (string $t): array => array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(), 'name');
eq('our_sermons columns', ['id', 'title', 'preacher', 'date_preached', 'date_source', 'service', 'series', 'summary', 'key_points', 'scriptures',
    'artwork_url', 'artwork_source', 'audio_url', 'duration_seconds', 'spotify_url', 'spotify_episode_id', 'youtube_url', 'youtube_id',
    'source', 'feed_id', 'feed_guid', 'feed_title', 'feed_published_at', 'status', 'ai_status', 'ai_drafted_at', 'published_at',
    'published_by', 'notified_at', 'created_by', 'created_at', 'updated_at', 'updated_by'], $cols('our_sermons'));
eq('our_sermon_feeds columns', ['id', 'url', 'title', 'is_active', 'import_from', 'last_checked_at', 'last_ok_at', 'last_error', 'etag',
    'last_modified', 'items_seen', 'created_by', 'created_at', 'updated_at'], $cols('our_sermon_feeds'));
eq('our_sermon_feed_skips columns', ['id', 'feed_guid', 'title', 'created_by', 'created_at'], $cols('our_sermon_feed_skips'));
$mig = (string) file_get_contents($root . '/db/migrations/20261213090000_our_sermons_core.sql');
ok('the migration is idempotent InnoDB utf8mb4', substr_count($mig, 'CREATE TABLE IF NOT EXISTS') === 3
    && substr_count($mig, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') === 3 && !preg_match('/\bDROP\b|ALTER TABLE/i', $mig));
ok('date_preached is required; feed_guid, the Spotify episode and the video are unique',
    str_contains($mig, 'date_preached DATE NOT NULL') && str_contains($mig, 'UNIQUE KEY uq_our_sermons_guid (feed_guid)')
    && str_contains($mig, 'UNIQUE KEY uq_our_sermons_spotify (spotify_episode_id)') && str_contains($mig, 'UNIQUE KEY uq_our_sermons_youtube (youtube_id)'));
ok('S2\'s and S3\'s tables are theirs (no notes, progress or AI usage table here)',
    !preg_match('/CREATE TABLE[^(]*\b(our_sermon_(?!feeds|feed_skips)\w+|\w*ai_\w*)\b/i', implode('', array_map('file_get_contents',
        glob($root . '/db/migrations/2026121309*') ?: []))));
/** PHP source without its comments. */
$code = static function (string $file): string {
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }
    return $out;
};
ok('the poller and the module are portable (no NOW(), INSERT IGNORE, ON DUPLICATE KEY)',
    !preg_match('/\bNOW\(\)|INSERT IGNORE|ON DUPLICATE KEY/i', implode('', array_map($code, glob($root . '/includes/our_sermons/*.php') ?: []))));
ok('the module never touches the public Word Library\'s table',
    !preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+sermons\b/i', implode('', array_map('file_get_contents',
        array_merge(glob($root . '/includes/our_sermons/*.php') ?: [], glob($root . '/modules/our_sermons/*.php') ?: [], [$root . '/api/our_sermons_api.php'])))));

// ===========================================================================
section('The title parser');
// ===========================================================================
$cases = [
    // raw => [title, preacher, date, service]
    'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026'
        => ['Ground Zero - The Faith Once Delivered', 'Pastor Ebele Uzo-Peters', '2026-11-08', 'sunday'],
    'The Blood | Midweek Service | Pastor Ebele Uzo-Peters | Exousia | 30th September 2026'
        => ['The Blood', 'Pastor Ebele Uzo-Peters', '2026-09-30', 'midweek'],
    'SOUND THAT MOVES MOUNTAINS | PASTOR EBELE UZO-PETERS | MIDWEEK SERVICE | 9TH APRIL, 2025'
        => ['Sound That Moves Mountains', 'Pastor Ebele Uzo-Peters', '2025-04-09', 'midweek'],
    'Walking in Love | Pst. Ebele Uzo-Peters | 8/11/2026' => ['Walking in Love', 'Pastor Ebele Uzo-Peters', '2026-11-08', 'sunday'],
    'Walking in Love - Pst Ebele Uzo-Peters - Nov 8, 2026' => ['Walking in Love', 'Pastor Ebele Uzo-Peters', '2026-11-08', 'sunday'],
    'Faith That Works | Rev. Dr. John Okafor | Sunday 2nd Service | Sun, Nov 1, 2026'
        => ['Faith That Works', 'Rev. Dr. John Okafor', '2026-11-01', 'sunday_2'],
    'Grace Abounds | Sunday 1st Service | Pastor Ade Bello | 1st November 2026' => ['Grace Abounds', 'Pastor Ade Bello', '2026-11-01', 'sunday_1'],
    'Grace Abounds | Third Service | Pastor Ade Bello | 1st November 2026' => ['Grace Abounds', 'Pastor Ade Bello', '2026-11-01', 'sunday_3'],
    'Crossover Night | Thanksgiving Service | Pastor Ade Bello | 31st December 2026' => ['Crossover Night', 'Pastor Ade Bello', '2026-12-31', 'special'],
    'Night Vigil | Pastor Ade Bello | 4th December 2026' => ['Night Vigil', 'Pastor Ade Bello', '2026-12-04', null],
    'Power Of Prayer | Evangelist Grace Udo | 2026-10-14' => ['Power Of Prayer', 'Evangelist Grace Udo', '2026-10-14', 'midweek'],
    'Holy Spirit | Minister Tola Ade | Saturday 10th October, 2026' => ['Holy Spirit', 'Minister Tola Ade', '2026-10-10', null],
    'Unshakeable | 8th of November, 2026 | Pastor (Mrs) Ngozi Eze' => ['Unshakeable', 'Pastor (Mrs) Ngozi Eze', '2026-11-08', 'sunday'],
    'Pastor\'s Corner | 2026-10-04' => ['Pastor\'s Corner', null, '2026-10-04', 'sunday'],
    'The Name of Jesus' => ['The Name of Jesus', null, null, null],
    'Ground Zero &amp; Beyond | Pastor Ebele Uzo-Peters | November 8th, 2026' => ['Ground Zero & Beyond', 'Pastor Ebele Uzo-Peters', '2026-11-08', 'sunday'],
    'Rest | Bro. Femi Ola | 4.10.2026' => ['Rest', 'Brother Femi Ola', '2026-10-04', 'sunday'],
    'By Faith | Exousia | Dr Kemi Ade | Wednesday, 7 October 2026' => ['By Faith', 'Dr. Kemi Ade', '2026-10-07', 'midweek'],
    'Mid-week Service | Pastor Ebele Uzo-Peters | 14 Oct 2026 | Kingdom Keys' => ['Kingdom Keys', 'Pastor Ebele Uzo-Peters', '2026-10-14', 'midweek'],
    'PASTOR EBELE UZO-PETERS | THE LORD IS MY SHEPHERD | 25TH OCTOBER 2026' => ['The Lord Is My Shepherd', 'Pastor Ebele Uzo-Peters', '2026-10-25', 'sunday'],
    // The live Lekki feed, 13 Nov 2026 (anchor.fm/s/116f1a844), every title it had:
    'Fully Decided | Pastor Ebele UZo-Peters | 1st November 2026' => ['Fully Decided', 'Pastor Ebele Uzo-Peters', '2026-11-01', 'sunday'],
    'Understanding Divine Success | Pastor Ebele UZo-Peters | 11th October 2026'
        => ['Understanding Divine Success', 'Pastor Ebele Uzo-Peters', '2026-10-11', 'sunday'],
    'Empowered To Prosper | Pastor Ebele Uzo-Peters | Total Experience | 4th October 2026'
        => ['Empowered To Prosper', 'Pastor Ebele Uzo-Peters', '2026-10-04', 'sunday'],
    'Arise and Shine | Pastor Ebele Uzo-Peters | Total Experience | 28th September 2026'
        => ['Arise and Shine', 'Pastor Ebele Uzo-Peters', '2026-09-28', null],
    'The Testimony of Faith | Pastor Ebele Uzo-Peters | Total Experience | 13th September 2026'
        => ['The Testimony of Faith', 'Pastor Ebele Uzo-Peters', '2026-09-13', 'sunday'],
    'The Sequence of Faith | Pastor Ebele Uzo-Peters | Total Experience | 6th September 2026'
        => ['The Sequence of Faith', 'Pastor Ebele Uzo-Peters', '2026-09-06', 'sunday'],
];
foreach ($cases as $raw => [$title, $preacher, $date, $service]) {
    $p = our_sermons_parse_title($raw);
    eq('parse: ' . mb_strimwidth($raw, 0, 60, '…'), [$title, $preacher, $date, $service], [$p['title'], $p['preacher'], $p['date'], $p['service']]);
}
eq('"Total Experience" (a programme, not a preacher or a date) is the series guess', 'Total Experience',
    our_sermons_parse_title('Empowered To Prosper | Pastor Ebele Uzo-Peters | Total Experience | 4th October 2026')['series']);
eq('one preacher however the feed capitalises them', [our_sermons_parse_title('A | Pastor Ebele UZo-Peters | 1st November 2026')['preacher']],
    array_values(array_unique([our_sermons_parse_title('A | Pastor Ebele UZo-Peters | 1st November 2026')['preacher'],
        our_sermons_parse_title('B | PASTOR EBELE UZO-PETERS | 1st November 2026')['preacher'],
        our_sermons_parse_title('C | Pastor Ebele Uzo-Peters | 1st November 2026')['preacher']])));
eq('a second plain part is a series guess', 'Ground Zero', our_sermons_parse_title('The Faith Once Delivered | Ground Zero | Pastor Ebele Uzo-Peters | 8 Nov 2026')['series']);
eq('no impossible dates (31 September)', null, our_sermons_parse_title('Hope | Pastor A Bee | 31st September 2026')['date']);
eq('no dates out of range', null, our_sermons_find_date('Psalm 1990-2200 in 3000'));
eq('a date anywhere in a part, and what is left', ['date' => '2026-11-08', 'rest' => 'Sunday Service'],
    our_sermons_find_date('Sunday Service November 8th 2026'));
eq('a two-digit year', '2026-11-08', our_sermons_find_date('8/11/26')['date'] ?? null);
eq('day first in numbers (Nigeria)', '2026-03-04', our_sermons_find_date('4/3/2026')['date'] ?? null);
eq('names: capitals and odd capitals tidied', 'Ebele Uzo-Peters', our_sermons_name_case('EBELE UZo-Peters'));
eq('names: McDonald and O\'Neil kept', "Mary McDonald O'Neil", our_sermons_name_case("Mary McDonald O'Neil"));
eq('names: lower case lifted', 'Ade Bello-Coker', our_sermons_name_case('ade bello-coker'));
eq('titles: capitals to Title Case, small words kept small', 'Sound That Moves Mountains of the Earth', our_sermons_title_case('SOUND THAT MOVES MOUNTAINS OF THE EARTH'));
eq('titles: mixed case left as typed', 'iPhone and the Gospel', our_sermons_title_case('iPhone and the Gospel'));
eq('a sentence that starts with Pastor is not a preacher', null, our_sermons_preacher_of('Pastor and the flock gather'));
eq('services: Exousia is midweek', 'midweek', our_sermons_service_of('Exousia'));
eq('services: a plain Sunday', 'sunday', our_sermons_service_of('Sunday Service'));
eq('services: a long sentence is not a service', null, our_sermons_service_of('The service of the Lord is our joy forever and ever'));
eq('the Sunday on or before (published on a Wednesday)', '2026-11-08', our_sermons_guess_sunday('2026-11-11 09:00:00'));
eq('the Sunday on or before (a Sunday)', '2026-11-08', our_sermons_guess_sunday('2026-11-08'));
eq('matching folds case, dashes, accents and punctuation',
    our_sermons_match_key('Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026'),
    our_sermons_match_key('GROUND ZERO – THE FAITH ONCE DELIVERED  |  PASTOR EBELE UZO‑PETERS | NOVEMBER 8TH 2026'));

// ===========================================================================
section('Links, durations, audio hosts');
// ===========================================================================
$ep = '04zNnoxeIDwt0Hy2zjmPWa';
foreach (['https://open.spotify.com/episode/' . $ep . '?si=abc123', 'open.spotify.com/episode/' . $ep,
             'https://open.spotify.com/intl-fr/episode/' . $ep, 'https://open.spotify.com/embed/episode/' . $ep, 'spotify:episode:' . $ep] as $u) {
    eq('Spotify: ' . $u, ['provider' => 'spotify', 'id' => $ep, 'url' => 'https://open.spotify.com/episode/' . $ep], our_sermons_parse_link($u));
}
foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30', 'https://youtu.be/dQw4w9WgXcQ', 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
             'https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'https://www.youtube.com/shorts/dQw4w9WgXcQ'] as $u) {
    eq('YouTube: ' . $u, 'dQw4w9WgXcQ', our_sermons_parse_link($u)['id'] ?? null);
}
foreach (['https://open.spotify.com/show/04M65RCkKwJZFlxeGZhjgV', 'https://open.spotify.com/track/' . $ep, 'https://evil.example/episode/' . $ep,
             'https://www.youtube.com/watch?v=short', 'javascript:alert(1)', '', 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'] as $u) {
    eq('refused: ' . ($u === '' ? '(empty)' : $u), null, our_sermons_parse_link($u));
}
eq('durations', [3723, 3723, 3723, 3724, 610, null, null, null],
    [our_sermons_duration('1:02:03'), our_sermons_duration('62:03'), our_sermons_duration('3723'), our_sermons_duration('3723.5'),
     our_sermons_duration('10:10'), our_sermons_duration(''), our_sermons_duration('soon'), our_sermons_duration('0')]);
eq('lengths', ['52 min', '1 h 05 min', ''], [our_sermons_length_label(3130), our_sermons_length_label(3900), our_sermons_length_label(null)]);
eq('audio hosts: the feed host and the host its tracking prefix forwards to', ['https://anchor.fm', 'https://d3ctxlq1ktw2nl.cloudfront.net'],
    our_sermons_media_hosts('https://anchor.fm/s/abc12345/podcast/play/98765/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-11-11%2Fep.mp3'));
eq('audio hosts: a plain file', ['https://mcdn.podbean.com'], our_sermons_media_hosts('https://mcdn.podbean.com/mf/web/abc/ep.mp3'));
eq('audio hosts: none', [], our_sermons_media_hosts(null));

// ===========================================================================
section('The RSS reader is hardened');
// ===========================================================================
$xml = os_feed([
    ['title' => 'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026', 'guid' => 'g-1', 'pub' => 'Wed, 11 Nov 2026 08:00:00 GMT',
        'image' => 'https://d3t3ozftmdmh3i.cloudfront.net/ep1.jpg'],
    ['title' => 'A video', 'guid' => 'g-v', 'audio' => 'https://x.example/v.mp4', 'type' => 'video/mp4'],
    ['title' => 'Old http', 'guid' => 'g-h', 'audio' => 'http://x.example/a.mp3'],
    ['title' => 'Long guid', 'guid' => str_repeat('x', 300)],
]);
$feed = our_sermons_feed_parse($xml);
ok('a podcast feed reads', $feed['ok'] && $feed['title'] === 'Household Of David Lekki Centre' && count($feed['items']) === 4);
$first = $feed['items'][0];
eq('an episode: guid, title, publish time in Lagos, length, artwork', ['g-1', '2026-11-11 09:00:00', 3130, 'https://d3t3ozftmdmh3i.cloudfront.net/ep1.jpg'],
    [$first['guid'], $first['published_at'], $first['duration'], $first['image']]);
ok('its audio is the full MP3 behind the tracking prefix', str_starts_with((string) $first['audio_url'], 'https://anchor.fm/s/abc12345/podcast/play/'));
eq('a video enclosure is not audio; an http one is refused', [null, null], [$feed['items'][1]['audio_url'], $feed['items'][2]['audio_url']]);
eq('an episode without its own artwork takes the show\'s', 'https://d3t3ozftmdmh3i.cloudfront.net/show.jpg', $feed['items'][1]['image']);
eq('a guid too long for the column is hashed', 'sha1:' . sha1(str_repeat('x', 300)), $feed['items'][3]['guid']);
eq('a DOCTYPE is refused before parsing', false, our_sermons_feed_parse('<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><title>&x;</title></channel></rss>')['ok']);
eq('an ENTITY anywhere is refused', false, our_sermons_feed_parse('<rss><channel><!ENTITY x "y"><title>t</title></channel></rss>')['ok']);
eq('a web page is not a feed', 'That link is not a podcast feed.', our_sermons_feed_parse('<html><body>hello</body></html>')['error']);
eq('nothing is not a feed', false, our_sermons_feed_parse('')['ok']);

// ===========================================================================
section('The fetcher: https only, public addresses, redirects re-checked, size caps');
// ===========================================================================
eq('http is refused', 'Use an https link.', our_sermons_http_get('http://example.org/feed')['error']);
eq('another port is refused', 'Use an https link.', our_sermons_http_get('https://example.org:8443/feed')['error']);
eq('credentials in the link are refused', 'Use an https link.', our_sermons_http_get('https://a:b@example.org/feed')['error']);
eq('an allow-list holds', 'That address is not allowed here.', our_sermons_http_get('https://evil.example/x', ['hosts' => ['open.spotify.com']])['error']);
$GLOBALS['os_routes'] = [
    'https://example.org/feed' => ['status' => 301, 'headers' => ['Location' => 'https://cdn.example.org/feed.xml']],
    'https://cdn.example.org/feed.xml' => ['body' => 'OK'],
    'https://example.org/sneaky' => ['status' => 302, 'headers' => ['Location' => 'http://169.254.169.254/latest']],
    'https://open.spotify.com/hop' => ['status' => 302, 'headers' => ['Location' => 'https://evil.example/x']],
    'https://example.org/loop' => ['status' => 302, 'headers' => ['Location' => '/loop']],
    'https://example.org/big' => ['body' => str_repeat('a', 2048)],
];
$r = our_sermons_http_get('https://example.org/feed');
eq('a redirect is followed', ['ok' => true, 'body' => 'OK', 'url' => 'https://cdn.example.org/feed.xml'], ['ok' => $r['ok'], 'body' => $r['body'], 'url' => $r['url']]);
eq('a redirect to http (or anywhere private) is refused', 'Use an https link.', our_sermons_http_get('https://example.org/sneaky')['error']);
eq('a redirect off the allow-list is refused', 'That address is not allowed here.', our_sermons_http_get('https://open.spotify.com/hop', ['hosts' => ['open.spotify.com']])['error']);
eq('redirect loops stop', 'That link redirects too many times.', our_sermons_http_get('https://example.org/loop')['error']);
eq('past the size cap is refused', 'That file is too big.', our_sermons_http_get('https://example.org/big', ['max_bytes' => 1024])['error']);
eq('a 404 says nothing was found', 'Nothing was found at that link.', our_sermons_http_get('https://example.org/missing')['error']);
eq('private, loopback, link-local and reserved addresses are refused', [null, null, null, null, null, '8.8.8.8'],
    array_map('our_sermons_public_ip', ['127.0.0.1', '10.1.2.3', '192.168.0.10', '169.254.169.254', '0.0.0.0', '8.8.8.8']));
eq('a host name that is not one is refused', null, our_sermons_public_ip('bad_host!'));
$curlSrc = (string) file_get_contents($root . '/includes/our_sermons/fetch.php');
ok('the real transport is pinned to the checked address, https only, no automatic redirects',
    str_contains($curlSrc, 'CURLOPT_RESOLVE') && str_contains($curlSrc, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS')
    && str_contains($curlSrc, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($curlSrc, 'CURLOPT_WRITEFUNCTION'));
$GLOBALS['os_routes']['https://img.example.org/a.png'] = ['body' => $png];
$GLOBALS['os_routes']['https://img.example.org/fake.png'] = ['body' => '<?php echo 1; ?>'];
$GLOBALS['os_routes']['https://img.example.org/big.png'] = ['body' => os_png(900, 900)];
$stored = our_sermons_store_artwork('https://img.example.org/a.png', null, $artDir);
ok('artwork: a real picture is copied under a random name', (bool) preg_match('#^/uploads/sermons/artwork/\d{8}_[0-9a-f]{16}\.png$#', (string) $stored));
eq('artwork: something that is not a picture is not stored', null, our_sermons_store_artwork('https://img.example.org/fake.png', null, $artDir));
eq('artwork: an oEmbed thumbnail only from the provider\'s image hosts', null, our_sermons_store_artwork('https://img.example.org/a.png', OUR_SERMONS_THUMB_HOSTS, $artDir));
if (function_exists('imagecreatetruecolor')) {
    $bigUrl = (string) our_sermons_store_artwork('https://img.example.org/big.png', null, $artDir);
    $size = @getimagesize($artDir . '/' . basename($bigUrl));
    eq('artwork: with GD a big picture is shrunk to 640 px, as JPEG', [true, 640, IMAGETYPE_JPEG],
        [str_ends_with($bigUrl, '.jpg'), $size[0] ?? null, $size[2] ?? null]);
}
our_sermons_artwork_delete('/uploads/sermons/artwork/../../.env', $artDir);
our_sermons_artwork_delete($stored, $artDir);
eq('artwork: only our own names are ever deleted', false, is_file($artDir . '/' . basename((string) $stored)));

// ===========================================================================
section('Access: the our_sermons row of the matrix');
// ===========================================================================
$can = static function (int $uid, string $level) use ($pdo): bool {
    os_as($uid);
    return can('our_sermons', $level, 'church');
};
eq('a member views and does nothing else', [true, false, false, false, false],
    [$can(4, 'view'), $can(4, 'create'), $can(4, 'edit'), $can(4, 'delete'), $can(4, 'manage')]);
eq('an Envision seat does everything', [true, true, true, true, true],
    [$can(3, 'view'), $can(3, 'create'), $can(3, 'edit'), $can(3, 'delete'), $can(3, 'manage')]);
eq('the Resident Pastor does everything', [true, true, true, true], [$can(2, 'create'), $can(2, 'edit'), $can(2, 'delete'), $can(2, 'manage')]);
eq('a seat in another department only views', [true, false], [$can(8, 'view'), $can(8, 'create')]);
ok('the menu shows Our sermons to everyone', perm_ctx_nav_visible(perm_resolve($pdo, 4), 'our_sermons') && perm_ctx_nav_visible(perm_resolve($pdo, 8), 'our_sermons'));
eq('the people told about drafts: Edit holders, not the Super Admin', [2, 3], perm_holders($pdo, 'our_sermons', 'edit'));
eq('a member is refused every action', ['FORBIDDEN', 'FORBIDDEN', 'FORBIDDEN', 'FORBIDDEN', 'FORBIDDEN'],
    array_map(static fn ($a) => act($pdo, 4, $a, ['id' => 1, 'link' => 'x', 'url' => 'x'])['code'] ?? '', ['add_link', 'save', 'publish', 'delete', 'feed_add']));
eq('an unknown action', 'UNKNOWN', act($pdo, 3, 'drop_tables')['code'] ?? '');
eq('before the migration: almost ready', 'FEATURE_NOT_READY', act(os_db(false), 3, 'add_link', ['link' => 'x'])['code'] ?? '');

// ===========================================================================
section('The feed: adding it reads it, and each new episode becomes a draft');
// ===========================================================================
$feedUrl = 'https://anchor.fm/s/abc12345/podcast/rss';
$episodes = [
    ['title' => 'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026', 'guid' => 'ep-1', 'pub' => 'Wed, 11 Nov 2026 08:00:00 GMT'],
    ['title' => 'The Blood | Midweek Service | Pastor Ebele Uzo-Peters | Exousia | 30th September 2026', 'guid' => 'ep-2', 'pub' => 'Fri, 02 Oct 2026 08:00:00 GMT'],
    ['title' => 'Untitled Thanksgiving', 'guid' => 'ep-3', 'pub' => 'Tue, 03 Nov 2026 08:00:00 GMT', 'dur' => '1:05:00'],
    ['title' => 'No audio here', 'guid' => 'ep-x', 'audio' => null],
];
$GLOBALS['os_routes'] = [
    $feedUrl => static fn () => ['body' => os_feed($GLOBALS['os_episodes']), 'headers' => ['ETag' => '"v1"']],
    'https://d3t3ozftmdmh3i.cloudfront.net/*' => ['body' => $png],
];
$GLOBALS['os_episodes'] = $episodes;
eq('a page is not a feed', 'FEED', act($pdo, 3, 'feed_add', ['url' => 'https://example.org/not-a-feed'])['code'] ?? '');
eq('http is refused', 'url', act($pdo, 3, 'feed_add', ['url' => 'http://anchor.fm/x'])['field'] ?? '');
$GLOBALS['os_log'] = [];
$add = act($pdo, 3, 'feed_add', ['url' => $feedUrl, 'import_from' => '']);
eq('added and read at once: 3 drafts (the episode without audio is left)', ['success', 4, 3], [$add['status'], $add['episodes'] ?? null, $add['new'] ?? null]);
eq('the same feed twice is refused', 'DUPLICATE', act($pdo, 3, 'feed_add', ['url' => $feedUrl])['code'] ?? '');
$drafts = $pdo->query("SELECT * FROM our_sermons ORDER BY id")->fetchAll();
$byGuid = array_column($drafts, null, 'feed_guid');
$g1 = $byGuid['ep-1'];
eq('a draft from the feed: parsed title, preacher, date PREACHED, service', ['Ground Zero - The Faith Once Delivered', 'Pastor Ebele Uzo-Peters',
    '2026-11-08', 'title', 'sunday', 'feed', 'draft'], [$g1['title'], $g1['preacher'], $g1['date_preached'], $g1['date_source'], $g1['service'], $g1['source'], $g1['status']]);
eq('…its publish time is kept apart, never as the date preached', '2026-11-11 09:00:00', $g1['feed_published_at']);
eq('…with the full audio, the length and the original title', [3130, true, 'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026'],
    [(int) $g1['duration_seconds'], str_starts_with((string) $g1['audio_url'], 'https://anchor.fm/'), $g1['feed_title']]);
ok('…and our own copy of the artwork, under a random name', (bool) preg_match('#^/uploads/sermons/artwork/\d{8}_[0-9a-f]{16}\.png$#', (string) $g1['artwork_url'])
    && is_file($artDir . '/' . basename((string) $g1['artwork_url'])));
eq('episodes sharing the show\'s artwork share one copy (downloaded once)', [1, 1],
    [count(array_unique(array_column($drafts, 'artwork_url'))), count(array_filter($GLOBALS['os_log'], static fn ($l) => str_contains($l['url'], 'show.jpg')))]);
$g3 = $byGuid['ep-3'];
eq('no date in the title: the Sunday before it was published, flagged as a guess', ['2026-11-01', 'guess', 3900],
    [$g3['date_preached'], $g3['date_source'], (int) $g3['duration_seconds']]);
// S3 (includes/our_sermons/ai.php, loaded by the bootstrap) queues every new draft; without it they are 'off'.
eq('AI: every new draft pending with S3 there, off without it', [function_exists('our_sermons_ai_queue') ? 'pending' : 'off'],
    array_values(array_unique(array_column($drafts, 'ai_status'))));
$notes = $pdo->query('SELECT user_id, title, message, link_url FROM system_notifications ORDER BY user_id')->fetchAll();
eq('Envision and the pastor are told once ("3 new sermons to check"), nobody else', [2, 3], array_map('intval', array_column($notes, 'user_id')));
eq('…pointing at the drafts', ['3 new sermons to check', '/modules/our_sermons/manage'], [$notes[0]['title'], $notes[0]['link_url']]);
$feedRow = $pdo->query('SELECT * FROM our_sermon_feeds')->fetch();
eq('the feed keeps its title, size, etag and when it was read', ['Household Of David Lekki Centre', 4, '"v1"', '2026-11-13 10:00:00', null],
    [$feedRow['title'], (int) $feedRow['items_seen'], $feedRow['etag'], $feedRow['last_ok_at'], $feedRow['last_error']]);

section('The live Lekki feed, replayed (UUID guids, CDATA, the anchor.fm play link, 13 Nov 2026)');
$lekki = [
    ['6da0b462-b3ff-4dc1-b2f1-5cb2cba86dcc', 'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026', 'Wed, 11 Nov 2026 16:15:53 GMT', '00:28:17'],
    ['caaae769-a49a-45e2-9a8d-8e9ff509cc68', 'Fully Decided | Pastor Ebele UZo-Peters | 1st November 2026', 'Thu, 05 Nov 2026 13:58:43 GMT', '00:39:45'],
    ['a404c4b4-b235-4707-bf6d-cee143afbe61', 'Understanding Divine Success | Pastor Ebele UZo-Peters | 11th October 2026', 'Tue, 13 Oct 2026 09:26:07 GMT', '00:27:39'],
    ['ea560ced-e225-48b2-aec1-d4fb51ab7522', 'Empowered To Prosper | Pastor Ebele Uzo-Peters | Total Experience | 4th October 2026', 'Sat, 10 Oct 2026 10:05:23 GMT', '00:43:06'],
    ['200375b9-1cbf-46e6-8dc4-3bec33c072b9', 'Arise and Shine | Pastor Ebele Uzo-Peters | Total Experience | 28th September 2026', 'Mon, 28 Sep 2026 21:21:01 GMT', '00:39:28'],
    ['34efcfd0-3160-4fa5-8e81-a889f1cd0d26', 'The Testimony of Faith | Pastor Ebele Uzo-Peters | Total Experience | 13th September 2026', 'Mon, 14 Sep 2026 19:18:51 GMT', '00:35:53'],
    ['c3da7c92-ddcd-47a7-9ecf-8671fa45a402', 'The Sequence of Faith | Pastor Ebele Uzo-Peters | Total Experience | 6th September 2026', 'Mon, 07 Sep 2026 04:01:40 GMT', '00:42:53'],
];
$liveUrl = 'https://anchor.fm/s/116f1a844/podcast/rss';
$GLOBALS['os_routes'][$liveUrl] = ['body' => os_feed(array_map(static fn ($e) => ['guid' => $e[0], 'title' => $e[1], 'pub' => $e[2], 'dur' => $e[3],
    'audio' => 'https://anchor.fm/s/116f1a844/podcast/play/' . crc32($e[0]) . '/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-11-11%2F' . $e[0] . '.mp3',
    'image' => 'https://d3t3ozftmdmh3i.cloudfront.net/staging/podcast_uploaded_nologo/46699033/show.jpg'], $lekki)), 'headers' => ['ETag' => 'W/"29ee"']];
$pl = os_db();
$pl->exec(perm_test_migration('20261213090200_our_sermons_feed_seed.sql'));
eq('the seed sets the Lekki feed once, unread', [1, $liveUrl, null], [(int) $pl->query('SELECT COUNT(*) FROM our_sermon_feeds')->fetchColumn(),
    $pl->query('SELECT url FROM our_sermon_feeds')->fetchColumn(), $pl->query('SELECT last_checked_at FROM our_sermon_feeds')->fetchColumn()]);
$pl->exec(perm_test_migration('20261213090200_our_sermons_feed_seed.sql'));
eq('…runs twice as a no-op', 1, (int) $pl->query('SELECT COUNT(*) FROM our_sermon_feeds')->fetchColumn());
$run = our_sermons_feeds_run($pl);
$rows = $pl->query('SELECT * FROM our_sermons ORDER BY date_preached DESC')->fetchAll();
eq('the first tick after the deploy brings all 7 in as drafts', [1, 7, 7], [$run['checked'], $run['new'], count($rows)]);
eq('…by the day each was preached', ['2026-11-08', '2026-11-01', '2026-10-11', '2026-10-04', '2026-09-28', '2026-09-13', '2026-09-06'],
    array_column($rows, 'date_preached'));
eq('…one preacher, "Total Experience" as the series guess, lengths from itunes:duration',
    [['Pastor Ebele Uzo-Peters'], [null, null, null, 'Total Experience', 'Total Experience', 'Total Experience', 'Total Experience'], 1697],
    [array_values(array_unique(array_column($rows, 'preacher'))), array_column($rows, 'series'), (int) $rows[0]['duration_seconds']]);
eq('…the newest is "Ground Zero", published three days after it was preached', ['Ground Zero - The Faith Once Delivered', '2026-11-11 17:15:53'],
    [$rows[0]['title'], $rows[0]['feed_published_at']]);
eq('…its audio plays from both hosts the redirect touches', ['https://anchor.fm', 'https://d3ctxlq1ktw2nl.cloudfront.net'],
    our_sermons_media_hosts($rows[0]['audio_url']));
$sp = os_db();
$sp->exec("INSERT INTO our_sermon_feeds (url, is_active, items_seen, created_at) VALUES ('https://example.org/other.xml', 1, 0, '2026-11-13 09:00:00')");
$sp->exec(perm_test_migration('20261213090200_our_sermons_feed_seed.sql'));
eq('the seed never adds to a feed Envision already set', ['https://example.org/other.xml'], $sp->query('SELECT url FROM our_sermon_feeds')->fetchAll(PDO::FETCH_COLUMN));

section('The poller: every 15 minutes, deduped by guid, conditional GET');
$pdo->exec('DELETE FROM system_notifications');
$run = our_sermons_feeds_run($pdo);
eq('read a moment ago: not read again', ['checked' => 0, 'new' => 0, 'linked' => 0, 'errors' => 0], $run);
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-13 10:15:00', new DateTimeZone('Africa/Lagos'));
$GLOBALS['os_log'] = [];
$GLOBALS['os_routes'][$feedUrl] = static fn ($u, $o) => in_array('If-None-Match: "v1"', $o['headers'] ?? [], true)
    ? ['status' => 304] : ['body' => os_feed($GLOBALS['os_episodes']), 'headers' => ['ETag' => '"v2"']];
$run = our_sermons_feeds_run($pdo);
eq('15 minutes later it is read, with its etag, and nothing has changed', [1, 0], [$run['checked'], $run['new']]);
eq('…the request carried If-None-Match', true, in_array('If-None-Match: "v1"', $GLOBALS['os_log'][0]['headers'] ?? [], true));
$GLOBALS['os_episodes'][] = ['title' => 'Kingdom Keys | Pastor Ebele Uzo-Peters | 15th November 2026', 'guid' => 'ep-4', 'pub' => 'Mon, 16 Nov 2026 08:00:00 GMT'];
$GLOBALS['os_routes'][$feedUrl] = static fn () => ['body' => os_feed($GLOBALS['os_episodes']), 'headers' => ['ETag' => '"v3"']];
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Africa/Lagos'));
$run = our_sermons_feeds_run($pdo);
eq('a new episode: one new draft, the old ones not repeated', [1, 4], [$run['new'], (int) $pdo->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn()]);
eq('…and one notice each: "New sermon to check" opening it', ['New sermon to check', 'Kingdom Keys', true],
    [$pdo->query('SELECT title FROM system_notifications LIMIT 1')->fetchColumn(), $pdo->query('SELECT message FROM system_notifications LIMIT 1')->fetchColumn(),
     str_starts_with((string) $pdo->query('SELECT link_url FROM system_notifications LIMIT 1')->fetchColumn(), '/modules/our_sermons/manage?id=')]);
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-16 10:20:00', new DateTimeZone('Africa/Lagos'));
eq('two runs at once: the UNIQUE guid keeps one draft per episode', 4, (function () use ($pdo) {
    our_sermons_create($pdo, ['title' => 'dup', 'feed_guid' => 'ep-4', 'source' => 'feed', 'date_preached' => '2026-11-15'], null);
    return (int) $pdo->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn();
})());
eq('…and says which sermon holds it', ['id' => (int) $pdo->query("SELECT id FROM our_sermons WHERE feed_guid = 'ep-4'")->fetchColumn(), 'existing' => true],
    our_sermons_create($pdo, ['title' => 'dup', 'feed_guid' => 'ep-4'], null));
eq('a dry run reads nothing', ['checked' => 0, 'new' => 0, 'linked' => 0, 'errors' => 0], our_sermons_feeds_run($pdo, ['dry_run' => true]));

section('The poller: a broken feed, the cap, "bring in from"');
$GLOBALS['os_routes'][$feedUrl] = ['status' => 500];
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-16 11:00:00', new DateTimeZone('Africa/Lagos'));
$run = our_sermons_feeds_run($pdo);
eq('a broken feed is recorded on the Feed tab, nothing else breaks', [1, 'That site answered with an error (500).'],
    [$run['errors'], $pdo->query('SELECT last_error FROM our_sermon_feeds')->fetchColumn()]);
$many = [];
for ($i = 1; $i <= 25; $i++) {
    $many[] = ['title' => "Episode {$i} | Pastor Ade Bello | " . (new DateTimeImmutable('2026-01-04'))->modify('+' . ($i * 7) . ' days')->format('jS F Y'),
        'guid' => "many-{$i}", 'pub' => (new DateTimeImmutable('2026-01-05 08:00:00'))->modify('+' . ($i * 7) . ' days')->format(DATE_RSS)];
}
$p2 = os_db();
$GLOBALS['os_routes']['https://feeds.example.org/many.xml'] = static fn () => ['body' => os_feed($GLOBALS['os_many']), 'headers' => ['ETag' => '"m"']];
$GLOBALS['os_many'] = $many;
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-16 12:00:00', new DateTimeZone('Africa/Lagos'));
$add = act($p2, 3, 'feed_add', ['url' => 'https://feeds.example.org/many.xml']);
eq('at most 20 drafts per read, newest first; the etag waits until all are in', [20, null, 'Episode 25'],
    [$add['new'] ?? null, $p2->query('SELECT etag FROM our_sermon_feeds')->fetchColumn(), $p2->query('SELECT title FROM our_sermons ORDER BY id LIMIT 1')->fetchColumn()]);
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-16 12:15:00', new DateTimeZone('Africa/Lagos'));
$run = our_sermons_feeds_run($p2);
eq('the next read brings the other 5, then keeps the etag', [5, 25, '"m"'],
    [$run['new'], (int) $p2->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn(), $p2->query('SELECT etag FROM our_sermon_feeds')->fetchColumn()]);
$p3 = os_db();
$add = act($p3, 3, 'feed_add', ['url' => 'https://feeds.example.org/many.xml', 'import_from' => '2026-06-01']);
$expected = count(array_filter($many, static fn ($m) => (new DateTimeImmutable($m['pub']))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d') >= '2026-06-01'));
eq('"bring in from" leaves older episodes out', $expected, (int) $p3->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn());
eq('a bad date there is refused', 'import_from', act($p3, 3, 'feed_add', ['url' => 'https://x.example/f', 'import_from' => '31/12'])['field'] ?? '');
eq('Remove feed keeps its sermons', [0, $expected], (function () use ($p3) {
    act($p3, 3, 'feed_remove', ['id' => (int) $p3->query('SELECT id FROM our_sermon_feeds')->fetchColumn()]);
    return [(int) $p3->query('SELECT COUNT(*) FROM our_sermon_feeds')->fetchColumn(), (int) $p3->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn()];
})());

// ===========================================================================
section('Paste link: Spotify (with its feed episode) and YouTube');
// ===========================================================================
$GLOBALS['os_routes'][$feedUrl] = static fn () => ['body' => os_feed($GLOBALS['os_episodes'])];
$spotify = [
    // episode id => title Spotify gives
    '04zNnoxeIDwt0Hy2zjmPWa' => 'Ground Zero - The Faith Once Delivered | Pastor Ebele UZo-Peters | November 8th, 2026',   // already a feed draft
    '1111111111111111111111' => 'Fresh Fire | Pastor Ebele Uzo-Peters | 22nd November 2026',                             // not in the feed yet
    '2222222222222222222222' => 'Kingdom Keys | Pastor Ebele Uzo-Peters | 15th November 2026',                           // in the feed (ep-4)
];
$GLOBALS['os_spotify'] = $spotify;
$GLOBALS['os_routes']['https://open.spotify.com/oembed?url=*'] = static function (string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $id = basename((string) ($q['url'] ?? ''));
    return isset($GLOBALS['os_spotify'][$id])
        ? ['body' => json_encode(['title' => $GLOBALS['os_spotify'][$id], 'thumbnail_url' => 'https://i.scdn.co/image/' . $id, 'type' => 'rich'])]
        : ['status' => 404];
};
$GLOBALS['os_routes']['https://i.scdn.co/image/*'] = ['body' => $png];
$GLOBALS['os_routes']['https://www.youtube.com/oembed?format=json&url=*'] = static fn () => ['body' => json_encode([
    'title' => 'Fresh Fire (Live) | Pastor Ebele Uzo-Peters', 'author_name' => 'HOD Lekki', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'])];
$GLOBALS['os_routes']['https://i.ytimg.com/*'] = ['body' => $png];
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-23 09:00:00', new DateTimeZone('Africa/Lagos'));

eq('not a Spotify or YouTube link', ['INVALID', 'link'], (function () use ($pdo) {
    $r = act($pdo, 3, 'add_link', ['link' => 'https://example.org/sermon.mp3']);
    return [$r['code'] ?? '', $r['field'] ?? ''];
})());
eq('an episode Spotify does not know', 'Spotify could not find that episode.', act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/3333333333333333333333'])['message'] ?? '');
$r = act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa?si=x']);
eq('an episode the feed already brought in: that draft opens, with the Spotify link added', [true, (int) $g1['id'], 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa'],
    [$r['existing'] ?? null, $r['id'] ?? null, our_sermons_get($pdo, (int) $g1['id'])['spotify_url']]);
$r = act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa']);
eq('the same link again: the same sermon', [true, (int) $g1['id']], [$r['existing'] ?? null, $r['id'] ?? null]);
$r = act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/1111111111111111111111']);
$fresh = our_sermons_get($pdo, (int) $r['id']);
eq('an episode not in the feed yet: a pasted draft, filled from its title, artwork copied, no audio yet',
    [false, 'Fresh Fire', 'Pastor Ebele Uzo-Peters', '2026-11-22', 'title', 'paste', null, null, true],
    [$r['existing'], $fresh['title'], $fresh['preacher'], $fresh['date_preached'], $fresh['date_source'], $fresh['source'], $fresh['audio_url'],
     $fresh['feed_guid'], str_starts_with((string) $fresh['artwork_url'], '/uploads/sermons/artwork/')]);
$GLOBALS['os_episodes'][] = ['title' => 'Fresh Fire | Pastor Ebele Uzo-Peters | 22nd November 2026', 'guid' => 'ep-5', 'pub' => 'Mon, 23 Nov 2026 07:00:00 GMT'];
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-23 10:00:00', new DateTimeZone('Africa/Lagos'));
$before = (int) $pdo->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn();
$run = our_sermons_feeds_run($pdo);
$fresh = our_sermons_get($pdo, $fresh['id']);
eq('when the feed has it, the pasted draft gets the audio (no second draft)', [0, 1, $before, 'ep-5', true],
    [$run['new'], $run['linked'], (int) $pdo->query('SELECT COUNT(*) FROM our_sermons')->fetchColumn(), $fresh['feed_guid'], $fresh['audio_url'] !== null]);
$ep4 = (int) $pdo->query("SELECT id FROM our_sermons WHERE feed_guid = 'ep-4'")->fetchColumn();
$r = act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/2222222222222222222222']);
eq('an episode the feed has (matched by title): its draft, with the Spotify link', [true, $ep4, '2222222222222222222222'],
    [$r['existing'] ?? null, $r['id'] ?? null, our_sermons_get($pdo, $ep4)['spotify_episode_id']]);
$r = act($pdo, 3, 'add_link', ['link' => 'https://youtu.be/dQw4w9WgXcQ']);
$yt = our_sermons_get($pdo, (int) $r['id']);
eq('YouTube: a video draft from its title and thumbnail; no date in it, so the Sunday before is a guess',
    [false, 'Fresh Fire (Live)', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', '2026-11-22', 'guess', null],
    [$r['existing'], $yt['title'], $yt['youtube_id'], $yt['youtube_url'], $yt['date_preached'], $yt['date_source'], $yt['audio_url']]);
eq('the same video again: the same sermon', [true, $yt['id']], (function () use ($pdo) {
    $r = act($pdo, 3, 'add_link', ['link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
    return [$r['existing'] ?? null, $r['id'] ?? null];
})());
eq('a member cannot paste', 'FORBIDDEN', act($pdo, 4, 'add_link', ['link' => 'https://youtu.be/dQw4w9WgXcQ'])['code'] ?? '');

// ===========================================================================
section('Checking a draft: every field');
// ===========================================================================
$id = (int) $g1['id'];
$form = static fn (array $over = []): array => $over + ['id' => $id, 'title' => 'Ground Zero - The Faith Once Delivered', 'preacher' => 'Pastor Ebele Uzo-Peters',
    'date_preached' => '2026-11-08', 'service' => 'sunday', 'series' => 'Ground Zero', 'summary' => "Contend for the faith.\nHold fast.",
    'key_points' => "- Contend earnestly\n2. Know what you believe\n\n• Stay rooted", 'scriptures' => "Jude 3\nJn 3.16; 2 Tim 4:7", 'youtube_url' => '',
    'spotify_url' => 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa'];
foreach ([
    ['title', '', 'title'], ['date_preached', '', 'date_preached'], ['date_preached', '2026-02-30', 'date_preached'],
    ['date_preached', '2027-01-10', 'date_preached'], ['service', 'brunch', 'service'], ['scriptures', 'Hezekiah 4:2', 'scriptures'],
    ['youtube_url', 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa', 'youtube_url'],
    ['youtube_url', 'https://youtu.be/dQw4w9WgXcQ', 'youtube_url'],            // on the video draft already
    ['spotify_url', 'https://open.spotify.com/episode/1111111111111111111111', 'spotify_url'],
] as [$field, $value, $where]) {
    $r = act($pdo, 3, 'save', $form([$field => $value]));
    eq("refused: {$field} = " . ($value === '' ? '(empty)' : $value), ['error', $where], [$r['status'], $r['field'] ?? null]);
}
eq('saved', 'success', act($pdo, 3, 'save', $form())['status']);
$s = our_sermons_get($pdo, $id);
eq('…every field, the date now confirmed by hand', ['Ground Zero', 'sunday', 'manual', "Contend for the faith.\nHold fast."],
    [$s['series'], $s['service'], $s['date_source'], $s['summary']]);
eq('…key points one per line, bullets and numbers dropped', ['Contend earnestly', 'Know what you believe', 'Stay rooted'], $s['key_points']);
eq('…scriptures through the Bible module\'s parser', ['Jude 1:3', 'John 3:16', '2 Timothy 4:7'], $s['scriptures']);
eq('a member cannot save', 'FORBIDDEN', act($pdo, 4, 'save', $form())['code'] ?? '');
act($pdo, 3, 'save', $form(['preacher' => 'PASTOR EBELE UZo-Peters']));
eq('a typed preacher is tidied like a parsed one (one preacher, one filter)', 'Pastor Ebele Uzo-Peters', our_sermons_get($pdo, $id)['preacher']);
act($pdo, 3, 'save', $form());

// ===========================================================================
section('Publishing tells everyone who can sign in, once');
// ===========================================================================
$pdo->exec('DELETE FROM system_notifications');
$noMedia = our_sermons_create($pdo, ['title' => 'Nothing to hear', 'date_preached' => '2026-11-01'], 3)['id'];
eq('nothing to listen to or watch: not published', ['NO_MEDIA', 'draft'],
    [act($pdo, 3, 'publish', ['id' => $noMedia, 'title' => 'Nothing to hear', 'date_preached' => '2026-11-01'])['code'] ?? '',
     our_sermons_get($pdo, $noMedia)['status']]);
$r = act($pdo, 3, 'publish', $form());
$s = our_sermons_get($pdo, $id);
eq('published by Envision', ['success', 'published', 3, '2026-11-23 10:00:00'], [$r['status'], $s['status'], $s['published_by'], $s['published_at']]);
$told = $pdo->query('SELECT user_id, title, message, link_url FROM system_notifications ORDER BY user_id')->fetchAll();
eq('everyone with a sign-in, active and not relocated, is told (not the account without a password)', [1, 2, 3, 4, 8],
    array_map('intval', array_column($told, 'user_id')));
eq('"New sermon: <title>" · preacher and day, opening the sermon', ['New sermon: Ground Zero - The Faith Once Delivered',
    'Pastor Ebele Uzo-Peters · Sun 8 Nov 2026', '/modules/our_sermons/sermon?id=' . $id], [$told[0]['title'], $told[0]['message'], $told[0]['link_url']]);
eq('…the reply says how many', 5, $r['notified'] ?? null);
act($pdo, 3, 'publish', $form());
act($pdo, 3, 'hide', ['id' => $id]);
eq('hidden', 'hidden', our_sermons_get($pdo, $id)['status']);
act($pdo, 3, 'publish', $form());
eq('publishing again (also after Hide) tells nobody again', [5, 'published', '2026-11-23 10:00:00'],
    [(int) $pdo->query('SELECT COUNT(*) FROM system_notifications')->fetchColumn(), our_sermons_get($pdo, $id)['status'], our_sermons_get($pdo, $id)['published_at']]);
eq('two presses at once: the claim lets one through', 0, our_sermons_notify_everyone($pdo, $id));
$big = os_db();
$ins = $big->prepare("INSERT INTO users (id, first_name, password_hash, account_status, attendance_status) VALUES (?, 'P', 'h', 'active', 'Active')");
$big->beginTransaction();
for ($i = 100; $i < 100 + 450; $i++) {
    $ins->execute([$i]);
}
$big->commit();
foreach (os_schema_sql('20261213090000_our_sermons_core.sql') as $stmt) {
    try { $big->exec($stmt); } catch (Throwable $e) { /* already there */ }
}
$bid = our_sermons_create($big, ['title' => 'Batch', 'date_preached' => '2026-11-22', 'youtube_id' => 'abcdefghijk', 'youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk'], 3)['id'];
$big->exec("UPDATE our_sermons SET status = 'published' WHERE id = {$bid}");
eq('a big church is told in batches, one row each', [455, 455], [our_sermons_notify_everyone($big, $bid),
    (int) $big->query('SELECT COUNT(DISTINCT user_id) FROM system_notifications')->fetchColumn()]);
ok('the rows are written in batches of 200, inside the claim\'s transaction', str_contains((string) file_get_contents($root . '/includes/our_sermons/store.php'), 'array_chunk($audience, 200)'));

// ===========================================================================
section('The members\' list, filters and calendar');
// ===========================================================================
$mk = static function (string $title, string $date, string $preacher, ?string $series, ?string $service, string $status = 'published') use ($pdo): int {
    $id = our_sermons_create($pdo, ['title' => $title, 'date_preached' => $date, 'preacher' => $preacher, 'series' => $series, 'service' => $service,
        'audio_url' => 'https://anchor.fm/s/a/podcast/play/1/x.mp3'], 3)['id'];
    $pdo->prepare('UPDATE our_sermons SET status = ? WHERE id = ?')->execute([$status, $id]);
    return $id;
};
$mk('Joy Unspeakable', '2026-10-25', 'Pastor Ade Bello', 'Joy', 'sunday');
$mk('Joy in Trials', '2026-10-28', 'Pastor Ade Bello', 'Joy', 'midweek');
$mk('100% Sure', '2026-10-18', 'Evangelist Grace Udo', null, 'special');
$mk('Secret Draft', '2026-10-21', 'Pastor Ade Bello', 'Joy', 'midweek', 'draft');
$all = our_sermons_search($pdo, []);
eq('published only, newest preached first', ['Ground Zero - The Faith Once Delivered', 'Joy in Trials', 'Joy Unspeakable', '100% Sure'],
    array_column($all['rows'], 'title'));
eq('search the title, preacher or series', ['Joy in Trials', 'Joy Unspeakable'], array_column(our_sermons_search($pdo, ['q' => 'joy'])['rows'], 'title'));
eq('search by preacher', ['100% Sure'], array_column(our_sermons_search($pdo, ['q' => 'grace udo'])['rows'], 'title'));
eq('a % in the search is a percent sign, not a wildcard', ['100% Sure'], array_column(our_sermons_search($pdo, ['q' => '0%'])['rows'], 'title'));
eq('filter by preacher, series and service', [['Joy in Trials', 'Joy Unspeakable'], ['Joy in Trials', 'Joy Unspeakable'], ['Joy in Trials']],
    [array_column(our_sermons_search($pdo, ['preacher' => 'Pastor Ade Bello'])['rows'], 'title'),
     array_column(our_sermons_search($pdo, ['series' => 'Joy'])['rows'], 'title'),
     array_column(our_sermons_search($pdo, ['series' => 'Joy', 'service' => 'midweek'])['rows'], 'title')]);
eq('pages', [2, 4, ['Joy Unspeakable', '100% Sure']], (function () use ($pdo) {
    $p = our_sermons_search($pdo, [], 2, 2);
    return [count($p['rows']), $p['total'], array_column($p['rows'], 'title')];
})());
$opts = our_sermons_filter_options($pdo);
eq('the filters offer what published sermons use (never a draft\'s)', [['Evangelist Grace Udo', 'Pastor Ade Bello', 'Pastor Ebele Uzo-Peters'], ['Ground Zero', 'Joy'],
    ['sunday', 'midweek', 'special']], [$opts['preachers'], $opts['series'], $opts['services']]);
$oct = our_sermons_month($pdo, '2026-10');
eq('the calendar: October by the day preached (no drafts)', ['2026-10-18', '2026-10-25', '2026-10-28'], array_keys($oct));
eq('…a bad month is empty', [], our_sermons_month($pdo, '2026-13'));
eq('…filters apply to the calendar too', ['2026-10-28'], array_keys(our_sermons_month($pdo, '2026-10', ['service' => 'midweek'])));

// ===========================================================================
section('Delete');
// ===========================================================================
$shared = $pdo->query("SELECT artwork_url FROM our_sermons WHERE feed_guid = 'ep-2'")->fetchColumn();
$art = $artDir . '/' . basename((string) $shared);
$ep2 = (int) $pdo->query("SELECT id FROM our_sermons WHERE feed_guid = 'ep-2'")->fetchColumn();
eq('a member cannot delete', 'FORBIDDEN', act($pdo, 4, 'delete', ['id' => $ep2])['code'] ?? '');
eq('deleted; its artwork stays while another sermon shows it', ['success', true], [act($pdo, 3, 'delete', ['id' => $ep2])['status'], is_file($art)]);
foreach ($pdo->query('SELECT id FROM our_sermons WHERE artwork_url = ' . $pdo->quote((string) $shared))->fetchAll(PDO::FETCH_COLUMN) as $other) {
    act($pdo, 3, 'delete', ['id' => (int) $other]);
}
eq('…and goes with the last one', false, is_file($art));
eq('deleting again: not found', 'NOT_FOUND', act($pdo, 3, 'delete', ['id' => $ep2])['code'] ?? '');
eq('a deleted feed episode is remembered', true, (bool) $pdo->query("SELECT COUNT(*) FROM our_sermon_feed_skips WHERE feed_guid = 'ep-2'")->fetchColumn());
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-29 10:00:00', new DateTimeZone('Africa/Lagos'));
eq('…so the poller does not bring deleted episodes back', 0, our_sermons_feeds_run($pdo)['new']);
$GLOBALS['os_spotify']['5555555555555555555555'] = 'The Blood | Midweek Service | Pastor Ebele Uzo-Peters | Exousia | 30th September 2026';
$back = act($pdo, 3, 'add_link', ['link' => 'https://open.spotify.com/episode/5555555555555555555555']);
eq('…but Paste link can, with its audio from the feed', [false, 'ep-2', true],
    [$back['existing'] ?? null, our_sermons_get($pdo, (int) $back['id'])['feed_guid'], our_sermons_get($pdo, (int) $back['id'])['audio_url'] !== null]);
eq('…and deleting it again is fine', 'success', act($pdo, 3, 'delete', ['id' => (int) $back['id']])['status']);

// ===========================================================================
section('S3\'s hook: a new draft is queued for the AI when S3 is there');
// ===========================================================================
$s3 = function_exists('our_sermons_ai_queue');   // S3's real queue, once includes/our_sermons/ai.php is merged
if (!$s3) {
    function our_sermons_ai_queue(PDO $pdo, int $id): void
    {
        $GLOBALS['os_ai_calls'][] = $id;
    }
}
$GLOBALS['os_ai_calls'] = [];
$GLOBALS['os_episodes'][] = ['title' => 'After AI | Pastor Ade Bello | 29th November 2026', 'guid' => 'ep-6', 'pub' => 'Mon, 30 Nov 2026 07:00:00 GMT'];
$GLOBALS['our_sermons_now'] = new DateTimeImmutable('2026-11-30 10:00:00', new DateTimeZone('Africa/Lagos'));
our_sermons_feeds_run($pdo);
$ai = $pdo->query("SELECT id, ai_status FROM our_sermons WHERE feed_guid = 'ep-6'")->fetch();
if ($s3) {
    // tests/our_sermons/ai.php checks what S3's queue does; here, that the hook reaches it.
    eq('ai_status pending, through S3\'s own our_sermons_ai_queue() (includes/our_sermons/ai.php)', ['pending', 'ai.php'],
        [$ai['ai_status'], basename((string) (new ReflectionFunction('our_sermons_ai_queue'))->getFileName())]);
} else {
    eq('ai_status pending, and our_sermons_ai_queue($pdo, $id) called once', ['pending', [(int) $ai['id']]], [$ai['ai_status'], $GLOBALS['os_ai_calls']]);
}

// ===========================================================================
section('Wiring: the cron, the API, the pages, CI');
// ===========================================================================
$tick = (string) file_get_contents($root . '/cron/automation_tick.php');
ok('the poller rides on automation_tick.php behind function_exists(), in its own try (no new cron line)',
    (bool) preg_match("/if \\(function_exists\\('our_sermons_feeds_run'\\)\\) \\{\\s*try \\{\\s*\\\$os = our_sermons_feeds_run\\(\\\$pdo/", $tick));
ok('…and is on the campaigns suite\'s list of optional functions', str_contains((string) file_get_contents($root . '/tests/campaigns/run.php'), "'our_sermons_feeds_run'"));
$api = (string) file_get_contents($root . '/api/our_sermons_api.php');
ok('the API checks the session, the matrix and the CSRF token before any action',
    strpos($api, "empty(\$_SESSION['user_id'])") < strpos($api, "api_require_can('our_sermons'")
    && strpos($api, "api_require_can('our_sermons'") < strpos($api, 'csrf_require();') && strpos($api, 'csrf_require();') < strpos($api, 'our_sermons_action($pdo'));
foreach (['index', 'manage', 'sermon'] as $page) {
    $src = (string) file_get_contents($root . "/modules/our_sermons/{$page}.php");
    ok("{$page}: a strict page that asks can()", str_contains($src, "'mode' => 'strict'") && str_contains($src, "require_can('our_sermons'"));
}
$sermonPage = (string) file_get_contents($root . '/modules/our_sermons/sermon.php');
ok('the sermon page alone lets the audio\'s host into media-src', str_contains($sermonPage, "'media-src' => our_sermons_media_hosts(")
    && !str_contains((string) file_get_contents($root . '/modules/our_sermons/index.php'), 'media-src'));
ok('the sermon page plays the feed\'s audio and offers Spotify and YouTube', str_contains($sermonPage, '<audio class="os-audio" controls')
    && str_contains($sermonPage, "'Open in Spotify'") && str_contains($sermonPage, "'Watch on YouTube'"));
ok('the members\' view choice is remembered (cookie hod_os_view)', str_contains((string) file_get_contents($root . '/modules/our_sermons/index.php'), "setcookie('hod_os_view'"));
$nav = (string) file_get_contents($root . '/includes/ui/nav.php');
ok('the menu entry is there, with its (i) line', str_contains($nav, "'key' => 'our_sermons', 'label' => 'Our sermons'") && str_contains($nav, "'info' => 'Listen to recent sermons"));
$reg = require $root . '/includes/permissions/modules.php';
eq('the registry row: member-facing, up to Delete, plus Manage', [true, 'delete', ['manage']],
    [$reg['our_sermons']['member'] ?? false, $reg['our_sermons']['max'] ?? null, $reg['our_sermons']['extras'] ?? null]);
$boot = (string) file_get_contents($root . '/includes/our_sermons/bootstrap.php');
ok('S3\'s ai.php is loaded when it is there (our_sermons_ai_queue, our_sermons_ai_panel)',
    str_contains($boot, "if (is_file(__DIR__ . '/ai.php')) {") && str_contains($boot, "require_once __DIR__ . '/ai.php';"));
$manageSrc = (string) file_get_contents($root . '/modules/our_sermons/manage.php');
$panelAt = strpos($manageSrc, 'our_sermons_ai_panel($pdo, $s,');
ok('S3\'s panel (its own Regenerate form) sits on the editor page, outside the editor\'s form',
    $panelAt !== false && $panelAt < strpos($manageSrc, '<form class="ui-card" data-action="os.save"')
    && str_contains($manageSrc, "if (function_exists('our_sermons_ai_panel')):"));
ok('S2\'s notes and progress tables are named only in S2\'s files', !preg_match('/our_sermon_(notes|progress)\b/', implode('', array_map('file_get_contents',
    array_merge(glob($root . '/includes/our_sermons/*.php') ?: [], glob($root . '/modules/our_sermons/manage.php') ?: [], glob($root . '/db/migrations/2026121309*') ?: [],
        [$root . '/api/our_sermons_api.php', __FILE__])))));
ok('CI runs this suite', str_contains((string) file_get_contents($root . '/.github/workflows/deploy.yml'), 'php tests/our_sermons/run.php'));
ok('the integration job applies the migration on MySQL and MariaDB (marker)', str_contains((string) file_get_contents($root . '/tests/special_events/db_setup.php'), "'_our_sermons_'"));

foreach (glob($artDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($artDir);
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
