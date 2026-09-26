<?php
/**
 * DB migration runner — forward-only, filename-ordered SQL migrations.
 *
 * Layout:
 *   db/migrate.php          — this runner (CLI only)
 *   db/migrations/*.sql     — migration files, applied in lexical order
 *
 * File naming convention:
 *   YYYYMMDDhhmmss_short_description.sql
 *   e.g. 20260927150000_add_reach_notes_column.sql
 *
 * Tracking:
 *   Applied migrations are recorded in the `schema_migrations` table.
 *   On first run against an existing prod database, the runner seeds a
 *   `0000_baseline` row so nothing pre-existing runs. Only migrations
 *   whose filename sorts AFTER `0000_baseline` are applied.
 *
 * Usage:
 *   php db/migrate.php               # apply all pending
 *   php db/migrate.php --status      # list pending / applied
 *   php db/migrate.php --dry-run     # print what would run, don't execute
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args      = array_slice($argv, 1);
$dryRun    = in_array('--dry-run', $args, true);
$statusOnly = in_array('--status', $args, true);

$rootDir       = dirname(__DIR__);
$envPath       = $rootDir . '/.env';
$migrationsDir = __DIR__ . '/migrations';

/* ---------------------------------------------------------------------- *
 * Load .env
 * ---------------------------------------------------------------------- */
if (!is_file($envPath)) {
    fwrite(STDERR, "[migrate] .env not found at {$envPath}\n");
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
    fwrite(STDERR, "[migrate] DB_NAME / DB_USER missing from .env\n");
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * Connect
 * ---------------------------------------------------------------------- */
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
    fwrite(STDERR, "[migrate] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * Ensure tracking table + baseline
 * ---------------------------------------------------------------------- */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_id VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$baselined = (int) $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations WHERE migration_id = '0000_baseline'"
)->fetchColumn();

if ($baselined === 0) {
    echo "[migrate] seeding baseline row (existing schema is treated as already applied)\n";
    if (!$dryRun) {
        $pdo->prepare("INSERT INTO schema_migrations (migration_id) VALUES ('0000_baseline')")->execute();
    }
}

/* ---------------------------------------------------------------------- *
 * Collect migration files
 * ---------------------------------------------------------------------- */
if (!is_dir($migrationsDir)) {
    echo "[migrate] no db/migrations/ directory; nothing to do\n";
    exit(0);
}
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$applied = array_flip(
    $pdo->query("SELECT migration_id FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN)
);

$pending = [];
foreach ($files as $path) {
    $id = pathinfo($path, PATHINFO_FILENAME);
    if ($id === '0000_baseline') continue;      // baseline is seeded above
    if (isset($applied[$id])) continue;         // already applied
    if (strcmp($id, '0000_baseline') <= 0) continue; // guard against ids that sort before baseline
    $pending[$id] = $path;
}

/* ---------------------------------------------------------------------- *
 * Status mode
 * ---------------------------------------------------------------------- */
if ($statusOnly) {
    echo "\nApplied:\n";
    foreach (array_keys($applied) as $id) {
        echo "  ✓ {$id}\n";
    }
    echo "\nPending:\n";
    if (!$pending) {
        echo "  (none)\n";
    } else {
        foreach (array_keys($pending) as $id) {
            echo "  · {$id}\n";
        }
    }
    exit(0);
}

/* ---------------------------------------------------------------------- *
 * Apply pending
 * ---------------------------------------------------------------------- */
if (!$pending) {
    echo "[migrate] nothing pending; DB is up to date\n";
    exit(0);
}

echo "[migrate] applying " . count($pending) . " migration(s)" . ($dryRun ? " (dry run)" : "") . "\n";

$insert = $pdo->prepare("INSERT INTO schema_migrations (migration_id) VALUES (:id)");

foreach ($pending as $id => $path) {
    echo "  → {$id} ... ";
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        echo "SKIP (empty file)\n";
        continue;
    }

    if ($dryRun) {
        echo "would run (" . strlen($sql) . " bytes)\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        // PDO::exec handles multi-statement SQL as long as each statement is
        // terminated with ';'. Keep migrations to plain DDL/DML — no
        // DELIMITER blocks — or split them across files.
        $pdo->exec($sql);
        $insert->execute([':id' => $id]);
        // MySQL implicitly commits on DDL (CREATE/ALTER/DROP), which ends the
        // transaction; commit()/rollBack() would then throw on PHP 8.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo "OK\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "FAILED\n";
        fwrite(STDERR, "[migrate] {$id} failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "[migrate] done\n";
