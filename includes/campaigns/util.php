<?php
// /includes/campaigns/util.php
//
// Small shared pieces: the Lagos clock (overridable in tests), tokens, the
// table checks every entry point asks first, a named lock, and email_settings.

const CAMPAIGNS_TZ = 'Africa/Lagos';

const CAMPAIGNS_TABLES = [
    'email_addresses', 'email_consent_texts', 'email_consents', 'email_suppressions',
    'email_outbox', 'email_events', 'email_rate_buckets', 'email_settings',
    'email_templates', 'email_campaigns', 'automation_rules', 'automation_runs',
    'automation_sends', 'automation_person_day',
];

const CAMPAIGNS_CHURCH_NAME = 'Household of David Lekki';

// email_settings keys and their defaults. Values are strings in the table.
const EMAIL_SETTING_DEFAULTS = [
    'hourly_cap'     => '100',   // the host's cap for the domain until the owner confirms it
    'safety'         => '0.9',   // never plan to use more than this share of the cap
    'bulk_share'     => '0.6',   // automations + campaigns together, of the usable budget
    'per_minute'     => '20',    // messages one worker run may send
    'window_start'   => '07:00', // church news is only released inside this window (Lagos)
    'window_end'     => '20:00',
    'track_opens'    => '0',     // campaigns may switch it on per campaign
    'postal_line'    => 'Household of David Lekki Centre, Lekki, Lagos, Nigeria',
    'paused'         => '0',     // kill switch for the worker
    'learned_cap'    => '',      // read from the host's own refusal, if it ever refuses
];

/** Now, in Lagos. Tests pin it with campaigns_set_clock(). */
function campaigns_now(): DateTimeImmutable
{
    $pinned = campaigns_set_clock();
    return $pinned ?? new DateTimeImmutable('now', new DateTimeZone(CAMPAIGNS_TZ));
}

/**
 * Pin (or with no argument, read) the clock. Pass false to unpin.
 *
 * @param DateTimeImmutable|false|null $at
 */
function campaigns_set_clock($at = null): ?DateTimeImmutable
{
    static $pinned = null;
    if ($at === false) {
        $pinned = null;
    } elseif ($at instanceof DateTimeImmutable) {
        $pinned = $at->setTimezone(new DateTimeZone(CAMPAIGNS_TZ));
    }
    return $pinned;
}

function campaigns_dt(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone(CAMPAIGNS_TZ));
}

function campaigns_sql_dt(DateTimeImmutable $at): string
{
    return $at->setTimezone(new DateTimeZone(CAMPAIGNS_TZ))->format('Y-m-d H:i:s');
}

/** URL-safe random token: 16 bytes = 22 characters. */
function campaigns_token(int $bytes = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function campaigns_cut(?string $s, int $len): string
{
    $s = (string) $s;
    return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') : $s;
}

function campaigns_json($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** A unique-key collision, on MySQL/MariaDB (1062) and SQLite (SQLSTATE 23000). */
function campaigns_is_duplicate_key(PDOException $e): bool
{
    return $e->getCode() === '23000' || ((int) ($e->errorInfo[1] ?? 0)) === 1062;
}

function campaigns_driver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * Per-connection memo (a WeakMap, so a new connection never inherits an old
 * one's answers even when PHP reuses the object id).
 */
function campaigns_memo(PDO $pdo): ArrayObject
{
    static $map = null;
    $map ??= new WeakMap();
    if (!isset($map[$pdo])) {
        $map[$pdo] = new ArrayObject();
    }
    return $map[$pdo];
}

/** Does $table exist? Cached per connection and table. */
function campaigns_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        return false;
    }
    $memo = campaigns_memo($pdo);
    $key = 'table:' . $table;
    if (!isset($memo[$key])) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
            $memo[$key] = true;
        } catch (Throwable $e) {
            $memo[$key] = false;
        }
    }
    return $memo[$key];
}

/** Every table of the module exists (the migration has run). */
function campaigns_ready(PDO $pdo): bool
{
    foreach (CAMPAIGNS_TABLES as $table) {
        if (!campaigns_table_exists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

/**
 * A named lock held by this connection (MySQL/MariaDB GET_LOCK). On SQLite
 * (the tests: one process) it always succeeds.
 */
function campaigns_lock(PDO $pdo, string $name, int $waitSeconds = 0): bool
{
    if (campaigns_driver($pdo) !== 'mysql') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$name, max(0, $waitSeconds)]);
    return (int) $stmt->fetchColumn() === 1;
}

function campaigns_unlock(PDO $pdo, string $name): void
{
    if (campaigns_driver($pdo) !== 'mysql') {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable $e) {
        error_log('[email] could not release lock ' . $name . ': ' . $e->getMessage());
    }
}

/** A setting from email_settings, else its default. Cached per request. */
function email_setting(PDO $pdo, string $key): string
{
    $all = email_settings_all($pdo);
    return $all[$key] ?? (EMAIL_SETTING_DEFAULTS[$key] ?? '');
}

/** @return array<string,string> */
function email_settings_all(PDO $pdo, bool $refresh = false): array
{
    $memo = campaigns_memo($pdo);
    if ($refresh || !isset($memo['settings'])) {
        $values = EMAIL_SETTING_DEFAULTS;
        if (campaigns_table_exists($pdo, 'email_settings')) {
            foreach ($pdo->query('SELECT skey, svalue FROM email_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values[(string) $row['skey']] = (string) ($row['svalue'] ?? '');
            }
        }
        $memo['settings'] = $values;
    }
    return $memo['settings'];
}

/** Write a setting (update, else insert). Unknown keys are refused. */
function email_setting_set(PDO $pdo, string $key, string $value, ?int $byUserId = null): void
{
    if (!array_key_exists($key, EMAIL_SETTING_DEFAULTS)) {
        throw new InvalidArgumentException('Unknown email setting: ' . $key);
    }
    $now = campaigns_sql_dt(campaigns_now());
    $exists = $pdo->prepare('SELECT 1 FROM email_settings WHERE skey = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE email_settings SET svalue = ?, updated_by = ?, updated_at = ? WHERE skey = ?')
            ->execute([$value, $byUserId, $now, $key]);
    } else {
        try {
            $pdo->prepare('INSERT INTO email_settings (skey, svalue, updated_by, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$key, $value, $byUserId, $now]);
        } catch (PDOException $e) {
            if (!campaigns_is_duplicate_key($e)) {
                throw $e;
            }
            $pdo->prepare('UPDATE email_settings SET svalue = ?, updated_by = ?, updated_at = ? WHERE skey = ?')
                ->execute([$value, $byUserId, $now, $key]);
        }
    }
    email_settings_all($pdo, true);
}

/** "HH:MM" → minutes after midnight, or null. */
function campaigns_minutes(string $hhmm): ?int
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/** A person's display names, with the friendly fallback the emails use. */
function campaigns_person_names(array $person): array
{
    $first = trim((string) ($person['first_name'] ?? ''));
    $last  = trim((string) ($person['last_name'] ?? ''));
    return [
        'first_name' => $first !== '' ? $first : 'friend',
        'last_name'  => $last,
        'full_name'  => trim($first . ' ' . $last) !== '' ? trim($first . ' ' . $last) : 'friend',
    ];
}
