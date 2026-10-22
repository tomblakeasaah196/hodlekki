<?php
// /tests/dashboard_metrics_test.php
//
// BEHAVIOURAL tests for the home dashboard's numbers
// (includes/dashboard_metrics.php). They EXECUTE the real functions against
// in-memory SQLite, the way tests/status_ladder_test.php does, and each one
// pins a way the old cards were wrong:
//
//   - "New Pipeline" counted Visitor + 1st_Timer: no 2nd or 3rd Timers, and
//     every ordinary Visitor ever recorded.
//   - "Last Sunday" counted attendance ROWS over a 7-day window: two Sundays
//     added together on a Sunday, someone at two services counted twice, and
//     QR walk-ins with no record never counted at all.
//   - "Workforce" counted the 'Worker' label, not who is on a live roster.
//
// Run from the repo root:
//     php tests/dashboard_metrics_test.php
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

require_once __DIR__ . '/../includes/dashboard_metrics.php';

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

function dm_db(bool $withHistory = true, bool $withRosterStatus = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
        spiritual_status TEXT, phone TEXT)");
    $pdo->exec("CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)");
    $pdo->exec("CREATE TABLE user_departments (id INTEGER PRIMARY KEY, user_id INTEGER, department_id INTEGER,
        role_in_dept TEXT, is_active INTEGER" . ($withRosterStatus ? ", roster_status TEXT NOT NULL DEFAULT 'Active'" : '') . ")");
    $pdo->exec("CREATE TABLE embrace_followups (id INTEGER PRIMARY KEY, visitor_id INTEGER, status TEXT, followup_date TEXT)");
    $pdo->exec("CREATE TABLE pastor_qa (id INTEGER PRIMARY KEY, status TEXT, submitted_at TEXT)");
    $pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT)");
    $pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, status TEXT,
        attendance_date TEXT, check_in_time TEXT)");
    $pdo->exec("CREATE TABLE checkins (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, checkin_date TEXT,
        phone TEXT, checked_in_at TEXT)");
    if ($withHistory) {
        $pdo->exec("CREATE TABLE user_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            from_status TEXT NOT NULL DEFAULT '', to_status TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL, changed_by INTEGER NULL, changed_at TEXT NOT NULL)");
    }
    return $pdo;
}

function dm_user(PDO $pdo, int $id, string $status): void
{
    $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status) VALUES (?, ?, 'Test', ?)")
        ->execute([$id, 'User' . $id, $status]);
}

function dm_roster(PDO $pdo, int $user, int $dept, int $active = 1, string $roster = 'Active', string $role = 'Worker'): void
{
    $hasRoster = ladder_probe($pdo, 'SELECT roster_status FROM user_departments LIMIT 0');
    if ($hasRoster) {
        $pdo->prepare("INSERT INTO user_departments (user_id, department_id, role_in_dept, is_active, roster_status) VALUES (?, ?, ?, ?, ?)")
            ->execute([$user, $dept, $role, $active, $roster]);
    } else {
        $pdo->prepare("INSERT INTO user_departments (user_id, department_id, role_in_dept, is_active) VALUES (?, ?, ?, ?)")
            ->execute([$user, $dept, $role, $active]);
    }
}

function dm_event(PDO $pdo, string $date, string $category = 'Sunday_Service', string $time = '09:30:00'): int
{
    $pdo->prepare("INSERT INTO events (title, event_category, event_date) VALUES (?, ?, ?)")
        ->execute([$category, $category, $date . ' ' . $time]);
    return (int) $pdo->lastInsertId();
}

function dm_present(PDO $pdo, int $user, int $event, string $date, string $status = 'Present'): void
{
    $pdo->prepare("INSERT INTO attendance (event_id, user_id, status, attendance_date, check_in_time) VALUES (?, ?, ?, ?, ?)")
        ->execute([$event, $user, $status, $date, $date . ' 09:45:00']);
}

function dm_qr(PDO $pdo, ?int $user, int $event, string $date, ?string $phone = null): void
{
    $pdo->prepare("INSERT INTO checkins (event_id, user_id, checkin_date, phone, checked_in_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$event, $user, $date, $phone, $date . ' 09:50:00']);
}

// ---------------------------------------------------------------------------
echo "Workforce = Departments' live headcount, not the 'Worker' label\n";
// ---------------------------------------------------------------------------
$pdo = dm_db();
$pdo->exec("INSERT INTO departments (id, name) VALUES (1, 'Choir'), (2, 'Ushering'), (3, 'Media')");
dm_user($pdo, 1, 'Worker');
dm_user($pdo, 2, 'Member');      // serving, never relabelled 'Worker'
dm_user($pdo, 3, 'Worker');      // in two departments
dm_user($pdo, 4, 'Worker');      // relieved of duty (is_active = 0)
dm_user($pdo, 5, 'Worker');      // Removed inside the live year
dm_user($pdo, 6, 'Worker');      // label only, on no roster at all
dm_roster($pdo, 1, 1);
dm_roster($pdo, 2, 1);
dm_roster($pdo, 3, 1);
dm_roster($pdo, 3, 2, 1, 'Active', 'Director');
dm_roster($pdo, 4, 2, 0);
dm_roster($pdo, 5, 3, 1, 'Removed');

same('distinct people on a live roster', 3, dash_workforce($pdo));
same('the old label count would have said', 5,
    (int) $pdo->query("SELECT COUNT(*) FROM users WHERE spiritual_status = 'Worker'")->fetchColumn());
same('by department, largest first; Removed and inactive rows never show',
    [['id' => 1, 'name' => 'Choir', 'people' => 3], ['id' => 2, 'name' => 'Ushering', 'people' => 1]],
    dash_workforce_by_department($pdo));
same('only the departments asked for', 1, dash_workforce($pdo, [2]));
same('an empty list of departments matches nobody', 0, dash_workforce($pdo, []));
same('departments led as Director', [2], dash_led_departments($pdo, 3, 'Director'));
same('nobody else leads anything', [], dash_led_departments($pdo, 1, 'Director'));

$pre = dm_db(true, false);
dm_user($pre, 1, 'Worker');
dm_roster($pre, 1, 1);
dm_roster($pre, 2, 1, 0);
same('before the ministry-years migration, is_active alone decides', 1, dash_workforce($pre));

// ---------------------------------------------------------------------------
echo "New Pipeline = Embrace's active list\n";
// ---------------------------------------------------------------------------
$pdo = dm_db();
dm_user($pdo, 1, '1st_Timer');
dm_user($pdo, 2, '2nd_Timer');
dm_user($pdo, 3, '3rd_Timer');
dm_user($pdo, 4, 'Visitor');     // an ordinary Visitor added through Congregation
dm_user($pdo, 5, 'Visitor');     // graduated by the ladder, follow-up still open
dm_user($pdo, 6, 'Visitor');     // graduated by the ladder, follow-up reported
dm_user($pdo, 7, 'Member');
$hist = $pdo->prepare("INSERT INTO user_status_history (user_id, from_status, to_status, source, changed_at) VALUES (?, '3rd_Timer', 'Visitor', ?, '2026-10-18 10:00:00')");
$hist->execute([5, LADDER_SOURCE]);
$hist->execute([6, LADDER_SOURCE]);
$fu = $pdo->prepare("INSERT INTO embrace_followups (visitor_id, status, followup_date) VALUES (?, ?, ?)");
$fu->execute([5, 'Pending', '2026-10-12']);
$fu->execute([6, 'Completed', '2026-10-05']);

$pipe = dash_pipeline($pdo);
same('2nd and 3rd Timers count; ordinary Visitors and finished graduates do not',
    ['1st_Timer' => 1, '2nd_Timer' => 1, '3rd_Timer' => 1, 'Visitor' => 1], $pipe['by_status']);
same('total', 4, $pipe['total']);
same('the old Visitor + 1st_Timer count would have said', 4,
    (int) $pdo->query("SELECT COUNT(*) FROM users WHERE spiritual_status IN ('Visitor', '1st_Timer')")->fetchColumn());
same('…but for the wrong people: it saw none of the 2nd/3rd Timers', 0,
    (int) $pdo->query("SELECT COUNT(*) FROM users WHERE spiritual_status IN ('Visitor', '1st_Timer') AND id IN (2, 3)")->fetchColumn());

$noHistory = dm_db(false);
dm_user($noHistory, 1, '1st_Timer');
dm_user($noHistory, 2, 'Visitor');
same('before the ladder migration the list is just the timers', 1, dash_pipeline($noHistory)['total']);

// ---------------------------------------------------------------------------
echo "Follow-ups read each person's LATEST follow-up\n";
// ---------------------------------------------------------------------------
// Pipeline: 1 (1st), 2 (2nd), 3 (3rd), 5 (graduate, Pending 2026-10-12).
$fu->execute([1, 'Pending', '2026-10-01']);       // reassigned: this row is superseded…
$fu->execute([1, 'Completed', '2026-10-08']);     // …by a finished report
$fu->execute([2, 'In_Progress', '2026-10-10']);
// 3 has never been assigned.
$f = dash_followups($pdo);
same('open = waiting for a report', 2, $f['open']);
same('pending', 1, $f['pending']);
same('in progress', 1, $f['in_progress']);
same('never assigned', 1, $f['unassigned']);
same('reported but still on the list', 1, $f['reported']);
same('oldest open assignment', '2026-10-10', $f['oldest_open']);
same('the old row count would have said (superseded rows, people off the list)', 2,
    (int) $pdo->query("SELECT COUNT(*) FROM embrace_followups WHERE status = 'Pending'")->fetchColumn());

// ---------------------------------------------------------------------------
echo "Pending Q&A by age\n";
// ---------------------------------------------------------------------------
$pdo->exec("INSERT INTO pastor_qa (status, submitted_at) VALUES
    ('Pending', '2026-10-20 08:00:00'), ('Pending', '2026-10-01 08:00:00'),
    ('Pending', '2026-08-01 08:00:00'), ('Answered', '2026-10-21 08:00:00')");
same('buckets', ['total' => 3, 'this_week' => 1, 'weeks' => 1, 'older' => 1, 'oldest' => '2026-08-01 08:00:00'],
    dash_pending_qa($pdo, '2026-10-22'));

// ---------------------------------------------------------------------------
echo "Sunday = distinct people, members and walk-ins, one Sunday at a time\n";
// ---------------------------------------------------------------------------
$pdo = dm_db();
foreach (range(1, 6) as $id) dm_user($pdo, $id, 'Member');
$s04  = dm_event($pdo, '2026-10-04');
$s11  = dm_event($pdo, '2026-10-11');
$s18a = dm_event($pdo, '2026-10-18', 'Sunday_Service', '07:30:00');   // first service
$s18b = dm_event($pdo, '2026-10-18', 'Sunday Service', '10:00:00');   // second, the other spelling
$mid  = dm_event($pdo, '2026-10-18', 'Midweek_Service', '18:00:00');
$s25  = dm_event($pdo, '2026-10-25');
$s01  = dm_event($pdo, '2026-11-01');                                   // not yet

dm_present($pdo, 1, $s04, '2026-10-04');

dm_present($pdo, 1, $s11, '2026-10-11');
dm_present($pdo, 2, $s11, '2026-10-11');
dm_qr($pdo, 3, $s11, '2026-10-11');

// 18 Oct: member 1 on the roster AND by QR; member 2 at both services;
// member 3 marked Absent; member 4 only at the midweek event; a walk-in
// (no record) at both services with the same phone; two walk-ins without
// a phone.
dm_present($pdo, 1, $s18a, '2026-10-18');
dm_qr($pdo, 1, $s18a, '2026-10-18');
dm_present($pdo, 2, $s18a, '2026-10-18');
dm_present($pdo, 2, $s18b, '2026-10-18');
dm_present($pdo, 3, $s18a, '2026-10-18', 'Absent');
dm_present($pdo, 4, $mid, '2026-10-18');
dm_qr($pdo, null, $s18a, '2026-10-18', '+2348031234567');
dm_qr($pdo, null, $s18b, '2026-10-18', '+2348031234567');
dm_qr($pdo, null, $s18b, '2026-10-18', null);
dm_qr($pdo, null, $s18b, '2026-10-18', ' ');

same('Sunday service days on or before a date, newest first',
    ['2026-10-18', '2026-10-11', '2026-10-04'], dash_sunday_dates($pdo, '2026-10-22'));
same('counts per day',
    ['2026-10-18' => ['members' => 2, 'guests' => 3, 'total' => 5], '2026-10-11' => ['members' => 3, 'guests' => 0, 'total' => 3]],
    dash_sunday_counts($pdo, ['2026-10-18', '2026-10-11']));

$thu = dash_sunday($pdo, '2026-10-22');
same('a Thursday shows the latest Sunday', '2026-10-18', $thu['date']);
same('…not live', false, $thu['is_today']);
same('…5 people', 5, $thu['total']);
same('…+2 on the Sunday before', 2, $thu['change']);
same('…and the recent Sundays', ['2026-10-18', '2026-10-11', '2026-10-04'], array_column($thu['history'], 'date'));

$oldWay = (int) $pdo->query("SELECT COUNT(a.id) FROM attendance a JOIN events e ON a.event_id = e.id
    WHERE e.event_category = 'Sunday_Service' AND e.event_date >= '2026-10-15'")->fetchColumn();
same('the old row count would have said (Absent row, member 2 twice, no walk-ins, missed the second spelling)', 3, $oldWay);

dm_present($pdo, 5, $s25, '2026-10-25');
dm_qr($pdo, null, $s25, '2026-10-25', '+2348039999999');
$sun = dash_sunday($pdo, '2026-10-25');
same('on a Sunday it is today, live', ['2026-10-25', true, 2], [$sun['date'], $sun['is_today'], $sun['total']]);
same('…with last Sunday alongside, not added in', 5, $sun['previous']['total']);
same('…and no change while the service is still filling up', null, $sun['change']);

same('no Sunday service recorded yet', [null, 0, null], (function () {
    $s = dash_sunday(dm_db(), '2026-10-22');
    return [$s['date'], $s['total'], $s['previous']];
})());

// ---------------------------------------------------------------------------
echo "The shared attendance helper's new day filter\n";
// ---------------------------------------------------------------------------
same('no dates = exactly the old SQL', assim_attendance_union_sql(['Sunday_Service']), assim_attendance_union_sql(['Sunday_Service'], [], []));
$threw = false;
try {
    assim_attendance_union_sql([], [], ["2026-10-18' OR '1'='1"]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('anything but a plain date is refused', $threw);
$threw = false;
try {
    assim_attendance_union_sql([], [], ["2026-10-18\n"]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('…including a trailing newline', $threw);

// ---------------------------------------------------------------------------
echo "Tiers\n";
// ---------------------------------------------------------------------------
same('pastors and admins', ['leadership', 'leadership', 'leadership'],
    [dash_tier('Super_Admin'), dash_tier('Resident_Pastor'), dash_tier('Assoc_Pastor')]);
same('directors, HODs, everyone else', ['director', 'hod', 'member', 'member'],
    [dash_tier('Director'), dash_tier('HOD'), dash_tier('Church_Member'), dash_tier('Member')]);
same('leadership cards', ['workforce', 'pipeline', 'followups', 'pending_qa', 'sunday'], DASH_TIER_CARDS['leadership']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
