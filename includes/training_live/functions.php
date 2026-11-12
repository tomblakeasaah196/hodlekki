<?php
// /includes/training_live/functions.php
/**
 * The few global hooks PR 1's Trainings code calls, each guarded by function_exists() there, so Trainings works
 * the same with or without live classes on Meet.
 */

use HOD\TrainingLive\Live;

/** The Academy's Meet page of a live item: its link, attendance and "Needs a look" */
function training_live_url(int $itemId): string
{
    return '/modules/academy/live?item=' . $itemId;
}

/**
 * After api/trainings_api.php ran a builder action: Meets follow their items (moved, retitled, made in person,
 * removed). Never fails the action: a Google problem is kept on the meeting and shown on its page.
 */
function training_live_after_action(PDO $pdo, string $action, array $in): void
{
    if (!in_array($action, ['item_save', 'item_delete', 'section_delete', 'settings', 'delete'], true) || !Live::ready($pdo)) {
        return;
    }
    try {
        Live::reconcileTraining($pdo, (int) ($in['training_id'] ?? 0));
    } catch (Throwable $e) {
        error_log('[trainings] Meet follow-up after ' . $action . ' failed: ' . get_class($e));
    }
}

/** The daily run (cron/trainings_daily.php): attendance of yesterday's classes. Returns how many were read. */
function training_live_daily(PDO $pdo): int
{
    return Live::ready($pdo) ? Live::syncDue($pdo) : 0;
}
