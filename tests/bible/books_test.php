<?php
// /tests/bible/books_test.php — the canon, reference parsing and formatting.

section('canon');
eq('66 books', 66, count(bible_books()));
eq('1,189 chapters', 1189, bible_total_chapters());
eq('39 Old Testament books', 39, count(array_filter(bible_books(), static fn($b) => $b['testament'] === 'OT')));
eq('Genesis is book 1', 'GEN', bible_book(1)['usfm']);
eq('John is book 43', 'JHN', bible_book(43)['usfm']);
eq('Revelation is book 66', 'REV', bible_book(66)['usfm']);
eq('USFM lookup', 19, bible_book_by_usfm('psa')['id']);
eq('Unique USFM codes', 66, count(array_unique(array_column(bible_books(), 'usfm'))));
eq('Sequence covers the canon', 1189, count(bible_chapter_sequence()));

section('aliases never collide');
$seen = [];
$dupes = [];
foreach (BIBLE_BOOK_ROWS as $i => $row) {
    foreach (explode(' ', $row[6]) as $alias) {
        $k = bible_book_key($alias);
        if (isset($seen[$k]) && $seen[$k] !== $i + 1) {
            $dupes[] = $alias;
        }
        $seen[$k] = $i + 1;
    }
}
eq('no alias names two books', [], $dupes);

section('parsing');
$cases = [
    'John 3:16'        => 'John 3:16',
    'jn 3:16-18'       => 'John 3:16–18',
    'Jn 3.16'          => 'John 3:16',
    '1 John 4:8'       => '1 John 4:8',
    'I John 4'         => '1 John 4',
    'iii john 1:2'     => '3 John 1:2',
    'Second Kings 2'   => '2 Kings 2',
    '1st Cor 13'       => '1 Corinthians 13',
    'Ps 23'            => 'Psalm 23',
    'psalm 119:105'    => 'Psalm 119:105',
    'Song 2:4'         => 'Song of Solomon 2:4',
    'Song of Solomon 1' => 'Song of Solomon 1',
    'Jude 5'           => 'Jude 1:5',
    'Obadiah 3-4'      => 'Obadiah 1:3–4',
    'Gen 1-3'          => 'Genesis 1–3',
    'Romans 8:28–9:2'  => 'Romans 8:28–9:2',
    'Phil 4:13'        => 'Philippians 4:13',
    'Philemon 6'       => 'Philemon 1:6',
    'revelatio 3'      => 'Revelation 3',
    'Romans'           => 'Romans 1',
    'ex 3:14'          => 'Exodus 3:14',
];
foreach ($cases as $in => $want) {
    $p = bible_parse_ref($in);
    $got = $p === null ? null : bible_format_ref($p['book'], $p['chapter'], $p['verse'], $p['chapter_end'], $p['verse_end']);
    eq("parse '{$in}'", $want, $got);
}
foreach (['hello', 'Matthew 29', 'John 0', 'Phi 2', 'John 3:16-3', '', 'Romans 9:2-8:28'] as $bad) {
    eq("reject '{$bad}'", null, bible_parse_ref($bad));
}

section('formatting');
eq('chapter', 'Psalm 23', bible_format_ref(19, 23));
eq('psalm run', 'Psalms 148–150', bible_format_ref(19, 148, null, 150));
eq('verse range', 'John 3:16–18', bible_format_ref(43, 3, 16, null, 18));
eq('same verse', 'John 3:16', bible_format_ref(43, 3, 16, null, 16));
eq('short', 'Ps 23', bible_format_ref(19, 23, null, null, null, true));
