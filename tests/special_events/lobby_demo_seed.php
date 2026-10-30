<?php
// /tests/special_events/lobby_demo_seed.php
//
// Seeds a Chara-like event whose doors are open RIGHT NOW, so the check-in
// flow, the lobby screens and the volunteer hand-the-phone mode can be
// rehearsed (and screenshotted) end to end on a laptop:
//
//   - /e/<slug> published, check-in open for the next four hours;
//   - four teams (Gold, Teal, Rose, Violet), the Chara verses, a hero image;
//   - 40 registered guests (every fourth one ticked karaoke) who have NOT
//     checked in yet, plus walk-ins left to the person rehearsing;
//   - the karaoke hand-off pointed at an external song picker.
//
// It prints the lobby and volunteer links (with their keys) at the end.
//
// CLI only, never reads .env: the DSN comes from SE_TEST_DB_*, the same
// variables db_setup.php uses. tests/ is not deployed.
//
// Usage, from the repository root:
//   php tests/special_events/db_setup.php --socket=/run/mysqld/mysqld.sock --db=se_local --fresh --seed
//   SE_TEST_DB_SOCKET=/run/mysqld/mysqld.sock SE_TEST_DB_NAME=se_local \
//       php tests/special_events/lobby_demo_seed.php [--hero=/path/to/hero.jpg] [--checkins=N]
//
// With the lobby screen open, simulate people walking in (no reseed):
//       php tests/special_events/lobby_demo_seed.php --arrive=N [--gap=MS]
// checks in the next N registered guests, MS milliseconds apart (default
// 1500; 0 = all at once, the rush). Past the 40th it makes walk-ins.
//   php -S 127.0.0.1:8099 -t . tests/special_events/dev_router.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$opts = getopt('', ['hero::', 'checkins::', 'slug::', 'karaoke-url::', 'arrive::', 'gap::']);

$socket = getenv('SE_TEST_DB_SOCKET') ?: '';
$host   = getenv('SE_TEST_DB_HOST') ?: '127.0.0.1';
$port   = (int) (getenv('SE_TEST_DB_PORT') ?: 3306);
$user   = getenv('SE_TEST_DB_USER') ?: 'root';
$pass   = getenv('SE_TEST_DB_PASS') ?: '';
$name   = getenv('SE_TEST_DB_NAME') ?: 'se_test';
$slug   = (string) ($opts['slug'] ?? 'chara');

$dsn = $socket !== ''
    ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec("SET time_zone = '+01:00'");
date_default_timezone_set('Africa/Lagos');

// The volunteer link printed at the end is signed with the pepper, so pass
// the web server's own (SE_HASH_PEPPER=… in the environment) for it to work.
$_ENV['SE_HASH_PEPPER'] = getenv('SE_HASH_PEPPER') ?: str_repeat('ab', 32);

// The module's libraries one by one, like the integration suite: bootstrap.php
// would pull in includes/db.php, which reads .env.
$root = dirname(__DIR__, 2);
if (!function_exists('security_client_ip')) {
    function security_client_ip(): string { return '127.0.0.1'; }
}
// sms_functions.php opens its own connection through includes/db.php; hand it
// the same test database instead of letting it look for .env.
$_ENV['DB_HOST'] = $socket !== '' ? 'localhost' : $host;
$_ENV['DB_NAME'] = $name;
$_ENV['DB_USER'] = getenv('SE_TEST_DB_APP_USER') ?: $user;
$_ENV['DB_PASS'] = getenv('SE_TEST_DB_APP_PASS') ?: $pass;
require_once $root . '/includes/sms_functions.php';
foreach (['constants', 'util', 'db', 'theme', 'settings', 'security', 'events', 'assets', 'ai',
          'identity', 'capacity', 'registration', 'realtime', 'live', 'kjv_bundle', 'bible', 'verses',
          'teams', 'checkin', 'program', 'karaoke', 'messages', 'attendees', 'cards', 'export',
          'portal', 'games', 'games_engine', 'scoring', 'party_games', 'after_event'] as $lib) {
    require_once $root . '/includes/special_events/' . $lib . '.php';
}

// Snapshots and the hero land in this checkout's live/ and uploads/, which
// are git-ignored, so the dev server serves them like production does.
$_SERVER['DOCUMENT_ROOT'] = $root;

// ---- Arrivals only ------------------------------------------------------------
if (isset($opts['arrive'])) {
    $stmt = $pdo->prepare("SELECT id FROM se_events WHERE slug = ?");
    $stmt->execute([$slug]);
    $eventId = (int) ($stmt->fetchColumn() ?: 0);
    if (!$eventId) {
        fwrite(STDERR, "[demo] seed /e/{$slug} first\n");
        exit(1);
    }
    $count = max(1, (int) $opts['arrive']);
    $gapMs = max(0, (int) ($opts['gap'] ?? 1500));
    $walkFirst = ['Grace', 'Joy', 'Peace', 'Faith', 'Hope', 'Mercy', 'Favour', 'Blessing', 'Praise', 'Glory'];
    $done = 0;
    for ($n = 0; $done < $count && $n < 400; $n++) {
        $event = se_event_find($pdo, $eventId);
        $days  = se_event_days($pdo, $eventId);
        $in = ['phone' => se_phone_normalize(sprintf('0803%07d', 1000000 + $n))];
        if ($n >= 40) {
            $in += ['first_name' => $walkFirst[$n % 10], 'last_name' => 'Walk-in', 'gender' => $n % 2 ? 'Male' : 'Female'];
        }
        $result = se_checkin($pdo, $event, $days, $in, ['method' => 'desk', 'actor_user_id' => 1]);
        if (($result['outcome'] ?? '') !== 'checked_in') {
            continue;
        }
        $done++;
        $team = $result['team']['color_label'] ?? '-';
        echo "[demo] {$result['registration']['display_name']} → {$team}\n";
        if ($gapMs && $done < $count) {
            usleep($gapMs * 1000);
        }
    }
    exit(0);
}

// ---- Start clean -----------------------------------------------------------
$stmt = $pdo->prepare("SELECT id FROM se_events WHERE slug = ?");
$stmt->execute([$slug]);
$old = $stmt->fetch();
if ($old) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'event_id'
                             AND TABLE_NAME LIKE 'se\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->prepare("DELETE FROM `{$table}` WHERE event_id = ?")->execute([(int) $old['id']]);
    }
    $pdo->prepare("DELETE FROM se_events WHERE id = ?")->execute([(int) $old['id']]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "[demo] removed the previous /e/{$slug}\n";
}

// ---- The event ---------------------------------------------------------------
$now = se_now();
$publicId = se_new_public_id();

$pdo->prepare(
    "INSERT INTO se_events
        (public_id, preview_key, slug, title, edition_label, tagline, description_md,
         organizer_label, venue_name, venue_address, brand_primary, brand_secondary, brand_accent,
         starts_at, ends_at, status, visibility, published_at,
         online_capacity, auto_close_at_capacity, waitlist_enabled, waitlist_promotion,
         self_cancel_enabled, walkin_enabled, walkin_capacity, seats_left_mode, settings_json, created_by)
     VALUES (?, ?, ?, 'Chara', '2026', 'The fulness of joy.', ?,
             'Envision', 'HOD Lekki Centre', '12 Admiralty Way, Lekki Phase 1, Lagos',
             '#E8336D', '#F5B700', '#7B4DFF',
             ?, ?, 'published', 'public', NOW(),
             150, 1, 1, 'auto_confirm', 1, 1, 60, 'always', ?, 1)"
)->execute([
    $publicId,
    substr(bin2hex(random_bytes(16)), 0, 22),
    $slug,
    "In thy presence is fulness of joy.\n\nA night of worship, karaoke and games.",
    $now->modify('+20 minutes')->format('Y-m-d H:i:s'),
    $now->modify('+4 hours')->format('Y-m-d H:i:s'),
    se_json_encode([
        'karaoke' => ['external_url' => (string) ($opts['karaoke-url'] ?? 'https://karena.live/v/hod-lekki')],
        'lobby'   => ['show_names' => true],
        // Games run on another platform tonight.
        'games'   => ['enabled' => false],
    ]),
]);
$eventId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO se_slugs (slug, event_id, is_canonical) VALUES (?, ?, 1)")->execute([$slug, $eventId]);

$pdo->prepare(
    "INSERT INTO se_event_days (event_id, day_date, doors_open_at, starts_at, ends_at, checkin_closes_at)
     VALUES (?, ?, ?, ?, ?, ?)"
)->execute([
    $eventId,
    $now->format('Y-m-d'),
    $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
    $now->modify('+20 minutes')->format('Y-m-d H:i:s'),
    $now->modify('+4 hours')->format('Y-m-d H:i:s'),
    $now->modify('+4 hours')->format('Y-m-d H:i:s'),
]);

$event = se_event_find($pdo, $eventId);

// ---- Hero ---------------------------------------------------------------------
$hero = (string) ($opts['hero'] ?? '');
if ($hero !== '' && is_file($hero)) {
    $dir = se_uploads_dir($publicId, 'hero');
    $detected = se_detect_type($hero);
    $img = se_image_process($hero, (string) $detected, $dir, 'hero');
    $pdo->prepare(
        "INSERT INTO se_assets (event_id, kind, role, title, alt_text, path, mime, bytes, width, height, sha256, variants_json, created_by)
         VALUES (?, 'image', 'hero', 'Chara', 'Chara — the fulness of joy', ?, ?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        $eventId,
        '/uploads/se/' . $publicId . '/hero/' . $img['file'],
        $img['mime'],
        (int) filesize($img['path']),
        $img['width'],
        $img['height'],
        hash_file('sha256', $hero),
        $img['variants'] ? se_json_encode($img['variants']) : null,
    ]);
    $pdo->prepare("UPDATE se_events SET hero_asset_id = ? WHERE id = ?")->execute([(int) $pdo->lastInsertId(), $eventId]);
    echo "[demo] hero image stored\n";
}

// ---- Teams and verses -----------------------------------------------------------
se_teams_save($pdo, $event, [
    ['color_hex' => '#F5B700', 'color_label' => 'Gold'],
    ['color_hex' => '#00B3A4', 'color_label' => 'Teal'],
    ['color_hex' => '#E8336D', 'color_label' => 'Rose'],
    ['color_hex' => '#7B4DFF', 'color_label' => 'Violet'],
], 1);

$verses = se_chara_verses_add($pdo, $event, 1);
echo "[demo] {$verses['added']} welcome verses added\n";

// ---- Guests -----------------------------------------------------------------------
$first = ['Ada', 'Tunde', 'Funmi', 'Chidi', 'Kemi', 'Seyi', 'Bisi', 'Femi', 'Ngozi', 'Dayo',
          'Amaka', 'Tobi', 'Zainab', 'Emeka', 'Yemi', 'Ifeoma', 'Kunle', 'Halima', 'Uche', 'Lola'];
$last  = ['Okafor', 'Adeyemi', 'Bello', 'Eze', 'Ogunleye', 'Nwosu', 'Balogun', 'Ibrahim', 'Okon', 'Afolabi'];
$days  = se_event_days($pdo, $eventId);

for ($n = 0; $n < 40; $n++) {
    $result = se_register($pdo, se_event_find($pdo, $eventId), se_event_settings($event), $days, [
        'phone'            => se_phone_normalize(sprintf('0803%07d', 1000000 + $n)),
        'first_name'       => $first[$n % 20],
        'last_name'        => $last[($n * 3) % 10],
        'gender'           => $n % 2 === 0 ? 'Female' : 'Male',
        'karaoke_interest' => $n % 4 === 0,
        'consent'          => true,
        'consent_text'     => 'Demo consent',
        'answers'          => [],
        'channel'          => 'portal',
        // The doors are already open, so the public form would be closed;
        // add them the way the desk does.
        'ignore_state'     => true,
    ], ['now' => se_now(), 'ip_hash' => null, 'device' => null]);
    if (($result['outcome'] ?? '') !== 'registered' && ($result['outcome'] ?? '') !== 'waitlisted') {
        fwrite(STDERR, '[demo] guest ' . $n . ': ' . ($result['outcome'] ?? '?') . "\n");
    }
}
echo "[demo] 40 guests registered (phones 0803 100 0000 … 0803 100 0039)\n";

// Optionally check some of them in already, so the lobby is not empty.
$pre = max(0, (int) ($opts['checkins'] ?? 0));
for ($n = 0; $n < $pre; $n++) {
    se_checkin($pdo, se_event_find($pdo, $eventId), $days, [
        'phone' => se_phone_normalize(sprintf('0803%07d', 1000000 + $n)),
    ], ['method' => 'desk', 'actor_user_id' => 1]);
}
if ($pre) echo "[demo] {$pre} already checked in\n";

$state = se_live_state($pdo, $eventId);
se_live_publish($pdo, $eventId, true);

echo "[demo] guest check-in  /e/{$slug}/in\n";
foreach (se_display_links(se_event_find($pdo, $eventId), $state) as $kind => $url) {
    echo "[demo] {$kind} " . $url . "\n";
}
