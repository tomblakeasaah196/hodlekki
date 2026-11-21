<?php
// /includes/exams/access.php
//
// Who may do what, and the actions of api/exams_api.php (exams_action()).
//
//   Tutors (the question bank, exam set-up, results): Trainings, Edit,
//   church-wide: the Academy's own rule (includes/trainings/actions.php
//   trainings_staff_can(); seats in an owner department have it), the same
//   one PR 1's training page asks before it links to the exam set-up.
//   Learners (taking an exam): Trainings on their own records (as PR 1's
//   learner pages) AND an enrolment in the training (training_enrolments,
//   not withdrawn) while the training is published.
// The engine (engine.php) and the bank (bank.php) never check access.

require_once __DIR__ . '/bootstrap.php';

/** A training (PR 1), or null. */
function exams_training(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !exams_table_exists($pdo, 'trainings')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, title, department_id, status FROM trainings WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['department_id'] = $row['department_id'] === null ? null : (int) $row['department_id'];
    return $row;
}

/** The Academy's staff check: Trainings at church-wide reach (Edit = any training, mark work). */
function exams_staff_can(string $level = 'edit'): bool
{
    return can('trainings', $level, 'church');
}

/** May the signed-in person work on this bank ($trainingId null = the shared bank)? */
function exams_may_manage(PDO $pdo, ?int $trainingId): bool
{
    return exams_staff_can('edit') && ($trainingId === null || exams_training($pdo, $trainingId) !== null);
}

/** The banks a tutor works on: the shared bank first, then every training not archived. */
function exams_banks(PDO $pdo): array
{
    if (!exams_staff_can('edit')) {
        return [];
    }
    $out = [['id' => null, 'title' => 'Shared bank']];
    if (exams_table_exists($pdo, 'trainings')) {
        foreach ($pdo->query("SELECT id, title FROM trainings WHERE status <> 'archived' ORDER BY title")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $out[] = ['id' => (int) $t['id'], 'title' => (string) $t['title']];
        }
    }
    return $out;
}

/**
 * May this person sit the exam? Their enrolment id, or null. A standalone test (training kind 'test',
 * includes/trainings/tests.php) is open to every member while published: opening it enrols them.
 */
function exams_learner_enrolment(PDO $pdo, array $item, int $userId): ?int
{
    if (($item['training_status'] ?? 'published') !== 'published' || !can('trainings', 'view', perm_person($userId))) {
        return null;
    }
    $id = exam_enrolment_id($pdo, (int) $item['training_id'], $userId);
    if ($id === null && exams_item_is_test($item)) {
        exams_load_trainings();
        $id = function_exists('trainings_test_enrol') ? trainings_test_enrol($pdo, (int) $item['training_id'], $userId) : null;
    }
    return $id;
}

/** Is this exam item a standalone test's? */
function exams_item_is_test(array $item): bool
{
    return ($item['training_kind'] ?? '') === 'test';
}

/** PR 1's library (Trainings core), loaded on first use, as exam_progress_hook() does. */
function exams_load_trainings(): void
{
    $core = dirname(__DIR__) . '/trainings/bootstrap.php';
    if (!function_exists('trainings_get') && is_file($core)) {
        require_once $core;
    }
}

/**
 * After a standalone test: the "Confirm your email" card (owner, 21 Nov 2026: the code comes after the test, never
 * before it). Null when their email is confirmed; see trainings_entry_email_prompt(). Never breaks the result.
 */
function exams_email_prompt(PDO $pdo, int $userId): ?array
{
    try {
        $root = dirname(__DIR__);
        if (!function_exists('trainings_entry_email_prompt')) {
            foreach (['/campaigns/bootstrap.php', '/trainings/bootstrap.php', '/trainings/entry.php'] as $f) {
                if (!is_file($root . $f)) {
                    return null;
                }
                require_once $root . $f;
            }
        }
        return trainings_entry_email_prompt($pdo, $userId);
    } catch (Throwable $e) {
        error_log('[exams] email prompt: ' . $e->getMessage());
        return null;
    }
}

/** The learner's certificate link for this item's training, when one is held. */
function exams_certificate_url(PDO $pdo, array $item, int $userId): ?string
{
    exams_load_trainings();
    $enrolment = exam_enrolment_id($pdo, (int) $item['training_id'], $userId);
    if ($enrolment === null || !function_exists('trainings_certs_ready') || !trainings_certs_ready($pdo)
        || trainings_certificate_current($pdo, $enrolment) === null) {
        return null;
    }
    return '/api/trainings_certificate.php?e=' . $enrolment;
}

/** A question as the tutor's screen shows it. */
function exams_question_row(array $q, array $inUse = []): array
{
    return [
        'id' => $q['id'], 'type' => $q['qtype'], 'type_label' => EXAM_QTYPES[$q['qtype']] ?? $q['qtype'], 'text' => $q['body'],
        'options' => $q['options'], 'correct' => $q['correct'], 'answer' => exam_answer_text($q, $q['correct']),
        'marks' => $q['marks'], 'explanation' => (string) ($q['explanation'] ?? ''), 'topic' => (string) $q['topic'],
        'status' => $q['status'], 'source' => (string) $q['source'], 'in_use' => isset($inUse[$q['id']]),
    ];
}

/** Which of these questions someone has answered. @return array<int,true> */
function exams_in_use(PDO $pdo, array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values($ids), 400) as $chunk) {
        $stmt = $pdo->prepare('SELECT DISTINCT question_id FROM training_attempt_answers WHERE question_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = true;
        }
    }
    return $out;
}

/** The exam's set-up screen: the settings, and the Ready questions it may use. */
function exams_setup_view(PDO $pdo, array $item): array
{
    $exam = exam_get($pdo, (int) $item['id']);
    $pool = array_merge(exam_question_list($pdo, (int) $item['training_id'], ['status' => 'approved']),
                        exam_question_list($pdo, null, ['status' => 'approved']));
    $now = exams_now();
    return [
        'item' => ['id' => $item['id'], 'title' => $item['title'], 'training_id' => $item['training_id'], 'training' => $item['training_title']],
        'exam' => $exam,
        'started' => $exam !== null ? exam_attempt_count($pdo, (int) $exam['id']) : 0,
        'window' => $exam !== null ? exam_window($exam, $now) : 'not_set',
        'review' => $exam !== null ? exam_review_state($pdo, $exam, $now) : null,
        'pool' => array_map(static fn ($q) => exams_question_row($q) + ['shared' => $q['training_id'] === null], $pool),
        'test' => exams_test_setup($pdo, $item),
    ];
}

/** A standalone test's own settings for its set-up page (null for a course exam). */
function exams_test_setup(PDO $pdo, array $item): ?array
{
    if (!exams_item_is_test($item)) {
        return null;
    }
    exams_load_trainings();
    $t = function_exists('trainings_get') ? trainings_get($pdo, (int) $item['training_id']) : null;
    if ($t === null) {
        return null;
    }
    return ['title' => (string) $t['title'], 'status' => (string) $t['status'], 'gives_certificate' => (bool) $t['gives_certificate'],
            'url' => trainings_test_url((int) $item['id'])];
}

/** What the learner's page shows before, during and after. */
function exams_take_view(PDO $pdo, array $item, int $userId): array
{
    $exam = exam_get($pdo, (int) $item['id']);
    $now = exams_now();
    if ($exam === null) {
        return ['state' => 'not_set', 'item' => ['title' => $item['title'], 'training' => $item['training_title']]];
    }
    exam_finalize_due($pdo, $now, (int) $exam['id']);
    $attempts = exam_attempts_of($pdo, (int) $exam['id'], $userId);
    $attempt = $attempts === [] ? null : $attempts[count($attempts) - 1];
    $st = exam_attempts_state($exam, $attempts, $now);
    $best = $st['best'];
    $view = [
        'item' => ['title' => $item['title'], 'training' => $item['training_title']],
        'test' => exams_item_is_test($item),
        'window' => exam_window($exam, $now),
        'opens' => $exam['window_open'], 'closes' => $exam['window_close'],
        'minutes' => $exam['minutes'], 'questions' => count($exam['question_ids']), 'pass_percent' => $exam['pass_percent'],
        'part_marks' => (bool) $exam['part_marks'],
        'review' => exam_review_state($pdo, $exam, $now),
        // Retakes (docs/EXAMS.md §3): the take page offers "Try again (attempt N of M)" when can_retake.
        'attempts_allowed' => $st['allowed'], 'attempts_used' => $st['used'], 'attempts_left' => $st['left'],
        'attempt_no' => $attempt['attempt_no'] ?? null, 'can_retake' => $st['can_retake'],
        'best' => $best === null ? null : ['score' => $best['score'], 'max' => $best['max_score'], 'percent' => $best['percent'],
                                           'passed' => (bool) $best['passed'], 'attempt_no' => $best['attempt_no']],
        'attempts' => array_map(static fn ($a) => ['attempt_no' => $a['attempt_no'], 'status' => $a['status'], 'score' => $a['score'],
            'max' => $a['max_score'], 'percent' => $a['percent'], 'passed' => $a['passed'] === null ? null : (bool) $a['passed'],
            'submitted_at' => $a['submitted_at'], 'best' => $best !== null && $a['id'] === $best['id']], $attempts),
    ];
    if ($attempt === null) {
        return $view + ['state' => 'ready'];
    }
    if ($attempt['status'] === 'started') {
        return $view + ['state' => 'started', 'paper' => exam_paper($pdo, $attempt, $now)];
    }
    return $view + ['state' => 'done', 'result' => exam_result_for_learner($pdo, $exam, $attempt, $now),
                    'certificate' => exams_certificate_url($pdo, $item, $userId),
                    'email' => exams_item_is_test($item) ? exams_email_prompt($pdo, $userId) : null];
}

/**
 * One action. The caller has checked sign-in and CSRF. $files: $_FILES (the import).
 * @return array{status:string, ...}
 */
function exams_action(PDO $pdo, string $do, array $in, int $me, array $files = []): array
{
    $fail = static fn (string $msg, array $errors = [], ?string $code = null): array =>
        ['status' => 'error', 'message' => $msg] + ($errors !== [] ? ['errors' => $errors] : []) + ($code ? ['code' => $code] : []);
    $forbidden = static fn (): array => ['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'You do not have access to this.'];
    $bank = isset($in['training_id']) && (string) $in['training_id'] !== '' ? (int) $in['training_id'] : null;

    // ---- The question bank (tutors) -------------------------------------
    switch ($do) {
        case 'questions':
            if (!exams_may_manage($pdo, $bank)) {
                return $forbidden();
            }
            $qs = exam_question_list($pdo, $bank, ['status' => (string) ($in['status'] ?? ''), 'q' => (string) ($in['q'] ?? '')]);
            $used = exams_in_use($pdo, array_column($qs, 'id'));
            return ['status' => 'success', 'questions' => array_map(static fn ($q) => exams_question_row($q, $used), $qs)];

        case 'question_save':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            if ($id !== null) {
                $q = exam_question_get($pdo, $id);
                if ($q === null || !exams_may_manage($pdo, $q['training_id'])) {
                    return $forbidden();
                }
            } elseif (!exams_may_manage($pdo, $bank)) {
                return $forbidden();
            }
            $r = exam_question_save($pdo, $id, $bank, [
                'qtype' => (string) ($in['type'] ?? ''), 'body' => $in['text'] ?? '', 'options' => (array) ($in['options'] ?? []),
                'correct' => array_map('intval', (array) ($in['correct'] ?? [])), 'marks' => (string) ($in['marks'] ?? ''),
                'explanation' => $in['explanation'] ?? '', 'topic' => $in['topic'] ?? '',
                'status' => !empty($in['ready']) ? 'approved' : 'draft',
            ], $me);
            if (!$r['ok']) {
                return $fail(implode(' ', array_slice($r['errors'], 0, 3)), $r['errors']);
            }
            $q = exam_question_get($pdo, (int) $r['id']);
            return ['status' => 'success', 'message' => $id === null ? 'Question added.' : 'Saved.', 'warnings' => $r['warnings'] ?? [],
                    'question' => exams_question_row($q, exams_in_use($pdo, [$q['id']]))];

        case 'question_status':
            $q = exam_question_get($pdo, (int) ($in['id'] ?? 0));
            if ($q === null || !exams_may_manage($pdo, $q['training_id'])) {
                return $forbidden();
            }
            $to = (string) ($in['to'] ?? '');
            if (!exam_question_set_status($pdo, (int) $q['id'], $to, $me)) {
                return $fail('That change is not possible.');
            }
            return ['status' => 'success', 'message' => ['approved' => 'Ready to use.', 'draft' => 'Back to draft.', 'archived' => 'Archived.'][$to] ?? 'Saved.',
                    'question' => exams_question_row((array) exam_question_get($pdo, (int) $q['id']))];

        // Marking questions Ready: explanations are written ONCE here (owner's answer 12). Questions without one get
        // a suggestion from Gemini, in one batch; the tutor checks and edits them, and only 'approve' stores them.
        case 'approve_check':
            $qs = [];
            foreach (array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? []))))) as $id) {
                $q = exam_question_get($pdo, $id);
                if ($q === null || !exams_may_manage($pdo, $q['training_id'])) {
                    return $forbidden();
                }
                $qs[] = $q;
            }
            if ($qs === []) {
                return $fail('Choose the questions first.');
            }
            $need = array_values(array_filter($qs, static fn ($q) => trim((string) ($q['explanation'] ?? '')) === ''));
            $suggested = [];
            $aiError = null;
            if ($need !== []) {
                require_once __DIR__ . '/ai.php';
                $aiError = exams_ai_blocked($pdo, $me);
                if ($aiError === null) {
                    $r = exams_ai_explanations($pdo, $me, $need);
                    $suggested = $r['explanations'];
                    $aiError = $r['error'] ?? null;
                }
            }
            return ['status' => 'success', 'ai_error' => $aiError, 'items' => array_map(static fn ($q) => [
                'id' => $q['id'], 'text' => $q['body'], 'type_label' => EXAM_QTYPES[$q['qtype']] ?? $q['qtype'], 'answer' => exam_answer_text($q, $q['correct']),
                'explanation' => trim((string) ($q['explanation'] ?? '')) !== '' ? (string) $q['explanation'] : ($suggested[$q['id']] ?? ''),
                'suggested' => isset($suggested[$q['id']]) && trim((string) ($q['explanation'] ?? '')) === '',
            ], $qs)];

        case 'approve':
            $done = 0;
            foreach ((array) ($in['items'] ?? []) as $it) {
                $q = exam_question_get($pdo, (int) ($it['id'] ?? 0));
                if ($q === null || !exams_may_manage($pdo, $q['training_id'])) {
                    return $forbidden();
                }
                $explanation = exams_text($it['explanation'] ?? '', 2000);
                if ($explanation !== trim((string) ($q['explanation'] ?? ''))) {
                    $r = exam_question_save($pdo, (int) $q['id'], null, ['qtype' => $q['qtype'], 'body' => $q['body'], 'options' => $q['options'],
                        'correct' => $q['correct'], 'marks' => (string) $q['marks'], 'explanation' => $explanation, 'topic' => $q['topic']], $me);
                    if (!$r['ok']) {
                        return $fail(implode(' ', $r['errors']));
                    }
                }
                exam_question_set_status($pdo, (int) $q['id'], 'approved', $me);
                $done++;
            }
            return ['status' => 'success', 'message' => $done . ($done === 1 ? ' question is' : ' questions are') . ' ready.', 'count' => $done];

        case 'import_check':
            if (!exams_may_manage($pdo, $bank)) {
                return $forbidden();
            }
            $f = $files['sheet'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                return $fail('Choose the Excel file to upload.');
            }
            if ((int) ($f['size'] ?? 0) > 5 * 1024 * 1024) {
                return $fail('The file is too big (5 MB at most).');
            }
            if (strtolower((string) pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') {
                return $fail('Upload an Excel (.xlsx) file: the template, filled in.');
            }
            $r = exam_import_check($pdo, (string) $f['tmp_name'], (string) ($f['name'] ?? ''), $bank, $me);
            return $r['ok'] ? ['status' => 'success', 'token' => $r['token'], 'counts' => $r['counts'],
                               'rows' => array_map('exams_import_row_view', $r['rows'])]
                            : $fail((string) $r['error']);

        case 'import_confirm':
            $imp = exam_import_get($pdo, (string) ($in['token'] ?? ''), $me);
            if ($imp === null) {
                return $fail('That check has expired. Upload the file again.');
            }
            if (!exams_may_manage($pdo, $imp['training_id'])) {
                return $forbidden();
            }
            // From an exam's set-up page: this training's bank, always Ready,
            // added to that exam and saved in the same step (owner, 12 Nov
            // 2026: "add and save at once"; exam_import_into_exam()).
            $into = null;
            if (!empty($in['item'])) {
                $into = exam_item($pdo, (int) $in['item']);
                if ($into === null) {
                    return $fail('That exam no longer exists.', [], 'NOT_FOUND');
                }
                if ($imp['training_id'] !== (int) $into['training_id']) {
                    return $fail('That file was checked for another bank. Upload it again.');
                }
                // People have started: the tutor confirms first (the page asks), before anything is imported.
                $exam = exam_get($pdo, (int) $into['id']);
                $started = $exam !== null ? exam_attempt_count($pdo, (int) $exam['id']) : 0;
                if ($started > 0 && empty($in['started_ok'])) {
                    return ['status' => 'error', 'code' => 'CONFIRM_STARTED', 'started' => $started,
                            'message' => exams_started_note($started)];
                }
            }
            $r = exam_import_confirm($pdo, (string) $in['token'], $me, $into !== null || !empty($in['ready']));
            if (!$r['ok']) {
                return $fail($r['message']);
            }
            if ($into === null) {
                return ['status' => 'success', 'message' => $r['message'], 'imported' => $r['imported'], 'question_ids' => $r['question_ids']];
            }
            $put = exam_import_into_exam($pdo, $into, $r['question_ids'], $me, is_array($in['exam'] ?? null) ? $in['exam'] : [], !empty($in['started_ok']));
            return ['status' => 'success', 'message' => exams_import_exam_message($r, $put), 'imported' => $r['imported'],
                    'question_ids' => $r['question_ids'], 'into' => $put['state'], 'added' => $put['added'],
                    'unexplained' => exams_unexplained($pdo, $r['new_ids'])] + exams_setup_view($pdo, $into);
    }

    // ---- One exam item ----------------------------------------------------
    $item = exam_item($pdo, (int) ($in['item'] ?? 0));
    if ($item === null) {
        return $fail('That exam no longer exists.', [], 'NOT_FOUND');
    }
    $tutor = in_array($do, ['setup', 'exam_save', 'answers', 'results', 'attempt', 'end_attempt', 'test_save', 'test_publish', 'test_delete'], true);
    if ($tutor && !exams_may_manage($pdo, (int) $item['training_id'])) {
        return $forbidden();
    }
    switch ($do) {
        case 'setup':
            return ['status' => 'success'] + exams_setup_view($pdo, $item);

        case 'exam_save':
            $r = exam_save($pdo, $item, $in, $me);
            return $r['ok'] ? ['status' => 'success', 'message' => 'Exam saved.'] + exams_setup_view($pdo, $item)
                            : $fail(implode(' ', array_slice($r['errors'], 0, 3)), $r['errors']);

        case 'test_delete':
            exams_load_trainings();
            $t = exams_item_is_test($item) && function_exists('trainings_get') ? trainings_get($pdo, (int) $item['training_id']) : null;
            if ($t === null) {
                return $fail('That is not a test.', [], 'NOT_FOUND');
            }
            if (!can('trainings', 'delete', 'church')) {
                return $forbidden();
            }
            $r = trainings_test_delete($pdo, $t);
            return !empty($r['ok']) ? ['status' => 'success', 'message' => $r['message'], 'redirect' => '/modules/academy/#tab=tests']
                : $fail((string) $r['message'], [], $r['code'] ?? null);

        case 'test_save':
        case 'test_publish':
            // A standalone test's name, certificate switch and status (includes/trainings/tests.php).
            exams_load_trainings();
            $t = exams_item_is_test($item) && function_exists('trainings_get') ? trainings_get($pdo, (int) $item['training_id']) : null;
            if ($t === null) {
                return $fail('That is not a test.', [], 'NOT_FOUND');
            }
            $r = $do === 'test_save' ? trainings_test_save($pdo, $t, $in) : trainings_test_publish($pdo, $t, !empty($in['publish']));
            return !empty($r['ok']) ? ['status' => 'success', 'message' => $r['message']] + exams_setup_view($pdo, exam_item($pdo, (int) $item['id']) ?? $item)
                : $fail((string) $r['message'], isset($r['field']) ? [$r['field'] => $r['message']] : [], $r['code'] ?? null);

        case 'answers':
            $exam = exam_get($pdo, (int) $item['id']);
            if ($exam === null) {
                return $fail('Set the exam up first.');
            }
            exam_show_answers($pdo, $exam, !empty($in['show']), $me);
            return ['status' => 'success', 'message' => !empty($in['show']) ? 'Answers shown.' : 'Answers hidden.'] + exams_setup_view($pdo, $item);

        case 'results':
            $exam = exam_get($pdo, (int) $item['id']);
            return $exam === null ? ['status' => 'success', 'rows' => [], 'summary' => null]
                : ['status' => 'success'] + exam_results($pdo, $exam);

        case 'attempt':
        case 'end_attempt':
            $a = exam_attempt_by_id($pdo, (int) ($in['attempt_id'] ?? 0));
            if ($a === null || $a['item_id'] !== (int) $item['id']) {
                return $fail('That attempt no longer exists.');
            }
            if ($do === 'end_attempt') {
                $a = exam_submit($pdo, (int) $a['id'], 'tutor') ?? $a;
            }
            return ['status' => 'success', 'attempt' => $a, 'message' => $do === 'end_attempt' ? 'Handed in.' : null] + exam_attempt_detail($pdo, $a);
    }

    // ---- The learner ------------------------------------------------------
    $enrolment = exams_learner_enrolment($pdo, $item, $me);
    if ($enrolment === null) {
        return $fail(exams_item_is_test($item) ? 'This test is not open.' : 'This exam is for people enrolled in ' . $item['training_title'] . '.', [], 'NOT_ENROLLED');
    }
    $exam = exam_get($pdo, (int) $item['id']);
    if ($exam === null && $do !== 'take') {
        return $fail('This exam is not ready yet.');
    }
    switch ($do) {
        case 'take':
            return ['status' => 'success'] + exams_take_view($pdo, $item, $me);

        case 'start':
            $r = exam_start($pdo, $exam, $me, $enrolment);
            return $r['ok'] ? ['status' => 'success'] + exams_take_view($pdo, $item, $me) : $fail((string) $r['error'], [], $r['code'] ?? null);

        case 'answer':
        case 'focus':
        case 'submit':
            $a = exam_attempt_get($pdo, (int) $exam['id'], $me);
            if ($a === null) {
                return $fail('Start the exam first.');
            }
            if ($do === 'answer') {
                $r = exam_answer($pdo, $a, (int) ($in['n'] ?? 0), array_values((array) ($in['chosen'] ?? [])));
                return $r['ok'] ? ['status' => 'success', 'seconds_left' => exam_seconds_left($a, exams_now())]
                    : $fail((string) $r['error'], [], $r['code'] ?? null);
            }
            if ($do === 'focus') {
                $r = exam_note_focus($pdo, $exam, $a, (string) ($in['kind'] ?? ''));
                return ['status' => 'success', 'leaves' => $r['leaves']];
            }
            exam_submit($pdo, (int) $a['id'], 'learner');
            return ['status' => 'success', 'message' => 'Handed in.'] + exams_take_view($pdo, $item, $me);
    }
    return $fail('Unknown action.');
}

/** What the set-up page says after Import: the bank, then the exam. */
function exams_import_exam_message(array $r, array $put): string
{
    $n = (int) $r['imported'];
    $bank = $n === 0 ? 'No new questions.' : $n . ($n === 1 ? ' new question' : ' new questions') . ' in the bank.';
    $added = (int) $put['added'];
    $exam = [
        'saved' => $added . ($added === 1 ? ' question' : ' questions') . ' added to this exam and saved.'
            . ((int) ($put['started'] ?? 0) > 0 ? ' Papers already started stay as they were.' : ''),
        'unchanged' => $r['question_ids'] === [] ? 'Nothing to add to this exam.' : 'They are all in this exam already.',
        'confirm' => 'Not added: people have started this exam.',
        'failed' => 'Not saved: ' . (string) (($put['errors'] ?? [''])[0]) . ' They are ticked: fix it and press Save.',
    ][$put['state']] ?? '';
    $notReady = (int) ($r['not_ready'] ?? 0);
    $left = $notReady > 0 ? ' ' . $notReady . ($notReady === 1 ? ' is' : ' are') . ' in the bank but not Ready, so left out.' : '';
    return trim($bank . ' ' . $exam . $left);
}

/** The confirm before adding questions to an exam people have started. */
function exams_started_note(int $started): string
{
    return $started . ($started === 1 ? ' person has' : ' people have') . ' started. Each paper is fixed when it starts, so theirs won\'t change;'
        . ' new starters get the new questions.';
}

/** Of these questions, the ones with no explanation yet (the set-up page offers AI suggestions for them). */
function exams_unexplained(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT id FROM training_questions WHERE (explanation IS NULL OR TRIM(explanation) = '') AND id IN ("
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** An import row as the check screen shows it. */
function exams_import_row_view(array $r): array
{
    $f = $r['fields'] ?? [];
    return [
        'row' => $r['row'], 'status' => $r['status'], 'errors' => $r['errors'] ?? [], 'warnings' => $r['warnings'] ?? [],
        'type' => (string) ($f['type'] ?? ''), 'text' => mb_substr((string) ($f['question'] ?? ''), 0, 160),
        'correct' => (string) ($f['correct'] ?? ''), 'marks' => (string) ($f['marks'] ?? ''),
        'explained' => trim((string) ($f['explanation'] ?? '')) !== '',
    ];
}
