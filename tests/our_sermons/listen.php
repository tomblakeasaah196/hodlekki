<?php
// /tests/our_sermons/listen.php
//
// Our sermons, PR S2 (the listening page), on SQLite built from the REAL
// migrations (this PR's notes and progress, S1's our_sermons table once it is
// in the tree, the email schema and the permission matrix): the table
// contract, cleaning what the page shows, the media-src hosts, scripture
// references in free text, notes (private to their writer), "carry on where
// you stopped", "Email my notes" through the outbox with its limits, the
// endpoint's decisions, and the page's static promises (strict CSP, no
// YouTube script, no other file reading the notes).
//
//   php tests/our_sermons/listen.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

date_default_timezone_set('Africa/Lagos');
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['MAIL_FROM_ADDRESS'] = 'system@hodlekki.org';
$_ENV['MAIL_USERNAME'] = 'system@hodlekki.org';
$_ENV['SMS_VAULT_KEY'] = str_repeat('0', 64);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/security_helpers.php';
require_once $root . '/includes/ui/bootstrap.php';
require_once $root . '/includes/ui/components.php';
require_once $root . '/includes/csrf.php';
require_once $root . '/includes/sms_functions.php';
require_once $root . '/includes/campaigns/bootstrap.php';
require_once $root . '/tests/campaigns/lib.php';          // ct_schema_sql(), ct_platform_sql(): the real email schema
require_once $root . '/tests/permissions/install.php';    // the permission engine and the matrix
require_once $root . '/includes/bible/bootstrap.php';
require_once $root . '/includes/our_sermons/listen.php';

const OSL_MIGRATION = '20261213100000_our_sermons_listen.sql';

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
    ok($label, $expected === $actual, 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}
function section(string $name): void
{
    echo "\n-- {$name}\n";
}

/** A migration's CREATE TABLEs, translated to SQLite (as the other suites do). */
function osl_schema_sql(string $sql): array
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    preg_match_all('/CREATE TABLE IF NOT EXISTS `?(\w+)`? \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
    $out = [];
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim(str_replace('`', '', $line));
            if ($line === '' || ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line))) {
                continue;
            }
            $line = (string) preg_replace('/\((\w+)\(\d+\)\)/', '($1)', $line);   // prefix lengths in keys
            if (preg_match('/^UNIQUE (?:KEY|INDEX) \w+ \((.+)\)$/', $line, $m)) {
                $cols[] = 'UNIQUE (' . $m[1] . ')';
                continue;
            }
            if (preg_match('/^(?:KEY|INDEX) (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE INDEX {$m[1]} ON {$name} ({$m[2]})";
                continue;
            }
            if (preg_match('/^(?:FULLTEXT|CONSTRAINT|FOREIGN)\b/', $line)) {
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = (string) preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\bENUM\([^)]*\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(LONGTEXT|MEDIUMTEXT|TINYTEXT|JSON|DATETIME|TIMESTAMP|DATE)\b/', 'TEXT', $line);
            $line = (string) preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP\b/', '', $line);
            $line = (string) preg_replace('/\s+(?:CHARACTER SET|COLLATE) \w+/', '', $line);
            $line = (string) preg_replace('/\s+COMMENT \'[^\']*\'/', '', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        array_push($out, ...$indexes);
    }
    return $out;
}

/** S1's migration that creates our_sermons, once it is in the tree. */
function osl_s1_migration(): ?string
{
    foreach (glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') ?: [] as $file) {
        if (basename($file) !== OSL_MIGRATION && str_contains((string) file_get_contents($file), 'CREATE TABLE IF NOT EXISTS our_sermons (')) {
            return $file;
        }
    }
    return null;
}

/** The contract's our_sermons (OUR-SERMONS-PLAN.md), until S1's real table is in the tree. */
const OSL_STANDIN = "CREATE TABLE our_sermons (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, preacher TEXT, date_preached TEXT NOT NULL,
    service TEXT, series TEXT, summary TEXT, key_points TEXT, scriptures TEXT, artwork_url TEXT, audio_url TEXT, duration_seconds INTEGER,
    spotify_url TEXT, spotify_episode_id TEXT, youtube_url TEXT, youtube_id TEXT, source TEXT, feed_guid TEXT, status TEXT NOT NULL DEFAULT 'draft',
    ai_status TEXT, ai_drafted_at TEXT, published_at TEXT, published_by INTEGER, created_by INTEGER, created_at TEXT, updated_at TEXT)";

/** A church: the email schema, the matrix, people, and (optionally) the tables. */
function osl_db(bool $withListen = true, bool $withSermons = true): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (ct_platform_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    foreach (ct_schema_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    if ($withSermons) {
        $s1 = osl_s1_migration();
        $made = false;
        if ($s1 !== null) {
            try {
                foreach (osl_schema_sql((string) file_get_contents($s1)) as $stmt) {
                    $pdo->exec($stmt);
                }
                $made = true;
            } catch (Throwable $e) {
                ok('S1\'s migration translates to SQLite', false, $e->getMessage());
            }
        }
        if (!$made) {
            $pdo->exec(OSL_STANDIN);
        }
    }
    if ($withListen) {
        foreach (osl_schema_sql((string) file_get_contents($root . '/db/migrations/' . OSL_MIGRATION)) as $stmt) {
            $pdo->exec($stmt);
        }
    }
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor')");
    $pdo->exec("INSERT INTO departments (id, name) VALUES (20, 'Media'), (40, 'Envision')");
    foreach ([[900, 'Rita', 'rita@gmail.com'], [901, 'Ade', 'ade.worker@hodlc.com'], [902, 'Bola', null], [903, 'Chi', 'chi@yahoo.com']] as [$id, $name, $email]) {
        $pdo->prepare("INSERT INTO users (id, first_name, last_name, email, spiritual_status, attendance_status, password_hash, account_status, created_at)
                       VALUES (?, ?, 'Test', ?, 'Member', 'Active', 'x', 'active', '2026-01-01 10:00:00')")
            ->execute([$id, $name, $email]);
    }
    // Rita is the Resident Pastor: the plan gives pastors edit on our_sermons.
    $pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (900, 2)');
    perm_test_install($pdo);
    // S1's matrix rows, when the shared installer does not apply them (yet).
    if (array_key_exists('our_sermons', perm_modules())
        && (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE module_key = 'our_sermons'")->fetchColumn() === 0) {
        foreach (glob($root . '/db/migrations/*.sql') ?: [] as $file) {
            $sql = (string) file_get_contents($file);
            if (str_contains($sql, 'INSERT INTO perm_grants') && str_contains($sql, "'our_sermons'")) {
                $pdo->exec(perm_test_migration(basename($file)));
            }
        }
    }
    perm_reset();
    return $pdo;
}

function osl_as(int $uid): void
{
    $_SESSION = ['user_id' => $uid];
    perm_reset();
}

function osl_sermon(PDO $pdo, array $over = []): int
{
    $row = $over + [
        'title' => 'Ground Zero - The Faith Once Delivered', 'preacher' => 'Pastor Ebele Uzo-Peters', 'date_preached' => '2026-11-08',
        'service' => 'sunday_2', 'series' => 'Ground Zero', 'summary' => "Contend for the faith (Jude 3).\n\nBuild on the rock.",
        'key_points' => json_encode(['The faith was delivered once', 'Contend for it: read Jude 1:3 again']),
        'scriptures' => json_encode(['Jude 1:3', 'Ephesians 2:20', 'Not a verse']),
        'artwork_url' => '/uploads/sermons/ab12cd34.jpg', 'audio_url' => 'https://anchor.fm/s/abc123/podcast/play/9876/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-10-11%2Fep.mp3',
        'duration_seconds' => 3000, 'spotify_url' => 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa', 'youtube_url' => null, 'youtube_id' => null,
        'source' => 'feed', 'status' => 'published', 'created_at' => '2026-11-11 09:00:00', 'updated_at' => '2026-11-11 09:00:00',
    ];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO our_sermons (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

function osl_count(PDO $pdo, string $table, string $where = '1=1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function osl_clock(string $at): void
{
    $t = new DateTimeImmutable($at, new DateTimeZone('Africa/Lagos'));
    $GLOBALS['our_sermon_now'] = $t;
    campaigns_set_clock($t);
}

osl_clock('2026-11-13 10:00:00');
$migration = (string) file_get_contents($root . '/db/migrations/' . OSL_MIGRATION);
$hasS1 = osl_s1_migration() !== null;
$hasPerm = array_key_exists('our_sermons', perm_modules());
ok('S1\'s migration is in the tree (our_sermons is built from it)', $hasS1);
ok('S1\'s permission row is in the registry', $hasPerm);

// ---------------------------------------------------------------------------
section('The migration');
$pdo = osl_db();
eq('our_sermon_notes: its columns', ['id', 'sermon_id', 'user_id', 'at_seconds', 'body', 'created_at', 'updated_at'],
    array_column($pdo->query('PRAGMA table_info(our_sermon_notes)')->fetchAll(), 'name'));
eq('our_sermon_progress: its columns', ['id', 'sermon_id', 'user_id', 'position_seconds', 'finished_at', 'updated_at'],
    array_column($pdo->query('PRAGMA table_info(our_sermon_progress)')->fetchAll(), 'name'));
ok('notes are found by member and sermon', str_contains($migration, 'KEY ix_our_sermon_notes_user (user_id, sermon_id)'));
ok('one progress row per member and sermon', str_contains($migration, 'UNIQUE KEY uq_our_sermon_progress (sermon_id, user_id)'));
ok('idempotent (IF NOT EXISTS), drops nothing, alters nothing', substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 2
    && stripos($migration, 'DROP ') === false && stripos($migration, 'ALTER ') === false);
ok('in S2\'s slot, with the _our_sermons_ marker', str_starts_with(OSL_MIGRATION, '20261213100000') && str_contains(OSL_MIGRATION, '_our_sermons_'));
ok('the file is LF only', !str_contains($migration, "\r"));

// ---------------------------------------------------------------------------
section('Before the migrations everything degrades');
$bare = osl_db(false, false);
eq('not ready without any table', false, our_sermon_listen_ready($bare));
$half = osl_db(false, true);
eq('not ready with S1\'s table alone', false, our_sermon_listen_ready($half));
osl_as(903);
eq('the endpoint says FEATURE_NOT_READY', 'FEATURE_NOT_READY', our_sermon_listen_action($half, 'notes', ['sermon_id' => 1], 903)['code'] ?? null);
eq('no progress yet reads as the start', ['position' => 0, 'finished' => false, 'finished_at' => null], our_sermon_progress_get($half, 903, 1));
eq('no notes yet', [], our_sermon_notes_list($half, 903, 1));
eq('ready once both are in', true, our_sermon_listen_ready($pdo));

// ---------------------------------------------------------------------------
section('Times, and S1\'s labels');
eq('seconds', '0:07', our_sermon_fmt_time(7));
eq('minutes', '12:34', our_sermon_fmt_time(754));
eq('hours', '1:02:03', our_sermon_fmt_time(3723));
eq('never negative', '0:00', our_sermon_fmt_time(-5));
eq('the page uses S1\'s length label', '50 min', our_sermon_view(['id' => 1, 'duration_seconds' => 3000, 'date_preached' => '2026-11-08'])['length']);
eq('…and S1\'s date label', 'Sun 8 Nov 2026', our_sermon_view(['id' => 1, 'date_preached' => '2026-11-08'])['date_label']);

// ---------------------------------------------------------------------------
section('What the page shows is cleaned');
$id = osl_sermon($pdo);
$view = our_sermon_view(our_sermon_listen_get($pdo, $id));
eq('title', 'Ground Zero - The Faith Once Delivered', $view['title']);
eq('date label', 'Sun 8 Nov 2026', $view['date_label']);
eq('the service in S1\'s words', 'Sunday 2nd service', $view['service']);
eq('summary paragraphs', ['Contend for the faith (Jude 3).', 'Build on the rock.'], $view['summary']);
eq('key points (S1: a JSON array of strings)', [['text' => 'The faith was delivered once', 'detail' => ''], ['text' => 'Contend for it: read Jude 1:3 again', 'detail' => '']], $view['key_points']);
eq('three scriptures kept', 3, count($view['scriptures']));
eq('…objects are read too, should S3 ever store them', [['text' => 'Contend', 'detail' => 'Why'], 'The foundation'],
    [our_sermon_key_points([['point' => 'Contend', 'detail' => 'Why']])[0], our_sermon_scripture_list([['ref' => 'Eph 2:20', 'note' => 'The foundation']])[0]['note']]);
eq('…a reference opens the panel', ['b' => 65, 'c' => 1, 'v' => 3, 've' => 3, 'ce' => 1, 'label' => 'Jude 1:3'], $view['scriptures'][0]['ref']);
eq('…labels come from the Bible module', ['Ephesians 2:20', ''], [$view['scriptures'][1]['text'], $view['scriptures'][1]['note']]);
eq('…words that are not a reference stay words', [null, 'Not a verse'], [$view['scriptures'][2]['ref'], $view['scriptures'][2]['text']]);
eq('local artwork is shown', '/uploads/sermons/ab12cd34.jpg', $view['artwork']);
eq('Spotify only from open.spotify.com', 'https://open.spotify.com/episode/04zNnoxeIDwt0Hy2zjmPWa', $view['spotify']);
eq('the length', 3000, $view['duration']);
eq('no video', null, $view['youtube_id']);

$messy = our_sermon_view(['id' => 9, 'title' => 'X', 'date_preached' => '2026-11-01', 'status' => 'published',
    'artwork_url' => 'https://i.scdn.co/image/abc.jpg', 'audio_url' => 'http://example.com/a.mp3', 'spotify_url' => 'https://evil.example/episode/1',
    'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90', 'key_points' => 'not json', 'scriptures' => null, 'duration_seconds' => 999999]);
eq('a hot-linked picture is not shown (img-src stays self)', null, $messy['artwork']);
eq('plain http audio is refused', null, $messy['audio']);
eq('a link that is not Spotify is refused', null, $messy['spotify']);
eq('the video id comes from the YouTube link', 'dQw4w9WgXcQ', $messy['youtube_id']);
eq('…or from youtu.be', 'dQw4w9WgXcQ', our_sermon_youtube_id(['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?si=x']));
eq('…or from /live/', 'dQw4w9WgXcQ', our_sermon_youtube_id(['youtube_url' => 'https://www.youtube.com/live/dQw4w9WgXcQ']));
eq('…the stored id wins', 'aaaaaaaaaaa', our_sermon_youtube_id(['youtube_id' => 'aaaaaaaaaaa', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']));
eq('…a bad id is no video', null, our_sermon_youtube_id(['youtube_id' => 'x"><script>', 'youtube_url' => '']));
eq('broken JSON is no key points', [], $messy['key_points']);
eq('a length over a day is no length', null, $messy['duration']);
eq('audio on this site is fine', '/uploads/sermons/ep.mp3', our_sermon_audio_url('/uploads/sermons/ep.mp3'));
eq('…but not a path that climbs out', null, our_sermon_audio_url('/uploads/../.env.mp3'));

// ---------------------------------------------------------------------------
section('media-src (S1\'s our_sermons_media_hosts()) and the audio type');
// A real enclosure from the Lekki feed (13 Nov 2026): it 302s to the CDN, which
// answers 206 ranges as application/octet-stream.
$lekki = 'https://anchor.fm/s/116f1a844/podcast/play/129266832/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-10-11%2F011d372e-3172-8b53-46df-707e7f9467f4.mp3';
eq('a real Lekki feed enclosure: anchor.fm and the CDN it redirects to', ['https://anchor.fm', 'https://d3ctxlq1ktw2nl.cloudfront.net'], our_sermons_media_hosts($lekki));
eq('…the same for the test sermon\'s cleaned audio', ['https://anchor.fm', 'https://d3ctxlq1ktw2nl.cloudfront.net'], our_sermons_media_hosts($view['audio'] ?? null));
eq('the type is declared (the CDN says octet-stream): MP3 inside an anchor.fm URL', 'audio/mpeg', our_sermon_audio_type($lekki));
eq('…an m4a', 'audio/mp4', our_sermon_audio_type('https://cdn.example.org/a/b.M4A?x=1'));
eq('…MP3 when nothing says', 'audio/mpeg', our_sermon_audio_type('https://cdn.example.org/play/123'));
eq('http audio never reaches the page, so it adds no host', [null, []], [our_sermon_audio_url('http://cdn.example.org/a.mp3'), our_sermons_media_hosts(null)]);

// ---------------------------------------------------------------------------
section('Scripture references in free text');
$labels = static fn (string $t): array => array_map(static fn ($f) => $f[2]['label'], our_sermon_find_refs($t));
eq('John 3:16', ['John 3:16'], $labels('Remember John 3:16 tonight'));
eq('a range and a numbered book', ['1 Corinthians 13:4–7'], $labels('Love: 1 Cor 13:4-7.'));
eq('a three-word book', ['Song of Solomon 2:4'], $labels('see Song of Solomon 2:4'));
eq('an abbreviation with a dot', ['Romans 8:28'], $labels('Rom. 8:28 says it'));
eq('a whole chapter, capitalised', ['Psalm 23'], $labels('We read Psalm 23 together'));
eq('a one-chapter book in brackets', ['Jude 1:3'], $labels('Contend (Jude 3) daily'));
eq('two in one note', ['Hebrews 11:1', 'Romans 10:17'], $labels('Faith: Hebrews 11:1 and Romans 10:17'));
eq('lower case with a verse is a reference', ['John 3:16'], $labels('john 3:16'));
eq('lower case without a verse is not ("I am 5 minutes late")', [], $labels('I am 5 minutes late, page 12'));
eq('a number with no book is not', [], $labels('At 12:30 we prayed for 3 people'));
eq('a chapter that does not exist is not', [], $labels('John 99:1'));
$text = "Pastor said <b>this</b>: Jude 3 — then Rom 8:28!\nAmen";
$segs = our_sermon_text_segments($text);
eq('segments put the text back together exactly', $text, implode('', array_column($segs, 't')));
eq('…with the references marked', ['Jude 3', 'Rom 8:28'], array_values(array_map(static fn ($s) => $s['t'], array_filter($segs, static fn ($s) => isset($s['ref'])))));
$html = our_sermon_rich_html($text);
ok('rich text escapes what it is given', str_contains($html, '&lt;b&gt;') && !str_contains($html, '<b>'));
ok('…and references become links that open the Bible panel', substr_count($html, 'data-action="os.ref"') === 2 && str_contains($html, 'data-b="45"'));
ok('…which, without the script, open the Bible module at the verse', str_contains($html, '<a class="osl-ref" href="/modules/bible/#read=65.1.3"')
    && str_contains($html, 'href="/modules/bible/#read=45.8.28"'));
eq('no text, one empty run', [['t' => '']], our_sermon_text_segments(''));

// ---------------------------------------------------------------------------
section('Notes: private, stamped, editable');
$n1 = our_sermon_note_add($pdo, 903, ['id' => $id, 'duration_seconds' => 3000], "  Faith comes by hearing (Romans 10:17)\r\n\r\n\r\n\r\nAmen \x07 ", 754.6);
ok('a note is added', $n1['ok'] ?? false);
eq('…stamped at the moment', [754, '12:34'], [$n1['note']['at'], $n1['note']['at_label']]);
eq('…its text cleaned (one blank line at most, no control characters)', "Faith comes by hearing (Romans 10:17)\n\nAmen", $n1['note']['body']);
ok('…references marked for the page', in_array('Romans 10:17', array_column(array_filter($n1['note']['segments'], static fn ($s) => isset($s['ref'])), 't'), true));
$n2 = our_sermon_note_add($pdo, 903, ['id' => $id, 'duration_seconds' => 3000], 'Before the start', '');
eq('an unstamped note', null, $n2['note']['at']);
$n3 = our_sermon_note_add($pdo, 903, ['id' => $id, 'duration_seconds' => 3000], 'Early', 30);
$n4 = our_sermon_note_add($pdo, 903, ['id' => $id, 'duration_seconds' => 3000], 'Past the end', 9999);
eq('a moment past the end is the end', 3000, $n4['note']['at']);
eq('a negative moment is no moment', null, our_sermon_note_at(-3));
eq('in the sermon\'s order, unstamped last', ['Early', 'Faith comes by hearing (Romans 10:17)', 'Past the end', 'Before the start'],
    array_map(static fn ($n) => strtok($n['body'], "\n"), our_sermon_notes_list($pdo, 903, $id)));
eq('empty is refused', 'EMPTY', our_sermon_note_add($pdo, 903, ['id' => $id], "  \n ", 5)['code'] ?? null);
eq('too long is refused', 'TOO_LONG', our_sermon_note_add($pdo, 903, ['id' => $id], str_repeat('é', OUR_SERMON_NOTE_MAX + 1), 5)['code'] ?? null);
ok('…exactly the limit is fine', our_sermon_note_add($pdo, 903, ['id' => $id], str_repeat('é', OUR_SERMON_NOTE_MAX), 5)['ok'] ?? false);

eq('another member sees none of them', [], our_sermon_notes_list($pdo, 902, $id));
eq('…cannot change one', 'NOT_FOUND', our_sermon_note_edit($pdo, 902, $n1['note']['id'], 'Mine now')['code'] ?? null);
eq('…cannot delete one', 'NOT_FOUND', our_sermon_note_delete($pdo, 902, $n1['note']['id'])['code'] ?? null);
eq('…and nothing changed', "Faith comes by hearing (Romans 10:17)\n\nAmen",
    (string) $pdo->query('SELECT body FROM our_sermon_notes WHERE id = ' . (int) $n1['note']['id'])->fetchColumn());

osl_clock('2026-11-13 10:05:00');
$e1 = our_sermon_note_edit($pdo, 903, $n3['note']['id'], 'Early, and it matters');
eq('the writer edits a note', 'Early, and it matters', $e1['note']['body'] ?? null);
eq('…its moment is kept', 30, $e1['note']['at'] ?? null);
eq('…and it shows as edited', true, $e1['note']['edited'] ?? null);
eq('a new moment can be given', 45, our_sermon_note_edit($pdo, 903, $n3['note']['id'], 'Early, and it matters', 45)['note']['at'] ?? null);
$untimed = our_sermon_note_edit($pdo, 903, $n3['note']['id'], 'Early, and it matters', '');
eq('…or taken away', [true, null], [array_key_exists('at', $untimed['note'] ?? []), $untimed['note']['at'] ?? null]);
eq('the writer deletes a note', true, our_sermon_note_delete($pdo, 903, $n4['note']['id'])['ok'] ?? null);
eq('…once', 'NOT_FOUND', our_sermon_note_delete($pdo, 903, $n4['note']['id'])['code'] ?? null);

$busy = osl_sermon($pdo, ['title' => 'Busy']);
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO our_sermon_notes (sermon_id, user_id, at_seconds, body, created_at, updated_at) VALUES (?, 901, 1, 'n', '2026-11-13 10:00:00', '2026-11-13 10:00:00')");
for ($i = 0; $i < OUR_SERMON_NOTES_PER_SERMON; $i++) {
    $stmt->execute([$busy]);
}
$pdo->commit();
eq('a sermon holds ' . OUR_SERMON_NOTES_PER_SERMON . ' notes per member', 'FULL', our_sermon_note_add($pdo, 901, ['id' => $busy], 'one more', 2)['code'] ?? null);
ok('…another member is not affected', our_sermon_note_add($pdo, 903, ['id' => $busy], 'mine', 2)['ok'] ?? false);

// ---------------------------------------------------------------------------
section('Carry on where you stopped');
$sermonRow = ['id' => $id, 'duration_seconds' => 3000];
osl_clock('2026-11-13 11:00:00');
eq('the first save makes the row', ['position' => 615, 'finished' => false, 'finished_at' => null], our_sermon_progress_put($pdo, 903, $sermonRow, '615.9', false));
eq('…and it reads back', ['position' => 615, 'finished' => false, 'finished_at' => null], our_sermon_progress_get($pdo, 903, $id));
our_sermon_progress_put($pdo, 903, $sermonRow, 1200, false);
eq('a later save moves it, still one row', [1200, 1], [our_sermon_progress_get($pdo, 903, $id)['position'], osl_count($pdo, 'our_sermon_progress', 'user_id = 903 AND sermon_id = ?', [$id])]);
eq('past the end is the end', 3000, our_sermon_progress_put($pdo, 903, $sermonRow, 99999, false)['position']);
eq('below zero is the start', 0, our_sermon_progress_put($pdo, 903, $sermonRow, -40, false)['position']);
eq('nonsense is the start', 0, our_sermon_progress_put($pdo, 903, $sermonRow, 'abc', false)['position']);
$done = our_sermon_progress_put($pdo, 903, $sermonRow, 2990, true);
eq('finishing stamps it and goes back to the start', [0, true, '2026-11-13 11:00:00'], [$done['position'], $done['finished'], $done['finished_at']]);
osl_clock('2026-11-14 08:00:00');
our_sermon_progress_put($pdo, 903, $sermonRow, 400, false);
$again = our_sermon_progress_put($pdo, 903, $sermonRow, 0, true);
eq('listening again keeps the first finish', '2026-11-13 11:00:00', $again['finished_at']);
eq('each member has their own place', ['position' => 0, 'finished' => false, 'finished_at' => null], our_sermon_progress_get($pdo, 902, $id));
ok('a second tab racing the first insert still ends with one row', (static function () use ($pdo, $id): bool {
    $pdo->exec("INSERT INTO our_sermon_progress (sermon_id, user_id, position_seconds, finished_at, updated_at) VALUES ({$id}, 901, 5, NULL, '2026-11-14 08:00:00')");
    $r = our_sermon_progress_put($pdo, 901, ['id' => $id, 'duration_seconds' => 3000], 50, false);
    return $r['position'] === 50 && osl_count($pdo, 'our_sermon_progress', 'user_id = 901') === 1;
})());

// ---------------------------------------------------------------------------
section('Email my notes');
osl_clock('2026-11-14 09:00:00');
$sermonFull = our_sermon_listen_get($pdo, $id);
$doc = our_sermon_notes_doc($sermonFull, our_sermon_notes_list($pdo, 903, $id), our_sermon_page_url($id));
$valid = email_doc_validate($doc);
ok('the email is a valid block document', $valid['ok'], implode('; ', $valid['errors']));
eq('…its subject names the sermon', 'Your notes: Ground Zero - The Faith Once Delivered', $doc['subject']);
ok('…the button goes back to the sermon', in_array('https://hodlekki.org/modules/our_sermons/sermon?id=' . $id, array_column($doc['blocks'], 'href'), true));
$tricky = our_sermon_notes_doc(['id' => 1, 'title' => 'T {{first_name}}', 'date_preached' => '2026-11-08'],
    [['at' => 61, 'body' => "Hi {{unsubscribe}}\nline two"], ['at' => null, 'body' => 'loose']], 'https://hodlekki.org/x');
ok('…a member\'s {{…}} is not a merge field', email_doc_validate($tricky)['ok'], implode('; ', email_doc_validate($tricky)['errors']));
$many = [];
for ($i = 0; $i < 300; $i++) {
    $many[] = ['at' => $i, 'body' => str_repeat('word ', 380)];
}
$big = our_sermon_notes_doc($sermonFull, $many, 'https://hodlekki.org/x');
ok('…300 long notes still make a valid email (packed, with a line for the rest)', email_doc_validate($big)['ok'], implode('; ', email_doc_validate($big)['errors']));

osl_as(903);
$r = our_sermon_notes_email($pdo, 903, $sermonFull, 'mine');
eq('to my email: the account email when no personal one is confirmed', [true, 'c•••@yahoo.com'], [$r['ok'] ?? false, $r['to'] ?? null]);
$row = $pdo->query("SELECT * FROM email_outbox ORDER BY id DESC LIMIT 1")->fetch();
eq('…queued in the outbox as an account email', ['transactional', 'account', 'our_sermon_note_email', 'chi@yahoo.com', 903, 903],
    [$row['lane'], $row['purpose'], $row['flow'], $row['to_email'], (int) $row['user_id'], (int) $row['created_by']]);
eq('one a minute', 'TOO_SOON', our_sermon_notes_email($pdo, 903, $sermonFull, 'mine')['code'] ?? null);
osl_clock('2026-11-14 09:02:00');
eq('another address must be an address', ['INVALID_EMAIL', 'email'], [our_sermon_notes_email($pdo, 903, $sermonFull, 'other', 'not-an-email')['code'] ?? null,
    our_sermon_notes_email($pdo, 903, $sermonFull, 'other', 'nope@')['field'] ?? null]);
eq('…a typed one is used', 'f•••@example.com', our_sermon_notes_email($pdo, 903, $sermonFull, 'other', ' Friend@Example.com ')['to'] ?? null);
foreach (['09:04', '09:06', '09:08'] as $t) {
    osl_clock('2026-11-14 ' . $t . ':00');
    our_sermon_notes_email($pdo, 903, $sermonFull, 'mine');
}
osl_clock('2026-11-14 09:10:00');
eq(OUR_SERMON_EMAILS_PER_DAY . ' a day', 'LIMIT', our_sermon_notes_email($pdo, 903, $sermonFull, 'mine')['code'] ?? null);
osl_clock('2026-11-15 09:01:00');
ok('…a day later it works again', our_sermon_notes_email($pdo, 903, $sermonFull, 'mine')['ok'] ?? false);
eq('no notes, nothing to send', 'NO_NOTES', our_sermon_notes_email($pdo, 900, $sermonFull, 'mine')['code'] ?? null);
our_sermon_note_add($pdo, 902, $sermonFull, 'Bola\'s note', 10);
eq('no address on the account: type one', ['NO_EMAIL', 'email'], [our_sermon_notes_email($pdo, 902, $sermonFull, 'mine')['code'] ?? null,
    our_sermon_notes_email($pdo, 902, $sermonFull, 'mine')['field'] ?? null]);
$pdo->prepare("INSERT INTO email_addresses (user_id, email, status, source, confirmed_user_id, confirmed_email, created_at) VALUES (902, 'bola@gmail.com', 'confirmed', 'profile', 902, 'bola@gmail.com', '2026-11-01 10:00:00')")->execute();
eq('…a confirmed personal email is used first', 'b•••@gmail.com', our_sermon_notes_email($pdo, 902, $sermonFull, 'mine')['to'] ?? null);
$outboxNotes = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE flow = 'our_sermon_note_email'")->fetchColumn();
eq('every send above is one outbox row, and only those', 7, $outboxNotes);

// ---------------------------------------------------------------------------
section('The endpoint');
$draft = osl_sermon($pdo, ['title' => 'Draft one', 'status' => 'draft']);
if (!$hasPerm) {
    echo "  note: the permission row 'our_sermons' is S1's; until it is in the tree can() fails closed\n";
    osl_as(903);
    eq('without the row everyone is refused (fail closed)', 'FORBIDDEN', our_sermon_listen_action($pdo, 'notes', ['sermon_id' => $id], 903)['code'] ?? null);
} else {
    osl_as(903);
    $list = our_sermon_listen_action($pdo, 'notes', ['sermon_id' => $id], 903);
    eq('a member lists their notes', 'success', $list['status']);
    ok('…only theirs', count($list['data']['notes']) > 0 && !in_array("Bola's note", array_column($list['data']['notes'], 'body'), true));
    $add = our_sermon_listen_action($pdo, 'note_add', ['sermon_id' => $id, 'body' => 'From the API', 'at' => '61'], 903);
    eq('…adds one', ['success', 61], [$add['status'], $add['data']['note']['at'] ?? null]);
    eq('…edits it', 'Changed', our_sermon_listen_action($pdo, 'note_edit', ['id' => $add['data']['note']['id'], 'body' => 'Changed'], 903)['data']['note']['body'] ?? null);
    eq('…an empty edit is refused with its field', ['EMPTY', 'body'], [our_sermon_listen_action($pdo, 'note_edit', ['id' => $add['data']['note']['id'], 'body' => ' '], 903)['code'] ?? null,
        our_sermon_listen_action($pdo, 'note_edit', ['id' => $add['data']['note']['id'], 'body' => ' '], 903)['field'] ?? null]);
    eq('…saves the place', 120, our_sermon_listen_action($pdo, 'progress', ['sermon_id' => $id, 'position' => '120', 'finished' => '0'], 903)['data']['position'] ?? null);
    eq('…finishes it', true, our_sermon_listen_action($pdo, 'progress', ['sermon_id' => $id, 'position' => '2999', 'finished' => '1'], 903)['data']['finished'] ?? null);
    eq('…looks up a reference for the Bible panel', 'Romans 8:28', our_sermon_listen_action($pdo, 'ref', ['q' => 'rom 8:28'], 903)['data']['ref']['label'] ?? null);
    eq('…a bad reference says what to type', ['NOT_FOUND', 'q'], [our_sermon_listen_action($pdo, 'ref', ['q' => 'hello'], 903)['code'] ?? null,
        our_sermon_listen_action($pdo, 'ref', ['q' => 'hello'], 903)['field'] ?? null]);
    eq('…deletes it', 'success', our_sermon_listen_action($pdo, 'note_delete', ['id' => $add['data']['note']['id']], 903)['status']);
    eq('a draft is not there for a member', 'NOT_FOUND', our_sermon_listen_action($pdo, 'note_add', ['sermon_id' => $draft, 'body' => 'x'], 903)['code'] ?? null);
    eq('…nor its progress', 'NOT_FOUND', our_sermon_listen_action($pdo, 'progress', ['sermon_id' => $draft, 'position' => 5], 903)['code'] ?? null);
    osl_as(900);
    eq('Envision (edit) may open a draft to check it', 'success', our_sermon_listen_action($pdo, 'notes', ['sermon_id' => $draft], 900)['status']);
    eq('an unknown action', 'UNKNOWN_ACTION', our_sermon_listen_action($pdo, 'drop_table', [], 900)['code'] ?? null);
    eq('signed out', 'SIGNED_OUT', our_sermon_listen_action($pdo, 'notes', ['sermon_id' => $id], 0)['code'] ?? null);
}
eq('a sermon that does not exist is nobody\'s', null, our_sermon_listen_get($pdo, 999999));
eq('a draft needs the preview right', [null, $draft], [our_sermon_listen_get($pdo, $draft), (int) (our_sermon_listen_get($pdo, $draft, true)['id'] ?? 0)]);

// ---------------------------------------------------------------------------
section('The files keep their promises');
$api = (string) file_get_contents($root . '/api/our_sermons_listen_api.php');
ok('the endpoint is POST only, signed in, can(our_sermons, view), CSRF', str_contains($api, "!== 'POST'") && str_contains($api, "empty(\$_SESSION['user_id'])")
    && str_contains($api, "api_require_can('our_sermons', 'view');") && str_contains($api, 'csrf_require();'));
ok('…and decides nothing itself (one dispatcher)', str_contains($api, 'our_sermon_listen_action($pdo'));
$page = (string) file_get_contents($root . '/modules/our_sermons/sermon.php');
ok('the page is strict', str_contains($page, "'mode' => 'strict'"));
ok('…loads only the metadata until Play (files are 25–55 MB)', str_contains($page, '<audio preload="metadata" data-os-audio>') && !preg_match('/<audio[^>]*\b(autoplay|preload="auto")/', $page));
ok('…declares the audio type on a <source>', str_contains($page, 'type="<?= ui_e(our_sermon_audio_type($osView[\'audio\'])) ?>" data-os-source'));
ok('…asks can(our_sermons, view)', str_contains($page, "require_can('our_sermons', 'view');"));
ok('…media-src only from this sermon\'s audio (S1\'s hosts)', str_contains($page, "\$osCsp = ['media-src' => our_sermons_media_hosts(\$osView['audio'] ?? null)];"));
ok('…frame-src only youtube-nocookie, only with a video', str_contains($page, "\$osCsp['frame-src'] = [OUR_SERMON_YOUTUBE_ORIGIN];") && OUR_SERMON_YOUTUBE_ORIGIN === 'https://www.youtube-nocookie.com');
ok('…no script-src or style-src added', !preg_match("/\\\$osCsp\\['(script|style|connect)-src'\\]/", $page));
ok('…no inline script, handler or style', !preg_match('/<script(?![^>]*type="application\/json")|\son[a-z]+=|\sstyle=/i', $page));
$js = (string) file_get_contents($root . '/assets/ui/js/pages/our-sermon.js');
ok('the script never loads YouTube\'s own script', !str_contains($js, 'iframe_api') && !str_contains($js, 'createElement(\'script\')'));
ok('…checks where YouTube\'s messages come from', str_contains($js, 'e.origin !== cfg.youtubeOrigin') && str_contains($js, 'e.source !== yt.frame.contentWindow'));
ok('…reads chapters from the Bible module\'s endpoint', str_contains($js, "HOD.api('/api/bible_api.php', { method: 'GET', action: 'chapter'"));
ok('…reports licensed text to FUMS, as the Bible module does', str_contains($js, 'reportFums(data.fums_token)'));
ok('…honours reduced motion', str_contains($js, "prefers-reduced-motion: reduce"));
ok('…saves the place as the page goes away (keepalive, with the token)', str_contains($js, 'keepalive: true') && str_contains($js, "form.append('_csrf'"));
ok('…puts notes in with textContent, never as HTML', str_contains($js, 'document.createTextNode(seg.t)') && !preg_match('/innerHTML\s*=\s*[^;]*(note\.body|seg\.t|\.label)/', $js));
foreach (['play', 'pause', 'rotate-ccw', 'rotate-cw', 'headphones'] as $icon) {
    ok("the {$icon} icon is in the sprite", str_contains((string) file_get_contents($root . '/assets/ui/icons.svg'), 'id="' . $icon . '"'));
}

// A note is the member's alone: no other file may read the table. Test files are
// skipped: they name the table to prove it is NOT read (tests/our_sermons/ai.php
// checks the AI never touches notes), which is not reading it.
$allowed = ['includes/our_sermons/listen.php', 'db/migrations/' . OSL_MIGRATION];
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('#^(vendor|node_modules|\.git|\.tailwind|\.claude|uploads|assets/uploads|tests)/#', $rel) || !preg_match('/\.(php|js|sql)$/', $rel)) {
        continue;
    }
    if (!in_array($rel, $allowed, true) && preg_match('/\bour_sermon_notes\b/', (string) file_get_contents($file->getPathname()))) {
        $offenders[] = $rel;
    }
}
eq('only includes/our_sermons/listen.php reads our_sermon_notes', [], $offenders);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
