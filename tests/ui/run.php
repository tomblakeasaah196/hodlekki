<?php
// /tests/ui/run.php
/**
 * The UI foundation's tests (docs/UI.md). No database server, no network.
 *
 *   php tests/ui/run.php
 *
 * 1. The navigation registry: unique keys, real files, icons that exist.
 * 2. ui_nav_can_access() gives EXACTLY the answers the old hard-coded
 *    sidebar in includes/header.php gave, for every combination of roles,
 *    department seats and module flags below. The permissions matrix will
 *    replace that function; until it does, nobody's menu may change.
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
suite('2. access parity with the old sidebar (includes/header.php before the foundation)');

/**
 * The old sidebar, transcribed rule for rule (git show 61b8516:includes/header.php).
 * Each value is what decided whether the link rendered, including the
 * section wrapper it sat inside.
 */
function old_sidebar(array $c): array
{
    $has = static function (array $roles, array $depts = []) use ($c): bool {
        if ($c['is_super_admin']) {
            return true;
        }
        if (array_intersect($roles, $c['roles'])) {
            return true;
        }
        return $depts !== [] && (bool) array_intersect($depts, $c['dept_ids']);
    };
    $P = ['Resident_Pastor', 'Assoc_Pastor'];
    $PD = ['Resident_Pastor', 'Assoc_Pastor', 'Director'];
    $SMS = ['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
    $canDepartments = $has($P) || (bool) array_intersect($c['dept_roles'], ['Director', 'HOD', 'Sub_Unit_Head']);

    $units = $has($P, [1, 3, 4, 7, 11]) || $c['se_nav'];
    $core = $has(array_merge($PD, $SMS), [1, 3, 13]);
    $stew = $has($PD, []);

    return [
        'dashboard' => $has($PD, [1]),
        'member_portal' => true, 'profile' => true, 'bible' => true, 'hymnal' => true, 'regions' => true, 'academy' => true,
        'idi' => $has($P, [1]),
        'congregation' => $has($P, [1]),
        'reach' => $has($P, [1, 8]),
        'embrace' => $has($P, [1, 2]),
        'assimilation' => $c['assim_nav'],
        'charis' => $has($P, [1, 10]),
        'junior_church' => $units && $has($P, [1, 11]),
        'river_of_life' => $units && $has($P, [1, 4]),
        'envision' => $units && $has($P, [1, 3]),
        'special_events' => $units && $c['se_nav'],
        'zoe' => $units && $has($P, [1, 7]),
        'departments' => $core && $canDepartments,
        'tribes' => $core && $has($P, [1, 13]),
        'events' => $core && $has($PD, [1]),
        'sms_studio' => $core && $has($SMS, []),
        'assets' => $core && $has($PD, [1, 3]),
        'pastoral' => $core && $has($PD, [1]),
        'announcements' => $core && $has($PD, [1]),
        'testimonies' => $core && $has($PD, [1]),
        'finance' => $stew,
        'requisition' => $stew && $has(['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'], []),
        'roles' => $stew && $c['is_super_admin'],
        'security' => $stew && $has(['Resident_Pastor'], []),
        'alerts' => $stew && $has(['Resident_Pastor'], []),
    ];
}

$roleSets = [[], ['Church_Member'], ['Resident_Pastor'], ['Assoc_Pastor'], ['Director'], ['HOD'], ['Sub_Unit_Head'],
    ['Director', 'HOD'], ['Super_Admin'], ['Church_Member', 'Sub_Unit_Head']];
$deptSets = [[], [1], [2], [3], [4], [7], [8], [10], [11], [13], [2, 8], [3, 13], [99]];
$deptRoleSets = [[], ['Member'], ['HOD'], ['Director'], ['Sub_Unit_Head']];
$checked = 0;
$mismatch = [];
foreach ($roleSets as $roles) {
    foreach ($deptSets as $depts) {
        foreach ($deptRoleSets as $deptRoles) {
            foreach ([[false, false], [true, false], [false, true]] as [$assim, $se]) {
                $ctx = [
                    'user_id' => 1, 'roles' => $roles, 'is_super_admin' => in_array('Super_Admin', $roles, true),
                    'dept_ids' => $depts, 'dept_roles' => $deptRoles, 'assim_nav' => $assim, 'assim_manager' => false, 'se_nav' => $se,
                ];
                foreach (old_sidebar($ctx) as $key => $expected) {
                    $checked++;
                    if (ui_nav_can_access($key, $ctx) !== $expected) {
                        $mismatch[] = $key . ' for ' . json_encode([$roles, $depts, $deptRoles, $assim, $se]);
                    }
                }
            }
        }
    }
}
ok("{$checked} decisions match the old sidebar", $mismatch === [], implode('; ', array_slice($mismatch, 0, 5)));
ok('the design system page is for Super Admins only',
    ui_nav_can_access('ui_guide', ['roles' => ['Super_Admin'], 'is_super_admin' => true, 'dept_ids' => [], 'dept_roles' => []])
    && !ui_nav_can_access('ui_guide', ['roles' => ['Resident_Pastor'], 'is_super_admin' => false, 'dept_ids' => [1], 'dept_roles' => []]));
ok('System Health is for Super Admins only (monitor_user_can() today)',
    ui_nav_can_access('system_health', ['roles' => ['Super_Admin'], 'is_super_admin' => true, 'dept_ids' => [], 'dept_roles' => []])
    && !ui_nav_can_access('system_health', ['roles' => ['Resident_Pastor'], 'is_super_admin' => false, 'dept_ids' => [1], 'dept_roles' => []]));
ok('an unknown key is refused', !ui_nav_can_access('nonexistent', ['roles' => ['Super_Admin'], 'is_super_admin' => true, 'dept_ids' => [], 'dept_roles' => []]));
$covered = array_keys(old_sidebar(['roles' => [], 'is_super_admin' => false, 'dept_ids' => [], 'dept_roles' => [], 'assim_nav' => false, 'se_nav' => false]));
$untested = array_diff($keys, $covered, ['ui_guide', 'system_health']);
ok('every registry entry is covered by the parity matrix', $untested === [], implode(', ', $untested));

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
    function assim_is_manager($pdo, $uid, $role): bool { return false; }
    function assim_is_team_member($pdo, $uid): bool { return false; }
    function se_nav_visible($pdo, $uid, $roles): bool { return false; }
    function se_session_roles(): array { return []; }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE user_departments (user_id INT, department_id INT, role_in_dept TEXT, is_active INT)');
    $pdo->exec("INSERT INTO user_departments VALUES (7, 2, 'HOD', 1)");
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
    ok('a department seat without a pastoral role does not see Congregation', strpos($html, '/modules/congregation/index.php') === false);
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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
