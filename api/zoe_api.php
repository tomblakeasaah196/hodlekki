<?php
// /api/zoe_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Core Security & Session Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 2. Zoe RBAC 
$is_zoe_admin = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']);

// Check if user is officially in Zoe (Worker or Volunteer)
$rosterCheck = $pdo->prepare("SELECT id FROM zoe_roster WHERE user_id = ? AND is_active = 1");
$rosterCheck->execute([$user_id]);
$is_zoe_member = $rosterCheck->fetch() || $is_zoe_admin;

// If not a member, they can ONLY submit prayer requests. Block everything else.
if (!$is_zoe_member && $action !== 'submit_prayer_request' && $action !== 'fetch_my_requests') {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. You are not an active member of the Zoe Intercessory unit.']);
    exit;
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DASHBOARD (War Room, Sessions, Roster, Analytics)
        // =====================================================================================
        case 'fetch_dashboard':
            // 1. Campaigns
            $campaigns = $pdo->query("SELECT * FROM zoe_campaigns ORDER BY start_date DESC LIMIT 10")->fetchAll();

            // 2. Upcoming Sessions
            $sessions = $pdo->query("
                SELECT s.*, c.title as campaign_title, 
                       (SELECT COUNT(*) FROM zoe_topics t WHERE t.session_id = s.id) as topic_count
                FROM zoe_sessions s
                LEFT JOIN zoe_campaigns c ON s.campaign_id = c.id
                WHERE s.session_date >= CURDATE() OR s.status = 'Draft'
                ORDER BY s.session_date ASC, s.start_time ASC
            ")->fetchAll();

            // 3. War Room Feed
            $war_room = $pdo->query("
                SELECT w.*, u.first_name, u.last_name 
                FROM zoe_war_room w
                JOIN users u ON w.user_id = u.id
                ORDER BY w.posted_at DESC LIMIT 50
            ")->fetchAll();

            // 4. Church-Wide Prayer Requests
            $requests = $pdo->query("
                SELECT r.*, u.first_name, u.last_name, u.phone 
                FROM zoe_prayer_requests r
                JOIN users u ON r.submitted_by = u.id
                ORDER BY r.submitted_at DESC LIMIT 100
            ")->fetchAll();

            // 5. Active Roster (Official Workers from user_departments + Volunteers from zoe_roster)
            $roster = $pdo->query("
                SELECT MAX(roster_id) as id, user_id, MAX(roster_type) as roster_type, first_name, last_name, spiritual_status
                FROM (
                    SELECT zr.id as roster_id, zr.user_id, zr.roster_type, u.first_name, u.last_name, u.spiritual_status
                    FROM zoe_roster zr
                    JOIN users u ON zr.user_id = u.id
                    WHERE zr.is_active = 1
                    
                    UNION ALL
                    
                    SELECT NULL as roster_id, ud.user_id, ud.role_in_dept as roster_type, u.first_name, u.last_name, u.spiritual_status
                    FROM user_departments ud
                    JOIN users u ON ud.user_id = u.id
                    WHERE ud.department_id = 7 AND ud.is_active = 1
                ) as combined_roster
                GROUP BY user_id, first_name, last_name, spiritual_status
                ORDER BY roster_type ASC, first_name ASC
            ")->fetchAll();

            // 6. Basic Analytics
            $analytics = $pdo->query("
                SELECT 
                    (SELECT COUNT(*) FROM zoe_prayer_requests WHERE status = 'Pending') as pending_requests,
                    (SELECT COUNT(*) FROM zoe_prayer_requests WHERE status = 'Answered_Testimony') as total_testimonies,
                    (SELECT COUNT(*) FROM zoe_sessions WHERE status = 'Completed') as completed_sessions
            ")->fetch();

            echo json_encode([
                'status' => 'success',
                'is_admin' => $is_zoe_admin,
                'campaigns' => $campaigns,
                'sessions' => $sessions,
                'war_room' => $war_room,
                'requests' => $requests,
                'roster' => $roster,
                'analytics' => $analytics
            ]);
            break;

        // =====================================================================================
        // ACTION 2: CAMPAIGN MANAGEMENT
        // =====================================================================================
        case 'save_campaign':
            if (!$is_zoe_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $id = $_POST['id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $theme = trim($_POST['theme_description'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';

            if (empty($title) || empty($start) || empty($end)) exit(json_encode(['status' => 'error', 'message' => 'Title and dates are required.']));

            if ($id) {
                $pdo->prepare("UPDATE zoe_campaigns SET title=?, theme_description=?, start_date=?, end_date=? WHERE id=?")->execute([$title, $theme, $start, $end, $id]);
            } else {
                $pdo->prepare("INSERT INTO zoe_campaigns (title, theme_description, start_date, end_date) VALUES (?, ?, ?, ?)")->execute([$title, $theme, $start, $end]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Prayer campaign saved successfully.']);
            break;

        // =====================================================================================
        // ACTION 3: SESSION MANAGEMENT (Meetings & Bulletin Uploads)
        // =====================================================================================
        case 'save_session':
            if (!$is_zoe_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $id = $_POST['id'] ?? '';
            $campaign_id = empty($_POST['campaign_id']) ? null : $_POST['campaign_id'];
            $title = trim($_POST['title'] ?? '');
            $date = $_POST['session_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $type = $_POST['meeting_type'] ?? 'In-Person';
            $location = trim($_POST['location_or_link'] ?? '');

            if (empty($title) || empty($date) || empty($start_time) || empty($location)) exit(json_encode(['status' => 'error', 'message' => 'Please fill all required session details.']));

            // Optional PDF Bulletin Upload
            $pdf_url = $_POST['existing_pdf_url'] ?? null;
            if (isset($_FILES['bulletin_pdf']) && $_FILES['bulletin_pdf']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/zoe/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_BULLETIN_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['bulletin_pdf']['name']));
                if (move_uploaded_file($_FILES['bulletin_pdf']['tmp_name'], $upload_dir . $filename)) {
                    $pdf_url = '../../uploads/zoe/' . $filename; 
                }
            }

            if ($id) {
                $pdo->prepare("UPDATE zoe_sessions SET campaign_id=?, title=?, session_date=?, start_time=?, end_time=?, meeting_type=?, location_or_link=?, bulletin_pdf_url=? WHERE id=?")
                    ->execute([$campaign_id, $title, $date, $start_time, $end_time, $type, $location, $pdf_url, $id]);
            } else {
                $pdo->prepare("INSERT INTO zoe_sessions (campaign_id, title, session_date, start_time, end_time, meeting_type, location_or_link, bulletin_pdf_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$campaign_id, $title, $date, $start_time, $end_time, $type, $location, $pdf_url]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Prayer session scheduled.']);
            break;

        case 'update_session_status':
            if (!$is_zoe_admin) exit();
            $pdo->prepare("UPDATE zoe_sessions SET status = ? WHERE id = ?")->execute([$_POST['status'], $_POST['session_id']]);
            echo json_encode(['status' => 'success', 'message' => 'Session status updated.']);
            break;

        // =====================================================================================
        // ACTION 4: FETCH SINGLE SESSION DETAILS (Topics & Roll Call Data)
        // =====================================================================================
        case 'fetch_session_details':
            $session_id = $_POST['session_id'] ?? '';
            
            // Get Session Info
            $stmt = $pdo->prepare("SELECT s.*, c.title as campaign_title FROM zoe_sessions s LEFT JOIN zoe_campaigns c ON s.campaign_id = c.id WHERE s.id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            // Get Allocated Topics & Assignments
            $tStmt = $pdo->prepare("
                SELECT t.*, u.first_name, u.last_name 
                FROM zoe_topics t 
                LEFT JOIN users u ON t.assigned_user_id = u.id 
                WHERE t.session_id = ? ORDER BY t.id ASC
            ");
            $tStmt->execute([$session_id]);
            $topics = $tStmt->fetchAll();

            // Get Attendance Roll Call List (Active Roster + Already Marked)
            $attStmt = $pdo->prepare("
                SELECT zr.user_id, u.first_name, u.last_name, 
                       COALESCE(za.attendance_status, 'Absent') as current_status
                FROM zoe_roster zr
                JOIN users u ON zr.user_id = u.id
                LEFT JOIN zoe_attendance za ON za.user_id = zr.user_id AND za.session_id = ?
                WHERE zr.is_active = 1
                ORDER BY u.first_name ASC
            ");
            $attStmt->execute([$session_id]);
            $attendance_list = $attStmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'session' => $session,
                'topics' => $topics,
                'attendance_list' => $attendance_list
            ]);
            break;

        // =====================================================================================
        // ACTION 5: PRAYER TOPICS & SHIFT ASSIGNMENTS
        // =====================================================================================
        case 'save_topic':
            if (!$is_zoe_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $session_id = $_POST['session_id'] ?? '';
            $title = trim($_POST['topic_title'] ?? '');
            $points = trim($_POST['prayer_points'] ?? '');
            $bible = trim($_POST['bible_reference'] ?? ''); // New Field
            $time = trim($_POST['allocated_time'] ?? '');
            $assigned = empty($_POST['assigned_user_id']) ? null : $_POST['assigned_user_id'];

            if (empty($session_id) || empty($title) || empty($points)) exit(json_encode(['status' => 'error', 'message' => 'Title and prayer points are required.']));

            $pdo->prepare("INSERT INTO zoe_topics (session_id, topic_title, prayer_points, bible_reference, allocated_time, assigned_user_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$session_id, $title, $points, $bible, $time, $assigned]);
            
            echo json_encode(['status' => 'success', 'message' => 'Prayer topic and assignment locked in.']);
            break;

        case 'delete_topic':
            if (!$is_zoe_admin) exit();
            $pdo->prepare("DELETE FROM zoe_topics WHERE id = ?")->execute([$_POST['topic_id']]);
            echo json_encode(['status' => 'success', 'message' => 'Topic removed.']);
            break;

        // =====================================================================================
        // ACTION 6: MANUAL ATTENDANCE (ROLL CALL)
        // =====================================================================================
        case 'mark_attendance':
            if (!$is_zoe_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $session_id = $_POST['session_id'] ?? '';
            $attendance_data = json_decode($_POST['attendance_data'], true); // Format: {user_id: 'Present', ...}

            if (empty($session_id) || empty($attendance_data)) exit(json_encode(['status' => 'error', 'message' => 'No data provided.']));

            $pdo->beginTransaction();
            // Clear existing for this session to handle updates
            $pdo->prepare("DELETE FROM zoe_attendance WHERE session_id = ?")->execute([$session_id]);
            
            $stmt = $pdo->prepare("INSERT INTO zoe_attendance (session_id, user_id, attendance_status, marked_by) VALUES (?, ?, ?, ?)");
            foreach ($attendance_data as $uid => $status) {
                $stmt->execute([$session_id, $uid, $status, $user_id]);
            }
            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => 'Session attendance roll call saved.']);
            break;

        // =====================================================================================
        // ACTION 7: WAR ROOM (Prophetic Postings)
        // =====================================================================================
        case 'post_war_room':
            $type = $_POST['post_type'] ?? 'General_Note';
            $msg = trim($_POST['message'] ?? '');
            
            if (empty($msg)) exit(json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']));
            
            $pdo->prepare("INSERT INTO zoe_war_room (user_id, post_type, message) VALUES (?, ?, ?)")->execute([$user_id, $type, $msg]);
            echo json_encode(['status' => 'success', 'message' => 'Posted to the War Room securely.']);
            break;

        // =====================================================================================
        // ACTION 8: CHURCH-WIDE PRAYER REQUESTS
        // =====================================================================================
        case 'submit_prayer_request':
            // Anyone can submit via their dashboard
            $msg = trim($_POST['request_text'] ?? '');
            if (empty($msg)) exit(json_encode(['status' => 'error', 'message' => 'Request cannot be empty.']));
            
            $pdo->prepare("INSERT INTO zoe_prayer_requests (submitted_by, request_text) VALUES (?, ?)")->execute([$user_id, $msg]);
            echo json_encode(['status' => 'success', 'message' => 'Your prayer request has been securely submitted to the Zoe Intercessory team.']);
            break;

        case 'update_request_status':
            // HOD/Admin tracking lifecycles
            if (!$is_zoe_admin) exit();
            $req_id = $_POST['request_id'] ?? '';
            $status = $_POST['status'] ?? '';
            
            $pdo->prepare("UPDATE zoe_prayer_requests SET status = ? WHERE id = ?")->execute([$status, $req_id]);
            $msg = $status === 'Answered_Testimony' ? 'Hallelujah! Marked as a Testimony.' : 'Status updated.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================================
        // ACTION 9: ROSTER MANAGEMENT (Adding Volunteers)
        // =====================================================================================
        case 'add_to_roster':
            if (!$is_zoe_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $target_user_id = $_POST['user_id'] ?? '';
            $type = $_POST['roster_type'] ?? 'Volunteer';
            
            if (empty($target_user_id)) exit(json_encode(['status' => 'error', 'message' => 'User selection required.']));

            // Check if already active
            $chk = $pdo->prepare("SELECT id FROM zoe_roster WHERE user_id = ? AND is_active = 1");
            $chk->execute([$target_user_id]);
            if ($chk->fetch()) exit(json_encode(['status' => 'error', 'message' => 'User is already an active Zoe member.']));

            $pdo->prepare("INSERT INTO zoe_roster (user_id, roster_type) VALUES (?, ?)")->execute([$target_user_id, $type]);
            echo json_encode(['status' => 'success', 'message' => "Member successfully added as a Zoe $type."]);
            break;

        case 'remove_from_roster':
            if (!$is_zoe_admin) exit();
            $pdo->prepare("UPDATE zoe_roster SET is_active = 0 WHERE id = ?")->execute([$_POST['roster_id']]);
            echo json_encode(['status' => 'success', 'message' => 'Member deactivated from the Zoe roster.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Zoe API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Consult the logs.']);
}
?>