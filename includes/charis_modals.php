<?php
// /includes/charis_modals.php
// The Charis dialogs (native <dialog> via ui_dialog_start), required by
// modules/charis/index.php after the panels. The page sets $charisManager,
// $charisMonths and $charisYears. Ids avoid the word "modal" on purpose
// (docs/UI.md): those belong to the legacy modal manager.
require_once __DIR__ . '/charis_helpers.php';

/** Mic, Tidy and the tidied-text review under a notes box (charis.js "dictation"). */
function charis_dictation(string $target, string $kind): string
{
    return '<div class="mt-2 flex flex-wrap items-center gap-2">'
        . ui_button('Speak instead', ['variant' => 'soft', 'size' => 'sm', 'icon' => 'mic-vocal', 'icon_only' => true,
            'attrs' => ['data-action' => 'charis.dictate', 'data-target' => $target, 'data-dictate-mic' => $target, 'aria-pressed' => 'false']])
        . ui_button('Tidy', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'sparkles',
            'attrs' => ['data-action' => 'charis.tidy', 'data-target' => $target, 'data-kind' => $kind, 'data-dictate-clean' => $target]])
        . '<p class="ui-hint" data-dictate-hint="' . ui_e($target) . '" hidden>Listening… tap the mic to stop.</p>'
        . '</div>'
        . '<div class="ui-banner ui-banner--info mt-2" data-dictate-review="' . ui_e($target) . '" hidden>'
        . '<div class="ui-banner__body"><p class="ui-banner__title">Tidied. Keep it?</p>'
        . '<p class="ui-banner__text whitespace-pre-line" data-dictate-clean-text></p>'
        . '<div class="ui-banner__actions">'
        . ui_button('Use this', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'charis.tidy-use', 'data-target' => $target]])
        . ui_button('Keep mine', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'charis.tidy-drop', 'data-target' => $target]])
        . '</div></div></div>';
}

$charisStatuses = ['Member' => true, 'Worker' => true, 'Pastor' => true, '1st_Timer' => false, '2nd_Timer' => false,
    '3rd_Timer' => false, 'Visitor' => false, 'Non_Member' => false];
$charisBudgetYears = array_slice($charisYears, 0, 3, true);
$charisCancel = ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]);
?>

<?= ui_dialog_start('charis-birthdays', ['title' => 'Birthdays', 'description' => 'This month and next.', 'size' => 'drawer']) ?>
    <ul class="ui-list" id="charis-birthdays-list" aria-live="polite"></ul>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('charis-life-events', ['title' => 'Life events', 'description' => 'Weddings, dedications and milestones.', 'size' => 'drawer']) ?>
    <ul class="ui-list" id="charis-life-list" aria-live="polite"></ul>
<?= ui_dialog_end(ui_button('Log event', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'charis-life-event']])) ?>

<?= ui_dialog_start('charis-awol-config', ['title' => 'Change the AWOL rule', 'description' => 'Who lands on the AWOL list, church-wide.', 'size' => 'lg']) ?>
    <form class="ui-form" id="charis-awol-config-form" data-action="charis.awol-save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="save_awol_config">
        <input type="hidden" name="is_first_time" id="charis-awol-first" value="0">
        <input type="hidden" name="service_rules" id="charis-awol-rules" value="">
        <div class="ui-form-grid">
            <?= ui_field('number', ['name' => 'consecutive_threshold', 'id' => 'charis-awol-threshold', 'label' => 'Sundays in a row', 'required' => true,
                'value' => (int) CHARIS_AWOL_DEFAULT_CONSECUTIVE, 'inputmode' => 'numeric', 'info' => 'Missed in a row, counted since they were last present.',
                'attrs' => ['min' => 1, 'max' => 52, 'data-action-input' => 'charis.awol-preview']]) ?>
            <?= ui_field('number', ['name' => 'archive_weeks', 'id' => 'charis-awol-archive', 'label' => 'Dormant after (weeks)', 'required' => true,
                'value' => (int) CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS, 'inputmode' => 'numeric', 'info' => 'Unbroken weeks away before a case is marked Dormant.',
                'attrs' => ['min' => 1, 'max' => 260, 'data-action-input' => 'charis.awol-preview']]) ?>
        </div>

        <fieldset class="ui-fieldset">
            <div class="ui-label-row">
                <legend class="ui-legend">Services</legend>
                <?= ui_info('Members travel far and miss midweek for work, so a missed midweek flags no one. Being at midweek still resets the count.', 'About the services') ?>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--compact">
                    <thead><tr><th scope="col">Service</th><th scope="col">Missing counts</th><th scope="col">Attending resets</th></tr></thead>
                    <tbody>
<?php foreach (['Sunday_Service' => ['Sunday service', true, true], 'Midweek_Service' => ['Midweek service', false, true]] as $svc => [$svcLabel, $counts, $resets]): ?>
                        <tr>
                            <th scope="row" class="font-medium"><?= ui_e($svcLabel) ?></th>
                            <td><label class="ui-check"><input type="checkbox" data-service="<?= ui_e($svc) ?>" data-rule="counts" data-action-change="charis.awol-preview"<?= $counts ? ' checked' : '' ?>><span class="sr-only"><?= ui_e($svcLabel) ?>: missing counts</span></label></td>
                            <td><label class="ui-check"><input type="checkbox" data-service="<?= ui_e($svc) ?>" data-rule="resets" data-action-change="charis.awol-preview"<?= $resets ? ' checked' : '' ?>><span class="sr-only"><?= ui_e($svcLabel) ?>: attending resets</span></label></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </fieldset>

        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Statuses to watch</legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" id="charis-awol-statuses">
<?php foreach ($charisStatuses as $status => $on): ?>
                <label class="ui-check"><input type="checkbox" name="spiritual_statuses[]" value="<?= ui_e($status) ?>" data-action-change="charis.awol-preview"<?= $on ? ' checked' : '' ?>><span><?= ui_e(str_replace('_', ' ', $status)) ?></span></label>
<?php endforeach; ?>
            </div>
        </fieldset>

        <?= ui_field('text', ['name' => 'change_reason', 'id' => 'charis-awol-reason', 'label' => 'Reason for the change', 'optional' => true]) ?>

        <div class="ui-banner ui-banner--info">
            <div class="ui-banner__body">
                <p class="ui-banner__title">The rule</p>
                <p class="ui-banner__text" id="charis-awol-preview" aria-live="polite"></p>
            </div>
        </div>

        <details class="rounded-ui border border-line">
            <summary class="cursor-pointer px-3 py-2 text-sm font-semibold">Rule history</summary>
            <div class="space-y-2 px-3 pb-3" id="charis-awol-history"></div>
        </details>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Save rule', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-awol-config-form']])) ?>

<?= ui_dialog_start('charis-recap', ['title' => 'Monthly recap', 'description' => 'This month\'s celebrants, ready for slides.', 'size' => 'xl']) ?>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" id="charis-recap-grid" aria-live="polite"></div>
<?= ui_dialog_end(ui_button('Export', ['variant' => 'primary', 'icon' => 'download', 'attrs' => ['id' => 'charis-recap-export', 'data-action' => 'charis.recap-export']])) ?>

<?= ui_dialog_start('charis-assign-worker', ['title' => 'Assign a worker', 'description' => 'Who takes care of this celebration?', 'size' => 'sm']) ?>
    <form class="ui-form" id="charis-assign-worker-form" data-action="charis.form" data-reload="dashboard" data-ui-validate novalidate>
        <input type="hidden" name="action" value="assign_celebration">
        <input type="hidden" name="target_user_id" id="charis-assign-target">
        <input type="hidden" name="event_type" id="charis-assign-type">
        <input type="hidden" name="event_date" id="charis-assign-date">
        <div class="rounded-ui bg-surface-sunken p-3">
            <p class="font-semibold text-ink" id="charis-assign-name"></p>
            <p class="text-sm text-ink-muted" id="charis-assign-badge"></p>
        </div>
        <?= ui_field('select', ['name' => 'worker_id', 'id' => 'charis-worker-select', 'label' => 'Worker', 'required' => true, 'options' => ['' => 'Pick a worker']]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Assign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-assign-worker-form']])) ?>

<?= ui_dialog_start('charis-life-event', ['title' => 'Log life event', 'description' => 'A wedding, a dedication, a birth or another milestone.']) ?>
    <form class="ui-form" id="charis-life-event-form" data-action="charis.form" data-reload="dashboard" data-ui-validate novalidate>
        <input type="hidden" name="action" value="add_life_event">
        <?= ui_field('select', ['name' => 'user_id', 'id' => 'charis-member-select', 'label' => 'Member', 'required' => true, 'options' => ['' => 'Pick a member'], 'attrs' => ['data-ui-combobox' => true]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'event_type', 'id' => 'charis-life-type', 'label' => 'Event', 'required' => true,
                'options' => ['Wedding_Anniversary' => 'Wedding anniversary', 'Baby_Dedication' => 'Baby dedication', 'Child_Birth' => 'Child birth', 'Other' => 'Other'],
                'attrs' => ['data-action-change' => 'charis.life-type']]) ?>
            <?= ui_field('date', ['name' => 'event_date', 'id' => 'charis-life-date', 'label' => 'Date', 'required' => true]) ?>
        </div>
        <?= ui_field('text', ['name' => 'custom_event_type', 'id' => 'charis-life-custom', 'label' => 'Event name', 'placeholder' => 'Graduation', 'class' => 'hidden']) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Save event', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-life-event-form']])) ?>

<?= ui_dialog_start('charis-case', ['title' => 'Manage case', 'description' => ' ', 'size' => 'lg']) ?>
    <div class="mb-4 grid grid-cols-2 gap-2">
        <?= ui_button('Call', ['variant' => 'secondary', 'icon' => 'phone', 'href' => '#', 'attrs' => ['id' => 'charis-case-call']]) ?>
        <?= ui_button('WhatsApp', ['variant' => 'secondary', 'icon' => 'message-circle', 'href' => '#', 'attrs' => ['id' => 'charis-case-whatsapp', 'target' => '_blank', 'rel' => 'noopener']]) ?>
    </div>
    <form class="ui-form" id="charis-case-form" data-action="charis.form" data-reload="dashboard" data-close="charis-case" data-ui-validate novalidate>
        <input type="hidden" name="action" value="update_awol_status">
        <input type="hidden" name="user_id" id="charis-case-user">
        <input type="hidden" name="followup_id" id="charis-case-followup">
        <div>
            <?= ui_field('textarea', ['name' => 'comments', 'id' => 'charis-case-notes', 'label' => 'What you found', 'required' => true, 'placeholder' => 'Spoke to them. Travelling for work, back next week.', 'attrs' => ['rows' => 3]]) ?>
            <?= charis_dictation('charis-case-notes', 'update') ?>
        </div>
        <div class="flex justify-end"><?= ui_button('Save note', ['variant' => 'secondary', 'type' => 'submit']) ?></div>
    </form>

    <hr class="ui-divider my-5">

    <form class="ui-form" id="charis-resolve-form" data-action="charis.form" data-reload="dashboard" data-close="charis-case" data-ui-validate novalidate>
        <input type="hidden" name="action" value="resolve_awol_case">
        <input type="hidden" name="user_id" id="charis-resolve-user">
        <input type="hidden" name="followup_id" id="charis-resolve-followup" value="0">
        <h3 class="ui-h3">Close the case</h3>
        <?= ui_field('select', ['name' => 'attendance_status', 'id' => 'charis-resolve-status', 'label' => 'Final status', 'required' => true,
            'info' => 'Relocated and Attends another church leave the AWOL list for good. Active and Unknown stay monitored.',
            'options' => ['' => 'Pick the outcome', 'Active' => 'Active: back and attending', 'Relocated' => 'Relocated', 'Attends_Another_Church' => 'Attends another church', 'Unknown' => 'Unknown: cannot be reached']]) ?>
        <div>
            <?= ui_field('textarea', ['name' => 'comments', 'id' => 'charis-resolve-notes', 'label' => 'Summary', 'required' => true, 'placeholder' => 'A short summary for the record', 'attrs' => ['rows' => 2]]) ?>
            <?= charis_dictation('charis-resolve-notes', 'resolve') ?>
        </div>
        <div class="flex justify-end"><?= ui_button('Resolve', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'circle-check']) ?></div>
    </form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('charis-assign-case', ['title' => 'Assign case', 'description' => 'Who follows up with this member?', 'size' => 'sm']) ?>
    <form class="ui-form" id="charis-assign-case-form" data-action="charis.form" data-reload="dashboard" data-ui-validate novalidate>
        <input type="hidden" name="action" value="assign_welfare_case">
        <input type="hidden" name="target_user_id" id="charis-assign-case-target">
        <input type="hidden" name="followup_id" id="charis-assign-case-followup">
        <?= ui_field('select', ['name' => 'worker_id', 'id' => 'charis-case-worker', 'label' => 'Worker', 'required' => true, 'options' => ['' => 'Pick a worker']]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Assign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-assign-case-form']])) ?>

<?php if ($charisManager): ?>
<?= ui_dialog_start('charis-awol-report', ['title' => 'AWOL report', 'description' => 'A confidential PDF for pastoral review.', 'size' => 'sm']) ?>
    <div class="ui-form">
        <div class="ui-segmented" role="group" aria-label="Report period">
            <button type="button" class="ui-segmented__item" data-action="charis.report-mode" data-mode="month" aria-pressed="true">By month</button>
            <button type="button" class="ui-segmented__item" data-action="charis.report-mode" data-mode="range" aria-pressed="false">Date range</button>
        </div>
        <div class="ui-form-grid" data-report-fields="month">
            <?= ui_field('select', ['name' => 'month', 'id' => 'charis-rpt-month', 'label' => 'Month', 'options' => $charisMonths, 'value' => (int) date('n')]) ?>
            <?= ui_field('select', ['name' => 'year', 'id' => 'charis-rpt-year', 'label' => 'Year', 'options' => $charisYears]) ?>
        </div>
        <div class="ui-form-grid" data-report-fields="range" hidden>
            <?= ui_field('date', ['name' => 'date_start', 'id' => 'charis-rpt-from', 'label' => 'From']) ?>
            <?= ui_field('date', ['name' => 'date_end', 'id' => 'charis-rpt-to', 'label' => 'To']) ?>
        </div>
    </div>
<?= ui_dialog_end($charisCancel . ui_button('Download PDF', ['variant' => 'primary', 'icon' => 'file-down', 'attrs' => ['data-action' => 'charis.report-download']])) ?>
<?php endif; ?>

<?= ui_dialog_start('charis-notes', ['title' => 'Welfare notes', 'description' => ' ', 'size' => 'lg']) ?>
    <div class="space-y-3" id="charis-notes-list" aria-live="polite"></div>
    <form class="ui-form mt-5 border-t border-line pt-5" id="charis-note-form" data-action="charis.note-save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="save_charis_note">
        <input type="hidden" name="target_user_id" id="charis-note-target">
        <input type="hidden" name="note_id" id="charis-note-id" value="">
        <div>
            <?= ui_field('textarea', ['name' => 'note_text', 'id' => 'charis-note-text', 'label' => 'New note', 'required' => true, 'placeholder' => 'Type or speak what you found', 'attrs' => ['rows' => 3]]) ?>
            <?= charis_dictation('charis-note-text', 'update') ?>
        </div>
        <label class="ui-check" id="charis-note-pastor-wrap">
            <input type="checkbox" name="pastor_only" id="charis-note-pastor" value="1">
            <span>Pastors only<span class="ui-check__hint">Hidden from everyone but pastors.</span></span>
        </label>
        <div class="flex justify-end gap-2">
            <?= ui_button('Cancel edit', ['variant' => 'ghost', 'attrs' => ['id' => 'charis-note-cancel', 'data-action' => 'charis.note-reset', 'hidden' => true]]) ?>
            <?= ui_button('Save note', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['id' => 'charis-note-save']]) ?>
        </div>
    </form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('charis-event-tasks', ['title' => 'Event tasks', 'description' => ' ', 'size' => 'lg']) ?>
    <div class="mb-4 flex justify-end">
        <?= ui_button('Report', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'file-down', 'href' => '#', 'attrs' => ['id' => 'charis-event-pdf', 'target' => '_blank', 'rel' => 'noopener']]) ?>
    </div>
    <section class="ui-card ui-card--sunken mb-5" id="charis-task-add" hidden aria-labelledby="charis-task-add-title">
        <div class="ui-card__body">
            <h3 class="ui-h3 mb-3" id="charis-task-add-title">New task</h3>
            <form class="ui-form" id="charis-task-form" data-action="charis.task-add" data-ui-validate novalidate>
                <input type="hidden" name="action" value="add_event_task">
                <input type="hidden" name="event_id" id="charis-task-event">
                <?= ui_field('text', ['name' => 'task_title', 'id' => 'charis-task-title', 'label' => 'Task', 'required' => true, 'placeholder' => 'Water and food']) ?>
                <?= ui_field('text', ['name' => 'task_description', 'id' => 'charis-task-desc', 'label' => 'Details', 'optional' => true]) ?>
                <div class="ui-form-grid ui-form-grid--3">
                    <?= ui_field('select', ['name' => 'task_category', 'id' => 'charis-task-category', 'label' => 'Kind', 'options' => ['Logistics' => 'Logistics', 'Supplies' => 'Supplies']]) ?>
                    <?= ui_field('select', ['name' => 'assigned_worker_id', 'id' => 'charis-task-worker', 'label' => 'Member', 'options' => ['' => 'Pick a member']]) ?>
                    <?= ui_field('number', ['name' => 'estimated_budget', 'id' => 'charis-task-budget', 'label' => 'Budget (₦)', 'optional' => true, 'inputmode' => 'decimal', 'attrs' => ['min' => 0, 'step' => '0.01']]) ?>
                </div>
                <div class="flex justify-end"><?= ui_button('Add task', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'plus']) ?></div>
            </form>
        </div>
    </section>
    <div class="space-y-3" id="charis-tasks" aria-live="polite"></div>
    <section class="mt-5 space-y-3 border-t border-line pt-5" id="charis-signoff" hidden aria-label="Sign-off">
        <div class="space-y-2" id="charis-stamps"></div>
        <div class="flex flex-wrap justify-end gap-2">
            <?= ui_button('Sign off', ['variant' => 'primary', 'icon' => 'shield-check', 'attrs' => ['id' => 'charis-stamp-hod', 'data-action' => 'charis.stamp', 'data-stamp' => 'hod', 'hidden' => true]]) ?>
            <?= ui_button('Countersign', ['variant' => 'brand', 'icon' => 'shield-check', 'attrs' => ['id' => 'charis-stamp-fin', 'data-action' => 'charis.stamp', 'data-stamp' => 'finance', 'hidden' => true]]) ?>
        </div>
    </section>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('charis-task-report', ['title' => 'Task report', 'description' => ' ']) ?>
    <form class="ui-form" id="charis-task-report-form" data-action="charis.task-report" data-ui-validate novalidate>
        <input type="hidden" name="action" value="update_event_task">
        <input type="hidden" name="task_id" id="charis-report-task">
        <input type="hidden" name="status" value="Completed">
        <?= ui_field('textarea', ['name' => 'member_report', 'id' => 'charis-report-text', 'label' => 'What you bought or did', 'required' => true, 'placeholder' => '4 cartons of water from the market', 'attrs' => ['rows' => 3]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('number', ['name' => 'actual_spent', 'id' => 'charis-report-spent', 'label' => 'Amount spent (₦)', 'required' => true, 'inputmode' => 'decimal', 'attrs' => ['min' => 0, 'step' => '0.01']]) ?>
            <?= ui_field('text', ['name' => 'receipt_note', 'id' => 'charis-report-receipt', 'label' => 'Receipt number', 'optional' => true]) ?>
        </div>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Submit report', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-task-report-form']])) ?>

<?= ui_dialog_start('charis-budget', ['title' => 'Set budget', 'description' => 'The approved welfare budget for a month.', 'size' => 'sm']) ?>
    <form class="ui-form" id="charis-budget-form" data-action="charis.form" data-reload="finance" data-ui-validate novalidate>
        <input type="hidden" name="action" value="set_monthly_budget">
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'budget_month', 'id' => 'charis-budget-month', 'label' => 'Month', 'options' => $charisMonths, 'value' => (int) date('n')]) ?>
            <?= ui_field('select', ['name' => 'budget_year', 'id' => 'charis-budget-year', 'label' => 'Year', 'options' => $charisBudgetYears]) ?>
        </div>
        <?= ui_field('number', ['name' => 'amount', 'id' => 'charis-budget-amount', 'label' => 'Amount (₦)', 'required' => true, 'inputmode' => 'decimal', 'attrs' => ['min' => 0, 'step' => '0.01']]) ?>
        <?= ui_field('text', ['name' => 'notes', 'id' => 'charis-budget-notes', 'label' => 'Notes', 'optional' => true]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Save budget', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-budget-form']])) ?>

<?= ui_dialog_start('charis-expense', ['title' => 'Log expense', 'description' => 'A purchase or request against the welfare budget.']) ?>
    <form class="ui-form" id="charis-expense-form" data-action="charis.form" data-reload="finance" data-ui-validate novalidate>
        <input type="hidden" name="action" value="log_expense">
        <?= ui_field('text', ['name' => 'item_name', 'id' => 'charis-expense-item', 'label' => 'Item or purpose', 'required' => true, 'placeholder' => 'Sunday service water']) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'category', 'id' => 'charis-expense-category', 'label' => 'Category', 'required' => true,
                'options' => ['Food_Beverage' => 'Food and drinks', 'Pastoral_Care' => 'Pastoral care', 'Stationery' => 'Stationery', 'Decor' => 'Decor', 'Other' => 'Other']]) ?>
            <?= ui_field('number', ['name' => 'amount_requested', 'id' => 'charis-expense-amount', 'label' => 'Amount (₦)', 'required' => true, 'inputmode' => 'decimal', 'attrs' => ['min' => 0, 'step' => '0.01']]) ?>
        </div>
        <?= ui_field('select', ['name' => 'event_id', 'id' => 'charis-expense-event', 'label' => 'Event', 'optional' => true, 'options' => ['' => 'No event']]) ?>
        <?= ui_field('textarea', ['name' => 'notes', 'id' => 'charis-expense-notes', 'label' => 'Notes', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Log expense', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-expense-form']])) ?>

<datalist id="charis-book-categories"></datalist>

<?= ui_dialog_start('charis-add-book', ['title' => 'Add book', 'description' => 'A new title for the library.', 'size' => 'lg']) ?>
    <form class="ui-form" id="charis-book-form" data-action="charis.form" data-reload="library" data-ui-validate novalidate enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_new_book">
        <div class="ui-form-grid">
            <?= ui_field('file', ['name' => 'cover_image', 'id' => 'charis-cover-front', 'label' => 'Front cover', 'optional' => true, 'attrs' => ['accept' => 'image/*']]) ?>
            <?= ui_field('file', ['name' => 'back_cover', 'id' => 'charis-cover-back', 'label' => 'Back cover', 'optional' => true, 'attrs' => ['accept' => 'image/*']]) ?>
        </div>
        <div class="flex items-center gap-2">
            <?= ui_button('Read cover', ['variant' => 'soft', 'size' => 'sm', 'icon' => 'sparkles', 'attrs' => ['id' => 'charis-read-cover', 'data-action' => 'charis.read-cover']]) ?>
            <?= ui_info('Fills in the title, author and description from the cover pictures. Check them before saving.', 'About reading the cover') ?>
        </div>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'title', 'id' => 'charis-book-title', 'label' => 'Title', 'required' => true]) ?>
            <?= ui_field('text', ['name' => 'author', 'id' => 'charis-book-author', 'label' => 'Author', 'required' => true]) ?>
            <?= ui_field('text', ['name' => 'category', 'id' => 'charis-book-category', 'label' => 'Category', 'required' => true, 'attrs' => ['list' => 'charis-book-categories']]) ?>
            <?= ui_field('select', ['name' => 'book_type', 'id' => 'charis-book-type', 'label' => 'Format', 'required' => true,
                'options' => ['Physical' => 'Printed', 'E-Book' => 'E-book', 'Both' => 'Both'], 'attrs' => ['data-action-change' => 'charis.book-type']]) ?>
            <?= ui_field('number', ['name' => 'total_copies', 'id' => 'charis-book-copies', 'label' => 'Copies', 'value' => 1, 'inputmode' => 'numeric', 'class' => 'charis-physical', 'attrs' => ['min' => 0]]) ?>
            <?= ui_field('file', ['name' => 'ebook_file', 'id' => 'charis-book-ebook', 'label' => 'E-book PDF', 'class' => 'charis-ebook hidden', 'attrs' => ['accept' => 'application/pdf']]) ?>
            <?= ui_field('url', ['name' => 'audiobook_link', 'id' => 'charis-book-audio', 'label' => 'Audiobook link', 'optional' => true, 'placeholder' => 'https://']) ?>
            <?= ui_field('text', ['name' => 'estimated_read_time', 'id' => 'charis-book-time', 'label' => 'Reading time', 'optional' => true, 'placeholder' => '6 hours']) ?>
        </div>
        <?= ui_field('textarea', ['name' => 'description', 'id' => 'charis-book-desc', 'label' => 'Description', 'optional' => true, 'attrs' => ['rows' => 3]]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Add book', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-book-form']])) ?>

<?= ui_dialog_start('charis-edit-book', ['title' => 'Edit book', 'description' => ' ', 'size' => 'lg']) ?>
    <form class="ui-form" id="charis-edit-book-form" data-action="charis.form" data-reload="library" data-ui-validate novalidate enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit_book">
        <input type="hidden" name="book_id" id="charis-edit-id">
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'title', 'id' => 'charis-edit-title', 'label' => 'Title', 'required' => true]) ?>
            <?= ui_field('text', ['name' => 'author', 'id' => 'charis-edit-author', 'label' => 'Author', 'required' => true]) ?>
            <?= ui_field('text', ['name' => 'category', 'id' => 'charis-edit-category', 'label' => 'Category', 'required' => true, 'attrs' => ['list' => 'charis-book-categories']]) ?>
            <?= ui_field('select', ['name' => 'book_type', 'id' => 'charis-edit-type', 'label' => 'Format',
                'options' => ['Physical' => 'Printed', 'E-Book' => 'E-book', 'Both' => 'Both'], 'attrs' => ['data-action-change' => 'charis.book-type']]) ?>
            <?= ui_field('number', ['name' => 'total_copies', 'id' => 'charis-edit-copies', 'label' => 'Copies', 'inputmode' => 'numeric', 'class' => 'charis-physical', 'attrs' => ['min' => 0]]) ?>
            <?= ui_field('url', ['name' => 'audiobook_link', 'id' => 'charis-edit-audio', 'label' => 'Audiobook link', 'optional' => true, 'placeholder' => 'https://']) ?>
            <?= ui_field('text', ['name' => 'estimated_read_time', 'id' => 'charis-edit-time', 'label' => 'Reading time', 'optional' => true]) ?>
            <?= ui_field('file', ['name' => 'cover_image', 'id' => 'charis-edit-cover', 'label' => 'New cover', 'optional' => true, 'attrs' => ['accept' => 'image/*']]) ?>
            <?= ui_field('file', ['name' => 'ebook_file', 'id' => 'charis-edit-ebook', 'label' => 'New e-book PDF', 'optional' => true, 'attrs' => ['accept' => 'application/pdf']]) ?>
        </div>
        <?= ui_field('textarea', ['name' => 'description', 'id' => 'charis-edit-desc', 'label' => 'Description', 'optional' => true, 'attrs' => ['rows' => 3]]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Save changes', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-edit-book-form']])) ?>

<?= ui_dialog_start('charis-return-book', ['title' => 'Book return', 'description' => ' ', 'size' => 'sm']) ?>
    <form class="ui-form" id="charis-return-form" data-action="charis.form" data-reload="library" data-ui-validate novalidate>
        <input type="hidden" name="action" value="process_book_return">
        <input type="hidden" name="borrow_id" id="charis-return-id">
        <?= ui_field('select', ['name' => 'condition', 'id' => 'charis-return-condition', 'label' => 'Condition', 'options' => ['Good' => 'Good', 'Damaged' => 'Damaged', 'Lost' => 'Lost']]) ?>
    </form>
<?= ui_dialog_end($charisCancel . ui_button('Confirm return', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'charis-return-form']])) ?>

<?= ui_dialog_start('charis-portrait', ['title' => 'Edit portrait', 'description' => 'Crop the photo for the slides.', 'size' => 'lg']) ?>
    <input type="hidden" id="charis-portrait-ref">
    <div class="mx-auto max-h-[60vh] max-w-sm overflow-hidden rounded-ui bg-surface-sunken">
        <img id="charis-cropper-img" class="block max-w-full" src="data:," alt="The portrait being cropped">
    </div>
    <div class="ui-segmented mt-4 overflow-x-auto" role="group" aria-label="Photo filter">
<?php foreach (['none' => 'Original', 'grayscale' => 'Black and white', 'sepia' => 'Sepia', 'brightness' => 'Brighter', 'contrast' => 'Contrast'] as $filter => $filterLabel): ?>
        <button type="button" class="ui-segmented__item" data-action="charis.portrait-filter" data-filter="<?= ui_e($filter) ?>" aria-pressed="<?= $filter === 'none' ? 'true' : 'false' ?>"><?= ui_e($filterLabel) ?></button>
<?php endforeach; ?>
    </div>
    <div class="mt-4">
        <?= ui_field('file', ['name' => 'portrait', 'id' => 'charis-portrait-upload', 'label' => 'Another photo', 'optional' => true, 'attrs' => ['accept' => 'image/*', 'data-action-change' => 'charis.portrait-upload']]) ?>
    </div>
<?= ui_dialog_end($charisCancel . ui_button('Save portrait', ['variant' => 'primary', 'attrs' => ['data-action' => 'charis.portrait-save']])) ?>
