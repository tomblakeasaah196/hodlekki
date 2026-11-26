<?php
// /auth/login.php
// Sign-in, rebuilt lean (owner's brief, 13 Nov 2026). One markup, two shapes:
//
//   wide screens   the church photo full-bleed, the welcome on it, and the
//                  sign-in form on a frosted panel at the right;
//   phones/tablets the welcome fills the screen (greeting, the services with
//                  the next one marked, rotating scripture, "New here?" to
//                  Connect) with a red Sign in button pinned at the bottom
//                  that slides the same form up as a sheet. Without script
//                  the form simply follows the welcome.
//
// The form posts to /api/auth_api.php as before: a personal email, a church
// sign-in name or a phone number, the password, and "keep me signed in"
// (30 days, explained behind the (i)). Script: assets/ui/js/pages/login.js;
// styles: the .si block in assets/ui/src/app.css; the welcome's words:
// includes/auth_welcome.php.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/../includes/security_headers.php';
security_send_baseline_headers();
session_start();

// Constants and pure helpers only, unless this device is remembered (below).
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/auth_welcome.php';

// "Return to". Wherever the member was going when the login wall stopped them:
// a tapped notification (header.php passes it), a Special Events console link
// on a fresh device (guide §21.4), or the Library's older ?redirect=.
// auth_safe_next() only ever accepts a same-origin screen; anything else is
// ignored silently.
$next = auth_safe_next($_GET['next'] ?? ($_GET['redirect'] ?? null));

// A remembered device ("Keep me signed in") needs the database to start its
// session; db.php does that on the way in.
if (!isset($_SESSION['user_id']) && !empty($_COOKIE[AUTH_REMEMBER_COOKIE])) {
    require_once __DIR__ . '/../includes/db.php';
}

// Already signed in: straight on. The browser re-attaches any #fragment.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($next ?? '/'));
    exit;
}

// Remember it for api/auth_api.php, or forget an older one: a destination is
// only good for the visit to this page that asked for it.
if ($next !== null) {
    $_SESSION['post_login_next'] = $next;
} else {
    unset($_SESSION['post_login_next']);
}

// When the security gate tears a session down (suspension, revoke, "sign out
// everywhere"), it leaves a short-lived cookie explaining why. Show it once,
// then clear it.
$signOutNotice = '';
if (!empty($_COOKIE[SECURITY_SIGNOUT_COOKIE])) {
    $signOutNotice = substr((string) $_COOKIE[SECURITY_SIGNOUT_COOKIE], 0, 300);
    setcookie(SECURITY_SIGNOUT_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

require_once __DIR__ . '/../includes/ui/standalone.php';

// The installed app can open here (its start URL bounces signed-out people to
// sign-in), so this page keeps the launch screen and the install banner.
ui_standalone_open('Sign in', [
    'bare' => true,
    'boot' => true,
    'quiet' => false,
    'scripts' => ['/assets/ui/js/passkey.js', '/assets/ui/js/pages/login.js'],
]);

$now = auth_welcome_now();
$services = auth_welcome_services($now);
$verse = AUTH_WELCOME_SCRIPTURE[0];
$photo = '/assets/images/connect/hod-lekki-';
$srcset = static fn (string $ext): string => "{$photo}640.{$ext} 640w, {$photo}960.{$ext} 960w, {$photo}1078.{$ext} 1078w";
$sizes = '(min-width: 1024px) 75vw, 100vw';
// On a phone the sheet starts open when there is something to read in it,
// or when a link asked for the form (/auth/login?signin=1).
$openSheet = $signOutNotice !== '' || isset($_GET['signin']);
$credit = 'Engineered by <a href="https://jbspraxis.com" target="_blank" rel="noopener">JBS Praxis</a>';

// Passkeys (includes/passkeys.php, owner 26 Nov 2026). The big fingerprint for
// a device that has signed in with one, or made one: the browser remembers
// that (localStorage, assets/ui/js/passkey.js) and the line below reads it
// before the page paints, so the right screen shows first time.
$passkeyButton = static function (string $where): string {
    return '<button type="button" class="si-pk__print" data-action="pk.go" aria-describedby="si-pk-hint-' . $where . '">'
        . '<span class="si-pk__ring" aria-hidden="true"></span><span class="si-pk__ring si-pk__ring--2" aria-hidden="true"></span>'
        . ui_icon('fingerprint') . '<span class="si-pk__scan" aria-hidden="true"></span>'
        . '<span class="sr-only">Sign in with your fingerprint or face</span></button>'
        . '<p class="si-pk__hint" id="si-pk-hint-' . $where . '" data-pk-hint>Touch to sign in</p>';
};
?>
<script nonce="<?= ui_e(ui_csp_nonce()) ?>">(function (d) {
    d.classList.add('si-js');
    try {
        if (window.PublicKeyCredential && navigator.credentials) {
            d.classList.add('si-pk-can');
            if (localStorage.getItem('hod.passkey') === '1') d.classList.add('si-pk-on');
        }
    } catch (e) { /* no storage: the password form, as always */ }
})(document.documentElement);</script>
<div class="si" data-si<?= $openSheet ? ' data-si-open' : '' ?>>
    <div class="si-media" aria-hidden="true">
        <picture>
            <source type="image/avif" srcset="<?= ui_e($srcset('avif')) ?>" sizes="<?= ui_e($sizes) ?>">
            <source type="image/webp" srcset="<?= ui_e($srcset('webp')) ?>" sizes="<?= ui_e($sizes) ?>">
            <img src="<?= ui_e($photo) ?>1078.jpg" srcset="<?= ui_e($srcset('jpg')) ?>" sizes="<?= ui_e($sizes) ?>"
                 width="1078" height="1007" alt="" fetchpriority="high" decoding="async">
        </picture>
    </div>
    <div class="si-wash" aria-hidden="true"></div>

    <section class="si-welcome" aria-labelledby="si-title" data-si-welcome>
        <div class="si-brand" data-si-in="1">
            <img src="/assets/pwa/icon-128.png" width="40" height="40" alt="">
            <span>HOD Lekki</span>
        </div>

        <div class="si-welcome__body">
            <p class="si-greet" data-si-in="1"><?= ui_e(auth_welcome_greeting($now)) ?></p>
            <h1 class="si-title" id="si-title" data-si-in="2">Welcome Home.</h1>
            <p class="si-church" data-si-in="3">Household of David, Lekki</p>

            <figure class="si-verse" data-si-in="4" data-login-quote>
                <blockquote class="si-verse__text" data-quote-text><?= ui_e($verse[0]) ?></blockquote>
                <figcaption class="si-verse__ref" data-quote-ref><?= ui_e($verse[1]) ?></figcaption>
            </figure>

            <ul class="si-services" data-si-in="5" aria-label="Services">
<?php foreach ($services as $s): ?>
                <li class="si-service<?= $s['state'] !== '' ? ' is-' . $s['state'] : '' ?>">
<?php if ($s['when'] !== ''): ?>
                    <span class="si-service__when"><?php if ($s['state'] === 'live'): ?><span class="si-live" aria-hidden="true"></span><?php endif; ?><?= ui_e($s['when']) ?></span>
<?php endif; ?>
                    <span class="si-service__time"><?= ui_e($s['days'] . ', ') ?><span class="si-nowrap"><?= ui_e($s['time']) ?></span></span>
                    <span class="si-service__name"><?= ui_e($s['name']) ?></span>
                </li>
<?php endforeach; ?>
            </ul>

            <a class="si-new" href="/connect" data-si-in="6">New here? <span>Connect with us</span></a>
        </div>
    </section>

    <div class="si-dock">
        <div class="si-pk si-pk--dock" data-si-in="7">
            <?= $passkeyButton('dock') ?>
            <button type="button" class="si-pk__alt" data-action="si.open" aria-controls="si-panel">Use password</button>
        </div>
        <button type="button" class="si-open" data-action="si.open" aria-controls="si-panel" aria-expanded="false" data-si-in="7">Sign in</button>
        <p class="si-credit si-credit--dock" data-si-in="7"><?= $credit ?></p>
    </div>
    <div class="si-scrim" data-action="si.close" aria-hidden="true"></div>

    <section class="si-panel" id="si-panel" aria-labelledby="si-panel-title" tabindex="-1" data-si-panel>
        <div class="si-panel__grip" data-si-grip aria-hidden="true"><span></span></div>
        <?= ui_button('Close', ['variant' => 'ghost', 'icon' => 'x', 'icon_only' => true, 'class' => 'si-panel__close',
            'attrs' => ['data-action' => 'si.close', 'data-tooltip' => null]]) ?>
        <div class="si-panel__inner">
            <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" class="si-panel__logo" width="160" height="56">
            <h2 class="si-panel__title" id="si-panel-title">Sign in</h2>

<?php if ($signOutNotice !== ''): ?>
            <div class="si-panel__notice"><?= ui_banner('warning', ['text' => $signOutNotice]) ?></div>
<?php endif; ?>
            <div id="login-alert" aria-live="polite"></div>

            <div class="si-pk si-pk--panel">
                <?= $passkeyButton('panel') ?>
                <p class="si-pk__or">or use your password</p>
            </div>

            <form class="ui-form si-form" id="login-form" data-action="login.submit" data-ui-validate novalidate>
                <?= ui_field('text', ['name' => 'email', 'id' => 'email', 'label' => 'Email or phone', 'required' => true,
                    'autocomplete' => 'username webauthn', 'info' => 'Your own email, your phone number, or your old @hodlc.com name.',
                    'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false', 'autocorrect' => 'off']]) ?>
                <div class="ui-field">
                    <div class="si-label-row">
                        <label class="ui-label" for="password">Password<span class="ui-label__req" aria-hidden="true">*</span></label>
                        <a class="ui-link si-forgot" href="/auth/forgot_password">Forgot?</a>
                    </div>
                    <div class="si-secret">
                        <input class="ui-input" type="password" id="password" name="password" required autocomplete="current-password"
                               aria-describedby="password-error">
                        <button type="button" class="si-secret__toggle" data-action="si.reveal" aria-controls="password"
                                aria-pressed="false" aria-label="Show password"><?= ui_icon('eye') ?></button>
                    </div>
                    <p class="ui-field-error" id="password-error" aria-live="polite"></p>
                </div>
                <div class="si-remember">
                    <label class="ui-check">
                        <input type="checkbox" name="remember" value="1" checked>
                        <span>Keep me signed in</span>
                    </label>
                    <?= ui_info('This device stays signed in for 30 days. Untick it on a shared or office computer.', 'About keeping you signed in') ?>
                </div>
                <?= ui_button('Sign in', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'login-submit']]) ?>
            </form>

            <p class="si-pk-other"><button type="button" data-action="pk.go"><?= ui_icon('fingerprint') ?>Have a passkey? <span>Use it</span></button></p>
            <p class="si-first"><a class="ui-link" href="/auth/setup_password">First sign-in? <span>Set your password</span></a></p>
            <p class="si-credit si-credit--panel"><?= $credit ?></p>
        </div>
    </section>
</div>
<?= ui_json_script('si-scripture', AUTH_WELCOME_SCRIPTURE) ?>
<?php
ui_standalone_close();
