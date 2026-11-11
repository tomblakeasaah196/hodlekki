<?php
// /auth/add_email.php
//
// The REQUIRED personal-email prompt after sign-in (owner's decision 1).
// includes/header.php sends people here while email_prompt_state() says
// "required"; this file never includes header.php (that would loop), like
// change_password.php.
//
//   no address yet   → add one (an address on file is offered), with the
//                      church-news consent box unticked; or "I don't have an
//                      email" (asked again in 90 days)
//   link pending     → check your inbox · resend (one tap) · use another;
//                      under 7 days they may continue to the app
//   confirmed        → nothing to do: straight on to where they were going
//
// Forms post back here (strict CSP, no script needed). Guide: docs/EMAIL.md.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}
$uid = (int) $_SESSION['user_id'];
$next = auth_safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? '')) ?? '/index.php';
$self = '/auth/add_email.php?next=' . rawurlencode($next);

if (!email_identity_ready($pdo)) {
    header('Location: ' . $next);
    exit;
}

$error = null;
$resendNote = null;
$typed = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ui_standalone_csrf_ok()) {
        $error = 'This page was open too long to save safely. Please try again.';
    } else {
        $do = (string) ($_POST['do'] ?? '');
        $ip = security_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if ($do === 'save' || $do === 'resend') {
            $typed = trim((string) ($_POST['email'] ?? ''));
            if ($do === 'resend') {
                $typed = (string) (email_address_state($pdo, $uid)['pending'] ?? '');
            }
            $consent = $do === 'save' && !empty($_POST['news'])
                ? ['granted' => true, 'method' => 'signin_prompt', 'text' => EMAIL_CONSENT_NEWS_TEXT, 'ip' => $ip, 'user_agent' => $ua]
                : null;
            $r = email_capture_start($pdo, $uid, $typed, 'signin_prompt', $uid, ['consent' => $consent, 'ip' => $ip, 'user_agent' => $ua]);
            if (!empty($r['ok'])) {
                email_prompt_unskip($pdo, $uid);
                $back = auth_safe_next((string) ($_POST['back'] ?? ''));
                header('Location: ' . ($back ?? ($self . '&sent=1')));
                exit;
            }
            if ($do === 'resend') {
                // Stay on "check your inbox" and say why nothing new was sent.
                $resendNote = (string) ($r['message'] ?? 'Please try again in a minute.');
                $typed = '';
            } else {
                $error = (string) ($r['message'] ?? 'That did not work. Please try again.');
            }
        } elseif ($do === 'change') {
            email_capture_cancel($pdo, $uid);
            email_prompt_forget();
            header('Location: ' . $self);
            exit;
        } elseif ($do === 'skip') {
            email_prompt_skip($pdo, $uid, $uid);
            header('Location: ' . $next);
            exit;
        }
    }
}

$state = email_address_state($pdo, $uid);
$prompt = email_prompt_state($pdo, $uid);
email_prompt_forget();
if ($state['confirmed'] !== null) {
    header('Location: ' . $next);
    exit;
}
$firstName = (string) ($_SESSION['first_name'] ?? 'there');
$hidden = ui_csrf_field() . '<input type="hidden" name="next" value="' . ui_e($next) . '">';
$signOut = '<a class="ui-link" href="/auth/logout.php">Sign out</a>';

ui_standalone_open($state['pending'] !== null ? 'Check your inbox' : 'Add your email', ['footer' => $signOut]);

if ($state['pending'] !== null && $error === null):
    $sent = !empty($_GET['sent']);
?>
    <h1 class="ui-h2">Check your inbox</h1>
    <p class="text-ink-muted">
        We sent a link to <strong class="text-ink"><?= ui_e($state['pending']) ?></strong>.
        Tap it to confirm this is your email. It may take a minute, and it can land in Spam or Promotions.
    </p>
    <?php if ($resendNote !== null): ?>
        <?= ui_banner('info', ['title' => 'No new link yet', 'text' => $resendNote]) ?>
    <?php elseif ($sent): ?>
        <?= ui_banner('success', ['title' => 'Link sent', 'text' => 'It works for 7 days.']) ?>
    <?php elseif ($prompt === 'required'): ?>
        <?= ui_banner('warning', ['title' => 'Please confirm your email to continue',
            'text' => 'It has been more than a week. Send yourself a new link, or use a different email.']) ?>
    <?php endif; ?>
    <div class="ui-stack">
        <form method="post" action="/auth/add_email.php">
            <?= $hidden ?>
            <input type="hidden" name="do" value="resend">
            <?= ui_button('Send the link again', ['variant' => $prompt === 'required' ? 'primary' : 'secondary', 'type' => 'submit', 'icon' => 'mail', 'block' => true]) ?>
        </form>
        <form method="post" action="/auth/add_email.php">
            <?= $hidden ?>
            <input type="hidden" name="do" value="change">
            <?= ui_button('Use a different email', ['variant' => 'ghost', 'type' => 'submit', 'block' => true]) ?>
        </form>
        <?php if ($prompt !== 'required'): ?>
            <a class="ui-btn ui-btn--primary ui-btn--block" href="<?= ui_e($next) ?>">Continue to the app</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <h1 class="ui-h2">Add your personal email</h1>
    <p class="text-ink-muted">
        Hi <?= ui_e($firstName) ?>. We will use it to send you a link if you ever forget your password, and soon you will
        sign in with it. Use your own address, such as Gmail or Yahoo, not a church one.
    </p>
    <?php if ($error !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $error]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/add_email.php" class="ui-form" data-ui-validate novalidate>
        <?= $hidden ?>
        <input type="hidden" name="do" value="save">
        <?= ui_field('email', [
            'name' => 'email',
            'label' => 'Your personal email',
            'value' => $typed !== '' ? $typed : (string) ($state['on_file'] ?? ''),
            'required' => true,
            'autocomplete' => 'email',
            'inputmode' => 'email',
            'hint' => $state['on_file'] !== null && $typed === ''
                ? 'This is the email we have on file. If it is still yours, send the link; if not, type the right one.'
                : 'For example name@gmail.com.',
        ]) ?>
        <label class="ui-check">
            <input type="checkbox" name="news" value="1">
            <span><?= ui_e(EMAIL_CONSENT_NEWS_TEXT) ?>
                <span class="ui-check__hint">Optional. Account emails, like password reset links, come either way.</span>
            </span>
        </label>
        <?= ui_button('Send confirmation link', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
    <form method="post" action="/auth/add_email.php" class="text-center">
        <?= $hidden ?>
        <input type="hidden" name="do" value="skip">
        <?= ui_button("I don't have an email", ['variant' => 'ghost', 'type' => 'submit']) ?>
        <p class="ui-hint">We will ask again in 3 months. A church worker can also add one for you.</p>
    </form>
<?php
endif;
ui_standalone_close();
