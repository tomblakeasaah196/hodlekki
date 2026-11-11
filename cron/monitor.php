<?php
// /cron/monitor.php
/**
 * ============================================================================
 * THE SYSTEM MONITOR — background work (docs/monitor.md)
 * ----------------------------------------------------------------------------
 * Every minute (cPanel → Cron Jobs):
 *     * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/monitor.php >/dev/null 2>&1
 *
 * Each run, in order, and cheap when there is nothing to do:
 *   1. what was spooled while the database was unreachable (errors, speed reports)
 *   2. the server's error_log files, from where the last run stopped
 *   3. every 5 minutes: the health checks (hourly: SMTP sign-in, BulkSMS
 *      balance, the cPanel quota, real-user speed roll-up)
 *   4. alerts: new and returning errors, spikes, red checks and recoveries,
 *      and the morning summary
 *   5. at 03:00: clearing out old records
 *   6. the live wall's snapshot
 *
 * The monitor's own heartbeat is written by the capture (every cron/*.php gets
 * one); if this job stops, ordinary page requests notice (the watchdog).
 *
 * Flags:
 *     --status        print the checks and open errors, change nothing
 *     --checks        run the health checks now (and the hourly ones)
 *     --digest        send the morning summary now
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI (AGENTS.md).
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/monitor/bootstrap.php';

$args = array_slice($argv, 1);
$started = microtime(true);
$say = static function (string $line): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
};

if (!monitor_ready($pdo)) {
    $say('The monitor tables are not in the database yet: run "php db/migrate.php" (a deploy does).');
    exit(0);
}

if (in_array('--status', $args, true)) {
    foreach (monitor_checks_current($pdo) as $c) {
        $say(str_pad(strtoupper($c['state']), 8) . str_pad($c['label'], 34) . ' ' . $c['detail']);
    }
    $open = $pdo->query("SELECT COUNT(*) FROM monitor_error_groups WHERE status IN ('new', 'investigating')")->fetchColumn();
    $say('Open problems: ' . $open . '. Heartbeats in ' . (hod_monitor_data_dir() ?? '(no data folder)') . '/beats.');
    exit(0);
}

// One run at a time: a slow run (an SMTP sign-in timing out) must not pile up.
$dir = hod_monitor_data_dir();
$lock = $dir !== null ? @fopen($dir . '/cron.lock', 'c') : false;
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('Another run is still working; leaving it to finish.');
    exit(0);
}
if ($dir !== null && !is_file($dir . '/installed_at')) {
    @file_put_contents($dir . '/installed_at', (string) time());
}

$now = monitor_time();
$minute = (int) date('i', $now);
$done = [];

// 1. The spool (each kind on its own: a problem with one never holds up the other).
try {
    $done['spooled'] = monitor_spool_import('events', fn (array $record) => monitor_store_all($pdo, [$record]));
} catch (Throwable $e) {
    hod_monitor_report($e, ['step' => 'spool: errors']);
}
try {
    $done['speed'] = monitor_spool_import('rum', fn (array $record) => monitor_rum_store($pdo, [$record]));
} catch (Throwable $e) {
    hod_monitor_report($e, ['step' => 'spool: speed']);
}

// 2. The error logs.
try {
    $scan = monitor_log_scan_all($pdo);
    $done['log'] = $scan['recorded'] . '/' . $scan['entries'];
} catch (Throwable $e) {
    hod_monitor_report($e, ['step' => 'log scan']);
}

// 3. Health checks: every 5 minutes; the slow facts hourly.
$checks = null;
$lastChecks = (int) monitor_state_get($pdo, 'checks_at', '0');
$lastSlow = (int) monitor_state_get($pdo, 'slow_checks_at', '0');
$forceChecks = in_array('--checks', $args, true);
if ($forceChecks || $minute % 5 === 0 || $lastChecks < $now - 300) {
    $slow = $forceChecks || $lastSlow < $now - 3600;
    try {
        $checks = monitor_checks_run($pdo, $pdo, $slow);
        monitor_state_set($pdo, 'checks_at', (string) $now);
        if ($slow) {
            monitor_state_set($pdo, 'slow_checks_at', (string) $now);
        }
        $done['checks'] = monitor_checks_worst($checks) . ($slow ? ' (+hourly)' : '');
    } catch (Throwable $e) {
        hod_monitor_report($e, ['step' => 'checks']);
    }
}

// 4. Alerts.
foreach ([
    'errors' => fn () => monitor_alert_new_errors($pdo, $pdo),
    'spikes' => fn () => monitor_alert_spikes($pdo, $pdo),
    'health' => fn () => $checks !== null ? monitor_alert_health($pdo, $pdo) : 0,
    'digest' => fn () => monitor_alert_digest($pdo, $pdo, in_array('--digest', $args, true)) ? 1 : 0,
] as $what => $fn) {
    try {
        $n = $fn();
        if ($n) {
            $done['alerted ' . $what] = $n;
        }
    } catch (Throwable $e) {
        hod_monitor_report($e, ['step' => 'alerts: ' . $what]);
    }
}

// 5. Clearing out, once a day at 03:00.
if ((int) date('G', $now) === 3 && monitor_state_get($pdo, 'cleanup:' . date('Y-m-d', $now)) === null) {
    monitor_state_set($pdo, 'cleanup:' . date('Y-m-d', $now), '1');
    try {
        $done['cleared'] = monitor_cleanup($pdo, $now);
    } catch (Throwable $e) {
        hod_monitor_report($e, ['step' => 'cleanup']);
    }
}

// 6. The live wall, and old rate-limit counters.
try {
    monitor_wall_publish($pdo, true);
    monitor_intake_rate_cleanup();
} catch (Throwable $e) {
    hod_monitor_report($e, ['step' => 'wall']);
}

$parts = [];
foreach ($done as $k => $v) {
    $parts[] = $k . '=' . $v;
}
$say('monitor: ' . ($parts ? implode(' ', $parts) : 'nothing new') . ' (' . number_format(microtime(true) - $started, 2) . ' s)');

/** Old records out: occurrences after 30 days, trends after 90, settled problems after 90 idle days. */
function monitor_cleanup(PDO $pdo, int $now): int
{
    $n = 0;
    $old = static function (string $sql, array $args) use ($pdo, &$n): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $n += $stmt->rowCount();
    };
    $d30 = monitor_fmt($now - 30 * 86400);
    $d90 = monitor_fmt($now - 90 * 86400);
    $old('DELETE FROM monitor_error_events WHERE occurred_at < ?', [$d30]);
    $old('DELETE FROM monitor_error_hourly WHERE hour < ?', [$d90]);
    $old('DELETE FROM monitor_error_people WHERE day < ?', [substr($d90, 0, 10)]);
    // Settled problems after 90 quiet days; browser and CSP reports (anyone can
    // send one) after 30 quiet days whatever their status; quota counters after 2.
    $ids = $pdo->prepare("SELECT id FROM monitor_error_groups
                           WHERE (status IN ('fixed', 'ignored') AND last_seen_at < ?) OR (kind IN ('js', 'csp') AND last_seen_at < ?)");
    $ids->execute([$d90, $d30]);
    foreach (array_chunk(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)), 200) as $chunk) {
        $in = implode(',', $chunk);
        foreach (['monitor_error_events', 'monitor_error_hourly', 'monitor_error_people', 'monitor_error_paths', 'monitor_error_notes'] as $t) {
            $pdo->exec("DELETE FROM {$t} WHERE group_id IN ({$in})");
        }
        $n += $pdo->exec("DELETE FROM monitor_error_groups WHERE id IN ({$in})");
    }
    $old('DELETE FROM monitor_check_history WHERE at < ?', [$d90]);
    $old('DELETE FROM monitor_probes WHERE received_at < ?', [$d90]);
    $old('DELETE FROM monitor_alert_log WHERE at < ?', [$d90]);
    $old('DELETE FROM monitor_rum_daily WHERE day < ?', [monitor_fmt($now - 400 * 86400, 'Y-m-d')]);
    $old("DELETE FROM monitor_state WHERE (k LIKE 'digest:%' OR k LIKE 'cleanup:%') AND updated_at < ?", [monitor_fmt($now - 14 * 86400)]);
    $old("DELETE FROM monitor_state WHERE k LIKE 'browser_new:%' AND updated_at < ?", [monitor_fmt($now - 2 * 86400)]);
    $dir = hod_monitor_data_dir();
    foreach ($dir !== null ? (glob($dir . '/spool/*.jsonl') ?: []) : [] as $f) {
        if ((int) @filemtime($f) < $now - 7 * 86400) {
            @unlink($f);
        }
    }

    return $n;
}
