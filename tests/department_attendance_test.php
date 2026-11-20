<?php
// /tests/department_attendance_test.php
//
// BEHAVIOURAL tests for the Departments Attendance tab
// (includes/department_attendance.php). They EXECUTE the real functions
// against in-memory SQLite, the way tests/dashboard_metrics_test.php does,
// and pin the rules that matter at a workers' meeting:
//
//   - the expected list is the live year's Active rosters (not a status label);
//     a removed or archived-year row never counts;
//   - someone on two rosters is listed under both but counted once;
//   - present = an attendance row OR a door check-in, and a stranger who
//     clocks in is counted apart ("outside"), never as a worker;
//   - a clock-in refuses a closed event, a non-worker and a second tap, and
//     writes the same row Events writes;
//   - a door check-in is never removed from here;
//   - a head of department clocks in only their own people;
//   - the export cannot run a name as a spreadsheet formula.
//
// Run from the repo root:
//     php tests/department_attendance_test.php
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

date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/../includes/department_attendance.php';

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

function da_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, attendance_status TEXT)");
    $pdo->exec("CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER)");
    $pdo->exec("CREATE TABLE user_departments (id INTEGER PRIMARY KEY, user_id INTEGER, department_id INTEGER,
        role_in_dept TEXT, membership_type TEXT, ministry_year_id INTEGER, roster_status TEXT DEFAULT 'Active', is_active INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT, end_date TEXT, is_closed INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, status TEXT,
        attendance_date TEXT, check_in_time TEXT, checked_in_by INTEGER)");
    $pdo->exec("CREATE TABLE checkins (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, checkin_date TEXT,
        phone TEXT, checked_in_at TEXT)");

    return $pdo;
}

function da_user(PDO $pdo, int $id, string $first, string $last = 'Test', string $phone = '', string $status = 'Active'): void
{
    $pdo->prepare('INSERT INTO users (id, first_name, last_name, phone, attendance_status) VALUES (?,?,?,?,?)')
        ->execute([$id, $first, $last, $phone, $status]);
}

function da_roster(PDO $pdo, int $user, int $dept, string $seat = 'Worker', string $type = 'Primary', int $year = 1, string $status = 'Active'): void
{
    $pdo->prepare('INSERT INTO user_departments (user_id, department_id, role_in_dept, membership_type, ministry_year_id, roster_status) VALUES (?,?,?,?,?,?)')
        ->execute([$user, $dept, $seat, $type, $year, $status]);
}

function da_world(): PDO
{
    $pdo = da_db();
    $pdo->exec("INSERT INTO departments (id, name, parent_id) VALUES (1, 'Ushers', NULL), (2, 'Choir', NULL), (3, 'Audio', 2)");
    $pdo->exec("INSERT INTO events (id, title, event_category, event_date, is_closed) VALUES
        (10, 'Workers Meeting', 'Conference', '2026-11-20', 0), (11, 'Old Meeting', 'Conference', '2026-10-01', 1)");
    da_user($pdo, 1, 'Ada', 'Okoro', '0803 111 2222');
    da_user($pdo, 2, 'Bayo', 'Lawal', '+2348033334444');
    da_user($pdo, 3, 'Chi', 'Eze');
    da_user($pdo, 4, 'Dayo', 'Ola');
    da_user($pdo, 5, 'Efe', 'Moved', '', 'Relocated');
    da_user($pdo, 6, 'Femi', 'Removed');
    da_user($pdo, 7, 'Guest', 'Outsider');
    da_roster($pdo, 1, 1, 'HOD');                  // Ada leads Ushers
    da_roster($pdo, 2, 1);                         // Bayo: Ushers and ...
    da_roster($pdo, 2, 3, 'Worker', 'Secondary');  // ... Audio (sub-unit of Choir)
    da_roster($pdo, 3, 2);
    da_roster($pdo, 4, 2, 'Worker', 'Secondary');
    da_roster($pdo, 5, 2);                         // relocated: not expected
    da_roster($pdo, 6, 2, 'Worker', 'Primary', 1, 'Removed');   // removed: not expected
    da_roster($pdo, 4, 1, 'Worker', 'Primary', 2); // an archived year's row: not expected

    return $pdo;
}

echo "Who is expected\n";
$pdo = da_world();
$roll = dept_att_roll($pdo, 10, 1, ['HOD']);
same('four distinct workers: Ada, Bayo, Chi, Dayo', 4, $roll['totals']['workers']);
same('nobody present yet', 0, $roll['totals']['present']);
same('everyone absent', 4, $roll['totals']['absent']);
same('0% turnout', 0, $roll['totals']['percent']);
same('departments are listed A to Z', ['Audio', 'Choir', 'Ushers'], array_column($roll['departments'], 'name'));
$byName = array_column($roll['departments'], null, 'name');
same('Audio says it is part of Choir', 'Choir', $byName['Audio']['parent']);
same('Ushers has Ada and Bayo', 2, $byName['Ushers']['total']);
same('the leader is listed first', 'Ada Okoro', $byName['Ushers']['members'][0]['name']);
same('Primary comes before Secondary', ['Chi Eze', 'Dayo Ola'], array_column($byName['Choir']['members'], 'name'));
$people = array_column($roll['people'], null, 'name');
same('Bayo (two rosters) is one person', 1, count(array_filter($roll['people'], fn ($p) => $p['name'] === 'Bayo Lawal')));
same('and carries both departments', 2, count($people['Bayo Lawal']['departments']));
same('a sub-unit is labelled with its parent', true, in_array('Audio · Choir', $people['Bayo Lawal']['departments'], true));
same('their department ids are kept for scoping', [1, 3], $people['Bayo Lawal']['dept_ids']);
ok('a relocated member is not expected', !isset($people['Efe Moved']));
ok('a removed row is not expected', !isset($people['Femi Removed']));
same('an archived year\'s row does not put Dayo on Ushers', 1, count($people['Dayo Ola']['departments']));

echo "\nWho is present\n";
$pdo = da_world();
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time, attendance_date) VALUES (10, 1, 'Present', '2026-11-20 18:05:00', '2026-11-20')");
$pdo->exec("INSERT INTO checkins (event_id, user_id, checkin_date, checked_in_at) VALUES (10, 3, '2026-11-20', '2026-11-20 18:10:00')");
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (10, 4, 'Absent', '2026-11-20 18:11:00')");
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (11, 2, 'Present', '2026-10-01 18:00:00')");
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (10, 7, 'Present', '2026-11-20 18:20:00')");
$roll = dept_att_roll($pdo, 10, 1, ['HOD']);
$people = array_column($roll['people'], null, 'name');
ok('an attendance row is present', $people['Ada Okoro']['present']);
same('with a readable time', '6:05 PM', $people['Ada Okoro']['time']);
ok('a door check-in is present too', $people['Chi Eze']['present']);
ok('and is marked as the door', $people['Chi Eze']['door']);
ok('a row that is not Present does not count', !$people['Dayo Ola']['present']);
ok('another event\'s row does not count', !$people['Bayo Lawal']['present']);
same('two present of four', [2, 2, 50], [$roll['totals']['present'], $roll['totals']['absent'], $roll['totals']['percent']]);
same('a stranger is counted apart, not as a worker', [4, 1], [$roll['totals']['workers'], $roll['totals']['outside']]);
$byName = array_column($roll['departments'], null, 'name');
same('Choir: Chi in, Dayo out', [1, 1, 2], [$byName['Choir']['present'], $byName['Choir']['absent'], $byName['Choir']['total']]);

echo "\nA person on two rosters is counted once\n";
$pdo = da_world();
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (10, 2, 'Present', '2026-11-20 18:00:00')");
$roll = dept_att_roll($pdo, 10, 1);
$byName = array_column($roll['departments'], null, 'name');
same('present in both of Bayo\'s departments', [1, 1], [$byName['Ushers']['present'], $byName['Audio']['present']]);
same('one worker present, not two', 1, $roll['totals']['present']);

echo "\nClocking in\n";
$pdo = da_world();
$r = dept_att_mark($pdo, 10, 3, 99, 1, '2026-11-20 18:30:00');
same('clock-in succeeds', 'success', $r['status']);
same('and reports the time', '6:30 PM', $r['time'] ?? '');
$row = $pdo->query('SELECT * FROM attendance WHERE event_id = 10 AND user_id = 3')->fetch(PDO::FETCH_ASSOC);
same('the same row Events writes', ['Present', '2026-11-20', '99'], [$row['status'], $row['attendance_date'], (string) $row['checked_in_by']]);
same('a second tap is a warning, not a second row', 'warning', dept_att_mark($pdo, 10, 3, 99, 1, '2026-11-20 18:31:00')['status']);
same('one row only', 1, (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE event_id = 10 AND user_id = 3')->fetchColumn());
$pdo->exec("INSERT INTO checkins (event_id, user_id, checkin_date, checked_in_at) VALUES (10, 4, '2026-11-20', '2026-11-20 18:00:00')");
same('someone already at the door is a warning', 'warning', dept_att_mark($pdo, 10, 4, 99, 1, '2026-11-20 18:32:00')['status']);
same('a non-worker is refused', 'error', dept_att_mark($pdo, 10, 7, 99, 1, '2026-11-20 18:33:00')['status']);
same('a removed worker is refused', 'error', dept_att_mark($pdo, 10, 6, 99, 1, '2026-11-20 18:33:00')['status']);
same('a closed event is refused', 'error', dept_att_mark($pdo, 11, 1, 99, 1, '2026-11-20 18:34:00')['status']);
same('an unknown event is refused', 'error', dept_att_mark($pdo, 404, 1, 99, 1, '2026-11-20 18:34:00')['status']);
same('the turnout moves', 1, dept_att_roll($pdo, 10, 1)['totals']['present'] - 1);

echo "\nMoving someone back\n";
same('a desk clock-in can be undone', 'success', dept_att_unmark($pdo, 10, 3)['status']);
ok('and they are absent again', !array_column(dept_att_roll($pdo, 10, 1)['people'], null, 'name')['Chi Eze']['present']);
same('undoing twice is a warning', 'warning', dept_att_unmark($pdo, 10, 3)['status']);
same('a door check-in is never removed from here', 'warning', dept_att_unmark($pdo, 10, 4)['status']);
ok('and still counts as present', array_column(dept_att_roll($pdo, 10, 1)['people'], null, 'name')['Dayo Ola']['present']);
same('a closed event cannot be changed', 'error', dept_att_unmark($pdo, 11, 3)['status']);

echo "\nA head of department\n";
$pdo = da_world();
same('everyone is in scope when the scope is null', true, dept_att_in_scope($pdo, 3, 1, null));
same('a head of Ushers may clock in Bayo', true, dept_att_in_scope($pdo, 2, 1, [1]));
same('but not Chi, who is in Choir', false, dept_att_in_scope($pdo, 3, 1, [1]));
same('a head of Choir covers the Audio sub-unit when it is in their scope', true, dept_att_in_scope($pdo, 2, 1, [2, 3]));
same('an empty scope clocks in nobody', false, dept_att_in_scope($pdo, 1, 1, []));

echo "\nThe export\n";
$pdo = da_world();
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (10, 1, 'Present', '2026-11-20 18:00:00')");
$roll = dept_att_roll($pdo, 10, 1, ['HOD']);
$absent = dept_att_export_rows($roll, 'absent', ['HOD' => 'Head of Department']);
same('the absent list leaves out the present', 0, count(array_filter($absent, fn ($r) => $r['Name'] === 'Ada Okoro')));
same('one row per person per department', 4, count($absent));
same('grouped department by department', ['Audio', 'Choir', 'Choir', 'Ushers'], array_column($absent, 'Department'));
$all = dept_att_export_rows($roll, 'all', ['HOD' => 'Head of Department']);
same('the full list has everyone', 5, count($all));
$ada = array_values(array_filter($all, fn ($r) => $r['Name'] === 'Ada Okoro'))[0];
same('a leader\'s seat reads as its label', 'Head of Department', $ada['Seat']);
same('and shows Present with a time', ['Present', '6:00 PM'], [$ada['Status'], $ada['Time in']]);
same('a formula is neutralised', "'=HYPERLINK(\"x\")", dept_att_csv_cell('=HYPERLINK("x")'));
same('so is an @ command', "'@SUM(1)", dept_att_csv_cell('@SUM(1)'));
same('a +234 phone is left alone', '+234 803 333 4444', dept_att_csv_cell('+234 803 333 4444'));
same('a plain name is untouched', 'Ada Okoro', dept_att_csv_cell('Ada Okoro'));

echo "\nWorkers-only events\n";
same('the Workers\' Meeting category is workers-only', true, dept_att_workers_only(['event_category' => 'Workers_Meeting', 'title' => 'Monthly']));
same('so is a title that says worker (the roster list\'s old guess)', true, dept_att_workers_only(['event_category' => 'Conference', 'title' => "Workers' Meeting November 2026"]));
same('but a clock-in is refused on the category alone', false, dept_att_workers_only(['event_category' => 'Conference', 'title' => 'Workers Appreciation Dinner'], true));
same('and the category counts when strict', true, dept_att_workers_only(['event_category' => 'Workers_Meeting', 'title' => 'x'], true));
same('an ordinary event is not', false, dept_att_workers_only(['event_category' => 'Sunday_Service', 'title' => 'Sunday Service']));
$pdo = da_world();
$pdo->exec("UPDATE user_departments SET is_active = 0 WHERE user_id = 3");
same('a serving member is a worker', true, dept_att_is_worker($pdo, 1));
same('someone whose row is not live is not', false, dept_att_is_worker($pdo, 3));
same('a removed row is not serving', false, dept_att_is_worker($pdo, 6));
same('a person on no roster is not', false, dept_att_is_worker($pdo, 7));
$sql = dept_att_live_roster_sql($pdo, 'ud');
same('the live-roster test says live and Active', true, str_contains($sql, 'ud.is_active = 1') && str_contains($sql, "ud.roster_status = 'Active'"));
$names = $pdo->query("SELECT DISTINCT u.id FROM users u WHERE u.id IN (SELECT ud.user_id FROM user_departments ud WHERE {$sql}) ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
same('as a roster scope it keeps Ada, Bayo and Dayo (Chi switched off, Femi removed, Efe relocated is still on the row)', ['1', '2', '4', '5'], array_map('strval', $names));
$noStatus = new PDO('sqlite::memory:');
$noStatus->exec('CREATE TABLE user_departments (id INTEGER PRIMARY KEY, user_id INTEGER, department_id INTEGER, is_active INTEGER)');
same('before the ministry-years migration is_active alone decides', '(ud.is_active = 1)', dept_att_live_roster_sql($noStatus, 'ud'));
try { dept_att_live_roster_sql($pdo, 'ud; DROP'); ok('a bad alias is refused', false); } catch (InvalidArgumentException $e) { ok('a bad alias is refused', true); }

echo "\nOpen events\n";
$events = dept_att_events($pdo);
same('only open events are offered', [10], array_column($events, 'id'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
