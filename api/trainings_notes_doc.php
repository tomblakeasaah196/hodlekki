<?php
// /api/trainings_notes_doc.php?t=<training id>
//
// Download My notes on a training as a Word file (includes/trainings/notes.php,
// includes/docx.php). Only the signed-in person's own note, never anyone
// else's: there is no user parameter. GET, no change made, so no CSRF token.

require_once '../includes/db.php';
require_once '../includes/trainings/bootstrap.php';

$fail = static function (int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
};

$me = (int) ($_SESSION['user_id'] ?? 0);
if ($me <= 0) {
    $fail(401, 'Please sign in again.');
}
if (!trainings_notes_ready($pdo)) {
    $fail(503, 'Notes are being set up. Try again later.');
}
if (!can('trainings', 'view', perm_person($me))) {
    $fail(403, 'Trainings is not open to you.');
}
$t = trainings_get($pdo, (int) ($_GET['t'] ?? 0));
$note = $t !== null ? trainings_note_get($pdo, (int) $t['id'], $me) : null;
if ($t === null || $note === null || trim($note['body']) === '') {
    $fail(404, 'There are no notes on this training yet.');
}
try {
    $doc = trainings_note_docx($t, trainings_person_brief($pdo, $me)['name'], $note);
} catch (Throwable $ex) {
    error_log('[trainings] notes Word file failed: ' . $ex->getMessage());
    $fail(503, 'The Word file could not be made just now. Try again later.');
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . docx_filename('My notes - ' . $t['title']) . '"');
header('Content-Length: ' . strlen($doc));
header('Cache-Control: private, no-store');
echo $doc;
