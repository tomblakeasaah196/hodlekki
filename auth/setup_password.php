<?php
// /auth/setup_password.php
// FIRST-TIME password setup (AGENTS.md: it only ever creates a first
// password; api/setup_password_api.php refuses an account that has one).
// Two views on one card: set the password, or look up the system email by
// first name and phone. Rebuilt on the foundation (strict CSP, standalone
// layout); script: assets/ui/js/pages/setup-password.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/../includes/security_headers.php';
security_send_baseline_headers();
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

ui_standalone_open('First-time password', [
    'scripts' => ['/assets/ui/js/pages/setup-password.js'],
    'footer' => '<a class="ui-link" href="/auth/login.php">Back to Login</a>',
]);
$rules = implode(' · ', security_password_rules());
?>
    <div>
        <h1 class="ui-h2">First-Time Password Setup</h1>
        <p class="mt-1 text-ink-muted" id="setup-sub">For accounts that have never had a password.</p>
    </div>

    <?= ui_banner('info', [
        'title' => 'Already have a password?',
        'text' => 'Sign in, then change it under My Profile, Security.',
        'info' => 'This page only creates a first password. A church administrator can also reset yours.',
    ]) ?>

    <div id="setup-alert" aria-live="polite"></div>

    <form class="ui-form" id="setup-form" data-action="setup.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="setup_password">
        <?= ui_field('text', ['name' => 'email', 'id' => 'setup-email', 'label' => 'Registered Email', 'required' => true,
            'autocomplete' => 'username', 'inputmode' => 'email', 'placeholder' => 'email@example.com',
            'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
        <?= ui_field('password', ['name' => 'new_password', 'id' => 'setup-password', 'label' => 'New Password', 'required' => true,
            'autocomplete' => 'new-password', 'info' => 'It needs: ' . $rules . '.']) ?>
        <?= ui_field('password', ['name' => 'confirm_password', 'id' => 'setup-confirm', 'label' => 'Confirm Password', 'required' => true,
            'autocomplete' => 'new-password']) ?>
        <?= ui_button('Update Credentials', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
        <div class="text-center">
            <?= ui_button('Find my email', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'search', 'attrs' => ['data-action' => 'setup.view', 'data-view' => 'lookup']]) ?>
        </div>
    </form>

    <form class="ui-form" id="lookup-form" data-action="setup.lookup" data-ui-validate novalidate hidden>
        <input type="hidden" name="action" value="lookup_email">
        <?= ui_field('text', ['name' => 'first_name', 'id' => 'lookup-first', 'label' => 'First Name', 'required' => true,
            'autocomplete' => 'given-name', 'placeholder' => 'David']) ?>
        <?= ui_field('tel', ['name' => 'phone', 'id' => 'lookup-phone', 'label' => 'Registered Phone', 'required' => true,
            'autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '08012345678']) ?>
        <?= ui_button('Find My Email', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
        <div class="text-center">
            <?= ui_button('I remember it', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'setup.view', 'data-view' => 'setup']]) ?>
        </div>
    </form>
<?php
ui_standalone_close();
