<?php
// /tests/special_events/party_games_test.php

is_same('Who Am I starts at 500', 500, se_who_am_i_points([500, 400, 300, 200, 100], 0));
is_same('Who Am I falls with each clue', 200, se_who_am_i_points([500, 400, 300, 200, 100], 3));
is_same('Who Am I clamps beyond the final clue', 100, se_who_am_i_points([500, 400, 300, 200, 100], 99));
is_same('Feud bank sums revealed answers and doubles', 120, se_feud_bank_points([30, 20, 10], 2));
is_same('Feud multiplier cannot be below one', 60, se_feud_bank_points([30, 20, 10], 0));
is_same('Survey normalises articles, punctuation and a simple plural', 'lion', se_survey_normalize('  The Lions! '));

$engine = (string) file_get_contents(__DIR__ . '/../../includes/special_events/games_engine.php');
$live = (string) file_get_contents(__DIR__ . '/../../includes/special_events/live.php');
ok('public round explicitly removes charades phrase', str_contains($engine, "unset(\$view['answer'], \$view['accept'], \$view['phrase']"));
ok('public snapshot source never reads charades phrase', !str_contains(substr($live, strpos($live, 'function se_snapshot_public'), strpos($live, 'function se_snapshot_room') - strpos($live, 'function se_snapshot_public')), "['phrase']"));
