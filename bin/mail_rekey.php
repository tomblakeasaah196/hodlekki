<?php
// /bin/mail_rekey.php

/**
 * Move every stored mailbox password to the current MAIL_VAULT_KEY (docs/MAIL.md, "Vault").
 *
 *   1. Put the new key in .env as MAIL_VAULT_KEY and the old one as MAIL_VAULT_KEY_PREVIOUS.
 *   2. /usr/local/bin/ea-php83 bin/mail_rekey.php          re-encrypts what the old key can read
 *   3. Remove MAIL_VAULT_KEY_PREVIOUS.
 *
 * The old key is lost?  bin/mail_rekey.php --reset-all     queues a new password for every ERP mailbox (the minute
 * cron sets them through `uapi`); nobody has to type anything. Prints counts only, never a password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/mail/bootstrap.php';

use HOD\Mail\Admin\Jobs;
use HOD\Mail\Db;
use HOD\Mail\Store;
use HOD\Mail\Vault;

if (!mail_ready() || !Vault::ready()) {
    fwrite(STDERR, "Not ready: the mail tables or MAIL_VAULT_KEY are missing.\n");
    exit(1);
}

if (in_array('--reset-all', $argv, true)) {
    $n = 0;
    foreach (Store::accounts("managed = 1 AND status IN ('active', 'needs_reset', 'suspended')") as $a) {
        Jobs::queue('reset', $a, ['reason' => 'rekey'], null);
        $n++;
    }
    echo "Queued a new password for {$n} mailbox(es). The minute cron sets them.\n";
    exit(0);
}

$moved = $current = $unreadable = 0;
foreach (Db::all('SELECT id, email, secret, secret_pending FROM mail_accounts') as $row) {
    $fill = [];
    foreach (['secret', 'secret_pending'] as $col) {
        if ($row[$col] === null || $row[$col] === '') {
            continue;
        }
        if (Vault::isCurrent($row[$col])) {
            $current++;
            continue;
        }
        $plain = Vault::decrypt($row[$col], (int) $row['id'], $row['email']);
        if ($plain === null) {
            $unreadable++;
            continue;
        }
        $fill[$col] = Vault::encrypt($plain, (int) $row['id'], $row['email']);
        $moved++;
    }
    if ($fill) {
        Db::update('mail_accounts', $fill, (int) $row['id']);
    }
}
echo "Re-encrypted {$moved}, already current {$current}, unreadable {$unreadable}.\n";
if ($unreadable) {
    echo "Unreadable ones need a new password: run with --reset-all, or Reset password on the Mailboxes screen.\n";
}
