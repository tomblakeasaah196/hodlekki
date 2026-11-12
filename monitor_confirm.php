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
 * on the standalone layout with its strict Content-Security-Policy. CSRF-exempt
 * (includes/csrf.php): the signed token in the link is the proof.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/monitor/bootstrap.php';
require_once __DIR__ . '/includes/ui/standalone.php';

header('Referrer-Policy: no-referrer');

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

ui_standalone_open($mode === 'stop' ? 'Stop system alerts' : 'Confirm system alerts');
?>
    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle"><?= ui_icon('activity', 'ui-icon--sm') ?>System monitor</p>
<?php if ($done !== null): ?>
    <h1 class="ui-h2"><?= $mode === 'stop' ? 'Alerts stopped' : 'You are on the list' ?></h1>
    <?= ui_banner('success', ['text' => $done]) ?>
<?php elseif ($error !== null): ?>
    <h1 class="ui-h2">That did not work</h1>
    <?= ui_banner('warning', ['text' => $error]) ?>
<?php else: ?>
    <div class="ui-title-row">
        <h1 class="ui-h2"><?= $mode === 'stop' ? 'Stop the system alerts?' : 'Confirm the system alerts' ?></h1>
        <?= ui_info($mode === 'stop'
            ? 'Error and health alerts and the morning summary from ' . site_host() . '. An admin can add this address again later.'
            : 'Error and health alerts and a short summary each morning from ' . site_host() . '. They never contain any member\'s personal details.',
            'About these alerts') ?>
    </div>
    <p class="text-ink-muted"><?= $mode === 'stop' ? 'This address stops getting the alerts and the morning summary.' : 'This address gets the alerts and a morning summary.' ?></p>
    <form method="post" action="/monitor_confirm">
        <input type="hidden" name="<?= $mode === 'stop' ? 'stop' : 't' ?>" value="<?= ui_e($mode === 'stop' ? $stop : $token) ?>">
        <?= ui_button($mode === 'stop' ? 'Stop the alerts' : 'Confirm this address', ['variant' => $mode === 'stop' ? 'secondary' : 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
<?php endif;

ui_standalone_close();
