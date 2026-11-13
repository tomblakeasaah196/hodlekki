<?php
// /tests/campaigns/lib.php
//
// Shared pieces for tests/campaigns/run.php: assertions, an in-memory SQLite
// database built from the REAL migration (a hand-written test schema that has
// drifted from production proves nothing), stand-ins for the platform tables
// the module reads, a pinned Lagos clock and a fake mail transport.

const CT_MIGRATIONS = ['20261127090000_campaigns_core.sql', '20261129090000_campaigns_identity.sql', '20261130090000_campaigns_throttle.sql',
    '20261207090000_campaigns_people.sql', '20261214090000_campaigns_signin_names.sql'];

$GLOBALS['ct_passed'] = 0;
$GLOBALS['ct_failed'] = 0;

function ct_ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['ct_passed']++;
        echo "  ok   {$label}\n";
    } else {
        $GLOBALS['ct_failed']++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function ct_eq(string $label, $expected, $actual): void
{
    ct_ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function ct_section(string $name): void
{
    echo "\n{$name}\n";
}

/** The module's migration, translated to SQLite. @return list<string> */
function ct_schema_sql(): array
{
    $sql = '';
    foreach (CT_MIGRATIONS as $file) {
        $sql .= (string) file_get_contents(__DIR__ . '/../../db/migrations/' . $file) . PHP_EOL;
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER)) {
        throw new RuntimeException('No CREATE TABLE statements found in the campaigns migrations');
    }
    $out = [];
    foreach ($tables as [, $name, $body]) {
        $cols = [];
        $indexes = [];
        $autoId = false;
        foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($autoId && preg_match('/^PRIMARY KEY \((\w+)\)$/', $line)) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY \w+ \((.+)\)$/', $line, $m)) {
                $cols[] = 'UNIQUE (' . $m[1] . ')';
                continue;
            }
            if (preg_match('/^KEY (\w+) \((.+)\)$/', $line, $m)) {
                $indexes[] = "CREATE INDEX {$m[1]} ON {$name} ({$m[2]})";
                continue;
            }
            if (str_starts_with($line, 'PRIMARY KEY')) {
                $cols[] = $line;
                continue;
            }
            if (str_contains($line, 'AUTO_INCREMENT')) {
                $cols[] = strtok($line, ' ') . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $line = preg_replace('/\b(TINYINT|SMALLINT|INT|BIGINT)(\(\d+\))?( UNSIGNED)?\b/', 'INTEGER', $line);
            $line = preg_replace('/\b(VARCHAR|CHAR)\(\d+\)/', 'TEXT', $line);
            $line = preg_replace('/\b(LONGTEXT|MEDIUMTEXT|DATETIME|DATE)\b/', 'TEXT', $line);
            $cols[] = $line;
        }
        $out[] = "CREATE TABLE {$name} (\n  " . implode(",\n  ", $cols) . "\n)";
        foreach ($indexes as $ix) {
            $out[] = $ix;
        }
    }
    return $out;
}

/** Stand-ins for the platform tables the module reads (and the SMS Studio tables it writes). */
function ct_platform_sql(): array
{
    return [
        "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT UNIQUE, phone TEXT,
            physical_address TEXT, spiritual_status TEXT, attendance_status TEXT, picture_path TEXT,
            region_id INTEGER, created_at TEXT, awol_exempt INTEGER NOT NULL DEFAULT 0,
            password_hash TEXT, account_status TEXT DEFAULT 'active', real_email TEXT,
            failed_login_count INTEGER NOT NULL DEFAULT 0, locked_until TEXT)",
        'CREATE TABLE regions (id INTEGER PRIMARY KEY, name TEXT, pastor_id INTEGER)',
        'CREATE TABLE events (id INTEGER PRIMARY KEY, title TEXT, event_category TEXT, event_date TEXT)',
        'CREATE TABLE checkins (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, checkin_date TEXT)',
        'CREATE TABLE attendance (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, status TEXT, attendance_date TEXT, check_in_time TEXT)',
        'CREATE TABLE embrace_followups (id INTEGER PRIMARY KEY, visitor_id INTEGER, status TEXT)',
        "CREATE TABLE user_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            from_status TEXT NOT NULL DEFAULT '', to_status TEXT NOT NULL DEFAULT '', source TEXT NOT NULL,
            changed_by INTEGER NULL, changed_at TEXT NOT NULL)",
        'CREATE TABLE charis_awol_config (id INTEGER PRIMARY KEY, service_types TEXT, spiritual_statuses TEXT,
            missed_threshold INTEGER, consecutive_threshold INTEGER, period_weeks INTEGER, archive_weeks INTEGER,
            service_rules TEXT, updated_by INTEGER, updated_at TEXT)',
        'CREATE TABLE charis_welfare_assignments (id INTEGER PRIMARY KEY, target_user_id INTEGER, worker_id INTEGER,
            status TEXT, followup_id INTEGER, created_at TEXT, resolved_at TEXT)',
        'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE user_departments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, department_id INTEGER,
            is_active INTEGER DEFAULT 1, role_in_dept TEXT)',
        'CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0)',
        'CREATE TABLE system_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, message TEXT, link_url TEXT)',
        "CREATE TABLE sms_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, audience TEXT, event_id INTEGER,
            filters_json TEXT, body_template TEXT NOT NULL, total INTEGER NOT NULL DEFAULT 0, sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0, status TEXT NOT NULL DEFAULT 'queued', created_by INTEGER, created_at TEXT)",
        "CREATE TABLE sms_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, recipient_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued', sent_status TEXT, error_message TEXT, locked_at TEXT, log_id INTEGER, created_at TEXT)",
        'CREATE TABLE sms_log (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, recipient_phone TEXT, body TEXT, status TEXT)',
        'CREATE TABLE sms_suppression (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT UNIQUE, released_at TEXT)',
    ];
}

function ct_db(bool $withModule = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (ct_platform_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    if ($withModule) {
        foreach (ct_schema_sql() as $stmt) {
            $pdo->exec($stmt);
        }
    }
    $pdo->exec("INSERT INTO charis_awol_config (id, service_types, spiritual_statuses, missed_threshold, consecutive_threshold,
                    period_weeks, archive_weeks, service_rules)
                VALUES (1, '[\"Sunday_Service\",\"Midweek_Service\"]', '[\"Member\",\"Worker\",\"Pastor\"]', 3, 3, 5, 12,
                    '{\"Sunday_Service\":{\"counts_absence\":true,\"resets_clock\":true},\"Midweek_Service\":{\"counts_absence\":false,\"resets_clock\":true}}')");
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor')");
    $pdo->exec("INSERT INTO regions (id, name, pastor_id) VALUES (1, 'Orchid', 902), (2, 'Lily', NULL)");
    $pdo->exec("INSERT INTO departments (id, name) VALUES (10, 'Charis'), (20, 'Media')");
    // Staff: 900 Resident Pastor, 901 Charis HOD, 902 Orchid region pastor, 903 assimilation manager (Director anywhere).
    foreach ([[900, 'Rita'], [901, 'Chidi'], [902, 'Paul'], [903, 'Dami']] as [$id, $name]) {
        $pdo->prepare("INSERT INTO users (id, first_name, last_name, spiritual_status, created_at) VALUES (?, ?, 'Staff', 'Worker', '2020-01-01 00:00:00')")
            ->execute([$id, $name]);
    }
    $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (900, 2)");
    $pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (901, 10, 1, 'HOD'), (903, 20, 1, 'Director')");
    // Team alerts ask the permission engine who leads Charis and who the pastors are.
    require_once __DIR__ . '/../permissions/install.php';
    perm_test_install($pdo);
    return $pdo;
}

function ct_user(PDO $pdo, int $id, string $first, string $status, array $extra = []): void
{
    $row = $extra + [
        'last_name' => 'Test', 'email' => null, 'phone' => null, 'attendance_status' => 'Active',
        'region_id' => null, 'created_at' => '2026-01-01 10:00:00', 'awol_exempt' => 0,
    ];
    $pdo->prepare('INSERT INTO users (id, first_name, last_name, email, phone, spiritual_status, attendance_status, region_id, created_at, awol_exempt)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $first, $row['last_name'], $row['email'], $row['phone'], $status, $row['attendance_status'],
                   $row['region_id'], $row['created_at'], $row['awol_exempt']]);
}

/** A held service on $date (someone must attend for it to count as held). */
function ct_service(PDO $pdo, string $date, string $category = 'Sunday_Service'): int
{
    $time = $category === 'Sunday_Service' ? ' 09:30:00' : ' 18:30:00';
    $pdo->prepare('INSERT INTO events (title, event_category, event_date) VALUES (?, ?, ?)')
        ->execute([$category, $category, $date . $time]);
    $id = (int) $pdo->lastInsertId();
    // The staff attend every service: so every service "held", and the staff
    // (Workers, so inside Charis's audience) are never on an absence streak.
    foreach ([900, 901, 902, 903] as $staff) {
        ct_present($pdo, $staff, $date, $id);
    }
    return $id;
}

function ct_present(PDO $pdo, int $userId, string $date, int $eventId): void
{
    $pdo->prepare('INSERT INTO checkins (event_id, user_id, checkin_date) VALUES (?, ?, ?)')->execute([$eventId, $userId, $date]);
}

function ct_count(PDO $pdo, string $table, string $where = '1=1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function ct_at(string $when): DateTimeImmutable
{
    $at = campaigns_dt($when);
    campaigns_set_clock($at);
    return $at;
}

/** A fake SMTP transport. $script: results to return in order (default ok). */
function ct_transport(array $script = []): array
{
    $sent = new ArrayObject();
    $send = static function (array $m) use ($sent, &$script): array {
        $sent[] = $m;
        return $script !== [] ? array_shift($script) : ['ok' => true, 'error' => null];
    };
    return ['send' => $send, 'close' => static function (): void {}, 'sent' => $sent];
}

/** Give a person a confirmed personal email (and optionally church-news consent). */
function ct_confirm(PDO $pdo, int $userId, string $email, bool $news = true): void
{
    $r = email_capture_start($pdo, $userId, $email, 'profile', null, ['send' => false]);
    if (empty($r['ok']) || empty($r['token'])) {
        throw new RuntimeException('ct_confirm: capture failed for ' . $email . ': ' . json_encode($r));
    }
    $c = email_capture_confirm($pdo, $r['token']);
    if (empty($c['ok'])) {
        throw new RuntimeException('ct_confirm: confirm failed for ' . $email . ': ' . json_encode($c));
    }
    if ($news) {
        email_consent_record($pdo, $userId, 'granted', 'profile', ['email' => $email, 'text' => EMAIL_CONSENT_NEWS_TEXT]);
    }
}

/** A short valid document. */
function ct_doc(array $blocks = [], string $subject = 'Hello {{first_name}}'): array
{
    return ['v' => 1, 'subject' => $subject, 'preheader' => 'A note for you', 'blocks' => $blocks ?: [
        ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Hi {{first_name}}'],
        email_doc_paragraphs('Hello there.'),
        ['type' => 'footer'],
    ]];
}
