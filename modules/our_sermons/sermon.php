<?php
// /modules/our_sermons/sermon.php
//
// Our sermons, PR S2: one sermon, for every signed-in member
// (can('our_sermons', 'view')). Listen with our own player (the feed's full
// audio, no Spotify sign-in), or watch when Envision attached a YouTube
// video; carry on where you stopped; open the Bible in a side panel while
// the sermon keeps playing; take private notes stamped with the moment and
// email them to yourself. Drafts open only for Envision (edit), to check.
//
//   /modules/our_sermons/sermon?id=<our_sermons.id>
//
// Behaviour: assets/ui/js/pages/our-sermon.js. Styles: assets/ui/src/app.css,
// "Our sermons: the listening page" (osl- classes; S1's list keeps os-).
// Server: includes/our_sermons/listen.php (which loads S1's bootstrap) and
// api/our_sermons_listen_api.php. CSP, on this page only: media-src gets the
// audio's hosts from S1's our_sermons_media_hosts() (the Lekki feed: anchor.fm
// and the CloudFront host it redirects to), frame-src youtube-nocookie only
// when there is a video, img-src API.Bible's FUMS host only when a licensed
// translation can be read in the Bible panel.
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/bible/bootstrap.php';
require_once __DIR__ . '/../../includes/our_sermons/listen.php';

$osRow = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $osRow = our_sermon_listen_get($pdo, (int) ($_GET['id'] ?? 0), our_sermon_can_preview());
    } catch (Throwable $e) {
        error_log('[our_sermons] sermon page: ' . $e->getMessage());
    }
}
$osView = $osRow !== null ? our_sermon_view($osRow) : null;

// The Bible panel: the member's own translation in the Bible module, and the
// translations that can be read now (licensed ones need API.Bible's FUMS).
$osBibleTranslations = [];
$osFums = bible_env('BIBLE_FUMS_URL', 'https://fums.api.bible/f3');
$osFumsOrigin = null;
if ($osView !== null) {
    foreach (bible_translations($pdo) as $t) {
        if ($t['available']) {
            $osBibleTranslations[] = ['code' => $t['code'], 'name' => $t['name'], 'local' => $t['source'] === 'local'];
        }
    }
    $fp = parse_url($osFums);
    if (is_array($fp) && ($fp['scheme'] ?? '') === 'https' && !empty($fp['host']) && count($osBibleTranslations) > 1) {
        $osFumsOrigin = 'https://' . strtolower((string) $fp['host']);
    }
}

$osCsp = ['media-src' => our_sermons_media_hosts($osView['audio'] ?? null)];
if ($osView !== null && $osView['youtube_id'] !== null) {
    $osCsp['frame-src'] = [OUR_SERMON_YOUTUBE_ORIGIN];
}
if ($osFumsOrigin !== null) {
    $osCsp['img-src'] = [$osFumsOrigin];
}

$ui = [
    'title' => 'Sermon',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/our-sermon.js'],
    'csp' => $osCsp,
];
require_once __DIR__ . '/../../includes/header.php';
require_can('our_sermons', 'view');

if ($osView === null) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty([
        'icon'    => 'headphones',
        'title'   => 'Sermon not found',
        'text'    => 'It was removed, or the link is wrong.',
        'actions' => ui_button('See all sermons', ['variant' => 'primary', 'href' => '/modules/our_sermons/']),
    ]) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$osUid = (int) $_SESSION['user_id'];
$osReady = our_sermon_listen_ready($pdo);
$osProgress = $osReady ? our_sermon_progress_get($pdo, $osUid, $osView['id']) : ['position' => 0, 'finished' => false, 'finished_at' => null];
$osNotes = $osReady ? our_sermon_notes_list($pdo, $osUid, $osView['id']) : [];
$osBibleState = bible_state_get($pdo, $osUid);
$osMyEmail = null;
if (is_file(__DIR__ . '/../../includes/campaigns/bootstrap.php')) {
    require_once __DIR__ . '/../../includes/campaigns/bootstrap.php';
}
try {
    $osMyEmail = our_sermon_account_email($pdo, $osUid);
} catch (Throwable $e) {
    error_log('[our_sermons] account email: ' . $e->getMessage());
}

$osHasAudio = $osView['audio'] !== null;
$osHasVideo = $osView['youtube_id'] !== null;
$osLength = $osView['length'];
$osCanEdit = can('our_sermons', 'edit', 'church');
$osMeta = implode(' · ', array_filter([$osView['preacher'], $osView['date_label']]));
$osAbout = $osView['summary'] !== [] || $osView['key_points'] !== [] || $osView['scriptures'] !== [];
$osHues = ['navy', 'violet', 'teal', 'gold', 'rose'];

echo ui_json_script('osl-config', [
    'id'           => $osView['id'],
    'title'        => $osView['title'],
    'preacher'     => $osView['preacher'],
    'artwork'      => $osView['artwork'],
    'duration'     => $osView['duration'],
    'audio'        => $osHasAudio,
    'youtube'      => $osView['youtube_id'],
    'youtubeOrigin'=> OUR_SERMON_YOUTUBE_ORIGIN,
    'siteOrigin'   => function_exists('site_origin') ? site_origin() : '',
    'ready'        => $osReady,
    'progress'     => $osProgress,
    'notes'        => $osNotes,
    'noteMax'      => OUR_SERMON_NOTE_MAX,
    'endpoint'     => '/api/our_sermons_listen_api.php',
    'bible'        => [
        'translation'  => $osBibleState['translation'],
        'translations' => $osBibleTranslations,
        'fums'         => $osFumsOrigin !== null ? $osFums : null,
    ],
]);
?>
<div class="ui-page osl" data-os-page>
    <?php if ($osView['status'] !== 'published'): ?>
        <?= ui_banner('info', ['title' => 'Preview', 'text' => 'Members see this once it is published.']) ?>
    <?php endif; ?>

    <section class="osl-hero" aria-label="Sermon">
        <div class="osl-hero__art">
            <?php if ($osView['artwork'] !== null): ?>
                <img src="<?= htmlspecialchars($osView['artwork'], ENT_QUOTES, 'UTF-8') ?>" alt="" width="160" height="160" decoding="async">
            <?php else: ?>
                <span class="osl-hero__mark"><?= ui_icon('headphones') ?></span>
            <?php endif; ?>
        </div>
        <div class="osl-hero__main">
            <?= ui_page_header([
                'back'        => ['href' => '/modules/our_sermons/', 'label' => 'Our sermons'],
                'title'       => $osView['title'] !== '' ? $osView['title'] : 'Sermon',
                'description' => $osMeta,
            ]) ?>
            <ul class="osl-facts" aria-label="Details">
                <?php if ($osView['service'] !== ''): ?><li class="osl-fact"><?= ui_icon('calendar') ?><span><?= ui_e($osView['service']) ?></span></li><?php endif; ?>
                <?php if ($osView['series'] !== ''): ?><li class="osl-fact osl-fact--series"><?= ui_icon('list') ?><span><?= ui_e($osView['series']) ?></span></li><?php endif; ?>
                <?php if ($osLength !== ''): ?><li class="osl-fact"><?= ui_icon('clock') ?><span><?= ui_e($osLength) ?></span></li><?php endif; ?>
                <li class="osl-fact osl-fact--heard" data-os-heard<?= $osProgress['finished'] ? '' : ' hidden' ?>><?= ui_icon('circle-check') ?><span>Heard</span></li>
            </ul>
            <?php if ($osView['spotify'] !== null || $osCanEdit): ?>
                <div class="osl-hero__actions">
                    <?php if ($osView['spotify'] !== null): ?>
                        <?= ui_button('Open in Spotify', ['href' => $osView['spotify'], 'icon' => 'external-link', 'size' => 'sm',
                            'attrs' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]) ?>
                    <?php endif; ?>
                    <?php if ($osCanEdit): ?>
                        <?= ui_button('Edit sermon', ['variant' => 'ghost', 'icon' => 'pencil', 'size' => 'sm', 'href' => our_sermons_manage_url($osView['id'])]) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($osHasAudio && $osHasVideo): ?>
        <?= ui_tabs(['listen' => ['label' => 'Listen', 'icon' => 'headphones'], 'watch' => ['label' => 'Watch', 'icon' => 'video']], 'listen', 'Listen or watch') ?>
    <?php endif; ?>

    <?php if ($osHasAudio): ?>
        <section class="osl-player" <?= $osHasVideo ? 'data-tab-panel="listen" id="panel-listen" role="tabpanel" aria-labelledby="tab-listen"' : 'aria-label="Player"' ?> data-os-player>
            <audio preload="metadata" data-os-audio>
                <source src="<?= htmlspecialchars($osView['audio'], ENT_QUOTES, 'UTF-8') ?>" type="<?= ui_e(our_sermon_audio_type($osView['audio'])) ?>" data-os-source>
            </audio>
            <p class="osl-resume" data-os-resume hidden>
                <?= ui_icon('history') ?><span>Carrying on from <b data-os-resume-at>0:00</b></span>
                <button type="button" class="osl-link" data-action="os.restart">Start over</button>
            </p>
            <div class="osl-scrub">
                <div class="osl-scrub__track" data-os-track>
                    <span class="osl-scrub__fill" data-os-fill></span>
                    <span class="osl-scrub__marks" data-os-marks aria-hidden="true"></span>
                    <input type="range" class="osl-scrub__range" min="0" max="<?= (int) ($osView['duration'] ?? 0) ?>" step="1" value="0"
                           aria-label="Position" aria-valuetext="0:00" data-os-scrub>
                </div>
                <div class="osl-scrub__times">
                    <span class="ui-num" data-os-cur>0:00</span>
                    <span class="ui-num" data-os-left><?= $osView['duration'] ? '-' . ui_e(our_sermon_fmt_time($osView['duration'])) : '' ?></span>
                </div>
            </div>
            <div class="osl-controls">
                <button type="button" class="osl-speed" data-action="os.speed" aria-label="Speed 1×" data-tooltip="Speed" data-os-speed>1×</button>
                <button type="button" class="osl-skip" data-action="os.back" aria-label="Back 15 seconds" data-tooltip="Back 15 s (J)"><?= ui_icon('rotate-ccw') ?><span aria-hidden="true">15</span></button>
                <button type="button" class="osl-play" data-action="os.play" aria-label="Play" data-os-play><?= ui_icon('play', 'osl-play__on') ?><?= ui_icon('pause', 'osl-play__off') ?></button>
                <button type="button" class="osl-skip" data-action="os.fwd" aria-label="Forward 15 seconds" data-tooltip="Forward 15 s (L)"><?= ui_icon('rotate-cw') ?><span aria-hidden="true">15</span></button>
                <button type="button" class="osl-speed osl-speed--note" data-action="os.mark" aria-label="Note this moment" data-tooltip="Note this moment (N)"><?= ui_icon('notebook-pen') ?></button>
            </div>
            <p class="osl-player__help">
                <span data-os-status aria-live="polite"></span>
                <?= ui_info('Space plays or pauses, J and L skip 15 seconds, N notes this moment, B opens the Bible. Your place is saved as you listen, so you carry on here next time, on any device.', 'About the player') ?>
            </p>
            <div class="osl-player__error" data-os-error hidden>
                <?= ui_banner('warning', ['title' => 'Audio not playing', 'text' => 'Check your connection, or open it in Spotify.']) ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($osHasVideo): ?>
        <section class="osl-watch" <?= $osHasAudio ? 'data-tab-panel="watch" id="panel-watch" role="tabpanel" aria-labelledby="tab-watch" hidden' : 'aria-label="Video"' ?> data-os-watch>
            <div class="osl-video" data-os-video>
                <button type="button" class="osl-video__start" data-action="os.watch">
                    <span class="osl-video__play"><?= ui_icon('play') ?></span><span>Play video</span>
                </button>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$osHasAudio && !$osHasVideo): ?>
        <div class="ui-card"><div class="ui-card__body">
            <?= ui_empty(['icon' => 'headphones', 'title' => 'Audio coming soon', 'text' => 'Envision is adding it.', 'compact' => true,
                'actions' => $osView['spotify'] !== null ? ui_button('Open in Spotify', ['href' => $osView['spotify'], 'icon' => 'external-link',
                    'attrs' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]) : '']) ?>
        </div></div>
    <?php endif; ?>

    <div class="osl-biblebar">
        <?= ui_button('Open Bible', ['variant' => 'soft', 'icon' => 'book-open', 'attrs' => ['data-action' => 'os.bible', 'aria-controls' => 'osl-bible', 'aria-expanded' => 'false']]) ?>
        <?php if ($osView['scriptures'] !== []): ?>
            <ul class="osl-chips" aria-label="Key scriptures">
                <?php foreach ($osView['scriptures'] as $sc): ?>
                    <li><?php if ($sc['ref'] !== null): ?><button type="button" class="osl-chip"<?= our_sermon_ref_attrs($sc['ref']) ?>><?= ui_e($sc['text']) ?></button><?php else: ?><span class="osl-chip osl-chip--plain"><?= ui_e($sc['text']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="osl-grid<?= $osAbout ? '' : ' osl-grid--notes' ?>">
        <section class="osl-card osl-notes" id="osl-notes" aria-labelledby="osl-notes-h" data-os-notes>
            <div class="osl-notes__head">
                <h2 class="osl-card__title" id="osl-notes-h"><?= ui_icon('notebook-pen') ?>My notes</h2>
                <?= ui_info('Only you can see your notes. Each one keeps the moment you wrote it: tap the time to hear it again. Bible references open in the Bible panel.', 'About my notes') ?>
                <?= ui_button('Email notes', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'mail', 'class' => 'osl-notes__email',
                    'attrs' => ['data-dialog-open' => 'osl-email-dialog', 'data-os-email-open' => true]]) ?>
            </div>
            <?php if ($osReady): ?>
                <form class="osl-compose" data-action="os.note-add" novalidate>
                    <div class="osl-compose__label">
                        <label for="osl-note-body">Note</label>
                        <button type="button" class="osl-stamp" data-action="os.stamp" aria-label="Stamped at 0:00. Tap to use the moment playing now." data-os-stamp>
                            <?= ui_icon('clock') ?><span data-os-stamp-at>0:00</span>
                        </button>
                    </div>
                    <textarea id="osl-note-body" name="body" class="ui-textarea osl-compose__text" rows="3" maxlength="<?= OUR_SERMON_NOTE_MAX ?>"
                              placeholder="What stood out?" data-os-note-text></textarea>
                    <div class="osl-compose__foot">
                        <span class="osl-compose__count ui-num" data-os-count aria-live="polite"></span>
                        <?= ui_button('Add note', ['variant' => 'primary', 'size' => 'sm', 'type' => 'submit', 'icon' => 'plus', 'attrs' => ['data-os-add' => true]]) ?>
                    </div>
                </form>
                <ol class="osl-notelist" data-os-notelist aria-label="Your notes"></ol>
                <div data-os-notes-empty hidden>
                    <?= ui_empty(['icon' => 'notebook-pen', 'title' => 'No notes yet', 'text' => 'Each note keeps the moment you wrote it.', 'compact' => true]) ?>
                </div>
            <?php else: ?>
                <?= ui_empty(['icon' => 'notebook-pen', 'title' => 'Notes coming soon', 'text' => 'Try again after the next update.', 'compact' => true]) ?>
            <?php endif; ?>
        </section>

        <?php if ($osAbout): ?>
            <div class="osl-about">
                <?php if ($osView['summary'] !== []): ?>
                    <section class="osl-card" aria-labelledby="osl-summary-h">
                        <h2 class="osl-card__title" id="osl-summary-h"><?= ui_icon('text-quote') ?>Summary</h2>
                        <div class="osl-prose">
                            <?php foreach ($osView['summary'] as $para): ?><p><?= our_sermon_rich_html($para) ?></p><?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($osView['key_points'] !== []): ?>
                    <section class="osl-card" aria-labelledby="osl-points-h">
                        <h2 class="osl-card__title" id="osl-points-h"><?= ui_icon('list-ordered') ?>Key points</h2>
                        <ol class="osl-points">
                            <?php foreach ($osView['key_points'] as $i => $kp): ?>
                                <li class="osl-point" data-hue="<?= $osHues[$i % count($osHues)] ?>">
                                    <span class="osl-point__n ui-num" aria-hidden="true"><?= $i + 1 ?></span>
                                    <div class="osl-point__body">
                                        <p class="osl-point__text"><?= our_sermon_rich_html($kp['text']) ?></p>
                                        <?php if ($kp['detail'] !== ''): ?><p class="osl-point__detail"><?= our_sermon_rich_html($kp['detail']) ?></p><?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endif; ?>

                <?php if ($osView['scriptures'] !== []): ?>
                    <section class="osl-card" aria-labelledby="osl-scriptures-h">
                        <h2 class="osl-card__title" id="osl-scriptures-h"><?= ui_icon('book-open') ?>Key scriptures</h2>
                        <ul class="osl-scriptures">
                            <?php foreach ($osView['scriptures'] as $sc): ?>
                                <li class="osl-scripture">
                                    <?php if ($sc['ref'] !== null): ?>
                                        <button type="button" class="osl-scripture__ref"<?= our_sermon_ref_attrs($sc['ref']) ?>><?= ui_icon('book-open') ?><span><?= ui_e($sc['text']) ?></span></button>
                                    <?php else: ?>
                                        <span class="osl-scripture__ref osl-scripture__ref--plain"><?= ui_e($sc['text']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($sc['note'] !== ''): ?><p class="osl-scripture__note"><?= ui_e($sc['note']) ?></p><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="osl-dock" data-os-dock>
        <div class="osl-mini" role="region" aria-label="Now playing" data-os-mini inert>
            <span class="osl-mini__bar" aria-hidden="true"><span class="osl-mini__fill" data-os-mini-fill></span></span>
            <?php if ($osView['artwork'] !== null): ?>
                <img class="osl-mini__art" src="<?= htmlspecialchars($osView['artwork'], ENT_QUOTES, 'UTF-8') ?>" alt="" width="40" height="40">
            <?php else: ?>
                <span class="osl-mini__art osl-mini__art--mark"><?= ui_icon('headphones') ?></span>
            <?php endif; ?>
            <button type="button" class="osl-mini__main" data-action="os.top">
                <span class="osl-mini__title"><?= ui_e($osView['title']) ?></span>
                <span class="osl-mini__time ui-num" data-os-mini-time>0:00</span>
            </button>
            <button type="button" class="osl-mini__btn" data-action="os.back" aria-label="Back 15 seconds"><?= ui_icon('rotate-ccw') ?></button>
            <button type="button" class="osl-mini__btn osl-mini__btn--play" data-action="os.play" aria-label="Play" data-os-play><?= ui_icon('play', 'osl-play__on') ?><?= ui_icon('pause', 'osl-play__off') ?></button>
            <button type="button" class="osl-mini__btn" data-action="os.fwd" aria-label="Forward 15 seconds"><?= ui_icon('rotate-cw') ?></button>
        </div>
    </div>
</div>

<aside class="osl-bible" id="osl-bible" aria-labelledby="osl-bible-ref" data-os-bible hidden>
    <div class="osl-bible__head">
        <span class="osl-bible__mark"><?= ui_icon('book-open') ?></span>
        <h2 class="osl-bible__ref" id="osl-bible-ref" tabindex="-1" data-os-bible-ref>Bible</h2>
        <?php if ($osHasAudio): ?>
            <button type="button" class="osl-bible__btn" data-action="os.play" aria-label="Play" data-os-play><?= ui_icon('play', 'osl-play__on') ?><?= ui_icon('pause', 'osl-play__off') ?></button>
        <?php endif; ?>
        <button type="button" class="osl-bible__btn" data-action="os.bible-close" aria-label="Close Bible" data-tooltip="Close (Esc)"><?= ui_icon('x') ?></button>
    </div>
    <div class="osl-bible__tools">
        <button type="button" class="osl-bible__btn" data-action="os.bible-prev" aria-label="Previous chapter" data-os-bible-prev><?= ui_icon('chevron-left') ?></button>
        <form class="osl-bible__go" data-action="os.bible-go" novalidate>
            <label class="sr-only" for="osl-bible-q">Go to a reference</label>
            <input id="osl-bible-q" name="q" type="search" class="ui-input" placeholder="John 3:16" autocomplete="off" enterkeyhint="go" maxlength="60">
        </form>
        <?php if (count($osBibleTranslations) > 1): ?>
            <label class="sr-only" for="osl-bible-t">Translation</label>
            <select id="osl-bible-t" class="ui-select osl-bible__t" data-action-change="os.bible-t">
                <?php foreach ($osBibleTranslations as $t): ?>
                    <option value="<?= ui_e($t['code']) ?>"<?= $t['code'] === $osBibleState['translation'] ? ' selected' : '' ?>><?= ui_e($t['code']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="button" class="osl-bible__btn" data-action="os.bible-next" aria-label="Next chapter" data-os-bible-next><?= ui_icon('chevron-right') ?></button>
    </div>
    <div class="osl-bible__body" data-os-bible-body tabindex="0" aria-label="Chapter text"></div>
    <div class="osl-bible__foot">
        <p class="osl-bible__copy" data-os-bible-copy></p>
        <a class="osl-link" href="/modules/bible/" target="_blank" rel="noopener" data-os-bible-full>Open in Bible<?= ui_icon('external-link') ?></a>
    </div>
</aside>

<?= ui_dialog_start('osl-email-dialog', ['title' => 'Email my notes', 'description' => 'All your notes on this sermon, in one email.', 'size' => 'sm']) ?>
    <form id="osl-email-form" class="ui-form" data-action="os.email" novalidate>
        <?php if ($osMyEmail !== null): ?>
            <fieldset class="ui-fieldset osl-email__choice">
                <legend class="sr-only">Send to</legend>
                <label class="ui-check"><input type="radio" name="to" value="mine" checked data-action-change="os.email-to"><span>My email <span class="ui-meta"><?= ui_e(our_sermon_mask_email($osMyEmail)) ?></span></span></label>
                <label class="ui-check"><input type="radio" name="to" value="other" data-action-change="os.email-to"><span>Another address</span></label>
            </fieldset>
        <?php else: ?>
            <input type="hidden" name="to" value="other">
        <?php endif; ?>
        <div data-os-email-other<?= $osMyEmail !== null ? ' hidden' : '' ?>>
            <?= ui_field('email', ['name' => 'email', 'label' => 'Email address', 'placeholder' => 'name@example.com', 'autocomplete' => 'email']) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send notes', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send', 'attrs' => ['form' => 'osl-email-form', 'data-os-email-send' => true]])
) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
