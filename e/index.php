<?php
// /e/index.php
//
// The public router and HTML shell for every Special Events surface
// (guide §8.8, §19.7). e/.htaccess rewrites /e/<slug>/<path> to here.
//
// PR1 delivered: slug resolution with lowercase and alias redirects, the
// draft gate, the security headers with a CSP nonce, the theme <style nonce>,
// the boot JSON, a server-rendered first-paint hero, a branded 404, the
// privacy notice, calendar.ics and the hub at /e/.
//
// PR2 adds the Marquee portal (§13.3) and the manage page (§13.5), both
// server-rendered first and enhanced by @se/portal/main.js. Check-in, games
// and the crew consoles still resolve here and render the PR1 placeholder, so
// a printed QR code made today keeps working.

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/special_events/bootstrap.php';

// --------------------------------------------------------------------------
// 1. Input
// --------------------------------------------------------------------------

$rawSlug = (string) ($_GET['__slug'] ?? '');
$rawPath = (string) ($_GET['__path'] ?? '');

// Normalise the path: no leading/trailing slash, no traversal, bounded.
$path = trim($rawPath, '/');
$path = preg_replace('#/{2,}#', '/', $path) ?? '';
if (str_contains($path, '..') || strlen($path) > 200) {
    $path = '__invalid__';
}

// --------------------------------------------------------------------------
// 2. The hub, /e/
// --------------------------------------------------------------------------

if ($rawSlug === '') {
    se_render_hub($pdo);
    exit;
}

// --------------------------------------------------------------------------
// 3. Slug: lowercase redirect, then lookup, then alias redirect (§8.8)
// --------------------------------------------------------------------------

$slug = strtolower($rawSlug);

if ($slug !== $rawSlug) {
    // Someone typed /e/CHARA (or a poster was printed in caps): 301 so the
    // canonical URL is the only one search engines and analytics ever see.
    se_redirect_permanent(se_event_url($slug, $path, false));
}

if (!preg_match(SE_SLUG_PATTERN, $slug)) {
    se_render_404($pdo, 'That link does not look right.');
    exit;
}

$resolved = se_event_find_by_slug($pdo, $slug);
if ($resolved === null) {
    se_render_404($pdo, 'We could not find that event.');
    exit;
}

if ($resolved['is_alias']) {
    // An old slug kept as an alias so printed QR codes never break (§10.2).
    se_redirect_permanent(se_event_url($resolved['canonical'], $path, false));
}

$event    = $resolved['event'];
$eventId  = (int) $event['id'];
$days     = se_event_days($pdo, $eventId);
$settings = se_event_settings($event);
$phase    = se_event_phase($event, $days);

// --------------------------------------------------------------------------
// 4. Draft gate (§8.8 step 3, §19.2)
// --------------------------------------------------------------------------
//
// A draft returns 404, not 403, so drafts cannot be enumerated. Crew and a
// correct ?preview= key see it.

$isPreview = false;
if ((string) $event['status'] === 'draft') {
    $previewKey = (string) ($_GET['preview'] ?? '');
    $expected   = (string) $event['preview_key'];

    if ($previewKey !== '' && $expected !== '' && hash_equals($expected, $previewKey)) {
        $isPreview = true;
    } elseif (!empty($_SESSION['user_id']) && se_has_capability($pdo, $eventId, 'insights.view', (int) $_SESSION['user_id'])) {
        $isPreview = true;
    }

    if (!$isPreview) {
        se_render_404($pdo, 'We could not find that event.');
        exit;
    }
}

// --------------------------------------------------------------------------
// 5. Routing (§8.8 step 4)
// --------------------------------------------------------------------------

$surface = 'portal';
$view    = 'home';
$token   = null;

if ($path === '') {
    $view = 'home';
} elseif ($path === 'in') {
    $view = 'checkin';
} elseif ($path === 'play') {
    $view = 'play';
} elseif ($path === 'privacy') {
    $view = 'privacy';
} elseif ($path === 'calendar.ics') {
    // Published events only: a draft has no public date to add.
    if ((string) $event['status'] !== 'published') {
        se_render_404($pdo, 'This event is not published yet.');
        exit;
    }
    se_send_calendar($event, $days, $settings);
    exit;
} elseif (preg_match('#^me/([A-Za-z0-9_-]{22})$#', $path, $m)) {
    $view  = 'manage';
    $token = $m[1];
} elseif ($path === 'stage' || $path === 'lobby') {
    $surface = $path;
    $view    = $path;
} elseif (in_array($path, ['host', 'desk', 'dj'], true)) {
    $surface = $path;
    $view    = $path;

    // Crew consoles need the ERP session. Bounce to login with a safe
    // return path (§21.4), and honour a forced password change (§19.2).
    if (empty($_SESSION['user_id'])) {
        $next = se_event_url($slug, $path, false);
        header('Location: /auth/login.php?next=' . rawurlencode($next));
        exit;
    }
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: /auth/change_password.php');
        exit;
    }
} else {
    se_render_404($pdo, 'That page is not part of this event.');
    exit;
}

// --------------------------------------------------------------------------
// 6. Render the shell (§8.8 step 5)
// --------------------------------------------------------------------------

se_render_shell($pdo, $event, $days, $settings, $phase, $surface, $view, $token, $isPreview);

// ==========================================================================
// Rendering
// ==========================================================================

/** A 301 that keeps the query string, for lowercase and alias redirects. */
function se_redirect_permanent(string $location): never
{
    $query = $_GET;
    unset($query['__slug'], $query['__path']);
    if ($query) {
        $location .= (str_contains($location, '?') ? '&' : '?') . http_build_query($query);
    }

    header('Location: ' . $location, true, 301);
    header('Cache-Control: no-store');
    exit;
}

/**
 * The page shell: meta, CSP, fonts, the theme variables, the import map, the
 * boot JSON, the server-rendered hero and the entry module.
 */
function se_render_shell(
    PDO $pdo, array $event, array $days, array $settings, array $phase,
    string $surface, string $view, ?string $token, bool $isPreview
): void {
    $nonce = se_csp_nonce();
    se_send_page_headers($nonce);

    $theme     = se_event_theme($event);
    $slug      = (string) $event['slug'];
    $title     = (string) $event['title'];
    $edition   = (string) ($event['edition_label'] ?? '');
    $tagline   = (string) ($event['tagline'] ?? '');
    $organizer = (string) ($event['organizer_label'] ?? 'Envision');

    $fullTitle = trim($title . ' ' . $edition);
    $pageTitle = match ($view) {
        'checkin' => 'Check in — ' . $fullTitle,
        'play'    => 'Games — ' . $fullTitle,
        'privacy' => 'Privacy — ' . $fullTitle,
        'manage'  => 'Your place at ' . $fullTitle,
        'stage', 'lobby', 'host', 'desk', 'dj' => ucfirst($view) . ' — ' . $fullTitle,
        default   => $fullTitle . ($tagline !== '' ? ' — ' . $tagline : ''),
    };

    $description = $tagline !== ''
        ? $tagline
        : (($event['description_md'] ?? '') !== ''
            ? se_markdown_excerpt((string) $event['description_md'], 160)
            : $fullTitle . ' — by ' . $organizer . '.');

    $canonical = se_event_url($slug, $view === 'home' ? '' : $view);

    // Only a published, public portal is indexable (§8.8 step 5).
    $indexable = (string) $event['status'] === 'published'
        && (string) $event['visibility'] === 'public'
        && $view === 'home';

    $ogAsset   = se_shell_asset($pdo, $event, 'og');
    $heroAsset = se_shell_asset($pdo, $event, 'hero');
    $ogImage   = $ogAsset['path'] ?? $heroAsset['path'] ?? null;

    $boot = se_boot_payload($pdo, $event, $days, $settings, $phase, $surface, $view, $token, $isPreview, $theme);

    $preload = SE_PRELOAD[$surface] ?? SE_PRELOAD['portal'];
    $entry   = '/assets/se/js/' . ($surface === 'portal' ? 'portal' : $surface) . '/main.js';

    // Google Fonts: the two families the event chose (§13.1.2).
    $fontDisplay = rawurlencode(str_replace(' ', '+', (string) $event['font_display']));
    $fontBody    = rawurlencode(str_replace(' ', '+', (string) $event['font_body']));
    $fontsHref   = 'https://fonts.googleapis.com/css2'
        . '?family=' . $fontDisplay . ':wght@600;700;800'
        . '&family=' . $fontBody . ':wght@400;500;700'
        . '&display=swap';

    ?>
<!doctype html>
<html lang="en-NG" data-theme="<?= se_h($event['theme_preset']) ?>" data-surface="<?= se_h($surface) ?>" data-view="<?= se_h($view) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= se_h($pageTitle) ?></title>
<meta name="description" content="<?= se_h($description) ?>">
<link rel="canonical" href="<?= se_h($canonical) ?>">
<?php if (!$indexable): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="<?= se_h($theme['tokens']['--se-bg']) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= se_h($organizer) ?>">
<meta property="og:title" content="<?= se_h($fullTitle) ?>">
<meta property="og:description" content="<?= se_h($description) ?>">
<meta property="og:url" content="<?= se_h($canonical) ?>">
<?php if ($ogImage !== null): ?>
<meta property="og:image" content="<?= se_h(se_site_origin() . $ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<meta name="twitter:title" content="<?= se_h($fullTitle) ?>">
<meta name="twitter:description" content="<?= se_h($description) ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?= se_h($fontsHref) ?>">
<link rel="stylesheet" href="/assets/se/css/se.css">
<link rel="icon" href="/favicon.ico" sizes="any">

<?php foreach ($preload as $module): ?>
<link rel="modulepreload" href="<?= se_h($module) ?>">
<?php endforeach; ?>

<style nonce="<?= se_h($nonce) ?>">
<?= se_theme_css_vars($theme) ?>
:root {
  --se-font-display: "<?= se_h($event['font_display']) ?>";
  --se-font-body: "<?= se_h($event['font_body']) ?>";
}
</style>

<script type="importmap" nonce="<?= se_h($nonce) ?>">
<?= se_json_for_html(se_import_map()) ?>
</script>
</head>
<body>
<a class="se-skip" href="#se-main">Skip to content</a>

<?php if ($isPreview): ?>
<div class="se-container se-notice-top">
  <p class="se-glass se-small se-notice" role="status">
    <strong>Draft preview.</strong> Only the crew can see this page. Guests get a “not found” page until it is published.
  </p>
</div>
<?php endif; ?>

<?php if ((string) $event['status'] === 'cancelled'): ?>
<div class="se-container se-notice-top">
  <p class="se-glass se-small se-notice se-notice-danger" role="alert">
    <strong>This event has been cancelled.</strong>
    <?= se_h((string) ($event['cancel_reason'] ?? '')) ?>
  </p>
</div>
<?php endif; ?>

<main id="se-main">
<?php
    // The first paint is real HTML, so the LCP element is text and the page
    // is readable before (and without) JavaScript (§8.8 step 5, §13.15).
    if ($view === 'privacy') {
        se_render_privacy($pdo, $event, $settings);
    } elseif ($view === 'manage') {
        se_portal_manage($event, $days, $settings);
    } elseif ($view === 'checkin') {
        se_portal_checkin($event, $days, $settings, se_checkin_window($event, $days));
    } elseif (in_array($view, ['stage', 'lobby', 'host', 'desk', 'dj'], true)) {
        se_render_crew_frame($event, $view);
    } elseif ($view === 'home') {
        se_portal_render(
            $pdo, $event, $days, $settings, $phase,
            se_capacity_counts($pdo, (int) $event['id']),
            $heroAsset,
            se_shell_asset($pdo, $event, 'hero_video'),
            se_shell_registration($pdo, $event)
        );
    } else {
        se_render_hero($event, $days, $phase, $heroAsset, $view);
    }
?>
</main>

<noscript>
  <div class="se-container se-small se-section">
    <p class="se-glass se-pad">
      This page needs JavaScript for registration and the live parts of the
      night. The details above are complete, and you can always register at
      the door.
    </p>
  </div>
</noscript>

<div id="se-app" hidden></div>

<script type="application/json" id="se-boot"><?= se_json_for_html($boot) ?></script>

<script nonce="<?= se_h($nonce) ?>">
// es-module-shims is only for old iOS, which lacks import-map support. Modern
// browsers skip the 82 KB entirely (§8.6.1).
if (!(HTMLScriptElement.supports && HTMLScriptElement.supports('importmap'))) {
    var s = document.createElement('script');
    s.src = '/assets/se/vendor/es-module-shims-2.8.4/es-module-shims.js';
    s.async = true;
    document.head.appendChild(s);
}
</script>
<?php if (in_array($surface, ['portal', 'stage', 'lobby'], true)): ?>
<?php foreach (SE_GSAP_SCRIPTS as $gsapScript): ?>
<script src="<?= se_h($gsapScript) ?>" defer nonce="<?= se_h($nonce) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
<script type="module" src="<?= se_h($entry) ?>" nonce="<?= se_h($nonce) ?>"></script>
</body>
</html>
<?php
}

/**
 * The boot payload (§8.6.2).
 *
 * Public-safe by construction: it carries the event's own public content and
 * this viewer's own context, and never another attendee's details.
 */
function se_boot_payload(
    PDO $pdo, array $event, array $days, array $settings, array $phase,
    string $surface, string $view, ?string $token, bool $isPreview, array $theme
): array {
    $slug = (string) $event['slug'];

    $dayList = array_map(static fn(array $d): array => [
        'day_date'          => $d['day_date'],
        'label'             => $d['label'],
        'doors_open_at'     => se_iso($d['doors_open_at']),
        'starts_at'         => se_iso($d['starts_at']),
        'ends_at'           => se_iso($d['ends_at']),
        'checkin_closes_at' => se_iso($d['checkin_closes_at']),
    ], $days);

    $payload = [
        'event' => [
            'public_id'  => $event['public_id'],
            'slug'       => $slug,
            'title'      => $event['title'],
            'edition'    => $event['edition_label'],
            'tagline'    => $event['tagline'],
            'organizer'  => $event['organizer_label'],
            'status'     => $event['status'],
            'visibility' => $event['visibility'],
            'starts_at'  => se_iso($event['starts_at']),
            'ends_at'    => se_iso($event['ends_at']),
            'venue'      => [
                'name'    => $event['venue_name'],
                'address' => $event['venue_address'],
                'map_url' => $event['venue_map_url'],
                'notes'   => $event['venue_notes'],
            ],
            'cancel_reason' => $event['cancel_reason'],
        ],
        'phase'   => $phase['phase'],
        'days'    => $dayList,
        'surface' => $surface,
        'view'    => $view,
        'preview' => $isPreview,
        'theme'   => ['preset' => $event['theme_preset'], 'tokens' => $theme['tokens']],
        'fonts'   => ['display' => $event['font_display'], 'body' => $event['font_body']],
        'urls'    => [
            'portal'   => se_event_url($slug, '', false),
            'checkin'  => se_event_url($slug, 'in', false),
            'play'     => se_event_url($slug, 'play', false),
            'privacy'  => se_event_url($slug, 'privacy', false),
            'calendar' => se_event_url($slug, 'calendar.ics', false),
            'live'     => '/live/' . $event['public_id'] . '/',
        ],
        'texts'   => [
            'min_age_note' => se_settings_path($settings, 'registration.min_age_note', ''),
            'intro_line'   => se_settings_path($settings, 'portal.intro_line'),
            'faq'          => se_settings_path($settings, 'portal.faq', []),
            'consent'      => se_settings_path($settings, 'registration.consent_text', ''),
            'optin'        => se_settings_path($settings, 'registration.optin_text', ''),
        ],
        'flags'   => [
            'show_countdown'  => (bool) se_settings_path($settings, 'portal.show_countdown', true),
            'karaoke_enabled' => (bool) se_settings_path($settings, 'karaoke.enabled', true),
            'games_enabled'   => (bool) se_settings_path($settings, 'games.enabled', true),
            'hero_video'      => (bool) se_settings_path($settings, 'portal.hero_video_enabled', true),
            // Registration shipped in PR2. It still needs its table: code is
            // copied to production before migrations run (§9.4).
            'registration_ready' => se_table_exists($pdo, 'se_registrations'),
            'im_going_card'      => (bool) se_settings_path($settings, 'share_cards.im_going', true),
            'ask_wants_visit'    => (bool) se_settings_path($settings, 'registration.ask_wants_visit_after_submit', true),
            'link_on_demand'     => (bool) se_settings_path($settings, 'registration.link_on_demand_enabled', true),
            'self_cancel'        => (bool) $event['self_cancel_enabled'],
        ],
        'realtime' => [
            'driver'      => se_settings_path($settings, 'realtime.driver', 'poll'),
            'min_poll_ms' => (int) se_settings_path($settings, 'realtime.min_poll_ms', 1000),
        ],
        'server_ms' => se_epoch_ms(),
    ];

    // Registration (§13.4). Everything the sheet needs to build itself
    // without a round trip: which fields to ask for, the consent wording,
    // the custom questions and the live state of the seats.
    if ($surface === 'portal' && se_table_exists($pdo, 'se_registrations')) {
        $counts = se_capacity_counts($pdo, (int) $event['id']);
        $state  = se_registration_state($event, $phase, $counts);

        $payload['reg'] = [
            'state'      => $state,
            'message'    => se_registration_state_message($state, $event),
            'seats_left' => se_seats_left($event, $counts),
            'opens_at'   => se_iso($event['reg_opens_at']),
            'closes_at'  => se_iso($event['reg_closes_at'] ?? ($phase['first_day']['starts_at'] ?? null)),
        ];

        $payload['form'] = [
            'consent_mode' => se_settings_path($settings, 'registration.consent_mode', 'required_followup'),
            'fields'       => [
                'gender'    => se_settings_path($settings, 'registration.fields.gender', 'required'),
                'email'     => se_settings_path($settings, 'registration.fields.email', 'optional'),
                'how_heard' => se_settings_path($settings, 'registration.fields.how_heard', 'optional'),
                'karaoke'   => (bool) se_settings_path($settings, 'registration.fields.karaoke_interest', true),
            ],
            'how_heard'  => SE_HOW_HEARD,
            'questions'  => array_map('se_form_field_public', se_form_fields($pdo, (int) $event['id'])),
            'min_t_ms'   => SE_REGISTER_MIN_FILL_MS,
        ];

        // ?s=<source> and ?r=<ref_code> ride along with the submission (§18.2).
        $src = (string) ($_GET['s'] ?? '');
        $ref = (string) ($_GET['r'] ?? '');
        $payload['attribution'] = [
            'src' => preg_match('/^[A-Za-z0-9_-]{1,20}$/', $src) ? strtolower($src) : null,
            'ref' => preg_match('/^[0-9A-Za-z]{' . SE_REF_CODE_LENGTH . '}$/', $ref) ? strtoupper($ref) : null,
        ];
    }

    if ($token !== null) {
        // The manage token is this viewer's own secret, already in their URL.
        $payload['token'] = $token;
    }

    // Crew surfaces need the CSRF token; public pages never do (§19.3).
    if (in_array($surface, ['host', 'desk', 'dj', 'stage', 'lobby'], true) && !empty($_SESSION['user_id'])) {
        $payload['csrf'] = se_csrf_token();
    }

    // Check-in (§13.6): the window, so the page can count down to the doors
    // without a round trip, and the flag that keeps the form honest before
    // the migration lands (§9.4).
    if ($view === 'checkin') {
        $payload['checkin'] = se_checkin_window($event, $days);
        $payload['flags']['checkin_ready'] = se_table_exists($pdo, 'se_checkins');
        $payload['form'] = [
            'consent_mode' => se_settings_path($settings, 'registration.consent_mode', 'required_followup'),
        ];

        // If this phone already has a registration, the page can open with
        // "Check in as Ada O." instead of an empty number field (§13.6).
        $mine = se_shell_registration($pdo, $event);
        $payload['me'] = $mine
            ? ['registration' => ['display_name' => (string) $mine['display_name']]]
            : null;
    }

    // Displays authenticate with the key in their own URL (§12.3). It is a
    // secret the crew pasted in, so it never leaves this page.
    if (in_array($surface, ['stage', 'lobby'], true)) {
        $key = (string) ($_GET['k'] ?? '');
        $payload['display_key'] = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) ? $key : null;
    }

    return $payload;
}

/**
 * This device's own registration, for the first paint.
 *
 * The server already knows, from the device cookie, whether the person
 * looking at the page has a seat — so the hero can say "You're registered ✓"
 * in the first byte rather than flashing "Register" and correcting itself.
 */
function se_shell_registration(PDO $pdo, array $event): ?array
{
    if (!se_table_exists($pdo, 'se_devices') || !se_table_exists($pdo, 'se_registrations')) {
        return null;
    }

    try {
        $device = se_device_load($pdo, $event, se_device_cookie_value((string) $event['public_id']));
        if (!$device || $device['registration_id'] === null) {
            return null;
        }

        return se_registration_by_id($pdo, (int) $device['registration_id'], (int) $event['id']);
    } catch (Throwable $e) {
        error_log('SE shell/registration: ' . $e->getMessage());
        return null;
    }
}

/** An event's logo/hero/og asset row, or null. */
function se_shell_asset(PDO $pdo, array $event, string $which): ?array
{
    $column = match ($which) {
        'logo'       => 'logo_asset_id',
        'hero'       => 'hero_asset_id',
        'hero_video' => 'hero_video_asset_id',
        'og'         => 'og_asset_id',
        default      => null,
    };
    if ($column === null || empty($event[$column])) {
        return null;
    }

    $asset = se_asset_find($pdo, (int) $event[$column]);

    return ($asset && $asset['deleted_at'] === null) ? $asset : null;
}

/**
 * The server-rendered first-paint hero. Real HTML: the title is the LCP
 * element and the page works with JavaScript disabled (§13.15).
 */
function se_render_hero(array $event, array $days, array $phase, ?array $heroAsset, string $view): void
{
    $title     = (string) $event['title'];
    $edition   = (string) ($event['edition_label'] ?? '');
    $tagline   = (string) ($event['tagline'] ?? '');
    $organizer = (string) ($event['organizer_label'] ?? 'Envision');
    $slug      = (string) $event['slug'];

    $firstDay  = $days[0] ?? null;
    $startsAt  = $firstDay ? se_parse_datetime($firstDay['starts_at']) : se_parse_datetime($event['starts_at']);
    $multiDay  = count($days) > 1;

    // The phase decides the call to action (§10.1 portal table). Registration
    // itself is PR2, so `upcoming` links to the hub rather than a dead form.
    [$ctaLabel, $ctaHref, $ctaNote] = match ($phase['phase']) {
        'upcoming' => ['Registration opens soon', null, 'We are putting the finishing touches to this page.'],
        'live'     => (($phase['checkin_open'] ?? false)
            ? ['Check in', se_event_url($slug, 'in', false), null]
            : ['Join the games', se_event_url($slug, 'play', false), 'Check-in has closed — please see the desk.']),
        'between_days' => ['See you tomorrow', null, null],
        'post'     => ['Relive the night', null, 'The recap is on its way.'],
        'cancelled' => ['This event has been cancelled', null, null],
        'archived' => ['This event has ended', null, null],
        default    => ['Registration opens soon', null, null],
    };
    ?>
<section class="se-section" aria-labelledby="se-hero-title">
  <div class="se-container">

    <p class="se-label"><?= se_h(mb_strtoupper(trim($title . ' ' . $edition), 'UTF-8')) ?> · BY <?= se_h(mb_strtoupper($organizer, 'UTF-8')) ?></p>

    <h1 id="se-hero-title" class="se-display-lg se-hero-title">
      <?= se_h($title) ?><?php if ($edition !== ''): ?> <span class="se-accent-text"><?= se_h($edition) ?></span><?php endif; ?>
    </h1>

    <?php if ($tagline !== ''): ?>
    <p class="se-h2 se-muted se-measure-hero"><?= se_h($tagline) ?></p>
    <?php endif; ?>

    <dl class="se-hero-meta">
      <?php if ($startsAt !== null): ?>
      <div>
        <dt class="se-label">When</dt>
        <dd>
          <time datetime="<?= se_h($startsAt->format('c')) ?>"><?= se_h(se_format_day($startsAt)) ?></time>
          <?php if ($multiDay): ?><br><span class="se-small se-muted"><?= count($days) ?> days</span><?php endif; ?>
        </dd>
      </div>
      <?php endif; ?>

      <?php if (!empty($event['venue_name'])): ?>
      <div>
        <dt class="se-label">Where</dt>
        <dd>
          <?= se_h($event['venue_name']) ?>
          <?php if (!empty($event['venue_address'])): ?>
          <br><span class="se-small se-muted"><?= se_h($event['venue_address']) ?></span>
          <?php endif; ?>
        </dd>
      </div>
      <?php endif; ?>
    </dl>

    <p>
      <?php if ($ctaHref !== null): ?>
      <a class="se-btn se-btn-primary" href="<?= se_h($ctaHref) ?>"><?= se_h($ctaLabel) ?></a>
      <?php else: ?>
      <span class="se-btn se-btn-ghost" aria-disabled="true"><?= se_h($ctaLabel) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($ctaNote !== null): ?>
    <p class="se-small se-muted"><?= se_h($ctaNote) ?></p>
    <?php endif; ?>

    <?php if ($heroAsset !== null): ?>
    <figure class="se-hero-figure">
      <img src="<?= se_h($heroAsset['path']) ?>"
           <?php if (se_asset_srcset($heroAsset) !== ''): ?>srcset="<?= se_h(se_asset_srcset($heroAsset)) ?>" sizes="100vw"<?php endif; ?>
           <?php if (!empty($heroAsset['width'])): ?>width="<?= (int) $heroAsset['width'] ?>" height="<?= (int) $heroAsset['height'] ?>"<?php endif; ?>
           alt="<?= se_h((string) ($heroAsset['alt_text'] ?? '')) ?>"
           decoding="async">
    </figure>
    <?php endif; ?>

    <?php if (!empty($event['description_md'])): ?>
    <div class="se-md se-hero-body se-measure">
      <?= se_markdown((string) $event['description_md']) ?>
    </div>
    <?php endif; ?>

    <?php if ($view !== 'home'): ?>
    <p class="se-glass se-small se-pad se-hero-body" role="status">
      This part of the night is not open yet. Keep this link — it will work on the day.
    </p>
    <?php endif; ?>

    <p class="se-small se-hero-foot se-muted">
      <a class="se-md-link" href="<?= se_h(se_event_url($slug, 'privacy', false)) ?>">How we use your details</a>
      <?php if ((string) $event['status'] === 'published'): ?>
      · <a class="se-md-link" href="<?= se_h(se_event_url($slug, 'calendar.ics', false)) ?>">Add to calendar</a>
      <?php endif; ?>
    </p>

  </div>
</section>
<?php
}

/**
 * The first paint for a display or a crew console.
 *
 * These surfaces are built entirely in JavaScript — a projector with no JS
 * is a broken projector either way — so the server renders only enough to
 * say the page is alive and which screen this is.
 */
function se_render_crew_frame(array $event, string $view): void
{
    $labels = [
        'stage' => ['Stage display', 'Starting the show…'],
        'lobby' => ['Lobby display', 'Waking up the welcome screen…'],
        'host'  => ['Host console', 'Loading tonight…'],
        'desk'  => ['Desk', 'Loading the guest list…'],
        'dj'    => ['Karaoke DJ', 'Loading the queue…'],
    ];
    [$label, $loading] = $labels[$view] ?? ['Crew', 'Loading…'];
    ?>
<section class="se-section" id="se-crew-frame" aria-labelledby="se-crew-title">
  <div class="se-container">
    <p class="se-label"><?= se_h(mb_strtoupper((string) $event['title'], 'UTF-8')) ?></p>
    <h1 class="se-h1 se-page-title" id="se-crew-title"><?= se_h($label) ?></h1>
    <p class="se-glass se-pad se-small" role="status"><?= se_h($loading) ?></p>
  </div>
</section>
    <?php
}

/** The privacy notice, rendered server-side from Markdown (§19.8). */
function se_render_privacy(PDO $pdo, array $event, array $settings): void
{
    $template = (string) se_setting_get($pdo, 'default_privacy_notice_md', '');
    if (trim($template) === '') {
        $template = se_default_privacy_notice_md();
    }

    $contact = (string) se_setting_get($pdo, 'privacy_contact_email', '');
    $notice  = strtr($template, [
        '{event}'                 => (string) $event['title'],
        '{privacy_contact_email}' => $contact !== '' ? $contact : 'the Envision team',
        '{organizer}'             => (string) ($event['organizer_label'] ?? 'Envision'),
    ]);
    ?>
<section class="se-section">
  <div class="se-container se-measure">
    <p class="se-label">Privacy</p>
    <h1 class="se-h1 se-page-title">How we use your details</h1>
    <div class="se-md"><?= se_markdown($notice) ?></div>
    <p class="se-hero-foot">
      <a class="se-btn se-btn-ghost" href="<?= se_h(se_event_url((string) $event['slug'], '', false)) ?>">Back to <?= se_h($event['title']) ?></a>
    </p>
  </div>
</section>
<?php
}

/** An .ics file for the event (§6.3). */
function se_send_calendar(array $event, array $days, array $settings): void
{
    $slug  = (string) $event['slug'];
    $title = trim(((string) $event['title']) . ' ' . ((string) ($event['edition_label'] ?? '')));

    $location = trim(((string) ($event['venue_name'] ?? '')) . ' ' . ((string) ($event['venue_address'] ?? '')));
    $url      = se_event_url($slug);

    $description = (string) ($event['tagline'] ?? '');
    if ($description === '' && !empty($event['description_md'])) {
        $description = se_markdown_excerpt((string) $event['description_md'], 300);
    }

    // RFC 5545: CRLF line endings, escaped text, UTC stamps.
    $esc = static fn(string $v): string => str_replace(
        ["\\", "\n", ',', ';'],
        ['\\\\', '\\n', '\\,', '\;'],
        se_line($v, 500)
    );
    $utc = static function (?string $sql): string {
        $dt = se_parse_datetime($sql);
        return $dt ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') : '';
    };

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Household of David Lekki Centre//Special Events//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
    ];

    $sourceDays = $days ?: [[
        'day_date'  => se_parse_datetime($event['starts_at'])?->format('Y-m-d') ?? '',
        'starts_at' => $event['starts_at'],
        'ends_at'   => $event['ends_at'],
        'label'     => null,
    ]];

    foreach ($sourceDays as $i => $day) {
        $summary = $title . (count($sourceDays) > 1
            ? ' — ' . ($day['label'] ?: 'Day ' . ($i + 1))
            : '');

        $lines[] = 'BEGIN:VEVENT';
        // Stable across regenerations, so a re-download updates rather than
        // duplicating the entry in someone's calendar.
        $lines[] = 'UID:' . $event['public_id'] . '-' . ($day['day_date'] ?: $i) . '@hodlc.lpc.cm';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . $utc($day['starts_at']);
        $lines[] = 'DTEND:' . $utc($day['ends_at']);
        $lines[] = 'SUMMARY:' . $esc($summary);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . $esc($description);
        }
        if ($location !== '') {
            $lines[] = 'LOCATION:' . $esc($location);
        }
        $lines[] = 'URL:' . $url;
        $lines[] = 'STATUS:' . ((string) $event['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '.ics"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');

    echo implode("\r\n", $lines) . "\r\n";
}

/**
 * The hub at /e/: published public events, upcoming first, then recent ones
 * (§8.8). Unlisted events never appear here.
 */
function se_render_hub(PDO $pdo): void
{
    $nonce = se_csp_nonce();
    se_send_page_headers($nonce, false);

    $upcoming = [];
    $past     = [];

    try {
        $stmt = $pdo->query(
            "SELECT * FROM se_events
             WHERE status = 'published' AND visibility = 'public' AND ends_at >= NOW()
             ORDER BY starts_at ASC LIMIT 12"
        );
        $upcoming = $stmt->fetchAll();

        $stmt = $pdo->query(
            "SELECT * FROM se_events
             WHERE status IN ('published', 'archived') AND visibility = 'public' AND ends_at < NOW()
             ORDER BY starts_at DESC LIMIT 6"
        );
        $past = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('SE hub: ' . $e->getMessage());
    }

    $theme = se_theme_derive('#1D356A', '#D11920');
    ?>
<!doctype html>
<html lang="en-NG" data-theme="marquee" data-surface="hub">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Special events — Household of David Lekki Centre</title>
<meta name="description" content="Upcoming special events at Household of David Lekki Centre.">
<link rel="canonical" href="<?= se_h(se_site_origin() . '/e/') ?>">
<meta name="theme-color" content="<?= se_h($theme['tokens']['--se-bg']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Unbounded:wght@600;700;800&family=Inter:wght@400;500;700&display=swap">
<link rel="stylesheet" href="/assets/se/css/se.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style nonce="<?= se_h($nonce) ?>"><?= se_theme_css_vars($theme) ?></style>
</head>
<body>
<main id="se-main" class="se-section">
  <div class="se-container">
    <p class="se-label">Household of David Lekki Centre</p>
    <h1 class="se-display-lg se-page-title">Special events</h1>

    <?php if (!$upcoming && !$past): ?>
    <div class="se-glass se-pad-lg se-stack">
      <p>Nothing is scheduled just yet — but something is always coming.</p>
      <p><a class="se-btn se-btn-primary" href="/">Visit the main site</a></p>
    </div>
    <?php endif; ?>

    <?php if ($upcoming): ?>
    <h2 class="se-h2">Coming up</h2>
    <ul class="se-card-grid se-hero-body">
      <?php foreach ($upcoming as $row):
          $start = se_parse_datetime($row['starts_at']); ?>
      <li class="se-card">
        <h3 class="se-h2 se-card-title">
          <a class="se-md-link" href="<?= se_h(se_event_url((string) $row['slug'], '', false)) ?>"><?= se_h(trim(((string) $row['title']) . ' ' . ((string) ($row['edition_label'] ?? '')))) ?></a>
        </h3>
        <?php if (!empty($row['tagline'])): ?>
        <p class="se-small se-muted"><?= se_h($row['tagline']) ?></p>
        <?php endif; ?>
        <?php if ($start !== null): ?>
        <p class="se-small"><time datetime="<?= se_h($start->format('c')) ?>"><?= se_h(se_format_day($start)) ?></time></p>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($past): ?>
    <h2 class="se-h2 se-hero-body">Recently</h2>
    <ul class="se-list-plain se-stack-sm">
      <?php foreach ($past as $row):
          $start = se_parse_datetime($row['starts_at']); ?>
      <li class="se-small">
        <a class="se-md-link" href="<?= se_h(se_event_url((string) $row['slug'], '', false)) ?>"><?= se_h(trim(((string) $row['title']) . ' ' . ((string) ($row['edition_label'] ?? '')))) ?></a>
        <?php if ($start !== null): ?><span class="se-muted"> · <?= se_h($start->format('M Y')) ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
<?php
}

/** A branded 404 that links back to the hub (§8.8 step 2). */
function se_render_404(PDO $pdo, string $message): void
{
    http_response_code(404);

    $nonce = se_csp_nonce();
    se_send_page_headers($nonce, false);

    $theme = se_theme_derive('#1D356A', '#D11920');
    ?>
<!doctype html>
<html lang="en-NG" data-theme="marquee" data-surface="error">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Not found — Household of David Lekki Centre</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= se_h($theme['tokens']['--se-bg']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Unbounded:wght@600;700;800&family=Inter:wght@400;500;700&display=swap">
<link rel="stylesheet" href="/assets/se/css/se.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style nonce="<?= se_h($nonce) ?>"><?= se_theme_css_vars($theme) ?></style>
</head>
<body>
<main id="se-main" class="se-section">
  <div class="se-container se-measure-tight">
    <p class="se-label">404</p>
    <h1 class="se-h1 se-hero-title">Nothing here</h1>
    <p class="se-muted"><?= se_h($message) ?></p>
    <p class="se-stack-lg">
      <a class="se-btn se-btn-primary" href="/e/">See what is coming up</a>
    </p>
    <p class="se-small se-stack">
      <a class="se-md-link" href="/">Household of David Lekki Centre</a>
    </p>
  </div>
</main>
</body>
</html>
<?php
}
