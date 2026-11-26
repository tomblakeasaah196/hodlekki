<?php
// /includes/campaigns/outbox.php
//
// The queue every system@ email goes through, and its worker.
//
//   email_enqueue()     put one message in the queue (rendered already)
//   email_send_doc()    render a block document for one person, then enqueue
//   email_send_now()    enqueue, then send it straight after this response
//                       (confirm links, resets, alerts: seconds, not a minute)
//   email_worker_run()  cron/email_worker.php: send what is due, by priority,
//                       under the domain's hourly budget, over ONE SMTP
//                       connection; retries; orphans; suppression and consent
//                       re-checked at the last moment
//
// Every row gets its Message-ID when it is queued, so the id is on record
// before the message leaves; campaign_mail_lookup() answers the mailbox's
// "which automation is this reply about?" from it.
//
// The transport is injectable (tests use a fake one). The default goes through
// hod_send_mail() with one shared PHPMailer; SMTP settings come only from .env.

require_once __DIR__ . '/../mailer.php';

const EMAIL_WORKER_LOCK       = 'hod_email_worker';
const EMAIL_ORPHAN_MINUTES    = 10;
const EMAIL_RETRY_MINUTES     = [1, 5, 15, 60];   // after attempts 1, 2, 3, 4; the 5th failure is final
const EMAIL_PRUNE_DAYS        = 90;
const EMAIL_PURPOSES          = ['account', 'news'];
const EMAIL_OUTBOX_STATUSES   = ['queued', 'sending', 'sent', 'failed', 'cancelled', 'suppressed', 'unknown', 'expired'];

/** The domain our Message-IDs end in: the sender's own (spam filters check). */
function email_message_domain(): string
{
    $from = function_exists('hod_mail_settings') ? (string) (hod_mail_settings()['from_address'] ?? '') : '';
    $at = strrpos($from, '@');
    return $at !== false ? strtolower(substr($from, $at + 1)) : site_host();
}

function email_new_message_id(string $ref): string
{
    $local = preg_replace('/[^A-Za-z0-9_-]/', '', $ref) . '.' . bin2hex(random_bytes(4));
    return '<' . $local . '@' . email_message_domain() . '>';
}

/** sha256 of the Message-ID, lower case, without angle brackets or spaces. */
function email_message_id_hash(string $messageId): string
{
    return hash('sha256', strtolower(trim($messageId, " \t<>")));
}

/**
 * The mailbox's lookup: an incoming reply's In-Reply-To / References id (hashed
 * with email_message_id_hash()) → which person and which flow it answers.
 *
 * @return array{user_id:?int, flow:string, sent_at:?string, subject:string, outbox_id:int, campaign_id:?int}|null
 */
function campaign_mail_lookup(PDO $pdo, string $messageIdHash): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $messageIdHash) || !campaigns_table_exists($pdo, 'email_outbox')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, user_id, flow, sent_at, subject, campaign_id FROM email_outbox WHERE message_id_hash = ? LIMIT 1');
    $stmt->execute([$messageIdHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'user_id'     => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'flow'        => (string) $row['flow'],
        'sent_at'     => $row['sent_at'],
        'subject'     => (string) $row['subject'],
        'outbox_id'   => (int) $row['id'],
        'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
    ];
}

/**
 * Queue one message.
 *
 * $msg: lane, purpose (account|news), flow, to_email, subject, html, text,
 *       ref (pre-made when the body already carries it), user_id, to_name,
 *       from_name, reply_to, reply_to_name, headers [name => value], links
 *       (tracked targets), campaign_id, automation_send_id, not_before
 *       (DateTimeImmutable), created_by.
 *
 * @return array{ok:bool, id?:int, ref?:string, message_id?:string, code?:string, message?:string}
 */
function email_enqueue(PDO $pdo, array $msg): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'Email is not set up yet.'];
    }
    $lane = (string) ($msg['lane'] ?? '');
    $purpose = (string) ($msg['purpose'] ?? 'news');
    if (!isset(EMAIL_LANES[$lane]) || $lane === 'mailbox') {
        throw new InvalidArgumentException('email_enqueue: bad lane ' . $lane);
    }
    if (!in_array($purpose, EMAIL_PURPOSES, true)) {
        throw new InvalidArgumentException('email_enqueue: bad purpose ' . $purpose);
    }
    $to = email_normalize((string) ($msg['to_email'] ?? ''));
    if (!email_is_valid_syntax($to)) {
        return ['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => 'That is not a valid email address.'];
    }
    if ($purpose === 'news' && email_is_church_domain($to)) {
        return ['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => 'Church news never goes to a hodlekki.org address.'];
    }
    $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($msg['subject'] ?? '')));
    if ($subject === '' || (string) ($msg['html'] ?? '') === '') {
        throw new InvalidArgumentException('email_enqueue: a subject and a body are required');
    }
    $flow = (string) ($msg['flow'] ?? '');
    if (!preg_match('/^[a-z0-9_.-]{1,60}$/', $flow)) {
        throw new InvalidArgumentException('email_enqueue: bad flow ' . $flow);
    }
    foreach ((array) ($msg['headers'] ?? []) as $name => $value) {
        if (!hod_mail_header_ok((string) $name, (string) $value)) {
            throw new InvalidArgumentException('email_enqueue: refused header ' . $name);
        }
    }
    foreach (['reply_to'] as $field) {
        if (!empty($msg[$field]) && !email_is_valid_syntax(email_normalize((string) $msg[$field]))) {
            throw new InvalidArgumentException('email_enqueue: bad ' . $field);
        }
    }

    $ref = (string) ($msg['ref'] ?? campaigns_token(16));
    if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $ref)) {
        throw new InvalidArgumentException('email_enqueue: bad ref');
    }
    $messageId = email_new_message_id($ref);
    $notBefore = ($msg['not_before'] ?? null) instanceof DateTimeImmutable ? campaigns_sql_dt($msg['not_before']) : null;
    $oneLine = static fn ($v, int $len): ?string => $v === null || $v === ''
        ? null : campaigns_cut(trim(str_replace(["\r", "\n"], ' ', (string) $v)), $len);

    $pdo->prepare('INSERT INTO email_outbox
            (ref, lane, priority, purpose, flow, user_id, to_email, to_name, from_name, reply_to, reply_to_name,
             subject, html, text_body, headers_json, links_json, campaign_id, automation_send_id,
             message_id, message_id_hash, status, attempts, not_before, send_by, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'queued\', 0, ?, ?, ?, ?)')
        ->execute([
            $ref,
            $lane,
            email_lane_priority($lane),
            $purpose,
            $flow,
            isset($msg['user_id']) ? (int) $msg['user_id'] : null,
            $to,
            $oneLine($msg['to_name'] ?? null, 190),
            $oneLine($msg['from_name'] ?? null, 190),
            !empty($msg['reply_to']) ? email_normalize((string) $msg['reply_to']) : null,
            $oneLine($msg['reply_to_name'] ?? null, 190),
            campaigns_cut($subject, 255),
            (string) $msg['html'],
            isset($msg['text']) ? (string) $msg['text'] : null,
            !empty($msg['headers']) ? campaigns_json($msg['headers']) : null,
            !empty($msg['links']) ? campaigns_json(array_values($msg['links'])) : null,
            isset($msg['campaign_id']) ? (int) $msg['campaign_id'] : null,
            isset($msg['automation_send_id']) ? (int) $msg['automation_send_id'] : null,
            $messageId,
            email_message_id_hash($messageId),
            $notBefore,
            ($msg['send_by'] ?? null) instanceof DateTimeImmutable ? campaigns_sql_dt($msg['send_by']) : null,
            isset($msg['created_by']) ? (int) $msg['created_by'] : null,
            campaigns_sql_dt(campaigns_now()),
        ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'ref' => $ref, 'message_id' => $messageId];
}

/**
 * Render a block document for one person and queue it.
 *
 * $person: id, first_name, last_name (+ email for {{email}}).
 * $msg: as email_enqueue() (no html/text/ref/links) plus vars (extra merge
 *       values), track (rewrite links for click counting), track_opens,
 *       now (true = email_send_now()).
 */
function email_send_doc(PDO $pdo, array $doc, array $person, array $msg): array
{
    $ref = campaigns_token(16);
    $purpose = (string) ($msg['purpose'] ?? 'news');
    $rendered = email_render($doc, $person, [
        'purpose'     => $purpose,
        'ref'         => $ref,
        'track'       => !empty($msg['track']),
        'track_opens' => !empty($msg['track_opens']),
        'vars'        => (array) ($msg['vars'] ?? []),
        'pdo'         => $pdo,
    ]);
    if (!$rendered['ok']) {
        return ['ok' => false, 'code' => 'INVALID_DOC', 'message' => implode('; ', $rendered['errors'])];
    }
    $names = campaigns_person_names($person);
    $send = $msg + [
        'ref'     => $ref,
        'subject' => $rendered['subject'],
        'html'    => $rendered['html'],
        'text'    => $rendered['text'],
        'links'   => $rendered['links'],
        'user_id' => isset($person['id']) ? (int) $person['id'] : null,
        'to_name' => $names['full_name'] !== 'friend' ? $names['full_name'] : null,
    ];
    unset($send['vars'], $send['track'], $send['track_opens'], $send['now']);
    return !empty($msg['now']) ? email_send_now($pdo, $send) : email_enqueue($pdo, $send);
}

/** Queue, then send this one message straight after the response (web requests). */
function email_send_now(PDO $pdo, array $msg): array
{
    $queued = email_enqueue($pdo, $msg);
    if ($queued['ok']) {
        email_dispatch_after_response($pdo, (int) $queued['id']);
    }
    return $queued;
}

/**
 * Remember $id and, once per request, arm a shutdown hook that releases the
 * response and sends those rows. Under the CLI it does nothing: the worker
 * sends them within the minute.
 */
function email_dispatch_after_response(PDO $pdo, int $id): void
{
    static $ids = [];
    static $armed = false;
    if (PHP_SAPI === 'cli') {
        return;
    }
    $ids[] = $id;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function(static function () use ($pdo, &$ids) {
        try {
            $fatal = error_get_last();
            if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            if (function_exists('push_release_response')) {
                push_release_response();
            } else {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }
            }
            ignore_user_abort(true);
            @set_time_limit(30);
            email_worker_run($pdo, ['only_ids' => $ids, 'lock_wait' => 0, 'limit' => count($ids)]);
        } catch (Throwable $e) {
            error_log('[email] inline send failed: ' . $e->getMessage());
        }
    });
}

/** Are the public unsubscribe and click pages deployed? (Church news links to them.) */
function email_public_endpoints_ready(): bool
{
    $root = dirname(__DIR__, 2) . '/email/';
    return is_file($root . 'unsubscribe.php') && is_file($root . 'c.php');
}

/** Inside the send window for church news (Lagos)? Account mail ignores it. */
function email_in_send_window(PDO $pdo, DateTimeImmutable $at): bool
{
    $start = campaigns_minutes(email_setting($pdo, 'window_start')) ?? 7 * 60;
    $end = campaigns_minutes(email_setting($pdo, 'window_end')) ?? 20 * 60;
    $local = $at->setTimezone(new DateTimeZone(CAMPAIGNS_TZ));
    $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
    return $minute >= $start && $minute < $end;
}

/** The headers every message carries, from its row. */
function email_build_headers(array $row): array
{
    $headers = [
        'X-HOD-Ref'      => (string) $row['ref'],
        // RFC 3834: automatic replies (out of office) must not answer this.
        'Auto-Submitted' => 'auto-generated',
    ];
    if (in_array($row['lane'], ['automation', 'campaign'], true)) {
        $headers['Precedence'] = 'bulk';
    }
    if ($row['purpose'] === 'news') {
        // RFC 8058 one-click unsubscribe (https only: no mailbox should have to
        // read unsubscribe mail).
        $headers['List-Unsubscribe'] = '<' . email_unsubscribe_url((string) $row['ref']) . '>';
        $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
    }
    foreach ((array) json_decode((string) ($row['headers_json'] ?? ''), true) as $name => $value) {
        if (is_string($name) && is_scalar($value) && hod_mail_header_ok($name, (string) $value)) {
            $headers[$name] = (string) $value;
        }
    }
    return $headers;
}

/**
 * What kind of failure is this send error?
 *   cap        the host's hourly cap: stop for the hour, requeue, learn the cap
 *   config     our own setup (no password, no PHPMailer, login refused): stop the run
 *   unavailable our mail server cannot be reached: stop the run, attempts not counted
 *   recipient  permanent, about this address (550 user unknown…): fail + suppress
 *   permanent  permanent, not about the address: fail
 *   temporary  anything else (4xx, a full mailbox): retry later
 */
function email_classify_failure(string $error): string
{
    if (email_parse_cap_refusal($error) !== null) {
        return 'cap';
    }
    // hod_zepto_error() marks what it already knows.
    if (str_starts_with($error, '[temporary] ')) {
        return 'temporary';
    }
    if (str_starts_with($error, '[permanent] ')) {
        return 'permanent';
    }
    if (preg_match('/not set up|not installed|could not authenticate|authentication failed|535[ -]/i', $error)) {
        return 'config';
    }
    // Our own mail server cannot be reached: nobody's message is at fault, so
    // stop the run and spend nobody's attempts (an outage must not fail the queue).
    if (preg_match('/connect\(\) failed|could not connect|failed to connect|connection (?:refused|timed out|reset)|unable to connect|network is unreachable/i', $error)) {
        return 'unavailable';
    }
    // A full mailbox is temporary (and counted as a soft bounce by the DSN path).
    if (preg_match('/\b552\b|\b5\.2\.2\b|mailbox (?:is )?full|over quota/i', $error)) {
        return 'temporary';
    }
    if (preg_match('/\b55[0-3]\b|\b5\.1\.\d\b|\b5\.2\.1\b|user unknown|no such user|unknown user|mailbox (?:unavailable|not found|does not exist)|recipient address rejected|does not exist|invalid recipient|address rejected/i', $error)) {
        return 'recipient';
    }
    if (preg_match('/\b5\d\d\b|\b5\.\d\.\d+\b/', $error)) {
        return 'permanent';
    }
    return 'temporary';
}

// Lanes that leave through the account-mail service when it is set up
// (hod_mail_account_settings(), 26 Nov 2026). Everything else uses the host.
const EMAIL_ACCOUNT_ROUTE_LANES = ['transactional', 'alert'];

/** 'account' (the transactional service) or 'host' (this server, under its hourly cap). */
function email_lane_route(string $lane): string
{
    return in_array($lane, EMAIL_ACCOUNT_ROUTE_LANES, true) && hod_mail_account_settings() !== null ? 'account' : 'host';
}

/**
 * The default transport: hod_send_mail() over one SMTP connection per route
 * (the message's 'route', from email_lane_route(); 'host' when missing).
 */
function email_smtp_transport(): array
{
    $mailers = [];
    $send = static function (array $m) use (&$mailers): array {
        $route = ($m['route'] ?? 'host') === 'account' ? 'account' : 'host';
        if ($route === 'account' && ($api = hod_mail_account_settings()) !== null && $api['transport'] === 'api') {
            return hod_zepto_send($api, $m);
        }
        if (!isset($mailers[$route])) {
            try {
                $settings = $route === 'account' ? hod_mail_account_settings() : null;
                if ($route === 'account' && $settings === null) {
                    return ['ok' => false, 'error' => 'Account mail is not set up: ACCOUNT_MAIL_HOST, ACCOUNT_MAIL_USERNAME and ACCOUNT_MAIL_PASSWORD are needed in .env.'];
                }
                $mailers[$route] = hod_mailer($settings);
                $mailers[$route]->SMTPKeepAlive = true;
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $mailer = $mailers[$route];
        return hod_send_mail(
            [$m['to_email'] => (string) ($m['to_name'] ?? '')],
            $m['subject'],
            $m['html'],
            $m['text'],
            [
                'mailer'        => $mailer,
                'from_name'     => $m['from_name'] ?? null,
                'reply_to'      => $m['reply_to'] ?? null,
                'reply_to_name' => $m['reply_to_name'] ?? null,
                'message_id'    => $m['message_id'],
                'headers'       => $m['headers'],
            ]
        );
    };
    $close = static function () use (&$mailers): void {
        foreach ($mailers as $mailer) {
            try {
                $mailer->smtpClose();
            } catch (Throwable $e) {
                // the connection is gone either way
            }
        }
        $mailers = [];
    };
    return ['send' => $send, 'close' => $close];
}

/**
 * Rows left in 'sending' by a run that died. The message may already be in
 * someone's inbox, so it is NEVER sent again: it becomes 'unknown'.
 */
function email_recover_orphans(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    $now ??= campaigns_now();
    $stmt = $pdo->prepare("UPDATE email_outbox
                              SET status = 'unknown', locked_at = NULL,
                                  last_error = 'The send was interrupted; it may or may not have left, so it is not sent again.'
                            WHERE status = 'sending' AND locked_at < ?");
    $stmt->execute([campaigns_sql_dt($now->modify('-' . EMAIL_ORPHAN_MINUTES . ' minutes'))]);
    return $stmt->rowCount();
}

/**
 * Send what is due. Returns a summary for the cron log.
 *
 * $opts: transport (['send' => callable, 'close' => callable] or a callable),
 *        limit (default per_minute setting), only_ids (int[]), lock_wait
 *        (seconds, default 5), now (DateTimeImmutable), dry_run.
 */
function email_worker_run(PDO $pdo, array $opts = []): array
{
    $out = ['ran' => false, 'locked' => false, 'recovered' => 0, 'expired' => 0, 'sent' => 0, 'failed' => 0,
            'retried' => 0, 'suppressed' => 0, 'waiting_budget' => 0, 'outside_window' => 0,
            'stopped' => null, 'message' => ''];
    if (!campaigns_ready($pdo)) {
        $out['message'] = 'Email tables are not there yet (db/migrate.php has not run).';
        return $out;
    }
    if (email_setting($pdo, 'paused') === '1') {
        $out['message'] = 'Sending is paused (email_settings.paused).';
        return $out;
    }
    if (!campaigns_lock($pdo, EMAIL_WORKER_LOCK, (int) ($opts['lock_wait'] ?? 5))) {
        $out['locked'] = true;
        $out['message'] = 'Another send run holds the lock.';
        return $out;
    }

    $transport = $opts['transport'] ?? null;
    if (is_callable($transport)) {
        $transport = ['send' => $transport, 'close' => static function (): void {}];
    }
    $transport ??= email_smtp_transport();

    try {
        $out['ran'] = true;
        $now = ($opts['now'] ?? null) instanceof DateTimeImmutable ? $opts['now'] : campaigns_now();
        $out['recovered'] = email_recover_orphans($pdo, $now);
        // A date-bound message ("see you tomorrow") that could not leave in
        // time is dropped rather than arriving days late.
        $expire = $pdo->prepare("UPDATE email_outbox SET status = 'expired', last_error = 'Not sent in time; dropped.'
                                  WHERE status = 'queued' AND send_by IS NOT NULL AND send_by < ?");
        $expire->execute([campaigns_sql_dt($now)]);
        $out['expired'] = $expire->rowCount();
        $limit = max(1, (int) ($opts['limit'] ?? email_setting($pdo, 'per_minute')));
        // Church news carries links to the public unsubscribe and click URLs;
        // until those pages are deployed it waits in the queue rather than
        // leaving with links that lead nowhere.
        $endpoints = (bool) ($opts['endpoints_ready'] ?? email_public_endpoints_ready());
        if (!$endpoints) {
            $out['message'] = 'Church news is held: the public /email/ pages are not deployed yet. ';
        }
        $inWindow = $endpoints && email_in_send_window($pdo, $now);

        $where = "status = 'queued' AND (not_before IS NULL OR not_before <= ?)";
        $params = [campaigns_sql_dt($now)];
        if (!empty($opts['only_ids'])) {
            $ids = array_values(array_unique(array_map('intval', (array) $opts['only_ids'])));
            $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        if (!$inWindow) {
            $where .= " AND purpose = 'account'";
        }
        $stmt = $pdo->prepare("SELECT * FROM email_outbox WHERE {$where} ORDER BY priority ASC, id ASC LIMIT " . ($limit * 3));
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$inWindow) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE status = 'queued' AND purpose = 'news' AND (not_before IS NULL OR not_before <= ?)");
            $count->execute([campaigns_sql_dt($now)]);
            $out['outside_window'] = (int) $count->fetchColumn();
        }

        $bulkFull = false;
        $sentThisRun = 0;
        foreach ($candidates as $row) {
            if ($sentThisRun >= $limit) {
                break;
            }
            $lane = (string) $row['lane'];
            $bulk = email_lane_is_bulk($lane);
            if ($bulk && $bulkFull) {
                $out['waiting_budget']++;
                continue;
            }
            if (!empty($opts['dry_run'])) {
                $sentThisRun++;
                continue;
            }
            // The host's hourly budget meters only what leaves through the host.
            $route = email_lane_route($lane);
            $metered = $route === 'host';
            if ($metered && !email_budget_take($pdo, $lane, 1, $now)) {
                $out['waiting_budget']++;
                if ($bulk) {
                    $bulkFull = true;
                    continue;
                }
                $out['stopped'] = 'budget';
                break;
            }
            $claim = $pdo->prepare("UPDATE email_outbox SET status = 'sending', locked_at = ?, attempts = attempts + 1
                                     WHERE id = ? AND status = 'queued'");
            $claim->execute([campaigns_sql_dt($now), (int) $row['id']]);
            if ($claim->rowCount() !== 1) {
                if ($metered) {
                    email_budget_give_back($pdo, $lane, 1, $now);
                }
                continue;
            }
            $row['attempts'] = (int) $row['attempts'] + 1;

            // Last-moment checks: the address may have bounced, or the person
            // unsubscribed, after this was queued.
            $stop = null;
            if (email_is_suppressed($pdo, (string) $row['to_email'])) {
                $stop = 'The address is suppressed (it bounced or complained).';
            } elseif ($row['purpose'] === 'news' && $row['user_id'] !== null) {
                $may = email_may_send_news($pdo, (int) $row['user_id']);
                if (!$may['ok'] || $may['email'] !== $row['to_email']) {
                    $stop = 'Not sent: ' . str_replace('_', ' ', $may['reason'] === 'ok' ? 'address changed' : $may['reason']) . '.';
                    // A worker message may go to a personal or profile address
                    // (EMAIL_PROFILE_ADDRESS_FLOWS), but only the one the lookup
                    // still picks now: consent, suppression and @hodlc.com included.
                    if (email_flow_takes_profile_address((string) ($row['flow'] ?? ''))) {
                        $alt = email_news_address($pdo, (int) $row['user_id']);
                        if ($alt['ok'] && $alt['email'] === $row['to_email']) {
                            $stop = null;
                        } elseif (!$alt['ok']) {
                            $stop = 'Not sent: ' . str_replace('_', ' ', $alt['reason']) . '.';
                        } else {
                            $stop = 'Not sent: address changed.';
                        }
                    }
                }
            }
            if ($stop !== null) {
                $pdo->prepare("UPDATE email_outbox SET status = 'suppressed', locked_at = NULL, last_error = ? WHERE id = ?")
                    ->execute([$stop, (int) $row['id']]);
                if ($metered) {
                    email_budget_give_back($pdo, $lane, 1, $now);
                }
                $out['suppressed']++;
                continue;
            }

            $message = [
                'to_email'      => (string) $row['to_email'],
                'to_name'       => $row['to_name'],
                'from_name'     => $row['from_name'],
                'reply_to'      => $row['reply_to'],
                'reply_to_name' => $row['reply_to_name'],
                'subject'       => (string) $row['subject'],
                'html'          => (string) $row['html'],
                'text'          => $row['text_body'] !== null ? (string) $row['text_body'] : null,
                'message_id'    => (string) $row['message_id'],
                'headers'       => email_build_headers($row),
                'route'         => $route,
            ];
            try {
                $result = ($transport['send'])($message);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }
            $sentThisRun++;

            if (!empty($result['ok'])) {
                $pdo->prepare("UPDATE email_outbox SET status = 'sent', sent_at = ?, locked_at = NULL, last_error = NULL WHERE id = ?")
                    ->execute([campaigns_sql_dt($now), (int) $row['id']]);
                email_note_delivered($pdo, (string) $row['to_email']);
                $out['sent']++;
                continue;
            }

            $error = campaigns_cut((string) ($result['error'] ?? 'Unknown send error'), 500);
            $kind = email_classify_failure($error);
            if ($kind === 'cap' || $kind === 'config' || $kind === 'unavailable') {
                // Not this message's fault: put it back untouched (the attempt does not count).
                $pdo->prepare("UPDATE email_outbox SET status = 'queued', locked_at = NULL, attempts = attempts - 1, last_error = ? WHERE id = ?")
                    ->execute([$error, (int) $row['id']]);
                if ($metered) {
                    email_budget_give_back($pdo, $lane, 1, $now);
                }
                if ($kind === 'cap' && $metered) {
                    email_budget_refused($pdo, email_parse_cap_refusal($error)['cap'] ?? null, $now);
                }
                $out['stopped'] = $kind;
                break;
            }
            if ($kind === 'temporary' && $row['attempts'] <= count(EMAIL_RETRY_MINUTES)) {
                $wait = EMAIL_RETRY_MINUTES[$row['attempts'] - 1];
                $pdo->prepare("UPDATE email_outbox SET status = 'queued', locked_at = NULL, not_before = ?, last_error = ? WHERE id = ?")
                    ->execute([campaigns_sql_dt($now->modify("+{$wait} minutes")), $error, (int) $row['id']]);
                $out['retried']++;
                continue;
            }
            $pdo->prepare("UPDATE email_outbox SET status = 'failed', locked_at = NULL, last_error = ? WHERE id = ?")
                ->execute([$error, (int) $row['id']]);
            if ($kind === 'recipient') {
                email_suppress($pdo, (string) $row['to_email'], 'hard_bounce', $error);
                email_event($pdo, (int) $row['id'], 'bounce', null, campaigns_cut($error, 255));
            }
            $out['failed']++;
        }

        $out['message'] .= sprintf(
            'sent %d, retry %d, failed %d, suppressed %d, waiting for budget %d%s%s',
            $out['sent'], $out['retried'], $out['failed'], $out['suppressed'], $out['waiting_budget'],
            $out['outside_window'] ? ", {$out['outside_window']} news held until the send window" : '',
            $out['stopped'] ? " (stopped: {$out['stopped']})" : ''
        );
    } finally {
        try {
            ($transport['close'])();
        } finally {
            campaigns_unlock($pdo, EMAIL_WORKER_LOCK);
        }
    }
    return $out;
}

/** Clear bodies older than EMAIL_PRUNE_DAYS (the rows stay: tokens and lookups). */
function email_prune(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    if (!campaigns_table_exists($pdo, 'email_outbox')) {
        return 0;
    }
    $now ??= campaigns_now();
    $stmt = $pdo->prepare("UPDATE email_outbox SET html = NULL, text_body = NULL, pruned_at = ?
                            WHERE pruned_at IS NULL AND status <> 'queued' AND created_at < ?");
    $stmt->execute([campaigns_sql_dt($now), campaigns_sql_dt($now->modify('-' . EMAIL_PRUNE_DAYS . ' days'))]);
    return $stmt->rowCount();
}

/** Queue depth by lane and status, for --status and the settings screen. */
function email_outbox_status(PDO $pdo): array
{
    if (!campaigns_table_exists($pdo, 'email_outbox')) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT lane, status, COUNT(*) AS n FROM email_outbox
                            WHERE status IN ('queued', 'sending') OR created_at >= ?
                            GROUP BY lane, status");
    $stmt->execute([campaigns_sql_dt(campaigns_now()->modify('-1 day'))]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['lane']][$r['status']] = (int) $r['n'];
    }
    return $out;
}
