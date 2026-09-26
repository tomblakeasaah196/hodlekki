<?php
// /api/rol_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Strict Security Gate
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Check Clearance: Super Admin, Pastors, or River of Life (Dept 4)
$has_clearance = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $has_clearance = true;
} else {
    $deptStmt = $pdo->prepare("SELECT id FROM user_departments WHERE user_id = ? AND department_id = 4 AND is_active = 1");
    $deptStmt->execute([$user_id]);
    if ($deptStmt->fetch()) $has_clearance = true;
}

if (!$has_clearance) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: You must be in the River of Life department to access this module.']);
    exit;
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA (KANBAN & REHEARSALS)
        // ==========================================
        case 'fetch_dashboard':
            
            // A. Fetch the Roster, joining with users table for profile pics and details
            $rosterStmt = $pdo->query("
                SELECT r.id as roster_id, r.role_category, r.section_name, 
                       u.id as user_id, u.first_name, u.last_name, u.phone, u.picture_path, u.gender
                FROM river_of_life_roster r
                JOIN users u ON r.user_id = u.id
                ORDER BY r.role_category ASC, r.section_name ASC, u.first_name ASC
            ");
            $raw_roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            // Group the roster by Role Category and then by Section Name for the Kanban UI
            $grouped_roster = [
                'Vocalist' => [],
                'Band_Instrumentalist' => [],
                'Technical' => []
            ];
            
            foreach ($raw_roster as $member) {
                $category = $member['role_category'];
                $section = $member['section_name'];
                
                if (!isset($grouped_roster[$category][$section])) {
                    $grouped_roster[$category][$section] = [];
                }
                $grouped_roster[$category][$section][] = $member;
            }

            // B. Fetch Rehearsals (Upcoming & Recent)
            $rehearsalsStmt = $pdo->query("
                SELECT r.*, 
                       DATE_FORMAT(r.rehearsal_date, '%W, %M %D, %Y @ %h:%i %p') as nice_date,
                       (SELECT COUNT(*) FROM rol_attendance a WHERE a.rehearsal_id = r.id AND a.status = 'Present') as present_count,
                       (SELECT COUNT(*) FROM rol_attendance a WHERE a.rehearsal_id = r.id) as total_expected
                FROM rol_rehearsals r
                ORDER BY r.rehearsal_date DESC
                LIMIT 15
            ");
            $rehearsals = $rehearsalsStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch Available Members to add to the Choir
            // We ONLY pull people who are officially in Department 4 (River of Life) but NOT YET in the specific ROL Roster
            $availableStmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name, u.gender 
                FROM users u
                JOIN user_departments ud ON u.id = ud.user_id
                WHERE ud.department_id = 4 AND ud.is_active = 1
                AND u.id NOT IN (SELECT user_id FROM river_of_life_roster)
                ORDER BY u.first_name ASC
            ");
            $available_members = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Get Quick Stats
            $stats = [
                'total_members' => count($raw_roster),
                'vocalists' => $pdo->query("SELECT COUNT(*) FROM river_of_life_roster WHERE role_category = 'Vocalist'")->fetchColumn(),
                'band' => $pdo->query("SELECT COUNT(*) FROM river_of_life_roster WHERE role_category = 'Band_Instrumentalist'")->fetchColumn(),
                'tech' => $pdo->query("SELECT COUNT(*) FROM river_of_life_roster WHERE role_category = 'Technical'")->fetchColumn(),
            ];

            echo json_encode([
                'status' => 'success',
                'grouped_roster' => $grouped_roster,
                'rehearsals' => $rehearsals,
                'available_members' => $available_members,
                'stats' => $stats
            ]);
            break;

        // ==========================================
        // ACTION 2: ASSIGN MEMBER TO KANBAN SECTION
        // ==========================================
        case 'assign_member':
            $target_user_id = $_POST['user_id'] ?? '';
            $role_category = $_POST['role_category'] ?? ''; // Vocalist, Band_Instrumentalist, Technical
            $section_name = trim($_POST['section_name'] ?? ''); // e.g. Soprano, Drums, Sound

            if (empty($target_user_id) || empty($role_category) || empty($section_name)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }

            // Check if already in roster
            $checkStmt = $pdo->prepare("SELECT id FROM river_of_life_roster WHERE user_id = ?");
            $checkStmt->execute([$target_user_id]);
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Member is already assigned to a section.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO river_of_life_roster (user_id, role_category, section_name) VALUES (?, ?, ?)");
            $stmt->execute([$target_user_id, $role_category, $section_name]);

            // NOTIFICATION TRIGGER: Alert the newly assigned member
            $cleanRole = str_replace('_', ' ', $role_category);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'River of Life Roster', ?, '/modules/profile/index.php')");
            $notifMsg = "You have been officially assigned to the River of Life team as a {$cleanRole} ({$section_name}).";
            $notifStmt->execute([$target_user_id, $notifMsg]);

            echo json_encode(['status' => 'success', 'message' => 'Member successfully assigned to ' . $section_name . '.']);
            break;

        // ==========================================
        // ACTION 3: REMOVE MEMBER FROM ROSTER
        // ==========================================
        case 'remove_member':
            $roster_id = $_POST['roster_id'] ?? '';
            if ($roster_id) {
                $stmt = $pdo->prepare("DELETE FROM river_of_life_roster WHERE id = ?");
                $stmt->execute([$roster_id]);
                echo json_encode(['status' => 'success', 'message' => 'Member removed from specific section.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid ID.']);
            }
            break;

        // ==========================================
        // ACTION 4: SCHEDULE A REHEARSAL
        // ==========================================
        case 'schedule_rehearsal':
            $rehearsal_date = $_POST['rehearsal_date'] ?? ''; // Expecting datetime local format
            $focus_topic = trim($_POST['focus_topic'] ?? '');

            if (empty($rehearsal_date) || empty($focus_topic)) {
                echo json_encode(['status' => 'error', 'message' => 'Date, time, and focus topic are required.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Create the Rehearsal
                $stmt = $pdo->prepare("INSERT INTO rol_rehearsals (rehearsal_date, focus_topic) VALUES (?, ?)");
                $stmt->execute([$rehearsal_date, $focus_topic]);
                $rehearsal_id = $pdo->lastInsertId();

                // 2. Pre-populate the attendance sheet with EVERYONE currently in the choir roster (Defaults to Absent)
                $pdo->query("
                    INSERT INTO rol_attendance (rehearsal_id, user_id, status)
                    SELECT $rehearsal_id, user_id, 'Absent' FROM river_of_life_roster
                ");

                // NOTIFICATION TRIGGER: Alert all ROL Members of the rehearsal
                $rolStmt = $pdo->query("SELECT user_id FROM river_of_life_roster");
                $rol_members = $rolStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($rol_members)) {
                    $nice_date = date('l, M jS @ g:i A', strtotime($rehearsal_date));
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Rehearsal Scheduled', ?, '/modules/river_of_life/index.php')");
                    $msg = "A new rehearsal has been scheduled for {$nice_date}. Focus: {$focus_topic}. Please be punctual.";
                    
                    foreach($rol_members as $uid) {
                        if ($uid != $user_id) { // Don't alert the admin making the schedule
                            $notifStmt->execute([$uid, $msg]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Rehearsal scheduled and attendance roster generated.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==========================================
        // ACTION 5: FETCH SPECIFIC REHEARSAL ATTENDANCE
        // ==========================================
        case 'fetch_attendance':
            $rehearsal_id = $_POST['rehearsal_id'] ?? '';
            
            $stmt = $pdo->prepare("
                SELECT a.status, a.user_id, u.first_name, u.last_name, u.picture_path, r.role_category, r.section_name
                FROM rol_attendance a
                JOIN users u ON a.user_id = u.id
                JOIN river_of_life_roster r ON u.id = r.user_id
                WHERE a.rehearsal_id = ?
                ORDER BY r.role_category ASC, r.section_name ASC, u.first_name ASC
            ");
            $stmt->execute([$rehearsal_id]);
            
            echo json_encode(['status' => 'success', 'attendance' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION 6: MARK INDIVIDUAL ATTENDANCE
        // ==========================================
        case 'mark_attendance':
            $rehearsal_id = $_POST['rehearsal_id'] ?? '';
            $target_user_id = $_POST['user_id'] ?? '';
            $status = $_POST['status'] ?? 'Absent'; // Present, Absent, Excused

            $stmt = $pdo->prepare("UPDATE rol_attendance SET status = ? WHERE rehearsal_id = ? AND user_id = ?");
            $stmt->execute([$status, $rehearsal_id, $target_user_id]);

            echo json_encode(['status' => 'success']);
            break;
            
        // ==========================================
        // ACTION 7: SAVE SETLIST AUDIO KEY (10-20 SEC)
        // ==========================================
        case 'upload_audio_key':
            $event_id = $_POST['event_id'] ?? '';
            $song_id = $_POST['song_id'] ?? '';
            $performance_key = trim($_POST['performance_key'] ?? '');

            // Ensure directory exists
            $target_dir = "../../uploads/rol_keys/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

            $audio_path = null;
            if (isset($_FILES['audio_data']) && $_FILES['audio_data']['error'] == UPLOAD_ERR_OK) {
                // Generate unique filename
                $filename = uniqid('key_', true) . '.webm';
                $target_path = $target_dir . $filename;
                
                if (move_uploaded_file($_FILES['audio_data']['tmp_name'], $target_path)) {
                    $audio_path = '/uploads/rol_keys/' . $filename;
                }
            }

            // Insert into setlist
            $stmt = $pdo->prepare("INSERT INTO rol_event_setlists (event_id, song_id, performance_key, audio_key_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$event_id, $song_id, $performance_key, $audio_path]);

            // NOTIFICATION TRIGGER: Alert the ROL Team that practice materials are up
            $rolStmt = $pdo->query("SELECT user_id FROM river_of_life_roster");
            $rol_members = $rolStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($rol_members)) {
                // Get event title for context
                $evtStmt = $pdo->prepare("SELECT title FROM events WHERE id = ?");
                $evtStmt->execute([$event_id]);
                $evtTitle = $evtStmt->fetchColumn() ?: "an upcoming service";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Setlist Audio Key', ?, '/modules/river_of_life/index.php')");
                $msg = "A new song key ({$performance_key}) has been uploaded for {$evtTitle}. Please check the setlist and start practicing!";
                
                foreach($rol_members as $uid) {
                    if ($uid != $user_id) {
                        $notifStmt->execute([$uid, $msg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Song and audio key added to setlist!']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("River of Life API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>