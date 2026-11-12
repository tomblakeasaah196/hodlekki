<?php
// /api/tribes_api.php
//
// Tribes (modules/tribes/index.php, assets/ui/js/pages/tribes.js).
// Writes need the CSRF token.

// 1. Core Includes & Headers
require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. Access: every member sees and runs their own tribe (user_tribes); the
//    church-wide picture is tribes / view church-wide, a new tribe is tribes /
//    create church-wide (day one: pastors). The old Director/HOD branch read
//    departments.director_id / hod_id, columns that do not exist, outside the
//    try/catch — it failed for every Director or HOD. It is gone.
api_require_can('tribes', 'view');
$can_view_global = can('tribes', 'view', 'church');
$can_create_tribe = can('tribes', 'create', 'church');

// The fixed lists the page offers; nothing else is stored.
$tribe_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$tribe_categories = ['Tribe_Meeting', 'Evangelism'];

/** Is this member in that tribe? */
function tribes_is_mine(PDO $pdo, int $uid, int $tribeId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM user_tribes WHERE user_id = ? AND tribe_id = ? LIMIT 1');
    $stmt->execute([$uid, $tribeId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * May this member work on that event? Only a TRIBE event, and only their own
 * tribe's unless they have the church-wide picture. (save_report used to
 * overwrite the report notes of any event at all — a closed hole.)
 */
function tribes_may_touch_event(PDO $pdo, int $uid, $eventId, bool $global): bool
{
    $stmt = $pdo->prepare('SELECT tribe_id FROM events WHERE id = ?');
    $stmt->execute([(int) $eventId]);
    $tribeId = $stmt->fetchColumn();
    if ($tribeId === false || $tribeId === null) {
        return false;
    }
    return $global || tribes_is_mine($pdo, $uid, (int) $tribeId);
}

if (in_array($action, ['create_activity', 'save_report', 'assign_roster'], true)) {
    api_require_can('tribes', 'create');
}
if ($action === 'create_activity' && !$can_view_global && !tribes_is_mine($pdo, (int) $user_id, (int) ($_POST['tribe_id'] ?? 0))) {
    echo json_encode(['status' => 'error', 'message' => 'You can only plan activities for your own tribe.']);
    exit;
}
if (in_array($action, ['save_report', 'assign_roster', 'fetch_roster'], true)
    && !tribes_may_touch_event($pdo, (int) $user_id, $_POST['event_id'] ?? $_GET['event_id'] ?? 0, $can_view_global)) {
    echo json_encode(['status' => 'error', 'message' => 'That is not one of your tribe\'s events.']);
    exit;
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA (Smart Routing)
        // ==========================================
        case 'fetch_dashboard':
            $response = ['status' => 'success', 'global_data' => null, 'my_tribe' => null];

            // A. Fetch Global Data (Only if authorized)
            if ($can_view_global) {
                $globalStmt = $pdo->query("
                    SELECT t.id, t.name, t.location_address, t.meeting_day,
                           CONCAT(u.first_name, ' ', u.last_name) as captain_name,
                           (SELECT COUNT(*) FROM user_tribes WHERE tribe_id = t.id) as member_count
                    FROM tribes t
                    LEFT JOIN users u ON t.captain_id = u.id
                    ORDER BY t.name ASC
                ");
                $response['global_data'] = $globalStmt->fetchAll();
            }

            // B. Fetch "My Tribe" Data (For everyone, including Pastors who belong to a tribe)
            $myTribeStmt = $pdo->prepare("
                SELECT t.id, t.name, t.location_address, t.meeting_day, t.captain_id
                FROM tribes t
                JOIN user_tribes ut ON t.id = ut.tribe_id
                WHERE ut.user_id = :uid LIMIT 1
            ");
            $myTribeStmt->execute(['uid' => $user_id]);
            $my_tribe = $myTribeStmt->fetch();

            if ($my_tribe) {
                $response['my_tribe'] = $my_tribe;

                // The last two weeks and what is coming: a meeting is reported
                // after it happens, so it must still be listed then.
                $actStmt = $pdo->prepare("
                    SELECT id, title, event_category, event_date, report_notes
                    FROM events
                    WHERE tribe_id = :tid AND event_date >= CURDATE() - INTERVAL 14 DAY
                    ORDER BY event_date ASC LIMIT 12
                ");
                $actStmt->execute(['tid' => $my_tribe['id']]);
                $response['my_tribe']['upcoming_events'] = $actStmt->fetchAll();

                // Get members of my tribe
                $memStmt = $pdo->prepare("
                    SELECT u.id, u.first_name, u.last_name, u.phone
                    FROM users u
                    JOIN user_tribes ut ON u.id = ut.user_id
                    WHERE ut.tribe_id = :tid
                    ORDER BY u.first_name, u.last_name
                ");
                $memStmt->execute(['tid' => $my_tribe['id']]);
                $response['my_tribe']['members'] = $memStmt->fetchAll();
            }

            echo json_encode($response);
            break;

        // ==========================================
        // ACTION 2: CREATE A NEW TRIBE (tribes / create church-wide)
        // ==========================================
        case 'create_tribe':
            if (!$can_create_tribe) {
                echo json_encode(['status' => 'error', 'message' => 'You do not have permission to create tribes.']);
                exit;
            }

            $name = trim($_POST['name'] ?? '');
            $location = mb_substr(trim($_POST['location_address'] ?? ''), 0, 255);
            $meeting_day = $_POST['meeting_day'] ?? 'Sunday';
            $captain_id = (int) ($_POST['captain_id'] ?? 0) ?: null;

            if ($name === '' || mb_strlen($name) > 100) {
                echo json_encode(['status' => 'error', 'field' => 'name', 'message' => 'Give the tribe a name of 100 characters or fewer.']);
                exit;
            }
            if (!in_array($meeting_day, $tribe_days, true)) {
                echo json_encode(['status' => 'error', 'field' => 'meeting_day', 'message' => 'Choose a meeting day.']);
                exit;
            }

            // Some installs have tribes.season NOT NULL with no default: fill it with this year.
            $hasSeason = (bool) $pdo->query("SHOW COLUMNS FROM tribes LIKE 'season'")->fetch();
            $params = ['name' => $name, 'loc' => $location, 'day' => $meeting_day, 'cap' => $captain_id];
            if ($hasSeason) {
                $params['season'] = (int) date('Y');
            }
            $stmt = $pdo->prepare($hasSeason
                ? 'INSERT INTO tribes (name, season, location_address, meeting_day, captain_id) VALUES (:name, :season, :loc, :day, :cap)'
                : 'INSERT INTO tribes (name, location_address, meeting_day, captain_id) VALUES (:name, :loc, :day, :cap)');
            $stmt->execute($params);

            // If a captain was assigned, automatically add them as a member of this new tribe
            if (!empty($captain_id)) {
                $new_tribe_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO user_tribes (user_id, tribe_id) VALUES (?, ?)")->execute([$captain_id, $new_tribe_id]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Tribe created successfully!']);
            break;

        // ==========================================
        // ACTION 3: CREATE TRIBE ACTIVITY (Rhema, Outreach, Study)
        // ==========================================
        case 'create_activity':
            $tribe_id = (int) ($_POST['tribe_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $category = $_POST['category'] ?? 'Tribe_Meeting'; // Maps to ENUM in events table
            $raw_date = (string) ($_POST['event_date'] ?? '');
            $when = DateTime::createFromFormat('!Y-m-d\TH:i', $raw_date) ?: DateTime::createFromFormat('!Y-m-d H:i:s', $raw_date);
            $event_date = $when ? $when->format('Y-m-d H:i:s') : '';

            if ($tribe_id <= 0 || $title === '' || $event_date === '') {
                echo json_encode(['status' => 'error', 'message' => 'Title, date, and tribe are required.']);
                exit;
            }
            if (!in_array($category, $tribe_categories, true)) {
                echo json_encode(['status' => 'error', 'field' => 'category', 'message' => 'Choose what kind of activity this is.']);
                exit;
            }
            if (mb_strlen($title) > 150) {
                echo json_encode(['status' => 'error', 'field' => 'title', 'message' => 'Keep the title to 150 characters.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO events (title, event_category, event_date, tribe_id, created_by)
                VALUES (:title, :category, :edate, :tid, :uid)
            ");
            $stmt->execute([
                'title' => $title, 'category' => $category, 'edate' => $event_date, 'tid' => $tribe_id, 'uid' => $user_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'Activity scheduled successfully.']);
            break;

        // ==========================================
        // ACTION 4: SAVE RHEMA REPORT & IMPACT
        // ==========================================
        case 'save_report':
            $event_id = (int) ($_POST['event_id'] ?? 0);
            $report_notes = trim($_POST['report_notes'] ?? '');

            if ($event_id <= 0 || $report_notes === '') {
                echo json_encode(['status' => 'error', 'field' => 'report_notes', 'message' => 'Report content cannot be empty.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE events SET report_notes = :notes WHERE id = :eid");
            $stmt->execute(['notes' => $report_notes, 'eid' => $event_id]);

            echo json_encode(['status' => 'success', 'message' => 'Rhema/Outreach report saved securely.']);
            break;

        // ==========================================
        // ACTION 5: ASSIGN ROSTER DUTY (Who is doing what)
        // ==========================================
        case 'assign_roster':
            $event_id = (int) ($_POST['event_id'] ?? 0);
            $task_name = trim($_POST['task_name'] ?? '');
            $assigned_user_id = (int) ($_POST['assigned_user_id'] ?? 0);

            if ($event_id <= 0 || $task_name === '' || $assigned_user_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required to assign a duty.']);
                exit;
            }
            if (mb_strlen($task_name) > 100) {
                echo json_encode(['status' => 'error', 'field' => 'task_name', 'message' => 'Keep the duty to 100 characters.']);
                exit;
            }
            // Only someone in the tribe whose meeting this is.
            $inTribe = $pdo->prepare('SELECT 1 FROM user_tribes ut JOIN events e ON e.tribe_id = ut.tribe_id WHERE e.id = ? AND ut.user_id = ? LIMIT 1');
            $inTribe->execute([$event_id, $assigned_user_id]);
            if (!$inTribe->fetchColumn()) {
                echo json_encode(['status' => 'error', 'field' => 'assigned_user_id', 'message' => 'Choose someone in this tribe.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO tribe_rosters (event_id, task_name, assigned_user_id)
                VALUES (:eid, :task, :uid)
            ");
            $stmt->execute(['eid' => $event_id, 'task' => $task_name, 'uid' => $assigned_user_id]);

            echo json_encode(['status' => 'success', 'message' => 'Duty assigned.']);
            break;

        // ==========================================
        // ACTION 6: FETCH ROSTER FOR AN EVENT
        // ==========================================
        case 'fetch_roster':
            $event_id = (int) ($_POST['event_id'] ?? $_GET['event_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT r.id, r.task_name, CONCAT(u.first_name, ' ', u.last_name) as assigned_name
                FROM tribe_rosters r
                JOIN users u ON r.assigned_user_id = u.id
                WHERE r.event_id = :eid
                ORDER BY r.id
            ");
            $stmt->execute(['eid' => $event_id]);
            $roster = $stmt->fetchAll();

            echo json_encode(['status' => 'success', 'data' => $roster]);
            break;

        // ==========================================
        // DEFAULT: INVALID ACTION
        // ==========================================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Tribes API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
