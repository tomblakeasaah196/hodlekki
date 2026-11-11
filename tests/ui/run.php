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

$allowedCdn = array_values(array_filter(array_map('trim', file($root . '/tests/ui/tailwind_cdn_allowlist.txt') ?: []),
    static fn ($l) => $l !== '' && $l[0] !== '#'));
sort($tailwindCdn);
sort($allowedCdn);
$newCdn = array_diff($tailwindCdn, $allowedCdn);
$gone = array_diff($allowedCdn, $tailwindCdn);
ok('no new page loads the Tailwind CDN', $newCdn === [], implode(', ', $newCdn));
ok('the allow-list names only pages that still use it (remove the ones you rebuilt)', $gone === [], implode(', ', $gone));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
