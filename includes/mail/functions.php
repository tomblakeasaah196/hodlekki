<?php
// /includes/mail/functions.php

/**
 * The module's procedural front, loaded by includes/mail/bootstrap.php.
 *
 *   mail_ready()                          are the tables there yet? (the tree reaches production before migrations)
 *   mail_can($action, $mailbox, $userId)  THE access decision (HOD\Mail\MailAccess::decide)
 *   mail_json_error(Throwable)            the one way endpoints turn an engine error into JSON
 */

use HOD\Mail\Db;
use HOD\Mail\Imap\ImapException;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailSendException;

if (!function_exists('mail_ready')) {
    function mail_ready(bool $fresh = false): bool
    {
        static $ready = null;
        if ($ready !== null && !$fresh) {
            return $ready;
        }
        try {
            $ready = (int) Db::value(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN ('mail_accounts', 'mail_admin_jobs', 'mail_messages')"
            ) === 3;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('mail_compose_ready')) {
    /**
     * Writing (PR 3) has its tables (migration 20261203090000_mail_compose). The code reaches the server before
     * the migration runs: until then the composer is not drawn, so no Reply button leads nowhere.
     */
    function mail_compose_ready(bool $fresh = false): bool
    {
        static $ready = null;
        if ($ready !== null && !$fresh) {
            return $ready;
        }
        try {
            $ready = (int) Db::value(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN ('mail_outbox', 'mail_signatures', 'mail_compose_files')"
            ) === 3;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('mail_team_ready')) {
    /** Teamwork, alerts and AI (PR 4) have their tables (migration 20261205090000_mail_team_alerts) */
    function mail_team_ready(bool $fresh = false): bool
    {
        static $ready = null;
        if ($ready !== null && !$fresh) {
            return $ready;
        }
        try {
            $ready = (int) Db::value(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN ('mail_user_prefs', 'mail_alert_log')"
            ) === 2;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('mail_can')) {
    /**
     * May this person (default: the signed-in one) do $action? Module abilities ('mail.admin', …) need no mailbox;
     * mailbox actions ('read', 'send', 'organise', 'assign', 'manage') need its row.
     */
    function mail_can(string $action, ?array $mailbox = null, ?int $userId = null): bool
    {
        $userId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if (!mail_ready()) {
            return false;
        }

        return MailAccess::decide($userId, $action, $mailbox);
    }
}

if (!function_exists('mail_json_error')) {
    /**
     * Send the JSON for an engine error and stop: a MailError's own words and status, a calm sentence (502) for a
     * mail server problem, and a generic one (500) for anything else, whose detail goes to the error log only.
     */
    function mail_json_error(Throwable $e): never
    {
        if ($e instanceof MailError) {
            $status = $e->status;
            $body = ['status' => 'error', 'message' => $e->getMessage()] + ($e->field ? ['field' => $e->field] : []);
        } elseif ($e instanceof ImapException || $e instanceof MailSendException) {
            error_log('Mail: server problem: ' . $e->getMessage());
            $status = 502;
            $body = ['status' => 'error', 'message' => 'The mail server did not accept that just now. Try again in a minute.'];
        } else {
            error_log('Mail: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $status = 500;
            $body = ['status' => 'error', 'message' => 'Something went wrong on our side. Try again; if it keeps happening, tell a mail admin.'];
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($body);
        exit;
    }
}

if (!function_exists('mail_nav_visible')) {
    /** Does Mail appear in this person's navigation? (a mailbox they work from, or the Mailboxes screen) */
    function mail_nav_visible(int $userId): bool
    {
        if ($userId <= 0 || !mail_ready()) {
            return false;
        }

        return MailAccess::decide($userId, 'admin')
            || MailAccess::decide($userId, 'export_admin')
            || MailAccess::decide($userId, 'export_pastoral')
            || MailAccess::accounts($userId) !== [];
    }
}

if (!function_exists('mail_nav_unread')) {
    /** The badge on Mail: unread Inbox conversations (HOD\Mail\MailNav, cached 30 s). Never throws. */
    function mail_nav_unread(int $userId): int
    {
        try {
            return \HOD\Mail\MailNav::unread($userId);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('mail_info')) {
    /**
     * The (i) that holds a screen's longer explanation (owner's short-copy rule: a short title, at most one short
     * line, everything else behind an (i)). Always placed where the component takes HTML (an `actions` slot or
     * raw markup), never inside an escaped `text`.
     *
     * The foundation's ui_info() (docs/UI.md, "Writing: short copy"); nothing where it is not loaded (CLI).
     */
    function mail_info(string $text): string
    {
        return function_exists('ui_info') ? ui_info($text) : '';
    }
}
