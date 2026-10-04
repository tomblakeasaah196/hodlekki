<?php
// /tests/special_events/settings_test.php — se_settings_normalize() against
// the Appendix E contract (guide §20.3, §22.1).

$defaults = se_settings_defaults();

echo "    the defaults match Appendix E\n";
is_same('consent mode', 'required_followup', $defaults['registration']['consent_mode']);
is_same('gender is required', 'required', $defaults['registration']['fields']['gender']);
is_same('email is optional', 'optional', $defaults['registration']['fields']['email']);
is_same('karaoke interest is asked', true, $defaults['registration']['fields']['karaoke_interest']);
is_same('check-in opens an hour before', 60, $defaults['checkin']['opens_minutes_before']);
is_same('four teams', 4, $defaults['teams']['count']);
is_same('teams balance by size, gender, membership', ['size', 'gender', 'membership'], $defaults['teams']['balance']);
is_same('unique karaoke songs', true, $defaults['karaoke']['unique_songs']);
is_same('the song list starts unpublished', false, $defaults['karaoke']['list_published']);
is_same('no singer cap by default', null, $defaults['karaoke']['max_singers']);
is_same('quiz runs for 20 seconds', 20000, $defaults['games']['defaults']['live_quiz']['duration_ms']);
is_same('who-am-i clue points', [500, 400, 300, 200, 100], $defaults['games']['defaults']['who_am_i']['points_by_clue']);
is_same('feud multipliers', [1, 1, 2, 3], $defaults['games']['defaults']['feud']['round_multipliers']);
is_same('stage volume', 0.8, $defaults['stage']['volume']);
is_same('poll driver', 'poll', $defaults['realtime']['driver']);
is_same('names show as a first initial', 'first_initial', $defaults['display']['name_format']);
is_same('test mode is off', false, $defaults['test_mode']);
is_same('six FAQ entries ship by default', 6, count($defaults['portal']['faq']));
is_same('capacity has not been reviewed on a new event', false, $defaults['registration']['capacity_reviewed']);

echo "    the whole Appendix E document round-trips\n";
is_same('normalising the defaults changes nothing', $defaults, se_settings_normalize($defaults));
is_same('a JSON round trip changes nothing', $defaults, se_settings_normalize(se_json_encode($defaults)));

echo "    unknown keys are dropped\n";
$out = se_settings_normalize(['registration' => ['consent_mode' => 'required_followup', 'nonsense' => 'x'], 'bogus' => 1]);
ok('a top-level unknown key is gone', !array_key_exists('bogus', $out));
ok('a nested unknown key is gone', !array_key_exists('nonsense', $out['registration']));
ok('the known sibling survives', $out['registration']['consent_mode'] === 'required_followup');

echo "    types are coerced\n";
is_same('a numeric string becomes an int', 90, se_settings_normalize(['checkin' => ['opens_minutes_before' => '90']])['checkin']['opens_minutes_before']);
is_same('"true" becomes a bool', true, se_settings_normalize(['test_mode' => 'true'])['test_mode']);
is_same('1 becomes a bool', true, se_settings_normalize(['test_mode' => 1])['test_mode']);
is_same('"off" becomes false', false, se_settings_normalize(['test_mode' => 'off'])['test_mode']);
is_same('a float is clamped and kept', 1.0, se_settings_normalize(['stage' => ['volume' => 5]])['stage']['volume']);
is_same('a negative float clamps to the floor', 0.0, se_settings_normalize(['stage' => ['volume' => -3]])['stage']['volume']);

echo "    bounds\n";
is_same('team count clamps up to the minimum', SE_MIN_TEAMS, se_settings_normalize(['teams' => ['count' => 1]])['teams']['count']);
is_same('team count clamps down to the maximum', SE_MAX_TEAMS, se_settings_normalize(['teams' => ['count' => 99]])['teams']['count']);
is_same('opens_minutes_before clamps at a day', 1440, se_settings_normalize(['checkin' => ['opens_minutes_before' => 99999]])['checkin']['opens_minutes_before']);

echo "    enums fall back to the default\n";
is_same('a bad consent mode', 'required_followup', se_settings_normalize(['registration' => ['consent_mode' => 'whatever']])['registration']['consent_mode']);
is_same('a bad field mode', 'required', se_settings_normalize(['registration' => ['fields' => ['gender' => 'maybe']]])['registration']['fields']['gender']);
is_same('a bad realtime driver', 'poll', se_settings_normalize(['realtime' => ['driver' => 'websocket']])['realtime']['driver']);
is_same('a valid alternative is kept', 'ably', se_settings_normalize(['realtime' => ['driver' => 'ably']])['realtime']['driver']);

echo "    nullable values\n";
is_same('an empty string becomes null', null, se_settings_normalize(['karaoke' => ['max_singers' => '']])['karaoke']['max_singers']);
is_same('an explicit null stays null', null, se_settings_normalize(['karaoke' => ['max_singers' => null]])['karaoke']['max_singers']);
is_same('a number is kept', 40, se_settings_normalize(['karaoke' => ['max_singers' => 40]])['karaoke']['max_singers']);

echo "    lists\n";
is_same('an empty balance list falls back to the default', ['size', 'gender', 'membership'],
    se_settings_normalize(['teams' => ['balance' => []]])['teams']['balance']);
is_same('unknown balance keys are dropped', ['gender'],
    se_settings_normalize(['teams' => ['balance' => ['gender', 'astrology']]])['teams']['balance']);
is_same('duplicates are dropped', ['size'],
    se_settings_normalize(['teams' => ['balance' => ['size', 'size']]])['teams']['balance']);
is_same('an int list is coerced', [1, 2, 3],
    se_settings_normalize(['games' => ['defaults' => ['feud' => ['round_multipliers' => ['1', 2, 3.0]]]]])['games']['defaults']['feud']['round_multipliers']);

echo "    the FAQ list\n";
$faq = se_settings_normalize(['portal' => ['faq' => [
    ['q' => 'Is it free?', 'a' => 'Yes.'],
    ['q' => '', 'a' => ''],
    'not an object',
]]])['portal']['faq'];
is_same('empty entries are dropped and non-objects ignored', 1, count($faq));
is_same('the real entry survives', 'Is it free?', $faq[0]['q']);

echo "    text limits\n";
$long = se_settings_normalize(['registration' => ['consent_text' => str_repeat('x', 5000)]])['registration']['consent_text'];
ok('consent text is capped at 600 characters', mb_strlen($long, 'UTF-8') <= 600, (string) mb_strlen($long, 'UTF-8'));

echo "    time of day\n";
is_same('a valid time is kept', '18:00', se_settings_normalize(['messages' => ['reminder_1' => ['at' => '18:00']]])['messages']['reminder_1']['at']);
is_same('a single-digit hour is padded', '09:05', se_settings_normalize(['messages' => ['reminder_1' => ['at' => '9:05']]])['messages']['reminder_1']['at']);
is_same('an impossible hour falls back', '18:00', se_settings_normalize(['messages' => ['reminder_1' => ['at' => '99:00']]])['messages']['reminder_1']['at']);
is_same('nonsense falls back', '18:00', se_settings_normalize(['messages' => ['reminder_1' => ['at' => 'soon']]])['messages']['reminder_1']['at']);

echo "    hostile input is survivable\n";
ok('a deeply nested array does not break it', is_array(se_settings_normalize(['teams' => ['count' => [[[1]]]]])));
ok('a string where an object belongs does not break it', is_array(se_settings_normalize(['teams' => 'nope'])));
ok('null input gives the defaults', se_settings_normalize(null) === $defaults);
ok('invalid JSON gives the defaults', se_settings_normalize('{not json') === $defaults);
ok('a JSON scalar gives the defaults', se_settings_normalize('42') === $defaults);

echo "    dotted lookup\n";
is_same('reads a nested value', 60, se_settings_path($defaults, 'checkin.opens_minutes_before'));
is_same('a missing path returns the fallback', 'x', se_settings_path($defaults, 'nope.nope', 'x'));
is_same('a path through a scalar returns the fallback', 'x', se_settings_path($defaults, 'test_mode.deeper', 'x'));
