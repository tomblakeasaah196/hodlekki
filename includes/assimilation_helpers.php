<?php
// /includes/assimilation_helpers.php
// Shared by api/assimilation_api.php, api/assimilation_public_api.php,
// modules/assimilation/index.php, cron/assimilation_watchlists.php,
// includes/assimilation_report_pdf.php and the sidebar badge in
// includes/header.php.
//
// reach_helpers.php is required for the two genuinely module-agnostic
// helpers it already owns: reach_gemini() (the shared Gemini 2.5 Flash
// caller, which returns null when there is no key) and reach_markdown()
// (the safe Markdown renderer used for how_to_use.md and the guides).
require_once __DIR__ . '/reach_helpers.php';

// ==========================================================================
// VOCABULARY
// Human words live in the UI; these are the stored values.
// ==========================================================================

// To call -> Reached -> Promised to come -> Returned home, plus four
// closed-with-a-reason endings.
const ASSIM_OPEN_STATUSES   = ['To_Call', 'Reached', 'Promised'];
const ASSIM_CLOSED_STATUSES = ['Returned_Home', 'Unreachable', 'Not_Interested', 'Relocated', 'Attends_Elsewhere'];
const ASSIM_CHANNELS        = ['Call', 'WhatsApp', 'SMS', 'Visit', 'At_Church'];
const ASSIM_OUTCOMES        = ['Spoke_With_Them', 'Promised_To_Come', 'No_Answer', 'Wrong_Number', 'Not_Interested', 'Relocated', 'Attends_Elsewhere', 'Asked_For_No_Contact'];

// A follow-up with one of these outcomes ends the case, with that reason.
const ASSIM_CLOSING_OUTCOMES = [
    'Not_Interested'       => 'Not_Interested',
    'Asked_For_No_Contact' => 'Not_Interested',
    'Relocated'            => 'Relocated',
    'Attends_Elsewhere'    => 'Attends_Elsewhere',
];

const ASSIM_SPIRITUAL_STATUSES = ['1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor', 'Non_Member', 'Member', 'Worker', 'Pastor'];

// Age bands match the ones the Reach capture form offers.
const ASSIM_AGE_BANDS = [
    'Under 18' => [0, 17],
    '18-25'    => [18, 25],
    '26-35'    => [26, 35],
    '36-50'    => [36, 50],
    '51-65'    => [51, 65],
    '65+'      => [66, 130],
];

const ASSIM_WINDOW_UNITS     = ['days', 'weeks', 'months'];
const ASSIM_OVERDUE_DAYS     = 7;
const ASSIM_DEFAULT_RULE     = ['max_services' => 3, 'window_value' => 2, 'window_unit' => 'months', 'ever_attended' => 1];
const ASSIM_MODULE_LINK      = '/modules/assimilation/index.php';
const ASSIM_DEFAULT_GUIDE_FILE = __DIR__ . '/assimilation_volunteer_guide.md';

// ==========================================================================
// ATTENDANCE — one reusable definition of "was in the house that day"
// ==========================================================================

// The church records attendance in two places: `checkins` (QR / walk-in
// check-in, one row per check-in) and `attendance` (the events module's
// roster). A person who checks in twice on one Sunday, or who appears in
// both tables for the same service, must count once — hence UNION (not
// UNION ALL) over (user_id, attended_on), which de-duplicates per person
// per calendar day.
//
// Both tables carry the member's users.id (checkin_api.php resolves it from
// the phone before writing either row), so joining on user_id is what links
// attendance to a person. Rows with no user_id are guests, not members who
// have drifted, and are correctly ignored.
//
// Every caller — filters, analytics, returned-home detection, the drawer
// timeline and the cron — goes through this one function. Wrap it in
// parentheses and alias it: "FROM " . assim_attendance_union_sql() . " att".
function assim_attendance_union_sql(): string {
    return "(
        SELECT c.user_id AS user_id, c.checkin_date AS attended_on
          FROM checkins c
         WHERE c.user_id IS NOT NULL AND c.checkin_date IS NOT NULL
        UNION
        SELECT a.user_id AS user_id, COALESCE(a.attendance_date, DATE(a.check_in_time)) AS attended_on
          FROM attendance a
         WHERE a.user_id IS NOT NULL AND a.status = 'Present'
           AND COALESCE(a.attendance_date, a.check_in_time) IS NOT NULL
    )";
}

// Per-person totals used by the finder and the analytics tab. $lo/$hi bound
// the rule's window, $prev_lo the equally long window right before it, so a
// row can show "2 services, down from 9".
function assim_attendance_rollup_sql(): string {
    return "(
        SELECT x.user_id,
               COUNT(*) AS total_services,
               MAX(x.attended_on) AS last_attended,
               COALESCE(SUM(x.attended_on BETWEEN ? AND ?), 0) AS services_in_window,
               COALESCE(SUM(x.attended_on >= ? AND x.attended_on < ?), 0) AS services_previous
          FROM " . assim_attendance_union_sql() . " x
         GROUP BY x.user_id
    )";
}

// Monthly attendance counts for a handful of people — the 12-month
// sparkline on each result row. Returns [user_id => ['YYYY-MM' => count]].
function assim_monthly_attendance(PDO $pdo, array $user_ids, int $months = 12): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if (!$ids) {
        return [];
    }
    $from = (new DateTime('first day of this month'))->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT x.user_id, DATE_FORMAT(x.attended_on, '%Y-%m') AS ym, COUNT(*) AS n
          FROM " . assim_attendance_union_sql() . " x
         WHERE x.user_id IN ({$in}) AND x.attended_on >= ?
         GROUP BY x.user_id, ym
    ");
    $stmt->execute(array_merge($ids, [$from]));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['user_id']][$r['ym']] = (int) $r['n'];
    }
    return $out;
}

// The last $limit days this person was in the house, newest first.
function assim_attendance_days(PDO $pdo, int $user_id, int $limit = 10): array {
    $limit = max(1, min(60, $limit));
    $stmt  = $pdo->prepare("
        SELECT x.attended_on
          FROM " . assim_attendance_union_sql() . " x
         WHERE x.user_id = ?
         ORDER BY x.attended_on DESC
         LIMIT {$limit}
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Turns a ['YYYY-MM' => count] map into the last $months slots, oldest
// first, with empty months kept so the shape of the drift is visible.
function assim_months_frame(array $counts, int $months = 12): array {
    $cursor = (new DateTime('first day of this month'))->modify('-' . ($months - 1) . ' months');
    $out    = [];
    for ($i = 0; $i < $months; $i++) {
        $key   = $cursor->format('Y-m');
        $out[] = ['month' => $key, 'label' => $cursor->format('M'), 'count' => (int) ($counts[$key] ?? 0)];
        $cursor->modify('+1 month');
    }
    return $out;
}

function assim_attendance_months(PDO $pdo, int $user_id, int $months = 12): array {
    return assim_months_frame(assim_monthly_attendance($pdo, [$user_id], $months)[$user_id] ?? [], $months);
}

// ==========================================================================
// SETTINGS
// ==========================================================================

function assim_setting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare("SELECT setting_value FROM assimilation_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null || $v === '') ? null : (string) $v;
}

function assim_save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("
        INSERT INTO assimilation_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([$key, $value]);
}

function assim_overdue_days(PDO $pdo): int {
    static $days = null;
    if ($days === null) {
        try {
            $days = (int) (assim_setting($pdo, 'overdue_days') ?? ASSIM_OVERDUE_DAYS);
        } catch (PDOException $e) {
            $days = ASSIM_OVERDUE_DAYS;
        }
        $days = max(1, min(90, $days));
    }
    return $days;
}

function assim_allow_self_claim(PDO $pdo): bool {
    try {
        return assim_setting($pdo, 'allow_self_claim') !== '0';
    } catch (PDOException $e) {
        return true;
    }
}

function assim_default_rule(PDO $pdo): array {
    try {
        $raw = assim_setting($pdo, 'default_rule');
    } catch (PDOException $e) {
        $raw = null;
    }
    return assim_normalize_rule($raw ? (json_decode($raw, true) ?: []) : ASSIM_DEFAULT_RULE);
}

function assim_volunteer_guide(PDO $pdo): string {
    try {
        $custom = assim_setting($pdo, 'volunteer_guide');
    } catch (PDOException $e) {
        $custom = null;
    }
    return $custom ?? (string) @file_get_contents(ASSIM_DEFAULT_GUIDE_FILE);
}

// ==========================================================================
// WHO CAN SEE WHAT
// ==========================================================================

// Super Admin and the pastors, plus any active Director or HOD of any
// department — they carry the shepherding responsibility for their people.
function assim_is_manager(PDO $pdo, int $user_id, string $active_role): bool {
    if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
        return true;
    }
    if ($user_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_departments
         WHERE user_id = ? AND is_active = 1 AND role_in_dept IN ('HOD', 'Director')
         LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function assim_is_team_member(PDO $pdo, int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM assimilation_team WHERE user_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function assim_can_view(PDO $pdo, int $user_id, string $active_role): bool {
    return assim_is_manager($pdo, $user_id, $active_role) || assim_is_team_member($pdo, $user_id);
}

// Everyone who should hear about a new drift, a return home or an
// unclaimed case: Super Admins, pastors and every active Director/HOD.
function assim_manager_ids(PDO $pdo): array {
    $ids = $pdo->query("
        SELECT DISTINCT ud.user_id FROM user_departments ud
         WHERE ud.is_active = 1 AND ud.role_in_dept IN ('HOD', 'Director')
    ")->fetchAll(PDO::FETCH_COLUMN);
    $roles = $pdo->query("
        SELECT DISTINCT ur.user_id FROM user_roles ur
          JOIN roles r ON r.id = ur.role_id
         WHERE r.role_name IN ('Super_Admin', 'Resident_Pastor', 'Assoc_Pastor')
    ")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_map('intval', array_merge($ids, $roles))));
}

// The volunteer roster, with the workload numbers the Team tab shows.
function assim_team(PDO $pdo, bool $active_only = true): array {
    $sql = "
        SELECT t.user_id, t.is_active, t.added_at, t.notes,
               TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name,
               u.phone, u.picture_path, u.spiritual_status,
               TRIM(CONCAT_WS(' ', ab.first_name, ab.last_name)) AS added_by_name,
               (SELECT COUNT(*) FROM assimilation_cases c
                 WHERE c.assigned_to = t.user_id AND c.closed_at IS NULL) AS open_cases,
               (SELECT COUNT(*) FROM assimilation_follow_ups f
                 WHERE f.logged_by = t.user_id AND f.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS contacts_this_month,
               (SELECT COUNT(*) FROM assimilation_cases c
                 WHERE c.assigned_to = t.user_id AND c.returned_home_at IS NOT NULL) AS returned_home,
               (SELECT MAX(f.created_at) FROM assimilation_follow_ups f WHERE f.logged_by = t.user_id) AS last_active
          FROM assimilation_team t
          JOIN users u ON u.id = t.user_id
          LEFT JOIN users ab ON ab.id = t.added_by
         " . ($active_only ? 'WHERE t.is_active = 1' : '') . "
         ORDER BY t.is_active DESC, name
    ";
    return array_map(function (array $r): array {
        $r['user_id']             = (int) $r['user_id'];
        $r['is_active']           = (int) $r['is_active'];
        $r['open_cases']          = (int) $r['open_cases'];
        $r['contacts_this_month'] = (int) $r['contacts_this_month'];
        $r['returned_home']       = (int) $r['returned_home'];
        return $r;
    }, $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
}

function assim_notify(PDO $pdo, array $user_ids, string $title, string $message, string $link = ASSIM_MODULE_LINK): void {
    $user_ids = array_unique(array_filter(array_map('intval', $user_ids)));
    if (!$user_ids) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)");
    foreach ($user_ids as $uid) {
        $stmt->execute([$uid, $title, $message, $link]);
    }
}

// Assigned more than $days ago and nobody has logged anything since.
function assim_overdue_sql(int $days, string $alias = 'c.'): string {
    return "({$alias}closed_at IS NULL AND {$alias}assigned_to IS NOT NULL"
        . " AND {$alias}assigned_at < NOW() - INTERVAL " . $days . " DAY"
        . " AND ({$alias}last_contact_at IS NULL OR {$alias}last_contact_at < {$alias}assigned_at))";
}

// The SSR sidebar badge: my overdue cases, the unclaimed pool, and people
// an active watchlist has flagged whom nobody has picked up yet.
function assim_sidebar_counts(PDO $pdo, int $user_id): array {
    $days = assim_overdue_days($pdo);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.assigned_to = ? AND " . assim_overdue_sql($days) . "), 0) AS my_overdue,
               COALESCE(SUM(c.assigned_to = ? AND c.closed_at IS NULL), 0) AS my_open,
               COALESCE(SUM(c.assigned_to IS NULL AND c.closed_at IS NULL), 0) AS pool
          FROM assimilation_cases c
    ");
    $stmt->execute([$user_id, $user_id]);
    $counts = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $counts['watchlist_untouched'] = (int) $pdo->query("
        SELECT COUNT(DISTINCT h.user_id)
          FROM assimilation_watchlist_hits h
          JOIN assimilation_watchlists w ON w.id = h.watchlist_id AND w.is_active = 1
         WHERE NOT EXISTS (SELECT 1 FROM assimilation_cases c WHERE c.user_id = h.user_id AND c.closed_at IS NULL)
    ")->fetchColumn();
    return $counts;
}

// ==========================================================================
// THE RULE — "fewer than N services in the past M days/weeks/months"
// ==========================================================================

// Everything that arrives from a form or a saved watchlist goes through
// here, so the finder only ever sees values it can trust.
function assim_normalize_rule($raw): array {
    $r = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

    $unit = in_array($r['window_unit'] ?? '', ASSIM_WINDOW_UNITS, true) ? $r['window_unit'] : 'months';
    $max  = (int) ($r['max_services'] ?? ASSIM_DEFAULT_RULE['max_services']);
    $win  = (int) ($r['window_value'] ?? ASSIM_DEFAULT_RULE['window_value']);

    $statuses = $r['spiritual_status'] ?? [];
    if (is_string($statuses)) {
        $statuses = $statuses === '' ? [] : explode(',', $statuses);
    }
    $statuses = array_values(array_intersect(ASSIM_SPIRITUAL_STATUSES, array_map('trim', (array) $statuses)));

    $date = function ($v): ?string {
        $v = trim((string) $v);
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    };

    return [
        'max_services'         => max(1, min(200, $max)),
        'window_value'         => max(1, min(104, $win)),
        'window_unit'          => $unit,
        'spiritual_status'     => $statuses,
        'department_id'        => max(0, (int) ($r['department_id'] ?? 0)),
        'region_id'            => max(0, (int) ($r['region_id'] ?? 0)),
        'gender'               => in_array($r['gender'] ?? '', ['Male', 'Female'], true) ? $r['gender'] : '',
        'age_band'             => isset(ASSIM_AGE_BANDS[$r['age_band'] ?? '']) ? $r['age_band'] : '',
        'ever_attended'        => !empty($r['ever_attended']) ? 1 : 0,
        'last_attended_before' => $date($r['last_attended_before'] ?? ''),
        'last_attended_after'  => $date($r['last_attended_after'] ?? ''),
        'case_state'           => in_array($r['case_state'] ?? '', ['open_case', 'no_case'], true) ? $r['case_state'] : '',
        'search'               => mb_substr(trim((string) ($r['search'] ?? '')), 0, 80),
    ];
}

// [window start, today, previous window start] as Y-m-d.
function assim_rule_window(array $rule): array {
    $spec = ['days' => 'day', 'weeks' => 'week', 'months' => 'month'][$rule['window_unit']];
    $hi   = new DateTime('today');
    $lo   = (new DateTime('today'))->modify('-' . $rule['window_value'] . ' ' . $spec);
    $prev = (clone $lo)->modify('-' . $rule['window_value'] . ' ' . $spec);
    return [$lo->format('Y-m-d'), $hi->format('Y-m-d'), $prev->format('Y-m-d')];
}

// A one-line English rendering of a rule, for watchlist cards and the PDF.
function assim_rule_summary(array $rule): string {
    $unit  = rtrim($rule['window_unit'], 's') . ($rule['window_value'] === 1 ? '' : 's');
    $parts = ["fewer than {$rule['max_services']} service" . ($rule['max_services'] === 1 ? '' : 's')
        . " in the past {$rule['window_value']} {$unit}"];
    if ($rule['spiritual_status']) {
        $parts[] = implode(' / ', array_map(fn($s) => str_replace('_', ' ', $s), $rule['spiritual_status']));
    }
    if ($rule['gender']) {
        $parts[] = $rule['gender'];
    }
    if ($rule['age_band']) {
        $parts[] = 'aged ' . $rule['age_band'];
    }
    if ($rule['ever_attended']) {
        $parts[] = 'has attended before';
    }
    if ($rule['last_attended_before']) {
        $parts[] = 'last seen before ' . $rule['last_attended_before'];
    }
    if ($rule['last_attended_after']) {
        $parts[] = 'last seen after ' . $rule['last_attended_after'];
    }
    return implode(' · ', $parts);
}

// The finder. Returns ['sql' => ..., 'params' => ...] selecting one row per
// person who matches $rule, with their attendance rollup and open case.
// $opt: select (list|ids|count), order, limit, offset.
function assim_find_query(array $rule, array $opt = []): array {
    [$lo, $hi, $prev_lo] = assim_rule_window($rule);

    // The rollup's four placeholders come first, in the order the derived
    // table spells them.
    $params = [$lo, $hi, $prev_lo, $lo];
    $where  = ['COALESCE(att.services_in_window, 0) < ?'];
    $params[] = $rule['max_services'];

    if ($rule['ever_attended']) {
        $where[] = 'att.total_services > 0';
    }
    if ($rule['spiritual_status']) {
        $where[] = 'u.spiritual_status IN (' . implode(',', array_fill(0, count($rule['spiritual_status']), '?')) . ')';
        $params  = array_merge($params, $rule['spiritual_status']);
    }
    if ($rule['department_id']) {
        $where[]  = 'EXISTS (SELECT 1 FROM user_departments ud WHERE ud.user_id = u.id AND ud.department_id = ? AND ud.is_active = 1)';
        $params[] = $rule['department_id'];
    }
    if ($rule['region_id']) {
        $where[]  = 'u.region_id = ?';
        $params[] = $rule['region_id'];
    }
    if ($rule['gender']) {
        $where[]  = 'u.gender = ?';
        $params[] = $rule['gender'];
    }
    if ($rule['age_band']) {
        [$min, $max] = ASSIM_AGE_BANDS[$rule['age_band']];
        $where[] = 'u.dob IS NOT NULL AND TIMESTAMPDIFF(YEAR, u.dob, CURDATE()) BETWEEN ? AND ?';
        $params[] = $min;
        $params[] = $max;
    }
    if ($rule['last_attended_before']) {
        $where[]  = 'att.last_attended < ?';
        $params[] = $rule['last_attended_before'];
    }
    if ($rule['last_attended_after']) {
        $where[]  = 'att.last_attended >= ?';
        $params[] = $rule['last_attended_after'];
    }
    if ($rule['case_state'] === 'open_case') {
        $where[] = 'oc.id IS NOT NULL';
    } elseif ($rule['case_state'] === 'no_case') {
        $where[] = 'oc.id IS NULL';
    }
    if ($rule['search'] !== '') {
        $where[]  = "(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) LIKE ? OR u.phone LIKE ?)";
        $params[] = '%' . $rule['search'] . '%';
        $params[] = '%' . $rule['search'] . '%';
    }
    // People who have left are not drifting — they told us they were going.
    $where[] = "COALESCE(u.attendance_status, '') <> 'Relocated'";

    $select = match ($opt['select'] ?? 'list') {
        'count' => 'COUNT(*)',
        'ids'   => 'u.id',
        default => "u.id, u.first_name, u.last_name, u.phone, u.gender, u.dob,
               u.spiritual_status, u.attendance_status, u.picture_path, u.region_id,
               rg.name AS region_name,
               (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                  FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                 WHERE ud.user_id = u.id AND ud.is_active = 1) AS departments,
               COALESCE(att.total_services, 0)     AS total_services,
               att.last_attended,
               COALESCE(att.services_in_window, 0) AS services_in_window,
               COALESCE(att.services_previous, 0)  AS services_previous,
               DATEDIFF(CURDATE(), att.last_attended) AS days_since,
               oc.id AS case_id, oc.status AS case_status, oc.assigned_to,
               oc.next_touch_date, oc.last_contact_at,
               TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS assignee_name",
    };

    $sql = "SELECT {$select}
              FROM users u
              LEFT JOIN regions rg ON rg.id = u.region_id
              LEFT JOIN " . assim_attendance_rollup_sql() . " att ON att.user_id = u.id
              LEFT JOIN assimilation_cases oc ON oc.user_id = u.id AND oc.closed_at IS NULL
              LEFT JOIN users au ON au.id = oc.assigned_to
             WHERE " . implode(' AND ', $where);

    if (($opt['select'] ?? 'list') !== 'count') {
        $sql .= ' ORDER BY ' . ($opt['order'] ?? 'att.last_attended IS NULL, att.last_attended ASC, u.first_name');
        if (!empty($opt['limit'])) {
            $sql .= ' LIMIT ' . (int) $opt['limit'] . ' OFFSET ' . (int) ($opt['offset'] ?? 0);
        }
    }
    return ['sql' => $sql, 'params' => $params];
}

function assim_count_rule(PDO $pdo, array $rule): int {
    $q    = assim_find_query($rule, ['select' => 'count']);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    return (int) $stmt->fetchColumn();
}

function assim_rule_user_ids(PDO $pdo, array $rule): array {
    $q    = assim_find_query($rule, ['select' => 'ids', 'order' => 'u.id']);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ==========================================================================
// WATCHLIST CASES — opening them, keeping hits in step, and pushing open
// lists to the volunteer pool. Shared by the module API, the public API and
// the nightly cron, so there is exactly one definition of each step.
// ==========================================================================

// Opens a case for one person, or explains why it could not. The unique key
// on assimilation_cases.open_user_id is the real guard against two open
// cases for the same person, so a race loses here rather than in the data.
// $to_user_id NULL means the unclaimed pool.
function assim_open_case(PDO $pdo, int $person_id, ?int $to_user_id, int $by_user_id, ?int $watchlist_id): array {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$person_id]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $last = $pdo->prepare("SELECT MAX(x.attended_on) FROM " . assim_attendance_union_sql() . " x WHERE x.user_id = ?");
    $last->execute([$person_id]);
    $last_attended = $last->fetchColumn() ?: null;

    try {
        $ins = $pdo->prepare("
            INSERT INTO assimilation_cases
                (user_id, watchlist_id, assigned_to, assigned_by, assigned_at, status, last_attended_on)
            VALUES (?, ?, ?, ?, ?, 'To_Call', ?)
        ");
        $ins->execute([
            $person_id, $watchlist_id ?: null, $to_user_id ?: null,
            $to_user_id ? $by_user_id : null, $to_user_id ? date('Y-m-d H:i:s') : null,
            $last_attended,
        ]);
    } catch (PDOException $e) {
        // 23000 = the one-open-case-per-person unique key.
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'reason' => 'already_open'];
        }
        throw $e;
    }
    $case_id = (int) $pdo->lastInsertId();
    if ($to_user_id) {
        $pdo->prepare("
            INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action)
            VALUES (?, NULL, ?, ?, 'assign')
        ")->execute([$case_id, $to_user_id, $by_user_id]);
    }
    return ['ok' => true, 'case_id' => $case_id];
}

// Keeps assimilation_watchlist_hits in step with a rule, so the cron and
// the sidebar badge both read from one place. announced_at stays untouched,
// which is what stops a person being announced twice.
function assim_sync_watchlist_hits(PDO $pdo, int $watchlist_id, array $user_ids): array {
    $existing = $pdo->prepare("SELECT user_id FROM assimilation_watchlist_hits WHERE watchlist_id = ?");
    $existing->execute([$watchlist_id]);
    $before = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

    $fresh = array_values(array_diff($user_ids, $before));
    $gone  = array_values(array_diff($before, $user_ids));

    if ($fresh) {
        $ins = $pdo->prepare("
            INSERT INTO assimilation_watchlist_hits (watchlist_id, user_id) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
        ");
        foreach ($fresh as $uid) {
            $ins->execute([$watchlist_id, $uid]);
        }
    }
    if ($gone) {
        // They are back in the house often enough — stop watching them, and
        // let them be announced again if they drift a second time.
        $del = $pdo->prepare("DELETE FROM assimilation_watchlist_hits WHERE watchlist_id = ? AND user_id = ?");
        foreach ($gone as $uid) {
            $del->execute([$watchlist_id, $uid]);
        }
    }
    return ['new' => $fresh, 'left' => $gone];
}

// --------------------------------------------------------------------------
// Open watchlists: push people straight into the unclaimed pool so every
// volunteer sees them on the public page and can claim them. Two guards keep
// this gentle:
//   1. assim_open_case's unique key skips anyone who is already being
//      followed up (claimed or pooled elsewhere).
//   2. Anyone a previous follow-up closed for a reason OTHER than returning
//      home (not interested, relocated, attends elsewhere, unreachable) is
//      never pushed automatically again — a human can still assign them.
//      Someone whose earlier case closed as Returned_Home CAN be pushed a
//      second time: drifting again after coming home is exactly what these
//      lists exist to catch.
// Returns the number of people actually pushed.
// --------------------------------------------------------------------------
function assim_auto_pool_open(PDO $pdo, int $watchlist_id, array $user_ids, int $by_user_id): int {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if (!$user_ids) {
        return 0;
    }
    $blocked = [];
    $in     = implode(',', array_fill(0, count($user_ids), '?'));
    $stmt   = $pdo->prepare("
        SELECT DISTINCT user_id FROM assimilation_cases
         WHERE user_id IN ({$in})
           AND closed_at IS NOT NULL AND status <> 'Returned_Home'
    ");
    $stmt->execute($user_ids);
    $blocked = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $pushed = 0;
    foreach (array_diff($user_ids, $blocked) as $uid) {
        $res = assim_open_case($pdo, $uid, null, $by_user_id, $watchlist_id);
        if (!empty($res['ok'])) {
            $pushed++;
        }
    }
    return $pushed;
}

// True once 20261005120000_assimilation_open_watchlists.sql has run. The
// tree ships to production BEFORE migrations do (see AGENTS.md), so every
// is_open read or write asks this first and quietly behaves like the feature
// does not exist yet, instead of dying on an unknown column.
function assim_has_open_watchlists(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = (int) $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'assimilation_watchlists' AND column_name = 'is_open'
        ")->fetchColumn() === 1;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

// Who should hear that an OPEN list pushed new people: the managers, told in
// the module; plus every active team volunteer, told on the public volunteer
// page — because they are the ones who can pick people up. Managed lists
// keep the classic audience: managers alone. A manager who also volunteers
// is told once, as a manager.
function assim_watchlist_audience(PDO $pdo, bool $is_open): array {
    $managers = array_values(array_unique(array_map('intval', assim_manager_ids($pdo))));
    if (!$is_open) {
        return ['managers' => $managers, 'volunteers' => []];
    }
    $volunteers = array_map(fn($t) => (int) $t['user_id'], assim_team($pdo));
    return ['managers' => $managers, 'volunteers' => array_values(array_diff($volunteers, $managers))];
}

// ==========================================================================
// CASES
// ==========================================================================

// Status is derived from the follow-up log wherever it can be, the same way
// reach_recompute_status() works. Returning home always wins; a follow-up
// with a closing outcome ends the case with that reason.
function assim_recompute_case_status(PDO $pdo, int $case_id): string {
    $stmt = $pdo->prepare("SELECT id, status, closed_at, returned_home_at FROM assimilation_cases WHERE id = ?");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        return '';
    }
    // A case a manager closed by hand keeps that ending unless the person
    // has since come home.
    if ($case['closed_at'] !== null && $case['returned_home_at'] === null) {
        return (string) $case['status'];
    }

    $fu = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(outcome = 'Spoke_With_Them'), 0)  AS spoke,
               COALESCE(SUM(outcome = 'Promised_To_Come'), 0) AS promised,
               MIN(created_at) AS first_contact,
               MAX(created_at) AS last_contact,
               (SELECT outcome FROM assimilation_follow_ups f2
                 WHERE f2.case_id = ? AND f2.outcome IN ('" . implode("','", array_keys(ASSIM_CLOSING_OUTCOMES)) . "')
                 ORDER BY f2.id DESC LIMIT 1) AS closing_outcome
          FROM assimilation_follow_ups f WHERE f.case_id = ?
    ");
    $fu->execute([$case_id, $case_id]);
    $s = $fu->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($case['returned_home_at'] !== null) {
        $status = 'Returned_Home';
    } elseif (!empty($s['closing_outcome'])) {
        $status = ASSIM_CLOSING_OUTCOMES[$s['closing_outcome']];
    } elseif ((int) ($s['promised'] ?? 0) > 0) {
        $status = 'Promised';
    } elseif ((int) ($s['spoke'] ?? 0) > 0) {
        $status = 'Reached';
    } else {
        $status = 'To_Call';
    }

    $closing = in_array($status, ASSIM_CLOSED_STATUSES, true);
    $pdo->prepare("
        UPDATE assimilation_cases
           SET status = ?,
               first_contact_at = ?,
               last_contact_at  = ?,
               closed_at = IF(? = 1, COALESCE(closed_at, NOW()), NULL),
               outcome   = IF(? = 1, COALESCE(outcome, ?), NULL)
         WHERE id = ?
    ")->execute([
        $status, $s['first_contact'] ?? null, $s['last_contact'] ?? null,
        $closing ? 1 : 0, $closing ? 1 : 0, assim_status_words($status), $case_id,
    ]);
    return $status;
}

function assim_status_words(string $status): string {
    return [
        'To_Call'           => 'To call',
        'Reached'           => 'Reached',
        'Promised'          => 'Promised to come',
        'Returned_Home'     => 'Returned home',
        'Unreachable'       => 'Could not reach them',
        'Not_Interested'    => 'Not interested for now',
        'Relocated'         => 'Has relocated',
        'Attends_Elsewhere' => 'Attends another church',
    ][$status] ?? str_replace('_', ' ', $status);
}

// "Returned home" = the person was in the house on a day AFTER we first
// reached out on the case. Detected here (cron + every admin page load) so
// a check-in from the QR or events modules counts on its own, with no hook
// into those modules. Cases closed within the last 90 days are included:
// someone we gave up on walking back in is exactly what we want to catch.
// Returns the rows it marked, so callers can celebrate them.
function assim_detect_returned_home(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT c.id, c.user_id, c.assigned_to, c.status,
               TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name,
               MIN(att.attended_on) AS came_on
          FROM assimilation_cases c
          JOIN users u ON u.id = c.user_id
          JOIN " . assim_attendance_union_sql() . " att
            ON att.user_id = c.user_id AND att.attended_on > DATE(c.first_contact_at)
         WHERE c.returned_home_at IS NULL
           AND c.first_contact_at IS NOT NULL
           AND (c.closed_at IS NULL OR c.closed_at > NOW() - INTERVAL 90 DAY)
         GROUP BY c.id, c.user_id, c.assigned_to, c.status, name
    ");
    $stmt->execute();
    $back = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$back) {
        return [];
    }
    $upd = $pdo->prepare("
        UPDATE assimilation_cases
           SET returned_home_at = ?, status = 'Returned_Home', closed_at = COALESCE(closed_at, NOW()),
               outcome = 'Returned home'
         WHERE id = ? AND returned_home_at IS NULL
    ");
    $marked = [];
    foreach ($back as $row) {
        $upd->execute([$row['came_on'] . ' 12:00:00', $row['id']]);
        if ($upd->rowCount() > 0) {
            $marked[] = $row;
        }
    }
    return $marked;
}

// One notification per person who came home, to the volunteer who called
// and to the managers. No emoji in code — the UI draws an icon.
function assim_announce_returned_home(PDO $pdo, array $marked): void {
    if (!$marked) {
        return;
    }
    $managers = assim_manager_ids($pdo);
    foreach ($marked as $row) {
        $when = date('D j M', strtotime((string) $row['came_on']));
        assim_notify($pdo, array_unique(array_merge([(int) $row['assigned_to']], $managers)),
            'They came back home!',
            trim((string) $row['name']) . " was in the house on {$when} — the Assimilation case is now Returned home.");
    }
}

// ==========================================================================
// ANALYTICS — shared by the Analytics tab and the PDF report
// ==========================================================================

function assim_pct(int $part, int $whole): float {
    return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
}

function assim_median(array $values): ?float {
    $values = array_values(array_filter($values, fn($v) => $v !== null));
    if (!$values) {
        return null;
    }
    sort($values);
    $n = count($values);
    $m = intdiv($n, 2);
    return $n % 2 ? (float) $values[$m] : round(($values[$m - 1] + $values[$m]) / 2, 1);
}

function assim_analytics(PDO $pdo, string $from, string $to): array {
    $lo = $from . ' 00:00:00';
    $hi = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS opened,
               COALESCE(SUM(c.first_contact_at IS NOT NULL), 0) AS contacted_ever,
               COALESCE(SUM(EXISTS(SELECT 1 FROM assimilation_follow_ups f WHERE f.case_id = c.id AND f.outcome = 'Spoke_With_Them')), 0) AS reached,
               COALESCE(SUM(EXISTS(SELECT 1 FROM assimilation_follow_ups f WHERE f.case_id = c.id AND f.outcome = 'Promised_To_Come')), 0) AS promised,
               COALESCE(SUM(c.returned_home_at IS NOT NULL), 0) AS returned_from_cohort
          FROM assimilation_cases c
         WHERE c.opened_at >= ? AND c.opened_at < ?
    ");
    $stmt->execute([$lo, $hi]);
    $cohort = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    // Activity inside the range, whenever the case was opened.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assimilation_cases WHERE first_contact_at >= ? AND first_contact_at < ?");
    $stmt->execute([$lo, $hi]);
    $contacted = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assimilation_cases WHERE returned_home_at >= ? AND returned_home_at < ?");
    $stmt->execute([$lo, $hi]);
    $returned = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT TIMESTAMPDIFF(HOUR, opened_at, first_contact_at) / 24 AS days
          FROM assimilation_cases
         WHERE first_contact_at >= ? AND first_contact_at < ? AND first_contact_at >= opened_at
    ");
    $stmt->execute([$lo, $hi]);
    $median_days = assim_median(array_map('floatval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $drifted = (int) $pdo->query("
        SELECT COUNT(DISTINCT h.user_id) FROM assimilation_watchlist_hits h
          JOIN assimilation_watchlists w ON w.id = h.watchlist_id AND w.is_active = 1
    ")->fetchColumn();

    $overdue = (int) $pdo->query("
        SELECT COUNT(*) FROM assimilation_cases c WHERE " . assim_overdue_sql(assim_overdue_days($pdo))
    )->fetchColumn();

    // Returned home by month, 12 months ending in the range's last month.
    $trend_from = (new DateTime($to))->modify('first day of this month')->modify('-11 months');
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(returned_home_at, '%Y-%m') AS ym, COUNT(*) AS n
          FROM assimilation_cases
         WHERE returned_home_at >= ?
         GROUP BY ym
    ");
    $stmt->execute([$trend_from->format('Y-m-d') . ' 00:00:00']);
    $by_month = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by_month[$r['ym']] = (int) $r['n'];
    }
    $trend  = [];
    $cursor = clone $trend_from;
    for ($i = 0; $i < 12; $i++) {
        $trend[] = ['month' => $cursor->format('Y-m'), 'label' => $cursor->format('M y'), 'count' => (int) ($by_month[$cursor->format('Y-m')] ?? 0)];
        $cursor->modify('+1 month');
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(u.spiritual_status, 'Unknown') AS bucket, COUNT(*) AS total,
               COALESCE(SUM(c.returned_home_at IS NOT NULL), 0) AS returned
          FROM assimilation_cases c JOIN users u ON u.id = c.user_id
         WHERE c.opened_at >= ? AND c.opened_at < ?
         GROUP BY bucket ORDER BY total DESC
    ");
    $stmt->execute([$lo, $hi]);
    $by_status = array_map(fn($r) => [
        'label' => str_replace('_', ' ', $r['bucket']), 'count' => (int) $r['total'], 'returned' => (int) $r['returned'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT d.name AS label, COUNT(DISTINCT c.id) AS total,
               COUNT(DISTINCT IF(c.returned_home_at IS NOT NULL, c.id, NULL)) AS returned
          FROM assimilation_cases c
          JOIN user_departments ud ON ud.user_id = c.user_id AND ud.is_active = 1
          JOIN departments d ON d.id = ud.department_id
         WHERE c.opened_at >= ? AND c.opened_at < ?
         GROUP BY d.id, d.name ORDER BY total DESC, label LIMIT 12
    ");
    $stmt->execute([$lo, $hi]);
    $by_dept = array_map(fn($r) => [
        'label' => $r['label'], 'count' => (int) $r['total'], 'returned' => (int) $r['returned'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Everyone who actually did the work in this window — team volunteers and
    // any manager who picked up the phone themselves.
    $stmt = $pdo->prepare("
        SELECT a.user_id,
               TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name,
               (SELECT COUNT(*) FROM assimilation_follow_ups f
                 WHERE f.logged_by = a.user_id AND f.created_at >= ? AND f.created_at < ?) AS contacts,
               (SELECT COUNT(*) FROM assimilation_cases c
                 WHERE c.assigned_to = a.user_id AND c.returned_home_at >= ? AND c.returned_home_at < ?) AS returned
          FROM (
              SELECT DISTINCT logged_by AS user_id FROM assimilation_follow_ups
               WHERE logged_by IS NOT NULL AND created_at >= ? AND created_at < ?
              UNION
              SELECT DISTINCT assigned_to AS user_id FROM assimilation_cases
               WHERE assigned_to IS NOT NULL AND returned_home_at >= ? AND returned_home_at < ?
          ) a
          JOIN users u ON u.id = a.user_id
         ORDER BY returned DESC, contacts DESC, name
         LIMIT 15
    ");
    $stmt->execute([$lo, $hi, $lo, $hi, $lo, $hi, $lo, $hi]);
    $volunteers = array_map(fn($v) => [
        'name' => $v['name'], 'contacts' => (int) $v['contacts'], 'returned' => (int) $v['returned'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT w.id, w.name, w.rule_json, w.is_active,
               (SELECT COUNT(*) FROM assimilation_watchlist_hits h WHERE h.watchlist_id = w.id) AS people,
               (SELECT COUNT(*) FROM assimilation_watchlist_hits h WHERE h.watchlist_id = w.id AND h.first_seen_at >= ? AND h.first_seen_at < ?) AS new_in_range
          FROM assimilation_watchlists w
         ORDER BY w.is_active DESC, w.name
    ");
    $stmt->execute([$lo, $hi]);
    $watchlists = array_map(fn($w) => [
        'name'         => $w['name'],
        'rule'         => assim_rule_summary(assim_normalize_rule($w['rule_json'])),
        'is_active'    => (int) $w['is_active'],
        'people'       => (int) $w['people'],
        'new_in_range' => (int) $w['new_in_range'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'range' => ['from' => $from, 'to' => $to],
        'kpis'  => [
            'drifted'         => $drifted,
            'cases_opened'    => $cohort['opened'] ?? 0,
            'contacted'       => $contacted,
            'returned_home'   => $returned,
            'return_rate'     => assim_pct($returned, $contacted),
            'median_days'     => $median_days,
            'overdue'         => $overdue,
        ],
        'funnel' => [
            ['stage' => 'To call', 'count' => $cohort['opened'] ?? 0],
            ['stage' => 'Reached', 'count' => $cohort['reached'] ?? 0],
            ['stage' => 'Promised to come', 'count' => $cohort['promised'] ?? 0],
            ['stage' => 'Returned home', 'count' => $cohort['returned_from_cohort'] ?? 0],
        ],
        'returned_trend'        => $trend,
        'status_breakdown'      => $by_status,
        'department_breakdown'  => $by_dept,
        'volunteer_leaderboard' => $volunteers,
        'watchlists'            => $watchlists,
    ];
}

// ==========================================================================
// SMALL SHARED PIECES
// ==========================================================================

// Last 9 digits, so +234 / 0-prefixed spellings of one number match. A
// shorter fragment must never match, or a stray "1" would find a member.
function assim_phone_key(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    return strlen($digits) >= 9 ? substr($digits, -9) : '';
}

// The public page never shows a full surname for a phone number.
function assim_short_name(?string $first, ?string $last): string {
    $first = trim((string) $first);
    $last  = trim((string) $last);
    return trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
}

// "6 weeks ago" — how long since they were last in the house.
function assim_since_words(?string $date): string {
    if (!$date) {
        return 'never recorded';
    }
    $days = (int) (new DateTime($date))->diff(new DateTime('today'))->format('%r%a');
    if ($days <= 0)  return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 14)  return $days . ' days ago';
    if ($days < 60)  return intdiv($days, 7) . ' weeks ago';
    if ($days < 730) return intdiv($days, 30) . ' months ago';
    return intdiv($days, 365) . ' years ago';
}

// ==========================================================================
// AI HELP — reach_gemini() is the shared Gemini 2.5 Flash caller and returns
// null when there is no key, so both of these degrade to "not available"
// rather than breaking the follow-up.
// ==========================================================================

// Tidies what the volunteer typed or dictated. The prompt forbids judgement
// and invention: this is a pastoral record about a person, not an appraisal
// of them. Returns null if the model is unavailable or says nothing useful.
function assim_clean_notes(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $prompt = "You tidy up follow-up notes written by a church volunteer who has just called someone who stopped coming to Household of David Lekki Centre.\n"
        . "Rewrite the notes as at most 5 short bullet points, each starting with \"- \", in this order where the notes mention them: what was discussed; the reason for their absence; prayer points; what they committed to; the next step.\n"
        . "Rules you must follow:\n"
        . "- Keep a warm, respectful, pastoral tone. This is a record about a person the church loves.\n"
        . "- Never add judgement, blame, diagnosis or opinion about the person, their faith or their choices. Record only what was said.\n"
        . "- Keep every name, place, date, amount and phone number exactly as written.\n"
        . "- Invent nothing. If something is not in the notes, leave it out — do not guess a reason for their absence.\n"
        . "- Plain text only: no headings, no markdown beyond the \"- \" bullets, no preamble.\n\n"
        . "Notes:\n" . $raw;
    $out = reach_gemini($prompt, 0.2);
    return $out !== null && trim($out) !== '' ? trim($out) : null;
}

// One gentle opening line for the call. Only the essentials of the call go
// to the model: a first name, how long since they were last in the house,
// where the case stands, and the last note. Nothing else about the person.
function assim_opening_line(PDO $pdo, int $case_id, string $person_name, ?string $last_attended, string $status): ?string {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(notes_clean, ''), notes) AS note
          FROM assimilation_follow_ups
         WHERE case_id = ? AND TRIM(COALESCE(COALESCE(NULLIF(notes_clean, ''), notes), '')) <> ''
         ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$case_id]);
    $last_note = (string) ($stmt->fetchColumn() ?: '');

    $first  = explode(' ', trim($person_name))[0] ?: 'them';
    $facts  = "- First name: {$first}\n"
        . '- Last in church: ' . assim_since_words($last_attended) . "\n"
        . '- Where the follow-up stands: ' . assim_status_words($status) . "\n";
    if ($last_note !== '') {
        $facts .= '- What we know from the last call: ' . mb_substr($last_note, 0, 600) . "\n";
    }
    $prompt = "A volunteer from Household of David Lekki Centre is about to call a church member who stopped coming, to check on them with love.\n"
        . "Write ONE warm opening sentence the volunteer can say, at most 30 words, in plain Nigerian English.\n"
        . "It must lead with genuine care for the person, never with their attendance, and must not ask why they stopped coming, guilt them or mention any record.\n"
        . "Return only the sentence, no quotes and no explanation.\n\n" . $facts;
    $out = reach_gemini($prompt, 0.7);
    return $out !== null && trim($out) !== '' ? trim(strip_tags($out)) : null;
}
