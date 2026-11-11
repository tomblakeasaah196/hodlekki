<?php
// /includes/campaigns/absence.php
//
// Absence ladders for Assimilation and Charis (owner's decision 5). Default:
// 2 missed Sundays → a warm "we miss you" email to the person; 4 → alert the
// team; 8 → alert their pastor. Steps, thresholds, texts and the statuses each
// module covers live in the rule.
//
// The streak is NOT computed here. It comes from charis_compute_awol_list(),
// the Charis AWOL engine itself (AGENTS.md, "Charis AWOL Monitoring
// contract"): a consecutive-absence streak anchored on the last day present,
// Sunday absence counting and midweek presence clearing it, services nobody
// attended skipped, new members anchored on registration, awol_exempt and the
// departed statuses excluded. This file only calls it with:
//   threshold = the rule's lowest step, statuses = the rule's audience,
//   service_rules = Charis's own (so both modules count Sundays the same way).
//
// A streak is identified by the last day the person was present at a service
// that clears it (over all their history, through assim_attendance_union_sql()),
// so each step fires once per absence and again if the person comes back and
// later drifts away. (Not the first missed date: the engine looks back a fixed
// number of weeks, so for someone away longer than that the first missed date
// it reports moves forward every week.) If someone passed several steps for the same audience at
// once (the rule was off, or the cron was), only the highest fires; the lower
// ones are recorded as superseded.

require_once __DIR__ . '/../charis_helpers.php';

/** @return list<array> */
function absence_candidates(PDO $pdo, array $rule, array $occasion): array
{
    $cfg = $rule['config'];
    $steps = array_values(array_filter($cfg['steps'] ?? [], static fn (array $s): bool => in_array($s['key'], $occasion['steps'], true)));
    if ($steps === []) {
        return [];
    }
    $thresholds = array_column($steps, 'threshold');
    $charis = charis_get_awol_config($pdo) ?? [];
    $engineConfig = [
        'consecutive_threshold' => min($thresholds),
        'spiritual_statuses'    => (array) ($cfg['audience']['statuses'] ?? []),
        'service_rules'         => $charis['service_rules'] ?? null,
        'archive_weeks'         => max((int) ($charis['archive_weeks'] ?? CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS), max($thresholds) + 2),
    ];
    $awol = charis_compute_awol_list($pdo, $engineConfig, $occasion['at']);
    $lastReset = absence_last_reset_presence($pdo, $engineConfig['service_rules'], $awol['awol_checks'], $occasion['at']);

    $out = [];
    foreach ($awol['awol_checks'] as $check) {
        $streak = (int) $check['consecutive_missed'];
        if ($streak <= 0) {
            continue;
        }
        // The streak's identity: the last day they were present at a service
        // that clears the counter, over ALL their history. Not the first missed
        // date: for someone away longer than the engine's look-back window that
        // date slides forward every week and every step would fire again.
        $uid = (int) $check['target_user_id'];
        $streakStart = isset($lastReset[$uid]) ? 'after:' . $lastReset[$uid] : 'never';
        $user = [
            'id'                => (int) $check['target_user_id'],
            'first_name'        => $check['first_name'],
            'last_name'         => $check['last_name'],
            'phone'             => $check['phone'],
            'spiritual_status'  => $check['spiritual_status'],
            'attendance_status' => $check['attendance_status'],
        ];
        $vars = [
            'streak'        => (string) $streak,
            'weeks_away'    => $check['weeks_away'] !== null ? (string) $check['weeks_away'] : (string) $streak,
            'last_attended' => $check['last_attended_date'] !== null
                ? campaigns_dt((string) $check['last_attended_date'])->format('l j F Y')
                : 'not yet recorded',
        ];
        // Highest reached step per audience; lower ones of that audience are superseded.
        $byTarget = [];
        foreach ($steps as $step) {
            if ($step['threshold'] <= $streak) {
                $byTarget[$step['target']][] = $step;
            }
        }
        foreach ($byTarget as $reached) {
            usort($reached, static fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);
            $top = array_pop($reached);
            $out[] = [
                'user'       => $user,
                'step'       => $top,
                'dedupe'     => $streakStart,
                'variant'    => 0,
                'date_bound' => false,
                'vars'       => $vars,
                'superseded' => array_column($reached, 'key'),
            ];
        }
    }
    return $out;
}

/**
 * Each flagged person's last day present at a service whose rule clears the
 * streak (Charis's own rules), up to $at, through the shared attendance helper.
 *
 * @return array<int,string> user id => 'Y-m-d'
 */
function absence_last_reset_presence(PDO $pdo, $serviceRules, array $checks, DateTimeImmutable $at): array
{
    $reset = charis_awol_reset_services(charis_awol_service_rules(['service_rules' => $serviceRules]));
    $ids = array_values(array_unique(array_map(static fn (array $c): int => (int) $c['target_user_id'], $checks)));
    $out = [];
    if ($reset === [] || $ids === []) {
        return $out;
    }
    foreach (array_chunk($ids, 500) as $chunk) {
        $union = assim_attendance_union_sql($reset, $chunk);
        $stmt = $pdo->prepare("SELECT x.user_id, MAX(x.attended_on) AS last_day FROM {$union} x WHERE x.attended_on <= ? GROUP BY x.user_id");
        $stmt->execute([$at->format('Y-m-d')]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['user_id']] = substr((string) $row['last_day'], 0, 10);
        }
    }
    return $out;
}
