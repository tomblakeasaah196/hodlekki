<?php
// /auth/login.php
// Sign-in. Rebuilt on the foundation (strict CSP, self-hosted assets, the
// standalone layout); the behaviour is unchanged: the form posts to
// /api/auth_api.php, which signs in by confirmed personal email or church
// sign-in name, locks out after repeated failures, says where a moved
// sign-in name now lives, keeps the device signed in for 30 days when asked,
// and answers with where to go next. Script: assets/ui/js/pages/login.js.
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
    'scripts' => ['/assets/ui/js/pages/login.js'],
]);

$quote = ['“Let all that you do be done in love.”', '1 Corinthians 16:14'];
?>
<div class="relative flex min-h-screen items-center justify-center bg-gradient-to-br from-hodBlue via-[#16294f] to-black p-4 sm:p-8">
    <div class="ui-card w-full max-w-5xl overflow-hidden shadow-ui-lg md:grid md:min-h-[600px] md:grid-cols-[5fr_7fr]">

        <aside class="relative hidden overflow-hidden bg-hodBlue p-10 text-white md:flex md:flex-col md:justify-between lg:p-12" aria-label="Welcome">
            <picture>
                <source media="(min-width: 768px)" srcset="/assets/images/hod_lekki.jpeg">
                <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="" class="absolute inset-0 h-full w-full object-cover opacity-50" decoding="async">
            </picture>
            <div class="absolute inset-0 bg-gradient-to-br from-black/70 via-black/30 to-hodBlue/90" aria-hidden="true"></div>
            <div class="relative">
                <h2 class="font-display text-3xl font-bold tracking-tight lg:text-4xl">Welcome Home.</h2>
                <p class="mt-3 text-white/85">Thank you for serving with excellence.</p>
            </div>
            <figure class="relative min-h-[140px] transition-opacity duration-700" data-login-quote>
                <blockquote class="font-display text-lg font-medium italic leading-snug text-white/90 lg:text-xl" data-quote-text><?= ui_e($quote[0]) ?></blockquote>
                <figcaption class="mt-4 flex items-center gap-3 text-xs font-semibold uppercase tracking-widest text-white/70">
                    <span class="h-px w-8 bg-hodRed" aria-hidden="true"></span><span data-quote-ref><?= ui_e($quote[1]) ?></span>
                </figcaption>
            </figure>
        </aside>

        <section class="flex flex-col justify-center p-6 sm:p-10 lg:p-16">
            <div class="mx-auto w-full max-w-sm">
                <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" class="mx-auto mb-8 h-14 object-contain md:mx-0" width="160" height="56">

                <h1 class="ui-h1 text-center md:text-left">Sign In to Workspace</h1>
                <p class="mb-6 mt-1 text-center text-sm text-ink-muted md:text-left">Enter your details to access your dashboard.</p>

<?php if ($signOutNotice !== ''): ?>
                <div class="mb-5"><?= ui_banner('warning', ['text' => $signOutNotice]) ?></div>
<?php endif; ?>
                <div class="mb-5" id="login-alert" aria-live="polite"></div>

                <form class="ui-form" id="login-form" data-action="login.submit" data-ui-validate novalidate>
                    <?= ui_field('text', ['name' => 'email', 'id' => 'email', 'label' => 'Email', 'required' => true,
                        'autocomplete' => 'username', 'inputmode' => 'email', 'placeholder' => 'Your personal email or church sign-in name',
                        'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
                    <div>
                        <?= ui_field('password', ['name' => 'password', 'id' => 'password', 'label' => 'Password', 'required' => true,
                            'autocomplete' => 'current-password', 'placeholder' => '••••••••']) ?>
                        <div class="mt-2 flex flex-wrap justify-between gap-2 text-sm">
                            <a class="ui-link font-medium text-hodRed" href="/auth/setup_password">First time? Set your password</a>
                            <a class="ui-link text-ink-muted" href="/auth/forgot_password">Forgot your password?</a>
                        </div>
                    </div>
                    <label class="ui-check">
                        <input type="checkbox" name="remember" value="1" checked>
                        <span>Keep me signed in on this device for 30 days<span class="ui-check__hint">Untick this on a shared or office computer.</span></span>
                    </label>
                    <?= ui_button('Authenticate', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'login-submit']]) ?>
                </form>

                <figure class="mt-8 border-t border-line pt-6 text-center transition-opacity duration-700 md:hidden" data-login-quote>
                    <blockquote class="font-display italic text-ink-muted" data-quote-text><?= ui_e($quote[0]) ?></blockquote>
                    <figcaption class="mt-2 text-xs font-semibold uppercase tracking-widest text-ink-subtle" data-quote-ref><?= ui_e($quote[1]) ?></figcaption>
                </figure>

                <p class="mt-8 text-center text-xs text-ink-subtle">&copy; <?= date('Y') ?> Household of David. All rights reserved.</p>
            </div>
        </section>
    </div>
</div>
<?php
ui_standalone_close();
