<?php
// /includes/csrf.php
/**
 * One CSRF token per session, for every form and every state-changing call.
 *
 * Browser side, nothing to do: the shells print it in
 * <meta name="csrf-token">, and assets/ui/js/ui.js adds it as X-CSRF-Token to
 * every same-origin fetch / XMLHttpRequest (jQuery included) that is not a
 * GET, and as a hidden _csrf field to every same-origin POST form.
 *
 * Server side:
 *   csrf_require()  at the top of an endpoint (after db.php) refuses a write
 *                   without a valid token: 403 + {"status":"error","code":"CSRF"}.
 *                   Every endpoint of a rebuilt module calls it.
 *   csrf_observe()  runs from includes/db.php on every request. Until the
 *                   last module is rebuilt it only LOGS a signed-in write that
 *                   arrived without the token (once per endpoint per session),
 *                   so the switch to global enforcement can be made on
 *                   evidence. CSRF_ENFORCE_ALL = true turns it into
 *                   csrf_require() for everything not exempt.
 *
 * The older per-module tokens (sms_csrf, charis_csrf, bible_csrf …) keep
 * working beside this one until their modules move over.
 */

if (defined('HOD_CSRF_LOADED')) {
    return;
}
define('HOD_CSRF_LOADED', true);

const CSRF_SESSION_KEY = '_csrf';
const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';
const CSRF_FIELD = '_csrf';

/** Flip to true once every module sends the token (planned for the last UI PR). */
const CSRF_ENFORCE_ALL = false;

/**
 * Endpoints that are called without the signed-in browser's page: public
 * forms with their own protection, webhooks, and the service worker's push
 * re-subscription. Prefixes of the request path.
 */
const CSRF_EXEMPT_PREFIXES = [
    '/api/auth_api.php',
    '/api/setup_password_api.php',
    '/api/sms_webhook.php',
    '/api/special_events_public_api.php',
    '/api/special_events_display_api.php',
    '/api/special_events_live_api.php',
    '/api/csp_report.php',
    '/api/push_api.php',
    '/webhook/',
    // The error and health monitor: an emailed confirmation form (its own
    // secret link), browser beacons, and an HMAC-signed outside probe.
    '/monitor_confirm.php',
    '/api/monitor_intake.php',
    '/api/monitor_probe.php',
    // RFC 8058 one-click unsubscribe: mail providers POST it with no cookies
    // or token; the unguessable per-email ref in the URL is its credential.
    // The page's own buttons still check the token themselves.
    '/email/unsubscribe.php',
];

/** The session's token, created on first use. */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (empty($_SESSION[CSRF_SESSION_KEY]) || !is_string($_SESSION[CSRF_SESSION_KEY]) || strlen($_SESSION[CSRF_SESSION_KEY]) !== 64) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_SESSION_KEY];
}

/** True for methods that may change something. */
function csrf_is_write_request(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
}

/** The token the request carries: header first, then a form field, then a JSON body's _csrf. */
function csrf_request_token(): string
{
    $sent = (string) ($_SERVER[CSRF_HEADER] ?? '');
    if ($sent === '' && isset($_POST[CSRF_FIELD]) && is_string($_POST[CSRF_FIELD])) {
        $sent = $_POST[CSRF_FIELD];
    }
    if ($sent === '' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($body) && isset($body[CSRF_FIELD]) && is_string($body[CSRF_FIELD])) {
            $sent = $body[CSRF_FIELD];
        }
    }

    return $sent;
}

/**
 * Does the request come from this site? A browser always sends Origin on a
 * cross-site POST, and Sec-Fetch-Site on every request in current browsers.
 * Absent headers (old browsers, curl) are not proof of anything either way.
 */
function csrf_same_origin(): bool
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        return false;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        return $origin === '';
    }
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $port = parse_url($origin, PHP_URL_PORT);
    if ($port !== null) {
        $originHost .= ':' . $port;
    }

    return $originHost === strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
}

/** A write carrying the right token from this site. */
function csrf_valid(): bool
{
    $have = $_SESSION[CSRF_SESSION_KEY] ?? '';
    $sent = csrf_request_token();

    return is_string($have) && $have !== '' && $sent !== '' && hash_equals($have, $sent) && csrf_same_origin();
}

function csrf_is_exempt(string $path): bool
{
    // Browsers send CSP reports themselves, never with the page's token.
    if (defined('HOD_CSP_REPORT_PATH') && $path === HOD_CSP_REPORT_PATH) {
        return true;
    }
    foreach (CSRF_EXEMPT_PREFIXES as $prefix) {
        if (strncmp($path, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }

    return false;
}

/** Refuse a state-changing request that does not carry a valid token. */
function csrf_require(): void
{
    if (PHP_SAPI === 'cli' || !csrf_is_write_request() || csrf_valid()) {
        return;
    }

    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'status' => 'error',
        'code' => 'CSRF',
        'message' => 'This page has been open too long to save safely. Reload it and try again — nothing was changed.',
    ]);
    exit;
}

/**
 * Called by includes/db.php on every request. Logs (does not block) a
 * signed-in write without a valid token, once per endpoint per session.
 */
function csrf_observe(): void
{
    if (PHP_SAPI === 'cli' || !csrf_is_write_request() || empty($_SESSION['user_id'])) {
        return;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path === '' || csrf_is_exempt($path)) {
        return;
    }
    if (CSRF_ENFORCE_ALL) {
        csrf_require();
        return;
    }
    if (csrf_valid()) {
        return;
    }

    // Once per endpoint per session…
    $seen = (array) ($_SESSION['_csrf_observed'] ?? []);
    if (isset($seen[$path]) || count($seen) > 200) {
        return;
    }
    $seen[$path] = 1;
    $_SESSION['_csrf_observed'] = $seen;

    // …and once per endpoint per hour for the whole site, so a busy endpoint
    // used by a hundred members writes one line, not a hundred.
    $marker = sys_get_temp_dir() . '/hod_csrf_' . date('YmdH') . '_' . substr(sha1($path), 0, 16);
    $fh = @fopen($marker, 'x');
    if ($fh === false) {
        return;
    }
    fclose($fh);

    // The prefix is the contract with the error and health monitor, which
    // files these as quiet security notices, never as errors. Keep it.
    $why = csrf_request_token() === '' ? 'no token' : (csrf_same_origin() ? 'wrong token' : 'cross-site');
    error_log(sprintf('CSRF report-only: %s %s (%s)', $_SERVER['REQUEST_METHOD'] ?? '?', $path, $why));
}
