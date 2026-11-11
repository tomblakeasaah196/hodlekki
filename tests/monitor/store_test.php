<?php
// /tests/monitor/store_test.php — grouping, counting, regressions, spikes, the spool and the flush.

$db = monitor_test_db();
monitor_test_clock('2026-11-11 10:15:00');

// ---- Grouping
$a = monitor_test_record($db);
$b = monitor_test_record($db, ['request' => ['user_id' => 7, 'uri' => '/modules/events/report.php?id=99']]);
ok('the first occurrence opens a new problem', $a['is_new']);
ok('the same message from the same place is the same problem', !$b['is_new'] && $a['group_id'] === $b['group_id']);
$c = monitor_test_record($db, ['line' => 121, 'frames' => [['line' => 121]]]);
ok('another line is another problem', $c['group_id'] !== $a['group_id']);
$d1 = monitor_test_record($db, ['kind' => 'log', 'level' => 'error', 'message' => 'Payment 4411 for member 8341 failed', 'file' => null, 'line' => null, 'frames' => []]);
$d2 = monitor_test_record($db, ['kind' => 'log', 'level' => 'error', 'message' => 'Payment 17 for member 42 failed', 'file' => null, 'line' => null, 'frames' => []]);
ok('numbers in a message do not split a problem', $d1['group_id'] === $d2['group_id']);
ok('a reference code looks like E7KQ2M9X', (bool) preg_match('/^E[A-HJ-NP-Z2-9]{7}$/', $a['ref']));

$g = monitor_group($db, $a['group_id']);
eq('two occurrences counted', 2, (int) $g['occurrences']);
eq('status starts as new', 'new', $g['status']);
eq('the file is stored relative to the site', 'modules/events/index.php', $g['file']);
eq('the route is the screen, not the query', '/modules/events/index.php', $g['route']);
has('the trace is kept, without arguments', (string) $g['trace'], 'modules/events/index.php:120 render_row()');

$events = $db->query('SELECT * FROM monitor_error_events WHERE group_id = ' . (int) $a['group_id'])->fetchAll();
eq('each occurrence has its own row', 2, count($events));
eq('the role is kept', 'Church_Member', $events[0]['role']);
eq('the page keeps its parameter names only', '/modules/events/index.php?id=…', $events[0]['path']);
eq('the browser is a family', 'Chrome 131 · Android', $events[0]['browser']);
eq('the device is a class', 'phone', $events[0]['device']);
ok('the person is a 16-character keyed hash', (bool) preg_match('/^[0-9a-f]{16}$/', (string) $events[0]['user_hash']));
$everything = json_encode($db->query('SELECT * FROM monitor_error_events')->fetchAll()) . json_encode($db->query('SELECT * FROM monitor_error_groups')->fetchAll());
lacks('no IP address is stored anywhere', $everything, '102.89.1.1');
lacks('no user agent is stored whole', $everything, 'AppleWebKit');
eq('two different people counted', 2, (int) $db->query('SELECT COUNT(DISTINCT user_hash) FROM monitor_error_people WHERE group_id = ' . (int) $a['group_id'])->fetchColumn());
eq('the pages it happened on are counted', 2, (int) $db->query('SELECT COUNT(*) FROM monitor_error_paths WHERE group_id = ' . (int) $a['group_id'])->fetchColumn());

// ---- A visitor who is not signed in is counted for the day only
$g1 = monitor_prepare_event(monitor_test_raw(['request' => ['user_id' => null, 'role' => 'guest']]));
monitor_test_clock('2026-11-12 10:15:00');
$g2 = monitor_prepare_event(monitor_test_raw(['request' => ['user_id' => null, 'role' => 'guest']]));
ok('a guest is counted by a hash that changes every day', $g1['user_hash'] !== null && $g1['user_hash'] !== $g2['user_hash']);
monitor_test_clock('2026-11-11 10:15:00');

// ---- Fixed, then back: a regression
$db->prepare("UPDATE monitor_error_groups SET status = 'fixed', fixed_at = ?, fixed_release = 'abc1234', alerted_at = ? WHERE id = ?")
   ->execute(['2026-11-11 10:20:00', '2026-11-11 10:16:00', $a['group_id']]);
monitor_test_clock('2026-11-11 11:00:00');
$back = monitor_test_record($db);
$g = monitor_group($db, $a['group_id']);
ok('it happened again after the fix: came back', $back['came_back']);
eq('reopened as new', 'new', $g['status']);
eq('the regression is counted', 1, (int) $g['regressions']);
ok('it will be announced again', $g['alerted_at'] === null);
has('a note says when and since which release', (string) $db->query('SELECT body FROM monitor_error_notes WHERE group_id = ' . (int) $a['group_id'])->fetchColumn(), 'release abc1234');
$db->prepare("UPDATE monitor_error_groups SET status = 'ignored' WHERE id = ?")->execute([$c['group_id']]);
monitor_test_record($db, ['line' => 121, 'frames' => [['line' => 121]]]);
eq('an ignored problem stays ignored', 'ignored', monitor_group($db, $c['group_id'])['status']);

// ---- A noisy problem: counted every time, at most 20 rows an hour (fatal: always)
monitor_test_clock('2026-11-11 12:05:00');
for ($i = 0; $i < 30; $i++) {
    $noisy = monitor_test_record($db, ['message' => 'Deprecated thing', 'level' => 'deprecated', 'line' => 5, 'frames' => []]);
}
eq('30 occurrences counted', 30, (int) monitor_group($db, $noisy['group_id'])['occurrences']);
eq('20 rows kept for the hour', 20, (int) $db->query('SELECT COUNT(*) FROM monitor_error_events WHERE group_id = ' . (int) $noisy['group_id'])->fetchColumn());
eq('the hour counts all 30', 30, (int) $db->query('SELECT n FROM monitor_error_hourly WHERE group_id = ' . (int) $noisy['group_id'])->fetchColumn());
for ($i = 0; $i < 25; $i++) {
    $fatal = monitor_test_record($db, ['message' => 'Allowed memory size exhausted', 'level' => 'fatal', 'line' => 9, 'frames' => []]);
}
eq('every fatal occurrence keeps its row', 25, (int) $db->query('SELECT COUNT(*) FROM monitor_error_events WHERE group_id = ' . (int) $fatal['group_id'])->fetchColumn());

// ---- Keeping the latest 50
for ($h = 0; $h < 4; $h++) {
    monitor_test_clock('2026-11-11 ' . (13 + $h) . ':05:00');
    for ($i = 0; $i < 20; $i++) {
        $kept = monitor_test_record($db, ['message' => 'Kept rows', 'line' => 77, 'frames' => []]);
    }
}
eq('at most 50 occurrences are kept per problem', 50, (int) $db->query('SELECT COUNT(*) FROM monitor_error_events WHERE group_id = ' . (int) $kept['group_id'])->fetchColumn());

// ---- A spike: 30 in the hour against a quiet day
monitor_test_clock('2026-11-12 09:30:00');
for ($i = 0; $i < 30; $i++) {
    $spike = monitor_test_record($db, ['message' => 'Suddenly everywhere', 'line' => 88, 'frames' => []]);
}
$sg = monitor_group($db, $spike['group_id']);
ok('a sudden burst is noted as a spike', $sg['spiked_at'] !== null);
has('the spike note gives the numbers', (string) $db->query("SELECT body FROM monitor_error_notes WHERE group_id = " . (int) $spike['group_id'] . " AND body LIKE 'Spiking%'")->fetchColumn(), '20 times in the last hour');
// A burst found in old log lines is history, not a spike.
for ($i = 0; $i < 30; $i++) {
    $past = monitor_test_record($db, ['message' => 'Busy last night', 'line' => 89, 'frames' => [], 'at' => monitor_time() - 5 * 3600 + $i]);
}
ok('a burst in lines hours old is not a spike', monitor_group($db, $past['group_id'])['spiked_at'] === null);

// ---- Upserts on both spellings
monitor_state_set($db, 'k1', 'one');
monitor_state_set($db, 'k1', 'two');
eq('monitor_state upserts', 'two', monitor_state_get($db, 'k1'));
eq('a missing key gives the default', 'd', monitor_state_get($db, 'nope', 'd'));

// ---- The spool
$ev = monitor_prepare_event(monitor_test_raw(['message' => 'Spooled while the database was down', 'line' => 3]));
ok('the spool takes records', monitor_spool_write('events', [$ev]));
$got = [];
$n = monitor_spool_import('events', function (array $record) use (&$got) { $got[] = $record; });
eq('the spool gives them back once', 1, $n);
eq('…unchanged', $ev['fingerprint'], $got[0]['fingerprint'] ?? null);
eq('…and is empty afterwards', 0, monitor_spool_import('events', fn () => null));
$stored = monitor_store_all($db, $got);
eq('spooled records store like any other', 1, $stored);

// ---- The flush after a response
$fresh = monitor_test_db();
monitor_db_set($fresh);
monitor_flush_request(['cli' => true, 'buffer' => [monitor_test_raw(['message' => 'Flushed after the response'])]]);
eq('the flush writes to the monitor\'s own connection', 1, (int) $fresh->query("SELECT COUNT(*) FROM monitor_error_groups WHERE title = 'Flushed after the response'")->fetchColumn());
monitor_flush_request(['cli' => true, 'buffer' => [monitor_test_raw(['kind' => 'log', 'level' => 'fatal', 'message' => 'Database Connection Failed: SQLSTATE[HY000] [2002] No such file'])]]);
eq('a database outage goes to the spool, not to the database', 0, (int) $fresh->query("SELECT COUNT(*) FROM monitor_error_groups WHERE title LIKE 'Database Connection Failed%'")->fetchColumn());
eq('…where the cron will find it', 1, monitor_spool_import('events', fn (array $r) => monitor_store_all($fresh, [$r])));

// ---- One bad record never blocks the others, and nothing is written twice (review, 11 Nov)
eq('a line number beyond an INT column is dropped, not stored', null, monitor_prepare_event(monitor_test_raw(['line' => '3000000000']))['line']);
eq('…a sane one is kept', 12, monitor_int_or_null('12'));
monitor_test_refuse($fresh, 'refuse_one', 'Refuse me', 'refused for the test');
$three = array_map(fn ($m) => monitor_prepare_event(monitor_test_raw(['message' => $m, 'line' => 900])), ['First of three', 'Refuse me', 'Third of three']);
$handled = 0;
eq('a record the database refuses is skipped; the others are written', 2, monitor_store_all($fresh, $three, $handled));
eq('…and all three count as handled (nothing to retry)', 3, $handled);

// The database goes away part-way: only what was not written is kept for later.
monitor_test_refuse($fresh, 'lock_one', 'Locked out', 'database is locked');
monitor_db_set($fresh);
monitor_flush_request(['cli' => true, 'buffer' => [monitor_test_raw(['message' => 'Before the outage', 'line' => 901]),
    monitor_test_raw(['message' => 'Locked out', 'line' => 902]), monitor_test_raw(['message' => 'After the outage', 'line' => 903])]]);
eq('written before the database went away', 1, (int) $fresh->query("SELECT COUNT(*) FROM monitor_error_groups WHERE title = 'Before the outage'")->fetchColumn());
$respooled = [];
monitor_spool_import('events', function (array $r) use (&$respooled) { $respooled[] = $r['title']; });
eq('only the two not yet written were spooled', ['Locked out', 'After the outage'], $respooled);
@unlink($GLOBALS['mt_dir'] . '/db_down');

// The import stops when the database goes away, and keeps what it did not hand over.
monitor_spool_write('events', array_map(fn ($m) => monitor_prepare_event(monitor_test_raw(['message' => $m, 'line' => 950])), ['One', 'Two', 'Three']));
$seen = [];
$first = monitor_spool_import('events', function (array $r) use (&$seen) {
    if ($r['title'] === 'Two') {
        throw new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');
    }
    $seen[] = $r['title'];
});
eq('the import hands over what it can, then stops', [1, ['One']], [$first, $seen]);
$later = [];
monitor_spool_import('events', function (array $r) use (&$later) { $later[] = $r['title']; });
eq('…and the next run gets the rest, once', ['Two', 'Three'], $later);

// Anyone can send browser reports: at most 200 new browser problems an hour.
$quota = monitor_test_db();
monitor_test_clock('2026-11-13 10:00:00');
$opened = 0;
for ($i = 0; $i < 205; $i++) {
    $opened += monitor_store_all($quota, [monitor_test_raw(['kind' => 'js', 'level' => 'error', 'message' => 'Injected ' . str_repeat('x', $i % 7) . chr(65 + $i % 26) . ($i * 7919), 'line' => $i, 'frames' => []])]);
}
eq('a flood of unique browser reports opens at most 200 problems an hour', 200, (int) $quota->query("SELECT COUNT(*) FROM monitor_error_groups WHERE kind = 'js'")->fetchColumn());
monitor_store_all($quota, [monitor_test_raw(['kind' => 'php', 'level' => 'error', 'message' => 'A real server error during the flood', 'line' => 7])]);
eq('…while server errors are still recorded', 1, (int) $quota->query("SELECT COUNT(*) FROM monitor_error_groups WHERE kind = 'php'")->fetchColumn());
monitor_db_set(null);
