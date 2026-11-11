<?php
// /includes/security_headers.php
/**
 * Response headers every page and endpoint gets, sent from includes/db.php.
 * The root .htaccess is cPanel-managed and never deployed, so headers live
 * here, in PHP.
 *
 * The Content-Security-Policy is NOT sent here: the shells send it
 * (security_send_csp(), called from includes/ui/shell.php), because each
 * page knows what it needs. Special Events' /e/ pages send their own and
 * call header() after this file, which replaces anything set here.
 */

if (defined('HOD_SECURITY_HEADERS')) {
    return;
}
define('HOD_SECURITY_HEADERS', true);

/**
 * Where browsers send Content-Security-Policy violation reports. ONE place:
 * the error and health monitor owns what happens to them (api/csp_report.php
 * hands each report to includes/monitor/intake.php when that exists). Change
 * the path here and nowhere else.
 */
const HOD_CSP_REPORT_PATH = '/api/csp_report.php';

function security_send_baseline_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Only this site may frame its pages (the Special Events stage screens
    // are opened directly, never framed by another site).
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    // Microphone: the member portal, River of Life and public testimonies
    // record audio. Camera: QR check-in. Geolocation: address pickers.
    header('Permissions-Policy: accelerometer=(), camera=(self), display-capture=(), geolocation=(self), gyroscope=(), magnetometer=(), microphone=(self), payment=(), usb=(), fullscreen=(self)');
}

/**
 * The page's Content-Security-Policy.
 *
 * 'strict' (rebuilt pages): only this site's own scripts, styles, fonts and
 * images; inline script and style are refused except the nonced boot blocks
 * of includes/pwa_head.php. Violations are reported to /api/csp_report.php.
 * $extra adds sources per directive, e.g. ['connect-src' => ['https://api.geoapify.com']].
 *
 * 'legacy' (pages not rebuilt yet, which still use inline handlers): the
 * directives that cannot break them — no plugins, no <base> hijack, no
 * framing by other sites.
 */
function security_send_csp(string $mode, string $nonce, array $extra = []): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    if ($mode !== 'strict') {
        header("Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $directives = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'nonce-{$nonce}'"],
        'style-src' => ["'self'", "'nonce-{$nonce}'"],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'", 'blob:'],
        'worker-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'frame-src' => ["'none'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];
    foreach ($extra as $directive => $sources) {
        if (!isset($directives[$directive])) {
            continue;
        }
        if ($directives[$directive] === ["'none'"]) {
            $directives[$directive] = [];
        }
        foreach ((array) $sources as $source) {
            // A source is a scheme, a host or a quoted keyword; never let a
            // caller smuggle in a directive separator.
            $source = trim((string) $source);
            if ($source !== '' && !preg_match('/[;,\s]/', $source) && !in_array($source, $directives[$directive], true)) {
                $directives[$directive][] = $source;
            }
        }
    }

    $policy = [];
    foreach ($directives as $name => $sources) {
        $policy[] = $name . ' ' . implode(' ', $sources);
    }
    if ($https) {
        $policy[] = 'upgrade-insecure-requests';
    }
    // report-to (Reporting API) where supported, report-uri everywhere else.
    $policy[] = 'report-uri ' . HOD_CSP_REPORT_PATH;
    $policy[] = 'report-to hod-csp';

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) {
        header('Reporting-Endpoints: hod-csp="' . ($https ? 'https' : 'http') . '://' . $host . HOD_CSP_REPORT_PATH . '"');
    }
    header('Content-Security-Policy: ' . implode('; ', $policy));
}
