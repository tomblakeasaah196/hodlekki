<?php
// /cron/automation_tick.php
/**
 * AUTOMATION — the engine's clock (every 5 minutes).
 *
 * Runs every due occasion of every switched-on rule (Embrace's Monday welcome
 * and Wednesday/Saturday reminders, the Assimilation and Charis absence checks),
 * then gives yesterday's deferred messages another chance. Each occasion runs
 * once however often this fires (automation_runs is unique per occasion), and
 * each person gets a step once (automation_sends is unique per person, step and
 * week/streak). Emails go to the outbox (cron/email_worker.php sends them);
 * texts go to SMS Studio's queue (cron/sms_queue_worker.php sends them).
 * Everything lives in includes/campaigns/automation.php.
 *
 * Crontab:
 *     0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/automation_tick.php >> /home/smartqaq/logs/hod-email.log 2>&1
 *
 *   --dry-run            list who would get what, by which channel, and why; write nothing
 *   --rule=ID            only this rule (with --dry-run it may be switched off)
 *   --at="Y-m-d H:i"     pretend it is this time (Lagos), e.g. --at="2026-11-16 10:05"
 *   --status             list the rules and their last runs
 *
 * Rules are created switched OFF the first time this runs; nothing is sent
 * until someone switches a rule on in its module's Automation tab.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
if (!ini_get('error_log')) ini_set('error_log', dirname(__DIR__) . '/includes/error_log');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms_functions.php';   // the SMS fallback uses SMS Studio's phone rules
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

$args = array_slice($argv ?? [], 1);
$opt = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};
$line = static fn (string $msg) => print('[' . date('Y-m-d H:i:s') . '] [automation] ' . $msg . "\n");

if (!campaigns_ready($pdo)) {
    $line('Automation tables are not there yet (db/migrate.php has not run). Nothing to do.');
    exit(0);
}

if (in_array('--status', $args, true)) {
    automation_ensure_defaults($pdo);
    foreach ($pdo->query('SELECT * FROM automation_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $line(sprintf('#%d %s/%s "%s": %s', $rule['id'], $rule['module'], $rule['kind'], $rule['name'],
            (int) $rule['is_enabled'] === 1 ? 'ON since ' . $rule['enabled_at'] : 'off'));
        $runs = $pdo->prepare('SELECT * FROM automation_runs WHERE rule_id = ? ORDER BY id DESC LIMIT 3');
        $runs->execute([(int) $rule['id']]);
        foreach ($runs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $line(sprintf('    %s %s: %d people, %d emailed, %d texted, %d alerted, %d skipped%s',
                $r['occasion_key'], $r['status'], $r['people'], $r['emailed'], $r['texted'], $r['alerted'], $r['skipped'],
                $r['detail'] ? ' — ' . $r['detail'] : ''));
        }
    }
    exit(0);
}

$at = null;
if (($raw = $opt('at')) !== null) {
    try {
        $at = new DateTimeImmutable($raw, new DateTimeZone(CAMPAIGNS_TZ));
    } catch (Throwable $e) {
        $line('Could not read --at="' . $raw . '".');
        exit(2);
    }
}
$dry = in_array('--dry-run', $args, true);

// Trainings reminders (includes/trainings/reminders.php) ride on this 5-minute beat: an hour before a
// live class or an exam opening, plus the day-before and due ones outside 21:00-06:30. Its own try, so
// a problem there never stops the automation below. No new cron line (TRAININGS-PLAN.md, PR 4a).
if (is_file(__DIR__ . '/../includes/trainings/bootstrap.php')) {
    require_once __DIR__ . '/../includes/trainings/bootstrap.php';
}
if (function_exists('trainings_reminders_run')) {
    try {
        $tr = trainings_reminders_run($pdo, array_filter(['now' => $at, 'dry_run' => $dry]));
        if ($tr['sent'] > 0) {
            $line('trainings reminders: ' . $tr['sent'] . ' sent');
        }
    } catch (Throwable $e) {
        error_log('[trainings] reminders: ' . $e->getMessage());
    }
}

// Our sermons (includes/our_sermons/feeds.php): each RSS feed Envision pasted is read every 15 minutes on this
// beat, and a new episode becomes a draft for Envision to check. Its own try, so a problem there never stops
// the automation below. No new cron line (OUR-SERMONS-PLAN.md, PR S1).
if (is_file(__DIR__ . '/../includes/our_sermons/bootstrap.php')) {
    require_once __DIR__ . '/../includes/our_sermons/bootstrap.php';
}
if (function_exists('our_sermons_feeds_run')) {
    try {
        $os = our_sermons_feeds_run($pdo, array_filter(['now' => $at, 'dry_run' => $dry]));
        if ($os['new'] > 0 || $os['linked'] > 0) {
            $line('our sermons: ' . $os['new'] . ' new draft(s), ' . $os['linked'] . ' completed from the feed');
        }
    } catch (Throwable $e) {
        error_log('[our_sermons] feeds: ' . $e->getMessage());
    }
}

try {
    $r = automation_tick($pdo, array_filter([
        'dry_run' => $dry,
        'rule_id' => $opt('rule') !== null ? (int) $opt('rule') : null,
        'now'     => $at,
    ], static fn ($v) => $v !== null && $v !== false));
    if ($r['locked']) {
        $line('Another run is still going. Exiting.');
        exit(0);
    }
    foreach ($r['runs'] as $run) {
        $c = $run['counts'];
        $line(sprintf('rule #%d (%s) %s: %s — %d people, %d emailed, %d texted, %d alerted, %d skipped, %d deferred',
            $run['rule_id'], $run['module'], $run['occasion'], $run['status'],
            $c['people'], $c['emailed'], $c['texted'], $c['alerted'], $c['skipped'], $c['deferred']));
        if ($dry) {
            foreach ($run['decisions'] as $d) {
                $line(sprintf('    #%d %s · %s · %s · %s%s', $d['user_id'], $d['name'], $d['step'], $d['channel'], $d['outcome'],
                    $d['detail'] ? ' (' . $d['detail'] . ')' : ''));
            }
        }
    }
    if (!empty($r['retried']['retried']) || !empty($r['retried']['expired'])) {
        $line("deferred messages: {$r['retried']['retried']} retried");
    }
    $line($r['message']);
} catch (Throwable $e) {
    $line('ERROR: ' . $e->getMessage());
    error_log('[automation] tick: ' . $e->getMessage());
    exit(1);
}
