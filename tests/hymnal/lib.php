<?php
// /tests/hymnal/lib.php
//
// Shared helpers for the Hymnary tests: an assertion counter, and an in-memory
// SQLite database built from the module's real migration so the tests run
// against the same columns, keys and defaults production gets.
//
// No MySQL and no network. The AI layer is never called — hymnal_ai_read_song()
// refuses without a key, which is the behaviour the suite checks.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../../includes/hymnal/bootstrap.php';
date_default_timezone_set('Africa/Lagos');

$GLOBALS['ht_passed'] = 0;
$GLOBALS['ht_failed'] = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['ht_passed']++;

        return;
    }
    $GLOBALS['ht_failed']++;
    echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function eq(string $label, $expected, $actual): void
{
    ok(
        $label,
        $expected === $actual,
        'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
    );
}

function section(string $name): void
{
    echo "· {$name}\n";
}

const HYMNAL_MIGRATION = '20261120090000_hymnal_core.sql';

/**
 * Translate the module's migration to SQLite.
 *
 * Deliberately a translation of the real file rather than a hand-written
 * schema: a test schema that has drifted from production proves nothing.
 *
 * @return list<string>
 */
function hymnal_test_schema_sql(): array
{
    $path = __DIR__ . '/../../db/migrations/' . HYMNAL_MIGRATION;
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $out = [];

    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER)) {
        throw new RuntimeException('No CREATE TABLE statements found in ' . HYMNAL_MIGRATION);
    }
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^PRIMARY KEY \((\w+)\)$/', $line) && $autoId) {
                continue;   // folded into the AUTOINCREMENT column
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
            $line = preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = preg_replace('/\b(LONGTEXT|MEDIUMTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        foreach ($indexes as $ix) {
            $out[] = $ix;
        }
    }

    return $out;
}

/**
 * The few platform tables the module reads but does not own: departments and
 * roles, for the Studio clearance check. Stand-ins with just the columns
 * rights.php touches — the real ones live in earlier migrations.
 */
function hymnal_test_platform_sql(): array
{
    return [
        'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        'CREATE TABLE user_departments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, department_id INTEGER, is_active INTEGER DEFAULT 1)',
        'CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT NOT NULL)',
        'CREATE TABLE user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0)',
    ];
}

function hymnal_test_db(bool $withPlatform = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (hymnal_test_schema_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    if ($withPlatform) {
        foreach (hymnal_test_platform_sql() as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->exec("INSERT INTO departments (id, name) VALUES (4, 'River of Life')");
        $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor'), (4, 'Church_Member')");
    }

    return $pdo;
}

/** Put a member in River of Life. */
function hymnal_test_give_music(PDO $pdo, int $userId): void
{
    $pdo->prepare('INSERT INTO user_departments (user_id, department_id, is_active) VALUES (?, 4, 1)')
        ->execute([$userId]);
}

/** Give a member a role by name. */
function hymnal_test_give_role(PDO $pdo, int $userId, string $role): void
{
    $id = $pdo->prepare('SELECT id FROM roles WHERE role_name = ?');
    $id->execute([$role]);
    $roleId = (int) $id->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES (?, ?, 0)')
        ->execute([$userId, $roleId]);
}

/** A complete, valid song payload the Studio could have sent. */
function hymnal_test_song_input(array $override = []): array
{
    return $override + [
        'title'        => 'Baruch Hashem Adonai',
        'writer'       => 'Traditional',
        'licence_code' => 'CHURCH',
        'collections'  => ['worship'],
        'lang'         => 'en',
        'song_key'     => 'D',
        'time_sig'     => '4/4',
        'sections'     => [
            ['kind' => 'verse', 'lines' => ['Baruch Hashem Adonai', 'Blessed be the name of the Lord']],
            ['kind' => 'chorus', 'lines' => ['Blessed be the Lord most high']],
        ],
    ];
}
