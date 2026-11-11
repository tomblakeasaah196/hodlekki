<?php
// /includes/monitor/keys.php
/**
 * The monitor's keys (docs/monitor.md, "Signing"). Small on purpose: every page
 * that carries the browser script asks for an intake token through here.
 *
 *   monitor_secret()                MONITOR_SECRET from .env, or a random key the monitor
 *                                   makes once in its own folder outside the docroot
 *                                   (secret.key, 0600), so nothing has to be set up.
 *                                   Never derived from the database password: every
 *                                   page prints an HMAC made with this key, which would
 *                                   let anyone test password guesses offline.
 *   monitor_hash($value)            a keyed hash, to count people without knowing who
 *   monitor_intake_token()          today's token for the browser intake: "20261111.<hmac>"
 *   monitor_intake_token_valid($t)  today's, or one of the two days before (a tab left open)
 *   monitor_probe_signature(...)    the outside probe's HMAC (MONITOR_PROBE_SECRET)
 *
 * Tokens are per day, not per person: they make the intake refuse anything that
 * did not come from a page this site served, without linking reports to anyone.
 */

require_once __DIR__ . '/capture.php';

if (!function_exists('monitor_secret')) {
    function monitor_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        $own = hod_monitor_env('MONITOR_SECRET');
        if (strlen($own) >= 16) {
            return $secret = $own;
        }
        $dir = hod_monitor_data_dir();
        $made = $dir !== null ? monitor_key_file($dir) : null;
        if ($made !== null) {
            return $secret = $made;
        }

        // No folder to keep a key in (it should not happen on the server): a key
        // for this process only. Tokens and stop links then last one request, so
        // Health says to set MONITOR_SECRET.
        return $secret = bin2hex(random_bytes(32));
    }
}

if (!function_exists('monitor_key_file')) {
    /**
     * The key kept in <dir>/secret.key: read it, or make it once. Made in a
     * temporary file and linked into place, so two first requests at the same
     * moment end up with the same key. Null if it can be neither read nor made.
     */
    function monitor_key_file(string $dir): ?string
    {
        $file = $dir . '/secret.key';
        $read = static function () use ($file): ?string {
            $v = is_file($file) ? trim((string) @file_get_contents($file)) : '';
            return preg_match('/^[0-9a-f]{64}$/', $v) ? $v : null;
        };
        if (($v = $read()) !== null) {
            return $v;
        }
        $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, bin2hex(random_bytes(32))) === false) {
            return null;
        }
        @chmod($tmp, 0600);
        if (!@link($tmp, $file) && !is_file($file)) {
            @rename($tmp, $file);   // a filesystem without hard links
        }
        @unlink($tmp);

        return $read();
    }
}

if (!function_exists('monitor_hash')) {
    function monitor_hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, monitor_secret()), 0, 16);
    }
}

if (!function_exists('monitor_intake_token')) {
    function monitor_intake_token(?int $ts = null): string
    {
        $day = gmdate('Ymd', $ts ?? (int) ($GLOBALS['monitor_test_now'] ?? time()));

        return $day . '.' . substr(hash_hmac('sha256', 'intake|' . $day, monitor_secret()), 0, 24);
    }
}

if (!function_exists('monitor_intake_token_valid')) {
    function monitor_intake_token_valid(string $token, ?int $now = null): bool
    {
        $now = $now ?? (int) ($GLOBALS['monitor_test_now'] ?? time());
        if (!preg_match('/^\d{8}\.[0-9a-f]{24}$/', $token)) {
            return false;
        }
        for ($back = 0; $back <= 2; $back++) {
            if (hash_equals(monitor_intake_token($now - $back * 86400), $token)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('monitor_probe_signature')) {
    /** "sha256=<hex>" over "<timestamp>\n<body>", keyed with MONITOR_PROBE_SECRET. */
    function monitor_probe_signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    }
}
