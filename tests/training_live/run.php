<?php
// /tests/training_live/run.php
//
// Trainings PR 3, live classes on Meet (docs/INTEGRATIONS.md), on SQLite built from the REAL migrations (PR 1's
// core, the permission matrix, integration_settings and this PR's tables) with Google faked: the Meet link on a
// live item and the learner's page, the Meet following the builder's edits, Join class, attendance through the
// Matcher into training_progress, "Needs a look", the tutor's corrections surviving a later read, the daily run.
//
//   php tests/training_live/run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(getenv('CI') ? 1 : 0);
}
date_default_timezone_set('Africa/Lagos');
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['MAIL_FROM_ADDRESS'] = 'system@hodlekki.org';
$_ENV['MAIL_USERNAME'] = 'system@hodlekki.org';
$_ENV['SMS_VAULT_KEY'] = str_repeat('0', 64);
$_ENV['GOOGLE_OAUTH_CLIENT_ID'] = 'client.apps.googleusercontent.com';
$_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] = 'test-secret';
$root = dirname(__DIR__, 2);
require_once $root . '/includes/security_helpers.php';
require_once $root . '/includes/auth_helpers.php';
require_once $root . '/includes/ui/bootstrap.php';
require_once $root . '/includes/ui/components.php';
require_once $root . '/includes/csrf.php';
require_once $root . '/includes/sms_functions.php';
require_once $root . '/includes/campaigns/bootstrap.php';
require_once $root . '/tests/campaigns/lib.php';
require_once $root . '/tests/permissions/install.php';
require_once $root . '/includes/trainings/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Settings;
use HOD\Integrations\Vault;
use HOD\TrainingLive\Live;

$passed = 0;
$failed = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . ($cond || $detail === '' ? '' : "  ({$detail})") . "\n";
}
function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function section(string $name): void
{
    echo "\n-- {$name}\n";
}
/** A migration's tables, translated to SQLite (as tests/trainings/run.php does). */
function tl_schema(string $file): array
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/' . $file));
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
    $out = [];
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '' || ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line))) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY \w+ \((.+)\)$/', $line, $m)) {
                $cols[] = 'UNIQUE (' . $m[1] . ')';
                continue;
            }
            if (preg_match('/^KEY (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE INDEX {$m[1]} ON {$name} ({$m[2]})";
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = (string) preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(LONGTEXT|MEDIUMTEXT|DATETIME|DATE|TEXT)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        array_push($out, ...$indexes);
    }
    return $out;
}
function tl_as(int $uid): void
{
    $_SESSION = ['user_id' => $uid];
    perm_reset();
}
function act(PDO $pdo, int $uid, string $action, array $in = []): array
{
    tl_as($uid);
    $out = trainings_action($pdo, $action, $in, [], $uid);
    if (($out['status'] ?? '') === 'success') {
        training_live_after_action($pdo, $action, $in);   // as api/trainings_api.php does
    }
    return $out;
}
function at(string $lagos): void
{
    $GLOBALS['trainings_now'] = new DateTimeImmutable($lagos, new DateTimeZone('Africa/Lagos'));
}

// A church with the email schema, the matrix and people (as PR 1's suite)
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (array_merge(ct_platform_sql(), ct_schema_sql(), tl_schema('20261208090000_trainings_core.sql')) as $stmt) {
    $pdo->exec($stmt);
}
$pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor')");
$pdo->exec("INSERT INTO departments (id, name) VALUES (20, 'Media'), (30, 'HOD Academy')");
foreach ([[900, 'Rita', 'Worker'], [901, 'Ade', 'Worker'], [902, 'Bola', 'Worker'], [903, 'Chiamaka', 'Okoro'], [904, 'Dayo', 'Adeyemi'],
          [906, 'Femi', 'Balogun']] as [$id, $first, $last]) {
    $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, password_hash, account_status, created_at)
                   VALUES (?, ?, ?, 'Worker', 'Active', 'x', 'active', '2026-01-01 10:00:00')")->execute([$id, $first, $last]);
}
$pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (900, 2)');
perm_test_install($pdo);
$pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (901, 30, 1, 'HOD'), (902, 20, 1, 'Worker')");
perm_reset();
at('2026-12-01 10:00:00');
campaigns_set_clock(new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Africa/Lagos')));

section('Before this PR\'s migration nothing breaks');
ok('Live::ready() is false', !Live::ready($pdo));
foreach (array_merge(tl_schema('20261210090000_integrations_google.sql'), tl_schema('20261210100000_trainings_live.sql')) as $stmt) {
    $pdo->exec($stmt);
}
ok('after it, ready', Live::ready($pdo));
$live = (string) file_get_contents($root . '/db/migrations/20261210100000_trainings_live.sql');
ok('PR 3\'s slot, the _trainings_ marker, idempotent, no DROP', substr_count($live, 'CREATE TABLE IF NOT EXISTS') === 4 && stripos($live, 'DROP ') === false);
ok('every table keyed on training_items.id (item_id)', substr_count($live, 'item_id INT NOT NULL') === 4);
ok('one Meet and one attendance row per item and learner', str_contains($live, 'UNIQUE KEY uq_training_meetings_item (item_id)')
    && str_contains($live, 'UNIQUE KEY uq_training_attendance (item_id, user_id)'));

// Google: connected, a valid access token, every call answered by a fake
Settings::usePdo($pdo);
Vault::useKeys(str_repeat('ab', 32));
$calls = [];
$replies = [];
Google::fake(function (array $r) use (&$calls, &$replies): array {
    $calls[] = $r;
    foreach ($replies as [$match, $reply]) {
        if (str_contains($r['method'] . ' ' . $r['url'], $match)) {
            return is_callable($reply) ? $reply($r) : $reply;
        }
    }
    return ['status' => 500, 'body' => '{}'];
});
$json = static fn (array $a): array => ['status' => 200, 'body' => json_encode($a)];

section('A Meet link for a live class');
$tid = (int) act($pdo, 901, 'create', ['title' => 'Workers\' training', 'length' => 'one_day', 'starts_on' => '2026-12-05'])['id'];
$sid = (int) array_values(array_filter(trainings_outline($pdo, $tid), fn ($s) => (int) $s['id'] > 0))[0]['id'];
$itemIn = ['training_id' => $tid, 'type' => 'live', 'title' => 'Opening class', 'section_id' => $sid, 'date' => '2026-12-05', 'time' => '10:00',
    'minutes' => 120, 'live_mode' => 'online', 'present_minutes' => 30];
$itemId = (int) act($pdo, 901, 'item_save', $itemIn)['id'];
$inPerson = (int) act($pdo, 901, 'item_save', ['title' => 'Prayer walk', 'live_mode' => 'in_person', 'venue' => 'Main hall', 'time' => '13:00'] + $itemIn)['id'];
act($pdo, 901, 'publish', ['training_id' => $tid]);
act($pdo, 901, 'assign', ['training_id' => $tid, 'users' => [903, 904, 906]]);
eq('not connected: Google is named, nothing is made', 'Google is not connected yet.', Live::createMeet($pdo, $itemId, 901)['message'] ?? null);
Settings::setSecret(Google::REFRESH, '1//refresh');
Settings::set(Google::STATUS, 'connected');
Settings::set(Google::EMAIL, 'hodlekkichurch@gmail.com');
Settings::setSecret(Google::ACCESS, json_encode(['t' => 'ya29.ok', 'e' => time() + 3000]));
eq('an in-person class gets no Meet', 'Make the class online first (Edit item).', Live::createMeet($pdo, $inPerson, 901)['message'] ?? null);
$replies = [['POST https://www.googleapis.com/calendar/v3/calendars/primary/events', $json(['id' => 'ev1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
    'conferenceData' => ['conferenceId' => 'abc-defg-hij']])]];
$calls = [];
$r = Live::createMeet($pdo, $itemId, 901);
$sent = json_decode((string) $calls[0]['body'], true);
ok('Create Meet link: one events.insert', !empty($r['ok']) && count($calls) === 1, json_encode($r));
eq('…titled training: class, at the class\'s time and length in Lagos', ['Workers\' training: Opening class', '2026-12-05T10:00:00+01:00', '2026-12-05T12:00:00+01:00'],
    [$sent['summary'], $sent['start']['dateTime'], $sent['end']['dateTime']]);
$item = trainings_item_get($pdo, $itemId);
eq('…the link and event id go into the item\'s details', ['https://meet.google.com/abc-defg-hij', 'ev1'], [$item['details']['meet_url'] ?? null, $item['details']['meet_event_id'] ?? null]);
eq('…and the learner\'s page offers that link', 'https://meet.google.com/abc-defg-hij', trainings_learner_item($item)['details']['link']);
$calls = [];
ok('pressing it again makes nothing new', !empty(Live::createMeet($pdo, $itemId, 901)['ok']) && $calls === []);

section('The Meet follows the builder');
$calls = [];
$replies = [['PATCH https://www.googleapis.com/calendar/v3/calendars/primary/events/ev1', $json(['id' => 'ev1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])]];
act($pdo, 901, 'item_save', ['id' => $itemId, 'time' => '09:30', 'link' => ''] + $itemIn);
$sent = json_decode((string) ($calls[0]['body'] ?? '{}'), true);
eq('moving the class patches the event', ['PATCH', '2026-12-05T09:30:00+01:00', '2026-12-05T11:30:00+01:00'],
    [$calls[0]['method'] ?? null, $sent['start']['dateTime'] ?? null, $sent['end']['dateTime'] ?? null]);
ok('…and the link stays on the item through PR 1\'s edit', (trainings_item_get($pdo, $itemId)['details']['meet_url'] ?? '') === 'https://meet.google.com/abc-defg-hij');
$calls = [];
act($pdo, 901, 'item_save', ['id' => $itemId, 'time' => '09:30', 'link' => ''] + $itemIn);
ok('saving without a change sends nothing', $calls === []);
act($pdo, 901, 'item_save', ['id' => $itemId, 'time' => '10:00', 'link' => ''] + $itemIn);
$calls = [];
Settings::set(Google::STATUS, 'revoked');
act($pdo, 901, 'item_save', ['id' => $itemId, 'time' => '10:00', 'title' => 'Opening class (new name)', 'link' => ''] + $itemIn);
ok('Google refusing never fails the builder; the reason waits on the Meet page', $calls === [] && str_contains((string) Live::meeting($pdo, $itemId)['last_error'], 'withdrawn'));
Settings::set(Google::STATUS, 'connected');
$replies[] = ['PATCH', $json(['id' => 'ev1'])];
act($pdo, 901, 'item_save', ['id' => $itemId, 'time' => '10:00', 'link' => ''] + $itemIn);
ok('the next save puts it right', Live::meeting($pdo, $itemId)['last_error'] === null && Live::meeting($pdo, $itemId)['title_sent'] === 'Workers\' training: Opening class');

section('Join class');
at('2026-12-05 09:58:00');
eq('someone not on the training cannot', 'Join the training first.', Live::join($pdo, $itemId, 902)['message'] ?? null);
eq('a learner gets the Meet link', 'https://meet.google.com/abc-defg-hij', Live::join($pdo, $itemId, 903)['url'] ?? null);
Live::join($pdo, $itemId, 903);
eq('a double tap within a minute is logged once', 1, (int) $pdo->query("SELECT COUNT(*) FROM training_join_log WHERE user_id = 903")->fetchColumn());
at('2026-12-05 10:02:00');
Live::join($pdo, $itemId, 906);
eq('an in-person class has nothing to join', 'This class has no link to join.', Live::join($pdo, $inPerson, 903)['message'] ?? null);
$page = (string) file_get_contents($root . '/modules/trainings/index.php');
ok('the learner\'s Join class button is logged (data-live-join), the link still opens as usual', str_contains($page, "'data-live-join' => (string) \$item['id']")
    && str_contains($page, "'target' => '_blank'"));

section('After the class: attendance from Meet');
at('2026-12-05 09:00:00');
eq('before the class starts: nothing to read', 'The class has not started yet.', Live::sync($pdo, $itemId)['message'] ?? null);
at('2026-12-05 12:30:00');
$replies = [
    ['GET https://meet.googleapis.com/v2/conferenceRecords?', $json(['conferenceRecords' => [['name' => 'conferenceRecords/r1',
        'startTime' => '2026-12-05T08:55:00Z', 'endTime' => '2026-12-05T11:05:00Z']]])],
    ['GET https://meet.googleapis.com/v2/conferenceRecords/r1/participants?', $json(['participants' => [
        ['name' => 'conferenceRecords/r1/participants/a', 'signedinUser' => ['displayName' => 'Dayo Adeyemi'], 'earliestStartTime' => '2026-12-05T09:00:00Z', 'latestEndTime' => '2026-12-05T10:30:00Z'],
        ['name' => 'conferenceRecords/r1/participants/b', 'anonymousUser' => ['displayName' => 'Galaxy A12'], 'earliestStartTime' => '2026-12-05T08:59:00Z', 'latestEndTime' => '2026-12-05T10:20:00Z'],
        ['name' => 'conferenceRecords/r1/participants/c', 'anonymousUser' => ['displayName' => 'F. Balogun'], 'earliestStartTime' => '2026-12-05T09:20:00Z', 'latestEndTime' => '2026-12-05T09:35:00Z'],
        ['name' => 'conferenceRecords/r1/participants/d', 'signedinUser' => ['displayName' => 'Tutor Phone'], 'earliestStartTime' => '2026-12-05T08:55:00Z', 'latestEndTime' => '2026-12-05T11:05:00Z'],
    ]])],
    ['/participantSessions', $json([])],
];
$calls = [];
$r = Live::sync($pdo, $itemId);
eq('read: 2 present, 2 to look at', ['synced', 2, 2], [$r['state'] ?? null, $r['present'] ?? null, $r['open'] ?? null]);
parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $q);
ok('…Meet asked for calls with this code around the class', str_contains($q['filter'], 'space.meeting_code = "abc-defg-hij"'));
$att = $pdo->query('SELECT user_id, source, minutes, present FROM training_attendance ORDER BY user_id')->fetchAll();
eq('Dayo by name (90 min); Chiamaka by the Join log ("Galaxy A12" entered just after she pressed Join)',
    [['user_id' => 903, 'source' => 'join', 'minutes' => 81, 'present' => 1], ['user_id' => 904, 'source' => 'meet', 'minutes' => 90, 'present' => 1]],
    array_map(fn ($a) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $a), $att));
$prog = $pdo->query('SELECT e.user_id, p.status, p.data FROM training_progress p JOIN training_enrolments e ON e.id = p.enrolment_id WHERE p.item_id = ' . $itemId . ' ORDER BY e.user_id')->fetchAll();
eq('present learners have the class completed ({by: meet}); nobody else', [[903, 'completed', 'meet'], [904, 'completed', 'meet']],
    array_map(fn ($p) => [(int) $p['user_id'], $p['status'], json_decode($p['data'], true)['by'] ?? null], $prog));
$state = Live::state($pdo, $itemId);
$open = array_values(array_filter($state['review'], fn ($x) => $x['status'] === 'open'));
eq('Needs a look: the tutor\'s phone, F. Balogun (15 min), and Femi pressed Join at 10:02 but was not found', ['Tutor Phone', 'F. Balogun'],
    array_column($open, 'display_name'));
$femiRow = array_values(array_filter($open, fn ($x) => $x['display_name'] === 'F. Balogun'))[0];
eq('…with Femi suggested first, as the one who pressed Join', 906, $femiRow['suggestions'][0]['user_id'] ?? null);
eq('the learners list shows who pressed Join', '2026-12-05 10:02:00', array_values(array_filter($state['learners'], fn ($l) => $l['user_id'] === 906))[0]['joined_at']);

section('The tutor decides, and a later read never undoes it');
$tutorRow = array_values(array_filter($open, fn ($x) => $x['display_name'] === 'Tutor Phone'))[0];
ok('Ignore: the tutor\'s phone leaves the list', !empty(Live::ignore($pdo, $itemId, $tutorRow['id'], 901)['ok']));
$r = Live::assign($pdo, $itemId, $femiRow['id'], 906, 901);
eq('F. Balogun is Femi: 15 minutes, not enough on their own', [false, 15], [$r['present'] ?? null, $r['minutes'] ?? null]);
Live::setPresent($pdo, $itemId, 906, true, 901);
Live::setPresent($pdo, $itemId, 904, false, 901);
$r = Live::sync($pdo, $itemId);
$rows = $pdo->query('SELECT user_id, source, present FROM training_attendance ORDER BY user_id')->fetchAll();
eq('after reading again: the tutor\'s present and absent stand', [[903, 'join', 1], [904, 'manual', 0], [906, 'manual', 1]],
    array_map(fn ($a) => [(int) $a['user_id'], $a['source'], (int) $a['present']], $rows));
$prog = $pdo->query('SELECT e.user_id, p.data FROM training_progress p JOIN training_enrolments e ON e.id = p.enrolment_id WHERE p.item_id = ' . $itemId . ' ORDER BY e.user_id')->fetchAll();
eq('…and so does progress (Dayo cleared, Femi ticked by the tutor)', [[903, 'meet'], [906, 'tutor']],
    array_map(fn ($p) => [(int) $p['user_id'], json_decode($p['data'], true)['by'] ?? null], $prog));
$review = Live::state($pdo, $itemId)['review'];
$kept = array_column(array_filter($review, fn ($x) => $x['status'] !== 'open'), 'status');
sort($kept);
eq('…the looked-at rows keep their decisions', ['assigned', 'ignored'], $kept);
Live::reopen($pdo, $itemId, $tutorRow['id']);
eq('Reopen puts a row back', 'open', array_values(array_filter(Live::state($pdo, $itemId)['review'], fn ($x) => $x['id'] === $tutorRow['id']))[0]['status']);
eq('a learner not on the training cannot be marked', 'That learner is not on this training.', Live::setPresent($pdo, $itemId, 902, true, 901)['message'] ?? null);

section('The Academy\'s Meet page renders');
$stub = sys_get_temp_dir() . '/tl_render_' . getmypid();
@mkdir($stub . '/includes', 0777, true);
@mkdir($stub . '/a/b', 0777, true);
file_put_contents($stub . '/includes/header.php', "<?php // test stub: the shell is tested elsewhere\n");
file_put_contents($stub . '/includes/footer.php', "<?php\n");
$render = static function (int $uid, int $item) use ($stub, $root, $pdo): string {
    tl_as($uid);
    $cwd = getcwd();
    chdir($stub . '/a/b');
    $_GET = ['item' => (string) $item];
    ob_start();
    try {
        (static function () use ($root, $pdo) {
            include $root . '/modules/academy/live.php';
        })();
    } finally {
        $html = (string) ob_get_clean();
        chdir((string) $cwd);
    }
    return $html;
};
$html = $render(901, $itemId);
ok('the Meet link, Update and Remove link for the Academy', str_contains($html, 'https://meet.google.com/abc-defg-hij') && str_contains($html, 'data-action="live.update"')
    && str_contains($html, 'data-action="live.cancel"'));
ok('every learner with Present/Absent and a correction button', substr_count($html, 'data-action="live.present"') === 3 && str_contains($html, 'Chiamaka Okoro'));
ok('Needs a look: the reopened row with a learner picker, Assign and Ignore', str_contains($html, 'Needs a look') && str_contains($html, 'data-live-pick=')
    && str_contains($html, 'data-action="live.assign"'));
ok('Looked at: the resolved row with Reopen', str_contains($html, 'Counted for Femi Balogun') && str_contains($html, 'data-action="live.reopen"'));
ok('the page script and its data', str_contains($html, '"item":' . $itemId));
$html = $render(900, $inPerson);
ok('an in-person class: attendance only, no Meet card', !str_contains($html, 'Meet link') && str_contains($html, 'Attendance'));
array_map('unlink', [$stub . '/includes/header.php', $stub . '/includes/footer.php']);
@rmdir($stub . '/includes');
@rmdir($stub . '/a/b');
@rmdir($stub . '/a');
@rmdir($stub);

section('The daily run, and removing the class');
$pdo->exec('UPDATE training_meetings SET synced_at = NULL');
at('2026-12-05 12:30:00');
eq('within 2 hours of the end, the daily run waits', 0, training_live_daily($pdo));
at('2026-12-06 06:30:00');
eq('the morning after, it reads the class once', 1, training_live_daily($pdo));
eq('…and not again', 0, training_live_daily($pdo));
$calls = [];
$replies = [['DELETE https://www.googleapis.com/calendar/v3/calendars/primary/events/ev1', ['status' => 204, 'body' => '']]];
act($pdo, 901, 'item_delete', ['training_id' => $tid, 'item_id' => $itemId]);
ok('removing the class deletes its event', ($calls[0]['method'] ?? '') === 'DELETE' && Live::meeting($pdo, $itemId)['status'] === 'cancelled');

section('Static rules');
$read = fn (string $f): string => (string) file_get_contents("{$root}/{$f}");
$api = $read('api/training_live_api.php');
ok('the API checks CSRF, the learner\'s own access for join, the Academy\'s edit for the rest', str_contains($api, 'csrf_require();')
    && str_contains($api, "can('trainings', 'view', perm_person(\$uid))") && str_contains($api, "can('trainings', 'edit', 'church')"));
ok('the Meet page: the Academy only (view), edits need edit', str_contains($read('modules/academy/live.php'), "require_can('trainings', 'view', 'church')")
    && str_contains($read('modules/academy/live.php'), "can('trainings', 'edit', 'church')"));
foreach (['includes/trainings/bootstrap.php' => "is_file(dirname(__DIR__) . '/training_live/bootstrap.php')", 'api/trainings_api.php' => "function_exists('training_live_after_action')",
    'modules/academy/training.php' => "function_exists('training_live_url')", 'cron/trainings_daily.php' => "function_exists('training_live_daily')"] as $file => $guard) {
    ok("{$file}: the hook is guarded, so Trainings works without it", str_contains($read($file), $guard));
}
ok('the Meet page link is clean', training_live_url(5) === '/modules/academy/live?item=5');
ok('no learner record goes to Google: the event has no guests', !str_contains($read('includes/training_live/Live.php'), 'attendees'));

Google::fake(null);
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
