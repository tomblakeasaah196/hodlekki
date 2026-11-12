<?php
/**
 * The team bar (PR 4; docs/MAIL.md §7e), above the messages of a shared mailbox's conversation: status (Open,
 * Waiting, Done), who it is assigned to, who else is reading or replying, and its activity. Drawn once by
 * modules/mail/index.php (shared mailboxes only) and filled by assets/ui/js/pages/mail-team.js for each conversation
 * opened, from api/mail_team_api.php.
 *
 * In scope (Partial::mount): $user_id, $account_id, $folder, $thread_id, $shared.
 */

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}
?>
<section class="mt" data-team aria-label="Teamwork" hidden>
    <div class="mt-row">
        <div class="mt-status" role="group" aria-label="Status" data-team-status></div>
        <label class="sr-only" for="mt-assign">Assigned to</label>
        <select id="mt-assign" class="ui-select ui-select--sm mt-assign" data-team-assign hidden></select>
        <span class="mt-assignee" data-team-assignee></span>
        <span class="mt-grow" aria-hidden="true"></span>
        <button type="button" class="mr-link-btn" data-team-activity aria-expanded="false" aria-controls="mt-log">Activity</button>
    </div>
    <p class="mt-presence" data-team-presence aria-live="polite" hidden></p>
    <ol class="mt-log" id="mt-log" data-team-log hidden></ol>
</section>
