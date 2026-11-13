<?php
// /modules/our_sermons/sermon.php
// One sermon: its details, the audio from the show's feed in a plain player,
// Open in Spotify and the YouTube link. PR S1 keeps it MINIMAL on purpose:
// PR S2 replaces this page with the full listening page (our own player,
// notes, the Bible panel) at the same address, /modules/our_sermons/sermon?id=N,
// which is where the publish notification opens.
// The audio streams from the feed's host, so media-src allows that host (and
// any host its tracking prefix forwards to) on this page only.
// Access: everyone signed in sees a published sermon; those who may check
// sermons (Edit) also see drafts and hidden ones, as a preview.
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/our_sermons/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$sermon = !empty($_SESSION['user_id']) && our_sermons_ready($pdo) ? our_sermons_get($pdo, $id) : null;
$ui = [
    'title' => 'Sermon',
    'mode' => 'strict',
    'csp' => ['media-src' => our_sermons_media_hosts($sermon['audio_url'] ?? null)],
];
require_once '../../includes/header.php';
require_can('our_sermons', 'view');

$canEdit = can('our_sermons', 'edit', 'church');
$back = ['href' => '/modules/our_sermons/', 'label' => 'Our sermons'];
?>
<div class="ui-page ui-page--narrow">
<?php if ($sermon === null || ($sermon['status'] !== 'published' && !$canEdit)): ?>
    <?= ui_page_header(['title' => 'Not found', 'back' => $back]) ?>
    <div class="ui-card">
        <?= ui_empty(['icon' => 'headphones', 'title' => 'Sermon not found', 'text' => 'It may have been moved or taken down.',
            'actions' => ui_button('All sermons', ['variant' => 'primary', 'href' => '/modules/our_sermons/'])]) ?>
    </div>
<?php else:
    $s = $sermon;
    $sub = implode(' · ', array_filter([(string) $s['preacher'], our_sermons_date_label($s['date_preached'])]));
    $actions = $canEdit ? ui_button('Edit sermon', ['variant' => 'secondary', 'icon' => 'pencil', 'href' => our_sermons_manage_url($s['id'])]) : '';
?>
    <?= ui_page_header(['title' => $s['title'], 'description' => $sub, 'back' => $back, 'actions' => $actions]) ?>
<?php if ($s['status'] !== 'published'): ?>
    <?= ui_banner('warning', ['title' => 'Preview', 'text' => 'Members see this once it is published.']) ?>
<?php endif; ?>
    <article class="ui-card">
        <div class="ui-card__body ui-stack ui-stack--md">
            <div class="os-hero">
<?php if ($s['artwork_url']): ?>
                <img class="os-hero__art" src="<?= ui_e($s['artwork_url']) ?>" alt="" width="160" height="160" decoding="async">
<?php endif; ?>
                <dl class="ui-dl os-hero__facts">
<?php foreach ([
        'Preacher' => (string) $s['preacher'],
        'Preached' => our_sermons_date_label($s['date_preached']),
        'Service' => our_sermons_service_label($s['service']),
        'Series' => (string) $s['series'],
        'Length' => our_sermons_length_label($s['duration_seconds']),
    ] as $label => $value):
        if ($value === '') {
            continue;
        } ?>
                    <dt><?= ui_e($label) ?></dt>
                    <dd><?= ui_e($value) ?></dd>
<?php endforeach; ?>
                </dl>
            </div>
<?php if ($s['audio_url']): ?>
            <audio class="os-audio" controls preload="none" src="<?= ui_e($s['audio_url']) ?>">
                <a href="<?= ui_e($s['audio_url']) ?>" rel="noopener">Download the audio</a>
            </audio>
<?php endif; ?>
<?php if ($s['spotify_url'] || $s['youtube_url']): ?>
            <div class="os-links">
<?php if ($s['spotify_url']): ?>
                <?= ui_button('Open in Spotify', ['variant' => $s['audio_url'] ? 'secondary' : 'primary', 'icon' => 'external-link', 'href' => $s['spotify_url'],
                    'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?>
<?php endif; ?>
<?php if ($s['youtube_url']): ?>
                <?= ui_button('Watch on YouTube', ['variant' => 'secondary', 'icon' => 'video', 'href' => $s['youtube_url'],
                    'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?>
<?php endif; ?>
            </div>
<?php endif; ?>
        </div>
    </article>
<?php if ($s['summary'] || $s['key_points'] !== [] || $s['scriptures'] !== []): ?>
    <section class="ui-card mt-6" aria-labelledby="os-about">
        <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3" id="os-about">About this sermon</h2></div>
        <div class="ui-card__body ui-stack ui-stack--md">
<?php if ($s['summary']): ?>
            <div class="ui-prose"><?= nl2br(ui_e($s['summary'])) ?></div>
<?php endif; ?>
<?php if ($s['key_points'] !== []): ?>
            <div>
                <h3 class="font-semibold text-ink">Key points</h3>
                <ul class="os-points">
<?php foreach ($s['key_points'] as $point): ?>
                    <li><?= ui_e($point) ?></li>
<?php endforeach; ?>
                </ul>
            </div>
<?php endif; ?>
<?php if ($s['scriptures'] !== []): ?>
            <div>
                <h3 class="font-semibold text-ink">Key scriptures</h3>
                <ul class="os-scriptures">
<?php foreach ($s['scriptures'] as $ref): ?>
                    <li><?= ui_badge($ref, 'primary', ['icon' => 'book-open']) ?></li>
<?php endforeach; ?>
                </ul>
            </div>
<?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
