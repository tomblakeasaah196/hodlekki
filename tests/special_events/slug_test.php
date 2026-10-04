<?php
// /tests/special_events/slug_test.php — slug format, reserved words, case
// folding and suggestions (guide §10.2, §22.1).

// se_slug_validate() lives in events.php, which is otherwise database-bound.
// Only the two pure functions are needed here.
require_once __DIR__ . '/../../includes/special_events/events.php';

echo "    format\n";
ok('a plain word is valid', se_slug_validate('chara')['ok']);
ok('digits are allowed', se_slug_validate('chara2026')['ok']);
ok('inner hyphens are allowed', se_slug_validate('chara-night-2026')['ok']);
ok('a single character is valid', se_slug_validate('a')['ok']);
is_same('40 characters is the limit', true, se_slug_validate(str_repeat('a', 40))['ok']);
is_same('41 characters is too long', false, se_slug_validate(str_repeat('a', 41))['ok']);

ok('an empty slug is rejected', !se_slug_validate('')['ok']);
ok('whitespace only is rejected', !se_slug_validate('   ')['ok']);
ok('a leading hyphen is rejected', !se_slug_validate('-chara')['ok']);
ok('a trailing hyphen is rejected', !se_slug_validate('chara-')['ok']);
ok('an underscore is rejected', !se_slug_validate('chara_night')['ok']);
ok('a slash is rejected', !se_slug_validate('chara/night')['ok']);
ok('a dot is rejected', !se_slug_validate('chara.night')['ok']);
ok('punctuation is rejected', !se_slug_validate('chara!')['ok']);
ok('a non-ASCII letter is rejected', !se_slug_validate('charà')['ok']);

echo "    case folding and spaces\n";
is_same('uppercase folds to lowercase', 'chara', se_slug_validate('CHARA')['slug']);
is_same('mixed case folds', 'charanight', se_slug_validate('CharaNight')['slug']);
is_same('spaces become hyphens', 'chara-night', se_slug_validate('chara night')['slug']);
ok('a space-separated name is valid once folded', se_slug_validate('Chara Night')['ok']);
is_same('surrounding space is trimmed', 'chara', se_slug_validate('  chara  ')['slug']);

echo "    reserved words\n";
foreach (['admin', 'api', 'me', 'in', 'play', 'stage', 'host', 'privacy', 'new', 'e', 'live'] as $reserved) {
    ok("'{$reserved}' is reserved", !se_slug_validate($reserved)['ok']);
}
is_same('a reserved word gets a usable suggestion', 'admin-live', se_slug_validate('admin')['suggestion']);
ok('a reserved word reads as reserved, not malformed',
    str_contains(strtolower((string) se_slug_validate('api')['reason']), 'reserved'));

echo "    suggestions\n";
is_same('a title becomes a slug', 'chara-2026', se_slug_suggest('Chara 2026'));
is_same('punctuation is dropped', 'chara-night', se_slug_suggest('Chara: Night!'));
is_same('runs of separators collapse', 'a-b', se_slug_suggest('a   ---   b'));
is_same('accents transliterate', 'cafe-night', se_slug_suggest('Café Night'));
is_same('Yoruba dotted vowels transliterate', 'ayo-ni', se_slug_suggest('Ayọ Ni'));
is_same('a reserved suggestion is deflected', 'stage-live', se_slug_suggest('stage'));
ok('a suggestion is always valid', se_slug_validate(se_slug_suggest('!!! ???'))['ok']);
ok('an empty title still yields a valid slug', se_slug_validate(se_slug_suggest(''))['ok']);
ok('a very long title is truncated to a valid slug',
    se_slug_validate(se_slug_suggest(str_repeat('chara ', 40)))['ok']);
ok('a title ending mid-hyphen does not leave a trailing hyphen',
    !str_ends_with(se_slug_suggest(str_repeat('ab ', 20)), '-'));

echo "    the bad-character path offers a fix\n";
$v = se_slug_validate('Chara Night!');
ok('rejected', !$v['ok']);
is_same('with a suggestion', 'chara-night', $v['suggestion']);
ok('and a reason a person can act on', str_contains((string) $v['reason'], 'lowercase'));
