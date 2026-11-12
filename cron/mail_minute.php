<?php
// /cron/mail_minute.php
/**
 * Mail, every minute (docs/MAIL.md, "Crons"):
 *     * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/mail_minute.php >/dev/null 2>&1
 *
 *   1. admin jobs (cPanel through `uapi`: create, bring in, reset, suspend, remove, …), then the mailbox count
 *      when it is 5 minutes old or a job was queued;
 *   2. the outbox: messages due to leave (each takes the domain's hourly budget; HOD\Mail\Compose\OutboxSender);
 *   3. sync: every active mailbox, least recently synced first, within a 45 s budget.
 *
 * One run at a time (a database lock): an overlapping run exits at once. A run where a step fails writes one line
 * to ~/logs/hod-mail.log (HOD\Mail\Admin\CronLog; the cron line sends output to /dev/null). Flags:
 *     --verbose        print what each step did
 *     --account=<id>   sync only this mailbox (and every folder)
 *     --jobs-only      only the admin jobs
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail/bootstrap.php';

use HOD\Mail\Admin\CronLog;
use HOD\Mail\Admin\Jobs;
use HOD\Mail\Admin\Quota;
use HOD\Mail\Compose\OutboxSender;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\Sync\MailSync;

$args = array_slice($argv, 1);
$verbose = in_array('--verbose', $args, true);
$only = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--account=')) {
        $only = (int) substr($a, 10);
    }
}
$say = static function (string $what, $data) use ($verbose): void {
    if ($verbose) {
        echo '[mail] ', $what, ': ', json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
    }
};

if (!mail_ready()) {
    $say('not ready', 'the mail tables do not exist yet (db/migrate.php)');
    exit(0);
}
if (!Db::lock('hod-mail-minute')) {
    $say('busy', 'another run is still going');
    exit(0);
}

$started = microtime(true);
$problems = [];
try {
    try {
        $jobs = Jobs::run();
        $say('jobs', $jobs);
        if (!empty($jobs['failed'])) {
            $problems[] = "jobs: {$jobs['failed']} failed (Mailboxes, Activity)";
        }
    } catch (Throwable $e) {
        $problems[] = 'jobs: ' . $e->getMessage();
    }
    try {
        if (Quota::due()) {
            Quota::refresh();
            $say('quota', 'refreshed');
        }
    } catch (Throwable $e) {
        // also kept as the uapi status the Health tab shows
        $problems[] = 'quota: ' . $e->getMessage();
    }

    if (!in_array('--jobs-only', $args, true) && $only === null && mail_compose_ready()) {
        try {
            $say('outbox', OutboxSender::make()->due());
        } catch (Throwable $e) {
            $problems[] = 'outbox: ' . $e->getMessage();
        }
    }

    if (!in_array('--jobs-only', $args, true)) {
        $budget = (float) Config::get('sync.budget', 45);
        $accounts = $only ? [['id' => $only]] : MailSync::due();
        foreach ($accounts as $account) {
            $left = $budget - (microtime(true) - $started);
            if ($left < 3) {
                break;
            }
            try {
                $result = MailSync::make()->account((int) $account['id'], $left, $only !== null);
                $say('sync ' . $account['id'], $result);
                if (($result['status'] ?? '') === 'error') {
                    $problems[] = 'sync ' . $account['id'] . ': ' . ($result['error'] ?? 'failed');
                }
            } catch (Throwable $e) {
                $problems[] = 'sync ' . $account['id'] . ': ' . $e->getMessage();
            }
        }
    }
} finally {
    Db::release('hod-mail-minute');
    $logged = CronLog::problems('mail_minute', $problems);
    if ($logged !== null) {
        $say('logged', rtrim($logged));
    }
}
