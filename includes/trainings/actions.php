<?php
// /includes/trainings/actions.php
//
// Every action of api/trainings_api.php, and the one place each is checked
// against the permission matrix (can('trainings', …)). Kept out of the
// endpoint so tests/trainings/run.php runs the same decisions on SQLite.
//
//   Learner (own records):  mine, catalogue, view, join, leave, item_done, submit_work
//   The Academy (church):   overview, create, builder, settings, publish, unpublish,
//                           archive, delete, section_*, item_*, learners,
//                           people_search, audience_options, assign, remove_learner,
//                           set_due, invite, invite_prefill, learner_progress,
//                           tutor_done, mark_work, to_mark, to_confirm, confirm

/** Staff side: the Academy's ability at church-wide reach (seats in an owner department have it). */
function trainings_staff_can(string $level): bool
{
    return can('trainings', $level, 'church');
}

/** Learner side: their own records. */
function trainings_self_can(int $userId, string $level = 'create'): bool
{
    return can('trainings', $level, perm_person($userId));
}

function trainings_forbidden(): array
{
    return trainings_fail('You do not have access to this. Ask the HOD Academy if you think you should.', 'FORBIDDEN');
}

/**
 * Run one action. $in: the request fields; $files: $_FILES; $uid: the signed-in person.
 * @return array the JSON reply
 */
function trainings_action(PDO $pdo, string $action, array $in, array $files, int $uid): array
{
    if (!trainings_ready($pdo)) {
        return trainings_fail('Trainings is being set up. Try again later.', 'FEATURE_NOT_READY');
    }
    $tid = (int) ($in['training_id'] ?? 0);
    $t = null;
    $needTraining = static function () use ($pdo, $tid, &$t): ?array {
        $t = trainings_get($pdo, $tid);
        return $t === null ? trainings_fail('That training was removed. Reload the page.', 'NOT_FOUND') : null;
    };
    $reply = static fn (array $r, array $extra = []): array => !empty($r['ok'])
        ? trainings_ok(array_diff_key($r, ['ok' => 1, 'token' => 1]) + $extra)
        : trainings_fail((string) ($r['message'] ?? 'That did not work. Try again.'), (string) ($r['code'] ?? 'INVALID'), $r['field'] ?? null);

    switch ($action) {
        // ------------------------------------------------------------ learner
        case 'mine':
            if (!trainings_self_can($uid, 'view')) {
                return trainings_forbidden();
            }
            return trainings_ok(['mine' => trainings_mine($pdo, $uid), 'catalogue' => trainings_catalogue($pdo, $uid)]);

        case 'view':
            if ($err = $needTraining()) {
                return $err;
            }
            $e = trainings_enrolment_for($pdo, $tid, $uid);
            $staff = trainings_staff_can('view');
            $open = $t['status'] === 'published' && ($e !== null && $e['status'] !== 'withdrawn' || $t['catalogue']);
            if (!$staff && !($open && trainings_self_can($uid, 'view'))) {
                return trainings_fail('This training is not open to you.', 'FORBIDDEN');
            }
            return trainings_ok(trainings_learner_view($pdo, $t, $e !== null && $e['status'] !== 'withdrawn' ? $e : null)
                + ['can_join' => $t['status'] === 'published' && $t['catalogue'] && ($e === null || $e['status'] === 'withdrawn')
                    && trainings_self_can($uid)]);

        case 'join':
            if ($err = $needTraining()) {
                return $err;
            }
            if (!trainings_self_can($uid)) {
                return trainings_forbidden();
            }
            if ($t['status'] !== 'published' || !$t['catalogue']) {
                return trainings_fail('This training is not open to join.');
            }
            if ($t['ends_on'] !== null && $t['ends_on'] < trainings_today()) {
                return trainings_fail('This training has ended.');
            }
            $r = trainings_enrol($pdo, $tid, $uid, 'self', null, null);
            return trainings_ok(['enrolment_id' => $r['id']]);

        case 'leave':
            $e = trainings_enrolment_get($pdo, (int) ($in['enrolment_id'] ?? 0));
            if ($e === null || $e['user_id'] !== $uid || !trainings_self_can($uid)) {
                return trainings_forbidden();
            }
            if ($e['source'] !== 'self') {
                return trainings_fail('Ask the HOD Academy to take you off this one.');
            }
            if ($e['status'] !== 'active') {
                return trainings_fail('Finished trainings stay on your record.');
            }
            trainings_withdraw($pdo, $e);
            return trainings_ok();

        case 'item_done':
        case 'submit_work':
            $item = trainings_item_get($pdo, (int) ($in['item_id'] ?? 0));
            if ($item === null) {
                return trainings_fail('That item was removed. Reload the page.', 'NOT_FOUND');
            }
            $t = trainings_get($pdo, $item['training_id']);
            $e = trainings_enrolment_for($pdo, $item['training_id'], $uid);
            if ($t === null || $e === null || $t['status'] !== 'published' || !trainings_self_can($uid)) {
                return trainings_forbidden();
            }
            if ($action === 'item_done') {
                return $reply(trainings_self_done($pdo, $t, $e, $item, !empty($in['done'])));
            }
            return $reply(trainings_submit($pdo, $t, $e, $item, array_values((array) ($in['answers'] ?? []))));
    }

    // ------------------------------------------------------------- the Academy
    switch ($action) {
        case 'overview':
            if (!trainings_staff_can('view')) {
                return trainings_forbidden();
            }
            trainings_sync_newcomers($pdo);
            return trainings_ok(['trainings' => trainings_overview($pdo),
                'to_mark' => trainings_staff_can('edit') ? count(trainings_to_mark($pdo)) : 0,
                'to_confirm' => trainings_staff_can('approve') ? trainings_to_confirm($pdo) : []]);

        case 'create':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            return $reply(trainings_create($pdo, $in, $uid));

        case 'people_search':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            return trainings_ok(['people' => trainings_people_search($pdo, (string) ($in['q'] ?? ''))]);

        case 'audience_options':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            return trainings_ok(trainings_audience_options($pdo));

        case 'invite_prefill':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            return trainings_ok(['email' => trainings_invite_prefill($pdo, (int) ($in['user_id'] ?? 0))]);

        case 'to_mark':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            return trainings_ok(['work' => trainings_to_mark($pdo, $tid > 0 ? $tid : null)]);

        case 'to_confirm':
            if (!trainings_staff_can('approve')) {
                return trainings_forbidden();
            }
            return trainings_ok(['people' => trainings_to_confirm($pdo, $tid > 0 ? $tid : null)]);

        case 'certificate_issue':
        case 'certificate_revoke':
            // Certificates (includes/trainings/certificates.php): issuing again replaces the current one.
            if (!trainings_staff_can('approve')) {
                return trainings_forbidden();
            }
            $e = trainings_enrolment_get($pdo, (int) ($in['enrolment_id'] ?? 0));
            if ($e === null) {
                return trainings_fail('They are no longer on this training. Reload the page.', 'NOT_FOUND');
            }
            return $reply($action === 'certificate_issue' ? trainings_certificate_issue($pdo, $e, $uid)
                : trainings_certificate_revoke($pdo, $e, $uid, (string) ($in['reason'] ?? '')));

        case 'settings_get':
        case 'settings_save':
            // The Academy's settings: who signs certificates (never hard-coded).
            if (!trainings_staff_can('manage')) {
                return trainings_forbidden();
            }
            if (!trainings_certs_ready($pdo)) {
                return trainings_fail('Certificates are being set up. Try again later.', 'FEATURE_NOT_READY');
            }
            return $action === 'settings_get' ? trainings_ok(['settings' => trainings_settings($pdo)])
                : $reply(trainings_settings_save($pdo, $in, $files, $uid));

        case 'confirm':
            if (!trainings_staff_can('approve')) {
                return trainings_forbidden();
            }
            $done = 0;
            $moved = 0;
            foreach (array_slice(array_map('intval', (array) ($in['enrolment_ids'] ?? [])), 0, 500) as $eid) {
                $e = trainings_enrolment_get($pdo, $eid);
                if ($e === null) {
                    continue;
                }
                $r = trainings_confirm($pdo, $e, $uid);
                if (!empty($r['ok'])) {
                    $done++;
                    $moved += !empty($r['moved']) ? 1 : 0;
                }
            }
            return trainings_ok(['confirmed' => $done, 'moved' => $moved]);

        case 'mark_work':
        case 'tutor_done':
        case 'learner_progress':
        case 'remove_learner':
        case 'set_due':
            $e = trainings_enrolment_get($pdo, (int) ($in['enrolment_id'] ?? 0));
            if ($e === null) {
                return trainings_fail('They are no longer on this training. Reload the page.', 'NOT_FOUND');
            }
            $t = trainings_get($pdo, $e['training_id']);
            if ($t === null || !trainings_staff_can($action === 'learner_progress' ? 'view' : 'edit')) {
                return trainings_forbidden();
            }
            if ($action === 'learner_progress') {
                return trainings_ok(trainings_learner_view($pdo, $t, $e) + ['person' => trainings_person_brief($pdo, $e['user_id'])]);
            }
            if ($action === 'remove_learner') {
                trainings_withdraw($pdo, $e);
                return trainings_ok();
            }
            if ($action === 'set_due') {
                $due = trim((string) ($in['due_on'] ?? '')) === '' ? null : trainings_date($in['due_on']);
                if (trim((string) ($in['due_on'] ?? '')) !== '' && $due === null) {
                    return trainings_fail('Choose a date.', 'INVALID', 'due_on');
                }
                $pdo->prepare('UPDATE training_enrolments SET due_on = ?, updated_at = ? WHERE id = ?')->execute([$due, trainings_sql_dt(), $e['id']]);
                return trainings_ok();
            }
            $item = trainings_item_get($pdo, (int) ($in['item_id'] ?? 0));
            if ($item === null || $item['training_id'] !== $t['id']) {
                return trainings_fail('That item was removed. Reload the page.', 'NOT_FOUND');
            }
            if ($action === 'tutor_done') {
                return $reply(trainings_tutor_done($pdo, $t, $e, $item, !empty($in['done']), $uid));
            }
            return $reply(trainings_mark_work($pdo, $t, $e, $item, $in['mark'] ?? '', (string) ($in['comment'] ?? ''), $uid));
    }

    // Everything below works on one training.
    if ($err = $needTraining()) {
        return $err;
    }
    switch ($action) {
        case 'builder':
            if (!trainings_staff_can('view')) {
                return trainings_forbidden();
            }
            $outline = array_map(static function (array $s): array {
                $s['items'] = array_map(static fn (array $i): array => ['details' => trainings_details_for_browser($i['details'])] + $i, $s['items']);
                return $s;
            }, trainings_outline($pdo, $tid));
            return trainings_ok(['training' => trainings_staff_training($pdo, $t), 'sections' => $outline,
                'can_edit' => trainings_staff_can('edit'), 'can_delete' => trainings_staff_can('delete'),
                'can_assign' => trainings_staff_can('create'), 'can_approve' => trainings_staff_can('approve')]);

        case 'learners':
            if (!trainings_staff_can('view')) {
                return trainings_forbidden();
            }
            trainings_sync_newcomers($pdo, $tid);
            return trainings_ok(['learners' => trainings_learners($pdo, $t), 'assignments' => trainings_assignment_history($pdo, $tid)]);

        case 'assign':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            if ($t['status'] === 'archived') {
                return trainings_fail('Bring the training back first.');
            }
            $due = trim((string) ($in['due_on'] ?? '')) === '' ? null : trainings_date($in['due_on']);
            if (trim((string) ($in['due_on'] ?? '')) !== '' && $due === null) {
                return trainings_fail('Choose a date.', 'INVALID', 'due_on');
            }
            $aud = ['workers' => !empty($in['workers']), 'statuses' => (array) ($in['statuses'] ?? []),
                'departments' => (array) ($in['departments'] ?? []), 'users' => (array) ($in['users'] ?? [])];
            return $reply(trainings_assign($pdo, $t, $aud, $due, !empty($in['keep']), $uid));

        case 'invite':
            if (!trainings_staff_can('create')) {
                return trainings_forbidden();
            }
            $person = (int) ($in['user_id'] ?? 0);
            if ($person <= 0) {
                return trainings_fail('Choose the person.', 'INVALID', 'user_id');
            }
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$person]);
            $hash = $stmt->fetchColumn();
            if ($hash === false) {
                return trainings_fail('That person was not found.', 'NOT_FOUND', 'user_id');
            }
            if ((string) $hash !== '') {
                return trainings_fail('They can sign in already. Assign it instead.', 'HAS_SIGNIN', 'user_id');
            }
            if (!function_exists('campaigns_ready') || !campaigns_ready($pdo)) {
                return trainings_fail('Email is being set up. Try again later.', 'FEATURE_NOT_READY');
            }
            $enrol = trainings_enrol($pdo, $tid, $person, 'invite', $uid, trainings_date($in['due_on'] ?? '') ?: null);
            $e = trainings_enrolment_get($pdo, $enrol['id']);
            return $reply(trainings_invite_send($pdo, $t, $e, (string) ($in['email'] ?? ''), $uid));

        case 'settings':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            return $reply(trainings_update_settings($pdo, $t, $in));

        case 'publish':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            $r = trainings_publish($pdo, $t);
            if (!empty($r['ok'])) {
                // People assigned while it was a draft hear about it now.
                $stmt = $pdo->prepare("SELECT user_id FROM training_enrolments WHERE training_id = ? AND status = 'active'");
                $stmt->execute([$tid]);
                if ($t['status'] === 'draft') {
                    trainings_notify($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), 'New training', (string) $t['title'],
                        '/modules/trainings/?t=' . $tid);
                }
            }
            return $reply($r);

        case 'unpublish':
        case 'archive':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            trainings_set_status($pdo, $t, $action === 'archive' ? 'archived' : 'draft');
            return trainings_ok();

        case 'delete':
            if (!trainings_staff_can('delete')) {
                return trainings_forbidden();
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM training_enrolments WHERE training_id = ? AND status <> 'withdrawn'");
            $stmt->execute([$tid]);
            if ((int) $stmt->fetchColumn() > 0 && empty($in['confirm_learners'])) {
                return trainings_fail('People are on it. Archive it instead.', 'HAS_LEARNERS');
            }
            trainings_delete($pdo, $tid);
            return trainings_ok();

        case 'section_add':
        case 'section_update':
        case 'section_delete':
        case 'section_move':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            if ($action === 'section_add') {
                return $reply(trainings_section_add($pdo, $t, (string) ($in['title'] ?? '')));
            }
            $s = trainings_section_get($pdo, (int) ($in['section_id'] ?? 0));
            if ($s === null || (int) $s['training_id'] !== $tid) {
                return trainings_fail('That card was removed. Reload the page.', 'NOT_FOUND');
            }
            if ($action === 'section_move') {
                trainings_section_move($pdo, $s, (int) ($in['dir'] ?? 1));
                return trainings_ok();
            }
            return $reply($action === 'section_update' ? trainings_section_update($pdo, $t, $s, $in) : trainings_section_delete($pdo, $t, $s));

        case 'item_save':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            return $reply(trainings_item_save($pdo, $t, $in, $files));

        case 'slides_add':
            // "Add slides": one .pptx per request; the page sends a batch one by one, in name order.
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            $f = $files['pptx_file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return trainings_fail('Choose a PowerPoint (.pptx) file.', 'INVALID', 'pptx_file');
            }
            if (trainings_section_get($pdo, (int) ($in['section_id'] ?? 0)) === null) {
                return trainings_fail('Choose the card it belongs to.', 'INVALID', 'section_id');
            }
            $deck = trainings_deck_from_upload($f);
            if (!$deck['ok']) {
                return trainings_fail((string) $deck['error'], 'INVALID', 'pptx_file');
            }
            $r = trainings_slides_add($pdo, $t, (int) $in['section_id'], $deck);
            if (empty($r['ok'])) {
                trainings_forget_file((string) $deck['details']['deck']);
            }
            return $reply($r);

        case 'item_delete':
        case 'item_move':
            if (!trainings_staff_can('edit')) {
                return trainings_forbidden();
            }
            $item = trainings_item_get($pdo, (int) ($in['item_id'] ?? 0));
            if ($item === null || $item['training_id'] !== $tid) {
                return trainings_fail('That item was removed. Reload the page.', 'NOT_FOUND');
            }
            if ($action === 'item_delete') {
                trainings_item_delete($pdo, $item);
            } else {
                trainings_item_move($pdo, $item, (int) ($in['dir'] ?? 1));
            }
            return trainings_ok();
    }

    return trainings_fail('Unknown action.', 'UNKNOWN_ACTION');
}

/** The Academy's list: every training with its counts. */
function trainings_overview(PDO $pdo): array
{
    $rows = $pdo->query("SELECT t.*,
            (SELECT COUNT(*) FROM training_items i WHERE i.training_id = t.id) AS items,
            (SELECT COUNT(*) FROM training_enrolments e WHERE e.training_id = t.id AND e.status <> 'withdrawn') AS learners,
            (SELECT COUNT(*) FROM training_enrolments e WHERE e.training_id = t.id AND e.status = 'completed') AS completed,
            (SELECT COUNT(*) FROM training_progress p JOIN training_enrolments e ON e.id = p.enrolment_id
              WHERE e.training_id = t.id AND p.status = 'submitted' AND e.status <> 'withdrawn') AS to_mark
          FROM trainings t
         ORDER BY CASE t.status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END,
                  CASE WHEN t.starts_on IS NULL THEN 1 ELSE 0 END, t.starts_on DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn ($r) => trainings_staff_training(null, trainings_shape($r)) + [
        'items' => (int) $r['items'], 'learners' => (int) $r['learners'], 'completed' => (int) $r['completed'], 'to_mark' => (int) $r['to_mark']], $rows);
}

/** A training as the Academy sees it. */
function trainings_staff_training(?PDO $pdo, array $t): array
{
    return trainings_public_training($t) + ['kind' => (string) $t['kind'], 'status' => (string) $t['status'],
        'status_label' => TRAININGS_STATUSES[$t['status']] ?? (string) $t['status'], 'catalogue' => (bool) $t['catalogue'],
        'live_percent' => (int) ($t['pass_rule']['live_percent'] ?? 100), 'official_label' => TRAININGS_OFFICIAL[$t['official_key'] ?? ''] ?? ''];
}

/** Name and status for a learner's progress sheet. */
function trainings_person_brief(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT first_name, last_name, spiritual_status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['id' => $userId, 'name' => trainings_person_name($u), 'status' => trainings_status_label($u['spiritual_status'] ?? '')];
}

/** The Assign presses on a training, newest first, in words. */
function trainings_assignment_history(PDO $pdo, int $trainingId): array
{
    $stmt = $pdo->prepare('SELECT a.*, u.first_name, u.last_name FROM training_assignments a LEFT JOIN users u ON u.id = a.created_by
                            WHERE a.training_id = ? ORDER BY a.id DESC LIMIT 50');
    $stmt->execute([$trainingId]);
    $deptNames = [];
    foreach ($pdo->query('SELECT id, name FROM departments')->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $deptNames[(int) $d['id']] = (string) $d['name'];
    }
    return array_map(static function ($a) use ($deptNames) {
        $aud = trainings_audience_clean(trainings_decode($a['audience']));
        $parts = [];
        if ($aud['workers']) {
            $parts[] = 'All workers';
        }
        foreach ($aud['statuses'] as $s) {
            $parts[] = trainings_status_label($s);
        }
        foreach ($aud['departments'] as $d) {
            $parts[] = $deptNames[$d] ?? 'A department';
        }
        if ($aud['users'] !== []) {
            $parts[] = count($aud['users']) . (count($aud['users']) === 1 ? ' person' : ' people');
        }
        return ['id' => (int) $a['id'], 'who' => implode(', ', $parts), 'people' => (int) $a['people'],
            'keep' => (int) $a['keep_enrolling_newcomers'] === 1, 'due_days' => $a['due_days'] !== null ? (int) $a['due_days'] : null,
            'by' => trainings_person_name($a), 'at' => (string) $a['created_at']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
