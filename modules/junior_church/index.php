<?php
// /modules/junior_church/index.php
/**
 * Junior Church: the children and their guardians (with medical alerts and
 * a quick call or WhatsApp to a parent), the Sunday classes and their
 * attendance, and the curriculum vault, with a full-screen view for
 * projecting a video, PDF or image to the class.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/junior-church.js, data from api/junior_church_api.php.
 *
 * Access: Roles & permissions, module "junior_church". View opens the page;
 * Edit registers or changes a child and takes attendance; Create logs a class
 * and uploads curriculum; Delete removes curriculum. The API checks each
 * again.
 */
$ui = [
    'title'   => 'Junior Church',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/junior-church.js'],
    'offline' => false,   // children's records and medical notes: never an old copy
    // Class videos play from YouTube (no-cookie) inside the page.
    'csp'     => ['frame-src' => ["'self'", 'https://www.youtube-nocookie.com'], 'img-src' => ['https://i.ytimg.com']],
];
require_once '../../includes/header.php';
require_can('junior_church');

$canEdit = can('junior_church', 'edit');
$canCreate = can('junior_church', 'create');
$canDelete = can('junior_church', 'delete');
$tabs = [
    'roster' => ['label' => 'Children', 'icon' => 'baby'],
    'services' => ['label' => 'Classes', 'icon' => 'calendar-check'],
    'curriculum' => ['label' => 'Curriculum', 'icon' => 'book-open'],
];
$actions = '';
if ($canCreate) {
    $actions .= ui_button('Log class', ['icon' => 'calendar-check', 'attrs' => ['data-action' => 'jc.class.new']]);
}
if ($canEdit) {
    $actions .= ui_button('Register child', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'jc.child.new']]);
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Junior Church',
        'description' => 'Children, Sunday classes and the curriculum.',
        'info' => 'Each child is linked to one or two guardians from the member list, so a teacher can call or WhatsApp a parent from the card. '
            . 'A red alert marks medical notes. Logging a class opens its attendance sheet with every child marked absent until checked in.',
        'actions' => $actions,
    ]) ?>

    <div class="mb-4" data-jc-error hidden><?= ui_banner('danger', ['text' => 'Junior Church did not load: reload the page to try again.']) ?></div>

    <?= ui_tabs($tabs, 'roster') ?>

    <section data-tab-panel="roster" id="panel-roster" role="tabpanel" aria-labelledby="tab-roster" class="ui-tab-panel ui-stack">
        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="jc-search">Find a child</label>
                <input class="ui-input" type="search" id="jc-search" placeholder="Child or guardian" data-action-input="jc.filter" autocomplete="off">
            </div>
        </div>
        <div class="ui-grid ui-grid--3" data-jc-children></div>
    </section>

    <section data-tab-panel="services" id="panel-services" role="tabpanel" aria-labelledby="tab-services" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Date</th><th scope="col">Topic</th><th scope="col">Teacher</th><th scope="col">Present</th><th scope="col">Resources</th>
                        <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-jc-classes><tr><td colspan="6"><p class="ui-meta">Loading…</p></td></tr></tbody>
                </table>
            </div>
        </div>
        <p class="ui-meta">The latest 20 classes.</p>
    </section>

    <section data-tab-panel="curriculum" id="panel-curriculum" role="tabpanel" aria-labelledby="tab-curriculum" class="ui-tab-panel ui-stack" hidden>
<?php if ($canCreate): ?>
        <div class="ui-toolbar">
            <span class="ui-toolbar__grow"></span>
            <?= ui_button('Upload', ['icon' => 'upload', 'attrs' => ['data-action' => 'jc.curriculum.new']]) ?>
        </div>
<?php endif; ?>
        <div class="ui-grid ui-grid--3" data-jc-curriculum></div>
    </section>
</div>

<?php if ($canEdit): ?>
<?= ui_dialog_start('jc-child-dialog', ['title' => 'Register a child']) ?>
    <form class="ui-form" id="jc-child-form" data-action="jc.child.save" novalidate>
        <input type="hidden" name="child_id" value="">
        <div class="flex items-center gap-4">
            <span class="ui-avatar ui-avatar--xl" data-jc-photo><span aria-hidden="true">?</span></span>
            <?= ui_field('file', ['name' => 'photo_file', 'id' => 'jc-photo-file', 'label' => 'Photo', 'optional' => true, 'hint' => 'You can crop it next.',
                'attrs' => ['accept' => 'image/jpeg,image/png', 'data-action-change' => 'jc.photo.pick']]) ?>
        </div>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'child_first_name', 'label' => 'First name', 'required' => true, 'attrs' => ['maxlength' => 50]]) ?>
            <?= ui_field('text', ['name' => 'child_last_name', 'label' => 'Last name', 'required' => true, 'attrs' => ['maxlength' => 50]]) ?>
        </div>
        <?= ui_field('date', ['name' => 'dob', 'label' => 'Date of birth', 'optional' => true]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'parent_id', 'label' => 'Guardian', 'required' => true, 'options' => ['' => 'Choose…'], 'attrs' => ['data-ui-combobox' => true]]) ?>
            <?= ui_field('select', ['name' => 'parent2_id', 'label' => 'Second guardian', 'optional' => true, 'options' => ['' => 'None'], 'attrs' => ['data-ui-combobox' => true]]) ?>
        </div>
        <?= ui_field('textarea', ['name' => 'medical_notes', 'label' => 'Medical notes', 'optional' => true, 'placeholder' => 'Peanut allergy, asthma…',
            'info' => 'Anything a teacher must know: allergies, conditions, medication. It shows as a red alert on the card and the attendance sheet.', 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save child', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'jc-child-form']])
) ?>

<?= ui_dialog_start('jc-crop-dialog', ['title' => 'Crop the photo', 'description' => 'Drag to frame the face.', 'size' => 'lg']) ?>
    <div class="max-h-[60vh] overflow-hidden">
        <img id="jc-crop-img" class="block max-w-full" src="data:," alt="The photo being cropped">
    </div>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Apply crop', ['variant' => 'primary', 'attrs' => ['data-action' => 'jc.photo.apply']])
) ?>
<?php endif; ?>

<?php if ($canCreate): ?>
<?= ui_dialog_start('jc-class-dialog', ['title' => 'Log a Sunday class']) ?>
    <form class="ui-form" id="jc-class-form" data-action="jc.class.save" novalidate>
        <div class="ui-form-grid">
            <?= ui_field('date', ['name' => 'service_date', 'label' => 'Date', 'required' => true]) ?>
            <?= ui_field('select', ['name' => 'teacher_id', 'label' => 'Teacher', 'required' => true, 'options' => ['' => 'Choose…']]) ?>
        </div>
        <?= ui_field('text', ['name' => 'topic', 'label' => 'Topic', 'required' => true, 'placeholder' => 'The story of David', 'attrs' => ['maxlength' => 255]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'master_pages_covered', 'label' => 'Curriculum pages', 'optional' => true, 'placeholder' => 'Pages 14–16', 'attrs' => ['maxlength' => 100]]) ?>
            <?= ui_field('url', ['name' => 'media_link', 'label' => 'Video or link', 'optional' => true, 'placeholder' => 'https://youtube.com/watch?v=…', 'attrs' => ['maxlength' => 255]]) ?>
        </div>
        <?= ui_field('file', ['name' => 'service_file', 'label' => 'Worksheet or slides', 'optional' => true, 'hint' => 'PDF, Word, PowerPoint or an image; 10 MB at most.',
            'attrs' => ['accept' => '.pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png']]) ?>
        <?= ui_field('textarea', ['name' => 'notes', 'label' => 'Notes', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Log class', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'jc-class-form']])
) ?>

<?= ui_dialog_start('jc-curriculum-dialog', ['title' => 'Upload curriculum', 'size' => 'sm']) ?>
    <form class="ui-form" id="jc-curriculum-form" data-action="jc.curriculum.save" novalidate>
        <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => 'Q2 2026 teachers\' manual', 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('textarea', ['name' => 'description', 'label' => 'Description', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
        <?= ui_field('file', ['name' => 'curriculum_file', 'label' => 'File', 'required' => true, 'hint' => 'PDF, Word, PowerPoint or an image; 10 MB at most.',
            'attrs' => ['accept' => '.pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Upload', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'jc-curriculum-form']])
) ?>
<?php endif; ?>

<?= ui_dialog_start('jc-detail-dialog', ['title' => 'Class']) ?>
    <dl class="ui-dl" data-jc-detail></dl>
    <div class="ui-toolbar mt-4" data-jc-detail-resources></div>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])) ?>

<?= ui_dialog_start('jc-attendance-dialog', ['title' => 'Attendance', 'size' => 'drawer']) ?>
    <p class="ui-meta" data-jc-att-for></p>
    <div class="ui-toolbar" data-jc-att-resources></div>
    <div class="ui-toolbar">
        <p class="font-semibold ui-toolbar__grow" data-jc-att-count aria-live="polite"></p>
<?php if ($canEdit): ?>
        <?= ui_button('All present', ['size' => 'sm', 'icon' => 'check', 'attrs' => ['data-action' => 'jc.att.all']]) ?>
<?php endif; ?>
    </div>
    <div class="ui-list" data-jc-att></div>
<?= ui_dialog_end(ui_button('Done', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?= ui_dialog_start('jc-show-dialog', ['title' => 'Present', 'size' => 'xl']) ?>
    <div class="flex min-h-[50vh] items-center justify-center bg-black" data-jc-show></div>
<?= ui_dialog_end(
    ui_button('Full screen', ['icon' => 'external-link', 'attrs' => ['data-action' => 'jc.show.full']])
    . ui_button('Close', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])
) ?>

<?= ui_json_script('junior-church-boot', [
    'api' => '/api/junior_church_api.php',
    'canEdit' => $canEdit,
    'canCreate' => $canCreate,
    'canDelete' => $canDelete,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
