<?php
// /auth/add_email.php
//
// The REQUIRED personal-email prompt after sign-in (owner's decision 1).
// includes/header.php sends people here while email_prompt_state() says
// "required"; this file never includes header.php (that would loop), like
// change_password.php.
//
//   no address yet   → "Add your email" (an address on file is offered), or
//                      "I don't have an email" (asked again in 90 days). No
//                      church-news box: for members, church messages come with
//                      membership, with one-tap unsubscribe in every email.
//   link pending     → "Check your inbox": continue (under 7 days), resend,
//                      or "Wrong email?"
//   confirmed        → straight on to where they were going
//
// Short copy (docs/UI.md §12): the rest sits behind ui_info(). Forms post
// back here (strict CSP, no page script). Guide: docs/EMAIL.md.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}
$uid = (int) $_SESSION['user_id'];
$next = auth_safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? '')) ?? '/';
$self = '/auth/add_email?next=' . rawurlencode($next);

if (!email_identity_ready($pdo)) {
    header('Location: ' . $next);
    exit;
}

$error = null;
$resendNote = null;
$typed = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ui_standalone_csrf_ok()) {
        $error = 'Please try again.';
    } else {
        $do = (string) ($_POST['do'] ?? '');
        $ip = security_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if ($do === 'save' || $do === 'resend') {
            $typed = $do === 'resend'
                ? (string) (email_address_state($pdo, $uid)['pending'] ?? '')
                : trim((string) ($_POST['email'] ?? ''));
            $r = email_capture_start($pdo, $uid, $typed, 'signin_prompt', $uid, ['ip' => $ip, 'user_agent' => $ua]);
            if (!empty($r['ok'])) {
                email_prompt_unskip($pdo, $uid);
                $back = auth_safe_next((string) ($_POST['back'] ?? ''));
                header('Location: ' . ($back ?? ($self . '&sent=1')));
                exit;
            }
            if ($do === 'resend') {
                $resendNote = (string) ($r['message'] ?? 'Try again in a minute.');
                $typed = '';
            } else {
                $error = (string) ($r['message'] ?? 'Please try again.');
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
$firstName = trim((string) ($_SESSION['first_name'] ?? ''));
$hidden = ui_csrf_field() . '<input type="hidden" name="next" value="' . ui_e($next) . '">';
$linkBtn = static fn (string $do, string $label): string => '<form method="post" action="/auth/add_email" class="inline">'
    . $hidden . '<input type="hidden" name="do" value="' . ui_e($do) . '">'
    . '<button type="submit" class="ui-link">' . ui_e($label) . '</button></form>';

ui_standalone_open($state['pending'] !== null && $error === null ? 'Check your inbox' : 'Add your email',
    ['footer' => '<a class="ui-link" href="/auth/logout">Sign out</a>']);

if ($state['pending'] !== null && $error === null): ?>
    <h1 class="ui-h2">Check your inbox</h1>
    <p class="text-ink-muted">Sent to <?= ui_e(email_mask($state['pending'])) ?>
        <?= ui_info('It can take a minute. Look in Spam or Promotions too. The link works for 7 days.', 'About the link') ?></p>
    <?php if ($resendNote !== null): ?>
        <?= ui_banner('info', ['title' => 'No new link yet', 'text' => $resendNote]) ?>
    <?php elseif (!empty($_GET['sent'])): ?>
        <?= ui_banner('success', ['title' => 'Link sent']) ?>
    <?php elseif ($prompt === 'required'): ?>
        <?= ui_banner('warning', ['title' => 'Confirm to continue',
            'info' => 'It has been more than a week. Send a new link, or use another email.']) ?>
    <?php endif; ?>
    <?php if ($prompt !== 'required'): ?>
        <?= ui_button('Continue to app', ['variant' => 'primary', 'href' => $next, 'block' => true]) ?>
    <?php endif; ?>
    <div class="flex items-center justify-center gap-2 text-sm"><?= $linkBtn('resend', 'Resend') ?><span aria-hidden="true">·</span><?= $linkBtn('change', 'Wrong email?') ?></div>
<?php else: ?>
    <?php if ($firstName !== ''): ?>
        <p class="text-ink-muted">Hi <?= ui_e($firstName) ?></p>
    <?php endif; ?>
    <h1 class="ui-h2">Add your email</h1>
    <p class="text-ink-muted">For password resets and sign-in.
        <?= ui_info('Use your own address, like Gmail or Yahoo, not a church one. Church messages come to it too; every email has a one-tap unsubscribe.', 'About your email') ?></p>
    <?php if ($error !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $error]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/add_email" class="ui-form" data-ui-validate novalidate>
        <?= $hidden ?>
        <input type="hidden" name="do" value="save">
        <?= ui_field('email', [
            'name' => 'email',
            'label' => 'Email',
            'value' => $typed !== '' ? $typed : (string) ($state['on_file'] ?? ''),
            'required' => true,
            'autocomplete' => 'email',
            'inputmode' => 'email',
            'info' => $state['on_file'] !== null && $typed === '' ? 'The email we have on file. Change it if it is not yours.' : null,
        ]) ?>
        <?= ui_button('Send link', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
    <form method="post" action="/auth/add_email" class="text-center">
        <?= $hidden ?>
        <input type="hidden" name="do" value="skip">
        <button type="submit" class="ui-link">I don't have an email</button>
        <?= ui_info('We will ask again in 3 months. A church worker can add one for you.', 'No email') ?>
    </form>
<?php
endif;
ui_standalone_close();
