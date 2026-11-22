<?php
// /includes/exams/export.php
//
// The tutor's results as an Excel workbook (docs/EXAMS.md, "The tutor's ranking").
// Pure: it only lays out what exam_results() already read (one row per person,
// ranked), so the tests can build the workbook and read it back with no
// database. The file itself is written by xlsx_write() (xlsx.php); the access
// check is the 'results_xlsx' action in access.php.
//
//   exam_results_sheets($item, $exam, $res, $at)  -> the sheets for xlsx_write()
//   exam_results_file($item, $exam, $res, $at)    -> ['filename' => …, 'bytes' => …]
//
// Two sheets. "Results": one row per person in rank order, with filter buttons,
// the headings and the first two columns kept in view, numbers as real numbers
// (score, percent, time taken) so a tutor can sort and add them up. "Summary":
// the counts and the average, highest and lowest score.

/** How an attempt ended, as the Results tab says it (assets/ui/js/pages/exams.js ENDED). */
const EXAM_ENDED_LABELS = ['learner' => 'Handed in', 'timer' => 'Time ran out', 'closed' => 'Exam closed', 'tutor' => 'Handed in by a tutor'];

/** Where the person stands: Not started, Writing, or how their attempt ended. */
function exam_results_status(array $r): string
{
    if ($r['status'] === 'not_started') {
        return 'Not started';
    }
    if ($r['status'] === 'started') {
        return 'Writing';
    }
    return EXAM_ENDED_LABELS[(string) ($r['ended_by'] ?? '')] ?? 'Handed in';
}

/** A whole number as an int, anything else as a float; null as an empty cell. */
function exam_results_number($n)
{
    if ($n === null) {
        return '';
    }
    return floor((float) $n) === (float) $n ? (int) $n : (float) $n;
}

/** The name people see on the page: a test's own title, an exam's item title. */
function exam_results_heading(array $item): string
{
    $test = ($item['training_kind'] ?? '') === 'test';
    return trim((string) ($test ? ($item['training_title'] ?? '') : ($item['title'] ?? ''))) ?: 'Exam';
}

/** "my-test-results-2026-11-22.xlsx": letters, digits and hyphens only, so it is safe in a header. */
function exam_results_filename(array $item, ?DateTimeImmutable $at = null): string
{
    $at ??= exams_now();
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(exam_results_heading($item), 'UTF-8')), '-');
    $slug = rtrim(substr($slug, 0, 50), '-');
    return ($slug !== '' ? $slug : 'exam') . '-results-' . $at->format('Y-m-d') . '.xlsx';
}

/**
 * @param array $item  exam_item(): title, training_title, training_kind
 * @param array $exam  exam_get(): pass_percent
 * @param array $res   exam_results(): people (ranked), summary
 * @return list<array> the sheets for xlsx_write()
 */
function exam_results_sheets(array $item, array $exam, array $res, ?DateTimeImmutable $at = null): array
{
    $at ??= exams_now();
    $people = $res['people'] ?? [];
    $sum = $res['summary'] ?? ['enrolled' => 0, 'started' => 0, 'finished' => 0, 'passed' => 0, 'attempts' => 0];

    // ---- Results: one row per person, in rank order ----------------------
    $columns = [['Position', 10, 'headc'], ['Person', 32, 'head'], ['Status', 20, 'head'], ['Score', 9, 'headc'], ['Out of', 9, 'headc'],
                ['Percent', 10, 'headc'], ['Result', 13, 'headc'], ['Attempt', 10, 'headc'], ['Attempts taken', 15, 'headc'],
                ['Time taken', 12, 'headc'], ['Left tab', 10, 'headc']];
    $rows = [array_map(static fn (array $c): array => ['v' => $c[0], 's' => $c[2]], $columns)];
    foreach ($people as $r) {
        $center = static fn ($v): mixed => $v === '' ? '' : ['v' => $v, 's' => 'center'];
        $rows[] = [
            $center($r['position'] === null ? '' : (int) $r['position']),
            (string) $r['name'],
            exam_results_status($r),
            $center(exam_results_number($r['score'])),
            $center(exam_results_number($r['max_score'])),
            $r['percent'] === null ? '' : ['v' => (float) $r['percent'] / 100, 's' => 'pct'],
            $r['passed'] === null ? '' : ($r['passed'] ? ['v' => 'Passed', 's' => 'pass'] : ['v' => 'Not passed', 's' => 'fail']),
            $center($r['attempt_no'] === null ? '' : (int) $r['attempt_no']),
            $center((int) $r['attempts']),
            ($r['seconds'] ?? null) === null ? '' : ['v' => $r['seconds'] / 86400, 's' => 'time'],
            $center((int) ($r['leaves'] ?? 0)),
        ];
    }
    $results = [
        'name' => 'Results',
        'rows' => $rows,
        'widths' => array_column($columns, 1),
        'freeze' => true,
        'freeze_cols' => 2,
        'filter' => true,
        'fit' => true,
    ];

    // ---- Summary: the counts and the spread of the scores ----------------
    $fractions = [];
    foreach ($people as $r) {
        if (!empty($r['best']) && $r['percent'] !== null) {
            $fractions[] = (float) $r['percent'] / 100;
        }
    }
    $cell = static fn ($v, string $style = 'center'): mixed => $v === null || $v === '' ? '' : ['v' => $v, 's' => $style];
    $measure = static fn (string $label, $value, string $style = 'center'): array => [$label, $cell($value, $style)];
    $finished = (int) $sum['finished'];
    $course = ($item['training_kind'] ?? '') === 'test' ? '' : (string) ($item['training_title'] ?? '');
    $summary = [
        'name' => 'Summary',
        'widths' => [34, 16],
        'rows' => [
            [['v' => exam_results_heading($item), 's' => 'title']],
            [($course !== '' ? $course . ' · ' : '') . 'Exported ' . $at->format('j M Y, H:i')],
            [],
            [['v' => 'Measure', 's' => 'head'], ['v' => 'Value', 's' => 'headc']],
            $measure('Enrolled', (int) $sum['enrolled']),
            $measure('Started', (int) $sum['started']),
            $measure('Not started', max(0, (int) $sum['enrolled'] - (int) $sum['started'])),
            $measure('Handed in', $finished),
            $measure('Passed', (int) $sum['passed']),
            $measure('Not passed', max(0, $finished - (int) $sum['passed'])),
            $measure('Pass rate', $finished > 0 ? (int) $sum['passed'] / $finished : null, 'pct'),
            $measure('Pass mark', (int) $exam['pass_percent'] / 100, 'pct'),
            $measure('Attempts', (int) $sum['attempts']),
            $measure('Average score', $fractions !== [] ? array_sum($fractions) / count($fractions) : null, 'pct'),
            $measure('Highest score', $fractions !== [] ? max($fractions) : null, 'pct'),
            $measure('Lowest score', $fractions !== [] ? min($fractions) : null, 'pct'),
        ],
    ];

    return [$results, $summary];
}

/** @return array{filename:string, bytes:string} */
function exam_results_file(array $item, array $exam, array $res, ?DateTimeImmutable $at = null): array
{
    $at ??= exams_now();
    return ['filename' => exam_results_filename($item, $at), 'bytes' => xlsx_write(exam_results_sheets($item, $exam, $res, $at))];
}
