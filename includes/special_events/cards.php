<?php
// /includes/special_events/cards.php
//
// Server side of the personal share cards (guide §14.3). The drawing happens
// on the attendee's phone — @se/core/svg.js resolves the template tokens and
// rasterises to PNG — so all this file does is assemble the DATA the template
// needs, and decide whether the card is allowed at all.
//
// Nothing here touches the guest's photo. It is read, cropped and drawn
// entirely in the browser and never reaches the server (§14.3, §19.5).

/** Personal card templates available on the portal. */
const SE_CARD_KINDS_READY = ['im_going', 'welcome', 'team', 'my_night'];

/** The sizes a card may be rendered at (§14.3). */
const SE_CARD_SIZES = [
    'story'  => ['w' => 1080, 'h' => 1920],
    'square' => ['w' => 1080, 'h' => 1080],
];

/**
 * The faces the "I'm going" templates are drawn in (§14.3, decided 2026-10-07).
 *
 * Fraunces is in SE_FONT_PAIRS, so it needs no new dependency — but it is
 * NOT the event's own `font_display` (Chara runs Unbounded), and the card
 * template says `font-family="Fraunces, Unbounded, serif"`. The builder must
 * therefore embed these, not the event's, or the display lines fall through
 * to Unbounded and lose the voice the design was approved for.
 */
const SE_CARD_FONTS = [
    'display' => 'Fraunces',
    'body'    => 'Inter',
];

/**
 * The verse the card carries when the Studio has not overridden it.
 *
 * Psalm 16:11, KJV, verbatim (D26, §7 decision 2). It is a *default*, sent in
 * the payload so a Studio override can take over later without touching the
 * artwork: the template renders whichever one arrives.
 */
const SE_CARD_VERSE = [
    'text' => 'Thou wilt shew me the path of life: in thy presence is fulness of joy;'
        . ' at thy right hand there are pleasures for evermore.',
    'ref'  => 'PSALM 16:11',
];

/** The house crest, used when the event has no `logo` asset of its own. */
const SE_CARD_DEFAULT_LOGO = '/assets/images/hod_logo.svg';

/** Template file for a card kind and size, relative to the web root. */
function se_card_template_url(string $kind, string $size = 'story'): string
{
    $name = $size === 'square' ? $kind . '_square' : $kind;

    return '/assets/se/templates/' . $name . '.svg';
}

/** An active registration is a confirmed seat or a place on the waitlist. */
function se_card_registration_is_active(array $registration): bool
{
    return in_array((string) ($registration['status'] ?? ''), ['confirmed', 'waitlisted'], true);
}

/**
 * Resolve the current event's active registration by the phone the guest
 * entered. This is only used for the "I'm going" card: it does not bind the
 * browser to the seat or grant access to the manage page.
 */
function se_card_registration_for_phone(PDO $pdo, int $eventId, string $e164): ?array
{
    if (!se_tables_exist($pdo, ['se_contacts', 'se_registrations'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT r.*
           FROM se_registrations r
           JOIN se_contacts c ON c.id = r.contact_id
          WHERE r.event_id = ? AND c.phone_e164 = ?
          LIMIT 1"
    );
    $stmt->execute([$eventId, $e164]);
    $registration = $stmt->fetch();

    return $registration && se_card_registration_is_active($registration) ? $registration : null;
}

/**
 * Data for one personal card.
 *
 * @throws SeRuleException FEATURE_DISABLED | NOT_CHECKED_IN | NOT_REGISTERED
 */
function se_card_payload(PDO $pdo, array $event, array $days, array $settings, string $kind, array $registration): array
{
    if (!in_array($kind, SE_CARD_KINDS_READY, true)) {
        throw new SeRuleException('FEATURE_DISABLED', 'That card is not available yet.');
    }
    if (!se_bool($settings['share_cards'][$kind] ?? true)) {
        throw new SeRuleException('FEATURE_DISABLED', 'That card is switched off for this event.');
    }
    if ($kind === 'im_going' && !se_card_registration_is_active($registration)) {
        throw new SeRuleException('NOT_REGISTERED', 'An active registration is required to make this card.');
    }

    $first = $days[0] ?? null;
    $start = se_parse_datetime($first['starts_at'] ?? ($event['starts_at'] ?? null));
    // Doors are per day and optional; the card prints them only when they
    // are set, and never invents them (§3.1, approved render).
    $doors = se_parse_datetime($first['doors_open_at'] ?? null);
    $theme = se_event_theme($event);

    $refUrl = se_event_url((string) $event['slug']) . '?r=' . rawurlencode((string) $registration['ref_code']);

    $signature = trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))
        . ' by ' . (string) ($event['organizer_label'] ?? 'Envision');

    // The welcome and team cards are about tonight, not about the invitation,
    // so they are built separately rather than bent out of the "I'm going"
    // shape. They share the colours, the fonts and the signature.
    if ($kind === 'welcome' || $kind === 'team' || $kind === 'my_night') {
        return se_card_night_payload($pdo, $event, $settings, $kind, $registration, $theme, $signature);
    }

    $heroId   = isset($event['hero_asset_id']) ? (int) $event['hero_asset_id'] : 0;
    $heroRow  = $heroId > 0 ? se_asset_find($pdo, $heroId) : null;
    $heroPath = $heroRow !== null ? se_card_hero_path($heroRow) : null;

    return [
        'kind'  => $kind,
        'sizes' => SE_CARD_SIZES,
        'templates' => [
            'story'  => se_card_template_url($kind, 'story'),
            'square' => se_card_template_url($kind, 'square'),
        ],
        'text' => [
            'title'      => (string) $event['title'],
            'edition'    => (string) ($event['edition_label'] ?? ''),
            'tagline'    => (string) ($event['tagline'] ?? ''),
            'headline'   => "I'm going!",
            'first_name' => (string) $registration['first_name'],
            'date'       => $start !== null ? $start->format('D j M') : '',
            'time'       => $start !== null ? ltrim($start->format('g:i A'), '0') : '',
            'doors'      => $doors !== null ? 'Doors open ' . ltrim($doors->format('g:i A'), '0') : '',
            'venue'      => (string) ($event['venue_name'] ?? ''),
            'url'        => se_card_short_url($event),
            'organizer'  => (string) ($event['organizer_label'] ?? 'Envision'),
            'verse_text' => SE_CARD_VERSE['text'],
            'verse_ref'  => SE_CARD_VERSE['ref'],
            'signature'  => trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))
                . ' by ' . (string) ($event['organizer_label'] ?? 'Envision'),
        ],
        'qr'     => ['ref_url' => $refUrl, 'portal_url' => se_event_url((string) $event['slug'])],
        'flags'  => ['has_photo' => false, 'multi_day' => count($days) > 1],
        'colors' => se_card_colors($theme),
        'fonts' => [
            'display' => (string) $event['font_display'],
            'body'    => (string) $event['font_body'],
            // What the builder actually embeds (§14.3). The card is drawn in
            // Fraunces/Inter whatever the event's own faces are, so the two
            // are separate keys rather than a rewrite of `display`.
            'card_display' => SE_CARD_FONTS['display'],
            'card_body'    => SE_CARD_FONTS['body'],
        ],
        // Paths, not bytes: @se/core/svg.js cannot fetch, so portal/card.js
        // inlines both before the template is resolved (§14.2 step 2).
        'hero'   => $heroPath,
        'images' => ['logo' => se_card_logo_path($pdo, $event)],
        'filename' => se_card_filename($event, $kind, (string) $registration['first_name']),
        'privacy'  => 'Your photo stays on your phone.',
    ];
}

/**
 * The §14.4 `se__fill__<role>` roles, taken from the event's derived palette.
 * `primary` is the RAW brand colour, not the contrast-raised token: a card is
 * a poster, and the brand colour is the point of it.
 */
function se_card_colors(array $theme): array
{
    $t = (array) ($theme['tokens'] ?? []);

    return [
        'primary'   => (string) ($t['--se-primary-raw'] ?? '#1D356A'),
        'secondary' => (string) ($t['--se-secondary-raw'] ?? '#D11920'),
        'accent'    => (string) ($t['--se-accent'] ?? '#F5C518'),
        'bg'        => (string) ($t['--se-bg'] ?? '#0B0D13'),
        'surface'   => (string) ($t['--se-surface'] ?? '#151822'),
        'text'      => (string) ($t['--se-text'] ?? '#FFFFFF'),
        'glow'      => (string) ($t['--se-glow'] ?? '#D11920'),
        'on_primary' => (string) ($t['--se-on-primary'] ?? '#FFFFFF'),
    ];
}

/** The URL printed on the card: short, typeable, no query string. */
function se_card_short_url(array $event): string
{
    $url = se_event_url((string) $event['slug']);

    return preg_replace('#^https?://#', '', $url) ?? $url;
}

/**
 * The hero variant a personal card should draw (§3.3).
 *
 * The card only ever uses the hero as a ~96 px atmosphere thumbnail, so the
 * largest variant at or under 480 px is the right one: it arrives as a 2–4 KB
 * data URI, it is already on disk, and there is nothing left to downscale on
 * a cheap phone. Falls back to the smallest variant we do have, then to the
 * original, when an upload predates the resize pass.
 */
function se_card_hero_path(array $asset): ?string
{
    $path     = (string) ($asset['path'] ?? '');
    $variants = se_json_decode($asset['variants_json'] ?? null);
    if ($path === '') {
        return null;
    }
    $dir      = dirname($path);
    $best     = null;
    $smallest = null;

    foreach ($variants as $variant) {
        if (!isset($variant['file'], $variant['width'])) {
            continue;
        }
        $width = (int) $variant['width'];
        if ($width < 1) {
            continue;
        }
        if ($width <= 480 && ($best === null || $width > $best['width'])) {
            $best = ['file' => (string) $variant['file'], 'width' => $width];
        }
        if ($smallest === null || $width < $smallest['width']) {
            $smallest = ['file' => (string) $variant['file'], 'width' => $width];
        }
    }

    $pick = $best ?? $smallest;

    return $pick !== null ? $dir . '/' . $pick['file'] : $path;
}

/**
 * The crest the card is signed with (§3.5).
 *
 * The artwork carries two `se__image__logo` elements — the masthead and the
 * 5 % ghost bleeding off a corner — and the engine REMOVES an image token it
 * has no source for, so a payload without one ships an unsigned card. The
 * event's own Studio `logo` wins; the church crest is the fallback, exactly
 * as `church_logo` is on the programme poster.
 *
 * A path, not bytes: the builder fetches it same-origin and inlines it,
 * because an SVG drawn to a canvas can fetch nothing (§14.2 step 2).
 */
function se_card_logo_path(PDO $pdo, array $event): string
{
    $id = isset($event['logo_asset_id']) ? (int) $event['logo_asset_id'] : 0;
    if ($id > 0) {
        $asset = se_asset_find($pdo, $id);
        if ($asset !== null && trim((string) ($asset['path'] ?? '')) !== '') {
            return (string) $asset['path'];
        }
    }

    return SE_CARD_DEFAULT_LOGO;
}

/** `<slug>-<edition>-<card>-<firstname>.png`, slugified (§14.3). */
function se_card_filename(array $event, string $kind, string $firstName): string
{
    $parts = [
        (string) $event['slug'],
        (string) ($event['edition_label'] ?? ''),
        str_replace('_', '-', $kind),
        $firstName,
    ];

    $slug = strtolower(implode('-', array_filter(array_map('trim', $parts), static fn($p) => $p !== '')));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

    return trim($slug, '-') . '.png';
}

// --------------------------------------------------------------------------
// Check-in posters (§14.2)
// --------------------------------------------------------------------------

/**
 * The check-in poster sizes (§14.2): two printable, at 300 dpi, plus a 16:9
 * one for a lobby TV or a projector. `mm` is only set for the printable
 * ones — se_poster_payload() and the print PDF endpoint both key off its
 * presence to decide whether "Print PDF" makes sense at all.
 */
const SE_POSTER_SIZES = [
    'poster_a4'     => ['w' => 2480, 'h' => 3508, 'label' => 'A4',     'mm' => [210, 297]],
    'poster_a3'     => ['w' => 3508, 'h' => 4961, 'label' => 'A3',     'mm' => [297, 420]],
    'poster_screen' => ['w' => 1920, 'h' => 1080, 'label' => 'Screen'],
];

/**
 * Everything the check-in poster templates need (§14.2).
 *
 * The poster is rendered in the Studio, in the browser, by the same template
 * engine the share cards use — so this returns template URLs and token data,
 * never pixels. The QR points at /e/<slug>/in, which is the only address a
 * guest ever has to type.
 */
function se_poster_payload(PDO $pdo, array $event): array
{
    $days  = se_event_days($pdo, (int) $event['id']);
    $first = $days[0] ?? null;
    $start = se_parse_datetime($first['starts_at'] ?? ($event['starts_at'] ?? null));
    $doors = se_parse_datetime($first['doors_open_at'] ?? null);
    $theme = se_event_theme($event);

    $checkinUrl = se_event_url((string) $event['slug'], 'in');

    $sizes = [];
    foreach (SE_POSTER_SIZES as $kind => $spec) {
        $sizes[$kind] = [
            'width'     => $spec['w'],
            'height'    => $spec['h'],
            'label'     => $spec['label'],
            'template'  => '/assets/se/templates/qr_' . $kind . '.svg',
            'filename'  => se_card_filename($event, 'checkin-' . strtolower($spec['label']), ''),
            // Only the paper sizes have a physical dimension to print at;
            // the Studio uses this to decide whether to offer "Print PDF".
            'printable' => isset($spec['mm']),
        ];
    }

    return [
        'sizes' => $sizes,
        'text'  => [
            'organizer' => (string) ($event['organizer_label'] ?? 'Envision'),
            'title'     => (string) $event['title'],
            'edition'   => (string) ($event['edition_label'] ?? ''),
            'headline'  => 'Check in here',
            'sub'       => 'Scan with your phone camera',
            'date'      => $start !== null ? $start->format('D j M Y') : '',
            'time'      => $doors !== null
                ? 'Doors ' . ltrim($doors->format('g:i A'), '0')
                : ($start !== null ? ltrim($start->format('g:i A'), '0') : ''),
            'venue'     => (string) ($event['venue_name'] ?? ''),
            'url'       => preg_replace('#^https?://#', '', $checkinUrl) ?? $checkinUrl,
            'help'      => 'No phone? The desk will check you in.',
            'signature' => trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))
                . ' by ' . (string) ($event['organizer_label'] ?? 'Envision'),
        ],
        'qr'     => ['checkin_url' => $checkinUrl],
        'flags'  => ['has_venue' => ((string) ($event['venue_name'] ?? '')) !== ''],
        'colors' => se_card_colors($theme),
        'fonts'  => [
            'display' => (string) $event['font_display'],
            'body'    => (string) $event['font_body'],
        ],
    ];
}

/**
 * The two cards that only exist once someone is in the room (§14.3).
 *
 * `welcome` is the verse they were given at check-in — the same one, not a
 * fresh draw, because they have already read it on their phone and a card
 * that said something different would feel like a trick.
 *
 * `team` is their colour, their captain and their player number.
 *
 * @throws SeRuleException NOT_CHECKED_IN
 */
function se_card_night_payload(
    PDO $pdo,
    array $event,
    array $settings,
    string $kind,
    array $registration,
    array $theme,
    string $signature
): array {
    $eventId = (int) $event['id'];
    $colors  = se_card_colors($theme);

    $days      = se_event_days($pdo, $eventId);
    $phaseInfo = se_event_phase($event, $days);
    $checkin = null;
    if (se_checkin_ready($pdo)) {
        if ($kind === 'my_night') {
            $stmt = $pdo->prepare("SELECT * FROM se_checkins WHERE event_id=? AND registration_id=? ORDER BY checked_in_at DESC LIMIT 1");
            $stmt->execute([$eventId, (int) $registration['id']]);
            $checkin = $stmt->fetch() ?: null;
        } else {
            $checkin = se_checkin_row($pdo, $eventId, (int) $registration['id'], se_checkin_day($phaseInfo));
        }
    }

    if ($checkin === null) {
        throw new SeRuleException('NOT_CHECKED_IN', 'This card is ready once you have checked in.');
    }

    $firstName = (string) $registration['first_name'];

    $team = !empty($registration['team_id']) && se_teams_ready($pdo)
        ? se_team_find($pdo, $eventId, (int) $registration['team_id'])
        : null;
    $teamPublic = $team ? se_team_public($team, $theme) : null;

    $text = [
        'title'      => (string) $event['title'],
        'edition'    => (string) ($event['edition_label'] ?? ''),
        'organizer'  => (string) ($event['organizer_label'] ?? 'Envision'),
        'first_name' => $firstName,
        'url'        => se_card_short_url($event),
        'signature'  => $signature,
        'team'       => $teamPublic ? ($teamPublic['name'] ?? ('Team ' . $teamPublic['label'])) : '',
    ];

    if ($kind === 'my_night') {
        $finale = function_exists('se_finale_payload') ? se_finale_payload($pdo, $event) : [];
        $standing = null;
        foreach (($finale['teams'] ?? []) as $row) {
            if ((int) ($row['id'] ?? 0) === (int) ($registration['team_id'] ?? 0)) {
                $standing = $row;
                break;
            }
        }
        $songStmt = $pdo->prepare("SELECT s.title FROM se_karaoke_entries k JOIN se_songs s ON s.id=k.song_id WHERE k.event_id=? AND k.registration_id=? AND k.status='done' ORDER BY k.finished_at DESC LIMIT 1");
        $songStmt->execute([$eventId, (int) $registration['id']]);
        $song = $songStmt->fetchColumn() ?: null;
        $text += [
            'headline' => 'My Night',
            'rank' => $standing ? '#' . (int) ($standing['rank'] ?? 0) : '',
            'points' => $standing ? (string) (int) ($standing['score'] ?? 0) : '0',
            'song' => $song ? (string) $song : '',
            'mvp' => (int) (($finale['mvp']['registration_id'] ?? 0)) === (int) $registration['id'] ? 'MVP' : '',
        ];
    } elseif ($kind === 'welcome') {
        $verse = !empty($checkin['verse_id']) && se_verses_ready($pdo)
            ? se_verse_for_card(se_verse_find($pdo, $eventId, (int) $checkin['verse_id']), $firstName)
            : null;

        $text += [
            'headline'  => 'Welcome,',
            'verse_ref' => $verse['ref'] ?? '',
            'verse'     => $verse['text'] ?? '',
            'prayer'    => $verse['prayer'] ?? '',
        ];
    } else {
        $captain = null;
        if ($team && !empty($team['captain_registration_id'])) {
            $captainReg = se_registration_by_id($pdo, (int) $team['captain_registration_id'], $eventId);
            $captain = $captainReg ? (string) $captainReg['display_name'] : null;
        }

        $size = 0;
        if ($teamPublic) {
            $counts = se_team_counts($pdo, $eventId);
            $size   = (int) ($counts[$teamPublic['id']]['n'] ?? 0);
        }

        $text += [
            'headline'  => "I'm playing for",
            'captain'   => $captain ?? 'To be announced',
            'player_no' => $registration['player_no'] !== null ? (string) (int) $registration['player_no'] : '—',
            'count'     => $size > 0 ? $size . ' of us tonight' : '',
        ];
    }

    // The team colour overrides the brand accent on these two cards: on the
    // night, the colour people identify with is their team's, not Envision's.
    if ($teamPublic) {
        $colors['team'] = $teamPublic['hex'];
        $colors['on_team'] = $teamPublic['on'];
    }

    return [
        'kind'  => $kind,
        'sizes' => SE_CARD_SIZES,
        'templates' => [
            'story'  => se_card_template_url($kind, 'story'),
            'square' => se_card_template_url($kind, 'square'),
        ],
        'text'   => $text,
        'qr'     => [],
        'flags'  => ['has_team' => $teamPublic !== null],
        'colors' => $colors,
        'fonts'  => [
            'display' => (string) $event['font_display'],
            'body'    => (string) $event['font_body'],
        ],
        'filename' => se_card_filename($event, $kind, $firstName),
        'privacy'  => 'Nothing here leaves your phone until you share it.',
    ];
}

// --------------------------------------------------------------------------
// Programme poster (§14.2b)
// --------------------------------------------------------------------------

/**
 * The two shapes a programme poster is ever wanted in: one to print and one
 * to put on a screen. Both are drawn in the browser at these pixel sizes.
 */
const SE_PROGRAM_POSTER_SIZES = [
    'a4' => [
        'w' => 2480, 'h' => 3508,
        'label' => 'A4 poster', 'hint' => '210 × 297 mm at 300 dpi',
    ],
    'screen' => [
        'w' => 1920, 'h' => 1080,
        'label' => '16:9 screen', 'hint' => 'Projector, lobby TV or a slide',
    ],
];

/** A human date for one programme day: "Saturday 8 November". */
function se_program_poster_day_label(array $day): string
{
    $when = se_parse_datetime((string) ($day['day_date'] ?? ''));
    if ($when === null) {
        return (string) ($day['label'] ?? '');
    }

    $date = $when->format('l j F');
    $name = trim((string) ($day['label'] ?? ''));

    return $name !== '' ? $name . ' · ' . $date : $date;
}

/**
 * The line under the times, which has to match what the page promises.
 * Exact times get no note at all — the times are the note.
 */
function se_program_poster_note(string $timeMode): string
{
    return match ($timeMode) {
        'order_only' => 'In this order on the night.',
        'exact'      => '',
        default      => 'Times are approximate — the night runs on joy, not a stopwatch.',
    };
}

/**
 * Everything the programme poster needs, as data (§14.2b).
 *
 * Like every other render in this module the pixels are drawn in the browser
 * — @se/studio/program_poster.js lays the list out and rasterises it — so
 * this returns the public programme, the palette, the fonts and the hero
 * image's URL, and never an image.
 *
 * The publish gate is deliberately NOT applied: a producer prints the run of
 * show for the crew long before the page is allowed to show it. `published`
 * travels with the payload so the Studio can say which is which.
 */
function se_program_poster_payload(PDO $pdo, array $event, ?array $settings = null): array
{
    $settings ??= se_event_settings($event);
    $days      = se_event_days($pdo, (int) $event['id']);
    $theme     = se_event_theme($event);

    $program = ['days' => [], 'time_mode' => 'approximate'];
    if (function_exists('se_program_ready') && se_program_ready($pdo)) {
        try {
            $program = se_program_public($pdo, $event, $days, $settings);
        } catch (Throwable $e) {
            error_log('SE poster/program: ' . $e->getMessage());
        }
    }

    // The day rows carry the times; the public programme carries the items.
    $byId = [];
    foreach ($days as $day) {
        $byId[(int) $day['id']] = $day;
    }

    $outDays = [];
    foreach ($program['days'] as $block) {
        $day   = $byId[(int) $block['day_id']] ?? ['day_date' => $block['day_date'], 'label' => $block['label']];
        $doors = se_parse_datetime($day['doors_open_at'] ?? null);
        $start = se_parse_datetime($day['starts_at'] ?? null);

        $outDays[] = [
            'day_id' => (int) $block['day_id'],
            'date'   => se_program_poster_day_label($day + ['label' => $block['label'] ?? null]),
            'doors'  => $doors !== null ? 'Doors ' . ltrim($doors->format('g:i A'), '0') : '',
            'start'  => $start !== null ? ltrim($start->format('g:i A'), '0') : '',
            'items'  => array_map(static fn(array $item): array => [
                'time'     => (string) ($item['time'] ?? ''),
                'title'    => (string) $item['title'],
                'blurb'    => (string) ($item['blurb'] ?? ''),
                'host'     => (string) ($item['host'] ?? ''),
                'featured' => (bool) $item['featured'],
            ], $block['items']),
        ];
    }

    $heroId = isset($event['hero_asset_id']) ? (int) $event['hero_asset_id'] : 0;
    $hero   = $heroId > 0 ? se_asset_find($pdo, $heroId) : null;

    $sizes = [];
    foreach (SE_PROGRAM_POSTER_SIZES as $key => $spec) {
        $sizes[$key] = [
            'width'  => $spec['w'],
            'height' => $spec['h'],
            'label'  => $spec['label'],
            'hint'   => $spec['hint'],
        ];
    }

    $timeMode = (string) ($program['time_mode'] ?? 'approximate');

    return [
        'published'  => se_bool($settings['program']['published'] ?? false),
        'sizes'      => $sizes,
        'days'       => $outDays,
        'time_mode'  => $timeMode,
        'note'       => se_program_poster_note($timeMode),
        'text'       => [
            'organizer' => (string) ($event['organizer_label'] ?? 'Envision'),
            'title'     => (string) $event['title'],
            'edition'   => (string) ($event['edition_label'] ?? ''),
            'tagline'   => (string) ($event['tagline'] ?? ''),
            'venue'     => (string) ($event['venue_name'] ?? ''),
            'url'       => se_card_short_url($event),
            'heading'   => 'The programme',
        ],
        'qr'         => se_event_url((string) $event['slug']),
        'hero'       => $hero !== null ? (string) $hero['path'] : null,
        'church_logo' => '/assets/images/hod_logo.svg',
        'colors'     => se_card_colors($theme),
        'fonts'      => [
            'display' => (string) $event['font_display'],
            'body'    => (string) $event['font_body'],
        ],
        'filename'   => str_replace('.png', '', se_card_filename($event, 'programme', '')),
    ];
}
