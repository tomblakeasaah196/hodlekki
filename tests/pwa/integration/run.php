<?php
// /tests/pwa/integration/run.php
/**
 * ============================================================================
 * WEB PUSH — INTEGRATION SUITE (needs a real MySQL or MariaDB)
 * ----------------------------------------------------------------------------
 * The unit suite (tests/pwa/run.php) covers the crypto and the pure string
 * handling. This one covers the half that only a database can answer: the
 * fan-out SQL, the audience expansion, the unique key that stops a
 * notification being sent twice, the cursor that stops months of backlog
 * being replayed at the congregation, and the recovery of a run that died
 * mid-flight.
 *
 *     php tests/pwa/integration/run.php --socket=/tmp/mdb.sock --db=push_it
 *     php tests/pwa/integration/run.php --host=127.0.0.1 --user=root --pass=root --db=push_it
 *
 * It creates its own stand-in tables, applies db/migrations/*_pwa_push.sql,
 * and drops nothing it did not make. Run it on BOTH engines — the production
 * one is unconfirmed (AGENTS.md §Special Events), and they disagree on real
 * things.
 *
 * No network: delivery itself is never attempted, because every subscription
 * here is fictional. What is proved is that the right rows reach the queue.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$opt = getopt('', ['host::', 'port::', 'socket::', 'user::', 'pass::', 'db::']);
$db  = $opt['db'] ?? 'push_it';

$dsn = isset($opt['socket']) && $opt['socket'] !== ''
    ? "mysql:unix_socket={$opt['socket']};dbname={$db};charset=utf8mb4"
    : 'mysql:host=' . ($opt['host'] ?? '127.0.0.1') . ';port=' . ($opt['port'] ?? '3306')
      . ";dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $opt['user'] ?? 'root', $opt['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+01:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
    exit(1);
}

$root = dirname(__DIR__, 3);
require_once $root . '/includes/push_helpers.php';

$checks = 0;
$fails  = [];

function check(string $what, $expected, $actual): void
{
    global $checks, $fails;
    $checks++;
    if ($expected === $actual) { echo "    ok    {$what}\n"; return; }
    $fails[] = $what;
    echo "    FAIL  {$what}\n";
    echo "          expected: " . var_export($expected, true) . "\n";
    echo "          actual:   " . var_export($actual, true) . "\n";
}

function suite(string $name): void { echo "\n  {$name}\n"; }

/* ---------------------------------------------------------------------- *
 * Stand-in platform tables, shaped like production.
 * ---------------------------------------------------------------------- */

foreach ([
    "DROP TABLE IF EXISTS push_queue, push_subscriptions, push_config, push_user_prefs,
                           system_notifications, users, user_departments, user_roles, regions",
    "CREATE TABLE users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(80) NULL, last_name VARCHAR(80) NULL,
        region_id INT UNSIGNED NULL,
        account_status VARCHAR(20) NULL DEFAULT 'active',
        attendance_status VARCHAR(30) NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE user_departments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL, department_id INT UNSIGNED NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE user_roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL, role_id INT UNSIGNED NOT NULL,
        is_frozen TINYINT(1) NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // The production shape, which the test stand-in in tests/special_events
    // does NOT have: department_id, role_id and region_id all exist.
    "CREATE TABLE system_notifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        department_id INT UNSIGNED NULL,
        role_id INT UNSIGNED NULL,
        region_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        message TEXT NULL,
        link_url VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $ddl) {
    $pdo->exec($ddl);
}

// The real migration, exactly as db/migrate.php runs it.
$pdo->exec((string) file_get_contents($root . '/db/migrations/20261012090000_pwa_push.sql'));

$pdo->exec("INSERT INTO users (id, first_name, last_name, region_id, attendance_status) VALUES
    (1,'Ada','Okafor',1,'Active'), (2,'Tunde','Bello',1,'Active'),
    (3,'Ngozi','Eze',2,'Active'),  (4,'Gone','Away',1,'Relocated'),
    (5,'Frozen','Role',2,'Active')");
$pdo->exec("INSERT INTO user_departments (user_id, department_id, is_active) VALUES
    (1,7,1), (2,7,1), (3,7,0)");
$pdo->exec("INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES
    (1,3,0), (2,3,NULL), (5,3,1)");

echo "\n==========================================================\n";
echo "  Web Push integration — " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "==========================================================\n";

/* ---------------------------------------------------------------------- */
suite('the schema is there and degrade-safe checks agree');
check('push_subscriptions exists', true, push_table_exists($pdo, 'push_subscriptions'));
check('push_queue exists', true, push_table_exists($pdo, 'push_queue'));
check('a table that does not exist reads false', false, push_table_exists($pdo, 'push_nope'));
check('the audience columns are detected', true,
    isset(push_notification_columns($pdo)['department_id'], push_notification_columns($pdo)['region_id']));

/* ---------------------------------------------------------------------- */
suite('switching the feature on never replays the backlog');
// Months of history already in the table before push existed.
for ($i = 0; $i < 25; $i++) {
    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message) VALUES (1, 'Old', 'History')")->execute();
}
$first = push_fanout($pdo);
check('the first run queues nothing', 0, $first['queued']);
check('and parks the cursor at the newest row', 25, $first['cursor']);
check('so the queue is empty', 0, (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

/* ---------------------------------------------------------------------- */
suite('a new notification reaches exactly one person');
$pdo->exec("INSERT INTO system_notifications (user_id, title, message, link_url)
            VALUES (1, 'New Follow-up Task', 'A first timer was assigned to you.', '/modules/embrace/index.php')");
$r = push_fanout($pdo);
check('one row scanned', 1, $r['scanned']);
check('one job queued', 1, $r['queued']);

$job = $pdo->query("SELECT * FROM push_queue ORDER BY id DESC LIMIT 1")->fetch();
check('addressed to the right member', 1, (int) $job['user_id']);
check('carries the title', 'New Follow-up Task', $job['title']);
check('carries the cleaned body', 'A first timer was assigned to you.', $job['body']);
check('carries the link', '/modules/embrace/index.php', $job['url']);
check('starts pending', 'pending', $job['status']);
check('tagged by notification id', 'hod-notif-26', $job['tag']);

/* ---------------------------------------------------------------------- */
suite('nobody is ever sent the same notification twice');
$again = push_fanout($pdo);
check('a second run finds nothing new', 0, $again['queued']);
// Force the cursor back: a worker that died after queueing but before saving
// the cursor must not double-send on the next run.
push_config_set($pdo, 'fanout_cursor_id', '25');
$replay = push_fanout($pdo);
check('replaying the same row queues nothing new', 0, $replay['queued']);
check('still exactly one job', 1, (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

/* ---------------------------------------------------------------------- */
suite('audience expansion');
$pdo->exec("DELETE FROM push_queue");

$pdo->exec("INSERT INTO system_notifications (department_id, title, message) VALUES (7, 'Department', 'x')");
push_fanout($pdo);
$dept = array_map('intval', $pdo->query("SELECT user_id FROM push_queue ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN));
check('a department reaches its live roster only', [1, 2], $dept);

$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO system_notifications (role_id, title, message) VALUES (3, 'Role', 'x')");
push_fanout($pdo);
$role = array_map('intval', $pdo->query("SELECT user_id FROM push_queue ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN));
check('a role skips frozen holders', [1, 2], $role);

$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO system_notifications (region_id, title, message) VALUES (1, 'Region', 'x')");
push_fanout($pdo);
$region = array_map('intval', $pdo->query("SELECT user_id FROM push_queue ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN));
check('a region skips people who have relocated', [1, 2], $region);

// The N-squared guard: api/regions_api.php writes ONE row per member, each
// carrying user_id AND region_id. Expanding both would make a 200-member
// broadcast 40,000 queue writes.
$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO system_notifications (user_id, region_id, title, message) VALUES (1, 1, 'Broadcast', 'x')");
push_fanout($pdo);
check('a row naming a user goes to that user alone', 1,
    (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

/* ---------------------------------------------------------------------- */
suite('rows already dealt with are not pushed');
$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO system_notifications (user_id, title, message, is_read) VALUES (1, 'Read already', 'x', 1)");
$read = push_fanout($pdo);
check('a read notification is scanned', 1, $read['scanned']);
check('but never queued', 0, $read['queued']);

/* ---------------------------------------------------------------------- */
suite('member preferences are honoured at queue time');
$pdo->exec("DELETE FROM push_queue");
push_save_prefs($pdo, 1, ['push_enabled' => false]);
$pdo->exec("INSERT INTO system_notifications (user_id, title, message) VALUES (1, 'Ordinary', 'x')");
push_fanout($pdo);
check('somebody opted out is not queued', 0, (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

push_save_prefs($pdo, 1, ['push_enabled' => true]);
check('turning it back on is read fresh', 1, push_user_prefs($pdo, 1, true)['push_enabled']);

// Quiet hours covering the whole day, so the result does not depend on the
// clock the suite happens to run at.
push_save_prefs($pdo, 1, ['push_enabled' => true, 'quiet_from' => 0, 'quiet_to' => 23]);
check('a normal message is held during quiet hours', false, push_user_allows($pdo, 1, 'normal'));
check('a security alert still goes through', true, push_user_allows($pdo, 1, 'high'));
push_save_prefs($pdo, 1, ['push_enabled' => true, 'quiet_from' => null, 'quiet_to' => null]);
check('clearing quiet hours restores delivery', true, push_user_allows($pdo, 1, 'normal'));

/* ---------------------------------------------------------------------- */
suite('subscriptions');
$key  = push_b64url_encode("\x04" . str_repeat("\x11", 64));
$auth = push_b64url_encode(str_repeat("\x22", 16));

$saved = push_save_subscription($pdo, 1, 'https://fcm.googleapis.com/fcm/send/abc', $key, $auth,
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/124.0.0.0 Mobile Safari/537.36');
check('a device registers', 'success', $saved['status']);
check('and is found', 1, count(push_user_subscriptions($pdo, 1)));

$again = push_save_subscription($pdo, 1, 'https://fcm.googleapis.com/fcm/send/abc', $key, $auth, 'same device');
check('the same endpoint re-registers rather than duplicating', 'success', $again['status']);
check('still one device', 1, count(push_user_subscriptions($pdo, 1)));

check('a malformed key is refused', 'error',
    push_save_subscription($pdo, 1, 'https://fcm.googleapis.com/x', 'not-a-key', $auth)['status']);
check('a non-https endpoint is refused', 'error',
    push_save_subscription($pdo, 1, 'http://insecure.example/x', $key, $auth)['status']);
check('a dead endpoint is retired, not deleted', true, (function (PDO $pdo) {
    $id = (int) $pdo->query("SELECT id FROM push_subscriptions LIMIT 1")->fetchColumn();
    push_retire_subscription($pdo, $id, 'HTTP 410 — gone');
    $row = $pdo->query("SELECT is_active, last_error FROM push_subscriptions WHERE id = {$id}")->fetch();
    return (int) $row['is_active'] === 0 && str_contains($row['last_error'], '410');
})($pdo));
check('and is no longer offered to the sender', 0, count(push_user_subscriptions($pdo, 1)));

/* ---------------------------------------------------------------------- */
suite('the queue drains without devices rather than hanging');
$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO system_notifications (user_id, title, message) VALUES (2, 'For Tunde', 'x')");
push_fanout($pdo);
check('queued for a member with no device', 1, (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

$drain = push_drain($pdo, 10, 5.0);
check('the job was claimed', 1, $drain['claimed']);
check('and closed as skipped, not left pending', 'skipped',
    $pdo->query("SELECT status FROM push_queue ORDER BY id DESC LIMIT 1")->fetchColumn());

/* ---------------------------------------------------------------------- */
suite('a run that died mid-flight is recovered');
$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO push_queue (user_id, title, body, url, status, attempts, created_at)
            VALUES (1, 'Interrupted', 'x', '/', 'sending', 1, DATE_SUB(NOW(), INTERVAL 30 MINUTE))");
$pdo->exec("INSERT INTO push_queue (user_id, title, body, url, status, attempts, created_at)
            VALUES (1, 'Exhausted', 'x', '/', 'sending', " . PUSH_MAX_ATTEMPTS . ", DATE_SUB(NOW(), INTERVAL 30 MINUTE))");
$pdo->exec("INSERT INTO push_queue (user_id, title, body, url, status, attempts)
            VALUES (1, 'Still going', 'x', '/', 'sending', 1)");

check('one row is put back', 1, push_recover_stuck($pdo));
$statuses = $pdo->query("SELECT title, status FROM push_queue ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
check('the interrupted one retries', 'pending', $statuses['Interrupted']);
check('the exhausted one is abandoned', 'failed', $statuses['Exhausted']);
check('a run still in flight is left alone', 'sending', $statuses['Still going']);

/* ---------------------------------------------------------------------- */
suite('the unread count behind the app icon badge');
$pdo->exec("DELETE FROM system_notifications");
$pdo->exec("INSERT INTO system_notifications (user_id, title, is_read) VALUES (1,'a',0),(1,'b',0),(1,'c',1)");
$pdo->exec("INSERT INTO system_notifications (department_id, title, is_read) VALUES (7,'d',0)");
$pdo->exec("INSERT INTO system_notifications (role_id, title, is_read) VALUES (3,'e',0)");
check('counts direct, department and role, unread only', 4, push_unread_count($pdo, 1));
check('somebody with nothing has nothing', 0, push_unread_count($pdo, 99));

/* ---------------------------------------------------------------------- */
suite('the worker lock keeps two senders apart');
$other = new PDO($dsn, $opt['user'] ?? 'root', $opt['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check('a second connection can take the lock first', 1,
    (int) $other->query("SELECT GET_LOCK('hod_push_worker', 0)")->fetchColumn());
$blocked = push_run($pdo, 10, 1.0);
check('so push_run() stands down instead of double-sending', true, $blocked['locked']);
$other->query("SELECT RELEASE_LOCK('hod_push_worker')");
$free = push_run($pdo, 10, 1.0);
check('and runs once the lock is free', false, $free['locked']);

/* ---------------------------------------------------------------------- */
suite('pruning');
$pdo->exec("DELETE FROM push_queue");
$pdo->exec("INSERT INTO push_queue (user_id, title, body, url, status, created_at)
            VALUES (1,'Old sent','x','/','sent', DATE_SUB(NOW(), INTERVAL 30 DAY)),
                   (1,'Recent sent','x','/','sent', NOW()),
                   (1,'Old pending','x','/','pending', DATE_SUB(NOW(), INTERVAL 30 DAY))");
check('old finished rows are cleared', 1, push_prune($pdo, 14));
check('recent and still-pending rows are kept', 2,
    (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn());

echo "\n==========================================================\n";
if ($fails === []) {
    echo "  passed: {$checks}   failed: 0\n";
    echo "==========================================================\n";
    exit(0);
}
echo "  passed: " . ($checks - count($fails)) . "   failed: " . count($fails) . "\n";
echo "==========================================================\n";
foreach ($fails as $f) { echo "  - {$f}\n"; }
exit(1);
