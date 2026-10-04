<?php
// /tests/special_events/run.php
//
// CLI unit-test harness for the Special Events module (guide §22.1).
//
// There is no PHPUnit in this project (and no dev dependencies on the cPanel
// host), so this is a plain assertion runner in the same style as
// tests/security_helpers_test.php. It discovers every *_test.php beside it,
// runs each in turn and sums the results.
//
// None of these tests touch a database: they cover pure logic only, so they
// can run anywhere, including in CI.
//
//   php tests/special_events/run.php            # everything
//   php tests/special_events/run.php slug theme # only matching files
//
// Exit code 0 = all good, 1 = at least one failure.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// The module's libraries are pure PHP with no database work at load time.
// util.php needs a pepper for the hashing helpers; a fixed test value keeps
// the vectors reproducible and never reaches a real environment.
$_ENV['SE_HASH_PEPPER'] = str_repeat('0123456789abcdef', 4);

require_once __DIR__ . '/../../includes/special_events/constants.php';
require_once __DIR__ . '/../../includes/special_events/util.php';
require_once __DIR__ . '/../../includes/special_events/theme.php';
require_once __DIR__ . '/../../includes/special_events/settings.php';

// assets.php and ai.php hold the SVG sanitiser and the schema validator. They
// reference PDO in other functions, but nothing runs at include time.
require_once __DIR__ . '/../../includes/special_events/db.php';
require_once __DIR__ . '/../../includes/special_events/assets.php';
require_once __DIR__ . '/../../includes/special_events/ai.php';

$GLOBALS['se_passed'] = 0;
$GLOBALS['se_failed'] = 0;
$GLOBALS['se_failures'] = [];

/** Assert a condition. */
function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['se_passed']++;
        return;
    }
    $GLOBALS['se_failed']++;
    $GLOBALS['se_failures'][] = $label . ($detail !== '' ? "  ({$detail})" : '');
    echo "    FAIL  {$label}" . ($detail !== '' ? "  — {$detail}" : '') . "\n";
}

/** Assert two values are identical, printing both when they are not. */
function is_same(string $label, mixed $expected, mixed $actual): void
{
    $same = $expected === $actual;
    ok($label, $same, $same ? '' : 'expected ' . se_test_dump($expected) . ', got ' . se_test_dump($actual));
}

/** Assert a callable throws, optionally of a given class. */
function throws(string $label, callable $fn, ?string $class = null): void
{
    try {
        $fn();
        ok($label, false, 'nothing was thrown');
    } catch (Throwable $e) {
        ok($label, $class === null || $e instanceof $class,
            $class === null ? '' : 'got ' . get_class($e));
    }
}

function se_test_dump(mixed $value): string
{
    if (is_string($value)) { return "'" . (mb_strlen($value) > 120 ? mb_substr($value, 0, 117) . '…' : $value) . "'"; }
    if (is_bool($value))   { return $value ? 'true' : 'false'; }
    if ($value === null)   { return 'null'; }
    if (is_array($value))  { return se_json_encode($value); }

    return (string) $value;
}

/** Load a shared fixture. */
function fixture(string $name): array
{
    $path = __DIR__ . '/fixtures/' . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "[se-tests] missing fixture {$name}\n");
        exit(1);
    }

    return json_decode((string) file_get_contents($path), true) ?: [];
}

// --------------------------------------------------------------------------

$filters = array_slice($argv, 1);
$files   = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);

if ($filters) {
    $files = array_values(array_filter($files, static function (string $f) use ($filters): bool {
        foreach ($filters as $needle) {
            if (str_contains(basename($f), $needle)) {
                return true;
            }
        }
        return false;
    }));
}

if (!$files) {
    fwrite(STDERR, "[se-tests] no test files matched\n");
    exit(1);
}

echo "Special Events unit tests\n";
echo str_repeat('=', 58) . "\n";

foreach ($files as $file) {
    $before = $GLOBALS['se_passed'] + $GLOBALS['se_failed'];
    echo "\n  " . basename($file, '.php') . "\n";

    try {
        require $file;
    } catch (Throwable $e) {
        $GLOBALS['se_failed']++;
        $GLOBALS['se_failures'][] = basename($file) . ' threw ' . get_class($e) . ': ' . $e->getMessage();
        echo "    FAIL  the file threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }

    $run = $GLOBALS['se_passed'] + $GLOBALS['se_failed'] - $before;
    echo "    {$run} checks\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
printf("  passed: %d   failed: %d\n", $GLOBALS['se_passed'], $GLOBALS['se_failed']);

if ($GLOBALS['se_failures']) {
    echo str_repeat('-', 58) . "\n";
    foreach ($GLOBALS['se_failures'] as $failure) {
        echo "  • {$failure}\n";
    }
}
echo str_repeat('=', 58) . "\n";

exit($GLOBALS['se_failed'] > 0 ? 1 : 0);
