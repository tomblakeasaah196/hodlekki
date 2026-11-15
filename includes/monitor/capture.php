<?php
// /includes/monitor/capture.php
/**
 * The error monitor's catcher (docs/monitor.md, "Capture").
 *
 * Installed on line 2 of includes/db.php, so every API endpoint, every ERP page
 * (header.php requires db.php first) and every cron job has it; optionally also
 * by includes/monitor/prepend.php through cPanel's auto_prepend_file, for the
 * few public pages that never load db.php. Installing twice is a no-op.
 *
 *   hod_monitor_install()               set the handlers (db.php does this)
 *   hod_monitor_report($e, $context)    a caught exception worth knowing about
 *   hod_monitor_note($level, $message)  any other problem (a failed send, a refused call)
 *   hod_monitor_context([...])          more about this request (e.g. ['not_slow' => true])
 *
 * What it records: PHP warnings, notices and deprecations (those error_reporting
 * reports), uncaught exceptions, fatal errors (memory, time limit, compile),
 * pages slower than MONITOR_SLOW_MS (3 s), and for cron/*.php a heartbeat (start,
 * finish, ok) so Health can tell whether each job ran on time.
 *
 * Rules this file keeps, because it runs on every request the site serves:
 *   - It never prints and never changes what the visitor gets. An uncaught
 *     exception is recorded and handed back to PHP, so it ends exactly as it
 *     always did; a warning the monitor records is logged as ONE short line
 *     marked "HODMON [ref]" instead of PHP's own (the log reader skips the marker).
 *   - Nothing is written during the request. Problems are kept in memory and
 *     written after the response has been sent (fastcgi/litespeed_finish_request),
 *     on the monitor's own database connection (includes/monitor/store.php), so
 *     a page's rolled-back transaction cannot take the record with it.
 *   - At most 25 problems a request; the same one twice in a request counts once.
 *   - It keeps no function arguments, request bodies, cookies or headers.
 *     Everything kept is redacted again before it is stored (redact.php).
 *
 * MONITOR_ENABLED=0 in .env switches recording off without a deploy.
 */

if (!defined('HOD_MONITOR_MAX_PER_REQUEST')) {
    define('HOD_MONITOR_MAX_PER_REQUEST', 25);
    define('HOD_MONITOR_REF_CHARS', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');   // no 0/O or 1/I
}

if (!function_exists('hod_monitor_install')) {
    function hod_monitor_install(): void
    {
        if (!empty($GLOBALS['hod_monitor']['installed'])) {
            return;
        }
        $cli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
        $GLOBALS['hod_monitor'] = [
            'installed' => true,
            'cli'       => $cli,
            'started'   => (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'buffer'    => [],
            'seen'      => [],
            'count'     => 0,
            'busy'      => false,
            'uncaught'  => false,
            'fatal'     => false,
            'flushed'   => false,
            'cron'      => null,
            'bg_due'    => false,
            'context'   => [],
        ];

        if ($cli) {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '')));
            $args = array_slice((array) ($_SERVER['argv'] ?? []), 1);
            $inspecting = (bool) preg_grep('/^--(status|help|dry|check)/', array_map('strval', $args));
            if (preg_match('#/cron/([A-Za-z0-9_]+)\.php$#', $script, $m) && !$inspecting) {
                $GLOBALS['hod_monitor']['cron'] = $m[1];
                hod_monitor_beat($m[1], 'start');
            }
        }

        set_error_handler('hod_monitor_on_error');
        set_exception_handler('hod_monitor_on_exception');
        register_shutdown_function('hod_monitor_on_shutdown');
    }
}

if (!function_exists('hod_monitor_enabled')) {
    /** False when .env says MONITOR_ENABLED=0. Assumed on until .env is loaded. */
    function hod_monitor_enabled(): bool
    {
        $v = $_ENV['MONITOR_ENABLED'] ?? getenv('MONITOR_ENABLED');
        return !($v !== false && $v !== null && trim((string) $v) === '0');
    }
}

if (!function_exists('hod_monitor_context')) {
    /** More about this request: 'not_slow' => true for a deliberately long one (a download, an export). */
    function hod_monitor_context(array $context): void
    {
        if (isset($GLOBALS['hod_monitor'])) {
            $GLOBALS['hod_monitor']['context'] = array_merge($GLOBALS['hod_monitor']['context'], $context);
        }
    }
}

if (!function_exists('hod_monitor_on_error')) {
    /** PHP warnings, notices and deprecations. Fatal ones are left to PHP (the shutdown records them). */
    function hod_monitor_on_error(int $no, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $no) || !hod_monitor_enabled()) {
            return false;   // silenced with @, or below error_reporting: PHP's business, as before
        }
        $fatal = $no === E_USER_ERROR || $no === E_RECOVERABLE_ERROR;
        $level = match (true) {
            $fatal                                        => 'fatal',
            $no === E_NOTICE || $no === E_USER_NOTICE     => 'notice',
            $no === E_DEPRECATED || $no === E_USER_DEPRECATED => 'deprecated',
            default                                       => 'warning',
        };
        $ref = hod_monitor_capture([
            'kind'    => 'php',
            'level'   => $level,
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
            'frames'  => hod_monitor_frames(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 31), 1)),
        ]);
        // Not recorded, fatal (PHP must still stop the script), or a developer
        // is watching errors on screen: PHP handles it exactly as before.
        if ($ref === null || $fatal || hod_monitor_displaying()) {
            return false;
        }
        hod_monitor_log_line($ref, $level, $message, $file, $line);

        return true;
    }
}

if (!function_exists('hod_monitor_on_exception')) {
    /**
     * An exception nothing caught: recorded, then handed straight back to PHP,
     * which ends the request exactly as it always has — the 500 (CLI: exit code
     * 255), its own "PHP Fatal error: Uncaught …" log line, and error_get_last()
     * set for the shutdown functions that look at it (the push dispatcher sends
     * nothing after a crashed request; the SMS worker logs why its run ended).
     * The log reader matches that line to this record, so it counts once.
     */
    function hod_monitor_on_exception(Throwable $e): void
    {
        $GLOBALS['hod_monitor']['uncaught'] = true;
        hod_monitor_report($e, [], 'fatal');

        throw $e;
    }
}

if (!function_exists('hod_monitor_on_shutdown')) {
    /** Fatal errors, slow pages and the cron heartbeat; then the write, after the response. */
    function hod_monitor_on_shutdown(): void
    {
        $st = &$GLOBALS['hod_monitor'];
        try {
            $err = error_get_last();
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if ($err && in_array($err['type'], $fatalTypes, true)) {
                $st['fatal'] = true;
                if (str_contains($err['message'], 'Allowed memory size')) {
                    // The same limit applies now: give the record (and the page's error) some room.
                    @ini_set('memory_limit', (string) (memory_get_usage() + 32 * 1048576));
                }
                $ours = $st['uncaught'] && str_starts_with($err['message'], 'Uncaught');
                if (!$ours && hod_monitor_enabled()) {
                    hod_monitor_capture([
                        'kind'    => 'php',
                        'level'   => 'fatal',
                        'message' => $err['message'],
                        'file'    => $err['file'],
                        'line'    => $err['line'],
                        'force'   => true,
                        'spool'   => str_contains($err['message'], 'Maximum execution time'),
                    ]);
                }
            }
            if ($st['uncaught']) {
                $st['fatal'] = true;
            }

            $ms = (int) round((microtime(true) - $st['started']) * 1000);
            $st['duration_ms'] = $ms;
            // The setting is only read for a request that took over a second, so
            // an ordinary page never opens .env for it.
            if (!$st['cli'] && $ms >= 1000 && empty($st['context']['not_slow']) && hod_monitor_enabled()) {
                $slow = max(1000, (int) (hod_monitor_env('MONITOR_SLOW_MS') ?: 3000));
                if ($slow > 0 && $ms >= $slow) {
                    $req = hod_monitor_request();
                    hod_monitor_capture([
                        'kind'        => 'slow',
                        'level'       => 'slow',
                        'message'     => 'Slow ' . ($req['source'] === 'api' ? 'call' : 'page') . ': ' . number_format($ms / 1000, 1) . ' s',
                        'duration_ms' => $ms,
                        'force'       => true,
                    ]);
                }
            }

            if ($st['cron'] !== null) {
                hod_monitor_beat($st['cron'], 'finish', !$st['fatal'], $st['fatal'] ? hod_monitor_first_message() : '', $ms);
            }

            // Decided now, and never after a fatal error: nothing here may touch
            // error_get_last() before the push dispatcher reads it.
            $st['bg_due'] = !$st['fatal'] && hod_monitor_background_due();
            // Always registered now, so it runs after every other shutdown function
            // (the push dispatcher's included) and still records what they raise.
            // It does nothing at all when there is nothing to write.
            register_shutdown_function('hod_monitor_flush');
        } catch (Throwable $x) {
            @error_log('HODMON shutdown: ' . $x->getMessage());
        }
    }
}

if (!function_exists('hod_monitor_flush')) {
    /** Write what this request collected. The visitor has their response already. */
    function hod_monitor_flush(): void
    {
        $st = &$GLOBALS['hod_monitor'];
        if ($st['flushed']) {
            return;
        }
        // From here on the handler steps aside: anything raised later (destructors,
        // the session write) is logged by PHP itself and read in by the log reader.
        $st['flushed'] = true;
        if (!hod_monitor_enabled() || (!$st['buffer'] && empty($st['bg_due']))) {
            return;
        }
        try {
            if (!$st['cli']) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                @ignore_user_abort(true);
            }
            require_once __DIR__ . '/store.php';
            monitor_flush_request($st);
        } catch (Throwable $x) {
            @error_log('HODMON could not write its record: ' . $x->getMessage());
        }
    }
}

if (!function_exists('hod_monitor_report')) {
    /**
     * A caught exception worth the team knowing about. Returns its reference
     * code (e.g. "E7KQ2M9X", easy to read out over the phone), or null.
     */
    function hod_monitor_report(Throwable $e, array $context = [], string $level = 'error'): ?string
    {
        if (!isset($GLOBALS['hod_monitor']) || !hod_monitor_enabled()) {
            return null;
        }
        $frames = hod_monitor_frames(array_merge(
            [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '{throw}']],
            $e->getTrace()
        ));
        [$file, $line] = hod_monitor_app_frame($frames, $e->getFile(), $e->getLine());
        $causes = [];
        for ($p = $e->getPrevious(), $i = 0; $p !== null && $i < 3; $p = $p->getPrevious(), $i++) {
            $causes[] = get_class($p) . ': ' . $p->getMessage();
        }

        return hod_monitor_capture([
            'kind'            => 'exception',
            'level'           => $level,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
            'file'            => $file,
            'line'            => $line,
            'frames'          => $frames,
            'causes'          => $causes,
            'context'         => $context,
            'force'           => $level === 'fatal',
        ]);
    }
}

if (!function_exists('hod_monitor_note')) {
    /** Any other problem: hod_monitor_note('error', 'BulkSMS refused the token', ['campaign' => 12]). */
    function hod_monitor_note(string $level, string $message, array $context = []): ?string
    {
        if (!isset($GLOBALS['hod_monitor']) || !hod_monitor_enabled()) {
            return null;
        }
        $at = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        if ($level === 'fatal') {
            $GLOBALS['hod_monitor']['fatal'] = true;   // a cron run that hit this did not succeed
        }

        return hod_monitor_capture([
            'kind'    => 'log',
            'level'   => in_array($level, ['fatal', 'error', 'warning', 'notice'], true) ? $level : 'error',
            'message' => $message,
            'file'    => $at['file'] ?? null,
            'line'    => $at['line'] ?? null,
            'context' => $context,
            'force'   => $level === 'fatal',
        ]);
    }
}

if (!function_exists('hod_monitor_capture')) {
    /** Keep one problem in memory for the write after the response. Returns its code, or null. */
    function hod_monitor_capture(array $e): ?string
    {
        if (!isset($GLOBALS['hod_monitor'])) {
            return null;
        }
        $st = &$GLOBALS['hod_monitor'];
        if ($st['busy'] || $st['flushed'] || ($st['count'] >= HOD_MONITOR_MAX_PER_REQUEST && empty($e['force']))) {
            return null;   // after the write, PHP logs it as usual and the log reader takes it
        }
        $st['busy'] = true;
        try {
            $key = ($e['kind'] ?? '') . '|' . ($e['level'] ?? '') . '|' . ($e['file'] ?? '') . '|' . ($e['line'] ?? '')
                . '|' . ($e['exception_class'] ?? '') . '|' . mb_substr((string) ($e['message'] ?? ''), 0, 200);
            if (isset($st['seen'][$key])) {
                $i = $st['seen'][$key];
                $st['buffer'][$i]['repeat'] = ($st['buffer'][$i]['repeat'] ?? 1) + 1;
                return $st['buffer'][$i]['ref'];
            }
            $ref = 'E';
            for ($i = 0; $i < 7; $i++) {
                $ref .= HOD_MONITOR_REF_CHARS[random_int(0, 31)];
            }
            $e['ref'] = $ref;
            $e['at'] = time();
            $e['request'] = hod_monitor_request();
            $e['memory_mb'] = (int) round(memory_get_peak_usage() / 1048576);
            unset($e['force']);
            $st['buffer'][] = $e;
            $st['seen'][$key] = count($st['buffer']) - 1;
            $st['count']++;

            return $ref;
        } catch (Throwable $x) {
            return null;
        } finally {
            $st['busy'] = false;
        }
    }
}

if (!function_exists('hod_monitor_request')) {
    /**
     * Where this happened and for whom, as raw facts held in memory only. The
     * address is cleaned, the user id turned into a keyed hash and the user
     * agent into a browser family before anything is stored (store.php).
     */
    function hod_monitor_request(): array
    {
        $st = $GLOBALS['hod_monitor'] ?? ['cli' => PHP_SAPI === 'cli', 'cron' => null];
        if ($st['cli']) {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? 'cli')));
            $short = preg_match('#/((?:cron|bin|db)/[^/]+)$#', $script, $m) ? $m[1] : basename($script);

            return ['source' => $st['cron'] !== null ? 'cron' : 'cli', 'method' => 'CLI', 'uri' => $short,
                    'host' => '', 'role' => 'system', 'user_id' => null, 'ua' => '', 'ip' => ''];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $plain = (string) parse_url($uri, PHP_URL_PATH);
        $source = str_starts_with($plain, '/api/') ? 'api'
            : (str_starts_with($plain, '/webhook/') ? 'webhook' : 'page');
        // Not only an ACTIVE session: an endpoint that released its lock early
        // (session_write_close(), as 14 do) keeps $_SESSION and was being recorded
        // as "guest". A revoked session is emptied ($_SESSION = []) before it is closed.
        $session = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
        $userId = isset($session['user_id']) ? (int) $session['user_id'] : null;
        $role = isset($session['active_role']) ? (string) $session['active_role'] : ($userId ? 'member' : 'guest');

        return [
            'source'  => $source,
            'method'  => strtoupper(substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8)),
            'uri'     => $uri,
            'host'    => strtolower(preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? ''),
            'role'    => mb_substr(preg_replace('/[^A-Za-z0-9_\- ]/', '', $role) ?? 'guest', 0, 40),
            'user_id' => $userId,
            'ua'      => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'      => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
        ];
    }
}

if (!function_exists('hod_monitor_frames')) {
    /** Stack frames with nothing but where and what: no arguments, ever. */
    function hod_monitor_frames(array $trace): array
    {
        $out = [];
        foreach (array_slice($trace, 0, 30) as $f) {
            $out[] = [
                'file'     => isset($f['file']) ? (string) $f['file'] : null,
                'line'     => isset($f['line']) ? (int) $f['line'] : null,
                'function' => (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '') . (string) ($f['function'] ?? ''),
            ];
        }

        return $out;
    }
}

if (!function_exists('hod_monitor_app_frame')) {
    /** The first frame in the site's own code (not vendor/): where to look, and how to group. */
    function hod_monitor_app_frame(array $frames, ?string $file, ?int $line): array
    {
        foreach ($frames as $f) {
            $path = str_replace('\\', '/', (string) ($f['file'] ?? ''));
            if ($path !== '' && !str_contains($path, '/vendor/') && !str_contains($path, '/includes/monitor/')) {
                return [$f['file'], $f['line']];
            }
        }

        return [$file, $line];
    }
}

if (!function_exists('hod_monitor_displaying')) {
    /**
     * Is someone watching errors on screen (a developer's machine, or a person
     * running a script in a terminal)? Then PHP keeps showing them. A cron job
     * has display_errors on too (the CLI default) but no terminal: not watching.
     */
    function hod_monitor_displaying(): bool
    {
        $v = strtolower((string) ini_get('display_errors'));
        if (!in_array($v, ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true)) {
            return false;
        }
        if (PHP_SAPI === 'cli') {
            return defined('STDOUT') && function_exists('stream_isatty') && @stream_isatty(STDOUT);
        }

        return true;
    }
}

if (!function_exists('hod_monitor_log_line')) {
    /** The one line the server log gets instead of PHP's: marked, short, redacted. */
    function hod_monitor_log_line(string $ref, string $level, string $message, ?string $file, ?int $line): void
    {
        try {
            require_once __DIR__ . '/redact.php';
            $first = strtok(monitor_redact($message), "\n") ?: '';
            $where = $file ? ' in ' . (monitor_short_path($file) ?? '') . ($line ? ' on line ' . $line : '') : '';
            @error_log('HODMON [' . $ref . '] ' . $level . ': ' . mb_substr($first, 0, 1000) . $where);
        } catch (Throwable $x) {
            // the record itself still happens
        }
    }
}

if (!function_exists('hod_monitor_first_message')) {
    /** What stopped a cron run, redacted: it is stored in the heartbeat and shown in Health. */
    function hod_monitor_first_message(): string
    {
        foreach ($GLOBALS['hod_monitor']['buffer'] ?? [] as $e) {
            if (in_array($e['level'] ?? '', ['fatal', 'error'], true)) {
                require_once __DIR__ . '/redact.php';
                $text = trim((string) ($e['exception_class'] ?? '') . ' ' . (string) $e['message']);

                return mb_substr(monitor_redact(strtok($text, "\n") ?: $text), 0, 300);
            }
        }

        return 'stopped with a fatal error';
    }
}

if (!function_exists('hod_monitor_env')) {
    /** A setting from .env (loaded by db.php, or read from the file when it has not been). */
    function hod_monitor_env(string $key): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return trim((string) $_ENV[$key]);
        }
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return trim($v);
        }
        if (!empty($_ENV['DB_NAME']) || !empty($_ENV['DB_HOST'])) {
            return '';   // db.php has loaded .env already: the setting is simply not there
        }
        if (!function_exists('site_env')) {
            require_once dirname(__DIR__) . '/site_url.php';
        }

        return site_env($key);
    }
}

if (!function_exists('hod_monitor_data_dir')) {
    /**
     * The monitor's own folder, OUTSIDE the docroot: /home/<account>/.hodlekki_monitor
     * on the server (MONITOR_DATA_DIR overrides; elsewhere the system temp folder).
     * Holds the spool, cron heartbeats, rate-limit counters and small state files.
     * Null when nothing writable could be found.
     */
    function hod_monitor_data_dir(): ?string
    {
        static $dir = false;
        if ($dir !== false && ($dir === null || is_dir($dir))) {
            return $dir;
        }
        $want = hod_monitor_env('MONITOR_DATA_DIR');
        $candidates = [];
        if ($want !== '') {
            $candidates[] = $want;
        } else {
            $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
            if (preg_match('#^(/home\d*/[^/]+)/#', $root, $m)) {
                $candidates[] = $m[1] . '/.hodlekki_monitor';   // never inside any public_html
            }
            $candidates[] = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/hodlekki_monitor';
        }
        foreach ($candidates as $c) {
            $c = rtrim($c, '/\\');
            if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) {
                return $dir = $c;
            }
        }

        return $dir = null;
    }
}

if (!function_exists('hod_monitor_beat')) {
    /**
     * A cron job's heartbeat, in a small file per job (no database needed, so a
     * job still reports when the database is the problem). Unix times.
     */
    function hod_monitor_beat(string $name, string $phase, bool $ok = true, string $error = '', ?int $ms = null): void
    {
        try {
            $dir = hod_monitor_data_dir();
            if ($dir === null || !preg_match('/^[A-Za-z0-9_]{1,60}$/', $name)) {
                return;
            }
            if (!is_dir($dir . '/beats')) {
                @mkdir($dir . '/beats', 0700, true);
            }
            $fh = @fopen($dir . '/beats/' . $name . '.json', 'c+');
            if (!$fh) {
                return;
            }
            flock($fh, LOCK_EX);
            $data = json_decode((string) stream_get_contents($fh), true);
            $data = is_array($data) ? $data : ['name' => $name, 'runs' => 0, 'failures' => 0];
            $now = time();
            if ($phase === 'start') {
                $data['started_at'] = $now;
            } else {
                $data['finished_at'] = $now;
                $data['ok'] = $ok;
                $data['duration_ms'] = $ms;
                $data['runs'] = (int) ($data['runs'] ?? 0) + 1;
                if (!$ok) {
                    $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
                    $data['last_failure_at'] = $now;
                    $data['last_error'] = mb_substr($error, 0, 300);
                }
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (Throwable $x) {
            // a heartbeat is never worth breaking a job
        }
    }
}

if (!function_exists('hod_monitor_background_due')) {
    /**
     * Now and then (about one web request in 20) the request's after-response
     * time is also used to note how the WEB PHP runs (it differs from the cron's
     * command-line PHP) and to check that the monitor's own cron is alive. A
     * single file time is read; nothing else.
     */
    function hod_monitor_background_due(): bool
    {
        $st = $GLOBALS['hod_monitor'];
        if ($st['cli'] || random_int(1, 20) !== 1) {
            return false;
        }
        $dir = hod_monitor_data_dir();
        if ($dir === null) {
            return false;
        }
        // is_file() first: a failed filemtime() raises a warning, and even a
        // silenced one would replace what error_get_last() holds.
        $file = $dir . '/web.json';

        return !is_file($file) || filemtime($file) < time() - 600;
    }
}
