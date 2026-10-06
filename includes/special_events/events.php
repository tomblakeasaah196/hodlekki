<?php
// /includes/special_events/events.php
//
// The event aggregate: create, read, update (optimistic concurrency), the
// lifecycle (publish / unpublish / cancel / archive / delete draft), the
// computed phase, event days, slugs with aliases and reclaiming, and cloning.
//
// Guide: §9.5 (integrity), §10.1 (lifecycle and phases), §10.2 (slugs),
// §10.10 (cloning), Appendix H.1 (publish readiness).

// --------------------------------------------------------------------------
// Reading
// --------------------------------------------------------------------------

/** Columns the Studio may write, grouped by the `section` an update names. */
const SE_EVENT_SECTIONS = [
    'details' => [
        'title', 'edition_label', 'tagline', 'description_md', 'organizer_label',
        'venue_name', 'venue_address', 'venue_map_url', 'venue_notes',
        'slug', 'series_id', 'visibility',
    ],
    'brand' => [
        'theme_preset', 'brand_primary', 'brand_secondary', 'brand_accent',
        'font_display', 'font_body',
        'logo_asset_id', 'hero_asset_id', 'hero_video_asset_id', 'og_asset_id',
    ],
    'registration' => [
        'reg_opens_at', 'reg_closes_at', 'online_capacity', 'auto_close_at_capacity',
        'waitlist_enabled', 'waitlist_capacity', 'waitlist_promotion', 'waitlist_notify_sms',
        'self_cancel_enabled', 'walkin_enabled', 'walkin_capacity', 'walkin_hard_cap',
        'seats_left_mode', 'seats_left_threshold_pct',
    ],
    'settings' => ['settings_json'],
];

function se_event_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

function se_event_find_by_public_id(PDO $pdo, string $publicId): ?array
{
    if (!preg_match('/^[0-9A-Z]{1,12}$/', $publicId)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_events WHERE public_id = ?");
    $stmt->execute([$publicId]);

    return $stmt->fetch() ?: null;
}

/**
 * Resolve a slug to ['event' => array, 'canonical' => string, 'is_alias' => bool].
 * Null when the slug has never pointed at an event.
 */
function se_event_find_by_slug(PDO $pdo, string $slug): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }

    // e.* last, so se_events.slug wins: that column IS the canonical slug
    // by definition (§9.5 item 5), whether we arrived by it or by an alias.
    $stmt = $pdo->prepare(
        "SELECT e.* FROM se_slugs s JOIN se_events e ON e.id = s.event_id WHERE s.slug = ?"
    );
    $stmt->execute([$slug]);
    $event = $stmt->fetch();
    if (!$event) {
        return null;
    }
    $canonical = (string) $event['slug'];

    return [
        'event'     => $event,
        'canonical' => $canonical,
        'is_alias'  => $canonical !== $slug,
    ];
}

/** The event's days, ordered. */
function se_event_days(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM se_event_days WHERE event_id = ? ORDER BY day_date ASC, starts_at ASC"
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

/** The event's normalised settings document (Appendix E). */
function se_event_settings(array $event): array
{
    return se_settings_normalize($event['settings_json'] ?? null);
}

// --------------------------------------------------------------------------
// Phase (§10.1)
// --------------------------------------------------------------------------

/**
 * The computed phase of an event. Never stored.
 *
 * Returns ['phase' => 'draft'|'cancelled'|'archived'|'upcoming'|'live'
 *          |'between_days'|'post', ...] and always carries 'first_day' and
 * 'last_day' when the event has days.
 */
function se_event_phase(array $event, array $days, ?DateTimeImmutable $now = null): array
{
    $now ??= se_now();

    $status = (string) ($event['status'] ?? 'draft');
    if ($status === 'draft')     { return ['phase' => 'draft']; }
    if ($status === 'cancelled') { return ['phase' => 'cancelled']; }
    if ($status === 'archived')  { return ['phase' => 'archived']; }

    if (!$days) {
        // Published with no days should be impossible (H.1 blocks it), but a
        // phase function must never divide by an empty list.
        return ['phase' => 'upcoming'];
    }

    $first = $days[0];
    $last  = $days[count($days) - 1];
    $base  = ['first_day' => $first, 'last_day' => $last];

    $doorsFirst = se_parse_datetime($first['doors_open_at']);
    if ($doorsFirst !== null && $now < $doorsFirst) {
        return $base + ['phase' => 'upcoming', 'next_day' => $first];
    }

    foreach ($days as $day) {
        $doors   = se_parse_datetime($day['doors_open_at']);
        $ends    = se_parse_datetime($day['ends_at']);
        $ciClose = se_parse_datetime($day['checkin_closes_at']);
        if ($doors === null || $ends === null) {
            continue;
        }
        $liveEnd = ($ciClose !== null && $ciClose > $ends) ? $ciClose : $ends;

        if ($now >= $doors && $now <= $liveEnd) {
            return $base + [
                'phase'        => 'live',
                'day'          => $day,
                'checkin_open' => $ciClose !== null && $now <= $ciClose,
            ];
        }
    }

    $lastEnds   = se_parse_datetime($last['ends_at']);
    $lastClose  = se_parse_datetime($last['checkin_closes_at']);
    $lastMoment = $lastEnds;
    if ($lastClose !== null && ($lastMoment === null || $lastClose > $lastMoment)) {
        $lastMoment = $lastClose;
    }
    if ($lastMoment !== null && $now > $lastMoment) {
        return $base + ['phase' => 'post'];
    }

    foreach ($days as $day) {
        $doors = se_parse_datetime($day['doors_open_at']);
        if ($doors !== null && $doors > $now) {
            return $base + ['phase' => 'between_days', 'next_day' => $day];
        }
    }

    return $base + ['phase' => 'post'];
}

// --------------------------------------------------------------------------
// Slugs (§10.2)
// --------------------------------------------------------------------------

/**
 * Validate a slug. Returns ['ok' => bool, 'slug' => string, 'reason' => ?string,
 * 'suggestion' => ?string]. Bad characters are REJECTED rather than silently
 * dropped (§10.2 item 1), with a suggestion the Studio can offer.
 */
function se_slug_validate(string $input): array
{
    $raw = trim($input);
    $slug = strtolower($raw);
    $slug = preg_replace('/\s+/', '-', $slug) ?? '';

    $suggestion = se_slug_suggest($raw);

    if ($slug === '') {
        return ['ok' => false, 'slug' => '', 'reason' => 'Enter a short name for the link.', 'suggestion' => null];
    }
    if (mb_strlen($slug, 'UTF-8') > SE_SLUG_MAX_LENGTH) {
        return ['ok' => false, 'slug' => $slug, 'reason' => 'That is longer than 40 characters.', 'suggestion' => $suggestion];
    }
    if (!preg_match(SE_SLUG_PATTERN, $slug)) {
        return [
            'ok'         => false,
            'slug'       => $slug,
            'reason'     => 'Use lowercase letters, numbers and hyphens only, and do not start or end with a hyphen.',
            'suggestion' => $suggestion !== $slug ? $suggestion : null,
        ];
    }
    if (in_array($slug, SE_RESERVED_SLUGS, true)) {
        return [
            'ok'         => false,
            'slug'       => $slug,
            'reason'     => 'That word is reserved by the site. Please pick another.',
            'suggestion' => $slug . '-live',
        ];
    }

    return ['ok' => true, 'slug' => $slug, 'reason' => null, 'suggestion' => null];
}

/** A best-effort valid slug derived from arbitrary text (for the suggestion). */
function se_slug_suggest(string $text): string
{
    $slug = strtolower(trim($text));
    // Transliterate the common accented letters rather than dropping them.
    $slug = strtr($slug, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ẹ' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ọ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ş' => 's', 'ṣ' => 's', 'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'ß' => 'ss',
    ]);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, SE_SLUG_MAX_LENGTH);
    $slug = trim($slug, '-');

    if ($slug === '' || !preg_match(SE_SLUG_PATTERN, $slug)) {
        return 'event-' . strtolower(se_random_code(5));
    }
    if (in_array($slug, SE_RESERVED_SLUGS, true)) {
        return $slug . '-live';
    }

    return $slug;
}

/** True when nothing in se_slugs holds this slug. */
function se_slug_available(PDO $pdo, string $slug, ?int $ignoreEventId = null): bool
{
    $stmt = $pdo->prepare("SELECT event_id FROM se_slugs WHERE slug = ?");
    $stmt->execute([$slug]);
    $holder = $stmt->fetchColumn();

    if ($holder === false) {
        return true;
    }

    return $ignoreEventId !== null && (int) $holder === $ignoreEventId;
}

/** Append -2, -3 … until the slug is free. */
function se_slug_make_unique(PDO $pdo, string $base, ?int $ignoreEventId = null): string
{
    $base = substr($base, 0, SE_SLUG_MAX_LENGTH);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'event';
    }
    if (se_slug_available($pdo, $base, $ignoreEventId)) {
        return $base;
    }
    for ($i = 2; $i <= 200; $i++) {
        $suffix = '-' . $i;
        $slug   = substr($base, 0, SE_SLUG_MAX_LENGTH - strlen($suffix)) . $suffix;
        if (se_slug_available($pdo, $slug, $ignoreEventId)) {
            return $slug;
        }
    }

    return substr($base, 0, SE_SLUG_MAX_LENGTH - 7) . '-' . strtolower(se_random_code(5));
}

/**
 * Change an event's canonical slug (§10.2 item 5). The old slug stays as an
 * alias so printed QR codes keep working (301). Runs in one transaction.
 */
function se_slug_change(PDO $pdo, int $eventId, string $newSlug, ?int $actorId): void
{
    $check = se_slug_validate($newSlug);
    if (!$check['ok']) {
        throw new SeValidationException(['slug' => $check['reason']]);
    }
    $slug = $check['slug'];

    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("SELECT slug FROM se_events WHERE id = ? FOR UPDATE");
        $stmt->execute([$eventId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new SeNotFoundException('Event not found.');
        }
        if ((string) $current === $slug) {
            if ($owns) { $pdo->commit(); }
            return;
        }

        if (!se_slug_available($pdo, $slug, $eventId)) {
            throw new SeRuleException('VALIDATION', 'That link is already taken.');
        }

        // Demote the old canonical row to an alias, point the new one at us.
        $pdo->prepare("UPDATE se_slugs SET is_canonical = 0 WHERE event_id = ?")->execute([$eventId]);
        $pdo->prepare(
            "INSERT INTO se_slugs (slug, event_id, is_canonical) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), is_canonical = 1"
        )->execute([$slug, $eventId]);
        $pdo->prepare("UPDATE se_events SET slug = ?, updated_by = ? WHERE id = ?")
            ->execute([$slug, $actorId, $eventId]);

        if ($owns) { $pdo->commit(); }

        se_audit($pdo, $eventId, 'slug_change', ['from' => $current, 'to' => $slug], 'event', $eventId, $actorId);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Reclaim a slug currently held by an ARCHIVED event (§10.2 item 4) — the
 * annual-series case: next year's Chara wants /e/chara, and last year's
 * edition moves to /e/chara-2026.
 *
 * Allowed for managers, or for the Producer when both events share a series.
 */
function se_slug_reclaim(PDO $pdo, int $eventId, string $slug, ?int $actorId): array
{
    $check = se_slug_validate($slug);
    if (!$check['ok']) {
        throw new SeValidationException(['slug' => $check['reason']]);
    }
    $slug = $check['slug'];

    $owns = !$pdo->inTransaction();
    if ($owns) { $pdo->beginTransaction(); }

    try {
        $stmt = $pdo->prepare("SELECT event_id FROM se_slugs WHERE slug = ? FOR UPDATE");
        $stmt->execute([$slug]);
        $holderId = $stmt->fetchColumn();

        if ($holderId === false) {
            // Nobody holds it: an ordinary rename.
            se_slug_change($pdo, $eventId, $slug, $actorId);
            if ($owns) { $pdo->commit(); }
            return ['reclaimed_from' => null, 'old_event_slug' => null, 'slug' => $slug];
        }
        $holderId = (int) $holderId;
        if ($holderId === $eventId) {
            if ($owns) { $pdo->commit(); }
            return ['reclaimed_from' => null, 'old_event_slug' => null, 'slug' => $slug];
        }

        $holder = se_event_find($pdo, $holderId);
        $target = se_event_find($pdo, $eventId);
        if (!$holder || !$target) {
            throw new SeNotFoundException('Event not found.');
        }
        if ((string) $holder['status'] !== 'archived') {
            throw new SeRuleException(
                'VALIDATION',
                'That link belongs to "' . $holder['title'] . '", which is not archived yet. Archive it first, or pick another link.'
            );
        }

        // Permission: manager, or the producer of both when they share a series.
        $isManager = se_module_access($pdo, (int) $actorId, se_session_roles()) === 'manager';
        if (!$isManager) {
            $sameSeries = $holder['series_id'] !== null
                && (int) $holder['series_id'] === (int) ($target['series_id'] ?? 0);
            if (!$sameSeries) {
                throw new SeRuleException(
                    'FORBIDDEN',
                    'Only an administrator can reclaim a link from another series.'
                );
            }
        }

        // Move the old event aside: <slug>-<edition or year>, then -2, -3 …
        $labelPart = se_line($holder['edition_label'] ?? '', 20);
        if ($labelPart === '') {
            $startsAt  = se_parse_datetime($holder['starts_at']);
            $labelPart = $startsAt ? $startsAt->format('Y') : (string) $holder['id'];
        }
        $oldBase = se_slug_suggest($slug . '-' . $labelPart);
        $oldSlug = se_slug_make_unique($pdo, $oldBase, $holderId);

        $pdo->prepare("UPDATE se_slugs SET is_canonical = 0 WHERE event_id = ?")->execute([$holderId]);
        $pdo->prepare(
            "INSERT INTO se_slugs (slug, event_id, is_canonical) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), is_canonical = 1"
        )->execute([$oldSlug, $holderId]);
        $pdo->prepare("UPDATE se_events SET slug = ? WHERE id = ?")->execute([$oldSlug, $holderId]);

        // Point the reclaimed slug at the new event.
        $pdo->prepare("UPDATE se_slugs SET is_canonical = 0 WHERE event_id = ?")->execute([$eventId]);
        $pdo->prepare("UPDATE se_slugs SET event_id = ?, is_canonical = 1 WHERE slug = ?")
            ->execute([$eventId, $slug]);
        $pdo->prepare("UPDATE se_events SET slug = ?, updated_by = ? WHERE id = ?")
            ->execute([$slug, $actorId, $eventId]);

        if ($owns) { $pdo->commit(); }

        se_audit($pdo, $eventId, 'slug_reclaim', [
            'slug' => $slug, 'from_event_id' => $holderId, 'old_event_slug' => $oldSlug,
        ], 'event', $eventId, $actorId);

        return ['reclaimed_from' => $holderId, 'old_event_slug' => $oldSlug, 'slug' => $slug];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

// --------------------------------------------------------------------------
// Days (§10.1)
// --------------------------------------------------------------------------

/**
 * Replace an event's days.
 *
 * Each input day is {day_date|starts_at, ends_at, label?, doors_open_at?,
 * checkin_closes_at?}. Defaults: doors open `checkin.opens_minutes_before`
 * before the start, check-in closes when the day ends (§10.1).
 * se_events.starts_at/ends_at are kept in step (first start, last end).
 */
function se_event_days_save(PDO $pdo, int $eventId, array $days, array $settings, ?int $actorId): array
{
    if (!$days) {
        throw new SeValidationException(['days' => 'Add at least one day.']);
    }
    if (count($days) > SE_MAX_EVENT_DAYS) {
        throw new SeValidationException(['days' => 'An event can have at most ' . SE_MAX_EVENT_DAYS . ' days.']);
    }

    $opensBefore = (int) se_settings_path($settings, 'checkin.opens_minutes_before', 60);
    $rows        = [];
    $errors      = [];

    foreach (array_values($days) as $i => $day) {
        $starts = se_parse_datetime($day['starts_at'] ?? null);
        $ends   = se_parse_datetime($day['ends_at'] ?? null);

        if ($starts === null) {
            $errors["days.{$i}.starts_at"] = 'Enter a start time.';
            continue;
        }
        if ($ends === null) {
            $errors["days.{$i}.ends_at"] = 'Enter an end time.';
            continue;
        }
        if ($ends <= $starts) {
            $errors["days.{$i}.ends_at"] = 'The end time must be after the start time.';
            continue;
        }

        $doors = se_parse_datetime($day['doors_open_at'] ?? null)
            ?? $starts->sub(new DateInterval('PT' . max(0, $opensBefore) . 'M'));
        if ($doors > $starts) {
            $doors = $starts;
        }

        $ciCloses = se_parse_datetime($day['checkin_closes_at'] ?? null) ?? $ends;
        if ($ciCloses < $doors) {
            $ciCloses = $ends;
        }

        $rows[] = [
            'day_date'          => $starts->format('Y-m-d'),
            'label'             => se_line($day['label'] ?? '', 60) ?: null,
            'doors_open_at'     => se_sql_datetime($doors),
            'starts_at'         => se_sql_datetime($starts),
            'ends_at'           => se_sql_datetime($ends),
            'checkin_closes_at' => se_sql_datetime($ciCloses),
        ];
    }

    if ($errors) {
        throw new SeValidationException($errors);
    }

    usort($rows, static fn($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

    // Two days on the same calendar date would break UNIQUE(event_id, day_date).
    $seen = [];
    foreach ($rows as $i => $row) {
        if (isset($seen[$row['day_date']])) {
            throw new SeValidationException([
                "days.{$i}.starts_at" => 'There is already a day on ' . $row['day_date'] . '. Use one row per date.',
            ]);
        }
        $seen[$row['day_date']] = true;
    }

    $owns = !$pdo->inTransaction();
    if ($owns) { $pdo->beginTransaction(); }

    try {
        $keepDates = array_column($rows, 'day_date');
        $in        = implode(',', array_fill(0, count($keepDates), '?'));
        $pdo->prepare("DELETE FROM se_event_days WHERE event_id = ? AND day_date NOT IN ({$in})")
            ->execute(array_merge([$eventId], $keepDates));

        $stmt = $pdo->prepare(
            "INSERT INTO se_event_days
                (event_id, day_date, label, doors_open_at, starts_at, ends_at, checkin_closes_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), doors_open_at = VALUES(doors_open_at),
                starts_at = VALUES(starts_at), ends_at = VALUES(ends_at),
                checkin_closes_at = VALUES(checkin_closes_at)"
        );
        foreach ($rows as $row) {
            $stmt->execute([
                $eventId, $row['day_date'], $row['label'], $row['doors_open_at'],
                $row['starts_at'], $row['ends_at'], $row['checkin_closes_at'],
            ]);
        }

        $pdo->prepare("UPDATE se_events SET starts_at = ?, ends_at = ?, updated_by = ? WHERE id = ?")
            ->execute([$rows[0]['starts_at'], $rows[count($rows) - 1]['ends_at'], $actorId, $eventId]);

        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    se_audit($pdo, $eventId, 'event_update:days', ['days' => count($rows)], 'event', $eventId, $actorId);

    return se_event_days($pdo, $eventId);
}

// --------------------------------------------------------------------------
// Create
// --------------------------------------------------------------------------

/**
 * Create a draft event. The creator becomes its Producer (§6.2, D6).
 *
 * @param array $input {title, slug, days[], tagline?, venue_name?, series_id?,
 *                      series_name?, brand_primary?, brand_secondary?,
 *                      online_capacity?, edition_label?}
 */
function se_event_create(PDO $pdo, array $input, int $actorId): array
{
    $title = se_line($input['title'] ?? '', 120);
    if ($title === '') {
        throw new SeValidationException(['title' => 'Give the event a name.']);
    }

    $slugCheck = se_slug_validate((string) ($input['slug'] ?? '') !== ''
        ? (string) $input['slug']
        : se_slug_suggest($title));
    if (!$slugCheck['ok']) {
        throw new SeValidationException(['slug' => $slugCheck['reason']]);
    }
    $slug = $slugCheck['slug'];

    // A new event's programme starts unpublished (§10.7.2); a clone passes
    // the source event's settings and keeps whatever that one chose.
    $settings = se_settings_for_new_event($input['settings'] ?? []);

    // PDO cannot nest a transaction, so join the caller's when there is
    // one (se_clone_event wraps create; create wraps days_save).
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        if (!se_slug_available($pdo, $slug)) {
            throw new SeRuleException('VALIDATION', 'That link is already taken. Try another.');
        }

        // Series: either an existing id or a name to create on the fly (D27).
        $seriesId = se_int_or_null($input['series_id'] ?? null, 1);
        if ($seriesId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM se_series WHERE id = ?");
            $stmt->execute([$seriesId]);
            if ($stmt->fetchColumn() === false) {
                $seriesId = null;
            }
        }
        if ($seriesId === null && se_line($input['series_name'] ?? '', 120) !== '') {
            $stmt = $pdo->prepare("INSERT INTO se_series (name, created_by) VALUES (?, ?)");
            $stmt->execute([se_line($input['series_name'], 120), $actorId]);
            $seriesId = (int) $pdo->lastInsertId();
        }

        $defaults  = se_settings_all($pdo);
        $primary   = se_normalize_hex($input['brand_primary'] ?? '')
            ?? se_normalize_hex($defaults['default_brand_primary'] ?? '') ?? '#1D356A';
        $secondary = se_normalize_hex($input['brand_secondary'] ?? '')
            ?? se_normalize_hex($defaults['default_brand_secondary'] ?? '') ?? '#D11920';
        $accent    = se_normalize_hex($input['brand_accent'] ?? '');
        $preset    = se_enum($input['theme_preset'] ?? ($defaults['default_theme_preset'] ?? 'marquee'), SE_THEME_PRESETS, 'marquee');
        $theme     = se_theme_derive($primary, $secondary, $accent, $preset);

        // A unique public id. The UNIQUE key is the real guard; the loop only
        // avoids surfacing a collision to the user.
        $publicId = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = se_new_public_id();
            $stmt = $pdo->prepare("SELECT 1 FROM se_events WHERE public_id = ?");
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false) {
                $publicId = $candidate;
                break;
            }
        }
        if ($publicId === null) {
            throw new RuntimeException('Could not allocate a public id.');
        }

        // Day times are needed up front for starts_at/ends_at (NOT NULL).
        $days = is_array($input['days'] ?? null) ? $input['days'] : [];
        if (!$days) {
            $start = se_now()->modify('+14 days')->setTime(17, 0);
            $days  = [[
                'starts_at' => se_sql_datetime($start),
                'ends_at'   => se_sql_datetime($start->modify('+4 hours')),
            ]];
        }
        $firstStart = se_parse_datetime($days[0]['starts_at'] ?? null) ?? se_now()->modify('+14 days');
        $lastEnd    = se_parse_datetime($days[count($days) - 1]['ends_at'] ?? null) ?? $firstStart->modify('+4 hours');

        $stmt = $pdo->prepare(
            "INSERT INTO se_events (
                public_id, preview_key, series_id, slug, title, edition_label, tagline,
                organizer_label, venue_name, starts_at, ends_at, status, visibility,
                theme_preset, brand_primary, brand_secondary, brand_accent, palette_json,
                font_display, font_body, online_capacity, settings_json, created_by, updated_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $publicId,
            se_random_token(SE_PREVIEW_KEY_LENGTH),
            $seriesId,
            $slug,
            $title,
            se_line($input['edition_label'] ?? '', 40) ?: null,
            se_line($input['tagline'] ?? '', 160) ?: null,
            se_line($input['organizer_label'] ?? 'Envision', 80) ?: 'Envision',
            se_line($input['venue_name'] ?? '', 160) ?: null,
            se_sql_datetime($firstStart),
            se_sql_datetime($lastEnd),
            se_enum($input['visibility'] ?? 'unlisted', ['public', 'unlisted'], 'unlisted'),
            $preset,
            $primary,
            $secondary,
            $accent,
            se_json_encode($theme),
            se_enum($input['font_display'] ?? 'Unbounded', array_keys(SE_FONT_PAIRS), 'Unbounded'),
            se_line($input['font_body'] ?? 'Inter', 60) ?: 'Inter',
            se_int_or_null($input['online_capacity'] ?? null, 0, 1000000),
            se_json_encode($settings),
            $actorId,
            $actorId,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO se_slugs (slug, event_id, is_canonical) VALUES (?, ?, 1)")
            ->execute([$slug, $eventId]);

        // The creator is the Producer (D6).
        if (se_table_exists($pdo, 'se_crew')) {
            $pdo->prepare(
                "INSERT INTO se_crew (event_id, user_id, role, added_by) VALUES (?, ?, 'producer', ?)
                 ON DUPLICATE KEY UPDATE revoked_at = NULL"
            )->execute([$eventId, $actorId, $actorId]);
        }

        se_event_days_save($pdo, $eventId, $days, $settings, $actorId);

        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    se_audit($pdo, $eventId, 'event_create', ['title' => $title, 'slug' => $slug], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

// --------------------------------------------------------------------------
// Update (optimistic concurrency, §9.3)
// --------------------------------------------------------------------------

/**
 * Update one section of an event.
 *
 * Every write carries the row_version the Studio loaded. The UPDATE is
 * guarded by `WHERE row_version = :expected`; 0 affected rows means someone
 * else saved first, and the caller gets STALE_VERSION so it can offer
 * "reload or overwrite" (§13.13) rather than silently clobbering their work.
 *
 * @param string $section One of SE_EVENT_SECTIONS.
 */
function se_event_update(PDO $pdo, int $eventId, string $section, array $fields, int $expectedVersion, ?int $actorId): array
{
    if (!isset(SE_EVENT_SECTIONS[$section])) {
        throw new SeValidationException(['section' => 'Unknown section.']);
    }

    $event = se_event_find($pdo, $eventId);
    if (!$event) {
        throw new SeNotFoundException('Event not found.');
    }
    if ((string) $event['status'] === 'archived') {
        throw new SeRuleException('EVENT_ARCHIVED', 'This event is archived and can no longer be edited.');
    }

    $allowed = SE_EVENT_SECTIONS[$section];
    $set     = [];
    $values  = [];
    $errors  = [];
    $slugChange = null;
    $settingsChange = null;

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;   // Unknown fields are ignored (§12.1).
        }

        switch ($key) {
            // --- Details -------------------------------------------------
            case 'title':
                $v = se_line($value, 120);
                if ($v === '') { $errors['title'] = 'Give the event a name.'; break; }
                $set[] = 'title = ?'; $values[] = $v;
                break;
            case 'edition_label':   $set[] = 'edition_label = ?';   $values[] = se_line($value, 40) ?: null; break;
            case 'tagline':         $set[] = 'tagline = ?';         $values[] = se_line($value, 160) ?: null; break;
            case 'description_md':  $set[] = 'description_md = ?';  $values[] = se_str($value, 20000) ?: null; break;
            case 'organizer_label': $set[] = 'organizer_label = ?'; $values[] = se_line($value, 80) ?: 'Envision'; break;
            case 'venue_name':      $set[] = 'venue_name = ?';      $values[] = se_line($value, 160) ?: null; break;
            case 'venue_address':   $set[] = 'venue_address = ?';   $values[] = se_line($value, 255) ?: null; break;
            case 'venue_notes':     $set[] = 'venue_notes = ?';     $values[] = se_line($value, 255) ?: null; break;

            case 'venue_map_url':
                $url = se_line($value, 500);
                if ($url !== '' && !preg_match('#^https://[^\s]+$#i', $url)) {
                    $errors['venue_map_url'] = 'Use a full https:// link, or leave it empty.';
                    break;
                }
                $set[] = 'venue_map_url = ?'; $values[] = $url ?: null;
                break;

            case 'visibility':
                $set[] = 'visibility = ?'; $values[] = se_enum($value, ['public', 'unlisted'], 'unlisted');
                break;

            case 'series_id':
                $sid = se_int_or_null($value, 1);
                if ($sid !== null) {
                    $stmt = $pdo->prepare("SELECT 1 FROM se_series WHERE id = ?");
                    $stmt->execute([$sid]);
                    if ($stmt->fetchColumn() === false) { $sid = null; }
                }
                $set[] = 'series_id = ?'; $values[] = $sid;
                break;

            case 'slug':
                // Handled after the version check, in its own transaction step.
                $slugChange = (string) $value;
                break;

            // --- Brand ---------------------------------------------------
            case 'theme_preset':
                $set[] = 'theme_preset = ?'; $values[] = se_enum($value, SE_THEME_PRESETS, 'marquee');
                break;
            case 'brand_primary':
            case 'brand_secondary':
                $hex = se_normalize_hex($value);
                if ($hex === null) {
                    $errors[$key] = 'Enter a hex colour like #D11920.';
                    break;
                }
                $set[] = "{$key} = ?"; $values[] = $hex;
                break;
            case 'brand_accent':
                $hex = ($value === null || $value === '') ? null : se_normalize_hex($value);
                if ($value !== null && $value !== '' && $hex === null) {
                    $errors[$key] = 'Enter a hex colour like #00E5FF, or leave it empty.';
                    break;
                }
                $set[] = 'brand_accent = ?'; $values[] = $hex;
                break;
            case 'font_display':
                $set[] = 'font_display = ?'; $values[] = se_enum($value, array_keys(SE_FONT_PAIRS), 'Unbounded');
                break;
            case 'font_body':
                $set[] = 'font_body = ?'; $values[] = se_line($value, 60) ?: 'Inter';
                break;
            case 'logo_asset_id':
            case 'hero_asset_id':
            case 'hero_video_asset_id':
            case 'og_asset_id':
                $assetId = se_int_or_null($value, 1);
                if ($assetId !== null && !se_asset_belongs_to_event($pdo, $assetId, $eventId)) {
                    $errors[$key] = 'That file is not in this event\'s assets.';
                    break;
                }
                $set[] = "{$key} = ?"; $values[] = $assetId;
                break;

            // --- Registration (capacity) ---------------------------------
            case 'reg_opens_at':
            case 'reg_closes_at':
                $dt = ($value === null || $value === '') ? null : se_parse_datetime((string) $value);
                $set[] = "{$key} = ?"; $values[] = $dt ? se_sql_datetime($dt) : null;
                break;
            case 'online_capacity':
            case 'waitlist_capacity':
            case 'walkin_capacity':
                $set[] = "{$key} = ?"; $values[] = se_int_or_null($value, 0, 1000000);
                break;
            case 'auto_close_at_capacity':
            case 'waitlist_enabled':
            case 'waitlist_notify_sms':
            case 'self_cancel_enabled':
            case 'walkin_enabled':
            case 'walkin_hard_cap':
                $set[] = "{$key} = ?"; $values[] = se_bool($value) ? 1 : 0;
                break;
            case 'waitlist_promotion':
                $set[] = 'waitlist_promotion = ?'; $values[] = se_enum($value, ['auto_confirm', 'manual'], 'auto_confirm');
                break;
            case 'seats_left_mode':
                $set[] = 'seats_left_mode = ?'; $values[] = se_enum($value, ['never', 'threshold', 'always'], 'never');
                break;
            case 'seats_left_threshold_pct':
                $set[] = 'seats_left_threshold_pct = ?'; $values[] = se_int($value, 0, 100, 70);
                break;

            // --- Settings document ---------------------------------------
            case 'settings_json':
                $incoming = is_array($value) ? $value : se_json_decode(is_string($value) ? $value : null);
                // Merge over what is stored, so a tab may save just its own branch.
                $merged = se_settings_merge(se_event_settings($event), $incoming);
                $settingsChange = se_settings_normalize($merged);
                $set[] = 'settings_json = ?'; $values[] = se_json_encode($settingsChange);
                break;
        }
    }

    if ($errors) {
        throw new SeValidationException($errors);
    }

    // Re-derive the palette when any of its inputs moved (§13.2.2).
    if ($section === 'brand') {
        $next      = array_merge($event, $fields);
        $primary   = se_normalize_hex($next['brand_primary'] ?? '') ?? (string) $event['brand_primary'];
        $secondary = se_normalize_hex($next['brand_secondary'] ?? '') ?? (string) $event['brand_secondary'];
        $accentRaw = array_key_exists('brand_accent', $fields) ? $fields['brand_accent'] : ($event['brand_accent'] ?? null);
        $accent    = ($accentRaw === null || $accentRaw === '') ? null : se_normalize_hex($accentRaw);
        $preset    = se_enum($next['theme_preset'] ?? 'marquee', SE_THEME_PRESETS, 'marquee');

        $set[] = 'palette_json = ?';
        $values[] = se_json_encode(se_theme_derive($primary, $secondary, $accent, $preset));
    }

    // PDO cannot nest a transaction, so join the caller's when there is
    // one (se_clone_event wraps create; create wraps days_save).
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        if ($set) {
            $set[] = 'updated_by = ?';
            $values[] = $actorId;

            $sql = "UPDATE se_events SET " . implode(', ', $set)
                 . ", row_version = row_version + 1 WHERE id = ? AND row_version = ?";
            $values[] = $eventId;
            $values[] = $expectedVersion;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            if ($stmt->rowCount() === 0) {
                throw new SeStaleVersionException('Someone else saved this event while you were editing.');
            }
        } elseif ($slugChange === null) {
            // Nothing recognised: still verify the version so the client learns
            // about a conflict rather than believing an empty save succeeded.
            $stmt = $pdo->prepare("SELECT row_version FROM se_events WHERE id = ?");
            $stmt->execute([$eventId]);
            if ((int) $stmt->fetchColumn() !== $expectedVersion) {
                throw new SeStaleVersionException('Someone else saved this event while you were editing.');
            }
        }

        if ($slugChange !== null && strtolower(trim($slugChange)) !== (string) $event['slug']) {
            se_slug_change($pdo, $eventId, $slugChange, $actorId);
        }

        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    se_audit($pdo, $eventId, 'event_update:' . $section, ['fields' => array_keys($fields)], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

/**
 * Patch one branch of `settings_json` without a version stamp.
 *
 * se_event_update() is the form path: it demands `expected_row_version`
 * because two people editing the same tab must collide. A toggle like
 * "publish the song list" or "test mode" is not a form — it is one switch,
 * last press wins — so it goes through here instead of inventing a version
 * the caller never had (§12.5, PR4 note in §28.4).
 */
function se_event_settings_patch(PDO $pdo, array $event, array $patch, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    if ((string) $event['status'] === 'archived') {
        throw new SeRuleException('EVENT_ARCHIVED', 'This event is archived and can no longer be edited.');
    }

    $merged = se_settings_normalize(se_settings_merge(se_event_settings($event), $patch));

    $pdo->prepare("UPDATE se_events SET settings_json = ?, row_version = row_version + 1, updated_by = ? WHERE id = ?")
        ->execute([se_json_encode($merged), $actorId, $eventId]);

    se_audit($pdo, $eventId, 'event_update:settings', ['keys' => array_keys($patch)], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? $event;
}

/** Deep-merge an incoming settings branch over the stored document. */
function se_settings_merge(array $base, array $incoming): array
{
    foreach ($incoming as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
            $base[$key] = se_settings_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

/** Capacity override (§10.4, D8). Audited, with the producer's note. */
function se_event_override_set(PDO $pdo, int $eventId, string $mode, string $note, ?int $actorId): array
{
    $mode = se_enum($mode, ['none', 'force_open', 'force_closed'], 'none');

    $stmt = $pdo->prepare(
        "UPDATE se_events
         SET reg_override = ?, reg_override_note = ?, reg_override_by = ?, reg_override_at = NOW(),
             row_version = row_version + 1
         WHERE id = ?"
    );
    $stmt->execute([$mode, se_line($note, 255) ?: null, $actorId, $eventId]);

    se_audit($pdo, $eventId, 'override_set', ['mode' => $mode, 'note' => se_line($note, 255)], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

// --------------------------------------------------------------------------
// Publish readiness (Appendix H.1)
// --------------------------------------------------------------------------

/**
 * The publish checklist. Returns rows of
 * ['key','label','blocking','ok','hint'] plus a readiness percentage, which
 * the Studio draws as the Overview ring (§13.13).
 */
function se_publish_checklist(PDO $pdo, array $event): array
{
    $eventId  = (int) $event['id'];
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);
    $moduleSettings = se_settings_all($pdo);

    $slugOk = false;
    $slug   = (string) ($event['slug'] ?? '');
    if ($slug !== '' && se_slug_validate($slug)['ok']) {
        $slugOk = se_slug_available($pdo, $slug, $eventId);
    }

    $daysOk = false;
    if ($days) {
        $daysOk = true;
        foreach ($days as $day) {
            $s = se_parse_datetime($day['starts_at']);
            $e = se_parse_datetime($day['ends_at']);
            if ($s === null || $e === null || $e <= $s) {
                $daysOk = false;
                break;
            }
        }
    }

    // "Capacity number or 'unlimited' explicitly chosen" — a draft that has
    // never been through the Registration tab has neither, so we treat an
    // explicit choice as recorded in the settings document.
    $capacityReviewed = $event['online_capacity'] !== null
        || se_settings_path($settings, 'registration.capacity_reviewed', false) === true;

    $consentOk = trim((string) se_settings_path($settings, 'registration.consent_text', '')) !== '';
    $privacyEmail = trim((string) ($moduleSettings['privacy_contact_email'] ?? ''));
    $emailOk = $privacyEmail !== '' && filter_var($privacyEmail, FILTER_VALIDATE_EMAIL) !== false;

    $producerOk = false;
    if (se_table_exists($pdo, 'se_crew')) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM se_crew WHERE event_id = ? AND role = 'producer' AND revoked_at IS NULL LIMIT 1"
        );
        $stmt->execute([$eventId]);
        $producerOk = $stmt->fetchColumn() !== false;
    }

    $heroOk = false;
    if (se_table_exists($pdo, 'se_assets') && !empty($event['hero_asset_id'])) {
        $stmt = $pdo->prepare(
            "SELECT alt_text FROM se_assets WHERE id = ? AND event_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([(int) $event['hero_asset_id'], $eventId]);
        $alt = $stmt->fetchColumn();
        $heroOk = $alt !== false && trim((string) $alt) !== '';
    }

    $ogOk = !empty($event['og_asset_id']);

    $items = [
        ['key' => 'title',    'label' => 'Name, link and at least one day with valid times', 'blocking' => true,
         'ok' => se_line($event['title'] ?? '', 120) !== '' && $slugOk && $daysOk,
         'hint' => 'Set these on the Details tab.'],
        ['key' => 'venue',    'label' => 'Venue name', 'blocking' => true,
         'ok' => se_line($event['venue_name'] ?? '', 160) !== '',
         'hint' => 'Details tab → Venue.'],
        ['key' => 'brand',    'label' => 'Brand primary and secondary colour', 'blocking' => true,
         'ok' => se_normalize_hex($event['brand_primary'] ?? '') !== null
              && se_normalize_hex($event['brand_secondary'] ?? '') !== null,
         'hint' => 'Brand tab. The HOD defaults count.'],
        ['key' => 'capacity', 'label' => 'Registration settings reviewed', 'blocking' => true,
         'ok' => $capacityReviewed,
         'hint' => 'Registration tab: set a capacity, or tick "No limit".'],
        ['key' => 'consent',  'label' => 'Consent text and privacy contact email set', 'blocking' => true,
         'ok' => $consentOk && $emailOk,
         'hint' => $emailOk
             ? 'Registration tab → Consent.'
             : 'An administrator must set the privacy contact email in Settings.'],
        ['key' => 'producer', 'label' => 'At least one Producer in the crew', 'blocking' => true,
         'ok' => $producerOk,
         'hint' => 'Crew tab.'],
        ['key' => 'hero',     'label' => 'Hero image (or video and poster) with alt text', 'blocking' => false,
         'ok' => $heroOk,
         'hint' => 'Assets tab. Alt text is required before an image can be saved.'],
        ['key' => 'og',       'label' => 'Link card rendered', 'blocking' => false,
         'ok' => $ogOk,
         'hint' => 'Assets tab → Link card. Comes with the Format Studio.'],
    ];

    // Items whose feature arrives in a later PR are only shown once their
    // table exists, so the checklist never asks for something unbuildable.
    if (se_table_exists($pdo, 'se_program_items')) {
        $stmt = $pdo->prepare("SELECT 1 FROM se_program_items WHERE event_id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $items[] = ['key' => 'program', 'label' => 'Programme has at least one item', 'blocking' => false,
                    'ok' => $stmt->fetchColumn() !== false, 'hint' => 'Programme tab.'];
    }
    if (se_table_exists($pdo, 'se_teams') && se_settings_path($settings, 'teams.enabled', true)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_teams WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $items[] = ['key' => 'teams', 'label' => 'Teams configured (needed before check-in opens)', 'blocking' => false,
                    'ok' => (int) $stmt->fetchColumn() >= SE_MIN_TEAMS, 'hint' => 'Teams tab.'];
    }

    $blockers = array_values(array_filter($items, static fn($i) => $i['blocking'] && !$i['ok']));
    $done     = count(array_filter($items, static fn($i) => $i['ok']));

    return [
        'items'      => $items,
        'blockers'   => $blockers,
        'can_publish' => $blockers === [],
        'ready_pct'  => $items ? (int) round($done / count($items) * 100) : 0,
    ];
}

// --------------------------------------------------------------------------
// Lifecycle (§10.1)
// --------------------------------------------------------------------------

function se_event_publish(PDO $pdo, int $eventId, ?int $actorId): array
{
    $event = se_event_find($pdo, $eventId);
    if (!$event) {
        throw new SeNotFoundException('Event not found.');
    }
    if ((string) $event['status'] === 'published') {
        return $event;
    }
    if (in_array((string) $event['status'], ['archived', 'cancelled'], true)) {
        throw new SeRuleException('VALIDATION', 'A ' . $event['status'] . ' event cannot be published.');
    }

    $checklist = se_publish_checklist($pdo, $event);
    if (!$checklist['can_publish']) {
        throw new SeRuleException(
            'VALIDATION',
            'A few things still need finishing before this event can go live.',
            ['blockers' => $checklist['blockers']]
        );
    }

    $pdo->prepare(
        "UPDATE se_events SET status = 'published', published_at = COALESCE(published_at, NOW()),
                updated_by = ?, row_version = row_version + 1
         WHERE id = ? AND status = 'draft'"
    )->execute([$actorId, $eventId]);

    se_audit($pdo, $eventId, 'event_publish', [], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

/** Unpublish. Only while the event has no registrations at all (§10.1). */
function se_event_unpublish(PDO $pdo, int $eventId, ?int $actorId): array
{
    $count = se_event_registration_count($pdo, $eventId);
    if ($count > 0) {
        throw new SeRuleException(
            'VALIDATION',
            $count === 1
                ? 'Someone has already registered, so this event cannot go back to draft. Use "Pause registration" instead.'
                : $count . ' people have already registered, so this event cannot go back to draft. Use "Pause registration" instead.'
        );
    }

    $pdo->prepare(
        "UPDATE se_events SET status = 'draft', updated_by = ?, row_version = row_version + 1
         WHERE id = ? AND status = 'published'"
    )->execute([$actorId, $eventId]);

    se_audit($pdo, $eventId, 'event_unpublish', [], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

function se_event_cancel(PDO $pdo, int $eventId, string $reason, ?int $actorId): array
{
    $reason = se_line($reason, 255);
    if ($reason === '') {
        throw new SeValidationException(['reason' => 'Say why the event is cancelled — guests will see this.']);
    }

    $pdo->prepare(
        "UPDATE se_events
         SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(),
             reg_override = 'force_closed', updated_by = ?, row_version = row_version + 1
         WHERE id = ? AND status IN ('draft', 'published')"
    )->execute([$reason, $actorId, $eventId]);

    se_audit($pdo, $eventId, 'event_cancel', ['reason' => $reason], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

/**
 * Archive. Manager only, and not before the event has actually finished
 * (at least 24 hours after the last day ends, §10.1).
 */
function se_event_archive(PDO $pdo, int $eventId, ?int $actorId, bool $force = false): array
{
    $event = se_event_find($pdo, $eventId);
    if (!$event) {
        throw new SeNotFoundException('Event not found.');
    }
    if ((string) $event['status'] === 'archived') {
        return $event;
    }

    if (!$force && (string) $event['status'] !== 'cancelled') {
        $ends = se_parse_datetime($event['ends_at']);
        if ($ends !== null && se_now() < $ends->modify('+24 hours')) {
            throw new SeRuleException(
                'VALIDATION',
                'An event can be archived 24 hours after it ends. Cancel it instead if it is not happening.'
            );
        }
    }

    $pdo->prepare(
        "UPDATE se_events SET status = 'archived', archived_at = NOW(),
                updated_by = ?, row_version = row_version + 1
         WHERE id = ?"
    )->execute([$actorId, $eventId]);

    se_audit($pdo, $eventId, 'event_archive', [], 'event', $eventId, $actorId);

    return se_event_find($pdo, $eventId) ?? [];
}

/**
 * Delete a DRAFT event (§9.5 item 1). Manager only.
 *
 * Karaoke rows go first: fk_se_karaoke_reg is ON DELETE RESTRICT because
 * MySQL 8 forbids CASCADE on a column that feeds a stored generated column,
 * so the outcome must not depend on the order InnoDB happens to cascade in.
 * Contacts are never deleted by an event deletion.
 */
function se_event_delete_draft(PDO $pdo, int $eventId, ?int $actorId): void
{
    $event = se_event_find($pdo, $eventId);
    if (!$event) {
        throw new SeNotFoundException('Event not found.');
    }
    if ((string) $event['status'] !== 'draft') {
        throw new SeRuleException('VALIDATION', 'Only a draft event can be deleted. Archive this one instead.');
    }

    $title = (string) $event['title'];
    $slug  = (string) $event['slug'];

    // PDO cannot nest a transaction, so join the caller's when there is
    // one (se_clone_event wraps create; create wraps days_save).
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        if (se_table_exists($pdo, 'se_karaoke_entries')) {
            $pdo->prepare("DELETE FROM se_karaoke_entries WHERE event_id = ?")->execute([$eventId]);
        }
        $pdo->prepare("DELETE FROM se_events WHERE id = ? AND status = 'draft'")->execute([$eventId]);
        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // event_id is gone, so the audit row is written without it.
    se_audit($pdo, null, 'event_delete', ['event_id' => $eventId, 'title' => $title, 'slug' => $slug], 'event', $eventId, $actorId);
}

/** Registrations of any status. Zero when the table is not there yet. */
function se_event_registration_count(PDO $pdo, int $eventId): int
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_registrations WHERE event_id = ?");
    $stmt->execute([$eventId]);

    return (int) $stmt->fetchColumn();
}

/** Confirmed / waitlisted / checked-in counts for the Overview KPIs. */
function se_event_counts(PDO $pdo, int $eventId): array
{
    $counts = ['confirmed' => 0, 'waitlisted' => 0, 'cancelled' => 0, 'walkin' => 0, 'checked_in' => 0, 'total' => 0];

    if (se_table_exists($pdo, 'se_registrations')) {
        $stmt = $pdo->prepare(
            "SELECT status, seat_pool, COUNT(*) AS n
             FROM se_registrations WHERE event_id = ? GROUP BY status, seat_pool"
        );
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll() as $row) {
            $n = (int) $row['n'];
            $counts['total'] += $n;
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] += $n;
            }
            if ($status === 'confirmed' && (string) $row['seat_pool'] === 'walkin') {
                $counts['walkin'] += $n;
            }
        }
    }
    if (se_table_exists($pdo, 'se_checkins')) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT registration_id) FROM se_checkins WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $counts['checked_in'] = (int) $stmt->fetchColumn();
    }

    return $counts;
}

/** True when the asset exists on this event and is not deleted. */
function se_asset_belongs_to_event(PDO $pdo, int $assetId, int $eventId): bool
{
    if (!se_table_exists($pdo, 'se_assets')) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM se_assets WHERE id = ? AND event_id = ? AND deleted_at IS NULL");
    $stmt->execute([$assetId, $eventId]);

    return $stmt->fetchColumn() !== false;
}

// --------------------------------------------------------------------------
// Cloning (§10.10)
// --------------------------------------------------------------------------

/**
 * Clone an event into a new draft, in one transaction.
 *
 * PR1 copies the tables that exist so far: details, brand (assets re-linked,
 * never re-uploaded), registration settings, custom questions, days (shifted
 * by Δ = new first start − old first start) and, optionally, the crew. Later
 * PRs extend this as their tables land; each `se_table_exists()` guard below
 * is the extension point.
 *
 * @param array $opts {details, brand, registration, form_fields, days, crew}
 */
function se_clone_event(PDO $pdo, int $sourceId, array $opts, array $input, int $actorId): array
{
    $source = se_event_find($pdo, $sourceId);
    if (!$source) {
        throw new SeNotFoundException('The event you are cloning no longer exists.');
    }

    $opt = static fn(string $k, bool $default = true): bool
        => array_key_exists($k, $opts) ? se_bool($opts[$k]) : $default;

    $title = se_line($input['title'] ?? '', 120);
    if ($title === '') {
        $title = se_line($source['title'], 110) . ' (copy)';
    }

    $sourceDays = se_event_days($pdo, $sourceId);
    $oldFirst   = $sourceDays ? se_parse_datetime($sourceDays[0]['starts_at']) : null;
    $newFirst   = se_parse_datetime($input['first_start'] ?? null);

    if ($newFirst === null) {
        // Default: same time of day, a year on — the periodic-event case (D27).
        $newFirst = $oldFirst ? $oldFirst->modify('+1 year') : se_now()->modify('+14 days')->setTime(17, 0);
    }

    // Δ is applied to every copied timestamp (§10.10).
    $deltaSeconds = ($oldFirst !== null)
        ? $newFirst->getTimestamp() - $oldFirst->getTimestamp()
        : 0;
    $shift = static function (?string $sqlDatetime) use ($deltaSeconds): ?string {
        $dt = se_parse_datetime($sqlDatetime);
        if ($dt === null) {
            return null;
        }
        return se_sql_datetime($dt->modify(($deltaSeconds >= 0 ? '+' : '-') . abs($deltaSeconds) . ' seconds'));
    };

    $days = [];
    foreach ($sourceDays as $day) {
        $days[] = [
            'starts_at'         => $shift($day['starts_at']),
            'ends_at'           => $shift($day['ends_at']),
            'doors_open_at'     => $shift($day['doors_open_at']),
            'checkin_closes_at' => $shift($day['checkin_closes_at']),
            'label'             => $day['label'],
        ];
    }

    $settings = se_event_settings($source);
    // The song list is never published on a clone, and test mode never carries.
    $settings['karaoke']['list_published'] = false;
    $settings['test_mode'] = false;
    // Nor is the programme: next year's run of show starts as last year's
    // and is then pulled apart for weeks (§10.7.2).
    $settings['program']['published'] = false;
    // Message schedules are relative (a time of day, or minutes before the
    // start), so they carry over unshifted; only an absolute stamp would need Δ.
    if (!$opt('registration')) {
        $settings['registration'] = se_settings_defaults()['registration'];
    }

    $createInput = [
        'title'           => $title,
        'slug'            => $input['slug'] ?? '',
        'days'            => $days,
        'settings'        => $settings,
        'series_id'       => $input['series_id'] ?? $source['series_id'],
        'edition_label'   => $input['edition_label'] ?? null,
        'organizer_label' => $source['organizer_label'],
        'visibility'      => $source['visibility'],
    ];
    if ($opt('details')) {
        $createInput['tagline']    = $source['tagline'];
        $createInput['venue_name'] = $source['venue_name'];
    }
    if ($opt('brand')) {
        $createInput['theme_preset']    = $source['theme_preset'];
        $createInput['brand_primary']   = $source['brand_primary'];
        $createInput['brand_secondary'] = $source['brand_secondary'];
        $createInput['brand_accent']    = $source['brand_accent'];
        $createInput['font_display']    = $source['font_display'];
        $createInput['font_body']       = $source['font_body'];
    }
    if ($opt('registration')) {
        $createInput['online_capacity'] = $source['online_capacity'];
    }

    // PDO cannot nest a transaction, so join the caller's when there is
    // one (se_clone_event wraps create; create wraps days_save).
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $new     = se_event_create($pdo, $createInput, $actorId);
        $newId   = (int) $new['id'];

        $pdo->prepare("UPDATE se_events SET cloned_from_event_id = ? WHERE id = ?")
            ->execute([$sourceId, $newId]);

        if ($opt('details')) {
            $pdo->prepare(
                "UPDATE se_events SET description_md = ?, venue_address = ?, venue_map_url = ?, venue_notes = ?
                 WHERE id = ?"
            )->execute([
                $source['description_md'], $source['venue_address'],
                $source['venue_map_url'], $source['venue_notes'], $newId,
            ]);
        }

        // Brand-kit assets are RE-LINKED, not re-uploaded (§10.10): the rows
        // stay on the source event and the new event points at the same files.
        if ($opt('brand')) {
            $pdo->prepare(
                "UPDATE se_events SET logo_asset_id = ?, hero_asset_id = ?, hero_video_asset_id = ?, og_asset_id = ?
                 WHERE id = ?"
            )->execute([
                $source['logo_asset_id'], $source['hero_asset_id'],
                $source['hero_video_asset_id'], $source['og_asset_id'], $newId,
            ]);
        }

        if ($opt('registration')) {
            $pdo->prepare(
                "UPDATE se_events SET
                    auto_close_at_capacity = ?, waitlist_enabled = ?, waitlist_capacity = ?,
                    waitlist_promotion = ?, waitlist_notify_sms = ?, self_cancel_enabled = ?,
                    walkin_enabled = ?, walkin_capacity = ?, walkin_hard_cap = ?,
                    seats_left_mode = ?, seats_left_threshold_pct = ?
                 WHERE id = ?"
            )->execute([
                $source['auto_close_at_capacity'], $source['waitlist_enabled'], $source['waitlist_capacity'],
                $source['waitlist_promotion'], $source['waitlist_notify_sms'], $source['self_cancel_enabled'],
                $source['walkin_enabled'], $source['walkin_capacity'], $source['walkin_hard_cap'],
                $source['seats_left_mode'], $source['seats_left_threshold_pct'], $newId,
            ]);
        }

        if ($opt('form_fields') && se_table_exists($pdo, 'se_form_fields')) {
            $stmt = $pdo->prepare("SELECT * FROM se_form_fields WHERE event_id = ? ORDER BY sort_order");
            $stmt->execute([$sourceId]);
            $insert = $pdo->prepare(
                "INSERT INTO se_form_fields
                    (event_id, field_key, label, type, options_json, is_required, placeholder, help_text, audience, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($stmt->fetchAll() as $f) {
                $insert->execute([
                    $newId, $f['field_key'], $f['label'], $f['type'], $f['options_json'],
                    $f['is_required'], $f['placeholder'], $f['help_text'], $f['audience'],
                    $f['sort_order'], $f['is_active'],
                ]);
            }
        }

        // Teams are the colours, not the people: a cloned event starts with
        // the same palette and empty rosters, which is exactly what a series
        // wants (§10.10). Names, captains and keys are deliberately dropped —
        // "The Lions" belonged to last year's room.
        if ($opt('teams') && se_table_exists($pdo, 'se_teams')) {
            $stmt = $pdo->prepare(
                "SELECT sort_order, color_hex, color_label FROM se_teams WHERE event_id = ? ORDER BY sort_order"
            );
            $stmt->execute([$sourceId]);
            $insert = $pdo->prepare(
                "INSERT INTO se_teams (event_id, sort_order, color_hex, color_label, team_key)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($stmt->fetchAll() as $t) {
                $insert->execute([
                    $newId, (int) $t['sort_order'], (string) $t['color_hex'],
                    (string) $t['color_label'], se_random_token(SE_DISPLAY_KEY_LENGTH),
                ]);
            }
        }

        // Verses carry their approval with them: they were read by a human
        // once and the text has not changed. Usage counts reset, because
        // "spread them evenly" is a question about tonight.
        if ($opt('verses') && se_table_exists($pdo, 'se_event_verses')) {
            $stmt = $pdo->prepare(
                "SELECT translation, ref_display, text, text_source, prayer_template, sort_order,
                        is_active, approved_by, approved_at
                   FROM se_event_verses WHERE event_id = ? ORDER BY sort_order, id"
            );
            $stmt->execute([$sourceId]);
            $insert = $pdo->prepare(
                "INSERT INTO se_event_verses
                    (event_id, translation, ref_display, text, text_source, prayer_template,
                     sort_order, is_active, approved_by, approved_at, times_used)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            );
            foreach ($stmt->fetchAll() as $v) {
                $insert->execute([
                    $newId, $v['translation'], $v['ref_display'], $v['text'], $v['text_source'],
                    $v['prayer_template'], (int) $v['sort_order'], (int) $v['is_active'],
                    $v['approved_by'], $v['approved_at'],
                ]);
            }
        }

        // The portal's chapters are page copy, so they travel with the rest
        // of the written detail (§13.3 S3). The background IMAGES do not:
        // an asset row belongs to the event it was uploaded to, and pointing
        // a clone at last year's upload is how a deleted file becomes a
        // broken card. The words, the icons and the order come across; the
        // pictures are chosen again.
        if ($opt('details') && se_chapters_ready($pdo)) {
            $stmt = $pdo->prepare(
                "SELECT chapter_key, title, blurb, icon, feature, sort_order, is_active
                   FROM se_chapters WHERE event_id = ? ORDER BY sort_order, id"
            );
            $stmt->execute([$sourceId]);
            $rows = $stmt->fetchAll() ?: [];

            if ($rows) {
                $insert = $pdo->prepare(
                    "INSERT IGNORE INTO se_chapters
                        (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($rows as $c) {
                    $insert->execute([
                        $newId, $c['chapter_key'], $c['title'], $c['blurb'], $c['icon'],
                        $c['feature'], (int) $c['sort_order'], (int) $c['is_active'], $actorId,
                    ]);
                }
            }
        }

        // Programme items carry over with their times shifted by Δ and
        // every status reset to planned (§10.10). The day map pairs the
        // source's days with the new event's, in order.
        if ($opt('program') && se_program_ready($pdo)) {
            $newDays = se_event_days($pdo, $newId);
            $dayMap  = [];
            foreach ($sourceDays as $i => $day) {
                if (isset($newDays[$i])) {
                    $dayMap[(int) $day['id']] = (int) $newDays[$i]['id'];
                }
            }
            se_program_clone($pdo, $sourceId, $newId, $dayMap, $deltaSeconds);
        }

        // The song list comes along; the claims never do (§10.10).
        if ($opt('karaoke') && se_karaoke_ready($pdo)) {
            se_karaoke_clone($pdo, $sourceId, $newId, $actorId);
        }

        if ($opt('crew', false) && se_table_exists($pdo, 'se_crew')) {
            $stmt = $pdo->prepare(
                "SELECT user_id, role FROM se_crew WHERE event_id = ? AND revoked_at IS NULL"
            );
            $stmt->execute([$sourceId]);
            $insert = $pdo->prepare(
                "INSERT INTO se_crew (event_id, user_id, role, added_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE revoked_at = NULL"
            );
            foreach ($stmt->fetchAll() as $c) {
                $insert->execute([$newId, (int) $c['user_id'], (string) $c['role'], $actorId]);
            }
        }

        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    se_audit($pdo, $newId, 'event_create', [
        'cloned_from' => $sourceId, 'options' => array_keys(array_filter($opts, 'se_bool')),
    ], 'event', $newId, $actorId);

    return se_event_find($pdo, $newId) ?? [];
}
