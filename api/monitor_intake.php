<?php
// /api/monitor_intake.php
/**
 * Where browsers report to the error monitor (docs/monitor.md, "Browser and CSP intake").
 *
 *   POST (from assets/js/monitor.js)                JSON beacon: errors, failed loads, speed
 *   POST ?k=csp&t=<token> (a page's CSP report-uri) a Content-Security-Policy violation report
 *
 * Signed (the day's intake token from the page) and rate-limited (60 a minute
 * per address, 3000 in all). Answers 204 at once and records after the response,
 * so a phone never waits on it. Deliberately does NOT load includes/db.php: a
 * report costs no database connection unless it carries an error, and speed
 * figures go to the spool, imported by the cron every minute.
 */

require_once __DIR__ . '/../includes/monitor/capture.php';
hod_monitor_install();
hod_monitor_context(['not_slow' => true]);
require_once __DIR__ . '/../includes/monitor/intake.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!monitor_intake_rate_ok((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')))) {
    http_response_code(429);
    header('Retry-After: 60');
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MONITOR_INTAKE_MAX_BYTES) {
    http_response_code(413);
    exit;
}
$body = (string) file_get_contents('php://input', false, null, 0, MONITOR_INTAKE_MAX_BYTES + 1);
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

// Who met it: the role only, from the session if the browser sent one. Read
// and closed at once, so a report never holds the member's session lock.
$session = null;
if (isset($_COOKIE[session_name()]) && session_status() === PHP_SESSION_NONE) {
    if (@session_start(['read_and_close' => true])) {
        $session = $_SESSION ?? null;
    }
}

$events = [];
$rum = null;
$isCsp = ($_GET['k'] ?? '') === 'csp' || str_contains($contentType, 'csp-report') || str_contains($contentType, 'reports+json');
if ($isCsp) {
    if (!monitor_intake_token_valid((string) ($_GET['t'] ?? ''))) {
        http_response_code(403);
        exit;
    }
    $events = monitor_intake_csp_events($body, $contentType, $_SERVER, $session);
} else {
    $r = monitor_intake_beacon($body, $_SERVER, $session);
    if (!$r['ok']) {
        http_response_code($r['status']);
        exit;
    }
    $events = $r['events'];
    $rum = $r['rum'];
}

http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    @litespeed_finish_request();
}
@ignore_user_abort(true);

if ($events) {
    monitor_intake_store_events($events);
}
if ($rum !== null) {
    require_once __DIR__ . '/../includes/monitor/rum.php';
    $row = monitor_rum_row((array) $rum['rum'], (string) $rum['page'], (string) $rum['device'], (int) $rum['at']);
    if ($row !== null) {
        monitor_spool_write('rum', [$row]);
    }
}
