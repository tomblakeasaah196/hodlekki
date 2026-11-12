<?php
// /cron/email_bounces.php
//
// The bounce reader: reads system@'s inbox (where delivery reports and spam
// complaints for everything the outbox sends come back), suppresses addresses
// that hard-bounce or complain, counts soft bounces, re-queues an email the
// host dropped for its hourly cap, and files the reports in INBOX.Bounces.
// The logic is includes/campaigns/bounces.php; guide: docs/EMAIL.md.
//
// cPanel → Cron Jobs, every 10 minutes (keep the output in a log):
//   */10 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/email_bounces.php >> /home/smartqaq/logs/hod-email.log 2>&1
//
//   php cron/email_bounces.php --dry-run      read and report, change nothing
//   php cron/email_bounces.php --limit=50     at most 50 messages this run
//
// It logs in as MAIL_USERNAME with MAIL_PASSWORD (the sending account, the same
// mailbox), on the mailbox module's IMAP client (includes/mail). One run at a
// time (a MySQL named lock).

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/mail/bootstrap.php';

$args = array_slice($argv ?? [], 1);
$dry = in_array('--dry-run', $args, true);
$limit = EMAIL_BOUNCES_MAX;
foreach ($args as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int) $m[1];
    }
}
$say = static function (string $line): void {
    echo '[' . campaigns_now()->format('Y-m-d H:i:s') . '] bounces: ' . $line . "\n";
};

if (!campaigns_ready($pdo)) {
    $say('the email tables are not there yet (run db/migrate.php)');
    exit(0);
}
$user = site_env('MAIL_USERNAME');
$pass = site_env('MAIL_PASSWORD');
if ($user === '' || $pass === '') {
    $say('MAIL_USERNAME / MAIL_PASSWORD are not set: nothing to read');
    exit(0);
}
if (!campaigns_lock($pdo, EMAIL_BOUNCES_LOCK, 0)) {
    $say('another run holds the lock');
    exit(0);
}

$imap = null;
$code = 0;
try {
    $imap = \HOD\Mail\MailServer::make()->connect($user, $pass);
    $n = email_bounces_run($pdo, $imap, ['dry' => $dry, 'limit' => $limit]);
    $line = ($dry ? 'DRY RUN, nothing changed. ' : '') . email_bounces_line($n);
    if (!$dry) {
        email_setting_set($pdo, 'bounces_last_at', campaigns_sql_dt(campaigns_now()));
        email_setting_set($pdo, 'bounces_last', campaigns_cut($line, 250));
    }
    $say($line);
} catch (Throwable $e) {
    $code = 1;
    error_log('[email] bounce reader failed: ' . $e->getMessage());
    $say('failed: ' . get_class($e) . ': ' . campaigns_cut($e->getMessage(), 200));
    try {
        email_setting_set($pdo, 'bounces_last_at', campaigns_sql_dt(campaigns_now()));
        email_setting_set($pdo, 'bounces_last', 'failed: ' . campaigns_cut($e->getMessage(), 200));
    } catch (Throwable $ignored) {
    }
} finally {
    if ($imap !== null) {
        try {
            $imap->logout();
        } catch (Throwable $ignored) {
        }
    }
    campaigns_unlock($pdo, EMAIL_BOUNCES_LOCK);
}
exit($code);
