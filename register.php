<?php
// /register.php
// Public, unauthenticated event registration + discovery page.
// Anyone with the link (church member or open/visitor) can view event details and register.
// The event, its days and its own questions come from /api/registration_api.php
// (fetch_form), and the registration is posted back to it (submit_registration)
// as before. Rebuilt on the public layout (strict CSP, self-hosted assets; the
// event video is click-to-play from youtube-nocookie.com). Script:
// assets/ui/js/pages/register.js.
require_once __DIR__ . '/includes/db.php'; // establishes $pdo and the session safely

$is_logged_in = isset($_SESSION['user_id']);

// -----------------------------------------------------------------------------------
// Server-side meta lookup (for link previews / SEO).
// -----------------------------------------------------------------------------------
$token = $_GET['token'] ?? '';
$meta_title = 'Event Registration | HOD Lekki Centre';
$meta_description = 'You\'re invited! Tap to view event details and register.';
$meta_image = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$meta_url = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/register.php');
$page_title = 'Event Registration';

if (!empty($token)) {
    try {
        $metaStmt = $pdo->prepare("SELECT title, description, event_date, end_date, banner_image_url, ministers, ministers_image_url FROM events WHERE registration_token = ?");
        $metaStmt->execute([$token]);
        $metaEvent = $metaStmt->fetch(PDO::FETCH_ASSOC);
        if ($metaEvent) {
            $meta_title = $metaEvent['title'] . ' | HOD Lekki Centre';
            $page_title = (string) $metaEvent['title'];
            $niceDate = date('F j, Y', strtotime($metaEvent['event_date']));
            if (!empty($metaEvent['end_date']) && $metaEvent['end_date'] !== date('Y-m-d', strtotime($metaEvent['event_date']))) {
                $niceDate .= ' - ' . date('F j, Y', strtotime($metaEvent['end_date']));
            }
            $meta_description = !empty($metaEvent['description'])
                ? mb_strimwidth(trim(strip_tags($metaEvent['description'])), 0, 500, '...')
                : "You're invited! Join us on {$niceDate}. Tap to register.";
            if (!empty($metaEvent['banner_image_url'])) {
                $meta_image = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . $metaEvent['banner_image_url'];
            }
        }
    } catch (\Throwable $e) {
        error_log('register.php meta lookup error: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

$head = '    <meta name="description" content="' . ui_e($meta_description) . '">' . "\n";
foreach (['og:type' => 'website', 'og:title' => $meta_title, 'og:description' => $meta_description, 'og:url' => $meta_url, 'og:site_name' => 'CHURCH HOD Lekki'] as $p => $v) {
    $head .= '    <meta property="' . $p . '" content="' . ui_e($v) . '">' . "\n";
}
if ($meta_image) {
    $head .= '    <meta property="og:image" content="' . ui_e($meta_image) . '">' . "\n";
}
foreach (['twitter:card' => 'summary_large_image', 'twitter:title' => $meta_title, 'twitter:description' => $meta_description] as $n => $v) {
    $head .= '    <meta name="' . $n . '" content="' . ui_e($v) . '">' . "\n";
}
if ($meta_image) {
    $head .= '    <meta name="twitter:image" content="' . ui_e($meta_image) . '">' . "\n";
}

ui_public_open([
    'title' => $page_title,
    'mode' => 'strict',
    'indexable' => true,
    'head' => $head,
    'scripts' => ['/assets/ui/js/pages/register.js'],
    'csp' => [
        'frame-src' => ['https://www.youtube-nocookie.com'],
        'img-src' => ['https://img.youtube.com'],
    ],
]);
echo ui_json_script('reg-config', [
    'token' => (string) $token,
    'loggedIn' => $is_logged_in,
    'login' => '/auth/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/register.php'),
]);
?>
<div class="mx-auto max-w-2xl px-4 py-6 sm:py-10">

    <div class="ui-card" id="reg-loading" aria-live="polite">
        <div class="ui-card__body flex flex-col items-center gap-3 py-10 text-ink-muted">
            <span class="ui-spinner ui-spinner--lg" aria-hidden="true"></span>
            <p>Verifying secure link…</p>
        </div>
    </div>

    <section class="ui-card" id="reg-error" hidden>
        <div class="ui-empty">
            <span class="ui-empty__icon"><?= ui_icon('circle-alert') ?></span>
            <p class="ui-empty__title">Registration unavailable</p>
            <p class="ui-empty__text" id="reg-error-text">This link is invalid or the event has been closed.</p>
            <div class="ui-empty__actions"><?= ui_button('Return home', ['variant' => 'secondary', 'href' => '/', 'attrs' => ['id' => 'reg-error-link']]) ?></div>
        </div>
    </section>

    <section class="ui-card" id="reg-success" hidden>
        <div class="ui-card__body space-y-4 py-8 text-center">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-success-soft text-success"><?= ui_icon('circle-check', 'ui-icon--xl') ?></span>
            <h2 class="ui-h2">You’re registered!</h2>
            <p class="text-ink-muted">Your spot is confirmed. See you there!</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                <?= ui_button('Invite others', ['variant' => 'primary', 'icon' => 'send', 'attrs' => ['data-action' => 'reg.share']]) ?>
                <?= ui_button('Register another', ['variant' => 'secondary', 'attrs' => ['data-action' => 'reg.again']]) ?>
            </div>
        </div>
    </section>

    <div class="space-y-4" id="reg-main" hidden>
        <article class="ui-card overflow-hidden">
            <div class="relative" id="reg-hero" hidden>
                <img id="reg-hero-img" class="max-h-96 w-full object-cover" src="data:," alt="Event banner">
            </div>
            <div class="ui-card__body space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="ui-badge ui-badge--brand" id="reg-category">Event</span>
                    <?= ui_button('Share', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'send', 'attrs' => ['data-action' => 'reg.share']]) ?>
                </div>
                <h1 class="ui-h1" id="reg-title"></h1>
                <div class="flex flex-wrap gap-3 font-mono text-center" id="reg-countdown" aria-live="off" hidden></div>
                <ul class="space-y-1 text-sm text-ink-muted">
                    <li class="flex items-center gap-2"><?= ui_icon('calendar') ?><span id="reg-date"></span></li>
                    <li class="flex items-center gap-2" id="reg-location" hidden><?= ui_icon('map-pin') ?><span></span></li>
                </ul>
                <div id="reg-video" hidden></div>
                <div id="reg-external" hidden></div>
                <div class="ui-prose max-w-none" id="reg-description" hidden></div>
                <div id="reg-ministers" hidden>
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Ministering</h2>
                    <div class="flex flex-wrap gap-4" id="reg-ministers-list"></div>
                </div>
            </div>
        </article>

        <form class="space-y-4" id="reg-form" data-action="reg.submit" novalidate>
            <input type="hidden" name="action" value="submit_registration">
            <input type="hidden" name="token" id="reg-token">

            <section class="ui-card" data-reg-section aria-labelledby="reg-you-title">
                <div class="ui-card__body ui-form">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white" data-reg-step>1</span>
                        <h2 class="ui-h3 flex-1" id="reg-you-title">Your information</h2>
                    </div>
                    <label class="ui-check" id="reg-proxy-wrap" hidden><input type="checkbox" name="registering_someone_else" id="reg-proxy" value="1" data-action-change="reg.proxy"><span>Registering someone else?</span></label>
                    <p class="ui-hint" id="reg-member-note" hidden>Filled from your member profile. Update anything that has changed.</p>
                    <?= ui_field('text', ['name' => 'guest_name', 'id' => 'reg-name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'placeholder' => 'John Doe']) ?>
                    <div class="ui-form-grid">
                        <?= ui_field('tel', ['name' => 'guest_phone', 'id' => 'reg-phone', 'label' => 'Phone number', 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '+234…']) ?>
                        <?= ui_field('email', ['name' => 'guest_email', 'id' => 'reg-email', 'label' => 'Email', 'optional' => true, 'autocomplete' => 'email']) ?>
                    </div>
                    <?= ui_field('select', ['name' => 'guest_gender', 'id' => 'reg-gender', 'label' => 'Gender', 'required' => true, 'options' => ['' => 'Select gender', 'Male' => 'Male', 'Female' => 'Female']]) ?>
                </div>
            </section>

            <section class="ui-card" id="reg-days" data-reg-section aria-labelledby="reg-days-title" hidden>
                <div class="ui-card__body space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white" data-reg-step>2</span>
                        <div><h2 class="ui-h3" id="reg-days-title">Which days will you attend?</h2><p class="text-sm text-ink-muted">Pick every day you will come.</p></div>
                    </div>
                    <div class="ui-chips flex-wrap" id="reg-day-list"></div>
                </div>
            </section>

            <section class="ui-card" id="reg-custom" data-reg-section aria-labelledby="reg-custom-title" hidden>
                <div class="ui-card__body ui-form">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white" data-reg-step>3</span>
                        <h2 class="ui-h3" id="reg-custom-title">Event specifics</h2>
                    </div>
                    <div class="space-y-4" id="reg-fields"></div>
                </div>
            </section>

            <?= ui_button('Confirm registration', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'reg-submit']]) ?>
        </form>
    </div>
</div>

<?= ui_dialog_start('reg-issues', ['title' => 'Please fix these', 'size' => 'sm']) ?>
    <ul class="list-disc space-y-1 pl-5 text-sm text-ink-muted" id="reg-issue-list"></ul>
<?= ui_dialog_end(ui_button('Got it', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])) ?>
<?php
ui_public_close();
