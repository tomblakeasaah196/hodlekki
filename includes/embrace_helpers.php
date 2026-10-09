<?php
// /includes/embrace_helpers.php
// Shared Embrace (first-timer follow-up) data helpers, used by the JSON API
// and by the branded Excel register so both show exactly the same notes.

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
