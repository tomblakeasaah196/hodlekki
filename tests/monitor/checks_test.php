<?php
// /tests/monitor/checks_test.php — every health check's verdict, from facts.

$now = monitor_test_clock('2026-11-11 10:00:00');
$jobs = monitor_cron_jobs();
$push = $jobs['push_worker'];

// ---- Cron jobs that run every minute
eq('never ran, monitoring just started: waiting', 'unknown', monitor_check_cron('push_worker', $push, null, $now, $now - 120)['state']);
eq('never ran, monitoring started an hour ago: stopped', 'bad', monitor_check_cron('push_worker', $push, null, $now, $now - 3600)['state']);
eq('ran a minute ago: fine', 'ok', monitor_check_cron('push_worker', $push, ['started_at' => $now - 60, 'finished_at' => $now - 59, 'ok' => true, 'duration_ms' => 800], $now, null)['state']);
eq('last ran 7 minutes ago: late', 'warn', monitor_check_cron('push_worker', $push, ['started_at' => $now - 420, 'finished_at' => $now - 419, 'ok' => true], $now, null)['state']);
$stopped = monitor_check_cron('push_worker', $push, ['started_at' => $now - 1200, 'finished_at' => $now - 1199, 'ok' => true], $now, null);
eq('last ran 20 minutes ago: stopped', 'bad', $stopped['state']);
has('…and the fix names the exact crontab line', (string) $stopped['fix'], 'cron/push_worker.php');
$stuck = monitor_check_cron('push_worker', $push, ['started_at' => $now - 720, 'finished_at' => $now - 780], $now, null);
eq('a run that started 12 minutes ago and never finished: stuck', 'warn', $stuck['state']);
has('…and says so', $stuck['detail'], 'has not finished');
eq('the last run failed: worth a look', 'warn', monitor_check_cron('push_worker', $push, ['started_at' => $now - 60, 'finished_at' => $now - 59, 'ok' => false, 'last_error' => 'boom'], $now, null)['state']);

// ---- The email jobs: watched, at their cron's own schedule
foreach (['email_worker' => 1, 'automation_tick' => 5, 'email_bounces' => 10] as $name => $every) {
    eq("$name is watched every $every minute(s)", $every, $jobs[$name]['every'] ?? null);
    $head = (string) @file_get_contents(dirname(__DIR__, 2) . "/cron/$name.php");
    has("…and its line is the one cron/$name.php documents", $head, $jobs[$name]['line'] ?? 'missing');
}
eq('a stopped email worker alerts at once', true, in_array('cron_email_worker', monitor_urgent_checks(), true));

// ---- Daily jobs at 07:00
$daily = $jobs['reach_lost_souls'];
$at8 = monitor_test_clock('2026-11-11 08:00:00');
eq('ran at 07:00 today, it is 08:00: fine', 'ok', monitor_check_cron('reach_lost_souls', $daily, ['started_at' => monitor_ts('2026-11-11 07:00:02'), 'finished_at' => monitor_ts('2026-11-11 07:00:09'), 'ok' => true], $at8, monitor_ts('2026-11-01 00:00:00'))['state']);
eq('did not run today, it is 08:00: missed', 'bad', monitor_check_cron('reach_lost_souls', $daily, ['started_at' => monitor_ts('2026-11-10 07:00:02'), 'finished_at' => monitor_ts('2026-11-10 07:00:09'), 'ok' => true], $at8, monitor_ts('2026-11-01 00:00:00'))['state']);
$at6 = monitor_test_clock('2026-11-11 06:30:00');
eq('it is 06:30 and it ran yesterday at 07:00: fine', 'ok', monitor_check_cron('reach_lost_souls', $daily, ['started_at' => monitor_ts('2026-11-10 07:00:02'), 'finished_at' => monitor_ts('2026-11-10 07:00:09'), 'ok' => true], $at6, monitor_ts('2026-11-01 00:00:00'))['state']);
eq('monitoring started after today\'s 07:00: waiting for tomorrow', 'unknown', monitor_check_cron('reach_lost_souls', $daily, null, $at8, monitor_ts('2026-11-11 07:30:00'))['state']);

// ---- OPcache, as HQ judges it (warm hit rate)
eq('a cache just restarted: every file missed once, nothing wrong', 100.0, monitor_warm_hit_rate(['hits' => 3654, 'misses' => 744, 'cached_files' => 744]));
eq('code compiled again and again: a low rate', 5.9, monitor_warm_hit_rate(['hits' => 50, 'misses' => 1544, 'cached_files' => 744]));
eq('too few loads to judge', null, monitor_warm_hit_rate(['hits' => 20, 'misses' => 30, 'cached_files' => 30]));
$web = ['at' => $now, 'php' => '8.3.14', 'sapi' => 'litespeed', 'memory_limit' => '128M', 'max_execution' => 300, 'early_finish' => true,
        'opcache' => true, 'hits' => 3654, 'misses' => 744, 'cached_files' => 744, 'free_mb' => 101, 'restarts' => 0, 'display_errors' => false, 'prepend' => false,
        'extensions' => array_fill_keys(['pdo_mysql', 'mbstring', 'openssl', 'curl', 'json', 'gd', 'zip', 'dom', 'xml', 'simplexml', 'xmlwriter', 'zlib', 'iconv', 'ctype', 'sodium', 'intl', 'exif'], true) + ['fileinfo' => false]];
eq('speed settings fine', 'ok', monitor_check_speed($web)['state']);
eq('OPcache off: worth a look', 'warn', monitor_check_speed(['opcache' => false] + $web)['state']);
eq('PHP fine without fileinfo (the code has fallbacks)', 'ok', monitor_check_php($web, $now)['state']);
has('…and says so', monitor_check_php($web, $now)['detail'], 'without fileinfo');
eq('display_errors on in production: bad', 'bad', monitor_check_php(['display_errors' => true] + $web, $now)['state']);
eq('pdo_mysql missing: bad', 'bad', monitor_check_php(['extensions' => ['pdo_mysql' => false] + $web['extensions']] + $web, $now)['state']);
eq('no page has reported yet: unknown', 'unknown', monitor_check_php(null, $now)['state']);

// ---- SMS, push, email
eq('SMS waiting 20 minutes: the queue is stuck', 'bad', monitor_check_sms(['ready' => true, 'queued' => 40, 'oldest_queued' => $now - 1200], $now)['state']);
eq('SMS wallet for ~300 texts: top up soon', 'warn', monitor_check_sms(['ready' => true, 'balance' => ['balance' => 1200, 'unit_price' => 4, 'at' => $now]], $now)['state']);
eq('SMS wallet for ~50 texts: now', 'bad', monitor_check_sms(['ready' => true, 'balance' => ['balance' => 200, 'unit_price' => 4, 'at' => $now]], $now)['state']);
eq('SMS fine', 'ok', monitor_check_sms(['ready' => true, 'queued' => 0, 'sent_24h' => 120, 'failed_24h' => 2, 'balance' => ['balance' => 90000, 'unit_price' => 4, 'at' => $now]], $now)['state']);
eq('push waiting over 15 minutes: bad', 'bad', monitor_check_push(['ready' => true, 'devices' => 40, 'stuck' => 3], $now)['state']);
eq('push off in the Alerts Centre: bad', 'bad', monitor_check_push(['ready' => false, 'reason' => 'DISABLED'], $now)['state']);
eq('email not set up: bad', 'bad', monitor_check_email(['configured' => false], $now)['state']);
eq('SMTP refused the sign-in: bad', 'bad', monitor_check_email(['configured' => true, 'smtp' => ['ok' => false, 'error' => 'auth failed', 'at' => $now]], $now)['state']);
eq('two failed sends: worth a look', 'warn', monitor_check_email(['configured' => true, 'smtp' => ['ok' => true, 'at' => $now], 'failures_24h' => 2], $now)['state']);

// ---- Mail sync (the 12 Nov 2026 outage: no mailbox had ever synced, and nothing said so)
$box = static fn (string $name, ?int $synced, ?int $since = null, ?string $error = null): array => compact('name', 'synced', 'since', 'error');
eq('Mail not installed: nothing to watch', 'ok', monitor_check_mail(['ready' => false], $now)['state']);
eq('no mailbox brought in yet: fine', 'ok', monitor_check_mail(['ready' => true, 'active' => [], 'stuck' => []], $now)['state']);
$fine = monitor_check_mail(['ready' => true, 'active' => [$box('Embrace', $now - 60), $box('Info', $now - 180)], 'stuck' => []], $now);
eq('every active mailbox synced in the last minutes: fine', 'ok', $fine['state']);
has('…and says how fresh', $fine['detail'], '2 active mailbox(es) synced within 10 minutes; the oldest 3 minutes ago');
$never = monitor_check_mail(['ready' => true, 'active' => [$box('Embrace', null, $now - 86400, 'Could not reach mail.hodlekki.org:993 (erreur 0) for ada@example.org')], 'stuck' => []], $now);
eq('active but never synced in a day: red', 'bad', $never['state']);
has('…says never synced', $never['detail'], 'Never synced.');
has('…shows the last error', $never['detail'], 'Could not reach mail.hodlekki.org:993');
lacks('…without a personal address', $never['detail'], 'ada@example.org');
has('…and the fix names the log and the cron', (string) $never['fix'], 'hod-mail.log');
eq('brought in 3 minutes ago, first sync still to come: fine', 'ok', monitor_check_mail(['ready' => true, 'active' => [$box('New box', null, $now - 180)], 'stuck' => []], $now)['state']);
$one = monitor_check_mail(['ready' => true, 'active' => [$box('Embrace', $now - 60), $box('Info', $now - 660)], 'stuck' => []], $now);
eq('one mailbox 11 minutes behind: red', 'bad', $one['state']);
has('…names it', $one['detail'], '1 of 2 active mailbox(es) not synced for 10 minutes: Info');
eq('exactly 10 minutes: still fine', 'ok', monitor_check_mail(['ready' => true, 'active' => [$box('Info', $now - 600)], 'stuck' => []], $now)['state']);
$reset = monitor_check_mail(['ready' => true, 'active' => [$box('Embrace', $now - 60)], 'stuck' => [['name' => 'Info', 'status' => 'needs_reset', 'error' => 'refused the address or the password']]], $now);
eq('a mailbox needing a new password: amber', 'warn', $reset['state']);
has('…names it', $reset['detail'], '1 need(s) a new password: Info');
eq('a mailbox not on the server: amber', 'warn', monitor_check_mail(['ready' => true, 'active' => [], 'stuck' => [['name' => 'Youth', 'status' => 'failed', 'error' => null]]], $now)['state']);
$both = monitor_check_mail(['ready' => true, 'active' => [$box('Embrace', $now - 3600)], 'stuck' => [['name' => 'Info', 'status' => 'needs_reset', 'error' => null]]], $now);
eq('stale and a reset waiting: red wins', 'bad', $both['state']);
has('…and still mentions the reset', $both['detail'], 'Also: 1 need(s) a new password: Info');
ok('Mail sync alerts at once when red', in_array('mail_sync', monitor_urgent_checks(), true));

// The facts on either engine: a fixture table that both accept, with explicit ids, dropped afterwards so the
// MySQL/MariaDB run (MONITOR_TEST_MYSQL_DSN) starts clean next time. A database with a real mail_accounts is left alone.
$mailDb = monitor_test_db();
if (monitor_table_exists($mailDb, 'mail_accounts')) {
    ok('facts: skipped, this database has its own mail_accounts', true);
} else {
    eq('facts: no mail_accounts table, not installed', ['ready' => false], monitor_facts_mail($mailDb));
    $mailDb->exec('CREATE TABLE mail_accounts (id INT NOT NULL PRIMARY KEY, display_name VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL,'
        . ' last_synced_at DATETIME NULL, last_error VARCHAR(500) NULL, created_at DATETIME NULL)');
    try {
        $ins = $mailDb->prepare('INSERT INTO mail_accounts (id, display_name, status, last_synced_at, last_error, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([1, 'Embrace', 'active', monitor_fmt($now - 120), null, monitor_fmt($now - 86400)]);
        $ins->execute([2, 'Info', 'needs_reset', null, 'refused the address or the password', monitor_fmt($now - 86400)]);
        $ins->execute([3, 'Old', 'suspended', null, null, monitor_fmt($now - 86400)]);
        $mf = monitor_facts_mail($mailDb);
        eq('facts: active mailboxes with Lagos times read back', [['name' => 'Embrace', 'error' => null, 'synced' => $now - 120, 'since' => $now - 86400]], $mf['active']);
        eq('facts: needs_reset kept, suspended ignored', ['Info'], array_column($mf['stuck'], 'name'));
        eq('facts to verdict: amber for the reset', 'warn', monitor_check_mail($mf, $now)['state']);
    } finally {
        $mailDb->exec('DROP TABLE mail_accounts');
    }
}

// ---- Google (Trainings' Meet links): red when revoked, amber when not set up or not connected
$gOk = ['ready' => true, 'client' => true, 'key' => true, 'connected' => true, 'status' => 'connected', 'last_ok' => $now - 300, 'last_error' => null];
eq('Google: integrations not installed, nothing to watch', 'ok', monitor_check_google(['ready' => false], $now)['state']);
$revoked = monitor_check_google(['status' => 'revoked', 'connected' => false] + $gOk, $now);
eq('Google withdrew access: red', 'bad', $revoked['state']);
has('…and the fix says Connect again', (string) $revoked['fix'], 'Connect again');
eq('no client in .env: amber', 'warn', monitor_check_google(['client' => false] + $gOk, $now)['state']);
eq('no vault key: amber', 'warn', monitor_check_google(['key' => false] + $gOk, $now)['state']);
eq('not connected: amber', 'warn', monitor_check_google(['connected' => false, 'status' => null] + $gOk, $now)['state']);
eq('a permission refused on the last call: amber', 'warn', monitor_check_google(['last_error' => 'scopes 2026-11-11 09:00:00'] + $gOk, $now)['state']);
$gFine = monitor_check_google(['last_error' => 'quota 2026-11-11 09:00:00'] + $gOk, $now);
eq('connected, a passing quota error: fine', 'ok', $gFine['state']);
has('…and says when the last call worked', $gFine['detail'], 'Last call worked 5 minutes ago');
ok('Google alerts at once when red', in_array('google', monitor_urgent_checks(), true));
$gDb = monitor_test_db();
if (!monitor_table_exists($gDb, 'integration_settings')) {
    eq('Google facts: no integration_settings, not installed', ['ready' => false], monitor_facts_google($gDb));
    $gDb->exec('CREATE TABLE integration_settings (setting_key VARCHAR(64) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_by INT NULL, updated_at DATETIME NULL)');
    try {
        $gIns = $gDb->prepare('INSERT INTO integration_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach (['google.status' => 'revoked', 'google.refresh_token' => 'v1:abcd1234:xyz', 'google.last_error' => 'revoked 2026-11-11 09:00:00'] as $k => $v) {
            $gIns->execute([$k, $v]);
        }
        $gf = monitor_facts_google($gDb);
        eq('Google facts: revoked read back, never the token', ['ready' => true, 'connected' => false, 'status' => 'revoked'],
            ['ready' => $gf['ready'], 'connected' => $gf['connected'], 'status' => $gf['status']]);
        lacks('…no secret in the facts', (string) json_encode($gf), 'v1:abcd1234');
        eq('Google facts to verdict: red', 'bad', monitor_check_google($gf, $now)['state']);
    } finally {
        $gDb->exec('DROP TABLE integration_settings');
    }
}

// ---- Server
eq('database answering slowly: worth a look', 'warn', monitor_check_db(['query_ms' => 400, 'connect_ms' => 20])['state']);
eq('database unreachable: bad', 'bad', monitor_check_db(['error' => 'gone away'])['state']);
eq('quota 96% used: bad', 'bad', monitor_check_disk(['used' => 96, 'limit' => 100])['state']);
eq('quota 50% used: fine', 'ok', monitor_check_disk(['used' => 50, 'limit' => 100])['state']);
// cPanel answers "0 MB used" when the server's quotas are off: never shown as 0%.
$zero = monitor_check_disk(['used' => 0, 'limit' => 200000 * 1048576, 'at' => $now]);
eq('quota says 0 used: amber, not 0%', 'warn', $zero['state']);
has('…called unknown', $zero['detail'], 'Unknown');
lacks('…with no percentage', $zero['detail'], '%');
$f = monitor_disk_facts(['megabytes_used' => '0.00', 'megabyte_limit' => '200000.00'], 5242880, 1e12, 2e12);
eq('…so the home folder\'s size (du) stands in, against the quota', [5242880 * 1024.0, 200000 * 1048576.0, 'du'], [$f['used'], $f['limit'], $f['source']]);
$d = monitor_check_disk($f);
eq('…and is judged like the quota', 'ok', $d['state']);
has('…saying how it was measured', $d['detail'], '5.0 GB used of the 195.3 GB cPanel quota (3%), measured from the home folder');
eq('a quota that reports its use is believed', [1073741824.0, 'quota'], array_values(array_intersect_key(monitor_disk_facts(['megabytes_used' => 1024, 'megabyte_limit' => 2048], null, 1, 2), ['used' => 1, 'source' => 1])));
eq('no quota and no du: the disk\'s free space', ['free' => 50.0, 'total' => 100.0], monitor_disk_facts(['megabytes_used' => 0, 'megabyte_limit' => 0], null, 50, 100));
eq('no limit but a measured home folder: fine', 'ok', monitor_check_disk(monitor_disk_facts(null, 1024, 50, 100))['state']);
eq('nothing could be read: amber', 'warn', monitor_check_disk(['at' => $now])['state']);
// du ran out of time this hour: the last measure from the past day stands, with its age
$quota = ['megabytes_used' => '0.00', 'megabyte_limit' => '200000.00'];
$good = monitor_disk_facts($quota, 12745560, 1e12, 2e12, null, $now - 3 * 3600);
eq('a fresh du measure keeps its time', [$now - 3 * 3600, 'du'], [$good['measured_at'], $good['source']]);
$kept = monitor_disk_facts($quota, null, 1e12, 2e12, $good + ['at' => $now - 3 * 3600], $now);
eq('du failed: the measure of 3 hours ago stands', [$good['used'], $now - 3 * 3600], [$kept['used'], $kept['measured_at']]);
monitor_test_clock('2026-11-11 10:00:00'); // the clock moved to 06:30 above; $now is 10:00
$keptCheck = monitor_check_disk($kept + ['at' => $now]);
eq('…and the check stays green', 'ok', $keptCheck['state']);
has('…saying how old the measure is', $keptCheck['detail'], 'measured from the home folder 3 hours ago');
$stale = monitor_disk_facts($quota, null, 1e12, 2e12, ['used' => 5e9, 'source' => 'du', 'measured_at' => $now - 2 * 86400], $now);
eq('a measure older than a day is not kept: amber again', 'warn', monitor_check_disk($stale + ['at' => $now])['state']);
eq('a quota that answers is still believed over an old measure', 'quota', monitor_disk_facts(['megabytes_used' => 1024, 'megabyte_limit' => 2048], null, 1, 2, $good, $now)['source']);
eq('not read yet: waiting', 'unknown', monitor_check_disk([])['state']);
eq('no backup found: bad', 'bad', monitor_check_backups(['readable' => true, 'dir' => '/x'], $now)['state']);
eq('last backup 30 hours old: bad', 'bad', monitor_check_backups(['readable' => true, 'dir' => '/x', 'db_at' => $now - 30 * 3600, 'db_size' => 9e6], $now)['state']);
eq('last backup half the size of the one before: worth a look', 'warn', monitor_check_backups(['readable' => true, 'dir' => '/x', 'db_at' => $now - 3600, 'db_size' => 4e6, 'prev_size' => 9e6, 'files_at' => $now - 86400], $now)['state']);
eq('backups fine', 'ok', monitor_check_backups(['readable' => true, 'dir' => '/x', 'db_at' => $now - 3600, 'db_size' => 9e6, 'prev_size' => 8.9e6, 'files_at' => $now - 86400], $now)['state']);
eq('logs over 100 MB: worth a look', 'warn', monitor_check_logs(['total' => 150 * 1048576, 'files' => 3, 'biggest' => ['path' => 'error_log', 'bytes' => 140 * 1048576]])['state']);

// ---- The deploy log
$deployLog = <<<'LOG'
============================================================
[deploy] 2026-11-10T09:00:00+01:00 starting
[deploy] pre-pull HEAD: 1111111aaaa
[deploy] post-pull HEAD: 2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
[deploy] commit: 2222222 Move to hodlekki.org (Tom-Blake Forghab)
[deploy] 2026-11-10T09:01:10+01:00 OK
============================================================
[deploy] 2026-11-11T09:00:00+01:00 starting
[deploy] post-pull HEAD: 3333333cccccccccccccccccccccccccccccccccc
[deploy] commit: 3333333 Add the system monitor (Tom-Blake Forghab)
Migration failed: SQLSTATE[42000]
LOG;
$runs = monitor_deploy_log_parse($deployLog);
eq('two deploys read', 2, count($runs));
eq('the first finished', [true, '2222222', 'Move to hodlekki.org'], [$runs[0]['ok'], $runs[0]['commit'], $runs[0]['subject']]);
eq('the author\'s name is not kept', false, str_contains(json_encode($runs), 'Forghab'));
eq('a deploy that never finished: bad', 'bad', monitor_check_deploy($runs, null, $now)['state']);
has('…naming its last line', monitor_check_deploy($runs, null, $now)['detail'], 'Migration failed');
eq('the last deploy finished: fine', 'ok', monitor_check_deploy([$runs[0]], null, $now)['state']);
eq('main moved on and never reached the server: worth a look', 'warn',
    monitor_check_deploy([$runs[0]], ['main_sha' => '9999999dddd', 'at' => $now], $now)['state']);

// ---- The outside view
eq('no probe report yet: unknown', 'unknown', monitor_check_outside([], [], $now, 60, true)[0]['state']);
$site = ['ok' => true, 'http_status' => 200, 'ttfb_ms' => 320, 'detail' => [], 'at' => $now - 600];
$latest = ['site' => $site, 'connect' => $site, 'tls' => ['ok' => true, 'detail' => ['edge_days' => 70, 'origin_days' => 5000], 'at' => $now - 600],
           'dns' => ['ok' => true, 'detail' => ['a' => true, 'mx' => true, 'spf' => true, 'dkim' => true, 'dmarc' => true], 'at' => $now - 600],
           'origin_lock' => ['ok' => true, 'http_status' => 403, 'detail' => ['tested' => true], 'at' => $now - 600]];
$byKey = fn (array $checks) => array_column($checks, 'state', 'key');
eq('all green from outside', ['probe' => 'ok', 'outside_site' => 'ok', 'outside_connect' => 'ok', 'tls' => 'ok', 'dns' => 'ok', 'origin_lock' => 'ok'],
    $byKey(monitor_check_outside($latest, ['site' => [168, 168], 'connect' => [168, 168]], $now, 60, true)));
$down = $byKey(monitor_check_outside(['site' => ['ok' => false, 'http_status' => 522, 'ttfb_ms' => 0] + $site] + $latest, [], $now, 60, true));
eq('the site down from outside: bad', 'bad', $down['outside_site']);
eq('reports stopped for 4 hours: worth a look', 'warn', $byKey(monitor_check_outside(array_map(fn ($r) => ['at' => $now - 4 * 3600] + $r, $latest), [], $now, 60, true))['probe'] === 'warn' ? 'warn' : 'x');
$fresh = array_column(monitor_check_outside($latest, [], $now, 60, true), null, 'key');
eq('each outside result carries the time it was measured', $now - 600, $fresh['dns']['at'] ?? null);
$old = array_column(monitor_check_outside(array_map(fn ($r) => ['at' => $now - 29 * 3600] + $r, $latest), [], $now, 60, true), null, 'key');
eq('reports stopped: a green result is no longer OK', ['outside_site' => 'unknown', 'outside_connect' => 'unknown', 'tls' => 'unknown', 'dns' => 'unknown', 'origin_lock' => 'unknown'],
    array_intersect_key(array_column($old, 'state', 'key'), array_flip(['outside_site', 'outside_connect', 'tls', 'dns', 'origin_lock'])));
has('…and says how old its last result is', $old['dns']['detail'], 'Last result, 29 hours ago');
eq('…while a red result stays red', 'bad', $byKey(monitor_check_outside(array_map(fn ($r) => ['at' => $now - 29 * 3600] + $r, ['dns' => ['ok' => false, 'detail' => ['a' => true, 'mx' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true]]] + $latest), [], $now, 60, true))['dns']);
eq('the lock being off is read from .env, not the probe: no report time', null, array_column(monitor_check_outside($latest, [], $now, 60, false), null, 'key')['origin_lock']['at'] ?? null);
eq('a certificate with 10 days left: worth a look', 'warn', $byKey(monitor_check_outside(['tls' => ['ok' => false, 'detail' => ['edge_days' => 10], 'at' => $now]] + $latest, [], $now, 60, true))['tls']);
eq('no MX record: bad', 'bad', $byKey(monitor_check_outside(['dns' => ['ok' => false, 'detail' => ['a' => true, 'mx' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true], 'at' => $now]] + $latest, [], $now, 60, true))['dns']);
eq('the server answered around Cloudflare: bad', 'bad', $byKey(monitor_check_outside(['origin_lock' => ['ok' => false, 'http_status' => 200, 'detail' => ['tested' => true], 'at' => $now]] + $latest, [], $now, 60, true))['origin_lock']);
eq('no EDGE_TOKEN in .env: the lock is off', 'warn', $byKey(monitor_check_outside($latest, [], $now, 60, false))['origin_lock']);

// ---- Real users
eq('too few visits: unknown', 'unknown', monitor_check_rum('phone', ['n' => 5])['state']);
eq('phones loading in 4.6 s: bad', 'bad', monitor_check_rum('phone', ['n' => 400, 'lcp' => 4600, 'inp' => 150, 'cls' => 40, 'ttfb' => 600])['state']);
eq('computers fine', 'ok', monitor_check_rum('desktop', ['n' => 400, 'lcp' => 1800, 'inp' => 90, 'cls' => 20, 'ttfb' => 400])['state']);
eq('p75 is the nearest rank', 75, monitor_p75(range(1, 100)));

// ---- Errors
eq('a fatal in the last hour: bad', 'bad', monitor_check_errors(['open_24h' => 1, 'new_24h' => 1, 'fatal_hour' => 1])['state']);
eq('nothing open: fine', 'ok', monitor_check_errors(['open_24h' => 0])['state']);

// ---- Storing checks: a change of state goes to the history
$db = monitor_test_db();
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'ok', 'fine')], $now);
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'ok', 'fine')], $now + 60);
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'bad', 'full')], $now + 120);
eq('history keeps changes only', ['ok', 'bad'], $db->query("SELECT state FROM monitor_check_history WHERE check_key = 'disk' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
eq('"since" is when it turned', monitor_fmt($now + 120), $db->query("SELECT since FROM monitor_checks WHERE check_key = 'disk'")->fetchColumn());
eq('the worst state wins', 'bad', monitor_checks_worst([['state' => 'ok'], ['state' => 'warn'], ['state' => 'bad']]));

// ---- The whole run, on an empty database: never throws, every check answers
$GLOBALS['monitor_test_now'] = time();
$all = monitor_checks_run($db, $db, false);
ok('a full run on an empty database gives every check a state', count($all) >= 20 && !array_filter($all, fn ($c) => !in_array($c['state'], ['ok', 'warn', 'bad', 'unknown'], true)), (string) count($all));

// ---- The monitor's own cron: a setup step until it first runs, an outage only after
$now = monitor_test_clock('2026-11-11 23:00:00');
$never = monitor_check_monitor_cron(null, $now);
eq('never run: amber, a step still to do (not a red error)', 'warn', $never['state']);
has('…with the exact line to add', (string) $never['fix'], '/home/smartqaq/public_html/hodlekki/cron/monitor.php');
eq('ran 25 minutes ago: stopped (red)', 'bad', monitor_check_monitor_cron(['started_at' => $now - 1500, 'finished_at' => $now - 1499], $now)['state']);
eq('ran a minute ago: fine', 'ok', monitor_check_monitor_cron(['started_at' => $now - 60, 'finished_at' => $now - 58], $now)['state']);
eq('inside its own run (only the start written so far): fine', 'ok', monitor_check_monitor_cron(['started_at' => $now], $now)['state']);
