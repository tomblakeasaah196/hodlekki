<?php
// /includes/special_events/export.php
//
// PhpSpreadsheet exports (guide §18.6, §8.7).
//
// This file only *builds* workbooks. Sending one is the job of an endpoint
// (api/special_events_export.php), because a binary .xlsx cannot travel
// inside the {status, message, data} envelope the Studio API uses.
//
// PhpSpreadsheet is NOT loaded here: the `use` statements below are plain
// aliases, resolved only when a function actually runs, so this file costs
// nothing on a request that never exports. The caller requires
// vendor/autoload.php first — se_export_available() says whether it can.

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** The HOD brand, as the two hex fills a workbook can use. */
const SE_EXPORT_BLUE = '1D356A';
const SE_EXPORT_RED  = 'D11920';

/** True when composer's autoloader is present, i.e. exports can be built. */
function se_export_available(): bool
{
    return class_exists(Spreadsheet::class)
        || is_file(dirname(__DIR__, 2) . '/vendor/autoload.php');
}

/** Loads composer's autoloader once. Returns false if it is not installed. */
function se_export_boot(): bool
{
    if (class_exists(Spreadsheet::class)) {
        return true;
    }

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return false;
    }
    require_once $autoload;

    return class_exists(Spreadsheet::class);
}

/** `slug-attendees-20261024-1705.xlsx`. */
function se_export_attendees_filename(array $event): string
{
    return (string) $event['slug'] . '-attendees-' . se_now()->format('Ymd-Hi') . '.xlsx';
}

/**
 * The attendee workbook: one sheet of people, one of the numbers a Producer
 * quotes in a meeting (§18.6).
 *
 * Phone numbers and emails are written in full only when $withPii is true —
 * i.e. the caller holds `attendee.pii` (§19.1); otherwise they are masked.
 *
 * @param array{rows?: int} $meta  filled with how many rows were written
 */
function se_export_attendees(PDO $pdo, array $event, bool $withPii, array &$meta = []): Spreadsheet
{
    $eventId = (int) $event['id'];

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

    $meta['rows'] = count($rows);

    $formFields = se_form_fields($pdo, $eventId);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Household of David Lekki Centre')
        ->setTitle(se_export_event_title($event) . ' — attendees');

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
            // Explicit string, or Excel turns "08031234567" into 8031234567.
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($i + 1) . $r,
                $value,
                DataType::TYPE_STRING
            );
        }
    }

    se_export_style($sheet, $headerRow, $r, count($headers));

    $summary = $spreadsheet->createSheet();
    $summary->setTitle('Summary');

    $counts = se_event_counts($pdo, $eventId);
    $summaryRows = [
        ['Event', se_export_event_title($event)],
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

    return $spreadsheet;
}

// ==========================================================================
// Helpers
// ==========================================================================

/** "Chara 2026", from the title and the optional edition label. */
function se_export_event_title(array $event): string
{
    return trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''));
}

/** The branded banner rows; returns the row the headers go on. */
function se_export_banner($sheet, int $columns, array $event, bool $withPii): int
{
    $last = Coordinate::stringFromColumnIndex($columns);

    $sheet->mergeCells("A1:{$last}1");
    $sheet->setCellValue('A1', 'CHURCH HOD LEKKI CENTRE');
    $sheet->getStyle("A1:{$last}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => SE_EXPORT_BLUE]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $sheet->mergeCells("A2:{$last}2");
    $sheet->setCellValue('A2', se_export_event_title($event) . ' — Attendees');
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
