<?php
// /integrations/google/connect.php
/**
 * Connect Google (docs/INTEGRATIONS.md): the Integrations page's Connect button posts here (CSRF token in the form).
 * A random state and a PKCE verifier go into the session, then the admin is sent to Google's consent page, which
 * comes back to /integrations/google/callback. Only people who may manage Integrations get this far.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/integrations/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$back = '/modules/integrations/';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . auth_login_url_for_request());
    exit;
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: ' . $back);
    exit;
}
csrf_require();
if (!can('integrations', 'manage')) {
    header('Location: ' . $back . '?google=denied');
    exit;
}

$state = bin2hex(random_bytes(24));
[$verifier, $challenge] = Google::pkce();
$_SESSION['google_oauth'] = ['state' => $state, 'verifier' => $verifier, 'at' => time()];

try {
    $url = Google::authorizationUrl($state, $challenge);
} catch (GoogleError $e) {
    unset($_SESSION['google_oauth']);
    header('Location: ' . $back . '?google=' . rawurlencode($e->reason));
    exit;
}
header('Location: ' . $url, true, 303);
exit;
