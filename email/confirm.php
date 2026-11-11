<?php
// /email/confirm.php
//
// The link in a "please confirm your email" message. Public: the person may
// not be signed in (a first timer from Connect, a member on their phone).
// GET only SHOWS the address and one button; the POST confirms. Mail scanners
// and link previews open links, they do not press buttons, so a scanner can
// never confirm an address on someone's behalf. The token (32 random bytes,
// stored as sha256) is the only thing trusted. Guide: docs/EMAIL.md.
// email/.htaccess must keep Referrer-Policy same-origin (never no-referrer):
// this page's own POST is CSRF-checked (#120).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$token = preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) ? $token : '';
$signedIn = !empty($_SESSION['user_id']);
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $token !== '') {
    $result = ui_standalone_csrf_ok()
        ? email_capture_confirm($pdo, $token, security_client_ip())
        : ['ok' => false, 'code' => 'STALE'];
    if (!empty($result['ok']) && $signedIn && (int) $_SESSION['user_id'] === (int) ($result['user_id'] ?? 0)) {
        email_prompt_forget();
    }
}
$peek = $token !== '' ? email_capture_peek($pdo, $token) : null;
$continue = $signedIn ? ui_button('Continue', ['variant' => 'primary', 'href' => '/index.php', 'block' => true])
                      : ui_button('Sign in', ['variant' => 'primary', 'href' => '/auth/login.php', 'block' => true]);

ui_standalone_open('Confirm your email');

if ($result !== null && !empty($result['ok'])): ?>
    <?= ui_banner('success', ['title' => 'Email confirmed', 'info' => 'Password reset links and church messages will come to ' . (string) $result['email'] . '.']) ?>
    <?= $continue ?>
<?php elseif ($result !== null && ($result['code'] ?? '') === 'TAKEN'): ?>
    <?= ui_banner('danger', ['title' => 'Email already in use', 'text' => 'Ask the church office for help.']) ?>
<?php elseif ($result !== null && ($result['code'] ?? '') === 'STALE'): ?>
    <?= ui_banner('warning', ['title' => 'Please try again', 'text' => 'Tap the link in your email again.']) ?>
<?php elseif ($peek !== null && $peek['state'] === 'pending'): ?>
    <h1 class="ui-h2">Confirm your email</h1>
    <p class="text-ink"><strong><?= ui_e($peek['email']) ?></strong></p>
    <form method="post" action="/email/confirm.php">
        <?= ui_csrf_field() ?>
        <input type="hidden" name="t" value="<?= ui_e($token) ?>">
        <?= ui_button('Confirm email', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check', 'block' => true]) ?>
    </form>
    <p class="ui-hint">Not you? Close this page. <?= ui_info('Nothing changes unless you press the button.', 'Not you') ?></p>
<?php elseif ($peek !== null && $peek['state'] === 'confirmed'): ?>
    <?= ui_banner('success', ['title' => 'Already confirmed']) ?>
    <?= $continue ?>
<?php elseif ($peek !== null && $peek['state'] === 'expired'): ?>
    <?= ui_banner('warning', ['title' => 'Link expired', 'text' => 'Sign in to get a new one.', 'info' => 'Links work for 7 days.']) ?>
    <?= $signedIn ? ui_button('Send new link', ['variant' => 'primary', 'href' => '/auth/add_email.php', 'block' => true]) : $continue ?>
<?php else: ?>
    <?= ui_banner('warning', ['title' => 'Link no longer works', 'text' => 'Use the newest link in your inbox.', 'info' => 'A newer link replaced it, or it was already used.']) ?>
    <?= $continue ?>
<?php endif;

ui_standalone_close();
