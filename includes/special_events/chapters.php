<?php
// /includes/special_events/chapters.php
//
// Portal chapters — "The night, chapter by chapter" (guide §13.3 S3).
//
// The four chapters used to be hard-coded in portal.php and switched on and
// off by the feature flags. They are now rows in se_chapters: a producer can
// add one, retire one, rewrite the copy, drop a photograph behind the card
// and move it up or down, all from Studio → Chapters.
//
// Three things this file is careful about:
//
//   * It degrades. The tree reaches production BEFORE migrations run
//     (AGENTS.md), so every read checks se_table_exists() first and falls
//     back to SE_CHAPTER_DEFAULTS. A portal never 500s because a table is
//     one deploy behind.
//   * Icons come from a CATALOGUE, not from free input. Each entry is an
//     inline SVG with the hooks @se/portal/main.js animates, so a chapter
//     invented tonight moves like the ones that shipped.
//   * The background image never competes with the icon. The markup always
//     carries the scrim and the icon always sits above it (§13.3 S3) — the
//     overlay is not an option a producer can switch off and regret on a
//     projector.

/** True once the chapters table exists (§9.4). */
function se_chapters_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_chapters');
}

/** The icon catalogue, shaped for the Studio picker. */
function se_chapter_icon_options(): array
{
    $out = [];
    foreach (SE_CHAPTER_ICONS as $key => $meta) {
        $out[] = [
            'key'   => $key,
            'label' => $meta['label'],
            'hint'  => $meta['hint'],
            'svg'   => $key === 'orbit' ? se_chapter_orbit_preview() : se_chapter_icon_svg($key),
        ];
    }

    return $out;
}

/** A chapter key that is URL-safe, stable and unique inside one event. */
function se_chapter_key_make(PDO $pdo, int $eventId, string $title, ?int $ignoreId = null): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '');
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'chapter';
    }
    $base = substr($base, 0, 30);

    if (!se_chapters_ready($pdo)) {
        return $base;
    }

    $candidate = $base;
    for ($n = 2; $n < 50; $n++) {
        $stmt = $pdo->prepare(
            "SELECT id FROM se_chapters WHERE event_id = ? AND chapter_key = ?" . ($ignoreId ? " AND id <> ?" : "")
        );
        $stmt->execute($ignoreId ? [$eventId, $candidate, $ignoreId] : [$eventId, $candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
        $candidate = substr($base, 0, 27) . '-' . $n;
    }

    return substr($base, 0, 24) . '-' . strtolower(se_random_code(4));
}

/**
 * Put the four default chapters on an event that has none.
 *
 * Called lazily on the first read, so events created before the migration,
 * cloned events and events created afterwards all end up with the same
 * starting set without a hook in six places.
 */
function se_chapters_seed(PDO $pdo, int $eventId, ?int $actorId = null): void
{
    if (!se_chapters_ready($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_chapters WHERE event_id = ?");
    $stmt->execute([$eventId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO se_chapters
            (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
    );

    $order = 10;
    foreach (SE_CHAPTER_DEFAULTS as $row) {
        $insert->execute([
            $eventId,
            $row['chapter_key'],
            $row['title'],
            $row['blurb'],
            $row['icon'],
            $row['feature'],
            $order,
            $actorId,
        ]);
        $order += 10;
    }
}

/**
 * Every chapter of an event, in order.
 *
 * @param bool $activeOnly Public callers want only the chapters that are on.
 * @return list<array<string,mixed>>
 */
function se_chapters_list(PDO $pdo, int $eventId, bool $activeOnly = false): array
{
    if (!se_chapters_ready($pdo)) {
        return se_chapters_fallback();
    }

    try {
        se_chapters_seed($pdo, $eventId);

        $sql = "SELECT c.*, a.path AS bg_path, a.alt_text AS bg_alt, a.width AS bg_width,
                       a.height AS bg_height, a.variants_json AS bg_variants, a.kind AS bg_kind
                  FROM se_chapters c
             LEFT JOIN se_assets a ON a.id = c.bg_asset_id AND a.deleted_at IS NULL
                 WHERE c.event_id = ?";
        if ($activeOnly) {
            $sql .= " AND c.is_active = 1";
        }
        $sql .= " ORDER BY c.sort_order ASC, c.id ASC LIMIT " . (int) SE_CHAPTERS_MAX;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$eventId]);

        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('SE chapters/list: ' . $e->getMessage());

        return se_chapters_fallback();
    }
}

/**
 * The defaults as list rows, for a portal whose database is a deploy behind.
 *
 * @return list<array<string,mixed>>
 */
function se_chapters_fallback(): array
{
    $rows  = [];
    $order = 10;
    foreach (SE_CHAPTER_DEFAULTS as $row) {
        $rows[] = $row + [
            'id'          => 0,
            'bg_asset_id' => null,
            'sort_order'  => $order,
            'is_active'   => 1,
        ];
        $order += 10;
    }

    return $rows;
}

/** One chapter of one event, or null. */
function se_chapter_find(PDO $pdo, int $eventId, int $chapterId): ?array
{
    if (!se_chapters_ready($pdo) || $chapterId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_chapters WHERE id = ? AND event_id = ?");
    $stmt->execute([$chapterId, $eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Validate the editable fields of a chapter.
 *
 * @return array{0: array<string,mixed>, 1: array<string,string>} [clean, errors]
 */
function se_chapter_clean(array $input): array
{
    $errors = [];

    $title = se_line($input['title'] ?? '', 120);
    if ($title === '') {
        $errors['title'] = 'Give the chapter a title — "The Mic", "The Food".';
    }

    $blurb = se_line($input['blurb'] ?? '', 400);

    $icon = se_enum($input['icon'] ?? '', array_keys(SE_CHAPTER_ICONS), '');
    if ($icon === '') {
        $errors['icon'] = 'Pick an icon so the card has something to animate.';
    }

    $feature = se_str($input['feature'] ?? '', 20);
    if ($feature !== '' && !in_array($feature, SE_CHAPTER_FEATURES, true)) {
        $feature = '';
    }

    return [[
        'title'       => $title,
        'blurb'       => $blurb,
        'icon'        => $icon,
        'feature'     => $feature === '' ? null : $feature,
        'is_active'   => se_bool($input['is_active'] ?? true),
        'bg_asset_id' => se_int_or_null($input['bg_asset_id'] ?? null, 1),
    ], $errors];
}

/**
 * The background asset id, once it is proved to be this event's own image.
 *
 * An id that belongs to another event, has been deleted, or is a PDF rather
 * than a picture is simply dropped — the card keeps its plain surface.
 */
function se_chapter_bg_asset_id(PDO $pdo, int $eventId, ?int $assetId): ?int
{
    if ($assetId === null || $assetId <= 0 || !se_table_exists($pdo, 'se_assets')) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM se_assets WHERE id = ? AND event_id = ? AND deleted_at IS NULL AND kind = 'image'"
    );
    $stmt->execute([$assetId, $eventId]);
    $found = $stmt->fetchColumn();

    return $found === false ? null : (int) $found;
}

/**
 * Create or update one chapter.
 *
 * @return list<array<string,mixed>> the whole list afterwards, in order
 */
function se_chapter_save(PDO $pdo, array $event, array $input, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    if (!se_chapters_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Chapters are not available until the database is migrated.');
    }
    if ((string) $event['status'] === 'archived') {
        throw new SeRuleException('EVENT_ARCHIVED', 'This event is archived and can no longer be edited.');
    }

    [$clean, $errors] = se_chapter_clean($input);
    if ($errors) {
        throw new SeValidationException($errors);
    }

    $clean['bg_asset_id'] = se_chapter_bg_asset_id($pdo, $eventId, $clean['bg_asset_id']);

    $id       = se_int($input['chapter_id'] ?? $input['id_chapter'] ?? 0, 0);
    $existing = $id > 0 ? se_chapter_find($pdo, $eventId, $id) : null;

    if ($id > 0 && !$existing) {
        throw new SeNotFoundException('That chapter is no longer in this event.');
    }

    if ($existing) {
        $pdo->prepare(
            "UPDATE se_chapters
                SET title = ?, blurb = ?, icon = ?, feature = ?, bg_asset_id = ?, is_active = ?
              WHERE id = ? AND event_id = ?"
        )->execute([
            $clean['title'], $clean['blurb'], $clean['icon'], $clean['feature'],
            $clean['bg_asset_id'], $clean['is_active'] ? 1 : 0, $id, $eventId,
        ]);

        se_audit($pdo, $eventId, 'chapter_update', [
            'chapter_id' => $id,
            'title'      => $clean['title'],
        ], 'chapter', $id, $actorId);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM se_chapters WHERE event_id = ?");
        $stmt->execute([$eventId]);
        if ((int) $stmt->fetchColumn() >= SE_CHAPTERS_MAX) {
            throw new SeRuleException(
                'VALIDATION',
                'That is ' . SE_CHAPTERS_MAX . ' chapters already — the section stops being a story past that.'
            );
        }

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM se_chapters WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $next = (int) $stmt->fetchColumn() + 10;

        $key = se_chapter_key_make($pdo, $eventId, $clean['title']);

        $pdo->prepare(
            "INSERT INTO se_chapters
                (event_id, chapter_key, title, blurb, icon, feature, bg_asset_id, sort_order, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $eventId, $key, $clean['title'], $clean['blurb'], $clean['icon'], $clean['feature'],
            $clean['bg_asset_id'], $next, $clean['is_active'] ? 1 : 0, $actorId,
        ]);

        se_audit($pdo, $eventId, 'chapter_add', [
            'chapter_key' => $key,
            'title'       => $clean['title'],
        ], 'chapter', (int) $pdo->lastInsertId(), $actorId);
    }

    return se_chapters_list($pdo, $eventId);
}

/** Remove a chapter. The asset behind it stays in the kit. */
function se_chapter_delete(PDO $pdo, array $event, int $chapterId, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $chapter = se_chapter_find($pdo, $eventId, $chapterId);
    if (!$chapter) {
        throw new SeNotFoundException('That chapter is no longer in this event.');
    }

    $pdo->prepare("DELETE FROM se_chapters WHERE id = ? AND event_id = ?")->execute([$chapterId, $eventId]);

    se_audit($pdo, $eventId, 'chapter_delete', [
        'chapter_key' => $chapter['chapter_key'],
        'title'       => $chapter['title'],
    ], 'chapter', $chapterId, $actorId);

    return se_chapters_list($pdo, $eventId);
}

/**
 * Move a chapter one place up or down.
 *
 * Swapping the two sort_order values keeps every other row untouched, which
 * is what makes the up/down buttons safe to press quickly: two producers
 * reordering at once cannot interleave into a renumbered mess.
 */
function se_chapter_move(PDO $pdo, array $event, int $chapterId, string $direction, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $chapter = se_chapter_find($pdo, $eventId, $chapterId);
    if (!$chapter) {
        throw new SeNotFoundException('That chapter is no longer in this event.');
    }

    $up = $direction !== 'down';

    return se_lock_event($pdo, $eventId, function () use ($pdo, $eventId, $chapter, $up, $chapterId, $actorId) {
        $stmt = $pdo->prepare(
            $up
                ? "SELECT id, sort_order FROM se_chapters
                     WHERE event_id = ? AND (sort_order < ? OR (sort_order = ? AND id < ?))
                  ORDER BY sort_order DESC, id DESC LIMIT 1"
                : "SELECT id, sort_order FROM se_chapters
                     WHERE event_id = ? AND (sort_order > ? OR (sort_order = ? AND id > ?))
                  ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $stmt->execute([$eventId, (int) $chapter['sort_order'], (int) $chapter['sort_order'], $chapterId]);
        $neighbour = $stmt->fetch();

        if ($neighbour) {
            $update = $pdo->prepare("UPDATE se_chapters SET sort_order = ? WHERE id = ? AND event_id = ?");
            // Equal sort_order values (two rows seeded at 10) would swap into
            // themselves, so the pair is always renumbered apart.
            $mine  = (int) $chapter['sort_order'];
            $theirs = (int) $neighbour['sort_order'];
            if ($mine === $theirs) {
                $mine   = $up ? $theirs - 1 : $theirs + 1;
                $update->execute([$mine, $chapterId, $eventId]);
            } else {
                $update->execute([$theirs, $chapterId, $eventId]);
                $update->execute([$mine, (int) $neighbour['id'], $eventId]);
            }

            se_audit($pdo, $eventId, 'chapter_reorder', [
                'chapter_id' => $chapterId,
                'direction'  => $up ? 'up' : 'down',
            ], 'chapter', $chapterId, $actorId);
        }

        return se_chapters_list($pdo, $eventId);
    });
}

/**
 * Put the four defaults back.
 *
 * Only ever used deliberately from the Studio: it deletes what is there and
 * re-seeds, which is the quickest way out of an experiment that went wrong
 * an hour before doors.
 */
function se_chapters_reset(PDO $pdo, array $event, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    if (!se_chapters_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Chapters are not available until the database is migrated.');
    }

    $pdo->prepare("DELETE FROM se_chapters WHERE event_id = ?")->execute([$eventId]);
    se_chapters_seed($pdo, $eventId, $actorId);

    se_audit($pdo, $eventId, 'chapters_reset', [], 'event', $eventId, $actorId);

    return se_chapters_list($pdo, $eventId);
}

/**
 * One chapter, shaped for the Studio.
 *
 * The icon SVG travels with the row so the tab can show the real artwork in
 * its picker and preview instead of a second, drifting copy in JavaScript.
 */
function se_chapter_payload(array $row): array
{
    $icon = (string) ($row['icon'] ?? 'spark');

    return [
        'id'          => (int) ($row['id'] ?? 0),
        'key'         => (string) ($row['chapter_key'] ?? ''),
        'title'       => (string) ($row['title'] ?? ''),
        'blurb'       => (string) ($row['blurb'] ?? ''),
        'icon'        => $icon,
        'icon_label'  => SE_CHAPTER_ICONS[$icon]['label'] ?? $icon,
        'feature'     => isset($row['feature']) && $row['feature'] !== '' ? (string) $row['feature'] : null,
        'is_active'   => (bool) ($row['is_active'] ?? true),
        'sort_order'  => (int) ($row['sort_order'] ?? 0),
        'bg_asset_id' => isset($row['bg_asset_id']) && $row['bg_asset_id'] !== null ? (int) $row['bg_asset_id'] : null,
        'bg_path'     => $row['bg_path'] ?? null,
        'bg_alt'      => $row['bg_alt'] ?? null,
        'words'       => se_chapter_word_count((string) ($row['blurb'] ?? '')),
    ];
}

/** Words in a blurb — §13.3 asks for 25 or fewer, and the Studio says so. */
function se_chapter_word_count(string $blurb): int
{
    $words = preg_split('/\s+/u', trim($blurb), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return count($words);
}

// --------------------------------------------------------------------------
// The icon catalogue
// --------------------------------------------------------------------------

/**
 * A still of the teams orbit, for the Studio.
 *
 * The real thing is CSS: orbs painted from the --team-N custom properties
 * the portal's shell writes, which the Studio page does not load. So the
 * picker gets a plain SVG that reads the same — four circles meeting in one
 * light — rather than an empty box.
 */
function se_chapter_orbit_preview(): string
{
    return '<svg viewBox="0 0 160 120" role="img" aria-hidden="true" fill="none" stroke="currentColor"'
        . ' stroke-width="3" data-se-icon="orbit">'
        . '<circle cx="80" cy="60" r="20" fill="currentColor" stroke="none" opacity="0.2"/>'
        . '<circle cx="80" cy="30" r="11" fill="currentColor" stroke="none" opacity="0.85"/>'
        . '<circle cx="110" cy="60" r="11" fill="currentColor" stroke="none" opacity="0.65"/>'
        . '<circle cx="80" cy="90" r="11" fill="currentColor" stroke="none" opacity="0.5"/>'
        . '<circle cx="50" cy="60" r="11" fill="currentColor" stroke="none" opacity="0.75"/>'
        . '<circle cx="80" cy="60" r="38" stroke-dasharray="3 9" opacity="0.35"/>'
        . '</svg>';
}

/**
 * The inline SVG for one icon (§13.3 S3).
 *
 * `currentColor` and the theme variables only — no request, no hard-coded
 * colour, and the animation hooks (`data-se-*`) are attributes rather than
 * classes so the stylesheet never has to know this list exists.
 *
 * `orbit` is the one icon with real data behind it: it paints one orb per
 * actual team, which is why $teams is threaded through.
 */
function se_chapter_icon_svg(string $icon, array $teams = []): string
{
    if ($icon === 'orbit') {
        return se_portal_team_orbit($teams);
    }

    $open = '<svg viewBox="0 0 160 120" role="img" aria-hidden="true" fill="none" stroke="currentColor"'
        . ' stroke-width="3" stroke-linecap="round" stroke-linejoin="round" data-se-icon="' . se_h($icon) . '">';

    $body = match ($icon) {
        // The Mic: capsule, cradle, stand, a dashed halo that ignites and an
        // equaliser that sings underneath it.
        'mic' => '<g data-se-mic-glow opacity="0.14">'
            . '<circle cx="80" cy="52" r="34" fill="currentColor" stroke="none" opacity="0.18"/></g>'
            . '<circle cx="80" cy="52" r="40" stroke-dasharray="4 10" opacity="0.5" data-se-mic-ring/>'
            . '<rect x="68" y="14" width="24" height="46" rx="12" data-se-mic-capsule/>'
            . '<path d="M56 52a24 24 0 0 0 48 0"/><path d="M80 76v16M66 92h28"/>'
            . '<g data-se-mic-eq>'
            . '<path d="M28 48v16" data-se-mic-bar/><path d="M40 42v28" data-se-mic-bar/>'
            . '<path d="M120 42v28" data-se-mic-bar/><path d="M132 48v16" data-se-mic-bar/>'
            . '</g>',

        // The Games: a fan of cards that deals itself out, a buzzer that
        // pings and a score line that ticks up behind them.
        'cards' => '<g data-se-card-fan>'
            . '<rect x="30" y="34" width="44" height="62" rx="8" opacity="0.45" data-se-card="-16"/>'
            . '<rect x="50" y="28" width="44" height="66" rx="8" opacity="0.7" data-se-card="-7"/>'
            . '<rect x="70" y="24" width="44" height="70" rx="8" data-se-card="4"/>'
            . '<path d="M92 46v26M79 59h26" opacity="0.9"/>'
            . '</g>'
            . '<g data-se-buzz>'
            . '<circle cx="128" cy="86" r="11" fill="currentColor" stroke="none" opacity="0.9"/>'
            . '<circle cx="128" cy="86" r="17" opacity="0.5" data-se-buzz-ring/>'
            . '</g>'
            . '<path d="M22 100h26l10-12 10 20 8-10" opacity="0.55" data-se-draw/>',

        'timeline' => '<path d="M12 96h136" opacity="0.4"/>'
            . '<path d="M20 96 46 60l24 20 26-44 24 36 18-14" data-se-draw/>'
            . '<circle cx="46" cy="60" r="4" fill="currentColor" stroke="none" data-se-dot/>'
            . '<circle cx="96" cy="36" r="4" fill="currentColor" stroke="none" data-se-dot/>'
            . '<circle cx="120" cy="72" r="4" fill="currentColor" stroke="none" data-se-dot/>',

        'clock' => '<circle cx="80" cy="60" r="40"/>'
            . '<circle cx="80" cy="60" r="48" stroke-dasharray="3 9" opacity="0.4"/>'
            . '<path d="M80 36v24l16 10" data-se-clock-hands/>',

        'music' => '<path d="M62 86V30l44-10v56" data-se-note/>'
            . '<ellipse cx="52" cy="88" rx="12" ry="9" data-se-note/>'
            . '<ellipse cx="96" cy="78" rx="12" ry="9" data-se-note/>'
            . '<path d="M124 34c6 4 6 14 0 18" opacity="0.5" data-se-float/>'
            . '<path d="M134 26c12 8 12 30 0 38" opacity="0.3" data-se-float/>',

        'trophy' => '<path d="M58 22h44v24a22 22 0 0 1-44 0z"/>'
            . '<path d="M58 28H44a14 14 0 0 0 14 14M102 28h14a14 14 0 0 1-14 14"/>'
            . '<path d="M80 68v16M62 96h36M68 84h24l6 12H62z"/>'
            . '<path d="M70 32 86 26l-4 14 10 4" opacity="0.6" data-se-shine/>',

        'gift' => '<rect x="36" y="48" width="88" height="54" rx="8"/>'
            . '<path d="M80 48v54M32 66h96" opacity="0.7"/>'
            . '<g data-se-lid><rect x="30" y="34" width="100" height="18" rx="6"/></g>'
            . '<path d="M80 34c-14-18-32-4-18 6M80 34c14-18 32-4 18 6" opacity="0.8"/>',

        'camera' => '<rect x="26" y="34" width="108" height="68" rx="12"/>'
            . '<path d="M60 34l8-12h24l8 12"/>'
            . '<circle cx="80" cy="68" r="20" data-se-shutter/>'
            . '<circle cx="80" cy="68" r="9" fill="currentColor" stroke="none" opacity="0.5"/>'
            . '<circle cx="116" cy="48" r="3" fill="currentColor" stroke="none" data-se-flash/>',

        'people' => '<g data-se-person="-26"><circle cx="46" cy="50" r="12"/><path d="M26 92a20 20 0 0 1 40 0"/></g>'
            . '<g data-se-person="26"><circle cx="114" cy="50" r="12"/><path d="M94 92a20 20 0 0 1 40 0"/></g>'
            . '<g data-se-person="0"><circle cx="80" cy="42" r="15"/><path d="M56 94a24 24 0 0 1 48 0"/></g>',

        'flame' => '<path d="M80 18c18 20 30 30 30 48a30 30 0 0 1-60 0c0-12 8-20 14-28 4 8 10 10 10 4 0-10 2-18 6-24z" data-se-flame/>'
            . '<path d="M80 60c7 8 11 13 11 21a11 11 0 0 1-22 0c0-8 4-13 11-21z" opacity="0.6" data-se-flame-core/>',

        'cross' => '<g data-se-glow><circle cx="80" cy="58" r="40" fill="currentColor" stroke="none" opacity="0.12"/></g>'
            . '<path d="M80 16v88M52 46h56"/>'
            . '<path d="M40 104h80" opacity="0.4"/>',

        'plate' => '<circle cx="80" cy="66" r="32"/><circle cx="80" cy="66" r="22" opacity="0.45"/>'
            . '<path d="M68 30c-6-8 0-12 4-16M80 28c-6-8 0-12 4-16M92 30c-6-8 0-12 4-16" opacity="0.6" data-se-steam/>',

        default => '<path d="M80 24l7 19 19 7-19 7-7 19-7-19-19-7 19-7z" data-se-twinkle/>'
            . '<path d="M36 72l4 10 10 4-10 4-4 10-4-10-10-4 10-4z" opacity="0.7" data-se-twinkle/>'
            . '<path d="M122 60l3 8 8 3-8 3-3 8-3-8-8-3 8-3z" opacity="0.5" data-se-twinkle/>',
    };

    return $open . $body . '</svg>';
}
