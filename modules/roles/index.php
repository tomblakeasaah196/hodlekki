<?php
// /modules/roles/index.php
/**
 * Roles & permissions: who may open what, and until when (permissions PR 3,
 * design §6). Five tabs:
 *   Matrix   modules × roles, then seat types, then Everyone; one cell = a
 *            level (none · view · create · edit · delete), the extras the
 *            module has (approve · export · manage) and a scope (own ·
 *            their departments · church).
 *   Roles    create (from a preset or a copy), rename, archive, view as.
 *   People   a person's roles with dates, their seats, and their effective
 *            access with the reason for each module; give / remove roles,
 *            renew; view as them.
 *   Seats    seat types, which seats each department uses, module owners,
 *            and the year end (roll over the ministry year).
 *   History  every change (perm_history), before → after.
 *
 * Gate: roles: View (Super Admins, the Resident Pastor). Every change: roles:
 * Manage + fits within (includes/permissions/admin.php), enforced by
 * api/roles_api.php. Strict mode; behaviour in assets/ui/js/pages/roles.js.
 */
$ui = [
    'title'   => 'Roles & permissions',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/roles.js'],
    'offline' => false,   // access control: never an old copy
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/permissions/expiry.php';
require_can('roles');

$real = perm_real_current();
$canManage = $real['breakglass'] || perm_ctx_can($real, 'roles', 'manage');
$canRoll = $real['breakglass'] || perm_ctx_can($real, 'departments', 'manage');
$viewing = perm_view_as() !== null;
$levelInfo = [
    'none' => 'No access to this module.',
    'view' => 'See it: open the pages and read the records.',
    'create' => 'View, and add new records.',
    'edit' => 'Create, and change existing records.',
    'delete' => 'Edit, and remove records.',
    'approve' => 'Approve or sign off: requests, budgets, publishing.',
    'export' => 'Download, print or export lists.',
    'manage' => 'Settings, teams and other people\'s work in the module.',
    'own' => 'Only their own records.',
    'dept' => 'The departments they serve in (a seat: its department).',
    'church' => 'The whole church.',
];
$tabs = [
    'matrix' => ['label' => 'Matrix', 'icon' => 'sliders-horizontal'],
    'roles' => ['label' => 'Roles', 'icon' => 'shield-check'],
    'people' => ['label' => 'People', 'icon' => 'users'],
    'seats' => ['label' => 'Seats', 'icon' => 'building-2'],
    'history' => ['label' => 'History', 'icon' => 'history'],
];
$levelRadios = '';
foreach (['none' => 'None', 'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'] as $value => $label) {
    $levelRadios .= '<label class="ui-check" data-level-option="' . $value . '"><input type="radio" name="level" value="' . $value . '">'
        . '<span>' . $label . '</span> ' . ui_info($levelInfo[$value], 'About ' . $label) . '</label>';
}
$extraChecks = '';
foreach (['approve' => 'Approve', 'export' => 'Export', 'manage' => 'Manage'] as $value => $label) {
    $extraChecks .= '<label class="ui-check" data-extra-option="' . $value . '"><input type="checkbox" name="' . $value . '" value="1">'
        . '<span>' . $label . '</span> ' . ui_info($levelInfo[$value], 'About ' . $label) . '</label>';
}
$scopeOptions = ['own' => 'Own records', 'dept' => 'Their departments', 'church' => 'Whole church'];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Roles & permissions',
        'description' => 'Who may open what, and until when.',
        'info' => 'Roles are given to people; seats come from the Departments roster. '
            . 'Each cell says how far a role or seat reaches in one module. Nobody can give more than they hold, '
            . 'and every change is kept in History.',
        'actions' => $canManage && !$viewing
            ? ui_button('New role', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'roles.new']])
            : '',
    ]) ?>

<?php if (!$canManage): ?>
    <?= ui_banner('info', ['text' => 'You can look and view as others, not change.',
        'info' => 'Changing roles and permissions is for Super Admins.']) ?>
<?php elseif ($viewing): ?>
    <?= ui_banner('warning', ['text' => 'Stop viewing as someone to make changes.']) ?>
<?php endif; ?>

    <?= ui_tabs($tabs, 'matrix') ?>

    <section data-tab-panel="matrix" id="panel-matrix" role="tabpanel" aria-labelledby="tab-matrix" class="ui-tab-panel ui-stack">
        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="roles-matrix-search">Find a module</label>
                <input class="ui-input" type="search" id="roles-matrix-search" placeholder="Find a module" data-action-input="roles.matrix.filter">
            </div>
            <label class="sr-only" for="roles-matrix-column">Column</label>
            <select class="ui-select" id="roles-matrix-column" data-action-change="roles.matrix.filter">
                <option value="">Every column</option>
            </select>
            <span class="ui-meta"><?= ui_info('V view · C create · E edit · D delete; a approve · x export · m manage. '
                . 'The word under each cell is how far it reaches: Own, Dept or All.', 'How to read a cell') ?></span>
        </div>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap" data-roles-matrix>
                <div class="ui-loading"><span class="ui-spinner"></span><span>Loading the matrix…</span></div>
            </div>
        </div>
    </section>

    <section data-tab-panel="roles" id="panel-roles" role="tabpanel" aria-labelledby="tab-roles" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-card ui-card__body--flush"><div class="ui-list" data-roles-list></div></div>
    </section>

    <section data-tab-panel="people" id="panel-people" role="tabpanel" aria-labelledby="tab-people" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="roles-people-search">Find a person</label>
                <input class="ui-input" type="search" id="roles-people-search" placeholder="Name, email or phone" data-action-input="roles.people.search" autocomplete="off">
            </div>
        </div>
        <div class="ui-card ui-card__body--flush" data-roles-people-results hidden><div class="ui-list"></div></div>
        <div data-roles-person>
            <?= ui_empty(['icon' => 'user-round', 'title' => 'Find a person', 'text' => 'Search above to see their roles, seats and access.']) ?>
        </div>
    </section>

    <section data-tab-panel="seats" id="panel-seats" role="tabpanel" aria-labelledby="tab-seats" class="ui-tab-panel ui-stack" hidden>
        <div data-roles-seats>
            <div class="ui-loading"><span class="ui-spinner"></span><span>Loading seats…</span></div>
        </div>
    </section>

    <section data-tab-panel="history" id="panel-history" role="tabpanel" aria-labelledby="tab-history" class="ui-tab-panel ui-stack" hidden>
        <form class="ui-toolbar" data-action="roles.history.filter">
            <label class="sr-only" for="roles-history-type">Change</label>
            <select class="ui-select" id="roles-history-type" name="type">
                <option value="">Every change</option>
                <option value="grant">Matrix cells</option>
                <option value="role.">Roles</option>
                <option value="user_role">Roles given or removed</option>
                <option value="seat">Seats</option>
                <option value="view_as">View as</option>
            </select>
            <label class="sr-only" for="roles-history-module">Module</label>
            <select class="ui-select" id="roles-history-module" name="module"><option value="">Every module</option></select>
            <label class="sr-only" for="roles-history-from">From</label>
            <input class="ui-input" type="date" id="roles-history-from" name="from">
            <label class="sr-only" for="roles-history-to">To</label>
            <input class="ui-input" type="date" id="roles-history-to" name="to">
            <?= ui_button('Show changes', ['type' => 'submit', 'icon' => 'filter']) ?>
        </form>
        <div class="ui-card ui-card__body--flush"><div class="ui-list" data-roles-history></div></div>
        <div class="ui-toolbar" data-roles-history-more hidden>
            <?= ui_button('Show more', ['variant' => 'ghost', 'attrs' => ['data-action' => 'roles.history.more']]) ?>
        </div>
    </section>
</div>

<?= ui_dialog_start('roles-cell-dialog', ['title' => 'Edit access', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-cell-form" data-action="roles.cell.save" novalidate>
        <p class="ui-meta" data-cell-where></p>
        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Level</legend>
            <div class="ui-stack ui-stack--sm"><?= $levelRadios ?></div>
        </fieldset>
        <fieldset class="ui-fieldset" data-cell-extras>
            <legend class="ui-legend">Extras</legend>
            <div class="ui-stack ui-stack--sm"><?= $extraChecks ?></div>
        </fieldset>
        <?= ui_field('select', ['name' => 'scope', 'label' => 'Reaches', 'options' => $scopeOptions, 'value' => 'church',
            'info' => 'Own: ' . $levelInfo['own'] . ' Their departments: ' . $levelInfo['dept'] . ' Whole church: ' . $levelInfo['church']]) ?>
        <?= ui_field('select', ['name' => 'view_scope', 'label' => 'Views', 'options' => ['' => 'Same as above'] + $scopeOptions,
            'info' => 'Lets them see further than they may change, e.g. view the whole church, edit their department.']) ?>
        <p class="ui-meta" data-cell-impact></p>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save access', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-cell-form']])
) ?>

<?= ui_dialog_start('roles-column-dialog', ['title' => 'Column actions', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-column-form" data-action="roles.column.save" novalidate>
        <p class="ui-meta" data-column-where></p>
        <label class="ui-check"><input type="radio" name="op" value="copy" checked><span>Copy another column</span></label>
        <?= ui_field('select', ['name' => 'from', 'label' => 'Copy from', 'options' => []]) ?>
        <label class="ui-check"><input type="radio" name="op" value="readonly"><span>Read-only church-wide</span>
            <?= ui_info('View on every everyday module across the church. Money, security and private notes stay closed.', 'About read-only') ?></label>
        <label class="ui-check"><input type="radio" name="op" value="clear"><span>Clear the column</span></label>
        <p class="ui-meta" data-column-impact></p>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Apply', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-column-form']])
) ?>

<?= ui_dialog_start('roles-role-dialog', ['title' => 'New role', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-role-form" data-action="roles.role.save" novalidate>
        <input type="hidden" name="role_id" value="">
        <?= ui_field('text', ['name' => 'label', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 60]]) ?>
        <?= ui_field('text', ['name' => 'description', 'label' => 'Description', 'optional' => true, 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('select', ['name' => 'start', 'label' => 'Start from', 'options' => [], 'class' => 'roles-start-field',
            'info' => 'A preset fills the matrix with a sensible start; you can change every cell after. Or copy an existing role.']) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save role', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-role-form']])
) ?>

<?= ui_dialog_start('roles-assign-dialog', ['title' => 'Give a role', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-assign-form" data-action="roles.assign.save" novalidate>
        <input type="hidden" name="user_id" value="">
        <input type="hidden" name="mode" value="add">
        <?= ui_field('select', ['name' => 'role_id', 'label' => 'Role', 'options' => []]) ?>
        <div class="ui-form-grid">
            <?= ui_field('date', ['name' => 'starts_on', 'label' => 'Starts', 'optional' => true]) ?>
            <?= ui_field('date', ['name' => 'ends_on', 'label' => 'Ends', 'optional' => true,
                'info' => 'Leave empty for no end. They and whoever gave it are told 14 days before; Super Admin never ends.']) ?>
        </div>
        <?= ui_field('text', ['name' => 'custom_title', 'label' => 'Title shown', 'optional' => true, 'attrs' => ['maxlength' => 100],
            'info' => 'A display tag only, e.g. "Media Director". It gives no access.']) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save role', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-assign-form']])
) ?>

<?= ui_dialog_start('roles-seat-dialog', ['title' => 'Edit seat type', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-seat-form" data-action="roles.seat.save" novalidate>
        <input type="hidden" name="seat_key" value="">
        <?= ui_field('text', ['name' => 'label', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 40]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('number', ['name' => 'seat_rank', 'label' => 'Rank', 'attrs' => ['min' => 1, 'max' => 99],
                'info' => 'You may only appoint seats ranked below your own. Pastor in charge 60, Director 50, HOD 40.']) ?>
            <?= ui_field('number', ['name' => 'max_holders', 'label' => 'Most per department', 'optional' => true, 'attrs' => ['min' => 1, 'max' => 50]]) ?>
        </div>
        <label class="ui-check"><input type="checkbox" name="covers_sub_units" value="1"><span>Covers sub-units</span></label>
        <label class="ui-check"><input type="checkbox" name="can_appoint" value="1"><span>May appoint</span></label>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save seat', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-seat-form']])
) ?>

<?= ui_dialog_start('roles-owner-dialog', ['title' => 'Module owners', 'size' => 'sm']) ?>
    <form class="ui-form" id="roles-owner-form" data-action="roles.owner.save" novalidate>
        <input type="hidden" name="module" value="">
        <p class="ui-meta" data-owner-where></p>
        <div class="ui-stack ui-stack--sm" data-owner-list></div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save owners', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'roles-owner-form']])
) ?>

<?= ui_json_script('roles-boot', [
    'api' => '/api/roles_api.php',
    'canManage' => $canManage && !$viewing,
    'canRoll' => $canRoll,
    'me' => (int) $real['user_id'],
    'levels' => ['—', 'View', 'Create', 'Edit', 'Delete'],
    'scopeWords' => ['own' => 'Own', 'dept' => 'Dept', 'church' => 'All'],
    'scopeNames' => $scopeOptions,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
