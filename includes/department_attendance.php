<?php
// /includes/department_attendance.php
//
// Department attendance: for ONE event, who on the live ministry year's
// department rosters is present and who is absent, grouped by department.
//
// This is a second view of the Events attendance, not a second record. A
// clock-in writes the same `attendance` row Events writes, so the Events
// screen, the analytics and the "was this person in church?" helper
// (assim_attendance_union_sql) all agree. A person counts as present when the
// event has an `attendance` row for them OR a QR `checkins` row (the door
// page writes both for a member, but a row in either is enough).
//
// Who is expected: every Active row of the live ministry year, in any
// department, Primary or Secondary, leaders included. Someone on two rosters
// is listed under both departments but counted ONCE in the totals.
//
// Plain PDO and portable SQL (no NOW(), no FIELD()), so tests/department_
// attendance_test.php can run it against SQLite.

/**
 * Open events to take attendance for, newest first (the same list the Events
 * Attendance tab offers: not closed, latest 50).
 */
function dept_att_events(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, title, event_category, event_date, end_date, is_closed
           FROM events WHERE is_closed = 0
          ORDER BY event_date DESC, id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_closed'] = (int) $r['is_closed'];
    }
    unset($r);

    return $rows;
}

/** One event the attendance can be taken for, or null. */
function dept_att_event(PDO $pdo, int $eventId): ?array
{
    $st = $pdo->prepare('SELECT id, title, event_category, event_date, end_date, is_closed FROM events WHERE id = ?');
    $st->execute([$eventId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['is_closed'] = (int) $row['is_closed'];

    return $row;
}

/** "9:41 PM" from a stored date-time, or '' when there is none. */
function dept_att_time(?string $stamp): string
{
    if ($stamp === null || $stamp === '') {
        return '';
    }
    $t = strtotime($stamp);

    return $t === false ? '' : date('g:i A', $t);
}

/**
 * The roll for one event.
 *
 * @param string[] $leaderRoles roster values of the three leadership seats
 * @return array{departments: array, people: array, totals: array}
 */
function dept_att_roll(PDO $pdo, int $eventId, int $yearId, array $leaderRoles = []): array
{
    // Who is expected.
    $st = $pdo->prepare(
        "SELECT ud.department_id, ud.role_in_dept, ud.membership_type,
                u.id AS user_id, u.first_name, u.last_name, u.phone,
                d.name AS dept_name, d.parent_id, p.name AS parent_name
           FROM user_departments ud
           JOIN users u ON u.id = ud.user_id
           JOIN departments d ON d.id = ud.department_id
           LEFT JOIN departments p ON p.id = d.parent_id
          WHERE ud.ministry_year_id = ? AND ud.roster_status = 'Active'
            AND COALESCE(u.attendance_status, '') <> 'Relocated'"
    );
    $st->execute([$yearId]);
    $roster = $st->fetchAll(PDO::FETCH_ASSOC);

    // Who is here: a desk clock-in or a door check-in, earliest time wins.
    $here = [];
    $door = [];
    $a = $pdo->prepare(
        "SELECT user_id, MIN(check_in_time) AS at FROM attendance
          WHERE event_id = ? AND status = 'Present' AND user_id IS NOT NULL GROUP BY user_id"
    );
    $a->execute([$eventId]);
    foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $here[(int) $r['user_id']] = (string) $r['at'];
    }
    $c = $pdo->prepare(
        "SELECT user_id, MIN(checked_in_at) AS at FROM checkins
          WHERE event_id = ? AND user_id IS NOT NULL GROUP BY user_id"
    );
    $c->execute([$eventId]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int) $r['user_id'];
        $door[$uid] = true;
        if (!isset($here[$uid]) || ((string) $r['at'] !== '' && (string) $r['at'] < $here[$uid])) {
            $here[$uid] = (string) $r['at'];
        }
    }

    $departments = [];
    $people = [];
    foreach ($roster as $r) {
        $uid = (int) $r['user_id'];
        $did = (int) $r['department_id'];
        $present = isset($here[$uid]);
        $name = trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name']));
        $time = $present ? dept_att_time($here[$uid]) : '';

        if (!isset($departments[$did])) {
            $departments[$did] = [
                'id' => $did, 'name' => (string) $r['dept_name'],
                'parent' => $r['parent_name'] !== null ? (string) $r['parent_name'] : null,
                'present' => 0, 'absent' => 0, 'total' => 0, 'members' => [],
            ];
        }
        $departments[$did]['members'][] = [
            'user_id' => $uid, 'name' => $name, 'phone' => (string) ($r['phone'] ?? ''),
            'seat' => (string) $r['role_in_dept'],
            'leader' => in_array((string) $r['role_in_dept'], $leaderRoles, true),
            'membership' => (string) $r['membership_type'],
            'present' => $present, 'time' => $time, 'door' => isset($door[$uid]),
        ];
        $departments[$did]['total']++;
        $departments[$did][$present ? 'present' : 'absent']++;

        if (!isset($people[$uid])) {
            $people[$uid] = [
                'user_id' => $uid, 'name' => $name, 'phone' => (string) ($r['phone'] ?? ''),
                'present' => $present, 'time' => $time, 'door' => isset($door[$uid]),
                'departments' => [], 'dept_ids' => [],
            ];
        }
        $label = $r['parent_name'] !== null ? $r['dept_name'] . ' · ' . $r['parent_name'] : (string) $r['dept_name'];
        $people[$uid]['departments'][] = $label;
        $people[$uid]['dept_ids'][] = $did;
    }

    foreach ($departments as &$d) {
        usort($d['members'], static function (array $x, array $y): int {
            return [$x['leader'] ? 0 : 1, $x['membership'] === 'Primary' ? 0 : 1, mb_strtolower($x['name'])]
                <=> [$y['leader'] ? 0 : 1, $y['membership'] === 'Primary' ? 0 : 1, mb_strtolower($y['name'])];
        });
    }
    unset($d);
    $departments = array_values($departments);
    usort($departments, static fn (array $x, array $y): int => strcasecmp($x['name'], $y['name']));

    $people = array_values($people);
    usort($people, static fn (array $x, array $y): int => strcasecmp($x['name'], $y['name']));

    $presentCount = count(array_filter($people, static fn (array $p): bool => $p['present']));
    $outside = 0;
    foreach (array_keys($here) as $uid) {
        $onRoster = false;
        foreach ($people as $p) {
            if ($p['user_id'] === $uid) {
                $onRoster = true;
                break;
            }
        }
        if (!$onRoster) {
            $outside++;
        }
    }
    $workers = count($people);

    return [
        'departments' => $departments,
        'people' => $people,
        'totals' => [
            'workers' => $workers,
            'present' => $presentCount,
            'absent' => $workers - $presentCount,
            'outside' => $outside,
            'percent' => $workers > 0 ? (int) round($presentCount * 100 / $workers) : 0,
        ],
    ];
}

/**
 * Is this a workers-only event? The "Workers' Meeting" category says so
 * outright. The roster LIST also still treats a title that says "worker" that
 * way (the Events attendance tab always has), but that is only a guess, so
 * with $strict (what a clock-in is refused on) only the category counts: a
 * "Workers' Appreciation Dinner" with guests must not lock its guests out.
 *
 * @param array $event needs event_category and title
 */
function dept_att_workers_only(array $event, bool $strict = false): bool
{
    if (($event['event_category'] ?? '') === 'Workers_Meeting') {
        return true;
    }

    return !$strict && stripos((string) ($event['title'] ?? ''), 'worker') !== false;
}

/**
 * "Serving now" on a roster row aliased $alias: in the live year, and not
 * Removed inside it (Departments' own meaning, as the dashboard's Workforce
 * uses it). Before the ministry-years migration there is no roster_status and
 * is_active alone says the same.
 */
function dept_att_live_roster_sql(PDO $pdo, string $alias = 'ud'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('dept_att_live_roster_sql takes a table alias.');
    }
    $live = "{$alias}.is_active = 1";
    try {
        $pdo->query('SELECT roster_status FROM user_departments LIMIT 0');
        $live .= " AND {$alias}.roster_status = 'Active'";
    } catch (Throwable $e) {
        // pre-migration: is_active alone
    }

    return "({$live})";
}

/** Is this person serving on a live department roster right now? */
function dept_att_is_worker(PDO $pdo, int $userId): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM user_departments ud WHERE ud.user_id = ? AND ' . dept_att_live_roster_sql($pdo, 'ud') . ' LIMIT 1'
    );
    $st->execute([$userId]);

    return (bool) $st->fetchColumn();
}

/** Is this person on a live-year roster (the only people a workers' roll takes)? */
function dept_att_on_roster(PDO $pdo, int $userId, int $yearId): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM user_departments ud JOIN users u ON u.id = ud.user_id
          WHERE ud.user_id = ? AND ud.ministry_year_id = ? AND ud.roster_status = 'Active'
            AND COALESCE(u.attendance_status, '') <> 'Relocated' LIMIT 1"
    );
    $st->execute([$userId, $yearId]);

    return (bool) $st->fetchColumn();
}

/**
 * May someone without the Events permission clock this person in? Only a head
 * of department (a scope of departments, as dept_manageable_ids() gives it) for
 * a person on one of THEIR rosters. $scope null means every department.
 */
function dept_att_in_scope(PDO $pdo, int $userId, int $yearId, ?array $scope): bool
{
    if ($scope === null) {
        return true;
    }
    $ids = array_values(array_filter(array_map('intval', $scope)));
    if ($ids === []) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM user_departments
          WHERE user_id = ? AND ministry_year_id = ? AND roster_status = 'Active'
            AND department_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') LIMIT 1'
    );
    $st->execute(array_merge([$userId, $yearId], $ids));

    return (bool) $st->fetchColumn();
}

/**
 * Clock one worker in. Same row Events writes; refuses a closed event, someone
 * who is not on a live roster, and a second clock-in (desk or door).
 *
 * @return array{status: string, message: string, time?: string}
 */
function dept_att_mark(PDO $pdo, int $eventId, int $userId, int $byId, int $yearId, string $now): array
{
    $event = dept_att_event($pdo, $eventId);
    if (!$event) {
        return ['status' => 'error', 'message' => 'Event not found.'];
    }
    if ($event['is_closed'] === 1) {
        return ['status' => 'error', 'message' => 'This event is closed.'];
    }
    if (!dept_att_on_roster($pdo, $userId, $yearId)) {
        return ['status' => 'error', 'message' => 'Not on a department roster.'];
    }

    $dup = $pdo->prepare('SELECT 1 FROM attendance WHERE event_id = ? AND user_id = ?');
    $dup->execute([$eventId, $userId]);
    if ($dup->fetchColumn()) {
        return ['status' => 'warning', 'message' => 'Already clocked in.'];
    }
    $door = $pdo->prepare('SELECT 1 FROM checkins WHERE event_id = ? AND user_id = ?');
    $door->execute([$eventId, $userId]);
    if ($door->fetchColumn()) {
        return ['status' => 'warning', 'message' => 'Already checked in at the door.'];
    }

    $pdo->prepare(
        "INSERT INTO attendance (event_id, user_id, status, check_in_time, checked_in_by, attendance_date)
         VALUES (?, ?, 'Present', ?, ?, ?)"
    )->execute([$eventId, $userId, $now, $byId, substr($now, 0, 10)]);

    // Same as the Events screen: a Sunday clock-in can move a Timer up the ladder.
    if (function_exists('ladder_advance_user')) {
        ladder_advance_user($pdo, $userId);
    }

    return ['status' => 'success', 'message' => 'Clocked in.', 'time' => dept_att_time($now)];
}

/**
 * Undo a desk clock-in. A door (QR) check-in is a record the person made
 * themselves, so it is never removed from here.
 *
 * @return array{status: string, message: string}
 */
function dept_att_unmark(PDO $pdo, int $eventId, int $userId): array
{
    $event = dept_att_event($pdo, $eventId);
    if (!$event) {
        return ['status' => 'error', 'message' => 'Event not found.'];
    }
    if ($event['is_closed'] === 1) {
        return ['status' => 'error', 'message' => 'This event is closed.'];
    }
    $door = $pdo->prepare('SELECT 1 FROM checkins WHERE event_id = ? AND user_id = ?');
    $door->execute([$eventId, $userId]);
    if ($door->fetchColumn()) {
        return ['status' => 'warning', 'message' => 'Checked in at the door. It stays.'];
    }

    $del = $pdo->prepare('DELETE FROM attendance WHERE event_id = ? AND user_id = ?');
    $del->execute([$eventId, $userId]);
    if ($del->rowCount() === 0) {
        return ['status' => 'warning', 'message' => 'Was not clocked in.'];
    }
    if (function_exists('ladder_rollback_user')) {
        ladder_rollback_user($pdo, $userId);
    }

    return ['status' => 'success', 'message' => 'Moved back to absent.'];
}

/**
 * The absent (or whole) roll as flat rows for a download, one per person per
 * department, department by department.
 *
 * @return array<int, array<string, string>>
 */
function dept_att_export_rows(array $roll, string $scope, array $seatLabels = []): array
{
    $rows = [];
    foreach ($roll['departments'] as $d) {
        foreach ($d['members'] as $m) {
            if ($scope === 'absent' && $m['present']) {
                continue;
            }
            $rows[] = [
                'Department' => $d['name'],
                'Part of' => (string) ($d['parent'] ?? ''),
                'Name' => $m['name'],
                'Phone' => $m['phone'],
                'Seat' => $m['leader'] ? ($seatLabels[$m['seat']] ?? str_replace('_', ' ', $m['seat'])) : $m['membership'],
                'Status' => $m['present'] ? 'Present' : 'Absent',
                'Time in' => $m['time'],
            ];
        }
    }

    return $rows;
}

/** A spreadsheet cell that cannot run as a formula (names and phones come from people). */
function dept_att_csv_cell(string $v): string
{
    if ($v === '' || strpbrk($v[0], "=+-@\t\r") === false) {
        return $v;
    }

    // A phone such as +234 803 123 4567 is only digits and punctuation: leave it.
    return preg_match('/^\+?[\d\s().-]+$/', $v) ? $v : "'" . $v;
}
