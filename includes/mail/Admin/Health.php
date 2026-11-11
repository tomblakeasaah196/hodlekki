<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\Vault;

/**
 * What the Mailboxes screen's Health panel and bin/mail_doctor.php both show: checks that need no network and no
 * cPanel, run under whichever PHP asks (the web PHP and the CLI PHP differ on this host). Never prints a secret.
 *
 * @return list<array{ok: bool, label: string, detail: string}>
 */
final class Health
{
    public static function checks(): array
    {
        $out = [];
        $add = function (bool $ok, string $label, string $detail = '') use (&$out): void {
            $out[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
        };

        $add(mail_ready(true), 'Mail tables', mail_ready() ? 'present' : 'missing: db/migrate.php has not run yet');
        $add(Vault::ready(), 'MAIL_VAULT_KEY', Vault::ready() ? 'set (64 hex)' : 'missing or not 64 hex characters in .env');
        foreach (['openssl' => 'TLS and the vault', 'mbstring' => 'text', 'dom' => 'reading HTML mail', 'iconv' => 'old character sets', 'curl' => 'AI (PR 4)'] as $ext => $why) {
            $add(extension_loaded($ext), "PHP {$ext} (" . PHP_SAPI . ')', extension_loaded($ext) ? 'loaded' : "missing: needed for {$why}");
        }
        $add(true, 'PHP imap extension', extension_loaded('imap') ? 'loaded, unused (Mail has its own IMAP client)' : 'not loaded: as intended');
        if (!mail_ready()) {
            return $out;
        }
        $snap = Quota::snapshot();
        $add($snap['domain_used'] < $snap['domain_quota'], 'Mailboxes on ' . Addresses::domain(), "{$snap['domain_used']} of {$snap['domain_quota']}"
            . ($snap['account_max'] ? "; hosting account {$snap['account_used']} of {$snap['account_max']}" : '')
            . ($snap['read_at'] ? ' (read ' . $snap['read_at'] . ')' : ' (never read yet: the minute cron reads it)'));
        $lastSync = Db::value('SELECT MAX(last_synced_at) FROM mail_accounts');
        $age = Clock::ageSeconds($lastSync);
        $hasActive = (bool) Db::value("SELECT 1 FROM mail_accounts WHERE status = 'active' LIMIT 1");
        $add(!$hasActive || ($age !== null && $age < 300), 'Minute cron (sync)', $lastSync ? 'last sync ' . $lastSync : 'no mailbox synced yet');
        $stuck = (int) Db::value("SELECT COUNT(*) FROM mail_admin_jobs WHERE status = 'queued' AND created_at < ?", [Clock::sql(-300)]);
        $add($stuck === 0, 'Admin jobs', $stuck ? "{$stuck} waiting more than 5 minutes: is the minute cron running?" : 'none waiting');
        $add(true, 'Mailboxes needing attention', (string) Db::value("SELECT COUNT(*) FROM mail_accounts WHERE status IN ('needs_reset', 'failed')"));

        return $out;
    }
}
