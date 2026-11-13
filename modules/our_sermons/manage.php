<?php
// /modules/our_sermons/manage.php
// Our sermons, Envision's manager (OUR-SERMONS-PLAN.md, PR S1):
//   /modules/our_sermons/manage         Drafts · Published · Hidden · Feed, and Paste link
//   /modules/our_sermons/manage?id=N    check one sermon: correct every field, then Publish,
//                                       Hide or Delete
// New episodes of the show arrive as drafts from its RSS feed (read every 15
// minutes by cron/automation_tick.php); Paste link adds one from a Spotify or
// YouTube link and fills it in. Publishing tells everyone, once.
// Rendered on the server; actions go to /api/our_sermons_api.php from
// assets/ui/js/pages/our-sermons-manage.js. Access: the 'our_sermons' row of
// the permission matrix (Create to open it; Edit, Delete, Manage per action).
require_once __DIR__ . '/../../includes/our_sermons/bootstrap.php';
$ui = [
    'title' => 'Manage sermons',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/our-sermons-manage.js'],
];
require_once '../../includes/header.php';
require_can('our_sermons', 'create', 'church');

$ready = our_sermons_ready($pdo);
$canEdit = can('our_sermons', 'edit', 'church');
$canDelete = can('our_sermons', 'delete', 'church');
$canManage = can('our_sermons', 'manage', 'church');
$id = (int) ($_GET['id'] ?? 0);
$sermon = $ready && $id > 0 ? our_sermons_get($pdo, $id) : null;

/** A row in Envision's lists. */
$row = static function (array $s): string {
    $badges = [];
    if ($s['status'] === 'draft') {
        $badges[] = ui_badge($s['source'] === 'feed' ? 'From feed' : 'Pasted', 'neutral');
        if ($s['date_source'] === 'guess') {
            $badges[] = ui_badge('Check date', 'warning', ['dot' => true]);
        }
        if ($s['ai_status'] === 'pending') {
            $badges[] = ui_badge('AI drafting', 'info');
        } elseif ($s['ai_status'] === 'done') {
            $badges[] = ui_badge('AI drafted', 'success');
        }
    }
    if ($s['audio_url'] === null && $s['youtube_id'] === null) {
        $badges[] = ui_badge('No audio', 'warning');
    }
    $meta = implode(' · ', array_filter([(string) $s['preacher'], our_sermons_date_label($s['date_preached']),
        our_sermons_length_label($s['duration_seconds'])]));
    $art = $s['artwork_url']
        ? '<img class="os-thumb" src="' . ui_e($s['artwork_url']) . '" alt="" loading="lazy" decoding="async" width="48" height="48">'
        : '<span class="os-thumb os-thumb--none" aria-hidden="true">' . ui_icon('headphones') . '</span>';
    return '<li><a class="ui-list__item" href="' . ui_e(our_sermons_manage_url($s['id'])) . '">' . $art
        . '<span class="ui-list__main"><span class="ui-list__title">' . ui_e($s['title']) . '</span>'
        . '<span class="ui-list__meta">' . ui_e($meta) . '</span>'
        . ($badges !== [] ? '<span class="os-badges">' . implode('', $badges) . '</span>' : '')
        . '</span>' . ui_icon('chevron-right', 'text-ink-subtle') . '</a></li>';
};
?>
<div class="ui-page">
<?php if (!$ready): ?>
    <?= ui_page_header(['title' => 'Manage sermons']) ?>
    <?= ui_banner('info', ['title' => 'Almost ready', 'text' => 'Our sermons opens after the next update.']) ?>
<?php elseif ($id > 0 && $sermon === null): ?>
    <?= ui_page_header(['title' => 'Not found', 'back' => ['href' => our_sermons_manage_url(), 'label' => 'Manage sermons']]) ?>
    <div class="ui-card">
        <?= ui_empty(['icon' => 'headphones', 'title' => 'Sermon not found', 'text' => 'It may have been deleted.',
            'actions' => ui_button('All drafts', ['variant' => 'primary', 'href' => our_sermons_manage_url()])]) ?>
    </div>
<?php elseif ($sermon !== null):
    $s = $sermon;
    $status = OUR_SERMONS_STATUSES[$s['status']] ?? $s['status'];
    $origin = $s['source'] === 'feed' ? 'from the feed' : 'pasted';
    $view = ui_button('View page', ['variant' => 'ghost', 'icon' => 'eye', 'href' => our_sermons_url($s['id'])]);
?>
    <?= ui_page_header([
        'title' => 'Check sermon',
        'description' => $status . ' · ' . $origin,
        'info' => 'Correct anything the title got wrong, then publish. Everyone is told the first time it is published.'
            . ($s['feed_title'] ? ' Original title: ' . $s['feed_title'] : ''),
        'back' => ['href' => our_sermons_manage_url(), 'label' => 'Manage sermons'],
        'actions' => $view,
    ]) ?>
    <div class="ui-stack ui-stack--md">
<?php if ($s['date_source'] === 'guess'): ?>
    <?= ui_banner('warning', ['title' => 'Check the date', 'text' => 'Set the day it was preached, then save.',
        'info' => 'The title had no date, so the Sunday before it was published was filled in.']) ?>
<?php endif; ?>
<?php if ($s['audio_url'] === null && $s['youtube_id'] === null && $s['spotify_url'] === null): ?>
    <?= ui_banner('warning', ['title' => 'No audio yet', 'text' => 'Add a Spotify or YouTube link to publish.',
        'info' => 'Audio comes from the show\'s feed. A pasted episode gets it as soon as the feed has it.']) ?>
<?php endif; ?>
<?php if (function_exists('our_sermons_ai_panel')): ?>
    <?= our_sermons_ai_panel($pdo, $s, (string) ($_SERVER['REQUEST_URI'] ?? our_sermons_manage_url($s['id']))) ?>
<?php endif; ?>
    <form class="ui-card" data-action="os.save" data-ui-validate novalidate data-sermon="<?= (int) $s['id'] ?>"
          data-status="<?= ui_e($s['status']) ?>" data-notified="<?= $s['notified_at'] !== null ? '1' : '0' ?>">
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <div class="ui-card__body ui-stack ui-stack--md">
            <div class="os-media">
<?php if ($s['artwork_url']): ?>
                <img class="os-media__art" src="<?= ui_e($s['artwork_url']) ?>" alt="" width="96" height="96" decoding="async">
<?php else: ?>
                <span class="os-media__art os-thumb--none" aria-hidden="true"><?= ui_icon('headphones') ?></span>
<?php endif; ?>
                <ul class="os-media__facts">
                    <li><?= ui_icon('headphones', 'ui-icon--sm') ?> <?= $s['audio_url'] ? ui_e('Audio · ' . (our_sermons_length_label($s['duration_seconds']) ?: 'length unknown')) : 'No audio yet' ?></li>
<?php if (!function_exists('our_sermons_ai_panel')): ?>
                    <li><?= ui_icon('sparkles', 'ui-icon--sm') ?> <?= ui_e(['pending' => 'AI is drafting the summary', 'done' => 'AI drafted the summary',
                        'failed' => 'AI could not draft it', 'off' => 'Summary by hand'][$s['ai_status']] ?? 'Summary by hand') ?></li>
<?php endif; ?>
                </ul>
            </div>
            <div class="ui-form-grid">
                <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'value' => $s['title'], 'required' => true, 'class' => 'ui-span-full',
                    'attrs' => ['maxlength' => OUR_SERMONS_TITLE_MAX]]) ?>
                <?= ui_field('text', ['name' => 'preacher', 'label' => 'Preacher', 'value' => (string) $s['preacher'], 'placeholder' => 'Pastor Ebele Uzo-Peters',
                    'attrs' => ['maxlength' => OUR_SERMONS_PREACHER_MAX]]) ?>
                <?= ui_field('date', ['name' => 'date_preached', 'label' => 'Date preached', 'value' => $s['date_preached'], 'required' => true,
                    'info' => 'The day it was preached, not the day it went on Spotify.',
                    'attrs' => ['max' => our_sermons_now()->modify('+1 day')->format('Y-m-d')]]) ?>
                <?= ui_field('select', ['name' => 'service', 'label' => 'Service', 'value' => (string) $s['service'],
                    'options' => ['' => 'Not set'] + OUR_SERMONS_SERVICES]) ?>
                <?= ui_field('text', ['name' => 'series', 'label' => 'Series', 'value' => (string) $s['series'], 'optional' => true,
                    'attrs' => ['maxlength' => OUR_SERMONS_SERIES_MAX]]) ?>
                <?= ui_field('textarea', ['name' => 'summary', 'label' => 'Summary', 'value' => (string) $s['summary'], 'class' => 'ui-span-full',
                    'optional' => true, 'attrs' => ['rows' => 5, 'maxlength' => OUR_SERMONS_SUMMARY_MAX]]) ?>
                <?= ui_field('textarea', ['name' => 'key_points', 'label' => 'Key points', 'value' => implode("\n", $s['key_points']), 'optional' => true,
                    'hint' => 'One per line.', 'attrs' => ['rows' => 4]]) ?>
                <?= ui_field('textarea', ['name' => 'scriptures', 'label' => 'Key scriptures', 'value' => implode("\n", $s['scriptures']), 'optional' => true,
                    'hint' => 'One per line, like John 3:16.', 'attrs' => ['rows' => 4]]) ?>
                <?= ui_field('url', ['name' => 'youtube_url', 'label' => 'YouTube video', 'value' => (string) $s['youtube_url'], 'optional' => true,
                    'placeholder' => 'https://youtu.be/…', 'info' => 'The same sermon on YouTube, if there is one.']) ?>
                <?= ui_field('url', ['name' => 'spotify_url', 'label' => 'Spotify episode', 'value' => (string) $s['spotify_url'], 'optional' => true,
                    'placeholder' => 'https://open.spotify.com/episode/…', 'info' => 'Members get an Open in Spotify button.']) ?>
            </div>
        </div>
        <div class="ui-card__footer os-editor__actions">
<?php if ($canDelete): ?>
            <?= ui_button('Delete', ['variant' => 'danger-ghost', 'icon' => 'trash-2', 'attrs' => ['data-action' => 'os.delete']]) ?>
<?php endif; ?>
            <span class="os-editor__spacer"></span>
<?php if ($canEdit): ?>
<?php if ($s['status'] !== 'hidden'): ?>
            <?= ui_button('Hide', ['variant' => 'ghost', 'icon' => 'eye-off', 'attrs' => ['data-action' => 'os.hide']]) ?>
<?php endif; ?>
<?php if ($s['status'] === 'published'): ?>
            <?= ui_button('Save changes', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check']) ?>
<?php else: ?>
            <?= ui_button($s['status'] === 'draft' ? 'Save draft' : 'Save changes', ['variant' => 'secondary', 'type' => 'submit', 'icon' => 'check']) ?>
            <?= ui_button('Publish', ['variant' => 'primary', 'icon' => 'send', 'attrs' => ['data-action' => 'os.publish']]) ?>
<?php endif; ?>
<?php endif; ?>
        </div>
    </form>
    </div>
<?php else:
    $counts = our_sermons_counts($pdo);
    $tabs = ['drafts' => ['label' => 'Drafts', 'count' => $counts['draft']], 'published' => ['label' => 'Published', 'count' => $counts['published']],
        'hidden' => ['label' => 'Hidden', 'count' => $counts['hidden']]];
    if ($canManage) {
        $tabs['feed'] = ['label' => 'Feed', 'icon' => 'rss'];
    }
    $feeds = our_sermons_feeds($pdo);
    $paste = ui_button('Paste link', ['variant' => 'primary', 'icon' => 'link', 'attrs' => ['data-dialog-open' => 'os-link-dialog']]);
?>
    <?= ui_page_header([
        'title' => 'Manage sermons',
        'description' => 'Check each draft, then publish it for everyone.',
        'info' => 'New episodes of the Lekki show arrive here from its feed every 15 minutes. Paste a Spotify or YouTube link to add one yourself. Publishing tells everyone.',
        'back' => ['href' => '/modules/our_sermons/', 'label' => 'Our sermons'],
        'actions' => $paste,
    ]) ?>
<?php if ($feeds === [] && $canManage): ?>
    <?= ui_banner('info', ['title' => 'Add the feed', 'text' => 'Paste the show\'s RSS link in the Feed tab.',
        'info' => 'Spotify for Creators → Settings → Availability → RSS distribution: switch it on and copy the feed link.']) ?>
<?php endif; ?>
    <?= ui_tabs($tabs, 'drafts', 'Sermons') ?>
<?php foreach (['drafts' => 'draft', 'published' => 'published', 'hidden' => 'hidden'] as $tab => $status):
        $list = our_sermons_manage_list($pdo, $status); ?>
    <section data-tab-panel="<?= $tab ?>" id="panel-<?= $tab ?>" role="tabpanel" aria-labelledby="tab-<?= $tab ?>" class="ui-tab-panel"<?= $tab !== 'drafts' ? ' hidden' : '' ?>>
        <div class="ui-card">
<?php if ($list === []): ?>
            <?= match ($tab) {
                'drafts' => ui_empty(['icon' => 'inbox', 'title' => 'No drafts', 'text' => 'New episodes arrive here from the feed.', 'actions' => $paste]),
                'published' => ui_empty(['icon' => 'headphones', 'title' => 'Nothing published yet', 'text' => 'Publish a draft to share it.']),
                default => ui_empty(['icon' => 'eye-off', 'title' => 'Nothing hidden', 'text' => 'Hidden sermons wait here.']),
            } ?>
<?php else: ?>
            <ul class="ui-list" aria-label="<?= ui_e(OUR_SERMONS_STATUSES[$status]) ?>">
<?php foreach ($list as $item): ?>
                <?= $row($item) ?>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
<?php if ($canManage): ?>
    <section data-tab-panel="feed" id="panel-feed" role="tabpanel" aria-labelledby="tab-feed" class="ui-tab-panel ui-stack ui-stack--md" hidden>
<?php if ($feeds !== []): ?>
        <div class="ui-card">
            <ul class="ui-list" aria-label="Feeds">
<?php foreach ($feeds as $f):
            $state = $f['last_error'] !== null ? ui_badge('Problem', 'danger', ['dot' => true])
                : ($f['last_ok_at'] !== null ? ui_badge('Working', 'success', ['dot' => true]) : ui_badge('Not read yet', 'neutral'));
            $when = $f['last_checked_at'] ? 'Read ' . our_sermons_date_label($f['last_checked_at'], false) . ' ' . substr((string) $f['last_checked_at'], 11, 5) : 'Not read yet'; ?>
                <li class="ui-list__item os-feed">
                    <span class="ui-list__icon"><?= ui_icon('rss') ?></span>
                    <span class="ui-list__main">
                        <span class="ui-list__title"><?= ui_e($f['title'] ?: 'Podcast feed') ?></span>
                        <span class="ui-list__meta os-feed__url"><?= ui_e($f['url']) ?></span>
                        <span class="os-badges"><?= $state ?><span class="ui-meta"><?= ui_e($when . ' · ' . (int) $f['items_seen'] . ' episodes') ?></span></span>
<?php if ($f['last_error'] !== null): ?>
                        <span class="ui-meta text-danger"><?= ui_e($f['last_error']) ?></span>
<?php endif; ?>
                    </span>
                    <span class="ui-list__end os-feed__actions">
                        <?= ui_button('Check now', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'os.feedCheck', 'data-feed' => $f['id']]]) ?>
                        <?= ui_button('Remove feed', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true, 'attrs' => ['data-action' => 'os.feedRemove', 'data-feed' => $f['id']]]) ?>
                    </span>
                </li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endif; ?>
        <form class="ui-card" data-action="os.feedAdd" data-ui-validate novalidate>
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3"><?= $feeds === [] ? 'Add the feed' : 'Add another feed' ?></h2></div>
            <div class="ui-card__body ui-form-grid">
                <?= ui_field('url', ['name' => 'url', 'label' => 'Feed link', 'required' => true, 'class' => 'ui-span-full',
                    'placeholder' => 'https://anchor.fm/s/…/podcast/rss',
                    'info' => 'Spotify for Creators → Settings → Availability → RSS distribution. Switch it on and copy the feed link.']) ?>
                <?= ui_field('date', ['name' => 'import_from', 'label' => 'Bring in from', 'optional' => true,
                    'info' => 'Episodes published before this day are left out. Leave it empty to bring in every episode.']) ?>
            </div>
            <div class="ui-card__footer">
                <?= ui_button('Add feed', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'plus']) ?>
            </div>
        </form>
    </section>
<?php endif; ?>

    <?= ui_dialog_start('os-link-dialog', ['title' => 'Paste a link', 'description' => 'A Spotify episode or a YouTube video.', 'size' => 'sm']) ?>
        <form id="os-link-form" class="ui-form" data-action="os.addLink" data-ui-validate novalidate>
            <?= ui_field('url', ['name' => 'link', 'label' => 'Link', 'required' => true, 'placeholder' => 'https://open.spotify.com/episode/…',
                'info' => 'The title, preacher, date and artwork fill in by themselves. Spotify episodes also get their audio from the feed.',
                'attrs' => ['autocomplete' => 'off']]) ?>
        </form>
    <?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Add sermon', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'plus', 'attrs' => ['form' => 'os-link-form']])) ?>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
