<?php
// /api/our_sermons_listen_api.php
//
// Our sermons, PR S2: the listening page's endpoint (modules/our_sermons/
// sermon.php, assets/ui/js/pages/our-sermon.js). Notes, "carry on where you
// stopped", "Email my notes" and the Bible panel's reference lookup. Every
// decision lives in our_sermon_listen_action() (includes/our_sermons/
// listen.php); this file signs the request in, checks the module and the
// CSRF token and reads the form. POST only, form-encoded (the position saved
// as the page goes away arrives the same way, with keepalive).

require_once '../includes/db.php';
require_once '../includes/campaigns/bootstrap.php';
require_once '../includes/bible/bootstrap.php';
require_once '../includes/our_sermons/listen.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Use POST.']);
    exit;
}
api_require_can('our_sermons', 'view');
csrf_require();

$userId = (int) $_SESSION['user_id'];
// Nothing below writes the session: release it so a slow email never blocks
// the member's other tabs.
session_write_close();

try {
    $out = our_sermon_listen_action($pdo, (string) ($_POST['action'] ?? ''), $_POST, $userId);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[our_sermons] listen action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
