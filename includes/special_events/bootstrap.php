<?php
// /includes/special_events/bootstrap.php
//
// The single entry point for the Special Events module. Every page and
// endpoint does:
//
//     require_once '../includes/db.php';                       // session + PDO + security gate
//     require_once '../includes/special_events/bootstrap.php';
//
// in that order: db.php owns the session, the PDO handle and
// security_enforce_session(), and nothing here duplicates that work.

// Idempotent: a page and its includes may both ask for the module.
if (defined('SE_BOOTSTRAPPED')) {
    return;
}
define('SE_BOOTSTRAPPED', true);

/** The module's own version, shown in Studio → Settings → Health. */
const SE_MODULE_VERSION = '1.0.0-pr1';

// Session: db.php starts it, but a CLI script (cron, tests) may not have one.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/ai.php';

// security_client_ip() lives in the platform's security helpers. The module
// only ever stores an HMAC of it (§9.1), never the address itself.
if (!function_exists('security_client_ip')) {
    require_once __DIR__ . '/../security_helpers.php';
}

// --------------------------------------------------------------------------
// The API envelope (§12.1)
// --------------------------------------------------------------------------

/**
 * Emit a success envelope and stop.
 *
 * House style (AGENTS.md): HTTP 200 for every handled outcome, with the
 * machine-readable result in `data`.
 */
function se_api_success(string $message = 'OK', array $data = []): never
{
    se_send_api_headers();
    echo se_json_encode(['status' => 'success', 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * Emit an error envelope and stop. `$code` is one of the §12.1 error codes,
 * which is what the client switches on; `$message` is always safe to show.
 */
function se_api_error(string $message, string $code = 'SERVER_ERROR', array $data = []): never
{
    se_send_api_headers();
    $body = ['status' => 'error', 'message' => $message, 'code' => $code];
    if ($data) {
        $body['data'] = $data;
    }
    echo se_json_encode($body);
    exit;
}

/**
 * Read and decode the JSON request body (§12.1).
 *
 * Rejects a body over 1 MB with 413 (uploads use multipart and never come
 * through here).
 */
function se_request_body(): array
{
    if (!empty($_FILES) || str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        // Multipart: the fields arrive in $_POST, and `payload` may carry JSON.
        $body = $_POST;
        if (isset($_POST['payload'])) {
            $decoded = json_decode((string) $_POST['payload'], true);
            if (is_array($decoded)) {
                $body = array_merge($body, $decoded);
            }
        }
        return $body;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > SE_MAX_JSON_BODY_BYTES) {
        http_response_code(413);
        se_api_error('That request was too large.', 'BAD_REQUEST');
    }

    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > SE_MAX_JSON_BODY_BYTES) {
        http_response_code(413);
        se_api_error('That request was too large.', 'BAD_REQUEST');
    }
    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        se_api_error('Malformed request.', 'BAD_REQUEST');
    }

    return $decoded;
}

/**
 * Turn an exception into the right envelope.
 *
 * Every endpoint wraps its dispatch in try/catch and calls this, so a PDO
 * error can never escape as a fatal and break the JSON contract (AGENTS.md).
 */
function se_api_fail(Throwable $e, string $context): never
{
    if ($e instanceof SeValidationException) {
        se_api_error($e->getMessage(), 'VALIDATION', ['fields' => $e->fields]);
    }
    if ($e instanceof SeStaleVersionException) {
        se_api_error($e->getMessage(), 'STALE_VERSION');
    }
    if ($e instanceof SeNotFoundException) {
        se_api_error($e->getMessage(), 'EVENT_NOT_FOUND');
    }
    if ($e instanceof SeRuleException) {
        se_api_error($e->getMessage(), $e->errorCode, $e->details);
    }
    if ($e instanceof SeAiException) {
        se_api_error($e->getMessage(), $e->errorCode);
    }

    // Anything else is unexpected: log it server-side, stay generic outward.
    error_log('SE ' . $context . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    se_api_error('Something went wrong on our side. Please try again.', 'SERVER_ERROR');
}

// --------------------------------------------------------------------------
// URLs
// --------------------------------------------------------------------------

/** The site's origin, e.g. https://hodlc.lpc.cm (no trailing slash). */
function se_site_origin(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    return ($https ? 'https://' : 'http://') . $host;
}

/** `/e/<slug>` plus an optional sub-path, as an absolute URL. */
function se_event_url(string $slug, string $path = '', bool $absolute = true): string
{
    $url = '/e/' . rawurlencode($slug);
    if ($path !== '') {
        $url .= '/' . ltrim($path, '/');
    }

    return $absolute ? se_site_origin() . $url : $url;
}

/** The Studio URL for one event and tab. */
function se_studio_url(?int $eventId = null, string $tab = 'overview'): string
{
    $url = '/modules/special_events/index.php';
    if ($eventId !== null) {
        $url .= '?event=' . $eventId;
    }

    return $url . '#tab=' . rawurlencode($tab);
}
