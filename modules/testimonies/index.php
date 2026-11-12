<?php
// /modules/testimonies/index.php
/**
 * Testimonies admin: review, format and publish the stories members and
 * guests send in, and choose which are read at the Sunday service.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/testimonies-admin.js, data from
 * api/testimony_admin_api.php. The editor is the page's own (bold, italic,
 * underline, lists); the API keeps only that formatting (an allowlist), so
 * nothing else can reach the public wall.
 *
 * Access: Roles & permissions, module "testimonies". View opens the list,
 * Edit formats, publishes and flags for Sunday, Delete removes. The API checks
 * each again.
 */
$currentModule = 'testimonies';
$ui = [
    'title'   => 'Testimonies',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/testimonies-admin.js'],
];
require_once '../../includes/header.php';
require_can('testimonies');

$canEdit = can('testimonies', 'edit');
$canDelete = can('testimonies', 'delete');
$statuses = ['Published' => 'Published on the wall', 'Pending_Editing' => 'Pending: needs editing', 'Hidden' => 'Hidden'];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Testimonies',
        'description' => 'Review, format and publish testimonies.',
        'info' => 'Stories come from the member portal and the public testimonies page. Format one, then publish it to the wall, keep it pending or hide it. '
            . 'Sunday marks the ones to read at the service. You see who asked to stay anonymous; the wall never shows their name.',
    ]) ?>

    <div class="ui-stack">
        <div data-tm-error hidden><?= ui_banner('danger', ['text' => 'Testimonies did not load: reload the page to try again.']) ?></div>

        <div class="ui-grid ui-grid--stats">
            <div data-stat="total_testimonies"><?= ui_stat(['label' => 'Stories', 'value' => '—', 'icon' => 'sparkles']) ?></div>
            <div data-stat="pending_edits"><?= ui_stat(['label' => 'Pending edits', 'value' => '—', 'icon' => 'pencil']) ?></div>
            <div data-stat="total_voice_notes"><?= ui_stat(['label' => 'Voice notes', 'value' => '—', 'icon' => 'mic-vocal']) ?></div>
            <div data-stat="selected_for_service"><?= ui_stat(['label' => 'For Sunday', 'value' => '—', 'icon' => 'calendar']) ?></div>
        </div>

        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="tm-search">Find a testimony</label>
                <input class="ui-input" type="search" id="tm-search" placeholder="Name or words" data-action-input="tma.filter" autocomplete="off">
            </div>
            <label class="sr-only" for="tm-status">Show</label>
            <select class="ui-select" id="tm-status" data-action-change="tma.filter">
                <option value="">All</option>
                <option value="Pending_Editing">Pending</option>
                <option value="Published">Published</option>
                <option value="Hidden">Hidden</option>
                <option value="sunday">For Sunday</option>
            </select>
        </div>

        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">From</th><th scope="col">Status</th><th scope="col">Testimony</th><th scope="col">Sunday</th>
                        <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-tm-body><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<?= ui_dialog_start('tm-review-dialog', ['title' => 'Review testimony', 'size' => 'lg']) ?>
    <form class="ui-form" id="tm-review-form" data-action="tma.save" novalidate>
        <input type="hidden" name="testimony_id" value="">
        <p class="ui-meta" data-tm-meta></p>
        <div data-tm-audio hidden>
            <p class="ui-label">Voice note</p>
            <audio class="w-full" controls preload="none"></audio>
        </div>
        <div class="ui-field">
            <div class="ui-label-row">
                <span class="ui-label" id="tm-editor-label">Testimony</span>
                <?= ui_info('Bold, italic, underline and lists are kept. Pasted text arrives plain; any other formatting is removed when you save.', 'About formatting') ?>
            </div>
            <div class="ui-toolbar" role="toolbar" aria-label="Formatting" data-tm-tools>
                <?= ui_button('Bold', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'bold', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'bold']]) ?>
                <?= ui_button('Italic', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'italic', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'italic']]) ?>
                <?= ui_button('Underline', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'underline', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'underline']]) ?>
                <?= ui_button('Bulleted list', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'list', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'insertUnorderedList']]) ?>
                <?= ui_button('Numbered list', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'list-ordered', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'insertOrderedList']]) ?>
                <?= ui_button('Clear formatting', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'remove-formatting', 'icon_only' => true, 'attrs' => ['data-action' => 'tma.format', 'data-cmd' => 'removeFormat']]) ?>
            </div>
            <div class="ui-textarea max-h-96 overflow-y-auto [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="tm-editor-label"
                 aria-describedby="tm-editor-error" data-tm-editor></div>
            <p class="ui-field-error" id="tm-editor-error" aria-live="polite"></p>
        </div>
        <?= ui_field('select', ['name' => 'status', 'label' => 'Status', 'options' => $statuses]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'tm-review-form']])
) ?>
<?php endif; ?>

<?= ui_json_script('testimonies-admin-boot', [
    'api' => '/api/testimony_admin_api.php',
    'canEdit' => $canEdit,
    'canDelete' => $canDelete,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
