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
 * Run every minute via cron (CLI only), keeping its output — a worker that
 * dies every minute looks exactly like one that never runs when the line
 * ends in ">/dev/null 2>&1":
 *     * * * * *  /usr/local/bin/ea-php83 /path/to/cron/sms_queue_worker.php >> /home/<user>/sms_worker.log 2>&1
 *
 * Safe to overlap: only one run works at a time (MySQL named lock); a second
 * run waits a few seconds, then exits. A run that dies mid-way is recovered by
 * the next one without sending anyone the same message twice. All logic lives
 * in sms_process_queue() in includes/sms_functions.php, which the Studio also
 * calls ("Send now") when this cron job has stopped.
 *
 * What the Studio's status bar sees (sms_worker_heartbeat):
 *   last_cron_seen_at  stamped here before anything else — "the cron fired"
 *   last_cron_at       stamped only when a run completed — "the cron works"
 *   last_message       what the last run did, or the error that stopped it
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI. Without this
// the worker looked for /.env, could not connect, and died silently under cron,
// so queued campaigns were never sent.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);

// Under the CLI SAPI error_log() goes to stderr, which cron throws away. Send
// it to the same file the site's own PHP errors land in (includes/error_log,
// cPanel → Metrics → Errors) unless php.ini already names a log.
if (!ini_get('error_log')) ini_set('error_log', dirname(__DIR__) . '/includes/error_log');

// A fatal error, or an exit() inside a bootstrap include (db.php dies when the
// database connection fails), would otherwise leave no trace at all.
$smsWorkerDone = false;
register_shutdown_function(function () use (&$smsWorkerDone) {
    if ($smsWorkerDone) return;
    $e = error_get_last();
    error_log('SMS worker: run ended before completing'
        . ($e ? ' — ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']
              : ' — exit() in a bootstrap include (is .env readable and the database reachable?)'));
});

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms_functions.php';

function logline($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

// "The cron fired" — before the lock and before any work, so the Studio can
// tell a cron that never runs from one that runs and fails every minute.
sms_worker_seen($pdo);

try {
    $r = sms_process_queue($pdo, 55, 'cron');
    if ($r['locked']) {
        logline('Another worker run is still going. Exiting.');
        $smsWorkerDone = true;
        exit(0);
    }
    logline('Worker: ' . $r['message'] . ($r['recovered'] ? " Recovered {$r['recovered']} interrupted job(s)." : ''));
} catch (Throwable $e) {
    logline('ERROR: ' . $e->getMessage());
    error_log('SMS worker: ' . $e->getMessage());
    // Leave the reason where the status bar can show it (last_cron_at is NOT
    // advanced: the run did not complete).
    try {
        sms_worker_heartbeat($pdo, 'cron', ['sent' => 0, 'failed' => 0, 'polled' => 0,
            'message' => 'ERROR: ' . sms_cut($e->getMessage(), 240)], false);
    } catch (Throwable $x) {
        error_log('SMS worker: could not record the error in the heartbeat: ' . $x->getMessage());
    }
    $smsWorkerDone = true;
    exit(1);
}
$smsWorkerDone = true;
