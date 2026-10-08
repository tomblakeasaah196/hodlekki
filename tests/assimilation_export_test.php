<?php
// /tests/assimilation_export_test.php
// Regression checks for the Assimilation Excel-export formatting helpers.

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

fwrite(STDOUT, "Assimilation Excel export helpers OK ({$checks} checks).\n");
