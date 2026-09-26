<?php
// /api/assets_api.php

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

// Check Clearance: Super Admin, Pastors, or Specific Departments (IDI, Envision, River of Life)
$has_clearance = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $has_clearance = true;
} else {
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d 
        JOIN user_departments ud ON d.id = ud.department_id 
        WHERE ud.user_id = ? AND ud.is_active = 1 
        AND (d.name LIKE '%IDI%' OR d.name LIKE '%Envision%' OR d.name LIKE '%River of Life%')
    ");
    $deptStmt->execute([$user_id]);
    if ($deptStmt->fetch()) $has_clearance = true;
}

if (!$has_clearance) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: Asset Management requires specific departmental clearance.']);
    exit;
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            
            // A. High-Level Stats
            $stats = [];
            $stats['total'] = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
            $stats['in_use'] = $pdo->query("SELECT COUNT(*) FROM assets WHERE current_status = 'In_Use'")->fetchColumn();
            $stats['maintenance'] = $pdo->query("SELECT COUNT(*) FROM assets WHERE current_status = 'Maintenance'")->fetchColumn();
            $stats['lost'] = $pdo->query("SELECT COUNT(*) FROM assets WHERE current_status = 'Lost'")->fetchColumn();

            // B. Fetch All Assets
            $assetsStmt = $pdo->query("
                SELECT a.*, d.name as department_name 
                FROM assets a
                LEFT JOIN departments d ON a.managing_department_id = d.id
                ORDER BY a.name ASC
            ");
            $assets = $assetsStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch Recent Logs (Audit Trail)
            $logsStmt = $pdo->query("
                SELECT l.*, a.name as asset_name, u.first_name, u.last_name,
                       DATE_FORMAT(l.action_date, '%b %d, %Y %h:%i %p') as nice_date
                FROM asset_logs l
                JOIN assets a ON l.asset_id = a.id
                LEFT JOIN users u ON l.user_id = u.id
                ORDER BY l.action_date DESC
                LIMIT 50
            ");
            $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Helper Dropdowns
            $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $users = $pdo->query("SELECT id, first_name, last_name FROM users WHERE attendance_status = 'Active' ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'assets' => $assets,
                'logs' => $logs,
                'departments' => $departments,
                'users' => $users
            ]);
            break;

        // ==========================================
        // ACTION 2: ADD OR UPDATE ASSET
        // ==========================================
        case 'save_asset':
            $asset_id = $_POST['asset_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'General');
            $quantity = (int)($_POST['quantity'] ?? 1);
            $department_id = $_POST['managing_department_id'] ?? null;
            $location = trim($_POST['storage_location'] ?? '');
            $condition = $_POST['condition_rating'] ?? 'Good';
            
            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'Asset name is required.']);
                exit;
            }

            // Optional: Generate a unique hash for QR code generation if new
            $qr_hash = empty($asset_id) ? bin2hex(random_bytes(16)) : null;

            if (empty($asset_id)) {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO assets (name, category, quantity, managing_department_id, storage_location, condition_rating, qr_code_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $category, $quantity, $department_id, $location, $condition, $qr_hash]);
                $msg = "Asset successfully registered in the database.";
                $action_type = "added to";
            } else {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE assets SET name=?, category=?, quantity=?, managing_department_id=?, storage_location=?, condition_rating=? WHERE id=?");
                $stmt->execute([$name, $category, $quantity, $department_id, $location, $condition, $asset_id]);
                $msg = "Asset details successfully updated.";
                $action_type = "updated in";
            }

            // NOTIFICATION TRIGGER: Alert the HOD and Director of the managing department
            if ($department_id) {
                $leaderStmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ? AND role_in_dept IN ('HOD', 'Director') AND is_active = 1");
                $leaderStmt->execute([$department_id]);
                $leaders = $leaderStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($leaders)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Inventory Update', ?, '/modules/assets/index.php')");
                    $alertMsg = "The asset '{$name}' has been {$action_type} your department's inventory.";
                    foreach ($leaders as $leader_id) {
                        if ($leader_id != $user_id) { // Don't notify the person who is making the change
                            $notifStmt->execute([$leader_id, $alertMsg]);
                        }
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 3: LOG AN ACTION (Check-Out/In, Lost, Maintenance)
        // ==========================================
        case 'log_action':
            $asset_id = $_POST['asset_id'] ?? '';
            $assigned_user_id = $_POST['user_id'] ?? $user_id; // Defaults to the person making the log if not explicitly assigned to someone else
            $log_action = $_POST['log_action'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($asset_id) || empty($log_action)) {
                echo json_encode(['status' => 'error', 'message' => 'Asset and Action Type are required.']);
                exit;
            }

            $pdo->beginTransaction();

            try {
                // Fetch asset info to make notifications actionable
                $assetStmt = $pdo->prepare("SELECT name, managing_department_id FROM assets WHERE id = ?");
                $assetStmt->execute([$asset_id]);
                $assetInfo = $assetStmt->fetch(PDO::FETCH_ASSOC);
                $assetName = $assetInfo ? $assetInfo['name'] : 'An asset';
                $managing_dept = $assetInfo ? $assetInfo['managing_department_id'] : null;

                // 1. Record the Log
                $logStmt = $pdo->prepare("INSERT INTO asset_logs (asset_id, user_id, action, notes) VALUES (?, ?, ?, ?)");
                $logStmt->execute([$asset_id, $assigned_user_id, $log_action, $notes]);

                // 2. Morph the Root Asset Status automatically
                $new_status = 'Available';
                if ($log_action === 'Check-Out') $new_status = 'In_Use';
                if ($log_action === 'Maintenance') $new_status = 'Maintenance';
                if ($log_action === 'Lost') $new_status = 'Lost';

                $updateStmt = $pdo->prepare("UPDATE assets SET current_status = ? WHERE id = ?");
                $updateStmt->execute([$new_status, $asset_id]);

                // NOTIFICATION TRIGGER 1: Alert the user if the asset was assigned to them by an admin
                if ($log_action === 'Check-Out' && $assigned_user_id != $user_id) {
                    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Checked Out', ?, '/modules/assets/index.php')")
                        ->execute([$assigned_user_id, "The asset '{$assetName}' has been checked out to you and is now your responsibility."]);
                }

                // NOTIFICATION TRIGGER 2: Alert Department Leaders if an asset goes missing or breaks down
                if (in_array($log_action, ['Lost', 'Maintenance']) && $managing_dept) {
                    $leaderStmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ? AND role_in_dept IN ('HOD', 'Director') AND is_active = 1");
                    $leaderStmt->execute([$managing_dept]);
                    $leaders = $leaderStmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($leaders)) {
                        $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Status Alert', ?, '/modules/assets/index.php')");
                        foreach ($leaders as $leader_id) {
                            $notifStmt->execute([$leader_id, "Attention: The asset '{$assetName}' has been flagged as {$log_action}."]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => "Asset logged as {$log_action}."]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Assets API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred.']);
}
?>