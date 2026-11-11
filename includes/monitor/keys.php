<?php
// /includes/monitor/keys.php
/**
 * The monitor's keys (docs/monitor.md, "Signing"). Small on purpose: every page
 * that carries the browser script asks for an intake token through here.
 *
 *   monitor_secret()                MONITOR_SECRET from .env, or one derived from the
 *                                   database settings (one-way, labelled) so nothing
 *                                   has to be set up first
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

        return $secret = hash_hmac(
            'sha256',
            'hod-monitor/v1|' . hod_monitor_env('DB_NAME'),
            hod_monitor_env('DB_PASS') . '|' . hod_monitor_env('DB_USER') . '|hod'
        );
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
