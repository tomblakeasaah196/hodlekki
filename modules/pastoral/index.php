<?php
// /modules/pastoral/index.php
/**
 * Pastoral Desk: Q&A questions, suggestions and periodic impressions for the
 * pastors.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/pastoral.js, data from api/pastoral_api.php.
 *
 * Access: Roles & permissions, module "pastoral". Everyone may submit and
 * read their own entries; View church-wide reads every entry; Edit
 * church-wide sets the status; Manage sets the priority. The API checks each
 * again, and never sends the name of an anonymous entry to anyone else.
 */
$ui = [
    'title'   => 'Pastoral Desk',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/pastoral.js'],
    'offline' => false,   // private entries: never an old copy
];
require_once '../../includes/header.php';
require_can('pastoral');

$isLeadership = can('pastoral', 'view', 'church');
$canTriage = can('pastoral', 'edit', 'church');
$canPriority = can('pastoral', 'manage');
$canCreate = can('pastoral', 'create');
$tabs = [
    'qa' => ['label' => 'Q&A', 'icon' => 'circle-help'],
    'suggestions' => ['label' => 'Suggestions', 'icon' => 'messages-square'],
    'impressions' => ['label' => 'Impressions', 'icon' => 'sparkles'],
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Pastoral Desk',
        'description' => $isLeadership ? 'Questions, suggestions and impressions from members.' : 'Questions, suggestions and impressions for the pastors.',
        'info' => 'Ask the pastors a question, drop a suggestion, or share a quarterly or yearly impression. '
            . 'Questions and suggestions can be sent anonymously: your name is then never shown to anyone. '
            . ($isLeadership ? 'You see every entry; members see only their own.' : 'You see only your own entries and their status.'),
        'actions' => $canCreate ? ui_button('New entry', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'pas.new']]) : '',
    ]) ?>

    <div class="mb-4" data-pas-error hidden><?= ui_banner('danger', ['text' => 'This section did not load: reload the page to try again.']) ?></div>

    <?= ui_tabs($tabs, 'qa') ?>

<?php foreach ($tabs as $key => $tab): ?>
    <section data-tab-panel="<?= $key ?>" id="panel-<?= $key ?>" role="tabpanel" aria-labelledby="tab-<?= $key ?>" class="ui-tab-panel ui-stack"<?= $key === 'qa' ? '' : ' hidden' ?>>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">From</th><th scope="col">Entry</th><th scope="col">Status</th>
<?php if ($isLeadership): ?>
                        <th scope="col">Priority</th>
<?php endif; ?>
                        <th scope="col" class="is-num">Date</th></tr></thead>
                    <tbody data-pas-body="<?= $key ?>"><tr><td colspan="5"><p class="ui-meta">Loading…</p></td></tr></tbody>
                </table>
            </div>
        </div>
    </section>
<?php endforeach; ?>
</div>

<?= ui_dialog_start('pas-dialog', ['title' => 'New entry']) ?>
    <form class="ui-form" id="pas-form" data-action="pas.save" novalidate>
        <?= ui_field('select', ['name' => 'type', 'label' => 'What is it', 'options' => [
            'qa' => 'A question for the pastors', 'suggestion' => 'A suggestion', 'impression' => 'An impression',
        ], 'attrs' => ['data-action-change' => 'pas.type']]) ?>
        <div class="ui-form-grid" data-pas-impression hidden>
            <?= ui_field('select', ['name' => 'period_type', 'label' => 'Period', 'options' => ['Quarterly' => 'Quarterly', 'Yearly' => 'Yearly']]) ?>
            <?= ui_field('text', ['name' => 'period_label', 'label' => 'Which one', 'optional' => true, 'placeholder' => 'Q1 2026', 'attrs' => ['maxlength' => 50]]) ?>
        </div>
        <?= ui_field('textarea', ['name' => 'content', 'label' => 'Your message', 'required' => true, 'attrs' => ['rows' => 5, 'maxlength' => 5000]]) ?>
        <div data-pas-anon>
            <label class="ui-check"><input type="checkbox" name="is_anonymous" value="1"><span>Send anonymously</span></label>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send', 'attrs' => ['form' => 'pas-form']])
) ?>

<?= ui_json_script('pastoral-boot', [
    'api' => '/api/pastoral_api.php',
    'isLeadership' => $isLeadership,
    'canTriage' => $canTriage,
    'canPriority' => $canPriority,
    'statuses' => [
        'qa' => ['Pending', 'Answered', 'Archived'],
        'suggestions' => ['New', 'Reviewed', 'Actioned'],
        'impressions' => ['New', 'Reviewed', 'Archived'],
    ],
]) ?>
<?php require_once '../../includes/footer.php'; ?>
