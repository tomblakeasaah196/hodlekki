<?php
// /modules/alerts/index.php
/**
 * Alerts Centre: push delivery health, the site-wide switches, and the
 * broadcast composer that can reach the whole congregation.
 *
 * Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/alerts.js, data from api/push_admin_api.php.
 *
 * Access: Roles & permissions, module "alerts", read live from the database
 * through can('alerts') — never the session's switchable role. View opens the
 * health panel; Manage sends broadcasts and flips the switches. The API checks
 * both again.
 */
$ui = [
    'title'   => 'Alerts Centre',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/alerts.js'],
    'offline' => false,   // live delivery state: never an old copy
];
require_once '../../includes/header.php';
require_can('alerts');

$canManage = can('alerts', 'manage');

$banners = [
    'key_mismatch' => ui_banner('danger', [
        'title' => 'Signing keys changed',
        'text' => 'Devices that subscribed before the change receive nothing.',
        'info' => 'The VAPID keys in .env do not match the pair generated before them, and devices are still registered against the old pair. '
            . 'Either remove the .env keys to go back to the generated pair, or delete the old pair from push_config '
            . 'and ask everyone to turn alerts on again. See docs/pwa_push.md.',
    ]),
    'FEATURE_NOT_READY' => ui_banner('warning', [
        'title' => 'Push is not delivering',
        'text' => 'The tables arrive with the next deploy: nothing to do.',
    ]),
    'NO_VAPID_KEYS' => ui_banner('warning', [
        'title' => 'Push is not delivering',
        'text' => 'There are no usable signing keys yet.',
        'info' => 'Run php bin/generate_vapid_keys.php and put the result in .env.',
    ]),
    'NO_OPENSSL_ECDH' => ui_banner('warning', [
        'title' => 'Push is not delivering',
        'text' => "This server's PHP cannot encrypt push messages.",
        'info' => 'ext-openssl on this server has no elliptic-curve support.',
    ]),
    'DISABLED' => ui_banner('warning', [
        'title' => 'Push is switched off',
        'text' => 'Nobody is sent anything; the bell still works.',
    ]),
    'other' => ui_banner('warning', [
        'title' => 'Push is not delivering',
        'text' => 'Check the delivery panel below for the cause.',
    ]),
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Alerts Centre',
        'description' => 'Push health and congregation broadcasts.',
        'info' => 'Shows whether push notifications are being delivered, and gives one place to reach the congregation. '
            . 'A broadcast writes a normal notification: everyone sees it in their bell, and phones with alerts on also buzz. '
            . 'Quiet hours and opt-outs are respected automatically.',
        'actions' => ui_button('My alerts', ['icon' => 'bell-ring', 'attrs' => ['data-hod-alerts' => true]])
            . ui_button('Refresh', ['icon' => 'refresh-cw', 'attrs' => ['data-action' => 'alerts.refresh']]),
    ]) ?>

    <div class="ui-stack">
        <div data-alerts-error hidden><?= ui_banner('danger', ['text' => 'Delivery health did not load: try Refresh.']) ?></div>
<?php foreach ($banners as $key => $html): ?>
        <div data-alerts-banner="<?= ui_e($key) ?>" hidden><?= $html ?></div>
<?php endforeach; ?>

        <div class="ui-grid ui-grid--stats">
            <div data-stat="devices"><?= ui_stat(['label' => 'Devices', 'value' => '—', 'icon' => 'smartphone']) ?></div>
            <div data-stat="members"><?= ui_stat(['label' => 'Members reached', 'value' => '—', 'icon' => 'users']) ?></div>
            <div data-stat="sent_today"><?= ui_stat(['label' => 'Delivered today', 'value' => '—', 'icon' => 'send']) ?></div>
            <div data-stat="pending"><?= ui_stat(['label' => 'Waiting', 'value' => '—', 'icon' => 'clock']) ?></div>
        </div>

        <div class="<?= $canManage ? 'ui-grid ui-grid--sidebar' : 'ui-stack' ?>">
<?php if ($canManage): ?>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h2 class="ui-h3">Send a broadcast</h2><?= ui_info(
                        'It reaches everyone\'s bell, whether or not they have a phone registered, and buzzes the phones of those who do. '
                        . 'The first tap shows how many people it reaches; the second sends it. A broadcast cannot be unsent.',
                        'About broadcasts'
                    ) ?></div>
                </div>
                <div class="ui-card__body">
                    <form class="ui-form" id="alerts-form" data-action="alerts.send" novalidate>
                        <?= ui_field('text', ['name' => 'title', 'id' => 'alerts-title', 'label' => 'Title', 'required' => true,
                            'hint' => '0/120', 'info' => 'The bold line on the lock screen.',
                            'attrs' => ['maxlength' => 120, 'data-action-input' => 'alerts.edit', 'data-count' => 120]]) ?>
                        <?= ui_field('textarea', ['name' => 'message', 'id' => 'alerts-message', 'label' => 'Message', 'required' => true,
                            'hint' => '0/400', 'info' => 'Phones show about two lines.',
                            'attrs' => ['maxlength' => 400, 'rows' => 3, 'data-action-input' => 'alerts.edit', 'data-count' => 400]]) ?>
                        <div class="ui-form-grid">
                            <?= ui_field('select', ['name' => 'audience', 'label' => 'Who', 'required' => true, 'options' => [
                                '' => 'Choose…', 'everyone' => 'Everyone', 'with_devices' => 'Everyone with alerts on',
                                'department' => 'A department', 'role' => 'A role', 'region' => 'A region',
                            ], 'attrs' => ['data-action-change' => 'alerts.audience']]) ?>
                            <div data-alerts-target hidden>
                                <?= ui_field('select', ['name' => 'audience_id', 'label' => 'Which one', 'required' => true, 'options' => [],
                                    'attrs' => ['data-action-change' => 'alerts.edit']]) ?>
                            </div>
                        </div>
                        <?= ui_field('text', ['name' => 'link', 'label' => 'Opens when tapped', 'value' => '/',
                            'info' => 'A path on this site, such as /modules/events/. Anything else opens the dashboard.',
                            'attrs' => ['maxlength' => 255, 'data-action-input' => 'alerts.edit']]) ?>
                        <div data-alerts-preview hidden></div>
                        <div class="ui-toolbar">
                            <?= ui_button('Check reach', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'users', 'attrs' => ['data-alerts-submit' => true]]) ?>
                        </div>
                    </form>
                </div>
            </section>
<?php endif; ?>

            <div class="ui-stack">
                <section class="ui-card">
                    <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Delivery</h2></div>
                    <div class="ui-card__body" data-alerts-health><p class="ui-meta">Loading…</p></div>
                </section>
<?php if ($canManage): ?>
                <section class="ui-card">
                    <div class="ui-card__header ui-card__header--divided">
                        <div class="ui-title-row"><h2 class="ui-h3">Switches</h2><?= ui_info('Every change here is written to the security audit log.', 'About switches') ?></div>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <div class="ui-title-row">
                            <label class="ui-check items-center"><input type="checkbox" class="ui-switch" data-action-change="alerts.switch" data-key="enabled" disabled><span>Push notifications</span></label>
                            <?= ui_info('Off means nobody is sent anything. The bell keeps working.', 'About push notifications') ?>
                        </div>
                        <div class="ui-title-row">
                            <label class="ui-check items-center"><input type="checkbox" class="ui-switch" data-action-change="alerts.switch" data-key="inline_dispatch" disabled><span>Instant delivery</span></label>
                            <?= ui_info('Off means the once-a-minute cron job sends them instead.', 'About instant delivery') ?>
                        </div>
                    </div>
                </section>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= ui_json_script('alerts-boot', [
    'api' => '/api/push_admin_api.php',
    'canManage' => $canManage,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
