<?php
// /tests/pwa/run.php
/**
 * ============================================================================
 * PWA + WEB PUSH TEST SUITE
 * ----------------------------------------------------------------------------
 *     php tests/pwa/run.php
 *
 * No database and no network: everything here is either pure computation or
 * checked against a published RFC vector, so it runs anywhere PHP does and
 * gives the same answer every time.
 *
 * What it protects
 *   push_crypto_test  the encryption is verified byte for byte against the
 *                     worked example in RFC 8291 §5. If this breaks, every
 *                     browser silently discards every notification and the
 *                     only clue is that nobody gets anything.
 *   push_helpers_test the string handling that decides what a member reads on
 *                     their lock screen, and the guard that stops a
 *                     notification's link_url opening somebody else's site.
 *   function_calls    php -l cannot see a call to a function that does not
 *                     exist; this does.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$GLOBALS['pwa_checks']   = 0;
$GLOBALS['pwa_failures'] = [];

/** Assert that two values are identical. */
function check(string $what, $expected, $actual): void
{
    $GLOBALS['pwa_checks']++;

    if ($expected === $actual) {
        return;
    }

    $render = static function ($value): string {
        if (is_string($value)) return strlen($value) > 220 ? substr($value, 0, 200) . '…' : $value;
        return var_export($value, true);
    };

    $GLOBALS['pwa_failures'][] = "  {$what}\n"
        . "      expected: " . $render($expected) . "\n"
        . "      actual:   " . $render($actual);
}

/** Assert that a condition holds. */
function ok(string $what, bool $condition, string $detail = ''): void
{
    check($what . ($detail !== '' ? " ({$detail})" : ''), true, $condition);
}

/** Assert that $call throws $class. */
function throws(string $what, string $class, callable $call): void
{
    $thrown = null;
    try {
        $call();
    } catch (Throwable $e) {
        $thrown = get_class($e);
    }
    check($what, $class, $thrown);
}

function suite(string $name): void
{
    echo "\n  {$name}\n";
}

$repoRoot = dirname(__DIR__, 2);

require_once $repoRoot . '/includes/push_crypto.php';
require_once $repoRoot . '/includes/push_helpers.php';

echo "\n==========================================================\n";
echo "  PWA + Web Push\n";
echo "==========================================================\n";

foreach (['push_crypto_test.php', 'push_helpers_test.php', 'function_calls_test.php'] as $test) {
    require __DIR__ . '/' . $test;
}

$failures = $GLOBALS['pwa_failures'];
$checks   = $GLOBALS['pwa_checks'];

echo "\n==========================================================\n";
if ($failures === []) {
    echo "  passed: {$checks}   failed: 0\n";
    echo "==========================================================\n";
    exit(0);
}

echo "  passed: " . ($checks - count($failures)) . "   failed: " . count($failures) . "\n";
echo "==========================================================\n\n";
foreach ($failures as $failure) {
    echo $failure . "\n";
}
echo "\n";
exit(1);
