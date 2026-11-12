<?php
/**
 * The conversation list of the Mail page (docs/MAIL.md §7c): count and pages, rows with their badges, the empty
 * states, and the way past the ERP's 12 months (older mail, server search). Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $list (ThreadList result), $q (search), $filter, $openId (open thread id or 0)
 */

use HOD\Mail\People;
use HOD\Mail\Read\Format;
use HOD\Mail\Read\ThreadList;
use HOD\Mail\Store;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$folder = $inbox->folder;
$role = $folder['role'] ?? null;
$label = $folder ? Store::folderLabel($folder) : 'this folder';
$total = $list['total'];
$page = $list['page'];
$per = ThreadList::PER_PAGE;
$lastPage = max(1, (int) ceil($total / $per));
$searching = $q !== '';
$toLine = in_array($role, ['sent', 'drafts'], true);
$shared = $inbox->shared();
$labels = $inbox->labels();
$mailbox = $inbox->account['email'] ?? '';
?>
<div class="mr-list-meta">
    <span class="mr-list-count" aria-live="polite">
        <?php if ($searching): ?>
            <?= ui_e(Format::count($total, 'match', 'matches')) ?>
        <?php elseif ($total): ?>
            <?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?> of <?= $total ?>
        <?php else: ?>
            <?= ui_e($label) ?>
        <?php endif; ?>
    </span>
    <span class="mr-pager">
        <?php if ($inbox->allows('organise') && in_array($role, ['trash', 'spam'], true) && !$searching && $total): ?>
            <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="empty">Empty <?= $role === 'trash' ? 'Trash' : 'Spam' ?></button>
        <?php endif; ?>
        <?php if ($lastPage > 1): ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-page="<?= $page - 1 ?>"<?= $page <= 1 ? ' disabled' : '' ?> aria-label="Newer" title="Newer"><?= Format::icon('chevron-left') ?></button>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-page="<?= $page + 1 ?>"<?= $page >= $lastPage ? ' disabled' : '' ?> aria-label="Older" title="Older"><?= Format::icon('chevron-right') ?></button>
        <?php endif; ?>
    </span>
</div>

<?php if (!$list['rows']): ?>
    <div class="mr-empty">
        <?php if ($searching): ?>
            <p class="mr-empty-title">No match for “<?= ui_e(mb_strimwidth($q, 0, 60, '…')) ?>”</p>
            <p>The ERP keeps 12 months. Search the server below.</p>
        <?php elseif ($filter !== 'all'): ?>
            <p class="mr-empty-title">Nothing with this filter</p>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-filter="all">Show all</button>
        <?php elseif ($folder && !$folder['initial_synced_at']): ?>
            <p class="mr-empty-title">Copying this folder</p>
            <p>Newest mail comes first. The list updates itself.</p>
        <?php else: ?>
            <p class="mr-empty-title"><?= ui_e($label) ?> is empty</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <ul class="mr-rows" role="list">
        <?php foreach ($list['rows'] as $t):
            $unread = $t['row_unread'] > 0;
            $active = $t['id'] === $openId;
            $names = Format::people($t['participants'], $mailbox) ?: $mailbox;
            $tags = [];
            if ($searching) {
                foreach ($t['row_folders'] as $fid) {
                    if (isset($labels[$fid]) && !in_array($labels[$fid], $tags, true)) {
                        $tags[] = $labels[$fid];
                    }
                }
                $tags = array_slice($tags, 0, 2);
            }
            $href = '?box=' . (int) $inbox->account['id'] . ($folder ? '&folder=' . rawurlencode($inbox->key($folder)) : '') . '&thread=' . (int) $t['id'];
            ?>
            <li>
                <a class="mr-row<?= $unread ? ' is-unread' : '' ?><?= $active ? ' is-active' : '' ?>" href="<?= ui_e($href) ?>" data-thread="<?= (int) $t['id'] ?>"<?= $active ? ' aria-current="true"' : '' ?>>
                    <span class="mr-row-from"><?= $toLine ? 'To: ' : '' ?><?= ui_e($names) ?><?php if ($t['message_count'] > 1): ?><span class="mr-row-n"><?= (int) $t['message_count'] ?></span><?php endif; ?></span>
                    <span class="mr-row-date" title="<?= ui_e(Format::full($t['row_last_at'])) ?>"><?= ui_e(Format::when($t['row_last_at'])) ?></span>
                    <span class="mr-row-subject"><?php if ($unread): ?><span class="sr-only">Unread: </span><?php endif; ?><?= ui_e($t['subject'] ?: '(no subject)') ?></span>
                    <span class="mr-row-snippet"><?= ui_e($t['snippet'] ?? '') ?></span>
                    <span class="mr-row-badges">
                        <?php foreach ($tags as $tag): ?><span class="mr-tag"><?= ui_e($tag) ?></span><?php endforeach; ?>
                        <?php if ($t['row_draft'] && $role !== 'drafts'): ?><span class="mr-tag mr-tag--draft">Draft</span><?php endif; ?>
                        <?php if ($shared && $t['status'] === 'waiting'): ?><?= ui_badge('Waiting', 'warning') ?><?php endif; ?>
                        <?php if ($shared && $t['status'] === 'done'): ?><?= ui_badge('Done', 'success') ?><?php endif; ?>
                        <?php if ($shared && $t['assigned_to']): ?><span class="mr-tag" title="Assigned to <?= ui_e(People::name($t['assigned_to'])) ?>"><?= Format::icon('user-check', 'ui-icon--xs') ?> <?= $t['assigned_to'] === $inbox->userId ? 'You' : ui_e(People::firstName($t['assigned_to'])) ?></span><?php endif; ?>
                        <?php if ($t['row_attach']): ?><span title="Attachment"><?= Format::icon('paperclip', 'ui-icon--xs') ?><span class="sr-only">Attachment</span></span><?php endif; ?>
                        <?php if ($t['row_flagged']): ?><span class="mr-flag" title="Flagged"><?= Format::icon('flag', 'ui-icon--xs') ?><span class="sr-only">Flagged</span></span><?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div data-live></div>

<?php if ($folder): ?>
    <div class="mr-list-foot" data-list-foot>
        <?php if ($searching): ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="server-search"><?= Format::icon('server') ?> Search server</button>
            <?= mail_info('Searches all of ' . $label . ' on the mail server, at every date. It takes a little longer.') ?>
        <?php elseif ($page >= $lastPage && ($folder['initial_synced_at'] || $total)): ?>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="older"><?= Format::icon('history') ?> Load older</button>
            <?= mail_info('The ERP keeps the last 12 months. Older mail is read from the server and not kept.') ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
