<?php
// /modules/departments/index.php
/**
 * Departments: every unit with its leaders and its Primary / Secondary
 * roster for one ministry year; create and edit departments, change a
 * leadership seat, add and move people, ministry years, the rollover
 * worksheet ("Start new year") and the downloads (A4 image, Excel, CSV).
 * The Attendance tab takes a roll for one event (a workers' meeting) by
 * department: present, absent, clock in, export the absent list. Behaviour in
 * assets/ui/js/pages/departments-attendance.js, data from the attendance_*
 * actions of api/department_api.php and api/department_attendance_export.php.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/departments.js, the A4 sheet drawn by
 * assets/js/department_export.js, data from api/department_api.php.
 *
 * Access: Roles & permissions, module "departments" (View to open, Edit for
 * your departments, Manage for ministry years and the rollover). Seats come
 * from the seat types; you only appoint seats ranked below your own — the API
 * decides (dept_may_appoint).
 */
$ui = [
    'title'   => 'Departments',
    'mode'    => 'strict',
    'scripts' => ['/assets/js/department_export.js', '/assets/ui/js/pages/departments.js', '/assets/ui/js/pages/departments-attendance.js'],
];
require_once '../../includes/header.php';
require_once '../../includes/department_helpers.php';
require_can('departments', 'view');

if (!dept_schema_ready($pdo)) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty([
        'icon' => 'building-2', 'title' => 'One migration outstanding',
        'text' => 'Run php db/migrate.php on the server, or deploy.',
    ]) . '</div>';
    require_once '../../includes/footer.php';
    exit;
}

$types = [
    'Service_Unit' => 'Service unit', 'Ministry_Arm' => 'Ministry arm', 'Focused_Group' => 'Focused group',
    'Administrative' => 'Administrative / office', 'Welfare' => 'Welfare', 'Special_Task_Force' => 'Special task force',
];
$demographics = ['All' => 'Everyone', 'Male_Only' => 'Men only', 'Female_Only' => 'Women only'];
$leaders = [
    'pastor_in_charge' => ['assoc_pastor_id', 'ap'],
    'director' => ['director_id', 'dir'],
    'hod' => ['hod_id', 'hod'],
];
$seatTypes = dept_seat_types();
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Departments',
        'description' => 'Units, leaders and who serves in each.',
        'info' => 'Each department has a Pastor in charge, a Director and an HOD, then Primary members (the core team) '
            . 'and Secondary members (who serve now and then). Rosters belong to a ministry year; past years stay read-only for downloads.',
        'actions' => '<label class="sr-only" for="dept-year">Ministry year</label>'
            . '<select class="ui-select" id="dept-year" data-action-change="dept.year"></select>'
            . ui_button('Years', ['variant' => 'ghost', 'icon' => 'calendar', 'attrs' => ['data-action' => 'dept.years', 'hidden' => true, 'data-perm' => 'years']])
            . ui_button('Start new year', ['icon' => 'refresh-cw', 'attrs' => ['data-action' => 'dept.rollover', 'hidden' => true, 'data-perm' => 'rollover']])
            . ui_button('Download', ['icon' => 'download', 'attrs' => ['data-action' => 'dept.download']])
            . ui_button('New department', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'dept.new', 'hidden' => true, 'data-perm' => 'new']]),
    ]) ?>

    <?= ui_tabs([
        'departments' => ['label' => 'Departments', 'icon' => 'building-2'],
        'attendance' => ['label' => 'Attendance', 'icon' => 'user-check'],
    ], 'departments', 'Departments sections') ?>

    <div id="panel-departments" data-tab-panel="departments" class="ui-tab-panel ui-stack">

    <div data-dept-archived hidden>
        <?= ui_banner('warning', [
            'title' => 'Archived year: read-only',
            'text' => 'Switch to the live year to make changes.',
            'actions' => ui_button('Open live year', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'dept.live']]),
        ]) ?>
    </div>

    <section data-dept-dashboard class="ui-stack">
        <div class="ui-grid ui-grid--stats">
            <div data-stat="departments"><?= ui_stat(['label' => 'Departments', 'value' => '—', 'icon' => 'building-2']) ?></div>
            <div data-stat="primary"><?= ui_stat(['label' => 'Primary', 'value' => '—', 'icon' => 'users']) ?></div>
            <div data-stat="secondary"><?= ui_stat(['label' => 'Secondary', 'value' => '—', 'icon' => 'users-round']) ?></div>
            <div data-stat="people"><?= ui_stat(['label' => 'Serving now', 'value' => '—', 'icon' => 'circle-check']) ?></div>
        </div>
        <div class="ui-grid ui-grid--3" data-dept-grid>
            <div class="ui-loading"><span class="ui-spinner"></span><span>Loading departments…</span></div>
        </div>
    </section>

    <section data-dept-detail class="ui-stack" hidden>
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow">
                <button type="button" class="ui-backlink" data-action="dept.back"><?= ui_icon('chevron-left') ?><span>Departments</span></button>
            </div>
            <?= ui_button('Download', ['variant' => 'ghost', 'icon' => 'download', 'attrs' => ['data-action' => 'dept.download']]) ?>
        </div>

        <div class="ui-card">
            <div class="ui-card__body ui-stack">
                <div class="ui-toolbar">
                    <div class="ui-toolbar__grow">
                        <h2 class="ui-h2" data-dept-name></h2>
                        <p class="ui-meta" data-dept-desc></p>
                        <div class="ui-chips" data-dept-badges></div>
                    </div>
                    <div class="ui-toolbar__end">
                        <?= ui_button('Edit settings', ['icon' => 'pencil', 'attrs' => ['data-action' => 'dept.edit', 'data-dept-manage' => '1']]) ?>
                        <?= ui_button('Add member', ['variant' => 'primary', 'icon' => 'user-round-plus', 'attrs' => ['data-action' => 'dept.add', 'data-dept-manage' => '1']]) ?>
                    </div>
                </div>
                <div class="ui-grid ui-grid--3" data-dept-seats></div>
            </div>
        </div>

        <section class="ui-card" data-dept-subunits-card>
            <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Sub-units</h3></div>
            <div class="ui-list" data-dept-subunits></div>
        </section>

        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h3 class="ui-h3">Primary members</h3><span class="ui-count" data-dept-primary-count>0</span>
                    <?= ui_info('The core team: people whose main commitment is this department.', 'About Primary members') ?></div>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Member</th><th scope="col">Seat</th><th scope="col">Phone</th><th scope="col">Joined</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-dept-primary></tbody>
                </table>
            </div>
        </section>

        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h3 class="ui-h3">Secondary members</h3><span class="ui-count" data-dept-secondary-count>0</span>
                    <?= ui_info('People who serve occasionally: a bench that moves up to Primary in one click.', 'About Secondary members') ?></div>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Member</th><th scope="col">Seat</th><th scope="col">Phone</th><th scope="col">Joined</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-dept-secondary></tbody>
                </table>
            </div>
        </section>
    </section>
    </div>

    <!-- Attendance ------------------------------------------------------ -->
    <section id="panel-attendance" data-tab-panel="attendance" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow">
                <label class="sr-only" for="datt-event">Event</label>
                <select class="ui-select" id="datt-event" data-action-change="datt.event"><option value="">Choose an event</option></select>
            </div>
            <div class="ui-toolbar__end">
                <label class="ui-check items-center"><input type="checkbox" class="ui-switch" id="datt-live" checked data-action-change="datt.live"><span>Live</span></label>
                <?= ui_button('Refresh', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'icon_only' => true, 'attrs' => ['data-action' => 'datt.refresh']]) ?>
<?php if (can('departments', 'export')): ?>
                <?= ui_button('Export absent', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => '#', 'attrs' => ['id' => 'datt-export', 'hidden' => true]]) ?>
<?php endif; ?>
            </div>
        </div>

        <div id="datt-none">
            <?= ui_empty(['icon' => 'user-check', 'title' => 'Pick an event', 'text' => 'Its workers show here.']) ?>
        </div>

        <div class="ui-stack ui-stack--md" id="datt-body" hidden>
            <div class="ui-grid ui-grid--stats">
                <div data-datt-stat="workers"><?= ui_stat(['label' => 'Workers', 'value' => '—', 'icon' => 'users']) ?></div>
                <div data-datt-stat="present"><?= ui_stat(['label' => 'Present', 'value' => '—', 'icon' => 'circle-check']) ?></div>
                <div data-datt-stat="absent"><?= ui_stat(['label' => 'Absent', 'value' => '—', 'icon' => 'circle-alert']) ?></div>
                <div data-datt-stat="percent"><?= ui_stat(['label' => 'Turnout', 'value' => '—', 'icon' => 'chart-column']) ?></div>
            </div>
            <progress class="ui-progress ui-progress--success" id="datt-progress" value="0" max="100" aria-label="Workers present"></progress>

            <div class="ui-toolbar">
                <div class="ui-toolbar__grow ui-search">
                    <?= ui_icon('search') ?>
                    <label class="sr-only" for="datt-search">Search workers</label>
                    <input class="ui-input" type="search" id="datt-search" placeholder="Name or phone" autocomplete="off" enterkeyhint="search" data-action-input="datt.search">
                </div>
                <div class="ui-toolbar__end">
                    <?= ui_info('Search, then press Enter to clock in the only match. Someone clocked in by mistake can be moved back; a door check-in stays.', 'About clocking in') ?>
                    <div class="ui-segmented" role="group" aria-label="Show">
                        <button type="button" class="ui-segmented__item" data-action="datt.view" data-view="dept" aria-pressed="true">By department</button>
                        <button type="button" class="ui-segmented__item" data-action="datt.view" data-view="absent" aria-pressed="false">Absent <span class="ui-count" data-datt-count="absent">0</span></button>
                        <button type="button" class="ui-segmented__item" data-action="datt.view" data-view="present" aria-pressed="false">Present <span class="ui-count" data-datt-count="present">0</span></button>
                    </div>
                </div>
            </div>
            <p class="ui-meta" id="datt-updated" aria-live="polite"></p>

            <div id="datt-view" class="ui-stack ui-stack--md" aria-live="polite"></div>
        </div>
    </section>
</div>

<?= ui_dialog_start('dept-edit-dialog', ['title' => 'New department', 'size' => 'lg']) ?>
    <form class="ui-form" id="dept-edit-form" data-action="dept.save" novalidate>
        <input type="hidden" name="department_id" value="">
        <input type="hidden" name="ministry_year_id" value="">
        <?= ui_field('text', ['name' => 'name', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 120]]) ?>
        <?= ui_field('textarea', ['name' => 'description', 'label' => 'Description', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'type', 'label' => 'Type', 'options' => $types, 'value' => 'Service_Unit']) ?>
            <?= ui_field('select', ['name' => 'target_demographic', 'label' => 'Who may join', 'options' => $demographics, 'value' => 'All']) ?>
        </div>
        <?= ui_field('select', ['name' => 'parent_id', 'label' => 'Part of', 'options' => ['' => 'Nothing: a main department'],
            'info' => 'Pick a parent only for a sub-unit, e.g. Audio under Envision.']) ?>
        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Leaders <?= ui_info('Picking someone new relieves the outgoing leader and takes them off this roster; they are told and can be added back. Empty leaves the seat as it is.', 'About changing leaders') ?></legend>
            <div class="ui-form-grid ui-form-grid--3">
<?php foreach ($leaders as $key => [$field]): ?>
                <?= ui_field('select', ['name' => $field, 'label' => (string) ($seatTypes[$key]['label'] ?? $key), 'options' => ['' => '—'],
                    'class' => 'dept-leader-field', 'attrs' => ['data-ui-combobox' => true, 'data-leader' => $key]]) ?>
<?php endforeach; ?>
            </div>
        </fieldset>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save department', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'dept-edit-form']])
) ?>

<?= ui_dialog_start('dept-add-dialog', ['title' => 'Add member', 'size' => 'sm']) ?>
    <form class="ui-form" id="dept-add-form" data-action="dept.add.save" novalidate>
        <input type="hidden" name="department_id" value="">
        <p class="ui-meta" data-dept-add-where></p>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'Person', 'required' => true, 'options' => ['' => ''],
            'attrs' => ['data-ui-combobox' => true],
            'info' => 'People already on this roster this year are left out. Someone removed earlier can be added straight back.']) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'membership_type', 'label' => 'Membership', 'options' => ['Primary' => 'Primary', 'Secondary' => 'Secondary']]) ?>
            <?= ui_field('select', ['name' => 'role_in_dept', 'label' => 'Seat', 'options' => dept_member_seats(), 'value' => dept_seat_value('worker')]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add member', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'dept-add-form']])
) ?>

<?= ui_dialog_start('dept-seat-dialog', ['title' => 'Change seat', 'size' => 'sm']) ?>
    <form class="ui-form" id="dept-seat-form" data-action="dept.seat.save" novalidate>
        <input type="hidden" name="role" value="">
        <p class="ui-meta" data-dept-seat-now></p>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'New holder', 'options' => ['' => ''], 'attrs' => ['data-ui-combobox' => true]]) ?>
        <label class="ui-check"><input type="checkbox" name="vacate" value="1"><span>Leave the seat vacant</span></label>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save seat', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'dept-seat-form']])
) ?>

<?= ui_dialog_start('dept-download-dialog', ['title' => 'Download lists', 'size' => 'sm']) ?>
    <div class="ui-stack">
        <p class="ui-meta" data-dept-download-summary>Preparing the roster…</p>
        <div class="ui-list">
            <div class="ui-list__item">
                <div class="ui-list__main"><p class="ui-list__title">A4 image <?= ui_info('One A4 page at 300 dpi: logo, ministry year, every department with its leaders and members. Type shrinks to fit very large rosters.', 'About the A4 image') ?></p></div>
                <div class="ui-list__end"><?= ui_button('Save image', ['icon' => 'image', 'attrs' => ['data-action' => 'dept.download.image']]) ?></div>
            </div>
            <div class="ui-list__item">
                <div class="ui-list__main"><p class="ui-list__title">Excel workbook <?= ui_info('A summary sheet, one sheet per department, and an "All workers" sheet with phone and email.', 'About the Excel workbook') ?></p></div>
                <div class="ui-list__end"><?= ui_button('Save Excel', ['icon' => 'file-down', 'attrs' => ['data-action' => 'dept.download.file', 'data-format' => 'xlsx']]) ?></div>
            </div>
            <div class="ui-list__item">
                <div class="ui-list__main"><p class="ui-list__title">CSV list <?= ui_info('Every worker in every department, names only. Opens in anything.', 'About the CSV list') ?></p></div>
                <div class="ui-list__end"><?= ui_button('Save CSV', ['icon' => 'file-down', 'attrs' => ['data-action' => 'dept.download.file', 'data-format' => 'csv']]) ?></div>
            </div>
        </div>
        <p class="ui-meta">Removed members never appear in a download.</p>
    </div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('dept-years-dialog', ['title' => 'Ministry years', 'size' => 'lg']) ?>
    <div class="ui-stack">
        <div class="ui-list" data-dept-years></div>
        <form class="ui-card" id="dept-year-form" data-action="dept.year.save" novalidate>
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h3 class="ui-h3">Add or edit a year</h3>
                    <?= ui_info('A year can be added here quietly, or built with the Start new year worksheet, which also carries people over.', 'About adding a year') ?></div>
            </div>
            <div class="ui-card__body">
                <input type="hidden" name="id" value="">
                <div class="ui-form-grid ui-form-grid--3">
                    <?= ui_field('text', ['name' => 'label', 'label' => 'Label', 'placeholder' => '2027', 'required' => true]) ?>
                    <?= ui_field('date', ['name' => 'start_date', 'label' => 'Starts', 'required' => true]) ?>
                    <?= ui_field('date', ['name' => 'end_date', 'label' => 'Ends', 'required' => true]) ?>
                </div>
                <label class="ui-check"><input type="checkbox" name="is_active" value="1"><span>Make it the live year</span></label>
            </div>
            <div class="ui-card__footer ui-form-actions">
                <?= ui_button('Save year', ['variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('dept-rollover-dialog', ['title' => 'Start new year', 'size' => 'xl']) ?>
    <div class="ui-stack">
        <p class="ui-meta" data-dept-ro-from></p>
        <div class="ui-form-grid ui-form-grid--3">
            <?= ui_field('text', ['name' => 'ro_label', 'id' => 'dept-ro-label', 'label' => 'Year label', 'placeholder' => '2027']) ?>
            <?= ui_field('date', ['name' => 'ro_start', 'id' => 'dept-ro-start', 'label' => 'Starts']) ?>
            <?= ui_field('date', ['name' => 'ro_end', 'id' => 'dept-ro-end', 'label' => 'Ends']) ?>
        </div>
        <div class="ui-toolbar">
            <span class="ui-meta">Start from <?= ui_info('Carry everyone: all seats and members as they are. Members only: leaders are appointed again. Start empty: a blank sheet. Then rearrange each department.', 'About the starting points') ?></span>
            <div class="ui-segmented" role="group" aria-label="Start from">
                <button type="button" class="ui-segmented__item" data-action="dept.ro.preset" data-mode="carry" aria-pressed="false">Carry everyone</button>
                <button type="button" class="ui-segmented__item" data-action="dept.ro.preset" data-mode="members" aria-pressed="true">Members only</button>
                <button type="button" class="ui-segmented__item" data-action="dept.ro.preset" data-mode="empty" aria-pressed="false">Start empty</button>
            </div>
        </div>
        <div class="ui-stack" data-dept-ro-body>
            <div class="ui-loading"><span class="ui-spinner"></span><span>Loading the roster…</span></div>
        </div>
    </div>
<?= ui_dialog_end(
    '<p class="ui-meta" data-dept-ro-summary></p>'
    . ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Go live', ['variant' => 'primary', 'icon' => 'circle-check', 'attrs' => ['data-action' => 'dept.ro.commit']])
) ?>

<?= ui_json_script('departments-boot', [
    'api' => '/api/department_api.php',
    'exportUrl' => '/api/department_export_excel.php',
    'seatLabels' => dept_role_labels(),
    'leaders' => array_map(static fn ($k) => ['value' => dept_seat_value($k), 'label' => (string) ($seatTypes[$k]['label'] ?? $k),
        'field' => $leaders[$k][0], 'prefix' => $leaders[$k][1]], array_combine(array_keys($leaders), array_keys($leaders))),
    'subUnitHead' => dept_seat_value('sub_unit_head'),
    'memberSeat' => dept_seat_value('member'),
    'types' => $types,
    'demographics' => $demographics,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
