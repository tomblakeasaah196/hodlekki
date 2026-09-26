<?php
// /api/roles_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Ultimate Security Gate: SUPER ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['active_role'] !== 'Super_Admin') {
    echo json_encode(['status' => 'error', 'message' => 'CRITICAL CLASSIFIED: Only Super Admins can access the Identity & Access Management core.']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH MASTER ROSTER & FLAGS
        // ==========================================
        case 'fetch_master_roster':
            // Fetches all users, their global roles, custom tags, and security flags
            $stmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.spiritual_status, u.attendance_status,
                       GROUP_CONCAT(CONCAT(r.role_name, ':', ur.is_primary, ':', IFNULL(ur.custom_title, '')) SEPARATOR '|') as role_data,
                       MAX(ur.is_frozen) as account_frozen
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN roles r ON ur.role_id = r.id
                GROUP BY u.id
                ORDER BY u.first_name ASC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch standard roles for the assignment dropdown
            $roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'users' => $users, 'roles' => $roles]);
            break;

        // ==========================================
        // ACTION 2: ASSIGN SYSTEM ROLE (With Hard Blocks)
        // ==========================================
        case 'assign_role':
            $target_id = $_POST['user_id'] ?? '';
            $role_id = $_POST['role_id'] ?? '';
            $is_primary = isset($_POST['is_primary']) ? 1 : 0;

            if (empty($target_id) || empty($role_id)) {
                echo json_encode(['status' => 'error', 'message' => 'User and Role selections are mandatory.']);
                exit;
            }

            // A. Fetch Target User Data to run the Hard Blocks
            $uStmt = $pdo->prepare("SELECT first_name, last_name, phone, email, spiritual_status FROM users WHERE id = ?");
            $uStmt->execute([$target_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);

            // BLOCK 1: Unknown / Incomplete Profiles (Question 9 - Option A)
            $is_unknown = (stripos($user['last_name'], 'Unknown') !== false || stripos($user['first_name'], 'Unknown') !== false);
            $missing_contact = (empty($user['phone']) && empty($user['email']));
            
            if ($is_unknown || $missing_contact) {
                echo json_encode([
                    'status' => 'blocked', 
                    'message' => 'Action Blocked: Profile is incomplete. Name and contact details are required for leadership roles.',
                    'action_link' => '/modules/congregation/index.php?edit=' . $target_id,
                    'action_text' => 'Update Profile in Congregation'
                ]);
                exit;
            }

            // BLOCK 2: Membership/Spiritual Status Check (Question 3 - Option B)
            if ($user['spiritual_status'] !== 'Worker') {
                echo json_encode([
                    'status' => 'blocked', 
                    'message' => 'Action Blocked: User is currently a ' . str_replace('_', ' ', $user['spiritual_status']) . '. They must be officially upgraded to a Worker first.',
                    'action_link' => '/modules/congregation/index.php?edit=' . $target_id,
                    'action_text' => 'Upgrade Status in Congregation'
                ]);
                exit;
            }

            // B. Role Stacking Logic (Question 2 - Option C)
            // If setting as primary, demote any existing primary roles for this user
            if ($is_primary) {
                $pdo->prepare("UPDATE user_roles SET is_primary = 0 WHERE user_id = ?")->execute([$target_id]);
            }

            // Insert or Update the Role
            $stmt = $pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, is_primary) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary)
            ");
            $stmt->execute([$target_id, $role_id, $is_primary]);

            // C. Audit Trail (Question 7 - Option A)
            $rStmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
            $rStmt->execute([$role_id]);
            $role_name = $rStmt->fetchColumn();

            $log = $pdo->prepare("INSERT INTO role_assignment_logs (target_user_id, role_id, action_type, action_details, performed_by) VALUES (?, ?, 'Granted', ?, ?)");
            $log->execute([$target_id, $role_id, "Granted $role_name role (Primary: " . ($is_primary ? 'Yes' : 'No') . ")", $admin_id]);

            // NOTIFICATION TRIGGER: Inform the user of their new clearance
            $cleanRole = str_replace('_', ' ', $role_name);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'System Clearance Updated', ?, '/modules/profile/index.php')");
            $notifStmt->execute([$target_id, "You have been granted the '{$cleanRole}' system role by an administrator. Please log out and back in to see your new modules."]);

            echo json_encode(['status' => 'success', 'message' => "Role successfully assigned to {$user['first_name']}."]);
            break;

        // ==========================================
        // ACTION 3: ADD CUSTOM TITLE TAG (Hybrid Roles)
        // ==========================================
        case 'assign_custom_title':
            $target_id = $_POST['user_id'] ?? '';
            $role_id = $_POST['role_id'] ?? ''; // Which base role this title attaches to
            $title = trim($_POST['custom_title'] ?? '');

            $stmt = $pdo->prepare("UPDATE user_roles SET custom_title = ? WHERE user_id = ? AND role_id = ?");
            $stmt->execute([$title, $target_id, $role_id]);

            $log = $pdo->prepare("INSERT INTO role_assignment_logs (target_user_id, role_id, action_type, action_details, performed_by) VALUES (?, ?, 'Title_Added', ?, ?)");
            $log->execute([$target_id, $role_id, "Added custom display tag: '$title'", $admin_id]);

            echo json_encode(['status' => 'success', 'message' => "Custom title tag attached."]);
            break;

        // ==========================================
        // ACTION 4: REVOKE ROLE
        // ==========================================
        case 'revoke_role':
            $target_id = $_POST['user_id'] ?? '';
            $role_id = $_POST['role_id'] ?? '';

            // Fetch role name for logging and notification before deletion
            $rStmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
            $rStmt->execute([$role_id]);
            $role_name = $rStmt->fetchColumn();

            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?")->execute([$target_id, $role_id]);

            $log = $pdo->prepare("INSERT INTO role_assignment_logs (target_user_id, role_id, action_type, action_details, performed_by) VALUES (?, ?, 'Revoked', 'System role revoked', ?)");
            $log->execute([$target_id, $role_id, $admin_id]);

            // NOTIFICATION TRIGGER: Alert the user that access was removed
            $cleanRole = str_replace('_', ' ', $role_name);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'System Clearance Revoked', ?, '/modules/profile/index.php')");
            $notifStmt->execute([$target_id, "Your '{$cleanRole}' system privileges have been formally revoked. Contact an administrator if you believe this is an error."]);

            echo json_encode(['status' => 'success', 'message' => 'Role completely revoked.']);
            break;

        // ==========================================
        // ACTION 5: FETCH AUDIT LOGS (Tab 2)
        // ==========================================
        case 'fetch_audit_logs':
            $stmt = $pdo->query("
                SELECT l.*, 
                       t.first_name as target_fname, t.last_name as target_lname,
                       p.first_name as admin_fname, p.last_name as admin_lname,
                       r.role_name
                FROM role_assignment_logs l
                JOIN users t ON l.target_user_id = t.id
                JOIN users p ON l.performed_by = p.id
                LEFT JOIN roles r ON l.role_id = r.id
                ORDER BY l.created_at DESC LIMIT 100
            ");
            echo json_encode(['status' => 'success', 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION 6: FREEZE/UNFREEZE ACCOUNT (Manual Review for Relocated)
        // ==========================================
        case 'toggle_freeze':
            $target_id = $_POST['user_id'] ?? '';
            $is_frozen = $_POST['freeze_status'] ?? 0;

            $pdo->prepare("UPDATE user_roles SET is_frozen = ? WHERE user_id = ?")->execute([$is_frozen, $target_id]);

            $action = $is_frozen ? 'Frozen' : 'Unfrozen';
            $log = $pdo->prepare("INSERT INTO role_assignment_logs (target_user_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)");
            $log->execute([$target_id, $action, "Account privileges $action manually.", $admin_id]);

            // NOTIFICATION TRIGGER: Inform the user of their account status
            $status_word = $is_frozen ? 'suspended' : 'restored';
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Account Status Alert', ?, '/modules/profile/index.php')");
            $notifStmt->execute([$target_id, "Your administrative account privileges have been {$status_word}."]);

            echo json_encode(['status' => 'success', 'message' => "Account privileges have been $action."]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid IAM command.']);
            break;
    }

} catch (PDOException $e) {
    error_log("IAM API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error. Contact Database Admin.']);
}
?>