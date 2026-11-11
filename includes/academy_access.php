<?php
// /includes/academy_access.php
/**
 * Who may read and write a cohort's noticeboard (api/academy_api.php
 * fetch_forum / post_forum_message): Academy staff (the API's $is_admin —
 * pastors, Super Admins and the Academy department), students enrolled in
 * that batch, and the facilitators of the courses in that batch's programme.
 * Everyone else — including students of another cohort — gets nothing.
 */

function academy_forum_allowed(PDO $pdo, int $userId, bool $isStaff, $batchId): bool
{
    $batchId = (int) $batchId;
    if ($batchId <= 0 || $userId <= 0) {
        return false;
    }
    if ($isStaff) {
        return true;
    }

    $enrolled = $pdo->prepare('SELECT 1 FROM academy_enrollments WHERE user_id = ? AND batch_id = ? LIMIT 1');
    $enrolled->execute([$userId, $batchId]);
    if ($enrolled->fetchColumn()) {
        return true;
    }

    $facilitates = $pdo->prepare('
        SELECT 1 FROM academy_courses c
        JOIN academy_batches b ON b.program_id = c.program_id
        WHERE b.id = ? AND c.instructor_id = ?
        LIMIT 1
    ');
    $facilitates->execute([$batchId, $userId]);

    return (bool) $facilitates->fetchColumn();
}
