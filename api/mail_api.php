<?php
// /api/mail_api.php

/**
 * Mail for everyone who works from a mailbox (docs/MAIL.md). PR 1 answers two things; reading (PR 2), writing
 * (PR 3) and teamwork (PR 4) add their own actions here.
 *
 *   GET  nav                 {unread}: the badge on Mail in both shells (cached 30 s per session)
 *   POST refresh&box=<id>    read the server now for that mailbox (any level), at most every 20 seconds
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Clock;
use HOD\Mail\MailError;
use HOD\Mail\MailNav;
use HOD\Mail\Store;
use HOD\Mail\Sync\MailSync;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in again to continue.']);
    exit;
}
if (!mail_ready()) {
    echo json_encode(['status' => 'success', 'data' => ['unread' => 0, 'ready' => false]]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));

try {
    if ($method !== 'POST' && $action === 'nav') {
        echo json_encode(['status' => 'success', 'data' => ['unread' => MailNav::unread($uid)]]);
        exit;
    }
    if ($method === 'POST' && $action === 'refresh') {
        csrf_require();
        $account = Store::account((int) ($_POST['box'] ?? 0)) ?? throw MailError::notFound();
        if (!mail_can('read', $account)) {
            throw MailError::denied();
        }
        $age = Clock::ageSeconds($account['last_synced_at']);
        // the session is released first: reading the server must not hold the person's other requests
        session_write_close();
        $result = ($age !== null && $age < 20) ? ['status' => 'fresh'] : MailSync::make()->account((int) $account['id'], 20.0);
        MailNav::forget();
        echo json_encode(['status' => 'success', 'data' => ['sync' => $result['status'], 'added' => $result['added'] ?? 0]]);
        exit;
    }
    throw MailError::notFound('Unknown request.');
} catch (Throwable $e) {
    mail_json_error($e);
}
