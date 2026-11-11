<?php
// /auth/reset_password.php
//
// The page a reset link opens. GET shows the form (and never uses the link);
// the POST sets the new password through email_reset_complete(), which applies
// the one password policy (security_password_problems()) and the AGENTS.md
// rule for every credential change: password_changed_at, lockout cleared,
// every session and remembered device signed out. The link works once.
// Short copy: docs/UI.md §12.

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
$again = ui_button('Send new link', ['variant' => 'primary', 'href' => '/auth/forgot_password.php', 'block' => true]);

$formError = null;
$formInfo = null;
if ($result !== null && empty($result['ok'])) {
    $problems = array_values((array) ($result['problems'] ?? []));
    [$formError, $formInfo] = match ($result['code'] ?? '') {
        'MISMATCH' => ['The two passwords differ.', null],
        'WEAK'     => [(string) ($problems[0] ?? 'Choose a stronger password.'), count($problems) > 1 ? implode(' ', $problems) : null],
        'STALE'    => ['Please try again.', null],
        default    => [null, null],
    };
}
$rules = implode(' · ', security_password_rules());

ui_standalone_open('New password');

if ($result !== null && !empty($result['ok'])): ?>
    <?= ui_banner('success', ['title' => 'Password changed', 'text' => 'Every device was signed out.']) ?>
    <?= $signIn ?>
<?php elseif ($peek['state'] === 'valid'): ?>
    <?php if ($peek['first_name'] !== ''): ?>
        <p class="text-ink-muted">Hi <?= ui_e($peek['first_name']) ?></p>
    <?php endif; ?>
    <h1 class="ui-h2">New password</h1>
    <?php if ($formError !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $formError, 'info' => $formInfo]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/reset_password.php" class="ui-form" data-ui-validate novalidate>
        <?= ui_csrf_field() ?>
        <input type="hidden" name="t" value="<?= ui_e($token) ?>">
        <?= ui_field('password', ['name' => 'password', 'label' => 'New password', 'required' => true,
            'autocomplete' => 'new-password', 'info' => 'It needs: ' . $rules . '.']) ?>
        <?= ui_field('password', ['name' => 'confirm', 'label' => 'Type it again', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <?= ui_button('Save password', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
<?php elseif ($peek['state'] === 'expired'): ?>
    <?= ui_banner('warning', ['title' => 'Link expired', 'text' => 'Ask for a new one.', 'info' => 'Reset links work for 30 minutes.']) ?>
    <?= $again ?>
<?php else: ?>
    <?= ui_banner('warning', ['title' => 'Link no longer works', 'text' => 'Ask for a new one.', 'info' => 'It was already used, or a newer link replaced it.']) ?>
    <?= $again ?>
<?php endif;

ui_standalone_close();
