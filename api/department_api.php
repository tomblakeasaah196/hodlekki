<?php
// /api/department_api.php
//
// Departments module endpoint.
//
// Everything here is ministry-year aware:
//   * a roster row belongs to exactly ONE ministry year per department;
//   * a person is either Primary or Secondary in a department, one row each;
//   * removing somebody marks the row Removed (and is_active = 0) instead of
//     leaving it on screen — the UI never renders Removed rows again, but the
//     audit trail survives and re-adding the same person reactivates the row.
//
// The old implementation let one person accumulate several rows per department
// (one per role), which is what made "assign a worker" look empty until a
// manual page refresh: the reload re-queried with a different ORDER BY and the
// duplicate rows masked the change. Mutations are now one row per person per
// department per year, executed in a transaction.

require_once '../includes/db.php';
require_once '../includes/department_helpers.php';
header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// 1. Core security & session check
// ---------------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id     = (int) $_SESSION['user_id'];
$active_role = $_SESSION['active_role'] ?? 'Member';
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

// Leadership-only module (unchanged from the previous release).
if (!in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. Department management is restricted to leadership.']);
    exit;
}

$RANK_ROLES  = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];
$is_rank     = in_array($active_role, $RANK_ROLES, true);
$can_roll    = in_array($active_role, ['Super_Admin', 'Resident_Pastor'], true);

if (!dept_schema_ready($pdo)) {
    echo json_encode(['status' => 'error', 'message' => dept_schema_message(), 'code' => 'schema']);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Global role ladder, used to propagate leadership roles to user_roles. */
function syncGlobalRole(PDO $pdo, $user_id, $role_name): void
{
    if (!$user_id) {
        return;
    }
    $roles = ['Super_Admin' => 1, 'Resident_Pastor' => 2, 'Assoc_Pastor' => 3, 'Director' => 4, 'HOD' => 5, 'Worker' => 6];
    $role_id = $roles[$role_name] ?? 6;
    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$user_id, $role_id]);
}

function dept_notify(PDO $pdo, $user_id, string $title, string $message, string $link = '/modules/departments/index.php'): void
{
    if (!$user_id) {
        return;
    }
    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)")
        ->execute([$user_id, $title, $message, $link]);
}

function dept_fail(string $message, string $code = 'error'): void
{
    echo json_encode(['status' => 'error', 'code' => $code, 'message' => $message]);
    exit;
}

function dept_role_label(string $role): string
{
    return DEPT_ROLE_LABELS[$role] ?? str_replace('_', ' ', $role);
}

function dept_clean_membership($value): string
{
    return strcasecmp((string) $value, 'Secondary') === 0 ? 'Secondary' : 'Primary';
}

/** The active leader of a department role, or null. */
function getActiveLeaderId(PDO $pdo, $dept_id, $role_in_dept, $year_id)
{
    if (!$dept_id || !$year_id) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT user_id FROM user_departments
          WHERE department_id = ? AND role_in_dept = ? AND ministry_year_id = ?
            AND roster_status = 'Active'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$dept_id, $role_in_dept, $year_id]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Put ONE Active roster row for (person, department, year).
 * Updates the existing row when there is one, so re-adding somebody who was
 * removed earlier brings the same record back to life instead of duplicating it.
 */
function dept_put_roster_row(PDO $pdo, array $ctx, int $user_id, int $dept_id, string $role, string $membership, bool $active = true): int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM user_departments
          WHERE user_id = ? AND department_id = ? AND ministry_year_id = ?
          ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$user_id, $dept_id, $ctx['year_id']]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $update = $pdo->prepare(
            "UPDATE user_departments
                SET role_in_dept = ?, membership_type = ?, roster_status = 'Active',
                    is_active = ?, end_date = NULL, season = ?
              WHERE id = ?"
        );
        $update->execute([$role, $membership, $active ? 1 : 0, $ctx['season'], $existing]);
        return (int) $existing;
    }

    $insert = $pdo->prepare(
        "INSERT INTO user_departments
             (user_id, department_id, role_in_dept, membership_type, season, ministry_year_id, roster_status, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 'Active', ?)"
    );
    $insert->execute([$user_id, $dept_id, $role, $membership, $ctx['season'], $ctx['year_id'], $active ? 1 : 0]);

    return (int) $pdo->lastInsertId();
}

/** Take somebody off a department's live roster, keeping the audit row. */
function dept_remove_roster_row(PDO $pdo, array $ctx, int $user_id, int $dept_id): void
{
    $pdo->prepare(
        "UPDATE user_departments
            SET roster_status = 'Removed', is_active = 0, end_date = CURDATE()
          WHERE user_id = ? AND department_id = ? AND ministry_year_id = ?
            AND roster_status = 'Active'"
    )->execute([$user_id, $dept_id, $ctx['year_id']]);
}

/**
 * Change the person holding a leadership seat (Pastor in Charge / Director /
 * HOD). The outgoing leader is taken off the department entirely — the record
 * stays in the database as Removed, invisible in the UI, and re-addable later.
 *
 * Returns the names involved so the caller can report them.
 */
function dept_set_leader(PDO $pdo, array $ctx, array $dept, string $role, ?int $new_user_id): array
{
    $dept_id = (int) $dept['id'];
    $old     = getActiveLeaderId($pdo, $dept_id, $role, $ctx['year_id']);

    if ($old === $new_user_id) {
        return ['changed' => false];
    }

    $out = ['changed' => true, 'outgoing' => null, 'incoming' => null];
    $label = dept_role_label($role);

    if ($old) {
        $nameStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $nameStmt->execute([$old]);
        $out['outgoing'] = trim(implode(' ', array_filter($nameStmt->fetch(PDO::FETCH_NUM) ?: [])));

        dept_remove_roster_row($pdo, $ctx, $old, $dept_id);
        dept_notify(
            $pdo,
            $old,
            'Department Update',
            "You have been relieved of duty as {$label} of {$dept['name']}. Thank you for your service — you have been removed from the department roster and can be re-added at any time.",
            '/modules/profile/index.php'
        );
    }

    if ($new_user_id) {
        $nameStmt = $pdo->prepare("SELECT first_name, last_name, gender FROM users WHERE id = ?");
        $nameStmt->execute([$new_user_id]);
        $person = $nameStmt->fetch(PDO::FETCH_ASSOC);

        if (!$person) {
            throw new RuntimeException('The selected person no longer exists.');
        }
        if ($dept['target_demographic'] === 'Male_Only' && $person['gender'] !== 'Male') {
            throw new RuntimeException("Gender Restriction: only male workers can serve in {$dept['name']}.");
        }
        if ($dept['target_demographic'] === 'Female_Only' && $person['gender'] !== 'Female') {
            throw new RuntimeException("Gender Restriction: only female workers can serve in {$dept['name']}.");
        }

        dept_put_roster_row($pdo, $ctx, $new_user_id, $dept_id, $role, 'Primary', true);
        syncGlobalRole($pdo, $new_user_id, $role);

        $out['incoming'] = trim($person['first_name'] . ' ' . $person['last_name']);
        dept_notify(
            $pdo,
            $new_user_id,
            'Leadership Appointment',
            "You have been appointed as the {$label} of {$dept['name']} for {$ctx['year_label']}."
        );
    }

    return $out;
}

function dept_department(PDO $pdo, int $dept_id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$dept_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Guard: the year must be live before anything can be changed in it. */
function dept_require_live_year(array $year): void
{
    if (empty($year['is_active'])) {
        dept_fail('This ministry year is archived and read-only. Switch to the current year to make changes.', 'archived');
    }
}

try {
    switch ($action) {

        // =====================================================================
        // ACTION 1: FETCH DASHBOARD (all departments + master lists)
        // =====================================================================
        case 'fetch_dashboard':
            $years       = dept_list_years($pdo);
            $active_year = dept_active_year($pdo);

            $requested = (int) ($_POST['ministry_year_id'] ?? $_GET['ministry_year_id'] ?? 0);
            $year      = $requested ? dept_year_by_id($pdo, $requested) : $active_year;
            if (!$year) {
                dept_fail('That ministry year no longer exists.');
            }
            $year_id = (int) $year['id'];

            $flat      = dept_year_departments($pdo, $year_id);
            $master    = [];
            $all_depts = [];
            $by_id     = [];

            foreach ($flat as $d) {
                $all_depts[] = ['id' => $d['id'], 'name' => $d['name']];
                $by_id[$d['id']] = $d;
            }

            // Nest sub-units inside their master department, and fold a
            // sub-unit's people into the master department's totals on the card.
            $known_ids = array_column($flat, 'id');
            foreach ($flat as $d) {
                // Orphaned sub-units (parent deleted) are shown as top-level so
                // their roster stays reachable.
                if ($d['parent_id'] === null || !in_array($d['parent_id'], $known_ids, true)) {
                    $master[$d['id']] = $d;
                }
            }
            foreach ($flat as $d) {
                if ($d['parent_id'] !== null && isset($master[$d['parent_id']])) {
                    $master[$d['parent_id']]['sub_units'][] = $d;
                }
            }
            foreach ($master as &$m) {
                foreach ($m['sub_units'] ?? [] as $sub) {
                    $m['primary_count']   += $sub['primary_count'];
                    $m['secondary_count'] += $sub['secondary_count'];
                    $m['total_count']      = $m['primary_count'] + $m['secondary_count'];
                }
            }
            unset($m);

            // Pick lists
            $pastors = $pdo->query(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.gender
                   FROM users u
                   JOIN user_roles ur ON u.id = ur.user_id
                   JOIN roles r ON ur.role_id = r.id
                  WHERE r.role_name IN ('Resident_Pastor', 'Assoc_Pastor')
                  ORDER BY u.first_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $directors = $pdo->query(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.gender
                   FROM users u
                   JOIN user_roles ur ON u.id = ur.user_id
                   JOIN roles r ON ur.role_id = r.id
                  WHERE r.role_name = 'Director'
                  ORDER BY u.first_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $all_workers = $pdo->query(
                "SELECT id, first_name, last_name, gender
                   FROM users
                  WHERE spiritual_status IN ('Worker', 'Member')
                  ORDER BY first_name ASC, last_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);

            echo json_encode([
                'status'              => 'success',
                'ministry_years'      => $years,
                'year'                => [
                    'id'         => $year_id,
                    'label'      => $year['label'],
                    'heading'    => dept_year_heading($year),
                    'period'     => dept_period_text($year),
                    'is_active'  => (int) $year['is_active'],
                    'archived'   => empty($year['is_active']),
                ],
                'active_year_id'      => (int) ($active_year['id'] ?? 0),
                'master_departments'  => array_values($master),
                'pastors'             => $pastors,
                'directors'           => $directors,
                'all_workers'         => $all_workers,
                'all_depts'           => $all_depts,
                'permissions'         => [
                    'can_manage_all'    => $scope === null,
                    'manageable_ids'    => $scope,
                    'can_rollover'      => $can_roll && !empty($year['is_active']),
                    'can_edit_year'     => $can_roll,
                    'live_headcount'    => dept_live_headcount($pdo),
                ],
            ]);
            break;

        // =====================================================================
        // ACTION 2: CREATE / UPDATE DEPARTMENT
        // =====================================================================
        case 'save_department':
            $dept_id   = empty($_POST['department_id']) ? null : (int) $_POST['department_id'];
            $name      = trim($_POST['name'] ?? '');
            $desc      = trim($_POST['description'] ?? '');
            $type      = $_POST['type'] ?? 'Service_Unit';
            $demo      = $_POST['target_demographic'] ?? 'All';
            $parent_id = empty($_POST['parent_id']) ? null : (int) $_POST['parent_id'];

            $active_year = dept_active_year($pdo);
            $year        = $active_year;
            if (!empty($_POST['ministry_year_id'])) {
                $year = dept_year_by_id($pdo, (int) $_POST['ministry_year_id']) ?: $active_year;
            }
            dept_require_live_year($year);

            if ($name === '') {
                dept_fail('Department name is required.');
            }

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);

            $ap_id  = empty($_POST['assoc_pastor_id']) ? null : (int) $_POST['assoc_pastor_id'];
            $dir_id = empty($_POST['director_id'])     ? null : (int) $_POST['director_id'];
            $hod_id = empty($_POST['hod_id'])          ? null : (int) $_POST['hod_id'];

            $ctx = [
                'year_id'    => (int) $year['id'],
                'year_label' => dept_year_heading($year),
                'season'     => (int) date('Y', strtotime($year['start_date'])),
            ];

            $pdo->beginTransaction();

            if ($dept_id) {
                if (!dept_can_manage($scope, $dept_id)) {
                    $pdo->rollBack();
                    dept_fail('You can only manage departments you lead.');
                }
                $existing = dept_department($pdo, $dept_id);
                if (!$existing) {
                    $pdo->rollBack();
                    dept_fail('That department no longer exists.');
                }
                $pdo->prepare("UPDATE departments SET name=?, description=?, type=?, target_demographic=?, parent_id=? WHERE id=?")
                    ->execute([$name, $desc, $type, $demo, $parent_id, $dept_id]);
                $dept = dept_department($pdo, $dept_id);
                $msg  = 'Department updated and leadership transitions applied.';
            } else {
                if (!$is_rank) {
                    $pdo->rollBack();
                    dept_fail('Only a Super Admin or a Pastor can create new departments.');
                }
                $pdo->prepare("INSERT INTO departments (name, description, type, target_demographic, parent_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$name, $desc, $type, $demo, $parent_id]);
                $dept_id = (int) $pdo->lastInsertId();
                $dept    = dept_department($pdo, $dept_id);
                $msg     = 'Department created successfully.';
            }

            // Leadership transitions. Passing an empty value clears the seat
            // and relieves the sitting leader — that is how a vacancy is made.
            $transitions = [];
            $seats = [
                'Assoc_Pastor' => $ap_id,
                'Director'     => $dir_id,
                'HOD'          => $hod_id,
            ];
            // Only touch a seat that the form actually sent, so a partial form
            // can never silently vacate a seat.
            foreach ($seats as $role => $new_id) {
                $field = ['Assoc_Pastor' => 'assoc_pastor_id', 'Director' => 'director_id', 'HOD' => 'hod_id'][$role];
                if (!array_key_exists($field, $_POST)) {
                    continue;
                }
                if ($role === 'Assoc_Pastor' && !in_array($active_role, $RANK_ROLES, true)) {
                    continue;   // only a Pastor / Super Admin appoints the Pastor in Charge
                }
                $result = dept_set_leader($pdo, $ctx, $dept, $role, $new_id);
                if ($result['changed']) {
                    $transitions[] = $result + ['role' => $role];
                }
            }

            $pdo->commit();

            // Tell the outgoing leaders (if any) in the response so the toast can
            // spell out what happened.
            $notes = [];
            foreach ($transitions as $t) {
                $notes[] = dept_role_label($t['role']) . ': '
                    . ($t['outgoing'] ?: 'vacant') . ' → ' . ($t['incoming'] ?: 'vacant');
            }
            if ($notes) {
                $msg .= ' (' . implode('; ', $notes) . ')';
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================
        // ACTION 3: DEPARTMENT DETAILS (roster for one ministry year)
        // =====================================================================
        case 'fetch_department_details':
            $dept_id = (int) ($_POST['department_id'] ?? 0);
            if (!$dept_id) {
                dept_fail('Department ID missing.');
            }

            $year = !empty($_POST['ministry_year_id'])
                ? dept_year_by_id($pdo, (int) $_POST['ministry_year_id'])
                : dept_active_year($pdo);
            if (!$year) {
                dept_fail('No ministry year configured.');
            }
            $year_id = (int) $year['id'];

            $dept = dept_department($pdo, $dept_id);
            if (!$dept) {
                dept_fail('That department no longer exists.');
            }

            // Leaders for this year (the edit modal pre-fills from these).
            $info = array_merge($dept, [
                'id'        => (int) $dept['id'],
                'parent_id' => $dept['parent_id'] === null ? null : (int) $dept['parent_id'],
            ]);
            foreach (['Assoc_Pastor' => 'ap', 'Director' => 'dir', 'HOD' => 'hod'] as $role => $prefix) {
                $leader_id = getActiveLeaderId($pdo, $dept_id, $role, $year_id);
                $info[$prefix . '_id'] = $leader_id;
                if ($leader_id) {
                    $stmt = $pdo->prepare("SELECT first_name, last_name, gender FROM users WHERE id = ?");
                    $stmt->execute([$leader_id]);
                    $person = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'gender' => ''];
                    $info[$prefix . '_fname'] = $person['first_name'];
                    $info[$prefix . '_lname'] = $person['last_name'];
                    $info[$prefix . '_gender'] = $person['gender'];
                } else {
                    $info[$prefix . '_fname'] = $info[$prefix . '_lname'] = $info[$prefix . '_gender'] = null;
                }
            }

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);

            // Sub-units of this department, counted for the same year.
            $subStmt = $pdo->prepare(
                "SELECT d.id, d.name, d.type,
                        (SELECT COUNT(*) FROM user_departments ud
                          WHERE ud.department_id = d.id AND ud.ministry_year_id = ?
                            AND ud.roster_status = 'Active') AS active_members
                   FROM departments d WHERE d.parent_id = ? ORDER BY d.name ASC"
            );
            $subStmt->execute([$year_id, $dept_id]);
            $sub_units = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            // The dashboard card counts a master department together with its
            // sub-units, so the detail view says out loud how much of that
            // number lives in the sub-units rather than in this roster.
            $subCountStmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN ud.membership_type = 'Primary' THEN 1 ELSE 0 END) AS sub_primary,
                    SUM(CASE WHEN ud.membership_type <> 'Primary' THEN 1 ELSE 0 END) AS sub_secondary
                   FROM user_departments ud
                   JOIN departments sub ON ud.department_id = sub.id
                  WHERE sub.parent_id = ? AND ud.ministry_year_id = ?
                    AND ud.roster_status = 'Active'
                    AND ud.role_in_dept NOT IN ('Assoc_Pastor', 'Director', 'HOD')"
            );
            $subCountStmt->execute([$dept_id, $year_id]);
            $subCounts = $subCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $sub_unit_counts = [
                'primary'   => (int) ($subCounts['sub_primary'] ?? 0),
                'secondary' => (int) ($subCounts['sub_secondary'] ?? 0),
            ];

            // Roster: Active rows only. Removed people are gone from the UI.
            $rosterStmt = $pdo->prepare(
                "SELECT ud.id AS record_id, ud.role_in_dept, ud.membership_type, ud.season,
                        ud.roster_status, ud.is_active, ud.joined_at, ud.end_date,
                        u.id AS user_id, u.first_name, u.last_name, u.gender, u.phone
                   FROM user_departments ud
                   JOIN users u ON ud.user_id = u.id
                  WHERE ud.department_id = ? AND ud.ministry_year_id = ?
                    AND ud.roster_status = 'Active'
                  ORDER BY u.first_name ASC, u.last_name ASC"
            );
            $rosterStmt->execute([$dept_id, $year_id]);
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            $primary = $secondary = $leaders = [];
            $active_user_ids = [];
            foreach ($roster as $r) {
                $r['record_id'] = (int) $r['record_id'];
                $r['user_id']   = (int) $r['user_id'];
                $active_user_ids[] = $r['user_id'];
                if (in_array($r['role_in_dept'], DEPT_LEADER_ROLES, true)) {
                    $leaders[] = $r;
                } elseif ($r['membership_type'] === 'Secondary') {
                    $secondary[] = $r;
                } else {
                    $primary[] = $r;
                }
            }

            echo json_encode([
                'status'        => 'success',
                'info'          => $info,
                'sub_units'     => $sub_units,
                'sub_unit_counts' => $sub_unit_counts,
                'leaders'       => $leaders,
                'primary'       => $primary,
                'secondary'     => $secondary,
                'active_user_ids' => $active_user_ids,
                'year'          => [
                    'id'        => $year_id,
                    'label'     => $year['label'],
                    'heading'   => dept_year_heading($year),
                    'period'    => dept_period_text($year),
                    'is_active' => (int) $year['is_active'],
                    'archived'  => empty($year['is_active']),
                ],
                'permissions'   => [
                    'can_manage' => dept_can_manage($scope, $dept_id) && !empty($year['is_active']),
                    'archived'   => empty($year['is_active']),
                    'is_rank'    => $is_rank,
                ],
            ]);
            break;

        // =====================================================================
        // ACTION 4: ASSIGN WORKER (Primary by default)
        // =====================================================================
        case 'assign_worker':
            $dept_id   = (int) ($_POST['department_id'] ?? 0);
            $worker_id = (int) ($_POST['user_id'] ?? 0);
            $role      = $_POST['role_in_dept'] ?? 'Worker';
            $membership = dept_clean_membership($_POST['membership_type'] ?? 'Primary');

            if (!$dept_id || !$worker_id) {
                dept_fail('Department and person are required.');
            }
            if (!in_array($role, ['Worker', 'Member', 'Sub_Unit_Head'], true)) {
                dept_fail('That role cannot be assigned here. Use Edit Settings to appoint leadership.');
            }

            $year = dept_active_year($pdo);
            dept_require_live_year($year);

            $dept = dept_department($pdo, $dept_id);
            if (!$dept) {
                dept_fail('That department no longer exists.');
            }
            $scope = dept_manageable_ids($pdo, $user_id, $active_role);
            if (!dept_can_manage($scope, $dept_id)) {
                dept_fail('You can only manage departments you lead.');
            }

            $userStmt = $pdo->prepare("SELECT gender, first_name, last_name FROM users WHERE id = ?");
            $userStmt->execute([$worker_id]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$userData) {
                dept_fail('That person no longer exists.');
            }

            if ($dept['target_demographic'] === 'Male_Only' && $userData['gender'] !== 'Male') {
                dept_fail("Gender Restriction: Only male workers can be assigned to {$dept['name']}.");
            }
            if ($dept['target_demographic'] === 'Female_Only' && $userData['gender'] !== 'Female') {
                dept_fail("Gender Restriction: Only female workers can be assigned to {$dept['name']}.");
            }

            $activeCheck = $pdo->prepare(
                "SELECT id FROM user_departments
                  WHERE user_id = ? AND department_id = ? AND ministry_year_id = ?
                    AND roster_status = 'Active'"
            );
            $activeCheck->execute([$worker_id, $dept_id, $year['id']]);
            if ($activeCheck->fetch()) {
                dept_fail('This person is already on this department\'s roster for this ministry year.');
            }

            $ctx = [
                'year_id'    => (int) $year['id'],
                'year_label' => dept_year_heading($year),
                'season'     => (int) date('Y', strtotime($year['start_date'])),
            ];

            $pdo->beginTransaction();
            dept_put_roster_row($pdo, $ctx, $worker_id, $dept_id, $role, $membership, true);
            syncGlobalRole($pdo, $worker_id, $role);
            $pdo->commit();

            dept_notify(
                $pdo,
                $worker_id,
                'Department Assignment',
                "You have been assigned to the {$dept['name']} department as a " . dept_role_label($role)
                    . " ({$membership} member) for {$ctx['year_label']}.",
                '/modules/profile/index.php'
            );

            // Alert the department's leadership.
            $leaderStmt = $pdo->prepare(
                "SELECT user_id FROM user_departments
                  WHERE department_id = ? AND ministry_year_id = ?
                    AND role_in_dept IN ('HOD', 'Director') AND roster_status = 'Active'
                    AND user_id <> ?"
            );
            $leaderStmt->execute([$dept_id, $year['id'], $worker_id]);
            foreach ($leaderStmt->fetchAll(PDO::FETCH_COLUMN) as $leader_id) {
                dept_notify(
                    $pdo,
                    $leader_id,
                    'New Team Member',
                    "{$userData['first_name']} {$userData['last_name']} has been added to your department ({$dept['name']}) as a {$membership} member."
                );
            }

            // Burnout monitor — still a warning, never a blocker.
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_departments WHERE user_id = ? AND is_active = 1");
            $countStmt->execute([$worker_id]);
            $active_count = (int) $countStmt->fetchColumn();

            if ($active_count > 4) {
                echo json_encode([
                    'status'  => 'warning',
                    'message' => "Assigned as a {$membership} member. Note: {$userData['first_name']} is now serving in $active_count departments — watch for burnout.",
                ]);
            } else {
                echo json_encode(['status' => 'success', 'message' => "Successfully added as a {$membership} member of {$dept['name']}."]);
            }
            break;

        // =====================================================================
        // ACTION 5: REMOVE WORKER (gone from the UI, kept for audit)
        // =====================================================================
        case 'remove_worker':
            $record_id = (int) ($_POST['record_id'] ?? 0);
            if (!$record_id) {
                dept_fail('Record ID required.');
            }

            $stmt = $pdo->prepare(
                "SELECT ud.id, ud.user_id, ud.department_id, ud.role_in_dept, ud.membership_type,
                        ud.ministry_year_id, d.name AS dept_name, d.target_demographic,
                        u.first_name, u.last_name
                   FROM user_departments ud
                   JOIN departments d ON ud.department_id = d.id
                   JOIN users u ON ud.user_id = u.id
                  WHERE ud.id = ?"
            );
            $stmt->execute([$record_id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                dept_fail('That roster record no longer exists.');
            }

            $year = dept_year_by_id($pdo, (int) $record['ministry_year_id']) ?: dept_active_year($pdo);
            dept_require_live_year($year);

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);
            if (!dept_can_manage($scope, (int) $record['department_id'])) {
                dept_fail('You can only manage departments you lead.');
            }
            if (in_array($record['role_in_dept'], DEPT_LEADER_ROLES, true)) {
                dept_fail('Leadership seats are changed with Edit Settings → Leadership, so the seat is never left empty by accident.');
            }

            $pdo->prepare(
                "UPDATE user_departments
                    SET roster_status = 'Removed', is_active = 0, end_date = CURDATE()
                  WHERE id = ?"
            )->execute([$record_id]);

            dept_notify(
                $pdo,
                (int) $record['user_id'],
                'Department Update',
                "You have been removed from the {$record['dept_name']} department roster. Thank you for your service — you can be added back at any time.",
                '/modules/profile/index.php'
            );

            echo json_encode([
                'status'  => 'success',
                'message' => "{$record['first_name']} {$record['last_name']} has been removed from {$record['dept_name']}. They no longer appear on the roster; add them again any time.",
            ]);
            break;

        // =====================================================================
        // ACTION 6: MOVE BETWEEN PRIMARY AND SECONDARY (one click)
        // =====================================================================
        case 'switch_membership':
            $record_id  = (int) ($_POST['record_id'] ?? 0);
            $membership = dept_clean_membership($_POST['membership_type'] ?? 'Secondary');

            if (!$record_id) {
                dept_fail('Record ID required.');
            }

            $stmt = $pdo->prepare(
                "SELECT ud.*, d.name AS dept_name, u.first_name, u.last_name
                   FROM user_departments ud
                   JOIN departments d ON ud.department_id = d.id
                   JOIN users u ON ud.user_id = u.id
                  WHERE ud.id = ?"
            );
            $stmt->execute([$record_id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                dept_fail('That roster record no longer exists.');
            }

            $year = dept_year_by_id($pdo, (int) $record['ministry_year_id']) ?: dept_active_year($pdo);
            dept_require_live_year($year);

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);
            if (!dept_can_manage($scope, (int) $record['department_id'])) {
                dept_fail('You can only manage departments you lead.');
            }
            if (in_array($record['role_in_dept'], DEPT_LEADER_ROLES, true)) {
                dept_fail('A leadership seat is always a Primary commitment. Change the seat first.');
            }

            $pdo->prepare("UPDATE user_departments SET membership_type = ? WHERE id = ?")
                ->execute([$membership, $record_id]);

            echo json_encode([
                'status'  => 'success',
                'message' => "{$record['first_name']} {$record['last_name']} is now a {$membership} member of {$record['dept_name']}.",
                'data'    => ['record_id' => $record_id, 'membership_type' => $membership],
            ]);
            break;

        // =====================================================================
        // ACTION 7: LEADERSHIP SEAT CHANGE (Edit Settings → Leadership)
        // =====================================================================
        case 'set_leader':
            $dept_id   = (int) ($_POST['department_id'] ?? 0);
            $role      = $_POST['role'] ?? '';
            $new_id    = empty($_POST['user_id']) ? null : (int) $_POST['user_id'];

            if (!$dept_id || !in_array($role, DEPT_LEADER_ROLES, true)) {
                dept_fail('Department and a valid leadership role are required.');
            }

            $year = dept_active_year($pdo);
            dept_require_live_year($year);

            $dept = dept_department($pdo, $dept_id);
            if (!$dept) {
                dept_fail('That department no longer exists.');
            }

            $scope = dept_manageable_ids($pdo, $user_id, $active_role);
            if (!dept_can_manage($scope, $dept_id)) {
                dept_fail('You can only manage departments you lead.');
            }
            if ($role === 'Assoc_Pastor' && !$is_rank) {
                dept_fail('Only a Super Admin or a Pastor can appoint the Pastor in Charge.');
            }

            $ctx = [
                'year_id'    => (int) $year['id'],
                'year_label' => dept_year_heading($year),
                'season'     => (int) date('Y', strtotime($year['start_date'])),
            ];

            $pdo->beginTransaction();
            $result = dept_set_leader($pdo, $ctx, $dept, $role, $new_id);
            $pdo->commit();

            if (!$result['changed']) {
                echo json_encode(['status' => 'success', 'message' => 'No change — that person already holds the seat.']);
                break;
            }

            $label = dept_role_label($role);
            echo json_encode([
                'status'  => 'success',
                'message' => $label . ' of ' . $dept['name'] . ' is now '
                    . ($result['incoming'] ?: 'vacant')
                    . ($result['outgoing'] ? " ({$result['outgoing']} was relieved of the role as requested)" : ''),
            ]);
            break;

        // =====================================================================
        // ACTION 8: MINISTRY YEARS (list / create / edit)
        // =====================================================================
        case 'save_ministry_year':
            if (!$can_roll) {
                dept_fail('Only a Super Admin or the Resident Pastor can manage ministry years.');
            }

            $id     = (int) ($_POST['id'] ?? 0);
            $label  = trim($_POST['label'] ?? '');
            $start  = trim($_POST['start_date'] ?? '');
            $end    = trim($_POST['end_date'] ?? '');
            $active = !empty($_POST['is_active']);

            if ($label === '' || !strtotime($start) || !strtotime($end)) {
                dept_fail('A label and both dates are required.');
            }
            if (strtotime($start) >= strtotime($end)) {
                dept_fail('The end date must be after the start date.');
            }

            $pdo->beginTransaction();

            if ($id) {
                $year = dept_year_by_id($pdo, $id);
                if (!$year) {
                    $pdo->rollBack();
                    dept_fail('That ministry year no longer exists.');
                }
                $pdo->prepare("UPDATE ministry_years SET label = ?, start_date = ?, end_date = ? WHERE id = ?")
                    ->execute([$label, date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end)), $id]);
            } else {
                $pdo->prepare("INSERT INTO ministry_years (label, start_date, end_date, is_active, created_by) VALUES (?, ?, ?, 0, ?)")
                    ->execute([$label, date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end)), $user_id]);
                $id = (int) $pdo->lastInsertId();
            }

            if ($active) {
                $pdo->exec("UPDATE ministry_years SET is_active = 0 WHERE id <> " . (int) $id);
                $pdo->prepare("UPDATE ministry_years SET is_active = 1, closed_at = NULL WHERE id = ?")->execute([$id]);

                // `is_active` on a roster row means "serving now, in the live
                // year", so it has to follow the year that was just made live:
                // stand down every other year, bring this year's Active rows up.
                // Without this, switching the live year from the Years panel
                // would leave two years' worth of rows flagged as serving.
                $pdo->prepare("UPDATE user_departments SET is_active = 0 WHERE is_active = 1 AND (ministry_year_id <> ? OR ministry_year_id IS NULL)")
                    ->execute([$id]);
                $pdo->prepare("UPDATE user_departments SET is_active = 1 WHERE ministry_year_id = ? AND roster_status = 'Active'")
                    ->execute([$id]);
            }

            $pdo->commit();

            // Labels can change → keep `season` aligned with the year it belongs to.
            $pdo->prepare("UPDATE user_departments ud JOIN ministry_years my ON ud.ministry_year_id = my.id
                              SET ud.season = YEAR(my.start_date)
                            WHERE ud.ministry_year_id = ?")->execute([$id]);

            echo json_encode(['status' => 'success', 'message' => 'Ministry year saved.', 'data' => ['id' => $id]]);
            break;

        // =====================================================================
        // ACTION 9: ROLLOVER WORKSHEET (shuffle a new year before committing)
        // =====================================================================
        case 'fetch_rollover_worksheet':
            if (!$can_roll) {
                dept_fail('Only a Super Admin or the Resident Pastor can start a new ministry year.');
            }

            $from_year = !empty($_POST['from_year_id'])
                ? dept_year_by_id($pdo, (int) $_POST['from_year_id'])
                : dept_active_year($pdo);
            if (!$from_year) {
                dept_fail('There is no current ministry year to roll over from.');
            }
            $from_id = (int) $from_year['id'];

            // Current roster, with the seat each person holds today.
            $roster = dept_year_roster($pdo, $from_id);
            $people = [];
            foreach ($roster as $r) {
                $people[(int) $r['user_id']][] = [
                    'record_id'      => (int) $r['record_id'],
                    'department_id'  => (int) $r['department_id'],
                    'role_in_dept'   => $r['role_in_dept'],
                    'membership_type'=> $r['membership_type'],
                ];
            }

            $departments = dept_year_departments($pdo, $from_id);
            $units = [];
            foreach ($departments as $d) {
                $units[] = [
                    'id'          => $d['id'],
                    'name'        => $d['name'],
                    'parent_id'   => $d['parent_id'],
                    'type'        => $d['type'],
                    'leaders'     => $d['leaders'],
                    'primary_count'   => $d['primary_count'],
                    'secondary_count' => $d['secondary_count'],
                ];
            }

            // Everyone who could be put in the new year.
            $candidates = $pdo->query(
                "SELECT id, first_name, last_name, gender, spiritual_status
                   FROM users
                  WHERE spiritual_status IN ('Worker', 'Member')
                  ORDER BY first_name ASC, last_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Suggested next period: the day after the current year ends,
            // running to the end of the following calendar year.
            $endTs   = strtotime($from_year['end_date']);
            $nextY   = (int) date('Y', $endTs);
            $suggest = [
                'label'      => (string) ($nextY + 1),
                'start_date' => date('Y-m-d', strtotime('+1 day', $endTs)),
                'end_date'   => ($nextY + 1) . '-12-31',
            ];
            // If the current year already runs to a calendar year end, snap to
            // clean calendar boundaries instead.
            if (date('m-d', $endTs) === '12-31') {
                $suggest['start_date'] = ($nextY + 1) . '-01-01';
            }

            echo json_encode([
                'status'        => 'success',
                'from_year'     => [
                    'id'      => $from_id,
                    'label'   => $from_year['label'],
                    'heading' => dept_year_heading($from_year),
                    'period'  => dept_period_text($from_year),
                ],
                'suggested'     => $suggest,
                'departments'   => $units,
                'people'        => $people,
                'candidates'    => $candidates,
            ]);
            break;

        // =====================================================================
        // ACTION 10: COMMIT ROLLOVER (create the year, install the worksheet)
        // =====================================================================
        case 'commit_rollover':
            if (!$can_roll) {
                dept_fail('Only a Super Admin or the Resident Pastor can start a new ministry year.');
            }

            $label = trim($_POST['label'] ?? '');
            $start = trim($_POST['start_date'] ?? '');
            $end   = trim($_POST['end_date'] ?? '');
            $mode  = ($_POST['mode'] ?? 'carry') === 'empty' ? 'empty' : 'carry';

            if ($label === '' || !strtotime($start) || !strtotime($end)) {
                dept_fail('A year label and both dates are required.');
            }
            if (strtotime($start) >= strtotime($end)) {
                dept_fail('The end date must be after the start date.');
            }

            // assignments: [{user_id, department_id, membership_type, role_in_dept}]
            $assignments = json_decode((string) ($_POST['assignments'] ?? '[]'), true);
            if (!is_array($assignments)) {
                dept_fail('The worksheet could not be read. Please reload and try again.');
            }

            $previous = dept_active_year($pdo);
            if ($previous) {
                dept_require_live_year($previous);
            }

            $pdo->beginTransaction();

            // 1. Close the old year and open the new one.
            if ($previous) {
                $pdo->prepare("UPDATE ministry_years SET is_active = 0, closed_at = NOW() WHERE id = ?")
                    ->execute([$previous['id']]);
                // Serving-now clearance (is_active) only ever belongs to the
                // live year, so everything historical stands down at once.
                $pdo->prepare("UPDATE user_departments SET is_active = 0 WHERE ministry_year_id = ?")
                    ->execute([$previous['id']]);
            }

            $pdo->prepare("INSERT INTO ministry_years (label, start_date, end_date, is_active, created_by) VALUES (?, ?, ?, 1, ?)")
                ->execute([$label, date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end)), $user_id]);
            $new_year_id = (int) $pdo->lastInsertId();
            $ctx = ['year_id' => $new_year_id, 'year_label' => 'Ministry Year ' . $label, 'season' => (int) date('Y', strtotime($start))];

            // 2. Install the worksheet.
            $installed = 0;
            $leaders    = 0;
            $seen = [];
            foreach ($assignments as $a) {
                $person  = (int) ($a['user_id'] ?? 0);
                $dept_id = (int) ($a['department_id'] ?? 0);
                if (!$person || !$dept_id) {
                    continue;
                }
                if (isset($seen[$person . ':' . $dept_id])) {
                    continue;                     // one row per person per department
                }
                $seen[$person . ':' . $dept_id] = true;

                $role = $a['role_in_dept'] ?? 'Member';
                if (!in_array($role, ['Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head', 'Worker', 'Member'], true)) {
                    $role = 'Member';
                }
                $membership = in_array($role, DEPT_LEADER_ROLES, true)
                    ? 'Primary'
                    : dept_clean_membership($a['membership_type'] ?? 'Primary');

                dept_put_roster_row($pdo, $ctx, $person, $dept_id, $role, $membership, true);
                $installed++;
                if (in_array($role, DEPT_LEADER_ROLES, true)) {
                    $leaders++;
                    syncGlobalRole($pdo, $person, $role);
                }
            }

            $pdo->commit();

            echo json_encode([
                'status'  => 'success',
                'message' => "Ministry Year {$label} is now live: {$installed} roster entries installed"
                    . ($leaders ? ", including {$leaders} leadership appointment(s)" : '')
                    . ". The previous year is archived and read-only.",
                'data'    => ['ministry_year_id' => $new_year_id, 'entries' => $installed, 'leaders' => $leaders, 'mode' => $mode],
            ]);
            break;

        // =====================================================================
        // ACTION 11: EXPORT DATASET (A4 image renderer + Excel share this)
        // =====================================================================
        case 'fetch_export_dataset':
            $year = !empty($_POST['ministry_year_id'])
                ? dept_year_by_id($pdo, (int) $_POST['ministry_year_id'])
                : dept_active_year($pdo);
            if (!$year) {
                dept_fail('No ministry year configured.');
            }

            echo json_encode([
                'status' => 'success',
                'data'   => dept_report_dataset($pdo, $year),
            ]);
            break;

        default:
            dept_fail('Unknown action.');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Department API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Please check the system logs.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Department API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
