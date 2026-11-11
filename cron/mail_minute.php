<?php
// /cron/mail_minute.php
/**
 * Mail, every minute (docs/MAIL.md, "Crons"):
 *     * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/mail_minute.php >/dev/null 2>&1
 *
 *   1. admin jobs (cPanel through `uapi`: create, bring in, reset, suspend, remove, …), then the mailbox count
 *      when it is 5 minutes old or a job was queued;
 *   2. (PR 3) the outbox: messages due to leave;
 *   3. sync: every active mailbox, least recently synced first, within a 45 s budget.
 *
 * One run at a time (a database lock): an overlapping run exits at once. Flags:
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

use HOD\Mail\Admin\Jobs;
use HOD\Mail\Admin\Quota;
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
try {
    try {
        $say('jobs', Jobs::run());
        if (Quota::due()) {
            Quota::refresh();
            $say('quota', 'refreshed');
        }
    } catch (Throwable $e) {
        error_log('Mail cron: jobs: ' . $e->getMessage());
    }

    if (!in_array('--jobs-only', $args, true)) {
        $budget = (float) Config::get('sync.budget', 45);
        $accounts = $only ? [['id' => $only]] : MailSync::due();
        foreach ($accounts as $account) {
            $left = $budget - (microtime(true) - $started);
            if ($left < 3) {
                break;
            }
            $say('sync ' . $account['id'], MailSync::make()->account((int) $account['id'], $left, $only !== null));
        }
    }
} finally {
    Db::release('hod-mail-minute');
}
