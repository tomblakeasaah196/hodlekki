<?php
// /tests/bible/run.php
//
// The Bible module test suite. No MySQL and no network: the database half
// runs on in-memory SQLite built from the real migration, and API.Bible is a
// fake transport fed with recorded responses (tests/bible/fixtures/, Berean
// Standard Bible text, public domain / CC0).
//
//     php tests/bible/run.php
//
// Exit code 0 = all good, 1 = at least one failure.

require_once __DIR__ . '/lib.php';

$files = [
    'books_test.php',
    'kjv_data_test.php',
    'ranges_test.php',
    'api_bible_test.php',
    'plans_test.php',
    'db_test.php',
];

foreach ($files as $f) {
    echo "\n== {$f}\n";
    require __DIR__ . '/' . $f;
}

printf("\n%d passed, %d failed\n", $GLOBALS['bt_passed'], $GLOBALS['bt_failed']);
exit($GLOBALS['bt_failed'] > 0 ? 1 : 0);
