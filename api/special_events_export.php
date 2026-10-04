<?php
// /api/special_events_export.php
//
// The attendee workbook (guide §18.6).
//
// A separate GET endpoint rather than a Studio action, because a binary
// .xlsx cannot travel inside the {status, message, data} envelope every
// other action uses (logged as a deviation in §28.4). The Studio's
// `attendees_export` action returns this URL and the browser downloads it,
// the same pattern as api/export_event_excel.php.
//
// Gate: ERP session + the `attendee.export` capability on THIS event. Phone
// numbers and emails are only written for a caller who also holds
// `attendee.pii`; otherwise they are masked (§19.1). Every export is audited.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    se_export_fail(500, 'Spreadsheet support is not installed. Run composer install.');
}
require_once $autoload;

$withPii = se_has_capability($pdo, $eventId, 'attendee.pii', $userId);

// --------------------------------------------------------------------------
// Data
// --------------------------------------------------------------------------

try {
    $stmt = $pdo->prepare(
        "SELECT r.*, c.phone_e164, c.email AS contact_email, c.consent_followup, c.opted_out_at,
                ref.display_name AS referrer_name
           FROM se_registrations r
           LEFT JOIN se_contacts c ON c.id = r.contact_id
           LEFT JOIN se_registrations ref ON ref.id = r.referred_by_registration_id
          WHERE r.event_id = ?
          ORDER BY r.status, r.created_at, r.id"
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('SE export/query: ' . $e->getMessage());
    se_export_fail(500, 'The list could not be read. Please try again.');
}

$formFields = se_form_fields($pdo, $eventId);

// --------------------------------------------------------------------------
// Workbook
// --------------------------------------------------------------------------

const SE_EXPORT_BLUE = '1D356A';
const SE_EXPORT_RED  = 'D11920';

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Household of David Lekki Centre')
    ->setTitle(trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? '')) . ' — attendees');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendees');

$headers = [
    'Reg code', 'Name shown', 'First name', 'Last name', 'Phone', 'Email', 'Gender',
    'Member?', 'Status', 'Pool', 'Channel', 'Source', 'Referred by',
    'Karaoke interest', 'Wants visit', 'Consent', 'Opted out', 'Test row',
    'Registered at', 'Confirmed at', 'Waitlisted at', 'Cancelled at', 'First check-in',
];
foreach ($formFields as $field) {
    $headers[] = (string) $field['label'];
}

$headerRow = se_export_banner($sheet, count($headers), $event, $withPii);

foreach ($headers as $i => $label) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $headerRow, $label);
}

$r = $headerRow;
foreach ($rows as $row) {
    $r++;
    $phone = (string) ($row['phone_e164'] ?? '');
    $email = (string) ($row['email'] ?? $row['contact_email'] ?? '');

    $values = [
        (string) $row['reg_code'],
        (string) $row['display_name'],
        (string) $row['first_name'],
        (string) $row['last_name'],
        $phone === '' ? '' : ($withPii ? se_phone_display($phone) : se_mask_phone($phone)),
        $email === '' ? '' : ($withPii ? $email : se_mask_email($email)),
        (string) ($row['gender'] ?? ''),
        se_bool($row['is_member']) ? 'Member' : 'Guest',
        (string) $row['status'],
        (string) ($row['seat_pool'] ?? ''),
        (string) $row['channel'],
        (string) ($row['src'] ?? ''),
        (string) ($row['referrer_name'] ?? ''),
        se_bool($row['karaoke_interest']) ? 'Yes' : '',
        se_bool($row['wants_visit']) ? 'Yes' : '',
        se_bool($row['consent_followup'] ?? 0) ? 'Yes' : 'No',
        ($row['opted_out_at'] ?? null) !== null ? 'Yes' : '',
        se_bool($row['is_test']) ? 'TEST' : '',
        se_export_time($row['created_at'] ?? null),
        se_export_time($row['confirmed_at'] ?? null),
        se_export_time($row['waitlisted_at'] ?? null),
        se_export_time($row['cancelled_at'] ?? null),
        se_export_time($row['first_checkin_at'] ?? null),
    ];

    $answers = se_json_decode($row['answers_json'] ?? null) ?? [];
    foreach ($formFields as $field) {
        $answer = $answers[(string) $field['field_key']] ?? '';
        $values[] = is_array($answer) ? implode(', ', array_map('strval', $answer)) : (string) $answer;
    }

    foreach ($values as $i => $value) {
        // setCellValueExplicit keeps "08031234567" a string, not 8031234567.
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($i + 1) . $r,
            $value,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
    }
}

se_export_style($sheet, $headerRow, $r, count($headers));

// A second sheet of the numbers the Producer actually quotes in a meeting.
$summary = $spreadsheet->createSheet();
$summary->setTitle('Summary');
$counts = se_event_counts($pdo, $eventId);
$summaryRows = [
    ['Event', trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))],
    ['Exported', se_now()->format('Y-m-d H:i')],
    ['Confirmed', (string) ($counts['confirmed'] ?? 0)],
    ['Waitlisted', (string) ($counts['waitlisted'] ?? 0)],
    ['Cancelled', (string) ($counts['cancelled'] ?? 0)],
    ['Walk-ins', (string) ($counts['walkin'] ?? 0)],
    ['Checked in', (string) ($counts['checked_in'] ?? 0)],
    ['Online capacity', $event['online_capacity'] === null ? 'Unlimited' : (string) (int) $event['online_capacity']],
];
foreach ($summaryRows as $i => [$label, $value]) {
    $summary->setCellValue('A' . ($i + 1), $label);
    $summary->setCellValue('B' . ($i + 1), $value);
}
$summary->getStyle('A1:A' . count($summaryRows))->getFont()->setBold(true);
$summary->getColumnDimension('A')->setAutoSize(true);
$summary->getColumnDimension('B')->setAutoSize(true);

$spreadsheet->setActiveSheetIndex(0);

// --------------------------------------------------------------------------
// Send
// --------------------------------------------------------------------------

try {
    se_audit($pdo, $eventId, 'attendees_export',
        ['rows' => count($rows), 'pii' => $withPii], 'event', $eventId, $userId);
} catch (Throwable $e) {
    error_log('SE export/audit: ' . $e->getMessage());
}

$filename = $event['slug'] . '-attendees-' . se_now()->format('Ymd-Hi') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

(new Xlsx($spreadsheet))->save('php://output');
exit;

// ==========================================================================
// Helpers
// ==========================================================================

/** The branded banner rows; returns the row the headers go on. */
function se_export_banner($sheet, int $columns, array $event, bool $withPii): int
{
    $last = Coordinate::stringFromColumnIndex($columns);
    $title = trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''));

    $sheet->mergeCells("A1:{$last}1");
    $sheet->setCellValue('A1', 'CHURCH HOD LEKKI CENTRE');
    $sheet->getStyle("A1:{$last}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => SE_EXPORT_BLUE]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $sheet->mergeCells("A2:{$last}2");
    $sheet->setCellValue('A2', $title . ' — Attendees');
    $sheet->getStyle("A2:{$last}2")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => SE_EXPORT_RED]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(22);

    $sheet->mergeCells("A3:{$last}3");
    $sheet->setCellValue('A3', 'Exported ' . se_now()->format('F j, Y \a\t g:i A')
        . ($withPii ? ' · contains personal data — handle with care' : ' · phone numbers and emails are masked'));
    $sheet->getStyle("A3:{$last}3")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '6B7280'], 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    return 5;   // row 4 is a spacer
}

/** Header fill, banding, borders, auto-size and a frozen header. */
function se_export_style($sheet, int $headerRow, int $lastRow, int $columns): void
{
    $last = Coordinate::stringFromColumnIndex($columns);

    $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => SE_EXPORT_BLUE]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(20);

    if ($lastRow > $headerRow) {
        $sheet->getStyle('A' . ($headerRow + 1) . ":{$last}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
        $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastRow}");
    }

    for ($c = 1; $c <= $columns; $c++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
    }

    $sheet->freezePane('A' . ($headerRow + 1));
}

/** "2026-10-24 17:05", or an empty cell. */
function se_export_time(?string $sql): string
{
    $when = se_parse_datetime($sql);

    return $when !== null ? $when->format('Y-m-d H:i') : '';
}
