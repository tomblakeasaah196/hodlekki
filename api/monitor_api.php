<?php
// /api/monitor_api.php
/**
 * The error + health monitor's JSON API (docs/monitor.md, "API"), for the
 * monitor's screens and the live wall.
 *
 * Access: monitor_user_can() — Super_Admin from the live roles table today,
 * can('monitor.view' / 'monitor.manage') once the permissions module lands.
 * Writes need POST and the CSRF token: the platform's csrf_require() when it
 * exists, else this module's own X-Monitor-CSRF header (token from ?action=summary).
 *
 *   GET  summary | errors | error&id= | brief&id= | export | checks | speed | uptime
 *        | deploys | recipients | settings | wall
 *   POST set_status | add_note | scan_now | run_checks | recipient_add | recipient_remove
 *        | settings_save | wall_rotate | test_alert
 */

require_once '../includes/db.php';
require_once '../includes/monitor/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function monitor_api_reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function monitor_api_error(string $message, int $code = 400): never
{
    monitor_api_reply(['status' => 'error', 'message' => $message], $code);
}

/** a***@gmail.com, for the audit trail. */
function monitor_mask_email(string $email): string
{
    $email = strtolower(trim($email));
    $at = strpos($email, '@');

    return $at === false ? '(invalid)' : substr($email, 0, 1) . '***' . substr($email, $at);
}

if (!isset($_SESSION['user_id'])) {
    monitor_api_error('Unauthorized', 401);
}
if (!monitor_user_can($pdo, 'monitor.view')) {
    monitor_api_error('The system monitor is for Super Admins.', 403);
}
if (!monitor_ready($pdo)) {
    monitor_api_error('FEATURE_NOT_READY', 503);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'summary');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$writes = ['set_status', 'add_note', 'scan_now', 'run_checks', 'recipient_add', 'recipient_remove', 'settings_save', 'wall_rotate', 'test_alert'];
$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['monitor_csrf'])) {
    $_SESSION['monitor_csrf'] = bin2hex(random_bytes(16));
}
if (in_array($action, $writes, true)) {
    if (!$isPost) {
        monitor_api_error('Use POST for this.', 405);
    }
    if (function_exists('csrf_require')) {
        csrf_require();
    } elseif (!hash_equals((string) $_SESSION['monitor_csrf'], (string) ($_SERVER['HTTP_X_MONITOR_CSRF'] ?? ($_POST['csrf'] ?? '')))) {
        monitor_api_error('This page has expired. Reload it and try again.', 419);
    }
    if (in_array($action, ['recipient_add', 'recipient_remove', 'settings_save', 'wall_rotate', 'test_alert'], true)
        && !monitor_user_can($pdo, 'monitor.manage')) {
        monitor_api_error('Only an admin who manages the monitor can change this.', 403);
    }
}
$input = $isPost ? (json_decode((string) file_get_contents('php://input'), true) ?: $_POST) : $_GET;

try {
    switch ($action) {
        case 'summary': {
            $snap = monitor_wall_snapshot($pdo);
            $snap['recipients'] = count(monitor_recipients_active($pdo));
            $snap['csrf'] = $_SESSION['monitor_csrf'];
            $snap['can_manage'] = monitor_user_can($pdo, 'monitor.manage');
            monitor_api_reply(['status' => 'success', 'data' => $snap]);
        }

        case 'errors': {
            $where = [];
            $args = [];
            $status = (string) ($input['status'] ?? 'open');
            if ($status === 'open') {
                $where[] = "status IN ('new', 'investigating')";
            } elseif (in_array($status, MONITOR_STATUSES, true)) {
                $where[] = 'status = ?';
                $args[] = $status;
            }
            $kind = (string) ($input['kind'] ?? '');
            if (in_array($kind, ['php', 'exception', 'js', 'csp', 'log', 'slow'], true)) {
                $where[] = 'kind = ?';
                $args[] = $kind;
            } elseif ($kind === 'server') {
                $where[] = "kind IN ('php', 'exception', 'log')";
            }
            if (empty($input['quiet'])) {
                $where[] = "level NOT IN ('notice', 'deprecated')";
            }
            $q = trim((string) ($input['q'] ?? ''));
            if ($q !== '') {
                $where[] = "(title LIKE ? ESCAPE '!' OR file LIKE ? ESCAPE '!' OR route LIKE ? ESCAPE '!')";
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 80)) . '%';
                array_push($args, $like, $like, $like);
            }
            $order = match ((string) ($input['sort'] ?? 'last')) {
                'count' => 'occurrences DESC',
                'first' => 'first_seen_at DESC',
                default => 'last_seen_at DESC',
            };
            $per = max(10, min(100, (int) ($input['per'] ?? 50)));
            $page = max(1, (int) ($input['page'] ?? 1));
            $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM monitor_error_groups' . $sqlWhere);
            $stmt->execute($args);
            $total = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT id, kind, level, source, title, file, line, route, status, first_seen_at, last_seen_at, occurrences, regressions
                                     FROM monitor_error_groups' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $people = $pdo->prepare('SELECT COUNT(DISTINCT user_hash) FROM monitor_error_people WHERE group_id = ? AND day >= ?');
            $day = $pdo->prepare('SELECT COALESCE(SUM(n), 0) FROM monitor_error_hourly WHERE group_id = ? AND hour >= ?');
            foreach ($rows as &$r) {
                $people->execute([$r['id'], monitor_fmt(monitor_time() - 7 * 86400, 'Y-m-d')]);
                $r['people_7d'] = (int) $people->fetchColumn();
                $day->execute([$r['id'], monitor_fmt(monitor_time() - 86400, 'Y-m-d H:00:00')]);
                $r['last_24h'] = (int) $day->fetchColumn();
                $r['source_label'] = monitor_kind_label($r);
            }
            unset($r);
            monitor_api_reply(['status' => 'success', 'data' => ['total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows]]);
        }

        case 'error': {
            $id = (int) ($input['id'] ?? 0);
            $g = monitor_group($pdo, $id);
            if ($g === null) {
                monitor_api_error('That problem is not in the monitor (it may have been cleared after 90 days).', 404);
            }
            $stmt = $pdo->prepare('SELECT id, ref, occurred_at, source, method, path, host, role, release_id, device, browser, duration_ms, memory_mb, message, trace, context
                                     FROM monitor_error_events WHERE group_id = ? ORDER BY id DESC LIMIT 20');
            $stmt->execute([$id]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('SELECT n.id, n.body, n.created_at, n.user_id, u.first_name FROM monitor_error_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.group_id = ? ORDER BY n.id');
            try {
                $stmt->execute([$id]);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare('SELECT id, body, created_at, user_id FROM monitor_error_notes WHERE group_id = ? ORDER BY id');
                $stmt->execute([$id]);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $g['kind_label'] = monitor_kind_label($g);
            monitor_api_reply(['status' => 'success', 'data' => [
                'group' => $g, 'stats' => monitor_group_stats($pdo, $id), 'events' => $events, 'notes' => $notes, 'hint' => monitor_explain($g),
            ]]);
        }

        case 'brief': {
            $md = monitor_brief($pdo, (int) ($input['id'] ?? 0));
            if ($md === null) {
                monitor_api_error('That problem is not in the monitor.', 404);
            }
            monitor_api_reply(['status' => 'success', 'data' => ['markdown' => $md]]);
        }

        case 'export': {
            $md = monitor_export_open($pdo, 40, !empty($input['quiet']));
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="hodlekki-open-errors-' . monitor_fmt(null, 'Y-m-d-Hi') . '.md"');
            echo $md;
            exit;
        }

        case 'set_status': {
            $id = (int) ($input['id'] ?? 0);
            $status = (string) ($input['status'] ?? '');
            if (!in_array($status, MONITOR_STATUSES, true)) {
                monitor_api_error('Unknown status.');
            }
            $g = monitor_group($pdo, $id);
            if ($g === null) {
                monitor_api_error('That problem is not in the monitor.', 404);
            }
            $now = monitor_fmt();
            if ($status === 'fixed') {
                $pdo->prepare('UPDATE monitor_error_groups SET status = ?, status_changed_at = ?, status_by = ?, fixed_at = ?, fixed_release = ? WHERE id = ?')
                    ->execute([$status, $now, $userId, $now, monitor_release_id(), $id]);
            } else {
                $pdo->prepare('UPDATE monitor_error_groups SET status = ?, status_changed_at = ?, status_by = ?' . ($status === 'new' ? ', fixed_at = NULL' : '') . ' WHERE id = ?')
                    ->execute([$status, $now, $userId, $id]);
            }
            $label = ['new' => 'Reopened', 'investigating' => 'Marked as being investigated', 'fixed' => 'Marked fixed', 'ignored' => 'Ignored'][$status];
            $note = trim((string) ($input['note'] ?? ''));
            monitor_add_note($pdo, $id, $userId, $label . ($status === 'fixed' && monitor_release_id() ? ' (release ' . monitor_release_id() . ')' : '') . ($note !== '' ? ': ' . mb_substr($note, 0, 2000) : '.'));
            monitor_api_reply(['status' => 'success', 'message' => $label . '.']);
        }

        case 'add_note': {
            $id = (int) ($input['id'] ?? 0);
            $body = trim((string) ($input['body'] ?? ''));
            if ($body === '' || monitor_group($pdo, $id) === null) {
                monitor_api_error('Write the note first.');
            }
            monitor_add_note($pdo, $id, $userId, mb_substr($body, 0, 5000));
            monitor_api_reply(['status' => 'success', 'message' => 'Note saved.']);
        }

        case 'scan_now': {
            $last = (int) monitor_state_get($pdo, 'scan_now_at', '0');
            if ($last > monitor_time() - 30) {
                monitor_api_error('A scan ran less than 30 seconds ago.', 429);
            }
            monitor_state_set($pdo, 'scan_now_at', (string) monitor_time());
            hod_monitor_context(['not_slow' => true]);
            $imported = monitor_spool_import('events', fn (array $record) => monitor_store_all($pdo, [$record]));
            $scan = monitor_log_scan_all($pdo);
            monitor_wall_publish($pdo, true);
            monitor_api_reply(['status' => 'success', 'message' => 'Read ' . $scan['files'] . ' log file(s): ' . $scan['recorded'] . ' new entr' . ($scan['recorded'] === 1 ? 'y' : 'ies') . ' recorded' . ($imported ? ', ' . $imported . ' from the spool' : '') . '.', 'data' => $scan]);
        }

        case 'checks':
            monitor_api_reply(['status' => 'success', 'data' => monitor_checks_current($pdo)]);

        case 'run_checks': {
            $last = (int) monitor_state_get($pdo, 'run_checks_at', '0');
            if ($last > monitor_time() - 30) {
                monitor_api_error('The checks ran less than 30 seconds ago.', 429);
            }
            monitor_state_set($pdo, 'run_checks_at', (string) monitor_time());
            hod_monitor_context(['not_slow' => true]);
            $checks = monitor_checks_run($pdo, $pdo, false);
            monitor_wall_publish($pdo, true);
            monitor_api_reply(['status' => 'success', 'data' => monitor_checks_current($pdo), 'message' => 'Checked ' . count($checks) . ' things.']);
        }

        case 'speed': {
            $days = in_array((int) ($input['days'] ?? 7), [1, 7, 28], true) ? (int) $input['days'] : 7;
            $device = in_array($input['device'] ?? 'phone', ['phone', 'tablet', 'desktop'], true) ? (string) $input['device'] : 'phone';
            $stmt = $pdo->prepare("SELECT day, metric, n, p75 FROM monitor_rum_daily WHERE page = '*' AND device = ? AND day >= ? ORDER BY day");
            $stmt->execute([$device, monitor_fmt(monitor_time() - $days * 86400, 'Y-m-d')]);
            monitor_api_reply(['status' => 'success', 'data' => [
                'summary' => monitor_rum_summary($pdo, 7), 'days' => $days, 'device' => $device,
                'daily' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'pages' => monitor_rum_pages($pdo, $days, $device),
                'limits' => monitor_vital_limits(),
            ]]);
        }

        case 'uptime': {
            $days = max(1, min(90, (int) ($input['days'] ?? 7)));
            $stmt = $pdo->prepare("SELECT target, SUBSTR(received_at, 1, 10) AS day, SUM(ok) AS up, COUNT(*) AS n, AVG(ttfb_ms) AS ttfb
                                     FROM monitor_probes WHERE received_at >= ? AND target IN ('site', 'connect') GROUP BY target, SUBSTR(received_at, 1, 10) ORDER BY day");
            $stmt->execute([monitor_fmt(monitor_time() - $days * 86400)]);
            $outside = monitor_facts_outside($pdo);
            monitor_api_reply(['status' => 'success', 'data' => ['days' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'latest' => $outside['latest'], 'probe' => $outside['meta']]]);
        }

        case 'deploys':
            monitor_api_reply(['status' => 'success', 'data' => monitor_recent_deploys(20)]);

        case 'recipients':
            monitor_api_reply(['status' => 'success', 'data' => monitor_recipients_list($pdo)]);

        case 'recipient_add': {
            $r = monitor_recipient_add($pdo, (string) ($input['email'] ?? ''), $userId);
            security_log($pdo, 'monitor.recipient_add', null, 'Alert email added (waiting for confirmation): ' . monitor_mask_email((string) ($input['email'] ?? '')));
            monitor_api_reply($r['ok']
                ? ['status' => 'success', 'message' => 'A confirmation link is on its way. Nothing is sent to that address until it is clicked.']
                : ['status' => 'error', 'message' => $r['error']], $r['ok'] ? 200 : 422);
        }

        case 'recipient_remove': {
            $ok = monitor_recipient_remove($pdo, (int) ($input['id'] ?? 0));
            if ($ok) {
                security_log($pdo, 'monitor.recipient_remove', null, 'Alert email removed (#' . (int) ($input['id'] ?? 0) . ')');
            }
            monitor_api_reply(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Removed. That address gets nothing more.' : 'Already removed.']);
        }

        case 'settings':
            monitor_api_reply(['status' => 'success', 'data' => [
                'digest_hour' => (int) monitor_state_get($pdo, 'digest_hour', '8'),
                'probe_configured' => strlen(hod_monitor_env('MONITOR_PROBE_SECRET')) >= 32,
                'own_secret' => strlen(hod_monitor_env('MONITOR_SECRET')) >= 16,
                'prepend' => (json_decode((string) @file_get_contents((hod_monitor_data_dir() ?? '') . '/web.json'), true)['prepend'] ?? null),
            ]]);

        case 'settings_save': {
            $hour = (int) ($input['digest_hour'] ?? 8);
            if ($hour < 5 || $hour > 11) {
                monitor_api_error('Choose a morning hour between 05:00 and 11:00.');
            }
            monitor_state_set($pdo, 'digest_hour', (string) $hour);
            security_log($pdo, 'monitor.settings', null, 'Morning summary hour set to ' . $hour);
            monitor_api_reply(['status' => 'success', 'message' => 'The morning summary goes out at ' . sprintf('%02d:00', $hour) . ' Lagos time.']);
        }

        case 'wall': {
            $key = monitor_wall_key($pdo);
            monitor_wall_publish($pdo, false);
            monitor_api_reply(['status' => 'success', 'data' => ['snapshot' => '/live/monitor/' . $key . '.json', 'tv_link' => site_url('/modules/monitor/wall.php#k=' . $key)]]);
        }

        case 'wall_rotate': {
            $key = monitor_wall_key($pdo, true);
            security_log($pdo, 'monitor.wall_rotate', null, 'Live wall link replaced');
            monitor_wall_publish($pdo, true);
            monitor_api_reply(['status' => 'success', 'message' => 'New wall link made. Screens using the old one stop updating.',
                               'data' => ['snapshot' => '/live/monitor/' . $key . '.json', 'tv_link' => site_url('/modules/monitor/wall.php#k=' . $key)]]);
        }

        case 'test_alert': {
            $mailed = monitor_email_recipients($pdo, 'test', 'HOD Lekki: test alert', 'This is a test alert',
                'Someone pressed "Send a test" in the system monitor. If you are reading this, email alerts reach you.', [], false);
            $belled = monitor_notify_admins($pdo, $pdo, 'test', 'Test alert from the system monitor', 'If you can read this, monitor alerts reach your bell and phone.', monitor_screen_link(), false);
            monitor_api_reply(['status' => 'success', 'message' => 'Sent to ' . $mailed . ' email address(es) and ' . $belled . ' admin bell(s).']);
        }

        default:
            monitor_api_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    $ref = hod_monitor_report($e);
    monitor_api_error('The monitor could not do that' . ($ref ? ' (reference ' . $ref . ')' : '') . '.', 500);
}
