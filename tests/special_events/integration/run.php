<?php
// /tests/special_events/integration/run.php
//
// Integration tests against a real MySQL (guide §22.2).
//
// Unlike tests/special_events/run.php, these need a database: the whole
// point is to prove that se_lock_event() actually serialises concurrent
// seat allocation, which no amount of pure-function testing can show.
//
// They are NOT part of the default CI job, because the shared runner has no
// MySQL service. Run them locally, or in a workflow with a mysql service:
//
//   php tests/special_events/db_setup.php --fresh --seed --db=se_test \
//       --host=127.0.0.1 --port=3306 --user=root --pass=root
//   SE_TEST_DB_NAME=se_test SE_TEST_DB_USER=root SE_TEST_DB_PASS=root \
//       php tests/special_events/integration/run.php
//
// Exit code 0 = all good, 1 = at least one failure.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$_ENV['SE_HASH_PEPPER'] = $_ENV['SE_HASH_PEPPER'] ?? str_repeat('0123456789abcdef', 4);
$_ENV['SMS_VAULT_KEY']  = $_ENV['SMS_VAULT_KEY'] ?? str_repeat('ab', 32);

$root = dirname(__DIR__, 3);

require_once $root . '/includes/sms_functions.php';
foreach (['constants', 'util', 'db', 'theme', 'settings', 'events', 'identity',
          'capacity', 'registration', 'realtime', 'live', 'bible', 'verses',
          'teams', 'checkin', 'messages', 'attendees'] as $lib) {
    require_once $root . '/includes/special_events/' . $lib . '.php';
}

// security.php wants the platform's client-IP helper; the module only ever
// stores an HMAC of it, and in a test there is no request at all.
if (!function_exists('security_client_ip')) {
    function security_client_ip(): string { return '127.0.0.1'; }
}

// --------------------------------------------------------------------------
// Connection
// --------------------------------------------------------------------------

/** A fresh PDO on the test database. Each worker in a race needs its own. */
function se_it_pdo(): PDO
{
    $socket = getenv('SE_TEST_DB_SOCKET') ?: '';
    $host   = getenv('SE_TEST_DB_HOST') ?: '127.0.0.1';
    $port   = (int) (getenv('SE_TEST_DB_PORT') ?: 3306);
    $user   = getenv('SE_TEST_DB_USER') ?: 'root';
    $pass   = getenv('SE_TEST_DB_PASS') ?: '';
    $name   = getenv('SE_TEST_DB_NAME') ?: 'se_test';

    $dsn = $socket !== ''
        ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
        : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET time_zone = '+01:00'");

    return $pdo;
}

$GLOBALS['se_passed'] = 0;
$GLOBALS['se_failed'] = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['se_passed']++;
        echo "    ok    {$label}\n";
        return;
    }
    $GLOBALS['se_failed']++;
    echo "    FAIL  {$label}" . ($detail !== '' ? "  — {$detail}" : '') . "\n";
}

function is_same(string $label, mixed $expected, mixed $actual): void
{
    ok($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// --------------------------------------------------------------------------
// Fixtures
// --------------------------------------------------------------------------

/** A published event starting in a fortnight, with the given capacity. */
function se_it_event(PDO $pdo, array $overrides = []): array
{
    $slug = 'it-' . bin2hex(random_bytes(4));
    $start = (new DateTimeImmutable('+14 days', se_tz()))->setTime(17, 0);
    $end   = $start->modify('+4 hours');

    $fields = $overrides + [
        'online_capacity'        => 20,
        'walkin_capacity'        => null,
        'waitlist_enabled'       => 1,
        'waitlist_capacity'      => null,
        'waitlist_promotion'     => 'auto_confirm',
        'auto_close_at_capacity' => 1,
        'self_cancel_enabled'    => 1,
        'seats_left_mode'        => 'always',
    ];

    $pdo->prepare(
        "INSERT INTO se_events
            (public_id, preview_key, slug, title, organizer_label, venue_name,
             starts_at, ends_at, status, visibility,
             online_capacity, walkin_capacity, waitlist_enabled, waitlist_capacity,
             waitlist_promotion, auto_close_at_capacity, self_cancel_enabled, seats_left_mode,
             created_by)
         VALUES (?, ?, ?, 'Integration', 'Envision', 'HOD Lekki Centre', ?, ?, 'published', 'public',
                 ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        substr(bin2hex(random_bytes(8)), 0, 12),
        substr(bin2hex(random_bytes(16)), 0, 22),
        $slug,
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $fields['online_capacity'],
        $fields['walkin_capacity'],
        $fields['waitlist_enabled'],
        $fields['waitlist_capacity'],
        $fields['waitlist_promotion'],
        $fields['auto_close_at_capacity'],
        $fields['self_cancel_enabled'],
        $fields['seats_left_mode'],
    ]);

    $eventId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO se_event_days (event_id, day_date, doors_open_at, starts_at, ends_at, checkin_closes_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $eventId,
        $start->format('Y-m-d'),
        $start->modify('-60 minutes')->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
    ]);

    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$eventId]);

    return $stmt->fetch();
}

/** The nth distinct Nigerian mobile in a deterministic block. */
function se_it_phone(int $n): array
{
    return se_phone_normalize(sprintf('080%08d', 10000000 + $n));
}

/** Register one guest through the real se_register() path. */
function se_it_register(PDO $pdo, array $event, int $n): array
{
    $days     = se_event_days($pdo, (int) $event['id']);
    $settings = se_event_settings($event);

    return se_register($pdo, $event, $settings, $days, [
        'phone'      => se_it_phone($n),
        'first_name' => 'Guest' . $n,
        'last_name'  => 'Tester',
        'gender'     => $n % 2 === 0 ? 'Female' : 'Male',
        'consent'    => true,
        'consent_text' => 'Test consent',
        'answers'    => [],
        'channel'    => 'portal',
    ], ['now' => se_now(), 'ip_hash' => null, 'device' => null]);
}

// ==========================================================================
// Test 1 — the seat race (§22.2)
// ==========================================================================
//
// 60 registrations for 20 seats, fired from 60 separate connections at the
// same moment. Exactly 20 must be confirmed and exactly 40 waitlisted. A
// check-then-insert implementation oversells here; se_lock_event() does not.

echo "\n  seat race\n";

$pdo   = se_it_pdo();
$event = se_it_event($pdo, ['online_capacity' => 20]);
$eventId = (int) $event['id'];

$workers  = 60;
$capacity = 20;

$children = [];
$canFork  = function_exists('pcntl_fork');

if ($canFork) {
    // Real concurrency: each child opens its own connection, so the lock is
    // genuinely contended rather than reentrant on one handle.
    for ($i = 1; $i <= $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $child = se_it_pdo();
                $stmt  = $child->prepare("SELECT * FROM se_events WHERE id = ?");
                $stmt->execute([$eventId]);
                se_it_register($child, $stmt->fetch(), $i);
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, "    worker {$i}: " . $e->getMessage() . "\n");
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
} else {
    echo "    (pcntl not available: running the same 60 registrations serially)\n";
    for ($i = 1; $i <= $workers; $i++) {
        se_it_register($pdo, $event, $i);
    }
}

$counts = se_capacity_counts($pdo, $eventId);

is_same('exactly the capacity is confirmed', $capacity, $counts['online_taken']);
is_same('everyone else is waitlisted', $workers - $capacity, $counts['waitlisted']);
is_same('nobody is lost', $workers, $counts['online_taken'] + $counts['waitlisted']);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ?");
$stmt->execute([$eventId]);
is_same('one row per person, no duplicates', $workers, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT reg_code) FROM se_registrations WHERE event_id = ?");
$stmt->execute([$eventId]);
is_same('every reg code is unique', $workers, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT ref_code) FROM se_registrations WHERE event_id = ?");
$stmt->execute([$eventId]);
is_same('every referral code is unique', $workers, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'confirmed' AND seat_pool <> 'online'");
$stmt->execute([$eventId]);
is_same('every confirmed seat is an online seat', 0, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'waitlisted' AND waitlisted_at IS NULL");
$stmt->execute([$eventId]);
is_same('every waitlisted row is timestamped (the queue needs an order)', 0, (int) $stmt->fetchColumn());

// ==========================================================================
// Test 2 — cancel with promotion (§22.2)
// ==========================================================================
//
// Three confirmed people release their seats. The three longest-waiting
// people on the waitlist must be promoted, in that order, and nobody else.

echo "\n  cancel with promotion\n";

$stmt = $pdo->prepare(
    "SELECT id FROM se_registrations
      WHERE event_id = ? AND status = 'waitlisted'
      ORDER BY waitlisted_at IS NULL, waitlisted_at, id"
);
$stmt->execute([$eventId]);
$queue = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

is_same('the queue is 40 long before we start', 40, count($queue));
$expectedPromotions = array_slice($queue, 0, 3);

$stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE event_id = ? AND status = 'confirmed' ORDER BY id LIMIT 3");
$stmt->execute([$eventId]);
$toCancel = $stmt->fetchAll();

$days = se_event_days($pdo, $eventId);
$promoted = [];

foreach ($toCancel as $reg) {
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $result = se_registration_cancel($pdo, $stmt->fetch(), $days, $reg, 'self');

    is_same('the cancellation took effect', 'cancelled', $result['status']);
    is_same('exactly one person was promoted for it', 1, count($result['promoted']));
    $promoted = array_merge($promoted, $result['promoted']);
}

is_same('three promotions in total', 3, count($promoted));
is_same('and they are the three who waited longest, in order', $expectedPromotions, $promoted);

$counts = se_capacity_counts($pdo, $eventId);
is_same('the house is still exactly full', $capacity, $counts['online_taken']);
is_same('the waitlist is three shorter', 37, $counts['waitlisted']);
is_same('the cancellations are counted', 3, $counts['cancelled']);

$in = implode(',', array_fill(0, count($promoted), '?'));
$stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE id IN ({$in}) AND status = 'confirmed' AND seat_pool = 'online'");
$stmt->execute($promoted);
is_same('every promoted person holds a real online seat', 3, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM se_audit_log WHERE event_id = ? AND action = 'cancel'");
$stmt->execute([$eventId]);
is_same('each cancellation is audited', 3, (int) $stmt->fetchColumn());

echo "\n  cancel when the waitlist is empty\n";

$quiet = se_it_event($pdo, ['online_capacity' => 5]);
se_it_register($pdo, $quiet, 1001);
$stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE event_id = ? LIMIT 1");
$stmt->execute([(int) $quiet['id']]);
$only = $stmt->fetch();

$result = se_registration_cancel($pdo, $quiet, se_event_days($pdo, (int) $quiet['id']), $only, 'self');
is_same('it still cancels', 'cancelled', $result['status']);
is_same('and promotes nobody', [], $result['promoted']);

$result = se_registration_cancel($pdo, $quiet, se_event_days($pdo, (int) $quiet['id']), $only, 'self');
is_same('cancelling twice is a no-op, not an error', 'noop', $result['status']);

echo "\n  self-cancel rules\n";

$locked = se_it_event($pdo, ['online_capacity' => 5, 'self_cancel_enabled' => 0]);
se_it_register($pdo, $locked, 2001);
$stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE event_id = ? LIMIT 1");
$stmt->execute([(int) $locked['id']]);
$reg = $stmt->fetch();

try {
    se_registration_cancel($pdo, $locked, se_event_days($pdo, (int) $locked['id']), $reg, 'self');
    ok('self-cancel is refused when the event forbids it', false, 'no exception');
} catch (SeRuleException $e) {
    is_same('…with SELF_CANCEL_DISABLED', 'SELF_CANCEL_DISABLED', $e->errorCode);
}

$result = se_registration_cancel($pdo, $locked, se_event_days($pdo, (int) $locked['id']), $reg, 'crew');
is_same('but the crew can always do it at the desk', 'cancelled', $result['status']);


// ==========================================================================
// Test 5 — the check-in race (§22.2)
// ==========================================================================
//
// Forty people tap "Check in" at the same instant, from forty connections.
// Three things must hold afterwards, and all three are things a
// check-then-insert implementation gets wrong under load:
//
//   * exactly forty check-in rows, one per person — the unique key on
//     (event_id, registration_id, day_date) is what makes a double tap a
//     no-op rather than a second arrival;
//   * player numbers 1…40, each used once — allocated inside the lock;
//   * team sizes within one of each other, which is invariant I1 holding
//     under real concurrency rather than in a unit test.

echo "\n  check-in race\n";

if (!se_checkin_ready($pdo) || !se_teams_ready($pdo)) {
    echo "    (se_checkins/se_teams not migrated: skipping)\n";
} else {
    $raceEvent = se_it_event($pdo, ['online_capacity' => 60]);
    $raceId    = (int) $raceEvent['id'];

    // Doors are open now, so the window lets everybody through.
    $now = se_now();
    $pdo->prepare(
        "UPDATE se_event_days
            SET day_date = ?, doors_open_at = ?, starts_at = ?, ends_at = ?, checkin_closes_at = ?
          WHERE event_id = ?"
    )->execute([
        $now->format('Y-m-d'),
        $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
        $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
        $now->modify('+3 hours')->format('Y-m-d H:i:s'),
        $now->modify('+3 hours')->format('Y-m-d H:i:s'),
        $raceId,
    ]);
    $pdo->prepare("UPDATE se_events SET starts_at = ?, ends_at = ? WHERE id = ?")->execute([
        $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
        $now->modify('+3 hours')->format('Y-m-d H:i:s'),
        $raceId,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$raceId]);
    $raceEvent = $stmt->fetch();

    // Four teams, the usual Envision palette.
    se_teams_save($pdo, $raceEvent, array_map(
        static fn(string $hex): array => ['color_hex' => $hex],
        ['#D11920', '#1D356A', '#1E9E62', '#F5C518']
    ), 1);

    $arrivals = 40;
    for ($i = 1; $i <= $arrivals; $i++) {
        se_it_register($pdo, $raceEvent, 5000 + $i);
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM se_registrations WHERE event_id = ? AND status = 'confirmed' ORDER BY id"
    );
    $stmt->execute([$raceId]);
    $regIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    is_same('forty people are confirmed and ready to arrive', $arrivals, count($regIds));

    $checkIn = static function (PDO $db, int $eventId, int $registrationId): void {
        $stmt = $db->prepare("SELECT * FROM se_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        se_checkin($db, $event, se_event_days($db, $eventId), [
            'registration_id' => $registrationId,
        ], [
            'method'        => 'desk',
            'device'        => null,
            'actor_user_id' => 1,
            'ip_hash'       => null,
            'is_crew'       => true,
        ]);
    };

    if ($canFork) {
        $kids = [];
        foreach ($regIds as $registrationId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $child = se_it_pdo();
                    // Each child checks the SAME person in twice, half a
                    // millisecond apart: a double tap on a slow phone. The
                    // second must be absorbed, not counted.
                    $checkIn($child, $raceId, $registrationId);
                    try { $checkIn($child, $raceId, $registrationId); } catch (Throwable $e) { /* expected */ }
                    exit(0);
                } catch (Throwable $e) {
                    fwrite(STDERR, "    check-in worker {$registrationId}: " . $e->getMessage() . "\n");
                    exit(1);
                }
            }
            $kids[] = $pid;
        }
        foreach ($kids as $pid) { pcntl_waitpid($pid, $status); }
    } else {
        echo "    (pcntl not available: running the same 40 check-ins serially)\n";
        foreach ($regIds as $registrationId) {
            $checkIn($pdo, $raceId, $registrationId);
            try { $checkIn($pdo, $raceId, $registrationId); } catch (Throwable $e) { /* expected */ }
        }
    }

    $today = se_now()->format('Y-m-d');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_checkins WHERE event_id = ? AND day_date = ?");
    $stmt->execute([$raceId, $today]);
    is_same('one check-in row per person, double taps absorbed', $arrivals, (int) $stmt->fetchColumn());

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND player_no IS NULL AND status = 'confirmed'"
    );
    $stmt->execute([$raceId]);
    is_same('everybody got a player number', 0, (int) $stmt->fetchColumn());

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT player_no) FROM se_registrations WHERE event_id = ? AND player_no IS NOT NULL"
    );
    $stmt->execute([$raceId]);
    is_same('and every player number is unique', $arrivals, (int) $stmt->fetchColumn());

    $stmt = $pdo->prepare(
        "SELECT MIN(player_no), MAX(player_no) FROM se_registrations WHERE event_id = ? AND player_no IS NOT NULL"
    );
    $stmt->execute([$raceId]);
    [$minNo, $maxNo] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    is_same('the numbers run from one…', 1, $minNo);
    is_same('…to forty, with no gaps', $arrivals, $maxNo);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'confirmed' AND team_id IS NULL"
    );
    $stmt->execute([$raceId]);
    is_same('everybody is on a team', 0, (int) $stmt->fetchColumn());

    $teamCounts = se_team_counts($pdo, $raceId);
    $sizes = array_map(static fn(array $c): int => (int) $c['n'], $teamCounts);
    is_same('the four teams hold forty people between them', $arrivals, array_sum($sizes));
    ok('and no team is more than one person bigger than another',
        max($sizes) - min($sizes) <= 1,
        'sizes: ' . implode(', ', $sizes));

    $spread = 0;
    foreach (['n_Male', 'n_Female'] as $key) {
        $column = array_map(static fn(array $c): int => (int) ($c[$key] ?? 0), $teamCounts);
        $spread = max($spread, max($column) - min($column));
    }
    ok('the gender counts are within two, as §10.6.3 requires',
        $spread <= 2, 'spread: ' . $spread);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_team_moves WHERE event_id = ? AND method = 'auto'"
    );
    $stmt->execute([$raceId]);
    is_same('each assignment left exactly one audit row', $arrivals, (int) $stmt->fetchColumn());
}

// ==========================================================================

echo "\n==========================================================\n";
echo "  passed: {$GLOBALS['se_passed']}   failed: {$GLOBALS['se_failed']}\n";
echo "==========================================================\n";

exit($GLOBALS['se_failed'] === 0 ? 0 : 1);
