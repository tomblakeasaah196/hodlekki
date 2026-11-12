<?php
// /modules/departments/renew.php
/**
 * Renew seats (permissions design §5.7, lead decision 4). The "Seats ending
 * soon" message a pastor receives 14 days before seats end links here with
 * the roster rows (?ids=…). Each seat that continues is ticked and renewed in
 * one click: its end moves to the next ministry year's end, or 12 months on
 * (perm_seat_renew). Only seats ranked below the person's own standing can be
 * renewed (lead decision 6). A plain POST form: strict mode, no script.
 */
$ui = ['title' => 'Renew seats', 'mode' => 'strict', 'offline' => false];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/permissions/expiry.php';
require_can('departments', 'view');

$ctx = perm_current();
$ids = array_slice(array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string) ($_POST['ids'] ?? $_GET['ids'] ?? '')))))), 0, 200);

$renewed = 0;
$problems = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $problems[] = 'This page was open too long. Tick the seats again and renew.';
    } else {
        foreach ((array) ($_POST['record'] ?? []) as $rid) {
            try {
                perm_seat_renew($pdo, $ctx, (int) $rid, 'departments');
                $renewed++;
            } catch (PermAdminError $e) {
                $problems[$e->getMessage()] = $e->getMessage();
            }
        }
    }
}

$names = perm_department_names($pdo);
$rows = [];
foreach ($ids as $id) {
    $seat = perm_seat_record($pdo, $id);
    if ($seat === null || $seat['roster_status'] !== 'Active' || $seat['seat_key'] === null) {
        continue;
    }
    $rows[] = [
        'id' => $id,
        'name' => perm_user_name(perm_user_row($pdo, (int) $seat['user_id'])),
        'seat' => perm_seat_text($pdo, $seat['seat_key'], (int) $seat['department_id'], $names),
        'ends_on' => $seat['ends_on'],
        'may' => (int) $seat['year_live'] === 1 && perm_ctx_can_appoint($pdo, $ctx, (int) $seat['department_id'], $seat['seat_key']),
    ];
}
$mayAny = array_filter($rows, static fn ($r) => $r['may']) !== [];
?>
<div class="ui-page ui-page--narrow">
    <?= ui_page_header([
        'title' => 'Renew seats',
        'description' => 'Tick the seats that continue, then renew.',
        'info' => 'A renewed seat runs to the end of the next ministry year, or 12 months on if none is set up yet. '
            . 'You can renew seats ranked below your own. Seats you leave unticked end on their date.',
        'back' => ['href' => '/modules/departments/', 'label' => 'Departments'],
    ]) ?>

<?php if ($renewed > 0): ?>
    <?= ui_banner('success', ['title' => $renewed . ' ' . ($renewed === 1 ? 'seat' : 'seats') . ' renewed',
        'text' => 'Each holder has been told.']) ?>
<?php endif; ?>
<?php foreach ($problems as $problem): ?>
    <?= ui_banner('danger', ['text' => $problem]) ?>
<?php endforeach; ?>

<?php if ($rows === []): ?>
    <?= ui_empty(['icon' => 'calendar-check', 'title' => 'No seats to renew',
        'text' => 'Open this page from a "Seats ending soon" message.',
        'actions' => ui_button('Open Departments', ['variant' => 'primary', 'href' => '/modules/departments/'])]) ?>
<?php else: ?>
    <form method="post" class="ui-card">
        <?= ui_csrf_field() ?>
        <input type="hidden" name="ids" value="<?= ui_e(implode(',', $ids)) ?>">
        <div class="ui-list">
<?php foreach ($rows as $r): ?>
            <label class="ui-list__item ui-check">
                <input type="checkbox" name="record[]" value="<?= (int) $r['id'] ?>"<?= $r['may'] ? '' : ' disabled' ?><?= $r['may'] && $renewed === 0 ? ' checked' : '' ?>>
                <span class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($r['name']) ?></span>
                    <span class="ui-list__meta"><?= ui_e($r['seat']) ?> · until <?= ui_e(perm_date_text($r['ends_on'])) ?><?= $r['may'] ? '' : ' · ranked above you' ?></span>
                </span>
            </label>
<?php endforeach; ?>
        </div>
<?php if ($mayAny): ?>
        <div class="ui-card__footer ui-form-actions">
            <?= ui_button('Renew seats', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'refresh-cw']) ?>
        </div>
<?php endif; ?>
    </form>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
