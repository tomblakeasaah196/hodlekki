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

// Who may search what is the permission matrix: people = Congregation view,
// events = Events & Attendance view (Roles & permissions).
$can_search_people = can('congregation', 'view');
$can_search_events = can('events', 'view');

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
