<?php
// /api/integrations_api.php
/**
 * The Integrations page's buttons (modules/integrations/index.php, docs/INTEGRATIONS.md). POST JSON, CSRF checked,
 * only for people who may manage Integrations (can('integrations', 'manage')).
 *
 *   google_test        a harmless call to the calendar: proves the connection still works
 *   google_disconnect  revoke at Google (best effort) and forget the connection
 *
 * Connect itself is a form post to /integrations/google/connect (a redirect to Google, not JSON).
 */

require_once '../includes/db.php';
require_once '../includes/integrations/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;
use HOD\Integrations\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function integrations_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    integrations_out(401, ['status' => 'error', 'message' => 'Sign in again to continue.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    integrations_out(405, ['status' => 'error', 'message' => 'Use the buttons on the Integrations page.']);
}
csrf_require();
if (!can('integrations', 'manage')) {
    integrations_out(403, ['status' => 'error', 'message' => 'Only Integrations managers can do this.']);
}
if (!Settings::ready()) {
    integrations_out(503, ['status' => 'error', 'message' => 'Integrations are being set up. Try again after the next update.']);
}
$in = json_decode((string) file_get_contents('php://input'), true);
$action = is_array($in) ? (string) ($in['action'] ?? '') : '';
session_write_close();

try {
    switch ($action) {
        case 'google_test':
            $email = Google::test();
            integrations_out(200, ['status' => 'success', 'message' => 'Google answered' . ($email !== '' ? ' for ' . $email : '') . '.']);
            // no break: integrations_out() exits
        case 'google_disconnect':
            $email = (string) Settings::get(Google::EMAIL);
            Google::disconnect();
            security_log($pdo, 'INTEGRATION_GOOGLE_DISCONNECTED', null, 'Disconnected Google' . ($email !== '' ? ' (' . $email . ')' : '') . '.', $uid);
            integrations_out(200, ['status' => 'success', 'message' => 'Google disconnected.']);
            // no break
        default:
            integrations_out(400, ['status' => 'error', 'message' => 'Unknown request. Reload the page.']);
    }
} catch (GoogleError $e) {
    integrations_out(502, ['status' => 'error', 'code' => $e->reason, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Integrations API: ' . get_class($e));
    integrations_out(500, ['status' => 'error', 'message' => 'That did not work. Try again.']);
}
