<?php
// /includes/embrace_helpers.php
// Shared Embrace (first-timer follow-up) data helpers, used by the JSON API
// and by the branded Excel register so both show exactly the same notes.

require_once __DIR__ . '/status_ladder.php';

// A follow-up with one of these statuses is a final report: the worker reached
// the person, or tried and could not. Anything else (Pending, In_Progress) is
// still owed.
const EMBRACE_FINAL_FOLLOWUP_STATUSES = ['Completed', 'Unreachable'];

/**
 * SQL condition for "this person is in the Embrace pipeline" (the active
 * list), over users aliased $alias. The Archive is everyone else with a
 * follow-up record, so every screen that asks must ask here.
 *
 * In the pipeline are:
 *   - everyone currently a 1st, 2nd or 3rd Timer; and
 *   - a Visitor the Sunday ladder graduated (their latest status change is the
 *     ladder's move to Visitor) whose latest follow-up is not a final report
 *     yet -- including someone who was never assigned a worker. Without this
 *     the ladder would send a person to the Archive the moment they passed
 *     3rd Timer, however long they had waited for a call.
 *
 * An ordinary Visitor (added through Congregation, say) is never pulled in:
 * only the ladder's own record qualifies. Before db/migrate.php has created
 * the history table this is just the three timer statuses.
 */
function embrace_pipeline_sql(PDO $pdo, string $alias = 'u'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('embrace_pipeline_sql takes a table alias.');
    }
    $timers = "{$alias}.spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer')";
    if (!ladder_ready($pdo)) {
        return "({$timers})";
    }
    $final = "'" . implode("', '", EMBRACE_FINAL_FOLLOWUP_STATUSES) . "'";
    return "({$timers} OR ({$alias}.spiritual_status = '" . LADDER_TOP_STATUS . "'
        AND EXISTS (SELECT 1 FROM user_status_history pl_h
                     WHERE pl_h.user_id = {$alias}.id
                       AND pl_h.source = '" . LADDER_SOURCE . "'
                       AND pl_h.to_status = '" . LADDER_TOP_STATUS . "'
                       AND pl_h.id = (SELECT MAX(pl_h2.id) FROM user_status_history pl_h2 WHERE pl_h2.user_id = {$alias}.id))
        AND NOT EXISTS (SELECT 1 FROM embrace_followups pl_f
                         WHERE pl_f.visitor_id = {$alias}.id
                           AND pl_f.status IN ({$final})
                           AND pl_f.id = (SELECT MAX(pl_f2.id) FROM embrace_followups pl_f2 WHERE pl_f2.visitor_id = {$alias}.id))))";
}

/**
 * Every follow-up note logged on each of the given people, across ALL of
 * their follow-up cycles, oldest first.
 *
 * A reassignment inserts a new embrace_followups row, so notes written before
 * it are attached to an older followup_id. Reading only the latest followup
 * (as the screen used to) silently hid those notes. Returns
 * [visitor_id => notes[]]. Visibility is not filtered: every note is shown to
 * every user with Embrace access, including notes flagged "Pastors" only.
 */
/**
 * May $viewerId read this note? Its author always; 'All' everyone; 'Pastors'
 * those who may read notes marked for pastors (pastoral_notes); 'Directors'
 * Embrace managers; 'Assigned_Worker' the worker on the follow-up, and
 * managers. visible_to is a JSON list (or one plain value from older rows).
 */
function embrace_note_visible(array $note, int $viewerId): bool
{
    if ((int) ($note['author_id'] ?? 0) === $viewerId) {
        return true;
    }
    $tags = json_decode((string) ($note['visible_to'] ?? ''), true);
    if (!is_array($tags)) {
        $tags = trim((string) ($note['visible_to'] ?? '')) === '' ? ['All'] : [(string) $note['visible_to']];
    }
    if ($tags === [] || in_array('All', $tags, true)) {
        return true;
    }
    $can = static fn (string $m, string $l): bool => function_exists('can') && can($m, $l);
    foreach ($tags as $tag) {
        if ($tag === 'Pastors' && $can('pastoral_notes', 'view')) {
            return true;
        }
        if ($tag === 'Directors' && $can('embrace', 'manage')) {
            return true;
        }
        if ($tag === 'Assigned_Worker'
            && ((int) ($note['assigned_worker_id'] ?? 0) === $viewerId || $can('embrace', 'manage'))) {
            return true;
        }
    }
    return false;
}

function embrace_notes_for_visitors(PDO $pdo, array $visitorIds, ?int $viewerId = null): array
{
    $ids = [];
    foreach ($visitorIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    $byVisitor = [];
    if (!$ids) {
        return $byVisitor;
    }

    $ids = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT n.id, n.followup_id, n.author_id, n.note_text, n.visible_to, n.created_at,
               f.visitor_id, f.assigned_worker_id, u.first_name, u.last_name
        FROM embrace_followup_notes n
        JOIN embrace_followups f ON f.id = n.followup_id
        LEFT JOIN users u ON u.id = n.author_id
        WHERE f.visitor_id IN ($placeholders)
        ORDER BY n.created_at ASC, n.id ASC
    ");
    $stmt->execute($ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($viewerId !== null && !embrace_note_visible($row, $viewerId)) {
            continue;
        }
        $visitorId = (int) $row['visitor_id'];
        unset($row['visitor_id'], $row['assigned_worker_id']);
        $byVisitor[$visitorId][] = $row;
    }

    return $byVisitor;
}

/**
 * Every distinct Embrace worker ever assigned to each of the given people,
 * oldest assignment first, as display names. Returns [visitor_id => string[]].
 */
function embrace_worker_history_for_visitors(PDO $pdo, array $visitorIds): array
{
    $ids = [];
    foreach ($visitorIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    $byVisitor = [];
    if (!$ids) {
        return $byVisitor;
    }

    $ids = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT f.visitor_id, f.assigned_worker_id, w.first_name, w.last_name
        FROM embrace_followups f
        LEFT JOIN users w ON w.id = f.assigned_worker_id
        WHERE f.visitor_id IN ($placeholders)
          AND f.assigned_worker_id IS NOT NULL
        ORDER BY f.id ASC
    ");
    $stmt->execute($ids);

    $seen = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $visitorId = (int) $row['visitor_id'];
        $workerId = (int) $row['assigned_worker_id'];
        if (isset($seen[$visitorId][$workerId])) {
            continue;
        }
        $seen[$visitorId][$workerId] = true;

        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name !== '') {
            $byVisitor[$visitorId][] = $name;
        }
    }

    return $byVisitor;
}

/**
 * When each person came to church, for the caller's context on the phone
 * (Embrace team, 24 Nov 2026): every service counts, each day named.
 *
 * Days come from assim_attendance_union_sql() (one per person per calendar
 * day, checkins and attendance together), so the count agrees with
 * Assimilation and the Sunday ladder; assim_attendance_named_sql() only puts
 * names on those days. Newest first, at most $limit days each; 'count' is
 * every day.
 *
 * @return array<int, array{last: ?string, since: string, count: int,
 *               days: list<array{date: string, services: list<string>}>}>
 */
function embrace_visits_for_visitors(PDO $pdo, array $visitorIds, int $limit = 30): array
{
    $ids = [];
    foreach ($visitorIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $out = [];
    if (!$ids) {
        return $out;
    }
    $ids = array_values($ids);
    $limit = max(1, $limit);

    $days = $pdo->query("
        SELECT x.user_id, x.attended_on
          FROM " . assim_attendance_union_sql([], $ids) . " x
         ORDER BY x.user_id, x.attended_on DESC
    ");
    $kept = $seen = [];
    while ($row = $days->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $row['user_id'];
        $date = substr((string) $row['attended_on'], 0, 10);
        if (isset($seen[$uid][$date])) {
            continue; // the union already de-duplicates; this guards a date stored with a time
        }
        $seen[$uid][$date] = true;
        if (!isset($out[$uid])) {
            $out[$uid] = ['last' => $date, 'since' => assim_since_words($date), 'count' => 0, 'days' => []];
        }
        $out[$uid]['count']++;
        if (count($out[$uid]['days']) < $limit) {
            $kept[$uid][$date] = count($out[$uid]['days']);
            $out[$uid]['days'][] = ['date' => $date, 'services' => []];
        }
    }
    if (!$out) {
        return $out;
    }

    $names = $pdo->query("
        SELECT n.user_id, n.attended_on, n.title, n.category
          FROM " . assim_attendance_named_sql(array_keys($out)) . " n
    ");
    while ($row = $names->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $row['user_id'];
        $at = $kept[$uid][substr((string) $row['attended_on'], 0, 10)] ?? null;
        if ($at === null) {
            continue;
        }
        $name = trim((string) ($row['title'] ?? ''));
        if ($name === '') {
            $name = trim(str_replace('_', ' ', (string) ($row['category'] ?? '')));
        }
        if ($name !== '' && !in_array($name, $out[$uid]['days'][$at]['services'], true)) {
            $out[$uid]['days'][$at]['services'][] = $name;
        }
    }
    return $out;
}

// The Connect card's optional columns: left blank, they are left OUT of the
// INSERT (embrace_connect_insert()), so each column keeps its own default.
const EMBRACE_CONNECT_OPTIONAL = ['middle_name', 'email', 'gender', 'dob', 'physical_address', 'latitude', 'longitude'];

/**
 * The Connect card's INSERT into users: every given column, but an optional
 * one (EMBRACE_CONNECT_OPTIONAL) only when the visitor filled it in. A blank
 * optional field is omitted rather than sent as NULL or '': the column's
 * default then applies, NULL where it allows NULL and 'To be updated' for
 * physical_address (NOT NULL DEFAULT 'To be updated' in the live schema,
 * where an explicit NULL failed with MySQL 1048 and the card was lost,
 * 12 Nov 2026). '' would break the ENUM, DATE and UNIQUE columns.
 *
 * @param array<string, mixed> $fields column => value (null or '' = not given)
 * @return array{0:string, 1:list<mixed>} the SQL and its parameters
 */
function embrace_connect_insert(array $fields, string $table = 'users'): array
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('Bad table name.');
    }
    $cols = [];
    $vals = [];
    foreach ($fields as $col => $value) {
        if (!is_string($col) || !preg_match('/^[a-z_]+$/', $col)) {
            throw new InvalidArgumentException('Bad column name.');
        }
        if (in_array($col, EMBRACE_CONNECT_OPTIONAL, true) && ($value === null || $value === '')) {
            continue;
        }
        $cols[] = $col;
        $vals[] = $value;
    }
    return ['INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals];
}
