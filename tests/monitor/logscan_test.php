<?php
// /tests/monitor/logscan_test.php — reading cPanel's error_log files.

$db = monitor_test_db();
monitor_test_clock('2026-11-11 10:30:00');   // the sample lines are 10 minutes old

$lines = <<<'LOG'
[11-Nov-2026 09:20:01 UTC] PHP Warning:  Undefined variable $total in /home/smartqaq/public_html/hodlekki/modules/finance/index.php on line 88
[11-Nov-2026 09:20:02 UTC] PHP Fatal error:  Uncaught PDOException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Ngozi Eze' for key 'full_name' in /home/smartqaq/public_html/hodlekki/api/congregation_api.php:212
Stack trace:
#0 /home/smartqaq/public_html/hodlekki/api/congregation_api.php(212): PDOStatement->execute(Array)
#1 /home/smartqaq/public_html/hodlekki/api/congregation_api.php(40): save_member('Ngozi Eze', '08031234567')
#2 {main}
  thrown in /home/smartqaq/public_html/hodlekki/api/congregation_api.php on line 212
[11-Nov-2026 09:20:03 Africa/Douala] PHP Parse error:  syntax error, unexpected token "}" in /home/smartqaq/public_html/hodlekki/connect.php on line 301
[11-Nov-2026 09:20:04 UTC] [mailer] send failed: SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting
[11-Nov-2026 09:20:05 UTC] SMS worker: sent 3 messages
[11-Nov-2026 09:20:06 UTC] HODMON [E7KQ2M9X] warning: Undefined array key "x" in modules/events/index.php on line 4
LOG;

$entries = monitor_log_parse($lines . "\n");
eq('six entries, the stack trace kept with its exception', 6, count($entries));

$ev = array_map(fn ($e) => monitor_log_entry_event($e, 'error_log'), $entries);
eq('a PHP warning', ['php', 'warning', 'Undefined variable $total', 88], [$ev[0]['kind'], $ev[0]['level'], $ev[0]['message'], $ev[0]['line']]);
eq('an uncaught exception', ['exception', 'fatal', 'PDOException', 212], [$ev[1]['kind'], $ev[1]['level'], $ev[1]['exception_class'], $ev[1]['line']]);
lacks('its stack trace loses the arguments PHP printed', (string) $ev[1]['trace_text'], 'Ngozi');
has('…but keeps where and what', (string) $ev[1]['trace_text'], 'api/congregation_api.php:40 save_member()');
eq('a parse error is fatal', ['php', 'fatal', 301], [$ev[2]['kind'], $ev[2]['level'], $ev[2]['line']]);
eq('a line the code wrote about a failure is an error', ['log', 'error'], [$ev[3]['kind'], $ev[3]['level']]);
ok('a progress line is not a problem', $ev[4] === null);
ok('a line the monitor wrote itself is skipped', $ev[5] === null);
eq('UTC log times become the right moment', monitor_ts('2026-11-11 10:20:01'), $ev[0]['at']);
eq('named zones too (Africa/Douala is Lagos time)', monitor_ts('2026-11-11 09:20:03'), $ev[2]['at']);

$stored = monitor_prepare_event($ev[1]);
lacks('the duplicate value is redacted before storing', $stored['message'], 'Ngozi');

// ---- Reading a file incrementally
$file = $GLOBALS['mt_dir'] . '/error_log';
file_put_contents($file, $lines . "\n");
$r1 = monitor_log_scan_file($db, $file);
eq('first read: 4 problems recorded, 2 lines skipped', [4, 2], [$r1['recorded'], $r1['skipped']]);
$r2 = monitor_log_scan_file($db, $file);
eq('second read: nothing new', 0, $r2['entries']);
file_put_contents($file, "[11-Nov-2026 09:29:00 UTC] PHP Warning:  Undefined variable \$total in /home/smartqaq/public_html/hodlekki/modules/finance/index.php on line 88\n", FILE_APPEND);
$r3 = monitor_log_scan_file($db, $file);
eq('an appended line is read', 1, $r3['recorded']);
eq('…and counted on the same problem', 2, (int) $db->query("SELECT occurrences FROM monitor_error_groups WHERE title = 'Undefined variable \$total'")->fetchColumn());
monitor_test_clock('2026-11-11 12:00:00');
file_put_contents($file, "[11-Nov-2026 09:40:00 UTC] [sms] BulkSMS refused the token\n[11-Nov-2026 10:59:00 UTC] [push] could not reach FCM\n");
$r4 = monitor_log_scan_file($db, $file);
eq('an emptied (rotated) log is read from its start', 2, $r4['recorded']);

// Lines older than an hour are listed, not announced.
$old = $db->query("SELECT alerted_at FROM monitor_error_groups WHERE title LIKE '[sms]%'")->fetchColumn();
ok('a problem first found in a line over an hour old is not announced', is_string($old));
$fresh = $db->query("SELECT alerted_at FROM monitor_error_groups WHERE title LIKE '[push]%'")->fetchColumn();
ok('a recent one is left for the alerts', $fresh === null);

// ---- The UI foundation's CSRF reports: kept quietly, one problem per endpoint and reason
$csrf = monitor_log_entry_event(['ts' => '11-Nov-2026 09:00:00 UTC', 'text' => 'CSRF report-only: POST /api/events_api.php?id=4 (no token)'], 'api/error_log');
eq('a CSRF report-only line is a quiet report, not a server error', ['log', 'notice', 'CSRF report-only'], [$csrf['kind'], $csrf['level'], $csrf['exception_class']]);
eq('…grouped by endpoint and reason, without the query', 'CSRF report-only: POST /api/events_api.php (no token)', $csrf['message']);
$ce = monitor_prepare_event($csrf);
eq('…on its endpoint\'s route', '/api/events_api.php', $ce['route']);
has('…with its own hint', (string) monitor_explain($ce), 'CSRF_EXEMPT_PREFIXES');
$db2 = monitor_test_db();
monitor_record($db2, $ce);
eq('…and never announced', 0, monitor_alert_new_errors($db2, $db2));

// ---- Sources: the docroot and three levels below, never vendor/ or uploads/
$tree = $GLOBALS['mt_dir'] . '/tree';
foreach (['', '/api', '/modules/events', '/vendor/x', '/uploads/se'] as $d) {
    @mkdir($tree . $d, 0700, true);
    file_put_contents($tree . $d . '/error_log', "x\n");
}
$real = str_replace('\\', '/', (string) realpath($tree));
$found = [];
foreach (monitor_log_sources($tree) as $p) {
    $p = str_replace('\\', '/', $p);
    if (str_starts_with($p, $real . '/')) {
        $found[] = substr($p, strlen($real));   // PHP's own error_log setting is listed too; not part of this tree
    }
}
sort($found);
eq('the error_log files that count', ['/api/error_log', '/error_log', '/modules/events/error_log'], $found);
