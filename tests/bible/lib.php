<?php
// /tests/bible/lib.php
//
// Shared helpers for the Bible module tests: a tiny assertion counter and an
// in-memory SQLite database built from the module's real migration.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../../includes/bible/bootstrap.php';
date_default_timezone_set('Africa/Lagos');

$GLOBALS['bt_passed'] = 0;
$GLOBALS['bt_failed'] = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['bt_passed']++;
        return;
    }
    $GLOBALS['bt_failed']++;
    echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

function section(string $name): void
{
    echo "· {$name}\n";
}

/**
 * Translate db/migrations/20261114090000_bible_core.sql to SQLite, so the
 * tests run against the same columns, keys and defaults production gets.
 */
function bible_test_schema_sql(): array
{
    $sql = (string) file_get_contents(__DIR__ . '/../../db/migrations/20261114090000_bible_core.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $out = [];

    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER)) {
        throw new RuntimeException('No CREATE TABLE statements found in the migration.');
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
            if (preg_match('/^PRIMARY KEY \((\w+)\)$/', $line, $m) && $autoId) {
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
                $col = strtok($line, ' ');
                $cols[] = "{$col} INTEGER PRIMARY KEY AUTOINCREMENT";
                $autoId = true;
                continue;
            }
            $line = preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = preg_replace('/\b(MEDIUMTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        foreach ($indexes as $ix) {
            $out[] = $ix;
        }
    }

    return $out;
}

function bible_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (bible_test_schema_sql() as $stmt) {
        $pdo->exec($stmt);
    }

    return $pdo;
}
