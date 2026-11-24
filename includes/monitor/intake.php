<?php
// /includes/monitor/intake.php
/**
 * What browsers send (docs/monitor.md, "Browser and CSP intake"), checked
 * before anything is kept. Used by api/monitor_intake.php.
 *
 *   monitor_intake_beacon($body, $server, $session)   assets/js/monitor.js: JS errors, failed
 *                                                      loads, and one speed report per page view
 *   monitor_intake_csp_events($body, $contentType, $server, $session)
 *                                                      Content-Security-Policy violation reports
 *                                                      (report-uri and Reporting API formats)
 *   monitor_intake_csp($body, $contentType)            the same, recorded at once: for another
 *                                                      endpoint that receives CSP reports itself
 *   monitor_intake_store_events($events)               the database, or the spool if it is down
 *   monitor_intake_rate_ok($ip)                        at most 60 reports a minute per address (before
 *                                                      anything is read)
 *   monitor_intake_global_ok()                         3000 a minute in all, counting only reports
 *                                                      that carried a valid token
 *
 * Signed: a beacon carries the day's intake token (keys.php) the page was
 * served with, and a CSP report carries it in the report-uri. Nothing that did
 * not come from a page this site served is accepted.
 *
 * Kept small and private: at most 10 errors per beacon, 500 characters a
 * message, 20 stack lines; addresses reduced to their path; browser-extension
 * noise and "Script error." without a source dropped; the request's role is
 * read from the session, the person only ever hashed.
 */

require_once dirname(__DIR__) . '/site_url.php';
require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/redact.php';

const MONITOR_INTAKE_MAX_BYTES  = 65536;
const MONITOR_INTAKE_MAX_ERRORS = 10;

/** The request facts every event from the browser carries (raw: store.php cleans them). */
function monitor_intake_request(string $page, array $server, ?array $session, string $device = ''): array
{
    $userId = isset($session['user_id']) ? (int) $session['user_id'] : null;

    return [
        'source'  => 'browser',
        'method'  => 'GET',
        'uri'     => $page !== '' ? $page : '/',
        'host'    => strtolower(preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($server['HTTP_HOST'] ?? '')) ?? ''),
        'role'    => isset($session['active_role']) ? (string) $session['active_role'] : ($userId ? 'member' : 'guest'),
        'user_id' => $userId,
        'ua'      => (string) ($server['HTTP_USER_AGENT'] ?? ''),
        'ip'      => (string) ($server['HTTP_CF_CONNECTING_IP'] ?? ($server['REMOTE_ADDR'] ?? '')),
        'device'  => in_array($device, ['phone', 'tablet', 'desktop'], true) ? $device : '',
    ];
}

/**
 * A beacon from monitor.js. Returns ['ok' => bool, 'status' => int, 'events' => raw events, 'rum' => ?row-input].
 */
function monitor_intake_beacon(string $body, array $server, ?array $session, ?int $now = null): array
{
    $now = $now ?? (int) ($GLOBALS['monitor_test_now'] ?? time());
    if (strlen($body) > MONITOR_INTAKE_MAX_BYTES) {
        return ['ok' => false, 'status' => 413, 'events' => [], 'rum' => null];
    }
    $p = json_decode($body, true);
    if (!is_array($p) || !monitor_intake_token_valid((string) ($p['t'] ?? ''), $now)) {
        return ['ok' => false, 'status' => 403, 'events' => [], 'rum' => null];
    }
    $page = monitor_intake_page((string) ($p['page'] ?? '/'));
    $device = (string) ($p['dev'] ?? '');
    $req = monitor_intake_request($page, $server, $session, $device);

    $events = [];
    foreach (array_slice((array) ($p['errors'] ?? []), 0, MONITOR_INTAKE_MAX_ERRORS) as $err) {
        if (is_array($err) && ($ev = monitor_intake_js_event($err, $req, $now)) !== null) {
            $events[] = $ev;
        }
    }
    $rum = null;
    if (isset($p['rum']) && is_array($p['rum'])) {
        $rum = ['rum' => $p['rum'], 'page' => $page, 'device' => $req['device'] ?: monitor_device_class($req['ua']), 'at' => $now];
    }

    return ['ok' => true, 'status' => 204, 'events' => $events, 'rum' => $rum];
}

/** A line, column or time a browser sent, if it is a sane whole number (it fits an INT column). */
function monitor_intake_int($v): ?int
{
    if (!is_int($v) && !(is_string($v) && ctype_digit($v)) && !(is_float($v) && floor($v) === $v)) {
        return null;
    }

    return $v >= 0 && $v <= 10000000 ? (int) $v : null;
}

/** The page a beacon is about: a same-site path, nothing else. */
function monitor_intake_page(string $page): string
{
    $path = (string) (parse_url($page, PHP_URL_PATH) ?? '/');

    return $path !== '' && $path[0] === '/' ? mb_substr($path, 0, 255) : '/';
}

/** One browser error as a raw event, or null for noise we cannot act on. */
function monitor_intake_js_event(array $err, array $req, int $now): ?array
{
    $type = in_array($err['type'] ?? '', ['error', 'rejection', 'resource'], true) ? (string) $err['type'] : 'error';
    // Only text counts: an object sent as a field ({} from an SVG href) used to
    // be cast to the word "Array" and filed as a file called /Array.
    $str = fn ($v) => is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
    $msg = trim(mb_substr($str($err['msg'] ?? ''), 0, 500));
    $src = $str($err['src'] ?? '');
    $stack = mb_substr($str($err['stack'] ?? ''), 0, 6000);

    // Noise: browser extensions, cross-origin "Script error." with nothing to go on,
    // the ResizeObserver notice browsers raise for harmless layout loops, and a
    // page-to-page view transition the browser skipped or aborted ("Transition was
    // skipped", "…Page already revealed", "…ViewTransition opt-in disabled" when
    // one side does not opt in): the browser raises those itself, on pages with or
    // without includes/pwa_head.php's guard (/connect has none). Also Cloudflare's
    // own analytics beacon failing to load (problem #117): Cloudflare's edge adds
    // that script to the HTML, no page of ours asks for it, the strict CSP
    // (script-src 'self') refuses it by design and blockers stop it elsewhere.
    $all = $msg . ' ' . $src . ' ' . $stack;
    if ($msg === '' || preg_match('#(chrome|moz|safari(-web)?|ms-browser)-extension://|webkit-masked-url://#i', $all)
        || ($type === 'resource' && preg_match('#^(https?:)?//static\.cloudflareinsights\.com/#i', $src))
        || (preg_match('/^Script error\.?$/i', $msg) && $src === '')
        || preg_match('/^ResizeObserver loop (limit exceeded|completed with undelivered notifications)/i', $msg)
        || ($type === 'rejection' && preg_match('/^(InvalidStateError|AbortError): Transition was (aborted|skipped)\b/', $msg))) {
        return null;
    }

    $srcPath = monitor_intake_src($src);
    $lines = [];
    foreach (array_slice(preg_split("/\r?\n/", $stack) ?: [], 0, 20) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = mb_substr(preg_replace_callback('#https?://[^\s)]+#', fn ($m) => monitor_intake_src($m[0]), $line) ?? $line, 0, 300);
        }
    }

    if ($type === 'resource') {
        // One of this site's files that IS on the server, failing in one browser, is that browser's
        // connection (a phone switching apps or losing signal mid-load; 18 Nov 2026: three scripts of an
        // event page in the same second): a notice in its own group, kept but not counted as a problem.
        // A file that is not on the server (a deploy that lost it, a wrong path) stays a warning.
        $onServer = monitor_intake_file_on_server($srcPath);
        $title = 'Could not load ' . ($srcPath !== '' ? $srcPath : 'a file') . ($onServer ? " (visitor's connection)" : '');
        return [
            'kind' => 'js', 'level' => $onServer ? 'notice' : 'warning', 'exception_class' => $onServer ? 'ConnectionDropped' : 'ResourceError',
            'message' => $title, 'title' => $title, 'file' => $srcPath, 'line' => null, 'trace_text' => null,
            'request' => $req, 'at' => $now, 'context' => ['tag' => mb_substr($str($err['tag'] ?? ''), 0, 12)],
        ];
    }

    return [
        'kind'            => 'js',
        'level'           => 'error',
        'exception_class' => $type === 'rejection' ? 'UnhandledRejection' : (preg_match('/^(\w*Error):/', $msg, $m) ? $m[1] : 'Error'),
        'message'         => $msg,
        'file'            => $srcPath !== '' ? $srcPath : null,
        'line'            => monitor_intake_int($err['line'] ?? null),
        'trace_text'      => $lines ? implode("\n", $lines) : null,
        'request'         => $req,
        'at'              => $now,
        'context'         => array_filter([
            'col'        => monitor_intake_int($err['col'] ?? null),
            'after_ms'   => monitor_intake_int($err['at'] ?? null),
        ], fn ($v) => $v !== null),
    ];
}

/** Whether a path from monitor_intake_src() is one of this site's files, present and not empty on the server. */
function monitor_intake_file_on_server(string $path): bool
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
        return false; // nothing, another site's file (host/path), or a path that climbs
    }
    $root = realpath(dirname(__DIR__, 2));
    $file = $root !== false ? realpath($root . strtok($path, '#?')) : false;

    return $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) && filesize($file) > 0;
}

/**
 * A script or file address as something safe to keep and group by: this site's
 * files as their path (no ?v=…), other sites as their host and path, "inline".
 */
function monitor_intake_src(string $src): string
{
    $src = trim($src);
    if ($src === '' || $src === 'inline' || $src === 'eval') {
        return $src;
    }
    if (preg_match('/^(data|blob):/i', $src, $m)) {
        return strtolower($m[1]) . ':';
    }
    // A stack frame's "…/app.js?v=12:10:5": keep the line and column, lose the query.
    $position = '';
    if (preg_match('#^(https?://[^/]+/.*?)(:\d+(?::\d+)?)$#', $src, $m)) {
        [$src, $position] = [$m[1], $m[2]];
    }
    $parts = parse_url($src);
    if ($parts === false) {
        return '';
    }
    $path = (string) ($parts['path'] ?? '');
    $host = strtolower((string) ($parts['host'] ?? ''));
    $own = $host === '' || $host === site_host() || str_ends_with($host, '.' . site_host()) || $host === 'localhost';
    $clean = monitor_clean_url($path !== '' ? $path : '/')[0];

    return mb_substr(($own ? $clean : $host . $clean) . $position, 0, 255);
}

/**
 * For another endpoint that receives CSP reports itself (the UI foundation's
 * api/csp_report.php): parse the raw body and record the violations. Returns
 * how many were kept. Never throws.
 */
function monitor_intake_csp(string $body, string $contentType = 'application/csp-report'): int
{
    try {
        $session = session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) ? $_SESSION : null;

        return monitor_intake_store_events(monitor_intake_csp_events($body, $contentType, $_SERVER, $session));
    } catch (Throwable $e) {
        return 0;
    }
}

/** Record raw events from the browser: the database when it answers, else the spool. */
function monitor_intake_store_events(array $events): int
{
    if (!$events) {
        return 0;
    }
    require_once __DIR__ . '/store.php';
    $prepared = array_map('monitor_prepare_event', $events);
    if (monitor_circuit_open()) {
        return monitor_spool_write('events', $prepared) ? count($prepared) : 0;   // the database was unreachable a moment ago
    }
    $handled = 0;
    try {
        $db = monitor_db();
        if (!monitor_ready($db)) {
            return monitor_spool_write('events', $prepared) ? count($prepared) : 0;   // before the migration
        }
        $n = monitor_store_all($db, $prepared, $handled);
    } catch (Throwable $e) {
        monitor_circuit_trip();
        $rest = array_slice($prepared, (int) $handled);
        return monitor_spool_write('events', $rest) ? count($rest) : 0;
    }
    try {
        require_once __DIR__ . '/wall.php';
        monitor_wall_publish($db, false);
    } catch (Throwable $e) {
        // the cron publishes within the minute
    }

    return $n;
}

/**
 * Content-Security-Policy reports: application/csp-report ({"csp-report": {...}})
 * or application/reports+json ([{"type": "csp-violation", "body": {...}}]).
 * Returns raw events.
 */
function monitor_intake_csp_events(string $body, string $contentType, array $server, ?array $session, ?int $now = null): array
{
    $now = $now ?? (int) ($GLOBALS['monitor_test_now'] ?? time());
    if (strlen($body) > MONITOR_INTAKE_MAX_BYTES) {
        return [];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [];
    }
    $reports = [];
    if (isset($data['csp-report']) && is_array($data['csp-report'])) {
        $r = $data['csp-report'];
        $reports[] = [
            'document'    => (string) ($r['document-uri'] ?? ''),
            'directive'   => (string) ($r['effective-directive'] ?? ($r['violated-directive'] ?? '')),
            'blocked'     => (string) ($r['blocked-uri'] ?? ''),
            'disposition' => (string) ($r['disposition'] ?? 'enforce'),
            'source'      => (string) ($r['source-file'] ?? ''),
            'line'        => $r['line-number'] ?? null,
        ];
    } elseif (array_is_list($data)) {
        foreach (array_slice($data, 0, 20) as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'csp-violation' || !is_array($item['body'] ?? null)) {
                continue;
            }
            $b = $item['body'];
            $reports[] = [
                'document'    => (string) ($b['documentURL'] ?? ($item['url'] ?? '')),
                'directive'   => (string) ($b['effectiveDirective'] ?? ''),
                'blocked'     => (string) ($b['blockedURL'] ?? ''),
                'disposition' => (string) ($b['disposition'] ?? 'enforce'),
                'source'      => (string) ($b['sourceFile'] ?? ''),
                'line'        => $b['lineNumber'] ?? null,
            ];
        }
    }

    $events = [];
    foreach ($reports as $r) {
        $directive = preg_replace('/[^a-z\-]/', '', strtolower(strtok($r['directive'], ' ') ?: '')) ?: 'unknown';
        $blocked = monitor_intake_src($r['blocked']) ?: 'unknown';
        if (preg_match('#(chrome|moz|safari(-web)?)-extension#i', $r['blocked'] . $r['source'])) {
            continue;   // a browser extension, not this site
        }
        $page = monitor_intake_page($r['document']);
        $report = $r['disposition'] === 'report';
        $title = 'CSP ' . ($report ? 'would block' : 'blocked') . ' ' . $blocked . ' (' . $directive . ')';
        $events[] = [
            'kind'            => 'csp',
            'level'           => $report ? 'notice' : 'warning',
            'exception_class' => $directive,
            'title'           => $title,
            'message'         => $title . ($report ? '. Report-only: nothing was blocked yet.' : '. The browser refused it.'),
            'file'            => $r['source'] !== '' ? monitor_intake_src($r['source']) : null,
            'line'            => monitor_intake_int($r['line']),
            'request'         => monitor_intake_request($page, $server, $session),
            'at'              => $now,
            'context'         => ['disposition' => $report ? 'report-only' : 'enforced'],
        ];
    }

    return $events;
}

/**
 * At most $perIp reports a minute from one address, checked before anything
 * is read. Counted in small files under the monitor's folder (no database per
 * report); the address is only ever a hash salted with the minute.
 */
function monitor_intake_rate_ok(string $ip, int $perIp = 60): bool
{
    return monitor_intake_count(gmdate('YmdHi') . '-' . substr(hash_hmac('sha256', $ip . '|' . gmdate('YmdHi'), monitor_secret()), 0, 20), $perIp);
}

/**
 * At most $global reports a minute from everyone, counted only once a report
 * has shown a valid token: a flood of junk from one place cannot use up the
 * allowance real browsers need.
 */
function monitor_intake_global_ok(int $global = 3000): bool
{
    return monitor_intake_count('g-' . gmdate('YmdHi'), $global);
}

function monitor_intake_count(string $name, int $limit): bool
{
    $dir = hod_monitor_data_dir();
    if ($dir === null) {
        return true;
    }
    $rl = $dir . '/rl';
    if (!is_dir($rl)) {
        @mkdir($rl, 0700, true);
    }
    $fh = @fopen($rl . '/' . $name, 'c+');
    if (!$fh) {
        return true;
    }
    flock($fh, LOCK_EX);
    $n = (int) stream_get_contents($fh) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) $n);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $n <= $limit;
}

/** Old rate-limit counters out (the cron every run, and the intake now and then in case the cron is not set up). */
function monitor_intake_rate_cleanup(): void
{
    $dir = hod_monitor_data_dir();
    foreach ($dir !== null ? (glob($dir . '/rl/*') ?: []) : [] as $f) {
        if ((int) @filemtime($f) < time() - 180) {
            @unlink($f);
        }
    }
}
