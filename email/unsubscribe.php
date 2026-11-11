<?php
// /email/unsubscribe.php
//
// Stop church emails (docs/EMAIL.md). Two ways in, both keyed by the email's
// own `ref` (22 random characters, one per email sent; the only thing
// trusted):
//
//   POST with body "List-Unsubscribe=One-Click"  RFC 8058, sent by the mail app
//       itself (Gmail, Apple Mail…) from the List-Unsubscribe header: no
//       cookies, no CSRF token (exempt in includes/csrf.php). Unsubscribes at
//       once and answers in plain text.
//   GET  → the page: one button. Its POST (and "Undo") check the CSRF token.
//
// Only church news stops; account mail (confirm and reset links) still comes.
// email/.htaccess keeps Referrer-Policy same-origin, never no-referrer (#120).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

$ref = (string) ($_GET['r'] ?? $_POST['r'] ?? '');
$isPreview = $ref === 'preview'; // the footer link in an editor preview
$ref = preg_match('/^[A-Za-z0-9_-]{22}$/', $ref) ? $ref : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = function_exists('security_client_ip') ? security_client_ip() : null;
$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

// 1. RFC 8058 one-click, from the mail app.
if ($method === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if ($ref === '') {
        http_response_code(400);
        echo "Unknown link.\n";
        exit;
    }
    $r = email_unsubscribe($pdo, $ref, 'one_click', $ip, $ua);
    http_response_code(!empty($r['ok']) ? 200 : 404);
    echo !empty($r['ok']) ? "Unsubscribed.\n" : "Unknown link.\n";
    exit;
}

// 2. The page.
require_once __DIR__ . '/../includes/ui/standalone.php';

$done = null;
if ($method === 'POST' && $ref !== '') {
    if (!ui_standalone_csrf_ok()) {
        $done = 'stale';
    } elseif (($_POST['do'] ?? '') === 'undo') {
        $done = !empty(email_resubscribe($pdo, $ref, $ip, $ua)['ok']) ? 'resubscribed' : 'unknown';
    } else {
        $done = !empty(email_unsubscribe($pdo, $ref, 'unsubscribe_link', $ip, $ua)['ok']) ? 'unsubscribed' : 'unknown';
    }
}
$row = $ref !== '' ? email_outbox_by_ref($pdo, $ref) : null;
$state = $row !== null && $row['user_id'] !== null ? email_consent_state($pdo, (int) $row['user_id']) : null;
$address = $row !== null && $row['to_email'] !== 'erased' ? email_mask((string) $row['to_email']) : '';
$form = static fn (string $do, string $label, string $variant): string => '<form method="post" action="/email/unsubscribe.php">'
    . ui_csrf_field() . '<input type="hidden" name="r" value="' . ui_e($ref) . '">'
    . '<input type="hidden" name="do" value="' . ui_e($do) . '">'
    . ui_button($label, ['variant' => $variant, 'type' => 'submit', 'block' => true]) . '</form>';

ui_standalone_open('Unsubscribe');

if ($isPreview): ?>
    <?= ui_banner('info', ['title' => 'Preview link', 'text' => 'This is how the unsubscribe page looks.']) ?>
<?php elseif ($row === null || $done === 'unknown'): ?>
    <?= ui_banner('warning', ['title' => 'Unknown link', 'text' => 'Use the link in your latest email.']) ?>
<?php elseif ($done === 'stale'): ?>
    <?= ui_banner('warning', ['title' => 'Please try again', 'text' => 'Tap the link in your email again.']) ?>
<?php elseif ($done === 'unsubscribed' || ($done === null && $state === 'withdrawn')): ?>
    <h1 class="ui-h2">You're unsubscribed</h1>
    <p class="text-ink-muted">No more church emails to <?= ui_e($address) ?>
        <?= ui_info('Password reset and confirmation emails still come, because they are about your account.', 'What still comes') ?></p>
    <?= $form('undo', 'Undo', 'secondary') ?>
<?php elseif ($done === 'resubscribed'): ?>
    <?= ui_banner('success', ['title' => "You're back", 'text' => 'Church emails will come again.']) ?>
<?php else: ?>
    <h1 class="ui-h2">Stop church emails?</h1>
    <p class="text-ink-muted">To <?= ui_e($address) ?>
        <?= ui_info('News, reminders and messages from the pastoral team stop. Account emails, like password resets, still come.', 'What stops') ?></p>
    <?= $form('unsubscribe', 'Unsubscribe', 'primary') ?>
<?php endif;

ui_standalone_close();
