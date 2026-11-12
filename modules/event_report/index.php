<?php
// /modules/event_report/index.php
// Event report: for one event, how it was promoted (SMS sent from SMS Studio
// and BulkSMS campaigns logged here), who registered, who attended (members,
// walk-ins, guests) and how the IDI follow-up did, with a short summary, two
// charts and a PDF for those allowed to export.
// Data: /api/event_report_api.php (its CSRF token is report_csrf) and
// /api/event_report_pdf.php. Script: assets/ui/js/pages/event-report.js.
$ui = [
    'title' => 'Event report',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/event-report.js'],
];
require_once '../../includes/header.php';
// Access: Events & Attendance in the permission matrix (Roles & permissions).
require_can('events');
if (empty($_SESSION['report_csrf'])) $_SESSION['report_csrf'] = bin2hex(random_bytes(32));

echo ui_json_script('event-report-config', [
    'csrf' => $_SESSION['report_csrf'],
    'canEdit' => can('events', 'edit'),
    'canDelete' => can('events', 'delete'),
]);

/** A count tile. */
function er_tile(string $key, string $label, string $meta): string
{
    return '<div class="ui-card ui-stat"><span class="ui-stat__label">' . ui_e($label) . '</span>'
        . '<span class="ui-stat__value ui-num" data-er="' . ui_e($key) . '">—</span>'
        . '<span class="ui-stat__meta">' . ui_e($meta) . '</span></div>';
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Event report',
        'description' => 'Promotion, registration, attendance and follow-up.',
        'info' => 'Pick an event to see its numbers. SMS sent from SMS Studio is counted on its own; BulkSMS campaigns sent elsewhere can be logged here so their cost is included. The PDF has every section.',
        'back' => ['href' => '/modules/events/', 'label' => 'Events'],
    ]) ?>

    <div class="ui-toolbar mb-4">
        <div class="ui-toolbar__grow">
            <label class="sr-only" for="er-event">Event</label>
            <select class="ui-select" id="er-event" data-action-change="er.event"><option value="">Choose an event</option></select>
        </div>
<?php if (can('events', 'export')): ?>
        <div class="ui-toolbar__end">
            <?= ui_button('PDF', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => '#', 'attrs' => ['id' => 'er-pdf', 'hidden' => true]]) ?>
        </div>
<?php endif; ?>
    </div>

    <div id="er-none">
        <?= ui_empty(['icon' => 'file-text', 'title' => 'Pick an event', 'text' => 'Its report shows here.']) ?>
    </div>

    <div class="ui-stack ui-stack--md" id="er-body" hidden>
        <section class="ui-card" aria-labelledby="er-summary-title">
            <div class="ui-card__header"><h2 class="ui-card__title" id="er-summary-title">In summary</h2></div>
            <div class="ui-card__body ui-prose max-w-none text-sm" id="er-summary"></div>
        </section>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
            <?= er_tile('cong', 'Congregation', 'People on file') ?>
            <?= er_tile('reg', 'Registered', 'For this event') ?>
            <?= er_tile('att', 'Attended', 'Each person once') ?>
            <?= er_tile('idi', 'IDI list', 'To follow up') ?>
            <?= er_tile('idiAtt', 'IDI came', 'From the IDI list') ?>
            <?= er_tile('cost', 'SMS cost', 'All campaigns') ?>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="ui-card" aria-labelledby="er-att-title">
                <div class="ui-card__header"><h2 class="ui-card__title" id="er-att-title">Attendance by day</h2></div>
                <div class="ui-card__body"><div class="relative h-56"><canvas id="er-att-chart" role="img" aria-label="Attendance by day chart"></canvas></div></div>
            </section>
            <section class="ui-card" aria-labelledby="er-src-title">
                <div class="ui-card__header">
                    <div class="ui-title-row"><h2 class="ui-card__title" id="er-src-title">How they heard</h2><?= ui_info('What registrants chose when they registered.', 'About how they heard') ?></div>
                </div>
                <div class="ui-card__body"><div class="relative h-56"><canvas id="er-src-chart" role="img" aria-label="Registration sources chart"></canvas></div></div>
            </section>
        </div>

        <section class="ui-card" aria-labelledby="er-sms-title">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h2 class="ui-card__title" id="er-sms-title">SMS</h2><?= ui_info('Reach is every unique phone number on file. Campaigns sent from SMS Studio appear by themselves; log BulkSMS campaigns sent elsewhere so they count.', 'About SMS') ?></div>
<?php if (can('events', 'edit')): ?>
                <?= ui_button('Log campaign', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'er.sms-add']]) ?>
<?php endif; ?>
            </div>
            <div class="ui-card__body border-b border-line">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4" id="er-sms-kpis"></div>
            </div>
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Campaign</th><th scope="col">Sent</th><th scope="col">Recipients</th><th scope="col">Pages</th><th scope="col">Cost</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody id="er-sms-rows"></tbody>
                </table>
            </div>
        </section>

        <section class="ui-card" aria-labelledby="er-detail-title">
            <div class="ui-card__header"><h2 class="ui-card__title" id="er-detail-title">The detail</h2></div>
            <div class="ui-card__body">
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-3">
                    <div><dt class="ui-meta">Registered before</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="regBefore">—</dd></div>
                    <div><dt class="ui-meta">Registered on the days</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="regOnDay">—</dd></div>
                    <div><dt class="ui-meta">Came after registering</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="attPrior">—</dd></div>
                    <div><dt class="ui-meta">Members who came</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="attMem">—</dd></div>
                    <div><dt class="ui-meta">Walk-ins and guests</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="attWalk">—</dd></div>
                    <div><dt class="ui-meta">IDI contacted</dt><dd class="ui-num text-xl font-semibold text-ink" data-er="idiContacted">—</dd></div>
                </dl>
            </div>
        </section>
    </div>
</div>

<?= ui_dialog_start('er-sms', ['title' => 'Log SMS campaign', 'description' => 'A BulkSMS campaign sent outside SMS Studio.']) ?>
    <form class="ui-form" id="er-sms-form" data-action="er.sms-save" data-ui-validate novalidate>
        <input type="hidden" name="id" value="">
        <?= ui_field('text', ['name' => 'campaign_label', 'id' => 'er-sms-label', 'label' => 'Campaign', 'required' => true, 'placeholder' => 'e.g. Day 1 reminder', 'attrs' => ['maxlength' => 120, 'data-error' => 'Name the campaign.']]) ?>
        <div class="ui-form-grid">
            <?= ui_field('date', ['name' => 'sent_date', 'id' => 'er-sms-date', 'label' => 'Sent on', 'optional' => true]) ?>
            <?= ui_field('text', ['name' => 'audience_label', 'id' => 'er-sms-aud', 'label' => 'Sent to', 'optional' => true, 'placeholder' => 'Congregation, IDI…', 'attrs' => ['maxlength' => 120]]) ?>
            <?= ui_field('number', ['name' => 'recipient_count', 'id' => 'er-sms-count', 'label' => 'Recipients', 'attrs' => ['min' => 0, 'inputmode' => 'numeric']]) ?>
            <?= ui_field('number', ['name' => 'characters', 'id' => 'er-sms-chars', 'label' => 'Characters', 'optional' => true, 'attrs' => ['min' => 0, 'inputmode' => 'numeric']]) ?>
            <?= ui_field('number', ['name' => 'pages', 'id' => 'er-sms-pages', 'label' => 'Pages', 'value' => '1', 'attrs' => ['min' => 1, 'inputmode' => 'numeric']]) ?>
            <?= ui_field('number', ['name' => 'unit_cost', 'id' => 'er-sms-cost', 'label' => 'Cost per page (₦)', 'value' => '6.99', 'attrs' => ['min' => 0, 'step' => '0.01', 'inputmode' => 'decimal']]) ?>
        </div>
        <?= ui_field('text', ['name' => 'notes', 'id' => 'er-sms-notes', 'label' => 'Notes', 'optional' => true, 'attrs' => ['maxlength' => 255]]) ?>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]]) . ui_button('Save campaign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'er-sms-form', 'id' => 'er-sms-go']])) ?>
<?php require_once '../../includes/footer.php'; ?>
