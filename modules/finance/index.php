<?php
// /modules/finance/index.php
/**
 * Finance & stewardship: this month's money in and out, account balances, the
 * ledger, and the setup (bank and cash accounts, funds, categories).
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/finance.js, data from api/finance_api.php.
 *
 * Access: Roles & permissions, module "finance": View opens the page, Create
 * records a transaction, Edit changes the setup, Delete removes a transaction
 * or a setup row. The API checks each level again.
 */
$ui = [
    'title'   => 'Finance & Stewardship',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/finance.js'],
    'offline' => false,   // money: never an old copy
];
require_once '../../includes/header.php';
require_can('finance');

$canCreate = can('finance', 'create');
$canEdit = can('finance', 'edit');
$canDelete = can('finance', 'delete');
$tabs = [
    'overview' => ['label' => 'Overview', 'icon' => 'layout-dashboard'],
    'ledger' => ['label' => 'Ledger', 'icon' => 'clipboard-list'],
];
if ($canEdit) {
    $tabs['setup'] = ['label' => 'Setup', 'icon' => 'settings'];
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Finance & stewardship',
        'description' => 'Accounts, funds and every transaction.',
        'info' => 'Every transaction moves money in or out of an account (bank or cash) and is tagged with a fund and a category. '
            . 'Paid requisition batches are posted here automatically.',
        'actions' => $canCreate ? ui_button('Log transaction', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'fin.tx.new']]) : '',
    ]) ?>

    <div class="mb-4" data-fin-error hidden><?= ui_banner('danger', ['text' => 'Finance did not load: reload the page to try again.']) ?></div>

    <?= ui_tabs($tabs, 'overview') ?>

    <section data-tab-panel="overview" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" class="ui-tab-panel ui-stack">
        <div class="ui-grid ui-grid--3">
            <div data-stat="income"><?= ui_stat(['label' => 'In this month', 'value' => '—', 'icon' => 'trending-up']) ?></div>
            <div data-stat="expense"><?= ui_stat(['label' => 'Out this month', 'value' => '—', 'icon' => 'trending-down']) ?></div>
            <div data-stat="net"><?= ui_stat(['label' => 'Net this month', 'value' => '—', 'icon' => 'wallet']) ?></div>
        </div>
        <div class="ui-grid ui-grid--2">
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Balances</h3></div>
                <div class="ui-list" data-fin-balances></div>
            </section>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Latest transactions</h3></div>
                <div class="ui-list" data-fin-latest></div>
            </section>
        </div>
    </section>

    <section data-tab-panel="ledger" id="panel-ledger" role="tabpanel" aria-labelledby="tab-ledger" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">What</th><th scope="col">Account</th>
                        <th scope="col" class="is-num">Amount</th><th scope="col">Receipt</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-fin-ledger></tbody>
                </table>
            </div>
        </div>
        <p class="ui-meta">The latest 100 transactions.</p>
    </section>

<?php if ($canEdit): ?>
    <section data-tab-panel="setup" id="panel-setup" role="tabpanel" aria-labelledby="tab-setup" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-grid ui-grid--3">
<?php foreach ([
    'account' => ['Accounts', 'Bank accounts and petty cash: where money actually sits.'],
    'fund' => ['Funds', 'Virtual wallets inside the accounts, e.g. Building fund, Welfare.'],
    'category' => ['Categories', 'What money is for, separately for income and expenses.'],
] as $kind => [$title, $about]): ?>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided ui-toolbar">
                    <div class="ui-title-row ui-toolbar__grow"><h3 class="ui-h3"><?= ui_e($title) ?></h3><?= ui_info($about, 'About ' . strtolower($title)) ?></div>
                    <?= ui_button('Add', ['size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'fin.setup.new', 'data-kind' => $kind]]) ?>
                </div>
                <div class="ui-list" data-fin-setup="<?= $kind ?>"></div>
            </section>
<?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
</div>

<?= ui_dialog_start('fin-tx-dialog', ['title' => 'Log transaction']) ?>
    <form class="ui-form" id="fin-tx-form" data-action="fin.tx.save" novalidate>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'transaction_type', 'label' => 'Type', 'options' => ['Income' => 'Money in', 'Expense' => 'Money out'],
                'attrs' => ['data-action-change' => 'fin.tx.type']]) ?>
            <?= ui_field('number', ['name' => 'amount', 'label' => 'Amount (₦)', 'required' => true, 'inputmode' => 'decimal', 'attrs' => ['step' => '0.01', 'min' => '0.01']]) ?>
        </div>
        <div class="ui-form-grid ui-form-grid--3">
            <?= ui_field('select', ['name' => 'account_id', 'label' => 'Account', 'required' => true, 'options' => []]) ?>
            <?= ui_field('select', ['name' => 'fund_id', 'label' => 'Fund', 'required' => true, 'options' => []]) ?>
            <?= ui_field('select', ['name' => 'category_id', 'label' => 'Category', 'optional' => true, 'options' => []]) ?>
        </div>
        <?= ui_field('text', ['name' => 'description', 'label' => 'What for', 'optional' => true, 'attrs' => ['maxlength' => 255]]) ?>
        <?= ui_field('file', ['name' => 'receipt_file', 'label' => 'Receipt', 'optional' => true, 'hint' => 'JPG, PNG or PDF.', 'attrs' => ['accept' => '.jpg,.jpeg,.png,.pdf']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save transaction', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'fin-tx-form']])
) ?>

<?= ui_dialog_start('fin-setup-dialog', ['title' => 'Account', 'size' => 'sm']) ?>
    <form class="ui-form" id="fin-setup-form" data-action="fin.setup.save" novalidate>
        <input type="hidden" name="kind" value="">
        <input type="hidden" name="id" value="">
        <?= ui_field('text', ['name' => 'name', 'id' => 'fin-setup-name', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 120]]) ?>
        <div data-fin-for="account">
            <?= ui_field('select', ['name' => 'account_type', 'label' => 'Kind', 'options' => ['Bank' => 'Bank account', 'Cash' => 'Petty cash']]) ?>
        </div>
        <div data-fin-for="fund">
            <?= ui_field('text', ['name' => 'description', 'label' => 'Description', 'optional' => true, 'attrs' => ['maxlength' => 255]]) ?>
        </div>
        <div data-fin-for="category">
            <?= ui_field('select', ['name' => 'type', 'label' => 'For', 'options' => ['Income' => 'Money in', 'Expense' => 'Money out']]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'fin-setup-form']])
) ?>

<?= ui_json_script('finance-boot', [
    'api' => '/api/finance_api.php',
    'canCreate' => $canCreate,
    'canEdit' => $canEdit,
    'canDelete' => $canDelete,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
