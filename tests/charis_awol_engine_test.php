<?php
// /tests/charis_awol_engine_test.php
//
// BEHAVIOURAL tests for the Charis AWOL streak engine. These EXECUTE
// charis_compute_awol_list() against a real (in-memory SQLite) database.
//
// tests/charis_awol_test.py only string-matches the source, which is exactly
// how the window-counting bug shipped: every static assertion passed while a
// member standing in church on 4 October still read "3 MISSED". Any change to
// the engine must keep these scenarios green.
//
// Run from the repo root:
//     php tests/charis_awol_engine_test.php
//
// Exit code 0 = all good, 1 = at least one failure.
//
// CLI only, same convention as the rest of tests/.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

// charis_column_exists() lives in api/charis_api.php, which cannot be included
// here (it boots a session and emits JSON). The helpers no longer call it, but
// define a shim so an accidental reintroduction fails loudly in review, not in
// production.
if (!function_exists('charis_column_exists')) {
    function charis_column_exists(PDO $pdo, string $table, string $column): bool { return true; }
}

require_once __DIR__ . '/../includes/charis_helpers.php';

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

function awol_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE regions (id INTEGER PRIMARY KEY, name TEXT)");
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT,
        physical_address TEXT, email TEXT, spiritual_status TEXT,
        attendance_status TEXT, picture_path TEXT, region_id INTEGER, created_at TEXT)");
    $pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT)");
    $pdo->exec("CREATE TABLE checkins (id INTEGER PRIMARY KEY, user_id INTEGER, checkin_date TEXT)");
    $pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT,
        attendance_date TEXT, check_in_time TEXT)");
    $pdo->exec("CREATE TABLE charis_welfare_assignments (id INTEGER PRIMARY KEY, target_user_id INTEGER,
        worker_id INTEGER, status TEXT, followup_id INTEGER, created_at TEXT, resolved_at TEXT)");
    $pdo->exec("CREATE TABLE charis_welfare_notes (id INTEGER PRIMARY KEY, target_user_id INTEGER,
        author_id INTEGER NULL, note_text TEXT, visible_to TEXT, is_system INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE charis_awol_auto_resolutions (id INTEGER PRIMARY KEY, target_user_id INTEGER,
        assignment_id INTEGER, followup_id INTEGER, case_type TEXT, attended_on TEXT,
        event_category TEXT, streak_at_resolution INTEGER DEFAULT 0, note_id INTEGER, created_at TEXT)");
    $pdo->exec("CREATE TABLE embrace_followups (id INTEGER PRIMARY KEY, visitor_id INTEGER, status TEXT,
        followup_date TEXT, followup_notes TEXT)");
    $pdo->exec("INSERT INTO regions (id, name) VALUES (1, 'Orchid')");
    return $pdo;
}

function awol_add_service(PDO $pdo, string $date, string $category): void
{
    $time = $category === 'Sunday_Service' ? ' 09:30:00' : ' 18:30:00';
    $title = $category === 'Sunday_Service' ? 'Total Experience' : 'Mercy Experience';
    $pdo->prepare("INSERT INTO events (title, event_category, event_date) VALUES (?, ?, ?)")
        ->execute([$title, $category, $date . $time]);
}

function awol_add_member(PDO $pdo, int $id, string $name, string $registered = '2020-01-01',
                         string $status = 'Worker', string $attendanceStatus = 'Active'): void
{
    $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status,
                   attendance_status, region_id, created_at) VALUES (?, ?, 'Test', ?, ?, 1, ?)")
        ->execute([$id, $name, $status, $attendanceStatus, $registered . ' 10:00:00']);
}

function awol_mark_present(PDO $pdo, int $userId, string $date): void
{
    $pdo->prepare("INSERT INTO checkins (user_id, checkin_date) VALUES (?, ?)")->execute([$userId, $date]);
}

/** Standard rule: 3 consecutive Sunday absences; midweek clears but never counts. */
function awol_config(int $threshold = 3, int $archiveWeeks = 12): array
{
    return [
        'consecutive_threshold' => $threshold,
        'archive_weeks'         => $archiveWeeks,
        'spiritual_statuses'    => ['Member', 'Worker', 'Pastor'],
        'service_rules'         => CHARIS_AWOL_DEFAULT_SERVICE_RULES,
    ];
}

function awol_find(array $result, int $userId): ?array
{
    foreach ($result['awol_checks'] as $row) {
        if ((int) $row['target_user_id'] === $userId) return $row;
    }
    return null;
}

// Monday 12 October 2026, 15:18 Lagos — the moment of the reported bug.
$NOW = new DateTimeImmutable('2026-10-12 15:18:00', new DateTimeZone('Africa/Lagos'));

// Six weeks of real services leading up to $NOW.
$SUNDAYS  = ['2026-09-06', '2026-09-13', '2026-09-20', '2026-09-27', '2026-10-04', '2026-10-11'];
$MIDWEEKS = ['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08'];

function awol_seed_services(PDO $pdo): void
{
    global $SUNDAYS, $MIDWEEKS;
    foreach ($SUNDAYS as $d)  awol_add_service($pdo, $d, 'Sunday_Service');
    foreach ($MIDWEEKS as $d) awol_add_service($pdo, $d, 'Midweek_Service');
}

/** Keep every service "held" by giving an unrelated member perfect attendance. */
function awol_seed_attendance_floor(PDO $pdo): void
{
    global $SUNDAYS, $MIDWEEKS;
    awol_add_member($pdo, 999, 'Floor');
    foreach (array_merge($SUNDAYS, $MIDWEEKS) as $d) awol_mark_present($pdo, 999, $d);
}

echo "\nCharis AWOL streak engine\n";

// -------------------------------------------------------------------------
echo "\n1. Reset on return (the reported bug)\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 1, 'Resets');
// Missed 20 & 27 Sep, ATTENDED 4 Oct, missed 11 Oct.
awol_mark_present($pdo, 1, '2026-09-06');
awol_mark_present($pdo, 1, '2026-09-13');
awol_mark_present($pdo, 1, '2026-10-04');
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('attendance resets the counter: miss, miss, ATTEND, miss is not flagged', awol_find($r, 1) === null);

$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 2, 'Absent');
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
$row = awol_find($r, 2);
ok('a genuinely absent member is still flagged', $row !== null);
ok('streak counts every Sunday since the anchor', $row && $row['consecutive_missed'] === 6,
   'got ' . ($row['consecutive_missed'] ?? 'null'));

// -------------------------------------------------------------------------
echo "\n2. Midweek asymmetry\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 3, 'Faithful');
foreach ($SUNDAYS as $d) awol_mark_present($pdo, 3, $d);   // never midweek
$r = charis_compute_awol_list($pdo, awol_config(2), $NOW);
ok('perfect Sunday attendance is never flagged for missing midweek', awol_find($r, 3) === null);

$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 4, 'Midweeker');
awol_mark_present($pdo, 4, '2026-10-08');                   // Thursday only
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('midweek presence clears a Sunday streak', awol_find($r, 4) === null);

// -------------------------------------------------------------------------
echo "\n3. Joy Dare-Kayode and Sis Maimuna\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 5, 'Joy');
foreach (['2026-09-06','2026-09-13','2026-09-20','2026-09-24','2026-09-27','2026-10-04'] as $d) {
    awol_mark_present($pdo, 5, $d);
}
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('Joy, present on 4 Oct, is no longer on the list', awol_find($r, 5) === null);

$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 6, 'Maimuna');
awol_mark_present($pdo, 6, '2026-10-11');                   // returned yesterday
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('Maimuna, back yesterday after a long absence, drops off immediately', awol_find($r, 6) === null);

// -------------------------------------------------------------------------
echo "\n4. New-member grace (no configuration required)\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 7, 'JustJoined', '2026-10-05');       // one week old, never attended
awol_add_member($pdo, 8, 'LongRegistered', '2026-08-01');   // old, never attended
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('a member registered last week is not flagged', awol_find($r, 7) === null);
ok('a member registered months ago who never came is flagged', awol_find($r, 8) !== null);

// -------------------------------------------------------------------------
echo "\n5. Sundays that did not hold are skipped\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_add_member($pdo, 9, 'Floor2');
// Floor member attends everything EXCEPT 11 Oct, so that Sunday has zero
// attendance church-wide and must be treated as not held.
foreach (array_merge($SUNDAYS, $MIDWEEKS) as $d) {
    if ($d === '2026-10-11') continue;
    awol_mark_present($pdo, 9, $d);
}
awol_add_member($pdo, 10, 'Counted');
awol_mark_present($pdo, 10, '2026-09-20');
$r = charis_compute_awol_list($pdo, awol_config(2), $NOW);
$row = awol_find($r, 10);
ok('a zero-attendance Sunday is reported as skipped',
   count($r['skipped_services']) === 1 && $r['skipped_services'][0]['service_date'] === '2026-10-11');
// 27 Sep and 4 Oct count; 11 Oct held no service so it must not make this 3.
ok('a skipped Sunday does not advance the streak', $row && $row['consecutive_missed'] === 2,
   'got ' . ($row['consecutive_missed'] ?? 'null'));
ok('a skipped Sunday is absent from the missed list',
   $row && !in_array('2026-10-11', $row['missed_service_dates'], true));

// -------------------------------------------------------------------------
echo "\n6. Card payload\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 11, 'Dormant');
$r = charis_compute_awol_list($pdo, awol_config(3, 2), $NOW);   // 2-week archive horizon
$row = awol_find($r, 11);
ok('the strip is capped so a long absence renders compactly',
   $row && count($row['attendance_strip']) <= CHARIS_AWOL_STRIP_LENGTH);
ok('missed dates are returned for the card', $row && !empty($row['missed_service_dates']));
ok('a long absence is tiered Dormant', $row && $row['tier'] === 'Dormant',
   'got ' . ($row['tier'] ?? 'null'));
ok('never-attended members are marked as such', $row && $row['never_attended'] === true);

$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 12, 'Recent');
awol_mark_present($pdo, 12, '2026-09-20');
$r = charis_compute_awol_list($pdo, awol_config(2, 12), $NOW);
$row = awol_find($r, 12);
ok('a recent absence is tiered Follow_Up', $row && $row['tier'] === 'Follow_Up',
   'got ' . ($row['tier'] ?? 'null'));
ok('last attended date is reported', $row && $row['last_attended_date'] === '2026-09-20');

// -------------------------------------------------------------------------
echo "\n7. Excluded members\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 13, 'Relocated', '2020-01-01', 'Worker', 'Relocated');
awol_add_member($pdo, 14, 'FirstTimer', '2020-01-01', '1st_Timer', 'Active');
$r = charis_compute_awol_list($pdo, awol_config(3), $NOW);
ok('relocated members are never flagged', awol_find($r, 13) === null);
ok('statuses outside the rule are never flagged', awol_find($r, 14) === null);

// -------------------------------------------------------------------------
echo "\n8. Automatic case closure on return\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 15, 'Returned');
$pdo->prepare("INSERT INTO charis_welfare_assignments (id, target_user_id, worker_id, status, followup_id, created_at)
               VALUES (1, 15, 999, 'Assigned', 0, '2026-09-28 10:00:00')")->execute();
awol_mark_present($pdo, 15, '2026-10-11');                  // came back after the case opened

$resolved = charis_awol_auto_resolve_returned($pdo, awol_config(3), $NOW);
ok('returning closes the open case automatically', count($resolved) === 1);
$status = $pdo->query("SELECT status FROM charis_welfare_assignments WHERE id = 1")->fetchColumn();
ok('the assignment is marked Resolved', $status === 'Resolved', 'got ' . var_export($status, true));
$note = $pdo->query("SELECT note_text, author_id, is_system FROM charis_welfare_notes WHERE target_user_id = 15")
            ->fetch(PDO::FETCH_ASSOC);
ok('a system-generated archive note is written', $note !== false && (int) $note['is_system'] === 1);
ok('the system note has no human author', $note !== false && $note['author_id'] === null);
ok('the note names the service and date',
   $note !== false && str_contains($note['note_text'], 'Sunday Service') && str_contains($note['note_text'], '11 Oct 2026'));
$audit = (int) $pdo->query("SELECT COUNT(*) FROM charis_awol_auto_resolutions WHERE target_user_id = 15")->fetchColumn();
ok('an audit row is filed for the closure', $audit === 1);

$again = charis_awol_auto_resolve_returned($pdo, awol_config(3), $NOW);
ok('re-running does not close the same case twice', $again === []);

// A later non-service visit (a wedding, a meeting) must not mask the service
// attendance that actually clears the case.
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 18, 'WeddingGuest');
$pdo->prepare("INSERT INTO charis_welfare_assignments (id, target_user_id, worker_id, status, followup_id, created_at)
               VALUES (2, 18, 999, 'Assigned', 0, '2026-09-28 10:00:00')")->execute();
awol_mark_present($pdo, 18, '2026-10-04');   // a real Sunday service
awol_mark_present($pdo, 18, '2026-10-10');   // a Saturday with no service
$resolved = charis_awol_auto_resolve_returned($pdo, awol_config(3), $NOW);
ok('a later non-service visit does not hide the clearing attendance',
   count($resolved) === 1 && $resolved[0]['attended_on'] === '2026-10-04');

// Manual flags clear the same way.
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 16, 'Flagged');
$pdo->prepare("INSERT INTO embrace_followups (id, visitor_id, status, followup_date, followup_notes)
               VALUES (7, 16, 'Pending', '2026-09-28', 'SYSTEM FLAG: pushed from Congregation Module.')")->execute();
awol_mark_present($pdo, 16, '2026-10-11');
$resolved = charis_awol_auto_resolve_returned($pdo, awol_config(3), $NOW);
ok('a manual flag clears on attendance too', count($resolved) === 1 && $resolved[0]['case_type'] === 'Manual_Check');
$fstatus = $pdo->query("SELECT status FROM embrace_followups WHERE id = 7")->fetchColumn();
ok('the manual follow-up is marked Completed', $fstatus === 'Completed', 'got ' . var_export($fstatus, true));

// -------------------------------------------------------------------------
echo "\n9. Same-day duplicate attendance\n";
// -------------------------------------------------------------------------
$pdo = awol_test_db();
awol_seed_services($pdo);
awol_seed_attendance_floor($pdo);
awol_add_member($pdo, 17, 'DoubleScanned');
// Scanned twice on the same Sunday, plus a row in the other attendance table.
awol_mark_present($pdo, 17, '2026-09-20');
awol_mark_present($pdo, 17, '2026-09-20');
$pdo->prepare("INSERT INTO attendance (user_id, status, attendance_date) VALUES (17, 'Present', '2026-09-20')")->execute();
$r = charis_compute_awol_list($pdo, awol_config(2), $NOW);
$row = awol_find($r, 17);
// 27 Sep, 4 Oct, 11 Oct are missed. Three duplicate presences on 20 Sep must
// count as one, so the streak is 3 rather than being distorted by the extras.
ok('duplicate check-ins on one day count as a single presence',
   $row && $row['consecutive_missed'] === 3, 'got ' . ($row['consecutive_missed'] ?? 'null'));
ok('the anchor is the duplicated day itself',
   $row && $row['last_attended_date'] === '2026-09-20');

// -------------------------------------------------------------------------
echo "\n10. Service rule normalisation\n";
// -------------------------------------------------------------------------
$rules = charis_awol_service_rules([]);
ok('with no grid stored, Sunday absence counts', $rules['Sunday_Service']['counts_absence'] === true);
ok('with no grid stored, midweek absence never counts', $rules['Midweek_Service']['counts_absence'] === false);
ok('with no grid stored, midweek presence still resets', $rules['Midweek_Service']['resets_clock'] === true);
$rules = charis_awol_service_rules(['service_rules' => '{"Sunday_Service":{"counts_absence":true,"resets_clock":true},"Midweek_Service":{"counts_absence":true,"resets_clock":true}}']);
ok('a stored grid is honoured', $rules['Midweek_Service']['counts_absence'] === true);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
