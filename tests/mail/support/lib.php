<?php
// /tests/mail/support/lib.php
//
// Shared by every Mail test file (tests/mail/*.php): the engine, the fakes (mail server, uapi) and tiny helpers.
// No database here; tests/mail/integration.php opens its own.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
date_default_timezone_set('Africa/Lagos');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['__mail_root'] = dirname(__DIR__, 3);
$_ENV['MAIL_DOMAIN'] = 'hodlekki.org';
require_once $GLOBALS['__mail_root'] . '/includes/security_helpers.php';   // before any output: it may start a session
require_once $GLOBALS['__mail_root'] . '/includes/mail/bootstrap.php';
require_once __DIR__ . '/FakeImapSession.php';
require_once __DIR__ . '/FakeMailServer.php';
require_once __DIR__ . '/FakeUapi.php';

\HOD\Mail\Vault::useKeys(str_repeat('a1', 32));

$GLOBALS['__mail_fail'] = $GLOBALS['__mail_fail'] ?? 0;
$GLOBALS['__mail_pass'] = $GLOBALS['__mail_pass'] ?? 0;

if (!function_exists('check')) {
    function check(string $label, bool $ok, string $detail = ''): void
    {
        printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, (!$ok && $detail !== '') ? "\n        " . $detail : '');
        $ok ? $GLOBALS['__mail_pass']++ : $GLOBALS['__mail_fail']++;
    }

    function section(string $title): void
    {
        echo "\n--- {$title} ---\n";
    }

    /** Run $fn and return the exception it throws (or null) */
    function throws(callable $fn): ?Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    function mail_test_done(): void
    {
        printf("\n%d passed, %d failed\n", $GLOBALS['__mail_pass'], $GLOBALS['__mail_fail']);
        exit($GLOBALS['__mail_fail'] ? 1 : 0);
    }
}
