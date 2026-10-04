<?php
// /includes/special_events/messages.php
//
// Outgoing SMS (guide §16). The module never talks to a gateway: it writes an
// `sms_campaigns` row plus one `sms_queue` row per recipient and lets SMS
// Studio's worker do the rest, so the spam guard, suppression list, logging
// and delivery reports all keep working. `sms_log` is never written here
// (AGENTS.md).
//
// PR2 ships the two single-recipient kinds — `waitlist_promotion` and
// `link_on_demand`. The scheduled ones (reminders, thank-you) arrive with the
// PR4 cron and reuse se_messages_run() unchanged.

/** Human labels for the campaign title. */
const SE_MESSAGE_KIND_LABELS = [
    'reminder_1'         => 'Reminder (day before)',
    'reminder_2'         => 'Reminder (today)',
    'thank_you'          => 'Thank you',
    'waitlist_promotion' => 'Waitlist promotion',
    'link_on_demand'     => 'Link on demand',
    'adhoc'              => 'Message',
];

/** True when SMS Studio's tables are present. Everything degrades on false. */
function se_sms_available(PDO $pdo): bool
{
    static $ok = null;

    return $ok ??= se_table_exists($pdo, 'sms_campaigns') && se_table_exists($pdo, 'sms_queue');
}

/**
 * Worker heartbeat younger than three minutes (§16.1).
 *
 * A stale worker never stops a run — the message still goes in the queue and
 * leaves when the cron recovers — but the Studio shows it in red.
 */
function se_sms_worker_fresh(PDO $pdo): bool
{
    if (!function_exists('sms_health')) {
        return false;
    }

    try {
        $health = sms_health($pdo);
        $age    = $health['cron_age_sec'] ?? null;

        return $age !== null && (int) $age < 180;
    } catch (Throwable $e) {
        error_log('SE messages/health: ' . $e->getMessage());
        return false;
    }
}

// --------------------------------------------------------------------------
// Templates (§16.3)
// --------------------------------------------------------------------------

/**
 * Substitute the EVENT-level merge fields, so what we store on the campaign
 * is already specific to this event. Only {{first_name}} and {{link}} are
 * left for sms_render() in the worker.
 */
function se_message_render_event(string $template, array $event, array $days, array $settings = []): string
{
    $first = $days[0] ?? null;
    $start = se_parse_datetime($first['starts_at'] ?? ($event['starts_at'] ?? null));

    $title = trim((string) $event['title']);
    if (trim((string) ($event['edition_label'] ?? '')) !== '') {
        $title .= ' ' . trim((string) $event['edition_label']);
    }

    $map = [
        '{{event_title}}' => $title,
        '{{date}}'        => $start !== null ? $start->format('D j M') : '',
        '{{time}}'        => $start !== null ? ltrim($start->format('g:i A'), '0') : '',
        '{{venue}}'       => trim((string) ($event['venue_name'] ?? '')),
        '{{map}}'         => trim((string) ($event['venue_map_url'] ?? '')),
    ];

    return trim(strtr($template, $map));
}

/** The configured template for a kind, falling back to the Appendix E default. */
function se_message_template(array $settings, string $kind): string
{
    return (string) ($settings['messages'][$kind]['template'] ?? '');
}

/**
 * Preview one template for a sample person: the rendered body, the encoding
 * and the billable pages. Used by the Studio editor and by the tests.
 *
 * @return array{body:string, encoding:string, chars:int, pages:int}
 */
function se_message_preview(string $template, array $event, array $days, array $sample = [], array $settings = []): array
{
    $body = se_message_render_event($template, $event, $days, $settings);

    if (function_exists('sms_render')) {
        $body = sms_render($body, $sample + [
            'first_name' => 'Ada',
            'last_name'  => 'Okonkwo',
            'link'       => se_event_url((string) $event['slug'], 'me/' . str_repeat('x', SE_MANAGE_TOKEN_LENGTH)),
        ]);
    }

    $seg = function_exists('sms_segments')
        ? sms_segments($body)
        : ['encoding' => 'GSM-7', 'chars' => strlen($body), 'pages' => 1];

    return [
        'body'     => $body,
        'encoding' => (string) $seg['encoding'],
        'chars'    => (int) $seg['chars'],
        'pages'    => (int) $seg['pages'],
    ];
}

// --------------------------------------------------------------------------
// Runs (§16.4) — the idempotency ledger
// --------------------------------------------------------------------------

/**
 * Claim a run key. Returns the se_message_runs id, or null when this run has
 * already happened — the UNIQUE(event_id, run_key) index is the guard, so two
 * cron ticks racing each other can never double-send.
 */
function se_message_run_claim(PDO $pdo, int $eventId, string $kind, string $runKey, ?DateTimeImmutable $scheduledFor = null, ?int $createdBy = null): ?int
{
    if (!se_table_exists($pdo, 'se_message_runs')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO se_message_runs (event_id, kind, run_key, scheduled_for, status, created_by)
             VALUES (?, ?, ?, ?, 'scheduled', ?)"
        );
        $stmt->execute([
            $eventId,
            $kind,
            $runKey,
            $scheduledFor !== null ? se_sql_datetime($scheduledFor) : null,
            $createdBy,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (se_is_duplicate_key($e)) {
            return null;   // already claimed
        }
        throw $e;
    }
}

/** Close a claimed run. */
function se_message_run_finish(PDO $pdo, ?int $runId, string $status, array $extra = []): void
{
    if ($runId === null || !se_table_exists($pdo, 'se_message_runs')) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE se_message_runs
                SET status = ?, sms_campaign_id = ?, recipients = ?, est_units = ?, detail = ?
              WHERE id = ?"
        );
        $stmt->execute([
            $status,
            $extra['sms_campaign_id'] ?? null,
            (int) ($extra['recipients'] ?? 0),
            (int) ($extra['est_units'] ?? 0),
            se_line($extra['detail'] ?? null, 255) ?: null,
            $runId,
        ]);
    } catch (Throwable $e) {
        error_log('SE messages/run_finish: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// Enqueueing
// --------------------------------------------------------------------------

/**
 * Create one campaign and its queue rows.
 *
 * $recipients is a list of registration rows; each gets its own fresh manage
 * token (§16.3), so revoking one message's link never breaks another's.
 *
 * @return array{campaign_id:?int, recipients:int, est_units:int, skipped:int}
 */
function se_messages_send(PDO $pdo, array $event, array $days, string $kind, string $template, array $recipients, ?int $createdBy = null, string $linkSuffix = ''): array
{
    $out = ['campaign_id' => null, 'recipients' => 0, 'est_units' => 0, 'skipped' => 0];

    if (!se_sms_available($pdo) || !$recipients) {
        $out['skipped'] = count($recipients);
        return $out;
    }

    $body = se_message_render_event($template, $event, $days);
    if (trim($body) === '') {
        $out['skipped'] = count($recipients);
        return $out;
    }

    $title = trim((string) $event['title']) . ' · ' . (SE_MESSAGE_KIND_LABELS[$kind] ?? $kind);

    $pdo->prepare(
        "INSERT INTO sms_campaigns (title, audience, event_id, filters_json, body_template, total, status, created_by)
         VALUES (?, 'special_event', NULL, ?, ?, 0, 'queued', ?)"
    )->execute([
        mb_substr($title, 0, 255, 'UTF-8'),
        se_json_encode(['se_event_id' => (int) $event['id'], 'kind' => $kind]),
        $body,
        $createdBy,
    ]);
    $campaignId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES (?, ?, 'queued')");
    $sent   = 0;
    $units  = 0;

    foreach ($recipients as $reg) {
        $phone = se_message_recipient_phone($pdo, $reg);
        if ($phone === null) {
            $out['skipped']++;
            continue;
        }

        $link = se_manage_url($pdo, $event, $days, (int) $reg['id']) . $linkSuffix;

        $recipient = [
            'source'      => 'se_registration',
            'source_id'   => (int) $reg['id'],
            'name'        => trim((string) $reg['first_name'] . ' ' . (string) ($reg['last_name'] ?? '')),
            'first_name'  => (string) $reg['first_name'],
            'last_name'   => (string) ($reg['last_name'] ?? ''),
            'phone'       => $phone,
            'event_title' => (string) $event['title'],
            'link'        => $link,
        ];

        $insert->execute([$campaignId, se_json_encode($recipient)]);
        $sent++;

        if (function_exists('sms_render') && function_exists('sms_segments')) {
            $units += (int) sms_segments(sms_render($body, $recipient))['pages'];
        }
    }

    $pdo->prepare("UPDATE sms_campaigns SET total = ? WHERE id = ?")->execute([$sent, $campaignId]);

    $out['campaign_id'] = $campaignId;
    $out['recipients']  = $sent;
    $out['est_units']   = $units;

    return $out;
}

/**
 * The number to text, or null when this person must not be texted: opted out,
 * erased, or not reachable on the Nigerian route.
 */
function se_message_recipient_phone(PDO $pdo, array $registration): ?string
{
    $stmt = $pdo->prepare(
        "SELECT phone_e164, sms_capable, opted_out_at, erased_at FROM se_contacts WHERE id = ? LIMIT 1"
    );
    $stmt->execute([(int) $registration['contact_id']]);
    $contact = $stmt->fetch();

    if (!$contact || $contact['opted_out_at'] !== null || $contact['erased_at'] !== null) {
        return null;
    }
    if (!se_bool($contact['sms_capable'])) {
        return null;
    }

    return (string) $contact['phone_e164'];
}

// --------------------------------------------------------------------------
// The two PR2 kinds
// --------------------------------------------------------------------------

/**
 * "A seat opened — you're in." One campaign per promoted person, keyed
 * `waitlist:<registration_id>:<n>` so a double promotion in the same second
 * can only text them once (§16.5).
 *
 * @param list<int> $registrationIds
 */
function se_messages_enqueue_waitlist_promotion(PDO $pdo, array $event, array $days, array $registrationIds, ?int $createdBy = null): int
{
    if (!$registrationIds || !se_sms_available($pdo)) {
        return 0;
    }

    $settings = se_event_settings($event);
    $template = se_message_template($settings, 'waitlist_promotion');
    if (trim($template) === '') {
        return 0;
    }

    $sent = 0;

    foreach ($registrationIds as $id) {
        $reg = se_registration_by_id($pdo, (int) $id, (int) $event['id']);
        if (!$reg) {
            continue;
        }

        // The counter makes the key unique across a promote → cancel →
        // re-promote cycle, which is legitimate and must text them again.
        $n      = se_message_run_sequence($pdo, (int) $event['id'], 'waitlist:' . (int) $id . ':');
        $runKey = 'waitlist:' . (int) $id . ':' . $n;

        $runId = se_message_run_claim($pdo, (int) $event['id'], 'waitlist_promotion', $runKey, null, $createdBy);
        if ($runId === null) {
            continue;
        }

        $result = se_messages_send($pdo, $event, $days, 'waitlist_promotion', $template, [$reg], $createdBy);

        se_message_run_finish($pdo, $runId, $result['recipients'] > 0 ? 'queued' : 'skipped', $result);
        se_audit($pdo, (int) $event['id'], 'messages_run', [
            'kind'            => 'waitlist_promotion',
            'run_key'         => $runKey,
            'registration_id' => (int) $id,
            'recipients'      => $result['recipients'],
        ], 'registration', (int) $id, $createdBy);

        $sent += $result['recipients'];
    }

    return $sent;
}

/** How many runs already exist with this key prefix, plus one. */
function se_message_run_sequence(PDO $pdo, int $eventId, string $prefix): int
{
    if (!se_table_exists($pdo, 'se_message_runs')) {
        return 1;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_message_runs WHERE event_id = ? AND run_key LIKE ?");
    $stmt->execute([$eventId, str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%']);

    return 1 + (int) $stmt->fetchColumn();
}

/**
 * "Here is your link." Keyed `link:<registration_id>:<YYYYMMDDHH>`, which is
 * the per-hour throttle from §12.1 expressed as data rather than as a timer.
 *
 * Returns true when something was queued.
 */
function se_messages_enqueue_link(PDO $pdo, array $event, array $days, array $registration, ?int $createdBy = null): bool
{
    if (!se_sms_available($pdo)) {
        return false;
    }

    $settings = se_event_settings($event);
    if (!se_bool($settings['registration']['link_on_demand_enabled'] ?? true)) {
        return false;
    }

    $template = se_message_template($settings, 'link_on_demand');
    if (trim($template) === '') {
        return false;
    }

    $runKey = 'link:' . (int) $registration['id'] . ':' . se_now()->format('YmdH');
    $runId  = se_message_run_claim($pdo, (int) $event['id'], 'link_on_demand', $runKey, null, $createdBy);
    if ($runId === null) {
        return false;   // already sent this hour
    }

    $result = se_messages_send($pdo, $event, $days, 'link_on_demand', $template, [$registration], $createdBy);

    se_message_run_finish($pdo, $runId, $result['recipients'] > 0 ? 'queued' : 'skipped', $result);
    se_audit($pdo, (int) $event['id'], 'messages_run', [
        'kind'            => 'link_on_demand',
        'run_key'         => $runKey,
        'registration_id' => (int) $registration['id'],
        'recipients'      => $result['recipients'],
    ], 'registration', (int) $registration['id'], $createdBy);

    return $result['recipients'] > 0;
}

// --------------------------------------------------------------------------
// Scheduled kinds (§16.2, §16.4) — PR4
// --------------------------------------------------------------------------

/**
 * When each enabled scheduled kind is due, and with which run key.
 *
 * Pure apart from the days it is handed: the cron asks this, the Studio asks
 * this to draw "Reminder 2 · Sat 24 Oct, 3:00 PM", and both get the same
 * answer. Multi-day events get one `reminder_2` per day (§16.2); there is
 * only ever one `reminder_1`, the evening before the first day.
 *
 * @param list<array<string,mixed>> $days
 * @return list<array{kind:string, run_key:string, scheduled_for:DateTimeImmutable, day:?array, label:string}>
 */
function se_message_schedule(array $event, array $days, array $settings): array
{
    $out   = [];
    $first = $days[0] ?? null;

    $start = se_parse_datetime($first['starts_at'] ?? ($event['starts_at'] ?? null));
    if ($start === null) {
        return $out;
    }

    if (se_bool($settings['messages']['reminder_1']['enabled'] ?? false)) {
        $at = se_normalize_time_of_day((string) ($settings['messages']['reminder_1']['at'] ?? '18:00'), '18:00');
        [$h, $m] = array_map('intval', explode(':', $at));
        $when = $start->modify('-1 day')->setTime($h, $m, 0);

        $out[] = [
            'kind'          => 'reminder_1',
            'run_key'       => 'reminder_1:' . $start->format('Ymd'),
            'scheduled_for' => $when,
            'day'           => $first,
            'label'         => 'The evening before',
        ];
    }

    if (se_bool($settings['messages']['reminder_2']['enabled'] ?? false)) {
        $before = se_int($settings['messages']['reminder_2']['minutes_before'] ?? 120, 0, 1440, 120);

        foreach ($days as $day) {
            $dayStart = se_parse_datetime($day['starts_at']);
            if ($dayStart === null) {
                continue;
            }

            $out[] = [
                'kind'          => 'reminder_2',
                'run_key'       => 'reminder_2:' . $dayStart->format('Ymd'),
                'scheduled_for' => $dayStart->modify('-' . $before . ' minutes'),
                'day'           => $day,
                'label'         => count($days) > 1
                    ? $before . ' minutes before ' . $dayStart->format('D j M')
                    : $before . ' minutes before the doors',
            ];
        }
    }

    return $out;
}

/**
 * The people one run should text.
 *
 * Every audience excludes test rows, opted-out and erased contacts, and
 * anything without a usable Nigerian mobile — se_messages_send() checks the
 * last two again per recipient, because a person can opt out between the
 * count and the send.
 *
 * @return list<array<string,mixed>> registration rows
 */
function se_message_audience(PDO $pdo, array $event, string $segment, ?array $day = null): array
{
    $eventId = (int) $event['id'];

    $base = "SELECT r.* FROM se_registrations r
                JOIN se_contacts c ON c.id = r.contact_id
               WHERE r.event_id = ? AND r.is_test = 0
                 AND c.opted_out_at IS NULL AND c.erased_at IS NULL AND c.sms_capable = 1";
    $args = [$eventId];

    $checkinJoin = se_table_exists($pdo, 'se_checkins');

    switch ($segment) {
        case 'reminder_1':
        case 'confirmed':
            $sql = $base . " AND r.status = 'confirmed'";
            break;

        case 'waitlisted':
            $sql = $base . " AND r.status = 'waitlisted'";
            break;

        case 'cancelled':
            $sql = $base . " AND r.status = 'cancelled'";
            break;

        case 'checked_in':
            if (!$checkinJoin) {
                return [];
            }
            $sql = $base . " AND EXISTS (SELECT 1 FROM se_checkins k WHERE k.event_id = r.event_id AND k.registration_id = r.id)";
            break;

        case 'karaoke_singers':
            if (!se_table_exists($pdo, 'se_karaoke_entries')) {
                return [];
            }
            $sql = $base . " AND EXISTS (SELECT 1 FROM se_karaoke_entries e
                                          WHERE e.event_id = r.event_id AND e.registration_id = r.id
                                            AND e.status IN ('held','queued','up_next','on_stage','done'))";
            break;

        case 'reminder_2':
        case 'confirmed_not_checked_in':
            // Per day for reminder 2 (§16.2): somebody who came yesterday
            // still needs tonight's nudge.
            $sql = $base . " AND r.status = 'confirmed'";
            if ($checkinJoin) {
                if ($day !== null) {
                    $sql .= " AND NOT EXISTS (SELECT 1 FROM se_checkins k
                                               WHERE k.event_id = r.event_id AND k.registration_id = r.id
                                                 AND k.day_date = ?)";
                    $args[] = (string) $day['day_date'];
                } else {
                    $sql .= " AND NOT EXISTS (SELECT 1 FROM se_checkins k
                                               WHERE k.event_id = r.event_id AND k.registration_id = r.id)";
                }
            }
            break;

        default:
            return [];
    }

    // One row per registration (the contact join is one-to-one). The "one
    // message per phone" rule is applied below in PHP. A GROUP BY here broke
    // the cron on MariaDB, whose ONLY_FULL_GROUP_BY does not accept r.* even
    // when r.id is in the group.
    $sql .= " ORDER BY r.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll() ?: [];

    $seen = [];
    $out  = [];
    foreach ($rows as $row) {
        $phone = se_message_recipient_phone($pdo, $row);
        if ($phone === null || isset($seen[$phone])) {
            continue;
        }
        $seen[$phone] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * Run one scheduled message (§16.4).
 *
 * The run key is claimed first, so two crons racing produce one run; a run
 * whose moment passed by more than `max_lateness_min` is recorded as
 * `skipped`, because a reminder two hours after the start is worse than no
 * reminder at all.
 *
 * @return array{status:string, recipients:int, est_units:int, detail:?string}
 */
function se_messages_run_scheduled(PDO $pdo, array $event, array $days, array $slot, ?DateTimeImmutable $now = null, ?int $createdBy = null): array
{
    $now ??= se_now();
    $eventId  = (int) $event['id'];
    $settings = se_event_settings($event);
    $kind     = (string) $slot['kind'];

    $runId = se_message_run_claim($pdo, $eventId, $kind, (string) $slot['run_key'], $slot['scheduled_for'], $createdBy);
    if ($runId === null) {
        return ['status' => 'already', 'recipients' => 0, 'est_units' => 0, 'detail' => 'Already run.'];
    }

    $finish = static function (string $status, array $extra) use ($pdo, $runId, $eventId, $kind, $slot, $createdBy): array {
        se_message_run_finish($pdo, $runId, $status, $extra);
        se_audit($pdo, $eventId, 'messages_run', [
            'kind'       => $kind,
            'run_key'    => $slot['run_key'],
            'status'     => $status,
            'recipients' => (int) ($extra['recipients'] ?? 0),
        ], 'event', $eventId, $createdBy);

        return [
            'status'     => $status,
            'recipients' => (int) ($extra['recipients'] ?? 0),
            'est_units'  => (int) ($extra['est_units'] ?? 0),
            'detail'     => $extra['detail'] ?? null,
        ];
    };

    if (in_array((string) $event['status'], ['cancelled', 'archived'], true)) {
        return $finish('skipped', ['detail' => 'The event is ' . $event['status'] . '.']);
    }

    $lateness = (int) round(($now->getTimestamp() - $slot['scheduled_for']->getTimestamp()) / 60);
    $maxLate  = se_int($settings['messages']['max_lateness_min'] ?? 90, 0, 1440, 90);
    if ($lateness > $maxLate) {
        return $finish('skipped', ['detail' => 'Missed by ' . $lateness . ' minutes.']);
    }

    $template = se_message_template($settings, $kind);
    if (trim($template) === '') {
        return $finish('skipped', ['detail' => 'No template.']);
    }

    $recipients = se_message_audience($pdo, $event, $kind, $slot['day'] ?? null);
    if (!$recipients) {
        return $finish('skipped', ['detail' => 'Nobody to text.']);
    }

    $result = se_messages_send($pdo, $event, $days, $kind, $template, $recipients, $createdBy, $kind === 'thank_you' ? '#recap' : '');

    return $finish($result['recipients'] > 0 ? 'queued' : 'skipped', $result);
}

/**
 * Every due scheduled run for one event. Called by the cron, and by the
 * Studio's "Run now" so a Producer can recover from a dead cron.
 */
function se_messages_run_due(PDO $pdo, array $event, ?DateTimeImmutable $now = null, ?int $createdBy = null): array
{
    $now ??= se_now();

    if ((string) $event['status'] !== 'published') {
        return [];
    }

    $days     = se_event_days($pdo, (int) $event['id']);
    $settings = se_event_settings($event);
    $out      = [];

    foreach (se_message_schedule($event, $days, $settings) as $slot) {
        if ($slot['scheduled_for'] > $now) {
            continue;
        }
        $result = se_messages_run_scheduled($pdo, $event, $days, $slot, $now, $createdBy);
        if ($result['status'] !== 'already') {
            $out[$slot['run_key']] = $result;
        }
    }

    return $out;
}

/**
 * An ad-hoc message to a segment (§16.6). Producer-only; the caller has
 * already confirmed the recipient count on screen.
 */
function se_messages_send_adhoc(PDO $pdo, array $event, string $segment, string $template, ?int $createdBy): array
{
    $segment = se_enum($segment, SE_MESSAGE_SEGMENTS, '');
    if ($segment === '') {
        throw new SeValidationException(['segment' => 'Choose who this message goes to.']);
    }

    $template = se_str($template, 600);
    if (trim($template) === '') {
        throw new SeValidationException(['template' => 'Write the message first.']);
    }

    $eventId = (int) $event['id'];
    $days    = se_event_days($pdo, $eventId);

    $runKey = 'adhoc:' . se_now()->format('YmdHis');
    $runId  = se_message_run_claim($pdo, $eventId, 'adhoc', $runKey, se_now(), $createdBy);
    if ($runId === null) {
        throw new SeRuleException('RATE_LIMITED', 'That message is already going out.');
    }

    $recipients = se_message_audience($pdo, $event, $segment);
    if (!$recipients) {
        se_message_run_finish($pdo, $runId, 'skipped', ['detail' => 'Nobody in that segment.']);
        throw new SeRuleException('RULE', 'There is nobody in that group to text.');
    }

    $result = se_messages_send($pdo, $event, $days, 'adhoc', $template, $recipients, $createdBy);

    se_message_run_finish($pdo, $runId, $result['recipients'] > 0 ? 'queued' : 'skipped', $result + ['detail' => $segment]);
    se_audit($pdo, $eventId, 'adhoc_message', [
        'segment' => $segment, 'recipients' => $result['recipients'], 'run_key' => $runKey,
    ], 'event', $eventId, $createdBy);

    return $result + ['segment' => $segment, 'run_key' => $runKey];
}

/**
 * Send one message to one number, so the crew can read it on a real phone
 * before a hundred people do. It is a real campaign of one, keyed by the
 * minute, which is also its throttle.
 */
function se_messages_test_send(PDO $pdo, array $event, string $kind, string $phone, ?int $createdBy): array
{
    if (!se_sms_available($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'SMS Studio is not set up on this site.');
    }

    $normalised = se_phone_normalize($phone);
    if ($normalised === null) {
        throw new SeValidationException(['phone' => 'Enter a mobile number, e.g. 0803 123 4567.']);
    }

    $settings = se_event_settings($event);
    $template = se_message_template($settings, $kind);
    if (trim($template) === '') {
        throw new SeValidationException(['kind' => 'That message has no template yet.']);
    }

    $eventId = (int) $event['id'];
    $days    = se_event_days($pdo, $eventId);
    $runKey  = 'test:' . $kind . ':' . se_now()->format('YmdHi');

    $runId = se_message_run_claim($pdo, $eventId, $kind, $runKey, se_now(), $createdBy);
    if ($runId === null) {
        throw new SeRuleException('RATE_LIMITED', 'A test of that message just went out. Give it a minute.');
    }

    $body = se_message_render_event($template, $event, $days);
    $body = function_exists('sms_render')
        ? sms_render($body, ['first_name' => 'Ada', 'link' => se_event_url((string) $event['slug'], '')])
        : $body;

    $pdo->prepare(
        "INSERT INTO sms_campaigns (title, audience, event_id, filters_json, body_template, total, status, created_by)
         VALUES (?, 'special_event', NULL, ?, ?, 1, 'queued', ?)"
    )->execute([
        mb_substr(trim((string) $event['title']) . ' · Test ' . (SE_MESSAGE_KIND_LABELS[$kind] ?? $kind), 0, 255, 'UTF-8'),
        se_json_encode(['se_event_id' => $eventId, 'kind' => $kind, 'test' => true]),
        $body,
        $createdBy,
    ]);
    $campaignId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES (?, ?, 'queued')")
        ->execute([$campaignId, se_json_encode([
            'source'     => 'se_test',
            'source_id'  => 0,
            'name'       => 'Test',
            'first_name' => 'Ada',
            'phone'      => $normalised,
            'event_title' => (string) $event['title'],
            'link'       => se_event_url((string) $event['slug'], ''),
        ])]);

    se_message_run_finish($pdo, $runId, 'queued', [
        'sms_campaign_id' => $campaignId, 'recipients' => 1, 'est_units' => 1, 'detail' => 'Test send',
    ]);

    return ['campaign_id' => $campaignId, 'phone' => se_mask_phone($normalised)];
}

/**
 * Everything the Studio's Messages tab draws: the settings, each kind's
 * preview and estimate, and the run log.
 */
function se_messages_studio(PDO $pdo, array $event): array
{
    $eventId  = (int) $event['id'];
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);

    $kinds = [];
    foreach (['reminder_1', 'reminder_2', 'thank_you', 'waitlist_promotion', 'link_on_demand'] as $kind) {
        $config   = is_array($settings['messages'][$kind] ?? null) ? $settings['messages'][$kind] : [];
        $template = se_message_template($settings, $kind);
        $preview  = se_message_preview($template, $event, $days, [], $settings);

        $audience = in_array($kind, ['reminder_1', 'reminder_2'], true)
            ? count(se_message_audience($pdo, $event, $kind, $kind === 'reminder_2' ? ($days[0] ?? null) : null))
            : null;

        $kinds[$kind] = [
            'kind'     => $kind,
            'label'    => SE_MESSAGE_KIND_LABELS[$kind] ?? $kind,
            'enabled'  => se_bool($config['enabled'] ?? true),
            'at'       => $config['at'] ?? null,
            'minutes_before' => isset($config['minutes_before']) ? (int) $config['minutes_before'] : null,
            'template' => $template,
            'preview'  => $preview,
            'audience' => $audience,
            'est_units' => $audience !== null ? $audience * (int) $preview['pages'] : null,
            'scheduled' => false,
        ];
    }

    $schedule = [];
    foreach (se_message_schedule($event, $days, $settings) as $slot) {
        $schedule[] = [
            'kind'          => $slot['kind'],
            'run_key'       => $slot['run_key'],
            'scheduled_for' => $slot['scheduled_for']->format('c'),
            'label'         => $slot['label'],
        ];
        $kinds[$slot['kind']]['scheduled'] = true;
    }

    return [
        'kinds'    => array_values($kinds),
        'schedule' => $schedule,
        'segments' => SE_MESSAGE_SEGMENTS,
        'runs'     => se_message_runs($pdo, $eventId),
        'sms'      => [
            'available'     => se_sms_available($pdo),
            'worker_fresh'  => se_sms_worker_fresh($pdo),
            'studio_url'    => '/modules/sms_studio/index.php',
        ],
        'max_lateness_min' => se_int($settings['messages']['max_lateness_min'] ?? 90, 0, 1440, 90),
    ];
}

/** The run log, newest first, with a link into SMS Studio's history. */
function se_message_runs(PDO $pdo, int $eventId, int $limit = 50): array
{
    if (!se_table_exists($pdo, 'se_message_runs')) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt  = $pdo->prepare(
        "SELECT * FROM se_message_runs WHERE event_id = ? ORDER BY id DESC LIMIT {$limit}"
    );
    $stmt->execute([$eventId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $campaignId = $row['sms_campaign_id'] !== null ? (int) $row['sms_campaign_id'] : null;
        $out[] = [
            'id'            => (int) $row['id'],
            'kind'          => (string) $row['kind'],
            'label'         => SE_MESSAGE_KIND_LABELS[(string) $row['kind']] ?? (string) $row['kind'],
            'run_key'       => (string) $row['run_key'],
            'scheduled_for' => se_iso($row['scheduled_for']),
            'status'        => (string) $row['status'],
            'recipients'    => (int) $row['recipients'],
            'est_units'     => (int) $row['est_units'],
            'detail'        => $row['detail'] ?? null,
            'campaign_id'   => $campaignId,
            'campaign_url'  => $campaignId !== null ? '/modules/sms_studio/index.php?campaign=' . $campaignId : null,
            'created_at'    => se_iso($row['created_at'] ?? null),
        ];
    }

    return $out;
}
