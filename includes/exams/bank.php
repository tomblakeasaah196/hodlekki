<?php
// /includes/exams/bank.php
//
// The question bank (training_questions) and the Excel import.
//
// Three kinds of question (owner, 12 Nov 2026): one correct answer, several
// correct answers (each exam chooses whether part-right earns part marks),
// and true/false. No written answers in exams. Options are A–E. Every
// question is checked by exam_question_check(), whoever writes it: the form,
// the import, and later the AI drafts (PR 4).
//
// A question has a content hash (import_hash: the bank, the type, the words,
// the options and the answer, normalised), UNIQUE in the table, so the same
// question can never be in a bank twice and importing the same sheet again
// adds nothing. Once anyone has answered a question in an exam, only its
// explanation and topic can change (their marks were given against it).
//
// The import: download the template (exam_template_bytes()), fill it in,
// upload it; exam_import_check() reads it, checks EVERY row and keeps the
// result (not the file) in training_question_imports; nothing is added
// until exam_import_confirm().

const EXAM_QTYPES  = ['single' => 'One answer', 'multiple' => 'Several answers', 'true_false' => 'True/False'];
const EXAM_QSTATUS = ['draft' => 'Draft', 'approved' => 'Ready', 'archived' => 'Archived'];
const EXAM_LETTERS = ['A', 'B', 'C', 'D', 'E'];
const EXAM_TF      = ['True', 'False'];
const EXAM_IMPORT_MAX  = 500;   // questions per sheet
const EXAM_IMPORT_ROWS = EXAM_IMPORT_MAX + 50;
const EXAM_TEMPLATE_COLUMNS = [
    'type'        => ['Type', 16, 'One answer, Several answers or True/False (pick from the list).'],
    'question'    => ['Question', 48, 'The question, as people will read it.'],
    'a'           => ['Option A', 22, 'The first choice. Leave A–E empty for True/False.'],
    'b'           => ['Option B', 22, 'The second choice.'],
    'c'           => ['Option C', 22, 'Optional.'],
    'd'           => ['Option D', 22, 'Optional.'],
    'e'           => ['Option E', 22, 'Optional. Five choices at most.'],
    'correct'     => ['Correct', 12, 'The letter of the right choice (B). Several answers: every right letter (A, C). True/False: True or False.'],
    'marks'       => ['Marks', 8, 'A whole number from 1 to 100. Empty means 1.'],
    'explanation' => ['Explanation', 48, 'Shown only to people who get it wrong, after the exam. Say why the answer is right.'],
    'topic'       => ['Topic', 18, 'Optional: a short label to group questions (for example "Salvation").'],
];

// ---------------------------------------------------------------------------
// Checking a question
// ---------------------------------------------------------------------------

/** 'single' | 'multiple' | 'true_false' from what a person typed, or null. */
function exam_qtype_from_text(string $s): ?string
{
    $k = strtolower((string) preg_replace('/[^a-z]/i', '', $s));
    return match ($k) {
        'single', 'oneanswer', 'one', 'onecorrectanswer', 'singleanswer', 'mcq', 'multiplechoice' => 'single',
        'multiple', 'severalanswers', 'several', 'severalcorrectanswers', 'multipleanswers', 'multi' => 'multiple',
        'truefalse', 'tf', 'trueorfalse', 'yesno' => 'true_false',
        default => null,
    };
}

/**
 * The right answer(s) from a cell like "B", "A, C", "AC", "True".
 * @return array{ok:bool, correct:list<int>, error:?string}
 */
function exam_correct_from_text(string $text, string $qtype, int $optionCount): array
{
    $t = strtoupper(trim($text));
    $bad = static fn (string $why): array => ['ok' => false, 'correct' => [], 'error' => $why];
    if ($t === '') {
        return $bad('Say which answer is right.');
    }
    if ($qtype === 'true_false') {
        $map = ['TRUE' => 0, 'T' => 0, 'YES' => 0, 'A' => 0, 'FALSE' => 1, 'F' => 1, 'NO' => 1, 'B' => 1];
        return isset($map[$t]) ? ['ok' => true, 'correct' => [$map[$t]], 'error' => null] : $bad('Write True or False.');
    }
    $letters = preg_match('/^[A-E]+$/', $t) ? str_split($t) : preg_split('/[\s,;&\/+]+|\bAND\b/', $t, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($letters as $l) {
        $l = trim($l, " .)(");
        $i = array_search($l, EXAM_LETTERS, true);
        if ($i === false) {
            return $bad('Use the letters A to E for the right answer.');
        }
        if ($i >= $optionCount) {
            return $bad('Option ' . $l . ' is empty, so it cannot be the right answer.');
        }
        $out[$i] = $i;
    }
    $out = array_values($out);
    sort($out);
    if ($qtype === 'single' && count($out) !== 1) {
        return $bad('One answer: give exactly one letter (or choose Several answers).');
    }
    if ($qtype === 'multiple' && count($out) === $optionCount) {
        return $bad('Several answers: not every option can be right.');
    }
    return ['ok' => true, 'correct' => $out, 'error' => null];
}

/**
 * Check and clean one question.
 * $in: qtype, body, options (list, A first; blanks at the end are dropped),
 *      correct (list of indexes) or correct_text ("A, C"), marks, explanation, topic.
 *
 * @return array{ok:bool, q:array, errors:list<string>, warnings:list<string>}
 */
function exam_question_check(array $in): array
{
    $errors = $warnings = [];
    $qtype = isset(EXAM_QTYPES[$in['qtype'] ?? '']) ? (string) $in['qtype'] : exam_qtype_from_text((string) ($in['qtype'] ?? ''));
    if ($qtype === null) {
        $errors[] = 'Type: choose One answer, Several answers or True/False.';
        $qtype = 'single';
    }
    $body = exams_text($in['body'] ?? '', 2000);
    if ($body === '') {
        $errors[] = 'Write the question.';
    }
    $options = [];
    if ($qtype === 'true_false') {
        $options = EXAM_TF;
    } else {
        $raw = array_values(array_map(static fn ($o) => exams_line($o, 300), (array) ($in['options'] ?? [])));
        while ($raw !== [] && end($raw) === '') {
            array_pop($raw);
        }
        if (count($raw) > 5) {
            $errors[] = 'Five options at most (A to E).';
            $raw = array_slice($raw, 0, 5);
        }
        foreach ($raw as $i => $o) {
            if ($o === '') {
                $errors[] = 'Option ' . EXAM_LETTERS[$i] . ' is empty but a later option is filled.';
            }
        }
        if (count($raw) < 2) {
            $errors[] = 'Give at least two options (A and B).';
        }
        $seen = [];
        foreach ($raw as $i => $o) {
            $k = mb_strtolower($o);
            if ($o !== '' && isset($seen[$k])) {
                $errors[] = 'Options ' . EXAM_LETTERS[$seen[$k]] . ' and ' . EXAM_LETTERS[$i] . ' are the same.';
            }
            $seen[$k] ??= $i;
        }
        $options = $raw;
    }
    $correct = [];
    if (array_key_exists('correct', $in) && is_array($in['correct'])) {
        $idx = array_values(array_unique(array_map('intval', $in['correct'])));
        sort($idx);
        $text = implode(',', array_map(static fn ($i) => $qtype === 'true_false' ? (EXAM_TF[$i] ?? '?') : (EXAM_LETTERS[$i] ?? '?'), $idx));
        $c = exam_correct_from_text($text, $qtype, count($options));
    } else {
        $c = exam_correct_from_text((string) ($in['correct_text'] ?? ''), $qtype, count($options));
    }
    if (!$c['ok']) {
        $errors[] = 'Correct: ' . $c['error'];
    } else {
        $correct = $c['correct'];
    }
    $marksRaw = trim((string) ($in['marks'] ?? ''));
    $marks = 1;
    if ($marksRaw !== '') {
        if (!preg_match('/^\d+$/', $marksRaw) || (int) $marksRaw < 1 || (int) $marksRaw > 100) {
            $errors[] = 'Marks: a whole number from 1 to 100.';
        } else {
            $marks = (int) $marksRaw;
        }
    }
    $explanation = exams_text($in['explanation'] ?? '', 2000);
    if ($explanation === '') {
        $warnings[] = 'No explanation: people who get it wrong will see none.';
    }
    $topic = exams_line($in['topic'] ?? '', 120);
    return [
        'ok' => $errors === [],
        'q' => ['qtype' => $qtype, 'body' => $body, 'options' => $options, 'correct' => $correct, 'marks' => $marks,
                'explanation' => $explanation, 'topic' => $topic],
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/** The question's identity in its bank: the same words, options and answer hash the same. */
function exam_question_hash(?int $trainingId, array $q): string
{
    $norm = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    return hash('sha256', exams_json([
        $trainingId ?? 'shared', $q['qtype'], $norm((string) $q['body']),
        array_map($norm, array_map('strval', (array) $q['options'])), array_values((array) $q['correct']),
    ]));
}

// ---------------------------------------------------------------------------
// The bank
// ---------------------------------------------------------------------------

function exam_question_decode(array $row): array
{
    foreach (['id', 'training_id', 'marks', 'created_by', 'updated_by', 'approved_by'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
    }
    $row['options'] = json_decode((string) $row['options_json'], true) ?: [];
    $row['correct'] = array_map('intval', json_decode((string) $row['correct_json'], true) ?: []);
    unset($row['options_json'], $row['correct_json']);
    return $row;
}

function exam_question_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_questions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? exam_question_decode($row) : null;
}

/**
 * A bank's questions ($trainingId null = the shared bank).
 * $f: status (draft|approved|archived|'' = not archived), topic, q (search), ids.
 */
function exam_question_list(PDO $pdo, ?int $trainingId, array $f = []): array
{
    $where = [$trainingId === null ? 'training_id IS NULL' : 'training_id = ?'];
    $params = $trainingId === null ? [] : [$trainingId];
    $status = (string) ($f['status'] ?? '');
    if (isset(EXAM_QSTATUS[$status])) {
        $where[] = 'status = ?';
        $params[] = $status;
    } else {
        $where[] = "status <> 'archived'";
    }
    if (($f['topic'] ?? '') !== '') {
        $where[] = 'topic = ?';
        $params[] = (string) $f['topic'];
    }
    if (trim((string) ($f['q'] ?? '')) !== '') {
        $where[] = '(body LIKE ? OR topic LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $f['q'])) . '%';
        array_push($params, $like, $like);
    }
    $stmt = $pdo->prepare('SELECT * FROM training_questions WHERE ' . implode(' AND ', $where) . ' ORDER BY topic, id LIMIT 2000');
    $stmt->execute($params);
    return array_map('exam_question_decode', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Has anyone answered it in an exam? Then its marks stand and its content is fixed. */
function exam_question_in_use(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM training_attempt_answers WHERE question_id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Add ($id null) or change a question.
 * @return array{ok:bool, id?:int, errors?:list<string>, warnings?:list<string>}
 */
function exam_question_save(PDO $pdo, ?int $id, ?int $trainingId, array $in, int $actor, string $source = 'manual'): array
{
    $check = exam_question_check($in);
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $q = $check['q'];
    $now = exams_sql_dt(exams_now());
    if ($id !== null) {
        $old = exam_question_get($pdo, $id);
        if ($old === null) {
            return ['ok' => false, 'errors' => ['That question no longer exists.']];
        }
        $trainingId = $old['training_id'];
        $same = $old['qtype'] === $q['qtype'] && $old['body'] === $q['body'] && $old['options'] === $q['options']
            && $old['correct'] === $q['correct'] && $old['marks'] === $q['marks'];
        if (!$same && exam_question_in_use($pdo, $id)) {
            return ['ok' => false, 'errors' => ['People have answered this in an exam, so only its explanation and topic can change.']];
        }
    }
    $hash = exam_question_hash($trainingId, $q);
    try {
        if ($id === null) {
            $status = in_array($in['status'] ?? 'draft', ['draft', 'approved'], true) ? (string) ($in['status'] ?? 'draft') : 'draft';
            $pdo->prepare('INSERT INTO training_questions (training_id, topic, qtype, body, options_json, correct_json, marks, explanation, status,
                                                           source, import_hash, created_by, created_at, approved_by, approved_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$trainingId, $q['topic'], $q['qtype'], $q['body'], exams_json($q['options']), exams_json($q['correct']),
                           $q['marks'], $q['explanation'] !== '' ? $q['explanation'] : null, $status, $source, $hash, $actor, $now,
                           $status === 'approved' ? $actor : null, $status === 'approved' ? $now : null]);
            return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'warnings' => $check['warnings']];
        }
        $pdo->prepare('UPDATE training_questions SET topic = ?, qtype = ?, body = ?, options_json = ?, correct_json = ?, marks = ?,
                              explanation = ?, import_hash = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$q['topic'], $q['qtype'], $q['body'], exams_json($q['options']), exams_json($q['correct']), $q['marks'],
                       $q['explanation'] !== '' ? $q['explanation'] : null, $hash, $actor, $now, $id]);
        return ['ok' => true, 'id' => $id, 'warnings' => $check['warnings']];
    } catch (PDOException $e) {
        if (exams_is_duplicate_key($e)) {
            return ['ok' => false, 'errors' => ['This question is already in the bank.']];
        }
        throw $e;
    }
}

/** Ready (approved), back to draft, or archived. */
function exam_question_set_status(PDO $pdo, int $id, string $status, int $actor): bool
{
    if (!isset(EXAM_QSTATUS[$status])) {
        return false;
    }
    $now = exams_sql_dt(exams_now());
    $stmt = $pdo->prepare('UPDATE training_questions SET status = ?, updated_by = ?, updated_at = ?,
                                  approved_by = CASE WHEN ? = \'approved\' THEN ? ELSE approved_by END,
                                  approved_at = CASE WHEN ? = \'approved\' THEN ? ELSE approved_at END
                            WHERE id = ?');
    $stmt->execute([$status, $actor, $now, $status, $actor, $status, $now, $id]);
    return $stmt->rowCount() === 1;
}

/** "B" or "A, C" or "True": a question's right answer in words. */
function exam_answer_text(array $q, array $indexes): string
{
    $labels = $q['qtype'] === 'true_false' ? EXAM_TF : EXAM_LETTERS;
    return implode(', ', array_map(static fn ($i) => $labels[$i] ?? '?', $indexes));
}

// ---------------------------------------------------------------------------
// The Excel template
// ---------------------------------------------------------------------------

/** The .xlsx template: a Questions sheet (headings + three examples) and a Guide sheet. */
function exam_template_bytes(): string
{
    $head = $widths = [];
    foreach (EXAM_TEMPLATE_COLUMNS as [$label, $width]) {
        $head[] = ['v' => $label, 's' => 'head'];
        $widths[] = $width;
    }
    $ex = static fn (array $cells): array => array_map(static fn ($v) => is_int($v) ? $v : ['v' => $v, 's' => 'example'], $cells);
    $last = EXAM_IMPORT_MAX + 4;
    $questions = [
        'name' => 'Questions',
        'freeze' => true,
        'widths' => $widths,
        'rows' => [
            $head,
            $ex(['One answer', 'Example: Which of these is listed first among the fruit of the Spirit?', 'Love', 'Joy', 'Peace', 'Patience', '',
                 'A', 1, 'Galatians 5:22 lists love first.', 'Example']),
            $ex(['Several answers', 'Example: Which of these books are Gospels?', 'Matthew', 'Acts', 'John', 'Romans', '',
                 'A, C', 2, 'The four Gospels are Matthew, Mark, Luke and John. Acts and Romans come after them.', 'Example']),
            $ex(['True/False', 'Example: The Book of Psalms is in the Old Testament.', '', '', '', '', '',
                 'True', 1, 'Psalms is one of the Old Testament books of poetry.', 'Example']),
        ],
        'lists' => [['range' => 'A2:A' . $last, 'values' => array_values(EXAM_QTYPES), 'title' => 'Type',
                     'prompt' => 'One answer, Several answers or True/False.']],
        'numbers' => [['range' => 'I2:I' . $last, 'min' => 1, 'max' => 100, 'title' => 'Marks', 'prompt' => 'A whole number from 1 to 100. Empty means 1.']],
    ];
    $guideRows = [
        [['v' => 'Column', 's' => 'head'], ['v' => 'What to write', 's' => 'head']],
    ];
    foreach (EXAM_TEMPLATE_COLUMNS as [$label, , $help]) {
        $guideRows[] = [['v' => $label, 's' => 'wrap'], ['v' => $help, 's' => 'wrap']];
    }
    foreach ([
        '',
        'How it works',
        'One row per question, on the Questions sheet. Keep the first row (the headings) as it is.',
        'Rows whose question starts with "Example:" are skipped, so you can keep or delete the three examples.',
        'Upload the file in Academy → Questions → Import. Every row is checked and shown before anything is added; fix the rows marked in red and upload again.',
        'Importing the same file again never adds a question twice.',
        'Up to ' . EXAM_IMPORT_MAX . ' questions per file.',
        'Written answers are not supported in exams: use one answer, several answers or true/false.',
    ] as $i => $line) {
        $guideRows[] = [['v' => $line, 's' => $i === 1 ? 'head' : 'note'], ''];
    }
    return xlsx_write([$questions, ['name' => 'Guide', 'widths' => [22, 90], 'rows' => $guideRows]]);
}

// ---------------------------------------------------------------------------
// The import
// ---------------------------------------------------------------------------

/** Which column holds what, from the heading row. @return array<string,int>|null */
function exam_import_columns(array $cells): ?array
{
    $map = [];
    foreach ($cells as $col => $text) {
        $k = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $text));
        $key = match (true) {
            $k === 'type' || $k === 'questiontype' => 'type',
            $k === 'question' || $k === 'questions' => 'question',
            in_array($k, ['optiona', 'a'], true) => 'a',
            in_array($k, ['optionb', 'b'], true) => 'b',
            in_array($k, ['optionc', 'c'], true) => 'c',
            in_array($k, ['optiond', 'd'], true) => 'd',
            in_array($k, ['optione', 'e'], true) => 'e',
            in_array($k, ['correct', 'correctanswer', 'correctanswers', 'answer', 'answers'], true) => 'correct',
            in_array($k, ['marks', 'mark', 'points', 'score'], true) => 'marks',
            in_array($k, ['explanation', 'explain', 'why'], true) => 'explanation',
            in_array($k, ['topic', 'topics', 'section'], true) => 'topic',
            default => null,
        };
        if ($key !== null && !isset($map[$key])) {
            $map[$key] = (int) $col;
        }
    }
    return isset($map['question'], $map['type'], $map['correct']) ? $map : null;
}

/**
 * Check every row of a sheet (xlsx_read() rows). Rows are not saved here.
 * @return array{ok:bool, error:?string, rows:list<array>}
 */
function exam_import_parse(array $sheetRows): array
{
    $cols = null;
    $out = [];
    foreach ($sheetRows as $r => $cells) {
        if ($cols === null) {
            $cols = exam_import_columns($cells);
            if ($cols === null && $r >= 10) {
                break;
            }
            continue;
        }
        $get = static fn (string $k): string => isset($cols[$k]) ? trim((string) ($cells[$cols[$k]] ?? '')) : '';
        $fields = [];
        foreach (array_keys(EXAM_TEMPLATE_COLUMNS) as $k) {
            $fields[$k] = $get($k);
        }
        if (implode('', $fields) === '') {
            continue;
        }
        if (preg_match('/^\s*(example|e\.g\.)\s*[:\-]/i', $fields['question'])) {
            $out[] = ['row' => (int) $r, 'status' => 'example', 'errors' => [], 'warnings' => [], 'fields' => $fields];
            continue;
        }
        $check = exam_question_check([
            'qtype' => $fields['type'], 'body' => $fields['question'],
            'options' => [$fields['a'], $fields['b'], $fields['c'], $fields['d'], $fields['e']],
            'correct_text' => $fields['correct'], 'marks' => $fields['marks'],
            'explanation' => $fields['explanation'], 'topic' => $fields['topic'],
        ]);
        $out[] = ['row' => (int) $r, 'status' => $check['ok'] ? 'ok' : 'error', 'errors' => $check['errors'],
                  'warnings' => $check['warnings'], 'fields' => $fields, 'q' => $check['ok'] ? $check['q'] : null];
    }
    if ($cols === null) {
        return ['ok' => false, 'error' => 'The first row must be the headings from the template (Type, Question, Option A … Correct). Download the template and use that.', 'rows' => []];
    }
    return ['ok' => true, 'error' => null, 'rows' => $out];
}

/**
 * Read an uploaded sheet, check every row against the form's rules, the rest
 * of the sheet and the bank, and keep the result for confirming.
 *
 * @return array{ok:bool, error?:string, token?:string, rows?:list<array>, counts?:array}
 */
function exam_import_check(PDO $pdo, string $path, string $fileName, ?int $trainingId, int $actor): array
{
    $sheet = xlsx_read($path, 'Questions');
    if (!$sheet['ok']) {
        return ['ok' => false, 'error' => (string) $sheet['error']];
    }
    $parsed = exam_import_parse($sheet['rows']);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => (string) $parsed['error']];
    }
    $rows = $parsed['rows'];
    $real = array_filter($rows, static fn ($r) => $r['status'] !== 'example');
    if ($real === []) {
        return ['ok' => false, 'error' => 'No questions found under the headings. Add one row per question.'];
    }
    if (count($real) > EXAM_IMPORT_MAX || $sheet['truncated']) {
        return ['ok' => false, 'error' => 'Up to ' . EXAM_IMPORT_MAX . ' questions per file. Split it into smaller files.'];
    }

    // The same question twice in the sheet, or already in the bank.
    $firstRow = [];
    $hashes = [];
    foreach ($rows as $i => $r) {
        if ($r['status'] !== 'ok') {
            continue;
        }
        $h = exam_question_hash($trainingId, $r['q']);
        $rows[$i]['hash'] = $h;
        if (isset($firstRow[$h])) {
            $rows[$i]['status'] = 'duplicate';
            $rows[$i]['warnings'] = ['The same question as row ' . $firstRow[$h] . '.'];
            continue;
        }
        $firstRow[$h] = $r['row'];
        $hashes[] = $h;
    }
    $known = [];
    foreach (array_chunk($hashes, 300) as $chunk) {
        $stmt = $pdo->prepare('SELECT import_hash, status FROM training_questions WHERE import_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $known[(string) $k['import_hash']] = (string) $k['status'];
        }
    }
    foreach ($rows as $i => $r) {
        if ($r['status'] === 'ok' && isset($known[$r['hash']])) {
            $rows[$i]['status'] = 'duplicate';
            $rows[$i]['warnings'] = [$known[$r['hash']] === 'archived' ? 'Already in the bank (archived).' : 'Already in the bank.'];
        }
    }
    $counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0, 'example' => 0];
    foreach ($rows as $r) {
        $counts[$r['status']]++;
    }

    $now = exams_now();
    $pdo->prepare('DELETE FROM training_question_imports WHERE created_at < ?')->execute([exams_sql_dt($now->modify('-1 day'))]);
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO training_question_imports (token, training_id, file_name, rows_json, ok_rows, error_rows, duplicate_rows, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $trainingId, exams_line($fileName, 190), exams_json($rows), $counts['ok'], $counts['error'], $counts['duplicate'],
                   $actor, exams_sql_dt($now)]);
    return ['ok' => true, 'token' => $token, 'rows' => $rows, 'counts' => $counts];
}

/** A checked sheet waiting for confirmation, if it is this person's and still fresh. */
function exam_import_get(PDO $pdo, string $token, int $actor): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM training_question_imports WHERE token = ? AND created_by = ? AND created_at >= ?');
    $stmt->execute([$token, $actor, exams_sql_dt(exams_now()->modify('-1 day'))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['training_id'] = $row['training_id'] === null ? null : (int) $row['training_id'];
    $row['rows'] = json_decode((string) $row['rows_json'], true) ?: [];
    unset($row['rows_json']);
    return $row;
}

/**
 * Add the rows that passed. Run once: a second confirm adds nothing; a
 * question someone else added meanwhile is skipped by the UNIQUE hash.
 *
 * @return array{ok:bool, imported:int, skipped:int, message:string}
 */
function exam_import_confirm(PDO $pdo, string $token, int $actor, bool $ready): array
{
    $imp = exam_import_get($pdo, $token, $actor);
    if ($imp === null) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'message' => 'That check has expired. Upload the file again.'];
    }
    $claim = $pdo->prepare('UPDATE training_question_imports SET imported_at = ? WHERE id = ? AND imported_at IS NULL');
    $claim->execute([exams_sql_dt(exams_now()), (int) $imp['id']]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'message' => 'This file has already been imported.'];
    }
    $added = $skipped = 0;
    foreach ($imp['rows'] as $r) {
        if (($r['status'] ?? '') !== 'ok' || !is_array($r['q'] ?? null)) {
            continue;
        }
        $res = exam_question_save($pdo, null, $imp['training_id'], $r['q'] + ['status' => $ready ? 'approved' : 'draft'], $actor, 'import');
        $res['ok'] ? $added++ : $skipped++;
    }
    $pdo->prepare('UPDATE training_question_imports SET imported = ? WHERE id = ?')->execute([$added, (int) $imp['id']]);
    $msg = $added . ($added === 1 ? ' question added' : ' questions added') . ($skipped ? ', ' . $skipped . ' already there' : '') . '.';
    return ['ok' => true, 'imported' => $added, 'skipped' => $skipped, 'message' => $msg];
}
