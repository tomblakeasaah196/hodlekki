<?php
// /includes/monitor/probe.php
/**
 * The outside view (docs/monitor.md, "Outside probe").
 *
 * A server cannot see itself the way members do: whether hodlekki.org answers
 * through Cloudflare, how long the first byte takes, whether certificates are
 * about to run out, whether the mail records are still published, whether the
 * origin lock really refuses a request that went around Cloudflare. So a
 * scheduled GitHub Actions workflow (.github/workflows/monitor-probe.yml)
 * checks all that from GitHub's machines and POSTs the result here, signed:
 *
 *   X-Monitor-Timestamp: <unix time>
 *   X-Monitor-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>\n<body>" with MONITOR_PROBE_SECRET>
 *
 * Refused: no secret configured, a timestamp more than 10 minutes off, a wrong
 * signature, or a signature seen before (a replay).
 */

require_once __DIR__ . '/store.php';

const MONITOR_PROBE_TARGETS = ['site', 'connect', 'tls', 'dns', 'origin_lock'];

/** Null when the request is genuine; otherwise [http status, reason]. */
function monitor_probe_verify(string $secret, string $timestamp, string $signature, string $body, int $now): ?array
{
    if (strlen($secret) < 32) {
        return [503, 'MONITOR_PROBE_SECRET is not set on the server'];
    }
    if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > 600) {
        return [401, 'stale or missing timestamp'];
    }
    if (!hash_equals(monitor_probe_signature($secret, $timestamp, $body), $signature)) {
        return [401, 'bad signature'];
    }

    return null;
}

/** Remember a signature; false if it was used before (a replay). */
function monitor_probe_fresh(PDO $db, string $signature): bool
{
    $seen = json_decode((string) monitor_state_get($db, 'probe_seen'), true);
    $seen = is_array($seen) ? $seen : [];
    $key = substr(sha1($signature), 0, 16);
    if (in_array($key, $seen, true)) {
        return false;
    }
    $seen[] = $key;
    monitor_state_set($db, 'probe_seen', (string) json_encode(array_slice($seen, -50)));

    return true;
}

/** Store one report. Returns how many targets were stored. */
function monitor_probe_store(PDO $db, array $p, int $now): int
{
    $runId = mb_substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) ($p['run_id'] ?? '')) ?: (string) $now, 0, 40);
    $probeAt = isset($p['at']) && is_numeric($p['at']) && abs($now - (int) $p['at']) < 3600 ? (int) $p['at'] : $now;
    $targets = is_array($p['targets'] ?? null) ? $p['targets'] : [];
    $stmt = $db->prepare('INSERT INTO monitor_probes (run_id, received_at, probe_at, target, ok, http_status, ttfb_ms, total_ms, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach (MONITOR_PROBE_TARGETS as $target) {
        $t = $targets[$target] ?? null;
        if (!is_array($t)) {
            continue;
        }
        $int = fn ($k, $max) => isset($t[$k]) && is_numeric($t[$k]) ? max(0, min($max, (int) $t[$k])) : null;
        $detail = [];
        foreach ($t as $k => $v) {
            if (in_array($k, ['ok', 'status', 'ttfb_ms', 'total_ms'], true) || !preg_match('/^[a-z_]{1,20}$/', (string) $k)) {
                continue;
            }
            if (is_bool($v) || is_int($v) || is_float($v)) {
                $detail[$k] = $v;
            } elseif (is_string($v)) {
                $detail[$k] = mb_substr(monitor_redact($v), 0, 200);
            }
        }
        $stmt->execute([
            $runId, monitor_fmt($now), monitor_fmt($probeAt), $target, !empty($t['ok']) ? 1 : 0,
            $int('status', 999), $int('ttfb_ms', 600000), $int('total_ms', 600000),
            $detail ? (string) json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
        ]);
        $n++;
    }
    $main = preg_match('/^[0-9a-f]{7,40}$/', (string) ($p['main_sha'] ?? '')) ? (string) $p['main_sha'] : null;
    $every = isset($p['every_min']) && is_numeric($p['every_min']) ? max(5, min(1440, (int) $p['every_min'])) : 60;
    monitor_state_set($db, 'probe_last', (string) json_encode(['at' => $now, 'run_id' => $runId, 'main_sha' => $main, 'every_min' => $every]));

    return $n;
}

/* ─────────── when GitHub's schedule skips the probe, the server asks for a run ─────────── */

const MONITOR_PROBE_KICK_AFTER = 70 * 60;   // no report for this long: ask GitHub to run the probe now
const MONITOR_PROBE_KICK_EVERY = 55 * 60;   // and ask at most once in this long

/**
 * Ask GitHub to run the outside probe now when no report has come for 70
 * minutes. GitHub's scheduler skips many hourly runs (on 12 Nov 2026 one of
 * twelve fired), but a run started through its API (workflow_dispatch) is not
 * skipped. The probe still runs on GitHub's machines, so it still looks at the
 * site from outside. cron/monitor.php calls this every minute.
 *
 * $token: MONITOR_PROBE_KICK_TOKEN from .env, a fine-grained GitHub token for
 * this repository only with "Actions: read and write"; nothing happens without
 * it. $post(url, headers, body) returns the HTTP status (tests pass a fake).
 *
 * @return string|null what happened, for the cron's line; null when nothing was due
 */
function monitor_probe_kick(PDO $db, int $now, string $token, string $repo, ?callable $post = null): ?string
{
    if ($token === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        return null;
    }
    $last = json_decode((string) monitor_state_get($db, 'probe_last'), true);
    if (is_array($last) && (int) ($last['at'] ?? 0) > $now - MONITOR_PROBE_KICK_AFTER) {
        return null;
    }
    $kick = json_decode((string) monitor_state_get($db, 'probe_kick'), true);
    if (is_array($kick) && (int) ($kick['at'] ?? 0) > $now - MONITOR_PROBE_KICK_EVERY) {
        return null;
    }
    $post ??= 'monitor_probe_kick_post';
    $status = (int) $post(
        'https://api.github.com/repos/' . $repo . '/actions/workflows/monitor-probe.yml/dispatches',
        [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: hodlekki-monitor',
            'Content-Type: application/json',
        ],
        '{"ref":"main"}'
    );
    monitor_state_set($db, 'probe_kick', (string) json_encode(['at' => $now, 'status' => $status]));

    return $status === 204
        ? 'asked GitHub to run the outside probe'
        : 'could not ask GitHub to run the outside probe (HTTP ' . $status . ')';
}

/** POST over HTTPS only; the HTTP status, or 0 when GitHub could not be reached. */
function monitor_probe_kick_post(string $url, array $headers, string $body): int
{
    if (!function_exists('curl_init')) {
        return 0;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status;
}
