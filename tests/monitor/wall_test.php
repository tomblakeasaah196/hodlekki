<?php
// /tests/monitor/wall_test.php — the live wall's snapshot: useful, and private.

$db = monitor_test_db();
$now = monitor_test_clock('2026-11-11 10:00:00');
monitor_test_record($db, ['kind' => 'exception', 'level' => 'fatal', 'exception_class' => 'Error', 'message' => 'Call to undefined function x()', 'line' => 3]);
monitor_test_record($db, ['level' => 'notice', 'message' => 'A notice', 'line' => 4]);
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'warn', '88% used')], $now);

$snap = monitor_wall_snapshot($db, $now);
eq('the overall state is the worst check', 'warn', $snap['overall']);
eq('one open problem (notices are not on the wall)', 1, $snap['errors']['open']);
eq('a 24-hour sparkline', 24, count($snap['errors']['spark_24h']));
eq('this hour\'s count is the last bar', 1, $snap['errors']['last_hour']);
eq('the latest problem is listed with its people', [1, 'fatal'], [$snap['latest'][0]['people_24h'], $snap['latest'][0]['level']]);
$json = json_encode($snap);
foreach (['user_hash', 'Church_Member', '102.89', 'AppleWebKit'] as $private) {
    lacks('the wall carries no ' . $private, $json, $private);
}

$key = monitor_wall_key($db);
ok('the wall\'s key is 32 hex characters', (bool) preg_match('/^[0-9a-f]{32}$/', $key));
eq('the key is stable', $key, monitor_wall_key($db));
ok('publishing writes the snapshot', monitor_wall_publish($db, true) && is_file(monitor_wall_path($key)));
eq('…not again within 5 seconds', false, monitor_wall_publish($db, false));
$new = monitor_wall_key($db, true);
ok('a new key replaces the old file (old TV links stop)', $new !== $key && !is_file(monitor_wall_path($key)));
ok('the snapshot file name never leaves live/ through a crafted key', !str_contains(monitor_wall_path('../../x'), '..'));
