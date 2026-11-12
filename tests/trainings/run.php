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
        foreach (trn_schema_sql('20261208090000_trainings_core.sql') as $stmt) {
            $pdo->exec($stmt);
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
eq('…with its link and attendance rule', ['online', 'https://meet.google.com/abc-defg-hij', 45],
    [$liveItem['details']['mode'], $liveItem['details']['meet_url'], $liveItem['details']['present_minutes']]);
eq('a day outside the week is refused', 'date', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'date' => '2026-12-15', 'time' => '09:00'])['field'] ?? null);
eq('a bad time is refused', 'time', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'time' => '25:00'])['field'] ?? null);
eq('a link must be https', 'link', $save(['type' => 'live', 'title' => 'X', 'section_id' => $w1, 'live_mode' => 'online', 'link' => 'http://x.org'])['field'] ?? null);
eq('an exam cannot be made here yet (PR 2)', 'type', $save(['type' => 'exam', 'title' => 'X', 'section_id' => $w1])['field'] ?? null);
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
eq('at the pass mark it is done', [true, 'done'], [$r['passed'] ?? null, trainings_progress_map($pdo, $e['id'])[$essay]['status']]);
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
section('The endpoint, pages and copy');
$api = (string) file_get_contents($root . '/api/trainings_api.php');
ok('the API checks the session, the matrix and the CSRF token before any action', strpos($api, "empty(\$_SESSION['user_id'])") < strpos($api, 'csrf_require();')
    && strpos($api, "api_require_can('trainings', 'view');") < strpos($api, 'csrf_require();') && strpos($api, 'csrf_require();') < strrpos($api, 'trainings_action('));
ok('the API answers POST only', str_contains($api, "!== 'POST'"));
$actions = (string) file_get_contents($root . '/includes/trainings/actions.php');
preg_match_all("/case '([a-z_]+)':/", $actions, $m);
foreach ($m[1] as $name) {
    if (in_array($name, ['mine', 'view', 'join', 'leave', 'item_done', 'submit_work'], true)) {
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
ok('only the pages that play media allow the two embed hosts', str_contains((string) file_get_contents($root . '/modules/trainings/index.php'), "'frame-src' => TRAININGS_EMBED_HOSTS")
    && !str_contains((string) file_get_contents($root . '/modules/academy/index.php'), 'frame-src'));
$invitePage = (string) file_get_contents($root . '/auth/training_invite.php');
ok('the invite page uses the link only on a CSRF-checked POST', str_contains($invitePage, 'ui_standalone_csrf_ok()') && str_contains($invitePage, 'trainings_invite_accept('));
ok('…and signs in as a password sign-in does', str_contains($invitePage, 'auth_start_session(') && str_contains($invitePage, 'security_register_session('));
$cron = (string) file_get_contents($root . '/cron/trainings_daily.php');
ok('the daily job is CLI only and sets DOCUMENT_ROOT first', strpos($cron, "PHP_SAPI !== 'cli'") < strpos($cron, "\$_SERVER['DOCUMENT_ROOT']")
    && strpos($cron, "\$_SERVER['DOCUMENT_ROOT']") < strpos($cron, 'includes/db.php'));
ok('the crontab line is in the README', str_contains((string) file_get_contents($root . '/README.md'), 'cron/trainings_daily.php'));
ok('AGENTS.md documents Trainings', str_contains((string) file_get_contents($root . '/AGENTS.md'), '## Trainings'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
