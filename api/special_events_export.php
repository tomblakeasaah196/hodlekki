<?php
// /api/special_events_export.php
//
// Streams the attendee workbook (guide §18.6).
//
// A separate GET endpoint rather than a Studio action, because a binary
// .xlsx cannot travel inside the {status, message, data} envelope every
// other action uses (logged as a deviation in §28.4). The Studio's
// `attendees_export` action returns this URL and the browser follows it,
// the same pattern as api/export_event_excel.php.
//
// The workbook itself is built by includes/special_events/export.php; this
// file is only the gate and the response.
//
// Gate: ERP session + the `attendee.export` capability on THIS event. Phone
// numbers and emails are only written for a caller who also holds
// `attendee.pii`; otherwise they are masked (§19.1). Every export is audited.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';

/** Plain-text failure: the caller is a browser following a link, not JSON. */
function se_export_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $message . "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    se_export_fail(405, 'This endpoint only accepts GET.');
}
if (!isset($_SESSION['user_id'])) {
    se_export_fail(401, 'Please sign in to continue.');
}

$userId = (int) $_SESSION['user_id'];

$event = se_event_find_by_public_id($pdo, se_str($_GET['event'] ?? '', 12));
if (!$event) {
    se_export_fail(404, 'That event no longer exists.');
}

$eventId = (int) $event['id'];
if (!se_has_capability($pdo, $eventId, 'attendee.export', $userId)) {
    se_export_fail(403, 'You do not have permission to export this list.');
}
if (!se_table_exists($pdo, 'se_registrations')) {
    se_export_fail(503, 'The attendee tables are not migrated yet.');
}
if (!se_export_boot()) {
    se_export_fail(500, 'Spreadsheet support is not installed. Run composer install.');
}

$withPii = se_has_capability($pdo, $eventId, 'attendee.pii', $userId);
$meta    = [];

try {
    $spreadsheet = se_export_attendees($pdo, $event, $withPii, $meta);
} catch (Throwable $e) {
    error_log('SE export/build: ' . $e->getMessage());
    se_export_fail(500, 'The list could not be read. Please try again.');
}

try {
    se_audit($pdo, $eventId, 'attendees_export',
        ['rows' => $meta['rows'] ?? 0, 'pii' => $withPii], 'event', $eventId, $userId);
} catch (Throwable $e) {
    error_log('SE export/audit: ' . $e->getMessage());
}

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . se_export_attendees_filename($event) . '"');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
exit;
