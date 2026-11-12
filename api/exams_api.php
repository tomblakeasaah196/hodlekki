<?php
// /api/exams_api.php
//
// Trainings exams and the question bank (modules/exams/, assets/ui/js/pages/exams.js).
// Every action and access decision is exams_action() in includes/exams/access.php.
//
//   GET  action=template                         the Excel template (.xlsx)
//   POST questions · question_save · question_status · import_check (multipart) · import_confirm
//        setup · exam_save · results · attempt · end_attempt         (tutors)
//        take · start · answer · focus · submit                      (enrolled learners)
//
// Guide: docs/EXAMS.md.

require_once '../includes/db.php';
require_once '../includes/exams/access.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
    exit;
}
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

$me = (int) $_SESSION['user_id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['action'] ?? '') === 'template') {
    // Access: anyone who manages a question bank (Trainings, Manage).
    if (exams_banks($pdo) === []) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You do not have access to this.';
        exit;
    }
    $bytes = exam_template_bytes();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="questions-template.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
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
    // Access: Trainings Manage (tutors) or an enrolment (learners), decided per action.
    $out = exams_action($pdo, (string) ($in['action'] ?? ''), $in, $me, $_FILES);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[exams] action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
