<?php
// /includes/event_analytics_helpers.php
// ============================================================================
// EVENTS & ATTENDANCE — ANALYTICS TAB (Sunday Service / Midweek Service only)
// ----------------------------------------------------------------------------
// Every number this module produces is built from the SAME data the Attendance
// tab already writes:
//   - `attendance`  : the roster-based check-in (Events > Attendance tab picks
//                      the event, staff tap a congregant from the FULL
//                      congregation roster to mark them Present). This is the
//                      authoritative source for Sunday Service / Midweek
//                      Service attendance and always carries a real user_id.
//   - `checkins`    : the public QR self-check-in table. Included defensively
//                      (UNION) so nothing is undercounted if it is ever used
//                      for a Sunday/Midweek event, but in normal operation it
//                      contributes nothing extra here because every regular
//                      congregant comes from the roster, not a public link.
//   - `users`       : gender, spiritual_status, region_id for whoever was
//                      marked present.
//   - `regions`     : human region names.
//
// Sunday/Midweek services NEVER use event_registrations — this file never
// touches that table.
// ============================================================================

const EA_CATEGORIES = ['Sunday_Service', 'Midweek_Service'];

const EA_CATEGORY_LABELS = [
    'Sunday_Service'  => 'Sunday Service',
    'Midweek_Service' => 'Midweek Service',
];

// Canonical spiritual_status vocabulary (matches includes/assimilation_helpers.php)
// plus one bucket for attendees we truly could not match to a profile.
const EA_STATUS_ORDER = ['Member', 'Worker', 'Pastor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor', 'Non_Member', 'Unspecified', 'Not_On_File'];

const EA_STATUS_LABELS = [
    'Member'      => 'Member',
    'Worker'      => 'Worker',
    'Pastor'      => 'Pastor',
    '1st_Timer'   => '1st Timer',
    '2nd_Timer'   => '2nd Timer',
    '3rd_Timer'   => '3rd Timer',
    'Visitor'     => 'Visitor',
    'Non_Member'  => 'Non-Member',
    'Unspecified' => 'Unspecified',
    'Not_On_File' => 'Not On File',
];

const EA_STATUS_COLORS = [
    'Member'      => '#1D356A',
    'Worker'      => '#2563EB',
    'Pastor'      => '#7C3AED',
    '1st_Timer'   => '#D11920',
    '2nd_Timer'   => '#F97316',
    '3rd_Timer'   => '#F59E0B',
    'Visitor'     => '#10B981',
    'Non_Member'  => '#06B6D4',
    'Unspecified' => '#9CA3AF',
    'Not_On_File' => '#4B5563',
];

function ea_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return $cache[$table] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function ea_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        return $cache[$key] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = true; // don't block on a check failure
    }
}

function ea_valid_category(?string $c): bool {
    return in_array($c, EA_CATEGORIES, true);
}

/** Normalize a spiritual_status value to one of the known buckets. */
function ea_norm_status(?string $s): string {
    if (!$s || trim($s) === '') return 'Unspecified';
    return in_array($s, EA_STATUS_ORDER, true) ? $s : 'Unspecified';
}

/**
 * All events of one category inside [start, end] (inclusive, by DATE(event_date)),
 * ordered oldest -> newest, plus (separately) the single most recent event of the
 * same category strictly BEFORE the range — used as the baseline for the very
 * first row's "vs previous service" delta.
 */
function ea_fetch_events(PDO $pdo, string $category, string $start, string $end): array {
    $stmt = $pdo->prepare("
        SELECT id, title, event_category, event_date, end_date, location, is_closed,
               banner_image_url, ministers, report_notes
        FROM events
        WHERE event_category = ? AND DATE(event_date) BETWEEN ? AND ?
        ORDER BY event_date ASC, id ASC
    ");
    $stmt->execute([$category, $start, $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ea_fetch_previous_event(PDO $pdo, string $category, string $beforeDate, int $excludeId = 0): ?array {
    $stmt = $pdo->prepare("
        SELECT id, title, event_date
        FROM events
        WHERE event_category = ? AND DATE(event_date) < ? AND id <> ?
        ORDER BY event_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$category, $beforeDate, $excludeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Last N events of a category strictly before (and including) a given date, oldest->newest. */
function ea_fetch_trailing_events(PDO $pdo, string $category, string $onOrBeforeDate, int $limit = 12, int $excludeId = 0): array {
    $stmt = $pdo->prepare("
        SELECT id, title, event_date
        FROM events
        WHERE event_category = ? AND DATE(event_date) <= ? AND id <> ?
        ORDER BY event_date DESC, id DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute([$category, $onOrBeforeDate, $excludeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_reverse($rows);
}

/**
 * The core aggregate: for a set of event IDs, return per-event totals broken
 * down by gender / spiritual_status / region, plus grand totals across all of
 * them. Single pass over one UNIONed result set — safe for a few hundred
 * events at once.
 */
function ea_bulk_attendance_stats(PDO $pdo, array $eventIds): array {
    $per = [];
    foreach ($eventIds as $eid) {
        $per[$eid] = [
            'total' => 0, 'male' => 0, 'female' => 0, 'unknown_gender' => 0,
            'status' => array_fill_keys(EA_STATUS_ORDER, 0),
            'region' => [], // region_name => count ('Unassigned' for matched users with no region)
            'user_ids' => [],
        ];
    }
    if (!$eventIds) {
        return ['per_event' => $per, 'gender' => ['Male'=>0,'Female'=>0,'Unknown'=>0], 'status' => array_fill_keys(EA_STATUS_ORDER, 0), 'region' => []];
    }

    $hasCheckins  = ea_table_exists($pdo, 'checkins');
    $hasRegionCol = ea_column_exists($pdo, 'users', 'region_id');
    $hasRegions   = ea_table_exists($pdo, 'regions');

    $in = implode(',', array_fill(0, count($eventIds), '?'));

    // Matched attendees (roster check-in ∪ QR check-in with a resolved profile).
    $unionSql = "SELECT event_id, user_id FROM attendance WHERE status = 'Present' AND event_id IN ($in)";
    $unionParams = $eventIds;
    if ($hasCheckins) {
        $unionSql .= " UNION SELECT event_id, user_id FROM checkins WHERE user_id IS NOT NULL AND event_id IN ($in)";
        $unionParams = array_merge($unionParams, $eventIds);
    }

    $regionSelect = ($hasRegionCol && $hasRegions) ? "r.name AS region_name" : "NULL AS region_name";
    $regionJoin   = ($hasRegionCol && $hasRegions) ? "LEFT JOIN regions r ON r.id = u.region_id" : "";

    $sql = "
        SELECT ea.event_id, u.id AS user_id, u.gender, u.spiritual_status, $regionSelect
        FROM ($unionSql) ea
        JOIN users u ON u.id = ea.user_id
        $regionJoin
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($unionParams);

    $genderTotals = ['Male' => 0, 'Female' => 0, 'Unknown' => 0];
    $statusTotals = array_fill_keys(EA_STATUS_ORDER, 0);
    $regionTotals = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eid = $row['event_id'];
        if (!isset($per[$eid])) continue;
        if (in_array($row['user_id'], $per[$eid]['user_ids'], true)) continue; // dedupe overlap
        $per[$eid]['user_ids'][] = $row['user_id'];
        $per[$eid]['total']++;

        $g = ($row['gender'] === 'Male' || $row['gender'] === 'Female') ? $row['gender'] : 'Unknown';
        if ($g === 'Male') $per[$eid]['male']++; elseif ($g === 'Female') $per[$eid]['female']++; else $per[$eid]['unknown_gender']++;
        $genderTotals[$g]++;

        $st = ea_norm_status($row['spiritual_status']);
        $per[$eid]['status'][$st]++;
        $statusTotals[$st]++;

        $rn = trim((string)($row['region_name'] ?? ''));
        $rn = $rn === '' ? 'Unassigned' : $rn;
        $per[$eid]['region'][$rn] = ($per[$eid]['region'][$rn] ?? 0) + 1;
        $regionTotals[$rn] = ($regionTotals[$rn] ?? 0) + 1;
    }

    // Attendees who checked in with NO resolvable profile at all (should be ~0
    // for Sunday/Midweek since roster check-in always has a user_id, but a
    // stray QR walk-in is still counted honestly instead of being dropped).
    if ($hasCheckins) {
        $stmt2 = $pdo->prepare("SELECT event_id, COUNT(DISTINCT phone) AS c FROM checkins WHERE user_id IS NULL AND event_id IN ($in) GROUP BY event_id");
        $stmt2->execute($eventIds);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $eid = $row['event_id']; $c = (int)$row['c'];
            if (!isset($per[$eid]) || $c <= 0) continue;
            $per[$eid]['total'] += $c;
            $per[$eid]['unknown_gender'] += $c;
            $per[$eid]['status']['Not_On_File'] += $c;
            $per[$eid]['region']['Not on file'] = ($per[$eid]['region']['Not on file'] ?? 0) + $c;
            $genderTotals['Unknown'] += $c;
            $statusTotals['Not_On_File'] += $c;
            $regionTotals['Not on file'] = ($regionTotals['Not on file'] ?? 0) + $c;
        }
    }

    foreach ($per as &$p) { unset($p['user_ids']); arsort($p['region']); }
    arsort($regionTotals);

    return ['per_event' => $per, 'gender' => $genderTotals, 'status' => $statusTotals, 'region' => $regionTotals];
}

/** Check-in pace (by hour of day) for one event, from attendance.check_in_time. */
function ea_checkin_pace(PDO $pdo, int $eventId): array {
    $hasCheckins = ea_table_exists($pdo, 'checkins');
    $hasCheckedAt = $hasCheckins && ea_column_exists($pdo, 'checkins', 'checked_in_at');

    $sql = "SELECT HOUR(check_in_time) AS h, COUNT(*) AS c FROM attendance WHERE event_id = ? AND status='Present' AND check_in_time IS NOT NULL GROUP BY HOUR(check_in_time)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $hours = array_fill(0, 24, 0);
    $any = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $hours[(int)$row['h']] += (int)$row['c']; $any = true; }

    if ($hasCheckedAt) {
        $stmt2 = $pdo->prepare("SELECT HOUR(checked_in_at) AS h, COUNT(*) AS c FROM checkins WHERE event_id = ? AND checked_in_at IS NOT NULL GROUP BY HOUR(checked_in_at)");
        $stmt2->execute([$eventId]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) { $hours[(int)$row['h']] += (int)$row['c']; $any = true; }
    }

    if (!$any) return ['has_data' => false, 'hours' => []];

    // Trim leading/trailing empty hours for a tighter chart.
    $firstH = null; $lastH = null;
    foreach ($hours as $h => $c) { if ($c > 0) { if ($firstH === null) $firstH = $h; $lastH = $h; } }
    if ($firstH === null) return ['has_data' => false, 'hours' => []];
    $firstH = max(0, $firstH - 1); $lastH = min(23, $lastH + 1);

    $out = [];
    for ($h = $firstH; $h <= $lastH; $h++) {
        $label = date('g A', mktime($h, 0, 0));
        $out[] = ['label' => $label, 'count' => $hours[$h]];
    }
    return ['has_data' => true, 'hours' => $out];
}

/** Full attendee roster for one event (for the collapsible "View Roster" table). */
function ea_event_roster(PDO $pdo, int $eventId): array {
    $hasCheckins  = ea_table_exists($pdo, 'checkins');
    $hasRegionCol = ea_column_exists($pdo, 'users', 'region_id');
    $hasRegions   = ea_table_exists($pdo, 'regions');
    $regionSelect = ($hasRegionCol && $hasRegions) ? "r.name" : "NULL";
    $regionJoin   = ($hasRegionCol && $hasRegions) ? "LEFT JOIN regions r ON r.id = u.region_id" : "";

    $unionSql = "SELECT user_id, check_in_time AS marked_at FROM attendance WHERE event_id = ? AND status = 'Present'";
    $params = [$eventId];
    if ($hasCheckins) {
        $unionSql .= " UNION SELECT user_id, checked_in_at AS marked_at FROM checkins WHERE event_id = ? AND user_id IS NOT NULL";
        $params[] = $eventId;
    }

    $sql = "
        SELECT u.id, u.first_name, u.last_name, u.gender, u.spiritual_status, u.phone, $regionSelect AS region_name,
               MIN(ea.marked_at) AS marked_at
        FROM ($unionSql) ea
        JOIN users u ON u.id = ea.user_id
        $regionJoin
        GROUP BY u.id
        ORDER BY u.first_name ASC, u.last_name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($hasCheckins) {
        $stmt2 = $pdo->prepare("SELECT full_name, phone, checked_in_at FROM checkins WHERE event_id = ? AND user_id IS NULL ORDER BY full_name ASC");
        $stmt2->execute([$eventId]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $rows[] = [
                'id' => null, 'first_name' => $g['full_name'], 'last_name' => '', 'gender' => null,
                'spiritual_status' => 'Not_On_File', 'phone' => $g['phone'], 'region_name' => null, 'marked_at' => $g['checked_in_at'],
            ];
        }
    }
    return $rows;
}

/** Bucket key for a date, by granularity. */
function ea_bucket_key(string $dateStr, string $granularity): string {
    $ts = strtotime($dateStr);
    if ($granularity === 'month') return date('Y-m', $ts);
    if ($granularity === 'week') {
        // Monday-start week, computed by plain day arithmetic (no ambiguous
        // "monday this week" relative-format edge cases).
        $dow = (int) date('N', $ts); // 1 (Mon) .. 7 (Sun)
        $monday = strtotime('-' . ($dow - 1) . ' days', $ts);
        return date('Y-m-d', $monday);
    }
    return date('Y-m-d', $ts) . '#' . $dateStr; // 'service' granularity: one bucket per event
}

function ea_bucket_label(string $key, string $granularity): string {
    if ($granularity === 'month') return date('M Y', strtotime($key . '-01'));
    if ($granularity === 'week') return 'Wk of ' . date('M j', strtotime($key));
    $parts = explode('#', $key);
    return date('M j, Y', strtotime($parts[0]));
}

function ea_pct_change(float $from, float $to): ?float {
    if ($from == 0) return $to == 0 ? 0.0 : null; // null = "new" (can't express % from zero)
    return round((($to - $from) / $from) * 100, 1);
}
