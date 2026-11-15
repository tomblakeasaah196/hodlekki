<?php
// /tests/monitor/capture_test.php — the real handlers, in real child PHP processes.
//
// Each script below installs the capture exactly as includes/db.php does, then
// misbehaves. The child writes to a SQLite file through the monitor's own
// connection (MONITOR_DB_DSN, tests only) and to its own PHP error log; this
// test then reads both.

$base = $GLOBALS['mt_dir'] . '/capture';
@mkdir($base . '/cron', 0700, true);
@mkdir($base . '/bin', 0700, true);
$dbFile = $base . '/monitor.sqlite';
monitor_test_db($dbFile);
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$log = $base . '/php.log';

$boot = "<?php\n"
    . '$_ENV["MONITOR_DB_DSN"] = ' . var_export('sqlite:' . $dbFile, true) . ";\n"
    . '$_ENV["MONITOR_DATA_DIR"] = ' . var_export($base . '/data', true) . ";\n"
    . '$_ENV["MONITOR_LIVE_DIR"] = ' . var_export($base . '/live', true) . ";\n"
    . '$_ENV["MONITOR_SECRET"] = ' . var_export($_ENV['MONITOR_SECRET'], true) . ";\n"
    . '$_ENV["SITE_URL"] = "https://hodlekki.org";' . "\n"
    . 'require ' . var_export($root . '/includes/monitor/capture.php', true) . ";\n"
    . "hod_monitor_install();\n";

$run = function (string $file, string $body) use ($boot, $log): array {
    file_put_contents($file, $boot . $body);
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log="' . $log . '"', '-d', 'error_reporting=-1', $file],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out, $err];
};

// ---- A cron job that warns, is silenced, deprecates, then dies of an exception
[$code] = $run($base . '/cron/fake_job.php', <<<'PHP'
// A shutdown function registered after the monitor, like the push dispatcher's,
// must still see the crash through error_get_last().
register_shutdown_function(function () {
    $e = error_get_last();
    file_put_contents(__DIR__ . '/last_error.txt', ($e['type'] ?? 'none') . ' ' . substr((string) ($e['message'] ?? ''), 0, 25));
});
$x = [];
echo $x['missing'];
@file_get_contents(__DIR__ . '/does-not-exist.txt');
trigger_error('Old way of doing it', E_USER_DEPRECATED);
throw new RuntimeException('Job blew up for 08031234567');
PHP);
eq('an uncaught exception still ends the script with exit code 255, as PHP would', 255, $code);
eq('…and later shutdown functions still see it in error_get_last() (the push dispatcher relies on it)',
    E_ERROR . ' Uncaught RuntimeException', (string) @file_get_contents($base . '/cron/last_error.txt'));

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$groups = $db->query('SELECT kind, level, source, title, exception_class, file FROM monitor_error_groups ORDER BY id')->fetchAll();
$titles = array_column($groups, 'title');
ok('the warning was recorded', in_array('Undefined array key "missing"', $titles, true), json_encode($titles));
ok('the deprecation was recorded', in_array('Old way of doing it', $titles, true));
ok('the @-silenced warning was not', count(array_filter($titles, fn ($t) => str_contains($t, 'does-not-exist'))) === 0);
$ex = array_values(array_filter($groups, fn ($g) => $g['kind'] === 'exception'))[0] ?? [];
eq('the exception was recorded as fatal', 'fatal', $ex['level'] ?? null);
eq('…with its class', 'RuntimeException', $ex['exception_class'] ?? null);
eq('…from a cron job', 'cron', $ex['source'] ?? null);
eq('…with the phone number taken out', 'Job blew up for <phone>', $ex['title'] ?? null);

$logText = (string) @file_get_contents($log);
has('the server log gets the monitor\'s marked line instead of PHP\'s', $logText, 'HODMON [');
lacks('…and not PHP\'s own line for the same warning (no double counting)', $logText, 'PHP Warning:  Undefined array key');
$ours = implode("\n", preg_grep('/HODMON \[/', explode("\n", $logText)) ?: []);
has('the monitor\'s own lines carry its reference codes', $ours, 'warning: Undefined array key "missing"');
lacks('…and are redacted', $ours, '08031234567');
has('the uncaught exception is logged by PHP itself, exactly as before', $logText, 'PHP Fatal error:  Uncaught RuntimeException: Job blew up');

$beat = json_decode((string) @file_get_contents($base . '/data/beats/fake_job.json'), true) ?: [];
eq('the cron heartbeat counted the run', 1, $beat['runs'] ?? null);
eq('…as failed', false, $beat['ok'] ?? null);
has('…with what stopped it', (string) ($beat['last_error'] ?? ''), 'RuntimeException');

// ---- A cron job that runs cleanly
[$code] = $run($base . '/cron/good_job.php', "echo 'fine';\n");
eq('a clean job exits 0', 0, $code);
$beat = json_decode((string) @file_get_contents($base . '/data/beats/good_job.json'), true) ?: [];
ok('…and its heartbeat says it succeeded', ($beat['ok'] ?? null) === true && isset($beat['started_at'], $beat['finished_at']));

// ---- Code reporting what it caught itself
[$code, $out] = $run($base . '/bin/other.php', <<<'PHP'
$a = hod_monitor_note('error', 'BulkSMS refused the token', ['campaign' => 12]);
$b = hod_monitor_report(new LogicException('caught one'));
echo $a . ' ' . $b;
PHP);
ok('hod_monitor_note and hod_monitor_report hand back reference codes', (bool) preg_match('/^E[A-Z2-9]{7} E[A-Z2-9]{7}$/', trim($out)), $out);
eq('a script that reports and carries on exits normally', 0, $code);
ok('…and is not a cron job: no heartbeat', !is_file($base . '/data/beats/other.json'));
$n = $db->query("SELECT COUNT(*) FROM monitor_error_groups WHERE (title = 'BulkSMS refused the token' AND kind = 'log') OR (exception_class = 'LogicException' AND level = 'error')")->fetchColumn();
eq('both are recorded', 2, (int) $n);

// ---- A real engine fatal (out of memory): PHP logs it itself, the shutdown records it
[$code] = $run($base . '/bin/hungry.php', "ini_set('memory_limit', '16M');\n\$s = str_repeat('x', 64 * 1048576);\n");
eq('out of memory still ends the script with 255', 255, $code);
$fatal = $db->query("SELECT * FROM monitor_error_groups WHERE title LIKE 'Allowed memory size%'")->fetch();
ok('the out-of-memory fatal was recorded at shutdown', $fatal !== false);
has('PHP wrote its own line for it', (string) @file_get_contents($log), 'PHP Fatal error:  Allowed memory size');

// ---- The log reader skips everything the handlers already recorded
$GLOBALS['monitor_test_now'] = time();
$before = (int) $db->query('SELECT SUM(occurrences) FROM monitor_error_groups')->fetchColumn();
$db->exec("DELETE FROM monitor_state");   // first sight of the log: read it all (it is small)
$scan = monitor_log_scan_file($db, $log);
$after = (int) $db->query('SELECT SUM(occurrences) FROM monitor_error_groups')->fetchColumn();
eq('reading the same log afterwards records nothing twice', $before, $after);
ok('…it saw the entries and skipped them', $scan['entries'] > 0 && $scan['recorded'] === 0, json_encode($scan));

// ---- Warnings raised after the monitor's shutdown work are not lost (review, 11 Nov)
@unlink($log);
[$code] = $run($base . '/bin/late.php', <<<'PHP'
register_shutdown_function(function () { $a = []; echo $a['late_shutdown']; });
class Leaves { public function __destruct() { $a = []; echo $a['in_destructor']; } }
$keep = new Leaves();
PHP);
eq('a script with late warnings still ends normally', 0, $code);
ok('a warning from a later shutdown function is recorded', (bool) $db->query("SELECT 1 FROM monitor_error_groups WHERE title = 'Undefined array key \"late_shutdown\"'")->fetchColumn());
has('a warning after the write is left to PHP\'s own log, where the log reader finds it', (string) @file_get_contents($log), 'PHP Warning:  Undefined array key "in_destructor"');

// ---- Problem #97: an endpoint that released its session early was recorded as "guest"
$savedMon = $GLOBALS['hod_monitor'] ?? null;
$savedServer = $_SERVER;
$GLOBALS['hod_monitor'] = ['cli' => false, 'cron' => null];
$_SERVER['REQUEST_URI'] = '/api/integrations_api.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['user_id' => 1, 'active_role' => 'Super_Admin'];   // as left by session_write_close()
$asClosed = hod_monitor_request();
$_SESSION = [];                                                  // as left by a revoked session
$asRevoked = hod_monitor_request();
unset($_SESSION);
$asNone = hod_monitor_request();
$GLOBALS['hod_monitor'] = $savedMon;
$_SERVER = $savedServer;
eq('a session closed early still names the role', ['Super_Admin', 1, 'api'], [$asClosed['role'], $asClosed['user_id'], $asClosed['source']]);
eq('…an emptied (revoked) or absent session is a guest', ['guest', null, 'guest', null],
    [$asRevoked['role'], $asRevoked['user_id'], $asNone['role'], $asNone['user_id']]);
