<?php
// /cron/permissions_daily.php
// Daily, 00:10 Lagos: seat and role end dates (design §5.7). Tells holders and
// their pastors 14 days before a seat or dated role ends, sends the year-end
// summary to the Resident Pastor and the Super Admins (14 and 3 days before
// the ministry year ends), then closes what has ended — with history and a
// message each time. Access itself already ends on the day without this job
// (can() checks the dates); only the messages and the tidy-up wait for it.
//
// Safe to run twice: reminded_at and the perm_config "yearend_*" markers stop
// repeats. --dry-run prints the counts and changes nothing.
//
// Crontab: 10 0 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/permissions_daily.php >/dev/null 2>&1

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions/expiry.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$report = perm_daily_run($pdo, null, $dryRun);

if ($report['skipped'] !== null) {
    echo 'Nothing to do: ' . $report['skipped'] . ".\n";
    exit(0);
}
echo ($dryRun ? '[dry run] ' : '')
    . "seat reminders {$report['seat_reminders']}, role reminders {$report['role_reminders']}, "
    . "year-end summaries {$report['summaries']}, seats closed {$report['seats_closed']}, "
    . "seats lapsed with the year {$report['seats_lapsed']}, roles ended {$report['roles_ended']}, "
    . "messages {$report['messages']}\n";
