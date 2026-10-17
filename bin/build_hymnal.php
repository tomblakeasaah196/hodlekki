<?php
// /bin/build_hymnal.php
//
// Stage two of the Hymnary corpus build. Turns the public-domain sources into
// the generated corpus the module reads at runtime: includes/hymnal/corpus/.
//
// Run it after bin/hymnal_parse_abc.mjs. Neither stage ever runs in
// production — the corpus is generated here and committed, exactly as
// includes/bible/kjv/ is, because the cPanel host has no node and because
// pinned bytes are the only way to be sure the words of a hymn have not
// drifted. MANIFEST holds a SHA-256 per file and tests/hymnal/run.php fails on
// any difference.
//
// THE RULE THIS SCRIPT EXISTS TO ENFORCE: no hymn text, tune or harmony is
// ever typed into this repository by hand, and nothing enters the corpus whose
// words AND tune AND arrangement are not each, separately, public domain. A
// hymn being old is not enough — a 1950s four-part setting of an 1840s hymn is
// still somebody's copyright, and that is the mistake this gate is here to
// stop. Songs under a church copyright licence belong in the Studio, where
// each one carries its own licence record, never in this corpus.
//
// Usage:
//   node bin/hymnal_parse_abc.mjs <abc-dir> <source>/parsed
//   php  bin/build_hymnal.php --source=<source> [--verbose]
//
// <source> holds:
//   catalog.json    the WorshipCommons catalogue export
//   songjson/<id>.json   per-song rights, one file per song
//   parsed/<id>.json     output of stage one
//   parsed/_excluded.json
//
// See modules/hymnal/how_to_use.md ("Where the hymns come from") and AGENTS.md.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/build_hymnal.php is a command line tool.\n");
}

require_once __DIR__ . '/../includes/hymnal/solfa.php';
require_once __DIR__ . '/../includes/hymnal/collections.php';

const CORPUS_DIR     = __DIR__ . '/../includes/hymnal/corpus';
const SHARD_SIZE     = 50;
// The corpus owns numbers 1..1999; the Studio allocates from 2000 up, so a
// rebuild that adds hymns can never collide with a number the music team has
// already given out and printed on a service sheet.
const CORPUS_CEILING = 1999;

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opts = ['source' => '', 'verbose' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--source=')) {
        $opts['source'] = substr($arg, 9);
    } elseif ($arg === '--verbose') {
        $opts['verbose'] = true;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}
if ($opts['source'] === '') {
    fwrite(STDERR, "usage: php bin/build_hymnal.php --source=<dir> [--verbose]\n");
    exit(2);
}
$source = rtrim($opts['source'], '/');
foreach (['catalog.json', 'songjson', 'parsed'] as $need) {
    if (!file_exists("$source/$need")) {
        fwrite(STDERR, "Source is missing $need: $source/$need\n");
        exit(2);
    }
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function readJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($path), true);

    return is_array($d) ? $d : null;
}

// ---------------------------------------------------------------------------
// Rights
// ---------------------------------------------------------------------------

/**
 * Is every part of this song public domain, and can we show our working?
 *
 * The three parts are judged separately and all three must clear. `arrangement`
 * is allowed to be absent, which means the source is publishing the tune as
 * written with no separate harmonisation claim; it is NOT allowed to be
 * something other than PD.
 *
 * @return array{ok:bool, reason:string, rights:array}
 */
function rightsGate(array $row, ?array $song): array
{
    if ($song === null || !isset($song['rights']) || !is_array($song['rights'])) {
        return ['ok' => false, 'reason' => 'no per-part rights record', 'rights' => []];
    }
    $rights = $song['rights'];
    $out = [];
    foreach (['text', 'tune'] as $part) {
        $p = $rights[$part] ?? null;
        if ($p === null) {
            // Every song has a tune and every song has words, so a missing
            // record here is a gap in the evidence, not an absence of claim —
            // unlike `arrangement`, which many hymns genuinely do not have.
            // We do not guess: the hymn is held back, listed in meta.php, and
            // can be added through the Studio with its own licence record.
            return [
                'ok'     => false,
                'reason' => $part . ' rights are not recorded',
                'rights' => [],
            ];
        }
        if (!is_array($p) || ($p['license'] ?? null) !== 'PD') {
            return [
                'ok'     => false,
                'reason' => $part . ' is ' . json_encode(is_array($p) ? ($p['license'] ?? null) : $p),
                'rights' => [],
            ];
        }
        $out[$part] = [
            'license' => 'PD',
            'basis'   => (string) ($p['basis'] ?? ''),
            'source'  => (string) ($p['source'] ?? ''),
        ];
    }
    $arr = $rights['arrangement'] ?? null;
    if ($arr !== null) {
        if (!is_array($arr) || ($arr['license'] ?? null) !== 'PD') {
            return [
                'ok'     => false,
                'reason' => 'arrangement is ' . json_encode(is_array($arr) ? ($arr['license'] ?? null) : $arr),
                'rights' => [],
            ];
        }
        $out['arrangement'] = [
            'license' => 'PD',
            'basis'   => (string) ($arr['basis'] ?? ''),
            'source'  => (string) ($arr['source'] ?? ''),
        ];
    }
    // The catalogue's own rollup must agree. If the two ever disagree, that is
    // a signal to look rather than to pick the friendlier answer.
    if (($row['license'] ?? null) !== 'PD') {
        return [
            'ok'     => false,
            'reason' => 'per-part rights say PD but the catalogue says ' . json_encode($row['license'] ?? null),
            'rights' => [],
        ];
    }

    return ['ok' => true, 'reason' => '', 'rights' => $out];
}

// ---------------------------------------------------------------------------
// Words
// ---------------------------------------------------------------------------

/** Section labels we recognise. Matched whole, so a lyric line that happens to
 *  begin with the word "refrain" is not mistaken for a heading. */
const SECTION_LABEL = '/^(verse|chorus|refrain|pre-?chorus|bridge|tag|ending|intro|interlude|coda|vamp)(?:\s+(\d+))?$/i';

function labelKind(string $word): string
{
    $w = strtolower(str_replace('-', '', $word));

    return match ($w) {
        'refrain', 'chorus' => 'chorus',
        'prechorus'         => 'prechorus',
        default             => $w,
    };
}

/** Strip ChordPro chord brackets, leaving the words. */
function stripChords(string $line): string
{
    return (string) preg_replace('/\[[^\]]*\]/u', '', $line);
}

/**
 * Tidy one sung line.
 *
 * The sources carry a hyphen after punctuation ("full,- He has riches untold")
 * where the score holds a syllable across notes. It belongs to the engraving,
 * not to the sentence, so it comes out of the words a congregation reads.
 */
function cleanLine(string $line): string
{
    $s = stripChords($line);
    $s = (string) preg_replace('/([,.;:!?])\s*-/u', '$1', $s);
    $s = (string) preg_replace('/\s+/u', ' ', $s);

    return trim($s);
}

/**
 * ChordPro into sections.
 *
 * @return list<array{label:string, kind:string, num:?int, lines:list<string>}>
 */
function parseChordPro(string $text): array
{
    $sections = [];
    $verseNo = 0;
    foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $block) {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $block) ?: []),
            static fn(string $l): bool => $l !== ''
        ));
        if ($lines === []) {
            continue;
        }
        $kind = 'verse';
        $num  = null;
        $label = '';
        if (preg_match(SECTION_LABEL, $lines[0], $m)) {
            $kind  = labelKind($m[1]);
            $num   = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $label = $lines[0];
            array_shift($lines);
        }
        // A {c: ...} comment is the source's own note to the musician.
        $lines = array_values(array_filter(
            $lines,
            static fn(string $l): bool => !preg_match('/^\{.*\}$/u', $l)
        ));
        $words = [];
        foreach ($lines as $l) {
            $c = cleanLine($l);
            if ($c !== '') {
                $words[] = $c;
            }
        }
        if ($words === []) {
            continue;
        }
        if ($kind === 'verse') {
            $verseNo++;
            if ($num === null) {
                $num = $verseNo;
            } else {
                $verseNo = $num;
            }
            $label = 'Verse ' . $num;
        } elseif ($label === '') {
            $label = ucfirst($kind);
        } else {
            $label = $kind === 'chorus' ? 'Chorus' : ucwords(strtolower($label));
        }
        $sections[] = ['label' => $label, 'kind' => $kind, 'num' => $num, 'lines' => $words];
    }

    return $sections;
}

/**
 * Collapse a repeated refrain and remember the order it was sung in.
 *
 * A chord chart writes out "Verse 1 / Chorus / Verse 2 / Chorus …" because a
 * musician plays straight down the page. A hymnal prints the refrain once. We
 * keep each distinct section once and record the chart's running order beside
 * it, so the page can read like a hymnal while still knowing the chorus comes
 * round after every verse.
 *
 * @param list<array> $sections
 * @return array{sections:list<array>, order:list<int>}
 */
function dedupeSections(array $sections): array
{
    $seen = [];
    $unique = [];
    $order = [];
    foreach ($sections as $s) {
        $key = $s['kind'] . '|' . implode("\n", $s['lines']);
        if (!array_key_exists($key, $seen)) {
            $seen[$key] = count($unique);
            $unique[] = $s;
        }
        $order[] = $seen[$key];
    }

    return ['sections' => $unique, 'order' => $order];
}

/** Significant words of a passage, for comparing two settings of a hymn. */
function wordSet(string $text): array
{
    $text = (string) preg_replace('/\[[^\]]*\]/u', '', $text);
    preg_match_all("/[a-z']+/u", mb_strtolower($text), $m);
    $out = [];
    foreach ($m[0] as $w) {
        if (mb_strlen($w) > 2) {
            $out[$w] = true;
        }
    }

    return $out;
}

/**
 * How much of the score's text appears in the words we are going to print.
 *
 * This exists because of a trap in the sources: a published score and a
 * published chord chart can carry the SAME TUNE under DIFFERENT WORDS. The
 * chart for "Come, Thou Almighty King" and the Open Hymnal score filed with it
 * are both correct and share the tune ITALIAN HYMN, but the score's words are
 * "God, whose almighty word" — a different hymn. Pairing them would print the
 * wrong words under the notes and have the choir rehearse a text the
 * congregation is not reading.
 *
 * Across the 310 scored hymns this measure has a median of 0.98, sits above
 * 0.43 for every hymn whose score and chart are the same text, and collapses
 * to 0.03-0.21 for the five that are not. The threshold sits in that gap.
 * Below it the hymn keeps its words and loses its score; above it a low score
 * only means the chart has verses the score does not, which is recorded as a
 * gap for the music team rather than treated as an error.
 */
function scoreTextAgreement(array $scoreVerses, string $chartText): float
{
    $score = wordSet(implode(' ', array_map('strval', $scoreVerses)));
    $chart = wordSet($chartText);
    if ($score === [] || $chart === []) {
        return 1.0;   // nothing to disagree about
    }

    return count(array_intersect_key($score, $chart)) / count($score);
}

/** Everything the congregation would sing, as one string. */
function chartText(array $sections): string
{
    $out = [];
    foreach ($sections as $s) {
        foreach ($s['lines'] as $l) {
            $out[] = $l;
        }
    }

    return implode(' ', $out);
}

// A score below this agreement is set to different words; see
// scoreTextAgreement(). Below the second, the chart simply has verses the
// score lacks, which is worth recording but not refusing.
const SCORE_AGREEMENT_MIN  = 0.35;
const SCORE_AGREEMENT_FULL = 0.80;

// ---------------------------------------------------------------------------
// Voices
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

/**
 * The number ledger.
 *
 * A HOD number is permanent and never reused: it gets called out from the
 * pulpit and written on service sheets, so it cannot shift because a rebuild
 * added a hymn that sorts earlier. The ledger is committed, the build only ever
 * appends to it, and a hymn that leaves the corpus keeps its number reserved
 * rather than handing it to someone else.
 *
 * @return array{byId:array<string,int>, next:int}
 */
function readLedger(string $path): array
{
    $byId = [];
    $max = 0;
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line), 3);
            if (count($parts) < 2) {
                continue;
            }
            $n = (int) $parts[0];
            $byId[$parts[1]] = $n;
            $max = max($max, $n);
        }
    }

    return ['byId' => $byId, 'next' => $max + 1];
}

function writeLedger(string $path, array $byId): void
{
    asort($byId);
    $out = "# /includes/hymnal/corpus/NUMBERS\n"
        . "#\n"
        . "# HOD number -> source id. GENERATED by bin/build_hymnal.php,\n"
        . "# append-only, and committed. A number here is permanent: it is read out in\n"
        . "# church. Never renumber, never reuse, never sort this file by hand.\n"
        . "#\n";
    foreach ($byId as $id => $n) {
        $out .= sprintf("%04d %s\n", $n, $id);
    }
    file_put_contents($path, $out);
}

// ---------------------------------------------------------------------------
// Build
// ---------------------------------------------------------------------------

$catalog = readJson("$source/catalog.json");
if ($catalog === null || !isset($catalog['rows'])) {
    fwrite(STDERR, "catalog.json has no rows\n");
    exit(1);
}
$excludedScores = [];
foreach (readJson("$source/parsed/_excluded.json") ?? [] as $e) {
    $excludedScores[(string) ($e['id'] ?? '')] = (string) ($e['reason'] ?? '');
}

$skipped = [];
$songs = [];
$verseGaps = [];
$wrongText = [];
$outOfScope = 0;

foreach ($catalog['rows'] as $row) {
    $id = (string) ($row['id'] ?? '');
    if ($id === '') {
        continue;
    }
    // One language at a time. Yoruba and the rest arrive as Studio versions
    // against an existing song, not as separate rows in this corpus.
    if (($row['language'] ?? '') !== 'English') {
        continue;
    }
    // Anything the source does not itself call public domain is out of scope
    // for this corpus by design — licensed songs belong in the Studio, each
    // with its own licence record. Counted, not listed as a failure.
    if (($row['license'] ?? null) !== 'PD') {
        $outOfScope++;
        continue;
    }

    $songJson = readJson("$source/songjson/$id.json");
    $gate = rightsGate($row, $songJson);
    if (!$gate['ok']) {
        $skipped[] = ['id' => $id, 'title' => (string) ($row['title'] ?? ''), 'reason' => $gate['reason']];
        continue;
    }

    $parsedSections = parseChordPro((string) ($row['chordPro'] ?? ''));
    if ($parsedSections === []) {
        $skipped[] = ['id' => $id, 'title' => (string) ($row['title'] ?? ''), 'reason' => 'no words'];
        continue;
    }
    ['sections' => $sections, 'order' => $singOrder] = dedupeSections($parsedSections);

    $parsed = readJson("$source/parsed/$id.json");
    $score = null;
    $voices = [];
    if ($parsed !== null && !empty($parsed['abc'])) {
        // Stage one already named the voices, using the same extractor the
        // Studio runs in the browser (assets/js/hymnal/abc_voices.js).
        $voiceInput = $parsed['voices'] ?? [];
        if ($voiceInput !== []) {
            $key = $parsed['key'] ?? ['root' => 'C', 'acc' => '', 'mode' => '', 'accidentals' => []];
            // One doh for the whole song, taken from the melody, so the four
            // parts read vertically as a printed sol-fa hymn does.
            $sung = hymnal_solfa_song($voiceInput, $key);
            foreach ($sung['voices'] as $v) {
                $voices[] = [
                    'id'    => $v['id'],
                    'name'  => $v['name'],
                    'clef'  => $v['clef'],
                    'doh'   => $v['doh'],
                    'solfa' => $v['solfa'],
                ];
            }
            $score = [
                'abc' => (string) $parsed['abc'],
                'doh' => $sung['doh'],
            ];
            // Refuse a score that is set to a different text, and record the
            // milder case where the chart simply carries fewer verses.
            $agreement = scoreTextAgreement($parsed['verses'] ?? [], chartText($sections));
            if ($agreement < SCORE_AGREEMENT_MIN) {
                $wrongText[$id] = sprintf(
                    'the published score is set to different words (%.2f agreement)',
                    $agreement
                );
                $score = null;
                $voices = [];
            } elseif ($agreement < SCORE_AGREEMENT_FULL) {
                $verseGaps[] = sprintf(
                    '%s "%s": the score carries words the chart does not (%.2f agreement)',
                    $id,
                    (string) ($row['title'] ?? ''),
                    $agreement
                );
            }
        }
    }

    $themes = array_values(array_filter(array_map('trim', explode(',', (string) ($row['themes'] ?? '')))));
    $firstVerse = null;
    foreach ($sections as $s) {
        if ($s['kind'] === 'verse') {
            $firstVerse = $s;
            break;
        }
    }

    $songs[$id] = [
        'id'          => $id,
        'title'       => trim((string) ($row['title'] ?? '')),
        'first_line'  => $firstVerse['lines'][0] ?? ($sections[0]['lines'][0] ?? ''),
        'writer'      => trim((string) ($row['writer'] ?? '')),
        'attribution' => trim((string) ($row['attribution'] ?? '')),
        'year'        => isset($row['year']) && $row['year'] !== null ? (int) $row['year'] : null,
        'key'         => trim((string) ($row['songKey'] ?? '')),
        'time'        => trim((string) ($row['timeSignature'] ?? '')),
        'bpm'         => isset($row['bpm']) && $row['bpm'] !== null ? (int) $row['bpm'] : null,
        'metre'       => trim((string) ($row['meter'] ?? '')),
        'themes'      => $themes,
        'collections' => hymnal_collections_for_themes($themes),
        'scripture'   => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($songJson['scripture'] ?? ''))
        ))),
        'rights'      => $gate['rights'],
        'source'      => [
            'project' => 'Open Hymnal Project',
            'via'     => 'WorshipCommons',
            'id'      => $id,
            'url'     => 'https://worshipcommons.org/song/' . $id,
        ],
        'sections'    => $sections,
        'sing_order'  => $singOrder,
        'chordpro'    => trim((string) ($row['chordPro'] ?? '')),
        'score'       => $score,
        'voices'      => $voices,
        'no_score'    => $score === null
            ? ($wrongText[$id]
                ?? $excludedScores[$id]
                ?? (empty($row['abcUrl']) ? 'no score published for this hymn' : 'score could not be read'))
            : '',
    ];
}

say(sprintf(
    'Eligible: %d song(s), %d of them with a four-part score.',
    count($songs),
    count(array_filter($songs, static fn(array $s): bool => $s['score'] !== null))
));
say(sprintf(
    'Not taken: %d licensed song(s) out of scope for a public-domain corpus, '
    . '%d public-domain song(s) held back.',
    $outOfScope,
    count($skipped)
));
if ($skipped !== []) {
    say('--- held back (listed in corpus/meta.php) ---');
    foreach ($skipped as $s) {
        say(sprintf('  %s "%s": %s', $s['id'], $s['title'], $s['reason']));
    }
}
if ($wrongText !== []) {
    say(sprintf('--- score rejected, set to different words (%d) ---', count($wrongText)));
    foreach ($wrongText as $wid => $why) {
        say(sprintf('  %s "%s": %s', $wid, $songs[$wid]['title'] ?? '', $why));
    }
}
if ($verseGaps !== []) {
    say(sprintf('--- chart may be missing verses the score has (%d) ---', count($verseGaps)));
    foreach (array_slice($verseGaps, 0, 8) as $m) {
        say('  ' . $m);
    }
}

// Numbering: honour the ledger, then give new hymns the next free numbers in
// title order so that a first build reads like a hymnal index.
if (!is_dir(CORPUS_DIR)) {
    mkdir(CORPUS_DIR, 0o755, true);
}
$ledger = readLedger(CORPUS_DIR . '/NUMBERS');
$unnumbered = array_values(array_filter(
    array_keys($songs),
    static fn(string $id): bool => !isset($ledger['byId'][$id])
));
usort($unnumbered, static function (string $a, string $b) use ($songs): int {
    return strcasecmp($songs[$a]['title'], $songs[$b]['title']) ?: strcmp($a, $b);
});
$next = $ledger['next'];
foreach ($unnumbered as $id) {
    $ledger['byId'][$id] = $next++;
}
if ($next - 1 > CORPUS_CEILING) {
    fwrite(STDERR, sprintf(
        "The corpus has outgrown its reserved block (%d numbers used, ceiling %d).\n"
        . "Raise CORPUS_CEILING here AND the Studio's starting number together, or\n"
        . "Studio songs will collide with corpus numbers.\n",
        $next - 1,
        CORPUS_CEILING
    ));
    exit(1);
}
// Everything the ledger has ever held is written back, including ids no longer
// in the corpus: their numbers stay spoken for so a future rebuild cannot hand
// a retired number to a different hymn.
writeLedger(CORPUS_DIR . '/NUMBERS', $ledger['byId']);
say(sprintf('Numbered: %d new, %d total in the ledger.', count($unnumbered), count($ledger['byId'])));

// Shard and write.
$byNumber = [];
foreach ($songs as $id => $song) {
    $song['n'] = $ledger['byId'][$id];
    $byNumber[$song['n']] = $song;
}
ksort($byNumber);

$written = [];
$index = [];
$shards = [];
foreach ($byNumber as $n => $song) {
    $shards[intdiv($n - 1, SHARD_SIZE) + 1][$n] = $song;
}

function phpFile(string $path, string $header, array $data): void
{
    $body = "<?php\n"
        . '// /' . ltrim(str_replace(dirname(__DIR__) . '/', '', $path), '/') . "\n"
        . "//\n"
        . $header
        . "// GENERATED by bin/build_hymnal.php — never edit by hand.\n"
        . "// MANIFEST pins this file's SHA-256 and tests/hymnal/run.php checks it.\n"
        . "\n"
        . 'return ' . varExport($data, 0) . ";\n";
    file_put_contents($path, $body);
}

/** Compact, stable, readable export. var_export's formatting is not stable
 *  enough across versions to pin with a hash. */
function varExport(mixed $v, int $depth): string
{
    $pad = str_repeat('    ', $depth + 1);
    $padEnd = str_repeat('    ', $depth);
    if (is_array($v)) {
        if ($v === []) {
            return '[]';
        }
        $isList = array_is_list($v);
        // Short lists of scalars read better on one line.
        if ($isList && !array_filter($v, 'is_array')) {
            $parts = array_map(static fn($x): string => varExport($x, 0), $v);
            $oneLine = '[' . implode(', ', $parts) . ']';
            if (strlen($oneLine) <= 96) {
                return $oneLine;
            }
        }
        $out = "[\n";
        foreach ($v as $k => $x) {
            $out .= $pad . ($isList ? '' : varExport($k, 0) . ' => ') . varExport($x, $depth + 1) . ",\n";
        }

        return $out . $padEnd . ']';
    }
    if (is_string($v)) {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null) {
        return 'null';
    }

    return (string) $v;
}

foreach ($shards as $shard => $rows) {
    $name = sprintf('%03d.php', $shard);
    $from = min(array_keys($rows));
    $to   = max(array_keys($rows));
    phpFile(
        CORPUS_DIR . '/' . $name,
        "// Hymns $from-$to of the Household of David library, keyed by HOD number.\n"
        . "// Words, tunes and four-part settings: public domain, every part verified.\n",
        $rows
    );
    $written[] = $name;
}

foreach ($byNumber as $n => $song) {
    $index[] = [
        'n'           => $n,
        'title'       => $song['title'],
        'first_line'  => $song['first_line'],
        'writer'      => $song['writer'],
        'year'        => $song['year'],
        'key'         => $song['key'],
        'metre'       => $song['metre'],
        'themes'      => $song['themes'],
        'collections' => $song['collections'],
        'score'       => $song['score'] !== null,
        'voices'      => count($song['voices']),
        'shard'       => intdiv($n - 1, SHARD_SIZE) + 1,
    ];
}
phpFile(
    CORPUS_DIR . '/index.php',
    "// Every hymn in one light row, so browsing, searching and jumping to a\n"
    . "// number never loads a shard. The words live in the shards.\n",
    $index
);
$written[] = 'index.php';

$collectionCounts = [];
foreach ($index as $r) {
    foreach ($r['collections'] as $c) {
        $collectionCounts[$c] = ($collectionCounts[$c] ?? 0) + 1;
    }
}
ksort($collectionCounts);

phpFile(
    CORPUS_DIR . '/meta.php',
    "// What this corpus is, where it came from, and what it does not contain.\n",
    [
        'built_at'    => gmdate('Y-m-d\TH:i:s\Z'),
        'songs'       => count($index),
        'with_score'  => count(array_filter($index, static fn(array $r): bool => $r['score'])),
        'shard_size'  => SHARD_SIZE,
        'shards'      => count($shards),
        'ceiling'     => CORPUS_CEILING,
        'collections' => $collectionCounts,
        'sources'     => [
            [
                'project' => 'Open Hymnal Project',
                'url'     => 'http://openhymnal.org/',
                'note'    => 'Four-part scores in ABC Plus with every verse. Freely distributable; terms stated per hymn.',
            ],
            [
                'project' => 'WorshipCommons',
                'url'     => 'https://github.com/ChurchApps/WorshipCommonsContent',
                'note'    => 'Catalogue, chord charts and per-part rights records. Song content licensed per song; tools MIT.',
            ],
        ],
        'out_of_scope'     => $outOfScope,
        'held_back'        => $skipped,
        'scores_excluded'  => $excludedScores,
        'scores_wrong_text' => $wrongText,
        'verse_gaps'        => $verseGaps,
    ]
);
$written[] = 'meta.php';

// MANIFEST last, over everything else.
sort($written);
$manifest = "# /includes/hymnal/corpus/MANIFEST\n"
    . "#\n"
    . "# SHA-256 of every generated corpus file. tests/hymnal/run.php fails on any\n"
    . "# difference, so a hymn's words cannot drift and a hand edit cannot hide.\n"
    . "# GENERATED by bin/build_hymnal.php.\n"
    . "#\n";
foreach ($written as $name) {
    $manifest .= hash_file('sha256', CORPUS_DIR . '/' . $name) . '  ' . $name . "\n";
}
file_put_contents(CORPUS_DIR . '/MANIFEST', $manifest);

say(sprintf(
    'Wrote %d file(s) to includes/hymnal/corpus/ (%s), plus MANIFEST and NUMBERS.',
    count($written),
    implode(', ', array_slice($written, 0, 4)) . (count($written) > 4 ? ', …' : '')
));
say(sprintf(
    'Collections: %s',
    implode('  ', array_map(static fn($k, $v): string => "$k=$v", array_keys($collectionCounts), $collectionCounts))
));
