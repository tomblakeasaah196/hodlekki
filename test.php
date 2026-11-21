<?php
// /test.php
// The link and the QR code of a standalone test (includes/trainings/tests.php, docs/EXAMS.md §8 and §9):
//   /test?t=<the test's exam item id>
// Signed in: straight to the test. Not signed in: the QR entry (owner, 21 Nov 2026, "very lean… straight to the
// point"): the phone number → "Welcome, <name>" → the password, or a first one → the email, only if HOD has none →
// the test. A forgotten password takes a code sent to their confirmed email. Steps: assets/ui/js/pages/test-entry.js
// → api/test_entry_api.php → includes/trainings/entry.php. Strict CSP, standalone layout.
require_once __DIR__ . '/includes/db.php';

$item = (int) ($_GET['t'] ?? 0);
if ($item <= 0) {
    header('Location: /modules/trainings/#tab=tests', true, 302);
    exit;
}
$target = '/modules/exams/take?item=' . $item;
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $target, true, 302);
    exit;
}

require_once __DIR__ . '/includes/campaigns/bootstrap.php';
require_once __DIR__ . '/includes/trainings/bootstrap.php';
require_once __DIR__ . '/includes/trainings/entry.php';
require_once __DIR__ . '/includes/ui/standalone.php';

$test = trainings_ready($pdo) && function_exists('exams_ready') && exams_ready($pdo) ? trainings_entry_test($pdo, $item) : null;
unset($_SESSION['test_entry']);   // a fresh start every time the link is opened

ui_standalone_open($test !== null ? (string) $test['title'] : 'Test', [
    'scripts' => ['/assets/ui/js/pages/test-entry.js'],
    'footer' => '<a class="ui-link" href="/auth/login?next=' . rawurlencode($target) . '">Sign in with email instead</a>',
]);
if ($test === null): ?>
    <div class="ui-stack ui-stack--sm">
        <h1 class="ui-h2">Test not open</h1>
        <p class="text-ink-muted">It may have closed, or not opened yet.</p>
    </div>
<?php
    ui_standalone_close();
    exit;
endif;
$rules = implode(' · ', security_password_rules());
?>
    <div class="te" data-test-entry="<?= (int) $item ?>">
        <p class="ui-meta"><?= ui_e($test['title']) ?></p>
        <div id="te-alert" aria-live="polite"></div>

        <form class="ui-form" data-te-step="phone" data-action="te.lookup" novalidate>
            <h1 class="ui-h2">Take the test</h1>
            <?= ui_field('tel', ['name' => 'phone', 'id' => 'te-phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel',
                'inputmode' => 'tel', 'placeholder' => '0803 000 0000', 'attrs' => ['maxlength' => 24, 'autofocus' => true]]) ?>
            <?= ui_button('Continue', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'icon' => 'arrow-right']) ?>
        </form>

        <div data-te-step="choose" hidden>
            <h1 class="ui-h2">Which one is you?</h1>
            <div class="ui-stack ui-stack--sm mt-4" data-te-people></div>
        </div>

        <form class="ui-form" data-te-step="password" data-action="te.signin" hidden novalidate>
            <h1 class="ui-h2" data-te-welcome>Welcome</h1>
            <?= ui_field('password', ['name' => 'password', 'id' => 'te-password', 'label' => 'Password', 'required' => true, 'autocomplete' => 'current-password']) ?>
            <?= ui_button('Sign in', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
            <p class="text-center"><button type="button" class="ui-link text-sm" data-action="te.forgot">Forgot password?</button></p>
        </form>

        <form class="ui-form" data-te-step="set_password" data-action="te.set" hidden novalidate>
            <h1 class="ui-h2" data-te-welcome>Welcome</h1>
            <p class="text-ink-muted">First time here? Set a password.</p>
            <?= ui_field('password', ['name' => 'password', 'id' => 'te-new', 'label' => 'New password', 'required' => true, 'autocomplete' => 'new-password',
                'info' => 'It needs: ' . $rules . '.']) ?>
            <?= ui_field('password', ['name' => 'confirm', 'id' => 'te-new2', 'label' => 'Confirm password', 'required' => true, 'autocomplete' => 'new-password']) ?>
            <?= ui_button('Set password', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
        </form>

        <form class="ui-form" data-te-step="email" data-action="te.email" hidden novalidate>
            <h1 class="ui-h2">Your email</h1>
            <?= ui_field('email', ['name' => 'email', 'id' => 'te-email', 'label' => 'Email', 'required' => true, 'autocomplete' => 'email',
                'inputmode' => 'email', 'placeholder' => 'you@gmail.com', 'attrs' => ['maxlength' => 190, 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
            <?= ui_button('Continue', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'icon' => 'arrow-right']) ?>
            <p class="text-center"><button type="button" class="ui-link text-sm" data-action="te.no-email">I don't have an email</button></p>
        </form>

        <form class="ui-form" data-te-step="code" data-action="te.reset" hidden novalidate>
            <h1 class="ui-h2">Check your email</h1>
            <p class="text-ink-muted">We sent a code to <strong data-te-to></strong>.</p>
            <?= ui_field('text', ['name' => 'code', 'id' => 'te-code', 'label' => 'Code', 'required' => true, 'autocomplete' => 'one-time-code',
                'inputmode' => 'numeric', 'attrs' => ['maxlength' => 7, 'pattern' => '[0-9 ]*']]) ?>
            <?= ui_field('password', ['name' => 'password', 'id' => 'te-reset', 'label' => 'New password', 'required' => true, 'autocomplete' => 'new-password',
                'info' => 'It needs: ' . $rules . '.']) ?>
            <?= ui_field('password', ['name' => 'confirm', 'id' => 'te-reset2', 'label' => 'Confirm password', 'required' => true, 'autocomplete' => 'new-password']) ?>
            <?= ui_button('Reset password', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
        </form>

        <div data-te-step="office" hidden>
            <h1 class="ui-h2">See the church office</h1>
            <p class="mt-1 text-ink-muted" data-te-office></p>
            <p class="mt-4"><button type="button" class="ui-link" data-action="te.restart">Try another number</button></p>
        </div>

        <div data-te-step="go" hidden>
            <h1 class="ui-h2">Opening your test</h1>
            <p class="mt-1 text-ink-muted">One moment…</p>
        </div>
    </div>
<?php
ui_standalone_close();
