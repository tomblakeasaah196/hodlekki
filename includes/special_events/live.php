<?php
// /includes/special_events/live.php
//
// The live backbone (guide §8.5): one row of control state per event, the
// snapshot builders of Appendix B, debounced atomic publishing, the heartbeat
// tick, display keys, scenes, announcements, sound cues — and test mode.
//
// Two rules shape everything here:
//
//   1. public.json is world-readable, so it NEVER contains a name, a phone,
//      an unrevealed answer or a charades phrase (§19.1). Names live only in
//      the key-protected room/team/lobby snapshots.
//   2. Every mutation goes through se_live_mutate(), which holds a row lock,
//      checks expected_version and bumps the version. Two consoles acting at
//      once therefore cannot silently overwrite each other: the second gets
//      STALE_VERSION and re-reads.
//
// Everything degrades: if the A.7 migration has not run yet, se_live_ready()
// is false and callers answer FEATURE_NOT_READY instead of fatalling (§9.4).

// --------------------------------------------------------------------------
// State
// --------------------------------------------------------------------------

/** The snapshot kinds se_live_publish() knows how to build. */
const SE_SNAPSHOT_KINDS = ['public', 'room', 'team', 'lobby'];

/** Is the live state table there yet? (§9.4 — code ships before migrations.) */
function se_live_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_live_state');
}

/**
 * The live state row for an event, created on first use.
 *
 * The three display keys are minted here rather than in a migration so that
 * an event cloned or created before this PR still gets its own keys the
 * first time anyone opens a console.
 */
function se_live_state(PDO $pdo, int $eventId, bool $create = true): ?array
{
    if (!se_live_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM se_live_state WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();

    if ($row || !$create) {
        return $row ?: null;
    }

    try {
        $insert = $pdo->prepare(
            "INSERT INTO se_live_state (event_id, room_key, lobby_key, stage_key)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE event_id = event_id"
        );
        $insert->execute([
            $eventId,
            se_random_token(SE_DISPLAY_KEY_LENGTH),
            se_random_token(SE_DISPLAY_KEY_LENGTH),
            se_random_token(SE_DISPLAY_KEY_LENGTH),
        ]);
    } catch (Throwable $e) {
        error_log('SE live/state: ' . $e->getMessage());
    }

    $stmt->execute([$eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Apply a change to the live state under a row lock.
 *
 * `$fn` receives the locked row and returns `column => value` pairs. The
 * version bump, the audit entry and the publish are done here so that no
 * caller can forget one of the three.
 *
 * @param callable(array, PDO): array $fn
 * @throws SeStaleVersionException when expected_version no longer matches
 */
function se_live_mutate(
    PDO $pdo,
    array $event,
    ?int $expectedVersion,
    callable $fn,
    ?string $auditAction = null,
    array $auditDetail = [],
    ?int $actorId = null,
    bool $publish = true
): array {
    $eventId = (int) $event['id'];

    if (!se_live_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'The live controls are not available yet.');
    }

    // Make sure the row exists before we try to lock it.
    se_live_state($pdo, $eventId);

    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM se_live_state WHERE event_id = ? FOR UPDATE");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new SeNotFoundException('That event is not live yet.');
        }

        if ($expectedVersion !== null && (int) $row['version'] !== $expectedVersion) {
            throw new SeStaleVersionException('Someone else just changed the show — check and retry.');
        }

        $changes = $fn($row, $pdo);

        $sets   = ['version = version + 1', 'updated_at = NOW(3)', 'updated_by = ?'];
        $params = [$actorId];
        foreach ($changes as $column => $value) {
            // Column names come from this file only, never from a request.
            if (!preg_match('/^[a-z_]+$/', (string) $column)) {
                continue;
            }
            $sets[]   = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $eventId;

        $pdo->prepare("UPDATE se_live_state SET " . implode(', ', $sets) . " WHERE event_id = ?")
            ->execute($params);

        $stmt->execute([$eventId]);
        $fresh = $stmt->fetch();

        if ($owns) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($auditAction !== null) {
        se_audit($pdo, $eventId, $auditAction, $auditDetail, 'event', $eventId, $actorId);
    }

    if ($publish) {
        se_live_publish($pdo, $eventId, true);
        $fresh = se_live_state($pdo, $eventId) ?? $fresh;
    }

    return $fresh;
}

/**
 * Rotate one or more display keys (§12.5 `display_keys_rotate`).
 *
 * The old snapshot files are deleted, which is what actually revokes the old
 * link: a projector left behind in a hall stops updating within a second.
 */
function se_live_keys_rotate(PDO $pdo, array $event, array $which, ?int $actorId): array
{
    $columns = array_values(array_intersect($which, ['room_key', 'lobby_key', 'stage_key']));
    if (!$columns) {
        $columns = ['room_key', 'lobby_key', 'stage_key'];
    }

    $before = se_live_state($pdo, (int) $event['id']);

    $fresh = se_live_mutate($pdo, $event, null, static function (array $row) use ($columns): array {
        $changes = [];
        foreach ($columns as $column) {
            $changes[$column] = se_random_token(SE_DISPLAY_KEY_LENGTH);
        }
        return $changes;
    }, 'keys_rotate', ['keys' => $columns], $actorId, false);

    // Remove the snapshots the old keys addressed, then republish under the
    // new names. Order matters: purge first, or we delete what we just wrote.
    $publicId = (string) $event['public_id'];
    if ($before) {
        if (in_array('room_key', $columns, true)) {
            @unlink((string) se_live_path($publicId, 'room-' . $before['room_key']));
        }
        if (in_array('lobby_key', $columns, true)) {
            @unlink((string) se_live_path($publicId, 'lobby-' . $before['lobby_key']));
        }
    }

    se_live_publish($pdo, (int) $event['id'], true);

    return $fresh;
}

// --------------------------------------------------------------------------
// Snapshots (§8.5.2, Appendix B)
// --------------------------------------------------------------------------

/** The `{v, t, e, kind, data}` envelope every snapshot shares. */
function se_snapshot_envelope(array $event, array $state, string $kind, array $data): array
{
    return [
        'v'    => (int) $state['version'],
        't'    => se_epoch_ms(),
        'e'    => (string) $event['public_id'],
        'kind' => $kind,
        'data' => $data,
    ];
}

/**
 * `public.json` — readable by anyone who knows the event's public id.
 *
 * Nothing in here may identify a person. The team rows carry counts, never
 * rosters; the arrival stream lives in the lobby snapshot behind a key.
 */
function se_snapshot_public(PDO $pdo, array $event, array $state, array $context = []): array
{
    $eventId  = (int) $event['id'];
    $days     = $context['days']     ?? se_event_days($pdo, $eventId);
    $settings = $context['settings'] ?? se_event_settings($event);
    $phase    = $context['phase']    ?? se_event_phase($event, $days);
    $counts   = $context['counts']   ?? se_capacity_counts($pdo, $eventId);
    $teams    = $context['teams']    ?? se_teams($pdo, $eventId);

    $today      = se_now()->format('Y-m-d');
    $teamCounts = se_team_counts($pdo, $eventId);
    $bg         = se_event_theme($event)['tokens']['--se-bg'] ?? '#0B0D13';

    $teamRows = [];
    foreach ($teams as $i => $team) {
        $tokens = se_team_tokens((string) $team['color_hex'], (string) $bg, $i);
        $teamRows[] = [
            'id'    => (int) $team['id'],
            'label' => (string) $team['color_label'],
            'name'  => $team['name'] !== null && $team['name'] !== '' ? (string) $team['name'] : null,
            'hex'   => $tokens['color'],
            'on'    => $tokens['on'],
            'ring'  => (bool) $tokens['needs_ring'],
            'n'     => (int) ($teamCounts[(int) $team['id']]['n'] ?? 0),
            // Scores are a PR5 ledger; the field exists now so the stage and
            // the phones never have to branch on its absence.
            'score' => 0,
            'rank'  => $i + 1,
        ];
    }

    return [
        'event' => [
            'phase'        => (string) ($phase['phase'] ?? 'upcoming'),
            'day'          => isset($phase['day']) ? (string) $phase['day']['day_date'] : null,
            'checkin_open' => (bool) ($phase['checkin_open'] ?? false),
            'test_mode'    => se_bool($settings['test_mode'] ?? false),
        ],
        'reg' => [
            'state'      => se_registration_state($event, $phase, $counts),
            'seats_left' => se_seats_left($event, $counts),
        ],
        'counts' => [
            'checked_in'       => se_checkin_total($pdo, $eventId),
            'checked_in_today' => se_checkin_total($pdo, $eventId, $today),
            'joined_games'     => se_joined_games_count($pdo, $eventId),
        ],
        'teams'   => $teamRows,
        // Public programme: public items only, times in the event's chosen
        // mode, and never a crew note (§10.7.2).
        'program' => se_program_ready($pdo)
            ? se_program_public($pdo, $event, $days, $settings)
            : null,
        // Songs, never singers: public.json is world-readable (§8.5.2).
        'karaoke' => se_karaoke_ready($pdo) ? se_karaoke_public($pdo, $event) : null,
        'scene'   => se_scene_payload($state),
        'game'    => null,
        'announcement' => se_announcement_payload($state),
        'sfx'     => ['seq' => (int) $state['sfx_seq'], 'cue' => $state['sfx_cue']],
    ];
}

/** `room-<room_key>.json` — display names allowed (checked-in devices only). */
function se_snapshot_room(PDO $pdo, array $event, array $state, array $context = []): array
{
    $eventId = (int) $event['id'];
    $teams   = $context['teams'] ?? se_teams($pdo, $eventId);

    $captains = [];
    foreach ($teams as $team) {
        if (empty($team['captain_registration_id'])) {
            continue;
        }
        $reg = se_registration_by_id($pdo, (int) $team['captain_registration_id'], $eventId);
        if ($reg) {
            $captains[(string) $team['id']] = (string) $reg['display_name'];
        }
    }

    return [
        // MVP, presenter and buzz winner arrive with PR5. The keys are
        // present and empty so the client shape never changes.
        'mvp'         => [],
        'karaoke'     => se_karaoke_ready($pdo) ? se_snapshot_karaoke_room($pdo, $event) : null,
        'presenter'   => null,
        'buzz_winner' => null,
        'captains'    => $captains,
    ];
}

/** `team-<team_key>.json` — one per team. */
function se_snapshot_team(PDO $pdo, array $event, array $state, array $team): array
{
    $eventId = (int) $event['id'];

    $captain = null;
    if (!empty($team['captain_registration_id'])) {
        $reg = se_registration_by_id($pdo, (int) $team['captain_registration_id'], $eventId);
        if ($reg) {
            $captain = [
                'name' => (string) $reg['display_name'],
                // An HMAC, so the captain's own phone can recognise itself
                // without the id ever being readable (Appendix B.3).
                'registration_id_hash' => substr(se_hmac('reg', (string) $reg['id']), 0, 16),
            ];
        }
    }

    $members = 0;
    $joined  = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM se_registrations
             WHERE event_id = ? AND team_id = ? AND status = 'confirmed'"
        );
        $stmt->execute([$eventId, (int) $team['id']]);
        $members = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT d.registration_id)
             FROM se_devices d
             JOIN se_registrations r ON r.id = d.registration_id
             WHERE d.event_id = ? AND r.team_id = ? AND d.joined_games_at IS NOT NULL"
        );
        $stmt->execute([$eventId, (int) $team['id']]);
        $joined = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE live/team snapshot: ' . $e->getMessage());
    }

    return [
        'team_id'        => (int) $team['id'],
        'captain'        => $captain,
        'suggestions'    => null,
        'captain_choice' => null,
        'members'        => $members,
        'joined'         => $joined,
    ];
}

/** `lobby-<lobby_key>.json` — the arrivals stream for the lobby screen. */
function se_snapshot_lobby(PDO $pdo, array $event, array $state, array $context = []): array
{
    $eventId  = (int) $event['id'];
    $days     = $context['days']     ?? se_event_days($pdo, $eventId);
    $settings = $context['settings'] ?? se_event_settings($event);
    $teams    = $context['teams']    ?? se_teams($pdo, $eventId);

    $showNames  = se_bool(se_settings_path($settings, 'lobby.show_names', true));
    $teamCounts = se_team_counts($pdo, $eventId);
    $bg         = se_event_theme($event)['tokens']['--se-bg'] ?? '#0B0D13';

    $teamRows = [];
    foreach ($teams as $i => $team) {
        $tokens = se_team_tokens((string) $team['color_hex'], (string) $bg, $i);
        $teamRows[] = [
            'id'    => (int) $team['id'],
            'label' => (string) $team['color_label'],
            'name'  => $team['name'] !== null && $team['name'] !== '' ? (string) $team['name'] : null,
            'hex'   => $tokens['color'],
            'on'    => $tokens['on'],
            'ring'  => (bool) $tokens['needs_ring'],
            'n'     => (int) ($teamCounts[(int) $team['id']]['n'] ?? 0),
        ];
    }

    $arrivals = [];
    if (se_table_exists($pdo, 'se_checkins')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT c.checked_in_at, r.display_name, r.team_id
                 FROM se_checkins c
                 JOIN se_registrations r ON r.id = c.registration_id
                 WHERE c.event_id = ?
                 ORDER BY c.checked_in_at DESC, c.id DESC
                 LIMIT 20"
            );
            $stmt->execute([$eventId]);
            foreach ($stmt->fetchAll() as $row) {
                $arrivals[] = [
                    'name'    => $showNames ? (string) $row['display_name'] : null,
                    'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
                    'at_ms'   => se_epoch_ms(se_parse_datetime($row['checked_in_at'])),
                ];
            }
        } catch (Throwable $e) {
            error_log('SE live/lobby snapshot: ' . $e->getMessage());
        }
    }

    $first = $days[0] ?? null;

    return [
        'checked_in' => se_checkin_total($pdo, $eventId, se_now()->format('Y-m-d')),
        'teams'      => $teamRows,
        'arrivals'   => $arrivals,
        'show_names' => $showNames,
        'starts_at'  => se_iso($first['starts_at'] ?? ($event['starts_at'] ?? null)),
        'doors_at'   => se_iso($first['doors_open_at'] ?? null),
        'checkin_url' => se_event_url((string) $event['slug'], 'in'),
    ];
}

/** The scene block of public.json, with the moment the scene was set. */
function se_scene_payload(array $state): array
{
    $stored = se_json_decode($state['scene_payload_json'] ?? null);

    return [
        'key'      => (string) $state['scene'],
        'since_ms' => isset($stored['since_ms']) ? (int) $stored['since_ms'] : null,
        'payload'  => $stored['payload'] ?? null,
    ];
}

/** The announcement block, or null once it has expired. */
function se_announcement_payload(array $state): ?array
{
    $stored = se_json_decode($state['announcement_json'] ?? null);
    if (!$stored || ($stored['text'] ?? '') === '') {
        return null;
    }

    $until = (int) ($stored['until_ms'] ?? 0);
    if ($until > 0 && $until < se_epoch_ms()) {
        return null;
    }

    return ['text' => (string) $stored['text'], 'until_ms' => $until ?: null];
}

// --------------------------------------------------------------------------
// Publishing (§8.5.6)
// --------------------------------------------------------------------------

/**
 * Build and write the requested snapshots.
 *
 * Debounced: a burst of writes (answers, buzzes) only marks the state dirty,
 * and the next tick — at most SE_SNAPSHOT_DEBOUNCE_MS later — does the work
 * once. Host actions pass `$force = true` because a person is waiting.
 */
function se_live_publish(PDO $pdo, int $eventId, bool $force = false, array $kinds = SE_SNAPSHOT_KINDS): bool
{
    if (!se_live_ready($pdo)) {
        return false;
    }

    try {
        $event = se_event_find($pdo, $eventId);
        if (!$event) {
            return false;
        }

        $state = se_live_state($pdo, $eventId);
        if (!$state) {
            return false;
        }

        if (!$force) {
            $last = se_parse_datetime($state['last_published_at'] ?? null);
            if ($last !== null && (se_epoch_ms() - se_epoch_ms($last)) < SE_SNAPSHOT_DEBOUNCE_MS) {
                $pdo->prepare("UPDATE se_live_state SET dirty = 1 WHERE event_id = ?")->execute([$eventId]);
                return false;
            }
        }

        $driver   = se_realtime_driver($event);
        $publicId = (string) $event['public_id'];

        $context = [
            'days'     => se_event_days($pdo, $eventId),
            'settings' => se_event_settings($event),
            'counts'   => se_capacity_counts($pdo, $eventId),
            'teams'    => se_teams($pdo, $eventId),
        ];
        $context['phase'] = se_event_phase($event, $context['days']);

        foreach ($kinds as $kind) {
            switch ($kind) {
                case 'public':
                    $driver->publish($publicId, 'public', se_snapshot_envelope(
                        $event, $state, 'public', se_snapshot_public($pdo, $event, $state, $context)
                    ));
                    break;

                case 'room':
                    $driver->publish($publicId, 'room-' . $state['room_key'], se_snapshot_envelope(
                        $event, $state, 'room', se_snapshot_room($pdo, $event, $state, $context)
                    ));
                    break;

                case 'lobby':
                    $driver->publish($publicId, 'lobby-' . $state['lobby_key'], se_snapshot_envelope(
                        $event, $state, 'lobby', se_snapshot_lobby($pdo, $event, $state, $context)
                    ));
                    break;

                case 'team':
                    foreach ($context['teams'] as $team) {
                        $driver->publish($publicId, 'team-' . $team['team_key'], se_snapshot_envelope(
                            $event, $state, 'team', se_snapshot_team($pdo, $event, $state, $team)
                        ));
                    }
                    break;

                default:
                    break;
            }
        }

        $pdo->prepare("UPDATE se_live_state SET dirty = 0, last_published_at = NOW(3) WHERE event_id = ?")
            ->execute([$eventId]);

        return true;
    } catch (Throwable $e) {
        // A failed publish must never break the action that triggered it.
        error_log('SE live/publish: ' . $e->getMessage());
        return false;
    }
}

/** Mark the state dirty without building anything (bursty writers). */
function se_live_touch(PDO $pdo, int $eventId): void
{
    if (!se_live_ready($pdo)) {
        return;
    }
    try {
        $pdo->prepare("UPDATE se_live_state SET dirty = 1 WHERE event_id = ?")->execute([$eventId]);
    } catch (Throwable $e) {
        error_log('SE live/touch: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// Heartbeat (§8.5.6)
// --------------------------------------------------------------------------

/**
 * One tick: time-based transitions, then a publish if anything is dirty.
 *
 * Guarded by GET_LOCK so the stage display and the host console — both
 * ticking at 1 Hz — never do the work twice. Correctness never depends on
 * the tick: every write validates its own deadlines. Only freshness does.
 */
function se_live_tick(PDO $pdo, array $event, ?int $actorId = null): array
{
    $eventId = (int) $event['id'];
    $state   = se_live_state($pdo, $eventId);

    if (!$state) {
        return ['server_ms' => se_epoch_ms(), 'v' => 0];
    }

    $lockName = 'se_tick_' . $eventId;
    $got      = false;

    try {
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$lockName]);
        $got = (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('SE live/tick lock: ' . $e->getMessage());
    }

    if (!$got) {
        return ['server_ms' => se_epoch_ms(), 'v' => (int) $state['version']];
    }

    try {
        $dirty = (int) $state['dirty'] === 1;

        // 1. Expire an announcement whose time is up.
        $announcement = se_json_decode($state['announcement_json'] ?? null);
        if ($announcement && (int) ($announcement['until_ms'] ?? 0) > 0
            && (int) $announcement['until_ms'] < se_epoch_ms()) {
            $pdo->prepare(
                "UPDATE se_live_state SET announcement_json = NULL, version = version + 1 WHERE event_id = ?"
            )->execute([$eventId]);
            $dirty = true;
        }

        // 2. Test mode must be off before doors open (§11.13).
        if (se_test_mode_due_off($pdo, $event)) {
            try {
                se_reset_rehearsal($pdo, $event, $actorId);
                se_test_mode_set($pdo, $event, false, $actorId, 'auto');
                $dirty = true;
            } catch (Throwable $e) {
                error_log('SE live/tick test-mode: ' . $e->getMessage());
            }
        }

        // 3. Karaoke holds whose release time has passed (§10.8.2). The cron
        //    does this too; the tick means the picker frees the song within
        //    a second rather than within five minutes.
        try {
            if (se_karaoke_release_holds($pdo, $event) > 0) {
                $dirty = true;
            }
        } catch (Throwable $e) {
            error_log('SE live/tick karaoke: ' . $e->getMessage());
        }

        // 4. PR5 adds round auto-lock, charades timeout and buzz settling
        //    here. They are time-based transitions on tables that do not
        //    exist yet, so the hook is the `dirty` flag below.

        if ($dirty) {
            se_live_publish($pdo, $eventId, true);
        }
    } finally {
        try {
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        } catch (Throwable $e) {
            error_log('SE live/tick unlock: ' . $e->getMessage());
        }
    }

    $fresh = se_live_state($pdo, $eventId) ?? $state;

    return ['server_ms' => se_epoch_ms(), 'v' => (int) $fresh['version']];
}

// --------------------------------------------------------------------------
// Host actions (§12.4)
// --------------------------------------------------------------------------

/** Set the stage scene (§11.12). */
function se_scene_set(PDO $pdo, array $event, string $scene, mixed $payload, ?int $expectedVersion, ?int $actorId): array
{
    if (!in_array($scene, SE_SCENES, true)) {
        throw new SeValidationException(['scene' => 'That scene does not exist.']);
    }

    $clean = se_scene_payload_clean($scene, is_array($payload) ? $payload : []);

    return se_live_mutate($pdo, $event, $expectedVersion, static fn(): array => [
        'scene'              => $scene,
        'scene_payload_json' => se_json_encode(['since_ms' => se_epoch_ms(), 'payload' => $clean]),
    ], 'scene_set', ['scene' => $scene], $actorId);
}

/** Keep only the fields §11.12 defines for each scene. */
function se_scene_payload_clean(string $scene, array $payload): ?array
{
    return match ($scene) {
        'welcome' => array_filter([
            'title'    => se_line($payload['title'] ?? '', 80) ?: null,
            'subtitle' => se_line($payload['subtitle'] ?? '', 120) ?: null,
        ], static fn($v) => $v !== null),
        'break' => [
            'minutes' => se_int($payload['minutes'] ?? 10, 1, 240, 10),
            'label'   => se_line($payload['label'] ?? 'Back soon', 60) ?: 'Back soon',
            'ends_ms' => se_epoch_ms() + se_int($payload['minutes'] ?? 10, 1, 240, 10) * 60000,
        ],
        'standby' => array_filter([
            'countdown_to' => se_line($payload['countdown_to'] ?? '', 40) ?: null,
        ], static fn($v) => $v !== null),
        'leaderboard' => ['show_mvp' => se_bool($payload['show_mvp'] ?? true)],
        default => null,
    };
}

/** Show a banner on every surface for `$seconds` (§11.12). */
function se_announce(PDO $pdo, array $event, string $text, int $seconds, ?int $expectedVersion, ?int $actorId): array
{
    $text = se_line($text, 140);
    if ($text === '') {
        throw new SeValidationException(['text' => 'Type the announcement first.']);
    }

    $seconds = max(5, min(600, $seconds));
    $until   = se_epoch_ms() + $seconds * 1000;

    return se_live_mutate($pdo, $event, $expectedVersion, static fn(): array => [
        'announcement_json' => se_json_encode(['text' => $text, 'until_ms' => $until, 'seconds' => $seconds]),
    ], 'scene_set:announce', ['seconds' => $seconds], $actorId);
}

/** Clear the banner early. */
function se_announce_clear(PDO $pdo, array $event, ?int $expectedVersion, ?int $actorId): array
{
    return se_live_mutate($pdo, $event, $expectedVersion, static fn(): array => [
        'announcement_json' => null,
    ], 'scene_set:announce_clear', [], $actorId);
}

/** Fire a sound cue. The stage plays it when `sfx.seq` increases (§11.12). */
function se_sound_cue(PDO $pdo, array $event, string $cue, ?int $expectedVersion, ?int $actorId): array
{
    if (!in_array($cue, SE_SFX_CUES, true)) {
        throw new SeValidationException(['cue' => 'That sound does not exist.']);
    }

    return se_live_mutate($pdo, $event, $expectedVersion, static fn(array $row): array => [
        'sfx_seq' => (int) $row['sfx_seq'] + 1,
        'sfx_cue' => $cue,
    ], 'scene_set:sound', ['cue' => $cue], $actorId);
}

// --------------------------------------------------------------------------
// Health and monitoring (§13.10 top bar, §18.3)
// --------------------------------------------------------------------------

/** Snapshot age, publishing state and the two background workers. */
function se_live_health(PDO $pdo, array $event, ?array $state = null): array
{
    $state ??= se_live_state($pdo, (int) $event['id']);

    $path = se_live_path((string) $event['public_id'], 'public');
    $age  = null;
    if ($path !== null && is_file($path)) {
        $age = max(0, time() - (int) @filemtime($path));
    }

    return [
        'snapshot_age_s' => $age,
        'version'        => $state ? (int) $state['version'] : 0,
        'dirty'          => $state ? (int) $state['dirty'] === 1 : false,
        'driver'         => get_class(se_realtime_driver($event)) === 'SeAblyDriver' ? 'ably' : 'poll',
        'sms_worker'     => se_sms_worker_fresh($pdo),
        // cron/special_events.php lands with PR4; until then there is nothing
        // to be stale, and the console shows the dot as "not applicable".
        'cron'           => null,
    ];
}

/**
 * Studio → Live monitor (§18.3): check-in pace, team balance, joined-games,
 * snapshot health and recent audit events.
 */
function se_live_monitor(PDO $pdo, array $event): array
{
    $eventId = (int) $event['id'];
    $state   = se_live_state($pdo, $eventId);
    $teams   = se_teams($pdo, $eventId);
    $counts  = se_team_counts($pdo, $eventId);

    $pace = [];
    if (se_table_exists($pdo, 'se_checkins')) {
        try {
            // Five-minute buckets over the last three hours.
            $stmt = $pdo->prepare(
                "SELECT FLOOR(UNIX_TIMESTAMP(checked_in_at) / 300) * 300 AS bucket, COUNT(*) AS n
                 FROM se_checkins
                 WHERE event_id = ? AND checked_in_at >= (NOW() - INTERVAL 3 HOUR)
                 GROUP BY bucket
                 ORDER BY bucket"
            );
            $stmt->execute([$eventId]);
            foreach ($stmt->fetchAll() as $row) {
                $pace[] = ['at_ms' => ((int) $row['bucket']) * 1000, 'n' => (int) $row['n']];
            }
        } catch (Throwable $e) {
            error_log('SE live/monitor pace: ' . $e->getMessage());
        }
    }

    $balance = [];
    foreach ($teams as $team) {
        $id = (int) $team['id'];
        $balance[] = [
            'id'       => $id,
            'label'    => (string) $team['color_label'],
            'name'     => $team['name'],
            'hex'      => (string) $team['color_hex'],
            'n'        => (int) ($counts[$id]['n'] ?? 0),
            'n_Male'   => (int) ($counts[$id]['n_Male'] ?? 0),
            'n_Female' => (int) ($counts[$id]['n_Female'] ?? 0),
            'n_member' => (int) ($counts[$id]['n_member'] ?? 0),
            'n_guest'  => (int) ($counts[$id]['n_guest'] ?? 0),
        ];
    }

    $audit = [];
    if (se_table_exists($pdo, 'se_audit_log')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT action, entity, entity_id, actor_user_id, created_at
                 FROM se_audit_log WHERE event_id = ? ORDER BY id DESC LIMIT 20"
            );
            $stmt->execute([$eventId]);
            foreach ($stmt->fetchAll() as $row) {
                $audit[] = [
                    'action' => (string) $row['action'],
                    'entity' => $row['entity'],
                    'at'     => se_iso($row['created_at']),
                    'actor'  => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                ];
            }
        } catch (Throwable $e) {
            error_log('SE live/monitor audit: ' . $e->getMessage());
        }
    }

    $confirmed = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'confirmed'");
        $stmt->execute([$eventId]);
        $confirmed = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE live/monitor counts: ' . $e->getMessage());
    }

    return [
        'scene'        => $state ? (string) $state['scene'] : null,
        'test_mode'    => se_bool(se_event_settings($event)['test_mode'] ?? false),
        'checked_in'   => se_checkin_total($pdo, $eventId),
        'confirmed'    => $confirmed,
        'joined_games' => se_joined_games_count($pdo, $eventId),
        'karaoke_queue' => null,
        'pace'         => $pace,
        'balance'      => $balance,
        'health'       => se_live_health($pdo, $event, $state),
        'audit'        => $audit,
        'displays'     => se_display_links($event, $state),
    ];
}

/** The three key-bearing display URLs, for Studio → Crew and Overview. */
function se_display_links(array $event, ?array $state): array
{
    if (!$state) {
        return [];
    }

    $slug = (string) $event['slug'];

    return [
        'stage' => se_event_url($slug, 'stage') . '?k=' . rawurlencode((string) $state['stage_key']),
        'lobby' => se_event_url($slug, 'lobby') . '?k=' . rawurlencode((string) $state['lobby_key']),
        'room'  => se_live_url((string) $event['public_id'], 'room-' . $state['room_key']),
    ];
}

// --------------------------------------------------------------------------
// Test mode and rehearsal reset (§11.13)
// --------------------------------------------------------------------------

/** Turn test mode on or off. It lives in the event's own settings. */
function se_test_mode_set(PDO $pdo, array $event, bool $on, ?int $actorId, string $how = 'manual'): array
{
    $settings = se_event_settings($event);
    $settings['test_mode'] = $on;

    $pdo->prepare("UPDATE se_events SET settings_json = ?, row_version = row_version + 1, updated_by = ? WHERE id = ?")
        ->execute([se_json_encode($settings), $actorId, (int) $event['id']]);

    se_audit($pdo, (int) $event['id'], 'test_mode', ['on' => $on, 'how' => $how], 'event', (int) $event['id'], $actorId);

    $fresh = se_event_find($pdo, (int) $event['id']) ?? $event;
    se_live_publish($pdo, (int) $event['id'], true);

    return $fresh;
}

/** Is it within 15 minutes of doors opening with test mode still on? */
function se_test_mode_due_off(PDO $pdo, array $event, ?DateTimeImmutable $now = null): bool
{
    if (!se_bool(se_event_settings($event)['test_mode'] ?? false)) {
        return false;
    }

    $now ??= se_now();
    foreach (se_event_days($pdo, (int) $event['id']) as $day) {
        $doors = se_parse_datetime($day['doors_open_at']);
        if ($doors === null) {
            continue;
        }
        $cutoff = $doors->modify('-15 minutes');
        if ($now >= $cutoff && $now <= $doors->modify('+6 hours')) {
            return true;
        }
    }

    return false;
}

/**
 * Reset rehearsal (§11.13): delete everything a test run created.
 *
 * Only the tables that exist today are touched; PR4 and PR5 extend the list
 * as their tables land. The counter reset is conditional: if a real check-in
 * has happened, player numbers must keep counting from where they are.
 */
function se_reset_rehearsal(PDO $pdo, array $event, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $removed = ['checkins' => 0, 'karaoke_entries' => 0, 'registrations' => 0, 'contacts' => 0, 'team_moves' => 0];

    se_lock_event($pdo, $eventId, static function (array $locked, PDO $pdo) use ($eventId, &$removed): void {
        // The registrations we are about to delete, captured before the rows
        // disappear so the contact cleanup below has something to work with.
        $regIds = [];
        $stmt = $pdo->prepare("SELECT id, contact_id FROM se_registrations WHERE event_id = ? AND is_test = 1");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $regIds[(int) $row['id']] = (int) $row['contact_id'];
        }

        if (se_table_exists($pdo, 'se_checkins')) {
            $stmt = $pdo->prepare("DELETE FROM se_checkins WHERE event_id = ? AND is_test = 1");
            $stmt->execute([$eventId]);
            $removed['checkins'] = $stmt->rowCount();
        }

        // Karaoke rows go before the registrations that own them:
        // fk_se_karaoke_reg is ON DELETE RESTRICT (§9.5 item 1).
        if (se_table_exists($pdo, 'se_karaoke_entries')) {
            $stmt = $pdo->prepare("DELETE FROM se_karaoke_entries WHERE event_id = ? AND is_test = 1");
            $stmt->execute([$eventId]);
            $removed['karaoke_entries'] = $stmt->rowCount();
        }

        if ($regIds) {
            $in = implode(',', array_fill(0, count($regIds), '?'));
            $ids = array_keys($regIds);

            if (se_table_exists($pdo, 'se_team_moves')) {
                $stmt = $pdo->prepare("DELETE FROM se_team_moves WHERE event_id = ? AND registration_id IN ({$in})");
                $stmt->execute(array_merge([$eventId], $ids));
                $removed['team_moves'] = $stmt->rowCount();
            }

            $pdo->prepare("DELETE FROM se_access_tokens WHERE registration_id IN ({$in})")->execute($ids);
            $pdo->prepare("UPDATE se_devices SET registration_id = NULL WHERE registration_id IN ({$in})")->execute($ids);

            $stmt = $pdo->prepare("DELETE FROM se_registrations WHERE event_id = ? AND is_test = 1");
            $stmt->execute([$eventId]);
            $removed['registrations'] = $stmt->rowCount();

            // A contact is only deleted when the rehearsal is the single
            // reason it exists: no other registration, no member link.
            foreach (array_unique(array_values($regIds)) as $contactId) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE contact_id = ?");
                $check->execute([$contactId]);
                if ((int) $check->fetchColumn() > 0) {
                    continue;
                }

                $member = $pdo->prepare("SELECT member_user_id FROM se_contacts WHERE id = ?");
                $member->execute([$contactId]);
                if ((int) ($member->fetchColumn() ?: 0) > 0) {
                    continue;
                }

                $del = $pdo->prepare("DELETE FROM se_contacts WHERE id = ?");
                $del->execute([$contactId]);
                $removed['contacts'] += $del->rowCount();
            }
        }

        // Counters go back to the start only when no real check-in happened.
        $real = 0;
        if (se_table_exists($pdo, 'se_checkins')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_checkins WHERE event_id = ? AND is_test = 0");
            $stmt->execute([$eventId]);
            $real = (int) $stmt->fetchColumn();
        }

        if ($real === 0) {
            $pdo->prepare(
                "UPDATE se_events SET next_player_no = 1, next_karaoke_no = 1, team_rr_pointer = 0 WHERE id = ?"
            )->execute([$eventId]);
            $pdo->prepare(
                "UPDATE se_registrations SET team_id = NULL, team_assigned_at = NULL, player_no = NULL,
                        first_checkin_at = NULL
                 WHERE event_id = ?"
            )->execute([$eventId]);
        }
    });

    se_audit($pdo, $eventId, 'test_mode:reset', $removed, 'event', $eventId, $actorId);

    $fresh = se_event_find($pdo, $eventId) ?? $event;
    se_live_publish($pdo, $eventId, true);

    return ['removed' => $removed, 'event' => $fresh];
}

// --------------------------------------------------------------------------
// Small shared counters
// --------------------------------------------------------------------------

/** How many people have checked in (optionally on one day). */
function se_checkin_total(PDO $pdo, int $eventId, ?string $day = null): int
{
    if (!se_table_exists($pdo, 'se_checkins')) {
        return 0;
    }

    try {
        if ($day === null) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT registration_id) FROM se_checkins WHERE event_id = ?");
            $stmt->execute([$eventId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_checkins WHERE event_id = ? AND day_date = ?");
            $stmt->execute([$eventId, $day]);
        }

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE live/checkin total: ' . $e->getMessage());
        return 0;
    }
}

/** How many devices have joined the games portal. */
function se_joined_games_count(PDO $pdo, int $eventId): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT registration_id) FROM se_devices
             WHERE event_id = ? AND registration_id IS NOT NULL AND joined_games_at IS NOT NULL"
        );
        $stmt->execute([$eventId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE live/joined games: ' . $e->getMessage());
        return 0;
    }
}

// --------------------------------------------------------------------------
// The crew payloads (§13.10, §13.11)
// --------------------------------------------------------------------------

/**
 * Everything the host console draws, in one round trip (§13.10).
 *
 * The console polls this once a second, so it has to be cheap and it has to
 * be complete: a half-loaded console in a dark room is worse than a slow
 * one. PR4's programme, game, round and karaoke blocks are present as null
 * so the client can ship its columns now and light them up later without a
 * shape change.
 */
function se_live_console(PDO $pdo, array $event): array
{
    $eventId  = (int) $event['id'];
    $state    = se_live_state($pdo, $eventId);
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);
    $theme    = se_event_theme($event);

    $window = se_checkin_ready($pdo)
        ? se_checkin_window($event, $days)
        : ['open' => false, 'reason' => 'FEATURE_NOT_READY'];

    $teams  = [];
    $roster = [];
    if (se_teams_ready($pdo)) {
        $counts = se_team_counts($pdo, $eventId);
        foreach (se_teams($pdo, $eventId) as $team) {
            $public = se_team_public($team, $theme);
            $public['n'] = (int) ($counts[(int) $team['id']]['n'] ?? 0);
            $public['captain_registration_id'] = $team['captain_registration_id'] !== null
                ? (int) $team['captain_registration_id']
                : null;
            $teams[] = $public;
        }

        // The roster carries names, which is why this endpoint is behind a
        // capability check and never becomes a snapshot (§8.5.4).
        foreach (se_teams_roster($pdo, $eventId) as $person) {
            $roster[] = [
                'registration_id' => (int) $person['registration_id'],
                'team_id'         => (int) $person['team_id'],
                'display_name'    => (string) $person['display_name'],
                'player_no'       => $person['player_no'] !== null ? (int) $person['player_no'] : null,
            ];
        }
    }

    $confirmed = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'confirmed'");
        $stmt->execute([$eventId]);
        $confirmed = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE live/console counts: ' . $e->getMessage());
    }

    $phase = se_event_phase($event, $days);

    return [
        'server_ms' => (int) round(microtime(true) * 1000),
        'event'     => [
            'public_id' => (string) $event['public_id'],
            'slug'      => (string) $event['slug'],
            'title'     => (string) $event['title'],
            'edition'   => (string) ($event['edition_label'] ?? ''),
            'phase'     => (string) $phase['phase'],
            'test_mode' => se_bool($settings['test_mode'] ?? false),
        ],
        'state' => $state ? [
            'version'       => (int) $state['version'],
            'scene'         => (string) $state['scene'],
            'scene_payload' => se_scene_payload($state),
            'announcement'  => se_announcement_payload($state),
            'sfx'           => ['seq' => (int) $state['sfx_seq'], 'cue' => $state['sfx_cue']],
        ] : null,
        'scenes' => SE_SCENES,
        'cues'   => SE_SFX_CUES,
        'teams'  => $teams,
        'roster' => $roster,
        'counts' => [
            'confirmed'        => $confirmed,
            'checked_in'       => se_checkin_total($pdo, $eventId),
            'checked_in_today' => se_checkin_ready($pdo)
                ? se_checkin_total($pdo, $eventId, se_checkin_day($phase))
                : 0,
            'joined_games'     => se_joined_games_count($pdo, $eventId),
        ],
        'checkin'  => $window,
        'health'   => se_live_health($pdo, $event, $state),
        'displays' => se_display_links($event, $state),

        'program'  => se_program_ready($pdo) ? se_program_payload($pdo, $event, $days) : null,
        'karaoke'  => se_karaoke_ready($pdo) ? se_karaoke_queue($pdo, $event) : null,

        // PR5 fills these in; the shape is fixed so the console can be
        // written once (§28.3).
        'game'     => null,
        'round'    => null,
    ];
}

/**
 * The karaoke block of the room snapshot: now, next and the short list, with
 * display names — which is why it lives behind the room key and not in
 * public.json (§8.5.2).
 */
function se_snapshot_karaoke_room(PDO $pdo, array $event): ?array
{
    $queue = se_karaoke_queue($pdo, $event);
    if (!$queue['ready']) {
        return null;
    }

    $short = [];
    foreach ($queue['entries'] as $entry) {
        if (!in_array($entry['status'], ['queued', 'up_next'], true)) {
            continue;
        }
        $short[] = [
            'queue_no' => $entry['queue_no'],
            'singer'   => $entry['singer'],
            'song'     => $entry['song']['title'],
            'artist'   => $entry['song']['artist'],
        ];
        if (count($short) >= 8) {
            break;
        }
    }

    $shape = static fn(?array $e): ?array => $e === null ? null : [
        'queue_no' => $e['queue_no'],
        'singer'   => $e['singer'],
        'song'     => $e['song']['title'],
        'artist'   => $e['song']['artist'],
    ];

    if ($queue['now'] === null && $queue['next'] === null && !$short) {
        return null;
    }

    return [
        'now'   => $shape($queue['now']),
        'next'  => $shape($queue['next']),
        'queue' => $short,
        'stats' => $queue['stats'],
    ];
}

/**
 * The much smaller payload desk mode polls (§13.11).
 *
 * The desk never shows the show, so it never gets the scene, the cues or
 * the announcement — only the door: how many are in, how many walk-in
 * places are left, and whether the window is open.
 */
function se_desk_state(PDO $pdo, array $event): array
{
    $eventId  = (int) $event['id'];
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);
    $counts   = se_capacity_counts($pdo, $eventId);
    $phase    = se_event_phase($event, $days);

    $teams = [];
    if (se_teams_ready($pdo)) {
        $theme      = se_event_theme($event);
        $teamCounts = se_team_counts($pdo, $eventId);
        foreach (se_teams($pdo, $eventId) as $team) {
            $public = se_team_public($team, $theme);
            $public['n'] = (int) ($teamCounts[(int) $team['id']]['n'] ?? 0);
            $teams[] = $public;
        }
    }

    return [
        'server_ms' => (int) round(microtime(true) * 1000),
        'test_mode' => se_bool($settings['test_mode'] ?? false),
        'checkin'   => se_checkin_ready($pdo)
            ? se_checkin_window($event, $days)
            : ['open' => false, 'reason' => 'FEATURE_NOT_READY'],
        'counts' => [
            'confirmed'        => (int) $counts['confirmed'],
            'checked_in_today' => se_checkin_ready($pdo)
                ? se_checkin_total($pdo, $eventId, se_checkin_day($phase))
                : 0,
            'walkin_taken'     => (int) $counts['walkin_taken'],
            'walkin_left'      => se_walkin_free($event, $counts),
        ],
        'teams'  => $teams,
        'health' => se_live_health($pdo, $event),
    ];
}
