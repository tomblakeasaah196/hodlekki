<?php
// /api/automation_api.php
//
// The Automation tab's actions (Embrace, Assimilation, Charis), for people with
// the module's manage right. Every action runs through automation_tab_action()
// (includes/campaigns/automation_tab.php); answers that change the panel carry
// it again, server-rendered, for assets/ui/js/pages/automation.js to swap in.
//
// POST action=panel|save|switch_preview|enable|disable|test|run_people|email_doc|save_email, module=…

require_once '../includes/db.php';
require_once '../includes/campaigns/automation_tab.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Form posts, or JSON (the email editor sends its document as a JSON tree).
$in = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The request could not be read.']);
        exit;
    }
}
$module = (string) ($in['module'] ?? $_GET['module'] ?? '');
if (!isset(AUTOMATION_KINDS[$module])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown module.']);
    exit;
}
// Access: the module's "manage" right (its Automation tab) — Roles & permissions.
api_require_can($module, 'manage');
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

try {
    echo json_encode(automation_tab_action($pdo, $module, (string) ($in['action'] ?? ''), $in, (int) $_SESSION['user_id']));
} catch (Throwable $e) {
    error_log('[automation] tab action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
