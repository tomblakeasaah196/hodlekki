<?php
// /api/mail_file.php

/**
 * Files from mail (docs/MAIL.md §7c): attachments to open or download, and the cid: pictures inside a message.
 * The ERP keeps no copy: each file is read from the mail server when asked for, through HOD\Mail\MailFetch,
 * which checks the person can read that mailbox.
 *
 *   ?a=<attachment id>[&view=1]                         an attachment of a stored message
 *   ?m=<message id>&cid=<Content-ID>                    a cid: picture of a stored message (pictures only)
 *   ?folder=<folder id>&uid=<uid>&section=<n>[&view=1]  a file of a message older than 12 months (read live)
 *
 * Only pictures (not SVG), PDF and plain text open in the browser, and only when their first bytes match what
 * they claim to be (the host's PHP has no fileinfo); everything else downloads as bytes
 * (application/octet-stream, nosniff), so a file someone mailed never runs as part of the ERP.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Imap\ImapException;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailFetch;
use HOD\Mail\Read\Reader;
use HOD\Mail\Store;

const MAIL_FILE_PICTURES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

function mail_file_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

/** Do the first bytes match the type the sender claimed? Text has no signature, but must not look like markup. */
function mail_file_looks_like(string $mime, string $bytes): bool
{
    return match ($mime) {
        'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
        'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
        'image/gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
        'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
        'application/pdf' => str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-'),
        'text/plain' => !preg_match('/^\s*<(?:!doctype|html|script|svg|\?xml)/i', substr($bytes, 0, 512)),
        default => false,
    };
}

/** @param array{filename: string, mime: string, content: string} $file */
function mail_file_send(array $file, bool $view, string $cache = 'private, no-store'): never
{
    $mime = strtolower(trim((string) $file['mime']));
    $inline = $view && in_array($mime, Reader::VIEWABLE, true) && mail_file_looks_like($mime, $file['content']);
    $name = trim(str_replace(["\r", "\n", '/', '\\', "\0"], ' ', (string) $file['filename'])) ?: 'attachment';
    $fallback = preg_replace('/[^\x20-\x7E]|[%"\\\\;]/', '_', $name) ?: 'attachment';
    header('Content-Type: ' . ($inline ? ($mime === 'text/plain' ? 'text/plain; charset=utf-8' : $mime) : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . strlen($file['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cache);
    header('Referrer-Policy: no-referrer');
    if ($mime !== 'application/pdf' || !$inline) {
        // A picture or text opened on its own: nothing in it may run (PDF keeps the browser's own viewer)
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    }
    echo $file['content'];
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    mail_file_fail(401, 'Sign in again to open this file.');
}
// Reading a file from the mail server can take a few seconds: don't hold the person's other requests
session_write_close();
if (!mail_ready()) {
    mail_file_fail(404, 'Not found.');
}

try {
    $view = !empty($_GET['view']);
    $fetch = MailFetch::make();

    if (isset($_GET['a'])) {
        mail_file_send($fetch->attachment($uid, (int) $_GET['a']), $view);
    }
    if (isset($_GET['m'], $_GET['cid'])) {
        $message = Store::message((int) $_GET['m']) ?? throw MailError::notFound();
        if (!MailAccess::canMessage($uid, $message, 'read')) {
            throw MailError::notFound();
        }
        $file = $fetch->inline($uid, $message['id'], (string) $_GET['cid']);
        $mime = strtolower((string) ($file['mime'] ?? ''));
        if (!$file || !in_array($mime, MAIL_FILE_PICTURES, true) || !mail_file_looks_like($mime, $file['content'])) {
            throw MailError::notFound('That picture is no longer in the message.');
        }
        mail_file_send($file, true, 'private, max-age=3600');
    }
    if (isset($_GET['folder'], $_GET['uid'], $_GET['section'])) {
        $section = (string) $_GET['section'];
        if (!preg_match('/^\d+(\.\d+)*$/', $section)) {
            throw MailError::notFound();
        }
        mail_file_send($fetch->liveAttachment($uid, (int) $_GET['folder'], (int) $_GET['uid'], $section), $view);
    }
    throw MailError::notFound();
} catch (MailError $e) {
    // a mailbox the person can't open looks the same as one that doesn't exist
    mail_file_fail($e->status === 403 ? 404 : $e->status, $e->status === 403 ? 'Not found.' : $e->getMessage());
} catch (ImapException $e) {
    error_log('Mail: file: server: ' . $e->getMessage());
    mail_file_fail(502, 'The mail server did not send this file. Go back and try again in a minute.');
} catch (Throwable $e) {
    error_log('Mail: file: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mail_file_fail(500, 'Something went wrong on our side. Try again; if it keeps happening, tell a mail admin.');
}
