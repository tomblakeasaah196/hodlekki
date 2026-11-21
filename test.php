<?php
// /test.php
// The link and the QR code of a standalone test (includes/trainings/tests.php, docs/EXAMS.md §8 and §9):
//   /test?t=<the test's exam item id>
// Signed in: straight to the test. Not signed in: a short conversation that signs them in (owner, 21-22 Nov 2026:
// "very very short, punchy and lean… under 2 minutes… slight animations and transitions as messages go"): the phone
// number, then only what this person needs (their password; or a code to their email and a new one; their email,
// once). The server decides each step (includes/trainings/entry.php via api/test_entry_api.php); the conversation is
// assets/ui/js/pages/test-entry.js, styled in assets/ui/src/app.css "Test entry". Strict CSP, standalone (bare).
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
$signIn = '/auth/login?next=' . rawurlencode($target);
// The same quiet line as the sign-in page (owner: subtle, linking to the website).
$credit = 'Engineered by <a href="https://jbspraxis.com" target="_blank" rel="noopener">JBS Praxis</a>';

ui_standalone_open($test !== null ? (string) $test['title'] : 'Test', ['bare' => true, 'scripts' => ['/assets/ui/js/pages/test-entry.js']]);
if ($test === null): ?>
<div class="tq tq--closed">
    <div class="tq-closed">
        <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" width="140" height="49">
        <h1 class="ui-h2">Test not open</h1>
        <p class="text-ink-muted">It may have closed, or not opened yet.</p>
        <p><a class="ui-link" href="/modules/trainings/">Go to Trainings</a></p>
    </div>
    <p class="tq-credit"><?= $credit ?></p>
</div>
<?php
    ui_standalone_close();
    exit;
endif;
echo ui_json_script('tq-config', [
    'item' => $item,
    'title' => (string) $test['title'],
    'rules' => security_password_rules(),
    'signIn' => $signIn,
]);
?>
<div class="tq" data-test-entry>
    <header class="tq-head">
        <div class="tq-head__row">
            <img class="tq-logo" src="/assets/pwa/icon-128.png" width="36" height="36" alt="">
            <div class="tq-head__text">
                <p class="tq-kicker">HOD Lekki · Test</p>
                <h1 class="tq-title"><?= ui_e($test['title']) ?></h1>
            </div>
            <button type="button" class="tq-restart" data-action="tq.restart" hidden><?= ui_icon('rotate-ccw') ?><span>Start over</span></button>
        </div>
        <ol class="tq-rail" aria-label="Your progress">
            <li class="tq-rail__step" data-rail="phone"><span>Phone</span></li>
            <li class="tq-rail__step" data-rail="email"><span>Email</span></li>
            <li class="tq-rail__step" data-rail="password"><span>Password</span></li>
            <li class="tq-rail__step" data-rail="test"><span>Test</span></li>
        </ol>
    </header>

    <ol class="tq-log" data-tq-log aria-live="polite" aria-relevant="additions"></ol>

    <footer class="tq-foot">
        <div class="tq-pills" data-tq-pills role="group" aria-label="Quick answers" hidden></div>
        <form class="tq-composer" data-action="tq.send" novalidate>
            <label class="sr-only" for="tq-input" data-tq-label>Your answer</label>
            <input class="tq-input" id="tq-input" data-tq-input type="tel" inputmode="tel" autocomplete="tel" maxlength="190" placeholder="0803 123 4567">
            <button type="button" class="tq-eye" data-action="tq.eye" data-tq-eye aria-label="Hide password" aria-pressed="false" hidden><?= ui_icon('eye-off') ?></button>
            <button type="submit" class="tq-send" data-tq-send aria-label="Send"><?= ui_icon('send') ?></button>
        </form>
        <p class="tq-hint" data-tq-hint hidden></p>
        <p class="tq-credit"><?= $credit ?></p>
    </footer>
    <noscript><p class="tq-noscript">This page needs JavaScript. <a href="<?= ui_e($signIn) ?>">Sign in here</a> instead.</p></noscript>
</div>
<?php
ui_standalone_close();
