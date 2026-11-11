<?php
// /includes/monitor/checks.php
/**
 * The health checks (docs/monitor.md, "Health checks").
 *
 *   monitor_checks_run($db, $pdo)   gather facts, judge every check, store the result
 *   monitor_checks_current($db)     what was stored last (for the API, the wall, the summary)
 *
 * Every check is a pure function of facts (monitor_check_*), so each verdict is
 * tested without a server, plus a gatherer (monitor_facts_*) that never throws:
 * a check whose facts cannot be read reports 'unknown' with the reason. Each
 * returns ['key', 'grp', 'label', 'state' ok|warn|bad|unknown, 'detail', 'fix'].
 *
 * The slow facts (an SMTP sign-in, the BulkSMS balance, the cPanel quota, real-
 * user speed) are refreshed hourly from the cron and kept in monitor_state; the
 * PHP and OPcache facts come from a WEB request (web.json, store.php), because
 * the cron's command-line PHP has different settings.
 */

require_once __DIR__ . '/store.php';

/** The cron jobs Health watches, with their schedules (README, "Cron jobs"). */
function monitor_cron_jobs(): array
{
    return [
        'sms_queue_worker'        => ['label' => 'SMS queue worker', 'every' => 1,
            'line' => '* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/sms_queue_worker.php >> /home/smartqaq/sms_worker.log 2>&1'],
        'push_worker'             => ['label' => 'Push notification worker', 'every' => 1,
            'line' => '* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/push_worker.php >/dev/null 2>&1'],
        'special_events'          => ['label' => 'Special Events (reminders, clean-up)', 'every' => 5,
            'line' => '*/5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/special_events.php >/dev/null 2>&1'],
        'reach_lost_souls'        => ['label' => 'Reach: lost souls (07:00)', 'daily' => '07:00',
            'line' => '0 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/reach_lost_souls.php >/dev/null 2>&1'],
        'assimilation_watchlists' => ['label' => 'Assimilation watchlists (07:15)', 'daily' => '07:15',
            'line' => '15 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/assimilation_watchlists.php >/dev/null 2>&1'],
        'monitor'                 => ['label' => 'This monitor', 'every' => 1,
            'line' => '* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/monitor.php >/dev/null 2>&1'],
    ];
}

/** Checks that alert at once when they turn red (the rest wait for the morning summary). */
function monitor_urgent_checks(): array
{
    return ['cron_sms_queue_worker', 'cron_push_worker', 'cron_special_events', 'cron_reach_lost_souls',
            'cron_assimilation_watchlists', 'sms', 'push', 'email', 'db', 'disk', 'backups', 'php', 'deploy',
            'outside_site', 'outside_connect', 'tls', 'dns', 'origin_lock'];
}

function monitor_check(string $key, string $grp, string $label, string $state, string $detail, ?string $fix = null): array
{
    return compact('key', 'grp', 'label', 'state', 'detail', 'fix');
}

/** The worst state in a list: bad, then warn, then unknown, else ok. */
function monitor_checks_worst(array $checks): string
{
    $states = array_column($checks, 'state');
    foreach (['bad', 'warn', 'unknown'] as $s) {
        if (in_array($s, $states, true)) {
            return $s;
        }
    }

    return 'ok';
}

/** "3 minutes ago", "2 hours ago", "4 days ago". */
function monitor_ago(?int $ts, ?int $now = null): string
{
    if ($ts === null) {
        return 'never';
    }
    $s = max(0, ($now ?? monitor_time()) - $ts);
    if ($s < 90) {
        return $s < 45 ? 'just now' : 'a minute ago';
    }
    if ($s < 5400) {
        return round($s / 60) . ' minutes ago';
    }
    if ($s < 129600) {
        return round($s / 3600) . ' hours ago';
    }

    return round($s / 86400) . ' days ago';
}

function monitor_mb(int|float $bytes): string
{
    return $bytes >= 1073741824 ? number_format($bytes / 1073741824, 1) . ' GB' : number_format($bytes / 1048576, 1) . ' MB';
}

/* ═════════════════════════════ the verdicts (pure) ═════════════════════════════ */

/**
 * One cron job from its heartbeat file. $since is when monitoring started (a
 * job is only blamed for not running once it has had the chance).
 */
function monitor_check_cron(string $name, array $job, ?array $beat, int $now, ?int $since): array
{
    $key = 'cron_' . $name;
    $label = $job['label'];
    $fix = 'cPanel → Cron Jobs must have: ' . $job['line'] . ' — then run it once by hand in cPanel → Terminal to see any error.';
    $started = isset($beat['started_at']) ? (int) $beat['started_at'] : null;
    $finished = isset($beat['finished_at']) ? (int) $beat['finished_at'] : null;

    if (isset($job['every'])) {
        $n = (int) $job['every'];
        $warnMin = 3 * $n + 2;
        $badMin = 6 * $n + 10;
        if ($started === null) {
            if ($since !== null && $now - $since < $badMin * 60) {
                return monitor_check($key, 'Background jobs', $label, 'unknown', 'Waiting for its first run since monitoring started ' . monitor_ago($since, $now) . '.');
            }
            return monitor_check($key, 'Background jobs', $label, 'bad', 'Has not run since monitoring started' . ($since ? ' ' . monitor_ago($since, $now) : '') . '. It should run every ' . ($n === 1 ? 'minute' : $n . ' minutes') . '.', $fix);
        }
        $late = intdiv($now - $started, 60);
        $detail = 'Last ran ' . monitor_ago($started, $now)
            . (isset($beat['duration_ms']) && $finished !== null && $finished >= $started ? ' (' . number_format(((int) $beat['duration_ms']) / 1000, 1) . ' s)' : '')
            . '; runs every ' . ($n === 1 ? 'minute' : $n . ' minutes') . '.';
        if ($late >= $badMin) {
            return monitor_check($key, 'Background jobs', $label, 'bad', 'Stopped: ' . $detail, $fix);
        }
        $stuck = $finished === null || $finished < $started;
        if ($stuck && $now - $started > max(10, 5 * $n) * 60) {
            return monitor_check($key, 'Background jobs', $label, 'warn', 'A run started ' . monitor_ago($started, $now) . ' and has not finished.', 'It may be stuck on a slow outside service. Check its log, or run it by hand in cPanel → Terminal.');
        }
        if ($late >= $warnMin) {
            return monitor_check($key, 'Background jobs', $label, 'warn', 'Late: ' . $detail, $fix);
        }
        if (isset($beat['ok']) && !$beat['ok']) {
            return monitor_check($key, 'Background jobs', $label, 'warn', $detail . ' The last run stopped with an error: ' . (string) ($beat['last_error'] ?? 'see Errors') . '.', 'Open Errors, filter on cron, and use "Copy for AI".');
        }

        return monitor_check($key, 'Background jobs', $label, 'ok', $detail);
    }

    // Daily at HH:MM (Lagos). Judge today's run once it is 45 minutes overdue,
    // otherwise yesterday's.
    [$h, $m] = array_map('intval', explode(':', (string) $job['daily']));
    $lagos = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Africa/Lagos'));
    $dueToday = $lagos->setTime($h, $m)->getTimestamp();
    $due = $now >= $dueToday + 45 * 60 ? $dueToday : $dueToday - 86400;
    $when = (new DateTimeImmutable('@' . $due))->setTimezone(new DateTimeZone('Africa/Lagos'));
    $dayWord = $due === $dueToday ? 'today' : 'yesterday';
    if ($since !== null && $since > $due - 600 && ($started === null || $started < $due - 600)) {
        return monitor_check($key, 'Background jobs', $label, 'unknown', 'Waiting for its first run at ' . $job['daily'] . ' (monitoring started ' . monitor_ago($since, $now) . ').');
    }
    if ($started === null || $started < $due - 600) {
        return monitor_check($key, 'Background jobs', $label, 'bad', 'Did not run ' . $dayWord . ' at ' . $when->format('H:i') . '. Last ran ' . monitor_ago($started, $now) . '.', $fix);
    }
    if (isset($beat['ok']) && !$beat['ok']) {
        return monitor_check($key, 'Background jobs', $label, 'warn', 'Ran ' . monitor_ago($started, $now) . ' but stopped with an error: ' . (string) ($beat['last_error'] ?? 'see Errors') . '.', 'Open Errors, filter on cron, and use "Copy for AI".');
    }

    return monitor_check($key, 'Background jobs', $label, 'ok', 'Ran ' . $dayWord . ' at ' . (new DateTimeImmutable('@' . $started))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('H:i') . '.');
}

/** SMS: the queue moving, failures, and the BulkSMS wallet. */
function monitor_check_sms(array $f, int $now): array
{
    $label = 'SMS sending';
    if (empty($f['ready'])) {
        return monitor_check('sms', 'Messages', $label, 'unknown', 'The SMS tables are not in this database yet.');
    }
    $parts = [];
    $state = 'ok';
    $fix = null;
    $oldest = $f['oldest_queued'] ?? null;
    if ($oldest !== null && $now - $oldest > 15 * 60) {
        $state = 'bad';
        $parts[] = (int) $f['queued'] . ' message(s) waiting, the oldest for ' . monitor_ago($oldest, $now) . ' — the queue is not moving';
        $fix = 'Check the SMS queue worker cron and SMS Studio → Settings (the BulkSMS token).';
    } elseif ((int) ($f['queued'] ?? 0) > 0) {
        $parts[] = (int) $f['queued'] . ' waiting';
    }
    if (!empty($f['stuck_sending'])) {
        $state = 'bad';
        $parts[] = (int) $f['stuck_sending'] . ' stuck "sending" for over 15 minutes';
        $fix = $fix ?? 'A worker run died half way. SMS Studio shows them; the worker never re-sends a billable message on its own.';
    }
    $sent = (int) ($f['sent_24h'] ?? 0);
    $failed = (int) ($f['failed_24h'] ?? 0);
    $parts[] = $sent . ' sent and ' . $failed . ' failed in the last 24 hours';
    if ($failed >= 10 && $failed > $sent) {
        $state = $state === 'ok' ? 'warn' : $state;
        $fix = $fix ?? 'Open SMS Studio → History to see why BulkSMS refused them.';
    }
    $bal = $f['balance'] ?? null;
    if (is_array($bal)) {
        if (!empty($bal['error'])) {
            $parts[] = 'balance unknown (' . $bal['error'] . ')';
            $state = $state === 'ok' ? 'warn' : $state;
        } elseif (isset($bal['balance'])) {
            $texts = isset($bal['unit_price']) && $bal['unit_price'] > 0 ? (int) floor($bal['balance'] / $bal['unit_price']) : null;
            $parts[] = 'balance ₦' . number_format((float) $bal['balance'], 2) . ($texts !== null ? ' (about ' . number_format($texts) . ' texts)' : '')
                . ', checked ' . monitor_ago((int) ($bal['at'] ?? $now), $now);
            if ($texts !== null && $texts < 100) {
                $state = 'bad';
                $fix = 'Top up the BulkSMS wallet now: campaigns will stop.';
            } elseif ($texts !== null && $texts < 500 && $state === 'ok') {
                $state = 'warn';
                $fix = 'Top up the BulkSMS wallet soon.';
            }
        }
    }

    return monitor_check('sms', 'Messages', $label, $state, ucfirst(implode('; ', $parts)) . '.', $fix);
}

/** Push notifications: ready, the queue moving, deliveries landing. Never 'bad' for failures alone. */
function monitor_check_push(array $f, int $now): array
{
    $label = 'Push notifications';
    if (empty($f['ready'])) {
        $why = (string) ($f['reason'] ?? '');
        if ($why === 'FEATURE_NOT_READY') {
            return monitor_check('push', 'Messages', $label, 'unknown', 'The push tables are not in this database yet.');
        }
        return monitor_check('push', 'Messages', $label, 'bad', 'Push cannot send: ' . ($why === 'DISABLED' ? 'switched off in the Alerts Centre' : ($why === 'NO_OPENSSL_ECDH' ? 'PHP\'s OpenSSL cannot do the P-256 maths push needs' : ($why === 'NO_VAPID_KEYS' ? 'there are no VAPID keys' : $why))) . '.',
            'Alerts Centre → Settings; docs/pwa_push.md.');
    }
    $detail = (int) ($f['devices'] ?? 0) . ' device(s) subscribed; ' . (int) ($f['sent_24h'] ?? 0) . ' delivered and ' . (int) ($f['failed_24h'] ?? 0) . ' failed in the last 24 hours';
    if ((int) ($f['stuck'] ?? 0) > 0) {
        return monitor_check('push', 'Messages', $label, 'bad', $detail . '; ' . (int) $f['stuck'] . ' waiting for over 15 minutes.', 'Check the push worker cron ("php cron/push_worker.php --status").');
    }
    if ((int) ($f['failed_24h'] ?? 0) >= 10 && (int) $f['failed_24h'] > (int) ($f['sent_24h'] ?? 0)) {
        return monitor_check('push', 'Messages', $label, 'warn', $detail . '.', 'Send yourself a test from the Alerts Centre; if it does not arrive, look at Errors.');
    }

    return monitor_check('push', 'Messages', $label, 'ok', $detail . '.');
}

/** Email: configured, the SMTP sign-in works, and sends are not failing. */
function monitor_check_email(array $f, int $now): array
{
    $label = 'Email sending';
    if (empty($f['configured'])) {
        return monitor_check('email', 'Messages', $label, 'bad', 'Email is not set up: MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS are needed in .env.', 'DEPLOY.md → Email; test with bin/mail_test.php.');
    }
    $t = $f['smtp'] ?? null;
    $failures = (int) ($f['failures_24h'] ?? 0);
    $detail = 'Sends as ' . ($f['from'] ?? 'system@') . ' through ' . ($f['host'] ?? 'localhost') . '.';
    if (is_array($t) && isset($t['ok'])) {
        $detail .= $t['ok'] ? ' Signed in to the mail server ' . monitor_ago((int) $t['at'], $now) . '.' : ' The mail server refused the sign-in ' . monitor_ago((int) $t['at'], $now) . ': ' . $t['error'] . '.';
        if (!$t['ok']) {
            return monitor_check('email', 'Messages', $label, 'bad', $detail, 'Check MAIL_PASSWORD in .env against cPanel → Email Accounts → system@.');
        }
    }
    if ($failures > 0) {
        return monitor_check('email', 'Messages', $label, $failures >= 5 ? 'bad' : 'warn', $detail . ' ' . $failures . ' send(s) failed in the last 24 hours.', 'Open Errors and search "[mailer]".');
    }

    return monitor_check('email', 'Messages', $label, 'ok', $detail . ' No failed sends in the last 24 hours.');
}

/** Database speed: connecting, a simple and a real query, and slow pages. */
function monitor_check_db(array $f): array
{
    $label = 'Database speed';
    if (!empty($f['error'])) {
        return monitor_check('db', 'Server', $label, 'bad', 'The database could not be reached: ' . $f['error'] . '.', 'Check DB_* in .env and cPanel → MySQL Databases; the host may be overloaded.');
    }
    $q = (int) ($f['query_ms'] ?? 0);
    $c = $f['connect_ms'] ?? null;
    $detail = ($f['version'] ?? 'Database') . ': ' . ($c !== null ? 'connects in ' . (int) $c . ' ms, ' : '')
        . 'a members query takes ' . $q . ' ms. ' . (int) ($f['slow_24h'] ?? 0) . ' slow page(s) (over 3 s) in the last 24 hours'
        . (!empty($f['slowest']) ? ', the slowest ' . $f['slowest'] : '') . '.';
    $state = $q > 1000 || ($c !== null && $c > 1000) ? 'bad' : ($q > 250 || ($c !== null && $c > 300) || (int) ($f['slow_24h'] ?? 0) >= 20 ? 'warn' : 'ok');

    return monitor_check('db', 'Server', $label, $state, $detail, $state === 'ok' ? null : 'Look at Errors → Slow pages for the screens involved; a sudden change often follows a deploy.');
}

/** Disk: the cPanel quota when it can be read, else the free space of the disk. */
function monitor_check_disk(array $f): array
{
    $label = 'Disk space';
    if (isset($f['used'], $f['limit']) && $f['limit'] > 0) {
        $share = $f['used'] / $f['limit'];
        $detail = monitor_mb($f['used']) . ' used of the ' . monitor_mb($f['limit']) . ' cPanel quota (' . round($share * 100) . '%).';
        $state = $share >= 0.95 ? 'bad' : ($share >= 0.85 ? 'warn' : 'ok');
    } elseif (isset($f['free'], $f['total']) && $f['total'] > 0) {
        $share = 1 - $f['free'] / $f['total'];
        $detail = monitor_mb($f['free']) . ' free of ' . monitor_mb($f['total']) . ' on the disk (' . round($share * 100) . '% used; the account quota could not be read).';
        $state = $share >= 0.97 ? 'bad' : ($share >= 0.90 ? 'warn' : 'ok');
    } else {
        return monitor_check('disk', 'Server', $label, 'unknown', 'Neither the cPanel quota nor the free space could be read.');
    }

    return monitor_check('disk', 'Server', $label, $state, $detail, $state === 'ok' ? null : 'cPanel → Disk Usage: old backups in the home folder, large error_log files and uploads are the usual suspects.');
}

/** Backups made by JBS HQ's backup system into ~/backups/hodlc (docs in jbspraxis/platform: BACKUPS.md). */
function monitor_check_backups(array $f, int $now): array
{
    $label = 'Backups';
    if (empty($f['readable'])) {
        return monitor_check('backups', 'Server', $label, 'warn', 'The backup folder ' . ($f['dir'] ?? '~/backups/hodlc') . ' cannot be seen from this site, so backups cannot be checked here.', 'JBS HQ → System health → Server backups shows them.');
    }
    if (empty($f['db_at'])) {
        return monitor_check('backups', 'Server', $label, 'bad', 'No database backup of this site was found in ' . $f['dir'] . '.', 'JBS HQ → System health → Server backups (the nightly 02:00 job).');
    }
    $age = ($now - (int) $f['db_at']) / 3600;
    $detail = 'Last database backup ' . monitor_ago((int) $f['db_at'], $now) . ' (' . monitor_mb((int) $f['db_size']) . ', encrypted)';
    $state = $age > 26 ? 'bad' : 'ok';
    $fix = $age > 26 ? 'The nightly backup did not run: JBS HQ → System health → Server backups.' : null;
    if (!empty($f['prev_size']) && (int) $f['db_size'] < 0.5 * (int) $f['prev_size']) {
        $state = $state === 'ok' ? 'warn' : $state;
        $detail .= ', less than half the size of the one before (' . monitor_mb((int) $f['prev_size']) . ')';
        $fix = $fix ?? 'Check the dump in JBS HQ → Server backups: a backup that shrank by half is often incomplete.';
    }
    if (!empty($f['files_at'])) {
        $detail .= '; uploads backed up ' . monitor_ago((int) $f['files_at'], $now);
        if ($now - (int) $f['files_at'] > 8 * 86400) {
            $state = $state === 'ok' ? 'warn' : $state;
            $fix = $fix ?? 'The weekly uploads backup (Sundays 03:00) is late.';
        }
    } else {
        $detail .= '; no uploads backup yet';
    }

    return monitor_check('backups', 'Server', $label, $state, $detail . '.', $fix);
}

/** PHP as the WEB server runs it: version, extensions, errors hidden, the prepend. */
function monitor_check_php(?array $web, int $now): array
{
    $label = 'PHP (web)';
    if (!$web) {
        return monitor_check('php', 'Server', $label, 'unknown', 'No page has reported how the web PHP runs yet (it does about every 10 minutes once pages are visited).');
    }
    $required = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'json', 'gd', 'zip', 'dom', 'xml', 'simplexml', 'xmlwriter', 'zlib', 'iconv', 'ctype'];
    $cushioned = ['fileinfo', 'intl', 'sodium', 'exif'];
    $ext = (array) ($web['extensions'] ?? []);
    $missing = array_values(array_filter($required, fn ($e) => isset($ext[$e]) && !$ext[$e]));
    $soft = array_values(array_filter($cushioned, fn ($e) => isset($ext[$e]) && !$ext[$e]));
    $problems = [];
    $state = 'ok';
    if (version_compare((string) $web['php'], '8.3', '<')) {
        $problems[] = 'PHP ' . $web['php'] . ' is older than 8.3';
        $state = 'bad';
    }
    if ($missing) {
        $problems[] = 'missing ' . implode(', ', $missing);
        $state = 'bad';
    }
    if (!empty($web['display_errors'])) {
        $problems[] = 'display_errors is ON, so visitors can see file paths and code when something fails';
        $state = 'bad';
    }
    $detail = 'PHP ' . $web['php'] . ' (' . $web['sapi'] . '), memory ' . $web['memory_limit'] . ', time limit ' . (int) $web['max_execution'] . ' s'
        . ($soft ? '; without ' . implode(', ', $soft) . ' (the code has fallbacks: DEPLOY.md, "PHP extensions")' : '')
        . '; the monitor\'s prepend is ' . (!empty($web['prepend']) ? 'on (every page is watched)' : 'off (pages that never load db.php are covered by the log reader)')
        . '. Seen ' . monitor_ago((int) $web['at'], $now) . '.';

    return monitor_check('php', 'Server', $label, $state, $problems ? ucfirst(implode('; ', $problems)) . '. ' . $detail : $detail,
        $problems ? 'cPanel → MultiPHP INI Editor (display_errors Off) / Select PHP extensions, or ask the host.' : null);
}

/**
 * The share of code loads OPcache served from memory, leaving out each file's
 * first load (HQ's warm hit rate, 11 Nov 2026): LiteSpeed restarts PHP after it
 * has been idle, and a fresh cache misses every file once with nothing wrong.
 * Misses beyond one per cached file are code compiled again: that is the problem.
 * Null until there are 200 loads to judge.
 */
function monitor_warm_hit_rate(array $f): ?float
{
    if (!isset($f['hits'], $f['misses'], $f['cached_files'])) {
        return isset($f['hit_rate']) ? round((float) $f['hit_rate'], 1) : null;
    }
    $again = max(0, (int) $f['misses'] - (int) $f['cached_files']);
    $loads = (int) $f['hits'] + $again;

    return $loads >= 200 ? round(100 * (int) $f['hits'] / $loads, 1) : null;
}

/** Server speed settings (learned from HQ's speedFrom()). Never 'bad'. */
function monitor_check_speed(?array $web): array
{
    $label = 'Server speed settings';
    if (!$web) {
        return monitor_check('speed', 'Server', $label, 'unknown', 'No page has reported how the web PHP runs yet.');
    }
    $problems = $fixes = [];
    $rate = monitor_warm_hit_rate($web);
    if (empty($web['early_finish'])) {
        $problems[] = 'PHP runs as ' . $web['sapi'] . ', which cannot answer a page before slow work (push, the monitor) is done';
        $fixes[] = 'cPanel → MultiPHP Manager: PHP-FPM or LiteSpeed (LSAPI) for hodlekki.org.';
    }
    if (empty($web['opcache'])) {
        $problems[] = 'OPcache is off, so every page compiles the code again';
        $fixes[] = 'Ask the host to switch OPcache on (opcache.enable=1, memory_consumption=256, max_accelerated_files=32531).';
    } elseif (!empty($web['opcache_full']) || !empty($web['restarts']) || ($rate !== null && $rate < 95)) {
        $problems[] = 'OPcache is full or misses often' . ($rate !== null ? " ({$rate}% from memory)" : '') . (!empty($web['restarts']) ? ', and it has had to start again for lack of room' : '');
        $fixes[] = 'Ask the host for more OPcache memory (memory_consumption=256, max_accelerated_files=32531).';
    }
    if ($problems) {
        return monitor_check('speed', 'Server', $label, 'warn', ucfirst(implode('; ', $problems)) . '.', implode(' ', $fixes));
    }

    return monitor_check('speed', 'Server', $label, 'ok', 'PHP (' . $web['sapi'] . ') answers before slow work is done; OPcache serves '
        . ($rate !== null ? $rate . '% of code from memory after each file\'s first load' : 'code from memory')
        . (isset($web['cached_files']) ? ' (' . number_format((int) $web['cached_files']) . ' files' . (isset($web['free_mb']) ? ', ' . $web['free_mb'] . ' MB free' : '') . ')' : '') . '.');
}

/** Error log sizes. */
function monitor_check_logs(array $f): array
{
    $total = (int) ($f['total'] ?? 0);
    $big = $f['biggest'] ?? null;
    $detail = monitor_mb($total) . ' in ' . (int) ($f['files'] ?? 0) . ' error_log file(s)' . ($big ? '; the largest is ' . $big['path'] . ' (' . monitor_mb($big['bytes']) . ')' : '') . '.';
    $state = $total > 500 * 1048576 ? 'bad' : ($total > 100 * 1048576 || ($big && $big['bytes'] > 50 * 1048576) ? 'warn' : 'ok');

    return monitor_check('logs', 'Server', 'Log files', $state, $detail,
        $state === 'ok' ? null : 'The monitor has read them already: empty the largest in cPanel → File Manager (do not delete the file, empty it), then fix the warning that fills it.');
}

/**
 * The deploy log (~/deploy.log, written by bin/deploy.sh): every run from its
 * "starting" line to "OK", with the commit it deployed.
 *
 * @return list<array{started_at:?int,ok:bool,sha:?string,commit:?string,subject:?string,last:?string,finished_at:?int}>
 */
function monitor_deploy_log_parse(string $text): array
{
    $runs = [];
    $cur = null;
    foreach (preg_split("/\r?\n/", $text) ?: [] as $line) {
        if (preg_match('/^\[deploy\] (\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:?\d{2}) starting/', $line, $m)) {
            if ($cur !== null) {
                $runs[] = $cur;
            }
            $cur = ['started_at' => strtotime($m[1]) ?: null, 'ok' => false, 'sha' => null, 'commit' => null, 'subject' => null, 'last' => null, 'finished_at' => null];
            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (preg_match('/^\[deploy\] post-pull HEAD: ([0-9a-f]{7,40})/', $line, $m)) {
            $cur['sha'] = $m[1];
        } elseif (preg_match('/^\[deploy\] commit: ([0-9a-f]{6,12}) (.*?)(?: \([^()]*\))?$/', $line, $m)) {
            $cur['commit'] = $m[1];
            $cur['subject'] = mb_substr($m[2], 0, 200);
        } elseif (preg_match('/^\[deploy\] (\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:?\d{2}) OK/', $line, $m)) {
            $cur['ok'] = true;
            $cur['finished_at'] = strtotime($m[1]) ?: null;
        } elseif (trim($line) !== '' && !str_starts_with($line, '====')) {
            $cur['last'] = mb_substr(trim($line), 0, 300);
        }
    }
    if ($cur !== null) {
        $runs[] = $cur;
    }

    return $runs;
}

/** The last deploy: did it finish, and is the server running what main has? */
function monitor_check_deploy(array $runs, ?array $probe, int $now): array
{
    $label = 'Last deploy';
    if (!$runs) {
        return monitor_check('deploy', 'Server', $label, 'unknown', 'No deploy found in ~/deploy.log.');
    }
    $last = end($runs);
    $ok = array_values(array_filter($runs, fn ($r) => $r['ok']));
    $good = $ok ? end($ok) : null;
    $what = $good ? ($good['commit'] ?? substr((string) $good['sha'], 0, 7)) . ' "' . ($good['subject'] ?? '') . '", ' . monitor_ago($good['finished_at'] ?? $good['started_at'], $now) : 'none';
    if (!$last['ok'] && $last['started_at'] !== null && $now - $last['started_at'] > 15 * 60) {
        return monitor_check('deploy', 'Server', $label, 'bad', 'The deploy that started ' . monitor_ago($last['started_at'], $now) . ' did not finish' . ($last['last'] ? ' (last line: ' . monitor_redact($last['last']) . ')' : '') . '. Running: ' . $what . '.',
            'Read ~/deploy.log in cPanel → Terminal ("tail -n 80 ~/deploy.log"), fix, and deploy again.');
    }
    $detail = 'Running ' . $what . '.';
    $main = is_array($probe) ? (string) ($probe['main_sha'] ?? '') : '';
    $deployed = $good ? (string) ($good['sha'] ?? '') : '';
    if ($main !== '' && $deployed !== '' && !str_starts_with($main, substr($deployed, 0, 7)) && !str_starts_with($deployed, substr($main, 0, 7))
        && isset($probe['at']) && $good && ($good['finished_at'] ?? 0) < (int) $probe['at'] - 30 * 60) {
        return monitor_check('deploy', 'Server', $label, 'warn', $detail . ' GitHub\'s main is at ' . substr($main, 0, 7) . ', which has not reached the server.', 'GitHub → Actions → the last "CI + Deploy" run on main: did the deploy job fail?');
    }

    return monitor_check('deploy', 'Server', $label, 'ok', $detail);
}

/**
 * The outside view, from the probe's latest report and the day's history.
 * $latest: target => row (ok, http_status, ttfb_ms, detail array, at); $uptime: target => [up, total].
 */
function monitor_check_outside(array $latest, array $uptime, int $now, int $everyMin, bool $lockConfigured): array
{
    $checks = [];
    $lastAt = $latest ? max(array_map(fn ($r) => (int) $r['at'], $latest)) : null;
    $stale = $lastAt === null || $now - $lastAt > max(3 * $everyMin, 90) * 60;
    $grp = 'From outside';
    $fixProbe = 'GitHub → Actions → "Outside probe": is it running, and are MONITOR_PROBE_SECRET (secret) and the URL set? DEPLOY.md → "Outside probe".';
    if ($lastAt === null) {
        $checks[] = monitor_check('probe', $grp, 'Outside probe', 'unknown', 'No report from the outside probe yet.', $fixProbe);
        foreach (['outside_site' => 'hodlekki.org', 'outside_connect' => 'connect.hodlekki.org', 'tls' => 'Certificates', 'dns' => 'DNS and mail records', 'origin_lock' => 'Origin lock'] as $k => $l) {
            $checks[] = monitor_check($k, $grp, $l, 'unknown', 'Waiting for the outside probe.');
        }
        return $checks;
    }
    $checks[] = monitor_check('probe', $grp, 'Outside probe', $stale ? 'warn' : 'ok',
        'Last report ' . monitor_ago($lastAt, $now) . ' (it runs about every ' . ($everyMin >= 60 ? intdiv($everyMin, 60) . ' hour' . ($everyMin >= 120 ? 's' : '') : $everyMin . ' minutes') . ' from GitHub).'
        . ($stale ? ' Reports have stopped: either the probe stopped, or it could not reach the site to report.' : ''), $stale ? $fixProbe : null);

    foreach (['site' => ['outside_site', 'hodlekki.org'], 'connect' => ['outside_connect', 'connect.hodlekki.org']] as $t => [$key, $label]) {
        $r = $latest[$t] ?? null;
        if ($r === null) {
            $checks[] = monitor_check($key, $grp, $label, 'unknown', 'Not in the probe\'s last report.');
            continue;
        }
        [$up, $total] = $uptime[$t] ?? [0, 0];
        $pct = $total > 0 ? round(100 * $up / $total, 1) : null;
        $detail = ($r['ok'] ? 'Up' : 'DOWN') . ' (HTTP ' . (int) $r['http_status'] . ', first byte in ' . (int) $r['ttfb_ms'] . ' ms) ' . monitor_ago((int) $r['at'], $now)
            . ($pct !== null ? '; ' . $pct . '% of ' . $total . ' checks up in the last 7 days' : '') . '.';
        $state = !$r['ok'] ? 'bad' : ((int) $r['ttfb_ms'] > 2500 || ($pct !== null && $pct < 99) ? 'warn' : 'ok');
        $checks[] = monitor_check($key, $grp, $label, $state, $detail, !$r['ok'] ? 'Open the site yourself; then cPanel (is the account suspended?), Cloudflare (SSL mode, rules) and Errors.' : null);
    }

    $tls = $latest['tls'] ?? null;
    if ($tls === null) {
        $checks[] = monitor_check('tls', $grp, 'Certificates', 'unknown', 'Not in the probe\'s last report.');
    } else {
        $d = (array) ($tls['detail'] ?? []);
        $edge = $d['edge_days'] ?? null;
        $origin = $d['origin_days'] ?? null;
        $min = min(array_filter([$edge, $origin], fn ($v) => $v !== null) ?: [999]);
        $state = $min < 5 ? 'bad' : ($min < 14 ? 'warn' : 'ok');
        $checks[] = monitor_check('tls', $grp, 'Certificates', $state,
            'Cloudflare\'s certificate: ' . ($edge !== null ? $edge . ' days left' : 'not read') . '; the server\'s origin certificate: ' . ($origin !== null ? $origin . ' days left' : 'not read (no ORIGIN_IP for the probe)') . '.',
            $state === 'ok' ? null : 'Cloudflare renews its own; the origin certificate is renewed in Cloudflare → SSL/TLS → Origin Server and installed in cPanel → SSL/TLS.');
    }

    $dns = $latest['dns'] ?? null;
    if ($dns === null) {
        $checks[] = monitor_check('dns', $grp, 'DNS and mail records', 'unknown', 'Not in the probe\'s last report.');
    } else {
        $d = (array) ($dns['detail'] ?? []);
        $missingBad = array_values(array_filter(['mx' => 'MX', 'spf' => 'SPF', 'a' => 'A'], fn ($v, $k) => empty($d[$k]), ARRAY_FILTER_USE_BOTH));
        $missingWarn = array_values(array_filter(['dkim' => 'DKIM', 'dmarc' => 'DMARC'], fn ($v, $k) => empty($d[$k]), ARRAY_FILTER_USE_BOTH));
        $state = $missingBad ? 'bad' : ($missingWarn ? 'warn' : 'ok');
        $checks[] = monitor_check('dns', $grp, 'DNS and mail records', $state,
            $state === 'ok' ? 'The site\'s address, MX, SPF, DKIM and DMARC records are all published.' : 'Missing: ' . implode(', ', array_merge($missingBad, $missingWarn)) . '. Without them emails land in spam or bounce.',
            $state === 'ok' ? null : 'Cloudflare → DNS for hodlekki.org (DEPLOY.md → "Addresses, origin lock and email").');
    }

    $lock = $latest['origin_lock'] ?? null;
    if (!$lockConfigured) {
        $checks[] = monitor_check('origin_lock', $grp, 'Origin lock', 'warn', 'The lock is off: .env has no EDGE_TOKEN, so the server answers requests that went around Cloudflare.', 'DEPLOY.md → "Origin lock" (Cloudflare rule first, then EDGE_TOKEN, then a deploy).');
    } elseif ($lock === null || ($lock['detail']['tested'] ?? true) === false) {
        $checks[] = monitor_check('origin_lock', $grp, 'Origin lock', 'unknown', 'The probe does not test it yet (it needs the server\'s address as the ORIGIN_IP secret).', 'GitHub → Settings → Secrets → ORIGIN_IP.');
    } else {
        $checks[] = monitor_check('origin_lock', $grp, 'Origin lock', $lock['ok'] ? 'ok' : 'bad',
            $lock['ok'] ? 'A request straight to the server, around Cloudflare, is refused (HTTP ' . (int) $lock['http_status'] . ').'
                        : 'A request straight to the server, around Cloudflare, was ANSWERED (HTTP ' . (int) $lock['http_status'] . '): the lock is not working.',
            $lock['ok'] ? null : 'Run bash ~/repositories/hodlekki/bin/edge_lock.sh and check the block at the top of the docroot .htaccess.');
    }

    return $checks;
}

/** Core Web Vitals thresholds (web.dev): [good up to, poor above]. CLS is x1000. */
function monitor_vital_limits(): array
{
    return ['lcp' => [2500, 4000], 'inp' => [200, 500], 'cls' => [100, 250], 'ttfb' => [800, 1800], 'fcp' => [1800, 3000]];
}

function monitor_vital_rating(string $metric, int $value): string
{
    [$good, $poor] = monitor_vital_limits()[$metric];

    return $value <= $good ? 'good' : ($value > $poor ? 'poor' : 'ni');
}

function monitor_vital_text(string $metric, int $v): string
{
    return match ($metric) {
        'cls'   => number_format($v / 1000, 2),
        'inp'   => $v . ' ms',
        default => number_format($v / 1000, 1) . ' s',
    };
}

/** Real-user speed for one kind of device: p75 of each vital over 7 days. */
function monitor_check_rum(string $device, array $agg): array
{
    $key = 'rum_' . $device;
    $label = $device === 'phone' ? 'Real-user speed: phones' : 'Real-user speed: computers';
    $n = (int) ($agg['n'] ?? 0);
    if ($n < 20) {
        return monitor_check($key, 'Real users', $label, 'unknown', 'Not enough visits measured yet (' . $n . ' in 7 days; 20 needed).');
    }
    $parts = [];
    $worst = 'good';
    foreach (['lcp' => 'loading (LCP)', 'inp' => 'response to taps (INP)', 'cls' => 'layout shifts (CLS)', 'ttfb' => 'server response (TTFB)'] as $m => $what) {
        if (!isset($agg[$m])) {
            continue;
        }
        $rating = monitor_vital_rating($m, (int) $agg[$m]);
        $parts[] = $what . ' ' . monitor_vital_text($m, (int) $agg[$m]) . ($rating === 'good' ? '' : ($rating === 'poor' ? ' (poor)' : ' (needs work)'));
        if ($rating === 'poor' || ($rating === 'ni' && $worst === 'good')) {
            $worst = $rating;
        }
    }
    $state = $worst === 'poor' ? 'bad' : ($worst === 'ni' ? 'warn' : 'ok');
    $detail = '75% of ' . number_format($n) . ' visits in 7 days were at least this fast: ' . implode(', ', $parts) . '.'
        . (!empty($agg['worst_page']) ? ' Slowest page: ' . $agg['worst_page'] . '.' : '');

    return monitor_check($key, 'Real users', $label, $state, $detail, $state === 'ok' ? null : 'Open Speed for the pages and metrics behind it.');
}

/** Open errors in the last 24 hours. */
function monitor_check_errors(array $f): array
{
    $open = (int) ($f['open_24h'] ?? 0);
    $new = (int) ($f['new_24h'] ?? 0);
    $fatal = (int) ($f['fatal_hour'] ?? 0);
    $back = (int) ($f['back_24h'] ?? 0);
    $state = $fatal > 0 || $new > 5 ? 'bad' : ($open > 0 ? 'warn' : 'ok');
    $detail = $open === 0 ? 'No open errors in the last 24 hours.'
        : $open . ' open problem(s) seen in the last 24 hours: ' . $new . ' new, ' . $back . ' came back' . ($fatal ? ', ' . $fatal . ' fatal in the last hour' : '') . '.';

    return monitor_check('errors', 'Errors', 'Errors', $state, $detail, $open ? 'Open Errors and use "Copy for AI" on each.' : null);
}

/* ═════════════════════════════ gathering facts (never throw) ═════════════════════════════ */

function monitor_table_exists(PDO $db, string $table): bool
{
    try {
        $db->query('SELECT 1 FROM ' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ' WHERE 1 = 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function monitor_beats(): array
{
    $dir = hod_monitor_data_dir();
    $out = [];
    foreach ($dir !== null ? (glob($dir . '/beats/*.json') ?: []) : [] as $f) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d)) {
            $out[basename($f, '.json')] = $d;
        }
    }

    return $out;
}

/** When monitoring started on this server (the first web flush or cron run). */
function monitor_installed_at(): ?int
{
    $dir = hod_monitor_data_dir();
    $v = $dir !== null ? @file_get_contents($dir . '/installed_at') : false;

    return $v !== false && ctype_digit(trim($v)) ? (int) trim($v) : null;
}

function monitor_facts_sms(PDO $pdo, PDO $db): array
{
    if (!monitor_table_exists($pdo, 'sms_queue')) {
        return ['ready' => false];
    }
    $f = ['ready' => true];
    $now = monitor_time();
    $r = $pdo->query("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM sms_queue WHERE status = 'queued'")->fetch(PDO::FETCH_ASSOC);
    $f['queued'] = (int) $r['n'];
    $f['oldest_queued'] = $r['oldest'] ? monitor_ts((string) $r['oldest']) : null;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_queue WHERE status = 'sending' AND locked_at < ?");
    $stmt->execute([monitor_fmt($now - 900)]);
    $f['stuck_sending'] = (int) $stmt->fetchColumn();
    if (monitor_table_exists($pdo, 'sms_log')) {
        $stmt = $pdo->prepare("SELECT SUM(CASE WHEN status IN ('sent','delivered','pending','unknown') THEN 1 ELSE 0 END) AS sent,
                                      SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
                                 FROM sms_log WHERE created_at >= ?");
        try {
            $stmt->execute([monitor_fmt($now - 86400)]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            $f['sent_24h'] = (int) ($s['sent'] ?? 0);
            $f['failed_24h'] = (int) ($s['failed'] ?? 0);
        } catch (Throwable $e) {
            // older sms_log without created_at: counts are left out
        }
    }
    $bal = json_decode((string) monitor_state_get($db, 'sms_balance'), true);
    $f['balance'] = is_array($bal) ? $bal : null;

    return $f;
}

/** Hourly, from the cron: ask BulkSMS for the wallet balance. */
function monitor_refresh_sms_balance(PDO $pdo, PDO $db): void
{
    $file = monitor_root() . '/includes/sms_functions.php';
    if (!is_file($file)) {
        return;
    }
    require_once $file;
    if (!function_exists('sms_load_settings') || !function_exists('sms_fetch_balance')) {
        return;
    }
    $settings = sms_load_settings($pdo);
    if (!is_array($settings) || empty($settings['api_token'])) {
        monitor_state_set($db, 'sms_balance', (string) json_encode(['error' => 'BulkSMS is not configured', 'at' => monitor_time()]));
        return;
    }
    $r = sms_fetch_balance($settings, false);
    monitor_state_set($db, 'sms_balance', (string) json_encode([
        'balance'    => $r['ok'] ? (float) $r['balance'] : null,
        'unit_price' => function_exists('sms_unit_price') ? (float) sms_unit_price($settings) : null,
        'error'      => $r['ok'] ? null : mb_substr(monitor_redact((string) $r['error']), 0, 200),
        'at'         => monitor_time(),
    ]));
}

function monitor_facts_push(PDO $pdo): array
{
    $file = monitor_root() . '/includes/push_helpers.php';
    if (is_file($file)) {
        require_once $file;
    }
    if (!function_exists('push_ready')) {
        return ['ready' => false, 'reason' => 'FEATURE_NOT_READY'];
    }
    $ready = push_ready($pdo);
    $f = ['ready' => $ready['ok'], 'reason' => $ready['reason']];
    if (!$ready['ok']) {
        return $f;
    }
    $now = monitor_time();
    $f['devices'] = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) FROM push_queue WHERE created_at >= ?");
    $stmt->execute([monitor_fmt($now - 86400)]);
    [$sent, $failed] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $f['sent_24h'] = (int) $sent;
    $f['failed_24h'] = (int) $failed;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM push_queue WHERE (status = 'pending' AND available_at < ?) OR (status = 'sending' AND created_at < ?)");
    $stmt->execute([monitor_fmt($now - 900), monitor_fmt($now - 900)]);
    $f['stuck'] = (int) $stmt->fetchColumn();

    return $f;
}

function monitor_facts_email(PDO $db): array
{
    $file = monitor_root() . '/includes/mailer.php';
    if (is_file($file)) {
        require_once $file;
    }
    $s = function_exists('hod_mail_settings') ? hod_mail_settings() : [];
    $f = [
        'configured' => function_exists('hod_mail_configured') && hod_mail_configured($s),
        'host'       => $s['host'] ?? null,
        'from'       => $s['from_address'] ?? null,
        'smtp'       => json_decode((string) monitor_state_get($db, 'smtp_test'), true),
    ];
    $stmt = $db->prepare("SELECT COALESCE(SUM(n), 0) FROM monitor_error_hourly h JOIN monitor_error_groups g ON g.id = h.group_id
                           WHERE h.hour >= ? AND (g.title LIKE '[mailer]%' OR g.title LIKE '%mailer%send failed%')");
    $stmt->execute([monitor_fmt(monitor_time() - 86400, 'Y-m-d H:00:00')]);
    $f['failures_24h'] = (int) $stmt->fetchColumn();

    return $f;
}

/** Hourly, from the cron: sign in to the SMTP server without sending anything. */
function monitor_refresh_smtp_test(PDO $db): void
{
    $file = monitor_root() . '/includes/mailer.php';
    if (!is_file($file)) {
        return;
    }
    require_once $file;
    if (!function_exists('hod_mail_configured') || !hod_mail_configured()) {
        return;
    }
    $ok = false;
    $error = null;
    try {
        $mail = hod_mailer();
        $mail->Timeout = 10;
        $ok = $mail->smtpConnect();
        $mail->smtpClose();
        if (!$ok) {
            $error = 'could not connect or sign in';
        }
    } catch (Throwable $e) {
        $error = mb_substr(monitor_redact($e->getMessage()), 0, 200);
    }
    monitor_state_set($db, 'smtp_test', (string) json_encode(['ok' => $ok, 'error' => $error, 'at' => monitor_time()]));
}

function monitor_facts_db(PDO $pdo, PDO $db): array
{
    $f = [];
    try {
        $t = microtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $f['ping_ms'] = (int) round((microtime(true) - $t) * 1000);
        $t = microtime(true);
        $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $f['query_ms'] = (int) round((microtime(true) - $t) * 1000);
        $f['version'] = monitor_driver($pdo) === 'mysql' ? (string) $pdo->query('SELECT VERSION()')->fetchColumn() : 'SQLite';
        if (stripos($f['version'], 'maria') !== false) {
            $f['version'] = 'MariaDB ' . preg_replace('/-.*$/', '', $f['version']);
        } elseif (monitor_driver($pdo) === 'mysql') {
            $f['version'] = 'MySQL ' . $f['version'];
        }
    } catch (Throwable $e) {
        $f['error'] = mb_substr(monitor_redact($e->getMessage()), 0, 200);
        return $f;
    }
    if (monitor_driver($pdo) === 'mysql') {
        try {
            $t = microtime(true);
            $probe = new PDO('mysql:host=' . (hod_monitor_env('DB_HOST') ?: 'localhost') . ';dbname=' . hod_monitor_env('DB_NAME'), hod_monitor_env('DB_USER'), hod_monitor_env('DB_PASS'), [PDO::ATTR_TIMEOUT => 5]);
            $f['connect_ms'] = (int) round((microtime(true) - $t) * 1000);
            $probe = null;
        } catch (Throwable $e) {
            $f['error'] = 'a new connection failed: ' . mb_substr(monitor_redact($e->getMessage()), 0, 160);
        }
    }
    $stmt = $db->prepare("SELECT COALESCE(SUM(h.n), 0) FROM monitor_error_hourly h JOIN monitor_error_groups g ON g.id = h.group_id WHERE g.kind = 'slow' AND h.hour >= ?");
    $stmt->execute([monitor_fmt(monitor_time() - 86400, 'Y-m-d H:00:00')]);
    $f['slow_24h'] = (int) $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT title FROM monitor_error_groups WHERE kind = 'slow' AND last_seen_at >= ? ORDER BY occurrences DESC LIMIT 1");
    $stmt->execute([monitor_fmt(monitor_time() - 86400)]);
    $slowest = $stmt->fetchColumn();
    $f['slowest'] = $slowest !== false ? preg_replace('/^Slow \w+: /', '', (string) $slowest) : null;

    return $f;
}

/** Hourly, from the cron: the cPanel quota (uapi), or the disk's free space. */
function monitor_refresh_disk(PDO $db): void
{
    $f = [];
    if (function_exists('exec') && PHP_SAPI === 'cli' && is_executable('/usr/bin/uapi')) {
        $out = [];
        @exec('/usr/bin/uapi --output=json Quota get_quota_info 2>/dev/null', $out);
        $j = json_decode(implode("\n", $out), true);
        $d = $j['result']['data'] ?? null;
        if (is_array($d) && isset($d['megabytes_used'])) {
            $limit = (float) ($d['megabyte_limit'] ?? 0);
            $f = ['used' => (float) $d['megabytes_used'] * 1048576, 'limit' => $limit > 0 ? $limit * 1048576 : 0];
        }
    }
    if (empty($f['limit'])) {
        $free = @disk_free_space(monitor_root());
        $total = @disk_total_space(monitor_root());
        if ($free !== false && $total) {
            $f = ['free' => $free, 'total' => $total];
        }
    }
    $f['at'] = monitor_time();
    monitor_state_set($db, 'disk', (string) json_encode($f));
}

function monitor_facts_backups(): array
{
    $dir = hod_monitor_env('MONITOR_BACKUP_DIR');
    if ($dir === '') {
        $root = str_replace('\\', '/', monitor_root());
        $dir = preg_match('#^(/home\d*/[^/]+)/#', $root . '/', $m) ? $m[1] . '/backups/hodlc' : '';
    }
    $f = ['dir' => $dir !== '' ? $dir : '~/backups/hodlc', 'readable' => $dir !== '' && is_dir($dir) && is_readable($dir)];
    if (!$f['readable']) {
        return $f;
    }
    foreach (['db', 'files'] as $kind) {
        $files = glob($dir . '/*_' . $kind . '_*') ?: [];
        usort($files, fn ($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
        if ($files) {
            $f[$kind . '_at'] = (int) @filemtime($files[0]);
            $f[$kind . '_size'] = (int) @filesize($files[0]);
            if (isset($files[1])) {
                $f[$kind === 'db' ? 'prev_size' : 'files_prev_size'] = (int) @filesize($files[1]);
            }
        }
    }

    return $f;
}

function monitor_facts_logs(): array
{
    $f = ['total' => 0, 'files' => 0, 'biggest' => null];
    require_once __DIR__ . '/logscan.php';
    foreach (monitor_log_sources() as $p) {
        $b = (int) @filesize($p);
        $f['total'] += $b;
        $f['files']++;
        if ($f['biggest'] === null || $b > $f['biggest']['bytes']) {
            $f['biggest'] = ['path' => monitor_short_path($p), 'bytes' => $b];
        }
    }

    return $f;
}

/** The deploy log's runs; also notes the running release for every recorded error (release.json). */
function monitor_facts_deploy(): array
{
    $path = hod_monitor_env('MONITOR_DEPLOY_LOG');
    if ($path === '') {
        $root = str_replace('\\', '/', monitor_root());
        $path = preg_match('#^(/home\d*/[^/]+)/#', $root . '/', $m) ? $m[1] . '/deploy.log' : '';
    }
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $size = (int) @filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return [];
    }
    fseek($fh, max(0, $size - 262144));
    $text = (string) fread($fh, 262144);
    fclose($fh);
    $runs = monitor_deploy_log_parse($text);

    $ok = array_values(array_filter($runs, fn ($r) => $r['ok'] && $r['sha']));
    $dir = hod_monitor_data_dir();
    if ($ok && $dir !== null) {
        $good = end($ok);
        $release = ['sha' => $good['sha'], 'commit' => $good['commit'], 'subject' => $good['subject'], 'at' => $good['finished_at'] ?? $good['started_at']];
        $current = is_file($dir . '/release.json') ? json_decode((string) @file_get_contents($dir . '/release.json'), true) : null;
        if (($current['sha'] ?? null) !== $release['sha']) {
            @file_put_contents($dir . '/release.json', (string) json_encode($release), LOCK_EX);
        }
    }

    return $runs;
}

/** The probe's latest result per target, and 7-day uptime for the two sites. */
function monitor_facts_outside(PDO $db): array
{
    $latest = [];
    $uptime = [];
    $since = monitor_fmt(monitor_time() - 7 * 86400);
    $stmt = $db->query('SELECT p.* FROM monitor_probes p JOIN (SELECT target, MAX(id) AS id FROM monitor_probes GROUP BY target) m ON m.id = p.id');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $latest[$r['target']] = [
            'ok' => (bool) $r['ok'], 'http_status' => $r['http_status'], 'ttfb_ms' => $r['ttfb_ms'],
            'detail' => json_decode((string) $r['detail'], true) ?: [], 'at' => monitor_ts($r['received_at']),
        ];
    }
    $stmt = $db->prepare("SELECT target, SUM(ok) AS up, COUNT(*) AS total FROM monitor_probes WHERE received_at >= ? AND target IN ('site', 'connect') GROUP BY target");
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uptime[$r['target']] = [(int) $r['up'], (int) $r['total']];
    }
    $meta = json_decode((string) monitor_state_get($db, 'probe_last'), true);

    return ['latest' => $latest, 'uptime' => $uptime, 'meta' => is_array($meta) ? $meta : null];
}

function monitor_facts_errors(PDO $db): array
{
    $now = monitor_time();
    $day = monitor_fmt($now - 86400);
    $stmt = $db->prepare(
        "SELECT SUM(CASE WHEN status IN ('new', 'investigating') THEN 1 ELSE 0 END) AS open_24h,
                SUM(CASE WHEN status IN ('new', 'investigating') AND first_seen_at >= ? THEN 1 ELSE 0 END) AS new_24h,
                SUM(CASE WHEN regressed_at >= ? THEN 1 ELSE 0 END) AS back_24h,
                SUM(CASE WHEN status IN ('new', 'investigating') AND level = 'fatal' AND last_seen_at >= ? THEN 1 ELSE 0 END) AS fatal_hour
           FROM monitor_error_groups
          WHERE last_seen_at >= ? AND kind NOT IN ('slow', 'csp') AND level NOT IN ('notice', 'deprecated')"
    );
    $stmt->execute([$day, $day, monitor_fmt($now - 3600), $day]);

    return array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

/* ═════════════════════════════ running and storing ═════════════════════════════ */

/**
 * Judge every check from fresh facts and store the result. $pdo is the site's
 * connection (its tables), $db the monitor's (often the same). $slow refreshes
 * the hourly facts first. Returns the checks.
 */
function monitor_checks_run(PDO $pdo, PDO $db, bool $slow = false): array
{
    $now = monitor_time();
    if ($slow && PHP_SAPI === 'cli') {
        foreach (['monitor_refresh_smtp_test' => [$db], 'monitor_refresh_sms_balance' => [$pdo, $db], 'monitor_refresh_disk' => [$db]] as $fn => $args) {
            try {
                $fn(...$args);
            } catch (Throwable $e) {
                @error_log('HODMON ' . $fn . ': ' . $e->getMessage());
            }
        }
        try {
            require_once __DIR__ . '/rum.php';
            monitor_rum_rollup($db, $now);
        } catch (Throwable $e) {
            @error_log('HODMON rum rollup: ' . $e->getMessage());
        }
    }

    $checks = [];
    $safe = function (string $key, string $grp, string $label, callable $fn) use (&$checks) {
        try {
            $r = $fn();
            foreach (isset($r['key']) ? [$r] : $r as $c) {
                $checks[] = $c;
            }
        } catch (Throwable $e) {
            $checks[] = monitor_check($key, $grp, $label, 'unknown', 'This check could not run: ' . mb_substr(monitor_redact($e->getMessage()), 0, 160));
        }
    };

    $beats = monitor_beats();
    $since = monitor_installed_at();
    foreach (monitor_cron_jobs() as $name => $job) {
        if ($name === 'monitor') {
            continue;   // it is running right now; the watchdog covers it stopping
        }
        $safe('cron_' . $name, 'Background jobs', $job['label'], fn () => monitor_check_cron($name, $job, $beats[$name] ?? null, $now, $since));
    }
    $safe('sms', 'Messages', 'SMS sending', fn () => monitor_check_sms(monitor_facts_sms($pdo, $db), $now));
    $safe('push', 'Messages', 'Push notifications', fn () => monitor_check_push(monitor_facts_push($pdo), $now));
    $safe('email', 'Messages', 'Email sending', fn () => monitor_check_email(monitor_facts_email($db), $now));
    $safe('db', 'Server', 'Database speed', fn () => monitor_check_db(monitor_facts_db($pdo, $db)));
    $safe('disk', 'Server', 'Disk space', fn () => monitor_check_disk(json_decode((string) monitor_state_get($db, 'disk'), true) ?: []));
    $safe('backups', 'Server', 'Backups', fn () => monitor_check_backups(monitor_facts_backups(), $now));
    $web = null;
    $dir = hod_monitor_data_dir();
    if ($dir !== null && is_file($dir . '/web.json')) {
        $web = json_decode((string) @file_get_contents($dir . '/web.json'), true) ?: null;
    }
    $safe('php', 'Server', 'PHP (web)', fn () => monitor_check_php($web, $now));
    $safe('speed', 'Server', 'Server speed settings', fn () => monitor_check_speed($web));
    $safe('logs', 'Server', 'Log files', fn () => monitor_check_logs(monitor_facts_logs()));
    $outside = null;
    $safe('probe', 'From outside', 'Outside probe', function () use ($db, $now, &$outside) {
        $outside = monitor_facts_outside($db);
        $every = (int) ($outside['meta']['every_min'] ?? 60);
        return monitor_check_outside($outside['latest'], $outside['uptime'], $now, max(5, $every), hod_monitor_env('EDGE_TOKEN') !== '');
    });
    $safe('deploy', 'Server', 'Last deploy', fn () => monitor_check_deploy(monitor_facts_deploy(), $outside['meta'] ?? null, $now));
    require_once __DIR__ . '/rum.php';
    $rum = [];
    $safe('rum_phone', 'Real users', 'Real-user speed: phones', function () use ($db, &$rum) {
        $rum = monitor_rum_summary($db, 7);
        return monitor_check_rum('phone', $rum['phone'] ?? []);
    });
    $safe('rum_desktop', 'Real users', 'Real-user speed: computers', fn () => monitor_check_rum('desktop', $rum['desktop'] ?? []));
    $safe('errors', 'Errors', 'Errors', fn () => monitor_check_errors(monitor_facts_errors($db)));

    monitor_checks_store($db, $checks, $now);

    return $checks;
}

/** Store the checks; a change of state goes to the history. */
function monitor_checks_store(PDO $db, array $checks, int $now): void
{
    $prev = [];
    foreach ($db->query('SELECT check_key, state, since FROM monitor_checks')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prev[$r['check_key']] = $r;
    }
    $at = monitor_fmt($now);
    foreach ($checks as $c) {
        $old = $prev[$c['key']] ?? null;
        $changed = $old === null || $old['state'] !== $c['state'];
        monitor_upsert($db, 'monitor_checks', [
            'check_key' => $c['key'], 'grp' => $c['grp'], 'label' => $c['label'], 'state' => $c['state'],
            'detail' => $c['detail'], 'fix' => $c['fix'], 'since' => $changed ? $at : $old['since'], 'checked_at' => $at,
        ], ['check_key'], [
            'grp' => '{grp}', 'label' => '{label}', 'state' => '{state}', 'detail' => '{detail}', 'fix' => '{fix}',
            'since' => '{since}', 'checked_at' => '{checked_at}',
        ]);
        if ($changed) {
            $db->prepare('INSERT INTO monitor_check_history (check_key, state, detail, at) VALUES (?, ?, ?, ?)')
               ->execute([$c['key'], $c['state'], mb_substr((string) $c['detail'], 0, 255), $at]);
        }
    }
}

/** What was stored last, in display order. */
function monitor_checks_current(PDO $db): array
{
    $order = ['Errors' => 0, 'From outside' => 1, 'Background jobs' => 2, 'Messages' => 3, 'Server' => 4, 'Real users' => 5];
    $rows = $db->query('SELECT check_key AS `key`, grp, label, state, detail, fix, since, checked_at, alerted_at, alerted_state FROM monitor_checks')->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, fn ($a, $b) => [($order[$a['grp']] ?? 9), $a['label']] <=> [($order[$b['grp']] ?? 9), $b['label']]);

    return $rows;
}
