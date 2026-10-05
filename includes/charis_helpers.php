<?php
// /includes/charis_helpers.php
// Shared access control, welfare-note visibility, and validation helpers for
// the Charis page, API, and AWOL report.

require_once __DIR__ . '/assimilation_helpers.php';

const CHARIS_ATTENDANCE_STATUSES = [
    'New',
    'Active',
    'Inconsistent',
    'Unknown',
    'Relocated',
    'Attends_Another_Church',
];

const CHARIS_CANONICAL_SERVICE_TYPES = [
    'Sunday_Service',
    'Midweek_Service',
];

const CHARIS_CANONICAL_SPIRITUAL_STATUSES = [
    'Member',
    'Worker',
    'Pastor',
    '1st_Timer',
    '2nd_Timer',
    '3rd_Timer',
    'Visitor',
    'Non_Member',
    'Unspecified',
];

const CHARIS_EXCLUDED_ATTENDANCE_STATUSES = [
    'Unknown',
    'Relocated',
    'Attends Another Church',
    'Attends_Another_Church',
];

const CHARIS_AWOL_DEFAULT_SERVICES_MISSED = 2;
const CHARIS_AWOL_DEFAULT_PERIOD_WEEKS = 5;

const CHARIS_AWOL_CANONICAL_SERVICES = [
    'Sunday_Service'  => 'Sunday Service',
    'Midweek_Service' => 'Thursday Midweek Service',
];

const CHARIS_AWOL_CANONICAL_STATUSES = [
    'Member'     => 'Member',
    'Worker'     => 'Worker',
    'Pastor'     => 'Pastor',
    '1st_Timer'  => '1st Timer',
    '2nd_Timer'  => '2nd Timer',
    '3rd_Timer'  => '3rd Timer',
    'Visitor'    => 'Visitor',
    'Non_Member' => 'Non-Member',
    'Unspecified'=> 'Unspecified',
];

const CHARIS_AWOL_DEFAULT_STATUSES = ['Member', 'Worker', 'Pastor'];

const CHARIS_AWOL_EXCLUDED_ATTENDANCE_STATUSES = [
    'Unknown',
    'Relocated',
    'Attends Another Church',
    'Attends_Another_Church',
];

/** @return string[] */
function charis_session_role_names(?array $roles = null): array
{
    $roles = $roles ?? ($_SESSION['roles'] ?? []);
    $names = [];
    foreach ($roles as $role) {
        $name = is_array($role) ? ($role['role_name'] ?? '') : (string) $role;
        if ($name !== '') $names[] = $name;
    }
    return array_values(array_unique($names));
}

/**
 * Return true if the user is authorized to edit the shared AWOL configuration:
 * Super Admins, and active Directors or HODs in IDI/Charis/Welfare departments.
 */
function charis_can_configure_awol(PDO $pdo, $userOrContext, ?array $roles = null): bool
{
    if (is_array($userOrContext)) {
        if (!empty($userOrContext['is_super_admin'])) {
            return true;
        }
        if (isset($userOrContext['can_configure_awol'])) {
            return (bool) $userOrContext['can_configure_awol'];
        }
        $userId = (int) ($userOrContext['user_id'] ?? $userOrContext['id'] ?? 0);
    } else {
        $userId = (int) $userOrContext;
    }

    $roleNames = charis_session_role_names($roles);
    if (in_array('Super_Admin', $roleNames, true)) {
        return true;
    }
    if ($userId <= 0) return false;

    $stmt = $pdo->prepare("
        SELECT ud.role_in_dept
          FROM user_departments ud
          JOIN departments d ON d.id = ud.department_id
         WHERE ud.user_id = ?
           AND ud.is_active = 1
           AND (
                ud.department_id IN (1, 10)
                OR d.name LIKE '%Charis%'
                OR d.name LIKE '%Welfare%'
                OR d.name = 'IDI'
           )
           AND ud.role_in_dept IN ('Director', 'HOD')
         LIMIT 1
    ");
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Return the one access decision used by the page, API, and PDF.
 *
 * The sidebar exposes Charis to pastors and active members of departments 1
 * (IDI) and 10 (Charis). Name matching keeps access intact if those department
 * IDs differ on an older installation. A Director/HOD badge only grants Charis
 * management rights when it belongs to one of those departments; leadership
 * in an unrelated department must not expose confidential welfare records.
 *
 * @return array{can_access:bool,is_manager:bool,is_pastor:bool,is_super_admin:bool,is_team_member:bool,can_configure_awol:bool}
 */
function charis_access_context(PDO $pdo, int $userId, ?array $roles = null): array
{
    $roleNames = charis_session_role_names($roles);
    $isSuper   = in_array('Super_Admin', $roleNames, true);
    $isPastor  = in_array('Resident_Pastor', $roleNames, true)
        || in_array('Assoc_Pastor', $roleNames, true);

    $isTeamMember = false;
    $isTeamManager = false;
    if ($userId > 0) {
        $stmt = $pdo->prepare("
            SELECT ud.role_in_dept
              FROM user_departments ud
              JOIN departments d ON d.id = ud.department_id
             WHERE ud.user_id = ?
               AND ud.is_active = 1
               AND (
                    ud.department_id IN (1, 10)
                    OR d.name LIKE '%Charis%'
                    OR d.name LIKE '%Welfare%'
                    OR d.name = 'IDI'
               )
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $departmentRole) {
            $isTeamMember = true;
            if (in_array($departmentRole, ['Director', 'HOD'], true)) {
                $isTeamManager = true;
            }
        }
    }

    return [
        'can_access'         => $isSuper || $isPastor || $isTeamMember,
        'is_manager'         => $isSuper || $isPastor || $isTeamManager,
        'is_pastor'          => $isPastor,
        'is_super_admin'     => $isSuper,
        'is_team_member'     => $isTeamMember,
        'can_configure_awol' => $isSuper || $isTeamManager,
    ];
}

function charis_is_team_worker(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) return false;
    $stmt = $pdo->prepare("
        SELECT 1
          FROM user_departments ud
          JOIN departments d ON d.id = ud.department_id
         WHERE ud.user_id = ?
           AND ud.is_active = 1
           AND (
                ud.department_id IN (1, 10)
                OR d.name LIKE '%Charis%'
                OR d.name LIKE '%Welfare%'
                OR d.name = 'IDI'
           )
         LIMIT 1
    ");
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

function charis_welfare_assignment_access(
    PDO $pdo,
    int $currentUserId,
    int $targetUserId,
    int $followupId,
    bool $isManager
): bool {
    if ($currentUserId <= 0 || $targetUserId <= 0 || $followupId < 0) return false;

    $stmt = $pdo->prepare("
        SELECT worker_id
          FROM charis_welfare_assignments
         WHERE target_user_id = ?
           AND followup_id = ?
           AND status = 'Assigned'
         LIMIT 1
    ");
    $stmt->execute([$targetUserId, $followupId]);
    $workerId = $stmt->fetchColumn();
    if ($workerId === false) return false;
    return $isManager || (int) $workerId === $currentUserId;
}

/**
 * Notes are visible to their author and to audiences listed in visible_to.
 * The UI currently creates either All or Pastors notes, but the wider audience
 * vocabulary remains supported for records created by earlier versions.
 *
 * @return array<int,array<string,mixed>>
 */
function charis_secure_welfare_notes(
    PDO $pdo,
    int $targetUserId,
    int $currentUserId,
    array $access,
    ?string $createdOnOrBefore = null
): array {
    try {
        $noteDateWhere = $createdOnOrBefore !== null ? ' AND n.created_at <= ?' : '';
        $noteParams = [$targetUserId];
        if ($createdOnOrBefore !== null) $noteParams[] = $createdOnOrBefore;

        $stmt = $pdo->prepare("
            SELECT n.*, u.first_name, u.last_name
              FROM charis_welfare_notes n
              JOIN users u ON u.id = n.author_id
             WHERE n.target_user_id = ? {$noteDateWhere}
             ORDER BY n.created_at ASC, n.id ASC
        ");
        $stmt->execute($noteParams);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // The deploy copies PHP before it runs migrations. Keep dashboard reads
        // alive during that brief window; writes still return a clear API error.
        error_log('Charis welfare notes unavailable: ' . $e->getMessage());
        return [];
    }
    if (!$notes) return [];

    $assignedStmt = $pdo->prepare("
        SELECT 1
          FROM charis_welfare_assignments
         WHERE target_user_id = ? AND worker_id = ? AND status = 'Assigned'
         LIMIT 1
    ");
    $assignedStmt->execute([$targetUserId, $currentUserId]);
    $isAssignedWorker = (bool) $assignedStmt->fetchColumn();

    return array_values(array_filter($notes, static function (array $note) use (
        $currentUserId,
        $access,
        $isAssignedWorker
    ): bool {
        if ((int) $note['author_id'] === $currentUserId) return true;

        $visibility = json_decode((string) ($note['visible_to'] ?? ''), true);
        if (!is_array($visibility) || !$visibility) $visibility = ['All'];

        if (in_array('All', $visibility, true)) return true;
        if (in_array('Pastors', $visibility, true)
            && ($access['is_pastor'] || $access['is_super_admin'])) return true;
        if (in_array('Directors', $visibility, true) && $access['is_manager']) return true;
        if (in_array('Assigned_Worker', $visibility, true) && $isAssignedWorker) return true;
        return false;
    }));
}

function charis_insert_welfare_note(
    PDO $pdo,
    int $targetUserId,
    int $authorId,
    string $noteText,
    array $visibleTo = ['All']
): int {
    $noteText = trim($noteText);
    if ($noteText === '') return 0;
    if ((function_exists('mb_strlen') ? mb_strlen($noteText) : strlen($noteText)) > 5000) {
        throw new InvalidArgumentException('Welfare notes cannot exceed 5,000 characters.');
    }

    $allowed = ['All', 'Pastors', 'Directors', 'Assigned_Worker'];
    $visibleTo = array_values(array_intersect($allowed, $visibleTo));
    if (!$visibleTo) $visibleTo = ['All'];

    $stmt = $pdo->prepare("
        INSERT INTO charis_welfare_notes (target_user_id, author_id, note_text, visible_to)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$targetUserId, $authorId, $noteText, json_encode($visibleTo)]);
    return (int) $pdo->lastInsertId();
}

function charis_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date) return null;
    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
    return $date->format('Y-m-d') === $value ? $date : null;
}

/**
 * Fetch the shared AWOL configuration. Returns null if unconfigured.
 */
function charis_get_awol_config(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->query("SELECT * FROM charis_awol_config WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $serviceTypes = json_decode((string) $row['service_types'], true);
        if (!is_array($serviceTypes) || empty($serviceTypes)) {
            $serviceTypes = array_keys(CHARIS_AWOL_CANONICAL_SERVICES);
        }
        $spiritualStatuses = json_decode((string) $row['spiritual_statuses'], true);
        if (!is_array($spiritualStatuses) || empty($spiritualStatuses)) {
            $spiritualStatuses = CHARIS_AWOL_DEFAULT_STATUSES;
        }

        return [
            'id'                 => (int) $row['id'],
            'services_missed'    => (int) $row['missed_threshold'],
            'missed_threshold'   => (int) $row['missed_threshold'],
            'period_weeks'       => (int) $row['period_weeks'],
            'service_types'      => array_values($serviceTypes),
            'spiritual_statuses' => array_values($spiritualStatuses),
            'updated_by'         => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_at'         => $row['updated_at'],
        ];
    } catch (PDOException $e) {
        error_log('Charis AWOL config unavailable: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch the historical AWOL configuration active as of a given timestamp.
 */
function charis_get_historical_awol_config(PDO $pdo, string $asOfDateTime): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT *
              FROM charis_awol_config_history
             WHERE created_at <= ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1
        ");
        $stmt->execute([$asOfDateTime]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $serviceTypes = json_decode((string) $row['service_types'], true);
            $spiritualStatuses = json_decode((string) $row['spiritual_statuses'], true);
            return [
                'services_missed'    => (int) $row['missed_threshold'],
                'missed_threshold'   => (int) $row['missed_threshold'],
                'period_weeks'       => (int) $row['period_weeks'],
                'service_types'      => is_array($serviceTypes) ? array_values($serviceTypes) : array_keys(CHARIS_AWOL_CANONICAL_SERVICES),
                'spiritual_statuses' => is_array($spiritualStatuses) ? array_values($spiritualStatuses) : CHARIS_AWOL_DEFAULT_STATUSES,
                'updated_by'         => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
                'updated_at'         => $row['created_at'],
            ];
        }
    } catch (Throwable $e) {
        // Fallback to active config if history is unavailable
    }
    return charis_get_awol_config($pdo);
}

/**
 * Persist the shared AWOL configuration and append an auditable history record.
 */
function charis_save_awol_config(
    PDO $pdo,
    int $servicesMissed,
    int $periodWeeks,
    array $serviceTypes,
    array $spiritualStatuses,
    int $updatedBy
): array {
    if ($servicesMissed <= 0 || $servicesMissed > 52) {
        throw new InvalidArgumentException('Services missed must be a positive integer between 1 and 52.');
    }
    if ($periodWeeks <= 0 || $periodWeeks > 104) {
        throw new InvalidArgumentException('Period of focus must be a positive integer between 1 and 104 weeks.');
    }

    $validServiceKeys = array_keys(CHARIS_AWOL_CANONICAL_SERVICES);
    $filteredServices = array_values(array_intersect($validServiceKeys, $serviceTypes));
    if (empty($filteredServices)) {
        throw new InvalidArgumentException('At least one valid service type must be selected.');
    }

    $validStatusKeys = array_keys(CHARIS_AWOL_CANONICAL_STATUSES);
    $filteredStatuses = array_values(array_intersect($validStatusKeys, $spiritualStatuses));
    if (empty($filteredStatuses)) {
        throw new InvalidArgumentException('At least one valid spiritual status must be selected.');
    }

    $servicesJson = json_encode($filteredServices);
    $statusesJson = json_encode($filteredStatuses);

    $stmt = $pdo->prepare("
        INSERT INTO charis_awol_config (id, services_missed, missed_threshold, period_weeks, service_types, spiritual_statuses, updated_by, updated_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            services_missed    = VALUES(services_missed),
            missed_threshold   = VALUES(missed_threshold),
            period_weeks       = VALUES(period_weeks),
            service_types      = VALUES(service_types),
            spiritual_statuses = VALUES(spiritual_statuses),
            updated_by         = VALUES(updated_by),
            updated_at         = NOW()
    ");
    $stmt->execute([$servicesMissed, $servicesMissed, $periodWeeks, $servicesJson, $statusesJson, $updatedBy]);

    try {
        $histStmt = $pdo->prepare("
            INSERT INTO charis_awol_config_history (config_id, services_missed, missed_threshold, period_weeks, service_types, spiritual_statuses, updated_by, created_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $histStmt->execute([$servicesMissed, $servicesMissed, $periodWeeks, $servicesJson, $statusesJson, $updatedBy]);
    } catch (Throwable $e) {
        error_log('Charis AWOL config history write failed: ' . $e->getMessage());
    }

    return [
        'id'                 => 1,
        'services_missed'    => $servicesMissed,
        'missed_threshold'   => $servicesMissed,
        'period_weeks'       => $periodWeeks,
        'service_types'      => $filteredServices,
        'spiritual_statuses' => $filteredStatuses,
        'updated_by'         => $updatedBy,
        'updated_at'         => date('Y-m-d H:i:s'),
    ];
}

/**
 * Compute the active AWOL list based on the shared configuration and Lagos timezone.
 */
function charis_compute_awol_list(
    PDO $pdo,
    array $config,
    ?DateTimeImmutable $asOf = null
): array {
    $now = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
    $periodWeeks = (int) ($config['period_weeks'] ?? CHARIS_AWOL_DEFAULT_PERIOD_WEEKS);
    $missedThreshold = (int) ($config['missed_threshold'] ?? $config['services_missed'] ?? CHARIS_AWOL_DEFAULT_SERVICES_MISSED);
    $serviceTypes = (array) ($config['service_types'] ?? array_keys(CHARIS_AWOL_CANONICAL_SERVICES));
    $spiritualStatuses = (array) ($config['spiritual_statuses'] ?? CHARIS_AWOL_DEFAULT_STATUSES);

    if (empty($serviceTypes) || empty($spiritualStatuses) || $missedThreshold <= 0 || $periodWeeks <= 0) {
        return [
            'qualifying_services'       => [],
            'total_qualifying_services' => 0,
            'period_weeks'              => $periodWeeks,
            'missed_threshold'          => $missedThreshold,
            'service_types'             => $serviceTypes,
            'spiritual_statuses'        => $spiritualStatuses,
            'awol_checks'               => [],
            'has_services'              => false,
        ];
    }

    $nowStr = $now->format('Y-m-d H:i:s');
    $windowStart = $now->modify("-{$periodWeeks} weeks");
    $windowStartStr = $windowStart->format('Y-m-d H:i:s');
    $todayDate = $now->format('Y-m-d');

    // 1. Find completed qualifying service occurrences for selected types in rolling window
    $typePlaceholders = implode(',', array_fill(0, count($serviceTypes), '?'));
    $eventParams = array_merge($serviceTypes, [$windowStartStr, $nowStr]);

    $eventStmt = $pdo->prepare("
        SELECT event_category, DATE(event_date) AS service_date, MIN(event_date) AS event_date
          FROM events
         WHERE event_category IN ({$typePlaceholders})
           AND event_date >= ?
           AND event_date <= ?
         GROUP BY event_category, DATE(event_date)
         ORDER BY service_date ASC
    ");
    $eventStmt->execute($eventParams);
    $qualifyingServices = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalQualifyingCount = count($qualifyingServices);
    if ($totalQualifyingCount === 0) {
        return [
            'qualifying_services'       => [],
            'total_qualifying_services' => 0,
            'period_weeks'              => $periodWeeks,
            'missed_threshold'          => $missedThreshold,
            'service_types'             => $serviceTypes,
            'spiritual_statuses'        => $spiritualStatuses,
            'awol_checks'               => [],
            'has_services'              => false,
        ];
    }

    $qualifyingDates = array_values(array_unique(array_column($qualifyingServices, 'service_date')));
    $datePlaceholders = implode(',', array_fill(0, count($qualifyingDates), '?'));

    // 2. Fetch eligible people matching spiritual statuses and attendance status exclusions
    $statusPlaceholders = implode(',', array_fill(0, count($spiritualStatuses), '?'));
    $userStmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.phone, u.physical_address, u.email,
               u.spiritual_status, u.attendance_status, u.picture_path,
               r.name AS region_name
          FROM users u
          LEFT JOIN regions r ON u.region_id = r.id
         WHERE u.spiritual_status IN ({$statusPlaceholders})
           AND COALESCE(u.attendance_status, '') NOT IN ('Relocated', 'Attends_Another_Church', 'Attends Another Church', 'Unknown')
         ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $userStmt->execute($spiritualStatuses);
    $eligibleUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($eligibleUsers)) {
        return [
            'qualifying_services'       => $qualifyingServices,
            'total_qualifying_services' => $totalQualifyingCount,
            'period_weeks'              => $periodWeeks,
            'missed_threshold'          => $missedThreshold,
            'service_types'             => $serviceTypes,
            'spiritual_statuses'        => $spiritualStatuses,
            'awol_checks'               => [],
            'has_services'              => true,
        ];
    }

    $attendanceUnion = assim_attendance_union_sql();

    // 3. User attendance on qualifying dates - deduplicating same-day multiple attendance records
    $attStmt = $pdo->prepare("
        SELECT attended.user_id, attended.attended_on, COUNT(DISTINCT attended.attended_on) AS distinct_days
          FROM {$attendanceUnion} attended
         WHERE attended.attended_on IN ({$datePlaceholders})
         GROUP BY attended.user_id, attended.attended_on
    ");
    $attStmt->execute($qualifyingDates);
    $userAttendedMap = [];
    while ($row = $attStmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $row['user_id'];
        $userAttendedMap[$uid][$row['attended_on']] = true;
    }

    // 4. Latest attended service date overall (up to now)
    $latestAttStmt = $pdo->prepare("
        SELECT attended.user_id, MAX(attended.attended_on) AS last_attended
          FROM {$attendanceUnion} attended
         WHERE attended.attended_on <= ?
         GROUP BY attended.user_id
    ");
    $latestAttStmt->execute([$todayDate]);
    $latestAttendedMap = [];
    while ($row = $latestAttStmt->fetch(PDO::FETCH_ASSOC)) {
        $latestAttendedMap[(int) $row['user_id']] = $row['last_attended'];
    }

    // 5. Active & resolved assignments in charis_welfare_assignments (followup_id = 0)
    $hasResolvedAt = charis_column_exists($pdo, 'charis_welfare_assignments', 'resolved_at');
    $resolvedCol = $hasResolvedAt ? 'cwa.resolved_at' : 'NULL';
    $asgStmt = $pdo->query("
        SELECT cwa.id AS assignment_id, cwa.target_user_id, cwa.worker_id, cwa.status AS assignment_status,
               {$resolvedCol} AS resolved_at, cwa.created_at,
               w.first_name AS worker_fname, w.last_name AS worker_lname, w.phone AS worker_phone
          FROM charis_welfare_assignments cwa
          LEFT JOIN users w ON cwa.worker_id = w.id
         WHERE cwa.followup_id = 0
         ORDER BY cwa.id DESC
    ");
    $assignmentsByUser = [];
    while ($row = $asgStmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $row['target_user_id'];
        if (!isset($assignmentsByUser[$uid])) {
            $assignmentsByUser[$uid] = [];
        }
        $assignmentsByUser[$uid][] = $row;
    }

    // 6. Evaluate each eligible user
    $awolResults = [];

    foreach ($eligibleUsers as $user) {
        $uid = (int) $user['id'];
        $attendedDates = $userAttendedMap[$uid] ?? [];
        $attendedCount = count($attendedDates);
        $missedTotal = $totalQualifyingCount - $attendedCount;

        $userAssignments = $assignmentsByUser[$uid] ?? [];
        $activeAssignment = null;
        $resolvedAssignment = null;

        foreach ($userAssignments as $asg) {
            if ($asg['assignment_status'] === 'Resolved') {
                if ($resolvedAssignment === null) {
                    $resolvedAssignment = $asg;
                }
            } elseif ($activeAssignment === null) {
                $activeAssignment = $asg;
            }
        }

        // Resolution suppression / reappearance:
        if ($resolvedAssignment !== null && $activeAssignment === null) {
            $resolvedTimestamp = $resolvedAssignment['resolved_at'] ?? $resolvedAssignment['created_at'];
            if ($resolvedTimestamp) {
                $resolvedDate = substr($resolvedTimestamp, 0, 10);
                $qualifyingAfter = 0;
                $attendedAfter = 0;
                foreach ($qualifyingServices as $qs) {
                    if ($qs['service_date'] > $resolvedDate || $qs['event_date'] > $resolvedTimestamp) {
                        $qualifyingAfter++;
                        if (isset($attendedDates[$qs['service_date']])) {
                            $attendedAfter++;
                        }
                    }
                }
                $missedAfter = $qualifyingAfter - $attendedAfter;
                if ($missedAfter < $missedThreshold) {
                    continue; // Suppress: member has not missed >= threshold services since resolution
                }
            }
        }

        if ($missedTotal >= $missedThreshold) {
            $lastAttended = $latestAttendedMap[$uid] ?? null;

            $entry = [
                'followup_id'               => 0,
                'alert_type'                => 'AWOL',
                'target_user_id'            => $uid,
                'first_name'                => $user['first_name'],
                'last_name'                 => $user['last_name'],
                'phone'                     => $user['phone'],
                'physical_address'          => $user['physical_address'],
                'email'                     => $user['email'],
                'spiritual_status'          => $user['spiritual_status'],
                'attendance_status'         => $user['attendance_status'],
                'picture_path'              => $user['picture_path'],
                'region_name'               => $user['region_name'],
                'services_missed'           => $missedTotal,
                'missed_count'              => $missedTotal,
                'total_qualifying_services' => $totalQualifyingCount,
                'attended_count'            => $attendedCount,
                'period_weeks'              => $periodWeeks,
                'last_attended_date'        => $lastAttended,
                'assignment_id'             => $activeAssignment ? (int) $activeAssignment['assignment_id'] : null,
                'worker_id'                 => $activeAssignment ? ($activeAssignment['worker_id'] ? (int) $activeAssignment['worker_id'] : null) : null,
                'assignment_status'         => $activeAssignment ? $activeAssignment['assignment_status'] : null,
                'worker_fname'              => $activeAssignment ? $activeAssignment['worker_fname'] : null,
                'worker_lname'              => $activeAssignment ? $activeAssignment['worker_lname'] : null,
                'worker_phone'              => $activeAssignment ? $activeAssignment['worker_phone'] : null,
            ];
            $awolResults[] = $entry;
        }
    }

    return [
        'qualifying_services'       => $qualifyingServices,
        'total_qualifying_services' => $totalQualifyingCount,
        'period_weeks'              => $periodWeeks,
        'missed_threshold'          => $missedThreshold,
        'service_types'             => $serviceTypes,
        'spiritual_statuses'        => $spiritualStatuses,
        'awol_checks'               => $awolResults,
        'has_services'              => true,
    ];
}
