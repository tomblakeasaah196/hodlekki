<?php
// /includes/monitor/store.php
/**
 * Writing problems down (docs/monitor.md, "Storage").
 *
 *   monitor_flush_request($state)   what capture.php collected, after the response
 *   monitor_store_all($db, $events) record prepared events (also used by the log
 *                                   reader, the browser intake and the spool import)
 *   monitor_record($db, $event)     one occurrence: grouped by fingerprint, counted
 *                                   per hour, people and pages; reopens a problem
 *                                   marked fixed (a regression); notes a spike
 *   monitor_spool_write/import      the fallback file when the database is unreachable
 *   monitor_state_get/set           small key-value settings (monitor_state)
 *
 * The SQL runs on MySQL 8, MariaDB 10.11 and SQLite (the tests): times are
 * passed in from PHP, never NOW(), and the two upsert spellings live in
 * monitor_upsert() / monitor_insert_ignore().
 */

require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/redact.php';
if (!function_exists('site_env')) {
    require_once dirname(__DIR__) . '/site_url.php';
}

const MONITOR_EVENTS_KEPT     = 50;     // occurrences kept per problem
const MONITOR_EVENTS_PER_HOUR = 20;     // occurrence rows per problem per hour (fatal: always)
const MONITOR_SPOOL_MAX_BYTES = 20 * 1048576;
const MONITOR_BROWSER_NEW_PER_HOUR = 200;   // new problems a browser report may open in an hour

/** A record deliberately not kept (a quota): skipped quietly, never retried. */
final class MonitorSkip extends RuntimeException
{
}
const MONITOR_LEVELS          = ['fatal', 'error', 'warning', 'notice', 'deprecated', 'slow', 'info'];
const MONITOR_STATUSES        = ['new', 'investigating', 'fixed', 'ignored'];

/* ───────────────────────────── time, keys, connection ───────────────────────────── */

/** Now, as a Unix time (tests set $GLOBALS['monitor_test_now']). */
function monitor_time(): int
{
    return (int) ($GLOBALS['monitor_test_now'] ?? time());
}

/** A Unix time as Lagos 'Y-m-d H:i:s', the way the rest of the database keeps time. */
function monitor_fmt(?int $ts = null, string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('@' . ($ts ?? monitor_time())))
        ->setTimezone(new DateTimeZone('Africa/Lagos'))->format($format);
}

/** A Lagos 'Y-m-d H:i:s' back to a Unix time. */
function monitor_ts(?string $lagos): ?int
{
    if ($lagos === null || $lagos === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($lagos, new DateTimeZone('Africa/Lagos')))->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

/** The project root (the docroot on the server). */
function monitor_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * The monitor's own database connection, so a page's rolled-back transaction
 * cannot take a record with it. Tests hand one in with monitor_db_set().
 */
function monitor_db(): PDO
{
    if (isset($GLOBALS['monitor_db_override']) && $GLOBALS['monitor_db_override'] instanceof PDO) {
        return $GLOBALS['monitor_db_override'];
    }
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ];
    $dsn = hod_monitor_env('MONITOR_DB_DSN');   // tests only: sqlite:/path/to/file
    if ($dsn !== '') {
        $pdo = new PDO($dsn, null, null, $options);
        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA busy_timeout = 3000');
        }
        return $pdo;
    }
    $host = hod_monitor_env('DB_HOST') ?: 'localhost';
    $port = hod_monitor_env('DB_PORT');
    $pdo = new PDO(
        'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . hod_monitor_env('DB_NAME') . ';charset=utf8mb4',
        hod_monitor_env('DB_USER'),
        hod_monitor_env('DB_PASS'),
        $options
    );
    try {
        $pdo->exec("SET time_zone = '+01:00'");
    } catch (Throwable $e) {
        // best effort, as in includes/db.php
    }

    return $pdo;
}

function monitor_db_set(?PDO $pdo): void
{
    $GLOBALS['monitor_db_override'] = $pdo;
}

function monitor_driver(PDO $db): string
{
    return (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Do the monitor's tables exist yet? (The tree reaches the server before the migration.) */
function monitor_ready(PDO $db): bool
{
    static $ready = [];
    $key = spl_object_id($db);
    if (!empty($ready[$key])) {
        return true;
    }
    try {
        $db->query('SELECT 1 FROM monitor_state WHERE 1 = 0');
        return $ready[$key] = true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * INSERT, or UPDATE the row with the same key. $set maps a column to an SQL
 * expression; {col} in it means the value this insert tried to write.
 */
function monitor_upsert(PDO $db, string $table, array $row, array $key, array $set): void
{
    $cols = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $sqlite = monitor_driver($db) === 'sqlite';
    $parts = [];
    foreach ($set as $col => $expr) {
        $expr = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $sqlite ? 'excluded.' . $m[1] : 'VALUES(' . $m[1] . ')', $expr);
        $parts[] = $col . ' = ' . $expr;
    }
    $sql .= $sqlite
        ? ' ON CONFLICT(' . implode(', ', $key) . ') DO UPDATE SET ' . implode(', ', $parts)
        : ' ON DUPLICATE KEY UPDATE ' . implode(', ', $parts);
    $db->prepare($sql)->execute(array_values($row));
}

/** INSERT unless a row with the same key exists. */
function monitor_insert_ignore(PDO $db, string $table, array $row): void
{
    $cols = array_keys($row);
    $verb = monitor_driver($db) === 'sqlite' ? 'INSERT OR IGNORE INTO ' : 'INSERT IGNORE INTO ';
    $db->prepare($verb . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
       ->execute(array_values($row));
}

function monitor_state_get(PDO $db, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $db->prepare('SELECT v FROM monitor_state WHERE k = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function monitor_state_set(PDO $db, string $key, ?string $value): void
{
    monitor_upsert($db, 'monitor_state', ['k' => $key, 'v' => $value, 'updated_at' => monitor_fmt()], ['k'],
        ['v' => '{v}', 'updated_at' => '{updated_at}']);
}

/** The release the server runs (written by the cron from ~/deploy.log): ['sha','subject','at'] or []. */
function monitor_release(): array
{
    static $release = null;
    if ($release !== null) {
        return $release;
    }
    $dir = hod_monitor_data_dir();
    $data = $dir !== null && is_file($dir . '/release.json') ? json_decode((string) @file_get_contents($dir . '/release.json'), true) : null;

    return $release = is_array($data) ? $data : [];
}

function monitor_release_id(): ?string
{
    $sha = (string) (monitor_release()['sha'] ?? '');
    return $sha !== '' ? substr($sha, 0, 12) : null;
}

/* ───────────────────────────── shaping an event ───────────────────────────── */

/** The shape of a message, so "user 42" and "user 8341" are one problem. */
function monitor_normalise(string $message): string
{
    $s = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message) ?? $message;
    $s = preg_replace('/\b[0-9a-f]{12,}\b/i', '?', $s) ?? $s;
    $s = preg_replace('/(?<![A-Za-z_$])\d+(?:\.\d+)?/', '#', $s) ?? $s;
    $s = preg_replace('/\s+VALUES\s*\(.*$/is', ' VALUES (…)', $s) ?? $s;

    return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? $s), 0, 500);
}

/** One problem's identity: the same message from the same place is the same problem. */
function monitor_fingerprint(array $ev): string
{
    $kind = (string) $ev['kind'];
    if ($kind === 'slow') {
        return sha1('slow|' . ($ev['method'] ?? '') . '|' . ($ev['route'] ?? ''));
    }
    if ($kind === 'csp') {
        return sha1('csp|' . ($ev['exception_class'] ?? '') . '|' . monitor_normalise((string) $ev['title']));
    }
    $first = strtok((string) $ev['message'], "\n") ?: '';

    return sha1(implode('|', [
        $kind,
        in_array($kind, ['php', 'log'], true) ? $ev['level'] : '',
        $ev['exception_class'] ?? '',
        monitor_normalise($first),
        $ev['file'] ?? '',
        $kind === 'log' && empty($ev['file']) ? '' : (string) ($ev['line'] ?? ''),
    ]));
}

/** Frames as text: "#0 modules/events/index.php:120 render()". */
function monitor_trace_text(array $frames): string
{
    $out = [];
    foreach (array_values($frames) as $i => $f) {
        $where = !empty($f['file']) ? monitor_short_path((string) $f['file']) . ':' . ($f['line'] ?? '?') : '[internal]';
        $out[] = '#' . $i . ' ' . $where . (($f['function'] ?? '') !== '' ? ' ' . $f['function'] . '()' : '');
    }

    return implode("\n", $out);
}

/**
 * From what capture.php (or the intake, or the log reader) collected to what
 * may be stored: cleaned address, hashed person, browser family, redacted text.
 */
function monitor_prepare_event(array $e): array
{
    $req = $e['request'] ?? [];
    $kind = (string) ($e['kind'] ?? 'log');
    $level = in_array($e['level'] ?? '', MONITOR_LEVELS, true) ? (string) $e['level'] : 'error';
    $uri = (string) ($req['uri'] ?? '');
    [$path, $route] = $uri === '' ? [null, null] : monitor_clean_url(str_starts_with($uri, '/') ? $uri : '/' . $uri);
    $method = (string) ($req['method'] ?? '');

    $message = monitor_redact(trim((string) ($e['message'] ?? '')));
    if ($message === '') {
        $message = $kind === 'exception' ? (string) ($e['exception_class'] ?? 'Exception') : '(no message)';
    }
    foreach ((array) ($e['causes'] ?? []) as $cause) {
        $message .= "\nCaused by " . monitor_redact((string) $cause);
    }
    $message = mb_substr($message, 0, 20000);

    $file = $e['file'] ?? null;
    if ($kind === 'js' || $kind === 'csp') {
        $file = $file !== null && $file !== '' ? mb_substr(monitor_clean_url((string) $file)[0], 0, 255) : null;
    } else {
        $file = monitor_short_path($file !== null ? (string) $file : null);
    }

    $trace = null;
    if (!empty($e['frames'])) {
        $trace = monitor_trace_text((array) $e['frames']);
    } elseif (!empty($e['trace_text'])) {
        $trace = (string) $e['trace_text'];
    }
    $trace = $trace !== null ? mb_substr(monitor_redact($trace), 0, 60000) : null;

    $context = [];
    foreach ((array) ($e['context'] ?? []) as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $context[mb_substr((string) $k, 0, 40)] = is_string($v) ? mb_substr(monitor_redact($v), 0, 300) : $v;
        }
    }
    if (!empty($e['repeat']) && $e['repeat'] > 1) {
        $context['times_in_request'] = (int) $e['repeat'];
    }

    $title = (string) ($e['title'] ?? '');
    if ($title === '') {
        $title = strtok($message, "\n") ?: $message;
        if ($kind === 'slow') {
            $title = 'Slow ' . ($req['source'] ?? '') . ': ' . $method . ' ' . $route;
        }
    }
    $title = mb_substr(monitor_redact($title), 0, 255);

    $userHash = null;
    if (!empty($req['user_id'])) {
        $userHash = monitor_hash('u:' . (int) $req['user_id']);
    } elseif (!empty($req['ip']) && ($req['source'] ?? '') !== 'cron') {
        // A visitor who is not signed in: counted by address for one day, never
        // followed across days. Not by browser too: a sender that only changes its
        // user agent must not count as several people (the 3-people alert rule).
        $userHash = monitor_hash('g:' . monitor_fmt((int) ($e['at'] ?? monitor_time()), 'Ymd') . '|' . $req['ip']);
    }

    $ev = [
        'kind'            => $kind,
        'level'           => $level,
        'source'          => mb_substr((string) ($req['source'] ?? $kind), 0, 12),
        'title'           => $title,
        'message'         => $message,
        'exception_class' => isset($e['exception_class']) ? mb_substr((string) $e['exception_class'], 0, 190) : null,
        'file'            => $file,
        'line'            => monitor_int_or_null($e['line'] ?? null),
        'route'           => $route,
        'path'            => $path,
        'method'          => $method !== '' ? mb_substr($method, 0, 8) : null,
        'host'            => !empty($req['host']) ? mb_substr((string) $req['host'], 0, 64) : null,
        'role'            => !empty($req['role']) ? mb_substr((string) $req['role'], 0, 40) : null,
        'user_hash'       => $userHash,
        'release'         => $e['release'] ?? monitor_release_id(),
        'device'          => !empty($req['device']) ? (string) $req['device'] : (!empty($req['ua']) ? monitor_device_class((string) $req['ua']) : null),
        'browser'         => !empty($req['ua']) ? monitor_browser_family((string) $req['ua']) : null,
        'duration_ms'     => monitor_int_or_null($e['duration_ms'] ?? null),
        'memory_mb'       => monitor_int_or_null($e['memory_mb'] ?? null),
        'trace'           => $trace,
        'context'         => $context ? mb_substr((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000) : null,
        'ref'             => (string) ($e['ref'] ?? monitor_new_ref()),
        'at'              => (int) ($e['at'] ?? monitor_time()),
        'bulk'            => !empty($e['bulk']),
    ];
    $ev['fingerprint'] = monitor_fingerprint($ev);

    return $ev;
}

/** A whole number that fits an INT column (0 to 2^31 - 1), or null: browsers can send anything. */
function monitor_int_or_null($v): ?int
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    $f = (float) $v;

    return $f >= 0 && $f <= 2147483647 ? (int) $f : null;
}

function monitor_new_ref(): string
{
    $ref = 'E';
    for ($i = 0; $i < 7; $i++) {
        $ref .= HOD_MONITOR_REF_CHARS[random_int(0, 31)];
    }

    return $ref;
}

/* ───────────────────────────── recording ───────────────────────────── */

/**
 * Record prepared events, each on its own: one the database refuses (bad data)
 * is skipped with a line in the log, so it can never block the others. Throws
 * only when the database itself is unreachable; $handled then says how many
 * events were dealt with (written or skipped) before that, so a caller re-spools
 * only the rest and nothing is written twice. Returns how many were written.
 */
function monitor_store_all(PDO $db, array $events, ?int &$handled = null): int
{
    $written = 0;
    $handled = 0;
    foreach (array_values($events) as $ev) {
        try {
            if (!isset($ev['fingerprint'])) {
                $ev = monitor_prepare_event($ev);
            }
            monitor_record($db, $ev);
            $written++;
        } catch (MonitorSkip $e) {
            // deliberately not kept
        } catch (Throwable $e) {
            if (monitor_is_connection_error($e)) {
                throw $e;
            }
            @error_log('HODMON skipped a record the database refused: ' . mb_substr(monitor_redact($e->getMessage()), 0, 300));
        }
        $handled++;
    }

    return $written;
}

/** Is this the database being unreachable or overloaded (try later), rather than one bad record? */
function monitor_is_connection_error(Throwable $e): bool
{
    return (bool) preg_match('/SQLSTATE\[08|\[(1040|1203|2002|2003|2005|2006|2013)\]|server has gone away|Lost connection|Too many connections|max_user_connections|database is locked|Connection refused/i', $e->getMessage());
}

/**
 * One occurrence. Returns ['group_id', 'ref', 'is_new', 'came_back'].
 */
function monitor_record(PDO $db, array $ev): array
{
    $at = monitor_fmt($ev['at']);
    $hour = monitor_fmt($ev['at'], 'Y-m-d H:00:00');
    $day = monitor_fmt($ev['at'], 'Y-m-d');
    $isNew = false;
    $cameBack = false;

    $group = monitor_group_by_fingerprint($db, $ev['fingerprint']);
    if ($group === null && in_array($ev['kind'], ['js', 'csp'], true)) {
        // Anyone can send a browser report, and a sender varying the message would
        // open a new problem each time: at most MONITOR_BROWSER_NEW_PER_HOUR an hour.
        $key = 'browser_new:' . monitor_fmt($ev['at'], 'YmdH');
        $opened = (int) monitor_state_get($db, $key, '0');
        if ($opened >= MONITOR_BROWSER_NEW_PER_HOUR) {
            throw new MonitorSkip('browser problem quota reached for this hour');
        }
        monitor_state_set($db, $key, (string) ($opened + 1));
    }
    if ($group === null) {
        try {
            $db->prepare(
                'INSERT INTO monitor_error_groups (fingerprint, kind, level, source, title, message, exception_class, file, line, route,
                     status, first_seen_at, last_seen_at, first_release, last_release, occurrences, trace)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            )->execute([
                $ev['fingerprint'], $ev['kind'], $ev['level'], $ev['source'], $ev['title'], $ev['message'], $ev['exception_class'],
                $ev['file'], $ev['line'], $ev['route'], 'new', $at, $at, $ev['release'], $ev['release'], $ev['trace'],
            ]);
            $groupId = (int) $db->lastInsertId();
            $isNew = true;
            $group = ['id' => $groupId, 'status' => 'new', 'occurrences' => 1, 'last_seen_at' => $at, 'fixed_at' => null, 'spiked_at' => null];
        } catch (PDOException $e) {
            // Two requests met a brand-new problem at the same moment: the other one
            // created the group, so this is simply the second occurrence.
            $group = monitor_group_by_fingerprint($db, $ev['fingerprint']);
            if ($group === null) {
                throw $e;
            }
        }
    }
    $groupId = (int) $group['id'];

    if (!$isNew) {
        $occurrences = (int) $group['occurrences'] + 1;
        $set = ['occurrences = occurrences + 1', 'message = ?', 'last_release = ?'];
        $args = [$ev['message'], $ev['release']];
        if ($at > (string) $group['last_seen_at']) {
            $set[] = 'last_seen_at = ?';
            $args[] = $at;
        }
        if (empty($group['has_trace']) && $ev['trace'] !== null) {
            $set[] = 'trace = ?';
            $args[] = $ev['trace'];
        }
        // Marked fixed, and it happened again after that: a regression. Open it
        // again, and let the alerts announce it again.
        if ($group['status'] === 'fixed' && $group['fixed_at'] !== null && $at > (string) $group['fixed_at']) {
            $cameBack = true;
            array_push($set, "status = 'new'", 'regressions = regressions + 1', 'regressed_at = ?', 'status_changed_at = ?', 'status_by = NULL', 'alerted_at = NULL');
            array_push($args, $at, $at);
        }
        $args[] = $groupId;
        $db->prepare('UPDATE monitor_error_groups SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        if ($cameBack) {
            monitor_add_note($db, $groupId, null, 'Came back on ' . $at . ' after being marked fixed on ' . $group['fixed_at']
                . ($group['fixed_release'] ? ' (release ' . $group['fixed_release'] . ')' : '') . ($ev['release'] ? '; now running ' . $ev['release'] : '') . '.', $ev['at']);
        }
    } else {
        $occurrences = 1;
    }

    monitor_upsert($db, 'monitor_error_hourly', ['group_id' => $groupId, 'hour' => $hour, 'n' => 1], ['group_id', 'hour'], ['n' => 'n + 1']);
    $stmt = $db->prepare('SELECT n FROM monitor_error_hourly WHERE group_id = ? AND hour = ?');
    $stmt->execute([$groupId, $hour]);
    $thisHour = (int) $stmt->fetchColumn();

    if ($thisHour <= MONITOR_EVENTS_PER_HOUR || $ev['level'] === 'fatal') {
        $db->prepare(
            'INSERT INTO monitor_error_events (group_id, ref, occurred_at, source, method, path, host, role, user_hash, release_id,
                 device, browser, duration_ms, memory_mb, message, trace, context)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $groupId, $ev['ref'], $at, $ev['source'], $ev['method'], $ev['path'], $ev['host'], $ev['role'], $ev['user_hash'], $ev['release'],
            $ev['device'], $ev['browser'], $ev['duration_ms'], $ev['memory_mb'], $ev['message'], $ev['trace'], $ev['context'],
        ]);
    }
    if ($ev['user_hash'] !== null) {
        monitor_insert_ignore($db, 'monitor_error_people', ['group_id' => $groupId, 'day' => $day, 'user_hash' => $ev['user_hash']]);
    }
    if ($ev['path'] !== null) {
        monitor_upsert($db, 'monitor_error_paths',
            ['group_id' => $groupId, 'path_hash' => sha1($ev['path']), 'path' => $ev['path'], 'n' => 1, 'last_seen_at' => $at],
            ['group_id', 'path_hash'], ['n' => 'n + 1', 'last_seen_at' => '{last_seen_at}']);
    }

    if ($occurrences % 10 === 0) {
        monitor_prune_events($db, $groupId);
        if ($group['status'] !== 'ignored') {
            monitor_spike_check($db, $groupId, $ev['at'], $group['spiked_at'] ?? null);
        }
    }

    return ['group_id' => $groupId, 'ref' => $ev['ref'], 'is_new' => $isNew, 'came_back' => $cameBack];
}

function monitor_group_by_fingerprint(PDO $db, string $fingerprint): ?array
{
    $stmt = $db->prepare(
        'SELECT id, status, occurrences, last_seen_at, fixed_at, fixed_release, spiked_at, (trace IS NOT NULL) AS has_trace
           FROM monitor_error_groups WHERE fingerprint = ?'
    );
    $stmt->execute([$fingerprint]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function monitor_add_note(PDO $db, int $groupId, ?int $userId, string $body, ?int $at = null): void
{
    $db->prepare('INSERT INTO monitor_error_notes (group_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
       ->execute([$groupId, $userId, mb_substr($body, 0, 5000), monitor_fmt($at)]);
}

/** Keep the latest MONITOR_EVENTS_KEPT occurrences of a problem. */
function monitor_prune_events(PDO $db, int $groupId): void
{
    $stmt = $db->prepare('SELECT id FROM monitor_error_events WHERE group_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (MONITOR_EVENTS_KEPT - 1));
    $stmt->execute([$groupId]);
    $keepFrom = $stmt->fetchColumn();
    if ($keepFrom !== false) {
        $db->prepare('DELETE FROM monitor_error_events WHERE group_id = ? AND id < ?')->execute([$groupId, (int) $keepFrom]);
    }
}

/**
 * Suddenly far more often than usual: at least 20 in the last hour and five
 * times its hourly average over the day before. Noted at most every 6 hours;
 * the alerts announce it (spiked_at newer than spike_alerted_at).
 */
function monitor_spike_check(PDO $db, int $groupId, int $now, ?string $spikedAt): bool
{
    if ($spikedAt !== null && (monitor_ts($spikedAt) ?? 0) > $now - 6 * 3600) {
        return false;
    }
    $stmt = $db->prepare('SELECT hour, n FROM monitor_error_hourly WHERE group_id = ? AND hour >= ?');
    $stmt->execute([$groupId, monitor_fmt($now - 25 * 3600, 'Y-m-d H:00:00')]);
    $lastHour = 0;
    $before = 0;
    $cut = monitor_fmt($now - 3600);
    $current = monitor_fmt($now, 'Y-m-d H:00:00');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['hour'] === $current || $r['hour'] >= $cut) {
            $lastHour += (int) $r['n'];
        } else {
            $before += (int) $r['n'];
        }
    }
    $usual = $before / 24;
    if ($lastHour >= 20 && $lastHour >= 5 * max(1.0, $usual)) {
        $db->prepare('UPDATE monitor_error_groups SET spiked_at = ? WHERE id = ?')->execute([monitor_fmt($now), $groupId]);
        monitor_add_note($db, $groupId, null, "Spiking: {$lastHour} times in the last hour, against about " . (int) round($usual) . ' an hour over the day before.', $now);
        return true;
    }

    return false;
}

/* ───────────────────────────── after the response ───────────────────────────── */

/** capture.php's write: events to the database (or the spool), and the occasional background look. */
function monitor_flush_request(array $st): void
{
    $events = [];
    $spoolOnly = false;
    foreach ($st['buffer'] as $raw) {
        $spoolOnly = $spoolOnly || !empty($raw['spool']);
        $events[] = monitor_prepare_event($raw);
    }

    $wrote = 0;
    if ($events) {
        if ($spoolOnly || monitor_circuit_open() || monitor_is_db_outage($st['buffer'])) {
            monitor_spool_write('events', $events);
        } else {
            $handled = 0;
            try {
                $db = monitor_db();
                if (!monitor_ready($db)) {
                    monitor_spool_write('events', $events);   // before the migration: kept for the cron
                } else {
                    $wrote = monitor_store_all($db, $events, $handled);
                }
            } catch (Throwable $e) {
                monitor_circuit_trip();
                monitor_spool_write('events', array_slice($events, (int) $handled));   // only what was not written
            }
        }
    }

    if (!$st['cli']) {
        try {
            monitor_background_web();
        } catch (Throwable $e) {
            // never more than a note in the log
            @error_log('HODMON background: ' . $e->getMessage());
        }
    }
    if ($wrote > 0) {
        try {
            require_once __DIR__ . '/wall.php';
            monitor_wall_publish(monitor_db(), false);
        } catch (Throwable $e) {
            // the cron publishes within the minute anyway
        }
    }
}

/** The problem IS the database: no point opening another connection to it. */
function monitor_is_db_outage(array $buffer): bool
{
    foreach ($buffer as $e) {
        if (preg_match('/SQLSTATE\[(HY000|08\w{3})\]\s*\[(1040|1203|2002|2003|2005|2006|2013|1045)\]|Too many connections|max_user_connections|Database Connection Failed/i', (string) ($e['message'] ?? ''))) {
            return true;
        }
    }

    return false;
}

/** After a failed connection, a minute without trying again (no pile-up on a struggling database). */
function monitor_circuit_open(): bool
{
    $dir = hod_monitor_data_dir();
    $t = $dir !== null ? @filemtime($dir . '/db_down') : false;

    return $t !== false && $t > time() - 60;
}

function monitor_circuit_trip(): void
{
    $dir = hod_monitor_data_dir();
    if ($dir !== null) {
        @touch($dir . '/db_down');
    }
}

/* ───────────────────────────── the spool ───────────────────────────── */

/** Append prepared records to the spool file for $kind ('events' or 'rum'). Never throws. */
function monitor_spool_write(string $kind, array $records): bool
{
    try {
        $dir = hod_monitor_data_dir();
        if ($dir === null || $records === []) {
            return false;
        }
        if (!is_dir($dir . '/spool')) {
            @mkdir($dir . '/spool', 0700, true);
        }
        $file = $dir . '/spool/' . $kind . '-' . gmdate('YmdH') . '.jsonl';
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > MONITOR_SPOOL_MAX_BYTES) {
            return false;
        }
        $lines = '';
        foreach ($records as $r) {
            $lines .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }

        return @file_put_contents($file, $lines, FILE_APPEND | LOCK_EX) !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Take everything spooled for $kind and hand it to $each, one record at a time,
 * oldest file first, each file under its lock (a writer appending meanwhile
 * only adds to the next import). If $each throws (the database went away), the
 * records not yet handled are written back and the import stops: nothing is
 * lost and nothing is handled twice. Returns how many records were handled.
 */
function monitor_spool_import(string $kind, callable $each, int $maxFiles = 30): int
{
    $dir = hod_monitor_data_dir();
    if ($dir === null || !is_dir($dir . '/spool')) {
        return 0;
    }
    $files = glob($dir . '/spool/' . $kind . '-*.jsonl') ?: [];
    sort($files);
    $count = 0;
    foreach (array_slice($files, 0, $maxFiles) as $file) {
        $fh = @fopen($file, 'r+');
        if (!$fh) {
            continue;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            continue;
        }
        $text = (string) stream_get_contents($fh);
        $records = [];
        foreach (explode("\n", $text) as $line) {
            $r = $line !== '' ? json_decode($line, true) : null;
            if (is_array($r)) {
                $records[] = $r;
            }
        }
        $done = 0;
        $failed = false;
        try {
            foreach ($records as $r) {
                $each($r);
                $done++;
            }
        } catch (Throwable $e) {
            $failed = true;
        }
        ftruncate($fh, 0);
        rewind($fh);
        if ($failed) {
            $rest = '';
            foreach (array_slice($records, $done) as $r) {
                $rest .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
            fwrite($fh, $rest);
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $count += $done;
        if ($failed) {
            break;   // the database is not answering: the rest waits for the next run
        }
        // An emptied file from an earlier hour will not be written again.
        if (!str_contains(basename($file), gmdate('YmdH'))) {
            @unlink($file);
        }
    }

    return $count;
}

/* ───────────────────────────── web PHP facts, watchdog ───────────────────────────── */

/**
 * How the WEB PHP runs (the cron's command-line PHP has other settings: this
 * host's web PHP has no fileinfo, its CLI does), written to web.json for the
 * health checks, and the watchdog: is the monitor's own cron still running?
 * Called after the response, about every 10 minutes.
 */
function monitor_background_web(): void
{
    $dir = hod_monitor_data_dir();
    if ($dir === null) {
        return;
    }
    $t = @filemtime($dir . '/web.json');
    if ($t !== false && $t > time() - 600) {
        return;
    }
    @file_put_contents($dir . '/web.json', (string) json_encode(monitor_web_facts()), LOCK_EX);
    if (!is_file($dir . '/installed_at')) {
        @file_put_contents($dir . '/installed_at', (string) time());
    }

    require_once __DIR__ . '/alerts.php';
    monitor_watchdog($dir);
}

/** The facts the PHP, extensions and speed checks need, from this (web) process. */
function monitor_web_facts(): array
{
    $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    $stats = is_array($opcache) ? ($opcache['opcache_statistics'] ?? []) : [];
    $want = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'json', 'gd', 'zip', 'dom', 'xml', 'simplexml', 'xmlwriter',
             'zlib', 'iconv', 'ctype', 'sodium', 'fileinfo', 'intl', 'exif'];
    $ext = [];
    foreach ($want as $name) {
        $ext[$name] = extension_loaded($name);
    }

    return [
        'at'            => time(),
        'php'           => PHP_VERSION,
        'sapi'          => PHP_SAPI,
        'extensions'    => $ext,
        'memory_limit'  => (string) ini_get('memory_limit'),
        'max_execution' => (int) ini_get('max_execution_time'),
        'upload_max'    => (string) ini_get('upload_max_filesize'),
        'display_errors'=> hod_monitor_displaying() || in_array(strtolower((string) ini_get('display_errors')), ['1', 'on'], true),
        'log_errors'    => (bool) ini_get('log_errors'),
        'error_log'     => (string) ini_get('error_log'),
        'prepend'       => defined('HOD_MONITOR_PREPENDED'),
        'early_finish'  => function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'),
        'opcache'       => is_array($opcache) && !empty($opcache['opcache_enabled']),
        'opcache_full'  => is_array($opcache) && !empty($opcache['cache_full']),
        'hit_rate'      => isset($stats['opcache_hit_rate']) ? (float) $stats['opcache_hit_rate'] : null,
        'hits'          => isset($stats['hits']) ? (int) $stats['hits'] : null,
        'misses'        => isset($stats['misses']) ? (int) $stats['misses'] : null,
        'cached_files'  => isset($stats['num_cached_scripts']) ? (int) $stats['num_cached_scripts'] : null,
        'restarts'      => (int) ($stats['oom_restarts'] ?? 0) + (int) ($stats['hash_restarts'] ?? 0),
        'free_mb'       => isset($opcache['memory_usage']['free_memory']) ? (int) round($opcache['memory_usage']['free_memory'] / 1048576) : null,
        'timezone'      => date_default_timezone_get(),
    ];
}
