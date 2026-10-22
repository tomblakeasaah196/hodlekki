<?php
// /tests/auth_helpers_test.php
//
// BEHAVIOURAL tests for includes/auth_helpers.php:
//
//   - "Return to": auth_safe_next() is the only thing standing between a
//     login URL and an open redirect, so every way out of this site is tried.
//   - "Stay signed in": the 30-day device token is EXECUTED against in-memory
//     SQLite built from the real migration, through a real (cookie-less) PHP
//     session, and every way the Security Centre ends a session must end the
//     token too.
//
// Run from the repo root:
//     php tests/auth_helpers_test.php
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

// A real session (session_regenerate_id() is part of what is under test), so
// nothing may be printed before it starts: buffer everything.
ob_start();
date_default_timezone_set('Africa/Lagos');
$sessionDir = sys_get_temp_dir() . '/hod_auth_test_' . getmypid();
@mkdir($sessionDir, 0700, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '0');
ini_set('session.cache_limiter', '');
session_save_path($sessionDir);
session_start();

// The security schema check reads information_schema (MySQL only); everything
// else in security_helpers.php is used as it is.
$SEC_ON = true;
function security_schema_ready(PDO $pdo): bool
{
    return $GLOBALS['SEC_ON'];
}
function security_register_session(PDO $pdo, int $userId): void
{
    $pdo->prepare("INSERT INTO user_sessions (user_id, session_hash) VALUES (?, ?)")
        ->execute([$userId, security_current_session_hash()]);
}

require_once __DIR__ . '/../includes/auth_helpers.php';

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

/** The real migration, translated to SQLite, so code and schema cannot drift apart. */
function at_migration_sql(): array
{
    $sql = file_get_contents(__DIR__ . '/../db/migrations/20261124090000_auth_remember_tokens.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+) \((.*)\)\s*ENGINE=/s', $sql, $m);
    [$table, $body] = [$m[1], $m[2]];
    $columns = [];
    $indexes = [];
    foreach (array_map('trim', explode("\n", $body)) as $line) {
        $line = rtrim($line, ',');
        if ($line === '' || str_starts_with($line, 'PRIMARY KEY')) {
            continue;
        }
        if (preg_match('/^(UNIQUE )?KEY (\w+) \(([^)]+)\)$/', $line, $k)) {
            $indexes[] = 'CREATE ' . ($k[1] ? 'UNIQUE ' : '') . "INDEX {$k[2]} ON {$table} ({$k[3]})";
            continue;
        }
        $line = preg_replace('/\bINT UNSIGNED NOT NULL AUTO_INCREMENT\b/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $line);
        $columns[] = $line;
    }
    return array_merge(["CREATE TABLE {$table} (" . implode(', ', $columns) . ')'], $indexes);
}

function at_db(bool $withTokens = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT,
        picture_path TEXT, account_status TEXT NOT NULL DEFAULT 'active', sessions_valid_from TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT)");
    $pdo->exec("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_frozen INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        session_hash TEXT NOT NULL UNIQUE, ip_address TEXT, user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
        revoked_at TEXT NULL, revoked_by INTEGER NULL, revoke_reason TEXT NULL)");
    if ($withTokens) {
        foreach (at_migration_sql() as $statement) {
            $pdo->exec($statement);
        }
    }
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Resident_Pastor'), (2, 'Church_Member')");
    $pdo->exec("INSERT INTO users (id, first_name, last_name, email) VALUES (7, 'Ada', 'Obi', 'ada.obi@hodlc.com')");
    $pdo->exec("INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES (7, 1, 1), (7, 2, 0)");
    return $pdo;
}

/** A fresh browser session with nothing in it: what PHP hands out after GC. */
function at_new_session(): void
{
    $_SESSION = [];
    session_write_close();
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = [];
}

/** Sign in by password on this device and tick "Keep me signed in". */
function at_sign_in(PDO $pdo, int $userId = 7): string
{
    at_new_session();
    $_COOKIE = [];
    $user = $pdo->query("SELECT * FROM users WHERE id = {$userId}")->fetch(PDO::FETCH_ASSOC);
    auth_start_session($pdo, $user, true);
    security_register_session($pdo, $userId);
    auth_remember_issue($pdo, $userId);
    return (string) $_COOKIE[AUTH_REMEMBER_COOKIE];
}

function at_token(PDO $pdo, string $cookie): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM auth_remember_tokens WHERE selector = ?");
    $stmt->execute([substr($cookie, 0, 18)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------------------
echo "Return to: only this site's screens\n";
// ---------------------------------------------------------------------------
foreach ([
    '/modules/special_events/index.php?event=12',
    '/modules/special_events/index.php?event=12#tab=attendees',
    '/modules/charis/index.php',
    '/modules/library/index.php?q=John%20Doe',
    '/e/chara/host',
    '/reach.php',
] as $good) {
    same("keeps {$good}", $good, auth_safe_next($good));
}
foreach ([
    'the dashboard (the role decides)'   => '/index.php',
    'the PWA start url'                  => '/index.php?source=pwa',
    'the bare root'                      => '/',
    'nothing'                            => '',
    'another host'                       => '//evil.example/x',
    'a backslash host'                   => '/\\evil.example',
    'a scheme'                           => 'https://evil.example/',
    'javascript:'                        => 'javascript:alert(1)',
    'a dot segment'                      => '/modules/../auth/logout.php',
    'an encoded dot segment'             => '/modules/%2e%2e/auth/logout.php',
    'a single dot segment'               => '/modules/./x/index.php',
    'an API endpoint'                    => '/api/dashboard_api.php',
    'the login page itself'              => '/auth/login.php',
    'sign out'                           => '/auth/logout.php',
    'a bare /modules/'                   => '/modules/',
    'a bare /e/'                         => '/e/',
    'a // hidden in the query'           => '/modules/x/index.php?u=//evil.example',
    'a newline in the query'             => "/modules/x/index.php?a=b\nc",
    'a quote'                            => '/modules/x/index.php?a="',
    'something too long'                 => '/modules/x/index.php?' . str_repeat('a', 400),
] as $what => $bad) {
    same("refuses {$what}", null, auth_safe_next($bad));
}
same('refuses a non-string', null, auth_safe_next(['/modules/x/']));
same('login URL carries the page', '/auth/login.php?next=%2Fmodules%2Fevents%2Findex.php%3Fevent%3D3',
    auth_login_url('/modules/events/index.php?event=3'));
same('login URL for something unsafe is the plain login page', '/auth/login.php', auth_login_url('//evil.example'));
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/modules/special_events/index.php?event=12';
same('a GET is remembered', '/auth/login.php?next=%2Fmodules%2Fspecial_events%2Findex.php%3Fevent%3D12', auth_login_url_for_request());
$_SERVER['REQUEST_METHOD'] = 'POST';
same('a POST is never replayed', '/auth/login.php', auth_login_url_for_request());
$_SERVER['REQUEST_METHOD'] = 'GET';

// ---------------------------------------------------------------------------
echo "Stay signed in: issue and resume\n";
// ---------------------------------------------------------------------------
$pdo = at_db();
$cookie = at_sign_in($pdo);
$loginHash = security_current_session_hash();
$token = at_token($pdo, $cookie);
ok('the cookie is selector:validator', auth_remember_parse($cookie) !== null, $cookie);
ok('a token row exists', $token !== null);
ok('only a hash of the validator is stored', $token && $token['validator_hash'] === hash('sha256', substr($cookie, 19))
    && !str_contains(json_encode($token), substr($cookie, 19)));
same('it is tied to this session', $loginHash, $token['session_hash'] ?? null);
ok('it lasts 30 days', abs(auth_time($token['expires_at']) - (time() + 30 * 86400)) < 5);

// PHP's session runs out (24 idle minutes); the next request has no session.
at_new_session();
same('a fresh session is resumed from the token', 7, auth_remember_resume($pdo, $cookie));
same('…signed in as the member', 7, (int) ($_SESSION['user_id'] ?? 0));
same('…with frozen roles left out', 'Church_Member', $_SESSION['active_role'] ?? null);
same('…and a new session id', true, security_current_session_hash() !== $loginHash);
same('the device keeps ONE row in the Security Centre, moved to the new session',
    [[security_current_session_hash()]], $pdo->query("SELECT session_hash FROM user_sessions WHERE user_id = 7")->fetchAll(PDO::FETCH_NUM));
same('the token follows the session', security_current_session_hash(), at_token($pdo, $cookie)['session_hash']);

$pdo->exec("UPDATE users SET must_change_password = 1 WHERE id = 7");
at_new_session();
auth_remember_resume($pdo, $cookie);
same('a forced password change survives a resume', true, $_SESSION['must_change_password'] ?? null);
$pdo->exec("UPDATE users SET must_change_password = 0 WHERE id = 7");

// ---------------------------------------------------------------------------
echo "Stay signed in: everything that ends a session ends the token\n";
// ---------------------------------------------------------------------------
$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->exec("UPDATE users SET account_status = 'suspended' WHERE id = 7");
at_new_session();
same('suspended: refused', null, auth_remember_resume($pdo, $cookie));
same('…the token is gone', null, at_token($pdo, $cookie));
same('…the cookie is cleared', false, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));
same('…and nobody is signed in', false, isset($_SESSION['user_id']));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->exec("UPDATE users SET account_status = 'revoked' WHERE id = 7");
at_new_session();
same('revoked: refused', null, auth_remember_resume($pdo, $cookie));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = 7")->execute([date('Y-m-d H:i:s', time() + 5)]);
at_new_session();
same('"sign out everywhere" (sessions_valid_from after the token): refused', null, auth_remember_resume($pdo, $cookie));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = 7")->execute([date('Y-m-d H:i:s', time() - 60)]);
at_new_session();
same('an older sessions_valid_from does not stop it', 7, auth_remember_resume($pdo, $cookie));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->exec("UPDATE user_sessions SET revoked_at = '2026-10-22 09:00:00', revoke_reason = 'Device signed out by an administrator'");
at_new_session();
same('this device signed out in the Security Centre: refused', null, auth_remember_resume($pdo, $cookie));
same('…and no new session row was made for it', 1, (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn());

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->exec("UPDATE auth_remember_tokens SET expires_at = '2020-01-01 00:00:00'");
at_new_session();
same('expired: refused', null, auth_remember_resume($pdo, $cookie));
same('…and deleted', null, at_token($pdo, $cookie));

$pdo = at_db();
$cookie = at_sign_in($pdo);
at_new_session();
$tampered = substr($cookie, 0, 19) . str_repeat('0', 64);
same('right selector, wrong validator: refused', null, auth_remember_resume($pdo, $tampered));
same('…and the token is burnt for the real owner too', null, at_token($pdo, $cookie));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$pdo->exec("DELETE FROM users WHERE id = 7");
at_new_session();
same('a member who no longer exists: refused', null, auth_remember_resume($pdo, $cookie));

at_new_session();
$_COOKIE[AUTH_REMEMBER_COOKIE] = 'not-a-token';
same('a malformed cookie: refused', null, auth_remember_resume(at_db(), 'not-a-token'));
same('…and cleared', false, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));
same('an unknown selector: refused', null, auth_remember_resume(at_db(), str_repeat('a', 18) . ':' . str_repeat('b', 64)));

// ---------------------------------------------------------------------------
echo "Stay signed in: the revocation hooks\n";
// ---------------------------------------------------------------------------
$pdo = at_db();
$phone = at_sign_in($pdo);
$phoneHash = security_current_session_hash();
$laptop = at_sign_in($pdo);
$laptopHash = security_current_session_hash();
same('two devices, two tokens', 2, (int) $pdo->query("SELECT COUNT(*) FROM auth_remember_tokens")->fetchColumn());
same('a password change that keeps this browser keeps only its token', 1, auth_remember_forget_user($pdo, 7, $laptopHash));
same('…the laptop stays', true, at_token($pdo, $laptop) !== null);
same('…the phone goes', null, at_token($pdo, $phone));
same('"sign out everywhere" takes the rest', 1, auth_remember_forget_user($pdo, 7));

$pdo = at_db();
$phone = at_sign_in($pdo);
$phoneHash = security_current_session_hash();
same('signing out one device in the Security Centre', 1, auth_remember_forget_session($pdo, $phoneHash));
same('…ends its token', null, at_token($pdo, $phone));

$pdo = at_db();
$cookie = at_sign_in($pdo);
auth_remember_forget_current($pdo);
same('signing out on the device ends its token', null, at_token($pdo, $cookie));
same('…and clears its cookie', false, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));

$pdo = at_db();
$cookie = at_sign_in($pdo);
$first = at_token($pdo, $cookie)['selector'];
$_COOKIE[AUTH_REMEMBER_COOKIE] = $cookie;
auth_remember_issue($pdo, 7);
same('signing in again on the same device replaces its token', 1, (int) $pdo->query("SELECT COUNT(*) FROM auth_remember_tokens")->fetchColumn());
same('…with a new selector', true, at_token($pdo, $_COOKIE[AUTH_REMEMBER_COOKIE])['selector'] !== $first);

// ---------------------------------------------------------------------------
echo "Stay signed in: a live session keeps the token honest\n";
// ---------------------------------------------------------------------------
$pdo = at_db();
$cookie = at_sign_in($pdo);
session_regenerate_id(true);
auth_remember_sync($pdo);
same('a new session id is followed (so revoking it in the Security Centre ends the device)',
    security_current_session_hash(), at_token($pdo, $cookie)['session_hash']);

$pdo->exec("UPDATE auth_remember_tokens SET expires_at = '2026-01-01 00:00:00'");
$_SESSION['auth_remember_touched'] = time() - 2 * 86400;
auth_remember_sync($pdo);
ok('the 30 days slide forward once a day', auth_time(at_token($pdo, $cookie)['expires_at']) > time() + 29 * 86400);

$pdo->exec("DELETE FROM auth_remember_tokens");
$_SESSION['auth_remember_touched'] = 0;
auth_remember_sync($pdo);
same('a token revoked under a live session lets the cookie go', false, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));

// ---------------------------------------------------------------------------
echo "Before the migration\n";
// ---------------------------------------------------------------------------
$pre = at_db(false);
at_new_session();
$_COOKIE = [];
$user = $pre->query("SELECT * FROM users WHERE id = 7")->fetch(PDO::FETCH_ASSOC);
auth_start_session($pre, $user, true);
same('issuing quietly does nothing', false, auth_remember_issue($pre, 7));
same('no cookie is set', false, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));
at_new_session();
$_COOKIE[AUTH_REMEMBER_COOKIE] = str_repeat('a', 18) . ':' . str_repeat('b', 64);
same('a cookie from later is left alone, not cleared', null, auth_remember_resume($pre, $_COOKIE[AUTH_REMEMBER_COOKIE]));
same('…still there for when the table exists', true, isset($_COOKIE[AUTH_REMEMBER_COOKIE]));
same('forgetting is a no-op', 0, auth_remember_forget_user($pre, 7));

session_write_close();
array_map('unlink', glob($sessionDir . '/sess_*') ?: []);
@rmdir($sessionDir);

echo "\n{$passed} passed, {$failed} failed\n";
ob_end_flush();
exit($failed > 0 ? 1 : 0);
