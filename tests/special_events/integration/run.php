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

// security.php wants the platform's client-IP helper; the module only ever
// stores an HMAC of it, and in a test there is no request at all.
if (!function_exists('security_client_ip')) {
    function security_client_ip(): string { return '127.0.0.1'; }
}

// The same libraries, in the same order, as includes/special_events/bootstrap.php
// loads them. A shorter list hides real faults: without security.php every
// se_audit() call failed on the missing se_ip_hash() and the audit assertions
// below could never pass.
require_once $root . '/includes/sms_functions.php';
foreach (['constants', 'util', 'db', 'theme', 'settings', 'security', 'events', 'assets', 'ai',
          'identity', 'capacity', 'registration', 'realtime', 'live', 'bible', 'verses',
          'teams', 'checkin', 'program', 'karaoke', 'messages', 'attendees', 'cards', 'export',
          'portal', 'games', 'games_engine', 'scoring', 'party_games', 'after_event'] as $lib) {
    require_once $root . '/includes/special_events/' . $lib . '.php';
}

// Snapshots are written under <DOCUMENT_ROOT>/live/<public_id>/ (se_live_root()).
// Point that at a scratch directory so a test run never litters the tree.
$_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir() . '/se_it_docroot_' . getmypid();
@mkdir($_SERVER['DOCUMENT_ROOT'] . '/live', 0775, true);

/**
 * End a forked worker WITHOUT running PHP's shutdown sequence.
 *
 * A child inherits the parent's open MySQL socket. A normal exit() destroys
 * that inherited PDO, which sends COM_QUIT down the shared socket and kills
 * the PARENT's session ("MySQL server has gone away"). Replacing the process
 * image skips every destructor while keeping the exit code.
 */
function se_it_child_exit(int $code): never
{
    if (function_exists('pcntl_exec')) {
        pcntl_exec('/bin/sh', ['-c', 'exit ' . $code]);
    }
    exit($code);
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

/** Move an event's only day to "now", so its check-in window is open. */
function se_it_open_doors(PDO $pdo, int $eventId): void
{
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
        $eventId,
    ]);
    $pdo->prepare("UPDATE se_events SET starts_at = ?, ends_at = ? WHERE id = ?")->execute([
        $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
        $now->modify('+3 hours')->format('Y-m-d H:i:s'),
        $eventId,
    ]);
}

// ==========================================================================
// Test 0 — phone-first Congregation recognition
// ==========================================================================

echo "\n  congregation member lookup\n";

$pdo = se_it_pdo();
$identityEvent = se_it_event($pdo);
$identitySettings = se_event_settings($identityEvent);

// Ada exists in the Congregation users table, but has never used Special
// Events. The first phone lookup must recognise her without a se_contacts row.
$adaPhone = se_phone_normalize('08030000004');
$pdo->prepare("DELETE FROM se_contacts WHERE phone_e164 = ?")->execute([$adaPhone['e164']]);
$lookup = se_lookup_phone($pdo, $identityEvent, $identitySettings, $adaPhone, null);

is_same('a first-time Special Events visitor is recognised from Congregation', 'member', $lookup['kind']);
is_same('the member receives only a masked display name', 'Ada O.', $lookup['display_name']);
ok('known profile fields are not requested again',
    count(array_intersect(['first_name', 'last_name', 'gender', 'email'], $lookup['needs'])) === 0);
ok('the public lookup does not expose private directory fields',
    !array_key_exists('email', $lookup) && !array_key_exists('first_name', $lookup));

// A previous negative result must not hide somebody who was subsequently
// added to Congregation for the remainder of the 24-hour cache window.
$chideraPhone = se_phone_normalize('08030000003');
$pdo->prepare("DELETE FROM se_contacts WHERE phone_e164 = ?")->execute([$chideraPhone['e164']]);
$pdo->prepare(
    "INSERT INTO se_contacts
        (phone_e164, phone_display, first_name, last_name, member_user_id, member_checked_at)
     VALUES (?, ?, 'Guest', '', NULL, NOW())"
)->execute([$chideraPhone['e164'], $chideraPhone['display']]);

$lookup = se_lookup_phone($pdo, $identityEvent, $identitySettings, $chideraPhone, null);
is_same('an interactive lookup bypasses a fresh cached non-member result', 'member', $lookup['kind']);
is_same('the newly matched member name comes from Congregation', 'Chidera O.', $lookup['display_name']);
$stmt = $pdo->prepare("SELECT member_user_id FROM se_contacts WHERE phone_e164 = ?");
$stmt->execute([$chideraPhone['e164']]);
is_same('the successful match is cached on the Special Events contact', 3, (int) $stmt->fetchColumn());

// ==========================================================================
// Test 0b — "I'm going" card can be remade by phone on another device
// ==========================================================================

echo "\n  repeatable card lookup by phone\n";

$cardEvent = se_it_event($pdo, ['online_capacity' => 1]);
$cardConfirmed = se_it_register($pdo, $cardEvent, 60001)['registration'];
$cardWaitlisted = se_it_register($pdo, $cardEvent, 60002)['registration'];
$confirmedPhone = se_it_phone(60001);
$waitlistedPhone = se_it_phone(60002);
$unknownPhone = se_it_phone(60003);

is_same('phone lookup finds a confirmed registration without a device binding',
    (int) $cardConfirmed['id'],
    (int) (se_card_registration_for_phone($pdo, (int) $cardEvent['id'], $confirmedPhone['e164'])['id'] ?? 0));
is_same('phone lookup also finds a waitlisted registration',
    (int) $cardWaitlisted['id'],
    (int) (se_card_registration_for_phone($pdo, (int) $cardEvent['id'], $waitlistedPhone['e164'])['id'] ?? 0));
is_same('an unregistered phone cannot resolve a card registration', null,
    se_card_registration_for_phone($pdo, (int) $cardEvent['id'], $unknownPhone['e164']));
$otherCardEvent = se_it_event($pdo);
is_same('a registration for another event cannot make this event card', null,
    se_card_registration_for_phone($pdo, (int) $otherCardEvent['id'], $confirmedPhone['e164']));

$cancelEvent = se_it_event($pdo);
$cancelled = se_it_register($pdo, $cancelEvent, 60004)['registration'];
$cancelledPhone = se_it_phone(60004);
se_registration_cancel($pdo, $cancelEvent, se_event_days($pdo, (int) $cancelEvent['id']), $cancelled, 'crew');
is_same('a cancelled registration cannot resolve a card on another device', null,
    se_card_registration_for_phone($pdo, (int) $cancelEvent['id'], $cancelledPhone['e164']));
$stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ?");
$stmt->execute([(int) $cancelled['id']]);
$cancelled = $stmt->fetch();
try {
    se_card_payload($pdo, $cancelEvent, se_event_days($pdo, (int) $cancelEvent['id']),
        se_event_settings($cancelEvent), 'im_going', $cancelled);
    ok('the card payload rejects a cancelled registration', false, 'no exception');
} catch (SeRuleException $e) {
    is_same('the card payload rejects it as NOT_REGISTERED', 'NOT_REGISTERED', $e->errorCode);
}

// ==========================================================================
// Test 1 — the seat race (§22.2)
// ==========================================================================
//
// 60 registrations for 20 seats, fired from 60 separate connections at the
// same moment. Exactly 20 must be confirmed and exactly 40 waitlisted. A
// check-then-insert implementation oversells here; se_lock_event() does not.

echo "\n  seat race\n";

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
                se_it_child_exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, "    worker {$i}: " . $e->getMessage() . "\n");
                se_it_child_exit(1);
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

    // Four teams, the usual Envision palette.
    se_teams_save($pdo, $raceEvent, array_map(
        static fn(string $hex): array => ['color_hex' => $hex],
        ['#D11920', '#1D356A', '#1E9E62', '#F5C518']
    ), 1);

    // Register while the event is still upcoming: once it is live, online
    // registration is closed and newcomers come in as walk-ins (§10.4.2).
    $arrivals = 40;
    for ($i = 1; $i <= $arrivals; $i++) {
        se_it_register($pdo, $raceEvent, 5000 + $i);
    }

    // Then open the doors, so the window lets everybody through.
    se_it_open_doors($pdo, $raceId);

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
                    se_it_child_exit(0);
                } catch (Throwable $e) {
                    fwrite(STDERR, "    check-in worker {$registrationId}: " . $e->getMessage() . "\n");
                    se_it_child_exit(1);
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
// Test 6 — the karaoke song race (§10.8.1, §22.2)
// ==========================================================================
//
// Ten people tap the same song in the same second. Exactly one may have it:
// the uniqueness is a UNIQUE index on a generated column, not a SELECT then
// an INSERT, so this is the test that proves the index is the one doing the
// work. The nine losers must get SONG_TAKEN — a sentence, not a 500.

echo "\n  karaoke song race\n";

if (!se_karaoke_ready($pdo)) {
    echo "    (se_karaoke_entries is not in this database — skipped)\n";
} else {
    $kEvent = se_it_event($pdo, ['online_capacity' => 50]);
    $kId    = (int) $kEvent['id'];

    $pdo->prepare("UPDATE se_events SET settings_json = ? WHERE id = ?")->execute([
        se_json_encode(se_settings_normalize([
            'karaoke' => ['enabled' => true, 'prepick_enabled' => true, 'unique_songs' => true, 'list_published' => true],
        ])),
        $kId,
    ]);
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$kId]);
    $kEvent = $stmt->fetch();

    $song = se_song_upsert($pdo, 'Way Maker', 'Sinach', 312, 1);
    $pdo->prepare("INSERT INTO se_event_songs (event_id, song_id, is_active, added_by) VALUES (?, ?, 1, 1)")
        ->execute([$kId, (int) $song['id']]);

    $singers = [];
    for ($i = 1; $i <= 10; $i++) {
        $singers[] = se_it_register($pdo, $kEvent, 7000 + $i)['registration'];
    }

    $pick = static function (PDO $db, int $eventId, array $registration, int $songId): bool {
        $stmt = $db->prepare("SELECT * FROM se_events WHERE id = ?");
        $stmt->execute([$eventId]);

        try {
            se_karaoke_pick($db, $stmt->fetch(), $registration, $songId, 'prepick');

            return true;
        } catch (SeRuleException $e) {
            if ($e->errorCode !== 'SONG_TAKEN') {
                fwrite(STDERR, "    unexpected karaoke error: " . $e->errorCode . "\n");
            }

            return false;
        }
    };

    if ($canFork) {
        $kids = [];
        foreach ($singers as $registration) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $child = se_it_pdo();
                    se_it_child_exit($pick($child, $kId, $registration, (int) $song['id']) ? 0 : 1);
                } catch (Throwable $e) {
                    fwrite(STDERR, "    karaoke worker: " . $e->getMessage() . "\n");
                    se_it_child_exit(2);
                }
            }
            $kids[] = $pid;
        }
        $crashes = 0;
        foreach ($kids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 2) { $crashes++; }
        }
        is_same('nobody got an exception instead of an answer', 0, $crashes);
    } else {
        echo "    (pcntl not available: running the same ten picks serially)\n";
        foreach ($singers as $registration) {
            $pick($pdo, $kId, $registration, (int) $song['id']);
        }
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_karaoke_entries
          WHERE event_id = ? AND song_id = ? AND status IN ('held','queued','up_next','on_stage','done')"
    );
    $stmt->execute([$kId, (int) $song['id']]);
    is_same('exactly one person has the song', 1, (int) $stmt->fetchColumn());

    // The loser may try again on a different song, and must succeed.
    $other = se_song_upsert($pdo, 'Imela', 'Nathaniel Bassey', 298, 1);
    $pdo->prepare("INSERT INTO se_event_songs (event_id, song_id, is_active, added_by) VALUES (?, ?, 1, 1)")
        ->execute([$kId, (int) $other['id']]);

    $stmt = $pdo->prepare(
        "SELECT registration_id FROM se_karaoke_entries WHERE event_id = ? AND song_id = ? LIMIT 1"
    );
    $stmt->execute([$kId, (int) $song['id']]);
    $winnerId = (int) $stmt->fetchColumn();

    $loser = null;
    foreach ($singers as $registration) {
        if ((int) $registration['id'] !== $winnerId) { $loser = $registration; break; }
    }
    ok('somebody who lost the race can take another song',
        $pick($pdo, $kId, $loser, (int) $other['id']));

    // And holds become queue numbers in arrival order at check-in (§10.8.2).
    // Check-in only opens on the night (§10.5), so open the doors first.
    se_it_open_doors($pdo, $kId);
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$kId]);
    $kEvent = $stmt->fetch();

    foreach ([$winnerId, (int) $loser['id']] as $registrationId) {
        se_checkin($pdo, $kEvent, se_event_days($pdo, $kId), ['registration_id' => $registrationId], [
            'method' => 'desk', 'device' => null, 'actor_user_id' => 1, 'ip_hash' => null, 'is_crew' => true,
        ]);
    }

    $stmt = $pdo->prepare(
        "SELECT queue_no FROM se_karaoke_entries WHERE event_id = ? AND status = 'queued' ORDER BY queue_no"
    );
    $stmt->execute([$kId]);
    $numbers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    is_same('checking in turned both holds into queued entries', 2, count($numbers));
    is_same('numbered from one…', 1, $numbers[0] ?? 0);
    is_same('…upwards, in arrival order', 2, $numbers[1] ?? 0);
}

// ==========================================================================
// Test 7 — cron idempotency (§16.4, §23.4)
// ==========================================================================
//
// The cron runs every five minutes and a reminder is due. Running it twice
// must create ONE run, because the run key is unique — this is the whole
// defence against a stuck cron sending the same text to the room twice.

echo "\n  cron idempotency\n";

if (!se_table_exists($pdo, 'se_message_runs')) {
    echo "    (se_message_runs is not in this database — skipped)\n";
} else {
    $mEvent = se_it_event($pdo, ['online_capacity' => 10]);
    $mId    = (int) $mEvent['id'];

    // Register while the event is a fortnight away (registration closes when
    // the doors open, §10.4.2)…
    for ($i = 1; $i <= 3; $i++) {
        se_it_register($pdo, $mEvent, 8000 + $i);
    }

    // …then pull the day in so the same-day reminder (120 minutes before the
    // start) fell due half an hour ago, inside the 90-minute lateness window.
    $start = se_now()->modify('+90 minutes');
    $pdo->prepare(
        "UPDATE se_event_days SET day_date = ?, doors_open_at = ?, starts_at = ?, ends_at = ?, checkin_closes_at = ? WHERE event_id = ?"
    )->execute([
        $start->format('Y-m-d'),
        $start->modify('-30 minutes')->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s'),
        $start->modify('+3 hours')->format('Y-m-d H:i:s'),
        $start->modify('+3 hours')->format('Y-m-d H:i:s'),
        $mId,
    ]);
    $pdo->prepare("UPDATE se_events SET starts_at = ?, settings_json = ? WHERE id = ?")->execute([
        $start->format('Y-m-d H:i:s'),
        se_json_encode(se_settings_normalize([
            'messages' => [
                'reminder_1' => ['enabled' => true, 'at' => '18:00'],
                'reminder_2' => ['enabled' => true, 'minutes_before' => 120],
            ],
        ])),
        $mId,
    ]);
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$mId]);
    $mEvent = $stmt->fetch();

    $first  = se_messages_run_due($pdo, $mEvent, se_now(), null);
    $second = se_messages_run_due($pdo, $mEvent, se_now(), null);

    ok('the first run did something', count($first) > 0, 'ran: ' . count($first));
    is_same('the second run has nothing left to do', 0, count($second));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_message_runs WHERE event_id = ?");
    $stmt->execute([$mId]);
    is_same('one row per run key, however many times the cron fires',
        count($first), (int) $stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT run_key) FROM se_message_runs WHERE event_id = ?");
    $stmt->execute([$mId]);
    is_same('…and every key is distinct', count($first), (int) $stmt->fetchColumn());

    $queued = count(array_filter($first, static fn(array $r): bool => $r['status'] === 'queued'));
    ok('the same-day reminder was queued for the three guests', $queued >= 1,
        'statuses: ' . implode(', ', array_map(static fn(array $r): string => $r['status'] . ' (' . ($r['detail'] ?? '') . ')', $first)));

    if (se_table_exists($pdo, 'sms_campaigns')) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sms_campaigns WHERE filters_json LIKE ?"
        );
        $stmt->execute(['%"se_event_id":' . $mId . '%']);
        // A skipped run (missed, or nobody to text) creates no campaign.
        is_same('one SMS campaign per queued run, never two', $queued, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sms_queue q JOIN sms_campaigns c ON c.id = q.campaign_id WHERE c.filters_json LIKE ?"
        );
        $stmt->execute(['%"se_event_id":' . $mId . '%']);
        is_same('…holding one queued text per guest', 3 * $queued, (int) $stmt->fetchColumn());
    }
}


// ==========================================================================
// Test 8 — a hand-off re-run creates nothing new (§17.6)
// ==========================================================================

echo "\n  hand-off rerun idempotency\n";
if (!se_table_exists($pdo, 'se_handoffs')) {
    echo "    (post-event migration is not in this database — skipped)\n";
} else {
    $hEvent = se_it_event($pdo, ['online_capacity' => 10]);
    $hReg = se_it_register($pdo, $hEvent, 9901);
    $hRegId = (int) ($hReg['registration']['id'] ?? $hReg['id'] ?? 0);
    $pdo->prepare("UPDATE se_registrations SET first_checkin_at=NOW() WHERE id=?")->execute([$hRegId]);
    $first = se_handoff_push($pdo, $hEvent, [], [], 1);
    $second = se_handoff_push($pdo, $hEvent, [], [], 1);
    $q=$pdo->prepare("SELECT COUNT(*) FROM reach_leads WHERE campaign_id=?"); $q->execute([(int)$first['reach_campaign_id']]);
    is_same('one Reach lead after two hand-off runs', 1, (int)$q->fetchColumn());
    $q=$pdo->prepare("SELECT COUNT(*) FROM se_handoff_items WHERE event_id=? AND outcome IN ('created','linked_existing')"); $q->execute([(int)$hEvent['id']]);
    is_same('one successful hand-off item after rerun', 1, (int)$q->fetchColumn());
    ok('rerun records the already-handed-off outcome', (int)($second['counts']['already_handed_off']??0) >= 1);

    // An override may hold a ready person back, never push someone who did
    // not consent (or opted out, or is a member) — that is the whole point
    // of reviewing before anything leaves Envision.
    $noConsent = se_it_register($pdo, $hEvent, 9902);
    $noConsentId = (int) ($noConsent['registration']['id'] ?? 0);
    $pdo->prepare("UPDATE se_registrations SET first_checkin_at=NOW() WHERE id=?")->execute([$noConsentId]);
    $pdo->prepare("UPDATE se_contacts c JOIN se_registrations r ON r.contact_id=c.id SET c.consent_followup=0 WHERE r.id=?")->execute([$noConsentId]);
    $held = se_it_register($pdo, $hEvent, 9903);
    $heldId = (int) ($held['registration']['id'] ?? 0);
    $pdo->prepare("UPDATE se_registrations SET first_checkin_at=NOW() WHERE id=?")->execute([$heldId]);

    $third = se_handoff_push($pdo, $hEvent, [], [
        ['registration_id' => $noConsentId, 'destination' => 'reach'],
        ['registration_id' => $heldId, 'destination' => 'none', 'reason' => 'Asked us not to'],
    ], 1);
    $outcome = static function (int $regId) use ($pdo, $third): ?string {
        $q = $pdo->prepare("SELECT outcome FROM se_handoff_items WHERE handoff_id=? AND registration_id=?");
        $q->execute([(int) $third['handoff_id'], $regId]);
        return $q->fetchColumn() ?: null;
    };
    is_same('an override cannot hand off someone without consent', 'skipped_no_consent', $outcome($noConsentId));
    is_same('…but can hold a ready person back', 'skipped_excluded', $outcome($heldId));
    $q=$pdo->prepare("SELECT COUNT(*) FROM reach_leads WHERE campaign_id=?"); $q->execute([(int)$first['reach_campaign_id']]);
    is_same('…and no Reach lead was created for either', 1, (int)$q->fetchColumn());
}

// ==========================================================================
// Test 9 — a whole game night (§11, Appendix H.3)
// ==========================================================================
//
// Eight checked-in players on four teams play every game type the way the
// host console and the phones drive them, against the real schema. Along the
// way it checks the rules the guide makes non-negotiable: no answer and no
// name in public.json, the ledger never double-counts, a void undoes points,
// the charades phrase reaches only the presenter, and Reset rehearsal leaves
// nothing behind.

echo "\n  game night\n";

/** Expect a SeRuleException with this code. */
function se_it_expect_rule(string $label, string $code, callable $fn): void
{
    try {
        $fn();
        ok($label, false, 'no exception');
    } catch (SeRuleException $e) {
        is_same($label, $code, $e->errorCode);
    } catch (Throwable $e) {
        ok($label, false, get_class($e) . ': ' . $e->getMessage());
    }
}

/** The event row, fresh. */
function se_it_event_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch();
}

/** Open a round's answer window now, instead of sleeping through the preroll. */
function se_it_open_now(PDO $pdo, int $roundId, int $closesInMs = 20000): void
{
    $pdo->prepare("UPDATE se_rounds SET opens_at = ?, closes_at = ? WHERE id = ?")
        ->execute([se_ms_to_sql(se_epoch_ms() - 500), se_ms_to_sql(se_epoch_ms() + $closesInMs), $roundId]);
}

if (!se_game_ready($pdo) || !se_teams_ready($pdo)) {
    echo "    (games tables are not in this database — skipped)\n";
} else {
    $gEvent = se_it_event($pdo, ['online_capacity' => 40]);
    $gId    = (int) $gEvent['id'];
    se_teams_save($pdo, $gEvent, array_map(static fn(string $hex): array => ['color_hex' => $hex],
        ['#000000', '#D11920', '#F5C518', '#1D356A']), 1);

    $players = [];
    for ($i = 1; $i <= 8; $i++) {
        $players[] = (int) se_it_register($pdo, $gEvent, 9100 + $i)['registration']['id'];
    }
    se_it_open_doors($pdo, $gId);
    $gEvent = se_it_event_row($pdo, $gId);

    foreach ($players as $regId) {
        se_checkin($pdo, $gEvent, se_event_days($pdo, $gId), ['registration_id' => $regId], [
            'method' => 'desk', 'device' => null, 'actor_user_id' => 1, 'ip_hash' => null, 'is_crew' => true,
        ]);
    }

    // One phone per player, joined to the games and seen just now.
    $devices = [];
    $regs    = [];
    foreach ($players as $regId) {
        $pdo->prepare(
            "INSERT INTO se_devices (event_id, registration_id, token_hash, mode, joined_games_at, last_seen_at)
             VALUES (?, ?, ?, 'full', NOW(), NOW())"
        )->execute([$gId, $regId, hash('sha256', 'it-device-' . $regId . '-' . random_bytes(4))]);
        $stmt = $pdo->prepare("SELECT * FROM se_devices WHERE id = ?");
        $stmt->execute([(int) $pdo->lastInsertId()]);
        $devices[$regId] = $stmt->fetch();
        $regs[$regId]    = se_registration_by_id($pdo, $regId, $gId);
    }

    $byTeam = [];
    foreach ($regs as $regId => $reg) {
        $byTeam[(int) $reg['team_id']][] = $regId;
    }
    ksort($byTeam);
    $teamIds = array_keys($byTeam);
    is_same('eight players on four teams of two', [2, 2, 2, 2], array_map('count', array_values($byTeam)));

    $starter = se_chara_starter_content($pdo, $gEvent, 1);
    is_same('the starter pack creates the six suggested games', 6, $starter['games']);
    $again = se_chara_starter_content($pdo, $gEvent, 1);
    is_same('pressing it again adds nothing', [0, 0], [$again['items'], $again['games']]);

    $games = [];
    foreach (se_game_list($pdo, $gId) as $g) {
        $games[$g['type']] = $g;
    }
    ok('every starter game has questions and is ready', count(array_filter($games, static fn(array $g): bool => $g['items_total'] > 0 && $g['status'] === 'ready')) === 6);

    $live    = static fn(): int => (int) se_live_state($pdo, $gId)['version'];
    $publicJson = static function () use ($pdo, $gId): string {
        $event = se_it_event_row($pdo, $gId);
        return se_json_encode(se_snapshot_public($pdo, $event, se_live_state($pdo, $gId)));
    };
    $names = array_map(static fn(array $r): string => (string) $r['display_name'], $regs);
    $noNames = static function (string $json) use ($names): bool {
        foreach ($names as $name) {
            if ($name !== '' && str_contains($json, $name)) {
                return false;
            }
        }
        return true;
    };

    // ---- Live Quiz -------------------------------------------------------
    $quiz = $games['live_quiz'];
    se_it_expect_rule('a round cannot start before its game', 'GAME_NOT_LIVE',
        fn() => se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $quiz['id']), false, null, 1));
    se_game_status_set($pdo, $gEvent, $quiz['id'], 'live', null, 1);
    $round = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $quiz['id']), false, $live(), 1);
    se_it_expect_rule('a stale console version is refused', 'STALE_VERSION', static function () use ($pdo, $gEvent, $round): void {
        try {
            se_round_arm_live($pdo, $gEvent, $round['id'], null, null, 1, 1);
        } catch (SeStaleVersionException $e) {
            throw new SeRuleException('STALE_VERSION', $e->getMessage());
        }
    });
    se_round_arm_live($pdo, $gEvent, $round['id'], null, null, $live(), 1);

    $first = $players[0];
    se_it_expect_rule('answering during the preroll is too early', 'TOO_EARLY',
        fn() => se_game_answer($pdo, $gEvent, $regs[$first], ['round_id' => $round['id'], 'choice_index' => 0], $devices[$first]));
    se_it_open_now($pdo, $round['id']);

    $roundRow = se_round_find($pdo, $gId, $round['id']);
    $correct  = (int) se_round_item($pdo, $roundRow)['payload']['answer_index'];
    foreach ($players as $n => $regId) {
        $choice = $n < 5 ? $correct : ($correct + 1) % 4;
        se_game_answer($pdo, $gEvent, $regs[$regId], ['round_id' => $round['id'], 'choice_index' => $choice, 'client_elapsed_ms' => 1000 * $n], $devices[$regId]);
    }
    se_it_expect_rule('a second answer is refused, not counted', 'ALREADY_ANSWERED',
        fn() => se_game_answer($pdo, $gEvent, $regs[$first], ['round_id' => $round['id'], 'choice_index' => 1], $devices[$first]));

    $json = $publicJson();
    $pub  = json_decode($json, true)['game']['round'];
    is_same('public.json counts the answers', 8, $pub['answered'] ?? null);
    ok('…but carries no correct answer before the reveal', !isset($pub['result']) && !str_contains($json, 'answer_index') && !str_contains($json, 'correct_index'));
    ok('…and no names', $noNames($json));

    $out = se_round_transition_live($pdo, $gEvent, $round['id'], 'revealed', $live(), 1);
    is_same('reveal locks, reveals and auto-scores the quiz', 'scored', $out['state']);
    $pub = json_decode($publicJson(), true)['game']['round'];
    is_same('the reveal shows the correct answer', $correct, $pub['result']['correct_index'] ?? null);
    is_same('…and how the room answered', 8, array_sum($pub['result']['distribution'] ?? []));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_score_events WHERE round_id = ? AND scope = 'individual' AND voided_at IS NULL");
    $stmt->execute([$round['id']]);
    is_same('five correct players earned individual points', 5, (int) $stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_score_events WHERE round_id = ?");
    $stmt->execute([$round['id']]);
    $rows = (int) $stmt->fetchColumn();

    se_it_expect_rule('scoring the same round again is refused', 'ROUND_NOT_REVEALED',
        fn() => se_round_score_live($pdo, $gEvent, $round['id'], $live(), 1));
    $stmt->execute([$round['id']]);
    is_same('…and wrote nothing new', $rows, (int) $stmt->fetchColumn());

    $me = se_game_me_payload($pdo, $gEvent, $regs[$first], $devices[$first]);
    ok('a correct player sees ✓ and their points', ($me['round']['my_answer']['is_correct'] ?? null) === true && ($me['score']['points'] ?? 0) > 0);

    se_it_expect_rule('voiding needs a reason', 'VALIDATION', static function () use ($pdo, $gEvent, $round, $live): void {
        try {
            se_round_transition_live($pdo, $gEvent, $round['id'], 'void', $live(), 1, '');
        } catch (SeValidationException $e) {
            throw new SeRuleException('VALIDATION', 'x');
        }
    });
    $void = se_round_transition_live($pdo, $gEvent, $round['id'], 'void', $live(), 1, 'Wrong question shown');
    ok('a void undoes every point the round gave', $void['voided_scores'] === $rows);
    $replay = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $quiz['id']), false, $live(), 1);
    is_same('…and the next round replays the same question', (int) $roundRow['deck_item_id'], (int) se_round_find($pdo, $gId, $replay['id'])['deck_item_id']);
    // Play the replay properly, so the night has an MVP.
    se_round_arm_live($pdo, $gEvent, $replay['id'], null, null, $live(), 1);
    se_it_open_now($pdo, $replay['id']);
    foreach ($players as $n => $regId) {
        se_game_answer($pdo, $gEvent, $regs[$regId], ['round_id' => $replay['id'], 'choice_index' => $n < 3 ? $correct : ($correct + 1) % 4, 'client_elapsed_ms' => 500 * $n], $devices[$regId]);
    }
    se_round_transition_live($pdo, $gEvent, $replay['id'], 'revealed', $live(), 1);

    // ---- Trivia (captain mode) -------------------------------------------
    $trivia = $games['trivia'];
    se_game_status_set($pdo, $gEvent, $trivia['id'], 'live', null, 1);
    $round = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $trivia['id']), false, $live(), 1);
    se_round_arm_live($pdo, $gEvent, $round['id'], null, null, $live(), 1);
    se_it_open_now($pdo, $round['id']);
    $correct = (int) se_round_item($pdo, se_round_find($pdo, $gId, $round['id']))['payload']['answer_index'];

    [$capA, $memberA] = $byTeam[$teamIds[0]];
    se_captain_set($pdo, $gEvent, $teamIds[0], $capA, 1);
    se_it_expect_rule('a non-captain cannot lock in the team answer', 'NOT_CAPTAIN',
        fn() => se_game_answer($pdo, $gEvent, $regs[$memberA], ['round_id' => $round['id'], 'choice_index' => $correct], $devices[$memberA]));
    se_game_suggest($pdo, $gEvent, $regs[$memberA], ['round_id' => $round['id'], 'choice_index' => $correct]);
    se_game_answer($pdo, $gEvent, $regs[$capA], ['round_id' => $round['id'], 'choice_index' => $correct], $devices[$capA]);

    // Team B has no captain answer: its members' suggestions decide.
    foreach ($byTeam[$teamIds[1]] as $regId) {
        se_game_suggest($pdo, $gEvent, $regs[$regId], ['round_id' => $round['id'], 'choice_index' => ($correct + 1) % 4]);
    }

    $teamSnap = se_snapshot_team($pdo, $gEvent, se_live_state($pdo, $gId), se_team_find($pdo, $gId, $teamIds[0]));
    is_same('the team snapshot shows the captain’s locked-in choice', $correct, $teamSnap['captain_choice']['choice_index'] ?? null);
    is_same('…and the captain as a hash the phone can recognise', se_registration_hash($capA), $teamSnap['captain']['registration_id_hash'] ?? null);

    se_round_transition_live($pdo, $gEvent, $round['id'], 'revealed', $live(), 1);
    $result = se_round_result(se_round_find($pdo, $gId, $round['id']));
    is_same('the captain’s team scores', 300, $result['team_points'][(string) $teamIds[0]] ?? null);
    is_same('…a team decided by vote is marked so', 'vote', $result['team_choices'][(string) $teamIds[1]]['by'] ?? null);
    is_same('…and scores nothing when the vote was wrong', 0, $result['team_points'][(string) $teamIds[1]] ?? null);

    // ---- Buzzer ----------------------------------------------------------
    $buzzer = $games['buzzer'];
    se_game_status_set($pdo, $gEvent, $buzzer['id'], 'live', null, 1);
    $round = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $buzzer['id']), false, $live(), 1);
    se_round_arm_live($pdo, $gEvent, $round['id'], null, null, $live(), 1);
    se_it_open_now($pdo, $round['id'], 15000);

    $buzzA = $byTeam[$teamIds[0]][0];
    $buzzB = $byTeam[$teamIds[1]][0];
    se_game_buzz($pdo, $gEvent, $regs[$buzzA], ['round_id' => $round['id'], 'attempt' => 1, 'client_ms' => se_epoch_ms()]);
    se_it_expect_rule('a teammate cannot buzz again in the same attempt', 'ALREADY_BUZZED',
        fn() => se_game_buzz($pdo, $gEvent, $regs[$byTeam[$teamIds[0]][1]], ['round_id' => $round['id'], 'attempt' => 1, 'client_ms' => se_epoch_ms()]));

    $room = se_snapshot_room($pdo, se_it_event_row($pdo, $gId), se_live_state($pdo, $gId));
    is_same('the room snapshot names who buzzed first', (string) $regs[$buzzA]['display_name'], $room['buzz_winner']['display_name'] ?? null);
    ok('…which public.json does not', $noNames($publicJson()));

    $leader = se_round_buzz_leader($pdo, se_round_find($pdo, $gId, $round['id']));
    $judged = se_buzz_judge_party($pdo, $gEvent, $round['id'], (int) $leader['id'], false, $live(), 1);
    ok('a wrong answer reopens the buzzers for the others', $judged['reopened'] === true);
    $roundRow = se_round_find($pdo, $gId, $round['id']);
    is_same('…as attempt two', 2, (int) $roundRow['attempt']);
    se_it_open_now($pdo, $round['id'], 10000);
    se_it_expect_rule('the wrong team is locked out', 'LOCKED_OUT',
        fn() => se_game_buzz($pdo, $gEvent, $regs[$buzzA], ['round_id' => $round['id'], 'attempt' => 2, 'client_ms' => se_epoch_ms()]));
    se_game_buzz($pdo, $gEvent, $regs[$buzzB], ['round_id' => $round['id'], 'attempt' => 2, 'client_ms' => se_epoch_ms()]);
    $leader = se_round_buzz_leader($pdo, se_round_find($pdo, $gId, $round['id']));
    $judged = se_buzz_judge_party($pdo, $gEvent, $round['id'], (int) $leader['id'], true, $live(), 1);
    is_same('a correct buzz scores the team', 300, $judged['points']);
    is_same('…and closes the round', 'scored', se_round_find($pdo, $gId, $round['id'])['state']);

    // ---- Who Am I? -------------------------------------------------------
    $who = $games['who_am_i'];
    se_game_status_set($pdo, $gEvent, $who['id'], 'live', null, 1);
    $round = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $who['id']), false, $live(), 1);
    se_round_arm_live($pdo, $gEvent, $round['id'], null, null, $live(), 1);
    $pub = json_decode($publicJson(), true)['game']['round'];
    is_same('only the first clue is public', 1, count($pub['clues'] ?? []));

    // The window runs out with nobody buzzing: the tick locks the round,
    // and Next clue must still work (it used to be refused).
    $pdo->prepare("UPDATE se_rounds SET opens_at = ?, closes_at = ? WHERE id = ?")
        ->execute([se_ms_to_sql(se_epoch_ms() - 30000), se_ms_to_sql(se_epoch_ms() - 1000), $round['id']]);
    ok('the tick locks a buzz window nobody used', se_games_tick($pdo, $gEvent));
    se_clue_next($pdo, $gEvent, $round['id'], $live(), 1);
    $pub = json_decode($publicJson(), true)['game']['round'];
    is_same('…and the next clue reopens it', [2, 'armed'], [count($pub['clues'] ?? []), $pub['state'] ?? null]);
    se_it_open_now($pdo, $round['id']);
    $buzzC = $byTeam[$teamIds[2]][0];
    se_game_buzz($pdo, $gEvent, $regs[$buzzC], ['round_id' => $round['id'], 'attempt' => 2, 'client_ms' => se_epoch_ms()]);
    $leader = se_round_buzz_leader($pdo, se_round_find($pdo, $gId, $round['id']));
    $judged = se_buzz_judge_party($pdo, $gEvent, $round['id'], (int) $leader['id'], true, $live(), 1);
    is_same('a right answer on the second clue is worth 400', 400, $judged['points']);

    // ---- Charades --------------------------------------------------------
    $charades = $games['charades'];
    se_game_status_set($pdo, $gEvent, $charades['id'], 'live', null, 1);
    $turn = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $charades['id']), false, $live(), 1);
    $presenterId = $byTeam[$teamIds[0]][0];
    $pick = se_charades_turn($pdo, $gEvent, $turn['id'], $teamIds[0], '#' . $regs[$presenterId]['player_no'], false, $live(), 1);
    is_same('the presenter is found by player number', $presenterId, $pick['presenter']['registration_id']);

    $secret = se_charades_presenter_payload($pdo, $gEvent, $presenterId, $devices[$presenterId]);
    ok('the presenter’s phone gets the phrase', ($secret['phrase'] ?? '') !== '');
    is_same('…a teammate’s phone does not', null, se_charades_presenter_payload($pdo, $gEvent, $byTeam[$teamIds[0]][1], $devices[$byTeam[$teamIds[0]][1]]));
    ok('…and no snapshot carries it', !str_contains($publicJson(), (string) $secret['phrase'])
        && !str_contains(se_json_encode(se_snapshot_room($pdo, se_it_event_row($pdo, $gId), se_live_state($pdo, $gId))), (string) $secret['phrase']));

    $seen = [(string) $secret['phrase']];
    se_charades_start($pdo, $gEvent, $turn['id'], $live(), 1);
    se_it_open_now($pdo, $turn['id'], 60000);
    foreach (['correct', 'pass', 'correct'] as $mark) {
        se_charades_mark($pdo, $gEvent, $turn['id'], $mark, $live(), 1);
        $next = se_charades_presenter_payload($pdo, $gEvent, $presenterId, $devices[$presenterId]);
        if (!empty($next['phrase'])) {
            $seen[] = (string) $next['phrase'];
        }
    }
    se_charades_end($pdo, $gEvent, $turn['id'], $live(), 1);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM se_score_events WHERE round_id = ? AND voided_at IS NULL");
    $stmt->execute([$turn['id']]);
    is_same('two words guessed is 400 points', 400, (int) $stmt->fetchColumn());

    $turn2 = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $charades['id']), false, $live(), 1);
    $presenter2 = $byTeam[$teamIds[1]][0];
    se_charades_turn($pdo, $gEvent, $turn2['id'], $teamIds[1], (string) $regs[$presenter2]['player_no'], false, $live(), 1);
    $secret2 = se_charades_presenter_payload($pdo, $gEvent, $presenter2, $devices[$presenter2]);
    ok('the next team gets a phrase nobody has seen yet', ($secret2['phrase'] ?? '') !== '' && !in_array($secret2['phrase'], $seen, true),
        'seen: ' . implode(' | ', $seen) . ' — got: ' . ($secret2['phrase'] ?? 'none'));
    se_charades_end($pdo, $gEvent, $turn2['id'], $live(), 1);

    // ---- Family Feud -----------------------------------------------------
    $feud  = $games['feud'];
    $items = se_feud_items($pdo, $gEvent);
    $first = $items[0]['item_id'];
    foreach ($players as $n => $regId) {
        se_survey_save($pdo, $gEvent, $regs[$regId], $first, ['Lions', 'the lion', 'Giraffe', 'Elephants'][$n % 4]);
    }
    $draft = se_feud_build_board($pdo, $gEvent, $first, 1);
    is_same('too few answers for AI: the exact groups are offered', 'manual', $draft['mode']);
    is_same('…"Lions" and "the lion" are one answer', 4, $draft['answers'][0]['points'] ?? null);
    se_feud_board_save($pdo, $gEvent, $first, $draft['answers'], true, 1);
    foreach (array_slice($items, 1) as $other) {
        se_feud_board_save($pdo, $gEvent, $other['item_id'], [['label' => 'One', 'points' => 5]], true, 1);
    }

    se_game_status_set($pdo, $gEvent, $feud['id'], 'live', null, 1);
    se_it_expect_rule('the survey closes when the Feud starts', 'ROUND_CLOSED',
        fn() => se_survey_save($pdo, $gEvent, $regs[$players[0]], $first, 'Doves'));
    $round = se_round_next_live($pdo, $gEvent, se_game_find($pdo, $gId, $feud['id']), false, $live(), 1);
    se_feud_update($pdo, $gEvent, $round['id'], 'faceoff', ['team_a' => $teamIds[0], 'team_b' => $teamIds[1]], $live(), 1);
    $board = se_feud_board($pdo, $gEvent, $first, true);
    se_feud_update($pdo, $gEvent, $round['id'], 'reveal', ['answer_id' => (int) $board[0]['id']], $live(), 1);
    se_feud_update($pdo, $gEvent, $round['id'], 'control', ['team_id' => $teamIds[0]], $live(), 1);
    se_feud_update($pdo, $gEvent, $round['id'], 'reveal', ['answer_id' => (int) $board[1]['id']], $live(), 1);
    for ($k = 0; $k < 3; $k++) {
        se_feud_update($pdo, $gEvent, $round['id'], 'strike', [], $live(), 1);
    }
    $state = se_feud_update($pdo, $gEvent, $round['id'], 'steal', ['success' => false], $live(), 1);
    is_same('three strikes and a failed steal go to the bank', 'bank', $state['feud']['phase']);
    $bank = $state['feud']['bank'];
    se_feud_update($pdo, $gEvent, $round['id'], 'bank', [], $live(), 1);
    $stmt = $pdo->prepare("SELECT team_id, points FROM se_score_events WHERE idempotency_key = ? AND event_id = ?");
    $stmt->execute(['f:' . $round['id'] . ':bank', $gId]);
    $banked = $stmt->fetch();
    is_same('the controlling team banks the revealed points', [$teamIds[0], $bank], [(int) $banked['team_id'], (int) $banked['points']]);
    $pubFeud = json_decode($publicJson(), true)['game']['round'];
    ok('the public board never shows unrevealed answers', count(array_filter($pubFeud['board'] ?? [], static fn(array $s): bool => !$s['revealed'] && $s['label'] !== null)) === 0);

    // ---- Leaderboard and finale ------------------------------------------
    se_score_adjust_live($pdo, se_it_event_row($pdo, $gId), ['scope' => 'team', 'team_id' => $teamIds[3], 'points' => 100, 'reason' => 'Best team spirit'], $live(), 1);
    $finale = se_finale_start($pdo, se_it_event_row($pdo, $gId), $live(), 1);
    ok('the finale has a champion', ($finale['finale']['champion']['points'] ?? 0) > 0);
    $json = $publicJson();
    ok('public.json during the finale names teams, never people', $noNames($json));
    $room = se_snapshot_room($pdo, se_it_event_row($pdo, $gId), se_live_state($pdo, $gId));
    ok('the MVP name travels in the room snapshot', ($room['mvp'][0]['display_name'] ?? '') !== '');
    ok('named awards are listed', in_array('Best team spirit', array_column($finale['finale']['awards'], 'reason'), true));

    // ---- Rehearsal: test mode, then Reset --------------------------------
    $testEvent = se_test_mode_set($pdo, se_it_event_row($pdo, $gId), true, 1);
    $quizRow = se_game_find($pdo, $gId, $quiz['id']);
    se_game_status_set($pdo, $testEvent, $quiz['id'], 'live', null, 1);
    $round = se_round_next_live($pdo, $testEvent, $quizRow, true, $live(), 1);
    se_round_arm_live($pdo, $testEvent, $round['id'], null, null, $live(), 1);
    se_it_open_now($pdo, $round['id']);
    $correct = (int) se_round_item($pdo, se_round_find($pdo, $gId, $round['id']))['payload']['answer_index'];
    se_game_answer($pdo, $testEvent, $regs[$players[1]], ['round_id' => $round['id'], 'choice_index' => $correct], $devices[$players[1]]);
    se_round_transition_live($pdo, $testEvent, $round['id'], 'revealed', $live(), 1);
    se_score_adjust_live($pdo, $testEvent, ['scope' => 'team', 'team_id' => $teamIds[2], 'points' => 50, 'reason' => 'Rehearsal bonus'], $live(), 1);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_score_events WHERE event_id = ? AND voided_at IS NULL AND (reason = 'TEST' OR reason LIKE 'TEST: %')");
    $stmt->execute([$gId]);
    ok('rehearsal points are marked TEST, awards included', (int) $stmt->fetchColumn() >= 3);

    se_reset_rehearsal($pdo, se_it_event_row($pdo, $gId), 1);
    $stmt->execute([$gId]);
    is_same('Reset rehearsal voids every TEST row', 0, (int) $stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_rounds WHERE event_id = ? AND is_test = 1");
    $stmt->execute([$gId]);
    is_same('…and deletes the rehearsal rounds', 0, (int) $stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_score_events WHERE event_id = ? AND voided_at IS NULL AND reason = 'Best team spirit'");
    $stmt->execute([$gId]);
    is_same('…while real awards stay', 1, (int) $stmt->fetchColumn());
}

// ==========================================================================

echo "\n==========================================================\n";
echo "  passed: {$GLOBALS['se_passed']}   failed: {$GLOBALS['se_failed']}\n";
echo "==========================================================\n";

exit($GLOBALS['se_failed'] === 0 ? 0 : 1);
