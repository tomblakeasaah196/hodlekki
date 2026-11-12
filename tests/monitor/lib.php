<?php
// /tests/monitor/lib.php
//
// Shared helpers for the system monitor's tests: an assertion counter, a
// throwaway data folder, and SQLite databases built from the monitor's REAL
// migration (db/migrations/20261125090000_monitor_core.sql), so the tests run
// against the same columns, keys and defaults production gets.
//
// No MySQL, no network, no email: hod_send_mail() is replaced by a recorder
// before includes/mailer.php can define the real one.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const MONITOR_MIGRATION = '20261125090000_monitor_core.sql';

date_default_timezone_set('Africa/Lagos');

// A data folder of our own, and a fixed key, before anything reads them.
$GLOBALS['mt_dir'] = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/hod_monitor_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($GLOBALS['mt_dir'], 0700, true);
$_ENV['MONITOR_DATA_DIR'] = $GLOBALS['mt_dir'];
$_ENV['MONITOR_SECRET'] = 'test-secret-for-the-monitor-suite-0123456789';
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['DB_PASS'] = 'Pw-for-the-test-9431';
$_ENV['MAIL_PASSWORD'] = 'mailbox-secret-77';
$_ENV['MONITOR_DEPLOY_LOG'] = $GLOBALS['mt_dir'] . '/deploy.log';
$_ENV['MONITOR_BACKUP_DIR'] = $GLOBALS['mt_dir'] . '/backups';
$_ENV['MONITOR_LIVE_DIR'] = $GLOBALS['mt_dir'] . '/live';
register_shutdown_function(static function (): void {
    $rm = static function (string $d) use (&$rm): void {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $rm($f) : @unlink($f);
        }
        @rmdir($d);
    };
    $rm($GLOBALS['mt_dir']);
});

// Every email the code tries to send lands here instead.
$GLOBALS['mt_mail'] = [];
$GLOBALS['mt_mail_fail'] = false;
function hod_send_mail($to, string $subject, string $html, ?string $text = null, array $options = []): array
{
    if ($GLOBALS['mt_mail_fail']) {
        return ['ok' => false, 'error' => 'SMTP connect() failed.'];
    }
    $GLOBALS['mt_mail'][] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => (string) $text];

    return ['ok' => true, 'error' => null];
}

require_once __DIR__ . '/../../includes/monitor/bootstrap.php';

$GLOBALS['mt_passed'] = 0;
$GLOBALS['mt_failed'] = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['mt_passed']++;
        return;
    }
    $GLOBALS['mt_failed']++;
    echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual,
        'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function has(string $label, string $haystack, string $needle): void
{
    ok($label, str_contains($haystack, $needle), 'missing ' . json_encode($needle) . ' in ' . json_encode(mb_substr($haystack, 0, 300)));
}

function lacks(string $label, string $haystack, string $needle): void
{
    ok($label, !str_contains($haystack, $needle), 'found ' . json_encode($needle));
}

/** The real migration, translated for SQLite. */
function monitor_test_schema_sql(): array
{
    $sql = (string) file_get_contents(__DIR__ . '/../../db/migrations/' . MONITOR_MIGRATION);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER)) {
        throw new RuntimeException('No CREATE TABLE statements in ' . MONITOR_MIGRATION);
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
            if (preg_match('/^PRIMARY KEY \((\w+)\)$/', $line) && $autoId) {
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

/** The platform tables the monitor reads but does not own (stand-ins with the columns it touches). */
function monitor_test_platform_sql(): array
{
    return [
        "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, account_status TEXT DEFAULT 'active')",
        'CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT NOT NULL, is_break_glass INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0)',
        'CREATE TABLE system_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, message TEXT, link_url TEXT)',
    ];
}

/**
 * A fresh database with two Super_Admins (one frozen) and a member: in-memory
 * SQLite by default, a SQLite file when $file is given (the capture test's
 * child processes), or — when MONITOR_TEST_MYSQL_DSN is set — a real MySQL or
 * MariaDB database rebuilt from the migration exactly as db/migrate.php runs it
 * (one PDO::exec() per file), applied twice to prove it is idempotent. CI runs
 * the suite both ways.
 */
function monitor_test_db(?string $file = null): PDO
{
    $dsn = (string) getenv('MONITOR_TEST_MYSQL_DSN');
    if ($dsn !== '' && $file === null) {
        return monitor_test_mysql($dsn);
    }
    if ($file !== null && is_file($file)) {
        unlink($file);
    }
    $pdo = new PDO('sqlite:' . ($file ?? ':memory:'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (array_merge(monitor_test_schema_sql(), monitor_test_platform_sql()) as $stmt) {
        $pdo->exec($stmt);
    }
    $pdo->exec("INSERT INTO roles (id, role_name, is_break_glass) VALUES (1, 'Super_Admin', 1), (2, 'Resident_Pastor', 0), (4, 'Church_Member', 0)");
    $pdo->exec("INSERT INTO users (id, first_name) VALUES (1, 'Admin'), (2, 'Frozen'), (3, 'Member')");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES (1, 1, 0), (2, 1, 1), (3, 4, 0)');

    return $pdo;
}

function monitor_test_mysql(string $dsn): PDO
{
    $pdo = new PDO($dsn, getenv('MONITOR_TEST_MYSQL_USER') ?: 'root', (string) getenv('MONITOR_TEST_MYSQL_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+01:00'");
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (str_starts_with((string) $t, 'monitor_') || in_array($t, ['users', 'roles', 'user_roles', 'system_notifications'], true)) {
            $pdo->exec('DROP TABLE `' . $t . '`');
        }
    }
    $sql = (string) file_get_contents(__DIR__ . '/../../db/migrations/' . MONITOR_MIGRATION);
    $pdo->exec($sql);
    $pdo->exec($sql);   // a second run must change nothing (forward-only, idempotent)
    $pdo->exec("CREATE TABLE users (id INT PRIMARY KEY, first_name VARCHAR(80), account_status VARCHAR(12) DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE roles (id INT PRIMARY KEY, role_name VARCHAR(40) NOT NULL, is_break_glass TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE user_roles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, role_id INT, is_frozen TINYINT DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(150), message TEXT, link_url VARCHAR(255)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO roles (id, role_name, is_break_glass) VALUES (1, 'Super_Admin', 1), (2, 'Resident_Pastor', 0), (4, 'Church_Member', 0)");
    $pdo->exec("INSERT INTO users (id, first_name) VALUES (1, 'Admin'), (2, 'Frozen'), (3, 'Member')");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES (1, 1, 0), (2, 1, 1), (3, 4, 0)');

    return $pdo;
}

/** Make the database refuse a problem with this title, with this message, on either engine. */
function monitor_test_refuse(PDO $db, string $name, string $title, string $message): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec("CREATE TRIGGER {$name} BEFORE INSERT ON monitor_error_groups WHEN NEW.title = " . $db->quote($title)
            . ' BEGIN SELECT RAISE(ABORT, ' . $db->quote($message) . '); END');
        return;
    }
    $db->exec("CREATE TRIGGER {$name} BEFORE INSERT ON monitor_error_groups FOR EACH ROW BEGIN IF NEW.title = " . $db->quote($title)
        . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " . $db->quote($message) . '; END IF; END');
}

/** The columns of a table, on either engine. */
function monitor_test_columns(PDO $db, string $table): array
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
    }

    return array_column($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Field');
}

/** A raw event as capture.php would collect it. */
function monitor_test_raw(array $over = []): array
{
    return array_replace_recursive([
        'kind' => 'php', 'level' => 'warning', 'message' => 'Undefined array key "phone"',
        'file' => dirname(__DIR__, 2) . '/modules/events/index.php', 'line' => 120,
        'frames' => [['file' => dirname(__DIR__, 2) . '/modules/events/index.php', 'line' => 120, 'function' => 'render_row']],
        'at' => monitor_time(),
        'request' => ['source' => 'page', 'method' => 'GET', 'uri' => '/modules/events/index.php?id=12', 'host' => 'hodlekki.org',
                      'role' => 'Church_Member', 'user_id' => 3, 'ua' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36', 'ip' => '102.89.1.1'],
    ], $over);
}

/** Record a raw event; returns monitor_record()'s answer. */
function monitor_test_record(PDO $db, array $over = []): array
{
    return monitor_record($db, monitor_prepare_event(monitor_test_raw($over)));
}

function monitor_test_clock(string $lagos): int
{
    $GLOBALS['monitor_test_now'] = (new DateTimeImmutable($lagos, new DateTimeZone('Africa/Lagos')))->getTimestamp();

    return $GLOBALS['monitor_test_now'];
}
