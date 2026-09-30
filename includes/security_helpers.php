<?php
// /includes/security_helpers.php
//
// Shared account-security primitives used by:
//   - includes/db.php              (per-request session enforcement)
//   - api/auth_api.php             (login gate, lockout, login history)
//   - api/profile_api.php          (self-service password change)
//   - api/security_api.php         (admin Security Centre)
//   - api/setup_password_api.php   (first-time password setup only)
//
// Schema lives in db/migrations/20261005090000_security_core.sql. Every
// function degrades to a safe no-op when that migration has not been applied
// yet, so the tree can be rsynced to production before `php db/migrate.php`
// runs without white-screening the site.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------------------------------------------
// Tunables
// --------------------------------------------------------------------------

/** Consecutive failed logins before the account is temporarily locked. */
const SECURITY_MAX_FAILED_LOGINS = 5;

/** How long an auto-lock lasts, in minutes. */
const SECURITY_LOCK_MINUTES = 15;

/** Minimum password length enforced on every new/changed password. */
const SECURITY_MIN_PASSWORD_LENGTH = 10;

/** Don't hammer the DB updating last_seen_at on every single request. */
const SECURITY_SESSION_TOUCH_SECONDS = 60;

/** Failed "current password" attempts allowed per user per window. */
const SECURITY_MAX_PASSWORD_CHANGE_FAILURES = 5;
const SECURITY_PASSWORD_CHANGE_WINDOW_MINUTES = 15;

/** Cookie used to tell the login screen why the user was kicked out. */
const SECURITY_SIGNOUT_COOKIE = 'hod_signout_notice';

/**
 * Domain every system-generated sign-in email lives in (users.email). Members
 * sign in with this address; their personal address is users.real_email.
 */
const SECURITY_LOGIN_EMAIL_DOMAIN = 'hodlc.com';

/** Hard cap for users.email — matches login_attempts.email VARCHAR(190). */
const SECURITY_LOGIN_EMAIL_MAX_LENGTH = 190;

// --------------------------------------------------------------------------
// Request context
// --------------------------------------------------------------------------

if (!function_exists('security_client_ip')) {
    /**
     * Best-effort client IP. LiteSpeed on the cPanel host forwards the real
     * address in X-Forwarded-For; we take the left-most public-looking entry.
     */
    function security_client_ip(): string
    {
        $candidates = [];

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
                $candidates[] = trim($part);
            }
        }
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = trim($_SERVER['REMOTE_ADDR']);
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return substr($ip, 0, 45);
            }
        }

        return 'unknown';
    }
}

if (!function_exists('security_user_agent')) {
    function security_user_agent(): string
    {
        return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }
}

if (!function_exists('security_describe_device')) {
    /** Turn a raw user agent into something a pastor can read in a table. */
    function security_describe_device(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return 'Unknown device';
        }

        $os = 'Unknown OS';
        foreach ([
            'Windows'   => 'Windows',
            'iPhone'    => 'iPhone',
            'iPad'      => 'iPad',
            'Android'   => 'Android',
            'Macintosh' => 'macOS',
            'Mac OS X'  => 'macOS',
            'Linux'     => 'Linux',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $os = $label;
                break;
            }
        }

        $browser = 'Unknown browser';
        foreach ([
            'Edg'     => 'Edge',
            'OPR'     => 'Opera',
            'Chrome'  => 'Chrome',
            'Safari'  => 'Safari',
            'Firefox' => 'Firefox',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $browser = $label;
                break;
            }
        }

        return $browser . ' on ' . $os;
    }
}

if (!function_exists('security_current_session_hash')) {
    /** sha256 of the PHP session id. The raw id is never written to the DB. */
    function security_current_session_hash(): string
    {
        $sid = session_id();
        return $sid === '' ? '' : hash('sha256', $sid);
    }
}

// --------------------------------------------------------------------------
// Schema readiness
// --------------------------------------------------------------------------

if (!function_exists('security_schema_ready')) {
    /**
     * True once 20261005090000_security_core.sql has been applied. Result is
     * cached per request and, once true, remembered for the session so we only
     * pay for the information_schema lookup once per visitor.
     */
    function security_schema_ready(PDO $pdo): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        if (!empty($_SESSION['__security_schema_ok'])) {
            $ready = true;
            return true;
        }

        try {
            $row = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'users'
                        AND column_name = 'account_status')            AS has_status,
                    (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'user_sessions')      AS has_sessions,
                    (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'security_audit_log') AS has_audit,
                    (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'login_attempts')     AS has_attempts
            ")->fetch(PDO::FETCH_ASSOC);

            $ready = $row
                && (int) $row['has_status'] === 1
                && (int) $row['has_sessions'] === 1
                && (int) $row['has_audit'] === 1
                && (int) $row['has_attempts'] === 1;
        } catch (Throwable $e) {
            error_log('security_schema_ready: ' . $e->getMessage());
            $ready = false;
        }

        if ($ready) {
            $_SESSION['__security_schema_ok'] = true;
        }

        return $ready;
    }
}

// --------------------------------------------------------------------------
// Password policy
// --------------------------------------------------------------------------

if (!function_exists('security_password_rules')) {
    /** Human-readable rules, mirrored by the strength meter in the UI. */
    function security_password_rules(): array
    {
        return [
            'At least ' . SECURITY_MIN_PASSWORD_LENGTH . ' characters long',
            'At least one UPPERCASE letter',
            'At least one lowercase letter',
            'At least one number',
            'Not your name, email or an obvious word',
        ];
    }
}

if (!function_exists('security_password_problems')) {
    /**
     * Returns a list of human-readable reasons the password is unacceptable.
     * An empty array means the password passes policy.
     *
     * @param array $user Optional user row (first_name, last_name, email) so we
     *                    can reject passwords built from the person's own name.
     */
    function security_password_problems(string $password, array $user = []): array
    {
        $problems = [];

        if (strlen($password) < SECURITY_MIN_PASSWORD_LENGTH) {
            $problems[] = 'It must be at least ' . SECURITY_MIN_PASSWORD_LENGTH . ' characters long.';
        }
        if (strlen($password) > 200) {
            $problems[] = 'It must be shorter than 200 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $problems[] = 'It must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $problems[] = 'It must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $problems[] = 'It must contain at least one number.';
        }
        if (preg_match('/^\s|\s$/', $password)) {
            $problems[] = 'It cannot start or end with a space.';
        }

        $lower = strtolower($password);

        $banned = [
            'password', 'passw0rd', 'password1', 'qwerty', 'letmein', 'welcome',
            'iloveyou', 'admin123', 'abc12345', '12345678', '123456789', '1234567890',
            'hodlekki', 'hodlagos', 'household', 'jesuschrist', 'changeme',
        ];
        foreach ($banned as $bad) {
            if ($lower === $bad || str_contains($lower, $bad)) {
                $problems[] = 'It contains a word that is far too easy to guess (' . $bad . ').';
                break;
            }
        }

        if (preg_match('/^(.)\1+$/', $password)) {
            $problems[] = 'It cannot be the same character repeated.';
        }

        // Reject passwords built out of the member's own identity.
        $identityBits = [];
        foreach (['first_name', 'last_name'] as $field) {
            if (!empty($user[$field])) {
                $identityBits[] = strtolower(trim((string) $user[$field]));
            }
        }
        if (!empty($user['email']) && str_contains((string) $user['email'], '@')) {
            $identityBits[] = strtolower(explode('@', (string) $user['email'])[0]);
        }
        foreach ($identityBits as $bit) {
            if (strlen($bit) >= 4 && str_contains($lower, $bit)) {
                $problems[] = 'It cannot contain your own name or email address.';
                break;
            }
        }

        return $problems;
    }
}

if (!function_exists('security_suggest_password')) {
    /** Generates a strong password that satisfies security_password_problems(). */
    function security_suggest_password(): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // no I/O
        $lower  = 'abcdefghijkmnopqrstuvwxyz';  // no l
        $digits = '23456789';                   // no 0/1
        $all    = $upper . $lower . $digits;

        $out = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        for ($i = count($out); $i < 14; $i++) {
            $out[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($out);

        return implode('', $out);
    }
}

// --------------------------------------------------------------------------
// Login-email policy (admin Security Centre)
// --------------------------------------------------------------------------

if (!function_exists('security_login_email_prefix')) {
    /**
     * The username part for a generated sign-in email, built the same way
     * api/congregation_api.php builds one for a new member: the shortest
     * name part of each name ("Oluchi Chiamaka" -> "oluchi.chiamaka"),
     * falling back to whichever name exists. Returns '' when neither name
     * yields anything usable (e.g. both names are punctuation).
     */
    function security_login_email_prefix(string $firstName, string $lastName): string
    {
        $shortest = static function (string $name): string {
            $best = '';
            foreach (preg_split('/[\s\-]+/', trim($name)) ?: [] as $part) {
                $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $part) ?? '');
                if ($clean === '') {
                    continue;
                }
                if ($best === '' || strlen($clean) < strlen($best)) {
                    $best = $clean;
                }
            }
            return $best;
        };

        $first = $shortest($firstName);
        $last  = $shortest($lastName);

        if ($first !== '' && $last !== '') {
            // Same 20-character ceiling the congregation creator applies.
            $combined = $first . '.' . $last;
            return strlen($combined) > 20 ? $first : $combined;
        }

        return $first !== '' ? $first : $last;
    }
}

if (!function_exists('security_generate_login_email')) {
    /**
     * Builds a unique @hodlc.com sign-in email for a member who has none.
     *
     * Appends 2, 3, … while the address is taken, checking both the live
     * table and the $reserved list (addresses handed out earlier in the same
     * batch, which are not in the DB yet).
     *
     * @param string[] $reserved Lowercase addresses to treat as taken.
     * @return string|null The email, or null when no prefix could be built.
     */
    function security_generate_login_email(PDO $pdo, string $firstName, string $lastName, array $reserved = []): ?string
    {
        $prefix = security_login_email_prefix($firstName, $lastName);
        if ($prefix === '') {
            return null;
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');

        $candidate = $prefix . '@' . SECURITY_LOGIN_EMAIL_DOMAIN;
        $counter   = 1;

        while (true) {
            $taken = in_array($candidate, $reserved, true);
            if (!$taken) {
                $check->execute([$candidate]);
                $taken = $check->fetch() !== false;
            }
            if (!$taken) {
                return $candidate;
            }

            $counter++;
            $candidate = $prefix . $counter . '@' . SECURITY_LOGIN_EMAIL_DOMAIN;
        }
    }
}

if (!function_exists('security_normalize_login_email')) {
    /**
     * Normalises an admin-supplied sign-in email.
     *
     * Accepts either a bare prefix ("grace") or a full address
     * ("grace@hodlc.com") and returns the full lowercase address, or null when
     * the input can never be a valid sign-in email.
     */
    function security_normalize_login_email(string $raw): ?string
    {
        $email = strtolower(trim($raw));

        if ($email === '') {
            return null;
        }
        if (!str_contains($email, '@')) {
            $email .= '@' . SECURITY_LOGIN_EMAIL_DOMAIN;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (strlen($email) > SECURITY_LOGIN_EMAIL_MAX_LENGTH) {
            return null;
        }

        return $email;
    }
}

if (!function_exists('security_login_email_problems')) {
    /**
     * Human-readable reasons a new sign-in email is rejected (mirrors
     * security_password_problems). An empty array means the address is fine.
     *
     * Sign-in emails are locked to the church system domain on purpose: it
     * keeps the login identity (users.email) clearly separate from the
     * member's personal inbox (users.real_email).
     */
    function security_login_email_problems(string $raw): array
    {
        $trimmed = strtolower(trim($raw));

        if ($trimmed === '') {
            return ['Type the new sign-in email first.'];
        }
        if (strlen($trimmed) > SECURITY_LOGIN_EMAIL_MAX_LENGTH) {
            return ['It must be shorter than ' . SECURITY_LOGIN_EMAIL_MAX_LENGTH . ' characters.'];
        }

        $email = $trimmed;
        if (!str_contains($email, '@')) {
            $email .= '@' . SECURITY_LOGIN_EMAIL_DOMAIN;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['That is not a valid email address.'];
        }

        if (!str_ends_with($email, '@' . SECURITY_LOGIN_EMAIL_DOMAIN)) {
            return [
                'Sign-in emails must end in @' . SECURITY_LOGIN_EMAIL_DOMAIN
                . ' (the church system address) — to change their personal email, edit it on the member profile instead.',
            ];
        }

        return [];
    }
}

// --------------------------------------------------------------------------
// Audit trail + login history
// --------------------------------------------------------------------------

if (!function_exists('security_log')) {
    /**
     * Writes one row to security_audit_log. Never throws — an audit failure
     * must not break the action the user is performing (it is logged instead).
     */
    function security_log(
        PDO $pdo,
        string $action,
        ?int $targetUserId = null,
        string $details = '',
        ?int $actorUserId = null,
        ?string $actorLabel = null
    ): void {
        if (!security_schema_ready($pdo)) {
            return;
        }

        if ($actorUserId === null && isset($_SESSION['user_id'])) {
            $actorUserId = (int) $_SESSION['user_id'];
        }
        if ($actorLabel === null) {
            $actorLabel = trim(
                (string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '')
            );
            if ($actorLabel === '') {
                $actorLabel = 'System';
            }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO security_audit_log
                    (actor_user_id, actor_label, target_user_id, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $actorUserId,
                substr($actorLabel, 0, 150),
                $targetUserId,
                substr($action, 0, 60),
                $details,
                security_client_ip(),
                security_user_agent(),
            ]);
        } catch (Throwable $e) {
            error_log('security_log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('security_record_attempt')) {
    /** Records a login (or password-change) attempt. Never throws. */
    function security_record_attempt(
        PDO $pdo,
        ?int $userId,
        string $email,
        bool $successful,
        ?string $failureReason = null,
        string $context = 'login'
    ): void {
        if (!security_schema_ready($pdo)) {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts
                    (user_id, email, was_successful, failure_reason, context, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                substr($email, 0, 190),
                $successful ? 1 : 0,
                $failureReason === null ? null : substr($failureReason, 0, 80),
                substr($context, 0, 40),
                security_client_ip(),
                security_user_agent(),
            ]);
        } catch (Throwable $e) {
            error_log('security_record_attempt failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('security_recent_failure_count')) {
    /** Failures for this user + context inside the rolling window. */
    function security_recent_failure_count(PDO $pdo, int $userId, string $context, int $windowMinutes): int
    {
        if (!security_schema_ready($pdo)) {
            return 0;
        }

        // $windowMinutes is an internal constant, never user input, and MySQL
        // will not take a placeholder for an INTERVAL quantity reliably under
        // native prepares — so it is cast and inlined while the real inputs
        // stay bound.
        $window = max(1, (int) $windowMinutes);

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM login_attempts
                 WHERE user_id = ? AND context = ? AND was_successful = 0
                   AND created_at >= (NOW() - INTERVAL {$window} MINUTE)
            ");
            $stmt->execute([$userId, $context]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('security_recent_failure_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

// --------------------------------------------------------------------------
// Session registry
// --------------------------------------------------------------------------

if (!function_exists('security_register_session')) {
    /** Called right after a successful login to register the browser session. */
    function security_register_session(PDO $pdo, int $userId): void
    {
        if (!security_schema_ready($pdo)) {
            return;
        }

        $hash = security_current_session_hash();
        if ($hash === '') {
            return;
        }

        // Sessions that predate this feature have no start stamp; give them one
        // now so "sign out everywhere" can reason about them.
        if (empty($_SESSION['auth_started_at'])) {
            $_SESSION['auth_started_at'] = time();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_id       = VALUES(user_id),
                    ip_address    = VALUES(ip_address),
                    user_agent    = VALUES(user_agent),
                    last_seen_at  = NOW(),
                    created_at    = NOW(),
                    revoked_at    = NULL,
                    revoked_by    = NULL,
                    revoke_reason = NULL
            ");
            $stmt->execute([$userId, $hash, security_client_ip(), security_user_agent()]);
        } catch (Throwable $e) {
            error_log('security_register_session failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('security_revoke_sessions')) {
    /**
     * Kills sessions for a user.
     *
     * Sets users.sessions_valid_from = NOW() as a blunt kill-switch (this also
     * catches sessions created before the user_sessions table existed) and
     * stamps revoked_at on every matching session row.
     *
     * @param string|null $exceptHash Session hash to keep alive (the actor's own
     *                                browser when they change their own password).
     * @return int Number of session rows revoked.
     */
    function security_revoke_sessions(
        PDO $pdo,
        int $userId,
        ?int $actorId,
        string $reason,
        ?string $exceptHash = null
    ): int {
        if (!security_schema_ready($pdo)) {
            return 0;
        }

        try {
            $sql    = "UPDATE user_sessions
                          SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
                        WHERE user_id = ? AND revoked_at IS NULL";
            $params = [$actorId, substr($reason, 0, 160), $userId];

            if ($exceptHash !== null && $exceptHash !== '') {
                $sql     .= " AND session_hash <> ?";
                $params[] = $exceptHash;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();

            // Blunt kill-switch for anything not in the registry.
            if ($exceptHash === null || $exceptHash === '') {
                $pdo->prepare("UPDATE users SET sessions_valid_from = NOW() WHERE id = ?")
                    ->execute([$userId]);
            } else {
                // Keep the current browser alive: only invalidate sessions that
                // started before this one did. Writing the value back into the
                // session keeps the two in step for legacy sessions that had no
                // start stamp.
                $started = isset($_SESSION['auth_started_at']) ? (int) $_SESSION['auth_started_at'] : time();
                $_SESSION['auth_started_at'] = $started;
                $pdo->prepare("UPDATE users SET sessions_valid_from = FROM_UNIXTIME(?) WHERE id = ?")
                    ->execute([$started, $userId]);
            }

            return $count;
        } catch (Throwable $e) {
            error_log('security_revoke_sessions failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('security_destroy_current_session')) {
    /**
     * Tears down the caller's session and leaves a short-lived cookie so
     * /auth/login.php can explain what happened. Does NOT redirect: callers
     * (header.php for pages, the `!isset($_SESSION['user_id'])` guard in every
     * api/*.php) already handle an unauthenticated request correctly, and a
     * redirect here would corrupt JSON responses.
     */
    function security_destroy_current_session(string $notice = ''): void
    {
        if ($notice !== '' && !headers_sent()) {
            setcookie(SECURITY_SIGNOUT_COOKIE, $notice, [
                'expires'  => time() + 300,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('security_enforce_session')) {
    /**
     * Per-request gate, called at the bottom of includes/db.php so it covers
     * every authenticated page AND every API endpoint with a single hook.
     *
     * Kills the session when the account has been suspended/revoked, when the
     * session has been individually revoked, or when an admin pressed
     * "sign out everywhere".
     */
    function security_enforce_session(PDO $pdo): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (empty($_SESSION['user_id'])) {
            return;
        }
        if (!security_schema_ready($pdo)) {
            return;
        }

        $userId = (int) $_SESSION['user_id'];
        $hash   = security_current_session_hash();

        try {
            $stmt = $pdo->prepare("
                SELECT u.account_status,
                       u.status_reason,
                       UNIX_TIMESTAMP(u.sessions_valid_from) AS valid_from,
                       u.must_change_password,
                       s.id           AS session_row_id,
                       s.revoked_at,
                       s.revoke_reason,
                       UNIX_TIMESTAMP(s.last_seen_at) AS last_seen
                  FROM users u
             LEFT JOIN user_sessions s
                    ON s.session_hash = ? AND s.user_id = u.id
                 WHERE u.id = ?
                 LIMIT 1
            ");
            $stmt->execute([$hash, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('security_enforce_session query failed: ' . $e->getMessage());
            return;
        }

        if (!$row) {
            security_destroy_current_session('Your account no longer exists. Please contact the church office.');
            return;
        }

        $status = strtolower((string) ($row['account_status'] ?? 'active'));
        if ($status === 'suspended') {
            security_destroy_current_session('Your account has been suspended by an administrator.');
            return;
        }
        if ($status === 'revoked' || $status === 'disabled') {
            security_destroy_current_session('Your account access has been revoked. Please contact the church office.');
            return;
        }

        if (!empty($row['revoked_at'])) {
            $reason = trim((string) ($row['revoke_reason'] ?? ''));
            security_destroy_current_session(
                'You were signed out' . ($reason !== '' ? ' (' . $reason . ')' : '') . '. Please sign in again.'
            );
            return;
        }

        $validFrom = $row['valid_from'] !== null ? (int) $row['valid_from'] : 0;
        if ($validFrom > 0) {
            $startedAt = isset($_SESSION['auth_started_at']) ? (int) $_SESSION['auth_started_at'] : 0;
            if ($startedAt < $validFrom) {
                security_destroy_current_session('All sessions for your account were signed out. Please sign in again.');
                return;
            }
        }

        // Surfaced to includes/header.php, which bounces the user to
        // /auth/change_password.php until they pick a new password.
        $_SESSION['must_change_password'] = (int) ($row['must_change_password'] ?? 0) === 1;

        // Keep the session registry honest for logins that predate this feature.
        if (empty($row['session_row_id'])) {
            security_register_session($pdo, $userId);
            return;
        }

        // Throttled heartbeat so the admin Sessions tab shows real activity.
        $lastSeen = (int) ($row['last_seen'] ?? 0);
        if (time() - $lastSeen >= SECURITY_SESSION_TOUCH_SECONDS) {
            try {
                $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW(), ip_address = ? WHERE id = ?")
                    ->execute([security_client_ip(), (int) $row['session_row_id']]);
            } catch (Throwable $e) {
                error_log('security_enforce_session touch failed: ' . $e->getMessage());
            }
        }
    }
}

// --------------------------------------------------------------------------
// Authorisation helpers for the admin Security Centre
// --------------------------------------------------------------------------

if (!function_exists('security_admin_roles')) {
    /** Roles allowed into modules/security + api/security_api.php. */
    function security_admin_roles(): array
    {
        return ['Super_Admin', 'Resident_Pastor'];
    }
}

if (!function_exists('security_user_role_names')) {
    /**
     * All EFFECTIVE role names held by a user, read live from the DB (never
     * cached). Frozen rows are excluded so a revoked account cannot keep its
     * Security Centre clearance just because it was reinstated — it has to
     * have its roles explicitly restored first, exactly like api/auth_api.php
     * does when it builds $_SESSION['roles'].
     */
    function security_user_role_names(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT r.role_name
                  FROM user_roles ur
                  JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ?
                   AND COALESCE(ur.is_frozen, 0) = 0
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            error_log('security_user_role_names failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('security_is_admin')) {
    /**
     * Security Centre clearance. Checks the live roles table rather than
     * trusting $_SESSION['active_role'], which the user can switch themselves.
     */
    function security_is_admin(PDO $pdo, ?int $userId = null): bool
    {
        $userId = $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);
        if ($userId <= 0) {
            return false;
        }

        $held = security_user_role_names($pdo, $userId);
        foreach (security_admin_roles() as $allowed) {
            if (in_array($allowed, $held, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('security_is_super_admin')) {
    function security_is_super_admin(PDO $pdo, ?int $userId = null): bool
    {
        $userId = $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);
        if ($userId <= 0) {
            return false;
        }
        return in_array('Super_Admin', security_user_role_names($pdo, $userId), true);
    }
}

if (!function_exists('security_guard_target')) {
    /**
     * Guard rails so the Security Centre can't be used to lock everyone out.
     *
     *  - Nobody can revoke or reset themselves from the admin screen.
     *  - A Resident_Pastor cannot act on a Super_Admin.
     *  - The last remaining active Super_Admin cannot be revoked or suspended.
     *
     * @return string|null Error message, or null when the action is allowed.
     */
    function security_guard_target(PDO $pdo, int $actorId, int $targetId, string $action): ?string
    {
        if ($targetId <= 0) {
            return 'No member was selected.';
        }

        if ($actorId === $targetId) {
            return 'For safety you cannot run security actions on your own account here. '
                 . 'Use My Profile → Security to change your own password, or ask another admin '
                 . 'to change your own sign-in email.';
        }

        $targetIsSuper = security_is_super_admin($pdo, $targetId);

        if ($targetIsSuper && !security_is_super_admin($pdo, $actorId)) {
            return 'Only a Super Admin can run security actions against another Super Admin.';
        }

        if ($targetIsSuper && in_array($action, ['suspend', 'revoke'], true)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT u.id)
                      FROM users u
                      JOIN user_roles ur ON ur.user_id = u.id
                      JOIN roles r       ON r.id = ur.role_id
                     WHERE r.role_name = 'Super_Admin'
                       AND u.account_status = 'active'
                       AND COALESCE(ur.is_frozen, 0) = 0
                       AND u.id <> ?
                ");
                $stmt->execute([$targetId]);
                if ((int) $stmt->fetchColumn() === 0) {
                    return 'Blocked: this is the last active Super Admin. '
                         . 'Promote another Super Admin before locking this one out.';
                }
            } catch (Throwable $e) {
                error_log('security_guard_target failed: ' . $e->getMessage());
                return 'Could not verify Super Admin coverage. Action cancelled for safety.';
            }
        }

        return null;
    }
}

if (!function_exists('security_notify_user')) {
    /** Drops a row into system_notifications. Never throws. */
    function security_notify_user(PDO $pdo, int $userId, string $title, string $message): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_notifications (user_id, title, message, link_url)
                VALUES (?, ?, ?, '/modules/profile/index.php')
            ");
            $stmt->execute([$userId, substr($title, 0, 120), $message]);
        } catch (Throwable $e) {
            error_log('security_notify_user failed: ' . $e->getMessage());
        }
    }
}
