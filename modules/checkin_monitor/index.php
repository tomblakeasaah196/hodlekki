<?php
// /modules/checkin_monitor/index.php
// Check-in Monitor: live check-in counts for one event (it starts on today's
// service when there is one) and the latest people in, refreshed every 30
// seconds while the page is open. Guests at a service are counted apart:
// they are never registered. Excel export for those allowed to export.
// Data: /api/checkin_qr_api.php (checkin_stats; CSRF checkin_csrf) and
// /api/export_checkin_excel.php. Script: assets/ui/js/pages/checkin-monitor.js.
$ui = [
    'title' => 'Check-in monitor',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/checkin-monitor.js'],
];
require_once '../../includes/header.php';
// Access: Events & Attendance in the permission matrix (Roles & permissions).
require_can('events');
if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));

echo ui_json_script('checkin-monitor-config', [
    'csrf' => $_SESSION['checkin_csrf'],
    'canExport' => can('events', 'export'),
]);

/** A count tile. */
function cm_tile(string $key, string $label, string $meta): string
{
    return '<div class="ui-card ui-stat"><span class="ui-stat__label" data-cm-label="' . ui_e($key) . '">' . ui_e($label) . '</span>'
        . '<span class="ui-stat__value ui-num" data-cm="' . ui_e($key) . '">—</span>'
        . '<span class="ui-stat__meta" data-cm-meta="' . ui_e($key) . '">' . ui_e($meta) . '</span></div>';
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Check-in monitor',
        'description' => 'Who has checked in, live.',
        'info' => 'Counts come from the check-in page (the door QR). Members are matched by phone. At a service, a newcomer is a guest: counted, never registered. The list refreshes every 30 seconds while Live is on.',
        'back' => ['href' => '/modules/events/', 'label' => 'Events'],
    ]) ?>

    <div class="ui-toolbar mb-4">
        <div class="ui-toolbar__grow">
            <label class="sr-only" for="cm-event">Event</label>
            <select class="ui-select" id="cm-event" data-action-change="cm.event"><option value="">Choose an event</option></select>
        </div>
        <div class="ui-toolbar__end">
            <label class="ui-check items-center"><input type="checkbox" class="ui-switch" id="cm-live" checked data-action-change="cm.live"><span>Live</span></label>
            <?= ui_button('Refresh', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'icon_only' => true, 'attrs' => ['data-action' => 'cm.refresh']]) ?>
<?php if (can('events', 'export')): ?>
            <?= ui_button('Export', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => '#', 'attrs' => ['id' => 'cm-export', 'hidden' => true]]) ?>
<?php endif; ?>
        </div>
    </div>

    <div id="cm-none">
        <?= ui_empty(['icon' => 'activity', 'title' => 'Pick an event', 'text' => 'Its check-ins show here.']) ?>
    </div>

    <div class="ui-stack ui-stack--md" id="cm-body" hidden>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <?= cm_tile('total', 'Checked in', 'All days') ?>
            <?= cm_tile('today', 'Today', 'Since midnight') ?>
            <?= cm_tile('members', 'Members', 'Matched by phone') ?>
            <?= cm_tile('walkins', 'Walk-ins', 'Registered at the door') ?>
            <?= cm_tile('edits', 'Names fixed', 'At the door') ?>
        </div>

        <section class="ui-card" id="cm-days-card" aria-labelledby="cm-days-title" hidden>
            <div class="ui-card__header"><h2 class="ui-card__title" id="cm-days-title">By day</h2></div>
            <div class="ui-card__body"><div class="ui-chips flex-wrap" id="cm-days"></div></div>
        </section>

        <section class="ui-card" aria-labelledby="cm-recent-title">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title" id="cm-recent-title">Latest check-ins</h2>
                <span class="ui-meta" id="cm-updated" aria-live="polite"></span>
            </div>
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Name</th><th scope="col">Phone</th><th scope="col">Type</th><th scope="col">How</th><th scope="col">Time</th></tr></thead>
                    <tbody id="cm-rows"></tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
