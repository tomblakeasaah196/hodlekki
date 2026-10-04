<?php
// /tests/special_events/scoring_test.php
require_once __DIR__ . '/../../includes/special_events/games_engine.php';
require_once __DIR__ . '/../../includes/special_events/scoring.php';
is_same('instant quiz answer earns base', 1000, se_quiz_points(1000, 0, 20000));
is_same('halfway answer earns three quarters', 750, se_quiz_points(1000, 10000, 20000));
is_same('late answer never goes negative', 0, se_quiz_points(1000, 30000, 20000));
is_same('team normalisation', 500, se_team_normalized_points(1000, 1, 2));
is_same('buzz time is clamped to server receipt', 1200, se_buzz_effective(2000, 1000, 1200));
is_same('idempotency key format', 'q:4:p:9', se_score_key('q', 4, 9));
