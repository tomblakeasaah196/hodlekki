<?php
// /tests/hymnal/run.php
//
// The Hymnary test suite. No MySQL and no network: the database half runs on
// in-memory SQLite built from the real migration, and the AI layer is only
// exercised through its refusals.
//
//     php tests/hymnal/run.php
//
// Exit code 0 = all good, 1 = at least one failure.

require_once __DIR__ . '/lib.php';

$files = [
    'solfa_test.php',
    'function_calls_test.php',
    'corpus_test.php',
    'library_test.php',
    'db_test.php',
];

foreach ($files as $f) {
    echo "\n== {$f}\n";
    require __DIR__ . '/' . $f;
}

printf("\n%d passed, %d failed\n", $GLOBALS['ht_passed'], $GLOBALS['ht_failed']);
exit($GLOBALS['ht_failed'] > 0 ? 1 : 0);
