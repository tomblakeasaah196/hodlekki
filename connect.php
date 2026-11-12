<?php
// /connect.php
// The welcome page every first timer sees, and the public Connect card: a
// full-screen welcome, the church's vision, mission and core values (I SEE &
// ACT), then the guest's details in three short steps, sent to
// /api/embrace_public_api.php (check_existing_user after step 1, then
// submit_connect_card). Public layout, strict CSP, self-hosted assets.
// Script: assets/ui/js/pages/connect.js (the card, and the page's motion).
// Styles: the "Connect" block in assets/ui/src/app.css (.cx-*).
// The owner asked for the full story here (12 Nov 2026): the intro, vision and
// mission are shown in full (tests/ui/copy_allowlist.txt says so).
// Not the app (owner, 12 Nov 2026): the app belongs to hodlekki.org, so this
// page never suggests installing it. No manifest, no install tags, no launch
// splash, no service worker registration (ui_public_open 'app' => false); any
// beforeinstallprompt is cancelled and, on connect.hodlekki.org, a worker the
// old page registered there is retired (connect.js). Pinned by tests/ui/run.php.
// Links use the clean addresses (no .php; the old ones redirect).
//
// Kept exactly as the campaigns work set it (#119, #127; includes/campaigns):
// - the consent box: name="email_news" value="1", pre-ticked, with
//   EMAIL_CONSENT_VISITOR_LABEL and, behind (i), EMAIL_CONSENT_VISITOR_DETAILS
//   word for word (the API records that wording);
// - the form guard: a signed, session-bound, single-use form_ts rendered fresh
//   per request (so this page is never cached: the public layout sends
//   no-store) and the off-screen honeypot CAMPAIGNS_FORM_HONEYPOT;
// - Turnstile, only when campaigns_turnstile_enabled(): its widget sits inside
//   the form (its hidden cf-turnstile-response field is read by the API) and
//   the card is posted as new FormData(form). Every error reply swaps in the
//   fresh form_ts and resets the widget (connect.js).
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
if (session_status() === PHP_SESSION_NONE) session_start();

// This page is also served at the root of connect.hodlekki.org (index.php), so
// its links to the rest of the site go to the main address: signing in on
// connect.hodlekki.org would leave the session on the wrong host.
require_once __DIR__ . '/includes/site_url.php';
require_once __DIR__ . '/includes/campaigns/consent.php';   // EMAIL_CONSENT_NEWS_TEXT: the wording shown is the wording recorded
require_once __DIR__ . '/includes/campaigns/formguard.php'; // signed render time + honeypot for the card
$site = static fn (string $path): string => site_url($path);

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

// The welcome photo, drawn here at three widths in AVIF, WebP and JPEG (the
// original is 1078 px wide, so nothing larger). On a phone the photo covers a
// tall screen, so it is drawn about as wide as 110% of the screen's height.
$heroBase = '/assets/images/connect/hod-lekki-';
$heroSet = static fn (string $ext): string => implode(', ', array_map(
    static fn (int $w): string => $heroBase . $w . '.' . $ext . ' ' . $w . 'w',
    [640, 960, 1078]
));
$heroSizes = '(orientation: portrait) 110vh, 100vw';

$turnstile = campaigns_turnstile_enabled();
$csp = ['connect-src' => ['https://api.geoapify.com']];
$scripts = ['/assets/ui/js/pages/connect.js'];
if ($turnstile) {
    $csp['script-src'] = ['https://challenges.cloudflare.com'];
    $csp['frame-src'] = ['https://challenges.cloudflare.com'];
    $scripts[] = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
}

ui_public_open([
    'title' => 'Welcome Home',
    'mode' => 'strict',
    'indexable' => true,
    'bleed' => true,
    'app' => false,
    'home' => $site('/'),
    'scripts' => $scripts,
    'csp' => $csp,
    'head' => '    <meta name="description" content="Household of David, Lekki Centre. Passing through or looking for a family, you belong here.">' . "\n"
        . '    <link rel="preload" as="image" type="image/avif" imagesrcset="' . ui_e($heroSet('avif')) . '" imagesizes="' . ui_e($heroSizes) . '" fetchpriority="high">' . "\n",
]);
echo ui_json_script('connect-config', [
    'geoapifyKey' => $_ENV['GEOAPIFY_API_KEY'] ?? '7a189b607e9e4c4cbedf6ada291b6bc1',
    'systemDomain' => '@hodlc.com',
    'retireWorker' => is_connect_host(),
]);

// I SEE & ACT: each core value beside its letter. Screen readers get the list
// of values; the big letters and the ampersand are drawn for the eye only.
$values = [
    ['I', 'Integrity', ''],
    ['S', 'Sharing our faith boldly', ''],
    ['E', 'Excellence', 'A global mindset'],
    ['E', 'Excitement', ''],
    ['&', '', ''],
    ['A', 'Acceptance', ''],
    ['C', 'Commitment', ''],
    ['T', 'Teamwork', ''],
];
$more = [
    ['Parent Portal', 'baby', '/parent_portal.php'],
    ['Library', 'book-open', '/modules/library/'],
    ['Testimonies', 'sparkles', '/testimonies.php'],
    ['Sermons', 'music', '/sermons.php'],
];
?>
<div class="cx">

    <section class="cx-hero" aria-labelledby="cx-title">
        <div class="cx-hero__media" data-cx-parallax>
            <picture>
                <source type="image/avif" srcset="<?= ui_e($heroSet('avif')) ?>" sizes="<?= ui_e($heroSizes) ?>">
                <source type="image/webp" srcset="<?= ui_e($heroSet('webp')) ?>" sizes="<?= ui_e($heroSizes) ?>">
                <img src="<?= ui_e($heroBase) ?>1078.jpg" srcset="<?= ui_e($heroSet('jpg')) ?>" sizes="<?= ui_e($heroSizes) ?>"
                     width="1078" height="1007" fetchpriority="high" alt="Two women at the church hugging and laughing">
            </picture>
        </div>
        <div class="cx-hero__wash" aria-hidden="true"></div>
        <div class="cx-wrap cx-hero__content">
            <h1 class="cx-title" id="cx-title">Welcome Home.</h1>
            <p class="cx-intro">Household of David is a global ministry led by <strong>Pastor Sola Osunmakinde</strong>. Our Resident Pastor is <strong>Pastor Ebele Uzo-Peters</strong>. Whether you’re just passing through or looking for a family, you belong here.</p>
            <div class="cx-actions">
                <?= ui_button('I’m new here', ['variant' => 'brand', 'size' => 'lg', 'href' => '#connect', 'attrs' => ['data-action' => 'connect.start']]) ?>
                <?= ui_button('Already family', ['variant' => 'secondary', 'size' => 'lg', 'icon' => 'user-round', 'href' => $site('/auth/login.php')]) ?>
            </div>
        </div>
    </section>

    <section class="cx-story cx-wrap" aria-label="Our vision and mission">
        <div class="cx-vision" data-reveal>
            <h2 class="cx-kicker">Our vision</h2>
            <p class="cx-statement">To raise a people after God’s heart in every nation of the earth.</p>
        </div>
        <div class="cx-mission" data-reveal>
            <h2 class="cx-kicker">Our mission</h2>
            <p class="cx-mission__text">To bring people into an experience of God’s unconditional love that transforms them into men and women passionate about influencing their world through the principles of the Kingdom.</p>
        </div>
    </section>

    <section class="cx-values" id="values" aria-labelledby="cx-values-title">
        <div class="cx-wrap cx-values__grid">
            <div class="cx-values__head" data-reveal>
                <h2 class="cx-values__title" id="cx-values-title">Our core values</h2>
                <p class="cx-values__lead">Together they spell I SEE &amp; ACT.</p>
            </div>
            <ul class="cx-acrostic" data-cx-acrostic>
<?php foreach ($values as $i => [$letter, $value, $note]): ?>
<?php if ($value === ''): ?>
                <li class="cx-acro cx-acro--amp" aria-hidden="true" data-cx-amp><span class="cx-acro__letter" data-l="&amp;">&amp;</span></li>
<?php else: ?>
                <li class="cx-acro<?= $i < 4 ? ' cx-acro--see' : '' ?>" data-cx-acro>
                    <span class="cx-acro__letter" data-l="<?= ui_e($letter) ?>" aria-hidden="true"><?= ui_e($letter) ?></span>
                    <span class="cx-acro__value"><?= ui_e($value) ?><?php if ($note !== ''): ?><span class="sr-only">, </span><span class="cx-acro__note"><?= ui_e($note) ?></span><?php endif; ?></span>
                </li>
<?php endif; ?>
<?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="cx-connect" id="connect" aria-labelledby="cx-connect-title">
        <div class="cx-wrap cx-connect__inner">
            <div class="cx-connect__intro" data-reveal>
                <h2 class="cx-connect__title" id="cx-connect-title">Let’s connect</h2>
                <p class="cx-connect__lead">Three short steps. About two minutes.</p>
            </div>

            <div class="ui-card cx-card" id="connect-wizard" data-reveal>
                <div class="ui-card__header ui-card__header--divided">
                    <div class="min-w-0">
                        <h3 class="ui-card__title" id="connect-wizard-title">Let’s get acquainted</h3>
                        <div class="mt-2 flex gap-1.5" aria-hidden="true">
                            <span class="h-1.5 w-8 rounded-full bg-hodRed" data-dot="1"></span>
                            <span class="h-1.5 w-8 rounded-full bg-line-strong" data-dot="2"></span>
                            <span class="h-1.5 w-8 rounded-full bg-line-strong" data-dot="3"></span>
                        </div>
                        <p class="sr-only" id="connect-step-label" aria-live="polite">Step 1 of 3</p>
                    </div>
                </div>
                <div class="ui-card__body space-y-4">
                    <div class="ui-banner ui-banner--danger" id="connect-error" role="alert" hidden>
                        <?= ui_icon('circle-alert') ?>
                        <div class="ui-banner__body">
                            <p class="ui-banner__title">Please check this</p>
                            <p class="ui-banner__text" id="connect-error-text"></p>
                            <div class="ui-banner__actions" id="connect-error-login" hidden>
                                <?= ui_button('Sign in', ['variant' => 'primary', 'size' => 'sm', 'href' => $site('/auth/login.php')]) ?>
                                <?= ui_button('Find my email', ['variant' => 'ghost', 'size' => 'sm', 'href' => $site('/auth/setup_password.php')]) ?>
                            </div>
                        </div>
                        <?= ui_button('Dismiss', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'x', 'icon_only' => true, 'attrs' => ['data-action' => 'connect.dismiss']]) ?>
                    </div>

                    <?php /* novalidate: a required field on a hidden step would block the submit
                             silently; validateStep() in connect.js and the API do the checking. */ ?>
                    <form class="ui-form" id="connectForm" data-action="connect.submit" novalidate>
                        <input type="hidden" name="action" value="submit_connect_card">
                        <input type="hidden" name="form_ts" value="<?= ui_e(campaigns_form_token()) ?>">
                        <div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true">
                            <label>Leave this field empty <input type="text" name="<?= ui_e(CAMPAIGNS_FORM_HONEYPOT) ?>" value="" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <div class="space-y-4" data-step="1">
                            <p class="text-ink-muted">We are so glad you are here! What should we call you?</p>
                            <div class="ui-form-grid">
                                <?= ui_field('text', ['name' => 'first_name', 'id' => 'connect-first', 'label' => 'First name', 'required' => true, 'autocomplete' => 'given-name', 'attrs' => ['maxlength' => 50]]) ?>
                                <?= ui_field('text', ['name' => 'last_name', 'id' => 'connect-last', 'label' => 'Last name', 'required' => true, 'autocomplete' => 'family-name', 'attrs' => ['maxlength' => 50]]) ?>
                            </div>
                            <?= ui_field('tel', ['name' => 'phone', 'id' => 'connect-phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel',
                                'placeholder' => 'e.g. 0803 123 4567', 'info' => 'For your welcome text.', 'attrs' => ['maxlength' => 25]]) ?>
                            <?= ui_field('email', ['name' => 'email', 'id' => 'connect-email', 'label' => 'Email', 'optional' => true, 'autocomplete' => 'email', 'inputmode' => 'email',
                                'placeholder' => 'Your personal email, e.g. name@gmail.com', 'hint' => 'We will send a link to confirm it.', 'attrs' => ['maxlength' => 100]]) ?>
                            <div class="flex items-start gap-2">
                                <label class="ui-check"><input type="checkbox" name="email_news" value="1" checked><span><?= ui_e(EMAIL_CONSENT_VISITOR_LABEL) ?></span></label>
                                <?= ui_info(EMAIL_CONSENT_VISITOR_DETAILS, 'What this means') ?>
                            </div>
                        </div>

                        <div class="space-y-4" data-step="2" hidden>
                            <p class="text-ink-muted">A little more, so we can serve and celebrate you.</p>
                            <div class="ui-form-grid">
                                <?= ui_field('select', ['name' => 'gender', 'id' => 'connect-gender', 'label' => 'Gender', 'options' => ['' => 'Select…', 'Male' => 'Male', 'Female' => 'Female']]) ?>
                                <?= ui_field('select', ['name' => 'marital_status', 'id' => 'connect-marital', 'label' => 'Marital status', 'options' => ['Single' => 'Single', 'Married' => 'Married']]) ?>
                            </div>
                            <?= ui_field('date', ['name' => 'dob', 'id' => 'connect-dob', 'label' => 'Date of birth', 'optional' => true, 'attrs' => ['min' => '1900-01-01']]) ?>
                            <div class="ui-field">
                                <label class="ui-label" for="connect-address-text">Where do you live?</label>
                                <div class="ui-geo" id="connect-address"></div>
                                <p class="ui-hint">Type to search, or just type your area.</p>
                                <p class="ui-field-error" id="connect-address-error" aria-live="polite"></p>
                                <input type="hidden" name="physical_address" id="connectAddress">
                                <input type="hidden" name="latitude" id="connectLat">
                                <input type="hidden" name="longitude" id="connectLng">
                            </div>
                        </div>

                        <div class="space-y-4" data-step="3" hidden>
                            <p class="text-ink-muted">How can we stand with you and support your spiritual journey?</p>
                            <?= ui_field('text', ['name' => 'invited_by', 'id' => 'connect-invited', 'label' => 'Who invited you?', 'optional' => true,
                                'placeholder' => 'A friend’s name, or Social Media', 'attrs' => ['maxlength' => 150]]) ?>
                            <label class="ui-check"><input type="checkbox" name="wants_to_join" value="1"><span>I am looking to make HOD my home church.</span></label>
                            <label class="ui-check"><input type="checkbox" name="wants_visitation" value="1"><span>I would like a Pastor or minister to call or visit me.</span></label>
                            <?= ui_field('textarea', ['name' => 'prayer_requests', 'id' => 'connect-prayer', 'label' => 'How can we pray for you today?', 'optional' => true,
                                'placeholder' => 'Our Zoe Intercessory team is ready to agree with you', 'attrs' => ['rows' => 3, 'maxlength' => 2000]]) ?>
<?php if ($turnstile): ?>
                            <div class="cf-turnstile" data-sitekey="<?= ui_e(campaigns_turnstile_site_key()) ?>" data-theme="light"></div>
<?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="ui-card__footer flex gap-2">
                    <?= ui_button('Back', ['variant' => 'secondary', 'icon' => 'arrow-left', 'attrs' => ['id' => 'connect-prev', 'data-action' => 'connect.back', 'hidden' => true]]) ?>
                    <?= ui_button('Next step', ['variant' => 'primary', 'icon_end' => 'arrow-right', 'class' => 'flex-1', 'attrs' => ['id' => 'connect-next', 'data-action' => 'connect.next']]) ?>
                    <?= ui_button('Welcome me home', ['variant' => 'brand', 'type' => 'submit', 'icon' => 'heart-handshake', 'class' => 'flex-1', 'attrs' => ['id' => 'connect-submit', 'form' => 'connectForm', 'hidden' => true]]) ?>
                </div>
            </div>

            <div class="ui-card cx-card" id="connect-success" tabindex="-1" hidden aria-labelledby="connect-success-title">
                <div class="ui-card__body space-y-4 text-center">
                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-success-soft text-success"><?= ui_icon('circle-check', 'ui-icon--xl') ?></span>
                    <h3 class="ui-h2" id="connect-success-title">We’ve received you!</h3>
                    <p class="text-ink-muted">Welcome to the family. Our Embrace Team will reach out soon.</p>
                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                        <?= ui_button('Read testimonies', ['variant' => 'primary', 'href' => $site('/testimonies.php')]) ?>
                        <?= ui_button('Return home', ['variant' => 'secondary', 'href' => $site('/')]) ?>
                    </div>
                </div>
            </div>

            <nav class="cx-more" aria-label="More from HOD Lekki">
<?php foreach ($more as [$label, $icon, $path]): ?>
                <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= ui_e($site($path)) ?>"><?= ui_icon($icon) ?><span><?= ui_e($label) ?></span></a>
<?php endforeach; ?>
            </nav>
        </div>
    </section>
</div>
<?php
ui_public_close();
