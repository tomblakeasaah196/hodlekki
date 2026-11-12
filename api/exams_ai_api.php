<?php
// /api/exams_ai_api.php
//
// Trainings AI help (modules/exams/ai.php, assets/ui/js/pages/exams-ai.js), on
// Gemini. JSON in and out; multipart when a PDF comes with the request (it is
// read, sent to Gemini inline and never stored). Every action and access
// decision is exams_ai_action() in includes/exams/ai_actions.php.
//
//   status · questions · outline · outline_apply · summary · summary_apply · prompts · prompts_apply
//
// Guide: docs/EXAMS.md §7. Only training content staff choose is sent; never learner records.

require_once '../includes/db.php';
require_once '../includes/exams/ai_actions.php';
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
// Access: the Academy's staff (Trainings, Edit, church-wide), checked again in exams_ai_action().
api_require_can('trainings', 'edit', 'church');
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

$in = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 1024 * 1024), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The request could not be read.']);
        exit;
    }
}

try {
    if (!exams_ready($pdo)) {
        echo json_encode(['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'Exams are being set up. Try again later.']);
        exit;
    }
    $out = exams_ai_action($pdo, (string) ($in['action'] ?? ''), $in, (int) $_SESSION['user_id'], $_FILES);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[exams-ai] action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
