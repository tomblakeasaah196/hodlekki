<?php
/**
 * ============================================================================
 * SMS STUDIO — Delivery Status Webhook Receiver
 * File: /api/sms_webhook.php
 * ----------------------------------------------------------------------------
 * BulkSMS posts delivery callbacks here. This endpoint is PUBLIC (called by the
 * BulkSMS server), so it FAILS CLOSED: if SMS_WEBHOOK_SECRET is not configured,
 * it refuses to run rather than accepting anonymous writes.
 *
 * Every callback is logged to sms_webhook_log so the true carrier status is
 * never lost. Status mapping uses the shared vocabulary in sms_status.php.
 * ============================================================================
 */

require_once '../includes/db.php';
require_once '../includes/sms_status.php';
header('Content-Type: application/json');

/* ---- Fail-closed shared-secret check ---- */
$secret = getenv('SMS_WEBHOOK_SECRET');
if ($secret === false || $secret === '') {
    http_response_code(503);
    echo json_encode(['status'=>'error','message'=>'Webhook not configured. Set SMS_WEBHOOK_SECRET.']);
    exit;
}
$provided = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Invalid webhook token.']);
    exit;
}

/* ---- Read + log the raw payload FIRST (before any parsing) ---- */
$raw = file_get_contents('php://input');

$logStmt = $pdo->prepare(
    "INSERT INTO sms_webhook_log (remote_ip, headers, raw_body) VALUES (?,?,?)"
);
$logStmt->execute([
    $_SERVER['REMOTE_ADDR'] ?? null,
    json_encode(array_filter($_SERVER, function ($k) {
        return strpos($k, 'HTTP_') === 0;
    }, ARRAY_FILTER_USE_KEY)),
    $raw,
]);
$webhookLogId = (int)$pdo->lastInsertId();

/* ---- Parse incoming body (JSON or POST/GET) ---- */
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST ?: [];

$messageId = $data['message_id'] ?? $data['id'] ?? $data['msg_id'] ?? null;
$statusRaw = $data['delivery_status'] ?? $data['status'] ?? $data['event'] ?? $data['state'] ?? null;
$code = $data['code'] ?? null;

if (!$messageId || !$statusRaw) {
    $pdo->prepare("UPDATE sms_webhook_log SET message_id=?, parsed_status=? WHERE id=?")
        ->execute([$messageId, $statusRaw ?: '(missing)', $webhookLogId]);
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'Missing message_id or status.']);
    exit;
}

/* ---- Map using the shared vocabulary; never guess ---- */
$newStatus = sms_map_dlr_status($statusRaw);
if ($newStatus === null) {
    error_log("SMS webhook: unmapped status '{$statusRaw}' for message_id {$messageId}");
    $pdo->prepare("UPDATE sms_webhook_log
                   SET message_id=?, parsed_status=?, applied_status=? WHERE id=?")
        ->execute([$messageId, $statusRaw, 'unrecognised', $webhookLogId]);
    echo json_encode(['status'=>'success','message'=>'Unrecognised status, logged.']);
    exit;
}

/* ---- Apply: status + carrier's own words in error_message ---- */
$stmt = $pdo->prepare("UPDATE sms_log
                       SET status = ?,
                           api_code = COALESCE(?, api_code),
                           error_message = ?,
                           updated_at = NOW()
                       WHERE message_id = ?");
$stmt->execute([$newStatus, $code, $statusRaw, $messageId]);

$pdo->prepare("UPDATE sms_webhook_log
               SET message_id=?, parsed_status=?, applied_status=?, matched_rows=? WHERE id=?")
    ->execute([$messageId, $statusRaw, $newStatus, $stmt->rowCount(), $webhookLogId]);

echo json_encode(['status'=>'success','message'=>'Updated to '.$newStatus]);
