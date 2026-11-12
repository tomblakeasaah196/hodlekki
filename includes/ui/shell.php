<?php
// /includes/ui/shell.php
/**
 * The members' app shell: one document that is the DESKTOP shell from
 * 1024 px (navy sidebar, top bar with search, alerts and account) and the
 * PHONE shell below it (app bar, bottom bar Home · Search · Alerts · Me ·
 * Menu, the registry as a full-screen menu sheet).
 *
 * includes/header.php calls ui_shell_open(); includes/footer.php calls
 * ui_shell_close(). A page configures itself by setting $ui before
 * requiring header.php — see ui_page_config() and docs/UI.md.
 */

if (defined('HOD_UI_SHELL')) {
    return;
}
define('HOD_UI_SHELL', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/nav.php';
require_once dirname(__DIR__) . '/security_headers.php';
require_once dirname(__DIR__) . '/csrf.php';

/**
 * A page's settings, with defaults. Keys:
 *   title        page name (default: the registry label of this module)
 *   mode         'legacy' (default) — the page still has inline scripts and
 *                handlers: jQuery loads synchronously, the old modal
 *                manager and the Toastify bridge are present, the CSP is the
 *                safe minimum. 'strict' — rebuilt on the components: no
 *                inline script or handler anywhere, enforced strict CSP.
 *   csp          strict only: extra sources, e.g. ['connect-src' => ['https://api.geoapify.com']]
 *   scripts      extra script URLs, loaded deferred after ui.js (in order)
 *   styles       extra stylesheet URLs
 *   jquery       strict only: also load jQuery (deferred). Avoid.
 *   offline      strict only: may a copy be kept for reading offline?
 *                Default yes, except the modules listed in UI_OFFLINE_NEVER.
 *   page_action  HTML for the action slot in the top/app bar (one button)
 *   body_class   extra classes on <body>
 */
function ui_page_config(array $ui, string $currentModule): array
{
    $label = null;
    foreach (ui_nav_registry() as $item) {
        if (in_array($currentModule, (array) ($item['match'] ?? []), true)) {
            $label = $item['label'];
            break;
        }
    }

    $mode = ($ui['mode'] ?? 'legacy') === 'strict' ? 'strict' : 'legacy';
    $neverOffline = ['security', 'roles', 'finance', 'alerts', 'requisition', 'sms_studio', 'ui_guide'];

    return [
        'title' => (string) ($ui['title'] ?? ($label ?? ucfirst(str_replace('_', ' ', $currentModule)))),
        'mode' => $mode,
        'csp' => (array) ($ui['csp'] ?? []),
        'scripts' => array_values((array) ($ui['scripts'] ?? [])),
        'styles' => array_values((array) ($ui['styles'] ?? [])),
        'jquery' => $mode === 'legacy' || !empty($ui['jquery']),
        'offline' => $mode === 'strict' && ($ui['offline'] ?? true) && !in_array($currentModule, $neverOffline, true),
        'page_action' => (string) ($ui['page_action'] ?? ''),
        'body_class' => (string) ($ui['body_class'] ?? ''),
        'module' => $currentModule,
    ];
}

/** Everything from the HTTP headers to the opening of the page's content. */
function ui_shell_open(PDO $pdo, array $page): void
{
    $nonce = ui_csp_nonce();
    security_send_csp($page['mode'], $nonce, $page['csp']);
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        // The service worker may keep a read-only copy of this page for
        // offline reading (sw.js); without this header it never does.
        if ($page['offline']) {
            // u= keys the copies to this session: a new session (another
            // member, or the same one after signing in again) wipes them.
            header('X-HOD-Offline: snapshot; u=' . substr(hash('sha256', session_id() . '|hod-offline-v1'), 0, 24));
        }
    }

    $sections = ui_nav_visible($pdo, $page['module']);
    $homeHref = ui_nav_home_href($pdo);
    $firstName = (string) ($_SESSION['first_name'] ?? 'Member');
    $lastName = (string) ($_SESSION['last_name'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    $role = ui_role_label((string) ($_SESSION['active_role'] ?? 'Member'));
    $photo = !empty($_SESSION['picture_path']) ? (string) $_SESSION['picture_path'] : null;
    $rail = ($_COOKIE['hod_ui_rail'] ?? '') === '1';
    $isHome = in_array($page['module'], ['index.php', 'dashboard', 'member_portal'], true);

    $htmlClass = 'ui-html' . ($rail ? ' ui-rail' : '');
    $GLOBALS['__ui_shell'] = compact('sections', 'homeHref', 'firstName', 'fullName', 'role', 'photo', 'isHome', 'page');
    // A page that never requires footer.php (Charis ends on its own script)
    // still gets the bottom bar, panels and search: whatever the page printed
    // last, the shell is closed when the script ends. footer.php closes it
    // earlier, and the flag in ui_shell_close() stops it printing twice.
    register_shutdown_function('ui_shell_close');

    $pwaNonce = $nonce;
    ?>
<!DOCTYPE html>
<html lang="en" class="<?= ui_e($htmlClass) ?>" data-ui-mode="<?= ui_e($page['mode']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF">
<?php
    // First in <head>: the PWA head partial, which also carries the error
    // monitor's browser capture (a sync, same-origin script) — it must load
    // before any page script so it sees their errors.
    require dirname(__DIR__) . '/pwa_head.php';
?>
    <title><?= ui_e($page['title']) ?> · HOD Lekki</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= ui_e(csrf_token()) ?>">
    <link rel="preload" href="/assets/ui/fonts/inter-latin.v1.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= ui_e(ui_asset('/assets/ui/app.css')) ?>">
<?php foreach ($page['styles'] as $href): ?>
    <link rel="stylesheet" href="<?= ui_e(strncmp($href, '/assets/', 8) === 0 && strpos($href, '?') === false ? ui_asset($href) : $href) ?>">
<?php endforeach; ?>
<?php if ($page['mode'] === 'legacy'): ?>
    <script src="<?= ui_e(ui_asset('/assets/js/modal-manager.js')) ?>" defer></script>
    <script src="<?= ui_e(ui_asset('/assets/js/tab-deeplink.js')) ?>" defer></script>
<?php elseif ($page['jquery']): ?>
    <script src="<?= ui_e(ui_asset('/assets/ui/vendor/jquery-3.7.1.min.js')) ?>" defer></script>
<?php endif; ?>
    <script src="<?= ui_e(ui_asset('/assets/ui/js/ui.js')) ?>" defer></script>
    <script src="<?= ui_e(ui_asset('/assets/ui/js/shell.js')) ?>" defer></script>
    <script src="<?= ui_e(ui_asset('/assets/js/global-search.js')) ?>" defer></script>
<?php foreach ($page['scripts'] as $src): ?>
    <script src="<?= ui_e(strncmp($src, '/assets/', 8) === 0 && strpos($src, '?') === false ? ui_asset($src) : $src) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="<?= ui_e(trim('ui-app ' . $page['body_class'])) ?>">
<?php require dirname(__DIR__) . '/pwa_boot.php'; ?>
<a class="ui-skip" href="#main">Skip to content</a>

<aside class="ui-sidebar" id="ui-sidebar" aria-label="Main menu">
    <div class="ui-sidebar__brand">
        <a class="ui-brand" href="<?= ui_e($homeHref) ?>">
            <span class="ui-brand__mark"><img src="/assets/pwa/icon-96.png" alt="" width="28" height="28"></span>
            <span class="ui-brand__text">
                <span class="ui-brand__name">HOD Lekki</span>
                <span class="ui-brand__sub">Household of David</span>
            </span>
        </a>
        <button type="button" class="ui-sidebar__iconbtn ui-sidebar__collapse" data-ui-rail-toggle aria-label="Collapse menu" data-tooltip="Collapse menu" data-tooltip-side="bottom">
            <?= ui_icon('panel-left-close') ?>
        </button>
        <button type="button" class="ui-sidebar__iconbtn ui-sidebar__close" data-ui-menu-close aria-label="Close menu">
            <?= ui_icon('x', 'ui-icon--lg') ?>
        </button>
    </div>

    <nav class="ui-nav" aria-label="Modules">
<?php foreach ($sections as $section): ?>
        <div class="ui-nav__section">
            <p class="ui-nav__heading" id="nav-<?= ui_e($section['key']) ?>"><?= ui_e($section['label']) ?></p>
            <ul class="ui-nav__list" aria-labelledby="nav-<?= ui_e($section['key']) ?>">
<?php foreach ($section['items'] as $item): ?>
                <li>
                    <a class="ui-nav__item" href="<?= ui_e($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?> data-tooltip="<?= ui_e($item['label']) ?>" data-tooltip-side="right">
                        <?= ui_icon($item['icon']) ?>
                        <span class="ui-nav__label"><?= ui_e($item['label']) ?></span>
<?php if ($item['count'] > 0): ?>
                        <span class="ui-count" title="<?= ui_e($item['badge_title'] ?? '') ?>"><?= $item['count'] > 99 ? '99+' : (int) $item['count'] ?></span>
<?php endif; ?>
                    </a>
                </li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endforeach; ?>
    </nav>

    <div class="ui-sidebar__footer">
        <div class="ui-usercard">
            <a class="ui-usercard__link" href="/modules/profile/index.php" data-tooltip="My profile" data-tooltip-side="right">
                <?= ui_avatar($fullName !== '' ? $fullName : $firstName, $photo, 'sm') ?>
                <span class="ui-usercard__text">
                    <span class="ui-usercard__name"><?= ui_e($fullName !== '' ? $fullName : $firstName) ?></span>
                    <span class="ui-usercard__role"><?= ui_e($role) ?></span>
                </span>
            </a>
            <a class="ui-sidebar__iconbtn" href="/auth/logout.php" aria-label="Sign out" data-tooltip="Sign out">
                <?= ui_icon('log-out') ?>
            </a>
        </div>
    </div>
</aside>

<div class="ui-main-col">
    <header class="ui-topbar">
        <a class="ui-topbar__mark" href="<?= ui_e($homeHref) ?>" aria-label="Home"><img src="/assets/pwa/icon-96.png" alt="" width="26" height="26"></a>
        <p class="ui-topbar__title"><?= ui_e($page['title']) ?></p>
        <button type="button" class="ui-topbar__search" data-gs-open aria-label="Search (Ctrl K)">
            <?= ui_icon('search') ?>
            <span>Search modules, people, events…</span>
            <kbd class="ui-kbd" data-gs-kbd>Ctrl K</kbd>
        </button>
        <div class="ui-topbar__actions">
            <div class="ui-topbar__pageaction"><?= $page['page_action'] ?></div>
            <div class="ui-topbar__desktop">
                <button type="button" class="ui-iconbtn" data-dialog-open="ui-notifications" aria-label="Notifications" data-tooltip="Notifications" data-tooltip-side="bottom">
                    <?= ui_icon('bell') ?>
                    <span class="ui-iconbtn__dot" data-ui-notif-dot hidden></span>
                </button>
                <button type="button" class="ui-account-btn" data-dialog-open="ui-account" aria-label="Account: <?= ui_e($firstName) ?>">
                    <?= ui_avatar($fullName !== '' ? $fullName : $firstName, $photo, 'sm') ?>
                    <span class="ui-account-btn__text">
                        <span class="ui-account-btn__name"><?= ui_e($firstName) ?></span>
                        <span class="ui-account-btn__role"><?= ui_e($role) ?></span>
                    </span>
                </button>
            </div>
        </div>
    </header>
    <div class="ui-offline-bar" role="status" aria-live="polite">
        <?= ui_icon('wifi-off') ?><span data-ui-offline-text>You're offline. Anything you change will wait until you're connected.</span>
    </div>
    <main id="main" class="ui-main" tabindex="-1">
<?php if (function_exists('perm_view_as') && ($viewAs = perm_view_as()) !== null): ?>
        <div class="ui-page">
            <?= ui_banner('warning', [
                'title' => 'Viewing as ' . $viewAs['label'],
                'text' => 'Read-only: nothing can be changed.',
                'info' => 'You see what they may open, never more than you hold yourself. Pages about "me" still show your own things.',
                'actions' => '<form method="post" action="/api/roles_api.php">' . ui_csrf_field()
                    . '<input type="hidden" name="action" value="view_as_stop">'
                    . ui_button('Stop viewing', ['variant' => 'primary', 'type' => 'submit', 'size' => 'sm', 'icon' => 'x']) . '</form>',
            ]) ?>
        </div>
<?php endif; ?>
<?php if ($page['mode'] === 'legacy'): ?>
        <!-- Legacy pages run inline scripts that need jQuery while they parse.
             Loaded here, not in <head>, so the shell paints before it. -->
        <script src="<?= ui_e(ui_asset('/assets/ui/vendor/jquery-3.7.1.min.js')) ?>"></script>
        <script src="<?= ui_e(ui_asset('/assets/ui/js/legacy.js')) ?>"></script>
        <div class="ui-legacy-content">
<?php endif;
}

/** Everything after the page's content. */
function ui_shell_close(): void
{
    $s = $GLOBALS['__ui_shell'] ?? null;
    if ($s === null || !empty($GLOBALS['__ui_shell_closed'])) {
        return;
    }
    $GLOBALS['__ui_shell_closed'] = true;
    $page = $s['page'];
    $searchIndex = ui_nav_search_index($s['sections']);
    ?>
<?php if ($page['mode'] === 'legacy'): ?>
        </div>
<?php endif; ?>
    </main>
</div>

<nav class="ui-bottombar" aria-label="Quick navigation">
    <a class="ui-bottombar__item" href="<?= ui_e($s['homeHref']) ?>"<?= $s['isHome'] ? ' aria-current="page"' : '' ?>>
        <span class="ui-bottombar__pill"><?= ui_icon('house') ?></span><span class="ui-bottombar__label">Home</span>
    </a>
    <button type="button" class="ui-bottombar__item" data-gs-open>
        <span class="ui-bottombar__pill"><?= ui_icon('search') ?></span><span class="ui-bottombar__label">Search</span>
    </button>
    <button type="button" class="ui-bottombar__item" data-dialog-open="ui-notifications">
        <span class="ui-bottombar__pill"><?= ui_icon('bell') ?></span><span class="ui-bottombar__label">Alerts</span>
        <span class="ui-count ui-bottombar__badge" data-ui-notif-count hidden></span>
    </button>
    <button type="button" class="ui-bottombar__item" data-dialog-open="ui-account">
        <span class="ui-bottombar__pill"><?= ui_icon('circle-user-round') ?></span><span class="ui-bottombar__label">Me</span>
    </button>
    <button type="button" class="ui-bottombar__item" data-ui-menu-toggle aria-controls="ui-sidebar" aria-expanded="false">
        <span class="ui-bottombar__pill"><?= ui_icon('menu') ?></span><span class="ui-bottombar__label">Menu</span>
    </button>
</nav>

<dialog id="ui-notifications" class="ui-dialog ui-dialog--panel" aria-labelledby="ui-notifications-title" data-modal-ignore>
    <div class="ui-dialog__header">
        <div class="ui-dialog__heading">
            <h2 class="ui-dialog__title" id="ui-notifications-title">Notifications</h2>
            <p class="ui-dialog__description" data-ui-notif-summary>Loading…</p>
        </div>
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-hod-alerts aria-label="Alert settings on this device" data-tooltip="Alert settings">
            <?= ui_icon('settings') ?>
        </button>
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-dialog-close aria-label="Close">
            <?= ui_icon('x') ?>
        </button>
    </div>
    <div class="ui-dialog__body ui-card__body--flush" data-ui-notif-list>
        <div class="ui-loading"><span class="ui-spinner"></span><span>Loading notifications…</span></div>
    </div>
    <div class="ui-dialog__footer" data-ui-notif-footer hidden>
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ui.notifications.read-all">
            <?= ui_icon('check') ?><span>Mark all as read</span>
        </button>
    </div>
</dialog>

<dialog id="ui-account" class="ui-dialog ui-dialog--panel ui-dialog--narrow" aria-labelledby="ui-account-title" data-modal-ignore>
    <div class="ui-dialog__header">
        <?= ui_avatar($s['fullName'] !== '' ? $s['fullName'] : $s['firstName'], $s['photo'], 'md') ?>
        <div class="ui-dialog__heading">
            <h2 class="ui-dialog__title" id="ui-account-title"><?= ui_e($s['fullName'] !== '' ? $s['fullName'] : $s['firstName']) ?></h2>
            <p class="ui-dialog__description"><?= ui_e($s['role']) ?></p>
        </div>
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" data-dialog-close aria-label="Close">
            <?= ui_icon('x') ?>
        </button>
    </div>
    <div class="ui-dialog__body">
        <div class="ui-stack ui-stack--sm">
            <a class="ui-menu__item" href="/modules/profile/index.php"><?= ui_icon('circle-user-round') ?><span>My profile</span></a>
            <a class="ui-menu__item" href="/modules/member_portal/index.php"><?= ui_icon('house') ?><span>My portal</span></a>
            <button type="button" class="ui-menu__item" data-hod-alerts><?= ui_icon('bell-ring') ?><span>Alerts on this device</span></button>
            <button type="button" class="ui-menu__item" data-hod-install data-ui-install hidden><?= ui_icon('smartphone') ?><span>Install the app</span></button>
            <hr class="ui-menu__sep">
            <a class="ui-menu__item ui-menu__item--danger" href="/auth/logout.php"><?= ui_icon('log-out') ?><span>Sign out</span></a>
        </div>
    </div>
</dialog>

<div id="gsOverlay" class="ui-palette" role="dialog" aria-modal="true" aria-label="Search" hidden data-modal-ignore>
    <div id="gsPanel" class="ui-palette__panel">
        <div class="ui-palette__bar">
            <?= ui_icon('search') ?>
            <input id="gsInput" class="ui-palette__input" type="search" autocomplete="off" spellcheck="false" enterkeyhint="go" placeholder="Search modules, tabs, people, events…" aria-label="Search" aria-controls="gsResults">
            <span id="gsSpinner" class="ui-spinner ui-spinner--sm" hidden></span>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-gs-close>Close</button>
        </div>
        <div id="gsResults" class="ui-palette__results" role="listbox" aria-label="Results"></div>
        <div class="ui-palette__foot">
            <span><kbd class="ui-kbd">↑</kbd><kbd class="ui-kbd">↓</kbd> to move</span>
            <span><kbd class="ui-kbd">Enter</kbd> to open</span>
            <span><kbd class="ui-kbd">Esc</kbd> to close</span>
        </div>
    </div>
</div>

<div class="ui-toasts" id="ui-toasts" aria-live="polite" aria-atomic="false" popover="manual" data-ui-keep-live></div>

<?= ui_json_script('hod-ui', [
    'sprite' => ui_sprite_url(),
    'mode' => $page['mode'],
    'module' => $page['module'],
    'offline' => $page['offline'],
    'search' => ['index' => $searchIndex, 'groups' => array_merge(['__recent__'], array_values(ui_nav_sections()), ['Actions', 'People', 'Events'])],
    'notifications' => '/api/notifications_api.php',
    // Vendored libraries a page loads on demand with HOD.lib('name').
    'vendor' => [
        'chart' => ui_asset('/assets/ui/vendor/chart.js-4.5.0/chart.umd.min.js'),
        'cropper' => ui_asset('/assets/ui/vendor/cropperjs-1.5.13/cropper.min.js'),
        'cropper.css' => ui_asset('/assets/ui/vendor/cropperjs-1.5.13/cropper.min.css'),
        'geoapify' => ui_asset('/assets/ui/vendor/geoapify-autocomplete-1.5.0/geocoder-autocomplete.min.js'),
        'geoapify.css' => ui_asset('/assets/ui/vendor/geoapify-autocomplete-1.5.0/minimal.css'),
        'html2canvas' => ui_asset('/assets/ui/vendor/html2canvas-1.4.1/html2canvas.min.js'),
        'xlsx' => ui_asset('/assets/ui/vendor/xlsx-0.18.5/xlsx.full.min.js'),
        'sortable' => ui_asset('/assets/ui/vendor/sortablejs-1.15.6/Sortable.min.js'),
        // An ES module: import(HOD.config.vendor.qrcode).then(m => m.qrcode(0, 'M'))
        'qrcode' => ui_asset('/assets/ui/vendor/qrcode-generator-1.5.0/qrcode.mjs'),
    ],
]) ?>
</body>
</html>
<?php
}
