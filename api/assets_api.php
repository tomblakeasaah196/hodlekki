<?php
// /api/assets_api.php
//
// Asset Management (modules/assets/index.php, assets/ui/js/pages/assets.js).
// Access: the 'assets' row of Roles & permissions. View reads the inventory
// and the activity log, Create registers an asset, Edit changes one and logs
// check-outs, check-ins, repairs and losses. Writes need the CSRF token.

require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

// 1. Strict Security Gate
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Clearance: the 'assets' row of the permission matrix (pastors, and the seats
// of the departments that own Asset Management).
$is_new_asset = $action === 'save_asset' && (int) ($_POST['asset_id'] ?? 0) === 0;
$has_clearance = can('assets', 'view')
    && (in_array($action, ['fetch_dashboard'], true) || can('assets', $is_new_asset ? 'create' : 'edit'));

// The fixed lists the page offers; nothing else is stored.
$asset_categories = ['Audio', 'Video', 'Lighting', 'Instruments', 'IT', 'Furniture', 'General'];
$asset_conditions = ['Excellent', 'Good', 'Fair', 'Poor'];
$asset_actions = ['Check-Out' => 'In_Use', 'Check-In' => 'Available', 'Maintenance' => 'Maintenance', 'Lost' => 'Lost'];

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
            $asset_id = (int) ($_POST['asset_id'] ?? 0) ?: '';
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'General');
            $quantity = (int)($_POST['quantity'] ?? 1);
            $department_id = (int) ($_POST['managing_department_id'] ?? 0) ?: null;
            $location = mb_substr(trim($_POST['storage_location'] ?? ''), 0, 150);
            $condition = $_POST['condition_rating'] ?? 'Good';
            
            if ($name === '') {
                echo json_encode(['status' => 'error', 'field' => 'name', 'message' => 'Asset name is required.']);
                exit;
            }
            if (mb_strlen($name) > 150) {
                echo json_encode(['status' => 'error', 'field' => 'name', 'message' => 'Keep the name to 150 characters.']);
                exit;
            }
            if ($quantity < 1) {
                echo json_encode(['status' => 'error', 'field' => 'quantity', 'message' => 'Quantity is at least 1.']);
                exit;
            }
            if (!in_array($category, $asset_categories, true)) {
                // An older asset may carry a category from before the list: keep that one.
                $current = null;
                if ($asset_id) {
                    $cur = $pdo->prepare('SELECT category FROM assets WHERE id = ?');
                    $cur->execute([$asset_id]);
                    $current = $cur->fetchColumn();
                }
                $category = ($current !== null && $current !== false && $category === $current) ? $current : 'General';
            }
            if (!in_array($condition, $asset_conditions, true)) {
                echo json_encode(['status' => 'error', 'field' => 'condition_rating', 'message' => 'Unknown condition.']);
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
                $leaders = perm_department_leaders($pdo, (int) $department_id);

                if (!empty($leaders)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Inventory Update', ?, '/modules/assets/')");
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
            $asset_id = (int) ($_POST['asset_id'] ?? 0);
            // Defaults to the person making the log if not explicitly assigned to someone else
            $assigned_user_id = (int) ($_POST['user_id'] ?? 0) ?: (int) $user_id;
            $log_action = $_POST['log_action'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if ($asset_id <= 0 || !isset($asset_actions[$log_action])) {
                echo json_encode(['status' => 'error', 'field' => 'log_action', 'message' => 'Asset and Action Type are required.']);
                exit;
            }
            $exists = $pdo->prepare('SELECT 1 FROM assets WHERE id = ?');
            $exists->execute([$asset_id]);
            if (!$exists->fetchColumn()) {
                echo json_encode(['status' => 'error', 'message' => 'That asset no longer exists.']);
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
                $new_status = $asset_actions[$log_action];

                $updateStmt = $pdo->prepare("UPDATE assets SET current_status = ? WHERE id = ?");
                $updateStmt->execute([$new_status, $asset_id]);

                // NOTIFICATION TRIGGER 1: Alert the user if the asset was assigned to them by an admin
                if ($log_action === 'Check-Out' && $assigned_user_id != $user_id) {
                    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Checked Out', ?, '/modules/assets/')")
                        ->execute([$assigned_user_id, "The asset '{$assetName}' has been checked out to you and is now your responsibility."]);
                }

                // NOTIFICATION TRIGGER 2: Alert Department Leaders if an asset goes missing or breaks down
                if (in_array($log_action, ['Lost', 'Maintenance']) && $managing_dept) {
                    $leaders = perm_department_leaders($pdo, (int) $managing_dept);

                    if (!empty($leaders)) {
                        $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Asset Status Alert', ?, '/modules/assets/')");
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