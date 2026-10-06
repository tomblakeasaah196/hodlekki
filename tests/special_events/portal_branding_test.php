<?php
// /tests/special_events/portal_branding_test.php — the public registration
// page must visibly render the brand choices the Studio saves.

require_once __DIR__ . '/../../includes/special_events/portal.php';

$event = [
    'slug'            => 'chara',
    'title'           => 'Chara Night',
    'edition_label'   => '2026',
    'tagline'         => 'Karaoke, games and warm joy for everyone.',
    'organizer_label' => 'Envision',
    'starts_at'       => '2026-10-31 15:30:00',
    'venue_name'      => 'Hebron, Kon-X Place',
    'venue_map_url'   => null,
];
$days = [[
    'starts_at' => '2026-10-31 15:30:00',
]];
$settings = [
    'portal'       => ['show_countdown' => true, 'hero_video_enabled' => true],
    'registration' => ['min_age_note' => 'For ages 16 and above.'],
];
$phase = ['phase' => 'upcoming'];
$hero = [
    'path'          => '/uploads/se/CHARA/hero/crowd.webp',
    'alt_text'      => 'Young adults enjoying Chara Night together.',
    'width'         => 1280,
    'height'        => 720,
    'variants_json' => se_json_encode([
        ['file' => 'crowd-480.webp', 'width' => 480],
        ['file' => 'crowd-960.webp', 'width' => 960],
    ]),
];

ob_start();
se_portal_hero($event, $days, $settings, $phase, 'open', null, $hero, null, null);
$heroHtml = (string) ob_get_clean();

ok('the public hero emits the selected image',
    str_contains($heroHtml, 'src="/uploads/se/CHARA/hero/crowd.webp"'));
ok('the public hero uses responsive image variants',
    str_contains($heroHtml, 'crowd-480.webp 480w') && str_contains($heroHtml, 'sizes="100vw"'));
ok('the public hero preserves the uploaded alt text',
    str_contains($heroHtml, 'alt="Young adults enjoying Chara Night together."'));
ok('the selected hero is prioritised for first paint',
    str_contains($heroHtml, 'fetchpriority="high"'));
ok('the image activates the media blending treatment',
    str_contains($heroHtml, 'data-has-media="1"') && str_contains($heroHtml, 'se-hero-shade'));
ok('the calendar pill contains the date without duplicating the time',
    str_contains($heroHtml, '>Sat 31 Oct</time>')
    && !str_contains($heroHtml, 'Sat 31 Oct, 3:30 PM'));
is_same('the clock pill contains the time exactly once', 1, substr_count($heroHtml, '3:30 PM'));
is_same('the date-only formatter omits the time', 'Sat 31 Oct',
    se_format_date(new DateTimeImmutable('2026-10-31 15:30:00')));

ob_start();
se_portal_hero($event, $days, $settings, $phase, 'open', null, null, null, null);
$plainHeroHtml = (string) ob_get_clean();
ok('the gradient-only fallback remains available without an image',
    str_contains($plainHeroHtml, 'data-has-media="0"') && !str_contains($plainHeroHtml, 'se-hero-image'));

ob_start();
se_portal_topbar($event, 'Envision');
se_portal_footer($event, 'Envision');
$brandHtml = (string) ob_get_clean();

is_same('the canonical church logo is shown in the hero and footer',
    2, substr_count($brandHtml, 'src="/assets/images/hod_logo.svg"'));
ok('the top logo links to the church home page',
    str_contains($brandHtml, 'class="se-church-lockup" href="/"'));
ok('the footer logo has an accessible church name',
    str_contains($brandHtml, 'alt="Household of David Lekki Centre"'));
ok('the topbar uses accessible share and menu icons instead of visible labels',
    str_contains($brandHtml, 'aria-label="Share this event"')
    && str_contains($brandHtml, 'aria-label="Open page menu"')
    && substr_count($brandHtml, 'class="se-topbar-icon"') === 2
    && !str_contains($brandHtml, '>Share</button>')
    && !str_contains($brandHtml, '>Menu</summary>'));

$css = (string) file_get_contents(__DIR__ . '/../../assets/se/css/se.input.css');
ok('the hero image has a full-bleed CSS treatment',
    str_contains($css, '.se-hero-image') && str_contains($css, 'object-fit: cover'));
ok('the selected image keeps the animated mesh blend',
    str_contains($css, '.se-hero-bg[data-has-media="1"] .se-hero-mesh')
    && str_contains($css, 'mix-blend-mode: screen'));
