<?php
// /includes/mail/mail_config.php

/**
 * Mail settings, read through HOD\Mail\Config::get('sync.months'). Values a
 * person might need to change on the server come from .env (site_env());
 * secrets never live here. Tests override with Config::set().
 *
 * (Not "config.php": Windows treats config.php and Config.php as one file.)
 */

$env = static function (string $key, string $default): string {
    $value = function_exists('site_env') ? site_env($key) : '';

    return $value !== '' ? $value : $default;
};
$flag = static fn (string $key, bool $default): bool => filter_var($env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);

return [

    // The only domain the ERP makes or touches mailboxes in. The hosting account also
    // holds lpc.cm, nfelia.com and other sites: every cPanel call is locked to this one.
    'domain' => strtolower($env('MAIL_DOMAIN', 'hodlekki.org')),

    // Mailboxes this domain may hold (owner, 11 Nov 2026: 15 of the account's 50).
    'domain_quota' => (int) $env('MAIL_DOMAIN_QUOTA', '15'),

    // The one mail server. Never taken from a request. Its own name, not mail.hodlekki.org:
    // ports 993 and 465 present the certificate for srv-web-ns9.newtoncorp.fr only, so a
    // connection to mail.hodlekki.org fails the name check ("Could not reach … (erreur 0)",
    // every minute in ~/logs/hod-mail.log until 12 Nov 2026: no mailbox ever synced).
    'server' => [
        'imap_host'       => $env('MAIL_IMAP_HOST', 'srv-web-ns9.newtoncorp.fr'),
        'imap_port'       => (int) $env('MAIL_IMAP_PORT', '993'),
        'imap_encryption' => 'ssl',
        'smtp_host'       => $env('MAIL_SMTP_HOST', 'srv-web-ns9.newtoncorp.fr'),
        'smtp_port'       => (int) $env('MAIL_SMTP_PORT', '465'),
        'smtp_encryption' => 'ssl',
    ],

    // Seconds to wait for the mail server
    'timeout' => 20,

    // Check the server's certificate. Off only for a local test server; never on live.
    'verify_tls' => $flag('MAIL_VERIFY_TLS', true),

    // The name SMTP greets with, and the domain of Message-IDs made here
    'local_domain' => strtolower($env('MAIL_DOMAIN', 'hodlekki.org')),

    'sync' => [
        'months'              => 12,      // copied into the ERP; older mail is read from the server
        'budget'              => 45,      // seconds of one cron run, shared by every mailbox
        'batch'               => 40,      // messages per IMAP FETCH
        'other_folders_every' => 5,       // minutes between checks of folders other than the Inbox
        'folders_every'       => 30,      // minutes between reads of the folder list
        'full_pass_every'     => 60,      // minutes: an unchanged folder is still checked fully this often
        'max_body_bytes'      => 1000000, // larger bodies are read when opened
        'notify_within_hours' => 48,      // older "new" mail never raises mail.received
        'retry_pause'         => 2,
    ],

    'thread_subject_days' => 30,

    // Largest attachment handed out in one piece (the web PHP has 128 MB of memory)
    'max_attachment_bytes' => 25 * 1024 * 1024,

    // Disk per mailbox in MB (lead, 11 Nov 2026) and the warning level
    'disk' => [
        'department'   => 2048,
        'church'       => 2048,
        'personal'     => 1024,
        'warn_percent' => 80,
    ],

    // cPanel through the server's own command (no API token). CLI only. 'binary' empty: the first of 'candidates'
    // this account can run (HOD\Mail\Admin\Uapi::binary; /usr/local/cpanel/bin/uapi fails under CageFS here).
    'uapi' => [
        'binary'  => $env('MAIL_UAPI_BINARY', ''),
        'candidates' => ['/usr/bin/uapi', '/usr/local/cpanel/bin/uapi'],
        'timeout' => 30,
    ],

    // A line per cron run that had problems (HOD\Mail\Admin\CronLog); empty: ~/logs/hod-mail.log
    'log' => [
        'file' => $env('MAIL_LOG_FILE', ''),
    ],

    'jobs' => [
        'per_run'       => 10,  // admin jobs per cron run
        'stuck_minutes' => 10,  // a job left running this long is checked and retried once
        'quota_every'   => 5,   // minutes between counts of the server's mailboxes
    ],

    // A personal mailbox whose holder leaves (lead, 11 Nov 2026)
    'leaving' => [
        'auto_reply_days'   => 30,
        'forward_to'        => 'info',  // local part on this domain
        'export_hold_days'  => 30,      // removal waits at most this long for an open export request
        'export_open_days'  => 7,       // downloads stay open this long once approved
    ],

    // Addresses nobody may be issued
    'reserved_local_parts' => [
        'abuse', 'admin', 'administrator', 'cpanel', 'dmarc', 'hostmaster', 'info', 'mailer-daemon',
        'no-reply', 'noreply', 'postmaster', 'root', 'support', 'system', 'webmaster', 'www',
    ],

    // On the server but never brought into the ERP, with what the Mailboxes screen says about them
    'outside' => [
        'system' => 'Sends the ERP\'s own email.',
        'dmarc'  => 'Receives DMARC reports.',
    ],
];
