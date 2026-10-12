<?php
// /tests/pwa/function_calls_test.php
/**
 * Every function the PWA/push code calls must exist.
 *
 * php -l only parses. A call to a helper that was renamed, never written, or
 * lives in a file nobody requires sails through CI and becomes a fatal error
 * on the live site — and here that fatal would land inside a shutdown hook
 * that runs on every POST the platform handles.
 *
 * The scanner is the one the Special Events suite uses
 * (tests/special_events/function_calls_test.php); it is loaded rather than
 * copied, so the two can never drift.
 *
 * Run me through tests/pwa/run.php.
 */

$repoRoot = dirname(__DIR__, 2);

// That file both defines the scanner and runs its own assertions with the SE
// harness's helpers, so only the functions are taken from it.
if (!function_exists('se_fc_declared')) {
    $scanner = (string) file_get_contents($repoRoot . '/tests/special_events/function_calls_test.php');
    $cut     = strpos($scanner, 'echo "    the scanner works');
    eval('?>' . ($cut === false ? $scanner : substr($scanner, 0, $cut)));
}

suite('every function the PWA code calls exists');

$declared = se_fc_declared(array_map('file_get_contents', array_merge(
    se_fc_php_files($repoRoot . '/includes'),
    se_fc_php_files($repoRoot . '/api'),
    se_fc_php_files($repoRoot . '/cron'),
    se_fc_php_files($repoRoot . '/bin'),
    glob($repoRoot . '/*.php') ?: []
)));

$pwaFiles = array_filter([
    $repoRoot . '/includes/push_crypto.php',
    $repoRoot . '/includes/push_helpers.php',
    $repoRoot . '/includes/push_dispatch.php',
    $repoRoot . '/includes/pwa_head.php',
    $repoRoot . '/includes/pwa_boot.php',
    $repoRoot . '/api/push_api.php',
    $repoRoot . '/api/push_admin_api.php',
    $repoRoot . '/modules/alerts/index.php',
    $repoRoot . '/cron/push_worker.php',
    $repoRoot . '/bin/build_pwa_icons.php',
    $repoRoot . '/bin/generate_vapid_keys.php',
    $repoRoot . '/manifest.php',
], 'is_file');

check('every PWA source file is on disk', 12, count($pwaFiles));

$problems = [];
foreach ($pwaFiles as $file) {
    foreach (se_fc_unknown_calls((string) file_get_contents($file), $declared) as [$name, $line]) {
        // Both only exist under a FastCGI/LiteSpeed SAPI, never under the CLI
        // this test runs on. push_dispatch.php guards each with
        // function_exists() before calling it.
        if ($name === 'litespeed_finish_request' || $name === 'fastcgi_finish_request') {
            continue;
        }
        $problems[] = substr($file, strlen($repoRoot) + 1) . ':' . $line . ' ' . $name . '()';
    }
}
check('no call to an undefined function', [], $problems);

suite('the dispatcher is wired into every request');
$db = (string) file_get_contents($repoRoot . '/includes/db.php');
ok('includes/db.php requires the push helpers', str_contains($db, "push_helpers.php"));
ok('includes/db.php arms the dispatcher', str_contains($db, 'push_register_dispatcher($pdo)'));

suite('the app shell carries the PWA');
$header = (string) file_get_contents($repoRoot . '/includes/header.php');
ok('header.php includes the head partial', str_contains($header, "pwa_head.php"));
ok('header.php includes the launch screen', str_contains($header, "pwa_boot.php"));
ok('the notification panel can open alert settings', str_contains($header, 'data-hod-alerts'));

suite('every icon the manifest promises exists');
ob_start();
require $repoRoot . '/manifest.php';
$manifest = json_decode((string) ob_get_clean(), true);

ok('the manifest is valid JSON', is_array($manifest));
check('it is named for the church', 'HOD Lekki', $manifest['name'] ?? null);
check('it installs as a standalone app', 'standalone', $manifest['display'] ?? null);
check('its scope is the whole site', '/', $manifest['scope'] ?? null);
ok('it declares a maskable icon', in_array('maskable', array_column($manifest['icons'] ?? [], 'purpose'), true));

$missing = [];
foreach ($manifest['icons'] ?? [] as $entry) {
    $path = $repoRoot . parse_url($entry['src'], PHP_URL_PATH);
    if (!is_file($path)) {
        $missing[] = $entry['src'];
    }
}
foreach ($manifest['shortcuts'] ?? [] as $shortcut) {
    foreach ($shortcut['icons'] ?? [] as $entry) {
        $path = $repoRoot . parse_url($entry['src'], PHP_URL_PATH);
        if (!is_file($path)) {
            $missing[] = $entry['src'];
        }
    }
}
check('no manifest icon is missing from assets/pwa', [], array_values(array_unique($missing)));

suite('every iOS launch image the head partial promises exists');
$_SESSION = ['user_id' => 1, 'first_name' => 'Test'];
ob_start();
require $repoRoot . '/includes/pwa_head.php';
$head = (string) ob_get_clean();

preg_match_all('#/assets/pwa/([a-z0-9\-]+\.png)#i', $head, $matches);
$referenced = array_values(array_unique($matches[1]));
ok('the head references a full launch-image set', count($referenced) > 30, count($referenced) . ' files');

$absent = array_values(array_filter($referenced, static fn($file) => !is_file($repoRoot . '/assets/pwa/' . $file)));
check('no referenced artwork is missing', [], $absent);

ok('the manifest is linked', str_contains($head, 'rel="manifest"'));
ok('iOS is told it is a web app', str_contains($head, 'apple-mobile-web-app-capable'));
ok('the client runtime is loaded', str_contains($head, '/assets/js/pwa.js'));

suite('every surface that should carry the app does');
// A page is only part of the installable app if it has BOTH partials: the
// head one alone leaves a launch screen that never renders, and the boot one
// alone leaves markup with no stylesheet.
foreach ([
    'includes/header.php'  => 'every ERP screen',
    'auth/login.php'       => 'the sign-in page',
    'connect.php'          => 'Connect',
    'sermons.php'          => 'Sermons',
    'testimonies.php'      => 'Testimonies',
    'register.php'         => 'Event registration',
    'checkin.php'          => 'Check-in',
    'parent_portal.php'    => 'Parent portal',
    'live_radio.php'       => 'Live radio',
    'verify.php'           => 'Verify',
    'idi_mobilization.php' => 'IDI mobilisation',
    'reach.php'            => 'Reach capture',
    'assimilation.php'     => 'Assimilation volunteers',
    'e/index.php'          => 'Special Events public pages',
] as $file => $label) {
    $source = (string) @file_get_contents($repoRoot . '/' . $file);
    ok($label . ' has the head partial', str_contains($source, 'pwa_head.php'), $file);
    ok($label . ' has the launch screen', str_contains($source, 'pwa_boot.php'), $file);
}

suite('the Special Events pages stay inside their CSP');
$eRouter = (string) file_get_contents($repoRoot . '/e/index.php');
// §19.7 sets script-src/style-src to 'self' plus a nonce. An unnonced inline
// block is dropped silently — the page still renders, so nothing fails until
// somebody notices the preloader never appears.
ok('the router passes its CSP nonce to the partial', str_contains($eRouter, '$pwaNonce = $nonce;'));
ok('and marks those pages quiet', str_contains($eRouter, '$pwaQuiet = true;'));

// pwa_head.php renders once per process (PWA_HEAD_RENDERED), and this suite
// already rendered the plain variant above — so the nonced one is rendered in
// a fresh process rather than silently coming back empty.
$noncedHead = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        '$_SESSION = ["user_id" => 1, "first_name" => "Test"];'
        . ' $pwaNonce = "test-nonce-value";'
        . ' $pwaQuiet = true;'
        . ' require ' . var_export($repoRoot . '/includes/pwa_head.php', true) . ';'
    ) . ' 2>&1'
);
ok('the nonced render produced a page head', str_contains($noncedHead, 'rel="manifest"'));

check('the inline style carries the nonce', 1, substr_count($noncedHead, '<style nonce="test-nonce-value">'));
ok('the inline boot script carries the nonce', str_contains($noncedHead, '<script nonce="test-nonce-value">'));
ok('the runtime script carries the nonce', str_contains($noncedHead, '<script nonce="test-nonce-value" src="/assets/js/pwa.js'));
ok('no inline block is left unnonced', !preg_match('/<(script|style)(?![^>]*\bnonce=)[^>]*>/', $noncedHead));
ok('quiet mode reaches the client', str_contains($noncedHead, 'quiet: true'));

suite('quiet pages never interrupt');
$runtime = (string) file_get_contents($repoRoot . '/assets/js/pwa.js');
ok('quiet is read from the config', str_contains($runtime, 'CFG.quiet === true'));
foreach ([
    'the install banner'  => 'if (!QUIET && !store.snoozed(\'install-dismissed\'',
    'the push opt-in'     => 'if (QUIET || !CFG.signedIn',
    'pull to refresh'     => 'if (QUIET || !env.standalone',
    'the iOS nudge'       => 'if (!QUIET && env.iOS',
] as $what => $guard) {
    ok($what . ' is suppressed', str_contains($runtime, $guard));
}
ok('a subtree can opt out of the pull gesture', str_contains($runtime, "closest('[data-no-pull]')"));

suite('the Alerts Centre is gated like the Security Centre');
$alerts = (string) file_get_contents($repoRoot . '/modules/alerts/index.php');
$adminApi = (string) file_get_contents($repoRoot . '/api/push_admin_api.php');
// Both check the live roles table, never $_SESSION['active_role'], which the
// member can switch from their own profile screen.
ok('the page checks the live roles table', str_contains($alerts, 'security_is_admin($pdo)'));
ok('the endpoint checks it too', str_contains($adminApi, 'security_is_admin($pdo)'));
// The naive substring check fails on the comments in those files, which say
// in words what the code must never do. php_strip_whitespace() drops comments,
// so what is left is only what actually runs.
foreach (['modules/alerts/index.php', 'api/push_admin_api.php'] as $gated) {
    ok($gated . ' never reads the switchable session role',
        !str_contains(php_strip_whitespace($repoRoot . '/' . $gated), 'active_role'));
}
ok('broadcasts are written to the audit log', str_contains($adminApi, "security_log(\$pdo, 'push_broadcast'"));
ok('switch changes are audited too', str_contains($adminApi, "security_log(\$pdo, 'push_switch'"));
// The composer writes notifications rather than pushing directly, so quiet
// hours and opt-outs are honoured by the normal fan-out.
ok('a broadcast goes through system_notifications',
    str_contains($adminApi, 'INSERT INTO system_notifications'));
ok('and its link cannot leave this site',
    str_contains($adminApi, "str_starts_with(\$link, '//')"));

suite('the service worker keeps member data out of the cache');
$sw = (string) file_get_contents($repoRoot . '/sw.js');
foreach (['/api/', '/auth/', '/live/', '/e/', '/webhook/', '/uploads/'] as $prefix) {
    ok("{$prefix} is never cached", str_contains($sw, "'{$prefix}'"));
}
ok('navigations are not served from the cache', str_contains($sw, "request.mode === 'navigate'"));
ok('the worker answers push events', str_contains($sw, "addEventListener('push'"));
ok('and re-registers a rotated subscription', str_contains($sw, "addEventListener('pushsubscriptionchange'"));
