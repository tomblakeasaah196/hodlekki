<?php
// /includes/department_helpers.php
//
// Shared data layer for the Departments module.
//
// Everything the module needs to answer "who is serving in what, this ministry
// year" lives here, so that the JSON endpoint (/api/department_api.php) and the
// Excel export (/api/department_export_excel.php) can never drift apart.
//
// Vocabulary
// ----------
//   ministry year   a row in `ministry_years` (label + start/end date, exactly
//                   one of them is_active). Rosters belong to a year.
//   roster row      a row in `user_departments` for one person in one
//                   department in one ministry year.
//   membershiptype  Primary | Secondary — the two lists the UI shows.
//   roster_status   Active | Removed *inside that year*. Removed rows are
//                   never rendered anywhere in the module.
//   is_active       "serving right now, in the live ministry year". Kept in
//                   sync for the benefit of every other module that reads
//                   user_departments (header.php nav clearance, special_events,
//                   reach, charis …).

const DEPT_LEADER_ROLES = ['Assoc_Pastor', 'Director', 'HOD'];

const DEPT_ROLE_LABELS = [
    'Assoc_Pastor'  => 'Pastor in Charge',
    'Director'      => 'Director in Charge',
    'HOD'           => 'Head of Department',
    'Sub_Unit_Head' => 'Sub-Unit Head',
    'Worker'        => 'Worker',
    'Member'        => 'Member',
];

/**
 * True once 20261111090000_department_ministry_years.sql has been applied.
 * A cached information_schema probe, not a fatal assumption, so the module can
 * say something useful instead of throwing a 500 when a deploy half-ran.
 */
function dept_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $tables = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'ministry_years'"
        )->fetchColumn();
        $columns = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_departments'
                AND column_name IN ('membership_type', 'ministry_year_id', 'roster_status')"
        )->fetchColumn();
        $ready = ($tables === 1 && $columns === 3);
    } catch (PDOException $e) {
        error_log('Department schema probe failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function dept_schema_message(): string
{
    return 'Departments needs database migration 20261111090000_department_ministry_years.sql. '
         . 'Ask your administrator to run “php db/migrate.php” on the server, then reload this page.';
}

/** The live ministry year, bootstrapped on first use. */
function dept_active_year(PDO $pdo): ?array
{
    $row = $pdo->query(
        "SELECT * FROM ministry_years WHERE is_active = 1 ORDER BY start_date DESC, id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row;
    }

    // Legacy database: no year configured yet. Open one for the current
    // calendar year and adopt every roster row that predates the concept.
    $year = (int) date('Y');
    $pdo->prepare(
        "INSERT INTO ministry_years (label, start_date, end_date, is_active, created_by)
         VALUES (?, ?, ?, 1, ?)"
    )->execute([(string) $year, "{$year}-01-01", "{$year}-12-31", $_SESSION['user_id'] ?? null]);

    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE user_departments SET ministry_year_id = ? WHERE ministry_year_id IS NULL")
        ->execute([$id]);

    return dept_year_by_id($pdo, $id);
}

function dept_year_by_id(PDO $pdo, int $year_id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM ministry_years WHERE id = ?");
    $stmt->execute([$year_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Every ministry year, newest first, each with its live head-count. */
function dept_list_years(PDO $pdo): array
{
    $years = $pdo->query(
        "SELECT id, label, start_date, end_date, is_active, closed_at
           FROM ministry_years
          ORDER BY start_date DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$years) {
        return [];
    }

    $counts = $pdo->query(
        "SELECT ministry_year_id, COUNT(DISTINCT user_id) AS people
           FROM user_departments
          WHERE roster_status = 'Active' AND ministry_year_id IS NOT NULL
          GROUP BY ministry_year_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($years as &$y) {
        $y['people']  = (int) ($counts[$y['id']] ?? 0);
        $y['period']  = dept_period_text($y);
        $y['archived'] = empty($y['is_active']);
    }
    unset($y);

    return $years;
}

/** "1 Jan 2026 – 31 Dec 2026" */
function dept_period_text(array $year): string
{
    $from = strtotime($year['start_date'] ?? '');
    $to   = strtotime($year['end_date'] ?? '');
    if (!$from || !$to) {
        return '';
    }
    return date('j M Y', $from) . ' – ' . date('j M Y', $to);
}

/**
 * "Ministry Year 2026" when the year is a plain calendar year, otherwise
 * "Ministry Year 2026/2027" — the label the exports carry at the top.
 */
function dept_year_heading(array $year): string
{
    $from = (int) date('Y', strtotime($year['start_date'] ?? 'now'));
    $to   = (int) date('Y', strtotime($year['end_date'] ?? 'now'));
    $label = trim((string) ($year['label'] ?? ''));

    if ($label === '') {
        $label = $from === $to ? (string) $from : "{$from}/{$to}";
    }
    return 'Ministry Year ' . $label;
}

/**
 * Department ids the signed-in user may CHANGE, or null for "all of them".
 * Ranking roles see everything; a Director / HOD / Sub-Unit Head only sees the
 * departments they actually lead this year (plus their sub-units). This is what
 * makes relieving someone of duty take effect immediately, whatever global
 * role they still carry.
 */
function dept_manageable_ids(PDO $pdo, int $user_id, string $active_role): ?array
{
    if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT department_id
           FROM user_departments
          WHERE user_id = ? AND is_active = 1
            AND role_in_dept IN ('Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head')"
    );
    $stmt->execute([$user_id]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($ids) {
        // A leader also oversees the sub-units hanging off their department.
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sub = $pdo->prepare("SELECT id FROM departments WHERE parent_id IN ($in)");
        $sub->execute($ids);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', $sub->fetchAll(PDO::FETCH_COLUMN)))));
    }

    return $ids;
}

function dept_can_manage(?array $scope, int $dept_id): bool
{
    return $scope === null || in_array($dept_id, $scope, true);
}

/** All departments (master + sub-units) with this year's head-counts. */
function dept_year_departments(PDO $pdo, int $year_id): array
{
    $stmt = $pdo->prepare(
        "SELECT d.id, d.name, d.description, d.type, d.parent_id, d.target_demographic,
                (SELECT COUNT(*) FROM user_departments ud
                  WHERE ud.department_id = d.id AND ud.ministry_year_id = ?
                    AND ud.roster_status = 'Active' AND ud.membership_type = 'Primary') AS primary_count,
                (SELECT COUNT(*) FROM user_departments ud
                  WHERE ud.department_id = d.id AND ud.ministry_year_id = ?
                    AND ud.roster_status = 'Active' AND ud.membership_type <> 'Primary') AS secondary_count
           FROM departments d
          ORDER BY d.name ASC"
    );
    $stmt->execute([$year_id, $year_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Leaders for the year, one lookup for the whole page.
    $leaderStmt = $pdo->prepare(
        "SELECT ud.department_id, ud.role_in_dept,
                u.id AS user_id, u.first_name, u.last_name, u.gender, u.phone
           FROM user_departments ud
           JOIN users u ON ud.user_id = u.id
          WHERE ud.ministry_year_id = ?
            AND ud.roster_status = 'Active'
            AND ud.role_in_dept IN ('Assoc_Pastor', 'Director', 'HOD')
          ORDER BY ud.id ASC"
    );
    $leaderStmt->execute([$year_id]);

    $leaders = [];
    foreach ($leaderStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $leaders[(int) $l['department_id']][$l['role_in_dept']] = $l;
    }

    foreach ($rows as &$d) {
        $d['id']            = (int) $d['id'];
        $d['parent_id']     = $d['parent_id'] === null ? null : (int) $d['parent_id'];
        $d['primary_count']  = (int) $d['primary_count'];
        $d['secondary_count'] = (int) $d['secondary_count'];
        $d['total_count']    = $d['primary_count'] + $d['secondary_count'];
        $d['leaders']        = $leaders[$d['id']] ?? [];
    }
    unset($d);

    return $rows;
}

/** The full roster of one ministry year: every Active row, removed rows never. */
function dept_year_roster(PDO $pdo, int $year_id, array $dept_ids = []): array
{
    $sql = "SELECT ud.id AS record_id, ud.department_id, ud.role_in_dept, ud.membership_type,
                   ud.season, ud.roster_status, ud.is_active, ud.joined_at, ud.end_date,
                   u.id AS user_id, u.first_name, u.last_name, u.gender, u.phone, u.email
              FROM user_departments ud
              JOIN users u ON ud.user_id = u.id
             WHERE ud.ministry_year_id = ?
               AND ud.roster_status = 'Active'";
    $params = [$year_id];

    if ($dept_ids) {
        $sql .= " AND ud.department_id IN (" . implode(',', array_fill(0, count($dept_ids), '?')) . ")";
        $params = array_merge($params, $dept_ids);
    }

    $sql .= " ORDER BY FIELD(ud.role_in_dept, 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head', 'Worker', 'Member'),
                       u.first_name ASC, u.last_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** How many people are serving in the *live* (non-archived) year. */
function dept_live_headcount(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(DISTINCT user_id) FROM user_departments
          WHERE is_active = 1 AND roster_status = 'Active'"
    )->fetchColumn();
}

/**
 * The whole picture the downloads need: every department, its leaders, its
 * Primary and Secondary members, nested under the master department it belongs
 * to. Shared by the A4 image renderer (via /api/department_api.php) and the
 * Excel export.
 */
function dept_report_dataset(PDO $pdo, array $year): array
{
    $year_id     = (int) $year['id'];
    $departments = dept_year_departments($pdo, $year_id);
    $roster      = dept_year_roster($pdo, $year_id);

    // Bucket the roster by department.
    $by_dept = [];
    $people  = [];
    foreach ($roster as $r) {
        $by_dept[(int) $r['department_id']][] = $r;
        $people[(int) $r['user_id']] = true;
    }

    $shape = function (array $r): array {
        return [
            'user_id'       => (int) $r['user_id'],
            'name'          => trim($r['first_name'] . ' ' . $r['last_name']),
            'first_name'    => $r['first_name'],
            'last_name'     => $r['last_name'],
            'gender'        => $r['gender'],
            'phone'         => $r['phone'],
            'email'         => $r['email'],
            'role_in_dept'  => $r['role_in_dept'],
            'sub_unit_head' => $r['role_in_dept'] === 'Sub_Unit_Head',
            'joined'        => !empty($r['joined_at']) ? date('j M Y', strtotime($r['joined_at'])) : null,
        ];
    };

    $build = function (array $dept) use ($by_dept, $shape) {
        $primary = $secondary = [];
        foreach ($by_dept[$dept['id']] ?? [] as $r) {
            if (in_array($r['role_in_dept'], DEPT_LEADER_ROLES, true)) {
                continue;                       // leaders get their own line
            }
            if ($r['membership_type'] === 'Secondary') {
                $secondary[] = $shape($r);
            } else {
                $primary[] = $shape($r);
            }
        }

        $leaders = [];
        foreach (DEPT_LEADER_ROLES as $role) {
            $leaders[$role] = isset($dept['leaders'][$role])
                ? [
                    'user_id' => (int) $dept['leaders'][$role]['user_id'],
                    'name'    => trim($dept['leaders'][$role]['first_name'] . ' ' . $dept['leaders'][$role]['last_name']),
                    'gender'  => $dept['leaders'][$role]['gender'],
                    'phone'   => $dept['leaders'][$role]['phone'],
                ]
                : null;
        }

        return [
            'id'              => $dept['id'],
            'name'            => $dept['name'],
            'type'            => $dept['type'],
            'description'     => $dept['description'],
            'parent_id'       => $dept['parent_id'],
            'parent_name'     => null,
            'target_demographic' => $dept['target_demographic'],
            'leaders'         => $leaders,
            'primary'         => $primary,
            'secondary'       => $secondary,
            'primary_count'   => count($primary),
            'secondary_count' => count($secondary),
            'is_empty'        => !$primary && !$secondary && !array_filter($leaders),
            'sub_units'       => [],
        ];
    };

    $known_ids = array_column($departments, 'id');
    $master = $children = [];
    foreach ($departments as $dept) {
        // A department whose parent is missing counts as a master department:
        // it still has a roster to print, so it must not be dropped.
        if ($dept['parent_id'] === null || !in_array($dept['parent_id'], $known_ids, true)) {
            $master[$dept['id']] = $build($dept);
        } else {
            $children[$dept['parent_id']][] = $dept;
        }
    }

    foreach ($children as $parent_id => $kids) {
        if (!isset($master[$parent_id])) {
            continue;
        }
        foreach ($kids as $kid) {
            $node = $build($kid);
            $node['parent_name'] = $master[$parent_id]['name'];
            $master[$parent_id]['sub_units'][] = $node;
        }
    }

    $all_nodes = [];
    foreach ($master as $node) {
        $all_nodes[] = $node;
        foreach ($node['sub_units'] as $kid) {
            $all_nodes[] = $kid;
        }
    }

    $primary = $secondary = 0;
    foreach ($all_nodes as $node) {
        $primary   += $node['primary_count'];
        $secondary += $node['secondary_count'];
    }

    return [
        'year'    => [
            'id'         => $year_id,
            'label'      => $year['label'],
            'heading'    => dept_year_heading($year),
            'period'     => dept_period_text($year),
            'start_date' => $year['start_date'],
            'end_date'   => $year['end_date'],
            'is_active'  => (int) $year['is_active'],
        ],
        'church'  => [
            'name'    => 'Household of David Lekki Centre',
            'short'   => 'HOD Lekki Centre',
            'logo'    => '/assets/images/logo_hod.png',
            'accent'  => '#D11920',
            'ink'     => '#0A0E17',
        ],
        'departments' => array_values($master),
        'flat'        => $all_nodes,
        'totals'      => [
            'departments' => count($all_nodes),
            'primary'     => $primary,
            'secondary'   => $secondary,
            'people'      => count($people),
        ],
        'generated_at' => date('j M Y, g:i a'),
    ];
}

/** Flat, one-row-per-person list for the "All Workers" Excel sheet. */
function dept_flat_worker_rows(PDO $pdo, int $year_id): array
{
    $stmt = $pdo->prepare(
        "SELECT d.name AS department, d.parent_id,
                ud.role_in_dept, ud.membership_type, ud.season, ud.joined_at,
                u.first_name, u.last_name, u.gender, u.phone, u.email, u.spiritual_status
           FROM user_departments ud
           JOIN users u ON ud.user_id = u.id
           JOIN departments d ON ud.department_id = d.id
          WHERE ud.ministry_year_id = ?
            AND ud.roster_status = 'Active'
          ORDER BY d.name ASC, FIELD(ud.role_in_dept, 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head', 'Worker', 'Member'),
                   u.first_name ASC, u.last_name ASC"
    );
    $stmt->execute([$year_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
