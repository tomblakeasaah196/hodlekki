<?php
// /db/backfill_status_ladder.php
/**
 * Catch-up pass for the Sunday ladder.
 *
 * Clock-ins move a 1st/2nd/3rd Timer up by themselves (includes/status_ladder.php),
 * but only from the day that code was deployed. This script brings everyone who
 * is already on the ladder to the step their Sunday attendance has earned --
 * the person with three Sundays on the books who still reads "1st Timer".
 *
 * Rules (the same engine the clock-in uses, so the two can never disagree)
 *   - A "time" is one calendar day present at a SUNDAY service.
 *   - 1 Sunday = 1st Timer, 2 = 2nd, 3 = 3rd, 4 or more = Visitor.
 *   - Forward only. Only current 1st/2nd/3rd Timers are looked at. Members,
 *     Workers, Pastors, Non-Members and Visitors are never touched, nor is
 *     anyone marked Relocated / Attends Another Church (or awol_exempt).
 *   - Each move is written to user_status_history, so Embrace keeps a graduated
 *     Visitor on its active list until a follow-up report is logged, and past
 *     event reports keep showing the status the person had that day.
 *
 * Idempotent and safe to run every time
 *   Running it again finds nothing left to move. Like
 *   db/backfill_embrace_sunday_checkins.php it is wired into bin/deploy.sh and
 *   re-runs on every deploy as a standing safety net: it also catches a
 *   clock-in path that never called the engine. It is NOT tracked in
 *   schema_migrations.
 *
 * Usage
 *   php db/backfill_status_ladder.php             # apply
 *   php db/backfill_status_ladder.php --dry-run   # list who would move, change nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);

$rootDir = dirname(__DIR__);
$envPath = $rootDir . '/.env';

/* ---------------------------------------------------------------------- *
 * Load .env (standalone loader — same as db/migrate.php. Deliberately NOT
 * requiring includes/db.php: that file locates .env via
 * $_SERVER['DOCUMENT_ROOT'], which is unset under the CLI SAPI.)
 * ---------------------------------------------------------------------- */
if (!is_file($envPath)) {
    fwrite(STDERR, "[status-ladder] .env not found at {$envPath}\n");
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
    fwrite(STDERR, "[status-ladder] DB_NAME / DB_USER missing from .env\n");
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
    fwrite(STDERR, "[status-ladder] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

date_default_timezone_set('Africa/Lagos');

require_once $rootDir . '/includes/status_ladder.php';

echo "[status-ladder] " . date('Y-m-d H:i:s') . " starting" . ($dryRun ? " (dry run)" : "") . "\n";

try {
    $result = ladder_sweep($pdo, !$dryRun);
} catch (Throwable $e) {
    // A data hiccup here must not block shipping code.
    error_log('[status-ladder] sweep failed: ' . $e->getMessage());
    echo "[status-ladder] sweep failed: " . $e->getMessage() . "\n";
    exit(0);
}

if (!$result['ready']) {
    echo "[status-ladder] user_status_history does not exist yet (db/migrate.php has not run) — nothing to do\n";
    exit(0);
}

foreach ($result['moved'] as $m) {
    echo "  " . ($dryRun ? "would move" : "moved") . " {$m['name']} (#{$m['id']}): "
        . "{$m['from']} -> {$m['to']} ({$m['days']} Sunday" . ($m['days'] === 1 ? '' : 's') . ")\n";
}

echo "[status-ladder] done — scanned={$result['scanned']} "
    . ($dryRun ? "would_move" : "moved") . "=" . count($result['moved'])
    . " unchanged={$result['unchanged']} departed={$result['departed']} failed={$result['failed']}\n";

// Individual failures are logged by the engine and never fail the deploy.
exit(0);
