<?php
// /includes/special_events/verses.php
//
// Welcome verses (guide §10.9, §15.7).
//
// A verse set is built in the Studio: a theme produces suggested REFERENCES
// from the model, each reference is fetched from the KJV (§15.9) and stored
// as text, and crew approves it before it can ever reach a guest's card.
// AI never supplies the words of Scripture — only the pointer to them.
//
// At check-in the least-used approved verse is handed out (ties broken at
// random), and the choice is stored on the check-in row, so re-opening the
// card on the same day always shows the same verse.

/** Is the verse table migrated yet? (§9.4) */
function se_verses_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_event_verses');
}

/** Every verse of an event, newest sort order first. */
function se_verses_list(PDO $pdo, int $eventId, bool $activeOnly = false): array
{
    if (!se_verses_ready($pdo)) {
        return [];
    }

    try {
        $sql = "SELECT * FROM se_event_verses WHERE event_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1 AND approved_at IS NOT NULL";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$eventId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('SE verses/list: ' . $e->getMessage());
        return [];
    }
}

/** One verse, scoped to its event. */
function se_verse_find(PDO $pdo, int $eventId, int $id): ?array
{
    if (!se_verses_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM se_event_verses WHERE event_id = ? AND id = ?");
    $stmt->execute([$eventId, $id]);

    return $stmt->fetch() ?: null;
}

/** A verse is usable when it is active, approved, and — if typed by hand — double-approved. */
function se_verse_is_usable(array $verse): bool
{
    if (!se_bool($verse['is_active']) || empty($verse['approved_at'])) {
        return false;
    }

    // A manual paste was never checked against the KJV service, so §15.9
    // requires a second pair of eyes before it goes on a card.
    if ((string) $verse['text_source'] === 'manual' && empty($verse['second_approved_by'])) {
        return false;
    }

    return true;
}

/** The shape the Studio renders. */
function se_verse_payload(array $verse): array
{
    return [
        'id'            => (int) $verse['id'],
        'translation'   => (string) $verse['translation'],
        'ref_display'   => (string) $verse['ref_display'],
        'text'          => (string) $verse['text'],
        'text_source'   => (string) $verse['text_source'],
        'prayer_template' => $verse['prayer_template'],
        'sort_order'    => (int) $verse['sort_order'],
        'is_active'     => se_bool($verse['is_active']),
        'approved_at'   => se_iso($verse['approved_at']),
        'approved_by'   => $verse['approved_by'] !== null ? (int) $verse['approved_by'] : null,
        'second_approved_by' => $verse['second_approved_by'] !== null ? (int) $verse['second_approved_by'] : null,
        'times_used'    => (int) $verse['times_used'],
        'usable'        => se_verse_is_usable($verse),
    ];
}

/**
 * Add one verse by reference.
 *
 * `$text` is only used when the lookup service is unreachable; it is then
 * stored as `manual` and needs the second approval (§15.9).
 */
function se_verse_add(
    PDO $pdo,
    array $event,
    string $ref,
    ?string $prayerTemplate,
    ?int $actorId,
    ?string $manualText = null
): array {
    $eventId = (int) $event['id'];

    if (!se_verses_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Welcome verses are not available yet.');
    }

    $parsed = se_bible_ref_normalize($ref);
    if ($parsed === null) {
        throw new SeValidationException(['ref' => 'That does not look like a Bible reference, e.g. Psalms 16:11.']);
    }

    $prayer = se_line($prayerTemplate ?? '', 300);
    if ($prayer !== '' && !str_contains($prayer, '{name}')) {
        throw new SeValidationException(['prayer_template' => 'Include {name} so we can greet the guest.']);
    }

    $looked = se_bible_lookup($pdo, $ref, 'KJV');
    $source = 'lookup';
    $text   = $looked['text'] ?? '';
    $display = $looked['ref_display'] ?? $parsed['display'];

    if ($text === '') {
        $text = trim(se_str($manualText ?? '', 2000));
        if ($text === '') {
            throw new SeRuleException(
                'BIBLE_UNAVAILABLE',
                'We could not fetch that verse. Paste the KJV text and a second person can approve it.'
            );
        }
        $source = 'manual';
    }

    $next = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM se_event_verses WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $next = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('SE verses/next: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare(
        "INSERT INTO se_event_verses
            (event_id, translation, ref_display, text, text_source, prayer_template, sort_order, is_active)
         VALUES (?, 'KJV', ?, ?, ?, ?, ?, 1)"
    );
    $stmt->execute([$eventId, $display, $text, $source, $prayer !== '' ? $prayer : null, $next]);

    $id = (int) $pdo->lastInsertId();
    se_audit($pdo, $eventId, 'verse_save:add', ['ref' => $display, 'source' => $source], 'verse', $id, $actorId);

    return se_verse_find($pdo, $eventId, $id) ?? [];
}

/**
 * Update the editable parts of the set: prayer line, order and active flag.
 *
 * The reference and the text are never edited here. Changing which words a
 * guest reads means adding a new verse, so the approval trail stays honest.
 */
function se_verses_save(PDO $pdo, array $event, array $rows, ?int $actorId): array
{
    $eventId = (int) $event['id'];

    if (!se_verses_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Welcome verses are not available yet.');
    }

    foreach (array_values($rows) as $i => $row) {
        $id = se_int($row['id'] ?? 0, 0);
        if ($id <= 0 || !se_verse_find($pdo, $eventId, $id)) {
            continue;
        }

        $prayer = se_line($row['prayer_template'] ?? '', 300);
        if ($prayer !== '' && !str_contains($prayer, '{name}')) {
            throw new SeValidationException(['prayer_template' => 'Include {name} so we can greet the guest.']);
        }

        $pdo->prepare(
            "UPDATE se_event_verses SET prayer_template = ?, sort_order = ?, is_active = ?
             WHERE id = ? AND event_id = ?"
        )->execute([
            $prayer !== '' ? $prayer : null,
            se_int($row['sort_order'] ?? $i, 0, 9999, $i),
            se_bool($row['is_active'] ?? true) ? 1 : 0,
            $id,
            $eventId,
        ]);
    }

    se_audit($pdo, $eventId, 'verse_save', ['count' => count($rows)], 'event', $eventId, $actorId);

    return se_verses_list($pdo, $eventId);
}

/**
 * Approve a verse. The first approval is the crew check on an AI suggestion;
 * a manual paste needs a different person for the second one.
 */
function se_verse_approve(PDO $pdo, array $event, int $id, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $verse   = se_verse_find($pdo, $eventId, $id);

    if (!$verse) {
        throw new SeNotFoundException('That verse is no longer in this event.');
    }

    if (empty($verse['approved_at'])) {
        $pdo->prepare("UPDATE se_event_verses SET approved_by = ?, approved_at = NOW() WHERE id = ? AND event_id = ?")
            ->execute([$actorId, $id, $eventId]);
    } elseif ((string) $verse['text_source'] === 'manual' && empty($verse['second_approved_by'])) {
        if ($actorId !== null && (int) $verse['approved_by'] === $actorId) {
            throw new SeRuleException(
                'SECOND_APPROVAL_REQUIRED',
                'A typed-in verse needs a second person to check it against their Bible.'
            );
        }
        $pdo->prepare("UPDATE se_event_verses SET second_approved_by = ? WHERE id = ? AND event_id = ?")
            ->execute([$actorId, $id, $eventId]);
    }

    se_audit($pdo, $eventId, 'verse_save:approve', ['ref' => $verse['ref_display']], 'verse', $id, $actorId);

    return se_verse_find($pdo, $eventId, $id) ?? $verse;
}

/** Remove a verse that has never been handed out. */
function se_verse_delete(PDO $pdo, array $event, int $id, ?int $actorId): void
{
    $eventId = (int) $event['id'];
    $verse   = se_verse_find($pdo, $eventId, $id);

    if (!$verse) {
        return;
    }
    if ((int) $verse['times_used'] > 0) {
        throw new SeRuleException(
            'VERSE_IN_USE',
            'Someone has already received this verse. Switch it off instead of deleting it.'
        );
    }

    $pdo->prepare("DELETE FROM se_event_verses WHERE id = ? AND event_id = ?")->execute([$id, $eventId]);
    se_audit($pdo, $eventId, 'verse_save:delete', ['ref' => $verse['ref_display']], 'verse', $id, $actorId);
}

/**
 * Pick the verse for an arriving guest: least used first, random among ties
 * (§10.9).
 *
 * MUST be called inside the check-in lock so that two simultaneous arrivals
 * cannot both take the same least-used verse and skew the spread.
 */
function se_pick_verse(PDO $pdo, int $eventId): ?array
{
    if (!se_verses_ready($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM se_event_verses
             WHERE event_id = ? AND is_active = 1 AND approved_at IS NOT NULL
               AND (text_source = 'lookup' OR second_approved_by IS NOT NULL)
             ORDER BY times_used ASC, RAND()
             LIMIT 1"
        );
        $stmt->execute([$eventId]);
        $verse = $stmt->fetch();

        if (!$verse) {
            return null;
        }

        $pdo->prepare("UPDATE se_event_verses SET times_used = times_used + 1 WHERE id = ?")
            ->execute([(int) $verse['id']]);

        return $verse;
    } catch (Throwable $e) {
        error_log('SE verses/pick: ' . $e->getMessage());
        return null;
    }
}

/** The verse as the check-in response and the welcome card want it. */
function se_verse_for_card(?array $verse, string $firstName): ?array
{
    if (!$verse) {
        return null;
    }

    return [
        'id'     => (int) $verse['id'],
        'ref'    => (string) $verse['ref_display'],
        'text'   => (string) $verse['text'],
        'prayer' => se_verse_prayer($verse['prayer_template'] ?? null, $firstName),
    ];
}

/** Resolve `{name}` in a prayer line. Returns null when there is no line. */
function se_verse_prayer(?string $template, string $firstName): ?string
{
    $template = trim((string) $template);
    if ($template === '') {
        return null;
    }

    return str_replace('{name}', $firstName !== '' ? $firstName : 'Friend', $template);
}

/**
 * AI suggestions for a theme (§15.7).
 *
 * The model returns references only. Each one is normalised and fetched; a
 * reference we cannot resolve is dropped with a reason rather than offered,
 * because an unverifiable "verse" is worse than one fewer suggestion.
 */
function se_verses_suggest(PDO $pdo, array $event, string $theme, int $count, string $tone, int $actorId): array
{
    $theme = se_line($theme, 60);
    if ($theme === '') {
        throw new SeValidationException(['theme' => 'What is tonight about? e.g. joy, courage, belonging.']);
    }

    $count = max(1, min(24, $count));

    $result = se_ai($pdo, 'verses_suggest', [
        'theme'       => $theme,
        'tone'        => se_line($tone, 80) ?: 'warm, celebratory',
        'translation' => 'KJV',
        'count'       => (string) $count,
        'title'       => (string) $event['title'],
        'tagline'     => (string) ($event['tagline'] ?? ''),
    ], ['user_id' => $actorId, 'event_id' => (int) $event['id']]);

    $existing = [];
    foreach (se_verses_list($pdo, (int) $event['id']) as $verse) {
        $parsed = se_bible_ref_normalize((string) $verse['ref_display']);
        if ($parsed !== null) {
            $existing[$parsed['ref_norm']] = true;
        }
    }

    $out     = [];
    $dropped = [];
    $seen    = [];

    // The requested count is enforced here rather than as a maxItems bound on
    // an array of objects in Gemini's constrained response schema.
    foreach (array_slice((array) ($result['verses'] ?? []), 0, $count) as $item) {
        $ref = se_line($item['ref'] ?? '', 60);
        if ($ref === '') {
            continue;
        }

        $parsed = se_bible_ref_normalize($ref);
        if ($parsed === null) {
            $dropped[] = ['ref' => $ref, 'why' => 'not a reference we recognise'];
            continue;
        }
        if (isset($seen[$parsed['ref_norm']])) {
            continue;
        }
        $seen[$parsed['ref_norm']] = true;

        $looked = se_bible_lookup($pdo, $ref, 'KJV');
        if ($looked === null || $looked['text'] === '') {
            $dropped[] = ['ref' => $parsed['display'], 'why' => 'the KJV lookup did not return it'];
            continue;
        }

        $prayer = se_line($item['prayer_template'] ?? '', 300);
        if ($prayer !== '' && !str_contains($prayer, '{name}')) {
            $prayer = '';
        }

        $out[] = [
            'ref_display'     => $looked['ref_display'],
            'text'            => $looked['text'],
            'why'             => se_line($item['why'] ?? '', 80),
            'prayer_template' => $prayer !== '' ? $prayer : null,
            'already_added'   => isset($existing[$parsed['ref_norm']]),
        ];
    }

    $jobId = se_ai_job_create($pdo, (int) $event['id'], 'verses_suggest', [
        'theme' => $theme, 'count' => $count, 'tone' => $tone,
    ], ['verses' => $out, 'dropped' => $dropped], $actorId);

    return ['job_id' => $jobId, 'verses' => $out, 'dropped' => $dropped];
}
