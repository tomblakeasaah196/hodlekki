<?php
// /api/email_editor_api.php
//
// The block editor's actions (assets/ui/js/email-editor.js), for people who
// manage an Automation tab or write campaigns (email_editor_allowed()). JSON in (the document is
// a JSON tree, never HTML) and out; pictures as multipart (action=upload).
//
//   check             validate a document (errors, the cleaned document)
//   draft             keep a validated copy for the preview frame → token
//   upload            a picture → its URL on this site
//   templates / template / template_save / template_archive
//
// Includes: includes/campaigns/editor.php. Guide: docs/EMAIL.md.

require_once '../includes/db.php';
require_once '../includes/campaigns/editor.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
    exit;
}
// Access: the manage right of Embrace, Assimilation or Charis (their
// Automation tabs), or Email Campaigns create — Roles & permissions.
if (!email_editor_allowed()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'You do not have access to this. Ask the church office if you think you should.']);
    exit;
}
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

$in = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $raw = (string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The email could not be read.']);
        exit;
    }
}
$action = (string) ($in['action'] ?? '');

try {
    if (!campaigns_ready($pdo)) {
        echo json_encode(['status' => 'error', 'message' => 'Email is being set up. Try again later.']);
        exit;
    }
    if ($action === 'upload') {
        $r = email_editor_store_image((array) ($_FILES['picture'] ?? []));
        echo json_encode($r['ok'] ? ['status' => 'success'] + $r : ['status' => 'error', 'message' => $r['message']]);
        exit;
    }
    echo json_encode(email_editor_action($pdo, $action, $in, (int) $_SESSION['user_id']));
} catch (Throwable $e) {
    error_log('[email] editor action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
