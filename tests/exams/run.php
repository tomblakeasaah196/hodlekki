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
    foreach (['20261208090000_trainings_core.sql', '20261209090000_trainings_exams.sql', '20261211100000_trainings_ai.sql'] as $file) {
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
    $alters = (string) file_get_contents(__DIR__ . '/../../db/migrations/20261209090100_trainings_exams_review.sql');
    preg_match_all("/'ALTER TABLE (\\w+) ADD COLUMN ([^']+)'/", $alters, $am, PREG_SET_ORDER);
    foreach ($am as [, $table, $col]) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN " . preg_replace('/\\b(DATETIME|INT)\\b/', 'TEXT', $col));
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
et_eq('hand in: the result; no closing time and no end date, so the answers wait for the tutor', ['done', 100, false, 'tutor'],
    [$handed['state'], (int) $handed['result']['percent'], $handed['result']['review_open'], $handed['result']['review_when']]);
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

// ===========================================================================
et_section('10. When the answers show (nobody still to sit it can be helped)');
// ===========================================================================
$r = et_db();
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
et_at('2027-03-01 09:00');
$rq = exam_question_save($r, null, 10, ['qtype' => 'true_false', 'body' => 'Is the sky green?', 'correct_text' => 'False', 'explanation' => 'It is blue.', 'status' => 'approved'], 1);
$rset = static fn (array $over) => exam_save($r, exam_item($r, 100), $over + ['window_open' => '', 'window_close' => '', 'minutes' => 10, 'pass_percent' => 50,
    'shuffle' => 0, 'leave_alert' => 3, 'question_ids' => [$rq['id']]], 1)['exam'];
$ex = $rset(['window_close' => '2027-03-05T12:00']);
$state = static fn (string $at) => exam_review_state($r, exam_get($r, 100), exams_dt($at));
et_eq('a closing time: hidden before it, with when', [false, 'close', '2027-03-05 12:00:00'], array_values($state('2027-03-05 11:59')));
et_eq('…shown from it', true, $state('2027-03-05 12:00')['open']);
$ex = $rset(['window_close' => '']);
$r->exec("UPDATE trainings SET ends_on = '2027-03-10' WHERE id = 10");
et_eq('no closing time but an end date: hidden until the day after it', [false, 'end', '2027-03-11 00:00:00'], array_values($state('2027-03-10 23:59')));
et_eq('…shown from then', true, $state('2027-03-11 00:00')['open']);
$r->exec('UPDATE trainings SET ends_on = NULL WHERE id = 10');
et_eq('neither (self-paced): hidden until a tutor shows them, with no date to give', [false, 'tutor', null], array_values($state('2030-01-01 00:00')));
// Ada sits it and gets it wrong.
$a = exam_start($r, exam_get($r, 100), 2)['attempt'];
exam_answer($r, $a, 1, [0]);
exam_submit($r, $a['id'], 'learner');
$res = exams_action($r, 'take', ['item' => 100], 2)['result'];
et_eq('her result: the score, how many wrong, no answers, and why not yet', [0, 1, [], 'tutor', null],
    [(int) $res['percent'], $res['wrong_count'], $res['wrong'], $res['review_when'], $res['review_at']]);
$GLOBALS['et_grants'] = $learners;
et_eq('a learner cannot press the switch', 'FORBIDDEN', exams_action($r, 'answers', ['item' => 100, 'show' => 1], 2)['code'] ?? null);
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
$shown = exams_action($r, 'answers', ['item' => 100, 'show' => 1], 1);
et_eq('the tutor shows the answers', ['success', true, 'shown'], [$shown['status'], $shown['review']['open'], $shown['review']['when']]);
$res = exams_action($r, 'take', ['item' => 100], 2)['result'];
et_eq('…now she sees the right answer and the explanation for the one she got wrong', [true, 'False', 'It is blue.'],
    [$res['review_open'], $res['wrong'][0]['right'] ?? null, $res['wrong'][0]['explanation'] ?? null]);
et_eq('…and who showed them is kept', 1, exam_get($r, 100)['answers_shown_by']);
exams_action($r, 'answers', ['item' => 100, 'show' => 0], 1);
et_eq('hiding them again goes back to the rule', [false, 'tutor'], [$state('2027-03-02 09:00')['open'], $state('2027-03-02 09:00')['when']]);
$rset(['window_close' => '2027-06-01T12:00']);
exam_show_answers($r, exam_get($r, 100), true, 1);
et_eq('a tutor may also show them before the closing time', [true, 'shown'], [$state('2027-03-02 09:00')['open'], $state('2027-03-02 09:00')['when']]);
$intro = exams_take_view($r, exam_item($r, 100), 3);
et_eq('the learner sees when answers show before starting', ['ready', 'shown', true], [$intro['state'], $intro['review']['when'], $intro['review']['open']]);

// ===========================================================================
et_section('11. AI help (Gemini through the shared client, a fake transport)');
// ===========================================================================
require_once __DIR__ . '/../../includes/exams/ai_actions.php';
$_ENV['GEMINI_API_KEY'] = 'test-key';
$ai = et_db();
et_at('2027-04-01 09:00');
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
$sent = [];
$replies = [];
$reply = static fn (array $data, int $status = 200, int $in = 100, int $out = 50): array => ['status' => $status, 'body' => json_encode([
    'candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]], 'finishReason' => 'STOP']],
    'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out, 'totalTokenCount' => $in + $out],
])];
\HOD\Mail\Ai\Gemini::fake(function (array $request) use (&$sent, &$replies): array {
    $sent[] = $request;
    return array_shift($replies) ?? ['status' => 500, 'body' => '{}'];
});
$lastSent = static function () use (&$sent): string {
    return (string) json_encode(end($sent) ?: []);
};

// Questions from pasted notes: drafts through the one check; repeats and unusable ones left out.
exam_question_save($ai, null, 10, ['qtype' => 'true_false', 'body' => 'Grace is a gift.', 'correct_text' => 'True', 'status' => 'approved'], 1);
$replies[] = $reply(['questions' => [
    ['type' => 'one', 'question' => 'What is faith?', 'options' => ['Trust in God', 'A feeling', 'A rule'], 'correct' => 'A', 'topic' => 'Faith', 'explanation' => 'Hebrews 11:1 calls faith trust.'],
    ['type' => 'several', 'question' => 'Which are fruits of the Spirit?', 'options' => ['Love', 'Pride', 'Joy'], 'correct' => ['A', 'C']],
    ['type' => 'true_false', 'question' => 'Grace is a gift.', 'correct' => 'True'],
    ['type' => 'one', 'question' => 'Broken one', 'options' => ['x', 'y'], 'correct' => 'D'],
    ['type' => 'essay', 'question' => 'Write about faith.'],
]]);
$notes = 'Faith is trust in God. The fruit of the Spirit is love, joy and peace. Grace is a gift we do not earn.';
$q = exams_ai_action($ai, 'questions', ['training_id' => 10, 'source' => 'notes', 'notes' => $notes, 'count' => 5,
    'types' => ['single', 'multiple', 'true_false'], 'marks' => 2], 1);
et_eq('questions from notes: 2 drafts, 1 repeat, 2 left out', ['success', 2, '2 drafts added · 1 already in the bank · 2 left out (not usable).'],
    [$q['status'], $q['added'] ?? null, $q['message'] ?? null]);
et_eq('…saved as drafts from the AI, with the marks asked for', 2, et_count($ai, 'training_questions', "source = 'ai' AND status = 'draft' AND marks = 2 AND training_id = 10"));
et_ok('…the request carries the notes and the rules (references, not quoted verses), and no learner', str_contains($lastSent(), 'SOURCE MATERIAL')
    && str_contains($lastSent(), 'Faith is trust in God') && str_contains($lastSent(), 'do not quote the verse')
    && !str_contains($lastSent(), 'Okafor') && !str_contains($lastSent(), 'Ada'));
$log = $ai->query('SELECT user_id, task, training_id, input_tokens, output_tokens, total_tokens, ok FROM training_ai_requests ORDER BY id DESC LIMIT 1')->fetch();
et_eq('…one usage row: who, what, tokens, never the text', [1, 'questions', 10, 100, 50, 150, 1],
    [(int) $log['user_id'], $log['task'], (int) $log['training_id'], (int) $log['input_tokens'], (int) $log['output_tokens'], (int) $log['total_tokens'], (int) $log['ok']]);
et_eq('…the usage table has no column for text', [], array_values(array_filter(array_column($ai->query('PRAGMA table_info(training_ai_requests)')->fetchAll(), 'name'),
    static fn ($c) => in_array($c, ['prompt', 'text', 'answer', 'body', 'source'], true))));

// A PDF goes inline, never stored; anything else is refused before a call.
$calls = count($sent);
et_eq('a file that is not a PDF is refused before any call', ['That file is not a PDF.', $calls],
    [exams_ai_source_parts($ai, ['pdf' => 'hello'])['error'] ?? null, count($sent)]);
$replies[] = $reply(['questions' => [['type' => 'true_false', 'question' => 'The PDF says yes.', 'correct' => 'True']]]);
$r = exams_ai_questions($ai, 1, 10, ['count' => 1, 'types' => ['true_false']], ['pdf' => '%PDF-1.4 tiny']);
et_ok('a PDF is sent inline as application/pdf', ($r['added'] ?? 0) === 1 && str_contains($lastSent(), '"mime_type":"application\/pdf"')
    && str_contains($lastSent(), base64_encode('%PDF-1.4 tiny')));

// From the training's own readings and slides (PR 1b's details.slides_text).
$ai->exec("INSERT INTO training_items (id, training_id, position, type, title, details, created_at) VALUES
    (102, 10, 3, 'reading', 'Deck', '{\"text\":\"\",\"slides_text\":[\"Slide one: prayer\",\"Slide two: fasting\"]}', '2027-01-01 09:00:00'),
    (201, 20, 2, 'reading', 'Other training', '{\"text\":\"Not this one at all, it belongs elsewhere.\"}', '2027-01-01 09:00:00')");
$ai->exec("UPDATE training_items SET details = '{\"text\":\"Notes on prayer and the Holy Spirit for new workers.\"}' WHERE id = 101");
et_eq('the readings offered: those with text or slides, slides marked', [[101, false], [102, true]],
    array_map(static fn ($i) => [$i['id'], $i['slides']], exams_ai_source_items($ai, 10)));
$text = exams_ai_source_text($ai, 10, []);
et_ok('…their text and slides go together, nothing from another training', str_contains($text, 'Slide two: fasting') && str_contains($text, 'Holy Spirit')
    && !str_contains($text, 'belongs elsewhere'));
et_ok('…one item can be chosen', !str_contains(exams_ai_source_text($ai, 10, [102]), 'Holy Spirit'));
et_eq('…and too little text is refused', 'Paste more of the lesson (a few sentences at least).', exams_ai_source_parts($ai, ['notes' => 'Too short'])['error'] ?? null);

// Failures are logged and said plainly.
$replies[] = ['status' => 429, 'body' => '{}'];
et_eq('Gemini busy: a plain message, a failed usage row', ['Gemini is busy. Try again in a minute.', 'busy'],
    [exams_ai_call($ai, 1, 'summary', 10, 'x', 'y')['error'] ?? null, $ai->query('SELECT failure FROM training_ai_requests ORDER BY id DESC LIMIT 1')->fetchColumn()]);
$replies[] = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]]])];
et_eq('an answer that is not the JSON asked for', 'Gemini\'s answer could not be used. Try again.', exams_ai_call($ai, 1, 'summary', 10, 'x', 'y')['error'] ?? null);

// The guards: the church's daily cap and the person's hourly limit, before any call.
$ai->prepare("INSERT INTO training_ai_requests (user_id, task, total_tokens, ok, created_at) VALUES (5, 'questions', ?, 1, '2027-04-01 08:00:00')")
    ->execute([exams_ai_daily_cap()]);
$calls = count($sent);
et_eq('the daily cap reached: refused in words, no call made', ["Today's AI allowance for the church is used up. It starts again at midnight.", $calls],
    [exams_ai_action($ai, 'questions', ['training_id' => 10, 'source' => 'notes', 'notes' => $notes, 'types' => ['single']], 1)['message'] ?? null, count($sent)]);
et_at('2027-04-02 00:01');
et_eq('…it starts again the next day', null, exams_ai_blocked($ai, 1));
for ($i = 0; $i < EXAMS_AI_HOURLY; $i++) {
    $ai->exec("INSERT INTO training_ai_requests (user_id, task, total_tokens, ok, created_at) VALUES (1, 'summary', 1, 1, '2027-04-02 00:00:30')");
}
et_eq('the hourly limit per person', 'You have used AI help 30 times this hour. Try again later.', exams_ai_blocked($ai, 1));
et_eq('…someone else is not held back', null, exams_ai_blocked($ai, 2));
$_ENV['TRAININGS_AI_DAILY_TOKENS'] = '1000';
et_eq('the cap can be set in .env', 1000, exams_ai_daily_cap());
unset($_ENV['TRAININGS_AI_DAILY_TOKENS']);
et_at('2027-04-03 09:00');

// Explanations: suggested once in the approve flow, stored only when the tutor confirms.
$d1 = (int) $ai->query("SELECT id FROM training_questions WHERE body = 'Which are fruits of the Spirit?'")->fetchColumn();
$d2 = (int) $ai->query("SELECT id FROM training_questions WHERE body = 'What is faith?'")->fetchColumn();
$ai->prepare("UPDATE training_questions SET explanation = NULL WHERE id = ?")->execute([$d1]);
$replies[] = $reply(['explanations' => [['id' => $d1, 'explanation' => 'Love and joy are fruits of the Spirit; pride is not.'], ['id' => 999, 'explanation' => 'stray']]]);
$check = exams_action($ai, 'approve_check', ['ids' => [$d1, $d2]], 1);
$byId = array_column($check['items'], null, 'id');
et_eq('approve_check: a suggestion for the one without an explanation, the other kept', [true, 'Love and joy are fruits of the Spirit; pride is not.', false, 'Hebrews 11:1 calls faith trust.'],
    [$byId[$d1]['suggested'], $byId[$d1]['explanation'], $byId[$d2]['suggested'], $byId[$d2]['explanation']]);
et_ok('…the request names the right answer, and nothing about learners', str_contains($lastSent(), 'Right answer: A, C') && !str_contains($lastSent(), 'Okafor'));
et_eq('…nothing is stored yet', [null, 'draft'], array_values($ai->query("SELECT explanation, status FROM training_questions WHERE id = {$d1}")->fetch(PDO::FETCH_NUM)));
$ok = exams_action($ai, 'approve', ['items' => [['id' => $d1, 'explanation' => 'Love and joy are; pride is not.'], ['id' => $d2, 'explanation' => 'Hebrews 11:1 calls faith trust.']]], 1);
et_eq('approve: the tutor\'s version stored, both Ready', ['2 questions are ready.', 'Love and joy are; pride is not.', 2],
    [$ok['message'], $ai->query("SELECT explanation FROM training_questions WHERE id = {$d1}")->fetchColumn(),
     et_count($ai, 'training_questions', "status = 'approved' AND id IN ({$d1}, {$d2})")]);
$plain = exam_question_save($ai, null, 10, ['qtype' => 'true_false', 'body' => 'Prayer matters.', 'correct_text' => 'True'], 1);
$ai->exec("INSERT INTO training_ai_requests (user_id, task, total_tokens, ok, created_at) VALUES (5, 'explain', " . exams_ai_daily_cap() . ", 1, '2027-04-03 08:00:00')");
$calls = count($sent);
$blocked = exams_action($ai, 'approve_check', ['ids' => [$plain['id']]], 1);
et_eq('with the cap reached: no call, the reason given, an empty explanation to write', [$calls, true, ''],
    [count($sent), str_contains((string) $blocked['ai_error'], 'used up'), $blocked['items'][0]['explanation']]);
$GLOBALS['et_grants'] = $learners;
et_eq('learners cannot approve', 'FORBIDDEN', exams_action($ai, 'approve_check', ['ids' => [$plain['id']]], 2)['code'] ?? null);
et_eq('…nor use AI help', 'FORBIDDEN', exams_ai_action($ai, 'status', ['training_id' => 10], 2)['code'] ?? null);
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
et_eq('an unknown training', 'NOT_FOUND', exams_ai_action($ai, 'status', ['training_id' => 999], 1)['code'] ?? null);
et_at('2027-04-04 09:00');

// Content proposals: nothing is created until Apply.
$ai->exec("INSERT INTO trainings (id, title, status, length, created_at) VALUES (30, 'Workers induction', 'draft', 'self_paced', '2027-01-01 09:00:00')");
$ai->exec("INSERT INTO training_sections (training_id, kind, title, position) VALUES (30, 'module', 'Module 1', 1)");
$replies[] = $reply(['sections' => [
    ['title' => 'Welcome', 'items' => [['type' => 'live', 'title' => 'Opening class', 'minutes' => 60], ['type' => 'reading', 'title' => 'Our story', 'note' => 'How the church began.'],
                                     ['type' => 'dance', 'title' => 'Not a type']]],
    ['title' => 'Serving', 'items' => [['type' => 'assignment', 'title' => 'Reflection', 'note' => 'Write half a page.'], ['type' => 'exam', 'title' => 'Short test']]],
]]);
$items0 = et_count($ai, 'training_items', 'training_id = 30');
$o = exams_ai_action($ai, 'outline', ['training_id' => 30, 'notes' => 'An induction for new workers: our story, serving well.', 'sections' => 2], 1);
et_eq('outline: a proposal, an unknown kind left out, nothing created', [2, 2, 2, $items0],
    [count($o['proposal']['sections'] ?? []), count($o['proposal']['sections'][0]['items'] ?? []), count($o['proposal']['sections'][1]['items'] ?? []), et_count($ai, 'training_items', 'training_id = 30')]);
$applied = exams_ai_action($ai, 'outline_apply', ['training_id' => 30, 'proposal' => $o['proposal']], 1);
et_eq('Apply: the empty card reused and renamed, one card added, four items through PR 1\'s builder', ['success', ['Welcome', 'Serving'], 4, 'How the church began.'],
    [$applied['status'], $ai->query('SELECT title FROM training_sections WHERE training_id = 30 ORDER BY position')->fetchAll(PDO::FETCH_COLUMN),
     et_count($ai, 'training_items', 'training_id = 30'),
     json_decode((string) $ai->query("SELECT details FROM training_items WHERE training_id = 30 AND type = 'reading'")->fetchColumn(), true)['text'] ?? null]);
$replies[] = $reply(['summary' => 'Prayer and the Holy Spirit, in short.']);
$sum = exams_ai_action($ai, 'summary', ['training_id' => 10, 'item_id' => 101], 1);
et_eq('summary: a proposal from the reading', 'Prayer and the Holy Spirit, in short.', $sum['summary'] ?? null);
$ai->exec("INSERT INTO training_sections (id, training_id, kind, title, position) VALUES (50, 10, 'module', 'Module 1', 1)");
$ai->exec('UPDATE training_items SET section_id = 50 WHERE training_id = 10');
$add = exams_ai_action($ai, 'summary_apply', ['training_id' => 10, 'item_id' => 101, 'text' => 'Prayer and the Holy Spirit, in short (edited).'], 1);
et_eq('…Add as reading: an optional reading in the same card', ['success', 1],
    [$add['status'], et_count($ai, 'training_items', "training_id = 10 AND type = 'reading' AND title = 'Summary: Notes' AND required = 0 AND section_id = 50")]);
et_eq('sermon prompts need notes first', 'Paste a few lines about the sermon first (title, text, main points).', exams_ai_action($ai, 'prompts', ['training_id' => 10, 'notes' => 'short'], 1)['message'] ?? null);
$replies[] = $reply(['prompts' => ['What were the main points?', '', 'Which scriptures were used?', str_repeat('x', 400)]]);
$pr = exams_ai_action($ai, 'prompts', ['training_id' => 10, 'notes' => 'Sermon: Walking by faith, Hebrews 11. Main points: trust, obedience.'], 1);
et_eq('…prompts cleaned as PR 1 keeps them', [3, 300], [count($pr['prompts'] ?? []), mb_strlen($pr['prompts'][2] ?? '')]);
$ai->exec("INSERT INTO training_items (id, training_id, section_id, position, type, title, details, created_at) VALUES
    (103, 10, 50, 9, 'assignment', 'Sermon review', '{\"kind\":\"sermon_review\",\"instructions\":\"Listen first.\",\"prompts\":[\"Old\"],\"max_mark\":10,\"pass_mark\":6}', '2027-01-01 09:00:00')");
exams_ai_action($ai, 'prompts_apply', ['training_id' => 10, 'item_id' => 103, 'prompts' => ['Main points?', 'Scriptures?']], 1);
$det = json_decode((string) $ai->query('SELECT details FROM training_items WHERE id = 103')->fetchColumn(), true);
et_eq('…Use these: the prompts replaced, the rest of the assignment kept', [['Main points?', 'Scriptures?'], 'Listen first.', 6],
    [$det['prompts'] ?? null, $det['instructions'] ?? null, $det['pass_mark'] ?? null]);

// The shared client stays as Mail uses it.
$replies[] = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hi']]]]], 'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 1, 'totalTokenCount' => 4]])];
et_eq('Mail\'s string prompt still goes as one text part, and usage is read', ['Hi', [['text' => 'plain']], 4],
    [\HOD\Mail\Ai\Gemini::generate('sys', 'plain'), end($sent)['body']['contents'][0]['parts'], \HOD\Mail\Ai\Gemini::$lastUsage['total'] ?? null]);
\HOD\Mail\Ai\Gemini::fake(null);
unset($_ENV['GEMINI_API_KEY']);

$aiPage = (string) file_get_contents($root . '/modules/exams/ai.php');
$aiJs = (string) file_get_contents($root . '/assets/ui/js/pages/exams-ai.js');
$aiApi = (string) file_get_contents($root . '/api/exams_ai_api.php');
$aiActs = (string) file_get_contents($root . '/includes/exams/ai_actions.php');
et_ok('the AI endpoint checks staff access and the CSRF token', str_contains($aiApi, "api_require_can('trainings', 'edit', 'church');") && str_contains($aiApi, 'csrf_require();'));
et_ok('the AI page is strict, with no inline handler, script or style', str_contains($aiPage, "'mode' => 'strict'")
    && preg_match_all('/\son[a-z]+\s*=|<script(?![^>]*type="application\/json")|\sstyle\s*=/i', $aiPage) === 0);
preg_match_all("/HOD\\.actions\\.on\\('(ai\\.[a-z-]+)'/", $aiJs, $m);
$aiHandled = array_values(array_unique($m[1]));
preg_match_all('/data-action(?:-change|-input)?="(ai\.[a-z-]+)"|\'data-action(?:-change|-input)?\' => \'(ai\.[a-z-]+)\'/', $aiPage . $aiJs, $m);
$aiUsed = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
et_eq('every AI action on the page has a handler, and every handler is used', [[], []],
    [array_values(array_diff($aiUsed, $aiHandled)), array_values(array_diff($aiHandled, $aiUsed))]);
preg_match_all("/call\\('([a-z_]+)'/", $aiJs, $m);
et_eq('every action the AI script calls exists', [], array_values(array_filter(array_unique($m[1]), static fn ($a) => !str_contains($aiActs, "case '{$a}':"))));
// Two functions with one name in a page script: the later silently replaces the earlier (the bank's row pick once ran
// the exam's answer code).
foreach (['exams.js' => (string) file_get_contents($root . '/assets/ui/js/pages/exams.js'), 'exams-ai.js' => $aiJs] as $name => $src) {
    preg_match_all('/\bfunction ([A-Za-z_$][\w$]*)\s*\(/', $src, $m);
    et_eq("{$name}: no two functions share a name", [], array_values(array_unique(array_diff_assoc($m[1], array_unique($m[1])))));
}

// ===========================================================================
et_section('12. Import straight into an exam (the set-up page), and finding the bank');
// ===========================================================================
$x = et_db();
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
et_at('2027-04-01 09:00');
$xRows = [ET_HEAD,
    ['One answer', 'Who led Israel out of Egypt?', 'Moses', 'Aaron', '', '', '', 'A', '1', 'Exodus 3.', 'Exodus'],
    ['True/False', 'Jonah was swallowed by a great fish.', '', '', '', '', '', 'True', '1', 'Jonah 1:17.', ''],
    ['Several answers', 'Which are fruits of the Spirit?', 'Love', 'Pride', 'Joy', '', '', 'A, C', '2', 'Galatians 5:22.', ''],
];
$xFile = et_sheet($xRows);
$xCheck = static fn (string $file, ?int $bank = 10): string => (string) exam_import_check($x, $file, 'quiz.xlsx', $bank, 1)['token'];
$xIds = static fn (): array => exam_get($x, 100)['question_ids'] ?? [];

$GLOBALS['et_grants'] = [];
et_eq('without the Academy\'s Edit the import is refused', 'FORBIDDEN', exams_action($x, 'import_confirm', ['token' => $xCheck($xFile), 'item' => 100], 1)['code'] ?? null);
$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);

// The form as the page sends it with the confirm (examValues() in exams.js).
$form = static fn (array $ids, array $over = []): array => $over + ['window_open' => '', 'window_close' => '', 'minutes' => '25', 'pass_percent' => '70',
    'leave_alert' => '4', 'shuffle' => 0, 'part_marks' => 0, 'question_ids' => $ids];
$n1 = exams_action($x, 'import_confirm', ['token' => $xCheck($xFile), 'item' => 100, 'exam' => $form([])], 1);
$e1 = exam_get($x, 100);
et_eq('an exam not saved yet: Ready in the bank, added and saved at once, with the rules the form shows', ['success', 'saved', 3, 3, 3, 25, 70, 4],
    [$n1['status'], $n1['into'] ?? null, $n1['imported'] ?? null, count($e1['question_ids'] ?? []),
     et_count($x, 'training_questions', "training_id = 10 AND status = 'approved' AND source = 'import'"), $e1['minutes'] ?? null, $e1['pass_percent'] ?? null, $e1['leave_alert'] ?? null]);
et_ok('…the page gets the saved exam back, and the toast says what was added',
    ($n1['exam']['question_ids'] ?? null) === $e1['question_ids'] && $n1['message'] === '3 new questions in the bank. 3 questions added to this exam and saved.', $n1['message']);
et_eq('…every row had an explanation, so none to suggest', [], $n1['unexplained'] ?? null);
$saul = exams_action($x, 'import_confirm', ['token' => $xCheck(et_sheet([ET_HEAD,
    ['One answer', 'Who was the first king of Israel?', 'Saul', 'David', '', '', '', 'A', '1', '', '']])), 'item' => 100, 'ready' => 0, 'exam' => $form($e1['question_ids'])], 1);
et_ok('…Ready even when the bank\'s "Mark them Ready" box would be off', ($saul['into'] ?? null) === 'saved' && et_count($x, 'training_questions', "status = 'draft'") === 0);
et_eq('…a row with no explanation is offered for AI suggestions, and the import itself asks nothing of the AI',
    [[(int) $x->query("SELECT id FROM training_questions WHERE body LIKE 'Who was the first king%'")->fetchColumn()], 0],
    [$saul['unexplained'] ?? null, et_count($x, 'training_ai_requests')]);

$q0 = exam_question_save($x, null, 10, ['qtype' => 'true_false', 'body' => 'Is the Bible one book?', 'correct_text' => 'False', 'explanation' => '66 books.', 'status' => 'approved'], 1);
$rules = ['window_open' => '2027-04-02T09:00', 'window_close' => '', 'minutes' => 20, 'pass_percent' => 60, 'shuffle' => 0, 'part_marks' => 1, 'leave_alert' => 5];
exam_save($x, exam_item($x, 100), $rules + ['question_ids' => [$q0['id']]], 1);
$xFile2 = et_sheet(array_merge($xRows, [['One answer', 'Who built the ark?', 'Noah', 'Abraham', '', '', '', 'A', '1', 'Genesis 6.', '']]));
$n2 = exams_action($x, 'import_confirm', ['token' => $xCheck($xFile2), 'item' => 100, 'exam' => ['minutes' => 35] + $rules + ['question_ids' => [$q0['id']]]], 1);
$after = exam_get($x, 100);
et_eq('a saved exam: the new one and the file\'s Ready ones from the bank join the ticked ones, saved at once', ['saved', 1, 4, 5],
    [$n2['into'] ?? null, $n2['imported'] ?? null, $n2['added'] ?? null, count($after['question_ids'])]);
et_eq('…the ticked ones first, then the file\'s', $q0['id'], $after['question_ids'][0]);
et_eq('…saved as the form shows it (a Minutes the tutor typed included), so the page and the exam agree', ['2027-04-02 09:00:00', null, 35, 60, 0, 1, 5],
    [$after['window_open'], $after['window_close'], $after['minutes'], $after['pass_percent'], $after['shuffle'], $after['part_marks'], $after['leave_alert']]);
et_ok('…and the toast says so', str_contains($n2['message'], '4 questions added to this exam and saved.'), $n2['message']);

$bankBefore = et_count($x, 'training_questions');
$stamp = (string) $x->query('SELECT updated_at FROM training_exams WHERE item_id = 100')->fetchColumn();
et_at('2027-04-01 10:00');
$n3 = exams_action($x, 'import_confirm', ['token' => $xCheck($xFile2), 'item' => 100, 'exam' => $form($after['question_ids'], $rules)], 1);
et_eq('the same file again adds nothing twice, and saves nothing', ['unchanged', 0, $bankBefore, $after['question_ids'], $stamp],
    [$n3['into'] ?? null, $n3['imported'] ?? null, et_count($x, 'training_questions'), $xIds(),
     (string) $x->query('SELECT updated_at FROM training_exams WHERE item_id = 100')->fetchColumn()]);
et_ok('…and says so', str_contains($n3['message'], 'No new questions') && str_contains($n3['message'], 'all in this exam already'), $n3['message']);
et_eq('…the claimed check cannot be confirmed twice', 'This file has already been imported.',
    exams_action($x, 'import_confirm', ['token' => (string) $x->query('SELECT token FROM training_question_imports ORDER BY id DESC LIMIT 1')->fetchColumn(), 'item' => 100], 1)['message'] ?? null);

$draftFile = et_sheet([ET_HEAD, ['One answer', 'Who was swallowed by a fish?', 'Jonah', 'Peter', '', '', '', 'A', '1', '', '']]);
exam_import_confirm($x, $xCheck($draftFile), 1, false);   // the bank's import with "Mark them Ready" off: a draft
$n4 = exams_action($x, 'import_confirm', ['token' => $xCheck($draftFile), 'item' => 100, 'exam' => $form($after['question_ids'], $rules)], 1);
et_eq('a question already in the bank as a draft is not put in the exam, and is counted', ['unchanged', 0, $after['question_ids']],
    [$n4['into'] ?? null, count($n4['question_ids'] ?? [1]), $xIds()]);
et_ok('…the page says it was left out', str_contains($n4['message'], '1 is in the bank but not Ready'), $n4['message']);

$bad = exams_action($x, 'import_confirm', ['token' => $xCheck(et_sheet([ET_HEAD, ['One answer', 'Who was the second king?', 'David', 'Saul', '', '', '', 'A', '1', '', '']])),
    'item' => 100, 'exam' => $form($after['question_ids'], ['window_open' => '2027-04-05T09:00', 'window_close' => '2027-04-04T09:00'] + $rules)], 1);
et_eq('a rule on the form that doesn\'t pass saves nothing: the question is in the bank, ticked, and the page says what to fix',
    ['failed', 1, $after['question_ids'], 'Not saved: It must close after it opens. They are ticked: fix it and press Save.'],
    [$bad['into'] ?? null, $bad['imported'] ?? null, $xIds(), str_replace('1 new question in the bank. ', '', $bad['message'] ?? '')]);

$GLOBALS['et_grants'] = array_merge(['trainings:edit:"church"'], $learners);
$noBank = exams_action($x, 'import_confirm', ['token' => $t20 = $xCheck(et_sheet([ET_HEAD, ['One answer', 'Who wrote Acts?', 'Luke', 'Paul', '', '', '', 'A', '1', '', '']]), 20), 'item' => 100], 1);
et_eq('a file checked for another training\'s bank is refused, and nothing is added', ['That file was checked for another bank. Upload it again.', 0, 1],
    [$noBank['message'] ?? null, et_count($x, 'training_questions', 'training_id = 20'), et_count($x, 'training_question_imports', 'token = ? AND imported_at IS NULL', [$t20])]);
$bankOnly = exams_action($x, 'import_confirm', ['token' => $t20, 'ready' => 0], 1);
et_eq('the bank\'s own import is as before: the tutor\'s Ready choice, no exam touched', ['success', 1, [], 'draft'],
    [$bankOnly['status'], $bankOnly['imported'] ?? null, $bankOnly['question_ids'] ?? null,
     (string) $x->query('SELECT status FROM training_questions WHERE training_id = 20')->fetchColumn()]);

// People have started: ask first; papers are fixed at their start, new starters get the new list.
et_at('2027-04-03 09:00');
et_eq('(a learner starts the exam)', 'success', exams_action($x, 'start', ['item' => 100], 2)['status']);
$a2 = (int) $x->query('SELECT id FROM training_attempts WHERE user_id = 2')->fetchColumn();
$paper = static fn (int $attemptId): array => array_map('intval', $x->query('SELECT question_id FROM training_attempt_answers WHERE attempt_id = ' . $attemptId . ' ORDER BY position')->fetchAll(PDO::FETCH_COLUMN));
$paper2 = $paper($a2);
$bankBefore = et_count($x, 'training_questions');
$baptism = $xCheck(et_sheet([ET_HEAD, ['One answer', 'Who baptised Jesus?', 'John', 'Peter', '', '', '', 'A', '1', 'Matthew 3.', '']]));
$ask = exams_action($x, 'import_confirm', ['token' => $baptism, 'item' => 100, 'exam' => $form($after['question_ids'], $rules)], 1);
et_eq('once people have started, the page must confirm first, and nothing is imported before it does',
    ['CONFIRM_STARTED', 1, $bankBefore, 1], [$ask['code'] ?? null, $ask['started'] ?? null, et_count($x, 'training_questions'),
     et_count($x, 'training_question_imports', 'token = ? AND imported_at IS NULL', [$baptism])]);
et_eq('…the confirm says papers are fixed at the start', '1 person has started. Each paper is fixed when it starts, so theirs won\'t change; new starters get the new questions.',
    $ask['message'] ?? null);
$n5 = exams_action($x, 'import_confirm', ['token' => $baptism, 'item' => 100, 'started_ok' => 1,
    'exam' => $form([], ['window_close' => '2027-04-30T17:00'] + $rules)], 1);   // the page's locked ticks can't drop questions
$grown = exam_get($x, 100);
et_eq('…confirmed: the question is added for new starters and saved; none taken away; the locked rules kept, the times taken',
    ['saved', 1, array_merge($after['question_ids'], [$n5['question_ids'][0] ?? 0]), '2027-04-30 17:00:00', 35],
    [$n5['into'] ?? null, $n5['added'] ?? null, $grown['question_ids'], $grown['window_close'], $grown['minutes']]);
et_ok('…the toast says started papers stay as they were', str_contains($n5['message'], 'Papers already started stay as they were.'), $n5['message']);
et_eq('…the started paper is unchanged', $paper2, $paper($a2));
et_eq('(a second learner starts)', 'success', exams_action($x, 'start', ['item' => 100], 3)['status']);
et_eq('…and gets the new question', count($paper2) + 1, count($paper((int) $x->query('SELECT id FROM training_attempts WHERE user_id = 3')->fetchColumn())));
exams_action($x, 'submit', ['item' => 100], 2);
et_eq('…the first is marked on their own paper only', (int) $x->query('SELECT SUM(q.marks) FROM training_attempt_answers a JOIN training_questions q ON q.id = a.question_id WHERE a.attempt_id = ' . $a2)->fetchColumn(),
    (int) $x->query('SELECT max_score FROM training_attempts WHERE id = ' . $a2)->fetchColumn());
$item100 = exam_item($x, 100);
et_eq('adding after the start never removes, and the Save button still can\'t add', [false, false],
    [exam_save($x, $item100, $rules + ['window_close' => '2027-04-30T17:00', 'question_ids' => array_slice($grown['question_ids'], 1)], 1, true)['ok'],
     exam_save($x, $item100, $rules + ['window_close' => '2027-04-30T17:00', 'question_ids' => array_merge($grown['question_ids'], [$q0['id'] + 1000])], 1)['ok']]);
et_eq('…nor change the minutes', false, exam_save($x, $item100, ['minutes' => 50] + $rules + ['window_close' => '2027-04-30T17:00', 'question_ids' => $grown['question_ids']], 1, true)['ok']);
et_eq('exam_import_into_exam() with nothing to add changes nothing', ['state' => 'unchanged', 'added' => 0, 'started' => 0], exam_import_into_exam($x, exam_item($x, 200), [], 1));

$setupSrc = (string) file_get_contents($root . '/modules/exams/setup.php');
et_ok('the set-up page has Import questions, Download template and its own import dialog',
    str_contains($setupSrc, "ui_button('Import questions'") && str_contains($setupSrc, "'data-action' => 'ex.import-open'")
    && str_contains($setupSrc, "ui_button('Download template'") && str_contains($setupSrc, '/api/exams_api.php?action=template')
    && str_contains($setupSrc, "ui_dialog_start('ex-import'"));
et_ok('…with no bank to choose and no Ready box (always this training\'s bank, always Ready)',
    !str_contains($setupSrc, 'data-ex-import-ready') && !str_contains($setupSrc, "'name' => 'bank'"));
et_ok('…and the offer of AI explanations, checked in a dialog before anything is stored',
    str_contains($setupSrc, 'data-ex-import-explain') && str_contains($setupSrc, "ui_dialog_start('ex-approve'") && str_contains($setupSrc, "'data-action' => 'ex.approve-confirm'"));
$setupJs = (string) file_get_contents($root . '/assets/ui/js/pages/exams.js');
et_ok('…the script sends the form with the confirm, asks first when people have started, and handles it all on the set-up page',
    str_contains($setupJs, 'exam: examValues(one(\'#ex-exam-form\'))') && str_contains($setupJs, "err.code === 'CONFIRM_STARTED'")
    && str_contains($setupJs, "if (yes) importSend(btn, true);")
    && preg_match("/cfg\\.page === 'setup'\\) \\{[^}]*ex\\.import-open[^}]*ex\\.import-check[^}]*ex\\.import-confirm[^}]*ex\\.approve-confirm/s", $setupJs) === 1);
et_ok('…AI explanations only when the tutor ticked the offer', str_contains($setupJs, 'if (explain && res.unexplained && res.unexplained.length) approveStart(res.unexplained);'));
et_eq('the bank\'s address helper', ['/modules/exams/', '/modules/exams/?training=10'], [exam_url_bank(), exam_url_bank(10)]);
foreach (['modules/academy/index.php' => "exam_url_bank()", 'modules/academy/training.php' => 'exam_url_bank($tid)'] as $f => $call) {
    $src = (string) file_get_contents($root . '/' . $f);
    et_ok("{$f}: a Question bank button for Trainings Edit, through the helper and only when it exists",
        str_contains($src, "ui_button('Question bank'") && str_contains($src, $call) && str_contains($src, "function_exists('exam_url_bank')")
        && !str_contains($src, "'/modules/exams/"));
}

exams_set_clock(false);
echo PHP_EOL . $GLOBALS['et_passed'] . ' passed, ' . $GLOBALS['et_failed'] . ' failed' . PHP_EOL;
exit($GLOBALS['et_failed'] === 0 ? 0 : 1);
