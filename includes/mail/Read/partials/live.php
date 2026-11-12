<?php
/**
 * An older message (past the ERP's 12 months) in the reader, read live from the server and not kept
 * (docs/MAIL.md §7c). Drawn by Read\Partial with: $inbox (Read\Inbox), $item (Reader::live row, or null when the
 * server no longer has it or did not answer), $uid
 */

use HOD\Mail\Read\Format;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}
?>
<div class="mr-toolbar" role="toolbar" aria-label="Message actions">
    <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-action="close" data-back aria-label="Back to list" title="Back to list (u)"><?= Format::icon('arrow-left') ?></button>
    <span class="mr-toolbar-note"><?= Format::icon('server') ?> Read from the server</span>
    <?= mail_info('Older than 12 months, so the ERP does not keep a copy. Replying to it is not possible yet.') ?>
</div>
<?php if (!$item): ?>
    <div class="mr-reader-pad">
        <div class="mr-alert" role="status">
            <?= Format::icon('circle-alert') ?>
            <span>The server did not send this message.</span>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-live-uid="<?= (int) $uid ?>">Try again</button>
        </div>
    </div>
<?php else:
    $body = $item['body'];
    $to = Format::people($item['to'], '', 4);
    ?>
    <header class="mr-subject-head">
        <h2 class="mr-subject" tabindex="-1"><?= ui_e($item['subject'] ?: '(no subject)') ?></h2>
    </header>
    <div class="mr-messages">
        <article class="mr-msg is-open" aria-label="Message from <?= ui_e($item['from']) ?>" data-live-message="<?= (int) $item['uid'] ?>">
            <div class="mr-msg-head mr-msg-head--static">
                <span class="mr-avatar" aria-hidden="true"><?= ui_e($item['initials']) ?></span>
                <span class="mr-msg-from"><strong><?= ui_e($item['from']) ?></strong><?php if ($item['from_email'] && $item['from_email'] !== $item['from']): ?> <span class="mr-msg-addr">&lt;<?= ui_e($item['from_email']) ?>&gt;</span><?php endif; ?></span>
                <span class="mr-msg-date"><?= ui_e($item['date_full']) ?></span>
                <span class="mr-msg-sub mr-msg-sub--open">to <?= ui_e($to ?: 'undisclosed recipients') ?></span>
            </div>
            <div class="mr-msg-open">
                <div class="mr-msg-info">
                    <?php if ($item['to']): ?><span><b>To</b> <?= ui_e(Format::addresses($item['to'])) ?></span><?php endif; ?>
                    <?php if ($item['cc']): ?><span><b>Cc</b> <?= ui_e(Format::addresses($item['cc'])) ?></span><?php endif; ?>
                    <span><b>In</b> <?= ui_e($item['folder']) ?></span>
                </div>
                <div class="mr-msg-body">
                    <?php if (($body['blocked'] ?? 0) > 0 && !$item['remote']): ?>
                        <div class="mr-images" role="status">
                            <?= Format::icon('image') ?>
                            <span>Pictures hidden</span>
                            <span class="mr-images-actions">
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="live-images">Show pictures</button>
                                <?php if ($item['from_email']): ?>
                                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="live-trust">Always show</button>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($body['kind'] === 'html'): ?>
                        <iframe class="mr-frame" title="Message from <?= ui_e($item['from']) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                referrerpolicy="no-referrer" src="<?= ui_e($body['src']) ?>"></iframe>
                    <?php elseif ($body['kind'] === 'text'): ?>
                        <?= $body['html'] /* escaped by TextFormatter */ ?>
                    <?php else: ?>
                        <p class="mr-muted">No text in this message.</p>
                    <?php endif; ?>
                    <?php if ($item['attachments']): ?>
                        <div class="mr-files" aria-label="Attachments">
                            <?php foreach ($item['attachments'] as $a): ?>
                                <span class="mr-file">
                                    <?= Format::icon('paperclip') ?>
                                    <span class="mr-file-name" title="<?= ui_e($a['filename']) ?>"><?= ui_e($a['filename']) ?></span>
                                    <span class="mr-file-size"><?= ui_e(Format::size((int) $a['size'])) ?></span>
                                    <?php if ($a['viewable']): ?>
                                        <a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="<?= ui_e($a['url']) ?>&amp;view=1" target="_blank" rel="noopener" aria-label="Open <?= ui_e($a['filename']) ?>" title="Open"><?= Format::icon('eye') ?></a>
                                    <?php endif; ?>
                                    <a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="<?= ui_e($a['url']) ?>" download aria-label="Download <?= ui_e($a['filename']) ?>" title="Download"><?= Format::icon('download') ?></a>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    </div>
<?php endif; ?>
