<?php
// /cron/email_worker.php
/**
 * EMAIL — Outbox worker (every minute).
 *
 * Sends what is due in email_outbox, highest priority first (confirm links and
 * password resets, then alerts, then automations, then campaigns), under the
 * domain's hourly budget shared with the ERP mailboxes, over one SMTP
 * connection. Church news waits for the send window (07:00–20:00 Lagos).
 * Everything lives in email_worker_run() (includes/campaigns/outbox.php).
 *
 * Crontab (keep the output: a worker that dies every minute looks exactly like
 * one that never runs when the line ends in ">/dev/null 2>&1"):
 *     * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/email_worker.php >> /home/smartqaq/logs/hod-email.log 2>&1
 *
 *   --status    print the hour's budget and the queue, send nothing
 *   --dry-run   say how many would go now, send nothing
 *   --prune     also clear bodies older than 90 days (run it once a day)
 *
 * Safe to overlap: one run at a time (a MySQL named lock). A run that dies
 * mid-send leaves rows in 'sending'; the next run marks them 'unknown' and
 * never sends them again (they may already be in someone's inbox).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
if (!ini_get('error_log')) ini_set('error_log', dirname(__DIR__) . '/includes/error_log');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

$args = array_slice($argv ?? [], 1);
$line = static fn (string $msg) => print('[' . date('Y-m-d H:i:s') . '] [email] ' . $msg . "\n");

if (!campaigns_ready($pdo)) {
    $line('Email tables are not there yet (db/migrate.php has not run). Nothing to do.');
    exit(0);
}

if (in_array('--status', $args, true)) {
    $b = email_budget_status($pdo);
    $line(sprintf('hour %s: %d of %d usable sent (%d bulk of %d), cap %d%s',
        $b['hour'], $b['sent_total'], $b['usable'], $b['sent_bulk'], $b['bulk_limit'], $b['cap'],
        $b['refused'] ? ' — the host refused this hour' : ''));
    foreach (email_outbox_status($pdo) as $lane => $statuses) {
        $parts = [];
        foreach ($statuses as $status => $n) {
            $parts[] = "{$status} {$n}";
        }
        $line(str_pad($lane, 14) . implode(', ', $parts));
    }
    $line('paused: ' . (email_setting($pdo, 'paused') === '1' ? 'YES' : 'no')
        . '; mail settings in .env: ' . (hod_mail_configured() ? 'present' : 'MISSING'));
    exit(0);
}

try {
    $r = email_worker_run($pdo, ['dry_run' => in_array('--dry-run', $args, true)]);
    if ($r['locked']) {
        $line('Another run is still going. Exiting.');
        exit(0);
    }
    $line(($r['recovered'] ? "recovered {$r['recovered']} interrupted send(s) as unknown; " : '') . $r['message']);
    if (in_array('--prune', $args, true)) {
        $line('pruned bodies of ' . email_prune($pdo) . ' old email(s)');
    }
} catch (Throwable $e) {
    $line('ERROR: ' . $e->getMessage());
    error_log('[email] worker: ' . $e->getMessage());
    exit(1);
}
