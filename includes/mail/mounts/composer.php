<?php
/**
 * The composer (PR 3; docs/MAIL.md §7d). Drawn once on the Mail page through HOD\Mail\Read\Partial::mount('composer'),
 * which also turns on every Reply, Reply all, Forward and New message button. Nothing shows until the browser event
 * mail:compose {mode, accountId, messageId?, outboxId?, to?} opens it: a modal dialog on a computer, full screen on
 * a phone. Driven by assets/ui/js/pages/mail-compose.js against api/mail_compose_api.php, which saves as the person
 * types; the signature, the quote and the From line are added when the message is built.
 *
 * In scope (Partial::mount): $user_id, $account_id, $folder, $thread_id, $shared.
 */

use HOD\Mail\Compose\ComposeConfig;
use HOD\Mail\Compose\Signatures;
use HOD\Mail\Read\Format;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$max = Format::size((int) ComposeConfig::get('max_attachment_bytes'));
$chips = static function (string $field, string $label): string {
    return '<div class="mc-row" data-row="' . $field . '"' . ($field !== 'to' ? ' hidden' : '') . '>'
        . '<label class="mc-label" for="mc-' . $field . '-input">' . $label . '</label>'
        . '<div class="mc-chips" data-chips="' . $field . '">'
        . '<ul class="mc-chip-list" data-chip-list aria-label="' . $label . ' recipients"></ul>'
        . '<input id="mc-' . $field . '-input" class="mc-chip-input" type="text" inputmode="email" autocomplete="off" spellcheck="false"'
        . ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mc-' . $field . '-list" aria-describedby="mc-' . $field . '-error">'
        . '<ul class="mc-suggest" id="mc-' . $field . '-list" role="listbox" aria-label="Suggestions" hidden></ul>'
        . '</div>'
        . ($field === 'to' ? '<button type="button" class="mc-link" data-mc="show-cc" aria-expanded="false">Cc, Bcc</button>' : '')
        . '<p class="mc-field-error" id="mc-' . $field . '-error" data-error-for="' . $field . '" hidden></p>'
        . '</div>';
};
$tool = static fn (string $cmd, string $icon, string $label, string $extra = ''): string =>
    '<button type="button" class="mc-tool" ' . $cmd . ' aria-label="' . ui_e($label) . '" title="' . ui_e($label) . '"' . $extra . '>' . ui_icon($icon, 'ui-icon--sm') . '</button>';
?>
<dialog class="mc-dialog" id="mail-composer" aria-labelledby="mc-title" data-mail-composer data-modal-ignore>
    <form class="mc" method="dialog" novalidate data-mc-form>
        <header class="mc-head">
            <h2 class="mc-title" id="mc-title">New message</h2>
            <p class="mc-saved" data-mc-saved aria-live="polite"></p>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-mc="close" aria-label="Close (draft kept)" title="Close (Esc)"><?= ui_icon('x') ?></button>
        </header>
        <div class="mc-scroll">
            <div class="mc-fields">
                <div class="mc-row" data-row="from">
                    <label class="mc-label" for="mc-from">From</label>
                    <select id="mc-from" class="mc-from" data-mc-from></select>
                    <p class="mc-field-error" data-error-for="account_id" hidden></p>
                </div>
                <?= $chips('to', 'To') . $chips('cc', 'Cc') . $chips('bcc', 'Bcc') ?>
                <div class="mc-row" data-row="subject">
                    <label class="mc-label" for="mc-subject">Subject</label>
                    <input id="mc-subject" class="mc-subject" type="text" maxlength="250" autocomplete="off" data-mc-subject>
                </div>
            </div>
            <div class="mc-toolbar" role="toolbar" aria-label="Formatting" data-mc-toolbar>
                <?= $tool('data-cmd="bold"', 'bold', 'Bold (Ctrl+B)') ?>
                <?= $tool('data-cmd="italic"', 'italic', 'Italic (Ctrl+I)') ?>
                <?= $tool('data-cmd="underline"', 'underline', 'Underline (Ctrl+U)') ?>
                <span class="mc-tool-sep" aria-hidden="true"></span>
                <?= $tool('data-cmd="insertUnorderedList"', 'list', 'Bullets') ?>
                <?= $tool('data-cmd="insertOrderedList"', 'list-ordered', 'Numbers') ?>
                <?= $tool('data-cmd="formatBlock"', 'text-quote', 'Quote', ' data-value="blockquote"') ?>
                <span class="mc-tool-sep" aria-hidden="true"></span>
                <?= $tool('data-mc="link"', 'link', 'Link (Ctrl+K)') ?>
                <?= $tool('data-cmd="removeFormat"', 'remove-formatting', 'Clear formatting') ?>
                <span class="mt-grow" aria-hidden="true"></span>
                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mc-ai-btn" data-mc="ai" aria-expanded="false" aria-controls="mc-ai" hidden><?= ui_icon('sparkles', 'ui-icon--sm') ?> AI draft</button>
            </div>
            <div class="mc-ai" id="mc-ai" data-mc-ai hidden>
                <div class="mc-ai-row">
                    <label class="mc-label" for="mc-ai-instruction">What to say</label>
                    <?= mail_info('AI writes in the church\'s tone: "Dear …", then "God bless you" and the team name. It adds scripture only if you write it here. Read and change the draft before you send; AI never sends.') ?>
                </div>
                <textarea id="mc-ai-instruction" class="ui-input" rows="2" maxlength="2000" data-mc-ai-instruction placeholder="Thank them; the retreat starts at 9am"></textarea>
                <p class="mc-field-error" data-error-for="instruction" hidden></p>
                <div class="mc-ai-actions">
                    <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-mc="ai-draft">Write draft</button>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-mc="ai-close">Close</button>
                </div>
                <div class="mc-ai-result" data-mc-ai-result hidden>
                    <div class="mc-ai-text" data-mc-ai-text></div>
                    <div class="mc-ai-actions">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-mc="ai-use">Use draft</button>
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-mc="ai-insert">Insert</button>
                    </div>
                </div>
            </div>
            <div class="mc-linkbar" data-mc-linkbar hidden>
                <label class="mc-label" for="mc-link-url">Link</label>
                <input id="mc-link-url" class="ui-input ui-input--sm" type="text" inputmode="url" placeholder="https://… or an email" autocomplete="off" spellcheck="false">
                <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-mc="link-apply">Add link</button>
                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-mc="link-cancel">Cancel</button>
            </div>
            <div class="mc-body" data-mc-drop>
                <div class="mc-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message" data-mc-editor></div>
                <?php /* In the order the message goes out: text, signature, then the original */ ?>
                <section class="mc-sig" aria-label="Signature" data-mc-sig>
                    <div class="mc-sig-bar">
                        <label class="mc-label" for="mc-sig-style">Signature</label>
                        <select id="mc-sig-style" class="ui-select ui-select--sm" data-mc-sig-style>
                            <?php foreach (Signatures::STYLES as $k => $label): ?>
                                <option value="<?= ui_e($k) ?>"><?= ui_e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a class="mc-link" href="/modules/mail/signature.php">My signature</a>
                    </div>
                    <div class="mc-sig-preview" data-mc-sig-preview></div>
                </section>
                <div class="mc-quote" data-mc-quote hidden>
                    <div class="mc-quote-bar">
                        <button type="button" class="mc-link" data-mc="toggle-quote" aria-expanded="false" aria-controls="mc-quote-body">Show original</button>
                        <label class="mc-check"><input type="checkbox" data-mc-quote-on checked> Include original</label>
                    </div>
                    <div class="mc-quote-body" id="mc-quote-body" hidden data-mc-quote-body></div>
                </div>
            </div>
            <ul class="mc-files" data-mc-files aria-label="Attachments"></ul>
            <p class="mc-field-error mc-files-error" data-error-for="attachments" hidden></p>
        </div>
        <p class="mc-error" role="alert" data-mc-error hidden></p>
        <footer class="mc-foot">
            <div class="mc-send">
                <button type="button" class="ui-btn ui-btn--primary mc-send-main" data-mc="send" title="Send (Ctrl+Enter)"><?= ui_icon('send', 'ui-icon--sm') ?> Send</button>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--icon mc-send-more" data-mc="schedule-menu" aria-haspopup="true" aria-expanded="false" aria-controls="mc-schedule" aria-label="Schedule" title="Schedule"><?= ui_icon('chevron-down', 'ui-icon--sm') ?></button>
                <div class="mc-schedule" id="mc-schedule" data-mc-schedule hidden>
                    <p class="mc-schedule-title">Schedule</p>
                    <button type="button" class="mc-schedule-opt" data-quick="tomorrow">Tomorrow 08:00</button>
                    <button type="button" class="mc-schedule-opt" data-quick="monday">Next Monday 08:00</button>
                    <div class="mc-schedule-pick">
                        <label class="mc-label" for="mc-schedule-at">Other time</label>
                        <input id="mc-schedule-at" class="ui-input ui-input--sm" type="datetime-local" data-mc-schedule-at>
                        <p class="mc-field-error" data-error-for="send_at" hidden></p>
                        <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-mc="schedule">Schedule</button>
                    </div>
                </div>
            </div>
            <label class="ui-btn ui-btn--ghost mc-attach" title="Attach files (<?= ui_e($max) ?> together)">
                <?= ui_icon('paperclip', 'ui-icon--sm') ?><span>Attach</span>
                <input type="file" multiple data-mc-file class="mc-file-input">
            </label>
            <span class="mc-grow" aria-hidden="true"></span>
            <button type="button" class="ui-btn ui-btn--danger-ghost" data-mc="discard"><?= ui_icon('trash-2', 'ui-icon--sm') ?> Delete</button>
        </footer>
        <div class="mc-confirm" data-mc-confirm hidden role="alertdialog" aria-labelledby="mc-confirm-t">
            <p id="mc-confirm-t">Delete this draft? It leaves the server's Drafts too.</p>
            <div class="mc-confirm-actions">
                <button type="button" class="ui-btn ui-btn--ghost" data-mc="discard-cancel">Keep draft</button>
                <button type="button" class="ui-btn ui-btn--danger" data-mc="discard-yes">Delete draft</button>
            </div>
        </div>
    </form>
</dialog>
<div class="mc-bar" data-mc-bar role="status" aria-live="polite" hidden>
    <span class="mc-bar-text" data-mc-bar-text></span>
    <button type="button" class="mc-bar-btn" data-mc-bar-action hidden></button>
    <button type="button" class="mc-bar-close" data-mc-bar-close aria-label="Close"><?= ui_icon('x', 'ui-icon--sm') ?></button>
</div>
<?= ui_json_script('mail-compose', [
    'api' => '/api/mail_compose_api.php',
    'accountId' => $account_id ?? null,
    'maxBytes' => (int) ComposeConfig::get('max_attachment_bytes'),
    'maxLabel' => $max,
    'undoSeconds' => (int) ComposeConfig::get('undo_seconds', 10),
    'teamApi' => '/api/mail_team_api.php',
]) ?>
