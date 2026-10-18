<?php
// /tests/status_ladder_test.php
//
// BEHAVIOURAL tests for the Sunday ladder (includes/status_ladder.php), the
// Embrace pipeline predicate and the as-at-date status the event reports use.
// These EXECUTE the real functions against in-memory SQLite, the same way
// tests/charis_awol_engine_test.php does for AWOL.
//
// Run from the repo root:
//     php tests/status_ladder_test.php
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

require_once __DIR__ . '/../includes/status_ladder.php';
require_once __DIR__ . '/../includes/embrace_helpers.php';
require_once __DIR__ . '/../includes/event_analytics_helpers.php';

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

function sl_db(bool $withHistory = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, gender TEXT, phone TEXT,
        spiritual_status TEXT, attendance_status TEXT, awol_exempt INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT)");
    $pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, status TEXT,
        attendance_date TEXT, check_in_time TEXT)");
    $pdo->exec("CREATE TABLE checkins (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, checkin_date TEXT,
        phone TEXT, checked_in_at TEXT)");
    $pdo->exec("CREATE TABLE embrace_followups (id INTEGER PRIMARY KEY, visitor_id INTEGER, status TEXT)");
    if ($withHistory) {
        $pdo->exec("CREATE TABLE user_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            from_status TEXT NOT NULL DEFAULT '', to_status TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL, changed_by INTEGER NULL, changed_at TEXT NOT NULL)");
    }
    return $pdo;
}

function sl_user(PDO $pdo, int $id, string $status, string $attendance = 'Active', int $exempt = 0): void
{
    $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, awol_exempt)
                   VALUES (?, ?, 'Test', ?, ?, ?)")
        ->execute([$id, 'User' . $id, $status, $attendance, $exempt]);
}

function sl_event(PDO $pdo, string $date, string $category = 'Sunday_Service'): int
{
    $pdo->prepare("INSERT INTO events (title, event_category, event_date) VALUES (?, ?, ?)")
        ->execute([$category, $category, $date . ' 09:30:00']);
    return (int) $pdo->lastInsertId();
}

/** Roster clock-in (the `attendance` table). */
function sl_present(PDO $pdo, int $user, int $event, string $date, string $status = 'Present'): int
{
    $pdo->prepare("INSERT INTO attendance (event_id, user_id, status, attendance_date, check_in_time) VALUES (?, ?, ?, ?, ?)")
        ->execute([$event, $user, $status, $date, $date . ' 09:45:00']);
    return (int) $pdo->lastInsertId();
}

/** QR check-in (the `checkins` table). */
function sl_qr(PDO $pdo, int $user, int $event, string $date): void
{
    $pdo->prepare("INSERT INTO checkins (event_id, user_id, checkin_date, checked_in_at) VALUES (?, ?, ?, ?)")
        ->execute([$event, $user, $date, $date . ' 09:50:00']);
}

function sl_status(PDO $pdo, int $id): string
{
    return (string) $pdo->query("SELECT spiritual_status FROM users WHERE id = {$id}")->fetchColumn();
}

function sl_history(PDO $pdo, int $id): array
{
    return $pdo->query("SELECT from_status, to_status, source FROM user_status_history WHERE user_id = {$id} ORDER BY id")
               ->fetchAll(PDO::FETCH_ASSOC);
}

/** Make one Sunday service per date and return [date => event id]. */
function sl_sundays(PDO $pdo, array $dates): array
{
    $ids = [];
    foreach ($dates as $date) $ids[$date] = sl_event($pdo, $date);
    return $ids;
}

$SUNDAYS = ['2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01'];

// ---------------------------------------------------------------------------
echo "\nCounting: Sunday days only\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);
    $midweek = sl_event($pdo, '2026-10-08', 'Midweek_Service');
    $special = sl_event($pdo, '2026-10-18', 'Special_Event');
    $spaced = sl_event($pdo, '2026-11-08', 'Sunday Service');

    sl_user($pdo, 1, '1st_Timer');
    sl_present($pdo, 1, $sun['2026-10-04'], '2026-10-04');
    sl_present($pdo, 1, $midweek, '2026-10-08');
    sl_present($pdo, 1, $special, '2026-10-18');
    same('a midweek and a special event add nothing to one Sunday', [1 => 1], ladder_sunday_counts($pdo, [1]));

    sl_user($pdo, 2, '1st_Timer');
    sl_present($pdo, 2, $sun['2026-10-11'], '2026-10-11');
    sl_qr($pdo, 2, $sun['2026-10-11'], '2026-10-11');
    same('a QR check-in and a roster clock-in on one Sunday are one time', [2 => 1], ladder_sunday_counts($pdo, [2]));

    sl_user($pdo, 3, '1st_Timer');
    $second = sl_event($pdo, '2026-10-18');
    sl_present($pdo, 3, $sun['2026-10-18'], '2026-10-18');
    sl_present($pdo, 3, $second, '2026-10-18');
    same('two services on one Sunday are one time', [3 => 1], ladder_sunday_counts($pdo, [3]));

    sl_user($pdo, 4, '1st_Timer');
    sl_present($pdo, 4, $spaced, '2026-11-08');
    same("an event stored as 'Sunday Service' still counts", [4 => 1], ladder_sunday_counts($pdo, [4]));

    sl_user($pdo, 5, '1st_Timer');
    sl_present($pdo, 5, $sun['2026-10-04'], '2026-10-04', 'Absent');
    same('a row that is not Present does not count', [], ladder_sunday_counts($pdo, [5]));

    sl_user($pdo, 6, '1st_Timer');
    sl_qr($pdo, 6, $midweek, '2026-10-08');
    same('a midweek QR check-in does not count', [], ladder_sunday_counts($pdo, [6]));

    $all = ladder_sunday_counts($pdo, [1, 2, 3, 4, 5, 6]);
    same('one grouped query answers for everyone', [1 => 1, 2 => 1, 3 => 1, 4 => 1], $all);

    ok('the shared union helper refuses anything but plain category words', (function () {
        try {
            assim_attendance_union_sql(["x') OR 1=1 --"]);
            return false;
        } catch (InvalidArgumentException $e) {
            return true;
        }
    })());
    ok('the unfiltered union is still every service',
        strpos(assim_attendance_union_sql(), 'JOIN events') === false);
}

// ---------------------------------------------------------------------------
echo "\nThe ladder: 1, 2, 3 Sundays, then Visitor\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);
    sl_user($pdo, 10, '1st_Timer');

    $expected = [
        '2026-10-04' => '1st_Timer',
        '2026-10-11' => '2nd_Timer',
        '2026-10-18' => '3rd_Timer',
        '2026-10-25' => 'Visitor',
        '2026-11-01' => 'Visitor',
    ];
    foreach ($expected as $date => $status) {
        sl_present($pdo, 10, $sun[$date], $date);
        ladder_advance_user($pdo, 10, null, $date . ' 10:00:00');
        same("after the Sunday of {$date} they are {$status}", $status, sl_status($pdo, 10));
    }
    same('three moves were recorded, one per step', [
        ['from_status' => '1st_Timer', 'to_status' => '2nd_Timer', 'source' => 'attendance_ladder'],
        ['from_status' => '2nd_Timer', 'to_status' => '3rd_Timer', 'source' => 'attendance_ladder'],
        ['from_status' => '3rd_Timer', 'to_status' => 'Visitor',   'source' => 'attendance_ladder'],
    ], sl_history($pdo, 10));

    $again = ladder_advance_user($pdo, 10);
    ok('running it again changes nothing', $again === null && count(sl_history($pdo, 10)) === 3);

    // Someone clocked in twice for the same Sunday must not move.
    sl_user($pdo, 11, '1st_Timer');
    sl_present($pdo, 11, $sun['2026-10-04'], '2026-10-04');
    sl_qr($pdo, 11, $sun['2026-10-04'], '2026-10-04');
    ladder_advance_user($pdo, 11);
    same('the same Sunday twice is still a 1st Timer', '1st_Timer', sl_status($pdo, 11));

    // A midweek clock-in never moves anyone.
    $midweek = sl_event($pdo, '2026-10-08', 'Midweek_Service');
    sl_user($pdo, 12, '1st_Timer');
    sl_present($pdo, 12, $sun['2026-10-04'], '2026-10-04');
    sl_present($pdo, 12, $midweek, '2026-10-08');
    $r = ladder_advance_user($pdo, 12);
    ok('a Sunday plus a midweek is still one time', $r === null && sl_status($pdo, 12) === '1st_Timer');
}

// ---------------------------------------------------------------------------
echo "\nWho the ladder never touches\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);
    $id = 20;
    $untouchable = ['Member', 'Worker', 'Pastor', 'Non_Member', 'Visitor', 'Unspecified', ''];
    foreach ($untouchable as $status) {
        $id++;
        sl_user($pdo, $id, $status);
        foreach ($SUNDAYS as $date) sl_present($pdo, $id, $sun[$date], $date);
        ladder_advance_user($pdo, $id);
        same("a {$status} with five Sundays is left as they are", $status, sl_status($pdo, $id));
    }
    same('and nothing was logged for any of them', 0, (int) $pdo->query("SELECT COUNT(*) FROM user_status_history")->fetchColumn());

    $departed = [
        ['Relocated', 0], ['Attends Another Church', 0], ['Attends_Another_Church', 0], ['Active', 1],
    ];
    foreach ($departed as [$attendance, $exempt]) {
        $id++;
        sl_user($pdo, $id, '1st_Timer', $attendance, $exempt);
        foreach ($SUNDAYS as $date) sl_present($pdo, $id, $sun[$date], $date);
        ladder_advance_user($pdo, $id);
        same("a 1st Timer who has left ({$attendance}, exempt {$exempt}) is not moved", '1st_Timer', sl_status($pdo, $id));
    }

    $id++;
    sl_user($pdo, $id, '1st_Timer', 'Unknown');
    foreach (array_slice($SUNDAYS, 0, 2) as $date) sl_present($pdo, $id, $sun[$date], $date);
    ladder_advance_user($pdo, $id);
    same("'Unknown' means hard to reach, not departed: they still move", '2nd_Timer', sl_status($pdo, $id));

    same('a person who does not exist is a quiet no-op', null, ladder_advance_user($pdo, 9999));
}

// ---------------------------------------------------------------------------
echo "\nForward only, and the catch-up sweep\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);

    sl_user($pdo, 30, '1st_Timer');   // 5 Sundays -> Visitor in one move
    foreach ($SUNDAYS as $d) sl_present($pdo, 30, $sun[$d], $d);
    sl_user($pdo, 31, '1st_Timer');   // 3 Sundays -> 3rd
    foreach (array_slice($SUNDAYS, 0, 3) as $d) sl_present($pdo, 31, $sun[$d], $d);
    sl_user($pdo, 32, '1st_Timer');   // 2 Sundays -> 2nd
    foreach (array_slice($SUNDAYS, 0, 2) as $d) sl_present($pdo, 32, $sun[$d], $d);
    sl_user($pdo, 33, '1st_Timer');   // no Sundays -> stays
    sl_user($pdo, 34, '3rd_Timer');   // set by hand, one Sunday: never lowered
    sl_present($pdo, 34, $sun[$SUNDAYS[0]], $SUNDAYS[0]);
    sl_user($pdo, 35, '2nd_Timer');   // set by hand, three Sundays: moves up
    foreach (array_slice($SUNDAYS, 0, 3) as $d) sl_present($pdo, 35, $sun[$d], $d);
    sl_user($pdo, 36, 'Member');      // never touched
    foreach ($SUNDAYS as $d) sl_present($pdo, 36, $sun[$d], $d);
    sl_user($pdo, 37, '1st_Timer', 'Relocated');
    foreach ($SUNDAYS as $d) sl_present($pdo, 37, $sun[$d], $d);

    $dry = ladder_sweep($pdo, false);
    same('a dry run reports the moves', [30, 31, 32, 35], array_column($dry['moved'], 'id'));
    same('a dry run writes nothing', '1st_Timer', sl_status($pdo, 30));
    same('a dry run logs nothing', 0, (int) $pdo->query("SELECT COUNT(*) FROM user_status_history")->fetchColumn());

    $run = ladder_sweep($pdo, true, '2026-11-02 08:00:00');
    same('scanned every 1st/2nd/3rd Timer', 7, $run['scanned']);
    same('one has left and is skipped', 1, $run['departed']);
    same('5 Sundays jumps straight to Visitor', 'Visitor', sl_status($pdo, 30));
    same('3 Sundays is a 3rd Timer', '3rd_Timer', sl_status($pdo, 31));
    same('2 Sundays is a 2nd Timer', '2nd_Timer', sl_status($pdo, 32));
    same('no Sundays stays a 1st Timer', '1st_Timer', sl_status($pdo, 33));
    same('a 3rd Timer set by hand with one Sunday is never lowered', '3rd_Timer', sl_status($pdo, 34));
    same('a 2nd Timer set by hand with three Sundays moves up', '3rd_Timer', sl_status($pdo, 35));
    same('a Member is untouched', 'Member', sl_status($pdo, 36));
    same('someone who relocated is untouched', '1st_Timer', sl_status($pdo, 37));
    same('the jump is one history row, from where they were', [
        ['from_status' => '1st_Timer', 'to_status' => 'Visitor', 'source' => 'attendance_ladder'],
    ], sl_history($pdo, 30));

    $rerun = ladder_sweep($pdo);
    same('running the sweep again moves nobody', [], $rerun['moved']);
    same('and failed nobody', 0, $rerun['failed']);

    // A staff edit landing between the read and the write must win.
    $lost = ladder_apply($pdo, 33, '2nd_Timer', '3rd_Timer', LADDER_SOURCE);
    ok('a stale write is refused and leaves no history', $lost === false && sl_history($pdo, 33) === []);
}

// ---------------------------------------------------------------------------
echo "\nWithdrawing a clock-in takes back what it earned\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);
    $rows = [];
    sl_user($pdo, 40, '1st_Timer');
    foreach (array_slice($SUNDAYS, 0, 4) as $d) {
        $rows[$d] = sl_present($pdo, 40, $sun[$d], $d);
        ladder_advance_user($pdo, 40, null, $d . ' 10:00:00');
    }
    same('four Sundays made them a Visitor', 'Visitor', sl_status($pdo, 40));

    $pdo->exec("DELETE FROM attendance WHERE id = " . $rows['2026-10-25']);
    $undo = ladder_rollback_user($pdo, 40, null, '2026-10-26 09:00:00');
    same('undoing the 4th Sunday takes them back to 3rd Timer', '3rd_Timer', sl_status($pdo, 40));
    same('and says what it did', ['from' => 'Visitor', 'to' => '3rd_Timer', 'days' => 3], $undo);

    $pdo->exec("DELETE FROM attendance WHERE id = " . $rows['2026-10-18']);
    ladder_rollback_user($pdo, 40);
    same('undoing a second clock-in goes down one more step', '2nd_Timer', sl_status($pdo, 40));
    $pdo->exec("DELETE FROM attendance WHERE id = " . $rows['2026-10-11']);
    ladder_rollback_user($pdo, 40);
    same('and again, back to where the ladder found them', '1st_Timer', sl_status($pdo, 40));
    same('and never below that', null, ladder_rollback_user($pdo, 40));

    $undoRows = array_filter(sl_history($pdo, 40), fn($r) => $r['source'] === 'attendance_ladder_undo');
    same('each take-back is on the record as an undo', 3, count($undoRows));

    // The clock-in is put back: they climb again.
    sl_present($pdo, 40, $sun['2026-10-11'], '2026-10-11');
    ladder_advance_user($pdo, 40);
    same('clocking them in again promotes them again', '2nd_Timer', sl_status($pdo, 40));

    // A hand-set status is a floor.
    sl_user($pdo, 41, '2nd_Timer');
    foreach (array_slice($SUNDAYS, 0, 3) as $d) $rows41[$d] = sl_present($pdo, 41, $sun[$d], $d);
    ladder_advance_user($pdo, 41);
    same('a hand-set 2nd Timer with three Sundays is promoted to 3rd', '3rd_Timer', sl_status($pdo, 41));
    $pdo->exec("DELETE FROM attendance WHERE user_id = 41");
    ladder_rollback_user($pdo, 41);
    same('with every Sunday withdrawn they return to the 2nd Timer staff set, no lower', '2nd_Timer', sl_status($pdo, 41));

    // A change made by hand after the promotion is never rolled back.
    sl_user($pdo, 42, '1st_Timer');
    foreach (array_slice($SUNDAYS, 0, 2) as $d) $rows42[$d] = sl_present($pdo, 42, $sun[$d], $d);
    ladder_advance_user($pdo, 42);
    $pdo->exec("UPDATE users SET spiritual_status = '3rd_Timer' WHERE id = 42");
    ladder_log_change($pdo, 42, '2nd_Timer', '3rd_Timer', 'events_profile', 5);
    $pdo->exec("DELETE FROM attendance WHERE user_id = 42");
    same('a status staff changed after the ladder is not rolled back', null, ladder_rollback_user($pdo, 42));
    same('and stays as staff left it', '3rd_Timer', sl_status($pdo, 42));

    // A status changed without being recorded is not touched either.
    sl_user($pdo, 43, '3rd_Timer');
    same('a person the ladder never moved has nothing to roll back', null, ladder_rollback_user($pdo, 43));

    // A rollback that the remaining Sundays do not call for does nothing.
    sl_user($pdo, 44, '1st_Timer');
    foreach (array_slice($SUNDAYS, 0, 3) as $d) sl_present($pdo, 44, $sun[$d], $d);
    ladder_advance_user($pdo, 44);
    same('three Sundays is a 3rd Timer', '3rd_Timer', sl_status($pdo, 44));
    same('with all three still there nothing is rolled back', null, ladder_rollback_user($pdo, 44));
}

// ---------------------------------------------------------------------------
echo "\nEmbrace: a graduated Visitor stays until followed up\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);

    // A graduated by the ladder, never assigned a worker.
    // B an ordinary Visitor (Congregation added them).
    // C graduated, latest follow-up Completed.   D graduated, Pending.
    // E graduated, Unreachable.                  F graduated, then staff changed them.
    // G graduated, an old follow-up Completed but reassigned: latest Pending.
    foreach ([50, 51, 52, 53, 54, 55, 56] as $id) sl_user($pdo, $id, 'Visitor');
    sl_user($pdo, 57, '2nd_Timer');
    sl_user($pdo, 58, 'Member');
    foreach ([50, 52, 53, 54, 55, 56] as $id) ladder_log_change($pdo, $id, '3rd_Timer', 'Visitor', LADDER_SOURCE);
    foreach ([['id' => 52, 's' => 'Completed'], ['id' => 53, 's' => 'Pending'], ['id' => 54, 's' => 'Unreachable'],
              ['id' => 56, 's' => 'Completed'], ['id' => 56, 's' => 'Pending']] as $f) {
        $pdo->prepare("INSERT INTO embrace_followups (visitor_id, status) VALUES (?, ?)")->execute([$f['id'], $f['s']]);
    }
    ladder_log_change($pdo, 55, 'Visitor', 'Member', 'congregation_edit', 3);
    $pdo->exec("UPDATE users SET spiritual_status = 'Visitor' WHERE id = 55");
    ladder_log_change($pdo, 55, 'Member', 'Visitor', 'congregation_edit', 3);

    $pipeline = embrace_pipeline_sql($pdo, 'u');
    $in = array_map('intval', $pdo->query("SELECT u.id FROM users u WHERE $pipeline ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN));
    same('the active list is the timers plus owed graduates', [50, 53, 56, 57], $in);

    $archive = array_map('intval', $pdo->query("
        SELECT u.id FROM users u
        JOIN embrace_followups f ON f.visitor_id = u.id AND f.id = (SELECT MAX(id) FROM embrace_followups WHERE visitor_id = u.id)
        WHERE u.spiritual_status IS NOT NULL AND NOT $pipeline ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN));
    same('the archive is exactly the people who finished (and is disjoint from the list)', [52, 54], $archive);

    $pdo->exec("INSERT INTO embrace_followups (visitor_id, status) VALUES (50, 'Completed')");
    $in2 = array_map('intval', $pdo->query("SELECT u.id FROM users u WHERE $pipeline ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN));
    same('a final report takes a graduate off the list', [53, 56, 57], $in2);

    $bare = sl_db(false);
    sl_user($bare, 1, '1st_Timer');
    sl_user($bare, 2, 'Visitor');
    $in3 = array_map('intval', $bare->query("SELECT u.id FROM users u WHERE " . embrace_pipeline_sql($bare, 'u'))->fetchAll(PDO::FETCH_COLUMN));
    same('before the migration the list is just the timers', [1], $in3);

    ok('the alias is validated', (function () use ($pdo) {
        try { embrace_pipeline_sql($pdo, "u; DROP TABLE users"); return false; }
        catch (InvalidArgumentException $e) { return true; }
    })());
}

// ---------------------------------------------------------------------------
echo "\nReports read a person's status as at the day of the event\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    $sun = sl_sundays($pdo, $SUNDAYS);
    sl_user($pdo, 60, '3rd_Timer');   // 1st -> 2nd on 11 Oct, 2nd -> 3rd on 25 Oct
    sl_user($pdo, 61, 'Member');      // never changed
    foreach ([60, 61] as $uid) foreach ($SUNDAYS as $d) sl_present($pdo, $uid, $sun[$d], $d);
    ladder_log_change($pdo, 60, '1st_Timer', '2nd_Timer', LADDER_SOURCE, null, '2026-10-11 10:00:00');
    ladder_log_change($pdo, 60, '2nd_Timer', '3rd_Timer', LADDER_SOURCE, null, '2026-10-25 10:00:00');

    $statusAt = ladder_status_at_sql($pdo, 'u', 'ev.event_date');
    $status = [];
    foreach ($pdo->query("SELECT ev.id AS event_id, u.id AS uid, $statusAt AS s
                            FROM events ev JOIN users u ON u.id IN (60, 61)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $status[(int) $r['uid']][(int) $r['event_id']] = $r['s'];
    }
    same('4 Oct: they had not been promoted yet', '1st_Timer', $status[60][$sun['2026-10-04']]);
    same('11 Oct: a change on the day counts', '2nd_Timer', $status[60][$sun['2026-10-11']]);
    same('18 Oct: between the two changes', '2nd_Timer', $status[60][$sun['2026-10-18']]);
    same('25 Oct: the day of the second change', '3rd_Timer', $status[60][$sun['2026-10-25']]);
    same('1 Nov: no later change, so the current status', '3rd_Timer', $status[60][$sun['2026-11-01']]);
    same('someone never changed is always their status', 'Member', $status[61][$sun['2026-10-04']]);

    $bare = sl_db(false);
    sl_user($bare, 1, 'Visitor');
    same('before the migration a report just reads the current status', 'u.spiritual_status',
        ladder_status_at_sql($bare, 'u', 'ev.event_date'));

    ok('only column references are accepted', (function () use ($pdo) {
        try { ladder_status_at_sql($pdo, 'u', "ev.event_date) OR (1=1"); return false; }
        catch (InvalidArgumentException $e) { return true; }
    })());

    // The real report code. Oct 4's service must still show one first timer
    // after they have been promoted twice.
    $stats = ea_bulk_attendance_stats($pdo, array_values($sun));
    same('Event Analytics: 4 Oct still counts a 1st Timer', 1, $stats['per_event'][$sun['2026-10-04']]['status']['1st_Timer']);
    same('Event Analytics: 11 Oct counts a 2nd Timer', 1, $stats['per_event'][$sun['2026-10-11']]['status']['2nd_Timer']);
    same('Event Analytics: 1 Nov counts a 3rd Timer', 1, $stats['per_event'][$sun['2026-11-01']]['status']['3rd_Timer']);
    same('Event Analytics: the Member is a Member every week', 1, $stats['per_event'][$sun['2026-10-18']]['status']['Member']);
    same('Event Analytics: totals are unchanged by the status join', 2, $stats['per_event'][$sun['2026-10-04']]['total']);

    $roster = ea_event_roster($pdo, $sun['2026-10-04']);
    $byId = [];
    foreach ($roster as $r) $byId[(int) $r['id']] = $r['spiritual_status'];
    same('the event roster shows status as it was that Sunday', ['60' => '1st_Timer', '61' => 'Member'],
        array_map('strval', array_combine(array_map('strval', array_keys($byId)), array_values($byId))));
}

// ---------------------------------------------------------------------------
echo "\nBefore the migration: nothing moves and nothing breaks\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db(false);
    $sun = sl_sundays($pdo, $SUNDAYS);
    sl_user($pdo, 70, '1st_Timer');
    foreach ($SUNDAYS as $d) sl_present($pdo, 70, $sun[$d], $d);

    ok('the ladder says it is not ready', ladder_ready($pdo) === false);
    same('a clock-in moves nobody', null, ladder_advance_user($pdo, 70));
    same('a withdrawn clock-in rolls nothing back', null, ladder_rollback_user($pdo, 70));
    $sweep = ladder_sweep($pdo);
    ok('the sweep reports not ready and moves nobody', $sweep['ready'] === false && $sweep['moved'] === []);
    same('they are exactly where they were', '1st_Timer', sl_status($pdo, 70));
    same('there is no status to remember', null, ladder_status_of($pdo, 70));
    ladder_note_change($pdo, 70, null, 'events_profile');
    ladder_forget_user($pdo, 70);
    ok('note and forget are quiet no-ops', true);
    ok('the event reports still run', isset(ea_bulk_attendance_stats($pdo, array_values($sun))['per_event']));

    // The table appears while the process is running (the deploy ran migrate.php).
    $pdo->exec("CREATE TABLE user_status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        from_status TEXT NOT NULL DEFAULT '', to_status TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL, changed_by INTEGER NULL, changed_at TEXT NOT NULL)");
    ok('and the ladder starts working without a restart', ladder_ready($pdo) === true);
    ladder_advance_user($pdo, 70);
    same('now the five Sundays count', 'Visitor', sl_status($pdo, 70));
}

// ---------------------------------------------------------------------------
echo "\nStatus history written by the screens\n";
// ---------------------------------------------------------------------------
{
    $pdo = sl_db();
    sl_user($pdo, 80, 'Visitor');

    $before = ladder_status_of($pdo, 80);
    $pdo->exec("UPDATE users SET spiritual_status = 'Member' WHERE id = 80");
    ladder_note_change($pdo, 80, $before, 'events_profile', 7);
    same('a changed status is recorded with who and where', [
        ['from_status' => 'Visitor', 'to_status' => 'Member', 'source' => 'events_profile'],
    ], sl_history($pdo, 80));
    same('changed_by is the person who saved it', 7, (int) $pdo->query("SELECT changed_by FROM user_status_history WHERE user_id = 80")->fetchColumn());

    $before = ladder_status_of($pdo, 80);
    ladder_note_change($pdo, 80, $before, 'events_profile', 7);
    same('saving with the status unchanged records nothing', 1, count(sl_history($pdo, 80)));

    ladder_forget_user($pdo, 80);
    same('deleting a person removes their history', [], sl_history($pdo, 80));
}

// ---------------------------------------------------------------------------
echo "\nWiring (static; the engine tests above are what prove behaviour)\n";
// ---------------------------------------------------------------------------
{
    $root = dirname(__DIR__);
    $has = function (string $file, string $needle) use ($root): bool {
        return strpos((string) file_get_contents($root . '/' . $file), $needle) !== false;
    };
    ok('a roster clock-in runs the ladder', $has('api/events_api.php', 'ladder_advance_user($pdo, (int) $target_user_id)'));
    ok('clocking out runs the rollback', $has('api/events_api.php', 'ladder_rollback_user($pdo, (int) $target_user_id)'));
    ok('QR check-in runs the ladder (registered) and (walk-in)', substr_count((string) file_get_contents($root . '/api/checkin_api.php'), 'ladder_advance_user(') === 2);
    ok('the Events profile editor records status changes', $has('api/events_api.php', "'events_profile'"));
    ok('the Congregation editor records status changes', $has('api/congregation_api.php', "'congregation_edit'"));
    ok('Embrace push records status changes', $has('api/embrace_api.php', "'embrace_push'"));
    ok('the Academy graduation records status changes', $has('api/academy_api.php', "'academy_graduate'"));
    ok('Embrace lists use the shared pipeline predicate', substr_count((string) file_get_contents($root . '/api/embrace_api.php'), 'embrace_pipeline_sql(') === 3);
    ok('the Excel register uses it too', $has('includes/embrace_export_excel.php', 'embrace_pipeline_sql('));

    $migration = (string) file_get_contents($root . '/db/migrations/20261122090000_user_status_history.sql');
    foreach (['user_id', 'from_status', 'to_status', 'source', 'changed_by', 'changed_at', 'idx_ush_user_changed'] as $col) {
        ok("the migration declares {$col}", strpos($migration, $col) !== false);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
