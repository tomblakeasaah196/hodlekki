<?php
// /tests/hymnal/solfa_test.php
//
// The sol-fa engine. Pure arithmetic, so everything here is checkable against
// music theory by hand — which is the point: a chorister rehearsing from wrong
// sol-fa is worse served than one with none.

section('letters and accidentals');
eq('middle C is C', 0, hymnal_pitch_letter(0));
eq('the D above it is D', 1, hymnal_pitch_letter(1));
eq('the B below it is B', 6, hymnal_pitch_letter(-1));
eq('an octave up is C again', 0, hymnal_pitch_letter(7));
eq('an octave down is C again', 0, hymnal_pitch_letter(-7));
eq('C is index 0', 0, hymnal_letter_index('C'));
eq('B is index 6', 6, hymnal_letter_index('B'));
eq('lower case still reads', 2, hymnal_letter_index('e'));
eq('a non-letter is refused', -1, hymnal_letter_index('H'));
eq('a sharp is one semitone up', 1, hymnal_accidental_delta('sharp'));
eq('a flat is one semitone down', -1, hymnal_accidental_delta('flat'));
eq('a natural moves nothing', 0, hymnal_accidental_delta('natural'));
eq('no accidental moves nothing', 0, hymnal_accidental_delta(null));
eq('a double flat is two down', -2, hymnal_accidental_delta('dblflat'));

section('the key signature');
$ebMajor = [
    'root' => 'E',
    'acc'  => 'b',
    'mode' => '',
    'accidentals' => [
        ['acc' => 'flat', 'note' => 'B'],
        ['acc' => 'flat', 'note' => 'e'],   // abcjs varies the case
        ['acc' => 'flat', 'note' => 'A'],
    ],
];
$acc = hymnal_key_accidentals($ebMajor);
eq('B is flattened by the key', -1, $acc[hymnal_letter_index('B')]);
eq('E is flattened by the key', -1, $acc[hymnal_letter_index('E')]);
eq('A is flattened by the key', -1, $acc[hymnal_letter_index('A')]);
ok('D is untouched by the key', !isset($acc[hymnal_letter_index('D')]));
eq('doh is named with its flat', 'E♭', hymnal_solfa_doh_name($ebMajor));
eq('doh is the key note', hymnal_letter_index('E'), hymnal_doh_letter($ebMajor));

section('degrees in a major key');
// E flat major, doh on the E flat below middle C (step -5: E is letter 2, so
// -5 is E flat 3).
$doh = -5;
eq('the key note is doh', 'd', hymnal_solfa_note(-5, null, $doh, $acc));
eq('the second is ray', 'r', hymnal_solfa_note(-4, null, $doh, $acc));
eq('the third is me', 'm', hymnal_solfa_note(-3, null, $doh, $acc));
eq('the fourth is fah', 'f', hymnal_solfa_note(-2, null, $doh, $acc));
eq('the fifth is soh', 's', hymnal_solfa_note(-1, null, $doh, $acc));
eq('the sixth is lah', 'l', hymnal_solfa_note(0, null, $doh, $acc));
eq('the seventh is te', 't', hymnal_solfa_note(1, null, $doh, $acc));
eq('the octave is doh above', "d'", hymnal_solfa_note(2, null, $doh, $acc));
eq('two octaves up carries two marks', "d''", hymnal_solfa_note(9, null, $doh, $acc));
eq('the fifth below is soh under', 's,', hymnal_solfa_note(-8, null, $doh, $acc));

section('chromatic degrees are relative to the key, not to the letter');
// Inside the doh octave (steps -5 to 1): Eb3 F3 G3 Ab3 Bb3 C4 D4.
// The key already flattens A, so a written A natural is the raised fourth,
// which Curwen calls fe.
eq('A natural in E flat is fe', 'fe', hymnal_solfa_note(-2, 'natural', $doh, $acc));
// The key leaves F alone, so a written F sharp is the raised second: re.
eq('F sharp in E flat is re', 're', hymnal_solfa_note(-4, 'sharp', $doh, $acc));
// A written A flat is exactly what the key says, so it stays plain fah.
eq('A flat in E flat is plain fah', 'f', hymnal_solfa_note(-2, 'flat', $doh, $acc));
// The key leaves D alone, so a written D flat is the flattened seventh: ta.
eq('a flattened seventh is ta', 'ta', hymnal_solfa_note(1, 'flat', $doh, $acc));
// The key already flattens E, so a written E natural is doh raised: de.
eq('E natural in E flat is de', 'de', hymnal_solfa_note(-5, 'natural', $doh, $acc));
// Sharpening a note the key already flattens moves it two semitones, which the
// engine spells by doubling the vowel. Vanishingly rare in a hymn.
eq('a doubly raised fourth doubles the vowel', 'fee', hymnal_solfa_note(-2, 'sharp', $doh, $acc));

section('a minor key is read la-based');
$aMinor = ['root' => 'A', 'acc' => '', 'mode' => 'm', 'accidentals' => []];
eq('doh is the relative major', hymnal_letter_index('C'), hymnal_doh_letter($aMinor));
eq('the key note itself is lah', 'l', hymnal_solfa_note(5, null, 0, []));
$aMajor = ['root' => 'A', 'acc' => '', 'mode' => '', 'accidentals' => []];
eq('a major key is not shifted', hymnal_letter_index('A'), hymnal_doh_letter($aMajor));
$aMaj2 = ['root' => 'A', 'acc' => '', 'mode' => 'maj', 'accidentals' => []];
eq('"maj" is not mistaken for minor', hymnal_letter_index('A'), hymnal_doh_letter($aMaj2));

section('a whole voice');
$cMajor = ['root' => 'C', 'acc' => '', 'mode' => '', 'accidentals' => []];
$bars = [
    [['p' => 0, 'd' => 0.25], ['p' => 2, 'd' => 0.25], ['p' => 4, 'd' => 0.25]],
    [['r' => 1, 'd' => 0.25], ['p' => 7, 'd' => 0.25]],
    [['p' => 4, 'd' => 0.25, 'ts' => 1], ['p' => 4, 'd' => 0.25, 'te' => 1]],
];
$v = hymnal_solfa_voice($bars, $cMajor);
eq('doh is named', 'C', $v['doh']);
eq('the first bar is doh me soh', ['d', 'm', 's'], $v['bars'][0]);
eq('a rest is blank and the octave is marked', ['', "d'"], $v['bars'][1]);
eq('a tied note carries on as a dash', ['s', '-'], $v['bars'][2]);
// Three notes, then a rest and a note, then a note and its tied continuation:
// five syllables actually sung.
eq('a tied continuation is not counted as a note', 5, $v['notes']);

section('doh is placed inside each voice’s own range');
// A bass line sitting an octave and a half below middle C should read around
// d, not drown in commas.
$bassBars = [[['p' => -14], ['p' => -12], ['p' => -10], ['p' => -7]]];
$bass = hymnal_solfa_voice($bassBars, $cMajor);
$flat = $bass['bars'][0];
$commas = 0;
foreach ($flat as $syl) {
    $commas = max($commas, substr_count($syl, ','));
}
ok('the bass is not buried in octave marks', $commas <= 1, implode(' ', $flat));
eq('an empty voice does not explode', [], hymnal_solfa_voice([], $cMajor)['bars']);

section('transposing does not change a syllable');
// This is the whole reason sol-fa is generated once and stored: movable doh is
// invariant under transposition, so the reader can move a hymn to a lower key
// for the congregation and the choir's sol-fa still reads true.
$inC = hymnal_solfa_voice([[['p' => 0], ['p' => 2], ['p' => 4]]], $cMajor);
$gMajor = ['root' => 'G', 'acc' => '', 'mode' => '', 'accidentals' => [['acc' => 'sharp', 'note' => 'F']]];
$inG = hymnal_solfa_voice([[['p' => 4], ['p' => 6], ['p' => 8]]], $gMajor);
eq('the same tune in another key reads the same', $inC['bars'][0], $inG['bars'][0]);
ok('only the doh heading changes', $inC['doh'] !== $inG['doh'], $inC['doh'] . ' vs ' . $inG['doh']);
