<?php
// /includes/special_events/security.php
//
// Access levels, per-event capabilities, request integrity, rate limiting,
// masking and the HTTP headers for /e/ pages (guide §6.2, §19.2-§19.7).
//
// Every endpoint calls se_require_capability() server-side. Hiding a control
// in the UI is cosmetic and is never the gate.

// --------------------------------------------------------------------------
// Module-level access (§6.2)
// --------------------------------------------------------------------------

/**
 * The module access level of a user: 'manager', 'studio_member', 'crew_only'
 * or 'none'.
 *
 * $_SESSION['active_role'] MUST NOT decide this (§6.2): a pastor whose active
 * role is "Church_Member" still manages events, so the whole roles list is
 * read, exactly as includes/header.php does.
 *
 * @param array $sessionRoles The user's full role list ($_SESSION['roles']).
 */
function se_module_access(PDO $pdo, int $userId, array $sessionRoles = []): string
{
    static $cache = [];
    if ($userId <= 0) {
        return 'none';
    }
    // $_SESSION['roles'] holds rows like ['id' => 1, 'role_name' => '...']
    // (api/auth_api.php), but a caller may pass plain names. Flatten first,
    // then key the cache off the names.
    $roles = [];
    foreach ($sessionRoles as $role) {
        if (is_string($role)) {
            $roles[] = $role;
        } elseif (is_array($role) && isset($role['role_name'])) {
            $roles[] = (string) $role['role_name'];
        }
    }

    $key = $userId . '|' . implode(',', $roles);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (array_intersect($roles, SE_MANAGER_ROLES)) {
        return $cache[$key] = 'manager';
    }

    try {
        $deptId = se_envision_department_id($pdo);

        $stmt = $pdo->prepare(
            "SELECT role_in_dept FROM user_departments
             WHERE user_id = ? AND department_id = ? AND is_active = 1"
        );
        $stmt->execute([$userId, $deptId]);
        $deptRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if ($deptRoles) {
            foreach ($deptRoles as $deptRole) {
                if (in_array((string) $deptRole, SE_MANAGER_DEPT_ROLES, true)) {
                    return $cache[$key] = 'manager';
                }
            }
            return $cache[$key] = 'studio_member';
        }

        // Crew on any non-archived event.
        if (se_table_exists($pdo, 'se_crew')) {
            $stmt = $pdo->prepare(
                "SELECT c.id FROM se_crew c
                 JOIN se_events e ON e.id = c.event_id
                 WHERE c.user_id = ? AND c.revoked_at IS NULL AND e.status <> 'archived'
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() !== false) {
                return $cache[$key] = 'crew_only';
            }
        }
    } catch (Throwable $e) {
        // A missing table must never hand out access, nor break the caller.
        error_log('SE security/module_access: ' . $e->getMessage());
        return $cache[$key] = 'none';
    }

    return $cache[$key] = 'none';
}

/** Envision's department id: se_settings override, else SE_ENVISION_DEPT_ID. */
function se_envision_department_id(PDO $pdo): int
{
    $configured = se_setting_get($pdo, 'envision_department_id');
    $id         = is_numeric($configured) ? (int) $configured : 0;

    return $id > 0 ? $id : SE_ENVISION_DEPT_ID;
}

/** True when the module link should appear in the ERP sidebar (§21.1). */
function se_nav_visible(PDO $pdo, int $userId, array $sessionRoles = []): bool
{
    return se_module_access($pdo, $userId, $sessionRoles) !== 'none';
}

/** The roles list from the session, tolerating both shapes used in the app. */
function se_session_roles(): array
{
    $roles = $_SESSION['roles'] ?? [];
    if (!is_array($roles)) {
        $roles = [];
    }
    // A single active role is still a role, in case 'roles' was never set.
    if (!empty($_SESSION['active_role'])) {
        $roles[] = $_SESSION['active_role'];
    }

    return $roles;
}

// --------------------------------------------------------------------------
// Event-level capabilities (§6.2)
// --------------------------------------------------------------------------

/**
 * Every capability a user holds on one event.
 *
 * A manager holds all of them. A crew member holds the union of their
 * non-revoked roles. A studio_member with no crew role holds only
 * 'insights.view' (the Overview aggregates every studio member may read).
 */
function se_event_capabilities(PDO $pdo, int $eventId, int $userId, ?string $level = null): array
{
    $level ??= se_module_access($pdo, $userId, se_session_roles());

    if ($level === 'manager') {
        $all = SE_MANAGER_ONLY_CAPABILITIES;
        foreach (SE_CAPABILITIES as $caps) {
            $all = array_merge($all, $caps);
        }
        return array_values(array_unique($all));
    }
    if ($level === 'none' || $eventId <= 0) {
        return [];
    }

    $caps = $level === 'studio_member' ? ['insights.view'] : [];
    try {
        if (se_table_exists($pdo, 'se_crew')) {
            $stmt = $pdo->prepare(
                "SELECT role FROM se_crew WHERE event_id = ? AND user_id = ? AND revoked_at IS NULL"
            );
            $stmt->execute([$eventId, $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $role) {
                $caps = array_merge($caps, SE_CAPABILITIES[(string) $role] ?? []);
            }
        }
    } catch (Throwable $e) {
        error_log('SE security/event_capabilities: ' . $e->getMessage());
        return [];
    }

    return array_values(array_unique($caps));
}

/** True when the user holds $capability on $eventId. */
function se_has_capability(PDO $pdo, int $eventId, string $capability, ?int $userId = null): bool
{
    $userId ??= (int) ($_SESSION['user_id'] ?? 0);

    return in_array($capability, se_event_capabilities($pdo, $eventId, $userId), true);
}

/**
 * Gate an endpoint action. Emits the JSON error envelope and exits when the
 * caller is not allowed, so a handler can simply call this and carry on.
 */
function se_require_capability(PDO $pdo, int $eventId, string $capability): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        se_api_error('Please sign in to continue.', 'UNAUTHENTICATED');
    }
    if (!se_has_capability($pdo, $eventId, $capability, $userId)) {
        se_api_error('You do not have permission to do that.', 'FORBIDDEN');
    }
}

/** Gate a manager-only action that is not tied to one event. */
function se_require_manager(PDO $pdo): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        se_api_error('Please sign in to continue.', 'UNAUTHENTICATED');
    }
    if (se_module_access($pdo, $userId, se_session_roles()) !== 'manager') {
        se_api_error('Only an administrator can do that.', 'FORBIDDEN');
    }
}

/** Gate entry to the Studio at all (any level above 'none'). */
function se_require_module_access(PDO $pdo): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        se_api_error('Please sign in to continue.', 'UNAUTHENTICATED');
    }
    $level = se_module_access($pdo, $userId, se_session_roles());
    if ($level === 'none') {
        se_api_error('Special Events is available to the Envision team.', 'FORBIDDEN');
    }

    return $level;
}

// --------------------------------------------------------------------------
// CSRF (§19.3)
// --------------------------------------------------------------------------

/** The session's CSRF token, created on first use. Embedded in page boot data. */
function se_csrf_token(): string
{
    if (empty($_SESSION['se_csrf']) || !is_string($_SESSION['se_csrf']) || strlen($_SESSION['se_csrf']) !== 64) {
        $_SESSION['se_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['se_csrf'];
}

// --------------------------------------------------------------------------
// Request integrity (§19.3)
// --------------------------------------------------------------------------

/**
 * Checks shared by every module POST:
 *   - X-SE-Request: 1          (cross-origin pages cannot set it without a
 *                               CORS preflight, which we never grant)
 *   - Origin host == HTTP_HOST (when the header is present)
 *   - Sec-Fetch-Site in {same-origin, none} (when present)
 *
 * Emits the error envelope and exits on failure.
 */
function se_require_request_integrity(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        se_api_error('This endpoint only accepts POST.', 'BAD_REQUEST');
    }
    if (($_SERVER['HTTP_X_SE_REQUEST'] ?? '') !== '1') {
        se_api_error('Malformed request.', 'BAD_REQUEST');
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        if ($originPort) {
            $originHost .= ':' . $originPort;
        }
        if (!is_string($originHost) || $originHost === '' || !hash_equals($host, $originHost)) {
            se_api_error('Malformed request.', 'BAD_REQUEST');
        }
    }

    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        se_api_error('Malformed request.', 'BAD_REQUEST');
    }
}

/** The extra CSRF header required of crew and Studio POSTs (§19.3). */
function se_require_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_SE_CSRF'] ?? '');
    $have = (string) ($_SESSION['se_csrf'] ?? '');
    if ($sent === '' || $have === '' || !hash_equals($have, $sent)) {
        se_api_error('Your session expired. Please reload the page.', 'CSRF');
    }
}

// --------------------------------------------------------------------------
// Rate limiting (§19.4)
// --------------------------------------------------------------------------

/**
 * Fixed-window counter. Returns true when the request is ALLOWED.
 *
 * INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1, then read the value, so
 * two concurrent requests can never both see the pre-increment count.
 */
function se_rate_limit(PDO $pdo, string $bucket, string $subjectHash, int $limit, int $windowSeconds): bool
{
    if ($limit <= 0 || $windowSeconds <= 0) {
        return true;
    }
    if (!se_table_exists($pdo, 'se_rate_limits')) {
        return true;   // Degrade open rather than lock everyone out (§9.4).
    }

    try {
        $now         = time();
        $windowStart = date('Y-m-d H:i:s', $now - ($now % $windowSeconds));

        $stmt = $pdo->prepare(
            "INSERT INTO se_rate_limits (bucket, subject_hash, window_start, hits)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1"
        );
        $stmt->execute([$bucket, $subjectHash, $windowStart]);

        $stmt = $pdo->prepare(
            "SELECT hits FROM se_rate_limits
             WHERE bucket = ? AND subject_hash = ? AND window_start = ?"
        );
        $stmt->execute([$bucket, $subjectHash, $windowStart]);

        return ((int) $stmt->fetchColumn()) <= $limit;
    } catch (Throwable $e) {
        error_log('SE security/rate_limit: ' . $e->getMessage());
        return true;
    }
}

/**
 * Apply a bucket to the current request and exit with RATE_LIMITED if spent.
 * `$subject` is already a stable identifier (a token, a phone, an IP); it is
 * hashed here so the raw value never reaches the table.
 */
function se_rate_limit_or_fail(PDO $pdo, string $bucket, string $subject, int $limit, int $windowSeconds): void
{
    if (!se_has_hash_pepper()) {
        return;
    }
    if (!se_rate_limit($pdo, $bucket, se_hmac('ratelimit/' . $bucket, $subject), $limit, $windowSeconds)) {
        $retry = $windowSeconds - (time() % $windowSeconds);
        header('Retry-After: ' . $retry);
        http_response_code(429);
        se_api_error(
            'That was a lot of requests. Please wait a moment and try again.',
            'RATE_LIMITED',
            ['retry_after_s' => $retry]
        );
    }
}

/** HMAC of the caller's IP, for IP-keyed buckets and audit rows. */
function se_ip_hash(): ?string
{
    if (!se_has_hash_pepper()) {
        return null;
    }
    if (!function_exists('security_client_ip')) {
        return null;
    }

    return se_hmac('ip', security_client_ip());
}

/** HMAC of the caller's user agent. */
function se_ua_hash(): ?string
{
    if (!se_has_hash_pepper()) {
        return null;
    }

    return se_hmac('ua', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

// --------------------------------------------------------------------------
// Page headers (§19.7)
// --------------------------------------------------------------------------

/** A fresh CSP nonce for one page render. */
function se_csp_nonce(): string
{
    static $nonce = null;

    return $nonce ??= base64_encode(random_bytes(16));
}

/**
 * Send the security headers for a public /e/ page (§19.7).
 *
 * GSAP and Preact change styles through the CSSOM, which CSP does not
 * restrict, so no 'unsafe-inline' is needed for them — only the nonce on the
 * theme <style> block and the import map.
 */
function se_send_page_headers(string $nonce, bool $allowYouTube = true): void
{
    if (headers_sent()) {
        return;
    }

    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https://i.ytimg.com",
        "media-src 'self' blob:",
        "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com",
        $allowYouTube ? 'frame-src https://www.youtube-nocookie.com' : "frame-src 'none'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
    ];

    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), screen-wake-lock=(self), fullscreen=(self)');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/** The JSON headers every module endpoint sends (§19.7). */
function se_send_api_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}
