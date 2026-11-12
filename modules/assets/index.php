<?php
// /modules/assets/index.php
/**
 * Asset Management: the church's equipment, who has it, where it lives and
 * what state it is in, with an activity log of check-outs, check-ins,
 * repairs and losses.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/assets.js, data from api/assets_api.php.
 *
 * Access: Roles & permissions, module "assets". View opens the inventory,
 * Create registers an asset, Edit changes one and logs its movements. The API
 * checks each again.
 */
$ui = [
    'title'   => 'Asset Management',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/assets.js'],
];
require_once '../../includes/header.php';
require_can('assets');

$canCreate = can('assets', 'create');
$canEdit = can('assets', 'edit');
$categories = ['Audio' => 'Audio', 'Video' => 'Video & camera', 'Lighting' => 'Lighting', 'Instruments' => 'Musical instruments',
    'IT' => 'IT & computers', 'Furniture' => 'Furniture', 'General' => 'General / other'];
$conditions = ['Excellent' => 'Excellent', 'Good' => 'Good', 'Fair' => 'Fair', 'Poor' => 'Poor (needs repair)'];
$tabs = [
    'inventory' => ['label' => 'Inventory', 'icon' => 'package'],
    'logs' => ['label' => 'Activity', 'icon' => 'history'],
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Asset Management',
        'description' => 'Church equipment, who has it, and its condition.',
        'info' => 'Register each piece of equipment once, then log every movement: checking it out to someone, checking it back in, '
            . 'sending it for repair or marking it missing. The status follows the latest log, and the department that manages it is told about repairs and losses.',
        'actions' => $canCreate ? ui_button('Add asset', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'ast.new']]) : '',
    ]) ?>

    <div class="ui-stack">
        <div data-ast-error hidden><?= ui_banner('danger', ['text' => 'Assets did not load: reload the page to try again.']) ?></div>

        <div class="ui-grid ui-grid--stats">
            <div data-stat="total"><?= ui_stat(['label' => 'Assets', 'value' => '—', 'icon' => 'package']) ?></div>
            <div data-stat="in_use"><?= ui_stat(['label' => 'In use', 'value' => '—', 'icon' => 'users']) ?></div>
            <div data-stat="maintenance"><?= ui_stat(['label' => 'In repair', 'value' => '—', 'icon' => 'settings']) ?></div>
            <div data-stat="lost"><?= ui_stat(['label' => 'Missing', 'value' => '—', 'icon' => 'triangle-alert']) ?></div>
        </div>

        <div>
            <?= ui_tabs($tabs, 'inventory') ?>

            <section data-tab-panel="inventory" id="panel-inventory" role="tabpanel" aria-labelledby="tab-inventory" class="ui-tab-panel ui-stack">
                <div class="ui-toolbar">
                    <div class="ui-search ui-toolbar__grow">
                        <?= ui_icon('search') ?>
                        <label class="sr-only" for="ast-search">Find an asset</label>
                        <input class="ui-input" type="search" id="ast-search" placeholder="Name, department or place" data-action-input="ast.filter" autocomplete="off">
                    </div>
                    <label class="sr-only" for="ast-status">Show</label>
                    <select class="ui-select" id="ast-status" data-action-change="ast.filter">
                        <option value="">All</option>
                        <option value="Available">Available</option>
                        <option value="In_Use">In use</option>
                        <option value="Maintenance">In repair</option>
                        <option value="Lost">Missing</option>
                    </select>
                </div>
                <div class="ui-card ui-card__body--flush">
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--stack">
                            <thead><tr><th scope="col">Asset</th><th scope="col">Department</th><th scope="col">Status</th><th scope="col">Condition</th>
                                <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody data-ast-body><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section data-tab-panel="logs" id="panel-logs" role="tabpanel" aria-labelledby="tab-logs" class="ui-tab-panel ui-stack" hidden>
                <div class="ui-card ui-card__body--flush">
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--stack">
                            <thead><tr><th scope="col">When</th><th scope="col">Asset</th><th scope="col">Action</th><th scope="col">Person</th><th scope="col">Notes</th></tr></thead>
                            <tbody data-ast-logs></tbody>
                        </table>
                    </div>
                </div>
                <p class="ui-meta">The latest 50 entries.</p>
            </section>
        </div>
    </div>
</div>

<?= ui_dialog_start('ast-dialog', ['title' => 'Add asset']) ?>
    <form class="ui-form" id="ast-form" data-action="ast.save" novalidate>
        <input type="hidden" name="asset_id" value="">
        <?= ui_field('text', ['name' => 'name', 'label' => 'Name or model', 'required' => true, 'placeholder' => 'Shure SM58 microphone', 'attrs' => ['maxlength' => 150]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'category', 'label' => 'Category', 'options' => $categories, 'value' => 'General']) ?>
            <?= ui_field('number', ['name' => 'quantity', 'label' => 'Quantity', 'required' => true, 'value' => '1', 'inputmode' => 'numeric', 'attrs' => ['min' => 1, 'step' => 1]]) ?>
        </div>
        <?= ui_field('select', ['name' => 'managing_department_id', 'label' => 'Managing department', 'optional' => true, 'options' => ['' => 'None'],
            'attrs' => ['data-ui-combobox' => true]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'storage_location', 'label' => 'Kept at', 'optional' => true, 'placeholder' => 'Media booth', 'attrs' => ['maxlength' => 150]]) ?>
            <?= ui_field('select', ['name' => 'condition_rating', 'label' => 'Condition', 'options' => $conditions, 'value' => 'Good']) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save asset', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ast-form']])
) ?>

<?= ui_dialog_start('ast-log-dialog', ['title' => 'Log a movement', 'size' => 'sm']) ?>
    <form class="ui-form" id="ast-log-form" data-action="ast.log.save" novalidate>
        <input type="hidden" name="asset_id" value="">
        <p class="ui-meta" data-ast-log-name></p>
        <?= ui_field('select', ['name' => 'log_action', 'label' => 'What happened', 'required' => true, 'options' => [
            '' => 'Choose…', 'Check-Out' => 'Checked out', 'Check-In' => 'Checked back in', 'Maintenance' => 'Sent for repair', 'Lost' => 'Gone missing',
        ]]) ?>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'Person responsible', 'options' => ['' => 'Me'],
            'attrs' => ['data-ui-combobox' => true]]) ?>
        <?= ui_field('textarea', ['name' => 'notes', 'label' => 'Notes', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save log', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ast-log-form']])
) ?>

<?= ui_json_script('assets-boot', [
    'api' => '/api/assets_api.php',
    'canEdit' => $canEdit,
    'canCreate' => $canCreate,
    'categories' => $categories,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
