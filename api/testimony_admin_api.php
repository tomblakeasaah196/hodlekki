<?php
// /api/testimony_admin_api.php
//
// Testimonies admin (modules/testimonies/index.php, assets/ui/js/pages/testimonies-admin.js).
// Access: the 'testimonies' row of Roles & permissions. View reads the list,
// Edit formats, publishes and flags for Sunday, Delete removes one. Writes
// need the CSRF token.
//
// Formatted text is cleaned with an allowlist (includes/html_allowlist.php)
// when it is saved and again when it is read: the public wall shows it as
// HTML, so only paragraphs, breaks, bold, italic, underline, strike and lists
// ever reach it, with no attributes.

require_once '../includes/db.php';
require_once '../includes/html_allowlist.php';
csrf_require();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

api_require_can('testimonies', 'view');
$levels = [
    'save_edited_testimony' => 'edit',
    'toggle_service_selection' => 'edit',
    'delete_testimony' => 'delete',
];
if (isset($levels[$action])) {
    api_require_can('testimonies', $levels[$action]);
}
$statuses = ['Published', 'Pending_Editing', 'Hidden'];

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH ADMIN DASHBOARD (Analytics & Master List)
        // =====================================================================================
        case 'fetch_dashboard':
            $analytics = $pdo->query("
                SELECT
                    COUNT(*) as total_testimonies,
                    SUM(CASE WHEN status = 'Pending_Editing' THEN 1 ELSE 0 END) as pending_edits,
                    SUM(CASE WHEN is_selected_for_service = 1 THEN 1 ELSE 0 END) as selected_for_service,
                    SUM(CASE WHEN voice_note_url IS NOT NULL THEN 1 ELSE 0 END) as total_voice_notes
                FROM testimonies
            ")->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->query("
                SELECT t.id, t.user_id, t.guest_name, t.content_text, t.voice_note_url, t.privacy_level,
                       t.request_editing, t.status, t.is_selected_for_service, t.created_at,
                       u.first_name as member_fname, u.last_name as member_lname,
                       (SELECT COUNT(*) FROM testimony_comments c WHERE c.testimony_id = t.id) as comment_count
                FROM testimonies t
                LEFT JOIN users u ON t.user_id = u.id
                ORDER BY t.created_at DESC
            ");
            $testimonies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Leadership sees who asked for anonymity (the wall never does).
            foreach ($testimonies as &$t) {
                if ($t['user_id']) {
                    $t['display_name'] = trim($t['member_fname'] . ' ' . $t['member_lname']);
                    $t['submitter_type'] = 'Church Member';
                } else {
                    $t['display_name'] = $t['guest_name'] ?: 'Unknown Guest';
                    $t['submitter_type'] = 'Public Guest';
                }
                // The editor's starting point: cleaned, and plain text keeps its line breaks.
                $raw = (string) ($t['content_text'] ?? '');
                $clean = hod_html_allowlist($raw);
                $t['content_html'] = str_contains($raw, '<') ? $clean : nl2br($clean, false);
                unset($t['content_text'], $t['member_fname'], $t['member_lname']);
            }
            unset($t);

            echo json_encode([
                'status' => 'success',
                'analytics' => $analytics,
                'testimonies' => $testimonies
            ]);
            break;

        // =====================================================================================
        // ACTION 2: EDIT & APPROVE TESTIMONY (formatted text, allowlisted)
        // =====================================================================================
        case 'save_edited_testimony':
            $testimony_id = (int) ($_POST['testimony_id'] ?? 0);
            $content = hod_html_allowlist($_POST['content_text'] ?? '');
            $status = $_POST['status'] ?? 'Published';

            if ($testimony_id <= 0 || hod_html_is_blank($content)) {
                exit(json_encode(['status' => 'error', 'field' => 'content_text', 'message' => 'Content cannot be empty.']));
            }
            if (!in_array($status, $statuses, true)) {
                exit(json_encode(['status' => 'error', 'field' => 'status', 'message' => 'Unknown status.']));
            }

            $stmt = $pdo->prepare("UPDATE testimonies SET content_text = ?, status = ? WHERE id = ?");
            $stmt->execute([$content, $status, $testimony_id]);

            echo json_encode(['status' => 'success', 'message' => 'Testimony formatted and published successfully.']);
            break;

        // =====================================================================================
        // ACTION 3: TOGGLE LIVE SERVICE SELECTION
        // =====================================================================================
        case 'toggle_service_selection':
            $testimony_id = (int) ($_POST['testimony_id'] ?? 0);
            $current_state = $_POST['current_state'] ?? 0;
            $new_state = $current_state == 1 ? 0 : 1;

            if ($testimony_id <= 0) exit(json_encode(['status' => 'error', 'message' => 'Missing ID.']));

            $pdo->prepare("UPDATE testimonies SET is_selected_for_service = ? WHERE id = ?")->execute([$new_state, $testimony_id]);

            $msg = $new_state == 1 ? 'Flagged for Live Sunday Service!' : 'Removed from Live Service roster.';
            echo json_encode(['status' => 'success', 'message' => $msg, 'selected' => $new_state]);
            break;

        // =====================================================================================
        // ACTION 4: DELETE TESTIMONY
        // =====================================================================================
        case 'delete_testimony':
            $testimony_id = (int) ($_POST['testimony_id'] ?? 0);
            if ($testimony_id <= 0) exit(json_encode(['status' => 'error', 'message' => 'Missing ID.']));

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
