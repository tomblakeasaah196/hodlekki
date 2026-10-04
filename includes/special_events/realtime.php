<?php
// /includes/special_events/realtime.php
//
// The realtime transport (guide §8.5.7).
//
// v1 publishes snapshots as static JSON files under /live/<public_id>/ and
// phones poll them (§8.5.1): on shared hosting an open connection costs a PHP
// entry process, so 150 phones on SSE would take the whole ERP down, while
// 150 conditional GETs of a small static file are served by LiteSpeed's event
// loop and mostly answered with 304.
//
// The driver interface exists so a push transport can be added later without
// touching a single caller. SeAblyDriver is deliberately a thin wrapper that
// ALWAYS writes the file as well, so polling stays the fallback and an Ably
// outage degrades to "the snapshot is up to a second old".

/**
 * One realtime transport.
 *
 * Implementations MUST be side-effect free on failure: a snapshot that cannot
 * be delivered is logged and swallowed, because the next publish (or the
 * client's next poll) will carry the same state again.
 */
interface SeRealtimeDriver
{
    /**
     * @param string $eventPublicId the event's 12-character public id
     * @param string $channel       'public' | 'room-<key>' | 'team-<key>' | 'lobby-<key>'
     * @param array  $envelope      {v, t, e, kind, data} (§8.5.2)
     */
    public function publish(string $eventPublicId, string $channel, array $envelope): void;
}

/**
 * The default driver: an atomic write of `live/<public_id>/<channel>.json`.
 *
 * Atomicity comes from write-to-temp-then-rename in the same directory, so a
 * phone polling mid-write never reads half a file (§8.5.6).
 */
final class SePollDriver implements SeRealtimeDriver
{
    public function publish(string $eventPublicId, string $channel, array $envelope): void
    {
        $path = se_live_path($eventPublicId, $channel);
        if ($path === null) {
            error_log('SE realtime: refusing to publish channel "' . $channel . '"');
            return;
        }

        se_write_file_atomic($path, se_json_encode($envelope));
    }
}

/**
 * Ably fan-out, specified in §8.5.7 and not enabled in v1.
 *
 * It delegates to SePollDriver first — the file is the source of truth and
 * the fallback — and then best-effort POSTs the same envelope to Ably. A
 * failure there is logged and ignored: the phones still have the file.
 */
final class SeAblyDriver implements SeRealtimeDriver
{
    public function __construct(private readonly SePollDriver $fallback = new SePollDriver()) {}

    public function publish(string $eventPublicId, string $channel, array $envelope): void
    {
        $this->fallback->publish($eventPublicId, $channel, $envelope);

        $key = (string) ($_ENV['ABLY_API_KEY'] ?? '');
        if ($key === '' || !function_exists('curl_init')) {
            return;
        }

        $url = 'https://rest.ably.io/channels/'
            . rawurlencode('se:' . $eventPublicId . ':' . $channel) . '/messages';

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERPWD        => $key,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => se_json_encode(['name' => 'snapshot', 'data' => $envelope]),
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                error_log('SE realtime/ably: HTTP ' . $status . ' for ' . $channel);
            }
        } catch (Throwable $e) {
            error_log('SE realtime/ably: ' . $e->getMessage());
        }
    }
}

/**
 * The driver for one event.
 *
 * `SE_REALTIME_DRIVER` in the environment is the deployment-wide default;
 * `settings.realtime.driver` lets one event opt in without a redeploy. Any
 * value we do not recognise falls back to polling, which always works.
 */
function se_realtime_driver(array $event): SeRealtimeDriver
{
    static $cache = [];

    $settings = se_event_settings($event);
    $name     = (string) se_settings_path($settings, 'realtime.driver', '');
    if ($name === '') {
        $name = (string) ($_ENV['SE_REALTIME_DRIVER'] ?? 'poll');
    }

    if (!isset($cache[$name])) {
        $cache[$name] = match ($name) {
            'ably'  => new SeAblyDriver(),
            default => new SePollDriver(),
        };
    }

    return $cache[$name];
}

// --------------------------------------------------------------------------
// Snapshot paths (§8.5.2, §23.1)
// --------------------------------------------------------------------------

/** The docroot's `live/` directory, with no trailing slash. */
function se_live_root(): string
{
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root === '' || !is_dir($root)) {
        $root = dirname(__DIR__, 2);
    }

    return rtrim($root, '/') . '/live';
}

/** A snapshot's web path, e.g. `/live/k3m9q2x7p1za/public.json`. */
function se_live_url(string $eventPublicId, string $channel): string
{
    return '/live/' . rawurlencode($eventPublicId) . '/' . rawurlencode($channel) . '.json';
}

/**
 * The absolute path of one snapshot file, or null when either part of the
 * name is not a shape we generate.
 *
 * Both components are validated rather than escaped: a display key comes from
 * the database, but this function is the last line between it and the file
 * system, and `..` must never be able to reach it.
 */
function se_live_path(string $eventPublicId, string $channel): ?string
{
    if (!preg_match('/^[0-9a-z]{1,16}$/i', $eventPublicId)) {
        return null;
    }
    if (!preg_match('/^(public|lobby|room|team)(-[A-Za-z0-9_-]{1,40})?$/', $channel)) {
        return null;
    }

    return se_live_root() . '/' . $eventPublicId . '/' . $channel . '.json';
}

/**
 * Delete every snapshot of one event (key rotation, archive, test reset).
 *
 * Only the files this module writes are removed, never the directory itself:
 * `live/.htaccess` protects the tree and must survive.
 */
function se_live_purge(string $eventPublicId, ?string $prefix = null): int
{
    if (!preg_match('/^[0-9a-z]{1,16}$/i', $eventPublicId)) {
        return 0;
    }

    $dir = se_live_root() . '/' . $eventPublicId;
    if (!is_dir($dir)) {
        return 0;
    }

    $removed = 0;
    foreach ((glob($dir . '/' . ($prefix ?? '') . '*.json') ?: []) as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}
