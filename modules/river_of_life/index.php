<?php
// /modules/river_of_life/index.php
/**
 * River of Life (the choir): who sings, plays or runs sound in which section,
 * the rehearsals, and each rehearsal's roll-call.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/river-of-life.js, data from api/rol_api.php.
 *
 * Access: Roles & permissions, module "river_of_life". View opens the page;
 * Create assigns members and schedules rehearsals; Edit takes the roll-call;
 * Delete takes someone off the roster. The API checks each again.
 */
$ui = [
    'title'   => 'River of Life',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/river-of-life.js'],
];
require_once '../../includes/header.php';
require_can('river_of_life');

$canCreate = can('river_of_life', 'create');
$canEdit = can('river_of_life', 'edit');
$canDelete = can('river_of_life', 'delete');
$categories = ['Vocalist' => 'Vocalist', 'Band_Instrumentalist' => 'Band & instruments', 'Technical' => 'Technical & sound'];
$tabs = [
    'roster' => ['label' => 'Roster', 'icon' => 'users'],
    'rehearsals' => ['label' => 'Rehearsals', 'icon' => 'calendar'],
];
$actions = '';
if ($canCreate) {
    $actions = ui_button('Schedule', ['icon' => 'calendar', 'attrs' => ['data-action' => 'rol.rehearsal.new']])
        . ui_button('Assign member', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'rol.assign.new']]);
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'River of Life',
        'description' => 'Choir sections, the band and rehearsals.',
        'info' => 'Everyone on the River of Life team can be placed in one section: a voice part, an instrument or a technical role. '
            . 'Scheduling a rehearsal tells the whole roster and opens a roll-call sheet with everyone marked absent until checked in.',
        'actions' => $actions,
    ]) ?>

    <div class="ui-stack">
        <div data-rol-error hidden><?= ui_banner('danger', ['text' => 'River of Life did not load: reload the page to try again.']) ?></div>

        <div class="ui-grid ui-grid--stats">
            <div data-stat="total_members"><?= ui_stat(['label' => 'Team', 'value' => '—', 'icon' => 'users']) ?></div>
            <div data-stat="vocalists"><?= ui_stat(['label' => 'Vocalists', 'value' => '—', 'icon' => 'mic-vocal']) ?></div>
            <div data-stat="band"><?= ui_stat(['label' => 'Band', 'value' => '—', 'icon' => 'music']) ?></div>
            <div data-stat="tech"><?= ui_stat(['label' => 'Tech', 'value' => '—', 'icon' => 'sliders-horizontal']) ?></div>
        </div>

        <div>
            <?= ui_tabs($tabs, 'roster') ?>

            <section data-tab-panel="roster" id="panel-roster" role="tabpanel" aria-labelledby="tab-roster" class="ui-tab-panel ui-stack">
                <div class="ui-grid ui-grid--3" data-rol-roster></div>
            </section>

            <section data-tab-panel="rehearsals" id="panel-rehearsals" role="tabpanel" aria-labelledby="tab-rehearsals" class="ui-tab-panel ui-stack" hidden>
                <div class="ui-card ui-card__body--flush">
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--stack">
                            <thead><tr><th scope="col">When</th><th scope="col">Focus</th><th scope="col">Status</th><th scope="col">Turnout</th>
                                <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody data-rol-rehearsals><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                        </table>
                    </div>
                </div>
                <p class="ui-meta">The latest 15 rehearsals.</p>
            </section>
        </div>
    </div>
</div>

<?php if ($canCreate): ?>
<?= ui_dialog_start('rol-assign-dialog', ['title' => 'Assign a member', 'size' => 'sm']) ?>
    <form class="ui-form" id="rol-assign-form" data-action="rol.assign.save" novalidate>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'Member', 'required' => true, 'options' => ['' => 'Choose…'],
            'info' => 'People on the River of Life team who are not in a section yet.', 'attrs' => ['data-ui-combobox' => true]]) ?>
        <?= ui_field('select', ['name' => 'role_category', 'label' => 'Role', 'options' => $categories]) ?>
        <?= ui_field('text', ['name' => 'section_name', 'label' => 'Section or instrument', 'required' => true, 'placeholder' => 'Soprano, drums, mixing', 'attrs' => ['maxlength' => 50]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Assign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'rol-assign-form']])
) ?>

<?= ui_dialog_start('rol-rehearsal-dialog', ['title' => 'Schedule a rehearsal', 'size' => 'sm']) ?>
    <form class="ui-form" id="rol-rehearsal-form" data-action="rol.rehearsal.save" novalidate>
        <?= ui_field('datetime-local', ['name' => 'rehearsal_date', 'label' => 'When', 'required' => true]) ?>
        <?= ui_field('text', ['name' => 'focus_topic', 'label' => 'Focus', 'required' => true, 'placeholder' => 'Sunday prep: Way Maker', 'attrs' => ['maxlength' => 255]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Schedule', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'rol-rehearsal-form']])
) ?>
<?php endif; ?>

<?= ui_dialog_start('rol-roll-dialog', ['title' => 'Roll-call', 'size' => 'drawer']) ?>
    <p class="ui-meta" data-rol-roll-for></p>
    <p class="font-semibold" data-rol-roll-counts aria-live="polite"></p>
    <div class="ui-list" data-rol-roll></div>
<?= ui_dialog_end(ui_button('Done', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?= ui_json_script('river-of-life-boot', [
    'api' => '/api/rol_api.php',
    'canCreate' => $canCreate,
    'canEdit' => $canEdit,
    'canDelete' => $canDelete,
    'categories' => $categories,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
