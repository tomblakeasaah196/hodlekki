<?php
// /includes/special_events/scoring.php
//
// The score ledger and leaderboards (guide §11.6, §11.11).
//
// Scores are a LEDGER (AGENTS.md): every point awarded or removed is a row in
// se_score_events, totals are sums, and a mistake is fixed by voiding the row
// and writing a new one — never by updating points in place. Automatic rows
// carry an idempotency key (§11.6.2), so scoring a round twice, or two
// consoles scoring it at once, can never double-count: the unique key on
// (event_id, idempotency_key) absorbs the second write.

// --------------------------------------------------------------------------
// Formulas (§11.6.1) — pure, unit-tested
// --------------------------------------------------------------------------

/** Live Quiz speed points: round(base × (1 − (elapsed / duration) / 2)). */
function se_quiz_points(int $base, int $elapsed, int $duration): int
{
    if ($base <= 0) {
        return 0;
    }
    $fraction = min($duration, max(0, $elapsed)) / (float) max(1, $duration);

    return max(0, (int) round($base * (1 - $fraction / 2)));
}

/** A team's share of a quiz question: base × correct members / eligible members. */
function se_team_normalized_points(int $base, int $correct, int $eligible): int
{
    return (int) round($base * $correct / max(1, $eligible));
}

/** A game's weight multiplies its TEAM points at write time (§11.6.1). */
function se_weighted_points(int $points, mixed $weight): int
{
    $weight = is_numeric($weight) ? (float) $weight : 1.0;

    return (int) round($points * max(0.0, $weight));
}

/** The base points of a Live Quiz question for its points_mode. */
function se_quiz_base(array $settings): int
{
    return match ($settings['points_mode'] ?? 'standard') {
        'double' => 2000,
        'none'   => 0,
        default  => 1000,
    };
}

/** Idempotency key of an automatic row (§11.6.2). */
function se_score_key(string $kind, int $round, int $subject, int $attempt = 0): string
{
    $scope = $kind === 'q' ? 'p' : 't';

    return $kind . ':' . $round . ':' . $scope . ':' . $subject . ($attempt ? ':a:' . $attempt : '');
}

/**
 * The reason stored on a rehearsal row. Reset rehearsal voids `TEST` and
 * `TEST: …` rows (§11.13), so an award named during a rehearsal is cleared
 * along with the automatic points.
 */
function se_score_test_reason(?string $label = null): string
{
    $label = trim((string) $label);

    return $label === '' ? 'TEST' : mb_substr('TEST: ' . $label, 0, 160, 'UTF-8');
}

// --------------------------------------------------------------------------
// The ledger
// --------------------------------------------------------------------------

/**
 * Write one ledger row. With an idempotency key, a repeat is a no-op that
 * returns the original row's id.
 *
 * @return array{id: int, points: int, idempotency_key: ?string, duplicate: bool}
 */
function se_score_insert(PDO $pdo, array $event, array $in, int $actor): array
{
    $scope = se_enum($in['scope'] ?? '', ['team', 'individual'], '');
    if ($scope === '') {
        throw new SeValidationException(['scope' => 'Choose a team or a player.']);
    }

    $eventId = (int) $event['id'];
    $points  = (int) ($in['points'] ?? 0);
    $key     = se_line($in['idempotency_key'] ?? '', 80) ?: null;
    $teamId  = isset($in['team_id']) && $in['team_id'] !== null ? (int) $in['team_id'] : null;
    $regId   = isset($in['registration_id']) && $in['registration_id'] !== null ? (int) $in['registration_id'] : null;

    if ($scope === 'team' && !$teamId) {
        throw new SeValidationException(['team_id' => 'Choose the team.']);
    }
    if ($scope === 'individual' && !$regId) {
        throw new SeValidationException(['registration_id' => 'Choose the player.']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO se_score_events
            (event_id, scope, team_id, registration_id, game_id, round_id, kind, points, reason, idempotency_key, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    try {
        $stmt->execute([
            $eventId,
            $scope,
            $teamId,
            $regId,
            isset($in['game_id']) ? (int) $in['game_id'] : null,
            isset($in['round_id']) ? (int) $in['round_id'] : null,
            se_enum($in['kind'] ?? 'award', ['auto', 'award', 'penalty', 'correction'], 'award'),
            $points,
            se_line($in['reason'] ?? '', 160) ?: null,
            $key,
            $actor ?: null,
        ]);
    } catch (PDOException $e) {
        if ($key !== null && se_is_duplicate_key($e)) {
            $q = $pdo->prepare("SELECT id, points FROM se_score_events WHERE event_id = ? AND idempotency_key = ?");
            $q->execute([$eventId, $key]);
            $row = $q->fetch() ?: ['id' => 0, 'points' => $points];

            return ['id' => (int) $row['id'], 'points' => (int) $row['points'], 'idempotency_key' => $key, 'duplicate' => true];
        }
        throw $e;
    }

    return ['id' => (int) $pdo->lastInsertId(), 'points' => $points, 'idempotency_key' => $key, 'duplicate' => false];
}

/** Void one row (the ledger's "undo"). A reason is required and kept. */
function se_score_void(PDO $pdo, array $event, int $id, string $reason, int $actor): bool
{
    $reason = se_line($reason, 160);
    if (mb_strlen($reason) < 3) {
        throw new SeValidationException(['reason' => 'Give a reason for undoing this score.']);
    }

    $stmt = $pdo->prepare(
        "UPDATE se_score_events SET voided_at = NOW(), voided_by = ?, void_reason = ?
          WHERE id = ? AND event_id = ? AND voided_at IS NULL"
    );
    $stmt->execute([$actor ?: null, $reason, $id, (int) $event['id']]);

    return $stmt->rowCount() > 0;
}

/** Void every row a round wrote (§11.2 "void"). Returns how many. */
function se_round_scores_void(PDO $pdo, array $event, int $roundId, string $reason, int $actor): int
{
    $stmt = $pdo->prepare(
        "UPDATE se_score_events SET voided_at = NOW(), voided_by = ?, void_reason = ?
          WHERE event_id = ? AND round_id = ? AND voided_at IS NULL"
    );
    $stmt->execute([$actor ?: null, se_line($reason, 160) ?: 'Round voided', (int) $event['id'], $roundId]);

    return $stmt->rowCount();
}

// --------------------------------------------------------------------------
// Leaderboards (§11.11)
// --------------------------------------------------------------------------

/**
 * Team totals for every team (a team with no points yet is on the board
 * with 0), dense-ranked, plus the individual MVP top five.
 *
 * MVP ties break on correct answers, then on who reached the score first
 * (§11.6.2).
 */
function se_leaderboards(PDO $pdo, array $event, int $mvpCount = 5): array
{
    if (!se_table_exists($pdo, 'se_score_events')) {
        return ['teams' => [], 'mvp' => []];
    }
    $eventId = (int) $event['id'];

    $stmt = $pdo->prepare(
        "SELECT team_id, SUM(points) AS points
           FROM se_score_events
          WHERE event_id = ? AND scope = 'team' AND voided_at IS NULL AND team_id IS NOT NULL
          GROUP BY team_id"
    );
    $stmt->execute([$eventId]);
    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $totals[(int) $row['team_id']] = (int) $row['points'];
    }

    $teams = [];
    if (se_teams_ready($pdo)) {
        foreach (se_teams($pdo, $eventId) as $team) {
            $teams[] = ['team_id' => (int) $team['id'], 'points' => $totals[(int) $team['id']] ?? 0];
        }
    } else {
        foreach ($totals as $teamId => $points) {
            $teams[] = ['team_id' => $teamId, 'points' => $points];
        }
    }
    usort($teams, static fn(array $a, array $b): int => [$b['points'], $a['team_id']] <=> [$a['points'], $b['team_id']]);

    $rank = 0;
    $last = null;
    foreach ($teams as &$team) {
        if ($team['points'] !== $last) {
            $rank++;
            $last = $team['points'];
        }
        $team['rank'] = $rank;
    }
    unset($team);

    $stmt = $pdo->prepare(
        "SELECT s.registration_id,
                SUM(s.points) AS points,
                MAX(s.created_at) AS last_scoring_at,
                (SELECT COUNT(*) FROM se_answers a
                  WHERE a.event_id = s.event_id AND a.registration_id = s.registration_id AND a.is_correct = 1) AS correct_count
           FROM se_score_events s
          WHERE s.event_id = ? AND s.scope = 'individual' AND s.voided_at IS NULL AND s.registration_id IS NOT NULL
          GROUP BY s.event_id, s.registration_id
         HAVING SUM(s.points) > 0
          ORDER BY points DESC, correct_count DESC, last_scoring_at ASC, s.registration_id ASC
          LIMIT " . max(1, min(20, $mvpCount))
    );
    $stmt->execute([$eventId]);

    $mvp = array_map(static fn(array $row): array => [
        'registration_id' => (int) $row['registration_id'],
        'points'          => (int) $row['points'],
        'correct_count'   => (int) $row['correct_count'],
    ], $stmt->fetchAll());

    return ['teams' => $teams, 'mvp' => $mvp];
}

/** Team totals keyed by team id (for snapshots). */
function se_team_scores(PDO $pdo, array $event): array
{
    $out = [];
    foreach (se_leaderboards($pdo, $event, 1)['teams'] as $row) {
        $out[$row['team_id']] = $row;
    }

    return $out;
}

// --------------------------------------------------------------------------
// Crew awards and penalties (§11.6.2)
// --------------------------------------------------------------------------

/** A named award or penalty from the console, under the live version check. */
function se_score_adjust_live(PDO $pdo, array $event, array $input, ?int $expected, int $actor): array
{
    $reason = se_line($input['reason'] ?? '', 120);
    if (mb_strlen($reason) < 3) {
        throw new SeValidationException(['reason' => 'Give the award or penalty a name.']);
    }
    $points = se_int($input['points'] ?? 0, -5000, 5000, 0);
    if ($points === 0) {
        throw new SeValidationException(['points' => 'How many points?']);
    }

    $testMode = se_bool(se_event_settings($event)['test_mode'] ?? false);
    $row = [
        'scope'           => se_enum($input['scope'] ?? 'team', ['team', 'individual'], 'team'),
        'team_id'         => isset($input['team_id']) ? (int) $input['team_id'] : null,
        'registration_id' => isset($input['registration_id']) ? (int) $input['registration_id'] : null,
        'kind'            => $points < 0 ? 'penalty' : 'award',
        'points'          => $points,
        'reason'          => $testMode ? se_score_test_reason($reason) : $reason,
    ];

    $saved = [];
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $row, $actor, &$saved): array {
        $saved = se_score_insert($pdo, $event, $row, $actor);

        return [];
    }, 'score_award', ['points' => $points, 'reason' => $reason], $actor);

    return $saved + ['version' => (int) $state['version'], 'leaderboard' => se_leaderboards($pdo, $event)];
}

/** Undo one ledger row from the console. */
function se_score_void_live(PDO $pdo, array $event, int $scoreId, string $reason, ?int $expected, int $actor): array
{
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $scoreId, $reason, $actor): array {
        if (!se_score_void($pdo, $event, $scoreId, $reason, $actor)) {
            throw new SeRuleException('STALE_STATE', 'That score was already undone.');
        }

        return [];
    }, 'score_void', ['score_id' => $scoreId, 'reason' => $reason], $actor);

    return ['version' => (int) $state['version'], 'leaderboard' => se_leaderboards($pdo, $event)];
}

/** The console's score history: the latest rows, named for people. */
function se_score_history(PDO $pdo, array $event, int $limit = 30): array
{
    if (!se_table_exists($pdo, 'se_score_events')) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT s.id, s.scope, s.team_id, s.registration_id, s.points, s.reason, s.kind, s.round_id, s.created_at,
                r.display_name
           FROM se_score_events s
           LEFT JOIN se_registrations r ON r.id = s.registration_id
          WHERE s.event_id = ? AND s.voided_at IS NULL
          ORDER BY s.id DESC
          LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute([(int) $event['id']]);

    return array_map(static fn(array $row): array => [
        'id'           => (int) $row['id'],
        'scope'        => (string) $row['scope'],
        'team_id'      => $row['team_id'] !== null ? (int) $row['team_id'] : null,
        'player'       => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        'points'       => (int) $row['points'],
        'reason'       => (string) ($row['reason'] ?? ''),
        'kind'         => (string) $row['kind'],
        'round_id'     => $row['round_id'] !== null ? (int) $row['round_id'] : null,
        'created_at'   => se_iso($row['created_at']),
    ], $stmt->fetchAll());
}
