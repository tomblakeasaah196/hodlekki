<?php
// /api/csp_report.php
/**
 * Receives Content-Security-Policy violation reports from rebuilt (strict)
 * pages — the path is HOD_CSP_REPORT_PATH in includes/security_headers.php —
 * and hands them to the error and health monitor.
 *
 * A violation on a strict page is a bug: something inline slipped back in, or
 * a source is missing from the page's $ui['csp']. The monitor
 * (includes/monitor/intake.php, monitor_intake_csp()) redacts, dedupes and
 * groups them. Until the monitor is deployed, reports are counted and dropped:
 * they are deliberately NOT written to error_log, whose every line the
 * monitor's log scanner imports as a server error.
 *
 * Accepts both formats browsers send: application/csp-report (report-uri)
 * and application/reports+json (report-to). No database, no session, no
 * output. Bounded: 16 KB per body and at most 60 forwarded reports a minute
 * per server, so a page stuck in a loop cannot flood anything.
 */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($contentType, ['application/csp-report', 'application/reports+json', 'application/json'], true)) {
    http_response_code(415);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 16384);
if ($raw === '' || json_decode($raw, true) === null) {
    http_response_code(400);
    exit;
}

// Per-minute budget, shared by every PHP process through a temp file.
$bucket = sys_get_temp_dir() . '/hod_csp_' . date('YmdHi');
$count = (int) @file_get_contents($bucket);
if ($count >= 60) {
    http_response_code(204);
    exit;
}
@file_put_contents($bucket, (string) ($count + 1), LOCK_EX);

$intake = dirname(__DIR__) . '/includes/monitor/intake.php';
if (is_file($intake)) {
    try {
        require_once $intake;
        if (function_exists('monitor_intake_csp')) {
            monitor_intake_csp($raw, $contentType);
        }
    } catch (Throwable $e) {
        // The monitor records its own failures; a report must never 500.
    }
}

http_response_code(204);
