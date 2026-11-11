<?php
// /api/csp_report.php
/**
 * Receives Content-Security-Policy violation reports from rebuilt pages
 * (security_send_csp() in includes/security_headers.php) and writes one line
 * per distinct violation to the PHP error log. A violation on a rebuilt page
 * is a bug — something inline slipped back in, or a source is missing from
 * the page's $ui['csp'] — and this is how it gets seen.
 *
 * No database, no session, no output. Bounded: small bodies only, and at
 * most a few lines per minute per server process.
 */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 16384);
$data = json_decode($raw, true);
$report = is_array($data) ? ($data['csp-report'] ?? ($data[0]['body'] ?? null)) : null;

if (is_array($report)) {
    $clean = static function ($value, int $max = 200): string {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value);
        // Strip query strings: they can carry tokens or personal data.
        $value = preg_replace('/\?[^\s]*/', '?…', (string) $value);

        return mb_substr((string) $value, 0, $max);
    };

    $page = $clean($report['document-uri'] ?? ($report['documentURL'] ?? ''));
    $directive = $clean($report['effective-directive'] ?? ($report['effectiveDirective'] ?? ($report['violated-directive'] ?? '')), 60);
    $blocked = $clean($report['blocked-uri'] ?? ($report['blockedURL'] ?? ''));
    $where = $clean(($report['source-file'] ?? ($report['sourceFile'] ?? '')) . ':' . ($report['line-number'] ?? ($report['lineNumber'] ?? '')));

    // Simple per-process throttle: enough to see a regression, not enough to
    // fill the disk if a page loops.
    $bucket = sys_get_temp_dir() . '/hod_csp_' . date('YmdHi');
    $count = (int) @file_get_contents($bucket);
    if ($count < 30) {
        @file_put_contents($bucket, (string) ($count + 1));
        error_log(sprintf('CSP violation: %s blocked %s on %s (%s)', $directive, $blocked ?: 'inline', $page, $where));
    }
}

http_response_code(204);
