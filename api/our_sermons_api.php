<?php
// /api/our_sermons_api.php
//
// Our sermons: Envision's manager (paste a link, check drafts, publish, hide,
// delete, the RSS feeds). Every decision lives in
// includes/our_sermons/actions.php (our_sermons_action()), checked there
// against the 'our_sermons' row of the permission matrix; this file only
// signs the request in, checks the CSRF token and reads the input.

require_once '../includes/db.php';
require_once '../includes/our_sermons/bootstrap.php';
header('Content-Type: application/json');
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
api_require_can('our_sermons', 'create', 'church');
csrf_require();

try {
    $out = our_sermons_action($pdo, (string) ($_POST['action'] ?? ''), $_POST, (int) $_SESSION['user_id']);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[our_sermons] action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
