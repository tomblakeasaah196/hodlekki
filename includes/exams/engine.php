<?php
// /includes/exams/engine.php
//
// Exams, "fair and firm" (owner, 12 Nov 2026):
//   - an exam opens and closes at set times (window_open / window_close);
//   - ONE attempt per person: UNIQUE (exam_id, user_id), claimed by insert;
//   - a countdown: the attempt's deadline is fixed on the server when it
//     starts (the earlier of start + minutes and the window's close) and
//     answers after it are refused;
//   - questions, and the options inside each, shuffled per person; the order
//     each person saw is stored, and the browser only ever gets the shown
//     positions — never which option is right, before or after;
//   - auto-submit at zero: the page submits when its clock reaches zero, and
//     the server ends any attempt past its deadline whenever anyone looks
//     (exam_finalize_due()), so a closed laptop cannot keep one open;
//   - leaving the exam tab is noted; after leave_alert times the tutor is
//     told, once;
//   - marking happens on the server, once, when the attempt ends; several-
//     answer questions earn part marks only when the exam says so;
//   - afterwards a person sees their score, and — for the questions they got
//     wrong, once nobody can still be helped by them (exam_review_state()) — the right answer and the stored
//     explanation. Nothing calls the AI while marking.
// Exams hang off PR 1's training items (type 'exam'); exam_progress_hook()
// tells PR 1's training_progress when someone has finished.

const EXAM_GRACE_SECONDS = 20;     // network slack after the deadline
const EXAM_MINUTES_MAX   = 300;

// ---------------------------------------------------------------------------
// Items and settings
// ---------------------------------------------------------------------------

/** A training item of type exam, with its training (PR 1's tables), or null. */
function exam_item(PDO $pdo, int $itemId): ?array
{
    if ($itemId <= 0 || !exams_table_exists($pdo, 'training_items') || !exams_table_exists($pdo, 'trainings')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT i.id, i.training_id, i.title, i.type, t.title AS training_title, t.department_id, t.status AS training_status
                             FROM training_items i JOIN trainings t ON t.id = i.training_id
                            WHERE i.id = ? AND i.type = 'exam'");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach (['id', 'training_id', 'department_id'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int) $row[$k];
    }
    return $row;
}

function exam_decode(array $row): array
{
    foreach (['id', 'item_id', 'training_id', 'minutes', 'pass_percent', 'shuffle', 'part_marks', 'leave_alert', 'created_by', 'updated_by'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int) $row[$k];
    }
    // The tutor's "Show answers" switch (migration 20261209090100; absent before it runs).
    $row['answers_shown_at'] = $row['answers_shown_at'] ?? null;
    $row['answers_shown_by'] = isset($row['answers_shown_by']) ? (int) $row['answers_shown_by'] : null;
    $row['question_ids'] = array_values(array_map('intval', json_decode((string) $row['question_ids_json'], true) ?: []));
    unset($row['question_ids_json']);
    return $row;
}

/** The exam set up on an item, or null. */
function exam_get(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_exams WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? exam_decode($row) : null;
}

function exam_get_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_exams WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? exam_decode($row) : null;
}

function exam_attempt_count(PDO $pdo, int $examId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_attempts WHERE exam_id = ?');
    $stmt->execute([$examId]);
    return (int) $stmt->fetchColumn();
}

/** "2026-11-20T09:00" or "2026-11-20 09:00" (Lagos) → a time, '' → null. @return DateTimeImmutable|null|false (false = unreadable) */
function exam_parse_time($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $v)) {
        return false;
    }
    $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr(str_replace('T', ' ', $v), 0, 16), new DateTimeZone(EXAMS_TZ));
    return $at === false ? false : $at;
}

/**
 * Set up (or change) the exam on an item.
 * $in: window_open, window_close, minutes, pass_percent, shuffle, part_marks, leave_alert, question_ids.
 * Once anyone has started, the questions, the time, the pass mark and the
 * marking rules are fixed; the window can still move and the alert change.
 *
 * @return array{ok:bool, errors?:list<string>, exam?:array}
 */
function exam_save(PDO $pdo, array $item, array $in, int $actor): array
{
    $errors = [];
    $open = exam_parse_time($in['window_open'] ?? '');
    $close = exam_parse_time($in['window_close'] ?? '');
    if ($open === false) {
        $errors[] = 'Opens: pick a date and time.';
    }
    if ($close === false) {
        $errors[] = 'Closes: pick a date and time.';
    }
    if ($open instanceof DateTimeImmutable && $close instanceof DateTimeImmutable && $close <= $open) {
        $errors[] = 'It must close after it opens.';
    }
    $minutes = (int) ($in['minutes'] ?? 0);
    if ($minutes < 1 || $minutes > EXAM_MINUTES_MAX) {
        $errors[] = 'Time: from 1 to ' . EXAM_MINUTES_MAX . ' minutes.';
    }
    $pass = (int) ($in['pass_percent'] ?? -1);
    if ($pass < 0 || $pass > 100) {
        $errors[] = 'Pass mark: from 0 to 100%.';
    }
    $alert = (int) ($in['leave_alert'] ?? 3);
    if ($alert < 1 || $alert > 50) {
        $errors[] = 'Tell me after: from 1 to 50 leaves.';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['question_ids'] ?? [])), static fn ($i) => $i > 0)));
    if ($ids === []) {
        $errors[] = 'Choose at least one question.';
    } else {
        $stmt = $pdo->prepare('SELECT id, training_id, status FROM training_questions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $found[(int) $q['id']] = $q;
        }
        foreach ($ids as $id) {
            $q = $found[$id] ?? null;
            if ($q === null || ($q['training_id'] !== null && (int) $q['training_id'] !== $item['training_id'])) {
                $errors[] = 'A chosen question is not in this training\'s bank.';
                break;
            }
            if ($q['status'] !== 'approved') {
                $errors[] = 'Only questions marked Ready can go in an exam.';
                break;
            }
        }
    }
    $shuffle = !empty($in['shuffle']) ? 1 : 0;
    $part = !empty($in['part_marks']) ? 1 : 0;
    $old = exam_get($pdo, (int) $item['id']);
    if ($old !== null && exam_attempt_count($pdo, (int) $old['id']) > 0
        && ($old['question_ids'] !== $ids || $old['minutes'] !== $minutes || $old['pass_percent'] !== $pass
            || $old['shuffle'] !== $shuffle || $old['part_marks'] !== $part)) {
        $errors[] = 'People have started it, so only the times and the leave alert can change.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => array_values(array_unique($errors))];
    }
    $now = exams_sql_dt(exams_now());
    $values = [exams_json($ids), $open ? exams_sql_dt($open) : null, $close ? exams_sql_dt($close) : null, $minutes, $pass, $shuffle, $part, $alert];
    if ($old === null) {
        try {
            $pdo->prepare('INSERT INTO training_exams (question_ids_json, window_open, window_close, minutes, pass_percent, shuffle, part_marks, leave_alert,
                                                       item_id, training_id, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [(int) $item['id'], (int) $item['training_id'], $actor, $now]));
        } catch (PDOException $e) {
            if (!exams_is_duplicate_key($e)) {
                throw $e;
            }
            return ['ok' => false, 'errors' => ['Someone else set this exam up just now. Reload to see it.']];
        }
    } else {
        $pdo->prepare('UPDATE training_exams SET question_ids_json = ?, window_open = ?, window_close = ?, minutes = ?, pass_percent = ?, shuffle = ?,
                              part_marks = ?, leave_alert = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute(array_merge($values, [$actor, $now, (int) $old['id']]));
    }
    return ['ok' => true, 'exam' => exam_get($pdo, (int) $item['id'])];
}

/**
 * Put imported questions into an exam (the set-up page's Import questions).
 * They join the ones already chosen; a question already in it is not added
 * twice. A saved exam nobody has started is saved at once, its rules
 * unchanged. One not saved yet waits for the tutor's Save, because its
 * rules aren't set: the page ticks them. Once people have started, its
 * questions can't change, so they stay in the bank only.
 *
 * @param list<int> $ids Ready questions of this training's bank (exam_import_confirm()'s question_ids)
 * @return array{state:string, added:int, errors?:list<string>} state: saved | unchanged | unsaved | started | failed
 */
function exam_import_into_exam(PDO $pdo, array $item, array $ids, int $actor): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $exam = exam_get($pdo, (int) $item['id']);
    if ($ids === []) {
        return ['state' => 'unchanged', 'added' => 0];
    }
    if ($exam === null) {
        return ['state' => 'unsaved', 'added' => count($ids)];
    }
    if (exam_attempt_count($pdo, (int) $exam['id']) > 0) {
        return ['state' => 'started', 'added' => 0];
    }
    $new = array_values(array_diff($ids, $exam['question_ids']));
    if ($new === []) {
        return ['state' => 'unchanged', 'added' => 0];
    }
    $r = exam_save($pdo, $item, [
        'window_open' => (string) ($exam['window_open'] ?? ''), 'window_close' => (string) ($exam['window_close'] ?? ''),
        'minutes' => $exam['minutes'], 'pass_percent' => $exam['pass_percent'], 'leave_alert' => $exam['leave_alert'],
        'shuffle' => $exam['shuffle'], 'part_marks' => $exam['part_marks'],
        'question_ids' => array_merge($exam['question_ids'], $new),
    ], $actor);
    return $r['ok'] ? ['state' => 'saved', 'added' => count($new)] : ['state' => 'failed', 'added' => 0, 'errors' => $r['errors']];
}

/** not_open | open | closed */
function exam_window(array $exam, DateTimeImmutable $now): string
{
    if ($exam['window_open'] !== null && $now < exams_dt((string) $exam['window_open'])) {
        return 'not_open';
    }
    if ($exam['window_close'] !== null && $now >= exams_dt((string) $exam['window_close'])) {
        return 'closed';
    }
    return 'open';
}

/**
 * When a learner may see the right answers and explanations for the
 * questions they got wrong: only once nobody can still be helped by them.
 *   shown  a tutor pressed Show answers (answers_shown_at), at any time;
 *   close  the exam has a closing time: from then;
 *   end    no closing time, but the training has an end date: the day after it;
 *   tutor  neither (self-paced): only when a tutor shows them.
 * ("Once everyone enrolled has sat it" was left out: someone enrolling later
 * would reopen the leak.)
 *
 * @return array{open:bool, when:string, at:?string}
 */
function exam_review_state(PDO $pdo, array $exam, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    if (!empty($exam['answers_shown_at'])) {
        return ['open' => true, 'when' => 'shown', 'at' => (string) $exam['answers_shown_at']];
    }
    if ($exam['window_close'] !== null) {
        return ['open' => $now >= exams_dt((string) $exam['window_close']), 'when' => 'close', 'at' => (string) $exam['window_close']];
    }
    $ends = null;
    if (exams_table_exists($pdo, 'trainings')) {
        $stmt = $pdo->prepare('SELECT ends_on FROM trainings WHERE id = ?');
        $stmt->execute([(int) $exam['training_id']]);
        $ends = $stmt->fetchColumn() ?: null;
    }
    if ($ends !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $ends)) {
        $from = exams_dt(substr((string) $ends, 0, 10) . ' 00:00:00')->modify('+1 day');
        return ['open' => $now >= $from, 'when' => 'end', 'at' => exams_sql_dt($from)];
    }
    return ['open' => false, 'when' => 'tutor', 'at' => null];
}

/** The tutor's switch: show the answers to everyone who has finished (or hide them again). */
function exam_show_answers(PDO $pdo, array $exam, bool $show, int $actor): void
{
    $pdo->prepare('UPDATE training_exams SET answers_shown_at = ?, answers_shown_by = ? WHERE id = ?')
        ->execute([$show ? exams_sql_dt(exams_now()) : null, $show ? $actor : null, (int) $exam['id']]);
}

// ---------------------------------------------------------------------------
// The attempt
// ---------------------------------------------------------------------------

function exam_attempt_decode(array $row): array
{
    foreach (['id', 'exam_id', 'item_id', 'user_id', 'enrolment_id', 'max_score', 'passed', 'leaves'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int) $row[$k];
    }
    foreach (['score', 'percent'] as $k) {
        $row[$k] = $row[$k] === null ? null : (float) $row[$k];
    }
    return $row;
}

function exam_attempt_get(PDO $pdo, int $examId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_attempts WHERE exam_id = ? AND user_id = ?');
    $stmt->execute([$examId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? exam_attempt_decode($row) : null;
}

function exam_attempt_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_attempts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? exam_attempt_decode($row) : null;
}

/** A fair shuffle (random_int, Fisher–Yates). */
function exam_shuffled(array $list): array
{
    $list = array_values($list);
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
    }
    return $list;
}

function exam_event(PDO $pdo, int $attemptId, string $kind, ?string $detail = null, ?DateTimeImmutable $at = null): void
{
    $pdo->prepare('INSERT INTO training_attempt_events (attempt_id, kind, detail, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$attemptId, $kind, $detail !== null ? mb_substr($detail, 0, 255) : null, exams_sql_dt($at ?? exams_now())]);
}

/**
 * Start the one attempt (or resume it while its time runs).
 * @return array{ok:bool, attempt?:array, code?:string, error?:string}
 *   code: NOT_OPEN | CLOSED | DONE | EMPTY
 */
function exam_start(PDO $pdo, array $exam, int $userId, ?int $enrolmentId = null, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    exam_finalize_due($pdo, $now, (int) $exam['id']);
    $have = exam_attempt_get($pdo, (int) $exam['id'], $userId);
    if ($have !== null) {
        return $have['status'] === 'started'
            ? ['ok' => true, 'attempt' => $have]
            : ['ok' => false, 'code' => 'DONE', 'error' => 'You have taken this exam.'];
    }
    $state = exam_window($exam, $now);
    if ($state !== 'open') {
        return ['ok' => false, 'code' => $state === 'closed' ? 'CLOSED' : 'NOT_OPEN',
                'error' => $state === 'closed' ? 'This exam has closed.' : 'This exam is not open yet.'];
    }
    // Only questions still Ready, in the tutor's order.
    $ids = $exam['question_ids'];
    $questions = [];
    if ($ids !== []) {
        $stmt = $pdo->prepare("SELECT id, qtype, options_json FROM training_questions WHERE status = 'approved' AND id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $byId[(int) $q['id']] = $q;
        }
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $questions[] = $byId[$id];
            }
        }
    }
    if ($questions === []) {
        return ['ok' => false, 'code' => 'EMPTY', 'error' => 'This exam has no questions yet. Tell your tutor.'];
    }
    $deadline = $now->modify('+' . (int) $exam['minutes'] . ' minutes');
    if ($exam['window_close'] !== null && exams_dt((string) $exam['window_close']) < $deadline) {
        $deadline = exams_dt((string) $exam['window_close']);
    }
    $order = !empty($exam['shuffle']) ? exam_shuffled($questions) : $questions;

    $own = !$pdo->inTransaction();
    try {
        if ($own) {
            $pdo->beginTransaction();
        }
        $pdo->prepare("INSERT INTO training_attempts (exam_id, item_id, user_id, enrolment_id, status, started_at, deadline_at)
                       VALUES (?, ?, ?, ?, 'started', ?, ?)")
            ->execute([(int) $exam['id'], (int) $exam['item_id'], $userId, $enrolmentId, exams_sql_dt($now), exams_sql_dt($deadline)]);
        $attemptId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO training_attempt_answers (attempt_id, question_id, position, option_order) VALUES (?, ?, ?, ?)');
        foreach ($order as $pos => $q) {
            $n = count(json_decode((string) $q['options_json'], true) ?: []);
            $idx = range(0, max(0, $n - 1));
            // True/False keeps its order: shuffling it helps nobody and confuses everybody.
            if (!empty($exam['shuffle']) && $q['qtype'] !== 'true_false') {
                $idx = exam_shuffled($idx);
            }
            $ins->execute([$attemptId, (int) $q['id'], $pos + 1, implode(',', $idx)]);
        }
        exam_event($pdo, $attemptId, 'start', null, $now);
        if ($own) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (exams_is_duplicate_key($e)) {
            // Two tabs pressed Start at once: the other one won; use its attempt.
            $have = exam_attempt_get($pdo, (int) $exam['id'], $userId);
            if ($have !== null) {
                return $have['status'] === 'started' ? ['ok' => true, 'attempt' => $have]
                    : ['ok' => false, 'code' => 'DONE', 'error' => 'You have taken this exam.'];
            }
        }
        throw $e;
    }
    return ['ok' => true, 'attempt' => exam_attempt_by_id($pdo, $attemptId)];
}

/** The attempt's rows: answers joined to their questions, in the order this person sees them. */
function exam_attempt_rows(PDO $pdo, int $attemptId): array
{
    $stmt = $pdo->prepare('SELECT a.*, q.qtype, q.body, q.options_json, q.correct_json, q.marks, q.explanation, q.topic
                             FROM training_attempt_answers a JOIN training_questions q ON q.id = a.question_id
                            WHERE a.attempt_id = ? ORDER BY a.position');
    $stmt->execute([$attemptId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['options'] = json_decode((string) $r['options_json'], true) ?: [];
        $r['correct'] = array_map('intval', json_decode((string) $r['correct_json'], true) ?: []);
        $r['order'] = array_map('intval', explode(',', (string) $r['option_order']));
        $r['chosen'] = $r['chosen_json'] === null ? [] : array_map('intval', json_decode((string) $r['chosen_json'], true) ?: []);
        $out[] = $r;
    }
    return $out;
}

/** Seconds left on the attempt's clock (never below 0). */
function exam_seconds_left(array $attempt, DateTimeImmutable $now): int
{
    return max(0, exams_dt((string) $attempt['deadline_at'])->getTimestamp() - $now->getTimestamp());
}

/**
 * The paper as the browser gets it: the questions in this person's order, the
 * options in this person's order (as shown positions only), what they have
 * chosen so far. Never which option is right.
 */
function exam_paper(PDO $pdo, array $attempt, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    $qs = [];
    foreach (exam_attempt_rows($pdo, (int) $attempt['id']) as $r) {
        $shownOf = array_flip($r['order']);   // original index → shown position
        $qs[] = [
            'n' => (int) $r['position'],
            'type' => (string) $r['qtype'],
            'text' => (string) $r['body'],
            'marks' => (int) $r['marks'],
            'options' => array_map(static fn ($i) => (string) ($r['options'][$i] ?? ''), $r['order']),
            'chosen' => array_values(array_map(static fn ($i) => $shownOf[$i] ?? -1, $r['chosen'])),
        ];
    }
    return ['questions' => $qs, 'seconds_left' => exam_seconds_left($attempt, $now), 'deadline' => (string) $attempt['deadline_at']];
}

/**
 * Save an answer: $shown are the positions the person ticked on question $n.
 * Refused once the attempt has ended or its time is up.
 *
 * @return array{ok:bool, error?:string, code?:string}
 */
function exam_answer(PDO $pdo, array $attempt, int $n, array $shown, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    if ($attempt['status'] !== 'started') {
        return ['ok' => false, 'code' => 'ENDED', 'error' => 'This exam has ended.'];
    }
    if ($now->getTimestamp() > exams_dt((string) $attempt['deadline_at'])->getTimestamp() + EXAM_GRACE_SECONDS) {
        exam_submit($pdo, (int) $attempt['id'], 'timer', $now);
        return ['ok' => false, 'code' => 'ENDED', 'error' => 'Time is up. Your answers were handed in.'];
    }
    $stmt = $pdo->prepare('SELECT a.id, a.option_order, q.qtype FROM training_attempt_answers a JOIN training_questions q ON q.id = a.question_id
                            WHERE a.attempt_id = ? AND a.position = ?');
    $stmt->execute([(int) $attempt['id'], $n]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'That question is not in your exam.'];
    }
    $order = array_map('intval', explode(',', (string) $row['option_order']));
    $picked = [];
    foreach ($shown as $s) {
        if (!is_numeric($s) || (int) $s < 0 || (int) $s >= count($order)) {
            return ['ok' => false, 'error' => 'That answer is not one of the choices.'];
        }
        $picked[$order[(int) $s]] = true;
    }
    $picked = array_keys($picked);
    sort($picked);
    if ($row['qtype'] !== 'multiple' && count($picked) > 1) {
        return ['ok' => false, 'error' => 'Choose one answer.'];
    }
    $pdo->prepare('UPDATE training_attempt_answers SET chosen_json = ?, answered_at = ? WHERE id = ?')
        ->execute([$picked === [] ? null : exams_json($picked), exams_sql_dt($now), (int) $row['id']]);
    return ['ok' => true];
}

/**
 * The marks one answer earns.
 * One answer / true-false: all or nothing. Several answers: all or nothing,
 * or with part marks (+1 for each right option picked, −1 for each wrong one,
 * over the number of right options, never below 0).
 *
 * @return array{score:float, correct:bool}
 */
function exam_mark(string $qtype, array $correct, array $chosen, int $marks, bool $partMarks): array
{
    sort($correct);
    sort($chosen);
    $exact = $chosen !== [] && $chosen === $correct;
    if ($exact) {
        return ['score' => (float) $marks, 'correct' => true];
    }
    if ($qtype !== 'multiple' || !$partMarks || $chosen === [] || $correct === []) {
        return ['score' => 0.0, 'correct' => false];
    }
    $right = count(array_intersect($chosen, $correct));
    $wrong = count(array_diff($chosen, $correct));
    $fraction = max(0, ($right - $wrong) / count($correct));
    return ['score' => round($marks * $fraction, 2), 'correct' => false];
}

/**
 * End an attempt and mark it, once. $endedBy: learner | timer | closed | tutor.
 * Safe to call twice (the second does nothing). Returns the ended attempt.
 */
function exam_submit(PDO $pdo, int $attemptId, string $endedBy, ?DateTimeImmutable $now = null): ?array
{
    $now ??= exams_now();
    $attempt = exam_attempt_by_id($pdo, $attemptId);
    if ($attempt === null) {
        return null;
    }
    if ($attempt['status'] !== 'started') {
        return $attempt;
    }
    $exam = exam_get_by_id($pdo, (int) $attempt['exam_id']);
    $rows = exam_attempt_rows($pdo, $attemptId);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $claim = $pdo->prepare("UPDATE training_attempts SET status = 'submitted', submitted_at = ?, ended_by = ? WHERE id = ? AND status = 'started'");
        $claim->execute([exams_sql_dt($now), $endedBy, $attemptId]);
        if ($claim->rowCount() !== 1) {
            if ($own) {
                $pdo->rollBack();
            }
            return exam_attempt_by_id($pdo, $attemptId);
        }
        $score = 0.0;
        $max = 0;
        $upd = $pdo->prepare('UPDATE training_attempt_answers SET score = ?, is_correct = ? WHERE id = ?');
        foreach ($rows as $r) {
            $m = exam_mark((string) $r['qtype'], $r['correct'], $r['chosen'], (int) $r['marks'], !empty($exam['part_marks']));
            $upd->execute([$m['score'], $m['correct'] ? 1 : 0, (int) $r['id']]);
            $score += $m['score'];
            $max += (int) $r['marks'];
        }
        $percent = $max > 0 ? round($score / $max * 100, 2) : 0.0;
        $passed = $percent >= (int) ($exam['pass_percent'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE training_attempts SET score = ?, max_score = ?, percent = ?, passed = ? WHERE id = ?')
            ->execute([round($score, 2), $max, $percent, $passed, $attemptId]);
        exam_event($pdo, $attemptId, $endedBy === 'learner' ? 'submit' : 'timeout', $endedBy, $now);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $done = exam_attempt_by_id($pdo, $attemptId);
    if ($exam !== null && $done !== null) {
        exam_progress_hook($pdo, $exam, $done);
    }
    return $done;
}

/**
 * End every attempt whose time is up (plus the grace). Called whenever an
 * exam is opened, answered or looked at, so nobody has to wait for a cron.
 */
function exam_finalize_due(PDO $pdo, ?DateTimeImmutable $now = null, ?int $examId = null): int
{
    $now ??= exams_now();
    $cut = exams_sql_dt($now->modify('-' . EXAM_GRACE_SECONDS . ' seconds'));
    $sql = "SELECT a.id, a.deadline_at, e.window_close FROM training_attempts a JOIN training_exams e ON e.id = a.exam_id
             WHERE a.status = 'started' AND a.deadline_at < ?";
    $params = [$cut];
    if ($examId !== null) {
        $sql .= ' AND a.exam_id = ?';
        $params[] = $examId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $closed = $r['window_close'] !== null && (string) $r['window_close'] === (string) $r['deadline_at'];
        exam_submit($pdo, (int) $r['id'], $closed ? 'closed' : 'timer', $now);
        $n++;
    }
    return $n;
}

/**
 * The exam tab lost (leave) or regained (return) focus. After leave_alert
 * leaves, the tutor is told once.
 *
 * @return array{leaves:int, told:bool}
 */
function exam_note_focus(PDO $pdo, array $exam, array $attempt, string $kind, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    if ($attempt['status'] !== 'started' || !in_array($kind, ['leave', 'return'], true)) {
        return ['leaves' => (int) $attempt['leaves'], 'told' => false];
    }
    exam_event($pdo, (int) $attempt['id'], $kind, null, $now);
    if ($kind === 'return') {
        return ['leaves' => (int) $attempt['leaves'], 'told' => false];
    }
    $pdo->prepare('UPDATE training_attempts SET leaves = leaves + 1 WHERE id = ?')->execute([(int) $attempt['id']]);
    $leaves = (int) $attempt['leaves'] + 1;
    $told = false;
    if ($leaves >= (int) $exam['leave_alert']) {
        $claim = $pdo->prepare('UPDATE training_attempts SET tutor_told_at = ? WHERE id = ? AND tutor_told_at IS NULL');
        $claim->execute([exams_sql_dt($now), (int) $attempt['id']]);
        if ($claim->rowCount() === 1) {
            exam_tell_tutors($pdo, $exam, $attempt, $leaves);
            exam_event($pdo, (int) $attempt['id'], 'told', $leaves . ' leaves', $now);
            $told = true;
        }
    }
    return ['leaves' => $leaves, 'told' => $told];
}

/** Who hears about an exam: the people who set it up. */
function exam_tutor_ids(array $exam): array
{
    return array_values(array_unique(array_filter([(int) ($exam['created_by'] ?? 0), (int) ($exam['updated_by'] ?? 0)])));
}

/** A bell (and a push, through the notifications worker) for the tutors. */
function exam_tell_tutors(PDO $pdo, array $exam, array $attempt, int $leaves): void
{
    if (!exams_table_exists($pdo, 'system_notifications')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([(int) $attempt['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) ?: 'Someone';
    $ins = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    foreach (exam_tutor_ids($exam) as $uid) {
        $ins->execute([$uid, 'Exam: left the tab', $name . ' has left the exam tab ' . $leaves . ' times.',
                       exam_url_setup((int) $exam['item_id']) . '#tab=results']);
    }
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

/**
 * What a person sees after their exam: the score, and for the questions
 * they got wrong (once exam_review_state() opens the review) the right
 * answer and the stored explanation; until then, when that will be.
 */
function exam_result_for_learner(PDO $pdo, array $exam, array $attempt, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    $review = exam_review_state($pdo, $exam, $now);
    $open = $review['open'];
    $wrong = [];
    $count = 0;
    foreach (exam_attempt_rows($pdo, (int) $attempt['id']) as $r) {
        $count++;
        if ((int) $r['is_correct'] === 1) {
            continue;
        }
        $wrong[] = $open ? [
            'n' => (int) $r['position'],
            'text' => (string) $r['body'],
            'yours' => $r['chosen'] === [] ? '' : implode(' · ', array_map(static fn ($i) => (string) ($r['options'][$i] ?? ''), $r['chosen'])),
            'right' => implode(' · ', array_map(static fn ($i) => (string) ($r['options'][$i] ?? ''), $r['correct'])),
            'explanation' => (string) ($r['explanation'] ?? ''),
            'part' => (float) $r['score'] > 0 ? (float) $r['score'] : null,
            'marks' => (int) $r['marks'],
        ] : ['n' => (int) $r['position']];
    }
    return [
        'score' => $attempt['score'], 'max' => $attempt['max_score'], 'percent' => $attempt['percent'],
        'passed' => (bool) $attempt['passed'], 'pass_percent' => (int) $exam['pass_percent'],
        'questions' => $count, 'wrong_count' => count($wrong), 'review_open' => $open,
        'review_when' => $review['when'], 'review_at' => $open ? null : $review['at'], 'wrong' => $open ? $wrong : [],
    ];
}

/**
 * The tutor's table: every attempt, plus the people enrolled who have not
 * started (when PR 1's enrolments are there).
 */
function exam_results(PDO $pdo, array $exam, ?DateTimeImmutable $now = null): array
{
    exam_finalize_due($pdo, $now, (int) $exam['id']);
    $stmt = $pdo->prepare('SELECT a.*, u.first_name, u.last_name FROM training_attempts a LEFT JOIN users u ON u.id = a.user_id
                            WHERE a.exam_id = ? ORDER BY u.first_name, u.last_name, a.id');
    $stmt->execute([(int) $exam['id']]);
    $rows = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = exam_attempt_decode($r);
        $seen[$a['user_id']] = true;
        $rows[] = $a + ['name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) ?: ('#' . $a['user_id'])];
    }
    if (exams_table_exists($pdo, 'training_enrolments')) {
        $stmt = $pdo->prepare("SELECT e.user_id, u.first_name, u.last_name FROM training_enrolments e LEFT JOIN users u ON u.id = e.user_id
                                WHERE e.training_id = ? AND e.status <> 'withdrawn' ORDER BY u.first_name, u.last_name");
        $stmt->execute([(int) $exam['training_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($seen[(int) $r['user_id']])) {
                $rows[] = ['id' => null, 'user_id' => (int) $r['user_id'], 'status' => 'not_started', 'leaves' => 0, 'score' => null,
                           'max_score' => null, 'percent' => null, 'passed' => null, 'started_at' => null, 'submitted_at' => null, 'ended_by' => null,
                           'name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) ?: ('#' . $r['user_id'])];
            }
        }
    }
    $sum = ['enrolled' => count($rows), 'started' => 0, 'finished' => 0, 'passed' => 0];
    foreach ($rows as $r) {
        $sum['started'] += $r['status'] !== 'not_started' ? 1 : 0;
        $sum['finished'] += $r['status'] === 'submitted' ? 1 : 0;
        $sum['passed'] += !empty($r['passed']) ? 1 : 0;
    }
    return ['rows' => $rows, 'summary' => $sum];
}

/** One attempt in full, for the tutor: every question, the answer, the marks, the tab notes. */
function exam_attempt_detail(PDO $pdo, array $attempt): array
{
    $qs = [];
    foreach (exam_attempt_rows($pdo, (int) $attempt['id']) as $r) {
        $q = ['qtype' => $r['qtype']];
        $say = static fn (array $idx): string => implode(' · ', array_map(static fn ($i) => ($q['qtype'] === 'true_false' ? '' : (EXAM_LETTERS[$i] ?? '?') . '. ')
            . (string) ($r['options'][$i] ?? ''), $idx));
        $qs[] = [
            'n' => (int) $r['position'], 'text' => (string) $r['body'], 'marks' => (int) $r['marks'],
            'yours' => $r['chosen'] === [] ? '' : $say($r['chosen']),
            'right' => $say($r['correct']),
            'options' => $r['options'],
            'score' => $r['score'] === null ? null : (float) $r['score'], 'correct' => (int) $r['is_correct'] === 1,
        ];
    }
    $stmt = $pdo->prepare('SELECT kind, detail, created_at FROM training_attempt_events WHERE attempt_id = ? ORDER BY id');
    $stmt->execute([(int) $attempt['id']]);
    return ['questions' => $qs, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

// ---------------------------------------------------------------------------
// Hooks into PR 1 (Trainings core)
// ---------------------------------------------------------------------------

/** The person's enrolment in a training (PR 1), when there is one. */
function exam_enrolment_id(PDO $pdo, int $trainingId, int $userId): ?int
{
    if (!exams_table_exists($pdo, 'training_enrolments')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM training_enrolments WHERE training_id = ? AND user_id = ? AND status <> 'withdrawn'");
    $stmt->execute([$trainingId, $userId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Tell PR 1 that this person has sat the exam: training_progress (enrolment,
 * item) = 'completed' with data {score, max, percent, passed, attempt_id}
 * (PR 1 counts it towards completion only when passed), through PR 1's own
 * trainings_progress_put(); then trainings_recheck_enrolment(), which may
 * complete the training. Never breaks marking: a failure is logged.
 */
function exam_progress_hook(PDO $pdo, array $exam, array $attempt): void
{
    $enrolment = $attempt['enrolment_id'] ?? exam_enrolment_id($pdo, (int) $exam['training_id'], (int) $attempt['user_id']);
    if ($enrolment === null || !exams_table_exists($pdo, 'training_progress')) {
        return;
    }
    $data = ['score' => $attempt['score'], 'max' => $attempt['max_score'], 'percent' => $attempt['percent'],
             'passed' => (bool) $attempt['passed'], 'attempt_id' => (int) $attempt['id']];
    try {
        $core = dirname(__DIR__) . '/trainings/bootstrap.php';
        if (!function_exists('trainings_progress_put') && is_file($core)) {
            require_once $core;   // PR 1 loads exams the other way round; here it is loaded on first use.
        }
        if (function_exists('trainings_progress_put')) {
            trainings_progress_put($pdo, (int) $enrolment, (int) $exam['item_id'], 'completed', $data);
        } else {
            $at = (string) $attempt['submitted_at'];
            $upd = $pdo->prepare("UPDATE training_progress SET status = 'completed', completed_at = ?, data = ? WHERE enrolment_id = ? AND item_id = ?");
            $upd->execute([$at, exams_json($data), $enrolment, (int) $exam['item_id']]);
            if ($upd->rowCount() === 0) {
                $pdo->prepare("INSERT INTO training_progress (enrolment_id, item_id, status, completed_at, data) VALUES (?, ?, 'completed', ?, ?)")
                    ->execute([$enrolment, (int) $exam['item_id'], $at, exams_json($data)]);
            }
        }
        if (function_exists('trainings_recheck_enrolment')) {
            trainings_recheck_enrolment($pdo, (int) $enrolment);
        }
    } catch (Throwable $e) {
        error_log('[exams] progress for attempt ' . $attempt['id'] . ' not recorded: ' . $e->getMessage());
    }
}

/** Where tutors set up an exam item, and where learners take it (for PR 1's pages to link to). */
function exam_url_setup(int $itemId): string
{
    return '/modules/exams/setup?item=' . $itemId;
}

function exam_url_take(int $itemId): string
{
    return '/modules/exams/take?item=' . $itemId;
}

/** The question bank: one training's, or the default one (the Academy's and the builder's headers link here). */
function exam_url_bank(?int $trainingId = null): string
{
    return '/modules/exams/' . ($trainingId ? '?training=' . $trainingId : '');
}

/** AI help for a training (PR 4b; the builder's header links here). */
function exam_url_ai(int $trainingId): string
{
    return '/modules/exams/ai?training=' . $trainingId;
}
