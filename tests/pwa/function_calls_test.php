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
    $repoRoot . '/cron/push_worker.php',
    $repoRoot . '/bin/build_pwa_icons.php',
    $repoRoot . '/bin/generate_vapid_keys.php',
    $repoRoot . '/manifest.php',
], 'is_file');

check('every PWA source file is on disk', 10, count($pwaFiles));

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

suite('the service worker keeps member data out of the cache');
$sw = (string) file_get_contents($repoRoot . '/sw.js');
foreach (['/api/', '/auth/', '/live/', '/e/', '/webhook/', '/uploads/'] as $prefix) {
    ok("{$prefix} is never cached", str_contains($sw, "'{$prefix}'"));
}
ok('navigations are not served from the cache', str_contains($sw, "request.mode === 'navigate'"));
ok('the worker answers push events', str_contains($sw, "addEventListener('push'"));
ok('and re-registers a rotated subscription', str_contains($sw, "addEventListener('pushsubscriptionchange'"));
