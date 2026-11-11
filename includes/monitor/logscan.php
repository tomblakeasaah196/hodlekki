<?php
// /includes/monitor/logscan.php
/**
 * Reading the server's error logs into the monitor (docs/monitor.md, "Capture").
 *
 * It catches what the handlers in capture.php cannot: a parse error in a page
 * (PHP stops before any code runs), the public pages that never load db.php,
 * anything before the handlers are installed, and every error_log() line the
 * code writes when it caught something itself ("[mailer] send failed: …",
 * "SMS balance: BulkSMS answered without a readable balance …").
 *
 * cPanel writes an `error_log` file in the folder of the script that ran, so the
 * docroot and three levels below it are read (vendor/ and uploads/ are not),
 * plus PHP's own error_log setting and MONITOR_EXTRA_LOGS. Each file is read
 * from where the last run stopped (offset + inode in monitor_state), at most
 * 2 MB a run; a file seen for the first time is read from its last 256 KB, so
 * recent history arrives without months of old lines. Lines older than an hour,
 * and everything the first scan of a fresh install finds, are listed, never
 * announced; an old line never counts towards a spike either.
 *
 * Lines the handlers already recorded are skipped: their "HODMON [ref]" marker,
 * or — for the fatal errors PHP logs itself — an occurrence of the same problem
 * within 10 seconds.
 */

require_once __DIR__ . '/store.php';

const MONITOR_LOG_CHUNK      = 2 * 1048576;
const MONITOR_LOG_FIRST_READ = 256 * 1024;

/** Every log file worth reading that exists and can be read. */
function monitor_log_sources(?string $root = null): array
{
    $root = rtrim(str_replace('\\', '/', $root ?? monitor_root()), '/');
    $paths = [];
    foreach (['', '/*', '/*/*', '/*/*/*'] as $depth) {
        foreach (glob($root . $depth . '/error_log') ?: [] as $p) {
            $p = str_replace('\\', '/', $p);
            if (!preg_match('#/(vendor|uploads|node_modules|live|\.git)/#', substr($p, strlen($root)))) {
                $paths[] = $p;
            }
        }
    }
    $ini = (string) ini_get('error_log');
    if ($ini !== '' && $ini !== 'syslog' && (str_starts_with($ini, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $ini))) {
        $paths[] = $ini;
    }
    foreach (explode(',', hod_monitor_env('MONITOR_EXTRA_LOGS')) as $extra) {
        if (trim($extra) !== '') {
            $paths[] = trim($extra);
        }
    }
    $out = [];
    foreach ($paths as $p) {
        $real = @realpath($p) ?: $p;
        if (!isset($out[$real]) && is_file($real) && is_readable($real)) {
            $out[$real] = $real;
        }
    }

    return array_values($out);
}

/** Read every source. Returns counts for the cron's summary line. */
function monitor_log_scan_all(PDO $db, ?array $sources = null): array
{
    $done = ['files' => 0, 'entries' => 0, 'recorded' => 0, 'skipped' => 0];
    // The first scan of a fresh install reads the recent history of every log:
    // all of it is listed, none announced (the morning summary counts it).
    $first = (int) $db->query("SELECT COUNT(*) FROM monitor_state WHERE k LIKE 'log:%'")->fetchColumn() === 0;
    foreach ($sources ?? monitor_log_sources() as $path) {
        $r = monitor_log_scan_file($db, $path, MONITOR_LOG_CHUNK, $first);
        $done['files']++;
        foreach (['entries', 'recorded', 'skipped'] as $k) {
            $done[$k] += $r[$k];
        }
    }

    return $done;
}

/** Read what was added to one file since last time. */
function monitor_log_scan_file(PDO $db, string $path, int $max = MONITOR_LOG_CHUNK, bool $quiet = false): array
{
    $done = ['entries' => 0, 'recorded' => 0, 'skipped' => 0];
    $key = 'log:' . sha1($path);
    $state = json_decode((string) monitor_state_get($db, $key), true);
    clearstatcache(true, $path);
    $size = (int) @filesize($path);
    $inode = (int) @fileinode($path);

    if (!is_array($state)) {
        $offset = max(0, $size - MONITOR_LOG_FIRST_READ);
    } elseif ((int) ($state['inode'] ?? 0) !== $inode || $size < (int) ($state['offset'] ?? 0)) {
        $offset = 0;   // rotated, replaced or emptied
    } else {
        $offset = (int) $state['offset'];
    }
    if ($size <= $offset) {
        monitor_state_set($db, $key, (string) json_encode(['offset' => $size, 'inode' => $inode, 'path' => monitor_short_path($path)]));
        return $done;
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return $done;
    }
    fseek($fh, $offset);
    $blob = (string) fread($fh, $max);
    fclose($fh);

    // Whole lines only; the rest is read next time.
    $end = strrpos($blob, "\n");
    if ($end === false) {
        if (strlen($blob) < $max) {
            return $done;
        }
        $end = strlen($blob) - 1;
    }
    $blob = substr($blob, 0, $end + 1);
    $consumed = strlen($blob);
    if (!is_array($state) && $offset > 0 && ($nl = strpos($blob, "\n")) !== false) {
        $blob = substr($blob, $nl + 1);   // started in the middle of a line
    }

    $where = monitor_short_path($path) ?? basename($path);
    $now = monitor_time();
    foreach (monitor_log_parse($blob) as $entry) {
        $done['entries']++;
        $raw = monitor_log_entry_event($entry, $where);
        if ($raw === null) {
            $done['skipped']++;
            continue;
        }
        $ev = monitor_prepare_event($raw);
        if (monitor_log_already_recorded($db, $ev['fingerprint'], $ev['at'], $ev['kind'] === 'exception' ? $ev['exception_class'] : null)) {
            $done['skipped']++;
            continue;
        }
        $res = monitor_record($db, $ev);
        // An old line found in the log is listed, not announced; so is all of the first scan.
        if ($res['is_new'] && ($quiet || $ev['at'] < $now - 3600)) {
            $db->prepare('UPDATE monitor_error_groups SET alerted_at = ? WHERE id = ?')->execute([monitor_fmt($now), $res['group_id']]);
        }
        $done['recorded']++;
    }
    monitor_state_set($db, $key, (string) json_encode(['offset' => $offset + $consumed, 'inode' => $inode, 'path' => $where]));

    return $done;
}

/**
 * Split log text into entries: "[timestamp] message" lines, with the lines that
 * follow (a stack trace) belonging to the one before.
 *
 * @return list<array{ts:string,text:string}>
 */
function monitor_log_parse(string $blob): array
{
    $entries = [];
    $current = null;
    foreach (preg_split("/\r?\n/", $blob) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z0-9_\/+\-]+)?|\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]{0,12})\]\s?(.*)$/s', $line, $m)) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = ['ts' => $m[1], 'text' => $m[2]];
        } elseif ($current !== null) {
            $current['text'] .= "\n" . $line;
        } else {
            $entries[] = ['ts' => '', 'text' => $line];
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }

    return $entries;
}

/** One log entry as a raw event for monitor_prepare_event(), or null when it is not a problem. */
function monitor_log_entry_event(array $entry, string $where): ?array
{
    $text = trim((string) $entry['text']);
    if ($text === '' || str_starts_with($text, 'HODMON ')) {
        return null;   // empty, or written by the monitor (recorded when it happened)
    }
    $at = monitor_log_time((string) $entry['ts']);
    $request = ['source' => 'log', 'method' => null, 'uri' => '', 'role' => null];
    $context = ['log' => $where];

    if (preg_match('/^PHP\s+(Fatal error|Parse error|Recoverable fatal error|Catchable fatal error|Compile error|Core error|Warning|Notice|Deprecated|Strict Standards|User (?:warning|notice|error|deprecated))\s*:\s*(.*)$/is', $text, $m)) {
        $name = strtolower($m[1]);
        $body = $m[2];
        $level = (str_contains($name, 'fatal') || str_contains($name, 'parse') || str_contains($name, 'compile')
                  || str_contains($name, 'core') || $name === 'user error') ? 'fatal'
               : (str_contains($name, 'deprecated') ? 'deprecated'
               : (str_contains($name, 'notice') || str_contains($name, 'strict') ? 'notice' : 'warning'));

        // An exception nothing caught, on a page without the monitor's handler:
        // "Uncaught RuntimeException: message in /path/file.php:12\nStack trace:\n#0 …"
        if (preg_match('/^Uncaught\s+([A-Za-z0-9_\\\\]+)(?::\s+(.*?))?\s+in\s+(\S+?):(\d+)\s*(?:\n|$)/s', $body, $x)) {
            $trace = null;
            if (($pos = strpos($body, "Stack trace:")) !== false) {
                $trace = monitor_log_php_trace(substr($body, $pos + 12));
            }
            return [
                'kind' => 'exception', 'level' => 'fatal', 'exception_class' => $x[1], 'message' => (string) ($x[2] ?? ''),
                'file' => $x[3], 'line' => (int) $x[4], 'trace_text' => $trace, 'at' => $at, 'request' => $request,
                'context' => $context, 'bulk' => true,
            ];
        }

        $first = strtok($body, "\n") ?: '';
        $file = null;
        $line = null;
        if (preg_match_all('/\bin\s+(\S+?\.(?:php|phtml|inc))(?:\s+on\s+line\s+|:)(\d+)/i', $first, $mm, PREG_SET_ORDER)) {
            $last = end($mm);
            $file = $last[1];
            $line = (int) $last[2];
            $first = trim(preg_replace('/\s+in\s+\S+?\.(?:php|phtml|inc)(?:\s+on\s+line\s+|:)\d+\s*$/i', '', $first) ?? $first);
        }
        $rest = strpos($body, "\n") !== false ? trim(substr($body, strpos($body, "\n") + 1)) : '';

        return [
            'kind' => 'php', 'level' => $level, 'message' => $first, 'file' => $file, 'line' => $line,
            'trace_text' => $rest !== '' ? monitor_log_php_trace($rest) : null,
            'at' => $at, 'request' => $request, 'context' => $context, 'bulk' => true,
        ];
    }

    // A line the code wrote itself. Only problems are worth a record; the rest
    // of what scripts log (progress, counts) is skipped.
    $first = strtok($text, "\n") ?: $text;

    // The UI foundation's CSRF observer (includes/csrf.php, csrf_observe() from
    // db.php) notes, once per endpoint per session, a write that arrived without
    // the page's token: "CSRF report-only: POST /api/x_api.php (no token)". A
    // report for the move to enforced CSRF, not a server error: kept quietly
    // (never announced, not in the morning summary), one problem per endpoint and reason.
    if (preg_match('/^CSRF report-only:\s*([A-Z]{3,7})\s+(\S+)\s*\(([^)]{1,40})\)/', $first, $c)) {
        return [
            'kind' => 'log', 'level' => 'notice', 'exception_class' => 'CSRF report-only',
            'message' => 'CSRF report-only: ' . $c[1] . ' ' . monitor_clean_url($c[2])[1] . ' (' . $c[3] . ')',
            'file' => null, 'line' => null, 'at' => $at, 'context' => $context, 'bulk' => true,
            'request' => ['source' => 'log', 'method' => $c[1], 'uri' => $c[2], 'role' => null],
        ];
    }
    if (!preg_match('/error|fail|exception|could ?n[o\']t|unable|denied|refus|timed? ?out|invalid|missing|not found|gave up|corrupt|mismatch/i', $first)) {
        return null;
    }
    $level = preg_match('/fatal|exception|error|failed|could ?n[o\']t/i', $first) ? 'error' : 'warning';

    return [
        'kind' => 'log', 'level' => $level, 'message' => $text, 'file' => null, 'line' => null,
        'at' => $at, 'request' => $request, 'context' => $context, 'bulk' => true,
    ];
}

/** PHP's own stack trace text, without the arguments it may carry: "#0 includes/x.php:12 fn()". */
function monitor_log_php_trace(string $text): string
{
    $out = [];
    foreach (preg_split("/\r?\n/", trim($text)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'thrown in ')) {
            continue;
        }
        if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\):\s+([^\(]*)\(/', $line, $m)) {
            $out[] = '#' . $m[1] . ' ' . monitor_short_path($m[2]) . ':' . $m[3] . ' ' . trim($m[4]) . '()';
        } elseif (preg_match('/^#(\d+)\s+\[internal function\]:\s+([^\(]*)\(/', $line, $m)) {
            $out[] = '#' . $m[1] . ' [internal] ' . trim($m[2]) . '()';
        } elseif (preg_match('/^#\d+\s+\{main\}$/', $line)) {
            $out[] = $line;
        } elseif (count($out) < 40 && !str_starts_with($line, '#')) {
            $out[] = mb_substr($line, 0, 300);
        }
        if (count($out) >= 40) {
            break;
        }
    }

    return implode("\n", $out);
}

/** A log line's time ("11-Nov-2026 09:00:00 UTC", "… Africa/Douala", "2026-11-11 10:00:00") as a Unix time. */
function monitor_log_time(string $ts): int
{
    $ts = trim($ts);
    if ($ts === '') {
        return monitor_time();
    }
    foreach (['d-M-Y H:i:s e', 'd-M-Y H:i:s T', 'd-M-Y H:i:s', 'Y-m-d H:i:s e', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
        $zone = str_ends_with($format, 'e') || str_ends_with($format, 'T') || str_ends_with($format, 'P') ? new DateTimeZone('UTC') : new DateTimeZone('Africa/Lagos');
        $d = DateTimeImmutable::createFromFormat($format, $ts, $zone);
        if ($d !== false) {
            return $d->getTimestamp();
        }
    }

    return monitor_time();
}

/**
 * Did the handlers already record this problem at about this time? For an
 * uncaught exception the class is enough: the handler groups it by the site's
 * own frame, PHP's line names where it was thrown (inside vendor/, sometimes).
 */
function monitor_log_already_recorded(PDO $db, string $fingerprint, int $at, ?string $exceptionClass = null): bool
{
    $window = [monitor_fmt($at - 10), monitor_fmt($at + 10)];
    $stmt = $db->prepare(
        'SELECT 1 FROM monitor_error_events e JOIN monitor_error_groups g ON g.id = e.group_id
          WHERE g.fingerprint = ? AND e.occurred_at BETWEEN ? AND ? LIMIT 1'
    );
    $stmt->execute([$fingerprint, ...$window]);
    if ($stmt->fetchColumn()) {
        return true;
    }
    if ($exceptionClass === null || $exceptionClass === '') {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM monitor_error_events e JOIN monitor_error_groups g ON g.id = e.group_id
          WHERE g.kind = 'exception' AND g.level = 'fatal' AND g.exception_class = ? AND e.occurred_at BETWEEN ? AND ? LIMIT 1"
    );
    $stmt->execute([$exceptionClass, ...$window]);

    return (bool) $stmt->fetchColumn();
}
