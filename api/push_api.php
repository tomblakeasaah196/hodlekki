<?php
// /api/push_api.php
/**
 * ============================================================================
 * WEB PUSH — subscription and preference endpoint
 * ----------------------------------------------------------------------------
 * Everything assets/js/pwa.js needs: the VAPID public key to subscribe with,
 * somewhere to register the resulting endpoint, the member's own device list,
 * quiet-hours preferences, and a test send.
 *
 * Auth: signed-in members only, and every action is scoped to the caller's
 * own user_id. There is no action here that can reach another member's
 * devices — a broadcast is a system_notifications row, which the worker fans
 * out on its own.
 * ============================================================================
 */

require_once '../includes/db.php';
require_once '../includes/push_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

/** The service worker posts JSON; the rest of the app posts form fields. */
function push_input(string $key, $default = null)
{
    static $json = null;
    if ($json === null) {
        $json = [];
        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
    }

    return $_POST[$key] ?? $json[$key] ?? $_GET[$key] ?? $default;
}

try {
    switch ($action) {

        /**
         * Everything the client needs to decide what to show: whether push is
         * even possible on this deployment, the key to subscribe with, and
         * where this member currently stands.
         */
        case 'config':
            $ready = push_ready($pdo);
            $vapid = $ready['ok'] ? push_vapid($pdo) : null;

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'available'   => $ready['ok'],
                    'reason'      => $ready['reason'],
                    'public_key'  => $vapid['public'] ?? '',
                    'devices'     => count(push_user_subscriptions($pdo, $user_id)),
                    'unread'      => push_unread_count($pdo, $user_id),
                    'preferences' => push_user_prefs($pdo, $user_id),
                ],
            ]);
            break;

        /** Register (or refresh) this browser's push subscription. */
        case 'subscribe':
            $result = push_save_subscription(
                $pdo,
                $user_id,
                (string) push_input('endpoint', ''),
                (string) push_input('p256dh', ''),
                (string) push_input('auth', ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                (string) push_input('content_encoding', 'aes128gcm')
            );

            if ($result['status'] === 'success') {
                // A member who just turned alerts on clearly wants them, even
                // if they switched the master toggle off on an older device.
                $prefs = push_user_prefs($pdo, $user_id);
                if (empty($prefs['push_enabled'])) {
                    push_save_prefs($pdo, $user_id, ['push_enabled' => 1] + $prefs);
                }
            }

            echo json_encode($result + ['devices' => count(push_user_subscriptions($pdo, $user_id))]);
            break;

        /** Forget this browser. Called when a member turns alerts off. */
        case 'unsubscribe':
            $removed = push_delete_subscription($pdo, $user_id, (string) push_input('endpoint', ''));
            echo json_encode([
                'status'  => 'success',
                'message' => $removed ? 'This device will no longer receive alerts.' : 'This device was not registered.',
                'devices' => count(push_user_subscriptions($pdo, $user_id)),
            ]);
            break;

        /** The member's own registered devices, for the settings sheet. */
        case 'devices':
            $devices = [];
            foreach (push_user_subscriptions($pdo, $user_id) as $subscription) {
                $devices[] = [
                    'id'           => (int) $subscription['id'],
                    'label'        => $subscription['device_label'] !== '' ? $subscription['device_label'] : 'Unnamed device',
                    'platform'     => $subscription['platform'],
                    'added'        => $subscription['created_at'],
                    'last_success' => $subscription['last_success_at'],
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $devices]);
            break;

        /** Remove one device from the list (not necessarily the current one). */
        case 'forget_device':
            $id = (int) push_input('id', 0);
            // Scoped to the caller: an id belonging to somebody else matches
            // no row and silently does nothing.
            if ($id > 0 && push_table_exists($pdo, 'push_subscriptions')) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?")
                    ->execute([$id, $user_id]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Device removed.']);
            break;

        /** Quiet hours and the member's own master switch. */
        case 'save_prefs':
            $saved = push_save_prefs($pdo, $user_id, [
                'push_enabled' => (string) push_input('push_enabled', '1') === '1',
                'quiet_from'   => push_input('quiet_from', null),
                'quiet_to'     => push_input('quiet_to', null),
            ]);

            echo json_encode([
                'status'  => $saved ? 'success' : 'error',
                'message' => $saved ? 'Alert settings saved.' : 'Could not save alert settings.',
                'data'    => push_user_prefs($pdo, $user_id),
            ]);
            break;

        /**
         * Send the caller a push on the spot. This is how a member confirms
         * for themselves that alerts really work on this phone — the single
         * most common support question for any push feature.
         */
        case 'test':
            $result = push_send_to_user($pdo, $user_id, [
                'title'   => 'HOD Lekki',
                'body'    => 'Alerts are working on this device. You will not miss anything important.',
                'url'     => '/index.php',
                'tag'     => 'hod-test',
                'urgency' => 'high',
            ]);

            $messages = [
                'NO_DEVICES'        => 'No device is registered yet. Turn alerts on first.',
                'FEATURE_NOT_READY' => 'Push notifications are not set up on the server yet.',
                'NO_VAPID_KEYS'     => 'Push notifications are not set up on the server yet.',
                'DISABLED'          => 'Push notifications are switched off for the whole site.',
                'NO_OPENSSL_ECDH'   => 'This server\'s PHP build cannot encrypt push messages.',
            ];

            echo json_encode([
                'status'  => $result['status'],
                'message' => $messages[$result['message']] ?? $result['message'],
                'sent'    => $result['sent'],
                'devices' => $result['devices'],
            ]);
            break;

        /** Unread count, so the app icon badge stays honest between pushes. */
        case 'badge':
            echo json_encode(['status' => 'success', 'count' => push_unread_count($pdo, $user_id)]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Throwable $e) {
    error_log('Push API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not complete that request.']);
}
