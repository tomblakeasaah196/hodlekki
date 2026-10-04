<?php
// /includes/special_events/portal.php
//
// The "Marquee" portal (guide §13.3), rendered on the server.
//
// Everything a guest must be able to read — the title, the date, the venue,
// the FAQ, the state of registration — is real HTML in the first response.
// @se/portal/main.js then layers on the motion, the countdown, the sticky
// bar and the registration sheet. If the JavaScript never arrives, the page
// still tells people what the night is and when to turn up (§13.15).
//
// There is no `style="…"` anywhere in here: the §19.7 CSP covers <style
// nonce> blocks but NOT style attributes, so Chromium drops them. Per-event
// colour comes from the CSS variables e/index.php writes once.

/**
 * The whole portal home page: S0 … S7 plus the sticky bar.
 */
function se_portal_render(
    PDO $pdo,
    array $event,
    array $days,
    array $settings,
    array $phase,
    array $counts,
    ?array $heroAsset,
    ?array $heroVideoAsset,
    ?array $registration
): void {
    $slug      = (string) $event['slug'];
    $title     = (string) $event['title'];
    $edition   = (string) ($event['edition_label'] ?? '');
    $organizer = (string) ($event['organizer_label'] ?? 'Envision');

    $state     = se_registration_state($event, $phase, $counts);
    $seatsLeft = se_seats_left($event, $counts);

    se_portal_topbar($event, $organizer);
    se_portal_hero($event, $days, $settings, $phase, $state, $seatsLeft, $heroAsset, $heroVideoAsset, $registration);
    se_portal_intro($event, $settings);
    se_portal_chapters($event, $settings);
    se_portal_program($pdo, $event, $days, $settings);
    se_portal_venue($event, $days);
    se_portal_faq($event, $settings);
    se_portal_footer($event, $organizer);
    se_portal_sticky($event, $state, $seatsLeft, $registration);
}

// --------------------------------------------------------------------------
// S0 · Top bar
// --------------------------------------------------------------------------

function se_portal_topbar(array $event, string $organizer): void
{
    $slug = (string) $event['slug'];
    ?>
<header class="se-topbar" id="se-topbar" data-scrolled="0">
  <div class="se-container se-topbar-inner">
    <p class="se-label"><?= se_h(mb_strtoupper($organizer, 'UTF-8')) ?> PRESENTS</p>
    <nav class="se-topbar-actions" aria-label="Page">
      <button type="button" class="se-topbar-link" data-se-share hidden>Share</button>
      <details class="se-topbar-menu">
        <summary class="se-topbar-link">Menu</summary>
        <ul class="se-glass">
          <li><a href="#se-chapters">Programme</a></li>
          <li><a href="#se-venue">Venue</a></li>
          <li><a href="#se-faq">FAQ</a></li>
          <li><a href="<?= se_h(se_event_url($slug, 'privacy', false)) ?>">Privacy</a></li>
        </ul>
      </details>
    </nav>
  </div>
</header>
    <?php
}

// --------------------------------------------------------------------------
// S1 · Hero
// --------------------------------------------------------------------------

/**
 * The hero's call to action for a phase and registration state (§10.1, §13.3,
 * Appendix F). Returns [label, href|null, note|null, action|null].
 *
 * `action` is what the client binds to: 'register' opens the sheet, 'manage'
 * scrolls to the ticket. A null action with a null href renders as a plain
 * disabled-looking chip, which is correct for "registration opens soon".
 */
function se_portal_cta(array $event, array $phase, string $state, ?array $registration): array
{
    $slug = (string) $event['slug'];

    // On the night a registered guest needs the next step, not a reminder
    // that they registered: check in at the door, then join the games.
    if ($registration && (string) $registration['status'] === 'confirmed' && ($phase['phase'] ?? '') === 'live') {
        if (empty($registration['first_checkin_at']) && ($phase['checkin_open'] ?? false)) {
            return ['Check in', se_event_url($slug, 'in', false), "You're registered ✓", null];
        }
        if (se_bool(se_settings_path(se_event_settings($event), 'games.enabled', true))) {
            return ['Join the games', se_event_url($slug, 'play', false), "You're registered ✓", null];
        }
    }

    if ($registration && in_array((string) $registration['status'], ['confirmed', 'waitlisted'], true)) {
        $isWait = (string) $registration['status'] === 'waitlisted';

        return [
            $isWait ? "You're on the waitlist" : "You're registered ✓",
            null,
            $isWait ? 'We will text you the moment a seat opens.' : null,
            'manage',
        ];
    }

    return match ((string) ($phase['phase'] ?? 'upcoming')) {
        'upcoming' => match ($state) {
            'open'     => ['Register', null, null, 'register'],
            'waitlist' => ['Join the waitlist', null, se_registration_state_message($state, $event), 'register'],
            'full'     => ["We're full online", null, 'Walk-ins are welcome while space lasts.', null],
            default    => [se_registration_state_message($state, $event), null, null, null],
        },
        'live' => (($phase['checkin_open'] ?? false)
            ? ['Check in', se_event_url($slug, 'in', false), null, null]
            : ['Join the games', se_event_url($slug, 'play', false), 'Check-in has closed — please see the desk.', null]),
        'between_days' => ['See you tomorrow', null, null, null],
        'post'         => ['Relive the night', null, 'The recap is on its way.', null],
        'cancelled'    => ['This event has been cancelled', null, (string) ($event['cancel_reason'] ?? '') ?: null, null],
        'archived'     => ['This event has ended', null, null, null],
        default        => ['Registration opens soon', null, null, null],
    };
}

function se_portal_hero(
    array $event,
    array $days,
    array $settings,
    array $phase,
    string $state,
    ?int $seatsLeft,
    ?array $heroAsset,
    ?array $heroVideoAsset,
    ?array $registration
): void {
    $title     = (string) $event['title'];
    $edition   = (string) ($event['edition_label'] ?? '');
    $tagline   = (string) ($event['tagline'] ?? '');
    $organizer = (string) ($event['organizer_label'] ?? 'Envision');
    $slug      = (string) $event['slug'];

    $firstDay = $days[0] ?? null;
    $startsAt = se_parse_datetime($firstDay['starts_at'] ?? ($event['starts_at'] ?? null));

    [$ctaLabel, $ctaHref, $ctaNote, $ctaAction] = se_portal_cta($event, $phase, $state, $registration);

    $showCountdown = se_bool($settings['portal']['show_countdown'] ?? true)
        && ($phase['phase'] ?? '') === 'upcoming'
        && $startsAt !== null;

    $useVideo = $heroVideoAsset !== null && se_bool($settings['portal']['hero_video_enabled'] ?? true);
    ?>
<section class="se-hero" aria-labelledby="se-hero-title" id="se-hero">

  <div class="se-hero-bg" aria-hidden="true">
    <?php if ($useVideo): ?>
    <video class="se-hero-video" data-se-hero-video
           data-src="<?= se_h((string) $heroVideoAsset['path']) ?>"
           <?php if ($heroAsset !== null): ?>poster="<?= se_h((string) $heroAsset['path']) ?>"<?php endif; ?>
           muted playsinline loop preload="none"></video>
    <?php endif; ?>
    <div class="se-hero-mesh" data-se-mesh></div>
    <div class="se-hero-beam se-hero-beam-l" data-se-beam="l"></div>
    <div class="se-hero-beam se-hero-beam-r" data-se-beam="r"></div>
    <div class="se-hero-grain"></div>
    <div class="se-hero-vignette"></div>
  </div>

  <div class="se-container se-hero-inner">

    <p class="se-label"><?= se_h(mb_strtoupper(trim($title . ' ' . $edition), 'UTF-8')) ?> · BY <?= se_h(mb_strtoupper($organizer, 'UTF-8')) ?></p>

    <h1 id="se-hero-title" class="se-display-xl se-wordmark" data-se-wordmark>
      <?= se_h($title) ?><?php if ($edition !== ''): ?> <span class="se-wordmark-edition"><?= se_h($edition) ?></span><?php endif; ?>
    </h1>

    <?php if ($tagline !== ''): ?>
    <p class="se-h2 se-muted se-measure-hero"><?= se_h($tagline) ?></p>
    <?php endif; ?>

    <ul class="se-chips">
      <?php if ($startsAt !== null): ?>
      <li class="se-chip">📅 <time datetime="<?= se_h($startsAt->format('c')) ?>"><?= se_h(se_format_day($startsAt)) ?></time></li>
      <li class="se-chip">🕔 <?= se_h(ltrim($startsAt->format('g:i A'), '0')) ?></li>
      <?php endif; ?>
      <?php if (!empty($event['venue_name'])): ?>
      <li class="se-chip">📍
        <?php if (!empty($event['venue_map_url'])): ?>
        <a href="<?= se_h((string) $event['venue_map_url']) ?>" rel="noopener"><?= se_h((string) $event['venue_name']) ?></a>
        <?php else: ?>
        <?= se_h((string) $event['venue_name']) ?>
        <?php endif; ?>
      </li>
      <?php endif; ?>
      <?php if (count($days) > 1): ?>
      <li class="se-chip"><?= count($days) ?> days</li>
      <?php endif; ?>
      <li class="se-chip se-chip-live" data-se-live-chip hidden></li>
    </ul>

    <?php if ($showCountdown): ?>
    <ul class="se-countdown" data-se-countdown data-target="<?= se_h($startsAt->format('c')) ?>" aria-label="Countdown to the start">
      <li class="se-count-unit"><span class="se-count-num" data-unit="d">–</span><span class="se-count-label">Days</span></li>
      <li class="se-count-unit"><span class="se-count-num" data-unit="h">–</span><span class="se-count-label">Hours</span></li>
      <li class="se-count-unit"><span class="se-count-num" data-unit="m">–</span><span class="se-count-label">Min</span></li>
      <li class="se-count-unit"><span class="se-count-num" data-unit="s">–</span><span class="se-count-label">Sec</span></li>
    </ul>
    <?php endif; ?>

    <div class="se-cta-cluster">
      <span class="se-cta-main">
        <?php if ($ctaHref !== null): ?>
        <a class="se-btn se-btn-primary" href="<?= se_h($ctaHref) ?>"><?= se_h($ctaLabel) ?></a>
        <?php elseif ($ctaAction !== null): ?>
        <button type="button" class="se-btn se-btn-primary" data-se-cta="<?= se_h($ctaAction) ?>"><?= se_h($ctaLabel) ?></button>
        <?php else: ?>
        <span class="se-btn se-btn-ghost" aria-disabled="true"><?= se_h($ctaLabel) ?></span>
        <?php endif; ?>
        <?php if ($ctaAction === 'register'): ?>
        <span class="se-cta-ring se-animate-pulse" aria-hidden="true"></span>
        <?php endif; ?>
      </span>

      <?php if ($seatsLeft !== null): ?>
      <span class="se-seats" data-se-seats><?= (int) $seatsLeft ?> seats left</span>
      <?php endif; ?>

      <a class="se-btn se-btn-ghost" href="#se-intro">See the night ↓</a>
    </div>

    <?php if ($ctaNote !== null && $ctaNote !== ''): ?>
    <p class="se-small se-muted se-cta-note" data-se-cta-note><?= se_h($ctaNote) ?></p>
    <?php endif; ?>

    <?php if (!empty($settings['registration']['min_age_note'])): ?>
    <p class="se-small se-muted"><?= se_h((string) $settings['registration']['min_age_note']) ?></p>
    <?php endif; ?>

    <p class="se-scroll-cue se-animate-float" aria-hidden="true">Scroll</p>
  </div>

  <div class="se-eq" aria-hidden="true"><?php for ($i = 0; $i < 24; $i++): ?><span class="se-eq-bar" data-eq="<?= $i ?>"></span><?php endfor; ?></div>
</section>
    <?php
}

// --------------------------------------------------------------------------
// S2 · "What is it?"
// --------------------------------------------------------------------------

function se_portal_intro(array $event, array $settings): void
{
    $introLine = trim((string) ($settings['portal']['intro_line'] ?? ''));
    $body      = (string) ($event['description_md'] ?? '');

    if ($introLine === '' && trim($body) === '') {
        return;
    }
    ?>
<section class="se-section se-tint" id="se-intro" aria-labelledby="se-intro-title">
  <div class="se-container se-measure">
    <h2 class="se-label" id="se-intro-title">What is it?</h2>
    <?php if ($introLine !== ''): ?>
    <p class="se-intro-line se-hero-title" data-se-reveal><?= se_h($introLine) ?></p>
    <?php endif; ?>
    <?php if (trim($body) !== ''): ?>
    <div class="se-md"><?= se_markdown($body) ?></div>
    <?php endif; ?>
  </div>
</section>
    <?php
}

// --------------------------------------------------------------------------
// S3 · Chapters
// --------------------------------------------------------------------------

/**
 * One chapter per default activity block.
 *
 * `chapters_from_featured` lets an event drive this off its own featured
 * programme items instead; when it is off, or there is no programme yet,
 * the defaults from §13.3 are used.
 *
 * @return list<array{key:string, title:string, blurb:string}>
 */
function se_portal_chapter_list(array $settings): array
{
    $chapters = [];

    if (se_bool($settings['karaoke']['enabled'] ?? true)) {
        $chapters[] = [
            'key'   => 'mic',
            'title' => 'The Mic',
            'blurb' => 'Tick karaoke when you register — then pick your song before the night, so the queue is ready when you are.',
        ];
    }
    if (se_bool($settings['games']['enabled'] ?? true)) {
        $chapters[] = [
            'key'   => 'games',
            'title' => 'The Games',
            'blurb' => 'Charades, Live Quiz, Trivia, Buzzer and Family Feud — played on your phone, scored on the big screen.',
        ];
    }
    if (se_bool($settings['teams']['enabled'] ?? true)) {
        $chapters[] = [
            'key'   => 'teams',
            'title' => 'The Teams',
            'blurb' => "At check-in you'll join a colour team — balanced, friendly, and very competitive by round two.",
        ];
    }
    $chapters[] = [
        'key'   => 'night',
        'title' => 'The Night',
        'blurb' => 'Doors, welcome, games, karaoke, awards. Come as you are and bring someone with you.',
    ];

    return $chapters;
}

function se_portal_chapters(array $event, array $settings): void
{
    $chapters = se_portal_chapter_list($settings);
    if (!$chapters) {
        return;
    }
    ?>
<section class="se-section" id="se-chapters" aria-labelledby="se-chapters-title">
  <div class="se-container">
    <h2 class="se-label" id="se-chapters-title">The night, chapter by chapter</h2>
    <ol class="se-chapters se-hero-body">
      <?php foreach ($chapters as $i => $chapter): ?>
      <li class="se-chapter" data-se-chapter="<?= se_h($chapter['key']) ?>">
        <div>
          <p class="se-chapter-index" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></p>
          <h3 class="se-display-lg"><?= se_h($chapter['title']) ?></h3>
          <p class="se-chapter-blurb"><?= se_h($chapter['blurb']) ?></p>
        </div>
        <div class="se-chapter-art" aria-hidden="true"><?= se_portal_chapter_art($chapter['key']) ?></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
    <?php
}

/**
 * The interactive object for a chapter.
 *
 * Inline SVG with `currentColor` and the theme variables, so it costs no
 * request and inherits the event's palette. The animation is CSS/GSAP's job.
 */
function se_portal_chapter_art(string $key): string
{
    return match ($key) {
        'mic' => '<svg viewBox="0 0 120 120" role="img" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3">'
            . '<rect x="48" y="18" width="24" height="46" rx="12"/>'
            . '<path d="M36 56a24 24 0 0 0 48 0"/><path d="M60 80v18M46 98h28"/>'
            . '<circle cx="60" cy="56" r="40" stroke-dasharray="4 10" opacity="0.5" class="se-animate-pulse"/>'
            . '</svg>',
        'games' => '<svg viewBox="0 0 160 120" role="img" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3">'
            . '<rect x="16" y="26" width="46" height="66" rx="8" opacity="0.5"/>'
            . '<rect x="40" y="18" width="46" height="74" rx="8" opacity="0.75"/>'
            . '<rect x="66" y="14" width="46" height="80" rx="8"/>'
            . '<path d="M80 40v28M66 54h28"/>'
            . '</svg>',
        'teams' => '<div class="se-orbit">'
            . '<span class="se-orb se-team-chip" data-orb="1"></span>'
            . '<span class="se-orb se-team-chip" data-orb="2"></span>'
            . '<span class="se-orb se-team-chip" data-orb="3"></span>'
            . '<span class="se-orb se-team-chip" data-orb="4"></span>'
            . '</div>',
        default => '<svg viewBox="0 0 160 120" role="img" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3">'
            . '<path d="M12 96h136" opacity="0.4"/>'
            . '<path d="M20 96 46 60l24 20 26-44 24 36 18-14" stroke-linecap="round" stroke-linejoin="round" data-se-draw/>'
            . '<circle cx="46" cy="60" r="4" fill="currentColor" stroke="none"/>'
            . '<circle cx="96" cy="36" r="4" fill="currentColor" stroke="none"/>'
            . '<circle cx="120" cy="72" r="4" fill="currentColor" stroke="none"/>'
            . '</svg>',
    };
}

// --------------------------------------------------------------------------
// S4 · Programme
// --------------------------------------------------------------------------

/**
 * The public run of show, when the event has one (§10.7.2).
 *
 * Only public items, never a crew note, and times in the event's chosen mode
 * (se_program_public() does all three). Before PR4's table exists, or while
 * the programme is empty, the section is simply left out.
 */
function se_portal_program(PDO $pdo, array $event, array $days, array $settings): void
{
    if (!function_exists('se_program_ready') || !se_program_ready($pdo)) {
        return;
    }

    try {
        $program = se_program_public($pdo, $event, $days, $settings);
    } catch (Throwable $e) {
        error_log('SE portal/program: ' . $e->getMessage());
        return;
    }
    if (!$program['days']) {
        return;
    }

    $multiDay = count($program['days']) > 1;
    ?>
<section class="se-section" id="se-programme" aria-labelledby="se-programme-title">
  <div class="se-container">
    <h2 class="se-label" id="se-programme-title">The programme</h2>
    <?php foreach ($program['days'] as $day): ?>
    <?php if ($multiDay): ?>
    <h3 class="se-h2"><?= se_h($day['label'] ?? (se_parse_datetime((string) $day['day_date'])?->format('l j F') ?? '')) ?></h3>
    <?php endif; ?>
    <ol class="se-programme se-measure">
      <?php foreach ($day['items'] as $item): ?>
      <li class="se-programme-item" data-featured="<?= $item['featured'] ? '1' : '0' ?>" data-status="<?= se_h($item['status']) ?>">
        <span class="se-programme-time"><?= $item['time'] !== null ? se_h($item['time']) : '' ?></span>
        <span class="se-programme-body">
          <span class="se-programme-title"><?= se_h($item['title']) ?></span>
          <?php if ($item['blurb'] !== null && $item['blurb'] !== ''): ?>
          <span class="se-programme-blurb"><?= se_h($item['blurb']) ?></span>
          <?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endforeach; ?>
    <?php if ($program['time_mode'] === 'approximate'): ?>
    <p class="se-small se-muted">Times are approximate — the night runs on joy, not a stopwatch.</p>
    <?php endif; ?>
  </div>
</section>
    <?php
}

// --------------------------------------------------------------------------
// S5 · Venue
// --------------------------------------------------------------------------

function se_portal_venue(array $event, array $days): void
{
    if (empty($event['venue_name'])) {
        return;
    }
    $slug = (string) $event['slug'];
    ?>
<section class="se-section" id="se-venue" aria-labelledby="se-venue-title">
  <div class="se-container">
    <h2 class="se-label" id="se-venue-title">Where</h2>
    <div class="se-glass se-pad-lg se-venue se-hero-body se-measure">
      <p class="se-h2"><?= se_h((string) $event['venue_name']) ?></p>
      <?php if (!empty($event['venue_address'])): ?>
      <p class="se-muted"><?= se_h((string) $event['venue_address']) ?></p>
      <?php endif; ?>
      <?php if (!empty($event['venue_notes'])): ?>
      <p class="se-small"><?= se_h((string) $event['venue_notes']) ?></p>
      <?php endif; ?>
      <div class="se-venue-actions">
        <?php if (!empty($event['venue_map_url'])): ?>
        <a class="se-btn se-btn-ghost" href="<?= se_h((string) $event['venue_map_url']) ?>" rel="noopener">Open in Maps</a>
        <?php endif; ?>
        <?php if ((string) $event['status'] === 'published'): ?>
        <a class="se-btn se-btn-ghost" href="<?= se_h(se_event_url($slug, 'calendar.ics', false)) ?>">Add to calendar</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
    <?php
}

// --------------------------------------------------------------------------
// S6 · FAQ
// --------------------------------------------------------------------------

function se_portal_faq(array $event, array $settings): void
{
    $faq = array_values(array_filter(
        (array) ($settings['portal']['faq'] ?? []),
        static fn($row) => is_array($row) && trim((string) ($row['q'] ?? '')) !== ''
    ));
    if (!$faq) {
        return;
    }
    ?>
<section class="se-section" id="se-faq" aria-labelledby="se-faq-title">
  <div class="se-container se-measure">
    <h2 class="se-label" id="se-faq-title">Good to know</h2>
    <div class="se-faq se-hero-body">
      <?php foreach ($faq as $row): ?>
      <details class="se-faq-item">
        <summary><?= se_h((string) $row['q']) ?></summary>
        <p class="se-faq-a"><?= se_h((string) ($row['a'] ?? '')) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

// --------------------------------------------------------------------------
// S7 · Footer
// --------------------------------------------------------------------------

function se_portal_footer(array $event, string $organizer): void
{
    $slug = (string) $event['slug'];
    ?>
<footer class="se-footer">
  <div class="se-container">
    <p class="se-label"><?= se_h(mb_strtoupper($organizer, 'UTF-8')) ?></p>
    <p class="se-small se-muted">Household of David Lekki Centre</p>
    <ul class="se-footer-links se-small">
      <li><a class="se-md-link" href="<?= se_h(se_event_url($slug, 'privacy', false)) ?>">How we use your details</a></li>
      <li><a class="se-md-link" href="/">Main site</a></li>
      <li class="se-muted">Designed by <?= se_h($organizer) ?></li>
    </ul>
  </div>
</footer>
    <?php
}

// --------------------------------------------------------------------------
// Sticky bar
// --------------------------------------------------------------------------

function se_portal_sticky(array $event, string $state, ?int $seatsLeft, ?array $registration): void
{
    $registered = $registration && in_array((string) $registration['status'], ['confirmed', 'waitlisted'], true);

    if (!$registered && !in_array($state, ['open', 'waitlist'], true)) {
        return;   // nothing to offer: no bar at all rather than a dead one
    }
    ?>
<div class="se-sticky" id="se-sticky" data-shown="0" hidden>
  <div class="se-sticky-inner">
    <?php if ($registered): ?>
    <span class="se-small"><strong>You're registered ✓</strong></span>
    <button type="button" class="se-btn se-btn-ghost" data-se-cta="manage">Manage</button>
    <?php else: ?>
    <span class="se-small">
      <?php if ($seatsLeft !== null): ?><strong data-se-seats><?= (int) $seatsLeft ?> seats left</strong><?php else: ?><strong><?= se_h((string) $event['title']) ?></strong><?php endif; ?>
    </span>
    <button type="button" class="se-btn se-btn-primary" data-se-cta="register">
      <?= $state === 'waitlist' ? 'Join the waitlist' : 'Register' ?>
    </button>
    <?php endif; ?>
  </div>
</div>
    <?php
}

// --------------------------------------------------------------------------
// Manage page (§13.5)
// --------------------------------------------------------------------------

/**
 * The server-rendered frame of /me/<token>.
 *
 * The personal content is NOT rendered here: the token has to be exchanged
 * through `claim_link` first, so that opening the link also binds this
 * device. What the server paints is the event's own public detail plus a
 * live region the client fills in.
 */
function se_portal_manage(array $event, array $days, array $settings): void
{
    $slug     = (string) $event['slug'];
    $title    = trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''));
    $firstDay = $days[0] ?? null;
    $startsAt = se_parse_datetime($firstDay['starts_at'] ?? ($event['starts_at'] ?? null));
    ?>
<section class="se-section" aria-labelledby="se-manage-title">
  <div class="se-container se-measure">
    <p class="se-label">Your place at</p>
    <h1 class="se-h1 se-page-title" id="se-manage-title"><?= se_h($title) ?></h1>

    <ul class="se-chips">
      <?php if ($startsAt !== null): ?>
      <li class="se-chip">📅 <time datetime="<?= se_h($startsAt->format('c')) ?>"><?= se_h(se_format_day($startsAt)) ?></time></li>
      <li class="se-chip">🕔 <?= se_h(ltrim($startsAt->format('g:i A'), '0')) ?></li>
      <?php endif; ?>
      <?php if (!empty($event['venue_name'])): ?>
      <li class="se-chip">📍 <?= se_h((string) $event['venue_name']) ?></li>
      <?php endif; ?>
    </ul>

    <div id="se-manage" class="se-stack" aria-live="polite" aria-busy="true">
      <p class="se-glass se-pad">Opening your place…</p>
    </div>

    <noscript>
      <p class="se-glass se-pad se-small">
        This page needs JavaScript to open your personal link. Your seat is
        safe either way — just come to the door and give your phone number.
      </p>
    </noscript>

    <p class="se-small se-hero-foot">
      <a class="se-md-link" href="<?= se_h(se_event_url($slug, '', false)) ?>">Back to <?= se_h((string) $event['title']) ?></a>
      · <a class="se-md-link" href="<?= se_h(se_event_url($slug, 'privacy', false)) ?>">How we use your details</a>
    </p>
  </div>
</section>
    <?php
}

/**
 * `/in` — the check-in page (§13.6).
 *
 * The first paint is real HTML so that a guest standing in the doorway on a
 * weak signal sees the wordmark and the phone field immediately. The reveal,
 * the countdown and the welcome card are enhancements on top.
 */
function se_portal_checkin(array $event, array $days, array $settings, array $window): void
{
    $slug    = (string) $event['slug'];
    $title   = (string) $event['title'];
    $edition = (string) ($event['edition_label'] ?? '');
    $full    = trim($title . ' ' . $edition);
    $doors   = se_parse_datetime(($days[0]['doors_open_at'] ?? null));
    ?>
<section class="se-section" aria-labelledby="se-checkin-title">
  <div class="se-container se-measure">

    <p class="se-label"><?= se_h(mb_strtoupper($full, 'UTF-8')) ?></p>
    <h1 class="se-h1 se-page-title" id="se-checkin-title">Welcome to <?= se_h($title) ?>! Let's check you in.</h1>

    <?php if (!$window['open']): ?>
    <div class="se-glass se-pad se-stack" role="status">
      <?php if (($window['reason'] ?? '') === 'CHECKIN_CLOSED'): ?>
      <p class="se-h2">Check-in has closed — please see the desk.</p>
      <p class="se-small se-muted">The team at the door will sort you out in a moment.</p>
      <?php else: ?>
      <p class="se-h2">Check-in opens<?= $doors !== null ? ' at ' . se_h(ltrim($doors->format('g:i A'), '0')) : ' soon' ?>. See you soon!</p>
      <p class="se-small se-muted" id="se-checkin-countdown"
         data-opens-at="<?= se_h((string) ($window['opens_at'] ?? '')) ?>">
        Keep this page open — it will let you in the moment the doors do.
      </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div id="se-checkin" class="se-stack" aria-live="polite"
         data-open="<?= $window['open'] ? '1' : '0' ?>"></div>

    <noscript>
      <p class="se-glass se-pad se-small">
        This page needs JavaScript to check you in. Come to the desk and give
        your phone number — it takes a few seconds.
      </p>
    </noscript>

    <p class="se-small se-hero-foot">
      <a class="se-md-link" href="<?= se_h(se_event_url($slug, '', false)) ?>">Back to <?= se_h($title) ?></a>
      · <a class="se-md-link" href="<?= se_h(se_event_url($slug, 'privacy', false)) ?>">How we use your details</a>
    </p>
  </div>
</section>
    <?php
}
