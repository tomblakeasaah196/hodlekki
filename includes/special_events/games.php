<?php
// /includes/special_events/games.php
//
// Games authoring (guide §11.1, §11.14, §12.5 "Games", Appendix C): decks and
// their items, the games of an event, which items each game plays, and AI
// deck generation with KJV enrichment (§15.4, §15.9).
//
// This is Studio-side work. The round engine that runs a game on the night
// is games_engine.php, the ledger is scoring.php, and Who Am I?, Charades,
// Family Feud and the finale are party_games.php.
//
// Two rules hold everywhere below:
//   * only APPROVED items reach a game (a person reviewed every question the
//     room will see; AI output arrives as a draft, §15.1);
//   * a deck is visible to an event when it belongs to that event or sits in
//     the shared library — never another event's private deck.

/** Are the games tables migrated yet? (§9.4) */
function se_game_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_games');
}

// --------------------------------------------------------------------------
// Item payloads (Appendix C)
// --------------------------------------------------------------------------

/**
 * Clean and validate one item payload.
 *
 * Returns the clean payload and a map of field => problem. An empty map
 * means the item is valid. Nothing here throws: the Studio shows every
 * problem at once, and AI output is checked item by item.
 *
 * @return array{0: array<string,mixed>, 1: array<string,string>}
 */
function se_deck_payload_clean(string $type, array $in): array
{
    $errors = [];
    $out    = [];

    $text = static fn(string $key, int $max): string => se_line($in[$key] ?? '', $max);

    // "accept" lists arrive as an array or as one comma/newline separated
    // string from a text box; both become lower-cased alternatives.
    $accept = static function (mixed $raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[,\n]/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $list = [];
        foreach ($raw as $value) {
            $value = mb_strtolower(se_line($value, 60), 'UTF-8');
            if ($value !== '' && !in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return array_slice($list, 0, 8);
    };

    // Choices keep their positions, so answer_index keeps pointing at the
    // right one; blank boxes are dropped and the index is shifted to match.
    $choices = static function (array $in, bool $required) use (&$errors, &$out): void {
        $raw = $in['choices'] ?? null;
        if ($raw === null || $raw === [] || $raw === '') {
            if ($required) {
                $errors['choices'] = 'Give at least two answers to choose from.';
            }
            return;
        }
        if (!is_array($raw)) {
            $errors['choices'] = 'Give at least two answers to choose from.';
            return;
        }

        $answer = isset($in['answer_index']) && is_numeric($in['answer_index']) ? (int) $in['answer_index'] : -1;
        $kept   = [];
        $index  = -1;
        foreach (array_values($raw) as $i => $choice) {
            $choice = se_line($choice, 60);
            if ($choice === '') {
                continue;
            }
            if ($i === $answer) {
                $index = count($kept);
            }
            $kept[] = $choice;
        }

        if (count($kept) < 2 || count($kept) > 4) {
            $errors['choices'] = 'Use between two and four answers.';
            return;
        }
        if (count(array_unique(array_map(static fn(string $c): string => mb_strtolower($c, 'UTF-8'), $kept))) !== count($kept)) {
            $errors['choices'] = 'Two of the answers are the same.';
            return;
        }
        if ($index < 0) {
            $errors['answer_index'] = 'Mark which answer is correct.';
            return;
        }

        $out['choices']      = $kept;
        $out['answer_index'] = $index;
    };

    switch ($type) {
        case 'mcq':
            $out['prompt'] = $text('prompt', 160);
            if ($out['prompt'] === '') {
                $errors['prompt'] = 'Write the question.';
            }
            $choices($in, true);
            $explanation = $text('explanation', 200);
            if ($explanation !== '') {
                $out['explanation'] = $explanation;
            }
            break;

        case 'open':
            $out['prompt'] = $text('prompt', 160);
            $out['answer'] = $text('answer', 60);
            $out['accept'] = $accept($in['accept'] ?? []);
            if ($out['prompt'] === '') {
                $errors['prompt'] = 'Write the question.';
            }
            if ($out['answer'] === '') {
                $errors['answer'] = 'Write the answer the host will listen for.';
            }
            break;

        case 'emoji':
            $out['emojis'] = $text('emojis', 60);
            $out['answer'] = $text('answer', 60);
            $out['accept'] = $accept($in['accept'] ?? []);
            if ($out['emojis'] === '') {
                $errors['emojis'] = 'Add the emoji puzzle.';
            }
            if ($out['answer'] === '') {
                $errors['answer'] = 'Write the answer.';
            }
            $choices($in, false);
            break;

        case 'verse':
            $out['lead']   = $text('lead', 400);
            $out['answer'] = $text('answer', 400);
            if ($out['lead'] === '') {
                $errors['lead'] = 'Fetch the verse, then choose where it splits.';
            }
            if ($out['answer'] === '') {
                $errors['answer'] = 'The verse needs an ending to finish.';
            }
            $choices($in, false);
            break;

        case 'clues':
            $clues = [];
            foreach ((array) ($in['clues'] ?? []) as $clue) {
                $clue = se_line($clue, 90);
                if ($clue !== '') {
                    $clues[] = $clue;
                }
            }
            $out['clues']  = array_slice($clues, 0, 5);
            $out['answer'] = $text('answer', 40);
            $out['accept'] = $accept($in['accept'] ?? []);
            if (count($clues) < 3) {
                $errors['clues'] = 'Give at least three clues, hardest first.';
            } elseif (count($clues) > 5) {
                $errors['clues'] = 'Five clues at most.';
            }
            if ($out['answer'] === '') {
                $errors['answer'] = 'Who is it?';
            }
            break;

        case 'charade':
            $out['phrase']   = $text('phrase', 40);
            $out['category'] = se_enum($in['category'] ?? 'story', SE_CHARADE_CATEGORIES, 'story');
            $hint = $text('hint', 60);
            if ($hint !== '') {
                $out['hint'] = $hint;
            }
            if ($out['phrase'] === '') {
                $errors['phrase'] = 'Write the phrase to act out.';
            }
            break;

        case 'survey':
            $out['question'] = $text('question', 100);
            if ($out['question'] === '') {
                $errors['question'] = 'Write the survey question.';
            }
            break;

        default:
            $errors['content_type'] = 'Unknown content type.';
    }

    return [$out, $errors];
}

/** Validation errors for a payload, as a list (kept for older callers). */
function se_game_payload_validate(string $type, array $payload): array
{
    return array_values(se_deck_payload_clean($type, $payload)[1]);
}

/**
 * Can this item be played in a game of this type?
 *
 * Live Quiz and Trivia show answer tiles, so they need choices; a buzzer
 * game reads the question aloud and the host judges the spoken answer.
 */
function se_game_item_playable(string $gameType, string $contentType, array $payload): bool
{
    if (!in_array($contentType, SE_GAME_CONTENT_TYPES[$gameType] ?? [], true)) {
        return false;
    }
    if (in_array($gameType, ['live_quiz', 'trivia'], true)) {
        return count($payload['choices'] ?? []) >= 2 && isset($payload['answer_index']);
    }

    return true;
}

/** The text a host reads as "the answer", whatever the content type. */
function se_item_answer_text(string $contentType, array $payload): string
{
    if (isset($payload['choices'], $payload['answer_index']) && in_array($contentType, ['mcq', 'verse', 'emoji'], true)) {
        return (string) ($payload['choices'][(int) $payload['answer_index']] ?? '');
    }

    return match ($contentType) {
        'open', 'emoji', 'verse', 'clues' => (string) ($payload['answer'] ?? ''),
        'charade' => (string) ($payload['phrase'] ?? ''),
        default   => '',
    };
}

/** A one-line summary of an item for lists ("Who was swallowed by…"). */
function se_item_summary(string $contentType, array $payload): string
{
    return match ($contentType) {
        'mcq', 'open' => (string) ($payload['prompt'] ?? ''),
        'emoji'       => (string) ($payload['emojis'] ?? ''),
        'verse'       => (string) ($payload['lead'] ?? '') . ' …',
        'clues'       => (string) (($payload['clues'][0] ?? '') . ' …'),
        'charade'     => (string) ($payload['phrase'] ?? ''),
        'survey'      => (string) ($payload['question'] ?? ''),
        default       => '',
    };
}

/**
 * Split a fetched verse into the part the room hears and the part they
 * finish (§15.4 step 3): about 55–65 % of the words lead.
 *
 * @return array{lead: string, answer: string}
 */
function se_verse_split(string $text): array
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (count($words) < 4) {
        return ['lead' => trim($text), 'answer' => ''];
    }

    $cut = max(2, min(count($words) - 2, (int) round(count($words) * 0.6)));

    return [
        'lead'   => implode(' ', array_slice($words, 0, $cut)),
        'answer' => implode(' ', array_slice($words, $cut)),
    ];
}

// --------------------------------------------------------------------------
// Decks
// --------------------------------------------------------------------------

/** One deck the event may use, or null. */
function se_deck_find(PDO $pdo, int $eventId, int $deckId): ?array
{
    if (!se_game_ready($pdo) || $deckId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_decks WHERE id = ? AND (scope = 'library' OR event_id = ?)");
    $stmt->execute([$deckId, $eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Every deck the event may use, with its item counts by review status.
 *
 * Counts come from correlated subqueries rather than a GROUP BY over d.*,
 * which MariaDB's ONLY_FULL_GROUP_BY rejects.
 */
function se_deck_list(PDO $pdo, int $eventId, ?string $type = null): array
{
    if (!se_game_ready($pdo)) {
        return [];
    }

    $sql = "SELECT d.*,
                   (SELECT COUNT(*) FROM se_deck_items i WHERE i.deck_id = d.id) AS items_total,
                   (SELECT COUNT(*) FROM se_deck_items i WHERE i.deck_id = d.id AND i.review_status = 'approved') AS items_approved,
                   (SELECT COUNT(*) FROM se_deck_items i WHERE i.deck_id = d.id AND i.review_status = 'draft') AS items_draft
              FROM se_decks d
             WHERE (d.scope = 'library' OR d.event_id = ?)";
    $args = [$eventId];
    if ($type !== null && $type !== '') {
        $sql .= ' AND d.content_type = ?';
        $args[] = $type;
    }
    $sql .= ' ORDER BY d.scope DESC, d.title, d.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    return array_map('se_deck_public', $stmt->fetchAll());
}

/** The Studio view of a deck row. */
function se_deck_public(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'title'          => (string) $row['title'],
        'content_type'   => (string) $row['content_type'],
        'content_label'  => SE_CONTENT_TYPE_LABELS[$row['content_type']] ?? (string) $row['content_type'],
        'scope'          => (string) $row['scope'],
        'description'    => $row['description'] !== null ? (string) $row['description'] : '',
        'items_total'    => (int) ($row['items_total'] ?? 0),
        'items_approved' => (int) ($row['items_approved'] ?? 0),
        'items_draft'    => (int) ($row['items_draft'] ?? 0),
    ];
}

/** Create or rename a deck. The content type is fixed once it has items. */
function se_deck_save(PDO $pdo, array $event, array $in, int $actor): array
{
    if (!se_game_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Games are not available yet.');
    }

    $eventId = (int) $event['id'];
    $title   = se_line($in['title'] ?? '', 120);
    $type    = se_enum($in['content_type'] ?? '', SE_CONTENT_TYPES, '');
    $scope   = ($in['scope'] ?? 'event') === 'library' ? 'library' : 'event';
    $id      = se_int_or_null($in['deck_id'] ?? null, 1);

    $errors = [];
    if ($title === '') {
        $errors['title'] = 'Give the deck a name.';
    }
    if ($type === '' && $id === null) {
        $errors['content_type'] = 'Choose what kind of questions it holds.';
    }
    if ($errors) {
        throw new SeValidationException($errors);
    }

    $description = se_line($in['description'] ?? '', 255) ?: null;

    if ($id !== null) {
        $deck = se_deck_find($pdo, $eventId, $id);
        if (!$deck) {
            throw new SeNotFoundException('We could not find that deck.');
        }
        $pdo->prepare("UPDATE se_decks SET title = ?, description = ? WHERE id = ?")
            ->execute([$title, $description, $id]);
    } else {
        $pdo->prepare(
            "INSERT INTO se_decks (title, content_type, scope, event_id, description, translation, created_by)
             VALUES (?, ?, ?, ?, ?, 'KJV', ?)"
        )->execute([$title, $type, $scope, $scope === 'event' ? $eventId : null, $description, $actor]);
        $id = (int) $pdo->lastInsertId();
    }

    se_audit($pdo, $eventId, 'game_op:deck_save', ['deck_id' => $id, 'title' => $title], 'deck', $id, $actor);

    foreach (se_deck_list($pdo, $eventId) as $deck) {
        if ($deck['id'] === $id) {
            return $deck;
        }
    }

    throw new SeNotFoundException('We could not find that deck.');
}

/** Delete one of this event's decks. Questions already in a game block it. */
function se_deck_delete(PDO $pdo, array $event, int $deckId, int $actor): void
{
    $deck = se_deck_find($pdo, (int) $event['id'], $deckId);
    if (!$deck) {
        throw new SeNotFoundException('We could not find that deck.');
    }
    if ((string) $deck['scope'] !== 'event' || (int) $deck['event_id'] !== (int) $event['id']) {
        throw new SeRuleException('FORBIDDEN', 'Library decks are shared with other events, so they cannot be deleted here.');
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_game_items gi JOIN se_deck_items i ON i.id = gi.deck_item_id WHERE i.deck_id = ?"
    );
    $stmt->execute([$deckId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new SeRuleException('IN_USE', 'Some of these questions are in a game. Take them out of the game first.');
    }

    $pdo->prepare("DELETE FROM se_decks WHERE id = ?")->execute([$deckId]);
    se_audit($pdo, (int) $event['id'], 'game_op:deck_delete', ['deck_id' => $deckId, 'title' => $deck['title']], 'deck', $deckId, $actor);
}

// --------------------------------------------------------------------------
// Deck items
// --------------------------------------------------------------------------

/** The items of one deck, in order, with their payload decoded. */
function se_deck_items(PDO $pdo, int $deckId, bool $approvedOnly = false): array
{
    if (!se_table_exists($pdo, 'se_deck_items')) {
        return [];
    }

    $sql = "SELECT i.*, d.content_type,
                   (SELECT COUNT(*) FROM se_game_items gi WHERE gi.deck_item_id = i.id) AS in_games
              FROM se_deck_items i
              JOIN se_decks d ON d.id = i.deck_id
             WHERE i.deck_id = ?"
        . ($approvedOnly ? " AND i.review_status = 'approved'" : '')
        . " ORDER BY i.sort_order, i.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deckId]);

    return array_map('se_deck_item_public', $stmt->fetchAll());
}

/** The Studio view of one item row (with `content_type` joined in). */
function se_deck_item_public(array $row): array
{
    $payload = se_json_decode($row['payload_json'] ?? null);
    $type    = (string) ($row['content_type'] ?? '');

    return [
        'id'             => (int) $row['id'],
        'deck_id'        => (int) $row['deck_id'],
        'content_type'   => $type,
        'payload'        => $payload,
        'summary'        => se_item_summary($type, $payload),
        'answer'         => se_item_answer_text($type, $payload),
        'difficulty'     => (string) ($row['difficulty'] ?? 'medium'),
        'scripture_ref'  => $row['scripture_ref'] !== null ? (string) $row['scripture_ref'] : '',
        'scripture_text' => $row['scripture_text'] !== null ? (string) $row['scripture_text'] : '',
        'review_status'  => (string) $row['review_status'],
        'source'         => (string) $row['source'],
        'in_games'       => (int) ($row['in_games'] ?? 0),
        'times_used'     => (int) ($row['times_used'] ?? 0),
    ];
}

/** One item, provided its deck is visible to the event. */
function se_deck_item_find(PDO $pdo, int $eventId, int $itemId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, d.content_type, d.scope, d.event_id AS deck_event_id
           FROM se_deck_items i JOIN se_decks d ON d.id = i.deck_id
          WHERE i.id = ? AND (d.scope = 'library' OR d.event_id = ?)"
    );
    $stmt->execute([$itemId, $eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Create or edit one item.
 *
 * A question a producer types is reviewed by the act of typing it, so a
 * Studio save is approved unless the caller asks for a draft. AI output never
 * comes through here unreviewed: it is added by se_deck_generate_apply().
 */
function se_deck_item_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $eventId = (int) $event['id'];
    $deck    = se_deck_find($pdo, $eventId, se_int($in['deck_id'] ?? 0, 0));
    if (!$deck) {
        throw new SeNotFoundException('We could not find that deck.');
    }
    $type = (string) $deck['content_type'];

    $raw = $in['payload'] ?? [];
    if (!is_array($raw)) {
        throw new SeValidationException(['payload' => 'Fill in the question.']);
    }

    [$payload, $errors] = se_deck_payload_clean($type, $raw);
    if ($errors) {
        throw new SeValidationException($errors, reset($errors));
    }

    $ref        = se_line($in['scripture_ref'] ?? '', 60) ?: null;
    $refText    = se_str($in['scripture_text'] ?? '', 2000) ?: null;
    $difficulty = se_enum($in['difficulty'] ?? 'medium', ['easy', 'medium', 'hard'], 'medium');
    $status     = se_bool($in['draft'] ?? false) ? 'draft' : 'approved';
    $source     = se_enum($in['source'] ?? 'manual', ['manual', 'ai', 'import'], 'manual');
    $json       = se_json_encode($payload);
    $id         = se_int_or_null($in['item_id'] ?? null, 1);

    if ($id !== null) {
        $existing = se_deck_item_find($pdo, $eventId, $id);
        if (!$existing || (int) $existing['deck_id'] !== (int) $deck['id']) {
            throw new SeNotFoundException('We could not find that question.');
        }
        $pdo->prepare(
            "UPDATE se_deck_items
                SET payload_json = ?, scripture_ref = ?, scripture_text = ?, difficulty = ?,
                    review_status = ?, reviewed_by = ?, reviewed_at = IF(? = 'approved', NOW(), NULL)
              WHERE id = ?"
        )->execute([$json, $ref, $refText, $difficulty, $status, $status === 'approved' ? $actor : null, $status, $id]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM se_deck_items WHERE deck_id = ?");
        $stmt->execute([(int) $deck['id']]);
        $order = (int) $stmt->fetchColumn();

        $pdo->prepare(
            "INSERT INTO se_deck_items
                (deck_id, sort_order, payload_json, difficulty, scripture_ref, scripture_text,
                 review_status, reviewed_by, reviewed_at, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'approved', NOW(), NULL), ?, ?)"
        )->execute([
            (int) $deck['id'], $order, $json, $difficulty, $ref, $refText,
            $status, $status === 'approved' ? $actor : null, $status, $source, $actor,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $row = se_deck_item_find($pdo, $eventId, $id);

    return se_deck_item_public($row + ['in_games' => 0]);
}

/** Delete one item. An item a game still plays cannot go. */
function se_deck_item_delete(PDO $pdo, array $event, int $itemId, int $actor): void
{
    $item = se_deck_item_find($pdo, (int) $event['id'], $itemId);
    if (!$item) {
        throw new SeNotFoundException('We could not find that question.');
    }
    if ((string) $item['scope'] === 'library' && (int) ($item['deck_event_id'] ?? 0) !== (int) $event['id']) {
        throw new SeRuleException('FORBIDDEN', 'This question is in the shared library, so it cannot be deleted here.');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_game_items WHERE deck_item_id = ?");
    $stmt->execute([$itemId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new SeRuleException('IN_USE', 'This question is in a game. Take it out of the game first.');
    }

    $pdo->prepare("DELETE FROM se_deck_items WHERE id = ?")->execute([$itemId]);
    se_audit($pdo, (int) $event['id'], 'game_op:item_delete', ['item_id' => $itemId], 'deck_item', $itemId, $actor);
}

/** Approve, reject or send back to draft a batch of items. */
function se_deck_items_review(PDO $pdo, array $event, array $in, int $actor): array
{
    $ids    = $in['ids'] ?? [];
    $status = se_enum($in['status'] ?? '', ['draft', 'approved', 'rejected'], '');
    if (!is_array($ids) || !$ids || $status === '') {
        throw new SeValidationException(['ids' => 'Choose at least one question.']);
    }

    $stmt = $pdo->prepare(
        "UPDATE se_deck_items i JOIN se_decks d ON d.id = i.deck_id
            SET i.review_status = ?, i.reviewed_by = ?, i.reviewed_at = NOW()
          WHERE i.id = ? AND (d.scope = 'library' OR d.event_id = ?)"
    );

    $updated = 0;
    foreach (array_slice($ids, 0, 200) as $id) {
        // A rejected item cannot stay in a game.
        if ($status === 'rejected') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM se_game_items WHERE deck_item_id = ?");
            $check->execute([(int) $id]);
            if ((int) $check->fetchColumn() > 0) {
                continue;
            }
        }
        $stmt->execute([$status, $actor, (int) $id, (int) $event['id']]);
        $updated += $stmt->rowCount();
    }

    return ['updated' => $updated, 'status' => $status];
}

// --------------------------------------------------------------------------
// Games
// --------------------------------------------------------------------------

/**
 * A game's settings: the §11.14 defaults for its type with the stored values
 * on top. Only keys the type knows survive, so an old or hand-edited row can
 * never smuggle a setting the engine does not expect.
 */
function se_game_settings(array $game): array
{
    $type     = (string) ($game['type'] ?? $game['game_type'] ?? '');
    $defaults = SE_GAME_DEFAULTS[$type] ?? [];
    $stored   = is_array($game['settings_json'] ?? null)
        ? $game['settings_json']
        : se_json_decode($game['settings_json'] ?? null);

    return se_game_settings_clean($type, $stored + $defaults);
}

/** Clamp every setting into a range the engine and the screens can live with. */
function se_game_settings_clean(string $type, array $in): array
{
    $defaults = SE_GAME_DEFAULTS[$type] ?? [];
    $out      = [];

    $ranges = [
        'preroll_ms'        => [2500, 10000],
        'duration_ms'       => [5000, 120000],
        'grace_ms'          => [0, 5000],
        'window_ms'         => [3000, 60000],
        'reopen_window_ms'  => [3000, 60000],
        'faceoff_window_ms' => [3000, 60000],
        'turn_ms'           => [10000, 180000],
        'team_base'         => [0, 5000],
        'points_correct'    => [0, 5000],
        'points_per_word'   => [0, 2000],
        'wrong_penalty'     => [0, 2000],
        'max_passes'        => [0, 10],
    ];

    foreach ($defaults as $key => $default) {
        $value = $in[$key] ?? $default;

        if (is_bool($default)) {
            $out[$key] = se_bool($value);
        } elseif (is_int($default)) {
            [$min, $max] = $ranges[$key] ?? [0, 100000];
            $out[$key] = se_int($value, $min, $max, $default);
        } elseif (is_array($default)) {
            $list = is_array($value) ? array_values($value) : $default;
            $list = array_map(static fn($n): int => se_int($n, 0, 5000, 0), $list);
            $out[$key] = $list ?: $default;
        } else {
            $out[$key] = $key === 'points_mode'
                ? se_enum($value, ['standard', 'double', 'none'], (string) $default)
                : se_line($value, 40);
        }
    }

    return $out;
}

/** One game of the event, or null. */
function se_game_find(PDO $pdo, int $eventId, int $gameId): ?array
{
    if (!se_game_ready($pdo) || $gameId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_games WHERE id = ? AND event_id = ?");
    $stmt->execute([$gameId, $eventId]);

    return $stmt->fetch() ?: null;
}

/** Every game of the event, with item and round counts. */
function se_game_list(PDO $pdo, int $eventId): array
{
    if (!se_game_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT g.*,
                (SELECT COUNT(*) FROM se_game_items gi WHERE gi.game_id = g.id) AS items_total,
                (SELECT COUNT(*) FROM se_rounds r WHERE r.game_id = g.id AND r.state = 'scored') AS rounds_scored,
                (SELECT COUNT(*) FROM se_rounds r WHERE r.game_id = g.id AND r.state <> 'void') AS rounds_played
           FROM se_games g
          WHERE g.event_id = ?
          ORDER BY g.sort_order, g.id"
    );
    $stmt->execute([$eventId]);

    return array_map('se_game_public', $stmt->fetchAll());
}

/** The Studio and console view of a game row. */
function se_game_public(array $row): array
{
    $type = (string) $row['type'];

    return [
        'id'            => (int) $row['id'],
        'type'          => $type,
        'type_label'    => SE_GAME_TYPE_LABELS[$type] ?? $type,
        'title'         => (string) $row['title'],
        'status'        => (string) $row['status'],
        'weight'        => (float) $row['weight'],
        'sort_order'    => (int) $row['sort_order'],
        'settings'      => se_game_settings($row),
        'content_types' => SE_GAME_CONTENT_TYPES[$type] ?? [],
        'items_total'   => (int) ($row['items_total'] ?? 0),
        'rounds_played' => (int) ($row['rounds_played'] ?? 0),
        'rounds_scored' => (int) ($row['rounds_scored'] ?? 0),
    ];
}

/** Create or edit a game. Its type is fixed once it has questions or rounds. */
function se_game_save(PDO $pdo, array $event, array $in, int $actor): array
{
    if (!se_game_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Games are not available yet.');
    }

    $eventId = (int) $event['id'];
    $id      = se_int_or_null($in['game_id'] ?? null, 1);
    $type    = se_enum($in['type'] ?? '', SE_GAME_TYPES, '');
    $existing = $id !== null ? se_game_find($pdo, $eventId, $id) : null;

    if ($id !== null && !$existing) {
        throw new SeNotFoundException('We could not find that game.');
    }
    if ($existing) {
        $type = (string) $existing['type'];
    }
    if ($type === '') {
        throw new SeValidationException(['type' => 'Choose a game type.']);
    }

    $title    = se_line($in['title'] ?? '', 120) ?: (SE_GAME_TYPE_LABELS[$type] ?? $type);
    $weight   = round(se_float($in['weight'] ?? ($existing['weight'] ?? 1), 0.25, 5.0, 1.0), 2);
    $current  = $existing ? se_game_settings($existing) : (SE_GAME_DEFAULTS[$type] ?? []);
    $incoming = is_array($in['settings'] ?? null) ? $in['settings'] : [];
    $settings = se_game_settings_clean($type, $incoming + $current);

    if ($existing) {
        $pdo->prepare("UPDATE se_games SET title = ?, weight = ?, settings_json = ? WHERE id = ?")
            ->execute([$title, $weight, se_json_encode($settings), $id]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM se_games WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $order = (int) $stmt->fetchColumn();

        $pdo->prepare(
            "INSERT INTO se_games (event_id, type, title, settings_json, weight, status, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)"
        )->execute([$eventId, $type, $title, se_json_encode($settings), $weight, $order, $actor]);
        $id = (int) $pdo->lastInsertId();
    }

    se_audit($pdo, $eventId, 'game_op:save', ['game_id' => $id, 'type' => $type, 'title' => $title], 'game', $id, $actor);

    foreach (se_game_list($pdo, $eventId) as $game) {
        if ($game['id'] === $id) {
            return $game;
        }
    }

    throw new SeNotFoundException('We could not find that game.');
}

/**
 * Delete a game that has not really been played. Rehearsal rounds do not
 * count; a scored real round does, because its points are on the ledger.
 */
function se_game_delete(PDO $pdo, array $event, int $gameId, int $actor): void
{
    $game = se_game_find($pdo, (int) $event['id'], $gameId);
    if (!$game) {
        throw new SeNotFoundException('We could not find that game.');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_rounds WHERE game_id = ? AND is_test = 0 AND state <> 'void'");
    $stmt->execute([$gameId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new SeRuleException('IN_USE', 'This game has been played tonight, so it stays for the record.');
    }

    $pdo->prepare("DELETE FROM se_games WHERE id = ?")->execute([$gameId]);
    se_audit($pdo, (int) $event['id'], 'game_op:delete', ['game_id' => $gameId, 'title' => $game['title']], 'game', $gameId, $actor);
}

/** The items a game will play, in order. */
function se_game_items(PDO $pdo, int $gameId): array
{
    if (!se_game_ready($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT i.*, d.content_type, d.title AS deck_title, gi.sort_order AS game_order,
                (SELECT COUNT(*) FROM se_game_items x WHERE x.deck_item_id = i.id) AS in_games
           FROM se_game_items gi
           JOIN se_deck_items i ON i.id = gi.deck_item_id
           JOIN se_decks d ON d.id = i.deck_id
          WHERE gi.game_id = ?
          ORDER BY gi.sort_order, i.id"
    );
    $stmt->execute([$gameId]);

    return array_map(static function (array $row): array {
        return se_deck_item_public($row) + ['deck_title' => (string) $row['deck_title']];
    }, $stmt->fetchAll());
}

/**
 * Replace the ordered list of items a game plays.
 *
 * Every item must be approved, visible to this event and playable by this
 * game type — the whole list is refused otherwise, so a game never holds a
 * question the producer did not see.
 */
function se_game_items_save(PDO $pdo, array $event, array $in, int $actor): array
{
    $eventId = (int) $event['id'];
    $game    = se_game_find($pdo, $eventId, se_int($in['game_id'] ?? 0, 0));
    if (!$game) {
        throw new SeNotFoundException('We could not find that game.');
    }

    $ids = $in['item_ids'] ?? [];
    if (!is_array($ids)) {
        throw new SeValidationException(['item_ids' => 'Choose the questions for this game.']);
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) > 200) {
        throw new SeValidationException(['item_ids' => 'That is more than 200 questions — split it into two games.']);
    }

    foreach ($ids as $itemId) {
        $item = se_deck_item_find($pdo, $eventId, $itemId);
        if (!$item) {
            throw new SeNotFoundException('One of those questions is not available to this event.');
        }
        if ((string) $item['review_status'] !== 'approved') {
            throw new SeRuleException('NOT_APPROVED', 'Approve every question before adding it to a game.');
        }
        if (!se_game_item_playable((string) $game['type'], (string) $item['content_type'], se_json_decode($item['payload_json']))) {
            throw new SeRuleException('NOT_PLAYABLE', 'One of those questions cannot be played in a '
                . (SE_GAME_TYPE_LABELS[$game['type']] ?? $game['type']) . ' game.');
        }
    }

    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare("DELETE FROM se_game_items WHERE game_id = ?")->execute([(int) $game['id']]);
        $insert = $pdo->prepare("INSERT INTO se_game_items (game_id, deck_item_id, sort_order) VALUES (?, ?, ?)");
        foreach ($ids as $order => $itemId) {
            $insert->execute([(int) $game['id'], $itemId, $order + 1]);
        }

        // A game with questions is ready to start; one without is a draft.
        // Live, paused and finished games keep their status.
        if (in_array((string) $game['status'], ['draft', 'ready'], true)) {
            $pdo->prepare("UPDATE se_games SET status = ? WHERE id = ?")
                ->execute([$ids ? 'ready' : 'draft', (int) $game['id']]);
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

    se_audit($pdo, $eventId, 'game_op:items', ['game_id' => (int) $game['id'], 'items' => count($ids)], 'game', (int) $game['id'], $actor);

    return ['game_id' => (int) $game['id'], 'items' => se_game_items($pdo, (int) $game['id'])];
}

// --------------------------------------------------------------------------
// AI deck generation (§15.4)
// --------------------------------------------------------------------------

/** The payload shape each content type must come back in (prompt text). */
const SE_DECK_AI_SHAPES = [
    'mcq'     => '{"prompt": "question, at most 160 characters", "choices": ["4 short answers, at most 60 characters each"], "answer_index": 0, "explanation": "one friendly sentence, at most 200 characters"}',
    'open'    => '{"prompt": "question, at most 160 characters", "answer": "the answer, at most 60 characters", "accept": ["other spellings or short forms the host may accept"]}',
    'emoji'   => '{"emojis": "3 to 6 emoji that picture a Bible story", "answer": "the story, at most 60 characters", "accept": ["other ways to say it"]}',
    'verse'   => '{} (leave the payload empty: the system fetches the KJV text from the reference and builds the lead and the ending)',
    'clues'   => '{"clues": ["5 clues, hardest first, at most 90 characters each"], "answer": "the person, at most 40 characters", "accept": ["other names"]}',
    'charade' => '{"phrase": "something to act out, at most 40 characters", "category": "person | story | object | place | miracle | parable", "hint": "optional, at most 60 characters"}',
    'survey'  => '{"question": "a Family Feud survey question, at most 100 characters, with many possible fun answers"}',
];

/**
 * Ask the AI for a batch of items and prepare them for review.
 *
 * Nothing is written to the deck here: the producer picks which items to
 * keep in the review list, and se_deck_generate_apply() adds those (§15.1).
 */
function se_deck_generate(PDO $pdo, array $event, array $in, int $actor, array $ctx = []): array
{
    $eventId = (int) $event['id'];
    $deck    = se_deck_find($pdo, $eventId, se_int($in['deck_id'] ?? 0, 0));
    if (!$deck) {
        throw new SeNotFoundException('Choose the deck the new questions go into.');
    }
    $type  = (string) $deck['content_type'];
    $count = se_int($in['count'] ?? 8, 1, 15, 8);
    $topic = se_line($in['topic'] ?? '', 160) ?: 'joy, friendship and well-known Bible stories';

    // Recent prompts and answers from this event's decks, so a second batch
    // does not repeat the first. Content only, never anything personal.
    $avoid = [];
    foreach (se_deck_items($pdo, (int) $deck['id']) as $existing) {
        $avoid[] = mb_substr($existing['summary'], 0, 60, 'UTF-8');
        if (count($avoid) >= 25) {
            break;
        }
    }

    $input = [
        'content_type'   => SE_CONTENT_TYPE_LABELS[$type] ?? $type,
        'payload_shape'  => SE_DECK_AI_SHAPES[$type] ?? '{}',
        'topic'          => $topic,
        'count'          => $count,
        'difficulty_mix' => se_line($in['difficulty_mix'] ?? '', 80) ?: 'mostly easy and medium, a few hard',
        'avoid'          => $avoid ? implode(' | ', $avoid) : 'nothing yet',
    ];

    $result = se_ai($pdo, 'deck_generate', $input, ['user_id' => $actor, 'event_id' => $eventId] + $ctx);

    $items = [];
    foreach (array_slice((array) ($result['items'] ?? []), 0, $count) as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $items[] = se_deck_generated_item($pdo, $type, $raw);
    }

    $jobId = se_ai_job_create($pdo, $eventId, 'deck_generate', ['deck_id' => (int) $deck['id']] + $input, ['items' => $items], $actor, 'ready');

    return ['job_id' => $jobId, 'deck_id' => (int) $deck['id'], 'items' => $items];
}

/**
 * Validate one AI item and enrich it from the KJV (§15.4 post-processing):
 * the reference is looked up, a verse item's text is FETCHED and split
 * rather than taken from the model (D26), and MCQ choices are shuffled.
 */
function se_deck_generated_item(PDO $pdo, string $type, array $raw): array
{
    $payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
    $ref     = se_line($raw['ref'] ?? '', 60);
    $verse   = $ref !== '' ? se_bible_lookup($pdo, $ref, 'KJV') : null;
    $flags   = [];

    if ($ref !== '' && $verse === null) {
        $flags[] = 'The Bible reference could not be found — check it before keeping this one.';
    }

    if ($type === 'verse') {
        if ($verse === null) {
            $flags[] = 'A Finish-the-verse item needs a reference we can look up.';
        } else {
            $payload = se_verse_split((string) $verse['text']) + $payload;
        }
    }

    // Shuffle answer tiles so the correct one is not always first (§15.4.5).
    if (isset($payload['choices'], $payload['answer_index']) && is_array($payload['choices'])) {
        $correct = $payload['choices'][(int) $payload['answer_index']] ?? null;
        $choices = array_values($payload['choices']);
        shuffle($choices);
        $payload['choices'] = $choices;
        if ($correct !== null) {
            $payload['answer_index'] = (int) array_search($correct, $choices, true);
        }
    }

    [$clean, $errors] = se_deck_payload_clean($type, $payload);

    return [
        'payload'        => $clean,
        'summary'        => se_item_summary($type, $clean),
        'answer'         => se_item_answer_text($type, $clean),
        'scripture_ref'  => $verse['ref_display'] ?? $ref,
        'scripture_text' => $verse['text'] ?? '',
        'difficulty'     => se_enum($raw['difficulty'] ?? 'medium', ['easy', 'medium', 'hard'], 'medium'),
        'errors'         => array_values($errors),
        'flags'          => $flags,
        'ok'             => !$errors,
    ];
}

/**
 * Add the reviewed items the producer kept. `indexes` names them; the
 * selection on screen is the human review, so they arrive approved.
 */
function se_deck_generate_apply(PDO $pdo, array $event, int $jobId, int $deckId, int $actor, ?array $indexes = null): array
{
    $eventId = (int) $event['id'];
    $job = se_ai_job_find($pdo, $jobId, $eventId);
    if (!$job || (string) $job['status'] !== 'ready' || (string) $job['task'] !== 'deck_generate') {
        throw new SeRuleException('AI_JOB_INVALID', 'That AI result is no longer waiting for review.');
    }

    $input = se_json_decode($job['input_json'] ?? null);
    $deckId = $deckId > 0 ? $deckId : (int) ($input['deck_id'] ?? 0);
    $deck = se_deck_find($pdo, $eventId, $deckId);
    if (!$deck) {
        throw new SeNotFoundException('We could not find that deck.');
    }

    $items = (array) (se_json_decode($job['result_json'] ?? null)['items'] ?? []);
    $keep  = $indexes === null ? array_keys($items) : array_map('intval', $indexes);

    $created = 0;
    foreach ($keep as $index) {
        $item = $items[$index] ?? null;
        if (!is_array($item) || empty($item['ok'])) {
            continue;
        }
        se_deck_item_save($pdo, $event, [
            'deck_id'        => $deckId,
            'payload'        => $item['payload'] ?? [],
            'scripture_ref'  => $item['scripture_ref'] ?? '',
            'scripture_text' => $item['scripture_text'] ?? '',
            'difficulty'     => $item['difficulty'] ?? 'medium',
            'source'         => 'ai',
        ], $actor);
        $created++;
    }

    $pdo->prepare("UPDATE se_deck_items SET ai_job_id = ? WHERE deck_id = ? AND source = 'ai' AND ai_job_id IS NULL")
        ->execute([$jobId, $deckId]);
    se_ai_job_mark_applied($pdo, $jobId, $actor);

    return ['created' => $created, 'job_id' => $jobId, 'deck_id' => $deckId];
}
