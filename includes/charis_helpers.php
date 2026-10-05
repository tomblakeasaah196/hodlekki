<?php
// /includes/charis_helpers.php
// Shared access control, welfare-note visibility, and validation helpers for
// the Charis page, API, and AWOL report.

const CHARIS_ATTENDANCE_STATUSES = [
    'New',
    'Active',
    'Inconsistent',
    'Unknown',
    'Relocated',
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
 * Return the one access decision used by the page, API, and PDF.
 *
 * The sidebar exposes Charis to pastors and active members of departments 1
 * (IDI) and 10 (Charis). Name matching keeps access intact if those department
 * IDs differ on an older installation. A Director/HOD badge only grants Charis
 * management rights when it belongs to one of those departments; leadership
 * in an unrelated department must not expose confidential welfare records.
 *
 * @return array{can_access:bool,is_manager:bool,is_pastor:bool,is_super_admin:bool,is_team_member:bool}
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
        'can_access'     => $isSuper || $isPastor || $isTeamMember,
        'is_manager'     => $isSuper || $isPastor || $isTeamManager,
        'is_pastor'      => $isPastor,
        'is_super_admin' => $isSuper,
        'is_team_member' => $isTeamMember,
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
