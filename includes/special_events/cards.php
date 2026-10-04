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

/** Templates PR2 ships. Later PRs add welcome, team and my_night. */
const SE_CARD_KINDS_READY = ['im_going'];

/** The sizes a card may be rendered at (§14.3). */
const SE_CARD_SIZES = [
    'story'  => ['w' => 1080, 'h' => 1920],
    'square' => ['w' => 1080, 'h' => 1080],
];

/** Template file for a card kind and size, relative to the web root. */
function se_card_template_url(string $kind, string $size = 'story'): string
{
    $name = $size === 'square' ? $kind . '_square' : $kind;

    return '/assets/se/templates/' . $name . '.svg';
}

/**
 * Data for one personal card.
 *
 * @throws SeRuleException FEATURE_DISABLED | NOT_CHECKED_IN
 */
function se_card_payload(PDO $pdo, array $event, array $days, array $settings, string $kind, array $registration): array
{
    if (!in_array($kind, SE_CARD_KINDS_READY, true)) {
        throw new SeRuleException('FEATURE_DISABLED', 'That card is not available yet.');
    }
    if (!se_bool($settings['share_cards'][$kind] ?? true)) {
        throw new SeRuleException('FEATURE_DISABLED', 'That card is switched off for this event.');
    }

    $first = $days[0] ?? null;
    $start = se_parse_datetime($first['starts_at'] ?? ($event['starts_at'] ?? null));
    $theme = se_event_theme($event);

    $refUrl = se_event_url((string) $event['slug']) . '?r=' . rawurlencode((string) $registration['ref_code']);

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
            'venue'      => (string) ($event['venue_name'] ?? ''),
            'url'        => se_card_short_url($event),
            'organizer'  => (string) ($event['organizer_label'] ?? 'Envision'),
            'signature'  => trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))
                . ' by ' . (string) ($event['organizer_label'] ?? 'Envision'),
        ],
        'qr'     => ['ref_url' => $refUrl, 'portal_url' => se_event_url((string) $event['slug'])],
        'flags'  => ['has_photo' => false, 'multi_day' => count($days) > 1],
        'colors' => se_card_colors($theme),
        'fonts' => [
            'display' => (string) $event['font_display'],
            'body'    => (string) $event['font_body'],
        ],
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
