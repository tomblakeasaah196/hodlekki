<?php
// /tests/academy_forum_access_test.php
//
// Who may read and post on a cohort's noticeboard (includes/academy_access.php,
// used by api/academy_api.php fetch_forum / post_forum_message): that batch's
// students, the facilitators of its programme's courses, and Academy staff.
// Runs the real function against in-memory SQLite, and checks the API calls
// it before either action touches the messages.
//
// Run from the repo root:
//     php tests/academy_forum_access_test.php
//
// Exit code 0 = all good, 1 = at least one failure. CLI only.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

require_once __DIR__ . '/../includes/academy_access.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE academy_batches (id INTEGER PRIMARY KEY, program_id INT, batch_name TEXT)');
$pdo->exec('CREATE TABLE academy_courses (id INTEGER PRIMARY KEY, program_id INT, name TEXT, instructor_id INT)');
$pdo->exec('CREATE TABLE academy_enrollments (id INTEGER PRIMARY KEY, user_id INT, batch_id INT, graduation_status TEXT)');

// Programme 1 runs batches 10 and 11; programme 2 runs batch 20.
$pdo->exec("INSERT INTO academy_batches VALUES (10, 1, 'Nov 2026'), (11, 1, 'Feb 2027'), (20, 2, 'Leaders 2026')");
// Facilitator 500 teaches in programme 1; facilitator 600 in programme 2; one course has no facilitator.
$pdo->exec("INSERT INTO academy_courses VALUES (1, 1, 'The Holy Spirit', 500), (2, 2, 'Leadership', 600), (3, 1, 'Prayer', NULL)");
// Student 100 is in batch 10; student 101 in batch 11; graduate 102 was in batch 10.
$pdo->exec("INSERT INTO academy_enrollments VALUES (1, 100, 10, 'Enrolled'), (2, 101, 11, 'Enrolled'), (3, 102, 10, 'Graduated')");

echo "Cohort noticeboard access\n";
ok('a student reads and posts on their own cohort', academy_forum_allowed($pdo, 100, false, 10));
ok('a student of another cohort is refused, even in the same programme', !academy_forum_allowed($pdo, 101, false, 10));
ok('a student is refused on a cohort of another programme', !academy_forum_allowed($pdo, 100, false, 20));
ok('a graduate of the cohort keeps access to it', academy_forum_allowed($pdo, 102, false, 10));
ok('a facilitator of the programme reaches each of its cohorts', academy_forum_allowed($pdo, 500, false, 10) && academy_forum_allowed($pdo, 500, false, 11));
ok('a facilitator of another programme is refused', !academy_forum_allowed($pdo, 600, false, 10));
ok('a member with no link to the cohort is refused', !academy_forum_allowed($pdo, 999, false, 10));
ok('Academy staff reach every cohort', academy_forum_allowed($pdo, 999, true, 10) && academy_forum_allowed($pdo, 999, true, 20));
ok('a missing or nonsense batch id is refused, staff included', !academy_forum_allowed($pdo, 100, false, '') && !academy_forum_allowed($pdo, 100, true, '0') && !academy_forum_allowed($pdo, 100, true, 'abc'));
ok('a batch id as a form string works', academy_forum_allowed($pdo, 100, false, '10'));
ok('a NULL facilitator never matches', !academy_forum_allowed($pdo, 0, false, 10));

echo "\nThe API uses it\n";
$api = (string) file_get_contents(__DIR__ . '/../api/academy_api.php');
foreach (['post_forum_message' => 'INSERT INTO academy_forum_messages', 'fetch_forum' => 'FROM academy_forum_messages m'] as $action => $query) {
    $start = strpos($api, "case '{$action}':");
    $guard = $start === false ? false : strpos($api, 'academy_forum_allowed($pdo', $start);
    $touch = $start === false ? false : strpos($api, $query, $start);
    ok("{$action} checks access before it touches the messages",
        $start !== false && $guard !== false && $touch !== false && $guard < $touch);
}
ok('a refusal answers 403', substr_count($api, 'http_response_code(403);') >= 2);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
