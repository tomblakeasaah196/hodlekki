<?php
// /includes/campaigns/journey.php
//
// Embrace's first-timer journey (owner's decisions 3 and 4, 11 Nov 2026): a
// welcome every Monday and reminders before the Thursday midweek service and
// before Sunday, until the person reaches the stop status on the Sunday ladder
// (default 3rd Timer) or the journey's weeks run out (default 6), whichever
// comes first. Days, times, texts and durations live in the rule.
//
// WHO: the Embrace pipeline itself (embrace_pipeline_sql(), never a hand-made
// status list) minus anyone at or past the stop status, departed, or exempt.
// WHEN THEY STARTED: the earlier of their first Sunday in church (through
// assim_attendance_union_sql(), the one definition of "was in church") and the
// day their record was made (Connect / Embrace).
//
// The welcome is a weekly SERIES: each Monday a person is due, they get the
// next email they have not had yet (1st, 2nd, …), so someone who joined on a
// Tuesday, or who had no email on their first Monday, still starts at the
// beginning. Reminders are bound to their date ("see you tomorrow"): one per
// occasion, never deferred.

require_once __DIR__ . '/../status_ladder.php';
require_once __DIR__ . '/../embrace_helpers.php';
require_once __DIR__ . '/../assimilation_helpers.php';

/** @return list<array> */
function journey_candidates(PDO $pdo, array $rule, array $occasion): array
{
    $cfg = $rule['config'];
    $weeks = (int) ($cfg['audience']['weeks'] ?? 6);
    $stopRank = ladder_rank((string) ($cfg['audience']['stop_status'] ?? '3rd_Timer'));
    $day = $occasion['at']->setTimezone(new DateTimeZone(CAMPAIGNS_TZ))->format('Y-m-d');
    $dayAt = campaigns_dt($day);

    $people = journey_people($pdo);
    if ($people === []) {
        return [];
    }
    $starts = journey_start_dates($pdo, $people);

    $steps = [];
    foreach ($occasion['steps'] as $key) {
        $step = automation_step($rule, $key);
        if ($step !== null) {
            $steps[] = $step;
        }
    }
    $delivered = [];
    foreach ($steps as $step) {
        if (isset($step['series'])) {
            $delivered[$step['key']] = journey_delivered_counts($pdo, (int) $rule['id'], $step['key']);
        }
    }

    $out = [];
    foreach ($people as $person) {
        $uid = (int) $person['id'];
        $rank = ladder_rank((string) $person['spiritual_status']);
        if ($stopRank > 0 && $rank >= $stopRank) {
            continue;
        }
        $start = $starts[$uid] ?? null;
        if ($start === null || $start > $day) {
            continue; // joined after this occasion
        }
        $days = (int) campaigns_dt($start)->diff($dayAt)->days;
        if ($days >= $weeks * 7) {
            continue; // the journey is over
        }
        $week = intdiv($days, 7) + 1;
        foreach ($steps as $step) {
            $variant = 0;
            if (isset($step['series'])) {
                $variant = $delivered[$step['key']][$uid] ?? 0;
                if ($variant >= $weeks) {
                    continue;
                }
            }
            $out[] = [
                'user'       => $person,
                'step'       => $step,
                'dedupe'     => $day,
                'variant'    => $variant,
                'date_bound' => !isset($step['series']),
                'vars'       => ['week_number' => (string) $week],
                'superseded' => [],
            ];
        }
    }
    return $out;
}

/** People in the Embrace pipeline who have not left. */
function journey_people(PDO $pdo): array
{
    $exempt = journey_has_column($pdo, 'users', 'awol_exempt') ? ' AND COALESCE(u.awol_exempt, 0) = 0' : '';
    $departed = implode(',', array_fill(0, count(AUTOMATION_DEPARTED), '?'));
    $stmt = $pdo->prepare('SELECT u.id, u.first_name, u.last_name, u.phone, u.spiritual_status, u.attendance_status, u.created_at
                             FROM users u
                            WHERE ' . embrace_pipeline_sql($pdo, 'u') . "
                              AND COALESCE(u.attendance_status, '') NOT IN ({$departed}){$exempt}
                            ORDER BY u.id");
    $stmt->execute(AUTOMATION_DEPARTED);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Each person's journey start: the earlier of their first Sunday present and
 * the day their record was created ('Y-m-d').
 *
 * @return array<int,string>
 */
function journey_start_dates(PDO $pdo, array $people): array
{
    $starts = [];
    foreach ($people as $p) {
        $created = substr((string) ($p['created_at'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $created)) {
            $starts[(int) $p['id']] = $created;
        }
    }
    $ids = array_map(static fn (array $p): int => (int) $p['id'], $people);
    foreach (array_chunk($ids, 500) as $chunk) {
        $union = assim_attendance_union_sql(['Sunday_Service'], $chunk);
        foreach ($pdo->query("SELECT x.user_id, MIN(x.attended_on) AS first_day FROM {$union} x GROUP BY x.user_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) $row['user_id'];
            $first = substr((string) $row['first_day'], 0, 10);
            if (!isset($starts[$uid]) || $first < $starts[$uid]) {
                $starts[$uid] = $first;
            }
        }
    }
    return $starts;
}

/**
 * How many emails/texts of a series each person has actually received (the
 * next one to send is that index).
 *
 * @return array<int,int>
 */
function journey_delivered_counts(PDO $pdo, int $ruleId, string $stepKey): array
{
    $stmt = $pdo->prepare("SELECT user_id, COUNT(*) AS n FROM automation_sends
                            WHERE rule_id = ? AND step_key = ? AND outcome = 'queued'
                            GROUP BY user_id");
    $stmt->execute([$ruleId, $stepKey]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['user_id']] = (int) $row['n'];
    }
    return $out;
}

function journey_has_column(PDO $pdo, string $table, string $column): bool
{
    $memo = campaigns_memo($pdo);
    $key = "col:{$table}.{$column}";
    if (!isset($memo[$key])) {
        try {
            $pdo->query("SELECT {$column} FROM {$table} LIMIT 0");
            $memo[$key] = true;
        } catch (Throwable $e) {
            $memo[$key] = false;
        }
    }
    return $memo[$key];
}
