<?php
// /includes/our_sermons/feeds.php
//
// The show's RSS feed(s), pasted by Envision, and the poller that turns each
// new episode into a draft. our_sermons_feeds_run() rides on
// cron/automation_tick.php (every 5 minutes, behind a function_exists()
// guard: no new cron line) and reads each feed at most every 15 minutes.
//
//   * Deduped by guid: the UNIQUE key on our_sermons.feed_guid, so two
//     overlapping runs never make two drafts; each feed is also claimed
//     (last_checked_at) before it is read. An episode Envision deleted stays
//     deleted (our_sermon_feed_skips).
//   * An episode Envision already pasted (same title, no feed episode yet) is
//     completed with the audio instead of becoming a second draft.
//   * At most OUR_SERMONS_FEED_MAX_NEW drafts per read, newest first; the
//     rest come on the next read (the feed's etag is kept only once it is all in).
//   * The people who check sermons hear once per read ("New sermon to check");
//     S3's our_sermons_ai_queue() is called for each new draft.

const OUR_SERMONS_FEED_EVERY_MINUTES = 15;
const OUR_SERMONS_FEED_MAX_NEW = 20;

/** The feeds, oldest first. */
function our_sermons_feeds(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM our_sermon_feeds' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY id';
    return array_map(static function (array $r): array {
        foreach (['id', 'is_active', 'items_seen', 'created_by'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        return $r;
    }, $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Fetch and read a feed (once per request per URL). $cond: etag,
 * last_modified for a conditional GET.
 *
 * @return array{ok:bool, not_modified:bool, title:string, image:?string, items:list<array>, etag:?string, last_modified:?string, error:string}
 */
function our_sermons_feed_read(string $url, array $cond = []): array
{
    static $cache = [];
    if (isset($cache[$url]) && empty($GLOBALS['our_sermons_no_feed_cache'])) {
        return $cache[$url];
    }
    $headers = [];
    if (!empty($cond['etag'])) {
        $headers[] = 'If-None-Match: ' . $cond['etag'];
    }
    if (!empty($cond['last_modified'])) {
        $headers[] = 'If-Modified-Since: ' . $cond['last_modified'];
    }
    $r = our_sermons_http_get($url, ['max_bytes' => OUR_SERMONS_FEED_MAX_BYTES, 'timeout' => 25, 'headers' => $headers,
        'accept' => 'application/rss+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5']);
    $out = ['ok' => false, 'not_modified' => false, 'title' => '', 'image' => null, 'items' => [], 'etag' => null,
        'last_modified' => null, 'error' => ''];
    if (!$r['ok']) {
        return ['error' => $r['error']] + $out;
    }
    if ($r['status'] === 304) {
        return ['ok' => true, 'not_modified' => true] + $out;
    }
    $parsed = our_sermons_feed_parse($r['body']);
    if (!$parsed['ok']) {
        return ['error' => $parsed['error']] + $out;
    }
    $out = ['ok' => true, 'title' => $parsed['title'], 'image' => $parsed['image'], 'items' => $parsed['items'],
        'etag' => isset($r['headers']['etag']) ? mb_substr($r['headers']['etag'], 0, 255) : null,
        'last_modified' => isset($r['headers']['last-modified']) ? mb_substr($r['headers']['last-modified'], 0, 64) : null] + $out;
    if ($cond === []) {
        $cache[$url] = $out;
    }
    return $out;
}

/** A feed link Envision pasted, checked to be a podcast feed, then read straight away. */
function our_sermons_feed_add(PDO $pdo, string $url, ?string $importFrom, int $uid): array
{
    $url = trim($url);
    if (!preg_match('#^https://[^\s"<>]{4,490}$#i', $url)) {
        return our_sermons_fail('Paste the feed link (it starts with https://).', 'INVALID', 'url');
    }
    if ($importFrom !== null && $importFrom !== '' && our_sermons_date($importFrom) === null) {
        return our_sermons_fail('Choose a date, or leave it empty.', 'INVALID', 'import_from');
    }
    $read = our_sermons_feed_read($url);
    if (!$read['ok']) {
        return our_sermons_fail($read['error'] ?: 'That link is not a podcast feed.', 'FEED', 'url');
    }
    $now = our_sermons_sql_dt();
    try {
        $pdo->prepare('INSERT INTO our_sermon_feeds (url, title, is_active, import_from, items_seen, created_by, created_at, updated_at)
                       VALUES (?, ?, 1, ?, ?, ?, ?, ?)')
            ->execute([$url, $read['title'] ?: null, our_sermons_date($importFrom) ?: null, count($read['items']), $uid, $now, $now]);
    } catch (PDOException $e) {
        return our_sermons_fail('That feed is already here.', 'DUPLICATE', 'url');
    }
    $id = (int) $pdo->lastInsertId();
    $poll = our_sermons_feed_poll($pdo, $id, ['force' => true]);
    return our_sermons_ok(['id' => $id, 'title' => $read['title'], 'episodes' => count($read['items']), 'new' => $poll['new'],
        'linked' => $poll['linked']]);
}

function our_sermons_feed_remove(PDO $pdo, int $id): array
{
    $pdo->prepare('DELETE FROM our_sermon_feeds WHERE id = ?')->execute([$id]);
    return our_sermons_ok(['id' => $id]);
}

/**
 * Read one feed and make drafts of its new episodes. Claims the feed first:
 * a feed read in the last 15 minutes is skipped (30 seconds with 'force',
 * Envision's Check now), so overlapping runs never read it twice.
 *
 * @return array{read:bool, new:int, linked:int, error:string, ids:list<int>}
 */
function our_sermons_feed_poll(PDO $pdo, int $feedId, array $opts = []): array
{
    $out = ['read' => false, 'new' => 0, 'linked' => 0, 'error' => '', 'ids' => []];
    $now = ($opts['now'] ?? null) instanceof DateTimeImmutable ? $opts['now'] : our_sermons_now();
    $due = $now->modify(!empty($opts['force']) ? '-30 seconds' : '-' . (OUR_SERMONS_FEED_EVERY_MINUTES - 1) . ' minutes')->format('Y-m-d H:i:s');
    $stamp = $now->format('Y-m-d H:i:s');
    $claim = $pdo->prepare('UPDATE our_sermon_feeds SET last_checked_at = ? WHERE id = ? AND is_active = 1
                             AND (last_checked_at IS NULL OR last_checked_at <= ?)');
    $claim->execute([$stamp, $feedId, $due]);
    if ($claim->rowCount() !== 1) {
        return $out;
    }
    $stmt = $pdo->prepare('SELECT * FROM our_sermon_feeds WHERE id = ?');
    $stmt->execute([$feedId]);
    $feed = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feed) {
        return $out;
    }
    $out['read'] = true;

    $read = our_sermons_feed_read((string) $feed['url'], ['etag' => $feed['etag'], 'last_modified' => $feed['last_modified']]);
    if (!$read['ok']) {
        $error = mb_substr($read['error'] ?: 'The feed could not be read.', 0, 255);
        if ((string) $feed['last_error'] !== $error) {
            // Said once when it starts failing, not every 15 minutes (each line becomes a monitor event).
            error_log('[our_sermons] feed ' . $feedId . ' could not be read: ' . $error);
        }
        $pdo->prepare('UPDATE our_sermon_feeds SET last_error = ?, updated_at = ? WHERE id = ?')->execute([$error, $stamp, $feedId]);
        $out['error'] = $error;
        return $out;
    }
    if ($read['not_modified']) {
        $pdo->prepare('UPDATE our_sermon_feeds SET last_ok_at = ?, last_error = NULL, updated_at = ? WHERE id = ?')->execute([$stamp, $stamp, $feedId]);
        return $out;
    }

    $items = $read['items'];
    usort($items, static fn ($a, $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
    // Episodes already here, and the ones Envision deleted (never brought back by the poller).
    $known = array_flip(array_map('strval', array_merge(
        $pdo->query('SELECT feed_guid FROM our_sermons WHERE feed_guid IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN),
        $pdo->query('SELECT feed_guid FROM our_sermon_feed_skips')->fetchAll(PDO::FETCH_COLUMN))));
    $from = $feed['import_from'] ? substr((string) $feed['import_from'], 0, 10) : null;
    $complete = true;
    $titles = [];
    foreach ($items as $item) {
        if (isset($known[$item['guid']]) || $item['audio_url'] === null) {
            continue;
        }
        if ($from !== null && $item['published_at'] !== null && substr($item['published_at'], 0, 10) < $from) {
            continue;
        }
        if ($out['new'] >= OUR_SERMONS_FEED_MAX_NEW) {
            $complete = false;
            break;
        }
        $fromFeed = [
            'audio_url' => $item['audio_url'],
            'duration_seconds' => $item['duration'],
            'feed_id' => $feedId,
            'feed_guid' => $item['guid'],
            'feed_title' => $item['title'],
            'feed_published_at' => $item['published_at'],
        ];
        $pasted = our_sermons_find_unlinked_by_title($pdo, $item['title']);
        if ($pasted !== null) {
            $fill = $fromFeed;
            $current = our_sermons_get($pdo, $pasted);
            if ($current !== null && $current['artwork_url'] === null && $item['image'] !== null) {
                $fill['artwork_source'] = $item['image'];
                $fill['artwork_url'] = our_sermons_artwork_for($pdo, $item['image']);
            }
            our_sermons_fill_missing($pdo, $pasted, $fill, null);
            $out['linked']++;
            $known[$item['guid']] = true;
            continue;
        }
        $parsed = our_sermons_parse_title($item['title']);
        $made = our_sermons_create($pdo, $fromFeed + [
            'title' => $parsed['title'],
            'preacher' => $parsed['preacher'],
            'service' => $parsed['service'],
            'series' => $parsed['series'],
            'date_preached' => $parsed['date'] ?? our_sermons_guess_sunday($item['published_at'] ?? $stamp),
            'date_source' => $parsed['date'] !== null ? 'title' : 'guess',
            'artwork_source' => $item['image'],
            'artwork_url' => our_sermons_artwork_for($pdo, $item['image']),
            'source' => 'feed',
        ], null);
        $known[$item['guid']] = true;
        if (!$made['existing']) {
            $out['new']++;
            $out['ids'][] = $made['id'];
            $titles[] = $parsed['title'];
        }
    }

    $pdo->prepare('UPDATE our_sermon_feeds SET title = COALESCE(?, title), items_seen = ?, last_ok_at = ?, last_error = NULL,
                          etag = ?, last_modified = ?, updated_at = ? WHERE id = ?')
        ->execute([$read['title'] ?: null, count($read['items']), $stamp, $complete ? $read['etag'] : null,
            $complete ? $read['last_modified'] : null, $stamp, $feedId]);

    if ($out['new'] > 0) {
        $n = $out['new'];
        our_sermons_notify_managers($pdo,
            $n === 1 ? 'New sermon to check' : $n . ' new sermons to check',
            mb_strimwidth($titles[0], 0, 120, '…', 'UTF-8') . ($n > 1 ? ' and ' . ($n - 1) . ' more' : ''),
            $n === 1 ? our_sermons_manage_url($out['ids'][0]) : our_sermons_manage_url());
    }
    return $out;
}

/**
 * The cron's entry (cron/automation_tick.php, every 5 minutes): every active
 * feed that is due. Options: now (DateTimeImmutable), dry_run (read nothing).
 *
 * @return array{checked:int, new:int, linked:int, errors:int}
 */
function our_sermons_feeds_run(PDO $pdo, array $opts = []): array
{
    $out = ['checked' => 0, 'new' => 0, 'linked' => 0, 'errors' => 0];
    if (!our_sermons_ready($pdo) || !empty($opts['dry_run'])) {
        return $out;
    }
    foreach (our_sermons_feeds($pdo, true) as $feed) {
        try {
            $r = our_sermons_feed_poll($pdo, (int) $feed['id'], ['now' => $opts['now'] ?? null]);
        } catch (Throwable $e) {
            error_log('[our_sermons] feed ' . (int) $feed['id'] . ': ' . $e->getMessage());
            $out['errors']++;
            continue;
        }
        if ($r['read']) {
            $out['checked']++;
        }
        $out['new'] += $r['new'];
        $out['linked'] += $r['linked'];
        $out['errors'] += $r['error'] !== '' ? 1 : 0;
    }
    return $out;
}
