<?php
// /api/event_analytics_api.php
// Read-only analytics backend for the Events & Attendance > Analytics tab.
// Scope: Sunday_Service and Midweek_Service ONLY. Never touches
// event_registrations — these two service types never use registration.

require_once '../includes/db.php';
require_once '../includes/event_analytics_helpers.php';
header('Content-Type: application/json');

// ---- Auth: same clearance as the rest of the Events module ----
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles, true)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. You do not have clearance to view analytics.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Build the per-event summary array the frontend renders everywhere. */
function ea_summarize_events(PDO $pdo, array $events, string $category): array {
    $ids = array_map(fn($e) => (int)$e['id'], $events);
    $stats = ea_bulk_attendance_stats($pdo, $ids);
    $per = $stats['per_event'];

    // Previous-service baseline for the very first event in the list.
    $prevBeforeFirst = null;
    if ($events) {
        $prevBeforeFirst = ea_fetch_previous_event($pdo, $category, date('Y-m-d', strtotime($events[0]['event_date'])), (int)$events[0]['id']);
    }
    $prevTotal = null;
    if ($prevBeforeFirst) {
        $s = ea_bulk_attendance_stats($pdo, [(int)$prevBeforeFirst['id']]);
        $prevTotal = $s['per_event'][$prevBeforeFirst['id']]['total'] ?? null;
    }

    $out = [];
    foreach ($events as $ev) {
        $eid = (int)$ev['id'];
        $s = $per[$eid] ?? ['total'=>0,'male'=>0,'female'=>0,'unknown_gender'=>0,'status'=>array_fill_keys(EA_STATUS_ORDER,0),'region'=>[]];
        $delta = $prevTotal !== null ? ea_pct_change((float)$prevTotal, (float)$s['total']) : null;
        $ministers = [];
        if (!empty($ev['ministers'])) { $d = json_decode($ev['ministers'], true); if (is_array($d)) $ministers = $d; }

        $out[] = [
            'id' => $eid,
            'title' => $ev['title'],
            'category' => $ev['event_category'],
            'event_date' => $ev['event_date'],
            'location' => $ev['location'],
            'is_closed' => (int)$ev['is_closed'],
            'total' => (int)$s['total'],
            'male' => (int)$s['male'],
            'female' => (int)$s['female'],
            'unknown_gender' => (int)$s['unknown_gender'],
            'status' => $s['status'],
            'region' => $s['region'],
            'first_timers' => (int)($s['status']['1st_Timer'] ?? 0),
            'workers' => (int)(($s['status']['Worker'] ?? 0) + ($s['status']['Pastor'] ?? 0)),
            'vs_previous' => $delta,
            'prev_total' => $prevTotal,
            'ministers' => $ministers,
        ];
        $prevTotal = $s['total'];
    }
    return $out;
}

function ea_build_trend(array $summaries, string $granularity): array {
    $buckets = []; // key => aggregate
    foreach ($summaries as $s) {
        $key = ea_bucket_key($s['event_date'], $granularity);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key' => $key, 'label' => ea_bucket_label($key, $granularity),
                'total' => 0, 'male' => 0, 'female' => 0, 'events' => 0,
                'status' => array_fill_keys(EA_STATUS_ORDER, 0),
                'event_ids' => [], 'start_date' => $s['event_date'], 'end_date' => $s['event_date'],
            ];
        }
        $b = &$buckets[$key];
        $b['total'] += $s['total'];
        $b['male'] += $s['male'];
        $b['female'] += $s['female'];
        $b['events']++;
        foreach ($s['status'] as $k => $v) $b['status'][$k] += $v;
        $b['event_ids'][] = $s['id'];
        if (strtotime($s['event_date']) < strtotime($b['start_date'])) $b['start_date'] = $s['event_date'];
        if (strtotime($s['event_date']) > strtotime($b['end_date'])) $b['end_date'] = $s['event_date'];
        unset($b);
    }
    ksort($buckets);
    return array_values($buckets);
}

function ea_kpis(array $summaries, string $category, PDO $pdo, string $rangeStart): array {
    $n = count($summaries);
    if ($n === 0) {
        return [
            'services_count' => 0, 'total_attendance' => 0, 'avg_attendance' => 0,
            'avg_vs_baseline' => null, 'growth_pct' => null,
            'peak' => null, 'lowest' => null,
            'first_timers_total' => 0, 'first_timer_rate' => 0,
            'workers_total' => 0, 'male_pct' => 0, 'female_pct' => 0,
        ];
    }
    $total = array_sum(array_column($summaries, 'total'));
    $avg = round($total / $n, 1);

    // Growth: first half vs second half of the selected range.
    $half = (int)floor($n / 2);
    $growth = null;
    if ($half > 0) {
        $firstHalf = array_slice($summaries, 0, $half);
        $secondHalf = array_slice($summaries, $n - $half, $half);
        $fSum = array_sum(array_column($firstHalf, 'total'));
        $sSum = array_sum(array_column($secondHalf, 'total'));
        $growth = ea_pct_change((float)$fSum, (float)$sSum);
    }

    // Baseline: trailing 12 same-category events ending right before the range.
    $baselineEvents = ea_fetch_trailing_events($pdo, $category, $rangeStart, 12);
    $baselineIds = array_map(fn($e) => (int)$e['id'], $baselineEvents);
    // exclude anything that's actually inside the current range already
    $inRangeIds = array_column($summaries, 'id');
    $baselineIds = array_values(array_diff($baselineIds, $inRangeIds));
    $baselineAvg = null;
    if ($baselineIds) {
        $bs = ea_bulk_attendance_stats($pdo, $baselineIds);
        $vals = array_map(fn($e) => $bs['per_event'][$e]['total'] ?? 0, $baselineIds);
        $baselineAvg = count($vals) ? array_sum($vals) / count($vals) : null;
    }
    $avgVsBaseline = ($baselineAvg !== null) ? ea_pct_change($baselineAvg, $avg) : null;

    $peak = null; $lowest = null;
    foreach ($summaries as $s) {
        if ($peak === null || $s['total'] > $peak['total']) $peak = $s;
        if ($lowest === null || $s['total'] < $lowest['total']) $lowest = $s;
    }

    $firstTimers = array_sum(array_column($summaries, 'first_timers'));
    $workers = array_sum(array_column($summaries, 'workers'));
    $male = array_sum(array_column($summaries, 'male'));
    $female = array_sum(array_column($summaries, 'female'));
    $genderKnown = $male + $female;

    return [
        'services_count' => $n,
        'total_attendance' => $total,
        'avg_attendance' => $avg,
        'avg_vs_baseline' => $avgVsBaseline,
        'baseline_avg' => $baselineAvg !== null ? round($baselineAvg, 1) : null,
        'growth_pct' => $growth,
        'peak' => $peak ? ['id'=>$peak['id'],'title'=>$peak['title'],'date'=>$peak['event_date'],'total'=>$peak['total']] : null,
        'lowest' => $lowest ? ['id'=>$lowest['id'],'title'=>$lowest['title'],'date'=>$lowest['event_date'],'total'=>$lowest['total']] : null,
        'first_timers_total' => $firstTimers,
        'first_timer_rate' => $total > 0 ? round($firstTimers / $total * 100, 1) : 0,
        'workers_total' => $workers,
        'male_pct' => $genderKnown > 0 ? round($male / $genderKnown * 100, 1) : 0,
        'female_pct' => $genderKnown > 0 ? round($female / $genderKnown * 100, 1) : 0,
    ];
}

try {
    switch ($action) {

        // =====================================================================
        // OVERVIEW: everything one tab (Midweek OR Sunday) needs for one range.
        // =====================================================================
        case 'overview': {
            $category = $_POST['category'] ?? '';
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';
            $limit = (int)($_POST['limit'] ?? 0);
            $granularity = in_array($_POST['granularity'] ?? '', ['service','week','month'], true) ? $_POST['granularity'] : 'month';

            if (!ea_valid_category($category)) { echo json_encode(['status'=>'error','message'=>'Invalid or missing category. Must be Sunday_Service or Midweek_Service.']); exit; }

            if ($limit > 0) {
                // "Last N services" mode: derive the effective date range from
                // the N most recent events of this category, ignoring any
                // start/end sent by the client.
                $trailing = ea_fetch_trailing_events($pdo, $category, date('Y-m-d'), $limit);
                if ($trailing) {
                    $start = date('Y-m-d', strtotime($trailing[0]['event_date']));
                    $end = date('Y-m-d', strtotime(end($trailing)['event_date']));
                } else {
                    $start = date('Y-m-d');
                    $end = date('Y-m-d');
                }
            }

            if (!$start || !$end || strtotime($start) === false || strtotime($end) === false) { echo json_encode(['status'=>'error','message'=>'Invalid date range.']); exit; }
            if (strtotime($start) > strtotime($end)) { [$start,$end] = [$end,$start]; }

            $events = ea_fetch_events($pdo, $category, $start, $end);
            $summaries = ea_summarize_events($pdo, $events, $category);
            $trend = ea_build_trend($summaries, $granularity);
            $kpis = ea_kpis($summaries, $category, $pdo, $start);

            // Grand totals across the whole range (for the totals-donuts).
            $genderTotals = ['Male'=>array_sum(array_column($summaries,'male')), 'Female'=>array_sum(array_column($summaries,'female')), 'Unknown'=>array_sum(array_column($summaries,'unknown_gender'))];
            $statusTotals = array_fill_keys(EA_STATUS_ORDER, 0);
            foreach ($summaries as $s) foreach ($s['status'] as $k=>$v) $statusTotals[$k] += $v;
            $regionTotals = [];
            foreach ($summaries as $s) foreach ($s['region'] as $k=>$v) $regionTotals[$k] = ($regionTotals[$k] ?? 0) + $v;
            arsort($regionTotals);

            echo json_encode([
                'status' => 'success',
                'category' => $category,
                'category_label' => EA_CATEGORY_LABELS[$category],
                'range' => ['start'=>$start,'end'=>$end,'granularity'=>$granularity],
                'events' => $summaries,
                'trend' => $trend,
                'kpis' => $kpis,
                'gender_totals' => $genderTotals,
                'status_totals' => $statusTotals,
                'status_labels' => EA_STATUS_LABELS,
                'status_colors' => EA_STATUS_COLORS,
                'region_totals' => $regionTotals,
            ]);
            break;
        }

        // =====================================================================
        // EVENT DETAIL: single-service deep dive for the modal.
        // =====================================================================
        case 'event_detail': {
            $eventId = (int)($_POST['event_id'] ?? 0);
            if (!$eventId) { echo json_encode(['status'=>'error','message'=>'Missing event id.']); exit; }

            $stmt = $pdo->prepare("SELECT id, title, event_category, event_date, end_date, location, is_closed, banner_image_url, ministers, report_notes FROM events WHERE id = ?");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$event || !ea_valid_category($event['event_category'])) { echo json_encode(['status'=>'error','message'=>'Event not found or not a Sunday/Midweek service.']); exit; }

            $category = $event['event_category'];
            $stats = ea_bulk_attendance_stats($pdo, [$eventId]);
            $s = $stats['per_event'][$eventId];

            $prev = ea_fetch_previous_event($pdo, $category, date('Y-m-d', strtotime($event['event_date'])), $eventId);
            $prevTotal = null; $prevDelta = null;
            if ($prev) {
                $ps = ea_bulk_attendance_stats($pdo, [(int)$prev['id']]);
                $prevTotal = $ps['per_event'][$prev['id']]['total'] ?? 0;
                $prevDelta = ea_pct_change((float)$prevTotal, (float)$s['total']);
            }

            // Sparkline: last 8 occurrences of this category, including this one.
            $trailing = ea_fetch_trailing_events($pdo, $category, date('Y-m-d', strtotime($event['event_date'])), 8, 0);
            $trailIds = array_map(fn($e)=>(int)$e['id'], $trailing);
            if (!in_array($eventId, $trailIds, true)) { $trailing[] = $event; $trailIds[] = $eventId; }
            $trailStats = ea_bulk_attendance_stats($pdo, $trailIds);
            $sparkline = array_map(function($e) use ($trailStats) {
                return ['id'=>(int)$e['id'], 'date'=>$e['event_date'], 'total'=>$trailStats['per_event'][$e['id']]['total'] ?? 0];
            }, $trailing);

            // Baseline average (trailing 12, excluding this one).
            $baselineEvents = ea_fetch_trailing_events($pdo, $category, date('Y-m-d', strtotime($event['event_date'])), 12, $eventId);
            $baselineAvg = null;
            if ($baselineEvents) {
                $bIds = array_map(fn($e)=>(int)$e['id'], $baselineEvents);
                $bs = ea_bulk_attendance_stats($pdo, $bIds);
                $vals = array_map(fn($e) => $bs['per_event'][$e]['total'] ?? 0, $bIds);
                $baselineAvg = count($vals) ? round(array_sum($vals)/count($vals), 1) : null;
            }

            $pace = ea_checkin_pace($pdo, $eventId);
            $roster = ea_event_roster($pdo, $eventId);
            $ministers = [];
            if (!empty($event['ministers'])) { $d = json_decode($event['ministers'], true); if (is_array($d)) $ministers = $d; }

            echo json_encode([
                'status' => 'success',
                'event' => [
                    'id' => (int)$event['id'], 'title' => $event['title'], 'category' => $category,
                    'category_label' => EA_CATEGORY_LABELS[$category], 'event_date' => $event['event_date'],
                    'location' => $event['location'], 'banner_image_url' => $event['banner_image_url'],
                    'ministers' => $ministers, 'report_notes' => $event['report_notes'], 'is_closed' => (int)$event['is_closed'],
                ],
                'stats' => [
                    'total' => (int)$s['total'], 'male' => (int)$s['male'], 'female' => (int)$s['female'],
                    'unknown_gender' => (int)$s['unknown_gender'], 'status' => $s['status'], 'region' => $s['region'],
                    'first_timers' => (int)($s['status']['1st_Timer'] ?? 0), 'workers' => (int)(($s['status']['Worker'] ?? 0)+($s['status']['Pastor'] ?? 0)),
                ],
                'previous' => $prev ? ['id'=>(int)$prev['id'],'title'=>$prev['title'],'date'=>$prev['event_date'],'total'=>$prevTotal,'delta_pct'=>$prevDelta] : null,
                'baseline_avg' => $baselineAvg,
                'sparkline' => $sparkline,
                'pace' => $pace,
                'roster' => $roster,
                'status_labels' => EA_STATUS_LABELS,
                'status_colors' => EA_STATUS_COLORS,
            ]);
            break;
        }

        // =====================================================================
        // COMPARE: two arbitrary ranges, same category, side by side.
        // =====================================================================
        case 'compare': {
            $category = $_POST['category'] ?? '';
            if (!ea_valid_category($category)) { echo json_encode(['status'=>'error','message'=>'Invalid category.']); exit; }
            $aStart = $_POST['a_start'] ?? ''; $aEnd = $_POST['a_end'] ?? '';
            $bStart = $_POST['b_start'] ?? ''; $bEnd = $_POST['b_end'] ?? '';
            foreach ([$aStart,$aEnd,$bStart,$bEnd] as $d) {
                if (!$d || strtotime($d) === false) { echo json_encode(['status'=>'error','message'=>'Invalid comparison dates.']); exit; }
            }

            $build = function($start, $end) use ($pdo, $category) {
                if (strtotime($start) > strtotime($end)) { [$start,$end] = [$end,$start]; }
                $events = ea_fetch_events($pdo, $category, $start, $end);
                $summaries = ea_summarize_events($pdo, $events, $category);
                $n = count($summaries);
                $total = array_sum(array_column($summaries, 'total'));
                $male = array_sum(array_column($summaries, 'male'));
                $female = array_sum(array_column($summaries, 'female'));
                $ft = array_sum(array_column($summaries, 'first_timers'));
                $wk = array_sum(array_column($summaries, 'workers'));
                return [
                    'label_start' => $start, 'label_end' => $end,
                    'services_count' => $n, 'total_attendance' => $total,
                    'avg_attendance' => $n > 0 ? round($total / $n, 1) : 0,
                    'male' => $male, 'female' => $female,
                    'first_timers' => $ft, 'workers' => $wk,
                    'events' => $summaries,
                ];
            };

            $A = $build($aStart, $aEnd);
            $B = $build($bStart, $bEnd);

            $delta = function($from, $to) {
                return ea_pct_change((float)$from, (float)$to);
            };

            echo json_encode([
                'status' => 'success',
                'category' => $category,
                'category_label' => EA_CATEGORY_LABELS[$category],
                'a' => $A, 'b' => $B,
                'deltas' => [
                    'total_attendance' => $delta($A['total_attendance'], $B['total_attendance']),
                    'avg_attendance' => $delta($A['avg_attendance'], $B['avg_attendance']),
                    'first_timers' => $delta($A['first_timers'], $B['first_timers']),
                    'workers' => $delta($A['workers'], $B['workers']),
                    'services_count' => $delta($A['services_count'], $B['services_count']),
                ],
            ]);
            break;
        }

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            break;
    }
} catch (\Throwable $e) {
    error_log('Event Analytics API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Analytics request failed: ' . $e->getMessage()]);
}
