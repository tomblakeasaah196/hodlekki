<?php
// /includes/monitor/brief.php
/**
 * "Fix with AI" (docs/monitor.md, "Fix with AI").
 *
 *   monitor_brief($db, $groupId)    one problem as a precise Markdown prompt to paste into Claude:
 *                                   what happened, where (page / API / cron / browser), when and
 *                                   how often, who was affected (roles and a count, never a
 *                                   person), the stack trace, the code around each frame, the
 *                                   deploys around it, the team's notes, and the task
 *   monitor_export_open($db)        every open problem in one .md, worst first
 *   monitor_explain($group)         a plain-English hint for common errors on this site
 *   monitor_group_stats($db, $id)   the numbers the brief, the alerts and the screens share
 *
 * Everything in a brief was redacted when it was stored, and the code excerpts
 * are redacted again (a key typed into a file stays out of the prompt).
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/checks.php';

function monitor_group(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM monitor_error_groups WHERE id = ?');
    $stmt->execute([$id]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);

    return $g ?: null;
}

/** Counts, people, roles, pages, the hourly shape and before/after a fix. */
function monitor_group_stats(PDO $db, int $id, ?int $now = null): array
{
    $now = $now ?? monitor_time();
    $count = function (int $fromTs, ?int $toTs = null) use ($db, $id): int {
        $sql = 'SELECT COALESCE(SUM(n), 0) FROM monitor_error_hourly WHERE group_id = ? AND hour >= ?';
        $args = [$id, monitor_fmt($fromTs, 'Y-m-d H:00:00')];
        if ($toTs !== null) {
            $sql .= ' AND hour < ?';
            $args[] = monitor_fmt($toTs, 'Y-m-d H:00:00');
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    };
    $people = function (int $fromTs) use ($db, $id): int {
        $stmt = $db->prepare('SELECT COUNT(DISTINCT user_hash) FROM monitor_error_people WHERE group_id = ? AND day >= ?');
        $stmt->execute([$id, monitor_fmt($fromTs, 'Y-m-d')]);
        return (int) $stmt->fetchColumn();
    };

    $s = [
        'last_24h'   => $count($now - 86400),
        'last_7d'    => $count($now - 7 * 86400),
        'people_24h' => $people($now - 86400),
        'people_7d'  => $people($now - 7 * 86400),
        'people_30d' => $people($now - 30 * 86400),
    ];

    $hourly = [];
    $stmt = $db->prepare('SELECT hour, n FROM monitor_error_hourly WHERE group_id = ? AND hour >= ?');
    $stmt->execute([$id, monitor_fmt($now - 23 * 3600, 'Y-m-d H:00:00')]);
    $byHour = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'hour');
    for ($i = 23; $i >= 0; $i--) {
        $hourly[] = (int) ($byHour[monitor_fmt($now - $i * 3600, 'Y-m-d H:00:00')] ?? 0);
    }
    $s['hourly_24h'] = $hourly;

    $daily = [];
    $stmt = $db->prepare('SELECT hour, n FROM monitor_error_hourly WHERE group_id = ? AND hour >= ?');
    $stmt->execute([$id, monitor_fmt($now - 13 * 86400, 'Y-m-d 00:00:00')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = substr((string) $r['hour'], 0, 10);
        $daily[$d] = ($daily[$d] ?? 0) + (int) $r['n'];
    }
    $s['daily_14d'] = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = monitor_fmt($now - $i * 86400, 'Y-m-d');
        $s['daily_14d'][$d] = $daily[$d] ?? 0;
    }

    $stmt = $db->prepare('SELECT role, COUNT(*) AS n FROM monitor_error_events WHERE group_id = ? AND role IS NOT NULL GROUP BY role ORDER BY n DESC');
    $stmt->execute([$id]);
    $s['roles'] = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'role'));

    $stmt = $db->prepare('SELECT browser, COUNT(*) AS n FROM monitor_error_events WHERE group_id = ? AND browser IS NOT NULL AND browser <> ? GROUP BY browser ORDER BY n DESC');
    $stmt->execute([$id, '']);
    $s['browsers'] = array_slice(array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'browser')), 0, 5, true);

    $stmt = $db->prepare('SELECT path, n, last_seen_at FROM monitor_error_paths WHERE group_id = ? ORDER BY n DESC LIMIT 8');
    $stmt->execute([$id]);
    $s['paths'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Before and after a fix: the same span either side of the moment it was marked fixed.
    $g = monitor_group($db, $id);
    if ($g && $g['fixed_at']) {
        $fixedTs = monitor_ts($g['fixed_at']);
        $span = max(3600, min(7 * 86400, $now - $fixedTs));
        $s['fix'] = ['span_hours' => (int) round($span / 3600), 'before' => $count($fixedTs - $span, $fixedTs), 'after' => $count($fixedTs)];
    }

    return $s;
}

/** A plain-English hint for errors this site meets, or null. */
function monitor_explain(array $g): ?string
{
    $text = ($g['exception_class'] ?? '') . ' ' . ($g['title'] ?? '') . ' ' . ($g['message'] ?? '');
    $kind = (string) ($g['kind'] ?? '');
    if ($kind === 'slow') {
        return 'The page answered, but slowly. Usual causes: a query per row in a loop, a query without an index, a large export, or a slow outside service (BulkSMS, Gemini, API.Bible) called while the visitor waits.';
    }
    if ($kind === 'csp') {
        return 'The Content Security Policy refused (or, in report-only mode, would refuse) something on this page: an inline script, an inline style or on*= handler, or a file from another site. Move it into a file under assets/ and wire it with data-action (docs/UI.md), or add the site to the page\'s CSP if it is truly needed.';
    }
    $hints = [
        '/Base table or view not found|SQLSTATE\[42S02\]|doesn\'t exist/i' => 'A database table is missing. Either the migration has not run (bin/deploy.sh runs db/migrate.php after copying the files), or this code runs before its migration and must degrade instead (ask *_table_exists() first and answer FEATURE_NOT_READY, as AGENTS.md requires).',
        '/Unknown column|SQLSTATE\[42S22\]/i'                => 'The code asked for a database column that does not exist: a missing or out-of-date migration, or a typo in the column name.',
        '/Duplicate entry|SQLSTATE\[23000\].*1062/i'         => 'The database refused a second copy of something that must be unique. The code should check first or treat this as "already done".',
        '/1364|doesn\'t have a default value|cannot be null/i' => 'A required column was left empty when saving (users.physical_address, for one, cannot be NULL). The form or the API must send it.',
        '/Call to undefined function (mime_content_type|finfo_)/i' => 'The web PHP on this host has no fileinfo extension (the command-line PHP has it). Use code that works without it: DEPLOY.md, "PHP extensions".',
        '/Call to undefined function/i'                      => 'A function is missing: a file that defines it is not required here, or was never committed (bin/deploy.sh copies only what is in git).',
        '/Class "[^"]+" not found|Class \'[^\']+\' not found/i' => 'A PHP class is missing: vendor/ not installed (composer runs in the deploy), a missing require, or a file not committed.',
        '/Undefined (array key|variable|index|offset)|Attempt to read property .* on (null|int|string|bool|false)|Trying to access array offset/i' => 'The code used a value that was not there (a missing form field, an empty query result). It needs a check (?? or isset) before using it.',
        '/Allowed memory size .* exhausted/i'                => 'PHP ran out of memory (128 MB on this host), usually from loading a whole table or a big spreadsheet at once. Page through the rows or stream the output.',
        '/Maximum execution time/i'                          => 'The request ran past PHP\'s time limit and was stopped. Move the slow part to a cron job or make it smaller.',
        '/Too many connections|max_user_connections|SQLSTATE\[HY000\] \[(1040|2002|2006|2013)\]|server has gone away/i' => 'The database refused or lost the connection: too many at once on the shared host, or it was restarted. If it repeats, look for a page or cron that opens many connections or holds them long.',
        '/\[mailer\]|SMTP|stream_socket_client|Could not authenticate/i' => 'An email could not be sent. Check MAIL_* in .env and the system@ mailbox in cPanel → Email Accounts; bin/mail_test.php sends a test.',
        '/BulkSMS|sms_send|SMS balance/i'                    => 'BulkSMS refused or did not answer. Check SMS Studio → Settings (the token) and the wallet balance.',
        '/\$ is not defined|jQuery is not defined/i'         => 'The page used jQuery before it loaded, or on a page that no longer loads it (strict pages of the new UI do not).',
        '/Failed to fetch|NetworkError|Load failed|network error/i' => 'The browser could not reach the server: usually a phone losing its connection, not a code error. Worth handling only if a screen breaks instead of retrying.',
        '/Unexpected token \'?<|is not valid JSON|JSON\.parse|JSON Parse error/i' => 'An API answered with HTML (a PHP error page or a sign-in redirect) where the page expected JSON. Look for a server error on the same endpoint at the same time.',
        '/Could not load /i'                                 => 'A file the page asked for did not arrive: removed or renamed in a deploy (the deploy never deletes, but a new name breaks old links), a typo, or an ad blocker on the visitor\'s phone.',
        '/CSRF|token mismatch|Invalid request token/i'       => 'A form was sent after its page had expired. Usually harmless: the person reloads.',
    ];
    foreach ($hints as $re => $hint) {
        if (preg_match($re, $text)) {
            return $hint;
        }
    }

    return null;
}

/** ±$around lines of a file in this site, numbered, the line itself marked. Only .php and .js files. */
function monitor_code_excerpt(?string $file, ?int $line, int $around = 6): ?string
{
    if ($file === null || $file === '' || !$line || !preg_match('/\.(php|js|mjs|phtml)$/i', $file)) {
        return null;
    }
    $root = realpath(monitor_root());
    $candidate = realpath(monitor_root() . '/' . ltrim(preg_replace('#:\d+(:\d+)?$#', '', $file) ?? $file, '/'));
    if ($root === false || $candidate === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || !is_file($candidate)
        || str_contains($candidate, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        return null;
    }
    $lines = @file($candidate, FILE_IGNORE_NEW_LINES);
    if (!$lines || $line > count($lines)) {
        return null;
    }
    $from = max(1, $line - $around);
    $to = min(count($lines), $line + $around);
    $out = [];
    for ($n = $from; $n <= $to; $n++) {
        $out[] = str_pad((string) $n, 5, ' ', STR_PAD_LEFT) . ($n === $line ? ' > ' : '   ') . mb_substr(monitor_redact($lines[$n - 1]), 0, 240);
    }

    return implode("\n", $out);
}

/** The site's own frames of a stored trace: [[file, line], …], top first, without repeats. */
function monitor_trace_frames(?string $trace, int $limit = 5): array
{
    $frames = [];
    foreach (preg_split("/\r?\n/", (string) $trace) ?: [] as $l) {
        if (preg_match('#^\#\d+ ([^\s:\[][^\s:]*\.(?:php|js|mjs|phtml)):(\d+)#', trim($l), $m)
            && !str_contains($m[1], 'vendor/') && !str_contains($m[1], 'includes/monitor/')) {
            $key = $m[1] . ':' . $m[2];
            if (!isset($frames[$key])) {
                $frames[$key] = [$m[1], (int) $m[2]];
            }
        }
        if (count($frames) >= $limit) {
            break;
        }
    }

    return array_values($frames);
}

/** How a problem's source reads to a person. */
function monitor_kind_label(array $g): string
{
    return match ($g['kind']) {
        'js'        => 'Browser (JavaScript)',
        'csp'       => 'Browser (Content Security Policy)',
        'slow'      => 'Slow ' . ($g['source'] === 'api' ? 'API call' : 'page'),
        'log'       => 'Server log',
        default     => match ($g['source']) {
            'api'     => 'Server: API endpoint',
            'cron'    => 'Server: cron job',
            'cli'     => 'Server: command-line script',
            'webhook' => 'Server: webhook',
            'log'     => 'Server log',
            default   => 'Server: page',
        },
    };
}

/** The deploys of the last few days from ~/deploy.log, newest first. */
function monitor_recent_deploys(int $limit = 5): array
{
    $runs = array_reverse(monitor_facts_deploy());

    return array_slice(array_values(array_filter($runs, fn ($r) => $r['started_at'] !== null)), 0, $limit);
}

/** One problem as a prompt for Claude. */
function monitor_brief(PDO $db, int $groupId, ?int $now = null): ?string
{
    $now = $now ?? monitor_time();
    $g = monitor_group($db, $groupId);
    if ($g === null) {
        return null;
    }
    $s = monitor_group_stats($db, $groupId, $now);
    $stmt = $db->prepare('SELECT * FROM monitor_error_events WHERE group_id = ? ORDER BY id DESC LIMIT 6');
    $stmt->execute([$groupId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latest = $events[0] ?? null;
    $t = fn (?string $at) => $at ? substr($at, 0, 16) : '?';

    $md = '# Error on ' . site_host() . ': ' . $g['title'] . "\n\n";
    $md .= '> Problem #' . $g['id'] . ($latest ? ' · latest reference ' . $latest['ref'] : '') . ' · status ' . ucfirst($g['status'])
        . ' · ' . monitor_kind_label($g) . ' · level ' . $g['level'] . "\n\n";
    $md .= "**System.** HOD Lekki church ERP (Household of David Lekki Centre, Lagos): plain PHP 8.3 + MariaDB/MySQL through PDO, "
        . "file-per-URL (no framework, no router), on cPanel shared hosting with LiteSpeed behind Cloudflare. Screens are "
        . "`modules/<name>/index.php`, JSON endpoints `api/<name>_api.php`, cron jobs `cron/*.php`, shared code in `includes/`. "
        . "Repository `tomblakeasaah196/hodlekki`: read `AGENTS.md` first, it holds the project's rules.\n\n";

    $md .= "## What happened\n\n";
    $message = trim((string) $g['message']);
    if ($g['exception_class'] && $g['kind'] !== 'csp') {
        $md .= '`' . $g['exception_class'] . "`, with the message:\n\n";
    }
    $md .= "```\n" . $message . "\n```\n\n";

    $md .= "## Where\n\n";
    $md .= '- Kind: ' . monitor_kind_label($g) . "\n";
    if ($latest && $latest['path']) {
        $md .= '- Request: `' . trim(($latest['method'] ?? '') . ' ' . $latest['path']) . '`' . ($latest['host'] ? ' on ' . $latest['host'] : '') . "\n";
    } elseif ($g['route']) {
        $md .= '- Route: `' . $g['route'] . "`\n";
    }
    if ($g['file']) {
        $md .= '- Code: `' . $g['file'] . ($g['line'] ? ':' . $g['line'] : '') . "`\n";
    }
    if (!empty($s['paths'])) {
        $md .= '- Pages: ' . implode(', ', array_map(fn ($p) => '`' . $p['path'] . '` ×' . (int) $p['n'], array_slice($s['paths'], 0, 5))) . "\n";
    }
    $md .= "\n## When and how often\n\n";
    $md .= '- First seen ' . $t($g['first_seen_at']) . ' (Lagos time)' . ($g['first_release'] ? ', release `' . $g['first_release'] . '`' : '') . "\n";
    $md .= '- Last seen ' . $t($g['last_seen_at']) . ($g['last_release'] ? ', release `' . $g['last_release'] . '`' : '') . "\n";
    $md .= '- ' . (int) $g['occurrences'] . ' time(s) in all (one per request); ' . $s['last_24h'] . ' in the last 24 hours, ' . $s['last_7d'] . " in the last 7 days\n";
    $md .= '- Hourly, last 24 hours (oldest first): `' . implode(' ', $s['hourly_24h']) . "`\n";
    $deploys = monitor_recent_deploys(6);
    $firstTs = monitor_ts($g['first_seen_at']);
    foreach ($deploys as $d) {
        $doneAt = $d['finished_at'] ?? $d['started_at'];
        if ($d['ok'] && $firstTs !== null && $doneAt !== null && $firstTs >= $doneAt && $firstTs - $doneAt <= 2 * 3600) {
            $md .= '- **Started ' . max(1, (int) round(($firstTs - $doneAt) / 60)) . ' minute(s) after the deploy of `' . ($d['commit'] ?? substr((string) $d['sha'], 0, 7)) . '` "' . ($d['subject'] ?? '') . "\"**: look at that change first\n";
            break;
        }
    }
    if ((int) $g['regressions'] > 0) {
        $md .= '- Came back ' . (int) $g['regressions'] . ' time(s) after being marked fixed (last ' . $t($g['regressed_at']) . "): the earlier fix did not hold\n";
    }
    if (!empty($s['fix'])) {
        $md .= '- Marked fixed ' . $t($g['fixed_at']) . ($g['fixed_release'] ? ' (release `' . $g['fixed_release'] . '`)' : '') . ': ' . $s['fix']['before'] . ' in the ' . $s['fix']['span_hours'] . ' h before, ' . $s['fix']['after'] . " since\n";
    }

    $md .= "\n## Who was affected\n\n";
    $md .= '- ' . $s['people_7d'] . ' different people in the last 7 days (' . $s['people_24h'] . " in the last 24 hours). No names or contact details are kept: people are counted by a keyed hash.\n";
    if ($s['roles']) {
        $md .= '- Roles: ' . implode(', ', array_map(fn ($r, $n) => $r . ' ×' . $n, array_keys($s['roles']), $s['roles'])) . "\n";
    }
    if ($s['browsers']) {
        $md .= '- Browsers: ' . implode(', ', array_map(fn ($b, $n) => $b . ' ×' . $n, array_keys($s['browsers']), $s['browsers'])) . "\n";
    }

    if ($hint = monitor_explain($g)) {
        $md .= "\n## Plain-English hint\n\n" . $hint . "\n";
    }

    if ($events) {
        $md .= "\n## Recent occurrences\n\n";
        foreach ($events as $e) {
            $md .= '- ' . $t($e['occurred_at']) . ' · `' . trim(($e['method'] ?? '') . ' ' . ($e['path'] ?? '')) . '`'
                . ($e['role'] ? ' · ' . $e['role'] : '') . ($e['device'] ? ' · ' . $e['device'] : '') . ($e['browser'] ? ' (' . $e['browser'] . ')' : '')
                . ($e['duration_ms'] ? ' · ' . number_format($e['duration_ms'] / 1000, 1) . ' s' : '')
                . ' · ref ' . $e['ref'] . ($e['context'] ? ' · `' . $e['context'] . '`' : '') . "\n";
        }
    }

    $trace = $g['trace'] ?: ($latest['trace'] ?? null);
    if ($trace) {
        $md .= "\n## Stack trace\n\n```\n" . implode("\n", array_slice(explode("\n", (string) $trace), 0, 30)) . "\n```\n";
    }
    $frames = monitor_trace_frames($trace);
    if ($g['file'] && $g['line'] && $g['kind'] !== 'js' && $g['kind'] !== 'csp') {
        array_unshift($frames, [$g['file'], (int) $g['line']]);
    }
    $shown = [];
    $code = '';
    foreach ($frames as [$file, $line]) {
        $key = $file . ':' . $line;
        if (isset($shown[$key]) || count($shown) >= 5) {
            continue;
        }
        $excerpt = monitor_code_excerpt($file, $line);
        if ($excerpt !== null) {
            $shown[$key] = true;
            $lang = preg_match('/\.m?js$/', $file) ? 'js' : 'php';
            $code .= "\n### `" . $key . "`\n\n```" . $lang . "\n" . $excerpt . "\n```\n";
        }
    }
    if ($code !== '') {
        $md .= "\n## Code around each frame (as deployed now)\n" . $code;
    }

    if ($deploys) {
        $md .= "\n## Recent deploys\n\n";
        foreach ($deploys as $d) {
            $md .= '- ' . monitor_fmt($d['finished_at'] ?? $d['started_at'], 'Y-m-d H:i') . ' `' . ($d['commit'] ?? substr((string) $d['sha'], 0, 7)) . '` '
                . ($d['subject'] ?? '') . ($d['ok'] ? '' : ' — **did not finish**') . "\n";
        }
    }

    $stmt = $db->prepare('SELECT user_id, body, created_at FROM monitor_error_notes WHERE group_id = ? ORDER BY id');
    $stmt->execute([$groupId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($notes) {
        $md .= "\n## Team notes\n\n";
        foreach ($notes as $n) {
            $md .= '- ' . $t($n['created_at']) . ($n['user_id'] ? '' : ' (monitor)') . ': ' . str_replace("\n", ' ', monitor_redact((string) $n['body'])) . "\n";
        }
    }

    $md .= "\n## Task\n\n"
        . "Find the root cause and propose the smallest safe fix, as a change to the files named above. Keep existing behaviour and the rules in "
        . "`AGENTS.md`: code must degrade safely before its migration runs (the files reach production before `db/migrate.php`), SQL uses "
        . "prepared statements, and nothing may print or log members' personal data. Say how to test it (a case in `tests/*_test.php`, "
        . "on SQLite where possible) and how to confirm on the live site that it has stopped (this problem's count in the monitor). "
        . "Flag anything that needs a database change (a new forward-only file in `db/migrations/`).\n";

    return $md;
}

/** Every open problem (new or investigating) in one Markdown file, worst first. */
function monitor_export_open(PDO $db, int $limit = 40, bool $withQuiet = false): string
{
    $sql = "SELECT id FROM monitor_error_groups WHERE status IN ('new', 'investigating')"
        . ($withQuiet ? '' : " AND level NOT IN ('notice', 'deprecated') AND kind <> 'csp'")
        . " ORDER BY CASE level WHEN 'fatal' THEN 0 WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END, last_seen_at DESC LIMIT " . max(1, $limit);
    $ids = array_map('intval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    $out = '# Open errors on ' . site_host() . ' — ' . monitor_fmt(null, 'Y-m-d H:i') . " (Lagos)\n\n";
    if (!$ids) {
        return $out . "No open errors.\n";
    }
    $out .= count($ids) . " open problem(s), most serious first. Each section is a complete brief; fix them one at a time, starting at the top.\n\n";
    foreach ($ids as $i => $id) {
        $g = monitor_group($db, $id);
        $out .= ($i + 1) . '. [' . $g['level'] . '] ' . str_replace(["\n", '|'], [' ', '/'], mb_substr((string) $g['title'], 0, 140)) . ' — ×' . (int) $g['occurrences'] . "\n";
    }
    foreach ($ids as $id) {
        $brief = monitor_brief($db, $id);
        if ($brief !== null) {
            $out .= "\n\n---\n\n" . preg_replace('/^# /', '## ', preg_replace('/^(#{2,5}) /m', '#$1 ', $brief) ?? $brief);
        }
    }

    return $out;
}
