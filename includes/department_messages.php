<?php
// /includes/department_messages.php
//
// Departments > Messages: one message to the workers, by text, email and app
// alert at once (owner's answers, 20 Nov 2026).
//
//   * Who may send: SMS Studio users only (sms_user_can_send()). Nobody new can
//     spend the SMS wallet.
//   * Who it reaches: all workers, chosen departments (optionally with their
//     sub-units), workers absent at an event, or leaders only. "Workers" are the
//     live ministry year's Active roster rows, exactly the Attendance tab's
//     list; Relocated people are never included. Worked out on the SERVER when
//     the message goes out, never taken from the browser.
//   * Texts go through SMS Studio's own queue: one sms_campaigns row (audience
//     'departments') and one sms_queue row per number. Never sms_log. The
//     wallet, block list, duplicate guard and "HODLEKKI: " prefix all apply,
//     and the send shows in SMS Studio's Campaigns and History.
//   * Email follows the church-news rules (members get church messages unless
//     they unsubscribed; lane 'campaign'; the 07:00-20:00 window; send_by the
//     end of the send day, so a reminder is dropped rather than arriving after
//     its day). The address is email_news_address()'s (owner, 20 Nov 2026):
//     the confirmed personal email, else users.real_email, else users.email,
//     never @hodlc.com or @hodlekki.org, never a suppressed address. The email
//     worker accepts that fallback only for 'dept-msg-' flows, re-checked at
//     send time.
//   * The app alert is a system_notifications row per person, which the push
//     fan-out already turns into a push. Free.
//   * A scheduled message is expanded ONCE by dept_msg_tick() (run by the SMS
//     worker's cron and whenever the tab is open), claimed with a conditional
//     UPDATE.
//
// Merge fields are the three both channels understand: {{first_name}},
// {{last_name}}, {{full_name}}.
//
// Plain PDO and portable SQL, so tests/department_messages_test.php runs it on
// SQLite with the channels replaced by fakes.

require_once __DIR__ . '/department_attendance.php';

const DEPT_MSG_AUDIENCES   = ['all' => 'All workers', 'departments' => 'Departments', 'absent' => 'Absent', 'leaders' => 'Leaders'];
const DEPT_MSG_FIELDS      = ['first_name', 'last_name', 'full_name'];
const DEPT_MSG_MAX_CHARS   = 600;     // four text pages at most
const DEPT_MSG_MAX_DAYS    = 30;      // how far ahead a message may be scheduled
const DEPT_MSG_TICK_LIMIT  = 5;       // messages expanded per run
const DEPT_MSG_LINK        = '/modules/member_portal/';
const DEPT_MSG_TZ          = 'Africa/Lagos';

/** Has the migration run? */
function dept_msg_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM dept_messages LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** What is wrong with this message text, or null. */
function dept_msg_body_problem(string $body): ?string
{
    $body = trim($body);
    if ($body === '') {
        return 'Write the message.';
    }
    if (mb_strlen($body, 'UTF-8') > DEPT_MSG_MAX_CHARS) {
        return 'Keep the message under ' . DEPT_MSG_MAX_CHARS . ' characters.';
    }
    preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $body, $m);
    foreach ($m[1] as $field) {
        if (!in_array($field, DEPT_MSG_FIELDS, true)) {
            return 'Only {{first_name}}, {{last_name}} and {{full_name}} work here.';
        }
    }

    return null;
}

/**
 * A clean audience spec, or an error.
 *
 * @param array $in audience, department_ids (list), include_subunits, event_id
 * @return array{ok:bool, spec?:array, message?:string}
 */
function dept_msg_spec(array $in): array
{
    $audience = (string) ($in['audience'] ?? '');
    if (!isset(DEPT_MSG_AUDIENCES[$audience])) {
        return ['ok' => false, 'message' => 'Choose who gets it.'];
    }
    $ids = $in['department_ids'] ?? [];
    if (is_string($ids)) {
        $ids = json_decode($ids, true) ?: explode(',', $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), static fn ($i) => $i > 0)));
    $eventId = (int) ($in['event_id'] ?? 0);
    if ($audience === 'departments' && $ids === []) {
        return ['ok' => false, 'message' => 'Tick at least one department.'];
    }
    if ($audience === 'absent' && $eventId < 1) {
        return ['ok' => false, 'message' => 'Choose the event.'];
    }

    return ['ok' => true, 'spec' => [
        'audience' => $audience,
        'department_ids' => $audience === 'departments' ? $ids : [],
        'include_subunits' => !empty($in['include_subunits']) && $in['include_subunits'] !== '0',
        'event_id' => $audience === 'absent' ? $eventId : null,
    ]];
}

/**
 * The people a spec reaches right now: one entry per person, A to Z.
 *
 * @return array<int, array{user_id:int, first_name:string, last_name:string, phone:string, phone_bad:bool, departments:array}>
 */
function dept_msg_people(PDO $pdo, array $spec, int $yearId, array $leaderRoles = []): array
{
    $phoneStatus = false;
    try {
        $pdo->query('SELECT phone_status FROM users LIMIT 0');
        $phoneStatus = true;
    } catch (Throwable $e) {
        // older installs have no phone_status
    }
    $st = $pdo->prepare(
        'SELECT ud.user_id, ud.department_id, ud.role_in_dept, u.first_name, u.last_name, u.phone,
                d.name AS dept_name, d.parent_id' . ($phoneStatus ? ', u.phone_status' : '') . "
           FROM user_departments ud
           JOIN users u ON u.id = ud.user_id
           JOIN departments d ON d.id = ud.department_id
          WHERE ud.ministry_year_id = ? AND ud.roster_status = 'Active'
            AND COALESCE(u.attendance_status, '') <> 'Relocated'"
    );
    $st->execute([$yearId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $absentOnly = null;
    if ($spec['audience'] === 'absent') {
        $absentOnly = [];
        foreach (dept_att_roll($pdo, (int) $spec['event_id'], $yearId, $leaderRoles)['people'] as $p) {
            if (!$p['present']) {
                $absentOnly[$p['user_id']] = true;
            }
        }
    }

    $people = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $did = (int) $r['department_id'];
        $parent = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        if ($spec['audience'] === 'departments') {
            $hit = in_array($did, $spec['department_ids'], true)
                || ($spec['include_subunits'] && $parent !== null && in_array($parent, $spec['department_ids'], true));
            if (!$hit) {
                continue;
            }
        } elseif ($spec['audience'] === 'leaders') {
            if (!in_array((string) $r['role_in_dept'], $leaderRoles, true)) {
                continue;
            }
        } elseif ($absentOnly !== null && !isset($absentOnly[$uid])) {
            continue;
        }
        if (!isset($people[$uid])) {
            $people[$uid] = [
                'user_id' => $uid,
                'first_name' => trim((string) $r['first_name']),
                'last_name' => trim((string) $r['last_name']),
                'phone' => trim((string) ($r['phone'] ?? '')),
                'phone_bad' => $phoneStatus && ($r['phone_status'] ?? '') === 'invalid',
                'departments' => [],
            ];
        }
        $people[$uid]['departments'][] = (string) $r['dept_name'];
    }
    $people = array_values($people);
    usort($people, static fn ($a, $b) => strcasecmp($a['first_name'] . ' ' . $a['last_name'], $b['first_name'] . ' ' . $b['last_name']));

    return $people;
}

/**
 * SMS Studio recipients for these people: one per valid Nigerian number.
 *
 * @return array{data:array, no_phone:int, invalid:int, duplicates:int}
 */
function dept_msg_sms_recipients(array $people): array
{
    $out = [];
    $seen = [];
    $noPhone = $invalid = $dupes = 0;
    foreach ($people as $p) {
        if ($p['phone'] === '') {
            $noPhone++;
            continue;
        }
        $phone = $p['phone_bad'] ? null : sms_normalize_phone($p['phone']);
        if ($phone === null) {
            $invalid++;
            continue;
        }
        if (isset($seen[$phone])) {
            $dupes++;
            continue;
        }
        $seen[$phone] = true;
        $first = sms_clean_name($p['first_name']);
        $last = sms_clean_name($p['last_name']);
        $out[] = [
            'source' => 'user', 'source_id' => $p['user_id'], 'phone' => $phone,
            'name' => trim($first . ' ' . $last), 'first_name' => $first, 'last_name' => $last,
            'guest_name' => '', 'email' => '', 'event_title' => '', 'blessing_ref' => '',
        ];
    }

    return ['data' => $out, 'no_phone' => $noPhone, 'invalid' => $invalid, 'duplicates' => $dupes];
}

/**
 * Who can be emailed, one email per address: [ [person + email], ... ] and why
 * the rest cannot. The address is email_news_address()'s: the confirmed
 * personal email, else users.real_email, else users.email, never @hodlc.com,
 * never a suppressed address, never someone who unsubscribed (owner's decision,
 * 20 Nov 2026).
 *
 * @return array{data:array, skipped:array<string,int>, sources:array<string,int>}
 */
function dept_msg_email_targets(PDO $pdo, array $people): array
{
    $out = [];
    $skipped = [];
    $sources = [];
    if (!function_exists('email_news_address')) {
        return ['data' => [], 'skipped' => $people ? ['not_ready' => count($people)] : [], 'sources' => []];
    }
    $seen = [];
    foreach ($people as $p) {
        $addr = email_news_address($pdo, $p['user_id']);
        if (empty($addr['ok']) || empty($addr['email'])) {
            $reason = (string) ($addr['reason'] ?? 'no_email');
            $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
            continue;
        }
        if (isset($seen[$addr['email']])) {
            $skipped['shared'] = ($skipped['shared'] ?? 0) + 1;
            continue;
        }
        $seen[$addr['email']] = true;
        $sources[$addr['source']] = ($sources[$addr['source']] ?? 0) + 1;
        $out[] = ['id' => $p['user_id'], 'first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'email' => $addr['email']];
    }

    return ['data' => $out, 'skipped' => $skipped, 'sources' => $sources];
}

/** The message with one person's names filled in (for the app alert). */
function dept_msg_fill(string $body, string $first, string $last): string
{
    $first = trim($first);
    $last = trim($last);

    return trim((string) preg_replace_callback('/\{\{\s*(first_name|last_name|full_name)\s*\}\}/', static function ($m) use ($first, $last) {
        return ['first_name' => $first, 'last_name' => $last, 'full_name' => trim($first . ' ' . $last)][$m[1]];
    }, $body));
}

/** The email: the subject as a heading, the message as paragraphs, the footer. */
function dept_msg_email_doc(string $subject, string $body): array
{
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R/u', trim($body)) ?: []), static fn ($l) => $l !== ''));
    $subject = mb_substr(trim($subject), 0, 150, 'UTF-8');

    return [
        'v' => 1,
        'subject' => $subject,
        'preheader' => mb_substr(dept_msg_fill($paragraphs[0] ?? '', 'friend', ''), 0, 140, 'UTF-8'),
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => mb_substr($subject, 0, 120, 'UTF-8')],
            email_doc_paragraphs(...($paragraphs ?: [''])),
            ['type' => 'footer'],
        ],
    ];
}

/** End of the send day in Lagos: an email that cannot leave by then is dropped. */
function dept_msg_send_by(string $sendAt): DateTimeImmutable
{
    $tz = new DateTimeZone(DEPT_MSG_TZ);

    return (new DateTimeImmutable($sendAt, $tz))->setTime(23, 59, 59);
}

/**
 * What a message would reach right now, for the composer's summary. No wallet
 * call (that is a network request): the cost is units x the Studio's price.
 */
function dept_msg_preview(PDO $pdo, array $spec, string $body, int $yearId, array $leaderRoles = []): array
{
    $people = dept_msg_people($pdo, $spec, $yearId, $leaderRoles);
    $sms = dept_msg_sms_recipients($people);
    $email = dept_msg_email_targets($pdo, $people);

    $units = 0;
    $maxPages = 0;
    $price = null;
    if (trim($body) !== '' && function_exists('sms_load_settings')) {
        try {
            $settings = sms_load_settings($pdo);
        } catch (Throwable $e) {
            $settings = [];   // SMS Studio not set up: count pages with the default prefix
        }
        $price = sms_unit_price($settings);
        foreach ($sms['data'] as $rec) {
            $pages = sms_segments(sms_sign_body(sms_render($body, $rec), $settings))['pages'];
            $units += $pages;
            $maxPages = max($maxPages, $pages);
        }
    }

    return [
        'people' => count($people),
        'sms' => ['count' => count($sms['data']), 'no_phone' => $sms['no_phone'], 'invalid' => $sms['invalid'],
                  'duplicates' => $sms['duplicates'], 'units' => $units, 'pages' => $maxPages,
                  'cost' => $price === null ? null : round($units * $price, 2)],
        'email' => ['count' => count($email['data']), 'skipped' => $email['skipped'], 'sources' => $email['sources']],
        'push' => ['count' => count($people)],
        'sample' => array_map(static fn ($p) => trim($p['first_name'] . ' ' . $p['last_name']), array_slice($people, 0, 5)),
    ];
}

/**
 * Store a message. $sendAt null = now (the row is born 'sending', claimed by
 * this request); else it waits as 'scheduled'.
 *
 * @return array{ok:bool, id?:int, row?:array, message?:string}
 */
function dept_msg_create(PDO $pdo, array $spec, string $subject, string $body, ?string $sendAt, int $userId, DateTimeImmutable $now): array
{
    $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
    if ($subject === '') {
        return ['ok' => false, 'message' => 'Add a subject.'];
    }
    if (mb_strlen($subject, 'UTF-8') > 150) {
        return ['ok' => false, 'message' => 'Keep the subject under 150 characters.'];
    }
    if (($problem = dept_msg_body_problem($body)) !== null) {
        return ['ok' => false, 'message' => $problem];
    }
    $nowSql = $now->format('Y-m-d H:i:s');
    if ($sendAt !== null) {
        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $sendAt, $now->getTimezone())
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sendAt, $now->getTimezone());
        if (!$at) {
            return ['ok' => false, 'message' => 'Pick a valid time.'];
        }
        if ($at < $now->modify('+5 minutes')) {
            return ['ok' => false, 'message' => 'Pick a time at least 5 minutes ahead.'];
        }
        if ($at > $now->modify('+' . DEPT_MSG_MAX_DAYS . ' days')) {
            return ['ok' => false, 'message' => 'Pick a time within ' . DEPT_MSG_MAX_DAYS . ' days.'];
        }
        $sendAtSql = $at->format('Y-m-d H:i:s');
    } else {
        $sendAtSql = $nowSql;
    }

    $pdo->prepare(
        'INSERT INTO dept_messages (subject, body, audience, department_ids, include_subunits, event_id, send_at, status, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $subject, trim($body), $spec['audience'],
        $spec['department_ids'] ? json_encode($spec['department_ids']) : null,
        $spec['include_subunits'] ? 1 : 0, $spec['event_id'],
        $sendAtSql, $sendAt === null ? 'sending' : 'scheduled', $userId, $nowSql,
    ]);
    $id = (int) $pdo->lastInsertId();

    return ['ok' => true, 'id' => $id, 'row' => dept_msg_get($pdo, $id)];
}

function dept_msg_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM dept_messages WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** The spec stored on a row. */
function dept_msg_row_spec(array $row): array
{
    $ids = $row['department_ids'] ? (json_decode((string) $row['department_ids'], true) ?: []) : [];

    return [
        'audience' => (string) $row['audience'],
        'department_ids' => array_values(array_map('intval', (array) $ids)),
        'include_subunits' => (int) $row['include_subunits'] === 1,
        'event_id' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
    ];
}

/** Claim a scheduled row for sending; false when someone else already did. */
function dept_msg_claim(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("UPDATE dept_messages SET status = 'sending' WHERE id = ? AND status = 'scheduled'");
    $st->execute([$id]);

    return $st->rowCount() === 1;
}

/** Stop a scheduled message before it goes. */
function dept_msg_cancel(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("UPDATE dept_messages SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'");
    $st->execute([$id]);

    return $st->rowCount() === 1;
}

/* --------------------------------------------------------------- channels */

/**
 * Queue the texts in SMS Studio: one campaign, one queue row per number.
 *
 * @return array{ok:bool, campaign_id?:int, queued?:int, units?:int, message?:string}
 */
function dept_msg_channel_sms(PDO $pdo, array $row, array $recipients): array
{
    if (!$recipients) {
        return ['ok' => true, 'campaign_id' => null, 'queued' => 0, 'units' => 0];
    }
    $failed = [];
    $settings = sms_load_settings($pdo, $failed);
    if (empty($settings['api_token'])) {
        return ['ok' => false, 'message' => $failed ? 'SMS credentials cannot be read. Check SMS Studio settings.' : 'SMS is not set up. Check SMS Studio settings.'];
    }
    $units = 0;
    foreach ($recipients as $rec) {
        $units += sms_segments(sms_sign_body(sms_render((string) $row['body'], $rec), $settings))['pages'];
    }
    $wallet = sms_wallet_check($settings, $units);
    if (!$wallet['ok']) {
        return ['ok' => false, 'message' => (string) $wallet['message']];
    }

    $filters = json_encode(['dept_message_id' => (int) $row['id']] + dept_msg_row_spec($row));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO sms_campaigns (title, audience, event_id, filters_json, body_template, total, status, created_by)
                       VALUES (?, 'departments', ?, ?, ?, ?, 'queued', ?)")
            ->execute([sms_cut('Workers: ' . $row['subject'], 200), $row['event_id'], $filters, $row['body'], count($recipients), $row['created_by']]);
        $campaignId = (int) $pdo->lastInsertId();
        foreach (array_chunk($recipients, 200) as $chunk) {
            $vals = [];
            $args = [];
            foreach ($chunk as $rec) {
                $vals[] = "(?, ?, 'queued')";
                $args[] = $campaignId;
                $args[] = json_encode($rec, JSON_UNESCAPED_UNICODE);
            }
            $pdo->prepare('INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES ' . implode(',', $vals))->execute($args);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'campaign_id' => $campaignId, 'queued' => count($recipients), 'units' => $units];
}

/** Queue the emails (church news, confirmed address, dropped after the day). */
function dept_msg_channel_email(PDO $pdo, array $row, array $targets): array
{
    if (!$targets || !function_exists('email_send_doc')) {
        return ['ok' => true, 'queued' => 0];
    }
    $doc = dept_msg_email_doc((string) $row['subject'], (string) $row['body']);
    $sendBy = dept_msg_send_by((string) $row['send_at']);
    $queued = 0;
    $failed = 0;
    foreach ($targets as $t) {
        try {
            $r = email_send_doc($pdo, $doc, $t, [
                'lane' => 'campaign', 'purpose' => 'news', 'flow' => 'dept-msg-' . (int) $row['id'],
                'to_email' => $t['email'], 'send_by' => $sendBy,
                'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            ]);
            !empty($r['ok']) ? $queued++ : $failed++;
        } catch (Throwable $e) {
            $failed++;
            error_log('[dept_messages] email to user ' . $t['id'] . ' not queued: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'queued' => $queued, 'failed' => $failed];
}

/** The app alert: a bell row per person, which the push fan-out sends. */
function dept_msg_channel_push(PDO $pdo, array $row, array $people): array
{
    $st = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($people as $p) {
        $st->execute([$p['user_id'], mb_substr((string) $row['subject'], 0, 150, 'UTF-8'),
            mb_substr(dept_msg_fill((string) $row['body'], $p['first_name'], $p['last_name']), 0, 1000, 'UTF-8'), DEPT_MSG_LINK]);
        $n++;
    }

    return ['ok' => true, 'queued' => $n];
}

/** The real channels; tests pass fakes. */
function dept_msg_default_channels(): array
{
    return ['sms' => 'dept_msg_channel_sms', 'email' => 'dept_msg_channel_email', 'push' => 'dept_msg_channel_push'];
}

/**
 * Send a claimed ('sending') message to everyone it reaches now.
 * $strict (a send-now): if the texts cannot go (wallet, settings), nothing goes
 * and the row is 'failed'. Scheduled: email and alerts still go and the row
 * says why the texts did not.
 *
 * @return array{ok:bool, counts:array, message?:string}
 */
function dept_msg_expand(PDO $pdo, array $row, int $yearId, array $leaderRoles = [], array $channels = [], bool $strict = false): array
{
    $channels += dept_msg_default_channels();
    $done = static function (string $status, array $counts, ?string $error, ?int $campaignId) use ($pdo, $row): void {
        $pdo->prepare('UPDATE dept_messages SET status = ?, counts_json = ?, error_message = ?, sms_campaign_id = ?, sent_at = ? WHERE id = ?')
            ->execute([$status, json_encode($counts), $error !== null ? mb_substr($error, 0, 255, 'UTF-8') : null, $campaignId,
                date('Y-m-d H:i:s'), (int) $row['id']]);
    };

    try {
        $people = dept_msg_people($pdo, dept_msg_row_spec($row), $yearId, $leaderRoles);
        $sms = dept_msg_sms_recipients($people);
        $email = dept_msg_email_targets($pdo, $people);
        $counts = ['people' => count($people),
            'sms' => ['queued' => 0, 'no_phone' => $sms['no_phone'], 'invalid' => $sms['invalid'], 'duplicates' => $sms['duplicates']],
            'email' => ['queued' => 0, 'skipped' => $email['skipped']],
            'push' => ['queued' => 0]];

        $smsResult = ($channels['sms'])($pdo, $row, $sms['data']);
        $error = null;
        if (empty($smsResult['ok'])) {
            $error = 'Texts not sent: ' . ($smsResult['message'] ?? 'unknown error');
            if ($strict) {
                $done('failed', $counts, $error, null);
                return ['ok' => false, 'counts' => $counts, 'message' => (string) ($smsResult['message'] ?? 'Texts could not be queued.')];
            }
        } else {
            $counts['sms']['queued'] = (int) ($smsResult['queued'] ?? 0);
            $counts['sms']['units'] = (int) ($smsResult['units'] ?? 0);
        }
        $emailResult = ($channels['email'])($pdo, $row, $email['data']);
        $counts['email']['queued'] = (int) ($emailResult['queued'] ?? 0);
        $pushResult = ($channels['push'])($pdo, $row, $people);
        $counts['push']['queued'] = (int) ($pushResult['queued'] ?? 0);

        $done('sent', $counts, $error, isset($smsResult['campaign_id']) ? (int) $smsResult['campaign_id'] : null);

        return ['ok' => true, 'counts' => $counts, 'message' => $error];
    } catch (Throwable $e) {
        error_log('[dept_messages] message ' . $row['id'] . ' failed: ' . $e->getMessage());
        $done('failed', $counts ?? [], 'Not sent: ' . $e->getMessage(), null);
        return ['ok' => false, 'counts' => $counts ?? [], 'message' => 'It did not go out. Nothing was sent.'];
    }
}

/**
 * Send every scheduled message that is due. Safe to run from several places
 * at once: each row is claimed before it is sent.
 *
 * @return int messages sent
 */
function dept_msg_tick(PDO $pdo, ?string $now = null, array $channels = [], ?int $yearId = null, ?array $leaderRoles = null): int
{
    if (!dept_msg_ready($pdo)) {
        return 0;
    }
    $now = $now ?? date('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT * FROM dept_messages WHERE status = 'scheduled' AND send_at <= ? ORDER BY send_at, id LIMIT " . DEPT_MSG_TICK_LIMIT);
    $st->execute([$now]);
    $due = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$due) {
        return 0;
    }
    if ($yearId === null) {
        $year = function_exists('dept_active_year') ? dept_active_year($pdo) : null;
        if (!$year) {
            return 0;
        }
        $yearId = (int) $year['id'];
    }
    $leaderRoles = $leaderRoles ?? (function_exists('dept_leader_roles') ? dept_leader_roles() : []);

    $sent = 0;
    foreach ($due as $row) {
        if (!dept_msg_claim($pdo, (int) $row['id'])) {
            continue;
        }
        $result = dept_msg_expand($pdo, $row, $yearId, $leaderRoles, $channels, false);
        $sent++;
        // Tell the sender how it went (their bell, so a push too).
        if ($row['created_by'] !== null) {
            $c = $result['counts'];
            $line = $result['ok']
                ? sprintf('"%s" went out: %d texts, %d emails, %d alerts.', $row['subject'], $c['sms']['queued'] ?? 0, $c['email']['queued'] ?? 0, $c['push']['queued'] ?? 0)
                    . (!empty($result['message']) ? ' ' . $result['message'] : '')
                : sprintf('"%s" did not go out. Open Departments > Messages.', $row['subject']);
            try {
                $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)')
                    ->execute([(int) $row['created_by'], 'Workers message', mb_substr($line, 0, 1000, 'UTF-8'), '/modules/departments/#tab=messages']);
            } catch (Throwable $e) {
                error_log('[dept_messages] could not notify the sender: ' . $e->getMessage());
            }
        }
    }

    return $sent;
}

/**
 * Recent messages with how far each channel has got.
 *
 * @return array<int, array>
 */
function dept_msg_history(PDO $pdo, int $limit = 30): array
{
    $rows = $pdo->query(
        'SELECT m.*, u.first_name AS by_first, u.last_name AS by_last
           FROM dept_messages m LEFT JOIN users u ON u.id = m.created_by
          ORDER BY m.created_at DESC, m.id DESC LIMIT ' . max(1, min(100, $limit))
    )->fetchAll(PDO::FETCH_ASSOC);

    $outbox = false;
    try {
        $pdo->query('SELECT flow FROM email_outbox LIMIT 0');
        $outbox = true;
    } catch (Throwable $e) {
        // email not installed
    }
    $sms = $pdo->prepare('SELECT total, sent_count, failed_count, status FROM sms_campaigns WHERE id = ?');
    $mail = $outbox ? $pdo->prepare('SELECT status, COUNT(*) AS n FROM email_outbox WHERE flow = ? GROUP BY status') : null;

    $out = [];
    foreach ($rows as $r) {
        $item = [
            'id' => (int) $r['id'], 'subject' => (string) $r['subject'], 'body' => (string) $r['body'],
            'audience' => (string) $r['audience'], 'audience_label' => DEPT_MSG_AUDIENCES[$r['audience']] ?? $r['audience'],
            'status' => (string) $r['status'], 'send_at' => (string) $r['send_at'], 'sent_at' => $r['sent_at'],
            'by' => trim(((string) $r['by_first']) . ' ' . ((string) $r['by_last'])),
            'counts' => $r['counts_json'] ? (json_decode((string) $r['counts_json'], true) ?: []) : [],
            'error' => $r['error_message'], 'sms' => null, 'email' => null,
        ];
        if ($r['sms_campaign_id'] !== null) {
            try {
                $sms->execute([(int) $r['sms_campaign_id']]);
                $c = $sms->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $item['sms'] = ['total' => (int) $c['total'], 'sent' => (int) $c['sent_count'], 'failed' => (int) $c['failed_count'], 'status' => (string) $c['status']];
                }
            } catch (Throwable $e) {
                // SMS tables missing: leave it out
            }
        }
        if ($mail !== null && $r['status'] === 'sent') {
            $mail->execute(['dept-msg-' . (int) $r['id']]);
            $by = [];
            foreach ($mail->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $by[(string) $m['status']] = (int) $m['n'];
            }
            $item['email'] = $by;
        }
        $out[] = $item;
    }

    return $out;
}
