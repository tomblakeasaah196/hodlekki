<?php
// /tests/special_events/starter_content_test.php — the ready-made content.
//
// The ready-made games and welcome verses are added with one press in the
// Studio, approved, and can be on a projector the same night. So every item
// must pass the same checks a typed question does, every verse must be the
// KJV word for word, and each game must be big enough to play (Appendix G).

echo "    the bundled KJV verses\n";
$bundle = se_kjv_bundle();
ok('the bundle has every verse the content uses', count($bundle) >= 26, count($bundle) . ' verses');
$badKeys = [];
foreach ($bundle as $key => $text) {
    $parsed = se_bible_ref_normalize($key);
    if ($parsed === null || $parsed['ref_norm'] !== $key || trim($text) !== $text || str_contains($text, '  ') || $text === '') {
        $badKeys[] = $key;
    }
}
is_same('every key is a normalised reference with clean text', [], $badKeys);
is_same('John 3:16 is the KJV', 'For God so loved the world, that he gave his only begotten Son, that whosoever believeth in him should not perish, but have everlasting life.', $bundle['john 3:16'] ?? null);

echo "    the ready-made games\n";
$sets    = se_chara_starter_sets();
$invalid = [];
$verseMismatch = [];
foreach ($sets as $key => $set) {
    foreach ($set['items'] as $n => $entry) {
        [$clean, $errors] = se_deck_payload_clean($set['type'], $entry[0]);
        if ($errors || $clean != $entry[0]) {
            $invalid[] = $key . ' #' . ($n + 1) . ' ' . json_encode($errors ?: 'changed by cleaning');
        }
        if ($entry[1] !== null && se_bible_ref_normalize($entry[1]) === null) {
            $invalid[] = $key . ' #' . ($n + 1) . ' bad reference ' . $entry[1];
        }
        if ($set['type'] === 'verse') {
            $text = $bundle[se_bible_ref_normalize($entry[1])['ref_norm']] ?? '';
            $answerTile = isset($clean['choices']) ? $clean['choices'][$clean['answer_index']] : $clean['answer'];
            if ($clean['lead'] . ' ' . $clean['answer'] !== $text || $answerTile !== $clean['answer']) {
                $verseMismatch[] = $entry[1];
            }
        }
    }
}
is_same('every ready-made item passes the checks a typed one does', [], $invalid);
is_same('every Finish-the-verse item is the KJV verse, split in two', [], $verseMismatch);

$sizes = [];
$unplayable = [];
foreach (se_chara_starter_games() as $key => $game) {
    $count = 0;
    foreach ($game['decks'] as $deck) {
        foreach ($sets[$deck]['items'] as $entry) {
            [$clean] = se_deck_payload_clean($sets[$deck]['type'], $entry[0]);
            if (!se_game_item_playable($game['type'], $sets[$deck]['type'], $clean)) {
                $unplayable[] = $key . ': ' . $deck;
            }
            $count++;
        }
    }
    $sizes[$key] = $count;
}
is_same('every item plays in its game', [], $unplayable);
is_same('each game is the size of the suggested lineup',
    ['live_quiz' => 10, 'trivia' => 8, 'buzzer' => 10, 'who_am_i' => 5, 'charades' => 34, 'feud' => 5], $sizes);

$boards = array_filter($sets['survey']['items'], static fn(array $entry): bool => !empty($entry[2]));
is_same('every Feud question comes with a board', count($sets['survey']['items']), count($boards));

$titles = array_map(static fn(array $set): string => $set['title'], $sets);
is_same('no two question banks share a title', count($titles), count(array_unique($titles)));

try {
    se_chara_verse_payload('John 3:16', 'For God so loved the church');
    ok('a verse that does not start as written is refused', false, 'no exception');
} catch (LogicException $e) {
    ok('a verse that does not start as written is refused', true);
}

echo "    the ready-made welcome verses\n";
$verseSet = se_chara_verse_set();
$missing  = [];
$prayers  = [];
$refs     = [];
foreach ($verseSet as [$ref, $prayer]) {
    $parsed = se_bible_ref_normalize($ref);
    if ($parsed === null || !isset($bundle[$parsed['ref_norm']])) {
        $missing[] = $ref;
    } else {
        $refs[] = $parsed['ref_norm'];
    }
    if (!str_contains($prayer, '{name}') || mb_strlen($prayer) > 300) {
        $prayers[] = $ref;
    }
}
is_same('the joy set is the 22 verses of Appendix G', 22, count($verseSet));
is_same('every one of them is bundled, so adding never waits on the Bible service', [], $missing);
is_same('every prayer line greets the guest by {name}', [], $prayers);
is_same('no verse is listed twice', count($refs), count(array_unique($refs)));
