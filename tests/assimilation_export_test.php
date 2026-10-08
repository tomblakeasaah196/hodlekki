<?php
// /tests/assimilation_export_test.php
// Regression checks for the Assimilation Excel-export formatting helpers, and
// for the optional-logo fallback added after the 2026-10-08 production
// failure: the web SAPI has no ext-fileinfo, PhpSpreadsheet's
// Drawing::setPath() calls mime_content_type(), and both branded workbooks
// died with "Call to undefined function … mime_content_type()".
//
// Run: php tests/assimilation_export_test.php
// The workbook checks need Composer's vendor/ (composer install); without it
// the helper checks still run — CI lints the tree without installing packages.

require_once __DIR__ . '/../includes/assimilation_helpers.php';
require_once __DIR__ . '/../includes/assimilation_export_excel.php';

$checks = 0;

function expect_export_value($expected, $actual, string $message): void
{
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

foreach ([
    ['0803 123 4567', '+2348031234567'],
    ['803-123-4567', '+2348031234567'],
    ['+234 (0) 803 123 4567', '+2348031234567'],
    ['00234 803 123 4567', '+2348031234567'],
    ['2348031234567', '+2348031234567'],
    ['', ''],
    ['not a phone', ''],
    ['+1 202 555 0111', ''],
] as [$input, $expected]) {
    expect_export_value($expected, assim_export_normalize_phone($input), 'Nigerian phone normalization for ' . var_export($input, true));
}

expect_export_value('Ada Maria Nwosu', assim_export_full_name([
    'first_name' => '  Ada   Maria ',
    'last_name' => "Nwosu\n",
]), 'full name is joined and whitespace is cleaned');
expect_export_value('Ada', assim_export_full_name(['first_name' => 'Ada', 'last_name' => '']), 'name works when last name is blank');
expect_export_value('2025', assim_export_last_service(null), 'missing service date uses requested 2025 fallback');
expect_export_value('4 Jan 2026', assim_export_last_service('2026-01-04'), 'recorded service date is readable');
expect_export_value('2025', assim_export_last_service('not-a-date'), 'invalid service date falls back safely');

$general = assim_export_columns('general');
expect_export_value(['Full Name', 'Phone Number', 'Gender', 'Last Service Attended'], array_column($general, 'label'), 'general report has the agreed four-column layout');
expect_export_value(false, in_array('Case Status', array_column($general, 'label'), true), 'general report omits case-management columns');

$detailedLabels = array_column(assim_export_columns('detailed'), 'label');
expect_export_value(true, in_array('Case Status', $detailedLabels, true), 'detailed report includes case status');
expect_export_value(true, in_array('Assigned To', $detailedLabels, true), 'detailed report includes assigned volunteer');
expect_export_value('No active case', assim_export_cell_value([], 'case_status'), 'missing case status is labelled clearly');
expect_export_value('Unassigned', assim_export_cell_value([], 'assignee_name'), 'missing assignee is labelled clearly');
expect_export_value('2025', assim_export_cell_value([], 'last_service'), 'empty attendance maps to 2025 in workbook');

// ---------------------------------------------------------------------------
// Optional logo — the export must survive a runtime without ext-fileinfo.
// ---------------------------------------------------------------------------

$logoPath = dirname(__DIR__) . '/assets/images/logo_hod.png';

expect_export_value(
    function_exists('mime_content_type') && function_exists('getimagesize'),
    assim_export_can_inspect_images(),
    'the image-inspection probe matches what this PHP runtime can actually call'
);
expect_export_value('', assim_export_logo_skip_reason($logoPath, true), 'the logo is embeddable when the runtime can inspect images');
expect_export_value(
    true,
    str_contains(assim_export_logo_skip_reason($logoPath, false), 'ext-fileinfo'),
    'the no-fileinfo runtime explains why the logo is skipped — this is the production case'
);
expect_export_value(
    true,
    assim_export_logo_skip_reason($logoPath . '.missing', true) !== '',
    'a logo file that is not there is reported instead of attempted'
);

// The streamer must embed the logo through the guarded helper: a bare
// Drawing() in that function is exactly what took both exports down.
$exportSource = (string) file_get_contents(__DIR__ . '/../includes/assimilation_export_excel.php');
$streamerSource = '';
foreach (preg_split('/\n(?=function )/', $exportSource) ?: [] as $chunk) {
    if (str_starts_with($chunk, 'function assim_export_stream_excel(')) {
        $streamerSource = $chunk;
        break;
    }
}
expect_export_value(true, $streamerSource !== '', 'the workbook streamer is found in the export helper');
expect_export_value(true, str_contains($streamerSource, 'assim_export_attach_logo('), 'the workbook streamer embeds the logo through the guarded helper');
expect_export_value(
    false,
    str_contains($streamerSource, 'new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing('),
    'the workbook streamer never builds a Drawing directly'
);
expect_export_value(false, str_contains($streamerSource, 'mime_content_type('), 'the workbook streamer never calls mime_content_type() itself');

// ---------------------------------------------------------------------------
// Workbook behaviour. The logo-skipping branch is the production case, so it
// runs wherever PhpSpreadsheet is; the log lines it writes are expected.
// ---------------------------------------------------------------------------

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDOUT, "Assimilation Excel export helpers OK ({$checks} checks). Workbook checks skipped — run composer install to include them.\n");
    exit(0);
}

require_once $autoload;

$brandBlue = '1D356A';
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('B1', 'HOUSEHOLD OF DAVID');
$sheet->getStyle('B1')->getFill()
    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setRGB($brandBlue);

expect_export_value(false, assim_export_attach_logo($sheet, $logoPath, false), 'a runtime without image inspection skips the logo instead of failing the export');
expect_export_value(0, count($sheet->getDrawingCollection()), 'a skipped logo leaves no drawing on the sheet');
expect_export_value($brandBlue, $sheet->getStyle('B1')->getFill()->getStartColor()->getRGB(), 'the branded heading keeps its brand blue without the logo');

$tmpDir = sys_get_temp_dir();
$skippedBook = $tmpDir . '/assim_export_no_logo_' . getmypid() . '.xlsx';
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($skippedBook);
expect_export_value('PK', substr((string) file_get_contents($skippedBook), 0, 2), 'the workbook still saves as an xlsx when the logo is skipped');
expect_export_value(true, strlen((string) file_get_contents($skippedBook)) > 1000, 'the saved workbook is not an empty stream');

$bogusLogo = $tmpDir . '/assim_export_bogus_' . getmypid() . '.png';
file_put_contents($bogusLogo, "this is not an image\n");
expect_export_value(false, assim_export_attach_logo($sheet, $bogusLogo), 'a logo file PhpSpreadsheet cannot read is skipped too');
expect_export_value(0, count($sheet->getDrawingCollection()), 'a rejected logo leaves no drawing on the sheet');

if (assim_export_can_inspect_images()) {
    expect_export_value(true, assim_export_attach_logo($sheet, $logoPath), 'the logo is still embedded where the runtime can inspect images');
    expect_export_value(1, count($sheet->getDrawingCollection()), 'the embedded logo is attached to the sheet');

    $brandedBook = $tmpDir . '/assim_export_with_logo_' . getmypid() . '.xlsx';
    $writer->save($brandedBook);
    expect_export_value('PK', substr((string) file_get_contents($brandedBook), 0, 2), 'the workbook with the logo still saves');
    @unlink($brandedBook);
} else {
    // Belt and braces: a probe that is wrong (here it says "yes" while the
    // functions really are missing) must not fail the export either.
    $badProbe = null;
    try {
        $badProbe = assim_export_attach_logo($sheet, $logoPath, true);
    } catch (Throwable $e) {
        $badProbe = null;
    }
    expect_export_value(true, is_bool($badProbe), 'a wrong image-inspection probe still cannot fail the export');
    expect_export_value(0, count($sheet->getDrawingCollection()), 'nothing is attached when the image functions are missing');
}

$spreadsheet->disconnectWorksheets();
@unlink($skippedBook);
@unlink($bogusLogo);

fwrite(STDOUT, "Assimilation Excel export helpers OK ({$checks} checks).\n");
