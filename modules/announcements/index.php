<?php
// /modules/announcements/index.php
/**
 * Announcements: notices drafted for a service or event, approved before they
 * are read out.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/announcements.js, data from api/announcements_api.php.
 *
 * Access: Roles & permissions, module "announcements". View opens the
 * register, Create drafts a notice (Pending), Approve approves or archives,
 * Delete removes one. A notice can be changed by its author while Pending,
 * or by anyone who approves. The API checks each again.
 */
$ui = [
    'title'   => 'Announcements',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/announcements.js'],
];
require_once '../../includes/header.php';
require_can('announcements');

$canCreate = can('announcements', 'create');
$canApprove = can('announcements', 'approve');
$canDelete = can('announcements', 'delete');
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Announcements',
        'description' => 'Notices for upcoming services and events.',
        'info' => 'Anyone can draft a notice for an event. It waits as Pending until a pastor or leader approves it. '
            . 'You can change your own notice while it is still pending.',
        'actions' => $canCreate ? ui_button('New notice', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'ann.new']]) : '',
    ]) ?>

    <div class="ui-stack">
        <div data-ann-error hidden><?= ui_banner('danger', ['text' => 'Announcements did not load: reload the page to try again.']) ?></div>

        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="ann-search">Find a notice</label>
                <input class="ui-input" type="search" id="ann-search" placeholder="Title or event" data-action-input="ann.filter" autocomplete="off">
            </div>
            <label class="sr-only" for="ann-status">Show</label>
            <select class="ui-select" id="ann-status" data-action-change="ann.filter">
                <option value="">All</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Archived">Archived</option>
            </select>
        </div>

        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Event</th><th scope="col">Notice</th><th scope="col">Status</th>
                        <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-ann-body><tr><td colspan="4"><p class="ui-meta">Loading…</p></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= ui_dialog_start('ann-dialog', ['title' => 'New notice']) ?>
    <form class="ui-form" id="ann-form" data-action="ann.save" novalidate>
        <input type="hidden" name="id" value="">
        <?= ui_field('select', ['name' => 'target_event_id', 'label' => 'Event', 'required' => true, 'options' => ['' => 'Choose…'],
            'info' => 'Events from the last week and those coming up.']) ?>
        <?= ui_field('text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'attrs' => ['maxlength' => 200]]) ?>
        <?= ui_field('textarea', ['name' => 'content', 'label' => 'Message', 'required' => true, 'attrs' => ['rows' => 6]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save notice', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ann-form']])
) ?>

<?= ui_json_script('announcements-boot', [
    'api' => '/api/announcements_api.php',
    'userId' => (int) ($_SESSION['user_id'] ?? 0),
    'canCreate' => $canCreate,
    'canApprove' => $canApprove,
    'canDelete' => $canDelete,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
