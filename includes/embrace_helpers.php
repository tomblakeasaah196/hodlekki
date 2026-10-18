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
function embrace_notes_for_visitors(PDO $pdo, array $visitorIds): array
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
               f.visitor_id, u.first_name, u.last_name
        FROM embrace_followup_notes n
        JOIN embrace_followups f ON f.id = n.followup_id
        LEFT JOIN users u ON u.id = n.author_id
        WHERE f.visitor_id IN ($placeholders)
        ORDER BY n.created_at ASC, n.id ASC
    ");
    $stmt->execute($ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $visitorId = (int) $row['visitor_id'];
        unset($row['visitor_id']);
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
