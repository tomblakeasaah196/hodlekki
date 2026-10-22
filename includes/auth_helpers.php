<?php
// /includes/auth_helpers.php
//
// What every way of signing in shares:
//
//   1. "Return to". Whatever a member was opening when the login wall stopped
//      them (a push notification, an SMS link, a bookmark) travels through
//      /auth/login.php?next=… and api/auth_api.php sends them there after
//      signing in. Before this, header.php dropped it and everyone landed on
//      the dashboard.
//   2. "Stay signed in". A 30-day token per device, so tapping a notification
//      hours later opens the page instead of the login screen. PHP's own
//      session still dies after ~24 idle minutes (php.ini, which the deploy
//      never touches); the token quietly starts a fresh one.
//
// The token answers to the Security Centre exactly as a session does:
// suspending or revoking an account, "sign out everywhere", a password change
// that signs other devices out, and signing out one device all end it. Each
// device keeps ONE row in user_sessions across resumes, so revoking that row
// is revoking the device.
//
// Portable SQL only (tests/auth_helpers_test.php runs this against SQLite),
// and every function is a safe no-op until
// db/migrations/20261124090000_auth_remember_tokens.sql has run: the tree
// reaches production before db/migrate.php does.

require_once __DIR__ . '/security_helpers.php';

const AUTH_REMEMBER_COOKIE = 'hod_remember';
const AUTH_REMEMBER_DAYS = 30;

/** Slide the 30 days forward at most once a day per session. */
const AUTH_REMEMBER_TOUCH_SECONDS = 86400;

const AUTH_NEXT_MAX_LENGTH = 300;

// --------------------------------------------------------------------------
// Return to
// --------------------------------------------------------------------------

/**
 * A same-origin screen worth returning to, or null.
 *
 * Open-redirect safe by construction: one leading slash, never "//host" or a
 * backslash, no dot segments (encoded ones included, since browsers decode
 * %2e%2e as ".."), and only a module, a Special Events surface or a page at
 * the root. /api/, /auth/ and friends are never a destination. The dashboard
 * itself returns null so the role-based landing in auth_api.php still decides
 * where a member without dashboard access goes.
 */
function auth_safe_next($raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $next = trim($raw);
    if ($next === '' || strlen($next) > AUTH_NEXT_MAX_LENGTH) {
        return null;
    }

    $cut  = strcspn($next, '?#');
    $path = substr($next, 0, $cut);
    $rest = (string) substr($next, $cut);

    if (!preg_match('#^/[A-Za-z0-9/_\-.~]*$#D', $path)
        || str_contains($path, '//')
        || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        return null;
    }
    if ($rest !== '' && (!preg_match('#^[?\#][A-Za-z0-9/_\-.~?=&%+,:\#]*$#D', $rest) || str_contains($rest, '//'))) {
        return null;
    }
    if (!preg_match('#^/(?:modules/[A-Za-z0-9_\-]+/|e/[A-Za-z0-9_\-]|[A-Za-z0-9_\-]+\.php$)#D', $path)) {
        return null;
    }
    if ($path === '/index.php') {
        return null;
    }

    return $next;
}

function auth_login_url(?string $next = null): string
{
    $safe = auth_safe_next($next);
    return '/auth/login.php' . ($safe !== null ? '?next=' . rawurlencode($safe) : '');
}

/**
 * The login URL for the page being requested right now. GET only: a POST is
 * never replayed after sign-in. The browser carries the URL fragment (e.g.
 * #tab=attendees) across the redirect by itself, and login.php keeps it.
 */
function auth_login_url_for_request(): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return '/auth/login.php';
    }
    return auth_login_url((string) ($_SERVER['REQUEST_URI'] ?? ''));
}

// --------------------------------------------------------------------------
// The signed-in session
// --------------------------------------------------------------------------

/** Roles that count for this user. Frozen rows never do once security is on. */
function auth_load_roles(PDO $pdo, int $userId, bool $securityOn): array
{
    $sql = "SELECT r.id, r.role_name
              FROM roles r
              JOIN user_roles ur ON r.id = ur.role_id
             WHERE ur.user_id = ?";
    if ($securityOn) {
        // Keep this filter: revoking an account freezes its roles.
        $sql .= " AND COALESCE(ur.is_frozen, 0) = 0";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fill the session for a member who has just proved who they are, by password
 * (api/auth_api.php) or by device token (auth_remember_resume). Rotates the
 * session id first so a planted id never becomes a signed-in one.
 */
function auth_start_session(PDO $pdo, array $user, bool $securityOn): array
{
    $roles = auth_load_roles($pdo, (int) $user['id'], $securityOn);

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['first_name'] = $user['first_name'] ?? '';
    $_SESSION['last_name'] = $user['last_name'] ?? '';
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['picture_path'] = $user['picture_path'] ?? null;
    $_SESSION['auth_started_at'] = time();

    if (count($roles) > 0) {
        $_SESSION['roles'] = $roles;
        $_SESSION['active_role'] = $roles[0]['role_name'];
        $_SESSION['active_role_id'] = $roles[0]['id'];
    } else {
        $_SESSION['roles'] = [];
        $_SESSION['active_role'] = 'Member';
        $_SESSION['active_role_id'] = null;
    }

    return $roles;
}

// --------------------------------------------------------------------------
// Stay signed in
// --------------------------------------------------------------------------

function auth_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
}

/** A DATETIME written by this file or by MySQL (+01:00) as a unix time. */
function auth_time($value): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    try {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('Africa/Lagos')))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function auth_probe(PDO $pdo, string $sql): bool
{
    static $known = null;
    $known ??= new WeakMap();
    $seen = $known[$pdo] ?? [];
    if (!empty($seen[$sql])) {
        return true;
    }
    try {
        $pdo->query($sql)->closeCursor();
    } catch (Throwable $e) {
        return false;
    }
    $seen[$sql] = true;
    $known[$pdo] = $seen;
    return true;
}

function auth_remember_ready(PDO $pdo): bool
{
    return auth_probe($pdo, 'SELECT selector FROM auth_remember_tokens LIMIT 0');
}

function auth_sessions_table_ready(PDO $pdo): bool
{
    return auth_probe($pdo, 'SELECT session_hash FROM user_sessions LIMIT 0');
}

function auth_site_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function auth_remember_set_cookie(string $value, int $expires): void
{
    $_COOKIE[AUTH_REMEMBER_COOKIE] = $value;
    if (headers_sent()) {
        return;
    }
    setcookie(AUTH_REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => auth_site_is_https(),
        'httponly' => true,
        // Lax, not Strict: a notification tap is a top-level navigation and
        // must carry the cookie.
        'samesite' => 'Lax',
    ]);
}

function auth_remember_clear_cookie(): void
{
    unset($_COOKIE[AUTH_REMEMBER_COOKIE], $_SESSION['auth_remember_selector'], $_SESSION['auth_remember_hash']);
    if (headers_sent()) {
        return;
    }
    setcookie(AUTH_REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => auth_site_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** [selector, validator] from the cookie, or null when it is not one of ours. */
function auth_remember_parse($raw): ?array
{
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{18}):([a-f0-9]{64})$/D', $raw, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/** Tell the login screen why this device was signed out (same cookie as the security gate). */
function auth_remember_notice(string $notice): void
{
    if ($notice === '' || headers_sent()) {
        return;
    }
    setcookie(SECURITY_SIGNOUT_COOKIE, $notice, [
        'expires'  => time() + 300,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Keep this device signed in. Called by api/auth_api.php after a successful
 * password sign-in when "Keep me signed in" is ticked.
 */
function auth_remember_issue(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !auth_remember_ready($pdo)) {
        return false;
    }

    try {
        // Signing in again on the same device replaces its token, and expired
        // tokens are swept here (sign-ins are rare enough to carry it).
        $old = auth_remember_parse($_COOKIE[AUTH_REMEMBER_COOKIE] ?? null);
        if ($old !== null) {
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$old[0]]);
        }
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE expires_at < ?")->execute([auth_now()]);

        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + AUTH_REMEMBER_DAYS * 86400;
        $now       = auth_now();
        $authAt    = (new DateTimeImmutable('@' . (int) ($_SESSION['auth_started_at'] ?? time())))
            ->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s');
        $hash      = security_current_session_hash();

        $pdo->prepare("
            INSERT INTO auth_remember_tokens
                (user_id, selector, validator_hash, session_hash, auth_at, user_agent, created_at, last_used_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $selector,
            hash('sha256', $validator),
            $hash !== '' ? $hash : null,
            $authAt,
            substr(security_user_agent(), 0, 255),
            $now,
            $now,
            (new DateTimeImmutable('@' . $expires))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s'),
        ]);

        auth_remember_set_cookie($selector . ':' . $validator, $expires);
        $_SESSION['auth_remember_selector'] = $selector;
        $_SESSION['auth_remember_hash'] = $hash;
        $_SESSION['auth_remember_touched'] = time();
        return true;
    } catch (Throwable $e) {
        error_log('auth_remember_issue: ' . $e->getMessage());
        return false;
    }
}

/**
 * Start a session from this device's token. Returns the member's id, or null
 * (and the cookie is cleared) when the token no longer stands.
 */
function auth_remember_resume(PDO $pdo, string $cookie): ?int
{
    $parts = auth_remember_parse($cookie);
    if ($parts === null) {
        auth_remember_clear_cookie();
        return null;
    }
    if (!auth_remember_ready($pdo)) {
        return null;
    }
    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare("SELECT * FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    $drop = function (string $notice = '') use ($pdo, $selector): ?int {
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$selector]);
        auth_remember_clear_cookie();
        auth_remember_notice($notice);
        return null;
    };

    if (!$token) {
        auth_remember_clear_cookie();
        return null;
    }
    // Right selector, wrong validator: the cookie was tampered with or copied
    // and altered. The token is no longer trustworthy for anyone.
    if (!hash_equals((string) $token['validator_hash'], hash('sha256', $validator))) {
        return $drop();
    }
    if (auth_time($token['expires_at']) < time()) {
        return $drop();
    }

    $userId = (int) $token['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$userId]);
        return $drop();
    }

    $securityOn = security_schema_ready($pdo);

    // The same checks the security gate makes of a live session, made of the
    // token, so a device can never come back from something that ended it.
    $status = strtolower((string) ($user['account_status'] ?? 'active'));
    if ($status === 'suspended') {
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$userId]);
        return $drop('Your account has been suspended by an administrator.');
    }
    if ($status === 'revoked' || $status === 'disabled') {
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$userId]);
        return $drop('Your account access has been revoked. Please contact the church office.');
    }
    $validFrom = auth_time($user['sessions_valid_from'] ?? null);
    if ($validFrom > 0 && $validFrom > auth_time($token['auth_at'])) {
        return $drop('All sessions for your account were signed out. Please sign in again.');
    }
    $oldHash = (string) ($token['session_hash'] ?? '');
    if ($oldHash !== '' && auth_sessions_table_ready($pdo)) {
        $stmt = $pdo->prepare("SELECT revoked_at, revoke_reason FROM user_sessions WHERE session_hash = ? LIMIT 1");
        $stmt->execute([$oldHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['revoked_at'])) {
            $reason = trim((string) ($row['revoke_reason'] ?? ''));
            return $drop('You were signed out' . ($reason !== '' ? ' (' . $reason . ')' : '') . '. Please sign in again.');
        }
    }

    auth_start_session($pdo, $user, $securityOn);
    $_SESSION['must_change_password'] = $securityOn && (int) ($user['must_change_password'] ?? 0) === 1;

    $now     = auth_now();
    $newHash = security_current_session_hash();

    // One row per device in the Security Centre: move this device's row to
    // the new session id instead of adding another every time PHP's session
    // runs out.
    if ($newHash !== '' && auth_sessions_table_ready($pdo)) {
        $moved = 0;
        if ($oldHash !== '') {
            $stmt = $pdo->prepare("
                UPDATE user_sessions
                   SET session_hash = ?, last_seen_at = ?, ip_address = ?, user_agent = ?
                 WHERE session_hash = ? AND user_id = ? AND revoked_at IS NULL
            ");
            $stmt->execute([$newHash, $now, security_client_ip(), substr(security_user_agent(), 0, 255), $oldHash, $userId]);
            $moved = $stmt->rowCount();
        }
        if ($moved === 0) {
            security_register_session($pdo, $userId);
        }
    }

    $expires = time() + AUTH_REMEMBER_DAYS * 86400;
    $pdo->prepare("
        UPDATE auth_remember_tokens
           SET session_hash = ?, auth_at = ?, last_used_at = ?, expires_at = ?
         WHERE selector = ?
    ")->execute([
        $newHash !== '' ? $newHash : null,
        $now,
        $now,
        (new DateTimeImmutable('@' . $expires))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s'),
        $selector,
    ]);

    auth_remember_set_cookie($selector . ':' . $validator, $expires);
    $_SESSION['auth_remember_selector'] = $selector;
    $_SESSION['auth_remember_hash'] = $newHash;
    $_SESSION['auth_remember_touched'] = time();

    return $userId;
}

/**
 * For a live session on a remembered device: keep the token pointed at this
 * session (so revoking the session in the Security Centre ends the device),
 * and slide the 30 days forward once a day.
 */
function auth_remember_sync(PDO $pdo): void
{
    $selector = (string) ($_SESSION['auth_remember_selector'] ?? '');
    if ($selector === '' || empty($_SESSION['user_id'])) {
        return;
    }
    $parts = auth_remember_parse($_COOKIE[AUTH_REMEMBER_COOKIE] ?? null);
    if ($parts === null || $parts[0] !== $selector) {
        unset($_SESSION['auth_remember_selector'], $_SESSION['auth_remember_hash']);
        return;
    }

    $hash    = security_current_session_hash();
    $touched = (int) ($_SESSION['auth_remember_touched'] ?? 0);
    if ($hash === (string) ($_SESSION['auth_remember_hash'] ?? '') && time() - $touched < AUTH_REMEMBER_TOUCH_SECONDS) {
        return;
    }
    if (!auth_remember_ready($pdo)) {
        return;
    }

    $expires = time() + AUTH_REMEMBER_DAYS * 86400;
    $stmt = $pdo->prepare("
        UPDATE auth_remember_tokens
           SET session_hash = ?, last_used_at = ?, expires_at = ?
         WHERE selector = ? AND user_id = ?
    ");
    $stmt->execute([
        $hash !== '' ? $hash : null,
        auth_now(),
        (new DateTimeImmutable('@' . $expires))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s'),
        $selector,
        (int) $_SESSION['user_id'],
    ]);

    if ($stmt->rowCount() === 0) {
        // Revoked while this session was still alive: let it go with the session.
        auth_remember_clear_cookie();
        return;
    }
    auth_remember_set_cookie($selector . ':' . $parts[1], $expires);
    $_SESSION['auth_remember_hash'] = $hash;
    $_SESSION['auth_remember_touched'] = time();
}

/**
 * The per-request hook in includes/db.php, just before the security gate
 * (which then checks the resumed session like any other). Never throws,
 * never prints, never redirects.
 */
function auth_remember_boot(PDO $pdo): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    try {
        if (!empty($_SESSION['user_id'])) {
            auth_remember_sync($pdo);
            return;
        }
        $cookie = $_COOKIE[AUTH_REMEMBER_COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '' && !headers_sent()) {
            auth_remember_resume($pdo, $cookie);
        }
    } catch (Throwable $e) {
        error_log('auth_remember_boot: ' . $e->getMessage());
    }
}

/** Sign-out on this device ends its token too. */
function auth_remember_forget_current(PDO $pdo): void
{
    $parts = auth_remember_parse($_COOKIE[AUTH_REMEMBER_COOKIE] ?? null);
    if ($parts !== null && auth_remember_ready($pdo)) {
        try {
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$parts[0]]);
        } catch (Throwable $e) {
            error_log('auth_remember_forget_current: ' . $e->getMessage());
        }
    }
    auth_remember_clear_cookie();
}

/**
 * Called by security_revoke_sessions(): every remembered device of this member
 * goes, except the one $exceptHash names (the browser changing its own
 * password and keeping itself signed in).
 */
function auth_remember_forget_user(PDO $pdo, int $userId, ?string $exceptHash = null): int
{
    if ($userId <= 0 || !auth_remember_ready($pdo)) {
        return 0;
    }
    try {
        $sql    = "DELETE FROM auth_remember_tokens WHERE user_id = ?";
        $params = [$userId];
        if ($exceptHash !== null && $exceptHash !== '') {
            $sql     .= " AND (session_hash IS NULL OR session_hash <> ?)";
            $params[] = $exceptHash;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('auth_remember_forget_user: ' . $e->getMessage());
        return 0;
    }
}

/** The Security Centre signing out one device. */
function auth_remember_forget_session(PDO $pdo, string $sessionHash): int
{
    if ($sessionHash === '' || !auth_remember_ready($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE session_hash = ?");
        $stmt->execute([$sessionHash]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('auth_remember_forget_session: ' . $e->getMessage());
        return 0;
    }
}
