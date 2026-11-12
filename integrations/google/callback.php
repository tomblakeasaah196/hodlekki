<?php
// /integrations/google/callback.php
/**
 * Google's way back after Connect (docs/INTEGRATIONS.md): https://hodlekki.org/integrations/google/callback, the
 * redirect URI registered on the OAuth client. The state must match the one Connect put in this session (within
 * 15 minutes) and the code is exchanged with the PKCE verifier, so a callback this session did not start is refused.
 * Then back to the Integrations page with an outcome word (?google=connected|cancelled|state|…), never free text.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/integrations/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$back = '/modules/integrations/';
$go = static function (string $outcome) use ($back): never {
    header('Location: ' . $back . '?google=' . rawurlencode($outcome));
    exit;
};
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . auth_login_url_for_request());
    exit;
}
$flow = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);
if (!can('integrations', 'manage')) {
    $go('denied');
}
$state = (string) ($_GET['state'] ?? '');
if (!is_array($flow) || !is_string($flow['state'] ?? null) || $state === '' || !hash_equals($flow['state'], $state)
    || time() - (int) ($flow['at'] ?? 0) > 900) {
    $go('state');
}
if (isset($_GET['error'])) {
    $go('cancelled');
}
$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    $go('failed');
}

try {
    $email = Google::connect($code, (string) $flow['verifier'], (int) $_SESSION['user_id']);
} catch (GoogleError $e) {
    $go($e->reason);
} catch (Throwable $e) {
    error_log('Google connect: ' . get_class($e));
    $go('failed');
}
security_log($pdo, 'INTEGRATION_GOOGLE_CONNECTED', null, 'Connected Google as ' . ($email !== '' ? $email : 'an account without an email') . '.');
$go(Google::missingScopes() ? 'scopes' : 'connected');
