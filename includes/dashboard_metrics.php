<?php
// /includes/dashboard_metrics.php
//
// The numbers on the home dashboard's cards (api/dashboard_api.php) and the
// breakdowns behind them. Each card is the SAME number another screen shows,
// asked the way that screen asks it — a card that disagrees with the module it
// summarises is worse than no card, and three of the old ones did:
//
//   Workforce     Departments' live headcount: distinct people active on a
//                 roster in the live ministry year (dept_live_headcount()).
//                 Not the 'Worker' status label, which nothing keeps current.
//   New Pipeline  Embrace's active list (embrace_pipeline_sql()). It used to be
//                 Visitor + 1st_Timer: every 2nd and 3rd Timer missing, and
//                 every ordinary Visitor ever recorded counted for ever.
//   Follow-ups    people on that list whose latest follow-up is still owed.
//   Sunday        distinct people at the latest Sunday service: everyone with
//                 a record through assim_attendance_union_sql(), plus walk-ins
//                 the QR check-in could not match to anyone. It used to count
//                 roster rows over a 7-day window, which on a Sunday added two
//                 Sundays together and never saw a QR-only first timer.
//
// Portable SQL (tests/dashboard_metrics_test.php runs it on SQLite). Dates come
// in from PHP as 'Y-m-d', so nothing depends on the database's CURDATE().

require_once __DIR__ . '/embrace_helpers.php';

const DASH_SUNDAY_CATEGORY = 'Sunday_Service';

/** Which cards each dashboard tier shows; the detail endpoint answers only these. */
const DASH_TIER_CARDS = [
    'leadership' => ['workforce', 'pipeline', 'followups', 'pending_qa', 'sunday'],
    'director'   => ['team', 'units'],
    'hod'        => ['unit_workers'],
    'member'     => ['alerts'],
];

/** The dashboard tier for $_SESSION['active_role'], as the dashboard has always split it. */
function dash_tier(string $activeRole): string
{
    if (in_array($activeRole, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
        return 'leadership';
    }
    if ($activeRole === 'Director') {
        return 'director';
    }
    if ($activeRole === 'HOD') {
        return 'hod';
    }
    return 'member';
}

/** How many Sundays the Sunday card's breakdown lists. */
const DASH_SUNDAY_HISTORY = 4;

function dash_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
}

function dash_days_before(string $date, int $days): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('Africa/Lagos')))
        ->modify('-' . $days . ' days')->format('Y-m-d');
}

/** "18 Oct", for a 'Y-m-d' (or DATETIME) value. */
function dash_short_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $ts = strtotime(substr($date, 0, 10));
    return $ts === false ? '' : date('j M', $ts);
}

// --------------------------------------------------------------------------
// Workforce
// --------------------------------------------------------------------------

/**
 * "Serving now" on a roster row aliased $alias, as the Departments module
 * means it: is_active is "in the live year", roster_status rules out a row
 * Removed inside that year. Before the ministry-years migration there is no
 * roster_status and is_active alone carries the same meaning.
 */
function dash_roster_live_sql(PDO $pdo, string $alias = 'ud'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('dash_roster_live_sql takes a table alias.');
    }
    $live = "{$alias}.is_active = 1";
    if (ladder_probe($pdo, 'SELECT roster_status FROM user_departments LIMIT 0')) {
        $live .= " AND {$alias}.roster_status = 'Active'";
    }
    return "({$live})";
}

/** Distinct people serving, across all departments or only $deptIds. */
function dash_workforce(PDO $pdo, ?array $deptIds = null): int
{
    $live = dash_roster_live_sql($pdo, 'ud');
    [$in, $params] = dash_id_filter('ud.department_id', $deptIds);
    if ($in === false) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ud.user_id) FROM user_departments ud WHERE $live $in");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** [['id', 'name', 'people'], …], largest first. */
function dash_workforce_by_department(PDO $pdo, ?array $deptIds = null): array
{
    $live = dash_roster_live_sql($pdo, 'ud');
    [$in, $params] = dash_id_filter('ud.department_id', $deptIds);
    if ($in === false) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, COUNT(DISTINCT ud.user_id) AS people
          FROM user_departments ud
          JOIN departments d ON d.id = ud.department_id
         WHERE $live $in
         GROUP BY d.id, d.name
         ORDER BY people DESC, d.name ASC
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'people' => (int) $row['people']];
    }
    return $rows;
}

/**
 * Departments this member leads as $roleInDept ('Director', 'HOD'), on a live
 * roster row.
 */
function dash_led_departments(PDO $pdo, int $userId, string $roleInDept): array
{
    $live = dash_roster_live_sql($pdo, 'ud');
    $stmt = $pdo->prepare("SELECT DISTINCT ud.department_id FROM user_departments ud
                            WHERE ud.user_id = ? AND ud.role_in_dept = ? AND $live
                            ORDER BY ud.department_id");
    $stmt->execute([$userId, $roleInDept]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * ['', []] for "no filter", [' AND col IN (?,…)', ids] for a list, and
 * [false, []] for an empty list (which matches nothing).
 */
function dash_id_filter(string $column, ?array $ids): array
{
    if ($ids === null) {
        return ['', []];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    if (!$ids) {
        return [false, []];
    }
    return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

// --------------------------------------------------------------------------
// Embrace
// --------------------------------------------------------------------------

/** People on Embrace's active list, by status. 'Visitor' = graduated by the ladder, still owed a call. */
function dash_pipeline(PDO $pdo): array
{
    $pipeline = embrace_pipeline_sql($pdo, 'u');
    $out = ['1st_Timer' => 0, '2nd_Timer' => 0, '3rd_Timer' => 0, 'Visitor' => 0];
    foreach ($pdo->query("SELECT u.spiritual_status AS status, COUNT(*) AS n
                            FROM users u WHERE $pipeline
                           GROUP BY u.spiritual_status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string) $row['status']] = (int) $row['n'];
    }
    return ['by_status' => $out, 'total' => array_sum($out)];
}

/**
 * Where the people on Embrace's active list stand with their follow-up, read
 * from each person's LATEST follow-up row (a reassignment adds a row, so
 * counting every 'Pending' row, as the old card did, counted people twice).
 */
function dash_followups(PDO $pdo): array
{
    $pipeline = embrace_pipeline_sql($pdo, 'u');
    $final    = "'" . implode("', '", EMBRACE_FINAL_FOLLOWUP_STATUSES) . "'";
    $row = $pdo->query("
        SELECT COUNT(*) AS people,
               SUM(CASE WHEN f.id IS NULL THEN 1 ELSE 0 END) AS unassigned,
               SUM(CASE WHEN f.id IS NOT NULL AND f.status = 'Pending' THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN f.id IS NOT NULL AND f.status = 'In_Progress' THEN 1 ELSE 0 END) AS in_progress,
               SUM(CASE WHEN f.id IS NOT NULL AND f.status NOT IN ($final) THEN 1 ELSE 0 END) AS open_count,
               SUM(CASE WHEN f.id IS NOT NULL AND f.status IN ($final) THEN 1 ELSE 0 END) AS reported,
               MIN(CASE WHEN f.id IS NOT NULL AND f.status NOT IN ($final) THEN f.followup_date END) AS oldest_open
          FROM users u
          LEFT JOIN embrace_followups f
                 ON f.visitor_id = u.id
                AND f.id = (SELECT MAX(f2.id) FROM embrace_followups f2 WHERE f2.visitor_id = u.id)
         WHERE $pipeline
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $open    = (int) ($row['open_count'] ?? 0);
    $pending = (int) ($row['pending'] ?? 0);
    $working = (int) ($row['in_progress'] ?? 0);
    return [
        'open'        => $open,
        'pending'     => $pending,
        'in_progress' => $working,
        'other_open'  => max(0, $open - $pending - $working),
        'unassigned'  => (int) ($row['unassigned'] ?? 0),
        'reported'    => (int) ($row['reported'] ?? 0),
        'oldest_open' => $row['oldest_open'] ?? null,
    ];
}

// --------------------------------------------------------------------------
// Pastoral Desk
// --------------------------------------------------------------------------

function dash_pending_qa(PDO $pdo, string $today): array
{
    $weekAgo  = dash_days_before($today, 7);
    $monthAgo = dash_days_before($today, 28);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) AS this_week,
               SUM(CASE WHEN submitted_at < ? AND submitted_at >= ? THEN 1 ELSE 0 END) AS weeks,
               SUM(CASE WHEN submitted_at < ? THEN 1 ELSE 0 END) AS older,
               MIN(submitted_at) AS oldest
          FROM pastor_qa
         WHERE status = 'Pending'
    ");
    $stmt->execute([$weekAgo, $weekAgo, $monthAgo, $monthAgo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total'     => (int) ($row['total'] ?? 0),
        'this_week' => (int) ($row['this_week'] ?? 0),
        'weeks'     => (int) ($row['weeks'] ?? 0),
        'older'     => (int) ($row['older'] ?? 0),
        'oldest'    => $row['oldest'] ?? null,
    ];
}

// --------------------------------------------------------------------------
// Sunday
// --------------------------------------------------------------------------

/** The latest days with a Sunday service on or before $today, newest first. */
function dash_sunday_dates(PDO $pdo, string $today, int $limit = DASH_SUNDAY_HISTORY): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT DATE(event_date) AS service_day
          FROM events
         WHERE REPLACE(event_category, ' ', '_') = ?
           AND DATE(event_date) <= ?
         ORDER BY service_day DESC
         LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute([DASH_SUNDAY_CATEGORY, $today]);
    return array_map(fn ($d) => substr((string) $d, 0, 10), $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Distinct people at Sunday services on each of $dates:
 *   members  everyone with a record, through assim_attendance_union_sql() —
 *            one per person per day however many services or tables saw them;
 *   guests   QR check-ins nobody could match to a record (most real first
 *            timers), one per phone number, and one per check-in with none.
 */
function dash_sunday_counts(PDO $pdo, array $dates): array
{
    $out = [];
    foreach ($dates as $date) {
        $out[$date] = ['members' => 0, 'guests' => 0, 'total' => 0];
    }
    if (!$out) {
        return [];
    }

    $union = assim_attendance_union_sql([DASH_SUNDAY_CATEGORY], [], array_keys($out));
    foreach ($pdo->query("SELECT x.attended_on AS day, COUNT(DISTINCT x.user_id) AS n
                            FROM $union x GROUP BY x.attended_on")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = substr((string) $row['day'], 0, 10);
        if (isset($out[$day])) {
            $out[$day]['members'] = (int) $row['n'];
        }
    }

    $marks = implode(', ', array_fill(0, count($out), '?'));
    $stmt = $pdo->prepare("
        SELECT c.checkin_date AS day,
               COUNT(DISTINCT NULLIF(TRIM(c.phone), '')) AS phones,
               SUM(CASE WHEN c.phone IS NULL OR TRIM(c.phone) = '' THEN 1 ELSE 0 END) AS no_phone
          FROM checkins c
          JOIN events e ON e.id = c.event_id
         WHERE c.user_id IS NULL
           AND REPLACE(e.event_category, ' ', '_') = ?
           AND c.checkin_date IN ($marks)
         GROUP BY c.checkin_date
    ");
    $stmt->execute(array_merge([DASH_SUNDAY_CATEGORY], array_keys($out)));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = substr((string) $row['day'], 0, 10);
        if (isset($out[$day])) {
            $out[$day]['guests'] = (int) $row['phones'] + (int) $row['no_phone'];
        }
    }

    foreach ($out as &$count) {
        $count['total'] = $count['members'] + $count['guests'];
    }
    unset($count);
    return $out;
}

/**
 * The Sunday card. On a Sunday it is today's service, counting up as people
 * arrive; any other day it is the latest Sunday. The change against the
 * Sunday before is only given once a service is over: half-way through the
 * morning, "-300 on last week" says nothing.
 */
function dash_sunday(PDO $pdo, string $today): array
{
    $dates  = dash_sunday_dates($pdo, $today);
    $counts = dash_sunday_counts($pdo, $dates);

    $history = [];
    foreach ($dates as $date) {
        $history[] = ['date' => $date] + $counts[$date];
    }

    $latest   = $history[0] ?? null;
    $previous = $history[1] ?? null;
    $isToday  = $latest !== null && $latest['date'] === $today;

    return [
        'date'     => $latest['date'] ?? null,
        'is_today' => $isToday,
        'total'    => $latest['total'] ?? 0,
        'members'  => $latest['members'] ?? 0,
        'guests'   => $latest['guests'] ?? 0,
        'previous' => $previous,
        'change'   => ($latest !== null && $previous !== null && !$isToday)
            ? $latest['total'] - $previous['total']
            : null,
        'history'  => $history,
    ];
}
