<?php
// /auth/forgot_password.php
//
// "Forgot your password?" (owner's decision 2). Anyone may ask; the answer is
// always the same, so this page cannot be used to find out whether an account
// or an address exists. A link goes only to the CONFIRMED personal email of an
// active account (email_reset_request(), includes/campaigns/identity.php),
// throttled per requester and per identifier. First-time password setup stays
// on /auth/setup_password.php.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

$done = false;
$error = null;
$typed = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $typed = trim((string) ($_POST['identifier'] ?? ''));
    if (!ui_standalone_csrf_ok()) {
        $error = 'This page was open too long. Please try again.';
    } elseif ($typed === '') {
        $error = 'Enter the email you sign in with.';
    } else {
        try {
            email_reset_request($pdo, $typed, security_client_ip());
        } catch (Throwable $e) {
            error_log('[email] reset request failed: ' . $e->getMessage());
        }
        $done = true; // the same answer whatever happened
    }
}

ui_standalone_open('Reset your password', ['footer' => '<a class="ui-link" href="/auth/login.php">Back to sign in</a>']);

if ($done): ?>
    <h1 class="ui-h2">Check your email</h1>
    <p class="text-ink-muted">
        If that account has a confirmed personal email, a link to choose a new password is on its way.
        It works once, for 30 minutes. Look in Spam or Promotions too.
    </p>
    <?= ui_banner('info', ['title' => 'No email on your account yet?',
        'text' => 'Ask the church office to reset your password. Once you are signed in, add your email and next time you can do this yourself.']) ?>
<?php else: ?>
    <h1 class="ui-h2">Forgot your password?</h1>
    <p class="text-ink-muted">Enter the email you sign in with. We will send a link to your confirmed personal email.</p>
    <?php if ($error !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $error]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/forgot_password.php" class="ui-form" data-ui-validate novalidate>
        <?= ui_csrf_field() ?>
        <?= ui_field('email', [
            'name' => 'identifier',
            'label' => 'Email',
            'value' => $typed,
            'required' => true,
            'autocomplete' => 'username',
            'inputmode' => 'email',
            'hint' => 'Your personal email, or the church sign-in name you were given.',
        ]) ?>
        <?= ui_button('Send reset link', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'mail', 'block' => true]) ?>
    </form>
<?php endif;

ui_standalone_close();
