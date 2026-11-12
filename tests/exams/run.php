<?php
// /tests/exams/run.php
//
// BEHAVIOURAL tests for Trainings PR 2 (includes/exams/): the .xlsx writer
// and reader, the question checks, the Excel import (row by row, confirm,
// never twice), the bank, and the exam engine's "fair and firm" rules (the
// window, one attempt, shuffling, nothing right sent to the browser, the
// server's clock, auto-submit, part marks, tab-leave notes, results).
//
// In-memory SQLite built from the real migration, with stand-ins for PR 1's
// tables (trainings, training_items, training_enrolments, training_progress)
// and a pinned Lagos clock. Needs pdo_sqlite and zip.
//
//     php tests/exams/run.php      (exit 0 = all good)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
foreach (['pdo_sqlite', 'zip'] as $ext) {
    if (!extension_loaded($ext)) {
        echo "SKIP: {$ext} is not available in this PHP build.\n";
        exit(0);
    }
}
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/../../includes/exams/bootstrap.php';

$GLOBALS['et_passed'] = 0;
$GLOBALS['et_failed'] = 0;
function et_ok(string $label, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['et_passed']++;
        echo "  ok   {$label}" . PHP_EOL;
    } else {
        $GLOBALS['et_failed']++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    }
}
function et_eq(string $label, $expected, $actual): void
{
    et_ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function et_section(string $name): void
{
    echo PHP_EOL . $name . PHP_EOL;
}
function et_at(string $when): DateTimeImmutable
{
    $at = exams_dt($when);
    exams_set_clock($at);
    return $at;
}

/** PR 1's real tables (Trainings core) and this module's, from their migrations, translated to SQLite. */
function et_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sql = '';
    foreach (['20261208090000_trainings_core.sql', '20261209090000_trainings_exams.sql'] as $file) {
        $sql .= (string) file_get_contents(__DIR__ . '/../../db/migrations/' . $file) . PHP_EOL;
    }
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
    foreach ($tables as [, $name, $body]) {
        $cols = $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line)) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY \w+ \((.+)\)$/', $line, $m)) {
                $cols[] = 'UNIQUE (' . $m[1] . ')';
                continue;
            }
            if (preg_match('/^KEY (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE INDEX {$m[1]} ON {$name} ({$m[2]})";
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = (string) preg_replace('/\b(TINYINT|INT)\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)|\bDECIMAL\(\d+,\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(MEDIUMTEXT|LONGTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $pdo->exec("CREATE TABLE {$name} (" . implode(', ', $cols) . ')');
        foreach ($indexes as $ix) {
            $pdo->exec($ix);
        }
    }
    foreach ([
        'CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, spiritual_status TEXT)',
        'CREATE TABLE system_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, message TEXT, link_url TEXT)',
    ] as $stmt) {
        $pdo->exec($stmt);
    }
    $pdo->exec("INSERT INTO users (id, first_name, last_name) VALUES (1, 'Tutor', 'One'), (2, 'Ada', 'Okafor'), (3, 'Ben', 'Ade'), (4, 'Cy', 'Bello'), (5, 'Dee', 'Eze')");
    $at = '2027-01-01 09:00:00';
    $pdo->exec("INSERT INTO trainings (id, title, department_id, status, created_at) VALUES (10, 'Maturity Class', 7, 'published', '{$at}'),
                (20, 'Workers training', NULL, 'published', '{$at}')");
    $pdo->exec("INSERT INTO training_items (id, training_id, position, type, title, created_at) VALUES (100, 10, 2, 'exam', 'Final exam', '{$at}'),
                (101, 10, 1, 'reading', 'Notes', '{$at}'), (200, 20, 1, 'exam', 'Quiz', '{$at}')");
    foreach ([2, 3, 4, 5] as $uid) {
        $pdo->prepare("INSERT INTO training_enrolments (training_id, user_id, status, created_at) VALUES (10, ?, 'active', ?)")->execute([$uid, $at]);
    }
    return $pdo;
}

function et_count(PDO $pdo, string $table, string $where = '1=1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Write rows to a temporary .xlsx and return its path. */
function et_sheet(array $rows, string $name = 'Questions'): string
{
    $path = tempnam(sys_get_temp_dir(), 'etx') . '.xlsx';
    file_put_contents($path, xlsx_write([['name' => $name, 'rows' => $rows]]));
    return $path;
}

/** A hand-built workbook the way Excel writes one: shared strings, rich text, booleans, gaps. */
function et_excel_style(string $sheetXml, string $sharedXml, string $target = 'worksheets/sheet1.xml'): string
{
    $path = tempnam(sys_get_temp_dir(), 'etx') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . XLSX_NS_MAIN . '" xmlns:r="' . XLSX_NS_REL . '">'
        . '<sheets><sheet name="Notes" sheetId="1" r:id="rId9"/><sheet name="questions" sheetId="2" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="x" Target="' . $target . '"/><Relationship Id="rId9" Type="x" Target="worksheets/sheet9.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . XLSX_NS_MAIN . '">' . $sharedXml . '</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . XLSX_NS_MAIN . '"><sheetData>' . $sheetXml . '</sheetData></worksheet>');
    $zip->addFromString('xl/worksheets/sheet9.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . XLSX_NS_MAIN . '"><sheetData/></worksheet>');
    $zip->close();
    return $path;
}

const ET_HEAD = ['Type', 'Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E', 'Correct', 'Marks', 'Explanation', 'Topic'];

// ===========================================================================
et_section('1. The .xlsx writer and reader');
// ===========================================================================
$tpl = tempnam(sys_get_temp_dir(), 'etx') . '.xlsx';
file_put_contents($tpl, exam_template_bytes());
$read = xlsx_read($tpl, 'Questions');
et_ok('the template opens and its Questions sheet reads back', $read['ok'] && $read['sheet'] === 'Questions');
et_eq('…with the headings in the first row', ET_HEAD, array_values($read['rows'][1] ?? []));
et_eq('…and three example rows, one per type', ['One answer', 'Several answers', 'True/False'],
    [$read['rows'][2][0] ?? '', $read['rows'][3][0] ?? '', $read['rows'][4][0] ?? '']);
et_eq('…numbers come back as typed', ['1', '2'], [$read['rows'][2][8] ?? '', $read['rows'][3][8] ?? '']);
$zip = new ZipArchive();
$zip->open($tpl);
$bad = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (@simplexml_load_string((string) $zip->getFromIndex($i)) === false) {
        $bad[] = $name;
    }
}
$zip->close();
et_eq('every part of the template is well-formed XML', [], $bad);
$guide = xlsx_read($tpl, 'guide');
et_ok('the Guide sheet explains every column', $guide['ok'] && count($guide['rows']) >= count(ET_HEAD) + 1
    && str_contains(implode(' ', array_map('implode', $guide['rows'])), 'Shown only to people who get it wrong'));
$zip = new ZipArchive();
$zip->open($tpl);
$s1 = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
et_ok('the Type column has a dropdown, Marks a whole-number rule, the headings stay in view',
    str_contains($s1, '&quot;One answer,Several answers,True/False&quot;') && str_contains($s1, 'type="whole"') && str_contains($s1, 'state="frozen"'));

$excel = et_excel_style(
    '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
    . '<row r="3"><c r="A3" t="s"><v>2</v></c><c r="D3" t="b"><v>1</v></c><c r="E3"><v>2.0</v></c><c r="F3" t="inlineStr"><is><t>inline</t></is></c>'
    . '<c r="G3" t="e"><v>#N/A</v></c><c r="H3" t="str"><v>formula text</v></c><c r="I3"><v>0.5</v></c></row>',
    '<si><t>Type</t></si><si><r><t>Ques</t></r><r><rPr><b/></rPr><t>tion</t></r></si><si><t xml:space="preserve">  One answer </t></si>'
);
$x = xlsx_read($excel, 'Questions');
et_ok('an Excel-style file: the sheet is found by name whatever its case', $x['ok'] && $x['sheet'] === 'questions');
et_eq('…shared strings, rich text runs, booleans, numbers, inline strings, formula text; errors and gaps are empty',
    [1 => [0 => 'Type', 1 => 'Question'], 3 => [0 => '  One answer ', 3 => 'TRUE', 4 => '2', 5 => 'inline', 7 => 'formula text', 8 => '0.5']], $x['rows']);
$notZip = tempnam(sys_get_temp_dir(), 'etx');
file_put_contents($notZip, "Type,Question\nOne answer,Hi");
et_ok('a file that is not .xlsx is refused in words', !xlsx_read($notZip)['ok'] && str_contains((string) xlsx_read($notZip)['error'], 'Excel'));
$dtd = et_excel_style('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', '<si><t>x</t></si>');
$zip = new ZipArchive();
$zip->open($dtd);
$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x SYSTEM "file:///etc/passwd">]><sst xmlns="' . XLSX_NS_MAIN . '"><si><t>&x;</t></si></sst>');
$zip->close();
et_ok('a part with a DOCTYPE (entity tricks) is refused', !xlsx_read($dtd)['ok']);
et_ok('a sheet that points outside the package is refused', !xlsx_read(et_excel_style('', '', '../../../etc/passwd'), 'questions')['ok']);
et_eq('column letters both ways', ['A', 'Z', 'AA', 'AZ', 0, 25, 26, 51], [xlsx_col_letter(0), xlsx_col_letter(25), xlsx_col_letter(26),
    xlsx_col_letter(51), xlsx_col_index('A'), xlsx_col_index('Z'), xlsx_col_index('AA'), xlsx_col_index('AZ')]);

// ===========================================================================
et_section('2. Checking a question');
// ===========================================================================
$good = static fn (array $over = []): array => exam_question_check($over + ['qtype' => 'One answer', 'body' => 'Who?', 'options' => ['Paul', 'Peter', ''], 'correct_text' => 'b', 'marks' => '', 'explanation' => 'Because.']);
et_eq('a good one-answer question: options trimmed of blanks at the end, B, 1 mark',
    ['single', ['Paul', 'Peter'], [1], 1], [$good()['q']['qtype'], $good()['q']['options'], $good()['q']['correct'], $good()['q']['marks']]);
et_eq('the type in many spellings', ['single', 'multiple', 'true_false', 'true_false', null],
    [exam_qtype_from_text('one'), exam_qtype_from_text('Several Answers'), exam_qtype_from_text('True / False'), exam_qtype_from_text('TF'), exam_qtype_from_text('essay')]);
et_eq('several answers in many spellings', [[0, 2], [0, 2], [0, 2], [0, 2]], array_map(static fn ($t) => exam_correct_from_text($t, 'multiple', 4)['correct'], ['A, C', 'ac', 'A and C', 'C;A']));
et_eq('true/false in many spellings (Excel booleans too)', [[0], [1], [0], [1]], array_map(static fn ($t) => exam_correct_from_text($t, 'true_false', 2)['correct'], ['TRUE', 'f', 'Yes', 'B']));
$why = static fn (array $over): string => implode(' | ', $good($over)['errors']);
et_ok('no question', str_contains($why(['body' => ' ']), 'Write the question'));
et_ok('an unknown type', str_contains($why(['qtype' => 'essay']), 'Type'));
et_ok('one option only', str_contains($why(['options' => ['Paul'], 'correct_text' => 'A']), 'at least two options'));
et_ok('a gap between options', str_contains($why(['options' => ['Paul', '', 'John'], 'correct_text' => 'A']), 'Option B is empty'));
et_ok('the same option twice', str_contains($why(['options' => ['Paul', 'paul']]), 'are the same'));
et_ok('the right answer on an empty option', str_contains($why(['correct_text' => 'D']), 'Option D is empty'));
et_ok('two answers on a one-answer question', str_contains($why(['correct_text' => 'A,B']), 'exactly one letter'));
et_ok('every option right on a several-answers question', str_contains($why(['qtype' => 'multiple', 'correct_text' => 'A,B']), 'not every option'));
et_ok('marks out of range or not whole', str_contains($why(['marks' => '0']), 'Marks') && str_contains($why(['marks' => '1.5']), 'Marks'));
et_ok('no explanation is a warning, not an error', $good(['explanation' => ''])['ok'] && $good(['explanation' => ''])['warnings'] !== []);
et_eq('true/false ignores options and keeps True, False', ['True', 'False'], exam_question_check(['qtype' => 'true_false', 'body' => 'Q', 'options' => ['x'], 'correct_text' => 'false'])['q']['options']);
et_ok('the hash ignores case and spacing but not the bank', exam_question_hash(10, $good()['q']) === exam_question_hash(10, $good(['body' => '  WHO? '])['q'])
    && exam_question_hash(10, $good()['q']) !== exam_question_hash(null, $good()['q']));

// ===========================================================================
et_section('3. The Excel import: every row checked, then confirmed, never twice');
// ===========================================================================
$pdo = et_db();
et_at('2027-01-10 09:00');
// Columns in another order, and a missing Topic column: the headings decide.
$head = ['Question', 'Type', 'Correct', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E', 'Marks', 'Explanation'];
$sheetRows = [
    $head,
    ['Example: Which is first?', 'One answer', 'A', 'Love', 'Joy', '', '', '', '1', 'Gal 5:22'],
    ['Who wrote Romans?', 'One answer', 'B', 'Peter', 'Paul', 'John', '', '', '', 'Paul wrote it to the church in Rome.'],
    ['Which are Gospels?', 'Several answers', 'A, C', 'Matthew', 'Acts', 'John', 'Romans', '', '2', 'Matthew, Mark, Luke and John.'],
    ['Psalms is in the Old Testament.', 'True/False', 'True', '', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', '', '', ''],
    ['', 'One answer', 'A', 'x', 'y', '', '', '', '', ''],
    ['How many?', 'Essay', 'A', 'x', 'y', '', '', '', '', ''],
    ['Pick one', 'One answer', 'D', 'x', 'y', '', '', '', '', ''],
    ['who wrote romans?', 'One answer', 'B', 'Peter', 'Paul', 'John', '', '', '', ''],
];
$file = et_sheet($sheetRows);
$check = exam_import_check($pdo, $file, 'my questions.xlsx', 10, 1);
et_ok('the sheet is read and every row checked', $check['ok']);
et_eq('…3 good, 3 with errors, 1 repeat, 1 example; the empty row is ignored',
    ['ok' => 3, 'error' => 3, 'duplicate' => 1, 'example' => 1], $check['counts']);
$byRow = array_column($check['rows'], null, 'row');
et_eq('…each error names its row and says what to fix', [7 => 'Write the question.', 8 => 'Type: choose One answer, Several answers or True/False.',
    9 => 'Correct: Option D is empty, so it cannot be the right answer.'], [7 => $byRow[7]['errors'][0], 8 => $byRow[8]['errors'][0], 9 => $byRow[9]['errors'][0]]);
et_eq('…the repeat points at the first one', ['The same question as row 3.'], $byRow[10]['warnings']);
et_eq('nothing is added before the confirm', 0, et_count($pdo, 'training_questions'));
et_eq('the file itself is not kept, only the checked rows', 1, et_count($pdo, 'training_question_imports', 'file_name = ? AND ok_rows = 3', ['my questions.xlsx']));
et_eq("another person cannot confirm someone else's check", false, exam_import_confirm($pdo, $check['token'], 2, true)['ok']);
$done = exam_import_confirm($pdo, $check['token'], 1, true);
et_eq('confirm adds the good rows, Ready, from the import', [true, 3, 3], [$done['ok'], $done['imported'],
    et_count($pdo, 'training_questions', "training_id = 10 AND status = 'approved' AND source = 'import'")]);
et_eq('a second confirm adds nothing', [false, 3], [exam_import_confirm($pdo, $check['token'], 1, true)['ok'], et_count($pdo, 'training_questions')]);
$again = exam_import_check($pdo, $file, 'my questions.xlsx', 10, 1);
et_eq('importing the same file again: every good row is already in the bank', ['ok' => 0, 'error' => 3, 'duplicate' => 4, 'example' => 1], $again['counts']);
et_eq('…and confirming it adds nothing', [0, 3], [exam_import_confirm($pdo, $again['token'], 1, true)['imported'], et_count($pdo, 'training_questions')]);
$shared = exam_import_check($pdo, $file, 'x.xlsx', null, 1);
et_eq('the same questions are new to the shared bank', 3, $shared['counts']['ok']);
$noHead = exam_import_check($pdo, et_sheet([['Who?', 'One answer', 'A', 'x', 'y']]), 'x.xlsx', 10, 1);
et_ok('a sheet without the headings is refused with a way forward', !$noHead['ok'] && str_contains((string) $noHead['error'], 'template'));
$empty = exam_import_check($pdo, et_sheet([ET_HEAD, ['One answer', 'Example: x', 'a', 'b', '', '', '', 'A']]), 'x.xlsx', 10, 1);
et_ok('a sheet with only the examples is refused', !$empty['ok'] && str_contains((string) $empty['error'], 'No questions'));
$big = [ET_HEAD];
for ($i = 1; $i <= EXAM_IMPORT_MAX + 1; $i++) {
    $big[] = ['One answer', "Question {$i}", 'x', 'y', '', '', '', 'A'];
}
et_ok('more than ' . EXAM_IMPORT_MAX . ' questions is refused', !exam_import_check($pdo, et_sheet($big), 'x.xlsx', 10, 1)['ok']);
et_eq('the template itself imports nothing (only examples)', false, exam_import_check($pdo, $tpl, 'template.xlsx', 10, 1)['ok']);
et_at('2027-01-11 09:30');
et_eq('a check older than a day has expired', false, exam_import_confirm($pdo, $shared['token'], 1, true)['ok']);

// ===========================================================================
et_section('4. The bank');
// ===========================================================================
$m = exam_question_save($pdo, null, 10, ['qtype' => 'multiple', 'body' => 'Which are fruits of the Spirit?', 'options' => ['Love', 'Anger', 'Joy', 'Peace'],
    'correct' => [0, 2, 3], 'marks' => '3', 'explanation' => 'Galatians 5:22-23.', 'topic' => 'Spirit'], 1);
et_ok('a question written in the form is saved as a draft', $m['ok'] && exam_question_get($pdo, $m['id'])['status'] === 'draft');
et_eq('…the same question twice is refused', ['This question is already in the bank.'],
    exam_question_save($pdo, null, 10, ['qtype' => 'multiple', 'body' => 'which are fruits of the spirit?', 'options' => ['Love', 'Anger', 'Joy', 'Peace'], 'correct' => [0, 2, 3]], 1)['errors'] ?? []);
et_ok('it can be marked Ready', exam_question_set_status($pdo, $m['id'], 'approved', 1) && exam_question_get($pdo, $m['id'])['approved_by'] === 1);
et_ok('an edit keeps it unique', exam_question_save($pdo, $m['id'], null, ['qtype' => 'multiple', 'body' => 'Which are fruits of the Spirit?',
    'options' => ['Love', 'Anger', 'Joy', 'Peace'], 'correct' => [0, 2, 3], 'marks' => '3', 'explanation' => 'Galatians 5:22-23 lists them.', 'topic' => 'Spirit'], 1)['ok']);
et_eq('the bank lists by topic, without archived ones', 4, count(exam_question_list($pdo, 10)));
et_eq('…and finds by words', 1, count(exam_question_list($pdo, 10, ['q' => 'Romans'])));

// ===========================================================================
et_section('5. Setting up an exam');
// ===========================================================================
$item = exam_item($pdo, 100);
et_eq('an exam item and its training (PR 1)', [100, 10, 7, 'Maturity Class'], [$item['id'], $item['training_id'], $item['department_id'], $item['training_title']]);
et_eq('a reading is not an exam', null, exam_item($pdo, 101));
$ids = array_map(static fn ($q) => (int) $q['id'], exam_question_list($pdo, 10, ['status' => 'approved']));
sort($ids);
$draft = exam_question_save($pdo, null, 10, ['qtype' => 'true_false', 'body' => 'Draft only', 'correct_text' => 'True'], 1);
$other = exam_question_save($pdo, null, 20, ['qtype' => 'true_false', 'body' => 'Other training', 'correct_text' => 'True', 'status' => 'approved'], 1);
$base = ['window_open' => '2027-01-12T09:00', 'window_close' => '2027-01-12T12:00', 'minutes' => 30, 'pass_percent' => 60,
         'shuffle' => 1, 'part_marks' => 1, 'leave_alert' => 2, 'question_ids' => $ids];
$errs = static fn (array $over): string => implode(' | ', exam_save($pdo, $item, $over + $base, 1)['errors'] ?? []);
et_ok('no questions', str_contains($errs(['question_ids' => []]), 'at least one question'));
et_ok('a draft question', str_contains($errs(['question_ids' => [$draft['id']]]), 'Ready'));
et_ok("another training's question", str_contains($errs(['question_ids' => [$other['id']]]), 'not in this training'));
et_ok('closing before opening', str_contains($errs(['window_close' => '2027-01-12T08:00']), 'close after it opens'));
et_ok('no time, or too much', str_contains($errs(['minutes' => 0]), 'Time') && str_contains($errs(['minutes' => 999]), 'Time'));
$saved = exam_save($pdo, $item, $base, 1);
et_ok('a good set-up is saved', $saved['ok'] && $saved['exam']['question_ids'] === $ids && $saved['exam']['part_marks'] === 1);
$exam = $saved['exam'];
et_eq('the window: not open, open, closed', ['not_open', 'open', 'closed'],
    [exam_window($exam, exams_dt('2027-01-12 08:59')), exam_window($exam, exams_dt('2027-01-12 09:00')), exam_window($exam, exams_dt('2027-01-12 12:00'))]);

// ===========================================================================
et_section('6. Taking it: one attempt, shuffled, timed on the server, nothing right sent');
// ===========================================================================
et_at('2027-01-12 08:30');
et_eq('before it opens: not yet', 'NOT_OPEN', exam_start($pdo, $exam, 2)['code'] ?? null);
$now = et_at('2027-01-12 09:05');
$start = exam_start($pdo, $exam, 2, exam_enrolment_id($pdo, 10, 2));
$att = $start['attempt'];
et_ok('Ada starts: her deadline is 30 minutes on, set by the server', $start['ok'] && $att['deadline_at'] === '2027-01-12 09:35:00');
et_eq('…starting again resumes the same attempt', $att['id'], exam_start($pdo, $exam, 2)['attempt']['id']);
et_eq('…one attempt per person is the database\'s rule', 1, et_count($pdo, 'training_attempts', 'exam_id = ? AND user_id = 2', [$exam['id']]));
$paper = exam_paper($pdo, $att);
$json = json_encode($paper);
et_eq('the paper has every question, numbered in her order', [1, 2, 3, 4], array_column($paper['questions'], 'n'));
et_ok('…and never the right answers or the explanations', !str_contains($json, 'correct') && !str_contains($json, 'Paul wrote it')
    && !str_contains($json, 'Galatians') && !str_contains($json, 'explanation'));
et_eq('…the clock comes from the server', 1800, $paper['seconds_left']);
// Answer every question right, through the shown positions the browser knows.
$rows = exam_attempt_rows($pdo, $att['id']);
$shownFor = static function (array $r, array $originals): array {
    $pos = array_flip($r['order']);
    return array_map(static fn ($i) => $pos[$i], $originals);
};
foreach ($rows as $r) {
    exam_answer($pdo, $att, (int) $r['position'], $shownFor($r, $r['correct']));
}
$single = array_values(array_filter($rows, static fn ($r) => $r['qtype'] === 'single'))[0];
et_eq('two answers on a one-answer question are refused', 'Choose one answer.', exam_answer($pdo, $att, (int) $single['position'], [0, 1])['error'] ?? '');
et_eq('a choice that is not there is refused', 'That answer is not one of the choices.', exam_answer($pdo, $att, (int) $single['position'], [9])['error'] ?? '');
et_eq('a question that is not in her exam is refused', 'That question is not in your exam.', exam_answer($pdo, $att, 99, [0])['error'] ?? '');
$order = array_map(static fn ($r) => (int) $r['question_id'], $rows);
$tf = array_values(array_filter($rows, static fn ($r) => $r['qtype'] === 'true_false'))[0];
et_eq('true/false keeps True before False', [0, 1], $tf['order']);
$done2 = exam_submit($pdo, $att['id'], 'learner');
et_eq('she hands it in: marked on the server, all right (7 marks)', ['submitted', 'learner', 7.0, 7, 100.0, 1],
    [$done2['status'], $done2['ended_by'], $done2['score'], $done2['max_score'], $done2['percent'], $done2['passed']]);
et_eq('…handing in twice changes nothing', $done2['submitted_at'], exam_submit($pdo, $att['id'], 'learner', exams_dt('2027-01-12 09:20'))['submitted_at']);
et_eq('…no second attempt', 'DONE', exam_start($pdo, $exam, 2)['code'] ?? null);
et_eq('…answers after it ended are refused', 'ENDED', exam_answer($pdo, exam_attempt_by_id($pdo, $att['id']), 1, [0])['code'] ?? null);
et_eq('…and her training progress says so (PR 1)', ['completed', 100], (function () use ($pdo) {
    $p = $pdo->query('SELECT status, data FROM training_progress WHERE item_id = 100')->fetch();
    return [$p['status'] ?? null, json_decode((string) ($p['data'] ?? ''), true)['percent'] ?? null];
})());

// Shuffled per person: over several people the orders differ.
$orders = [implode(',', $order)];
foreach ([3, 4, 5] as $uid) {
    $a = exam_start($pdo, $exam, $uid)['attempt'];
    $orders[] = implode(',', array_map(static fn ($r) => (int) $r['question_id'], exam_attempt_rows($pdo, $a['id'])));
}
$sorted = $ids;
$perms = array_map(static function (string $o): string { $a = explode(',', $o); sort($a); return implode(',', $a); }, $orders);
et_eq('each person gets every question exactly once', [implode(',', $sorted)], array_values(array_unique($perms)));
$draws = [];
for ($i = 0; $i < 60; $i++) {
    $draws[implode(',', exam_shuffled([1, 2, 3, 4, 5]))] = true;
}
et_ok('the shuffle really shuffles (60 draws of 5 give many orders)', count($draws) > 20, (string) count($draws));

// Part marks on several-answers: Ben picks two of three right ones and one wrong one.
$ben = exam_attempt_get($pdo, $exam['id'], 3);
$multi = array_values(array_filter(exam_attempt_rows($pdo, $ben['id']), static fn ($r) => $r['qtype'] === 'multiple' && (int) $r['marks'] === 3))[0];
exam_answer($pdo, $ben, (int) $multi['position'], $shownFor($multi, [0, 2, 1]));
et_eq('part marks: (2 right − 1 wrong) / 3 right × 3 marks = 1', [1.0, false], array_values(exam_mark('multiple', [0, 2, 3], [0, 1, 2], 3, true)));
et_eq('…without part marks it is all or nothing', [0.0, false], array_values(exam_mark('multiple', [0, 2, 3], [0, 2], 3, false)));
et_eq('…never below nothing', [0.0, false], array_values(exam_mark('multiple', [0], [1, 2], 2, true)));
et_eq('…one-answer questions are all or nothing even with part marks', [0.0, false], array_values(exam_mark('single', [1], [0], 2, true)));

// Leaving the tab: the tutor is told once, after 2 leaves.
$cy = exam_attempt_get($pdo, $exam['id'], 4);
exam_note_focus($pdo, $exam, $cy, 'leave');
exam_note_focus($pdo, $exam, exam_attempt_get($pdo, $exam['id'], 4), 'return');
$second = exam_note_focus($pdo, $exam, exam_attempt_get($pdo, $exam['id'], 4), 'leave');
exam_note_focus($pdo, $exam, exam_attempt_get($pdo, $exam['id'], 4), 'leave');
et_eq('after 2 leaves the tutor is told, once', [2, true, 1, 3], [$second['leaves'], $second['told'],
    et_count($pdo, 'system_notifications', "user_id = 1 AND message LIKE 'Cy Bello has left the exam tab 2 times.'"),
    exam_attempt_get($pdo, $exam['id'], 4)['leaves']]);

// Time runs out: answers stop, the server hands it in.
et_at('2027-01-12 09:35:10');
$benOther = array_values(array_filter(exam_attempt_rows($pdo, $ben['id']), static fn ($r) => (int) $r['id'] !== (int) $multi['id']))[0];
et_eq('just after the deadline an answer still lands (network slack)', true, exam_answer($pdo, $ben, (int) $benOther['position'], [0])['ok']);
et_at('2027-01-12 09:35:30');
et_eq('past the slack, an answer hands the paper in instead', 'ENDED', exam_answer($pdo, exam_attempt_get($pdo, $exam['id'], 3), (int) $benOther['position'], [0])['code'] ?? null);
et_eq('…ended by the timer', 'timer', exam_attempt_get($pdo, $exam['id'], 3)['ended_by']);
$res = exam_results($pdo, $exam);
et_eq('looking at the results ends every attempt whose time is up', ['submitted', 'submitted', 'submitted', 'submitted'],
    array_column($res['rows'], 'status'));
et_eq('…the summary', ['enrolled' => 4, 'started' => 4, 'finished' => 4, 'passed' => 1], $res['summary']);

// A late start is cut short by the window's close.
$late = exam_save($pdo, exam_item($pdo, 200), $base + ['question_ids' => [$other['id']]] + [], 1);
$late = exam_save($pdo, exam_item($pdo, 200), ['question_ids' => [$other['id']], 'window_open' => '', 'window_close' => '2027-01-12T10:00'] + $base, 1)['exam'];
et_at('2027-01-12 09:50');
$l = exam_start($pdo, $late, 2)['attempt'];
et_eq('a start 10 minutes before the close gets 10 minutes', '2027-01-12 10:00:00', $l['deadline_at']);
et_at('2027-01-12 10:05');
exam_finalize_due($pdo);
et_eq('…and is ended as "closed"', 'closed', exam_attempt_by_id($pdo, $l['id'])['ended_by']);
et_eq('…a start after the close is refused', 'CLOSED', exam_start($pdo, $late, 3)['code'] ?? null);

// ===========================================================================
et_section('7. Results: the explanation only for wrong answers, after the exam closes');
// ===========================================================================
$cyDone = exam_attempt_get($pdo, $exam['id'], 4);
et_at('2027-01-12 10:00');
$early = exam_result_for_learner($pdo, $exam, $cyDone);
et_eq('while others may still sit it: the score, how many wrong, no answers yet', [false, 4, [], '2027-01-12 12:00:00'],
    [$early['review_open'], $early['wrong_count'], $early['wrong'], $early['review_at']]);
et_at('2027-01-12 12:00');
$adaRes = exam_result_for_learner($pdo, $exam, exam_attempt_get($pdo, $exam['id'], 2));
et_eq('all right: nothing to review', [true, 0, []], [$adaRes['passed'], $adaRes['wrong_count'], $adaRes['wrong']]);
$benRes = exam_result_for_learner($pdo, $exam, exam_attempt_get($pdo, $exam['id'], 3));
$benMulti = array_values(array_filter($benRes['wrong'], static fn ($w) => $w['marks'] === 3))[0] ?? [];
et_ok('after the close: each wrong answer with the right one and its stored explanation',
    $benRes['review_open'] && ($benMulti['explanation'] ?? '') === 'Galatians 5:22-23 lists them.' && ($benMulti['right'] ?? '') === 'Love · Joy · Peace'
    && ($benMulti['yours'] ?? '') === 'Love · Anger · Joy' && ($benMulti['part'] ?? null) === 1.0);
et_eq('…and only the wrong ones', $benRes['wrong_count'], count($benRes['wrong']));
$detail = exam_attempt_detail($pdo, exam_attempt_get($pdo, $exam['id'], 4));
et_eq('the tutor sees every question and the tab notes', [4, ['start', 'leave', 'return', 'leave', 'told', 'leave', 'timeout']],
    [count($detail['questions']), array_column($detail['events'], 'kind')]);

// ===========================================================================
et_section('8. Firm once started');
// ===========================================================================
et_eq('the questions cannot change once anyone has started', ['People have started it, so only the times and the leave alert can change.'],
    exam_save($pdo, $item, ['question_ids' => array_slice($ids, 1)] + $base, 1)['errors'] ?? []);
et_ok('…the window can still move', exam_save($pdo, $item, ['window_close' => '2027-01-13T12:00'] + $base, 1)['ok']);
$used = (int) $rows[0]['question_id'];
$q = exam_question_get($pdo, $used);
et_eq('a question someone answered cannot change its answer', ['People have answered this in an exam, so only its explanation and topic can change.'],
    exam_question_save($pdo, $used, null, ['qtype' => $q['qtype'], 'body' => $q['body'] . ' (edited)', 'options' => $q['options'], 'correct' => $q['correct'], 'marks' => (string) $q['marks']], 1)['errors'] ?? []);
et_ok('…but its explanation can', exam_question_save($pdo, $used, null, ['qtype' => $q['qtype'], 'body' => $q['body'], 'options' => $q['options'],
    'correct' => $q['correct'], 'marks' => (string) $q['marks'], 'explanation' => 'A clearer explanation.', 'topic' => $q['topic']], 1)['ok']);

// ===========================================================================
et_section('9. Who may do what (exams_action), and the screens\' contracts');
// ===========================================================================
// The real can() needs the permission tables; here a stand-in records what
// is asked and answers from a table, so the routing is what is tested.
require_once __DIR__ . '/../../includes/exams/access.php';
$GLOBALS['et_grants'] = [];
$GLOBALS['et_asked'] = [];
if (!function_exists('can')) {
    function can(string $module, string $level = 'view', $target = null): bool
    {
        $GLOBALS['et_asked'][] = $module . ':' . $level . ':' . json_encode($target);
        return in_array($module . ':' . $level . ':' . json_encode($target), $GLOBALS['et_grants'], true);
    }
}
if (!function_exists('perm_person')) {
    function perm_person(int $userId): array
    {
        return ['person' => $userId];
    }
}
$learners = array_map(static fn ($u) => 'trainings:view:{"person":' . $u . '}', [1, 2, 3, 4, 5]);
$p = et_db();
et_at('2027-02-01 09:00');
$q1 = exam_question_save($p, null, 10, ['qtype' => 'true_false', 'body' => 'Is this a test?', 'correct_text' => 'True', 'explanation' => 'Yes.', 'status' => 'approved'], 1);
exam_save($p, exam_item($p, 100), ['window_open' => '', 'window_close' => '', 'minutes' => 10, 'pass_percent' => 50, 'shuffle' => 1, 'leave_alert' => 3,
    'question_ids' => [$q1['id']]], 1);
$GLOBALS['et_grants'] = [];
et_eq('without the Academy\'s Edit a tutor action is refused', 'FORBIDDEN', exams_action($p, 'setup', ['item' => 100], 1)['code'] ?? null);
et_eq('…the question asked is PR 1\'s staff check: Trainings, Edit, church-wide', 'trainings:edit:"church"', end($GLOBALS['et_asked']));
$GLOBALS['et_grants'] = ['trainings:view:"church"'];
et_eq('…View alone is not enough', 'FORBIDDEN', exams_action($p, 'results', ['item' => 100], 1)['code'] ?? null);
$GLOBALS['et_grants'] = ['trainings:edit:"church"'];
et_eq('with it, the set-up opens', 'success', exams_action($p, 'setup', ['item' => 100], 1)['status']);
et_eq('…for every training, and the shared bank', ['success', 'success'],
    [exams_action($p, 'setup', ['item' => 200], 1)['status'], exams_action($p, 'questions', ['training_id' => ''], 1)['status']]);
et_eq('the banks a tutor sees: shared, then each training', [null, 10, 20], array_column(exams_banks($p), 'id'));
et_eq('a bank that does not exist is refused', 'FORBIDDEN', exams_action($p, 'questions', ['training_id' => 999], 1)['code'] ?? null);
$GLOBALS['et_grants'] = $learners;
et_eq('not enrolled: no exam', 'NOT_ENROLLED', exams_action($p, 'take', ['item' => 100], 1)['code'] ?? null);
$GLOBALS['et_grants'] = [];
et_eq('enrolled but without Trainings on their own records: no exam', 'NOT_ENROLLED', exams_action($p, 'take', ['item' => 100], 2)['code'] ?? null);
$GLOBALS['et_grants'] = $learners;
$take = exams_action($p, 'take', ['item' => 100], 2);
et_eq('enrolled: the exam, ready to start', ['success', 'ready', 1], [$take['status'], $take['state'], $take['questions']]);
$started = exams_action($p, 'start', ['item' => 100], 2);
et_ok('start: the paper, and nothing about which answer is right',
    $started['state'] === 'started' && !str_contains(json_encode($started), 'correct') && !str_contains(json_encode($started), 'Yes.'));
et_eq('answer through the API', 'success', exams_action($p, 'answer', ['item' => 100, 'n' => 1, 'chosen' => [0]], 2)['status']);
$p->exec("INSERT INTO training_progress (enrolment_id, item_id, status, completed_at, data) VALUES (1, 101, 'completed', '2027-02-01 08:00:00', '{\"by\":\"self\"}')");
$handed = exams_action($p, 'submit', ['item' => 100], 2);
et_eq('hand in: the result, no window close so the review is open', ['done', 100, true], [$handed['state'], (int) $handed['result']['percent'], $handed['result']['review_open']]);
$prog = $p->query('SELECT status, data, updated_at FROM training_progress WHERE enrolment_id = 1 AND item_id = 100')->fetch();
et_eq('PR 1: progress written through trainings_progress_put() (completed, passed, with updated_at)', ['completed', true, true],
    [$prog['status'] ?? null, json_decode((string) ($prog['data'] ?? ''), true)['passed'] ?? null, !empty($prog['updated_at'])]);
et_eq('PR 1: trainings_recheck_enrolment() then completes the training (the reading was done)', 'completed',
    $p->query('SELECT status FROM training_enrolments WHERE id = 1')->fetchColumn());
et_eq('…a learner cannot open the tutor\'s view of attempts', 'FORBIDDEN', exams_action($p, 'attempt', ['item' => 100, 'attempt_id' => 1], 2)['code'] ?? null);
$p->exec("UPDATE trainings SET status = 'draft' WHERE id = 10");
et_eq('a training that is not published: no exam', 'NOT_ENROLLED', exams_action($p, 'take', ['item' => 100], 3)['code'] ?? null);
et_eq('a reading item is not an exam', 'NOT_FOUND', exams_action($p, 'take', ['item' => 101], 2)['code'] ?? null);

$root = dirname(__DIR__, 2);
$api = (string) file_get_contents($root . '/api/exams_api.php');
$js = (string) file_get_contents($root . '/assets/ui/js/pages/exams.js');
$acts = (string) file_get_contents($root . '/includes/exams/access.php');
et_ok('the endpoint checks the CSRF token on every write', str_contains($api, 'csrf_require();'));
$pages = '';
foreach (['index.php', 'setup.php', 'take.php'] as $f) {
    $src = (string) file_get_contents($root . '/modules/exams/' . $f);
    $pages .= $src;
    et_ok("modules/exams/{$f} is a strict page with no inline handler, script or style", str_contains($src, "'mode' => 'strict'")
        && preg_match_all('/\son[a-z]+\s*=|<script(?![^>]*type="application\/json")|\sstyle\s*=/i', $src) === 0);
}
preg_match_all('/[\'"](ex\.[a-z-]+)[\'"]/', $pages . $js, $m);
preg_match_all("/HOD\\.actions\\.on\\('(ex\\.[a-z-]+)'/", $js, $m);
$handled = array_values(array_unique($m[1]));
preg_match_all('/data-action(?:-change|-input)?="(ex\.[a-z-]+)"|\'data-action(?:-change|-input)?\' => \'(ex\.[a-z-]+)\'/', $pages . $js, $m);
$inMarkup = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
et_eq('every action in the markup has a handler', [], array_values(array_diff($inMarkup, $handled)));
et_eq('every handler is used by the markup', [], array_values(array_diff($handled, $inMarkup)));
preg_match_all("/call\\('([a-z_]+)'|action: '([a-z_]+)'/", $js, $m);
$calls = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
et_eq('every action the script calls exists in exams_action() (' . count($calls) . ')', [],
    array_values(array_filter($calls, static fn ($a) => !str_contains($acts, "case '{$a}':"))));

exams_set_clock(false);
echo PHP_EOL . $GLOBALS['et_passed'] . ' passed, ' . $GLOBALS['et_failed'] . ' failed' . PHP_EOL;
exit($GLOBALS['et_failed'] === 0 ? 0 : 1);
