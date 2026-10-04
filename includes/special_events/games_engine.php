<?php
// /includes/special_events/games_engine.php
// Round state machine, timing and public game views.

function se_game_elapsed(int $client, int $server, int $duration): int { return max(0, min($duration, min($client, $server))); }
function se_buzz_effective(int $client, int $opens, int $received): int { return max($opens, min($client, $received)); }
function se_buzz_order(array $buzzes): array { usort($buzzes, static fn($a,$b)=>[$a['effective_ms'],$a['received_at']??'',(int)($a['team_order']??0)] <=> [$b['effective_ms'],$b['received_at']??'',(int)($b['team_order']??0)]); return $buzzes; }
function se_game_settings(array $game): array { return json_decode((string)($game['settings_json']??'{}'),true) ?: []; }
function se_round_item(PDO $pdo, array $round): ?array { if(empty($round['deck_item_id']))return null;$s=$pdo->prepare('SELECT * FROM se_deck_items WHERE id=?');$s->execute([(int)$round['deck_item_id']]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$r['payload']=json_decode($r['payload_json'],true)?:[];return $r; }
function se_round_next(PDO $pdo,array $event,array $game,int $actor,bool $test=false):array { $pdo->beginTransaction();try{$s=$pdo->prepare("SELECT COALESCE(MAX(round_no),0)+1 FROM se_rounds WHERE game_id=?");$s->execute([(int)$game['id']]);$no=(int)$s->fetchColumn();$s=$pdo->prepare("SELECT deck_item_id FROM se_game_items WHERE game_id=? ORDER BY sort_order LIMIT 1 OFFSET ".($no-1));$s->execute([(int)$game['id']]);$item=$s->fetchColumn();if(!$item)throw new SeRuleException('No approved deck item remains.','NO_ITEMS');$s=$pdo->prepare('INSERT INTO se_rounds(event_id,game_id,round_no,deck_item_id,is_test) VALUES(?,?,?,?,?)');$s->execute([(int)$event['id'],(int)$game['id'],$no,(int)$item,$test?1:0]);$id=(int)$pdo->lastInsertId();$pdo->commit();return ['id'=>$id,'round_no'=>$no,'state'=>'pending'];}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
function se_round_arm(PDO $pdo,array $event,int $roundId,int $preroll,int $duration,?int $expected,int $actor):array { $now=se_epoch_ms();$preroll=max(2500,$preroll);$s=$pdo->prepare('SELECT r.*,g.settings_json,g.type FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?');$s->execute([$roundId,(int)$event['id']]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new SeNotFoundException('Round not found.');if($r['state']!=='pending')throw new SeRuleException('That round has already started.','STALE_STATE');$opens=$now+$preroll;$closes=$opens+$duration;$elig=[];if(se_table_exists($pdo,'se_registrations')){$q=$pdo->prepare("SELECT team_id,COUNT(*) n FROM se_registrations WHERE event_id=? AND status='confirmed' AND team_id IS NOT NULL GROUP BY team_id");$q->execute([(int)$event['id']]);$elig=$q->fetchAll(PDO::FETCH_KEY_PAIR);}$dt=static fn($ms)=>date('Y-m-d H:i:s.',intdiv($ms,1000)).str_pad((string)($ms%1000),3,'0',STR_PAD_LEFT);$s=$pdo->prepare("UPDATE se_rounds SET state='armed',arm_at=?,opens_at=?,closes_at=?,eligible_json=? WHERE id=? AND state='pending'");$s->execute([$dt($now),$dt($opens),$dt($closes),se_json_encode($elig),$roundId]);if(!$s->rowCount())throw new SeRuleException('The round changed. Refresh and try again.','STALE_STATE');return ['id'=>$roundId,'state'=>'armed','arm_ms'=>$now,'opens_ms'=>$opens,'closes_ms'=>$closes];}
function se_round_transition(PDO $pdo,int $roundId,string $to,int $actor,string $reason=''):array { $allowed=['pending'=>['armed','void'],'armed'=>['open','locked','void'],'open'=>['locked','void'],'locked'=>['revealed','void'],'revealed'=>['scored','void'],'scored'=>['void']];$s=$pdo->prepare('SELECT * FROM se_rounds WHERE id=?');$s->execute([$roundId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new SeNotFoundException('Round not found.');if(!in_array($to,$allowed[$r['state']]??[],true))throw new SeRuleException('That round cannot make that transition.','STALE_STATE');$col=['locked'=>'locked_at','revealed'=>'revealed_at'][$to]??null;$sql='UPDATE se_rounds SET state=?';$args=[$to];if($col){$sql.=', '.$col.'=NOW(3)';}if($to==='void'){$sql.=',void_reason=?';$args[]=$reason;}$sql.=' WHERE id=? AND state=?';$args[]=$roundId;$args[]=$r['state'];$s=$pdo->prepare($sql);$s->execute($args);if(!$s->rowCount())throw new SeRuleException('The round changed.','STALE_STATE');return ['id'=>$roundId,'state'=>$to];}
function se_round_public(PDO $pdo, array $round, bool $reveal = false): array
{
    $item = se_round_item($pdo, $round);
    $payload = $item['payload'] ?? [];
    $type = $item['content_type'] ?? 'mcq';
    $view = [
        'id' => (int) $round['id'],
        'state' => (string) $round['state'],
        'attempt' => (int) ($round['attempt'] ?? 1),
        'opens_ms' => !empty($round['opens_at']) ? se_epoch_ms(se_parse_datetime($round['opens_at'])) : null,
        'closes_ms' => !empty($round['closes_at']) ? se_epoch_ms(se_parse_datetime($round['closes_at'])) : null,
        'content_type' => $type,
    ];
    foreach (['prompt', 'choices', 'emojis', 'lead', 'clues', 'question'] as $key) {
        if (isset($payload[$key])) $view[$key] = $payload[$key];
    }
    $state = se_round_state_data($round);
    if (!$reveal && isset($view['clues'])) {
        $view['clues'] = array_slice($view['clues'], 0, ((int) ($state['clue_index'] ?? 0)) + 1);
    }
    if ($type === 'charade') {
        $view = ['id' => (int) $round['id'], 'state' => (string) $round['state'], 'content_type' => 'charade', 'words_done' => count(array_filter($state['words'] ?? [], static fn(array $word): bool => ($word['result'] ?? '') === 'correct'))];
    }
    if ($reveal) {
        $result = json_decode((string) ($round['result_json'] ?? '{}'), true) ?: [];
        $view['reveal'] = $result;
    }
    unset($view['answer'], $view['accept'], $view['phrase'], $view['hint']);
    return $view;
}
function se_games_reset(PDO $pdo, array $event, int $actor): int
{
    $eventId = (int) $event['id'];
    $count = 0;
    if (se_table_exists($pdo, 'se_score_events')) {
        $stmt = $pdo->prepare("UPDATE se_score_events SET voided_at=COALESCE(voided_at,NOW()),voided_by=COALESCE(voided_by,?),void_reason=COALESCE(void_reason,'Reset rehearsal') WHERE event_id=? AND reason='TEST' AND voided_at IS NULL");
        $stmt->execute([$actor ?: null, $eventId]);
        $count += $stmt->rowCount();
    }
    if (se_table_exists($pdo, 'se_rounds')) {
        $stmt = $pdo->prepare('DELETE FROM se_rounds WHERE event_id=? AND is_test=1');
        $stmt->execute([$eventId]);
        $count += $stmt->rowCount();
    }
    if (se_table_exists($pdo, 'se_survey_responses')) {
        $stmt = $pdo->prepare('DELETE FROM se_survey_responses WHERE event_id=? AND is_test=1');
        $stmt->execute([$eventId]);
        $count += $stmt->rowCount();
    }
    return $count;
}

function se_game_require_player(PDO $pdo, array $event, array $reg, ?array $device): void
{
    if (!$device || ($device['mode'] ?? '') !== 'full' || (int)($device['registration_id'] ?? 0) !== (int)$reg['id']) {
        throw new SeRuleException('This phone is read-only. Ask the desk for a transfer code.', 'DEVICE_READONLY');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM se_checkins WHERE event_id=? AND registration_id=? AND day_date=CURDATE() LIMIT 1');
    $stmt->execute([(int)$event['id'],(int)$reg['id']]);
    if (!$stmt->fetchColumn()) throw new SeRuleException('Check in first to join the games.', 'NOT_CHECKED_IN');
}

function se_game_join(PDO $pdo, array $event, array $reg, ?array $device = null): array
{
    se_game_require_player($pdo, $event, $reg, $device);
    $pdo->prepare("UPDATE se_devices SET joined_games_at=COALESCE(joined_games_at,NOW()) WHERE id=? AND mode='full'")->execute([(int)$device['id']]);
    $state = se_live_state($pdo,(int)$event['id']);
    $team = !empty($reg['team_id']) ? se_team_find($pdo,(int)$event['id'],(int)$reg['team_id']) : null;
    return ['joined'=>true,'games'=>se_game_list($pdo,(int)$event['id']),'room_key'=>$state['room_key']??null,'team_key'=>$team['team_key']??null];
}
function se_game_is_captain(PDO $pdo, array $event, array $reg): bool
{
    if (empty($reg['team_id'])) return false;
    $stmt = $pdo->prepare('SELECT captain_registration_id FROM se_teams WHERE id=? AND event_id=?');
    $stmt->execute([(int)$reg['team_id'],(int)$event['id']]);
    $captain = (int)($stmt->fetchColumn() ?: 0);
    if ($captain) return $captain === (int)$reg['id'];
    $stmt = $pdo->prepare("SELECT d.registration_id FROM se_devices d JOIN se_registrations r ON r.id=d.registration_id WHERE d.event_id=? AND r.team_id=? AND d.joined_games_at IS NOT NULL AND d.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) ORDER BY d.joined_games_at,d.id LIMIT 1");
    $stmt->execute([(int)$event['id'],(int)$reg['team_id']]);
    return (int)($stmt->fetchColumn() ?: 0) === (int)$reg['id'];
}

function se_game_answer(PDO $pdo, array $event, array $reg, array $in): array
{
    $roundId=se_int($in['round_id']??0,0); $stmt=$pdo->prepare('SELECT r.*,g.type,g.settings_json FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?'); $stmt->execute([$roundId,(int)$event['id']]); $round=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$round) throw new SeNotFoundException('Round not found.');
    if($round['type']==='trivia' && !se_game_is_captain($pdo,$event,$reg)) throw new SeRuleException('Only your captain can submit the team answer.','NOT_CAPTAIN');
    if(empty($round['opens_at'])||empty($round['closes_at'])) throw new SeRuleException('That round is not open.','ROUND_CLOSED');
    $now=se_epoch_ms(); $opens=se_epoch_ms(se_parse_datetime($round['opens_at'])); $closes=se_epoch_ms(se_parse_datetime($round['closes_at'])); $settings=se_game_settings($round); $grace=(int)($settings['grace_ms']??1500);
    if($now<$opens) throw new SeRuleException('Not yet — get ready.','TOO_EARLY');
    if($now>$closes+$grace || in_array($round['state'],['locked','revealed','scored','void'],true)) throw new SeRuleException("Time's up.",'ROUND_CLOSED');
    $item=se_round_item($pdo,$round); $choices=$item['payload']['choices']??[]; $choice=se_int($in['choice_index']??-1,-1);
    if($choice<0||$choice>=count($choices)) throw new SeValidationException('Choose one of the answers.',['choice_index']);
    $duration=max(1,$closes-$opens); $serverElapsed=$now-$opens; $clientElapsed=se_int($in['client_elapsed_ms']??$serverElapsed,0); $elapsed=max(0,min($duration,min($serverElapsed,max($serverElapsed-2500,$clientElapsed))));
    try { $stmt=$pdo->prepare('INSERT INTO se_answers(event_id,round_id,registration_id,team_id,role,choice_index,received_at,client_elapsed_ms,elapsed_ms,device_id) VALUES(?,?,?,?,?,?,?,?,?,?)'); $stmt->execute([(int)$event['id'],$roundId,(int)$reg['id'],$reg['team_id']??null,($round['type']==='trivia'?'captain':'player'),$choice,date('Y-m-d H:i:s.v'),$clientElapsed,$elapsed,$in['device_id']??null]); }
    catch(PDOException $e){if((int)$e->errorInfo[1]===1062)throw new SeRuleException('Your answer is already locked in.','ALREADY_ANSWERED');throw $e;}
    return ['accepted'=>true,'elapsed_ms'=>$elapsed];
}

function se_game_suggest(PDO $pdo, array $event, array $reg, array $in): array
{
    $roundId=se_int($in['round_id']??0,0); $choice=se_int($in['choice_index']??-1,-1);
    $stmt=$pdo->prepare("SELECT r.*,g.type FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?"); $stmt->execute([$roundId,(int)$event['id']]); $round=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$round) throw new SeNotFoundException('Round not found.');
    if($round['type']!=='trivia' || empty($round['closes_at']) || !in_array($round['state'],['armed','open'],true) || se_epoch_ms()>se_epoch_ms(se_parse_datetime($round['closes_at']))) throw new SeRuleException("Time's up.",'ROUND_CLOSED');
    $item=se_round_item($pdo,$round); $choices=$item['payload']['choices']??[]; if($choice<0||$choice>=count($choices))throw new SeValidationException('Choose one of the answers.',['choice_index']);
    $stmt=$pdo->prepare("INSERT INTO se_answers(event_id,round_id,registration_id,team_id,role,choice_index,received_at,elapsed_ms) VALUES(?,?,?,?, 'suggestion',?,NOW(3),0) ON DUPLICATE KEY UPDATE choice_index=VALUES(choice_index),received_at=VALUES(received_at)");
    $stmt->execute([(int)$event['id'],$roundId,(int)$reg['id'],$reg['team_id']??null,$choice]); return ['accepted'=>true];
}
function se_game_buzz(PDO $pdo, array $event, array $reg, array $in): array
{
    if (empty($reg['team_id'])) throw new SeRuleException('You need a team to buzz.', 'NOT_ELIGIBLE');
    $roundId = se_int($in['round_id'] ?? 0, 0);
    $stmt = $pdo->prepare('SELECT r.*,g.type game_type FROM se_rounds r JOIN se_games g ON g.id=r.game_id WHERE r.id=? AND r.event_id=?');
    $stmt->execute([$roundId,(int)$event['id']]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$round) throw new SeNotFoundException('Round not found.');
    if (!in_array($round['state'], ['armed','open'], true) || !$round['opens_at'] || !$round['closes_at']) throw new SeRuleException('Buzzing is closed.', 'ROUND_CLOSED');
    if (se_int($in['attempt'] ?? 0, 0) !== (int)$round['attempt']) throw new SeRuleException('That buzz window has moved on.', 'ROUND_CLOSED');
    $now = se_epoch_ms();
    $opens = se_epoch_ms(se_parse_datetime($round['opens_at']));
    $closes = se_epoch_ms(se_parse_datetime($round['closes_at']));
    $client = se_int($in['client_ms'] ?? $now, 0);
    if ($now < $opens || $client < $opens - 200) throw new SeRuleException('Not yet — get ready.', 'TOO_EARLY');
    if ($now > $closes) throw new SeRuleException("Time's up.", 'ROUND_CLOSED');
    $state = se_round_state_data($round);
    if (($round['game_type'] ?? '') === 'feud' && ($state['phase'] ?? '') === 'faceoff'
        && !in_array((int)$reg['id'], array_map('intval', [$state['rep_a'] ?? 0, $state['rep_b'] ?? 0]), true)) {
        throw new SeRuleException('Only the two face-off representatives can buzz.', 'NOT_ELIGIBLE');
    }
    if (in_array((int)$reg['team_id'], array_map('intval',$state['locked_out'] ?? []), true)) throw new SeRuleException('Your team is locked out until the next clue.', 'LOCKED_OUT');
    if ($client > $now + 200) $client = $now;
    $effective = se_buzz_effective($client,$opens,$now);
    $stmt = $pdo->prepare('INSERT INTO se_buzzes(event_id,round_id,attempt,team_id,registration_id,effective_ms,received_at) VALUES(?,?,?,?,?,?,NOW(3))');
    try {
        $stmt->execute([(int)$event['id'],$roundId,(int)$round['attempt'],(int)$reg['team_id'],(int)$reg['id'],$effective]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) throw new SeRuleException('Your team already buzzed.', 'ALREADY_BUZZED');
        throw $e;
    }
    return ['accepted'=>true,'effective_ms'=>$effective,'first_for_team'=>true];
}
function se_live_game_payload(PDO $pdo, array $event): ?array
{
    if (!se_game_ready($pdo)) return null;
    $stmt = $pdo->prepare("SELECT * FROM se_games WHERE event_id=? AND status IN ('live','ready') ORDER BY status='live' DESC,sort_order,id LIMIT 1");
    $stmt->execute([(int)$event['id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) return null;
    $stmt = $pdo->prepare('SELECT * FROM se_rounds WHERE game_id=? ORDER BY round_no DESC LIMIT 1');
    $stmt->execute([(int)$game['id']]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);
    $roundView = $round ? se_round_public($pdo,$round,in_array($round['state'],['revealed','scored'],true)) : null;
    if ($round && $game['type'] === 'feud') {
        $item = se_round_item($pdo,$round);
        $state = se_round_state_data($round);
        $revealed = array_map('intval',$state['revealed']??[]);
        $roundView['question'] = (string)($item['payload']['question']??'');
        $roundView['board'] = array_map(static fn(array $answer): array => ['id'=>(int)$answer['id'],'label'=>in_array((int)$answer['id'],$revealed,true)?(string)$answer['label']:null,'points'=>in_array((int)$answer['id'],$revealed,true)?(int)$answer['points']:null,'revealed'=>in_array((int)$answer['id'],$revealed,true)], se_feud_board($pdo,$event,(int)$round['deck_item_id'],true));
        $roundView['strikes'] = (int)($state['strikes']??0);
        $roundView['bank'] = (int)($state['bank']??0);
    }
    return ['id'=>(int)$game['id'],'type'=>$game['type'],'title'=>$game['title'],'status'=>$game['status'],'settings'=>se_game_settings($game),'round'=>$roundView];
}

/** Live mutations that keep the host version and the round row atomic. */
function se_round_next_live(PDO $pdo, array $event, array $game, bool $test, ?int $expected, int $actor): array
{
    $round = [];
    $state = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $game, $test, &$round): array {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(round_no),0)+1 FROM se_rounds WHERE game_id=?');
        $stmt->execute([(int)$game['id']]);
        $number = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT deck_item_id FROM se_game_items WHERE game_id=? ORDER BY sort_order LIMIT 1 OFFSET ' . max(0,$number-1));
        $stmt->execute([(int)$game['id']]);
        $itemId = $stmt->fetchColumn();
        if (!$itemId) throw new SeRuleException('No approved deck item remains.', 'NO_ITEMS');
        $stmt = $pdo->prepare('INSERT INTO se_rounds(event_id,game_id,round_no,deck_item_id,is_test) VALUES(?,?,?,?,?)');
        $stmt->execute([(int)$event['id'],(int)$game['id'],$number,(int)$itemId,$test?1:0]);
        $round = ['id'=>(int)$pdo->lastInsertId(),'round_no'=>$number,'state'=>'pending'];
        return ['active_game_id'=>(int)$game['id'],'active_round_id'=>(int)$round['id'],'scene'=>'game'];
    }, 'round_next', ['game_id'=>(int)$game['id']], $actor);
    return $round + ['version'=>(int)$state['version']];
}

function se_game_status_set(PDO $pdo, array $event, int $gameId, string $status, ?int $expected, int $actor): array
{
    if (!in_array($status, ['live', 'paused', 'finished'], true)) {
        throw new SeValidationException('Invalid game status.', ['status']);
    }
    return se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $gameId, $event, $status): array {
        $s = $pdo->prepare('UPDATE se_games SET status = ?, started_at = IF(? = \'live\', COALESCE(started_at, NOW()), started_at), finished_at = IF(? = \'finished\', NOW(), finished_at) WHERE id = ? AND event_id = ?');
        $s->execute([$status, $status, $status, $gameId, (int) $event['id']]);
        if (!$s->rowCount()) {
            throw new SeNotFoundException('Game not found.');
        }
        return [];
    }, 'game_op', ['game_id' => $gameId, 'status' => $status], $actor);
}

function se_round_transition_live(PDO $pdo, array $event, int $roundId, string $to, ?int $expected, int $actor, string $reason = ''): array
{
    return se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $roundId, $to, $reason): array {
        $s = $pdo->prepare('SELECT state FROM se_rounds WHERE id = ? FOR UPDATE');
        $s->execute([$roundId]);
        $old = $s->fetchColumn();
        if ($old === false) throw new SeNotFoundException('Round not found.');
        $allowed = ['pending' => ['armed', 'void'], 'armed' => ['open', 'locked', 'void'], 'open' => ['locked', 'void'], 'locked' => ['revealed', 'void'], 'revealed' => ['scored', 'void'], 'scored' => ['void']];
        if (!in_array($to, $allowed[$old] ?? [], true)) throw new SeRuleException('That round cannot make that transition.', 'STALE_STATE');
        $set = 'state = ?'; $args = [$to];
        if ($to === 'locked') { $set .= ', locked_at = NOW(3)'; }
        if ($to === 'revealed') { $set .= ', revealed_at = NOW(3)'; }
        if ($to === 'void') { $set .= ', void_reason = ?'; $args[] = $reason; }
        $args[] = $roundId; $args[] = $old;
        $s = $pdo->prepare("UPDATE se_rounds SET {$set} WHERE id = ? AND state = ?"); $s->execute($args);
        if (!$s->rowCount()) throw new SeRuleException('The round changed.', 'STALE_STATE');
        return ['active_round_id' => $roundId];
    }, 'round_op', ['round_id' => $roundId, 'to' => $to], $actor);
}

function se_round_arm_live(PDO $pdo, array $event, int $roundId, int $preroll, int $duration, ?int $expected, int $actor): array
{
    $result = [];
    $out = se_live_mutate($pdo, $event, $expected, static function () use ($pdo, $event, $roundId, $preroll, $duration, &$result): array {
        $result = se_round_arm($pdo, $event, $roundId, $preroll, $duration, null, 0);
        return ['active_round_id' => $roundId, 'scene' => 'game'];
    }, 'round_op', ['round_id' => $roundId, 'to' => 'armed'], $actor);
    return $result + ['round' => $result];
}
