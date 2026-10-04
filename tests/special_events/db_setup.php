<?php
// /tests/special_events/db_setup.php
//
// Builds a local test database for the Special Events module:
//   1. creates minimal STAND-INS for the pre-existing platform tables the
//      module reads (users, roles, user_roles, user_departments, departments,
//      system_notifications, sms_campaigns, sms_queue, reach_*), derived from
//      how the module's code uses them — NOT a copy of production DDL;
//   2. applies every file in db/migrations/ the way db/migrate.php does, i.e.
//      the whole file in ONE PDO::exec(), which is how production behaves;
//   3. optionally seeds a usable Envision team and a draft event.
//
// CLI only. It never touches the production database: the DSN comes from the
// arguments or from SE_TEST_DB_* environment variables, never from .env.
//
// Usage:
//   php tests/special_events/db_setup.php --socket=/run/mysqld/mysqld.sock --db=se_test [--seed] [--fresh]
//   php tests/special_events/db_setup.php --host=127.0.0.1 --port=3308 --user=root --db=se_test

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$opts = se_test_args($argv);

$socket = $opts['socket'] ?? getenv('SE_TEST_DB_SOCKET') ?: '';
$host   = $opts['host']   ?? getenv('SE_TEST_DB_HOST')   ?: '127.0.0.1';
$port   = (int) ($opts['port'] ?? getenv('SE_TEST_DB_PORT') ?: 3306);
$user   = $opts['user']   ?? getenv('SE_TEST_DB_USER')   ?: 'root';
$pass   = $opts['pass']   ?? getenv('SE_TEST_DB_PASS')   ?: '';
$dbName = $opts['db']     ?? getenv('SE_TEST_DB_NAME')   ?: 'se_test';

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "[se-test] --db must be a plain identifier\n");
    exit(1);
}

$serverDsn = $socket !== ''
    ? "mysql:unix_socket={$socket};charset=utf8mb4"
    : "mysql:host={$host};port={$port};charset=utf8mb4";

try {
    $pdo = new PDO($serverDsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[se-test] cannot connect: " . $e->getMessage() . "\n");
    exit(1);
}

if (!empty($opts['fresh'])) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    echo "[se-test] dropped {$dbName}\n";
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");

// Production settings (guide §2.3, Appendix A): strict mode and WAT.
$pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("SET time_zone = '+01:00'");

echo "[se-test] database {$dbName} on " . ($socket !== '' ? $socket : "{$host}:{$port}") . "\n";

// --------------------------------------------------------------------------
// 1. Stand-ins for the platform tables the module reads
// --------------------------------------------------------------------------
//
// Only the columns the module actually touches are present, plus the few a
// realistic query needs. If a later PR reads a new column, add it here and
// say so in that PR — a missing column shows up as a test failure, which is
// exactly the signal we want.

$standIns = [

'departments' => "
    CREATE TABLE IF NOT EXISTS departments (
        id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name  VARCHAR(120) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'users' => "
    CREATE TABLE IF NOT EXISTS users (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name        VARCHAR(80)  NOT NULL DEFAULT '',
        last_name         VARCHAR(80)  NOT NULL DEFAULT '',
        email             VARCHAR(190) NULL,
        phone             VARCHAR(32)  NULL,
        gender            VARCHAR(10)  NULL,
        picture_path      VARCHAR(255) NULL,
        spiritual_status  VARCHAR(40)  NULL,
        marital_status    VARCHAR(40)  NULL,
        physical_address  VARCHAR(255) NULL,
        invitation_source VARCHAR(60) NULL,
        invited_by        VARCHAR(255) NULL,
        qr_code_hash      VARCHAR(128) NULL,
        account_status    VARCHAR(20)  NOT NULL DEFAULT 'active',
        password_hash     VARCHAR(255) NULL,
        created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_users_phone (phone),
        KEY idx_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'roles' => "
    CREATE TABLE IF NOT EXISTS roles (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        role_name  VARCHAR(40)  NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_roles_name (role_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'user_roles' => "
    CREATE TABLE IF NOT EXISTS user_roles (
        user_id    INT UNSIGNED NOT NULL,
        role_id    INT UNSIGNED NOT NULL,
        is_frozen  TINYINT(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'user_departments' => "
    CREATE TABLE IF NOT EXISTS user_departments (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id        INT UNSIGNED NOT NULL,
        department_id  INT UNSIGNED NOT NULL,
        role_in_dept   VARCHAR(40)  NOT NULL DEFAULT 'Member',
        is_active      TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_user_dept (user_id, department_id),
        KEY idx_ud_dept (department_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'system_notifications' => "
    CREATE TABLE IF NOT EXISTS system_notifications (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED NOT NULL,
        title      VARCHAR(190) NOT NULL,
        message    TEXT         NULL,
        link_url   VARCHAR(255) NULL,
        is_read    TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_notif_user (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// SMS Studio: the module enqueues campaigns and never writes sms_log (§16.1).
'sms_campaigns' => "
    CREATE TABLE IF NOT EXISTS sms_campaigns (
        id            INT NOT NULL AUTO_INCREMENT,
        name          VARCHAR(190) NOT NULL,
        message       TEXT         NULL,
        status        VARCHAR(20)  NOT NULL DEFAULT 'draft',
        scheduled_at  DATETIME     NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'sms_queue' => "
    CREATE TABLE IF NOT EXISTS sms_queue (
        id           INT NOT NULL AUTO_INCREMENT,
        campaign_id  INT          NULL,
        phone        VARCHAR(32)  NOT NULL,
        message      TEXT         NULL,
        status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_queue_campaign (campaign_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// Reach: only the hand-off (PR7) writes these; the stand-in exists so the
// hand-off can be developed and tested locally.
'reach_campaigns' => "
    CREATE TABLE IF NOT EXISTS reach_campaigns (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL,
        campaign_type VARCHAR(60) NOT NULL DEFAULT 'Other', campaign_date DATE NULL, start_time TIME NULL, end_time TIME NULL,
        location VARCHAR(255) NULL, meta_description TEXT NULL, share_scripture TEXT NULL,
        payload_tier ENUM('Rapid','Standard','Rich') NOT NULL DEFAULT 'Rich', status ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id), UNIQUE KEY uniq_reach_campaign_slug(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'reach_leads' => "
    CREATE TABLE IF NOT EXISTS reach_leads (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, campaign_id INT UNSIGNED NULL, first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NULL, phone VARCHAR(40) NULL, category ENUM('New_Convert','Unsaved','Saved','Broken','Dechurched','Other') NOT NULL DEFAULT 'Other',
        willing_for_visit TINYINT(1) NOT NULL DEFAULT 0, notes TEXT NULL,
        status ENUM('Not_Spoken_To','Spoken_To','Cold','Converted','Declined') NOT NULL DEFAULT 'Not_Spoken_To',
        assigned_to INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id), KEY idx_reach_leads_campaign(campaign_id), KEY idx_reach_leads_phone(phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'schema_migrations' => "
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename    VARCHAR(255) NOT NULL,
        applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($standIns as $name => $ddl) {
    $pdo->exec($ddl);
}
echo "[se-test] " . count($standIns) . " stand-in tables ready\n";

// Seed the roles and the Envision department (id 3, §6.2).
$pdo->exec("
    INSERT INTO roles (id, role_name) VALUES
        (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor'), (4, 'Church_Member')
    ON DUPLICATE KEY UPDATE role_name = VALUES(role_name)");
$pdo->exec("
    INSERT INTO departments (id, name) VALUES
        (1, 'IDI'), (2, 'Embrace'), (3, 'Envision'), (8, 'Reach')
    ON DUPLICATE KEY UPDATE name = VALUES(name)");

// --------------------------------------------------------------------------
// 2. Apply db/migrations/*.sql exactly as db/migrate.php does
// --------------------------------------------------------------------------
//
// One PDO::exec() for the whole file. That is what production does, so a file
// that only works when split statement-by-statement fails here too.

$migrationsDir = dirname(__DIR__, 2) . '/db/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);

$applied = 0;
$skipped = 0;
foreach ($files as $file) {
    $name = basename($file);

    // Reach/assimilation/SMS/security migrations reference platform tables we
    // do not stand in. Only the module's own files are required to apply.
    $isModule = str_contains($name, '_se_');

    $sql = (string) file_get_contents($file);
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?) ON DUPLICATE KEY UPDATE filename = filename")
            ->execute([$name]);
        $applied++;
        if ($isModule) {
            echo "  applied  {$name}\n";
        }
    } catch (PDOException $e) {
        if ($isModule) {
            fwrite(STDERR, "  FAILED   {$name}: " . $e->getMessage() . "\n");
            exit(1);
        }
        $skipped++;
    }
}
echo "[se-test] migrations: {$applied} applied, {$skipped} skipped (non-module)\n";

// --------------------------------------------------------------------------
// 3. Optional seed data
// --------------------------------------------------------------------------

if (!empty($opts['seed'])) {
    // A manager (Envision HOD), a plain Envision member, and an outsider.
    $pdo->exec("
        INSERT INTO users (id, first_name, last_name, email, phone, gender) VALUES
            (1, 'Tom-Blake', 'Asaah',  'asah.tomf@gmail.com', '08030000001', 'Male'),
            (2, 'Odun-Ayo',  'Funmilola', 'odun@example.test', '08030000002', 'Female'),
            (3, 'Chidera',   'Okeke',  'chidera@example.test', '08030000003', 'Female'),
            (4, 'Ada',       'Obi',    'ada@example.test',     '08030000004', 'Female'),
            (5, 'Outsider',  'Person', 'out@example.test',     '08030000005', 'Male')
        ON DUPLICATE KEY UPDATE first_name = VALUES(first_name)");

    $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1), (2, 4), (3, 4), (4, 4), (5, 4)
                ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)");

    // 2 = Envision Director (manager), 3 = Envision member (studio_member),
    // 4 = member of no department (crew-only candidate).
    $pdo->exec("
        INSERT INTO user_departments (user_id, department_id, role_in_dept, is_active) VALUES
            (2, 3, 'Director', 1),
            (3, 3, 'Member',   1)
        ON DUPLICATE KEY UPDATE role_in_dept = VALUES(role_in_dept)");

    $pdo->exec("INSERT INTO se_settings (setting_key, setting_value) VALUES
                    ('privacy_contact_email', 'privacy@hodlc.lpc.cm')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    echo "[se-test] seeded 5 users (1 Super_Admin, 1 Envision Director, 1 Envision member)\n";
}

echo "[se-test] ready\n";

/** Parse --key=value / --flag arguments. */
function se_test_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }

    return $out;
}
