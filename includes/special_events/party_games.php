<?php
// /includes/special_events/party_games.php
//
// The party games and the finale (guide §11.7–§11.11): judging a buzz, Who Am
// I? clues, Bible Charades turns, Family Feud (survey, board, face-off,
// strikes, steal, bank), the leaderboard finale, and the Chara starter pack
// (Appendix G).
//
// Privacy rules that matter here (AGENTS.md):
//   * a charades phrase reaches ONLY the presenter's phone (through `me`) and
//     the capability-protected console — never a snapshot;
//   * names (buzzers, presenters, face-off players, the MVP) go only into the
//     key-protected room snapshot, never into public.json.

// --------------------------------------------------------------------------
// Formulas — pure, unit-tested
// --------------------------------------------------------------------------

/** Who Am I? points by the clue the winning buzz came on (500, 400, …). */
function se_who_am_i_points(array $pointsByClue, int $clueIndex): int
{
    if ($pointsByClue === []) {
        $pointsByClue = SE_GAME_DEFAULTS['who_am_i']['points_by_clue'];
    }
    $index = max(0, min(count($pointsByClue) - 1, $clueIndex));

    return max(0, (int) $pointsByClue[$index]);
}

/** Family Feud: Σ revealed answer points × the round multiplier (≥ 1). */
function se_feud_bank_points(array $revealedPoints, int $multiplier): int
{
    return array_sum(array_map('intval', $revealedPoints)) * max(1, $multiplier);
}

// --------------------------------------------------------------------------
// Shared: a round change under the live lock
// --------------------------------------------------------------------------

/**
 * Run `$change($round, $live)` with the round row locked inside
 * se_live_mutate(). `$change` returns [result, live-state changes].
 */
function se_party_live_mutate(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor, string $action, callable $change): array
{
    $result = [];
    $state  = se_live_mutate($pdo, $event, $expected, static function (array $live) use ($pdo, $event, $roundId, $change, &$result): array {
        $round = se_round_find($pdo, (int) $event['id'], $roundId, true);
        if (!$round) {
            throw new SeNotFoundException('We could not find that round.');
        }

        [$result, $liveChanges] = $change($round, $live);

        return ['active_game_id' => (int) $round['game_id'], 'active_round_id' => $roundId, 'scene' => 'game'] + $liveChanges;
    }, 'round_op:' . $action, ['round_id' => $roundId], $actor);

    return ['version' => (int) $state['version']] + $result;
}

/** Persist a round's working state. */
function se_round_state_save(PDO $pdo, int $roundId, array $data): void
{
    $pdo->prepare("UPDATE se_rounds SET state_json = ? WHERE id = ?")->execute([se_json_encode($data), $roundId]);
}

// --------------------------------------------------------------------------
// Buzzer games and Who Am I? (§11.7, §11.8)
// --------------------------------------------------------------------------

/**
 * Next clue (§11.8): the stage adds the clue, every team that was locked out
 * may buzz again, and a fresh buzz window opens.
 */
function se_clue_next(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'clue_next', static function (array $round, array $live) use ($pdo): array {
        if ((string) $round['game_type'] !== 'who_am_i' || !in_array((string) $round['state'], ['armed', 'open', 'locked'], true)) {
            throw new SeRuleException('STALE_STATE', 'Show the first clue (Start) before moving on.');
        }

        $item  = se_round_item($pdo, $round);
        $clues = (array) ($item['payload']['clues'] ?? []);
        $data  = se_round_state_data($round);
        $index = (int) ($data['clue_index'] ?? 0);

        if ($index + 1 >= count($clues)) {
            throw new SeRuleException('NO_MORE_CLUES', 'That was the last clue — reveal the answer.');
        }
        if (se_round_buzz_leader($pdo, $round) !== null) {
            throw new SeRuleException('STALE_STATE', 'Judge the buzz first.');
        }

        $data['clue_index'] = $index + 1;
        $data['locked_out'] = [];

        $settings = se_game_settings($round);
        $opens    = se_epoch_ms() + 1500;
        $closes   = $opens + (int) ($settings['window_ms'] ?? 20000);

        $pdo->prepare(
            "UPDATE se_rounds SET state = 'armed', attempt = attempt + 1, state_json = ?, opens_at = ?, closes_at = ?, locked_at = NULL
              WHERE id = ?"
        )->execute([se_json_encode($data), se_ms_to_sql($opens), se_ms_to_sql($closes), (int) $round['id']]);

        return [['clue_index' => $index + 1, 'clues_total' => count($clues)], se_live_cue($live, 'whoosh')];
    });
}

/**
 * Judge the buzz the host heard (§11.7.6).
 *
 * ✔ awards the team (buzzer: points_correct; Who Am I?: by clue), reveals the
 * answer and scores the round. ✘ applies the optional penalty and locks the
 * team out; a Buzzer round then reopens for the other teams when
 * `reopen_on_wrong` is on, and a Who Am I? round waits for the next clue.
 */
function se_buzz_judge_party(PDO $pdo, array $event, int $roundId, int $buzzId, bool $correct, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'buzz_judge', static function (array $round, array $live) use ($pdo, $event, $roundId, $buzzId, $correct, $actor): array {
        $type = (string) $round['game_type'];
        if (!in_array($type, ['buzzer', 'who_am_i'], true)) {
            throw new SeRuleException('STALE_STATE', 'This round is not judged by buzz.');
        }
        if (in_array((string) $round['state'], ['revealed', 'scored', 'void'], true)) {
            throw new SeRuleException('STALE_STATE', 'This round is already over.');
        }

        $stmt = $pdo->prepare("SELECT * FROM se_buzzes WHERE id = ? AND round_id = ? AND judged = 'pending' FOR UPDATE");
        $stmt->execute([$buzzId, $roundId]);
        $buzz = $stmt->fetch();
        if (!$buzz) {
            throw new SeRuleException('STALE_STATE', 'That buzz was already judged.');
        }

        $pdo->prepare("UPDATE se_buzzes SET judged = ? WHERE id = ?")->execute([$correct ? 'correct' : 'wrong', $buzzId]);

        $settings = se_game_settings($round);
        $data     = se_round_state_data($round);
        $teamId   = (int) $buzz['team_id'];
        $reason   = !empty($round['is_test']) ? se_score_test_reason() : null;

        if ($correct) {
            $points = $type === 'who_am_i'
                ? se_who_am_i_points((array) ($settings['points_by_clue'] ?? []), (int) ($data['clue_index'] ?? 0))
                : (int) ($settings['points_correct'] ?? 300);
            $points = se_weighted_points($points, $round['weight'] ?? 1);

            if ($points > 0) {
                se_score_insert($pdo, $event, [
                    'scope' => 'team', 'team_id' => $teamId, 'game_id' => (int) $round['game_id'], 'round_id' => $roundId,
                    'kind' => 'auto', 'points' => $points, 'reason' => $reason ?? 'Correct buzz',
                    'idempotency_key' => 'r:' . $roundId . ':t:' . $teamId,
                ], $actor);
            }

            // Everybody still waiting in the queue is no longer in the running.
            $pdo->prepare("UPDATE se_buzzes SET judged = 'ignored' WHERE round_id = ? AND judged = 'pending'")->execute([$roundId]);

            $result = se_round_reveal_result($pdo, $event, $round) + [
                'winner_team_id' => $teamId,
                'team_points'    => [(string) $teamId => $points],
            ];
            $data['winner_team_id'] = $teamId;
            $pdo->prepare(
                "UPDATE se_rounds SET state = 'scored', state_json = ?, result_json = ?,
                        locked_at = COALESCE(locked_at, NOW(3)), revealed_at = NOW(3)
                  WHERE id = ?"
            )->execute([se_json_encode($data), se_json_encode($result), $roundId]);

            return [['correct' => true, 'team_id' => $teamId, 'points' => $points, 'round_state' => 'scored'], se_live_cue($live, 'correct')];
        }

        // Wrong.
        $penalty = (int) ($settings['wrong_penalty'] ?? 0);
        if ($penalty > 0) {
            se_score_insert($pdo, $event, [
                'scope' => 'team', 'team_id' => $teamId, 'game_id' => (int) $round['game_id'], 'round_id' => $roundId,
                'kind' => 'penalty', 'points' => -$penalty, 'reason' => $reason ?? 'Wrong buzz',
                'idempotency_key' => 'p:' . $roundId . ':t:' . $teamId . ':a:' . (int) $round['attempt'],
            ], $actor);
        }

        $locked = array_values(array_unique(array_merge(array_map('intval', (array) ($data['locked_out'] ?? [])), [$teamId])));
        $data['locked_out'] = $locked;

        $reopened = false;
        if ($type === 'buzzer' && !empty($settings['reopen_on_wrong'])) {
            $teams = se_teams_ready($pdo) ? count(se_teams($pdo, (int) $event['id'])) : 0;
            if ($teams > count($locked)) {
                // A fresh window for everybody not yet locked out (§11.7.6).
                $opens  = se_epoch_ms() + 1500;
                $closes = $opens + (int) ($settings['reopen_window_ms'] ?? 10000);
                $pdo->prepare(
                    "UPDATE se_rounds SET state = 'armed', attempt = attempt + 1, state_json = ?, opens_at = ?, closes_at = ?, locked_at = NULL
                      WHERE id = ?"
                )->execute([se_json_encode($data), se_ms_to_sql($opens), se_ms_to_sql($closes), $roundId]);
                $reopened = true;
            }
        }
        if (!$reopened) {
            se_round_state_save($pdo, $roundId, $data);
        }

        return [['correct' => false, 'team_id' => $teamId, 'reopened' => $reopened, 'round_state' => (string) $round['state']], se_live_cue($live, 'wrong')];
    });
}

// --------------------------------------------------------------------------
// Bible Charades (§11.9)
// --------------------------------------------------------------------------

/**
 * Pick a presenter on a team: by player number ("#47" or 47) or at random
 * among members checked in tonight whose phone was seen in the last two
 * minutes.
 */
function se_presenter_find(PDO $pdo, array $event, int $teamId, int|string $player): array
{
    $day  = se_game_day($pdo, $event);
    $args = [$day, (int) $event['id'], $teamId];
    $tail = 'ORDER BY RAND() LIMIT 1';

    if ($player !== 'random') {
        $number = (int) ltrim(trim((string) $player), '#');
        if ($number <= 0) {
            throw new SeValidationException(['player_no' => 'Type the player number, or choose Random.']);
        }
        $tail   = 'AND r.player_no = ? LIMIT 1';
        $args[] = $number;
    }

    $stmt = $pdo->prepare(
        "SELECT r.id, r.display_name, r.player_no, r.team_id,
                MAX(d.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)) AS active_phone
           FROM se_registrations r
           JOIN se_checkins c ON c.registration_id = r.id AND c.event_id = r.event_id AND c.day_date = ?
           LEFT JOIN se_devices d ON d.registration_id = r.id AND d.event_id = r.event_id AND d.mode = 'full'
          WHERE r.event_id = ? AND r.team_id = ? AND r.status = 'confirmed'
          GROUP BY r.id, r.display_name, r.player_no, r.team_id
         HAVING " . ($player === 'random' ? 'active_phone = 1 ' : '1 = 1 ') . $tail
    );
    $stmt->execute($args);
    $presenter = $stmt->fetch();

    if (!$presenter) {
        throw new SeRuleException('PRESENTER_UNAVAILABLE', $player === 'random'
            ? 'Nobody on that team has a phone in the games right now — type a player number instead.'
            : 'That player number is not checked in on this team.');
    }

    return $presenter;
}

/**
 * Every phrase this game has already shown, in any turn (§11.9.6 "never
 * repeated in the event"). A phrase counts as used the moment it reached the
 * presenter's phone, guessed or not.
 */
function se_charades_used_items(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("SELECT state_json FROM se_rounds WHERE game_id = ? AND state <> 'pending'");
    $stmt->execute([$gameId]);

    $used = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $data = se_json_decode($json);
        foreach ((array) ($data['words'] ?? []) as $word) {
            $used[(int) ($word['item_id'] ?? 0)] = true;
        }
        if (!empty($data['current_item_id'])) {
            $used[(int) $data['current_item_id']] = true;
        }
    }
    unset($used[0]);

    return array_keys($used);
}

/** The next phrase in the game's order that nobody has seen yet. */
function se_charades_next_item(PDO $pdo, int $gameId, array $used): ?int
{
    $stmt = $pdo->prepare(
        "SELECT gi.deck_item_id FROM se_game_items gi
           JOIN se_deck_items i ON i.id = gi.deck_item_id AND i.review_status = 'approved'
          WHERE gi.game_id = ? ORDER BY gi.sort_order, gi.deck_item_id"
    );
    $stmt->execute([$gameId]);
    $seen = array_flip(array_map('intval', $used));

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
        if (!isset($seen[(int) $itemId])) {
            return (int) $itemId;
        }
    }

    return null;
}

/** Next turn: the acting team and its presenter (§11.9.1). */
function se_charades_turn(PDO $pdo, array $event, int $roundId, int $teamId, int|string $player, bool $showOnConsole, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_turn', static function (array $round) use ($pdo, $event, $teamId, $player, $showOnConsole): array {
        if ((string) $round['game_type'] !== 'charades' || !in_array((string) $round['state'], ['pending', 'armed'], true)
            || !empty($round['opens_at'])) {
            throw new SeRuleException('STALE_STATE', 'This turn has already started — end it, then take the next turn.');
        }
        if (!se_team_find($pdo, (int) $event['id'], $teamId)) {
            throw new SeValidationException(['team_id' => 'Choose the acting team.']);
        }

        $presenter = se_presenter_find($pdo, $event, $teamId, $player);
        $next      = se_charades_next_item($pdo, (int) $round['game_id'], se_charades_used_items($pdo, (int) $round['game_id']));
        if ($next === null) {
            throw new SeRuleException('NO_ITEMS', 'Every phrase in this game has been used. Add more in Studio → Games.');
        }

        $data = [
            'words'           => [],
            'passes'          => 0,
            'current_item_id' => $next,
            'show_on_console' => $showOnConsole,
        ];
        $pdo->prepare(
            "UPDATE se_rounds SET state = 'armed', team_id = ?, presenter_registration_id = ?, state_json = ?,
                    arm_at = NOW(3), opens_at = NULL, closes_at = NULL
              WHERE id = ?"
        )->execute([$teamId, (int) $presenter['id'], se_json_encode($data), (int) $round['id']]);

        return [[
            'presenter' => [
                'registration_id' => (int) $presenter['id'],
                'display_name'    => (string) $presenter['display_name'],
                'player_no'       => $presenter['player_no'] !== null ? (int) $presenter['player_no'] : null,
                'active_phone'    => (bool) $presenter['active_phone'],
            ],
        ], []];
    });
}

/** Start the timer (§11.9.3). */
function se_charades_start(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_start', static function (array $round, array $live) use ($pdo): array {
        if ((string) $round['game_type'] !== 'charades' || (string) $round['state'] !== 'armed'
            || empty($round['presenter_registration_id']) || !empty($round['opens_at'])) {
            throw new SeRuleException('STALE_STATE', 'Pick the presenter first.');
        }

        $settings = se_game_settings($round);
        $opens    = se_epoch_ms() + 1500;
        $closes   = $opens + (int) ($settings['turn_ms'] ?? 60000);

        $pdo->prepare("UPDATE se_rounds SET opens_at = ?, closes_at = ? WHERE id = ?")
            ->execute([se_ms_to_sql($opens), se_ms_to_sql($closes), (int) $round['id']]);

        return [['opens_ms' => $opens, 'closes_ms' => $closes], se_live_cue($live, 'arm')];
    });
}

/**
 * ✔ Got it / Pass (§11.9.4): the result is recorded, a correct word scores
 * the acting team, and the presenter's phone moves to the next phrase.
 * A couple of seconds after the buzzer is allowed for the last tap.
 */
function se_charades_mark(PDO $pdo, array $event, int $roundId, string $result, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_mark', static function (array $round, array $live) use ($pdo, $event, $roundId, $result, $actor): array {
        if ((string) $round['game_type'] !== 'charades' || !in_array($result, ['correct', 'pass'], true)) {
            throw new SeRuleException('STALE_STATE', 'That mark is not available.');
        }
        if (!in_array((string) $round['state'], ['armed', 'open', 'locked'], true) || empty($round['opens_at'])) {
            throw new SeRuleException('TOO_EARLY', 'Start the timer first.');
        }

        $now    = se_epoch_ms();
        $closes = (int) se_sql_to_ms($round['closes_at']);
        if ($now > $closes + 3000) {
            throw new SeRuleException('ROUND_CLOSED', "Time's up — end the turn.");
        }

        $data     = se_round_state_data($round);
        $settings = se_game_settings($round);
        $current  = (int) ($data['current_item_id'] ?? 0);
        if ($current <= 0) {
            throw new SeRuleException('NO_ITEMS', 'There is no phrase on the presenter’s phone.');
        }
        if ($result === 'pass' && (int) ($data['passes'] ?? 0) >= (int) ($settings['max_passes'] ?? 2)) {
            throw new SeRuleException('PASS_LIMIT', 'No passes left this turn.');
        }

        $data['words'][] = ['item_id' => $current, 'result' => $result];
        $number = count($data['words']);

        $points = 0;
        if ($result === 'pass') {
            $data['passes'] = (int) ($data['passes'] ?? 0) + 1;
        } else {
            $points = se_weighted_points((int) ($settings['points_per_word'] ?? 200), $round['weight'] ?? 1);
            if ($points > 0) {
                se_score_insert($pdo, $event, [
                    'scope' => 'team', 'team_id' => (int) $round['team_id'], 'game_id' => (int) $round['game_id'],
                    'round_id' => $roundId, 'kind' => 'auto', 'points' => $points,
                    'reason' => !empty($round['is_test']) ? se_score_test_reason() : 'Charades',
                    'idempotency_key' => 'c:' . $roundId . ':w:' . $number,
                ], $actor);
            }
        }

        // The phrase just marked is used; the next unseen one goes up.
        $used = array_merge(se_charades_used_items($pdo, (int) $round['game_id']), array_column($data['words'], 'item_id'));
        $data['current_item_id'] = se_charades_next_item($pdo, (int) $round['game_id'], $used);
        se_round_state_save($pdo, $roundId, $data);

        $done = count(array_filter($data['words'], static fn(array $w): bool => $w['result'] === 'correct'));

        return [
            ['words_done' => $done, 'passes' => (int) ($data['passes'] ?? 0), 'points' => $points, 'has_next' => $data['current_item_id'] !== null],
            se_live_cue($live, $result === 'correct' ? 'ding' : 'whoosh'),
        ];
    });
}

/** End the turn (§11.9.5): the round is scored; its points are already on the ledger. */
function se_charades_end(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_end', static function (array $round, array $live) use ($pdo): array {
        if ((string) $round['game_type'] !== 'charades' || !in_array((string) $round['state'], ['armed', 'open', 'locked'], true)) {
            throw new SeRuleException('STALE_STATE', 'That turn is already over.');
        }

        $data = se_round_state_data($round);
        $done = count(array_filter((array) ($data['words'] ?? []), static fn(array $w): bool => ($w['result'] ?? '') === 'correct'));
        $result = ['words_done' => $done, 'team_id' => $round['team_id'] !== null ? (int) $round['team_id'] : null];

        $pdo->prepare(
            "UPDATE se_rounds SET state = 'scored', result_json = ?, locked_at = COALESCE(locked_at, NOW(3)), revealed_at = NOW(3)
              WHERE id = ?"
        )->execute([se_json_encode($result), (int) $round['id']]);

        return [['state' => 'scored', 'words_done' => $done], se_live_cue($live, 'applause')];
    });
}

/** The console's charades block: the phrase (the host may need it), the presenter. */
function se_charades_console(PDO $pdo, array $event, array $round): array
{
    $data      = se_round_state_data($round);
    $item      = !empty($data['current_item_id']) ? se_deck_item_by_id($pdo, (int) $data['current_item_id']) : null;
    $presenter = !empty($round['presenter_registration_id'])
        ? se_registration_by_id($pdo, (int) $round['presenter_registration_id'], (int) $event['id'])
        : null;

    return [
        'presenter'       => $presenter ? [
            'display_name' => (string) $presenter['display_name'],
            'player_no'    => $presenter['player_no'] !== null ? (int) $presenter['player_no'] : null,
        ] : null,
        'phrase'          => $item ? (string) ($item['payload']['phrase'] ?? '') : null,
        'category'        => $item ? (string) ($item['payload']['category'] ?? '') : null,
        'hint'            => $item ? (string) ($item['payload']['hint'] ?? '') : null,
        'show_on_console' => !empty($data['show_on_console']),
        'words'           => (array) ($data['words'] ?? []),
        'passes'          => (int) ($data['passes'] ?? 0),
    ];
}

/**
 * The secret card for the presenter's own phone (§11.9.2) — and nobody
 * else's: the registration must be this turn's presenter AND the phone must
 * be bound to it in full mode.
 */
function se_charades_presenter_payload(PDO $pdo, array $event, int $registrationId, ?array $device = null): ?array
{
    if (!se_game_ready($pdo)) {
        return null;
    }
    if ($device !== null && (($device['mode'] ?? '') !== 'full' || (int) ($device['registration_id'] ?? 0) !== $registrationId)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT r.*, g.settings_json, g.type AS game_type
           FROM se_rounds r JOIN se_games g ON g.id = r.game_id
          WHERE r.event_id = ? AND g.type = 'charades' AND r.presenter_registration_id = ? AND r.state IN ('armed','open','locked')
          ORDER BY r.id DESC LIMIT 1"
    );
    $stmt->execute([(int) $event['id'], $registrationId]);
    $round = $stmt->fetch();
    if (!$round) {
        return null;
    }

    $data = se_round_state_data($round);
    $item = !empty($data['current_item_id']) ? se_deck_item_by_id($pdo, (int) $data['current_item_id']) : null;

    return [
        'round_id'   => (int) $round['id'],
        'phrase'     => $item ? (string) ($item['payload']['phrase'] ?? '') : null,
        'category'   => $item ? (string) ($item['payload']['category'] ?? '') : '',
        'hint'       => $item ? (string) ($item['payload']['hint'] ?? '') : '',
        'words_done' => count(array_filter((array) ($data['words'] ?? []), static fn(array $w): bool => ($w['result'] ?? '') === 'correct')),
        'opens_ms'   => se_sql_to_ms($round['opens_at'] ?? null),
        'closes_ms'  => se_sql_to_ms($round['closes_at'] ?? null),
    ];
}

// --------------------------------------------------------------------------
// Family Feud — the survey (§11.10.1)
// --------------------------------------------------------------------------

/** Normalise a survey answer for grouping: case, punctuation, articles, a simple plural. */
function se_survey_normalize(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^\pL\pN\s]/u', ' ', $text) ?? '';
    $text = preg_replace('/\b(?:a|an|the)\b/u', ' ', $text) ?? $text;
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if (mb_strlen($text) > 3 && str_ends_with($text, 's') && !str_ends_with($text, 'ss')) {
        $text = mb_substr($text, 0, -1);
    }

    return $text;
}

/** Is the Feud survey still taking answers? It closes when the Feud starts. */
function se_survey_open(PDO $pdo, array $event): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_games WHERE event_id = ? AND type = 'feud' AND status IN ('live','paused','finished')");
    $stmt->execute([(int) $event['id']]);

    return (int) $stmt->fetchColumn() === 0;
}

/** One guest's survey answer (one per person per question, editable). */
function se_survey_save(PDO $pdo, array $event, array $registration, int $itemId, string $text): array
{
    $text = se_line($text, 60);
    if ($text === '') {
        throw new SeValidationException(['text' => 'Type an answer (60 characters at most).']);
    }
    if (preg_match('/\b(?:fuck\w*|shit\w*|bitch\w*|bastard\w*|asshole\w*)\b/iu', $text)) {
        throw new SeValidationException(['text' => 'Please keep it friendly for the room.']);
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM se_game_items gi JOIN se_games g ON g.id = gi.game_id
          WHERE gi.deck_item_id = ? AND g.event_id = ? AND g.type = 'feud' LIMIT 1"
    );
    $stmt->execute([$itemId, (int) $event['id']]);
    if (!$stmt->fetchColumn()) {
        throw new SeNotFoundException('That survey question has gone.');
    }
    if (!se_survey_open($pdo, $event)) {
        throw new SeRuleException('ROUND_CLOSED', 'The survey has closed — the Feud is on!');
    }

    $test = se_bool(se_event_settings($event)['test_mode'] ?? false) ? 1 : 0;
    $pdo->prepare(
        "INSERT INTO se_survey_responses (event_id, deck_item_id, registration_id, answer_text, answer_norm, is_test)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text), answer_norm = VALUES(answer_norm), is_test = VALUES(is_test)"
    )->execute([(int) $event['id'], $itemId, (int) $registration['id'], $text, mb_substr(se_survey_normalize($text), 0, 80, 'UTF-8'), $test]);

    return ['saved' => true, 'item_id' => $itemId];
}

/** The survey questions a guest can still answer, with their own answers. */
function se_survey_questions(PDO $pdo, array $event, int $registrationId): array
{
    if (!se_game_ready($pdo) || !se_survey_open($pdo, $event)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT d.id, d.payload_json, MIN(gi.sort_order) AS sort_order,
                (SELECT s.answer_text FROM se_survey_responses s
                  WHERE s.event_id = g.event_id AND s.deck_item_id = d.id AND s.registration_id = ?) AS answer
           FROM se_games g
           JOIN se_game_items gi ON gi.game_id = g.id
           JOIN se_deck_items d ON d.id = gi.deck_item_id
          WHERE g.event_id = ? AND g.type = 'feud'
          GROUP BY d.id, d.payload_json, g.event_id
          ORDER BY sort_order"
    );
    $stmt->execute([$registrationId, (int) $event['id']]);

    return array_map(static fn(array $row): array => [
        'id'       => (int) $row['id'],
        'question' => (string) (se_json_decode($row['payload_json'])['question'] ?? ''),
        'answer'   => $row['answer'] !== null ? (string) $row['answer'] : null,
    ], $stmt->fetchAll());
}

// --------------------------------------------------------------------------
// Family Feud — boards (§11.10.2)
// --------------------------------------------------------------------------

/** One question's board, in order. */
function se_feud_board(PDO $pdo, array $event, int $itemId, bool $approvedOnly = true): array
{
    if (!se_table_exists($pdo, 'se_feud_answers') || $itemId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT id, label, points, sort_order, source, approved FROM se_feud_answers
          WHERE event_id = ? AND deck_item_id = ?" . ($approvedOnly ? ' AND approved = 1' : '') . "
          ORDER BY sort_order, id"
    );
    $stmt->execute([(int) $event['id'], $itemId]);

    return $stmt->fetchAll();
}

/** The survey questions of the event's Feud games, for Studio → Games. */
function se_feud_items(PDO $pdo, array $event): array
{
    if (!se_game_ready($pdo)) {
        return [];
    }
    $eventId = (int) $event['id'];

    $stmt = $pdo->prepare(
        "SELECT d.id, d.payload_json, MIN(gi.sort_order) AS sort_order,
                (SELECT COUNT(*) FROM se_survey_responses s WHERE s.event_id = g.event_id AND s.deck_item_id = d.id) AS responses,
                (SELECT COUNT(*) FROM se_feud_answers f WHERE f.event_id = g.event_id AND f.deck_item_id = d.id AND f.approved = 1) AS board_approved,
                (SELECT COUNT(*) FROM se_feud_answers f WHERE f.event_id = g.event_id AND f.deck_item_id = d.id) AS board_total
           FROM se_games g
           JOIN se_game_items gi ON gi.game_id = g.id
           JOIN se_deck_items d ON d.id = gi.deck_item_id
          WHERE g.event_id = ? AND g.type = 'feud'
          GROUP BY d.id, d.payload_json, g.event_id
          ORDER BY sort_order"
    );
    $stmt->execute([$eventId]);

    return array_map(static function (array $row) use ($pdo, $event): array {
        return [
            'item_id'   => (int) $row['id'],
            'question'  => (string) (se_json_decode($row['payload_json'])['question'] ?? ''),
            'responses' => (int) $row['responses'],
            'approved'  => (int) $row['board_approved'] > 0,
            'board'     => array_map(static fn(array $a): array => [
                'id' => (int) $a['id'], 'label' => (string) $a['label'], 'points' => (int) $a['points'],
                'source' => (string) $a['source'], 'approved' => (bool) $a['approved'],
            ], se_feud_board($pdo, $event, (int) $row['id'], false)),
        ];
    }, $stmt->fetchAll());
}

/** A survey question that belongs to one of the event's Feud games. */
function se_feud_item_payload(PDO $pdo, array $event, int $itemId): array
{
    $stmt = $pdo->prepare(
        "SELECT d.payload_json FROM se_deck_items d
           JOIN se_game_items gi ON gi.deck_item_id = d.id
           JOIN se_games g ON g.id = gi.game_id
          WHERE d.id = ? AND g.event_id = ? AND g.type = 'feud' LIMIT 1"
    );
    $stmt->execute([$itemId, (int) $event['id']]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        throw new SeNotFoundException('That survey question is not in a Family Feud game.');
    }

    return se_json_decode((string) $json);
}

/**
 * Draft a board from the survey (§11.10.2): AI clustering once there are
 * `min_responses` answers, otherwise the exact normalised groups. Nothing is
 * saved — the crew edits the draft and approves it with se_feud_board_save().
 */
function se_feud_build_board(PDO $pdo, array $event, int $itemId, int $actor, array $ctx = []): array
{
    $question = se_line(se_feud_item_payload($pdo, $event, $itemId)['question'] ?? '', 100);

    $stmt = $pdo->prepare("SELECT id, answer_text, answer_norm FROM se_survey_responses WHERE event_id = ? AND deck_item_id = ? ORDER BY id");
    $stmt->execute([(int) $event['id'], $itemId]);
    $responses = $stmt->fetchAll();

    $minimum = max(1, (int) (se_settings_path(se_event_settings($event), 'games.feud.min_responses', 25)));

    $groups = [];
    foreach ($responses as $response) {
        $key = (string) $response['answer_norm'];
        if ($key !== '') {
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }
    }
    arsort($groups);
    $exact = [];
    foreach (array_slice($groups, 0, 8, true) as $label => $count) {
        $exact[] = ['label' => mb_substr(mb_convert_case((string) $label, MB_CASE_TITLE, 'UTF-8'), 0, 24, 'UTF-8'), 'points' => $count, 'source' => 'survey'];
    }

    if (count($responses) < $minimum) {
        return [
            'mode'      => 'manual',
            'responses' => count($responses),
            'minimum'   => $minimum,
            'answers'   => $exact,
            'warning'   => count($responses)
                ? 'Only ' . count($responses) . ' answers so far — these are the exact groups. Edit them or type an estimated board.'
                : 'No survey answers yet. Type an estimated board, or wait for answers to come in.',
        ];
    }

    // Anonymous text only (§15.6): numbers, emails and links are scrubbed.
    $anonymous = [];
    foreach (array_slice($responses, 0, 400) as $response) {
        $text = (string) $response['answer_text'];
        $text = preg_replace('/[+\d][\d\s()\-]{7,}\d/', '[number]', $text) ?? $text;
        $text = preg_replace('/\S+@\S+\.\S+/u', '[email]', $text) ?? $text;
        $text = preg_replace('~https?://\S+~i', '[link]', $text) ?? $text;
        $anonymous[] = (int) $response['id'] . ': ' . $text;
    }

    try {
        $result = se_ai($pdo, 'feud_cluster', ['question' => $question, 'responses' => $anonymous],
            ['user_id' => $actor, 'event_id' => (int) $event['id']] + $ctx);
    } catch (SeAiException $e) {
        return [
            'mode' => 'manual', 'responses' => count($responses), 'minimum' => $minimum, 'answers' => $exact,
            'warning' => 'AI grouping is unavailable (' . $e->getMessage() . ') — these are the exact groups.',
        ];
    }

    $answers = [];
    foreach ((array) ($result['clusters'] ?? []) as $cluster) {
        $count = count((array) ($cluster['response_ids'] ?? []));
        $label = se_line($cluster['label'] ?? '', 24);
        if ($count > 0 && $label !== '') {
            $answers[] = ['label' => $label, 'points' => $count, 'source' => 'survey'];
        }
    }
    usort($answers, static fn(array $a, array $b): int => $b['points'] <=> $a['points']);
    $answers = array_slice($answers, 0, 8);

    $jobId = se_ai_job_create($pdo, (int) $event['id'], 'feud_cluster', ['item_id' => $itemId], ['answers' => $answers], $actor, 'ready');

    return ['mode' => 'ai_review', 'job_id' => $jobId, 'responses' => count($responses), 'minimum' => $minimum, 'answers' => $answers];
}

/** Save (and optionally approve) one question's board: 1–8 answers. */
function se_feud_board_save(PDO $pdo, array $event, int $itemId, array $answers, bool $approved, int $actor): array
{
    se_feud_item_payload($pdo, $event, $itemId);

    $clean = [];
    foreach ($answers as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $label = se_line($answer['label'] ?? '', 24);
        if ($label === '') {
            continue;
        }
        $clean[] = [
            'label'  => $label,
            'points' => se_int($answer['points'] ?? 0, 0, 999, 0),
            'source' => se_enum($answer['source'] ?? 'manual', ['survey', 'ai', 'manual'], 'manual'),
        ];
    }
    if (count($clean) < 1 || count($clean) > 8) {
        throw new SeValidationException(['answers' => 'A board has between one and eight answers.']);
    }
    usort($clean, static fn(array $a, array $b): int => $b['points'] <=> $a['points']);

    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare("DELETE FROM se_feud_answers WHERE event_id = ? AND deck_item_id = ?")->execute([(int) $event['id'], $itemId]);
        $insert = $pdo->prepare(
            "INSERT INTO se_feud_answers (event_id, deck_item_id, label, points, sort_order, source, approved) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($clean as $index => $answer) {
            $insert->execute([(int) $event['id'], $itemId, $answer['label'], $answer['points'], $index, $answer['source'], $approved ? 1 : 0]);
        }
        if ($owns) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    se_audit($pdo, (int) $event['id'], 'game_op:feud_board', ['item_id' => $itemId, 'approved' => $approved, 'answers' => count($clean)], 'deck_item', $itemId, $actor);

    return ['item_id' => $itemId, 'approved' => $approved, 'answers' => se_feud_board($pdo, $event, $itemId, false)];
}

// --------------------------------------------------------------------------
// Family Feud — play (§11.10.3)
// --------------------------------------------------------------------------

/** The public Feud block: hidden slots until revealed, no names. */
function se_feud_public(PDO $pdo, array $round, array $data): array
{
    $item     = se_round_item($pdo, $round);
    $revealed = array_map('intval', (array) ($data['revealed'] ?? []));
    $board    = se_feud_board($pdo, ['id' => (int) $round['event_id']], (int) $round['deck_item_id'], true);

    return [
        'question'     => (string) ($item['payload']['question'] ?? ''),
        'board'        => array_map(static function (array $answer, int $i) use ($revealed): array {
            $shown = in_array((int) $answer['id'], $revealed, true);
            return ['slot' => $i + 1, 'label' => $shown ? (string) $answer['label'] : null, 'points' => $shown ? (int) $answer['points'] : null, 'revealed' => $shown];
        }, $board, array_keys($board)),
        'phase'        => (string) ($data['phase'] ?? 'setup'),
        'team_a'       => isset($data['team_a']) ? (int) $data['team_a'] : null,
        'team_b'       => isset($data['team_b']) ? (int) $data['team_b'] : null,
        'control_team' => isset($data['control_team']) ? (int) $data['control_team'] : null,
        'steal_team'   => isset($data['steal_team']) ? (int) $data['steal_team'] : null,
        'strikes'      => (int) ($data['strikes'] ?? 0),
        'bank'         => (int) ($data['bank'] ?? 0),
        'multiplier'   => (int) ($data['multiplier'] ?? 1),
        'winner_team'  => isset($data['winner_team']) ? (int) $data['winner_team'] : null,
    ];
}

/** One Feud control from the console: faceoff, control, reveal, strike, steal, bank, reveal_all. */
function se_feud_update(PDO $pdo, array $event, int $roundId, string $op, array $input, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'feud_' . $op, static function (array $round, array $live) use ($pdo, $event, $roundId, $op, $input, $actor): array {
        if ((string) $round['game_type'] !== 'feud') {
            throw new SeRuleException('STALE_STATE', 'This is not a Family Feud round.');
        }
        if (in_array((string) $round['state'], ['void'], true) || ((string) $round['state'] === 'scored' && $op !== 'reveal_all')) {
            throw new SeRuleException('STALE_STATE', 'This board is finished. Take the next round.');
        }

        $board = se_feud_board($pdo, $event, (int) $round['deck_item_id'], true);
        if (!$board) {
            throw new SeRuleException('BOARD_NOT_APPROVED', 'Approve this board in Studio → Games before playing it.');
        }

        $data = se_round_state_data($round);
        $cue  = null;
        $settings = se_game_settings($round);
        $multipliers = (array) ($settings['round_multipliers'] ?? [1, 1, 2, 3]);
        $data['multiplier'] = max(1, (int) ($multipliers[max(0, (int) $round['round_no'] - 1)] ?? end($multipliers) ?: 1));

        switch ($op) {
            case 'faceoff':
                $a = se_int($input['team_a'] ?? 0, 0);
                $b = se_int($input['team_b'] ?? 0, 0);
                if (!$a || !$b || $a === $b) {
                    throw new SeValidationException(['team_a' => 'Choose two different teams.']);
                }
                $repA = se_presenter_find($pdo, $event, $a, ($input['rep_a'] ?? '') !== '' ? (string) $input['rep_a'] : 'random');
                $repB = se_presenter_find($pdo, $event, $b, ($input['rep_b'] ?? '') !== '' ? (string) $input['rep_b'] : 'random');

                $data = [
                    'phase' => 'faceoff', 'team_a' => $a, 'team_b' => $b,
                    'rep_a' => (int) $repA['id'], 'rep_b' => (int) $repB['id'],
                    'control_team' => null, 'steal_team' => null, 'revealed' => [], 'strikes' => 0, 'bank' => 0,
                    'multiplier' => $data['multiplier'],
                ];
                $opens  = se_epoch_ms() + 1500;
                $closes = $opens + (int) ($settings['faceoff_window_ms'] ?? 15000);
                $pdo->prepare(
                    "UPDATE se_rounds SET state = 'armed', team_id = ?, opponent_team_id = ?, attempt = attempt + 1,
                            opens_at = ?, closes_at = ?, arm_at = COALESCE(arm_at, NOW(3)), locked_at = NULL
                      WHERE id = ?"
                )->execute([$a, $b, se_ms_to_sql($opens), se_ms_to_sql($closes), $roundId]);
                se_deck_item_mark_used($pdo, $round, (int) $event['id']);
                $cue = 'arm';
                break;

            case 'control':
                $team = se_int($input['team_id'] ?? 0, 0);
                if (!in_array($team, [(int) ($data['team_a'] ?? 0), (int) ($data['team_b'] ?? 0)], true)) {
                    throw new SeValidationException(['team_id' => 'Control goes to one of the two face-off teams.']);
                }
                $data['phase'] = 'control';
                $data['control_team'] = $team;
                $data['strikes'] = 0;
                break;

            case 'reveal':
                $answerId = se_int($input['answer_id'] ?? 0, 0);
                $match = null;
                foreach ($board as $answer) {
                    if ((int) $answer['id'] === $answerId) {
                        $match = $answer;
                    }
                }
                if (!$match) {
                    throw new SeNotFoundException('That answer is not on this board.');
                }
                if (!in_array($answerId, array_map('intval', (array) ($data['revealed'] ?? [])), true)) {
                    $data['revealed'][] = $answerId;
                    // Answers turned over after the bank are for fun only.
                    if (empty($data['banked'])) {
                        $data['bank'] = (int) ($data['bank'] ?? 0) + (int) $match['points'];
                    }
                }
                $cue = 'ding';
                break;

            case 'strike':
                if (($data['phase'] ?? '') !== 'control') {
                    throw new SeRuleException('STALE_STATE', 'Give a team control first.');
                }
                $data['strikes'] = min(3, (int) ($data['strikes'] ?? 0) + 1);
                if ($data['strikes'] >= 3) {
                    $data['phase'] = 'steal';
                    $data['steal_team'] = (int) $data['control_team'] === (int) $data['team_a'] ? (int) $data['team_b'] : (int) $data['team_a'];
                }
                $cue = 'strike';
                break;

            case 'steal':
                if (($data['phase'] ?? '') !== 'steal') {
                    throw new SeRuleException('STALE_STATE', 'A steal comes after three strikes.');
                }
                $data['steal_success'] = se_bool($input['success'] ?? false);
                $data['phase'] = 'bank';
                $cue = $data['steal_success'] ? 'correct' : 'wrong';
                break;

            case 'bank':
                if (!empty($data['banked'])) {
                    throw new SeRuleException('ALREADY_SCORED', 'This board is already banked.');
                }
                $winner = !empty($data['steal_success']) ? (int) ($data['steal_team'] ?? 0) : (int) ($data['control_team'] ?? 0);
                if (!$winner) {
                    throw new SeRuleException('NO_CONTROL', 'Give a team control before banking.');
                }
                $points = se_weighted_points(se_feud_bank_points([(int) ($data['bank'] ?? 0)], (int) $data['multiplier']), $round['weight'] ?? 1);
                if ($points > 0) {
                    se_score_insert($pdo, $event, [
                        'scope' => 'team', 'team_id' => $winner, 'game_id' => (int) $round['game_id'], 'round_id' => $roundId,
                        'kind' => 'auto', 'points' => $points,
                        'reason' => !empty($round['is_test']) ? se_score_test_reason() : 'Family Feud',
                        'idempotency_key' => 'f:' . $roundId . ':bank',
                    ], $actor);
                }
                $data['banked'] = true;
                $data['phase'] = 'done';
                $data['winner_team'] = $winner;
                $data['total'] = $points;
                $pdo->prepare(
                    "UPDATE se_rounds SET state = 'scored', result_json = ?, locked_at = COALESCE(locked_at, NOW(3)), revealed_at = NOW(3)
                      WHERE id = ?"
                )->execute([se_json_encode(['winner_team_id' => $winner, 'team_points' => [(string) $winner => $points]]), $roundId]);
                $cue = 'applause';
                break;

            case 'reveal_all':
                $data['revealed'] = array_map(static fn(array $a): int => (int) $a['id'], $board);
                $cue = 'reveal';
                break;

            default:
                throw new SeValidationException(['op' => 'Unknown Feud control.']);
        }

        se_round_state_save($pdo, $roundId, $data);

        return [['feud' => se_feud_public($pdo, se_round_find($pdo, (int) $event['id'], $roundId) ?? $round, $data)], $cue ? se_live_cue($live, $cue) : []];
    });
}

// --------------------------------------------------------------------------
// Leaderboards, MVP and the finale (§11.11)
// --------------------------------------------------------------------------

/**
 * Everything the finale and the recap need, WITH names (the MVP). Only the
 * console, the key-protected room snapshot, cards and the recap read this;
 * public.json gets se_finale_public() instead.
 */
function se_finale_payload(PDO $pdo, array $event): array
{
    $eventId = (int) $event['id'];
    $boards  = se_leaderboards($pdo, $event, 5);
    $scores  = array_column($boards['teams'], null, 'team_id');

    $teams = [];
    if (se_teams_ready($pdo)) {
        foreach (se_teams($pdo, $eventId) as $team) {
            $row = $scores[(int) $team['id']] ?? ['points' => 0, 'rank' => null];
            $teams[] = [
                'id'     => (int) $team['id'],
                'name'   => (string) ($team['name'] !== null && $team['name'] !== '' ? $team['name'] : 'Team ' . $team['color_label']),
                'hex'    => (string) $team['color_hex'],
                'points' => (int) $row['points'],
                'rank'   => $row['rank'] !== null ? (int) $row['rank'] : null,
            ];
        }
    }
    usort($teams, static fn(array $a, array $b): int => [$b['points'], $a['id']] <=> [$a['points'], $b['id']]);

    $mvp = [];
    foreach ($boards['mvp'] as $row) {
        $reg = se_registration_by_id($pdo, (int) $row['registration_id'], $eventId);
        if ($reg) {
            $mvp[] = [
                'registration_id' => (int) $row['registration_id'],
                'display_name'    => (string) $reg['display_name'],
                'team_id'         => $reg['team_id'] !== null ? (int) $reg['team_id'] : null,
                'points'          => (int) $row['points'],
            ];
        }
    }

    $champion = ($teams[0]['points'] ?? 0) > 0 ? $teams[0] : null;

    return ['teams' => $teams, 'champion' => $champion, 'mvp' => $mvp, 'mvp_winner' => $mvp[0] ?? null, 'awards' => se_named_awards($pdo, $event)];
}

/** The finale as public.json may show it: teams and the champion, no people. */
function se_finale_public(array $finale): array
{
    return [
        'teams'    => array_map(static fn(array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'hex' => $t['hex'], 'points' => $t['points'], 'rank' => $t['rank'],
        ], $finale['teams']),
        'champion' => $finale['champion'] ? [
            'id' => $finale['champion']['id'], 'name' => $finale['champion']['name'],
            'hex' => $finale['champion']['hex'], 'points' => $finale['champion']['points'],
        ] : null,
        'has_mvp'  => !empty($finale['mvp_winner']),
    ];
}

/** Named crew awards (team awards with a reason), for chips and the recap. */
function se_named_awards(PDO $pdo, array $event): array
{
    if (!se_table_exists($pdo, 'se_score_events')) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT id, scope, team_id, points, reason, created_at FROM se_score_events
          WHERE event_id = ? AND kind = 'award' AND voided_at IS NULL AND reason IS NOT NULL
            AND reason <> 'TEST' AND reason NOT LIKE 'TEST: %'
          ORDER BY id"
    );
    $stmt->execute([(int) $event['id']]);

    return array_map(static fn(array $row): array => [
        'id'      => (int) $row['id'],
        'scope'   => (string) $row['scope'],
        'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
        'points'  => (int) $row['points'],
        'reason'  => (string) $row['reason'],
    ], $stmt->fetchAll());
}

/**
 * Console → Finale (§11.11): the stage counts the teams down to the champion
 * with a fanfare; the MVP is revealed from the room snapshot.
 */
function se_finale_start(PDO $pdo, array $event, ?int $expected, int $actor): array
{
    $finale = se_finale_payload($pdo, $event);
    if (!$finale['champion']) {
        throw new SeRuleException('NO_SCORES', 'Score at least one round before the finale.');
    }

    $state = se_live_mutate($pdo, $event, $expected, static fn(array $live): array => [
        'scene'              => 'finale',
        'scene_payload_json' => se_json_encode(['since_ms' => se_epoch_ms(), 'payload' => se_finale_public($finale)]),
    ] + se_live_cue($live, 'fanfare'), 'scene_set:finale', ['champion_team_id' => $finale['champion']['id']], $actor);

    return ['version' => (int) $state['version'], 'finale' => $finale];
}

// --------------------------------------------------------------------------
// Ready-made games: the Chara starter pack (Appendix G)
// --------------------------------------------------------------------------

/**
 * The question banks behind the ready-made games. Every item is reviewed
 * content: references are real, and the Feud questions carry an estimated
 * board (what a hundred people would plausibly say) so the game can be
 * played even before any guest has answered the survey. The crew can
 * rebuild a board from the guests' own answers at any time.
 */
function se_chara_starter_sets(): array
{
    return [
        'quiz' => ['title' => 'Chara · Bible quiz', 'type' => 'mcq', 'items' => [
            [['prompt' => 'Who was swallowed by a great fish?', 'choices' => ['Jonah', 'Elijah', 'Peter', 'Noah'], 'answer_index' => 0,
              'explanation' => 'God prepared a great fish to swallow Jonah — three days and three nights.'], 'Jonah 1:17'],
            [['prompt' => 'For how many days and nights did it rain during the flood?', 'choices' => ['7', '12', '40', '100'], 'answer_index' => 2], 'Genesis 7:12'],
            [['prompt' => 'Who was the mother of the prophet Samuel?', 'choices' => ['Hannah', 'Ruth', 'Sarah', 'Elizabeth'], 'answer_index' => 0], '1 Samuel 1:20'],
            [['prompt' => 'At the wedding in Cana, what did Jesus turn water into?', 'choices' => ['Milk', 'Wine', 'Oil', 'Honey'], 'answer_index' => 1], 'John 2:9'],
            [['prompt' => 'Which king asked God for an understanding heart?', 'choices' => ['David', 'Saul', 'Solomon', 'Hezekiah'], 'answer_index' => 2], '1 Kings 3:9'],
            [['prompt' => 'Which disciple would not believe until he saw the wounds of Jesus?', 'choices' => ['Peter', 'Thomas', 'James', 'Andrew'], 'answer_index' => 1], 'John 20:27'],
            [['prompt' => 'What did God create on the first day?', 'choices' => ['The sun', 'Animals', 'Light', 'Man'], 'answer_index' => 2], 'Genesis 1:3'],
            [['prompt' => 'For how many pieces of silver did Judas betray Jesus?', 'choices' => ['30', '20', '10', '40'], 'answer_index' => 0], 'Matthew 26:15'],
        ]],
        // Finish the verse, with answer tiles for the phones. The words come
        // from kjv_bundle.php, never typed here (§15.9).
        'quiz_verses' => ['title' => 'Chara · Finish the verse (quiz)', 'type' => 'verse', 'items' => [
            [se_chara_verse_payload('John 3:16', 'For God so loved the world, that he gave his only begotten Son, that whosoever believeth in him should not perish, but',
                ['be saved from sin.', 'see the kingdom of God.', 'walk in the light.'], 2), 'John 3:16'],
            [se_chara_verse_payload('Psalm 118:24', 'This is the day which the LORD hath made;',
                ['we will sing and dance before him.', 'let the whole earth praise him.', 'his light shall shine upon us.'], 1), 'Psalm 118:24'],
        ]],
        // Trivia has its own questions, so a night with both games never
        // asks the same one twice.
        'trivia' => ['title' => 'Chara · Bible trivia', 'type' => 'mcq', 'items' => [
            [['prompt' => 'Who led the Israelites out of Egypt?', 'choices' => ['Aaron', 'Moses', 'Joshua', 'Abraham'], 'answer_index' => 1], 'Exodus 3:10'],
            [['prompt' => 'What did Esau sell to Jacob for a meal of bread and lentils?', 'choices' => ['His coat', 'His flock', 'His birthright', 'His bow'], 'answer_index' => 2], 'Genesis 25:34'],
            [['prompt' => 'How many loaves did the boy bring when Jesus fed the five thousand?', 'choices' => ['2', '5', '7', '12'], 'answer_index' => 1], 'John 6:9'],
            [['prompt' => 'Which disciple was a tax collector when Jesus called him to follow?', 'choices' => ['Andrew', 'Thomas', 'Luke', 'Matthew'], 'answer_index' => 3], 'Matthew 9:9'],
            [['prompt' => 'Who lived longer than anyone else in the Bible?', 'choices' => ['Adam', 'Noah', 'Methuselah', 'Abraham'], 'answer_index' => 2], 'Genesis 5:27'],
            [['prompt' => 'Who was the first king of Israel?', 'choices' => ['Saul', 'David', 'Solomon', 'Samuel'], 'answer_index' => 0], '1 Samuel 10:24'],
            [['prompt' => 'On which mountain did God give Moses the Ten Commandments?', 'choices' => ['Mount Carmel', 'Mount of Olives', 'Mount Zion', 'Mount Sinai'], 'answer_index' => 3], 'Exodus 31:18'],
            [['prompt' => 'How many tribes of Israel were there?', 'choices' => ['7', '10', '12', '14'], 'answer_index' => 2], 'Genesis 49:28'],
        ]],
        'buzz' => ['title' => 'Chara · Buzzer round', 'type' => 'open', 'items' => [
            [['prompt' => 'Which disciple denied Jesus three times?', 'answer' => 'Peter', 'accept' => ['peter', 'simon peter', 'simon']], 'Luke 22:61'],
            [['prompt' => 'Which city\'s walls fell after the people shouted?', 'answer' => 'Jericho', 'accept' => ['jericho']], 'Joshua 6:20'],
            [['prompt' => 'Who was thrown into a den of lions for praying?', 'answer' => 'Daniel', 'accept' => ['daniel']], 'Daniel 6:16'],
        ]],
        // Finish the verse out loud: the host reads the start and judges the
        // ending the team says.
        'buzz_verses' => ['title' => 'Chara · Finish the verse (buzzer)', 'type' => 'verse', 'items' => [
            [se_chara_verse_payload('Psalm 23:1', 'The LORD is my shepherd;'), 'Psalm 23:1'],
            [se_chara_verse_payload('Philippians 4:4', 'Rejoice in the Lord alway:'), 'Philippians 4:4'],
            [se_chara_verse_payload('Psalm 119:105', 'Thy word is a lamp unto my feet,'), 'Psalm 119:105'],
            [se_chara_verse_payload('Matthew 6:33', 'But seek ye first the kingdom of God, and his righteousness;'), 'Matthew 6:33'],
        ]],
        'emoji' => ['title' => 'Chara · Emoji Bible', 'type' => 'emoji', 'items' => [
            [['emojis' => '🌳 🐍 👫', 'answer' => 'Adam and Eve',
              'accept' => ['adam and eve', 'the fall', 'garden of eden', 'the garden of eden', 'eden']], 'Genesis 3:6'],
            [['emojis' => '⭐ 🐫🐫🐫 🎁', 'answer' => 'The wise men',
              'accept' => ['wise men', 'the wise men', 'three wise men', 'magi', 'the magi']], 'Matthew 2:11'],
            [['emojis' => '99 🐑 ➕ 1 🐑 ❓', 'answer' => 'The lost sheep',
              'accept' => ['lost sheep', 'the lost sheep', 'parable of the lost sheep', 'the parable of the lost sheep']], 'Luke 15:4'],
        ]],
        'clues' => ['title' => 'Chara · Who Am I?', 'type' => 'clues', 'items' => [
            [['clues' => ['My father gave me a special coat', 'My brothers sold me', 'I ended up in prison in Egypt', 'I interpreted Pharaoh\'s dreams', 'I became governor over Egypt'],
              'answer' => 'Joseph', 'accept' => ['joseph']], 'Genesis 37:3'],
            [['clues' => ['I was raised by my cousin', 'I became queen in Persia', 'I asked my people to fast for three days', 'I said, "if I perish, I perish"'],
              'answer' => 'Esther', 'accept' => ['esther', 'queen esther']], 'Esther 4:16'],
            [['clues' => ['I was not an Israelite: I came from Moab', 'My great-grandson was King David', 'When my husband died, I would not leave my mother-in-law',
                          'I gleaned in the field of Boaz', 'I said, "whither thou goest, I will go"'],
              'answer' => 'Ruth', 'accept' => ['ruth']], 'Ruth 1:16'],
            [['clues' => ['I hid in a cave after a queen threatened my life', 'Ravens brought me bread and meat by a brook',
                          'A widow\'s flour and oil did not run out while I stayed with her', 'I called down fire from heaven on Mount Carmel',
                          'I went up to heaven by a whirlwind'],
              'answer' => 'Elijah', 'accept' => ['elijah', 'elias', 'prophet elijah']], '2 Kings 2:11'],
            [['clues' => ['I was born in Tarsus', 'I made tents for a living', 'I was shipwrecked on the island of Malta',
                          'I was blinded by a light on the road to Damascus', 'I was also called Saul'],
              'answer' => 'Paul', 'accept' => ['paul', 'saul', 'apostle paul', 'paul the apostle', 'saint paul', 'st paul']], 'Acts 13:9'],
        ]],
        // About four phrases a turn, two turns for each of four teams.
        'charades' => ['title' => 'Chara · Bible Charades', 'type' => 'charade', 'items' => [
            [['phrase' => 'David and Goliath', 'category' => 'story'], '1 Samuel 17:49'],
            [['phrase' => 'Zacchaeus climbing a tree', 'category' => 'story', 'hint' => 'A short man who wanted to see Jesus'], 'Luke 19:4'],
            [['phrase' => "Daniel in the lions' den", 'category' => 'story'], 'Daniel 6:16'],
            [['phrase' => 'Noah building the ark', 'category' => 'story'], 'Genesis 6:14'],
            [['phrase' => 'Jesus walking on water', 'category' => 'miracle'], 'Matthew 14:25'],
            [['phrase' => 'The parting of the Red Sea', 'category' => 'miracle'], 'Exodus 14:21'],
            [['phrase' => 'The prodigal son', 'category' => 'parable'], 'Luke 15:20'],
            [['phrase' => 'Feeding the five thousand', 'category' => 'miracle'], 'John 6:11'],
            [['phrase' => 'Samson pushing down the pillars', 'category' => 'story'], 'Judges 16:30'],
            [['phrase' => 'Moses and the burning bush', 'category' => 'story'], 'Exodus 3:2'],
            [['phrase' => "Peter cutting off a servant's ear", 'category' => 'story'], 'John 18:10'],
            [['phrase' => 'Jesus calming the storm', 'category' => 'miracle'], 'Mark 4:39'],
            [['phrase' => "Jesus washing the disciples' feet", 'category' => 'story'], 'John 13:5'],
            [['phrase' => 'Lazarus coming out of the tomb', 'category' => 'miracle'], 'John 11:44'],
            [['phrase' => 'The Good Samaritan', 'category' => 'parable'], 'Luke 10:33'],
            [['phrase' => 'The wise and foolish builders', 'category' => 'parable'], 'Matthew 7:24'],
            [['phrase' => "Jacob's ladder", 'category' => 'story', 'hint' => 'A dream of angels going up and down'], 'Genesis 28:12'],
            [['phrase' => 'The Last Supper', 'category' => 'story'], 'Luke 22:19'],
            [['phrase' => 'The Tower of Babel', 'category' => 'story', 'hint' => 'Where the languages got mixed up'], 'Genesis 11:9'],
            [['phrase' => "The widow's mite", 'category' => 'story', 'hint' => 'She gave all she had'], 'Mark 12:42'],
            [['phrase' => 'The lost coin', 'category' => 'parable'], 'Luke 15:8'],
            [['phrase' => 'The mustard seed', 'category' => 'parable'], 'Matthew 13:31'],
            [['phrase' => 'Blind Bartimaeus', 'category' => 'person', 'hint' => 'A blind beggar who cried out to Jesus'], 'Mark 10:46'],
            [['phrase' => 'Ten lepers healed', 'category' => 'miracle'], 'Luke 17:14'],
            [['phrase' => 'Abraham counting the stars', 'category' => 'story'], 'Genesis 15:5'],
            [['phrase' => "Balaam's talking donkey", 'category' => 'story', 'hint' => 'A prophet whose donkey spoke to him'], 'Numbers 22:28'],
            [['phrase' => 'Jesus entering Jerusalem', 'category' => 'story', 'hint' => 'Palm branches and Hosanna'], 'John 12:13'],
            [['phrase' => 'Fishers of men', 'category' => 'story'], 'Matthew 4:19'],
            [['phrase' => 'The empty tomb', 'category' => 'story'], 'Luke 24:2'],
            [['phrase' => 'John the Baptist', 'category' => 'person'], 'Matthew 3:1'],
            [['phrase' => 'David dancing before the Lord', 'category' => 'story'], '2 Samuel 6:14'],
            [['phrase' => 'Manna falling from heaven', 'category' => 'miracle'], 'Exodus 16:15'],
            [['phrase' => 'The fiery furnace', 'category' => 'story'], 'Daniel 3:23'],
            [['phrase' => 'Martha busy in the kitchen', 'category' => 'story'], 'Luke 10:40'],
        ]],
        'survey' => ['title' => 'Chara · Feud survey', 'type' => 'survey', 'items' => [
            [['question' => "Name something you'd find on Noah's Ark."], null,
             [['Animals', 38], ["Noah's family", 21], ['Food', 14], ['A dove', 9], ['Wood', 5]]],
            [['question' => 'Name a gospel song everyone in Lagos knows the words to.'], null,
             [['Way Maker', 29], ['Imela', 17], ['Excess Love', 13], ['Ekwueme', 11], ['Onise Iyanu', 8]]],
            [['question' => 'Name something people do at a Nigerian wedding reception.'], null,
             [['Dance', 32], ['Spray money', 24], ['Eat jollof rice', 15], ['Take photos', 9], ['Give gifts', 6]]],
            [['question' => 'Name a Bible character known for being strong.'], null,
             [['Samson', 52], ['David', 16], ['Goliath', 11], ['Joshua', 5], ['Gideon', 4]]],
            [['question' => 'Name something you bring to a church picnic.'], null,
             [['Food', 36], ['Drinks', 20], ['A mat or blanket', 14], ['Chairs', 9], ['A Bible', 6]]],
        ]],
    ];
}

/**
 * A Finish-the-verse payload cut from the bundled KJV text: `$lead` must be
 * how the verse starts, and the rest of it is the answer. `$wrong` endings
 * turn it into answer tiles, with the right one at position `$at`.
 */
function se_chara_verse_payload(string $ref, string $lead, array $wrong = [], int $at = 0): array
{
    $parsed = se_bible_ref_normalize($ref);
    $text   = $parsed !== null ? (se_kjv_bundle()[$parsed['ref_norm']] ?? null) : null;
    if ($text === null || !str_starts_with($text, $lead . ' ')) {
        throw new LogicException('Ready-made verse ' . $ref . ' does not start "' . $lead . '".');
    }

    $payload = ['lead' => $lead, 'answer' => substr($text, strlen($lead) + 1)];
    if ($wrong) {
        $choices = array_values($wrong);
        array_splice($choices, $at, 0, [$payload['answer']]);
        $payload += ['choices' => $choices, 'answer_index' => $at];
    }

    return $payload;
}

/** The ready-made games the Studio offers, keyed, in their suggested running order. */
function se_chara_starter_games(): array
{
    return [
        'live_quiz' => ['title' => 'Live Quiz',      'type' => 'live_quiz', 'decks' => ['quiz', 'quiz_verses'],
                        'blurb' => 'Everyone answers on their phone; right and fast scores most. Includes Finish the verse.'],
        'trivia'    => ['title' => 'Bible Trivia',   'type' => 'trivia',    'decks' => ['trivia'],
                        'blurb' => 'Captains lock in one answer per team while teammates suggest.'],
        'buzzer'    => ['title' => 'Buzzer round',   'type' => 'buzzer',    'decks' => ['buzz', 'buzz_verses', 'emoji'],
                        'blurb' => 'Questions, Finish the verse and emoji puzzles: first team to buzz answers out loud.'],
        'who_am_i'  => ['title' => 'Who Am I?',      'type' => 'who_am_i',  'decks' => ['clues'],
                        'blurb' => 'Clues one at a time — the earlier the right answer, the more it scores.'],
        'charades'  => ['title' => 'Bible Charades', 'type' => 'charades',  'decks' => ['charades'],
                        'blurb' => 'One player acts out phrases only their phone shows.'],
        'feud'      => ['title' => 'Family Feud',    'type' => 'feud',      'decks' => ['survey'],
                        'blurb' => 'Survey says! Boards are ready; rebuild them from your guests’ answers if you like.'],
    ];
}

/**
 * The ready-made games for the Studio's picker: what each one is, how much
 * it holds, and whether this event already has it (by title — a game the
 * crew renamed counts as not added, and adding it again is harmless).
 */
function se_chara_catalogue(PDO $pdo, array $event): array
{
    $titles = [];
    if (se_game_ready($pdo)) {
        $stmt = $pdo->prepare("SELECT title FROM se_games WHERE event_id = ?");
        $stmt->execute([(int) $event['id']]);
        $titles = array_map(static fn($t): string => mb_strtolower((string) $t), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $sets  = se_chara_starter_sets();
    $units = ['who_am_i' => 'person', 'charades' => 'phrase', 'feud' => 'board'];
    $out   = [];
    foreach (se_chara_starter_games() as $key => $game) {
        $out[] = [
            'key'        => $key,
            'title'      => $game['title'],
            'type'       => $game['type'],
            'type_label' => SE_GAME_TYPE_LABELS[$game['type']] ?? $game['type'],
            'blurb'      => $game['blurb'],
            'count'      => array_sum(array_map(static fn(string $deck): int => count($sets[$deck]['items']), $game['decks'])),
            'unit'       => $units[$key] ?? 'question',
            'added'      => in_array(mb_strtolower($game['title']), $titles, true),
        ];
    }

    return $out;
}

/**
 * Add ready-made games to an event: the ones named in `$only` (keys of
 * se_chara_starter_games()), or all six. Each brings its question bank,
 * every item approved and attached, so a rehearsal can run it straight
 * away. Safe to press again: a deck or game that already exists by title
 * is reused, an item already in a deck is not added twice, and a Feud board
 * the crew already built is never overwritten.
 */
function se_chara_starter_content(PDO $pdo, array $event, int $actor, ?array $only = null): array
{
    $sets  = se_chara_starter_sets();
    $games = se_chara_starter_games();
    if ($only !== null) {
        $games = array_intersect_key($games, array_flip(array_map('strval', $only)));
        if (!$games) {
            throw new SeValidationException(['games' => 'Choose at least one of the ready-made games.']);
        }
    }
    $sets = array_intersect_key($sets, array_flip(array_merge(...array_values(array_column($games, 'decks')))));

    $eventId = (int) $event['id'];
    $created = ['items' => 0, 'games' => 0, 'boards' => 0];
    $itemIds = [];
    $boards  = [];

    foreach ($sets as $key => $set) {
        $check = $pdo->prepare("SELECT id FROM se_decks WHERE event_id = ? AND title = ? LIMIT 1");
        $check->execute([$eventId, $set['title']]);
        $deckId = (int) ($check->fetchColumn() ?: 0);
        if (!$deckId) {
            $deckId = se_deck_save($pdo, $event, [
                'title' => $set['title'], 'content_type' => $set['type'], 'scope' => 'event',
                'description' => 'Appendix G starter content',
            ], $actor)['id'];
        }

        $itemIds[$key] = [];
        foreach ($set['items'] as $entry) {
            [$payload, $ref] = $entry;
            [$clean] = se_deck_payload_clean($set['type'], $payload);
            $exists = $pdo->prepare("SELECT id FROM se_deck_items WHERE deck_id = ? AND payload_json = ? LIMIT 1");
            $exists->execute([$deckId, se_json_encode($clean)]);
            $id = (int) ($exists->fetchColumn() ?: 0);
            if (!$id) {
                $id = se_deck_item_save($pdo, $event, [
                    'deck_id' => $deckId, 'payload' => $clean, 'scripture_ref' => $ref ?? '', 'source' => 'manual',
                ], $actor)['id'];
                $created['items']++;
            }
            $itemIds[$key][] = $id;
            if (!empty($entry[2])) {
                $boards[$id] = $entry[2];
            }
        }
    }

    foreach ($games as $spec) {
        $check = $pdo->prepare("SELECT id FROM se_games WHERE event_id = ? AND title = ? LIMIT 1");
        $check->execute([$eventId, $spec['title']]);
        if ($check->fetchColumn()) {
            continue;
        }
        $gameId = se_game_save($pdo, $event, ['title' => $spec['title'], 'type' => $spec['type']], $actor)['id'];
        $items  = array_merge(...array_map(static fn(string $deck): array => $itemIds[$deck], $spec['decks']));
        se_game_items_save($pdo, $event, ['game_id' => $gameId, 'item_ids' => $items], $actor);
        $created['games']++;
    }

    // The estimated Feud boards, once the questions are in a Feud game (a
    // board belongs to a question that game plays), and never over a board
    // the crew already built.
    foreach ($boards as $itemId => $board) {
        if (se_feud_board($pdo, $event, $itemId, false)) {
            continue;
        }
        try {
            se_feud_board_save($pdo, $event, $itemId, array_map(
                static fn(array $row): array => ['label' => $row[0], 'points' => $row[1], 'source' => 'manual'],
                $board
            ), true, $actor);
            $created['boards']++;
        } catch (SeNotFoundException $e) {
            // The question is not in a Feud game here (the crew renamed or
            // removed it); the board can be built in Studio instead.
        }
    }

    return $created + ['decks' => count($sets)];
}
