<?php
// /includes/assimilation_export_excel.php
// Branded Excel exports for the Assimilation Find people list.

/**
 * Convert common Nigerian phone-number spellings to E.164.
 * Invalid or missing values are intentionally left blank rather than guessed.
 */
function assim_export_normalize_phone(mixed $value): string
{
    if (!is_string($value) && !is_int($value)) {
        return '';
    }

    $digits = preg_replace('/\D+/', '', trim((string) $value)) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    // Common local spellings: 0803..., 803..., +234 (0) 803..., or 234803...
    if (strlen($digits) === 14 && str_starts_with($digits, '2340')) {
        $digits = '234' . substr($digits, 4);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = '234' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && preg_match('/^[789]/', $digits)) {
        $digits = '234' . $digits;
    }

    return preg_match('/^234[789][01]\d{8}$/', $digits) ? '+' . $digits : '';
}

/** Join first and last name into one clean, single-line cell. */
function assim_export_full_name(array $row): string
{
    $parts = [];
    foreach (['first_name', 'last_name'] as $key) {
        $part = preg_replace('/\s+/u', ' ', trim((string) ($row[$key] ?? '')));
        if ($part !== null && $part !== '') {
            $parts[] = $part;
        }
    }

    return implode(' ', $parts);
}

/** Use the recorded date, or the requested 2025 fallback when none is known. */
function assim_export_last_service(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '2025';
    }

    $ymd = substr($date, 0, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$parsed || $parsed->format('Y-m-d') !== $ymd) {
        return '2025';
    }

    return $parsed->format('j M Y');
}

/**
 * The general file is a clean four-column register. The detailed file keeps
 * the existing assimilation metrics and adds gender plus the joined name.
 */
function assim_export_columns(string $reportType): array
{
    $core = [
        ['label' => 'Full Name', 'field' => 'full_name'],
        ['label' => 'Phone Number', 'field' => 'phone'],
        ['label' => 'Gender', 'field' => 'gender'],
        ['label' => 'Last Service Attended', 'field' => 'last_service'],
    ];

    if ($reportType === 'general') {
        return $core;
    }

    return [
        $core[0],
        $core[1],
        $core[2],
        ['label' => 'Spiritual Status', 'field' => 'spiritual_status'],
        ['label' => 'Departments', 'field' => 'departments'],
        ['label' => 'Region', 'field' => 'region'],
        $core[3],
        ['label' => 'How Long Ago', 'field' => 'how_long_ago'],
        ['label' => 'Services in Selected Window', 'field' => 'services_in_window'],
        ['label' => 'Services in Previous Window', 'field' => 'services_previous'],
        ['label' => 'Total Services', 'field' => 'total_services'],
        ['label' => 'Case Status', 'field' => 'case_status'],
        ['label' => 'Assigned To', 'field' => 'assignee_name'],
    ];
}

/** Return a safe, human-readable cell value for one workbook column. */
function assim_export_cell_value(array $row, string $field): string|int
{
    return match ($field) {
        'full_name' => assim_export_full_name($row),
        'phone' => assim_export_normalize_phone($row['phone'] ?? ''),
        'gender' => trim((string) ($row['gender'] ?? '')) !== ''
            ? str_replace('_', ' ', trim((string) $row['gender']))
            : 'Not recorded',
        'last_service' => assim_export_last_service(isset($row['last_attended']) ? (string) $row['last_attended'] : null),
        'spiritual_status' => trim((string) ($row['spiritual_status'] ?? '')) !== ''
            ? str_replace('_', ' ', trim((string) $row['spiritual_status']))
            : 'Not recorded',
        'departments' => trim((string) ($row['departments'] ?? '')) !== ''
            ? trim((string) $row['departments'])
            : 'Not recorded',
        'region' => trim((string) ($row['region_name'] ?? '')) !== ''
            ? trim((string) $row['region_name'])
            : 'Not recorded',
        'how_long_ago' => !empty($row['last_attended'])
            ? assim_since_words((string) $row['last_attended'])
            : 'No 2026+ record',
        'services_in_window' => (int) ($row['services_in_window'] ?? 0),
        'services_previous' => (int) ($row['services_previous'] ?? 0),
        'total_services' => (int) ($row['total_services'] ?? 0),
        'case_status' => !empty($row['case_status'])
            ? assim_status_words((string) $row['case_status'])
            : 'No active case',
        'assignee_name' => trim((string) ($row['assignee_name'] ?? '')) !== ''
            ? trim((string) $row['assignee_name'])
            : 'Unassigned',
        default => '',
    };
}

/** Build and stream a branded .xlsx workbook for the validated current rule. */
function assim_export_stream_excel(PDO $pdo, array $rule, string $reportType): void
{
    if (!in_array($reportType, ['general', 'detailed'], true)) {
        throw new InvalidArgumentException('Choose a valid Assimilation report type.');
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('The Excel engine is not installed. Please run composer install.');
    }
    require_once $autoload;

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('The Excel engine is not available. Please run composer install.');
    }

    $query = assim_find_query($rule, [
        'order' => 'att.last_attended IS NULL, att.last_attended ASC, u.first_name, u.last_name',
    ]);
    $stmt = $pdo->prepare($query['sql']);
    $stmt->execute($query['params']);

    $columns = assim_export_columns($reportType);
    $columnCount = count($columns);
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);
    $headerRow = 6;
    $dataRow = $headerRow + 1;
    $rowCount = 0;
    $brandBlue = '1D356A';
    $brandRed = 'D11920';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $reportLabel = $reportType === 'general' ? 'General Register' : 'Detailed Register';
    $spreadsheet->getProperties()
        ->setCreator('Household of David Lekki Centre')
        ->setLastModifiedBy('Assimilation')
        ->setTitle('Household of David — Assimilation ' . $reportLabel)
        ->setSubject('Assimilation register')
        ->setDescription('Branded Assimilation report generated from the current Find people filters.');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($reportType === 'general' ? 'General Register' : 'Detailed Register');
    $sheet->setShowGridlines(false);
    $sheet->getSheetView()->setZoomScale(90);

    // Logo sits in the white left block; the branded headings align alongside it.
    $sheet->mergeCells("B1:{$lastColumn}1");
    $sheet->mergeCells("B2:{$lastColumn}2");
    $sheet->mergeCells("A3:{$lastColumn}3");
    $sheet->mergeCells("A4:{$lastColumn}4");
    $sheet->setCellValue('B1', 'HOUSEHOLD OF DAVID');
    $sheet->setCellValue('B2', 'LEKKI CENTRE  •  ASSIMILATION — ' . strtoupper($reportLabel));
    $sheet->setCellValue('A4', 'Phones are normalized to Nigerian +234; a blank means no valid mobile is on file. “2025” means no last-service date is recorded; attendance records in this system begin in 2026.');

    $sheet->getStyle("A1:A2")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('FFFFFF');
    $sheet->getStyle("B1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandBlue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("B2:{$lastColumn}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandRed]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A3:{$lastColumn}3")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => '4B5563']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
        'font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => '374151']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF3FB']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(32);
    $sheet->getRowDimension(2)->setRowHeight(23);
    $sheet->getRowDimension(3)->setRowHeight(25);
    $sheet->getRowDimension(4)->setRowHeight(36);
    $sheet->getRowDimension(5)->setRowHeight(9);

    $logoPath = dirname(__DIR__) . '/assets/images/logo_hod.png';
    if (is_file($logoPath)) {
        $logo = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $logo->setName('Household of David logo');
        $logo->setDescription('Household of David Lekki Centre');
        $logo->setPath($logoPath);
        $logo->setHeight(47);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(5);
        $logo->setOffsetY(3);
        $logo->setWorksheet($sheet);
    }

    foreach ($columns as $index => $column) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $headerRow;
        $sheet->setCellValueExplicit($cell, $column['label'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ($columns as $index => $column) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $dataRow;
            $value = assim_export_cell_value($row, $column['field']);
            if (is_int($value)) {
                $sheet->setCellValue($cell, $value);
            } else {
                // Explicit strings keep phone numbers and profile data as text,
                // and prevent spreadsheet formula execution from user fields.
                $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $rowCount++;
        $dataRow++;
    }

    $generated = date('j F Y \\a\\t g:i A');
    $sheet->setCellValueExplicit(
        'A3',
        'Generated ' . $generated . '  •  ' . number_format($rowCount) . ' people  •  ' . assim_rule_summary($rule),
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $brandBlue]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D7DCE5']],
            'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => $brandRed]],
        ],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(32);

    $lastDataRow = max($headerRow, $dataRow - 1);
    if ($rowCount > 0) {
        $sheet->getStyle('A' . ($headerRow + 1) . ":{$lastColumn}{$lastDataRow}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR, 'color' => ['rgb' => 'E2E8F0']]],
        ]);

        for ($rowNumber = $headerRow + 1; $rowNumber <= $lastDataRow; $rowNumber++) {
            if (($rowNumber - $headerRow) % 2 === 0) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F5F8FC');
            }
            $sheet->getRowDimension($rowNumber)->setRowHeight(21);
        }

        if ($reportType === 'detailed') {
            $caseStatusColumn = 'L';
            for ($rowNumber = $headerRow + 1; $rowNumber <= $lastDataRow; $rowNumber++) {
                $status = (string) $sheet->getCell($caseStatusColumn . $rowNumber)->getValue();
                $statusColor = match ($status) {
                    'To call' => ['FEE2E2', '991B1B'],
                    'Reached' => ['FEF3C7', '92400E'],
                    'Promised to come' => ['DBEAFE', '1E40AF'],
                    'Returned home' => ['DCFCE7', '166534'],
                    default => ['F3F4F6', '4B5563'],
                };
                $sheet->getStyle($caseStatusColumn . $rowNumber)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $statusColor[1]]],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusColor[0]]],
                ]);
            }
        }
    } else {
        $sheet->mergeCells('A7:' . $lastColumn . '7');
        $sheet->setCellValueExplicit('A7', 'No people matched the current Find people filters.', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A7:' . $lastColumn . '7')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);
    }

    $widths = $reportType === 'general'
        ? [34, 21, 17, 25]
        : [34, 21, 17, 23, 30, 20, 25, 23, 22, 23, 16, 27, 28];
    foreach ($widths as $index => $width) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastDataRow}");
    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToPage(true)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
    $sheet->getHeaderFooter()->setOddFooter('&LHousehold of David Lekki Centre&RPage &P of &N');
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);

    $dateForFilename = date('Y-m-d');
    $filenameType = $reportType === 'general' ? 'General' : 'Detailed';
    $filename = 'HOD_Lekki_Assimilation_' . $filenameType . '_' . $dateForFilename . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    header('Pragma: public');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
}
