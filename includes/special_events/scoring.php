<?php
// /includes/special_events/scoring.php
// Append-only scoring ledger and leaderboards.

function se_quiz_points(int $base, int $elapsed, int $duration): int
{
    if ($base <= 0) {
        return 0;
    }

    return max(0, (int) round($base * (1 - (min($duration, max(0, $elapsed)) / (float) max(1, $duration)) / 2)));
}
function se_team_normalized_points(int $base, int $correct, int $eligible): int
{
    return (int) round($base * $correct / max(1, $eligible));
}
function se_score_key(string $kind, int $round, int $subject, int $attempt = 0): string
{
    $scope = $kind === 'q' ? 'p' : 't';

    return $kind . ':' . $round . ':' . $scope . ':' . $subject . ($attempt ? ':a:' . $attempt : '');
}
function se_score_insert(PDO $pdo, array $event, array $in, int $actor): array
{
    $scope = se_enum($in['scope'] ?? '', ['team', 'individual'], '');

    if ($scope === '') {
        throw new SeValidationException(['scope' => 'Score scope is required.'], 'Score scope is required.');
    }
    $points = (int) ($in['points'] ?? 0);
    $key = se_line($in['idempotency_key'] ?? '', 80) ?: null;
    $s = $pdo->prepare('INSERT INTO se_score_events(event_id,scope,team_id,registration_id,game_id,round_id,kind,points,reason,idempotency_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
    $s->execute([(int) $event['id'], $scope, $in['team_id'] ?? null, $in['registration_id'] ?? null, $in['game_id'] ?? null, $in['round_id'] ?? null, se_enum($in['kind'] ?? 'award', ['auto', 'award', 'penalty', 'correction'], 'award'), $points, se_line($in['reason'] ?? '', 160) ?: null, $key, $actor]);
    $id = (int) $pdo->lastInsertId();

    if (!$id && $key) {
        $q = $pdo->prepare('SELECT id FROM se_score_events WHERE event_id=? AND idempotency_key=?');
        $q->execute([(int) $event['id'], $key]);
        $id = (int) $q->fetchColumn();
    }

    return ['id' => $id, 'points' => $points, 'idempotency_key' => $key];
}
function se_score_void(PDO $pdo, array $event, int $id, string $reason, int $actor): void
{
    if (mb_strlen(trim($reason)) < 3) {
        throw new SeValidationException(['reason' => 'Give a reason for voiding a score.'], 'Give a reason for voiding a score.');
    }
    $s = $pdo->prepare('UPDATE se_score_events SET voided_at=NOW(),voided_by=?,void_reason=? WHERE id=? AND event_id=? AND voided_at IS NULL');
    $s->execute([$actor, $reason, $id, (int) $event['id']]);
}
function se_leaderboards(PDO $pdo, array $event): array
{
    if (!se_table_exists($pdo, 'se_score_events')) {
        return ['teams' => [], 'mvp' => []];
    }
    $id = (int) $event['id'];
    $s = $pdo->prepare("SELECT team_id,SUM(points) points FROM se_score_events WHERE event_id=? AND scope='team' AND voided_at IS NULL GROUP BY team_id ORDER BY points DESC,team_id");
    $s->execute([$id]);
    $teams = $s->fetchAll(PDO::FETCH_ASSOC);
    $s = $pdo->prepare("SELECT s.registration_id,SUM(s.points) points,COALESCE(a.correct_count,0) correct_count,MAX(s.created_at) last_scoring_at FROM se_score_events s LEFT JOIN (SELECT registration_id,SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) correct_count FROM se_answers WHERE event_id=? GROUP BY registration_id) a ON a.registration_id=s.registration_id WHERE s.event_id=? AND s.scope='individual' AND s.voided_at IS NULL GROUP BY s.registration_id,a.correct_count ORDER BY points DESC,correct_count DESC,last_scoring_at ASC,s.registration_id LIMIT 5");
    $s->execute([$id, $id]);

    return ['teams' => $teams, 'mvp' => $s->fetchAll(PDO::FETCH_ASSOC)];
}
function se_round_score(PDO $pdo, array $event, int $roundId, int $actor): array
{
    $stmt = $pdo->prepare('SELECT r.*,g.type game_type,g.settings_json,g.weight FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?');
    $stmt->execute([$roundId, (int) $event['id']]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        throw new SeNotFoundException('Round not found.');
    }

    if ($round['state'] !== 'revealed') {
        throw new SeRuleException('ROUND_NOT_REVEALED', 'Reveal the round before scoring.');
    }
    $item = se_round_item($pdo, $round);
    $payload = $item['payload'] ?? [];
    $correct = (int) ($payload['answer_index'] ?? -1);
    $stmt = $pdo->prepare("SELECT * FROM se_answers WHERE round_id=? AND role IN ('player','captain')");
    $stmt->execute([$roundId]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = se_game_settings($round);
    $testReason = !empty($round['is_test']) ? 'TEST' : null;
    $correctByTeam = [];

    foreach ($answers as $answer) {
        $ok = $correct >= 0 && (int) $answer['choice_index'] === $correct;
        $pdo->prepare('UPDATE se_answers SET is_correct=? WHERE id=?')->execute([$ok ? 1 : 0, (int) $answer['id']]);

        if ($round['game_type'] === 'live_quiz') {
            $points = $ok ? se_quiz_points((int) ($settings['base'] ?? 1000), (int) $answer['elapsed_ms'], (int) ($settings['duration_ms'] ?? 20000)) : 0;
            se_score_insert($pdo, $event, ['scope' => 'individual', 'registration_id' => (int) $answer['registration_id'], 'game_id' => (int) $round['game_id'], 'round_id' => $roundId, 'kind' => 'auto', 'points' => $points, 'reason' => $testReason, 'idempotency_key' => 'q:' . $roundId . ':p:' . $answer['registration_id']], $actor);

            if ($ok && $answer['team_id']) {
                $correctByTeam[(int) $answer['team_id']] = ($correctByTeam[(int) $answer['team_id']] ?? 0) + 1;
            }
        } elseif ($round['game_type'] === 'trivia' && $ok && $answer['team_id']) {
            se_score_insert($pdo, $event, ['scope' => 'team', 'team_id' => (int) $answer['team_id'], 'game_id' => (int) $round['game_id'], 'round_id' => $roundId, 'kind' => 'auto', 'points' => (int) ($settings['points_correct'] ?? 300), 'reason' => $testReason, 'idempotency_key' => 'r:' . $roundId . ':t:' . $answer['team_id']], $actor);
        }
    }

    if ($round['game_type'] === 'live_quiz') {
        $eligible = json_decode((string) ($round['eligible_json'] ?? '{}'), true) ?: [];

        foreach ($eligible as $teamId => $number) {
            $points = se_team_normalized_points((int) ($settings['team_base'] ?? 1000), (int) ($correctByTeam[(int) $teamId] ?? 0), (int) $number);
            se_score_insert($pdo, $event, ['scope' => 'team', 'team_id' => (int) $teamId, 'game_id' => (int) $round['game_id'], 'round_id' => $roundId, 'kind' => 'auto', 'points' => (int) round($points * (float) $round['weight']), 'reason' => $testReason, 'idempotency_key' => 'q:' . $roundId . ':t:' . $teamId], $actor);
        }
    }
    $stmt = $pdo->prepare("UPDATE se_rounds SET state='scored' WHERE id=? AND state='revealed'");
    $stmt->execute([$roundId]);

    if (!$stmt->rowCount()) {
        throw new SeRuleException('STALE_STATE', 'That round was already scored.');
    }

    return ['answers' => count($answers), 'leaderboard' => se_leaderboards($pdo, $event)];
}

function se_score_adjust_live(PDO $pdo, array $event, array $input, ?int $expected, int $actor): array
{
    $saved = [];
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $input, $actor, &$saved): array {
        $reason = se_line($input['reason'] ?? '', 160);

        if (mb_strlen($reason) < 3) {
            throw new SeValidationException(['reason' => 'Give the award or penalty a name.'], 'Give the award or penalty a name.');
        }
        $saved = se_score_insert($pdo, $event, $input + ['reason' => $reason], $actor);

        return [];
    }, 'score_adjust', ['reason' => se_line($input['reason'] ?? '', 160)], $actor);

    return $saved + ['version' => (int) $state['version'], 'leaderboard' => se_leaderboards($pdo, $event)];
}

function se_score_void_live(PDO $pdo, array $event, int $scoreId, string $reason, ?int $expected, int $actor): array
{
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $scoreId, $reason, $actor): array {
        se_score_void($pdo, $event, $scoreId, $reason, $actor);

        return [];
    }, 'score_void', ['score_id' => $scoreId, 'reason' => $reason], $actor);

    return ['version' => (int) $state['version'], 'leaderboard' => se_leaderboards($pdo, $event)];
}

function se_round_score_live(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    $result = [];
    $out = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $roundId, &$result, $actor): array {
        $result = se_round_score($pdo, $event, $roundId, $actor);

        return ['active_round_id' => $roundId];
    }, 'round_op', ['round_id' => $roundId, 'to' => 'scored'], $actor);

    return $out + ['result' => $result];
}
