<?php
// /includes/bible/study.php
//
// A member's own study layer: highlights, notes, the study journal, tags and
// where they stopped reading. Every function takes the member's user id and
// every query filters on it — nobody ever sees anyone else's notes, not even
// an administrator through this module.

const BIBLE_HL_COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];
const BIBLE_NOTE_MAX = 20000;
const BIBLE_TAGS_PER_NOTE = 12;
const BIBLE_REFS_PER_NOTE = 20;

// ---------------------------------------------------------------------------
// Positions and ranges
// ---------------------------------------------------------------------------

/**
 * (verse, offset) → one sortable integer. Verses are ≤ 176 and offsets far
 * below 10,000, so ordinary integer comparison orders positions.
 */
function bible_pos(int $verse, int $offset): int
{
    return $verse * 10000 + $offset;
}

/** @return array{0:int, 1:int} [verse, offset] */
function bible_pos_split(int $pos): array
{
    return [intdiv($pos, 10000), $pos % 10000];
}

/**
 * Plan the rows to change when a member paints [a, b) with $color, or erases
 * it ($color === null). Pure, so the rules are tested without a database.
 *
 *   * Painting merges with highlights of the SAME colour that overlap or
 *     touch it, so repainting never leaves a patchwork of rows.
 *   * Any other highlight under the new range is trimmed, or split in two
 *     when the new range falls in its middle.
 *   * Erasing trims and splits the same way and adds nothing.
 *
 * @param list<array{id:int, a:int, b:int, color:string}> $existing
 * @return array{delete:list<int>, update:array<int, array{0:int, 1:int}>, insert:list<array{0:int, 1:int, 2:string}>}
 */
function bible_hl_plan(array $existing, int $a, int $b, ?string $color): array
{
    $plan = ['delete' => [], 'update' => [], 'insert' => []];
    if ($b <= $a) {
        return $plan;
    }

    $ua = $a;
    $ub = $b;
    $gone = [];

    if ($color !== null) {
        foreach ($existing as $h) {
            if ($h['color'] === $color && $h['a'] <= $b && $h['b'] >= $a) {
                $ua = min($ua, $h['a']);
                $ub = max($ub, $h['b']);
                $gone[$h['id']] = true;
                $plan['delete'][] = $h['id'];
            }
        }
    }

    foreach ($existing as $h) {
        if (isset($gone[$h['id']])) {
            continue;
        }
        if ($h['b'] <= $ua || $h['a'] >= $ub) {
            continue;
        }
        $pieces = [];
        if ($h['a'] < $ua) {
            $pieces[] = [$h['a'], $ua];
        }
        if ($h['b'] > $ub) {
            $pieces[] = [$ub, $h['b']];
        }
        if ($pieces === []) {
            $plan['delete'][] = $h['id'];
        } else {
            $plan['update'][$h['id']] = $pieces[0];
            if (isset($pieces[1])) {
                $plan['insert'][] = [$pieces[1][0], $pieces[1][1], $h['color']];
            }
        }
    }

    if ($color !== null) {
        $plan['insert'][] = [$ua, $ub, $color];
    }

    return $plan;
}

/**
 * Clamp a selection to the chapter's real text. Returns null when it covers
 * nothing. $texts may be empty (licensed chapter not cached): then offsets
 * are only bounded, not checked against the words.
 *
 * @param array<int, string> $texts
 * @return array{0:int, 1:int, 2:int, 3:int}|null [vs, os, ve, oe]
 */
function bible_clamp_range(array $texts, int $vs, int $os, int $ve, int $oe): ?array
{
    $vs = max(1, min(200, $vs));
    $ve = max(1, min(200, $ve));
    $os = max(0, min(5000, $os));
    $oe = max(0, min(5000, $oe));

    if ($texts !== []) {
        if (!isset($texts[$vs]) || !isset($texts[$ve])) {
            return null;
        }
        $os = min($os, mb_strlen($texts[$vs], 'UTF-8'));
        $oe = min($oe, mb_strlen($texts[$ve], 'UTF-8'));
    }
    if (bible_pos($ve, $oe) <= bible_pos($vs, $os)) {
        return null;
    }

    return [$vs, $os, $ve, $oe];
}

// ---------------------------------------------------------------------------
// Highlights
// ---------------------------------------------------------------------------

/**
 * @return list<array{id:int, vs:int, os:int, ve:int, oe:int, color:string}>
 */
function bible_highlights_for_chapter(PDO $pdo, int $userId, string $translation, int $book, int $chapter): array
{
    $stmt = $pdo->prepare('SELECT id, verse_start, offset_start, verse_end, offset_end, color FROM bible_highlights
        WHERE user_id = ? AND translation = ? AND book = ? AND chapter = ?
        ORDER BY verse_start, offset_start');
    $stmt->execute([$userId, $translation, $book, $chapter]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'    => (int) $r['id'],
            'vs'    => (int) $r['verse_start'],
            'os'    => (int) $r['offset_start'],
            've'    => (int) $r['verse_end'],
            'oe'    => (int) $r['offset_end'],
            'color' => (string) $r['color'],
        ];
    }

    return $out;
}

/**
 * Paint or erase a range for one member, then return the chapter's
 * highlights as they now stand.
 *
 * @param array<int, string> $texts verse => text, for the KJV quote
 */
function bible_highlight_apply(PDO $pdo, int $userId, string $translation, int $book, int $chapter, array $range, ?string $color, array $texts): array
{
    if ($color !== null && !in_array($color, BIBLE_HL_COLORS, true)) {
        $color = 'yellow';
    }
    [$vs, $os, $ve, $oe] = $range;

    $pdo->beginTransaction();
    try {
        $existing = array_map(static fn(array $h): array => [
            'id'    => $h['id'],
            'a'     => bible_pos($h['vs'], $h['os']),
            'b'     => bible_pos($h['ve'], $h['oe']),
            'color' => $h['color'],
        ], bible_highlights_for_chapter($pdo, $userId, $translation, $book, $chapter));

        $plan = bible_hl_plan($existing, bible_pos($vs, $os), bible_pos($ve, $oe), $color);
        $keepQuote = bible_is_local($translation) && $texts !== [];
        $quote = static function (int $a, int $b) use ($texts, $keepQuote): ?string {
            if (!$keepQuote) {
                return null;
            }
            [$qvs, $qos] = bible_pos_split($a);
            [$qve, $qoe] = bible_pos_split($b);
            $q = bible_cut_quote($texts, $qvs, $qos, $qve, $qoe);

            return $q === '' ? null : mb_substr($q, 0, 1000, 'UTF-8');
        };
        $now = bible_now();

        if ($plan['delete'] !== []) {
            $del = $pdo->prepare('DELETE FROM bible_highlights WHERE id = ? AND user_id = ?');
            foreach ($plan['delete'] as $id) {
                $del->execute([$id, $userId]);
            }
        }
        $upd = $pdo->prepare('UPDATE bible_highlights SET verse_start = ?, offset_start = ?, verse_end = ?, offset_end = ?, quote = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        foreach ($plan['update'] as $id => [$a, $b]) {
            [$pvs, $pos] = bible_pos_split($a);
            [$pve, $poe] = bible_pos_split($b);
            $upd->execute([$pvs, $pos, $pve, $poe, $quote($a, $b), $now, $id, $userId]);
        }
        $ins = $pdo->prepare('INSERT INTO bible_highlights (user_id, translation, book, chapter, verse_start, offset_start, verse_end, offset_end, color, quote, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['insert'] as [$a, $b, $c]) {
            [$pvs, $pos] = bible_pos_split($a);
            [$pve, $poe] = bible_pos_split($b);
            $ins->execute([$userId, $translation, $book, $chapter, $pvs, $pos, $pve, $poe, $c, $quote($a, $b), $now, $now]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return bible_highlights_for_chapter($pdo, $userId, $translation, $book, $chapter);
}

/**
 * Every highlight a member has made, newest first, for the Highlights list
 * and the PDF export.
 *
 * @return array{total:int, items:list<array>}
 */
function bible_highlights_list(PDO $pdo, int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
{
    $where = ['user_id = ?'];
    $args  = [$userId];
    if (!empty($filters['color']) && in_array($filters['color'], BIBLE_HL_COLORS, true)) {
        $where[] = 'color = ?';
        $args[]  = $filters['color'];
    }
    if (!empty($filters['book'])) {
        $where[] = 'book = ?';
        $args[]  = (int) $filters['book'];
    }
    $sqlWhere = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM bible_highlights WHERE {$sqlWhere}");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $order  = ($filters['order'] ?? '') === 'canonical'
        ? 'book, chapter, verse_start, offset_start'
        : 'created_at DESC, id DESC';
    $stmt = $pdo->prepare("SELECT id, translation, book, chapter, verse_start, offset_start, verse_end, offset_end, color, quote, created_at
        FROM bible_highlights WHERE {$sqlWhere} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($args);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'id'          => (int) $r['id'],
            'translation' => (string) $r['translation'],
            'book'        => (int) $r['book'],
            'chapter'     => (int) $r['chapter'],
            'vs'          => (int) $r['verse_start'],
            'os'          => (int) $r['offset_start'],
            've'          => (int) $r['verse_end'],
            'oe'          => (int) $r['offset_end'],
            'color'       => (string) $r['color'],
            'quote'       => $r['quote'] !== null ? (string) $r['quote'] : null,
            'ref'         => bible_format_ref((int) $r['book'], (int) $r['chapter'], (int) $r['verse_start'], null, (int) $r['verse_end']),
            'created_at'  => (string) $r['created_at'],
        ];
    }

    return ['total' => $total, 'items' => $items];
}

function bible_highlight_delete(PDO $pdo, int $userId, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM bible_highlights WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Tags
// ---------------------------------------------------------------------------

/**
 * "  Faith & Hope! " → "faith & hope". Letters, digits, spaces, & and - only.
 */
function bible_tag_clean(string $raw): string
{
    $t = mb_strtolower(trim($raw), 'UTF-8');
    $t = ltrim($t, '#');
    $t = preg_replace('/[^\p{L}\p{N}\s&\-]/u', '', $t) ?? '';
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');

    return mb_substr($t, 0, 40, 'UTF-8');
}

/**
 * @param list<string> $names
 * @return list<string>
 */
function bible_tags_clean(array $names): array
{
    $out = [];
    foreach ($names as $n) {
        $c = bible_tag_clean((string) $n);
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }

    return array_slice($out, 0, BIBLE_TAGS_PER_NOTE);
}

function bible_tag_id(PDO $pdo, int $userId, string $name): int
{
    $sel = $pdo->prepare('SELECT id FROM bible_tags WHERE user_id = ? AND name = ?');
    $sel->execute([$userId, $name]);
    $id = $sel->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    try {
        $pdo->prepare('INSERT INTO bible_tags (user_id, name, created_at) VALUES (?, ?, ?)')->execute([$userId, $name, bible_now()]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        $sel->execute([$userId, $name]);
        return (int) $sel->fetchColumn();
    }
}

/**
 * @param list<string> $names already cleaned
 */
function bible_note_set_tags(PDO $pdo, int $userId, int $noteId, array $names): void
{
    $pdo->prepare('DELETE FROM bible_note_tags WHERE note_id = ?')->execute([$noteId]);
    $ins = $pdo->prepare('INSERT INTO bible_note_tags (note_id, tag_id) VALUES (?, ?)');
    foreach ($names as $name) {
        $ins->execute([$noteId, bible_tag_id($pdo, $userId, $name)]);
    }
    bible_tags_prune($pdo, $userId);
}

/** Drop a member's tags that no note uses any more. */
function bible_tags_prune(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM bible_tags WHERE user_id = ? AND id NOT IN (SELECT tag_id FROM bible_note_tags)')
        ->execute([$userId]);
}

/**
 * @return list<array{name:string, count:int}>
 */
function bible_tags_list(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT t.name, COUNT(nt.note_id) AS n FROM bible_tags t
        LEFT JOIN bible_note_tags nt ON nt.tag_id = t.id
        WHERE t.user_id = ? GROUP BY t.id, t.name ORDER BY t.name');
    $stmt->execute([$userId]);

    return array_map(static fn(array $r): array => ['name' => (string) $r['name'], 'count' => (int) $r['n']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------
// Notes and journal
// ---------------------------------------------------------------------------

/**
 * Parse the passages typed for a journal entry ("Romans 8:28; John 3:16-18").
 *
 * @param list<string>|string $raw
 * @return array{refs: list<array>, bad: list<string>}
 */
function bible_parse_ref_list($raw): array
{
    $items = is_array($raw) ? $raw : preg_split('/[;\n]+/', (string) $raw);
    $refs = [];
    $bad = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        $p = bible_parse_ref($item);
        if ($p === null) {
            $bad[] = $item;
            continue;
        }
        $refs[] = $p;
        if (count($refs) >= BIBLE_REFS_PER_NOTE) {
            break;
        }
    }

    return ['refs' => $refs, 'bad' => $bad];
}

/**
 * Create or update a note. $input keys:
 *   kind 'verse'|'journal', title, body, tags (list), and either
 *   anchor [translation, book, chapter, vs, os|null, ve, oe|null] (verse) or
 *   refs (list of strings, journal; verse notes may add extra links too).
 *
 * @return array{ok:bool, id?:int, message?:string}
 */
function bible_note_save(PDO $pdo, int $userId, array $input, array $texts = []): array
{
    $id    = (int) ($input['id'] ?? 0);
    $kind  = ($input['kind'] ?? 'verse') === 'journal' ? 'journal' : 'verse';
    $title = trim(mb_substr((string) ($input['title'] ?? ''), 0, 160, 'UTF-8'));
    $body  = trim(str_replace("\r\n", "\n", (string) ($input['body'] ?? '')));
    if (mb_strlen($body, 'UTF-8') > BIBLE_NOTE_MAX) {
        return ['ok' => false, 'message' => 'That note is too long. Please split it into two.'];
    }
    $tags = bible_tags_clean(is_array($input['tags'] ?? null) ? $input['tags'] : explode(',', (string) ($input['tags'] ?? '')));
    $parsedRefs = bible_parse_ref_list($input['refs'] ?? []);
    if ($parsedRefs['bad'] !== []) {
        return ['ok' => false, 'message' => 'We could not read this reference: “' . $parsedRefs['bad'][0] . '”. Try a form like “John 3:16” or “Romans 8”.'];
    }

    if ($kind === 'journal' && $title === '' && $body === '') {
        return ['ok' => false, 'message' => 'Give your entry a title or write something first.'];
    }
    if ($kind === 'verse' && $body === '') {
        return ['ok' => false, 'message' => 'Write your note first.'];
    }

    $anchor = [null, null, null, null, null, null, null];
    $quote  = null;
    if ($kind === 'verse') {
        $a = $input['anchor'] ?? null;
        if (!is_array($a) || count($a) < 6) {
            if ($id === 0) {
                return ['ok' => false, 'message' => 'Choose the verse this note belongs to.'];
            }
        } else {
            $translation = bible_translation_code((string) $a[0]);
            $book = (int) $a[1];
            $chapter = (int) $a[2];
            if (!bible_chapter_exists($book, $chapter)) {
                return ['ok' => false, 'message' => 'That chapter is not in the Bible.'];
            }
            $hasOffsets = isset($a[4], $a[6]) && $a[4] !== null && $a[6] !== null && $a[4] !== '' && $a[6] !== '';
            $vs = (int) $a[3];
            $ve = (int) $a[5];
            if ($hasOffsets) {
                $clamped = bible_clamp_range($texts, $vs, (int) $a[4], $ve, (int) $a[6]);
                if ($clamped === null) {
                    return ['ok' => false, 'message' => 'Select the words again — that selection has nothing in it.'];
                }
                [$vs, $os, $ve, $oe] = $clamped;
            } else {
                $vs = max(1, $vs);
                $ve = max($vs, $ve);
                $os = null;
                $oe = null;
            }
            $anchor = [$translation, $book, $chapter, $vs, $os, $ve, $oe];
            if (bible_is_local($translation) && $texts !== []) {
                $q = bible_cut_quote($texts, $vs, $os ?? 0, $ve, $oe ?? mb_strlen($texts[$ve] ?? '', 'UTF-8'));
                $quote = $q === '' ? null : mb_substr($q, 0, 1000, 'UTF-8');
            }
        }
    }

    $now = bible_now();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $own = $pdo->prepare('SELECT kind FROM bible_notes WHERE id = ? AND user_id = ?');
            $own->execute([$id, $userId]);
            $existingKind = $own->fetchColumn();
            if ($existingKind === false) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'That note no longer exists.'];
            }
            $kind = (string) $existingKind;
            if ($anchor[1] !== null) {
                $pdo->prepare('UPDATE bible_notes SET title = ?, body = ?, translation = ?, book = ?, chapter = ?, verse_start = ?, offset_start = ?, verse_end = ?, offset_end = ?, quote = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([$title !== '' ? $title : null, $body, $anchor[0], $anchor[1], $anchor[2], $anchor[3], $anchor[4], $anchor[5], $anchor[6], $quote, $now, $id, $userId]);
            } else {
                $pdo->prepare('UPDATE bible_notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([$title !== '' ? $title : null, $body, $now, $id, $userId]);
            }
        } else {
            $pdo->prepare('INSERT INTO bible_notes (user_id, kind, title, body, translation, book, chapter, verse_start, offset_start, verse_end, offset_end, quote, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $kind, $title !== '' ? $title : null, $body, $anchor[0], $anchor[1], $anchor[2], $anchor[3], $anchor[4], $anchor[5], $anchor[6], $quote, $now, $now]);
            $id = (int) $pdo->lastInsertId();
        }

        if (array_key_exists('refs', $input)) {
            $pdo->prepare('DELETE FROM bible_note_refs WHERE note_id = ? AND user_id = ?')->execute([$id, $userId]);
            $ins = $pdo->prepare('INSERT INTO bible_note_refs (note_id, user_id, book, chapter, verse_start, chapter_end, verse_end, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($parsedRefs['refs'] as $i => $r) {
                $ins->execute([$id, $userId, $r['book'], $r['chapter'], $r['verse'], $r['chapter_end'], $r['verse_end'], $i]);
            }
        }
        if (array_key_exists('tags', $input)) {
            bible_note_set_tags($pdo, $userId, $id, $tags);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'id' => $id];
}

function bible_note_delete(PDO $pdo, int $userId, int $id): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM bible_notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $pdo->prepare('DELETE FROM bible_note_refs WHERE note_id = ? AND user_id = ?')->execute([$id, $userId]);
            $pdo->prepare('DELETE FROM bible_note_tags WHERE note_id = ?')->execute([$id]);
            bible_tags_prune($pdo, $userId);
        }
        $pdo->commit();

        return $deleted;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Shape a bible_notes row (plus its tags and refs) for the client.
 */
function bible_note_shape(array $r, array $tags, array $refs): array
{
    $note = [
        'id'         => (int) $r['id'],
        'kind'       => (string) $r['kind'],
        'title'      => $r['title'] !== null ? (string) $r['title'] : '',
        'body'       => (string) ($r['body'] ?? ''),
        'quote'      => $r['quote'] !== null ? (string) $r['quote'] : null,
        'tags'       => $tags,
        'refs'       => $refs,
        'created_at' => (string) $r['created_at'],
        'updated_at' => (string) $r['updated_at'],
        'anchor'     => null,
    ];
    if ($r['book'] !== null) {
        $note['anchor'] = [
            'translation' => (string) $r['translation'],
            'book'        => (int) $r['book'],
            'chapter'     => (int) $r['chapter'],
            'vs'          => (int) $r['verse_start'],
            'os'          => $r['offset_start'] !== null ? (int) $r['offset_start'] : null,
            've'          => (int) $r['verse_end'],
            'oe'          => $r['offset_end'] !== null ? (int) $r['offset_end'] : null,
            'ref'         => bible_format_ref((int) $r['book'], (int) $r['chapter'], (int) $r['verse_start'], null, (int) $r['verse_end']),
        ];
    }

    return $note;
}

/**
 * Tags and linked passages for a set of notes, in two queries.
 *
 * @param list<int> $ids
 * @return array{0: array<int, list<string>>, 1: array<int, list<array>>}
 */
function bible_note_extras(PDO $pdo, int $userId, array $ids): array
{
    $tags = [];
    $refs = [];
    if ($ids === []) {
        return [$tags, $refs];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    $t = $pdo->prepare("SELECT nt.note_id, t.name FROM bible_note_tags nt JOIN bible_tags t ON t.id = nt.tag_id
        WHERE t.user_id = ? AND nt.note_id IN ({$in}) ORDER BY t.name");
    $t->execute(array_merge([$userId], $ids));
    foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tags[(int) $r['note_id']][] = (string) $r['name'];
    }

    $f = $pdo->prepare("SELECT note_id, book, chapter, verse_start, chapter_end, verse_end FROM bible_note_refs
        WHERE user_id = ? AND note_id IN ({$in}) ORDER BY note_id, sort_order");
    $f->execute(array_merge([$userId], $ids));
    foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vs = $r['verse_start'] !== null ? (int) $r['verse_start'] : null;
        $ve = $r['verse_end'] !== null ? (int) $r['verse_end'] : null;
        $refs[(int) $r['note_id']][] = [
            'book'        => (int) $r['book'],
            'chapter'     => (int) $r['chapter'],
            'verse'       => $vs,
            'chapter_end' => (int) $r['chapter_end'],
            'verse_end'   => $ve,
            'ref'         => bible_format_ref((int) $r['book'], (int) $r['chapter'], $vs, (int) $r['chapter_end'], $ve),
        ];
    }

    return [$tags, $refs];
}

function bible_note_get(PDO $pdo, int $userId, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM bible_notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    [$tags, $refs] = bible_note_extras($pdo, $userId, [$id]);

    return bible_note_shape($r, $tags[$id] ?? [], $refs[$id] ?? []);
}

/**
 * The Notes list: search, tag, kind and book filters, newest first.
 *
 * @return array{total:int, items:list<array>}
 */
function bible_notes_list(PDO $pdo, int $userId, array $filters = [], int $limit = 30, int $offset = 0): array
{
    $where = ['n.user_id = ?'];
    $args  = [$userId];

    $kind = (string) ($filters['kind'] ?? '');
    if ($kind === 'verse' || $kind === 'journal') {
        $where[] = 'n.kind = ?';
        $args[]  = $kind;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        // '!' as the LIKE escape: a backslash means different things to
        // MySQL and SQLite string literals, '!' means the same to both.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 80, 'UTF-8')) . '%';
        $where[] = "(n.title LIKE ? ESCAPE '!' OR n.body LIKE ? ESCAPE '!' OR n.quote LIKE ? ESCAPE '!')";
        array_push($args, $like, $like, $like);
    }
    $tag = bible_tag_clean((string) ($filters['tag'] ?? ''));
    if ($tag !== '') {
        $where[] = 'n.id IN (SELECT nt.note_id FROM bible_note_tags nt JOIN bible_tags t ON t.id = nt.tag_id WHERE t.user_id = ? AND t.name = ?)';
        array_push($args, $userId, $tag);
    }
    $book = (int) ($filters['book'] ?? 0);
    if ($book > 0) {
        $where[] = '(n.book = ? OR n.id IN (SELECT r.note_id FROM bible_note_refs r WHERE r.user_id = ? AND r.book = ?))';
        array_push($args, $book, $userId, $book);
    }
    $ids = $filters['ids'] ?? null;
    if (is_array($ids)) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return ['total' => 0, 'items' => []];
        }
        $where[] = 'n.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }
    $sqlWhere = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM bible_notes n WHERE {$sqlWhere}");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $order  = ($filters['order'] ?? '') === 'canonical'
        ? 'CASE WHEN n.book IS NULL THEN 1 ELSE 0 END, n.book, n.chapter, n.verse_start, n.updated_at DESC'
        : 'n.updated_at DESC, n.id DESC';
    $stmt = $pdo->prepare("SELECT n.* FROM bible_notes n WHERE {$sqlWhere} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $idsOut = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    [$tags, $refs] = bible_note_extras($pdo, $userId, $idsOut);

    $items = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $items[] = bible_note_shape($r, $tags[$id] ?? [], $refs[$id] ?? []);
    }

    return ['total' => $total, 'items' => $items];
}

/**
 * Notes to mark inside one chapter: verse notes pinned there (in any
 * translation — a note is about the passage, not the wording) and journal
 * entries that link to verses in it.
 *
 * @return list<array{id:int, kind:string, title:string, snippet:string, translation:?string, vs:int, os:?int, ve:int, oe:?int}>
 */
function bible_notes_for_chapter(PDO $pdo, int $userId, int $book, int $chapter): array
{
    $out = [];

    $stmt = $pdo->prepare('SELECT id, kind, title, body, translation, verse_start, offset_start, verse_end, offset_end FROM bible_notes
        WHERE user_id = ? AND book = ? AND chapter = ? ORDER BY verse_start, offset_start');
    $stmt->execute([$userId, $book, $chapter]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'          => (int) $r['id'],
            'kind'        => (string) $r['kind'],
            'title'       => (string) ($r['title'] ?? ''),
            'snippet'     => bible_snippet((string) ($r['body'] ?? '')),
            'translation' => $r['translation'] !== null ? (string) $r['translation'] : null,
            'vs'          => (int) $r['verse_start'],
            'os'          => $r['offset_start'] !== null ? (int) $r['offset_start'] : null,
            've'          => (int) $r['verse_end'],
            'oe'          => $r['offset_end'] !== null ? (int) $r['offset_end'] : null,
        ];
    }

    $stmt = $pdo->prepare('SELECT r.note_id, r.chapter, r.verse_start, r.chapter_end, r.verse_end, n.title, n.body FROM bible_note_refs r
        JOIN bible_notes n ON n.id = r.note_id AND n.user_id = r.user_id
        WHERE r.user_id = ? AND r.book = ? AND r.chapter <= ? AND r.chapter_end >= ?');
    $stmt->execute([$userId, $book, $chapter, $chapter]);
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['note_id'];
        if (isset($seen[$id])) {
            continue;
        }
        // Mark the first verse of the linked passage that falls in this chapter.
        $vs = (int) $r['chapter'] === $chapter && $r['verse_start'] !== null ? (int) $r['verse_start'] : 1;
        $ve = (int) $r['chapter_end'] === $chapter && $r['verse_end'] !== null ? (int) $r['verse_end'] : $vs;
        $seen[$id] = true;
        $out[] = [
            'id'          => $id,
            'kind'        => 'journal',
            'title'       => (string) ($r['title'] ?? ''),
            'snippet'     => bible_snippet((string) ($r['body'] ?? '')),
            'translation' => null,
            'vs'          => $vs,
            'os'          => null,
            've'          => max($vs, $ve),
            'oe'          => null,
        ];
    }

    return $out;
}

function bible_snippet(string $text, int $len = 140): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    return mb_strlen($t, 'UTF-8') > $len ? rtrim(mb_substr($t, 0, $len - 1, 'UTF-8')) . '…' : $t;
}

// ---------------------------------------------------------------------------
// Reader state and preferences
// ---------------------------------------------------------------------------

const BIBLE_PREF_DEFAULTS = [
    'theme'    => 'paper',    // paper | white | night
    'size'     => 19,         // px
    'font'     => 'serif',    // serif | sans | classic
    'leading'  => 1.8,
    'red'      => true,       // words of Jesus in red
    'numbers'  => true,       // verse numbers
    'headings' => true,       // section headings (licensed translations)
    'layout'   => 'para',     // para | verse
    'voice'    => '',         // speechSynthesis voiceURI
    'rate'     => 1.0,
    'continue' => true,       // voice carries on into the next chapter
];

/**
 * Keep only known preference keys with sane values.
 */
function bible_prefs_clean(array $in): array
{
    $p = BIBLE_PREF_DEFAULTS;
    if (isset($in['theme']) && in_array($in['theme'], ['paper', 'white', 'night'], true)) {
        $p['theme'] = $in['theme'];
    }
    if (isset($in['size'])) {
        $p['size'] = max(14, min(32, (int) $in['size']));
    }
    if (isset($in['font']) && in_array($in['font'], ['serif', 'sans', 'classic'], true)) {
        $p['font'] = $in['font'];
    }
    if (isset($in['leading'])) {
        $p['leading'] = round(max(1.4, min(2.4, (float) $in['leading'])), 2);
    }
    foreach (['red', 'numbers', 'headings', 'continue'] as $k) {
        if (array_key_exists($k, $in)) {
            $p[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
        }
    }
    if (isset($in['layout']) && in_array($in['layout'], ['para', 'verse'], true)) {
        $p['layout'] = $in['layout'];
    }
    if (isset($in['voice'])) {
        $p['voice'] = mb_substr(preg_replace('/[^\p{L}\p{N}\s\-_.:()\/#@+]/u', '', (string) $in['voice']) ?? '', 0, 200, 'UTF-8');
    }
    if (isset($in['rate'])) {
        $p['rate'] = round(max(0.5, min(2.0, (float) $in['rate'])), 2);
    }

    return $p;
}

/**
 * @return array{translation:string, book:int, chapter:int, verse:int, prefs:array}
 */
function bible_state_get(?PDO $pdo, int $userId): array
{
    $state = ['translation' => 'KJV', 'book' => 43, 'chapter' => 1, 'verse' => 1, 'prefs' => BIBLE_PREF_DEFAULTS, 'saved' => false];
    if ($pdo === null || !bible_table_exists($pdo, 'bible_user_state')) {
        return $state;
    }
    try {
        $stmt = $pdo->prepare('SELECT translation, book, chapter, verse, prefs FROM bible_user_state WHERE user_id = ?');
        $stmt->execute([$userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $book = (int) $r['book'];
            $chapter = (int) $r['chapter'];
            if (bible_chapter_exists($book, $chapter)) {
                $state['book'] = $book;
                $state['chapter'] = $chapter;
                $state['verse'] = max(1, (int) $r['verse']);
            }
            $state['translation'] = bible_translation_code((string) $r['translation']);
            $prefs = json_decode((string) ($r['prefs'] ?? ''), true);
            $state['prefs'] = bible_prefs_clean(is_array($prefs) ? $prefs : []);
            $state['saved'] = true;
        }
    } catch (Throwable $e) {
        error_log('Bible state/get: ' . $e->getMessage());
    }

    return $state;
}

function bible_state_save(PDO $pdo, int $userId, array $in): array
{
    $current = bible_state_get($pdo, $userId);
    $translation = isset($in['translation']) ? bible_translation_code((string) $in['translation']) : $current['translation'];
    $book    = isset($in['book']) ? (int) $in['book'] : $current['book'];
    $chapter = isset($in['chapter']) ? (int) $in['chapter'] : $current['chapter'];
    if (!bible_chapter_exists($book, $chapter)) {
        $book = $current['book'];
        $chapter = $current['chapter'];
    }
    $verse = isset($in['verse']) ? max(1, min(200, (int) $in['verse'])) : $current['verse'];
    $prefs = isset($in['prefs']) && is_array($in['prefs'])
        ? bible_prefs_clean(array_merge($current['prefs'], $in['prefs']))
        : $current['prefs'];
    $json = (string) json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = bible_now();

    $up = $pdo->prepare('UPDATE bible_user_state SET translation = ?, book = ?, chapter = ?, verse = ?, prefs = ?, updated_at = ? WHERE user_id = ?');
    $up->execute([$translation, $book, $chapter, $verse, $json, $now, $userId]);
    if ($up->rowCount() === 0 && !$current['saved']) {
        try {
            $pdo->prepare('INSERT INTO bible_user_state (user_id, translation, book, chapter, verse, prefs, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $translation, $book, $chapter, $verse, $json, $now]);
        } catch (PDOException $e) {
            $up->execute([$translation, $book, $chapter, $verse, $json, $now, $userId]);
        }
    }

    return ['translation' => $translation, 'book' => $book, 'chapter' => $chapter, 'verse' => $verse, 'prefs' => $prefs];
}
