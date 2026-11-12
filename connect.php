<?php
// /connect.php
// The public Connect card: a first-time guest's details in three short
// steps, sent to /api/embrace_public_api.php (check_existing_user after step
// 1, then submit_connect_card). Rebuilt on the public layout (strict CSP,
// self-hosted assets); script: assets/ui/js/pages/connect.js.
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

// The church's own statements, word for word (shown in full behind an (i)).
const CONNECT_VISION  = 'To raise a people after God’s heart in every nation of the earth.';
const CONNECT_MISSION = 'To bring people into an experience of God’s unconditional love that transforms them into men and women passionate about influencing their world through the principles of the Kingdom.';
const CONNECT_VALUES  = ['Integrity', 'Sharing our faith boldly', 'Excellence (Global mindset)', 'Excitement', 'Acceptance', 'Commitment', 'Teamwork'];
$site = static fn (string $path): string => site_url($path);

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

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
    'home' => $site('/'),
    'scripts' => $scripts,
    'csp' => $csp,
    'head' => '    <meta name="description" content="Household of David, Lekki Centre. Passing through or looking for a family, you belong here.">' . "\n",
]);
echo ui_json_script('connect-config', [
    'geoapifyKey' => $_ENV['GEOAPIFY_API_KEY'] ?? '7a189b607e9e4c4cbedf6ada291b6bc1',
    'systemDomain' => '@hodlc.com',
]);

$links = [
    ['Already Family', 'user-round', '/auth/login.php'],
    ['Parent Portal', 'baby', '/parent_portal.php'],
    ['Library', 'book-open', '/modules/library/index.php'],
    ['Testimonies', 'sparkles', '/testimonies.php'],
    ['Sermons', 'music', '/sermons.php'],
];
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:py-10">

    <section class="space-y-4" id="connect-landing" aria-labelledby="connect-title">
        <div class="ui-card overflow-hidden">
            <img src="/assets/images/hod_lekki.jpeg" alt="People connecting in fellowship" class="h-44 w-full object-cover sm:h-56">
            <div class="ui-card__body space-y-4">
                <div>
                    <h1 class="ui-h1" id="connect-title">Welcome Home.</h1>
                    <p class="mt-2 text-sm text-ink-muted">Led by <span class="font-semibold text-ink">Pastor Sola Osunmakinde</span>, with Resident Pastor <span class="font-semibold text-ink">Ebele Uzo-Peters</span>.</p>
                    <p class="mt-1 text-ink-muted">Passing through or looking for a family, you belong here.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-ui bg-surface-sunken p-3">
                        <div class="ui-title-row"><h2 class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Our Vision</h2><?= ui_info(CONNECT_VISION, 'Our vision in full') ?></div>
                        <p class="text-sm text-ink">A people after God’s heart in every nation.</p>
                    </div>
                    <div class="rounded-ui bg-surface-sunken p-3">
                        <div class="ui-title-row"><h2 class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Our Mission</h2><?= ui_info(CONNECT_MISSION, 'Our mission in full') ?></div>
                        <p class="text-sm text-ink">Bringing people into God’s life-changing love.</p>
                    </div>
                </div>
                <div>
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Our Values</h2>
                    <ul class="ui-chips flex-wrap"><?php foreach (CONNECT_VALUES as $value): ?><li class="ui-chip"><?= ui_e($value) ?></li><?php endforeach; ?></ul>
                </div>
                <?= ui_button('I’m new here', ['variant' => 'brand', 'size' => 'lg', 'block' => true, 'icon_end' => 'arrow-right', 'attrs' => ['data-action' => 'connect.start']]) ?>
            </div>
        </div>
        <nav class="grid grid-cols-2 gap-2 sm:grid-cols-3" aria-label="Already part of the family">
<?php foreach ($links as [$label, $icon, $path]): ?>
            <a class="ui-card ui-card--interactive flex items-center gap-2 p-3 text-sm font-semibold text-ink" href="<?= ui_e($site($path)) ?>"><?= ui_icon($icon) ?><span><?= ui_e($label) ?></span></a>
<?php endforeach; ?>
        </nav>
    </section>

    <section class="ui-card" id="connect-wizard" aria-labelledby="connect-wizard-title" hidden>
        <div class="ui-card__header ui-card__header--divided">
            <div class="min-w-0">
                <h2 class="ui-card__title" id="connect-wizard-title">Let’s get acquainted</h2>
                <div class="mt-2 flex gap-1.5" aria-hidden="true">
                    <span class="h-1.5 w-8 rounded-full bg-hodRed" data-dot="1"></span>
                    <span class="h-1.5 w-8 rounded-full bg-line-strong" data-dot="2"></span>
                    <span class="h-1.5 w-8 rounded-full bg-line-strong" data-dot="3"></span>
                </div>
                <p class="sr-only" id="connect-step-label" aria-live="polite">Step 1 of 3</p>
            </div>
            <?= ui_button('Close', ['variant' => 'ghost', 'icon' => 'x', 'icon_only' => true, 'attrs' => ['data-action' => 'connect.cancel']]) ?>
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
    </section>

    <section class="ui-card" id="connect-success" hidden aria-labelledby="connect-success-title">
        <div class="ui-card__body space-y-4 text-center">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-success-soft text-success"><?= ui_icon('circle-check', 'ui-icon--xl') ?></span>
            <h2 class="ui-h2" id="connect-success-title">We’ve received you!</h2>
            <p class="text-ink-muted">Welcome to the family. Our Embrace Team will reach out soon.</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                <?= ui_button('Read testimonies', ['variant' => 'primary', 'href' => $site('/testimonies.php')]) ?>
                <?= ui_button('Return home', ['variant' => 'secondary', 'href' => $site('/index.php')]) ?>
            </div>
        </div>
    </section>
</div>
<?php
ui_public_close();
