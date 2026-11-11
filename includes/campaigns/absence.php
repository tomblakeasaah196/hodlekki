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
// A streak is identified by its first missed Sunday (missed_service_dates[0]),
// so each step fires once per absence and again if the person comes back and
// later drifts away. If someone passed several steps for the same audience at
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

    $out = [];
    foreach ($awol['awol_checks'] as $check) {
        $streak = (int) $check['consecutive_missed'];
        $streakStart = (string) ($check['missed_service_dates'][0] ?? '');
        if ($streakStart === '') {
            continue;
        }
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
