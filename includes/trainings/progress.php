<?php
// /includes/trainings/progress.php
//
// A learner's way through a training, one training_progress row per item:
//   completed  finished: watched / listened / read (the learner says so),
//              attended (a tutor ticks it; PR 3 adds Google Meet attendance),
//              an assignment marked at or above its pass mark, an exam sat
//              (PR 2's exam_progress_hook() writes data {score, max, percent,
//              passed, attempt_id}; only passed: true counts)
//   submitted  an assignment handed in, waiting for a tutor
//   returned   marked below the pass mark: the learner hands it in again
// The status words are shared with PR 2 (docs/EXAMS.md). updated_at may be
// NULL: PR 2's hook does not write it.
// Completion (pass_rule): every required item completed, except live classes,
// where attending live_percent % of the required ones is enough. A completed
// official class waits for the Academy to CONFIRM it (owner's answer 4):
// Membership Class moves the person to Member through the status history
// (includes/status_ladder.php: ladder_status_of / ladder_note_change),
// Maturity Class marks them ready to serve. Nothing changes without that click.

require_once dirname(__DIR__) . '/status_ladder.php';

const TRAININGS_SELF_TYPES = ['video', 'audio', 'reading'];
const TRAININGS_MEMBER_FROM = ['1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor', 'Non_Member'];
const TRAININGS_LADDER_SOURCE = 'training_membership';

/** item id => ['status', 'completed_at', 'data'] for one enrolment. */
function trainings_progress_map(PDO $pdo, int $enrolmentId): array
{
    $stmt = $pdo->prepare('SELECT item_id, status, completed_at, data FROM training_progress WHERE enrolment_id = ?');
    $stmt->execute([$enrolmentId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['item_id']] = ['status' => (string) $r['status'], 'completed_at' => $r['completed_at'],
            'data' => trainings_decode($r['data'])];
    }
    return $out;
}

/** Write one progress row (insert or update). */
function trainings_progress_put(PDO $pdo, int $enrolmentId, int $itemId, string $status, array $data): void
{
    $now = trainings_sql_dt();
    $done = $status === 'completed' ? $now : null;
    $upd = $pdo->prepare('UPDATE training_progress SET status = ?, completed_at = ?, data = ?, updated_at = ? WHERE enrolment_id = ? AND item_id = ?');
    $upd->execute([$status, $done, trainings_json($data), $now, $enrolmentId, $itemId]);
    if ($upd->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO training_progress (enrolment_id, item_id, status, completed_at, data, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$enrolmentId, $itemId, $status, $done, trainings_json($data), $now]);
        } catch (PDOException $e) {
            // A double tap: the row exists now.
            $upd->execute([$status, $done, trainings_json($data), $now, $enrolmentId, $itemId]);
        }
    }
}

function trainings_progress_clear(PDO $pdo, int $enrolmentId, int $itemId): void
{
    $pdo->prepare('DELETE FROM training_progress WHERE enrolment_id = ? AND item_id = ?')->execute([$enrolmentId, $itemId]);
}

/** Does this progress row count towards completion? (An exam only when it was passed.) */
function trainings_progress_counts(array $item, ?array $p): bool
{
    if ($p === null || $p['status'] !== 'completed') {
        return false;
    }
    return !array_key_exists('passed', $p['data']) || !empty($p['data']['passed']);
}

/**
 * Check one enrolment again after something outside this module wrote its
 * progress (PR 2's exam_progress_hook(), PR 3's attendance). Safe to call any
 * time; returns true when this call completed the training.
 *     if (function_exists('trainings_recheck_enrolment')) trainings_recheck_enrolment($pdo, $enrolmentId);
 */
function trainings_recheck_enrolment(PDO $pdo, int $enrolmentId): bool
{
    if (!trainings_ready($pdo)) {
        return false;
    }
    $e = trainings_enrolment_get($pdo, $enrolmentId);
    $t = $e !== null ? trainings_get($pdo, $e['training_id']) : null;
    return $e !== null && $t !== null && trainings_check_completion($pdo, $t, $e);
}

/**
 * Counts for one enrolment: required items, done, handed in, to mark, percent,
 * and whether the pass rule is met.
 */
function trainings_enrolment_summary(PDO $pdo, array $t, array $enrolment, ?array $items = null, ?array $progress = null): array
{
    $items ??= trainings_items_flat($pdo, (int) $t['id']);
    $progress ??= trainings_progress_map($pdo, (int) $enrolment['id']);
    $required = 0;
    $done = 0;
    $toMark = 0;
    $liveReq = 0;
    $liveDone = 0;
    $otherMissing = 0;
    foreach ($items as $item) {
        $p = $progress[$item['id']] ?? null;
        $isDone = trainings_progress_counts($item, $p);
        if ($p !== null && $p['status'] === 'submitted') {
            $toMark++;
        }
        if (!$item['required']) {
            continue;
        }
        $required++;
        $done += $isDone ? 1 : 0;
        if ($item['type'] === 'live') {
            $liveReq++;
            $liveDone += $isDone ? 1 : 0;
        } elseif (!$isDone) {
            $otherMissing++;
        }
    }
    $livePercent = (int) ($t['pass_rule']['live_percent'] ?? 100);
    $liveOk = $liveReq === 0 || $liveDone * 100 >= $livePercent * $liveReq;
    return ['required' => $required, 'done' => $done, 'to_mark' => $toMark,
        'percent' => $required > 0 ? (int) floor($done * 100 / $required) : 0,
        'live_required' => $liveReq, 'live_done' => $liveDone,
        'complete' => $required > 0 && $otherMissing === 0 && $liveOk];
}

/**
 * Mark the enrolment completed once the pass rule is met (forward only: a
 * completed enrolment is never reopened here). Tells the learner.
 */
function trainings_check_completion(PDO $pdo, array $t, array $enrolment): bool
{
    if ($enrolment['status'] !== 'active') {
        return false;
    }
    if (!trainings_enrolment_summary($pdo, $t, $enrolment)['complete']) {
        return false;
    }
    $now = trainings_sql_dt();
    $upd = $pdo->prepare("UPDATE training_enrolments SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'active'");
    $upd->execute([$now, $now, $enrolment['id']]);
    if ($upd->rowCount() === 1) {
        trainings_notify($pdo, [$enrolment['user_id']], 'Training complete', (string) $t['title'],
            '/modules/trainings/?t=' . (int) $t['id']);
        // Its certificate, if it gives one (an official class waits for the Academy's confirm).
        trainings_certificate_auto($pdo, (int) $enrolment['id']);
        return true;
    }
    return false;
}

/** The learner says they watched / listened to / read it (or takes it back). */
function trainings_self_done(PDO $pdo, array $t, array $enrolment, array $item, bool $done): array
{
    if (!in_array($item['type'], TRAININGS_SELF_TYPES, true)) {
        return ['ok' => false, 'message' => 'A tutor records this one.'];
    }
    if ($enrolment['status'] === 'withdrawn') {
        return ['ok' => false, 'message' => 'Join the training again first.'];
    }
    if ($done) {
        trainings_progress_put($pdo, $enrolment['id'], $item['id'], 'completed', ['by' => 'self']);
        return ['ok' => true, 'completed' => trainings_check_completion($pdo, $t, $enrolment)];
    }
    $p = trainings_progress_map($pdo, $enrolment['id'])[$item['id']] ?? null;
    if ($p !== null && ($p['data']['by'] ?? '') === 'self') {
        trainings_progress_clear($pdo, $enrolment['id'], $item['id']);
    }
    return ['ok' => true, 'completed' => false];
}

/** A tutor ticks an item done for a learner (a class attended in person, …) or clears it. */
function trainings_tutor_done(PDO $pdo, array $t, array $enrolment, array $item, bool $done, int $by): array
{
    if ($item['type'] === 'assignment') {
        return ['ok' => false, 'message' => 'Mark the work instead.'];
    }
    if ($item['type'] === 'exam') {
        return ['ok' => false, 'message' => 'The exam records itself.'];
    }
    if ($done) {
        trainings_progress_put($pdo, $enrolment['id'], $item['id'], 'completed', ['by' => 'tutor', 'tutor_id' => $by]);
        return ['ok' => true, 'completed' => trainings_check_completion($pdo, $t, $enrolment)];
    }
    trainings_progress_clear($pdo, $enrolment['id'], $item['id']);
    return ['ok' => true, 'completed' => false];
}

/**
 * Hand in an assignment: one answer per question (or one answer when it has
 * no questions). Allowed when not handed in yet, or when it came back.
 */
function trainings_submit(PDO $pdo, array $t, array $enrolment, array $item, array $answers): array
{
    if ($item['type'] !== 'assignment') {
        return ['ok' => false, 'message' => 'This item takes no work.'];
    }
    if ($enrolment['status'] === 'withdrawn') {
        return ['ok' => false, 'message' => 'Join the training again first.'];
    }
    $p = trainings_progress_map($pdo, $enrolment['id'])[$item['id']] ?? null;
    if ($p !== null && $p['status'] === 'submitted') {
        return ['ok' => false, 'message' => 'Handed in. Wait for your tutor.'];
    }
    if ($p !== null && $p['status'] === 'completed') {
        return ['ok' => false, 'message' => 'Already marked.'];
    }
    $prompts = (array) ($item['details']['prompts'] ?? []);
    $want = max(1, count($prompts));
    $clean = [];
    for ($n = 0; $n < $want; $n++) {
        $a = trainings_text($answers[$n] ?? '', 5000, true);
        if ($a === '') {
            return ['ok' => false, 'message' => 'Answer every question.', 'field' => 'answer_' . $n];
        }
        $clean[] = $a;
    }
    $history = (array) ($p['data']['history'] ?? []);
    if ($p !== null) {
        $history[] = array_intersect_key($p['data'], array_flip(['answers', 'submitted_at', 'mark', 'comment', 'marked_at']));
    }
    trainings_progress_put($pdo, $enrolment['id'], $item['id'], 'submitted',
        ['answers' => $clean, 'submitted_at' => trainings_sql_dt(), 'history' => array_slice($history, -5)]);
    return ['ok' => true];
}

/** A tutor marks handed-in work: at or above the pass mark it is done; below, it goes back. */
function trainings_mark_work(PDO $pdo, array $t, array $enrolment, array $item, $mark, string $comment, int $by): array
{
    $p = trainings_progress_map($pdo, $enrolment['id'])[$item['id']] ?? null;
    if ($p === null || $p['status'] !== 'submitted') {
        return ['ok' => false, 'message' => 'Nothing is waiting to be marked.'];
    }
    $max = (int) ($item['details']['max_mark'] ?? 10);
    $pass = (int) ($item['details']['pass_mark'] ?? (int) ceil($max / 2));
    if (!is_numeric($mark) || (int) $mark < 0 || (int) $mark > $max || (string) (int) $mark !== trim((string) $mark)) {
        return ['ok' => false, 'message' => 'Give a mark from 0 to ' . $max . '.', 'field' => 'mark'];
    }
    $mark = (int) $mark;
    $data = $p['data'];
    $data['mark'] = $mark;
    $data['comment'] = trainings_text($comment, 2000, true);
    $data['marked_by'] = $by;
    $data['marked_at'] = trainings_sql_dt();
    $passed = $mark >= $pass;
    trainings_progress_put($pdo, $enrolment['id'], $item['id'], $passed ? 'completed' : 'returned', $data);
    trainings_notify($pdo, [$enrolment['user_id']], $passed ? 'Work marked' : 'Work returned', (string) $item['title'],
        '/modules/trainings/?t=' . (int) $t['id']);
    return ['ok' => true, 'passed' => $passed, 'completed' => $passed && trainings_check_completion($pdo, $t, $enrolment)];
}

/** Handed-in work waiting for a tutor (all trainings, or one). */
function trainings_to_mark(PDO $pdo, ?int $trainingId = null, int $limit = 200): array
{
    $sql = "SELECT p.enrolment_id, p.item_id, p.updated_at, p.data, i.title AS item_title, i.details, t.id AS training_id, t.title AS training_title,
                   u.first_name, u.last_name
              FROM training_progress p
              JOIN training_items i ON i.id = p.item_id
              JOIN training_enrolments e ON e.id = p.enrolment_id
              JOIN trainings t ON t.id = e.training_id
              JOIN users u ON u.id = e.user_id
             WHERE p.status = 'submitted' AND e.status <> 'withdrawn'";
    $params = [];
    if ($trainingId !== null) {
        $sql .= ' AND t.id = ?';
        $params[] = $trainingId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY p.updated_at, p.id LIMIT ' . max(1, min(500, $limit)));
    $stmt->execute($params);
    return array_map(static function ($r) {
        $d = trainings_decode($r['details']);
        $data = trainings_decode($r['data']);
        return ['enrolment_id' => (int) $r['enrolment_id'], 'item_id' => (int) $r['item_id'], 'training_id' => (int) $r['training_id'],
            'training' => (string) $r['training_title'], 'item' => (string) $r['item_title'], 'name' => trainings_person_name($r),
            'submitted_at' => (string) ($data['submitted_at'] ?? $r['updated_at']), 'prompts' => (array) ($d['prompts'] ?? []),
            'instructions' => (string) ($d['instructions'] ?? ''), 'answers' => (array) ($data['answers'] ?? []),
            'max_mark' => (int) ($d['max_mark'] ?? 10), 'pass_mark' => (int) ($d['pass_mark'] ?? 5)];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------
// The Academy confirms (official classes)
// ---------------------------------------------------------------------------

/** Completed official classes waiting for the Academy's click. */
function trainings_to_confirm(PDO $pdo, ?int $trainingId = null): array
{
    $sql = "SELECT e.id, e.user_id, e.completed_at, t.id AS training_id, t.title, t.official_key, u.first_name, u.last_name, u.spiritual_status
              FROM training_enrolments e JOIN trainings t ON t.id = e.training_id JOIN users u ON u.id = e.user_id
             WHERE e.status = 'completed' AND e.confirmed_at IS NULL AND t.kind = 'official' AND t.official_key IS NOT NULL";
    $params = [];
    if ($trainingId !== null) {
        $sql .= ' AND t.id = ?';
        $params[] = $trainingId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY e.completed_at, e.id LIMIT 500');
    $stmt->execute($params);
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => trainings_person_name($r),
        'training_id' => (int) $r['training_id'], 'training' => (string) $r['title'], 'official_key' => (string) $r['official_key'],
        'person_status' => trainings_status_label($r['spiritual_status']), 'completed_at' => (string) $r['completed_at'],
        'outcome' => trainings_confirm_outcome((string) $r['official_key'], (string) $r['spiritual_status'])],
        $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** What confirming will do, in words: "Becomes a Member", "Ready to serve", "Stays Worker". */
function trainings_confirm_outcome(string $officialKey, string $status): string
{
    if ($officialKey === 'membership') {
        return in_array($status, TRAININGS_MEMBER_FROM, true) ? 'Becomes a Member' : 'Stays ' . trainings_status_label($status);
    }
    return $officialKey === 'maturity' ? 'Ready to serve' : 'Confirmed';
}

/**
 * Confirm one graduate (owner's answer 4). Membership Class: their status
 * moves to Member, recorded in the status history, only from a status below
 * Member (a Worker or Pastor is never lowered). Maturity Class: recorded as
 * ready to serve (trainings_ready_to_serve()). @return array{ok:bool, message?:string, moved?:bool}
 */
function trainings_confirm(PDO $pdo, array $enrolment, int $by): array
{
    $t = trainings_get($pdo, (int) $enrolment['training_id']);
    if ($t === null || $t['kind'] !== 'official' || $t['official_key'] === null) {
        return ['ok' => false, 'message' => 'Only official classes are confirmed.'];
    }
    if ($enrolment['status'] !== 'completed') {
        return ['ok' => false, 'message' => 'They have not finished yet.'];
    }
    if ($enrolment['confirmed_at'] !== null) {
        return ['ok' => true, 'moved' => false];
    }
    $now = trainings_sql_dt();
    $upd = $pdo->prepare('UPDATE training_enrolments SET confirmed_by = ?, confirmed_at = ?, updated_at = ? WHERE id = ? AND confirmed_at IS NULL');
    $upd->execute([$by, $now, $now, $enrolment['id']]);
    if ($upd->rowCount() !== 1) {
        return ['ok' => true, 'moved' => false];
    }
    $moved = false;
    $uid = (int) $enrolment['user_id'];
    if ($t['official_key'] === 'membership') {
        $before = ladder_status_of($pdo, $uid);
        $stmt = $pdo->prepare('SELECT spiritual_status FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $current = (string) $stmt->fetchColumn();
        if (in_array($current, TRAININGS_MEMBER_FROM, true)) {
            $pdo->prepare("UPDATE users SET spiritual_status = 'Member' WHERE id = ?")->execute([$uid]);
            ladder_note_change($pdo, $uid, $before, TRAININGS_LADDER_SOURCE, $by);
            $moved = true;
        }
        trainings_notify($pdo, [$uid], 'Welcome, member', 'Membership Class confirmed.', '/modules/trainings/?t=' . (int) $t['id']);
    } else {
        trainings_notify($pdo, [$uid], 'Ready to serve', 'Maturity Class confirmed.', '/modules/trainings/?t=' . (int) $t['id']);
    }
    trainings_certificate_auto($pdo, (int) $enrolment['id']);
    return ['ok' => true, 'moved' => $moved];
}

/** Has this person a confirmed Maturity Class (ready for the workforce)? */
function trainings_ready_to_serve(PDO $pdo, int $userId): bool
{
    if (!trainings_ready($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM training_enrolments e JOIN trainings t ON t.id = e.training_id
                            WHERE e.user_id = ? AND t.official_key = 'maturity' AND e.confirmed_at IS NOT NULL LIMIT 1");
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// The learner's view
// ---------------------------------------------------------------------------

/** My trainings: active first (soonest due), then completed. */
function trainings_mine(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT e.*, t.title, t.summary, t.length, t.starts_on, t.ends_on, t.status AS training_status, t.official_key,
                                  t.gives_certificate, t.pass_rule, t.kind, t.catalogue, t.department_id, t.created_by, t.created_at AS t_created
                             FROM training_enrolments e JOIN trainings t ON t.id = e.training_id
                            WHERE e.user_id = ? AND e.status <> 'withdrawn' AND t.status = 'published'
                            ORDER BY CASE WHEN e.status = 'active' THEN 0 ELSE 1 END,
                                     CASE WHEN e.due_on IS NULL THEN 1 ELSE 0 END, e.due_on, t.starts_on, e.id DESC");
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = trainings_shape(['id' => $r['training_id'], 'title' => $r['title'], 'summary' => $r['summary'], 'length' => $r['length'],
            'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'status' => $r['training_status'], 'official_key' => $r['official_key'],
            'gives_certificate' => $r['gives_certificate'], 'pass_rule' => $r['pass_rule'], 'kind' => $r['kind'], 'catalogue' => $r['catalogue'],
            'department_id' => $r['department_id']]);
        $e = trainings_enrolment_shape($r);
        // Progress written elsewhere (an exam passed, Meet attendance) completes it on the next look.
        if ($e['status'] === 'active' && trainings_check_completion($pdo, $t, $e)) {
            $e = trainings_enrolment_get($pdo, $e['id']) ?? $e;
        }
        $sum = trainings_enrolment_summary($pdo, $t, $e);
        $out[] = trainings_public_training($t) + ['enrolment_id' => $e['id'], 'status' => $e['status'], 'due_on' => $e['due_on'],
            'source' => $e['source'], 'completed_at' => $e['completed_at'], 'confirmed_at' => $e['confirmed_at'],
            'done' => $sum['done'], 'required' => $sum['required'], 'percent' => $sum['percent'],
            'next' => trainings_next_item($pdo, $t, $e)];
    }
    return $out;
}

/** The next thing to do: the first required item not done (title and when). */
function trainings_next_item(PDO $pdo, array $t, array $enrolment): ?array
{
    $progress = trainings_progress_map($pdo, (int) $enrolment['id']);
    foreach (trainings_items_flat($pdo, (int) $t['id']) as $item) {
        $p = $progress[$item['id']] ?? null;
        if (!trainings_progress_counts($item, $p)) {
            return ['id' => $item['id'], 'title' => $item['title'], 'type' => $item['type'], 'starts_at' => $item['starts_at'],
                'status' => $p['status'] ?? ''];
        }
    }
    return null;
}

/**
 * One training as a learner sees it: cards, items with what they need to play
 * or hand in, and their own progress. Tutors' comments are shown; nobody
 * else's work is.
 */
function trainings_learner_view(PDO $pdo, array $t, ?array $enrolment): array
{
    if ($enrolment !== null && $enrolment['status'] === 'active' && trainings_check_completion($pdo, $t, $enrolment)) {
        $enrolment = trainings_enrolment_get($pdo, (int) $enrolment['id']) ?? $enrolment;
    }
    $progress = $enrolment !== null ? trainings_progress_map($pdo, (int) $enrolment['id']) : [];
    $sections = [];
    foreach (trainings_outline($pdo, (int) $t['id']) as $s) {
        $items = [];
        foreach ($s['items'] as $item) {
            $p = $progress[$item['id']] ?? null;
            $items[] = trainings_learner_item($item) + [
                'progress' => $p === null ? null : [
                    'status' => $p['status'], 'completed_at' => $p['completed_at'],
                    'by' => (string) ($p['data']['by'] ?? ''),
                    'answers' => (array) ($p['data']['answers'] ?? []),
                    'mark' => $p['data']['mark'] ?? null, 'comment' => (string) ($p['data']['comment'] ?? ''),
                    // An exam's result (PR 2): what the learner may see of their own attempt.
                    'percent' => $p['data']['percent'] ?? null,
                    'passed' => array_key_exists('passed', $p['data']) ? (bool) $p['data']['passed'] : null,
                ],
            ];
        }
        $sections[] = ['id' => (int) $s['id'], 'kind' => (string) $s['kind'], 'title' => (string) $s['title'],
            'date_from' => $s['date_from'], 'date_to' => $s['date_to'], 'items' => $items];
    }
    $out = ['training' => trainings_public_training($t), 'sections' => $sections, 'enrolment' => null];
    if ($enrolment !== null) {
        $sum = trainings_enrolment_summary($pdo, $t, $enrolment);
        $out['enrolment'] = ['id' => $enrolment['id'], 'status' => $enrolment['status'], 'due_on' => $enrolment['due_on'],
            'source' => $enrolment['source'], 'completed_at' => $enrolment['completed_at'], 'confirmed_at' => $enrolment['confirmed_at'],
            'done' => $sum['done'], 'required' => $sum['required'], 'percent' => $sum['percent'],
            'certificate' => (string) ($enrolment['certificate_no'] ?? '')];
    }
    return $out;
}

/** An item's public part: never a tutor-only field. */
function trainings_learner_item(array $item): array
{
    $d = $item['details'];
    $media = static fn (array $m): ?array => !empty($m['embed']) ? ['provider' => (string) ($m['provider'] ?? ''),
        'kind' => (string) ($m['kind'] ?? ''), 'embed' => (string) $m['embed'], 'url' => (string) ($m['url'] ?? '')]
        : (!empty($m['file']) ? ['provider' => 'file', 'file' => (string) $m['file'], 'name' => (string) ($m['name'] ?? '')] : null);
    $public = match ((string) $item['type']) {
        // PR 3's Meet link (meet_url) wins over a link the Academy pasted (link).
        'live' => ['mode' => (string) ($d['mode'] ?? 'in_person'), 'venue' => (string) ($d['venue'] ?? ''),
                   'link' => (string) (($d['meet_url'] ?? '') !== '' ? $d['meet_url'] : ($d['link'] ?? ''))],
        // PR 2 owns the exam; the learner goes to its page (null until PR 2 is here).
        'exam' => ['take_url' => trainings_exam_take_url((int) $item['id'])],
        'video', 'audio' => ['media' => $media($d)],
        // A deck's slide text (slides_text) is never part of what a learner gets.
        'reading' => ['text' => (string) ($d['text'] ?? ''), 'pdf' => (string) ($d['pdf'] ?? ''), 'pdf_name' => (string) ($d['pdf_name'] ?? ''),
                      'deck' => (string) ($d['deck'] ?? ''), 'deck_name' => (string) ($d['deck_name'] ?? ''), 'slides' => (int) ($d['slides'] ?? 0)],
        'assignment' => ['kind' => (string) ($d['kind'] ?? 'written'), 'instructions' => (string) ($d['instructions'] ?? ''),
                         'prompts' => array_values((array) ($d['prompts'] ?? [])), 'max_mark' => (int) ($d['max_mark'] ?? 10),
                         'pass_mark' => (int) ($d['pass_mark'] ?? 5), 'media' => $media((array) ($d['media'] ?? []))],
        default => [],
    };
    return ['id' => $item['id'], 'type' => $item['type'], 'type_label' => TRAININGS_ITEM_TYPES[$item['type']] ?? '',
        'title' => $item['title'], 'starts_at' => $item['starts_at'], 'minutes' => $item['minutes'], 'required' => $item['required'],
        'self' => in_array($item['type'], TRAININGS_SELF_TYPES, true), 'ready' => !$item['needs_content'], 'details' => $public];
}
