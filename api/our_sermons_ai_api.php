<?php
// /api/our_sermons_ai_api.php
//
// Our sermons PR S3: Envision's Regenerate, and the AI line's state, for a
// sermon draft (includes/our_sermons/ai.php). JSON in and out for a page
// script; a classic form post (the line our_sermons_ai_panel() draws) is sent
// back to the page it came from, with the answer kept for that line.
//
//   regenerate   {id, force?}   queue the draft for the AI again (force: over 2 hours too)
//   status       {id}           the AI line's state
//
// Access: Envision (can('our_sermons', 'edit', 'church')). Every POST carries
// the session's CSRF token. The AI only ever fills a draft; it never publishes.

require_once '../includes/db.php';
require_once '../includes/our_sermons/ai.php';
header('Cache-Control: no-store');

$isJson = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0;
$reply = static function (array $out, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
};

if (empty($_SESSION['user_id'])) {
    $reply(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $reply(['status' => 'error', 'message' => 'Use POST.'], 405);
}
// Access: those who manage sermons (Envision), church-wide.
api_require_can('our_sermons', 'edit', 'church');
// Writes (every POST) must carry the session's CSRF token (includes/csrf.php).
csrf_require();

$in = $_POST;
if ($isJson) {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 64 * 1024), true);
    if (!is_array($in)) {
        $reply(['status' => 'error', 'message' => 'The request could not be read.'], 400);
    }
}
$id = (int) ($in['id'] ?? 0);

try {
    if (!our_sermons_ai_ready($pdo)) {
        $out = ['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'AI drafts are being set up. Try again later.'];
    } else {
        switch ((string) ($in['action'] ?? '')) {
            case 'regenerate':
                $r = our_sermons_ai_regenerate($pdo, $id, (int) $_SESSION['user_id'], !empty($in['force']));
                $out = ['status' => $r['ok'] ? 'success' : 'error', 'message' => $r['message']];
                if ($r['ok'] && ($s = our_sermons_ai_sermon($pdo, $id)) !== null) {
                    $out['ai'] = our_sermons_ai_state($pdo, $s);
                }
                break;
            case 'status':
                $s = our_sermons_ai_sermon($pdo, $id);
                $out = $s !== null ? ['status' => 'success', 'ai' => our_sermons_ai_state($pdo, $s)]
                    : ['status' => 'error', 'code' => 'NOT_FOUND', 'message' => 'That sermon no longer exists.'];
                break;
            default:
                $reply(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }
    }
} catch (Throwable $e) {
    error_log('[sermons-ai] api: ' . $e->getMessage());
    $out = ['status' => 'error', 'message' => 'Something went wrong. Please try again.'];
}

if (!$isJson) {
    // A form post: back to the page (a path on this site only), the answer shown once on the AI line.
    $_SESSION['our_sermons_ai_notice'][$id] = (string) ($out['message'] ?? '');
    $back = (string) ($in['back'] ?? '');
    header('Location: ' . (preg_match('~^/(?![/\\\\])[^\s\\\\]*$~', $back) ? $back : '/'), true, 303);
    exit;
}
$reply($out);
