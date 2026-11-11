<?php
// /includes/monitor/browser.php
/**
 * The browser half of the monitor: one <script> tag (docs/monitor.md, "Browser").
 *
 *   echo monitor_browser_tag();
 *
 * includes/pwa_head.php prints it, so every page with the app shell — the ERP,
 * the public pages and the Special Events pages under /e/ — reports its
 * JavaScript errors, the files it failed to load, and its real-user speed.
 *
 * It is a plain same-origin script with its settings in data-* attributes, so
 * it works under the strictest Content-Security-Policy (script-src 'self',
 * connect-src 'self'): no inline code, no nonce. It is not deferred on purpose:
 * loaded first in <head>, it sees errors thrown by the inline scripts below it.
 * About 6 KB, cached for a year by its ?v= version.
 *
 * MONITOR_ENABLED=0 or MONITOR_BROWSER=0 in .env leaves the tag out.
 * MONITOR_RUM_SAMPLE (0 to 1, default 1) is the share of page views that send
 * speed figures; errors are always sent.
 */

require_once __DIR__ . '/keys.php';

if (!function_exists('monitor_browser_tag')) {
    function monitor_browser_tag(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        try {
            if (!hod_monitor_enabled() || hod_monitor_env('MONITOR_BROWSER') === '0' || hod_monitor_env('MONITOR_ENABLED') === '0') {
                return '';
            }
            $file = dirname(__DIR__, 2) . '/assets/js/monitor.js';
            if (!is_file($file)) {
                return '';
            }
            $rate = hod_monitor_env('MONITOR_RUM_SAMPLE');
            $rate = is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 1.0;
            $printed = true;

            return '<script src="/assets/js/monitor.js?v=' . (int) filemtime($file) . '"'
                . ' data-t="' . htmlspecialchars(monitor_intake_token(), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-rum="' . rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.') . '"'
                . ' data-e="/api/monitor_intake.php"></script>' . "\n";
        } catch (Throwable $e) {
            return '';   // a page never breaks over its monitor
        }
    }
}

if (!function_exists('monitor_csp_report_uri')) {
    /** For a page's Content-Security-Policy: report-uri <this>. Signed like the beacons. */
    function monitor_csp_report_uri(): string
    {
        return '/api/monitor_intake.php?k=csp&t=' . rawurlencode(monitor_intake_token());
    }
}
