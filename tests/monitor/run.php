<?php
// /tests/monitor/run.php
//
// The system monitor's test suite (docs/monitor.md). No MySQL, no network, no
// email: the database half runs on SQLite built from the real migration, and
// the capture is run for real in child PHP processes.
//
//     php tests/monitor/run.php
//
// Exit code 0 = all good, 1 = at least one failure.

require_once __DIR__ . '/lib.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "pdo_sqlite is missing: the monitor's database tests cannot run.\n";
    exit(1);
}

$files = [
    'migration_test.php',
    'redact_test.php',
    'store_test.php',
    'capture_test.php',
    'logscan_test.php',
    'checks_test.php',
    'intake_test.php',
    'probe_test.php',
    'alerts_test.php',
    'brief_test.php',
    'wall_test.php',
    'function_calls_test.php',
];

foreach ($files as $f) {
    echo "\n== {$f}\n";
    unset($GLOBALS['monitor_test_now'], $GLOBALS['monitor_db_override']);
    require __DIR__ . '/' . $f;
}

printf("\n%d passed, %d failed\n", $GLOBALS['mt_passed'], $GLOBALS['mt_failed']);
exit($GLOBALS['mt_failed'] > 0 ? 1 : 0);
