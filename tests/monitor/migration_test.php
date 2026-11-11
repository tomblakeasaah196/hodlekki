<?php
// /tests/monitor/migration_test.php — the migration's shape and rules (db/migrations/README.md).

$path = __DIR__ . '/../../db/migrations/' . MONITOR_MIGRATION;
$sql = (string) file_get_contents($path);
$files = array_map('basename', glob(__DIR__ . '/../../db/migrations/*.sql') ?: []);
sort($files);

ok('the migration exists', is_file($path));
ok('its file name carries the _monitor_ marker the MySQL/MariaDB integration job requires', str_contains(MONITOR_MIGRATION, '_monitor_'));
ok('it sorts after every migration that was there before it', array_search(MONITOR_MIGRATION, $files, true) > array_search('20261124090000_auth_remember_tokens.sql', $files, true));

preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
$tables = $m[1];
eq('it creates the 14 monitor tables', [
    'monitor_alert_log', 'monitor_check_history', 'monitor_checks', 'monitor_error_events', 'monitor_error_groups', 'monitor_error_hourly',
    'monitor_error_notes', 'monitor_error_paths', 'monitor_error_people', 'monitor_probes', 'monitor_recipients', 'monitor_rum',
    'monitor_rum_daily', 'monitor_state',
], (function ($t) { sort($t); return $t; })($tables));
ok('every table is the monitor\'s own (prefixed monitor_)', count(array_filter($tables, fn ($t) => !str_starts_with($t, 'monitor_'))) === 0);
eq('every CREATE is idempotent (IF NOT EXISTS)', substr_count($sql, 'CREATE TABLE IF NOT EXISTS'), substr_count($sql, 'CREATE TABLE'));
ok('no ALTER of a platform table, no DROP, no DELIMITER', !preg_match('/\b(ALTER\s+TABLE|DROP\s+|DELIMITER)\b/i', $sql));
$code = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
ok('no NOW() or CURRENT_TIMESTAMP defaults (times come from PHP)', !preg_match('/NOW\(\)|CURRENT_TIMESTAMP/i', $code));

$db = monitor_test_db();
foreach ($tables as $t) {
    ok("SQLite translation created {$t}", monitor_table_exists($db, $t));
}
$cols = monitor_test_columns($db, 'monitor_error_events');
ok('events keep a role and a hashed person, never a user id, name, phone or IP column',
    in_array('role', $cols, true) && in_array('user_hash', $cols, true)
    && !array_intersect(['user_id', 'ip', 'ip_address', 'email', 'phone', 'name', 'user_agent'], $cols));
