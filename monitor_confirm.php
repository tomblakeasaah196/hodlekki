<?php
// /monitor_confirm.php
/**
 * The links in the system monitor's emails (docs/monitor.md, "Alerts").
 *
 *   ?t=<id>.<secret>     confirm an address an admin added (nothing is sent before)
 *   ?stop=<id>.<sig>     stop the alerts to this address
 *
 * Opening the link only shows a button; the change happens on the button's
 * POST, so a mail scanner that follows every link cannot confirm or stop
 * anything by itself. Public (the person may have no account), no inline code,
 * its own strict Content-Security-Policy.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/monitor/bootstrap.php';

header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$stop = (string) ($_POST['stop'] ?? $_GET['stop'] ?? '');
$mode = $stop !== '' ? 'stop' : 'confirm';
$done = null;
$error = null;

if (!monitor_ready($pdo)) {
    $error = 'The system monitor is being set up. Try the link again in a few minutes.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $r = $mode === 'stop' ? monitor_recipient_stop($pdo, $stop) : monitor_recipient_confirm($pdo, $token);
    if ($r['ok']) {
        $done = $mode === 'stop'
            ? 'Done. ' . $r['email'] . ' will not receive the system alerts any more.'
            : 'Confirmed. ' . $r['email'] . ' will now receive the system alerts and the morning summary.';
    } else {
        $error = $r['error'];
    }
} elseif ($token === '' && $stop === '') {
    $error = 'This link is not complete. Open it from the email again.';
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $mode === 'stop' ? 'Stop system alerts' : 'Confirm system alerts' ?> · HOD Lekki</title>
    <link rel="stylesheet" href="/assets/css/monitor_public.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/monitor_public.css') ?>">
</head>
<body>
<main class="mp-card">
    <p class="mp-brand"><span class="mp-dot"></span>HOD Lekki · System monitor</p>
    <?php if ($done !== null): ?>
        <h1><?= $mode === 'stop' ? 'Alerts stopped' : 'You are on the list' ?></h1>
        <p><?= $e($done) ?></p>
    <?php elseif ($error !== null): ?>
        <h1>That did not work</h1>
        <p><?= $e($error) ?></p>
    <?php else: ?>
        <h1><?= $mode === 'stop' ? 'Stop the system alerts?' : 'Confirm the system alerts' ?></h1>
        <p><?= $mode === 'stop'
            ? 'This address will stop receiving error and health alerts and the morning summary from ' . $e(site_host()) . '. An admin can add it again later.'
            : 'This address will receive error and health alerts and a short summary each morning from ' . $e(site_host()) . '. They never contain any member\'s personal details.' ?></p>
        <form method="post" action="/monitor_confirm.php">
            <input type="hidden" name="<?= $mode === 'stop' ? 'stop' : 't' ?>" value="<?= $e($mode === 'stop' ? $stop : $token) ?>">
            <button type="submit" class="<?= $mode === 'stop' ? 'mp-btn mp-btn-quiet' : 'mp-btn' ?>"><?= $mode === 'stop' ? 'Stop the alerts' : 'Confirm this address' ?></button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
