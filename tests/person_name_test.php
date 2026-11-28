<?php
// /tests/person_name_test.php
//
// Tests for the middle name (owner, 28 Nov 2026: "optional but important").
// They EXECUTE includes/person_name.php against in-memory SQLite and pin:
//
//   - users.middle_name is optional: blank is stored as NULL, a typed one is
//     trimmed and single-spaced, capped at 100 characters.
//   - The full name is first, middle, last, blanks skipped.
//   - Before the migration nothing breaks: person_middle_sql() selects NULL
//     and person_middle_ready() is false, so writers leave the column alone.
//   - The public Connect card leaves a blank middle name out of its INSERT.
//   - Every screen that makes or edits a person carries the field, and its
//     endpoint saves it (static checks, so a later rewrite cannot drop it).
//
// Run from the repo root:
//     php tests/person_name_test.php
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

require_once __DIR__ . '/../includes/person_name.php';
require_once __DIR__ . '/../includes/embrace_helpers.php';

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

function same(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function pn_db(bool $migrated): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, '
        . ($migrated ? 'middle_name TEXT NULL, ' : '') . 'last_name TEXT)');
    return $pdo;
}

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);

echo "Full name\n";
same('first, middle and last', 'Ada Chiamaka Obi', person_full_name('Ada', 'Chiamaka', 'Obi'));
same('no middle name', 'Ada Obi', person_full_name('Ada', null, 'Obi'));
same('a blank middle name leaves one space', 'Ada Obi', person_full_name('Ada', '   ', 'Obi'));
same('spaces inside are single', 'Ada Chiamaka Obi', person_full_name(' Ada ', "Chiamaka\t", '  Obi'));
same('two middle names', 'Ada Chiamaka Ngozi Obi', person_full_name('Ada', 'Chiamaka Ngozi', 'Obi'));
same('only a first name', 'Ada', person_full_name('Ada', '', ''));
same('a row', 'Ada Chiamaka Obi', person_row_name(['first_name' => 'Ada', 'middle_name' => 'Chiamaka', 'last_name' => 'Obi']));
same('a row without the key', 'Ada Obi', person_row_name(['first_name' => 'Ada', 'last_name' => 'Obi']));

echo "Cleaning what was typed\n";
same('blank is NULL', null, person_middle_clean(''));
same('spaces only is NULL', null, person_middle_clean("   \t "));
same('missing is NULL', null, person_middle_clean(null));
same('trimmed', 'Chiamaka', person_middle_clean('  Chiamaka  '));
same('inner spaces single', 'Chiamaka Ngozi', person_middle_clean("Chiamaka \n  Ngozi"));
same('accents kept', 'Adébáyọ̀', person_middle_clean('Adébáyọ̀'));
same('capped at 100 characters', 100, mb_strlen((string) person_middle_clean(str_repeat('é', 150))));

echo "After the migration\n";
$pdo = pn_db(true);
ok('the column is seen', person_middle_ready($pdo));
same('the select names the column', 'u.middle_name', person_middle_sql($pdo, 'u'));
same('… or the bare column', 'middle_name', person_middle_sql($pdo, ''));
$pdo->exec("INSERT INTO users (id, first_name, middle_name, last_name) VALUES (1, 'Ada', 'Chiamaka', 'Obi'), (2, 'Tunde', NULL, 'Bello')");
$rows = $pdo->query('SELECT u.id, u.first_name, ' . person_middle_sql($pdo, 'u') . ' AS middle_name, u.last_name FROM users u ORDER BY u.id')
    ->fetchAll(PDO::FETCH_ASSOC);
same('a person with a middle name', 'Ada Chiamaka Obi', person_row_name($rows[0]));
same('a person without one', 'Tunde Bello', person_row_name($rows[1]));

echo "Before the migration\n";
$old = pn_db(false);
ok('the column is not seen', !person_middle_ready($old));
same('the select stands in NULL', 'NULL', person_middle_sql($old, 'u'));
$old->exec("INSERT INTO users (id, first_name, last_name) VALUES (1, 'Ada', 'Obi')");
$row = $old->query('SELECT u.first_name, ' . person_middle_sql($old, 'u') . ' AS middle_name, u.last_name FROM users u')->fetch(PDO::FETCH_ASSOC);
same('the query still runs', 'Ada Obi', person_row_name($row));
ok('the answer is kept per connection', person_middle_ready($pdo) && !person_middle_ready($old));

echo "Connect card INSERT\n";
[$sql, $params] = embrace_connect_insert(['first_name' => 'Ada', 'middle_name' => '', 'last_name' => 'Obi', 'phone' => '0803']);
ok('a blank middle name is left out', !str_contains($sql, 'middle_name'), $sql);
[$sql, $params] = embrace_connect_insert(['first_name' => 'Ada', 'middle_name' => 'Chiamaka', 'last_name' => 'Obi', 'phone' => '0803']);
ok('a given one is inserted', str_contains($sql, 'middle_name') && in_array('Chiamaka', $params, true), $sql);

echo "The migration\n";
$migration = $read('db/migrations/20261226090000_users_middle_name.sql');
ok('it exists', $migration !== '');
ok('it adds an optional column after first_name', (bool) preg_match('/ADD COLUMN middle_name VARCHAR\(100\) NULL DEFAULT NULL AFTER first_name/', $migration));
ok('it is guarded, so running it twice is a no-op', str_contains($migration, 'information_schema.columns') && str_contains($migration, "'DO 0'"));
ok('it has no DELIMITER block', !preg_match('/^\s*DELIMITER/mi', $migration));

echo "Every screen that makes or edits a person carries the field\n";
$screens = [
    'modules/congregation/index.php' => "'name' => 'middle_name'",
    'modules/embrace/index.php' => "'name' => 'middle_name'",
    'modules/profile/index.php' => "'name' => 'middle_name'",
    'connect.php' => "'name' => 'middle_name'",
    'assets/ui/js/pages/events-attendance.js' => "'att-wz-middle', 'middle_name'",
];
foreach ($screens as $file => $needle) {
    ok("{$file} has a Middle name field", str_contains($read($file), $needle));
}
ok('the Events verify sheet edits it too', str_contains($read('assets/ui/js/pages/events-attendance.js'), "'att-vf-middle', 'middle_name'"));
ok('Congregation\'s edit fills it', str_contains($read('assets/ui/js/pages/congregation.js'), "['first_name', 'middle_name', 'last_name'"));
ok('Embrace\'s edit fills it', str_contains($read('assets/ui/js/pages/embrace.js'), "['first_name', 'middle_name', 'last_name'"));
ok('Profile fills it', str_contains($read('assets/ui/js/pages/profile.js'), "['first_name', 'middle_name', 'last_name'"));
ok('Connect checks it on step 1', str_contains($read('assets/ui/js/pages/connect.js'), "middle_name: form.elements.middle_name.value"));

$endpoints = [
    'api/congregation_api.php' => 2,       // create_member, update_member
    'api/embrace_api.php' => 2,            // add_visitor, update_visitor
    'api/events_api.php' => 2,             // create_first_timer, update_person_details
    'api/profile_api.php' => 1,            // update_profile (a blank one only)
];
foreach ($endpoints as $file => $writers) {
    $src = $read($file);
    same("{$file} reads it {$writers} time(s)", $writers, substr_count($src, "\$_POST['middle_name']"));
    ok("{$file} cleans it", substr_count($src, 'person_middle_clean(') >= $writers);
}
ok('the Connect API reads and checks it', str_contains($read('api/embrace_public_api.php'), "connect_input('middle_name')")
    && str_contains($read('api/embrace_public_api.php'), "'middle_name'"));
ok('a member adds only a blank one', str_contains($read('api/profile_api.php'), "AND (middle_name IS NULL OR middle_name = '')"));

echo "Shown wherever the full name is\n";
foreach ([
    'assets/ui/js/pages/congregation.js',
    'assets/ui/js/pages/embrace.js',
    'assets/ui/js/pages/events-attendance.js',
    'assets/ui/js/pages/profile.js',
] as $file) {
    ok("{$file} builds the name with it", str_contains($read($file), 'middle_name'));
}
ok('global search matches it', str_contains($read('api/global_search_api.php'), 'CONCAT_WS(\' \', first_name, middle_name, last_name)'));
ok('the certificate prints it', str_contains($read('includes/trainings/certificates.php'), 'person_middle_sql('));
ok('the Embrace register has a Middle Name column', str_contains($read('includes/embrace_export_excel.php'), "'Middle Name'"));
ok('the IDI master roster carries it', str_contains($read('api/idi_api.php'), 'AS middle_name'));

echo "Nothing reads the column before it exists\n";
// A PHP file that names middle_name in SQL must ask person_middle_sql() /
// person_middle_ready() first: the tree reaches production before the migration.
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = substr($f->getPathname(), strlen($root) + 1);
    if ($f->getExtension() !== 'php' || preg_match('#^(vendor|tests|uploads|node_modules|db/migrations)/#', $path)
        || $path === 'includes/person_name.php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    if (!str_contains($src, 'middle_name')) {
        continue;
    }
    // A line of SQL that names the column: "u.middle_name", "SET middle_name = ?",
    // "middle_name IS NULL", "middle_name," in a column list, CONCAT_WS(...).
    $sqlLine = preg_match('/\b(?:\w+\.middle_name\b|middle_name\s*(?:=\s*\?|IS\s|,\s*\w+\s*(?:,|FROM|\)))|CONCAT\w*\([^)]*middle_name)/i', $src);
    if ($sqlLine && !str_contains($src, 'person_middle_sql(') && !str_contains($src, 'person_middle_ready(')) {
        $offenders[] = $path;
    }
}
ok('every SQL use is guarded', $offenders === [], implode(', ', $offenders));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
