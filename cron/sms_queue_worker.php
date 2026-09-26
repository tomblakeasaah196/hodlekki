<?php
/**
 * ============================================================================
 * SMS STUDIO — Queue Worker (bulletproof background sending)
 * File: /cron/sms_queue_worker.php
 * ----------------------------------------------------------------------------
 * Picks up queued SMS recipients and sends them in the BACKGROUND, so no
 * browser request is ever held open. This removes ALL server-timeout risk
 * regardless of audience size.
 *
 * Run every minute via cron (CLI only):
 *     * * * * *  php /path/to/sms_queue_worker.php  >/dev/null 2>&1
 * ============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms_status.php';
require_once __DIR__ . '/../includes/sms_functions.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const WORKER_BATCH = 20;   // sends per cron run (~ per minute)
const PAUSE_US = 400000;   // 0.4s between sends (rate-limit pacing)

function logline($msg){ echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

try {
    // Load settings once (decrypts the BulkSMS credentials)
    $settings = [];
    $rows = $pdo->query("SELECT skey, enc_value, iv, tag FROM sms_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $settings[$r['skey']] = sms_decrypt($r['enc_value'], $r['iv'], $r['tag']);
    }
    foreach (['base_url','api_token','sender_id','gateway','webhook_url'] as $k) {
        if (!isset($settings[$k])) $settings[$k] = '';
    }
    if (empty($settings['api_token'])) { logline('No API token configured. Exiting.'); exit; }

    // Claim up to WORKER_BATCH queued rows (mark 'sending' + lock)
    $pdo->beginTransaction();
    $claim = $pdo->prepare(
        "UPDATE sms_queue SET status='sending', locked_at=NOW()
         WHERE id IN (SELECT id FROM (
             SELECT id FROM sms_queue WHERE status='queued' ORDER BY id LIMIT ".WORKER_BATCH."
             FOR UPDATE
         ) t)"
    );
    $claim->execute();
    $pdo->commit();

    $pick = $pdo->prepare("SELECT * FROM sms_queue WHERE status='sending' AND locked_at IS NOT NULL ORDER BY id LIMIT ".WORKER_BATCH);
    $pick->execute();
    $jobs = $pick->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jobs)) { logline('Nothing queued.'); exit; }

    $sent = 0; $failed = 0;
    foreach ($jobs as $job) {
        $recipient = json_decode($job['recipient_json'], true);
        if (!is_array($recipient) || empty($recipient['phone'])) {
            $pdo->prepare("UPDATE sms_queue SET status='error', sent_status='failed', error_message='Invalid recipient' WHERE id=?")->execute([$job['id']]);
            $failed++;
            continue;
        }
        // pull the campaign template
        $c = $pdo->prepare("SELECT body_template, id FROM sms_campaigns WHERE id=?");
        $c->execute([$job['campaign_id']]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) { $failed++; continue; }

        $r = sms_send_one($pdo, $settings, $recipient, $camp['body_template'], $job['campaign_id'], null);
        $status = ($r['status'] === 'sent') ? 'done' : 'error';
        $sentStatus = $r['status'];
        $err = $r['error'] ?? ($r['status']==='blocked' ? $r['error'] : null);
        $pdo->prepare("UPDATE sms_queue SET status=?, sent_status=?, error_message=? WHERE id=?")
            ->execute([$status, $sentStatus, $err, $job['id']]);

        if ($r['status'] === 'sent') $sent++; else $failed++;
        usleep(PAUSE_US);
    }

    // update the campaign counters
    $cids = array_unique(array_column($jobs, 'campaign_id'));
    foreach ($cids as $cid) {
        $pdo->prepare("UPDATE sms_campaigns c
            SET sent_count = (SELECT COUNT(*) FROM sms_queue WHERE campaign_id=? AND sent_status='sent'),
                failed_count = (SELECT COUNT(*) FROM sms_queue WHERE campaign_id=? AND status='error')
            WHERE c.id=?")
            ->execute([$cid, $cid, $cid]);
        // mark campaign done when nothing left queued/sending
        $pdo->prepare("UPDATE sms_campaigns SET status='sent'
            WHERE id=? AND (SELECT COUNT(*) FROM sms_queue WHERE campaign_id=? AND status IN ('queued','sending'))=0")
            ->execute([$cid, $cid]);
    }

    logline("Worker: {$sent} sent, {$failed} failed in this run.");
} catch (PDOException $e) {
    logline('ERROR: ' . $e->getMessage());
    exit(1);
}
