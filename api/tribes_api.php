<?php
// /api/tribes_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. RBAC Helper: Can this user view ALL tribes?
$can_view_global = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $can_view_global = true;
} else if (in_array($active_role, ['Director', 'HOD'])) {
    // Check if they specifically direct/head the "Tribes" department
    $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE (director_id = :uid OR hod_id = :uid) AND name LIKE '%Tribe%' LIMIT 1");
    $deptStmt->execute(['uid' => $user_id]);
    if ($deptStmt->fetch()) {
        $can_view_global = true;
    }
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
                
                // Get upcoming activities for my tribe
                $actStmt = $pdo->prepare("
                    SELECT id, title, event_category, event_date 
                    FROM events 
                    WHERE tribe_id = :tid AND event_date >= CURDATE()
                    ORDER BY event_date ASC LIMIT 5
                ");
                $actStmt->execute(['tid' => $my_tribe['id']]);
                $response['my_tribe']['upcoming_events'] = $actStmt->fetchAll();

                // Get members of my tribe
                $memStmt = $pdo->prepare("
                    SELECT u.id, u.first_name, u.last_name, u.phone 
                    FROM users u 
                    JOIN user_tribes ut ON u.id = ut.user_id 
                    WHERE ut.tribe_id = :tid
                ");
                $memStmt->execute(['tid' => $my_tribe['id']]);
                $response['my_tribe']['members'] = $memStmt->fetchAll();
            }

            echo json_encode($response);
            break;

        // ==========================================
        // ACTION 2: CREATE A NEW TRIBE (Admin/Pastor/Director Only)
        // ==========================================
        case 'create_tribe':
            if (!$can_view_global) {
                echo json_encode(['status' => 'error', 'message' => 'You do not have permission to create tribes.']);
                exit;
            }

            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location_address'] ?? '');
            $meeting_day = $_POST['meeting_day'] ?? 'Sunday';
            $captain_id = $_POST['captain_id'] ?? null;

            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'Tribe name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO tribes (name, location_address, meeting_day, captain_id) 
                VALUES (:name, :loc, :day, :cap)
            ");
            $stmt->execute([
                'name' => $name, 'loc' => $location, 'day' => $meeting_day, 'cap' => empty($captain_id) ? null : $captain_id
            ]);

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
            $tribe_id = $_POST['tribe_id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $category = $_POST['category'] ?? 'Tribe_Meeting'; // Maps to ENUM in events table
            $event_date = $_POST['event_date'] ?? '';

            if (empty($tribe_id) || empty($title) || empty($event_date)) {
                echo json_encode(['status' => 'error', 'message' => 'Title, date, and tribe are required.']);
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
            $event_id = $_POST['event_id'] ?? '';
            $report_notes = trim($_POST['report_notes'] ?? '');

            if (empty($event_id) || empty($report_notes)) {
                echo json_encode(['status' => 'error', 'message' => 'Report content cannot be empty.']);
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
            $event_id = $_POST['event_id'] ?? '';
            $task_name = trim($_POST['task_name'] ?? '');
            $assigned_user_id = $_POST['assigned_user_id'] ?? '';

            if (empty($event_id) || empty($task_name) || empty($assigned_user_id)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required to assign a duty.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO tribe_rosters (event_id, task_name, assigned_user_id) 
                VALUES (:eid, :task, :uid)
            ");
            $stmt->execute(['eid' => $event_id, 'task' => $task_name, 'uid' => $assigned_user_id]);

            echo json_encode(['status' => 'success', 'message' => "$task_name assigned successfully."]);
            break;

        // ==========================================
        // ACTION 6: FETCH ROSTER FOR AN EVENT
        // ==========================================
        case 'fetch_roster':
            $event_id = $_POST['event_id'] ?? $_GET['event_id'] ?? '';
            
            $stmt = $pdo->prepare("
                SELECT r.id, r.task_name, CONCAT(u.first_name, ' ', u.last_name) as assigned_name 
                FROM tribe_rosters r
                JOIN users u ON r.assigned_user_id = u.id
                WHERE r.event_id = :eid
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
?>