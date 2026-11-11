<?php
// /modules/embrace/index.php
// Embrace: the first-timers pipeline (record, assign a worker, log calls,
// notes, push to the congregation), growth insights, the archive, and the
// date-range export (JPEG summary or Excel). Data: /api/embrace_api.php.
$ui = [
    'title' => 'Embrace Center',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/embrace.js'],
    // The address search (Geoapify) runs in the browser.
    'csp' => ['connect-src' => ['https://api.geoapify.com']],
];
require_once '../../includes/header.php';

$embraceVisit = ['None' => 'Does not want a visit', 'In-Person' => 'In person', 'Virtual' => 'Virtual (video call)'];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Embrace Center',
        'description' => 'Follow-up, spiritual growth and first-timer integration.',
        'actions' => ui_button('Export', ['variant' => 'secondary', 'icon' => 'download', 'attrs' => ['data-dialog-open' => 'emb-export']])
            . ui_button('New first timer', ['variant' => 'primary', 'icon' => 'user-round-plus', 'attrs' => ['data-action' => 'emb.new']]),
    ]) ?>

    <?= ui_tabs([
        'pipeline' => ['label' => 'Pipeline', 'icon' => 'users'],
        'insights' => ['label' => 'Growth insights', 'icon' => 'chart-column'],
        'archive' => ['label' => 'Archive', 'icon' => 'history'],
    ], 'pipeline', 'Embrace sections') ?>

    <section id="panel-pipeline" data-tab-panel="pipeline" class="ui-tab-panel">
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-segmented" role="group" aria-label="Whose first timers">
                    <button type="button" class="ui-segmented__item" data-action="emb.view" data-view="all" aria-pressed="true">All first timers</button>
                    <button type="button" class="ui-segmented__item" data-action="emb.view" data-view="mine" aria-pressed="false">My assignments <span class="ui-count" data-emb-mine-count hidden>0</span></button>
                </div>
                <div class="w-full sm:w-72">
                    <label class="sr-only" for="emb-search">Search names or phone</label>
                    <input class="ui-input" type="search" id="emb-search" placeholder="Search names or phone" autocomplete="off" data-action-input="emb.search">
                </div>
            </div>
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead>
                        <tr><th scope="col">Person</th><th scope="col">Joined</th><th scope="col">Markers</th><th scope="col">Follow-up</th><th scope="col">Contact</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                    </thead>
                    <tbody data-emb-rows>
<?php for ($i = 0; $i < 5; $i++): ?>
                        <tr><td colspan="6"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
<?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="panel-insights" data-tab-panel="insights" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--3 ui-grid--stats mb-6">
            <?= ui_stat(['label' => 'First timers', 'value' => '—', 'icon' => 'users']) ?>
            <?= ui_stat(['label' => 'Followed up', 'value' => '—', 'icon' => 'circle-check']) ?>
            <?= ui_stat(['label' => 'Ready to join', 'value' => '—', 'icon' => 'sprout']) ?>
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="ui-card lg:col-span-2">
                <div class="ui-card__header"><h2 class="ui-card__title">First timers over time</h2></div>
                <div class="ui-card__body pt-0"><div class="h-72"><canvas data-emb-chart="trend" role="img" aria-label="First timers by day"></canvas></div></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">How they heard about us</h2></div>
                <div class="ui-card__body pt-0"><div class="h-72"><canvas data-emb-chart="source" role="img" aria-label="First timers by how they heard about us"></canvas></div></div>
            </section>
        </div>
    </section>

    <section id="panel-archive" data-tab-panel="archive" class="ui-tab-panel" hidden>
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Congregation archive</h2>
                    <p class="ui-card__description">Pushed to the congregation, with their notes.</p>
                </div>
            </div>
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Integrated</th><th scope="col">First timer</th><th scope="col">Worker</th><th scope="col">Notes</th></tr></thead>
                    <tbody data-emb-archive></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<?= ui_dialog_start('emb-visitor', ['title' => 'New first timer', 'description' => 'Welcome them home with excellence.', 'size' => 'lg']) ?>
    <form class="ui-form" id="emb-visitor-form" data-action="emb.save-visitor" data-ui-validate novalidate>
        <input type="hidden" name="action" value="add_visitor">
        <input type="hidden" name="visitor_id" value="">
        <div class="flex items-center gap-4">
            <span class="ui-avatar ui-avatar--xl" data-emb-photo><?= ui_icon('user') ?></span>
            <div class="min-w-0 flex-1">
                <?= ui_field('file', ['name' => 'profile_pic', 'id' => 'emb-photo', 'label' => 'Photo', 'optional' => true, 'attrs' => ['accept' => 'image/*', 'data-action-change' => 'emb.photo']]) ?>
            </div>
        </div>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'first_name', 'id' => 'emb-first', 'label' => 'First name', 'required' => true, 'autocomplete' => 'off']) ?>
            <?= ui_field('text', ['name' => 'last_name', 'id' => 'emb-last', 'label' => 'Last name', 'required' => true, 'autocomplete' => 'off']) ?>
            <?= ui_field('tel', ['name' => 'phone', 'id' => 'emb-phone', 'label' => 'Phone', 'required' => true, 'inputmode' => 'tel', 'autocomplete' => 'off']) ?>
            <?= ui_field('email', ['name' => 'email', 'id' => 'emb-email', 'label' => 'Personal email', 'optional' => true, 'autocomplete' => 'off']) ?>
            <?= ui_field('select', ['name' => 'gender', 'id' => 'emb-gender', 'label' => 'Gender', 'options' => ['Male' => 'Male', 'Female' => 'Female']]) ?>
            <?= ui_field('select', ['name' => 'marital_status', 'id' => 'emb-marital', 'label' => 'Marital status', 'options' => ['Single' => 'Single', 'Married' => 'Married', 'Separated' => 'Separated', 'Divorced' => 'Divorced'], 'attrs' => ['data-action-change' => 'emb.conditional']]) ?>
            <div data-emb-anniversary hidden>
                <?= ui_field('date', ['name' => 'wedding_anniversary', 'id' => 'emb-anniversary', 'label' => 'Wedding anniversary', 'optional' => true]) ?>
            </div>
            <?= ui_field('select', ['name' => 'invitation_source', 'id' => 'emb-source', 'label' => 'How they heard about us', 'required' => true, 'options' => [
                'Self_Discovery' => 'Found us themselves (walk-in, map)',
                'Church_Member' => 'Invited by a church member',
                'Social_Media' => 'Social media',
                'Flyer_Billboard' => 'Flyer or billboard',
                'Other' => 'Other (special event, HQ)',
            ], 'attrs' => ['data-action-change' => 'emb.conditional']]) ?>
            <div data-emb-invited hidden>
                <div class="ui-field">
                    <label class="ui-label" for="emb-invited" data-emb-invited-label>Details</label>
                    <input class="ui-input" type="text" id="emb-invited" name="invited_by" autocomplete="off">
                </div>
            </div>
            <div class="ui-field ui-span-full">
                <label class="ui-label" for="emb-address-search">Home address</label>
                <div class="ui-geo" data-emb-address></div>
                <p class="ui-hint">Start typing and choose the address from the list.</p>
                <input type="hidden" name="physical_address" value="">
                <input type="hidden" name="latitude" value="">
                <input type="hidden" name="longitude" value="">
            </div>
        </div>
        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Spiritual and welfare needs</legend>
            <div class="ui-stack ui-stack--sm">
                <label class="ui-check"><input type="checkbox" name="is_born_again"><span>Born again</span></label>
                <label class="ui-check"><input type="checkbox" name="wants_to_join"><span>Wants to join the church</span></label>
            </div>
            <div class="ui-form-grid mt-4">
                <?= ui_field('select', ['name' => 'visitation_preference', 'id' => 'emb-visit', 'label' => 'Visit', 'options' => $embraceVisit]) ?>
                <?= ui_field('textarea', ['name' => 'prayer_requests', 'id' => 'emb-prayer', 'label' => 'Prayer requests', 'optional' => true, 'class' => 'ui-span-full', 'placeholder' => 'What are we standing with them for?', 'attrs' => ['rows' => '3']]) ?>
            </div>
        </fieldset>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save record', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'emb-visitor-form', 'data-emb-visitor-save' => true]])
) ?>

<?= ui_dialog_start('emb-notes', ['title' => 'Follow-up notes', 'description' => ' ']) ?>
    <ul class="ui-stack ui-stack--sm" data-emb-notes-list></ul>
    <form class="ui-form mt-5 border-t border-line pt-5" id="emb-note-form" data-action="emb.save-note" data-ui-validate novalidate>
        <input type="hidden" name="action" value="save_note">
        <input type="hidden" name="followup_id" value="">
        <input type="hidden" name="note_id" value="">
        <?= ui_field('textarea', ['name' => 'note_text', 'id' => 'emb-note-text', 'label' => 'Add a note', 'required' => true, 'attrs' => ['rows' => '3']]) ?>
        <fieldset class="ui-fieldset">
            <legend class="ui-label">Intended for <?= ui_info('Every note is visible to everyone with Embrace access, and in the Excel register. This records who you meant it for.', 'About note visibility') ?></legend>
            <div class="flex flex-wrap gap-4">
                <label class="ui-check"><input type="checkbox" name="vis_all" value="1" checked data-action-change="emb.vis"><span>Everyone</span></label>
                <label class="ui-check"><input type="checkbox" name="vis_pastor" value="1" data-action-change="emb.vis"><span>Pastors only</span></label>
            </div>
            <input type="hidden" name="vis_director" value="">
            <input type="hidden" name="vis_worker" value="">
        </fieldset>
        <div class="flex justify-end gap-2">
            <?= ui_button('Cancel edit', ['variant' => 'ghost', 'attrs' => ['data-action' => 'emb.note-reset', 'data-emb-note-cancel' => true, 'hidden' => true]]) ?>
            <?= ui_button('Save note', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['data-emb-note-save' => true]]) ?>
        </div>
    </form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('emb-assign', ['title' => 'Assign a worker', 'description' => ' ', 'size' => 'sm']) ?>
    <form class="ui-form" id="emb-assign-form" data-action="emb.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="assign_followup">
        <input type="hidden" name="visitor_id" value="">
        <?= ui_field('select', ['name' => 'worker_id', 'id' => 'emb-worker', 'label' => 'Embrace team member', 'required' => true, 'options' => ['' => 'Loading the team…'], 'attrs' => ['data-ui-combobox' => true]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Assign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'emb-assign-form']])
) ?>

<?= ui_dialog_start('emb-log', ['title' => 'Log the call', 'description' => ' ']) ?>
    <form class="ui-form" id="emb-log-form" data-action="emb.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="log_followup">
        <input type="hidden" name="followup_id" value="">
        <input type="hidden" name="visitor_id" value="">
        <?= ui_field('select', ['name' => 'status', 'id' => 'emb-log-status', 'label' => 'Outcome', 'required' => true, 'options' => [
            'Completed' => 'Reached and talked it through',
            'Unreachable' => 'Unreachable, no response',
            'In_Progress' => 'Spoke briefly, needs a second call',
        ]]) ?>
        <?= ui_field('textarea', ['name' => 'followup_notes', 'id' => 'emb-log-notes', 'label' => 'What you talked about', 'required' => true, 'placeholder' => 'What did they enjoy? Any prayer requests?', 'attrs' => ['rows' => '4']]) ?>
        <fieldset class="ui-fieldset">
            <legend class="ui-label">Confirmed on the call</legend>
            <div class="ui-stack ui-stack--sm">
                <label class="ui-check"><input type="checkbox" name="is_born_again"><span>Received Christ (born again)</span></label>
                <label class="ui-check"><input type="checkbox" name="wants_to_join"><span>Wants to join the workforce or a tribe</span></label>
            </div>
        </fieldset>
        <?= ui_field('select', ['name' => 'visitation_preference', 'id' => 'emb-log-visit', 'label' => 'Visit', 'options' => $embraceVisit]) ?>
        <label class="ui-check"><input type="checkbox" name="needs_pastor"><span>Flag for a pastor's attention</span></label>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save result', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'emb-log-form']])
) ?>

<?= ui_dialog_start('emb-report', ['title' => 'Follow-up report', 'description' => ' ', 'size' => 'sm']) ?>
    <dl class="ui-dl ui-dl--stacked">
        <dt>Outcome</dt><dd>Completed</dd>
        <dt>Worker's notes</dt><dd class="whitespace-pre-line" data-emb-report-notes></dd>
    </dl>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?= ui_dialog_start('emb-promote', ['title' => 'Push to the congregation', 'description' => ' ', 'size' => 'sm']) ?>
    <form class="ui-form" id="emb-promote-form" data-action="emb.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="promote_member">
        <input type="hidden" name="visitor_id" value="">
        <?= ui_field('select', ['name' => 'spiritual_status', 'id' => 'emb-promote-status', 'label' => 'Growth stage', 'required' => true, 'options' => ['Member' => 'Member', 'Worker' => 'Worker', 'Pastor' => 'Pastor', 'Non_Member' => 'Regular attendee (non-member)']]) ?>
        <?= ui_field('select', ['name' => 'attendance_status', 'id' => 'emb-promote-attendance', 'label' => 'Attendance', 'required' => true, 'options' => ['New' => 'New (still integrating)', 'Active' => 'Active, consistent', 'Inconsistent' => 'Inconsistent']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Push to congregation', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'emb-promote-form']])
) ?>

<?= ui_dialog_start('emb-export', ['title' => 'Export first timers', 'description' => 'Pick the dates, then the format.', 'size' => 'sm']) ?>
    <div class="ui-form-grid">
        <?= ui_field('date', ['name' => 'start', 'id' => 'emb-export-start', 'label' => 'From', 'required' => true, 'value' => date('Y-m-d', strtotime('-1 week'))]) ?>
        <?= ui_field('date', ['name' => 'end', 'id' => 'emb-export-end', 'label' => 'To', 'required' => true, 'value' => date('Y-m-d')]) ?>
    </div>
<?= ui_dialog_end(
    ui_button('Image summary', ['variant' => 'secondary', 'icon' => 'image', 'attrs' => ['data-action' => 'emb.jpeg']])
    . ui_button('Excel list', ['variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-action' => 'emb.excel']])
) ?>

<?= ui_json_script('emb-config', [
    'me' => (int) ($_SESSION['user_id'] ?? 0),
    'geoapifyKey' => $_ENV['GEOAPIFY_API_KEY'] ?? '7a189b607e9e4c4cbedf6ada291b6bc1',
]) ?>

<?php require_once '../../includes/footer.php'; ?>
