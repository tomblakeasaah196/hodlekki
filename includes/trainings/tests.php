<?php
// /includes/trainings/tests.php
//
// Standalone tests (owner, 21 Nov 2026): the School of the Word's monthly test, until now on Google Forms. A test
// has no course behind it. Underneath it is a training of kind 'test' with one card and one required exam item, so
// the exam engine (includes/exams/, docs/EXAMS.md) does for it all it does for a course exam: the question bank and
// the Excel import, the window, the timer, shuffling, marking on hand-in, retakes, results, and the certificate on a
// pass (trainings_check_completion() → trainings_certificate_auto()). What is different:
//
//   - every member may take it while it is published: opening it enrols them (source 'self',
//     trainings_test_enrol(), called by exams_learner_enrolment());
//   - it never shows in the catalogue, My trainings or the Academy's training list; it has its own lists:
//     trainings_tests_for_learner() (Trainings › Tests) and trainings_tests_overview() (Academy › Tests);
//   - the right answers show straight after the attempt that ends their tries (they passed, or it was the last one):
//     exam_review_state() 'test'; until then the result lists the questions they missed, without the answers;
//   - up to EXAM_TEST_ATTEMPTS_MAX (5) tries (owner: "up till a number of times we set");
//   - the link and QR code people scan: trainings_test_url() → /test?t=<item>.
// Guide: docs/EXAMS.md §8. Tests: tests/trainings/run.php, section "Standalone tests".

const TRAININGS_TEST_KIND = 'test';

function trainings_is_test(array $t): bool
{
    return ($t['kind'] ?? '') === TRAININGS_TEST_KIND;
}

/** The link people open or scan: the QR entry page (test.php). */
function trainings_test_url(int $itemId): string
{
    $path = '/test?t=' . $itemId;
    return function_exists('site_url') ? site_url($path) : $path;
}

/** The QR code of a test's link, as an inline SVG: dark on white with a 4-module quiet zone, so phones read it from paper or a screen. */
function trainings_test_qr_svg(string $url): string
{
    $m = hod_qr_matrix($url);
    $n = count($m);
    $q = 4;
    $d = '';
    foreach ($m as $y => $row) {
        $x = 0;
        while ($x < $n) {
            if (!$row[$x]) {
                $x++;
                continue;
            }
            $s = $x;
            while ($x < $n && $row[$x]) {
                $x++;
            }
            $d .= 'M' . ($s + $q) . ' ' . ($y + $q) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z';
        }
    }
    $w = $n + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" role="img" aria-label="QR code for the test link">'
        . '<rect width="' . $w . '" height="' . $w . '" fill="#fff"/><path d="' . $d . '" fill="#0B1B3F"/></svg>';
}

/** The same QR code as a PNG data URI (Download QR: for a slide, a flyer or WhatsApp). */
function trainings_test_qr_png_uri(string $url): string
{
    return 'data:image/png;base64,' . base64_encode(hod_qr_png(hod_qr_matrix($url), 12, 4));
}

/** The test's one exam item (its id), or null. */
function trainings_test_item_id(PDO $pdo, int $trainingId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM training_items WHERE training_id = ? AND type = 'exam' ORDER BY position, id LIMIT 1");
    $stmt->execute([$trainingId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * A new test: the training (kind 'test', a draft, self-paced, out of the catalogue), its one card and its exam item.
 * The questions, the window, the time, the pass mark and the tries are set on the exam set-up page next.
 * @return array{ok:bool, id?:int, item_id?:int, setup_url?:string, message?:string, field?:string}
 */
function trainings_test_create(PDO $pdo, array $in, int $by): array
{
    $title = trainings_text($in['title'] ?? '', 160);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the test a name.', 'field' => 'title'];
    }
    $now = trainings_sql_dt();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT INTO trainings (title, summary, kind, official_key, length, starts_on, ends_on, status, catalogue,
                                              gives_certificate, pass_rule, department_id, created_by, created_at)
                       VALUES (?, NULL, ?, NULL, ?, NULL, NULL, ?, 0, ?, ?, ?, ?, ?)')
            ->execute([$title, TRAININGS_TEST_KIND, 'self_paced', 'draft', !empty($in['gives_certificate']) ? 1 : 0,
                       trainings_json(TRAININGS_DEFAULT_PASS), trainings_default_department($pdo, $by), $by, $now]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO training_sections (training_id, kind, title, date_from, date_to, position) VALUES (?, ?, ?, NULL, NULL, 1)')
            ->execute([$id, 'module', 'Test']);
        $sectionId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO training_items (training_id, section_id, position, type, title, minutes, required, details, created_at)
                       VALUES (?, ?, 1, ?, ?, NULL, 1, ?, ?)')
            ->execute([$id, $sectionId, 'exam', $title, trainings_json([]), $now]);
        $itemId = (int) $pdo->lastInsertId();
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id, 'item_id' => $itemId,
            'setup_url' => function_exists('exam_url_setup') ? exam_url_setup($itemId) : '/modules/exams/setup?item=' . $itemId];
}

/** The name and the certificate switch (the exam's own rules are saved by exam_save()). */
function trainings_test_save(PDO $pdo, array $t, array $in): array
{
    if (!trainings_is_test($t)) {
        return ['ok' => false, 'message' => 'That is not a test.', 'code' => 'NOT_FOUND'];
    }
    $title = trainings_text($in['title'] ?? '', 160);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the test a name.', 'field' => 'title'];
    }
    $now = trainings_sql_dt();
    $pdo->prepare('UPDATE trainings SET title = ?, gives_certificate = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, !empty($in['gives_certificate']) ? 1 : 0, $now, $t['id']]);
    $pdo->prepare("UPDATE training_items SET title = ?, updated_at = ? WHERE training_id = ? AND type = 'exam'")
        ->execute([$title, $now, $t['id']]);
    return ['ok' => true, 'message' => 'Saved.'];
}

/**
 * Open it to every member (published) or take it back (draft). Publishing needs the exam saved with questions.
 * Unpublishing never touches attempts already made.
 */
function trainings_test_publish(PDO $pdo, array $t, bool $on): array
{
    if (!trainings_is_test($t)) {
        return ['ok' => false, 'message' => 'That is not a test.', 'code' => 'NOT_FOUND'];
    }
    if ($on) {
        $itemId = trainings_test_item_id($pdo, (int) $t['id']);
        $exam = $itemId !== null && function_exists('exam_get') ? exam_get($pdo, $itemId) : null;
        if ($exam === null || $exam['question_ids'] === []) {
            return ['ok' => false, 'message' => 'Add the questions and save first.', 'code' => 'NOT_READY'];
        }
    }
    $pdo->prepare('UPDATE trainings SET status = ?, updated_at = ? WHERE id = ?')
        ->execute([$on ? 'published' : 'draft', trainings_sql_dt(), $t['id']]);
    return ['ok' => true, 'message' => $on ? 'Published. Every member can take it now.' : 'Unpublished.'];
}

/**
 * Delete a test nobody has started (a mistake, or a try-out): its exam, its own questions and the training.
 * Once anyone has started, it stays (unpublish it instead): their attempts are their record.
 */
function trainings_test_delete(PDO $pdo, array $t): array
{
    if (!trainings_is_test($t)) {
        return ['ok' => false, 'message' => 'That is not a test.', 'code' => 'NOT_FOUND'];
    }
    $itemId = trainings_test_item_id($pdo, (int) $t['id']);
    if ($itemId !== null && function_exists('exams_ready') && exams_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_attempts WHERE item_id = ?');
        $stmt->execute([$itemId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'People have taken it. Unpublish it instead.', 'code' => 'STARTED'];
        }
        $pdo->prepare('DELETE FROM training_exams WHERE item_id = ?')->execute([$itemId]);
        $pdo->prepare('DELETE FROM training_questions WHERE training_id = ?')->execute([(int) $t['id']]);
    }
    trainings_delete($pdo, (int) $t['id']);
    return ['ok' => true, 'message' => 'Test deleted.'];
}

/**
 * A member opens a published test: their enrolment (made now if needed, source 'self'), or null when it is not a
 * published test. A withdrawn enrolment comes back (trainings_enrol()).
 */
function trainings_test_enrol(PDO $pdo, int $trainingId, int $userId): ?int
{
    $t = trainings_get($pdo, $trainingId);
    if ($t === null || !trainings_is_test($t) || $t['status'] !== 'published' || $userId <= 0) {
        return null;
    }
    return (int) trainings_enrol($pdo, $trainingId, $userId, 'self')['id'];
}

/** Published (or draft, for staff) tests with their exam, newest first. @return list<array> */
function trainings_tests_rows(PDO $pdo, bool $publishedOnly): array
{
    $sql = "SELECT t.id, t.title, t.status, t.gives_certificate, t.created_at, i.id AS item_id
              FROM trainings t JOIN training_items i ON i.training_id = t.id AND i.type = 'exam'
             WHERE t.kind = ?" . ($publishedOnly ? " AND t.status = 'published'" : " AND t.status <> 'archived'") . '
             ORDER BY t.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([TRAININGS_TEST_KIND]);
    $out = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($seen[(int) $r['id']])) {
            continue;   // one item per test; the first wins
        }
        $seen[(int) $r['id']] = true;
        $r['id'] = (int) $r['id'];
        $r['item_id'] = (int) $r['item_id'];
        $r['gives_certificate'] = (int) $r['gives_certificate'] === 1;
        $out[] = $r;
    }
    return $out;
}

/**
 * Trainings › Tests for one member: the tests open now or soon, and the ones they have sat (closed ones they never
 * started are left out). Each with where they stand, what they can do, and their certificate.
 * state: open | soon | closed. action: start | continue | retake | result | none.
 */
function trainings_tests_for_learner(PDO $pdo, int $userId): array
{
    if (!function_exists('exams_ready') || !exams_ready($pdo)) {
        return [];
    }
    $now = exams_now();
    $out = [];
    foreach (trainings_tests_rows($pdo, true) as $r) {
        $exam = exam_get($pdo, $r['item_id']);
        if ($exam === null || $exam['question_ids'] === []) {
            continue;
        }
        exam_finalize_due($pdo, $now, (int) $exam['id']);
        $window = exam_window($exam, $now);
        $attempts = exam_attempts_of($pdo, (int) $exam['id'], $userId);
        if ($window === 'closed' && $attempts === []) {
            continue;
        }
        $st = exam_attempts_state($exam, $attempts, $now);
        $best = $st['best'];
        $action = 'none';
        if ($st['in_progress']) {
            $action = 'continue';
        } elseif ($attempts === []) {
            $action = $window === 'open' ? 'start' : 'none';
        } elseif ($st['can_retake']) {
            $action = 'retake';
        } else {
            $action = 'result';
        }
        $cert = null;
        $e = trainings_enrolment_for($pdo, $r['id'], $userId);
        if ($e !== null && function_exists('trainings_certs_ready') && trainings_certs_ready($pdo)
            && trainings_certificate_current($pdo, (int) $e['id']) !== null) {
            $cert = '/api/trainings_certificate.php?e=' . (int) $e['id'];
        }
        $out[] = [
            'id' => $r['id'], 'item_id' => $r['item_id'], 'title' => (string) $r['title'],
            'state' => $window === 'not_open' ? 'soon' : $window, 'opens' => $exam['window_open'], 'closes' => $exam['window_close'],
            'minutes' => (int) $exam['minutes'], 'questions' => count($exam['question_ids']), 'pass_percent' => (int) $exam['pass_percent'],
            'attempts_allowed' => $st['allowed'], 'attempts_used' => $st['used'], 'passed' => $st['passed'],
            'best_percent' => $best !== null ? (int) round((float) $best['percent']) : null,
            'action' => $action, 'url' => exam_url_take($r['item_id']), 'certificate' => $cert, 'gives_certificate' => $r['gives_certificate'],
        ];
    }
    $rank = ['open' => 0, 'soon' => 1, 'closed' => 2];
    usort($out, static fn ($a, $b) => [$a['action'] === 'result' || $a['action'] === 'none' ? 1 : 0, $rank[$a['state']] ?? 3, -$a['id']]
        <=> [$b['action'] === 'result' || $b['action'] === 'none' ? 1 : 0, $rank[$b['state']] ?? 3, -$b['id']]);
    return $out;
}

/** How many tests this member can act on now (the Tests tab's count). */
function trainings_tests_waiting(array $tests): int
{
    return count(array_filter($tests, static fn ($t) => in_array($t['action'], ['start', 'continue', 'retake'], true)));
}

/** Academy › Tests: every test not archived, with its window and how many have sat it and passed. */
function trainings_tests_overview(PDO $pdo): array
{
    if (!function_exists('exams_ready') || !exams_ready($pdo)) {
        return [];
    }
    $now = exams_now();
    $out = [];
    foreach (trainings_tests_rows($pdo, false) as $r) {
        $exam = exam_get($pdo, $r['item_id']);
        $took = $passed = 0;
        if ($exam !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM training_attempts WHERE exam_id = ? AND status = 'submitted'");
            $stmt->execute([(int) $exam['id']]);
            $took = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM training_attempts WHERE exam_id = ? AND status = 'submitted' AND passed = 1");
            $stmt->execute([(int) $exam['id']]);
            $passed = (int) $stmt->fetchColumn();
        }
        $out[] = [
            'id' => $r['id'], 'item_id' => $r['item_id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'],
            'status_label' => TRAININGS_STATUSES[$r['status']] ?? (string) $r['status'],
            'window' => $exam !== null ? exam_window($exam, $now) : 'not_set',
            'opens' => $exam['window_open'] ?? null, 'closes' => $exam['window_close'] ?? null,
            'questions' => $exam !== null ? count($exam['question_ids']) : 0,
            'took' => $took, 'passed' => $passed, 'gives_certificate' => $r['gives_certificate'],
            'setup_url' => exam_url_setup($r['item_id']),
        ];
    }
    return $out;
}
