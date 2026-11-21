<?php
// /includes/trainings/people.php
//
// Who takes a training (owner's answers 3 and 13):
//   - the catalogue: published trainings marked "in the catalogue" that anyone
//     signed in joins themselves (source 'self');
//   - Assign: the Academy enrols groups (all workers, departments with their
//     sub-units, statuses such as 1st-3rd Timers) and named people from the
//     congregation, with a due date (source 'assigned'). "Keep enrolling
//     newcomers" re-runs the group part every day (cron/trainings_daily.php),
//     so someone who becomes a 1st Timer next month is enrolled too;
//   - invites: a visitor with no sign-in gets a one-time link by email
//     (source 'invite'). One tap confirms the email through the email
//     identity flow (includes/campaigns/addresses.php) and sets a password;
//     then they are signed in and land in Trainings (/auth/training_invite).
// People who left (Relocated, Attends Another Church) are never enrolled by a
// group; a named person always is. A person is enrolled once per training
// (UNIQUE training_id, user_id); assigning again changes nothing for them.

const TRAININGS_INVITE_TTL_DAYS = 7;
const TRAININGS_INVITES_PER_DAY = 3;

function trainings_enrolment_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_enrolments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? trainings_enrolment_shape($row) : null;
}

function trainings_enrolment_for(PDO $pdo, int $trainingId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_enrolments WHERE training_id = ? AND user_id = ?');
    $stmt->execute([$trainingId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? trainings_enrolment_shape($row) : null;
}

function trainings_enrolment_shape(array $e): array
{
    foreach (['id', 'training_id', 'user_id'] as $k) {
        $e[$k] = (int) $e[$k];
    }
    return $e;
}

/**
 * Enrol one person. A withdrawn enrolment comes back to active; anything else
 * already there stays as it is. @return array{id:int, created:bool, reactivated:bool}
 */
function trainings_enrol(PDO $pdo, int $trainingId, int $userId, string $source, ?int $by = null, ?string $dueOn = null): array
{
    $have = trainings_enrolment_for($pdo, $trainingId, $userId);
    $now = trainings_sql_dt();
    if ($have !== null) {
        if ($have['status'] === 'withdrawn') {
            $pdo->prepare("UPDATE training_enrolments SET status = 'active', source = ?, assigned_by = ?, due_on = ?, updated_at = ? WHERE id = ?")
                ->execute([$source, $by, $dueOn, $now, $have['id']]);
            return ['id' => $have['id'], 'created' => false, 'reactivated' => true];
        }
        return ['id' => $have['id'], 'created' => false, 'reactivated' => false];
    }
    try {
        $pdo->prepare("INSERT INTO training_enrolments (training_id, user_id, source, assigned_by, due_on, status, created_at)
                       VALUES (?, ?, ?, ?, ?, 'active', ?)")
            ->execute([$trainingId, $userId, $source, $by, $dueOn, $now]);
        return ['id' => (int) $pdo->lastInsertId(), 'created' => true, 'reactivated' => false];
    } catch (PDOException $e) {
        // Two presses at once: the other one enrolled them a moment ago.
        $have = trainings_enrolment_for($pdo, $trainingId, $userId);
        if ($have === null) {
            throw $e;
        }
        return ['id' => $have['id'], 'created' => false, 'reactivated' => false];
    }
}

function trainings_withdraw(PDO $pdo, array $enrolment): void
{
    $pdo->prepare("UPDATE training_enrolments SET status = 'withdrawn', updated_at = ? WHERE id = ?")
        ->execute([trainings_sql_dt(), $enrolment['id']]);
}

/** Published trainings anyone may join, newest first, with whether $userId is in. */
function trainings_catalogue(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT t.*, e.id AS enrolment_id, e.status AS enrolment_status
                             FROM trainings t
                             LEFT JOIN training_enrolments e ON e.training_id = t.id AND e.user_id = ?
                            WHERE t.status = 'published' AND t.catalogue = 1 AND t.kind <> 'test'
                              AND (t.ends_on IS NULL OR t.ends_on >= ?)
                            ORDER BY CASE WHEN t.starts_on IS NULL THEN 1 ELSE 0 END, t.starts_on, t.id DESC LIMIT 100");
    $stmt->execute([$userId, trainings_today()]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t = trainings_shape($row);
        $t['joined'] = $row['enrolment_id'] !== null && $row['enrolment_status'] !== 'withdrawn';
        $out[] = trainings_public_training($t) + ['joined' => $t['joined']];
    }
    return $out;
}

/** What a learner may see of a training. */
function trainings_public_training(array $t): array
{
    return ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'summary' => (string) ($t['summary'] ?? ''),
        'length' => (string) $t['length'], 'length_label' => TRAININGS_LENGTHS[$t['length']] ?? '',
        'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on'], 'official_key' => $t['official_key'],
        'gives_certificate' => (bool) $t['gives_certificate']];
}

// ---------------------------------------------------------------------------
// Audiences and Assign
// ---------------------------------------------------------------------------

/** The spiritual statuses a training can be assigned to (the users column's values). */
function trainings_known_statuses(): array
{
    return ['1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor', 'Non_Member', 'Member', 'Worker', 'Pastor'];
}

/**
 * A clean audience: workers (everyone with a live department seat), statuses,
 * departments (with their sub-units), users (named people).
 *
 * @return array{workers:bool, statuses:list<string>, departments:list<int>, users:list<int>}
 */
function trainings_audience_clean(array $in): array
{
    $ids = static fn ($v): array => array_values(array_unique(array_filter(array_map('intval', (array) $v), static fn ($i) => $i > 0)));
    return [
        'workers' => !empty($in['workers']),
        'statuses' => array_values(array_intersect(trainings_known_statuses(), array_map('strval', (array) ($in['statuses'] ?? [])))),
        'departments' => $ids($in['departments'] ?? []),
        'users' => array_slice($ids($in['users'] ?? []), 0, 500),
    ];
}

function trainings_audience_empty(array $aud): bool
{
    return !$aud['workers'] && $aud['statuses'] === [] && $aud['departments'] === [] && $aud['users'] === [];
}

/**
 * The people an audience covers (ascending ids). $groupsOnly leaves out the
 * named people (what "keep enrolling newcomers" re-runs).
 *
 * @return list<int>
 */
function trainings_audience_ids(PDO $pdo, array $aud, bool $groupsOnly = false): array
{
    $ids = [];
    $in = static fn (array $v): string => implode(',', array_fill(0, count($v), '?'));
    $departed = 'COALESCE(u.attendance_status, \'\') NOT IN (' . $in(TRAININGS_DEPARTED) . ')';
    $live = function_exists('perm_roster_has_years') && perm_roster_has_years($pdo)
        ? " AND COALESCE(ud.roster_status, 'Active') = 'Active'" : '';
    $run = static function (string $where, array $params) use ($pdo, $departed, &$ids): void {
        $stmt = $pdo->prepare("SELECT u.id FROM users u WHERE {$departed} AND {$where}");
        $stmt->execute(array_merge(TRAININGS_DEPARTED, $params));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }
    };
    if ($aud['workers']) {
        $run("u.id IN (SELECT ud.user_id FROM user_departments ud WHERE ud.is_active = 1{$live})", []);
    }
    if ($aud['statuses'] !== []) {
        $run('u.spiritual_status IN (' . $in($aud['statuses']) . ')', $aud['statuses']);
    }
    if ($aud['departments'] !== []) {
        $depts = [];
        foreach ($aud['departments'] as $d) {
            $sub = [$d];
            if (function_exists('perm_department_with_subunits')) {
                try {
                    $sub = perm_department_with_subunits($pdo, $d);
                } catch (Throwable $e) {
                    $sub = [$d];
                }
            }
            $depts = array_merge($depts, array_map('intval', $sub));
        }
        $depts = array_values(array_unique($depts));
        $run("u.id IN (SELECT ud.user_id FROM user_departments ud WHERE ud.is_active = 1{$live} AND ud.department_id IN (" . $in($depts) . '))', $depts);
    }
    if (!$groupsOnly && $aud['users'] !== []) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id IN (' . $in($aud['users']) . ')');
        $stmt->execute($aud['users']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }
    }
    $out = array_keys($ids);
    sort($out);
    return $out;
}

/** What the Assign dialog offers: statuses (with counts) and departments. */
function trainings_audience_options(PDO $pdo): array
{
    $counts = [];
    foreach ($pdo->query("SELECT spiritual_status AS s, COUNT(*) AS n FROM users WHERE spiritual_status IS NOT NULL GROUP BY spiritual_status")
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[(string) $r['s']] = (int) $r['n'];
    }
    $statuses = [];
    foreach (trainings_known_statuses() as $s) {
        $statuses[] = ['value' => $s, 'label' => trainings_status_label($s), 'count' => $counts[$s] ?? 0];
    }
    $departments = array_map(static fn ($r) => ['value' => (int) $r['id'], 'label' => (string) $r['name']],
        $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    return ['statuses' => $statuses, 'departments' => $departments];
}

/**
 * One Assign press: enrol everyone the audience covers, record the press, tell
 * the people newly enrolled. @return array{ok:bool, matched?:int, added?:int, already?:int, message?:string, field?:string}
 */
function trainings_assign(PDO $pdo, array $t, array $audience, ?string $dueOn, bool $keep, int $by): array
{
    $aud = trainings_audience_clean($audience);
    if (trainings_audience_empty($aud)) {
        return ['ok' => false, 'message' => 'Choose who takes it.', 'field' => 'audience'];
    }
    if ($dueOn !== null && $dueOn < trainings_today()) {
        return ['ok' => false, 'message' => 'Choose a due date from today on.', 'field' => 'due_on'];
    }
    $ids = trainings_audience_ids($pdo, $aud);
    $added = [];
    foreach ($ids as $uid) {
        $r = trainings_enrol($pdo, (int) $t['id'], $uid, 'assigned', $by, $dueOn);
        if ($r['created'] || $r['reactivated']) {
            $added[] = $uid;
        }
    }
    $pdo->prepare('INSERT INTO training_assignments (training_id, audience, due_days, keep_enrolling_newcomers, people, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$t['id'], trainings_json($aud), $dueOn !== null ? max(0, trainings_days_between(trainings_today(), $dueOn)) : null,
                   $keep && ($aud['workers'] || $aud['statuses'] !== [] || $aud['departments'] !== []) ? 1 : 0,
                   count($added), $by, trainings_sql_dt()]);
    if ($t['status'] === 'published') {
        trainings_notify($pdo, $added, 'New training', (string) $t['title'], '/modules/trainings/?t=' . (int) $t['id']);
    }
    return ['ok' => true, 'matched' => count($ids), 'added' => count($added), 'already' => count($ids) - count($added)];
}

/**
 * "Keep enrolling newcomers": for each such Assign press on a training that is
 * published and not over, enrol the people its groups cover now and did not
 * before. Their due date is the press's due_days from today. Daily, from
 * cron/trainings_daily.php (and when the Academy opens the training).
 */
function trainings_sync_newcomers(PDO $pdo, ?int $trainingId = null): int
{
    $sql = "SELECT a.*, t.title, t.status, t.ends_on FROM training_assignments a JOIN trainings t ON t.id = a.training_id
             WHERE a.keep_enrolling_newcomers = 1 AND t.status = 'published' AND (t.ends_on IS NULL OR t.ends_on >= ?)";
    $params = [trainings_today()];
    if ($trainingId !== null) {
        $sql .= ' AND a.training_id = ?';
        $params[] = $trainingId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY a.id');
    $stmt->execute($params);
    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $aud = trainings_audience_clean(trainings_decode($a['audience']));
        $due = $a['due_days'] !== null ? trainings_add_days(trainings_today(), (int) $a['due_days']) : null;
        $added = [];
        foreach (trainings_audience_ids($pdo, $aud, true) as $uid) {
            if (trainings_enrolment_for($pdo, (int) $a['training_id'], $uid) !== null) {
                continue;   // already in, or withdrawn by hand: never re-added by the sweep
            }
            trainings_enrol($pdo, (int) $a['training_id'], $uid, 'assigned', $a['created_by'] !== null ? (int) $a['created_by'] : null, $due);
            $added[] = $uid;
        }
        if ($added !== []) {
            $pdo->prepare('UPDATE training_assignments SET people = people + ? WHERE id = ?')->execute([count($added), $a['id']]);
            trainings_notify($pdo, $added, 'New training', (string) $a['title'], '/modules/trainings/?t=' . (int) $a['training_id']);
            $total += count($added);
        }
    }
    return $total;
}

/** The people picker: name, email or phone, at least 2 letters. */
function trainings_people_search(PDO $pdo, string $q, int $limit = 20): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $cols = ['first_name', 'last_name', $mysql ? "CONCAT(first_name, ' ', last_name)" : "first_name || ' ' || last_name", 'email', 'phone'];
    $where = implode(' OR ', array_map(static fn ($c) => "{$c} LIKE ?", $cols));
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, spiritual_status, password_hash FROM users WHERE {$where}
                            ORDER BY first_name, last_name LIMIT " . max(1, min(50, $limit)));
    $stmt->execute(array_fill(0, count($cols), $like));
    return array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => trainings_person_name($u),
        'status' => trainings_status_label($u['spiritual_status'] ?? ''),
        'signin' => (string) ($u['password_hash'] ?? '') !== ''], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Everyone on a training, for the Academy: status, progress, due date, sign-in.
 * $summaries comes from progress.php (enrolment id => summary).
 */
function trainings_learners(PDO $pdo, array $t): array
{
    $stmt = $pdo->prepare("SELECT e.*, u.first_name, u.last_name, u.spiritual_status, u.password_hash,
                                  (SELECT MAX(i.created_at) FROM training_invites i WHERE i.enrolment_id = e.id) AS invited_at
                             FROM training_enrolments e JOIN users u ON u.id = e.user_id
                            WHERE e.training_id = ? AND e.status <> 'withdrawn'
                            ORDER BY u.first_name, u.last_name");
    $stmt->execute([$t['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = trainings_items_flat($pdo, (int) $t['id']);
    $out = [];
    foreach ($rows as $r) {
        $e = trainings_enrolment_shape($r);
        $sum = trainings_enrolment_summary($pdo, $t, $e, $items);
        $out[] = ['id' => $e['id'], 'user_id' => $e['user_id'], 'name' => trainings_person_name($r),
            'person_status' => trainings_status_label($r['spiritual_status'] ?? ''), 'source' => $e['source'],
            'status' => $e['status'], 'due_on' => $e['due_on'], 'completed_at' => $e['completed_at'],
            'confirmed_at' => $e['confirmed_at'], 'signin' => (string) ($r['password_hash'] ?? '') !== '',
            'invited_at' => $r['invited_at'], 'done' => $sum['done'], 'required' => $sum['required'],
            'percent' => $sum['percent'], 'to_mark' => $sum['to_mark'], 'certificate' => (string) ($e['certificate_no'] ?? '')];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Visitor invites
// ---------------------------------------------------------------------------

/** The email to put in the invite box: a confirmed personal email, else a personal one on file. */
function trainings_invite_prefill(PDO $pdo, int $userId): string
{
    if (function_exists('member_confirmed_email')) {
        try {
            $c = member_confirmed_email($pdo, $userId);
            if ($c !== null) {
                return $c;
            }
        } catch (Throwable $e) {
            // Email not set up yet: fall through to the address on file.
        }
    }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $email = strtolower(trim((string) $stmt->fetchColumn()));
    $church = function_exists('email_is_church_domain') ? email_is_church_domain($email) : str_ends_with($email, '@hodlc.com');
    return ($email !== '' && !$church && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
}

/**
 * Does this person hold a live role or a department seat? Then a sign-in made
 * for them carries staff access, so it must never come from an invite link:
 * whoever reads the typed email would sign in with that access. Fails closed.
 */
function trainings_has_staff_access(PDO $pdo, int $userId): bool
{
    if (!function_exists('perm_ready') || !perm_ready($pdo)) {
        return false;   // no matrix yet: nobody holds a role or a seat
    }
    try {
        $today = perm_today();
        return perm_person_roles($pdo, $userId, $today) !== [] || perm_live_seats($pdo, $userId, $today) !== [];
    } catch (Throwable $e) {
        error_log('[trainings] could not read roles/seats for an invite: ' . $e->getMessage());
        return true;
    }
}

/**
 * Send (or re-send) a visitor's invite: a link that works once, for 7 days.
 * A newer link replaces any earlier one. Refused when the person can already
 * sign in (they just sign in), holds a role or a seat (staff access is never
 * handed out by email link), or the email belongs to someone else.
 *
 * @return array{ok:bool, message?:string, field?:string, sent?:bool, token?:string}
 *   token is for tests only: callers never show or log it
 */
function trainings_invite_send(PDO $pdo, array $t, array $enrolment, string $rawEmail, int $by): array
{
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, password_hash, account_status FROM users WHERE id = ?');
    $stmt->execute([$enrolment['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['ok' => false, 'message' => 'That person was not found.'];
    }
    if ((string) ($user['password_hash'] ?? '') !== '') {
        return ['ok' => false, 'message' => 'They can sign in already.', 'code' => 'HAS_SIGNIN'];
    }
    if (strtolower((string) ($user['account_status'] ?? 'active')) !== 'active') {
        return ['ok' => false, 'message' => 'This account is not active.'];
    }
    if (trainings_has_staff_access($pdo, (int) $user['id'])) {
        return ['ok' => false, 'message' => 'They hold a role. An admin sets up their sign-in.', 'code' => 'STAFF'];
    }
    $email = function_exists('email_normalize') ? email_normalize($rawEmail) : strtolower(trim($rawEmail));
    $problem = function_exists('email_address_problem') ? email_address_problem($email, $pdo, (int) $user['id'])
        : (filter_var($email, FILTER_VALIDATE_EMAIL) ? null : 'Check the email address.');
    if ($problem !== null) {
        return ['ok' => false, 'message' => $problem, 'field' => 'email'];
    }
    if (function_exists('member_by_confirmed_email')) {
        $owner = member_by_confirmed_email($pdo, $email);
        if ($owner !== null && $owner !== (int) $user['id']) {
            return ['ok' => false, 'message' => 'That email belongs to someone else.', 'field' => 'email'];
        }
    }
    if (!function_exists('email_send_doc') || !function_exists('campaigns_ready') || !campaigns_ready($pdo)) {
        return ['ok' => false, 'message' => 'Email is being set up. Try again later.', 'code' => 'FEATURE_NOT_READY'];
    }
    $now = trainings_now();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_invites WHERE user_id = ? AND created_at >= ?');
    $stmt->execute([$user['id'], trainings_sql_dt($now->modify('-1 day'))]);
    if ((int) $stmt->fetchColumn() >= TRAININGS_INVITES_PER_DAY) {
        return ['ok' => false, 'message' => 'Three links went today. Try tomorrow.'];
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $nowSql = trainings_sql_dt($now);
    $pdo->prepare('INSERT INTO training_invites (enrolment_id, user_id, email, token_hash, created_by, created_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$enrolment['id'], $user['id'], $email, hash('sha256', $token), $by, $nowSql,
                   trainings_sql_dt($now->modify('+' . TRAININGS_INVITE_TTL_DAYS . ' days'))]);
    $inviteId = (int) $pdo->lastInsertId();

    $sent = false;
    $link = function_exists('site_url') ? site_url('/auth/training_invite?t=' . rawurlencode($token)) : '/auth/training_invite?t=' . rawurlencode($token);
    try {
        $queued = email_send_doc($pdo, trainings_invite_doc((string) $t['title'], $link), $user + ['email' => $email], [
            'lane' => 'transactional', 'purpose' => 'account', 'flow' => 'training_invite', 'to_email' => $email,
            'created_by' => $by, 'now' => true,
        ]);
        $sent = !empty($queued['ok']);
        if (!$sent) {
            error_log('[trainings] invite not queued for user ' . $user['id'] . ': ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('[trainings] invite email failed: ' . $e->getMessage());
    }
    if (!$sent) {
        // A link nobody was sent must not stay usable; the earlier one still works.
        $pdo->prepare('DELETE FROM training_invites WHERE id = ?')->execute([$inviteId]);
        return ['ok' => false, 'message' => 'The email could not be sent. Try again later.'];
    }
    // A newer link replaces any earlier one.
    $pdo->prepare('UPDATE training_invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL AND id <> ?')
        ->execute([$nowSql, $user['id'], $inviteId]);
    return ['ok' => true, 'sent' => true, 'token' => $token];
}

function trainings_invite_doc(string $trainingTitle, string $link): array
{
    return [
        'v' => 1,
        'subject' => 'Your place in ' . $trainingTitle,
        'preheader' => 'One tap to start. The link works for ' . TRAININGS_INVITE_TTL_DAYS . ' days.',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'You are invited'],
            email_doc_paragraphs(
                'Hi {{first_name}},',
                'The HOD Academy has kept you a place in ' . $trainingTitle . '. Tap the button, choose a password, and your classes open.'
            ),
            ['type' => 'button', 'label' => 'Start my training', 'href' => $link, 'style' => 'primary', 'align' => 'left'],
            email_doc_paragraphs(
                'The link works once, for ' . TRAININGS_INVITE_TTL_DAYS . ' days. It also confirms this email address, so password reset links come here.'
            ),
            ['type' => 'footer'],
        ],
    ];
}

/**
 * An invite link's state for the page's GET (it never uses the link).
 * @return array{state:string, invite?:array, user?:array, training?:array}
 *   state: valid | expired | used | invalid | signin (the person can sign in already)
 */
function trainings_invite_peek(PDO $pdo, string $token): array
{
    if ($token === '' || !trainings_ready($pdo)) {
        return ['state' => 'invalid'];
    }
    $stmt = $pdo->prepare('SELECT i.*, e.training_id FROM training_invites i JOIN training_enrolments e ON e.id = i.enrolment_id
                            WHERE i.token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return ['state' => 'invalid'];
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$inv['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $t = trainings_get($pdo, (int) $inv['training_id']);
    $out = ['invite' => $inv, 'user' => $user, 'training' => $t];
    if ($inv['used_at'] !== null) {
        return ['state' => 'used'] + $out;
    }
    if ((string) $inv['expires_at'] < trainings_sql_dt()) {
        return ['state' => 'expired'] + $out;
    }
    if ((string) ($user['password_hash'] ?? '') !== '') {
        return ['state' => 'signin'] + $out;
    }
    // A role or seat given after the link went out: the link stops working (accept checks this too).
    if (trainings_has_staff_access($pdo, (int) $inv['user_id'])) {
        return ['state' => 'staff'] + $out;
    }
    return ['state' => 'valid'] + $out;
}

/**
 * Accept an invite: confirm the email (the email identity flow) and set the
 * password, in that order, in one transaction; the link is used first so two
 * taps cannot both win. The caller signs the person in.
 *
 * @return array{ok:bool, code?:string, problems?:list<string>, user?:array}
 *   code: INVALID | EXPIRED | USED | SIGNIN | MISMATCH | WEAK | NOT_ACTIVE | TAKEN
 */
function trainings_invite_accept(PDO $pdo, string $token, string $password, string $confirm, ?string $ip = null): array
{
    $peek = trainings_invite_peek($pdo, $token);
    if ($peek['state'] !== 'valid') {
        return ['ok' => false, 'code' => strtoupper($peek['state'])];
    }
    if ($password !== $confirm) {
        return ['ok' => false, 'code' => 'MISMATCH'];
    }
    $user = $peek['user'];
    $uid = (int) $user['id'];
    if (strtolower((string) ($user['account_status'] ?? 'active')) !== 'active') {
        return ['ok' => false, 'code' => 'NOT_ACTIVE'];
    }
    $problems = function_exists('security_password_problems') ? security_password_problems($password, $user) : [];
    if ($problems !== []) {
        return ['ok' => false, 'code' => 'WEAK', 'problems' => $problems];
    }
    $email = (string) $peek['invite']['email'];
    $now = trainings_sql_dt();
    $pdo->beginTransaction();
    try {
        $use = $pdo->prepare('UPDATE training_invites SET used_at = ? WHERE token_hash = ? AND used_at IS NULL');
        $use->execute([$now, hash('sha256', $token)]);
        if ($use->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'USED'];
        }
        if (function_exists('email_capture_start') && function_exists('campaigns_ready') && campaigns_ready($pdo)) {
            $start = email_capture_start($pdo, $uid, $email, 'staff', $peek['invite']['created_by'] !== null ? (int) $peek['invite']['created_by'] : null,
                ['send' => false, 'ip' => $ip]);
            if (empty($start['ok'])) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'TAKEN'];
            }
            if (empty($start['already_confirmed'])) {
                $conf = email_capture_confirm($pdo, (string) $start['token'], $ip);
                if (empty($conf['ok'])) {
                    $pdo->rollBack();
                    return ['ok' => false, 'code' => ($conf['code'] ?? '') === 'TAKEN' ? 'TAKEN' : 'INVALID'];
                }
            }
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (function_exists('security_schema_ready') && security_schema_ready($pdo)) {
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = ?, must_change_password = 0,
                                  failed_login_count = 0, locked_until = NULL WHERE id = ?')
                ->execute([$hash, $now, $uid]);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
        }
        $pdo->prepare("UPDATE training_enrolments SET status = 'active', updated_at = ? WHERE id = ? AND status = 'withdrawn'")
            ->execute([$now, $peek['invite']['enrolment_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if (function_exists('security_log')) {
        try {
            security_log($pdo, 'password_set_by_invite', $uid, 'Password chosen from a Trainings invite; email confirmed.', null, 'System');
        } catch (Throwable $e) {
            error_log('[trainings] security log: ' . $e->getMessage());
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    return ['ok' => true, 'user' => $stmt->fetch(PDO::FETCH_ASSOC) ?: $user, 'training_id' => (int) $peek['invite']['training_id']];
}
