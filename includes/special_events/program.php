<?php
// /includes/special_events/program.php
//
// The run of show (guide §10.7): the planned timeline, the live ETA engine,
// the host console's start/finish/skip/undo, and the public view of what is
// happening now and next.
//
// Two of these functions are deliberately pure — se_program_plan() and
// se_program_eta() take rows and a clock and return rows. They are the only
// place the evening's arithmetic lives, and tests/special_events/program_test.php
// pins them, because "running 8 minutes late" is a number the host reads out
// loud to a room.

/** True once PR4's migration has been applied (§9.4 rollout safety). */
function se_program_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_program_items');
}

/**
 * The programme rows of an event, in running order.
 *
 * @return list<array<string,mixed>>
 */
function se_program_items(PDO $pdo, int $eventId, ?int $dayId = null): array
{
    if (!se_program_ready($pdo)) {
        return [];
    }

    $sql = "SELECT * FROM se_program_items WHERE event_id = ?";
    $args = [$eventId];
    if ($dayId !== null) {
        $sql .= " AND day_id = ?";
        $args[] = $dayId;
    }
    $sql .= " ORDER BY day_id ASC, sort_order ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    return $stmt->fetchAll() ?: [];
}

/** One item, scoped to its event so a caller cannot reach into another's. */
function se_program_item(PDO $pdo, int $eventId, int $itemId): ?array
{
    if (!se_program_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM se_program_items WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$itemId, $eventId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

// --------------------------------------------------------------------------
// The planned timeline (§10.7.1) — pure
// --------------------------------------------------------------------------

/**
 * Walk one day's items and give each a planned start and end.
 *
 * An explicit `planned_start_at` wins and moves the cursor; when it lands
 * before the previous item's end the item is flagged `overlap_min`, which is
 * the amber warning the Studio shows.
 *
 * @param list<array<string,mixed>> $items in sort order
 * @return list<array<string,mixed>> the same rows plus planned_start/planned_end/overlap_min
 */
function se_program_plan(array $items, ?DateTimeImmutable $dayStart): array
{
    $cursor = $dayStart;
    $out    = [];

    foreach ($items as $item) {
        $explicit = se_parse_datetime($item['planned_start_at'] ?? null);
        $start    = $explicit ?? $cursor;

        if ($start === null) {
            // No day start and no explicit time: the item has no planned
            // position at all. Say so rather than inventing one.
            $item['planned_start'] = null;
            $item['planned_end']   = null;
            $item['overlap_min']   = 0;
            $out[] = $item;
            continue;
        }

        $overlap = 0;
        if ($explicit !== null && $cursor !== null && $explicit < $cursor) {
            $overlap = (int) round(($cursor->getTimestamp() - $explicit->getTimestamp()) / 60);
        }

        $duration = max(0, (int) ($item['duration_min'] ?? 0));
        $end      = $start->modify('+' . $duration . ' minutes');

        $item['planned_start'] = $start;
        $item['planned_end']   = $end;
        $item['overlap_min']   = $overlap;

        $cursor = $end;
        $out[]  = $item;
    }

    return $out;
}

/**
 * The live ETA (§10.7.2).
 *
 * Items must already carry `planned_start`/`planned_end` from
 * se_program_plan(). Done and skipped items push the cursor to when they
 * actually ended; the live item cannot end in the past; everything after
 * follows the cursor unless it has its own explicit start later than that.
 *
 * @param list<array<string,mixed>> $items
 * @return array{items: list<array<string,mixed>>, drift_min: int}
 */
function se_program_eta(array $items, DateTimeImmutable $now): array
{
    $cursor = null;
    $out    = [];

    foreach ($items as $item) {
        $status   = (string) ($item['status'] ?? 'planned');
        $duration = max(0, (int) ($item['duration_min'] ?? 0));
        $explicit = se_parse_datetime($item['planned_start_at'] ?? null);

        switch ($status) {
            case 'done':
            case 'skipped':
                $item['eta_start'] = se_parse_datetime($item['started_at'] ?? null) ?? $item['planned_start'] ?? null;
                $item['eta_end']   = se_parse_datetime($item['ended_at'] ?? null) ?? $item['eta_start'];
                $cursor = $item['eta_end'] ?? $cursor;
                break;

            case 'live':
                $started = se_parse_datetime($item['started_at'] ?? null) ?? $now;
                $planned = $started->modify('+' . $duration . ' minutes');
                $item['eta_start'] = $started;
                $item['eta_end']   = $planned > $now ? $planned : $now;
                $cursor = $item['eta_end'];
                break;

            default:
                $base  = $cursor ?? $item['planned_start'] ?? null;
                $start = $base;
                if ($explicit !== null) {
                    $start = ($base === null || $explicit > $base) ? $explicit : $base;
                }
                if ($start === null) {
                    $item['eta_start'] = null;
                    $item['eta_end']   = null;
                    break;
                }
                $item['eta_start'] = $start;
                $item['eta_end']   = $start->modify('+' . $duration . ' minutes');
                $cursor = $item['eta_end'];
        }

        $out[] = $item;
    }

    // Drift is measured at the end of the evening: where we now expect to
    // finish, against where the plan said we would. Positive = late.
    $drift = 0;
    for ($i = count($out) - 1; $i >= 0; $i--) {
        $etaEnd     = $out[$i]['eta_end']     ?? null;
        $plannedEnd = $out[$i]['planned_end'] ?? null;
        if ($etaEnd instanceof DateTimeImmutable && $plannedEnd instanceof DateTimeImmutable) {
            $drift = (int) round(($etaEnd->getTimestamp() - $plannedEnd->getTimestamp()) / 60);
            break;
        }
    }

    return ['items' => $out, 'drift_min' => $drift];
}

/** Planned + ETA for every day of an event, keyed by day id. */
function se_program_timeline(PDO $pdo, array $event, ?array $days = null, ?DateTimeImmutable $now = null): array
{
    $eventId = (int) $event['id'];
    $days  ??= se_event_days($pdo, $eventId);
    $now   ??= se_now();

    $all = se_program_items($pdo, $eventId);
    $byDay = [];
    foreach ($all as $row) {
        $byDay[(int) $row['day_id']][] = $row;
    }

    $out   = [];
    $drift = 0;
    foreach ($days as $day) {
        $dayId   = (int) $day['id'];
        $planned = se_program_plan($byDay[$dayId] ?? [], se_parse_datetime($day['starts_at']));
        $eta     = se_program_eta($planned, $now);

        $out[$dayId] = ['day' => $day, 'items' => $eta['items'], 'drift_min' => $eta['drift_min']];

        // The drift the console shows is today's, or the first day that has
        // anything running.
        if ($eta['drift_min'] !== 0 && $drift === 0) {
            $drift = $eta['drift_min'];
        }
    }

    return ['days' => $out, 'drift_min' => $drift];
}

// --------------------------------------------------------------------------
// Payloads
// --------------------------------------------------------------------------

/** ISO 8601 for a DateTimeImmutable the ETA engine produced (se_iso() takes SQL text). */
function se_program_iso(?DateTimeImmutable $when): ?string
{
    return $when?->format('c');
}

/** One item as the console and the Studio read it. */
function se_program_item_payload(array $item): array
{
    return [
        'id'            => (int) $item['id'],
        'day_id'        => (int) $item['day_id'],
        'sort_order'    => (int) $item['sort_order'],
        'kind'          => (string) $item['kind'],
        'title'         => (string) $item['title'],
        'public_blurb'  => $item['public_blurb'] !== null ? (string) $item['public_blurb'] : null,
        'host_name'     => $item['host_name'] !== null ? (string) $item['host_name'] : null,
        'duration_min'  => (int) $item['duration_min'],
        'planned_start_at' => se_iso($item['planned_start_at'] ?? null),
        'is_public'     => se_bool($item['is_public']),
        'is_featured'   => se_bool($item['is_featured']),
        'game_id'       => $item['game_id'] !== null ? (int) $item['game_id'] : null,
        'crew_notes'    => $item['crew_notes'] !== null ? (string) $item['crew_notes'] : null,
        'source'        => (string) $item['source'],
        'status'        => (string) $item['status'],
        'started_at'    => se_iso($item['started_at'] ?? null),
        'ended_at'      => se_iso($item['ended_at'] ?? null),
        'planned_start' => se_program_iso($item['planned_start'] ?? null),
        'planned_end'   => se_program_iso($item['planned_end'] ?? null),
        'eta_start'     => se_program_iso($item['eta_start'] ?? null),
        'eta_end'       => se_program_iso($item['eta_end'] ?? null),
        'eta_start_ms'  => isset($item['eta_start']) && $item['eta_start'] instanceof DateTimeImmutable
            ? $item['eta_start']->getTimestamp() * 1000 : null,
        'overlap_min'   => (int) ($item['overlap_min'] ?? 0),
    ];
}

/** The whole run of show, grouped by day, for the Studio and the console. */
function se_program_payload(PDO $pdo, array $event, ?array $days = null, ?DateTimeImmutable $now = null): array
{
    if (!se_program_ready($pdo)) {
        return ['ready' => false, 'days' => [], 'drift_min' => 0, 'live' => null, 'next' => null];
    }

    $timeline = se_program_timeline($pdo, $event, $days, $now);

    $out  = [];
    $live = null;
    $next = null;

    foreach ($timeline['days'] as $dayId => $block) {
        $items = [];
        foreach ($block['items'] as $item) {
            $payload = se_program_item_payload($item);
            if ($payload['status'] === 'live' && $live === null) {
                $live = $payload;
            } elseif ($payload['status'] === 'planned' && $next === null) {
                $next = $payload;
            }
            $items[] = $payload;
        }

        $out[] = [
            'day_id'    => (int) $dayId,
            'day_date'  => (string) $block['day']['day_date'],
            'label'     => $block['day']['label'] !== null ? (string) $block['day']['label'] : null,
            'starts_at' => se_iso($block['day']['starts_at'] ?? null),
            'items'     => $items,
            'drift_min' => (int) $block['drift_min'],
        ];
    }

    return [
        'ready'     => true,
        'days'      => $out,
        'drift_min' => (int) $timeline['drift_min'],
        'live'      => $live,
        'next'      => $next,
    ];
}

/**
 * How a time is shown to the public (§10.7.2):
 * `exact` → 7:15 PM · `approximate` → ~7:15 PM (15 min) · `order_only` → null.
 */
function se_program_public_time(?DateTimeImmutable $when, string $mode): ?string
{
    if ($when === null || $mode === 'order_only') {
        return null;
    }

    if ($mode === 'approximate') {
        $minutes = (int) $when->format('i');
        $rounded = (int) (round($minutes / 15) * 15);
        $when    = $when->modify('+' . ($rounded - $minutes) . ' minutes');

        return '~' . ltrim($when->format('g:i A'), '0');
    }

    return ltrim($when->format('g:i A'), '0');
}

/**
 * The programme as guests see it: public items only, no crew notes, and the
 * times rendered in the event's chosen mode.
 */
function se_program_public(PDO $pdo, array $event, ?array $days = null, ?array $settings = null, ?DateTimeImmutable $now = null): array
{
    if (!se_program_ready($pdo)) {
        return ['days' => [], 'now' => null, 'next' => null, 'drift_min' => 0, 'time_mode' => 'approximate'];
    }

    $settings ??= se_event_settings($event);
    $mode = se_enum($settings['program']['public_time_mode'] ?? 'approximate', SE_PROGRAM_TIME_MODES, 'approximate');

    $timeline = se_program_timeline($pdo, $event, $days, $now);

    $out  = [];
    $live = null;
    $next = null;

    foreach ($timeline['days'] as $dayId => $block) {
        $items = [];
        foreach ($block['items'] as $item) {
            if (!se_bool($item['is_public'])) {
                continue;
            }

            $row = [
                'id'       => (int) $item['id'],
                'title'    => (string) $item['title'],
                'kind'     => (string) $item['kind'],
                'blurb'    => $item['public_blurb'] !== null ? (string) $item['public_blurb'] : null,
                'host'     => $item['host_name'] !== null ? (string) $item['host_name'] : null,
                'featured' => se_bool($item['is_featured']),
                'status'   => (string) $item['status'],
                'time'     => se_program_public_time($item['eta_start'] ?? null, $mode),
                'duration_min' => (int) $item['duration_min'],
            ];

            if ($row['status'] === 'live' && $live === null) {
                $live = $row;
            } elseif ($row['status'] === 'planned' && $next === null) {
                $next = $row;
            }

            $items[] = $row;
        }

        if (!$items) {
            continue;
        }

        $out[] = [
            'day_id'   => (int) $dayId,
            'day_date' => (string) $block['day']['day_date'],
            'label'    => $block['day']['label'] !== null ? (string) $block['day']['label'] : null,
            'items'    => $items,
        ];
    }

    return [
        'days'      => $out,
        'now'       => $live,
        'next'      => $next,
        'drift_min' => (int) $timeline['drift_min'],
        'time_mode' => $mode,
    ];
}

// --------------------------------------------------------------------------
// Editing (Studio → Programme)
// --------------------------------------------------------------------------

/**
 * Save a whole day's (or event's) items: insert, update, reorder and delete
 * in one transaction. Rows that are `live` or `done` keep their timestamps —
 * the builder may rename them, never rewrite history.
 *
 * @param list<array<string,mixed>> $items client rows, in the order they should run
 * @return array the fresh programme payload
 */
function se_program_save(PDO $pdo, array $event, array $items, ?int $actorId): array
{
    if (!se_program_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'The programme is not available yet.');
    }

    $eventId = (int) $event['id'];
    $days    = se_event_days($pdo, $eventId);
    $dayIds  = array_map(static fn(array $d): int => (int) $d['id'], $days);
    if (!$dayIds) {
        throw new SeValidationException(['days' => 'Add a day to the event before building its programme.']);
    }

    if (count($items) > 200) {
        throw new SeValidationException(['items' => 'That is more than 200 items — split the day up.']);
    }

    $existing = [];
    foreach (se_program_items($pdo, $eventId) as $row) {
        $existing[(int) $row['id']] = $row;
    }

    $pdo->beginTransaction();
    try {
        $seen  = [];
        $order = [];

        foreach ($items as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $title = se_line($raw['title'] ?? '', 120);
            if ($title === '') {
                continue;   // an empty row is a row the producer abandoned
            }

            $dayId = se_int($raw['day_id'] ?? 0, 0);
            if (!in_array($dayId, $dayIds, true)) {
                $dayId = $dayIds[0];
            }

            $order[$dayId] = ($order[$dayId] ?? 0) + 1;

            $fields = [
                'day_id'       => $dayId,
                'sort_order'   => $order[$dayId],
                'kind'         => se_enum($raw['kind'] ?? 'other', SE_PROGRAM_KINDS, 'other'),
                'title'        => $title,
                'public_blurb' => se_str($raw['public_blurb'] ?? '', 400) ?: null,
                'host_name'    => se_line($raw['host_name'] ?? '', 120) ?: null,
                'duration_min' => se_int($raw['duration_min'] ?? 10, 0, 1440, 10),
                'is_public'    => se_bool($raw['is_public'] ?? true) ? 1 : 0,
                'is_featured'  => se_bool($raw['is_featured'] ?? false) ? 1 : 0,
                'crew_notes'   => se_str($raw['crew_notes'] ?? '', 2000) ?: null,
                'planned_start_at' => null,
            ];

            $start = se_parse_datetime($raw['planned_start_at'] ?? null);
            if ($start !== null) {
                $fields['planned_start_at'] = se_sql_datetime($start);
            }

            $id = se_int($raw['id'] ?? 0, 0);
            if ($id > 0 && isset($existing[$id])) {
                $sql = "UPDATE se_program_items SET day_id = ?, sort_order = ?, kind = ?, title = ?,
                               public_blurb = ?, host_name = ?, duration_min = ?, is_public = ?,
                               is_featured = ?, crew_notes = ?, planned_start_at = ?
                         WHERE id = ? AND event_id = ?";
                $pdo->prepare($sql)->execute([
                    $fields['day_id'], $fields['sort_order'], $fields['kind'], $fields['title'],
                    $fields['public_blurb'], $fields['host_name'], $fields['duration_min'],
                    $fields['is_public'], $fields['is_featured'], $fields['crew_notes'],
                    $fields['planned_start_at'], $id, $eventId,
                ]);
                $seen[] = $id;
                continue;
            }

            $sql = "INSERT INTO se_program_items
                        (event_id, day_id, sort_order, kind, title, public_blurb, host_name,
                         duration_min, is_public, is_featured, crew_notes, planned_start_at, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $eventId, $fields['day_id'], $fields['sort_order'], $fields['kind'], $fields['title'],
                $fields['public_blurb'], $fields['host_name'], $fields['duration_min'],
                $fields['is_public'], $fields['is_featured'], $fields['crew_notes'],
                $fields['planned_start_at'],
                se_enum($raw['source'] ?? 'manual', ['manual', 'ai'], 'manual'),
            ]);
            $seen[] = (int) $pdo->lastInsertId();
        }

        // Anything the builder no longer lists is deleted — except an item
        // the evening has already touched, which stays as a record.
        foreach ($existing as $id => $row) {
            if (in_array($id, $seen, true)) {
                continue;
            }
            if (in_array((string) $row['status'], ['live', 'done'], true)) {
                continue;
            }
            $pdo->prepare("DELETE FROM se_program_items WHERE id = ? AND event_id = ?")->execute([$id, $eventId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    se_audit($pdo, $eventId, 'program_op', ['op' => 'save', 'items' => count($seen)], 'event', $eventId, $actorId);
    se_live_publish($pdo, $eventId, true);

    return se_program_payload($pdo, $event, $days);
}

// --------------------------------------------------------------------------
// Running the show (§10.7.2)
// --------------------------------------------------------------------------

/**
 * Start / finish / skip / undo one item.
 *
 * Everything goes through se_live_mutate(), so the version check that stops
 * two consoles fighting applies to the run of show as well as to scenes, and
 * the snapshots are republished the moment the host taps.
 *
 * `undo` restores the previous status from the audit entry this function
 * wrote when the item last changed — §10.7.2's "Undo last".
 */
function se_program_op(PDO $pdo, array $event, int $itemId, string $op, ?int $expectedVersion, ?int $actorId): array
{
    if (!se_program_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'The programme is not available yet.');
    }

    $eventId = (int) $event['id'];
    $item    = se_program_item($pdo, $eventId, $itemId);
    if (!$item) {
        throw new SeNotFoundException('We could not find that programme item.');
    }

    $op = se_enum($op, ['start', 'finish', 'skip', 'undo'], '');
    if ($op === '') {
        throw new SeValidationException(['op' => 'Unknown programme action.']);
    }

    $before = ['status' => (string) $item['status'], 'started_at' => $item['started_at'], 'ended_at' => $item['ended_at']];
    $linkedGameId = null;

    se_live_mutate($pdo, $event, $expectedVersion, static function (array $state, PDO $pdo) use (
        $eventId, $item, $op, $before, &$linkedGameId
    ): array {
        $now = se_sql_datetime(se_now());
        $id  = (int) $item['id'];

        switch ($op) {
            case 'start':
                // One item is live at a time: whatever was running is done.
                $pdo->prepare(
                    "UPDATE se_program_items SET status = 'done', ended_at = COALESCE(ended_at, ?)
                      WHERE event_id = ? AND status = 'live' AND id <> ?"
                )->execute([$now, $eventId, $id]);

                $pdo->prepare(
                    "UPDATE se_program_items SET status = 'live', started_at = COALESCE(started_at, ?), ended_at = NULL
                      WHERE id = ? AND event_id = ?"
                )->execute([$now, $id, $eventId]);

                $linkedGameId = $item['game_id'] !== null ? (int) $item['game_id'] : null;
                break;

            case 'finish':
                $pdo->prepare(
                    "UPDATE se_program_items SET status = 'done', started_at = COALESCE(started_at, ?), ended_at = ?
                      WHERE id = ? AND event_id = ?"
                )->execute([$now, $now, $id, $eventId]);
                break;

            case 'skip':
                $pdo->prepare(
                    "UPDATE se_program_items SET status = 'skipped', ended_at = ? WHERE id = ? AND event_id = ?"
                )->execute([$now, $id, $eventId]);
                break;

            case 'undo':
                $previous = se_program_previous_state($pdo, $eventId, $id);
                $pdo->prepare(
                    "UPDATE se_program_items SET status = ?, started_at = ?, ended_at = ? WHERE id = ? AND event_id = ?"
                )->execute([
                    $previous['status'],
                    $previous['started_at'],
                    $previous['ended_at'],
                    $id,
                    $eventId,
                ]);
                break;
        }

        return [];
    }, 'program_op', ['op' => $op, 'item_id' => $itemId, 'before' => $before], $actorId);

    // Starting an item that belongs to a game puts the stage on that game's
    // intro (§10.7.2). The scene is a separate, unversioned follow-up so a
    // missing game (PR5) can never block the run of show.
    if ($linkedGameId !== null) {
        try {
            se_scene_set($pdo, $event, 'game', ['game_id' => $linkedGameId], null, $actorId);
        } catch (Throwable $e) {
            error_log('SE program/scene: ' . $e->getMessage());
        }
    }

    se_live_publish($pdo, $eventId, true);

    return se_program_payload($pdo, $event);
}

/**
 * The status an item had before its last change, read back from the audit
 * log — which is where se_program_op() put it.
 */
function se_program_previous_state(PDO $pdo, int $eventId, int $itemId): array
{
    $fallback = ['status' => 'planned', 'started_at' => null, 'ended_at' => null];

    if (!se_table_exists($pdo, 'se_audit_log')) {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT detail_json FROM se_audit_log
              WHERE event_id = ? AND action = 'program_op' AND entity = 'event'
              ORDER BY id DESC LIMIT 40"
        );
        $stmt->execute([$eventId]);

        foreach ($stmt->fetchAll() as $row) {
            $detail = se_json_decode($row['detail_json'] ?? null);
            if ((int) ($detail['item_id'] ?? 0) !== $itemId || ($detail['op'] ?? '') === 'undo') {
                continue;
            }
            $before = $detail['before'] ?? null;
            if (is_array($before) && isset($before['status'])) {
                return [
                    'status'     => se_enum($before['status'], ['planned', 'live', 'done', 'skipped'], 'planned'),
                    'started_at' => $before['started_at'] ?? null,
                    'ended_at'   => $before['ended_at'] ?? null,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('SE program/undo: ' . $e->getMessage());
    }

    return $fallback;
}

/** Move a planned item before another one (or to the end). */
function se_program_move(PDO $pdo, array $event, int $itemId, ?int $beforeId, ?int $expectedVersion, ?int $actorId): array
{
    if (!se_program_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'The programme is not available yet.');
    }

    $eventId = (int) $event['id'];
    $item    = se_program_item($pdo, $eventId, $itemId);
    if (!$item) {
        throw new SeNotFoundException('We could not find that programme item.');
    }
    if ((string) $item['status'] !== 'planned') {
        throw new SeRuleException('RULE', 'Only an item that has not run yet can be moved.');
    }

    se_live_mutate($pdo, $event, $expectedVersion, static function (array $state, PDO $pdo) use ($eventId, $item, $beforeId): array {
        $dayId = (int) $item['day_id'];
        $rows  = se_program_items($pdo, $eventId, $dayId);

        $ordered = [];
        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $item['id']) {
                continue;
            }
            if ($beforeId !== null && (int) $row['id'] === $beforeId) {
                $ordered[] = $item;
            }
            $ordered[] = $row;
        }
        if ($beforeId === null || !in_array((int) $item['id'], array_map(static fn($r) => (int) $r['id'], $ordered), true)) {
            $ordered[] = $item;
        }

        $update = $pdo->prepare("UPDATE se_program_items SET sort_order = ? WHERE id = ? AND event_id = ?");
        foreach (array_values($ordered) as $i => $row) {
            $update->execute([$i + 1, (int) $row['id'], $eventId]);
        }

        return [];
    }, 'program_op', ['op' => 'move', 'item_id' => $itemId, 'before_id' => $beforeId], $actorId);

    se_live_publish($pdo, $eventId, true);

    return se_program_payload($pdo, $event);
}

// --------------------------------------------------------------------------
// AI import (§15.3, §10.7.3)
// --------------------------------------------------------------------------

/**
 * Ask the model to read a programme (pasted text, a screenshot or a PDF) and
 * return a reviewable table. Nothing is written to se_program_items here:
 * the job is stored and applied only when a human presses Apply.
 */
function se_program_import(PDO $pdo, array $event, array $input, ?int $actorId): array
{
    $days = se_event_days($pdo, (int) $event['id']);
    if (!$days) {
        throw new SeValidationException(['days' => 'Add a day to the event first.']);
    }

    $dayList = [];
    foreach ($days as $i => $day) {
        $dayList[] = [
            'index'     => $i,
            'date'      => (string) $day['day_date'],
            'starts_at' => (string) $day['starts_at'],
            'ends_at'   => (string) $day['ends_at'],
        ];
    }

    $text    = se_str($input['text'] ?? '', 20000);
    $assetId = se_int_or_null($input['asset_id'] ?? null, 0);

    if ($text === '' && $assetId === null) {
        throw new SeValidationException(['text' => 'Paste the programme, or choose an uploaded image or PDF.']);
    }

    // An uploaded screenshot or PDF travels as inlineData (§15.1). The file
    // must belong to this event: an asset id alone never reaches the model.
    $parts = [];
    if ($assetId !== null) {
        $parts[] = se_ai_inline_asset($pdo, $event, $assetId, ['program_source']);
    }
    if ($text !== '') {
        $parts[] = ['text' => $text];
    }

    $result = se_ai($pdo, 'program_extract', [
        'kinds' => implode(', ', SE_PROGRAM_KINDS),
        'days'  => se_json_encode($dayList),
        'text'  => $text,
    ], [
        'user_id'  => $actorId,
        'event_id' => (int) $event['id'],
        'vision'   => $assetId !== null,
        'parts'    => $parts,
    ]);

    $items    = is_array($result['items'] ?? null) ? $result['items'] : [];
    $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];

    $clean = se_program_import_normalize($items, $days);
    if (!$clean) {
        throw new SeAiException('AI_INVALID_OUTPUT', 'We could not read a programme out of that. Try a clearer photo, or paste the text.');
    }

    $jobId = se_ai_job_create($pdo, (int) $event['id'], 'program_extract', [
        'asset_id' => $assetId,
        'chars'    => mb_strlen($text, 'UTF-8'),
    ], ['items' => $clean], (int) $actorId);

    return [
        'job_id'   => $jobId,
        'items'    => $clean,
        'warnings' => array_values(array_map(static fn($w): string => se_line($w, 200), $warnings)),
    ];
}

/** Parse a model-returned HH:MM clock without trusting browser locale. */
function se_program_import_clock(string $value): ?array
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        return $hour <= 23 && $minute <= 59 ? [$hour, $minute] : null;
    }

    // Be forgiving if a model returned a legible 12-hour time despite the
    // prompt asking for 24-hour HH:MM. It is still attached to the event day,
    // never to the server's current date.
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $value, $m)) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour < 1 || $hour > 12 || $minute > 59) {
            return null;
        }
        if (strtoupper($m[3]) === 'PM' && $hour !== 12) { $hour += 12; }
        if (strtoupper($m[3]) === 'AM' && $hour === 12) { $hour = 0; }
        return [$hour, $minute];
    }

    return null;
}

/**
 * Post-processing for an extraction (§15.3): map kinds, attach times to a
 * day, derive a duration from an explicit time range or the next item's
 * start, and drop anything without a title. Pure, so review behaviour stays
 * testable even when an AI provider is unavailable.
 */
function se_program_import_normalize(array $items, array $days): array
{
    $out = [];

    foreach ($items as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $title = se_line($raw['title'] ?? '', 120);
        if ($title === '') {
            continue;
        }

        $dayIndex = se_int($raw['day_index'] ?? 0, 0, max(0, count($days) - 1), 0);
        $day      = $days[$dayIndex] ?? ($days[0] ?? null);
        $base     = $day !== null ? se_parse_datetime($day['starts_at']) : null;
        $start    = null;
        $end      = null;

        $startClock = se_program_import_clock(se_str($raw['start_time'] ?? '', 16));
        if ($base !== null && $startClock !== null) {
            $start = $base->setTime($startClock[0], $startClock[1], 0);
        }

        // The extraction schema carries end_time so a written range such as
        // 3:30 PM–4:30 PM is exact rather than a guess from the next row.
        $endClock = se_program_import_clock(se_str($raw['end_time'] ?? '', 16));
        if ($base !== null && $endClock !== null) {
            $end = $base->setTime($endClock[0], $endClock[1], 0);
            if ($start !== null && $end <= $start) {
                $end = $end->modify('+1 day');
            }
        }

        $givenDuration = array_key_exists('duration_min', $raw) && $raw['duration_min'] !== null
            ? se_int($raw['duration_min'], 0, 1440, 10)
            : null;
        $rangeDuration = $start !== null && $end !== null
            ? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60)
            : null;

        $out[] = [
            'day_index'    => $dayIndex,
            'day_id'       => $day !== null ? (int) $day['id'] : null,
            'title'        => $title,
            'kind'         => se_enum(strtolower((string) ($raw['kind'] ?? 'other')), SE_PROGRAM_KINDS, 'other'),
            'start_time'   => se_program_iso($start),
            'duration_min' => $rangeDuration ?? $givenDuration,
            'host_name'    => se_line($raw['host'] ?? '', 120) ?: null,
            'notes'        => se_str($raw['notes'] ?? '', 400) ?: null,
            'confidence'   => round(se_float($raw['confidence'] ?? 0.0, 0.0, 1.0, 0.0), 2),
            'low_confidence' => se_float($raw['confidence'] ?? 0.0, 0.0, 1.0, 0.0) < 0.6,
            'duration_guessed' => false,
        ];
    }

    // Missing durations come from the next item's start on the same day.
    $count = count($out);
    for ($i = 0; $i < $count; $i++) {
        if ($out[$i]['duration_min'] !== null) {
            continue;
        }

        $derived = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($out[$j]['day_index'] !== $out[$i]['day_index']) {
                break;
            }
            if ($out[$j]['start_time'] !== null && $out[$i]['start_time'] !== null) {
                $a = se_parse_datetime($out[$i]['start_time']);
                $b = se_parse_datetime($out[$j]['start_time']);
                if ($a !== null && $b !== null && $b > $a) {
                    $derived = (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
                }
            }
            break;
        }

        $out[$i]['duration_min']     = $derived ?? 10;
        $out[$i]['duration_guessed'] = true;
    }

    return $out;
}

/**
 * Apply a reviewed import (§10.7.3).
 *
 * `replace` is refused when the day has already started running — the rows
 * on screen then are the evening's record, not a draft.
 */
function se_program_apply(PDO $pdo, array $event, array $items, string $mode, int $dayId, ?int $jobId, ?int $actorId): array
{
    if (!se_program_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'The programme is not available yet.');
    }

    $eventId = (int) $event['id'];
    $mode    = se_enum($mode, ['replace', 'append'], 'append');
    $days    = se_event_days($pdo, $eventId);
    $dayIds  = array_map(static fn(array $d): int => (int) $d['id'], $days);

    if (!in_array($dayId, $dayIds, true)) {
        $dayId = $dayIds[0] ?? 0;
    }
    if ($dayId === 0) {
        throw new SeValidationException(['day_id' => 'Add a day to the event first.']);
    }

    // The job id is audit/review metadata, not authority. Scope it to this
    // event before marking it applied so one Producer cannot alter another
    // event's AI-job history by guessing an id.
    if ($jobId !== null) {
        $job = se_ai_job_find($pdo, $jobId, $eventId);
        if (!$job || (string) $job['task'] !== 'program_extract') {
            throw new SeValidationException(['job_id' => 'That programme review is no longer available. Read the source again.']);
        }
    }

    // Append may deliberately contain rows for several event days. Replace is
    // always singular and explicit: every row must point at the selected day.
    $reviewed = [];
    foreach ($items as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $rowDayId = se_int($raw['day_id'] ?? $dayId, 0);
        if (!in_array($rowDayId, $dayIds, true)) {
            $rowDayId = $dayId;
        }
        if ($mode === 'replace' && $rowDayId !== $dayId) {
            throw new SeValidationException(['items' => 'Replace this day can only apply rows assigned to that selected day.']);
        }
        $raw['day_id'] = $rowDayId;
        $reviewed[] = $raw;
    }

    $allCurrent = se_program_items($pdo, $eventId);
    $currentByDay = [];
    $nextByDay = [];
    foreach ($allCurrent as $row) {
        $currentByDay[(int) $row['day_id']][] = $row;
        $id = (int) $row['day_id'];
        $nextByDay[$id] = max($nextByDay[$id] ?? 0, (int) $row['sort_order']);
    }

    if ($mode === 'replace') {
        foreach ($currentByDay[$dayId] ?? [] as $row) {
            if (in_array((string) $row['status'], ['live', 'done'], true)) {
                throw new SeRuleException('RULE', 'This day has already started, so it can only be added to.');
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') {
            $pdo->prepare("DELETE FROM se_program_items WHERE event_id = ? AND day_id = ? AND status = 'planned'")
                ->execute([$eventId, $dayId]);
            $nextByDay[$dayId] = 0;
        }

        $insert = $pdo->prepare(
            "INSERT INTO se_program_items
                 (event_id, day_id, sort_order, kind, title, host_name, duration_min, planned_start_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ai')"
        );

        $added = 0;
        foreach ($reviewed as $raw) {
            $title = se_line($raw['title'] ?? '', 120);
            if ($title === '' || !se_bool($raw['include'] ?? true)) {
                continue;
            }

            $rowDayId = (int) $raw['day_id'];
            $start = se_parse_datetime($raw['start_time'] ?? null);
            $nextByDay[$rowDayId] = ($nextByDay[$rowDayId] ?? 0) + 1;
            $insert->execute([
                $eventId,
                $rowDayId,
                $nextByDay[$rowDayId],
                se_enum($raw['kind'] ?? 'other', SE_PROGRAM_KINDS, 'other'),
                $title,
                se_line($raw['host_name'] ?? '', 120) ?: null,
                se_int($raw['duration_min'] ?? 10, 0, 1440, 10),
                $start !== null ? se_sql_datetime($start) : null,
            ]);
            $added++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($jobId !== null) {
        se_ai_job_mark_applied($pdo, $jobId, $actorId);
    }

    se_audit($pdo, $eventId, 'ai_job_apply', ['task' => 'program_extract', 'mode' => $mode, 'items' => $added], 'event', $eventId, $actorId);
    se_live_publish($pdo, $eventId, true);

    return se_program_payload($pdo, $event, $days);
}

/** Copy a source event's programme onto a clone, shifted by Δ (§10.10). */
function se_program_clone(PDO $pdo, int $sourceId, int $targetId, array $dayMap, int $shiftSeconds): int
{
    if (!se_program_ready($pdo)) {
        return 0;
    }

    $rows   = se_program_items($pdo, $sourceId);
    $insert = $pdo->prepare(
        "INSERT INTO se_program_items
             (event_id, day_id, sort_order, kind, title, public_blurb, icon, host_name,
              planned_start_at, duration_min, is_public, is_featured, crew_notes, source, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned')"
    );

    $copied = 0;
    foreach ($rows as $row) {
        $newDay = $dayMap[(int) $row['day_id']] ?? null;
        if ($newDay === null) {
            continue;
        }

        $start = se_parse_datetime($row['planned_start_at']);
        $insert->execute([
            $targetId,
            $newDay,
            (int) $row['sort_order'],
            (string) $row['kind'],
            (string) $row['title'],
            $row['public_blurb'],
            $row['icon'],
            $row['host_name'],
            $start !== null ? se_sql_datetime($start->modify(($shiftSeconds >= 0 ? '+' : '') . $shiftSeconds . ' seconds')) : null,
            (int) $row['duration_min'],
            (int) $row['is_public'],
            (int) $row['is_featured'],
            $row['crew_notes'],
            (string) $row['source'],
        ]);
        $copied++;
    }

    return $copied;
}
