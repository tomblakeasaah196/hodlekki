<?php
// /modules/tribes/index.php
/**
 * Tribes: the house fellowships. Every member sees their own tribe, its
 * meetings and its duty roster; the church-wide list is for those who hold
 * Tribes church-wide.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/tribes.js, data from api/tribes_api.php.
 *
 * Access: Roles & permissions, module "tribes". View opens the page (your own
 * tribe); View church-wide adds All tribes; Create church-wide adds New tribe.
 * Scheduling, reports and the roster are for your own tribe (or any, with the
 * church-wide view). The API checks each again.
 */
$ui = [
    'title'   => 'Tribes',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/tribes.js'],
];
require_once '../../includes/header.php';
require_can('tribes');

$canGlobal = can('tribes', 'view', 'church');
$canCreateTribe = can('tribes', 'create', 'church');
$canRun = can('tribes', 'create');
$mine = $pdo->prepare('SELECT tribe_id FROM user_tribes WHERE user_id = ? LIMIT 1');
$mine->execute([(int) $_SESSION['user_id']]);
$hasTribe = (bool) $mine->fetchColumn();

$tabs = [];
if ($canGlobal) {
    $tabs['all'] = ['label' => 'All tribes', 'icon' => 'users-round'];
}
$tabs['mine'] = ['label' => 'My tribe', 'icon' => 'house'];
if ($hasTribe) {
    $tabs['meetings'] = ['label' => 'Meetings', 'icon' => 'calendar'];
    $tabs['roster'] = ['label' => 'Roster', 'icon' => 'clipboard-list'];
}
$first = array_key_first($tabs);
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Tribes',
        'description' => 'House fellowships, their meetings and duty rosters.',
        'info' => 'Each member belongs to one tribe. Your tribe\'s meetings are listed for two weeks after they happen, so a report can be written afterwards. '
            . 'The roster assigns duties for a meeting to people in the tribe.',
        'actions' => $canCreateTribe ? ui_button('New tribe', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'trb.tribe.new']]) : '',
    ]) ?>

    <div class="mb-4" data-trb-error hidden><?= ui_banner('danger', ['text' => 'Tribes did not load: reload the page to try again.']) ?></div>

    <?= ui_tabs($tabs, $first) ?>

<?php if ($canGlobal): ?>
    <section data-tab-panel="all" id="panel-all" role="tabpanel" aria-labelledby="tab-all" class="ui-tab-panel ui-stack"<?= $first === 'all' ? '' : ' hidden' ?>>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Tribe</th><th scope="col">Captain</th><th scope="col">Meets</th><th scope="col">Where</th><th scope="col" class="is-num">Members</th></tr></thead>
                    <tbody data-trb-all><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

    <section data-tab-panel="mine" id="panel-mine" role="tabpanel" aria-labelledby="tab-mine" class="ui-tab-panel ui-stack"<?= $first === 'mine' ? '' : ' hidden' ?>>
<?php if (!$hasTribe): ?>
        <?= ui_empty(['icon' => 'house', 'title' => 'No tribe yet', 'text' => 'Ask the church office to add you to a house fellowship.']) ?>
<?php else: ?>
        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3" data-trb-name>…</h2></div>
            <div class="ui-card__body">
                <dl class="ui-dl">
                    <dt>Meets</dt><dd data-trb-day>—</dd>
                    <dt>Where</dt><dd data-trb-where>—</dd>
                </dl>
            </div>
        </section>
        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Members</h3></div>
            <div class="ui-list" data-trb-members></div>
        </section>
<?php endif; ?>
    </section>

<?php if ($hasTribe): ?>
    <section data-tab-panel="meetings" id="panel-meetings" role="tabpanel" aria-labelledby="tab-meetings" class="ui-tab-panel ui-stack" hidden>
<?php if ($canRun): ?>
        <div class="ui-toolbar">
            <span class="ui-toolbar__grow"></span>
            <?= ui_button('Schedule', ['icon' => 'plus', 'attrs' => ['data-action' => 'trb.activity.new']]) ?>
        </div>
<?php endif; ?>
        <section class="ui-card">
            <div class="ui-list" data-trb-meetings></div>
        </section>
    </section>

    <section data-tab-panel="roster" id="panel-roster" role="tabpanel" aria-labelledby="tab-roster" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-toolbar">
            <label class="sr-only" for="trb-roster-event">Meeting</label>
            <select class="ui-select ui-toolbar__grow" id="trb-roster-event" data-action-change="trb.roster.event">
                <option value="">Choose a meeting…</option>
            </select>
        </div>
        <div class="ui-grid ui-grid--sidebar" data-trb-roster hidden>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Duties</h3></div>
                <div class="ui-list" data-trb-duties></div>
            </section>
<?php if ($canRun): ?>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Assign a duty</h3></div>
                <div class="ui-card__body">
                    <form class="ui-form" id="trb-roster-form" data-action="trb.roster.save" novalidate>
                        <?= ui_field('text', ['name' => 'task_name', 'label' => 'Duty', 'required' => true, 'placeholder' => 'Opening prayer', 'attrs' => ['maxlength' => 100]]) ?>
                        <?= ui_field('select', ['name' => 'assigned_user_id', 'label' => 'Who', 'required' => true, 'options' => ['' => 'Choose…']]) ?>
                        <?= ui_button('Assign', ['variant' => 'primary', 'type' => 'submit']) ?>
                    </form>
                </div>
            </section>
<?php endif; ?>
        </div>
        <div data-trb-roster-empty><?= ui_empty(['icon' => 'clipboard-list', 'title' => 'Choose a meeting', 'compact' => true]) ?></div>
    </section>
<?php endif; ?>
</div>

<?php if ($canCreateTribe): ?>
<?= ui_dialog_start('trb-tribe-dialog', ['title' => 'New tribe', 'size' => 'sm']) ?>
    <form class="ui-form" id="trb-tribe-form" data-action="trb.tribe.save" novalidate>
        <?= ui_field('text', ['name' => 'name', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 100]]) ?>
        <?= ui_field('text', ['name' => 'location_address', 'label' => 'Where it meets', 'optional' => true, 'placeholder' => 'Address or area', 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('select', ['name' => 'meeting_day', 'label' => 'Meeting day', 'options' => array_combine($days, $days), 'value' => 'Sunday']) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Create tribe', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-tribe-form']])
) ?>
<?php endif; ?>

<?php if ($hasTribe && $canRun): ?>
<?= ui_dialog_start('trb-activity-dialog', ['title' => 'Schedule a meeting', 'size' => 'sm']) ?>
    <form class="ui-form" id="trb-activity-form" data-action="trb.activity.save" novalidate>
        <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => 'Weekly Rhema fellowship', 'attrs' => ['maxlength' => 150]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'category', 'label' => 'Kind', 'options' => ['Tribe_Meeting' => 'Rhema / meeting', 'Evangelism' => 'Outreach']]) ?>
            <?= ui_field('datetime-local', ['name' => 'event_date', 'label' => 'When', 'required' => true]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Schedule', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-activity-form']])
) ?>

<?= ui_dialog_start('trb-report-dialog', ['title' => 'Meeting report']) ?>
    <form class="ui-form" id="trb-report-form" data-action="trb.report.save" novalidate>
        <input type="hidden" name="event_id" value="">
        <p class="ui-meta" data-trb-report-for></p>
        <?= ui_field('textarea', ['name' => 'report_notes', 'label' => 'What happened', 'required' => true,
            'info' => 'Key points, testimonies and the impact made.', 'attrs' => ['rows' => 6]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save report', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-report-form']])
) ?>
<?php endif; ?>

<?= ui_json_script('tribes-boot', [
    'api' => '/api/tribes_api.php',
    'canGlobal' => $canGlobal,
    'canRun' => $canRun,
    'hasTribe' => $hasTribe,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
