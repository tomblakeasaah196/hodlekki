<?php
// /api/trainings_certificate.php?e=<enrolment id>
//
// Download a Trainings certificate as a PDF (includes/trainings/certificates.php).
// The learner gets their own; the Academy (view, church-wide) gets anyone's.
// Only the current certificate: a revoked or replaced one is never served.
// GET, no change made, so no CSRF token.

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
if (!trainings_certs_ready($pdo)) {
    $fail(503, 'Certificates are being set up. Try again later.');
}
$e = trainings_enrolment_get($pdo, (int) ($_GET['e'] ?? 0));
$own = $e !== null && $e['user_id'] === $me && can('trainings', 'view', perm_person($me));
if ($e === null || (!$own && !can('trainings', 'view', 'church'))) {
    $fail(403, 'This certificate is not yours to download.');
}
$cert = trainings_certificate_current($pdo, $e['id']);
if ($cert === null) {
    $fail(404, 'There is no certificate for this training yet.');
}
try {
    $pdf = trainings_certificate_pdf($cert, trainings_settings($pdo), trainings_certificate_signers($pdo, $cert));
} catch (Throwable $ex) {
    error_log('[trainings] certificate PDF failed: ' . $ex->getMessage());
    $pdf = null;
}
if ($pdf === null) {
    $fail(503, 'The certificate could not be made just now. Try again later.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Certificate-' . $cert['code'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
