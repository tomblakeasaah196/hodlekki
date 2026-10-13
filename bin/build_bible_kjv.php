<?php
// /bin/build_bible_kjv.php
/**
 * ============================================================================
 * KJV DATA BUILDER — The Bible module
 * ----------------------------------------------------------------------------
 * Converts the public-domain King James Version (1769 standard text) from
 * OSIS into one PHP data file per book under includes/bible/kjv/:
 *
 *     curl -L -o /tmp/eng-kjv.osis.xml \
 *       https://raw.githubusercontent.com/seven1m/open-bibles/master/eng-kjv.osis.xml
 *     php bin/build_bible_kjv.php /tmp/eng-kjv.osis.xml
 *
 * It is the same file bible-api.com serves and that
 * includes/special_events/kjv_bundle.php was copied from, so the two never
 * disagree on a word (tests/bible/run.php checks every bundled verse).
 *
 * The output IS committed — the cPanel host has no build step. Never edit a
 * generated file by hand; re-run this instead.
 *
 * Each book file returns [chapter => list of verses], a verse being
 * [number, text] or [number, text, extras]. Offsets in extras count
 * characters (Unicode code points) into `text`:
 *
 *   'p' => 1                  the verse opens a paragraph
 *   'q' => 1                  the verse is a line of poetry
 *   'd' => 'A Psalm of David.' a heading printed above the verse
 *                              (Psalm titles, Psalm 119's ALEPH, BETH …)
 *   'w' => [start, end, …]    words of Jesus (red letters), end-exclusive
 *   'i' => [start, end, …]    words the translators supplied (italics)
 *
 * Only the 66 books of the Protestant canon are written; the Apocrypha in
 * the source file is skipped.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require_once __DIR__ . '/../includes/bible/books.php';

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php bin/build_bible_kjv.php path/to/eng-kjv.osis.xml\n");
    exit(1);
}
if (!class_exists('XMLReader')) {
    fwrite(STDERR, "ext-xmlreader is required.\n");
    exit(1);
}

$outDir = __DIR__ . '/../includes/bible/kjv';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$bookByOsis = [];
foreach (bible_books() as $book) {
    $bookByOsis[$book['osis']] = $book['id'];
}

/**
 * One verse being assembled: segments of raw text with the flags in force
 * when each was read.
 */
final class KjvVerse
{
    /** @var list<array{0:string, 1:bool, 2:bool}> */
    public array $segments = [];
    public bool $para = false;
    public bool $poetry = false;
    public ?string $heading = null;

    public function __construct(public int $number)
    {
    }
}

/**
 * Collapse whitespace across segments and derive the mark ranges.
 *
 * @return array{0:string, 1:list<int>, 2:list<int>}
 */
function kjv_flatten(KjvVerse $v): array
{
    $chars  = [];
    $jesus  = [];
    $added  = [];
    foreach ($v->segments as [$text, $isJesus, $isAdded]) {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            $last = $chars === [] ? null : $chars[count($chars) - 1];
            if ($ch === ' ' && ($last === null || $last === ' ')) {
                continue;
            }
            $chars[] = $ch;
            $jesus[] = $isJesus;
            $added[] = $isAdded;
        }
    }
    while ($chars !== [] && $chars[count($chars) - 1] === ' ') {
        array_pop($chars);
        array_pop($jesus);
        array_pop($added);
    }

    return [implode('', $chars), kjv_ranges($chars, $jesus), kjv_ranges($chars, $added)];
}

/**
 * Flag list → flat [start, end, …] ranges, trimmed of edge spaces.
 *
 * @param list<string> $chars
 * @param list<bool>   $flags
 * @return list<int>
 */
function kjv_ranges(array $chars, array $flags): array
{
    $out = [];
    $n = count($flags);
    $i = 0;
    while ($i < $n) {
        if (!$flags[$i]) {
            $i++;
            continue;
        }
        $start = $i;
        while ($i < $n && $flags[$i]) {
            $i++;
        }
        $end = $i;
        while ($start < $end && $chars[$start] === ' ') {
            $start++;
        }
        while ($end > $start && $chars[$end - 1] === ' ') {
            $end--;
        }
        if ($end > $start) {
            $out[] = $start;
            $out[] = $end;
        }
    }

    return $out;
}

function kjv_php_string(string $s): string
{
    return "'" . strtr($s, ['\\' => '\\\\', "'" => "\\'"]) . "'";
}

$reader = new XMLReader();
if (!$reader->open($source, 'UTF-8', LIBXML_NONET)) {
    fwrite(STDERR, "Cannot open {$source}\n");
    exit(1);
}

$data         = [];     // book => chapter => verse => [text, extras]
$book         = null;   // current book number, null outside the canon
$chapter      = 0;
$verse        = null;   // KjvVerse being read
$inJesus      = false;
$addedDepth   = 0;
$titleDepth   = 0;
$titleType    = '';
$titleText    = '';
$pendingPara  = false;
$poetryDepth  = 0;
$pendingTitle = null;

while ($reader->read()) {
    $type = $reader->nodeType;
    $name = $reader->localName;

    if ($type === XMLReader::ELEMENT) {
        $empty = $reader->isEmptyElement;

        if ($name === 'div' && $reader->getAttribute('type') === 'book') {
            $osis = (string) $reader->getAttribute('osisID');
            $book = $bookByOsis[$osis] ?? null;
            $chapter = 0;
            continue;
        }
        if ($book === null) {
            continue;
        }

        switch ($name) {
            case 'chapter':
                if ($reader->getAttribute('sID') !== null) {
                    $chapter = (int) $reader->getAttribute('n');
                    $pendingPara = true;
                }
                break;

            case 'verse':
                if ($reader->getAttribute('sID') !== null) {
                    $verse = new KjvVerse((int) $reader->getAttribute('n'));
                    $verse->para = $pendingPara;
                    $verse->poetry = $poetryDepth > 0;
                    $verse->heading = $pendingTitle;
                    $pendingPara = false;
                    $pendingTitle = null;
                } elseif ($reader->getAttribute('eID') !== null && $verse !== null) {
                    [$text, $w, $i] = kjv_flatten($verse);
                    $extras = [];
                    if ($verse->para) {
                        $extras['p'] = 1;
                    }
                    if ($verse->poetry) {
                        $extras['q'] = 1;
                    }
                    if ($verse->heading !== null) {
                        $extras['d'] = $verse->heading;
                    }
                    if ($w !== []) {
                        $extras['w'] = $w;
                    }
                    if ($i !== []) {
                        $extras['i'] = $i;
                    }
                    $data[$book][$chapter][$verse->number] = [$text, $extras];
                    $verse = null;
                }
                break;

            case 'q':
                if ($reader->getAttribute('sID') !== null && $reader->getAttribute('who') === 'Jesus') {
                    $inJesus = true;
                } elseif ($reader->getAttribute('eID') !== null) {
                    $inJesus = false;
                }
                break;

            case 'transChange':
                if (!$empty) {
                    $addedDepth++;
                }
                break;

            case 'p':
                if (!$empty) {
                    $pendingPara = true;
                }
                break;

            case 'lb':
                $pendingPara = true;
                break;

            case 'lg':
                if (!$empty) {
                    $pendingPara = true;
                }
                break;

            case 'l':
                if (!$empty) {
                    $poetryDepth++;
                }
                break;

            case 'title':
                if (!$empty) {
                    $titleDepth++;
                    $titleType = (string) $reader->getAttribute('type');
                    $titleText = '';
                }
                break;
        }
        continue;
    }

    if ($type === XMLReader::END_ELEMENT) {
        if ($name === 'div' && $book !== null && $verse === null && $chapter > 0) {
            // Leaving a book <div>: the next book sets $book again.
        }
        switch ($name) {
            case 'transChange':
                $addedDepth = max(0, $addedDepth - 1);
                break;
            case 'l':
                $poetryDepth = max(0, $poetryDepth - 1);
                break;
            case 'title':
                $titleDepth = max(0, $titleDepth - 1);
                if ($book !== null && $titleType === 'psalm') {
                    $pendingTitle = trim(preg_replace('/\s+/u', ' ', $titleText) ?? $titleText);
                    $pendingPara = true;
                }
                $titleType = '';
                $titleText = '';
                break;
        }
        continue;
    }

    if ($type === XMLReader::TEXT || $type === XMLReader::WHITESPACE || $type === XMLReader::SIGNIFICANT_WHITESPACE) {
        if ($book === null) {
            continue;
        }
        if ($titleDepth > 0) {
            $titleText .= $reader->value;
            continue;
        }
        if ($verse !== null) {
            $verse->segments[] = [$reader->value, $inJesus, $addedDepth > 0];
        }
    }
}
$reader->close();

// ---------------------------------------------------------------------------
// Validate against the canon before writing anything.
// ---------------------------------------------------------------------------
$problems = [];
$verseTotal = 0;
foreach (bible_books() as $b) {
    $chapters = $data[$b['id']] ?? [];
    if (count($chapters) !== $b['chapters']) {
        $problems[] = sprintf('%s: %d chapters, expected %d', $b['name'], count($chapters), $b['chapters']);
    }
    foreach ($chapters as $c => $verses) {
        $expected = 1;
        foreach (array_keys($verses) as $n) {
            if ($n !== $expected) {
                $problems[] = sprintf('%s %d: verse %d out of sequence', $b['name'], $c, $n);
                break;
            }
            $expected++;
        }
        foreach ($verses as $n => [$text]) {
            if ($text === '') {
                $problems[] = sprintf('%s %d:%d is empty', $b['name'], $c, $n);
            }
        }
        $verseTotal += count($verses);
    }
}
if ($verseTotal !== 31102) {
    $problems[] = "verse total {$verseTotal}, expected 31102";
}
if ($problems !== []) {
    fwrite(STDERR, "Refusing to write: the source does not look like the KJV.\n  " . implode("\n  ", array_slice($problems, 0, 20)) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Write one file per book.
// ---------------------------------------------------------------------------
$sourceHash = hash_file('sha256', $source);
$manifest = [];

foreach (bible_books() as $b) {
    $file = sprintf('%s/%02d.php', $outDir, $b['id']);
    $lines = [];
    $lines[] = '<?php';
    $lines[] = sprintf('// /includes/bible/kjv/%02d.php', $b['id']);
    $lines[] = '//';
    $lines[] = sprintf('// %s — King James Version (1769), public domain.', $b['name']);
    $lines[] = '// GENERATED by bin/build_bible_kjv.php — never edit by hand.';
    $lines[] = '';
    $lines[] = 'return [';
    foreach ($data[$b['id']] as $c => $verses) {
        $lines[] = sprintf('    %d => [', $c);
        foreach ($verses as $n => [$text, $extras]) {
            $parts = [(string) $n, kjv_php_string($text)];
            if ($extras !== []) {
                $kv = [];
                foreach ($extras as $k => $v) {
                    if (is_array($v)) {
                        $kv[] = sprintf("'%s' => [%s]", $k, implode(', ', $v));
                    } elseif (is_string($v)) {
                        $kv[] = sprintf("'%s' => %s", $k, kjv_php_string($v));
                    } else {
                        $kv[] = sprintf("'%s' => %d", $k, $v);
                    }
                }
                $parts[] = '[' . implode(', ', $kv) . ']';
            }
            $lines[] = '        [' . implode(', ', $parts) . '],';
        }
        $lines[] = '    ],';
    }
    $lines[] = '];';
    $body = implode("\n", $lines) . "\n";
    file_put_contents($file, $body);
    $manifest[] = sprintf('%s  %02d.php', hash('sha256', $body), $b['id']);
}

// Verse counts per chapter: reading plans balance days by verses, and the
// reference picker needs them without loading a whole book.
$countLines = [
    '<?php',
    '// /includes/bible/kjv/counts.php',
    '//',
    '// Verses per chapter in the KJV, [book => [chapter => verses]].',
    '// GENERATED by bin/build_bible_kjv.php — never edit by hand.',
    '',
    'return [',
];
foreach (bible_books() as $b) {
    $counts = [];
    foreach ($data[$b['id']] as $c => $verses) {
        $counts[] = count($verses);
    }
    $countLines[] = sprintf('    %d => [%s],', $b['id'], implode(', ', array_map(
        static fn(int $i, int $n): string => ($i + 1) . ' => ' . $n,
        array_keys($counts),
        $counts
    )));
}
$countLines[] = '];';
$countsBody = implode("\n", $countLines) . "\n";
file_put_contents($outDir . '/counts.php', $countsBody);
$manifest[] = sprintf('%s  counts.php', hash('sha256', $countsBody));

file_put_contents(
    $outDir . '/MANIFEST',
    "# KJV data files generated by bin/build_bible_kjv.php\n"
    . "# source sha256 {$sourceHash}  eng-kjv.osis.xml (github.com/seven1m/open-bibles)\n"
    . implode("\n", $manifest) . "\n"
);

printf("Wrote 66 books, 1,189 chapters, %s verses to includes/bible/kjv/\n", number_format($verseTotal));
