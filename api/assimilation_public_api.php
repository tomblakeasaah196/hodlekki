<?php
// /api/assimilation_public_api.php
// UNAUTHENTICATED endpoint for the volunteer flow at /assimilation.php.
// There is no session: every single action re-checks the phone number against
// the active Assimilation team, and then checks that the case it is about
// belongs to that volunteer. Same shape as api/reach_public_api.php.
//
// Privacy: this endpoint never returns a full surname, an address, a date of
// birth, an email, finance or anything else about a person. A volunteer sees
// exactly what they need to make the call — "First L.", phone, when the person
// was last in the house, their attendance trend, their departments, their
// spiritual status and the previous follow-up notes.

require_once '../includes/db.php';
require_once '../includes/assimilation_helpers.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function assim_pub_fail(string $message): void {
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// Matches on the last 9 digits so +234 / 0-prefixed spellings of one number
// both resolve, and only an active team member ever gets through.
function assim_find_volunteer(PDO $pdo, string $phone): ?array {
    $key = assim_phone_key($phone);
    if ($key === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name
          FROM users u
          JOIN assimilation_team t ON t.user_id = u.id AND t.is_active = 1
         WHERE u.phone LIKE ?
         LIMIT 1
    ");
    $stmt->execute(['%' . $key . '%']);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assim_require_volunteer(PDO $pdo): array {
    $volunteer = assim_find_volunteer($pdo, trim((string) ($_POST['volunteer_phone'] ?? '')));
    if (!$volunteer) {
        assim_pub_fail('Please sign in with the phone number your leaders have for you. Only the Assimilation team can open this page.');
    }
    return $volunteer;
}

// The one place that decides whether this volunteer may touch this case.
function assim_pub_case(PDO $pdo, int $case_id, int $volunteer_id, bool $write): array {
    $stmt = $pdo->prepare("
        SELECT c.*, u.first_name, u.last_name, u.phone
          FROM assimilation_cases c JOIN users u ON u.id = c.user_id
         WHERE c.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        assim_pub_fail('That person is no longer on your list.');
    }
    $mine = (int) $case['assigned_to'] === $volunteer_id;
    if ($write && !$mine) {
        assim_pub_fail('Claim this person first, then you can save the follow-up.');
    }
    $pool = $case['assigned_to'] === null && $case['closed_at'] === null && assim_allow_self_claim($pdo);
    if (!$mine && !$pool) {
        assim_pub_fail('Someone else is following up with this person.');
    }
    return $case;
}

// Every past reach-out to these people, across all their cases (not just
// the open one), so a volunteer knows whether anyone has called before.
function assim_prior_contacts(PDO $pdo, array $user_ids): array {
    $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
    if (!$user_ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($user_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT c.user_id, COUNT(f.id) AS total, MAX(f.id) AS last_id
          FROM assimilation_follow_ups f JOIN assimilation_cases c ON c.id = f.case_id
         WHERE c.user_id IN ($in)
         GROUP BY c.user_id
    ");
    $stmt->execute($user_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    $last_ids = array_map(fn($r) => (int) $r['last_id'], $rows);
    $in2 = implode(',', array_fill(0, count($last_ids), '?'));
    $lstmt = $pdo->prepare("
        SELECT f.id, f.outcome, f.created_at, u.first_name, u.last_name
          FROM assimilation_follow_ups f LEFT JOIN users u ON u.id = f.logged_by
         WHERE f.id IN ($in2)
    ");
    $lstmt->execute($last_ids);
    $last = [];
    foreach ($lstmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $last[(int) $l['id']] = $l;
    }
    $out = [];
    foreach ($rows as $r) {
        $l = $last[(int) $r['last_id']] ?? null;
        $out[(int) $r['user_id']] = [
            'total'   => (int) $r['total'],
            'when'    => $l ? assim_since_words(substr((string) $l['created_at'], 0, 10)) : null,
            'by'      => $l ? assim_short_name($l['first_name'], $l['last_name']) : null,
            'outcome' => $l ? str_replace('_', ' ', (string) $l['outcome']) : null,
        ];
    }
    return $out;
}

// Exactly the fields the call needs, and nothing else.
function assim_pub_card(PDO $pdo, array $row, array $sparklines, array $notes): array {
    $id = (int) $row['id'];
    return [
        'case_id'        => $id,
        'name'           => assim_short_name($row['first_name'], $row['last_name']),
        'first_name'     => trim((string) $row['first_name']),
        'phone'          => $row['phone'],
        'whatsapp'       => preg_replace('/^0/', '234', preg_replace('/[^0-9]/', '', (string) $row['phone']) ?? ''),
        'status'         => $row['status'],
        'status_words'   => assim_status_words((string) $row['status']),
        'departments'    => $row['departments'],
        'spiritual_status' => str_replace('_', ' ', (string) $row['spiritual_status']),
        'last_attended'  => $row['last_attended_on'],
        'since_words'    => assim_since_words($row['last_attended_on']),
        'next_touch'     => $row['next_touch_date'],
        'touches'        => (int) $row['touches'],
        'is_overdue'     => (int) $row['is_overdue'] === 1,
        'is_mine'        => $row['assigned_to'] !== null,
        'last_note'      => $notes[$id] ?? null,
        'prior'          => $row['prior'] ?? null,
        'trend'          => array_map(fn($m) => $m['count'], assim_months_frame($sparklines[(int) $row['user_id']] ?? [], 12)),
    ];
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        // check_volunteer — step 1. Only active team members pass.
        // ------------------------------------------------------------------
        case 'check_volunteer':
            $row = assim_find_volunteer($pdo, trim((string) ($_POST['phone'] ?? '')));
            echo json_encode($row
                ? ['exists' => true, 'name' => assim_short_name($row['first_name'], $row['last_name'])]
                : ['exists' => false]);
            break;

        // ------------------------------------------------------------------
        // my_people — the call list, most urgent first, plus the pool
        // ------------------------------------------------------------------
        case 'my_people':
            $volunteer = assim_require_volunteer($pdo);
            $vid       = (int) $volunteer['id'];
            $days      = assim_overdue_days($pdo);
            $self      = assim_allow_self_claim($pdo);

            $sql = "
                SELECT c.id, c.user_id, c.status, c.next_touch_date, c.last_attended_on,
                       c.assigned_to, c.first_contact_at,
                       " . assim_overdue_sql($days) . " AS is_overdue,
                       u.first_name, u.last_name, u.phone, u.spiritual_status,
                       (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                          FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                         WHERE ud.user_id = c.user_id AND ud.is_active = 1) AS departments,
                       (SELECT COUNT(*) FROM assimilation_follow_ups f WHERE f.case_id = c.id) AS touches
                  FROM assimilation_cases c JOIN users u ON u.id = c.user_id
                 WHERE c.closed_at IS NULL AND c.assigned_to = ?
                 ORDER BY " . assim_overdue_sql($days) . " DESC,
                          (c.next_touch_date IS NOT NULL AND c.next_touch_date <= CURDATE()) DESC,
                          (c.first_contact_at IS NULL) DESC,
                          c.next_touch_date IS NULL, c.next_touch_date ASC,
                          c.last_attended_on IS NULL, c.last_attended_on ASC, c.id ASC
                 LIMIT 100
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vid]);
            $mine = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pool = [];
            if ($self) {
                $poolStmt = $pdo->prepare("
                    SELECT c.id, c.user_id, c.status, c.next_touch_date, c.last_attended_on,
                           c.assigned_to, c.first_contact_at, 0 AS is_overdue,
                           u.first_name, u.last_name, u.phone, u.spiritual_status,
                           (SELECT GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ')
                              FROM user_departments ud JOIN departments d ON d.id = ud.department_id
                             WHERE ud.user_id = c.user_id AND ud.is_active = 1) AS departments,
                           (SELECT COUNT(*) FROM assimilation_follow_ups f WHERE f.case_id = c.id) AS touches
                      FROM assimilation_cases c JOIN users u ON u.id = c.user_id
                     WHERE c.closed_at IS NULL AND c.assigned_to IS NULL
                     ORDER BY c.last_attended_on IS NULL, c.last_attended_on ASC, c.id ASC
                     LIMIT 30
                ");
                $poolStmt->execute();
                $pool = $poolStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $rows       = array_merge($mine, $pool);
            $sparklines = assim_monthly_attendance($pdo, array_column($rows, 'user_id'));

            $notes = [];
            if ($rows) {
                $in = implode(',', array_fill(0, count($rows), '?'));
                $nStmt = $pdo->prepare("
                    SELECT f.case_id, COALESCE(NULLIF(f.notes_clean, ''), f.notes) AS note, f.created_at
                      FROM assimilation_follow_ups f
                     WHERE f.case_id IN ({$in})
                       AND f.id = (SELECT MAX(f2.id) FROM assimilation_follow_ups f2 WHERE f2.case_id = f.case_id)
                ");
                $nStmt->execute(array_column($rows, 'id'));
                foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    $notes[(int) $n['case_id']] = ['note' => $n['note'], 'when' => $n['created_at']];
                }
            }

            $done_today = $pdo->prepare("SELECT COUNT(*) FROM assimilation_follow_ups WHERE logged_by = ? AND DATE(created_at) = CURDATE()");
            $done_today->execute([$vid]);
            $brought_home = $pdo->prepare("SELECT COUNT(*) FROM assimilation_cases WHERE assigned_to = ? AND returned_home_at IS NOT NULL");
            $brought_home->execute([$vid]);

            $prior = assim_prior_contacts($pdo, array_merge(array_column($mine, 'user_id'), array_column($pool, 'user_id')));
            echo json_encode(['status' => 'success', 'data' => [
                'volunteer'    => ['name' => assim_short_name($volunteer['first_name'], $volunteer['last_name'])],
                'mine'         => array_map(fn($r) => assim_pub_card($pdo, $r + ['prior' => $prior[(int) $r['user_id']] ?? null], $sparklines, $notes), $mine),
                'pool'         => array_map(fn($r) => assim_pub_card($pdo, $r + ['prior' => $prior[(int) $r['user_id']] ?? null], $sparklines, $notes), $pool),
                'allow_claim'  => $self,
                'channels'     => ASSIM_CHANNELS,
                'outcomes'     => ASSIM_OUTCOMES,
                'done_today'   => (int) $done_today->fetchColumn(),
                'brought_home' => (int) $brought_home->fetchColumn(),
            ]]);
            break;

        // ------------------------------------------------------------------
        // claim_case — take someone from the unclaimed pool
        // ------------------------------------------------------------------
        case 'claim_case':
            $volunteer = assim_require_volunteer($pdo);
            if (!assim_allow_self_claim($pdo)) {
                assim_pub_fail('Claiming is turned off — your leaders will assign people to you.');
            }
            $case_id = (int) ($_POST['case_id'] ?? 0);
            $vid     = (int) $volunteer['id'];

            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE assimilation_cases SET assigned_to = ?, assigned_at = NOW(), assigned_by = ?
                 WHERE id = ? AND assigned_to IS NULL AND closed_at IS NULL
            ");
            $upd->execute([$vid, $vid, $case_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                assim_pub_fail('Someone else has already picked this person up.');
            }
            $pdo->prepare("
                INSERT INTO assimilation_case_assignments (case_id, from_user_id, to_user_id, assigned_by, action)
                VALUES (?, NULL, ?, ?, 'self_claim')
            ")->execute([$case_id, $vid, $vid]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'They are yours. Pray for them, then call.']);
            break;

        // ------------------------------------------------------------------
        // log_follow_up — only ever on the volunteer's own case
        // ------------------------------------------------------------------
        case 'log_follow_up':
            $volunteer = assim_require_volunteer($pdo);
            $vid       = (int) $volunteer['id'];
            $case_id   = (int) ($_POST['case_id'] ?? 0);
            $case      = assim_pub_case($pdo, $case_id, $vid, true);

            $channel = $_POST['channel'] ?? '';
            $outcome = $_POST['outcome'] ?? '';
            if (!in_array($channel, ASSIM_CHANNELS, true) || !in_array($outcome, ASSIM_OUTCOMES, true)) {
                assim_pub_fail('Choose how you reached out and how it went.');
            }
            if ($case['closed_at'] !== null) {
                assim_pub_fail('This follow-up is already closed. Ask your leader to reopen it.');
            }

            $next     = trim((string) ($_POST['next_touch_date'] ?? ''));
            $nextDate = DateTime::createFromFormat('Y-m-d', $next);
            $next     = ($nextDate && $nextDate->format('Y-m-d') === $next) ? $next : null;
            $notes    = trim((string) ($_POST['notes'] ?? ''));
            $clean    = trim((string) ($_POST['notes_clean'] ?? ''));
            $prayer   = trim((string) ($_POST['prayer_points'] ?? ''));

            // A double tap on a phone must not write the same call twice.
            $dup = $pdo->prepare("
                SELECT id FROM assimilation_follow_ups
                 WHERE case_id = ? AND logged_by = ? AND channel = ? AND outcome = ?
                   AND created_at > NOW() - INTERVAL 60 SECOND LIMIT 1
            ");
            $dup->execute([$case_id, $vid, $channel, $outcome]);
            if ($dup->fetchColumn()) {
                echo json_encode(['status' => 'success', 'message' => 'Already saved.', 'data' => ['duplicate' => true]]);
                break;
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO assimilation_follow_ups
                    (case_id, logged_by, channel, outcome, notes, notes_clean, prayer_points, next_touch_date, created_by_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $case_id, $vid, $channel, $outcome,
                $notes !== '' ? mb_substr($notes, 0, 4000) : null,
                $clean !== '' ? mb_substr($clean, 0, 4000) : null,
                $prayer !== '' ? mb_substr($prayer, 0, 2000) : null,
                $next, mb_substr(trim((string) ($_POST['volunteer_phone'] ?? '')), 0, 40),
            ]);
            $pdo->prepare("UPDATE assimilation_cases SET next_touch_date = ? WHERE id = ?")->execute([$next, $case_id]);
            $status = assim_recompute_case_status($pdo, $case_id);
            $pdo->commit();

            $person = assim_short_name($case['first_name'], $case['last_name']);
            try {
                $marked = assim_detect_returned_home($pdo);
                assim_announce_returned_home($pdo, $marked);
                $came_home = (bool) array_filter($marked, fn($m) => (int) $m['id'] === $case_id);
                if ($came_home) {
                    $status = assim_recompute_case_status($pdo, $case_id);
                }
            } catch (PDOException $e) {
                error_log('Assimilation public returned-home sweep: ' . $e->getMessage());
                $came_home = false;
            }

            if (in_array($status, ASSIM_CLOSED_STATUSES, true) && !$came_home) {
                assim_notify($pdo, assim_manager_ids($pdo), 'Assimilation follow-up closed',
                    "{$person} was closed as " . strtolower(assim_status_words($status))
                    . ' by ' . assim_short_name($volunteer['first_name'], $volunteer['last_name']) . '.');
            }

            echo json_encode(['status' => 'success', 'message' => 'Saved. Thank you for going after them.', 'data' => [
                'case_status'   => $status,
                'status_words'  => assim_status_words($status),
                'closed'        => in_array($status, ASSIM_CLOSED_STATUSES, true),
                'returned_home' => $came_home,
            ]]);
            break;

        // ------------------------------------------------------------------
        // clean_notes — Gemini tidy-up. The volunteer reviews it before it
        // is saved; both the raw and the clean version are stored.
        // ------------------------------------------------------------------
        case 'clean_notes':
            // Each call spends credit, so only a signed-in volunteer, short input.
            assim_require_volunteer($pdo);
            $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000);
            if ($notes === '') {
                assim_pub_fail('Write or dictate the notes first.');
            }
            $clean = assim_clean_notes($notes);
            if ($clean === null) {
                assim_pub_fail('The AI helper is unavailable right now — save your notes as they are.');
            }
            echo json_encode(['status' => 'success', 'data' => ['notes_clean' => $clean]]);
            break;

        // ------------------------------------------------------------------
        // opening_line — a gentle way to start the call
        // ------------------------------------------------------------------
        case 'opening_line':
            $volunteer = assim_require_volunteer($pdo);
            $case_id   = (int) ($_POST['case_id'] ?? 0);
            $case      = assim_pub_case($pdo, $case_id, (int) $volunteer['id'], false);
            $line      = assim_opening_line($pdo, $case_id, trim($case['first_name'] . ' ' . $case['last_name']),
                $case['last_attended_on'], (string) $case['status']);
            if ($line === null) {
                assim_pub_fail('The AI helper is unavailable right now. Ask after them warmly and listen first.');
            }
            echo json_encode(['status' => 'success', 'data' => ['line' => $line]]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Assimilation Public API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Assimilation Public API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
