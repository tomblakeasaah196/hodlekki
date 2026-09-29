<?php
// /api/global_search_api.php
// Live data search behind the global command palette (Ctrl/Cmd+K).
// Returns people (congregation) and events matching a query, but only
// for users whose roles clear the same gates the modules themselves use.

require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$q      = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));

// ---------------------------------------------------------------------
// Role helpers (checks the whole lanyard, mirroring congregation_api.php)
// ---------------------------------------------------------------------
function gs_has_role(array $allowed): bool
{
    if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (in_array($role['role_name'], $allowed, true)) {
                return true;
            }
        }
    }
    return false;
}

$is_super_admin = gs_has_role(['Super_Admin']);

// Department membership (used to mirror the sidebar's userHasNavAccess)
$user_dept_ids = [];
try {
    $deptStmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = ? AND is_active = 1");
    $deptStmt->execute([$_SESSION['user_id']]);
    $user_dept_ids = array_map('intval', $deptStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    error_log('global_search_api dept lookup: ' . $e->getMessage());
}

function gs_in_dept(array $dept_ids): bool
{
    global $user_dept_ids;
    foreach ($dept_ids as $id) {
        if (in_array((int) $id, $user_dept_ids, true)) {
            return true;
        }
    }
    return false;
}

// Same clearance as api/congregation_api.php (leadership & admin only)
$can_search_people = $is_super_admin
    || gs_has_role(['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']);

// Same clearance as the sidebar's Events link: pastors/directors or IDI (dept 1)
$can_search_events = $is_super_admin
    || gs_has_role(['Resident_Pastor', 'Assoc_Pastor', 'Director'])
    || gs_in_dept([1]);

try {
    switch ($action) {

        case 'search':
            if (mb_strlen($q) < 2) {
                echo json_encode(['status' => 'success', 'data' => ['people' => [], 'events' => []]]);
                exit;
            }

            $like   = '%' . $q . '%';
            $people = [];
            $events = [];

            if ($can_search_people) {
                $stmt = $pdo->prepare("
                    SELECT id, first_name, last_name, phone, spiritual_status
                    FROM users
                    WHERE CONCAT(first_name, ' ', last_name) LIKE :q1
                       OR phone LIKE :q2
                       OR email LIKE :q3
                    ORDER BY first_name ASC, last_name ASC
                    LIMIT 6
                ");
                $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $people[] = [
                        'id'    => (int) $row['id'],
                        'label' => $fullName,
                        'sub'   => trim(($row['phone'] ? $row['phone'] . ' · ' : '') . ($row['spiritual_status'] ?? '')),
                        // Deep-link: congregation roster with the search box pre-filled
                        'url'   => '/modules/congregation/index.php#q=' . rawurlencode($fullName),
                    ];
                }
            }

            if ($can_search_events) {
                $stmt = $pdo->prepare("
                    SELECT id, title, event_category, event_date
                    FROM events
                    WHERE title LIKE :q1 OR event_category LIKE :q2
                    ORDER BY event_date DESC
                    LIMIT 5
                ");
                $stmt->execute(['q1' => $like, 'q2' => $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $niceDate = $row['event_date'] ? date('j M Y', strtotime($row['event_date'])) : '';
                    $events[] = [
                        'id'    => (int) $row['id'],
                        'label' => $row['title'],
                        'sub'   => trim($niceDate . ($row['event_category'] ? ' · ' . $row['event_category'] : '')),
                        'url'   => '/modules/events/index.php',
                    ];
                }
            }

            echo json_encode(['status' => 'success', 'data' => ['people' => $people, 'events' => $events]]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (PDOException $e) {
    error_log('global_search_api: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Search is temporarily unavailable.']);
}
