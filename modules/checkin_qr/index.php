<?php
// /modules/checkin_qr/index.php
// Check-in QR: the printable 4:5 poster people scan at the door. Either the
// standing service QR (/checkin.php?service=today: it always opens the service
// on that day, so one print serves every Sunday and Thursday) or one event's
// own QR. Drawn on a canvas (assets/ui/js/poster.js), downloaded as PNG or
// JPEG at 2160 x 2700. Data: /api/checkin_qr_api.php (its CSRF token is
// checkin_csrf). Script: assets/ui/js/pages/checkin-qr.js.
$ui = [
    'title' => 'Check-in QR',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/poster.js', '/assets/ui/js/pages/checkin-qr.js'],
];
require_once '../../includes/header.php';
// Access: Events & Attendance in the permission matrix (Roles & permissions).
require_can('events');
require_once '../../includes/site_url.php';
if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));

echo ui_json_script('checkin-qr-config', [
    'csrf' => $_SESSION['checkin_csrf'],
    'checkinUrl' => site_url('/checkin.php'),
]);
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Check-in QR',
        'description' => 'A poster for the door: people scan it to check in.',
        'info' => 'The service QR is printed once: on a Sunday or Thursday it opens that day’s service, and on other days it counts down to the next one. An event’s own QR opens just that event.',
        'back' => ['href' => '/modules/events/index.php', 'label' => 'Events'],
    ]) ?>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <section class="ui-card self-start" aria-labelledby="cq-controls-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="cq-controls-title">Poster</h2></div>
            <div class="ui-card__body ui-form">
                <div class="ui-field">
                    <label class="ui-label" for="cq-target">QR for</label>
                    <select class="ui-select" id="cq-target" data-action-change="cq.target">
                        <option value="service" selected>Every service (today’s)</option>
                        <optgroup label="One event" id="cq-events"></optgroup>
                    </select>
                    <p class="ui-hint" id="cq-hint">One print for every Sunday and Thursday.</p>
                </div>
                <div class="ui-field">
                    <span class="ui-label">Link</span>
                    <div class="flex items-center gap-2">
                        <a class="ui-link min-w-0 flex-1 truncate text-sm" id="cq-link" href="#" target="_blank" rel="noopener"></a>
                        <?= ui_button('Copy link', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'copy', 'icon_only' => true, 'attrs' => ['data-action' => 'cq.copy']]) ?>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <?= ui_button('PNG', ['variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-action' => 'cq.download', 'data-kind' => 'png', 'disabled' => true]]) ?>
                    <?= ui_button('JPEG', ['variant' => 'secondary', 'icon' => 'download', 'attrs' => ['data-action' => 'cq.download', 'data-kind' => 'jpeg', 'disabled' => true]]) ?>
                </div>
            </div>
        </section>

        <section class="ui-card" aria-label="Poster preview">
            <div class="ui-card__body">
                <canvas class="mx-auto block h-auto w-full max-w-lg rounded-ui shadow-md" id="cq-canvas" width="1080" height="1350" role="img" aria-label="Check-in poster preview"></canvas>
            </div>
        </section>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
