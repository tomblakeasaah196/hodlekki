<?php
// /tests/bible/kjv_data_test.php — the generated KJV data files.
//
// The text is never typed by hand: bin/build_bible_kjv.php writes it from the
// public-domain OSIS file, and MANIFEST pins every file's SHA-256 so an
// accidental edit (or a merge that mangles one) is caught here.

$dir = __DIR__ . '/../../includes/bible/kjv';

section('manifest');
$manifest = [];
foreach (file($dir . '/MANIFEST', FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    [$hash, $name] = preg_split('/\s+/', trim($line), 2);
    $manifest[$name] = $hash;
}
eq('67 files listed (66 books + counts)', 67, count($manifest));
$bad = [];
foreach ($manifest as $name => $hash) {
    if (!is_file("{$dir}/{$name}") || hash_file('sha256', "{$dir}/{$name}") !== $hash) {
        $bad[] = $name;
    }
}
eq('every file matches its SHA-256', [], $bad);

section('shape');
$total = 0;
$counts = bible_kjv_counts();
$problems = [];
foreach (bible_books() as $b) {
    $book = bible_kjv_book($b['id']);
    if (count($book) !== $b['chapters']) {
        $problems[] = $b['name'] . ' chapters';
    }
    foreach ($book as $c => $rows) {
        if (count($rows) !== ($counts[$b['id']][$c] ?? -1)) {
            $problems[] = "{$b['name']} {$c} count";
        }
        foreach ($rows as $i => $row) {
            $total++;
            if ($row[0] !== $i + 1 || !is_string($row[1]) || $row[1] === '' || trim($row[1]) !== $row[1]) {
                $problems[] = "{$b['name']} {$c}:{$row[0]}";
            }
            $len = mb_strlen($row[1], 'UTF-8');
            foreach (['w', 'i'] as $k) {
                $r = $row[2][$k] ?? [];
                for ($j = 0; $j + 1 < count($r); $j += 2) {
                    if ($r[$j] < 0 || $r[$j + 1] > $len || $r[$j] >= $r[$j + 1]) {
                        $problems[] = "{$b['name']} {$c}:{$row[0]} {$k} range";
                    }
                }
            }
        }
    }
}
eq('31,102 verses', 31102, $total);
eq('no malformed verses or ranges', [], array_slice($problems, 0, 5));

section('known verses');
$v = static function (int $b, int $c, int $n): array {
    foreach (bible_kjv_book($b)[$c] as $row) {
        if ($row[0] === $n) {
            return bible_kjv_verse($row);
        }
    }
    return [];
};
$j316 = $v(43, 3, 16);
eq('John 3:16', 'For God so loved the world, that he gave his only begotten Son, that whosoever believeth in him should not perish, but have everlasting life.', $j316['t']);
eq('John 3:16 is red letter, whole verse', [0, mb_strlen($j316['t'])], $j316['w']);
$g12 = $v(1, 1, 2);
eq('Genesis 1:2 supplied "was" in italics', 'was', mb_substr($g12['t'], $g12['i'][0], $g12['i'][1] - $g12['i'][0]));
eq('Psalm 23 title', 'A Psalm of David.', $v(19, 23, 1)['d']);
eq('Psalm 119 acrostic', 'BETH.', $v(19, 119, 9)['d']);
eq('Psalm 23:2 is poetry', 1, $v(19, 23, 2)['q']);
$rev = $v(66, 22, 20);
eq('Revelation 22:20 red letters only on Jesus', 'Surely I come quickly.', mb_substr($rev['t'], $rev['w'][0], $rev['w'][1] - $rev['w'][0]));
eq('Esther 8:9 (longest verse) intact', true, mb_strlen($v(17, 8, 9)['t']) > 400);

section('agrees with the Special Events KJV bundle');
require_once __DIR__ . '/../../includes/special_events/kjv_bundle.php';
$mismatch = [];
foreach (se_kjv_bundle() as $ref => $text) {
    $p = bible_parse_ref($ref);
    if ($p === null) {
        $mismatch[] = "unparsed {$ref}";
        continue;
    }
    $parts = [];
    foreach (bible_kjv_chapter($p['book'], $p['chapter']) as $verse) {
        if ($verse['v'] >= $p['verse'] && $verse['v'] <= $p['verse_end']) {
            $parts[] = $verse['t'];
        }
    }
    if (implode(' ', $parts) !== $text) {
        $mismatch[] = $ref;
    }
}
eq('every bundled verse is word-for-word the same', [], $mismatch);
