<?php
// /tests/hymnal/search_test.php
//
// Search, tested the way a member uses it: with what they would actually type —
// the wrong punctuation, a slip of the finger, half a word, a line they
// remember from the third verse — and checking the right hymn comes up.
//
// Runs after library_test.php, which leaves a published Studio song ($libDb,
// $number) behind for the last section.

section('folding: one normaliser for what is stored and what is typed');
eq('punctuation and case go', 'holy holy holy', hymnal_search_fold('Holy, Holy, Holy'));
eq('an apostrophe joins, it does not split', 'powr of grace', hymnal_search_fold("pow'r of grace"));
eq('a curly apostrophe too', 'twill guide', hymnal_search_fold("’Twill guide"));
eq('French and German accents go', 'cafe munster ss', hymnal_search_fold('Café Münster ß'));
eq('Yoruba dots and tones go (precomposed)', 'ese olorun', hymnal_search_fold("\u{1EB8}\u{1E63}\u{1EB9}, \u{1ECC}l\u{1ECD}\u{0301}run"));
eq('and the same letters arriving as base + mark', 'ese olorun', hymnal_search_fold("E\u{0323}s\u{0323}e\u{0323}, O\u{0323}lo\u{0323}\u{0301}run"));
eq('nothing left is nothing', '', hymnal_search_fold(" -- ,, "));

section('typos: one slip, a swapped pair, a missing letter');
eq('a swapped pair is one slip', 1, hymnal_search_distance('teh', 'the', 2));
eq('a doubled letter is one slip', 1, hymnal_search_distance('wonderfull', 'wonderful', 2));
eq('too far gives up above the limit', 3, hymnal_search_distance('cat', 'dog', 2));
eq('the same word is no distance', 0, hymnal_search_distance('grace', 'grace', 2));

section('finding a hymn by what a member would type');
$first = static function (string $q, ?PDO $db = null) {
    $r = hymnal_library_search($db, $q, ['limit' => 10]);

    return $r['rows'][0] ?? null;
};
eq('the title without its commas', 'Holy, Holy, Holy', $first('holy holy holy')['title'] ?? null);
eq('in capitals with stray spacing', 'Holy, Holy, Holy', $first('HOLY   holy,holy')['title'] ?? null);
eq('a title with a slip of the finger', 'Amazing Grace', $first('amazin grace')['title'] ?? null);
eq('a title with a swapped pair', 'Amazing Grace', $first('amazign grace')['title'] ?? null);
eq('a title half typed', 'Amazing Grace', $first('amazing grac')['title'] ?? null);
eq('a word with a doubled letter', 'Wonderful Words of Life', $first('wonderfull')['title'] ?? null);
eq('accents typed on a phone', 'Jesus, Lover of My Soul', $first("J\u{00E9}sus, lover of my soul")['title'] ?? null);
eq('a hymn number', 31, $first('31')['n'] ?? null);
eq('a hymn number with the word hymn', 31, $first('hymn 31')['n'] ?? null);
eq('a hymn number with a hash', 172, $first('#172')['n'] ?? null);
eq('nonsense still finds nothing', 0, hymnal_library_search(null, 'qxzjvkw')['total']);

section('a real word is never turned into a near word');
$love = hymnal_library_search(null, 'love', ['limit' => 50]);
$corpusDocs = hymnal_search_corpus()['docs'];
$strays = [];
foreach ($love['rows'] as $r) {
    $d = $corpusDocs[(int) $r['n']] ?? null;
    $text = $d === null ? '' : ' ' . $d['t'] . ' ' . implode(' ', $d['l']) . ' ' . $d['w'] . ' ' . $d['h'];
    if (!str_contains($text, ' love')) {
        $strays[] = $r['n'];
    }
}
eq("every hymn found for 'love' has a word starting 'love'", [], $strays);

section('finding a hymn by a line from inside it');
$rock = $first('let me hide myself');
eq('a remembered line finds its hymn', 'Rock of Ages', $rock['title'] ?? null);
eq('a line from a later verse finds it too', 'Rock of Ages', $first('cleft for me')['title'] ?? null);
$byLine = hymnal_library_search(null, 'rock of ages', ['limit' => 20]);
$glorious = null;
foreach ($byLine['rows'] as $r) {
    if ((int) $r['n'] === 127) {
        $glorious = $r;
    }
}
ok('a hymn found only by a line carries that line', isset($glorious['match']['line']), json_encode($glorious['match'] ?? null));
ok('and says which verse it is in', str_contains((string) ($glorious['match']['where'] ?? ''), 'Verse'));
ok('the line is the real, unfolded text', str_contains((string) ($glorious['match']['line'] ?? ''), 'Rock of Ages'));
$byTitle = hymnal_library_search(null, 'amazing grace', ['limit' => 3]);
ok('a hymn found by its title carries no snippet', !isset($byTitle['rows'][0]['match']));

section('ranking: the title beats a line, a line beats the words scattered');
$ranked = hymnal_search_rank(null, hymnal_library_index(null), 'rock of ages');
eq('the exact title is first', 375, (int) $ranked[0]['row']['n']);
eq('and was found as a title', 'title', $ranked[0]['kind']);
$pos = [];
foreach ($ranked as $i => $h) {
    $pos[(int) $h['row']['n']] = $i;
}
ok('a hymn found only by a lyric line ranks below the title match',
    isset($pos[127], $pos[375]) && $pos[375] < $pos[127],
    json_encode([$pos[375] ?? null, $pos[127] ?? null]));
eq('and that hymn was found as a lyric', 'lyric', $ranked[$pos[127] ?? 0]['kind'] ?? null);
$scores = array_column($ranked, 'score');
$sorted = $scores;
rsort($sorted);
eq('results are in score order', $sorted, $scores);

section('the music team\'s songs are searched too');
$studioHit = hymnal_library_search($libDb, 'lord most high', ['limit' => 10]);
$mine = null;
foreach ($studioHit['rows'] as $r) {
    if ((int) $r['n'] === $number) {
        $mine = $r;
    }
}
ok('a Studio song is found by a line of its words', $mine !== null);
eq('and shows that line', 'Blessed be the Lord most high', $mine['match']['line'] ?? null);
$typo = hymnal_library_search($libDb, 'baruch hashen', ['limit' => 5]);
eq('its title survives a typo', $number, (int) ($typo['rows'][0]['n'] ?? 0));

section('the committed index is the corpus, folded');
$committed = is_file(HYMNAL_SEARCH_INDEX_FILE) ? require HYMNAL_SEARCH_INDEX_FILE : [];
ok('search_index.php exists', $committed !== []);
eq('it is for this fold version', HYMNAL_SEARCH_VERSION, $committed['version'] ?? null);
eq('it is pinned to this MANIFEST', hymnal_search_manifest_hash(), $committed['manifest'] ?? null);
eq('it holds every hymn the library has', count(hymnal_corpus_index()), count($committed['docs'] ?? []));
ok('it matches a fresh rebuild exactly — run: php bin/build_hymnal_search.php', hymnal_search_build_corpus() === $committed);
ok('a withdrawn hymn is not in it', !isset($committed['docs'][178]));

section('every hymn can be found by its own title and first line');
$lost = [];
$tried = 0;
foreach (hymnal_corpus_index() as $i => $r) {
    if ($i % 10 !== 0) {
        continue;
    }
    $tried++;
    foreach (['title', 'first_line'] as $field) {
        $q = trim((string) $r[$field]);
        if ($q === '') {
            continue;
        }
        $ns = array_map(static fn(array $x): int => (int) $x['n'], hymnal_library_search(null, $q, ['limit' => 8])['rows']);
        if (!in_array((int) $r['n'], $ns, true)) {
            $lost[] = $r['n'] . ' by ' . $field;
        }
    }
}
ok('a sample of the library was tried', $tried >= 40, 'tried ' . $tried);
eq('every hymn tried is found by its title and by its first line', [], array_slice($lost, 0, 5));
