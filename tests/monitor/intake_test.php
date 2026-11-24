<?php
// /tests/monitor/intake_test.php — what browsers send, checked before anything is kept.

$now = monitor_test_clock('2026-11-11 10:00:00');
$server = ['HTTP_HOST' => 'hodlekki.org', 'REMOTE_ADDR' => '102.89.3.4',
           'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36'];
$session = ['user_id' => 3, 'active_role' => 'Church_Member'];

// ---- Tokens
$t = monitor_intake_token();
ok('a token looks like day.hmac', (bool) preg_match('/^\d{8}\.[0-9a-f]{24}$/', $t));
ok('today\'s token is valid', monitor_intake_token_valid($t, $now));
ok('…tomorrow and the day after (a tab left open)', monitor_intake_token_valid($t, $now + 86400) && monitor_intake_token_valid($t, $now + 2 * 86400));
ok('…not three days later', !monitor_intake_token_valid($t, $now + 3 * 86400));
ok('a forged token is refused', !monitor_intake_token_valid('20261111.000000000000000000000000', $now));

// ---- Beacons
$beacon = fn (array $p) => monitor_intake_beacon((string) json_encode($p + ['t' => $t, 'v' => 1, 'page' => '/modules/events/index.php', 'dev' => 'phone']), $server, $session, $now);
eq('not JSON: refused', 403, monitor_intake_beacon('nope', $server, $session, $now)['status']);
eq('no token: refused', 403, monitor_intake_beacon(json_encode(['errors' => []]), $server, $session, $now)['status']);
eq('too big: refused', 413, monitor_intake_beacon(str_repeat('x', 70000), $server, $session, $now)['status']);

$r = $beacon(['errors' => [
    ['type' => 'error', 'msg' => 'TypeError: Cannot read properties of undefined (reading \'name\')', 'src' => 'https://hodlekki.org/assets/js/events.js?v=12',
     'line' => 40, 'col' => 7, 'stack' => "TypeError: x\n    at render (https://hodlekki.org/assets/js/events.js?v=12:40:7)"],
    ['type' => 'error', 'msg' => 'Script error.', 'src' => ''],
    ['type' => 'error', 'msg' => 'x is undefined', 'src' => 'chrome-extension://abc/inject.js'],
    ['type' => 'resource', 'msg' => 'Could not load https://hodlekki.org/assets/ui/app.css?v=9', 'src' => 'https://hodlekki.org/assets/ui/app.css?v=9', 'tag' => 'link'],
    ['type' => 'rejection', 'msg' => 'Failed to fetch', 'stack' => ''],
]]);
eq('a good beacon is accepted', [true, 204], [$r['ok'], $r['status']]);
eq('three errors kept: noise from extensions and bare "Script error." dropped', 3, count($r['events']));
eq('the error\'s class is read from its message', 'TypeError', $r['events'][0]['exception_class']);
eq('the script is its path, no version query', '/assets/js/events.js', $r['events'][0]['file']);
has('stack frames keep line and column, lose the query', (string) $r['events'][0]['trace_text'], '/assets/js/events.js:40:7');
eq("a failed load of a file that is on the server: the visitor's connection, a notice in its own group",
    ["Could not load /assets/ui/app.css (visitor's connection)", 'notice', 'ConnectionDropped', '/assets/ui/app.css'],
    [$r['events'][1]['title'], $r['events'][1]['level'], $r['events'][1]['exception_class'], $r['events'][1]['file']]);
eq('an unhandled rejection is its own kind', 'UnhandledRejection', $r['events'][2]['exception_class']);
eq('the role comes from the session', 'Church_Member', $r['events'][0]['request']['role']);

// An SVG <use> whose sprite failed: its href is an object, which JSON sends as {}.
// It used to be cast to the word "Array" and filed as "Could not load /Array".
$svg = $beacon(['errors' => [
    ['type' => 'resource', 'msg' => 'Could not load [object SVGAnimatedString]', 'src' => [], 'tag' => 'use'],
    ['type' => 'resource', 'msg' => 'Could not load https://hodlekki.org/assets/ui/icons.svg?v=e3ba#search', 'src' => 'https://hodlekki.org/assets/ui/icons.svg?v=e3ba#search', 'tag' => 'use'],
]]);
eq('a field sent as an object is not filed as /Array', ['Could not load a file', ''], [$svg['events'][0]['title'], $svg['events'][0]['file']]);
eq('a sprite that failed is named by its path', "Could not load /assets/ui/icons.svg (visitor's connection)", $svg['events'][1]['title']);
$gone = $beacon(['errors' => [
    ['type' => 'resource', 'msg' => 'Could not load https://hodlekki.org/assets/se/js/portal/gone.js?v=3', 'src' => 'https://hodlekki.org/assets/se/js/portal/gone.js?v=3', 'tag' => 'script'],
    ['type' => 'resource', 'msg' => 'Could not load https://hodlekki.org/assets/../../etc/passwd', 'src' => 'https://hodlekki.org/assets/../../etc/passwd', 'tag' => 'img'],
    ['type' => 'resource', 'msg' => 'Could not load https://cdn.example.com/lib.js', 'src' => 'https://cdn.example.com/lib.js', 'tag' => 'script'],
]]);
eq('a file that is NOT on the server stays a warning (a deploy lost it, a wrong path)', ['warning', 'ResourceError', 'Could not load /assets/se/js/portal/gone.js'],
    [$gone['events'][0]['level'], $gone['events'][0]['exception_class'], $gone['events'][0]['title']]);
eq("a path that climbs out, or another site's file, is never on the server", ['warning', 'warning'], [$gone['events'][1]['level'], $gone['events'][2]['level']]);
eq('the two kinds group apart, so a dropped connection never reopens a real missing file', true,
    monitor_fingerprint($gone['events'][0] + ['source' => 'browser', 'route' => '/']) !== monitor_fingerprint(['message' => "Could not load /assets/se/js/portal/gone.js (visitor's connection)", 'exception_class' => 'ConnectionDropped'] + $gone['events'][0] + ['source' => 'browser', 'route' => '/']));

// Problem #117: Cloudflare's edge injects its analytics beacon; the strict CSP
// refuses it on purpose. Not ours, not seen by anyone: dropped. Any other
// outside script that fails is still kept.
$cf = $beacon(['errors' => [
    ['type' => 'resource', 'msg' => 'Could not load https://static.cloudflareinsights.com/beacon.min.js/vcd15cbe7772f49c399c6a5babf22c1241717689176015', 'src' => 'https://static.cloudflareinsights.com/beacon.min.js/vcd15cbe7772f49c399c6a5babf22c1241717689176015', 'tag' => 'script'],
    ['type' => 'resource', 'msg' => 'Could not load https://static.cloudflareinsights.com.example.net/x.js', 'src' => 'https://static.cloudflareinsights.com.example.net/x.js', 'tag' => 'script'],
]]);
eq("Cloudflare's injected beacon is dropped; a look-alike host is kept", ['Could not load static.cloudflareinsights.com.example.net/x.js'],
    array_column($cf['events'], 'title'));

// Problems #101 / #102: a page-to-page view transition the browser skipped or
// aborted is the browser's own business, on any page (/connect has no pwa_head guard).
$vt = $beacon(['errors' => [
    ['type' => 'rejection', 'msg' => 'InvalidStateError: Transition was aborted because of invalid state. ViewTransition opt-in disabled'],
    ['type' => 'rejection', 'msg' => 'InvalidStateError: Transition was aborted because of invalid state. Page already revealed'],
    ['type' => 'rejection', 'msg' => 'AbortError: Transition was skipped'],
    ['type' => 'rejection', 'msg' => 'InvalidStateError: The object is in an invalid state.'],
    ['type' => 'error', 'msg' => 'Uncaught AbortError: Transition was skipped', 'src' => '/assets/js/app.js'],
]]);
eq('skipped and aborted view transitions are dropped; other InvalidStateErrors and thrown errors are kept',
    ['InvalidStateError: The object is in an invalid state.', 'Uncaught AbortError: Transition was skipped'],
    array_map(fn ($e) => $e['message'], $vt['events']));

// #105: an object in any field raises no PHP warning (it was "Array to string conversion").
$warned = [];
set_error_handler(function (int $no, string $msg) use (&$warned) { $warned[] = $msg; return true; });
$obj = $beacon(['errors' => [
    ['type' => 'resource', 'msg' => 'Could not load x', 'src' => new stdClass(), 'tag' => ['use']],
    ['type' => 'error', 'msg' => ['not' => 'text'], 'src' => '/a.js'],
    ['type' => 'error', 'msg' => 'TypeError: x', 'src' => '/a.js', 'stack' => ['a', 'b']],
]]);
restore_error_handler();
eq('a field sent as an object raises no PHP warning', [], $warned);
eq('…a message that is not text is dropped, the rest kept without a stack', [2, 'TypeError', null],
    [count($obj['events']), $obj['events'][1]['exception_class'] ?? null, $obj['events'][1]['trace_text'] ?? null]);

$many = $beacon(['errors' => array_map(fn ($i) => ['type' => 'error', 'msg' => 'E' . $i, 'src' => '/a.js'], range(1, 30))]);
eq('at most 10 errors a beacon', 10, count($many['events']));

$db = monitor_test_db();
monitor_store_all($db, $r['events']);
$row = $db->query("SELECT * FROM monitor_error_groups WHERE kind = 'js' AND exception_class = 'TypeError'")->fetch();
eq('a browser error is stored with its page', '/modules/events/', $row['route'] ?? null);
eq('…as a browser error', 'browser', $row['source'] ?? null);

// ---- Speed
$s = $beacon(['rum' => ['lcp' => 2100.4, 'inp' => 180, 'cls' => 42, 'ttfb' => 650, 'fcp' => 1200, 'conn' => '4g', 'nav' => 'navigate']]);
$rumRow = monitor_rum_row($s['rum']['rum'], $s['rum']['page'], $s['rum']['device'], $s['rum']['at']);
eq('a speed report becomes a row', ['/modules/events/', 'phone', 2100, 180, 42, '4g'], [$rumRow['page'], $rumRow['device'], $rumRow['lcp'], $rumRow['inp'], $rumRow['cls'], $rumRow['conn']]);
$bad = monitor_rum_row(['lcp' => -5, 'inp' => 999999, 'conn' => 'wired<script>'], '/x', 'phone', $now);
ok('impossible values are dropped, and a report with nothing usable is refused', $bad === null);
eq('a page under /e/ is grouped by route', '/e/:event/checkin', monitor_rum_row(['lcp' => 1000], '/e/chara-2026/checkin', 'phone', $now)['page']);

for ($i = 1; $i <= 40; $i++) {
    monitor_rum_store($db, [monitor_rum_row(['lcp' => $i * 100, 'inp' => 100, 'cls' => 10, 'ttfb' => 500], '/index.php', $i % 2 ? 'phone' : 'desktop', $now - 3600)]);
}
monitor_rum_rollup($db, $now);
$daily = $db->query("SELECT * FROM monitor_rum_daily WHERE page = '/' AND device = 'phone' AND metric = 'lcp'")->fetch();
// Phones got 100, 300 … 3900 ms: 13 good (≤ 2.5 s), 7 needing work, none poor.
eq('the daily roll-up keeps the 75th percentile and the ratings', [20, 2900, 13, 7, 0],
    [(int) $daily['n'], (int) $daily['p75'], (int) $daily['good'], (int) $daily['ni'], (int) $daily['poor']]);
$summary = monitor_rum_summary($db);
eq('the 7-day summary per device', 20, $summary['phone']['n'] ?? null);

// ---- CSP reports, both formats
$legacy = json_encode(['csp-report' => [
    'document-uri' => 'https://hodlekki.org/modules/finance/index.php?id=4', 'effective-directive' => 'script-src-elem',
    'blocked-uri' => 'inline', 'disposition' => 'report', 'source-file' => 'https://hodlekki.org/modules/finance/index.php', 'line-number' => 210,
]]);
$e1 = monitor_intake_csp_events((string) $legacy, 'application/csp-report', $server, null, $now);
eq('a report-uri report is read', 'CSP would block inline (script-src-elem)', $e1[0]['title'] ?? null);
eq('report-only is a notice, nothing broke yet', 'notice', $e1[0]['level']);
$modern = json_encode([
    ['type' => 'csp-violation', 'url' => 'https://hodlekki.org/sermons.php', 'body' => ['documentURL' => 'https://hodlekki.org/sermons.php',
     'effectiveDirective' => 'img-src', 'blockedURL' => 'https://i.ytimg.com/vi/abc/hq.jpg?x=1', 'disposition' => 'enforce']],
    ['type' => 'csp-violation', 'body' => ['documentURL' => 'https://hodlekki.org/', 'effectiveDirective' => 'script-src', 'blockedURL' => 'chrome-extension://x/y.js', 'disposition' => 'enforce']],
    ['type' => 'deprecation', 'body' => []],
]);
$e2 = monitor_intake_csp_events((string) $modern, 'application/reports+json', $server, null, $now);
eq('a Reporting API batch: one real violation kept (extension and other report types dropped)', 1, count($e2));
eq('an enforced block is a warning, other sites by host and path', 'CSP blocked i.ytimg.com/vi/abc/hq.jpg (img-src)', $e2[0]['title']);
monitor_store_all($db, monitor_intake_csp_events((string) $legacy, 'application/csp-report', $server, null, $now));
monitor_store_all($db, monitor_intake_csp_events((string) str_replace('finance', 'events', $legacy), 'application/csp-report', $server, null, $now));
eq('the same violation on two pages is one problem, with both pages', 2,
    (int) $db->query("SELECT COUNT(*) FROM monitor_error_paths p JOIN monitor_error_groups g ON g.id = p.group_id WHERE g.kind = 'csp' AND g.exception_class = 'script-src-elem'")->fetchColumn());

// ---- Rate limit
$ok = 0;
for ($i = 0; $i < 65; $i++) {
    $ok += monitor_intake_rate_ok('203.0.113.9', 60) ? 1 : 0;
}
eq('60 reports a minute from one address, then refused', 60, $ok);
ok('another address is not affected', monitor_intake_rate_ok('203.0.113.10', 60));
$g = 0;
for ($i = 0; $i < 7; $i++) {
    $g += monitor_intake_global_ok(5) ? 1 : 0;
}
eq('the site-wide allowance is its own count (only valid reports use it)', 5, $g);
$big = $beacon(['errors' => [['type' => 'error', 'msg' => 'x', 'src' => '/a.js', 'line' => '3000000000', 'col' => -4]]]);
eq('an impossible line number from a browser is dropped', [null, []], [$big['events'][0]['line'], $big['events'][0]['context']]);

// ---- Without MONITOR_SECRET the key is random and kept, never derived from DB_PASS (review, 11 Nov)
$kd = $GLOBALS['mt_dir'] . '/keytest';
@mkdir($kd, 0700, true);
$k1 = monitor_key_file($kd);
ok('a random 64-hex key is made once', is_string($k1) && (bool) preg_match('/^[0-9a-f]{64}$/', $k1));
eq('…and read back the same afterwards', $k1, monitor_key_file($kd));
ok('…unrelated to the database password', !str_contains((string) $k1, hash_hmac('sha256', 'hod-monitor/v1|', 'Pw-for-the-test-9431|')));
