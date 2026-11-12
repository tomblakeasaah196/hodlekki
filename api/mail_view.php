<?php
// /api/mail_view.php

/**
 * The inside of the reader's frame (docs/MAIL.md §7c): one HTML message, made safe by HtmlSanitizer, as its own
 * document. It is a separate URL because a srcdoc frame would inherit the Mail page's strict CSP, which (rightly)
 * refuses a sender's inline styles. This document gets its own policy instead: no scripts at all, pictures only
 * from the ERP (cid:) and data: until the person allows them, no forms, no base, framed only by the ERP.
 *
 *   ?m=<message id>[&images=1]                 a stored message
 *   ?folder=<folder id>&uid=<uid>[&images=1]   a message older than 12 months, read live (not kept)
 *
 * Reading never marks a message read here (that is api/mail_api.php's POST). Anything else answers 404.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\MailAccess;
use HOD\Mail\Read\Reader;
use HOD\Mail\Store;

function mail_view_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    mail_view_fail(401, 'Sign in again to see this message.');
}
// Reading a body from the mail server can take a moment: don't hold the person's other requests
session_write_close();
if (!mail_ready()) {
    mail_view_fail(404, 'Not found.');
}

$images = !empty($_GET['images']);
$body = null;

try {
    if (isset($_GET['m'])) {
        $m = Store::message((int) $_GET['m']);
        if (!$m || !MailAccess::canMessage($uid, $m, 'read')) {
            mail_view_fail(404, 'Not found.');
        }
        $body = Reader::body($uid, $m, $images);
    } elseif (isset($_GET['folder'], $_GET['uid'])) {
        $folder = Store::folder((int) $_GET['folder']);
        $account = $folder ? Store::account((int) $folder['account_id']) : null;
        if (!$account || !MailAccess::can($uid, $account, 'read')) {
            mail_view_fail(404, 'Not found.');
        }
        $item = Reader::live($uid, $folder, (int) $_GET['uid'], $images, Store::folderLabel($folder));
        $body = $item['body'] ?? null;
        $images = (bool) ($item['remote'] ?? $images);
    }
} catch (Throwable $e) {
    error_log('Mail: view: ' . $e->getMessage());
    mail_view_fail(502, 'The mail server did not send this message. Try again in a minute.');
}

if (!$body || $body['kind'] !== 'html') {
    mail_view_fail(404, 'Not found.');
}

$img = "'self' data:" . ($images ? ' https: http:' : '');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; img-src {$img}; style-src 'unsafe-inline'; font-src data:" . ($images ? ' https:' : '')
    . "; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox");
echo $body['doc'];
