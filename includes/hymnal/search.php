<?php
// /includes/hymnal/search.php
//
// How the Hymnary finds a song.
//
// What a member types is not what is stored. They type "holy holy holy" for
// "Holy, Holy, Holy", "amazin grace" for "Amazing Grace", "heard thy voice" for
// a line in the second verse, and on a phone they leave the dots off Yoruba
// letters. So search works in three steps, all in this file and all in PHP,
// because production is a cPanel host with no search service, and the library
// (a few thousand songs at most) is small enough that a service would cost more
// to run than it saves:
//
//   1. FOLD. Everything — what is stored and what is typed — goes through
//      hymnal_search_fold(): lower case, accents and dots removed, apostrophes
//      dropped, punctuation turned into spaces. This is the ONE normaliser. The
//      generated index and the live query both call it; writing it twice is how
//      the two would drift and a search would quietly stop finding things.
//   2. MEET EACH TYPED WORD. A word is matched exactly; the last word, which may
//      still be being typed, also as a prefix; and a word that is not a word of
//      any hymn at all is matched with one or two letters of slack (a typo).
//      Typo matching is a fallback only, so "love" never turns into "live".
//   3. SCORE. The best place a song matches wins: its number, then its exact
//      title, then its title, its first line, a line you remember from any
//      verse, then the writer and themes. Several words found side by side in
//      one line score higher, which is what remembering a line looks like.
//
// The lyrics of the 511 generated hymns are pre-folded into search_index.php by
// bin/build_hymnal_search.php, and pinned to MANIFEST: if the corpus changes
// without the index being rebuilt, the index is ignored and rebuilt in memory
// (slower, never wrong) and tests/hymnal/search_test.php fails until it is
// regenerated. The music team's songs are folded at search time, because they
// change and there are few.

/** Bump when the folding or the document shape changes. */
const HYMNAL_SEARCH_VERSION = 1;

/** The committed, pre-folded lyrics of the generated hymns. */
const HYMNAL_SEARCH_INDEX_FILE = __DIR__ . '/search_index.php';

/** At most this many words of a query are used; a paragraph is not a search. */
const HYMNAL_SEARCH_MAX_WORDS = 8;

/** Words that carry little: they count for a third of an ordinary word. */
const HYMNAL_SEARCH_STOP = [
    'a', 'an', 'the', 'of', 'to', 'in', 'on', 'and', 'o', 'oh', 'my', 'me', 'i',
    'thy', 'thou', 'thee', 'is', 'it', 'be', 'for', 'with', 'that', 'hymn', 'no',
];

/**
 * The one normaliser. "Ẹ̀ṣẹ̀, Ọlọ́run!" and "ese olorun" fold to the same thing.
 */
function hymnal_search_fold(string $s): string
{
    static $map = null;
    if ($map === null) {
        $groups = [
            'a' => 'àáâãäåāăąǎạảầấẩẫậằắẳẵặ',
            'b' => 'ɓ',
            'c' => 'çćč',
            'd' => 'ďđɗ',
            'e' => 'èéêëēėęěẹẽẻềếểễệɛ',
            'g' => 'ğ',
            'i' => 'ìíîïīįıịĩỉ',
            'k' => 'ƙ',
            'l' => 'ł',
            'n' => 'ńňñṅ',
            'o' => 'òóôõöøōőọỏồốổỗộờớởỡợɔ',
            's' => 'śšşṣ',
            't' => 'ťţ',
            'u' => 'ùúûüūůűųụũủừứửữự',
            'y' => 'ýÿỳỹ',
            'z' => 'žźż',
        ];
        $map = ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe'];
        foreach ($groups as $plain => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $map[$ch] = $plain;
            }
        }
    }

    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer') && is_callable(['Normalizer', 'normalize'])) {
        $d = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($d)) {
            $s = $d;
        }
    }
    // Combining marks (tone marks, dots below) — whether intl split them off or
    // the text arrived already decomposed.
    $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
    $s = strtr($s, $map);
    // "pow'r" is one word, not two: the apostrophe is dropped, not spaced.
    $s = str_replace(["'", "\u{2019}", "\u{2018}", "\u{02BC}", '`'], '', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

    return trim($s);
}

/** @return list<string> */
function hymnal_search_words(string $s): array
{
    $f = hymnal_search_fold($s);

    return $f === '' ? [] : explode(' ', $f);
}

/**
 * Edit distance counting a swapped pair of letters as one slip ("teh" for
 * "the"). Gives up early and returns $max + 1 once the answer must exceed $max.
 */
function hymnal_search_distance(string $a, string $b, int $max): int
{
    $la = strlen($a);
    $lb = strlen($b);
    if (abs($la - $lb) > $max) {
        return $max + 1;
    }
    $prev2 = [];
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i];
        $rowMin = $i;
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $v = min($v, $prev2[$j - 2] + 1);
            }
            $cur[$j] = $v;
            if ($v < $rowMin) {
                $rowMin = $v;
            }
        }
        if ($rowMin > $max) {
            return $max + 1;
        }
        $prev2 = $prev;
        $prev = $cur;
    }

    return $prev[$lb];
}

/* ------------------------------------------------------------ the documents */

/**
 * The lines of a song, in the order a member reads them, each with the section
 * it belongs to. A line repeated (a chorus written out under every verse) is
 * kept once. The generated index and the snippet shown under a result both come
 * from here, so line number N means the same line in both.
 *
 * @param  array $sections [['label'=>…, 'lines'=>[…]], …]
 * @return list<array{text:string, where:string}>
 */
function hymnal_search_flatten(array $sections): array
{
    $out = [];
    $seen = [];
    foreach ($sections as $sec) {
        $where = (string) ($sec['label'] ?? '');
        foreach ((array) ($sec['lines'] ?? []) as $line) {
            $text = trim((string) $line);
            $key = hymnal_search_fold($text);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['text' => $text, 'where' => $where];
        }
    }

    return $out;
}

/**
 * One song as the search sees it: its fields and lines, already folded.
 *
 * @param  array $row   an index row (title, first_line, writer, themes)
 * @param  array $lines hymnal_search_flatten() output
 * @return array{t:string, f:string, w:string, h:string, l:list<string>}
 */
function hymnal_search_doc(array $row, array $lines): array
{
    return [
        't' => hymnal_search_fold((string) ($row['title'] ?? '')),
        'f' => hymnal_search_fold((string) ($row['first_line'] ?? '')),
        'w' => hymnal_search_fold((string) ($row['writer'] ?? '')),
        'h' => hymnal_search_fold(implode(' ', (array) ($row['themes'] ?? []))),
        'l' => array_map(static fn(array $l): string => hymnal_search_fold($l['text']), $lines),
    ];
}

/** Count the words of a doc into $vocab (word => number of songs using it). */
function hymnal_search_vocab_add(array &$vocab, array $doc): void
{
    $seen = [];
    foreach (['t', 'f', 'w', 'h'] as $k) {
        foreach ($doc[$k] === '' ? [] : explode(' ', $doc[$k]) as $w) {
            $seen[$w] = true;
        }
    }
    foreach ($doc['l'] as $line) {
        foreach ($line === '' ? [] : explode(' ', $line) as $w) {
            $seen[$w] = true;
        }
    }
    foreach ($seen as $w => $_) {
        $vocab[$w] = ($vocab[$w] ?? 0) + 1;
    }
}

/**
 * The generated hymns, folded. Built from the shards: this is what
 * bin/build_hymnal_search.php writes to search_index.php.
 *
 * @return array{version:int, manifest:string, docs:array<int,array>, vocab:array<string,int>}
 */
function hymnal_search_build_corpus(): array
{
    $docs = [];
    $vocab = [];
    foreach (hymnal_corpus_index() as $row) {
        $n = (int) $row['n'];
        $song = hymnal_corpus_song($n);
        if ($song === null) {
            continue;
        }
        $lines = hymnal_search_flatten($song['langs']['en']['sections'] ?? []);
        $doc = hymnal_search_doc($row, $lines);
        $docs[$n] = $doc;
        hymnal_search_vocab_add($vocab, $doc);
    }
    ksort($docs);
    ksort($vocab, SORT_STRING);

    return [
        'version' => HYMNAL_SEARCH_VERSION,
        'manifest' => hymnal_search_manifest_hash(),
        'docs' => $docs,
        'vocab' => $vocab,
    ];
}

/**
 * Fingerprint of the generated corpus. Line endings are normalised so a
 * Windows checkout and CI agree on it.
 */
function hymnal_search_manifest_hash(): string
{
    static $hash = null;
    if ($hash === null) {
        $raw = @file_get_contents(hymnal_corpus_dir() . '/MANIFEST');
        $hash = $raw === false ? '' : hash('sha256', str_replace("\r\n", "\n", $raw));
    }

    return $hash;
}

/**
 * The folded generated hymns: the committed index when it is current, otherwise
 * rebuilt in memory so a stale index can slow search down but never skew it.
 */
function hymnal_search_corpus(): array
{
    static $corpus = null;
    if ($corpus !== null) {
        return $corpus;
    }
    if (is_file(HYMNAL_SEARCH_INDEX_FILE)) {
        $d = require HYMNAL_SEARCH_INDEX_FILE;
        if (is_array($d)
            && ($d['version'] ?? 0) === HYMNAL_SEARCH_VERSION
            && ($d['manifest'] ?? '') === hymnal_search_manifest_hash()
            && hymnal_search_manifest_hash() !== '') {
            return $corpus = $d;
        }
    }

    return $corpus = hymnal_search_build_corpus();
}

/**
 * The music team's songs, folded: n => ['doc' => …, 'lines' => original lines].
 * One query for all their lyrics; every language of a song is searched.
 *
 * @param  list<array> $rows Studio index rows
 * @return array<int,array>
 */
function hymnal_search_studio_docs(?PDO $pdo, array $rows): array
{
    if ($pdo === null || $rows === [] || !hymnal_table_exists($pdo, 'hymnal_song_versions')) {
        return [];
    }
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) ($r['id'] ?? 0);
    }
    $ids = array_values(array_filter($ids));
    $sections = [];
    if ($ids !== []) {
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT song_id, sections FROM hymnal_song_versions WHERE song_id IN ($place) ORDER BY is_primary DESC, id ASC");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
                $s = json_decode((string) $v['sections'], true);
                if (is_array($s)) {
                    foreach ($s as $sec) {
                        $sections[(int) $v['song_id']][] = $sec;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Hymnary search studio lyrics: ' . $e->getMessage());
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $lines = hymnal_search_flatten($sections[(int) ($r['id'] ?? 0)] ?? []);
        $out[(int) $r['n']] = ['doc' => hymnal_search_doc($r, $lines), 'lines' => $lines];
    }

    return $out;
}

/* ------------------------------------------------------------------ queries */

/**
 * @return array{raw:string, words:list<string>, norm:string, number:?int}
 */
function hymnal_search_query(string $raw): array
{
    $raw = trim(mb_substr($raw, 0, 160));
    $number = null;
    if (preg_match('/^(?:hymn|no\.?|number|#)?\s*#?\s*(\d{1,4})$/i', $raw, $m)) {
        $number = (int) $m[1];
    }
    $words = array_slice(hymnal_search_words($raw), 0, HYMNAL_SEARCH_MAX_WORDS);

    return ['raw' => $raw, 'words' => $words, 'norm' => implode(' ', $words), 'number' => $number];
}

/**
 * For each typed word, the words of the library it can stand for, with how well
 * (1.0 the word itself, 0.85 a longer word it starts, 0.7 / 0.55 one / two
 * slips away). Slips are only allowed for a word that is not a word of any
 * hymn at all.
 *
 * @param  list<string>        $words
 * @param  array<string|int,int> $vocab
 * @return list<array<string,float>>
 */
function hymnal_search_matches(array $words, array $vocab): array
{
    $out = [];
    $last = count($words) - 1;
    foreach ($words as $i => $tok) {
        $m = [];
        $len = strlen($tok);
        $exact = isset($vocab[$tok]);
        if ($exact) {
            $m[$tok] = 1.0;
        }
        $prefix = $i === $last && $len >= 2;
        $fuzzy = !$exact && $len >= 3 && !ctype_digit($tok);
        if ($prefix || $fuzzy) {
            $max = $len >= 8 ? 2 : 1;
            foreach ($vocab as $w => $_) {
                $w = (string) $w;
                if ($prefix && $w !== $tok && str_starts_with($w, $tok)) {
                    $m[$w] = max($m[$w] ?? 0.0, 0.85);
                    continue;
                }
                if ($fuzzy && abs(strlen($w) - $len) <= $max) {
                    $d = hymnal_search_distance($tok, $w, $max);
                    if ($d <= $max) {
                        $m[$w] = max($m[$w] ?? 0.0, $d === 1 ? 0.7 : 0.55);
                    }
                }
            }
        }
        $out[] = $m;
    }

    return $out;
}

/**
 * Everything the scorer needs about one query, computed once.
 *
 * @return array
 */
function hymnal_search_prepare(string $raw, array $vocab): array
{
    $q = hymnal_search_query($raw);
    $q['match'] = hymnal_search_matches($q['words'], $vocab);
    // Is the last word a whole word of some hymn, or probably still being typed?
    // It decides whether "hol" may match "holy" as a phrase, and how highly.
    $q['exact_last'] = $q['words'] !== [] && isset($vocab[$q['words'][count($q['words']) - 1]]);
    $stop = array_flip(HYMNAL_SEARCH_STOP);
    $tw = [];
    foreach ($q['words'] as $w) {
        $tw[] = isset($stop[$w]) ? 1 / 3 : 1.0;
    }
    // A query made only of small words ("o lord", "to me") still has to count.
    if (count(array_filter($tw, static fn(float $x): bool => $x >= 1.0)) === 0) {
        $tw = array_fill(0, count($tw), 1.0);
    }
    $q['tw'] = $tw;
    $q['tw_total'] = array_sum($tw) ?: 1.0;
    // How much of the query a place must account for: every word of a short
    // query, all but a little of a long one (you remember most of a line).
    $q['min'] = count($q['words']) <= 3 ? 1.0 : 0.7;

    return $q;
}

/**
 * How much of the query these words account for.
 *
 * @param  list<string> $docWords
 * @return array{0:float, 1:float} [strength, coverage], each 0..1
 */
function hymnal_search_cover(array $docWords, array $q): array
{
    $str = 0.0;
    $cov = 0.0;
    foreach ($q['match'] as $i => $m) {
        $best = 0.0;
        foreach ($docWords as $w) {
            if (isset($m[$w]) && $m[$w] > $best) {
                $best = $m[$w];
                if ($best >= 1.0) {
                    break;
                }
            }
        }
        if ($best > 0.0) {
            $cov += $q['tw'][$i];
            $str += $q['tw'][$i] * $best;
        }
    }

    return [$str / $q['tw_total'], $cov / $q['tw_total']];
}

/**
 * Is the whole query in this text as a phrase, starting on a word boundary?
 * 1.0 when it is; 0.72 when it is only there because the last word, which does
 * not stand as a word of any hymn, is a half-typed start of a longer word; 0
 * when it is not there.
 */
function hymnal_search_phrase(string $hay, array $q): float
{
    $qn = $q['norm'];
    if ($qn === '') {
        return 0.0;
    }
    if (str_contains(' ' . $hay . ' ', ' ' . $qn . ' ')) {
        return 1.0;
    }
    if (!$q['exact_last'] && str_contains(' ' . $hay, ' ' . $qn)) {
        return 0.72;
    }

    return 0.0;
}

/** Do the query's words appear side by side, in order, in this line? */
function hymnal_search_adjacent(array $lineWords, array $q): bool
{
    $n = count($q['match']);
    $c = count($lineWords);
    if ($n < 2) {
        return false;
    }
    for ($p = 0; $p + $n <= $c; $p++) {
        $hit = true;
        for ($i = 0; $i < $n; $i++) {
            if (!isset($q['match'][$i][$lineWords[$p + $i]])) {
                $hit = false;
                break;
            }
        }
        if ($hit) {
            return true;
        }
    }

    return false;
}

/**
 * Score one song against a prepared query.
 *
 * @return array{0:float, 1:string, 2:?int} [score, where it matched, line number]
 *         where: title | first_line | lyric | spread | writer | theme
 */
function hymnal_search_score(int $n, array $doc, array $q): array
{
    $best = [0.0, '', null];
    $take = static function (float $s, string $kind, ?int $line = null) use (&$best): void {
        if ($s > $best[0]) {
            $best = [$s, $kind, $line];
        }
    };

    if ($q['number'] !== null && $q['number'] === $n) {
        $take(1000.0, 'number');
    }
    $qn = $q['norm'];
    if ($qn === '') {
        return $best;
    }
    $min = $q['min'];
    $t = $doc['t'];
    $f = $doc['f'];

    // The query as a phrase.
    if ($t === $qn) {
        $take(900.0, 'title');
    }
    $starts = str_starts_with($t, $qn) ? hymnal_search_phrase($t, $q) : 0.0;
    $take(800.0 * $starts, 'title');
    $take(650.0 * hymnal_search_phrase($t, $q), 'title');
    $fStarts = str_starts_with($f, $qn) ? hymnal_search_phrase($f, $q) : 0.0;
    $take(700.0 * $fStarts, 'first_line');
    $take(500.0 * hymnal_search_phrase($f, $q), 'first_line');

    // The query as words, in any order, typos forgiven.
    $tWords = $t === '' ? [] : explode(' ', $t);
    [$st, $cv] = hymnal_search_cover($tWords, $q);
    if ($cv >= $min) {
        $take(550.0 * $st, 'title');
    }
    $fWords = $f === '' ? [] : explode(' ', $f);
    [$st, $cv] = hymnal_search_cover($fWords, $q);
    if ($cv >= $min) {
        $take(420.0 * $st + (hymnal_search_adjacent($fWords, $q) ? 40.0 : 0.0), 'first_line');
    }

    // A line remembered from anywhere in the song. A phrase can run over the
    // end of one printed line into the next, so each line is also tried joined
    // to the one after it.
    $multi = count($q['words']) > 1;
    $all = $multi ? $tWords : [];
    $lines = $doc['l'];
    foreach ($lines as $i => $line) {
        $p = hymnal_search_phrase($line, $q);
        if ($p === 0.0 && $multi && isset($lines[$i + 1])) {
            $p = hymnal_search_phrase($line . ' ' . $lines[$i + 1], $q) * 0.98;
        }
        $take(450.0 * $p, 'lyric', $i);
        $lw = $line === '' ? [] : explode(' ', $line);
        [$st, $cv] = hymnal_search_cover($lw, $q);
        if ($cv >= $min) {
            $take(380.0 * $st + (hymnal_search_adjacent($lw, $q) ? 40.0 : 0.0), 'lyric', $i);
        }
        if ($multi) {
            $all = array_merge($all, $lw);
        }
    }

    // The words spread over the whole song.
    if ($multi && $best[0] < 250.0) {
        [$st, $cv] = hymnal_search_cover($all, $q);
        if ($cv >= $min) {
            $take(250.0 * $st, 'spread');
        }
    }

    // Who wrote it, and what it is about.
    if ($doc['w'] !== '') {
        [$st, $cv] = hymnal_search_cover(explode(' ', $doc['w']), $q);
        if ($cv >= 1.0) {
            $take(300.0 * $st, 'writer');
        }
    }
    if ($doc['h'] !== '') {
        [$st, $cv] = hymnal_search_cover(explode(' ', $doc['h']), $q);
        if ($cv >= 1.0) {
            $take(200.0 * $st, 'theme');
        }
    }

    return $best;
}

/**
 * Rank index rows against a query, best first.
 *
 * @param  list<array> $rows index rows (corpus and Studio)
 * @return list<array{row:array, score:float, kind:string, line:?int}>
 */
function hymnal_search_rank(?PDO $pdo, array $rows, string $query): array
{
    $corpus = hymnal_search_corpus();
    $studioRows = array_values(array_filter(
        $rows,
        static fn(array $r): bool => ($r['origin'] ?? '') === 'studio'
    ));
    $studio = hymnal_search_studio_docs($pdo, $studioRows);

    $vocab = $corpus['vocab'];
    foreach ($studio as $s) {
        hymnal_search_vocab_add($vocab, $s['doc']);
    }
    $q = hymnal_search_prepare($query, $vocab);
    if ($q['words'] === [] && $q['number'] === null) {
        return [];
    }

    $hits = [];
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        $doc = ($r['origin'] ?? '') === 'studio' ? ($studio[$n]['doc'] ?? null) : ($corpus['docs'][$n] ?? null);
        if ($doc === null) {
            $doc = hymnal_search_doc($r, []);
        }
        [$score, $kind, $line] = hymnal_search_score($n, $doc, $q);
        if ($score > 0.0) {
            $hits[] = ['row' => $r, 'score' => $score, 'kind' => $kind, 'line' => $line];
        }
    }
    // A hymn number is a hymn number. When some hymn has it, that hymn is the
    // answer; numerals that happen to sit in somebody's lyrics are not. (If no
    // hymn has the number it falls through to ordinary text, so a year or a
    // title with digits in it still works.)
    if ($q['number'] !== null) {
        $byNumber = array_values(array_filter($hits, static fn(array $h): bool => $h['kind'] === 'number'));
        if ($byNumber !== []) {
            $hits = $byNumber;
        }
    }
    usort($hits, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score']
            ?: strcasecmp((string) $a['row']['title'], (string) $b['row']['title'])
            ?: ((int) $a['row']['n'] <=> (int) $b['row']['n']);
    });

    return $hits;
}

/**
 * The line to show under a result when it was found by a line from inside the
 * song, so the member sees why it matched: ['line' => 'And it told Thy love',
 * 'where' => 'Verse 1'], or null when the title or first line already says it.
 */
function hymnal_search_snippet(?PDO $pdo, array $hit): ?array
{
    if ($hit['kind'] !== 'lyric' || $hit['line'] === null) {
        return null;
    }
    $row = $hit['row'];
    if (($row['origin'] ?? '') === 'studio') {
        $docs = hymnal_search_studio_docs($pdo, [$row]);
        $lines = $docs[(int) $row['n']]['lines'] ?? [];
    } else {
        $song = hymnal_corpus_song((int) $row['n']);
        $lines = $song === null ? [] : hymnal_search_flatten($song['langs']['en']['sections'] ?? []);
    }
    $l = $lines[$hit['line']] ?? null;
    if ($l === null) {
        return null;
    }
    // The first line is already printed under the title.
    if ($hit['line'] === 0 && hymnal_search_fold($l['text']) === hymnal_search_fold((string) ($row['first_line'] ?? ''))) {
        return null;
    }

    return ['line' => $l['text'], 'where' => $l['where']];
}
