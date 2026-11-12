<?php
/**
 * One message in the open conversation (docs/MAIL.md §7c): folded (sender, date, first words) or open (people,
 * "Opened by", the body made safe, pictures held back, attachments, reply buttons once the composer exists).
 * Drawn by Read\Partial with: $inbox (Read\Inbox), $m (Reader::item row)
 * HTML bodies load in a sandboxed frame without scripts, from api/mail_view.php (its own CSP); text bodies are
 * already escaped by TextFormatter.
 */

use HOD\Mail\Read\Format;
use HOD\Mail\Read\Partial;

if (!defined('HOD_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$body = $m['body'];
$reply = $inbox->allows('send') && Partial::mounted('composer');
$to = Format::people($m['to'], '', 4);
$cc = Format::people($m['cc'], '', 3);
?>
<article class="mr-msg<?= $m['open'] ? ' is-open' : '' ?><?= $m['is_unread'] ? ' is-unread' : '' ?>" data-message="<?= (int) $m['id'] ?>" data-loaded="<?= $body !== null ? '1' : '' ?>" aria-label="Message from <?= ui_e($m['from']) ?>">
    <button type="button" class="mr-msg-head" data-action="toggle" aria-expanded="<?= $m['open'] ? 'true' : 'false' ?>">
        <span class="mr-avatar" aria-hidden="true"><?= ui_e($m['initials']) ?></span>
        <span class="mr-msg-from"><strong><?= ui_e($m['from']) ?></strong><?php if ($m['from_email'] && $m['from_email'] !== $m['from']): ?> <span class="mr-msg-addr">&lt;<?= ui_e($m['from_email']) ?>&gt;</span><?php endif; ?></span>
        <span class="mr-msg-date" title="<?= ui_e($m['date_full']) ?>">
            <?php if ($m['is_flagged']): ?><span class="mr-flag"><?= Format::icon('flag', 'ui-icon--xs') ?><span class="sr-only">Flagged</span></span><?php endif; ?>
            <?php if ($m['attachments']): ?><span class="mr-msg-clip"><?= Format::icon('paperclip', 'ui-icon--xs') ?><span class="sr-only">Attachment</span></span><?php endif; ?>
            <?= ui_e($m['date']) ?>
        </span>
        <span class="mr-msg-sub mr-msg-sub--open">to <?= ui_e($to ?: 'undisclosed recipients') ?><?= $cc !== '' ? ', cc ' . ui_e($cc) : '' ?></span>
        <span class="mr-msg-sub mr-msg-sub--folded"><?= ui_e($m['snippet']) ?></span>
    </button>

    <div class="mr-msg-open" data-message-body>
        <?php if ($body !== null): ?>
            <div class="mr-msg-info">
                <span><b>Date</b> <?= ui_e($m['date_full']) ?></span>
                <?php if ($m['to']): ?><span><b>To</b> <?= ui_e(Format::addresses($m['to'])) ?></span><?php endif; ?>
                <?php if ($m['cc']): ?><span><b>Cc</b> <?= ui_e(Format::addresses($m['cc'])) ?></span><?php endif; ?>
                <?php if ($m['folder']): ?><span><b>In</b> <?= ui_e($m['folder']) ?></span><?php endif; ?>
                <?php if ($m['sent_by']): ?><span><b>Sent by</b> <?= ui_e($m['sent_by']) ?></span><?php endif; ?>
                <?php if ($m['opened_by']): ?><span><b>Opened by</b> <?= ui_e($m['opened_by']) ?></span><?php endif; ?>
            </div>

            <div class="mr-msg-body">
                <?php if (($body['blocked'] ?? 0) > 0 && !$m['remote']): ?>
                    <div class="mr-images" role="status">
                        <?= Format::icon('image') ?>
                        <span>Pictures hidden</span>
                        <?= mail_info('Pictures from the internet can tell the sender you opened the message. They stay hidden until you choose.') ?>
                        <span class="mr-images-actions">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="images">Show pictures</button>
                            <?php if ($m['from_email']): ?>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="trust">Always show</button>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($body['kind'] === 'html'): ?>
                    <iframe class="mr-frame" title="Message from <?= ui_e($m['from']) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                            referrerpolicy="no-referrer" src="<?= ui_e($body['src']) ?>"></iframe>
                <?php elseif ($body['kind'] === 'text'): ?>
                    <?= $body['html'] /* escaped by TextFormatter */ ?>
                <?php elseif ($body['kind'] === 'error'): ?>
                    <div class="mr-alert" role="status">
                        <?= Format::icon('circle-alert') ?>
                        <span>The server did not send this message.</span>
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="reload-message">Try again</button>
                    </div>
                <?php else: ?>
                    <p class="mr-muted">No text in this message.</p>
                <?php endif; ?>

                <?php if ($m['attachments']): ?>
                    <div class="mr-files" aria-label="Attachments">
                        <?php foreach ($m['attachments'] as $a): ?>
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

                <?php if ($reply): ?>
                    <div class="mr-msg-actions">
                        <?php if ($m['is_draft']): ?>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-compose="draft" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('pencil') ?> Edit draft</button>
                        <?php else: ?>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-compose="reply" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('reply') ?> Reply</button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-compose="reply_all" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('reply-all') ?> Reply all</button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-compose="forward" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('forward') ?> Forward</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
