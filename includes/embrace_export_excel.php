<?php
// /includes/embrace_export_excel.php
// Branded Excel register for the Embrace first-timer pipeline. Follows the
// Assimilation watchlist export (includes/assimilation_export_excel.php): the
// same title block, church logo, brand colours, zebra rows, frozen header,
// auto-filter, landscape print setup and footer.

require_once __DIR__ . '/embrace_helpers.php';
require_once __DIR__ . '/assimilation_export_excel.php';

/** Column layout. Every person from the date range appears, pushed or not. */
function embrace_export_columns(): array
{
    return [
        ['label' => 'S/N', 'width' => 8],
        ['label' => 'First Name', 'width' => 18],
        ['label' => 'Last Name', 'width' => 18],
        ['label' => 'Phone', 'width' => 16],
        ['label' => 'Email', 'width' => 28],
        ['label' => 'Gender', 'width' => 11],
        ['label' => 'Marital Status', 'width' => 15],
        ['label' => 'Anniversary', 'width' => 14],
        ['label' => 'Physical Address', 'width' => 34],
        ['label' => 'Source', 'width' => 18],
        ['label' => 'Invited By Details', 'width' => 24],
        ['label' => 'Born Again', 'width' => 11],
        ['label' => 'Wants to Join', 'width' => 13],
        ['label' => 'Visitation Pref.', 'width' => 16],
        ['label' => 'Prayer Requests', 'width' => 34],
        ['label' => 'Comments', 'width' => 30],
        ['label' => 'Follow-up Status', 'width' => 17],
        ['label' => 'Stage', 'width' => 21],
        ['label' => 'Assigned To', 'width' => 30],
        ['label' => 'Notes Logged', 'width' => 12],
        ['label' => 'Notes', 'width' => 70],
        ['label' => 'Date Added', 'width' => 14],
    ];
}

/** Trimmed string from a nullable DB value. */
function embrace_export_text(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

/** Format a stored date/time (Africa/Lagos), or '' when it is missing or invalid. */
function embrace_export_date(?string $value, string $format): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Africa/Lagos')))->format($format);
    } catch (Exception $e) {
        return '';
    }
}

/** One note as a readable block: when, who, visibility tags, then the text. */
function embrace_export_note_block(array $note): string
{
    $when = embrace_export_date($note['created_at'] ?? null, 'j M Y, g:i A');
    $author = trim(($note['first_name'] ?? '') . ' ' . ($note['last_name'] ?? ''));
    if ($author === '') {
        $author = 'Unknown author';
    }

    // "All" is the default and adds nothing; anything else (Pastors, Directors,
    // Assigned Worker) is shown so a reader knows who the author meant it for.
    $tags = [];
    $visibility = json_decode((string) ($note['visible_to'] ?? ''), true);
    if (is_array($visibility)) {
        foreach ($visibility as $tag) {
            $label = str_replace('_', ' ', trim((string) $tag));
            if ($label !== '' && $label !== 'All') {
                $tags[] = $label;
            }
        }
    }

    $heading = ($when !== '' ? $when : 'Date unknown') . '  •  ' . $author;
    if ($tags) {
        $heading .= '  [Visible to: ' . implode(', ', $tags) . ']';
    }

    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($note['note_text'] ?? '')));

    return $heading . "\n" . ($text !== '' ? $text : '(empty note)');
}

/** Estimated row height (points) so wrapped text is not cut off in Excel. */
function embrace_export_row_height(string $text, float $width): float
{
    $charsPerLine = max(10, (int) floor($width * 1.1));
    $lines = 0;
    foreach (explode("\n", $text) as $line) {
        $length = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line);
        $lines += (int) ceil(max(1, $length) / $charsPerLine);
    }

    return min(409.0, max(21.0, $lines * 13.5 + 7.0));
}

/**
 * One row of values in column order. Integers stay numeric; everything else
 * is written as explicit text so phone numbers keep their leading zero and a
 * cell can never run as a spreadsheet formula.
 */
function embrace_export_row_values(array $v, array $notes, array $workerHistory, int $serial, bool $inPipeline): array
{
    $current = embrace_export_text($v['current_worker'] ?? '');
    $earlier = array_values(array_filter($workerHistory, fn($name) => $name !== $current));
    $assigned = $current !== '' ? $current : 'Unassigned';
    if ($earlier) {
        $assigned .= ' (earlier: ' . implode(', ', $earlier) . ')';
    }

    $status = embrace_export_text($v['followup_status'] ?? '');
    $statusLabel = $status !== '' ? str_replace('_', ' ', $status) : 'Unassigned';

    $source = embrace_export_text($v['invitation_source'] ?? '');
    $gender = embrace_export_text($v['gender'] ?? '');
    $marital = embrace_export_text($v['marital_status'] ?? '');

    return [
        $serial,
        embrace_export_text($v['first_name'] ?? ''),
        embrace_export_text($v['last_name'] ?? ''),
        embrace_export_text($v['phone'] ?? ''),
        embrace_export_text($v['email'] ?? ''),
        $gender !== '' ? str_replace('_', ' ', $gender) : 'Not recorded',
        $marital !== '' ? str_replace('_', ' ', $marital) : 'Not recorded',
        embrace_export_date($v['wedding_anniversary'] ?? null, 'j M Y'),
        embrace_export_text($v['physical_address'] ?? ''),
        $source !== '' ? str_replace('_', ' ', $source) : 'Not recorded',
        embrace_export_text($v['invited_by'] ?? ''),
        (int) ($v['is_born_again'] ?? 0) ? 'Yes' : 'No',
        (int) ($v['wants_to_join'] ?? 0) ? 'Yes' : 'No',
        embrace_export_text($v['visitation_preference'] ?? ''),
        embrace_export_text($v['prayer_requests'] ?? ''),
        embrace_export_text($v['comments'] ?? ''),
        $statusLabel,
        $inPipeline ? 'In Embrace pipeline' : 'Pushed to Congregation',
        $assigned,
        count($notes),
        implode("\n\n", array_map('embrace_export_note_block', $notes)),
        embrace_export_date($v['created_at'] ?? null, 'j M Y'),
    ];
}

/** Build and stream a branded .xlsx register for the given date range. */
function embrace_export_stream_excel(PDO $pdo, string $startDate, string $endDate): void
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if (!$start || $start->format('Y-m-d') !== $startDate || !$end || $end->format('Y-m-d') !== $endDate) {
        throw new InvalidArgumentException('Choose a valid start and end date.');
    }
    if ($start > $end) {
        throw new InvalidArgumentException('The start date must be on or before the end date.');
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('The Excel engine is not installed. Please run composer install.');
    }
    require_once $autoload;

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('The Excel engine is not available. Please run composer install.');
    }

    // Everyone added in the range who is still in the pipeline, plus anyone
    // already pushed to the Congregation who went through Embrace (has a
    // follow-up). Previously the pushed people silently dropped out.
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.phone, u.email, u.gender, u.marital_status,
               u.wedding_anniversary, u.physical_address, u.invitation_source, u.invited_by,
               u.is_born_again, u.wants_to_join, u.visitation_preference, u.prayer_requests,
               u.comments, u.created_at, u.spiritual_status,
               (SELECT f.status FROM embrace_followups f
                 WHERE f.visitor_id = u.id ORDER BY f.id DESC LIMIT 1) AS followup_status,
               (SELECT CONCAT_WS(' ', w.first_name, w.last_name) FROM embrace_followups f
                  LEFT JOIN users w ON w.id = f.assigned_worker_id
                 WHERE f.visitor_id = u.id ORDER BY f.id DESC LIMIT 1) AS current_worker
        FROM users u
        WHERE DATE(u.created_at) BETWEEN ? AND ?
          AND (u.spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer')
               OR EXISTS (SELECT 1 FROM embrace_followups ef WHERE ef.visitor_id = u.id))
        ORDER BY u.created_at ASC, u.id ASC
    ");
    $stmt->execute([$startDate, $endDate]);
    $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_map(fn($v) => (int) $v['id'], $visitors);
    $notesByVisitor = embrace_notes_for_visitors($pdo, $ids);
    $workersByVisitor = embrace_worker_history_for_visitors($pdo, $ids);

    $columns = embrace_export_columns();
    $columnCount = count($columns);
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);
    $headerRow = 6;
    $brandBlue = '1D356A';
    $brandRed = 'D11920';

    $columnLetter = function (string $label) use ($columns): string {
        foreach ($columns as $index => $column) {
            if ($column['label'] === $label) {
                return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            }
        }
        return 'A';
    };

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Household of David Lekki Centre')
        ->setLastModifiedBy('Embrace')
        ->setTitle('Household of David — Embrace First Timers Register')
        ->setSubject('Embrace first timers register')
        ->setDescription('Branded Embrace register: first timers, follow-up status, assignments and every follow-up note.');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('First Timers Register');
    $sheet->setShowGridlines(false);
    $sheet->getSheetView()->setZoomScale(90);

    // Title block: logo sits in the white A1:A2 block, headings run alongside it.
    $sheet->mergeCells("B1:{$lastColumn}1");
    $sheet->mergeCells("B2:{$lastColumn}2");
    $sheet->mergeCells("A3:{$lastColumn}3");
    $sheet->mergeCells("A4:{$lastColumn}4");
    $sheet->setCellValue('B1', 'HOUSEHOLD OF DAVID');
    $sheet->setCellValue('B2', 'LEKKI CENTRE  •  EMBRACE — FIRST TIMERS REGISTER');
    $sheet->setCellValueExplicit(
        'A4',
        'Stage shows whether each person is still in the Embrace pipeline or has been pushed to the Congregation. '
        . 'Assigned To is the current follow-up worker, with earlier workers in brackets. Notes lists every follow-up note '
        . 'logged on the person, oldest first, across all assignments. Notes marked Pastors only are included.',
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    $sheet->getStyle('A1:A2')->getFill()
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

    // The logo is optional and skipped with a log line when the runtime cannot
    // inspect images (same fallback as the Assimilation exports).
    assim_export_attach_logo($sheet, dirname(__DIR__) . '/assets/images/logo_hod.png');

    foreach ($columns as $index => $column) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $headerRow;
        $sheet->setCellValueExplicit($cell, $column['label'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

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

    $dataRow = $headerRow + 1;
    $rowCount = 0;
    $pipelineCount = 0;
    $congregationCount = 0;

    foreach ($visitors as $v) {
        $vid = (int) $v['id'];
        $inPipeline = in_array($v['spiritual_status'] ?? '', ['1st_Timer', '2nd_Timer', '3rd_Timer'], true);
        $inPipeline ? $pipelineCount++ : $congregationCount++;

        $values = embrace_export_row_values(
            $v,
            $notesByVisitor[$vid] ?? [],
            $workersByVisitor[$vid] ?? [],
            $rowCount + 1,
            $inPipeline
        );

        $rowHeight = 21.0;
        foreach ($values as $index => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $dataRow;
            if (is_int($value)) {
                $sheet->setCellValue($cell, $value);
            } else {
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $rowHeight = max($rowHeight, embrace_export_row_height((string) $value, (float) $columns[$index]['width']));
            }
        }

        $sheet->getRowDimension($dataRow)->setRowHeight($rowHeight);
        $rowCount++;
        $dataRow++;
    }

    $lastDataRow = max($headerRow, $dataRow - 1);
    $generated = date('j F Y \a\t g:i A');
    $sheet->setCellValueExplicit(
        'A3',
        'Generated ' . $generated . '  •  ' . number_format($rowCount) . ' people ('
            . number_format($pipelineCount) . ' in pipeline, ' . number_format($congregationCount) . ' pushed to Congregation)'
            . '  •  Added ' . $start->format('j M Y') . ' to ' . $end->format('j M Y'),
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    if ($rowCount > 0) {
        $sheet->getStyle('A' . ($headerRow + 1) . ":{$lastColumn}{$lastDataRow}")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR, 'color' => ['rgb' => 'E2E8F0']]],
        ]);

        for ($rowNumber = $headerRow + 1; $rowNumber <= $lastDataRow; $rowNumber++) {
            if (($rowNumber - $headerRow) % 2 === 0) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F5F8FC');
            }
        }

        // Follow-up status and stage get the same colour chips as the Assimilation case status.
        $statusColumn = $columnLetter('Follow-up Status');
        $stageColumn = $columnLetter('Stage');
        for ($rowNumber = $headerRow + 1; $rowNumber <= $lastDataRow; $rowNumber++) {
            $status = strtolower((string) $sheet->getCell($statusColumn . $rowNumber)->getValue());
            $statusColor = match (true) {
                $status === 'completed' => ['DCFCE7', '166534'],
                $status === 'pending' || $status === 'in progress' => ['FEF3C7', '92400E'],
                $status === 'unreachable' => ['FEE2E2', '991B1B'],
                default => ['F3F4F6', '4B5563'],
            };
            $sheet->getStyle($statusColumn . $rowNumber)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $statusColor[1]]],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusColor[0]]],
            ]);

            $stage = (string) $sheet->getCell($stageColumn . $rowNumber)->getValue();
            $stageColor = $stage === 'Pushed to Congregation' ? ['DBEAFE', '1E40AF'] : ['F3F4F6', '4B5563'];
            $sheet->getStyle($stageColumn . $rowNumber)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $stageColor[1]]],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $stageColor[0]]],
            ]);
        }
    } else {
        $sheet->mergeCells('A7:' . $lastColumn . '7');
        $sheet->setCellValueExplicit(
            'A7',
            'No first timers were added between ' . $start->format('j M Y') . ' and ' . $end->format('j M Y') . '.',
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->getStyle('A7:' . $lastColumn . '7')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);
    }

    foreach ($columns as $index => $column) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->getColumnDimension($letter)->setWidth($column['width']);
    }

    // Freeze the header and the S/N + name columns so a wide register stays readable.
    $sheet->freezePane('C7');
    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastDataRow}");
    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3)
        ->setFitToPage(true)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
    $sheet->getHeaderFooter()->setOddFooter('&LHousehold of David Lekki Centre&RPage &P of &N');
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);

    $filename = 'HOD_Lekki_Embrace_Register_' . $start->format('Y-m-d') . '_to_' . $end->format('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    header('Pragma: public');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
}
