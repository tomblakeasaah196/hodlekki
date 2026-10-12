<?php
// /includes/push_helpers.php
/**
 * ============================================================================
 * WEB PUSH — subscriptions, queue, fan-out and delivery
 * ----------------------------------------------------------------------------
 * The one job of this file: every row that lands in `system_notifications`
 * also reaches the member's phone, without any of the ~60 call sites that
 * write that table having to know push exists.
 *
 * How that works
 * --------------
 *   1. push_fanout()  reads `system_notifications` rows newer than a cursor
 *                     kept in push_config, expands each row's audience
 *                     (user / department / role / region) and writes one
 *                     push_queue row per recipient. The unique key on
 *                     (notification_id, user_id) makes it safe to re-run.
 *   2. push_drain()   claims pending queue rows, encrypts one payload per
 *                     device and posts them all in parallel with curl_multi.
 *   3. push_run()     does both under a MySQL named lock, so the cron worker
 *                     and the after-response dispatcher can never collide.
 *
 * Degrading safely
 * ----------------
 * The deploy copies the tree to production BEFORE `php db/migrate.php` runs,
 * so for a minute or two this code exists while its tables do not. Every
 * entry point asks push_ready() first and returns a reason instead of
 * throwing. Nothing in here may ever take a page down.
 * ============================================================================
 */

require_once __DIR__ . '/push_crypto.php';

if (!defined('PUSH_HELPERS_LOADED')) {
    define('PUSH_HELPERS_LOADED', true);

    /** Give up on a queued message after this many attempts. */
    define('PUSH_MAX_ATTEMPTS', 5);

    /** Drop a subscription after this many consecutive hard failures. */
    define('PUSH_MAX_SUB_FAILURES', 8);

    /** How long a push service should hold a message for an offline device. */
    define('PUSH_TTL_SECONDS', 86400);

    /** Minutes to wait before attempt N+1 (index = attempts already made). */
    define('PUSH_BACKOFF_MINUTES', '1,5,15,60,180');

    /* ================================================================== *
     * Schema probing — everything below assumes nothing exists
     * ================================================================== */

    /** Does $table exist in the current schema? Cached per request. */
    function push_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
            );
            $stmt->execute([$table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    /** Which of the optional audience columns `system_notifications` carries. */
    function push_notification_columns(PDO $pdo): array
    {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }

        $cols = [];
        try {
            $stmt = $pdo->query(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'system_notifications'"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $cols[strtolower((string) $name)] = true;
            }
        } catch (Throwable $e) {
            $cols = [];
        }

        return $cols;
    }

    /**
     * Is push usable right now?
     * @return array{ok:bool,reason:string}
     */
    function push_ready(PDO $pdo): array
    {
        if (!push_table_exists($pdo, 'push_subscriptions')) {
            return ['ok' => false, 'reason' => 'FEATURE_NOT_READY'];
        }
        if (push_config_get($pdo, 'enabled', '1') !== '1') {
            return ['ok' => false, 'reason' => 'DISABLED'];
        }
        if (!function_exists('openssl_pkey_derive')) {
            return ['ok' => false, 'reason' => 'NO_OPENSSL_ECDH'];
        }
        if (push_vapid($pdo) === null) {
            return ['ok' => false, 'reason' => 'NO_VAPID_KEYS'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    function push_log(string $message): void
    {
        error_log('[push] ' . $message);
    }

    /* ================================================================== *
     * Config
     * ================================================================== */

    /**
     * The per-request config cache, shared by the getter and the setter.
     *
     * It lives here rather than as a `static` inside push_config_get() so
     * that push_config_set() can write through to it. It could not before,
     * and the consequence was not theoretical: push_fanout() reads
     * `fanout_cursor_id`, and a second fan-out in the same process would
     * read back the stale value the first one cached — for a brand-new
     * install that value is null, which means "first run ever", which means
     * re-parking the cursor at MAX(id) and silently dropping every
     * notification queued in between.
     *
     * @return array<string, string|null>
     */
    function &push_config_cache(): array
    {
        static $cache = [];
        return $cache;
    }

    function push_config_get(PDO $pdo, string $key, ?string $default = null): ?string
    {
        $cache = &push_config_cache();
        if (array_key_exists($key, $cache)) {
            return $cache[$key] ?? $default;
        }

        if (!push_table_exists($pdo, 'push_config')) {
            return $default;
        }

        try {
            $stmt = $pdo->prepare("SELECT config_value FROM push_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $cache[$key] = ($value === false) ? null : (string) $value;
        } catch (Throwable $e) {
            return $default;
        }

        return $cache[$key] ?? $default;
    }

    function push_config_set(PDO $pdo, string $key, ?string $value): bool
    {
        if (!push_table_exists($pdo, 'push_config')) {
            return false;
        }

        try {
            $pdo->prepare(
                "INSERT INTO push_config (config_key, config_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
            )->execute([$key, $value]);
        } catch (Throwable $e) {
            push_log('config write failed for ' . $key . ': ' . $e->getMessage());
            return false;
        }

        $cache = &push_config_cache();
        $cache[$key] = $value;

        return true;
    }

    /**
     * The VAPID identity this server signs with.
     *
     * .env wins, because a key there survives a database restore and stays
     * out of SQL dumps. With nothing in .env a keypair is generated once and
     * kept in push_config so the feature works the moment the migration runs.
     *
     * @return array{public:string,public_raw:string,pem:string,subject:string}|null
     */
    function push_vapid(PDO $pdo): ?array
    {
        static $vapid = false;
        if ($vapid !== false) {
            return $vapid;
        }

        $vapid = null;

        $publicB64  = trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''));
        $privateB64 = trim((string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''));

        if ($publicB64 === '' || $privateB64 === '') {
            if (!push_table_exists($pdo, 'push_config')) {
                return null;
            }
            $publicB64  = (string) push_config_get($pdo, 'vapid_public', '');
            $privateB64 = (string) push_config_get($pdo, 'vapid_private', '');
        }

        try {
            if ($publicB64 === '' || $privateB64 === '') {
                // First run on a database that has the tables but no identity.
                if (!push_table_exists($pdo, 'push_config')) {
                    return null;
                }
                $pair = push_generate_keypair();
                if (!push_config_set($pdo, 'vapid_public', $pair['public'])
                    || !push_config_set($pdo, 'vapid_private', $pair['private'])) {
                    return null;
                }
                push_log('generated a VAPID keypair and stored it in push_config');
                $vapid = [
                    'public'     => $pair['public'],
                    'public_raw' => $pair['public_raw'],
                    'pem'        => $pair['pem'],
                    'subject'    => push_vapid_subject($pdo),
                ];
                return $vapid;
            }

            $publicRaw  = push_b64url_decode($publicB64);
            $privateRaw = push_b64url_decode($privateB64);

            if (strlen($privateRaw) !== 32) {
                push_log('VAPID private key is not 32 bytes — push is off until it is fixed');
                return null;
            }
            if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
                // Recoverable: the point can be rebuilt from the scalar.
                $publicRaw = push_ec_point_from_scalar($privateRaw);
                $publicB64 = push_b64url_encode($publicRaw);
            }

            $vapid = [
                'public'     => $publicB64,
                'public_raw' => $publicRaw,
                'pem'        => push_ec_private_pem_from_raw($privateRaw, $publicRaw),
                'subject'    => push_vapid_subject($pdo),
            ];
        } catch (Throwable $e) {
            push_log('VAPID key material unusable: ' . $e->getMessage());
            $vapid = null;
        }

        return $vapid;
    }

    /** RFC 8292 requires a mailto: or https: contact for the sender. */
    function push_vapid_subject(PDO $pdo): string
    {
        $subject = trim((string) ($_ENV['VAPID_SUBJECT'] ?? ''));
        if ($subject === '') {
            $subject = trim((string) push_config_get($pdo, 'subject', ''));
        }
        if ($subject !== '' && (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://'))) {
            return $subject;
        }
        if ($subject !== '' && str_contains($subject, '@')) {
            return 'mailto:' . $subject;
        }

        return push_site_origin($pdo);
    }

    /**
     * Absolute origin of this site, used for icon URLs in payloads and as the
     * VAPID contact of last resort. Learned from the first web request and
     * remembered, because cron has no HTTP_HOST.
     */
    function push_site_origin(PDO $pdo): string
    {
        $configured = trim((string) ($_ENV['SITE_URL'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $stored = trim((string) push_config_get($pdo, 'site_origin', ''));

        if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
            $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $host   = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
            $origin = ($https ? 'https://' : 'http://') . $host;

            if ($host !== '' && $origin !== $stored) {
                push_config_set($pdo, 'site_origin', $origin);
            }
            return $origin;
        }

        return $stored !== '' ? $stored : 'https://hodlc.lpc.cm';
    }

    /* ================================================================== *
     * Subscriptions
     * ================================================================== */

    /** Name the push service behind an endpoint — purely for the device list. */
    function push_provider_from_endpoint(string $endpoint): string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if ($host === '') {
            return 'unknown';
        }
        if (str_contains($host, 'google') || str_contains($host, 'fcm')) return 'fcm';
        if (str_contains($host, 'mozilla'))                              return 'mozilla';
        if (str_contains($host, 'windows') || str_contains($host, 'microsoft')) return 'wns';
        if (str_contains($host, 'apple'))                                return 'apple';
        return $host;
    }

    /** A short human label for the device list ("iPhone · Safari"). */
    function push_device_label(string $userAgent): string
    {
        $ua = $userAgent;

        $platform = 'Device';
        if (preg_match('/iPhone/i', $ua))            $platform = 'iPhone';
        elseif (preg_match('/iPad/i', $ua))          $platform = 'iPad';
        elseif (preg_match('/Android/i', $ua))       $platform = 'Android';
        elseif (preg_match('/Windows/i', $ua))       $platform = 'Windows';
        elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $platform = 'Mac';
        elseif (preg_match('/Linux/i', $ua))         $platform = 'Linux';

        $browser = '';
        if (preg_match('/Edg\//i', $ua))                 $browser = 'Edge';
        elseif (preg_match('/OPR\/|Opera/i', $ua))       $browser = 'Opera';
        elseif (preg_match('/SamsungBrowser/i', $ua))    $browser = 'Samsung Internet';
        elseif (preg_match('/Firefox/i', $ua))           $browser = 'Firefox';
        elseif (preg_match('/Chrome|CriOS/i', $ua))      $browser = 'Chrome';
        elseif (preg_match('/Safari/i', $ua))            $browser = 'Safari';

        return $browser === '' ? $platform : $platform . ' · ' . $browser;
    }

    /** Rough platform bucket, used by the UI to explain iOS behaviour. */
    function push_platform_from_ua(string $ua): string
    {
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'ios';
        if (preg_match('/Android/i', $ua))          return 'android';
        if (preg_match('/Windows/i', $ua))          return 'windows';
        if (preg_match('/Mac OS X|Macintosh/i', $ua)) return 'mac';
        return 'other';
    }

    /**
     * Store (or refresh) one browser subscription.
     * @return array{status:string,message:string,id?:int}
     */
    function push_save_subscription(
        PDO $pdo,
        int $userId,
        string $endpoint,
        string $p256dh,
        string $authSecret,
        string $userAgent = '',
        string $contentEncoding = 'aes128gcm'
    ): array {
        if (!push_table_exists($pdo, 'push_subscriptions')) {
            return ['status' => 'error', 'message' => 'FEATURE_NOT_READY'];
        }
        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'Not signed in.'];
        }

        $endpoint = trim($endpoint);
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            return ['status' => 'error', 'message' => 'That push endpoint is not a valid https URL.'];
        }
        if (strlen($endpoint) > 2000) {
            return ['status' => 'error', 'message' => 'That push endpoint is too long.'];
        }

        // Validate the key material now rather than discovering it is junk on
        // the first broadcast: a bad p256dh would throw inside the sender.
        $rawKey  = push_b64url_decode($p256dh);
        $rawAuth = push_b64url_decode($authSecret);
        if (strlen($rawKey) !== 65 || $rawKey[0] !== "\x04") {
            return ['status' => 'error', 'message' => 'The subscription key is malformed.'];
        }
        if (strlen($rawAuth) !== 16) {
            return ['status' => 'error', 'message' => 'The subscription auth secret is malformed.'];
        }
        if ($contentEncoding !== 'aes128gcm') {
            return ['status' => 'error', 'message' => 'This browser is too old for encrypted push.'];
        }

        $userAgent = substr(trim($userAgent), 0, 255);

        try {
            $pdo->prepare(
                "INSERT INTO push_subscriptions
                    (user_id, endpoint, endpoint_hash, p256dh, auth_secret, content_encoding,
                     provider, device_label, platform, user_agent, is_active, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    user_id       = VALUES(user_id),
                    p256dh        = VALUES(p256dh),
                    auth_secret   = VALUES(auth_secret),
                    device_label  = VALUES(device_label),
                    platform      = VALUES(platform),
                    user_agent    = VALUES(user_agent),
                    is_active     = 1,
                    failure_count = 0,
                    last_error    = '',
                    revoked_at    = NULL,
                    last_seen_at  = NOW()"
            )->execute([
                $userId,
                $endpoint,
                hash('sha256', $endpoint),
                $p256dh,
                $authSecret,
                $contentEncoding,
                push_provider_from_endpoint($endpoint),
                push_device_label($userAgent),
                push_platform_from_ua($userAgent),
                $userAgent,
            ]);
        } catch (Throwable $e) {
            push_log('subscribe failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not save this device.'];
        }

        return ['status' => 'success', 'message' => 'This device will now receive alerts.'];
    }

    /** Forget one endpoint. Scoped to the owner unless $userId is 0 (system). */
    function push_delete_subscription(PDO $pdo, int $userId, string $endpoint): bool
    {
        if (!push_table_exists($pdo, 'push_subscriptions')) {
            return false;
        }

        try {
            if ($userId > 0) {
                $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?");
                $stmt->execute([hash('sha256', trim($endpoint)), $userId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ?");
                $stmt->execute([hash('sha256', trim($endpoint))]);
            }
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            push_log('unsubscribe failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Active devices for one member. */
    function push_user_subscriptions(PDO $pdo, int $userId): array
    {
        if (!push_table_exists($pdo, 'push_subscriptions') || $userId <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, endpoint, p256dh, auth_secret, failure_count, device_label, platform,
                        provider, created_at, last_success_at
                 FROM push_subscriptions
                 WHERE user_id = ? AND is_active = 1
                 ORDER BY last_seen_at DESC, id DESC
                 LIMIT 20"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            push_log('reading subscriptions failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Mark a dead endpoint (404/410) so nothing tries it again. */
    function push_retire_subscription(PDO $pdo, int $subscriptionId, string $reason): void
    {
        try {
            $pdo->prepare(
                "UPDATE push_subscriptions
                 SET is_active = 0, revoked_at = NOW(), last_error = ?
                 WHERE id = ?"
            )->execute([substr($reason, 0, 255), $subscriptionId]);
        } catch (Throwable $e) {
            push_log('retiring subscription failed: ' . $e->getMessage());
        }
    }

    /* ================================================================== *
     * Preferences
     * ================================================================== */

    /**
     * Preferences for one member. A member with no row is opted in — the real
     * consent gate is the browser permission they granted per device.
     */
    function push_user_prefs(PDO $pdo, int $userId, bool $fresh = false): array
    {
        $defaults = [
            'push_enabled' => 1,
            'quiet_from'   => null,
            'quiet_to'     => null,
            'muted_until'  => null,
        ];

        if (!push_table_exists($pdo, 'push_user_prefs') || $userId <= 0) {
            return $defaults;
        }

        // Cached per request: fanning a broadcast out to a whole region asks
        // for the same member's preferences once per queued message.
        static $cache = [];
        if ($fresh) {
            unset($cache[$userId]);
        } elseif (isset($cache[$userId])) {
            return $cache[$userId];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT push_enabled, quiet_from, quiet_to, muted_until FROM push_user_prefs WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $defaults;
        }

        if (!$row) {
            return $cache[$userId] = $defaults;
        }

        return $cache[$userId] = [
            'push_enabled' => (int) $row['push_enabled'],
            'quiet_from'   => $row['quiet_from'] === null ? null : (int) $row['quiet_from'],
            'quiet_to'     => $row['quiet_to'] === null ? null : (int) $row['quiet_to'],
            'muted_until'  => $row['muted_until'],
        ];
    }

    function push_save_prefs(PDO $pdo, int $userId, array $prefs): bool
    {
        if (!push_table_exists($pdo, 'push_user_prefs') || $userId <= 0) {
            return false;
        }

        $hour = static function ($value): ?int {
            if ($value === null || $value === '' || $value === 'null') return null;
            $hour = (int) $value;
            return ($hour >= 0 && $hour <= 23) ? $hour : null;
        };

        try {
            $pdo->prepare(
                "INSERT INTO push_user_prefs (user_id, push_enabled, quiet_from, quiet_to, muted_until)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    push_enabled = VALUES(push_enabled),
                    quiet_from   = VALUES(quiet_from),
                    quiet_to     = VALUES(quiet_to),
                    muted_until  = VALUES(muted_until)"
            )->execute([
                $userId,
                !empty($prefs['push_enabled']) ? 1 : 0,
                $hour($prefs['quiet_from'] ?? null),
                $hour($prefs['quiet_to'] ?? null),
                $prefs['muted_until'] ?? null,
            ]);
        } catch (Throwable $e) {
            push_log('saving preferences failed: ' . $e->getMessage());
            return false;
        }

        push_user_prefs($pdo, $userId, true);   // drop the now-stale cache entry

        return true;
    }

    /**
     * May we push to this member right now?
     * Urgent messages (security, account status) ignore quiet hours — those
     * are exactly the ones nobody should sleep through.
     */
    function push_user_allows(PDO $pdo, int $userId, string $urgency = 'normal'): bool
    {
        $prefs = push_user_prefs($pdo, $userId);

        if (empty($prefs['push_enabled'])) {
            return false;
        }
        if ($urgency === 'high') {
            return true;
        }
        if (!empty($prefs['muted_until']) && strtotime((string) $prefs['muted_until']) > time()) {
            return false;
        }

        $from = $prefs['quiet_from'];
        $to   = $prefs['quiet_to'];
        if ($from === null || $to === null || $from === $to) {
            return true;
        }

        $hour = (int) date('G');
        $inQuietHours = ($from < $to)
            ? ($hour >= $from && $hour < $to)      // 13 -> 15
            : ($hour >= $from || $hour < $to);     // 22 -> 6, wrapping midnight

        return !$inQuietHours;
    }

    /* ================================================================== *
     * Turning a notification into a push message
     * ================================================================== */

    /** Collapse markup and whitespace, then cut to a length a phone will show. */
    function push_clean_text(?string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    /**
     * Titles that must cut through a Do-Not-Disturb window. Deliberately
     * narrow: account security and anything the system itself calls urgent.
     */
    function push_urgency_for(string $title): string
    {
        return preg_match('/\b(security|urgent|alert|revoked|suspended|emergency)\b/i', $title)
            ? 'high'
            : 'normal';
    }

    /** system_notifications row -> the shape push_queue stores. */
    function push_message_from_notification(array $row): array
    {
        $title = push_clean_text($row['title'] ?? 'HOD Lekki', 90);
        $url   = trim((string) ($row['link_url'] ?? ''));

        // Only same-site paths may become a notification target: a push that
        // opened an arbitrary URL would turn any notification writer into an
        // open redirect with the app's branding on it.
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            $url = '/index.php';
        }

        return [
            'title'   => $title !== '' ? $title : 'HOD Lekki',
            'body'    => push_clean_text($row['message'] ?? '', 240),
            'url'     => substr($url, 0, 255),
            'tag'     => isset($row['id']) ? 'hod-notif-' . (int) $row['id'] : 'hod-notif',
            'urgency' => push_urgency_for((string) ($row['title'] ?? '')),
        ];
    }

    /**
     * Who should receive a notification row (user / department / role / region).
     *
     * A row that names a user_id is addressed to that user, full stop — the
     * group columns on it are context, not a second audience. This matters:
     * api/regions_api.php writes ONE row per member of a region, each
     * carrying both user_id and region_id. Expanding region_id as well would
     * turn a 200-member broadcast into 40,000 queue writes that the unique
     * key then throws away.
     */
    function push_notification_audience(PDO $pdo, array $row): array
    {
        $columns = push_notification_columns($pdo);
        $userIds = [];

        if (!empty($row['user_id'])) {
            return [(int) $row['user_id']];
        }

        try {
            if (isset($columns['department_id']) && !empty($row['department_id'])) {
                $stmt = $pdo->prepare(
                    "SELECT user_id FROM user_departments WHERE department_id = ? AND is_active = 1"
                );
                $stmt->execute([(int) $row['department_id']]);
                $userIds = array_merge($userIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }

            if (isset($columns['role_id']) && !empty($row['role_id'])) {
                // Frozen roles are excluded for the same reason api/auth_api.php
                // excludes them when building $_SESSION['roles']: a revoked
                // account must stop receiving privileged traffic immediately.
                $stmt = $pdo->prepare(
                    "SELECT user_id FROM user_roles WHERE role_id = ? AND COALESCE(is_frozen, 0) = 0"
                );
                $stmt->execute([(int) $row['role_id']]);
                $userIds = array_merge($userIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }

            if (isset($columns['region_id']) && !empty($row['region_id'])) {
                $stmt = $pdo->prepare(
                    "SELECT id FROM users WHERE region_id = ? AND COALESCE(attendance_status, '') != 'Relocated'"
                );
                $stmt->execute([(int) $row['region_id']]);
                $userIds = array_merge($userIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }
        } catch (Throwable $e) {
            push_log('expanding an audience failed: ' . $e->getMessage());
        }

        return array_values(array_unique(array_filter($userIds, static fn($id) => $id > 0)));
    }

    /* ================================================================== *
     * Queue
     * ================================================================== */

    /**
     * Queue one message for one member.
     *
     * @param int|null $notificationId  The system_notifications row this came
     *                                  from. Supplying it is what stops the
     *                                  same notification being sent twice.
     */
    function push_enqueue(PDO $pdo, int $userId, array $message, ?int $notificationId = null, string $source = 'direct'): bool
    {
        if (!push_table_exists($pdo, 'push_queue') || $userId <= 0) {
            return false;
        }

        $urgency = ($message['urgency'] ?? 'normal') === 'high' ? 'high' : 'normal';
        if (!push_user_allows($pdo, $userId, $urgency)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO push_queue
                    (user_id, notification_id, source, title, body, url, tag, urgency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id"
            );
            $stmt->execute([
                $userId,
                $notificationId,
                substr($source, 0, 40),
                substr((string) ($message['title'] ?? 'HOD Lekki'), 0, 190),
                substr((string) ($message['body'] ?? ''), 0, 500),
                substr((string) ($message['url'] ?? '/index.php'), 0, 255),
                substr((string) ($message['tag'] ?? ''), 0, 80),
                $urgency,
            ]);
        } catch (Throwable $e) {
            push_log('enqueue failed: ' . $e->getMessage());
            return false;
        }

        // 1 when a row was inserted, 0 when the unique key on
        // (notification_id, user_id) turned it into a no-op. Returning true
        // either way would make a re-run of the fan-out report work it did
        // not do, which is exactly the number the worker log and the Alerts
        // Centre put in front of somebody.
        return $stmt->rowCount() > 0;
    }

    /**
     * Copy every new `system_notifications` row into push_queue.
     *
     * This is the whole trick: no call site anywhere in the codebase changes,
     * and anything that writes a notification in future is covered the moment
     * it is written.
     *
     * @return array{scanned:int,queued:int,cursor:int}
     */
    function push_fanout(PDO $pdo, int $limit = 400): array
    {
        $result = ['scanned' => 0, 'queued' => 0, 'cursor' => 0];

        if (!push_table_exists($pdo, 'push_queue') || !push_table_exists($pdo, 'system_notifications')) {
            return $result;
        }

        try {
            $maxId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM system_notifications")->fetchColumn();
        } catch (Throwable $e) {
            push_log('fan-out could not read system_notifications: ' . $e->getMessage());
            return $result;
        }

        $cursorRaw = push_config_get($pdo, 'fanout_cursor_id');
        if ($cursorRaw === null) {
            // First ever run. Start from "now" so switching the feature on
            // never replays months of history at the whole congregation.
            push_config_set($pdo, 'fanout_cursor_id', (string) $maxId);
            $result['cursor'] = $maxId;
            return $result;
        }

        $cursor = (int) $cursorRaw;
        if ($cursor >= $maxId) {
            $result['cursor'] = $cursor;
            return $result;
        }

        $columns = push_notification_columns($pdo);
        $select  = ['id', 'user_id', 'title', 'message', 'link_url'];
        foreach (['department_id', 'role_id', 'region_id', 'is_read'] as $optional) {
            if (isset($columns[$optional])) {
                $select[] = $optional;
            }
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT " . implode(', ', $select) . "
                 FROM system_notifications
                 WHERE id > ? AND id <= ?
                 ORDER BY id ASC
                 LIMIT " . max(1, min($limit, 2000))
            );
            $stmt->execute([$cursor, $maxId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            push_log('fan-out query failed: ' . $e->getMessage());
            return $result;
        }

        $highest = $cursor;
        foreach ($rows as $row) {
            $result['scanned']++;
            $highest = max($highest, (int) $row['id']);

            // Already dealt with inside the app before the worker got here.
            if (isset($row['is_read']) && (int) $row['is_read'] === 1) {
                continue;
            }

            $message = push_message_from_notification($row);
            foreach (push_notification_audience($pdo, $row) as $userId) {
                if (push_enqueue($pdo, $userId, $message, (int) $row['id'], 'system_notifications')) {
                    $result['queued']++;
                }
            }
        }

        push_config_set($pdo, 'fanout_cursor_id', (string) $highest);
        $result['cursor'] = $highest;

        return $result;
    }

    /* ================================================================== *
     * Delivery
     * ================================================================== */

    /** The JSON the service worker receives. Short keys keep us well inside
     *  the ~4KB a push service will accept. */
    function push_build_payload(array $job, int $badge): string
    {
        $payload = [
            'title' => (string) $job['title'],
            'body'  => (string) $job['body'],
            'url'   => (string) $job['url'],
            'tag'   => (string) $job['tag'],
            'badge' => $badge,
            'at'    => time(),
        ];
        if (!empty($job['notification_id'])) {
            $payload['nid'] = (int) $job['notification_id'];
        }
        if (($job['urgency'] ?? 'normal') === 'high') {
            $payload['urgent'] = true;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Belt and braces: a pathological title/body pair still has to fit.
        if (strlen($json) > PUSH_MAX_PAYLOAD_BYTES) {
            $payload['body'] = push_clean_text($payload['body'], 120);
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /** Unread notifications for a member — drives the app icon badge. */
    function push_unread_count(PDO $pdo, int $userId): int
    {
        if ($userId <= 0 || !push_table_exists($pdo, 'system_notifications')) {
            return 0;
        }

        $columns = push_notification_columns($pdo);
        $clauses = ['user_id = :uid_direct'];
        $params  = ['uid_direct' => $userId];

        if (isset($columns['department_id'])) {
            $clauses[] = 'department_id IN (SELECT department_id FROM user_departments WHERE user_id = :uid_dept AND is_active = 1)';
            $params['uid_dept'] = $userId;
        }
        if (isset($columns['role_id'])) {
            $clauses[] = 'role_id IN (SELECT role_id FROM user_roles WHERE user_id = :uid_role)';
            $params['uid_role'] = $userId;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM system_notifications
                 WHERE is_read = 0 AND (" . implode(' OR ', $clauses) . ")"
            );
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Post a batch of already-encrypted requests in parallel.
     *
     * A regional broadcast is hundreds of endpoints; done one at a time that
     * is minutes of wall clock. curl_multi turns it into one round trip.
     *
     * @param array $requests  [['url'=>, 'body'=>, 'headers'=>[], 'ref'=>mixed], …]
     * @return array           ref => ['code'=>int, 'error'=>string]
     */
    function push_post_batch(array $requests, int $timeout = 12): array
    {
        $results = [];
        if ($requests === []) {
            return $results;
        }

        $multi   = curl_multi_init();
        $handles = [];

        foreach ($requests as $index => $request) {
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $request['body'],
                CURLOPT_HTTPHEADER     => $request['headers'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$index] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $index => $ch) {
            $body  = curl_multi_getcontent($ch);
            $error = curl_error($ch);
            $results[$requests[$index]['ref']] = [
                'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error' => $error !== '' ? $error : push_clean_text((string) $body, 180),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Send one message to every active device of one member, right now.
     *
     * @return array{status:string,sent:int,failed:int,devices:int,message:string}
     */
    function push_send_to_user(PDO $pdo, int $userId, array $message): array
    {
        $ready = push_ready($pdo);
        if (!$ready['ok']) {
            return ['status' => 'error', 'sent' => 0, 'failed' => 0, 'devices' => 0, 'message' => $ready['reason']];
        }

        $subscriptions = push_user_subscriptions($pdo, $userId);
        if ($subscriptions === []) {
            return ['status' => 'error', 'sent' => 0, 'failed' => 0, 'devices' => 0, 'message' => 'NO_DEVICES'];
        }

        $job = [
            'title'           => substr((string) ($message['title'] ?? 'HOD Lekki'), 0, 190),
            'body'            => substr((string) ($message['body'] ?? ''), 0, 500),
            'url'             => (string) ($message['url'] ?? '/index.php'),
            'tag'             => (string) ($message['tag'] ?? 'hod-direct'),
            'urgency'         => ($message['urgency'] ?? 'normal') === 'high' ? 'high' : 'normal',
            'notification_id' => $message['notification_id'] ?? null,
        ];

        $outcome = push_deliver_job($pdo, $job, $subscriptions, push_unread_count($pdo, $userId));

        return [
            'status'  => $outcome['sent'] > 0 ? 'success' : 'error',
            'sent'    => $outcome['sent'],
            'failed'  => $outcome['failed'],
            'devices' => count($subscriptions),
            'message' => $outcome['sent'] > 0 ? 'Delivered.' : ($outcome['error'] ?: 'No device accepted the message.'),
        ];
    }

    /**
     * Encrypt, post and record the outcome for one message across devices.
     * @return array{sent:int,failed:int,retryable:bool,error:string}
     */
    function push_deliver_job(PDO $pdo, array $job, array $subscriptions, int $badge): array
    {
        $vapid = push_vapid($pdo);
        if ($vapid === null) {
            return ['sent' => 0, 'failed' => 0, 'retryable' => true, 'error' => 'NO_VAPID_KEYS'];
        }

        $payload  = push_build_payload($job, $badge);
        $requests = [];
        $byRef    = [];
        $tokens   = [];   // VAPID Authorization header, keyed by push service

        foreach ($subscriptions as $subscription) {
            $endpoint = (string) $subscription['endpoint'];
            try {
                $body = push_encrypt_payload(
                    $payload,
                    push_b64url_decode((string) $subscription['p256dh']),
                    push_b64url_decode((string) $subscription['auth_secret'])
                );

                // One token per push service, not per device. A regional
                // broadcast is hundreds of endpoints that nearly all share an
                // audience, and each ES256 signature is a real elliptic-curve
                // operation.
                $audience = push_endpoint_audience($endpoint);
                if (!isset($tokens[$audience])) {
                    $tokens[$audience] = push_vapid_authorization(
                        $audience,
                        $vapid['subject'],
                        $vapid['public_raw'],
                        $vapid['pem']
                    );
                }
                $authorization = $tokens[$audience];
            } catch (Throwable $e) {
                // Key material we cannot use will never become usable.
                push_retire_subscription($pdo, (int) $subscription['id'], 'Unusable key material: ' . $e->getMessage());
                continue;
            }

            $ref           = 'sub-' . $subscription['id'];
            $byRef[$ref]   = $subscription;
            $requests[]    = [
                'url'     => $endpoint,
                'body'    => $body,
                'ref'     => $ref,
                'headers' => [
                    'Authorization: ' . $authorization,
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . strlen($body),
                    'TTL: ' . PUSH_TTL_SECONDS,
                    'Urgency: ' . ($job['urgency'] === 'high' ? 'high' : 'normal'),
                ],
            ];
        }

        if ($requests === []) {
            return ['sent' => 0, 'failed' => 0, 'retryable' => false, 'error' => 'NO_USABLE_DEVICES'];
        }

        $sent      = 0;
        $failed    = 0;
        $retryable = false;
        $lastError = '';

        foreach (push_post_batch($requests) as $ref => $result) {
            $subscription = $byRef[$ref];
            $code         = $result['code'];

            if ($code >= 200 && $code < 300) {
                $sent++;
                try {
                    $pdo->prepare(
                        "UPDATE push_subscriptions
                         SET last_success_at = NOW(), failure_count = 0, last_error = ''
                         WHERE id = ?"
                    )->execute([(int) $subscription['id']]);
                } catch (Throwable $e) {
                    // Delivery already happened; bookkeeping is not worth a fatal.
                }
                continue;
            }

            $failed++;
            $lastError = 'HTTP ' . $code . ($result['error'] !== '' ? ' — ' . $result['error'] : '');

            if ($code === 404 || $code === 410) {
                // The browser threw the subscription away (uninstalled, data
                // cleared, permission revoked). It will never come back.
                push_retire_subscription($pdo, (int) $subscription['id'], $lastError);
                continue;
            }

            if ($code === 429 || $code === 0 || $code >= 500) {
                $retryable = true;
            }

            try {
                // Assignment order matters. MySQL and MariaDB evaluate an
                // UPDATE's SET list left to right, and a later expression sees
                // the value an earlier one just wrote — so the two CASEs have
                // to come BEFORE the increment, or `failure_count + 1` would
                // already be the incremented count and a device would be
                // dropped a strike early.
                $pdo->prepare(
                    "UPDATE push_subscriptions
                     SET is_active     = CASE WHEN failure_count + 1 >= ? THEN 0 ELSE is_active END,
                         revoked_at    = CASE WHEN failure_count + 1 >= ? THEN NOW() ELSE revoked_at END,
                         failure_count = failure_count + 1,
                         last_error    = ?
                     WHERE id = ?"
                )->execute([
                    PUSH_MAX_SUB_FAILURES,
                    PUSH_MAX_SUB_FAILURES,
                    substr($lastError, 0, 255),
                    (int) $subscription['id'],
                ]);
            } catch (Throwable $e) {
                push_log('recording a delivery failure failed: ' . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'retryable' => $retryable, 'error' => $lastError];
    }

    /**
     * Send queued messages.
     *
     * @param float $budgetSeconds  Stop claiming new work after this long, so
     *                              a web request never outstays its welcome.
     * @return array{claimed:int,sent:int,failed:int,retried:int}
     */
    function push_drain(PDO $pdo, int $limit = 60, float $budgetSeconds = 8.0): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0];

        if (!push_table_exists($pdo, 'push_queue')) {
            return $stats;
        }

        $started = microtime(true);
        $backoff = array_map('intval', explode(',', PUSH_BACKOFF_MINUTES));

        try {
            $stmt = $pdo->prepare(
                "SELECT id, user_id, notification_id, title, body, url, tag, urgency, attempts
                 FROM push_queue
                 WHERE status = 'pending' AND available_at <= NOW()
                 ORDER BY (urgency = 'high') DESC, id ASC
                 LIMIT " . max(1, min($limit, 500))
            );
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            push_log('reading the queue failed: ' . $e->getMessage());
            return $stats;
        }

        // Group by member: one member with three devices is one encryption
        // pass and one parallel post, not three serial ones.
        $badgeCache = [];

        foreach ($jobs as $job) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }

            $jobId  = (int) $job['id'];
            $userId = (int) $job['user_id'];

            // Claim it. The WHERE clause is the claim: a second worker that
            // read the same row loses the race and skips it.
            try {
                $claim = $pdo->prepare(
                    "UPDATE push_queue
                     SET status = 'sending', attempts = attempts + 1
                     WHERE id = ? AND status = 'pending'"
                );
                $claim->execute([$jobId]);
                if ($claim->rowCount() === 0) {
                    continue;
                }
            } catch (Throwable $e) {
                push_log('claiming a queue row failed: ' . $e->getMessage());
                continue;
            }

            $stats['claimed']++;
            $attempts = (int) $job['attempts'] + 1;

            $subscriptions = push_user_subscriptions($pdo, $userId);
            if ($subscriptions === []) {
                push_finish_job($pdo, $jobId, 'skipped', 0, 'No registered device.');
                continue;
            }

            if (!isset($badgeCache[$userId])) {
                $badgeCache[$userId] = push_unread_count($pdo, $userId);
            }

            $outcome = push_deliver_job($pdo, $job, $subscriptions, $badgeCache[$userId]);

            if ($outcome['sent'] > 0) {
                $stats['sent'] += $outcome['sent'];
                push_finish_job($pdo, $jobId, 'sent', $outcome['sent'], '');
                continue;
            }

            if ($outcome['retryable'] && $attempts < PUSH_MAX_ATTEMPTS) {
                $minutes = $backoff[min($attempts - 1, count($backoff) - 1)] ?? 60;
                try {
                    $pdo->prepare(
                        "UPDATE push_queue
                         SET status = 'pending',
                             available_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                             last_error = ?
                         WHERE id = ?"
                    )->execute([$minutes, substr($outcome['error'], 0, 255), $jobId]);
                    $stats['retried']++;
                } catch (Throwable $e) {
                    push_log('rescheduling a queue row failed: ' . $e->getMessage());
                }
                continue;
            }

            $stats['failed']++;
            push_finish_job($pdo, $jobId, 'failed', 0, $outcome['error']);
        }

        return $stats;
    }

    /**
     * Put back any row a previous run claimed and never finished.
     *
     * push_drain() claims a row by moving it to 'sending'. If that process is
     * killed between the claim and the result — a cPanel memory limit, a
     * deploy mid-request, the request timing out — the row would sit there
     * for ever and that member would simply never be told. The attempt was
     * already counted, so a recovered row cannot loop.
     */
    function push_recover_stuck(PDO $pdo, int $olderThanMinutes = 10): int
    {
        if (!push_table_exists($pdo, 'push_queue')) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE push_queue
                 SET status = 'pending', last_error = 'Recovered from an interrupted run.'
                 WHERE status = 'sending'
                   AND attempts < ?
                   AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 LIMIT 500"
            );
            $stmt->execute([PUSH_MAX_ATTEMPTS, max(1, $olderThanMinutes)]);

            // One that has used up its attempts is not worth another round.
            $pdo->prepare(
                "UPDATE push_queue
                 SET status = 'failed', last_error = 'Abandoned after an interrupted run.', sent_at = NOW()
                 WHERE status = 'sending'
                   AND attempts >= ?
                   AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 LIMIT 500"
            )->execute([PUSH_MAX_ATTEMPTS, max(1, $olderThanMinutes)]);

            return $stmt->rowCount();
        } catch (Throwable $e) {
            push_log('recovering interrupted rows failed: ' . $e->getMessage());
            return 0;
        }
    }

    function push_finish_job(PDO $pdo, int $jobId, string $status, int $delivered, string $error): void
    {
        try {
            $pdo->prepare(
                "UPDATE push_queue
                 SET status = ?, delivered_count = ?, last_error = ?, sent_at = NOW()
                 WHERE id = ?"
            )->execute([$status, $delivered, substr($error, 0, 255), $jobId]);
        } catch (Throwable $e) {
            push_log('closing a queue row failed: ' . $e->getMessage());
        }
    }

    /** Keep the queue from growing without limit. */
    function push_prune(PDO $pdo, int $keepDays = 14): int
    {
        if (!push_table_exists($pdo, 'push_queue')) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare(
                "DELETE FROM push_queue
                 WHERE status <> 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                 LIMIT 5000"
            );
            $stmt->execute([max(1, $keepDays)]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            push_log('pruning the queue failed: ' . $e->getMessage());
            return 0;
        }
    }

    /* ================================================================== *
     * The run loop
     * ================================================================== */

    /**
     * Fan out and deliver, under a MySQL named lock so the cron worker and
     * the after-response dispatcher never duplicate each other's work.
     *
     * @return array{locked:bool,scanned:int,queued:int,sent:int,failed:int,retried:int,claimed:int}
     */
    function push_run(PDO $pdo, int $limit = 60, float $budgetSeconds = 8.0, bool $prune = false): array
    {
        $empty = ['locked' => true, 'scanned' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0, 'claimed' => 0];

        $ready = push_ready($pdo);
        if (!$ready['ok']) {
            return $empty + ['reason' => $ready['reason']];
        }

        try {
            $lock = $pdo->query("SELECT GET_LOCK('hod_push_worker', 0)")->fetchColumn();
            if ((int) $lock !== 1) {
                return $empty;
            }
        } catch (Throwable $e) {
            push_log('could not take the worker lock: ' . $e->getMessage());
            return $empty;
        }

        try {
            $recovered = push_recover_stuck($pdo);
            if ($recovered > 0) {
                push_log("recovered {$recovered} queue row(s) from an interrupted run");
            }

            $fanout = push_fanout($pdo);
            $drain  = push_drain($pdo, $limit, $budgetSeconds);
            if ($prune) {
                push_prune($pdo);
            }

            return [
                'locked'  => false,
                'scanned' => $fanout['scanned'],
                'queued'  => $fanout['queued'],
                'sent'    => $drain['sent'],
                'failed'  => $drain['failed'],
                'retried' => $drain['retried'],
                'claimed' => $drain['claimed'],
            ];
        } catch (Throwable $e) {
            push_log('worker run failed: ' . $e->getMessage());
            return $empty;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK('hod_push_worker')");
            } catch (Throwable $e) {
                // The lock dies with the connection anyway.
            }
        }
    }

    /** Is there anything waiting? A cheap check for the inline dispatcher. */
    function push_has_work(PDO $pdo): bool
    {
        if (!push_table_exists($pdo, 'push_queue')) {
            return false;
        }

        try {
            $pending = (int) $pdo->query(
                "SELECT EXISTS(SELECT 1 FROM push_queue WHERE status = 'pending' AND available_at <= NOW())"
            )->fetchColumn();
            if ($pending === 1) {
                return true;
            }

            $cursor = push_config_get($pdo, 'fanout_cursor_id');
            if ($cursor === null) {
                return true;
            }

            $stmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM system_notifications WHERE id > ?)");
            $stmt->execute([(int) $cursor]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}
