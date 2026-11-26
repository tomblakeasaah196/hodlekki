<?php
// /api/passkey_api.php
//
// Passkeys (includes/passkeys.php). POST, JSON answers, action =
//   login_options     anyone: a challenge for the sign-in page
//   login_finish      anyone: check the passkey, then sign in exactly like a
//                     password (includes/auth_signin.php), staying signed in
//   register_options  signed in: options for navigator.credentials.create()
//   register_finish   signed in: keep the new passkey
//   list              signed in: the person's own passkeys
//   delete            signed in: remove one of them
// Writes by a signed-in person carry the CSRF token (assets/ui/js/ui.js adds
// it); the two sign-in actions are bound to the session by their challenge.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/passkeys.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function passkey_api_out(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function passkey_api_fail(string $code, string $message, int $status = 200): void
{
    passkey_api_out(['status' => 'error', 'code' => $code, 'message' => $message], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    passkey_api_fail('METHOD', 'Invalid request method.', 405);
}
if (!passkeys_ready($pdo)) {
    passkey_api_fail('NOT_READY', 'Passkeys open after the next update.');
}

$body = $_POST;
if ($body === [] && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $body = (array) json_decode((string) file_get_contents('php://input'), true);
}
$action = (string) ($body['action'] ?? '');
$cred = (array) ($body['credential'] ?? []);

try {
    // ------------------------------------------------------------- sign-in
    if ($action === 'login_options') {
        passkey_api_out(['status' => 'success', 'options' => passkey_login_options()]);
    }

    if ($action === 'login_finish') {
        $check = passkey_login_finish($pdo, $cred);
        if (!$check['ok']) {
            if (($check['code'] ?? '') === 'UNKNOWN') {
                // Removed in Profile, or never saved: the device should stop offering it.
                passkey_api_fail('UNKNOWN', 'This passkey is no longer on your account. Sign in with your password.');
            }
            error_log('[passkey] sign-in refused: ' . ($check['why'] ?? $check['code'] ?? 'unknown'));
            passkey_api_fail('NOT_ACCEPTED', 'That did not work. Try again, or use your password.');
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$check['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            passkey_api_fail('UNKNOWN', 'This passkey is no longer on your account. Sign in with your password.');
        }
        require_once __DIR__ . '/../includes/auth_signin.php';
        $answer = auth_finish_signin($pdo, $user, 'passkey', true, security_schema_ready($pdo), 'passkey');
        passkey_api_out($answer);
    }

    // ---------------------------------------------- the signed-in person's own
    if (empty($_SESSION['user_id'])) {
        passkey_api_fail('SIGNED_OUT', 'Your session has ended. Sign in again.', 401);
    }
    $userId = (int) $_SESSION['user_id'];
    csrf_require();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];

    if ($action === 'register_options') {
        passkey_api_out(['status' => 'success', 'options' => passkey_register_options($pdo, $me)]);
    }

    if ($action === 'register_finish') {
        $done = passkey_register_finish($pdo, $me, $cred, security_user_agent(), security_client_ip());
        if (!$done['ok']) {
            if ($done['code'] === 'ALREADY') {
                passkey_api_out(['status' => 'success', 'already' => true]);
            }
            error_log('[passkey] not saved for user ' . $userId . ': ' . ($done['why'] ?? $done['code']));
            $messages = [
                'UNSUPPORTED' => 'This device made a kind of passkey we cannot use. Try another device.',
                'TOO_MANY'    => 'You have 20 passkeys. Remove one in Profile first.',
            ];
            passkey_api_fail($done['code'], $messages[$done['code']] ?? 'That did not work. Try again.');
        }
        if (function_exists('security_log')) {
            security_log($pdo, 'passkey_added', $userId, 'Passkey added on ' . $done['label'] . '.');
        }
        unset($_SESSION['passkey_offer']);
        passkey_api_out(['status' => 'success', 'label' => $done['label']]);
    }

    if ($action === 'list') {
        passkey_api_out(['status' => 'success', 'passkeys' => passkey_list($pdo, $userId)]);
    }

    if ($action === 'delete') {
        if (!passkey_delete($pdo, $userId, (int) ($body['id'] ?? 0))) {
            passkey_api_fail('NOT_FOUND', 'That passkey is already gone.');
        }
        if (function_exists('security_log')) {
            security_log($pdo, 'passkey_removed', $userId, 'Passkey removed.');
        }
        passkey_api_out(['status' => 'success', 'passkeys' => passkey_list($pdo, $userId)]);
    }

    passkey_api_fail('ACTION', 'Unknown action.', 400);
} catch (PDOException $e) {
    error_log('[passkey] ' . $e->getMessage());
    passkey_api_fail('SERVER', 'A system error occurred. Please try again later.', 500);
}
