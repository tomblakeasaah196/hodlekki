<?php
// /includes/special_events/party_games.php
// Party games, survey boards and finale controls (Special Events PR6).

function se_chara_starter_content(PDO $pdo, array $event, int $actor): array
{
    $sets = [
        ['title'=>'Chara · Who Am I?','type'=>'clues','items'=>[
            ['clues'=>['My father gave me a special coat','My brothers sold me','I interpreted dreams in Egypt','I became governor over Egypt'],'answer'=>'Joseph','accept'=>['joseph']],
            ['clues'=>['I was raised by my cousin','I became queen in Persia','I asked my people to fast','I said, if I perish, I perish'],'answer'=>'Esther','accept'=>['esther','queen esther']],
        ]],
        ['title'=>'Chara · Bible Charades','type'=>'charade','items'=>[
            ['phrase'=>'David and Goliath','category'=>'story'],
            ['phrase'=>'Zacchaeus climbing a tree','category'=>'story','hint'=>'A short man wanted to see Jesus'],
            ['phrase'=>"Daniel in the lions' den",'category'=>'story'],
        ]],
        ['title'=>'Chara · Feud Survey','type'=>'survey','items'=>array_map(static fn(string $question): array => ['question'=>$question], [
            "Name something you'd find on Noah's Ark.",
            'Name a gospel song everyone in Lagos knows the words to.',
            'Name something people do at a Nigerian wedding reception.',
            'Name a Bible character known for being strong.',
            'Name something you bring to a church picnic.',
        ])],
    ];
    $created = 0;
    foreach ($sets as $set) {
        $check = $pdo->prepare('SELECT id FROM se_decks WHERE event_id=? AND title=? LIMIT 1');
        $check->execute([(int)$event['id'],$set['title']]);
        $deckId = (int)($check->fetchColumn() ?: 0);
        if (!$deckId) {
            $deck = se_deck_save($pdo,$event,['title'=>$set['title'],'content_type'=>$set['type'],'scope'=>'event','description'=>'Appendix G starter content'], $actor);
            $deckId = (int)$deck['id'];
        }
        foreach ($set['items'] as $index => $payload) {
            $json = se_json_encode($payload);
            $exists = $pdo->prepare('SELECT 1 FROM se_deck_items WHERE deck_id=? AND payload_json=? LIMIT 1');
            $exists->execute([$deckId,$json]);
            if ($exists->fetchColumn()) continue;
            $item = se_deck_item_save($pdo,$event,['deck_id'=>$deckId,'content_type'=>$set['type'],'payload'=>$payload,'sort_order'=>$index,'source'=>'manual'],$actor);
            $pdo->prepare("UPDATE se_deck_items SET review_status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$actor,(int)$item['id']]);
            $created++;
        }
    }
    return ['created'=>$created,'decks'=>count($sets)];
}

function se_who_am_i_points(array $pointsByClue, int $clueIndex): int
{
    if ($pointsByClue === []) {
        $pointsByClue = [500, 400, 300, 200, 100];
    }
    $index = max(0, min(count($pointsByClue) - 1, $clueIndex));
    return max(0, (int) $pointsByClue[$index]);
}

function se_feud_bank_points(array $revealedPoints, int $multiplier): int
{
    return array_sum(array_map('intval', $revealedPoints)) * max(1, $multiplier);
}

function se_round_state_data(array $round): array
{
    $state = json_decode((string) ($round['state_json'] ?? '{}'), true);
    return is_array($state) ? $state : [];
}

function se_party_round(PDO $pdo, array $event, int $roundId, bool $lock = false): array
{
    $sql = 'SELECT r.*, g.type game_type, g.settings_json, g.weight FROM se_rounds r JOIN se_games g ON g.id = r.game_id WHERE r.id = ? AND r.event_id = ?';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roundId, (int) $event['id']]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$round) {
        throw new SeNotFoundException('Round not found.');
    }
    return $round;
}

function se_party_live_mutate(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor, string $action, callable $change): array
{
    $result = [];
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $roundId, $change, &$result): array {
        $round = se_party_round($pdo, $event, $roundId, true);
        $result = $change($round);
        return ['active_game_id' => (int) $round['game_id'], 'active_round_id' => $roundId, 'scene' => 'game'];
    }, $action, ['round_id' => $roundId], $actor);
    return ['version' => (int) $state['version']] + $result;
}

function se_clue_next(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'clue_next', static function (array $round) use ($pdo, $roundId): array {
        if ($round['game_type'] !== 'who_am_i' || !in_array($round['state'], ['armed', 'open'], true)) {
            throw new SeRuleException('That clue cannot be advanced now.', 'STALE_STATE');
        }
        $item = se_round_item($pdo, $round);
        $clues = $item['payload']['clues'] ?? [];
        $state = se_round_state_data($round);
        $next = min(max(0, count($clues) - 1), ((int) ($state['clue_index'] ?? 0)) + 1);
        if ($next === (int) ($state['clue_index'] ?? 0)) {
            throw new SeRuleException('That was the final clue.', 'NO_MORE_CLUES');
        }
        $state['clue_index'] = $next;
        $state['locked_out'] = [];
        $state['attempt'] = ((int) ($state['attempt'] ?? 1)) + 1;
        $stmt = $pdo->prepare('UPDATE se_rounds SET state_json = ?, attempt = attempt + 1, opens_at = NOW(3), closes_at = DATE_ADD(NOW(3), INTERVAL 15 SECOND) WHERE id = ?');
        $stmt->execute([se_json_encode($state), $roundId]);
        return ['clue_index' => $next, 'clues_total' => count($clues)];
    });
}

function se_presenter_find(PDO $pdo, array $event, int $teamId, int|string $player): array
{
    $args = [(int) $event['id'], $teamId];
    $where = '';
    if ($player === 'random') {
        $where = 'ORDER BY RAND() LIMIT 1';
    } else {
        $where = 'AND r.player_no = ? LIMIT 1';
        $args[] = (int) ltrim((string) $player, '#');
    }
    $stmt = $pdo->prepare("SELECT r.id, r.display_name, r.player_no, r.team_id
        FROM se_registrations r
        JOIN se_checkins c ON c.registration_id = r.id AND c.event_id = r.event_id AND c.day_date = CURDATE()
        JOIN se_devices d ON d.registration_id = r.id AND d.event_id = r.event_id AND d.mode = 'full'
        WHERE r.event_id = ? AND r.team_id = ? AND r.status = 'confirmed' AND d.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        {$where}");
    $stmt->execute($args);
    $presenter = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$presenter) {
        throw new SeRuleException('That player is not checked in on this team with an active phone.', 'PRESENTER_UNAVAILABLE');
    }
    return $presenter;
}

function se_charades_turn(PDO $pdo, array $event, int $roundId, int $teamId, int|string $player, bool $showOnConsole, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_turn', static function (array $round) use ($pdo, $event, $roundId, $teamId, $player, $showOnConsole): array {
        if ($round['game_type'] !== 'charades' || !in_array($round['state'], ['pending', 'armed'], true)) {
            throw new SeRuleException('That charades turn has already moved on.', 'STALE_STATE');
        }
        $presenter = se_presenter_find($pdo, $event, $teamId, $player);
        $state = ['words' => [], 'passes' => 0, 'phrase_index' => 0, 'show_on_console' => $showOnConsole];
        $stmt = $pdo->prepare("UPDATE se_rounds SET state = 'armed', team_id = ?, presenter_registration_id = ?, state_json = ?, arm_at = NOW(3), opens_at = NULL, closes_at = NULL WHERE id = ?");
        $stmt->execute([$teamId, (int) $presenter['id'], se_json_encode($state), $roundId]);
        return ['presenter' => ['registration_id' => (int) $presenter['id'], 'display_name' => $presenter['display_name'], 'player_no' => (int) $presenter['player_no']]];
    });
}

function se_charades_start(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_start', static function (array $round) use ($pdo, $roundId): array {
        if ($round['game_type'] !== 'charades' || $round['state'] !== 'armed' || empty($round['presenter_registration_id'])) {
            throw new SeRuleException('Pick the presenter first.', 'STALE_STATE');
        }
        $settings = se_game_settings($round);
        $turnMs = max(10000, min(180000, (int) ($settings['turn_ms'] ?? 60000)));
        $opens = se_epoch_ms() + 1500;
        $closes = $opens + $turnMs;
        $fmt = static fn(int $ms): string => date('Y-m-d H:i:s.', intdiv($ms, 1000)) . str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE se_rounds SET state = 'armed', opens_at = ?, closes_at = ? WHERE id = ?")
            ->execute([$fmt($opens), $fmt($closes), $roundId]);
        return ['opens_ms' => $opens, 'closes_ms' => $closes];
    });
}

function se_charades_item_at(PDO $pdo, array $round, int $offset): ?array
{
    $stmt = $pdo->prepare('SELECT d.* FROM se_game_items gi JOIN se_deck_items d ON d.id = gi.deck_item_id WHERE gi.game_id = ? ORDER BY gi.sort_order, d.id LIMIT 1 OFFSET ' . max(0, $offset));
    $stmt->execute([(int) $round['game_id']]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) return null;
    $item['payload'] = json_decode((string) $item['payload_json'], true) ?: [];
    return $item;
}

function se_charades_mark(PDO $pdo, array $event, int $roundId, string $result, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_mark', static function (array $round) use ($pdo, $event, $roundId, $result, $actor): array {
        if ($round['game_type'] !== 'charades' || !in_array($round['state'], ['armed', 'open'], true) || !in_array($result, ['correct', 'pass'], true)) {
            throw new SeRuleException('That mark is not available.', 'STALE_STATE');
        }
        $now = se_epoch_ms();
        $opens = !empty($round['opens_at']) ? se_epoch_ms(se_parse_datetime($round['opens_at'])) : PHP_INT_MAX;
        $closes = !empty($round['closes_at']) ? se_epoch_ms(se_parse_datetime($round['closes_at'])) : 0;
        if ($now < $opens) throw new SeRuleException('The timer has not started.', 'TOO_EARLY');
        if ($now > $closes) throw new SeRuleException("Time's up.", 'ROUND_CLOSED');
        $state = se_round_state_data($round);
        $settings = se_game_settings($round);
        if ($result === 'pass' && (int) ($state['passes'] ?? 0) >= (int) ($settings['max_passes'] ?? 2)) {
            throw new SeRuleException('No passes remain in this turn.', 'PASS_LIMIT');
        }
        $number = count($state['words'] ?? []) + 1;
        $state['words'][] = ['result' => $result, 'item_id' => (int) ($round['deck_item_id'] ?? 0)];
        if ($result === 'pass') {
            $state['passes'] = ((int) ($state['passes'] ?? 0)) + 1;
        } else {
            $points = (int) ($settings['points_per_word'] ?? 200);
            se_score_insert($pdo, $event, [
                'scope' => 'team', 'team_id' => (int) $round['team_id'], 'game_id' => (int) $round['game_id'],
                'round_id' => $roundId, 'kind' => 'auto', 'points' => $points,
                'reason' => !empty($round['is_test']) ? 'TEST' : 'Charades word',
                'idempotency_key' => 'c:' . $roundId . ':w:' . $number,
            ], $actor);
        }
        $state['phrase_index'] = ((int) ($state['phrase_index'] ?? 0)) + 1;
        $next = se_charades_item_at($pdo, $round, (int) $state['phrase_index']);
        $pdo->prepare('UPDATE se_rounds SET state_json = ? WHERE id = ?')->execute([se_json_encode($state), $roundId]);
        return ['words_done' => count(array_filter($state['words'], static fn(array $word): bool => $word['result'] === 'correct')), 'passes' => (int) $state['passes'], 'has_next' => $next !== null];
    });
}

function se_charades_end(PDO $pdo, array $event, int $roundId, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'charades_end', static function (array $round) use ($pdo, $roundId): array {
        if ($round['game_type'] !== 'charades' || !in_array($round['state'], ['armed', 'open'], true)) {
            throw new SeRuleException('That turn is already over.', 'STALE_STATE');
        }
        $pdo->prepare("UPDATE se_rounds SET state = 'scored', locked_at = NOW(3), revealed_at = NOW(3) WHERE id = ?")->execute([$roundId]);
        return ['state' => 'scored'];
    });
}

function se_charades_presenter_payload(PDO $pdo, array $event, int $registrationId): ?array
{
    if (!se_game_ready($pdo)) return null;
    $stmt = $pdo->prepare("SELECT r.*, g.settings_json FROM se_rounds r JOIN se_games g ON g.id = r.game_id
        WHERE r.event_id = ? AND g.type = 'charades' AND r.presenter_registration_id = ? AND r.state IN ('armed','open')
        ORDER BY r.id DESC LIMIT 1");
    $stmt->execute([(int) $event['id'], $registrationId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$round) return null;
    $state = se_round_state_data($round);
    $item = se_charades_item_at($pdo, $round, (int) ($state['phrase_index'] ?? 0));
    if (!$item) return null;
    $payload = $item['payload'];
    return [
        'round_id' => (int) $round['id'], 'phrase' => (string) ($payload['phrase'] ?? ''),
        'category' => (string) ($payload['category'] ?? ''), 'hint' => (string) ($payload['hint'] ?? ''),
        'words_done' => count(array_filter($state['words'] ?? [], static fn(array $word): bool => ($word['result'] ?? '') === 'correct')),
        'opens_ms' => $round['opens_at'] ? se_epoch_ms(se_parse_datetime($round['opens_at'])) : null,
        'closes_ms' => $round['closes_at'] ? se_epoch_ms(se_parse_datetime($round['closes_at'])) : null,
    ];
}

function se_survey_normalize(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^\pL\pN\s]/u', ' ', $text) ?? '';
    $text = preg_replace('/\b(?:a|an|the)\b/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (mb_strlen($text) > 3 && str_ends_with($text, 's') && !str_ends_with($text, 'ss')) {
        $text = mb_substr($text, 0, -1);
    }
    return $text;
}

function se_survey_save(PDO $pdo, array $event, array $registration, int $itemId, string $text): array
{
    $text = se_line($text, 60);
    if ($text === '' || mb_strlen($text) > 60) {
        throw new SeValidationException('Keep your answer between 1 and 60 characters.', ['text']);
    }
    if (preg_match('/\b(?:fuck|shit|bitch|bastard)\b/i', $text)) {
        throw new SeValidationException('Please keep it friendly for the room.', ['text']);
    }
    $stmt = $pdo->prepare("SELECT d.id FROM se_deck_items d JOIN se_game_items gi ON gi.deck_item_id = d.id JOIN se_games g ON g.id = gi.game_id
        WHERE d.id = ? AND g.event_id = ? AND g.type = 'feud' LIMIT 1");
    $stmt->execute([$itemId, (int) $event['id']]);
    if (!$stmt->fetchColumn()) throw new SeNotFoundException('Survey question not found.');
    $stmt = $pdo->prepare("SELECT started_at FROM se_games WHERE event_id = ? AND type = 'feud' AND status IN ('live','finished') LIMIT 1");
    $stmt->execute([(int) $event['id']]);
    if ($stmt->fetchColumn()) throw new SeRuleException('The survey has closed.', 'ROUND_CLOSED');
    $norm = se_survey_normalize($text);
    $test = se_bool(se_event_settings($event)['test_mode'] ?? false) ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO se_survey_responses(event_id,deck_item_id,registration_id,answer_text,answer_norm,is_test) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),answer_norm=VALUES(answer_norm),is_test=VALUES(is_test)');
    $stmt->execute([(int) $event['id'], $itemId, (int) $registration['id'], $text, $norm, $test]);
    return ['saved' => true, 'item_id' => $itemId];
}

function se_survey_questions(PDO $pdo, array $event, int $registrationId): array
{
    if (!se_game_ready($pdo)) return [];
    $stmt = $pdo->prepare("SELECT DISTINCT d.id, d.payload_json, r.answer_text
        FROM se_games g JOIN se_game_items gi ON gi.game_id = g.id JOIN se_deck_items d ON d.id = gi.deck_item_id
        LEFT JOIN se_survey_responses r ON r.event_id = g.event_id AND r.deck_item_id = d.id AND r.registration_id = ?
        WHERE g.event_id = ? AND g.type = 'feud' AND g.status NOT IN ('live','finished') ORDER BY gi.sort_order");
    $stmt->execute([$registrationId, (int) $event['id']]);
    return array_map(static function (array $row): array {
        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        return ['id' => (int) $row['id'], 'question' => (string) ($payload['question'] ?? ''), 'answer' => $row['answer_text']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function se_feud_board(PDO $pdo, array $event, int $itemId, bool $approvedOnly = true): array
{
    $sql = 'SELECT id,label,points,sort_order,source,approved FROM se_feud_answers WHERE event_id = ? AND deck_item_id = ?';
    if ($approvedOnly) $sql .= ' AND approved = 1';
    $sql .= ' ORDER BY sort_order,id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int) $event['id'], $itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function se_feud_item_payload(PDO $pdo, array $event, int $itemId): array
{
    $stmt = $pdo->prepare("SELECT d.payload_json FROM se_deck_items d JOIN se_game_items gi ON gi.deck_item_id=d.id JOIN se_games g ON g.id=gi.game_id WHERE d.id=? AND g.event_id=? AND g.type='feud' LIMIT 1");
    $stmt->execute([$itemId,(int)$event['id']]);
    $json = $stmt->fetchColumn();
    if ($json === false) throw new SeNotFoundException('Survey question not found.');
    return json_decode((string)$json,true) ?: [];
}

function se_feud_build_board(PDO $pdo, array $event, int $itemId, int $actor): array
{
    $payload = se_feud_item_payload($pdo, $event, $itemId);
    $question = se_line($payload['question'] ?? '', 100);
    if ($question === '') throw new SeNotFoundException('Survey question not found.');
    $stmt = $pdo->prepare('SELECT id,answer_text,answer_norm FROM se_survey_responses WHERE event_id = ? AND deck_item_id = ? ORDER BY id');
    $stmt->execute([(int) $event['id'], $itemId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = se_event_settings($event);
    $minimum = max(1, (int) ($settings['games']['feud']['min_responses'] ?? 25));
    if (count($responses) < $minimum) {
        $groups = [];
        foreach ($responses as $response) {
            $key = (string) $response['answer_norm'];
            if ($key === '') continue;
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }
        arsort($groups);
        $clusters = [];
        foreach ($groups as $label => $count) $clusters[] = ['label' => mb_convert_case($label, MB_CASE_TITLE), 'points' => $count, 'source' => 'survey'];
        return ['mode' => 'manual', 'responses' => count($responses), 'minimum' => $minimum, 'answers' => array_slice($clusters, 0, 8), 'warning' => 'Too few responses for AI clustering. Review these exact groups or enter an estimated board manually.'];
    }
    $anonymous = array_map(static function (array $response): string {
        $text = (string) $response['answer_text'];
        $text = preg_replace('/\b[+\d][\d\s()\-]{7,}\d\b/', '[number removed]', $text) ?? $text;
        $text = preg_replace('/\b[^\s@]+@[^\s@]+\.[^\s@]+\b/u', '[email removed]', $text) ?? $text;
        $text = preg_replace('~https?://\S+~i', '[link removed]', $text) ?? $text;
        return (int) $response['id'] . ': ' . $text;
    }, $responses);
    $result = se_ai($pdo, 'feud_cluster', ['question' => $question, 'responses' => $anonymous], ['user_id' => $actor, 'event_id' => (int) $event['id']]);
    $jobId = se_ai_job_create($pdo, (int) $event['id'], 'feud_cluster', ['item_id' => $itemId, 'question' => $question], $result, $actor, 'ready');
    return ['mode' => 'ai_review', 'job_id' => $jobId, 'responses' => count($responses), 'result' => $result];
}

function se_feud_board_save(PDO $pdo, array $event, int $itemId, array $answers, bool $approved, int $actor): array
{
    se_feud_item_payload($pdo, $event, $itemId);
    if (count($answers) < 1 || count($answers) > 8) throw new SeValidationException('Use between 1 and 8 board answers.', ['answers']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM se_feud_answers WHERE event_id = ? AND deck_item_id = ?')->execute([(int) $event['id'], $itemId]);
        $stmt = $pdo->prepare('INSERT INTO se_feud_answers(event_id,deck_item_id,label,points,sort_order,source,approved) VALUES(?,?,?,?,?,?,?)');
        foreach (array_values($answers) as $index => $answer) {
            if (!is_array($answer)) continue;
            $label = se_line($answer['label'] ?? '', 24);
            if ($label === '') throw new SeValidationException('Every board answer needs a label.', ['answers']);
            $source = se_enum($answer['source'] ?? 'manual', ['survey','ai','manual'], 'manual');
            $stmt->execute([(int) $event['id'], $itemId, $label, max(0, (int) ($answer['points'] ?? 0)), $index, $source, $approved ? 1 : 0]);
        }
        se_audit($pdo, (int) $event['id'], 'feud_board_save', ['item_id' => $itemId, 'approved' => $approved], 'deck_item', $itemId, $actor);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['answers' => se_feud_board($pdo, $event, $itemId, false), 'approved' => $approved];
}

function se_feud_update(PDO $pdo, array $event, int $roundId, string $op, array $input, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'feud_' . $op, static function (array $round) use ($pdo, $event, $roundId, $op, $input, $actor): array {
        if ($round['game_type'] !== 'feud') throw new SeRuleException('This is not a Feud round.', 'STALE_STATE');
        $state = se_round_state_data($round);
        $board = se_feud_board($pdo, $event, (int) $round['deck_item_id'], true);
        if ($board === []) throw new SeRuleException('Approve this Feud board before playing it.', 'BOARD_NOT_APPROVED');
        if ($op === 'faceoff') {
            $a = (int) ($input['team_a'] ?? 0); $b = (int) ($input['team_b'] ?? 0);
            if (!$a || !$b || $a === $b) throw new SeValidationException('Choose two different teams.', ['team_a','team_b']);
            $repA = se_presenter_find($pdo, $event, $a, !empty($input['rep_a']) ? (int)$input['rep_a'] : 'random');
            $repB = se_presenter_find($pdo, $event, $b, !empty($input['rep_b']) ? (int)$input['rep_b'] : 'random');
            $state = ['phase' => 'faceoff', 'team_a' => $a, 'team_b' => $b, 'rep_a' => (int)$repA['id'], 'rep_b' => (int)$repB['id'], 'rep_a_name' => (string)$repA['display_name'], 'rep_b_name' => (string)$repB['display_name'], 'control_team' => null, 'revealed' => [], 'strikes' => 0, 'bank' => 0, 'steal_team' => null, 'banked' => false];
            $pdo->prepare("UPDATE se_rounds SET state='armed', team_id=?, opponent_team_id=?, state_json=?, opens_at=NOW(3), closes_at=DATE_ADD(NOW(3), INTERVAL 15 SECOND) WHERE id=?")
                ->execute([$a, $b, se_json_encode($state), $roundId]);
        } elseif ($op === 'control') {
            $team = (int) ($input['team_id'] ?? 0);
            if (!in_array($team, [(int) ($state['team_a'] ?? 0), (int) ($state['team_b'] ?? 0)], true)) throw new SeValidationException('Control must go to a face-off team.', ['team_id']);
            $state['phase'] = 'control'; $state['control_team'] = $team;
        } elseif ($op === 'reveal') {
            $answerId = (int) ($input['answer_id'] ?? 0);
            $match = null;
            foreach ($board as $answer) if ((int) $answer['id'] === $answerId) $match = $answer;
            if (!$match) throw new SeNotFoundException('Board answer not found.');
            if (!in_array($answerId, $state['revealed'] ?? [], true)) {
                $state['revealed'][] = $answerId;
                $state['bank'] = ((int) ($state['bank'] ?? 0)) + (int) $match['points'];
            }
        } elseif ($op === 'strike') {
            $state['strikes'] = min(3, ((int) ($state['strikes'] ?? 0)) + 1);
            if ($state['strikes'] >= 3) { $state['phase'] = 'steal'; $state['steal_team'] = (int) $state['control_team'] === (int) $state['team_a'] ? (int) $state['team_b'] : (int) $state['team_a']; }
        } elseif ($op === 'steal') {
            $state['steal_success'] = se_bool($input['success'] ?? false);
            $state['phase'] = 'bank';
        } elseif ($op === 'reveal_all') {
            $state['revealed'] = array_map(static fn(array $a): int => (int) $a['id'], $board);
        } elseif ($op === 'bank') {
            if (!empty($state['banked'])) throw new SeRuleException('This bank is already awarded.', 'ALREADY_SCORED');
            $settings = se_game_settings($round);
            $multipliers = $settings['round_multipliers'] ?? [1,1,2,3];
            $multiplier = max(1, (int) ($input['multiplier'] ?? ($multipliers[max(0, (int) $round['round_no'] - 1)] ?? 1)));
            $winner = !empty($state['steal_success']) ? (int) $state['steal_team'] : (int) $state['control_team'];
            if (!$winner) throw new SeRuleException('Give a team control before banking.', 'NO_CONTROL');
            $points = se_feud_bank_points([(int) ($state['bank'] ?? 0)], $multiplier);
            se_score_insert($pdo, $event, ['scope'=>'team','team_id'=>$winner,'game_id'=>(int)$round['game_id'],'round_id'=>$roundId,'kind'=>'auto','points'=>$points,'reason'=>!empty($round['is_test'])?'TEST':'Family Feud bank','idempotency_key'=>'f:'.$roundId.':bank'], $actor);
            $state['banked'] = true; $state['winner_team'] = $winner; $state['multiplier'] = $multiplier; $state['total'] = $points;
            $pdo->prepare("UPDATE se_rounds SET state='scored',locked_at=NOW(3),revealed_at=NOW(3) WHERE id=?")->execute([$roundId]);
        } else {
            throw new SeValidationException('Unknown Feud operation.', ['op']);
        }
        $pdo->prepare('UPDATE se_rounds SET state_json = ? WHERE id = ?')->execute([se_json_encode($state), $roundId]);
        return ['board' => $board, 'feud' => $state];
    });
}

function se_buzz_judge_party(PDO $pdo, array $event, int $roundId, int $buzzId, bool $correct, ?int $expected, int $actor): array
{
    return se_party_live_mutate($pdo, $event, $roundId, $expected, $actor, 'buzz_judge', static function (array $round) use ($pdo, $event, $roundId, $buzzId, $correct, $actor): array {
        if (!in_array($round['game_type'], ['buzzer', 'who_am_i'], true)) throw new SeRuleException('This round is not judged by buzz.', 'STALE_STATE');
        $stmt = $pdo->prepare("SELECT * FROM se_buzzes WHERE id=? AND round_id=? AND judged='pending' FOR UPDATE");
        $stmt->execute([$buzzId, $roundId]);
        $buzz = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$buzz) throw new SeRuleException('That buzz was already judged.', 'STALE_STATE');
        $stmt = $pdo->prepare('UPDATE se_buzzes SET judged=? WHERE id=?');
        $stmt->execute([$correct ? 'correct' : 'wrong', $buzzId]);
        $settings = se_game_settings($round);
        $state = se_round_state_data($round);
        if ($correct) {
            $points = $round['game_type'] === 'who_am_i'
                ? se_who_am_i_points($settings['points_by_clue'] ?? [], (int) ($state['clue_index'] ?? 0))
                : (int) ($settings['points_correct'] ?? 300);
            se_score_insert($pdo, $event, ['scope'=>'team','team_id'=>(int)$buzz['team_id'],'game_id'=>(int)$round['game_id'],'round_id'=>$roundId,'kind'=>'auto','points'=>$points,'reason'=>!empty($round['is_test'])?'TEST':'Correct buzz','idempotency_key'=>'r:'.$roundId.':t:'.$buzz['team_id']], $actor);
            $state['winner_team_id'] = (int) $buzz['team_id'];
            $state['points'] = $points;
            $pdo->prepare("UPDATE se_rounds SET state='scored',state_json=?,locked_at=NOW(3),revealed_at=NOW(3) WHERE id=?")->execute([se_json_encode($state),$roundId]);
        } else {
            $state['locked_out'] = array_values(array_unique(array_merge($state['locked_out'] ?? [], [(int)$buzz['team_id']])));
            $penalty = (int) ($settings['wrong_penalty'] ?? 0);
            if ($penalty !== 0) se_score_insert($pdo,$event,['scope'=>'team','team_id'=>(int)$buzz['team_id'],'game_id'=>(int)$round['game_id'],'round_id'=>$roundId,'kind'=>'penalty','points'=>-$penalty,'reason'=>!empty($round['is_test'])?'TEST':'Wrong buzz','idempotency_key'=>'p:'.$roundId.':t:'.$buzz['team_id'].':a:'.$round['attempt']],$actor);
            $pdo->prepare('UPDATE se_rounds SET state_json=? WHERE id=?')->execute([se_json_encode($state),$roundId]);
        }
        return ['correct'=>$correct,'team_id'=>(int)$buzz['team_id'],'round_state'=>$correct?'scored':$round['state']];
    });
}

function se_live_game_private(PDO $pdo, array $event): ?array
{
    $public = se_live_game_payload($pdo, $event);
    if (!$public || empty($public['round'])) return $public;
    $stmt = $pdo->prepare('SELECT r.*,g.type game_type FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?');
    $stmt->execute([(int)$public['round']['id'],(int)$event['id']]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$round) return $public;
    $item = se_round_item($pdo, $round);
    $private = ['row'=>$round,'item'=>$item,'state'=>se_round_state_data($round)];
    $buzzes = $pdo->prepare('SELECT b.*,r.display_name FROM se_buzzes b JOIN se_registrations r ON r.id=b.registration_id WHERE b.round_id=? ORDER BY b.effective_ms,b.received_at');
    $buzzes->execute([(int)$round['id']]);
    $private['buzzes'] = $buzzes->fetchAll(PDO::FETCH_ASSOC);
    if ($round['game_type'] === 'feud') $private['board'] = se_feud_board($pdo,$event,(int)$round['deck_item_id'],true);
    return $public + ['private'=>$private];
}

function se_game_me_payload(PDO $pdo, array $event, array $registration, ?array $device): array
{
    $joined = $device !== null && ($device['joined_games_at'] ?? null) !== null;
    $games = ['joined' => $joined, 'captain' => false, 'survey' => se_survey_questions($pdo, $event, (int)$registration['id'])];
    $roundPayload = null;
    if (se_game_ready($pdo)) {
        $stmt = $pdo->prepare("SELECT r.*,g.type game_type FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.event_id=? AND r.state NOT IN ('void','pending') ORDER BY r.id DESC LIMIT 1");
        $stmt->execute([(int)$event['id']]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($round) {
            $roundPayload = ['id'=>(int)$round['id'],'state'=>(string)$round['state'],'game_type'=>(string)$round['game_type'],'attempt'=>(int)$round['attempt']];
            $answer = $pdo->prepare("SELECT choice_index,elapsed_ms,points,is_correct FROM se_answers WHERE round_id=? AND registration_id=? AND role IN ('player','captain') LIMIT 1");
            $answer->execute([(int)$round['id'],(int)$registration['id']]);
            $mine = $answer->fetch(PDO::FETCH_ASSOC);
            if ($mine) $roundPayload['my_answer'] = $mine;
        }
        if (!empty($registration['team_id'])) {
            $captain = $pdo->prepare('SELECT captain_registration_id FROM se_teams WHERE id=? AND event_id=?');
            $captain->execute([(int)$registration['team_id'],(int)$event['id']]);
            $games['captain'] = (int)($captain->fetchColumn() ?: 0) === (int)$registration['id'];
        }
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM se_score_events WHERE event_id=? AND registration_id=? AND scope='individual' AND voided_at IS NULL");
    $stmt->execute([(int)$event['id'],(int)$registration['id']]);
    $points = (int)$stmt->fetchColumn();
    $rankStmt = $pdo->prepare("SELECT COUNT(*)+1 FROM (SELECT registration_id,SUM(points) total FROM se_score_events WHERE event_id=? AND scope='individual' AND voided_at IS NULL GROUP BY registration_id HAVING total>?) ranked");
    $rankStmt->execute([(int)$event['id'],$points]);
    return ['games'=>$games,'round'=>$roundPayload,'presenter'=>se_charades_presenter_payload($pdo,$event,(int)$registration['id']),'score'=>['points'=>$points,'rank'=>(int)$rankStmt->fetchColumn()]];
}

function se_finale_payload(PDO $pdo, array $event): array
{
    $leaders = se_leaderboards($pdo, $event);
    $teams = [];
    $teamRows = se_teams($pdo, (int) $event['id']);
    $scores = array_column($leaders['teams'], 'points', 'team_id');
    foreach ($teamRows as $team) {
        $teams[] = ['id'=>(int)$team['id'],'name'=>(string)($team['name'] ?: $team['color_label']),'hex'=>(string)$team['color_hex'],'points'=>(int)($scores[(int)$team['id']] ?? 0)];
    }
    usort($teams, static fn(array $a, array $b): int => [$b['points'], $a['id']] <=> [$a['points'], $b['id']]);
    foreach ($teams as $index => &$team) $team['rank'] = $index + 1;
    unset($team);
    $mvp = [];
    foreach ($leaders['mvp'] as $row) {
        $stmt = $pdo->prepare('SELECT display_name,team_id FROM se_registrations WHERE id=? AND event_id=?');
        $stmt->execute([(int)$row['registration_id'],(int)$event['id']]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reg) $mvp[] = ['registration_id'=>(int)$row['registration_id'],'display_name'=>(string)$reg['display_name'],'team_id'=>(int)$reg['team_id'],'points'=>(int)$row['points']];
    }
    return ['teams'=>$teams,'champion'=>$teams[0]??null,'mvp'=>$mvp,'mvp_winner'=>$mvp[0]??null,'awards'=>se_named_awards($pdo,$event)];
}

function se_named_awards(PDO $pdo, array $event): array
{
    $stmt = $pdo->prepare("SELECT id,scope,team_id,registration_id,points,reason,created_at FROM se_score_events WHERE event_id=? AND kind='award' AND voided_at IS NULL AND reason IS NOT NULL ORDER BY id");
    $stmt->execute([(int)$event['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function se_finale_start(PDO $pdo, array $event, ?int $expected, int $actor): array
{
    $payload = se_finale_payload($pdo, $event);
    if (!$payload['champion']) throw new SeRuleException('Score at least one team before the finale.', 'NO_SCORES');
    $current = se_live_state($pdo, (int) $event['id']);
    $state = se_live_mutate($pdo, $event, $expected, static fn(): array => [
        'scene' => 'finale',
        'scene_payload_json' => se_json_encode(['since_ms' => se_epoch_ms(), 'payload' => $payload]),
        'sfx_seq' => ((int) ($current['sfx_seq'] ?? 0)) + 1,
        'sfx_cue' => 'fanfare',
    ], 'finale', ['champion_team_id'=>$payload['champion']['id']], $actor);
    return ['version'=>(int)$state['version'],'finale'=>$payload];
}
