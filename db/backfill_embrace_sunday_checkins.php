<?php
/**
 * Safety-net backfill: makes sure every Embrace-captured first timer is
 * checked in (in the `attendance` table) for the Sunday service they
 * actually walked in for.
 *
 * Why this exists
 * ----------------
 * Adding a First Timer through Embrace (api/embrace_api.php::add_visitor)
 * now auto-checks them in for whichever Sunday/Midweek service is running
 * *at that exact moment* (embrace_auto_checkin_today()). That covers the
 * common case, but it can miss someone if:
 *   - staff enter the visitor's details after the service has technically
 *     ended (is_closed / date rollover),
 *   - the auto-checkin call throws and is swallowed (best-effort by design),
 *   - the record came in through a different intake path that doesn't call
 *     embrace_auto_checkin_today() at all,
 *   - or a visitor is entered days later from a paper card.
 * This script is the reconciliation pass: it never trusts that the
 * real-time hook worked, it just looks at the data and fixes gaps.
 *
 * Attribution rule (exactly as specified)
 * ----------------------------------------
 * An Embrace first-timer record created on a Sunday, or on any day in the
 * six days that follow (Mon–Sat, i.e. "that week before the next Sunday"),
 * is attributed to THAT Sunday. e.g. created Tuesday -> attributed to last
 * Sunday. created on a Sunday -> attributed to that same Sunday.
 *
 * Scope
 * -----
 * - Sunday_Service ONLY (per spec — this is not a Midweek backfill).
 * - Population: users.spiritual_status IN ('1st_Timer','2nd_Timer','3rd_Timer')
 *   — the exact set the Embrace module itself treats as "an active visitor
 *   record" (see the same IN-list guarding api/embrace_api.php::update_visitor).
 *   This intentionally excludes ordinary congregation/admin-added profiles
 *   (api/congregation_api.php) so we never invent attendance for someone
 *   who wasn't actually captured as a visitor.
 * - Never touches event_registrations. Never touches Midweek_Service.
 *
 * Idempotent & safe to run every time
 * ------------------------------------
 * Before writing anything, each (user, event) pair is checked against BOTH
 * `attendance` and `checkins` (the same defensive union the Analytics tab's
 * ea_bulk_attendance_stats() uses) — if they're already accounted for by
 * ANY path, this script does nothing for them. Re-running it (e.g. on every
 * deploy) only ever fills genuine gaps; it never duplicates a row.
 *
 * Usage
 * -----
 *   php db/backfill_embrace_sunday_checkins.php               # apply
 *   php db/backfill_embrace_sunday_checkins.php --dry-run      # report only
 *   php db/backfill_embrace_sunday_checkins.php --since=2026-01-01
 *
 * Wired into bin/deploy.sh (runs after db/migrate.php, on every deploy —
 * unlike schema migrations this is a data-reconciliation job, not a
 * one-time change, so it deliberately is NOT tracked in schema_migrations
 * and is meant to run again and again).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args    = array_slice($argv, 1);
$dryRun  = in_array('--dry-run', $args, true);
$since   = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--since=')) $since = substr($a, 8);
}

$rootDir = dirname(__DIR__);
$envPath = $rootDir . '/.env';

/* ---------------------------------------------------------------------- *
 * Load .env (standalone loader — same as db/migrate.php. Deliberately NOT
 * requiring includes/db.php: that file locates .env via
 * $_SERVER['DOCUMENT_ROOT'], which is unset under the CLI SAPI.)
 * ---------------------------------------------------------------------- */
if (!is_file($envPath)) {
    fwrite(STDERR, "[embrace-backfill] .env not found at {$envPath}\n");
    exit(1);
}
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

if ($db === '' || $user === '') {
    fwrite(STDERR, "[embrace-backfill] DB_NAME / DB_USER missing from .env\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '+01:00';");
} catch (PDOException $e) {
    fwrite(STDERR, "[embrace-backfill] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

date_default_timezone_set('Africa/Lagos');

echo "[embrace-backfill] " . date('Y-m-d H:i:s') . " starting" . ($dryRun ? " (dry run)" : "") . "\n";

/* ---------------------------------------------------------------------- *
 * Helper: does a column exist? (mirrors ea_column_exists — kept local so
 * this script stays runnable standalone with zero app includes.)
 * ---------------------------------------------------------------------- */
function bf_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

/* ---------------------------------------------------------------------- *
 * Who backfilled rows get credited to (attendance.checked_in_by has always
 * been populated with a real users.id elsewhere in this codebase — see
 * api/checkin_api.php's ci_idi_staff_id() — so we follow that precedent
 * instead of risking a NULL that the column may not accept).
 * Preference order: an active Embrace Director/HOD, then the
 * lowest-id Super_Admin, then null (last resort; the insert will fail
 * loudly per-row if the column truly requires a value and neither exists,
 * which is logged and skipped rather than crashing the whole run).
 * ---------------------------------------------------------------------- */
function bf_system_actor_id(PDO $pdo): ?int {
    $stmt = $pdo->query("
        SELECT ud.user_id FROM user_departments ud
        JOIN departments d ON ud.department_id = d.id
        WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
        ORDER BY ud.user_id ASC LIMIT 1
    ");
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt = $pdo->query("
        SELECT ur.user_id FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE r.role_name = 'Super_Admin'
        ORDER BY ur.user_id ASC LIMIT 1
    ");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

$actorId = bf_system_actor_id($pdo);
if ($actorId === null) {
    echo "[embrace-backfill] WARNING: no Embrace Director/HOD or Super_Admin found to credit backfilled rows to; will attempt NULL and skip any row that rejects it.\n";
}

/* ---------------------------------------------------------------------- *
 * Population: active Embrace visitor records (never ordinary
 * congregation/admin-added profiles — those never pass through this
 * status set as their *original* capture status).
 * ---------------------------------------------------------------------- */
$sql = "
    SELECT id, first_name, last_name, created_at
    FROM users
    WHERE spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer')
      AND created_at IS NOT NULL
";
$params = [];
if ($since) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $since;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$visitors = $stmt->fetchAll();

echo "[embrace-backfill] scanning " . count($visitors) . " active Embrace visitor record(s)"
    . ($since ? " created on/after {$since}" : "") . "\n";

$scanned = 0;
$alreadyCovered = 0;
$backfilled = 0;
$unresolved = 0; // no Sunday_Service event found for the attributed date
$failed = 0;

$eventStmt = $pdo->prepare("
    SELECT id, event_date FROM events
    WHERE event_category = 'Sunday_Service' AND DATE(event_date) = ?
    ORDER BY event_date ASC
");
$attCheckStmt = $pdo->prepare("SELECT id FROM attendance WHERE event_id = ? AND user_id = ? LIMIT 1");
$checkinsHasUserId = bf_column_exists($pdo, 'checkins', 'user_id');
$checkinCheckStmt = $checkinsHasUserId
    ? $pdo->prepare("SELECT id FROM checkins WHERE event_id = ? AND user_id = ? LIMIT 1")
    : null;
$insertStmt = $pdo->prepare("
    INSERT INTO attendance (event_id, user_id, status, check_in_time, checked_in_by, attendance_date)
    VALUES (?, ?, 'Present', ?, ?, ?)
");

foreach ($visitors as $v) {
    $scanned++;
    $uid = (int)$v['id'];
    $name = trim($v['first_name'] . ' ' . $v['last_name']);

    $createdDate = date('Y-m-d', strtotime($v['created_at']));
    $dow = (int) date('w', strtotime($createdDate)); // 0 = Sunday ... 6 = Saturday
    $attributedSunday = date('Y-m-d', strtotime("-{$dow} days", strtotime($createdDate)));

    $eventStmt->execute([$attributedSunday]);
    $events = $eventStmt->fetchAll();

    if (!$events) {
        $unresolved++;
        echo "  · no Sunday_Service event on {$attributedSunday} for {$name} (#{$uid}, added {$createdDate}) — skipped\n";
        continue;
    }
    if (count($events) > 1) {
        echo "  · note: {$attributedSunday} has " . count($events) . " Sunday_Service events; using the earliest (id {$events[0]['id']}) for {$name} (#{$uid})\n";
    }
    $event = $events[0];
    $eventId = (int)$event['id'];

    $attCheckStmt->execute([$eventId, $uid]);
    $covered = (bool) $attCheckStmt->fetch();
    if (!$covered && $checkinCheckStmt) {
        $checkinCheckStmt->execute([$eventId, $uid]);
        $covered = (bool) $checkinCheckStmt->fetch();
    }
    if ($covered) {
        $alreadyCovered++;
        continue;
    }

    // Use the visitor's own timestamp if they were added the same day as the
    // service (accurate); otherwise fall back to the service's own
    // start time — we have no better "when" for a late-entered record.
    $checkInTime = ($createdDate === $attributedSunday) ? $v['created_at'] : $event['event_date'];

    if ($dryRun) {
        echo "  + would check in {$name} (#{$uid}) to event #{$eventId} ({$attributedSunday})\n";
        $backfilled++;
        continue;
    }

    try {
        $insertStmt->execute([$eventId, $uid, $checkInTime, $actorId, $attributedSunday]);
        $backfilled++;
        echo "  + checked in {$name} (#{$uid}) to event #{$eventId} ({$attributedSunday})\n";
    } catch (Throwable $e) {
        $failed++;
        error_log("[embrace-backfill] failed to check in user {$uid} for event {$eventId}: " . $e->getMessage());
        echo "  ! FAILED for {$name} (#{$uid}) -> event #{$eventId}: " . $e->getMessage() . "\n";
    }
}

echo "[embrace-backfill] done — scanned={$scanned} already_covered={$alreadyCovered} "
    . ($dryRun ? "would_backfill" : "backfilled") . "={$backfilled} unresolved={$unresolved} failed={$failed}\n";

// Individual row failures are logged but never fail the deploy — a data
// hiccup here shouldn't block shipping code. Only true infra failures
// (DB connection, missing .env) above exit(1) early.
exit(0);
