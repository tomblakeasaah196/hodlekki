<?php
// /tests/security_helpers_test.php
//
// Pure-logic tests for includes/security_helpers.php — the parts that do not
// need a database: the password policy, the generated-password suggester, the
// device describer and the client-IP resolver.
//
// There is no PHPUnit in this project (and no dev dependencies on the cPanel
// host), so this is a plain assertion script.
//
// Run it with any PHP 8.x CLI from the repo root:
//     php tests/security_helpers_test.php
//
// Exit code 0 = all good, 1 = at least one failure.
//
// CLI only — `tests/` is excluded by .deployignore, but this guard means the
// script is inert even if a copy ever lands in the docroot (same convention
// as everything in cron/).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/security_helpers.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
}

/** Assert the password is REJECTED, and that the reason mentions $needle. */
function rejects(string $password, string $needle, array $user = []): void
{
    $problems = security_password_problems($password, $user);
    $joined   = strtolower(implode(' ', $problems));
    ok(
        "rejects " . var_export($password, true) . " ({$needle})",
        !empty($problems) && str_contains($joined, strtolower($needle))
    );
}

/** Assert the password is ACCEPTED. */
function accepts(string $password, array $user = []): void
{
    $problems = security_password_problems($password, $user);
    ok(
        "accepts " . var_export($password, true),
        empty($problems)
    );
    if (!empty($problems)) {
        echo "       -> " . implode(' | ', $problems) . "\n";
    }
}

echo "\n--- password policy ---\n";

rejects('Ab3',              'at least');
rejects('Ab3xyzpq',         'at least');                 // 8 chars: below the 10 minimum
rejects('lowercase1234',    'uppercase letter');
rejects('UPPERCASE1234',    'lowercase letter');
rejects('NoDigitsInHere',   'number');
rejects('MyPassword1234',   'easy to guess');            // contains "password"
rejects('Qwerty123456',     'easy to guess');
rejects('Hodlekki12345',    'easy to guess');
rejects('aaaaaaaaaaaa',     'uppercase');                // also all-same-character
rejects(' LeadingSpace1',   'space');
rejects('TrailingSpace1 ',  'space');
rejects(str_repeat('Aa1', 80), 'shorter than 200');

// Identity-derived passwords
$member = ['first_name' => 'Tomiwa', 'last_name' => 'Adeleke', 'email' => 'tomiwa.a@hodlc.org'];
rejects('Tomiwa123456',  'your own name', $member);
rejects('xxAdeleke99Zz', 'your own name', $member);
rejects('Ztomiwa.a55Qb', 'your own name', $member);      // email local part

// Short identity fragments must NOT trigger the rule (< 4 chars)
$shortName = ['first_name' => 'Ayo', 'last_name' => 'Eze', 'email' => 'ay@hodlc.org'];
accepts('AyoKrRtVm42', $shortName);

accepts('Kx7mQp2wLzR4');
accepts('Blue-Harbour-71');
accepts('Correct9Horse');
accepts('Zion2026Lekki');

echo "\n--- generated passwords always satisfy the policy ---\n";

$allGood = true;
$seen = [];
for ($i = 0; $i < 200; $i++) {
    $candidate = security_suggest_password();
    $seen[$candidate] = true;
    if (!empty(security_password_problems($candidate))) {
        $allGood = false;
        echo "       -> rejected its own suggestion: {$candidate}\n";
        break;
    }
    if (strlen($candidate) !== 14) {
        $allGood = false;
        echo "       -> unexpected length: {$candidate}\n";
        break;
    }
}
ok('200 suggested passwords all pass policy and are 14 chars', $allGood);
ok('suggested passwords are not repeating', count($seen) > 190);

echo "\n--- password rules text ---\n";
$rules = security_password_rules();
ok('policy advertises 5 rules', count($rules) === 5);
ok('policy advertises the real minimum length', str_contains($rules[0], (string) SECURITY_MIN_PASSWORD_LENGTH));

echo "\n--- device description ---\n";

ok(
    'Chrome on Windows',
    security_describe_device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36')
        === 'Chrome on Windows'
);
ok(
    'Safari on iPhone',
    security_describe_device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1')
        === 'Safari on iPhone'
);
ok(
    'Chrome on Android',
    security_describe_device('Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 Chrome/119.0 Mobile Safari/537.36')
        === 'Chrome on Android'
);
ok('empty agent is handled', security_describe_device('') === 'Unknown device');
ok('null agent is handled', security_describe_device(null) === 'Unknown device');

echo "\n--- client IP resolution ---\n";

$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
ok('falls back to REMOTE_ADDR', security_client_ip() === '10.0.0.5');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '102.89.33.10, 10.0.0.5';
ok('prefers the left-most forwarded address', security_client_ip() === '102.89.33.10');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 102.89.33.11';
ok('skips junk in X-Forwarded-For', security_client_ip() === '102.89.33.11');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '<script>alert(1)</script>';
unset($_SERVER['REMOTE_ADDR']);
ok('never returns an unvalidated string', security_client_ip() === 'unknown');

echo "\n--- user agent capture ---\n";
$_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 400);
ok('user agent is truncated to the column width', strlen(security_user_agent()) === 255);

echo "\n--- session hash ---\n";
ok('hash is sha256 hex or empty when no session', in_array(strlen(security_current_session_hash()), [0, 64], true));

echo "\n--- admin role set ---\n";
$adminRoles = security_admin_roles();
ok('Super_Admin has Security Centre clearance', in_array('Super_Admin', $adminRoles, true));
ok('Resident_Pastor has Security Centre clearance', in_array('Resident_Pastor', $adminRoles, true));
ok('Assoc_Pastor does NOT', !in_array('Assoc_Pastor', $adminRoles, true));
ok('Church_Member does NOT', !in_array('Church_Member', $adminRoles, true));

echo "\n========================================\n";
echo "  passed: {$passed}   failed: {$failed}\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
