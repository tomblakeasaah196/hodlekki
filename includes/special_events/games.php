<?php
// /includes/special_events/games.php
// Game decks, setup and content validation for Special Events PR5.

function se_game_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_games');
}

function se_game_payload_validate(string $type, array $payload): array
{
    $errors = [];
    $need = static function (string $key) use (&$errors, $payload): void { if (!isset($payload[$key]) || !is_string($payload[$key]) || trim($payload[$key]) === '') { $errors[] = "$key is required"; } };

    if ($type === 'mcq') {
        $need('prompt');

        if (!isset($payload['choices']) || !is_array($payload['choices']) || count($payload['choices']) < 2 || count($payload['choices']) > 4) {
            $errors[] = 'choices must contain 2 to 4 items';
        }

        if (!isset($payload['answer_index']) || !is_int($payload['answer_index']) || $payload['answer_index'] < 0 || $payload['answer_index'] >= count($payload['choices'] ?? [])) {
            $errors[] = 'answer_index is invalid';
        }
    } elseif ($type === 'open') {
        $need('prompt');
        $need('answer');
    } elseif ($type === 'emoji') {
        $need('emojis');
        $need('answer');
    } elseif ($type === 'verse') {
        $need('lead');
        $need('answer');
    } elseif ($type === 'charade') {
        $need('phrase');
        $need('category');
    } elseif ($type === 'clues') {
        if (!isset($payload['clues']) || !is_array($payload['clues']) || count($payload['clues']) < 3) {
            $errors[] = 'at least three clues are required';
        } $need('answer');
    } elseif ($type === 'survey') {
        $need('question');
    } else {
        $errors[] = 'unknown content type';
    }

    return $errors;
}

function se_deck_list(PDO $pdo, int $eventId, ?string $type = null): array
{
    if (!se_game_ready($pdo)) {
        return [];
    }
    $sql = "SELECT * FROM se_decks WHERE (scope='library' OR event_id=?)";
    $args = [$eventId];

    if ($type !== null) {
        $sql .= ' AND content_type=?';
        $args[] = $type;
    }
    $sql .= ' ORDER BY title';
    $s = $pdo->prepare($sql);
    $s->execute($args);

    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function se_deck_items(PDO $pdo, int $deckId, bool $approvedOnly = false): array
{
    if (!se_table_exists($pdo, 'se_deck_items')) {
        return [];
    }
    $sql = 'SELECT * FROM se_deck_items WHERE deck_id=?' . ($approvedOnly ? " AND review_status='approved'" : '') . ' ORDER BY sort_order,id';
    $s = $pdo->prepare($sql);
    $s->execute([$deckId]);

    return array_map(static function ($r) {
        $r['payload'] = json_decode($r['payload_json'], true) ?: [];

        return $r;
    }, $s->fetchAll(PDO::FETCH_ASSOC));
}
function se_deck_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $type = se_enum($in['content_type'] ?? '', SE_CONTENT_TYPES, '');
    $title = se_line($in['title'] ?? '', 120);

    if ($type === '' || $title === '') {
        throw new SeValidationException(['title' => 'Deck title and content type are required.', 'content_type' => 'Deck title and content type are required.'], 'Deck title and content type are required.');
    }
    $scope = ($in['scope'] ?? 'event') === 'library' ? 'library' : 'event';
    $id = se_int_or_null($in['id'] ?? null, 0);
    $eventId = $scope === 'event' ? (int) $event['id'] : null;

    if ($id) {
        $s = $pdo->prepare('UPDATE se_decks SET title=?,content_type=?,scope=?,event_id=?,description=?,translation=?,updated_at=NOW() WHERE id=? AND (event_id=? OR scope="library")');
        $s->execute([$title, $type, $scope, $eventId, se_line($in['description'] ?? '', 255), se_line($in['translation'] ?? 'KJV', 10), $id, (int) $event['id']]);
    } else {
        $s = $pdo->prepare('INSERT INTO se_decks(title,content_type,scope,event_id,description,translation,created_by) VALUES (?,?,?,?,?,?,?)');
        $s->execute([$title, $type, $scope, $eventId, se_line($in['description'] ?? '', 255), se_line($in['translation'] ?? 'KJV', 10), $actor]);
        $id = (int) $pdo->lastInsertId();
    }

    return $pdo->query('SELECT * FROM se_decks WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
}
function se_deck_item_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $deckId = se_int($in['deck_id'] ?? 0, 0);
    $type = se_str($in['content_type'] ?? '', 20);
    $payload = $in['payload'] ?? [];

    if (!is_array($payload)) {
        throw new SeValidationException(['payload' => 'Payload must be an object.'], 'Payload must be an object.');
    }
    $owner = $pdo->prepare("SELECT content_type FROM se_decks WHERE id=? AND (event_id=? OR scope='library')");
    $owner->execute([$deckId, (int) $event['id']]);
    $ownedType = $owner->fetchColumn();

    if ($ownedType === false) {
        throw new SeNotFoundException('Deck not found.');
    }

    if ((string) $ownedType !== $type) {
        throw new SeValidationException(['content_type' => 'Item type must match its deck.'], 'Item type must match its deck.');
    }
    $bad = se_game_payload_validate($type, $payload);

    if ($bad) {
        throw new SeValidationException(['payload' => implode(' ', $bad)], implode(' ', $bad));
    }
    $json = se_json_encode($payload);
    $ref = se_line($in['scripture_ref'] ?? '', 60) ?: null;
    $id = se_int_or_null($in['id'] ?? null, 0);

    if ($id) {
        $s = $pdo->prepare('UPDATE se_deck_items SET payload_json=?,scripture_ref=?,review_status="draft",updated_at=NOW() WHERE id=? AND deck_id=?');
        $s->execute([$json, $ref, $id, $deckId]);
    } else {
        $s = $pdo->prepare('INSERT INTO se_deck_items(deck_id,sort_order,payload_json,scripture_ref,source,created_by) VALUES(?,?,?,?,?,?)');
        $s->execute([$deckId, se_int($in['sort_order'] ?? 0, 0), $json, $ref, ($in['source'] ?? 'manual'), $actor]);
        $id = (int) $pdo->lastInsertId();
    }

    return $pdo->query('SELECT * FROM se_deck_items WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
}
function se_game_list(PDO $pdo, int $eventId): array
{
    if (!se_game_ready($pdo)) {
        return [];
    } $s = $pdo->prepare('SELECT * FROM se_games WHERE event_id=? ORDER BY sort_order,id');
    $s->execute([$eventId]);

    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function se_game_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $type = se_enum($in['type'] ?? '', SE_GAME_TYPES, '');

    if ($type === '') {
        throw new SeValidationException(['type' => 'Choose a game type.'], 'Choose a game type.');
    } $settings = $in['settings'] ?? [];

    if (!is_array($settings)) {
        $settings = [];
    } $s = $pdo->prepare('INSERT INTO se_games(event_id,type,title,settings_json,weight,status,sort_order,created_by) VALUES(?,?,?,?,?,"draft",?,?)');
    $s->execute([(int) $event['id'], $type, se_line($in['title'] ?? ucwords(str_replace('_', ' ', $type)), 120), se_json_encode($settings), (float) ($in['weight'] ?? 1), se_int($in['sort_order'] ?? 0, 0), $actor]);

    return ['id' => (int) $pdo->lastInsertId()];
}
function se_deck_items_review(PDO $pdo, array $event, array $in, int $actor): array
{
    $ids = $in['ids'] ?? [];
    $status = se_enum($in['status'] ?? '', ['draft', 'approved', 'rejected'], 'draft');

    if (!is_array($ids) || !$ids) {
        throw new SeValidationException(['ids' => 'Choose at least one deck item.'], 'Choose at least one deck item.');
    }
    $q = $pdo->prepare("UPDATE se_deck_items i JOIN se_decks d ON d.id=i.deck_id SET i.review_status=?,i.reviewed_by=?,i.reviewed_at=NOW() WHERE i.id=? AND (d.scope='library' OR d.event_id=?)");
    $n = 0;

    foreach ($ids as $id) {
        $q->execute([$status, $actor, (int) $id, (int) $event['id']]);
        $n += $q->rowCount();
    }

    return ['updated' => $n];
}
function se_game_items_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $game = se_int($in['game_id'] ?? 0, 0);
    $items = $in['item_ids'] ?? [];

    if (!is_array($items)) {
        throw new SeValidationException(['item_ids' => 'item_ids must be a list.'], 'item_ids must be a list.');
    }
    $owns = $pdo->prepare('SELECT 1 FROM se_games WHERE id=? AND event_id=?');
    $owns->execute([$game, (int) $event['id']]);

    if (!$owns->fetchColumn()) {
        throw new SeNotFoundException('Game not found.');
    }
    $pdo->beginTransaction();

    try {
        $pdo->prepare('DELETE FROM se_game_items WHERE game_id=?')->execute([$game]);
        $s = $pdo->prepare("INSERT INTO se_game_items(game_id,deck_item_id,sort_order) SELECT ?,i.id,? FROM se_deck_items i JOIN se_decks d ON d.id=i.deck_id WHERE i.id=? AND i.review_status='approved' AND (d.scope='library' OR d.event_id=?)");
        $added = 0;

        foreach (array_values($items) as $n => $id) {
            $s->execute([$game, $n, (int) $id, (int) $event['id']]);
            $added += $s->rowCount();
        }

        if ($added !== count($items)) {
            throw new SeValidationException(['item_ids' => 'One or more items are not approved for this event.'], 'One or more items are not approved for this event.');
        }
        $pdo->commit();

        return ['game_id' => $game, 'items' => $added];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function se_deck_generate(PDO $pdo, array $event, array $in, int $actor): array
{
    $type = se_enum($in['content_type'] ?? '', SE_CONTENT_TYPES, 'mcq');
    $count = se_int($in['count'] ?? 10, 1, 30, 10);
    $result = se_ai($pdo, 'deck_generate', [
        'content_type' => $type,
        'topic' => se_line($in['topic'] ?? 'joy and Bible basics', 160),
        'count' => $count,
        'difficulty_mix' => se_line($in['difficulty_mix'] ?? 'easy, medium, hard', 80),
        'avoid' => se_line($in['avoid_recent'] ?? '', 1000),
        'translation' => 'KJV',
    ], ['user_id' => $actor, 'event_id' => (int) $event['id']]);
    $job = se_ai_job_create($pdo, (int) $event['id'], 'deck_generate', $in, $result, $actor, 'ready');

    return ['job_id' => $job, 'status' => 'ready', 'result' => $result];
}

function se_deck_generate_apply(PDO $pdo, array $event, int $jobId, int $deckId, int $actor): array
{
    $job = se_ai_job_find($pdo, $jobId, (int) $event['id']);

    if (!$job || $job['status'] !== 'ready') {
        throw new SeRuleException('AI_JOB_INVALID', 'That AI result is no longer awaiting review.');
    }
    $decoded = json_decode((string) $job['result_json'], true) ?: [];
    $items = $decoded['items'] ?? [];
    $deck = $pdo->prepare('SELECT content_type FROM se_decks WHERE id = ? AND (scope = "library" OR event_id = ?)');
    $deck->execute([$deckId, (int) $event['id']]);
    $type = (string) $deck->fetchColumn();

    if (!in_array($type, SE_CONTENT_TYPES, true)) {
        throw new SeNotFoundException('Deck not found.');
    }
    $created = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $payload = $item['payload'] ?? [];

        if (!is_array($payload) || se_game_payload_validate($type, $payload)) {
            continue;
        }
        $ref = se_line($item['ref'] ?? '', 60);
        $verse = $ref !== '' ? se_bible_lookup($pdo, $ref, 'KJV') : null;
        se_deck_item_save($pdo, $event, ['deck_id' => $deckId, 'content_type' => $type, 'payload' => $payload, 'scripture_ref' => $verse['ref_display'] ?? $ref, 'source' => 'ai'], $actor);
        $created++;
    }
    se_ai_job_mark_applied($pdo, $jobId, $actor);

    return ['created' => $created, 'job_id' => $jobId];
}
