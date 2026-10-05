<?php
// /includes/special_events/karaoke.php
//
// Karaoke (guide §10.8): the global song library, the per-event list, the
// pre-pick holds, the queue and the DJ console.
//
// Two rules shape everything here:
//
//   1. **A claim is a row, not a flag.** The uniqueness of a song and of a
//      singer is enforced by the two generated-column indexes in Appendix A
//      (`active_song_key`, `active_singer_key`), so ten phones racing for
//      the last copy of "Way Maker" produce one winner and nine honest
//      SONG_TAKEN answers — with no application-level locking at all.
//   2. **Numbers come from the event row.** Queue numbers are allocated
//      inside se_lock_event() from `next_karaoke_no`, which only ever
//      increases (§9.5 item 4), so a ticket number is never reused.

/** True once PR4's migration has been applied. */
function se_karaoke_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_karaoke_entries') && se_table_exists($pdo, 'se_songs');
}

// --------------------------------------------------------------------------
// Normalisation (§10.8.1) — pure
// --------------------------------------------------------------------------

/**
 * The matching form of a title or an artist: lower case, accents stripped,
 * punctuation removed, spaces collapsed. For artists a leading "the " goes
 * too, so "The Hillsong Worship" and "Hillsong Worship" are one artist.
 */
function se_song_norm(string $value, bool $isArtist = false): string
{
    $s = trim($value);
    if ($s === '') {
        return '';
    }

    // Decompose accents where iconv can, then drop what is left over.
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($converted) && $converted !== '') {
        // glibc's TRANSLIT writes "á" as "'a". Those marker characters are
        // dropped outright rather than turned into spaces, or "Imelá" and
        // "Imela" would end up as different songs — which is exactly the
        // duplicate this normaliser exists to prevent.
        $s = preg_replace('/[\'`^"~]+(?=\p{L})/u', '', $converted) ?? $converted;
    }

    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    $s = trim($s);

    if ($isArtist && str_starts_with($s, 'the ')) {
        $s = substr($s, 4);
    }

    return mb_substr($s, 0, 160, 'UTF-8');
}

/**
 * Parse a written duration into seconds (§10.8.1): `m:ss`, `mm:ss`,
 * `h:mm:ss`, plain seconds or `4m5s`. Anything else is null — shown as "—"
 * and counted as four minutes for estimates.
 */
function se_song_duration_parse(mixed $value): ?int
{
    if (is_int($value) || is_float($value)) {
        $n = (int) $value;

        return ($n >= 1 && $n <= SE_SONG_MAX_SECONDS) ? $n : null;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    // h:mm:ss, mm:ss, or a bare number of seconds. Anything else is prose,
    // and a wrong duration is worse than none: it skews the "minutes left"
    // the DJ reads off the console.
    if (preg_match('/^(\d{1,2}):([0-5]?\d):([0-5]\d)$/', $raw, $m)) {
        $seconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
    } elseif (preg_match('/^(\d{1,3}):([0-5]\d)$/', $raw, $m)) {
        $seconds = ((int) $m[1] * 60) + (int) $m[2];
    } elseif (preg_match('/^(\d{1,4})$/', $raw, $m)) {
        $seconds = (int) $m[1];
    } else {
        return null;
    }

    return ($seconds >= 1 && $seconds <= SE_SONG_MAX_SECONDS) ? $seconds : null;
}

function se_song_duration_label(?int $seconds): ?string
{
    if ($seconds === null || $seconds <= 0) {
        return null;
    }

    return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
}

/**
 * Parse a pasted list into rows (§10.8.1): one song per line, as
 * `Title - Artist - 4:05`, tab-separated, or a bare title.
 *
 * Pure: the import preview is testable without a database.
 *
 * @return list<array{title:string, artist:string, duration_sec:?int, line:int}>
 */
function se_songs_parse_text(string $text): array
{
    $out = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $i => $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        // People number their lists. "12. Imela" is one song, not a song
        // called "12." — so a leading index is dropped before anything else.
        $line = preg_replace('/^\s*\d{1,3}\s*[.)\]-]\s+/u', '', $line) ?? $line;

        // Separators, in the order they are meant: a tab from a spreadsheet,
        // then the dashes people actually type, then the word "by".
        if (str_contains($line, "\t")) {
            $cols = array_map('trim', explode("\t", $line));
        } elseif (preg_match('/\s[-–—]\s/u', $line)) {
            $cols = array_map('trim', preg_split('/\s[-–—]\s/u', $line, 2) ?: [$line]);
        } elseif (preg_match('/\s+by\s+/iu', $line)) {
            $cols = array_map('trim', preg_split('/\s+by\s+/iu', $line, 2) ?: [$line]);
        } else {
            $cols = [$line];
        }

        // A duration can be its own trailing column, or stuck on the end of
        // the artist ("Sinach 5:12"), which is how a pasted list usually
        // looks. Both are pulled out rather than left to pollute the name.
        $duration = null;
        if (count($cols) > 1) {
            $last = (string) end($cols);
            $try  = se_song_duration_parse($last);
            if ($try !== null && preg_match('/[:ms]/i', $last)) {
                $duration = $try;
                array_pop($cols);
            } elseif (preg_match('/^(.*?)[\s(\[]+(\d{1,2}:\d{2})\)?\]?$/u', $last, $m)) {
                $duration = se_song_duration_parse($m[2]);
                array_pop($cols);
                $cols[] = trim($m[1]);
            }
        }

        $title = se_line($cols[0] ?? '', 160);
        if ($title === '') {
            continue;
        }

        $out[] = [
            'title'        => $title,
            'artist'       => se_line($cols[1] ?? '', 160),
            'duration_sec' => $duration,
            'line'         => (int) $i + 1,
        ];
    }

    return $out;
}

function se_songs_parse_csv(string $csv): array
{
    $rows = [];
    $fh   = fopen('php://temp', 'r+');
    if ($fh === false) {
        return [];
    }
    fwrite($fh, $csv);
    rewind($fh);

    $map = ['title' => 0, 'artist' => 1, 'duration' => 2];
    $first = true;
    $line  = 0;

    while (($cols = fgetcsv($fh, 4096, ',', '"', '')) !== false) {
        $line++;
        if (!is_array($cols)) {
            continue;
        }
        $cols = array_map(static fn($c): string => trim((string) $c), $cols);

        if ($first) {
            $first = false;
            $found = [];
            foreach ($cols as $i => $name) {
                $key = strtolower($name);
                if (in_array($key, ['title', 'song'], true))                { $found['title'] = $i; }
                if (in_array($key, ['artist', 'singer'], true))             { $found['artist'] = $i; }
                if (in_array($key, ['duration', 'length', 'time'], true))   { $found['duration'] = $i; }
            }
            if (isset($found['title'])) {
                $map = $found + ['artist' => null, 'duration' => null];
                continue;   // a real header row, not data
            }
        }

        $title = se_line($cols[$map['title']] ?? '', 160);
        if ($title === '') {
            continue;
        }

        $rows[] = [
            'title'        => $title,
            'artist'       => se_line($map['artist'] !== null ? ($cols[$map['artist']] ?? '') : '', 160),
            'duration_sec' => se_song_duration_parse($map['duration'] !== null ? ($cols[$map['duration']] ?? '') : ''),
            'line'         => $line,
        ];
    }

    fclose($fh);

    return $rows;
}

// --------------------------------------------------------------------------
// The library and the event list
// --------------------------------------------------------------------------

/** Find a song by its normalised title and artist. */
function se_song_find(PDO $pdo, string $title, string $artist): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM se_songs WHERE title_norm = ? AND artist_norm = ? LIMIT 1");
    $stmt->execute([se_song_norm($title), se_song_norm($artist, true)]);

    return $stmt->fetch() ?: null;
}

/**
 * Add a song to the global library, or return the one that is already there.
 * Duration is filled in when the library row has none.
 */
function se_song_upsert(PDO $pdo, string $title, string $artist, ?int $durationSec, ?int $actorId): array
{
    $title  = se_line($title, 160);
    $artist = se_line($artist, 160);
    if ($title === '') {
        throw new SeValidationException(['title' => 'A song needs a title.']);
    }

    $existing = se_song_find($pdo, $title, $artist);
    if ($existing) {
        if ($durationSec !== null && $existing['duration_sec'] === null) {
            $pdo->prepare("UPDATE se_songs SET duration_sec = ? WHERE id = ?")
                ->execute([$durationSec, (int) $existing['id']]);
            $existing['duration_sec'] = $durationSec;
        }

        return $existing;
    }

    try {
        $pdo->prepare(
            "INSERT INTO se_songs (title, artist, duration_sec, title_norm, artist_norm, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $title, $artist, $durationSec,
            se_song_norm($title), se_song_norm($artist, true), $actorId,
        ]);
    } catch (PDOException $e) {
        if (!se_is_duplicate_key($e)) {
            throw $e;
        }
        // Someone inserted the same song between the lookup and the insert.
        return se_song_find($pdo, $title, $artist) ?? [];
    }

    $id   = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM se_songs WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch() ?: [];
}

/**
 * The event's song list with, for each song, whether it is already claimed.
 *
 * @return array{items: list<array<string,mixed>>, total: int, page: int, pages: int}
 */
function se_songs_event_list(PDO $pdo, array $event, array $opts = []): array
{
    if (!se_karaoke_ready($pdo)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }

    $eventId  = (int) $event['id'];
    $q        = se_line($opts['q'] ?? '', 80);
    $page     = se_int($opts['page'] ?? 1, 1, 1000, 1);
    $perPage  = se_int($opts['per_page'] ?? 50, 1, 100, 50);
    $activeOnly = (bool) ($opts['active_only'] ?? false);

    $where = ['es.event_id = ?'];
    $args  = [$eventId];
    if ($activeOnly) {
        $where[] = 'es.is_active = 1';
    }
    if ($q !== '') {
        $where[] = '(s.title_norm LIKE ? OR s.artist_norm LIKE ?)';
        $like = '%' . se_song_norm($q) . '%';
        $args[] = $like;
        $args[] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM se_event_songs es JOIN se_songs s ON s.id = es.song_id WHERE {$whereSql}");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT s.*, es.is_active, es.sort_order,
                   (SELECT COUNT(*) FROM se_karaoke_entries k
                     WHERE k.event_id = es.event_id AND k.song_id = s.id
                       AND k.status IN ('held','queued','up_next','on_stage','done')) AS taken_count
              FROM se_event_songs es
              JOIN se_songs s ON s.id = es.song_id
             WHERE {$whereSql}
             ORDER BY s.title_norm ASC
             LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    $settings = se_event_settings($event);
    $unique   = se_bool($settings['karaoke']['unique_songs'] ?? true);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['title'],
            'artist'       => (string) $row['artist'],
            'duration_sec' => $row['duration_sec'] !== null ? (int) $row['duration_sec'] : null,
            'duration'     => se_song_duration_label($row['duration_sec'] !== null ? (int) $row['duration_sec'] : null),
            'is_active'    => se_bool($row['is_active']),
            'taken'        => $unique && (int) $row['taken_count'] > 0,
        ];
    }

    return [
        'items' => $items,
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}

/**
 * Preview an import (§10.8.1): classify every parsed row against the library
 * and this event's list. Nothing is written.
 */
function se_songs_import_preview(PDO $pdo, array $event, array $rows): array
{
    $eventId = (int) $event['id'];
    $out     = [];
    $seen    = [];
    $counts  = ['new' => 0, 'in_library' => 0, 'in_event' => 0, 'duplicate' => 0, 'maybe_duplicate' => 0];

    foreach (array_slice($rows, 0, 400) as $row) {
        $title  = se_line($row['title'] ?? '', 160);
        $artist = se_line($row['artist'] ?? '', 160);
        if ($title === '') {
            continue;
        }

        $key = se_song_norm($title) . '|' . se_song_norm($artist, true);
        if (isset($seen[$key])) {
            $counts['duplicate']++;
            continue;   // the same line twice in one paste
        }
        $seen[$key] = true;

        $song   = se_song_find($pdo, $title, $artist);
        $status = 'new';

        if ($song) {
            $status = 'in_library';
            $check  = $pdo->prepare("SELECT 1 FROM se_event_songs WHERE event_id = ? AND song_id = ?");
            $check->execute([$eventId, (int) $song['id']]);
            if ($check->fetchColumn()) {
                $status = 'in_event';
            }
        } else {
            // Same title, different artist: probably the same song typed two
            // ways, so the crew gets a warning rather than a silent second row.
            $near = $pdo->prepare("SELECT title, artist FROM se_songs WHERE title_norm = ? LIMIT 1");
            $near->execute([se_song_norm($title)]);
            if ($near->fetch()) {
                $status = 'maybe_duplicate';
            }
        }

        $counts[$status === 'maybe_duplicate' ? 'maybe_duplicate' : $status]++;

        $out[] = [
            'title'        => $title,
            'artist'       => $artist,
            'duration_sec' => isset($row['duration_sec']) ? se_int_or_null($row['duration_sec'], 1, 3600) : null,
            'duration'     => se_song_duration_label(isset($row['duration_sec']) ? se_int_or_null($row['duration_sec'], 1, 3600) : null),
            'status'       => $status,
            'song_id'      => $song ? (int) $song['id'] : null,
            'include'      => $status !== 'in_event',
        ];
    }

    return ['rows' => $out, 'counts' => $counts];
}

/**
 * Commit a reviewed import: add missing songs to the library and every
 * included row to this event's list.
 */
function se_songs_import_commit(PDO $pdo, array $event, array $rows, ?int $actorId): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $eventId = (int) $event['id'];
    $added   = 0;

    $pdo->beginTransaction();
    try {
        $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM se_event_songs WHERE event_id = ?");
        $sort->execute([$eventId]);
        $order = (int) $sort->fetchColumn();

        $link = $pdo->prepare(
            "INSERT INTO se_event_songs (event_id, song_id, is_active, sort_order, added_by)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE is_active = 1"
        );

        foreach (array_slice($rows, 0, 400) as $row) {
            if (!is_array($row) || !se_bool($row['include'] ?? true)) {
                continue;
            }
            $title = se_line($row['title'] ?? '', 160);
            if ($title === '') {
                continue;
            }

            $song = se_song_upsert(
                $pdo,
                $title,
                se_line($row['artist'] ?? '', 160),
                se_int_or_null($row['duration_sec'] ?? null, 1, 3600),
                $actorId
            );
            if (!$song) {
                continue;
            }

            $order++;
            $link->execute([$eventId, (int) $song['id'], $order, $actorId]);
            $added++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    se_audit($pdo, $eventId, 'karaoke_op', ['op' => 'import', 'songs' => $added], 'event', $eventId, $actorId);

    return ['added' => $added] + se_songs_event_list($pdo, $event, ['per_page' => 100]);
}

/**
 * Read a song list out of a picture or a PDF (§15.5) and hand it back as a
 * preview. As with the programme, nothing is written until a human commits.
 */
function se_songs_import_ai(PDO $pdo, array $event, ?int $assetId, string $text, ?int $actorId): array
{
    $parts = [];
    if ($assetId !== null) {
        $parts[] = se_ai_inline_asset($pdo, $event, $assetId, ['songs_source']);
    }
    if ($text !== '') {
        $parts[] = ['text' => $text];
    }
    if (!$parts) {
        throw new SeValidationException(['text' => 'Paste the list, or choose an uploaded picture.']);
    }

    $result = se_ai($pdo, 'songs_extract', ['text' => $text], [
        'user_id'  => $actorId,
        'event_id' => (int) $event['id'],
        'vision'   => $assetId !== null,
        'parts'    => $parts,
    ]);

    $rows = [];
    $songs = is_array($result['songs'] ?? null) ? $result['songs'] : [];
    // Keep the 400-row cap in PHP instead of exposing a large nested-array
    // bound to Gemini's constrained-decoding compiler.
    foreach (array_slice($songs, 0, 400) as $song) {
        if (!is_array($song)) {
            continue;
        }
        $rows[] = [
            'title'        => se_line($song['title'] ?? '', 160),
            'artist'       => se_line($song['artist'] ?? '', 160),
            'duration_sec' => se_song_duration_parse($song['duration'] ?? ''),
        ];
    }

    $preview = se_songs_import_preview($pdo, $event, $rows);
    $preview['warnings'] = array_values(array_map(
        static fn($w): string => se_line($w, 200),
        is_array($result['warnings'] ?? null) ? $result['warnings'] : []
    ));

    return $preview;
}

/** Turn one song on or off for this event. */
function se_song_toggle(PDO $pdo, array $event, int $songId, bool $active, ?int $actorId): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $eventId = (int) $event['id'];
    $stmt = $pdo->prepare("UPDATE se_event_songs SET is_active = ? WHERE event_id = ? AND song_id = ?");
    $stmt->execute([$active ? 1 : 0, $eventId, $songId]);

    se_audit($pdo, $eventId, 'karaoke_op', ['op' => 'song_toggle', 'song_id' => $songId, 'active' => $active], 'song', $songId, $actorId);

    return se_songs_event_list($pdo, $event, ['per_page' => 100]);
}

// --------------------------------------------------------------------------
// Claims (§10.8.2)
// --------------------------------------------------------------------------

/** Is picking a song open right now? */
function se_karaoke_open(array $event, array $settings, ?array $phase = null): array
{
    if (!se_bool($settings['karaoke']['enabled'] ?? false)) {
        return ['open' => false, 'reason' => 'FEATURE_DISABLED'];
    }
    if (in_array((string) $event['status'], ['cancelled', 'archived'], true)) {
        return ['open' => false, 'reason' => 'KARAOKE_CLOSED'];
    }
    if (!se_bool($settings['karaoke']['list_published'] ?? false)) {
        return ['open' => false, 'reason' => 'LIST_NOT_PUBLISHED'];
    }

    return ['open' => true, 'reason' => null];
}

/** The caller's own entry, or null. */
function se_karaoke_entry_for(PDO $pdo, int $eventId, int $registrationId): ?array
{
    if (!se_karaoke_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT k.*, s.title, s.artist, s.duration_sec
           FROM se_karaoke_entries k JOIN se_songs s ON s.id = k.song_id
          WHERE k.event_id = ? AND k.registration_id = ?
          ORDER BY FIELD(k.status, 'on_stage','up_next','queued','held','done') ASC, k.id DESC
          LIMIT 1"
    );
    $stmt->execute([$eventId, $registrationId]);

    return $stmt->fetch() ?: null;
}

/** How many singers are ahead of this entry in the queue. */
function se_karaoke_ahead(PDO $pdo, int $eventId, array $entry): int
{
    if (!in_array((string) $entry['status'], ['queued', 'up_next'], true) || $entry['position'] === null) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_karaoke_entries
          WHERE event_id = ? AND status IN ('queued','up_next') AND position < ?"
    );
    $stmt->execute([$eventId, (int) $entry['position']]);

    return (int) $stmt->fetchColumn();
}

/** The `karaoke` block of the `me` payload (§12.2.1). */
function se_karaoke_me(PDO $pdo, array $event, int $registrationId): ?array
{
    $entry = se_karaoke_entry_for($pdo, (int) $event['id'], $registrationId);
    if (!$entry) {
        return null;
    }

    return [
        'entry_id' => (int) $entry['id'],
        'status'   => (string) $entry['status'],
        'queue_no' => $entry['queue_no'] !== null ? (int) $entry['queue_no'] : null,
        'ahead'    => se_karaoke_ahead($pdo, (int) $event['id'], $entry),
        'song'     => [
            'id'       => (int) $entry['song_id'],
            'title'    => (string) $entry['title'],
            'artist'   => (string) $entry['artist'],
            'duration' => se_song_duration_label($entry['duration_sec'] !== null ? (int) $entry['duration_sec'] : null),
        ],
    ];
}

/**
 * Claim a song (§10.8.2).
 *
 * Before the doors open this is a **hold**; a person who is already checked
 * in joins the queue straight away and gets their ticket number. Both paths
 * run inside se_lock_event(), because both may allocate a number.
 *
 * @return array the `karaoke` block for the caller
 */
function se_karaoke_pick(PDO $pdo, array $event, array $registration, int $songId, string $source, ?int $actorId = null): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $eventId  = (int) $event['id'];
    $regId    = (int) $registration['id'];
    $settings = se_event_settings($event);

    $open = se_karaoke_open($event, $settings);
    if (!$open['open'] && !in_array($source, ['desk', 'dj'], true)) {
        throw new SeRuleException(
            $open['reason'] === 'LIST_NOT_PUBLISHED' ? 'LIST_NOT_PUBLISHED' : 'KARAOKE_CLOSED',
            $open['reason'] === 'LIST_NOT_PUBLISHED'
                ? 'The song list is coming soon 🎤'
                : 'Karaoke is closed.'
        );
    }

    $song = $pdo->prepare(
        "SELECT s.* FROM se_songs s
           JOIN se_event_songs es ON es.song_id = s.id AND es.event_id = ?
          WHERE s.id = ? AND es.is_active = 1 LIMIT 1"
    );
    $song->execute([$eventId, $songId]);
    $songRow = $song->fetch();
    if (!$songRow) {
        throw new SeNotFoundException('That song is not on tonight’s list.');
    }

    return se_lock_event($pdo, $eventId, static function (array $locked, PDO $pdo) use (
        $event, $eventId, $registration, $regId, $songRow, $settings, $source, $actorId
    ): array {
        // One active entry per person, and with songs_per_person = 1 a
        // finished turn is still a turn (§10.8.2).
        $mine = $pdo->prepare(
            "SELECT status FROM se_karaoke_entries
              WHERE event_id = ? AND registration_id = ? AND status IN ('held','queued','up_next','on_stage','done')"
        );
        $mine->execute([$eventId, $regId]);
        $existing = $mine->fetchAll();

        $perPerson = se_int($settings['karaoke']['songs_per_person'] ?? 1, 1, 5, 1);
        if (count($existing) >= $perPerson) {
            throw new SeRuleException('ALREADY_HAS_SONG', 'You already have a song for tonight.');
        }

        // The cap counts everything that has claimed a slot tonight.
        $max = se_int_or_null($settings['karaoke']['max_singers'] ?? null, 1, 1000);
        if ($max !== null) {
            $count = $pdo->prepare(
                "SELECT COUNT(*) FROM se_karaoke_entries
                  WHERE event_id = ? AND status IN ('held','queued','up_next','on_stage','done')"
            );
            $count->execute([$eventId]);
            if ((int) $count->fetchColumn() >= $max) {
                throw new SeRuleException('KARAOKE_FULL', 'The karaoke list is full for tonight.');
            }
        }

        $unique = se_bool($settings['karaoke']['unique_songs'] ?? true);

        // Somebody who is already in the room goes straight into the queue.
        $checkedIn = false;
        if (se_table_exists($pdo, 'se_checkins')) {
            $stmt = $pdo->prepare("SELECT 1 FROM se_checkins WHERE event_id = ? AND registration_id = ? LIMIT 1");
            $stmt->execute([$eventId, $regId]);
            $checkedIn = (bool) $stmt->fetchColumn();
        }

        $status   = $checkedIn ? 'queued' : 'held';
        $queueNo  = null;
        $position = null;

        if ($checkedIn) {
            $queueNo = (int) $locked['next_karaoke_no'];
            $pos = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM se_karaoke_entries WHERE event_id = ?");
            $pos->execute([$eventId]);
            $position = (int) $pos->fetchColumn();
        }

        try {
            $pdo->prepare(
                "INSERT INTO se_karaoke_entries
                     (event_id, registration_id, song_id, status, source, enforce_unique, is_test,
                      queue_no, position, queued_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $eventId,
                $regId,
                (int) $songRow['id'],
                $status,
                se_enum($source, ['prepick', 'checkin', 'portal', 'desk', 'dj'], 'portal'),
                $unique ? 1 : 0,
                se_bool($registration['is_test'] ?? false) ? 1 : 0,
                $queueNo,
                $position,
                $checkedIn ? se_sql_datetime(se_now()) : null,
                $actorId,
            ]);
        } catch (PDOException $e) {
            if (!se_is_duplicate_key($e)) {
                throw $e;
            }
            // One of the two generated-column indexes fired. Which one tells
            // the singer what happened, and both answers are safe to show.
            if (str_contains($e->getMessage(), 'uniq_se_karaoke_singer')) {
                throw new SeRuleException('ALREADY_HAS_SONG', 'You already have a song for tonight.');
            }

            throw new SeRuleException('SONG_TAKEN', 'Somebody just took that song. Pick another 🎤');
        }

        if ($checkedIn) {
            $pdo->prepare("UPDATE se_events SET next_karaoke_no = next_karaoke_no + 1 WHERE id = ?")->execute([$eventId]);
        }

        se_audit($pdo, $eventId, 'karaoke_op', [
            'op' => 'pick', 'song_id' => (int) $songRow['id'], 'status' => $status, 'source' => $source,
        ], 'registration', $regId, $actorId, $actorId === null ? $regId : null);

        return [
            'entry_id' => (int) $pdo->lastInsertId(),
            'status'   => $status,
            'queue_no' => $queueNo,
            'ahead'    => $position !== null ? max(0, $position - 1) : 0,
            'song'     => [
                'id'       => (int) $songRow['id'],
                'title'    => (string) $songRow['title'],
                'artist'   => (string) $songRow['artist'],
                'duration' => se_song_duration_label($songRow['duration_sec'] !== null ? (int) $songRow['duration_sec'] : null),
            ],
        ];
    });
}

/**
 * Give a song back. Only a claim that has not been sung yet can be released,
 * and the row is kept as `cancelled` so the audit trail survives — the
 * generated keys drop out of the unique indexes, so the song is free again.
 */
function se_karaoke_release(PDO $pdo, array $event, array $registration, ?int $actorId = null): bool
{
    if (!se_karaoke_ready($pdo)) {
        return false;
    }

    $eventId = (int) $event['id'];
    $regId   = (int) $registration['id'];

    $stmt = $pdo->prepare(
        "UPDATE se_karaoke_entries SET status = 'cancelled', updated_by = ?
          WHERE event_id = ? AND registration_id = ? AND status IN ('held','queued')"
    );
    $stmt->execute([$actorId, $eventId, $regId]);

    if ($stmt->rowCount() > 0) {
        se_audit($pdo, $eventId, 'karaoke_op', ['op' => 'release'], 'registration', $regId, $actorId, $actorId === null ? $regId : null);
        se_live_publish($pdo, $eventId, true);

        return true;
    }

    return false;
}

/**
 * Release the holds of people who never arrived (§10.8.2), at
 * `first day starts_at + release_holds_after_min`. Called by the tick and
 * by the cron.
 *
 * @return int how many holds were released
 */
function se_karaoke_release_holds(PDO $pdo, array $event, ?array $days = null, ?DateTimeImmutable $now = null): int
{
    if (!se_karaoke_ready($pdo)) {
        return 0;
    }

    $eventId  = (int) $event['id'];
    $settings = se_event_settings($event);
    if (!se_bool($settings['karaoke']['enabled'] ?? false)) {
        return 0;
    }

    $days ??= se_event_days($pdo, $eventId);
    $first  = $days[0] ?? null;
    $start  = se_parse_datetime($first['starts_at'] ?? null);
    if ($start === null) {
        return 0;
    }

    $now ??= se_now();
    $cutoff = $start->modify('+' . se_int($settings['karaoke']['release_holds_after_min'] ?? 30, 0, 600, 30) . ' minutes');
    if ($now < $cutoff) {
        return 0;
    }

    // A hold is only released when the singer is not in the room. The
    // check-in flow has already promoted everyone who is.
    $sql = "UPDATE se_karaoke_entries k
               SET k.status = 'released'
             WHERE k.event_id = ? AND k.status = 'held'";
    if (se_table_exists($pdo, 'se_checkins')) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM se_checkins c
                                   WHERE c.event_id = k.event_id AND c.registration_id = k.registration_id)";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $released = $stmt->rowCount();

    if ($released > 0) {
        se_audit($pdo, $eventId, 'karaoke_op', ['op' => 'release_holds', 'n' => $released], 'event', $eventId, null);
        se_live_publish($pdo, $eventId, true);
    }

    return $released;
}

// --------------------------------------------------------------------------
// The queue and the DJ console (§13.12)
// --------------------------------------------------------------------------

/**
 * The full queue with names. Crew-only: this never becomes a snapshot and
 * never reaches public.json (§8.5.2).
 */
function se_karaoke_queue(PDO $pdo, array $event): array
{
    if (!se_karaoke_ready($pdo)) {
        return ['ready' => false, 'entries' => [], 'now' => null, 'next' => null, 'stats' => []];
    }

    $eventId = (int) $event['id'];
    $stmt = $pdo->prepare(
        "SELECT k.*, s.title, s.artist, s.duration_sec, r.display_name, r.player_no
           FROM se_karaoke_entries k
           JOIN se_songs s ON s.id = k.song_id
           JOIN se_registrations r ON r.id = k.registration_id
          WHERE k.event_id = ? AND k.status IN ('held','queued','up_next','on_stage','done','skipped','no_show')
          ORDER BY FIELD(k.status, 'on_stage','up_next','queued','held','done','skipped','no_show') ASC,
                   k.position ASC, k.id ASC"
    );
    $stmt->execute([$eventId]);

    $settings = se_event_settings($event);
    $avgMin   = se_int($settings['karaoke']['avg_song_min'] ?? 4, 1, 15, 4);

    $entries = [];
    $now     = null;
    $next    = null;
    $remaining = 0;
    $performed = 0;
    $secondsLeft = 0;

    foreach ($stmt->fetchAll() as $row) {
        $duration = $row['duration_sec'] !== null ? (int) $row['duration_sec'] : null;
        $entry = [
            'id'        => (int) $row['id'],
            'status'    => (string) $row['status'],
            'queue_no'  => $row['queue_no'] !== null ? (int) $row['queue_no'] : null,
            'position'  => $row['position'] !== null ? (int) $row['position'] : null,
            'singer'    => (string) $row['display_name'],
            'player_no' => $row['player_no'] !== null ? (int) $row['player_no'] : null,
            'registration_id' => (int) $row['registration_id'],
            'is_test'   => se_bool($row['is_test']),
            'song'      => [
                'id'       => (int) $row['song_id'],
                'title'    => (string) $row['title'],
                'artist'   => (string) $row['artist'],
                'duration' => se_song_duration_label($duration),
            ],
        ];

        if ($entry['status'] === 'on_stage') {
            $now = $entry;
        } elseif ($entry['status'] === 'up_next') {
            $next = $entry;
        }

        if (in_array($entry['status'], ['queued', 'up_next'], true)) {
            $remaining++;
            // Unknown durations count as the configured average, plus a
            // minute of changeover each (§13.12).
            $secondsLeft += ($duration ?? $avgMin * 60) + 60;
        }
        if ($entry['status'] === 'done') {
            $performed++;
        }

        $entries[] = $entry;
    }

    return [
        'ready'   => true,
        'entries' => $entries,
        'now'     => $now,
        'next'    => $next,
        'stats'   => [
            'performed' => $performed,
            'remaining' => $remaining,
            'minutes_left' => (int) ceil($secondsLeft / 60),
        ],
    ];
}

/**
 * What the room may see: the song on stage and the one after it, with no
 * names at all (public.json is world-readable, §8.5.2).
 */
function se_karaoke_public(PDO $pdo, array $event): ?array
{
    if (!se_karaoke_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT k.status, k.queue_no, s.title, s.artist
           FROM se_karaoke_entries k JOIN se_songs s ON s.id = k.song_id
          WHERE k.event_id = ? AND k.status IN ('on_stage','up_next')
          ORDER BY FIELD(k.status, 'on_stage','up_next') ASC LIMIT 2"
    );
    $stmt->execute([(int) $event['id']]);

    $out = ['now' => null, 'next' => null, 'waiting' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $song = ['title' => (string) $row['title'], 'artist' => (string) $row['artist']];
        if ((string) $row['status'] === 'on_stage') {
            $out['now'] = ['song' => $song, 'queue_no' => $row['queue_no'] !== null ? (int) $row['queue_no'] : null];
        } else {
            $out['next'] = ['song' => $song, 'queue_no' => $row['queue_no'] !== null ? (int) $row['queue_no'] : null];
        }
    }

    $waiting = $pdo->prepare("SELECT COUNT(*) FROM se_karaoke_entries WHERE event_id = ? AND status IN ('queued','up_next')");
    $waiting->execute([(int) $event['id']]);
    $out['waiting'] = (int) $waiting->fetchColumn();

    if ($out['now'] === null && $out['next'] === null && $out['waiting'] === 0) {
        return null;
    }

    return $out;
}

/**
 * Set an entry's status from the DJ console (§10.8.2).
 *
 * `up_next` and `on_stage` are singular: promoting one demotes whoever held
 * that slot, so the singer's phone alert and the stage scene can never point
 * at two people.
 */
function se_karaoke_set_status(PDO $pdo, array $event, int $entryId, string $status, ?int $expectedVersion, ?int $actorId): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $status = se_enum($status, SE_KARAOKE_DJ_STATUSES, '');
    if ($status === '') {
        throw new SeValidationException(['status' => 'Unknown karaoke status.']);
    }

    $eventId = (int) $event['id'];

    se_live_mutate($pdo, $event, $expectedVersion, static function (array $state, PDO $pdo) use ($eventId, $entryId, $status, $actorId): array {
        $stmt = $pdo->prepare("SELECT * FROM se_karaoke_entries WHERE id = ? AND event_id = ? FOR UPDATE");
        $stmt->execute([$entryId, $eventId]);
        $entry = $stmt->fetch();
        if (!$entry) {
            throw new SeNotFoundException('We could not find that singer.');
        }

        $now = se_sql_datetime(se_now());

        if ($status === 'up_next') {
            $pdo->prepare("UPDATE se_karaoke_entries SET status = 'queued' WHERE event_id = ? AND status = 'up_next' AND id <> ?")
                ->execute([$eventId, $entryId]);
        }
        if ($status === 'on_stage') {
            $pdo->prepare("UPDATE se_karaoke_entries SET status = 'done', finished_at = COALESCE(finished_at, ?) WHERE event_id = ? AND status = 'on_stage' AND id <> ?")
                ->execute([$now, $eventId, $entryId]);
        }

        $sets = ['status = ?'];
        $args = [$status];

        if ($status === 'on_stage') {
            $sets[] = 'on_stage_at = COALESCE(on_stage_at, ?)';
            $args[] = $now;
        }
        if (in_array($status, ['done', 'skipped', 'no_show'], true)) {
            $sets[] = 'finished_at = ?';
            $args[] = $now;
        }
        if (in_array($status, ['queued', 'up_next'], true) && $entry['queue_no'] === null) {
            // A singer the DJ pulls forward out of a hold still needs a
            // ticket number, and it comes from the same counter as everyone's.
            $locked = $pdo->prepare("SELECT next_karaoke_no FROM se_events WHERE id = ? FOR UPDATE");
            $locked->execute([$eventId]);
            $sets[] = 'queue_no = ?';
            $args[] = (int) $locked->fetchColumn();
            $pdo->prepare("UPDATE se_events SET next_karaoke_no = next_karaoke_no + 1 WHERE id = ?")->execute([$eventId]);

            $sets[] = 'queued_at = COALESCE(queued_at, ?)';
            $args[] = $now;
        }
        if ($entry['position'] === null && in_array($status, ['queued', 'up_next', 'on_stage'], true)) {
            $pos = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM se_karaoke_entries WHERE event_id = ?");
            $pos->execute([$eventId]);
            $sets[] = 'position = ?';
            $args[] = (int) $pos->fetchColumn();
        }

        $sets[] = 'updated_by = ?';
        $args[] = $actorId;
        $args[] = $entryId;

        $pdo->prepare("UPDATE se_karaoke_entries SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

        return [];
    }, 'karaoke_op', ['op' => 'set', 'entry_id' => $entryId, 'status' => $status], $actorId);

    se_live_publish($pdo, $eventId, true);

    return se_karaoke_queue($pdo, $event);
}

/** Drag an entry to a new place in the queue. */
function se_karaoke_move(PDO $pdo, array $event, int $entryId, ?int $beforeId, ?int $expectedVersion, ?int $actorId): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $eventId = (int) $event['id'];

    se_live_mutate($pdo, $event, $expectedVersion, static function (array $state, PDO $pdo) use ($eventId, $entryId, $beforeId): array {
        $stmt = $pdo->prepare(
            "SELECT id FROM se_karaoke_entries
              WHERE event_id = ? AND status IN ('queued','up_next')
              ORDER BY position ASC, id ASC"
        );
        $stmt->execute([$eventId]);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $stmt->fetchAll());

        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $entryId));

        $out = [];
        $placed = false;
        foreach ($ids as $id) {
            if ($beforeId !== null && $id === $beforeId) {
                $out[] = $entryId;
                $placed = true;
            }
            $out[] = $id;
        }
        if (!$placed) {
            $out[] = $entryId;
        }

        $update = $pdo->prepare("UPDATE se_karaoke_entries SET position = ? WHERE id = ? AND event_id = ?");
        foreach ($out as $i => $id) {
            $update->execute([$i + 1, $id, $eventId]);
        }

        return [];
    }, 'karaoke_op', ['op' => 'move', 'entry_id' => $entryId, 'before_id' => $beforeId], $actorId);

    se_live_publish($pdo, $eventId, true);

    return se_karaoke_queue($pdo, $event);
}

/** Add a walk-up singer from the DJ console. */
function se_karaoke_add(PDO $pdo, array $event, array $who, int $songId, ?int $actorId): array
{
    if (!se_karaoke_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Karaoke is not available yet.');
    }

    $eventId = (int) $event['id'];
    $regId   = se_int($who['registration_id'] ?? 0, 0);

    if ($regId === 0 && isset($who['player_no'])) {
        $stmt = $pdo->prepare("SELECT id FROM se_registrations WHERE event_id = ? AND player_no = ? LIMIT 1");
        $stmt->execute([$eventId, se_int($who['player_no'], 1)]);
        $regId = (int) ($stmt->fetchColumn() ?: 0);
    }

    $registration = $regId > 0 ? se_registration_by_id($pdo, $regId, $eventId) : null;
    if (!$registration) {
        throw new SeNotFoundException('We could not find that guest.');
    }

    $karaoke = se_karaoke_pick($pdo, $event, $registration, $songId, 'dj', $actorId);

    se_live_publish($pdo, $eventId, true);

    return ['karaoke' => $karaoke] + se_karaoke_queue($pdo, $event);
}

/** Copy the event's song list and karaoke settings onto a clone (§10.10). */
function se_karaoke_clone(PDO $pdo, int $sourceId, int $targetId, ?int $actorId): int
{
    if (!se_karaoke_ready($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO se_event_songs (event_id, song_id, is_active, sort_order, added_by)
         SELECT ?, song_id, is_active, sort_order, ?
           FROM se_event_songs WHERE event_id = ?
         ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)"
    );
    $stmt->execute([$targetId, $actorId, $sourceId]);

    return $stmt->rowCount();
}
