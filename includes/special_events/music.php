<?php
// /includes/special_events/music.php
//
// Portal music — the soft bed under /e/<slug> (guide §13.3 S0b).
//
// What this is NOT: autoplay. No browser shipped in the last decade will let
// a page make a sound before the visitor touches it, and no browser exposes
// whether the phone's ringer switch is on silent. Both of those are settled
// facts, not bugs to work around, so the design starts from them: the page
// loads silent, the first real gesture fades the music in at a low volume,
// and a visible control can always stop it (WCAG 2.1 SC 1.4.2 — any audio
// over three seconds needs one).
//
// Three rules this file enforces:
//
//   * Five tracks, no more (SE_MUSIC_MAX). Enforced inside the event lock so
//     two producers uploading at once cannot both see "four" and make six.
//   * Nothing reaches a public portal without `rights_confirmed`. The tick
//     is recorded against a named user and a timestamp, and
//     se_music_portal_list() filters on it a second time at read — a row
//     that somehow lost its tick goes quiet rather than playing.
//   * It degrades. The tree reaches production BEFORE migrations run
//     (AGENTS.md §9.4), so every read checks se_table_exists() first and a
//     portal whose se_music table is one deploy behind simply has no music.

/** True once the music table exists (§9.4). */
function se_music_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_music');
}

/**
 * The playlist of one event, in running order.
 *
 * Joined to se_assets because a track with no file is not a track: if the
 * asset was deleted the FK took the row with it, and an asset that was
 * soft-deleted (deleted_at) is excluded here.
 *
 * @return array<int, array> se_music rows with path/mime/bytes/duration_ms.
 */
function se_music_list(PDO $pdo, int $eventId): array
{
    if (!se_music_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT m.*, a.path, a.mime, a.bytes, a.duration_ms, a.title AS asset_title
           FROM se_music m
           JOIN se_assets a ON a.id = m.asset_id
          WHERE m.event_id = ? AND a.deleted_at IS NULL
       ORDER BY m.sort_order, m.id"
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll() ?: [];
}

/** One playlist row, or null. */
function se_music_find(PDO $pdo, int $eventId, int $trackId): ?array
{
    if (!se_music_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_music WHERE id = ? AND event_id = ?");
    $stmt->execute([$trackId, $eventId]);

    return $stmt->fetch() ?: null;
}

/** A playlist row shaped for the Studio. */
function se_music_payload(array $row): array
{
    return [
        'id'        => (int) $row['id'],
        'asset_id'  => (int) $row['asset_id'],
        'title'     => (string) $row['title'],
        'artist'    => (string) $row['artist'],
        'bpm'       => $row['bpm'] !== null ? (int) $row['bpm'] : null,
        'is_active' => (bool) $row['is_active'],
        'path'      => $row['path'] ?? null,
        'mime'      => $row['mime'] ?? null,
        'bytes'     => isset($row['bytes']) ? (int) $row['bytes'] : null,
        'rights'    => [
            'confirmed'    => (bool) $row['rights_confirmed'],
            'confirmed_at' => se_iso($row['rights_confirmed_at'] ?? null),
            'confirmed_by' => isset($row['rights_confirmed_by']) && $row['rights_confirmed_by'] !== null
                ? (int) $row['rights_confirmed_by'] : null,
        ],
        'created_at' => se_iso($row['created_at'] ?? null),
    ];
}

/**
 * The tracks a public portal may actually play.
 *
 * Active, rights-confirmed, with a real file behind them. The rights check
 * is repeated here rather than trusted from the write path, because this is
 * the gate that puts a URL in front of the public — and a gate that is only
 * enforced on the way in is not a gate.
 *
 * Pure, so the rule can be tested without a database (tests/music_test.php).
 *
 * @param array<int, array> $rows Rows from se_music_list().
 */
function se_music_playable(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!(int) ($row['is_active'] ?? 0) || !(int) ($row['rights_confirmed'] ?? 0)) {
            continue;
        }
        if ((string) ($row['path'] ?? '') === '') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/** Playable rows, shaped for the browser. Pure. */
function se_music_tracks(array $rows): array
{
    $tracks = [];
    foreach (se_music_playable($rows) as $row) {
        $title = (string) ($row['title'] ?? '');
        if ($title === '') {
            $title = (string) ($row['asset_title'] ?? '') ?: 'Track';
        }

        $tracks[] = [
            'src'    => (string) $row['path'],
            'title'  => $title,
            'artist' => (string) ($row['artist'] ?? ''),
            'bpm'    => isset($row['bpm']) && $row['bpm'] !== null ? (int) $row['bpm'] : null,
        ];
    }

    return $tracks;
}

/** Playable rows of one event. */
function se_music_portal_list(PDO $pdo, int $eventId): array
{
    return se_music_playable(se_music_list($pdo, $eventId));
}

/**
 * The `music` block of the portal boot payload (§8.6.2).
 *
 * `src` is all the browser needs; the title and artist travel too so the
 * control can name what is playing in its tooltip and aria-label. `bpm` is
 * only a fallback for the pulse animation — the live analyser is what
 * normally drives it — so a null here costs nothing.
 */
function se_music_boot(PDO $pdo, array $event, array $settings): array
{
    $off = ['enabled' => false, 'volume' => SE_MUSIC_DEFAULT_VOLUME, 'shuffle' => false, 'tracks' => []];

    if (!se_bool(se_settings_path($settings, 'portal.music_enabled', true))) {
        return $off;
    }

    $tracks = se_music_tracks(se_music_list($pdo, (int) $event['id']));

    if ($tracks === []) {
        return $off;
    }

    return [
        'enabled' => true,
        'volume'  => se_int(
            se_settings_path($settings, 'portal.music_volume', SE_MUSIC_DEFAULT_VOLUME),
            5, 100, SE_MUSIC_DEFAULT_VOLUME
        ),
        'shuffle' => se_bool(se_settings_path($settings, 'portal.music_shuffle', false)),
        'tracks'  => $tracks,
    ];
}

/**
 * Add an already-stored audio asset to the playlist.
 *
 * The cap is counted inside the event lock. Without it, two producers each
 * holding at four tracks would both pass the check and commit a sixth.
 *
 * @param array $data {title?, artist?, bpm?, rights_confirmed}
 */
function se_music_add(PDO $pdo, array $event, int $assetId, array $data, ?int $actorId): array
{
    if (!se_music_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Portal music is not available until the database is migrated.');
    }

    $eventId = (int) $event['id'];

    // The attestation is the whole point of the feature's governance: a track
    // cannot enter the playlist unattested, not even inactive.
    if (!se_bool($data['rights_confirmed'] ?? false)) {
        throw new SeValidationException(
            ['rights_confirmed' => 'Tick the box to confirm this track is clear to use.'],
            'Confirm the music rights first.'
        );
    }

    $asset = se_asset_find($pdo, $assetId);
    if (!$asset || $asset['deleted_at'] !== null || (int) $asset['event_id'] !== $eventId) {
        throw new SeNotFoundException('That audio file is no longer in this event.');
    }
    if ((string) $asset['kind'] !== 'audio') {
        throw new SeValidationException(['asset_id' => 'That file is not audio.']);
    }

    return se_lock_event($pdo, $eventId, function () use ($pdo, $eventId, $assetId, $asset, $data, $actorId) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM se_music WHERE event_id = ?");
        $countStmt->execute([$eventId]);
        $count = (int) $countStmt->fetchColumn();

        if ($count >= SE_MUSIC_MAX) {
            throw new SeRuleException('VALIDATION', sprintf(
                'The playlist holds %d tracks. Remove one before adding another.',
                SE_MUSIC_MAX
            ));
        }

        $title = se_line($data['title'] ?? '', 120);
        if ($title === '') {
            $title = se_line((string) ($asset['title'] ?? ''), 120) ?: 'Untitled track';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO se_music
                (event_id, asset_id, title, artist, bpm, sort_order, is_active,
                 rights_confirmed, rights_confirmed_by, rights_confirmed_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, NOW(), ?)"
        );
        $stmt->execute([
            $eventId,
            $assetId,
            $title,
            se_line($data['artist'] ?? '', 120),
            se_int_or_null($data['bpm'] ?? null, 40, 240),
            ($count + 1) * 10,
            $actorId,
            $actorId,
        ]);
        $trackId = (int) $pdo->lastInsertId();

        se_audit($pdo, $eventId, 'music_add', [
            'asset_id'         => $assetId,
            'title'            => $title,
            'rights_confirmed' => true,
        ], 'music', $trackId, $actorId);

        return se_music_list($pdo, $eventId);
    });
}

/** Rename a track, set its artist or BPM, or take it out of rotation. */
function se_music_update(PDO $pdo, array $event, int $trackId, array $data, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $track   = se_music_find($pdo, $eventId, $trackId);
    if (!$track) {
        throw new SeNotFoundException('That track is no longer in this playlist.');
    }

    $title = array_key_exists('title', $data)
        ? (se_line($data['title'], 120) ?: (string) $track['title'])
        : (string) $track['title'];

    $stmt = $pdo->prepare(
        "UPDATE se_music SET title = ?, artist = ?, bpm = ?, is_active = ? WHERE id = ? AND event_id = ?"
    );
    $stmt->execute([
        $title,
        array_key_exists('artist', $data) ? se_line($data['artist'], 120) : (string) $track['artist'],
        array_key_exists('bpm', $data)
            ? se_int_or_null($data['bpm'], 40, 240)
            : ($track['bpm'] !== null ? (int) $track['bpm'] : null),
        array_key_exists('is_active', $data) ? (se_bool($data['is_active']) ? 1 : 0) : (int) $track['is_active'],
        $trackId,
        $eventId,
    ]);

    se_audit($pdo, $eventId, 'music_update', ['title' => $title], 'music', $trackId, $actorId);

    return se_music_list($pdo, $eventId);
}

/**
 * Take a track out of the playlist and delete its file.
 *
 * The audio is removed as well as the row. A music asset has exactly one
 * purpose, so leaving the MP3 in the asset kit after the producer said
 * "remove" would only leave an unattested file lying in uploads/.
 */
function se_music_remove(PDO $pdo, array $event, int $trackId, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $track   = se_music_find($pdo, $eventId, $trackId);
    if (!$track) {
        throw new SeNotFoundException('That track is no longer in this playlist.');
    }

    $pdo->prepare("DELETE FROM se_music WHERE id = ? AND event_id = ?")->execute([$trackId, $eventId]);

    // Best effort: the playlist row is already gone, so a failure here must
    // not turn a successful removal into an error the producer has to retry.
    try {
        se_asset_delete($pdo, $eventId, (int) $track['asset_id'], $actorId);
    } catch (Throwable $e) {
        error_log('[se] music asset cleanup failed for track ' . $trackId . ': ' . $e->getMessage());
    }

    se_audit($pdo, $eventId, 'music_remove', [
        'title'    => $track['title'],
        'asset_id' => (int) $track['asset_id'],
    ], 'music', $trackId, $actorId);

    return se_music_list($pdo, $eventId);
}

/**
 * Move a track one place up or down.
 *
 * The same swap se_chapter_move() uses: two sort_order values exchanged, so
 * nothing else is renumbered and two quick clicks cannot interleave.
 */
function se_music_move(PDO $pdo, array $event, int $trackId, string $direction, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $track   = se_music_find($pdo, $eventId, $trackId);
    if (!$track) {
        throw new SeNotFoundException('That track is no longer in this playlist.');
    }

    $up = $direction !== 'down';

    return se_lock_event($pdo, $eventId, function () use ($pdo, $eventId, $track, $up, $trackId, $actorId) {
        $stmt = $pdo->prepare(
            $up
                ? "SELECT id, sort_order FROM se_music
                     WHERE event_id = ? AND (sort_order < ? OR (sort_order = ? AND id < ?))
                  ORDER BY sort_order DESC, id DESC LIMIT 1"
                : "SELECT id, sort_order FROM se_music
                     WHERE event_id = ? AND (sort_order > ? OR (sort_order = ? AND id > ?))
                  ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $order = (int) $track['sort_order'];
        $stmt->execute([$eventId, $order, $order, $trackId]);
        $neighbour = $stmt->fetch();

        if ($neighbour) {
            $update = $pdo->prepare("UPDATE se_music SET sort_order = ? WHERE id = ? AND event_id = ?");
            $theirs = (int) $neighbour['sort_order'];

            if ($order === $theirs) {
                $update->execute([$up ? $theirs - 1 : $theirs + 1, $trackId, $eventId]);
            } else {
                $update->execute([$theirs, $trackId, $eventId]);
                $update->execute([$order, (int) $neighbour['id'], $eventId]);
            }

            se_audit($pdo, $eventId, 'music_reorder', [
                'direction' => $up ? 'up' : 'down',
            ], 'music', $trackId, $actorId);
        }

        return se_music_list($pdo, $eventId);
    });
}
