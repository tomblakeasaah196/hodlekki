<?php
// /tests/bible/api_bible_test.php — API.Bible HTML parsing and catalogue
// matching. Fixtures are real API.Bible responses for the Berean Standard
// Bible (public domain / CC0), recorded by the American Bible Society's own
// SDK contract tests.

$fx = __DIR__ . '/fixtures';

section('real chapter HTML (BSB Genesis 1)');
$verses = bible_api_parse_html((string) file_get_contents($fx . '/bsb_gen1_section.html'));
$by = [];
foreach ($verses as $v) {
    $by[$v['v']] = $v;
}
eq('31 verses', 31, count($verses));
eq('in order', range(1, 31), array_column($verses, 'v'));
eq('verse 1 text', 'In the beginning God created the heavens and the earth.', $by[1]['t']);
eq('section heading before verse 1', ['The Creation'], $by[1]['h'] ?? null);
eq('parallel-passage line is dropped', false, str_contains(json_encode($verses), 'Hebrews 11'));
eq('heading before verse 3', ['The First Day'], $by[3]['h'] ?? null);
eq('verse 5 continues into a new paragraph', true, !empty($by[5]['br']));
$br = $by[5]['br'][0];
eq('…and the break falls before "And there was evening"', 'And there was evening', mb_substr($by[5]['t'], $br, 21));
eq('verse 4 runs on in the same paragraph', false, isset($by[4]['p']));
eq('curly quotes survive', true, str_contains($by[3]['t'], '“Let there be light,”'));
eq('no double spaces', false, (bool) preg_grep('/  /', array_column($verses, 't')));

section('real passage HTML (BSB John 3:16–19)');
$verses = bible_api_parse_html((string) file_get_contents($fx . '/bsb_john3_16_19.html'));
eq('verses 16–19', [16, 17, 18, 19], array_column($verses, 'v'));
eq('19 opens a paragraph', 1, $verses[3]['p'] ?? null);
eq('no trailing space', false, str_ends_with($verses[0]['t'], ' '));

section('marks, notes and poetry (synthetic markup, not Scripture)');
$html = '<p class="s1">A Heading</p><p class="d">A song for testing.</p>'
    . '<p class="q1"><span data-number="1" data-sid="TST 1:1" class="v">1</span>The first line,</p><p class="q2">continues here.</p>'
    . '<p class="p"><span data-number="2" class="v">2</span>He said, <span class="wj">“Follow the <span class="add">small</span> path.”</span>'
    . '<span class="f"><span class="fr">1:2 </span><span class="ft">A footnote.</span></span> The <span class="nd">Lord</span> keeps.</p>'
    . '<p class="r">(Somewhere 1:1)</p><p class="m"><span class="v" data-number="3">3</span>Third.</p>';
$v = bible_api_parse_html($html);
eq('three verses', [1, 2, 3], array_column($v, 'v'));
eq('poetry verse text joins its lines', 'The first line, continues here.', $v[0]['t']);
eq('poetry flag', 1, $v[0]['q'] ?? null);
eq('second line breaks at "continues"', [16], $v[0]['br'] ?? null);
eq('heading and title attach to verse 1', [['A Heading'], 'A song for testing.'], [$v[0]['h'] ?? null, $v[0]['d'] ?? null]);
eq('footnote dropped', 'He said, “Follow the small path.” The Lord keeps.', $v[1]['t']);
$cut = static fn(array $verse, string $k): string => mb_substr($verse['t'], $verse[$k][0], $verse[$k][1] - $verse[$k][0]);
eq('words of Jesus', '“Follow the small path.”', $cut($v[1], 'w'));
eq('supplied word', 'small', $cut($v[1], 'i'));
eq('divine name in small caps', 'Lord', $cut($v[1], 'n'));
eq('cross-reference paragraph skipped', 'Third.', $v[2]['t']);
eq('empty HTML', [], bible_api_parse_html(''));

section('catalogue matching');
$catalog = json_decode((string) file_get_contents($fx . '/api_bible_catalog_eng.json'), true);
eq('NKJV found', '63097d2a0a2f7db3-01', bible_api_match($catalog, 'NKJV')['id'] ?? null);
eq('NIV found under its local abbreviation (NIV11 internally)', '78a9f6124f344018-01', bible_api_match($catalog, 'NIV')['id'] ?? null);
eq('AMP absent from this key → null', null, bible_api_match($catalog, 'AMP'));
$withAmp = array_merge($catalog, [
    ['id' => 'ampc-1', 'abbreviation' => 'AMPC', 'abbreviationLocal' => 'AMPC', 'name' => 'Amplified Bible, Classic Edition'],
    ['id' => 'amp-1', 'abbreviation' => 'engAMP', 'abbreviationLocal' => 'AMP', 'name' => 'Amplified Bible'],
]);
eq('AMP matched, Amplified Classic ignored', 'amp-1', bible_api_match($withAmp, 'AMP')['id'] ?? null);
eq('unknown code', null, bible_api_match($catalog, 'XYZ'));
eq('copyright HTML flattened', 'Copyright © 1982 Thomas Nelson. Used by permission.', bible_api_plain('<p>Copyright &copy; 1982 Thomas Nelson.<br>Used by permission.</p>'));
