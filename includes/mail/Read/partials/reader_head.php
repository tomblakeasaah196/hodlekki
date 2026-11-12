<?php
/**
 * The top of the reader (docs/MAIL.md §7c): the toolbar (archive, spam, trash, move, unread, flag, delete for good,
 * and reply once the composer exists) and the conversation's subject. Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $thread (thread row), $items (Reader::item rows), $searching (bool)
 * PR 4's team bar sits just below this (its mount stays on the page).
 */

use HOD\Mail\Read\Format;
use HOD\Mail\Read\Partial;
use HOD\Mail\Store;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$role = $inbox->folder['role'] ?? null;
$organise = $inbox->allows('organise');
$reply = $inbox->allows('send') && Partial::mounted('composer');
$latest = $items ? end($items) : null;
$flagged = (bool) array_filter($items, fn ($m) => $m['is_flagged']);
$folded = count(array_filter($items, fn ($m) => !$m['open']));
$btn = fn (string $action, string $icon, string $label, string $key = '', string $extra = '') =>
    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-action="' . $action . '" aria-label="' . ui_e($label) . '" title="' . ui_e($label . ($key !== '' ? " ({$key})" : '')) . '"' . $extra . '>' . Format::icon($icon) . '</button>';
?>
<div class="mr-toolbar" role="toolbar" aria-label="Conversation actions">
    <?= $btn('close', 'arrow-left', 'Back to list', 'u', ' data-back') ?>
    <?php if ($organise): ?>
        <?php if (!in_array($role, ['archive', 'trash', 'spam'], true)): ?>
            <?= $btn('archive', 'archive', 'Archive', 'e') ?>
        <?php endif; ?>
        <?php if ($role === 'spam'): ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="inbox"><?= Format::icon('inbox') ?> Not spam</button>
        <?php else: ?>
            <?= $btn('spam', 'shield-alert', 'Move to Spam', '!') ?>
        <?php endif; ?>
        <?php if (in_array($role, ['trash', 'archive'], true)): ?>
            <?= $btn('inbox', 'inbox', 'Move to Inbox') ?>
        <?php endif; ?>
        <?php if (in_array($role, ['trash', 'spam'], true)): ?>
            <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="delete" title="Delete for good (#)"><?= Format::icon('trash-2') ?> Delete</button>
        <?php else: ?>
            <?= $btn('trash', 'trash-2', 'Move to Trash', '#') ?>
        <?php endif; ?>
        <label class="sr-only" for="mr-move">Move to folder</label>
        <select id="mr-move" class="ui-select ui-select--sm mr-move" data-move>
            <option value="">Move to…</option>
            <?php foreach ($inbox->folders as $f): ?>
                <?php if (!$inbox->folder || $f['id'] !== $inbox->folder['id']): ?>
                    <option value="<?= (int) $f['id'] ?>"><?= ui_e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f)) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <span class="mr-sep" aria-hidden="true"></span>
    <?php endif; ?>
    <?= $btn('unread', 'mail', 'Mark unread', 'Shift+U') ?>
    <?php if ($organise): ?>
        <?= $btn($flagged ? 'unflag' : 'flag', 'flag', $flagged ? 'Remove flag' : 'Flag', 's', ' aria-pressed="' . ($flagged ? 'true' : 'false') . '"' . ($flagged ? ' data-on' : '')) ?>
    <?php endif; ?>
    <span class="mr-grow"></span>
    <?php if ($reply && $latest): ?>
        <?php if ($latest['is_draft']): ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-compose="draft" data-message="<?= (int) $latest['id'] ?>"><?= Format::icon('pencil') ?> Edit draft</button>
        <?php else: ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-compose="reply" data-message="<?= (int) $latest['id'] ?>" aria-label="Reply" title="Reply (r)"><?= Format::icon('reply') ?></button>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-compose="reply_all" data-message="<?= (int) $latest['id'] ?>" aria-label="Reply all" title="Reply all (a)"><?= Format::icon('reply-all') ?></button>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-compose="forward" data-message="<?= (int) $latest['id'] ?>" aria-label="Forward" title="Forward (f)"><?= Format::icon('forward') ?></button>
        <?php endif; ?>
    <?php endif; ?>
</div>
<header class="mr-subject-head">
    <h2 class="mr-subject" tabindex="-1"><?= ui_e($thread['subject'] ?: '(no subject)') ?></h2>
    <div class="mr-subject-meta">
        <?php if (count($items) > 1): ?><span class="mr-tag"><?= count($items) ?> messages</span><?php endif; ?>
        <?php if ($inbox->shared() && $thread['status'] === 'waiting'): ?><?= ui_badge('Waiting', 'warning') ?><?php endif; ?>
        <?php if ($inbox->shared() && $thread['status'] === 'done'): ?><?= ui_badge('Done', 'success') ?><?php endif; ?>
        <?php if ($flagged): ?><span class="mr-tag mr-tag--flag"><?= Format::icon('flag', 'ui-icon--xs') ?> Flagged</span><?php endif; ?>
        <?php if ($folded > 0): ?>
            <button type="button" class="mr-link-btn" data-action="open-all">Open all (<?= count($items) ?>)</button>
        <?php endif; ?>
    </div>
</header>
