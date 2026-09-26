<?php
// /includes/reach_helpers.php
// Shared by api/reach_api.php, api/reach_public_api.php,
// modules/reach/index.php and the Reach badge in includes/header.php.

const REACH_DEPT_SQL = "(d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%')";
const REACH_OVERDUE_DAYS = 5;

// Assigned more than REACH_OVERDUE_DAYS ago and nobody has followed up
// since the assignment.
function reach_overdue_sql(string $alias = 'l.'): string {
    return "({$alias}assigned_to IS NOT NULL AND {$alias}pushed_to_embrace_at IS NULL"
        . " AND {$alias}assigned_at < NOW() - INTERVAL " . REACH_OVERDUE_DAYS . " DAY"
        . " AND ({$alias}last_follow_up_at IS NULL OR {$alias}last_follow_up_at < {$alias}assigned_at))";
}

// Super Admin, pastors, or HOD/Director of the Reach department.
function reach_is_manager(PDO $pdo, int $user_id, string $active_role): bool {
    if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
        return true;
    }
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.user_id = ? AND ud.is_active = 1
          AND ud.role_in_dept IN ('HOD', 'Director') AND " . REACH_DEPT_SQL . "
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function reach_manager_ids(PDO $pdo): array {
    return $pdo->query("
        SELECT DISTINCT ud.user_id FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.is_active = 1 AND ud.role_in_dept IN ('HOD', 'Director') AND " . REACH_DEPT_SQL
    )->fetchAll(PDO::FETCH_COLUMN);
}

function reach_members(PDO $pdo): array {
    return $pdo->query("
        SELECT DISTINCT u.id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
        FROM users u
        JOIN user_departments ud ON ud.user_id = u.id AND ud.is_active = 1
        JOIN departments d ON d.id = ud.department_id
        WHERE " . REACH_DEPT_SQL . "
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function reach_is_member(PDO $pdo, int $user_id): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.user_id = ? AND ud.is_active = 1 AND " . REACH_DEPT_SQL . "
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function reach_notify(PDO $pdo, array $user_ids, string $title, string $message, string $link = '/modules/reach/index.php'): void {
    $user_ids = array_unique(array_filter(array_map('intval', $user_ids)));
    if (!$user_ids) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)");
    foreach ($user_ids as $uid) {
        $stmt->execute([$uid, $title, $message, $link]);
    }
}

function reach_sidebar_counts(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(l.assigned_to IS NULL AND l.pushed_to_embrace_at IS NULL AND l.status <> 'Declined'), 0) AS unassigned_all,
            COALESCE(SUM(l.assigned_to = ? AND l.pushed_to_embrace_at IS NULL), 0) AS my_assigned,
            COALESCE(SUM(l.assigned_to = ? AND " . reach_overdue_sql() . "), 0) AS my_overdue
        FROM reach_leads l
    ");
    $stmt->execute([$user_id, $user_id]);
    return array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));
}
