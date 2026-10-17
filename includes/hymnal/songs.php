<?php
// /includes/hymnal/songs.php
//
// Songs the music team adds: everything the generated corpus cannot hold.
//
// This is where the modern worship lives. No free corpus carries Nigerian
// gospel, so the songs the congregation actually sings on a Sunday are entered
// here by River of Life and released by a pastor. The same tables carry the
// Yoruba versions of hymns that already exist, against the same tune.
//
// NUMBERING. The corpus owns HOD numbers 1-1999 through its committed ledger.
// Rows here take a number from 2000 up, allocated inside a transaction, and
// uniqueness comes from the unique key — never from check-then-insert, because
// two people adding a song at once would otherwise both read the same maximum.
// A number is never reused, even after a song is retired: it has been read out
// in church and printed on a service sheet.
//
// LICENCE. A song cannot be saved without a licence code, and cannot be
// published without a licence the church can point to. The library deliberately
// mixes public-domain hymns with songs held under the main church's copyright
// licence, so every row has to say which it is. "It was already in the system"
// is not a licence.

/** Studio songs start here. Must stay above HYMNAL_CORPUS_CEILING. */
const HYMNAL_DB_NUMBER_FLOOR = 2000;

/** Licence codes a Studio song may carry, and how they read to a member. */
const HYMNAL_LICENCES = [
    'PD' => [
        'label'  => 'Public domain',
        'detail' => 'Out of copyright. Free to print, project and arrange.',
        'needs'  => '',
    ],
    'CCLI' => [
        'label'  => 'Church copyright licence',
        'detail' => 'Held under the church’s CCLI licence.',
        'needs'  => 'number',
    ],
    'CHURCH' => [
        'label'  => 'Written for this church',
        'detail' => 'Written by a member of Household of David; the church holds it.',
        'needs'  => '',
    ],
    'PERMISSION' => [
        'label'  => 'Written permission',
        'detail' => 'Used by written permission of the copyright holder.',
        'needs'  => 'detail',
    ],
    'CC-BY' => [
        'label'  => 'Creative Commons BY',
        'detail' => 'Free to use and adapt with credit.',
        'needs'  => 'detail',
    ],
    'CC-BY-SA' => [
        'label'  => 'Creative Commons BY-SA',
        'detail' => 'Free to use and adapt with credit, sharing alike.',
        'needs'  => 'detail',
    ],
];

const HYMNAL_LANGUAGES = [
    'en' => 'English',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'ha' => 'Hausa',
    'pcm' => 'Pidgin',
];

function hymnal_songs_count(?PDO $pdo): int
{
    if ($pdo === null || !hymnal_table_exists($pdo, 'hymnal_songs')) {
        return 0;
    }
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM hymnal_songs WHERE status = 'published'");

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Hymnary songs count: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Allocate the next HOD number.
 *
 * Called inside the caller's transaction. Reads the maximum and adds one; the
 * unique key on `number` is what actually guarantees correctness, so a caller
 * that loses the race retries rather than overwriting.
 */
function hymnal_song_next_number(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT MAX(number) FROM hymnal_songs');
    $max = (int) $stmt->fetchColumn();

    return max($max + 1, HYMNAL_DB_NUMBER_FLOOR);
}

/** A URL-safe, unique-ish slug. Uniqueness is enforced by the key, not here. */
function hymnal_slugify(string $title): string
{
    $s = mb_strtolower(trim($title));
    $s = str_replace(['’', "'"], '', $s);
    $s = (string) preg_replace('/[^a-z0-9]+/u', '-', $s);
    $s = trim($s, '-');

    return $s === '' ? 'song' : mb_substr($s, 0, 150);
}

/**
 * Check what the Studio sent before any of it reaches the database.
 *
 * @return array{ok:bool, errors:array<string,string>, clean:array}
 */
function hymnal_song_validate(array $in, bool $forPublish = false): array
{
    $errors = [];
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        $errors['title'] = 'A song needs a title.';
    } elseif (mb_strlen($title) > 255) {
        $errors['title'] = 'That title is too long.';
    }

    $licence = strtoupper(trim((string) ($in['licence_code'] ?? '')));
    if (!isset(HYMNAL_LICENCES[$licence])) {
        $errors['licence_code'] = 'Choose how we are allowed to use this song.';
    } else {
        $needs = HYMNAL_LICENCES[$licence]['needs'];
        if ($needs === 'number' && trim((string) ($in['ccli_number'] ?? '')) === '') {
            $errors['ccli_number'] = 'Enter the CCLI song number so the licence can be checked.';
        }
        if ($needs === 'detail' && trim((string) ($in['licence_detail'] ?? '')) === '') {
            $errors['licence_detail'] = 'Say where the permission comes from.';
        }
    }

    $sections = hymnal_clean_sections($in['sections'] ?? []);
    if ($sections === []) {
        $errors['sections'] = 'A song needs at least one verse.';
    }

    $collections = [];
    foreach ((array) ($in['collections'] ?? []) as $c) {
        $c = (string) $c;
        if (hymnal_collection_exists($c) && !empty(HYMNAL_COLLECTIONS[$c]['studio'])) {
            $collections[$c] = true;
        }
    }
    if ($collections === []) {
        $errors['collections'] = 'Put the song on at least one shelf so people can find it.';
    }

    $lang = (string) ($in['lang'] ?? 'en');
    if (!isset(HYMNAL_LANGUAGES[$lang])) {
        $lang = 'en';
    }

    if ($forPublish && $licence === 'CCLI' && trim((string) ($in['licence_holder'] ?? '')) === '') {
        // Named so that, if the licence is ever queried, it is clear whose it
        // is — this is a branch and the licence is held by the main church.
        $errors['licence_holder'] = 'Name the licence holder before publishing (for example "Household of David, main church").';
    }

    $year = $in['year'] ?? null;
    $year = ($year === null || $year === '') ? null : (int) $year;
    if ($year !== null && ($year < 1 || $year > (int) date('Y') + 1)) {
        $errors['year'] = 'That year does not look right.';
    }

    $bpm = $in['bpm'] ?? null;
    $bpm = ($bpm === null || $bpm === '') ? null : (int) $bpm;
    if ($bpm !== null && ($bpm < 20 || $bpm > 300)) {
        $errors['bpm'] = 'A tempo should be between 20 and 300.';
    }

    $clean = [
        'title'          => $title,
        'alt_titles'     => mb_substr(trim((string) ($in['alt_titles'] ?? '')), 0, 500),
        'writer'         => mb_substr(trim((string) ($in['writer'] ?? '')), 0, 255),
        'composer'       => mb_substr(trim((string) ($in['composer'] ?? '')), 0, 255),
        'arranger'       => mb_substr(trim((string) ($in['arranger'] ?? '')), 0, 255),
        'tune_name'      => mb_substr(trim((string) ($in['tune_name'] ?? '')), 0, 190),
        'year'           => $year,
        'song_key'       => mb_substr(trim((string) ($in['song_key'] ?? '')), 0, 16),
        'time_sig'       => mb_substr(trim((string) ($in['time_sig'] ?? '')), 0, 16),
        'bpm'            => $bpm,
        'metre'          => mb_substr(trim((string) ($in['metre'] ?? '')), 0, 64),
        'themes'         => mb_substr(trim((string) ($in['themes'] ?? '')), 0, 500),
        'scripture'      => mb_substr(trim((string) ($in['scripture'] ?? '')), 0, 500),
        'licence_code'   => isset(HYMNAL_LICENCES[$licence]) ? $licence : 'CCLI',
        'licence_detail' => mb_substr(trim((string) ($in['licence_detail'] ?? '')), 0, 500),
        'licence_holder' => mb_substr(trim((string) ($in['licence_holder'] ?? '')), 0, 190),
        'ccli_number'    => mb_substr(trim((string) ($in['ccli_number'] ?? '')), 0, 32),
        'source_note'    => mb_substr(trim((string) ($in['source_note'] ?? '')), 0, 500),
        'collections'    => array_keys($collections),
        'lang'           => $lang,
        'sections'       => $sections,
        'chordpro'       => mb_substr((string) ($in['chordpro'] ?? ''), 0, 60000),
        'first_line'     => mb_substr($sections[0]['lines'][0] ?? '', 0, 500),
    ];

    return ['ok' => $errors === [], 'errors' => $errors, 'clean' => $clean];
}

/**
 * Normalise the verses the Studio sent.
 *
 * @return list<array{label:string, kind:string, num:?int, lines:list<string>}>
 */
function hymnal_clean_sections(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $verse = 0;
    foreach ($raw as $s) {
        if (!is_array($s)) {
            continue;
        }
        $lines = [];
        foreach ((array) ($s['lines'] ?? []) as $l) {
            $l = trim((string) preg_replace('/\s+/u', ' ', (string) $l));
            if ($l !== '') {
                $lines[] = mb_substr($l, 0, 500);
            }
        }
        if ($lines === []) {
            continue;
        }
        $kind = strtolower(trim((string) ($s['kind'] ?? 'verse')));
        if (!in_array($kind, ['verse', 'chorus', 'prechorus', 'bridge', 'tag', 'ending', 'intro', 'interlude', 'coda', 'vamp'], true)) {
            $kind = 'verse';
        }
        $num = null;
        if ($kind === 'verse') {
            $verse++;
            $num = $verse;
        }
        $label = trim((string) ($s['label'] ?? ''));
        if ($label === '') {
            $label = $kind === 'verse' ? 'Verse ' . $num : ucfirst($kind);
        }
        $out[] = [
            'label' => mb_substr($label, 0, 64),
            'kind'  => $kind,
            'num'   => $num,
            'lines' => array_slice($lines, 0, 40),
        ];
        if (count($out) >= 30) {
            break;
        }
    }

    return $out;
}

/**
 * Create or update a draft.
 *
 * A new song gets its number here and keeps it for good, even while it is still
 * a draft, so the music team can put it on a rehearsal sheet before a pastor
 * has released it.
 *
 * @return array{ok:bool, code?:string, message?:string, errors?:array, id?:int, number?:int}
 */
function hymnal_song_save(PDO $pdo, int $userId, array $in): array
{
    if (!hymnal_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'The Studio is not switched on yet.'];
    }
    $v = hymnal_song_validate($in);
    if (!$v['ok']) {
        return ['ok' => false, 'code' => 'INVALID', 'message' => 'Please check the highlighted fields.', 'errors' => $v['errors']];
    }
    $c = $v['clean'];
    $id = (int) ($in['id'] ?? 0);

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $existing = $pdo->prepare('SELECT id, number, status FROM hymnal_songs WHERE id = ?');
            $existing->execute([$id]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();

                return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'That song is no longer there.'];
            }
            $number = (int) $row['number'];
            $stmt = $pdo->prepare(
                'UPDATE hymnal_songs SET title = ?, alt_titles = ?, first_line = ?, writer = ?, composer = ?,
                        arranger = ?, tune_name = ?, year = ?, song_key = ?, time_sig = ?, bpm = ?, metre = ?,
                        themes = ?, scripture = ?, licence_code = ?, licence_detail = ?, licence_holder = ?,
                        ccli_number = ?, source_note = ?, updated_by = ?, updated_at = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                $c['title'], $c['alt_titles'], $c['first_line'], $c['writer'], $c['composer'],
                $c['arranger'], $c['tune_name'], $c['year'], $c['song_key'], $c['time_sig'], $c['bpm'], $c['metre'],
                $c['themes'], $c['scripture'], $c['licence_code'], $c['licence_detail'], $c['licence_holder'],
                $c['ccli_number'], $c['source_note'], $userId, hymnal_now(), $id,
            ]);
        } else {
            $number = hymnal_song_next_number($pdo);
            $slug = hymnal_slugify($c['title']);
            // The slug is unique-keyed; a collision means two songs share a
            // title, which is normal, so the number disambiguates it.
            $taken = $pdo->prepare('SELECT 1 FROM hymnal_songs WHERE slug = ?');
            $taken->execute([$slug]);
            if ($taken->fetchColumn() !== false) {
                $slug = $slug . '-' . $number;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO hymnal_songs
                    (number, slug, title, alt_titles, first_line, writer, composer, arranger, tune_name, year,
                     song_key, time_sig, bpm, metre, themes, scripture, licence_code, licence_detail,
                     licence_holder, ccli_number, source_note, status, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $number, $slug, $c['title'], $c['alt_titles'], $c['first_line'], $c['writer'], $c['composer'],
                $c['arranger'], $c['tune_name'], $c['year'], $c['song_key'], $c['time_sig'], $c['bpm'], $c['metre'],
                $c['themes'], $c['scripture'], $c['licence_code'], $c['licence_detail'],
                $c['licence_holder'], $c['ccli_number'], $c['source_note'], 'draft', $userId, hymnal_now(),
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        // Words, for the language this edit was for. Other languages are left
        // alone: editing the Yoruba must not silently drop the English.
        $sections = json_encode($c['sections'], JSON_UNESCAPED_UNICODE);
        $order = implode(',', range(0, count($c['sections']) - 1));
        $upd = $pdo->prepare(
            'UPDATE hymnal_song_versions SET title = ?, sections = ?, chordpro = ?, sing_order = ?, updated_at = ?
              WHERE song_id = ? AND lang = ?'
        );
        $upd->execute([$c['title'], $sections, $c['chordpro'], $order, hymnal_now(), $id, $c['lang']]);
        if ($upd->rowCount() === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO hymnal_song_versions
                    (song_id, lang, title, sections, chordpro, sing_order, is_primary, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $id, $c['lang'], $c['title'], $sections, $c['chordpro'], $order,
                $c['lang'] === 'en' ? 1 : 0, $userId, hymnal_now(),
            ]);
        }

        $pdo->prepare('DELETE FROM hymnal_song_collections WHERE song_id = ?')->execute([$id]);
        $addC = $pdo->prepare('INSERT INTO hymnal_song_collections (song_id, collection) VALUES (?, ?)');
        foreach ($c['collections'] as $col) {
            $addC->execute([$id, $col]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Hymnary song save: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'That did not save. Please try again.'];
    }

    hymnal_audit($pdo, $userId, $id > 0 ? 'song_save' : 'song_create', $id, $number, $c['title']);

    return ['ok' => true, 'id' => $id, 'number' => $number];
}

/**
 * Store a score against a song and generate its sol-fa.
 *
 * The Studio parses the ABC in the browser with the very abcjs build the reader
 * draws with, and sends the notes it found. The sol-fa is then computed HERE by
 * hymnal_solfa_voice(), the same tested function the corpus build uses, so a
 * Studio song's sol-fa is produced by the same arithmetic as a hymn's.
 *
 * @param array $voiceData [['id','name','clef','bars'=>[[{p,a,d,ts,te}]]], …]
 * @param array $keySig    abcjs getKeySignature() output
 */
function hymnal_song_score_save(
    PDO $pdo,
    int $userId,
    int $songId,
    string $abc,
    array $voiceData,
    array $keySig,
    string $arrangementLicence = '',
    string $arrangementNote = ''
): array {
    if (!hymnal_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'The Studio is not switched on yet.'];
    }
    $abc = trim($abc);
    if ($abc === '') {
        try {
            $pdo->prepare('DELETE FROM hymnal_song_scores WHERE song_id = ?')->execute([$songId]);
        } catch (Throwable $e) {
            error_log('Hymnary score clear: ' . $e->getMessage());
        }

        return ['ok' => true, 'voices' => 0];
    }
    if (mb_strlen($abc) > 200000) {
        return ['ok' => false, 'code' => 'INVALID', 'message' => 'That score is too large.'];
    }

    $input = [];
    foreach (array_slice($voiceData, 0, 8) as $i => $v) {
        $bars = hymnal_clean_bars($v['bars'] ?? []);
        if ($bars === []) {
            continue;
        }
        $input[] = [
            'id'   => mb_substr((string) ($v['id'] ?? ('V' . ($i + 1))), 0, 8),
            'name' => mb_substr((string) ($v['name'] ?? ('Part ' . ($i + 1))), 0, 32),
            'clef' => mb_substr((string) ($v['clef'] ?? ''), 0, 16),
            'bars' => $bars,
        ];
    }
    if ($input === []) {
        return ['ok' => false, 'code' => 'INVALID', 'message' => 'That score has no notes we could read.'];
    }

    // Generated here, by the same function and the same shared doh the corpus
    // build uses, so a Studio song's sol-fa is produced by identical arithmetic.
    $sung = hymnal_solfa_song($input, $keySig);
    $voices = [];
    foreach ($sung['voices'] as $v) {
        $voices[] = [
            'id'    => $v['id'],
            'name'  => $v['name'],
            'clef'  => $v['clef'],
            'doh'   => $v['doh'],
            'solfa' => $v['solfa'],
        ];
    }

    try {
        $payload = json_encode($voices, JSON_UNESCAPED_UNICODE);
        $doh = $sung['doh'];
        $upd = $pdo->prepare(
            'UPDATE hymnal_song_scores SET abc = ?, doh = ?, voices = ?, arrangement_licence = ?,
                    arrangement_note = ?, updated_at = ?
              WHERE song_id = ?'
        );
        $upd->execute([$abc, $doh, $payload, $arrangementLicence, $arrangementNote, hymnal_now(), $songId]);
        if ($upd->rowCount() === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO hymnal_song_scores
                    (song_id, abc, doh, voices, arrangement_licence, arrangement_note, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$songId, $abc, $doh, $payload, $arrangementLicence, $arrangementNote, $userId, hymnal_now()]);
        }
    } catch (Throwable $e) {
        error_log('Hymnary score save: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'The score did not save.'];
    }

    hymnal_audit($pdo, $userId, 'score_save', $songId, null, count($voices) . ' voice(s)');

    // The generated sol-fa goes back with the answer so the Studio can show
    // what the server made of the notes. Someone pasting a score should see
    // the syllables their choir will read, before anyone rehearses from them.
    return ['ok' => true, 'voices' => count($voices), 'doh' => $doh, 'parts' => $voices];
}

/**
 * Keep only what hymnal_solfa_voice() reads, with sane bounds. The Studio is a
 * trusted surface, but the shape still gets checked rather than trusted.
 */
function hymnal_clean_bars(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $bars = [];
    foreach (array_slice($raw, 0, 400) as $bar) {
        if (!is_array($bar)) {
            continue;
        }
        $notes = [];
        foreach (array_slice($bar, 0, 64) as $n) {
            if (!is_array($n)) {
                continue;
            }
            if (!empty($n['r'])) {
                $notes[] = ['r' => 1];
                continue;
            }
            if (!isset($n['p']) || !is_numeric($n['p'])) {
                continue;
            }
            $note = ['p' => max(-60, min(60, (int) $n['p']))];
            $acc = (string) ($n['a'] ?? '');
            if (in_array($acc, ['sharp', 'flat', 'natural', 'dblsharp', 'dblflat'], true)) {
                $note['a'] = $acc;
            }
            if (!empty($n['ts'])) {
                $note['ts'] = 1;
            }
            if (!empty($n['te'])) {
                $note['te'] = 1;
            }
            $notes[] = $note;
        }
        if ($notes !== []) {
            $bars[] = $notes;
        }
    }

    return $bars;
}

/** Make a draft visible to the congregation. Pastors only (see rights.php). */
function hymnal_song_publish(PDO $pdo, int $userId, int $songId): array
{
    if (!hymnal_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'The Studio is not switched on yet.'];
    }
    try {
        $stmt = $pdo->prepare('SELECT id, number, title, licence_code, licence_holder, ccli_number FROM hymnal_songs WHERE id = ?');
        $stmt->execute([$songId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'That song is no longer there.'];
        }
        $check = hymnal_song_validate([
            'title'          => $row['title'],
            'licence_code'   => $row['licence_code'],
            'licence_holder' => $row['licence_holder'],
            'ccli_number'    => $row['ccli_number'],
            'licence_detail' => 'checked at publish',
            'sections'       => hymnal_song_sections($pdo, $songId),
            'collections'    => hymnal_song_collection_slugs($pdo, $songId),
        ], true);
        if (!$check['ok']) {
            return [
                'ok'      => false,
                'code'    => 'INVALID',
                'message' => 'This song is not ready to publish.',
                'errors'  => $check['errors'],
            ];
        }
        $pdo->prepare('UPDATE hymnal_songs SET status = ?, published_by = ?, published_at = ? WHERE id = ?')
            ->execute(['published', $userId, hymnal_now(), $songId]);
    } catch (Throwable $e) {
        error_log('Hymnary publish: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'That did not publish. Please try again.'];
    }

    hymnal_audit($pdo, $userId, 'song_publish', $songId, (int) $row['number'], (string) $row['title']);

    return ['ok' => true, 'number' => (int) $row['number']];
}

/** Take a song out of the library, keeping its number spoken for. */
function hymnal_song_retire(PDO $pdo, int $userId, int $songId, string $reason): array
{
    if (!hymnal_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'The Studio is not switched on yet.'];
    }
    try {
        $stmt = $pdo->prepare('SELECT number, title FROM hymnal_songs WHERE id = ?');
        $stmt->execute([$songId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'That song is no longer there.'];
        }
        $pdo->prepare('UPDATE hymnal_songs SET status = ?, retired_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute(['retired', mb_substr($reason, 0, 255), $userId, hymnal_now(), $songId]);
    } catch (Throwable $e) {
        error_log('Hymnary retire: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'That did not save.'];
    }

    hymnal_audit($pdo, $userId, 'song_retire', $songId, (int) $row['number'], $reason);

    return ['ok' => true];
}

/** @return list<array> the verses of a song, in the primary language */
function hymnal_song_sections(PDO $pdo, int $songId, string $lang = 'en'): array
{
    try {
        $stmt = $pdo->prepare('SELECT sections FROM hymnal_song_versions WHERE song_id = ? AND lang = ?');
        $stmt->execute([$songId, $lang]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return [];
        }
        $d = json_decode((string) $raw, true);

        return is_array($d) ? $d : [];
    } catch (Throwable $e) {
        error_log('Hymnary sections: ' . $e->getMessage());

        return [];
    }
}

/** @return list<string> */
function hymnal_song_collection_slugs(PDO $pdo, int $songId): array
{
    try {
        $stmt = $pdo->prepare('SELECT collection FROM hymnal_song_collections WHERE song_id = ?');
        $stmt->execute([$songId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('Hymnary collections: ' . $e->getMessage());

        return [];
    }
}

/**
 * A Studio song in the same shape as a corpus hymn, so nothing above
 * library.php can tell them apart.
 *
 * @param bool $includeDrafts the Studio sees drafts; the reader never does
 */
function hymnal_song_db(PDO $pdo, int $number, bool $includeDrafts = false): ?array
{
    if (!hymnal_table_exists($pdo, 'hymnal_songs')) {
        return null;
    }
    try {
        $sql = 'SELECT * FROM hymnal_songs WHERE number = ?';
        if (!$includeDrafts) {
            $sql .= " AND status = 'published'";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return hymnal_song_db_shape($pdo, $row);
    } catch (Throwable $e) {
        error_log('Hymnary song read: ' . $e->getMessage());

        return null;
    }
}

function hymnal_song_db_by_id(PDO $pdo, int $id): ?array
{
    if (!hymnal_table_exists($pdo, 'hymnal_songs')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM hymnal_songs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? hymnal_song_db_shape($pdo, $row) : null;
    } catch (Throwable $e) {
        error_log('Hymnary song read by id: ' . $e->getMessage());

        return null;
    }
}

function hymnal_song_db_shape(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];

    $langs = [];
    try {
        $stmt = $pdo->prepare('SELECT lang, title, sections, chordpro, sing_order, is_primary FROM hymnal_song_versions WHERE song_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
            $sections = json_decode((string) $v['sections'], true);
            $order = $v['sing_order'] === ''
                ? []
                : array_map('intval', explode(',', (string) $v['sing_order']));
            $langs[(string) $v['lang']] = [
                'lang'       => (string) $v['lang'],
                'label'      => HYMNAL_LANGUAGES[(string) $v['lang']] ?? strtoupper((string) $v['lang']),
                'title'      => (string) ($v['title'] ?: $row['title']),
                'sections'   => is_array($sections) ? $sections : [],
                'sing_order' => $order,
                'chordpro'   => (string) $v['chordpro'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Hymnary versions: ' . $e->getMessage());
    }

    $score = null;
    $voices = [];
    try {
        $stmt = $pdo->prepare('SELECT abc, doh, voices, arrangement_licence, arrangement_note FROM hymnal_song_scores WHERE song_id = ?');
        $stmt->execute([$id]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($s && trim((string) $s['abc']) !== '') {
            $v = json_decode((string) $s['voices'], true);
            $voices = is_array($v) ? $v : [];
            $score = [
                'abc'                 => (string) $s['abc'],
                'doh'                 => (string) $s['doh'],
                'voices'              => $voices,
                'arrangement_licence' => (string) $s['arrangement_licence'],
                'arrangement_note'    => (string) $s['arrangement_note'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Hymnary score read: ' . $e->getMessage());
    }

    $code = (string) $row['licence_code'];
    $licence = HYMNAL_LICENCES[$code] ?? ['label' => $code, 'detail' => '', 'needs' => ''];
    $primary = $langs['en'] ?? (reset($langs) ?: null);

    return [
        'n'           => (int) $row['number'],
        'id'          => $id,
        'origin'      => 'studio',
        'status'      => (string) $row['status'],
        'title'       => (string) $row['title'],
        'first_line'  => (string) $row['first_line'],
        'writer'      => (string) $row['writer'],
        'attribution' => (string) $row['writer'],
        'composer'    => (string) $row['composer'],
        'arranger'    => (string) $row['arranger'],
        'tune'        => (string) $row['tune_name'],
        'year'        => $row['year'] === null ? null : (int) $row['year'],
        'key'         => (string) $row['song_key'],
        'time'        => (string) $row['time_sig'],
        'bpm'         => $row['bpm'] === null ? null : (int) $row['bpm'],
        'metre'       => (string) $row['metre'],
        'themes'      => array_values(array_filter(array_map('trim', explode(',', (string) $row['themes'])))),
        'collections' => hymnal_song_collection_slugs($pdo, $id),
        'scripture'   => array_values(array_filter(array_map('trim', explode(',', (string) $row['scripture'])))),
        'licence'     => [
            'code'   => $code,
            'label'  => $licence['label'],
            'detail' => trim((string) $row['licence_detail']) !== '' ? (string) $row['licence_detail'] : $licence['detail'],
            'holder' => (string) $row['licence_holder'],
            'ccli'   => (string) $row['ccli_number'],
            'parts'  => [],
        ],
        'source'      => ['project' => (string) $row['source_note'], 'via' => '', 'id' => '', 'url' => ''],
        'langs'       => $langs,
        'score'       => $score,
        'no_score'    => $score === null ? 'No score has been added for this song yet.' : '',
        'has'         => [
            'score'  => $score !== null,
            'solfa'  => $score !== null && $voices !== [],
            'chords' => trim((string) ($primary['chordpro'] ?? '')) !== '',
            'audio'  => $score !== null,
        ],
    ];
}

/**
 * Light index rows for Studio songs, matching the corpus index shape so the
 * two can be merged and sorted together.
 *
 * @return list<array>
 */
function hymnal_songs_index(?PDO $pdo, bool $includeDrafts = false): array
{
    if ($pdo === null || !hymnal_table_exists($pdo, 'hymnal_songs')) {
        return [];
    }
    try {
        $sql = 'SELECT s.id, s.number, s.title, s.first_line, s.writer, s.year, s.song_key, s.metre, s.themes, s.status
                  FROM hymnal_songs s';
        if (!$includeDrafts) {
            $sql .= " WHERE s.status = 'published'";
        }
        $sql .= ' ORDER BY s.number ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $place = implode(',', array_fill(0, count($ids), '?'));

        $cols = [];
        $stmt = $pdo->prepare("SELECT song_id, collection FROM hymnal_song_collections WHERE song_id IN ($place)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cols[(int) $r['song_id']][] = (string) $r['collection'];
        }

        $scored = [];
        $stmt = $pdo->prepare("SELECT song_id, voices FROM hymnal_song_scores WHERE song_id IN ($place)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $v = json_decode((string) $r['voices'], true);
            $scored[(int) $r['song_id']] = is_array($v) ? count($v) : 0;
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[] = [
                'n'           => (int) $r['number'],
                'id'          => $id,
                'origin'      => 'studio',
                'status'      => (string) $r['status'],
                'title'       => (string) $r['title'],
                'first_line'  => (string) $r['first_line'],
                'writer'      => (string) $r['writer'],
                'year'        => $r['year'] === null ? null : (int) $r['year'],
                'key'         => (string) $r['song_key'],
                'metre'       => (string) $r['metre'],
                'themes'      => array_values(array_filter(array_map('trim', explode(',', (string) $r['themes'])))),
                'collections' => $cols[$id] ?? [],
                'score'       => isset($scored[$id]) && $scored[$id] > 0,
                'voices'      => $scored[$id] ?? 0,
                'shard'       => 0,
            ];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('Hymnary songs index: ' . $e->getMessage());

        return [];
    }
}
