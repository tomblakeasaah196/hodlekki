<?php
// /includes/hymnal/solfa.php
//
// Tonic sol-fa, generated from a score.
//
// Our choir reads sol-fa (d r m f s l t), the pianist reads the staff, and the
// Pastor asked for one hymnary that serves both. Rather than ask anyone to type
// sol-fa by hand for hundreds of hymns, we derive it from the same notes the
// reader draws, so the two can never disagree.
//
// It works because abcjs reports a pitch as a DIATONIC step (0 = middle C,
// 1 = D4, -1 = B3), and sol-fa is a diatonic system. No chromatic conversion
// happens anywhere here: a degree is a subtraction, and that is the whole
// method. Everything in this file is pure arithmetic over those steps, so
// tests/hymnal/run.php can pin it without a database, a browser or a score.
//
// We use MOVABLE DOH ("doh is the key note"), which is what makes this worth
// generating once: transposing a hymn does not change a single syllable, so the
// reader can move "Amazing Grace" from F to D for a lower congregation and the
// sol-fa under the staff still reads true.
//
// Conventions, which modules/hymnal/how_to_use.md states for the choir:
//   - Plain degrees are the single letters d r m f s l t.
//   - Octaves are marked as in print: d' above the doh octave, d, below it.
//     ONE doh octave serves the whole song, taken from the melody, so the four
//     parts line up vertically the way a printed sol-fa hymn does: the melody
//     reads unmarked and the lower parts carry commas. Giving each voice its
//     own doh would read more tidily per line but would make d mean a
//     different pitch in the soprano than in the bass, which is no use to a
//     choir reading the harmony together.
//   - Sharpened degrees end in -e (fe, se, le), flattened in -a (ta, ma, ra),
//     after Curwen. Because plain degrees are always single letters here, "te"
//     (sharpened t) can never be mistaken for plain t.
//   - A rest is a blank, and a tied note continues as a dash, as sol-fa is
//     written on paper.

/** The seven degrees, doh first. */
const HYMNAL_SOLFA_DEGREES = ['d', 'r', 'm', 'f', 's', 'l', 't'];

/** Sharpened and flattened degrees (Curwen). Indexes match the degrees above. */
const HYMNAL_SOLFA_SHARP = ['de', 're', 'me', 'fe', 'se', 'le', 'te'];
const HYMNAL_SOLFA_FLAT  = ['da', 'ra', 'ma', 'fa', 'sa', 'la', 'ta'];

/** A rest is blank on the page; a tied note carries on as a dash. */
const HYMNAL_SOLFA_REST = '';
const HYMNAL_SOLFA_TIE  = '-';

/** Letter names in diatonic order from C, which is how abcjs counts steps. */
const HYMNAL_LETTERS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];

/**
 * Which letter an abcjs diatonic step lands on. 0 = C, 1 = D … 6 = B, for any
 * step above or below middle C.
 */
function hymnal_pitch_letter(int $pitch): int
{
    return (($pitch % 7) + 7) % 7;
}

/** 'C' => 0 … 'B' => 6; -1 for anything that is not a letter name. */
function hymnal_letter_index(string $letter): int
{
    $i = array_search(strtoupper(substr(trim($letter), 0, 1)), HYMNAL_LETTERS, true);

    return $i === false ? -1 : (int) $i;
}

/** How many semitones an accidental moves a note off its natural pitch. */
function hymnal_accidental_delta(?string $accidental): int
{
    return match ($accidental) {
        'sharp'    => 1,
        'flat'     => -1,
        'dblsharp' => 2,
        'dblflat'  => -2,
        default    => 0,   // 'natural', '' and null all mean the natural note
    };
}

/**
 * The key signature as letter index => semitone offset, so a note with no
 * written accidental can be compared against what the key already does to it.
 *
 * abcjs hands us the signature as a list like
 * [{acc:'flat', note:'B'}, {acc:'flat', note:'e'}] — the case of `note` varies
 * with how the key was written, so it is normalised here.
 *
 * @param array $keySig abcjs getKeySignature() output
 * @return array<int,int>
 */
function hymnal_key_accidentals(array $keySig): array
{
    $out = [];
    foreach ($keySig['accidentals'] ?? [] as $a) {
        $letter = hymnal_letter_index((string) ($a['note'] ?? ''));
        if ($letter >= 0) {
            $out[$letter] = hymnal_accidental_delta($a['acc'] ?? null);
        }
    }

    return $out;
}

/**
 * Which letter is doh, 0 = C … 6 = B.
 *
 * In a major key doh is the key note. In a minor key we use la-based minor, the
 * reading our choir was taught: the key note is lah, so doh sits five degrees
 * below it — A minor is read in the sol-fa of C major. Every hymn in the
 * generated corpus is major (see corpus/meta.php), so the minor branch exists
 * for songs the Studio adds later.
 */
function hymnal_doh_letter(array $keySig): int
{
    $root = hymnal_letter_index((string) ($keySig['root'] ?? 'C'));
    if ($root < 0) {
        $root = 0;
    }
    $mode = strtolower((string) ($keySig['mode'] ?? ''));
    $isMinor = $mode !== '' && (str_starts_with($mode, 'm') && !str_starts_with($mode, 'maj'));

    return $isMinor ? (($root - 5) % 7 + 7) % 7 : $root;
}

/**
 * Every pitch in a voice, in order, ignoring rests. Used to place the doh
 * octave inside the voice's own range.
 *
 * @param array $bars list of bars, each a list of note arrays
 * @return list<int>
 */
function hymnal_solfa_pitches(array $bars): array
{
    $out = [];
    foreach ($bars as $bar) {
        foreach ($bar as $note) {
            if (!empty($note['r'])) {
                continue;
            }
            if (isset($note['p'])) {
                $out[] = (int) $note['p'];
            }
        }
    }

    return $out;
}

/**
 * The absolute diatonic step that doh sits on for THIS voice.
 *
 * Sol-fa marks octaves relative to a doh, and which doh you pick decides
 * whether a bass line reads as "d, m, s," or "d,,, m,,, s,,,". We pick the
 * candidate doh that leaves the most notes unmarked, which is what a person
 * writing the part out by hand would do.
 */
function hymnal_solfa_doh_step(array $bars, array $keySig): int
{
    $pitches = hymnal_solfa_pitches($bars);
    $dohLetter = hymnal_doh_letter($keySig);
    if ($pitches === []) {
        return $dohLetter;
    }
    sort($pitches);
    $median = $pitches[intdiv(count($pitches), 2)];

    // The doh at or just below the median, then the octaves either side of it.
    $base = $median - ((hymnal_pitch_letter($median) - $dohLetter + 7) % 7);

    $best = $base;
    $bestScore = -1;
    foreach ([$base - 7, $base, $base + 7] as $candidate) {
        $score = 0;
        foreach ($pitches as $p) {
            if ($p - $candidate >= 0 && $p - $candidate < 7) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * One note as a sol-fa syllable.
 *
 * @param int         $pitch      abcjs diatonic step
 * @param string|null $accidental accidental written on the note, if any
 * @param int         $dohStep    absolute step doh sits on (hymnal_solfa_doh_step)
 * @param array<int,int> $keyAcc  hymnal_key_accidentals()
 */
function hymnal_solfa_note(int $pitch, ?string $accidental, int $dohStep, array $keyAcc): string
{
    $rel    = $pitch - $dohStep;
    $degree = (($rel % 7) + 7) % 7;
    $band   = intdiv($rel - $degree, 7);

    // A note is chromatic only relative to its key: in Eb major a written Eb
    // is plain doh, while a written E natural is doh sharpened.
    $letter   = hymnal_pitch_letter($pitch);
    $fromKey  = $keyAcc[$letter] ?? 0;
    $onNote   = $accidental === null || $accidental === ''
        ? $fromKey
        : hymnal_accidental_delta($accidental);
    $alter    = $onNote - $fromKey;

    if ($alter > 0) {
        $syllable = HYMNAL_SOLFA_SHARP[$degree];
        if ($alter > 1) {
            $syllable .= str_repeat('e', $alter - 1);
        }
    } elseif ($alter < 0) {
        $syllable = HYMNAL_SOLFA_FLAT[$degree];
        if ($alter < -1) {
            $syllable .= str_repeat('a', -$alter - 1);
        }
    } else {
        $syllable = HYMNAL_SOLFA_DEGREES[$degree];
    }

    if ($band > 0) {
        $syllable .= str_repeat("'", $band);
    } elseif ($band < 0) {
        $syllable .= str_repeat(',', -$band);
    }

    return $syllable;
}

/**
 * A whole voice as sol-fa, bar by bar, ready to print as a sol-fa part.
 *
 * @param array    $bars    bars of notes from bin/hymnal_parse_abc.mjs
 * @param array    $keySig  abcjs getKeySignature() output
 * @param int|null $dohStep the song's shared doh (hymnal_solfa_song()); when
 *                          null, this voice places its own, which is right
 *                          only for a single-voice score
 * @return array{doh:string, bars:list<list<string>>, notes:int}
 */
function hymnal_solfa_voice(array $bars, array $keySig, ?int $dohStep = null): array
{
    $dohStep ??= hymnal_solfa_doh_step($bars, $keySig);
    $keyAcc  = hymnal_key_accidentals($keySig);

    $out = [];
    $notes = 0;
    foreach ($bars as $bar) {
        $line = [];
        foreach ($bar as $note) {
            if (!empty($note['r'])) {
                $line[] = HYMNAL_SOLFA_REST;
                continue;
            }
            if (!isset($note['p'])) {
                continue;
            }
            // A tie's second note is the first one still sounding, so it is
            // written as a continuation rather than sung again.
            if (!empty($note['te']) && empty($note['ts'])) {
                $line[] = HYMNAL_SOLFA_TIE;
                continue;
            }
            $line[] = hymnal_solfa_note(
                (int) $note['p'],
                isset($note['a']) ? (string) $note['a'] : null,
                $dohStep,
                $keyAcc
            );
            $notes++;
        }
        if ($line !== []) {
            $out[] = $line;
        }
    }

    return [
        'doh'   => hymnal_solfa_doh_name($keySig),
        'bars'  => $out,
        'notes' => $notes,
    ];
}

/**
 * A whole song's voices as sol-fa, sharing one doh octave.
 *
 * The doh is placed from the FIRST voice — the melody — because that is the
 * line most people read, and leaving it unmarked is what makes a sol-fa part
 * legible. The lower parts then carry commas, exactly as they do in a printed
 * sol-fa hymn, and a syllable means the same pitch in every part.
 *
 * @param list<array{id?:string, name?:string, clef?:string, bars:array}> $voices
 * @param array $keySig abcjs getKeySignature() output
 * @return array{doh:string, doh_step:int, voices:list<array>}
 */
function hymnal_solfa_song(array $voices, array $keySig): array
{
    $melody = $voices[0]['bars'] ?? [];
    $dohStep = hymnal_solfa_doh_step($melody, $keySig);
    $doh = hymnal_solfa_doh_name($keySig);

    $out = [];
    foreach ($voices as $i => $v) {
        $solfa = hymnal_solfa_voice($v['bars'] ?? [], $keySig, $dohStep);
        $out[] = [
            'id'    => (string) ($v['id'] ?? ('V' . ($i + 1))),
            'name'  => (string) ($v['name'] ?? ('Part ' . ($i + 1))),
            'clef'  => (string) ($v['clef'] ?? ''),
            'doh'   => $doh,
            'solfa' => $solfa['bars'],
            'notes' => $solfa['notes'],
        ];
    }

    return ['doh' => $doh, 'doh_step' => $dohStep, 'voices' => $out];
}

/**
 * The letter doh stands on, for the "doh = Eb" line a sol-fa part is headed
 * with. That heading is the only thing that changes when a hymn is transposed.
 */
function hymnal_solfa_doh_name(array $keySig): string
{
    $letter = HYMNAL_LETTERS[hymnal_doh_letter($keySig)] ?? 'C';
    $acc    = (string) ($keySig['acc'] ?? '');
    $mode   = strtolower((string) ($keySig['mode'] ?? ''));
    $isMinor = $mode !== '' && str_starts_with($mode, 'm') && !str_starts_with($mode, 'maj');

    // In a minor key doh is the relative major, whose own accidental we do not
    // get handed; the letter alone is the honest answer there.
    if ($isMinor) {
        return $letter;
    }

    return $letter . ($acc === 'b' ? '♭' : ($acc === '#' ? '♯' : ''));
}
