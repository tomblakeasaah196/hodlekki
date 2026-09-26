<?php
// /api/announcements_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 2. Permission Check (Admins, Pastors, and HODs can manage announcements)
$can_manage = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']);

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH ANNOUNCEMENTS
        // ==========================================
        case 'fetch_announcements':
            // We join with events to see the date/title, and users to see who submitted/approved
            $stmt = $pdo->query("
                SELECT a.*, 
                       e.title as event_name, e.event_date,
                       u1.first_name as creator_fname, u1.last_name as creator_lname,
                       u2.first_name as approver_fname, u2.last_name as approver_lname
                FROM announcements a
                LEFT JOIN events e ON a.target_event_id = e.id
                LEFT JOIN users u1 ON a.submitted_by = u1.id
                LEFT JOIN users u2 ON a.approved_by = u2.id
                ORDER BY e.event_date DESC, a.id DESC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION 2: FETCH UPCOMING EVENTS (For the Dropdown)
        // ==========================================
        case 'fetch_events':
            // Only pull events that are recent or upcoming so the dropdown stays clean
            $stmt = $pdo->query("SELECT id, title, event_date FROM events WHERE event_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY event_date ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION 3: SAVE ANNOUNCEMENT (Create/Update)
        // ==========================================
        case 'save_announcement':
            $id = $_POST['id'] ?? null;
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $event_id = $_POST['target_event_id'] ?? null;

            if (empty($title) || empty($content) || empty($event_id)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }

            if ($id) {
                // Update
                $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ?, target_event_id = ? WHERE id = ?");
                $stmt->execute([$title, $content, $event_id, $id]);
                $msg = "Announcement updated.";
            } else {
                // Create
                $stmt = $pdo->prepare("INSERT INTO announcements (title, content, target_event_id, submitted_by, status) VALUES (?, ?, ?, ?, 'Pending')");
                $stmt->execute([$title, $content, $event_id, $user_id]);
                $msg = "Announcement submitted for approval.";

                // NOTIFICATION TRIGGER: Alert Pastors and Super Admins for review
                $approversStmt = $pdo->query("
                    SELECT user_id FROM user_roles 
                    JOIN roles ON user_roles.role_id = roles.id 
                    WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor', 'Assoc_Pastor')
                ");
                $approvers = $approversStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($approvers)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Pending Announcement', ?, '/modules/announcements/index.php')");
                    $alertMessage = "A new announcement titled '{$title}' has been submitted and is awaiting your approval.";
                    foreach($approvers as $uid) {
                        // Prevent sending an alert to themselves if an admin/pastor submits it
                        if ($uid != $user_id) {
                            $notifStmt->execute([$uid, $alertMessage]);
                        }
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 4: APPROVE / ARCHIVE STATUS
        // ==========================================
        case 'update_status':
            $id = $_POST['id'] ?? '';
            $new_status = $_POST['status'] ?? ''; // 'Approved' or 'Archived'

            if (!$can_manage) {
                echo json_encode(['status' => 'error', 'message' => 'Clearance required to approve announcements.']);
                exit;
            }

            // Fetch announcement details to know who submitted it
            $annStmt = $pdo->prepare("SELECT title, submitted_by FROM announcements WHERE id = ?");
            $annStmt->execute([$id]);
            $announcement = $annStmt->fetch(PDO::FETCH_ASSOC);

            if ($new_status === 'Approved') {
                $stmt = $pdo->prepare("UPDATE announcements SET status = ?, approved_by = ? WHERE id = ?");
                $stmt->execute([$new_status, $user_id, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE announcements SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);
            }

            // NOTIFICATION TRIGGER: Alert the original submitter of the decision
            if ($announcement && !empty($announcement['submitted_by'])) {
                // Ensure we don't notify the approver if they are approving their own post
                if ($announcement['submitted_by'] != $user_id) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Announcement Update', ?, '/modules/announcements/index.php')");
                    $message = "Your announcement titled '{$announcement['title']}' has been marked as {$new_status}.";
                    $notifStmt->execute([$announcement['submitted_by'], $message]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => "Announcement marked as $new_status."]);
            break;

        // ==========================================
        // ACTION 5: DELETE ANNOUNCEMENT
        // ==========================================
        case 'delete_announcement':
            if (!$can_manage) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Announcement deleted.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Announcement API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error occurred.']);
}