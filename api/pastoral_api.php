<?php
// /api/pastoral_api.php
//
// Pastoral Desk (modules/pastoral/index.php, assets/ui/js/pages/pastoral.js):
// Q&A questions, suggestions and impressions for the pastors.
// Access: the 'pastoral' row of Roles & permissions. Everyone may submit
// (Create) and read their own entries; View church-wide reads every entry;
// Edit church-wide sets the status; Manage sets the priority. Writes need the
// CSRF token.
//
// Anonymous means anonymous: the name and phone of an anonymous entry never
// leave the server, except to the person who wrote it.
require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

api_require_can('pastoral');

$user_id = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$is_leadership = can('pastoral', 'view', 'church');
$can_triage = can('pastoral', 'edit', 'church');
$is_super_admin = can('pastoral', 'manage');

// The three boxes. Table names and columns come only from here.
$tabs = [
    'qa' => ['table' => 'pastor_qa', 'text_col' => 'question', 'statuses' => ['Pending', 'Answered', 'Archived'], 'label' => 'Q&A question'],
    'suggestions' => ['table' => 'suggestions', 'text_col' => 'content', 'statuses' => ['New', 'Reviewed', 'Actioned'], 'label' => 'suggestion'],
    'impressions' => ['table' => 'impressions', 'text_col' => 'content', 'statuses' => ['New', 'Reviewed', 'Archived'], 'label' => 'spiritual impression'],
];
$priorities = [1, 5, 10];

try {
    switch ($action) {

        // Every entry for leadership, your own for everyone else.
        case 'fetch_tab_data':
            $tab = $_GET['tab'] ?? 'qa';
            if (!isset($tabs[$tab])) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown section.']);
                exit;
            }
            $table = $tabs[$tab]['table'];

            $sql = "SELECT t.*, u.first_name, u.last_name, u.phone
                    FROM $table t
                    LEFT JOIN users u ON t.user_id = u.id ";
            $params = [];
            if (!$is_leadership) {
                $sql .= " WHERE t.user_id = :uid ";
                $params = ['uid' => $user_id];
            }
            $sql .= " ORDER BY t.priority DESC, t.submitted_at DESC, t.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$row) {
                $mine = (int) $row['user_id'] === $user_id;
                $row['is_mine'] = $mine;
                if (!empty($row['is_anonymous']) && !$mine) {
                    $row['user_id'] = null;
                    $row['first_name'] = null;
                    $row['last_name'] = null;
                    $row['phone'] = null;
                }
                if (!$is_leadership) {
                    $row['phone'] = null;
                }
            }
            unset($row);

            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        case 'submit_entry':
            api_require_can('pastoral', 'create');
            $type = $_POST['type'] ?? '';
            $text = trim($_POST['content'] ?? '');
            $anon = !empty($_POST['is_anonymous']) ? 1 : 0;

            if (!in_array($type, ['qa', 'suggestion', 'impression'], true)) {
                echo json_encode(['status' => 'error', 'field' => 'type', 'message' => 'Choose what this is.']);
                exit;
            }
            if ($text === '') {
                echo json_encode(['status' => 'error', 'field' => 'content', 'message' => 'Content cannot be empty.']);
                exit;
            }
            if (mb_strlen($text) > 5000) {
                echo json_encode(['status' => 'error', 'field' => 'content', 'message' => 'Keep it to 5,000 characters.']);
                exit;
            }

            if ($type === 'qa') {
                $stmt = $pdo->prepare("INSERT INTO pastor_qa (user_id, is_anonymous, question, status) VALUES (?, ?, ?, 'Pending')");
                $stmt->execute([$user_id, $anon, $text]);
                $type_label = "Q&A Question";
            } elseif ($type === 'suggestion') {
                $stmt = $pdo->prepare("INSERT INTO suggestions (user_id, is_anonymous, content, status) VALUES (?, ?, ?, 'New')");
                $stmt->execute([$user_id, $anon, $text]);
                $type_label = "Suggestion";
            } else {
                $period_type = ($_POST['period_type'] ?? '') === 'Yearly' ? 'Yearly' : 'Quarterly';
                $period_label = mb_substr(trim($_POST['period_label'] ?? ''), 0, 50);
                $stmt = $pdo->prepare("INSERT INTO impressions (user_id, content, period_type, period_label, status) VALUES (?, ?, ?, ?, 'New')");
                $stmt->execute([$user_id, $text, $period_type, $period_label]);
                $type_label = "Spiritual Impression";
            }

            // Tell pastoral leadership there is something new.
            $pastors = perm_office_holders($pdo, 'pastoral', 'edit');
            if (!empty($pastors)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Pastoral Desk Entry', ?, '/modules/pastoral/')");
                $alertMessage = "A new {$type_label} has been submitted to the Pastoral Desk and requires your attention.";
                foreach ($pastors as $p_id) {
                    if ($p_id != $user_id) {
                        $notifStmt->execute([$p_id, $alertMessage]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Thank you. Your entry has been securely submitted.']);
            break;

        // Status (Edit church-wide) and priority (Manage).
        case 'update_entry':
            if (!$can_triage) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized management attempt.']);
                exit;
            }
            $tab = $_POST['tab'] ?? '';
            $id = (int) ($_POST['id'] ?? 0);
            if (!isset($tabs[$tab]) || $id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown entry.']);
                exit;
            }
            $table = $tabs[$tab]['table'];

            if (isset($_POST['priority'])) {
                if (!$is_super_admin) {
                    echo json_encode(['status' => 'error', 'message' => 'Only Super Admins can manage priorities.']);
                    exit;
                }
                $priority = (int) $_POST['priority'];
                if (!in_array($priority, $priorities, true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Unknown priority.']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE $table SET priority = ? WHERE id = ?");
                $stmt->execute([$priority, $id]);
            }

            if (isset($_POST['status'])) {
                $new_status = (string) $_POST['status'];
                if (!in_array($new_status, $tabs[$tab]['statuses'], true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Unknown status.']);
                    exit;
                }

                $uStmt = $pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
                $uStmt->execute([$id]);
                $submitter_id = $uStmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE $table SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);

                // Tell the person who wrote it.
                if ($submitter_id && $submitter_id != $user_id) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Pastoral Desk Update', ?, '/modules/member_portal/')");
                    $alertMsg = "Your submitted {$tabs[$tab]['label']} has been reviewed and marked as '{$new_status}'.";
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
