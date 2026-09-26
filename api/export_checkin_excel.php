<?php
/**
 * ============================================================================
 * CHECK-IN MONITOR — Excel Export (multi-sheet)
 * File: /api/export_checkin_excel.php
 * ----------------------------------------------------------------------------
 * Exports check-ins for an event as a multi-sheet .xlsx:
 *   Sheet 1 "All Attendees"  - every person, deduplicated by phone
 *   Sheet 2+                - one sheet per day, matching the event's DEFINED
 *                             length (event_date .. end_date). A one-day event
 *                             produces ONLY the "All Attendees" sheet.
 *
 * Uses the EXACT same PhpSpreadsheet API as export_event_excel.php
 * (setCellValue + Coordinate::stringFromColumnIndex + IOFactory).
 *
 * Usage: /api/export_checkin_excel.php?event_id=NNN
 * ============================================================================
 */

require_once '../includes/db.php';

/* ---- Auth Gate (same RBAC as export_event_excel.php) ---- */
if (!isset($_SESSION['user_id'])) { http_response_code(401); die('Unauthorized. Please log in.'); }
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) { http_response_code(403); die('Access Denied. You do not have clearance to export event data.'); }

/* ---- Params ---- */
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$event_id) { http_response_code(400); die('Missing event_id.'); }

/* ---- PhpSpreadsheet (same autoload path as export_event_excel.php) ---- */
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) { die('System configuration error: Vendor autoload missing.'); }
require_once $autoload_path;

/* ---- Event + its defined date span ---- */
$eventStmt = $pdo->prepare("SELECT title, event_date, end_date FROM events WHERE id = ?");
$eventStmt->execute([$event_id]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); die('Event not found.'); }

$startDate = $event['event_date'] ? date('Y-m-d', strtotime($event['event_date'])) : date('Y-m-d');
$endDate   = $event['end_date'] ? date('Y-m-d', strtotime($event['end_date'])) : $startDate;
$dayCount  = (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
if ($dayCount < 1) $dayCount = 1;

$brandBlue = '1E3A8A';
$brandRed  = 'EF4444';

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

/**
 * Branded banner (same as export_event_excel.php) — returns header start row.
 */
function writeBrandedBanner($sheet, $columnCount, $eventTitle, $reportLabel, $brandBlue, $brandRed) {
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', 'CHURCH HOD LEKKI CENTRE');
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandBlue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', $eventTitle . ' - ' . $reportLabel);
    $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandRed]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(22);

    $sheet->mergeCells("A3:{$lastCol}3");
    $sheet->setCellValue('A3', 'Exported on ' . date('F j, Y \a\t g:i A'));
    $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '6B7280'], 'size' => 9],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
    ]);
    return 5;
}

/**
 * Fills a sheet with headers + deduplicated rows, then styles it.
 * Uses setCellValue("A{$row}") — the same API as export_event_excel.php.
 */
function fillCheckinSheet($sheet, $pdo, $event_id, $sheetTitle, $brandBlue, $dateFilter = null) {
    $headers = ['Full Name', 'Phone', 'Type', 'Source', 'Date', 'Time', 'Blessing Ref'];
    $columnCount = count($headers);
    $headerRow = writeBrandedBanner($sheet, $columnCount, $sheetTitle, 'Check-Ins', $brandBlue, 'EF4444');

    foreach ($headers as $i => $label) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$headerRow}", $label);
    }

    $sql = "SELECT full_name, phone, is_member, is_walkin, source, checkin_date,
                   DATE_FORMAT(checked_in_at,'%h:%i %p') AS checkin_time, blessing_ref
            FROM checkins WHERE event_id = :eid";
    $params = [':eid' => $event_id];
    if ($dateFilter !== null) { $sql .= " AND checkin_date = :d"; $params[':d'] = $dateFilter; }
    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dedupe by phone (keep latest)
    $seen = []; $out = [];
    foreach ($rows as $r) { if (isset($seen[$r['phone']])) continue; $seen[$r['phone']] = true; $out[] = $r; }

    $row = $headerRow + 1;
    foreach ($out as $r) {
        $type = ($r['is_member']==1) ? 'Member' : (($r['is_walkin']==1) ? 'Walk-in' : 'Guest');
        $values = [$r['full_name'], $r['phone'], $type, $r['source'], $r['checkin_date'], $r['checkin_time'], $r['blessing_ref']];
        foreach ($values as $i => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            // Write phone (column 2 = B) as TEXT so Excel does NOT convert it to
            // a number / scientific notation (2.34817E+12). Everything else normal.
            if ($i + 1 === 2) {
                $sheet->setCellValueExplicit("{$col}{$row}", (string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue("{$col}{$row}", $val);
            }
        }
        $row++;
    }
    // Style (same as styleTable in export_event_excel.php)
    $lastDataRow = $row - 1;
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);
    // Force the phone column (B) to Text format for the whole data range so Excel
    // does not show long numbers in scientific notation (2.34817E+12).
    if ($lastDataRow > $headerRow) {
        $sheet->getStyle("B" . ($headerRow + 1) . ":B{$lastDataRow}")->getNumberFormat()->setFormatCode('@');
    }
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandBlue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
    ]);
    if ($lastDataRow >= $headerRow + 1) {
        $sheet->getStyle("A" . ($headerRow + 1) . ":{$lastCol}{$lastDataRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
    }
    for ($c = 1; $c <= $columnCount; $c++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }
    $sheet->freezePane('A' . ($headerRow + 1));
}

/* ---- Sheet 1: All Attendees ---- */
$sheet->setTitle('All Attendees');
fillCheckinSheet($sheet, $pdo, $event_id, 'All Attendees', $brandBlue);

/* ---- Per-day sheets (match event's defined length) ---- */
for ($i = 1; $i <= $dayCount; $i++) {
    $d = date('Y-m-d', strtotime($startDate . ' +' . ($i - 1) . ' days'));
    $sheet = $spreadsheet->createSheet();
    $title = 'Day ' . $i . ' ' . date('M j', strtotime($d));
    $sheet->setTitle($title);
    fillCheckinSheet($sheet, $pdo, $event_id, $title, $brandBlue, $d);
}

/* ---- One-day event: remove the single "Day 1" sheet (redundant) ---- */
if ($dayCount === 1) {
    $day1Name = 'Day 1 ' . date('M j', strtotime($startDate));
    $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName($day1Name));
    if ($idx !== null) { $spreadsheet->removeSheetByIndex($idx); }
}

/* ---- Stream ---- */
$safeEventName = preg_replace('/[^A-Za-z0-9_-]/', '_', $event['title']);
$filename = "Checkins_{$safeEventName}_" . date('Ymd') . '.xlsx';

if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
