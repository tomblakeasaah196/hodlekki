<?php
/**
 * Mail past the ERP's copy, read live from the server and not kept (docs/MAIL.md §7c): "Load older" (older than
 * 12 months, a page at a time) or "Search server". Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $mode ('older' | 'server'), $items (Reader::liveRow rows), $next (?int), $count (int)
 */

use HOD\Mail\Read\Format;
use HOD\Mail\Store;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$label = $inbox->folder ? Store::folderLabel($inbox->folder) : '';
?>
<div class="mr-live-head">
    <span>
        <?= Format::icon('server') ?>
        <?php if ($mode === 'older'): ?>
            Older mail, from the server
        <?php else: ?>
            Server: <?= ui_e(Format::count($count, 'match', 'matches')) ?> in <?= ui_e($label) ?>
        <?php endif; ?>
    </span>
    <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="close-live" aria-label="Hide these results" title="Hide"><?= Format::icon('x') ?></button>
</div>
<?php if (!$items): ?>
    <div class="mr-empty">
        <p class="mr-empty-title"><?= $mode === 'older' ? 'No older mail here' : 'Nothing on the server either' ?></p>
    </div>
<?php else: ?>
    <ul class="mr-rows" role="list">
        <?php foreach ($items as $item): ?>
            <li>
                <a class="mr-row<?= $item['is_seen'] ? '' : ' is-unread' ?>" href="#" data-live-uid="<?= (int) $item['uid'] ?>">
                    <span class="mr-row-from"><?= ui_e($item['from']) ?></span>
                    <span class="mr-row-date" title="<?= ui_e(Format::full($item['received_at'])) ?>"><?= ui_e(Format::when($item['received_at'])) ?></span>
                    <span class="mr-row-subject"><?= ui_e($item['subject'] ?: '(no subject)') ?></span>
                    <span class="mr-row-snippet"></span>
                    <span class="mr-row-badges">
                        <?php if ($item['has_attachments']): ?><span title="Attachment"><?= Format::icon('paperclip', 'ui-icon--xs') ?><span class="sr-only">Attachment</span></span><?php endif; ?>
                        <?php if ($item['is_flagged']): ?><span class="mr-flag" title="Flagged"><?= Format::icon('flag', 'ui-icon--xs') ?><span class="sr-only">Flagged</span></span><?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($mode === 'older' && $next): ?>
    <div class="mr-list-foot">
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="older" data-before="<?= (int) $next ?>"><?= Format::icon('history') ?> Load more</button>
    </div>
<?php endif; ?>
