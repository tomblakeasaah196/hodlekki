<?php
// /modules/events/index.php
// Events: create and manage events (one-off, or a month of services at once),
// their registration link and questions, the registrations, attendance (the
// roster, quick search, verify details, add to congregation) and the service
// analytics. Data: /api/events_api.php, /api/event_analytics_api.php and, for
// a new member, /api/congregation_api.php (create_member).
// Scripts: assets/ui/js/pages/events.js (list, configure, registrations),
// events-attendance.js and events-analytics.js. Deep links: ?section=… and
// ?attendance_event=… keep working (as do #tab=…).
$ui = [
    'title' => 'Events',
    'mode' => 'strict',
    'scripts' => [
        '/assets/ui/js/pages/events.js',
        '/assets/ui/js/pages/events-attendance.js',
        '/assets/ui/js/pages/events-analytics.js',
    ],
];
require_once '../../includes/header.php';
// Access: the 'events' row of the permission matrix (Roles & permissions).
require_can('events');

$evCanUnlock = can('events.unlock', 'edit');
$evCategories = [
    'Sunday_Service' => 'Sunday Service',
    'Midweek_Service' => 'Midweek Service',
    'Tribe_Meeting' => 'Tribe Meeting',
    'Department_Meeting' => 'Department Meeting',
    'Evangelism' => 'Evangelism',
    'Retreat' => 'Retreat',
    'Academy_Class' => 'Academy Class',
    'Conference' => 'Conference',
    'Prayer_Vigil' => 'Prayer Vigil',
];

echo ui_json_script('events-config', [
    'canUnlock' => $evCanUnlock,
    'categories' => $evCategories,
]);

/** A tappable attendance count that opens who is behind it. */
function ev_att_tile(string $card, string $label, string $sub): string
{
    return '<button type="button" class="ui-card ui-stat text-left" data-action="att.kpi" data-card="' . ui_e($card) . '" aria-haspopup="dialog">'
        . '<span class="ui-stat__label" data-att-kpi-label="' . ui_e($card) . '">' . ui_e($label) . '</span>'
        . '<span class="ui-stat__value ui-num" data-att-kpi-value="' . ui_e($card) . '">0</span>'
        . '<span class="ui-stat__meta" data-att-kpi-sub="' . ui_e($card) . '">' . ui_e($sub) . '</span>'
        . '</button>';
}

/** A chart card: a title with its (i), optional controls, and a fixed-height canvas. */
function ev_chart_card(string $id, string $title, string $info, string $controls = '', string $height = 'h-64'): string
{
    return '<section class="ui-card" aria-labelledby="' . ui_e($id) . '-title">'
        . '<div class="ui-card__header"><div class="ui-title-row"><h3 class="ui-card__title" id="' . ui_e($id) . '-title">' . ui_e($title) . '</h3>'
        . ui_info($info, 'About ' . mb_strtolower($title)) . '</div>' . $controls . '</div>'
        . '<div class="ui-card__body"><div class="relative ' . ui_e($height) . '"><canvas id="' . ui_e($id) . '" role="img" aria-label="' . ui_e($title) . ' chart"></canvas></div></div>'
        . '</section>';
}

/** Totals / Trend switch for a chart. */
function ev_view_switch(string $kind): string
{
    return '<div class="ui-segmented" role="group" aria-label="Chart view">'
        . '<button type="button" class="ui-segmented__item" data-action="an.view" data-kind="' . ui_e($kind) . '" data-view="totals" aria-pressed="true">Totals</button>'
        . '<button type="button" class="ui-segmented__item" data-action="an.view" data-kind="' . ui_e($kind) . '" data-view="trend" aria-pressed="false">Trend</button>'
        . '</div>';
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Events',
        'description' => 'Create events, then track registrations and attendance.',
        'info' => 'Set up one-off events or a month of services, share the registration link, follow the registrations, clock people in on the day, and see how attendance is trending.',
    ]) ?>

    <?= ui_tabs([
        'events' => ['label' => 'Events', 'icon' => 'calendar'],
        'configure' => ['label' => 'Configure', 'icon' => 'sliders-horizontal'],
        'registrations' => ['label' => 'Registrations', 'icon' => 'clipboard-list'],
        'attendance' => ['label' => 'Attendance', 'icon' => 'user-check'],
        'analytics' => ['label' => 'Analytics', 'icon' => 'chart-column'],
    ], 'events', 'Events sections') ?>

    <!-- Events ---------------------------------------------------------- -->
    <section id="panel-events" data-tab-panel="events" class="ui-tab-panel ui-stack ui-stack--md">
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow ui-search">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="ev-search">Search events</label>
                <input class="ui-input" type="search" id="ev-search" placeholder="Search events" autocomplete="off" data-action-input="ev.search">
            </div>
            <div class="ui-toolbar__end">
                <?= ui_button('New event', ['variant' => 'primary', 'icon' => 'plus', 'icon_end' => 'chevron-down', 'attrs' => ['data-menu-trigger' => true, 'aria-controls' => 'ev-create-menu', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false']]) ?>
                <div class="ui-menu" id="ev-create-menu" role="menu" hidden>
                    <button type="button" class="ui-menu__item" role="menuitem" data-action="ev.new"><?= ui_icon('calendar') ?><span>One-off event</span></button>
                    <button type="button" class="ui-menu__item" role="menuitem" data-action="ev.monthly"><?= ui_icon('calendar-check') ?><span>Monthly series</span></button>
                </div>
                <?= ui_button('More tools', ['variant' => 'secondary', 'icon' => 'ellipsis', 'icon_only' => true, 'attrs' => ['data-menu-trigger' => true, 'aria-controls' => 'ev-more-menu', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false']]) ?>
                <div class="ui-menu" id="ev-more-menu" role="menu" hidden>
                    <p class="ui-menu__label">Tools</p>
                    <a class="ui-menu__item" role="menuitem" href="/modules/event_qr/"><?= ui_icon('qr-code') ?><span>QR poster</span></a>
                    <a class="ui-menu__item" role="menuitem" href="/modules/event_report/"><?= ui_icon('file-text') ?><span>Event report</span></a>
                    <div class="ui-menu__sep"></div>
                    <a class="ui-menu__item" role="menuitem" href="/modules/checkin_qr/"><?= ui_icon('qr-code') ?><span>Check-in QR</span></a>
                    <a class="ui-menu__item" role="menuitem" href="/modules/checkin_monitor/"><?= ui_icon('activity') ?><span>Check-in monitor</span></a>
                </div>
            </div>
        </div>
        <div class="ui-grid ui-grid--3" id="ev-grid" aria-live="polite">
<?php for ($i = 0; $i < 3; $i++): ?>
            <div class="ui-card"><span class="ui-skeleton ui-skeleton--block h-32"></span><div class="ui-card__body space-y-2"><span class="ui-skeleton ui-skeleton--title w-2/3"></span><span class="ui-skeleton ui-skeleton--text w-1/2"></span></div></div>
<?php endfor; ?>
        </div>
        <div id="ev-grid-none" hidden>
            <?= ui_empty(['icon' => 'search', 'title' => 'No matching events', 'text' => 'Try another word.']) ?>
        </div>
    </section>

    <!-- Configure ------------------------------------------------------- -->
    <section id="panel-configure" data-tab-panel="configure" class="ui-tab-panel" hidden>
        <div class="grid gap-6 xl:grid-cols-2">
            <div class="ui-stack ui-stack--md min-w-0">
                <section class="ui-card" aria-labelledby="ev-form-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title" id="ev-form-title">Event details</h2>
                        <span class="ui-badge ui-badge--info" id="ev-mode">New</span>
                    </div>
                    <form class="ui-card__body ui-form" id="ev-form" data-action="ev.save" data-ui-validate enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="action" id="ev-action" value="create_event">
                        <input type="hidden" name="event_id" id="ev-id" value="">
                        <input type="hidden" name="is_custom_period" id="ev-period-flag" value="0">
                        <input type="hidden" name="ministers_json" id="ev-ministers-json" value="[]">
                        <?= ui_field('text', ['name' => 'title', 'id' => 'ev-title', 'label' => 'Title', 'required' => true, 'placeholder' => 'e.g. EXOUSIA 2026', 'attrs' => ['data-ev-preview' => true, 'maxlength' => 255]]) ?>
                        <?= ui_field('select', ['name' => 'event_category', 'id' => 'ev-category', 'label' => 'Category', 'required' => true, 'options' => $evCategories, 'attrs' => ['data-ev-preview' => true]]) ?>
                        <div class="ui-form-grid">
                            <div class="ui-field">
                                <label class="ui-label" for="ev-date"><span id="ev-date-label">Date and time</span><span class="ui-label__req" aria-hidden="true">*</span></label>
                                <input class="ui-input" type="datetime-local" name="event_date" id="ev-date" required data-ev-preview aria-describedby="ev-date-error">
                                <p class="ui-field-error" id="ev-date-error" aria-live="polite"></p>
                            </div>
                            <div class="ui-field" id="ev-end-wrap" hidden>
                                <label class="ui-label" for="ev-end">End date<span class="ui-label__req" aria-hidden="true">*</span></label>
                                <input class="ui-input" type="date" name="end_date" id="ev-end" data-ev-preview disabled aria-describedby="ev-end-error">
                                <p class="ui-field-error" id="ev-end-error" aria-live="polite"></p>
                            </div>
                        </div>
                        <label class="ui-check"><input type="checkbox" id="ev-period" data-action-change="ev.period"><span>Runs over several days<span class="ui-check__hint">Registrants pick their days.</span></span></label>
                        <?= ui_field('text', ['name' => 'location', 'id' => 'ev-location', 'label' => 'Location', 'optional' => true, 'placeholder' => 'e.g. Main Auditorium, Lekki', 'attrs' => ['data-ev-preview' => true, 'maxlength' => 255]]) ?>
                        <?= ui_field('url', ['name' => 'youtube_url', 'id' => 'ev-youtube', 'label' => 'YouTube link', 'optional' => true, 'placeholder' => 'https://youtu.be/…', 'info' => 'Paste any YouTube link. The registration page plays it.', 'inputmode' => 'url']) ?>
                        <?= ui_field('url', ['name' => 'external_registration_url', 'id' => 'ev-external', 'label' => 'Outside sign-up link', 'optional' => true, 'placeholder' => 'https://…', 'info' => 'When set, the registration page shows a button to register on that site, under the video.', 'inputmode' => 'url']) ?>

                        <div class="ui-field">
                            <div class="ui-label-row">
                                <span class="ui-label" id="ev-desc-label">Description</span>
                                <?= ui_info('Use the toolbar for bold, italic, underline and lists. Pasted text arrives plain.', 'About the description') ?>
                            </div>
                            <div class="overflow-hidden rounded-ui border border-line bg-surface">
                                <div class="flex flex-wrap gap-1 border-b border-line bg-surface-sunken p-1" role="toolbar" aria-label="Text formatting" aria-controls="ev-desc-editor">
                                    <?= ui_button('Bold', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'bold', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'bold']]) ?>
                                    <?= ui_button('Italic', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'italic', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'italic']]) ?>
                                    <?= ui_button('Underline', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'underline', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'underline']]) ?>
                                    <?= ui_button('Bulleted list', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'list', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'insertUnorderedList']]) ?>
                                    <?= ui_button('Numbered list', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'list-ordered', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'insertOrderedList']]) ?>
                                    <?= ui_button('Clear formatting', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'remove-formatting', 'icon_only' => true, 'attrs' => ['data-action' => 'ev.format', 'data-cmd' => 'removeFormat']]) ?>
                                </div>
                                <div class="ui-prose min-h-32 max-w-none px-3 py-2 text-sm focus:outline-none empty:before:text-ink-subtle empty:before:content-[attr(data-placeholder)]" id="ev-desc-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="ev-desc-label" data-placeholder="What is this event about?" spellcheck="true"></div>
                            </div>
                            <textarea name="description" id="ev-description" hidden></textarea>
                        </div>

                        <div class="ui-field">
                            <label class="ui-label" for="ev-banner">Banner image<span class="ui-label__opt">(optional)</span></label>
                            <input class="ui-input" type="file" name="banner_image" id="ev-banner" accept="image/png,image/jpeg,image/webp" data-action-change="ev.banner">
                            <img class="mt-2 max-h-48 w-full rounded-ui border border-line object-cover" id="ev-banner-preview" alt="Banner preview" hidden>
                        </div>

                        <div class="ui-field">
                            <div class="ui-label-row">
                                <span class="ui-label" id="ev-ministers-label">Ministers</span>
                                <?= ui_info('Add each minister with a photo and a name. Tap the photo to upload it.', 'About ministers') ?>
                                <?= ui_button('Add minister', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'plus', 'class' => 'ml-auto', 'attrs' => ['data-action' => 'ev.minister-add']]) ?>
                            </div>
                            <div class="space-y-2" id="ev-ministers" aria-labelledby="ev-ministers-label"></div>
                        </div>

                        <label class="ui-check items-center"><input type="checkbox" class="ui-switch" name="requires_registration" id="ev-requires" value="1"><span>Registration link<span class="ui-check__hint">A public page people register on.</span></span></label>
                        <label class="ui-check items-center"><input type="checkbox" class="ui-switch" name="allow_visitors" id="ev-visitors" value="1"><span>Open to everyone<span class="ui-check__hint">Visitors and non-members can register.</span></span></label>

                        <div class="ui-form-actions">
                            <?= ui_button('Create event', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['id' => 'ev-submit']]) ?>
                        </div>
                    </form>
                </section>

                <section class="ui-card" id="ev-lifecycle" aria-labelledby="ev-lifecycle-title" hidden>
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title" id="ev-lifecycle-title">Link and report</h2>
                        <span class="ui-badge ui-badge--danger" id="ev-closed-badge" hidden>Closed</span>
                    </div>
                    <div class="ui-card__body ui-form">
                        <div class="ui-field" id="ev-link-box" hidden>
                            <label class="ui-label" for="ev-link">Registration link</label>
                            <input class="ui-input ui-num" type="text" id="ev-link" readonly>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <?= ui_button('Copy', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'copy', 'attrs' => ['data-action' => 'ev.link-copy']]) ?>
                                <?= ui_button('WhatsApp', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'message-circle', 'attrs' => ['data-action' => 'ev.link-whatsapp']]) ?>
                                <?= ui_button('Share', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'send', 'attrs' => ['data-action' => 'ev.link-share']]) ?>
                            </div>
                        </div>
                        <?= ui_field('textarea', ['name' => 'report_notes', 'id' => 'ev-report', 'label' => 'Report notes', 'optional' => true, 'placeholder' => 'Highlights, issues, remarks', 'attrs' => ['rows' => 3]]) ?>
                        <div class="flex flex-wrap items-center gap-2">
                            <?= ui_button('Save report', ['variant' => 'secondary', 'icon' => 'file-text', 'attrs' => ['data-action' => 'ev.report']]) ?>
                            <span class="ml-auto inline-flex items-center gap-1">
                                <?= ui_button('Close event', ['variant' => 'danger-ghost', 'icon' => 'lock-keyhole', 'attrs' => ['data-action' => 'ev.close', 'id' => 'ev-close-btn']]) ?>
<?php if ($evCanUnlock): ?>
                                <?= ui_button('Unlock', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'ev.unlock', 'id' => 'ev-unlock-btn', 'hidden' => true]]) ?>
<?php endif; ?>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="ui-card" aria-labelledby="ev-fields-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <div class="ui-title-row">
                            <h2 class="ui-card__title" id="ev-fields-title">Registration questions</h2>
                            <?= ui_info('Extra questions on the registration page, in this order. Use the arrows to reorder; the preview updates as you go.', 'About registration questions') ?>
                        </div>
                        <?= ui_button('Add question', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'ev.field-add']]) ?>
                    </div>
                    <ul class="ui-list" id="ev-fields"></ul>
                </section>
            </div>

            <section class="min-w-0" aria-labelledby="ev-preview-title">
                <div class="xl:sticky xl:top-4">
                    <div class="mb-2 flex items-center gap-2">
                        <h2 class="ui-h3" id="ev-preview-title">Preview</h2>
                        <?= ui_info('How the public registration page will look. Nothing here is saved.', 'About the preview') ?>
                    </div>
                    <article class="ui-card overflow-hidden" aria-label="Registration page preview">
                        <img class="max-h-56 w-full object-cover" id="pv-banner" alt="" hidden>
                        <div class="ui-card__body space-y-3">
                            <span class="ui-badge ui-badge--brand" id="pv-category">Event</span>
                            <p class="font-display text-xl font-bold text-ink" id="pv-title">Your event title</p>
                            <ul class="space-y-1 text-sm text-ink-muted">
                                <li class="flex items-center gap-2"><?= ui_icon('calendar') ?><span id="pv-date">Pick a date</span></li>
                                <li class="flex items-center gap-2" id="pv-location" hidden><?= ui_icon('map-pin') ?><span></span></li>
                            </ul>
                            <div class="ui-prose max-w-none text-sm" id="pv-description" hidden></div>
                            <div id="pv-ministers" hidden>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Ministering</p>
                                <div class="flex flex-wrap gap-4" id="pv-ministers-list"></div>
                            </div>
                            <div id="pv-days" hidden>
                                <p class="mb-2 text-sm font-semibold text-ink">Which days will you attend?</p>
                                <div class="ui-chips flex-wrap" id="pv-days-list"></div>
                            </div>
                            <div class="space-y-3" id="pv-fields"></div>
                            <span class="ui-btn ui-btn--brand ui-btn--block pointer-events-none" aria-hidden="true">Confirm registration</span>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </section>

    <!-- Registrations --------------------------------------------------- -->
    <section id="panel-registrations" data-tab-panel="registrations" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow">
                <label class="sr-only" for="reg-event">Event</label>
                <select class="ui-select" id="reg-event" data-action-change="reg.event"><option value="">Choose an event</option></select>
            </div>
            <div class="ui-toolbar__end">
                <?= ui_button('Export', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => '#', 'attrs' => ['id' => 'reg-export', 'hidden' => true, 'target' => '_blank', 'rel' => 'noopener']]) ?>
            </div>
        </div>
        <div id="reg-none">
            <?= ui_empty(['icon' => 'clipboard-list', 'title' => 'Pick an event', 'text' => 'Its registrations show here.']) ?>
        </div>
        <div class="ui-stack ui-stack--md" id="reg-body" hidden>
            <div class="ui-grid ui-grid--3 ui-grid--stats">
                <div class="ui-card ui-stat"><span class="ui-stat__label">Registered</span><span class="ui-stat__value ui-num" id="reg-kpi-total">0</span><span class="ui-stat__meta">All time</span></div>
                <div class="ui-card ui-stat"><span class="ui-stat__label">Today</span><span class="ui-stat__value ui-num" id="reg-kpi-today">0</span><span class="ui-stat__meta" id="reg-kpi-trend">—</span></div>
                <div class="ui-card ui-stat"><span class="ui-stat__label">Yesterday</span><span class="ui-stat__value ui-num" id="reg-kpi-yesterday">0</span><span class="ui-stat__meta">Registrations</span></div>
            </div>
            <section class="ui-card" aria-labelledby="reg-list-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row">
                        <h2 class="ui-card__title" id="reg-list-title">Registrations</h2>
                        <?= ui_info('Guests who look like a member are matched with a confidence score. Confirm links the registration to that member; Not them dismisses it.', 'About member matches') ?>
                    </div>
                    <div class="ui-search w-full sm:w-72">
                        <?= ui_icon('search') ?>
                        <label class="sr-only" for="reg-search">Search registrations</label>
                        <input class="ui-input" type="search" id="reg-search" placeholder="Name, email or phone" autocomplete="off" data-action-input="reg.search">
                    </div>
                </div>
                <div class="ui-table-wrap border-0">
                    <table class="ui-table ui-table--stack">
                        <thead>
                            <tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">Contact</th><th scope="col">Days</th><th scope="col">Member match</th><th scope="col">Registered</th></tr>
                        </thead>
                        <tbody id="reg-rows"></tbody>
                    </table>
                </div>
                <div class="ui-pagination">
                    <span id="reg-page-info">Showing 0</span>
                    <div class="ui-pagination__pages" id="reg-pages"></div>
                </div>
            </section>
        </div>
    </section>

    <!-- Attendance ------------------------------------------------------ -->
    <section id="panel-attendance" data-tab-panel="attendance" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="grid gap-3 md:grid-cols-2">
            <div class="ui-field">
                <label class="ui-label" for="att-event">Event</label>
                <select class="ui-select" id="att-event" data-action-change="att.event"><option value="">Choose an open event</option></select>
            </div>
            <div class="ui-field relative">
                <div class="ui-label-row">
                    <label class="ui-label" for="att-search">Quick search</label>
                    <?= ui_info('Finds people still waiting to clock in, so you can clock several in without scrolling. Already checked in people are left out.', 'About quick search') ?>
                </div>
                <div class="ui-search">
                    <?= ui_icon('search') ?>
                    <input class="ui-input" type="search" id="att-search" placeholder="Type a name or phone" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="att-pop" aria-autocomplete="list" enterkeyhint="search" disabled>
                </div>
                <div class="ui-card ui-card--raised absolute inset-x-0 top-full z-20 mt-1 max-h-96 overflow-y-auto" id="att-pop" role="region" aria-label="Pending matches" hidden></div>
                <?= ui_button('Add to congregation', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'user-round-plus', 'class' => 'mt-1 self-start', 'attrs' => ['data-action' => 'att.add', 'id' => 'att-add', 'hidden' => true]]) ?>
            </div>
        </div>

        <div id="att-none">
            <?= ui_empty(['icon' => 'user-check', 'title' => 'Pick an event', 'text' => 'Its roster shows here.']) ?>
        </div>

        <div class="ui-stack ui-stack--md" id="att-body" hidden>
            <?= ui_button('Show counts', ['variant' => 'secondary', 'block' => true, 'icon' => 'chart-column', 'attrs' => ['data-action' => 'att.show-kpis', 'id' => 'att-kpi-toggle', 'hidden' => true]]) ?>
            <div class="ui-grid ui-grid--3 ui-grid--stats" id="att-kpis">
                <?= ev_att_tile('card1', 'Checked in', '0 registered') ?>
                <?= ev_att_tile('card2', 'Today', '—') ?>
                <?= ev_att_tile('card3', 'Members / walk-ins', 'Members / walk-ins') ?>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <section class="ui-card" aria-labelledby="att-pending-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title flex items-center gap-2" id="att-pending-title"><span class="ui-dot ui-dot--danger" aria-hidden="true"></span>Pending</h2>
                        <span class="ui-badge ui-num" id="att-pending-count">0</span>
                    </div>
                    <ul class="ui-list max-h-[40rem] overflow-y-auto" id="att-pending" aria-live="polite"></ul>
                </section>
                <section class="ui-card" aria-labelledby="att-in-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title flex items-center gap-2" id="att-in-title"><span class="ui-dot ui-dot--success" aria-hidden="true"></span>Checked in</h2>
                        <span class="ui-badge ui-badge--success ui-num" id="att-in-count">0</span>
                    </div>
                    <ul class="ui-list max-h-[40rem] overflow-y-auto" id="att-in" aria-live="polite"></ul>
                </section>
            </div>
        </div>
    </section>

    <!-- Analytics ------------------------------------------------------- -->
    <section id="panel-analytics" data-tab-panel="analytics" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="flex flex-wrap items-center gap-2">
            <div class="ui-segmented" role="group" aria-label="Service">
                <button type="button" class="ui-segmented__item" data-action="an.category" data-category="Midweek_Service" aria-pressed="true">Midweek</button>
                <button type="button" class="ui-segmented__item" data-action="an.category" data-category="Sunday_Service" aria-pressed="false">Sunday</button>
            </div>
            <div class="ml-auto flex gap-2">
                <?= ui_button('Compare', ['variant' => 'secondary', 'icon' => 'arrow-up-down', 'attrs' => ['data-action' => 'an.compare']]) ?>
                <?= ui_button('Export CSV', ['variant' => 'secondary', 'icon' => 'file-down', 'attrs' => ['data-action' => 'an.csv']]) ?>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card__body flex flex-wrap items-end gap-3">
                <div class="ui-field min-w-40">
                    <label class="ui-label" for="an-preset">Period</label>
                    <select class="ui-select" id="an-preset" data-action-change="an.preset">
                        <option value="8">Last 8 services</option>
                        <option value="3m">Last 3 months</option>
                        <option value="6m">Last 6 months</option>
                        <option value="12m" selected>Last 12 months</option>
                        <option value="ytd">This year</option>
                        <option value="all">All time</option>
                        <option value="custom">Custom range</option>
                    </select>
                </div>
                <div class="flex flex-wrap items-end gap-3" id="an-custom" hidden>
                    <div class="ui-field"><label class="ui-label" for="an-start">From</label><input class="ui-input" type="date" id="an-start"></div>
                    <div class="ui-field"><label class="ui-label" for="an-end">To</label><input class="ui-input" type="date" id="an-end"></div>
                    <?= ui_button('Apply', ['variant' => 'primary', 'attrs' => ['data-action' => 'an.apply']]) ?>
                </div>
                <div class="ui-field min-w-36">
                    <label class="ui-label" for="an-granularity">Group by</label>
                    <select class="ui-select" id="an-granularity" data-action-change="an.granularity">
                        <option value="month">Month</option>
                        <option value="week">Week</option>
                        <option value="service">Service</option>
                    </select>
                </div>
                <p class="ui-meta w-full" id="an-summary"></p>
            </div>
        </div>

        <div class="ui-card" id="an-loading" hidden>
            <div class="ui-card__body flex flex-col items-center gap-3 py-10 text-ink-muted"><span class="ui-spinner ui-spinner--lg" aria-hidden="true"></span><p>Loading analytics…</p></div>
        </div>
        <div id="an-empty" hidden>
            <?= ui_empty(['icon' => 'chart-column', 'title' => 'No services here', 'text' => 'Try a wider period.']) ?>
        </div>
        <div id="an-error" hidden></div>

        <div class="ui-stack ui-stack--md" id="an-content" hidden>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3" id="an-kpis"></div>
            <section class="ui-card" aria-labelledby="an-trend-title">
                <div class="ui-card__header">
                    <div class="ui-title-row">
                        <h3 class="ui-card__title" id="an-trend-title">Attendance trend</h3>
                        <?= ui_info('People marked present in each period. Tap a point to see the services in it. The dashed line is the average.', 'About attendance trend') ?>
                    </div>
                    <span class="ui-meta" id="an-trend-caption"></span>
                </div>
                <div class="ui-card__body"><div class="relative h-72"><canvas id="an-trend" role="img" aria-label="Attendance trend chart"></canvas></div></div>
            </section>
            <div class="grid gap-4 lg:grid-cols-2">
                <?= ev_chart_card('an-gender', 'Gender split', 'Male and female count of everyone marked present in the period, from each person\'s profile.', ev_view_switch('gender')) ?>
                <?= ev_chart_card('an-status', 'Who attended', 'Attendance by each person\'s status on their profile: Member, Worker, Pastor, 1st to 3rd Timer, Visitor or Non-member.', ev_view_switch('status')) ?>
            </div>
            <div id="an-region-card">
                <?= ev_chart_card('an-region', 'By region', 'Attendance in the period by each person\'s home region. Unassigned means a profile with no region.', '', 'h-80') ?>
            </div>
            <section class="ui-card" aria-labelledby="an-table-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row">
                        <h3 class="ui-card__title" id="an-table-title">Services</h3>
                        <?= ui_info('Every service in the period. “vs previous” compares each one with the service before it of the same type, even outside the period.', 'About the services table') ?>
                    </div>
                    <div class="ui-search w-full sm:w-64">
                        <?= ui_icon('search') ?>
                        <label class="sr-only" for="an-search">Search services</label>
                        <input class="ui-input" type="search" id="an-search" placeholder="Title or date" autocomplete="off" data-action-input="an.search">
                    </div>
                </div>
                <div class="ui-table-wrap border-0">
                    <table class="ui-table ui-table--stack">
                        <thead>
                            <tr>
                                <th scope="col" aria-sort="descending"><button type="button" class="inline-flex items-center gap-1" data-action="an.sort" data-col="event_date">Date <span data-an-sort="event_date" aria-hidden="true"></span></button></th>
                                <th scope="col">Title</th>
                                <th scope="col"><button type="button" class="inline-flex items-center gap-1" data-action="an.sort" data-col="total">Attendance <span data-an-sort="total" aria-hidden="true"></span></button></th>
                                <th scope="col">Male / female</th>
                                <th scope="col"><button type="button" class="inline-flex items-center gap-1" data-action="an.sort" data-col="first_timers">1st timers <span data-an-sort="first_timers" aria-hidden="true"></span></button></th>
                                <th scope="col">Workers</th>
                                <th scope="col"><button type="button" class="inline-flex items-center gap-1" data-action="an.sort" data-col="vs_previous">vs previous <span data-an-sort="vs_previous" aria-hidden="true"></span></button></th>
                                <th scope="col"><span class="sr-only">Details</span></th>
                            </tr>
                        </thead>
                        <tbody id="an-rows"></tbody>
                    </table>
                </div>
                <div class="ui-pagination">
                    <span id="an-page-info">Showing 0</span>
                    <div class="ui-pagination__pages" id="an-pages"></div>
                </div>
            </section>
        </div>
    </section>
</div>

<?= ui_dialog_start('ev-monthly', ['title' => 'Monthly series', 'description' => 'Every Sunday and Thursday service for a month.']) ?>
    <form class="ui-form" id="ev-monthly-form" data-action="ev.monthly-save" data-ui-validate novalidate>
        <?= ui_field('month', ['name' => 'month', 'id' => 'ev-month', 'label' => 'Month', 'required' => true, 'attrs' => ['data-action-change' => 'ev.monthly-preview']]) ?>
        <div class="grid gap-2 sm:grid-cols-2">
            <div class="rounded-ui border border-line bg-surface-sunken p-3"><p class="text-sm font-semibold text-ink">Sundays · 9:30 AM</p><p class="ui-meta">Total Experience</p></div>
            <div class="rounded-ui border border-line bg-surface-sunken p-3"><p class="text-sm font-semibold text-ink">Thursdays · 6:30 PM</p><p class="ui-meta">Mercy Experience</p></div>
        </div>
        <div>
            <div class="mb-2 flex items-center gap-2">
                <p class="text-sm font-semibold text-ink">To be created</p>
                <?= ui_info('The last Sunday is the Thanksgiving Service. All services are at Hebron, Kon-X Building, beside Scapular Plaza, Agungi, Lekki, with no registration link. A date that already has a service is skipped, not duplicated.', 'About the monthly series') ?>
                <span class="ui-badge ml-auto" id="ev-month-count">0 services</span>
            </div>
            <ul class="ui-list max-h-72 overflow-y-auto rounded-ui border border-line" id="ev-month-list"></ul>
        </div>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]]) . ui_button('Create series', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ev-monthly-form', 'id' => 'ev-month-submit']])) ?>

<?= ui_dialog_start('ev-field', ['title' => 'Add question', 'size' => 'sm']) ?>
    <form class="ui-form" id="ev-field-form" data-action="ev.field-save" data-ui-validate novalidate>
        <input type="hidden" id="ev-field-index" value="">
        <?= ui_field('text', ['name' => 'field_label', 'id' => 'ev-field-label', 'label' => 'Question', 'required' => true, 'placeholder' => 'e.g. Which day?', 'attrs' => ['maxlength' => 255, 'data-error' => 'Write the question.']]) ?>
        <?= ui_field('select', ['name' => 'field_type', 'id' => 'ev-field-type', 'label' => 'Answer type', 'options' => [
            'text' => 'Short answer', 'textarea' => 'Paragraph', 'email' => 'Email', 'number' => 'Number', 'date' => 'Date',
            'select' => 'Dropdown', 'radio' => 'Single choice', 'checkbox' => 'Multiple choice',
        ], 'attrs' => ['data-action-change' => 'ev.field-type']]) ?>
        <label class="ui-check"><input type="checkbox" id="ev-field-required" value="1"><span>Required</span></label>
        <?= ui_field('text', ['name' => 'placeholder', 'id' => 'ev-field-placeholder', 'label' => 'Hint text', 'optional' => true, 'placeholder' => 'Shown inside the empty field']) ?>
        <div id="ev-field-options-wrap" hidden>
            <?= ui_field('text', ['name' => 'field_options', 'id' => 'ev-field-options', 'label' => 'Options', 'required' => true, 'placeholder' => 'Day 1, Day 2, Day 3', 'hint' => 'Separate them with commas.', 'attrs' => ['disabled' => true, 'data-error' => 'Add at least one option.']]) ?>
        </div>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]]) . ui_button('Save question', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ev-field-form']])) ?>

<?= ui_dialog_start('att-kpi', ['title' => 'Details', 'description' => 'Attendance details for this event.', 'size' => 'lg']) ?>
    <div class="space-y-3" id="att-kpi-body"></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('att-verify', ['title' => 'Verify details', 'size' => 'lg']) ?>
    <div class="space-y-4" id="att-verify-body"></div>
<?= ui_dialog_end('<div class="flex w-full flex-wrap items-center gap-2" id="att-verify-actions"></div>') ?>

<?= ui_dialog_start('att-wizard', ['title' => 'Add to congregation', 'description' => 'Create their profile and clock them in.', 'size' => 'lg']) ?>
    <ol class="ui-steps mb-4" id="att-wiz-steps" aria-label="Steps">
        <li class="ui-step" data-att-step="0"><span class="ui-step__label">Type</span></li>
        <li class="ui-step" data-att-step="1"><span class="ui-step__label">Basics</span></li>
        <li class="ui-step" data-att-step="2"><span class="ui-step__label">Profile</span></li>
        <li class="ui-step" data-att-step="3"><span class="ui-step__label">Review</span></li>
    </ol>
    <div class="mb-4" id="att-wiz-error" role="alert" hidden></div>
    <div id="att-wiz-body"></div>
<?= ui_dialog_end('<div class="flex w-full flex-wrap items-center gap-2" id="att-wiz-footer"></div>') ?>

<?= ui_dialog_start('an-event', ['title' => 'Service', 'description' => ' ', 'size' => 'xl']) ?>
    <div class="space-y-4" id="an-event-body"></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('an-period', ['title' => 'Period', 'description' => ' ']) ?>
    <ul class="ui-list rounded-ui border border-line" id="an-period-body"></ul>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('an-compare', ['title' => 'Compare periods', 'description' => 'Pick two date ranges for this service.', 'size' => 'lg']) ?>
    <div class="space-y-4">
        <div class="ui-chips flex-wrap" id="an-compare-presets">
            <button type="button" class="ui-chip" data-action="an.preset-compare" data-preset="months">This vs last month</button>
            <button type="button" class="ui-chip" data-action="an.preset-compare" data-preset="quarters">This vs last quarter</button>
            <button type="button" class="ui-chip" data-action="an.preset-compare" data-preset="years">This vs last year</button>
            <button type="button" class="ui-chip" data-action="an.preset-compare" data-preset="last4">Last 4 vs previous 4</button>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <fieldset class="ui-fieldset rounded-ui border border-line p-3">
                <legend class="ui-legend px-1 text-primary">Period A</legend>
                <div class="ui-form-grid">
                    <div class="ui-field"><label class="ui-label" for="an-a-start">From</label><input class="ui-input" type="date" id="an-a-start"></div>
                    <div class="ui-field"><label class="ui-label" for="an-a-end">To</label><input class="ui-input" type="date" id="an-a-end"></div>
                </div>
            </fieldset>
            <fieldset class="ui-fieldset rounded-ui border border-line p-3">
                <legend class="ui-legend px-1 text-brand-red">Period B</legend>
                <div class="ui-form-grid">
                    <div class="ui-field"><label class="ui-label" for="an-b-start">From</label><input class="ui-input" type="date" id="an-b-start"></div>
                    <div class="ui-field"><label class="ui-label" for="an-b-end">To</label><input class="ui-input" type="date" id="an-b-end"></div>
                </div>
            </fieldset>
        </div>
        <div class="space-y-4" id="an-compare-results" hidden></div>
    </div>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]]) . ui_button('Compare', ['variant' => 'primary', 'attrs' => ['data-action' => 'an.run-compare']])) ?>
<?php require_once '../../includes/footer.php'; ?>
