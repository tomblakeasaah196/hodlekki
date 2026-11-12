<?php
// /api/campaigns_api.php
//
// Email Campaigns (modules/campaigns/, assets/ui/js/pages/campaigns.js). JSON
// in (the email is a JSON tree, never HTML) and out. Every action and every
// access decision is campaign_action() in includes/campaigns/campaign_actions.php.
//
//   list · options · get · mailbox · save · count · email_doc · save_email ·
//   preview · test · send · unschedule · cancel · delete · copy · report ·
//   settings · settings_save
//
// Guide: docs/EMAIL.md, "Campaigns".

require_once '../includes/db.php';
require_once '../includes/campaigns/campaign_actions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
    exit;
}
// Access: Email Campaigns, view (Roles & permissions); each action checks the campaign itself.
api_require_can('campaigns', 'view');
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

$in = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The request could not be read.']);
        exit;
    }
}

try {
    if (!campaigns_module_ready($pdo)) {
        echo json_encode(['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'Email is being set up. Try again later.']);
        exit;
    }
    $out = campaign_action($pdo, (string) ($in['action'] ?? ''), $in, (int) $_SESSION['user_id']);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[campaigns] action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
