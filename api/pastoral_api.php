<?php
// /api/pastoral_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Permission Helper
$is_leadership = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director']);
$is_super_admin = ($active_role === 'Super_Admin');

try {
    switch ($action) {

        // ==========================================
        // ACTION: FETCH DATA (Universal for all 3 tabs)
        // ==========================================
        case 'fetch_tab_data':
            $tab = $_GET['tab'] ?? 'qa'; // qa, suggestions, or impressions
            
            $table_map = [
                'qa' => ['table' => 'pastor_qa', 'text_col' => 'question'],
                'suggestions' => ['table' => 'suggestions', 'text_col' => 'content'],
                'impressions' => ['table' => 'impressions', 'text_col' => 'content']
            ];

            $config = $table_map[$tab];
            $table = $config['table'];
            $text_col = $config['text_col'];

            // Query Construction:
            // Leadership sees everything. Others see only their own.
            $sql = "SELECT t.*, u.first_name, u.last_name, u.phone 
                    FROM $table t 
                    LEFT JOIN users u ON t.user_id = u.id ";

            if (!$is_leadership) {
                $sql .= " WHERE t.user_id = :uid ";
                $params = ['uid' => $user_id];
            } else {
                $sql .= " ORDER BY t.priority DESC, t.submitted_at DESC ";
                $params = [];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Privacy Filter: If anonymous and requester isn't leadership, hide names
            foreach ($results as &$row) {
                if (isset($row['is_anonymous']) && $row['is_anonymous'] == 1 && !$is_leadership) {
                    $row['first_name'] = "Anonymous";
                    $row['last_name'] = "";
                    $row['phone'] = "Hidden";
                }
            }

            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        // ==========================================
        // ACTION: SUBMIT (QA / Suggestion / Impression)
        // ==========================================
        case 'submit_entry':
            $type = $_POST['type']; // qa, suggestion, impression
            $text = trim($_POST['content'] ?? '');
            $anon = isset($_POST['is_anonymous']) ? 1 : 0;

            if (empty($text)) {
                echo json_encode(['status' => 'error', 'message' => 'Content cannot be empty.']);
                exit;
            }

            if ($type === 'qa') {
                $stmt = $pdo->prepare("INSERT INTO pastor_qa (user_id, is_anonymous, question, status) VALUES (?, ?, ?, 'Pending')");
                $stmt->execute([$user_id, $anon, $text]);
                $type_label = "Q&A Question";
            } 
            elseif ($type === 'suggestion') {
                $stmt = $pdo->prepare("INSERT INTO suggestions (user_id, is_anonymous, content, status) VALUES (?, ?, ?, 'New')");
                $stmt->execute([$user_id, $anon, $text]);
                $type_label = "Suggestion";
            } 
            elseif ($type === 'impression') {
                $period_type = $_POST['period_type'] ?? 'Quarterly';
                $period_label = $_POST['period_label'] ?? '';
                $stmt = $pdo->prepare("INSERT INTO impressions (user_id, content, period_type, period_label, status) VALUES (?, ?, ?, ?, 'New')");
                $stmt->execute([$user_id, $text, $period_type, $period_label]);
                $type_label = "Spiritual Impression";
            }

            // NOTIFICATION TRIGGER: Alert Pastoral Leadership of the new submission
            $pastorStmt = $pdo->query("
                SELECT user_id FROM user_roles 
                JOIN roles ON user_roles.role_id = roles.id 
                WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor', 'Assoc_Pastor')
            ");
            $pastors = $pastorStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($pastors)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Pastoral Desk Entry', ?, '/modules/pastoral/index.php')");
                $alertMessage = "A new {$type_label} has been submitted to the Pastoral Desk and requires your attention.";
                
                foreach($pastors as $p_id) {
                    if ($p_id != $user_id) { // Don't notify them if they submitted it themselves
                        $notifStmt->execute([$p_id, $alertMessage]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Thank you. Your entry has been securely submitted.']);
            break;

        // ==========================================
        // ACTION: MANAGE (Priority & Status - Super Admin / Pastor Only)
        // ==========================================
        case 'update_entry':
            if (!$is_leadership) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized management attempt.']);
                exit;
            }

            $tab = $_POST['tab'];
            $id = $_POST['id'];
            $table = ($tab === 'qa') ? 'pastor_qa' : (($tab === 'suggestions') ? 'suggestions' : 'impressions');
            
            // Priority update (Strictly Super Admin as requested)
            if (isset($_POST['priority'])) {
                if (!$is_super_admin) {
                    echo json_encode(['status' => 'error', 'message' => 'Only Super Admins can manage priorities.']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE $table SET priority = ? WHERE id = ?");
                $stmt->execute([$_POST['priority'], $id]);
            }

            // Status update (Leadership)
            if (isset($_POST['status'])) {
                $new_status = $_POST['status'];
                
                // Fetch the original submitter BEFORE we update
                $uStmt = $pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
                $uStmt->execute([$id]);
                $submitter_id = $uStmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE $table SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);

                // NOTIFICATION TRIGGER: Inform the person who submitted it that the status changed
                if ($submitter_id && $submitter_id != $user_id) {
                    $type_label = ($tab === 'qa') ? 'Q&A question' : (($tab === 'suggestions') ? 'suggestion' : 'spiritual impression');
                    
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Pastoral Desk Update', ?, '/modules/member_portal/index.php')");
                    $alertMsg = "Your submitted {$type_label} has been reviewed and marked as '{$new_status}'.";
                    $notifStmt->execute([$submitter_id, $alertMsg]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Record updated successfully.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Pastoral API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database operation failed.']);
}