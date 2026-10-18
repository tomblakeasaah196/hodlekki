<?php
// /includes/status_ladder.php
// The Sunday ladder: 1st Timer -> 2nd Timer -> 3rd Timer -> Visitor, driven by
// attendance, plus the status history that lets reports and Embrace tell what
// a person's status was at a given time.
//
// RULES (agreed with the church, 2026-10-18)
//   * One "time" is one calendar day on which the person was present at a
//     SUNDAY service. Midweek and special events never move anyone. Counting
//     goes through assim_attendance_union_sql(), so a QR check-in and a roster
//     clock-in on the same day are one time, and so are two services on the
//     same Sunday.
//   * 1 Sunday = 1st Timer, 2 = 2nd Timer, 3 = 3rd Timer, 4 or more = Visitor.
//   * Forward only. Only people who are currently 1st, 2nd or 3rd Timer are
//     ever touched; Member, Worker, Pastor, Non-Member and Visitor never are,
//     nor is anyone who has left (Relocated / Attends Another Church / the
//     awol_exempt flag). A status staff set by hand is never lowered by the
//     sweep: it only moves up.
//   * The single exception to "never backward" is a clock-in that is withdrawn
//     (events_api clock_out): if the ladder itself promoted the person and
//     nobody has touched their status since, the promotion is taken back to
//     what the remaining Sundays justify (never below where the ladder found
//     them). A status someone changed by hand is never rolled back.
//   * The ladder cannot run before db/migrate.php has created
//     user_status_history: every move must be recorded (Embrace reads the
//     record), so without the table nothing moves. ladder_ready() says which.
//
// Every move the ladder makes is written to user_status_history with source
// 'attendance_ladder' (or 'attendance_ladder_undo'). Every OTHER writer of
// users.spiritual_status records itself too, via ladder_note_change(), so
// ladder_status_at_sql() can answer "what was their status on the day".

require_once __DIR__ . '/assimilation_helpers.php';

const LADDER_STEPS      = ['1st_Timer' => 1, '2nd_Timer' => 2, '3rd_Timer' => 3];
const LADDER_TOP_STATUS = 'Visitor';
const LADDER_TOP_RANK   = 4;
const LADDER_SERVICES   = ['Sunday_Service'];
const LADDER_SOURCE      = 'attendance_ladder';
const LADDER_SOURCE_UNDO = 'attendance_ladder_undo';
const LADDER_DEPARTED   = ['Relocated', 'Attends_Another_Church'];

// ==========================================================================
// SCHEMA PROBES — the tree reaches production before db/migrate.php runs
// ==========================================================================

/**
 * Does this statement run? Only successes are remembered, so a table created
 * while a long-lived process (a cron, a test) is running is noticed.
 */
function ladder_probe(PDO $pdo, string $sql): bool
{
    static $known = null;
    $known ??= new WeakMap();
    $seen = $known[$pdo] ?? [];
    if (!empty($seen[$sql])) return true;
    try {
        $pdo->query($sql)->closeCursor();
    } catch (Throwable $e) {
        return false;
    }
    $seen[$sql] = true;
    $known[$pdo] = $seen;
    return true;
}

/** True once user_status_history exists. Until then the ladder does nothing. */
function ladder_ready(PDO $pdo): bool
{
    return ladder_probe($pdo, 'SELECT user_id FROM user_status_history LIMIT 0');
}

function ladder_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
}

// ==========================================================================
// THE LADDER
// ==========================================================================

/** 1..3 for the timers, 4 for Visitor, 0 for anything the ladder ignores. */
function ladder_rank(string $status): int
{
    if (isset(LADDER_STEPS[$status])) return LADDER_STEPS[$status];
    return $status === LADDER_TOP_STATUS ? LADDER_TOP_RANK : 0;
}

function ladder_status_for_rank(int $rank): string
{
    if ($rank >= LADDER_TOP_RANK) return LADDER_TOP_STATUS;
    return array_search(max(1, $rank), LADDER_STEPS, true);
}

/** The status a person's number of Sundays earns. Zero Sundays is still a 1st Timer. */
function ladder_target_for_days(int $days): string
{
    return ladder_status_for_rank($days);
}

/** Has this person left, as opposed to being hard to reach? */
function ladder_is_departed(array $user): bool
{
    $attendance = str_replace(' ', '_', trim((string) ($user['attendance_status'] ?? '')));
    return in_array($attendance, LADDER_DEPARTED, true) || (int) ($user['awol_exempt'] ?? 0) === 1;
}

/**
 * Sunday days attended for each of the given people. People never present on
 * a Sunday are simply absent from the result.
 *
 * @return array<int,int> user id => Sundays
 */
function ladder_sunday_counts(PDO $pdo, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $counts = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $union = assim_attendance_union_sql(LADDER_SERVICES, $chunk);
        $rows = $pdo->query("SELECT x.user_id, COUNT(*) AS days FROM {$union} x GROUP BY x.user_id")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $counts[(int) $row['user_id']] = (int) $row['days'];
        }
    }
    return $counts;
}

function ladder_user_select(PDO $pdo): string
{
    $exempt = ladder_probe($pdo, 'SELECT awol_exempt FROM users LIMIT 0') ? 'awol_exempt' : '0 AS awol_exempt';
    return "SELECT id, first_name, last_name, spiritual_status, attendance_status, {$exempt} FROM users";
}

/**
 * Change one person's status and record it, together or not at all. The
 * UPDATE only lands if the status is still $from, so a staff edit made a
 * moment earlier is never overwritten.
 */
function ladder_apply(PDO $pdo, int $userId, string $from, string $to, string $source, ?int $by = null, ?string $at = null): bool
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE users SET spiritual_status = ? WHERE id = ? AND spiritual_status = ?");
        $st->execute([$to, $userId, $from]);
        if ($st->rowCount() !== 1) {
            if ($own) $pdo->rollBack();
            return false;
        }
        ladder_insert_history($pdo, $userId, $from, $to, $source, $by, $at);
        if ($own) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Status ladder write failed for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Recompute one person from their attendance and move them UP if they have
 * earned it. Call after any Sunday clock-in. Never throws: a failure here must
 * not stop someone being clocked in.
 *
 * @return array{from:string,to:string,days:int}|null what changed, null if nothing did
 */
function ladder_advance_user(PDO $pdo, int $userId, ?int $by = null, ?string $at = null): ?array
{
    if ($userId <= 0 || !ladder_ready($pdo)) return null;
    try {
        $st = $pdo->prepare(ladder_user_select($pdo) . ' WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;

        $from = (string) $user['spiritual_status'];
        if (!isset(LADDER_STEPS[$from]) || ladder_is_departed($user)) return null;

        $days = ladder_sunday_counts($pdo, [$userId])[$userId] ?? 0;
        $to = ladder_target_for_days($days);
        if (ladder_rank($to) <= LADDER_STEPS[$from]) return null;

        return ladder_apply($pdo, $userId, $from, $to, LADDER_SOURCE, $by, $at)
            ? ['from' => $from, 'to' => $to, 'days' => $days]
            : null;
    } catch (Throwable $e) {
        error_log('Status ladder advance failed for user ' . $userId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * A clock-in was withdrawn: take back a promotion the ladder made if the
 * remaining Sundays no longer justify it.
 *
 * Only a status the ladder itself set is touched. The history must end in one
 * or more ladder rows whose last destination is the person's current status;
 * if anyone changed it by hand since (or it was never recorded), nothing
 * happens. The person is never lowered below the status the ladder found them
 * at, so a status staff set by hand is never undercut.
 *
 * @return array{from:string,to:string,days:int}|null
 */
function ladder_rollback_user(PDO $pdo, int $userId, ?int $by = null, ?string $at = null): ?array
{
    if ($userId <= 0 || !ladder_ready($pdo)) return null;
    try {
        $st = $pdo->prepare(ladder_user_select($pdo) . ' WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;

        $current = (string) $user['spiritual_status'];
        $currentRank = ladder_rank($current);
        if ($currentRank === 0) return null;

        // Newest first by id: the order things were written, which a clock
        // adjustment can never reshuffle.
        $st = $pdo->prepare("SELECT from_status, to_status, source FROM user_status_history
                              WHERE user_id = ? ORDER BY id DESC LIMIT 25");
        $st->execute([$userId]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$history || $history[0]['to_status'] !== $current) return null;

        // Walk back through the unbroken run of ladder moves; the oldest
        // promotion in it is where the ladder found this person.
        $floor = null;
        foreach ($history as $row) {
            if ($row['source'] === LADDER_SOURCE) {
                $floor = LADDER_STEPS[$row['from_status']] ?? 1;
            } elseif ($row['source'] !== LADDER_SOURCE_UNDO) {
                break;
            }
        }
        if ($floor === null) return null;

        $days = ladder_sunday_counts($pdo, [$userId])[$userId] ?? 0;
        $newRank = max(ladder_rank(ladder_target_for_days($days)), $floor);
        if ($newRank >= $currentRank) return null;

        $to = ladder_status_for_rank($newRank);
        return ladder_apply($pdo, $userId, $current, $to, LADDER_SOURCE_UNDO, $by, $at)
            ? ['from' => $current, 'to' => $to, 'days' => $days]
            : null;
    } catch (Throwable $e) {
        error_log('Status ladder rollback failed for user ' . $userId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * The catch-up pass: bring every 1st/2nd/3rd Timer to the step their Sundays
 * earn. Idempotent and forward only, so it is safe to run on every deploy
 * (db/backfill_status_ladder.php). With $apply = false nothing is written.
 *
 * @return array{ready:bool,scanned:int,departed:int,unchanged:int,failed:int,moved:array<int,array>}
 */
function ladder_sweep(PDO $pdo, bool $apply = true, ?string $at = null): array
{
    $summary = ['ready' => ladder_ready($pdo), 'scanned' => 0, 'departed' => 0, 'unchanged' => 0, 'failed' => 0, 'moved' => []];
    if (!$summary['ready']) return $summary;

    $rows = $pdo->query(ladder_user_select($pdo) . " WHERE spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer') ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
    $counts = ladder_sunday_counts($pdo, array_column($rows, 'id'));

    foreach ($rows as $user) {
        $summary['scanned']++;
        if (ladder_is_departed($user)) {
            $summary['departed']++;
            continue;
        }
        $id = (int) $user['id'];
        $from = (string) $user['spiritual_status'];
        $days = $counts[$id] ?? 0;
        $to = ladder_target_for_days($days);
        if (ladder_rank($to) <= LADDER_STEPS[$from]) {
            $summary['unchanged']++;
            continue;
        }
        if ($apply && !ladder_apply($pdo, $id, $from, $to, LADDER_SOURCE, null, $at)) {
            $summary['failed']++;
            continue;
        }
        $summary['moved'][] = [
            'id'   => $id,
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'from' => $from,
            'to'   => $to,
            'days' => $days,
        ];
    }
    return $summary;
}

// ==========================================================================
// STATUS HISTORY
// ==========================================================================

/** Throws on failure — for callers that must be atomic with another write. */
function ladder_insert_history(PDO $pdo, int $userId, string $from, string $to, string $source, ?int $by = null, ?string $at = null): void
{
    $pdo->prepare("INSERT INTO user_status_history (user_id, from_status, to_status, source, changed_by, changed_at)
                   VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $from, $to, $source, $by ?: null, $at ?? ladder_now()]);
}

/**
 * Record a status change made by a screen. Does nothing when the status did
 * not change, when the table does not exist yet, and never throws: a failure
 * here must not undo or block the edit that was just saved.
 */
function ladder_log_change(PDO $pdo, int $userId, string $from, string $to, string $source, ?int $by = null, ?string $at = null): bool
{
    if ($userId <= 0 || $from === $to || !ladder_ready($pdo)) return false;
    try {
        ladder_insert_history($pdo, $userId, $from, $to, $source, $by, $at);
        return true;
    } catch (Throwable $e) {
        error_log('Status history write failed for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * The status to hand to ladder_note_change() before an edit. Null when there
 * is nothing to record (no table yet, or no such person).
 */
function ladder_status_of(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0 || !ladder_ready($pdo)) return null;
    try {
        $st = $pdo->prepare("SELECT spiritual_status FROM users WHERE id = ?");
        $st->execute([$userId]);
        $value = $st->fetchColumn();
        return $value === false ? null : (string) $value;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Call after a screen's UPDATE users SET spiritual_status ...:
 *     $before = ladder_status_of($pdo, $id);
 *     ... the UPDATE ...
 *     ladder_note_change($pdo, $id, $before, 'events_profile', $actor);
 * It reads the status back, so defaults applied by the UPDATE are recorded
 * as written rather than as posted.
 */
function ladder_note_change(PDO $pdo, int $userId, ?string $before, string $source, ?int $by = null): void
{
    if ($before === null) return;
    $now = ladder_status_of($pdo, $userId);
    if ($now === null) return;
    ladder_log_change($pdo, $userId, $before, $now, $source, $by);
}

/** Remove a deleted person's history, so deleting them leaves no trace. */
function ladder_forget_user(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !ladder_ready($pdo)) return;
    try {
        $pdo->prepare("DELETE FROM user_status_history WHERE user_id = ?")->execute([$userId]);
    } catch (Throwable $e) {
        error_log('Status history cleanup failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

/**
 * SQL for a person's spiritual_status AS AT THE END OF a given day: the
 * from_status of the first change made after that day, or their current status
 * when there has been none. Use it wherever a report describes a past event:
 *
 *     $status = ladder_status_at_sql($pdo, 'u', 'ev.event_date');
 *     "SELECT {$status} AS spiritual_status FROM events ev ... JOIN users u ..."
 *
 * A change on the day itself counts as having happened, so someone whose 2nd
 * Sunday it was is a 2nd Timer on that Sunday's report. Before the history
 * table exists, and for events older than it, this is just the current status
 * (what reports always did). Both arguments are column references written in
 * code, never user input.
 */
function ladder_status_at_sql(PDO $pdo, string $userAlias, string $dateColumn): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $userAlias) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $dateColumn)) {
        throw new InvalidArgumentException('ladder_status_at_sql takes column references only.');
    }
    $current = "{$userAlias}.spiritual_status";
    if (!ladder_ready($pdo)) return $current;
    return "COALESCE((SELECT h.from_status FROM user_status_history h
                       WHERE h.user_id = {$userAlias}.id AND DATE(h.changed_at) > DATE({$dateColumn})
                       ORDER BY h.changed_at ASC, h.id ASC LIMIT 1), {$current})";
}
