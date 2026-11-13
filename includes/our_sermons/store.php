<?php
// /includes/our_sermons/store.php
//
// Sermons: making them (from a feed episode or a pasted link), checking them
// (save, publish, hide, delete) and reading them for the pages. Publishing
// tells everyone who can sign in, once per sermon (notified_at is claimed in
// the same transaction as the notifications); a new draft tells the people
// who check sermons (perm_holders(… 'edit')). The bell rows become phone
// pushes through the normal fan-out (AGENTS.md: never a second push path).

/** One sermon, with its JSON lists decoded. */
function our_sermons_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM our_sermons WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? our_sermons_shape($row) : null;
}

/** A row as pages use it: ints as ints, lists as lists. */
function our_sermons_shape(array $row): array
{
    foreach (['id', 'duration_seconds', 'feed_id', 'published_by', 'created_by', 'updated_by'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
    }
    $row['key_points'] = our_sermons_list($row['key_points'] ?? null);
    $row['scriptures'] = our_sermons_list($row['scriptures'] ?? null);
    $row['date_preached'] = substr((string) ($row['date_preached'] ?? ''), 0, 10);
    return $row;
}

/** The sermon already holding this feed episode, Spotify episode or YouTube video. */
function our_sermons_find_existing(PDO $pdo, ?string $guid, ?string $spotifyId, ?string $youtubeId): ?int
{
    foreach (['feed_guid' => $guid, 'spotify_episode_id' => $spotifyId, 'youtube_id' => $youtubeId] as $col => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $stmt = $pdo->prepare("SELECT id FROM our_sermons WHERE {$col} = ?");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
    }
    return null;
}

/**
 * A pasted sermon not yet tied to a feed episode whose title is this one's:
 * the same original title, or the same sermon title and date preached.
 */
function our_sermons_find_unlinked_by_title(PDO $pdo, string $rawTitle): ?int
{
    $key = our_sermons_match_key($rawTitle);
    $parsed = our_sermons_parse_title($rawTitle);
    $titleKey = our_sermons_match_key($parsed['title']);
    if ($key === '') {
        return null;
    }
    $stmt = $pdo->query("SELECT id, title, feed_title, date_preached FROM our_sermons WHERE feed_guid IS NULL ORDER BY id DESC LIMIT 500");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['feed_title'] !== null && our_sermons_match_key((string) $r['feed_title']) === $key) {
            return (int) $r['id'];
        }
        if ($parsed['date'] !== null && substr((string) $r['date_preached'], 0, 10) === $parsed['date']
            && $titleKey !== '' && our_sermons_match_key((string) $r['title']) === $titleKey) {
            return (int) $r['id'];
        }
    }
    return null;
}

/**
 * Our copy of a picture: the file another sermon already copied from the
 * same place (when it is still on disk), else a fresh copy.
 */
function our_sermons_artwork_for(PDO $pdo, ?string $source, ?array $hosts = null): ?string
{
    if ($source === null || $source === '') {
        return null;
    }
    $source = mb_substr($source, 0, 500);
    $stmt = $pdo->prepare('SELECT artwork_url FROM our_sermons WHERE artwork_source = ? AND artwork_url IS NOT NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$source]);
    $have = $stmt->fetchColumn();
    if (is_string($have) && $have !== '') {
        $dir = $GLOBALS['our_sermons_artwork_dir'] ?? (dirname(__DIR__, 2) . OUR_SERMONS_ARTWORK_URL);
        if (is_file(rtrim($dir, '/') . '/' . basename($have))) {
            return $have;
        }
    }
    return our_sermons_store_artwork($source, $hosts, $GLOBALS['our_sermons_artwork_dir'] ?? null);
}

/**
 * Insert a draft. $d: title, preacher, date_preached, date_source, service,
 * series, artwork_url, artwork_source, audio_url, duration_seconds,
 * spotify_url, spotify_episode_id, youtube_url, youtube_id, source, feed_id,
 * feed_guid, feed_title, feed_published_at. A UNIQUE key that is already
 * taken (the same episode or video) is not an error: the existing sermon's id
 * comes back with 'existing' => true. S3's AI queue is called for a new one.
 *
 * @return array{id:int, existing:bool}
 */
function our_sermons_create(PDO $pdo, array $d, ?int $uid): array
{
    $cols = ['title', 'preacher', 'date_preached', 'date_source', 'service', 'series', 'artwork_url', 'artwork_source', 'audio_url',
        'duration_seconds', 'spotify_url', 'spotify_episode_id', 'youtube_url', 'youtube_id', 'source', 'feed_id', 'feed_guid',
        'feed_title', 'feed_published_at'];
    $row = [];
    foreach ($cols as $c) {
        $row[$c] = isset($d[$c]) && $d[$c] !== '' ? $d[$c] : null;
    }
    $row['title'] = mb_substr((string) ($row['title'] ?? 'Untitled sermon'), 0, OUR_SERMONS_TITLE_MAX);
    $row['date_preached'] ??= our_sermons_guess_sunday(our_sermons_now()->format('Y-m-d'));
    $row['date_source'] ??= 'manual';
    $row['source'] = $row['source'] === 'feed' ? 'feed' : 'paste';
    $row['status'] = 'draft';
    $row['ai_status'] = function_exists('our_sermons_ai_queue') ? 'pending' : 'off';
    $row['created_by'] = $uid;
    $row['created_at'] = our_sermons_sql_dt();
    $row['updated_at'] = $row['created_at'];

    $names = array_keys($row);
    try {
        $pdo->prepare('INSERT INTO our_sermons (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($row));
    } catch (PDOException $e) {
        $existing = our_sermons_find_existing($pdo, $row['feed_guid'], $row['spotify_episode_id'], $row['youtube_id']);
        if ($existing !== null) {
            return ['id' => $existing, 'existing' => true];
        }
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    our_sermons_queue_ai($pdo, $id);
    return ['id' => $id, 'existing' => false];
}

/** S3's hook: draft the summary, key points and scriptures. Never stops the caller. */
function our_sermons_queue_ai(PDO $pdo, int $id): void
{
    if (!function_exists('our_sermons_ai_queue')) {
        return;
    }
    try {
        our_sermons_ai_queue($pdo, $id);
    } catch (Throwable $e) {
        error_log('[our_sermons] AI queue: ' . $e->getMessage());
    }
}

/**
 * Fill the empty fields of an existing sermon from a feed episode or a link
 * (never overwrites what Envision typed). Only the columns given.
 */
function our_sermons_fill_missing(PDO $pdo, int $id, array $d, ?int $uid): void
{
    $current = our_sermons_get($pdo, $id);
    if ($current === null) {
        return;
    }
    $set = [];
    $params = [];
    foreach (['artwork_url', 'artwork_source', 'audio_url', 'duration_seconds', 'spotify_url', 'spotify_episode_id', 'youtube_url',
                 'youtube_id', 'feed_id', 'feed_guid', 'feed_title', 'feed_published_at', 'preacher', 'service'] as $c) {
        if (!array_key_exists($c, $d) || $d[$c] === null || $d[$c] === '') {
            continue;
        }
        if ($current[$c] === null || $current[$c] === '') {
            $set[] = "{$c} = ?";
            $params[] = $d[$c];
        }
    }
    if ($set === []) {
        return;
    }
    $set[] = 'updated_at = ?';
    $params[] = our_sermons_sql_dt();
    $set[] = 'updated_by = ?';
    $params[] = $uid;
    $params[] = $id;
    try {
        $pdo->prepare('UPDATE our_sermons SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    } catch (PDOException $e) {
        // A Spotify episode or video that another sermon holds: leave this one as it was.
        error_log('[our_sermons] fill: ' . $e->getMessage());
    }
}

/**
 * The paste-a-link flow. Spotify: oEmbed for the title and artwork, then the
 * feed episode with the same title (audio, length, guid). YouTube: oEmbed for
 * the title and thumbnail. A sermon that already holds the episode, the video
 * or (for Spotify) its feed episode is completed and returned instead of a
 * second copy.
 *
 * @return array status/id/existing, or a failure
 */
function our_sermons_add_link(PDO $pdo, string $url, int $uid): array
{
    $link = our_sermons_parse_link($url);
    if ($link === null) {
        return our_sermons_fail('Paste a Spotify episode or YouTube video link.', 'INVALID', 'link');
    }
    $isSpotify = $link['provider'] === 'spotify';
    $already = our_sermons_find_existing($pdo, null, $isSpotify ? $link['id'] : null, $isSpotify ? null : $link['id']);
    if ($already !== null) {
        return our_sermons_ok(['id' => $already, 'existing' => true]);
    }

    $meta = our_sermons_oembed($link);
    if (!$meta['ok']) {
        return our_sermons_fail($meta['error'], 'LOOKUP', 'link');
    }
    $parsed = our_sermons_parse_title($meta['title']);
    $d = [
        'title' => $parsed['title'],
        'preacher' => $parsed['preacher'],
        'service' => $parsed['service'],
        'series' => $parsed['series'],
        'feed_title' => $meta['title'],
        'source' => 'paste',
    ];
    if ($isSpotify) {
        $d['spotify_url'] = $link['url'];
        $d['spotify_episode_id'] = $link['id'];
    } else {
        $d['youtube_url'] = $link['url'];
        $d['youtube_id'] = $link['id'];
    }

    $item = $isSpotify ? our_sermons_feed_lookup($pdo, $meta['title']) : null;
    if ($item !== null) {
        $d += [
            'audio_url' => $item['audio_url'],
            'duration_seconds' => $item['duration'],
            'feed_id' => $item['feed_id'],
            'feed_guid' => $item['guid'],
            'feed_published_at' => $item['published_at'],
        ];
        $held = our_sermons_find_existing($pdo, $item['guid'], null, null);
        if ($held !== null) {
            our_sermons_fill_missing($pdo, $held, $d, $uid);
            return our_sermons_ok(['id' => $held, 'existing' => true]);
        }
    }

    $artSource = $meta['thumbnail'] ?? ($item['image'] ?? null);
    $d['artwork_source'] = $artSource;
    $d['artwork_url'] = our_sermons_artwork_for($pdo, $artSource, $meta['thumbnail'] !== null ? OUR_SERMONS_THUMB_HOSTS : null);
    if ($parsed['date'] !== null) {
        $d['date_preached'] = $parsed['date'];
        $d['date_source'] = 'title';
    } else {
        $from = $item['published_at'] ?? our_sermons_sql_dt();
        $d['date_preached'] = our_sermons_guess_sunday($from);
        $d['date_source'] = 'guess';
    }
    $made = our_sermons_create($pdo, $d, $uid);
    if ($made['existing']) {
        our_sermons_fill_missing($pdo, $made['id'], $d, $uid);
    }
    return our_sermons_ok(['id' => $made['id'], 'existing' => $made['existing']]);
}

/**
 * The episode in one of the active feeds whose title is $title (folded the
 * same way), or failing that the same sermon title and date preached.
 *
 * @return array|null the feed item plus feed_id
 */
function our_sermons_feed_lookup(PDO $pdo, string $title): ?array
{
    $key = our_sermons_match_key($title);
    $parsed = our_sermons_parse_title($title);
    $titleKey = our_sermons_match_key($parsed['title']);
    foreach (our_sermons_feeds($pdo, true) as $feed) {
        $read = our_sermons_feed_read($feed['url']);
        if (!$read['ok']) {
            continue;
        }
        $second = null;
        foreach ($read['items'] as $item) {
            if (our_sermons_match_key($item['title']) === $key) {
                return $item + ['feed_id' => (int) $feed['id']];
            }
            if ($second === null && $parsed['date'] !== null) {
                $p = our_sermons_parse_title($item['title']);
                if ($p['date'] === $parsed['date'] && our_sermons_match_key($p['title']) === $titleKey) {
                    $second = $item + ['feed_id' => (int) $feed['id']];
                }
            }
        }
        if ($second !== null) {
            return $second;
        }
    }
    return null;
}

/**
 * The editor's fields, checked. Returns the clean columns, or a failure with
 * the field to put it under.
 *
 * @return array{ok:bool, cols?:array, fail?:array}
 */
function our_sermons_clean_input(PDO $pdo, int $id, array $in): array
{
    $bad = static fn (string $msg, string $field) => ['ok' => false, 'fail' => our_sermons_fail($msg, 'INVALID', $field)];
    $title = our_sermons_text($in['title'] ?? '', OUR_SERMONS_TITLE_MAX);
    if ($title === '') {
        return $bad('Give the sermon a title.', 'title');
    }
    $date = our_sermons_date($in['date_preached'] ?? '');
    if ($date === null) {
        return $bad('Choose the day it was preached.', 'date_preached');
    }
    if ($date > our_sermons_now()->modify('+1 day')->format('Y-m-d')) {
        return $bad('The day it was preached cannot be in the future.', 'date_preached');
    }
    $service = trim((string) ($in['service'] ?? ''));
    if ($service !== '' && !isset(OUR_SERMONS_SERVICES[$service])) {
        return $bad('Choose a service from the list.', 'service');
    }

    $lines = static function ($value): array {
        $text = str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string) $value : '');
        return array_values(array_filter(array_map(static fn ($l) => trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $l)),
            explode("\n", $text)), static fn ($l) => $l !== ''));
    };
    $points = array_map(static fn ($p) => our_sermons_text($p, OUR_SERMONS_POINT_MAX), $lines($in['key_points'] ?? ''));
    if (count($points) > OUR_SERMONS_POINTS_MAX) {
        return $bad('Keep it to ' . OUR_SERMONS_POINTS_MAX . ' key points.', 'key_points');
    }
    $scriptures = [];
    foreach ($lines(str_replace(';', "\n", (string) ($in['scriptures'] ?? ''))) as $ref) {
        $norm = our_sermons_scripture($ref);
        if ($norm === null) {
            return $bad('Check this scripture: ' . mb_substr($ref, 0, 40), 'scriptures');
        }
        if (!in_array($norm, $scriptures, true)) {
            $scriptures[] = $norm;
        }
    }
    if (count($scriptures) > OUR_SERMONS_SCRIPTURES_MAX) {
        return $bad('Keep it to ' . OUR_SERMONS_SCRIPTURES_MAX . ' scriptures.', 'scriptures');
    }

    $cols = [
        'title' => $title,
        // Tidied like a parsed name ("PASTOR EBELE UZo-Peters" → "Pastor Ebele Uzo-Peters"), so one preacher is one filter.
        'preacher' => our_sermons_name_case(our_sermons_text($in['preacher'] ?? '', OUR_SERMONS_PREACHER_MAX)) ?: null,
        'date_preached' => $date,
        'date_source' => 'manual',
        'service' => $service !== '' ? $service : null,
        'series' => our_sermons_text($in['series'] ?? '', OUR_SERMONS_SERIES_MAX) ?: null,
        'summary' => our_sermons_text($in['summary'] ?? '', OUR_SERMONS_SUMMARY_MAX, true) ?: null,
        'key_points' => $points === [] ? null : our_sermons_json($points),
        'scriptures' => $scriptures === [] ? null : our_sermons_json($scriptures),
    ];

    foreach (['youtube' => 'youtube_url', 'spotify' => 'spotify_url'] as $provider => $field) {
        if (!array_key_exists($field, $in)) {
            continue;
        }
        $raw = trim((string) $in[$field]);
        $idCol = $provider === 'youtube' ? 'youtube_id' : 'spotify_episode_id';
        if ($raw === '') {
            $cols[$field] = null;
            $cols[$idCol] = null;
            continue;
        }
        $link = our_sermons_parse_link($raw);
        if ($link === null || $link['provider'] !== $provider) {
            return $bad($provider === 'youtube' ? 'Paste a YouTube video link.' : 'Paste a Spotify episode link.', $field);
        }
        $other = our_sermons_find_existing($pdo, null, $provider === 'spotify' ? $link['id'] : null, $provider === 'youtube' ? $link['id'] : null);
        if ($other !== null && $other !== $id) {
            return $bad('That link is already on another sermon.', $field);
        }
        $cols[$field] = $link['url'];
        $cols[$idCol] = $link['id'];
    }
    return ['ok' => true, 'cols' => $cols];
}

/** "Jn 3.16" → "John 3:16" with the Bible module's parser; any non-empty text without it. */
function our_sermons_scripture(string $ref): ?string
{
    $ref = our_sermons_text($ref, 60);
    if ($ref === '') {
        return null;
    }
    $books = dirname(__DIR__) . '/bible/books.php';
    if (!function_exists('bible_parse_ref') && is_file($books)) {
        require_once $books;
    }
    if (!function_exists('bible_parse_ref') || !function_exists('bible_format_ref')) {
        return $ref;
    }
    try {
        $r = bible_parse_ref($ref);
    } catch (Throwable $e) {
        return $ref;
    }
    if ($r === null) {
        return null;
    }
    return bible_format_ref($r['book'], $r['chapter'], $r['verse'], $r['chapter_end'], $r['verse_end']);
}

/** Write the editor's fields. */
function our_sermons_save(PDO $pdo, int $id, array $in, int $uid): array
{
    $s = our_sermons_get($pdo, $id);
    if ($s === null) {
        return our_sermons_fail('That sermon is no longer here.', 'NOT_FOUND');
    }
    $clean = our_sermons_clean_input($pdo, $id, $in);
    if (!$clean['ok']) {
        return $clean['fail'];
    }
    $cols = $clean['cols'] + ['updated_at' => our_sermons_sql_dt(), 'updated_by' => $uid];
    $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($cols)));
    try {
        $pdo->prepare("UPDATE our_sermons SET {$set} WHERE id = ?")->execute([...array_values($cols), $id]);
    } catch (PDOException $e) {
        return our_sermons_fail('That link is already on another sermon.', 'INVALID', 'youtube_url');
    }
    return our_sermons_ok(['id' => $id]);
}

/**
 * Save, then publish. The first time a sermon is published, everyone who can
 * sign in is told (in the bell and on their phone), exactly once.
 */
function our_sermons_publish(PDO $pdo, int $id, array $in, int $uid): array
{
    $saved = our_sermons_save($pdo, $id, $in, $uid);
    if ($saved['status'] !== 'success') {
        return $saved;
    }
    $s = our_sermons_get($pdo, $id);
    if (($s['audio_url'] ?? '') === '' && ($s['youtube_id'] ?? '') === '' && ($s['spotify_url'] ?? '') === '') {
        return our_sermons_fail('Add the audio, a Spotify or a YouTube link first.', 'NO_MEDIA', 'youtube_url');
    }
    $now = our_sermons_sql_dt();
    $pdo->prepare("UPDATE our_sermons SET status = 'published', published_at = COALESCE(published_at, ?), published_by = COALESCE(published_by, ?),
                          updated_at = ?, updated_by = ? WHERE id = ?")
        ->execute([$now, $uid, $now, $uid, $id]);
    $told = our_sermons_notify_everyone($pdo, $id);
    return our_sermons_ok(['id' => $id, 'notified' => $told]);
}

/** Back out of members' sight (a draft or a published sermon). */
function our_sermons_hide(PDO $pdo, int $id, int $uid): array
{
    if (our_sermons_get($pdo, $id) === null) {
        return our_sermons_fail('That sermon is no longer here.', 'NOT_FOUND');
    }
    $pdo->prepare("UPDATE our_sermons SET status = 'hidden', updated_at = ?, updated_by = ? WHERE id = ?")
        ->execute([our_sermons_sql_dt(), $uid, $id]);
    return our_sermons_ok(['id' => $id]);
}

/**
 * Remove a sermon for good, and its artwork when no other sermon shows it. A
 * feed episode is remembered in our_sermon_feed_skips, so the poller does not
 * bring it back 15 minutes later (Paste link still can).
 */
function our_sermons_delete(PDO $pdo, int $id, ?int $uid = null): array
{
    $s = our_sermons_get($pdo, $id);
    if ($s === null) {
        return our_sermons_fail('That sermon is no longer here.', 'NOT_FOUND');
    }
    $pdo->prepare('DELETE FROM our_sermons WHERE id = ?')->execute([$id]);
    if ($s['feed_guid'] !== null) {
        try {
            $pdo->prepare('INSERT INTO our_sermon_feed_skips (feed_guid, title, created_by, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$s['feed_guid'], mb_substr((string) ($s['feed_title'] ?? $s['title']), 0, 255), $uid, our_sermons_sql_dt()]);
        } catch (PDOException $e) {
            // Already skipped once (pasted back, then deleted again): nothing to add.
        }
    }
    if ($s['artwork_url'] !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM our_sermons WHERE artwork_url = ?');
        $stmt->execute([$s['artwork_url']]);
        if ((int) $stmt->fetchColumn() === 0) {
            our_sermons_artwork_delete($s['artwork_url'], $GLOBALS['our_sermons_artwork_dir'] ?? null);
        }
    }
    return our_sermons_ok(['id' => $id]);
}

/** Everyone who can sign in now: an account with a password, active, not relocated. */
function our_sermons_audience(PDO $pdo): array
{
    $sqls = [
        "SELECT id FROM users WHERE password_hash IS NOT NULL AND password_hash <> ''
            AND COALESCE(account_status, 'active') = 'active' AND COALESCE(attendance_status, '') <> 'Relocated' ORDER BY id",
        "SELECT id FROM users WHERE password_hash IS NOT NULL AND password_hash <> '' ORDER BY id",
    ];
    foreach ($sqls as $sql) {
        try {
            return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            continue;
        }
    }
    return [];
}

/**
 * "New sermon: Ground Zero" / "Pastor Ebele Uzo-Peters · Sun 8 Nov" to
 * everyone who can sign in, opening the sermon. Claimed through notified_at
 * in one transaction with the rows, so it happens once (a second Publish, a
 * re-publish after Hide, two presses at once: nothing more).
 *
 * @return int how many were told (0 when already told)
 */
function our_sermons_notify_everyone(PDO $pdo, int $id): int
{
    if (!our_sermons_table_exists($pdo, 'system_notifications')) {
        return 0;
    }
    $s = our_sermons_get($pdo, $id);
    if ($s === null || $s['status'] !== 'published' || $s['notified_at'] !== null) {
        return 0;
    }
    $title = 'New sermon: ' . mb_strimwidth($s['title'], 0, 120, '…', 'UTF-8');
    $message = implode(' · ', array_filter([(string) $s['preacher'], our_sermons_date_label($s['date_preached'])]));
    $link = our_sermons_url($id);
    $audience = our_sermons_audience($pdo);
    $told = 0;
    try {
        $pdo->beginTransaction();
        $claim = $pdo->prepare('UPDATE our_sermons SET notified_at = ? WHERE id = ? AND notified_at IS NULL');
        $claim->execute([our_sermons_sql_dt(), $id]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return 0;
        }
        foreach (array_chunk($audience, 200) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $uid) {
                array_push($params, $uid, $title, $message, $link);
            }
            $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES {$values}")->execute($params);
            $told += count($chunk);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[our_sermons] publish notice: ' . $e->getMessage());
        return 0;
    }
    return $told;
}

/** Tell the people who check sermons (Edit on 'our_sermons', not the Super Admins). */
function our_sermons_notify_managers(PDO $pdo, string $title, string $message, string $link): int
{
    if (!function_exists('perm_holders') || !our_sermons_table_exists($pdo, 'system_notifications')) {
        return 0;
    }
    $ids = perm_holders($pdo, 'our_sermons', 'edit');
    $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($ids as $id) {
        try {
            $stmt->execute([(int) $id, mb_substr($title, 0, 190), $message, $link]);
            $n++;
        } catch (Throwable $e) {
            error_log('[our_sermons] manager notice: ' . $e->getMessage());
        }
    }
    return $n;
}

/** LIKE pattern with ! as the escape character. */
function our_sermons_like(string $q): string
{
    return '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/**
 * Published sermons for the members' list, newest preached first.
 * $f: q, preacher, series, service.
 *
 * @return array{rows:list<array>, total:int}
 */
function our_sermons_search(PDO $pdo, array $f, int $page = 1, int $per = OUR_SERMONS_PAGE_SIZE): array
{
    [$where, $params] = our_sermons_filters_sql($f);
    $count = $pdo->prepare("SELECT COUNT(*) FROM our_sermons WHERE {$where}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $per = max(1, min(100, $per));
    $offset = max(0, ($page - 1) * $per);
    $stmt = $pdo->prepare("SELECT * FROM our_sermons WHERE {$where} ORDER BY date_preached DESC, id DESC LIMIT {$per} OFFSET {$offset}");
    $stmt->execute($params);
    return ['rows' => array_map('our_sermons_shape', $stmt->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total];
}

/** @return array{0:string, 1:list<string>} */
function our_sermons_filters_sql(array $f): array
{
    $where = ["status = 'published'"];
    $params = [];
    $q = our_sermons_text($f['q'] ?? '', 80);
    if ($q !== '') {
        $where[] = "(title LIKE ? ESCAPE '!' OR preacher LIKE ? ESCAPE '!' OR series LIKE ? ESCAPE '!' OR summary LIKE ? ESCAPE '!')";
        array_push($params, ...array_fill(0, 4, our_sermons_like($q)));
    }
    foreach (['preacher', 'series', 'service'] as $col) {
        $v = our_sermons_text($f[$col] ?? '', 120);
        if ($v !== '') {
            $where[] = "{$col} = ?";
            $params[] = $v;
        }
    }
    return [implode(' AND ', $where), $params];
}

/** The choices for the members' filters: preachers, series and services that published sermons use. */
function our_sermons_filter_options(PDO $pdo): array
{
    $col = static function (string $c) use ($pdo): array {
        return array_map('strval', $pdo->query("SELECT DISTINCT {$c} FROM our_sermons WHERE status = 'published' AND {$c} IS NOT NULL AND {$c} <> '' ORDER BY {$c}")
            ->fetchAll(PDO::FETCH_COLUMN));
    };
    $services = array_values(array_intersect(array_keys(OUR_SERMONS_SERVICES), $col('service')));
    return ['preachers' => $col('preacher'), 'series' => $col('series'), 'services' => $services];
}

/** Published sermons preached in one month ('Y-m'), by day. */
function our_sermons_month(PDO $pdo, string $ym, array $f = []): array
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) {
        return [];
    }
    $first = $ym . '-01';
    $last = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
    [$where, $params] = our_sermons_filters_sql($f);
    $stmt = $pdo->prepare("SELECT * FROM our_sermons WHERE {$where} AND date_preached >= ? AND date_preached <= ? ORDER BY date_preached, id");
    $stmt->execute([...$params, $first, $last]);
    $days = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = our_sermons_shape($row);
        $days[$row['date_preached']][] = $row;
    }
    return $days;
}

/** Envision's lists: drafts first-come, published and hidden newest preached first. */
function our_sermons_manage_list(PDO $pdo, string $status, int $limit = 200): array
{
    if (!isset(OUR_SERMONS_STATUSES[$status])) {
        return [];
    }
    $order = $status === 'draft' ? 'created_at DESC, id DESC' : 'date_preached DESC, id DESC';
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("SELECT * FROM our_sermons WHERE status = ? ORDER BY {$order} LIMIT {$limit}");
    $stmt->execute([$status]);
    return array_map('our_sermons_shape', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** status => count */
function our_sermons_counts(PDO $pdo): array
{
    $out = array_fill_keys(array_keys(OUR_SERMONS_STATUSES), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM our_sermons GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['status']] = (int) $r['n'];
    }
    return $out;
}
