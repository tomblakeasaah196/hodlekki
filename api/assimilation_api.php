<?php
// /api/assimilation_api.php

require_once '../includes/db.php';
require_once '../includes/assimilation_helpers.php';
header('Content-Type: application/json');

// ==========================================================================
// AUTH GATE — Super Admin, pastors, any active Director/HOD of any
// department, or a member of the Assimilation team. Nobody else. Every
// action below runs after this passes, and the manager-only actions check
// $is_manager again.
// ==========================================================================
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id     = (int) $_SESSION['user_id'];
$active_role = $_SESSION['active_role'] ?? '';
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $is_manager = assim_is_manager($pdo, $user_id, $active_role);
    $is_team    = assim_is_team_member($pdo, $user_id);
} catch (PDOException $e) {
    error_log('Assimilation gate: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
    exit;
}

if (!$is_manager && !$is_team) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Access Denied: Assimilation is for pastors, department Directors/HODs and the Assimilation team.'
    ]);
    exit;
}

function assim_deny(string $message = 'Only pastors and department Directors/HODs can do this.'): void {
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function assim_fail(string $message): void {
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function assim_user_name(PDO $pdo, ?int $id): string {
    if (!$id) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
}

// The rule comes in as JSON (the builder posts one field) or as loose POST
// fields; both go through assim_normalize_rule().
function assim_rule_from_request(): array {
    $raw = $_POST['rule'] ?? $_GET['rule'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        return assim_normalize_rule(json_decode($raw, true) ?: []);
    }
    return assim_normalize_rule($_POST ?: $_GET);
}

// Defaults to the current month; swaps the ends if they arrive reversed.
function assim_date_range(): array {
    $valid = function ($d) {
        $x = DateTime::createFromFormat('Y-m-d', (string) $d);
        return $x && $x->format('Y-m-d') === $d;
    };
    $from = $_POST['from_date'] ?? $_GET['from_date'] ?? '';
    $to   = $_POST['to_date'] ?? $_GET['to_date'] ?? '';
    $from = $valid($from) ? $from : date('Y-m-01');
    $to   = $valid($to) ? $to : date('Y-m-t');
    return $from <= $to ? [$from, $to] : [$to, $from];
}

// A volunteer may only ever touch a case that is theirs, or one sitting in
// the unclaimed pool when the managers have opened it up. Returns the case.
function assim_case_for(PDO $pdo, int $case_id, int $user_id, bool $is_manager, bool $write = false): array {
    $stmt = $pdo->prepare("
        SELECT c.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS person_name, u.phone AS person_phone,
               NULLIF(TRIM(CONCAT_WS(' ', au.first_name, au.last_name)), '') AS assignee_name
          FROM assimilation_cases c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users au ON au.id = c.assigned_to
         WHERE c.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        assim_fail('That case no longer exists.');
    }
    if ($is_manager) {
        return $case;
    }
    $mine = (int) $case['assigned_to'] === $user_id;
    $pool = $case['assigned_to'] === null && $case['closed_at'] === null && assim_allow_self_claim($pdo);
    if (!$mine && !$pool) {
        assim_fail('This person is being followed up by someone else.');
    }
    if ($write && !$mine) {
        assim_fail('Claim this person first, then you can log the follow-up.');
    }
    return $case;
}

// Opens a case for one person, or explains why it could not. The unique key
// on assimilation_cases.open_user_id is the real guard against two open
// cases for the same person, so a race loses here rather than in the data.
function assim_open_case(PDO $pdo, int $person_id, ?int $to_user_id, int $by_user_id, ?int $watchlist_id): array {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$person_id]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $last = $pdo->prepare("SELECT MAX(x.attended_on) FROM " . assim_attendance_union_sql() . " x WHERE x.user_id = ?");
    $last->execute([$person_id]);
    $last_attended = $last->fetchColumn() ?: null;

    try {
        $ins = $pdo->prepare("
            INSERT INTO assimilation_cases
                (user_id, watchlist_id, assigned_to, assigned_by, assigned_at, status, last_attended_on)
            VALUES (?, ?, ?, ?, ?, 'To_Call', ?)
        ");
        $ins->execute([
            $person_id, $watchlist_id ?: null, $to_user_id ?: null,
            $to_user_id ? $by_user_id : null, $to_user_id ? date('Y-m-d H:i:s') : null,
            $last_attended,
        ]);
    } catch (PDOException $e) {
        // 23000 = the one-open-case-per-person unique key.
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'reason' => 'already_open'];
        }
        throw $e;
    }
    $case_id = (int) $pdo->lastInsertId();
    if ($to_user_id) {
        $pdo->prepare("
            INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action)
            VALUES (?, NULL, ?, ?, 'assign')
        ")->execute([$case_id, $to_user_id, $by_user_id]);
    }
    return ['ok' => true, 'case_id' => $case_id];
}

// Keeps assimilation_watchlist_hits in step with a rule, so the cron and
// the sidebar badge both read from one place. announced_at stays untouched,
// which is what stops a person being announced twice.
function assim_sync_watchlist_hits(PDO $pdo, int $watchlist_id, array $user_ids): array {
    $existing = $pdo->prepare("SELECT user_id FROM assimilation_watchlist_hits WHERE watchlist_id = ?");
    $existing->execute([$watchlist_id]);
    $before = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

    $fresh = array_values(array_diff($user_ids, $before));
    $gone  = array_values(array_diff($before, $user_ids));

    if ($fresh) {
        $ins = $pdo->prepare("
            INSERT INTO assimilation_watchlist_hits (watchlist_id, user_id) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
        ");
        foreach ($fresh as $uid) {
            $ins->execute([$watchlist_id, $uid]);
        }
    }
    if ($gone) {
        // They are back in the house often enough — stop watching them, and
        // let them be announced again if they drift a second time.
        $del = $pdo->prepare("DELETE FROM assimilation_watchlist_hits WHERE watchlist_id = ? AND user_id = ?");
        foreach ($gone as $uid) {
            $del->execute([$watchlist_id, $uid]);
        }
    }
    return ['new' => $fresh, 'left' => $gone];
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        // bootstrap — one round trip for everything the page needs, plus the
        // returned-home sweep so a Sunday check-in shows up without a cron.
        // ------------------------------------------------------------------
        case 'bootstrap':
            $celebrations = [];
            try {
                $marked = assim_detect_returned_home($pdo);
                assim_announce_returned_home($pdo, $marked);
                $celebrations = array_map(fn($m) => ['name' => $m['name'], 'came_on' => $m['came_on']], $marked);
            } catch (PDOException $e) {
                error_log('Assimilation returned-home sweep: ' . $e->getMessage());
            }

            echo json_encode(['status' => 'success', 'data' => [
                'is_manager'       => $is_manager,
                'me'              => ['id' => $user_id, 'name' => assim_user_name($pdo, $user_id)],
                'overdue_days'     => assim_overdue_days($pdo),
                'allow_self_claim' => assim_allow_self_claim($pdo),
                'default_rule'     => assim_default_rule($pdo),
                'spiritual_statuses' => ASSIM_SPIRITUAL_STATUSES,
                'age_bands'        => array_keys(ASSIM_AGE_BANDS),
                'departments'      => $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
                'regions'          => $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
                'team'             => array_map(fn($t) => ['user_id' => $t['user_id'], 'name' => $t['name']], assim_team($pdo)),
                'counts'           => assim_sidebar_counts($pdo, $user_id),
                'celebrations'     => $celebrations,
            ]]);
            break;

        // ------------------------------------------------------------------
        // find_people — Tab 1. The rule builder plus every filter.
        // ------------------------------------------------------------------
        case 'find_people':
            if (!$is_manager) {
                assim_deny('Only pastors and department Directors/HODs can search the congregation.');
            }
            $rule     = assim_rule_from_request();
            $per_page = 24;
            $page     = max(1, (int) ($_POST['page'] ?? 1));
            $sort     = $_POST['sort'] ?? 'drift';
            $order    = [
                'drift'  => 'att.last_attended IS NULL, att.last_attended ASC, u.first_name',
                'recent' => 'att.last_attended IS NULL, att.last_attended DESC, u.first_name',
                'name'   => 'u.first_name, u.last_name',
                'fewest' => 'att.services_in_window ASC, att.last_attended ASC',
            ][$sort] ?? 'att.last_attended IS NULL, att.last_attended ASC, u.first_name';

            $q    = assim_find_query($rule, ['limit' => $per_page, 'offset' => ($page - 1) * $per_page, 'order' => $order]);
            $stmt = $pdo->prepare($q['sql']);
            $stmt->execute($q['params']);
            $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total  = assim_count_rule($pdo, $rule);

            $sparklines = assim_monthly_attendance($pdo, array_column($people, 'id'));
            foreach ($people as &$p) {
                $p['id']                 = (int) $p['id'];
                $p['services_in_window'] = (int) $p['services_in_window'];
                $p['services_previous']  = (int) $p['services_previous'];
                $p['total_services']     = (int) $p['total_services'];
                $p['case_id']            = $p['case_id'] !== null ? (int) $p['case_id'] : null;
                $p['since_words']        = assim_since_words($p['last_attended']);
                $p['sparkline']          = array_values(array_map(
                    fn($m) => $m['count'],
                    assim_months_frame($sparklines[$p['id']] ?? [])
                ));
            }
            unset($p);

            echo json_encode(['status' => 'success', 'data' => [
                'people'   => $people,
                'total'    => $total,
                'page'     => $page,
                'has_more' => ($page * $per_page) < $total,
                'rule'     => $rule,
                'summary'  => assim_rule_summary($rule),
            ]]);
            break;

        // ------------------------------------------------------------------
        // export_csv — the current rule's full result set
        // ------------------------------------------------------------------
        case 'export_csv':
            if (!$is_manager) {
                assim_deny();
            }
            $rule = assim_rule_from_request();
            $q    = assim_find_query($rule, ['order' => 'att.last_attended IS NULL, att.last_attended ASC']);
            $stmt = $pdo->prepare($q['sql']);
            $stmt->execute($q['params']);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="assimilation_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['First name', 'Last name', 'Phone', 'Spiritual status', 'Departments', 'Region',
                'Last attended', 'How long ago', 'Services in window', 'Services before', 'Total services',
                'Case status', 'Assigned to'], ',', '"', '');
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, array_map('assim_csv_safe', [
                    $r['first_name'], $r['last_name'], $r['phone'],
                    str_replace('_', ' ', (string) $r['spiritual_status']), $r['departments'], $r['region_name'],
                    $r['last_attended'], assim_since_words($r['last_attended']),
                    $r['services_in_window'], $r['services_previous'], $r['total_services'],
                    $r['case_status'] ? assim_status_words($r['case_status']) : '', $r['assignee_name'],
                ]), ',', '"', '');
            }
            fclose($out);
            exit;

        // ------------------------------------------------------------------
        // Watchlists
        // ------------------------------------------------------------------
        case 'list_watchlists':
            if (!$is_manager) {
                assim_deny();
            }
            $rows = $pdo->query("
                SELECT w.id, w.name, w.rule_json, w.is_active, w.notify, w.created_at,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name
                  FROM assimilation_watchlists w
                  LEFT JOIN users u ON u.id = w.created_by
                 ORDER BY w.is_active DESC, w.name
            ")->fetchAll(PDO::FETCH_ASSOC);

            $lists = [];
            foreach ($rows as $w) {
                $rule = assim_normalize_rule($w['rule_json']);
                $ids  = assim_rule_user_ids($pdo, $rule);
                if ((int) $w['is_active'] === 1) {
                    // Keeps the badge and the cron honest between nightly runs.
                    assim_sync_watchlist_hits($pdo, (int) $w['id'], $ids);
                }
                $untouched = 0;
                if ($ids) {
                    $in   = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM users u
                         WHERE u.id IN ({$in})
                           AND NOT EXISTS (SELECT 1 FROM assimilation_cases c WHERE c.user_id = u.id AND c.closed_at IS NULL)
                    ");
                    $stmt->execute($ids);
                    $untouched = (int) $stmt->fetchColumn();
                }
                $lists[] = [
                    'id'              => (int) $w['id'],
                    'name'            => $w['name'],
                    'rule'            => $rule,
                    'summary'         => assim_rule_summary($rule),
                    'is_active'       => (int) $w['is_active'],
                    'notify'          => (int) $w['notify'],
                    'created_by_name' => $w['created_by_name'],
                    'people'          => count($ids),
                    'untouched'       => $untouched,
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $lists]);
            break;

        case 'save_watchlist':
            if (!$is_manager) {
                assim_deny();
            }
            $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 120) {
                assim_fail('Give the watchlist a name (up to 120 characters).');
            }
            $rule   = assim_rule_from_request();
            $notify = empty($_POST['notify']) ? 0 : 1;
            $id     = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $pdo->prepare("UPDATE assimilation_watchlists SET name = ?, rule_json = ?, notify = ? WHERE id = ?")
                    ->execute([$name, json_encode($rule), $notify, $id]);
                // The rule moved, so who it covers moved with it.
                $pdo->prepare("DELETE FROM assimilation_watchlist_hits WHERE watchlist_id = ?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO assimilation_watchlists (name, rule_json, created_by, notify) VALUES (?, ?, ?, ?)")
                    ->execute([$name, json_encode($rule), $user_id, $notify]);
                $id = (int) $pdo->lastInsertId();
            }
            assim_sync_watchlist_hits($pdo, $id, assim_rule_user_ids($pdo, $rule));
            echo json_encode(['status' => 'success', 'message' => 'Watchlist saved.', 'data' => ['id' => $id]]);
            break;

        case 'toggle_watchlist':
            if (!$is_manager) {
                assim_deny();
            }
            $id = (int) ($_POST['id'] ?? 0);
            $on = empty($_POST['is_active']) ? 0 : 1;
            $pdo->prepare("UPDATE assimilation_watchlists SET is_active = ? WHERE id = ?")->execute([$on, $id]);
            echo json_encode(['status' => 'success', 'message' => $on ? 'Watchlist is on.' : 'Watchlist paused — no more digests from it.']);
            break;

        case 'delete_watchlist':
            if (!$is_manager) {
                assim_deny();
            }
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM assimilation_watchlist_hits WHERE watchlist_id = ?")->execute([$id]);
            // Cases opened from it keep their history; they just lose the label.
            $pdo->prepare("UPDATE assimilation_cases SET watchlist_id = NULL WHERE watchlist_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM assimilation_watchlists WHERE id = ?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Watchlist deleted. Follow-up history is untouched.']);
            break;

        // ------------------------------------------------------------------
        // bulk_assign — open a case per person. to_user_id 0 = the pool.
        // ------------------------------------------------------------------
        case 'bulk_assign':
            if (!$is_manager) {
                assim_deny('Only pastors and department Directors/HODs can assign people.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])))));
            if (!$ids) {
                assim_fail('Select at least one person first.');
            }
            if (count($ids) > 200) {
                assim_fail('Assign up to 200 people at a time.');
            }
            $to = (int) ($_POST['to_user_id'] ?? 0);
            if ($to > 0 && !assim_is_team_member($pdo, $to)) {
                assim_fail('Pick someone who is on the Assimilation team. Add them under the Team tab first.');
            }
            $watchlist_id = (int) ($_POST['watchlist_id'] ?? 0);

            $opened = 0;
            $skipped = 0;
            $names  = [];
            foreach ($ids as $pid) {
                $res = assim_open_case($pdo, $pid, $to ?: null, $user_id, $watchlist_id ?: null);
                if ($res['ok']) {
                    $opened++;
                    if (count($names) < 5) {
                        $names[] = assim_user_name($pdo, $pid);
                    }
                } else {
                    $skipped++;
                }
            }
            if ($opened && $to > 0) {
                $list = implode(', ', $names) . ($opened > count($names) ? ' and ' . ($opened - count($names)) . ' more' : '');
                assim_notify($pdo, [$to], $opened === 1 ? 'Someone to bring home' : "{$opened} people to bring home",
                    assim_user_name($pdo, $user_id) . " asked you to reach out to {$list}.");
            }
            $msg = $to > 0
                ? "{$opened} assigned to " . assim_user_name($pdo, $to) . '.'
                : "{$opened} added to the unclaimed pool.";
            echo json_encode([
                'status'  => 'success',
                'message' => $msg . ($skipped ? " {$skipped} already had an open case." : ''),
                'data'    => ['opened' => $opened, 'skipped' => $skipped],
            ]);
            break;

        // ------------------------------------------------------------------
        // list_cases — Tab 2, with the sub-tab counts
        // ------------------------------------------------------------------
        case 'list_cases':
            $days  = assim_overdue_days($pdo);
            $where = [];
            $params = [];

            // A volunteer sees their own work, plus the pool when it is open.
            if (!$is_manager) {
                if (assim_allow_self_claim($pdo)) {
                    $where[]  = '(c.assigned_to = ? OR (c.assigned_to IS NULL AND c.closed_at IS NULL))';
                    $params[] = $user_id;
                } else {
                    $where[]  = 'c.assigned_to = ?';
                    $params[] = $user_id;
                }
            }

            $assignee = $_POST['assignee'] ?? '';
            if ($assignee === 'unassigned') {
                $where[] = 'c.assigned_to IS NULL';
            } elseif ($assignee === 'me') {
                $where[]  = 'c.assigned_to = ?';
                $params[] = $user_id;
            } elseif ((int) $assignee > 0) {
                $where[]  = 'c.assigned_to = ?';
                $params[] = (int) $assignee;
            }
            if ((int) ($_POST['watchlist_id'] ?? 0) > 0) {
                $where[]  = 'c.watchlist_id = ?';
                $params[] = (int) $_POST['watchlist_id'];
            }
            $search = trim((string) ($_POST['search'] ?? ''));
            if ($search !== '') {
                $where[]  = "(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) LIKE ? OR u.phone LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            if (!empty($_POST['overdue_only'])) {
                $where[] = assim_overdue_sql($days);
            }

            $sub_map = [
                'to_call'       => "c.closed_at IS NULL AND c.status = 'To_Call'",
                'reached'       => "c.closed_at IS NULL AND c.status = 'Reached'",
                'promised'      => "c.closed_at IS NULL AND c.status = 'Promised'",
                'returned_home' => 'c.returned_home_at IS NOT NULL',
                'closed'        => 'c.closed_at IS NOT NULL AND c.returned_home_at IS NULL',
                'all'           => '1 = 1',
            ];
            $base = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sums = [];
            foreach ($sub_map as $k => $expr) {
                $sums[] = "COALESCE(SUM({$expr}), 0) AS `{$k}`";
            }
            $countStmt = $pdo->prepare("
                SELECT " . implode(', ', $sums) . ", COALESCE(SUM(" . assim_overdue_sql($days) . "), 0) AS overdue
                  FROM assimilation_cases c JOIN users u ON u.id = c.user_id {$base}
            ");
            $countStmt->execute($params);
            $counts = array_map('intval', $countStmt->fetch(PDO::FETCH_ASSOC) ?: []);

            $sub = $_POST['sub_tab'] ?? 'to_call';
            if (isset($sub_map[$sub]) && $sub !== 'all') {
                $where[] = $sub_map[$sub];
            }
            $list_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $per_page = 24;
            $page     = max(1, (int) ($_POST['page'] ?? 1));
            $listStmt = $pdo->prepare("
                SELECT c.id, c.user_id, c.status, c.opened_at, c.closed_at, c.outcome,
                       c.returned_home_at, c.first_contact_at, c.last_contact_at,
                       c.next_touch_date, c.assigned_to, c.assigned_at, c.last_attended_on,
                       " . assim_overdue_sql($days) . " AS is_overdue,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS person_name,
                       u.phone, u.spiritual_status, u.picture_path,
                       TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS assignee_name,
                       w.name AS watchlist_name,
                       (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                          FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                         WHERE ud.user_id = c.user_id AND ud.is_active = 1) AS departments,
                       (SELECT COUNT(*) FROM assimilation_follow_ups f WHERE f.case_id = c.id) AS touches,
                       (SELECT COALESCE(NULLIF(f.notes_clean, ''), f.notes) FROM assimilation_follow_ups f
                         WHERE f.case_id = c.id ORDER BY f.id DESC LIMIT 1) AS last_note
                  FROM assimilation_cases c
                  JOIN users u ON u.id = c.user_id
                  LEFT JOIN users au ON au.id = c.assigned_to
                  LEFT JOIN assimilation_watchlists w ON w.id = c.watchlist_id
                  {$list_where}
                 ORDER BY " . assim_overdue_sql($days) . " DESC,
                          (c.next_touch_date IS NOT NULL AND c.next_touch_date <= CURDATE()) DESC,
                          c.next_touch_date IS NULL, c.next_touch_date ASC,
                          c.last_attended_on IS NULL, c.last_attended_on ASC, c.id DESC
                 LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page) . "
            ");
            $listStmt->execute($params);
            $cases = array_map(function (array $c): array {
                $c['id']          = (int) $c['id'];
                $c['user_id']     = (int) $c['user_id'];
                $c['touches']     = (int) $c['touches'];
                $c['is_overdue']  = (int) $c['is_overdue'];
                $c['since_words'] = assim_since_words($c['last_attended_on']);
                return $c;
            }, $listStmt->fetchAll(PDO::FETCH_ASSOC));

            $total = $counts[$sub] ?? $counts['all'] ?? 0;
            echo json_encode(['status' => 'success', 'data' => [
                'cases'    => $cases,
                'counts'   => $counts,
                'has_more' => ($page * $per_page) < $total,
                'overdue_days' => $days,
            ]]);
            break;

        // ------------------------------------------------------------------
        // case_detail — the drawer
        // ------------------------------------------------------------------
        case 'case_detail':
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, $is_manager);
            $person  = (int) $case['user_id'];

            $stmt = $pdo->prepare("
                SELECT u.id, u.first_name, u.last_name, u.phone, u.spiritual_status, u.gender,
                       u.picture_path, u.region_id, rg.name AS region_name,
                       (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                          FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                         WHERE ud.user_id = u.id AND ud.is_active = 1) AS departments
                  FROM users u LEFT JOIN regions rg ON rg.id = u.region_id
                 WHERE u.id = ?
            ");
            $stmt->execute([$person]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $fu = $pdo->prepare("
                SELECT f.id, f.channel, f.outcome, f.notes, f.notes_clean, f.prayer_points,
                       f.next_touch_date, f.created_at,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS logged_by_name
                  FROM assimilation_follow_ups f LEFT JOIN users u ON u.id = f.logged_by
                 WHERE f.case_id = ? ORDER BY f.id DESC
            ");
            $fu->execute([$case_id]);

            $hist = $pdo->prepare("
                SELECT a.action, a.notes, a.created_at,
                       TRIM(CONCAT_WS(' ', tu.first_name, tu.last_name)) AS to_name,
                       TRIM(CONCAT_WS(' ', bu.first_name, bu.last_name)) AS by_name
                  FROM assimilation_case_assignments a
                  LEFT JOIN users tu ON tu.id = a.to_user_id
                  LEFT JOIN users bu ON bu.id = a.assigned_by
                 WHERE a.case_id = ? ORDER BY a.id DESC
            ");
            $hist->execute([$case_id]);

            // Read-only cross-links so nobody calls the same person twice
            // from two different modules on the same day.
            $links = [];
            $key   = assim_phone_key((string) ($profile['phone'] ?? ''));
            if ($key !== '') {
                $rl = $pdo->prepare("
                    SELECT id, status FROM reach_leads
                     WHERE phone LIKE ? AND pushed_to_embrace_at IS NULL AND status <> 'Declined'
                     ORDER BY id DESC LIMIT 1
                ");
                $rl->execute(['%' . $key . '%']);
                if ($lead = $rl->fetch(PDO::FETCH_ASSOC)) {
                    $links[] = ['module' => 'Reach', 'label' => 'Open Reach lead · ' . str_replace('_', ' ', $lead['status']), 'url' => '/modules/reach/index.php'];
                }
            }
            $eb = $pdo->prepare("SELECT id FROM embrace_followups WHERE visitor_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
            $eb->execute([$person]);
            if ($eb->fetchColumn()) {
                $links[] = ['module' => 'Embrace', 'label' => 'Open Embrace first-timer follow-up', 'url' => '/modules/embrace/index.php'];
            }

            $case['is_overdue'] = $case['closed_at'] === null && $case['assigned_to'] !== null
                && $case['assigned_at'] !== null
                && strtotime((string) $case['assigned_at']) < strtotime('-' . assim_overdue_days($pdo) . ' days')
                && ($case['last_contact_at'] === null || $case['last_contact_at'] < $case['assigned_at']);

            // last_attended_on is the snapshot taken when the case opened;
            // if they have been back since, say so rather than repeating it.
            $recent = assim_attendance_days($pdo, $person, 10);
            $latest = $recent[0] ?? $case['last_attended_on'];

            echo json_encode(['status' => 'success', 'data' => [
                'case'          => $case,
                'person'        => $profile,
                'last_attended' => $latest,
                'since_words'   => assim_since_words($latest),
                'opened_gap'    => assim_since_words($case['last_attended_on']),
                'months'        => assim_attendance_months($pdo, $person),
                'recent_days'   => $recent,
                'follow_ups'  => $fu->fetchAll(PDO::FETCH_ASSOC),
                'assignments' => $hist->fetchAll(PDO::FETCH_ASSOC),
                'cross_links' => $links,
                'can_assign'  => $is_manager,
                'can_log'     => $is_manager || (int) $case['assigned_to'] === $user_id,
                'channels'    => ASSIM_CHANNELS,
                'outcomes'    => ASSIM_OUTCOMES,
            ]]);
            break;

        // ------------------------------------------------------------------
        // log_follow_up
        // ------------------------------------------------------------------
        case 'log_follow_up':
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, $is_manager, true);
            $channel = $_POST['channel'] ?? '';
            $outcome = $_POST['outcome'] ?? '';
            if (!in_array($channel, ASSIM_CHANNELS, true) || !in_array($outcome, ASSIM_OUTCOMES, true)) {
                assim_fail('Pick how you reached out and how it went.');
            }
            if ($case['closed_at'] !== null) {
                assim_fail('This case is closed. Reopen it from the drawer if you need to log more.');
            }
            $next     = trim((string) ($_POST['next_touch_date'] ?? ''));
            $nextDate = DateTime::createFromFormat('Y-m-d', $next);
            $next     = ($nextDate && $nextDate->format('Y-m-d') === $next) ? $next : null;
            $notes    = trim((string) ($_POST['notes'] ?? ''));
            $clean    = trim((string) ($_POST['notes_clean'] ?? ''));
            $prayer   = trim((string) ($_POST['prayer_points'] ?? ''));

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO assimilation_follow_ups
                    (case_id, logged_by, channel, outcome, notes, notes_clean, prayer_points, next_touch_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $case_id, $user_id, $channel, $outcome,
                $notes !== '' ? mb_substr($notes, 0, 4000) : null,
                $clean !== '' ? mb_substr($clean, 0, 4000) : null,
                $prayer !== '' ? mb_substr($prayer, 0, 2000) : null,
                $next,
            ]);
            $pdo->prepare("UPDATE assimilation_cases SET next_touch_date = ? WHERE id = ?")->execute([$next, $case_id]);
            $status = assim_recompute_case_status($pdo, $case_id);
            $pdo->commit();

            $marked = assim_detect_returned_home($pdo);
            assim_announce_returned_home($pdo, $marked);
            if ($marked) {
                $status = assim_recompute_case_status($pdo, $case_id);
            }

            echo json_encode([
                'status'  => 'success',
                'message' => 'Follow-up saved — ' . strtolower(assim_status_words($status)) . '.',
                'data'    => ['case_status' => $status, 'returned_home' => (bool) array_filter($marked, fn($m) => (int) $m['id'] === $case_id)],
            ]);
            break;

        // ------------------------------------------------------------------
        // assign_case / reassign / unassign — managers only
        // ------------------------------------------------------------------
        case 'assign_case':
        case 'reassign':
            if (!$is_manager) {
                assim_deny();
            }
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $to      = (int) ($_POST['to_user_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, true);
            if (!assim_is_team_member($pdo, $to)) {
                assim_fail('Pick someone who is on the Assimilation team.');
            }
            $from = $case['assigned_to'] !== null ? (int) $case['assigned_to'] : null;
            if ($from === $to) {
                echo json_encode(['status' => 'success', 'message' => 'Already theirs.', 'data' => ['noop' => true]]);
                break;
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE assimilation_cases SET assigned_to = ?, assigned_at = NOW(), assigned_by = ? WHERE id = ?")
                ->execute([$to, $user_id, $case_id]);
            $pdo->prepare("
                INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$case_id, $from, $to, $user_id, $from ? 'reassign' : 'assign', trim((string) ($_POST['notes'] ?? '')) ?: null]);
            $pdo->commit();

            $by = assim_user_name($pdo, $user_id);
            assim_notify($pdo, [$to], 'Someone to bring home', "{$by} asked you to reach out to {$case['person_name']}.");
            if ($from) {
                assim_notify($pdo, [$from], 'Follow-up moved on', "{$case['person_name']} is now with " . assim_user_name($pdo, $to) . '.');
            }
            echo json_encode(['status' => 'success', 'message' => 'Assigned to ' . assim_user_name($pdo, $to) . '.']);
            break;

        case 'unassign':
            if (!$is_manager) {
                assim_deny();
            }
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, true);
            $from    = $case['assigned_to'] !== null ? (int) $case['assigned_to'] : null;
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE assimilation_cases SET assigned_to = NULL, assigned_at = NULL, assigned_by = NULL WHERE id = ?")
                ->execute([$case_id]);
            $pdo->prepare("
                INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action)
                VALUES (?, ?, NULL, ?, 'unassign')
            ")->execute([$case_id, $from, $user_id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Back in the unclaimed pool.']);
            break;

        // ------------------------------------------------------------------
        // self_claim — any team member, unclaimed cases only
        // ------------------------------------------------------------------
        case 'self_claim':
            if (!$is_team && !$is_manager) {
                assim_deny('Only the Assimilation team can claim people.');
            }
            if (!$is_manager && !assim_allow_self_claim($pdo)) {
                assim_fail('Claiming is turned off — your leaders will assign people to you.');
            }
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE assimilation_cases SET assigned_to = ?, assigned_at = NOW(), assigned_by = ?
                 WHERE id = ? AND assigned_to IS NULL AND closed_at IS NULL
            ");
            $upd->execute([$user_id, $user_id, $case_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                assim_fail('Someone else has already picked this person up.');
            }
            $pdo->prepare("
                INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action)
                VALUES (?, NULL, ?, ?, 'self_claim')
            ")->execute([$case_id, $user_id, $user_id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'They are yours to reach out to.']);
            break;

        // ------------------------------------------------------------------
        // close_case / reopen_case / mark_returned_home
        // ------------------------------------------------------------------
        case 'close_case':
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, $is_manager, true);
            $status  = $_POST['status'] ?? '';
            if (!in_array($status, ['Unreachable', 'Not_Interested', 'Relocated', 'Attends_Elsewhere'], true)) {
                assim_fail('Pick a reason for closing this case.');
            }
            $reason = trim((string) ($_POST['outcome'] ?? '')) ?: assim_status_words($status);
            $pdo->prepare("
                UPDATE assimilation_cases
                   SET status = ?, outcome = ?, closed_at = COALESCE(closed_at, NOW()), closed_by = ?, next_touch_date = NULL
                 WHERE id = ?
            ")->execute([$status, mb_substr($reason, 0, 255), $user_id, $case_id]);
            echo json_encode(['status' => 'success', 'message' => 'Case closed — ' . strtolower(assim_status_words($status)) . '.']);
            break;

        case 'reopen_case':
            if (!$is_manager) {
                assim_deny();
            }
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, true);
            if ($case['closed_at'] === null) {
                echo json_encode(['status' => 'success', 'message' => 'This case is already open.', 'data' => ['noop' => true]]);
                break;
            }
            // Another open case for the same person would break the unique
            // key, so say so plainly instead of throwing.
            $dup = $pdo->prepare("SELECT id FROM assimilation_cases WHERE user_id = ? AND closed_at IS NULL LIMIT 1");
            $dup->execute([(int) $case['user_id']]);
            if ($dup->fetchColumn()) {
                assim_fail('There is already an open case for this person.');
            }
            $pdo->prepare("
                UPDATE assimilation_cases SET closed_at = NULL, closed_by = NULL, outcome = NULL, returned_home_at = NULL WHERE id = ?
            ")->execute([$case_id]);
            assim_recompute_case_status($pdo, $case_id);
            echo json_encode(['status' => 'success', 'message' => 'Case reopened.']);
            break;

        case 'mark_returned_home':
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, $is_manager, true);
            $when    = trim((string) ($_POST['came_on'] ?? ''));
            $d       = DateTime::createFromFormat('Y-m-d', $when);
            $came    = ($d && $d->format('Y-m-d') === $when && $when <= date('Y-m-d')) ? $when : date('Y-m-d');
            $pdo->prepare("
                UPDATE assimilation_cases
                   SET returned_home_at = ?, status = 'Returned_Home', outcome = 'Returned home',
                       closed_at = COALESCE(closed_at, NOW()), next_touch_date = NULL
                 WHERE id = ? AND returned_home_at IS NULL
            ")->execute([$came . ' 12:00:00', $case_id]);
            assim_announce_returned_home($pdo, [[
                'id' => $case_id, 'name' => $case['person_name'],
                'assigned_to' => $case['assigned_to'], 'came_on' => $came,
            ]]);
            echo json_encode(['status' => 'success', 'message' => $case['person_name'] . ' is home. Thank you for going after them.']);
            break;

        // ------------------------------------------------------------------
        // Team
        // ------------------------------------------------------------------
        case 'team_list':
            echo json_encode(['status' => 'success', 'data' => assim_team($pdo, empty($_POST['include_inactive']))]);
            break;

        case 'search_users':
            if (!$is_manager) {
                assim_deny();
            }
            $q = trim((string) ($_POST['q'] ?? ''));
            $stmt = $pdo->prepare("
                SELECT u.id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name, u.phone,
                       u.spiritual_status, u.picture_path,
                       (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                          FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                         WHERE ud.user_id = u.id AND ud.is_active = 1) AS departments,
                       (SELECT MAX(x.attended_on) FROM " . assim_attendance_union_sql() . " x WHERE x.user_id = u.id) AS last_attended,
                       EXISTS(SELECT 1 FROM assimilation_team t WHERE t.user_id = u.id AND t.is_active = 1) AS on_team
                  FROM users u
                 WHERE (? = '' OR TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) LIKE ? OR u.phone LIKE ?)
                 ORDER BY on_team DESC, u.first_name, u.last_name
                 LIMIT 40
            ");
            $stmt->execute([$q, '%' . $q . '%', '%' . $q . '%']);
            $rows = array_map(function (array $r): array {
                $r['id']          = (int) $r['id'];
                $r['on_team']     = (int) $r['on_team'];
                $r['since_words'] = assim_since_words($r['last_attended']);
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        case 'team_add':
            if (!$is_manager) {
                assim_deny('Only pastors and department Directors/HODs can change the team.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])))));
            if (!$ids) {
                assim_fail('Pick at least one person.');
            }
            $ins = $pdo->prepare("
                INSERT INTO assimilation_team (user_id, added_by, notes, is_active) VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE is_active = 1, added_by = VALUES(added_by)
            ");
            $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
            $added = 0;
            foreach ($ids as $uid) {
                $check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $check->execute([$uid]);
                if (!$check->fetchColumn()) {
                    continue;
                }
                $ins->execute([$uid, $user_id, $notes ? mb_substr($notes, 0, 255) : null]);
                $added++;
                assim_notify($pdo, [$uid], 'Welcome to the Assimilation team',
                    assim_user_name($pdo, $user_id) . ' added you to the Assimilation team. Open the volunteer page to see who you are calling.',
                    '/assimilation.php');
            }
            echo json_encode(['status' => 'success', 'message' => $added === 1 ? 'Volunteer added.' : "{$added} volunteers added.", 'data' => assim_team($pdo)]);
            break;

        case 'team_remove':
            if (!$is_manager) {
                assim_deny('Only pastors and department Directors/HODs can change the team.');
            }
            $uid = (int) ($_POST['user_id'] ?? 0);
            $open = $pdo->prepare("SELECT COUNT(*) FROM assimilation_cases WHERE assigned_to = ? AND closed_at IS NULL");
            $open->execute([$uid]);
            $pdo->prepare("UPDATE assimilation_team SET is_active = 0 WHERE user_id = ?")->execute([$uid]);
            $left = (int) $open->fetchColumn();
            echo json_encode([
                'status'  => 'success',
                'message' => 'Removed from the team. Their follow-up history stays.'
                    . ($left ? " They still hold {$left} open case(s) — reassign them." : ''),
                'data'    => assim_team($pdo),
            ]);
            break;

        // ------------------------------------------------------------------
        // Settings
        // ------------------------------------------------------------------
        case 'fetch_settings':
            echo json_encode(['status' => 'success', 'data' => [
                'overdue_days'     => assim_overdue_days($pdo),
                'allow_self_claim' => assim_allow_self_claim($pdo),
                'default_rule'     => assim_default_rule($pdo),
                'guide'            => assim_volunteer_guide($pdo),
            ]]);
            break;

        case 'save_settings':
            if (!$is_manager) {
                assim_deny();
            }
            $days = (int) ($_POST['overdue_days'] ?? 0);
            if ($days < 1 || $days > 90) {
                assim_fail('Overdue days must be between 1 and 90.');
            }
            assim_save_setting($pdo, 'overdue_days', (string) $days);
            assim_save_setting($pdo, 'allow_self_claim', empty($_POST['allow_self_claim']) ? '0' : '1');
            assim_save_setting($pdo, 'default_rule', json_encode(assim_rule_from_request()));

            $guide = trim((string) ($_POST['guide'] ?? ''));
            if ($guide === '' || $guide === trim((string) @file_get_contents(ASSIM_DEFAULT_GUIDE_FILE))) {
                $pdo->prepare("DELETE FROM assimilation_settings WHERE setting_key = 'volunteer_guide'")->execute();
            } else {
                assim_save_setting($pdo, 'volunteer_guide', mb_substr($guide, 0, 20000));
            }
            echo json_encode(['status' => 'success', 'message' => 'Settings saved.']);
            break;

        // ------------------------------------------------------------------
        // Analytics, PDF
        // ------------------------------------------------------------------
        case 'fetch_analytics':
            if (!$is_manager) {
                assim_deny();
            }
            [$from, $to] = assim_date_range();
            echo json_encode(['status' => 'success', 'data' => assim_analytics($pdo, $from, $to)]);
            break;

        case 'generate_pdf':
            if (!$is_manager) {
                assim_deny();
            }
            [$from, $to] = assim_date_range();
            try {
                require_once '../includes/assimilation_report_pdf.php';
                $pdf = assim_build_report_pdf($pdo, assim_analytics($pdo, $from, $to), assim_user_name($pdo, $user_id) ?: 'Assimilation');
                $dir = __DIR__ . '/../uploads/assimilation_reports';
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    throw new RuntimeException('cannot create ' . $dir);
                }
                // Random suffix: the report names people, and uploads/ is web-served.
                $filename = date('Ymd_His') . "_assimilation_{$from}_{$to}_" . bin2hex(random_bytes(6)) . '.pdf';
                if (file_put_contents($dir . '/' . $filename, $pdf) === false) {
                    throw new RuntimeException('cannot write ' . $filename);
                }
            } catch (Throwable $e) {
                error_log('Assimilation PDF error: ' . $e->getMessage());
                assim_fail('Could not generate the PDF. Please try again.');
            }
            echo json_encode(['status' => 'success', 'data' => ['url' => '/uploads/assimilation_reports/' . $filename, 'filename' => $filename]]);
            break;

        // ------------------------------------------------------------------
        // AI help — both degrade to a clear message when there is no key
        // ------------------------------------------------------------------
        case 'clean_notes':
            $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000);
            if ($notes === '') {
                assim_fail('Write or dictate the notes first.');
            }
            $clean = assim_clean_notes($notes);
            if ($clean === null) {
                assim_fail('The AI helper is unavailable right now — your notes are saved exactly as you wrote them.');
            }
            echo json_encode(['status' => 'success', 'data' => ['notes_clean' => $clean]]);
            break;

        case 'suggest_opening_line':
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $case    = assim_case_for($pdo, $case_id, $user_id, $is_manager);
            $line    = assim_opening_line($pdo, (int) $case['id'], $case['person_name'], $case['last_attended_on'], (string) $case['status']);
            if ($line === null) {
                assim_fail('The AI helper is unavailable right now. Ask after them warmly and listen first.');
            }
            echo json_encode(['status' => 'success', 'data' => ['line' => $line]]);
            break;

        case 'fetch_sidebar_counts':
            echo json_encode(['status' => 'success', 'data' => assim_sidebar_counts($pdo, $user_id)]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Assimilation API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Assimilation API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
