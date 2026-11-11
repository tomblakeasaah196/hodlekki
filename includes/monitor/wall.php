<?php
// /includes/monitor/wall.php
/**
 * The live wall's data (docs/monitor.md, "Live wall").
 *
 * Shared hosting gives no websockets, and a held-open connection per screen
 * would pin one of the host's few PHP processes. So the monitor writes a small
 * JSON snapshot to live/monitor/<key>.json whenever something new is recorded
 * (at most every 5 seconds) and on every cron run, and LiteSpeed serves it as a
 * static file — the Special Events polling design (live/.htaccess): screens
 * poll it every few seconds without starting PHP, through Cloudflare.
 *
 * The file name carries a random key (monitor_state wall_key) that only the
 * signed-in wall page and the revocable TV link know. The snapshot holds no
 * personal data: counts, check states, problem titles (redacted when stored).
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/rum.php';

/** The wall's key; a new one (and the old file removed) when $rotate. */
function monitor_wall_key(PDO $db, bool $rotate = false): string
{
    $key = (string) monitor_state_get($db, 'wall_key', '');
    if ($rotate || !preg_match('/^[0-9a-f]{32}$/', $key)) {
        if ($key !== '' && is_file(monitor_wall_path($key))) {
            @unlink(monitor_wall_path($key));
        }
        $key = bin2hex(random_bytes(16));
        monitor_state_set($db, 'wall_key', $key);
    }

    return $key;
}

function monitor_wall_path(string $key): string
{
    $dir = hod_monitor_env('MONITOR_LIVE_DIR');   // tests only; the server serves live/monitor/

    return ($dir !== '' ? rtrim($dir, '/\\') : monitor_root() . '/live/monitor') . '/' . preg_replace('/[^0-9a-f]/', '', $key) . '.json';
}

/** Everything the wall shows, without a single personal detail. */
function monitor_wall_snapshot(PDO $db, ?int $now = null): array
{
    $now = $now ?? monitor_time();
    $checks = monitor_checks_current($db);
    $noisy = "g.level NOT IN ('notice', 'deprecated') AND g.kind NOT IN ('csp', 'slow')";

    $spark = array_fill(0, 24, 0);
    $stmt = $db->prepare("SELECT h.hour, SUM(h.n) AS n FROM monitor_error_hourly h JOIN monitor_error_groups g ON g.id = h.group_id
                           WHERE h.hour >= ? AND {$noisy} GROUP BY h.hour");
    $stmt->execute([monitor_fmt($now - 23 * 3600, 'Y-m-d H:00:00')]);
    $byHour = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'hour');
    for ($i = 23; $i >= 0; $i--) {
        $spark[23 - $i] = (int) ($byHour[monitor_fmt($now - $i * 3600, 'Y-m-d H:00:00')] ?? 0);
    }

    $stmt = $db->prepare("SELECT
            SUM(CASE WHEN g.status IN ('new', 'investigating') THEN 1 ELSE 0 END) AS open_n,
            SUM(CASE WHEN g.first_seen_at >= ? THEN 1 ELSE 0 END) AS new_24h
          FROM monitor_error_groups g WHERE {$noisy}");
    $stmt->execute([monitor_fmt($now - 86400)]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare("SELECT g.id, g.title, g.kind, g.level, g.source, g.status, g.occurrences, g.regressions, g.last_seen_at, g.route
                            FROM monitor_error_groups g WHERE g.status IN ('new', 'investigating') AND g.last_seen_at >= ? AND {$noisy}
                           ORDER BY g.last_seen_at DESC LIMIT 10");
    $stmt->execute([monitor_fmt($now - 7 * 86400)]);
    $latest = [];
    $people = $db->prepare('SELECT COUNT(DISTINCT user_hash) FROM monitor_error_people WHERE group_id = ? AND day >= ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $people->execute([$g['id'], monitor_fmt($now - 86400, 'Y-m-d')]);
        $latest[] = [
            'id' => (int) $g['id'], 'title' => mb_substr((string) $g['title'], 0, 140), 'kind' => $g['kind'], 'level' => $g['level'], 'source' => $g['source'],
            'status' => $g['status'], 'count' => (int) $g['occurrences'], 'people_24h' => (int) $people->fetchColumn(),
            'back' => (int) $g['regressions'] > 0, 'where' => mb_substr((string) $g['route'], 0, 80), 'last_seen' => monitor_ts($g['last_seen_at']),
        ];
    }

    $uptime = [];
    $stmt = $db->prepare("SELECT target, SUM(ok) AS up, COUNT(*) AS n, MAX(id) AS last_id FROM monitor_probes WHERE received_at >= ? AND target IN ('site', 'connect') GROUP BY target");
    $stmt->execute([monitor_fmt($now - 7 * 86400)]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last = $db->prepare('SELECT ok, ttfb_ms FROM monitor_probes WHERE id = ?');
        $last->execute([$r['last_id']]);
        $l = $last->fetch(PDO::FETCH_ASSOC) ?: [];
        $uptime[$r['target']] = ['pct_7d' => $r['n'] ? round(100 * $r['up'] / $r['n'], 2) : null, 'up_now' => (bool) ($l['ok'] ?? false), 'ttfb_ms' => isset($l['ttfb_ms']) ? (int) $l['ttfb_ms'] : null];
    }

    $release = monitor_release();

    return [
        'v'            => 1,
        'generated_at' => $now,
        'site'         => site_host(),
        'overall'      => $checks ? monitor_checks_worst($checks) : 'unknown',
        'checks'       => array_map(fn ($c) => ['key' => $c['key'], 'grp' => $c['grp'], 'label' => $c['label'], 'state' => $c['state'],
                                               'detail' => mb_substr((string) $c['detail'], 0, 200), 'since' => monitor_ts($c['since'])], $checks),
        'errors'       => ['open' => (int) ($counts['open_n'] ?? 0), 'new_24h' => (int) ($counts['new_24h'] ?? 0),
                           'last_hour' => $spark[23], 'spark_24h' => $spark],
        'latest'       => $latest,
        'uptime'       => $uptime,
        'speed'        => monitor_rum_summary($db, 7),
        'deploy'       => $release ? ['commit' => $release['commit'] ?? substr((string) ($release['sha'] ?? ''), 0, 7),
                                      'subject' => mb_substr((string) ($release['subject'] ?? ''), 0, 120), 'at' => $release['at'] ?? null] : null,
    ];
}

/** Write the snapshot (atomically) unless one was written in the last 5 seconds. */
function monitor_wall_publish(PDO $db, bool $force = false): bool
{
    if (!monitor_ready($db)) {
        return false;
    }
    $path = monitor_wall_path(monitor_wall_key($db));
    $t = @filemtime($path);
    if (!$force && $t !== false && $t > time() - 5) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, (string) json_encode(monitor_wall_snapshot($db), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        return false;
    }
    @chmod($tmp, 0644);

    return @rename($tmp, $path);
}
