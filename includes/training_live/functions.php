<?php
// /includes/training_live/functions.php
/**
 * The few global hooks PR 1's Trainings code calls, each guarded by function_exists() there, so Trainings works
 * the same with or without live classes on Meet.
 */

use HOD\Integrations\Google\Google;
use HOD\TrainingLive\Live;

/** The Academy's Meet page of a live item: its link, attendance and "Needs a look" */
function training_live_url(int $itemId): string
{
    return '/modules/academy/live?item=' . $itemId;
}

/**
 * After api/trainings_api.php ran a builder action: Meets follow their items (moved, retitled, made in person,
 * removed). Never fails the action: a Google problem is kept on the meeting and shown on its page.
 *
 * Saving an online class (owner, 19 Nov 2026) also makes its Meet link when it has a time and none yet, and
 * returns ['live' => ['page' => its Meet page, 'meet_url' => …, 'warning' => …]] for the builder, which then
 * opens that page. $out is the action's reply (a new item's id is there).
 */
function training_live_after_action(PDO $pdo, string $action, array $in, array $out = []): ?array
{
    if (!in_array($action, ['item_save', 'item_delete', 'section_delete', 'settings', 'delete'], true) || !Live::ready($pdo)) {
        return null;
    }
    try {
        Live::reconcileTraining($pdo, (int) ($in['training_id'] ?? 0));
    } catch (Throwable $e) {
        error_log('[trainings] Meet follow-up after ' . $action . ' failed: ' . get_class($e));
    }
    if ($action !== 'item_save') {
        return null;
    }
    $itemId = (int) ($out['id'] ?? ($in['id'] ?? 0));
    $ctx = $itemId > 0 ? Live::context($pdo, $itemId) : null;
    if ($ctx === null || ($ctx[0]['details']['mode'] ?? '') !== 'online') {
        return null;
    }
    $live = ['page' => training_live_url($itemId)];
    try {
        $m = Live::meeting($pdo, $itemId);
        if (($m === null || $m['status'] !== 'active') && Live::window($ctx[0]) !== null && Google::status()['state'] === 'connected') {
            $r = Live::createMeet($pdo, $itemId, (int) ($_SESSION['user_id'] ?? 0));
            if (!empty($r['ok'])) {
                $live['meet_url'] = $r['meet_url'] ?? null;
                if (!empty($r['warning'])) {
                    $live['warning'] = $r['warning'];
                }
            } else {
                $live['warning'] = (string) ($r['message'] ?? 'The Meet link was not made.');
            }
        }
    } catch (Throwable $e) {
        error_log('[trainings] Meet link on save failed: ' . get_class($e));
        $live['warning'] = 'The Meet link was not made. Try Create Meet link on this page.';
    }

    return ['live' => $live];
}

/** The daily run (cron/trainings_daily.php): attendance of yesterday's classes. Returns how many were read. */
function training_live_daily(PDO $pdo): int
{
    return Live::ready($pdo) ? Live::syncDue($pdo) : 0;
}

/** The 5-minute beat (cron/automation_tick.php): guest lists of classes that invite their learners. */
function training_live_tick(PDO $pdo): int
{
    return Live::ready($pdo) && Google::status()['state'] === 'connected' ? Live::syncGuestsDue($pdo) : 0;
}
