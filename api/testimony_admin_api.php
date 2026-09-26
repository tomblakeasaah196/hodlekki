<?php
// /api/testimony_admin_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Core Security & Session Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 2. RBAC: Only Pastors, Directors, and IDI Admins
$is_admin = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director']);
if (!$is_admin) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. This module is restricted to church leadership.']);
    exit;
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH ADMIN DASHBOARD (Analytics & Master List)
        // =====================================================================================
        case 'fetch_dashboard':
            // 1. Analytics for IDI
            $analytics = $pdo->query("
                SELECT 
                    COUNT(*) as total_testimonies,
                    SUM(CASE WHEN status = 'Pending_Editing' THEN 1 ELSE 0 END) as pending_edits,
                    SUM(CASE WHEN is_selected_for_service = 1 THEN 1 ELSE 0 END) as selected_for_service,
                    SUM(CASE WHEN voice_note_url IS NOT NULL THEN 1 ELSE 0 END) as total_voice_notes
                FROM testimonies
            ")->fetch(PDO::FETCH_ASSOC);

            // 2. Fetch Master List (Including Anonymity Logic for Display)
            $stmt = $pdo->query("
                SELECT t.*, 
                       u.first_name as member_fname, u.last_name as member_lname,
                       (SELECT COUNT(*) FROM testimony_comments c WHERE c.testimony_id = t.id) as comment_count
                FROM testimonies t
                LEFT JOIN users u ON t.user_id = u.id
                ORDER BY t.created_at DESC
            ");
            $testimonies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process names based on privacy so the Admin knows who requested anonymity
            foreach ($testimonies as &$t) {
                if ($t['user_id']) {
                    $t['display_name'] = $t['member_fname'] . ' ' . $t['member_lname'];
                    $t['submitter_type'] = 'Church Member';
                } else {
                    $t['display_name'] = $t['guest_name'] ?: 'Unknown Guest';
                    $t['submitter_type'] = 'Public Guest';
                }
            }

            echo json_encode([
                'status' => 'success',
                'analytics' => $analytics,
                'testimonies' => $testimonies
            ]);
            break;

        // =====================================================================================
        // ACTION 2: EDIT & APPROVE TESTIMONY (Rich Text formatting)
        // =====================================================================================
        case 'save_edited_testimony':
            $testimony_id = $_POST['testimony_id'] ?? '';
            // We allow HTML here for Rich Text (Bold, Colors, etc.)
            $rich_text_content = $_POST['content_text'] ?? ''; 
            $status = $_POST['status'] ?? 'Published';
            
            if (empty($testimony_id) || empty($rich_text_content)) {
                exit(json_encode(['status' => 'error', 'message' => 'Content cannot be empty.']));
            }

            $stmt = $pdo->prepare("UPDATE testimonies SET content_text = ?, status = ? WHERE id = ?");
            $stmt->execute([$rich_text_content, $status, $testimony_id]);

            echo json_encode(['status' => 'success', 'message' => 'Testimony formatted and published successfully.']);
            break;

        // =====================================================================================
        // ACTION 3: TOGGLE LIVE SERVICE SELECTION
        // =====================================================================================
        case 'toggle_service_selection':
            $testimony_id = $_POST['testimony_id'] ?? '';
            $current_state = $_POST['current_state'] ?? 0;
            $new_state = $current_state == 1 ? 0 : 1;

            if (empty($testimony_id)) exit(json_encode(['status' => 'error', 'message' => 'Missing ID.']));

            $pdo->prepare("UPDATE testimonies SET is_selected_for_service = ? WHERE id = ?")->execute([$new_state, $testimony_id]);
            
            $msg = $new_state == 1 ? 'Flagged for Live Sunday Service!' : 'Removed from Live Service roster.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================================
        // ACTION 4: DELETE/HIDE TESTIMONY
        // =====================================================================================
        case 'delete_testimony':
            $testimony_id = $_POST['testimony_id'] ?? '';
            if (empty($testimony_id)) exit();

            // We do a hard delete here for the Admin
            $pdo->prepare("DELETE FROM testimonies WHERE id = ?")->execute([$testimony_id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Testimony permanently deleted.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Testimony Admin API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Consult system logs.']);
}
?>