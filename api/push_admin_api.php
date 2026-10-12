<?php
// /api/push_admin_api.php
/**
 * ============================================================================
 * ALERTS CENTRE — admin endpoint
 * ----------------------------------------------------------------------------
 * Delivery health and the broadcast composer behind modules/alerts/index.php.
 *
 * Kept apart from api/push_api.php deliberately. That file is member-facing
 * and every action in it is scoped to the caller's own user_id; this one can
 * reach the whole congregation, so it has its own gate and its own file
 * rather than a second branch inside a member endpoint.
 *
 * Auth: Super Admin or Resident Pastor, checked against the live roles table
 * through security_is_admin() — not $_SESSION['active_role'], which the member
 * can switch themselves.
 * ============================================================================
 */

require_once '../includes/db.php';
require_once '../includes/push_helpers.php';
require_once '../includes/security_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if (!security_is_admin($pdo)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'The Alerts Centre is restricted to Super Admins and the Resident Pastor.',
    ]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** One scalar out of a COUNT query, never fatal. */
function push_admin_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

try {
    switch ($action) {

        /**
         * Everything the panel paints in one request: is push working, how
         * many devices are out there, what is stuck, and what has failed.
         */
        case 'overview':
            $ready     = push_ready($pdo);
            $installed = push_table_exists($pdo, 'push_subscriptions');

            $data = [
                'ready'       => $ready['ok'],
                'reason'      => $ready['reason'],
                'installed'   => $installed,
                'inline'      => push_config_get($pdo, 'inline_dispatch', '1') === '1',
                'enabled'     => push_config_get($pdo, 'enabled', '1') === '1',
                'key_source'  => trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? '')) !== '' ? 'env' : 'database',
                'devices'     => 0,
                'members'     => 0,
                'retired'     => 0,
                'pending'     => 0,
                'in_flight'   => 0,
                'sent_today'  => 0,
                'failed_today'=> 0,
                'cursor'      => null,
                'latest'      => null,
                'platforms'   => [],
                'failures'    => [],
            ];

            if ($installed) {
                $data['devices'] = push_admin_count($pdo, "SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1");
                $data['members'] = push_admin_count($pdo, "SELECT COUNT(DISTINCT user_id) FROM push_subscriptions WHERE is_active = 1");
                $data['retired'] = push_admin_count($pdo, "SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 0");

                try {
                    $stmt = $pdo->query(
                        "SELECT platform, COUNT(*) AS devices
                           FROM push_subscriptions
                          WHERE is_active = 1
                          GROUP BY platform
                          ORDER BY devices DESC"
                    );
                    $data['platforms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $data['platforms'] = [];
                }
            }

            if (push_table_exists($pdo, 'push_queue')) {
                $data['pending']      = push_admin_count($pdo, "SELECT COUNT(*) FROM push_queue WHERE status = 'pending'");
                $data['in_flight']    = push_admin_count($pdo, "SELECT COUNT(*) FROM push_queue WHERE status = 'sending'");
                $data['sent_today']   = push_admin_count($pdo, "SELECT COUNT(*) FROM push_queue WHERE status = 'sent' AND sent_at >= CURDATE()");
                $data['failed_today'] = push_admin_count($pdo, "SELECT COUNT(*) FROM push_queue WHERE status = 'failed' AND sent_at >= CURDATE()");

                // Group the failures so one broken push service reads as one
                // line, not two hundred.
                try {
                    $stmt = $pdo->query(
                        "SELECT last_error, COUNT(*) AS hits, MAX(sent_at) AS last_seen
                           FROM push_queue
                          WHERE status = 'failed' AND last_error <> ''
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY last_error
                          ORDER BY hits DESC
                          LIMIT 8"
                    );
                    $data['failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $data['failures'] = [];
                }
            }

            if (push_table_exists($pdo, 'system_notifications')) {
                $data['cursor'] = push_config_get($pdo, 'fanout_cursor_id');
                $data['latest'] = push_admin_count($pdo, "SELECT COALESCE(MAX(id), 0) FROM system_notifications");
            }

            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        /** Audience options for the composer. */
        case 'audiences':
            $lookup = static function (PDO $pdo, string $sql): array {
                try {
                    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    return [];
                }
            };

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'departments' => $lookup($pdo, "SELECT id, name FROM departments ORDER BY name"),
                    'roles'       => $lookup($pdo, "SELECT id, role_name AS name FROM roles ORDER BY id"),
                    'regions'     => $lookup($pdo, "SELECT id, name FROM regions ORDER BY name"),
                ],
            ]);
            break;

        /** The two site-wide switches, as toggles rather than SQL. */
        case 'set_switch':
            $key   = (string) ($_POST['key'] ?? '');
            $value = (string) ($_POST['value'] ?? '') === '1' ? '1' : '0';

            if (!in_array($key, ['enabled', 'inline_dispatch'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown setting.']);
                break;
            }

            $saved = push_config_set($pdo, $key, $value);
            if ($saved) {
                // Same ledger the Security Centre writes to, so turning the
                // congregation's alerts off is as traceable as suspending an
                // account.
                security_log($pdo, 'push_switch', null,
                    $key . ' set to ' . ($value === '1' ? 'on' : 'off'), $user_id);
            }

            echo json_encode([
                'status'  => $saved ? 'success' : 'error',
                'message' => $saved ? 'Saved.' : 'Could not save that setting.',
            ]);
            break;

        /**
         * How many people a broadcast would actually reach, before it is sent.
         * Nobody should be able to message the whole congregation without
         * first being told how many that is.
         */
        case 'preview':
            $audience = push_admin_audience($pdo, $_POST);

            if ($audience['error'] !== '') {
                echo json_encode(['status' => 'error', 'message' => $audience['error']]);
                break;
            }

            $withDevices = 0;
            if ($audience['user_ids'] !== [] && push_table_exists($pdo, 'push_subscriptions')) {
                $in = implode(',', array_fill(0, count($audience['user_ids']), '?'));
                $withDevices = push_admin_count(
                    $pdo,
                    "SELECT COUNT(DISTINCT user_id) FROM push_subscriptions WHERE is_active = 1 AND user_id IN ($in)",
                    $audience['user_ids']
                );
            }

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'people'       => count($audience['user_ids']),
                    'with_devices' => $withDevices,
                    'label'        => $audience['label'],
                ],
            ]);
            break;

        /**
         * Send a broadcast.
         *
         * It is written as system_notifications rows, not pushed directly, so
         * it behaves like every other notification on the platform: it sits in
         * the member's bell whether or not they have a phone registered, the
         * normal fan-out picks it up, and a member's quiet hours and opt-out
         * are honoured without this endpoint having to know about them.
         */
        case 'broadcast':
            $title   = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $link    = trim((string) ($_POST['link'] ?? '/index.php'));

            if ($title === '' || mb_strlen($title) > 120) {
                echo json_encode(['status' => 'error', 'message' => 'Give it a title of 120 characters or fewer.']);
                break;
            }
            if ($message === '' || mb_strlen($message) > 400) {
                echo json_encode(['status' => 'error', 'message' => 'Give it a message of 400 characters or fewer.']);
                break;
            }
            // Same rule the push layer enforces: a notification can only ever
            // point at this site.
            if ($link === '' || !str_starts_with($link, '/') || str_starts_with($link, '//') || str_contains($link, '\\')) {
                $link = '/index.php';
            }

            $audience = push_admin_audience($pdo, $_POST);
            if ($audience['error'] !== '') {
                echo json_encode(['status' => 'error', 'message' => $audience['error']]);
                break;
            }
            if ($audience['user_ids'] === []) {
                echo json_encode(['status' => 'error', 'message' => 'That audience has nobody in it.']);
                break;
            }

            $insert = $pdo->prepare(
                "INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)"
            );

            $written = 0;
            foreach ($audience['user_ids'] as $recipientId) {
                try {
                    $insert->execute([$recipientId, mb_substr($title, 0, 190), $message, mb_substr($link, 0, 255)]);
                    $written++;
                } catch (Throwable $e) {
                    error_log('Push broadcast insert: ' . $e->getMessage());
                }
            }

            security_log($pdo, 'push_broadcast', null,
                $audience['label'] . ' — ' . $written . ' recipient(s): ' . mb_substr($title, 0, 120),
                $user_id);

            // The shutdown hook in includes/db.php delivers these the moment
            // this response is finished, so there is nothing to kick here.
            echo json_encode([
                'status'  => 'success',
                'message' => 'Sent to ' . $written . ' ' . ($written === 1 ? 'person' : 'people')
                    . '. Phones will buzz within seconds; everyone sees it in their bell.',
                'recipients' => $written,
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Throwable $e) {
    error_log('Push Admin API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not complete that request.']);
}

/**
 * Resolve the composer's audience to a list of user ids.
 *
 * @return array{user_ids:list<int>,label:string,error:string}
 */
function push_admin_audience(PDO $pdo, array $input): array
{
    $none = ['user_ids' => [], 'label' => '', 'error' => ''];
    $kind = (string) ($input['audience'] ?? '');
    $id   = (int) ($input['audience_id'] ?? 0);

    try {
        switch ($kind) {
            case 'everyone':
                $stmt = $pdo->query(
                    "SELECT id FROM users
                      WHERE COALESCE(account_status, 'active') = 'active'
                        AND COALESCE(attendance_status, '') <> 'Relocated'"
                );
                return ['user_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
                        'label' => 'Everyone', 'error' => ''];

            case 'with_devices':
                if (!push_table_exists($pdo, 'push_subscriptions')) {
                    return $none + ['error' => 'Push is not set up yet.'];
                }
                $stmt = $pdo->query(
                    "SELECT DISTINCT s.user_id
                       FROM push_subscriptions s
                       JOIN users u ON u.id = s.user_id
                      WHERE s.is_active = 1
                        AND COALESCE(u.account_status, 'active') = 'active'"
                );
                return ['user_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
                        'label' => 'Everyone with alerts on', 'error' => ''];

            case 'department':
                if ($id <= 0) return $none + ['error' => 'Pick a department.'];
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT ud.user_id
                       FROM user_departments ud
                      WHERE ud.department_id = ? AND ud.is_active = 1"
                );
                $stmt->execute([$id]);
                $name = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $name->execute([$id]);
                return ['user_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
                        'label' => 'Department: ' . ($name->fetchColumn() ?: $id), 'error' => ''];

            case 'role':
                if ($id <= 0) return $none + ['error' => 'Pick a role.'];
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT user_id FROM user_roles
                      WHERE role_id = ? AND COALESCE(is_frozen, 0) = 0"
                );
                $stmt->execute([$id]);
                $name = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
                $name->execute([$id]);
                return ['user_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
                        'label' => 'Role: ' . str_replace('_', ' ', (string) ($name->fetchColumn() ?: $id)), 'error' => ''];

            case 'region':
                if ($id <= 0) return $none + ['error' => 'Pick a region.'];
                $stmt = $pdo->prepare(
                    "SELECT id FROM users
                      WHERE region_id = ? AND COALESCE(attendance_status, '') <> 'Relocated'"
                );
                $stmt->execute([$id]);
                $name = $pdo->prepare("SELECT name FROM regions WHERE id = ?");
                $name->execute([$id]);
                return ['user_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
                        'label' => 'Region: ' . ($name->fetchColumn() ?: $id), 'error' => ''];

            default:
                return $none + ['error' => 'Choose who this is going to.'];
        }
    } catch (Throwable $e) {
        error_log('Push broadcast audience: ' . $e->getMessage());
        return $none + ['error' => 'Could not work out that audience.'];
    }
}
