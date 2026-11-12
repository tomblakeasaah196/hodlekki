<?php
// /cron/trainings_daily.php
//
// Trainings, once a day (06:30): "keep enrolling newcomers". Every Assign
// press that asked for it, on a published training that has not ended, enrols
// the people its groups cover today and did not before (a new 1st Timer, a
// worker who joined a department), with the press's due period from today.
// The Academy page runs the same sweep when it opens, so this is the safety
// net. Exams and live-class reminders arrive with later PRs (TRAININGS-PLAN.md).
//
//   30 6 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/trainings_daily.php >/dev/null 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/trainings/bootstrap.php';

if (!trainings_ready($pdo)) {
    echo "trainings: tables not there yet (db/migrate.php), nothing to do\n";
    exit(0);
}
try {
    $n = trainings_sync_newcomers($pdo);
    echo "trainings: {$n} newcomer(s) enrolled\n";
    // PR 3: attendance of the live classes on Meet that ended (the Academy's Meet page can also bring it in at once)
    if (function_exists('training_live_daily')) {
        echo 'trainings: attendance read for ' . training_live_daily($pdo) . " class(es)\n";
    }
} catch (Throwable $e) {
    error_log('[trainings] daily run failed: ' . $e->getMessage());
    echo "trainings: failed, see the error log\n";
    exit(1);
}
