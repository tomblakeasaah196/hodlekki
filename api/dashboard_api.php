<?php
// /api/dashboard_api.php
//
// GET                         the home dashboard: cards for the member's tier,
//                             plus announcements. `metrics` keeps the old keys
//                             for modules/dashboard/index.php.
// GET ?action=detail&card=…   the breakdown behind one card, for its modal.
//
// Every number comes from includes/dashboard_metrics.php, which asks each one
// the way the module it summarises does.

// 1. Core Includes & Headers
require_once '../includes/db.php';
require_once '../includes/dashboard_metrics.php';
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.', 'redirect' => '/auth/login.php']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$active_role = (string) $_SESSION['active_role'];
$tier = dash_tier($active_role);
$today = dash_today();
$action = $_GET['action'] ?? 'summary';

/** "+12" / "−3" / "±0" */
function dash_signed(int $n): string
{
    return $n > 0 ? '+' . $n : ($n < 0 ? '−' . abs($n) : '±0');
}

function dash_plural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

try {
    // =========================================================================
    // DETAIL: the breakdown behind one card
    // =========================================================================
    if ($action === 'detail') {
        $card = (string) ($_GET['card'] ?? '');
        if (!in_array($card, DASH_TIER_CARDS[$tier], true)) {
            echo json_encode(['status' => 'error', 'message' => 'That card is not on your dashboard.']);
            exit;
        }

        $detail = ['status' => 'success', 'card' => $card, 'sections' => [], 'link' => null];

        switch ($card) {
            case 'workforce':
                $total = dash_workforce($pdo);
                $rows = [];
                foreach (dash_workforce_by_department($pdo) as $d) {
                    $rows[] = ['label' => $d['name'], 'value' => $d['people']];
                }
                $detail['title'] = 'Workforce';
                $detail['summary'] = dash_plural($total, 'person is', 'people are') . ' serving in a department this ministry year.';
                $detail['sections'][] = [
                    'heading' => 'By department',
                    'rows'    => $rows,
                    'note'    => 'Someone serving in two departments is listed under both and counted once in the total.',
                    'empty'   => 'Nobody is on a live roster yet.',
                ];
                $detail['link'] = ['href' => '/modules/departments/index.php', 'label' => 'Open Departments'];
                break;

            case 'pipeline':
                $p = dash_pipeline($pdo);
                $detail['title'] = 'New Pipeline';
                $detail['summary'] = dash_plural($p['total'], 'person is', 'people are') . ' on Embrace\'s active list.';
                $detail['sections'][] = [
                    'heading' => 'By status',
                    'rows'    => [
                        ['label' => '1st Timers', 'value' => $p['by_status']['1st_Timer']],
                        ['label' => '2nd Timers', 'value' => $p['by_status']['2nd_Timer']],
                        ['label' => '3rd Timers', 'value' => $p['by_status']['3rd_Timer']],
                        ['label' => 'Now Visitors, still owed a call', 'value' => $p['by_status']['Visitor'],
                         'hint'  => 'Came a 4th Sunday before their follow-up was finished.'],
                    ],
                ];
                $detail['link'] = ['href' => '/modules/embrace/index.php', 'label' => 'Open Embrace'];
                break;

            case 'followups':
                $f = dash_followups($pdo);
                $rows = [
                    ['label' => 'Assigned, waiting for a call', 'value' => $f['pending']],
                    ['label' => 'Call in progress', 'value' => $f['in_progress']],
                ];
                if ($f['other_open'] > 0) {
                    $rows[] = ['label' => 'Other open follow-ups', 'value' => $f['other_open']];
                }
                $detail['title'] = 'Follow-ups Due';
                $detail['summary'] = dash_plural($f['open'], 'follow-up is', 'follow-ups are') . ' waiting for a report'
                    . ($f['oldest_open'] ? ', the oldest assigned on ' . dash_short_date((string) $f['oldest_open']) : '') . '.';
                $detail['sections'][] = ['heading' => 'Open', 'rows' => $rows];
                $detail['sections'][] = [
                    'heading' => 'Also on the active list',
                    'rows'    => [
                        ['label' => 'Not yet assigned a worker', 'value' => $f['unassigned']],
                        ['label' => 'Reported, still a timer', 'value' => $f['reported'],
                         'hint'  => 'Their call is done; they stay on the list until they move up the ladder.'],
                    ],
                ];
                $detail['link'] = ['href' => '/modules/embrace/index.php', 'label' => 'Open Embrace'];
                break;

            case 'pending_qa':
                $q = dash_pending_qa($pdo, $today);
                $detail['title'] = 'Pending Q&A';
                $detail['summary'] = $q['total'] === 0
                    ? 'Every question has been answered.'
                    : dash_plural($q['total'], 'question is', 'questions are') . ' waiting for a pastor'
                        . ($q['oldest'] ? ', the oldest sent on ' . dash_short_date((string) $q['oldest']) : '') . '.';
                $detail['sections'][] = [
                    'heading' => 'Waiting for',
                    'rows'    => [
                        ['label' => 'Under a week', 'value' => $q['this_week']],
                        ['label' => '1 to 4 weeks', 'value' => $q['weeks']],
                        ['label' => 'Over 4 weeks', 'value' => $q['older']],
                    ],
                ];
                $detail['link'] = ['href' => '/modules/pastoral/index.php', 'label' => 'Open Pastoral Desk'];
                break;

            case 'sunday':
                $s = dash_sunday($pdo, $today);
                $detail['title'] = $s['is_today'] ? "Today's Service" : 'Last Sunday';
                if ($s['date'] === null) {
                    $detail['summary'] = 'No Sunday service has been recorded yet.';
                } else {
                    $detail['summary'] = dash_plural($s['total'], 'person', 'people')
                        . ($s['is_today'] ? ' so far today' : ' on ' . dash_short_date($s['date'])) . ', each counted once'
                        . ' however many services they attended.';
                    $detail['sections'][] = [
                        'heading' => $s['is_today'] ? 'Today' : dash_short_date($s['date']),
                        'rows'    => [
                            ['label' => 'People with a record', 'value' => $s['members'],
                             'hint'  => 'Clocked in on the roster or by QR.'],
                            ['label' => 'Walk-ins without a record', 'value' => $s['guests'],
                             'hint'  => 'QR check-ins that matched nobody, one per phone number.'],
                        ],
                    ];
                    $recent = [];
                    foreach ($s['history'] as $i => $h) {
                        $next = $s['history'][$i + 1] ?? null;
                        $hint = $h['members'] . ' with a record, ' . $h['guests'] . ' walk-in' . ($h['guests'] === 1 ? '' : 's');
                        if ($next !== null && !($i === 0 && $s['is_today'])) {
                            $hint .= ' · ' . dash_signed($h['total'] - $next['total']) . ' on the Sunday before';
                        }
                        $recent[] = [
                            'label' => dash_short_date($h['date']) . ($h['date'] === $today ? ' (so far)' : ''),
                            'value' => $h['total'],
                            'hint'  => $hint,
                        ];
                    }
                    $detail['sections'][] = ['heading' => 'Recent Sundays', 'rows' => $recent];
                }
                $detail['link'] = ['href' => '/modules/events/index.php', 'label' => 'Open Attendance Tracking'];
                break;

            case 'team':
            case 'units':
            case 'unit_workers':
                $led = dash_led_departments($pdo, $user_id, $tier === 'hod' ? 'HOD' : 'Director');
                $total = dash_workforce($pdo, $led);
                $rows = [];
                foreach (dash_workforce_by_department($pdo, $led) as $d) {
                    $rows[] = ['label' => $d['name'], 'value' => $d['people']];
                }
                $detail['title'] = $card === 'units' ? 'Units Managed' : ($card === 'team' ? 'Team Workers' : 'Unit Workers');
                $detail['summary'] = $led
                    ? dash_plural($total, 'person is', 'people are') . ' serving in '
                        . ($card === 'unit_workers' && count($led) === 1 ? 'your unit' : dash_plural(count($led), 'unit', 'units') . ' you lead') . '.'
                    : 'You are not on a live roster as ' . ($tier === 'hod' ? 'an HOD' : 'a Director') . ' yet.';
                $detail['sections'][] = [
                    'heading' => 'By unit',
                    'rows'    => $rows,
                    'note'    => count($rows) > 1 ? 'Someone serving in two of your units is counted once in the total.' : '',
                    'empty'   => 'No live rosters to show.',
                ];
                $detail['link'] = ['href' => '/modules/departments/index.php', 'label' => 'Open Departments'];
                break;

            case 'alerts':
                $unread = push_unread_count($pdo, $user_id);
                $rows = [];
                $columns = push_notification_columns($pdo);
                $clauses = ['user_id = :uid_direct'];
                $params = ['uid_direct' => $user_id];
                if (isset($columns['department_id'])) {
                    $clauses[] = 'department_id IN (SELECT department_id FROM user_departments WHERE user_id = :uid_dept AND is_active = 1)';
                    $params['uid_dept'] = $user_id;
                }
                if (isset($columns['role_id'])) {
                    $clauses[] = 'role_id IN (SELECT role_id FROM user_roles WHERE user_id = :uid_role)';
                    $params['uid_role'] = $user_id;
                }
                $stmt = $pdo->prepare("SELECT id, title, message, link_url, created_at
                                         FROM system_notifications
                                        WHERE is_read = 0 AND (" . implode(' OR ', $clauses) . ")
                                        ORDER BY created_at DESC, id DESC LIMIT 10");
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    $msg = push_message_from_notification($n);
                    $rows[] = [
                        'label' => $msg['title'],
                        'hint'  => trim($msg['body'] . ' · ' . dash_short_date((string) $n['created_at']), ' ·'),
                        'href'  => $msg['url'],
                    ];
                }
                $detail['title'] = 'Unread Alerts';
                $detail['summary'] = $unread === 0
                    ? 'You are all caught up.'
                    : dash_plural($unread, 'unread alert', 'unread alerts') . ($unread > count($rows) ? ', the latest ' . count($rows) . ' below' : '') . '.';
                $detail['sections'][] = ['heading' => 'Latest', 'rows' => $rows, 'empty' => 'Nothing new.'];
                break;
        }

        echo json_encode($detail);
        exit;
    }

    // =========================================================================
    // SUMMARY: the cards for this member's tier
    // =========================================================================
    $response = [
        'status' => 'success',
        'role' => $active_role,
        'tier' => $tier,
        'user_name' => ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
        'metrics' => [],
        'cards' => [],
        'live' => false,
        'announcements' => []
    ];

    // LEVEL 1: LEADERSHIP (Global Pulse)
    if ($tier === 'leadership') {
        $workforce = dash_workforce($pdo);
        $pipeline = dash_pipeline($pdo);
        $followups = dash_followups($pdo);
        $qa = dash_pending_qa($pdo, $today);
        $sunday = dash_sunday($pdo, $today);

        $response['metrics'] = [
            'total_workers' => $workforce,
            'total_visitors' => $pipeline['total'],
            'pending_qa' => $qa['total'],
            'pending_followups' => $followups['open'],
            'last_sunday_attendance' => $sunday['total'],
        ];

        if ($sunday['date'] === null) {
            $sundayNote = 'No Sunday service recorded yet';
            $sundayTrend = null;
        } elseif ($sunday['is_today']) {
            $sundayNote = 'So far' . ($sunday['previous'] ? ' · ' . dash_short_date($sunday['previous']['date']) . ' had ' . $sunday['previous']['total'] : '');
            $sundayTrend = null;
        } else {
            $sundayNote = dash_short_date($sunday['date'])
                . ($sunday['change'] !== null ? ' · ' . dash_signed($sunday['change']) . ' vs ' . dash_short_date($sunday['previous']['date']) : '');
            $sundayTrend = $sunday['change'] === null ? null : ($sunday['change'] > 0 ? 'up' : ($sunday['change'] < 0 ? 'down' : 'flat'));
        }

        $response['cards'] = [
            ['key' => 'workforce', 'title' => 'Workforce', 'value' => $workforce,
             'note' => 'Serving in a department'],
            ['key' => 'pipeline', 'title' => 'New Pipeline', 'value' => $pipeline['total'],
             'note' => 'On Embrace\'s active list'],
            ['key' => 'followups', 'title' => 'Follow-ups Due', 'value' => $followups['open'],
             'note' => $followups['unassigned'] > 0 ? $followups['unassigned'] . ' more not yet assigned' : 'Waiting for a report'],
            ['key' => 'pending_qa', 'title' => 'Pending Q&A', 'value' => $qa['total'],
             'note' => $qa['oldest'] ? 'Oldest from ' . dash_short_date((string) $qa['oldest']) : 'All answered'],
            ['key' => 'sunday', 'title' => $sunday['is_today'] ? "Today's Service" : 'Last Sunday', 'value' => $sunday['total'],
             'note' => $sundayNote, 'trend' => $sundayTrend],
        ];
        $response['live'] = $sunday['is_today'];
    }

    // LEVEL 2: DIRECTORS (Unit View)
    elseif ($tier === 'director') {
        $led = dash_led_departments($pdo, $user_id, 'Director');
        $team = dash_workforce($pdo, $led);
        $response['metrics']['team_size'] = $team;
        $response['metrics']['managed_units'] = count($led);
        $response['cards'] = [
            ['key' => 'team', 'title' => 'Team Workers', 'value' => $team, 'note' => 'Across the units you lead'],
            ['key' => 'units', 'title' => 'Units Managed', 'value' => count($led), 'note' => 'On a live roster'],
        ];
    }

    // LEVEL 3: HOD (Departmental View)
    elseif ($tier === 'hod') {
        $led = dash_led_departments($pdo, $user_id, 'HOD');
        $workers = dash_workforce($pdo, $led);
        $response['metrics']['active_workers'] = $workers;
        $response['cards'] = [
            ['key' => 'unit_workers', 'title' => 'Unit Workers', 'value' => $workers, 'note' => 'Serving in your unit'],
        ];
    }

    // LEVEL 4: STANDARD WORKER/MEMBER (Personal Inbox)
    else {
        // The same count as the bell and the app-icon badge: notifications to
        // this member, their departments and their roles.
        $unread = push_unread_count($pdo, $user_id);
        $response['metrics']['unread_alerts'] = $unread;
        $response['cards'] = [
            ['key' => 'alerts', 'title' => 'Unread Alerts', 'value' => $unread,
             'note' => $unread === 0 ? 'All caught up' : 'Tap to see them'],
        ];
    }

    // 4. GLOBAL DATA (Fetched for every role)
    // =========================================================================

    // Live Ministry Announcements (Only Approved notices for current/future events)
    $stmt = $pdo->query("
        SELECT a.title, a.content, e.title as event_name, DATE_FORMAT(e.event_date, '%b %D') as date_label
        FROM announcements a
        JOIN events e ON a.target_event_id = e.id
        WHERE a.status = 'Approved'
        AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY e.event_date ASC
        LIMIT 4
    ");
    $response['announcements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($response);

} catch (Throwable $e) {
    error_log("Dashboard API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Internal Engine Error.']);
}
?>
