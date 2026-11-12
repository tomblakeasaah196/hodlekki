<?php
// /modules/event_qr/index.php
// Event QR poster: a 16:9 branded poster with the event's registration QR
// (its register page), for screens and social media. Drawn on a canvas
// (assets/ui/js/poster.js), downloaded as PNG or JPEG at 3200 x 1800.
// Only events that take registrations have a registration link to show.
// Open with ?event_id=NNN to start on one event.
// Data: /api/event_qr_api.php. Script: assets/ui/js/pages/event-qr.js.
$ui = [
    'title' => 'Event QR poster',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/poster.js', '/assets/ui/js/pages/event-qr.js'],
];
require_once '../../includes/header.php';
// Access: Events & Attendance in the permission matrix (Roles & permissions).
require_can('events');
require_once '../../includes/site_url.php';

echo ui_json_script('event-qr-config', [
    'eventId' => (int) ($_GET['event_id'] ?? 0),
    'host' => site_host(),
]);
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Event QR poster',
        'description' => 'A 16:9 poster with the registration QR.',
        'info' => 'The QR opens the event’s registration page. Events with their Registration link off (services, for one) have nothing to register for; use the Check-in QR for them.',
        'back' => ['href' => '/modules/events/index.php', 'label' => 'Events'],
    ]) ?>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <section class="ui-card self-start" aria-labelledby="eq-controls-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="eq-controls-title">Poster</h2></div>
            <form class="ui-card__body ui-form" id="eq-form" novalidate>
                <div class="ui-field">
                    <label class="ui-label" for="eq-event">Event</label>
                    <select class="ui-select" id="eq-event" data-action-change="eq.event"><option value="">Choose an event</option></select>
                </div>
                <?= ui_field('text', ['name' => 'brand', 'id' => 'eq-brand', 'label' => 'Church name', 'value' => 'HOD Lekki', 'attrs' => ['maxlength' => 40, 'data-action-input' => 'eq.redraw']]) ?>
                <?= ui_field('text', ['name' => 'title', 'id' => 'eq-title', 'label' => 'Title', 'attrs' => ['maxlength' => 80, 'data-action-input' => 'eq.redraw']]) ?>
                <?= ui_field('text', ['name' => 'tagline', 'id' => 'eq-tagline', 'label' => 'Line above the title', 'optional' => true, 'value' => 'You are warmly invited', 'attrs' => ['maxlength' => 60, 'data-action-input' => 'eq.redraw']]) ?>
                <label class="ui-check items-center"><input type="checkbox" class="ui-switch" id="eq-details" checked data-action-change="eq.redraw"><span>Date and place</span></label>
                <label class="ui-check items-center"><input type="checkbox" class="ui-switch" id="eq-banner" checked data-action-change="eq.redraw"><span>Event banner behind</span></label>
                <div class="grid grid-cols-2 gap-2">
                    <?= ui_button('PNG', ['variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-action' => 'eq.download', 'data-kind' => 'png', 'disabled' => true]]) ?>
                    <?= ui_button('JPEG', ['variant' => 'secondary', 'icon' => 'download', 'attrs' => ['data-action' => 'eq.download', 'data-kind' => 'jpeg', 'disabled' => true]]) ?>
                </div>
            </form>
        </section>

        <section class="ui-card" aria-label="Poster preview">
            <div class="ui-card__body">
                <div id="eq-none">
                    <?= ui_empty(['icon' => 'qr-code', 'title' => 'Pick an event', 'text' => 'Its poster shows here.']) ?>
                </div>
                <div class="ui-banner ui-banner--warning mb-3" id="eq-nolink" role="status" hidden>
                    <?= ui_icon('triangle-alert') ?>
                    <div class="ui-banner__body"><p class="ui-banner__text">No registration link, so no QR.</p><p class="ui-banner__text"><a class="ui-link" href="/modules/checkin_qr/index.php">Use the Check-in QR</a></p></div>
                </div>
                <canvas class="block h-auto w-full rounded-ui shadow-md" id="eq-canvas" width="1600" height="900" role="img" aria-label="Event poster preview" hidden></canvas>
            </div>
        </section>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
