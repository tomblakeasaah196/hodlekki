<?php
/**
 * ============================================================================
 * SMS STUDIO — Backend API
 * File: /api/sms_api.php
 * ----------------------------------------------------------------------------
 * Handles: encrypted config (settings), audience building, send, test-send,
 * history and per-recipient resend for the BulkSMS Nigeria integration.
 *
 * Mirrors the existing pattern used by /api/events_api.php (PDO + action switch).
 * ============================================================================
 */

require_once '../includes/db.php';
require_once '../includes/sms_vault_key.php';
require_once '../includes/sms_status.php';
header('Content-Type: application/json');
require_once '../includes/sms_functions.php';


/* ============================================================================
 * SECURITY: auth + RBAC (mirror the events module)
 * ========================================================================== */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized access. Please log in.']);
    exit;
}

// ---- CSRF guard (state-changing POST requests must carry the session token) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if ($token === '' || empty($_SESSION['sms_csrf']) || !hash_equals($_SESSION['sms_csrf'], $token)) {
        echo json_encode(['status'=>'error','message'=>'Invalid or missing security token. Refresh the page and try again.']);
        exit;
    }
}

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$allowed_roles = ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD','Sub_Unit_Head'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $r) {
        if (in_array($r['role_name'], $allowed_roles)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) {
    echo json_encode(['status'=>'error','message'=>'Access Denied. You do not have clearance to send SMS.']);
    exit;
}

// Fetch the current user's record (for password verification + test-send number)
$me = $pdo->prepare("SELECT id, first_name, last_name, phone, email, password_hash FROM users WHERE id = ?");
$me->execute([$user_id]);
$ME = $me->fetch(PDO::FETCH_ASSOC);

try {
    switch ($action) {

        /* ================================================================
         * SETTINGS — status only (never returns secrets)
         * ================================================================ */
        case 'settings_status':
            $settings = sms_load_settings($pdo);
            $configured = !empty($settings['api_token']);
            echo json_encode([
                'status' => 'success',
                'configured' => $configured,
                'masked' => [
                    'base_url'  => $configured ? preg_replace('#^(.{12}).*#', '$1…', $settings['base_url']) : '',
                    'api_token' => $configured ? substr($settings['api_token'], 0, 4) . '••••••••' : '',
                    'sender_id' => $settings['sender_id'] ? substr($settings['sender_id'],0,2).'…' : '',
                    'gateway'   => $settings['gateway'] ?: '',
                ],
                'my_phone' => $ME['phone'] ?? '',
            ]);
            break;

        /* ================================================================
         * SETTINGS — unlock with ERP login password to reveal secrets
         * ================================================================ */
        case 'unlock_settings':
            $password = $_POST['password'] ?? '';
            if ($password === '' || !$ME || !password_verify($password, $ME['password_hash'])) {
                echo json_encode(['status'=>'error','message'=>'Incorrect password. Please try again.']);
                exit;
            }
            $_SESSION['sms_unlocked'] = time();
            $settings = sms_load_settings($pdo);
            echo json_encode(['status'=>'success','settings'=>$settings]);
            break;

        /* ================================================================
         * SETTINGS — lock (clear unlock flag)
         * ================================================================ */
        case 'lock_settings':
            unset($_SESSION['sms_unlocked']);
            echo json_encode(['status'=>'success','message'=>'Settings locked.']);
            break;

        /* ================================================================
         * SETTINGS — save (requires an active password unlock this session)
         * ================================================================ */
        case 'save_settings':
            if (empty($_SESSION['sms_unlocked']) || (time() - $_SESSION['sms_unlocked']) > 600) {
                echo json_encode(['status'=>'error','message'=>'Session locked. Re-enter your password to edit settings.']);
                exit;
            }
            $vals = [];
            foreach (SMS_KEYS as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if (($k === 'api_token' || $k === 'sender_id') && $v === '') {
                    echo json_encode(['status'=>'error','message'=> ucfirst(str_replace('_',' ',$k)) . ' is required.']);
                    exit;
                }
                $vals[$k] = $v;
            }
            foreach ($vals as $k => $v) sms_save_setting($pdo, $k, $v, $user_id);
            echo json_encode(['status'=>'success','message'=>'Connection details saved & encrypted.']);
            break;

        /* ================================================================
         * SETTINGS — test connection (check balance)
         * ================================================================ */
        case 'test_connection':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'API token not configured.']); exit; }
            $base = rtrim($settings['base_url'] ?: 'https://www.bulksmsnigeria.com/api/v2', '/');
            $res = sms_http('GET', $base . '/balance', $settings['api_token']);
            if ($res['ok'] && $res['data']) {
                $d = $res['data'];
                $bal = $d['balance']['total_balance'] ?? ($d['data']['balance'] ?? null);
                echo json_encode(['status'=>'success','message'=>'Connection OK.','balance'=>$bal]);
            } else {
                echo json_encode(['status'=>'error','message'=>'Connection failed: '.($res['message'] ?? 'Unknown error')]);
            }
            break;

        /* ================================================================
         * AUDIENCE — fetch events for the dropdown
         * ================================================================ */
        case 'fetch_events':
            $rows = $pdo->query("SELECT id, title, event_date FROM events ORDER BY event_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        /* ================================================================
         * AUDIENCE — build recipient list
         * POST: audience = 'users' | 'registrations'
         *       statuses[] (users) | event_id (registrations)
         *       search
         * ================================================================ */
        case 'fetch_recipients':
            $audience = $_POST['audience'] ?? 'users';
            $statuses = $_POST['statuses'] ?? null;
            $eventId  = $_POST['event_id'] ?? '';
            $search   = trim($_POST['search'] ?? '');

            if (!is_array($statuses)) $statuses = [];
            $statuses = array_values(array_filter(array_map('trim', $statuses)));

            if ($audience === 'registrations') {
                $recs = sms_audience_registrations($pdo, $eventId ? (int)$eventId : null, $search);
                echo json_encode(['status'=>'success','data'=>$recs,'count'=>count($recs),'valid_count'=>count($recs),'invalid_count'=>0]);
            } elseif ($audience === 'checkins') {
                $recs = sms_audience_checkins($pdo, $eventId ? (int)$eventId : null, $search);
                echo json_encode(['status'=>'success','data'=>$recs,'count'=>count($recs),'valid_count'=>count($recs),'invalid_count'=>0]);
            } else {
                $res = sms_audience_users($pdo, $statuses, $search);
                echo json_encode(['status'=>'success','data'=>$res['data'],'count'=>count($res['data']),
                                  'valid_count'=>count($res['data']),
                                  'invalid_count'=>$res['dropped'],
                                  'shared_phones'=>count($res['shared'])]);
            }
            break;

        /* ================================================================
         * SEND TEST — to the logged-in user's phone
         * ================================================================ */
        case 'send_test':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'BulkSMS not configured yet. Go to Settings.']); exit; }
            $template = $_POST['template'] ?? '';
            $audience = $_POST['audience'] ?? 'users';
            $testPhone = trim($_POST['test_phone'] ?? '');
            if (trim($template) === '') { echo json_encode(['status'=>'error','message'=>'Message is empty.']); exit; }

            $recipient = [
                'source'=>'user', 'source_id'=>$ME['id'],
                'name'=>trim($ME['first_name'].' '.$ME['last_name']),
                'phone'=>$ME['phone'] ?? '',
                'first_name'=>$ME['first_name'] ?? '', 'last_name'=>$ME['last_name'] ?? '',
                'email'=>$ME['email'] ?? '',
                'guest_name'=>'', 'event_title'=>'Test Message',
            ];
            if ($audience === 'registrations') $recipient['guest_name'] = trim($ME['first_name'].' '.$ME['last_name']);
            // If testing the check-ins audience, pull the user's actual blessing_ref
            if ($audience === 'checkins') {
                $bi = $pdo->prepare("SELECT blessing_ref, event_id FROM checkins WHERE phone = ? AND blessing_ref IS NOT NULL ORDER BY id DESC LIMIT 1");
                $bi->execute([sms_normalize_phone($ME['phone'] ?? '')]);
                $b = $bi->fetch(PDO::FETCH_ASSOC);
                if ($b) {
                    $recipient['blessing_ref'] = $b['blessing_ref'];
                    $ev = $pdo->prepare("SELECT title FROM events WHERE id = ?");
                    $ev->execute([$b['event_id']]);
                    $recipient['event_title'] = $ev->fetchColumn() ?: 'Exousia 2026';
                }
            }
            $targetLabel = $ME['phone'] ?? 'you';
            if ($testPhone !== '') {
                $norm = sms_normalize_phone($testPhone);
                if ($norm === null) { echo json_encode(['status'=>'error','message'=>'Invalid test phone number: '.$testPhone]); exit; }
                $recipient['phone'] = $norm;
                $targetLabel = $norm;
            }

            $r = sms_send_one($pdo, $settings, $recipient, $template, null, $user_id);
            if ($r['status'] === 'sent') {
                echo json_encode(['status'=>'success','message'=>'Test message sent to '.$targetLabel.'.']);
            } else {
                echo json_encode(['status'=>'error','message'=>'Test failed: '.($r['error'] ?? 'Unknown error')]);
            }
            break;

        /* ================================================================
         * SEND CAMPAIGN — recipients[] from the client (already fetched),
         * each = {source, source_id, name, phone, first_name, ...}
         * ================================================================ */
        case 'send_campaign':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'BulkSMS not configured yet. Go to Settings.']); exit; }
            $template   = $_POST['template'] ?? '';
            $recipients = json_decode($_POST['recipients'] ?? '[]', true);
            $title      = trim($_POST['title'] ?? 'Bulk SMS');
            $audience   = trim($_POST['audience'] ?? 'users');
            $eventId    = $_POST['event_id'] ?? '';
            $filters    = $_POST['filters'] ?? null;

            if (trim($template) === '') { echo json_encode(['status'=>'error','message'=>'Message is empty.']); exit; }
            if (!is_array($recipients) || count($recipients) === 0) { echo json_encode(['status'=>'error','message'=>'No recipients selected.']); exit; }

            // create campaign row (status 'queued' — will be sent by the cron worker)
            $stmt = $pdo->prepare("INSERT INTO sms_campaigns
                (title, audience, event_id, filters_json, body_template, total, status, created_by)
                VALUES (?,?,?,?,?,?,'queued',?)");
            $stmt->execute([
                $title, $audience,
                ($audience==='registrations' && $eventId) ? (int)$eventId : null,
                is_string($filters) ? $filters : null,
                $template, count($recipients), $user_id
            ]);
            $campaignId = $pdo->lastInsertId();

            // Enqueue every recipient for background sending (no timeout risk).
            // Batch-insert for speed.
            $ins = $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json) VALUES (?,?)");
            $rows = [];
            foreach ($recipients as $rec) {
                $ins->execute([$campaignId, json_encode($rec)]);
            }

            echo json_encode([
                'status'=>'success',
                'message'=> count($recipients) . ' queued for background sending.',
                'campaign_id'=>$campaignId,
                'queued'=>count($recipients),
            ]);
            break;

        /* ================================================================
         * HISTORY — paginated send log
         * ================================================================ */
        case 'get_history':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $per  = 50;
            $off  = ($page - 1) * $per;
            $search = trim($_POST['search'] ?? '');

            $where = "WHERE 1=1";
            $params = [];
            if ($search !== '') {
                $where .= " AND (recipient_phone LIKE :q1 OR recipient_name LIKE :q2 OR body LIKE :q3)";
                $params['q1'] = "%{$search}%";
                $params['q2'] = "%{$search}%";
                $params['q3'] = "%{$search}%";
            }
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM sms_log $where");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));

            $sql = "SELECT l.*, c.title AS campaign_title, u.first_name AS sent_by_fname, u.last_name AS sent_by_lname
                    FROM sms_log l
                    LEFT JOIN sms_campaigns c ON c.id = l.campaign_id
                    LEFT JOIN users u ON u.id = l.created_by
                    $where ORDER BY l.id DESC LIMIT $per OFFSET $off";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            echo json_encode(['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC),'page'=>$page,'pages'=>$pages,'total'=>$total]);
            break;

        /* ================================================================
         * CHECK DELIVERY — poll BulkSMS for the status of one sent message
         * (used by the History tab's Refresh button / auto-poll)
         * ================================================================ */
        case 'check_delivery':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'BulkSMS not configured.']); exit; }
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sms_log WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Log entry not found.']); exit; }
            if (empty($row['message_id'])) { echo json_encode(['status'=>'error','message'=>'No message ID to poll.']); exit; }

            $base = rtrim($settings['base_url'] ?: 'https://www.bulksmsnigeria.com/api/v2', '/');
            $res = sms_http('GET', $base . '/delivery-reports?message_id=' . urlencode($row['message_id']), $settings['api_token']);
            $newStatus = null; $detail = null; $rawStatus = null;

            if ($res['ok'] && $res['data']) {
                $d = $res['data'];
                $summary = $d['data']['summary'] ?? null;
                $reports = $d['data']['reports'] ?? null;

                // 1) Per-recipient reports (most precise) — match by recipient + shared vocabulary
                if (is_array($reports)) {
                    foreach ($reports as $rep) {
                        $who = preg_replace('/[^0-9]/', '', (string)($rep['recipient'] ?? ''));
                        if ($who !== '' && substr($who, -10) !== substr($row['recipient_phone'], -10)) {
                            continue; // this report belongs to someone else in the batch
                        }
                        $rawStatus = (string)($rep['delivery_status'] ?? '');
                        $mapped = sms_map_dlr_status($rawStatus);
                        if ($mapped !== null) { $newStatus = $mapped; break; }
                        error_log("SMS: unmapped delivery_status '{$rawStatus}' for log id {$id}");
                    }
                }
                // 2) Fallback to summary totals
                if ($newStatus === null && is_array($summary)) {
                    $del = (int)($summary['delivered'] ?? 0);
                    $fail = (int)($summary['failed'] ?? 0);
                    $pend = (int)($summary['pending'] ?? 0);
                    if ($del > 0 && $fail === 0 && $pend === 0) $newStatus = 'delivered';
                    elseif ($fail > 0 && $del === 0) $newStatus = 'failed';
                    elseif ($pend > 0 && $del === 0 && $fail === 0) $newStatus = 'pending';
                }
                $detail = $d['message'] ?? null;
            }

            // Persist the true delivery status + the raw BulkSMS status text as the reason
            if ($newStatus && $newStatus !== $row['status']) {
                $reason = $rawStatus ?: $detail;
                $pdo->prepare("UPDATE sms_log SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$newStatus, $reason, $id]);
                $row['status'] = $newStatus;
                $row['error_message'] = $reason;
            }

            // Human-readable summary of what BulkSMS actually reports
            $label = $row['status'] === 'pending' ? 'awaiting delivery confirmation' : $row['status'];
            echo json_encode([
                'status'=>'success',
                'message'=>'Delivery status: '.ucfirst($label) . ($rawStatus ? ' (' . $rawStatus . ')' : ''),
                'delivery_status'=>$row['status'],
                'raw_status'=>$rawStatus
            ]);
            break;

        /* ================================================================
         * RESEND — resend a single logged recipient
         * ================================================================ */
        case 'resend_recipient':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'BulkSMS not configured.']); exit; }
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sms_log WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Log entry not found.']); exit; }

            $recipient = [
                'source'=>$row['source_type'], 'source_id'=>$row['source_id'],
                'name'=>$row['recipient_name'], 'phone'=>$row['recipient_phone'],
                'first_name'=>$row['recipient_name'], 'last_name'=>'',
                'email'=>'', 'guest_name'=>$row['recipient_name'], 'event_title'=>'',
            ];
            $r = sms_send_one($pdo, $settings, $recipient, $row['body'], $row['campaign_id'], $user_id);
            echo json_encode($r['status']==='sent'
                ? ['status'=>'success','message'=>'Re-sent to '.$row['recipient_phone'].'.']
                : ['status'=>'error','message'=>'Resend failed: '.($r['error']??'Unknown')]);
            break;

        /* ================================================================
         * TEMPLATES — save a reusable message template (create or update)
         * POST: name, body_template, id (optional, to update own)
         * ================================================================ */
        case 'save_template':
            $name = trim($_POST['name'] ?? '');
            $body = trim($_POST['body_template'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            if ($name === '') { echo json_encode(['status'=>'error','message'=>'Template name is required.']); exit; }
            if ($body === '') { echo json_encode(['status'=>'error','message'=>'Template body is required.']); exit; }
            if ($id > 0) {
                $st = $pdo->prepare("UPDATE sms_templates SET name = ?, body_template = ? WHERE id = ? AND created_by = ?");
                $st->execute([$name, $body, $id, $user_id]);
                echo json_encode(['status'=>'success','message'=> $st->rowCount() ? 'Template updated.' : 'Template updated (or not found/owned by you).']);
            } else {
                $st = $pdo->prepare("INSERT INTO sms_templates (name, body_template, created_by) VALUES (?,?,?)");
                $st->execute([$name, $body, $user_id]);
                echo json_encode(['status'=>'success','message'=>'Template saved.']);
            }
            break;

        /* ================================================================
         * TEMPLATES — list all templates (id + name) for the dropdown
         * ================================================================ */
        case 'list_templates':
            $rows = $pdo->query("SELECT id, name, created_at FROM sms_templates ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        /* ================================================================
         * TEMPLATES — load one template's full body
         * ================================================================ */
        case 'get_template':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT id, name, body_template FROM sms_templates WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Template not found.']); exit; }
            echo json_encode(['status'=>'success','template'=>$row]);
            break;

        /* ================================================================
         * TEMPLATES — delete (own templates, or any if Super Admin)
         * ================================================================ */
        case 'delete_template':
            $id = (int)($_POST['id'] ?? 0);
            $is_super = false;
            if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
                foreach ($_SESSION['roles'] as $r) {
                    if (($r['role_name'] ?? '') === 'Super_Admin') { $is_super = true; break; }
                }
            }
            if ($is_super) {
                $st = $pdo->prepare("DELETE FROM sms_templates WHERE id = ?");
                $st->execute([$id]);
            } else {
                $st = $pdo->prepare("DELETE FROM sms_templates WHERE id = ? AND created_by = ?");
                $st->execute([$id, $user_id]);
            }
            echo json_encode(['status'=>'success','message'=> $st->rowCount() ? 'Template deleted.' : 'No template deleted (it may belong to another user).']);
            break;

        /* ================================================================
         * LIST SENDER IDS — fetch the account's approved sender IDs from
         * BulkSMS so the user can see exactly which value to enter.
         * ================================================================ */
        case 'list_sender_ids':
            $settings = sms_load_settings($pdo);
            if (empty($settings['api_token'])) { echo json_encode(['status'=>'error','message'=>'API token not configured.']); exit; }
            $base = rtrim($settings['base_url'] ?: 'https://www.bulksmsnigeria.com/api/v2', '/');
            $res = sms_http('GET', $base . '/sender-ids', $settings['api_token']);
            if ($res['ok'] && $res['data']) {
                $ids = $res['data']['data']['sender_ids'] ?? ($res['data']['sender_ids'] ?? []);
                echo json_encode(['status'=>'success','data'=>$ids]);
            } else {
                echo json_encode(['status'=>'error','message'=>'Could not fetch sender IDs: '.($res['message'] ?? 'Unknown')]);
            }
            break;

        /* ================================================================
         * SUPPRESSION — list suppressed numbers
         * ================================================================ */
        case 'list_suppression':
            $rows = $pdo->query(
                "SELECT id, phone, reason, consecutive_failures, first_failed_at,
                        last_failed_at, suppressed_at, released_at, note
                 FROM sms_suppression
                 ORDER BY (released_at IS NULL) DESC, suppressed_at DESC
                 LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        /* ================================================================
         * SUPPRESSION — release a suppressed number (allow sending again)
         * ================================================================ */
        case 'release_suppression':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Suppression ID is required.']); exit; }
            $stmt = $pdo->prepare("UPDATE sms_suppression
                                   SET released_at = NOW(), released_by = ?
                                   WHERE id = ? AND released_at IS NULL");
            $stmt->execute([$user_id, $id]);
            echo json_encode(['status'=>'success','message'=> $stmt->rowCount() ? 'Number released. It can now receive SMS again.' : 'Nothing to release.']);
            break;

        /* ================================================================
         * SUPPRESSION — manually suppress a number
         * ================================================================ */
        case 'add_suppression':
            $phone = trim($_POST['phone'] ?? '');
            $reason = trim($_POST['reason'] ?? 'manual');
            $norm = sms_normalize_phone($phone);
            if ($norm === null) { echo json_encode(['status'=>'error','message'=>'Invalid phone number.']); exit; }
            $stmt = $pdo->prepare(
                "INSERT INTO sms_suppression (phone, reason, last_failed_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason),
                   released_at = NULL, last_failed_at = NOW()"
            );
            $stmt->execute([$norm, $reason]);
            echo json_encode(['status'=>'success','message'=>'Number suppressed.']);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid API action requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log("SMS API Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'A system error occurred while processing the request.']);
}
