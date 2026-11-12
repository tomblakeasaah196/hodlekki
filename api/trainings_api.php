<?php
// /api/trainings_api.php
//
// Trainings (the new HOD Academy): one endpoint for the learner's Trainings
// page and the Academy's builder. Every decision lives in
// includes/trainings/actions.php (trainings_action()), checked there against
// the 'trainings' row of the permission matrix; this file only signs the
// request in, checks the CSRF token and reads the input. Item saves arrive as
// multipart (an audio file or a PDF may come with them); everything else is
// form-encoded.

require_once '../includes/db.php';
require_once '../includes/campaigns/bootstrap.php';
require_once '../includes/trainings/bootstrap.php';
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
api_require_can('trainings', 'view');
csrf_require();

try {
    $out = trainings_action($pdo, (string) ($_POST['action'] ?? ''), $_POST, $_FILES, (int) $_SESSION['user_id']);
    if (($out['code'] ?? '') === 'FORBIDDEN') {
        http_response_code(403);
    }
    // PR 3: a live class's Meet follows its item (moved, retitled, removed).
    if (($out['status'] ?? '') === 'success' && function_exists('training_live_after_action')) {
        training_live_after_action($pdo, (string) ($_POST['action'] ?? ''), $_POST);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[trainings] action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
