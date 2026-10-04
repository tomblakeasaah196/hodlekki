<?php
// /includes/special_events/games_engine.php
//
// The round engine (guide §11.2–§11.7): one state machine for every game,
//
//   pending ─arm─► armed ─(opens_at)─► open ─lock / closes_at─► locked ─reveal─► revealed ─score─► scored
//      └──────────────────────── void (any time; voids the round's ledger rows) ───────────────────────┘
//
// plus the player actions (answer, suggest, buzz), the heartbeat's auto-lock,
// and the three views of a round: public (no answers before the reveal, no
// names, ever), the console's private view, and each phone's own `me` block.
//
// Every host transition runs inside se_live_mutate(): the live-state row is
// locked, `expected_version` is checked (two consoles cannot both act), the
// round row is moved with `WHERE state = <expected>` (a double click cannot
// move it twice), and the screens are republished after commit.
//
// Party-game specifics (Who Am I? clues, Charades turns, Family Feud boards,
// judging a buzz, the finale) are in party_games.php; the ledger is
// scoring.php.

// --------------------------------------------------------------------------
// Timing (§8.5.5, §11.7) — pure, unit-tested
// --------------------------------------------------------------------------

/** Elapsed time for scoring: the client's claim, clamped to what is possible. */
function se_game_elapsed(int $client, int $server, int $duration): int
{
    return max(0, min($duration, min($client, $server)));
}

/** A buzz's effective time: its client time, clamped into [opens, received]. */
function se_buzz_effective(int $client, int $opens, int $received): int
{
    return max($opens, min($client, $received));
}

/** Buzzes in winning order: effective time, then arrival, then team order. */
function se_buzz_order(array $buzzes): array
{
    usort($buzzes, static fn(array $a, array $b): int =>
        [(int) $a['effective_ms'], (string) ($a['received_at'] ?? ''), (int) ($a['team_order'] ?? 0)]
        <=> [(int) $b['effective_ms'], (string) ($b['received_at'] ?? ''), (int) ($b['team_order'] ?? 0)]);

    return $buzzes;
}

// --------------------------------------------------------------------------
// Reading rounds
// --------------------------------------------------------------------------

/** A round with its game's type, settings and weight joined in. */
function se_round_find(PDO $pdo, int $eventId, int $roundId, bool $lock = false): ?array
{
    if (!se_game_ready($pdo) || $roundId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT r.*, g.type AS game_type, g.settings_json, g.weight, g.title AS game_title, g.status AS game_status
           FROM se_rounds r JOIN se_games g ON g.id = r.game_id
          WHERE r.id = ? AND r.event_id = ?" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$roundId, $eventId]);

    return $stmt->fetch() ?: null;
}

/** The deck item a round plays, with `payload` decoded and `content_type`. */
function se_round_item(PDO $pdo, array $round): ?array
{
    if (empty($round['deck_item_id'])) {
        return null;
    }

    return se_deck_item_by_id($pdo, (int) $round['deck_item_id']);
}

/** One deck item by id with `payload` and `content_type` (no visibility check). */
function se_deck_item_by_id(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, d.content_type FROM se_deck_items i JOIN se_decks d ON d.id = i.deck_id WHERE i.id = ?"
    );
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['payload'] = se_json_decode($row['payload_json'] ?? null);

    return $row;
}

/** The round's working state (clue index, lockouts, Feud board, charades words…). */
function se_round_state_data(array $round): array
{
    return se_json_decode($round['state_json'] ?? null);
}

/** The round's revealed result. */
function se_round_result(array $round): array
{
    return se_json_decode($round['result_json'] ?? null);
}

/** The game and round the host is running now, from the live state. */
function se_game_active(PDO $pdo, array $event, ?array $state = null): array
{
    $eventId = (int) $event['id'];
    $state ??= se_live_state($pdo, $eventId, false);

    $game = null;
    if (!empty($state['active_game_id'])) {
        $game = se_game_find($pdo, $eventId, (int) $state['active_game_id']);
    }
    if (!$game) {
        // Fall back to whichever game is live, so a console opened after a
        // restart still finds it.
        $stmt = $pdo->prepare("SELECT * FROM se_games WHERE event_id = ? AND status = 'live' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$eventId]);
        $game = $stmt->fetch() ?: null;
    }

    $round = null;
    if ($game) {
        if (!empty($state['active_round_id'])) {
            $round = se_round_find($pdo, $eventId, (int) $state['active_round_id']);
            if ($round && (int) $round['game_id'] !== (int) $game['id']) {
                $round = null;
            }
        }
        if (!$round) {
            $stmt = $pdo->prepare("SELECT id FROM se_rounds WHERE game_id = ? ORDER BY round_no DESC LIMIT 1");
            $stmt->execute([(int) $game['id']]);
            $latest = $stmt->fetchColumn();
            $round = $latest ? se_round_find($pdo, $eventId, (int) $latest) : null;
        }
    }

    return ['game' => $game, 'round' => $round];
}

/** Live-state columns that play a sound cue on the stage (§11.12). */
function se_live_cue(array $liveRow, string $cue): array
{
    return ['sfx_seq' => (int) ($liveRow['sfx_seq'] ?? 0) + 1, 'sfx_cue' => $cue];
}

/** The day check-ins count for right now (the live day, else today). */
function se_game_day(PDO $pdo, array $event): string
{
    return se_checkin_day(se_event_phase($event, se_event_days($pdo, (int) $event['id'])));
}

// --------------------------------------------------------------------------
// Players
// --------------------------------------------------------------------------

/**
 * The §11.3 entry rule: a phone bound in full mode to a confirmed person who
 * checked in on tonight's day. (Before PR8 this used MySQL's CURDATE(), which
 * turned every phone away after midnight on a night that ran late.)
 */
function se_game_require_player(PDO $pdo, array $event, array $reg, ?array $device): void
{
    if (!$device || ($device['mode'] ?? '') !== 'full' || (int) ($device['registration_id'] ?? 0) !== (int) $reg['id']) {
        throw new SeRuleException('DEVICE_READONLY', 'This phone is read-only. Ask the desk for a transfer code.');
    }
    if ((string) ($reg['status'] ?? '') !== 'confirmed') {
        throw new SeRuleException('NOT_CHECKED_IN', 'Check in first to join the games.');
    }
    if (!se_checkin_row($pdo, (int) $event['id'], (int) $reg['id'], se_game_day($pdo, $event))) {
        throw new SeRuleException('NOT_CHECKED_IN', 'Check in first to join the games.');
    }
}

/** Join the games: marks the device and hands back the snapshot keys. */
function se_game_join(PDO $pdo, array $event, array $reg, ?array $device = null): array
{
    se_game_require_player($pdo, $event, $reg, $device);

    $pdo->prepare("UPDATE se_devices SET joined_games_at = COALESCE(joined_games_at, NOW(3)) WHERE id = ? AND mode = 'full'")
        ->execute([(int) $device['id']]);

    $state = se_live_state($pdo, (int) $event['id']);
    $team  = !empty($reg['team_id']) ? se_team_find($pdo, (int) $event['id'], (int) $reg['team_id']) : null;

    return [
        'joined'   => true,
        'room_key' => $state['room_key'] ?? null,
        'team_key' => $team['team_key'] ?? null,
        'me_hash'  => se_registration_hash((int) $reg['id']),
    ];
}

/**
 * An opaque, stable tag for a registration, so a phone can recognise itself
 * in a team snapshot (the captain) without the snapshot ever carrying an id.
 */
function se_registration_hash(int $registrationId): string
{
    return substr(se_hmac('reg', (string) $registrationId), 0, 16);
}

/**
 * The team's captain for captain-mode play: the crew's choice, else the
 * member who joined the games first and whose phone was seen in the last two
 * minutes (§10.6.5 auto-captain).
 */
function se_game_captain_id(PDO $pdo, array $event, int $teamId): ?int
{
    $stmt = $pdo->prepare("SELECT captain_registration_id FROM se_teams WHERE id = ? AND event_id = ?");
    $stmt->execute([$teamId, (int) $event['id']]);
    $captain = (int) ($stmt->fetchColumn() ?: 0);
    if ($captain > 0) {
        return $captain;
    }

    $stmt = $pdo->prepare(
        "SELECT d.registration_id
           FROM se_devices d JOIN se_registrations r ON r.id = d.registration_id
          WHERE d.event_id = ? AND r.team_id = ? AND d.mode = 'full' AND d.joined_games_at IS NOT NULL
            AND d.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          ORDER BY d.joined_games_at, d.id
          LIMIT 1"
    );
    $stmt->execute([(int) $event['id'], $teamId]);
    $auto = (int) ($stmt->fetchColumn() ?: 0);

    return $auto > 0 ? $auto : null;
}

function se_game_is_captain(PDO $pdo, array $event, array $reg): bool
{
    return !empty($reg['team_id']) && se_game_captain_id($pdo, $event, (int) $reg['team_id']) === (int) $reg['id'];
}

/**
 * Eligible members per team, frozen at arm (§11.4): checked in tonight and
 * joined to the games. A team nobody has joined yet counts its checked-in
 * members instead, so it cannot divide by zero into free points.
 */
function se_round_eligibility(PDO $pdo, array $event): array
{
    $eventId = (int) $event['id'];
    $day     = se_game_day($pdo, $event);

    $stmt = $pdo->prepare(
        "SELECT r.team_id,
                COUNT(DISTINCT r.id) AS checked_in,
                COUNT(DISTINCT CASE WHEN d.joined_games_at IS NOT NULL THEN r.id END) AS joined
           FROM se_registrations r
           JOIN se_checkins c ON c.registration_id = r.id AND c.event_id = r.event_id AND c.day_date = ?
           LEFT JOIN se_devices d ON d.registration_id = r.id AND d.event_id = r.event_id AND d.mode = 'full'
          WHERE r.event_id = ? AND r.status = 'confirmed' AND r.team_id IS NOT NULL
          GROUP BY r.team_id"
    );
    $stmt->execute([$day, $eventId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['team_id']] = max(1, (int) $row['joined'] ?: (int) $row['checked_in']);
    }

    return $out;
}

// --------------------------------------------------------------------------
// Host transitions (§11.2)
// --------------------------------------------------------------------------

/**
 * Start, pause or finish a game. Starting one makes it the console's active
 * game and pauses any other game that was live — one game runs at a time.
 */
function se_game_status_set(PDO $pdo, array $event, int $gameId, string $status, ?int $expected, int $actor): array
{
    if (!in_array($status, ['live', 'paused', 'finished'], true)) {
        throw new SeValidationException(['status' => 'Start, pause or finish.']);
    }

    $eventId = (int) $event['id'];
    $game    = se_game_find($pdo, $eventId, $gameId);
    if (!$game) {
        throw new SeNotFoundException('We could not find that game.');
    }
    if ($status === 'live') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_game_items WHERE game_id = ?");
        $stmt->execute([$gameId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new SeRuleException('NO_ITEMS', 'Add questions to this game in Studio → Games before starting it.');
        }
    }

    return se_live_mutate($pdo, $event, $expected, static function (array $live) use ($pdo, $eventId, $gameId, $status): array {
        if ($status === 'live') {
            $pdo->prepare("UPDATE se_games SET status = 'paused' WHERE event_id = ? AND status = 'live' AND id <> ?")
                ->execute([$eventId, $gameId]);
        }
        $pdo->prepare(
            "UPDATE se_games
                SET status = ?,
                    started_at = IF(? = 'live', COALESCE(started_at, NOW()), started_at),
                    finished_at = IF(? = 'finished', NOW(), NULL)
              WHERE id = ? AND event_id = ?"
        )->execute([$status, $status, $status, $gameId, $eventId]);

        if ($status === 'live') {
            return ['active_game_id' => $gameId, 'scene' => 'game'];
        }

        return (int) ($live['active_game_id'] ?? 0) === $gameId && $status === 'finished'
            ? ['active_round_id' => null]
            : [];
    }, 'game_op:' . $status, ['game_id' => $gameId], $actor);
}

/**
 * Create the next round of a game (§11.2 "create").
 *
 * The next item is the first one in the game's order that no non-void round
 * has used yet — so voiding a round and asking for the next one replays the
 * same question, which is how a result is corrected (§11.6.2). A round that
 * is still pending is handed back rather than duplicated.
 */
function se_round_next_live(PDO $pdo, array $event, array $game, bool $test, ?int $expected, int $actor): array
{
    $eventId = (int) $event['id'];
    $gameId  = (int) $game['id'];
    $round   = [];

    $state = se_live_mutate($pdo, $event, $expected, static function (array $live) use ($pdo, $eventId, $gameId, $test, &$round): array {
        $stmt = $pdo->prepare("SELECT * FROM se_games WHERE id = ? AND event_id = ? FOR UPDATE");
        $stmt->execute([$gameId, $eventId]);
        $game = $stmt->fetch();
        if (!$game) {
            throw new SeNotFoundException('We could not find that game.');
        }
        if ((string) $game['status'] !== 'live') {
            throw new SeRuleException('GAME_NOT_LIVE', 'Start the game first.');
        }

        $stmt = $pdo->prepare(
            "SELECT id, state FROM se_rounds WHERE game_id = ? AND state IN ('pending','armed','open','locked','revealed')
              ORDER BY round_no DESC LIMIT 1"
        );
        $stmt->execute([$gameId]);
        $open = $stmt->fetch();
        if ($open && (string) $open['state'] === 'pending') {
            $round = ['id' => (int) $open['id'], 'state' => 'pending', 'reused' => true];
            return ['active_game_id' => $gameId, 'active_round_id' => (int) $open['id'], 'scene' => 'game'];
        }
        if ($open) {
            throw new SeRuleException('ROUND_IN_PROGRESS', 'Finish this round first: reveal and score it, or void it.');
        }

        $itemId = null;
        if ((string) $game['type'] !== 'charades') {
            $stmt = $pdo->prepare(
                "SELECT gi.deck_item_id
                   FROM se_game_items gi
                   JOIN se_deck_items i ON i.id = gi.deck_item_id AND i.review_status = 'approved'
                  WHERE gi.game_id = ?
                    AND gi.deck_item_id NOT IN (
                        SELECT r.deck_item_id FROM se_rounds r
                         WHERE r.game_id = gi.game_id AND r.state <> 'void' AND r.deck_item_id IS NOT NULL)
                  ORDER BY gi.sort_order, gi.deck_item_id
                  LIMIT 1"
            );
            $stmt->execute([$gameId]);
            $itemId = $stmt->fetchColumn();
            if (!$itemId) {
                throw new SeRuleException('NO_ITEMS', 'That was the last question in this game.');
            }
            $itemId = (int) $itemId;

            if ((string) $game['type'] === 'feud' && !se_feud_board($pdo, ['id' => $eventId], $itemId, true)) {
                throw new SeRuleException('BOARD_NOT_APPROVED', 'Approve the board for the next survey question in Studio → Games first.');
            }
        }

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(round_no), 0) + 1 FROM se_rounds WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $number = (int) $stmt->fetchColumn();

        $pdo->prepare(
            "INSERT INTO se_rounds (event_id, game_id, round_no, deck_item_id, is_test) VALUES (?, ?, ?, ?, ?)"
        )->execute([$eventId, $gameId, $number, $itemId, $test ? 1 : 0]);

        $round = ['id' => (int) $pdo->lastInsertId(), 'round_no' => $number, 'state' => 'pending'];

        return ['active_game_id' => $gameId, 'active_round_id' => $round['id'], 'scene' => 'game'];
    }, 'round_op:next', ['game_id' => $gameId], $actor);

    return $round + ['version' => (int) $state['version']];
}

/**
 * Arm a round (§11.2 "arm"): the question appears now, answers open after
 * the preroll, and they close `duration` later. Eligibility is frozen here.
 * Charades and Feud have their own start (the presenter, the face-off).
 */
function se_round_arm_live(PDO $pdo, array $event, int $roundId, ?int $prerollMs, ?int $durationMs, ?int $expected, int $actor): array
{
    $eventId = (int) $event['id'];
    $result  = [];

    $state = se_live_mutate($pdo, $event, $expected, static function (array $live) use ($pdo, $event, $eventId, $roundId, $prerollMs, $durationMs, &$result): array {
        $round = se_round_find($pdo, $eventId, $roundId, true);
        if (!$round) {
            throw new SeNotFoundException('We could not find that round.');
        }
        $type = (string) $round['game_type'];
        if (in_array($type, ['charades', 'feud'], true)) {
            throw new SeRuleException('STALE_STATE', $type === 'charades'
                ? 'Pick the presenter, then start the timer.'
                : 'Start the face-off to open this board.');
        }
        if ((string) $round['state'] !== 'pending') {
            throw new SeRuleException('STALE_STATE', 'That round has already started.');
        }

        $settings = se_game_settings($round);
        $preroll  = max(2500, min(10000, $prerollMs ?? (int) ($settings['preroll_ms'] ?? 3000)));
        $duration = $durationMs ?? (int) ($settings['duration_ms'] ?? $settings['window_ms'] ?? 15000);
        $duration = max(3000, min(120000, $duration));

        $now    = se_epoch_ms();
        $opens  = $now + $preroll;
        $closes = $opens + $duration;

        $data = se_round_state_data($round);
        if ($type === 'who_am_i') {
            $data += ['clue_index' => 0, 'locked_out' => []];
        }
        if ($type === 'buzzer') {
            $data += ['locked_out' => []];
        }

        $stmt = $pdo->prepare(
            "UPDATE se_rounds
                SET state = 'armed', arm_at = ?, opens_at = ?, closes_at = ?, eligible_json = ?, state_json = ?
              WHERE id = ? AND state = 'pending'"
        );
        $stmt->execute([
            se_ms_to_sql($now), se_ms_to_sql($opens), se_ms_to_sql($closes),
            se_json_encode(se_round_eligibility($pdo, $event)), $data ? se_json_encode($data) : null, $roundId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new SeRuleException('STALE_STATE', 'The round changed. Refresh and try again.');
        }

        se_deck_item_mark_used($pdo, $round, $eventId);

        $result = ['id' => $roundId, 'state' => 'armed', 'arm_ms' => $now, 'opens_ms' => $opens, 'closes_ms' => $closes];

        return ['active_game_id' => (int) $round['game_id'], 'active_round_id' => $roundId, 'scene' => 'game']
            + se_live_cue($live, 'arm');
    }, 'round_op:arm', ['round_id' => $roundId], $actor);

    return $result + ['version' => (int) $state['version'], 'round' => $result];
}

/** Usage counters on the item, so the next deck draft can avoid repeats (§15.4). */
function se_deck_item_mark_used(PDO $pdo, array $round, int $eventId): void
{
    if (empty($round['deck_item_id']) || !empty($round['is_test'])) {
        return;
    }
    $pdo->prepare(
        "UPDATE se_deck_items SET times_used = times_used + 1, last_used_event_id = ?, last_used_at = NOW() WHERE id = ?"
    )->execute([$eventId, (int) $round['deck_item_id']]);
}

/**
 * Lock, reveal, score or void one round (the console's buttons).
 *
 * Reveal works from armed/open as well as locked — the host pressing
 * "Reveal" early locks the round on the way — and, when the game's
 * `auto_score` is on (the default for the quiz and Trivia), scoring follows
 * in the same transaction.
 */
function se_round_transition_live(PDO $pdo, array $event, int $roundId, string $to, ?int $expected, int $actor, string $reason = ''): array
{
    $eventId = (int) $event['id'];
    $out     = [];

    $state = se_live_mutate($pdo, $event, $expected, static function (array $live) use ($pdo, $event, $eventId, $roundId, $to, $actor, $reason, &$out): array {
        $round = se_round_find($pdo, $eventId, $roundId, true);
        if (!$round) {
            throw new SeNotFoundException('We could not find that round.');
        }
        $from = (string) $round['state'];
        $cue  = null;
        $type = (string) $round['game_type'];

        if (in_array($type, ['charades', 'feud'], true) && $to !== 'void') {
            throw new SeRuleException('STALE_STATE', $type === 'charades'
                ? 'Use Got it, Pass and End turn for charades.'
                : 'Use the board controls — Bank ends a Family Feud round.');
        }

        switch ($to) {
            case 'locked':
                if (!in_array($from, ['armed', 'open'], true)) {
                    throw new SeRuleException('STALE_STATE', 'That round is not open.');
                }
                se_round_move($pdo, $roundId, $from, 'locked', ['locked_at' => 'NOW(3)']);
                break;

            case 'revealed':
                if (in_array($from, ['armed', 'open'], true)) {
                    se_round_move($pdo, $roundId, $from, 'locked', ['locked_at' => 'NOW(3)']);
                    $from = 'locked';
                }
                if ($from !== 'locked') {
                    throw new SeRuleException('STALE_STATE', 'Lock the round before revealing it.');
                }
                $result = se_round_reveal_result($pdo, $event, $round);
                $pdo->prepare("UPDATE se_rounds SET result_json = ? WHERE id = ?")->execute([se_json_encode($result), $roundId]);
                se_round_move($pdo, $roundId, 'locked', 'revealed', ['revealed_at' => 'NOW(3)']);
                $cue = 'reveal';

                // Quiz and Trivia score on reveal unless auto_score is off. A
                // buzzer or Who Am I? round has nothing left to score — its
                // points came from judging a buzz — so it closes here too.
                $settings  = se_game_settings($round);
                $autoScore = in_array($type, ['live_quiz', 'trivia'], true) ? !empty($settings['auto_score']) : true;
                if ($autoScore) {
                    $round = se_round_find($pdo, $eventId, $roundId, true);
                    $out['scored'] = se_round_score($pdo, $event, $round, $actor);
                }
                break;

            case 'scored':
                if ($from !== 'revealed') {
                    throw new SeRuleException('ROUND_NOT_REVEALED', 'Reveal the answer before scoring.');
                }
                $out['scored'] = se_round_score($pdo, $event, $round, $actor);
                break;

            case 'void':
                if ($from === 'void') {
                    throw new SeRuleException('STALE_STATE', 'That round is already void.');
                }
                $reason = se_line($reason, 160);
                if (mb_strlen($reason) < 3) {
                    throw new SeValidationException(['reason' => 'Say why the round is void (it goes in the log).']);
                }
                $out['voided_scores'] = se_round_scores_void($pdo, $event, $roundId, 'Round voided: ' . $reason, $actor);
                $pdo->prepare("UPDATE se_rounds SET state = 'void', void_reason = ? WHERE id = ? AND state = ?")
                    ->execute([$reason, $roundId, $from]);
                break;

            default:
                throw new SeValidationException(['to' => 'Unknown round step.']);
        }

        $out += ['round_id' => $roundId, 'state' => $to === 'revealed' && isset($out['scored']) ? 'scored' : $to];

        return ['active_round_id' => $roundId] + ($cue ? se_live_cue($live, $cue) : []);
    }, 'round_op:' . $to, ['round_id' => $roundId, 'reason' => $reason ?: null], $actor);

    return $out + ['version' => (int) $state['version']];
}

/**
 * Move a round from one state to another, or fail with STALE_STATE when
 * somebody else moved it first. `$stamps` are trusted column => SQL pairs.
 */
function se_round_move(PDO $pdo, int $roundId, string $from, string $to, array $stamps = []): void
{
    $sets = ['state = ?'];
    foreach ($stamps as $column => $sql) {
        if (preg_match('/^[a-z_]+$/', $column) && $sql === 'NOW(3)') {
            $sets[] = $column . ' = NOW(3)';
        }
    }

    $stmt = $pdo->prepare("UPDATE se_rounds SET " . implode(', ', $sets) . " WHERE id = ? AND state = ?");
    $stmt->execute([$to, $roundId, $from]);
    if ($stmt->rowCount() === 0) {
        throw new SeRuleException('STALE_STATE', 'The round changed. Refresh and try again.');
    }
}

/** The console's single entry point for "score this round". */
function se_round_score_live(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_round_transition_live($pdo, $event, $roundId, 'scored', $expected, $actor);
}

/**
 * What the room learns at the reveal (§11.2 "reveal"): the right answer, how
 * the answers were spread, and, in Trivia, each team's choice.
 */
function se_round_reveal_result(PDO $pdo, array $event, array $round): array
{
    $item    = se_round_item($pdo, $round);
    $payload = $item['payload'] ?? [];
    $content = (string) ($item['content_type'] ?? '');
    $type    = (string) $round['game_type'];

    $result = [
        'answer' => se_item_answer_text($content, $payload),
        'ref'    => $item ? (string) ($item['scripture_ref'] ?? '') : '',
    ];
    if (!empty($payload['explanation'])) {
        $result['explanation'] = (string) $payload['explanation'];
    }
    if ($content === 'verse' && !empty($item['scripture_text'])) {
        $result['verse'] = (string) $item['scripture_text'];
    }

    if (in_array($type, ['live_quiz', 'trivia'], true)) {
        $choices = $payload['choices'] ?? [];
        $result['correct_index'] = (int) ($payload['answer_index'] ?? -1);

        $role = $type === 'trivia' ? 'captain' : 'player';
        $stmt = $pdo->prepare(
            "SELECT choice_index, COUNT(*) AS n FROM se_answers WHERE round_id = ? AND role = ? GROUP BY choice_index"
        );
        $stmt->execute([(int) $round['id'], $role]);
        $distribution = array_fill(0, count($choices), 0);
        foreach ($stmt->fetchAll() as $row) {
            $index = (int) $row['choice_index'];
            if (isset($distribution[$index])) {
                $distribution[$index] = (int) $row['n'];
            }
        }
        $result['distribution'] = $distribution;
        $result['answered']     = array_sum($distribution);

        if ($type === 'trivia') {
            $result['team_choices'] = se_trivia_team_choices($pdo, $event, $round);
        }
    }

    return $result;
}

/**
 * Each team's Trivia answer: the captain's, else — when
 * `use_suggestions_if_no_captain` is on — the team's most-suggested choice,
 * earliest suggestion breaking a tie (§11.5).
 *
 * @return array<string, array{choice: int, by: string}>
 */
function se_trivia_team_choices(PDO $pdo, array $event, array $round): array
{
    $out = [];

    $stmt = $pdo->prepare("SELECT team_id, choice_index FROM se_answers WHERE round_id = ? AND role = 'captain' AND team_id IS NOT NULL");
    $stmt->execute([(int) $round['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['team_id']] = ['choice' => (int) $row['choice_index'], 'by' => 'captain'];
    }

    if (!empty(se_game_settings($round)['use_suggestions_if_no_captain'])) {
        $stmt = $pdo->prepare(
            "SELECT team_id, choice_index, COUNT(*) AS n, MIN(received_at) AS first_at
               FROM se_answers
              WHERE round_id = ? AND role = 'suggestion' AND team_id IS NOT NULL
              GROUP BY team_id, choice_index
              ORDER BY team_id, n DESC, first_at ASC"
        );
        $stmt->execute([(int) $round['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['team_id'];
            if (!isset($out[$key])) {
                $out[$key] = ['choice' => (int) $row['choice_index'], 'by' => 'vote'];
            }
        }
    }

    return $out;
}

/**
 * Write a revealed quiz or Trivia round to the ledger and mark it scored
 * (§11.6). Idempotent: every row has its §11.6.2 key, and the final state
 * move only succeeds once.
 */
function se_round_score(PDO $pdo, array $event, array $round, int $actor): array
{
    if ((string) $round['state'] !== 'revealed') {
        throw new SeRuleException('ROUND_NOT_REVEALED', 'Reveal the answer before scoring.');
    }

    $roundId  = (int) $round['id'];
    $type     = (string) $round['game_type'];
    $settings = se_game_settings($round);
    $weight   = $round['weight'] ?? 1;
    $reason   = !empty($round['is_test']) ? se_score_test_reason() : null;
    $item     = se_round_item($pdo, $round);
    $correct  = (int) ($item['payload']['answer_index'] ?? -1);
    $teams    = [];

    if ($type === 'live_quiz') {
        $base     = se_quiz_base($settings);
        $duration = max(1, (int) ($settings['duration_ms'] ?? 20000));

        $stmt = $pdo->prepare("SELECT * FROM se_answers WHERE round_id = ? AND role = 'player'");
        $stmt->execute([$roundId]);
        $update = $pdo->prepare("UPDATE se_answers SET is_correct = ?, points = ? WHERE id = ?");

        $correctByTeam = [];
        foreach ($stmt->fetchAll() as $answer) {
            $ok     = $correct >= 0 && (int) $answer['choice_index'] === $correct;
            $points = $ok ? se_quiz_points($base, (int) $answer['elapsed_ms'], $duration) : 0;
            $update->execute([$ok ? 1 : 0, $points, (int) $answer['id']]);

            if ($points > 0) {
                se_score_insert($pdo, $event, [
                    'scope' => 'individual', 'registration_id' => (int) $answer['registration_id'],
                    'game_id' => (int) $round['game_id'], 'round_id' => $roundId, 'kind' => 'auto',
                    'points' => $points, 'reason' => $reason,
                    'idempotency_key' => se_score_key('q', $roundId, (int) $answer['registration_id']),
                ], $actor);
            }
            if ($ok && $answer['team_id'] !== null) {
                $correctByTeam[(int) $answer['team_id']] = ($correctByTeam[(int) $answer['team_id']] ?? 0) + 1;
            }
        }

        foreach (se_json_decode($round['eligible_json'] ?? null) as $teamId => $eligible) {
            $points = se_weighted_points(
                se_team_normalized_points((int) ($settings['team_base'] ?? 1000), $correctByTeam[(int) $teamId] ?? 0, (int) $eligible),
                $weight
            );
            $teams[(string) $teamId] = $points;
            if ($points > 0) {
                se_score_insert($pdo, $event, [
                    'scope' => 'team', 'team_id' => (int) $teamId, 'game_id' => (int) $round['game_id'],
                    'round_id' => $roundId, 'kind' => 'auto', 'points' => $points, 'reason' => $reason,
                    'idempotency_key' => 'q:' . $roundId . ':t:' . (int) $teamId,
                ], $actor);
            }
        }
    } elseif ($type === 'trivia') {
        $points = se_weighted_points((int) ($settings['points_correct'] ?? 300), $weight);

        // The captain's answer row is the team's; mark everyone's for the MVP
        // tie-break and the phones' ✓/✗.
        $pdo->prepare(
            "UPDATE se_answers SET is_correct = (choice_index = ?) WHERE round_id = ? AND role IN ('captain','suggestion')"
        )->execute([$correct, $roundId]);

        foreach (se_trivia_team_choices($pdo, $event, $round) as $teamId => $choice) {
            $ok = $choice['choice'] === $correct;
            $teams[(string) $teamId] = $ok ? $points : 0;
            if ($ok && $points > 0) {
                se_score_insert($pdo, $event, [
                    'scope' => 'team', 'team_id' => (int) $teamId, 'game_id' => (int) $round['game_id'],
                    'round_id' => $roundId, 'kind' => 'auto', 'points' => $points, 'reason' => $reason,
                    'idempotency_key' => 'r:' . $roundId . ':t:' . (int) $teamId,
                ], $actor);
            }
        }
    }

    $result = se_round_result($round);
    $result['team_points'] = $teams;
    $pdo->prepare("UPDATE se_rounds SET result_json = ? WHERE id = ?")->execute([se_json_encode($result), $roundId]);

    $stmt = $pdo->prepare("UPDATE se_rounds SET state = 'scored' WHERE id = ? AND state = 'revealed'");
    $stmt->execute([$roundId]);
    if ($stmt->rowCount() === 0) {
        throw new SeRuleException('STALE_STATE', 'That round was already scored.');
    }

    return ['team_points' => $teams];
}

// --------------------------------------------------------------------------
// Player actions (§11.3, §11.5, §11.7)
// --------------------------------------------------------------------------

/**
 * One answer to a Live Quiz or Trivia round. The caller (public API) has
 * already run se_game_require_player().
 */
function se_game_answer(PDO $pdo, array $event, array $reg, array $in, ?array $device = null): array
{
    $round = se_round_find($pdo, (int) $event['id'], se_int($in['round_id'] ?? 0, 0));
    if (!$round) {
        throw new SeNotFoundException('That question has gone. Watch the stage for the next one.');
    }
    $type = (string) $round['game_type'];
    if (!in_array($type, ['live_quiz', 'trivia'], true)) {
        throw new SeRuleException('ROUND_CLOSED', 'This game is played out loud — no answer on the phone.');
    }
    if (!in_array((string) $round['state'], ['armed', 'open'], true) || empty($round['opens_at'])) {
        throw new SeRuleException('ROUND_CLOSED', "Time's up.");
    }

    $settings = se_game_settings($round);
    $now      = se_epoch_ms();
    $opens    = (int) se_sql_to_ms($round['opens_at']);
    $closes   = (int) se_sql_to_ms($round['closes_at']);

    if ($now < $opens) {
        throw new SeRuleException('TOO_EARLY', 'Not yet — get ready.');
    }
    if ($now > $closes + (int) ($settings['grace_ms'] ?? 1500)) {
        throw new SeRuleException('ROUND_CLOSED', "Time's up.");
    }

    $role = 'player';
    if ($type === 'trivia') {
        if (!se_game_is_captain($pdo, $event, $reg)) {
            throw new SeRuleException('NOT_CAPTAIN', 'Only your captain can lock in the team answer. Tap to suggest one instead.');
        }
        $role = 'captain';
    }

    $item    = se_round_item($pdo, $round);
    $choices = $item['payload']['choices'] ?? [];
    $choice  = se_int($in['choice_index'] ?? -1, -1, 9, -1);
    if ($choice < 0 || $choice >= count($choices)) {
        throw new SeValidationException(['choice_index' => 'Choose one of the answers.']);
    }

    $duration      = max(1, $closes - $opens);
    $serverElapsed = $now - $opens;
    $clientElapsed = se_int($in['client_elapsed_ms'] ?? $serverElapsed, 0, $duration + 10000, $serverElapsed);
    // §8.5.5: trust the phone's own clock by up to 2.5 s (Wi-Fi lag), never
    // beyond what the server saw, and never past the end of the round.
    $elapsed = max(0, min($duration, $serverElapsed, max($serverElapsed - 2500, $clientElapsed)));

    try {
        $pdo->prepare(
            "INSERT INTO se_answers
                (event_id, round_id, registration_id, team_id, role, choice_index, received_at, client_elapsed_ms, elapsed_ms, device_id)
             VALUES (?, ?, ?, ?, ?, ?, NOW(3), ?, ?, ?)"
        )->execute([
            (int) $event['id'], (int) $round['id'], (int) $reg['id'],
            $reg['team_id'] !== null ? (int) $reg['team_id'] : null,
            $role, $choice, $clientElapsed, $elapsed, $device ? (int) $device['id'] : null,
        ]);
    } catch (PDOException $e) {
        if (se_is_duplicate_key($e)) {
            $stmt = $pdo->prepare("SELECT choice_index, elapsed_ms FROM se_answers WHERE round_id = ? AND registration_id = ? AND role = ?");
            $stmt->execute([(int) $round['id'], (int) $reg['id'], $role]);
            $mine = $stmt->fetch() ?: [];
            throw new SeRuleException('ALREADY_ANSWERED', 'Your answer is already locked in.', [
                'choice_index' => isset($mine['choice_index']) ? (int) $mine['choice_index'] : null,
            ]);
        }
        throw $e;
    }

    // The answered counter on the stage moves at the next tick.
    se_live_touch($pdo, (int) $event['id']);

    return ['accepted' => true, 'choice_index' => $choice, 'elapsed_ms' => $elapsed, 'role' => $role];
}

/** A Trivia suggestion to the captain; changeable until the round locks. */
function se_game_suggest(PDO $pdo, array $event, array $reg, array $in): array
{
    $round = se_round_find($pdo, (int) $event['id'], se_int($in['round_id'] ?? 0, 0));
    if (!$round || (string) $round['game_type'] !== 'trivia') {
        throw new SeNotFoundException('That question has gone.');
    }
    if (empty($reg['team_id'])) {
        throw new SeRuleException('NOT_ELIGIBLE', 'You need a team to suggest an answer.');
    }
    if (!in_array((string) $round['state'], ['armed', 'open'], true) || empty($round['closes_at'])
        || se_epoch_ms() > (int) se_sql_to_ms($round['closes_at'])) {
        throw new SeRuleException('ROUND_CLOSED', "Time's up.");
    }

    $item    = se_round_item($pdo, $round);
    $choices = $item['payload']['choices'] ?? [];
    $choice  = se_int($in['choice_index'] ?? -1, -1, 9, -1);
    if ($choice < 0 || $choice >= count($choices)) {
        throw new SeValidationException(['choice_index' => 'Choose one of the answers.']);
    }

    $pdo->prepare(
        "INSERT INTO se_answers (event_id, round_id, registration_id, team_id, role, choice_index, received_at, elapsed_ms)
         VALUES (?, ?, ?, ?, 'suggestion', ?, NOW(3), 0)
         ON DUPLICATE KEY UPDATE choice_index = VALUES(choice_index), received_at = VALUES(received_at)"
    )->execute([(int) $event['id'], (int) $round['id'], (int) $reg['id'], (int) $reg['team_id'], $choice]);

    se_live_touch($pdo, (int) $event['id']);

    return ['accepted' => true, 'choice_index' => $choice];
}

/**
 * A buzz (§11.7): Bible Buzzer, Who Am I?, and the Family Feud face-off.
 * The first buzz per team per attempt counts; teammates get ALREADY_BUZZED.
 */
function se_game_buzz(PDO $pdo, array $event, array $reg, array $in): array
{
    if (empty($reg['team_id'])) {
        throw new SeRuleException('NOT_ELIGIBLE', 'You need a team to buzz.');
    }

    $round = se_round_find($pdo, (int) $event['id'], se_int($in['round_id'] ?? 0, 0));
    if (!$round || !in_array((string) $round['game_type'], ['buzzer', 'who_am_i', 'feud'], true)) {
        throw new SeNotFoundException('There is nothing to buzz for right now.');
    }
    if (!in_array((string) $round['state'], ['armed', 'open'], true) || empty($round['opens_at']) || empty($round['closes_at'])) {
        throw new SeRuleException('ROUND_CLOSED', 'Buzzing is closed.');
    }
    if (se_int($in['attempt'] ?? 0, 0) !== (int) $round['attempt']) {
        throw new SeRuleException('ROUND_CLOSED', 'That buzz window has moved on.');
    }

    $now    = se_epoch_ms();
    $opens  = (int) se_sql_to_ms($round['opens_at']);
    $closes = (int) se_sql_to_ms($round['closes_at']);
    $client = se_int($in['client_ms'] ?? $now, 0, PHP_INT_MAX, $now);

    if ($now < $opens || $client < $opens - 200) {
        throw new SeRuleException('TOO_EARLY', 'Not yet — wait for the green light.');
    }
    if ($now > $closes) {
        throw new SeRuleException('ROUND_CLOSED', "Time's up.");
    }

    $data = se_round_state_data($round);
    if ((string) $round['game_type'] === 'feud') {
        if (($data['phase'] ?? '') !== 'faceoff') {
            throw new SeRuleException('NOT_ELIGIBLE', 'Only the face-off has a buzzer.');
        }
        if (!in_array((int) $reg['id'], [(int) ($data['rep_a'] ?? 0), (int) ($data['rep_b'] ?? 0)], true)) {
            throw new SeRuleException('NOT_ELIGIBLE', 'Only the two face-off players can buzz.');
        }
    }
    if (in_array((int) $reg['team_id'], array_map('intval', (array) ($data['locked_out'] ?? [])), true)) {
        throw new SeRuleException('LOCKED_OUT', 'Your team is out for this one — next clue!');
    }

    // A phone whose clock runs ahead is believed only as far as the server
    // saw the buzz arrive.
    if ($client > $now + 200) {
        $client = $now;
    }
    $effective = se_buzz_effective($client, $opens, $now);

    try {
        $pdo->prepare(
            "INSERT INTO se_buzzes (event_id, round_id, attempt, team_id, registration_id, effective_ms, received_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(3))"
        )->execute([(int) $event['id'], (int) $round['id'], (int) $round['attempt'], (int) $reg['team_id'], (int) $reg['id'], $effective]);
    } catch (PDOException $e) {
        if (se_is_duplicate_key($e)) {
            throw new SeRuleException('ALREADY_BUZZED', 'Your team already buzzed.');
        }
        throw $e;
    }

    se_live_touch($pdo, (int) $event['id']);

    return ['accepted' => true, 'effective_ms' => $effective];
}

/** Buzzes of the round's current attempt, in winning order, with names. */
function se_round_buzzes(PDO $pdo, array $round, bool $allAttempts = false): array
{
    $sql = "SELECT b.*, r.display_name, r.player_no, t.sort_order AS team_order
              FROM se_buzzes b
              JOIN se_registrations r ON r.id = b.registration_id
              LEFT JOIN se_teams t ON t.id = b.team_id
             WHERE b.round_id = ?" . ($allAttempts ? '' : ' AND b.attempt = ?');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($allAttempts ? [(int) $round['id']] : [(int) $round['id'], (int) $round['attempt']]);

    return se_buzz_order($stmt->fetchAll());
}

/** The buzz the host should judge next: the earliest still pending. */
function se_round_buzz_leader(PDO $pdo, array $round): ?array
{
    foreach (se_round_buzzes($pdo, $round) as $buzz) {
        if ((string) $buzz['judged'] === 'pending') {
            return $buzz;
        }
    }

    return null;
}

// --------------------------------------------------------------------------
// Heartbeat (§8.5.6)
// --------------------------------------------------------------------------

/**
 * Time-based round transitions, run by se_live_tick(). Correctness never
 * depends on this — every answer and buzz checks its own window — it keeps
 * the screens in step with the clock.
 *
 *   * Quiz and Trivia lock once `closes_at + grace_ms` has passed (when
 *     `auto_lock` is on), so answers inside the grace are still accepted.
 *   * A buzzer or Who Am I? window that ends with nobody buzzing locks; one
 *     with a pending buzz stays for the host to judge.
 *   * A charades turn locks when its timer runs out.
 *   * Family Feud never auto-locks: its face-off window only gates the buzz.
 *
 * @return bool whether anything changed
 */
function se_games_tick(PDO $pdo, array $event): bool
{
    if (!se_game_ready($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT r.*, g.type AS game_type, g.settings_json
           FROM se_rounds r JOIN se_games g ON g.id = r.game_id
          WHERE r.event_id = ? AND r.state IN ('armed','open') AND r.closes_at IS NOT NULL AND r.closes_at <= NOW(3)"
    );
    $stmt->execute([(int) $event['id']]);

    $changed = false;
    $now     = se_epoch_ms();

    foreach ($stmt->fetchAll() as $round) {
        $type     = (string) $round['game_type'];
        $settings = se_game_settings($round);
        $closes   = (int) se_sql_to_ms($round['closes_at']);

        $lock = match ($type) {
            'live_quiz', 'trivia' => !empty($settings['auto_lock']) && $now >= $closes + (int) ($settings['grace_ms'] ?? 1500),
            'buzzer', 'who_am_i'  => se_round_buzz_leader($pdo, $round) === null,
            'charades'            => true,
            default               => false,
        };
        if (!$lock) {
            continue;
        }

        $upd = $pdo->prepare("UPDATE se_rounds SET state = 'locked', locked_at = NOW(3) WHERE id = ? AND state IN ('armed','open')");
        $upd->execute([(int) $round['id']]);
        $changed = $changed || $upd->rowCount() > 0;
    }

    return $changed;
}

// --------------------------------------------------------------------------
// Views
// --------------------------------------------------------------------------

/**
 * The public view of a round — what public.json carries. No answer before
 * the reveal, no charades phrase ever, no names (AGENTS.md).
 */
function se_round_public(PDO $pdo, array $round, ?array $item = null): array
{
    $item    ??= se_round_item($pdo, $round);
    $payload = $item['payload'] ?? [];
    $content = (string) ($item['content_type'] ?? '');
    $type    = (string) $round['game_type'];
    $state   = (string) $round['state'];
    $data    = se_round_state_data($round);

    $view = [
        'id'           => (int) $round['id'],
        'game_id'      => (int) $round['game_id'],
        'round_no'     => (int) $round['round_no'],
        'state'        => $state,
        'attempt'      => (int) $round['attempt'],
        'content_type' => $content,
        'opens_ms'     => se_sql_to_ms($round['opens_at'] ?? null),
        'closes_ms'    => se_sql_to_ms($round['closes_at'] ?? null),
        'test'         => !empty($round['is_test']),
    ];

    switch ($type) {
        case 'live_quiz':
        case 'trivia':
            $view['prompt']  = (string) ($payload['prompt'] ?? $payload['emojis'] ?? $payload['lead'] ?? '');
            $view['choices'] = array_values((array) ($payload['choices'] ?? []));
            if ($state !== 'pending') {
                $view['answered'] = se_round_answer_count($pdo, $round);
            }
            break;

        case 'buzzer':
            // Read aloud, judged by the host: never the choices (§11.1).
            $view['prompt'] = (string) ($payload['prompt'] ?? $payload['emojis'] ?? $payload['lead'] ?? '');
            $view['locked_out'] = array_map('intval', (array) ($data['locked_out'] ?? []));
            break;

        case 'who_am_i':
            $clueIndex = (int) ($data['clue_index'] ?? 0);
            $clues     = array_values((array) ($payload['clues'] ?? []));
            $view['clues']       = in_array($state, ['revealed', 'scored'], true) ? $clues : array_slice($clues, 0, $clueIndex + 1);
            $view['clue_index']  = $clueIndex;
            $view['clues_total'] = count($clues);
            $view['locked_out']  = array_map('intval', (array) ($data['locked_out'] ?? []));
            break;

        case 'charades':
            $words = (array) ($data['words'] ?? []);
            $view['team_id']    = $round['team_id'] !== null ? (int) $round['team_id'] : null;
            $view['words_done'] = count(array_filter($words, static fn($w): bool => ($w['result'] ?? '') === 'correct'));
            $view['passes']     = (int) ($data['passes'] ?? 0);
            $view['phrase_seq'] = count($words);
            break;

        case 'feud':
            $view += se_feud_public($pdo, $round, $data);
            break;
    }

    if (in_array($state, ['revealed', 'scored'], true)) {
        $result = se_round_result($round);
        unset($result['team_points']);
        $view['result'] = $result;
    }
    if ($state === 'scored') {
        $view['team_points'] = (array) (se_round_result($round)['team_points'] ?? []);
    }

    return $view;
}

/** How many players (or captains) have answered. */
function se_round_answer_count(PDO $pdo, array $round): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_answers WHERE round_id = ? AND role IN ('player','captain')");
    $stmt->execute([(int) $round['id']]);

    return (int) $stmt->fetchColumn();
}

/**
 * The game block of public.json: the active game and its current round.
 * `state` mirrors the round's state so @se/core/realtime.js's paceFor() can
 * poll at 1 s while a round is running.
 */
function se_live_game_payload(PDO $pdo, array $event, ?array $liveState = null): ?array
{
    if (!se_game_ready($pdo)) {
        return null;
    }

    ['game' => $game, 'round' => $round] = se_game_active($pdo, $event, $liveState);
    if (!$game) {
        return null;
    }

    $roundView = $round ? se_round_public($pdo, $round) : null;

    return [
        'id'        => (int) $game['id'],
        'type'      => (string) $game['type'],
        'title'     => (string) $game['title'],
        'status'    => (string) $game['status'],
        'state'     => $roundView['state'] ?? 'idle',
        'mode'      => match ((string) $game['type']) {
            'live_quiz' => 'individual',
            'trivia'    => 'captain',
            'charades'  => 'presenter',
            default     => 'buzzer',
        },
        'round'     => $roundView,
    ];
}

/**
 * The console's view (§13.10): the public view plus the answer, the live
 * counts, the buzz queue with names, the Feud board with its answers, and
 * the charades phrase. Capability-protected; never a snapshot.
 */
function se_live_game_private(PDO $pdo, array $event): ?array
{
    if (!se_game_ready($pdo)) {
        return null;
    }

    $public = se_live_game_payload($pdo, $event);
    if (!$public) {
        return null;
    }

    ['round' => $round] = se_game_active($pdo, $event);
    $private = ['round' => null, 'item' => null, 'buzzes' => [], 'answer' => null, 'next_item' => null];

    if ($round) {
        $item = se_round_item($pdo, $round);
        $private['round'] = [
            'id'        => (int) $round['id'],
            'round_no'  => (int) $round['round_no'],
            'state'     => (string) $round['state'],
            'attempt'   => (int) $round['attempt'],
            'team_id'   => $round['team_id'] !== null ? (int) $round['team_id'] : null,
            'void_reason' => $round['void_reason'],
            'data'      => se_round_state_data($round),
        ];
        if ($item) {
            $private['item'] = [
                'id'            => (int) $item['id'],
                'content_type'  => (string) $item['content_type'],
                'payload'       => $item['payload'],
                'scripture_ref' => (string) ($item['scripture_ref'] ?? ''),
            ];
            $private['answer'] = se_item_answer_text((string) $item['content_type'], $item['payload']);
        }

        if (in_array((string) $round['game_type'], ['buzzer', 'who_am_i', 'feud'], true)) {
            $private['buzzes'] = array_map(static fn(array $b): array => [
                'id'          => (int) $b['id'],
                'attempt'     => (int) $b['attempt'],
                'team_id'     => (int) $b['team_id'],
                'player'      => (string) $b['display_name'],
                'player_no'   => $b['player_no'] !== null ? (int) $b['player_no'] : null,
                'judged'      => (string) $b['judged'],
                'effective_ms' => (int) $b['effective_ms'],
            ], se_round_buzzes($pdo, $round, true));
        }

        if ((string) $round['game_type'] === 'live_quiz' || (string) $round['game_type'] === 'trivia') {
            $private['live_distribution'] = se_round_live_distribution($pdo, $round, $item);
        }

        if ((string) $round['game_type'] === 'feud') {
            $private['board'] = array_map(static fn(array $a): array => [
                'id' => (int) $a['id'], 'label' => (string) $a['label'], 'points' => (int) $a['points'],
            ], se_feud_board($pdo, $event, (int) $round['deck_item_id'], true));
        }

        if ((string) $round['game_type'] === 'charades') {
            $private['charades'] = se_charades_console($pdo, $event, $round);
        }
    }

    // What the "next" button will play, so the host can read ahead.
    if (in_array($public['type'], ['live_quiz', 'trivia', 'buzzer', 'who_am_i', 'feud'], true)) {
        $private['remaining'] = se_game_remaining($pdo, (int) $public['id']);
    }

    return $public + ['private' => $private];
}

/** How the answers are spreading while a round is open (console only). */
function se_round_live_distribution(PDO $pdo, array $round, ?array $item): array
{
    $choices = (array) ($item['payload']['choices'] ?? []);
    $role    = (string) $round['game_type'] === 'trivia' ? 'captain' : 'player';
    $stmt    = $pdo->prepare("SELECT choice_index, COUNT(*) AS n FROM se_answers WHERE round_id = ? AND role = ? GROUP BY choice_index");
    $stmt->execute([(int) $round['id'], $role]);

    $out = array_fill(0, count($choices), 0);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($out[(int) $row['choice_index']])) {
            $out[(int) $row['choice_index']] = (int) $row['n'];
        }
    }

    return $out;
}

/** How many of a game's questions have not been played yet. */
function se_game_remaining(PDO $pdo, int $gameId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_game_items gi
          WHERE gi.game_id = ?
            AND gi.deck_item_id NOT IN (SELECT r.deck_item_id FROM se_rounds r
                                         WHERE r.game_id = gi.game_id AND r.state <> 'void' AND r.deck_item_id IS NOT NULL)"
    );
    $stmt->execute([$gameId]);

    return (int) $stmt->fetchColumn();
}

/**
 * The games block of one phone's `me` (§12.2.1): whether it joined, its
 * answer and result for the current round, the charades secret when this
 * phone is the presenter, the Feud survey, and its score.
 */
function se_game_me_payload(PDO $pdo, array $event, array $registration, ?array $device): array
{
    $regId  = (int) $registration['id'];
    $joined = $device !== null && ($device['joined_games_at'] ?? null) !== null;

    $games = [
        'joined'  => $joined,
        'captain' => false,
        'me_hash' => se_registration_hash($regId),
        'survey'  => se_survey_questions($pdo, $event, $regId),
    ];
    $roundPayload = null;

    if (se_game_ready($pdo)) {
        if (!empty($registration['team_id'])) {
            $games['captain'] = se_game_captain_id($pdo, $event, (int) $registration['team_id']) === $regId;
        }

        ['round' => $round] = se_game_active($pdo, $event);
        if ($round && (string) $round['state'] !== 'void') {
            $roundPayload = ['id' => (int) $round['id'], 'state' => (string) $round['state'], 'game_type' => (string) $round['game_type']];

            $stmt = $pdo->prepare(
                "SELECT role, choice_index, elapsed_ms, points, is_correct FROM se_answers
                  WHERE round_id = ? AND registration_id = ? ORDER BY FIELD(role, 'captain', 'player', 'suggestion') LIMIT 1"
            );
            $stmt->execute([(int) $round['id'], $regId]);
            if ($mine = $stmt->fetch()) {
                $scored = (string) $round['state'] === 'scored';
                $roundPayload['my_answer'] = [
                    'role'         => (string) $mine['role'],
                    'choice_index' => $mine['choice_index'] !== null ? (int) $mine['choice_index'] : null,
                    'is_correct'   => $scored && $mine['is_correct'] !== null ? (bool) $mine['is_correct'] : null,
                    'points'       => $scored ? (int) $mine['points'] : null,
                ];
            }
        }
    }

    $points = 0;
    $rank   = null;
    if (se_table_exists($pdo, 'se_score_events')) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM se_score_events
              WHERE event_id = ? AND registration_id = ? AND scope = 'individual' AND voided_at IS NULL"
        );
        $stmt->execute([(int) $event['id'], $regId]);
        $points = (int) $stmt->fetchColumn();

        if ($points > 0) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) + 1 FROM (
                    SELECT registration_id, SUM(points) AS total FROM se_score_events
                     WHERE event_id = ? AND scope = 'individual' AND voided_at IS NULL AND registration_id IS NOT NULL
                     GROUP BY registration_id HAVING SUM(points) > ?
                 ) ahead"
            );
            $stmt->execute([(int) $event['id'], $points]);
            $rank = (int) $stmt->fetchColumn();
        }
    }

    return [
        'games'     => $games,
        'round'     => $roundPayload,
        'presenter' => se_charades_presenter_payload($pdo, $event, $regId, $device),
        'score'     => ['points' => $points, 'rank' => $rank],
    ];
}

// --------------------------------------------------------------------------
// Rehearsal (§11.13)
// --------------------------------------------------------------------------

/**
 * Remove what a test run of the games left behind: void the TEST ledger
 * rows, delete test rounds (answers and buzzes go with them) and test survey
 * answers, and put games with nothing left back to ready.
 */
function se_games_reset(PDO $pdo, array $event, ?int $actor): int
{
    $eventId = (int) $event['id'];
    $count   = 0;

    if (se_table_exists($pdo, 'se_score_events')) {
        $stmt = $pdo->prepare(
            "UPDATE se_score_events
                SET voided_at = COALESCE(voided_at, NOW()), voided_by = COALESCE(voided_by, ?),
                    void_reason = COALESCE(void_reason, 'Reset rehearsal')
              WHERE event_id = ? AND voided_at IS NULL AND (reason = 'TEST' OR reason LIKE 'TEST: %')"
        );
        $stmt->execute([$actor ?: null, $eventId]);
        $count += $stmt->rowCount();
    }

    if (se_table_exists($pdo, 'se_rounds')) {
        $stmt = $pdo->prepare("DELETE FROM se_rounds WHERE event_id = ? AND is_test = 1");
        $stmt->execute([$eventId]);
        $count += $stmt->rowCount();

        // A game whose only rounds were rehearsal goes back to the start.
        $pdo->prepare(
            "UPDATE se_games g
                SET g.status = IF((SELECT COUNT(*) FROM se_game_items gi WHERE gi.game_id = g.id) > 0, 'ready', 'draft'),
                    g.started_at = NULL, g.finished_at = NULL
              WHERE g.event_id = ? AND NOT EXISTS (SELECT 1 FROM se_rounds r WHERE r.game_id = g.id)"
        )->execute([$eventId]);
    }

    if (se_table_exists($pdo, 'se_survey_responses')) {
        $stmt = $pdo->prepare("DELETE FROM se_survey_responses WHERE event_id = ? AND is_test = 1");
        $stmt->execute([$eventId]);
        $count += $stmt->rowCount();
    }

    // The live state must not point at a round that no longer exists.
    if (se_live_ready($pdo)) {
        $pdo->prepare(
            "UPDATE se_live_state s
                SET s.active_round_id = NULL,
                    s.scene = IF(s.scene IN ('game','finale'), 'standby', s.scene),
                    s.scene_payload_json = IF(s.scene IN ('game','finale'), NULL, s.scene_payload_json),
                    s.version = s.version + 1
              WHERE s.event_id = ?
                AND s.active_round_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM se_rounds r WHERE r.id = s.active_round_id)"
        )->execute([$eventId]);
    }

    return $count;
}
