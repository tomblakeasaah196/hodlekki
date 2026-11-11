<?php
// /cron/mail_daily.php
/**
 * Mail, once a day at 06:45 Lagos (docs/MAIL.md, "Crons"):
 *     45 6 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/mail_daily.php >> /home/smartqaq/logs/hod-mail.log 2>&1
 *
 * Disk use and warnings, mailboxes gone from the server, the access diff (new passwords after someone lost access;
 * the "holder left" path for personal mailboxes), removals that are due, export downloads that ran out, pruning.
 * Each step is independent; the cPanel work it decides is queued and done by the next minute's run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail/bootstrap.php';

use HOD\Mail\Admin\Nightly;

if (!mail_ready()) {
    echo '[mail-daily] ', date('c'), " not ready (migration pending)\n";
    exit(0);
}

echo '[mail-daily] ', date('c'), ' ', json_encode(Nightly::run(), JSON_UNESCAPED_SLASHES), "\n";
