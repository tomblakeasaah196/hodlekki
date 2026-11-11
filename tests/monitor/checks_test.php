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

// ---- Server
eq('database answering slowly: worth a look', 'warn', monitor_check_db(['query_ms' => 400, 'connect_ms' => 20])['state']);
eq('database unreachable: bad', 'bad', monitor_check_db(['error' => 'gone away'])['state']);
eq('quota 96% used: bad', 'bad', monitor_check_disk(['used' => 96, 'limit' => 100])['state']);
eq('quota 50% used: fine', 'ok', monitor_check_disk(['used' => 50, 'limit' => 100])['state']);
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
