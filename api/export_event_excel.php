<?php
// /api/export_event_excel.php
// Streams a branded .xlsx workbook for one event - either the full registrants
// list or the full attendance log. Accessed as a direct link (not AJAX), same
// pattern as includes/requisition_pdf.php, so the browser downloads the file.

require_once '../includes/db.php';

// 1. Auth Gate (mirrors events_api.php's RBAC - this export contains member PII)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}

$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) {
    http_response_code(403);
    die('Access Denied. You do not have clearance to export event data.');
}

// 2. Params
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$type = $_GET['type'] ?? '';

if (!$event_id || !in_array($type, ['registrants', 'attendance'], true)) {
    http_response_code(400);
    die('Missing or invalid export parameters.');
}

// 3. PhpSpreadsheet (already installed via Composer for regions_api.php's export)
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) {
    die('System configuration error: Vendor autoload missing. Run composer install.');
}
require_once $autoload_path;

// 4. Fetch the event
$eventStmt = $pdo->prepare("SELECT title, event_date, end_date FROM events WHERE id = ?");
$eventStmt->execute([$event_id]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    die('Event not found.');
}

$brandBlue = '1E3A8A'; // matches the app's hodBlue
$brandRed  = 'EF4444'; // matches the app's hodRed

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

/**
 * Writes the two-row branded banner (church name + report title) and returns
 * the row number the column headers should start on.
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

    return 5; // headers start here; row 4 is left blank as a spacer
}

/**
 * Styles the header row (blue fill, white bold text) and the data rows below
 * it (thin borders + light banding on alternate rows), then auto-sizes columns
 * and freezes the header row.
 */
function styleTable($sheet, $headerRow, $lastDataRow, $columnCount, $brandBlue) {
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);

    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandBlue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(20);

    if ($lastDataRow >= $headerRow + 1) {
        $sheet->getStyle("A" . ($headerRow + 1) . ":{$lastCol}{$lastDataRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);

        for ($r = $headerRow + 1; $r <= $lastDataRow; $r++) {
            if (($r - $headerRow) % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
                ]);
            }
        }
    }

    for ($c = 1; $c <= $columnCount; $c++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    $sheet->freezePane('A' . ($headerRow + 1));
}

$safeEventName = preg_replace('/[^A-Za-z0-9_-]/', '_', $event['title']);
$safeSheetName = substr(preg_replace('/[^A-Za-z0-9 \-]/', '', $event['title']), 0, 24);

if ($type === 'registrants') {
    // ---------------------------------------------------------------------
    // REGISTRANTS WORKBOOK
    // ---------------------------------------------------------------------
    $sheet->setTitle($safeSheetName . ' Reg');

    // Every custom question the admin attached to this event becomes its own column
    $fieldsStmt = $pdo->prepare("SELECT id, field_label FROM event_custom_fields WHERE event_id = ? ORDER BY id ASC");
    $fieldsStmt->execute([$event_id]);
    $customFields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC);

    $baseHeaders = ['#', 'Full Name', 'Type', 'Phone', 'Email', 'Gender', 'Attending Day(s)', 'Match Status', 'Registered On'];
    $customHeaders = array_map(function ($f) { return $f['field_label']; }, $customFields);
    $headers = array_merge($baseHeaders, $customHeaders);
    $columnCount = count($headers);

    $headerRow = writeBrandedBanner($sheet, $columnCount, $event['title'], 'Pre-Registered Attendees', $brandBlue, $brandRed);

    foreach ($headers as $i => $label) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$headerRow}", $label);
    }

    $regStmt = $pdo->prepare("
        SELECT r.*, u.first_name, u.last_name, u.gender as member_gender, u.phone as member_phone, u.email as member_email
        FROM event_registrations r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.event_id = ?
        ORDER BY r.registered_at ASC
    ");
    $regStmt->execute([$event_id]);
    $registrants = $regStmt->fetchAll(PDO::FETCH_ASSOC);

    $dayStmt = $pdo->prepare("SELECT attendance_date FROM event_registration_days WHERE registration_id = ? ORDER BY attendance_date ASC");

    $row = $headerRow + 1;
    $n = 1;
    foreach ($registrants as $r) {
        $isMember = !empty($r['user_id']);
        $name = $isMember ? trim($r['first_name'] . ' ' . $r['last_name']) : $r['guest_name'];
        $phone = $isMember ? $r['member_phone'] : $r['guest_phone'];
        $email = $isMember ? $r['member_email'] : $r['guest_email'];
        $gender = $isMember ? $r['member_gender'] : $r['guest_gender'];

        $dayStmt->execute([$r['id']]);
        $days = $dayStmt->fetchAll(PDO::FETCH_COLUMN);
        $daysLabel = $days ? implode(', ', array_map(function ($d) { return date('M j', strtotime($d)); }, $days)) : '-';

        $matchLabel = '-';
        if ($r['match_status'] === 'confirmed') {
            $matchLabel = 'Confirmed Member Match';
        } elseif ($r['match_status'] === 'pending') {
            $matchLabel = 'Pending Review (' . round($r['match_confidence']) . '%)';
        } elseif ($r['match_status'] === 'rejected') {
            $matchLabel = 'Reviewed - Not a Match';
        }

        $registeredOn = $r['registered_at'] ? date('M j, Y g:i A', strtotime($r['registered_at'])) : '-';

        $values = [$n, $name, $isMember ? 'Member' : 'Guest', $phone ?: '-', $email ?: '-', $gender ?: '-', $daysLabel, $matchLabel, $registeredOn];

        $customResponses = json_decode($r['custom_responses'] ?? '{}', true) ?: [];
        foreach ($customFields as $f) {
            $values[] = $customResponses[$f['id']] ?? '-';
        }

        foreach ($values as $i => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$row}", is_array($val) ? implode(', ', $val) : $val);
        }

        $row++;
        $n++;
    }

    styleTable($sheet, $headerRow, $row - 1, $columnCount, $brandBlue);
    $filename = "Registrants_{$safeEventName}_" . date('Ymd') . '.xlsx';

} else {
    // ---------------------------------------------------------------------
    // ATTENDANCE WORKBOOK
    // ---------------------------------------------------------------------
    $sheet->setTitle($safeSheetName . ' Att');

    $headers = ['#', 'Full Name', 'Gender', 'Spiritual Status', 'Phone', 'Check-In Time', 'Checked In By'];
    $columnCount = count($headers);

    $headerRow = writeBrandedBanner($sheet, $columnCount, $event['title'], 'Attendance Log', $brandBlue, $brandRed);

    foreach ($headers as $i => $label) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$headerRow}", $label);
    }

    $attStmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.gender, u.spiritual_status, u.phone, a.check_in_time,
               admin.first_name as admin_fname, admin.last_name as admin_lname
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN users admin ON a.checked_in_by = admin.id
        WHERE a.event_id = ? AND a.status = 'Present'
        ORDER BY a.check_in_time ASC
    ");
    $attStmt->execute([$event_id]);
    $attendees = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    $row = $headerRow + 1;
    $n = 1;
    foreach ($attendees as $a) {
        $adminName = $a['admin_fname'] ? trim($a['admin_fname'] . ' ' . $a['admin_lname']) : 'Self / System';
        $checkIn = $a['check_in_time'] ? date('M j, Y g:i A', strtotime($a['check_in_time'])) : '-';

        $values = [$n, trim($a['first_name'] . ' ' . $a['last_name']), $a['gender'] ?: '-', $a['spiritual_status'] ?: '-', $a['phone'] ?: '-', $checkIn, $adminName];

        foreach ($values as $i => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$row}", $val);
        }
        $row++;
        $n++;
    }

    styleTable($sheet, $headerRow, $row - 1, $columnCount, $brandBlue);
    $filename = "Attendance_{$safeEventName}_" . date('Ymd') . '.xlsx';
}

// 5. Stream the workbook
if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;