<?php
// /tests/permissions/install.php
//
// Gives any SQLite test database the permission schema and the day-one
// matrix, from the REAL migration files: adds the columns the platform
// migration adds (when a suite's stand-ins lack them), creates the perm_*
// tables, applies the role flags and every matrix migration. Used by
// tests/permissions/ and by other suites whose code now asks can()
// (the Hymnary, …). Defines no ok()/eq(), so any suite can load it.

require_once __DIR__ . '/../../includes/permissions.php';

const PERM_TEST_CORE = '20261126090000_perm_core.sql';
const PERM_TEST_PLATFORM = '20261126090100_perm_platform_columns.sql';
const PERM_TEST_SEED = '20261126090200_perm_day_one.sql';
// Matrix cells added after the day-one file shipped (applied in this order).
const PERM_TEST_GRANTS = '20261126090300_perm_monitor_grant.sql';
const PERM_TEST_MATRIX = [PERM_TEST_SEED, PERM_TEST_GRANTS, '20261126090400_perm_pastoral_notes.sql',
    '20261208090200_perm_trainings.sql'];

function perm_test_migration(string $file): string
{
    $sql = (string) file_get_contents(__DIR__ . '/../../db/migrations/' . $file);
    return (string) preg_replace('/^\s*--.*$/m', '', $sql);
}

/** The core migration's CREATE TABLEs, translated to SQLite. */
function perm_test_core_sql(): array
{
    $sql = perm_test_migration(PERM_TEST_CORE);
    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER)) {
        throw new RuntimeException('No CREATE TABLE statements in ' . PERM_TEST_CORE);
    }
    $out = [];
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line)) {
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
            if (str_starts_with($line, 'PRIMARY KEY')) {
                $cols[] = $line;
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = (string) preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        array_push($out, ...$indexes);
    }
    return $out;
}

/** Platform tables as production has them after the platform-columns migration. */
function perm_test_platform_portable(): array
{
    preg_match_all('/^UPDATE roles [^;]*;/m', perm_test_migration(PERM_TEST_PLATFORM), $m);
    return $m[0];
}


/** Add a column to a SQLite table unless it is already there. */
function perm_test_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $have = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if ($have !== [] && !in_array($column, $have, true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

/**
 * Install the permission engine's tables and the day-one matrix into an
 * existing SQLite database that already has users / roles / user_roles /
 * departments / user_departments (with their rows: the seed resolves role and
 * department names as it runs). Points the engine at this database.
 */
function perm_test_install(PDO $pdo): void
{
    foreach ([
        ['roles', 'label', 'TEXT'], ['roles', 'description', 'TEXT'],
        ['roles', 'is_break_glass', 'INTEGER NOT NULL DEFAULT 0'], ['roles', 'is_system', 'INTEGER NOT NULL DEFAULT 0'],
        ['roles', 'is_archived', 'INTEGER NOT NULL DEFAULT 0'], ['roles', 'appoint_rank', 'INTEGER'],
        ['roles', 'sort_order', 'INTEGER NOT NULL DEFAULT 0'],
        ['user_roles', 'is_frozen', 'INTEGER DEFAULT 0'], ['user_roles', 'starts_on', 'TEXT'],
        ['user_roles', 'ends_on', 'TEXT'],
        ['departments', 'parent_id', 'INTEGER'], ['departments', 'type', "TEXT NOT NULL DEFAULT 'Service_Unit'"],
        ['user_departments', 'role_in_dept', "TEXT DEFAULT 'Worker'"],
    ] as [$table, $column, $def]) {
        perm_test_add_column($pdo, $table, $column, $def);
    }
    foreach (perm_test_core_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    foreach (perm_test_platform_portable() as $stmt) {
        $pdo->exec($stmt);
    }
    // The owner rows the platform migration resolves by department type.
    $pdo->exec("INSERT INTO perm_module_owners (module_key, department_id)
                SELECT m.k, d.id FROM departments d
                  JOIN (SELECT 'requisition.disburse' AS k UNION ALL SELECT 'finance.countersign') m
                 WHERE d.name = 'Finance' AND d.type = 'Administrative'");
    foreach (PERM_TEST_MATRIX as $file) {
        $pdo->exec(perm_test_migration($file));
    }
    perm_use_pdo($pdo);
}
