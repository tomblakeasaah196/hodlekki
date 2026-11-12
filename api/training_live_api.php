<?php
// /api/training_live_api.php
/**
 * Trainings' live classes on Meet (docs/INTEGRATIONS.md, "Live classes"). POST JSON, CSRF checked.
 *
 *   join                 a learner pressed Join class (own records: enrolled on the training)
 *   meet_create          make the Meet for a live item        ┐
 *   meet_update          send its title and time again        │
 *   meet_cancel          remove the Meet                      │ the Academy: can('trainings', 'edit', 'church')
 *   sync                 bring attendance in from Meet        │
 *   present              mark a learner present or not        │
 *   assign / ignore / reopen   a "Needs a look" participant   ┘
 */

require_once '../includes/db.php';
require_once '../includes/trainings/bootstrap.php';

use HOD\TrainingLive\Live;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function training_live_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    training_live_out(401, ['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    training_live_out(405, ['status' => 'error', 'message' => 'Use POST.']);
}
csrf_require();
if (!class_exists(Live::class) || !Live::ready($pdo)) {
    training_live_out(503, ['status' => 'error', 'message' => 'Live classes are being set up. Try again after the next update.']);
}
$in = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$action = (string) ($in['action'] ?? '');
$item = (int) ($in['item'] ?? 0);
session_write_close();

try {
    if ($action === 'join') {
        if (!can('trainings', 'view', perm_person($uid))) {
            training_live_out(403, ['status' => 'error', 'message' => 'Trainings is not open to you.']);
        }
        $r = Live::join($pdo, $item, $uid);
    } else {
        if (!can('trainings', 'edit', 'church')) {
            training_live_out(403, ['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'Only the Academy can change this.']);
        }
        $r = match ($action) {
            'meet_create' => Live::createMeet($pdo, $item, $uid),
            'meet_update' => Live::updateMeet($pdo, $item),
            'meet_cancel' => Live::cancelMeet($pdo, $item),
            'sync' => Live::sync($pdo, $item),
            'present' => Live::setPresent($pdo, $item, (int) ($in['user'] ?? 0), !empty($in['present']), $uid),
            'assign' => Live::assign($pdo, $item, (int) ($in['row'] ?? 0), (int) ($in['user'] ?? 0), $uid),
            'ignore' => Live::ignore($pdo, $item, (int) ($in['row'] ?? 0), $uid),
            'reopen' => Live::reopen($pdo, $item, (int) ($in['row'] ?? 0)),
            default => ['ok' => false, 'message' => 'Unknown request. Reload the page.'],
        };
    }
} catch (Throwable $e) {
    error_log('[trainings] live action ' . $action . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
    training_live_out(500, ['status' => 'error', 'message' => 'That did not work. Try again.']);
}
if (empty($r['ok'])) {
    training_live_out(400, ['status' => 'error', 'message' => (string) ($r['message'] ?? 'That did not work. Try again.')]);
}
unset($r['ok']);
training_live_out(200, ['status' => 'success', 'message' => (string) ($r['message'] ?? 'Saved.'), 'data' => $r]);
