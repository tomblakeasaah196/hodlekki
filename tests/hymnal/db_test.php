<?php
// /tests/hymnal/db_test.php
//
// The database half: who may do what, what stays private, what the Studio
// refuses to save, and that the AI never gets to decide anything.

$db = hymnal_test_db();

// 7 = a chorister in River of Life, 9 = a pastor, 11 = an ordinary member,
// 13 = a Super_Admin, 15 = a member whose role was frozen.
hymnal_test_give_music($db, 7);
hymnal_test_give_role($db, 9, 'Resident_Pastor');
hymnal_test_give_role($db, 13, 'Super_Admin');
hymnal_test_give_role($db, 11, 'Church_Member');
$db->exec('INSERT INTO user_roles (user_id, role_id, is_frozen) VALUES (15, 2, 1)');

section('who may open the Studio');
eq('River of Life is found by name', 4, hymnal_music_department_id($db));
ok('a chorister may open it', hymnal_clearance($db, 7)['allowed']);
ok('but may not publish', !hymnal_clearance($db, 7)['can_publish']);
ok('a pastor may open it', hymnal_clearance($db, 9)['allowed']);
ok('and may publish', hymnal_clearance($db, 9)['can_publish']);
ok('a Super_Admin may do both', hymnal_clearance($db, 13)['allowed'] && hymnal_clearance($db, 13)['can_publish']);
ok('an ordinary member may not', !hymnal_clearance($db, 11)['allowed']);
ok('and is told what would give it to them', str_contains(hymnal_clearance($db, 11)['why'], 'River of Life'));
ok('a frozen role grants nothing', !hymnal_clearance($db, 15)['allowed']);
ok('nobody signed in grants nothing', !hymnal_clearance($db, 0)['allowed']);

section('leaving the department takes the Studio away');
$db->exec('UPDATE user_departments SET is_active = 0 WHERE user_id = 7');
ok('a chorister who stopped serving loses it', !hymnal_clearance($db, 7)['allowed']);
$db->exec('UPDATE user_departments SET is_active = 1 WHERE user_id = 7');
ok('and gets it back on returning', hymnal_clearance($db, 7)['allowed']);

section('the endpoint guards say no in the API shape');
ok('a member is refused the Studio', hymnal_require_studio($db, 11) !== null);
ok('a chorister is not', hymnal_require_studio($db, 7) === null);
ok('a chorister is refused publishing', hymnal_require_publish($db, 7) !== null);
eq('with a code the front end can switch on', 'FORBIDDEN', hymnal_require_publish($db, 7)['code']);
ok('a pastor is not', hymnal_require_publish($db, 9) === null);

section('what a member writes is private to them');
$r = hymnal_favourite_toggle($db, 11, 31, true);
ok('a member marks a hymn', $r['ok'] && $r['on']);
eq('and sees it', [31], hymnal_favourites($db, 11));
eq('another member sees nothing of theirs', [], hymnal_favourites($db, 12));
hymnal_favourite_toggle($db, 12, 77, true);
eq('each sees only their own', [31], hymnal_favourites($db, 11));
eq('and the other only theirs', [77], hymnal_favourites($db, 12));

// The user id is on the delete as well as the read: one member must not be
// able to clear another's mark by knowing the hymn number.
hymnal_favourite_toggle($db, 12, 31, false);
eq('unmarking is scoped to the member doing it', [31], hymnal_favourites($db, 11));
$off = hymnal_favourite_toggle($db, 11, 31, false);
ok('a member can unmark their own', $off['ok'] && !$off['on']);
eq('and it is gone', [], hymnal_favourites($db, 11));

section('marking is idempotent and bounded');
hymnal_favourite_toggle($db, 11, 31, true);
hymnal_favourite_toggle($db, 11, 31, true);
eq('marking twice leaves one', [31], hymnal_favourites($db, 11));
ok('unmarking what was never marked is not an error', hymnal_favourite_toggle($db, 11, 4242, false)['ok']);

section('reading position and preferences');
ok('state saves', hymnal_state_save($db, 11, ['mode' => 'choir', 'last_number' => 31]));
$st = hymnal_state_get($db, 11);
eq('the mode is remembered', 'choir', $st['mode']);
eq('so is the hymn', 31, $st['last_number']);
ok('state saves again without duplicating the row', hymnal_state_save($db, 11, ['mode' => 'congregation']));
eq('and reads back changed', 'congregation', hymnal_state_get($db, 11)['mode']);
eq('one row per member', 1, (int) $db->query('SELECT COUNT(*) FROM hymnal_user_state WHERE user_id = 11')->fetchColumn());
eq('another member has their own default', 'congregation', hymnal_state_get($db, 99)['mode']);

hymnal_state_save($db, 11, ['mode' => 'nonsense']);
eq('a mode we cannot render is ignored', 'congregation', hymnal_state_get($db, 11)['mode']);
hymnal_state_save($db, 11, ['prefs' => ['text_size' => 99, 'tempo' => 5, 'page' => 'rainbow', 'voice' => 'Z']]);
$p = hymnal_state_get($db, 11)['prefs'];
eq('text size is clamped', 6, $p['text_size']);
eq('tempo is clamped', 50, $p['tempo']);
eq('an unknown page falls back', 'paper', $p['page']);
eq('an unknown voice falls back', 'all', $p['voice']);
hymnal_state_save($db, 11, ['prefs' => ['instrument' => 'tuba']]);
eq('an instrument we do not ship falls back', 'acoustic_grand_piano', hymnal_state_get($db, 11)['prefs']['instrument']);

section('recently opened');
hymnal_recent_touch($db, 11, 31);
hymnal_recent_touch($db, 11, 77);
hymnal_recent_touch($db, 11, 31);
eq('one row per hymn per member', 1, (int) $db->query('SELECT COUNT(*) FROM hymnal_recent WHERE user_id = 11 AND song_number = 31')->fetchColumn());
ok('both hymns are listed', count(hymnal_recent($db, 11)) === 2);
eq('and another member sees none of it', [], hymnal_recent($db, 12));

section('the Studio refuses what it should');
$bad = hymnal_song_save($db, 7, ['title' => '', 'sections' => [], 'collections' => [], 'licence_code' => '']);
ok('a song with nothing in it is refused', !$bad['ok']);
eq('with the fields named', 'INVALID', $bad['code']);
ok('the title is named', isset($bad['errors']['title']));
ok('the words are named', isset($bad['errors']['sections']));
ok('the shelf is named', isset($bad['errors']['collections']));
ok('the licence is named', isset($bad['errors']['licence_code']));

$noLicence = hymnal_song_save($db, 7, hymnal_test_song_input(['licence_code' => 'MADE_UP']));
ok('a licence we do not recognise is refused', !$noLicence['ok']);

$ccliNoNumber = hymnal_song_save($db, 7, hymnal_test_song_input(['licence_code' => 'CCLI']));
ok('a CCLI song without its number is refused', !$ccliNoNumber['ok']);
ok('and says why', isset($ccliNoNumber['errors']['ccli_number']));

$permNoDetail = hymnal_song_save($db, 7, hymnal_test_song_input(['licence_code' => 'PERMISSION']));
ok('permission without a source is refused', !$permNoDetail['ok']);

$badShelf = hymnal_song_save($db, 7, hymnal_test_song_input(['collections' => ['not-a-shelf']]));
ok('a shelf that does not exist is refused', !$badShelf['ok']);

$badYear = hymnal_song_save($db, 7, hymnal_test_song_input(['year' => 3500]));
ok('a year in the far future is refused', !$badYear['ok']);
$badBpm = hymnal_song_save($db, 7, hymnal_test_song_input(['bpm' => 1000]));
ok('an impossible tempo is refused', !$badBpm['ok']);

section('numbers are permanent and never collide');
$a = hymnal_song_save($db, 7, hymnal_test_song_input(['title' => 'First Song']));
$b = hymnal_song_save($db, 7, hymnal_test_song_input(['title' => 'Second Song']));
ok('both save', $a['ok'] && $b['ok']);
eq('the first takes the floor', HYMNAL_DB_NUMBER_FLOOR, (int) $a['number']);
eq('the next takes the one after', HYMNAL_DB_NUMBER_FLOOR + 1, (int) $b['number']);
ok('no Studio number reaches into the corpus block', (int) $a['number'] > HYMNAL_CORPUS_CEILING);

$again = hymnal_song_save($db, 7, hymnal_test_song_input(['id' => (int) $a['id'], 'title' => 'First Song Renamed']));
eq('editing a song keeps its number', (int) $a['number'], (int) $again['number']);

$same = hymnal_song_save($db, 7, hymnal_test_song_input(['title' => 'First Song']));
ok('two songs may share a title', $same['ok'], json_encode($same));
ok('and still get separate numbers', (int) $same['number'] !== (int) $a['number']);
eq('slugs stay unique', 3, (int) $db->query('SELECT COUNT(DISTINCT slug) FROM hymnal_songs')->fetchColumn());

$retired = hymnal_song_retire($db, 9, (int) $same['id'], 'duplicate');
ok('a song can be retired', $retired['ok']);
$next = hymnal_song_save($db, 7, hymnal_test_song_input(['title' => 'Later Song']));
ok('a retired number is never handed out again', (int) $next['number'] > (int) $same['number']);
eq('a retired song leaves the library', null, hymnal_library_song($db, (int) $same['number']));

section('publishing checks the licence, not just the words');
$ccli = hymnal_song_save($db, 7, hymnal_test_song_input([
    'title'        => 'A Licensed Song',
    'licence_code' => 'CCLI',
    'ccli_number'  => '7654321',
]));
ok('a CCLI song with its number saves', $ccli['ok'], json_encode($ccli));
$pubFail = hymnal_song_publish($db, 9, (int) $ccli['id']);
ok('but will not publish without a named licence holder', !$pubFail['ok']);
ok('and says which field is missing', isset($pubFail['errors']['licence_holder']));

hymnal_song_save($db, 7, hymnal_test_song_input([
    'id'             => (int) $ccli['id'],
    'title'          => 'A Licensed Song',
    'licence_code'   => 'CCLI',
    'ccli_number'    => '7654321',
    'licence_holder' => 'Household of David, main church',
]));
$pubOk = hymnal_song_publish($db, 9, (int) $ccli['id']);
ok('with the holder named it publishes', $pubOk['ok'], json_encode($pubOk));
$licensed = hymnal_library_song($db, (int) $ccli['number']);
eq('and the member is shown the licence', 'CCLI', $licensed['licence']['code']);
eq('with the holder', 'Household of David, main church', $licensed['licence']['holder']);
eq('and the song number', '7654321', $licensed['licence']['ccli']);

ok('publishing something that is gone is refused', !hymnal_song_publish($db, 9, 999999)['ok']);

section('a score, and the sol-fa generated from it');
$cMajor = ['root' => 'C', 'acc' => '', 'mode' => '', 'accidentals' => []];
$voiceData = [
    ['id' => 'S', 'name' => 'Soprano', 'clef' => 'treble', 'bars' => [[['p' => 0], ['p' => 2], ['p' => 4]]]],
    ['id' => 'B', 'name' => 'Bass', 'clef' => 'bass', 'bars' => [[['p' => -7], ['p' => -5], ['p' => -3]]]],
];
$sc = hymnal_song_score_save($db, 7, (int) $ccli['id'], "X:1\nK:C\nCEG", $voiceData, $cMajor);
ok('the score saves', $sc['ok'], json_encode($sc));
eq('both voices are kept', 2, $sc['voices']);
eq('doh is named from the key', 'C', $sc['doh']);

$withScore = hymnal_library_song($db, (int) $ccli['number']);
ok('now the song has a score', $withScore['has']['score']);
ok('and sol-fa', $withScore['has']['solfa']);
ok('and playback is offered', $withScore['has']['audio']);
eq('the sol-fa was computed on the server', ['d', 'm', 's'], $withScore['score']['voices'][0]['solfa'][0]);
eq('the bass reads in its own octave', ['d,', 'm,', 's,'], $withScore['score']['voices'][1]['solfa'][0]);

$replaced = hymnal_song_score_save($db, 7, (int) $ccli['id'], "X:1\nK:C\nCEG", [$voiceData[0]], $cMajor);
eq('saving again replaces rather than duplicates', 1, $replaced['voices']);
eq('one score row per song', 1, (int) $db->query('SELECT COUNT(*) FROM hymnal_song_scores WHERE song_id = ' . (int) $ccli['id'])->fetchColumn());

$cleared = hymnal_song_score_save($db, 7, (int) $ccli['id'], '', [], $cMajor);
ok('an empty score removes it', $cleared['ok']);
ok('and the song says the notes are not available', !hymnal_library_song($db, (int) $ccli['number'])['has']['score']);

$noNotes = hymnal_song_score_save($db, 7, (int) $ccli['id'], "X:1\nK:C", [['id' => 'S', 'bars' => []]], $cMajor);
ok('a score with no readable notes is refused', !$noNotes['ok']);

section('note data from the browser is checked, not trusted');
$junk = [[['p' => 'not a number'], ['p' => 9999], ['a' => 'DROP TABLE'], ['r' => 1], 'nonsense']];
$clean = hymnal_clean_bars($junk);
eq('one bar survives', 1, count($clean));
eq('only the usable notes are kept', 2, count($clean[0]));
eq('an absurd pitch is clamped', 60, $clean[0][0]['p']);
ok('an accidental we do not know is dropped', !isset($clean[0][0]['a']));
eq('a rest is kept as a rest', 1, $clean[0][1]['r']);
eq('a non-array bar is dropped', [], hymnal_clean_bars('nonsense'));

section('verses are tidied before they are stored');
$messy = hymnal_clean_sections([
    ['kind' => 'verse', 'lines' => ['  spaced   out  ', '', '   ']],
    ['kind' => 'nonsense', 'lines' => ['kept anyway']],
    ['kind' => 'verse', 'lines' => []],
    'not an array',
]);
eq('empty sections are dropped', 2, count($messy));
eq('whitespace is collapsed', 'spaced out', $messy[0]['lines'][0]);
eq('blank lines are dropped', 1, count($messy[0]['lines']));
eq('an unknown kind becomes a verse', 'verse', $messy[1]['kind']);
eq('verses are renumbered in order', 1, $messy[0]['num']);
eq('and labelled for the page', 'Verse 1', $messy[0]['label']);

section('the AI decides nothing');
// No GEMINI_API_KEY in the test environment, which is the behaviour to check:
// the Studio must stay usable by hand when the AI is unavailable.
ok('the AI reports itself unavailable without a key', !hymnal_ai_available());
ok('and says so in words a person can read', str_contains((string) hymnal_ai_unavailable_reason(), 'by hand'));
$aiTry = hymnal_ai_read_song($db, 7, 'Amazing grace how sweet the sound that saved a wretch like me');
ok('asking it to read anyway is refused', !$aiTry['ok']);
eq('with a code the Studio can switch on', 'AI_UNAVAILABLE', $aiTry['code']);
eq('no job row is left behind', 0, (int) $db->query('SELECT COUNT(*) FROM hymnal_ai_jobs')->fetchColumn());
eq('and nothing was logged as a call', 0, (int) $db->query('SELECT COUNT(*) FROM hymnal_ai_requests')->fetchColumn());

// The shaping of a model's answer is pure, so it is checked without a key.
$shaped = hymnal_ai_shape_song([
    'title'    => '  Great Is Thy Faithfulness  ',
    'writer'   => null,
    'sections' => [
        ['label' => 'Verse 1', 'kind' => 'VERSE', 'lines' => ['Great is thy faithfulness', '']],
        ['label' => 'x', 'kind' => 'chorus', 'lines' => []],
        'rubbish',
    ],
    'invented_field' => 'should be dropped',
    'notes'    => 'The last line was cut off.',
]);
eq('the title is trimmed', 'Great Is Thy Faithfulness', $shaped['title']);
eq('a null writer becomes empty, not the word null', '', $shaped['writer']);
eq('an empty section is dropped', 1, count($shaped['sections']));
eq('the kind is normalised', 'verse', $shaped['sections'][0]['kind']);
ok('a field we never asked for is dropped', !isset($shaped['invented_field']));
eq('what the model was unsure about is kept for the reviewer', 'The last line was cut off.', $shaped['notes']);

section('the Studio leaves a trail');
$rows = $db->query("SELECT action, COUNT(*) AS n FROM hymnal_audit GROUP BY action")->fetchAll();
$actions = [];
foreach ($rows as $r) {
    $actions[$r['action']] = (int) $r['n'];
}
ok('saving a song is recorded', ($actions['song_save'] ?? 0) > 0);
ok('publishing is recorded', ($actions['song_publish'] ?? 0) > 0);
ok('retiring is recorded', ($actions['song_retire'] ?? 0) > 0);
ok('saving a score is recorded', ($actions['score_save'] ?? 0) > 0);
$who = (int) $db->query("SELECT user_id FROM hymnal_audit WHERE action = 'song_publish' LIMIT 1")->fetchColumn();
eq('and says who did it', 9, $who);

section('module switches');
eq('an unset switch falls back', 'yes', hymnal_config_get($db, 'never_set', 'yes'));
ok('a switch saves', hymnal_config_set($db, 'ai_enabled', '0', 9));
eq('one row per switch', 1, (int) $db->query("SELECT COUNT(*) FROM hymnal_config WHERE config_key = 'ai_enabled'")->fetchColumn());
ok('setting it twice does not duplicate', hymnal_config_set($db, 'ai_enabled', '1', 9));
eq('still one row', 1, (int) $db->query("SELECT COUNT(*) FROM hymnal_config WHERE config_key = 'ai_enabled'")->fetchColumn());

section('the boot payload the page is rendered with');
$boot = hymnal_boot_payload($db, 7);
ok('it knows the migration has run', $boot['ready']);
ok('it counts the whole library', $boot['counts']['songs'] >= 500);
ok('it offers the shelves', count($boot['collections']) === count(HYMNAL_COLLECTIONS));
ok('it tells a chorister they have the Studio', $boot['studio']['allowed']);
ok('but not publishing', !$boot['studio']['can_publish']);
ok('it carries the sol-fa key for the page to print', $boot['solfa_key']['degrees'] === HYMNAL_SOLFA_DEGREES);
$memberBoot = hymnal_boot_payload($db, 11);
ok('an ordinary member is not offered the Studio', !$memberBoot['studio']['allowed']);
