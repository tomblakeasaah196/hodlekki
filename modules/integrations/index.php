<?php
// /modules/integrations/index.php
/**
 * Integrations (docs/INTEGRATIONS.md): the outside services the ERP connects to. Today Google, for Trainings' live
 * classes (Calendar events with a Meet link, then who attended). Only can('integrations', 'manage').
 * Strict mode: behaviour in assets/ui/js/pages/integrations.js, buttons through api/integrations_api.php;
 * Connect is a form post to /integrations/google/connect, which sends the admin to Google.
 */
$ui = [
    'title'   => 'Integrations',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/integrations.js'],
    'offline' => false,
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/integrations/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;

if (!can('integrations', 'manage')) {
    echo ui_no_access('Ask an admin if you need to connect a service.');
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$g = Google::status();
$outcome = (string) ($_GET['google'] ?? '');
$banners = [
    'connected' => ['success', 'Google connected', 'Live classes can get a Meet link.'],
    'scopes' => ['warning', 'Permissions missing', 'Connect again and allow every box.'],
    'cancelled' => ['info', 'Connect cancelled', 'Nothing changed.'],
    'state' => ['warning', 'Connect expired', 'Press Connect again.'],
    'denied' => ['danger', 'Not allowed', 'Only Integrations managers can connect.'],
];
$banner = $banners[$outcome] ?? (isset(GoogleError::MESSAGES[$outcome]) ? ['danger', 'Connect failed', GoogleError::MESSAGES[$outcome]] : null);

$who = null;
if ($g['connected_by']) {
    $st = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $st->execute([$g['connected_by']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $who = $row ? trim($row['first_name'] . ' ' . $row['last_name']) : null;
}
[$label, $tone] = match ($g['state']) {
    'connected' => ['Connected', 'success'],
    'revoked' => ['Access withdrawn', 'danger'],
    'not_connected' => ['Not connected', 'warning'],
    'no_table' => ['Not ready', 'neutral'],
    default => ['Not set up', 'warning'],
};
$canConnect = in_array($g['state'], ['not_connected', 'revoked'], true);
$setup = 'In .env: GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET (Google Cloud → Credentials → the web client), and '
    . 'INTEGRATIONS_VAULT_KEY (64 hex characters; on the server: php -r "echo bin2hex(random_bytes(32));"). '
    . 'The client\'s redirect URI must be ' . Google::redirectUri() . '.';
?>
<div class="ui-page ui-page--narrow" data-integrations>
    <?= ui_page_header(['title' => 'Integrations', 'description' => 'Outside services the church uses.']) ?>

    <?php if ($banner): ?>
        <?= ui_banner($banner[0], ['title' => $banner[1], 'text' => $banner[2]]) ?>
    <?php endif; ?>

    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided">
            <h2 class="ui-h3">Google</h2>
            <?= ui_info('Calendar events with a Meet link for live classes, then who attended (Meet participants). One Google account at a time: to switch, Disconnect, then Connect signed in as the other account. Permissions asked: your email, calendar events, and reading Meet calls.') ?>
        </div>
        <ul class="ui-list">
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($g['email'] ?? 'No account') ?></span>
                    <?php if ($g['connected_at'] && $g['state'] !== 'not_connected'): ?>
                        <span class="ui-list__meta">Since <?= ui_e(substr((string) $g['connected_at'], 0, 16)) ?><?= $who ? ' · ' . ui_e($who) : '' ?></span>
                    <?php endif; ?>
                </div>
                <div class="ui-list__end"><?= ui_badge($label, $tone, ['dot' => true]) ?></div>
            </li>
            <?php if (in_array($g['state'], ['no_client', 'no_key'], true)): ?>
            <li class="ui-list__item">
                <div class="ui-list__main"><span class="ui-list__title"><?= $g['state'] === 'no_client' ? 'Client missing' : 'Vault key missing' ?></span></div>
                <div class="ui-list__end"><?= ui_info($setup, 'What to set up') ?></div>
            </li>
            <?php endif; ?>
            <?php if ($g['state'] === 'connected' && $g['missing_scopes']): ?>
            <li class="ui-list__item">
                <div class="ui-list__main"><span class="ui-list__title">Permissions missing</span></div>
                <div class="ui-list__end"><?= ui_info('Not allowed: ' . implode(', ', $g['missing_scopes']) . '. Disconnect, then Connect and allow every box.') ?></div>
            </li>
            <?php endif; ?>
            <?php if ($g['state'] === 'connected' || $g['state'] === 'revoked'): ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title">Last call</span>
                    <span class="ui-list__meta"><?= ui_e($g['last_error'] ? 'Failed: ' . $g['last_error'] : ($g['last_ok_at'] ? 'Worked ' . substr((string) $g['last_ok_at'], 0, 16) : 'None yet')) ?></span>
                </div>
            </li>
            <?php endif; ?>
        </ul>
        <div class="ui-card__footer">
            <?php if ($canConnect): ?>
                <form method="post" action="/integrations/google/connect">
                    <?= ui_csrf_field() ?>
                    <?= ui_button($g['state'] === 'revoked' ? 'Connect again' : 'Connect', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'link']) ?>
                </form>
            <?php endif; ?>
            <?php if ($g['state'] === 'connected'): ?>
                <?= ui_button('Test', ['attrs' => ['data-action' => 'google.test']]) ?>
            <?php endif; ?>
            <?php if ($g['state'] === 'connected' || $g['state'] === 'revoked'): ?>
                <?= ui_button('Disconnect', ['variant' => 'danger-ghost', 'attrs' => ['data-action' => 'google.disconnect', 'data-email' => (string) ($g['email'] ?? '')]]) ?>
            <?php endif; ?>
            <?= ui_info($setup, 'Setup') ?>
        </div>
    </section>
</div>
<?= ui_json_script('integrations', ['api' => '/api/integrations_api.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
