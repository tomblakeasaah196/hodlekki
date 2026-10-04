<?php
// /includes/special_events/teams.php
//
// Teams: configuration, the balancing algorithm, crew moves, names and
// captains (guide §10.6).
//
// The algorithm itself (se_team_choose) is a pure function of the current
// counts, so tests/special_events/teams_test.php can run 100 000 random
// arrival sequences through it without a database and prove invariants
// I1 (sizes within ±1), I2 (gender spread) and I3 (determinism).
//
// Everything that WRITES an assignment happens inside se_lock_event(), which
// is what makes I4 true: two phones checking in at the same instant cannot
// both read the same counts.

// --------------------------------------------------------------------------
// Reading
// --------------------------------------------------------------------------

/** Are the teams tables migrated yet? (§9.4) */
function se_teams_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_teams');
}

/** The event's teams, ordered as the crew arranged them. */
function se_teams(PDO $pdo, int $eventId): array
{
    if (!se_teams_ready($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM se_teams WHERE event_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$eventId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('SE teams/list: ' . $e->getMessage());
        return [];
    }
}

/** One team of this event, or null. */
function se_team_find(PDO $pdo, int $eventId, int $teamId): ?array
{
    if (!se_teams_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM se_teams WHERE event_id = ? AND id = ?");
    $stmt->execute([$eventId, $teamId]);

    return $stmt->fetch() ?: null;
}

/**
 * Live counts per team (§10.6.1): size, both genders and the member/guest
 * mix, over confirmed registrations only.
 *
 * Every team the event has is present in the result, including empty ones —
 * the algorithm indexes by team id and must never hit a missing key.
 */
function se_team_counts(PDO $pdo, int $eventId): array
{
    $counts = [];
    foreach (se_teams($pdo, $eventId) as $team) {
        $counts[(int) $team['id']] = [
            'n' => 0, 'n_Male' => 0, 'n_Female' => 0, 'n_member' => 0, 'n_guest' => 0,
        ];
    }

    if (!$counts) {
        return $counts;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT team_id,
                    COUNT(*)                                            AS n,
                    SUM(CASE WHEN gender = 'Male'   THEN 1 ELSE 0 END)  AS n_male,
                    SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END)  AS n_female,
                    SUM(CASE WHEN is_member = 1     THEN 1 ELSE 0 END)  AS n_member
             FROM se_registrations
             WHERE event_id = ? AND team_id IS NOT NULL AND status = 'confirmed'
             GROUP BY team_id"
        );
        $stmt->execute([$eventId]);

        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['team_id'];
            if (!isset($counts[$id])) {
                continue;
            }
            $counts[$id] = [
                'n'        => (int) $row['n'],
                'n_Male'   => (int) $row['n_male'],
                'n_Female' => (int) $row['n_female'],
                'n_member' => (int) $row['n_member'],
                'n_guest'  => (int) $row['n'] - (int) $row['n_member'],
            ];
        }
    } catch (Throwable $e) {
        error_log('SE teams/counts: ' . $e->getMessage());
    }

    return $counts;
}

// --------------------------------------------------------------------------
// The algorithm (§10.6.2) — pure, so it can be property-tested
// --------------------------------------------------------------------------

/**
 * Every item whose `$fn` value equals the minimum, in the original order.
 *
 * Order preservation is what makes rule 4 (the round-robin pointer) stable
 * and the whole algorithm deterministic.
 */
function se_argmin(array $items, callable $fn): array
{
    if (!$items) {
        return [];
    }

    $best = null;
    $out  = [];

    foreach ($items as $item) {
        $value = $fn($item);
        if ($best === null || $value < $best) {
            $best = $value;
            $out  = [$item];
        } elseif ($value === $best) {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * Which team the next arrival joins.
 *
 * Pure: no database, no clock, no randomness. Given the same teams, counts,
 * person and pointer it always returns the same answer (I3).
 *
 * @param array  $teams   ordered rows, each with at least `id`
 * @param array  $counts  team id => ['n','n_Male','n_Female','n_member','n_guest']
 * @param string $gender  'Male' | 'Female'
 * @param bool   $isMember
 * @param int    $pointer the event's team_rr_pointer
 * @return array{team: array, index: int, next_pointer: int}
 */
function se_team_choose(array $teams, array $counts, string $gender, bool $isMember, int $pointer): array
{
    $teams = array_values($teams);
    $k     = count($teams);
    if ($k === 0) {
        throw new SeRuleException('TEAMS_NOT_SET', 'This event has no teams yet.');
    }

    $genderKey = $gender === 'Female' ? 'n_Female' : 'n_Male';
    $mixKey    = $isMember ? 'n_member' : 'n_guest';
    $of        = static fn(array $t, string $key): int => (int) ($counts[(int) $t['id']][$key] ?? 0);

    // Rule 1 — size: only the smallest teams are candidates. This alone is
    // what keeps max(n) − min(n) ≤ 1 after every assignment (I1).
    $cand = se_argmin($teams, static fn(array $t): int => $of($t, 'n'));
    // Rule 2 — gender: fewest people of the arriving person's gender.
    $cand = se_argmin($cand, static fn(array $t): int => $of($t, $genderKey));
    // Rule 3 — mix: fewest of the same membership type, so guests spread out.
    $cand = se_argmin($cand, static fn(array $t): int => $of($t, $mixKey));

    $candIds = array_map(static fn(array $t): int => (int) $t['id'], $cand);

    // Rule 4 — tie-break: the first candidate at or after the pointer.
    $ptr     = (($pointer % $k) + $k) % $k;
    $chosen  = null;
    $index   = 0;
    for ($i = 0; $i < $k; $i++) {
        $idx = ($ptr + $i) % $k;
        if (in_array((int) $teams[$idx]['id'], $candIds, true)) {
            $chosen = $teams[$idx];
            $index  = $idx;
            break;
        }
    }

    // Unreachable while $cand is non-empty, but a null here would be a very
    // confusing crash at the door.
    if ($chosen === null) {
        $chosen = $teams[$ptr];
        $index  = $ptr;
    }

    return ['team' => $chosen, 'index' => $index, 'next_pointer' => ($index + 1) % $k];
}

/**
 * Assign the arriving person to a team and record it.
 *
 * MUST be called inside se_lock_event(): it reads counts and then writes
 * them back, and the lock is the only thing that makes that safe (I4).
 *
 * @param array $event the LOCKED event row
 * @return array{team: ?array, assigned: bool}
 */
function se_assign_team(PDO $pdo, array $event, array $registration): array
{
    $eventId  = (int) $event['id'];
    $settings = se_event_settings($event);

    if (!se_teams_ready($pdo) || !se_bool(se_settings_path($settings, 'teams.enabled', true))) {
        return ['team' => null, 'assigned' => false];
    }
    if (!empty($registration['team_id'])) {
        return ['team' => se_team_find($pdo, $eventId, (int) $registration['team_id']), 'assigned' => false];
    }

    $teams = se_teams($pdo, $eventId);
    if (!$teams) {
        return ['team' => null, 'assigned' => false];
    }

    $gender = (string) ($registration['gender'] ?? '');
    if ($gender !== 'Male' && $gender !== 'Female') {
        // §10.5 step 6 asks for it before we ever get here; if it is still
        // missing, balance on size and mix only rather than refusing entry.
        $gender = 'Male';
    }

    $choice = se_team_choose(
        $teams,
        se_team_counts($pdo, $eventId),
        $gender,
        se_bool($registration['is_member'] ?? false),
        (int) $event['team_rr_pointer']
    );

    $teamId = (int) $choice['team']['id'];

    $pdo->prepare("UPDATE se_events SET team_rr_pointer = ? WHERE id = ?")
        ->execute([$choice['next_pointer'], $eventId]);

    // `AND team_id IS NULL` keeps this idempotent: a retry never re-assigns
    // somebody who already has a team.
    $stmt = $pdo->prepare(
        "UPDATE se_registrations SET team_id = ?, team_assigned_at = NOW()
         WHERE id = ? AND team_id IS NULL"
    );
    $stmt->execute([$teamId, (int) $registration['id']]);

    if ($stmt->rowCount() === 0) {
        $current = se_registration_by_id($pdo, (int) $registration['id'], $eventId);
        $existing = $current && $current['team_id'] !== null
            ? se_team_find($pdo, $eventId, (int) $current['team_id'])
            : null;
        return ['team' => $existing, 'assigned' => false];
    }

    $pdo->prepare(
        "INSERT INTO se_team_moves (event_id, registration_id, from_team_id, to_team_id, method)
         VALUES (?, ?, NULL, ?, 'auto')"
    )->execute([$eventId, (int) $registration['id'], $teamId]);

    return ['team' => $choice['team'], 'assigned' => true];
}

// --------------------------------------------------------------------------
// Configuration (§10.6.5)
// --------------------------------------------------------------------------

/**
 * Colour names for the auto-suggested labels.
 *
 * CSS named colours trimmed to the ones a person would actually say, plus a
 * short Nigerian-English-friendly list, because "Team Ankara Green" reads
 * better in a Lekki hall than "Team MediumSeaGreen".
 */
const SE_COLOR_NAMES = [
    'Black'   => '#000000', 'White'     => '#FFFFFF', 'Grey'      => '#808080',
    'Silver'  => '#C0C0C0', 'Charcoal'  => '#36454F', 'Cream'     => '#FFFDD0',
    'Red'     => '#FF0000', 'Crimson'   => '#DC143C', 'Maroon'    => '#800000',
    'Coral'   => '#FF7F50', 'Orange'    => '#FFA500', 'Amber'     => '#FFBF00',
    'Gold'    => '#FFD700', 'Yellow'    => '#FFFF00', 'Mustard'   => '#FFDB58',
    'Lime'    => '#00FF00', 'Green'     => '#008000', 'Emerald'   => '#50C878',
    'Olive'   => '#808000', 'Teal'      => '#008080', 'Turquoise' => '#40E0D0',
    'Cyan'    => '#00FFFF', 'Sky'       => '#87CEEB', 'Blue'      => '#0000FF',
    'Navy'    => '#000080', 'Royal'     => '#4169E1', 'Indigo'    => '#4B0082',
    'Purple'  => '#800080', 'Violet'    => '#8F00FF', 'Lavender'  => '#E6E6FA',
    'Magenta' => '#FF00FF', 'Pink'      => '#FFC0CB', 'Rose'      => '#FF007F',
    'Brown'   => '#A52A2A', 'Chocolate' => '#7B3F00', 'Beige'     => '#F5F5DC',
    'Ankara'  => '#E2725B', 'Palm'      => '#2E8B57', 'Lagoon'    => '#1CA9C9',
];

/** The nearest named colour to a hex, by OKLab distance (§10.6.5). */
function se_color_name(string $hex): string
{
    $norm = se_normalize_hex($hex);
    if ($norm === null) {
        return 'Colour';
    }

    $best     = 'Colour';
    $bestDist = PHP_FLOAT_MAX;

    foreach (SE_COLOR_NAMES as $name => $candidate) {
        $dist = se_color_delta_e($norm, $candidate);
        if ($dist < $bestDist) {
            $bestDist = $dist;
            $best     = $name;
        }
    }

    return $best;
}

/** Split a pasted list of colours on any separator (§10.6.5 paste box). */
function se_parse_hex_list(string $raw): array
{
    $parts = preg_split('/[\s,;|]+/', trim($raw)) ?: [];
    $out   = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $hex = se_normalize_hex($part);
        // The same colour twice would give two teams the same auto label,
        // and "Team Red, the other one" is not a thing a host can say. The
        // first occurrence keeps its position (§28.4).
        if ($hex !== null && !in_array($hex, $out, true)) {
            $out[] = $hex;
        }
    }

    return $out;
}

/** Has anyone been put on a team yet? The count locks once they have. */
function se_teams_locked(PDO $pdo, int $eventId): bool
{
    if (!se_teams_ready($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND team_id IS NOT NULL");
        $stmt->execute([$eventId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('SE teams/locked: ' . $e->getMessage());
        return true;
    }
}

/**
 * Save the team list (Studio → Teams).
 *
 * The count is locked once any registration has a team (§10.6.5): after that
 * only colours, labels, names and captains may change, so the rows are
 * matched by position and never deleted.
 *
 * @param array $input list of ['color_hex', 'color_label'?, 'name'?]
 */
function se_teams_save(PDO $pdo, array $event, array $input, ?int $actorId): array
{
    $eventId = (int) $event['id'];

    if (!se_teams_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Teams are not available yet.');
    }

    $wanted = [];
    foreach (array_values($input) as $i => $row) {
        if ($i >= SE_MAX_TEAMS) {
            break;
        }
        $hex = se_normalize_hex($row['color_hex'] ?? ($row['hex'] ?? ''));
        if ($hex === null) {
            throw new SeValidationException(
                ['teams' => 'Team ' . ($i + 1) . ' needs a colour like #1D356A.']
            );
        }
        $label = se_line($row['color_label'] ?? '', 30);
        $name  = se_line($row['name'] ?? '', 40);

        $wanted[] = [
            'color_hex'   => $hex,
            'color_label' => $label !== '' ? $label : se_color_name($hex),
            'name'        => $name !== '' ? $name : null,
        ];
    }

    if (count($wanted) < SE_MIN_TEAMS) {
        throw new SeValidationException(['teams' => 'An event needs at least ' . SE_MIN_TEAMS . ' teams.']);
    }

    $existing = se_teams($pdo, $eventId);
    $locked   = se_teams_locked($pdo, $eventId);

    if ($locked && count($wanted) !== count($existing)) {
        throw new SeRuleException(
            'TEAM_COUNT_LOCKED',
            'People are already on teams, so the number of teams cannot change tonight. Colours and names still can.'
        );
    }

    se_lock_event($pdo, $eventId, static function (array $lockedEvent, PDO $pdo) use ($eventId, $wanted, $existing, $actorId): void {
        foreach ($wanted as $i => $team) {
            if (isset($existing[$i])) {
                $pdo->prepare(
                    "UPDATE se_teams SET color_hex = ?, color_label = ?, name = COALESCE(?, name)
                     WHERE id = ? AND event_id = ?"
                )->execute([
                    $team['color_hex'], $team['color_label'], $team['name'],
                    (int) $existing[$i]['id'], $eventId,
                ]);
                continue;
            }

            $pdo->prepare(
                "INSERT INTO se_teams (event_id, sort_order, color_hex, color_label, name, team_key)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $eventId, $i, $team['color_hex'], $team['color_label'], $team['name'],
                se_random_token(SE_DISPLAY_KEY_LENGTH),
            ]);
        }

        // Teams past the new end are removed only while nothing is assigned,
        // which se_teams_save() has already checked.
        for ($i = count($wanted); $i < count($existing); $i++) {
            $pdo->prepare("DELETE FROM se_teams WHERE id = ? AND event_id = ?")
                ->execute([(int) $existing[$i]['id'], $eventId]);
        }
    });

    se_audit($pdo, $eventId, 'event_update:teams', ['count' => count($wanted)], 'event', $eventId, $actorId);
    se_live_publish($pdo, $eventId, true);

    return se_teams($pdo, $eventId);
}

/**
 * Everything the Studio Teams tab needs: the rows, their derived tokens, the
 * live counts, the roster and the two non-blocking warnings (§10.6.5).
 */
function se_teams_payload(PDO $pdo, array $event, bool $withRoster = true): array
{
    $eventId = (int) $event['id'];
    $teams   = se_teams($pdo, $eventId);
    $counts  = se_team_counts($pdo, $eventId);
    $theme   = se_event_theme($event);
    $bg      = (string) ($theme['tokens']['--se-bg'] ?? '#0B0D13');

    $rows = [];
    foreach ($teams as $i => $team) {
        $tokens = se_team_tokens((string) $team['color_hex'], $bg, $i);
        $id     = (int) $team['id'];

        $captain = null;
        if (!empty($team['captain_registration_id'])) {
            $reg = se_registration_by_id($pdo, (int) $team['captain_registration_id'], $eventId);
            if ($reg) {
                $captain = [
                    'registration_id' => (int) $reg['id'],
                    'display_name'    => (string) $reg['display_name'],
                    'player_no'       => $reg['player_no'] !== null ? (int) $reg['player_no'] : null,
                ];
            }
        }

        $rows[] = [
            'id'          => $id,
            'sort_order'  => (int) $team['sort_order'],
            'color_hex'   => (string) $team['color_hex'],
            'color_label' => (string) $team['color_label'],
            'name'        => $team['name'],
            'team_key'    => (string) $team['team_key'],
            'tokens'      => $tokens,
            'counts'      => $counts[$id] ?? ['n' => 0, 'n_Male' => 0, 'n_Female' => 0, 'n_member' => 0, 'n_guest' => 0],
            'captain'     => $captain,
        ];
    }

    // §10.6.5 names ΔE < 0.08 as "hard to tell apart" for teams; the shared
    // helper's default (0.12) is the Studio-wide palette warning.
    $similar = se_team_similarity_warnings(array_column($rows, 'color_hex'), 0.08);

    $lowContrast = [];
    foreach ($rows as $i => $row) {
        if ($row['tokens']['needs_ring']) {
            $lowContrast[] = ['index' => $i, 'ratio' => $row['tokens']['ratio_vs_bg']];
        }
    }

    return [
        'teams'    => $rows,
        'locked'   => se_teams_locked($pdo, $eventId),
        'bg'       => $bg,
        'warnings' => ['similar' => $similar, 'low_contrast' => $lowContrast],
        'roster'   => $withRoster ? se_teams_roster($pdo, $eventId) : null,
        'limits'   => ['min' => SE_MIN_TEAMS, 'max' => SE_MAX_TEAMS],
    ];
}

/** Who is on each team, for the Studio roster and the desk's move dialog. */
function se_teams_roster(PDO $pdo, int $eventId): array
{
    if (!se_teams_ready($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, team_id, display_name, player_no, gender, is_member
             FROM se_registrations
             WHERE event_id = ? AND team_id IS NOT NULL AND status = 'confirmed'
             ORDER BY player_no IS NULL, player_no ASC, display_name ASC"
        );
        $stmt->execute([$eventId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'registration_id' => (int) $row['id'],
                'team_id'         => (int) $row['team_id'],
                'display_name'    => (string) $row['display_name'],
                'player_no'       => $row['player_no'] !== null ? (int) $row['player_no'] : null,
                'gender'          => $row['gender'],
                'is_member'       => se_bool($row['is_member']),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('SE teams/roster: ' . $e->getMessage());
        return [];
    }
}

// --------------------------------------------------------------------------
// Live changes (§10.6.4, §10.6.5)
// --------------------------------------------------------------------------

/** Move one person to another team. Points already earned stay with the team. */
function se_team_move(PDO $pdo, array $event, int $registrationId, int $toTeamId, string $reason, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $reason  = se_line($reason, 160);

    if (mb_strlen($reason, 'UTF-8') < 3) {
        throw new SeValidationException(['reason' => 'Say why in a few words — it goes in the log.']);
    }

    $team = se_team_find($pdo, $eventId, $toTeamId);
    if (!$team) {
        throw new SeNotFoundException('That team is not part of this event.');
    }

    $result = se_lock_event($pdo, $eventId, static function (array $locked, PDO $pdo) use ($eventId, $registrationId, $toTeamId, $reason, $actorId): array {
        $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? FOR UPDATE");
        $stmt->execute([$registrationId, $eventId]);
        $reg = $stmt->fetch();

        if (!$reg) {
            throw new SeNotFoundException('We could not find that person.');
        }

        $from = $reg['team_id'] !== null ? (int) $reg['team_id'] : null;
        if ($from === $toTeamId) {
            return ['moved' => false, 'from_team_id' => $from];
        }

        $pdo->prepare("UPDATE se_registrations SET team_id = ?, team_assigned_at = NOW() WHERE id = ?")
            ->execute([$toTeamId, $registrationId]);

        $pdo->prepare(
            "INSERT INTO se_team_moves (event_id, registration_id, from_team_id, to_team_id, method, reason, moved_by)
             VALUES (?, ?, ?, ?, 'crew', ?, ?)"
        )->execute([$eventId, $registrationId, $from, $toTeamId, $reason, $actorId]);

        return ['moved' => true, 'from_team_id' => $from];
    });

    if ($result['moved']) {
        se_audit($pdo, $eventId, 'team_move', [
            'registration_id' => $registrationId,
            'from'            => $result['from_team_id'],
            'to'              => $toTeamId,
            'reason'          => $reason,
        ], 'registration', $registrationId, $actorId);

        se_live_publish($pdo, $eventId, true);
    }

    return ['moved' => $result['moved'], 'team' => $team];
}

/** Rename a team on the night. Triggers the stage reveal via the sound cue. */
function se_team_rename(PDO $pdo, array $event, int $teamId, string $name, ?int $expectedVersion, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $name    = se_line($name, 40);

    if ($name === '') {
        throw new SeValidationException(['name' => 'Give the team a name.']);
    }

    $team = se_team_find($pdo, $eventId, $teamId);
    if (!$team) {
        throw new SeNotFoundException('That team is not part of this event.');
    }

    $pdo->prepare("UPDATE se_teams SET name = ?, name_set_at = NOW(), name_set_by = ? WHERE id = ? AND event_id = ?")
        ->execute([$name, $actorId, $teamId, $eventId]);

    se_audit($pdo, $eventId, 'team_rename', ['team_id' => $teamId, 'name' => $name], 'team', $teamId, $actorId);

    // The rename and the cue share one version bump, so the stage sees the
    // new name and the `team_name` cue in the same snapshot.
    $state = se_live_mutate($pdo, $event, $expectedVersion, static fn(array $row): array => [
        'sfx_seq' => (int) $row['sfx_seq'] + 1,
        'sfx_cue' => 'team_name',
    ], null, [], $actorId);

    return ['team' => se_team_find($pdo, $eventId, $teamId), 'state' => $state];
}

/** Set (or clear) a team's captain. */
function se_captain_set(PDO $pdo, array $event, int $teamId, ?int $registrationId, ?int $actorId): array
{
    $eventId = (int) $event['id'];

    $team = se_team_find($pdo, $eventId, $teamId);
    if (!$team) {
        throw new SeNotFoundException('That team is not part of this event.');
    }

    if ($registrationId !== null) {
        $reg = se_registration_by_id($pdo, $registrationId, $eventId);
        if (!$reg) {
            throw new SeNotFoundException('We could not find that person.');
        }
        if ((int) ($reg['team_id'] ?? 0) !== $teamId) {
            throw new SeRuleException('VALIDATION', 'That person is not on this team.');
        }
    }

    $pdo->prepare("UPDATE se_teams SET captain_registration_id = ? WHERE id = ? AND event_id = ?")
        ->execute([$registrationId, $teamId, $eventId]);

    se_audit($pdo, $eventId, 'captain_set', [
        'team_id' => $teamId, 'registration_id' => $registrationId,
    ], 'team', $teamId, $actorId);

    se_live_publish($pdo, $eventId, true, ['public', 'room', 'team']);

    return se_team_find($pdo, $eventId, $teamId) ?? $team;
}

/** The public shape of a team, for check-in and the `me` payload. */
function se_team_public(array $team, array $theme): array
{
    $bg     = (string) ($theme['tokens']['--se-bg'] ?? '#0B0D13');
    $tokens = se_team_tokens((string) $team['color_hex'], $bg, (int) $team['sort_order']);

    return [
        'id'    => (int) $team['id'],
        'name'  => $team['name'] !== null && $team['name'] !== '' ? (string) $team['name'] : null,
        'label' => (string) $team['color_label'],
        'hex'   => $tokens['color'],
        'on'    => $tokens['on'],
        'glow'  => $tokens['glow'],
        'ring'  => (bool) $tokens['needs_ring'],
    ];
}
