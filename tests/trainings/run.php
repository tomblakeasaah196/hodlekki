<?php
// /tests/trainings/run.php
//
// Trainings (the new HOD Academy), PR 1, on SQLite built from the REAL
// migrations (trainings, email identity, the permission matrix): the shared
// table contract, the length wizard and templates, items and media links,
// cards, publishing, Assign and newcomers, the catalogue, progress, marking,
// completion, confirming through the status history, visitor invites through
// the email identity flow, and every can() decision of the API.
//
//   php tests/trainings/run.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

date_default_timezone_set('Africa/Lagos');
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['MAIL_FROM_ADDRESS'] = 'system@hodlekki.org';
$_ENV['MAIL_USERNAME'] = 'system@hodlekki.org';
$_ENV['SMS_VAULT_KEY'] = str_repeat('0', 64);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/security_helpers.php';
require_once $root . '/includes/auth_helpers.php';
require_once $root . '/includes/ui/bootstrap.php';
require_once $root . '/includes/ui/components.php';
require_once $root . '/includes/csrf.php';
require_once $root . '/includes/sms_functions.php';
require_once $root . '/includes/campaigns/bootstrap.php';
require_once $root . '/tests/campaigns/lib.php';          // ct_schema_sql(), ct_platform_sql(): the real email schema
require_once $root . '/tests/permissions/install.php';    // the permission engine and the day-one matrix
require_once $root . '/includes/trainings/bootstrap.php';

$passed = 0;
$failed = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}
function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function section(string $name): void
{
    echo "\n-- {$name}\n";
}

/** The Trainings core migration, translated to SQLite (as the other suites do). */
function trn_schema_sql(string $file): array
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
            $line = (string) preg_replace('/\b(LONGTEXT|MEDIUMTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        array_push($out, ...$indexes);
    }
    return $out;
}

/** A church: the email schema, the matrix, an Academy department, people. */
function trn_db(bool $withTrainings = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (ct_platform_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    foreach (ct_schema_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    if ($withTrainings) {
        foreach (['20261208090000_trainings_core.sql', '20261211090000_trainings_certificates.sql', '20261212090000_trainings_notes.sql',
            '20261220090000_trainings_signatories.sql'] as $file) {
            foreach (trn_schema_sql($file) as $stmt) {
                $pdo->exec($stmt);
            }
        }
    }
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor')");
    $pdo->exec("INSERT INTO departments (id, name) VALUES (20, 'Media'), (30, 'HOD Academy'), (31, 'Camera')");
    $people = [
        [900, 'Rita', 'Worker', 'x'], [901, 'Ade', 'Worker', 'x'], [902, 'Bola', 'Worker', 'x'], [903, 'Chi', '1st_Timer', null],
        [904, 'Dayo', 'Member', 'x'], [905, 'Efe', '2nd_Timer', null], [906, 'Femi', 'Worker', 'x'], [907, 'Gbenga', '1st_Timer', null],
    ];
    foreach ($people as [$id, $name, $status, $pw]) {
        $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, password_hash, account_status, created_at)
                       VALUES (?, ?, 'Test', ?, 'Active', ?, 'active', '2026-01-01 10:00:00')")
            ->execute([$id, $name, $status, $pw !== null ? password_hash('Old-pass-123!', PASSWORD_BCRYPT) : null]);
    }
    $pdo->exec("UPDATE users SET attendance_status = 'Relocated' WHERE id = 905");
    $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (900, 2)");
    perm_test_install($pdo);   // adds departments.parent_id, the matrix, owners (the Academy: by name)
    $pdo->exec('UPDATE departments SET parent_id = 20 WHERE id = 31');
    $pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES
                (901, 30, 1, 'HOD'), (902, 20, 1, 'Worker'), (906, 31, 1, 'Worker')");
    perm_reset();
    return $pdo;
}

function trn_as(int $uid): void
{
    $_SESSION = ['user_id' => $uid];
    perm_reset();
}

function act(PDO $pdo, int $uid, string $action, array $in = [], array $files = []): array
{
    trn_as($uid);
    return trainings_action($pdo, $action, $in, $files, $uid);
}

function trn_count(PDO $pdo, string $table, string $where = '1=1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Africa/Lagos'));
campaigns_set_clock(new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Africa/Lagos')));

// ---------------------------------------------------------------------------
section('Before the migration everything degrades');
$bare = trn_db(false);
eq('trainings_ready() is false', false, trainings_ready($bare));
eq('the API says FEATURE_NOT_READY', 'FEATURE_NOT_READY', act($bare, 904, 'mine')['code'] ?? null);
eq('nobody is ready to serve', false, trainings_ready_to_serve($bare, 904));

$pdo = trn_db();
eq('after the migration trainings_ready() is true', true, trainings_ready($pdo));

// ---------------------------------------------------------------------------
section('The shared table contract (TRAININGS-PLAN.md: PR 2 and PR 3 build on these names)');
$contract = [
    'trainings' => ['id', 'title', 'summary', 'kind', 'official_key', 'length', 'starts_on', 'ends_on', 'status', 'catalogue',
        'gives_certificate', 'pass_rule', 'department_id', 'created_by', 'created_at', 'updated_at'],
    'training_sections' => ['id', 'training_id', 'kind', 'title', 'date_from', 'date_to', 'position'],
    'training_items' => ['id', 'training_id', 'section_id', 'position', 'type', 'title', 'starts_at', 'minutes', 'required', 'details',
        'created_at', 'updated_at'],
    'training_enrolments' => ['id', 'training_id', 'user_id', 'source', 'assigned_by', 'due_on', 'status', 'completed_at', 'confirmed_by',
        'confirmed_at', 'certificate_no', 'certificate_hash', 'created_at', 'updated_at'],
    'training_assignments' => ['id', 'training_id', 'audience', 'due_days', 'keep_enrolling_newcomers', 'people', 'created_by', 'created_at'],
    'training_progress' => ['id', 'enrolment_id', 'item_id', 'status', 'completed_at', 'data', 'updated_at'],
    'training_invites' => ['id', 'enrolment_id', 'user_id', 'email', 'token_hash', 'created_by', 'created_at', 'expires_at', 'used_at'],
];
foreach ($contract as $table => $cols) {
    eq("{$table}: its columns", $cols, array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name'));
}
$core = (string) file_get_contents($root . '/db/migrations/20261208090000_trainings_core.sql');
ok('one enrolment per person per training', str_contains($core, 'UNIQUE KEY uq_training_enrolments (training_id, user_id)'));
ok('one progress row per learner per item', str_contains($core, 'UNIQUE KEY uq_training_progress (enrolment_id, item_id)'));
ok('the core file is idempotent (IF NOT EXISTS) and drops nothing', substr_count($core, 'CREATE TABLE IF NOT EXISTS') === 7 && stripos($core, 'DROP ') === false);
foreach (['20261208090000_trainings_core.sql', '20261208090100_trainings_retire_academy.sql'] as $f) {
    ok("{$f} sits in PR 1's slot and carries the _trainings_ marker", str_starts_with($f, '20261208') && str_contains($f, '_trainings_'));
}
$setup = (string) file_get_contents($root . '/tests/special_events/db_setup.php');
ok('the integration job treats _trainings_ migrations as fatal', str_contains($setup, "str_contains(\$name, '_trainings_')"));

// ---------------------------------------------------------------------------
section('The old Academy retires by RENAME (owner answer 16), never DROP');
$retire = (string) file_get_contents($root . '/db/migrations/20261208090100_trainings_retire_academy.sql');
$old = ['academy_applications', 'academy_assignments', 'academy_batches', 'academy_courses', 'academy_enrollments', 'academy_forum_messages',
    'academy_group_members', 'academy_interview_scores', 'academy_programs', 'academy_student_groups', 'academy_submissions',
    'course_materials', 'exam_questions', 'user_exams', 'user_exam_answers'];
foreach ($old as $t) {
    ok("{$t} → {$t}_retired_20261112, only if it is there and not yet retired",
        str_contains($retire, "'RENAME TABLE {$t} TO {$t}_retired_20261112'") && str_contains($retire, "table_name = '{$t}') = 1")
        && str_contains($retire, "table_name = '{$t}_retired_20261112') = 0"));
}
$retireSql = (string) preg_replace('/^\s*--.*$/m', '', $retire);
ok('no DROP, no DELIMITER', stripos($retireSql, 'DROP ') === false && stripos($retireSql, 'DELIMITER') === false);
eq('one PREPARE per table', count($old), substr_count($retire, 'PREPARE trainings_retire FROM @sql;'));
foreach (['api/academy_api.php', 'includes/academy_access.php', 'tests/academy_forum_access_test.php'] as $f) {
    ok("{$f} is gone", !is_file($root . '/' . $f));
}
$idi = (string) file_get_contents($root . '/api/idi_api.php');
ok('IDI reads the Academy pipeline from training_enrolments', str_contains($idi, 'FROM training_enrolments') && !str_contains($idi, 'FROM academy_enrollments'));

// ---------------------------------------------------------------------------
section('Access: the trainings row (it took over the Academy row)');
$modules = perm_modules();
ok('trainings is registered, member-facing, with Approve and Manage', isset($modules['trainings']) && !empty($modules['trainings']['member'])
    && $modules['trainings']['extras'] === ['approve', 'manage']);
ok('the academy key is gone from the registry', !isset($modules['academy']));
trn_as(904);
ok('a member works on their own trainings', can('trainings', 'create', perm_person(904)));
ok('…but not the Academy\'s', !can('trainings', 'view', 'church') && !can('trainings', 'edit', perm_person(903)));
trn_as(901);
ok('the Academy HOD seat runs everything, confirms graduates', can('trainings', 'delete', 'church') && can('trainings', 'approve', 'church'));
trn_as(900);
ok('the Resident Pastor too', can('trainings', 'edit', 'church') && can('trainings', 'approve', 'church'));
trn_as(902);
ok('a worker in another department: own records only', !can('trainings', 'view', 'church') && can('trainings', 'create', perm_person(902)));
require_once $root . '/includes/ui/nav.php';
$nav = array_column(ui_nav_registry(), null, 'key');
ok('nav: Trainings for everyone, with its (i) line (owner answer 1)', ($nav['trainings']['href'] ?? '') === '/modules/trainings/'
    && ($nav['trainings']['info'] ?? '') === 'Your classes, live sessions, exams and certificates in one place.');
$ctxFor = static function (int $uid): array {
    trn_as($uid);
    return ['perm' => perm_current(), 'assim_team' => false, 'se_crew' => false, 'mail_nav' => false];
};
ok('nav: Trainings shows to a member', ui_nav_can_access('trainings', $ctxFor(904)));
ok('nav: HOD Academy hides from a member', !ui_nav_can_access('academy', $ctxFor(904)));
ok('nav: HOD Academy shows to the Academy and the pastors', ui_nav_can_access('academy', $ctxFor(901)) && ui_nav_can_access('academy', $ctxFor(900)));
eq('nav: the Academy entry asks the trainings row', 'trainings', ui_nav_module('academy'));

// ---------------------------------------------------------------------------
section('The length wizard makes the empty frame (owner answer 2)');
$mk = static fn (array $in): array => act($pdo, 901, 'create', $in);
eq('a name is required', 'title', $mk(['length' => 'weeks', 'starts_on' => '2026-12-07', 'count' => 4])['field'] ?? null);
eq('a length is required', 'length', $mk(['title' => 'X', 'starts_on' => '2026-12-07'])['field'] ?? null);
eq('a dated length needs a first day', 'starts_on', $mk(['title' => 'X', 'length' => 'days', 'count' => 3])['field'] ?? null);
eq('a few days is 2 to 14', 'count', $mk(['title' => 'X', 'length' => 'days', 'starts_on' => '2026-12-07', 'count' => 1])['field'] ?? null);
eq('weeks is 1 to 26', 'count', $mk(['title' => 'X', 'length' => 'weeks', 'starts_on' => '2026-12-07', 'count' => 27])['field'] ?? null);
eq('a member cannot create one', 'FORBIDDEN', act($pdo, 904, 'create', ['title' => 'X', 'length' => 'self_paced'])['code'] ?? null);

$oneDay = (int) $mk(['title' => 'Workers\' training', 'length' => 'one_day', 'starts_on' => '2026-12-12'])['id'];
$days = (int) $mk(['title' => 'Retreat', 'length' => 'days', 'starts_on' => '2026-12-12', 'count' => 3])['id'];
$weeks = (int) $mk(['title' => 'Foundations', 'length' => 'weeks', 'starts_on' => '2026-12-07', 'count' => 2])['id'];
$self = (int) $mk(['title' => 'Prayer basics', 'length' => 'self_paced', 'starts_on' => '2026-12-07', 'count' => 9])['id'];
$frame = static fn (int $id): array => array_map(static fn ($s) => [$s['kind'], $s['title'], $s['date_from'], $s['date_to']],
    array_filter(trainings_outline($pdo, $id), static fn ($s) => (int) $s['id'] > 0));
eq('one day: a single dated card', [['day', 'Day 1', '2026-12-12', '2026-12-12']], array_values($frame($oneDay)));
eq('a few days: one card per day', [['day', 'Day 1', '2026-12-12', '2026-12-12'], ['day', 'Day 2', '2026-12-13', '2026-12-13'],
    ['day', 'Day 3', '2026-12-14', '2026-12-14']], array_values($frame($days)));
eq('weeks: one card per week', [['week', 'Week 1', '2026-12-07', '2026-12-13'], ['week', 'Week 2', '2026-12-14', '2026-12-20']], array_values($frame($weeks)));
eq('self-paced: no dates', [['module', 'Module 1', null, null]], array_values($frame($self)));
eq('the training ends with its last card', '2026-12-20', trainings_get($pdo, $weeks)['ends_on']);
eq('self-paced keeps no dates', [null, null], [trainings_get($pdo, $self)['starts_on'], trainings_get($pdo, $self)['ends_on']]);
eq('a new training is a draft, out of the catalogue', ['draft', false, 'individual'],
    [trainings_get($pdo, $weeks)['status'], trainings_get($pdo, $weeks)['catalogue'], trainings_get($pdo, $weeks)['kind']]);
eq('it belongs to the builder\'s Academy department', 30, trainings_get($pdo, $weeks)['department_id']);

// ---------------------------------------------------------------------------
section('Templates: Membership Class and Maturity Class');
$mem = (int) $mk(['template' => 'membership', 'starts_on' => '2026-12-06'])['id'];
$mat = (int) $mk(['template' => 'maturity', 'title' => 'Maturity Class (Jan)', 'starts_on' => '2027-01-03'])['id'];
$memT = trainings_get($pdo, $mem);
eq('Membership: official, keyed, named, 4 weeks', ['official', 'membership', 'Membership Class', 'weeks', '2027-01-02'],
    [$memT['kind'], $memT['official_key'], $memT['title'], $memT['length'], $memT['ends_on']]);
eq('Membership: 4 cards, 8 items, 4 live classes', [4, 8, 4], [count($frame($mem)), count(trainings_items_flat($pdo, $mem)),
    count(array_filter(trainings_items_flat($pdo, $mem), static fn ($i) => $i['type'] === 'live'))]);
eq('Maturity keeps the name it was given', 'Maturity Class (Jan)', trainings_get($pdo, $mat)['title']);
eq('Maturity: 6 weeks, 3 sermon reviews with the three prompts', [6, 3, ['The main points', 'The scriptures used', 'How it applies to me']],
    [count($frame($mat)), count(array_filter(trainings_items_flat($pdo, $mat), static fn ($i) => ($i['details']['kind'] ?? '') === 'sermon_review')),
     array_values(array_filter(trainings_items_flat($pdo, $mat), static fn ($i) => ($i['details']['kind'] ?? '') === 'sermon_review'))[0]['details']['prompts']]);
eq('templates attend 75% of live classes', 75, $memT['pass_rule']['live_percent']);
eq('an unknown template is refused', 'template', $mk(['template' => 'bogus'])['field'] ?? null);
eq('template items wait for their content (two readings, a video)', 3, count(array_filter(trainings_items_flat($pdo, $mem), static fn ($i) => $i['needs_content'])));

// ---------------------------------------------------------------------------
section('Media links: YouTube and Spotify only (owner answer 7)');
$m = trainings_media_parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s');
eq('YouTube watch link, with its start time', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=90', $m['embed'] ?? null);
eq('youtu.be', 'dQw4w9WgXcQ', trainings_media_parse('youtu.be/dQw4w9WgXcQ')['id'] ?? null);
eq('shorts and live', ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'], [trainings_media_parse('https://youtube.com/shorts/dQw4w9WgXcQ')['id'] ?? null,
    trainings_media_parse('https://www.youtube.com/live/dQw4w9WgXcQ?si=x')['id'] ?? null]);
eq('Spotify episode', 'https://open.spotify.com/embed/episode/4rOoJ6Egrf8K2IrywzwOMk',
    trainings_media_parse('https://open.spotify.com/episode/4rOoJ6Egrf8K2IrywzwOMk?si=abc')['embed'] ?? null);
eq('Spotify with a country path', 'show', trainings_media_parse('https://open.spotify.com/intl-fr/show/4rOoJ6Egrf8K2IrywzwOMk')['kind'] ?? null);
foreach (['https://vimeo.com/123', 'https://evil.example/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=short', 'javascript:alert(1)',
          'https://open.spotify.com/episode/../../x'] as $bad) {
    eq("refused: {$bad}", null, trainings_media_parse($bad));
}
ok('the pages allow exactly those two hosts in frame-src', TRAININGS_EMBED_HOSTS === ['https://www.youtube-nocookie.com', 'https://open.spotify.com']);
ok('uploads are sniffed: PDF, MP3 (ID3 or frame sync), M4A', trainings_sniff_bytes('%PDF-1.7', 'pdf') && trainings_sniff_bytes('ID3' . "\x04", 'mp3')
    && trainings_sniff_bytes("\xFF\xFB\x90", 'mp3') && trainings_sniff_bytes("\x00\x00\x00\x20ftypM4A ", 'm4a'));
ok('…and a script renamed .mp3 is not', !trainings_sniff_bytes('<?php echo 1;', 'mp3') && !trainings_sniff_bytes('<html>', 'pdf'));
eq('an upload over 50 MB is refused before anything is stored', false,
    trainings_upload(['name' => 'big.mp3', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 51 * 1048576], 'audio')['ok']);
eq('a file that did not arrive by upload is refused', false,
    trainings_upload(['name' => 'a.mp3', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10], 'audio')['ok']);

// ---------------------------------------------------------------------------
section('Items and cards');
$wk = array_values(array_filter(trainings_outline($pdo, $weeks), static fn ($s) => (int) $s['id'] > 0));
$w1 = (int) $wk[0]['id'];
$w2 = (int) $wk[1]['id'];
$save = static fn (array $in): array => act($pdo, 901, 'item_save', $in + ['training_id' => $weeks]);
$live = $save(['type' => 'live', 'title' => 'Opening class', 'section_id' => $w1, 'date' => '2026-12-09', 'time' => '18:30', 'minutes' => 90,
    'live_mode' => 'online', 'link' => 'https://meet.google.com/abc-defg-hij', 'present_minutes' => 45]);
ok('a live class saves', ($live['status'] ?? '') === 'success', json_encode($live));
$liveItem = trainings_item_get($pdo, (int) $live['id']);
eq('…on its day and time in the week', '2026-12-09 18:30:00', $liveItem['starts_at']);
eq('…with its pasted link and attendance rule', ['online', 'https://meet.google.com/abc-defg-hij', 45],
    [$liveItem['details']['mode'], $liveItem['details']['link'], $liveItem['details']['present_minutes']]);
ok('…leaving meet_url and meet_event_id to PR 3', !array_key_exists('meet_url', $liveItem['details']) && !array_key_exists('meet_event_id', $liveItem['details']));
eq('a day outside the week is refused', 'date', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'date' => '2026-12-15', 'time' => '09:00'])['field'] ?? null);
eq('a bad time is refused', 'time', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'time' => '25:00'])['field'] ?? null);
eq('a link must be https', 'link', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'live_mode' => 'online', 'link' => 'http://x.org'])['field'] ?? null);
if (!function_exists('exam_url_setup')) {
    // With PR 2 (includes/exams/) loaded, exams can be made: "Hooks for PR 2" below checks both cases.
    eq('an exam cannot be made here yet (PR 2)', 'type', $save(['type' => 'exam', 'title' => 'X', 'section_id' => $w1])['field'] ?? null);
}
eq('a video needs a YouTube or Spotify link', 'url', $save(['type' => 'video', 'title' => 'X', 'section_id' => $w1, 'url' => 'https://vimeo.com/1'])['field'] ?? null);
$video = (int) $save(['type' => 'video', 'title' => 'Teaching', 'section_id' => $w1, 'url' => 'https://youtu.be/dQw4w9WgXcQ'])['id'];
$audio = (int) $save(['type' => 'audio', 'title' => 'Podcast', 'section_id' => $w1, 'url' => 'https://open.spotify.com/episode/4rOoJ6Egrf8K2IrywzwOMk'])['id'];
$reading = (int) $save(['type' => 'reading', 'title' => 'Read this', 'section_id' => $w2, 'text' => "First paragraph.\n\nSecond."])['id'];
$essay = (int) $save(['type' => 'assignment', 'title' => 'Sermon review', 'section_id' => $w2, 'assignment_kind' => 'sermon_review',
    'instructions' => 'Listen, then answer.', 'prompts' => "Main points\nScriptures\nHow it applies to me", 'max_mark' => 10, 'pass_mark' => 6,
    'media_source' => 'link', 'media_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])['id'];
$optional = (int) $save(['type' => 'reading', 'title' => 'Extra reading', 'section_id' => $w2, 'text' => 'More.', 'required' => '0'])['id'];
eq('the audio link plays embedded', 'spotify', trainings_item_get($pdo, $audio)['details']['provider']);
eq('a sermon review keeps its prompts, marks and sermon', [['Main points', 'Scriptures', 'How it applies to me'], 10, 6, 'youtube'],
    [trainings_item_get($pdo, $essay)['details']['prompts'], trainings_item_get($pdo, $essay)['details']['max_mark'],
     trainings_item_get($pdo, $essay)['details']['pass_mark'], trainings_item_get($pdo, $essay)['details']['media']['provider']]);
eq('a pass mark above the total is refused', 'pass_mark', $save(['type' => 'assignment', 'title' => 'X', 'section_id' => $w2, 'max_mark' => 5, 'pass_mark' => 6])['field'] ?? null);
eq('a sermon review without its sermon still needs content', true, trainings_item_get($pdo, (int) $save(['type' => 'assignment', 'title' => 'Review 2',
    'section_id' => $w2, 'assignment_kind' => 'sermon_review', 'prompts' => 'Main points'])['id'])['needs_content']);
$last = trainings_items_flat($pdo, $weeks);
act($pdo, 901, 'item_delete', ['training_id' => $weeks, 'item_id' => end($last)['id']]);
eq('an optional item is not required', false, trainings_item_get($pdo, $optional)['required']);
$r = $save(['id' => $video, 'type' => 'live', 'title' => 'Teaching (new)', 'section_id' => $w1, 'url' => 'https://youtu.be/dQw4w9WgXcQ']);
eq('editing keeps the kind of an item', 'video', trainings_item_get($pdo, $video)['type']);
eq('…and changes its name', 'Teaching (new)', trainings_item_get($pdo, $video)['title']);
eq('an item of another training is refused', 'NOT_FOUND', act($pdo, 901, 'item_save', ['training_id' => $days, 'id' => $video, 'title' => 'x', 'section_id' => $w1])['code'] ?? null);
$order = static fn (int $sid): array => array_map(static fn ($i) => $i['id'], array_values(array_filter(trainings_items_flat($pdo, $weeks),
    static fn ($i) => $i['section_id'] === $sid)));
eq('a dated card lists timed items first, then in the order placed', [(int) $live['id'], $video, $audio], $order($w1));
act($pdo, 901, 'item_move', ['training_id' => $weeks, 'item_id' => $audio, 'dir' => -1]);
eq('moving an item swaps it with its neighbour', [(int) $live['id'], $audio, $video], $order($w1));

eq('a member cannot change items', 'FORBIDDEN', act($pdo, 904, 'item_save', ['training_id' => $weeks, 'type' => 'reading', 'title' => 'x', 'section_id' => $w1])['code'] ?? null);
$add = act($pdo, 901, 'section_add', ['training_id' => $weeks]);
eq('adding a card adds the next week', ['week', 'Week 3', '2026-12-21', '2026-12-27'], array_values($frame($weeks))[2]);
act($pdo, 901, 'section_update', ['training_id' => $weeks, 'section_id' => $w1, 'title' => 'Welcome week', 'date_from' => '2026-12-08']);
eq('moving a card moves its class times', '2026-12-10 18:30:00', trainings_item_get($pdo, (int) $live['id'])['starts_at']);
eq('…and the card is renamed', 'Welcome week', trainings_section_get($pdo, $w1)['title']);
act($pdo, 901, 'section_move', ['training_id' => $weeks, 'section_id' => (int) $add['id'], 'dir' => -1]);
eq('cards reorder', ['Welcome week', 'Week 3', 'Week 2'], array_column(array_values($frame($weeks)), 1));
act($pdo, 901, 'section_delete', ['training_id' => $weeks, 'section_id' => (int) $add['id']]);
eq('an empty card can be removed', 2, count($frame($weeks)));
eq('the last card stays', 'A training keeps at least one card.', act($pdo, 901, 'section_delete',
    ['training_id' => $oneDay, 'section_id' => (int) trainings_outline($pdo, $oneDay)[0]['id']])['message'] ?? null);
act($pdo, 901, 'settings', ['training_id' => $weeks, 'title' => 'Foundations', 'starts_on' => '2026-12-14', 'live_percent' => 50, 'catalogue' => 0]);
eq('moving the first day (8 → 14 Dec) moves every card and time', ['2026-12-14', '2026-12-16 18:30:00', 50],
    [trainings_section_get($pdo, $w1)['date_from'], trainings_item_get($pdo, (int) $live['id'])['starts_at'], trainings_get($pdo, $weeks)['pass_rule']['live_percent']]);
eq('a share above 100% is refused', 'live_percent', act($pdo, 901, 'settings', ['training_id' => $weeks, 'live_percent' => 120])['field'] ?? null);

// ---------------------------------------------------------------------------
section('Publishing');
eq('a training with items that need content cannot be published', 'error', act($pdo, 901, 'publish', ['training_id' => $mem])['status']);
eq('an empty one neither', 'Add an item first.', act($pdo, 901, 'publish', ['training_id' => $self])['message'] ?? null);
eq('a filled one can', 'success', act($pdo, 901, 'publish', ['training_id' => $weeks])['status']);
eq('it is published', 'published', trainings_get($pdo, $weeks)['status']);

// ---------------------------------------------------------------------------
section('Assign: groups and named people, with a due date (owner answer 3)');
$t = trainings_get($pdo, $weeks);
eq('an empty audience is refused', 'audience', act($pdo, 901, 'assign', ['training_id' => $weeks])['field'] ?? null);
eq('a due date in the past is refused', 'due_on', act($pdo, 901, 'assign', ['training_id' => $weeks, 'workers' => 1, 'due_on' => '2026-11-30'])['field'] ?? null);
eq('a member cannot assign', 'FORBIDDEN', act($pdo, 904, 'assign', ['training_id' => $weeks, 'workers' => 1])['code'] ?? null);
eq('all workers = everyone with a live seat', [901, 902, 906], trainings_audience_ids($pdo, trainings_audience_clean(['workers' => 1])));
eq('a department brings its sub-units', [902, 906], trainings_audience_ids($pdo, trainings_audience_clean(['departments' => [20]])));
eq('a status group leaves out people who left; a named person is always in', [903, 905, 907],
    trainings_audience_ids($pdo, trainings_audience_clean(['statuses' => ['1st_Timer', '2nd_Timer'], 'users' => [905]])));
eq('an unknown status is dropped', [], trainings_audience_clean(['statuses' => ['Admin']])['statuses']);
$r = act($pdo, 901, 'assign', ['training_id' => $weeks, 'statuses' => ['1st_Timer'], 'users' => [904], 'due_on' => '2026-12-31', 'keep' => 1]);
eq('assigning enrols the people it covers', [3, 3, 0], [$r['matched'] ?? null, $r['added'] ?? null, $r['already'] ?? null]);
eq('…with the due date, as assigned', ['assigned', '2026-12-31', 901],
    [trainings_enrolment_for($pdo, $weeks, 903)['source'], trainings_enrolment_for($pdo, $weeks, 903)['due_on'], (int) trainings_enrolment_for($pdo, $weeks, 903)['assigned_by']]);
eq('…and tells them (published)', 3, trn_count($pdo, 'system_notifications', "title = 'New training'"));
$r = act($pdo, 901, 'assign', ['training_id' => $weeks, 'statuses' => ['1st_Timer'], 'users' => [904]]);
eq('assigning again changes nobody', [0, 3], [$r['added'] ?? null, $r['already'] ?? null]);
eq('one row per person', 3, trn_count($pdo, 'training_enrolments', 'training_id = ?', [$weeks]));
$pdo->exec("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, account_status, created_at)
            VALUES (908, 'Halima', 'Test', '1st_Timer', 'Active', 'active', '2026-12-01 09:00:00')");
$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-12-02 06:30:00', new DateTimeZone('Africa/Lagos'));
eq('keep enrolling newcomers: the daily sweep enrols a new 1st Timer', 1, trainings_sync_newcomers($pdo));
eq('…due the same number of days after their own start', '2027-01-01', trainings_enrolment_for($pdo, $weeks, 908)['due_on']);
eq('…and only once', 0, trainings_sync_newcomers($pdo));
trainings_withdraw($pdo, trainings_enrolment_for($pdo, $weeks, 907));
eq('someone taken off by hand is not put back by the sweep', [0, 'withdrawn'], [trainings_sync_newcomers($pdo), trainings_enrolment_for($pdo, $weeks, 907)['status']]);
$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Africa/Lagos'));
$hist = trainings_assignment_history($pdo, $weeks);
eq('the Assign history says who, how many, and whether it keeps going', ['1st Timer, 1 person', 4, true], [$hist[1]['who'], $hist[1]['people'], $hist[1]['keep']]);

// ---------------------------------------------------------------------------
section('The catalogue (self-enrolment)');
eq('a training not in the catalogue cannot be joined', 'error', act($pdo, 902, 'join', ['training_id' => $weeks])['status']);
act($pdo, 901, 'settings', ['training_id' => $weeks, 'title' => 'Foundations', 'catalogue' => 1, 'live_percent' => 50]);
eq('it shows in the catalogue', [$weeks], array_column(act($pdo, 902, 'mine')['catalogue'], 'id'));
eq('joining enrols you', 'success', act($pdo, 902, 'join', ['training_id' => $weeks])['status']);
eq('…as self', 'self', trainings_enrolment_for($pdo, $weeks, 902)['source']);
eq('my trainings list it', [$weeks], array_column(act($pdo, 902, 'mine')['mine'], 'id'));
eq('you may leave what you joined', 'success', act($pdo, 902, 'leave', ['enrolment_id' => trainings_enrolment_for($pdo, $weeks, 902)['id']])['status']);
eq('…but not what the Academy gave you', 'error', act($pdo, 903, 'leave', ['enrolment_id' => trainings_enrolment_for($pdo, $weeks, 903)['id']])['status']);
eq('…nor someone else\'s', 'FORBIDDEN', act($pdo, 902, 'leave', ['enrolment_id' => trainings_enrolment_for($pdo, $weeks, 903)['id']])['code'] ?? null);
eq('a draft cannot be viewed by a learner', 'FORBIDDEN', act($pdo, 904, 'view', ['training_id' => $mem])['code'] ?? null);

// ---------------------------------------------------------------------------
section('My notes (owner, 13 Nov 2026): the learner\'s own, saved as they type, a Word copy');
$nb = trn_db();
$nb->exec('DROP TABLE training_notes');
eq('before the notes migration the panel is not offered', false, trainings_notes_ready($nb));
eq('…and the API says FEATURE_NOT_READY', 'FEATURE_NOT_READY', act($nb, 904, 'note_get', ['training_id' => 1])['code'] ?? null);
eq('after it, it is', true, trainings_notes_ready($pdo));
$g = act($pdo, 904, 'note_get', ['training_id' => $weeks]);
eq('no note yet: empty, never saved, with its limit', ['success', '', null, TRAININGS_NOTE_MAX], [$g['status'], $g['body'] ?? null, array_key_exists('updated_at', $g) ? $g['updated_at'] : 'missing', $g['max'] ?? null]);
$sv = act($pdo, 904, 'note_save', ['training_id' => $weeks, 'body' => "Faith comes by hearing.\r\n\tRomans 10:17\x07"]);
eq('saving works and says when', ['success', '2026-12-01 10:00:00'], [$sv['status'], $sv['updated_at'] ?? null]);
eq('…kept with Unix line ends, tabs, and no control characters', "Faith comes by hearing.\n\tRomans 10:17", act($pdo, 904, 'note_get', ['training_id' => $weeks])['body'] ?? null);
act($pdo, 904, 'note_save', ['training_id' => $weeks, 'body' => 'Second draft <&> é']);
eq('saving again updates the one row', [1, 'Second draft <&> é'],
    [trn_count($pdo, 'training_notes', 'training_id = ? AND user_id = ?', [$weeks, 904]), trainings_note_get($pdo, $weeks, 904)['body']]);
eq('each person has their own: another learner sees an empty note', '', act($pdo, 903, 'note_get', ['training_id' => $weeks])['body'] ?? null);
eq('a note over 20,000 characters is refused', 'TOO_LONG', act($pdo, 904, 'note_save', ['training_id' => $weeks, 'body' => str_repeat('é', 20001)])['code'] ?? null);
eq('…20,000 is kept', 'success', act($pdo, 904, 'note_save', ['training_id' => $weeks, 'body' => str_repeat('é', 20000)])['status']);
act($pdo, 904, 'note_save', ['training_id' => $weeks, 'body' => "Second draft <&> é\n\nPray daily."]);
eq('someone browsing the catalogue may take notes too', 'success', act($pdo, 902, 'note_save', ['training_id' => $weeks, 'body' => 'Browsing'])['status']);
eq('a draft is not open to a learner', 'FORBIDDEN', act($pdo, 904, 'note_save', ['training_id' => $mem, 'body' => 'x'])['code'] ?? null);
eq('…nor is reading a note on it', 'FORBIDDEN', act($pdo, 904, 'note_get', ['training_id' => $mem])['code'] ?? null);
eq('the Academy previewing a draft may note it', 'success', act($pdo, 901, 'note_save', ['training_id' => $mem, 'body' => 'Check slide 3'])['status']);
eq('a removed training says so', 'NOT_FOUND', act($pdo, 904, 'note_get', ['training_id' => 99999])['code'] ?? null);
act($pdo, 901, 'settings', ['training_id' => $weeks, 'title' => 'Foundations', 'catalogue' => 0, 'live_percent' => 50]);
eq('once a training closes to someone, their own note is still theirs to read', 'Browsing', act($pdo, 902, 'note_get', ['training_id' => $weeks])['body'] ?? null);
eq('…but not to change', 'FORBIDDEN', act($pdo, 902, 'note_save', ['training_id' => $weeks, 'body' => 'more'])['code'] ?? null);
act($pdo, 901, 'settings', ['training_id' => $weeks, 'title' => 'Foundations', 'catalogue' => 1, 'live_percent' => 50]);
$readers = [];
foreach (['includes', 'api', 'modules', 'cron'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (str_ends_with($f->getFilename(), '.php') && str_contains((string) file_get_contents($f->getPathname()), 'training_notes')) {
            $readers[] = substr($f->getPathname(), strlen($root) + 1);
        }
    }
}
sort($readers);
eq('only the notes code touches the table: no Academy screen, report or AI reads a note', ['includes/trainings/notes.php'], $readers);
$docSrc = (string) file_get_contents($root . '/api/trainings_notes_doc.php');
ok('the Word download serves the signed-in person\'s own note only (no user parameter)',
    str_contains($docSrc, "trainings_note_get(\$pdo, (int) \$t['id'], \$me)") && !preg_match('/\$_GET\[\'(u|user|user_id)\'\]/', $docSrc));
$bytes = trainings_note_docx(trainings_get($pdo, $weeks), 'Dayo Test', trainings_note_get($pdo, $weeks, 904));
$tmpDoc = tempnam(sys_get_temp_dir(), 'trnnote');
file_put_contents($tmpDoc, $bytes);
$zip = new ZipArchive();
ok('the Word copy is a zip', $zip->open($tmpDoc) === true);
$docXml = (string) $zip->getFromName('word/document.xml');
$parts = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $parts[] = $zip->getNameIndex($i);
}
$zip->close();
@unlink($tmpDoc);
sort($parts);
eq('…with the parts Word needs', ['[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'word/_rels/document.xml.rels', 'word/document.xml', 'word/styles.xml'], $parts);
$dom = new DOMDocument();
ok('…well-formed XML', @$dom->loadXML($docXml, LIBXML_NONET) === true);
$paras = [];
foreach ($dom->getElementsByTagNameNS(DOCX_NS_W, 'p') as $p) {
    $paras[] = $p->textContent;
}
eq('…the training, who and when, then the note line by line (blank lines kept, text escaped)',
    ['Foundations', 'My notes · Dayo Test · Saved 1 Dec 2026', 'Second draft <&> é', '', 'Pray daily.'], $paras);
eq('the file name is safe', 'My notes - Foundations Week 1.docx', docx_filename('My notes - Foundations: <Week 1>'));
eq('…and never empty', 'Notes.docx', docx_filename('!!!'));
$page = (string) file_get_contents($root . '/modules/trainings/index.php');
ok('the training page offers Take notes and the panel, remembered per training',
    str_contains($page, "'data-notes-open' => 'trn-notes'") && str_contains($page, "ui_notetaker('trn-notes'") && str_contains($page, "'remember' => 'trn-' . (int) \$t['id']"));
$panel = ui_notetaker('n1', ['subject' => 'A <b>', 'endpoint' => '/api/x.php', 'download' => '/d?t=1', 'remember' => 'k']);
ok('the panel is a labelled region, hidden until opened, never a dialog', str_contains($panel, '<section id="n1" class="ui-notes" aria-labelledby="n1-title"')
    && str_contains($panel, ' hidden>') && !str_contains($panel, '<dialog') && !str_contains($panel, 'aria-modal'));
ok('…escapes what it prints and labels the note', str_contains($panel, 'A &lt;b&gt;') && str_contains($panel, '<label class="sr-only" for="n1-text">My notes on A &lt;b&gt;</label>'));
ok('…offers Move, Minimise, Close and Download as Word', str_contains($panel, 'aria-label="Move notes"') && str_contains($panel, 'aria-label="Minimise"')
    && str_contains($panel, 'aria-label="Close notes"') && str_contains($panel, 'aria-label="Download as Word"'));
ok('…and without a download link, no Download', !str_contains(ui_notetaker('n2', []), 'data-notes-download'));

// ---------------------------------------------------------------------------
section('Progress, handing in work, marking, completion');
$e = trainings_enrolment_for($pdo, $weeks, 904);
$view = act($pdo, 904, 'view', ['training_id' => $weeks]);
eq('the learner sees their training', 'success', $view['status']);
$liveSeen = $view['sections'][0]['items'][0];
ok('a learner never sees tutor-only fields', !isset($liveSeen['details']['present_minutes']) && $liveSeen['details']['link'] === 'https://meet.google.com/abc-defg-hij');
eq('a learner cannot tick a live class', 'error', act($pdo, 904, 'item_done', ['item_id' => (int) $live['id'], 'done' => 1])['status']);
eq('…but marks a video done', 'success', act($pdo, 904, 'item_done', ['item_id' => $video, 'done' => 1])['status']);
act($pdo, 904, 'item_done', ['item_id' => $audio, 'done' => 1]);
act($pdo, 904, 'item_done', ['item_id' => $reading, 'done' => 1]);
eq('someone not on it cannot', 'FORBIDDEN', act($pdo, 906, 'item_done', ['item_id' => $video, 'done' => 1])['code'] ?? null);
eq('every question needs an answer', 'answer_2', act($pdo, 904, 'submit_work', ['item_id' => $essay, 'answers' => ['a', 'b', '']])['field'] ?? null);
eq('handing in', 'success', act($pdo, 904, 'submit_work', ['item_id' => $essay, 'answers' => ['Grace', 'John 3:16', 'Daily']])['status']);
eq('…once, until it is marked', 'error', act($pdo, 904, 'submit_work', ['item_id' => $essay, 'answers' => ['x', 'y', 'z']])['status']);
eq('it waits in To mark', [$essay], array_column(act($pdo, 901, 'to_mark')['work'], 'item_id'));
eq('a member cannot mark', 'FORBIDDEN', act($pdo, 904, 'mark_work', ['enrolment_id' => $e['id'], 'item_id' => $essay, 'mark' => 10])['code'] ?? null);
eq('a mark above the total is refused', 'mark', act($pdo, 901, 'mark_work', ['enrolment_id' => $e['id'], 'item_id' => $essay, 'mark' => 11])['field'] ?? null);
$r = act($pdo, 901, 'mark_work', ['enrolment_id' => $e['id'], 'item_id' => $essay, 'mark' => 4, 'comment' => 'Add the scriptures.']);
eq('below the pass mark it goes back', [false, 'returned'], [$r['passed'] ?? null, trainings_progress_map($pdo, $e['id'])[$essay]['status']]);
eq('…and they hand it in again', 'success', act($pdo, 904, 'submit_work', ['item_id' => $essay, 'answers' => ['Grace', 'John 3:16; Eph 2:8', 'Daily']])['status']);
eq('…keeping the first attempt in its history', 4, trainings_progress_map($pdo, $e['id'])[$essay]['data']['history'][0]['mark']);
$r = act($pdo, 901, 'mark_work', ['enrolment_id' => $e['id'], 'item_id' => $essay, 'mark' => 8, 'comment' => 'Good.']);
eq('at the pass mark it is completed', [true, 'completed'], [$r['passed'] ?? null, trainings_progress_map($pdo, $e['id'])[$essay]['status']]);
eq('the progress words are the ones PR 2 writes', ['completed'], array_values(array_unique(array_column(trainings_progress_map($pdo, $e['id']), 'status'))));
eq('not complete while a required live class is missing (50% of 1)', 'active', trainings_enrolment_get($pdo, $e['id'])['status']);
$r = act($pdo, 901, 'tutor_done', ['enrolment_id' => $e['id'], 'item_id' => (int) $live['id'], 'done' => 1]);
eq('a tutor ticks the class attended: complete', [true, 'completed'], [$r['completed'] ?? null, trainings_enrolment_get($pdo, $e['id'])['status']]);
eq('the optional reading never mattered', false, isset(trainings_progress_map($pdo, $e['id'])[$optional]));
eq('…and the learner is told', 1, trn_count($pdo, 'system_notifications', "user_id = 904 AND title = 'Training complete'"));
eq('a tutor cannot tick an assignment', 'error', act($pdo, 901, 'tutor_done', ['enrolment_id' => $e['id'], 'item_id' => $essay, 'done' => 1])['status']);
eq('my trainings show it complete, 5 of 5', ['completed', 5, 5, 100], [act($pdo, 904, 'mine')['mine'][0]['status'],
    act($pdo, 904, 'mine')['mine'][0]['done'], act($pdo, 904, 'mine')['mine'][0]['required'], act($pdo, 904, 'mine')['mine'][0]['percent']]);
$l = array_values(array_filter(act($pdo, 901, 'learners', ['training_id' => $weeks])['learners'], static fn ($x) => $x['user_id'] === 904))[0];
eq('the Academy sees the same', [5, 5, 'completed'], [$l['done'], $l['required'], $l['status']]);
eq('an individual training is never "confirmed"', [0, null], [act($pdo, 901, 'confirm', ['enrolment_ids' => [$e['id']]])['confirmed'] ?? null,
    trainings_enrolment_get($pdo, $e['id'])['confirmed_at']]);

// ---------------------------------------------------------------------------
section('The Academy confirms official classes (owner answer 4)');
$fill = static function (int $tid) use ($pdo): void {
    foreach (trainings_items_flat($pdo, $tid) as $i) {
        $d = match ($i['type']) {
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
            'reading' => ['text' => 'Read.'],
            'assignment' => ['assignment_kind' => $i['details']['kind'], 'prompts' => implode("\n", $i['details']['prompts']),
                'media_source' => 'link', 'media_url' => 'https://youtu.be/dQw4w9WgXcQ'],
            default => [],
        };
        act($pdo, 901, 'item_save', ['training_id' => $tid, 'id' => $i['id'], 'title' => $i['title'], 'section_id' => $i['section_id']] + $d);
    }
};
$fill($mem);
eq('Membership Class publishes once filled', 'success', act($pdo, 901, 'publish', ['training_id' => $mem])['status']);
act($pdo, 901, 'assign', ['training_id' => $mem, 'users' => [903, 906]]);
$finish = static function (int $tid, int $uid) use ($pdo): void {
    $e = trainings_enrolment_for($pdo, $tid, $uid);
    foreach (trainings_items_flat($pdo, $tid) as $i) {
        if ($i['type'] === 'assignment') {
            act($pdo, $uid, 'submit_work', ['item_id' => $i['id'], 'answers' => array_fill(0, max(1, count($i['details']['prompts'])), 'My answer')]);
            act($pdo, 901, 'mark_work', ['enrolment_id' => $e['id'], 'item_id' => $i['id'], 'mark' => 9]);
        } elseif ($i['type'] === 'live') {
            act($pdo, 901, 'tutor_done', ['enrolment_id' => $e['id'], 'item_id' => $i['id'], 'done' => 1]);
        } else {
            act($pdo, $uid, 'item_done', ['item_id' => $i['id'], 'done' => 1]);
        }
    }
};
$finish($mem, 903);
$finish($mem, 906);
$queue = act($pdo, 901, 'to_confirm')['people'];
eq('both graduates wait for the click', [903, 906], array_column($queue, 'user_id'));
eq('…each with what confirming will do', ['Becomes a Member', 'Stays Worker'], array_column($queue, 'outcome'));
eq('nothing has changed yet', '1st_Timer', ladder_status_of($pdo, 903) ?? $pdo->query('SELECT spiritual_status FROM users WHERE id = 903')->fetchColumn());
eq('a member cannot confirm', 'FORBIDDEN', act($pdo, 904, 'confirm', ['enrolment_ids' => array_column($queue, 'id')])['code'] ?? null);
trn_as(902);
eq('nor can a worker of another department', 'FORBIDDEN', trainings_action($pdo, 'confirm', ['enrolment_ids' => array_column($queue, 'id')], [], 902)['code'] ?? null);
$r = act($pdo, 900, 'confirm', ['enrolment_ids' => array_column($queue, 'id')]);
eq('the Resident Pastor confirms both: one moves', [2, 1], [$r['confirmed'] ?? null, $r['moved'] ?? null]);
eq('the 1st Timer is now a Member', 'Member', $pdo->query('SELECT spiritual_status FROM users WHERE id = 903')->fetchColumn());
$h = $pdo->query("SELECT from_status, to_status, source, changed_by FROM user_status_history WHERE user_id = 903")->fetchAll();
eq('…recorded in the status history', [['from_status' => '1st_Timer', 'to_status' => 'Member', 'source' => 'training_membership', 'changed_by' => 900]],
    array_map(static fn ($r) => ['from_status' => $r['from_status'], 'to_status' => $r['to_status'], 'source' => $r['source'], 'changed_by' => (int) $r['changed_by']], $h));
eq('a Worker is never lowered', 'Worker', $pdo->query('SELECT spiritual_status FROM users WHERE id = 906')->fetchColumn());
eq('confirming again changes nothing', [2, 0], [act($pdo, 900, 'confirm', ['enrolment_ids' => array_column($queue, 'id')])['confirmed'] ?? null,
    act($pdo, 900, 'confirm', ['enrolment_ids' => array_column($queue, 'id')])['moved'] ?? null]);
eq('the queue is empty', [], act($pdo, 901, 'to_confirm')['people']);
$fill($mat);
act($pdo, 901, 'publish', ['training_id' => $mat]);
act($pdo, 901, 'assign', ['training_id' => $mat, 'users' => [904]]);
$finish($mat, 904);
eq('Maturity Class: not ready to serve before the click', false, trainings_ready_to_serve($pdo, 904));
eq('…outcome in words', ['Ready to serve'], array_column(act($pdo, 901, 'to_confirm')['people'], 'outcome'));
act($pdo, 901, 'confirm', ['enrolment_ids' => [trainings_enrolment_for($pdo, $mat, 904)['id']]]);
eq('…ready to serve after it, status untouched', [true, 'Member'], [trainings_ready_to_serve($pdo, 904),
    $pdo->query('SELECT spiritual_status FROM users WHERE id = 904')->fetchColumn()]);

// ---------------------------------------------------------------------------
section('Hooks for PR 2 (exams) and PR 3 (Google Meet)');
$ex = (int) act($pdo, 901, 'create', ['title' => 'Exam course', 'length' => 'self_paced'])['id'];
$exSec = (int) trainings_outline($pdo, $ex)[0]['id'];
if (!function_exists('exam_url_setup')) {
    eq('before PR 2, the builder offers no exam', false, in_array('exam', trainings_builder_types(), true));
    eq('…refuses to make one', 'type', act($pdo, 901, 'item_save', ['training_id' => $ex, 'type' => 'exam', 'title' => 'X', 'section_id' => $exSec])['field'] ?? null);
    eq('…and has no exam links', [null, null], [trainings_exam_setup_url(1), trainings_exam_take_url(1)]);
    // PR 2's two URL helpers (includes/exams/engine.php), as it names them, for the rest of this run.
    function exam_url_setup(int $itemId): string
    {
        return '/modules/exams/setup?item=' . $itemId;
    }
    function exam_url_take(int $itemId): string
    {
        return '/modules/exams/take?item=' . $itemId;
    }
}
ok('with PR 2, the builder offers exams', in_array('exam', trainings_builder_types(), true));
$examItem = (int) act($pdo, 901, 'item_save', ['training_id' => $ex, 'type' => 'exam', 'title' => 'Final exam', 'section_id' => $exSec])['id'];
$exRead = (int) act($pdo, 901, 'item_save', ['training_id' => $ex, 'type' => 'reading', 'title' => 'Notes', 'section_id' => $exSec, 'text' => 'Read.'])['id'];
eq('exam links go through PR 2\'s helpers', [exam_url_setup($examItem), exam_url_take($examItem)],
    [trainings_exam_setup_url($examItem), trainings_exam_take_url($examItem)]);
$builderSrc = (string) file_get_contents($root . '/modules/academy/training.php');
ok('the builder and the learner page never hard-code an exam path', !str_contains($builderSrc, '/modules/exams/')
    && !str_contains((string) file_get_contents($root . '/modules/trainings/index.php'), '/modules/exams/')
    && str_contains($builderSrc, 'trainings_exam_setup_url('));
// 12 Nov 2026: the item form's Card picker had id "trb-section", the same as the Edit card dialog, so
// HOD.dialog.open('trb-section') found the <select> and the dialog never opened (cards could not be renamed).
preg_match_all("/'id' => '([a-z0-9-]+)'|ui_dialog_start\\('([a-z0-9-]+)'|\\bid=\"([a-z0-9-]+)\"/", $builderSrc, $idm);
$builderIds = array_values(array_filter(array_merge($idm[1], $idm[2], $idm[3])));
$dupIds = array_keys(array_filter(array_count_values($builderIds), static fn ($n) => $n > 1));
ok('every id on the builder page is used once (the Edit card dialog opens)', $dupIds === [] && in_array('trb-section', $builderIds, true),
    implode(', ', $dupIds));
eq('an exam item needs no content here (PR 2 sets it up)', 'success', act($pdo, 901, 'publish', ['training_id' => $ex])['status']);
act($pdo, 901, 'assign', ['training_id' => $ex, 'users' => [904, 902]]);
$ee = trainings_enrolment_for($pdo, $ex, 904);
act($pdo, 904, 'item_done', ['item_id' => $exRead, 'done' => 1]);
eq('a tutor never ticks an exam', 'error', act($pdo, 901, 'tutor_done', ['enrolment_id' => $ee['id'], 'item_id' => $examItem, 'done' => 1])['status']);
$pr2 = static function (int $enrolmentId, int $itemId, array $data) use ($pdo): void {
    // exam_progress_hook() exactly as #177 writes it: no updated_at.
    $upd = $pdo->prepare("UPDATE training_progress SET status = 'completed', completed_at = ?, data = ? WHERE enrolment_id = ? AND item_id = ?");
    $upd->execute(['2026-12-01 10:00:00', json_encode($data), $enrolmentId, $itemId]);
    if ($upd->rowCount() === 0) {
        $pdo->prepare("INSERT INTO training_progress (enrolment_id, item_id, status, completed_at, data) VALUES (?, ?, 'completed', ?, ?)")
            ->execute([$enrolmentId, $itemId, '2026-12-01 10:00:00', json_encode($data)]);
    }
};
$pr2($ee['id'], $examItem, ['score' => 3, 'max' => 10, 'percent' => 30, 'passed' => false, 'attempt_id' => 1]);
eq('PR 2\'s insert (without updated_at) is accepted', 1, trn_count($pdo, 'training_progress', 'enrolment_id = ? AND item_id = ?', [$ee['id'], $examItem]));
eq('a failed exam does not complete the training', [false, 'active'], [trainings_recheck_enrolment($pdo, $ee['id']), trainings_enrolment_get($pdo, $ee['id'])['status']]);
$seen = array_values(array_filter(act($pdo, 904, 'view', ['training_id' => $ex])['sections'][0]['items'], static fn ($i) => $i['id'] === $examItem))[0];
eq('the learner sees their score, and where to look', [30, false, exam_url_take($examItem)], [$seen['progress']['percent'], $seen['progress']['passed'], $seen['details']['take_url']]);
$pr2($ee['id'], $examItem, ['score' => 8, 'max' => 10, 'percent' => 80, 'passed' => true, 'attempt_id' => 1]);
eq('a passed exam completes it when PR 2 calls trainings_recheck_enrolment()', [true, 'completed'],
    [trainings_recheck_enrolment($pdo, $ee['id']), trainings_enrolment_get($pdo, $ee['id'])['status']]);
$e2 = trainings_enrolment_for($pdo, $ex, 902);
act($pdo, 902, 'item_done', ['item_id' => $exRead, 'done' => 1]);
$pr2($e2['id'], $examItem, ['score' => 9, 'max' => 10, 'percent' => 90, 'passed' => true, 'attempt_id' => 2]);
eq('…or on the learner\'s next look, if nobody called it', 'completed',
    array_values(array_filter(act($pdo, 902, 'mine')['mine'], static fn ($m) => $m['id'] === $ex))[0]['status']);
$lv = (int) act($pdo, 901, 'item_save', ['training_id' => $ex, 'type' => 'live', 'title' => 'Online class', 'section_id' => $exSec,
    'live_mode' => 'online', 'link' => 'https://zoom.us/j/123'])['id'];
$d = trainings_item_get($pdo, $lv)['details'];
$d['meet_url'] = 'https://meet.google.com/xyz-abcd-efg';
$d['meet_event_id'] = 'evt-1';
$pdo->prepare('UPDATE training_items SET details = ? WHERE id = ?')->execute([json_encode($d), $lv]);   // PR 3 creates the Meet
act($pdo, 901, 'item_save', ['training_id' => $ex, 'id' => $lv, 'title' => 'Online class (week 2)', 'section_id' => $exSec,
    'live_mode' => 'online', 'link' => 'https://zoom.us/j/123', 'venue' => '']);
$d = trainings_item_get($pdo, $lv)['details'];
eq('editing the item keeps PR 3\'s Meet fields', ['https://meet.google.com/xyz-abcd-efg', 'evt-1', 'https://zoom.us/j/123'],
    [$d['meet_url'] ?? null, $d['meet_event_id'] ?? null, $d['link'] ?? null]);
$seen = array_values(array_filter(act($pdo, 904, 'view', ['training_id' => $ex])['sections'][0]['items'], static fn ($i) => $i['id'] === $lv))[0];
eq('the learner joins PR 3\'s Meet before a pasted link', 'https://meet.google.com/xyz-abcd-efg', $seen['details']['link']);

// ---------------------------------------------------------------------------
section('Visitor invites: one tap confirms the email and sets a password (owner answer 13)');
$inv = static fn (array $in): array => act($pdo, 901, 'invite', $in + ['training_id' => $weeks]);
eq('someone who can sign in is assigned, not invited', 'HAS_SIGNIN', $inv(['user_id' => 904, 'email' => 'dayo@gmail.com'])['code'] ?? null);
eq('a church address is refused', 'email', $inv(['user_id' => 907, 'email' => 'gbenga@hodlekki.org'])['field'] ?? null);
eq('a member cannot invite', 'FORBIDDEN', act($pdo, 904, 'invite', ['training_id' => $weeks, 'user_id' => 907, 'email' => 'g@gmail.com'])['code'] ?? null);
$sent = trainings_invite_send($pdo, trainings_get($pdo, $weeks), trainings_enrolment_get($pdo, trainings_enrol($pdo, $weeks, 907, 'invite', 901)['id']),
    'Gbenga.Visitor@Gmail.com', 901);
eq('an invite goes out', true, $sent['ok']);
eq('…as account mail to that address', 1, trn_count($pdo, 'email_outbox', "flow = 'training_invite' AND to_email = 'gbenga.visitor@gmail.com' AND purpose = 'account'"));
eq('…the token is stored only as its hash', 1, trn_count($pdo, 'training_invites', 'token_hash = ?', [hash('sha256', $sent['token'])]));
eq('the person was enrolled back (invite)', ['active', 'invite'], [trainings_enrolment_for($pdo, $weeks, 907)['status'], trainings_enrolment_for($pdo, $weeks, 907)['source']]);
$r = act($pdo, 901, 'invite', ['training_id' => $weeks, 'user_id' => 907, 'email' => 'gbenga.visitor@gmail.com']);
$token = $sent['token'];
eq('a newer link replaces the older one', 'used', trainings_invite_peek($pdo, $token)['state']);
$token = (string) trainings_invite_send($pdo, trainings_get($pdo, $weeks), trainings_enrolment_for($pdo, $weeks, 907), 'gbenga.visitor@gmail.com', 901)['token'];
eq('three links a day at most', 'Three links went today. Try tomorrow.',
    trainings_invite_send($pdo, trainings_get($pdo, $weeks), trainings_enrolment_for($pdo, $weeks, 907), 'gbenga.visitor@gmail.com', 901)['message'] ?? null);
eq('the link opens (GET never uses it)', ['valid', 'valid'], [trainings_invite_peek($pdo, $token)['state'], trainings_invite_peek($pdo, $token)['state']]);
eq('the two passwords must match', 'MISMATCH', trainings_invite_accept($pdo, $token, 'Strong-pass-2026!', 'Strong-pass-2027!')['code'] ?? null);
eq('the password policy applies', 'WEAK', trainings_invite_accept($pdo, $token, 'abc', 'abc')['code'] ?? null);
$acc = trainings_invite_accept($pdo, $token, 'Strong-pass-2026!', 'Strong-pass-2026!', '203.0.113.9');
eq('accepting works', [true, $weeks], [$acc['ok'], $acc['training_id'] ?? null]);
ok('…the password is set', password_verify('Strong-pass-2026!', (string) $pdo->query('SELECT password_hash FROM users WHERE id = 907')->fetchColumn()));
eq('…the email is confirmed (they sign in with it, resets go there)', 'gbenga.visitor@gmail.com', member_confirmed_email($pdo, 907));
eq('…and the link is spent', ['used', 'USED'], [trainings_invite_peek($pdo, $token)['state'],
    trainings_invite_accept($pdo, $token, 'Strong-pass-2026!', 'Strong-pass-2026!')['code'] ?? null]);
eq('now they can sign in, so no more invites', 'HAS_SIGNIN', $inv(['user_id' => 907, 'email' => 'gbenga.visitor@gmail.com'])['code'] ?? null);
$pdo->exec("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, account_status, created_at)
            VALUES (909, 'Ifeoma', 'Test', '1st_Timer', 'Active', 'active', '2026-12-01 09:00:00')");
eq('an email someone else confirmed is refused', 'email', $inv(['user_id' => 909, 'email' => 'gbenga.visitor@gmail.com'])['field'] ?? null);
$late = (string) trainings_invite_send($pdo, trainings_get($pdo, $weeks), trainings_enrolment_get($pdo, trainings_enrol($pdo, $weeks, 909, 'invite', 901)['id']),
    'ifeoma@gmail.com', 901)['token'];
$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-12-09 10:00:01', new DateTimeZone('Africa/Lagos'));
eq('a link works for 7 days', ['expired', 'EXPIRED'], [trainings_invite_peek($pdo, $late)['state'],
    trainings_invite_accept($pdo, $late, 'Strong-pass-2026!', 'Strong-pass-2026!')['code'] ?? null]);
$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Africa/Lagos'));
eq('a made-up link is invalid', 'invalid', trainings_invite_peek($pdo, str_repeat('A', 43))['state']);
// Staff access is never handed out by email link: whoever reads the typed address would sign in with it.
$pdo->exec("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, account_status, created_at)
            VALUES (910, 'Kemi', 'Test', 'Member', 'Active', 'active', '2026-12-01 09:00:00'), (911, 'Lanre', 'Test', 'Member', 'Active', 'active', '2026-12-01 09:00:00')");
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (910, 3)");
perm_reset();
eq('someone with a role and no password is not invited', 'STAFF', $inv(['user_id' => 910, 'email' => 'kemi.other@gmail.com'])['code'] ?? null);
ok('…and no link is made for them', trn_count($pdo, 'training_invites', 'user_id = 910') === 0);
$before = (string) trainings_invite_send($pdo, trainings_get($pdo, $weeks), trainings_enrolment_get($pdo, trainings_enrol($pdo, $weeks, 911, 'invite', 901)['id']),
    'lanre@gmail.com', 901)['token'];
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (911, 3)");
perm_reset();
eq('a role given after the link went out stops it', ['staff', 'STAFF'], [trainings_invite_peek($pdo, $before)['state'],
    trainings_invite_accept($pdo, $before, 'Strong-pass-2026!', 'Strong-pass-2026!')['code'] ?? null]);
ok('…and no password is set', (string) $pdo->query('SELECT password_hash FROM users WHERE id = 911')->fetchColumn() === '');
$pdo->exec("INSERT INTO users (id, first_name, last_name, spiritual_status, attendance_status, account_status, created_at)
            VALUES (912, 'Musa', 'Test', 'Worker', 'Active', 'active', '2026-12-01 09:00:00')");
$pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (912, 20, 1, 'HOD')");
perm_reset();
eq('a department seat counts too', 'STAFF', $inv(['user_id' => 912, 'email' => 'musa@gmail.com'])['code'] ?? null);
$noMail = trn_db();
$noMail->exec('DROP TABLE email_outbox');
$noMailT = (int) act($noMail, 901, 'create', ['title' => 'X', 'length' => 'self_paced'])['id'];
eq('before email is set up, no invite (and no enrolment) is made', ['FEATURE_NOT_READY', 0, 0],
    [act($noMail, 901, 'invite', ['training_id' => $noMailT, 'user_id' => 907, 'email' => 'g@gmail.com'])['code'] ?? null,
     trn_count($noMail, 'training_invites'), trn_count($noMail, 'training_enrolments')]);

// ---------------------------------------------------------------------------
section('Archiving and deleting');
eq('deleting a training with people on it asks for an archive', 'HAS_LEARNERS', act($pdo, 901, 'delete', ['training_id' => $weeks])['code'] ?? null);
eq('a member cannot delete', 'FORBIDDEN', act($pdo, 904, 'delete', ['training_id' => $oneDay])['code'] ?? null);
act($pdo, 901, 'archive', ['training_id' => $weeks]);
eq('archived trainings leave My trainings', [], array_column(array_filter(act($pdo, 904, 'mine')['mine'], static fn ($m) => $m['id'] === $weeks), 'id'));
eq('deleting with the learners confirmed removes everything under it', 'success', act($pdo, 901, 'delete', ['training_id' => $weeks, 'confirm_learners' => 1])['status']);
eq('…nothing left behind', [0, 0, 0, 0, 0], [trn_count($pdo, 'training_sections', 'training_id = ?', [$weeks]),
    trn_count($pdo, 'training_items', 'training_id = ?', [$weeks]), trn_count($pdo, 'training_enrolments', 'training_id = ?', [$weeks]),
    trn_count($pdo, 'training_progress', 'item_id = ?', [$video]), trn_count($pdo, 'training_invites', 'user_id = 909')]);

// ---------------------------------------------------------------------------
section('PowerPoint decks: slides in the page, their text kept for search and the AI');

/**
 * A small .pptx on disk. $slides: slide number => list of paragraphs; $order: the slide numbers in
 * presentation order (null: no presentation.xml, so slideN order); $mainType: the main part's type.
 */
function trn_pptx(string $path, array $slides, ?array $order = null, string $mainType = TRAININGS_PPTX_MAIN_TYPE, array $raw = []): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="' . $mainType . '"/></Types>';
    $zip->addFromString('[Content_Types].xml', $types);
    $a = 'xmlns:a="' . TRAININGS_PPTX_NS_A . '" xmlns:p="' . TRAININGS_PPTX_NS_P . '"';
    foreach ($slides as $n => $paras) {
        $body = '';
        foreach ($paras as $para) {
            $runs = '';
            foreach ((array) $para as $run) {
                $runs .= '<a:r><a:t>' . htmlspecialchars($run, ENT_XML1) . '</a:t></a:r>';
            }
            $body .= '<a:p>' . $runs . '</a:p>';
        }
        $zip->addFromString("ppt/slides/slide{$n}.xml", $raw[$n] ?? '<?xml version="1.0"?><p:sld ' . $a . '><p:cSld><p:spTree><p:sp><p:txBody>' . $body
            . '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>');
    }
    if ($order !== null) {
        $rels = '<?xml version="1.0"?><Relationships xmlns="' . TRAININGS_PPTX_NS_PKG_REL . '">';
        $ids = '';
        foreach ($order as $i => $n) {
            $rels .= '<Relationship Id="rId' . (10 + $n) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/>';
            $ids .= '<p:sldId id="' . (256 + $i) . '" r:id="rId' . (10 + $n) . '"/>';
        }
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels . '</Relationships>');
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0"?><p:presentation xmlns:p="' . TRAININGS_PPTX_NS_P . '" xmlns:r="'
            . TRAININGS_PPTX_NS_R . '"><p:sldIdLst>' . $ids . '</p:sldIdLst></p:presentation>');
    }
    $zip->close();
    return $path;
}

ok('ZipArchive is there (CI loads the zip extension)', class_exists('ZipArchive'));
$tmpDir = sys_get_temp_dir() . '/hod-trn-' . bin2hex(random_bytes(4));
mkdir($tmpDir);
$deck1 = trn_pptx($tmpDir . '/Day_1.Workers_Training.pptx', [1 => [['Welcome'], ['Why we ', 'serve']], 2 => [['The second slide']],
    3 => [['Third']]], [3, 1, 2]);
eq('a real deck passes the check', true, trainings_pptx_check($deck1));
$exe = $tmpDir . '/virus.pptx';
file_put_contents($exe, "MZ\x90\x00" . str_repeat("\x00", 200));
$docx = trn_pptx($tmpDir . '/letter.pptx', [1 => [['x']]], [1], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml');
$pptm = trn_pptx($tmpDir . '/macro.pptx', [1 => [['x']]], [1], 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml');
$zipOnly = $tmpDir . '/plain.pptx';
$z = new ZipArchive();
$z->open($zipOnly, ZipArchive::CREATE);
$z->addFromString('readme.txt', 'hello');
$z->close();
ok('a renamed .exe, a .docx, a macro .pptm and a plain zip are refused', !trainings_pptx_check($exe) && !trainings_pptx_check($docx)
    && !trainings_pptx_check($pptm) && !trainings_pptx_check($zipOnly));
ok('the upload sniff uses the same check', trainings_sniff($deck1, 'pptx') && !trainings_sniff($exe, 'pptx') && !trainings_sniff($docx, 'pptx'));
ok('…and the zip magic first', trainings_sniff_bytes("PK\x03\x04rest", 'pptx') && !trainings_sniff_bytes("MZ\x90\x00", 'pptx'));
eq('a deck over 30 MB is refused before anything is stored', 'The file is too big. Use up to 30 MB.',
    trainings_upload(['name' => 'big.pptx', 'tmp_name' => $deck1, 'error' => UPLOAD_ERR_OK, 'size' => 31 * 1048576], 'pptx')['error'] ?? null);
$text = trainings_pptx_text($deck1);
eq('the text follows the presentation\'s slide order, paragraphs on lines', [true, 3,
    "Slide 1\nThird\n\nSlide 2\nWelcome\nWhy we serve\n\nSlide 3\nThe second slide"], [$text['ok'], $text['slides'], $text['text']]);
$noOrder = trn_pptx($tmpDir . '/loose.pptx', [10 => [['Ten']], 2 => [['Two']], 1 => [['One']]]);
eq('without a slide list, slideN by N (2 before 10)', "Slide 1\nOne\n\nSlide 2\nTwo\n\nSlide 3\nTen", trainings_pptx_text($noOrder)['text']);
$evil = trn_pptx($tmpDir . '/evil.pptx', [1 => [['ok']], 2 => [['x']]], [1, 2], TRAININGS_PPTX_MAIN_TYPE,
    [2 => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><p:sld xmlns:a="' . TRAININGS_PPTX_NS_A
        . '" xmlns:p="' . TRAININGS_PPTX_NS_P . '"><a:p><a:r><a:t>&e;</a:t></a:r></a:p></p:sld>']);
eq('a slide with a DOCTYPE is read as empty (no entity is ever expanded)', "Slide 1\nok\n\nSlide 2", trainings_pptx_text($evil)['text']);
$long = trn_pptx($tmpDir . '/long.pptx', array_fill(1, 30, [[str_repeat('word ', 1000)]]), range(1, 30));
$lt = trainings_pptx_text($long);
ok('the text is capped at about 100k characters', $lt['truncated'] && mb_strlen($lt['text']) <= TRAININGS_PPTX_MAX_CHARS);
eq('a name from a file name', ['Day 1 Workers Training', 'Slides'], [trainings_title_from_file('Day_1.Workers_Training.pptx'), trainings_title_from_file('.pptx')]);

$sl = (int) act($pdo, 901, 'create', ['title' => 'Workers\' training', 'length' => 'days', 'starts_on' => '2026-12-14', 'count' => 7])['id'];
$slDays = array_values(array_filter(trainings_outline($pdo, $sl), static fn ($s) => (int) $s['id'] > 0));
$sec = (int) $slDays[0]['id'];
act($pdo, 901, 'item_save', ['training_id' => $sl, 'type' => 'live', 'title' => 'Opening', 'section_id' => $sec]);
$deck2 = trn_pptx($tmpDir . '/Day_2_Prayer.pptx', [1 => [['Prayer']]], [1]);
$tSl = trainings_get($pdo, $sl);
foreach ([[$deck1, 'Day_1.Workers_Training.pptx'], [$deck2, 'Day_2_Prayer.pptx']] as $i => [$path, $name]) {
    $r = trainings_slides_add($pdo, $tSl, $sec, ['details' => trainings_deck_details($path, '/uploads/trainings/20261201_' . str_repeat((string) ($i + 1), 16) . '.pptx', $name),
        'title' => trainings_title_from_file($name)]);
    ok("Add slides: {$name} becomes a reading", !empty($r['ok']));
}
$cardItems = array_values(array_filter(trainings_items_flat($pdo, $sl), static fn ($i) => $i['section_id'] === $sec));
eq('…named from the files, after what the card already holds, in order', ['Opening', 'Day 1 Workers Training', 'Day 2 Prayer'], array_column($cardItems, 'title'));
eq('…each a reading with its deck, slide count and text', ['reading', 3, true, false],
    [$cardItems[1]['type'], $cardItems[1]['details']['slides'], str_contains($cardItems[1]['details']['slides_text'], 'Why we serve'), $cardItems[1]['needs_content']]);
eq('Add slides refuses a card of another training', 'section_id', trainings_slides_add($pdo, $tSl, $w1, ['details' => [], 'title' => 'x'])['field'] ?? null);
eq('the API needs a file', 'pptx_file', act($pdo, 901, 'slides_add', ['training_id' => $sl, 'section_id' => $sec])['field'] ?? null);
eq('a member cannot add slides', 'FORBIDDEN', act($pdo, 904, 'slides_add', ['training_id' => $sl, 'section_id' => $sec])['code'] ?? null);
act($pdo, 901, 'item_save', ['training_id' => $sl, 'id' => $cardItems[1]['id'], 'title' => 'Day 1', 'section_id' => $sec, 'text' => 'Read before class.']);
eq('editing a reading keeps its deck and text', [true, true], [trainings_item_get($pdo, $cardItems[1]['id'])['details']['deck'] === $cardItems[1]['details']['deck'],
    str_contains(trainings_item_get($pdo, $cardItems[1]['id'])['details']['slides_text'], 'Third')]);
act($pdo, 901, 'item_save', ['training_id' => $sl, 'id' => $cardItems[2]['id'], 'title' => 'Day 2', 'section_id' => $sec, 'text' => '', 'remove_deck' => 1]);
eq('Remove the slides takes the deck and its text away', [false, false, true], [isset(trainings_item_get($pdo, $cardItems[2]['id'])['details']['deck']),
    isset(trainings_item_get($pdo, $cardItems[2]['id'])['details']['slides_text']), trainings_item_get($pdo, $cardItems[2]['id'])['needs_content']]);
act($pdo, 901, 'item_delete', ['training_id' => $sl, 'item_id' => $cardItems[2]['id']]);
eq('a training of slides publishes', 'success', act($pdo, 901, 'publish', ['training_id' => $sl])['status']);
act($pdo, 901, 'assign', ['training_id' => $sl, 'users' => [904]]);
$seen = array_values(array_filter(act($pdo, 904, 'view', ['training_id' => $sl])['sections'][0]['items'], static fn ($i) => $i['id'] === $cardItems[1]['id']))[0];
eq('the learner gets the deck, its name and slide count', [$cardItems[1]['details']['deck'], 'Day_1.Workers_Training.pptx', 3],
    [$seen['details']['deck'], $seen['details']['deck_name'], $seen['details']['slides']]);
ok('…and never the slides\' text', !str_contains(json_encode(act($pdo, 904, 'view', ['training_id' => $sl])), 'Why we serve'));
ok('the builder\'s reply never carries it either', !str_contains(json_encode(act($pdo, 901, 'builder', ['training_id' => $sl])), 'Why we serve'));
ok('…nor the builder page\'s data', str_contains((string) file_get_contents($root . '/modules/academy/training.php'), "'details' => trainings_details_for_browser(\$i['details'])"));
require_once $root . '/includes/trainings/view.php';
$frame = trainings_view_deck((string) $cardItems[1]['details']['deck'], 'Day 1', 'Day_1.pptx', 3);
ok('the deck shows in Microsoft\'s Office viewer, fetched by its absolute address', str_contains($frame,
    'src="https://view.officeapps.live.com/op/embed.aspx?src=' . htmlspecialchars(rawurlencode('https://hodlekki.org' . $cardItems[1]['details']['deck'])) . '"'));
ok('…with a Download button', str_contains($frame, 'Download slides') && str_contains($frame, 'download="Day_1.pptx"'));
eq('only our own uploads are shown', '', trainings_view_deck('https://evil.example/x.pptx', 'x'));
ok('frame-src: the Office viewer joins the embed hosts', TRAININGS_FRAME_HOSTS === ['https://www.youtube-nocookie.com', 'https://open.spotify.com', 'https://view.officeapps.live.com']);
ok('…on the learner page and the builder only', str_contains((string) file_get_contents($root . '/modules/trainings/index.php'), "'frame-src' => TRAININGS_FRAME_HOSTS")
    && str_contains((string) file_get_contents($root . '/modules/academy/training.php'), "'frame-src' => TRAININGS_FRAME_HOSTS")
    && !str_contains((string) file_get_contents($root . '/modules/academy/index.php'), 'frame-src'));
ok('the privacy notice says slides go through Microsoft\'s viewer', str_contains((string) file_get_contents($root . '/privacy.php'), 'Microsoft\'s Office viewer'));
array_map('unlink', glob($tmpDir . '/*') ?: []);
rmdir($tmpDir);

// 12 Nov 2026: optional readings point to free books by address; the addresses are links.
$linked = trainings_view_text("Read it free:\nPDF: https://archive.org/download/x/x.pdf.\nOnline (https://www.gutenberg.org/ebooks/16739)\n\n<script>alert(1)</script> javascript:alert(1) a&b");
ok('a reading\'s web addresses become links that open in a new tab', str_contains($linked,
    '<a class="break-all" href="https://archive.org/download/x/x.pdf" target="_blank" rel="noopener noreferrer nofollow">https://archive.org/download/x/x.pdf</a>.<br>')
    && str_contains($linked, '(<a class="break-all" href="https://www.gutenberg.org/ebooks/16739"'));
ok('…and everything else stays escaped text (no script, no javascript: link)', str_contains($linked, '&lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($linked, 'href="javascript') && str_contains($linked, 'a&amp;b') && substr_count($linked, '<a ') === 2);
eq('an address with a query string keeps it, escaped', '<a class="break-all" href="https://anekopress.com/?p=5130&amp;x=1" target="_blank" rel="noopener noreferrer nofollow">https://anekopress.com/?p=5130&amp;x=1</a>',
    trainings_view_linkify('https://anekopress.com/?p=5130&x=1'));

// ---------------------------------------------------------------------------
section('Certificates (owner answer 14): issued, checked at /verify, reissued, revoked');
$bits = static function (array $m): string {
    $b = '';
    foreach ($m as $row) {
        foreach ($row as $d) {
            $b .= $d ? '1' : '0';
        }
    }
    return $b;
};
// Pinned from the browser library (assets/ui/vendor/qrcode-generator-1.5.0), module for module: versions 4 and 10.
eq('the QR encoder matches the browser library', ['3154b7fc17ab8d98b5f999d71a518fd9da777f8e81a809d01461d140321f3adc',
    'ca104e9e24ec3f623810f191f33a0956766af5edb1efd169eec5b143c52bb514'],
    [hash('sha256', $bits(hod_qr_matrix('https://hodlekki.org/verify?code=CERT-7K2M-9QXA'))), hash('sha256', $bits(hod_qr_matrix(str_repeat('Ab', 100))))]);
$png = hod_qr_png(hod_qr_matrix('https://hodlekki.org/verify?code=CERT-7K2M-9QXA'), 8, 4);
ok('…drawn as a PNG with no GD (33 modules + quiet zone, 8 px each)', str_starts_with($png, "\x89PNG\r\n\x1a\n") && unpack('N', substr($png, 16, 4))[1] === 328);
try {
    hod_qr_matrix(str_repeat('x', 214));
    $tooLong = false;
} catch (LengthException $e) {
    $tooLong = true;
}
ok('…and refuses what it cannot hold', $tooLong);
eq('the QR points at /verify', 'https://hodlekki.org/verify?code=CERT-AAAA-BBBB', trainings_certificate_verify_url('CERT-AAAA-BBBB'));

eq('who signs is a setting: no name in the code, the title "Resident Pastor"', ['signatory_name' => '', 'signatory_title' => 'Resident Pastor', 'signature_image' => ''],
    trainings_settings($pdo));
eq('a member cannot change it', 'FORBIDDEN', act($pdo, 904, 'settings_save', ['signatory_name' => 'X', 'signatory_title' => 'Y'])['code'] ?? null);
eq('a title is needed', 'signatory_title', act($pdo, 901, 'settings_save', ['signatory_name' => 'X', 'signatory_title' => ''])['field'] ?? null);
act($pdo, 901, 'settings_save', ['signatory_name' => 'The Signatory', 'signatory_title' => 'Resident Pastor']);
eq('the Academy (Manage) sets it', 'The Signatory', trainings_settings($pdo)['signatory_name']);

$ct = (int) act($pdo, 901, 'create', ['title' => 'Certified course', 'length' => 'self_paced'])['id'];
$ctItem = (int) act($pdo, 901, 'item_save', ['training_id' => $ct, 'type' => 'reading', 'title' => 'Read', 'section_id' => (int) trainings_outline($pdo, $ct)[0]['id'],
    'text' => 'Read.'])['id'];
act($pdo, 901, 'settings', ['training_id' => $ct, 'title' => 'Certified course', 'gives_certificate' => 1, 'live_percent' => 100]);
act($pdo, 901, 'publish', ['training_id' => $ct]);
act($pdo, 901, 'assign', ['training_id' => $ct, 'users' => [904, 902]]);
$ce = trainings_enrolment_for($pdo, $ct, 904);
act($pdo, 904, 'item_done', ['item_id' => $ctItem, 'done' => 1]);
$cur = trainings_certificate_current($pdo, $ce['id']);
ok('completing it issues the certificate (CERT-XXXX-XXXX)', $cur !== null && preg_match(TRAININGS_CERT_PATTERN, $cur['code']) === 1);
$ceNow = trainings_enrolment_get($pdo, $ce['id']);
eq('…held on the enrolment with its hash', [$cur['code'], $cur['hash']], [$ceNow['certificate_no'], $ceNow['certificate_hash']]);
eq('…the hash covers the code and what was printed', hash('sha256', $cur['code'] . '|' . $ce['id'] . '|' . trainings_json($cur['snapshot'])), $cur['hash']);
eq('…what was printed (a training with no signatories of its own: the Academy\'s signs)', ['learner' => 'Dayo Test', 'training' => 'Certified course',
    'completed_on' => '2026-12-01', 'signatory_name' => 'The Signatory', 'signatory_title' => 'Resident Pastor',
    'signatories' => [['name' => 'The Signatory', 'title' => 'Resident Pastor']]], $cur['snapshot']);
eq('…the learner is told and can download it', [1, $cur['code']], [trn_count($pdo, 'system_notifications', "user_id = 904 AND title = 'Certificate ready'"),
    act($pdo, 904, 'view', ['training_id' => $ct])['enrolment']['certificate']]);
eq('…the Academy sees it', $cur['code'], array_values(array_filter(act($pdo, 901, 'learners', ['training_id' => $ct])['learners'],
    static fn ($l) => $l['user_id'] === 904))[0]['certificate']);
eq('a training that gives none issues none', null, trainings_certificate_current($pdo, trainings_enrolment_for($pdo, $ex, 904)['id']));
eq('…and cannot be issued by hand', 'error', act($pdo, 901, 'certificate_issue', ['enrolment_id' => trainings_enrolment_for($pdo, $ex, 904)['id']])['status']);

$m2 = (int) act($pdo, 901, 'create', ['template' => 'membership', 'starts_on' => '2026-12-06'])['id'];
act($pdo, 901, 'settings', ['training_id' => $m2, 'title' => 'Membership Class', 'gives_certificate' => 1, 'live_percent' => 75]);
$fill($m2);
act($pdo, 901, 'publish', ['training_id' => $m2]);
act($pdo, 901, 'assign', ['training_id' => $m2, 'users' => [905]]);
$finish($m2, 905);
$me2 = trainings_enrolment_for($pdo, $m2, 905);
eq('an official class waits for the Academy\'s confirm', ['completed', null], [$me2['status'], trainings_certificate_current($pdo, $me2['id'])]);
act($pdo, 900, 'confirm', ['enrolment_ids' => [$me2['id']]]);
ok('…and confirming issues it', trainings_certificate_current($pdo, $me2['id']) !== null);

$look = trainings_certificate_lookup($pdo, strtolower($cur['code']));
eq('/verify finds it, in any case', ['valid', 'Dayo Test', 'Certified course', '2026-12-01'], [$look['state'], $look['learner'], $look['training'], $look['completed_on']]);
$reply = trainings_certificate_verify_reply($look);
eq('…as a certificate, with its own labels', ['Training Certificate', $cur['code'], 'Training', 'Awarded to', 'The Signatory, Resident Pastor', false],
    [$reply['document_type'], $reply['reference'], $reply['labels']['department'], $reply['labels']['amount'], $reply['validated_by'], $reply['revoked']]);
eq('an unknown or malformed code is not found', [null, null, null], [trainings_certificate_lookup($pdo, 'CERT-AAAA-AAAA'),
    trainings_certificate_lookup($pdo, 'VRF-A1B2-C3D4'), trainings_certificate_lookup($pdo, "CERT-'; DROP")]);
eq('a member cannot reissue or revoke', ['FORBIDDEN', 'FORBIDDEN'], [act($pdo, 904, 'certificate_issue', ['enrolment_id' => $ce['id']])['code'] ?? null,
    act($pdo, 904, 'certificate_revoke', ['enrolment_id' => $ce['id'], 'reason' => 'x'])['code'] ?? null]);
$r = act($pdo, 901, 'certificate_issue', ['enrolment_id' => $ce['id']]);
$new = trainings_certificate_current($pdo, $ce['id']);
ok('reissuing gives a new code', ($r['status'] ?? '') === 'success' && $new['code'] !== $cur['code'] && ($r['reissued'] ?? false) === true);
eq('…the old one verifies as replaced, not valid', ['replaced', true], [trainings_certificate_lookup($pdo, $cur['code'])['state'],
    trainings_certificate_verify_reply(trainings_certificate_lookup($pdo, $cur['code']))['revoked']]);
eq('revoking needs a reason', 'reason', act($pdo, 901, 'certificate_revoke', ['enrolment_id' => $ce['id'], 'reason' => ''])['field'] ?? null);
act($pdo, 901, 'certificate_revoke', ['enrolment_id' => $ce['id'], 'reason' => 'Issued in error']);
eq('a revoked certificate verifies as revoked, with why', ['revoked', 'Issued in error'],
    [trainings_certificate_lookup($pdo, $new['code'])['state'], trainings_certificate_lookup($pdo, $new['code'])['reason']]);
eq('…and nothing is left to download', [null, null, null], [trainings_enrolment_get($pdo, $ce['id'])['certificate_no'],
    trainings_enrolment_get($pdo, $ce['id'])['certificate_hash'], trainings_certificate_current($pdo, $ce['id'])]);
$pdo->exec("UPDATE users SET first_name = '<b>Bola</b>' WHERE id = 902");
act($pdo, 902, 'item_done', ['item_id' => $ctItem, 'done' => 1]);
$c2 = trainings_certificate_current($pdo, trainings_enrolment_for($pdo, $ct, 902)['id']);
$html = trainings_certificate_html($c2, trainings_settings($pdo));
ok('the certificate page escapes names', str_contains($html, '&lt;b&gt;Bola&lt;/b&gt;') && !str_contains($html, '<b>Bola'));
ok('…and shows the training, the code, who signs and the QR', str_contains($html, 'Certified course') && str_contains($html, $c2['code'])
    && str_contains($html, 'The Signatory') && str_contains($html, 'Resident Pastor') && str_contains($html, 'data:image/svg+xml;base64,'));
$pdf = trainings_certificate_pdf($c2, trainings_settings($pdo));
ok('the PDF comes from dompdf where Composer installed it (CI has none)', $pdf === null || str_starts_with($pdf, '%PDF'));

// The design the owner picked (19 Nov 2026, "Gilded") and who signs, per training.
ok('the design carries the HOD logo, the church, the title and Hodlekki\'s verify line', str_contains($html, 'HOUSEHOLD OF DAVID &#183; LEKKI CENTRE')
    && str_contains($html, 'CERTIFICATE OF COMPLETION') && str_contains($html, 'data:image/png;base64,' . base64_encode((string) file_get_contents($root . '/assets/images/logo_hod.png')))
    && str_contains($html, 'Verify at hodlekki.org/verify') && str_contains($html, 'Given on the 1st of December, 2026'));
$qrSvg = trainings_certificate_qr_svg(trainings_certificate_verify_url($c2['code']), '#1D356A');
$qrN = count(hod_qr_matrix(trainings_certificate_verify_url($c2['code']))) + 4;
ok('…the QR is a vector of the verify link, with a quiet zone', str_contains($html, base64_encode($qrSvg)) && str_contains($qrSvg, 'viewBox="0 0 ' . $qrN . ' ' . $qrN . '"'));
ok('…the seal is the flat gold foil seal: a saw-tooth edge, no ribbons', substr_count(trainings_certificate_seal_svg(), '<polygon') === 1
    && str_contains($html, base64_encode(trainings_certificate_seal_svg())));
$fontsCss = trainings_certificate_fonts_css();
ok('…its fonts ship with the site (SIL OFL), every one of them', substr_count($fontsCss, '@font-face') === count(TRAININGS_CERT_FONTS)
    && is_file($root . '/assets/fonts/certificate/OFL-cinzel.txt') && is_file($root . '/assets/fonts/certificate/OFL-ebgaramond.txt')
    && is_file($root . '/assets/fonts/certificate/OFL-pinyonscript.txt'));
$long = trainings_certificate_html(['code' => 'CERT-AAAA-BBBB', 'snapshot' => ['learner' => str_repeat('Oluwaseun ', 6), 'training' => str_repeat('Long title ', 10),
    'completed_on' => '2026-11-19']], trainings_settings($pdo), []);
ok('a long name gets smaller (never under 24pt) and a long title wraps smaller', str_contains($long, 'font-size:24pt;') && str_contains($long, 'font-size:16pt;')
    && str_contains($long, 'Given on the 19th of November, 2026') && !str_contains($long, 'class="sig"'));

eq('a signatory needs a title', 'signer1_title', act($pdo, 901, 'settings', ['training_id' => $ct, 'title' => 'Certified course', 'gives_certificate' => 1,
    'live_percent' => 100, 'signer1_name' => 'Pastor A', 'signer1_title' => ''])['field'] ?? null);
eq('…and nothing was saved', [], trainings_signatories($pdo, $ct));
act($pdo, 901, 'settings', ['training_id' => $ct, 'title' => 'Certified course', 'gives_certificate' => 1, 'live_percent' => 100,
    'signer1_name' => 'Pastor Sola Osunmakinde', 'signer1_title' => 'Senior Pastor, Household of David',
    'signer2_name' => 'Pastor Muyiwa Ogunfowora', 'signer2_title' => 'Rector, HOD Academy']);
eq('a training names its own two signatories in its Settings', [1 => 'Pastor Sola Osunmakinde', 2 => 'Pastor Muyiwa Ogunfowora'],
    array_map(static fn ($s) => $s['name'], trainings_signatories($pdo, $ct)));
eq('…a member cannot', 'FORBIDDEN', act($pdo, 904, 'settings', ['training_id' => $ct, 'title' => 'x', 'signer1_title' => 'x'])['code'] ?? null);
act($pdo, 901, 'assign', ['training_id' => $ct, 'users' => [906]]);
act($pdo, 906, 'item_done', ['item_id' => $ctItem, 'done' => 1]);
$c3 = trainings_certificate_current($pdo, trainings_enrolment_for($pdo, $ct, 906)['id']);
eq('…the next certificate names both, the first also where older readers look',
    [[['name' => 'Pastor Sola Osunmakinde', 'title' => 'Senior Pastor, Household of David'], ['name' => 'Pastor Muyiwa Ogunfowora', 'title' => 'Rector, HOD Academy']],
        'Pastor Sola Osunmakinde', 'Senior Pastor, Household of David'],
    [$c3['snapshot']['signatories'], $c3['snapshot']['signatory_name'], $c3['snapshot']['signatory_title']]);
eq('…/verify says both signed', 'Pastor Sola Osunmakinde, Senior Pastor, Household of David; Pastor Muyiwa Ogunfowora, Rector, HOD Academy',
    trainings_certificate_verify_reply(trainings_certificate_lookup($pdo, $c3['code']))['validated_by']);
$drawn = trainings_certificate_signers($pdo, $c3);
eq('…and both are drawn, side by side', ['Pastor Sola Osunmakinde', 'Pastor Muyiwa Ogunfowora'], array_column($drawn, 'name'));
$h3 = trainings_certificate_html($c3, trainings_settings($pdo), $drawn);
ok('…in the left and right places, the seal between', substr_count($h3, 'class="sig"') === 2 && str_contains($h3, 'left:50mm;') && str_contains($h3, 'left:177mm;')
    && str_contains($h3, 'Rector, HOD Academy'));
// Owner, 19 Nov 2026: signatures added after a certificate was issued must be on it (same code).
$c2now = trainings_certificate_current($pdo, (int) $c2['enrolment_id']);
eq('saving who signs re-signs the certificates already issued: the same code, both names, a new hash that covers them',
    [$c2['code'], ['Pastor Sola Osunmakinde', 'Pastor Muyiwa Ogunfowora'], hash('sha256', $c2['code'] . '|' . $c2['enrolment_id'] . '|' . trainings_json($c2now['snapshot'])), $c2now['hash']],
    [$c2now['code'], array_column($c2now['snapshot']['signatories'], 'name'), $c2now['hash'], trainings_enrolment_get($pdo, (int) $c2['enrolment_id'])['certificate_hash']]);
ok('…the learner, training and date stay as printed', [$c2now['snapshot']['learner'], $c2now['snapshot']['training'], $c2now['snapshot']['completed_on']]
    === [$c2['snapshot']['learner'], $c2['snapshot']['training'], $c2['snapshot']['completed_on']] && $c2['hash'] !== $c2now['hash']);
eq('…/verify agrees', 'Pastor Sola Osunmakinde, Senior Pastor, Household of David; Pastor Muyiwa Ogunfowora, Rector, HOD Academy',
    trainings_certificate_verify_reply(trainings_certificate_lookup($pdo, $c2['code']))['validated_by']);
eq('…a revoked or replaced certificate keeps what it said', ['The Signatory', 'The Signatory'],
    [trainings_certificate_lookup($pdo, $new['code'])['signatory_name'], trainings_certificate_lookup($pdo, $cur['code'])['signatory_name']]);
$pdo->prepare('UPDATE training_certificates SET snapshot = ? WHERE id = ?')->execute([trainings_json(['learner' => 'Femi Test', 'training' => 'Certified course',
    'completed_on' => '2026-12-01', 'signatory_name' => '', 'signatory_title' => 'Resident Pastor']), $c3['id']]);
eq('one issued before signatories (a title, no name) is re-signed at its next download, once', [1, ['Pastor Sola Osunmakinde', 'Pastor Muyiwa Ogunfowora'], 0],
    [trainings_certificates_resign($pdo, $ct, (int) $c3['enrolment_id']),
        array_column(trainings_certificate_current($pdo, (int) $c3['enrolment_id'])['snapshot']['signatories'], 'name'),
        trainings_certificates_resign($pdo, $ct, (int) $c3['enrolment_id'])]);
$own = [1 => ['name' => 'Pastor A', 'title' => 'Senior Pastor', 'signature_image' => '/uploads/trainings/signature/20261119_0123456789abcdef.png']];
eq('a signature image is drawn only for the same person in the same place', [null, null],
    array_column(trainings_certificate_signers_drawn(['signatories' => [['name' => 'Pastor B', 'title' => 'Senior Pastor'], ['name' => 'Pastor A', 'title' => 'Senior Pastor']]],
        $own, ['signatory_name' => '', 'signatory_title' => 'Resident Pastor', 'signature_image' => '']), 'image'));
eq('…a signatory with no name signs with their title', 'Resident Pastor', trainings_certificate_named(['signatory_name' => '', 'signatory_title' => 'Resident Pastor'])[0]['title']);
act($pdo, 901, 'settings', ['training_id' => $ct, 'title' => 'Certified course', 'gives_certificate' => 1, 'live_percent' => 100,
    'signer1_name' => '', 'signer1_title' => '', 'signer2_name' => '', 'signer2_title' => '']);
eq('emptying both rows hands it back to the Academy\'s signatory', [[], [['name' => 'The Signatory', 'title' => 'Resident Pastor']]],
    [trainings_signatories($pdo, $ct), trainings_certificate_signers_now($pdo, $ct)]);
eq('…and its certificates with it', ['The Signatory'], array_column(trainings_certificate_current($pdo, (int) $c2['enrolment_id'])['snapshot']['signatories'], 'name'));
act($pdo, 901, 'settings', ['training_id' => $ct, 'title' => 'Certified course', 'gives_certificate' => 1, 'live_percent' => 100, 'signer2_title' => 'Rector']);
eq('…a form that sends one row leaves the other as it is', [2 => 'Rector'], array_map(static fn ($s) => $s['title'], trainings_signatories($pdo, $ct)));
act($pdo, 901, 'settings_save', ['signatory_name' => 'New Signatory', 'signatory_title' => 'Resident Pastor']);
eq('changing the Academy\'s signatory re-signs the trainings it signs for, not one with its own', ['New Signatory', 'Rector'],
    [trainings_certificate_current($pdo, $me2['id'])['snapshot']['signatories'][0]['name'],
        trainings_certificate_current($pdo, (int) $c2['enrolment_id'])['snapshot']['signatories'][0]['title']]);
act($pdo, 901, 'settings_save', ['signatory_name' => 'The Signatory', 'signatory_title' => 'Resident Pastor']);
$gone = (int) act($pdo, 901, 'create', ['title' => 'Signed then deleted', 'length' => 'self_paced'])['id'];
act($pdo, 901, 'settings', ['training_id' => $gone, 'title' => 'Signed then deleted', 'signer1_title' => 'Pastor']);
trainings_delete($pdo, $gone);
eq('deleting a training clears who signed it', 0, trn_count($pdo, 'training_signatories', 'training_id = ' . $gone));
$dlSrc = (string) file_get_contents($root . '/api/trainings_certificate.php');
ok('the download re-signs first, then draws who the certificate names, with their images', str_contains($dlSrc, 'trainings_certificate_signers($pdo, $cert)')
    && strpos($dlSrc, "trainings_certificates_resign(\$pdo, \$e['training_id'], \$e['id'])") < strpos($dlSrc, 'trainings_certificate_pdf('));
$trbPage = (string) file_get_contents($root . '/modules/academy/training.php');
ok('the training\'s Settings tab names up to two signatories, with signature images', str_contains($trbPage, "'signer' . \$p . '_title'")
    && str_contains($trbPage, "'signer' . \$p . '_file'") && str_contains($trbPage, 'TRAININGS_SIGNERS_MAX'));
ok('…and its form posts the images (FormData)', str_contains((string) file_get_contents($root . '/assets/ui/js/pages/academy-training.js'),
    "var body = new FormData(form);   // FormData: the signature images go with it"));
$verifyApi = (string) file_get_contents($root . '/api/public_verify_api.php');
ok('the existing /verify API checks CERT- codes', str_contains($verifyApi, 'trainings_certificate_lookup($pdo, $code)'));
$verifyPage = (string) file_get_contents($root . '/verify.php');
ok('/verify?code=… fills the box and checks it', str_contains($verifyPage, "preg_replace('/[^A-Z0-9-]/', ''") && str_contains($verifyPage, 'data-autoverify')
    && str_contains((string) file_get_contents($root . '/assets/ui/js/pages/verify.js'), 'requestSubmit'));
$dl = (string) file_get_contents($root . '/api/trainings_certificate.php');
ok('the download is the learner\'s own or the Academy\'s, and only a current certificate', str_contains($dl, "can('trainings', 'view', perm_person(\$me))")
    && str_contains($dl, "can('trainings', 'view', 'church')") && str_contains($dl, 'trainings_certificate_current('));

// ---------------------------------------------------------------------------
section('Reminders (owner answer 15): email and push, never SMS, never twice');
$pdo->exec('CREATE TABLE training_exams (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, training_id INTEGER, window_open TEXT)');
$rt = (int) act($pdo, 901, 'create', ['title' => 'Reminder course', 'length' => 'days', 'starts_on' => '2026-12-01', 'count' => 3])['id'];
$rDays = array_values(array_filter(trainings_outline($pdo, $rt), static fn ($s) => (int) $s['id'] > 0));
$rsave = static fn (array $in): int => (int) act($pdo, 901, 'item_save', $in + ['training_id' => $rt])['id'];
$rRead = $rsave(['type' => 'reading', 'title' => 'Before class', 'section_id' => (int) $rDays[0]['id'], 'time' => '08:00', 'text' => 'Read.']);
$rLive = $rsave(['type' => 'live', 'title' => 'Class', 'section_id' => (int) $rDays[1]['id'], 'time' => '10:00']);
$rExam = $rsave(['type' => 'exam', 'title' => 'Test', 'section_id' => (int) $rDays[1]['id']]);
$pdo->prepare('INSERT INTO training_exams (item_id, training_id, window_open) VALUES (?, ?, ?)')->execute([$rExam, $rt, '2026-12-02 14:00:00']);
act($pdo, 901, 'publish', ['training_id' => $rt]);
act($pdo, 901, 'assign', ['training_id' => $rt, 'users' => [904, 902, 906], 'due_on' => '2026-12-02']);
trainings_withdraw($pdo, trainings_enrolment_for($pdo, $rt, 906));
ct_confirm($pdo, 904, 'dayo.learner@gmail.com', false);
$tz = new DateTimeZone('Africa/Lagos');
$run = static fn (string $at): array => trainings_reminders_run($pdo, ['now' => new DateTimeImmutable($at, $tz)]);
$kinds = static fn (array $r): array => array_filter($r['by_kind']);
$outbox0 = trn_count($pdo, 'email_outbox', "flow = 'training_reminder'");
$r = $run('2026-12-01 06:40:00');
eq('the morning run: a class and an exam tomorrow, the training due tomorrow, today\'s reading (not the one taken off)',
    ['class_day' => 2, 'exam_day' => 2, 'due' => 2, 'item_due' => 2], $kinds($r));
eq('…email only to a confirmed personal address', [4, 4], [trn_count($pdo, 'email_outbox', "flow = 'training_reminder'") - $outbox0,
    trn_count($pdo, 'email_outbox', "flow = 'training_reminder' AND to_email = 'dayo.learner@gmail.com'")]);
eq('…push to both (system_notifications fans out to phones)', [4, 4], [trn_count($pdo, 'system_notifications', "user_id = 904 AND title IN
    ('Class tomorrow', 'Exam opens tomorrow', 'Training due tomorrow', 'Due today')"), trn_count($pdo, 'system_notifications', "user_id = 902 AND title IN
    ('Class tomorrow', 'Exam opens tomorrow', 'Training due tomorrow', 'Due today')")]);
eq('…the channels are recorded', ['push,email', 'push'], [(string) $pdo->query("SELECT channels FROM training_reminders r JOIN training_enrolments e ON e.id = r.enrolment_id
    WHERE e.user_id = 904 AND r.kind = 'class_day'")->fetchColumn(), (string) $pdo->query("SELECT channels FROM training_reminders r JOIN training_enrolments e
    ON e.id = r.enrolment_id WHERE e.user_id = 902 AND r.kind = 'class_day'")->fetchColumn()]);
eq('never twice', [0, 8], [$run('2026-12-01 12:00:00')['sent'], $run('2026-12-01 12:00:00')['skipped']]);
$rLate = $rsave(['type' => 'live', 'title' => 'Evening class', 'section_id' => (int) $rDays[1]['id'], 'time' => '18:00']);
eq('quiet hours: nothing from 21:00 to 06:30', [0, 0], [$run('2026-12-01 21:30:00')['sent'], $run('2026-12-02 05:00:00')['sent']]);
eq('…before 21:00 it goes', ['class_day' => 2], $kinds($run('2026-12-01 20:55:00')));
eq('an hour before the class', ['class_hour' => 2], $kinds($run('2026-12-02 09:05:00')));
eq('…by the alert lane, straight away', 'alert', (string) $pdo->query("SELECT lane FROM email_outbox WHERE flow = 'training_reminder' ORDER BY id DESC LIMIT 1")->fetchColumn());
$pr2(trainings_enrolment_for($pdo, $rt, 904)['id'], $rExam, ['score' => 8, 'max' => 10, 'percent' => 80, 'passed' => true, 'attempt_id' => 9]);
eq('an hour before the exam opens, only to who has not sat it', ['exam_hour' => 1], $kinds($run('2026-12-02 13:05:00')));
act($pdo, 901, 'item_save', ['training_id' => $rt, 'id' => $rLive, 'title' => 'Class', 'section_id' => (int) $rDays[1]['id'], 'time' => '11:00']);
eq('a class moved to another time is reminded again', ['class_hour' => 2], $kinds($run('2026-12-02 10:05:00')));
$rDawn = $rsave(['type' => 'live', 'title' => 'Dawn prayer', 'section_id' => (int) $rDays[2]['id'], 'time' => '06:00']);
eq('the hour-before one goes even in quiet hours', ['class_hour' => 2], $kinds($run('2026-12-03 05:05:00')));
act($pdo, 901, 'unpublish', ['training_id' => $rt]);
$rDraft = $rsave(['type' => 'live', 'title' => 'Draft class', 'section_id' => (int) $rDays[2]['id'], 'time' => '12:00']);
eq('a training that is not published reminds nobody', 0, $run('2026-12-02 12:00:00')['sent']);
eq('no SMS, ever', 0, trn_count($pdo, 'sms_queue'));
$tick = (string) file_get_contents($root . '/cron/automation_tick.php');
ok('the */5 cron runs them through a function_exists() guard (no new cron line)', str_contains($tick, "function_exists('trainings_reminders_run')"));
ok('the 06:30 job runs them too', str_contains((string) file_get_contents($root . '/cron/trainings_daily.php'), 'trainings_reminders_run($pdo)'));

// ---------------------------------------------------------------------------
section('The endpoint, pages and copy');
$api = (string) file_get_contents($root . '/api/trainings_api.php');
ok('the API checks the session, the matrix and the CSRF token before any action', strpos($api, "empty(\$_SESSION['user_id'])") < strpos($api, 'csrf_require();')
    && strpos($api, "api_require_can('trainings', 'view');") < strpos($api, 'csrf_require();') && strpos($api, 'csrf_require();') < strrpos($api, 'trainings_action('));
ok('the API answers POST only', str_contains($api, "!== 'POST'"));
$actions = (string) file_get_contents($root . '/includes/trainings/actions.php');
preg_match_all("/case '([a-z_]+)':/", $actions, $m);
foreach ($m[1] as $name) {
    if (in_array($name, ['mine', 'view', 'join', 'leave', 'item_done', 'submit_work', 'note_get', 'note_save'], true)) {
        continue;
    }
    eq("a learner is refused '{$name}'", 'FORBIDDEN', act($pdo, 904, $name, ['training_id' => $mem, 'enrolment_id' => trainings_enrolment_for($pdo, $mat, 904)['id'],
        'item_id' => trainings_items_flat($pdo, $mem)[0]['id'], 'section_id' => (int) trainings_outline($pdo, $mem)[0]['id']])['code'] ?? null);
}
foreach (['modules/trainings/index.php', 'modules/academy/index.php', 'modules/academy/training.php'] as $page) {
    $src = (string) file_get_contents($root . '/' . $page);
    ok("{$page} is a strict page", str_contains($src, "'mode' => 'strict'"));
    ok("{$page} asks can() for trainings", str_contains($src, "require_can('trainings'"));
}
ok('only the pages that play media allow the embed hosts', str_contains((string) file_get_contents($root . '/modules/trainings/index.php'), "'frame-src' => TRAININGS_FRAME_HOSTS")
    && !str_contains((string) file_get_contents($root . '/modules/academy/index.php'), 'frame-src'));
$invitePage = (string) file_get_contents($root . '/auth/training_invite.php');
ok('the invite page uses the link only on a CSRF-checked POST', str_contains($invitePage, 'ui_standalone_csrf_ok()') && str_contains($invitePage, 'trainings_invite_accept('));
ok('…and signs in as a password sign-in does', str_contains($invitePage, 'auth_start_session(') && str_contains($invitePage, 'security_register_session('));
$cron = (string) file_get_contents($root . '/cron/trainings_daily.php');
ok('the daily job is CLI only and sets DOCUMENT_ROOT first', strpos($cron, "PHP_SAPI !== 'cli'") < strpos($cron, "\$_SERVER['DOCUMENT_ROOT']")
    && strpos($cron, "\$_SERVER['DOCUMENT_ROOT']") < strpos($cron, 'includes/db.php'));
ok('the crontab line is in the README', str_contains((string) file_get_contents($root . '/README.md'), 'cron/trainings_daily.php'));
ok('AGENTS.md documents Trainings', str_contains((string) file_get_contents($root . '/AGENTS.md'), '## Trainings'));

// ===========================================================================
section('Standalone tests (includes/trainings/tests.php, docs/EXAMS.md §8)');
// ===========================================================================
require_once $root . '/includes/exams/access.php';

/** PR 2's exam tables, from their migrations (named unique indexes, so the retakes migration can drop one). */
function trn_exam_tables(PDO $pdo): void
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20261209090000_trainings_exams.sql'));
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
    foreach ($tables as [, $name, $body]) {
        $cols = $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line)) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE UNIQUE INDEX {$m[1]} ON {$name} ({$m[2]})";
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
            $line = (string) preg_replace('/\b(TINYINT|INT)\b/', 'INTEGER', $line);
            $line = (string) preg_replace('/\b(VARCHAR|CHAR)\(\d+\)|\bDECIMAL\(\d+,\d+\)/', 'TEXT', $line);
            $line = (string) preg_replace('/\b(MEDIUMTEXT|LONGTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $pdo->exec("CREATE TABLE {$name} (" . implode(', ', $cols) . ')');
        foreach ($indexes as $ix) {
            $pdo->exec($ix);
        }
    }
    foreach (['20261209090100_trainings_exams_review.sql', '20261209090200_trainings_exams_retakes.sql'] as $file) {
        $alters = (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/' . $file);
        preg_match_all("/'ALTER TABLE (\\w+) (ADD COLUMN|ADD UNIQUE KEY|DROP INDEX) ([^']+)'/", $alters, $am, PREG_SET_ORDER);
        foreach ($am as [, $table, $op, $rest]) {
            if ($op === 'ADD COLUMN') {
                $rest = (string) preg_replace('/\\b(TINYINT|INT)\\b/', 'INTEGER', $rest);
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN " . preg_replace('/\\bDATETIME\\b/', 'TEXT', $rest));
            } elseif ($op === 'ADD UNIQUE KEY' && preg_match('/^(\\w+) \\((.+)\\)$/', $rest, $m)) {
                $pdo->exec("CREATE UNIQUE INDEX {$m[1]} ON {$table} ({$m[2]})");
            } elseif ($op === 'DROP INDEX') {
                $pdo->exec('DROP INDEX ' . trim($rest));
            }
        }
    }
}

$pdo = trn_db();
trn_exam_tables($pdo);
ok('the exam tables are there for the tests', exams_ready($pdo) && exams_retakes_ready($pdo));
$GLOBALS['trainings_now'] = new DateTimeImmutable('2026-11-24 18:00:00', new DateTimeZone('Africa/Lagos'));
exams_set_clock(exams_dt('2026-11-24 18:00:00'));
$sotw = trainings_tests_overview($pdo);
eq('no tests yet', [], $sotw);

eq('a member cannot create a test', 'FORBIDDEN', act($pdo, 904, 'test_create', ['title' => 'Mine'])['code'] ?? null);
eq('a test needs a name', 'title', act($pdo, 901, 'test_create', ['title' => '  '])['field'] ?? null);
$made = act($pdo, 901, 'test_create', ['title' => 'School of the Word, November test', 'gives_certificate' => '1']);
ok('the Academy creates a test', ($made['status'] ?? '') === 'success' && (int) ($made['item_id'] ?? 0) > 0, json_encode($made));
$tid = (int) $made['id'];
$itemId = (int) $made['item_id'];
eq('…and goes straight on to its set-up page', '/modules/exams/setup?item=' . $itemId, $made['setup_url'] ?? null);
$t = trainings_get($pdo, $tid);
ok('…a draft training of kind test, out of the catalogue, with a certificate', trainings_is_test($t) && $t['status'] === 'draft' && !$t['catalogue'] && $t['gives_certificate']);
eq('…one card and one required exam item with its name', [['exam', 'School of the Word, November test', 1]],
    array_map(static fn ($i) => [$i['type'], $i['title'], (int) $i['required']], trainings_items_flat($pdo, $tid)));
$item = exam_item($pdo, $itemId);
eq('the exam item knows it is a test', 'test', $item['training_kind'] ?? null);
ok('its link is /test?t=<item>', str_ends_with(trainings_test_url($itemId), '/test?t=' . $itemId));
ok('…and its QR code reads as an image (SVG and PNG)', str_starts_with(trainings_test_qr_svg(trainings_test_url($itemId)), '<svg')
    && str_starts_with(trainings_test_qr_png_uri(trainings_test_url($itemId)), 'data:image/png;base64,'));

// Not in the course lists.
eq('a test is not in the Academy\'s training list', [], array_values(array_filter(trainings_overview($pdo), static fn ($r) => $r['id'] === $tid)));
eq('…it is in the Tests list', [$tid], array_column(trainings_tests_overview($pdo), 'id'));

// Publishing needs questions.
trn_as(901);
eq('publishing with no questions is refused', 'NOT_READY', exams_action($pdo, 'test_publish', ['item' => $itemId, 'publish' => 1], 901)['code'] ?? null);
eq('a draft test is not open to members', null, exams_learner_enrolment($pdo, $item, 904));
$qs = [];
foreach ([['Who wrote Romans?', ['Paul', 'Peter', 'John'], [0]], ['Which are fruits of the Spirit?', ['Love', 'Anger', 'Joy'], [0, 2]],
          ['How many books are in the Bible?', ['66', '72', '39'], [0]], ['Jesus was born in Bethlehem.', null, null]] as [$body, $opts, $right]) {
    $q = $opts === null
        ? exam_question_save($pdo, null, $tid, ['qtype' => 'true_false', 'body' => $body, 'correct_text' => 'True', 'status' => 'approved',
            'explanation' => 'Matthew 2:1.'], 901)
        : exam_question_save($pdo, null, $tid, ['qtype' => count($right) > 1 ? 'multiple' : 'single', 'body' => $body, 'options' => $opts,
            'correct' => $right, 'status' => 'approved', 'explanation' => 'From the class notes.'], 901);
    ok("question saved: {$body}", !empty($q['ok']), json_encode($q['errors'] ?? null));
    $qs[] = (int) $q['id'];
}
$saved = exams_action($pdo, 'exam_save', ['item' => $itemId, 'window_open' => '2026-11-24T18:00', 'window_close' => '2026-11-24T21:00', 'minutes' => 20,
    'pass_percent' => 75, 'attempts_allowed' => 5, 'shuffle' => 1, 'leave_alert' => 3, 'question_ids' => $qs], 901);
eq('a test may allow up to 5 tries', 5, $saved['exam']['attempts_allowed'] ?? null);
eq('…but not 6', 'error', exams_action($pdo, 'exam_save', ['item' => $itemId, 'attempts_allowed' => 6, 'minutes' => 20, 'pass_percent' => 75, 'question_ids' => $qs], 901)['status'] ?? null);
exams_action($pdo, 'exam_save', ['item' => $itemId, 'window_open' => '2026-11-24T18:00', 'window_close' => '2026-11-24T21:00', 'minutes' => 20,
    'pass_percent' => 75, 'attempts_allowed' => 2, 'shuffle' => 1, 'leave_alert' => 3, 'question_ids' => $qs], 901);
eq('a member cannot publish it', 'FORBIDDEN', (function () use ($pdo, $itemId) { trn_as(904); return exams_action($pdo, 'test_publish', ['item' => $itemId, 'publish' => 1], 904)['code'] ?? null; })());
trn_as(901);
$pub = exams_action($pdo, 'test_publish', ['item' => $itemId, 'publish' => 1], 901);
ok('the Academy publishes it', ($pub['status'] ?? '') === 'success' && ($pub['test']['status'] ?? '') === 'published', json_encode($pub));
eq('…the set-up view says it is a test, with its link', trainings_test_url($itemId), $pub['test']['url'] ?? null);
$renamed = exams_action($pdo, 'test_save', ['item' => $itemId, 'title' => 'SOTW November', 'gives_certificate' => 1], 901);
eq('renaming the test renames its exam item too', ['SOTW November', 'SOTW November'],
    [$renamed['test']['title'] ?? null, exam_item($pdo, $itemId)['title'] ?? null]);
$item = exam_item($pdo, $itemId);

// Every member, by opening it.
eq('the review opens at once for a test', ['open' => true, 'when' => 'test', 'at' => null], exam_review_state($pdo, exam_get($pdo, $itemId)));
$forDayo = trainings_tests_for_learner($pdo, 904);
eq('a member sees it under Trainings › Tests, ready to start', [[$tid, 'open', 'start']], array_map(static fn ($x) => [$x['id'], $x['state'], $x['action']], $forDayo));
eq('…and it counts as waiting', 1, trainings_tests_waiting($forDayo));
trn_as(904);
$enrol = exams_learner_enrolment($pdo, $item, 904);
ok('opening it enrols the member (source self)', $enrol !== null && trainings_enrolment_get($pdo, $enrol)['source'] === 'self');
eq('…once', $enrol, exams_learner_enrolment($pdo, $item, 904));
eq('it never shows in My trainings', [], array_values(array_filter(trainings_mine($pdo, 904), static fn ($m) => $m['id'] === $tid)));
eq('…nor in the catalogue', [], array_values(array_filter(trainings_catalogue($pdo, 904), static fn ($c) => $c['id'] === $tid)));

// Try 1: everything wrong. Another try left: which ones they missed, never the answers.
$view = exams_action($pdo, 'start', ['item' => $itemId], 904);
eq('the member starts', 'started', $view['state'] ?? null);
$att = exam_attempt_get($pdo, (int) exam_get($pdo, $itemId)['id'], 904);
$rows = exam_attempt_rows($pdo, $att['id']);
$shownFor = static function (array $r, array $originals): array {
    $pos = array_flip($r['order']);
    return array_map(static fn ($i) => $pos[$i], $originals);
};
foreach ($rows as $r) {
    $wrongPick = array_values(array_diff(array_keys($r['options']), $r['correct']))[0];
    exam_answer($pdo, $att, (int) $r['position'], $shownFor($r, [$wrongPick]));
}
$done = exams_action($pdo, 'submit', ['item' => $itemId], 904);
$res = $done['result'] ?? [];
ok('try 1: not passed, a test, and the questions missed listed', ($res['passed'] ?? true) === false && ($res['test'] ?? false) === true
    && count($res['missed'] ?? []) === 4 && ($res['wrong'] ?? null) === [], json_encode($res));
ok('…with the questions but never the right answers', str_contains(json_encode($res['missed']), 'Who wrote Romans?')
    && !str_contains(json_encode($res), 'From the class notes') && !str_contains(json_encode($res), 'Matthew 2:1'));
eq('…and a try left', [true, 'attempts'], [$done['can_retake'] ?? null, $res['review_when'] ?? null]);
eq('…Trainings › Tests offers Try again', 'retake', trainings_tests_for_learner($pdo, 904)[0]['action']);

// Try 2: all right. Passed: the review opens (nothing wrong to show), the training completes, the certificate is issued.
exams_action($pdo, 'start', ['item' => $itemId], 904);
$att2 = exam_attempt_get($pdo, (int) exam_get($pdo, $itemId)['id'], 904);
foreach (exam_attempt_rows($pdo, $att2['id']) as $r) {
    exam_answer($pdo, $att2, (int) $r['position'], $shownFor($r, $r['correct']));
}
$done2 = exams_action($pdo, 'submit', ['item' => $itemId], 904);
ok('try 2: passed with 100%', ($done2['result']['passed'] ?? false) === true && (float) ($done2['result']['percent'] ?? 0) === 100.0, json_encode($done2['result'] ?? null));
eq('…the test is complete', 'completed', trainings_enrolment_get($pdo, $enrol)['status']);
ok('…the certificate is issued at once and offered on the result', trainings_certificate_current($pdo, $enrol) !== null
    && ($done2['certificate'] ?? '') === '/api/trainings_certificate.php?e=' . $enrol);
$after = trainings_tests_for_learner($pdo, 904)[0];
eq('…Trainings › Tests shows the result, the best score and the certificate', ['result', 100, true],
    [$after['action'], $after['best_percent'], $after['certificate'] !== null]);
eq('…the bell links to the test, not a course page', exam_url_take($itemId),
    $pdo->query("SELECT link_url FROM system_notifications WHERE user_id = 904 ORDER BY id DESC LIMIT 1")->fetchColumn());

// A second member fails on the last try: the right answers show at once.
trn_as(906);
exams_learner_enrolment($pdo, $item, 906);
foreach ([1, 2] as $try) {
    exams_action($pdo, 'start', ['item' => $itemId], 906);
    $a = exam_attempt_get($pdo, (int) exam_get($pdo, $itemId)['id'], 906);
    foreach (exam_attempt_rows($pdo, $a['id']) as $r) {
        exam_answer($pdo, $a, (int) $r['position'], $shownFor($r, [array_values(array_diff(array_keys($r['options']), $r['correct']))[0]]));
    }
    $last = exams_action($pdo, 'submit', ['item' => $itemId], 906);
}
ok('the last try: the right answers and explanations show at once', ($last['result']['review_open'] ?? false) === true
    && count($last['result']['wrong'] ?? []) === 4 && ($last['result']['missed'] ?? null) === []
    && str_contains(json_encode($last['result']['wrong']), 'From the class notes'), json_encode($last['result'] ?? null));
eq('…no certificate without a pass', null, $last['certificate'] ?? null);
$ov = trainings_tests_overview($pdo)[0];
eq('the Academy sees who took it and who passed', [2, 1, 4], [$ov['took'], $ov['passed'], $ov['questions']]);

// After it closes: only the people who sat it still see it.
exams_set_clock(exams_dt('2026-11-24 21:30:00'));
eq('closed: a member who never started no longer sees it', [], trainings_tests_for_learner($pdo, 902));
eq('…one who sat it still sees the result', 'result', trainings_tests_for_learner($pdo, 904)[0]['action'] ?? null);
exams_set_clock(exams_dt('2026-11-24 18:30:00'));
trn_as(901);
exams_action($pdo, 'test_publish', ['item' => $itemId, 'publish' => 0], 901);
eq('unpublished: closed to members again', null, exams_learner_enrolment($pdo, exam_item($pdo, $itemId), 902));
eq('…the attempts are kept', 4, trn_count($pdo, 'training_attempts', 'item_id = ?', [$itemId]));

// Deleting: only while nobody has started.
eq('a test people have taken cannot be deleted', 'STARTED', exams_action($pdo, 'test_delete', ['item' => $itemId], 901)['code'] ?? null);
$spare = act($pdo, 901, 'test_create', ['title' => 'Try-out']);
trn_as(901);
$gone = exams_action($pdo, 'test_delete', ['item' => (int) $spare['item_id']], 901);
ok('a test nobody started is deleted with its exam item', ($gone['status'] ?? '') === 'success' && trainings_get($pdo, (int) $spare['id']) === null
    && exam_item($pdo, (int) $spare['item_id']) === null, json_encode($gone));
$spare = act($pdo, 901, 'test_create', ['title' => 'Try-out 2']);
trn_as(904);
eq('a member cannot delete one', 'FORBIDDEN', exams_action($pdo, 'test_delete', ['item' => (int) $spare['item_id']], 904)['code'] ?? null);
trn_as(901);

// A course exam is untouched.
$course = trainings_create($pdo, ['title' => 'Workers class', 'length' => 'self_paced'], 901);
$pdo->prepare("UPDATE trainings SET status = 'published' WHERE id = ?")->execute([$course['id']]);
$pdo->prepare("INSERT INTO training_items (training_id, section_id, position, type, title, required, details, created_at) VALUES (?, ?, 1, 'exam', 'Final', 1, '{}', '2026-11-24 10:00:00')")
    ->execute([$course['id'], (int) trainings_outline($pdo, $course['id'])[0]['id']]);
$cItem = exam_item($pdo, (int) $pdo->lastInsertId());
ok('a course exam is not a test', $cItem !== null && !exams_item_is_test($cItem));
ok('…a member is not enrolled by opening it', $cItem !== null && exams_learner_enrolment($pdo, $cItem, 904) === null);
unset($GLOBALS['trainings_now']);
exams_set_clock(false);

// The pages.
$setupSrc = (string) file_get_contents($root . '/modules/exams/setup.php');
ok('the set-up page shows a test\'s name, certificate, publish and QR', str_contains($setupSrc, 'exams_test_setup(') && str_contains($setupSrc, 'trainings_test_qr_svg(')
    && str_contains($setupSrc, "'ex.test-publish'"));
$learnerSrc = (string) file_get_contents($root . '/modules/trainings/index.php');
ok('Trainings has a Tests tab for every member', str_contains($learnerSrc, 'trainings_tests_for_learner(') && str_contains($learnerSrc, "'tests' => ['label' => 'Tests'"));
$entry = (string) file_get_contents($root . '/test.php');
ok('/test signs people in first, then opens the test', str_contains($entry, "/auth/login?next=") && str_contains($entry, '/modules/exams/take?item='));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
