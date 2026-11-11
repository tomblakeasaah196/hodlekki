<?php
// /auth/reset_password.php
//
// The page a reset link opens. GET shows the form (and never uses the link);
// the POST sets the new password through email_reset_complete(), which applies
// the one password policy (security_password_problems()) and the AGENTS.md
// rule for every credential change: password_changed_at, lockout cleared,
// every session and remembered device signed out. The link works once.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$token = preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) ? $token : '';
$result = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $token !== '') {
    $result = ui_standalone_csrf_ok()
        ? email_reset_complete($pdo, $token, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''))
        : ['ok' => false, 'code' => 'STALE'];
}
$peek = $token !== '' ? email_reset_peek($pdo, $token) : ['state' => 'invalid', 'first_name' => ''];
$signIn = ui_button('Sign in', ['variant' => 'primary', 'href' => '/auth/login.php', 'block' => true]);
$again = ui_button('Send a new link', ['variant' => 'primary', 'href' => '/auth/forgot_password.php', 'block' => true]);

$formError = null;
if ($result !== null && empty($result['ok'])) {
    $formError = match ($result['code'] ?? '') {
        'MISMATCH' => 'The two passwords are not the same. Type them again.',
        'WEAK'     => implode(' ', (array) ($result['problems'] ?? [])),
        'STALE'    => 'This page was open too long. Please try again.',
        default    => null,
    };
}

ui_standalone_open('Choose a new password');

if ($result !== null && !empty($result['ok'])): ?>
    <?= ui_banner('success', ['title' => 'Password changed', 'text' => 'For your safety every device was signed out. Sign in with your new password.']) ?>
    <?= $signIn ?>
<?php elseif ($peek['state'] === 'valid'): ?>
    <h1 class="ui-h2">Choose a new password</h1>
    <p class="text-ink-muted">Hi <?= ui_e($peek['first_name']) ?>. Pick a password you do not use anywhere else.</p>
    <?php if ($formError !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $formError]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/reset_password.php" class="ui-form" data-ui-validate novalidate>
        <?= ui_csrf_field() ?>
        <input type="hidden" name="t" value="<?= ui_e($token) ?>">
        <?= ui_field('password', ['name' => 'password', 'label' => 'New password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <?= ui_field('password', ['name' => 'confirm', 'label' => 'Type it again', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <div class="ui-hint">
            <p>Your new password needs:</p>
            <ul class="ui-prose">
                <?php foreach (security_password_rules() as $rule): ?>
                    <li><?= ui_e($rule) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?= ui_button('Save new password', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
<?php elseif ($peek['state'] === 'expired'): ?>
    <?= ui_banner('warning', ['title' => 'This link has expired', 'text' => 'Reset links work for 30 minutes. Ask for a new one.']) ?>
    <?= $again ?>
<?php else: ?>
    <?= ui_banner('warning', ['title' => 'This link no longer works', 'text' => 'It was already used, or a newer link replaced it. Ask for a new one if you still need it.']) ?>
    <?= $again ?>
<?php endif;

ui_standalone_close();
