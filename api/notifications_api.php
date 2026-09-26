<?php
// /api/notifications_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'fetch':
            // FIX: Replaced reused :uid with unique placeholders to prevent PDO parameter count errors
            $stmt = $pdo->prepare("
                SELECT id, title, message, link_url, created_at 
                FROM system_notifications 
                WHERE is_read = 0 
                AND (
                    user_id = :uid1 
                    OR department_id IN (SELECT department_id FROM user_departments WHERE user_id = :uid2 AND is_active = 1)
                    OR role_id IN (SELECT role_id FROM user_roles WHERE user_id = :uid3)
                )
                ORDER BY created_at DESC LIMIT 15
            ");
            
            // Pass the user_id to all three unique placeholders
            $stmt->execute([
                'uid1' => $user_id,
                'uid2' => $user_id,
                'uid3' => $user_id
            ]);
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format timestamps for the UI
            foreach ($notifications as &$n) {
                $time = strtotime($n['created_at']);
                $diff = time() - $time;
                if ($diff < 3600) $n['time_ago'] = floor($diff / 60) . 'm ago';
                elseif ($diff < 86400) $n['time_ago'] = floor($diff / 3600) . 'h ago';
                else $n['time_ago'] = date('M d', $time);
            }

            echo json_encode([
                'status' => 'success', 
                'count' => count($notifications), 
                'data' => $notifications
            ]);
            break;

        case 'mark_read':
            $notif_id = $_POST['id'] ?? '';
            if ($notif_id) {
                // Marks specific notification as read
                $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE id = ?");
                $stmt->execute([$notif_id]);
            }
            echo json_encode(['status' => 'success']);
            break;

        case 'mark_all_read':
            // Marks all direct user notifications as read
            $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (PDOException $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>