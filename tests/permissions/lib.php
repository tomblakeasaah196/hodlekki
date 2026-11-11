<?php
// /tests/permissions/lib.php
//
// A small church in SQLite for the permission tests: the perm_* tables come
// from the REAL migration (translated), the day-one matrix from the REAL seed
// file, and the platform tables are stand-ins with the columns production has
// after 20261126090100_perm_platform_columns.sql. Departments and role ids
// mirror production (11 Nov 2026); every person is synthetic.

require_once __DIR__ . '/install.php';

$GLOBALS['perm_test_passed'] = 0;
$GLOBALS['perm_test_failed'] = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['perm_test_passed']++;
        return;
    }
    $GLOBALS['perm_test_failed']++;
    echo "  FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function eq(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $name): void
{
    echo "-- {$name}\n";
}

function perm_test_platform_sql(): array
{
    return [
        "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL DEFAULT '', last_name TEXT NOT NULL DEFAULT '',
            spiritual_status TEXT, account_status TEXT NOT NULL DEFAULT 'active')",
        "CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT NOT NULL UNIQUE, label TEXT, description TEXT,
            is_break_glass INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0, is_archived INTEGER NOT NULL DEFAULT 0,
            appoint_rank INTEGER, sort_order INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT)",
        "CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, is_primary INTEGER DEFAULT 0,
            custom_title TEXT, is_frozen INTEGER DEFAULT 0, starts_on TEXT, ends_on TEXT, assigned_by INTEGER,
            assigned_at TEXT, reminded_at TEXT, PRIMARY KEY (user_id, role_id))",
        "CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT, target_demographic TEXT DEFAULT 'All',
            parent_id INTEGER, type TEXT NOT NULL DEFAULT 'Service_Unit')",
        "CREATE TABLE ministry_years (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, start_date TEXT NOT NULL,
            end_date TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE user_departments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, department_id INTEGER NOT NULL,
            season INTEGER NOT NULL DEFAULT 2026, role_in_dept TEXT DEFAULT 'Worker', membership_type TEXT NOT NULL DEFAULT 'Primary',
            ministry_year_id INTEGER, roster_status TEXT NOT NULL DEFAULT 'Active', joined_at TEXT, is_active INTEGER DEFAULT 1,
            end_date TEXT, reminded_at TEXT)",
    ];
}

/** Production's departments and roles (ids and names as of 11 Nov 2026). */
function perm_test_platform_data(PDO $pdo): void
{
    $pdo->exec("INSERT INTO departments (id, name, type, parent_id) VALUES
        (1, 'Information and Data Insights', 'Service_Unit', NULL),
        (2, 'Embrace', 'Service_Unit', NULL),
        (3, 'Envision', 'Service_Unit', NULL),
        (4, 'River of Life', 'Service_Unit', NULL),
        (5, 'Joy', 'Service_Unit', NULL),
        (6, 'Sanitation', 'Service_Unit', NULL),
        (7, 'Zoe', 'Service_Unit', NULL),
        (8, 'Reach', 'Service_Unit', NULL),
        (9, 'HOD Academy', 'Service_Unit', NULL),
        (10, 'Charis', 'Service_Unit', NULL),
        (11, 'Junior Church', 'Service_Unit', NULL),
        (13, 'Camp David', 'Service_Unit', NULL),
        (14, 'Finance', 'Administrative', NULL),
        (15, 'Charis Visitation', 'Service_Unit', 10)");
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES
        (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor'), (4, 'Director'), (5, 'HOD'), (6, 'Worker'), (7, 'Member')");
    $pdo->exec("INSERT INTO ministry_years (id, label, start_date, end_date, is_active) VALUES (1, '2026', '2026-01-01', '2026-12-31', 1)");
}

/** The portable statements of the platform migration (its role flags). */
function perm_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach (perm_test_platform_sql() as $stmt) {
        $pdo->exec($stmt);
    }
    perm_test_platform_data($pdo);
    perm_test_install($pdo);
    perm_set_today('2026-11-11');
    return $pdo;
}

/** A person; returns their id. */
function perm_test_person(PDO $pdo, string $first, string $last = 'Test', string $status = 'active'): int
{
    $pdo->prepare('INSERT INTO users (first_name, last_name, account_status) VALUES (?, ?, ?)')->execute([$first, $last, $status]);
    return (int) $pdo->lastInsertId();
}

function perm_test_role(PDO $pdo, int $uid, string $role, array $extra = []): void
{
    $id = (int) $pdo->query('SELECT id FROM roles WHERE role_name = ' . $pdo->quote($role))->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_frozen, starts_on, ends_on) VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, $id, $extra['frozen'] ?? 0, $extra['starts_on'] ?? null, $extra['ends_on'] ?? null]);
    perm_reset();
}

function perm_test_seat(PDO $pdo, int $uid, int $dept, string $rosterValue, array $extra = []): void
{
    $pdo->prepare('INSERT INTO user_departments (user_id, department_id, role_in_dept, ministry_year_id, roster_status, is_active, end_date)
                   VALUES (?, ?, ?, 1, ?, ?, ?)')
        ->execute([$uid, $dept, $rosterValue, $extra['roster_status'] ?? 'Active', $extra['is_active'] ?? 1, $extra['end_date'] ?? null]);
    perm_reset();
}

/** Sign in as someone (no cookie: $_SESSION is just an array in the CLI). */
function perm_test_as(int $uid): void
{
    $_SESSION = ['user_id' => $uid];
}

function perm_test_scope(bool $church, array $depts = [], bool $own = true): array
{
    return perm_scope_normalise(['church' => $church, 'depts' => $depts, 'own' => $own]);
}
