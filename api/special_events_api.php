<?php
// /api/special_events_api.php
//
// The Studio API (guide §12.5). Auth: ERP session + X-SE-CSRF + module access
// (§6.2) + the capability for the event being acted on. Writes to se_events
// carry expected_row_version (§9.3).
//
// Implemented so far: Events, Brand, Registration, Days, Assets, Crew and
// Ops (PR1), Attendees (PR2), Teams, Verses and Live (PR3), and Programme,
// Karaoke and Messages (PR4). Actions belonging to later PRs are not routed
// at all, so an unknown action is simply BAD_REQUEST — never a half-working
// endpoint.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';
header('Content-Type: application/json');

// --------------------------------------------------------------------------
// Gate
// --------------------------------------------------------------------------

// Uploads are multipart; everything else is a JSON POST. Both must carry
// X-SE-Request, a same-origin Origin and the CSRF header (§19.3).
se_require_request_integrity();

if (!isset($_SESSION['user_id'])) {
    se_api_error('Please sign in to continue.', 'UNAUTHENTICATED');
}

se_require_csrf();

$accessLevel = se_require_module_access($pdo);
$userId      = (int) $_SESSION['user_id'];

$body   = se_request_body();
$action = se_str($body['action'] ?? '', 60);

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

/**
 * Resolve the event an action targets and check one capability on it.
 * Accepts `id` (internal) or `event` (public id), as §12.5 and §12.1 do.
 */
function se_studio_event(PDO $pdo, array $body, string $capability): array
{
    $event = null;

    if (!empty($body['id'])) {
        $event = se_event_find($pdo, (int) $body['id']);
    } elseif (!empty($body['event'])) {
        $event = se_event_find_by_public_id($pdo, se_str($body['event'], 12));
    }

    if (!$event) {
        se_api_error('That event no longer exists.', 'EVENT_NOT_FOUND');
    }

    se_require_capability($pdo, (int) $event['id'], $capability);

    return $event;
}

/**
 * Resolve an asset by its own id (§12.5 `asset_update {id, …}`) and check a
 * capability on the event that owns it.
 *
 * Asset actions name the ASSET in `id`, unlike every other Studio action, so
 * the event is derived here rather than taken from the request — which also
 * means a caller cannot pass someone else's asset with their own event id.
 *
 * @return array{0: array, 1: array} [asset, event]
 */
function se_studio_asset(PDO $pdo, array $body, string $capability): array
{
    $assetId = se_int($body['id'] ?? 0, 1);
    $asset   = $assetId > 0 ? se_asset_find($pdo, $assetId) : null;

    if (!$asset || $asset['deleted_at'] !== null || empty($asset['event_id'])) {
        se_api_error('That file is no longer in this event.', 'EVENT_NOT_FOUND');
    }

    $event = se_event_find($pdo, (int) $asset['event_id']);
    if (!$event) {
        se_api_error('That event no longer exists.', 'EVENT_NOT_FOUND');
    }

    se_require_capability($pdo, (int) $event['id'], $capability);

    return [$asset, $event];
}

/** The expected_row_version an update must carry. */
function se_studio_version(array $body): int
{
    if (!isset($body['expected_row_version']) || !is_numeric($body['expected_row_version'])) {
        throw new SeValidationException(
            ['expected_row_version' => 'Reload the page and try again.'],
            'This save is missing its version stamp.'
        );
    }

    return (int) $body['expected_row_version'];
}

/** Pagination from the request (§12.5: per_page <= 100). */
function se_studio_paging(array $body): array
{
    $page    = max(1, se_int($body['page'] ?? 1, 1, 100000, 1));
    $perPage = se_int($body['per_page'] ?? 25, 1, 100, 25);

    return [$page, $perPage, ($page - 1) * $perPage];
}

/**
 * Shape one event for the Studio. Includes the derived theme, the counts and
 * the viewer's capabilities, so the UI never has to guess what to show.
 */
function se_studio_event_payload(PDO $pdo, array $event, int $userId, string $accessLevel, bool $full = false): array
{
    $eventId  = (int) $event['id'];
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);
    $phase    = se_event_phase($event, $days);

    $payload = [
        'id'            => $eventId,
        'public_id'     => $event['public_id'],
        'slug'          => $event['slug'],
        'title'         => $event['title'],
        'edition_label' => $event['edition_label'],
        'tagline'       => $event['tagline'],
        'status'        => $event['status'],
        'visibility'    => $event['visibility'],
        'phase'         => $phase['phase'],
        'starts_at'     => se_iso($event['starts_at']),
        'ends_at'       => se_iso($event['ends_at']),
        'venue_name'    => $event['venue_name'],
        'organizer_label' => $event['organizer_label'],
        'series_id'     => $event['series_id'] !== null ? (int) $event['series_id'] : null,
        'row_version'   => (int) $event['row_version'],
        'online_capacity' => $event['online_capacity'] !== null ? (int) $event['online_capacity'] : null,
        'counts'        => se_event_counts($pdo, $eventId),
        // The live registration state, so Overview can colour the capacity
        // KPI and Attendees can explain why nobody new is coming in (§10.4.2).
        'reg_state'     => se_registration_state($event, $phase, se_capacity_counts($pdo, $eventId)),
        'seats_left'    => se_seats_left($event, se_capacity_counts($pdo, $eventId)),
        'brand'         => [
            'primary'   => $event['brand_primary'],
            'secondary' => $event['brand_secondary'],
            'accent'    => $event['brand_accent'],
            'preset'    => $event['theme_preset'],
            'font_display' => $event['font_display'],
            'font_body'    => $event['font_body'],
        ],
        'theme'         => se_event_theme($event),
        'portal_url'    => se_event_url((string) $event['slug']),
        'capabilities'  => se_event_capabilities($pdo, $eventId, $userId, $accessLevel),
    ];

    if (!$full) {
        return $payload;
    }

    $payload += [
        'description_md' => $event['description_md'],
        'venue_address'  => $event['venue_address'],
        'venue_map_url'  => $event['venue_map_url'],
        'venue_notes'    => $event['venue_notes'],
        'cancel_reason'  => $event['cancel_reason'],
        'cloned_from_event_id' => $event['cloned_from_event_id'] !== null ? (int) $event['cloned_from_event_id'] : null,
        'preview_url'    => se_event_url((string) $event['slug']) . '?preview=' . rawurlencode((string) $event['preview_key']),
        'days'           => array_map(static fn(array $d): array => [
            'id'                => (int) $d['id'],
            'day_date'          => $d['day_date'],
            'label'             => $d['label'],
            'doors_open_at'     => $d['doors_open_at'],
            'starts_at'         => $d['starts_at'],
            'ends_at'           => $d['ends_at'],
            'checkin_closes_at' => $d['checkin_closes_at'],
        ], $days),
        'registration'   => [
            'reg_opens_at'             => $event['reg_opens_at'],
            'reg_closes_at'            => $event['reg_closes_at'],
            'reg_override'             => $event['reg_override'],
            'reg_override_note'        => $event['reg_override_note'],
            'auto_close_at_capacity'   => (bool) $event['auto_close_at_capacity'],
            'waitlist_enabled'         => (bool) $event['waitlist_enabled'],
            'waitlist_capacity'        => $event['waitlist_capacity'] !== null ? (int) $event['waitlist_capacity'] : null,
            'waitlist_promotion'       => $event['waitlist_promotion'],
            'waitlist_notify_sms'      => (bool) $event['waitlist_notify_sms'],
            'self_cancel_enabled'      => (bool) $event['self_cancel_enabled'],
            'walkin_enabled'           => (bool) $event['walkin_enabled'],
            'walkin_capacity'          => $event['walkin_capacity'] !== null ? (int) $event['walkin_capacity'] : null,
            'walkin_hard_cap'          => (bool) $event['walkin_hard_cap'],
            'seats_left_mode'          => $event['seats_left_mode'],
            'seats_left_threshold_pct' => (int) $event['seats_left_threshold_pct'],
        ],
        'settings'       => $settings,
        'checklist'      => se_publish_checklist($pdo, $event),
        'form_fields'    => se_form_fields_list($pdo, $eventId),
        'assets'         => array_map('se_studio_asset_payload', se_asset_list($pdo, $eventId)),
        'asset_refs'     => [
            'logo'       => $event['logo_asset_id'] !== null ? (int) $event['logo_asset_id'] : null,
            'hero'       => $event['hero_asset_id'] !== null ? (int) $event['hero_asset_id'] : null,
            'hero_video' => $event['hero_video_asset_id'] !== null ? (int) $event['hero_video_asset_id'] : null,
            'og'         => $event['og_asset_id'] !== null ? (int) $event['og_asset_id'] : null,
        ],
    ];

    return $payload;
}

function se_studio_asset_payload(array $asset): array
{
    return [
        'id'       => (int) $asset['id'],
        'kind'     => $asset['kind'],
        'role'     => $asset['role'],
        'title'    => $asset['title'],
        'alt_text' => $asset['alt_text'],
        'path'     => $asset['path'],
        'mime'     => $asset['mime'],
        'bytes'    => (int) $asset['bytes'],
        'width'    => $asset['width'] !== null ? (int) $asset['width'] : null,
        'height'   => $asset['height'] !== null ? (int) $asset['height'] : null,
        'srcset'   => se_asset_srcset($asset),
        'meta'     => se_json_decode($asset['meta_json'] ?? null),
        'created_at' => se_iso($asset['created_at']),
    ];
}

/** Custom registration questions of an event. */
function se_form_fields_list(PDO $pdo, int $eventId): array
{
    if (!se_table_exists($pdo, 'se_form_fields')) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT * FROM se_form_fields WHERE event_id = ? ORDER BY sort_order, id");
    $stmt->execute([$eventId]);

    return array_map(static fn(array $f): array => [
        'id'          => (int) $f['id'],
        'field_key'   => $f['field_key'],
        'label'       => $f['label'],
        'type'        => $f['type'],
        'options'     => se_json_decode($f['options_json'] ?? null),
        'is_required' => (bool) $f['is_required'],
        'placeholder' => $f['placeholder'],
        'help_text'   => $f['help_text'],
        'audience'    => $f['audience'],
        'sort_order'  => (int) $f['sort_order'],
        'is_active'   => (bool) $f['is_active'],
    ], $stmt->fetchAll());
}

// --------------------------------------------------------------------------
// Dispatch
// --------------------------------------------------------------------------

try {
    switch ($action) {

    // ====================================================================
    // Events
    // ====================================================================

    case 'list_events': {
        $filter   = se_str($body['filter'] ?? 'all', 20);
        $seriesId = se_int_or_null($body['series_id'] ?? null, 1);
        [$page, $perPage, $offset] = se_studio_paging($body);

        $where  = [];
        $params = [];

        if (in_array($filter, ['draft', 'published', 'cancelled', 'archived'], true)) {
            $where[]  = 'e.status = ?';
            $params[] = $filter;
        } elseif ($filter === 'live') {
            $where[] = "e.status = 'published' AND e.starts_at <= NOW() AND e.ends_at >= NOW()";
        } elseif ($filter === 'upcoming') {
            $where[] = "e.status = 'published' AND e.starts_at > NOW()";
        } elseif ($filter === 'active') {
            $where[] = "e.status <> 'archived'";
        }

        if ($seriesId !== null) {
            $where[]  = 'e.series_id = ?';
            $params[] = $seriesId;
        }

        // A studio_member sees every event's card (aggregates only, §6.2); a
        // crew_only user sees just the events they are crew on.
        if ($accessLevel === 'crew_only') {
            $where[] = 'EXISTS (SELECT 1 FROM se_crew c WHERE c.event_id = e.id AND c.user_id = ? AND c.revoked_at IS NULL)';
            $params[] = $userId;
        }

        $sql = 'FROM se_events e' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $countStmt = $pdo->prepare("SELECT COUNT(*) {$sql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare("SELECT e.* {$sql} ORDER BY e.starts_at DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $listStmt->execute($params);

        $items = [];
        foreach ($listStmt->fetchAll() as $event) {
            $items[] = se_studio_event_payload($pdo, $event, $userId, $accessLevel);
        }

        $series = $pdo->query("SELECT id, name FROM se_series WHERE archived_at IS NULL ORDER BY name")->fetchAll();

        se_api_success('Events loaded.', [
            'items'  => $items,
            'page'   => $page,
            'pages'  => (int) max(1, ceil($total / $perPage)),
            'total'  => $total,
            'series' => array_map(static fn($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $series),
            'access' => $accessLevel,
            'can_create' => in_array($accessLevel, ['manager', 'studio_member'], true),
        ]);
    }

    case 'get_event': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        se_api_success('Event loaded.', [
            'event' => se_studio_event_payload($pdo, $event, $userId, $accessLevel, true),
        ]);
    }

    case 'create_event': {
        if (!in_array($accessLevel, ['manager', 'studio_member'], true)) {
            se_api_error('Only the Envision team can create events.', 'FORBIDDEN');
        }
        $event = se_event_create($pdo, [
            'title'           => $body['title'] ?? '',
            'slug'            => $body['slug'] ?? '',
            'tagline'         => $body['tagline'] ?? '',
            'edition_label'   => $body['edition_label'] ?? '',
            'venue_name'      => $body['venue_name'] ?? '',
            'organizer_label' => $body['organizer_label'] ?? 'Envision',
            'days'            => is_array($body['days'] ?? null) ? $body['days'] : [],
            'series_id'       => $body['series_id'] ?? null,
            'series_name'     => $body['series_name'] ?? '',
            'brand_primary'   => $body['brand_primary'] ?? '',
            'brand_secondary' => $body['brand_secondary'] ?? '',
            'online_capacity' => $body['online_capacity'] ?? null,
            'visibility'      => $body['visibility'] ?? 'unlisted',
        ], $userId);

        se_api_success('Event created.', [
            'event' => se_studio_event_payload($pdo, $event, $userId, $accessLevel, true),
        ]);
    }

    case 'update_event': {
        $event   = se_studio_event($pdo, $body, 'event.edit');
        $section = se_str($body['section'] ?? '', 20);
        $fields  = is_array($body['fields'] ?? null) ? $body['fields'] : [];

        $updated = se_event_update($pdo, (int) $event['id'], $section, $fields, se_studio_version($body), $userId);

        se_api_success('Saved.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'check_slug': {
        $input = se_str($body['slug'] ?? '', 60);
        $check = se_slug_validate($input);

        $eventId = se_int_or_null($body['event_id'] ?? null, 1);
        $available = $check['ok'] && se_slug_available($pdo, $check['slug'], $eventId);

        $heldBy = null;
        if ($check['ok'] && !$available) {
            $stmt = $pdo->prepare(
                "SELECT e.id, e.title, e.status FROM se_slugs s
                 JOIN se_events e ON e.id = s.event_id WHERE s.slug = ?"
            );
            $stmt->execute([$check['slug']]);
            $row = $stmt->fetch();
            if ($row) {
                $heldBy = [
                    'id'     => (int) $row['id'],
                    'title'  => $row['title'],
                    'status' => $row['status'],
                    // An archived holder can be reclaimed (§10.2 item 4).
                    'reclaimable' => (string) $row['status'] === 'archived',
                ];
            }
        }

        se_api_success('Checked.', [
            'slug'       => $check['slug'],
            'valid'      => $check['ok'],
            'available'  => $available,
            'reason'     => $check['ok'] ? ($available ? null : 'That link is already taken.') : $check['reason'],
            'suggestion' => $check['suggestion'],
            'held_by'    => $heldBy,
            'url'        => $check['ok'] ? se_event_url($check['slug']) : null,
        ]);
    }

    case 'reclaim_slug': {
        $event  = se_studio_event($pdo, $body, 'event.edit');
        $result = se_slug_reclaim($pdo, (int) $event['id'], se_str($body['slug'] ?? '', 60), $userId);

        se_api_success('Link reclaimed.', [
            'result' => $result,
            'event'  => se_studio_event_payload($pdo, se_event_find($pdo, (int) $event['id']) ?? [], $userId, $accessLevel, true),
        ]);
    }

    case 'publish': {
        $event   = se_studio_event($pdo, $body, 'event.publish');
        $updated = se_event_publish($pdo, (int) $event['id'], $userId);
        se_api_success('This event is live.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'unpublish': {
        $event   = se_studio_event($pdo, $body, 'event.publish');
        $updated = se_event_unpublish($pdo, (int) $event['id'], $userId);
        se_api_success('Back to draft.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'cancel': {
        $event   = se_studio_event($pdo, $body, 'event.publish');
        $updated = se_event_cancel($pdo, (int) $event['id'], se_str($body['reason'] ?? '', 255), $userId);
        se_api_success('Event cancelled.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'archive': {
        // Manager only (§6.2).
        se_require_manager($pdo);
        $event   = se_studio_event($pdo, $body, 'insights.view');
        $updated = se_event_archive($pdo, (int) $event['id'], $userId, se_bool($body['force'] ?? false));
        se_api_success('Event archived.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'delete_draft': {
        se_require_manager($pdo);
        $event = se_studio_event($pdo, $body, 'insights.view');
        se_event_delete_draft($pdo, (int) $event['id'], $userId);
        se_api_success('Draft deleted.', ['deleted' => true]);
    }

    case 'clone_event': {
        if (!in_array($accessLevel, ['manager', 'studio_member'], true)) {
            se_api_error('Only the Envision team can create events.', 'FORBIDDEN');
        }
        $source = se_event_find($pdo, se_int($body['source_id'] ?? 0, 1));
        if (!$source) {
            se_api_error('The event you are cloning no longer exists.', 'EVENT_NOT_FOUND');
        }
        // Reading an event in order to copy it needs sight of it.
        se_require_capability($pdo, (int) $source['id'], 'insights.view');

        $clone = se_clone_event($pdo, (int) $source['id'],
            is_array($body['options'] ?? null) ? $body['options'] : [],
            [
                'title'         => $body['title'] ?? '',
                'slug'          => $body['slug'] ?? '',
                'first_start'   => $body['first_start'] ?? null,
                'series_id'     => $body['series_id'] ?? null,
                'edition_label' => $body['edition_label'] ?? null,
            ], $userId);

        se_api_success('Event cloned.', [
            'event' => se_studio_event_payload($pdo, $clone, $userId, $accessLevel, true),
        ]);
    }

    case 'days_save': {
        $event    = se_studio_event($pdo, $body, 'event.edit');
        $settings = se_event_settings($event);
        $days     = se_event_days_save(
            $pdo, (int) $event['id'],
            is_array($body['days'] ?? null) ? $body['days'] : [],
            $settings, $userId
        );

        se_api_success('Dates saved.', [
            'event' => se_studio_event_payload($pdo, se_event_find($pdo, (int) $event['id']) ?? [], $userId, $accessLevel, true),
            'days'  => $days,
        ]);
    }

    // ====================================================================
    // Brand
    // ====================================================================

    case 'palette_derive': {
        // Pure maths, instant, no event needed: the Brand tab calls this on
        // every keystroke to preview tokens before anything is saved.
        $primary   = se_normalize_hex($body['primary'] ?? '');
        $secondary = se_normalize_hex($body['secondary'] ?? '');
        if ($primary === null || $secondary === null) {
            throw new SeValidationException([
                'primary'   => $primary === null ? 'Enter a hex colour like #1D356A.' : '',
                'secondary' => $secondary === null ? 'Enter a hex colour like #D11920.' : '',
            ]);
        }
        $accent = isset($body['accent']) && $body['accent'] !== '' ? se_normalize_hex($body['accent']) : null;
        $preset = se_enum($body['preset'] ?? 'marquee', SE_THEME_PRESETS, 'marquee');

        se_api_success('Palette derived.', ['theme' => se_theme_derive($primary, $secondary, $accent, $preset)]);
    }

    case 'palette_suggest': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        // Per-user and per-event AI limits (§15.1).
        se_rate_limit_or_fail($pdo, 'ai_user', 'u' . $userId, 30, 3600);

        $result = se_ai_palette_suggest(
            $pdo, $event,
            se_str($body['primary'] ?? $event['brand_primary'], 7),
            se_str($body['secondary'] ?? $event['brand_secondary'], 7),
            is_array($body['mood'] ?? null) ? $body['mood'] : [],
            $userId
        );

        se_api_success('Here are some palettes.', $result);
    }

    case 'apply_palette': {
        $event   = se_studio_event($pdo, $body, 'event.edit');
        $palette = is_array($body['palette'] ?? null) ? $body['palette'] : [];

        // Only the accent is taken from a suggestion: P and S stay the
        // Producer's, and every contrast-critical token is recomputed by the
        // theme engine, never by the AI (§15.2).
        $fields = [];
        if (!empty($palette['accent'])) {
            $fields['brand_accent'] = $palette['accent'];
        }
        if (!$fields) {
            throw new SeValidationException(['palette' => 'That palette has no accent colour to apply.']);
        }

        $updated = se_event_update($pdo, (int) $event['id'], 'brand', $fields, se_studio_version($body), $userId);

        se_api_success('Palette applied.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'fonts_list': {
        se_api_success('Fonts loaded.', [
            'pairs' => array_map(
                static fn($display, $bodyFont) => ['display' => $display, 'body' => $bodyFont],
                array_keys(SE_FONT_PAIRS), array_values(SE_FONT_PAIRS)
            ),
        ]);
    }

    // ====================================================================
    // Registration
    // ====================================================================

    case 'capacity_save': {
        $event  = se_studio_event($pdo, $body, 'event.edit');
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : $body;

        $allowed = [];
        foreach (SE_EVENT_SECTIONS['registration'] as $column) {
            if (array_key_exists($column, $fields)) {
                $allowed[$column] = $fields[$column];
            }
        }

        $version = se_studio_version($body);
        $updated = $allowed
            ? se_event_update($pdo, (int) $event['id'], 'registration', $allowed, $version, $userId)
            : $event;

        // Record that a capacity decision was made on purpose, so the H.1
        // checklist can tell "unlimited" from "never opened this tab".
        $settings = se_event_settings($updated);
        if (empty($settings['registration']['capacity_reviewed'])) {
            $updated = se_event_update(
                $pdo, (int) $event['id'], 'settings',
                ['settings_json' => ['registration' => ['capacity_reviewed' => true]]],
                (int) $updated['row_version'], $userId
            );
        }

        se_audit($pdo, (int) $event['id'], 'capacity_change', ['fields' => array_keys($allowed)], 'event', (int) $event['id'], $userId);

        se_api_success('Registration settings saved.', [
            'event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'override_set': {
        $event   = se_studio_event($pdo, $body, 'event.capacity_override');
        $updated = se_event_override_set(
            $pdo, (int) $event['id'],
            se_str($body['mode'] ?? 'none', 20),
            se_str($body['note'] ?? '', 255),
            $userId
        );

        se_api_success(match ((string) $updated['reg_override']) {
            'force_open'   => 'Registration is forced open.',
            'force_closed' => 'Registration is paused.',
            default        => 'Override cleared.',
        }, ['event' => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true)]);
    }

    case 'form_fields_save': {
        $event   = se_studio_event($pdo, $body, 'event.edit');
        $eventId = (int) $event['id'];

        if (!se_table_exists($pdo, 'se_form_fields')) {
            se_api_error('Custom questions are not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        $incoming = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        if (count($incoming) > SE_MAX_FORM_FIELDS) {
            throw new SeValidationException(['fields' => 'At most ' . SE_MAX_FORM_FIELDS . ' custom questions.']);
        }

        $rows   = [];
        $errors = [];
        $keys   = [];

        foreach (array_values($incoming) as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            $label = se_line($field['label'] ?? '', 160);
            if ($label === '') {
                $errors["fields.{$i}.label"] = 'Give the question a label.';
                continue;
            }

            // A stable field_key: answers_json is keyed by it, so an existing
            // key is never regenerated from a changed label (§9.3).
            $key = se_str($field['field_key'] ?? '', 40);
            $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $key) ?? '');
            $key = trim($key, '_');
            if ($key === '') {
                $key = trim(strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $label) ?? ''), '_');
                $key = substr($key, 0, 32) ?: 'q' . ($i + 1);
            }
            if (isset($keys[$key])) {
                $key = substr($key, 0, 34) . '_' . ($i + 1);
            }
            $keys[$key] = true;

            $type = se_enum($field['type'] ?? 'text', SE_FORM_FIELD_TYPES, 'text');

            $options = [];
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                $clean = se_line($option, 80);
                if ($clean !== '') {
                    $options[] = $clean;
                }
            }
            if (in_array($type, ['select', 'multiselect'], true) && count($options) < 2) {
                $errors["fields.{$i}.options"] = 'A choice question needs at least two options.';
                continue;
            }

            $rows[] = [
                'field_key'   => $key,
                'label'       => $label,
                'type'        => $type,
                'options_json' => $options ? se_json_encode(array_slice($options, 0, 20)) : null,
                'is_required' => se_bool($field['is_required'] ?? false) ? 1 : 0,
                'placeholder' => se_line($field['placeholder'] ?? '', 120) ?: null,
                'help_text'   => se_line($field['help_text'] ?? '', 255) ?: null,
                'audience'    => se_enum($field['audience'] ?? 'everyone', ['everyone', 'guests', 'members'], 'everyone'),
                'sort_order'  => $i,
                'is_active'   => se_bool($field['is_active'] ?? true) ? 1 : 0,
            ];
        }

        if ($errors) {
            throw new SeValidationException($errors);
        }

        $pdo->beginTransaction();
        try {
            // Questions that disappeared are deactivated, not deleted: their
            // key may still appear in an existing registration's answers.
            if ($rows) {
                $in = implode(',', array_fill(0, count($rows), '?'));
                $pdo->prepare("UPDATE se_form_fields SET is_active = 0 WHERE event_id = ? AND field_key NOT IN ({$in})")
                    ->execute(array_merge([$eventId], array_column($rows, 'field_key')));
            } else {
                $pdo->prepare("UPDATE se_form_fields SET is_active = 0 WHERE event_id = ?")->execute([$eventId]);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO se_form_fields
                    (event_id, field_key, label, type, options_json, is_required, placeholder, help_text, audience, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    label = VALUES(label), type = VALUES(type), options_json = VALUES(options_json),
                    is_required = VALUES(is_required), placeholder = VALUES(placeholder),
                    help_text = VALUES(help_text), audience = VALUES(audience),
                    sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
            );
            foreach ($rows as $row) {
                $stmt->execute([
                    $eventId, $row['field_key'], $row['label'], $row['type'], $row['options_json'],
                    $row['is_required'], $row['placeholder'], $row['help_text'],
                    $row['audience'], $row['sort_order'], $row['is_active'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        se_audit($pdo, $eventId, 'event_update:form_fields', ['count' => count($rows)], 'event', $eventId, $userId);

        se_api_success('Questions saved.', ['form_fields' => se_form_fields_list($pdo, $eventId)]);
    }

    // ====================================================================
    // Attendees (§12.5)
    // ====================================================================

    case 'attendees_list': {
        $event = se_studio_event($pdo, $body, 'attendee.search');
        $pii   = se_has_capability($pdo, (int) $event['id'], 'attendee.pii', $userId);

        $data = se_attendees_list(
            $pdo,
            (int) $event['id'],
            is_array($body['filters'] ?? null) ? $body['filters'] : [],
            se_str($body['q'] ?? '', 80),
            se_int($body['page'] ?? 1, 1),
            se_int($body['per_page'] ?? 50, 1),
            $pii
        );

        se_api_success('OK', $data + [
            'counts' => se_event_counts($pdo, (int) $event['id']),
            'pii'    => $pii,
        ]);
    }

    case 'attendee_get': {
        $event = se_studio_event($pdo, $body, 'attendee.search');
        $pii   = se_has_capability($pdo, (int) $event['id'], 'attendee.pii', $userId);

        $row = se_attendee_get($pdo, (int) $event['id'], se_int($body['attendee_id'] ?? 0, 1), $pii);
        if (!$row) {
            se_api_error('That person is not on this list.', 'EVENT_NOT_FOUND');
        }

        se_api_success('OK', ['attendee' => $row]);
    }

    case 'attendee_update': {
        $event = se_studio_event($pdo, $body, 'attendee.pii');
        $row   = se_attendee_update(
            $pdo, $event, se_int($body['attendee_id'] ?? 0, 1),
            is_array($body['fields'] ?? null) ? $body['fields'] : [],
            $userId
        );

        se_api_success('Saved.', ['attendee' => $row]);
    }

    case 'attendee_cancel': {
        $event = se_studio_event($pdo, $body, 'desk.checkin');
        $days  = se_event_days($pdo, (int) $event['id']);

        $reg = se_registration_by_id($pdo, se_int($body['attendee_id'] ?? 0, 1), (int) $event['id']);
        if (!$reg) {
            se_api_error('That person is not on this list.', 'EVENT_NOT_FOUND');
        }

        $result = se_registration_cancel($pdo, $event, $days, $reg, 'crew', se_line($body['reason'] ?? '', 160));

        se_api_success($result['status'] === 'cancelled' ? 'Seat released.' : 'Nothing to cancel.', [
            'status'   => $result['status'],
            'promoted' => $result['promoted'],
            'counts'   => se_event_counts($pdo, (int) $event['id']),
        ]);
    }

    case 'attendee_restore': {
        $event  = se_studio_event($pdo, $body, 'desk.checkin');
        $days   = se_event_days($pdo, (int) $event['id']);
        $result = se_attendee_restore($pdo, $event, $days, se_int($body['attendee_id'] ?? 0, 1), $userId);

        se_api_success($result['status'] === 'confirmed' ? 'Back on the list.' : 'Back on, as a waitlist place.', [
            'status' => $result['status'],
            'counts' => se_event_counts($pdo, (int) $event['id']),
        ]);
    }

    case 'attendee_promote': {
        $event  = se_studio_event($pdo, $body, 'event.capacity_override');
        $days   = se_event_days($pdo, (int) $event['id']);
        $result = se_attendee_promote($pdo, $event, $days, se_int($body['attendee_id'] ?? 0, 1), $userId);

        se_api_success($result['promoted'] ? 'Promoted off the waitlist.' : 'Nothing to promote.', [
            'promoted' => $result['promoted'],
            'counts'   => se_event_counts($pdo, (int) $event['id']),
        ]);
    }

    case 'attendee_remove': {
        $event  = se_studio_event($pdo, $body, 'attendee.pii');
        $days   = se_event_days($pdo, (int) $event['id']);
        $reason = se_line($body['reason'] ?? '', 160);
        if ($reason === '') {
            throw new SeValidationException(['reason' => 'Say why, so the next person reading the log understands.']);
        }

        $result = se_attendee_remove($pdo, $event, $days, se_int($body['attendee_id'] ?? 0, 1), $reason, $userId);

        se_api_success('Removed from the event.', $result + ['counts' => se_event_counts($pdo, (int) $event['id'])]);
    }

    case 'attendee_add': {
        $event = se_studio_event($pdo, $body, 'desk.checkin');
        $days  = se_event_days($pdo, (int) $event['id']);
        $settings = se_event_settings($event);

        $phone = se_phone_normalize($body['phone'] ?? '');
        if ($phone === null) {
            se_api_error('Please enter a mobile number, e.g. 0803 123 4567.', 'INVALID_PHONE');
        }

        $first = se_name_case(se_clean_name($body['first_name'] ?? ''));
        if ($first === '') {
            throw new SeValidationException(['first_name' => 'A first name is required.']);
        }

        $result = se_register($pdo, $event, $settings, $days, [
            'phone'            => $phone,
            'first_name'       => $first,
            'last_name'        => se_name_case(se_clean_name($body['last_name'] ?? '')),
            'gender'           => se_normalize_gender($body['gender'] ?? null),
            'email'            => se_contact_clean_email($body['email'] ?? null),
            'consent'          => se_bool($body['consent'] ?? false),
            'consent_text'     => (string) ($settings['registration']['consent_text'] ?? ''),
            'karaoke_interest' => se_bool($body['karaoke_interest'] ?? false),
            'answers'          => [],
            'src'              => null,
            'channel'          => 'studio',
            'is_test'          => se_bool($settings['test_mode'] ?? false),
            // A desk entry may be made after registration has closed: that
            // is the whole point of adding someone by hand (§10.4.5).
            'ignore_state'     => se_bool($body['force'] ?? true),
        ], ['now' => se_now(), 'ip_hash' => se_ip_hash(), 'device' => null, 'actor_user_id' => $userId]);

        if ($result['outcome'] === 'closed') {
            se_api_error(se_registration_state_message((string) $result['state'], $event), 'REG_CLOSED', ['state' => $result['state']]);
        }
        if ($result['outcome'] === 'blocked') {
            se_api_error('That person was removed from this event. Restore them instead.', 'BLOCKED');
        }

        se_api_success($result['outcome'] === 'already' ? 'Already on the list.' : 'Added.', [
            'outcome'  => $result['outcome'],
            'attendee' => se_attendee_get($pdo, (int) $event['id'], (int) $result['registration']['id'], true),
            'counts'   => se_event_counts($pdo, (int) $event['id']),
        ]);
    }

    case 'attendee_reset_links': {
        $event = se_studio_event($pdo, $body, 'attendee.pii');
        $days  = se_event_days($pdo, (int) $event['id']);
        $url   = se_attendee_reset_links($pdo, $event, $days, se_int($body['attendee_id'] ?? 0, 1), $userId);

        se_api_success('Old links are dead. Here is the new one.', ['manage_url' => $url]);
    }

    case 'attendee_erase': {
        // Irreversible, so it is a manager action, not a desk one (§19.9).
        se_require_manager($pdo);
        $event  = se_studio_event($pdo, $body, 'attendee.pii');
        $reason = se_line($body['reason'] ?? '', 160);
        if ($reason === '') {
            throw new SeValidationException(['reason' => 'Record why this erasure was requested.']);
        }

        se_attendee_erase($pdo, $event, se_int($body['attendee_id'] ?? 0, 1), $reason, $userId);

        se_api_success('Erased. Only the anonymous counts remain.', ['erased' => true]);
    }

    case 'attendees_export': {
        // The file itself is a separate GET endpoint: a spreadsheet cannot
        // travel inside the {status, message, data} envelope (§28.4).
        $event = se_studio_event($pdo, $body, 'attendee.export');

        if (!se_export_available()) {
            se_api_error('Spreadsheet support is not installed on this server.', 'FEATURE_NOT_READY');
        }

        se_api_success('OK', [
            'url'      => '/api/special_events_export.php?event=' . rawurlencode((string) $event['public_id']),
            'filename' => se_export_attendees_filename($event),
        ]);
    }

    case 'possible_duplicates': {
        $event = se_studio_event($pdo, $body, 'attendee.search');

        se_api_success('OK', ['items' => se_attendee_duplicates($pdo, (int) $event['id'])]);
    }

    case 'possible_members': {
        $event = se_studio_event($pdo, $body, 'attendee.search');

        se_api_success('OK', ['items' => se_attendee_possible_members($pdo, (int) $event['id'])]);
    }

    // ====================================================================
    // Share kit (§18.2) and copywriting (§15.8)
    // ====================================================================

    case 'share_kit': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        $base  = se_event_url((string) $event['slug']);

        $links = [['code' => '', 'label' => 'Plain link', 'url' => $base]];
        foreach (SE_SOURCE_CODES as $code => $label) {
            $links[] = ['code' => $code, 'label' => $label, 'url' => $base . '?s=' . $code];
        }
        $links[] = ['code' => 'checkin', 'label' => 'Check-in poster QR', 'url' => se_event_url((string) $event['slug'], 'in')];

        se_api_success('OK', ['links' => $links, 'views' => se_share_kit_views($pdo, (int) $event['id'])]);
    }

    case 'copywrite': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $purpose = se_enum($body['purpose'] ?? '', SE_COPYWRITE_PURPOSES, '');
        if ($purpose === '') {
            throw new SeValidationException(['purpose' => 'Choose what to write.']);
        }

        $days     = se_event_days($pdo, (int) $event['id']);
        $first    = $days[0] ?? null;
        $settings = se_event_settings($event);

        // Facts only, and never a single attendee's name, phone or email —
        // the AI privacy rule in §15.1.
        $facts = array_filter([
            $first !== null ? 'date ' . se_format_day(se_parse_datetime($first['starts_at'])) : null,
            ($event['venue_name'] ?? '') !== '' ? 'venue ' . $event['venue_name'] : null,
            ($event['tagline'] ?? '') !== '' ? 'tagline "' . $event['tagline'] . '"' : null,
            se_settings_path($settings, 'karaoke.enabled', true) ? 'karaoke' : null,
            se_settings_path($settings, 'games.enabled', true) ? 'Bible games and teams' : null,
            'tone words: ' . implode(', ', array_slice(array_map(
                static fn($w) => se_line($w, 24),
                is_array($body['tone'] ?? null) ? $body['tone'] : ['joyful', 'warm']
            ), 0, 4)),
            se_line($body['brief'] ?? '', 240) !== '' ? 'brief: ' . se_line($body['brief'], 240) : null,
        ]);

        $result = se_ai($pdo, 'copywrite', [
            'count'     => 3,
            'purpose'   => SE_COPYWRITE_LABELS[$purpose],
            'limit'     => SE_COPYWRITE_LIMITS[$purpose],
            'title'     => (string) $event['title'],
            'edition'   => (string) ($event['edition_label'] ?? ''),
            'organizer' => (string) ($event['organizer_label'] ?? 'Envision'),
            'facts'     => implode('; ', $facts),
        ], ['event_id' => (int) $event['id'], 'user_id' => $userId]);

        // SMS copy must survive sms_segments() before anyone can apply it.
        $variants = array_values(array_filter(array_map(
            static fn($v) => se_line($v, 600),
            (array) ($result['variants'] ?? [])
        )));

        if (str_starts_with($purpose, 'sms_') && function_exists('sms_segments')) {
            $variants = array_values(array_filter($variants, static function (string $text): bool {
                $info = sms_segments(str_replace('{{link}}', str_repeat('x', 53), $text));
                return ((int) ($info['pages'] ?? 1)) <= 2 && ($info['encoding'] ?? '') === 'GSM-7';
            }));
        }

        se_api_success('Here are three.', ['variants' => array_slice($variants, 0, 3), 'purpose' => $purpose]);
    }


    // ====================================================================
    // Teams (§10.6)
    // ====================================================================

    case 'teams_get': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        if (!se_teams_ready($pdo)) {
            se_api_error('Teams are not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        se_api_success('OK', se_teams_payload($pdo, $event));
    }

    case 'teams_save': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        if (!se_teams_ready($pdo)) {
            se_api_error('Teams are not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        // The Studio sends a paste box of hex codes plus the names typed
        // against each row. se_teams_save() wants one ordered list, so the
        // two are merged here rather than giving the library two shapes.
        $existing = se_teams($pdo, (int) $event['id']);
        $names    = [];
        foreach (is_array($body['teams'] ?? null) ? $body['teams'] : [] as $row) {
            if (!empty($row['id'])) {
                $names[(int) $row['id']] = se_line($row['name'] ?? '', 40);
            }
        }

        $hexList = isset($body['hex_list']) && is_string($body['hex_list'])
            ? se_parse_hex_list($body['hex_list'])
            : array_map(static fn(array $t): string => (string) $t['color_hex'], $existing);

        if (count($hexList) < SE_MIN_TEAMS || count($hexList) > SE_MAX_TEAMS) {
            throw new SeValidationException([
                'hex_list' => 'Between ' . SE_MIN_TEAMS . ' and ' . SE_MAX_TEAMS . ' colours, please.',
            ]);
        }

        $rows = [];
        foreach ($hexList as $i => $hex) {
            $current = $existing[$i] ?? null;
            $rows[] = [
                'color_hex'   => $hex,
                'color_label' => $current !== null && strcasecmp((string) $current['color_hex'], $hex) === 0
                    ? (string) $current['color_label']
                    : '',
                'name' => $current !== null
                    ? ($names[(int) $current['id']] ?? (string) ($current['name'] ?? ''))
                    : '',
            ];
        }

        se_teams_save($pdo, $event, $rows, $userId);

        se_api_success('Teams saved.', se_teams_payload($pdo, $event));
    }

    case 'team_move': {
        $event = se_studio_event($pdo, $body, 'team.move');

        se_team_move(
            $pdo,
            $event,
            se_int($body['registration_id'] ?? 0, 0),
            se_int($body['team_id'] ?? 0, 0),
            se_line($body['reason'] ?? '', 160),
            $userId
        );

        se_api_success('Moved.', se_teams_payload($pdo, $event));
    }

    case 'captain_set': {
        $event = se_studio_event($pdo, $body, 'team.rename');

        se_captain_set(
            $pdo,
            $event,
            se_int($body['team_id'] ?? 0, 0),
            isset($body['registration_id']) && $body['registration_id'] !== null && $body['registration_id'] !== ''
                ? se_int($body['registration_id'], 0)
                : null,
            $userId
        );

        se_api_success('Captain set.', se_teams_payload($pdo, $event));
    }

    // ====================================================================
    // Welcome verses (§10.9, §15.7)
    // ====================================================================

    case 'verses_list': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        if (!se_verses_ready($pdo)) {
            se_api_error('Welcome verses are not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        se_api_success('OK', [
            'verses' => array_map('se_verse_payload', se_verses_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'verse_add': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $verse = se_verse_add(
            $pdo,
            $event,
            se_line($body['ref'] ?? '', 60),
            $body['prayer_template'] ?? null,
            $userId,
            $body['text'] ?? null
        );

        se_api_success('Added.', [
            'verse'  => se_verse_payload($verse),
            'verses' => array_map('se_verse_payload', se_verses_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'verses_save': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        se_verses_save($pdo, $event, is_array($body['verses'] ?? null) ? $body['verses'] : [], $userId);

        se_api_success('Saved.', [
            'verses' => array_map('se_verse_payload', se_verses_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'verse_approve': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        se_verse_approve($pdo, $event, se_int($body['verse_id'] ?? 0, 0), $userId);

        se_api_success('Approved.', [
            'verses' => array_map('se_verse_payload', se_verses_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'verse_delete': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        se_verse_delete($pdo, $event, se_int($body['verse_id'] ?? 0, 0), $userId);

        se_api_success('Removed.', [
            'verses' => array_map('se_verse_payload', se_verses_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'verses_suggest': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        // AI only ever sees the event's theme words — never a guest (§15.1).
        $result = se_verses_suggest(
            $pdo,
            $event,
            se_line($body['theme'] ?? '', 160),
            se_int($body['count'] ?? 8, 8),
            se_line($body['tone'] ?? 'warm and hopeful', 60),
            $userId
        );

        se_api_success('Here are some suggestions. Nothing is saved until you approve it.', $result);
    }

    case 'bible_lookup': {
        se_studio_event($pdo, $body, 'insights.view');

        $found = se_bible_lookup($pdo, se_line($body['ref'] ?? '', 60), 'KJV');
        if ($found === null) {
            se_api_error('We could not find that reference in the KJV.', 'NOT_FOUND');
        }

        se_api_success('OK', $found);
    }

    // ====================================================================
    // Live: monitor, display keys, rehearsal (§11.13, §18.3)
    // ====================================================================

    case 'live_monitor': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        if (!se_live_ready($pdo)) {
            se_api_error('The live monitor is not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        se_api_success('OK', se_live_monitor($pdo, $event));
    }

    case 'keys_rotate': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        if (!se_live_ready($pdo)) {
            se_api_error('Display keys are not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        $which = is_array($body['which'] ?? null) ? $body['which'] : ['stage', 'lobby', 'room'];

        se_api_success('New links. The old ones stop working now.', [
            'displays' => se_live_keys_rotate($pdo, $event, $which, $userId),
        ]);
    }

    case 'test_mode': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $fresh = se_test_mode_set($pdo, $event, se_bool($body['on'] ?? false), $userId);

        se_api_success(
            se_bool($body['on'] ?? false)
                ? 'Test mode is ON. Everything from now on is a rehearsal.'
                : 'Test mode is off.',
            ['test_mode' => se_bool(se_event_settings($fresh)['test_mode'] ?? false)]
        );
    }

    case 'reset_rehearsal': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $out = se_reset_rehearsal($pdo, $event, $userId);

        se_api_success('Rehearsal cleared.', ['removed' => $out['removed']]);
    }

    // ====================================================================
    // Check-in posters (§14.2)
    // ====================================================================

    // ====================================================================
    // Days & programme (§10.7)
    // ====================================================================

    case 'program_list': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('OK', [
            'program' => se_program_payload($pdo, $event),
            'kinds'   => SE_PROGRAM_KINDS,
            'time_mode' => se_event_settings($event)['program']['public_time_mode'] ?? 'approximate',
        ]);
    }

    case 'program_save': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];

        $program = se_program_save($pdo, $event, $items, $userId);

        // The public time mode travels with the builder, because it is the
        // one programme setting a Producer changes while looking at it.
        if (isset($body['public_time_mode'])) {
            se_event_settings_patch($pdo, $event, [
                'program' => ['public_time_mode' => se_enum($body['public_time_mode'], SE_PROGRAM_TIME_MODES, 'approximate')],
            ], $userId);
        }

        se_api_success('Programme saved.', ['program' => $program]);
    }

    case 'program_import': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        se_rate_limit_or_fail($pdo, 'ai_user', 'u' . $userId, 30, 3600);

        se_api_success('Here is what we read.', se_program_import($pdo, $event, [
            'text'     => $body['text'] ?? '',
            'asset_id' => $body['asset_id'] ?? null,
        ], $userId));
    }

    case 'program_apply': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$items && !empty($body['job_id'])) {
            // The reviewer applied the job unchanged: read the rows back
            // from the stored result rather than trusting the round trip.
            $job = se_ai_job_find($pdo, se_int($body['job_id'], 1), (int) $event['id']);
            $items = is_array(se_json_decode($job['result_json'] ?? null)['items'] ?? null)
                ? se_json_decode($job['result_json'])['items'] : [];
        }

        $program = se_program_apply(
            $pdo,
            $event,
            $items,
            se_str($body['mode'] ?? 'append', 10),
            se_int($body['day_id'] ?? 0, 0),
            se_int_or_null($body['job_id'] ?? null, 1),
            $userId
        );

        se_api_success('Programme updated.', ['program' => $program]);
    }

    // ====================================================================
    // Karaoke (§10.8)
    // ====================================================================

    case 'songs_event_list': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('OK', se_songs_event_list($pdo, $event, [
            'q'        => $body['q'] ?? '',
            'page'     => $body['page'] ?? 1,
            'per_page' => $body['per_page'] ?? 100,
        ]) + [
            'settings' => se_event_settings($event)['karaoke'] ?? [],
            'queue'    => se_karaoke_ready($pdo) ? se_karaoke_queue($pdo, $event) : null,
        ]);
    }

    case 'songs_import_preview': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $assetId = se_int_or_null($body['asset_id'] ?? null, 1);
        $text    = se_str($body['text'] ?? '', 60000);
        $csv     = se_str($body['csv'] ?? '', 200000);

        if ($assetId !== null) {
            se_rate_limit_or_fail($pdo, 'ai_user', 'u' . $userId, 30, 3600);
            se_api_success('Here is what we read.', se_songs_import_ai($pdo, $event, $assetId, $text, $userId));
        }

        $rows = $csv !== '' ? se_songs_parse_csv($csv) : se_songs_parse_text($text);
        if (!$rows) {
            throw new SeValidationException(['text' => 'We could not find any songs in that.']);
        }

        se_api_success('Here is what we read.', se_songs_import_preview($pdo, $event, $rows));
    }

    case 'songs_import_commit': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        $rows  = is_array($body['rows'] ?? null) ? $body['rows'] : [];
        if (!$rows) {
            throw new SeValidationException(['rows' => 'Nothing to add.']);
        }

        se_api_success('Songs added.', se_songs_import_commit($pdo, $event, $rows, $userId));
    }

    case 'songs_toggle': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        se_api_success('Updated.', se_song_toggle(
            $pdo, $event, se_int($body['song_id'] ?? 0, 1), se_bool($body['active'] ?? true), $userId
        ));
    }

    case 'karaoke_settings_save': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        $in    = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        $updated = se_event_settings_patch($pdo, $event, ['karaoke' => $in], $userId);

        se_api_success('Karaoke settings saved.', [
            'settings' => se_event_settings($updated)['karaoke'] ?? [],
            'event'    => se_studio_event_payload($pdo, $updated, $userId, $accessLevel, true),
        ]);
    }

    case 'karaoke_publish_list': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        $on    = se_bool($body['on'] ?? true);

        $updated = se_event_settings_patch($pdo, $event, ['karaoke' => ['list_published' => $on]], $userId);

        se_live_publish($pdo, (int) $event['id'], true);

        se_api_success($on ? 'The song list is live.' : 'The song list is hidden again.', [
            'settings' => se_event_settings($updated)['karaoke'] ?? [],
        ]);
    }

    // ====================================================================
    // Messages (§16)
    // ====================================================================

    case 'messages_get': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('OK', se_messages_studio($pdo, $event));
    }

    case 'messages_save': {
        $event = se_studio_event($pdo, $body, 'event.edit');
        $in    = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        $updated = se_event_settings_patch($pdo, $event, ['messages' => $in], $userId);

        se_api_success('Messages saved.', se_messages_studio($pdo, $updated));
    }

    case 'messages_preview':
    case 'messages_estimate': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        $kind     = se_str($body['kind'] ?? '', 30);
        $settings = se_event_settings($event);
        $days     = se_event_days($pdo, (int) $event['id']);

        $template = isset($body['template']) && se_str($body['template'], 600) !== ''
            ? se_str($body['template'], 600)
            : se_message_template($settings, $kind);

        $preview  = se_message_preview($template, $event, $days, [], $settings);
        $segment  = se_str($body['segment'] ?? $kind, 40);
        $audience = count(se_message_audience($pdo, $event, $segment, $days[0] ?? null));

        se_api_success('OK', [
            'preview'    => $preview,
            'recipients' => $audience,
            'est_units'  => $audience * (int) $preview['pages'],
        ]);
    }

    case 'messages_test': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        se_api_success('Test message queued.', se_messages_test_send(
            $pdo, $event, se_str($body['kind'] ?? '', 30), se_str($body['phone'] ?? '', 20), $userId
        ));
    }

    case 'messages_send_adhoc': {
        $event = se_studio_event($pdo, $body, 'event.edit');

        $result = se_messages_send_adhoc(
            $pdo, $event,
            se_str($body['segment'] ?? '', 40),
            se_str($body['template'] ?? '', 600),
            $userId
        );

        se_api_success('On its way to ' . $result['recipients'] . ' people.', $result + [
            'runs' => se_message_runs($pdo, (int) $event['id']),
        ]);
    }

    case 'messages_run_now': {
        // The recovery path for a cron that stopped (§16.4). It claims the
        // same run keys, so it can never double-send what the cron did.
        $event = se_studio_event($pdo, $body, 'event.edit');

        $runs = se_messages_run_due($pdo, $event, se_now(), $userId);

        se_api_success($runs ? 'Done.' : 'Nothing is due right now.', [
            'ran'  => $runs,
            'runs' => se_message_runs($pdo, (int) $event['id']),
        ]);
    }

    case 'message_runs': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('OK', ['runs' => se_message_runs($pdo, (int) $event['id'])]);
    }

    case 'poster_data': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('OK', se_poster_payload($pdo, $event));
    }

    case 'render_save': {
        $event = se_studio_event($pdo, $body, 'assets.manage');

        $role = se_str($body['kind'] ?? '', 30);
        if (!in_array($role, ['poster_a4', 'poster_a3'], true)) {
            throw new SeValidationException(['kind' => 'That render cannot be saved yet.']);
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw new SeValidationException(['file' => 'The browser did not send the rendered image.']);
        }

        $asset = se_asset_store($pdo, $event, $role, $file, [
            'title'    => $role === 'poster_a3' ? 'Check-in poster (A3)' : 'Check-in poster (A4)',
            'alt_text' => 'Check-in poster for ' . $event['title'] . ' with a QR code to the check-in page.',
        ], $userId);

        se_api_success('Poster saved.', [
            'asset'  => se_studio_asset_payload($asset),
            'pdf'    => '/api/special_events_poster_pdf.php?event=' . rawurlencode((string) $event['public_id'])
                . '&asset=' . (int) $asset['id'],
            'assets' => array_map('se_studio_asset_payload', se_asset_list($pdo, (int) $event['id'])),
        ]);
    }

    // ====================================================================
    // Assets
    // ====================================================================

    case 'asset_upload':
    case 'template_upload': {
        $event = se_studio_event($pdo, $body, 'assets.manage');

        $role = $action === 'template_upload' ? 'template' : se_str($body['role'] ?? '', 30);
        if (!isset(SE_ASSET_ROLES[$role])) {
            throw new SeValidationException(['role' => 'Choose what this file is for.']);
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw new SeValidationException(['file' => 'Choose a file to upload.']);
        }

        $asset = se_asset_store($pdo, $event, $role, $file, [
            'title'    => $body['title'] ?? '',
            'alt_text' => $body['alt_text'] ?? '',
        ], $userId);

        se_api_success('Uploaded.', [
            'asset'  => se_studio_asset_payload($asset),
            'assets' => array_map('se_studio_asset_payload', se_asset_list($pdo, (int) $event['id'])),
        ]);
    }

    case 'asset_list': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        $role  = se_str($body['role'] ?? '', 30);

        se_api_success('Assets loaded.', [
            'assets' => array_map('se_studio_asset_payload', se_asset_list($pdo, (int) $event['id'], $role ?: null)),
            'roles'  => array_keys(SE_ASSET_ROLES),
        ]);
    }

    case 'asset_update': {
        [$asset, $event] = se_studio_asset($pdo, $body, 'assets.manage');

        $updated = se_asset_update($pdo, (int) $event['id'], (int) $asset['id'], [
            'title'    => $body['title'] ?? null,
            'alt_text' => $body['alt_text'] ?? null,
            'role'     => $body['role'] ?? null,
        ], $userId);

        se_api_success('Saved.', ['asset' => se_studio_asset_payload($updated)]);
    }

    case 'asset_delete': {
        [$asset, $event] = se_studio_asset($pdo, $body, 'assets.manage');
        $eventId = (int) $event['id'];

        se_asset_delete($pdo, $eventId, (int) $asset['id'], $userId);

        se_api_success('File removed.', [
            'assets' => array_map('se_studio_asset_payload', se_asset_list($pdo, $eventId)),
            'event'  => se_studio_event_payload($pdo, se_event_find($pdo, $eventId) ?? [], $userId, $accessLevel, true),
        ]);
    }

    // ====================================================================
    // Crew
    // ====================================================================

    case 'crew_list': {
        $event = se_studio_event($pdo, $body, 'insights.view');
        if (!se_table_exists($pdo, 'se_crew')) {
            se_api_error('Crew is not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        $stmt = $pdo->prepare(
            "SELECT c.id, c.user_id, c.role, c.added_at, c.revoked_at,
                    u.first_name, u.last_name, u.email, u.picture_path
             FROM se_crew c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.event_id = ?
             ORDER BY c.revoked_at IS NOT NULL, c.role, u.first_name"
        );
        $stmt->execute([(int) $event['id']]);

        $crew = array_map(static fn(array $c): array => [
            'id'         => (int) $c['id'],
            'user_id'    => (int) $c['user_id'],
            'role'       => $c['role'],
            'role_label' => SE_CREW_ROLES[(string) $c['role']] ?? $c['role'],
            'name'       => trim(((string) $c['first_name']) . ' ' . ((string) $c['last_name'])) ?: 'Unknown user',
            'email'      => $c['email'],
            'picture_path' => $c['picture_path'],
            'added_at'   => se_iso($c['added_at']),
            'revoked'    => $c['revoked_at'] !== null,
        ], $stmt->fetchAll());

        se_api_success('Crew loaded.', ['crew' => $crew, 'roles' => SE_CREW_ROLES]);
    }

    case 'crew_add': {
        $event   = se_studio_event($pdo, $body, 'event.crew');
        $eventId = (int) $event['id'];

        if (!se_table_exists($pdo, 'se_crew')) {
            se_api_error('Crew is not available until the database is migrated.', 'FEATURE_NOT_READY');
        }

        $targetId = se_int($body['user_id'] ?? 0, 1);
        $role     = se_str($body['role'] ?? '', 20);
        if (!isset(SE_CREW_ROLES[$role])) {
            throw new SeValidationException(['role' => 'Choose a crew role.']);
        }

        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new SeValidationException(['user_id' => 'That person is not in the directory.']);
        }

        $pdo->prepare(
            "INSERT INTO se_crew (event_id, user_id, role, added_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE revoked_at = NULL, added_by = VALUES(added_by), added_at = NOW()"
        )->execute([$eventId, $targetId, $role, $userId]);

        // Tell them, exactly as Reach does (§6.2).
        try {
            $pdo->prepare(
                "INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)"
            )->execute([
                $targetId,
                'You are on the crew for ' . $event['title'],
                'You have been added as ' . (SE_CREW_ROLES[$role] ?? $role) . ' for ' . $event['title'] . '.',
                se_studio_url($eventId, 'overview'),
            ]);
        } catch (Throwable $e) {
            // A notification failure must not undo the crew change.
            error_log('SE studio/crew_add notify: ' . $e->getMessage());
        }

        se_audit($pdo, $eventId, 'crew_add', ['user_id' => $targetId, 'role' => $role], 'crew', $targetId, $userId);

        se_api_success(
            trim(((string) $target['first_name']) . ' ' . ((string) $target['last_name'])) . ' is now ' . (SE_CREW_ROLES[$role] ?? $role) . '.',
            ['added' => true]
        );
    }

    case 'crew_revoke': {
        // §12.5 names the CREW ROW in `id`, so the event is derived from it
        // rather than taken from the request — a caller cannot pair someone
        // else's crew row with an event they happen to control.
        $crewId = se_int($body['id'] ?? 0, 1);

        $stmt = $pdo->prepare("SELECT * FROM se_crew WHERE id = ?");
        $stmt->execute([$crewId]);
        $row = $stmt->fetch();
        if (!$row) {
            se_api_error('That crew member is no longer on this event.', 'EVENT_NOT_FOUND');
        }

        $eventId = (int) $row['event_id'];
        $event   = se_event_find($pdo, $eventId);
        if (!$event) {
            se_api_error('That event no longer exists.', 'EVENT_NOT_FOUND');
        }
        se_require_capability($pdo, $eventId, 'event.crew');

        // The last Producer must not be removed: H.1 requires one, and
        // without it nobody could edit the event again.
        if ((string) $row['role'] === 'producer' && $row['revoked_at'] === null) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM se_crew WHERE event_id = ? AND role = 'producer' AND revoked_at IS NULL"
            );
            $stmt->execute([$eventId]);
            if ((int) $stmt->fetchColumn() <= 1) {
                se_api_error('This is the only Producer. Add another one first.', 'VALIDATION');
            }
        }

        $pdo->prepare("UPDATE se_crew SET revoked_at = NOW() WHERE id = ? AND event_id = ?")
            ->execute([$crewId, $eventId]);

        se_audit($pdo, $eventId, 'crew_revoke', [
            'user_id' => (int) $row['user_id'], 'role' => $row['role'],
        ], 'crew', $crewId, $userId);

        se_api_success('Crew member removed.', ['revoked' => true]);
    }

    case 'user_search': {
        $event = se_studio_event($pdo, $body, 'event.crew');
        $q     = se_line($body['q'] ?? '', 60);
        if (mb_strlen($q, 'UTF-8') < 2) {
            se_api_success('Type at least two letters.', ['users' => []]);
        }

        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, email, picture_path
             FROM users
             WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?
                    OR CONCAT(first_name, ' ', last_name) LIKE ?)
               AND COALESCE(account_status, 'active') = 'active'
             ORDER BY first_name, last_name
             LIMIT 20"
        );
        $stmt->execute([$like, $like, $like, $like]);

        se_api_success('Found.', [
            'users' => array_map(static fn(array $u): array => [
                'id'    => (int) $u['id'],
                'name'  => trim(((string) $u['first_name']) . ' ' . ((string) $u['last_name'])),
                // Crew search is an internal directory lookup, but the email
                // is still masked unless the viewer may see PII (§6.2).
                'email' => se_has_capability($pdo, (int) $event['id'], 'attendee.pii')
                    ? $u['email']
                    : ($u['email'] ? se_mask_email((string) $u['email']) : null),
                'picture_path' => $u['picture_path'],
            ], $stmt->fetchAll()),
        ]);
    }

    // ====================================================================
    // Ops
    // ====================================================================

    case 'audit_list': {
        $event = se_studio_event($pdo, $body, 'audit.view');
        [$page, $perPage, $offset] = se_studio_paging($body);

        $where  = ['a.event_id = ?'];
        $params = [(int) $event['id']];

        $filterAction = se_str($body['action_filter'] ?? '', 60);
        if ($filterAction !== '') {
            $where[]  = 'a.action LIKE ?';
            $params[] = $filterAction . '%';
        }

        $sql = 'FROM se_audit_log a WHERE ' . implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) {$sql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // The actor join is only on the list query, so COUNT(*) stays cheap.
        $listStmt = $pdo->prepare(
            "SELECT a.id, a.action, a.entity, a.entity_id, a.detail_json, a.created_at,
                    a.actor_user_id, u.first_name, u.last_name
             FROM se_audit_log a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);

        se_api_success('Audit loaded.', [
            'items' => array_map(static fn(array $a): array => [
                'id'      => (int) $a['id'],
                'action'  => $a['action'],
                'entity'  => $a['entity'],
                'entity_id' => $a['entity_id'] !== null ? (int) $a['entity_id'] : null,
                'detail'  => se_json_decode($a['detail_json'] ?? null),
                'actor'   => $a['actor_user_id'] !== null
                    ? (trim(((string) $a['first_name']) . ' ' . ((string) $a['last_name'])) ?: 'User #' . $a['actor_user_id'])
                    : 'System',
                'created_at' => se_iso($a['created_at']),
            ], $listStmt->fetchAll()),
            'page'  => $page,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'total' => $total,
        ]);
    }

    case 'health': {
        $event = se_studio_event($pdo, $body, 'insights.view');

        se_api_success('Health loaded.', [
            'module_version' => SE_MODULE_VERSION,
            'schema'         => se_schema_check($pdo),
            'ai'             => [
                'available' => se_ai_available(),
                'reason'    => se_ai_unavailable_reason(),
                'model_text' => (string) ($_ENV['SE_AI_MODEL_TEXT'] ?? 'gemini-2.5-flash'),
                'model_vision' => (string) ($_ENV['SE_AI_MODEL_VISION'] ?? 'gemini-2.5-flash'),
            ],
            'hash_pepper'    => ['configured' => se_has_hash_pepper()],
            'realtime'       => ['driver' => (string) ($_ENV['SE_REALTIME_DRIVER'] ?? 'poll')],
            'uploads'        => [
                'writable' => is_writable(se_docroot() . '/uploads') || is_writable(se_docroot()),
                'gd_webp'  => function_exists('imagewebp'),
            ],
            'cron_last_run'  => se_setting_get($pdo, 'cron_last_run'),
            'server_time'    => se_now()->format('c'),
            'event'          => ['id' => (int) $event['id'], 'phase' => se_event_phase($event, se_event_days($pdo, (int) $event['id']))['phase']],
        ]);
    }

    case 'settings_get': {
        $settings = se_settings_all($pdo, true);

        // Only the editable keys leave the server, so a future secret-ish
        // setting is not handed to the browser by accident.
        $out = [];
        foreach (SE_MODULE_SETTING_EDITABLE as $key => $type) {
            $out[$key] = $settings[$key] ?? '';
        }

        se_api_success('Settings loaded.', [
            'settings'   => $out,
            'types'      => SE_MODULE_SETTING_EDITABLE,
            'can_edit'   => $accessLevel === 'manager',
            'source_codes' => se_source_codes($pdo),
            'defaults'   => ['event_settings' => se_settings_defaults()],
        ]);
    }

    case 'settings_save': {
        se_require_manager($pdo);

        $incoming = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $saved    = [];
        $errors   = [];

        foreach ($incoming as $key => $value) {
            if (!isset(SE_MODULE_SETTING_EDITABLE[$key])) {
                continue;
            }
            switch (SE_MODULE_SETTING_EDITABLE[$key]) {
                case 'int':
                    $clean = (string) se_int($value, 0, 1000000, 0);
                    break;
                case 'hex':
                    $hex = se_normalize_hex($value);
                    if ($hex === null) {
                        $errors[$key] = 'Enter a hex colour like #1D356A.';
                        continue 2;
                    }
                    $clean = $hex;
                    break;
                case 'preset':
                    $clean = se_enum($value, SE_THEME_PRESETS, 'marquee');
                    break;
                case 'email':
                    $email = se_line($value, 190);
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $errors[$key] = 'Enter a valid email address.';
                        continue 2;
                    }
                    $clean = $email;
                    break;
                case 'markdown':
                    $clean = se_str($value, 20000);
                    break;
                default:
                    $clean = se_line($value, 255);
            }
            se_setting_save($pdo, (string) $key, $clean, $userId);
            $saved[] = $key;
        }

        if ($errors) {
            throw new SeValidationException($errors);
        }

        se_audit($pdo, null, 'settings_save', ['keys' => $saved], 'settings', null, $userId);

        se_api_success('Settings saved.', ['saved' => $saved]);
    }

    // ====================================================================

    case '':
        se_api_error('No action given.', 'BAD_REQUEST');

    default:
        // Actions for later PRs are deliberately absent rather than stubbed,
        // so the Studio can never half-use an unbuilt feature.
        se_api_error('Unknown action: ' . $action, 'BAD_REQUEST');
    }
} catch (Throwable $e) {
    se_api_fail($e, 'special_events_api/' . $action);
}
