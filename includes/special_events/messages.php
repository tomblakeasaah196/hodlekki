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
