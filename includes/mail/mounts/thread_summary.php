<?php
/**
 * The AI summary card (PR 4; docs/MAIL.md §7e), above the messages: the stored summary of the conversation, and
 * Summarize where AI is on for the mailbox (Ai::available). Hidden when there is neither. Filled by
 * assets/ui/js/pages/mail-team.js from api/mail_team_api.php. AI only summarises; it never sends.
 *
 * In scope (Partial::mount): $user_id, $account_id, $folder, $thread_id, $shared.
 */

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}
?>
<section class="ai-card" data-ai-summary aria-label="Summary" hidden>
    <div class="ai-head">
        <span class="ai-title"><?= ui_icon('sparkles', 'ui-icon--sm') ?> Summary</span>
        <span class="ai-meta" data-ai-meta></span>
        <?= mail_info('Written by AI from this conversation. Check it against the messages. AI never sends anything.') ?>
        <span class="mt-grow" aria-hidden="true"></span>
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-ai-summarise hidden>Summarize</button>
    </div>
    <div class="ai-body" data-ai-body></div>
</section>
<?= ui_json_script('mail-team', ['api' => '/api/mail_team_api.php', 'idleMinutes' => 5]) ?>
