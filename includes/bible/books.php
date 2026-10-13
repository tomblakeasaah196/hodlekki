<?php
// /includes/bible/books.php
//
// The 66-book Protestant canon, and the two things everything else in The
// Bible module needs from it: turning what a member types ("1 jn 4:8",
// "Ps 23", "song 2:4") into numbers, and turning numbers back into a
// reference people can read ("1 John 4:8").
//
// Books are numbered 1 (Genesis) to 66 (Revelation). Every table and every
// data file uses that number; `usfm` is the book code API.Bible expects in a
// chapter id ("JHN.3"), `osis` the code in the KJV source file.

const BIBLE_BOOK_ROWS = [
    // usfm, osis, name, short, chapters, group, extra aliases (compact, lower case)
    ['GEN', 'Gen',    'Genesis',         'Gen',    50,  'Law',              'ge gn'],
    ['EXO', 'Exod',   'Exodus',          'Exod',   40,  'Law',              'ex exo exod'],
    ['LEV', 'Lev',    'Leviticus',       'Lev',    27,  'Law',              'le lv'],
    ['NUM', 'Num',    'Numbers',         'Num',    36,  'Law',              'nu nm nb'],
    ['DEU', 'Deut',   'Deuteronomy',     'Deut',   34,  'Law',              'de dt deu'],
    ['JOS', 'Josh',   'Joshua',          'Josh',   24,  'History',          'jos jsh'],
    ['JDG', 'Judg',   'Judges',          'Judg',   21,  'History',          'jdg jg jdgs'],
    ['RUT', 'Ruth',   'Ruth',            'Ruth',   4,   'History',          'ru rth'],
    ['1SA', '1Sam',   '1 Samuel',        '1 Sam',  31,  'History',          '1sa 1s 1sm'],
    ['2SA', '2Sam',   '2 Samuel',        '2 Sam',  24,  'History',          '2sa 2s 2sm'],
    ['1KI', '1Kgs',   '1 Kings',         '1 Kgs',  22,  'History',          '1ki 1k 1kgs 1kin'],
    ['2KI', '2Kgs',   '2 Kings',         '2 Kgs',  25,  'History',          '2ki 2k 2kgs 2kin'],
    ['1CH', '1Chr',   '1 Chronicles',    '1 Chr',  29,  'History',          '1ch 1chr 1chron'],
    ['2CH', '2Chr',   '2 Chronicles',    '2 Chr',  36,  'History',          '2ch 2chr 2chron'],
    ['EZR', 'Ezra',   'Ezra',            'Ezra',   10,  'History',          'ezr'],
    ['NEH', 'Neh',    'Nehemiah',        'Neh',    13,  'History',          'ne'],
    ['EST', 'Esth',   'Esther',          'Esth',   10,  'History',          'es est'],
    ['JOB', 'Job',    'Job',             'Job',    42,  'Poetry & Wisdom',  'jb'],
    ['PSA', 'Ps',     'Psalms',          'Ps',     150, 'Poetry & Wisdom',  'ps psa psm pss psalm'],
    ['PRO', 'Prov',   'Proverbs',        'Prov',   31,  'Poetry & Wisdom',  'pr pro prv'],
    ['ECC', 'Eccl',   'Ecclesiastes',    'Eccl',   12,  'Poetry & Wisdom',  'ec ecc eccles qoh'],
    ['SNG', 'Song',   'Song of Solomon', 'Song',   8,   'Poetry & Wisdom',  'so sng song songofsongs canticles sos'],
    ['ISA', 'Isa',    'Isaiah',          'Isa',    66,  'Major Prophets',   'is'],
    ['JER', 'Jer',    'Jeremiah',        'Jer',    52,  'Major Prophets',   'je jr'],
    ['LAM', 'Lam',    'Lamentations',    'Lam',    5,   'Major Prophets',   'la'],
    ['EZK', 'Ezek',   'Ezekiel',         'Ezek',   48,  'Major Prophets',   'eze ezk'],
    ['DAN', 'Dan',    'Daniel',          'Dan',    12,  'Major Prophets',   'da dn'],
    ['HOS', 'Hos',    'Hosea',           'Hos',    14,  'Minor Prophets',   'ho'],
    ['JOL', 'Joel',   'Joel',            'Joel',   3,   'Minor Prophets',   'jl jol'],
    ['AMO', 'Amos',   'Amos',            'Amos',   9,   'Minor Prophets',   'am amo'],
    ['OBA', 'Obad',   'Obadiah',         'Obad',   1,   'Minor Prophets',   'ob oba'],
    ['JON', 'Jonah',  'Jonah',           'Jonah',  4,   'Minor Prophets',   'jnh jon'],
    ['MIC', 'Mic',    'Micah',           'Mic',    7,   'Minor Prophets',   'mc'],
    ['NAM', 'Nah',    'Nahum',           'Nah',    3,   'Minor Prophets',   'na nam'],
    ['HAB', 'Hab',    'Habakkuk',        'Hab',    3,   'Minor Prophets',   'hb'],
    ['ZEP', 'Zeph',   'Zephaniah',       'Zeph',   3,   'Minor Prophets',   'zp zep'],
    ['HAG', 'Hag',    'Haggai',          'Hag',    2,   'Minor Prophets',   'hg'],
    ['ZEC', 'Zech',   'Zechariah',       'Zech',   14,  'Minor Prophets',   'zc zec'],
    ['MAL', 'Mal',    'Malachi',         'Mal',    4,   'Minor Prophets',   'ml'],
    ['MAT', 'Matt',   'Matthew',         'Matt',   28,  'Gospels',          'mt mat'],
    ['MRK', 'Mark',   'Mark',            'Mark',   16,  'Gospels',          'mk mrk mr'],
    ['LUK', 'Luke',   'Luke',            'Luke',   24,  'Gospels',          'lk luk lu'],
    ['JHN', 'John',   'John',            'John',   21,  'Gospels',          'jn jhn joh'],
    ['ACT', 'Acts',   'Acts',            'Acts',   28,  'Church History',   'ac act'],
    ['ROM', 'Rom',    'Romans',          'Rom',    16,  'Paul’s Letters',   'ro rm'],
    ['1CO', '1Cor',   '1 Corinthians',   '1 Cor',  16,  'Paul’s Letters',   '1co 1cor'],
    ['2CO', '2Cor',   '2 Corinthians',   '2 Cor',  13,  'Paul’s Letters',   '2co 2cor'],
    ['GAL', 'Gal',    'Galatians',       'Gal',    6,   'Paul’s Letters',   'ga'],
    ['EPH', 'Eph',    'Ephesians',       'Eph',    6,   'Paul’s Letters',   'ep'],
    ['PHP', 'Phil',   'Philippians',     'Phil',   4,   'Paul’s Letters',   'php pp phil'],
    ['COL', 'Col',    'Colossians',      'Col',    4,   'Paul’s Letters',   'co'],
    ['1TH', '1Thess', '1 Thessalonians', '1 Thess', 5,  'Paul’s Letters',   '1th 1thes 1thess'],
    ['2TH', '2Thess', '2 Thessalonians', '2 Thess', 3,  'Paul’s Letters',   '2th 2thes 2thess'],
    ['1TI', '1Tim',   '1 Timothy',       '1 Tim',  6,   'Paul’s Letters',   '1ti 1tm'],
    ['2TI', '2Tim',   '2 Timothy',       '2 Tim',  4,   'Paul’s Letters',   '2ti 2tm'],
    ['TIT', 'Titus',  'Titus',           'Titus',  3,   'Paul’s Letters',   'ti tit'],
    ['PHM', 'Phlm',   'Philemon',        'Phlm',   1,   'Paul’s Letters',   'phm phlm philem pm'],
    ['HEB', 'Heb',    'Hebrews',         'Heb',    13,  'General Letters',  'he'],
    ['JAS', 'Jas',    'James',           'Jas',    5,   'General Letters',  'jm ja jas'],
    ['1PE', '1Pet',   '1 Peter',         '1 Pet',  5,   'General Letters',  '1pe 1pt 1p'],
    ['2PE', '2Pet',   '2 Peter',         '2 Pet',  3,   'General Letters',  '2pe 2pt 2p'],
    ['1JN', '1John',  '1 John',          '1 John', 5,   'General Letters',  '1jn 1jo 1joh 1j'],
    ['2JN', '2John',  '2 John',          '2 John', 1,   'General Letters',  '2jn 2jo 2joh 2j'],
    ['3JN', '3John',  '3 John',          '3 John', 1,   'General Letters',  '3jn 3jo 3joh 3j'],
    ['JUD', 'Jude',   'Jude',            'Jude',   1,   'General Letters',  'jud jd'],
    ['REV', 'Rev',    'Revelation',      'Rev',    22,  'Prophecy',         're rv revelations apocalypse'],
];

/**
 * The canon keyed by book number (1–66).
 *
 * @return array<int, array{id:int, usfm:string, osis:string, name:string, short:string, chapters:int, group:string, testament:string}>
 */
function bible_books(): array
{
    static $books = null;
    if ($books !== null) {
        return $books;
    }

    $books = [];
    foreach (BIBLE_BOOK_ROWS as $i => $row) {
        $id = $i + 1;
        $books[$id] = [
            'id'        => $id,
            'usfm'      => $row[0],
            'osis'      => $row[1],
            'name'      => $row[2],
            'short'     => $row[3],
            'chapters'  => $row[4],
            'group'     => $row[5],
            'testament' => $id <= 39 ? 'OT' : 'NT',
        ];
    }

    return $books;
}

function bible_book(int $id): ?array
{
    return bible_books()[$id] ?? null;
}

function bible_book_by_usfm(string $usfm): ?array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (bible_books() as $book) {
            $map[$book['usfm']] = $book['id'];
        }
    }
    $id = $map[strtoupper($usfm)] ?? null;

    return $id === null ? null : bible_book($id);
}

/** Total chapters across the canon (1,189). */
function bible_total_chapters(): int
{
    return array_sum(array_column(bible_books(), 'chapters'));
}

/**
 * Is (book, chapter) inside the canon?
 */
function bible_chapter_exists(int $book, int $chapter): bool
{
    $b = bible_book($book);

    return $b !== null && $chapter >= 1 && $chapter <= $b['chapters'];
}

/**
 * Every chapter of the canon in order, as [book, chapter] pairs. Reading
 * plans are cut from this sequence.
 *
 * @return list<array{0:int, 1:int}>
 */
function bible_chapter_sequence(int $fromBook = 1, int $toBook = 66): array
{
    $out = [];
    foreach (bible_books() as $book) {
        if ($book['id'] < $fromBook || $book['id'] > $toBook) {
            continue;
        }
        for ($c = 1; $c <= $book['chapters']; $c++) {
            $out[] = [$book['id'], $c];
        }
    }

    return $out;
}

/**
 * Lower-case, no spaces or dots, ordinals and Roman numerals folded into a
 * leading digit: "I John" → "1john", "Second Kings" → "2kings".
 */
function bible_book_key(string $raw): string
{
    $s = mb_strtolower(trim($raw), 'UTF-8');
    $s = str_replace(['’', "'", '.'], '', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/^(first|1st|i)\s+/u', '1 ', $s) ?? $s;
    $s = preg_replace('/^(second|2nd|ii)\s+/u', '2 ', $s) ?? $s;
    $s = preg_replace('/^(third|3rd|iii)\s+/u', '3 ', $s) ?? $s;

    return str_replace(' ', '', $s);
}

/**
 * Compact alias → book number. Built from each book's name, its short form
 * and the extra aliases in BIBLE_BOOK_ROWS.
 *
 * @return array<string, int>
 */
function bible_book_aliases(): array
{
    static $aliases = null;
    if ($aliases !== null) {
        return $aliases;
    }

    $aliases = [];
    foreach (BIBLE_BOOK_ROWS as $i => $row) {
        $id = $i + 1;
        foreach (array_merge([$row[2], $row[3], $row[1], $row[0]], explode(' ', $row[6])) as $alias) {
            $key = bible_book_key($alias);
            if ($key !== '' && !isset($aliases[$key])) {
                $aliases[$key] = $id;
            }
        }
    }

    return $aliases;
}

/**
 * Resolve a book name as typed. Exact alias first, then an unambiguous
 * prefix of a full book name ("revel", "corinth" is ambiguous → null).
 */
function bible_find_book(string $raw): ?int
{
    $key = bible_book_key($raw);
    if ($key === '') {
        return null;
    }

    $aliases = bible_book_aliases();
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    if (strlen($key) < 3) {
        return null;
    }

    $hits = [];
    foreach (bible_books() as $book) {
        if (str_starts_with(bible_book_key($book['name']), $key)) {
            $hits[] = $book['id'];
        }
    }

    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * Parse a reference. Accepts "John 3", "John 3:16", "John 3:16-18",
 * "John 3:16–4:2", "Jn 3.16", "Jude 5" (single-chapter books read the number
 * as a verse) and a bare book name ("Romans" → chapter 1).
 *
 * @return array{book:int, chapter:int, verse:?int, chapter_end:int, verse_end:?int}|null
 */
function bible_parse_ref(string $raw): ?array
{
    $text = trim(str_replace(['–', '—'], '-', $raw));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if ($text === '' || mb_strlen($text) > 60) {
        return null;
    }

    // Book name, optionally followed by chapter[:verse][-[chapter:]verse].
    if (!preg_match('/^((?:[123]|i{1,3}|first|second|third|1st|2nd|3rd)?\s*[\p{L}][\p{L}\s.’\']*?)\s*(?:(\d{1,3})(?:\s*[:.]\s*(\d{1,3}))?(?:\s*-\s*(\d{1,3})(?:\s*[:.]\s*(\d{1,3}))?)?)?$/iu', $text, $m)) {
        return null;
    }

    $book = bible_find_book($m[1]);
    if ($book === null) {
        return null;
    }
    $meta = bible_book($book);

    $chapter  = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
    $verse    = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
    $endA     = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
    $endB     = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : null;

    // "Jude 5", "Obadiah 3-4": one-chapter books count verses, not chapters.
    if ($meta['chapters'] === 1 && $verse === null && isset($m[2]) && $m[2] !== '' && $chapter > 1) {
        $verse   = $chapter;
        $chapter = 1;
    } elseif ($meta['chapters'] === 1 && $verse === null && $endA !== null && $endB === null && isset($m[2]) && $m[2] !== '') {
        $verse = $chapter;
        $chapter = 1;
    }

    $chapterEnd = $chapter;
    $verseEnd   = $verse;
    if ($endA !== null) {
        if ($endB !== null) {
            // 3:16-4:2
            $chapterEnd = $endA;
            $verseEnd   = $endB;
        } elseif ($verse !== null) {
            // 3:16-18
            $verseEnd = $endA;
        } else {
            // "Genesis 1-3": a run of whole chapters.
            $chapterEnd = $endA;
        }
    }

    if (!bible_chapter_exists($book, $chapter) || !bible_chapter_exists($book, $chapterEnd)) {
        return null;
    }
    if ($chapterEnd < $chapter || ($chapterEnd === $chapter && $verse !== null && $verseEnd !== null && $verseEnd < $verse)) {
        return null;
    }
    if (($verse !== null && $verse < 1) || ($verseEnd !== null && $verseEnd < 1)) {
        return null;
    }

    return [
        'book'        => $book,
        'chapter'     => $chapter,
        'verse'       => $verse,
        'chapter_end' => $chapterEnd,
        'verse_end'   => $verseEnd,
    ];
}

/**
 * The display name for a book in a reference: "Psalm 23", not "Psalms 23".
 */
function bible_ref_book_name(int $book, bool $short = false): string
{
    $b = bible_book($book);
    if ($b === null) {
        return '';
    }
    if ($book === 19 && !$short) {
        return 'Psalm';
    }

    return $short ? $b['short'] : $b['name'];
}

/**
 * "John 3:16", "John 3:16–18", "John 3:16–4:2", "Psalm 23", "Genesis 1–3".
 */
function bible_format_ref(int $book, int $chapter, ?int $verse = null, ?int $chapterEnd = null, ?int $verseEnd = null, bool $short = false): string
{
    $name = bible_ref_book_name($book, $short);
    if ($name === '') {
        return '';
    }
    $chapterEnd = $chapterEnd ?? $chapter;

    if ($verse === null) {
        if ($chapterEnd !== $chapter) {
            // "Psalms 148–150": a run of psalms takes the plural.
            return sprintf('%s %d–%d', $book === 19 && !$short ? 'Psalms' : $name, $chapter, $chapterEnd);
        }

        return sprintf('%s %d', $name, $chapter);
    }

    $out = sprintf('%s %d:%d', $name, $chapter, $verse);
    if ($chapterEnd !== $chapter) {
        $out .= sprintf('–%d:%d', $chapterEnd, $verseEnd ?? 1);
    } elseif ($verseEnd !== null && $verseEnd !== $verse) {
        $out .= '–' . $verseEnd;
    }

    return $out;
}
