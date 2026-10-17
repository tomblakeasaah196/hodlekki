<?php
// /includes/hymnal/library.php
//
// One library over two halves.
//
// The reader, the search box and the API only ever talk to this file. Whether a
// song came out of includes/hymnal/corpus/ or out of hymnal_songs is an
// implementation detail that stops here, which is what lets the 511 hymns work
// before the migration has run while the Studio songs appear the moment it has.
//
// Everything is keyed by HOD number, which is unique across both halves because
// the corpus owns 1-1999 and the Studio allocates from 2000 up.

/**
 * Every song a member may see, as light index rows.
 *
 * @param bool $includeDrafts the Studio passes true; the reader never does
 * @return list<array>
 */
function hymnal_library_index(?PDO $pdo, bool $includeDrafts = false): array
{
    $corpus = array_map(
        static function (array $r): array {
            $r['origin'] = 'corpus';
            $r['status'] = 'published';
            $r['id'] = null;

            return $r;
        },
        hymnal_corpus_index()
    );

    $studio = hymnal_songs_index($pdo, $includeDrafts);
    if ($studio === []) {
        return $corpus;
    }

    return array_merge($corpus, $studio);
}

function hymnal_library_count(?PDO $pdo): int
{
    return count(hymnal_corpus_index()) + hymnal_songs_count($pdo);
}

/**
 * One song in full.
 *
 * A number below the corpus ceiling is looked for in the files first, because
 * that is the common case and it costs no query.
 */
function hymnal_library_song(?PDO $pdo, int $number, bool $includeDrafts = false): ?array
{
    if ($number <= 0) {
        return null;
    }
    if ($number <= HYMNAL_CORPUS_CEILING) {
        $song = hymnal_corpus_song($number);
        if ($song !== null) {
            return $song;
        }
    }
    if ($pdo === null) {
        return null;
    }

    return hymnal_song_db($pdo, $number, $includeDrafts);
}

/**
 * Open a song for a member: the song, whether they have marked it, and a note
 * in their own recent list.
 *
 * @return array{ok:bool, code?:string, message?:string, data?:array}
 */
function hymnal_library_open(?PDO $pdo, int $userId, int $number, bool $includeDrafts = false): array
{
    $song = hymnal_library_song($pdo, $number, $includeDrafts);
    if ($song === null) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'There is no hymn with that number.'];
    }
    $song['favourite'] = false;
    if ($pdo !== null && $userId > 0 && hymnal_table_exists($pdo, 'hymnal_favourites')) {
        $song['favourite'] = in_array($number, hymnal_favourites($pdo, $userId), true);
        hymnal_recent_touch($pdo, $userId, $number);
        hymnal_state_save($pdo, $userId, ['last_number' => $number]);
    }

    return ['ok' => true, 'data' => $song];
}

/**
 * Search both halves at once.
 *
 * Done in PHP over the merged index rather than in SQL, for three reasons: the
 * corpus is not in the database, the whole library is small enough that it does
 * not matter, and search has to keep working before the migration has run.
 *
 * @return array{rows:list<array>, total:int}
 */
function hymnal_library_search(?PDO $pdo, string $query, array $opts = []): array
{
    $rows = hymnal_library_index($pdo, !empty($opts['include_drafts']));

    $collection = (string) ($opts['collection'] ?? '');
    if ($collection !== '') {
        $rows = array_values(array_filter(
            $rows,
            static fn(array $r): bool => in_array($collection, $r['collections'] ?? [], true)
        ));
    }
    if (!empty($opts['score_only'])) {
        $rows = array_values(array_filter($rows, static fn(array $r): bool => !empty($r['score'])));
    }
    if (!empty($opts['numbers']) && is_array($opts['numbers'])) {
        $want = array_flip(array_map('intval', $opts['numbers']));
        $rows = array_values(array_filter($rows, static fn(array $r): bool => isset($want[(int) $r['n']])));
    }

    $q = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if ($q !== '') {
        $needle = mb_strtolower($q);
        $scored = [];
        foreach ($rows as $r) {
            $rank = hymnal_match_rank($r, $needle);
            if ($rank > 0) {
                $scored[] = ['rank' => $rank, 'row' => $r];
            }
        }
        usort($scored, static fn(array $a, array $b): int =>
            $b['rank'] <=> $a['rank'] ?: strcasecmp((string) $a['row']['title'], (string) $b['row']['title']));
        $rows = array_map(static fn(array $s): array => $s['row'], $scored);
    } else {
        $sort = (string) ($opts['sort'] ?? 'number');
        usort($rows, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'title') {
                return strcasecmp((string) $a['title'], (string) $b['title']) ?: ((int) $a['n'] <=> (int) $b['n']);
            }
            if ($sort === 'first_line') {
                return strcasecmp((string) ($a['first_line'] ?? ''), (string) ($b['first_line'] ?? ''))
                    ?: ((int) $a['n'] <=> (int) $b['n']);
            }

            return (int) $a['n'] <=> (int) $b['n'];
        });
    }

    $total = count($rows);
    $offset = max(0, (int) ($opts['offset'] ?? 0));
    $limit = (int) ($opts['limit'] ?? 0);
    if ($limit > 0) {
        $rows = array_slice($rows, $offset, min($limit, 200));
    }

    return ['rows' => array_values($rows), 'total' => $total];
}

/**
 * The shelves, each with how many songs are on it. Empty shelves are kept, so
 * the music team can see where a new song is wanted.
 *
 * @return list<array{slug:string, name:string, blurb:string, count:int}>
 */
function hymnal_library_collections(?PDO $pdo): array
{
    $counts = [];
    foreach (hymnal_library_index($pdo) as $r) {
        foreach ($r['collections'] ?? [] as $c) {
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }
    }

    $out = [];
    foreach (HYMNAL_COLLECTIONS as $slug => $c) {
        $out[] = [
            'slug'  => $slug,
            'name'  => $c['name'],
            'blurb' => $c['blurb'],
            'count' => $counts[$slug] ?? 0,
            'order' => $c['order'],
        ];
    }
    usort($out, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

    return $out;
}

/**
 * Which languages the library actually holds anything in. English is always
 * there because the corpus is English; Yoruba appears as soon as the music team
 * adds its first version, rather than sitting in the interface empty.
 *
 * @return list<array{lang:string, label:string, songs:int}>
 */
function hymnal_library_languages(?PDO $pdo): array
{
    $counts = ['en' => count(hymnal_corpus_index())];
    if ($pdo !== null && hymnal_table_exists($pdo, 'hymnal_song_versions')) {
        try {
            $sql = "SELECT v.lang, COUNT(*) AS n
                      FROM hymnal_song_versions v
                      JOIN hymnal_songs s ON s.id = v.song_id
                     WHERE s.status = 'published'
                     GROUP BY v.lang";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $lang = (string) $r['lang'];
                $counts[$lang] = ($counts[$lang] ?? 0) + (int) $r['n'];
            }
        } catch (Throwable $e) {
            error_log('Hymnary languages: ' . $e->getMessage());
        }
    }

    $out = [];
    foreach ($counts as $lang => $n) {
        if ($n <= 0) {
            continue;
        }
        $out[] = [
            'lang'  => $lang,
            'label' => HYMNAL_LANGUAGES[$lang] ?? strtoupper($lang),
            'songs' => $n,
        ];
    }
    usort($out, static fn(array $a, array $b): int => $b['songs'] <=> $a['songs']);

    return $out;
}

/**
 * A short list for the home screen: what the member marked, what they read
 * lately, and otherwise a few hymns to start from.
 */
function hymnal_library_shelf(?PDO $pdo, int $userId): array
{
    $favourites = hymnal_favourites($pdo, $userId);
    $recent = hymnal_recent($pdo, $userId, 8);

    $rowsByNumber = [];
    foreach (hymnal_library_index($pdo) as $r) {
        $rowsByNumber[(int) $r['n']] = $r;
    }

    $pick = static function (array $numbers) use ($rowsByNumber): array {
        $out = [];
        foreach ($numbers as $n) {
            if (isset($rowsByNumber[(int) $n])) {
                $out[] = $rowsByNumber[(int) $n];
            }
        }

        return $out;
    };

    return [
        'favourites' => $pick($favourites),
        'recent'     => $pick($recent),
    ];
}
