<?php
// /modules/mail/signature.php
/**
 * My signature (docs/MAIL.md §7d): the church's design below every message a person sends. They edit only their own
 * numbers; mail admins also edit the church part. Strict mode: behaviour in assets/ui/js/pages/mail-signature.js,
 * which previews through api/mail_compose_api.php (sig_preview) and saves there.
 */
$ui = [
    'title'   => 'My signature',
    'mode'    => 'strict',
    'styles'  => ['/assets/css/mail.css'],
    'scripts' => ['/assets/ui/js/pages/mail-signature.js'],
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\Compose\Signatures;
use HOD\Mail\MailAccess;

$uid = (int) ($_SESSION['user_id'] ?? 0);
$back = ['href' => '/modules/mail/index.php', 'label' => 'Mail'];
if (!mail_ready() || !mail_compose_ready()) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'My signature', 'back' => $back])
        . ui_empty(['icon' => 'mail', 'title' => 'Not ready yet', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$accounts = MailAccess::accounts($uid, 'send');
if (!$accounts) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'My signature', 'back' => $back])
        . ui_empty(['icon' => 'mail', 'title' => 'No sending mailbox', 'text' => 'Ask a mail admin for one.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$numbers = Signatures::numbers($uid);
$person = Signatures::person($uid);
$isAdmin = mail_can('admin');
$church = Signatures::church();
$options = [];
foreach ($accounts as $a) {
    $options[(string) $a['id']] = $a['email'];
}
?>
<div class="ui-page" data-signature>
    <?= ui_page_header([
        'title' => 'My signature',
        'description' => 'Added below the messages you send.',
        'info' => 'One design for the whole church. You change only your numbers; mail admins change the church part. Replies get the short one.',
        'back' => $back,
    ]) ?>

    <div class="ms-grid">
        <div class="ui-stack">
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Your numbers</h2></div>
                <form class="ui-card__body ui-form" data-sig-numbers novalidate>
                    <?= ui_field('tel', ['name' => 'phone', 'label' => 'Office number', 'value' => (string) ($numbers['phone'] ?? ''), 'placeholder' => '+234 801 234 5678', 'optional' => true]) ?>
                    <?= ui_field('tel', ['name' => 'mobile', 'label' => 'Mobile number', 'value' => (string) ($numbers['saved'] ? ($numbers['mobile'] ?? '') : ($person['profile_phone'] ?? '')), 'placeholder' => '+234 801 234 5678', 'optional' => true,
                        'info' => 'Until you save, the phone on your profile shows here. Leave a box empty to hide that number.']) ?>
                    <?php if (count($options) > 1): ?>
                        <?= ui_field('select', ['name' => 'accountId', 'label' => 'Preview for', 'options' => $options]) ?>
                    <?php endif; ?>
                    <div class="ui-form-actions"><?= ui_button('Save numbers', ['variant' => 'primary', 'type' => 'submit']) ?></div>
                </form>
            </section>

            <?php if ($isAdmin): ?>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Church part</h2><?= mail_info('Mail admins only. It changes everyone\'s signature from their next message.') ?></div>
                <form class="ui-card__body ui-form" data-sig-church novalidate>
                    <?= ui_field('text', ['name' => 'name', 'label' => 'Church name', 'value' => $church['name'], 'required' => true]) ?>
                    <?= ui_field('text', ['name' => 'tagline', 'label' => 'Tagline', 'value' => $church['tagline'], 'optional' => true]) ?>
                    <?= ui_field('text', ['name' => 'address', 'label' => 'Address', 'value' => $church['address'], 'optional' => true]) ?>
                    <?= ui_field('tel', ['name' => 'phone', 'label' => 'Church phone', 'value' => $church['phone'], 'optional' => true, 'info' => 'Shown when a person has no number of their own.']) ?>
                    <?= ui_field('url', ['name' => 'website', 'label' => 'Website', 'value' => $church['website'], 'optional' => true]) ?>
                    <?= ui_field('url', ['name' => 'logo_url', 'label' => 'Logo address', 'value' => $church['custom'] ? $church['logo_url'] : '', 'placeholder' => 'https://…', 'optional' => true,
                        'info' => 'Empty keeps the church logo. Mail apps need a PNG or JPG on an https address.']) ?>
                    <label class="ui-check"><input type="checkbox" name="show_logo" value="1"<?= $church['show_logo'] ? ' checked' : '' ?>> Show the logo</label>
                    <div class="ui-form-actions">
                        <?= ui_button('Reset', ['variant' => 'ghost', 'attrs' => ['data-action' => 'sig.reset']]) ?>
                        <?= ui_button('Save church part', ['variant' => 'primary', 'type' => 'submit']) ?>
                    </div>
                </form>
            </section>
            <?php endif; ?>
        </div>

        <section class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Preview</h2></div>
            <div class="ui-card__body ui-stack">
                <p class="ui-hint">New messages</p>
                <div class="ms-preview" data-sig-preview="full" aria-live="polite"></div>
                <p class="ui-hint">Replies</p>
                <div class="ms-preview" data-sig-preview="slim"></div>
            </div>
        </section>
    </div>
</div>
<?= ui_json_script('mail-signature', ['api' => '/api/mail_compose_api.php', 'admin' => $isAdmin]) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
