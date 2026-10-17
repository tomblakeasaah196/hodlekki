<?php
// /tests/hymnal/library_test.php
//
// The one view over both halves of the library: that a Studio song and a
// generated hymn are indistinguishable to the reader, and that search finds
// what someone would actually type.

$libDb = hymnal_test_db();
hymnal_test_give_music($libDb, 7);

section('the library reads before the migration has run');
$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ok('no tables means not ready', !hymnal_ready($bare));
ok('but the hymns are still there', count(hymnal_library_index($bare)) >= 500);
$openNoDb = hymnal_library_open($bare, 1, 31);
ok('and a hymn still opens', $openNoDb['ok']);
eq('favourites are simply empty', [], hymnal_favourites($bare, 1));
eq('recent is simply empty', [], hymnal_recent($bare, 1));
eq('state falls back to the defaults', 'congregation', hymnal_state_get($bare, 1)['mode']);
ok('saving state fails quietly rather than throwing', hymnal_state_save($bare, 1, ['mode' => 'choir']) === false);

section('a number that does not exist');
$missing = hymnal_library_open($libDb, 1, 999999);
ok('is refused', !$missing['ok']);
eq('with a reason the reader can show', 'NOT_FOUND', $missing['code']);
ok('a number of zero is refused', hymnal_library_song($libDb, 0) === null);
ok('a negative number is refused', hymnal_library_song($libDb, -5) === null);

section('searching for what someone would type');
$byNumber = hymnal_library_search($libDb, '31');
ok('a bare number finds that hymn first', ($byNumber['rows'][0]['n'] ?? 0) === 31);

$byTitle = hymnal_library_search($libDb, 'amazing grace');
ok('a title finds it', count($byTitle['rows']) >= 1);
eq('and puts it first', 'Amazing Grace', $byTitle['rows'][0]['title']);

$byFirstLine = hymnal_library_search($libDb, 'amazing grace! how sweet');
ok('a first line finds it too', ($byFirstLine['rows'][0]['title'] ?? '') === 'Amazing Grace');

$caseless = hymnal_library_search($libDb, 'AMAZING GRACE');
eq('case does not matter', $byTitle['total'], $caseless['total']);

$nonsense = hymnal_library_search($libDb, 'qxzjvkw');
eq('nonsense finds nothing', 0, $nonsense['total']);

$blank = hymnal_library_search($libDb, '');
eq('an empty search lists the whole library', hymnal_library_count($libDb), $blank['total']);

section('browsing');
$sorted = hymnal_library_search($libDb, '', ['sort' => 'number']);
$ns = array_map(static fn(array $r): int => (int) $r['n'], $sorted['rows']);
$sortedCopy = $ns;
sort($sortedCopy);
eq('by number is in number order', $sortedCopy, $ns);

$byTitleSort = hymnal_library_search($libDb, '', ['sort' => 'title']);
$titles = array_map(static fn(array $r): string => (string) $r['title'], $byTitleSort['rows']);
$titlesCopy = $titles;
usort($titlesCopy, 'strcasecmp');
eq('by title is in title order', $titlesCopy, $titles);

$communion = hymnal_library_search($libDb, '', ['collection' => 'communion']);
ok('a shelf narrows the list', $communion['total'] > 0 && $communion['total'] < $blank['total']);
$offShelf = array_filter(
    $communion['rows'],
    static fn(array $r): bool => !in_array('communion', $r['collections'], true)
);
eq('and holds only what is on it', [], $offShelf);

$scored = hymnal_library_search($libDb, '', ['score_only' => true]);
ok('choir mode can list only what it can show', $scored['total'] >= 300 && $scored['total'] < $blank['total']);
$unscored = array_filter($scored['rows'], static fn(array $r): bool => empty($r['score']));
eq('every one of them has a score', [], $unscored);

section('paging');
$page1 = hymnal_library_search($libDb, '', ['limit' => 10, 'offset' => 0]);
$page2 = hymnal_library_search($libDb, '', ['limit' => 10, 'offset' => 10]);
eq('a page is the size asked for', 10, count($page1['rows']));
eq('the total is the whole library, not the page', $blank['total'], $page1['total']);
ok('pages do not overlap', $page1['rows'][0]['n'] !== $page2['rows'][0]['n']);

section('the shelves');
$shelves = hymnal_library_collections($libDb);
eq('every shelf in the registry is offered', count(HYMNAL_COLLECTIONS), count($shelves));
$hymns = null;
foreach ($shelves as $s) {
    if ($s['slug'] === 'hymns') {
        $hymns = $s;
    }
}
ok('the hymn shelf holds the whole corpus', ($hymns['count'] ?? 0) >= 500);
$worship = null;
foreach ($shelves as $s) {
    if ($s['slug'] === 'worship') {
        $worship = $s;
    }
}
eq('the worship shelf starts empty, waiting for the music team', 0, $worship['count']);

section('a Studio song joins the same library');
$save = hymnal_song_save($libDb, 7, hymnal_test_song_input());
ok('the song saves', $save['ok'], json_encode($save));
$number = (int) ($save['number'] ?? 0);
ok('it is numbered above the corpus block', $number >= HYMNAL_DB_NUMBER_FLOOR, 'got ' . $number);

$beforePublish = hymnal_library_search($libDb, 'Baruch');
eq('a draft is invisible to the congregation', 0, $beforePublish['total']);
ok('and will not open', hymnal_library_song($libDb, $number) === null);
ok('but the Studio can see it', hymnal_library_song($libDb, $number, true) !== null);

hymnal_test_give_role($libDb, 9, 'Resident_Pastor');
$pub = hymnal_song_publish($libDb, 9, (int) $save['id']);
ok('a pastor publishes it', $pub['ok'], json_encode($pub));

$afterPublish = hymnal_library_search($libDb, 'Baruch');
eq('now the congregation finds it', 1, $afterPublish['total']);
$song = hymnal_library_song($libDb, $number);
ok('and it opens', $song !== null);
eq('it is the same shape as a hymn', 'studio', $song['origin']);
ok('with the same keys as a corpus hymn', array_diff(
    ['n', 'origin', 'title', 'first_line', 'langs', 'score', 'no_score', 'has', 'licence', 'collections'],
    array_keys($song)
) === []);
eq('its first line comes from its first verse', 'Baruch Hashem Adonai', $song['first_line']);
ok('it has no score yet, and says so', !$song['has']['score'] && $song['no_score'] !== '');
eq('playback is not offered without a score', false, $song['has']['audio']);

section('the shelves and the counts move with it');
$shelvesNow = hymnal_library_collections($libDb);
$worshipNow = null;
foreach ($shelvesNow as $s) {
    if ($s['slug'] === 'worship') {
        $worshipNow = $s;
    }
}
eq('the worship shelf has one song', 1, $worshipNow['count']);
eq('the library count includes it', count(hymnal_corpus_index()) + 1, hymnal_library_count($libDb));

section('languages appear as they are filled');
$langs = hymnal_library_languages($libDb);
$codes = array_map(static fn(array $l): string => $l['lang'], $langs);
ok('English is there', in_array('en', $codes, true));
ok('Yoruba is not offered while nothing is in it', !in_array('yo', $codes, true));

$yo = hymnal_song_save($libDb, 7, hymnal_test_song_input([
    'id'       => (int) $save['id'],
    'lang'     => 'yo',
    'title'    => 'Baruch Hashem Adonai',
    'sections' => [['kind' => 'verse', 'lines' => ['Olúwa ni mo yìn', 'Ògo ni fún Olúwa']]],
]));
ok('a Yoruba version saves against the same song', $yo['ok'], json_encode($yo));
eq('and keeps the same HOD number', $number, (int) $yo['number']);

$langsNow = hymnal_library_languages($libDb);
$codesNow = array_map(static fn(array $l): string => $l['lang'], $langsNow);
ok('now Yoruba is offered', in_array('yo', $codesNow, true));

$both = hymnal_library_song($libDb, $number);
ok('the song carries both languages', isset($both['langs']['en'], $both['langs']['yo']));
eq('the Yoruba words are kept exactly', 'Olúwa ni mo yìn', $both['langs']['yo']['sections'][0]['lines'][0]);
eq('the English is not disturbed', 'Baruch Hashem Adonai', $both['langs']['en']['sections'][0]['lines'][0]);
