<?php
// /api/mail_admin_api.php

/**
 * The Mailboxes screen's JSON (docs/MAIL.md, "Mailboxes screen"). Every decision goes through mail_can() /
 * HOD\Mail\MailAccess; cPanel work is only QUEUED here (the minute cron carries it out with `uapi`).
 *
 *   GET  job&id=          one queued action's state (the screen polls it)
 *   GET  grants&id=       a shared mailbox's grants and what the editor offers
 *   GET  people&q=        active people for "Issue a personal mailbox" (with whether they may hold one)
 *   POST create_shared | issue_personal | bring_in | act (suspend, resume, reset, set_quota, auto_reply_on,
 *        auto_reply_off, remove) | grants_save | reserved_save | export_request | export_approve | export_refuse
 *
 * Answers {status: 'success', data} or {status: 'error', message, field?} with the right HTTP status.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Admin\Addresses;
use HOD\Mail\Admin\Exports;
use HOD\Mail\Admin\Grants;
use HOD\Mail\Admin\Jobs;
use HOD\Mail\Admin\Lifecycle;
use HOD\Mail\Admin\Quota;
use HOD\Mail\Db;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;
use HOD\Mail\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in again to continue.']);
    exit;
}
if (!mail_ready()) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'Mail is being set up. Try again after the next update.']);
    exit;
}

$isAdmin = mail_can('admin');
$approver = mail_can('export_admin') || mail_can('export_pastoral');
if (!$isAdmin && !$approver) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The Mailboxes screen is open to mail admins only.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$ok = static function ($data = null): never {
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$account = static fn (string $key = 'id'): array => Store::account((int) ($_POST[$key] ?? $_GET[$key] ?? 0)) ?? throw MailError::notFound();
$adminOnly = static function () use ($isAdmin): void {
    if (!$isAdmin) {
        throw MailError::denied('Only mail admins can do this.');
    }
};

try {
    if ($method !== 'POST') {
        switch ($action) {
            case 'job':
                $job = Jobs::find((int) ($_GET['id'] ?? 0)) ?? throw MailError::notFound();
                $ok(['id' => $job['id'], 'status' => $job['status'], 'error' => $job['error'], 'label' => $job['label']]);
                // no break: $ok exits
            case 'grants':
                $adminOnly();
                $a = $account();
                $ok(['grants' => Grants::list((int) $a['id']), 'options' => Grants::options()]);
                // no break
            case 'people':
                $adminOnly();
                $q = trim((string) ($_GET['q'] ?? ''));
                if (mb_strlen($q) < 2) {
                    $ok([]);
                }
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
                $rows = Db::all(
                    "SELECT id, first_name, last_name FROM users
                      WHERE COALESCE(account_status, 'active') = 'active'
                        AND (CONCAT(first_name, ' ', last_name) LIKE ? ESCAPE '!' OR last_name LIKE ? ESCAPE '!')
                   ORDER BY first_name, last_name LIMIT 15",
                    [$like, $like],
                );
                $ok(array_map(static fn ($r) => [
                    'id' => (int) $r['id'],
                    'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                    'eligible' => mail_can('hold_personal', null, (int) $r['id']),
                    'suggested' => Addresses::suggest((string) $r['first_name'], (string) $r['last_name']) . '@' . Addresses::domain(),
                ], $rows));
                // no break
        }
        throw MailError::notFound('Unknown request.');
    }

    csrf_require();

    switch ($action) {
        case 'create_shared':
            $adminOnly();
            $r = Lifecycle::requestShared($uid, $_POST);
            $ok(['job_id' => $r['job_id'], 'email' => $r['account']['email']]);
            // no break
        case 'issue_personal':
            $adminOnly();
            $r = Lifecycle::requestPersonal($uid, (int) ($_POST['user_id'] ?? 0), (string) ($_POST['local_part'] ?? ''));
            $ok(['job_id' => $r['job_id'], 'email' => $r['account']['email']]);
            // no break
        case 'bring_in':
            $adminOnly();
            $r = Lifecycle::requestBringIn($uid, $_POST);
            $ok(['job_id' => $r['job_id'], 'email' => $r['account']['email']]);
            // no break
        case 'act':
            $adminOnly();
            $ok(['job_id' => Lifecycle::request($uid, (string) ($_POST['what'] ?? ''), $account(), $_POST)]);
            // no break
        case 'grants_save':
            $adminOnly();
            $grants = json_decode((string) ($_POST['grants'] ?? '[]'), true);
            Grants::set($uid, $account(), is_array($grants) ? $grants : []);
            $ok();
            // no break
        case 'reserved_save':
            $adminOnly();
            $n = (int) ($_POST['reserved_personal'] ?? -1);
            if ($n < 0 || $n > (int) HOD\Mail\Config::get('domain_quota', 15)) {
                throw MailError::field('reserved_personal', 'Choose a number between 0 and the domain\'s quota.');
            }
            MailSettings::set('quota.reserved_personal', $n, $uid);
            HOD\Mail\Admin\Audit::log('mail_reserved_personal', (string) $n, $uid);
            $ok(Quota::snapshot());
            // no break
        case 'ai_plan':
            // The owner confirmed (or withdrew) the paid Gemini plan; a mail admin records it (docs/MAIL.md §7e)
            $adminOnly();
            HOD\Mail\Ai\Ai::setPlan($uid, ($_POST['confirmed'] ?? '') === '1');
            HOD\Mail\Admin\Audit::log('mail_ai_plan', ($_POST['confirmed'] ?? '') === '1' ? 'confirmed' : 'withdrawn', $uid);
            $ok(['plan' => HOD\Mail\Ai\Ai::plan()]);
            // no break
        case 'ai_mailbox':
            $adminOnly();
            $box = $account();
            HOD\Mail\Ai\Ai::setMailbox($uid, $box, ($_POST['on'] ?? '') === '1');
            HOD\Mail\Admin\Audit::log('mail_ai_mailbox', $box['email'] . ': ' . (($_POST['on'] ?? '') === '1' ? 'on' : 'off'), $uid);
            $ok();
            // no break
        case 'export_request':
            $ok(['id' => Exports::request($uid, (int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? ''))]);
            // no break
        case 'export_approve':
            $ok(Exports::approve($uid, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['side'] ?? '')));
            // no break
        case 'export_refuse':
            Exports::refuse($uid, (int) ($_POST['request_id'] ?? 0));
            $ok();
            // no break
    }
    throw MailError::notFound('Unknown action.');
} catch (Throwable $e) {
    mail_json_error($e);
}
