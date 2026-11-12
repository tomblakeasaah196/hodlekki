<?php
// /modules/mail/outbox.php
/**
 * Outbox (docs/MAIL.md §7d): a person's own messages that wait (scheduled), that did not go, and their drafts.
 * Open goes back to the Mail page with the composer on that draft (?compose=); Cancel and Delete act here through
 * api/mail_compose_api.php (assets/ui/js/pages/mail-outbox.js). Rows are the person's own only (Outbox::listFor).
 */
$ui = [
    'title'   => 'Outbox',
    'mode'    => 'strict',
    'styles'  => ['/assets/css/mail.css'],
    'scripts' => ['/assets/ui/js/pages/mail-outbox.js'],
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\Clock;
use HOD\Mail\Compose\Outbox;
use HOD\Mail\Read\Format;
use HOD\Mail\Store;

$uid = (int) ($_SESSION['user_id'] ?? 0);
$back = ['href' => '/modules/mail/', 'label' => 'Mail'];
if (!mail_ready() || !mail_compose_ready()) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'Outbox', 'back' => $back])
        . ui_empty(['icon' => 'mail', 'title' => 'Not ready yet', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$lists = Outbox::make()->listFor($uid);
$sections = [
    'scheduled' => ['Scheduled', 'clock'],
    'failed' => ['Not sent', 'triangle-alert'],
    'drafts' => ['Drafts', 'file-text'],
];
$row = static function (array $r, string $group): string {
    $p = $r['payload'];
    $account = Store::account($r['account_id']);
    $first = $p['to'][0] ?? $p['cc'][0] ?? $p['bcc'][0] ?? null;
    $more = count($p['to'] ?? []) + count($p['cc'] ?? []) + count($p['bcc'] ?? []) - 1;
    $to = $first ? ($first['name'] ?: $first['email']) . ($more > 0 ? " +{$more}" : '') : 'No recipient yet';
    $when = $group === 'scheduled' ? 'Goes ' . Format::full($r['send_at']) : Format::when($r['updated_at'], true);
    $open = '/modules/mail/?box=' . (int) $r['account_id'] . '&compose=' . rawurlencode($r['id']);
    $actions = ui_button('Open', ['variant' => 'secondary', 'size' => 'sm', 'href' => $open]);
    if ($group === 'scheduled') {
        $actions .= ui_button('Cancel send', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'outbox.cancel', 'data-id' => $r['id']]]);
    }
    $actions .= ui_button('Delete', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true, 'attrs' => ['data-action' => 'outbox.delete', 'data-id' => $r['id']]]);

    return '<li class="ui-list__item" data-row="' . ui_e($r['id']) . '"><div class="ui-list__main">'
        . '<span class="ui-list__title">' . ui_e(trim((string) ($p['subject'] ?? '')) ?: '(no subject)') . '</span>'
        . '<span class="ui-list__meta">' . ui_e($to) . ' · ' . ui_e($account['email'] ?? '') . ' · ' . ui_e($when) . '</span>'
        . ($group === 'failed' && $r['last_error'] ? '<span class="ui-list__meta">' . ui_e($r['last_error']) . '</span>' : '')
        . '</div><div class="ui-list__end">' . $actions . '</div></li>';
};
$total = count($lists['scheduled']) + count($lists['failed']) + count($lists['drafts']);
?>
<div class="ui-page" data-outbox>
    <?= ui_page_header([
        'title' => 'Outbox',
        'description' => 'Scheduled, not sent, and drafts.',
        'info' => 'Only your own messages. Open one to change it or send it again; a scheduled message goes back to a draft when opened.',
        'back' => $back,
        'actions' => ui_button('My signature', ['variant' => 'ghost', 'icon' => 'pencil', 'href' => '/modules/mail/signature']),
    ]) ?>

    <?php if ($total === 0): ?>
        <?= ui_empty(['icon' => 'send', 'title' => 'Nothing waiting', 'text' => 'Scheduled and unsent mail shows here.']) ?>
    <?php endif; ?>

    <?php foreach ($sections as $group => [$label, $icon]): ?>
        <?php if ($lists[$group]): ?>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3"><?= ui_icon($icon, 'ui-icon--sm') ?> <?= ui_e($label) ?> <?= ui_badge((string) count($lists[$group]), $group === 'failed' ? 'danger' : 'neutral', ['round' => true]) ?></h2></div>
                <ul class="ui-list">
                    <?php foreach ($lists[$group] as $r): ?>
                        <?= $row($r, $group) ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?= ui_json_script('mail-outbox', ['api' => '/api/mail_compose_api.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
