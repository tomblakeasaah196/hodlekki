<?php
// /cron/sms_queue_worker.php
/**
 * ============================================================================
 * SMS STUDIO — Queue Worker (background sending + delivery-report polling)
 * ----------------------------------------------------------------------------
 * Picks up queued SMS recipients and sends them in the BACKGROUND, so no
 * browser request is ever held open, then spends the rest of the minute
 * fetching delivery reports for messages still awaiting one (so History
 * reaches Delivered / Failed even when the BulkSMS webhook is not set up).
 *
 * Run every minute via cron (CLI only):
 *     * * * * *  php /path/to/cron/sms_queue_worker.php  >/dev/null 2>&1
 *
 * Safe to overlap: only one run works at a time (MySQL named lock); a second
 * run exits immediately. A run that dies mid-way is recovered by the next one
 * without sending anyone the same message twice. All logic lives in
 * sms_process_queue() in includes/sms_functions.php, which the Studio also
 * calls ("Send now") when this cron job has stopped.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI. Without this
// the worker looked for /.env, could not connect, and died silently under cron,
// so queued campaigns were never sent.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms_functions.php';

function logline($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

try {
    $r = sms_process_queue($pdo, 55, 'cron');
    if ($r['locked']) { logline('Another worker run is still going. Exiting.'); exit(0); }
    logline('Worker: ' . $r['message'] . ($r['recovered'] ? " Recovered {$r['recovered']} interrupted job(s)." : ''));
} catch (Throwable $e) {
    logline('ERROR: ' . $e->getMessage());
    error_log('SMS worker: ' . $e->getMessage());
    exit(1);
}
