<?php
// /tests/monitor/screens_test.php — the System Health page and the live wall
// keep their rules: strict CSP, the access gate, and error text never as HTML.
// (tests/ui/run.php's ratchet also checks the page has no inline code.)

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/modules/monitor/index.php');
$script = (string) file_get_contents($root . '/assets/ui/js/pages/monitor.js');
$wall = (string) file_get_contents($root . '/modules/monitor/wall.php');
$wallJs = (string) file_get_contents($root . '/assets/js/monitor_wall.js');
$nav = (string) file_get_contents($root . '/includes/ui/nav.php');

has('the page runs in strict mode', $page, "'mode'    => 'strict'");
has('the page asks monitor_user_can() before anything', $page, "if (!monitor_user_can(\$pdo, 'monitor.view'))");
has('…and refuses with the shared no-access state', $page, 'ui_no_access(');
has('settings are shown only to who may change them', $page, "\$canManage = monitor_user_can(\$pdo, 'monitor.manage');");
ok('no inline handler or style attribute on the page', !preg_match('/\son[a-z]+=|\sstyle="/i', $page));
has('the navigation points at this page', $nav, "'href' => '/modules/monitor/index.php'");

// The script puts error text in as text: innerHTML only for our own icon markup.
preg_match_all('/\.innerHTML\s*=/', $script, $m);
eq('the page script sets innerHTML in one place only', 1, count($m[0]));
has('…for an icon from our own sprite', $script, 'wrap.innerHTML = HOD.icon(name);');
ok('no insertAdjacentHTML, outerHTML or document.write', !preg_match('/insertAdjacentHTML|outerHTML|document\.write/', $script . $wallJs));
ok('the wall script never sets innerHTML', !str_contains($wallJs, 'innerHTML'));
has('the wall reads its key from the fragment only', $wallJs, 'window.location.hash');

// The wall page: its own strict policy, nothing from elsewhere.
has('the wall sends its own Content-Security-Policy', $wall, "Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'");
ok('…without unsafe-inline or unsafe-eval', !preg_match("/unsafe-(inline|eval)/", $wall));
ok('…and carries no inline script or style', !preg_match('/<script(?![^>]*\bsrc=)[^>]*>|<style\b|\sstyle="/i', $wall));
ok('the wall needs no sign-in and no database', !str_contains($wall, 'header.php') && !str_contains($wall, 'db.php'));

// Alerts link to the right tab.
ok('alert links open the right tab (#tab=…)', str_contains((string) file_get_contents($root . '/includes/monitor/alerts.php'), "monitor_screen_link('#tab=errors')"));
eq('…and the link reaches the page once it exists', '/modules/monitor/index.php#tab=health', monitor_screen_link('#tab=health'));
has('the page shows the monitor cron state with the exact line to copy', $page, "data-copy-target' => 'monitor-cron-line'");
has('…amber while it is a setup step, red only once it has stopped', $page, "\$cronCheck['state'] === 'bad' ? 'danger' : 'warning'");
