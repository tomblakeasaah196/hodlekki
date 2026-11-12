<?php
// tests/ui/render_connect.php
// Prints connect.php as a visitor gets it, in a process of its own: the app's
// head (includes/pwa_head.php) prints once per process, so a page rendered
// after another one in the same process could hide what it would print.
// Used by tests/ui/run.php, suite 11.
//   php tests/ui/render_connect.php [host]
$_SERVER['HTTP_HOST'] = $argv[1] ?? 'hodlekki.org';
$_SERVER['REQUEST_URI'] = '/connect';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__, 2) . '/connect.php';
