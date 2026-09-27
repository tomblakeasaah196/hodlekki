<?php
// /api/sms_webhook.php
/**
 * ============================================================================
 * SMS STUDIO — Delivery Status Webhook Receiver
 * ----------------------------------------------------------------------------
 * BulkSMS posts delivery callbacks here. This endpoint is PUBLIC (called by the
 * BulkSMS server), so it FAILS CLOSED: if SMS_WEBHOOK_SECRET is not configured,
 * it refuses to run rather than accepting anonymous writes.
 *
 * BulkSMS cannot send custom headers, so the secret travels in the URL:
 *     https://<site>/api/sms_webhook.php?token=<SMS_WEBHOOK_SECRET>
 * (Settings -> "Use this site's webhook URL" fills that in.) An
 * X-Webhook-Token header is also accepted.
 *
 * Every callback is logged to sms_webhook_log so the true carrier status is
 * never lost. Reports are applied through sms_apply_dlr(), which the delivery
 * poller also uses, so the two paths can never disagree, a late interim
 * report can never undo "delivered", and every movement lands on the
 * message's timeline.
 * ============================================================================
 */

require_once '../includes/db.php';          // loads .env into $_ENV
header('Content-Type: application/json');
require_once '../includes/sms_functions.php';

/* ---- Fail-closed shared-secret check ----
 * The secret used to be read with getenv() only. db.php loads .env into
 * $_ENV, not the process environment, so on this host every callback got
 * 503 and no delivery report ever reached History. */
$secret = sms_webhook_secret();
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['status'=>'error','message'=>'Webhook not configured. Set SMS_WEBHOOK_SECRET in .env.']);
    exit;
}
$provided = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ($_GET['token'] ?? ''));
if ($provided === '' || !hash_equals($secret, $provided)) {
    error_log('SMS webhook: rejected request with a missing/invalid token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Invalid webhook token.']);
    exit;
}

try {
    /* ---- Read + log the raw payload FIRST (before any parsing) ---- */
    $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
    $headers = array_filter($_SERVER, function ($k) {
        return strpos($k, 'HTTP_') === 0
            && !in_array($k, ['HTTP_COOKIE', 'HTTP_AUTHORIZATION', 'HTTP_X_WEBHOOK_TOKEN'], true);  // never store secrets
    }, ARRAY_FILTER_USE_KEY);
    $query = $_GET;
    unset($query['token']);

    $pdo->prepare("INSERT INTO sms_webhook_log (remote_ip, headers, raw_body) VALUES (?,?,?)")
        ->execute([
            $_SERVER['REMOTE_ADDR'] ?? null,
            json_encode($headers),
            $raw !== '' ? $raw : ($query ? http_build_query($query) : ''),
        ]);
    $webhookLogId = (int)$pdo->lastInsertId();

    /* ---- Parse incoming body: JSON, form-encoded, or query string ---- */
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $form = [];
        if ($raw !== '') parse_str($raw, $form);
        $data = $form ?: ($_POST ?: $query);
    }

    $reports = sms_extract_reports($data);
    if (!$reports || $reports[0]['message_id'] === null) {
        $pdo->prepare("UPDATE sms_webhook_log SET message_id = ?, parsed_status = ? WHERE id = ?")
            ->execute([$reports[0]['message_id'] ?? null, sms_cut($reports[0]['status'] ?? '(missing)', 191), $webhookLogId]);
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Missing message_id or status.']);
        exit;
    }

    $applied = []; $matched = 0; $firstMid = null; $firstRaw = null;
    $find = $pdo->prepare("SELECT * FROM sms_log WHERE message_id = ?");
    foreach ($reports as $rep) {
        if ($rep['message_id'] === null) continue;
        $firstMid = $firstMid ?? $rep['message_id'];
        $firstRaw = $firstRaw ?? $rep['status'];
        if (sms_map_dlr_status($rep['status']) === null) {
            error_log("SMS webhook: unmapped status '{$rep['status']}' for message_id {$rep['message_id']}");
            $applied[] = 'unrecognised';
            continue;
        }
        $find->execute([$rep['message_id']]);
        $rows = $find->fetchAll(PDO::FETCH_ASSOC);
        $who = substr(preg_replace('/\D/', '', $rep['recipient']), -10);
        foreach ($rows as $row) {
            // One message ID can only belong to one of our single-recipient sends,
            // but if BulkSMS names the recipient, make sure it is this one.
            if ($who !== '' && count($rows) > 1 && substr((string)$row['recipient_phone'], -10) !== $who) continue;
            $a = sms_apply_dlr($pdo, $row, $rep['status'], 'webhook', [
                'provider_time' => $rep['provider_time'], 'code' => $rep['code'],
            ]);
            $matched++;
            $applied[] = $a['status'];
        }
    }

    $pdo->prepare("UPDATE sms_webhook_log SET message_id = ?, parsed_status = ?, applied_status = ?, matched_rows = ? WHERE id = ?")
        ->execute([
            $firstMid !== null ? sms_cut($firstMid, 100) : null,
            $firstRaw !== null ? sms_cut($firstRaw, 100) : null,
            $applied ? sms_cut(implode(',', array_unique($applied)), 20) : null,
            $matched, $webhookLogId,
        ]);

    echo json_encode(['status'=>'success','message'=> $matched ? 'Applied to ' . $matched . ' message(s).' : 'Logged; no matching message.']);
} catch (Throwable $e) {
    error_log('SMS webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Could not process the delivery report.']);
}
