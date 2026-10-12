<?php
// /cron/push_worker.php
/**
 * ============================================================================
 * WEB PUSH — Queue Worker (fan-out + delivery)
 * ----------------------------------------------------------------------------
 * Reads every new row in `system_notifications`, expands it to its audience,
 * and delivers an encrypted push notification to each member's registered
 * devices. No module has to opt in: writing a notification is enough.
 *
 * Run every minute via cron (CLI only):
 *     * * * * *  php /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php >/dev/null 2>&1
 *
 * includes/push_dispatch.php already sends most messages within milliseconds
 * of the request that created them, so in normal operation this worker finds
 * nothing to do. It exists for what that cannot cover:
 *   - notifications written by other cron jobs (CLI has no shutdown hook);
 *   - retries after a push service answered 429 or 5xx;
 *   - a run that died half way through;
 *   - pruning the queue.
 *
 * Safe to overlap: push_run() takes the MySQL named lock `hod_push_worker`,
 * so a second run exits immediately rather than sending anything twice.
 *
 * Flags:
 *     --once        fan out and deliver one batch, then stop (the default)
 *     --status      print what is waiting and exit without sending
 *     --prune-only  only clear out old queue rows
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI — the same
// trap that silently killed the SMS worker. See AGENTS.md.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/push_helpers.php';

$args      = array_slice($argv, 1);
$statusOnly = in_array('--status', $args, true);
$pruneOnly  = in_array('--prune-only', $args, true);

function pushlog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

try {
    $ready = push_ready($pdo);

    if ($statusOnly) {
        pushlog('ready: ' . ($ready['ok'] ? 'yes' : 'no — ' . $ready['reason']));

        if (push_table_exists($pdo, 'push_queue')) {
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE status = 'pending'")->fetchColumn();
            $sending = (int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE status = 'sending'")->fetchColumn();
            $sent    = (int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE status = 'sent'")->fetchColumn();
            $failed  = (int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE status = 'failed'")->fetchColumn();
            // 'sending' should be 0 or a handful. A number that stays high
            // means runs are being killed mid-flight; push_recover_stuck()
            // puts those back after 10 minutes.
            pushlog("queue: {$pending} pending, {$sending} in flight, {$sent} sent, {$failed} failed");
        }
        if (push_table_exists($pdo, 'push_subscriptions')) {
            $devices = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1")->fetchColumn();
            $people  = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions WHERE is_active = 1")->fetchColumn();
            pushlog("devices: {$devices} active, across {$people} member(s)");
        }
        if (push_table_exists($pdo, 'system_notifications')) {
            $cursor = push_config_get($pdo, 'fanout_cursor_id', 'unset');
            $maxId  = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM system_notifications")->fetchColumn();
            pushlog("fan-out cursor: {$cursor} (latest notification is {$maxId})");
        }
        exit(0);
    }

    if ($pruneOnly) {
        pushlog('pruned ' . push_prune($pdo) . ' finished queue row(s)');
        exit(0);
    }

    if (!$ready['ok']) {
        // FEATURE_NOT_READY is the normal state in the minutes between the
        // tree landing in the docroot and db/migrate.php running. Not an error.
        pushlog('nothing to do — ' . $ready['reason']);
        exit(0);
    }

    // 45s of budget leaves the minute's cron slot room to finish and exit
    // before the next one starts; anything left over is picked up next run.
    $result = push_run($pdo, 200, 45.0, true);

    if ($result['locked']) {
        pushlog('another run (or an inline dispatch) is still working. Exiting.');
        exit(0);
    }

    pushlog(sprintf(
        'scanned %d notification(s), queued %d, delivered %d, retrying %d, failed %d',
        $result['scanned'],
        $result['queued'],
        $result['sent'],
        $result['retried'],
        $result['failed']
    ));
} catch (Throwable $e) {
    pushlog('ERROR: ' . $e->getMessage());
    error_log('Push worker: ' . $e->getMessage());
    exit(1);
}
