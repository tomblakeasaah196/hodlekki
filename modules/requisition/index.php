<?php
// /modules/requisition/index.php
/**
 * Requisitions: a department's expense request travels Submit → Director
 * stamp → Resident Pastor approval with a signature → Finance batch →
 * disbursed into the ledger. Tabs: Requests (mine and the Director queue),
 * Approval (pastors), Finance (batches) and Analytics.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/requisition.js, data from api/requisition_api.php (which
 * re-checks every permission and the state machine).
 *
 * Access: Roles & permissions, module "requisition" (create = submit for your
 * departments, approve = Director stamp for your departments / final approval
 * church-wide) and "requisition.disburse" (Finance).
 */
$ui = [
    'title'   => 'Requisitions',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/requisition.js'],
    'offline' => false,   // money and approvals: never an old copy
];
require_once '../../includes/header.php';
require_can('requisition');

$isPastor = can('requisition', 'approve', 'church');                      // final approval
$isDirector = perm_scope_beyond_own(perm_scope('requisition', 'approve'));  // stage-one validation
$canSubmit = perm_scope_beyond_own(perm_scope('requisition', 'create'));
$thisYear = (int) date('Y');
$months = [];
foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $m) {
    $months[$i + 1] = $m;
}
$years = [];
for ($y = $thisYear + 1; $y >= $thisYear - 2; $y--) {
    $years[$y] = (string) $y;
}
$tabs = ['requests' => ['label' => 'Requests', 'icon' => 'clipboard-list']];
if ($isPastor) {
    $tabs['approval'] = ['label' => 'Approval', 'icon' => 'circle-check'];
}
$tabs['finance'] = ['label' => 'Finance', 'icon' => 'wallet'];
$tabs['analytics'] = ['label' => 'Analytics', 'icon' => 'chart-column'];
$filters = [
    'all' => 'All', 'Pending_Director' => 'With Director', 'Pending_Pastor' => 'With Pastor',
    'Revision_Required' => 'Needs revision', 'Approved' => 'Approved', 'Disbursed' => 'Disbursed', 'Rejected' => 'Rejected',
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Requisitions',
        'description' => 'Department expenses, from request to payment.',
        'info' => 'A request goes to the department\'s Director for a digital stamp, then to the Resident Pastor to approve and sign, '
            . 'then to Finance to batch and pay. Every step is stamped and kept.',
        'actions' => ($isPastor ? ui_button('My signature', ['icon' => 'pencil', 'attrs' => ['data-action' => 'req.vault']]) : '')
            . ($canSubmit ? ui_button('New requisition', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'req.new']]) : ''),
    ]) ?>

    <div data-req-error hidden>
        <?= ui_banner('danger', ['text' => 'Requisitions did not load: reload the page to try again.']) ?>
    </div>

    <?= ui_tabs($tabs, 'requests') ?>

    <section data-tab-panel="requests" id="panel-requests" role="tabpanel" aria-labelledby="tab-requests" class="ui-tab-panel ui-stack">
<?php if ($isDirector): ?>
        <section class="ui-card" data-req-dirq-card hidden>
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h3 class="ui-h3">Waiting for your stamp</h3><span class="ui-count" data-req-dirq-count>0</span>
                    <?= ui_info('Requests from your departments. Review each one, then stamp it on to the Resident Pastor or send it back.', 'About the Director queue') ?></div>
            </div>
            <div class="ui-list" data-req-dirq></div>
        </section>
<?php endif; ?>
        <div class="ui-chips" role="group" aria-label="Show">
<?php foreach ($filters as $value => $label): ?>
            <button type="button" class="ui-chip" data-action="req.filter" data-value="<?= ui_e($value) ?>" aria-pressed="<?= $value === 'all' ? 'true' : 'false' ?>"><?= ui_e($label) ?></button>
<?php endforeach; ?>
        </div>
        <div class="ui-card ui-card__body--flush"><div class="ui-list" data-req-list></div></div>
        <div class="ui-toolbar" data-req-more hidden><?= ui_button('Show more', ['variant' => 'ghost', 'attrs' => ['data-action' => 'req.more']]) ?></div>
    </section>

<?php if ($isPastor): ?>
    <section data-tab-panel="approval" id="panel-approval" role="tabpanel" aria-labelledby="tab-approval" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-chips" role="group" aria-label="Show">
            <button type="button" class="ui-chip" data-action="req.approval.filter" data-value="all" aria-pressed="true">All waiting</button>
            <button type="button" class="ui-chip" data-action="req.approval.filter" data-value="pending" aria-pressed="false">New</button>
            <button type="button" class="ui-chip" data-action="req.approval.filter" data-value="revision" aria-pressed="false">Revised</button>
        </div>
        <div class="ui-card ui-card__body--flush"><div class="ui-list" data-req-approval></div></div>
    </section>
<?php endif; ?>

    <section data-tab-panel="finance" id="panel-finance" role="tabpanel" aria-labelledby="tab-finance" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-grid ui-grid--2">
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h3 class="ui-h3">Ready to batch</h3><span class="ui-count" data-req-avail-count>0</span></div>
                </div>
                <div class="ui-list" data-req-avail></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided ui-toolbar">
                    <div class="ui-title-row ui-toolbar__grow"><h3 class="ui-h3">Current batch</h3>
                        <?= ui_info('Group approved requests into a batch, seal it with your Finance stamp, then mark it paid. Paying posts each request to the ledger.', 'About batches') ?></div>
                    <?= ui_button('Create batch', ['size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'req.batch.new', 'data-req-batch-new' => '1']]) ?>
                </div>
                <div data-req-draft></div>
            </section>
        </div>
        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Batch history</h3></div>
            <div class="ui-list" data-req-batches></div>
        </section>
    </section>

    <section data-tab-panel="analytics" id="panel-analytics" role="tabpanel" aria-labelledby="tab-analytics" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-toolbar">
            <div class="ui-segmented" role="group" aria-label="Period">
<?php foreach (['month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year', 'custom' => 'Custom'] as $p => $label): ?>
                <button type="button" class="ui-segmented__item" data-action="req.period" data-value="<?= $p ?>" aria-pressed="<?= $p === 'quarter' ? 'true' : 'false' ?>"><?= $label ?></button>
<?php endforeach; ?>
            </div>
            <div class="ui-toolbar" data-req-custom hidden>
                <label class="sr-only" for="req-from">From</label><input class="ui-input" type="date" id="req-from">
                <label class="sr-only" for="req-to">To</label><input class="ui-input" type="date" id="req-to">
                <?= ui_button('Apply', ['size' => 'sm', 'attrs' => ['data-action' => 'req.analytics']]) ?>
            </div>
            <label class="sr-only" for="req-dept-filter">Department</label>
            <select class="ui-select" id="req-dept-filter" data-action-change="req.analytics"><option value="">All departments</option></select>
            <span class="ui-meta" data-req-period></span>
        </div>
        <div class="ui-grid ui-grid--stats">
            <div data-stat="total"><?= ui_stat(['label' => 'Requests', 'value' => '—', 'icon' => 'clipboard-list']) ?></div>
            <div data-stat="amount"><?= ui_stat(['label' => 'Amount asked', 'value' => '—', 'icon' => 'wallet']) ?></div>
            <div data-stat="cycle"><?= ui_stat(['label' => 'Days to approve', 'value' => '—', 'icon' => 'clock']) ?></div>
            <div data-stat="paid"><?= ui_stat(['label' => 'Paid out', 'value' => '—', 'icon' => 'circle-check']) ?></div>
        </div>
        <div class="ui-grid ui-grid--2">
            <section class="ui-card">
                <div class="ui-card__header"><h3 class="ui-h3">By status</h3></div>
                <div class="ui-card__body"><div class="relative h-64"><canvas data-req-chart="status" role="img" aria-label="Requests by status"></canvas></div></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header"><h3 class="ui-h3">Last 12 months</h3></div>
                <div class="ui-card__body"><div class="relative h-64"><canvas data-req-chart="trend" role="img" aria-label="Amount asked and approved by month"></canvas></div></div>
            </section>
        </div>
        <section class="ui-card" data-req-dept-card hidden>
            <div class="ui-card__header"><h3 class="ui-h3">By department</h3></div>
            <div class="ui-card__body"><div class="relative h-80"><canvas data-req-chart="dept" role="img" aria-label="Amount asked and approved by department"></canvas></div></div>
        </section>
        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Top 10 expense lines</h3></div>
            <div class="ui-list" data-req-top></div>
        </section>
        <section class="ui-card" data-req-bh-card hidden>
            <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Batches paid</h3></div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Batch</th><th scope="col">Status</th><th scope="col" class="is-num">Requests</th><th scope="col" class="is-num">Total</th><th scope="col">Paid</th></tr></thead>
                    <tbody data-req-bh></tbody>
                </table>
            </div>
        </section>
    </section>
</div>

<?= ui_dialog_start('req-new-dialog', ['title' => 'New requisition', 'size' => 'xl']) ?>
    <form class="ui-form" id="req-new-form" data-action="req.submit" novalidate>
        <div data-req-window hidden><?= ui_banner('warning', ['text' => 'Outside the usual submission window.']) ?></div>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'type', 'label' => 'Type', 'options' => ['Monthly' => 'Monthly', 'Occasional' => 'One-off or event'],
                'attrs' => ['data-action-change' => 'req.type']]) ?>
            <?= ui_field('select', ['name' => 'department_id', 'label' => 'Department', 'required' => true, 'options' => ['' => 'Choose…']]) ?>
        </div>
        <div class="ui-form-grid" data-req-monthly>
            <?= ui_field('select', ['name' => 'period_month', 'label' => 'Month', 'options' => $months, 'value' => (int) date('n')]) ?>
            <?= ui_field('select', ['name' => 'period_year', 'label' => 'Year', 'options' => $years, 'value' => $thisYear]) ?>
        </div>
        <div data-req-occasional hidden>
            <?= ui_field('text', ['name' => 'event_label', 'label' => 'Event or purpose', 'placeholder' => 'Easter Convention', 'attrs' => ['maxlength' => 150]]) ?>
        </div>
        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Expense lines</legend>
            <div class="ui-stack ui-stack--sm" data-req-lines></div>
            <div class="ui-toolbar">
                <?= ui_button('Add line', ['variant' => 'ghost', 'icon' => 'plus', 'attrs' => ['data-action' => 'req.line.add']]) ?>
                <p class="ui-toolbar__grow ui-h3 is-num" data-req-total>₦0.00</p>
            </div>
        </fieldset>
        <?= ui_field('textarea', ['name' => 'submission_note', 'label' => 'Note', 'optional' => true, 'attrs' => ['rows' => 2, 'maxlength' => 1000],
            'info' => 'Anything the Director or the Pastor should know.']) ?>
        <?= ui_field('file', ['name' => 'attachments[]', 'id' => 'req-files', 'label' => 'Quotes or receipts', 'optional' => true,
            'hint' => 'PDF, JPG or PNG, up to 5 MB each.', 'attrs' => ['multiple' => true, 'accept' => '.pdf,.jpg,.jpeg,.png', 'data-action-change' => 'req.files']]) ?>
        <div class="ui-list" data-req-files></div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Submit request', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-new-form']])
) ?>

<?= ui_dialog_start('req-detail-dialog', ['title' => 'Requisition', 'size' => 'drawer']) ?>
    <div class="ui-stack" data-req-detail>
        <div class="ui-loading"><span class="ui-spinner"></span><span>Loading…</span></div>
    </div>
<?= ui_dialog_end('<div class="ui-toolbar" data-req-detail-actions></div>') ?>

<?= ui_dialog_start('req-reason-dialog', ['title' => 'Reject requisition', 'size' => 'sm']) ?>
    <form class="ui-form" id="req-reason-form" data-action="req.reason.save" novalidate>
        <input type="hidden" name="mode" value="">
        <input type="hidden" name="req_id" value="">
        <p class="ui-meta" data-req-reason-note></p>
        <?= ui_field('textarea', ['name' => 'reason', 'label' => 'Reason', 'required' => true, 'attrs' => ['rows' => 4, 'maxlength' => 1000]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Reject', ['variant' => 'danger', 'type' => 'submit', 'attrs' => ['form' => 'req-reason-form', 'data-req-reason-submit' => '1']])
) ?>

<?= ui_dialog_start('req-lines-dialog', ['title' => 'Flag lines', 'size' => 'lg']) ?>
    <form class="ui-form" id="req-lines-form" data-action="req.lines.save" novalidate>
        <input type="hidden" name="req_id" value="">
        <p class="ui-meta">Only the flagged lines go back; the Director stamp stays.</p>
        <div class="ui-stack ui-stack--sm" data-req-lines-list></div>
        <?= ui_field('textarea', ['name' => 'notes', 'label' => 'Note', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send back', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-lines-form']])
) ?>

<?= ui_dialog_start('req-revise-dialog', ['title' => 'Revise lines', 'size' => 'lg']) ?>
    <form class="ui-form" id="req-revise-form" data-action="req.revise.save" novalidate>
        <input type="hidden" name="req_id" value="">
        <p class="ui-meta">Change only the lines the Pastor flagged.</p>
        <div class="ui-stack" data-req-revise-list></div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Resubmit', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-revise-form']])
) ?>

<?= ui_dialog_start('req-approve-dialog', ['title' => 'Approve and sign']) ?>
    <form class="ui-form" id="req-approve-form" data-action="req.approve.save" novalidate>
        <input type="hidden" name="req_id" value="">
        <div class="ui-card">
            <div class="ui-card__header ui-toolbar">
                <div class="ui-title-row ui-toolbar__grow"><h3 class="ui-h3">Your signature</h3>
                    <?= ui_info('Your signature is binding on this document. Draw it, or use the one stored in your vault (it asks for your PIN).', 'About signing') ?></div>
                <?= ui_button('Draw', ['size' => 'sm', 'icon' => 'pencil', 'attrs' => ['data-action' => 'req.sign', 'data-target' => 'approve']]) ?>
                <?= ui_button('Use stored', ['size' => 'sm', 'attrs' => ['data-action' => 'req.pin', 'data-req-use-stored' => '1', 'hidden' => true]]) ?>
            </div>
            <div class="ui-card__body">
                <img class="block max-h-32 mx-auto" data-req-approve-sig alt="Your signature" hidden>
                <p class="ui-meta" data-req-approve-nosig>Draw your signature or use the stored one.</p>
            </div>
        </div>
        <?= ui_field('textarea', ['name' => 'notes', 'label' => 'Notes', 'optional' => true, 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Approve', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'circle-check', 'attrs' => ['form' => 'req-approve-form']])
) ?>

<?= ui_dialog_start('req-sign-dialog', ['title' => 'Sign here', 'size' => 'xl']) ?>
    <p class="ui-meta">Use your finger, a stylus or the mouse.</p>
    <canvas class="block w-full h-72 touch-none select-none rounded-lg border border-line bg-surface" data-req-canvas aria-label="Signature area"></canvas>
<?= ui_dialog_end(
    ui_button('Clear', ['variant' => 'ghost', 'attrs' => ['data-action' => 'req.sign.clear']])
    . ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Use signature', ['variant' => 'primary', 'attrs' => ['data-action' => 'req.sign.done']])
) ?>

<?= ui_dialog_start('req-vault-dialog', ['title' => 'My signature']) ?>
    <div class="ui-stack">
        <p class="ui-meta" data-req-vault-status>Checking…</p>
        <section class="ui-card">
            <div class="ui-card__header ui-toolbar">
                <div class="ui-title-row ui-toolbar__grow"><h3 class="ui-h3">Store a signature</h3>
                    <?= ui_info('Stored encrypted. Each time you use it on an approval you type your 4-digit PIN.', 'About the signature vault') ?></div>
                <?= ui_button('Draw', ['size' => 'sm', 'icon' => 'pencil', 'attrs' => ['data-action' => 'req.sign', 'data-target' => 'vault']]) ?>
            </div>
            <div class="ui-card__body ui-stack ui-stack--sm">
                <img class="block max-h-32 mx-auto" data-req-vault-sig alt="Signature to store" hidden>
                <?= ui_field('password', ['name' => 'vault_pin', 'id' => 'req-vault-pin', 'label' => '4-digit PIN', 'autocomplete' => 'new-password',
                    'inputmode' => 'numeric', 'attrs' => ['maxlength' => 4, 'pattern' => '\d{4}']]) ?>
                <div><?= ui_button('Store signature', ['variant' => 'primary', 'attrs' => ['data-action' => 'req.vault.save']]) ?></div>
            </div>
        </section>
        <section class="ui-card" data-req-vault-delete hidden>
            <div class="ui-card__header"><h3 class="ui-h3">Delete the stored one</h3></div>
            <div class="ui-card__body ui-toolbar">
                <div class="ui-toolbar__grow">
                    <?= ui_field('password', ['name' => 'vault_delete_pin', 'id' => 'req-vault-delete-pin', 'label' => 'PIN', 'autocomplete' => 'off',
                        'inputmode' => 'numeric', 'attrs' => ['maxlength' => 4]]) ?>
                </div>
                <?= ui_button('Delete', ['variant' => 'danger', 'attrs' => ['data-action' => 'req.vault.delete']]) ?>
            </div>
        </section>
    </div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('req-pin-dialog', ['title' => 'Enter your PIN', 'size' => 'sm']) ?>
    <form class="ui-form" id="req-pin-form" data-action="req.pin.save" novalidate>
        <?= ui_field('password', ['name' => 'pin', 'id' => 'req-pin', 'label' => '4-digit PIN', 'autocomplete' => 'off', 'inputmode' => 'numeric',
            'attrs' => ['maxlength' => 4]]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Use signature', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-pin-form']])
) ?>

<?= ui_dialog_start('req-batch-dialog', ['title' => 'Create batch', 'size' => 'sm']) ?>
    <form class="ui-form" id="req-batch-form" data-action="req.batch.save" novalidate>
        <?= ui_field('text', ['name' => 'batch_label', 'label' => 'Label', 'optional' => true, 'placeholder' => 'May week 3',
            'info' => 'A reference is made from the current week. Add requests to the batch after creating it.']) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Create batch', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-batch-form']])
) ?>

<?= ui_dialog_start('req-pay-dialog', ['title' => 'Mark batch paid', 'size' => 'sm']) ?>
    <form class="ui-form" id="req-pay-form" data-action="req.pay.save" novalidate>
        <input type="hidden" name="batch_id" value="">
        <?= ui_banner('warning', ['text' => 'Only after the money has actually been paid out.',
            'info' => 'Every request in the batch is posted to the Finance ledger as an expense.']) ?>
        <?= ui_field('select', ['name' => 'account_id', 'label' => 'Debit account', 'required' => true, 'options' => ['' => 'Loading…']]) ?>
        <?= ui_field('select', ['name' => 'fund_id', 'label' => 'Fund', 'required' => true, 'options' => ['' => 'Loading…']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Mark paid', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'req-pay-form']])
) ?>

<?= ui_json_script('requisition-boot', [
    'api' => '/api/requisition_api.php',
    'pdf' => '/includes/requisition_pdf.php',
    'me' => (int) ($_SESSION['user_id'] ?? 0),
    'isPastor' => $isPastor,
    'isDirector' => $isDirector,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
