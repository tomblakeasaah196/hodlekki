<?php
// /tests/special_events/party_games_test.php
//
// Pure-logic checks for the games layer (guide §11, Appendix C): item
// validation, playability, scoring formulas, settings clamps and the privacy
// of the finale's public payload. The database-backed game night is covered
// by tests/special_events/integration/run.php.

echo "    formulas\n";

is_same('Who Am I starts at 500', 500, se_who_am_i_points([500, 400, 300, 200, 100], 0));
is_same('Who Am I falls with each clue', 200, se_who_am_i_points([500, 400, 300, 200, 100], 3));
is_same('Who Am I clamps beyond the final clue', 100, se_who_am_i_points([500, 400, 300, 200, 100], 99));
is_same('Who Am I falls back to the defaults', 500, se_who_am_i_points([], 0));
is_same('Feud bank sums revealed answers and doubles', 120, se_feud_bank_points([30, 20, 10], 2));
is_same('Feud multiplier cannot be below one', 60, se_feud_bank_points([30, 20, 10], 0));
is_same('quiz: an instant answer earns the full base', 1000, se_quiz_points(1000, 0, 20000));
is_same('quiz: the last moment still earns half', 500, se_quiz_points(1000, 20000, 20000));
is_same('quiz: halfway earns three quarters', 750, se_quiz_points(1000, 10000, 20000));
is_same('quiz: points_mode none awards nothing', 0, se_quiz_points(0, 0, 20000));
is_same('team share is normalised by eligible members', 750, se_team_normalized_points(1000, 3, 4));
is_same('a team with nobody eligible cannot divide by zero', 0, se_team_normalized_points(1000, 0, 0));
is_same('game weight multiplies team points', 450, se_weighted_points(300, '1.50'));
is_same('a missing weight counts as 1', 300, se_weighted_points(300, null));
is_same('quiz base follows points_mode', 2000, se_quiz_base(['points_mode' => 'double']));
is_same('a rehearsal row is marked TEST', 'TEST', se_score_test_reason());
is_same('a rehearsal award keeps its name after TEST: ', 'TEST: Best spirit', se_score_test_reason('Best spirit'));
is_same('idempotency key for a quiz player', 'q:12:p:34', se_score_key('q', 12, 34));
is_same('idempotency key for a wrong buzz attempt', 'p:12:t:3:a:2', se_score_key('p', 12, 3, 2));

echo "    survey answers\n";

is_same('Survey normalises articles, punctuation and a simple plural', 'lion', se_survey_normalize('  The Lions! '));
is_same('…but not a double s', 'glass', se_survey_normalize('Glass'));

echo "    item payloads (Appendix C)\n";

[$p, $e] = se_deck_payload_clean('mcq', ['prompt' => 'Who was swallowed by a great fish?', 'choices' => ['Jonah', 'Elijah', 'Peter', 'Noah'], 'answer_index' => 0]);
is_same('a good MCQ has no errors', [], $e);
is_same('…and keeps its four choices', 4, count($p['choices']));

[$p, $e] = se_deck_payload_clean('mcq', ['prompt' => 'Q?', 'choices' => ['A', '', 'C'], 'answer_index' => 2]);
is_same('a blank choice is dropped…', ['A', 'C'], $p['choices'] ?? null);
is_same('…and the answer index follows the right answer', 1, $p['answer_index'] ?? null);

[$p, $e] = se_deck_payload_clean('mcq', ['prompt' => 'Q?', 'choices' => ['A', 'B'], 'answer_index' => 5]);
ok('an answer index that points nowhere is an error', isset($e['answer_index']));

[$p, $e] = se_deck_payload_clean('mcq', ['prompt' => 'Q?', 'choices' => ['Same', 'same'], 'answer_index' => 0]);
ok('two identical choices are an error', isset($e['choices']));

[$p, $e] = se_deck_payload_clean('mcq', ['prompt' => '', 'choices' => ['A'], 'answer_index' => 0]);
ok('an MCQ needs a question', isset($e['prompt']));
ok('…and at least two choices', isset($e['choices']));

[$p, $e] = se_deck_payload_clean('open', ['prompt' => 'Who denied Jesus three times?', 'answer' => 'Peter', 'accept' => "Simon Peter, simon\nPETER"]);
is_same('an open question is valid', [], $e);
is_same('accept alternatives are lower-cased and de-duplicated', ['simon peter', 'simon', 'peter'], $p['accept']);

[$p, $e] = se_deck_payload_clean('clues', ['clues' => ['One', 'Two'], 'answer' => 'Joseph']);
ok('Who Am I? needs three clues', isset($e['clues']));

[$p, $e] = se_deck_payload_clean('charade', ['phrase' => 'David and Goliath', 'category' => 'not-a-category']);
is_same('an unknown charades category falls back to story', 'story', $p['category']);

[$p, $e] = se_deck_payload_clean('survey', ['question' => '']);
ok('a survey item needs its question', isset($e['question']));

[$p, $e] = se_deck_payload_clean('nonsense', []);
ok('an unknown content type is refused', isset($e['content_type']));

echo "    playability\n";

$mcq = ['prompt' => 'Q', 'choices' => ['A', 'B'], 'answer_index' => 0];
ok('an MCQ plays in the Live Quiz', se_game_item_playable('live_quiz', 'mcq', $mcq));
ok('…in Trivia', se_game_item_playable('trivia', 'mcq', $mcq));
ok('…and in the buzzer round (read aloud)', se_game_item_playable('buzzer', 'mcq', $mcq));
ok('an emoji puzzle without choices cannot be a quiz tile question', !se_game_item_playable('live_quiz', 'emoji', ['emojis' => '🌊', 'answer' => 'x']));
ok('…but it is fine in the buzzer round', se_game_item_playable('buzzer', 'emoji', ['emojis' => '🌊', 'answer' => 'x']));
ok('a charades phrase never plays in the quiz', !se_game_item_playable('live_quiz', 'charade', ['phrase' => 'x']));
ok('Feud plays survey questions', se_game_item_playable('feud', 'survey', ['question' => 'x']));

echo "    verses and answers\n";

$split = se_verse_split('For God so loved the world, that he gave his only begotten Son');
ok('a verse splits into a lead and an ending', $split['lead'] !== '' && $split['answer'] !== '');
is_same('…which together are the whole verse',
    'For God so loved the world, that he gave his only begotten Son', $split['lead'] . ' ' . $split['answer']);
is_same('a very short text is all lead', ['lead' => 'Jesus wept.', 'answer' => ''], se_verse_split('Jesus wept.'));
is_same('the answer text of an MCQ is the correct choice', 'Jonah', se_item_answer_text('mcq', ['choices' => ['Jonah', 'Noah'], 'answer_index' => 0]));
is_same('…of a charade is the phrase', 'David and Goliath', se_item_answer_text('charade', ['phrase' => 'David and Goliath']));

echo "    game settings (§11.14)\n";

$s = se_game_settings(['type' => 'live_quiz', 'settings_json' => '{"duration_ms": 999999, "preroll_ms": 10, "unknown": 1, "auto_lock": "0"}']);
is_same('a quiz cannot run for more than two minutes', 120000, $s['duration_ms']);
is_same('…nor give less than a 2.5 s preroll', 2500, $s['preroll_ms']);
ok('…unknown keys are dropped', !array_key_exists('unknown', $s));
is_same('…and booleans are read as booleans', false, $s['auto_lock']);
is_same('defaults fill the gaps', 1000, $s['team_base']);
is_same('Who Am I? keeps its clue ladder', [500, 400, 300, 200, 100], se_game_settings(['type' => 'who_am_i'])['points_by_clue']);

echo "    finale privacy\n";

$finale = [
    'teams'      => [['id' => 1, 'name' => 'Gold', 'hex' => '#F5C518', 'points' => 900, 'rank' => 1]],
    'champion'   => ['id' => 1, 'name' => 'Gold', 'hex' => '#F5C518', 'points' => 900, 'rank' => 1],
    'mvp'        => [['registration_id' => 7, 'display_name' => 'Ada O.', 'team_id' => 1, 'points' => 4200]],
    'mvp_winner' => ['registration_id' => 7, 'display_name' => 'Ada O.', 'team_id' => 1, 'points' => 4200],
    'awards'     => [],
];
$public = se_json_encode(se_finale_public($finale));
ok('the public finale names the champion team', str_contains($public, 'Gold'));
ok('…but never a person', !str_contains($public, 'Ada') && !str_contains($public, 'registration_id'));

echo "    buzz order (§11.7)\n";

$order = se_buzz_order([
    ['id' => 1, 'effective_ms' => 1500, 'received_at' => '2026-10-24 19:00:01.500', 'team_order' => 2],
    ['id' => 2, 'effective_ms' => 1200, 'received_at' => '2026-10-24 19:00:01.900', 'team_order' => 1],
    ['id' => 3, 'effective_ms' => 1200, 'received_at' => '2026-10-24 19:00:01.300', 'team_order' => 3],
]);
is_same('earliest effective time wins, then earliest arrival', [3, 2, 1], array_column($order, 'id'));
is_same('a buzz claimed before the window opens counts from the opening', 5000, se_buzz_effective(4000, 5000, 5600));
is_same('a phone ahead of the server is believed only to receipt', 5600, se_buzz_effective(9000, 5000, 5600));
