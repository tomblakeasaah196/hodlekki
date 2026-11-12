<?php
// /checkin.php
// Check-in at the door, on the public layout (strict CSP, self-hosted assets).
// Opened from a QR code:
//   /checkin?event=TOKEN    one event (its own check-in QR)
//   /checkin?service=today  the standing service QR: today's Sunday or
//                           Thursday service; with none today, a countdown
//                           to the next one.
// Flow: phone -> confirm the name (fix it if misspelled) -> present -> a
// blessing to keep. Newcomers: an event that takes registrations registers
// them on the spot; a check-in-only event (its Registration link is off, as
// services are) counts them as guests with no registration, then offers the
// Connect card, prefilled with their name and phone (this device only).
// Data: /api/checkin_api.php (its own CSRF token, checkin_csrf).
// Script: assets/ui/js/pages/checkin.js.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/includes/site_url.php';
require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

$token = trim((string) ($_GET['event'] ?? ''));
$service = ($_GET['service'] ?? '') === 'today';

ui_public_open([
    'title' => 'Check in',
    'mode' => 'strict',
    // Guests at the door, not the members' app: no install banner or prompt.
    'app' => false,
    'home' => site_url('/'),
    'scripts' => ['/assets/ui/js/pages/checkin.js'],
]);
echo ui_json_script('checkin-config', [
    'csrf' => $_SESSION['checkin_csrf'],
    'token' => $token,
    'service' => $service,
    'connect' => site_url('/connect.php'),
]);
$sources = [
    '' => 'Choose one',
    'Self_Discovery' => 'I’m a member of Household of David',
    'Social_Media' => 'Social media',
    'Broadcast' => 'Broadcast',
    'Media' => 'Media',
    'Invited_By' => 'Someone invited me',
    'Flyer_Banner_Poster' => 'Flyer, banner or poster',
    'Other' => 'Other',
];
?>
<div class="mx-auto max-w-md space-y-4 py-2 sm:py-6">

    <article class="ui-card overflow-hidden" id="ci-card" aria-live="polite">
        <img class="h-36 w-full object-cover sm:h-44" id="ci-banner" alt="" hidden>
        <header class="ci-head" id="ci-head" hidden>
            <p class="ci-head__day" id="ci-day" hidden></p>
            <h1 class="ci-head__title" id="ci-title"></h1>
            <p class="ci-head__meta" id="ci-meta"></p>
        </header>

        <div class="ui-card__body">
            <!-- Loading -->
            <div class="flex flex-col items-center gap-3 py-10 text-ink-muted" data-ci-step="loading">
                <span class="ui-spinner ui-spinner--lg" aria-hidden="true"></span>
                <p>Opening check-in…</p>
            </div>

            <!-- No service today: the countdown to the next one -->
            <section class="space-y-5 py-2 text-center" data-ci-step="countdown" hidden aria-labelledby="ci-next-title">
                <div>
                    <p class="text-sm font-semibold text-brand-red">Next service</p>
                    <h1 class="ci-head__title mt-1" id="ci-next-title"></h1>
                    <p class="ci-head__meta mt-1" id="ci-next-when"></p>
                </div>
                <div class="ci-countdown" role="timer" aria-live="off" aria-describedby="ci-next-title">
                    <div class="ci-countdown__unit"><span class="ci-countdown__num ui-num" data-ci-unit="d">0</span><span class="ci-countdown__label">days</span></div>
                    <div class="ci-countdown__unit"><span class="ci-countdown__num ui-num" data-ci-unit="h">00</span><span class="ci-countdown__label">hours</span></div>
                    <div class="ci-countdown__unit"><span class="ci-countdown__num ui-num" data-ci-unit="m">00</span><span class="ci-countdown__label">minutes</span></div>
                    <div class="ci-countdown__unit"><span class="ci-countdown__num ui-num" data-ci-unit="s">00</span><span class="ci-countdown__label">seconds</span></div>
                </div>
                <p class="text-sm text-ink-muted">Check-in opens on the day of the service.</p>
                <?= ui_button('I’m new here', ['variant' => 'secondary', 'icon' => 'heart-handshake', 'href' => site_url('/connect.php')]) ?>
            </section>

            <!-- Nothing to check in to -->
            <section class="py-2" data-ci-step="closed" hidden>
                <div class="ui-empty ui-empty--compact">
                    <span class="ui-empty__icon"><?= ui_icon('calendar') ?></span>
                    <p class="ui-empty__title" id="ci-closed-title">Check-in has closed</p>
                    <p class="ui-empty__text" id="ci-closed-text">Thank you for coming.</p>
                </div>
            </section>

            <!-- 1. Phone -->
            <form class="ui-form" data-ci-step="phone" data-action="ci.lookup" hidden novalidate>
                <div class="ui-banner ui-banner--info" id="ci-helper-banner" hidden>
                    <?= ui_icon('users') ?>
                    <div class="ui-banner__body"><p class="ui-banner__text">You’re helping someone check in.</p></div>
                    <?= ui_button('Stop helping', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'ci.helper-off']]) ?>
                </div>
                <div>
                    <h2 class="ui-h2" id="ci-phone-title">Welcome!</h2>
                    <p class="mt-1 text-ink-muted" id="ci-phone-lead">Enter your phone number to check in.</p>
                </div>
                <?= ui_field('tel', ['name' => 'phone', 'id' => 'ci-phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel',
                    'placeholder' => 'e.g. 0803 123 4567', 'attrs' => ['maxlength' => 20, 'enterkeyhint' => 'go']]) ?>
                <?= ui_button('Find me', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'ci-find']]) ?>
                <p class="text-center"><button type="button" class="ui-link text-sm" data-action="ci.helper-on" id="ci-helper-on">Helping someone without a phone?</button></p>
            </form>

            <!-- 2. Is this you? -->
            <section class="space-y-4 text-center" data-ci-step="confirm" hidden aria-labelledby="ci-confirm-title">
                <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-primary-soft text-primary"><?= ui_icon('user-check', 'ui-icon--lg') ?></span>
                <div>
                    <h2 class="ui-h2" id="ci-confirm-title">Welcome back!</h2>
                    <p class="mt-1 text-ink-muted" id="ci-confirm-lead">Is this you?</p>
                </div>
                <p class="rounded-ui border border-line bg-surface-sunken px-4 py-3 font-display text-lg font-bold text-ink" id="ci-confirm-name"></p>
                <div class="grid gap-2">
                    <?= ui_button('Check me in', ['variant' => 'brand', 'size' => 'lg', 'block' => true, 'icon' => 'circle-check', 'attrs' => ['data-action' => 'ci.present', 'id' => 'ci-present']]) ?>
                    <div class="grid grid-cols-2 gap-2">
                        <?= ui_button('Fix my name', ['variant' => 'secondary', 'icon' => 'pencil', 'attrs' => ['data-action' => 'ci.edit']]) ?>
                        <?= ui_button('Not me', ['variant' => 'ghost', 'attrs' => ['data-action' => 'ci.restart']]) ?>
                    </div>
                </div>
            </section>

            <!-- 2b. Fix the name -->
            <form class="ui-form" data-ci-step="edit" data-action="ci.save-name" hidden novalidate>
                <div>
                    <h2 class="ui-h2">Fix the name</h2>
                    <p class="mt-1 text-ink-muted">Type the full name as it should read.</p>
                </div>
                <?= ui_field('text', ['name' => 'name', 'id' => 'ci-edit-name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'attrs' => ['maxlength' => 100]]) ?>
                <div class="grid gap-2">
                    <?= ui_button('Save and check in', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'attrs' => ['id' => 'ci-save-name']]) ?>
                    <?= ui_button('Cancel', ['variant' => 'ghost', 'block' => true, 'attrs' => ['data-action' => 'ci.back-confirm']]) ?>
                </div>
            </form>

            <!-- 3a. A newcomer at a service: a guest, never a registration -->
            <form class="ui-form" data-ci-step="guest" data-action="ci.guest" hidden novalidate>
                <div class="text-center">
                    <span class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-full bg-danger-soft text-brand-red"><?= ui_icon('heart-handshake', 'ui-icon--lg') ?></span>
                    <h2 class="ui-h2">You’re welcome here!</h2>
                    <p class="mt-1 text-ink-muted">Tell us your name to check in.</p>
                </div>
                <?= ui_field('text', ['name' => 'name', 'id' => 'ci-guest-name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'attrs' => ['maxlength' => 100]]) ?>
                <p class="ui-meta">Phone <span class="ui-num font-semibold text-ink" data-ci-phone-echo></span> · <button type="button" class="ui-link" data-action="ci.restart">Change</button></p>
                <?= ui_button('Check me in', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'ci-guest-go']]) ?>
            </form>

            <!-- 3b. A newcomer at an event that takes registrations -->
            <form class="ui-form" data-ci-step="register" data-action="ci.register" hidden novalidate>
                <div>
                    <h2 class="ui-h2">Register to check in</h2>
                    <p class="mt-1 text-ink-muted">We don’t have this number for this event yet.</p>
                </div>
                <?= ui_field('text', ['name' => 'name', 'id' => 'ci-reg-name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'attrs' => ['maxlength' => 100]]) ?>
                <?= ui_field('tel', ['name' => 'phone', 'id' => 'ci-reg-phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'attrs' => ['maxlength' => 20]]) ?>
                <?= ui_field('select', ['name' => 'source', 'id' => 'ci-reg-source', 'label' => 'How did you hear about it?', 'optional' => true, 'options' => $sources]) ?>
                <div class="grid gap-2">
                    <?= ui_button('Register and check in', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'attrs' => ['id' => 'ci-reg-go']]) ?>
                    <?= ui_button('Try another number', ['variant' => 'ghost', 'block' => true, 'attrs' => ['data-action' => 'ci.restart']]) ?>
                </div>
            </form>

            <!-- 4. Done: a blessing to keep -->
            <section class="space-y-4" data-ci-step="done" hidden aria-labelledby="ci-done-title">
                <div class="text-center">
                    <span class="mx-auto mb-2 grid h-14 w-14 place-items-center rounded-full bg-success-soft text-success"><?= ui_icon('circle-check', 'ui-icon--lg') ?></span>
                    <h2 class="ui-h2" id="ci-done-title">You’re checked in</h2>
                    <p class="mt-1 text-ink-muted" id="ci-done-text"></p>
                </div>

                <figure class="ci-blessing" id="ci-blessing">
                    <p class="ci-blessing__kicker">A word just for you</p>
                    <p class="ci-blessing__dear" id="ci-bless-dear"></p>
                    <blockquote class="ci-blessing__verse"><p id="ci-bless-verse"></p><cite id="ci-bless-ref"></cite></blockquote>
                    <p class="ci-blessing__kicker">A prayer for you</p>
                    <p class="ci-blessing__prayer" id="ci-bless-prayer"></p>
                    <figcaption class="ci-blessing__sign"><span>With love and blessing,</span><strong id="ci-bless-sign">Household of David, Lekki</strong></figcaption>
                </figure>
                <div class="grid grid-cols-2 gap-2">
                    <?= ui_button('Copy', ['variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-action' => 'ci.copy']]) ?>
                    <?= ui_button('Save picture', ['variant' => 'secondary', 'icon' => 'download', 'attrs' => ['data-action' => 'ci.save']]) ?>
                </div>

                <div class="ci-welcome" id="ci-welcome" hidden>
                    <div>
                        <p class="font-display text-lg font-bold text-ink">New here?</p>
                        <p class="text-sm text-ink-muted">Fill the welcome card so our team can say hello. Your name and number are already in.</p>
                    </div>
                    <?= ui_button('Fill the welcome card', ['variant' => 'brand', 'block' => true, 'icon_end' => 'arrow-right', 'attrs' => ['data-action' => 'ci.connect']]) ?>
                </div>

                <div class="grid gap-2 border-t border-line pt-4" id="ci-helper-next" hidden>
                    <?= ui_button('Check in the next person', ['variant' => 'primary', 'block' => true, 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'ci.restart']]) ?>
                    <?= ui_button('Stop helping', ['variant' => 'ghost', 'block' => true, 'attrs' => ['data-action' => 'ci.helper-off']]) ?>
                </div>
            </section>
        </div>
    </article>
</div>
<?php
ui_public_close();
