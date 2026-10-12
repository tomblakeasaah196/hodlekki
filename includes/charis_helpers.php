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

// Consecutive qualifying absences before a member is flagged.
const CHARIS_AWOL_DEFAULT_CONSECUTIVE = 3;

// Unbroken weeks away before a flagged member drops out of the active list
// into Dormant. Deliberately separate from the legacy rolling-window weeks:
// reusing that value (live setting: 3) would archive people almost at once.
const CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS = 12;

// How many recent services the card's attendance strip shows. Capping it is
// what keeps a thirty-week absence rendering as compactly as a three-week one.
const CHARIS_AWOL_STRIP_LENGTH = 8;

// Sunday absence flags; midweek absence never does, but midweek presence still
// clears the counter. See charis_awol_service_rules().
const CHARIS_AWOL_DEFAULT_SERVICE_RULES = [
    'Sunday_Service'  => ['counts_absence' => true,  'resets_clock' => true],
    'Midweek_Service' => ['counts_absence' => false, 'resets_clock' => true],
];

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

/**
 * Events are stored with either underscore ('Sunday_Service') or spaced
 * ('Sunday Service') categories depending on where they were created. AWOL
 * detection must recognise both spellings so recent Sunday and Thursday
 * midweek services are never missed in the rolling focus period.
 *
 * @param string[] $serviceTypes canonical service type keys
 * @return string[] every accepted event_category spelling, de-duplicated
 */
function charis_awol_event_category_aliases(array $serviceTypes): array
{
    $aliases = [];
    foreach ($serviceTypes as $type) {
        $type = trim((string) $type);
        if ($type === '') continue;
        $underscored = str_replace(' ', '_', $type);
        $spaced      = str_replace('_', ' ', $type);
        foreach ([$type, $underscored, $spaced] as $variant) {
            $aliases[$variant] = true;
        }
    }
    return array_values(array_keys($aliases));
}

/**
 * Normalise an event_category back to its canonical underscore key.
 */
function charis_awol_canonical_service_key(string $eventCategory): string
{
    return str_replace(' ', '_', trim($eventCategory));
}

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

        // LEFT JOIN: system-generated notes (auto-resolution on attendance)
        // carry no author_id, and an inner join would hide them entirely.
        $stmt = $pdo->prepare("
            SELECT n.*, u.first_name, u.last_name
              FROM charis_welfare_notes n
              LEFT JOIN users u ON u.id = n.author_id
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

    $visible = array_values(array_filter($notes, static function (array $note) use (
        $currentUserId,
        $access,
        $isAssignedWorker
    ): bool {
        // System notes are part of the shared record: everyone who can see the
        // case can see why it closed itself. The explicit flag is what marks
        // one -- a null author_id is NOT enough, because production's foreign
        // key may null the author when a user is deleted, and that must never
        // quietly widen a private note's audience. Such a note falls through
        // to the visible_to rules below, exactly as it did before.
        if (!empty($note['is_system'])) return true;
        if ($note['author_id'] !== null && (int) $note['author_id'] === $currentUserId) return true;

        $visibility = json_decode((string) ($note['visible_to'] ?? ''), true);
        if (!is_array($visibility) || !$visibility) $visibility = ['All'];

        if (in_array('All', $visibility, true)) return true;
        if (in_array('Pastors', $visibility, true)
            && ($access['is_pastor'] || $access['is_super_admin'])) return true;
        if (in_array('Directors', $visibility, true) && $access['is_manager']) return true;
        if (in_array('Assigned_Worker', $visibility, true) && $isAssignedWorker) return true;
        return false;
    }));

    // Who wrote it, in a form that survives the author leaving. The live user
    // row is preferred; author_name is the snapshot taken when the note was
    // written, and it is all that remains once the account is deleted and the
    // foreign key nulls author_id. A welfare record must still say who wrote
    // it years later -- that is the whole point of the archive.
    foreach ($visible as &$note) {
        $liveName = trim((string) ($note['first_name'] ?? '') . ' ' . (string) ($note['last_name'] ?? ''));
        if (!empty($note['is_system'])) {
            $note['author_display_name'] = 'System';
        } elseif ($liveName !== '') {
            $note['author_display_name'] = $liveName;
        } elseif (!empty($note['author_name'])) {
            $note['author_display_name'] = (string) $note['author_name'] . ' (no longer on the roll)';
        } else {
            $note['author_display_name'] = 'Unknown author';
        }
    }
    unset($note);

    return $visible;
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

    // Snapshot the author's name now. The foreign key nulls author_id if the
    // account is ever removed, and without this the note would lose its author.
    $authorName = null;
    try {
        $nameStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $nameStmt->execute([$authorId]);
        if ($row = $nameStmt->fetch(PDO::FETCH_ASSOC)) {
            $authorName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            if ($authorName === '') $authorName = null;
        }
    } catch (PDOException $e) {
        error_log('Charis welfare note author lookup failed: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO charis_welfare_notes (target_user_id, author_id, author_name, note_text, visible_to)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$targetUserId, $authorId, $authorName, $noteText, json_encode($visibleTo)]);
    } catch (PDOException $e) {
        // author_name arrives with the streak migration; a tree deployed ahead
        // of it still has to be able to save a note.
        error_log('Charis welfare note author_name unavailable: ' . $e->getMessage());
        $stmt = $pdo->prepare("
            INSERT INTO charis_welfare_notes (target_user_id, author_id, note_text, visible_to)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$targetUserId, $authorId, $noteText, json_encode($visibleTo)]);
    }
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

        // The streak columns arrive with the rebuild migration. Until it runs,
        // fall back so a half-deployed tree keeps serving a sane rule.
        $consecutive = isset($row['consecutive_threshold'])
            ? (int) $row['consecutive_threshold']
            : (int) $row['missed_threshold'];
        $archiveWeeks = isset($row['archive_weeks'])
            ? (int) $row['archive_weeks']
            : CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS;
        $serviceRules = charis_awol_service_rules(['service_rules' => $row['service_rules'] ?? null]);

        return [
            'id'                    => (int) $row['id'],
            'services_missed'       => $consecutive,
            'missed_threshold'      => $consecutive,
            'consecutive_threshold' => $consecutive,
            'period_weeks'          => (int) $row['period_weeks'],
            'archive_weeks'         => $archiveWeeks,
            'service_types'         => array_values($serviceTypes),
            'service_rules'         => $serviceRules,
            'spiritual_statuses'    => array_values($spiritualStatuses),
            'updated_by'            => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_at'            => $row['updated_at'],
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
            $consecutive = isset($row['consecutive_threshold'])
                ? (int) $row['consecutive_threshold']
                : (int) $row['missed_threshold'];
            return [
                'services_missed'       => $consecutive,
                'missed_threshold'      => $consecutive,
                'consecutive_threshold' => $consecutive,
                'period_weeks'          => (int) $row['period_weeks'],
                'archive_weeks'         => isset($row['archive_weeks'])
                    ? (int) $row['archive_weeks']
                    : CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS,
                'service_types'         => is_array($serviceTypes) ? array_values($serviceTypes) : array_keys(CHARIS_AWOL_CANONICAL_SERVICES),
                'service_rules'         => charis_awol_service_rules(['service_rules' => $row['service_rules'] ?? null]),
                'spiritual_statuses'    => is_array($spiritualStatuses) ? array_values($spiritualStatuses) : CHARIS_AWOL_DEFAULT_STATUSES,
                'updated_by'            => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
                'updated_at'            => $row['created_at'],
            ];
        }
    } catch (Throwable $e) {
        // Fallback to active config if history is unavailable
    }
    return charis_get_awol_config($pdo);
}

/**
 * Persist the shared AWOL configuration and append an auditable history record.
 *
 * $serviceRules carries the per-service grid the editor now renders:
 * each canonical service independently decides whether its absence advances
 * the streak and whether its attendance clears it. $serviceTypes is still
 * written so an older tree reading the legacy column keeps working.
 */
function charis_save_awol_config(
    PDO $pdo,
    int $servicesMissed,
    int $periodWeeks,
    array $serviceTypes,
    array $spiritualStatuses,
    int $updatedBy,
    ?string $changeReason = null,
    ?array $serviceRules = null,
    ?int $archiveWeeks = null
): array {
    if ($servicesMissed <= 0 || $servicesMissed > 52) {
        throw new InvalidArgumentException('Consecutive absences must be a positive integer between 1 and 52.');
    }
    if ($periodWeeks <= 0 || $periodWeeks > 104) {
        throw new InvalidArgumentException('Period of focus must be a positive integer between 1 and 104 weeks.');
    }
    $archiveWeeks = $archiveWeeks ?? CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS;
    if ($archiveWeeks <= 0 || $archiveWeeks > 260) {
        throw new InvalidArgumentException('Archive horizon must be a positive integer between 1 and 260 weeks.');
    }

    $rules = charis_awol_service_rules(['service_rules' => $serviceRules]);
    if (!charis_awol_absence_services($rules)) {
        throw new InvalidArgumentException('At least one service type must count towards absence.');
    }

    // Keep the legacy list consistent with the grid so an older tree reading
    // service_types still sees the services that actually drive the rule.
    $validServiceKeys = array_keys(CHARIS_AWOL_CANONICAL_SERVICES);
    $filteredServices = array_values(array_intersect($validServiceKeys, $serviceTypes));
    if (!$filteredServices) {
        $filteredServices = charis_awol_absence_services($rules);
    }

    $validStatusKeys = array_keys(CHARIS_AWOL_CANONICAL_STATUSES);
    $filteredStatuses = array_values(array_intersect($validStatusKeys, $spiritualStatuses));
    if (empty($filteredStatuses)) {
        throw new InvalidArgumentException('At least one valid spiritual status must be selected.');
    }

    $servicesJson = json_encode($filteredServices);
    $statusesJson = json_encode($filteredStatuses);
    $rulesJson    = json_encode($rules);

    $changeReason = $changeReason !== null ? trim($changeReason) : null;
    if ($changeReason === '') $changeReason = null;
    if ($changeReason !== null) {
        $changeReason = function_exists('mb_substr')
            ? mb_substr($changeReason, 0, 255)
            : substr($changeReason, 0, 255);
    }

    // The streak columns arrive with the rebuild migration. If the deploy has
    // copied PHP but not yet run migrations, fall back to the legacy shape so
    // saving a rule never hard-fails mid-deploy.
    try {
        $stmt = $pdo->prepare("
            INSERT INTO charis_awol_config
                (id, services_missed, missed_threshold, consecutive_threshold, period_weeks,
                 archive_weeks, service_types, service_rules, spiritual_statuses, updated_by, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                services_missed       = VALUES(services_missed),
                missed_threshold      = VALUES(missed_threshold),
                consecutive_threshold = VALUES(consecutive_threshold),
                period_weeks          = VALUES(period_weeks),
                archive_weeks         = VALUES(archive_weeks),
                service_types         = VALUES(service_types),
                service_rules         = VALUES(service_rules),
                spiritual_statuses    = VALUES(spiritual_statuses),
                updated_by            = VALUES(updated_by),
                updated_at            = NOW()
        ");
        $stmt->execute([$servicesMissed, $servicesMissed, $servicesMissed, $periodWeeks,
                        $archiveWeeks, $servicesJson, $rulesJson, $statusesJson, $updatedBy]);
    } catch (PDOException $e) {
        error_log('Charis AWOL config streak columns unavailable, saving legacy shape: ' . $e->getMessage());
        $stmt = $pdo->prepare("
            INSERT INTO charis_awol_config
                (id, services_missed, missed_threshold, period_weeks, service_types, spiritual_statuses, updated_by, updated_at)
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
    }

    try {
        $histStmt = $pdo->prepare("
            INSERT INTO charis_awol_config_history
                (config_id, services_missed, missed_threshold, consecutive_threshold, period_weeks,
                 archive_weeks, service_types, service_rules, spiritual_statuses, updated_by, change_reason, created_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $histStmt->execute([$servicesMissed, $servicesMissed, $servicesMissed, $periodWeeks,
                            $archiveWeeks, $servicesJson, $rulesJson, $statusesJson, $updatedBy, $changeReason]);
    } catch (Throwable $e) {
        error_log('Charis AWOL config history write failed: ' . $e->getMessage());
    }

    return [
        'id'                    => 1,
        'services_missed'       => $servicesMissed,
        'missed_threshold'      => $servicesMissed,
        'consecutive_threshold' => $servicesMissed,
        'period_weeks'          => $periodWeeks,
        'archive_weeks'         => $archiveWeeks,
        'service_types'         => $filteredServices,
        'service_rules'         => $rules,
        'spiritual_statuses'    => $filteredStatuses,
        'updated_by'            => $updatedBy,
        'change_reason'         => $changeReason,
        'updated_at'            => date('Y-m-d H:i:s'),
    ];
}

/**
 * Recent AWOL rule changes for the editor modal's audit trail.
 *
 * @return array<int,array<string,mixed>>
 */
function charis_get_awol_config_history(PDO $pdo, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare("
            SELECT h.id, h.services_missed, h.missed_threshold, h.period_weeks,
                   h.service_types, h.spiritual_statuses, h.change_reason, h.created_at,
                   h.updated_by, u.first_name, u.last_name
              FROM charis_awol_config_history h
              LEFT JOIN users u ON u.id = h.updated_by
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Charis AWOL config history unavailable: ' . $e->getMessage());
        return [];
    }

    $history = [];
    foreach ($rows as $row) {
        $serviceTypes = json_decode((string) $row['service_types'], true);
        $spiritualStatuses = json_decode((string) $row['spiritual_statuses'], true);
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $history[] = [
            'id'                 => (int) $row['id'],
            'services_missed'    => (int) $row['services_missed'],
            'missed_threshold'   => (int) $row['missed_threshold'],
            'period_weeks'       => (int) $row['period_weeks'],
            'service_types'      => is_array($serviceTypes) ? array_values($serviceTypes) : [],
            'spiritual_statuses' => is_array($spiritualStatuses) ? array_values($spiritualStatuses) : [],
            'change_reason'      => $row['change_reason'],
            'created_at'         => $row['created_at'],
            'created_by_name'    => $name !== '' ? $name : 'Admin',
        ];
    }
    return $history;
}

/**
 * True once the AWOL exemption column exists.
 *
 * The deploy copies PHP before it runs migrations, so for a few seconds the
 * new code runs against the old schema. Selecting a column that is not there
 * yet would take the whole Charis dashboard down, so the engine asks first and
 * simply falls back to the status-based exclusion until the column lands.
 */
function charis_has_awol_exemption(PDO $pdo): bool
{
    // Probed with a real query rather than information_schema: that view is
    // MySQL-only, and this has to answer the same way under the SQLite the
    // engine tests run on. Cached per connection, not globally, so a process
    // holding two databases does not answer for the wrong one.
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $pdo->query("SELECT awol_exempt FROM users LIMIT 1");
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Normalise the per-service behaviour grid into a complete map.
 *
 * Each canonical service carries two independent switches:
 *   counts_absence - missing it advances the streak
 *   resets_clock   - attending it sets the streak back to zero
 *
 * The two are deliberately asymmetric for the midweek service. Members
 * travel a long way to Lekki and miss Mercy Experience for work, so a
 * midweek absence must never flag anyone; but a midweek attendance still
 * proves they are engaged, so it clears them.
 *
 * @return array<string,array{counts_absence:bool,resets_clock:bool}>
 */
function charis_awol_service_rules(array $config): array
{
    $raw = $config['service_rules'] ?? null;
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }

    $rules = [];
    foreach (array_keys(CHARIS_AWOL_CANONICAL_SERVICES) as $key) {
        if (is_array($raw) && isset($raw[$key]) && is_array($raw[$key])) {
            $rules[$key] = [
                'counts_absence' => !empty($raw[$key]['counts_absence']),
                'resets_clock'   => !empty($raw[$key]['resets_clock']),
            ];
            continue;
        }
        // No grid stored yet (half-deployed tree): fall back to the agreed
        // asymmetry rather than the legacy service_types list. Reading the
        // legacy list here would make midweek attendance invisible, which is
        // the exact failure the rebuild exists to remove.
        $rules[$key] = [
            'counts_absence' => $key === 'Sunday_Service',
            'resets_clock'   => true,
        ];
    }
    return $rules;
}

/** @return string[] canonical keys whose absence advances the streak */
function charis_awol_absence_services(array $rules): array
{
    return array_keys(array_filter($rules, static fn(array $r): bool => $r['counts_absence']));
}

/** @return string[] canonical keys whose attendance resets the streak */
function charis_awol_reset_services(array $rules): array
{
    return array_keys(array_filter($rules, static fn(array $r): bool => $r['resets_clock']));
}

/**
 * Persist Sundays the engine treated as "did not hold" so a broken check-in
 * scanner stays visible. A skipped service never advances a streak and never
 * resets one, so an undetected outage silently PAUSES the whole church's
 * counters -- these rows are how that becomes noticeable.
 */
function charis_awol_record_skipped_services(PDO $pdo, array $skipped): void
{
    if (!$skipped) return;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO charis_awol_skipped_services (service_date, event_category, reason)
            VALUES (?, ?, 'zero_attendance')
            ON DUPLICATE KEY UPDATE detected_at = detected_at
        ");
        foreach ($skipped as $row) {
            $stmt->execute([$row['service_date'], $row['event_category']]);
        }
    } catch (PDOException $e) {
        error_log('Charis AWOL skipped-service log failed: ' . $e->getMessage());
    }
}

/**
 * Compute the active AWOL list as a CONSECUTIVE-ABSENCE STREAK.
 *
 * The previous engine subtracted attended days from the total services in a
 * rolling window, which ignored ORDER: a member present on 4 October still
 * read "3 MISSED" because absences from before that visit kept counting.
 * This engine anchors on the last time the member was actually in the house
 * and counts only the unbroken run of qualifying absences after it, so any
 * single attendance clears them until they miss again.
 *
 * Anchor resolution:
 *   - last attendance at a service whose rule resets the clock; failing that
 *   - the member's registration date, which gives brand-new members their
 *     grace for free: they can only reach the threshold by missing N
 *     consecutive Sundays, which takes N weeks by definition.
 */
function charis_compute_awol_list(
    PDO $pdo,
    array $config,
    ?DateTimeImmutable $asOf = null
): array {
    $now   = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
    $rules = charis_awol_service_rules($config);

    $threshold = (int) ($config['consecutive_threshold']
        ?? $config['missed_threshold']
        ?? $config['services_missed']
        ?? CHARIS_AWOL_DEFAULT_SERVICES_MISSED);
    $archiveWeeks = (int) ($config['archive_weeks'] ?? CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS);
    $spiritualStatuses = (array) ($config['spiritual_statuses'] ?? CHARIS_AWOL_DEFAULT_STATUSES);

    $absenceServices = charis_awol_absence_services($rules);
    $resetServices   = charis_awol_reset_services($rules);

    $empty = [
        'qualifying_services'       => [],
        'total_qualifying_services' => 0,
        'consecutive_threshold'     => $threshold,
        'missed_threshold'          => $threshold,
        'archive_weeks'             => $archiveWeeks,
        'service_rules'             => $rules,
        'service_types'             => $absenceServices,
        'spiritual_statuses'        => $spiritualStatuses,
        'awol_checks'               => [],
        'skipped_services'          => [],
        'has_services'              => false,
    ];

    if (!$absenceServices || !$spiritualStatuses || $threshold <= 0) {
        return $empty;
    }

    // Look back far enough to resolve the longest streak we still display,
    // plus headroom so a member sitting just under the archive horizon is
    // measured against real history rather than a truncated window.
    $lookbackWeeks = min(260, max(8, $archiveWeeks + $threshold + 4));
    $windowStart   = $now->modify("-{$lookbackWeeks} weeks");
    $nowStr        = $now->format('Y-m-d H:i:s');
    $todayDate     = $now->format('Y-m-d');

    // 1. Every service occurrence that either counts or clears, newest last.
    $allServiceKeys  = array_values(array_unique(array_merge($absenceServices, $resetServices)));
    $categoryAliases = charis_awol_event_category_aliases($allServiceKeys);
    $typePlaceholders = implode(',', array_fill(0, count($categoryAliases), '?'));
    $eventStmt = $pdo->prepare("
        SELECT REPLACE(event_category, ' ', '_') AS event_category,
               DATE(event_date) AS service_date
          FROM events
         WHERE event_category IN ({$typePlaceholders})
           AND event_date >= ?
           AND event_date <= ?
         GROUP BY REPLACE(event_category, ' ', '_'), DATE(event_date)
         ORDER BY service_date ASC
    ");
    $eventStmt->execute(array_merge($categoryAliases, [$windowStart->format('Y-m-d H:i:s'), $nowStr]));
    $services = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$services) {
        return $empty;
    }

    $serviceDates = array_values(array_unique(array_column($services, 'service_date')));
    $datePlaceholders = implode(',', array_fill(0, count($serviceDates), '?'));
    $attendanceUnion = assim_attendance_union_sql();

    // 2. Who was present on each service date. One row per person per day:
    //    multiple check-ins on the same day are a single presence.
    $attStmt = $pdo->prepare("
        SELECT DISTINCT attended.user_id, attended.attended_on
          FROM {$attendanceUnion} attended
         WHERE attended.attended_on IN ({$datePlaceholders})
    ");
    $attStmt->execute($serviceDates);
    $presenceByUser = [];
    $attendedDates  = [];
    while ($row = $attStmt->fetch(PDO::FETCH_ASSOC)) {
        $presenceByUser[(int) $row['user_id']][$row['attended_on']] = true;
        $attendedDates[$row['attended_on']] = true;
    }

    // 3. A service nobody at all attended did not hold. Skip it: it must not
    //    advance a streak (the church was not gathered) and must not reset one.
    $heldAbsenceServices = [];
    $skippedServices     = [];
    foreach ($services as $service) {
        $counts = !empty($rules[$service['event_category']]['counts_absence']);
        if (!$counts) continue;
        if (isset($attendedDates[$service['service_date']])) {
            $heldAbsenceServices[] = $service;
        } else {
            $skippedServices[] = $service;
        }
    }

    // Dates whose service clears the counter, for anchor resolution.
    $resetDates = [];
    foreach ($services as $service) {
        if (!empty($rules[$service['event_category']]['resets_clock'])) {
            $resetDates[$service['service_date']] = true;
        }
    }

    if (!$heldAbsenceServices) {
        $empty['skipped_services'] = $skippedServices;
        $empty['has_services']     = true;
        return $empty;
    }

    // 4. Eligible people. Registration date doubles as the streak anchor for
    //    anyone never recorded present, so a member registered last week is
    //    not flagged before they have had N Sundays to appear.
    $statusPlaceholders = implode(',', array_fill(0, count($spiritualStatuses), '?'));

    // Someone deliberately taken off the list stays off, whatever any other
    // screen later writes to attendance_status. The status check remains for
    // records that predate the flag and for 'Unknown'.
    $exemptWhere = charis_has_awol_exemption($pdo)
        ? ' AND COALESCE(u.awol_exempt, 0) = 0'
        : '';
    $userStmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.phone, u.physical_address, u.email,
               u.spiritual_status, u.attendance_status, u.picture_path, u.created_at,
               r.name AS region_name
          FROM users u
          LEFT JOIN regions r ON u.region_id = r.id
         WHERE u.spiritual_status IN ({$statusPlaceholders})
           AND COALESCE(u.attendance_status, '') NOT IN ('Relocated', 'Attends_Another_Church', 'Attends Another Church', 'Unknown')
           {$exemptWhere}
         ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $userStmt->execute($spiritualStatuses);
    $eligibleUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$eligibleUsers) {
        $empty['has_services']     = true;
        $empty['skipped_services'] = $skippedServices;
        return $empty;
    }

    // 5. Last presence overall, for the "Away since" line on the card.
    $latestStmt = $pdo->prepare("
        SELECT attended.user_id, MAX(attended.attended_on) AS last_attended
          FROM {$attendanceUnion} attended
         WHERE attended.attended_on <= ?
         GROUP BY attended.user_id
    ");
    $latestStmt->execute([$todayDate]);
    $latestAttendedMap = [];
    while ($row = $latestStmt->fetch(PDO::FETCH_ASSOC)) {
        $latestAttendedMap[(int) $row['user_id']] = $row['last_attended'];
    }

    // 6. Open assignments, so a flagged member keeps their assigned worker.
    $assignmentsByUser = [];
    try {
        $asgStmt = $pdo->query("
            SELECT cwa.id AS assignment_id, cwa.target_user_id, cwa.worker_id,
                   cwa.status AS assignment_status, cwa.created_at,
                   w.first_name AS worker_fname, w.last_name AS worker_lname, w.phone AS worker_phone
              FROM charis_welfare_assignments cwa
              LEFT JOIN users w ON cwa.worker_id = w.id
             WHERE cwa.followup_id = 0 AND cwa.status = 'Assigned'
             ORDER BY cwa.id DESC
        ");
        while ($row = $asgStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int) $row['target_user_id'];
            if (!isset($assignmentsByUser[$uid])) $assignmentsByUser[$uid] = $row;
        }
    } catch (PDOException $e) {
        error_log('Charis AWOL assignments unavailable: ' . $e->getMessage());
    }

    // The last few counting services, for the fixed-width dot strip. Capped so
    // a member away thirty weeks renders exactly as compactly as one away three.
    $stripServices = array_slice($heldAbsenceServices, -CHARIS_AWOL_STRIP_LENGTH);

    $awolResults = [];
    foreach ($eligibleUsers as $user) {
        $uid      = (int) $user['id'];
        $presence = $presenceByUser[$uid] ?? [];

        // Anchor: the last day they were in the house for a clearing service.
        $anchor = null;
        foreach ($presence as $date => $_) {
            if (isset($resetDates[$date]) && ($anchor === null || $date > $anchor)) {
                $anchor = $date;
            }
        }
        $anchoredOnRegistration = false;
        if ($anchor === null) {
            $registered = !empty($user['created_at']) ? substr((string) $user['created_at'], 0, 10) : null;
            $anchor = $registered ?: $windowStart->format('Y-m-d');
            $anchoredOnRegistration = true;
        }

        // Streak: the unbroken run of counting services after the anchor.
        $streak      = 0;
        $missedDates = [];
        foreach ($heldAbsenceServices as $service) {
            if ($service['service_date'] <= $anchor) continue;
            if (isset($presence[$service['service_date']])) {
                // Defensive: a presence on a counting day that is not itself a
                // reset service still proves they were here, so never count it.
                $streak = 0;
                $missedDates = [];
                continue;
            }
            $streak++;
            $missedDates[] = $service['service_date'];
        }

        if ($streak < $threshold) continue;

        $lastAttended = $latestAttendedMap[$uid] ?? null;
        $awayFrom     = $lastAttended ?: ($anchoredOnRegistration ? $anchor : null);
        $weeksAway    = null;
        if ($awayFrom) {
            $awayDate  = charis_parse_date($awayFrom);
            $weeksAway = $awayDate ? (int) floor(($now->getTimestamp() - $awayDate->getTimestamp()) / 604800) : null;
        }

        $strip = [];
        foreach ($stripServices as $service) {
            $strip[] = [
                'date'    => $service['service_date'],
                'present' => isset($presence[$service['service_date']]),
            ];
        }

        $assignment = $assignmentsByUser[$uid] ?? null;
        $awolResults[] = [
            'followup_id'            => 0,
            'alert_type'             => 'AWOL',
            'target_user_id'         => $uid,
            'first_name'             => $user['first_name'],
            'last_name'              => $user['last_name'],
            'phone'                  => $user['phone'],
            'physical_address'       => $user['physical_address'],
            'email'                  => $user['email'],
            'spiritual_status'       => $user['spiritual_status'],
            'attendance_status'      => $user['attendance_status'],
            'picture_path'           => $user['picture_path'],
            'region_name'            => $user['region_name'],
            'consecutive_missed'     => $streak,
            'services_missed'        => $streak,
            'missed_count'           => $streak,
            'missed_service_dates'   => $missedDates,
            'attendance_strip'       => $strip,
            'last_attended_date'     => $lastAttended,
            'never_attended'         => $lastAttended === null,
            'weeks_away'             => $weeksAway,
            'tier'                   => ($weeksAway !== null && $weeksAway >= $archiveWeeks) ? 'Dormant' : 'Follow_Up',
            'consecutive_threshold'  => $threshold,
            'assignment_id'          => $assignment ? (int) $assignment['assignment_id'] : null,
            'worker_id'              => $assignment && $assignment['worker_id'] ? (int) $assignment['worker_id'] : null,
            'assignment_status'      => $assignment ? $assignment['assignment_status'] : null,
            'worker_fname'           => $assignment ? $assignment['worker_fname'] : null,
            'worker_lname'           => $assignment ? $assignment['worker_lname'] : null,
            'worker_phone'           => $assignment ? $assignment['worker_phone'] : null,
        ];
    }

    // Longest absence first: the people who need a pastor, not a reminder.
    usort($awolResults, static fn(array $a, array $b): int => $b['consecutive_missed'] <=> $a['consecutive_missed']);

    return [
        'qualifying_services'       => $heldAbsenceServices,
        'total_qualifying_services' => count($heldAbsenceServices),
        'consecutive_threshold'     => $threshold,
        'missed_threshold'          => $threshold,
        'archive_weeks'             => $archiveWeeks,
        'service_rules'             => $rules,
        'service_types'             => $absenceServices,
        'spiritual_statuses'        => $spiritualStatuses,
        'awol_checks'               => $awolResults,
        'skipped_services'          => $skippedServices,
        'has_services'              => true,
    ];
}

/**
 * Close cases for members who have come back, and write the archive note.
 *
 * The Minister's ask: "once IDI has marked an individual present for a
 * service, that automatically knocks out their AWOL status, at least until
 * they miss service again." Workers should not have to file paperwork for
 * someone who simply returned -- but the trace has to survive, so every
 * automatic closure writes a system-authored note and an audit row.
 *
 * Idempotent: a case already resolved, or already auto-resolved for that
 * same attendance date, is skipped.
 *
 * @return array<int,array<string,mixed>> the closures performed this run
 */
function charis_awol_auto_resolve_returned(
    PDO $pdo,
    array $config,
    ?DateTimeImmutable $asOf = null
): array {
    $now    = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
    $nowStr = $now->format('Y-m-d H:i:s');
    $today  = $now->format('Y-m-d');

    $rules         = charis_awol_service_rules($config);
    $resetServices = charis_awol_reset_services($rules);
    if (!$resetServices) return [];

    // Dates on which attending clears a case.
    $aliases = charis_awol_event_category_aliases($resetServices);
    $ph = implode(',', array_fill(0, count($aliases), '?'));
    try {
        $svcStmt = $pdo->prepare("
            SELECT DATE(event_date) AS service_date,
                   REPLACE(event_category, ' ', '_') AS event_category
              FROM events
             WHERE event_category IN ({$ph}) AND event_date <= ?
             GROUP BY DATE(event_date), REPLACE(event_category, ' ', '_')
        ");
        $svcStmt->execute(array_merge($aliases, [$nowStr]));
    } catch (PDOException $e) {
        error_log('Charis auto-resolution service lookup failed: ' . $e->getMessage());
        return [];
    }
    $resetDates = [];
    foreach ($svcStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resetDates[$row['service_date']] = $row['event_category'];
    }
    if (!$resetDates) return [];

    // Open cases: AWOL assignments and manual flags pushed from Congregation.
    $openCases = [];
    try {
        $asgStmt = $pdo->query("
            SELECT id AS assignment_id, target_user_id, followup_id, created_at
              FROM charis_welfare_assignments
             WHERE status = 'Assigned'
        ");
        foreach ($asgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $openCases[] = [
                'assignment_id' => (int) $row['assignment_id'],
                'user_id'       => (int) $row['target_user_id'],
                'followup_id'   => (int) $row['followup_id'],
                'opened_at'     => $row['created_at'],
                'case_type'     => ((int) $row['followup_id'] === 0) ? 'AWOL' : 'Manual_Check',
            ];
        }
    } catch (PDOException $e) {
        error_log('Charis auto-resolution assignment scan failed: ' . $e->getMessage());
    }

    try {
        $flagStmt = $pdo->query("
            SELECT ef.id AS followup_id, ef.visitor_id AS user_id, ef.followup_date
              FROM embrace_followups ef
             WHERE ef.status = 'Pending' AND ef.followup_notes LIKE '%SYSTEM FLAG%'
        ");
        foreach ($flagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $openCases[] = [
                'assignment_id' => null,
                'user_id'       => (int) $row['user_id'],
                'followup_id'   => (int) $row['followup_id'],
                'opened_at'     => $row['followup_date'],
                'case_type'     => 'Manual_Check',
            ];
        }
    } catch (PDOException $e) {
        error_log('Charis auto-resolution manual-flag scan failed: ' . $e->getMessage());
    }

    if (!$openCases) return [];

    $attendanceUnion = assim_attendance_union_sql();

    // One query for every case's attendance rather than one per case: the list
    // can carry a hundred open cases and this runs on every dashboard load.
    $caseUserIds = array_values(array_unique(array_map(
        static fn(array $c): int => $c['user_id'],
        $openCases
    )));
    $idPlaceholders = implode(',', array_fill(0, count($caseUserIds), '?'));
    $presenceByUser = [];
    try {
        $attStmt = $pdo->prepare("
            SELECT DISTINCT attended.user_id, attended.attended_on
              FROM {$attendanceUnion} attended
             WHERE attended.user_id IN ({$idPlaceholders})
               AND attended.attended_on <= ?
        ");
        $attStmt->execute(array_merge($caseUserIds, [$today]));
        while ($row = $attStmt->fetch(PDO::FETCH_ASSOC)) {
            $presenceByUser[(int) $row['user_id']][] = $row['attended_on'];
        }
    } catch (PDOException $e) {
        error_log('Charis auto-resolution attendance scan failed: ' . $e->getMessage());
        return [];
    }

    $resolved = [];

    foreach ($openCases as $case) {
        $openedDate = $case['opened_at'] ? substr((string) $case['opened_at'], 0, 10) : null;

        // Latest clearing attendance on or after the day the case was opened.
        $attendedOn = null;
        foreach ($presenceByUser[$case['user_id']] ?? [] as $date) {
            if ($openedDate !== null && $date < $openedDate) continue;
            if (!isset($resetDates[$date])) continue;
            if ($attendedOn === null || $date > $attendedOn) $attendedOn = $date;
        }
        if ($attendedOn === null) continue;

        $category = $resetDates[$attendedOn];
        $label    = CHARIS_AWOL_CANONICAL_SERVICES[$category] ?? str_replace('_', ' ', $category);
        $human    = date('j M Y', strtotime($attendedOn));
        $noteText = $case['case_type'] === 'AWOL'
            ? "Case closed automatically: attended {$label} on {$human}. The absence counter has been reset and will restart only if they miss again."
            : "Manual welfare flag cleared automatically: attended {$label} on {$human}. Reopen if the original concern still stands.";

        // Skip if this exact return was already filed.
        try {
            $dupe = $pdo->prepare("
                SELECT 1 FROM charis_awol_auto_resolutions
                 WHERE target_user_id = ? AND followup_id = ? AND attended_on = ? LIMIT 1
            ");
            $dupe->execute([$case['user_id'], $case['followup_id'], $attendedOn]);
            if ($dupe->fetchColumn()) continue;
        } catch (PDOException $e) {
            error_log('Charis auto-resolution dedupe check failed: ' . $e->getMessage());
        }

        try {
            $pdo->beginTransaction();

            if ($case['assignment_id'] !== null) {
                $pdo->prepare("
                    UPDATE charis_welfare_assignments
                       SET status = 'Resolved', resolved_at = ?
                     WHERE id = ? AND status = 'Assigned'
                ")->execute([$nowStr, $case['assignment_id']]);
            }

            if ($case['followup_id'] > 0) {
                $pdo->prepare("
                    UPDATE embrace_followups
                       SET status = 'Completed'
                     WHERE id = ? AND status = 'Pending'
                ")->execute([$case['followup_id']]);
            }

            $noteStmt = $pdo->prepare("
                INSERT INTO charis_welfare_notes (target_user_id, author_id, author_name, note_text, visible_to, is_system, created_at)
                VALUES (?, NULL, 'System', ?, ?, 1, ?)
            ");
            $noteStmt->execute([$case['user_id'], $noteText, json_encode(['All']), $nowStr]);
            $noteId = (int) $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO charis_awol_auto_resolutions
                    (target_user_id, assignment_id, followup_id, case_type, attended_on, event_category, note_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $case['user_id'], $case['assignment_id'], $case['followup_id'],
                $case['case_type'], $attendedOn, $category, $noteId, $nowStr,
            ]);

            $pdo->commit();
            $resolved[] = [
                'user_id'     => $case['user_id'],
                'case_type'   => $case['case_type'],
                'attended_on' => $attendedOn,
                'note'        => $noteText,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Charis auto-resolution failed for user ' . $case['user_id'] . ': ' . $e->getMessage());
        }
    }

    return $resolved;
}

/**
 * Statuses that mean a person has left the congregation, as opposed to simply
 * being hard to reach. Choosing one of these takes them off AWOL for good.
 */
const CHARIS_AWOL_DEPARTED_STATUSES = ['Relocated', 'Attends_Another_Church'];

/**
 * Set or clear a member's AWOL exemption, and keep attendance_status in step.
 *
 * This is the only place the flag is written. Marking someone Relocated or
 * Attends Another Church exempts them permanently; choosing any other final
 * status (they were reached, they are back) clears the exemption so normal
 * monitoring resumes.
 */
function charis_set_awol_exemption(
    PDO $pdo,
    int $targetUserId,
    string $attendanceStatus,
    int $actorUserId
): bool {
    if ($targetUserId <= 0) return false;
    if (!charis_has_awol_exemption($pdo)) return false;

    $departed = in_array(
        str_replace(' ', '_', trim($attendanceStatus)),
        CHARIS_AWOL_DEPARTED_STATUSES,
        true
    );

    try {
        if ($departed) {
            $pdo->prepare("
                UPDATE users
                   SET awol_exempt = 1,
                       awol_exempt_reason = ?,
                       awol_exempt_at = ?,
                       awol_exempt_by = ?
                 WHERE id = ?
            ")->execute([
                str_replace(' ', '_', trim($attendanceStatus)),
                (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s'),
                $actorUserId ?: null,
                $targetUserId,
            ]);
        } else {
            // Back in the congregation: resume monitoring from today.
            $pdo->prepare("
                UPDATE users
                   SET awol_exempt = 0,
                       awol_exempt_reason = NULL,
                       awol_exempt_at = NULL,
                       awol_exempt_by = NULL
                 WHERE id = ?
            ")->execute([$targetUserId]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('Charis AWOL exemption write failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Preserve a departed member's attendance_status when another screen saves a
 * profile without explicitly changing it.
 *
 * The Events profile editor writes `$attendance_status ?: 'New'`, so an absent
 * field silently resets someone who had left back to a monitored member. Call
 * this to find out whether the incoming value should be written at all.
 */
function charis_should_keep_attendance_status(PDO $pdo, int $targetUserId, string $incoming): bool
{
    if ($targetUserId <= 0) return false;
    if (trim($incoming) !== '') return false;
    try {
        $stmt = $pdo->prepare("SELECT attendance_status FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$targetUserId]);
        $current = (string) ($stmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        return false;
    }
    return in_array(str_replace(' ', '_', $current), CHARIS_AWOL_DEPARTED_STATUSES, true)
        || $current === 'Unknown';
}
