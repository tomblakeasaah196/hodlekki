<?php
// /modules/mail/settings.php
/**
 * My mail settings (docs/MAIL.md §7e): which new mail pushes a person gets per mailbox, the daily summary, and which
 * key alerts go to their confirmed personal email (sender and subject only). Strict mode: behaviour in
 * assets/ui/js/pages/mail-settings.js, saved through api/mail_team_api.php. Changes are saved as they are made.
 */
$ui = [
    'title'   => 'My mail settings',
    'mode'    => 'strict',
    'styles'  => ['/assets/css/mail.css'],
    'scripts' => ['/assets/ui/js/pages/mail-settings.js'],
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\MailAccess;
use HOD\Mail\Store;
use HOD\Mail\Team\PersonalAlerts;
use HOD\Mail\Team\Prefs;

$uid = (int) ($_SESSION['user_id'] ?? 0);
$back = ['href' => '/modules/mail/index.php', 'label' => 'Mail'];
if (!mail_ready() || !mail_team_ready()) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'My mail settings', 'back' => $back])
        . ui_empty(['icon' => 'mail', 'title' => 'Not ready yet', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$accounts = MailAccess::accounts($uid, 'read');
if (!$accounts) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'My mail settings', 'back' => $back])
        . ui_empty(['icon' => 'mail', 'title' => 'No mailbox yet', 'text' => 'Ask a mail admin for one.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$general = Prefs::general($uid);
$address = PersonalAlerts::address($uid);
$personalWords = Prefs::PERSONAL;
$hasPersonal = (bool) array_filter($accounts, fn ($a) => !Store::isShared($a));
$hasShared = (bool) array_filter($accounts, fn ($a) => Store::isShared($a));
if (!$hasPersonal) {
    unset($personalWords['personal_mail']);
}
if (!$hasShared) {
    unset($personalWords['assigned']);
}
if (!mail_can('admin')) {
    unset($personalWords['attention']);
}
?>
<div class="ui-page" data-mail-settings>
    <?= ui_page_header([
        'title' => 'My mail settings',
        'description' => 'Choose which mail alerts reach you.',
        'back' => $back,
    ]) ?>

    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">New mail on this device</h2>
            <?= mail_info('A push for each new email, when push is on for this device. Quiet hours from your notification settings apply.') ?></div>
        <ul class="ui-list">
            <?php foreach ($accounts as $a): $shared = Store::isShared($a); ?>
            <li class="ui-list__item">
                <div class="ui-list__main"><span class="ui-list__title"><?= ui_e($a['email']) ?></span></div>
                <div class="ui-list__end">
                    <label class="sr-only" for="push-<?= (int) $a['id'] ?>">Alerts for <?= ui_e($a['email']) ?></label>
                    <select id="push-<?= (int) $a['id'] ?>" class="ui-select ui-select--sm" data-push="<?= (int) $a['id'] ?>">
                        <?php foreach (Prefs::PUSH as $k => $label): if ($k === 'mine' && !$shared) { continue; } ?>
                            <option value="<?= ui_e($k) ?>"<?= Prefs::push($uid, (int) $a['id']) === $k ? ' selected' : '' ?>><?= ui_e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Daily summary</h2>
            <?= mail_info('At 06:45, only when there is something: unread mail, conversations open over a day, what is assigned to you.') ?></div>
        <div class="ui-card__body">
            <label class="ui-check"><input type="checkbox" data-general="summary"<?= $general['summary'] ? ' checked' : '' ?>> Show it in the app</label>
        </div>
    </section>

    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Personal email</h2>
            <?= mail_info('Key alerts to the personal email you confirmed for the church: the sender and subject only, never the message. At most one per conversation an hour, and 20 a day.') ?></div>
        <div class="ui-card__body ui-stack">
            <?php if ($address === null): ?>
                <p class="ui-hint">No confirmed personal email yet. <a href="/modules/profile/index.php">Add it on your profile</a>.</p>
            <?php else: ?>
                <p class="ui-hint">Goes to <?= ui_e($address) ?></p>
            <?php endif; ?>
            <?php foreach ($personalWords as $k => $label): ?>
                <label class="ui-check"><input type="checkbox" data-personal="<?= ui_e($k) ?>"<?= !empty($general['personal'][$k]) ? ' checked' : '' ?><?= $address === null ? ' disabled' : '' ?>> <?= ui_e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?= ui_json_script('mail-settings', ['api' => '/api/mail_team_api.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
