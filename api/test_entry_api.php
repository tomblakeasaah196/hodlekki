<?php
// /api/test_entry_api.php
//
// The QR entry to a standalone test (test.php, assets/ui/js/pages/test-entry.js): the phone number, the welcome, the
// password (or a first one), the email, a forgotten password by emailed code; and, after the test, confirming the
// email with a code. Public: the way in works before anyone is signed in. POST only, CSRF checked (the page's token),
// JSON in and out. Every step and its checks: includes/trainings/entry.php. Guide: docs/EXAMS.md §9.

require_once '../includes/db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/campaigns/bootstrap.php';
require_once '../includes/trainings/bootstrap.php';
require_once '../includes/trainings/entry.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Use POST.']);
    exit;
}
csrf_require();

$in = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 64 * 1024), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The request could not be read.']);
        exit;
    }
}

try {
    if (!trainings_ready($pdo) || !function_exists('exams_ready') || !exams_ready($pdo)) {
        echo json_encode(['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'Tests open after the next update.']);
        exit;
    }
    echo json_encode(trainings_entry_action($pdo, (string) ($in['action'] ?? ''), $in, security_client_ip()));
} catch (Throwable $e) {
    error_log('[test entry] ' . ($in['action'] ?? '?') . ' failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
