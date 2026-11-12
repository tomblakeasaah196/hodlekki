<?php
// /modules/zoe/index.php
/**
 * Zoe, the intercessory unit. Anyone who may open Zoe sends a prayer request;
 * Zoe members see the sessions and their prayer topics, the War Room, the
 * church's requests and the roster; Zoe / Manage runs it.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/zoe.js, data from api/zoe_api.php.
 *
 * Access: Roles & permissions, module "zoe", plus Zoe membership (the active
 * Zoe roster, or the team of the department that owns Zoe). The API checks
 * each again.
 */
$currentModule = 'zoe';
$ui = [
    'title'   => 'Zoe',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/zoe.js'],
    'offline' => false,   // prayer requests: never an old copy
];
require_once '../../includes/header.php';
require_can('zoe');

$uid = (int) $_SESSION['user_id'];
$isAdmin = can('zoe', 'manage');
$onRoster = $pdo->prepare('SELECT 1 FROM zoe_roster WHERE user_id = ? AND is_active = 1 LIMIT 1');
$onRoster->execute([$uid]);
$isMember = $isAdmin || (bool) $onRoster->fetchColumn() || in_array($uid, array_map('intval', perm_module_team($pdo, 'zoe')), true);
$canRequest = can('zoe', 'create');

$tabs = [
    'sessions' => ['label' => 'Sessions', 'icon' => 'calendar'],
    'war_room' => ['label' => 'War Room', 'icon' => 'flame'],
    'requests' => ['label' => 'Requests', 'icon' => 'heart-handshake'],
    'roster' => ['label' => 'Roster', 'icon' => 'users'],
];
$actions = '';
if ($isAdmin) {
    $actions = ui_button('New campaign', ['icon' => 'flag', 'attrs' => ['data-action' => 'zoe.campaign.new']])
        . ui_button('Schedule session', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'zoe.session.new']]);
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Zoe Intercessory Unit',
        'description' => $isMember ? 'Sessions, the War Room and prayer requests.' : 'Send a prayer request to the Zoe team.',
        'info' => $isMember
            ? 'Sessions carry the prayer topics and who leads each one. The War Room is where the team shares what they receive in prayer. Requests are the church\'s prayer requests, with who sent them.'
            : 'The Zoe intercessors will stand with you in prayer. Your request is seen only by the Zoe team.',
        'actions' => $actions,
    ]) ?>

<?php if (!$isMember): ?>
    <section class="ui-card ui-page--narrow">
        <div class="ui-card__body">
<?php if ($canRequest): ?>
            <form class="ui-form" id="zoe-public-form" data-action="zoe.request.save" novalidate>
                <?= ui_field('textarea', ['name' => 'request_text', 'label' => 'Your prayer request', 'required' => true, 'attrs' => ['rows' => 6, 'maxlength' => 5000]]) ?>
                <?= ui_button('Send request', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send']) ?>
            </form>
<?php else: ?>
            <?= ui_empty(['icon' => 'heart-handshake', 'title' => 'Requests closed', 'text' => 'Ask the church office if you think they should be open.']) ?>
<?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <div class="mb-4" data-zoe-error hidden><?= ui_banner('danger', ['text' => 'Zoe did not load: reload the page to try again.']) ?></div>

    <?= ui_tabs($tabs, 'sessions') ?>

    <section data-tab-panel="sessions" id="panel-sessions" role="tabpanel" aria-labelledby="tab-sessions" class="ui-tab-panel ui-stack">
        <div class="ui-stack" data-zoe-session-list>
            <section class="ui-card" data-zoe-campaigns-card hidden>
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Campaigns</h2></div>
                <div class="ui-list" data-zoe-campaigns></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Upcoming sessions</h2></div>
                <div class="ui-list" data-zoe-sessions></div>
            </section>
        </div>

        <div class="ui-stack" data-zoe-session-detail hidden>
            <div class="ui-toolbar">
                <?= ui_button('All sessions', ['variant' => 'ghost', 'icon' => 'chevron-left', 'attrs' => ['data-action' => 'zoe.session.back']]) ?>
                <span class="ui-toolbar__grow"></span>
<?php if ($isAdmin): ?>
                <?= ui_button('Roll-call', ['icon' => 'clipboard-list', 'attrs' => ['data-action' => 'zoe.roll.open']]) ?>
                <?= ui_button('Add topic', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'zoe.topic.new']]) ?>
<?php endif; ?>
            </div>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3" data-zoe-detail-title>…</h2></div>
                <div class="ui-card__body"><dl class="ui-dl" data-zoe-detail-meta></dl></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Prayer topics</h3></div>
                <div class="ui-list" data-zoe-topics></div>
            </section>
        </div>
    </section>

    <section data-tab-panel="war_room" id="panel-war_room" role="tabpanel" aria-labelledby="tab-war_room" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--sidebar">
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Latest</h2></div>
                <div class="ui-list" data-zoe-war></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Share a prompting</h2></div>
                <div class="ui-card__body">
                    <form class="ui-form" id="zoe-war-form" data-action="zoe.war.save" novalidate>
                        <?= ui_field('select', ['name' => 'post_type', 'label' => 'What it is', 'options' => [
                            'General_Note' => 'General note', 'Scripture' => 'Scripture received', 'Vision' => 'Prophetic vision', 'Word_of_Knowledge' => 'Word of knowledge',
                        ]]) ?>
                        <?= ui_field('textarea', ['name' => 'message', 'label' => 'Message', 'required' => true, 'attrs' => ['rows' => 4, 'maxlength' => 5000]]) ?>
                        <?= ui_button('Post', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send']) ?>
                    </form>
                </div>
            </section>
        </div>
    </section>

    <section data-tab-panel="requests" id="panel-requests" role="tabpanel" aria-labelledby="tab-requests" class="ui-tab-panel ui-stack" hidden>
<?php if ($canRequest): ?>
        <div class="ui-toolbar">
            <span class="ui-toolbar__grow"></span>
            <?= ui_button('Send request', ['icon' => 'plus', 'attrs' => ['data-action' => 'zoe.request.new']]) ?>
        </div>
<?php endif; ?>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">From</th><th scope="col">Request</th><th scope="col">Status</th></tr></thead>
                    <tbody data-zoe-requests></tbody>
                </table>
            </div>
        </div>
        <p class="ui-meta">The latest 100 requests.</p>
    </section>

    <section data-tab-panel="roster" id="panel-roster" role="tabpanel" aria-labelledby="tab-roster" class="ui-tab-panel ui-stack" hidden>
<?php if ($isAdmin): ?>
        <div class="ui-toolbar">
            <span class="ui-toolbar__grow"></span>
            <?= ui_button('Add volunteer', ['icon' => 'user-round-plus', 'attrs' => ['data-action' => 'zoe.volunteer.new']]) ?>
        </div>
<?php endif; ?>
        <section class="ui-card">
            <div class="ui-list" data-zoe-roster></div>
        </section>
    </section>
<?php endif; ?>
</div>

<?php if ($isMember && $canRequest): ?>
<?= ui_dialog_start('zoe-request-dialog', ['title' => 'Prayer request', 'size' => 'sm']) ?>
    <form class="ui-form" id="zoe-request-form" data-action="zoe.request.save" novalidate>
        <?= ui_field('textarea', ['name' => 'request_text', 'label' => 'Your prayer request', 'required' => true, 'attrs' => ['rows' => 6, 'maxlength' => 5000]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send request', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'zoe-request-form']])
) ?>
<?php endif; ?>

<?php if ($isMember): ?>
<?= ui_dialog_start('zoe-reader-dialog', ['title' => 'Prayer focus', 'size' => 'lg']) ?>
    <p data-zoe-reader-bible hidden><span class="ui-badge ui-badge--primary"></span></p>
    <div class="whitespace-pre-line break-words text-lg leading-relaxed" data-zoe-reader-points></div>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])) ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<?= ui_dialog_start('zoe-campaign-dialog', ['title' => 'New campaign', 'size' => 'sm']) ?>
    <form class="ui-form" id="zoe-campaign-form" data-action="zoe.campaign.save" novalidate>
        <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => '21 days of fasting', 'attrs' => ['maxlength' => 150]]) ?>
        <?= ui_field('textarea', ['name' => 'theme_description', 'label' => 'Theme', 'optional' => true, 'attrs' => ['rows' => 3]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('date', ['name' => 'start_date', 'label' => 'Starts', 'required' => true]) ?>
            <?= ui_field('date', ['name' => 'end_date', 'label' => 'Ends', 'required' => true]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save campaign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'zoe-campaign-form']])
) ?>

<?= ui_dialog_start('zoe-session-dialog', ['title' => 'Schedule a session']) ?>
    <form class="ui-form" id="zoe-session-form" data-action="zoe.session.save" novalidate>
        <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => 'Friday vigil', 'attrs' => ['maxlength' => 150]]) ?>
        <div class="ui-form-grid ui-form-grid--3">
            <?= ui_field('date', ['name' => 'session_date', 'label' => 'Date', 'required' => true]) ?>
            <?= ui_field('time', ['name' => 'start_time', 'label' => 'Starts', 'required' => true]) ?>
            <?= ui_field('time', ['name' => 'end_time', 'label' => 'Ends', 'required' => true]) ?>
        </div>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'meeting_type', 'label' => 'Where', 'options' => ['In-Person' => 'In person', 'Virtual' => 'Online']]) ?>
            <?= ui_field('select', ['name' => 'campaign_id', 'label' => 'Campaign', 'optional' => true, 'options' => ['' => 'None']]) ?>
        </div>
        <?= ui_field('text', ['name' => 'location_or_link', 'label' => 'Place or link', 'required' => true, 'placeholder' => 'Prayer hall, or https://…', 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('file', ['name' => 'bulletin_pdf', 'label' => 'Prayer bulletin', 'optional' => true, 'hint' => 'PDF only.', 'attrs' => ['accept' => '.pdf,application/pdf']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Schedule', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'zoe-session-form']])
) ?>

<?= ui_dialog_start('zoe-topic-dialog', ['title' => 'Add a prayer topic']) ?>
    <form class="ui-form" id="zoe-topic-form" data-action="zoe.topic.save" novalidate>
        <?= ui_field('text', ['name' => 'topic_title', 'label' => 'Topic', 'required' => true, 'placeholder' => 'Divine protection', 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('textarea', ['name' => 'prayer_points', 'label' => 'Prayer points', 'required' => true, 'info' => 'Paste them as they are, from WhatsApp or anywhere; line breaks are kept.', 'attrs' => ['rows' => 6]]) ?>
        <?= ui_field('text', ['name' => 'bible_reference', 'label' => 'Bible reference', 'optional' => true, 'placeholder' => 'Psalm 91:1-4', 'attrs' => ['maxlength' => 255]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'allocated_time', 'label' => 'Time slot', 'optional' => true, 'placeholder' => '12:00–1:00am', 'attrs' => ['maxlength' => 50]]) ?>
            <?= ui_field('select', ['name' => 'assigned_user_id', 'label' => 'Leader', 'optional' => true, 'options' => ['' => 'Nobody yet'], 'attrs' => ['data-ui-combobox' => true]]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add topic', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'zoe-topic-form']])
) ?>

<?= ui_dialog_start('zoe-roll-dialog', ['title' => 'Roll-call', 'size' => 'drawer']) ?>
    <p class="ui-meta">Tick everyone who is here.</p>
    <div class="ui-list" data-zoe-roll></div>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save roll-call', ['variant' => 'primary', 'attrs' => ['data-action' => 'zoe.roll.save']])
) ?>

<?= ui_dialog_start('zoe-volunteer-dialog', ['title' => 'Add a volunteer', 'size' => 'sm']) ?>
    <form class="ui-form" id="zoe-volunteer-form" data-action="zoe.volunteer.save" novalidate>
        <?= ui_field('select', ['name' => 'user_id', 'label' => 'Member', 'required' => true, 'options' => ['' => 'Choose…'], 'attrs' => ['data-ui-combobox' => true]]) ?>
        <?= ui_field('select', ['name' => 'roster_type', 'label' => 'As', 'options' => ['Volunteer' => 'Volunteer', 'Official_Worker' => 'Official worker']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'zoe-volunteer-form']])
) ?>
<?php endif; ?>

<?= ui_json_script('zoe-boot', [
    'api' => '/api/zoe_api.php',
    'isMember' => $isMember,
    'isAdmin' => $isAdmin,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
