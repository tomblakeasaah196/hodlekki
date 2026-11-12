<?php
// /modules/envision/index.php
/**
 * Envision, the media unit: the sermon archive behind the public Sermons
 * page, the media crew and service duties, comment moderation, and the live
 * audio broadcast. The how-to guide is modules/envision/how_to_use.md.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/envision.js, data from api/envision_api.php.
 *
 * Access: Roles & permissions, module "envision". View opens the page;
 * Create archives a sermon; Edit changes one and logs duties; Approve
 * publishes; Delete removes comments; Manage runs the crew and the broadcast.
 * The API checks each again.
 */
$ui = [
    'title'   => 'Envision',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/envision.js'],
];
require_once '../../includes/header.php';
require_once '../../includes/reach_helpers.php';
require_can('envision');

$canCreate = can('envision', 'create');
$canEdit = can('envision', 'edit');
$canApprove = can('envision', 'approve');
$canDelete = can('envision', 'delete');
$canManage = can('envision', 'manage');
$crewRoles = ['Videography' => 'Videography', 'Photography' => 'Photography', 'Audio_Engineering' => 'Audio engineering',
    'Projection' => 'Projection', 'Lighting' => 'Lighting', 'Note_Taking' => 'Note taking', 'Social_Media' => 'Social media', 'Direction' => 'Media direction'];
$serviceTypes = ['Total Experience' => 'Total Experience (Sunday)', 'Mercy Experience' => 'Mercy Experience (Thursday)', 'Special Program' => 'Special programme'];
$guideHtml = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));

$tabs = [
    'archive' => ['label' => 'Sermons', 'icon' => 'video'],
    'roster' => ['label' => 'Crew', 'icon' => 'users'],
    'comments' => ['label' => 'Comments', 'icon' => 'message-circle'],
    'config' => ['label' => 'Live radio', 'icon' => 'activity'],
    'howto' => ['label' => 'Guide', 'icon' => 'book-open'],
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Envision Media',
        'description' => 'Sermons, the media crew and the live broadcast.',
        'info' => 'The sermon archive here feeds the public Sermons page. The Guide tab explains each part, including how to set up the live audio broadcast.',
        'actions' => $canCreate ? ui_button('Archive sermon', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'env.sermon.new']]) : '',
    ]) ?>

    <div class="ui-stack">
        <div data-env-error hidden><?= ui_banner('danger', ['text' => 'Envision did not load: reload the page to try again.']) ?></div>

        <div class="ui-grid ui-grid--stats">
            <div data-stat="total_sermons"><?= ui_stat(['label' => 'Sermons', 'value' => '—', 'icon' => 'video']) ?></div>
            <div data-stat="published_sermons"><?= ui_stat(['label' => 'Live', 'value' => '—', 'icon' => 'globe']) ?></div>
            <div data-stat="total_downloads"><?= ui_stat(['label' => 'Downloads', 'value' => '—', 'icon' => 'download']) ?></div>
            <div data-stat="pending_comments"><?= ui_stat(['label' => 'New comments', 'value' => '—', 'icon' => 'message-circle']) ?></div>
        </div>

        <div>
            <?= ui_tabs($tabs, 'archive') ?>

            <section data-tab-panel="archive" id="panel-archive" role="tabpanel" aria-labelledby="tab-archive" class="ui-tab-panel ui-stack">
                <div class="ui-card ui-card__body--flush">
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--stack">
                            <thead><tr><th scope="col">Sermon</th><th scope="col">Media</th><th scope="col">Crew</th><th scope="col">Public</th>
                                <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody data-env-sermons><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                        </table>
                    </div>
                </div>
                <p class="ui-meta">The latest 50 sermons.</p>
            </section>

            <section data-tab-panel="roster" id="panel-roster" role="tabpanel" aria-labelledby="tab-roster" class="ui-tab-panel ui-stack" hidden>
<?php if ($canManage): ?>
                <div class="ui-toolbar">
                    <span class="ui-toolbar__grow"></span>
                    <?= ui_button('Add to crew', ['icon' => 'user-round-plus', 'attrs' => ['data-action' => 'env.crew.new']]) ?>
                </div>
<?php endif; ?>
                <section class="ui-card"><div class="ui-list" data-env-roster></div></section>
            </section>

            <section data-tab-panel="comments" id="panel-comments" role="tabpanel" aria-labelledby="tab-comments" class="ui-tab-panel ui-stack" hidden>
                <div class="ui-toolbar">
                    <div class="ui-search ui-toolbar__grow">
                        <?= ui_icon('search') ?>
                        <label class="sr-only" for="env-comment-search">Find a comment</label>
                        <input class="ui-input" type="search" id="env-comment-search" placeholder="A word or a name" data-action-input="env.comments.search" autocomplete="off">
                    </div>
                </div>
                <div class="ui-card ui-card__body--flush">
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--stack">
                            <thead><tr><th scope="col">From</th><th scope="col">Comment</th><th scope="col">Sermon</th>
                                <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody data-env-comments></tbody>
                        </table>
                    </div>
                </div>
                <div class="ui-toolbar">
                    <p class="ui-meta ui-toolbar__grow" data-env-comment-meta></p>
                    <?= ui_button('Newer', ['size' => 'sm', 'icon' => 'chevron-left', 'attrs' => ['data-action' => 'env.comments.page', 'data-step' => '-1', 'hidden' => true]]) ?>
                    <?= ui_button('Older', ['size' => 'sm', 'icon_end' => 'chevron-right', 'attrs' => ['data-action' => 'env.comments.page', 'data-step' => '1', 'hidden' => true]]) ?>
                </div>
            </section>

            <section data-tab-panel="config" id="panel-config" role="tabpanel" aria-labelledby="tab-config" class="ui-tab-panel" hidden>
                <div class="ui-grid ui-grid--2">
                    <section class="ui-card">
                        <div class="ui-card__header ui-card__header--divided">
                            <div class="ui-title-row"><h2 class="ui-h3">Stream link</h2><?= ui_info('The public listen link your stream provider gives you. The Guide tab walks through the setup.', 'About the stream link') ?></div>
                        </div>
                        <div class="ui-card__body">
                            <form class="ui-form" id="env-radio-form" data-action="env.radio.save" novalidate>
                                <?= ui_field('url', ['name' => 'zeno_stream_url', 'label' => 'Listen link', 'placeholder' => 'https://stream.zeno.fm/…', 'attrs' => array_filter(['maxlength' => 500, 'readonly' => !$canManage])]) ?>
<?php if ($canManage): ?>
                                <div class="ui-toolbar">
                                    <?= ui_button('Save link', ['variant' => 'primary', 'type' => 'submit']) ?>
                                    <?= ui_button('Test link', ['icon' => 'activity', 'attrs' => ['data-action' => 'env.radio.test']]) ?>
                                </div>
<?php endif; ?>
                            </form>
                        </div>
                    </section>
                    <section class="ui-card">
                        <div class="ui-card__header ui-card__header--divided">
                            <div class="ui-title-row"><h2 class="ui-h3">Broadcast</h2><?= ui_info('On shows the live player to the congregation; switch it off when the service ends.', 'About the broadcast switch') ?></div>
                        </div>
                        <div class="ui-card__body ui-stack">
                            <label class="ui-check items-center"><input type="checkbox" class="ui-switch" data-action-change="env.radio.live" disabled><span>Live now</span></label>
                            <p class="ui-meta" data-env-radio-state aria-live="polite">Loading…</p>
                        </div>
                    </section>
                </div>
            </section>

            <section data-tab-panel="howto" id="panel-howto" role="tabpanel" aria-labelledby="tab-howto" class="ui-tab-panel" hidden>
                <article class="ui-card ui-card__body ui-prose max-w-none">
                    <?= $guideHtml ?: '<p>The guide could not be loaded.</p>' ?>
                </article>
            </section>
        </div>
    </div>
</div>

<?php if ($canCreate || $canEdit): ?>
<?= ui_dialog_start('env-sermon-dialog', ['title' => 'Archive a sermon', 'size' => 'lg']) ?>
    <form class="ui-form" id="env-sermon-form" data-action="env.sermon.save" novalidate>
        <input type="hidden" name="sermon_id" value="">
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => 'The power of vision', 'attrs' => ['maxlength' => 255]]) ?>
            <?= ui_field('text', ['name' => 'preacher', 'label' => 'Preacher', 'required' => true, 'attrs' => ['maxlength' => 150]]) ?>
        </div>
        <div class="ui-form-grid">
            <?= ui_field('date', ['name' => 'date_preached', 'label' => 'Date preached', 'required' => true]) ?>
            <?= ui_field('select', ['name' => 'service_type', 'label' => 'Service', 'options' => $serviceTypes]) ?>
        </div>
        <?= ui_field('url', ['name' => 'youtube_link', 'label' => 'YouTube link', 'optional' => true, 'placeholder' => 'https://youtube.com/watch?v=…', 'attrs' => ['maxlength' => 255]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('file', ['name' => 'cover_image', 'label' => 'Cover image', 'optional' => true, 'hint' => 'JPG, PNG or WebP.', 'attrs' => ['accept' => '.jpg,.jpeg,.png,.webp']]) ?>
            <?= ui_field('file', ['name' => 'audio_file', 'label' => 'Audio', 'optional' => true, 'hint' => 'MP3, WAV or M4A.', 'attrs' => ['accept' => '.mp3,.wav,.m4a']]) ?>
        </div>
        <p class="ui-meta" data-env-has-files></p>
        <?= ui_field('textarea', ['name' => 'summary', 'label' => 'Summary', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
        <?= ui_field('textarea', ['name' => 'full_notes', 'label' => 'Sermon notes', 'optional' => true, 'attrs' => ['rows' => 6]]) ?>
        <div data-env-progress hidden>
            <p class="ui-meta" data-env-progress-text aria-live="polite"></p>
            <progress class="w-full" max="100" value="0"></progress>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save sermon', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'env-sermon-form']])
) ?>
<?php endif; ?>

<?= ui_dialog_start('env-duty-dialog', ['title' => 'Service crew', 'size' => 'sm']) ?>
    <p class="ui-meta" data-env-duty-for></p>
    <div class="ui-list" data-env-duties></div>
<?php if ($canEdit): ?>
    <form class="ui-form mt-4" id="env-duty-form" data-action="env.duty.save" novalidate>
        <?= ui_field('select', ['name' => 'duty_role', 'label' => 'Duty', 'options' => $crewRoles]) ?>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'Who', 'required' => true, 'options' => ['' => 'Choose…'], 'attrs' => ['data-ui-combobox' => true]]) ?>
        <?= ui_button('Log duty', ['variant' => 'primary', 'type' => 'submit']) ?>
    </form>
<?php endif; ?>
<?= ui_dialog_end(ui_button('Done', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])) ?>

<?php if ($canManage): ?>
<?= ui_dialog_start('env-crew-dialog', ['title' => 'Add to the crew', 'size' => 'sm']) ?>
    <form class="ui-form" id="env-crew-form" data-action="env.crew.save" novalidate>
        <div data-env-crew-pick>
            <?= ui_field('select', ['name' => 'user_id', 'label' => 'Member', 'required' => true, 'options' => ['' => 'Choose…'],
                'info' => 'People seated in the Envision department who are not on the crew yet.', 'attrs' => ['data-ui-combobox' => true]]) ?>
        </div>
        <p class="ui-meta" data-env-crew-name hidden></p>
        <?= ui_field('select', ['name' => 'role_category', 'label' => 'Specialisation', 'options' => $crewRoles]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'env-crew-form']])
) ?>
<?php endif; ?>

<?= ui_json_script('envision-boot', [
    'api' => '/api/envision_api.php',
    'canCreate' => $canCreate,
    'canEdit' => $canEdit,
    'canApprove' => $canApprove,
    'canDelete' => $canDelete,
    'canManage' => $canManage,
    'crewRoles' => $crewRoles,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
