<?php
// /modules/academy/index.php
// HOD Academy: the staff side of Trainings (TRAININGS-PLAN.md). Every
// training with its counts, work waiting to be marked, graduates waiting to
// be confirmed, and the length wizard that starts a new training (blank, or
// the Membership / Maturity Class template). A training opens in the builder
// (modules/academy/training.php). Learners use Trainings (modules/trainings/).
// The old Academy (programmes, cohorts, applications) retired on 12 Nov 2026:
// its tables were renamed *_retired_20261112 (migration 20261208090100).
// Access: the 'trainings' row of the permission matrix, at church-wide reach
// (the Academy department's seats and the pastors on day one). Data and
// actions: /api/trainings_api.php. Script: assets/ui/js/pages/academy.js.
require_once __DIR__ . '/../../includes/trainings/bootstrap.php';
require_once __DIR__ . '/../../includes/trainings/view.php';
$ui = [
    'title' => 'HOD Academy',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/academy.js'],
];
require_once '../../includes/header.php';
require_can('trainings', 'view', 'church');

$ready = trainings_ready($pdo);
$canCreate = can('trainings', 'create', 'church');
$canMark = can('trainings', 'edit', 'church');
$canApprove = can('trainings', 'approve', 'church');
$list = [];
$toMark = [];
$toConfirm = [];
if ($ready) {
    trainings_sync_newcomers($pdo);
    $list = trainings_overview($pdo);
    $toMark = $canMark ? trainings_to_mark($pdo) : [];
    $toConfirm = $canApprove ? trainings_to_confirm($pdo) : [];
}
$statusTone = ['published' => 'success', 'draft' => 'neutral', 'archived' => 'warning'];
$tabs = ['trainings' => ['label' => 'Trainings', 'count' => count($list)]];
if ($canMark) {
    $tabs['mark'] = ['label' => 'To mark', 'count' => count($toMark)];
}
if ($canApprove) {
    $tabs['confirm'] = ['label' => 'To confirm', 'count' => count($toConfirm)];
}
// Who signs certificates (includes/trainings/certificates.php): Manage only.
$canManage = $ready && can('trainings', 'manage', 'church') && trainings_certs_ready($pdo);
$settings = $canManage ? trainings_settings($pdo) : [];
if ($canManage) {
    $tabs['settings'] = 'Settings';
}
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'HOD Academy',
        'description' => 'Build trainings, assign them, confirm graduates.',
        'info' => 'A training is a list of items (live classes, videos, audio, readings, assignments) in days, weeks or self-paced modules. '
            . 'Assign it to groups or named people with a due date; visitors without a sign-in get an invite link. '
            . 'When someone finishes Membership Class or Maturity Class, confirm them here: Membership makes them a Member, Maturity marks them ready to serve.',
        'actions' => ($canCreate && $ready ? ui_button('New training', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'acd-new']]) : '')
            . ui_button('My trainings', ['variant' => 'secondary', 'icon' => 'graduation-cap', 'href' => '/modules/trainings/']),
    ]) ?>
<?php if (!$ready): ?>
    <?= ui_banner('info', ['title' => 'Almost ready', 'text' => 'The Academy opens after the next update.']) ?>
<?php else: ?>
<?php if ($toConfirm !== []): ?>
    <?= ui_banner('info', ['title' => count($toConfirm) === 1 ? '1 graduate to confirm' : count($toConfirm) . ' graduates to confirm',
        'text' => 'They finished an official class.', 'actions' => ui_button('Review', ['variant' => 'primary', 'size' => 'sm', 'href' => '#tab=confirm'])]) ?>
<?php endif; ?>
    <?= ui_tabs($tabs, 'trainings') ?>

    <section data-tab-panel="trainings" id="panel-trainings" role="tabpanel" aria-labelledby="tab-trainings" class="ui-tab-panel ui-stack ui-stack--md">
<?php if ($list === []): ?>
        <?= ui_empty(['icon' => 'graduation-cap', 'title' => 'No trainings yet', 'text' => 'Start from a template or a blank one.',
            'actions' => $canCreate ? ui_button('New training', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'acd-new']]) : '']) ?>
<?php else: ?>
        <div class="ui-card">
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Training</th><th scope="col">Status</th><th scope="col">When</th>
                        <th scope="col" class="is-num">Learners</th><th scope="col" class="is-num">Completed</th><th scope="col" class="is-num">To mark</th></tr></thead>
                    <tbody>
<?php foreach ($list as $t): ?>
                        <tr>
                            <td data-label="Training">
                                <a class="ui-table__primary" href="/modules/academy/training?id=<?= (int) $t['id'] ?>"><?= ui_e($t['title']) ?></a>
                                <span class="ui-table__secondary"><?= ui_e(trim(($t['official_label'] !== '' ? $t['official_label'] . ' · ' : '') . $t['length_label']
                                    . ' · ' . $t['items'] . ($t['items'] === 1 ? ' item' : ' items'))) ?></span>
                            </td>
                            <td data-label="Status"><?= ui_badge($t['status_label'], $statusTone[$t['status']] ?? 'neutral', ['dot' => true]) ?><?= $t['catalogue'] && $t['status'] === 'published' ? ' ' . ui_badge('Catalogue', 'info') : '' ?></td>
                            <td data-label="When"><?= ui_e(trainings_view_range($t['starts_on'], $t['ends_on']) ?: 'Self-paced') ?></td>
                            <td data-label="Learners" class="is-num"><?= (int) $t['learners'] ?></td>
                            <td data-label="Completed" class="is-num"><?= (int) $t['completed'] ?></td>
                            <td data-label="To mark" class="is-num"><?= (int) $t['to_mark'] ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php endif; ?>
    </section>

<?php if ($canMark): ?>
    <section data-tab-panel="mark" id="panel-mark" role="tabpanel" aria-labelledby="tab-mark" class="ui-tab-panel ui-stack ui-stack--md" hidden>
<?php if ($toMark === []): ?>
        <?= ui_empty(['icon' => 'circle-check', 'title' => 'Nothing to mark', 'text' => 'Handed-in work shows here.']) ?>
<?php else: ?>
        <div class="ui-card">
            <ul class="ui-list">
<?php foreach ($toMark as $n => $w): ?>
                <li class="ui-list__item">
                    <span class="ui-list__icon"><?= ui_icon('pencil') ?></span>
                    <span class="ui-list__main">
                        <span class="ui-list__title"><?= ui_e($w['name']) ?></span>
                        <span class="ui-list__meta"><?= ui_e($w['item'] . ' · ' . $w['training'] . ' · ' . trainings_view_date($w['submitted_at'])) ?></span>
                    </span>
                    <span class="ui-list__end"><?= ui_button('Mark', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'acd.mark', 'data-work' => $n]]) ?></span>
                </li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canApprove): ?>
    <section data-tab-panel="confirm" id="panel-confirm" role="tabpanel" aria-labelledby="tab-confirm" class="ui-tab-panel ui-stack ui-stack--md" hidden>
<?php if ($toConfirm === []): ?>
        <?= ui_empty(['icon' => 'user-check', 'title' => 'Nobody to confirm', 'text' => 'Graduates of official classes show here.']) ?>
<?php else: ?>
        <form class="ui-card" data-action="acd.confirm">
            <div class="ui-card__header ui-card__header--divided">
                <label class="ui-check"><input type="checkbox" data-action-change="acd.all" checked><span>Select all</span></label>
                <?= ui_button('Confirm', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check']) ?>
            </div>
            <ul class="ui-list">
<?php foreach ($toConfirm as $c): ?>
                <li class="ui-list__item">
                    <label class="ui-check flex-1">
                        <input type="checkbox" name="enrolment_ids[]" value="<?= (int) $c['id'] ?>" checked>
                        <span><span class="block font-semibold text-ink"><?= ui_e($c['name']) ?></span>
                            <span class="ui-check__hint"><?= ui_e($c['training'] . ' · now ' . $c['person_status'] . ' · finished ' . trainings_view_date($c['completed_at'])) ?></span></span>
                    </label>
                    <span class="ui-list__end"><?= ui_badge($c['outcome'], $c['outcome'] === 'Becomes a Member' || $c['outcome'] === 'Ready to serve' ? 'success' : 'neutral') ?></span>
                </li>
<?php endforeach; ?>
            </ul>
        </form>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canManage): ?>
    <section data-tab-panel="settings" id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <form class="ui-card" id="acd-settings-form" data-action="acd.settings" data-ui-validate novalidate>
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title">Certificates</h2>
                <?= ui_info('Every certificate carries this signature, name and title, a code and a QR code that anyone can check at hodlekki.org/verify.', 'About certificates') ?>
            </div>
            <div class="ui-card__body ui-stack ui-stack--md">
                <div class="ui-form-grid">
                    <?= ui_field('text', ['name' => 'signatory_name', 'label' => 'Signed by', 'optional' => true, 'value' => $settings['signatory_name'],
                        'attrs' => ['maxlength' => 120]]) ?>
                    <?= ui_field('text', ['name' => 'signatory_title', 'label' => 'Their title', 'required' => true, 'value' => $settings['signatory_title'],
                        'attrs' => ['maxlength' => 120]]) ?>
                </div>
<?php if ($settings['signature_image'] !== '' && trainings_signature_path($settings['signature_image']) !== null): ?>
                <div class="ui-stack ui-stack--sm">
                    <img class="h-16 w-auto max-w-xs rounded-ui border border-line bg-surface p-2" src="<?= ui_e($settings['signature_image']) ?>" alt="The signature on certificates">
                    <label class="ui-check"><input type="checkbox" name="remove_signature" value="1"><span>Remove the signature</span></label>
                </div>
<?php endif; ?>
                <?= ui_field('file', ['name' => 'signature_file', 'label' => 'Signature image', 'optional' => true, 'hint' => 'PNG or JPEG, up to 2 MB.',
                    'attrs' => ['accept' => '.png,.jpg,.jpeg,image/png,image/jpeg']]) ?>
            </div>
            <div class="ui-card__footer flex justify-end">
                <?= ui_button('Save settings', ['variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if ($ready && $canCreate): ?>
<?= ui_dialog_start('acd-new', ['title' => 'New training', 'description' => 'Pick how long it runs; you fill it in next.']) ?>
<form id="acd-new-form" class="ui-form" data-action="acd.create" data-ui-validate novalidate>
    <fieldset class="ui-fieldset">
        <legend class="ui-legend">Start from</legend>
        <label class="ui-check"><input type="radio" name="template" value="" checked data-action-change="acd.wizard"><span>Blank</span></label>
<?php foreach (TRAININGS_OFFICIAL as $key => $label): ?>
        <label class="ui-check"><input type="radio" name="template" value="<?= ui_e($key) ?>" data-action-change="acd.wizard"><span><?= ui_e($label) ?>
            <span class="ui-check__hint"><?= $key === 'membership' ? '4 weeks, ready to fill in' : '6 weeks, with sermon reviews' ?></span></span></label>
<?php endforeach; ?>
    </fieldset>
    <?= ui_field('text', ['name' => 'title', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 160]]) ?>
    <fieldset class="ui-fieldset" data-acd-length>
        <legend class="ui-legend">How long</legend>
<?php foreach (TRAININGS_LENGTHS as $key => $label): ?>
        <label class="ui-check"><input type="radio" name="length" value="<?= ui_e($key) ?>"<?= $key === 'weeks' ? ' checked' : '' ?> data-action-change="acd.wizard"><span><?= ui_e($label) ?></span></label>
<?php endforeach; ?>
    </fieldset>
    <div class="ui-form-grid">
        <?= ui_field('date', ['name' => 'starts_on', 'label' => 'First day', 'required' => true, 'class' => 'acd-dated', 'value' => trainings_today()]) ?>
        <?= ui_field('number', ['name' => 'count', 'label' => 'How many weeks', 'required' => true, 'value' => '4', 'class' => 'acd-count',
            'attrs' => ['min' => 1, 'max' => TRAININGS_MAX_WEEKS, 'inputmode' => 'numeric']]) ?>
    </div>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Create training', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'acd-new-form']])) ?>
<?php endif; ?>

<?php if ($ready && $canMark): ?>
<?= ui_dialog_start('acd-mark', ['title' => 'Mark work', 'size' => 'lg']) ?>
<form id="acd-mark-form" class="ui-form" data-action="acd.saveMark" data-ui-validate novalidate>
    <input type="hidden" name="enrolment_id">
    <input type="hidden" name="item_id">
    <p class="ui-meta" data-acd-mark-who></p>
    <div class="ui-stack ui-stack--sm" data-acd-mark-answers></div>
    <div class="ui-form-grid">
        <?= ui_field('number', ['name' => 'mark', 'id' => 'acd-mark-value', 'label' => 'Mark', 'required' => true, 'attrs' => ['min' => 0, 'inputmode' => 'numeric']]) ?>
    </div>
    <?= ui_field('textarea', ['name' => 'comment', 'label' => 'Comment', 'optional' => true, 'attrs' => ['rows' => 3, 'maxlength' => 2000]]) ?>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save mark', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'acd-mark-form']])) ?>
<?= ui_json_script('acd-data', ['work' => $toMark]) ?>
<?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>
