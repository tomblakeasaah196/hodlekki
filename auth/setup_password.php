<?php
// /auth/setup_password.php
// FIRST-TIME password setup (AGENTS.md: it only ever creates a first
// password; api/setup_password_api.php refuses an account that has one).
// One card: the email or the phone number on the member's record, then the
// new password (a number shared by two accounts also asks for the first
// name). Rebuilt on the foundation (strict CSP, standalone layout); script:
// assets/ui/js/pages/setup-password.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/../includes/security_headers.php';
security_send_baseline_headers();
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

ui_standalone_open('First-time password', [
    'scripts' => ['/assets/ui/js/pages/setup-password.js'],
    'footer' => '<a class="ui-link" href="/auth/login">Back to Login</a>',
]);
$rules = implode(' · ', security_password_rules());
?>
    <div>
        <h1 class="ui-h2">Set your password</h1>
        <p class="mt-1 text-ink-muted">For accounts that have never had one.</p>
    </div>

    <?= ui_banner('info', [
        'title' => 'Already have a password?',
        'text' => 'Sign in, then change it under My Profile, Security.',
        'info' => 'This page only creates a first password. A church administrator can also reset yours.',
    ]) ?>

    <div id="setup-alert" aria-live="polite"></div>

    <form class="ui-form" id="setup-form" data-action="setup.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="setup_password">
        <?= ui_field('text', ['name' => 'email', 'id' => 'setup-email', 'label' => 'Email or phone', 'required' => true,
            'autocomplete' => 'username', 'placeholder' => 'you@gmail.com or 0803…',
            'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
        <div id="setup-name" hidden>
            <?= ui_field('text', ['name' => 'first_name', 'id' => 'setup-first', 'label' => 'First name',
                'autocomplete' => 'given-name', 'info' => 'Two accounts share this number. Your first name tells them apart.']) ?>
        </div>
        <?= ui_field('password', ['name' => 'new_password', 'id' => 'setup-password', 'label' => 'New password', 'required' => true,
            'autocomplete' => 'new-password', 'info' => 'Your password: ' . $rules . '.']) ?>
        <?= ui_field('password', ['name' => 'confirm_password', 'id' => 'setup-confirm', 'label' => 'Confirm password', 'required' => true,
            'autocomplete' => 'new-password']) ?>
        <?= ui_button('Set password', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
<?php
ui_standalone_close();
