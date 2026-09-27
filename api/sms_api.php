<?php
// /api/sms_api.php
/**
 * ============================================================================
 * SMS STUDIO — Backend API
 * ----------------------------------------------------------------------------
 * Handles: encrypted config (settings), audience building, send, test-send,
 * campaigns (progress / cancel / send-now), history with full per-message
 * detail and status timeline, per-person history, delivery checks, resend,
 * templates and the suppression list for the BulkSMS Nigeria integration.
 *
 * Every call is a POST carrying the session CSRF token. All times are
 * Africa/Lagos (WAT, UTC+1): PHP and the MySQL session are both pinned to it
 * in includes/db.php, and every *_label field below says "WAT".
 * ============================================================================
 */

require_once '../includes/db.php';
header('Content-Type: application/json');
require_once '../includes/sms_functions.php';

function sms_json(array $a) { echo json_encode($a); exit; }
function sms_fail($msg) { sms_json(['status'=>'error','message'=>$msg]); }

/** One timestamp as raw / ISO-8601 with offset / human label, all in WAT. */
function sms_t($dt) {
    if ($dt === null || $dt === '' || strpos((string)$dt, '0000-00-00') === 0) return null;
    $ts = strtotime((string)$dt);
    if ($ts === false) return null;
    return ['raw' => (string)$dt, 'iso' => date('c', $ts), 'label' => date('D j M Y, g:i:s a', $ts) . ' WAT'];
}
function sms_ids_param($v, $max = 500) {
    if (is_string($v)) $v = explode(',', $v);
    if (!is_array($v)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $v), function ($i) { return $i > 0; })));
    return array_slice($ids, 0, $max);
}
function sms_in(array $ids) { return implode(',', array_fill(0, count($ids), '?')); }
function sms_person_name($first, $last) { return trim(($first ?? '') . ' ' . ($last ?? '')); }

/* ============================================================================
 * SECURITY: auth + CSRF + RBAC
 * ========================================================================== */
if (!isset($_SESSION['user_id'])) sms_fail('Unauthorized access. Please log in.');

// Every action is a POST (the old code also read ?action= from GET, which
// skipped the CSRF check entirely).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sms_fail('Use POST.');
$token = $_POST['_csrf'] ?? '';
if ($token === '' || empty($_SESSION['sms_csrf']) || !hash_equals($_SESSION['sms_csrf'], $token)) {
    sms_fail('Invalid or missing security token. Refresh the page and try again.');
}

$user_id = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');

if (!sms_user_can_send()) sms_fail('Access Denied. You do not have clearance to send SMS.');
if (!sms_vault_ready() && !in_array($action, ['health', 'settings_status'], true)) {
    sms_fail('SMS Studio is not configured: set SMS_VAULT_KEY in .env to a 64-hex-character value.');
}

// Actions that change the session keep its lock; everything else releases it so a
// long BulkSMS call (send / poll / send-now) never freezes the user's other tabs.
if (!in_array($action, ['unlock_settings', 'lock_settings', 'save_settings', 'send_campaign'], true)) {
    session_write_close();
}

// Fetch the current user's record (for password verification + test-send number)
$me = $pdo->prepare("SELECT id, first_name, last_name, phone, email, password_hash FROM users WHERE id = ?");
$me->execute([$user_id]);
$ME = $me->fetch(PDO::FETCH_ASSOC);
if (!$ME) sms_fail('Your user account could not be found.');
$MY_NAME = sms_person_name($ME['first_name'], $ME['last_name']);

try {
    switch ($action) {

        /* ================================================================
         * HEALTH — status bar: queue, cron worker, webhook, vault
         * ================================================================ */
        case 'health':
            $h = sms_vault_ready() ? sms_health($pdo) : ['vault_ok' => false];
            foreach (['oldest_queued_at', 'last_run_at', 'last_cron_at', 'last_webhook_at'] as $k) {
                if (isset($h[$k])) $h[$k . '_t'] = sms_t($h[$k]);
            }
            sms_json(['status'=>'success', 'health'=>$h, 'my_phone'=>$ME['phone'] ?? '']);

        /* ================================================================
         * SETTINGS — status only (never returns secrets)
         * ================================================================ */
        case 'settings_status':
            if (!sms_vault_ready()) sms_json(['status'=>'success','configured'=>false,'vault_ok'=>false,'masked'=>[],'my_phone'=>$ME['phone'] ?? '']);
            $failed = [];
            $settings = sms_load_settings($pdo, $failed);
            $configured = !empty($settings['api_token']);
            sms_json([
                'status' => 'success',
                'configured' => $configured,
                'vault_ok' => true,
                'decrypt_failed' => $failed,
                'masked' => [
                    'base_url'    => $settings['base_url'] ? preg_replace('#^(https?://[^/]+).*#', '$1/…', $settings['base_url']) : 'default (bulksmsnigeria.com)',
                    'api_token'   => $configured ? substr($settings['api_token'], 0, 4) . '••••••••' : '',
                    'sender_id'   => $settings['sender_id'] ? substr($settings['sender_id'], 0, 2) . '…' : '',
                    'gateway'     => $settings['gateway'] ?: 'direct-refund (default)',
                    'webhook_url' => $settings['webhook_url'] ? 'set' : 'not set',
                ],
                'my_phone' => $ME['phone'] ?? '',
            ]);

        /* ================================================================
         * SETTINGS — unlock with ERP login password to reveal secrets
         * (5 wrong passwords lock unlocking for 10 minutes)
         * ================================================================ */
        case 'unlock_settings':
            $lock = $_SESSION['sms_unlock_fail'] ?? ['n' => 0, 'at' => 0];
            if ($lock['n'] >= 5 && time() - $lock['at'] < 600) {
                sms_fail('Too many wrong passwords. Try again in ' . ceil((600 - (time() - $lock['at'])) / 60) . ' minute(s).');
            }
            $password = $_POST['password'] ?? '';
            if ($password === '' || !password_verify($password, (string)$ME['password_hash'])) {
                $_SESSION['sms_unlock_fail'] = ['n' => ($lock['n'] >= 5 ? 0 : $lock['n']) + 1, 'at' => time()];
                sms_fail('Incorrect password. Please try again.');
            }
            unset($_SESSION['sms_unlock_fail']);
            $_SESSION['sms_unlocked'] = time();
            $settings = sms_load_settings($pdo);
            $secret = sms_webhook_secret();
            $host = $_SERVER['HTTP_HOST'] ?? '';
            sms_json([
                'status' => 'success',
                'settings' => $settings,
                'webhook_suggestion' => ($secret !== '' && $host !== '') ? 'https://' . $host . '/api/sms_webhook.php?token=' . rawurlencode($secret) : '',
                'webhook_secret_set' => $secret !== '',
            ]);

        /* ================================================================
         * SETTINGS — lock (clear unlock flag)
         * ================================================================ */
        case 'lock_settings':
            unset($_SESSION['sms_unlocked']);
            sms_json(['status'=>'success','message'=>'Settings locked.']);

        /* ================================================================
         * SETTINGS — save (requires an active password unlock this session)
         * ================================================================ */
        case 'save_settings':
            if (empty($_SESSION['sms_unlocked']) || (time() - $_SESSION['sms_unlocked']) > 600) {
                sms_fail('Session locked. Re-enter your password to edit settings.');
            }
            $vals = [];
            foreach (SMS_KEYS as $k) $vals[$k] = trim((string)($_POST[$k] ?? ''));
            if ($vals['api_token'] === '') sms_fail('API token is required.');
            if ($vals['sender_id'] === '') sms_fail('Sender ID is required.');
            if (!preg_match('/^[A-Za-z0-9 .&\-]{3,11}$/', $vals['sender_id'])) {
                sms_fail('Sender ID must be 3–11 letters, numbers or spaces, exactly as approved on BulkSMS.');
            }
            // The API token travels as a Bearer header to this URL: never over plain HTTP.
            if ($vals['base_url'] !== '') {
                $u = parse_url($vals['base_url']);
                if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
                    sms_fail('Base URL must start with https:// (e.g. ' . SMS_DEFAULT_BASE . ').');
                }
            }
            if ($vals['gateway'] !== '' && !in_array($vals['gateway'], SMS_GATEWAYS, true)) sms_fail('Unknown gateway.');
            if ($vals['webhook_url'] !== '') {
                $u = parse_url($vals['webhook_url']);
                if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || empty($u['host'])) sms_fail('Webhook URL must start with https://.');
                if (strpos($vals['webhook_url'], 'sms_webhook.php') !== false && strpos($vals['webhook_url'], 'token=') === false) {
                    sms_fail('The webhook URL needs ?token=… or every delivery report will be rejected. Use "Use this site\'s webhook URL".');
                }
            }
            foreach ($vals as $k => $v) sms_save_setting($pdo, $k, $v, $user_id);
            sms_json(['status'=>'success','message'=>'Connection details saved & encrypted.']);

        /* ================================================================
         * SETTINGS — test connection (check balance)
         * Used to say "Connection OK" for any JSON reply, including a 401
         * for a wrong token ("Balance: ₦null").
         * ================================================================ */
        case 'test_connection':
            $failed = [];
            $settings = sms_load_settings($pdo, $failed);
            if (empty($settings['api_token'])) sms_fail($failed ? 'Stored credentials cannot be decrypted (SMS_VAULT_KEY changed?). Re-enter them.' : 'API token not configured.');
            $res = sms_http('GET', sms_base_url($settings) . '/balance', $settings['api_token']);
            if (!sms_http_ok($res)) sms_fail('Connection failed: ' . sms_api_error($res));
            $d = $res['data'];
            $bal = $d['balance']['total_balance'] ?? ($d['data']['balance'] ?? ($d['data']['total_balance'] ?? ($d['balance'] ?? null)));
            if (is_array($bal)) $bal = $bal['total_balance'] ?? ($bal['amount'] ?? null);
            sms_json(['status'=>'success','message'=>'Connection OK.' . (is_numeric($bal) ? ' Balance: ₦' . number_format((float)$bal, 2) : ' (balance not returned)'),'balance'=>$bal]);

        /* ================================================================
         * SETTINGS — list the account's approved sender IDs
         * ================================================================ */
        case 'list_sender_ids':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) sms_fail('API token not configured.');
            $res = sms_http('GET', sms_base_url($settings) . '/sender-ids', $settings['api_token']);
            if (!sms_http_ok($res)) sms_fail('Could not fetch sender IDs: ' . sms_api_error($res));
            $ids = $res['data']['data']['sender_ids'] ?? ($res['data']['sender_ids'] ?? ($res['data']['data'] ?? []));
            sms_json(['status'=>'success','data'=>is_array($ids) ? array_values($ids) : []]);

        /* ================================================================
         * AUDIENCE — events for the dropdowns
         * ================================================================ */
        case 'fetch_events':
            $rows = $pdo->query("SELECT id, title, event_date FROM events ORDER BY event_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['date_label'] = $r['event_date'] ? date('j M Y', strtotime($r['event_date'])) : '';
            unset($r);
            sms_json(['status'=>'success','data'=>$rows]);

        /* ================================================================
         * AUDIENCE — build recipient list
         * POST: audience = 'users' | 'registrations' | 'checkins'
         *       statuses[] (users) | event_id (registrations / checkins), search
         * ================================================================ */
        case 'fetch_recipients':
            $audience = $_POST['audience'] ?? 'users';
            $statuses = $_POST['statuses'] ?? [];
            $eventId  = (int)($_POST['event_id'] ?? 0);
            $search   = trim((string)($_POST['search'] ?? ''));
            if (!is_array($statuses)) $statuses = [];
            $statuses = array_values(array_filter(array_map('trim', $statuses)));

            if ($audience === 'registrations')  $res = sms_audience_registrations($pdo, $eventId ?: null, $search);
            elseif ($audience === 'checkins')   $res = sms_audience_checkins($pdo, $eventId ?: null, $search);
            else                                $res = sms_audience_users($pdo, $statuses, $search);
            sms_json([
                'status' => 'success', 'data' => $res['data'], 'count' => count($res['data']),
                'valid_count' => count($res['data']), 'invalid_count' => $res['dropped'],
                'shared_phones' => $res['duplicates'], 'truncated' => $res['truncated'], 'cap' => SMS_AUDIENCE_CAP,
            ]);

        /* ================================================================
         * SEND TEST — to the logged-in user's phone (or a typed number)
         * ================================================================ */
        case 'send_test':
            $failed = [];
            $settings = sms_load_settings($pdo, $failed);
            if (empty($settings['api_token'])) sms_fail($failed ? 'Stored BulkSMS credentials cannot be decrypted. Re-enter them in Settings.' : 'BulkSMS not configured yet. Go to Settings.');
            $template  = (string)($_POST['template'] ?? '');
            $audience  = (string)($_POST['audience'] ?? 'users');
            $eventId   = (int)($_POST['event_id'] ?? 0);
            $testPhone = trim((string)($_POST['test_phone'] ?? ''));
            if (trim($template) === '') sms_fail('Message is empty.');

            $recipient = [
                'source'=>'user', 'source_id'=>$ME['id'],
                'name'=>$MY_NAME, 'phone'=>$ME['phone'] ?? '',
                'first_name'=>$ME['first_name'] ?? '', 'last_name'=>$ME['last_name'] ?? '',
                'email'=>$ME['email'] ?? '', 'guest_name'=>'', 'event_title'=>'Test Message',
            ];
            if ($audience === 'registrations') $recipient['guest_name'] = $MY_NAME;
            if ($eventId) {
                $ev = $pdo->prepare("SELECT title FROM events WHERE id = ?");
                $ev->execute([$eventId]);
                $recipient['event_title'] = $ev->fetchColumn() ?: $recipient['event_title'];
            }
            // Testing the check-ins audience: pull the tester's own latest blessing_ref.
            // check-ins store the phone as typed (0803…), so match on the last 10 digits
            // — comparing with the normalized 234… form never matched anything.
            $myNorm = sms_normalize_phone($ME['phone'] ?? '');
            if ($audience === 'checkins' && $myNorm !== null) {
                $sql = "SELECT c.blessing_ref, e.title FROM checkins c LEFT JOIN events e ON e.id = c.event_id
                        WHERE c.blessing_ref IS NOT NULL AND c.blessing_ref <> ''
                          AND REPLACE(REPLACE(REPLACE(REPLACE(c.phone,' ',''),'-',''),'+',''),'(','') LIKE ?"
                     . ($eventId ? " AND c.event_id = ?" : "") . " ORDER BY c.id DESC LIMIT 1";
                $bi = $pdo->prepare($sql);
                $bi->execute($eventId ? ['%' . substr($myNorm, -10), $eventId] : ['%' . substr($myNorm, -10)]);
                if ($b = $bi->fetch(PDO::FETCH_ASSOC)) {
                    $recipient['blessing_ref'] = $b['blessing_ref'];
                    if ($b['title']) $recipient['event_title'] = $b['title'];
                } else {
                    $recipient['blessing_ref'] = 'Numbers 6:24';   // sample so the test shows where the verse goes
                }
            }
            $targetLabel = $ME['phone'] ?? 'you';
            if ($testPhone !== '') {
                $norm = sms_normalize_phone($testPhone);
                if ($norm === null) sms_fail('Invalid test phone number: ' . $testPhone);
                $recipient['phone'] = $norm;
                $targetLabel = $norm;
            } elseif ($myNorm === null) {
                sms_fail('Your profile has no valid Nigerian phone number. Type a number in "Send test to this number".');
            }

            $r = sms_send_one($pdo, $settings, $recipient, $template, null, $user_id, ['source' => 'studio']);
            if ($r['status'] === 'sent') sms_json(['status'=>'success','message'=>'Test message sent to ' . $targetLabel . '. Watch it arrive in History.','log_id'=>$r['log_id']]);
            if ($r['status'] === 'unknown') sms_json(['status'=>'error','message'=>'Test outcome unknown: ' . $r['error'],'log_id'=>$r['log_id']]);
            sms_json(['status'=>'error','message'=>'Test failed: ' . ($r['error'] ?? 'Unknown error'),'log_id'=>$r['log_id']]);

        /* ================================================================
         * SEND CAMPAIGN — the whole selection in ONE request.
         * The composer used to post batches of 10, and each batch created
         * its own campaign row (a 500-person send became 50 "campaigns"),
         * then reported every queued batch as "sent".
         * recipients = JSON [{source, source_id, name, phone, first_name, ...}]
         * ================================================================ */
        case 'send_campaign':
            $failed = [];
            $settings = sms_load_settings($pdo, $failed);
            if (empty($settings['api_token'])) sms_fail($failed ? 'Stored BulkSMS credentials cannot be decrypted. Re-enter them in Settings.' : 'BulkSMS not configured yet. Go to Settings.');
            $template = trim((string)($_POST['template'] ?? ''));
            if ($template === '') sms_fail('Message is empty.');

            // Double-click / network-retry protection: one campaign per compose token.
            $clientToken = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_POST['client_token'] ?? ''));
            $_SESSION['sms_sent_tokens'] = array_slice($_SESSION['sms_sent_tokens'] ?? [], -20, 20, true);
            if ($clientToken !== '' && isset($_SESSION['sms_sent_tokens'][$clientToken])) {
                sms_json(['status'=>'success','duplicate'=>true,'campaign_id'=>$_SESSION['sms_sent_tokens'][$clientToken],
                          'message'=>'This campaign was already queued — not sending it twice.']);
            }

            $recipients = json_decode((string)($_POST['recipients'] ?? '[]'), true);
            if (!is_array($recipients) || count($recipients) === 0) sms_fail('No recipients selected.');
            if (count($recipients) > SMS_MAX_CAMPAIGN) sms_fail('At most ' . SMS_MAX_CAMPAIGN . ' recipients per campaign. Split the audience.');

            // Re-validate on the server: normalize, drop invalid numbers, one message per number.
            $clean = []; $seen = []; $invalid = 0; $dupes = 0; $units = 0;
            foreach ($recipients as $rec) {
                if (!is_array($rec)) { $invalid++; continue; }
                $ph = sms_normalize_phone($rec['phone'] ?? '');
                if ($ph === null) { $invalid++; continue; }
                if (isset($seen[$ph])) { $dupes++; continue; }
                $seen[$ph] = true;
                $row = [
                    'source'    => in_array($rec['source'] ?? '', ['user', 'registration', 'checkin'], true) ? $rec['source'] : 'manual',
                    'source_id' => isset($rec['source_id']) && is_numeric($rec['source_id']) ? (int)$rec['source_id'] : null,
                    'phone'     => $ph,
                ];
                foreach (['name', 'first_name', 'last_name', 'guest_name', 'email', 'event_title', 'blessing_ref'] as $k) {
                    $row[$k] = sms_cut(is_scalar($rec[$k] ?? null) ? (string)$rec[$k] : '', 190);
                }
                $units += sms_segments(sms_render($template, $row))['pages'];
                $clean[] = $row;
            }
            if (!$clean) sms_fail('None of the selected numbers is a valid Nigerian mobile number.');

            $title    = sms_cut(trim((string)($_POST['title'] ?? '')), 200) ?: ('Bulk SMS – ' . date('j M Y, g:i a'));
            $audience = in_array($_POST['audience'] ?? '', ['users', 'registrations', 'checkins'], true) ? $_POST['audience'] : 'users';
            $eventId  = (int)($_POST['event_id'] ?? 0);
            // Check-in campaigns now keep their event too, so the Event Report counts them.
            $eventId  = ($audience !== 'users' && $eventId > 0) ? $eventId : null;
            $filters  = (string)($_POST['filters'] ?? '');
            $filters  = ($filters !== '' && is_array(json_decode($filters, true))) ? sms_cut($filters, 2000) : null;

            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO sms_campaigns
                    (title, audience, event_id, filters_json, body_template, total, status, created_by)
                    VALUES (?,?,?,?,?,?,'queued',?)")
                    ->execute([$title, $audience, $eventId, $filters, $template, count($clean), $user_id]);
                $campaignId = (int)$pdo->lastInsertId();
                foreach (array_chunk($clean, 200) as $chunk) {
                    $vals = []; $args = [];
                    foreach ($chunk as $rec) { $vals[] = "(?, ?, 'queued')"; $args[] = $campaignId; $args[] = json_encode($rec, JSON_UNESCAPED_UNICODE); }
                    $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES " . implode(',', $vals))->execute($args);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            if ($clientToken !== '') $_SESSION['sms_sent_tokens'][$clientToken] = $campaignId;

            $h = sms_health($pdo);
            $cronOk = $h['cron_age_sec'] !== null && $h['cron_age_sec'] < 180;
            sms_json([
                'status' => 'success',
                'message' => count($clean) . ' message(s) queued for sending'
                           . ($invalid ? ", {$invalid} invalid number(s) skipped" : '')
                           . ($dupes ? ", {$dupes} duplicate number(s) merged" : '') . '.',
                'campaign_id' => $campaignId, 'queued' => count($clean), 'invalid' => $invalid,
                'duplicates' => $dupes, 'est_units' => $units, 'cron_ok' => $cronOk,
            ]);

        /* ================================================================
         * SEND NOW — run the queue from this request (when cron has stopped)
         * ================================================================ */
        case 'process_queue':
            ignore_user_abort(true);   // closing the tab must not cut a send in half
            @set_time_limit(90);
            $r = sms_process_queue($pdo, 25, 'web');
            sms_json(['status'=>'success','result'=>$r,'message'=>$r['locked'] ? 'Already sending in the background…' : $r['message']]);

        /* ================================================================
         * CAMPAIGNS — list (legacy chunked sends are grouped back together:
         * same title + sender + message on the same day = one campaign)
         * ================================================================ */
        case 'list_campaigns':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $per = 15; $off = ($page - 1) * $per;
            $pdo->exec("SET SESSION group_concat_max_len = 65535");
            $groupBy = "GROUP BY c.title, c.created_by, MD5(c.body_template), DATE(c.created_at)";
            $total = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM sms_campaigns c $groupBy) t")->fetchColumn();
            $groups = $pdo->query("SELECT MIN(c.id) AS id, GROUP_CONCAT(c.id ORDER BY c.id) AS ids, c.title, c.created_by,
                        MAX(c.audience) AS audience, MAX(c.event_id) AS event_id, SUM(c.total) AS total,
                        MIN(c.created_at) AS created_at, COUNT(*) AS parts, MAX(c.body_template) AS body_template,
                        GROUP_CONCAT(DISTINCT c.status) AS statuses
                    FROM sms_campaigns c $groupBy
                    ORDER BY MIN(c.id) DESC LIMIT $per OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
            $all = [];
            foreach ($groups as $g) foreach (explode(',', $g['ids']) as $i) $all[] = (int)$i;
            $q = []; $l = [];
            if ($all) {
                $st = $pdo->prepare("SELECT campaign_id, status, COUNT(*) n FROM sms_queue WHERE campaign_id IN (" . sms_in($all) . ") GROUP BY campaign_id, status");
                $st->execute($all);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $q[$r['campaign_id']][$r['status']] = (int)$r['n'];
                $st = $pdo->prepare("SELECT campaign_id, status, COUNT(*) n, COALESCE(SUM(cost),0) cost, MAX(created_at) last_at
                                     FROM sms_log WHERE campaign_id IN (" . sms_in($all) . ") GROUP BY campaign_id, status");
                $st->execute($all);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $l[$r['campaign_id']][$r['status']] = $r;
            }
            $users = []; $events = [];
            $uids = array_values(array_unique(array_filter(array_column($groups, 'created_by'))));
            if ($uids) { $st = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id IN (" . sms_in($uids) . ")"); $st->execute($uids); foreach ($st->fetchAll() as $u) $users[$u['id']] = sms_person_name($u['first_name'], $u['last_name']); }
            $eids = array_values(array_unique(array_filter(array_column($groups, 'event_id'))));
            if ($eids) { $st = $pdo->prepare("SELECT id, title FROM events WHERE id IN (" . sms_in($eids) . ")"); $st->execute($eids); foreach ($st->fetchAll() as $e) $events[$e['id']] = $e['title']; }

            $out = [];
            foreach ($groups as $g) {
                $qs = ['queued'=>0,'sending'=>0,'done'=>0,'error'=>0,'cancelled'=>0];
                $ls = ['queued'=>0,'sent'=>0,'pending'=>0,'delivered'=>0,'failed'=>0,'blocked'=>0,'unknown'=>0];
                $cost = 0.0; $last = null;
                foreach (explode(',', $g['ids']) as $cid) {
                    foreach ($q[$cid] ?? [] as $s => $n) $qs[$s] = ($qs[$s] ?? 0) + $n;
                    foreach ($l[$cid] ?? [] as $s => $r) {
                        $ls[$s] = ($ls[$s] ?? 0) + (int)$r['n'];
                        $cost += (float)$r['cost'];
                        if ($r['last_at'] > $last) $last = $r['last_at'];
                    }
                }
                $statuses = explode(',', (string)$g['statuses']);
                $state = ($qs['queued'] + $qs['sending']) > 0 ? ($qs['sending'] > 0 || $qs['done'] + $qs['error'] > 0 ? 'sending' : 'queued')
                       : (in_array('cancelled', $statuses, true) ? 'cancelled' : 'sent');
                $out[] = [
                    'id' => (int)$g['id'], 'ids' => $g['ids'], 'parts' => (int)$g['parts'], 'title' => $g['title'],
                    'audience' => $g['audience'], 'event_title' => $events[$g['event_id']] ?? null,
                    'total' => (int)$g['total'], 'state' => $state, 'queue' => $qs, 'log' => $ls,
                    'cost' => round($cost, 2), 'body' => $g['body_template'],
                    'created_by_name' => $users[$g['created_by']] ?? null,
                    'created' => sms_t($g['created_at']), 'last_sent' => sms_t($last),
                ];
            }
            sms_json(['status'=>'success','data'=>$out,'page'=>$page,'pages'=>max(1, (int)ceil($total / $per)),'total'=>$total]);

        /* ================================================================
         * CAMPAIGNS — live progress of one campaign (ids = its parts)
         * ================================================================ */
        case 'campaign_status':
            $ids = sms_ids_param($_POST['ids'] ?? ($_POST['id'] ?? ''));
            if (!$ids) sms_fail('Campaign not found.');
            $in = sms_in($ids);
            $st = $pdo->prepare("SELECT c.*, e.title AS event_title, u.first_name, u.last_name FROM sms_campaigns c
                                 LEFT JOIN events e ON e.id = c.event_id LEFT JOIN users u ON u.id = c.created_by
                                 WHERE c.id IN ($in) ORDER BY c.id");
            $st->execute($ids);
            $camps = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$camps) sms_fail('Campaign not found.');
            $st = $pdo->prepare("SELECT status, COUNT(*) n FROM sms_queue WHERE campaign_id IN ($in) GROUP BY status");
            $st->execute($ids);
            $qs = ['queued'=>0,'sending'=>0,'done'=>0,'error'=>0,'cancelled'=>0];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $qs[$r['status']] = (int)$r['n'];
            $st = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(cost),0) cost, MIN(created_at) first_at, MAX(created_at) last_at
                                 FROM sms_log WHERE campaign_id IN ($in) GROUP BY status");
            $st->execute($ids);
            $ls = ['queued'=>0,'sent'=>0,'pending'=>0,'delivered'=>0,'failed'=>0,'blocked'=>0,'unknown'=>0];
            $cost = 0.0; $first = null; $last = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ls[$r['status']] = (int)$r['n'];
                $cost += (float)$r['cost'];
                if ($first === null || $r['first_at'] < $first) $first = $r['first_at'];
                if ($r['last_at'] > $last) $last = $r['last_at'];
            }
            $c0 = $camps[0];
            $total = array_sum(array_map('intval', array_column($camps, 'total')));
            $remaining = $qs['queued'] + $qs['sending'];
            $h = sms_health($pdo);
            sms_json(['status'=>'success','campaign'=>[
                'ids' => implode(',', $ids), 'title' => $c0['title'], 'audience' => $c0['audience'],
                'event_title' => $c0['event_title'], 'body' => $c0['body_template'], 'parts' => count($camps),
                'created_by_name' => sms_person_name($c0['first_name'], $c0['last_name']),
                'created' => sms_t($c0['created_at']), 'first_sent' => sms_t($first), 'last_sent' => sms_t($last),
                'total' => $total, 'remaining' => $remaining, 'queue' => $qs, 'log' => $ls, 'cost' => round($cost, 2),
                'cancelled' => in_array('cancelled', array_column($camps, 'status'), true),
                'filters' => json_decode((string)$c0['filters_json'], true),
            ], 'cron_ok' => $h['cron_age_sec'] !== null && $h['cron_age_sec'] < 180, 'cron_age_sec' => $h['cron_age_sec']]);

        /* ================================================================
         * CAMPAIGNS — stop everything not yet sent
         * ================================================================ */
        case 'cancel_campaign':
            if (!sms_schema_ready($pdo)) sms_fail('Run the database migrations first (db/migrate.php) to enable cancelling.');
            $ids = sms_ids_param($_POST['ids'] ?? ($_POST['id'] ?? ''));
            if (!$ids) sms_fail('Campaign not found.');
            $in = sms_in($ids);
            $why = 'Cancelled by ' . $MY_NAME . ' on ' . date('D j M Y, g:i a') . ' WAT';
            $st = $pdo->prepare("UPDATE sms_queue SET status = 'cancelled', error_message = ? WHERE campaign_id IN ($in) AND status = 'queued'");
            $st->execute(array_merge([$why], $ids));
            $n = $st->rowCount();
            $pdo->prepare("UPDATE sms_campaigns SET status = 'cancelled' WHERE id IN ($in) AND status IN ('queued','sending')")->execute($ids);
            sms_json(['status'=>'success','message'=> $n ? "Stopped {$n} message(s) that had not been sent yet." : 'Nothing left to stop — every message had already been sent.']);

        /* ================================================================
         * HISTORY — paginated send log with filters + per-status counts
         * POST: page, search, status (delivered|awaiting|failed|blocked|unknown),
         *       campaign_ids, phone, date_from, date_to (Y-m-d)
         * ================================================================ */
        case 'get_history':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $per  = 50;
            $off  = ($page - 1) * $per;
            $where = ["1=1"]; $params = [];
            $search = trim((string)($_POST['search'] ?? ''));
            if ($search !== '') {
                $where[] = "(l.recipient_phone LIKE ? OR l.recipient_name LIKE ? OR l.body LIKE ? OR l.message_id = ?)";
                $digits = preg_replace('/\D/', '', $search);
                $params[] = '%' . (strlen($digits) >= 7 ? substr($digits, -10) : $search) . '%';
                $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = $search;
            }
            $cids = sms_ids_param($_POST['campaign_ids'] ?? '');
            if ($cids) { $where[] = "l.campaign_id IN (" . sms_in($cids) . ")"; $params = array_merge($params, $cids); }
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($phone !== '') { $where[] = "l.recipient_phone = ?"; $params[] = sms_normalize_phone($phone) ?? $phone; }
            foreach (['date_from' => '>=', 'date_to' => '<'] as $k => $op) {
                $v = (string)($_POST[$k] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $where[] = "l.created_at {$op} ?";
                    $params[] = $k === 'date_to' ? date('Y-m-d', strtotime($v . ' +1 day')) : $v;
                }
            }
            $groups = ['delivered' => ['delivered'], 'awaiting' => ['queued', 'sent', 'pending'], 'failed' => ['failed'],
                       'blocked' => ['blocked'], 'unknown' => ['unknown']];
            $baseWhere = implode(' AND ', $where); $baseParams = $params;
            $sf = (string)($_POST['status'] ?? '');
            if (isset($groups[$sf])) {
                $where[] = "l.status IN (" . sms_in($groups[$sf]) . ")";
                $params = array_merge($params, $groups[$sf]);
            }
            $whereSql = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM sms_log l WHERE $whereSql");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));

            $st = $pdo->prepare("SELECT l.status, COUNT(*) n, COALESCE(SUM(l.cost),0) cost FROM sms_log l WHERE $baseWhere GROUP BY l.status");
            $st->execute($baseParams);
            $counts = ['delivered'=>0,'awaiting'=>0,'failed'=>0,'blocked'=>0,'unknown'=>0,'all'=>0]; $spend = 0.0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach ($groups as $gk => $gs) if (in_array($r['status'], $gs, true)) $counts[$gk] += (int)$r['n'];
                $counts['all'] += (int)$r['n'];
                $spend += (float)$r['cost'];
            }

            $st = $pdo->prepare("SELECT l.id, l.campaign_id, l.source_type, l.recipient_phone, l.recipient_name, l.body, l.status,
                           l.api_code, l.message_id, l.cost, l.error_message, l.created_at, l.updated_at, l.created_by,
                           c.title AS campaign_title, u.first_name AS sent_by_fname, u.last_name AS sent_by_lname
                    FROM sms_log l
                    LEFT JOIN sms_campaigns c ON c.id = l.campaign_id
                    LEFT JOIN users u ON u.id = l.created_by
                    WHERE $whereSql ORDER BY l.id DESC LIMIT $per OFFSET $off");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Final-status time of each row from its timeline (delivered / failed / unknown at …)
            $final = [];
            $ids = array_map('intval', array_column($rows, 'id'));
            if ($ids && sms_table_exists($pdo, 'sms_status_events')) {
                $ev = $pdo->prepare("SELECT sms_log_id, MAX(created_at) last_at,
                        MAX(CASE WHEN to_status IN ('delivered','failed','unknown','blocked') THEN created_at END) final_at
                        FROM sms_status_events WHERE sms_log_id IN (" . sms_in($ids) . ") GROUP BY sms_log_id");
                $ev->execute($ids);
                foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) $final[$e['sms_log_id']] = $e;
            }
            foreach ($rows as &$r) {
                $r['sent_by'] = sms_person_name($r['sent_by_fname'], $r['sent_by_lname']) ?: ($r['campaign_id'] ? 'Queue worker' : null);
                $r['created'] = sms_t($r['created_at']);
                $fin = $final[$r['id']]['final_at'] ?? (in_array($r['status'], ['delivered', 'failed', 'unknown'], true) ? $r['updated_at'] : null);
                $r['final'] = sms_t($fin);
                $r['updated'] = sms_t($final[$r['id']]['last_at'] ?? $r['updated_at']);
                $r['body_preview'] = sms_cut($r['body'], 90);
                unset($r['body'], $r['sent_by_fname'], $r['sent_by_lname']);
            }
            unset($r);
            sms_json(['status'=>'success','data'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,
                      'counts'=>$counts,'spend'=>round($spend, 2),'now'=>sms_t(date('Y-m-d H:i:s'))]);

        /* ================================================================
         * MESSAGE DETAIL — everything about one message: who, what, which
         * campaign, every status movement with its exact time, the raw
         * BulkSMS answer and callbacks, and this person's other messages.
         * ================================================================ */
        case 'get_message':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT l.*, c.title AS campaign_title, c.audience AS campaign_audience, c.created_at AS campaign_created_at,
                                        c.status AS campaign_status, c.event_id AS campaign_event_id, e.title AS event_title,
                                        u.first_name AS sent_by_fname, u.last_name AS sent_by_lname
                                 FROM sms_log l
                                 LEFT JOIN sms_campaigns c ON c.id = l.campaign_id
                                 LEFT JOIN events e ON e.id = c.event_id
                                 LEFT JOIN users u ON u.id = l.created_by
                                 WHERE l.id = ?");
            $st->execute([$id]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m) sms_fail('Message not found.');

            // Timeline
            $timeline = [];
            if (sms_table_exists($pdo, 'sms_status_events')) {
                $ev = $pdo->prepare("SELECT e.*, u.first_name, u.last_name FROM sms_status_events e
                                     LEFT JOIN users u ON u.id = e.created_by WHERE e.sms_log_id = ? ORDER BY e.id");
                $ev->execute([$id]);
                foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $timeline[] = [
                        'from' => $e['from_status'], 'to' => $e['to_status'], 'source' => $e['source'],
                        'raw' => $e['raw_status'], 'code' => $e['api_code'], 'detail' => $e['detail'],
                        'provider_time' => $e['provider_time'], 'by' => sms_person_name($e['first_name'], $e['last_name']) ?: null,
                        'at' => sms_t($e['created_at']), 'synthetic' => false,
                    ];
                }
            }
            $hasCreation = false;
            foreach ($timeline as $e) if ($e['from'] === null) { $hasCreation = true; break; }
            if (!$hasCreation) {
                // Sent before status tracking existed: rebuild what the row itself proves.
                array_unshift($timeline, ['from' => null, 'to' => $m['message_id'] ? 'sent' : $m['status'], 'source' => $m['campaign_id'] ? 'worker' : 'studio',
                               'raw' => null, 'code' => $m['api_code'], 'provider_time' => null, 'by' => null,
                               'detail' => ($m['message_id'] ? 'Accepted by BulkSMS · message ID ' . $m['message_id'] : $m['error_message'])
                                           . ' (sent before step-by-step tracking began)',
                               'at' => sms_t($m['created_at']), 'synthetic' => true]);
                if (count($timeline) === 1 && $m['message_id'] && $m['status'] !== 'sent') {
                    $timeline[] = ['from' => 'sent', 'to' => $m['status'], 'source' => 'system', 'raw' => $m['error_message'],
                                   'code' => null, 'provider_time' => null, 'by' => null, 'detail' => 'Latest status (time of change not recorded before tracking began)',
                                   'at' => sms_t($m['updated_at'] ?: null), 'synthetic' => true];
                }
            }

            // BulkSMS callbacks for this message
            $hooks = [];
            if ($m['message_id'] && sms_table_exists($pdo, 'sms_webhook_log')) {
                $tcol = sms_column_exists($pdo, 'sms_webhook_log', 'received_at') ? 'received_at'
                      : (sms_column_exists($pdo, 'sms_webhook_log', 'created_at') ? 'created_at' : null);
                $wh = $pdo->prepare("SELECT id, " . ($tcol ? "$tcol AS at, " : "NULL AS at, ") . "remote_ip, parsed_status, applied_status, raw_body
                                     FROM sms_webhook_log WHERE message_id = ? ORDER BY id LIMIT 50");
                $wh->execute([$m['message_id']]);
                foreach ($wh->fetchAll(PDO::FETCH_ASSOC) as $w) {
                    $w['at'] = sms_t($w['at']);
                    $w['raw_body'] = sms_cut($w['raw_body'], 2000);
                    $hooks[] = $w;
                }
            }

            // Queue job behind it
            $job = null;
            if ($m['campaign_id'] && sms_column_exists($pdo, 'sms_queue', 'log_id')) {
                $qj = $pdo->prepare("SELECT id, status, sent_status, error_message, locked_at FROM sms_queue WHERE log_id = ? LIMIT 1");
                $qj->execute([$id]);
                if ($job = $qj->fetch(PDO::FETCH_ASSOC)) $job['locked'] = sms_t($job['locked_at']);
            }

            // Who this is
            $person = null;
            $pid = (int)$m['source_id'];
            if ($m['source_type'] === 'user' && $pid) {
                $p = $pdo->prepare("SELECT id, first_name, last_name, email, phone, spiritual_status FROM users WHERE id = ?");
                $p->execute([$pid]);
                if ($u = $p->fetch(PDO::FETCH_ASSOC)) $person = ['kind' => 'Member / contact', 'name' => sms_person_name($u['first_name'], $u['last_name']),
                    'email' => $u['email'], 'phone_on_file' => $u['phone'], 'status' => $u['spiritual_status'], 'user_id' => (int)$u['id']];
            } elseif ($m['source_type'] === 'registration' && $pid) {
                $p = $pdo->prepare("SELECT r.guest_name, r.guest_email, r.guest_phone, r.registered_at, e.title FROM event_registrations r LEFT JOIN events e ON e.id = r.event_id WHERE r.id = ?");
                $p->execute([$pid]);
                if ($u = $p->fetch(PDO::FETCH_ASSOC)) $person = ['kind' => 'Event registrant', 'name' => $u['guest_name'], 'email' => $u['guest_email'],
                    'phone_on_file' => $u['guest_phone'], 'status' => 'Registered for ' . ($u['title'] ?: 'an event') . ' on ' . (sms_t($u['registered_at'])['label'] ?? '?')];
            } elseif ($m['source_type'] === 'checkin' && $pid) {
                $p = $pdo->prepare("SELECT c.full_name, c.phone, c.blessing_ref, c.checkin_date, e.title FROM checkins c LEFT JOIN events e ON e.id = c.event_id WHERE c.id = ?");
                $p->execute([$pid]);
                if ($u = $p->fetch(PDO::FETCH_ASSOC)) $person = ['kind' => 'Event attendee (check-in)', 'name' => $u['full_name'], 'email' => null,
                    'phone_on_file' => $u['phone'], 'status' => 'Checked in to ' . ($u['title'] ?: 'an event') . ($u['checkin_date'] ? ' on ' . date('j M Y', strtotime($u['checkin_date'])) : '') . ($u['blessing_ref'] ? ' · verse ' . $u['blessing_ref'] : '')];
            }

            // This number's messaging record
            $sum = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(cost),0) cost FROM sms_log WHERE recipient_phone = ? GROUP BY status");
            $sum->execute([$m['recipient_phone']]);
            $pc = []; $pcost = 0.0; $ptotal = 0;
            foreach ($sum->fetchAll(PDO::FETCH_ASSOC) as $r) { $pc[$r['status']] = (int)$r['n']; $pcost += (float)$r['cost']; $ptotal += (int)$r['n']; }
            $supp = null;
            if (sms_table_exists($pdo, 'sms_suppression')) {
                $sp = $pdo->prepare("SELECT id, reason, suppressed_at, released_at FROM sms_suppression WHERE phone = ? ORDER BY id DESC LIMIT 1");
                $sp->execute([$m['recipient_phone']]);
                if ($supp = $sp->fetch(PDO::FETCH_ASSOC)) { $supp['suppressed'] = sms_t($supp['suppressed_at']); $supp['released'] = sms_t($supp['released_at']); }
            }

            $seg = sms_segments((string)$m['body']);
            $raw = json_decode((string)$m['raw_response'], true);
            $canResend = !in_array($m['status'], ['queued'], true) && $m['body'] !== null && $m['body'] !== '';
            sms_json(['status'=>'success','message'=>[
                'id' => (int)$m['id'], 'status' => $m['status'], 'recipient_name' => $m['recipient_name'],
                'recipient_phone' => $m['recipient_phone'], 'body' => $m['body'], 'segments' => $seg,
                'sender_id' => $m['sender_id'], 'gateway' => $m['gateway_used'], 'message_id' => $m['message_id'],
                'api_code' => $m['api_code'], 'api_code_text' => $m['api_code'] ? sms_explain_code($m['api_code']) : null,
                'cost' => $m['cost'], 'units' => $m['units'], 'error_message' => $m['error_message'],
                'raw_response' => is_array($raw) ? json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $m['raw_response'],
                'source_type' => $m['source_type'], 'source_id' => $m['source_id'],
                'campaign' => $m['campaign_id'] ? ['id' => (int)$m['campaign_id'], 'title' => $m['campaign_title'], 'audience' => $m['campaign_audience'],
                                                   'status' => $m['campaign_status'], 'event_title' => $m['event_title'], 'created' => sms_t($m['campaign_created_at'])] : null,
                'sent_by' => sms_person_name($m['sent_by_fname'], $m['sent_by_lname']) ?: null,
                'created' => sms_t($m['created_at']), 'updated' => sms_t($m['updated_at']),
                'dlr_checked' => sms_t($m['dlr_checked_at'] ?? null),
                'timeline' => $timeline, 'webhooks' => $hooks, 'queue_job' => $job, 'person' => $person,
                'number' => ['total' => $ptotal, 'by_status' => $pc, 'cost' => round($pcost, 2), 'suppression' => $supp],
                'can_resend' => $canResend,
            ]]);

        /* ================================================================
         * PERSON — every message ever sent to one number
         * ================================================================ */
        case 'get_person':
            $raw = trim((string)($_POST['phone'] ?? ''));
            $phone = sms_normalize_phone($raw) ?? preg_replace('/\D/', '', $raw);
            if ($phone === '') sms_fail('Phone number required.');
            $st = $pdo->prepare("SELECT l.id, l.status, l.recipient_name, l.body, l.cost, l.message_id, l.error_message, l.created_at, l.updated_at,
                                        l.campaign_id, c.title AS campaign_title, u.first_name, u.last_name
                                 FROM sms_log l LEFT JOIN sms_campaigns c ON c.id = l.campaign_id LEFT JOIN users u ON u.id = l.created_by
                                 WHERE l.recipient_phone = ? ORDER BY l.id DESC LIMIT 200");
            $st->execute([$phone]);
            $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
            $final = [];
            $ids = array_map('intval', array_column($msgs, 'id'));
            if ($ids && sms_table_exists($pdo, 'sms_status_events')) {
                $ev = $pdo->prepare("SELECT sms_log_id, MAX(CASE WHEN to_status IN ('delivered','failed','unknown','blocked') THEN created_at END) final_at
                                     FROM sms_status_events WHERE sms_log_id IN (" . sms_in($ids) . ") GROUP BY sms_log_id");
                $ev->execute($ids);
                foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) $final[$e['sms_log_id']] = $e['final_at'];
            }
            $counts = []; $cost = 0.0; $names = [];
            foreach ($msgs as &$r) {
                $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
                $cost += (float)$r['cost'];
                if ($r['recipient_name']) $names[$r['recipient_name']] = true;
                $r['created'] = sms_t($r['created_at']);
                $r['final'] = sms_t($final[$r['id']] ?? (in_array($r['status'], ['delivered', 'failed', 'unknown'], true) ? $r['updated_at'] : null));
                $r['sent_by'] = sms_person_name($r['first_name'], $r['last_name']) ?: null;
                unset($r['first_name'], $r['last_name']);
            }
            unset($r);
            // People on file with this number (numbers are stored as typed, so match the last 10 digits)
            $people = [];
            if (strlen($phone) >= 10) {
                $pp = $pdo->prepare("SELECT id, first_name, last_name, email, spiritual_status FROM users
                                     WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(','') LIKE ? LIMIT 5");
                $pp->execute(['%' . substr($phone, -10)]);
                foreach ($pp->fetchAll(PDO::FETCH_ASSOC) as $u) $people[] = ['name' => sms_person_name($u['first_name'], $u['last_name']), 'email' => $u['email'], 'status' => $u['spiritual_status']];
            }
            $supp = null;
            if (sms_table_exists($pdo, 'sms_suppression')) {
                $sp = $pdo->prepare("SELECT id, reason, consecutive_failures, suppressed_at, released_at FROM sms_suppression WHERE phone = ? ORDER BY id DESC LIMIT 1");
                $sp->execute([$phone]);
                if ($supp = $sp->fetch(PDO::FETCH_ASSOC)) { $supp['suppressed'] = sms_t($supp['suppressed_at']); $supp['released'] = sms_t($supp['released_at']); }
            }
            sms_json(['status'=>'success','person'=>[
                'phone' => $phone, 'names' => array_keys($names), 'people' => $people, 'messages' => $msgs,
                'counts' => $counts, 'total' => count($msgs), 'cost' => round($cost, 2), 'suppression' => $supp,
                'first' => $msgs ? sms_t(end($msgs)['created_at']) : null, 'last' => $msgs ? sms_t($msgs[0]['created_at']) : null,
            ]]);

        /* ================================================================
         * CHECK DELIVERY — ask BulkSMS for one message's report now
         * ================================================================ */
        case 'check_delivery':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) sms_fail('BulkSMS not configured.');
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sms_log WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_fail('Log entry not found.');
            if (!empty($row['dlr_checked_at']) && time() - strtotime($row['dlr_checked_at']) < 15) {
                sms_json(['status'=>'success','message'=>'Checked a few seconds ago — status: ' . $row['status'] . '.','delivery_status'=>$row['status'],'raw_status'=>null]);
            }
            $r = sms_poll_delivery($pdo, $settings, $row, 'poll', $user_id);
            if (!$r['ok']) sms_fail($r['error']);
            $label = in_array($r['status'], ['sent', 'pending'], true) ? 'awaiting delivery confirmation' : $r['status'];
            sms_json([
                'status' => 'success',
                'message' => 'Delivery status: ' . ucfirst($label) . ($r['raw'] ? ' (BulkSMS says: ' . $r['raw'] . ')' : ' (no report from the carrier yet)'),
                'delivery_status' => $r['status'], 'raw_status' => $r['raw'], 'changed' => $r['changed'],
            ]);

        /* ================================================================
         * REFRESH STATUSES — the History tab's quiet background check.
         * One request for all visible awaiting rows (was one request per
         * row every 15 s per open tab), each row at most every 45 s.
         * ================================================================ */
        case 'refresh_statuses':
            $ids = sms_ids_param($_POST['ids'] ?? '', 20);
            if (!$ids) sms_json(['status'=>'success','changed'=>0]);
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) sms_json(['status'=>'success','changed'=>0]);
            $hasChk = sms_column_exists($pdo, 'sms_log', 'dlr_checked_at');
            $st = $pdo->prepare("SELECT * FROM sms_log WHERE id IN (" . sms_in($ids) . ") AND status IN ('sent','pending')
                                   AND message_id IS NOT NULL AND message_id <> ''"
                                 . ($hasChk ? " AND (dlr_checked_at IS NULL OR dlr_checked_at < (NOW() - INTERVAL 45 SECOND))" : "")
                                 . " ORDER BY id DESC LIMIT 8");
            $st->execute($ids);
            $changed = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $r = sms_poll_delivery($pdo, $settings, $row, 'poll');
                if ($r['changed']) $changed++;
            }
            sms_json(['status'=>'success','changed'=>$changed]);

        /* ================================================================
         * RESEND — resend a single logged message to the same person
         * ================================================================ */
        case 'resend_recipient':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) sms_fail('BulkSMS not configured.');
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sms_log WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_fail('Log entry not found.');
            if ($row['status'] === 'queued') sms_fail('This message is being handed to BulkSMS right now. Wait for its result before resending.');
            if ($row['status'] === 'unknown' && empty($_POST['confirm_unknown'])) {
                sms_json(['status'=>'confirm','message'=>'We never learned whether this message went out. Check the BulkSMS dashboard first — resending may give this person the same message twice. Resend anyway?']);
            }
            $name = sms_clean_name($row['recipient_name']);
            $recipient = [
                'source'=>$row['source_type'], 'source_id'=>$row['source_id'],
                'name'=>$name, 'phone'=>$row['recipient_phone'],
                'first_name'=>explode(' ', $name)[0] ?? '', 'last_name'=>'',
                'email'=>'', 'guest_name'=>$name, 'event_title'=>'',
            ];
            $r = sms_send_one($pdo, $settings, $recipient, (string)$row['body'], $row['campaign_id'], $user_id, [
                'source' => 'studio',
                // A message whose fate is unknown would otherwise be refused as its own duplicate.
                'skip_duplicate' => $row['status'] === 'unknown',
            ]);
            // from === to marks a note on the timeline rather than a status change
            sms_log_event($pdo, $r['log_id'], $r['status'], $r['status'], 'studio', ['detail' => 'This is a resend of message #' . $row['id'] . ', requested by ' . $MY_NAME . '.', 'user_id' => $user_id]);
            sms_log_event($pdo, $row['id'], $row['status'], $row['status'], 'studio', ['detail' => 'Resent by ' . $MY_NAME . ' as message #' . $r['log_id'] . ' (' . $r['status'] . ').', 'user_id' => $user_id]);
            if ($r['status'] === 'sent') sms_json(['status'=>'success','message'=>'Re-sent to ' . $row['recipient_phone'] . ' as message #' . $r['log_id'] . '.','log_id'=>$r['log_id']]);
            sms_json(['status'=>'error','message'=>($r['status'] === 'blocked' ? '' : 'Resend failed: ') . ($r['error'] ?? 'Unknown'),'log_id'=>$r['log_id']]);

        /* ================================================================
         * TEMPLATES — save a reusable message template (create or update)
         * POST: name, body_template, id (optional, to update own)
         * ================================================================ */
        case 'save_template':
            $name = sms_cut(trim((string)($_POST['name'] ?? '')), 100);
            $body = trim((string)($_POST['body_template'] ?? ''));
            $id = (int)($_POST['id'] ?? 0);
            if ($name === '') sms_fail('Template name is required.');
            if ($body === '') sms_fail('Template body is required.');
            if ($id > 0) {
                $st = $pdo->prepare("UPDATE sms_templates SET name = ?, body_template = ? WHERE id = ? AND created_by = ?");
                $st->execute([$name, $body, $id, $user_id]);
                sms_json(['status'=>'success','message'=> $st->rowCount() ? 'Template updated.' : 'Template updated (or not found/owned by you).']);
            }
            $st = $pdo->prepare("INSERT INTO sms_templates (name, body_template, created_by) VALUES (?,?,?)");
            $st->execute([$name, $body, $user_id]);
            sms_json(['status'=>'success','message'=>'Template saved.','id'=>(int)$pdo->lastInsertId()]);

        case 'list_templates':
            $rows = $pdo->query("SELECT id, name, created_at FROM sms_templates ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            sms_json(['status'=>'success','data'=>$rows]);

        case 'get_template':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT id, name, body_template FROM sms_templates WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_fail('Template not found.');
            sms_json(['status'=>'success','template'=>$row]);

        /* TEMPLATES — delete (own templates, or any if Super Admin) */
        case 'delete_template':
            $id = (int)($_POST['id'] ?? 0);
            if (sms_user_is_super_admin()) {
                $st = $pdo->prepare("DELETE FROM sms_templates WHERE id = ?");
                $st->execute([$id]);
            } else {
                $st = $pdo->prepare("DELETE FROM sms_templates WHERE id = ? AND created_by = ?");
                $st->execute([$id, $user_id]);
            }
            sms_json($st->rowCount()
                ? ['status'=>'success','message'=>'Template deleted.']
                : ['status'=>'error','message'=>'No template deleted (it may belong to another user).']);

        /* ================================================================
         * SUPPRESSION — list / release / add
         * ================================================================ */
        case 'list_suppression':
            $rows = $pdo->query(
                "SELECT s.id, s.phone, s.reason, s.consecutive_failures, s.first_failed_at,
                        s.last_failed_at, s.suppressed_at, s.released_at, s.note,
                        u.first_name AS rel_fname, u.last_name AS rel_lname
                 FROM sms_suppression s LEFT JOIN users u ON u.id = s.released_by
                 ORDER BY (s.released_at IS NULL) DESC, s.suppressed_at DESC
                 LIMIT 300"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['suppressed'] = sms_t($r['suppressed_at']);
                $r['released'] = sms_t($r['released_at']);
                $r['last_failed'] = sms_t($r['last_failed_at']);
                $r['released_by'] = sms_person_name($r['rel_fname'], $r['rel_lname']) ?: null;
                unset($r['rel_fname'], $r['rel_lname']);
            }
            unset($r);
            sms_json(['status'=>'success','data'=>$rows]);

        case 'release_suppression':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) sms_fail('Suppression ID is required.');
            $stmt = $pdo->prepare("UPDATE sms_suppression SET released_at = NOW(), released_by = ? WHERE id = ? AND released_at IS NULL");
            $stmt->execute([$user_id, $id]);
            sms_json(['status'=>'success','message'=> $stmt->rowCount() ? 'Number released. It can now receive SMS again.' : 'Nothing to release.']);

        case 'add_suppression':
            $phone = trim((string)($_POST['phone'] ?? ''));
            $reason = sms_cut(trim((string)($_POST['reason'] ?? '')), 200) ?: 'Manual';
            $norm = sms_normalize_phone($phone);
            if ($norm === null) sms_fail('Invalid phone number.');
            $note = 'Suppressed manually by ' . $MY_NAME . '.';
            // No reliance on a UNIQUE key on phone (the hand-made table may not have one).
            $ex = $pdo->prepare("SELECT id, released_at FROM sms_suppression WHERE phone = ? ORDER BY id DESC LIMIT 1");
            $ex->execute([$norm]);
            $row = $ex->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['released_at'] === null) sms_fail($norm . ' is already suppressed.');
            if ($row) {
                $pdo->prepare("UPDATE sms_suppression SET reason = ?, note = ?, suppressed_at = NOW(), released_at = NULL, released_by = NULL WHERE id = ?")
                    ->execute([$reason, $note, $row['id']]);
            } else {
                $pdo->prepare("INSERT INTO sms_suppression (phone, reason, note, suppressed_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$norm, $reason, $note]);
            }
            sms_json(['status'=>'success','message'=>$norm . ' suppressed. It will not receive SMS until released.']);

        default:
            sms_fail('Invalid API action requested.');
    }
} catch (Throwable $e) {
    // Throwable, not just PDOException: a TypeError / RuntimeException used to
    // print an HTML fatal error, which the page could not parse (silent failure).
    error_log("SMS API Error ({$action}): " . $e->getMessage());
    sms_fail('A system error occurred while processing the request.');
}
