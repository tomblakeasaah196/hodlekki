<?php
// /tests/special_events/smoke_seed.php
//
// Seeds one published event at /e/smoke, so the portal can be smoke-tested
// end to end (guide §22.3, Appendix H.2) against a database built by
// db_setup.php. Registration is open, there are 50 online seats, the
// waitlist and self-cancelling are on, and the seats-left counter is always
// shown — i.e. every behaviour PR2 adds is reachable from the page.
//
// CLI only, and it never reads .env: the DSN comes from SE_TEST_DB_*, the
// same variables db_setup.php uses.
//
// Usage, from the repository root:
//   php tests/special_events/db_setup.php --fresh --seed --db=se_test …
//   php tests/special_events/smoke_seed.php
//   php -S 127.0.0.1:8099 -t . tests/special_events/dev_router.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$socket = getenv('SE_TEST_DB_SOCKET') ?: '';
$host   = getenv('SE_TEST_DB_HOST') ?: '127.0.0.1';
$port   = (int) (getenv('SE_TEST_DB_PORT') ?: 3306);
$user   = getenv('SE_TEST_DB_USER') ?: 'root';
$pass   = getenv('SE_TEST_DB_PASS') ?: '';
$name   = getenv('SE_TEST_DB_NAME') ?: 'se_test';
$slug   = getenv('SE_SMOKE_SLUG') ?: 'smoke';

$dsn = $socket !== ''
    ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec("SET time_zone = '+01:00'");

// Start clean, so re-running the smoke never doubles the fixtures. The
// children go first: se_karaoke_entries is ON DELETE RESTRICT (Appendix A).
$stmt = $pdo->prepare("SELECT id FROM se_events WHERE slug = ?");
$stmt->execute([$slug]);
$existing = (int) ($stmt->fetchColumn() ?: 0);

if ($existing > 0) {
    foreach (['se_karaoke_entries', 'se_registrations', 'se_event_days', 'se_audit_log'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE event_id = ?")->execute([$existing]);
    }
    $pdo->prepare("DELETE FROM se_events WHERE id = ?")->execute([$existing]);
    echo "[se-smoke] removed the previous /e/{$slug}\n";
}

$tz    = new DateTimeZone('Africa/Lagos');
$start = (new DateTimeImmutable('+14 days', $tz))->setTime(17, 0);
$end   = $start->modify('+4 hours');

$pdo->prepare(
    "INSERT INTO se_events
        (public_id, preview_key, slug, title, tagline, description_md,
         organizer_label, venue_name, venue_address,
         starts_at, ends_at, status, visibility, published_at,
         online_capacity, auto_close_at_capacity,
         waitlist_enabled, waitlist_promotion, self_cancel_enabled,
         walkin_enabled, walkin_capacity, seats_left_mode, created_by)
     VALUES (?, ?, ?, 'Chara', 'One night. Everyone you have been meaning to invite.',
             ?, 'Envision', 'HOD Lekki Centre', '12 Admiralty Way, Lekki Phase 1, Lagos',
             ?, ?, 'published', 'public', NOW(),
             50, 1, 1, 'auto_confirm', 1, 1, 30, 'always', 1)"
)->execute([
    substr(bin2hex(random_bytes(8)), 0, 12),
    substr(bin2hex(random_bytes(16)), 0, 22),
    $slug,
    "An evening of games, karaoke and far too much jollof.\n\n"
        . "Bring a friend who has never been to church — that is the whole point.",
    $start->format('Y-m-d H:i:s'),
    $end->format('Y-m-d H:i:s'),
]);

$eventId = (int) $pdo->lastInsertId();

// /e/<slug> resolves through se_slugs, exactly as an event created in the
// Studio does (se_event_create()); without this row the portal is a 404.
$pdo->prepare("INSERT INTO se_slugs (slug, event_id, is_canonical) VALUES (?, ?, 1)")
    ->execute([$slug, $eventId]);

$pdo->prepare(
    "INSERT INTO se_event_days (event_id, day_date, doors_open_at, starts_at, ends_at, checkin_closes_at)
     VALUES (?, ?, ?, ?, ?, ?)"
)->execute([
    $eventId,
    $start->format('Y-m-d'),
    $start->modify('-60 minutes')->format('Y-m-d H:i:s'),
    $start->format('Y-m-d H:i:s'),
    $end->format('Y-m-d H:i:s'),
    $end->format('Y-m-d H:i:s'),
]);

echo "[se-smoke] /e/{$slug} is published with 50 seats, starting {$start->format('D j M Y, H:i')}\n";
