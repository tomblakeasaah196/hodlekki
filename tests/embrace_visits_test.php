<?php
// /tests/embrace_visits_test.php
//
// "When did they come to church?" on the Embrace list (Embrace team,
// 24 Nov 2026): embrace_visits_for_visitors() in includes/embrace_helpers.php,
// executed against in-memory SQLite. Every service counts, one day per person
// per calendar day (checkins and attendance together, as
// assim_attendance_union_sql() counts), each day named by what they came to.
//
// Run from the repo root:
//     php tests/embrace_visits_test.php
//
// Exit code 0 = all good, 1 = at least one failure. CLI only.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

require_once __DIR__ . '/../includes/embrace_helpers.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT)");
$pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, status TEXT,
    attendance_date TEXT, check_in_time TEXT)");
$pdo->exec("CREATE TABLE checkins (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, checkin_date TEXT,
    phone TEXT, checked_in_at TEXT)");

$day = static fn (int $ago): string => (new DateTime('today'))->modify("-{$ago} days")->format('Y-m-d');
$event = static function (int $id, string $title, string $category) use ($pdo): void {
    $pdo->prepare("INSERT INTO events (id, title, event_category) VALUES (?, ?, ?)")->execute([$id, $title, $category]);
};
$checkin = static function (int $uid, int $eid, string $date) use ($pdo): void {
    $pdo->prepare("INSERT INTO checkins (event_id, user_id, checkin_date) VALUES (?, ?, ?)")->execute([$eid, $uid, $date]);
};
$present = static function (int $uid, int $eid, ?string $date, ?string $time = null, string $status = 'Present') use ($pdo): void {
    $pdo->prepare("INSERT INTO attendance (event_id, user_id, status, attendance_date, check_in_time) VALUES (?, ?, ?, ?, ?)")
        ->execute([$eid, $uid, $status, $date, $time]);
};

$event(1, 'Sunday Service', 'Sunday_Service');
$event(2, 'Midweek Service', 'Midweek_Service');
$event(3, 'Youth Hangout', 'Special_Event');
$event(4, '', 'Midweek_Service');
$event(5, 'Sunday Service', 'Sunday_Service');

// Person 10: three Sundays ago (checked in AND marked present: one day), a
// midweek service 10 days ago, and two things on one day 3 days ago.
$checkin(10, 1, $day(21));
$present(10, 1, $day(21), $day(21) . ' 09:12:00');
$present(10, 2, $day(10));
$checkin(10, 5, $day(3));
$present(10, 3, $day(3));
$present(10, 1, $day(14), null, 'Absent');                  // absent: not a visit
// Person 11: one midweek service with no title, its date only on check_in_time.
$present(11, 4, null, $day(40) . ' 18:30:00');
// Person 12: 35 Sundays.
for ($i = 0; $i < 35; $i++) {
    $checkin(12, 1, $day(7 * $i + 1));
}
// Person 13: a check-in at an event that no longer exists.
$checkin(13, 99, $day(5));

$v = embrace_visits_for_visitors($pdo, [10, 11, 12, 13, 14, 0, -3, '10']);

same('every service counts, a day once (checked in and marked present is one visit)', 3, $v[10]['count']);
same('newest first', [$day(3), $day(10), $day(21)], array_column($v[10]['days'], 'date'));
same('last is the newest day', $day(3), $v[10]['last']);
same('…said the way Assimilation says it', '3 days ago', $v[10]['since']);
same('each day is named by what they came to', ['Midweek Service'], $v[10]['days'][1]['services']);
$both = $v[10]['days'][0]['services'];
sort($both);
same('two things on one day: one visit, both named', ['Sunday Service', 'Youth Hangout'], $both);
same('a duplicate of one service is named once', ['Sunday Service'], $v[10]['days'][2]['services']);

same('a date only on check_in_time still counts', [$day(40)], array_column($v[11]['days'], 'date'));
same('an event with no title is named by its kind', ['Midweek Service'], $v[11]['days'][0]['services']);
same('…weeks ago', '5 weeks ago', $v[11]['since']);

same('the count is every day…', 35, $v[12]['count']);
same('…the list the latest 30', 30, count($v[12]['days']));
same('…newest first', $day(1), $v[12]['days'][0]['date']);
same('a shorter limit is honoured', 5, count(embrace_visits_for_visitors($pdo, [12], 5)[12]['days']));

same('a visit to an event since deleted still counts, unnamed', [1, []], [$v[13]['count'], $v[13]['days'][0]['services']]);
ok('someone who never came is left out (the API says "No visit recorded")', !isset($v[14]));
same('only the people asked for', [10, 11, 12, 13], array_keys($v));
same('nobody asked: nothing, no query', [], embrace_visits_for_visitors($pdo, []));

// The count agrees with the one source every attendance question uses.
$union = (int) $pdo->query("SELECT COUNT(*) FROM " . assim_attendance_union_sql([], [10]) . " x")->fetchColumn();
same('the count is the union\'s count', $union, $v[10]['count']);

// The Embrace list carries it, and never fails because of it.
$api = (string) file_get_contents(__DIR__ . '/../api/embrace_api.php');
ok('fetch_visitors attaches each person\'s visits', str_contains($api, 'embrace_visits_for_visitors($pdo, $ids)')
    && str_contains($api, "\$v['visits'] ="));
ok('…inside a try, so the list still loads without it', (bool) preg_match('/try \{\s*\$visitsByVisitor = embrace_visits_for_visitors/', $api));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
