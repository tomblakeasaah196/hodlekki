<?php
// /api/department_export_excel.php
//
// Department rosters for one ministry year, as a branded workbook (Excel) or a
// plain CSV. Same data the A4 image renderer draws, from the same helper
// (includes/department_helpers.php), so the picture and the spreadsheet can
// never disagree.
//
//   ?ministry_year_id=<id>   defaults to the live year
//   &format=xlsx|csv         defaults to xlsx
//
// Direct link (not AJAX), so the browser performs the download — same pattern
// as api/export_event_excel.php.

require_once '../includes/db.php';
require_once '../includes/department_helpers.php';

// ---------------------------------------------------------------------------
// 1. Auth gate — this file contains member PII, so it mirrors the module's own
//    leadership-only rule.
// ---------------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}

$active_role = $_SESSION['active_role'] ?? 'Member';
if (!in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'], true)) {
    http_response_code(403);
    die('Access Denied. You do not have clearance to export department rosters.');
}

if (!dept_schema_ready($pdo)) {
    http_response_code(503);
    die(dept_schema_message());
}

$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$year_id = (int) ($_GET['ministry_year_id'] ?? 0);
$year = $year_id ? dept_year_by_id($pdo, $year_id) : dept_active_year($pdo);

if (!$year) {
    http_response_code(404);
    die('No ministry year configured.');
}

$data       = dept_report_dataset($pdo, $year);
$heading    = $data['year']['heading'];                 // "Ministry Year 2026"
$churchName = $data['church']['name'];
$flat       = $data['flat'];
$rows       = dept_flat_worker_rows($pdo, (int) $year['id']);

$safeLabel = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $year['label']);
$stamp     = date('Ymd');
$baseName  = "Department_Rosters_{$safeLabel}_{$stamp}";

// ---------------------------------------------------------------------------
// 2. CSV — one flat table, opens anywhere.
// ---------------------------------------------------------------------------
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$baseName}.csv\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens UTF-8 names correctly

    fputcsv($out, [$churchName]);
    fputcsv($out, [$heading]);
    fputcsv($out, ['Generated ' . $data['generated_at']]);
    fputcsv($out, []);

    fputcsv($out, ['#', 'Department', 'Member Type', 'Role', 'Name', 'Gender', 'Phone', 'Email', 'Spiritual Status', 'Joined Department']);
    $n = 0;
    foreach ($rows as $r) {
        $n++;
        fputcsv($out, [
            $n,
            $r['department'],
            in_array($r['role_in_dept'], DEPT_LEADER_ROLES, true) ? 'Leader' : $r['membership_type'],
            DEPT_ROLE_LABELS[$r['role_in_dept']] ?? str_replace('_', ' ', $r['role_in_dept']),
            trim($r['first_name'] . ' ' . $r['last_name']),
            $r['gender'] ?: '-',
            $r['phone'] ?: '-',
            $r['email'] ?: '-',
            $r['spiritual_status'] ?: '-',
            $r['joined_at'] ? date('j M Y', strtotime($r['joined_at'])) : '-',
        ]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// 3. XLSX
// ---------------------------------------------------------------------------
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('System configuration error: vendor/autoload.php missing. Run composer install.');
}
require_once $autoload;


$brandBlue = '0A0E17';   // hodBlue
$brandRed  = 'D11920';   // hodRed

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

/** Masthead: church, ministry year, generated stamp. Returns the header row index. */
function dept_banner($sheet, int $cols, string $church, string $heading, string $generated, string $blue, string $red): int
{
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);

    $sheet->mergeCells("A1:{$last}1");
    $sheet->setCellValue('A1', $church);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $blue);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $sheet->mergeCells("A2:{$last}2");
    $sheet->setCellValue('A2', $heading);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF' . $red);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(2)->setRowHeight(20);

    $sheet->mergeCells("A3:{$last}3");
    $sheet->setCellValue('A3', 'Generated ' . $generated);
    $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setARGB('FF6B7280');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    return 5;   // row 4 stays blank
}

/** Header row + zebra body. */
function dept_table($sheet, int $headerRow, int $lastRow, int $cols, string $blue): void
{
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);

    $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $blue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(20);

    if ($lastRow >= $headerRow) {
        $sheet->getStyle("A{$headerRow}:{$last}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
        ]);
    }

    for ($r = $headerRow + 1; $r <= $lastRow; $r++) {
        if (($r - $headerRow) % 2 === 0) {
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
        }
    }
}

function dept_sheet_name(string $name, array $used): string
{
    $clean = trim(preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $name));
    $clean = preg_replace('/\s+/', ' ', $clean);
    if ($clean === '') {
        $clean = 'Department';
    }
    $clean = mb_substr($clean, 0, 28);
    $candidate = $clean;
    $i = 2;
    while (in_array(mb_strtolower($candidate), $used, true)) {
        $candidate = mb_substr($clean, 0, 26) . ' ' . $i;
        $i++;
    }
    return $candidate;
}

// ---------------------------------------------------------------------------
// 3a. Sheet 1 — Summary (every department, both counts, leadership)
// ---------------------------------------------------------------------------
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Summary');
$headers = ['#', 'Department', 'Type', 'Pastor in Charge', 'Director in Charge', 'Head of Department', 'Primary Members', 'Secondary Members', 'Total'];
$cols = count($headers);
$headerRow = dept_banner($sheet, $cols, $churchName, $heading, $data['generated_at'], $brandBlue, $brandRed);

foreach ($headers as $i => $label) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue("{$col}{$headerRow}", $label);
}

$row = $headerRow + 1;
$n = 0;
foreach ($flat as $dept) {
    $n++;
    $leaders = $dept['leaders'];
    // A sub-unit is shown as "Sub-unit of X" so the sheet reads on its own.
    $label = $dept['parent_name'] ? ('   └ ' . $dept['name'] . ' (under ' . $dept['parent_name'] . ')') : $dept['name'];

    $values = [
        $n,
        $label,
        str_replace('_', ' ', (string) $dept['type']),
        $leaders['Assoc_Pastor']['name'] ?? '—',
        $leaders['Director']['name'] ?? '—',
        $leaders['HOD']['name'] ?? '—',
        $dept['primary_count'],
        $dept['secondary_count'],
        $dept['primary_count'] + $dept['secondary_count'],
    ];
    foreach ($values as $i => $val) {
        if (is_int($val) && $i >= 6) {
            // Keep the three count columns numeric so Excel can total them.
            $sheet->setCellValueExplicit("G{$row}", (string) $values[6], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("H{$row}", (string) $values[7], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("I{$row}", (string) $values[8], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            break;
        }
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValueExplicit("{$col}{$row}", (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $row++;
}

// Grand totals line.
$totalRow = $row;
$sheet->mergeCells('A' . $totalRow . ':F' . $totalRow);
$sheet->setCellValue('A' . $totalRow, 'TOTAL across ' . $data['totals']['departments'] . ' departments');
$sheet->getStyle('A' . $totalRow)->getFont()->setBold(true);
$sheet->setCellValue('G' . $totalRow, (string) $data['totals']['primary']);
$sheet->setCellValue('H' . $totalRow, (string) $data['totals']['secondary']);
$sheet->setCellValue('I' . $totalRow, (string) ($data['totals']['primary'] + $data['totals']['secondary']));
$sheet->getStyle('G' . $totalRow . ':I' . $totalRow)->getFont()->setBold(true);

dept_table($sheet, $headerRow, $row - 1, $cols, $brandBlue);
$sheet->getStyle('G' . $totalRow . ':I' . $totalRow)->getFont()->setBold(true);

foreach (['A' => 5, 'B' => 38, 'C' => 16, 'D' => 22, 'E' => 22, 'F' => 22, 'G' => 10, 'H' => 12, 'I' => 8] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// ---------------------------------------------------------------------------
// 3b. One sheet per master department: pastors, leadership, Primary, Secondary
// ---------------------------------------------------------------------------
$used = ['summary'];
foreach ($flat as $dept) {
    $title = dept_sheet_name($dept['name'], $used);
    $used[] = mb_strtolower($title);

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($title);

    $headers = ['#', 'Member Type', 'Role', 'Name', 'Gender', 'Phone', 'Email', 'Joined'];
    $cols = count($headers);
    $headerRow = dept_banner($sheet, $cols, $churchName, $heading, $data['generated_at'], $brandBlue, $brandRed);

    // Department subtitle
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);
    $sheet->mergeCells("A4:{$last}4");
    $sheet->setCellValue('A4', $dept['name'] . ($dept['parent_name'] ? ' (sub-unit of ' . $dept['parent_name'] . ')' : ''));
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(12);

    foreach ($headers as $i => $label) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$headerRow}", $label);
    }

    $row = $headerRow + 1;
    $n = 0;

    // 1. Leadership seats
    foreach (DEPT_LEADER_ROLES as $role) {
        $leader = $dept['leaders'][$role] ?? null;
        if (!$leader) {
            continue;
        }
        $n++;
        $values = [$n, 'Leader', DEPT_ROLE_LABELS[$role], $leader['name'], $leader['gender'] ?: '-', $leader['phone'] ?: '-', '-', '-'];
        foreach ($values as $i => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$row}", (string) $val);
        }
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        $row++;
    }

    // 2. Primary then Secondary members
    foreach ([['Primary', $dept['primary']], ['Secondary', $dept['secondary']]] as [$type, $members]) {
        foreach ($members as $m) {
            $n++;
            $values = [
                $n,
                $type,
                $m['sub_unit_head'] ? 'Sub-Unit Head' : 'Member',
                $m['name'],
                $m['gender'] ?: '-',
                $m['phone'] ?: '-',
                $m['email'] ?: '-',
                $m['joined'] ?: '-',
            ];
            foreach ($values as $i => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValueExplicit("{$col}{$row}", (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row++;
        }
    }

    if ($row === $headerRow + 1) {
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $sheet->setCellValue("A{$row}", 'No one is on this roster yet for ' . $heading . '.');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        $row++;
    }

    dept_table($sheet, $headerRow, $row - 1, $cols, $brandBlue);

    foreach (['A' => 5, 'B' => 14, 'C' => 20, 'D' => 30, 'E' => 10, 'F' => 18, 'G' => 30, 'H' => 14] as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }
    $sheet->freezePane('A' . ($headerRow + 1));
}

// ---------------------------------------------------------------------------
// 3c. Sheet — All Workers (one row per person per department)
// ---------------------------------------------------------------------------
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('All Workers');
$headers = ['#', 'Department', 'Member Type', 'Role', 'Name', 'Gender', 'Phone', 'Email', 'Spiritual Status', 'Joined'];
$cols = count($headers);
$headerRow = dept_banner($sheet, $cols, $churchName, $heading, $data['generated_at'], $brandBlue, $brandRed);

foreach ($headers as $i => $label) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue("{$col}{$headerRow}", $label);
}

$row = $headerRow + 1;
$n = 0;
foreach ($rows as $r) {
    $n++;
    $values = [
        $n,
        $r['department'],
        in_array($r['role_in_dept'], DEPT_LEADER_ROLES, true) ? 'Leader' : $r['membership_type'],
        DEPT_ROLE_LABELS[$r['role_in_dept']] ?? str_replace('_', ' ', $r['role_in_dept']),
        trim($r['first_name'] . ' ' . $r['last_name']),
        $r['gender'] ?: '-',
        $r['phone'] ?: '-',
        $r['email'] ?: '-',
        $r['spiritual_status'] ?: '-',
        $r['joined_at'] ? date('j M Y', strtotime($r['joined_at'])) : '-',
    ];
    foreach ($values as $i => $val) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValueExplicit("{$col}{$row}", (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $row++;
}

dept_table($sheet, $headerRow, $row - 1, $cols, $brandBlue);
foreach (['A' => 5, 'B' => 30, 'C' => 12, 'D' => 18, 'E' => 28, 'F' => 10, 'G' => 18, 'H' => 30, 'I' => 16, 'J' => 14] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}
$sheet->freezePane('A' . ($headerRow + 1));

$spreadsheet->setActiveSheetIndex(0);

// ---------------------------------------------------------------------------
// 4. Stream it
// ---------------------------------------------------------------------------
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$baseName}.xlsx\"");
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
