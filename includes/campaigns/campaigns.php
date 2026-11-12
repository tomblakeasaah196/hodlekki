<?php
// /includes/campaigns/campaigns.php
//
// Email campaigns (design §10, PR 5): one email to many people, written in the
// block editor, sent now or at a set time, with an optional text to the people
// email cannot reach, and a report.
//
//   draft ──► scheduled ──► sending ──► sent
//     │         │  (time)    ▲ campaigns_tick() expands it once,
//     └──► queued ───────────┘ inside cron/email_worker.php
//   any of draft / scheduled / queued / sending ──► cancelled
//   an expansion that throws ──► failed (back to a draft with Edit)
//
// Who a campaign reaches is decided ONCE, when it is expanded: one
// email_campaign_people row per person (channel email / sms / none and why),
// one outbox row per email (lane 'campaign', church news: unsubscribe link,
// consent and suppression checked again by the worker just before it sends),
// and one SMS Studio campaign for the texts (its worker applies the spam
// guard, suppression list and wallet check). The order is the automations'
// (automation_choose_channel()): unsubscribed → nobody; email when
// email_may_send_news() allows it; else a text when the campaign has one;
// else nobody. campaign_reach() does that for a whole audience in a few
// queries; tests/campaigns pins it person by person against the per-person
// functions.
//
// Access is the caller's job (api/campaigns_api.php): a campaign's target for
// can('campaigns', …) is its department, or 'church' when it has none. People
// who hold Campaigns only for their department(s) send department campaigns,
// and those go to that department's people only (lead, 11 Nov 2026).

require_once __DIR__ . '/bootstrap.php';

const CAMPAIGN_STATUSES  = ['draft', 'scheduled', 'queued', 'sending', 'sent', 'cancelled', 'failed'];
const CAMPAIGN_EDITABLE  = ['draft', 'scheduled', 'failed'];
const CAMPAIGN_AUDIENCES = [
    'church'       => 'Everyone',
    'statuses'     => 'By status',
    'departments'  => 'Departments',
    'regions'      => 'Regional hubs',
    'first_timers' => 'First timers',
];
const CAMPAIGN_REASONS = [
    'unsubscribed' => 'Unsubscribed',
    'no_email'     => 'No email',
    'no_consent'   => 'No consent',
    'bounced'      => 'Email bounced',
    'no_phone'     => 'No mobile number',
    'sms_blocked'  => 'Number blocked',
    'no_sms'       => 'No SMS set up',
    'failed'       => 'Could not queue',
];
const CAMPAIGN_SCHEDULE_DAYS = 60;   // how far ahead a campaign may be set
const CAMPAIGN_CHUNK         = 400;  // ids per IN (...) list

// ---------------------------------------------------------------------------
// Rows
// ---------------------------------------------------------------------------

function campaigns_module_ready(PDO $pdo): bool
{
    return campaigns_ready($pdo) && campaigns_table_exists($pdo, 'email_campaign_people');
}

/** A campaign with doc and audience decoded, or null. */
function campaign_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM email_campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? campaign_decode($row) : null;
}

function campaign_decode(array $row): array
{
    foreach (['id', 'department_id', 'sms_fallback', 'track_opens', 'total', 'emailed', 'texted', 'skipped',
              'sms_campaign_id', 'created_by', 'updated_by', 'sent_by'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
    }
    $doc = json_decode((string) ($row['doc_json'] ?? ''), true);
    $row['doc'] = is_array($doc) ? $doc : email_doc_empty();
    $aud = json_decode((string) ($row['audience_json'] ?? ''), true);
    $row['audience'] = is_array($aud) ? $aud : ['kind' => ''];
    unset($row['doc_json'], $row['audience_json']);
    return $row;
}

/** What can('campaigns', …) is asked about this campaign. */
function campaign_target(array $c)
{
    return !empty($c['department_id']) ? (int) $c['department_id'] : 'church';
}

/**
 * Campaigns the person may see, newest first. $scope is perm_scope('campaigns').
 * @return list<array>
 */
function campaign_list(PDO $pdo, array $scope, int $me, int $limit = 200): array
{
    if (!$scope['church'] && $scope['depts'] === [] && !$scope['own']) {
        return [];
    }
    $where = '1=1';
    $params = [];
    if (!$scope['church']) {
        $parts = [];
        if ($scope['depts'] !== []) {
            $parts[] = 'department_id IN (' . implode(',', array_fill(0, count($scope['depts']), '?')) . ')';
            $params = array_merge($params, array_map('intval', $scope['depts']));
        }
        if ($scope['own']) {
            $parts[] = 'created_by = ?';
            $params[] = $me;
        }
        $where = '(' . implode(' OR ', $parts) . ')';
    }
    $stmt = $pdo->prepare("SELECT id, name, subject, department_id, status, scheduled_at, queued_at, finished_at,
                                  total, emailed, texted, skipped, created_by, created_at, updated_at
                             FROM email_campaigns WHERE {$where}
                            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT " . max(1, min(500, $limit)));
    $stmt->execute($params);
    return array_map('campaign_decode', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------
// Audiences
// ---------------------------------------------------------------------------

/**
 * Check an audience against what the sender may reach.
 * $church: the sender may send to the whole church (can('campaigns', 'create', 'church')).
 * $depts:  the departments they may send for otherwise.
 *
 * @return array{ok:bool, audience:array, errors:list<string>}
 */
function campaign_audience_check(array $aud, ?int $deptId, bool $church, array $depts): array
{
    $kind = (string) ($aud['kind'] ?? '');
    $ids = static fn ($v): array => array_values(array_unique(array_filter(array_map('intval', (array) $v), static fn ($i) => $i > 0)));
    $out = ['kind' => $kind];
    $errors = [];
    if (!isset(CAMPAIGN_AUDIENCES[$kind])) {
        return ['ok' => false, 'audience' => ['kind' => ''], 'errors' => ['Choose who gets it.']];
    }
    switch ($kind) {
        case 'statuses':
            $out['statuses'] = array_values(array_unique(array_filter(array_map(
                static fn ($s) => campaigns_cut(trim((string) $s), 40), (array) ($aud['statuses'] ?? [])))));
            if ($out['statuses'] === []) {
                $errors[] = 'Pick at least one status.';
            }
            break;
        case 'departments':
        case 'regions':
            $out['ids'] = $ids($aud['ids'] ?? []);
            if ($out['ids'] === []) {
                $errors[] = $kind === 'departments' ? 'Pick at least one department.' : 'Pick at least one regional hub.';
            }
            break;
    }
    if (!$church) {
        // A department sender: their department's people, nothing wider.
        if ($deptId === null || !in_array($deptId, $depts, true)) {
            $errors[] = 'Send this from your department.';
        } elseif ($kind !== 'departments') {
            $errors[] = 'Department campaigns go to department members.';
        } elseif (array_diff($out['ids'] ?? [], $depts) !== []) {
            $errors[] = 'You can only send to your own departments.';
        }
    }
    return ['ok' => $errors === [], 'audience' => $out, 'errors' => $errors];
}

/** The choices the audience picker offers this sender. */
function campaign_audience_options(PDO $pdo, bool $church, array $depts): array
{
    $out = ['kinds' => [], 'statuses' => [], 'departments' => [], 'regions' => []];
    foreach (CAMPAIGN_AUDIENCES as $k => $label) {
        if ($church || $k === 'departments') {
            $out['kinds'][] = ['value' => $k, 'label' => $label];
        }
    }
    $rows = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($church || in_array((int) $r['id'], $depts, true)) {
            $out['departments'][] = ['value' => (int) $r['id'], 'label' => (string) $r['name']];
        }
    }
    if ($church) {
        foreach ($pdo->query("SELECT spiritual_status AS s, COUNT(*) AS n FROM users
                               WHERE spiritual_status IS NOT NULL AND spiritual_status <> ''
                               GROUP BY spiritual_status ORDER BY spiritual_status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['statuses'][] = ['value' => (string) $r['s'], 'label' => str_replace('_', ' ', (string) $r['s']), 'count' => (int) $r['n']];
        }
        if (campaigns_table_exists($pdo, 'regions')) {
            foreach ($pdo->query('SELECT id, name FROM regions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['regions'][] = ['value' => (int) $r['id'], 'label' => (string) $r['name']];
            }
        }
    }
    return $out;
}

/** A department and its sub-units (the roster's own tree when the permissions tables are there). */
function campaign_department_ids(PDO $pdo, array $ids): array
{
    $all = [];
    foreach ($ids as $id) {
        $sub = [(int) $id];
        if (function_exists('perm_department_with_subunits')) {
            try {
                $sub = perm_department_with_subunits($pdo, (int) $id);
            } catch (Throwable $e) {
                $sub = [(int) $id];
            }
        }
        $all = array_merge($all, $sub);
    }
    $all = array_values(array_unique(array_map('intval', $all)));
    sort($all);
    return $all;
}

/**
 * The people an audience covers (user ids, ascending). People who left
 * (Relocated, Attends Another Church) are never included.
 *
 * @return list<int>
 */
function campaign_audience_ids(PDO $pdo, array $aud): array
{
    $departed = implode(',', array_fill(0, count(AUTOMATION_DEPARTED), '?'));
    $where = ["COALESCE(u.attendance_status, '') NOT IN ({$departed})"];
    $params = AUTOMATION_DEPARTED;
    $in = static fn (array $v): string => implode(',', array_fill(0, count($v), '?'));
    switch ((string) ($aud['kind'] ?? '')) {
        case 'church':
            break;
        case 'statuses':
            $st = array_values((array) ($aud['statuses'] ?? []));
            if ($st === []) {
                return [];
            }
            $where[] = 'u.spiritual_status IN (' . $in($st) . ')';
            $params = array_merge($params, $st);
            break;
        case 'departments':
            $ids = campaign_department_ids($pdo, (array) ($aud['ids'] ?? []));
            if ($ids === []) {
                return [];
            }
            $live = function_exists('perm_roster_has_years') && perm_roster_has_years($pdo)
                ? " AND COALESCE(ud.roster_status, 'Active') = 'Active'" : '';
            $where[] = 'u.id IN (SELECT ud.user_id FROM user_departments ud WHERE ud.is_active = 1' . $live
                     . ' AND ud.department_id IN (' . $in($ids) . '))';
            $params = array_merge($params, $ids);
            break;
        case 'regions':
            $ids = array_values(array_map('intval', (array) ($aud['ids'] ?? [])));
            if ($ids === []) {
                return [];
            }
            $where[] = 'u.region_id IN (' . $in($ids) . ')';
            $params = array_merge($params, $ids);
            break;
        case 'first_timers':
            $where[] = embrace_pipeline_sql($pdo, 'u');
            break;
        default:
            return [];
    }
    $stmt = $pdo->prepare('SELECT u.id FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id');
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** id => row (id, first_name, last_name, phone, spiritual_status) for these people. */
function campaign_people_rows(PDO $pdo, array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values($ids), CAMPAIGN_CHUNK) as $chunk) {
        $stmt = $pdo->prepare('SELECT id, first_name, last_name, phone, spiritual_status FROM users WHERE id IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r;
        }
    }
    return $out;
}

/**
 * How each person would be reached, for a whole audience at once.
 * The same decisions as automation_choose_channel() + email_may_send_news(),
 * read in a few queries instead of four per person.
 *
 * @param array<int,array> $people id => row (needs phone, spiritual_status)
 * @return array<int,array{channel:string, email?:string, phone?:string, reason?:string}>
 */
function campaign_reach(PDO $pdo, array $people, bool $smsFallback): array
{
    $ids = array_keys($people);
    $confirmed = $emailConsent = $smsConsent = $suppressed = $smsBlocked = [];
    foreach (array_chunk($ids, CAMPAIGN_CHUNK) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT confirmed_user_id, confirmed_email FROM email_addresses WHERE confirmed_user_id IN ({$in})");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $confirmed[(int) $r['confirmed_user_id']] = (string) $r['confirmed_email'];
        }
        // The latest row per person and channel wins (ascending id: the last one seen).
        $stmt = $pdo->prepare("SELECT user_id, channel, action FROM email_consents
                                WHERE purpose = 'news' AND user_id IN ({$in}) ORDER BY id");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['channel'] === 'email') {
                $emailConsent[(int) $r['user_id']] = (string) $r['action'];
            } elseif ($r['channel'] === 'sms') {
                $smsConsent[(int) $r['user_id']] = (string) $r['action'];
            }
        }
    }
    $emails = array_values(array_unique($confirmed));
    $now = campaigns_now();
    foreach (array_chunk($emails, CAMPAIGN_CHUNK) as $chunk) {
        $stmt = $pdo->prepare('SELECT email, reason, until_at FROM email_suppressions WHERE released_at IS NULL AND email IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // email_is_suppressed(): a soft bounce counts only until its until_at.
            if ($r['reason'] !== 'soft_bounce' || ($r['until_at'] !== null && campaigns_dt((string) $r['until_at']) > $now)) {
                $suppressed[(string) $r['email']] = true;
            }
        }
    }
    $sms = $smsFallback && automation_sms_available($pdo);
    $phones = [];
    if ($sms) {
        foreach ($people as $id => $p) {
            $phones[$id] = automation_sms_phone((string) ($p['phone'] ?? ''));
        }
        $list = array_values(array_unique(array_filter($phones)));
        if ($list !== [] && campaigns_table_exists($pdo, 'sms_suppression')) {
            foreach (array_chunk($list, CAMPAIGN_CHUNK) as $chunk) {
                $stmt = $pdo->prepare('SELECT phone FROM sms_suppression WHERE released_at IS NULL AND phone IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ph) {
                    $smsBlocked[(string) $ph] = true;
                }
            }
        }
    }

    $out = [];
    foreach ($people as $id => $p) {
        // Said no to church news by email (even with no confirmed address): skipped, never texted.
        if (($emailConsent[$id] ?? '') === 'withdrawn') {
            $out[$id] = ['channel' => 'none', 'reason' => 'unsubscribed'];
            continue;
        }
        $email = $confirmed[$id] ?? null;
        $why = 'no_email';
        if ($email !== null) {
            $member = in_array((string) ($p['spiritual_status'] ?? ''), EMAIL_MEMBER_STATUSES, true);
            if (($emailConsent[$id] ?? '') !== 'granted' && !$member) {
                $why = 'no_consent';
            } elseif (isset($suppressed[$email])) {
                $why = 'bounced';
            } else {
                $out[$id] = ['channel' => 'email', 'email' => $email];
                continue;
            }
        }
        if (!$smsFallback) {
            $out[$id] = ['channel' => 'none', 'reason' => $why];
        } elseif (($smsConsent[$id] ?? '') === 'withdrawn') {
            $out[$id] = ['channel' => 'none', 'reason' => 'unsubscribed'];
        } elseif (!$sms) {
            $out[$id] = ['channel' => 'none', 'reason' => 'no_sms'];
        } elseif ($phones[$id] === null) {
            $out[$id] = ['channel' => 'none', 'reason' => 'no_phone'];
        } elseif (isset($smsBlocked[$phones[$id]])) {
            $out[$id] = ['channel' => 'none', 'reason' => 'sms_blocked'];
        } else {
            $out[$id] = ['channel' => 'sms', 'phone' => $phones[$id]];
        }
    }
    return $out;
}

/**
 * The numbers shown before Send: "212 people: 168 by email, 31 by text, 13 not reached".
 *
 * @return array{total:int, email:int, sms:int, none:int, reasons:array<string,int>, eta:string}
 */
function campaign_summary(PDO $pdo, array $aud, bool $smsFallback): array
{
    $people = campaign_people_rows($pdo, campaign_audience_ids($pdo, $aud));
    $n = ['total' => count($people), 'email' => 0, 'sms' => 0, 'none' => 0, 'reasons' => []];
    foreach (campaign_reach($pdo, $people, $smsFallback) as $r) {
        $n[$r['channel']]++;
        if ($r['channel'] === 'none') {
            $n['reasons'][$r['reason']] = ($n['reasons'][$r['reason']] ?? 0) + 1;
        }
    }
    $n['eta'] = campaign_eta($pdo, $n['email']);
    return $n;
}

/** "about 3 hours" at today's budget for bulk mail and the send window. */
function campaign_eta(PDO $pdo, int $emails): string
{
    if ($emails <= 0) {
        return '';
    }
    $perHour = max(1, (int) email_budget_status($pdo)['bulk_limit']);
    $hours = (int) ceil($emails / $perHour);
    if ($hours <= 1) {
        return 'within the hour';
    }
    $start = campaigns_minutes(email_setting($pdo, 'window_start')) ?? 7 * 60;
    $end = campaigns_minutes(email_setting($pdo, 'window_end')) ?? 20 * 60;
    $perDay = max(1, intdiv(max(60, $end - $start), 60));
    return $hours <= $perDay ? 'about ' . $hours . ' hours' : 'about ' . (int) ceil($hours / $perDay) . ' days';
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/** A department's own mailbox (Mail), the natural Reply-To for its campaigns. */
function campaign_department_mailbox(PDO $pdo, ?int $deptId): ?string
{
    if ($deptId === null || !campaigns_table_exists($pdo, 'mail_accounts')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT email FROM mail_accounts WHERE kind = 'department' AND department_id = ? AND status = 'active' ORDER BY id LIMIT 1");
    $stmt->execute([$deptId]);
    $email = $stmt->fetchColumn();
    return $email === false ? null : (string) $email;
}

/**
 * Create (id null) or change a campaign's settings. The email itself is
 * saved with campaign_save_doc(). $expect: the updated_at the form loaded
 * (null on create), so two people never overwrite each other unknowingly.
 *
 * $in: name, department_id, audience, reply_to, sms_fallback, sms_text, track_opens
 * @return array{ok:bool, id?:int, errors?:list<string>, code?:string}
 */
function campaign_save(PDO $pdo, ?int $id, array $in, int $actor, bool $church, array $depts, ?string $expect = null): array
{
    $errors = [];
    $name = campaigns_cut(trim(str_replace(["\r", "\n"], ' ', (string) ($in['name'] ?? ''))), 120);
    if ($name === '') {
        $errors[] = 'Give it a name.';
    }
    $deptId = (int) ($in['department_id'] ?? 0) ?: null;
    if ($deptId !== null && !$church && !in_array($deptId, $depts, true)) {
        $errors[] = 'Send this from your department.';
    }
    $aud = campaign_audience_check((array) ($in['audience'] ?? []), $deptId, $church, $depts);
    $errors = array_merge($errors, $aud['errors']);
    $reply = email_normalize((string) ($in['reply_to'] ?? ''));
    if ($reply !== '' && !email_is_valid_syntax($reply)) {
        $errors[] = 'Replies go to: not a valid email.';
    }
    $smsOn = !empty($in['sms_fallback']);
    $smsText = trim(str_replace("\r\n", "\n", (string) ($in['sms_text'] ?? '')));
    if (mb_strlen($smsText) > 480) {
        $errors[] = 'The text is too long (480 characters at most).';
    }
    if ($smsOn && $smsText === '') {
        $errors[] = 'Write the text, or untick it.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }
    $now = campaigns_sql_dt(campaigns_now());
    $values = [$name, campaigns_json($aud['audience']), $deptId, $reply !== '' ? $reply : null, $smsOn ? 1 : 0,
               $smsText !== '' ? $smsText : null, !empty($in['track_opens']) ? 1 : 0];

    if ($id === null) {
        $doc = email_doc_empty();
        $pdo->prepare("INSERT INTO email_campaigns (name, audience_json, department_id, reply_to, sms_fallback, sms_text, track_opens,
                                                    doc_json, status, created_by, created_at, updated_by, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)")
            ->execute(array_merge($values, [campaigns_json($doc), $actor, $now, $actor, $now]));
        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }
    return campaign_update($pdo, $id, 'name = ?, audience_json = ?, department_id = ?, reply_to = ?, sms_fallback = ?, sms_text = ?, track_opens = ?',
        $values, $actor, $expect);
}

/** Save the email (the block document). @return array{ok:bool, errors?:list<string>, code?:string} */
function campaign_save_doc(PDO $pdo, int $id, $doc, int $actor, ?string $expect = null): array
{
    $check = email_doc_validate($doc);
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $d = $check['doc'];
    return campaign_update($pdo, $id, 'doc_json = ?, subject = ?, preheader = ?',
        [campaigns_json($d), campaigns_cut((string) $d['subject'], 255), campaigns_cut((string) ($d['preheader'] ?? ''), 255)], $actor, $expect);
}

/** One guarded UPDATE: only while editable, and only over the version the form loaded. */
function campaign_update(PDO $pdo, int $id, string $set, array $values, int $actor, ?string $expect): array
{
    $editable = "'" . implode("', '", CAMPAIGN_EDITABLE) . "'";
    $now = campaigns_sql_dt(campaigns_now());
    $sql = "UPDATE email_campaigns SET {$set}, updated_by = ?, updated_at = ? WHERE id = ? AND status IN ({$editable})";
    $params = array_merge($values, [$actor, $now, $id]);
    if ($expect !== null) {
        $sql .= ' AND COALESCE(updated_at, created_at) = ?';
        $params[] = $expect;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() === 1) {
        return ['ok' => true, 'id' => $id, 'updated_at' => $now];
    }
    $c = campaign_get($pdo, $id);
    if ($c === null) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'errors' => ['That campaign no longer exists.']];
    }
    if (!in_array($c['status'], CAMPAIGN_EDITABLE, true)) {
        return ['ok' => false, 'code' => 'LOCKED', 'errors' => ['It is already going out, so it can no longer change.']];
    }
    return ['ok' => false, 'code' => 'STALE', 'errors' => ['Someone else changed it. Reload to see their version.']];
}

/** What stops this campaign from going out (empty = ready). @return list<string> */
function campaign_problems(PDO $pdo, array $c): array
{
    $out = [];
    $blocks = array_filter((array) ($c['doc']['blocks'] ?? []), static fn ($b) => ($b['type'] ?? '') !== 'footer');
    $check = email_doc_validate($c['doc']);
    if ($blocks === []) {
        $out[] = 'The email is empty.';
    } elseif (!$check['ok']) {
        $out[] = 'The email: ' . implode(' ', array_slice($check['errors'], 0, 2));
    }
    if (!isset(CAMPAIGN_AUDIENCES[(string) ($c['audience']['kind'] ?? '')])) {
        $out[] = 'Choose who gets it.';
    }
    if (!empty($c['sms_fallback']) && trim((string) $c['sms_text']) === '') {
        $out[] = 'Write the text, or untick it.';
    }
    if (!email_public_endpoints_ready()) {
        $out[] = 'The unsubscribe page is not on this site yet.';
    }
    return $out;
}

/**
 * Send now ($at null) or at $at. The worker expands it within a minute of
 * that time. @return array{ok:bool, message?:string, errors?:list<string>, code?:string}
 */
function campaign_send(PDO $pdo, int $id, ?DateTimeImmutable $at, int $actor, ?string $expect = null): array
{
    $c = campaign_get($pdo, $id);
    if ($c === null) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'errors' => ['That campaign no longer exists.']];
    }
    $problems = campaign_problems($pdo, $c);
    if ($problems !== []) {
        return ['ok' => false, 'errors' => $problems];
    }
    $now = campaigns_now();
    if ($at !== null) {
        if ($at <= $now->modify('+5 minutes')) {
            return ['ok' => false, 'errors' => ['Pick a time at least 5 minutes from now, or send it now.']];
        }
        if ($at > $now->modify('+' . CAMPAIGN_SCHEDULE_DAYS . ' days')) {
            return ['ok' => false, 'errors' => ['Pick a time within ' . CAMPAIGN_SCHEDULE_DAYS . ' days.']];
        }
    }
    $r = campaign_update($pdo, $id, 'status = ?, scheduled_at = ?, sent_by = ?',
        [$at === null ? 'queued' : 'scheduled', $at === null ? null : campaigns_sql_dt($at), $actor], $actor, $expect);
    if (!$r['ok']) {
        return $r;
    }
    return ['ok' => true, 'message' => $at === null ? 'Sending starts within a minute.' : 'Set for ' . $at->format('D j M, H:i') . '.'];
}

/** A scheduled (or failed) campaign back to a draft. */
function campaign_unschedule(PDO $pdo, int $id, int $actor): bool
{
    $stmt = $pdo->prepare("UPDATE email_campaigns SET status = 'draft', scheduled_at = NULL, updated_by = ?, updated_at = ?
                            WHERE id = ? AND status IN ('scheduled', 'failed')");
    $stmt->execute([$actor, campaigns_sql_dt(campaigns_now()), $id]);
    return $stmt->rowCount() === 1;
}

/**
 * Stop a campaign: nothing more leaves. Emails and texts already sent stay sent.
 * @return array{ok:bool, emails:int, texts:int, message:string}
 */
function campaign_cancel(PDO $pdo, int $id, int $actor): array
{
    $c = campaign_get($pdo, $id);
    if ($c === null || !in_array($c['status'], ['draft', 'scheduled', 'queued', 'sending', 'failed'], true)) {
        return ['ok' => false, 'emails' => 0, 'texts' => 0, 'message' => 'There is nothing left to stop.'];
    }
    $now = campaigns_sql_dt(campaigns_now());
    $pdo->prepare("UPDATE email_campaigns SET status = 'cancelled', finished_at = ?, updated_by = ?, updated_at = ?
                    WHERE id = ? AND status IN ('draft', 'scheduled', 'queued', 'sending', 'failed')")
        ->execute([$now, $actor, $now, $id]);
    $stmt = $pdo->prepare("UPDATE email_outbox SET status = 'cancelled', last_error = 'Campaign stopped'
                            WHERE campaign_id = ? AND status = 'queued'");
    $stmt->execute([$id]);
    $emails = $stmt->rowCount();
    $texts = 0;
    if (!empty($c['sms_campaign_id']) && automation_sms_available($pdo)) {
        $stmt = $pdo->prepare("UPDATE sms_queue SET status = 'cancelled' WHERE campaign_id = ? AND status = 'queued'");
        $stmt->execute([$c['sms_campaign_id']]);
        $texts = $stmt->rowCount();
        $pdo->prepare("UPDATE sms_campaigns SET status = 'cancelled' WHERE id = ? AND status IN ('queued', 'sending')")
            ->execute([$c['sms_campaign_id']]);
    }
    $left = $emails + $texts;
    return ['ok' => true, 'emails' => $emails, 'texts' => $texts,
            'message' => $c['status'] === 'sending' ? ($left > 0 ? "Stopped. {$left} not sent." : 'Stopped. Everything had already gone.') : 'Cancelled.'];
}

/** Delete a campaign that never sent anything. */
function campaign_delete(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("DELETE FROM email_campaigns WHERE id = ? AND status IN ('draft', 'cancelled', 'failed') AND queued_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->rowCount() === 1;
}

/** A new draft with the same email, audience and settings. */
function campaign_duplicate(PDO $pdo, array $c, int $actor): int
{
    $now = campaigns_sql_dt(campaigns_now());
    $pdo->prepare("INSERT INTO email_campaigns (name, subject, preheader, doc_json, audience_json, department_id, reply_to, sms_fallback,
                                                sms_text, track_opens, status, created_by, created_at, updated_by, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)")
        ->execute([campaigns_cut('Copy of ' . $c['name'], 120), $c['subject'], $c['preheader'], campaigns_json($c['doc']),
                   campaigns_json($c['audience']), $c['department_id'], $c['reply_to'], $c['sms_fallback'], $c['sms_text'],
                   $c['track_opens'], $actor, $now, $actor, $now]);
    return (int) $pdo->lastInsertId();
}

/** Send the email to yourself, marked as a test (account mail: no unsubscribe link). */
function campaign_test_send(PDO $pdo, array $c, int $viewerId): array
{
    $check = email_doc_validate($c['doc']);
    if (!$check['ok']) {
        return ['ok' => false, 'message' => 'Fix the email first.'];
    }
    $to = member_confirmed_email($pdo, $viewerId);
    $person = email_person($pdo, $viewerId);
    if ($to === null || $person === null) {
        return ['ok' => false, 'message' => 'Confirm your email in Profile first.'];
    }
    $doc = $check['doc'];
    $doc['subject'] = campaigns_cut('Test: ' . (string) $doc['subject'], 150);
    $r = email_send_doc($pdo, $doc, $person + ['email' => $to], [
        'lane'       => 'test',
        'purpose'    => 'account',
        'flow'       => 'campaign_test',
        'to_email'   => $to,
        'reply_to'   => $c['reply_to'] ?: null,
        'created_by' => $viewerId,
        'now'        => true,
    ]);
    return !empty($r['ok'])
        ? ['ok' => true, 'message' => 'Test sent to ' . email_mask($to) . '.']
        : ['ok' => false, 'message' => (string) ($r['message'] ?? 'The test could not be queued.')];
}

// ---------------------------------------------------------------------------
// Sending (cron/email_worker.php, every minute)
// ---------------------------------------------------------------------------

/**
 * Expand campaigns that are due, and close the ones whose emails have all
 * left. @return array{expanded:int, finished:int, failed:int}
 */
function campaigns_tick(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $out = ['expanded' => 0, 'finished' => 0, 'failed' => 0];
    if (!campaigns_module_ready($pdo)) {
        return $out;
    }
    $now ??= campaigns_now();
    $stmt = $pdo->prepare("SELECT id FROM email_campaigns
                            WHERE status = 'queued' OR (status = 'scheduled' AND scheduled_at <= ?)
                            ORDER BY COALESCE(scheduled_at, updated_at), id LIMIT 5");
    $stmt->execute([campaigns_sql_dt($now)]);
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        $r = campaign_expand($pdo, $id, $now);
        if ($r === true) {
            $out['expanded']++;
        } elseif ($r === false) {
            $out['failed']++;
        }
    }
    $out['finished'] = campaign_finish_sent($pdo, $now);
    return $out;
}

/**
 * Claim one due campaign and turn it into emails and texts, all in one
 * transaction. null = not due or another run has it; false = failed.
 */
function campaign_expand(PDO $pdo, int $id, ?DateTimeImmutable $now = null): ?bool
{
    $now ??= campaigns_now();
    $claim = $pdo->prepare("UPDATE email_campaigns SET status = 'sending', queued_at = ?
                             WHERE id = ? AND (status = 'queued' OR (status = 'scheduled' AND scheduled_at <= ?))");
    $claim->execute([campaigns_sql_dt($now), $id, campaigns_sql_dt($now)]);
    if ($claim->rowCount() !== 1) {
        return null;
    }
    $c = campaign_get($pdo, $id);
    try {
        if ($c === null || campaign_problems($pdo, $c) !== []) {
            throw new RuntimeException('not ready: ' . implode(' ', $c === null ? ['gone'] : campaign_problems($pdo, $c)));
        }
        $doc = email_doc_validate($c['doc'])['doc'];
        $people = campaign_people_rows($pdo, campaign_audience_ids($pdo, $c['audience']));
        $reach = campaign_reach($pdo, $people, !empty($c['sms_fallback']));
        $stamp = campaigns_sql_dt($now);
        $n = ['total' => 0, 'email' => 0, 'sms' => 0, 'none' => 0];
        $texts = [];

        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO email_campaign_people (campaign_id, user_id, channel, reason, outbox_id, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($people as $uid => $p) {
            $r = $reach[$uid];
            $outboxId = null;
            if ($r['channel'] === 'email') {
                $sent = email_send_doc($pdo, $doc, $p + ['email' => $r['email']], [
                    'lane'        => 'campaign',
                    'purpose'     => 'news',
                    'flow'        => 'campaign',
                    'to_email'    => $r['email'],
                    'reply_to'    => $c['reply_to'] ?: null,
                    'campaign_id' => $id,
                    'created_by'  => $c['sent_by'],
                    'track'       => true,
                    'track_opens' => !empty($c['track_opens']),
                ]);
                if (!empty($sent['ok'])) {
                    $outboxId = (int) $sent['id'];
                } else {
                    $r = ['channel' => 'none', 'reason' => 'failed'];
                }
            }
            $insert->execute([$id, $uid, $r['channel'], $r['reason'] ?? null, $outboxId, $stamp]);
            if ($r['channel'] === 'sms') {
                $texts[] = ['row' => (int) $pdo->lastInsertId(), 'person' => $p, 'phone' => $r['phone']];
            }
            $n['total']++;
            $n[$r['channel']]++;
        }

        $smsCampaignId = null;
        if ($texts !== []) {
            $pdo->prepare("INSERT INTO sms_campaigns (title, audience, event_id, filters_json, body_template, total, status, created_by, created_at)
                           VALUES (?, 'email_campaign', NULL, ?, ?, ?, 'queued', ?, ?)")
                ->execute([campaigns_cut('Email campaign · ' . $c['name'], 255), campaigns_json(['email_campaign_id' => $id]),
                           automation_sms_prepare((string) $c['sms_text'], []), count($texts), $c['sent_by'], $stamp]);
            $smsCampaignId = (int) $pdo->lastInsertId();
            $queue = $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES (?, ?, 'queued')");
            $link = $pdo->prepare('UPDATE email_campaign_people SET sms_queue_id = ? WHERE id = ?');
            foreach ($texts as $t) {
                $first = (string) ($t['person']['first_name'] ?? '');
                $last = (string) ($t['person']['last_name'] ?? '');
                $queue->execute([$smsCampaignId, campaigns_json([
                    'source' => 'user', 'source_id' => (int) $t['person']['id'], 'name' => trim($first . ' ' . $last),
                    'first_name' => $first, 'last_name' => $last, 'phone' => $t['phone'],
                ])]);
                $link->execute([(int) $pdo->lastInsertId(), $t['row']]);
            }
        }
        $pdo->prepare('UPDATE email_campaigns SET total = ?, emailed = ?, texted = ?, skipped = ?, sms_campaign_id = ? WHERE id = ?')
            ->execute([$n['total'], $n['email'], $n['sms'], $n['none'], $smsCampaignId, $id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[campaigns] campaign ' . $id . ' could not be sent: ' . $e->getMessage());
        $pdo->prepare("UPDATE email_campaigns SET status = 'failed', queued_at = NULL WHERE id = ? AND status = 'sending'")->execute([$id]);
        return false;
    }
}

/** Campaigns whose emails have all left (or failed) become 'sent'. */
function campaign_finish_sent(PDO $pdo, DateTimeImmutable $now): int
{
    $stmt = $pdo->prepare("UPDATE email_campaigns SET status = 'sent', finished_at = ?
                            WHERE status = 'sending'
                              AND NOT EXISTS (SELECT 1 FROM email_outbox o
                                               WHERE o.campaign_id = email_campaigns.id AND o.status IN ('queued', 'sending'))");
    $stmt->execute([campaigns_sql_dt($now)]);
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

/**
 * What happened: emails by outcome, opens (approximate), clicks per link,
 * unsubscribes, texts by status, and every person.
 */
function campaign_report(PDO $pdo, array $c): array
{
    $id = (int) $c['id'];
    $e = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'stopped' => 0, 'bounced' => 0, 'opened' => 0, 'clicked' => 0, 'unsubscribed' => 0];
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n, SUM(CASE WHEN bounced_at IS NULL THEN 0 ELSE 1 END) AS b,
                                  SUM(CASE WHEN opened_at IS NULL THEN 0 ELSE 1 END) AS o,
                                  SUM(CASE WHEN clicked_at IS NULL THEN 0 ELSE 1 END) AS k
                             FROM email_outbox WHERE campaign_id = ? GROUP BY status');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = match ((string) $r['status']) {
            'sent' => 'sent',
            'queued', 'sending' => 'waiting',
            'cancelled', 'suppressed' => 'stopped',
            default => 'failed',
        };
        $e[$key] += (int) $r['n'];
        $e['bounced'] += (int) $r['b'];
        $e['opened'] += (int) $r['o'];
        $e['clicked'] += (int) $r['k'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ev.outbox_id) FROM email_events ev JOIN email_outbox o ON o.id = ev.outbox_id
                            WHERE o.campaign_id = ? AND ev.kind = 'unsubscribe'");
    $stmt->execute([$id]);
    $e['unsubscribed'] = (int) $stmt->fetchColumn();

    // Clicks per link: the targets are the same for every copy (rendered from one document).
    $links = [];
    $stmt = $pdo->prepare('SELECT links_json FROM email_outbox WHERE campaign_id = ? AND links_json IS NOT NULL ORDER BY id LIMIT 1');
    $stmt->execute([$id]);
    $targets = json_decode((string) $stmt->fetchColumn(), true);
    if (is_array($targets)) {
        $count = $pdo->prepare("SELECT ev.link_index, COUNT(DISTINCT ev.outbox_id) AS n FROM email_events ev
                                  JOIN email_outbox o ON o.id = ev.outbox_id
                                 WHERE o.campaign_id = ? AND ev.kind = 'click' GROUP BY ev.link_index");
        $count->execute([$id]);
        $clicks = [];
        foreach ($count->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clicks[(int) $r['link_index']] = (int) $r['n'];
        }
        foreach (array_values($targets) as $i => $url) {
            $links[] = ['url' => (string) $url, 'people' => $clicks[$i] ?? 0];
        }
    }

    $sms = [];
    if (!empty($c['sms_campaign_id']) && automation_sms_available($pdo)) {
        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM sms_queue WHERE campaign_id = ? GROUP BY status');
        $stmt->execute([$c['sms_campaign_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sms[(string) $r['status']] = (int) $r['n'];
        }
    }

    $people = [];
    if (campaigns_table_exists($pdo, 'email_campaign_people')) {
        $smsJoin = automation_sms_available($pdo) ? 'LEFT JOIN sms_queue q ON q.id = p.sms_queue_id' : '';
        $smsCol = $smsJoin !== '' ? 'q.status AS sms_status' : 'NULL AS sms_status';
        $stmt = $pdo->prepare("SELECT p.user_id, p.channel, p.reason, u.first_name, u.last_name,
                                      o.status AS email_status, o.opened_at, o.clicked_at, o.bounced_at, {$smsCol}
                                 FROM email_campaign_people p
                                 LEFT JOIN users u ON u.id = p.user_id
                                 LEFT JOIN email_outbox o ON o.id = p.outbox_id
                                 {$smsJoin}
                                WHERE p.campaign_id = ? ORDER BY u.first_name, u.last_name, p.user_id LIMIT 5000");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $people[] = [
                'user_id' => (int) $r['user_id'],
                'name'    => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) ?: ('#' . $r['user_id']),
                'channel' => (string) $r['channel'],
                'result'  => campaign_person_result($r),
            ];
        }
    }
    return ['email' => $e, 'links' => $links, 'sms' => $sms, 'people' => $people];
}

/** One short word or two for a person's line in the report. */
function campaign_person_result(array $r): string
{
    if ($r['channel'] === 'none') {
        return CAMPAIGN_REASONS[(string) $r['reason']] ?? 'Not reached';
    }
    if ($r['channel'] === 'sms') {
        return match ((string) ($r['sms_status'] ?? '')) {
            'sent' => 'Texted',
            'failed' => 'Text failed',
            'cancelled' => 'Stopped',
            default => 'Text waiting',
        };
    }
    if ($r['bounced_at'] !== null) {
        return 'Bounced';
    }
    return match ((string) ($r['email_status'] ?? '')) {
        'sent' => $r['clicked_at'] !== null ? 'Clicked' : ($r['opened_at'] !== null ? 'Opened' : 'Sent'),
        'queued', 'sending' => 'Waiting',
        'cancelled' => 'Stopped',
        'suppressed' => 'Held back',
        default => 'Failed',
    };
}
