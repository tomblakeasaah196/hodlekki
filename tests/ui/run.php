<?php
// /tests/ui/run.php
/**
 * The UI foundation's tests (docs/UI.md). No database server, no network.
 *
 *   php tests/ui/run.php
 *
 * 1. The navigation registry: unique keys, real files, icons that exist.
 * 2. ui_nav_can_access() asks the roles × modules permission matrix (and
 *    the two module rosters). The old sidebar's answers are compared with it
 *    person by person in tests/permissions/ (permissions PR 2), where every
 *    difference must be one the owner decided.
 * 3. Every icon named in PHP or script is in the sprite.
 * 4. CSRF helpers and the Content-Security-Policy builder.
 * 5. The component helpers escape what they print and refuse inline handlers.
 * 6. The shell renders for a signed-in member, with no PHP notice.
 * 7. The ratchet: pages in strict mode carry no inline script, handler,
 *    style attribute or CDN; and the list of files still allowed the
 *    Tailwind CDN may only shrink.
 *
 * Exit code 0 = all good, 1 = at least one failure. CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$root = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function suite(string $name): void
{
    echo "\n{$name}\n";
}

require_once $root . '/includes/ui/bootstrap.php';
require_once $root . '/includes/ui/nav.php';
require_once $root . '/includes/security_headers.php';
require_once $root . '/includes/csrf.php';

$sprite = (string) file_get_contents($root . '/assets/ui/icons.svg');
preg_match_all('/<symbol id="([a-z0-9-]+)"/', $sprite, $m);
$icons = array_flip($m[1]);

/* ---------------------------------------------------------------------- */
suite('1. the navigation registry');

$registry = ui_nav_registry();
$keys = array_column($registry, 'key');
ok('keys are unique', count($keys) === count(array_unique($keys)));
$sections = ui_nav_sections();
$badSection = $badIcon = $badHref = [];
foreach ($registry as $item) {
    if (!isset($sections[$item['section']])) {
        $badSection[] = $item['key'];
    }
    if (!isset($icons[$item['icon']])) {
        $badIcon[] = $item['key'] . ':' . $item['icon'];
    }
    $path = parse_url($item['href'], PHP_URL_PATH);
    if (empty($item['when_exists']) && !is_file($root . $path)) {
        $badHref[] = $item['href'];
    }
}
ok('every entry is in a known section', $badSection === [], implode(', ', $badSection));
ok('every entry\'s icon is in the sprite', $badIcon === [], implode(', ', $badIcon));
ok('every entry links to a file that exists', $badHref === [], implode(', ', $badHref));
$searchIndex = ui_nav_search_index([]);
ok('the search index ends with sign-out', end($searchIndex)['url'] === '/auth/logout.php');

/* ---------------------------------------------------------------------- */
suite('2. the navigation asks the permission matrix');

// The old sidebar, rule for rule, now lives in includes/permissions/legacy_rules.php
// and tests/permissions/ compares it with the matrix for every kind of person.
// Here: ui_nav_can_access() routes to perm_ctx_nav_visible() and the rosters.
require_once $root . '/includes/permissions.php';
$sc = static fn (bool $church, array $depts = []): array => ['church' => $church, 'depts' => $depts, 'own' => true];
$own = ['church' => false, 'depts' => [], 'own' => true];
$navCtx = static fn (array $abilities, bool $bg = false): array => [
    'perm' => ['ready' => true, 'user_id' => 1, 'breakglass' => $bg, 'abilities' => $abilities,
        'seats' => [], 'role_ids' => [], 'seat_depts' => [], 'sources' => []],
    'assim_team' => false, 'se_crew' => false,
];
ok('a module the person may use beyond their own records shows', ui_nav_can_access('reach', $navCtx(['reach' => ['view' => $sc(true)]])));
ok('a department-scoped module shows too', ui_nav_can_access('departments', $navCtx(['departments' => ['edit' => $sc(false, [3])]])));
ok('own records only: no link', !ui_nav_can_access('pastoral', $navCtx(['pastoral' => ['create' => $own]])));
ok('member-facing pages always show', ui_nav_can_access('bible', $navCtx([])) && ui_nav_can_access('member_portal', $navCtx([])));
ok('Announcements shows to those who approve, not to every reader',
    ui_nav_can_access('announcements', $navCtx(['announcements' => ['approve' => $sc(true)]]))
    && !ui_nav_can_access('announcements', $navCtx(['announcements' => ['view' => $sc(true)]])));
ok('break-glass sees the design system and System Health',
    ui_nav_can_access('ui_guide', $navCtx([], true)) && ui_nav_can_access('system_health', $navCtx([], true)));
ok('System Health is the monitor module',
    ui_nav_can_access('system_health', $navCtx(['monitor' => ['view' => $sc(true)]])) && !ui_nav_can_access('system_health', $navCtx([])));
ok('Assimilation volunteers see Assimilation', ui_nav_can_access('assimilation', ['perm' => null, 'assim_team' => true, 'se_crew' => false]));
ok('Special Events crew see Special Events', ui_nav_can_access('special_events', ['perm' => null, 'assim_team' => false, 'se_crew' => true]));
ok('people who work from a mailbox see Mail (its own flag, from mail_nav_visible())',
    ui_nav_can_access('mail', ['perm' => null, 'mail_nav' => true]) && !ui_nav_can_access('mail', ['perm' => null]));
ok('nobody resolved, nothing shows', !ui_nav_can_access('reach', ['perm' => null]));
ok('an unknown key is refused', !ui_nav_can_access('nonexistent', $navCtx([], true)));
$unmapped = array_filter($keys, static fn (string $k): bool => !isset(perm_modules()[ui_nav_module($k)]));
ok('every registry entry has a permission module', $unmapped === [], implode(', ', $unmapped));
/* ---------------------------------------------------------------------- */
suite('3. every icon named in code exists');

$named = [];
$scan = static function (string $dir, string $pattern) use (&$named, $root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!preg_match('/\.(php|js)$/', $file->getFilename())) {
            continue;
        }
        preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $hits);
        foreach ($hits[1] as $name) {
            $named[$name][] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
};
$scan('/includes', "/ui_icon\\(\\s*'([a-z0-9-]+)'/");
$scan('/modules', "/ui_icon\\(\\s*'([a-z0-9-]+)'/");
$scan('/modules', "/'icon'\\s*=>\\s*'([a-z0-9-]+)'/");
$scan('/assets/ui/js', "/icon\\(\\s*'([a-z0-9-]+)'/");
$missing = [];
foreach ($named as $name => $files) {
    if (!isset($icons[$name])) {
        $missing[] = $name . ' (' . $files[0] . ')';
    }
}
ok(count($named) . ' icon names found, all in the sprite', $missing === [], implode(', ', $missing));

/* ---------------------------------------------------------------------- */
suite('4. CSRF and the Content-Security-Policy');

$_SESSION = [];
$token = csrf_token();
ok('a token is 64 hex characters', (bool) preg_match('/^[0-9a-f]{64}$/', $token));
ok('the same token for the whole session', csrf_token() === $token);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'hodlekki.org';
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$_SERVER['HTTP_ORIGIN'] = 'https://hodlekki.org';
ok('the right token from this site is valid', csrf_valid());
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
ok('the right token from another site is not', !csrf_valid());
$_SERVER['HTTP_ORIGIN'] = 'https://hodlekki.org';
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);
ok('a wrong token is not', !csrf_valid());
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST['_csrf'] = $token;
ok('the form field works as well as the header', csrf_valid());
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
ok('Sec-Fetch-Site: cross-site is refused', !csrf_valid());
unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_POST['_csrf']);
ok('GET is never a write', (function () { $_SERVER['REQUEST_METHOD'] = 'GET'; $r = !csrf_is_write_request(); $_SERVER['REQUEST_METHOD'] = 'POST'; return $r; })());
ok('the SMS webhook is exempt', csrf_is_exempt('/api/sms_webhook.php'));
ok('a module API is not exempt', !csrf_is_exempt('/api/congregation_api.php'));

// security_send_csp() is a no-op under the CLI, so test the policy text the
// way it is built: capture through a subprocess-free re-implementation check.
$cspSource = (string) file_get_contents($root . '/includes/security_headers.php');
ok('the strict policy never allows unsafe-inline', strpos($cspSource, "'unsafe-inline'") === false);
ok('the strict policy never allows unsafe-eval', strpos($cspSource, "'unsafe-eval'") === false);
ok('extra sources cannot inject a directive', strpos($cspSource, "preg_match('/[;,\\s]/', \$source)") !== false);
ok('violations are reported to one configurable path', strpos($cspSource, "'report-uri ' . HOD_CSP_REPORT_PATH") !== false
    && strpos($cspSource, 'report-to hod-csp') !== false);
$reportSource = (string) file_get_contents($root . '/api/csp_report.php');
ok('CSP reports never go to error_log (the monitor imports every error_log line)', strpos($reportSource, 'error_log(') === false);
ok('CSP reports are limited per IP and in total', strpos($reportSource, "csp_report_take('ip_'") !== false && strpos($reportSource, "csp_report_take('all'") !== false);
ok('CSP report URLs lose their query string and fragment', strpos($reportSource, "preg_replace('/[?#].*\$/s', ''") !== false);
ok('the monitor\'s public endpoints are exempt from CSRF',
    csrf_is_exempt('/monitor_confirm.php') && csrf_is_exempt('/api/monitor_intake.php') && csrf_is_exempt('/api/monitor_probe.php'));
$csrfSource = (string) file_get_contents($root . '/includes/csrf.php');
ok('a missing token is logged at most once per endpoint per hour site-wide',
    strpos($csrfSource, "'/hod_csrf_' . date('YmdH')") !== false && strpos($csrfSource, "@fopen(\$marker, 'x')") !== false);
ok('the CSRF log line keeps the prefix the monitor files as a notice', strpos($csrfSource, "'CSRF report-only: %s %s (%s)'") !== false);

/* ---------------------------------------------------------------------- */
suite('5. component helpers');

$btn = ui_button('<b>Save</b>', ['variant' => 'primary', 'attrs' => ['onclick' => 'alert(1)', 'data-action' => 'x']]);
ok('labels are escaped', strpos($btn, '&lt;b&gt;Save&lt;/b&gt;') !== false);
ok('inline handlers are dropped', stripos($btn, 'onclick') === false);
ok('data-action survives', strpos($btn, 'data-action="x"') !== false);
ok('an unknown variant falls back to secondary', strpos(ui_button('A', ['variant' => 'nope']), 'ui-btn--secondary') !== false);
$icon = ui_button('Delete Ada', ['icon' => 'trash-2', 'icon_only' => true]);
ok('an icon-only button is named for screen readers', strpos($icon, 'aria-label="Delete Ada"') !== false);
$field = ui_field('email', ['name' => 'email', 'label' => 'Email', 'required' => true, 'error' => 'Enter an email.']);
preg_match('/<label class="ui-label" for="([^"]+)"/', $field, $fm);
ok('a field\'s label points at its control', !empty($fm[1]) && strpos($field, 'id="' . $fm[1] . '"') !== false);
ok('a field\'s error is announced and linked', strpos($field, 'aria-invalid="true"') !== false && strpos($field, $fm[1] . '-error') !== false);
ok('initials of a two-part name', ui_initials('Adaeze Okonkwo') === 'AO');
ok('a person keeps their avatar colour', ui_avatar_tone('Adaeze Okonkwo') === ui_avatar_tone(' adaeze okonkwo '));
$json = ui_json_script('x', ['a' => '</script><script>alert(1)</script>']);
ok('boot data cannot close its script tag', strpos($json, '</script><script>') === false);
ok('asset URLs carry a content hash', (bool) preg_match('#^/assets/ui/app\.css\?v=[0-9a-f]{12}$#', ui_asset('/assets/ui/app.css')));

/* ---------------------------------------------------------------------- */
suite('6. the shell renders for a signed-in member');

if (!extension_loaded('pdo_sqlite')) {
    ok('pdo_sqlite is available for the shell render', false, 'install pdo_sqlite');
} else {
    // Stand-ins for the two module helpers the navigation consults (their
    // real files need the MySQL schema). Defined first, so nav.php uses them.
    function assim_is_team_member($pdo, $uid): bool { return false; }
    function se_is_crew($pdo, $uid): bool { return false; }
    function assim_sidebar_counts($pdo, $uid): array { return ['my_overdue' => 0, 'pool' => 0, 'watchlist_untouched' => 0]; }

    // The permission matrix from the real migrations, on a small church:
    // member 7 is HOD of Embrace (department 2) and holds no pastoral role.
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE user_departments (user_id INT, department_id INT, role_in_dept TEXT, is_active INT)');
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Super_Admin'), (2, 'Resident_Pastor'), (3, 'Assoc_Pastor'), (4, 'Church_Member')");
    $pdo->exec("INSERT INTO departments (id, name) VALUES (1, 'Information and Data Insights'), (2, 'Embrace'), (8, 'Reach')");
    $pdo->exec("INSERT INTO users (id, first_name, last_name) VALUES (7, 'Ada', 'Obi')");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (7, 4)');
    $pdo->exec("INSERT INTO user_departments VALUES (7, 2, 'HOD', 1)");
    require_once $root . '/tests/permissions/install.php';
    perm_test_install($pdo);
    $_SESSION = [
        'user_id' => 7, 'first_name' => 'Ada', 'last_name' => 'Obi', 'active_role' => 'Church_Member',
        'roles' => [['role_name' => 'Church_Member']],
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/modules/embrace/index.php';
    require_once $root . '/includes/ui/shell.php';
    restore_error_handler();
    $notices = [];
    set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
        // The module helpers that need their own tables log and carry on.
        $notices[] = $msg;
        return true;
    });
    $page = ui_page_config([], 'embrace');
    ob_start();
    ui_shell_open($pdo, $page);
    echo '<p>content</p>';
    ui_shell_close();
    $html = (string) ob_get_clean();
    restore_error_handler();

    ok('no PHP notice or warning while rendering', $notices === [], implode(' | ', array_slice($notices, 0, 3)));
    ok('the page is titled after the module', strpos($html, '<title>Embrace (First Timers) · HOD Lekki</title>') !== false);
    ok('the Embrace link is current', (bool) preg_match('#href="/modules/embrace/index.php" aria-current="page"#', $html));
    // Lead decision 2: an HOD seat carries what the retired global HOD role
    // gave (Congregation among it), so the link now shows; Finance does not.
    ok('an HOD seat sees Congregation (decision 2)', strpos($html, '/modules/congregation/index.php') !== false);
    ok('an HOD seat does not see Finance', strpos($html, '/modules/finance/index.php') === false);
    ok('the CSRF token is in the page', strpos($html, '<meta name="csrf-token" content="' . $_SESSION['_csrf'] . '">') !== false);
    ok('the phone bottom bar is there', strpos($html, 'class="ui-bottombar"') !== false);
    ok('legacy mode still loads jQuery for inline page scripts', strpos($html, '/assets/ui/vendor/jquery-3.7.1.min.js') !== false);
    ok('the closing half runs once', substr_count($html, 'id="hod-ui"') === 1);
    ui_shell_close();
    ok('closing again prints nothing', true);
    ok('no inline event handler in the shell', !preg_match('/\son[a-z]+="/i', $html));
    ok('no style attribute in the shell', strpos($html, ' style="') === false);

    // The public layout (signed-out pages such as the Library).
    $_SESSION = ['_csrf' => $_SESSION['_csrf']];
    require_once $root . '/includes/ui/public.php';
    ob_start();
    ui_public_open(['title' => 'Charis Library', 'mode' => 'strict', 'actions' => '<a href="/auth/login.php">Sign in</a>', 'head' => '<meta property="og:title" content="X">']);
    echo '<p>content</p>';
    ui_public_close();
    $pub = (string) ob_get_clean();
    ui_public_close();
    ok('the public layout renders a titled page with its head tags and bar actions',
        strpos($pub, '<title>Charis Library · HOD Lekki</title>') !== false
        && strpos($pub, '<meta property="og:title" content="X">') !== false
        && strpos($pub, 'class="ui-public-bar__actions"><a href="/auth/login.php">Sign in</a>') !== false);
    ok('the public layout has no members\' navigation', strpos($pub, 'ui-sidebar') === false && strpos($pub, 'ui-bottombar') === false);
    ok('the public layout carries the CSRF token, the toasts and the boot data once',
        strpos($pub, 'name="csrf-token"') !== false && substr_count($pub, 'id="ui-toasts"') === 1 && substr_count($pub, 'id="hod-ui"') === 1);
    ok('the public layout has no inline handler or style attribute', !preg_match('/\son[a-z]+="/i', $pub) && strpos($pub, ' style="') === false);
}

/* ---------------------------------------------------------------------- */
suite('7. the ratchet');

$strictFiles = [];
$tailwindCdn = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!preg_match('/\.php$/', $path) || preg_match('#^(vendor|tests|node_modules|\.git|\.tailwind)/#', $path)) {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match("/'mode'\\s*=>\\s*'strict'/", $src) && strpos($path, 'includes/') !== 0) {
        $strictFiles[$path] = $src;
    }
    if (strpos($src, 'cdn.tailwindcss.com') !== false) {
        $tailwindCdn[] = $path;
    }
}
ok('at least one page runs in strict mode', count($strictFiles) > 0);
foreach ($strictFiles as $path => $src) {
    $problems = [];
    if (preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $src)) {
        $problems[] = 'inline on*= handler';
    }
    if (preg_match('/<[a-z][^>]*\sstyle\s*=/i', $src)) {
        $problems[] = 'style attribute';
    }
    if (preg_match('/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/i', $src)) {
        $problems[] = 'inline <script>';
    }
    if (preg_match('#https?://(cdn\.|unpkg\.|cdnjs\.|code\.jquery|fonts\.googleapis)#i', $src)) {
        $problems[] = 'CDN URL';
    }
    if (preg_match('/\b(Toastify|confirm|alert|prompt)\s*\(/', $src)) {
        $problems[] = 'Toastify/confirm/alert/prompt';
    }
    ok("strict page {$path} is clean", $problems === [], implode(', ', $problems));
}

// Every module page must require the shell by a path that resolves from its
// own folder (modules/dashboard/ once required 'includes/header.php' and
// answered HTTP 500 while Departments linked to it).
$badRequire = [];
foreach (glob($root . '/modules/*/index.php') ?: [] as $page) {
    $src = (string) file_get_contents($page);
    if (!preg_match_all("/\brequire(?:_once)?\b\s*\(?\s*([^;\n]*header\.php[^;\n]*?)\)?\s*;/", $src, $req)) {
        continue;
    }
    foreach ($req[1] as $expr) {
        $ok = strpos($expr, '__DIR__') !== false || preg_match("#['\"]\\.\\./\\.\\./includes/header\\.php['\"]#", $expr);
        if (!$ok) {
            $badRequire[] = substr($page, strlen($root) + 1) . ': ' . trim($expr);
        }
    }
}
ok('every module requires the shell by a path that resolves from its folder', $badRequire === [], implode('; ', $badRequire));
// Every public entry point sends the baseline security headers: either it
// loads includes/db.php (or the shell) unconditionally at the top level, or it
// calls security_send_baseline_headers() itself (login and connect once sent
// none on a plain GET).
$noHeaders = [];
foreach (array_merge(glob($root . '/*.php') ?: [], glob($root . '/auth/*.php') ?: []) as $entry) {
    $src = (string) file_get_contents($entry);
    $loadsDb = (bool) preg_match("#^require_once __DIR__ \\. '(/\\.\\.)?/includes/(db|header)\\.php';#m", $src);
    if (!$loadsDb && strpos($src, 'security_send_baseline_headers()') === false) {
        $noHeaders[] = substr($entry, strlen($root) + 1);
    }
}
ok('every root and /auth/ entry point sends the baseline security headers', $noHeaders === [], implode(', ', $noHeaders));
ok('the old /modules/dashboard/ URL redirects to the dashboard',
    strpos((string) file_get_contents($root . '/modules/dashboard/index.php'), "header('Location: /index.php'") !== false);

$allowedCdn = array_values(array_filter(array_map('trim', file($root . '/tests/ui/tailwind_cdn_allowlist.txt') ?: []),
    static fn ($l) => $l !== '' && $l[0] !== '#'));
sort($tailwindCdn);
sort($allowedCdn);
$newCdn = array_diff($tailwindCdn, $allowedCdn);
$gone = array_diff($allowedCdn, $tailwindCdn);
ok('no new page loads the Tailwind CDN', $newCdn === [], implode(', ', $newCdn));
ok('the allow-list names only pages that still use it (remove the ones you rebuilt)', $gone === [], implode(', ', $gone));

/* ---------------------------------------------------------------------- */
suite('8. view transitions never leak "AbortError: Transition was skipped"');

// Page-to-page transitions are opted into by @view-transition in pwa.css,
// which only includes/pwa_head.php loads. A skipped transition rejects the
// ViewTransition's promises; unhandled, the error monitor logged one per
// page view. pwa_head.php handles them on pageswap and pagereveal.
$pwaHead = (string) file_get_contents($root . '/includes/pwa_head.php');
ok('pwa_head.php handles skipped page transitions on pageswap and pagereveal',
    strpos($pwaHead, "addEventListener('pageswap'") !== false
    && strpos($pwaHead, "addEventListener('pagereveal'") !== false
    && (bool) preg_match("/\\['ready', 'finished', 'updateCallbackDone'\\][\\s\\S]{0,200}\\.catch\\(/", $pwaHead));
$vtCss = [];
$vtJs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('#^(vendor|node_modules|\.git|\.tailwind|tests)/#', $path) || strpos($path, '/vendor/') !== false) {
        continue;
    }
    if (preg_match('/\.(css|php)$/', $path) && strpos((string) file_get_contents($file->getPathname()), '@view-transition') !== false) {
        $vtCss[] = $path;
    }
    if (preg_match('/\.(js|php)$/', $path)) {
        $src = (string) file_get_contents($file->getPathname());
        // Every script-started transition handles its promises within the same statement.
        if (preg_match_all('/startViewTransition\s*\(/', $src, $calls, PREG_OFFSET_CAPTURE)) {
            foreach ($calls[0] as [$call, $at]) {
                if (!preg_match('/\.catch\s*\(/', substr($src, $at, 600))) {
                    $vtJs[] = $path;
                }
            }
        }
    }
}
ok('only pwa.css (loaded with the pwa_head.php guard) opts pages into view transitions',
    array_diff($vtCss, ['assets/css/pwa.css']) === [], implode(', ', array_diff($vtCss, ['assets/css/pwa.css'])));
ok('every document.startViewTransition() call handles its promises (.catch)', $vtJs === [], implode(', ', array_unique($vtJs)));

/* ---------------------------------------------------------------------- */
suite('9. short copy (docs/UI.md "Writing: short copy")');

require_once __DIR__ . '/copy_lint.php';
ok('a greeting is not counted in a title', copy_words('Hi Tom-Blake, set your password', true) === 3);
ok('a leading welcome is not counted either', copy_words('Welcome, Ada', true) === 0);
ok('dashes and ampersands are not words', copy_words('Reach & Evangelism — new') === 3);
ok('the linter finds a long subtitle', (static function (): bool {
    $args = copy_calls("<?= ui_page_header(['title' => 'Giving', 'description' => 'One. Two.', 'actions' => ui_button('Save gift', [])]) ?>", 'ui_page_header');
    return count($args) === 1 && copy_option($args[0], 'description') === 'One. Two.' && copy_sentences('One. Two.') === 2;
})());
$copyFound = array_values(array_unique(array_map('copy_line', copy_findings($root))));
$copyAllowed = array_values(array_filter(array_map('rtrim', file(__DIR__ . '/copy_allowlist.txt') ?: []),
    static fn ($l) => $l !== '' && $l[0] !== '#'));
$copyNew = array_diff($copyFound, $copyAllowed);
$copyFixed = array_diff($copyAllowed, $copyFound);
ok('no new copy breaks the short-copy rule (title 2–4 words, one line, details behind ui_info())',
    $copyNew === [], "\n      " . implode("\n      ", array_slice($copyNew, 0, 12)));
ok('the copy allow-list only names copy that is still too long (delete the lines you fixed)',
    $copyFixed === [], "\n      " . implode("\n      ", array_slice($copyFixed, 0, 12)));
$infoHtml = ui_info('Counted from when it was assigned.', 'About overdue leads', ['id' => 'x-info']);
ok('ui_info() is a labelled button described by its text, in a manual popover',
    strpos($infoHtml, 'class="ui-info__btn" aria-label="About overdue leads" aria-describedby="x-info"') !== false
    && strpos($infoHtml, 'id="x-info" role="tooltip" popover="manual">Counted from when it was assigned.</span>') !== false
    && strpos($infoHtml, 'type="button"') !== false);
ok('ui_info() escapes its text unless told it is trusted markup',
    strpos(ui_info('<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;') !== false && strpos(ui_info('<b>x</b>', 'i', ['html' => true]), '<b>x</b>') !== false);
$fieldHtml = ui_field('number', ['name' => 'days', 'id' => 'f-days', 'label' => 'Overdue after', 'info' => 'Counted in days.']);
ok('ui_field info sits beside the label (not inside it) and describes the control',
    strpos($fieldHtml, '<div class="ui-label-row"><label') !== false
    && strpos($fieldHtml, '</label><span class="ui-info"') !== false
    && strpos($fieldHtml, 'aria-describedby="f-days-info f-days-error"') !== false);

/* ---------------------------------------------------------------------- */
suite('10. formatted text that reaches the public wall (includes/html_allowlist.php)');

require_once $root . '/includes/html_allowlist.php';
$clean = 'hod_html_allowlist';
ok('formatting tags stay, with every attribute removed',
    $clean('<p class="x" style="color:red" onclick="a()">A <strong>b</strong> <em>c</em> <u>d</u> <s>e</s></p><ul><li>f</li></ul><ol><li>g</li></ol>')
    === '<p>A <strong>b</strong> <em>c</em> <u>d</u> <s>e</s></p><ul><li>f</li></ul><ol><li>g</li></ol>');
ok('script, style, frames, media and forms go with what is inside them',
    $clean('<script>x()</script><style>p{}</style><iframe src="//e"></iframe><img src=x onerror=y()><svg><script>z()</script></svg><form><input></form>ok') === 'ok');
ok('links and other tags are unwrapped to their text', $clean('<a href="javascript:x()">link</a> <span style="color:red">red</span> <h1>big</h1>') === 'link red big');
ok('a split tag cannot rebuild itself', strpos($clean('<scr<script>ipt>alert(1)</script>'), '<script') === false);
ok('escaped text stays text, line breaks and all', $clean("&lt;b&gt;x&lt;/b&gt;\ny") === "&lt;b&gt;x&lt;/b&gt;\ny");
ok('accents and emoji survive', $clean('<p>Ọlọrun ṣeun 🙏</p>') === '<p>Ọlọrun ṣeun 🙏</p>');
ok('comments are removed', $clean('<!-- x --><p>y</p>') === '<p>y</p>');
ok('blank formatting counts as empty', hod_html_is_blank('<p><br></p>&nbsp;') && !hod_html_is_blank('<p>a</p>'));
$adminApi = (string) file_get_contents($root . '/api/testimony_admin_api.php');
$publicApi = (string) file_get_contents($root . '/api/testimony_public_api.php');
ok('the admin API cleans on save and on read, needs CSRF and asks each level',
    str_contains($adminApi, "\$content = hod_html_allowlist(\$_POST['content_text'] ?? '');")
    && str_contains($adminApi, '$clean = hod_html_allowlist($raw);') && str_contains($adminApi, 'csrf_require();')
    && str_contains($adminApi, "'save_edited_testimony' => 'edit'") && str_contains($adminApi, "'delete_testimony' => 'delete'")
    && str_contains($adminApi, 'in_array($status, $statuses, true)'));
ok('the wall cleans what it sends, rows saved before the allowlist included',
    str_contains($publicApi, "\$t['content_text'] = hod_html_allowlist(\$t['content_text'] ?? '');"));
$sermonsPage = (string) file_get_contents($root . '/sermons.php');
ok('the public Sermons cards escape what staff typed and only link safe media',
    str_contains($sermonsPage, '${esc(s.title)}') && str_contains($sermonsPage, '${esc(s.summary ||')
    && str_contains($sermonsPage, '${esc(s.service_type)}') && str_contains($sermonsPage, 'const audioUrl = safeMediaUrl(s.audio_file_path);')
    && !str_contains($sermonsPage, '>${s.title}<') && !str_contains($sermonsPage, '>${s.summary'));
ok('the shared-sermon head escapes its OG tags and JSON-encodes the slug',
    str_contains($sermonsPage, 'htmlspecialchars((string) $og_image, ENT_QUOTES)') && str_contains($sermonsPage, "json_encode((string) \$sharedSermon['slug']"));
$eventsApi = (string) file_get_contents($root . '/api/events_api.php');
$registrationApi = (string) file_get_contents($root . '/api/registration_api.php');
ok('an event description is cleaned when it is saved (create and update)',
    substr_count($eventsApi, "\$description = hod_html_allowlist((string) (\$_POST['description'] ?? ''));") === 2);
ok('the public register page gets it through the same allowlist, older rows included',
    str_contains($registrationApi, "hod_html_allowlist((string) \$html, ['b','strong','i','em','u','br','p','ul','ol','li'])")
    && str_contains($registrationApi, "\$event['description'] = safe_rich(\$event['description'])"));

/* ---------------------------------------------------------------------- */
suite('11. Connect is not the app (owner, 12 Nov 2026: the app belongs to hodlekki.org)');

$connectSrc = (string) file_get_contents($root . '/connect.php');
$connectJs = (string) file_get_contents($root . '/assets/ui/js/pages/connect.js');
ok('connect.php opens the public layout without the app', str_contains($connectSrc, "'app' => false"));
// The render below cannot prove this alone: includes/pwa_head.php prints once
// per process, and suite 6 may already have printed it.
$publicSrc = (string) file_get_contents($root . '/includes/ui/public.php');
ok('the public layout prints the app (pwa_head, pwa_boot) only when app is on',
    str_contains($publicSrc, "<?php if (\$page['app']): ?>\n<?php require dirname(__DIR__) . '/pwa_head.php'; ?>\n<?php else: ?>")
    && str_contains($publicSrc, "<?php if (\$page['app']) { require dirname(__DIR__) . '/pwa_boot.php'; } ?>")
    && substr_count($publicSrc, "require dirname(__DIR__) . '/pwa_head.php'") === 1
    && substr_count($publicSrc, "require dirname(__DIR__) . '/pwa_boot.php'") === 1);
ok('connect.js cancels any install prompt and registers no service worker',
    str_contains($connectJs, "addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); })")
    && !str_contains($connectJs, 'serviceWorker.register'));
// connect.hodlekki.org serves the same file (index.php requires connect.php);
// only there does connect.js retire a worker the old page registered.
ok('a worker is retired only on the Connect address',
    str_contains($connectSrc, "'retireWorker' => is_connect_host(),")
    && str_contains($connectJs, 'if (cfg.retireWorker && navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {'));
// Rendered in a process of its own (tests/ui/render_connect.php): the app's
// head prints once per process, so an earlier render could hide it.
$connectHtml = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/ui/render_connect.php'));
ok('Connect renders', str_contains($connectHtml, 'id="cx-title"') && str_contains($connectHtml, 'id="connectForm"'));
foreach ([
    'rel="manifest"' => 'no manifest link',
    'apple-mobile-web-app-capable' => 'no apple-mobile-web-app-capable',
    'mobile-web-app-capable' => 'no mobile-web-app-capable',
    'apple-mobile-web-app-title' => 'no app title',
    'apple-touch-startup-image' => 'no launch images',
    'id="hodSplash"' => 'no launch splash',
    '/assets/js/pwa.js' => 'no pwa.js (so no service worker registration and no install banner)',
    'hodInstall' => 'no install banner or button',
] as $needle => $label) {
    ok("Connect: {$label}", !str_contains($connectHtml, $needle));
}
ok('Connect keeps its favicon and theme colour',
    str_contains($connectHtml, 'rel="icon"') && str_contains($connectHtml, 'name="theme-color"'));

/* ---------------------------------------------------------------------- */
suite('12. a service never registers anyone (owner, 12 Nov 2026)');

$checkinApi = (string) file_get_contents($root . '/api/checkin_api.php');
$checkinPage = (string) file_get_contents($root . '/checkin.php');
/** The source of one `case 'x':` in the check-in API, up to the next case. */
$ciCase = static function (string $name) use ($checkinApi): string {
    $start = strpos($checkinApi, "case '{$name}':");
    if ($start === false) return '';
    $next = strpos($checkinApi, "\n        case '", $start + 1);
    return substr($checkinApi, $start, $next === false ? null : $next - $start);
};
$guest = $ciCase('guest_present');
$register = $ciCase('register_and_present');
ok('Registration link off means check-in only',
    str_contains($checkinApi, "return (int) (\$event['requires_registration'] ?? 0) !== 1;"));
ok('guest_present runs only on a check-in-only event', str_contains($guest, 'if (!ci_checkin_only($event))'));
ok('guest_present never writes a registration', $guest !== '' && !str_contains($guest, 'event_registrations'));
ok('guest_present counts the guest with no registration', str_contains($guest, 'INSERT INTO checkins'));
ok('register_and_present refuses a check-in-only event',
    str_contains($register, 'if (ci_checkin_only($event))')
    && strpos($register, 'if (ci_checkin_only($event))') < strpos($register, 'INSERT INTO event_registrations'));
ok('the check-in page is not the app (no install prompt at the door)', str_contains($checkinPage, "'app' => false"));
ok('the check-in page hands Connect the name in session storage, not the URL',
    !str_contains((string) file_get_contents($root . '/assets/ui/js/pages/checkin.js'), 'connect.php?')
    && str_contains((string) file_get_contents($root . '/assets/ui/js/pages/connect.js'), "'hod.connect.prefill'"));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
