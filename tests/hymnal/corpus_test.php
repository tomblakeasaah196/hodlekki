<?php
// /tests/hymnal/corpus_test.php
//
// The generated corpus: that it is intact, that it is shaped as the reader
// expects, and that nothing copyrighted got in.

section('MANIFEST pins every generated file');
$v = hymnal_corpus_verify();
ok('corpus matches MANIFEST', $v['ok'], implode('; ', array_slice($v['problems'], 0, 3)));
ok('MANIFEST covers the shards, the index and meta', $v['checked'] >= 13, 'checked ' . $v['checked']);

section('the index and the shards agree');
$index = hymnal_corpus_index();
$meta  = hymnal_corpus_meta();
eq('index rows match meta.songs', (int) $meta['songs'], count($index));
ok('the library is worth opening', count($index) >= 500, 'only ' . count($index) . ' hymns');

$numbers = array_map(static fn(array $r): int => (int) $r['n'], $index);
eq('every HOD number is unique', count($numbers), count(array_unique($numbers)));
eq('numbering starts at 1', 1, min($numbers));
ok('numbering stays inside the corpus block', max($numbers) <= HYMNAL_CORPUS_CEILING, 'max ' . max($numbers));

$withScore = count(array_filter($index, static fn(array $r): bool => !empty($r['score'])));
eq('meta.with_score matches the index', (int) $meta['with_score'], $withScore);
ok('most of the library has a score', $withScore >= 300, 'only ' . $withScore);

section('a known hymn reads correctly end to end');
$amazing = null;
foreach ($index as $r) {
    if ($r['title'] === 'Amazing Grace') {
        $amazing = hymnal_corpus_song((int) $r['n']);
        break;
    }
}
ok('Amazing Grace is in the library', $amazing !== null);
if ($amazing !== null) {
    eq('it comes from the corpus', 'corpus', $amazing['origin']);
    eq('its metre is common metre', '8.6.8.6', $amazing['metre']);
    ok('it has verses', count($amazing['langs']['en']['sections']) >= 4);
    ok('its first line is the first line', str_starts_with($amazing['first_line'], 'Amazing grace'), $amazing['first_line']);
    ok('it has a four-part score', $amazing['has']['score'] && count($amazing['score']['voices']) === 4);
    eq('the voices are named for a choir', ['Soprano', 'Alto', 'Tenor', 'Bass'],
        array_map(static fn(array $x): string => $x['name'], $amazing['score']['voices']));
    // NEW BRITAIN opens on the fifth below the tonic, then doh-me-doh.
    $soprano = $amazing['score']['voices'][0]['solfa'];
    eq('the melody opens on soh below doh', ['s,'], $soprano[0]);
    eq('then doh me doh', ['d', 'm', 'd'], $soprano[1]);
    eq('playback is offered wherever there is a score', true, $amazing['has']['audio']);
}

section('every hymn is shaped the way the reader expects');
$problems = [];
$noScoreWithoutReason = 0;
$checked = 0;
foreach ($index as $r) {
    $song = hymnal_corpus_song((int) $r['n']);
    if ($song === null) {
        $problems[] = 'hymn ' . $r['n'] . ' is in the index but not in its shard';
        continue;
    }
    $checked++;
    if ($song['title'] === '' || $song['langs']['en']['sections'] === []) {
        $problems[] = 'hymn ' . $r['n'] . ' has no title or no words';
    }
    if ($song['has']['score'] !== (bool) $r['score']) {
        $problems[] = 'hymn ' . $r['n'] . ' disagrees with the index about having a score';
    }
    if (!$song['has']['score'] && trim((string) $song['no_score']) === '') {
        $noScoreWithoutReason++;
    }
    if ($song['collections'] === []) {
        $problems[] = 'hymn ' . $r['n'] . ' is on no shelf, so nobody can browse to it';
    }
}
eq('every index row has a record in its shard', count($index), $checked);
eq('no shape problems', [], array_slice($problems, 0, 5));
eq('a hymn without a score always says why', 0, $noScoreWithoutReason);

section('nothing copyrighted got in');
$badLicence = [];
$unverified = [];
foreach ($index as $r) {
    $song = hymnal_corpus_song((int) $r['n']);
    if ($song === null) {
        continue;
    }
    if (($song['licence']['code'] ?? '') !== 'PD') {
        $badLicence[] = $r['n'];
    }
    // The words and the tune must each be stated, separately. A hymn being old
    // is not a licence, and a harmony has its own copyright.
    $parts = [];
    foreach ($song['licence']['parts'] ?? [] as $p) {
        $parts[$p['part']] = $p['licence'];
    }
    if (($parts['Words'] ?? '') !== 'PD' || ($parts['Tune'] ?? '') !== 'PD') {
        $unverified[] = $r['n'];
    }
    if ($song['has']['score'] && isset($parts['Harmony']) && $parts['Harmony'] !== 'PD') {
        $unverified[] = $r['n'];
    }
}
eq('every hymn is public domain', [], array_slice($badLicence, 0, 5));
eq('words and tune are each verified public domain', [], array_slice($unverified, 0, 5));

section('what the build deliberately left out is recorded');
ok('held-back songs are listed', isset($meta['held_back']) && is_array($meta['held_back']));
ok('scores rejected for carrying other words are listed', isset($meta['scores_wrong_text']));
ok('the sources are credited', !empty($meta['sources']));
