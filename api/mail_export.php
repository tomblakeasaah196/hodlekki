<?php
// /api/mail_export.php

/**
 * Download one folder of a departed holder's mailbox as mbox, after BOTH approvals (docs/MAIL.md, "Export").
 * Only the person who asked, only while the request is ready (7 days). Streamed from the mail server, never stored
 * on this server; each download is logged (mail_export_events, security_audit_log).
 *
 *   GET ?request=<id>                 the folder list (JSON)
 *   GET ?request=<id>&folder=<path>   that folder as an .mbox file
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Admin\Exports;

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0 || !mail_ready()) {
    http_response_code($uid <= 0 ? 401 : 503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $uid <= 0 ? 'Sign in again to continue.' : 'Mail is being set up.']);
    exit;
}
$requestId = (int) ($_GET['request'] ?? 0);
$folder = isset($_GET['folder']) ? (string) $_GET['folder'] : null;
session_write_close();

try {
    if ($folder === null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['status' => 'success', 'data' => Exports::folders($uid, $requestId)]);
        exit;
    }
    if (!in_array($folder, Exports::folders($uid, $requestId), true)) {
        throw HOD\Mail\MailError::notFound('That folder is not in this mailbox.');
    }
    set_time_limit(280);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', 'export-' . $requestId . '-' . $folder) . '.mbox';
    header('Content-Type: application/mbox');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    Exports::stream($uid, $requestId, $folder, static function (string $chunk): void {
        echo $chunk;
        flush();
    });
} catch (Throwable $e) {
    if (headers_sent()) {
        error_log('Mail export stopped: ' . $e->getMessage());
        exit;
    }
    mail_json_error($e);
}
