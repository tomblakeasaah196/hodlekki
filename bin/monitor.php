<?php
// /bin/monitor.php
/**
 * The system monitor from cPanel → Terminal (docs/monitor.md, "Command line").
 * Until the monitor's screens arrive, this is how to read and drive it.
 *
 *   php bin/monitor.php status                  the health checks and open problems
 *   php bin/monitor.php errors [all|fixed|...]  problems, newest first
 *   php bin/monitor.php brief <id>              one problem as a prompt for Claude ("Copy for AI")
 *   php bin/monitor.php export [file.md]        every open problem in one Markdown file
 *   php bin/monitor.php fixed <id> [note]       mark a problem fixed (it reopens by itself if it comes back)
 *   php bin/monitor.php checks                  run every health check now (the hourly ones too)
 *   php bin/monitor.php scan                    read the error logs and the spool now
 *   php bin/monitor.php recipients              who gets alert emails
 *   php bin/monitor.php recipient-add <email>   send a confirmation link (nothing is sent before it is clicked)
 *   php bin/monitor.php recipient-remove <id>
 *   php bin/monitor.php test-alert              a test email and bell, to prove both reach you
 *   php bin/monitor.php digest                  send the morning summary now
 *   php bin/monitor.php wall                    the live wall's snapshot address and TV link
 *
 * From the docroot: /usr/local/bin/ea-php83 bin/monitor.php status
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/monitor/bootstrap.php';

$cmd = $argv[1] ?? 'status';
$arg = $argv[2] ?? null;
$out = static function (string $s = ''): void {
    echo $s . "\n";
};

if (!monitor_ready($pdo)) {
    $out('The monitor tables are not in this database yet: run "php db/migrate.php".');
    exit(1);
}

switch ($cmd) {
    case 'status':
        $checks = monitor_checks_current($pdo);
        $out('Overall: ' . strtoupper($checks ? monitor_checks_worst($checks) : 'not checked yet'));
        $grp = null;
        foreach ($checks as $c) {
            if ($c['grp'] !== $grp) {
                $grp = $c['grp'];
                $out();
                $out('  ' . $grp);
            }
            $out('    ' . str_pad(strtoupper($c['state']), 8) . str_pad($c['label'], 34) . $c['detail']);
            if ($c['fix'] && $c['state'] !== 'ok') {
                $out(str_repeat(' ', 46) . '→ ' . $c['fix']);
            }
        }
        $out();
        // no break: the open problems follow
    case 'errors':
        $status = $cmd === 'errors' && in_array($arg, array_merge(['open', 'all'], MONITOR_STATUSES), true) ? $arg : 'open';
        $where = $status === 'open' ? "status IN ('new', 'investigating')" : ($status === 'all' ? '1 = 1' : 'status = ' . $pdo->quote($status));
        $rows = $pdo->query("SELECT id, level, kind, status, title, occurrences, last_seen_at FROM monitor_error_groups
                              WHERE {$where} AND level NOT IN ('notice', 'deprecated') ORDER BY last_seen_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        $out(count($rows) ? 'Problems (' . $status . '), newest first:' : 'No ' . $status . ' problems.');
        foreach ($rows as $r) {
            $out(sprintf('  #%-5d %-8s %-9s %-13s ×%-5d %s  %s', $r['id'], $r['level'], $r['kind'], $r['status'], $r['occurrences'],
                substr((string) $r['last_seen_at'], 5, 11), mb_substr((string) $r['title'], 0, 90)));
        }
        if ($rows) {
            $out();
            $out('"php bin/monitor.php brief <id>" prints the prompt for Claude.');
        }
        break;

    case 'brief':
        $md = monitor_brief($pdo, (int) $arg);
        $out($md ?? 'No problem #' . (int) $arg . '.');
        break;

    case 'export':
        $md = monitor_export_open($pdo);
        if ($arg) {
            file_put_contents($arg, $md);
            $out('Written to ' . $arg . '.');
        } else {
            echo $md;
        }
        break;

    case 'fixed':
        $g = monitor_group($pdo, (int) $arg);
        if ($g === null) {
            $out('No problem #' . (int) $arg . '.');
            exit(1);
        }
        $now = monitor_fmt();
        $pdo->prepare("UPDATE monitor_error_groups SET status = 'fixed', status_changed_at = ?, fixed_at = ?, fixed_release = ? WHERE id = ?")
            ->execute([$now, $now, monitor_release_id(), $g['id']]);
        monitor_add_note($pdo, (int) $g['id'], null, 'Marked fixed from the command line' . (isset($argv[3]) ? ': ' . implode(' ', array_slice($argv, 3)) : '.'));
        $out('#' . $g['id'] . ' marked fixed. If it happens again it reopens and you are told.');
        break;

    case 'checks':
        foreach (monitor_checks_run($pdo, $pdo, true) as $c) {
            $out(str_pad(strtoupper($c['state']), 8) . str_pad($c['label'], 34) . $c['detail']);
        }
        monitor_state_set($pdo, 'checks_at', (string) monitor_time());
        monitor_state_set($pdo, 'slow_checks_at', (string) monitor_time());
        break;

    case 'scan':
        $n = monitor_spool_import('events', fn (array $r) => monitor_store_all($pdo, [$r]));
        $m = monitor_spool_import('rum', fn (array $r) => monitor_rum_store($pdo, [$r]));
        $s = monitor_log_scan_all($pdo);
        $out('Spool: ' . $n . ' error(s), ' . $m . ' speed report(s). Logs: ' . $s['files'] . ' file(s), ' . $s['entries'] . ' entries, ' . $s['recorded'] . ' recorded, ' . $s['skipped'] . ' skipped.');
        break;

    case 'recipients':
        foreach (monitor_recipients_list($pdo) as $r) {
            $out(sprintf('  #%-4d %-8s %-40s added %s%s', $r['id'], $r['status'], $r['email'], substr((string) $r['added_at'], 0, 10),
                $r['last_sent_at'] ? ', last sent ' . substr((string) $r['last_sent_at'], 0, 16) : ''));
        }
        break;

    case 'recipient-add':
        $r = monitor_recipient_add($pdo, (string) $arg, null);
        $out($r['ok'] ? 'Confirmation link sent to ' . $arg . '. Nothing else is sent until it is clicked.' : 'Not added: ' . $r['error']);
        break;

    case 'recipient-remove':
        $out(monitor_recipient_remove($pdo, (int) $arg) ? 'Removed.' : 'Nothing to remove.');
        break;

    case 'test-alert':
        $m = monitor_email_recipients($pdo, 'test', 'HOD Lekki: test alert', 'This is a test alert', 'Sent from the command line to prove that email alerts reach you.', [], false);
        $b = monitor_notify_admins($pdo, $pdo, 'test', 'Test alert from the system monitor', 'If you can read this, monitor alerts reach your bell and phone.', monitor_screen_link(), false);
        $out('Sent to ' . $m . ' email address(es) and ' . $b . ' admin bell(s).');
        break;

    case 'digest':
        $out(monitor_alert_digest($pdo, $pdo, true) ? 'Morning summary sent.' : 'Nothing was sent (no confirmed recipients and no admins?).');
        break;

    case 'wall':
        $key = monitor_wall_key($pdo);
        monitor_wall_publish($pdo, true);
        $out('Snapshot: ' . site_url('/live/monitor/' . $key . '.json'));
        $out('TV link:  ' . site_url('/modules/monitor/wall#k=' . $key) . ' (no sign-in: the link is the key)');
        break;

    default:
        $out('Unknown command. See the top of bin/monitor.php.');
        exit(1);
}
