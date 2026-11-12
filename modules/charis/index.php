<?php
// /modules/charis/index.php
// Charis (welfare): birthdays and life events, AWOL monitoring and case
// follow-up, event tasks, the welfare budget, the library desk, and the
// guide (modules/charis/how_to_use.md). Data comes from /api/charis_api.php,
// which checks its own CSRF token (sent as X-Charis-CSRF by charis.js).
// Scripts: assets/ui/js/pages/charis.js (welfare) and charis-ops.js (events,
// finance, library, the monthly recap). Dialogs: includes/charis_modals.php.
$ui = [
    'title' => 'Charis',
    'mode' => 'strict',
    'scripts' => [
        '/assets/js/celebrants_export.js',
        '/assets/ui/js/pages/charis.js',
        '/assets/ui/js/pages/charis-ops.js',
        '/assets/ui/js/email-editor.js',
        '/assets/ui/js/pages/automation.js',
    ],
    // The Automation tab's email preview: a sandboxed frame on api/email_preview.php.
    'csp' => ['frame-src' => ["'self'"]],
];
require_once '../../includes/header.php';
// Access: the 'charis' row of the permission matrix (Roles & permissions).
require_can('charis');
require_once '../../includes/charis_helpers.php';
require_once '../../includes/reach_helpers.php';

$charisPageAccess = charis_access_context($pdo, (int) $_SESSION['user_id']);
if (empty($_SESSION['charis_csrf']) || !is_string($_SESSION['charis_csrf'])) {
    $_SESSION['charis_csrf'] = bin2hex(random_bytes(32));
}
$charisManager = !empty($charisPageAccess['is_manager']);
$charisCanConfigure = !empty($charisPageAccess['can_configure_awol']);

$charis_guide_html = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/', $charis_guide_html, $charis_guide_toc, PREG_SET_ORDER);

$charisMonths = [];
for ($m = 1; $m <= 12; $m++) {
    $charisMonths[$m] = date('F', mktime(0, 0, 0, $m, 1));
}
$charisYears = [];
for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--) {
    $charisYears[$y] = (string) $y;
}

/** A tappable count tile that opens what is behind it. */
function charis_tile(string $key, string $label, string $meta, string $icon, array $attrs): string
{
    return '<button type="button" class="ui-card ui-stat text-left"' . ui_attrs($attrs) . '>'
        . '<span class="ui-stat__label">' . ui_icon($icon) . '<span>' . ui_e($label) . '</span></span>'
        . '<span class="ui-stat__value" data-charis-count="' . ui_e($key) . '"><span class="ui-skeleton inline-block h-7 w-10"></span></span>'
        . '<span class="ui-stat__meta" data-charis-meta="' . ui_e($key) . '">' . ui_e($meta) . '</span>'
        . '</button>';
}

echo ui_json_script('charis-config', [
    'csrf' => $_SESSION['charis_csrf'],
    'manager' => $charisManager,
    'canConfigure' => $charisCanConfigure,
    'defaults' => [
        'consecutive' => (int) CHARIS_AWOL_DEFAULT_CONSECUTIVE,
        'archiveWeeks' => (int) CHARIS_AWOL_DEFAULT_ARCHIVE_WEEKS,
    ],
]);
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Charis',
        'description' => 'Welfare, celebrations, events and the library desk.',
        'info' => 'Charis looks after members: it marks birthdays and life events, follows up on members who stop coming, plans event tasks, tracks the welfare budget and runs the library desk.',
    ]) ?>

    <?= ui_tabs([
        'welfare' => ['label' => 'Welfare', 'icon' => 'heart-handshake'],
        'events' => ['label' => 'Events', 'icon' => 'calendar-check'],
        'finance' => ['label' => 'Finance', 'icon' => 'wallet'],
        'library' => ['label' => 'Library', 'icon' => 'book-open'],
    ] + ($charisCanConfigure ? ['automation' => ['label' => 'Automation', 'icon' => 'bell-ring']] : []) + [
        'howto' => ['label' => 'Guide', 'icon' => 'circle-help'],
    ], 'welfare', 'Charis sections') ?>

    <!-- Welfare ---------------------------------------------------------- -->
    <section id="panel-welfare" data-tab-panel="welfare" class="ui-tab-panel">
        <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
<?php if ($charisManager): ?>
            <?= ui_button('AWOL report', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'file-down', 'attrs' => ['data-dialog-open' => 'charis-awol-report']]) ?>
            <?= ui_button('Monthly recap', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'image', 'attrs' => ['data-action' => 'charis.recap']]) ?>
<?php endif; ?>
            <?= ui_button('Log event', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'charis-life-event']]) ?>
        </div>

        <div class="ui-grid ui-grid--3 ui-grid--stats mb-6">
            <?= charis_tile('birthdays', 'Birthdays', 'This month and next', 'party-popper', ['data-dialog-open' => 'charis-birthdays', 'aria-haspopup' => 'dialog']) ?>
            <?= charis_tile('life', 'Life events', 'Weddings and milestones', 'heart-handshake', ['data-dialog-open' => 'charis-life-events', 'aria-haspopup' => 'dialog']) ?>
            <?= charis_tile('awol', 'AWOL', 'Sundays missed in a row', 'triangle-alert', ['data-action' => 'charis.awol-focus']) ?>
        </div>

        <section class="ui-card" id="charis-awol" aria-labelledby="charis-awol-title" tabindex="-1">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row">
                    <h2 class="ui-card__title" id="charis-awol-title">AWOL Monitoring</h2>
                    <?= ui_info('Members who missed Sundays in a row. Any attendance resets the count. A case closes when they come back, or when you resolve it.', 'About AWOL Monitoring') ?>
                </div>
<?php if ($charisCanConfigure): ?>
                <?= ui_button('Change AWOL list', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'sliders-horizontal', 'attrs' => ['data-action' => 'charis.awol-config']]) ?>
<?php endif; ?>
            </div>
            <div class="ui-card__body space-y-3 border-b border-line">
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <div class="ui-segmented overflow-x-auto" role="group" aria-label="AWOL views">
                        <button type="button" class="ui-segmented__item" data-welfare-tab="my_cases" data-action="charis.welfare-tab" aria-pressed="true">My cases</button>
                        <button type="button" class="ui-segmented__item" data-welfare-tab="awol_list" data-action="charis.welfare-tab" aria-pressed="false">AWOL list</button>
                        <button type="button" class="ui-segmented__item" data-welfare-tab="manual_flags" data-action="charis.welfare-tab" aria-pressed="false">Manual flags</button>
                        <button type="button" class="ui-segmented__item" data-welfare-tab="archive" data-action="charis.welfare-tab" aria-pressed="false">Archive</button>
                    </div>
                    <div class="md:ml-auto md:w-72">
                        <label class="sr-only" for="charis-welfare-search">Search by name or phone</label>
                        <input class="ui-input" type="search" id="charis-welfare-search" placeholder="Name or phone" autocomplete="off" enterkeyhint="search" data-action-input="charis.welfare-search">
                    </div>
                </div>
                <div class="rounded-ui border border-line bg-surface-sunken" id="charis-awol-rule">
                    <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm" data-action="charis.rule-toggle" aria-expanded="false" aria-controls="charis-awol-rule-body">
                        <span class="font-semibold text-ink">Active rule</span>
                        <span class="truncate text-ink-muted" data-charis-rule-summary></span>
                        <span class="ui-badge ui-badge--outline ml-auto shrink-0" data-charis-rule-count>0 matching</span>
                        <?= ui_icon('chevron-down', 'shrink-0 transition-transform') ?>
                    </button>
                    <div class="ui-chips px-3 pb-3" id="charis-awol-rule-body" data-charis-rule-pills hidden></div>
                </div>
            </div>
            <ul class="ui-list" id="charis-welfare-list" aria-live="polite">
                <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>
            </ul>
        </section>
    </section>

    <!-- Events ----------------------------------------------------------- -->
    <section id="panel-events" data-tab-panel="events" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--sidebar">
            <section class="ui-card" aria-labelledby="charis-events-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row">
                        <h2 class="ui-card__title" id="charis-events-title">Event tasks</h2>
                        <?= ui_info('Give each person their own task, with a budget. They see only their tasks, report what they spent, and you sign off the event.', 'About event tasks') ?>
                    </div>
                    <div class="ui-segmented" role="group" aria-label="Which events">
                        <button type="button" class="ui-segmented__item" data-action="charis.events-view" data-view="upcoming" aria-pressed="true">Upcoming</button>
                        <button type="button" class="ui-segmented__item" data-action="charis.events-view" data-view="past" aria-pressed="false">Past</button>
                    </div>
                </div>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table--stack">
                        <thead><tr><th scope="col">Date</th><th scope="col">Event</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody id="charis-events-body">
                            <tr><td colspan="4"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="ui-card h-fit" aria-labelledby="charis-services-title">
                <div class="ui-card__header">
                    <div class="ui-title-row">
                        <h2 class="ui-card__title" id="charis-services-title">Sunday services</h2>
                        <?= ui_info('Creates every Sunday service in the month you pick, so tasks can be planned against them.', 'About Sunday services') ?>
                    </div>
                </div>
                <form class="ui-card__body ui-form" id="charis-services-form" data-action="charis.bulk-services" data-ui-validate novalidate>
                    <input type="hidden" name="action" value="bulk_create_services">
                    <?= ui_field('select', ['name' => 'month', 'id' => 'charis-services-month', 'label' => 'Month', 'required' => true, 'options' => ['' => 'Pick a month'] + $charisMonths]) ?>
                    <?= ui_button('Create services', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'icon' => 'calendar-check']) ?>
                </form>
            </section>

        </div>
    </section>

    <!-- Finance ---------------------------------------------------------- -->
    <section id="panel-finance" data-tab-panel="finance" class="ui-tab-panel" hidden>
        <div class="ui-toolbar mb-4">
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="ui-label" for="charis-fin-month">Month</label>
                    <select class="ui-select ui-select--sm" id="charis-fin-month" data-action-change="charis.finance-period">
<?php foreach ($charisMonths as $m => $label): ?>
                        <option value="<?= $m ?>"<?= $m === (int) date('n') ? ' selected' : '' ?>><?= ui_e($label) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ui-label" for="charis-fin-year">Year</label>
                    <select class="ui-select ui-select--sm" id="charis-fin-year" data-action-change="charis.finance-period">
<?php foreach ($charisYears as $y => $label): ?>
                        <option value="<?= $y ?>"><?= ui_e($label) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="ui-toolbar__end flex flex-wrap gap-2">
                <?= ui_button('Set budget', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'wallet', 'attrs' => ['data-dialog-open' => 'charis-budget']]) ?>
                <?= ui_button('Log expense', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'charis-expense']]) ?>
            </div>
        </div>

        <div class="ui-grid ui-grid--3 ui-grid--stats mb-6">
            <div class="ui-card ui-stat">
                <span class="ui-stat__label"><?= ui_icon('wallet') ?><span>Budget</span></span>
                <span class="ui-stat__value" id="charis-fin-budget">—</span>
                <span class="ui-stat__meta" id="charis-fin-budget-note"></span>
            </div>
            <div class="ui-card ui-stat">
                <span class="ui-stat__label"><?= ui_icon('trending-up') ?><span>Spent</span></span>
                <span class="ui-stat__value" id="charis-fin-actual">—</span>
                <span class="ui-stat__meta" data-charis-fin-period></span>
            </div>
            <div class="ui-card ui-stat">
                <span class="ui-stat__label"><?= ui_icon('arrow-up-down') ?><span>Variance</span></span>
                <span class="ui-stat__value" id="charis-fin-variance">—</span>
                <span class="ui-stat__meta" id="charis-fin-variance-note"></span>
            </div>
        </div>

        <div class="ui-grid ui-grid--2 mb-6">
            <section class="ui-card" aria-labelledby="charis-fin-trend-title">
                <div class="ui-card__header"><h2 class="ui-card__title" id="charis-fin-trend-title">Budget vs spent</h2></div>
                <div class="ui-card__body"><div class="relative h-64"><canvas id="charis-fin-bar" role="img" aria-label="Budget and spending over six months"></canvas></div></div>
            </section>
            <section class="ui-card" aria-labelledby="charis-fin-cat-title">
                <div class="ui-card__header"><h2 class="ui-card__title" id="charis-fin-cat-title">By category</h2></div>
                <div class="ui-card__body">
                    <div class="relative h-64"><canvas id="charis-fin-pie" role="img" aria-label="Spending by category"></canvas></div>
                    <p class="ui-hint hidden" data-charis-fin-pie-empty>No spending this month.</p>
                </div>
            </section>
        </div>

        <section class="ui-card mb-6" id="charis-forecast" aria-labelledby="charis-forecast-title" hidden>
            <div class="ui-card__header">
                <div class="ui-title-row">
                    <h2 class="ui-card__title" id="charis-forecast-title">Next month</h2>
                    <?= ui_info('A forecast per category: the average of the last three months.', 'About the forecast') ?>
                </div>
            </div>
            <div class="ui-card__body ui-grid ui-grid--3" id="charis-forecast-cards"></div>
        </section>

        <section class="ui-card" aria-labelledby="charis-ledger-title">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title" id="charis-ledger-title">Expenses</h2>
                <span class="ui-badge ui-badge--outline" data-charis-fin-period></span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Item</th><th scope="col">Category</th><th scope="col" class="ui-num">Requested</th><th scope="col" class="ui-num">Approved</th><th scope="col">Status</th></tr></thead>
                    <tbody id="charis-ledger-body">
                        <tr><td colspan="5"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </section>

    <!-- Library ---------------------------------------------------------- -->
    <section id="panel-library" data-tab-panel="library" class="ui-tab-panel" hidden>
        <div class="mb-4 flex justify-end">
            <?= ui_button('Add book', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'charis.add-book']]) ?>
        </div>
        <div class="ui-grid ui-grid--2 mb-6">
            <section class="ui-card" aria-labelledby="charis-borrows-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row">
                        <h2 class="ui-card__title" id="charis-borrows-title">Borrowed books</h2>
                        <?= ui_info('Reserved and borrowed physical books. Confirm the pickup when a member collects one, and process the return when it comes back.', 'About borrowed books') ?>
                    </div>
                </div>
                <ul class="ui-list" id="charis-borrows" aria-live="polite"><li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li></ul>
            </section>
            <section class="ui-card" aria-labelledby="charis-waitlist-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row">
                        <h2 class="ui-card__title" id="charis-waitlist-title">Waitlist</h2>
                        <?= ui_info('Members waiting for a copy. They are told when one comes back.', 'About the waitlist') ?>
                    </div>
                </div>
                <ul class="ui-list" id="charis-waitlist"></ul>
            </section>
        </div>
        <section class="ui-card" aria-labelledby="charis-catalog-title">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title" id="charis-catalog-title">Catalogue</h2>
                <span class="ui-badge ui-badge--outline" id="charis-catalog-count">0 books</span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Title</th><th scope="col">Author</th><th scope="col">Format</th><th scope="col">Copies</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody id="charis-catalog"><tr><td colspan="5"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr></tbody>
                </table>
            </div>
        </section>
    </section>

    <!-- Automation: the absence follow-up rule (can('charis', 'manage'), as the AWOL rules; includes/campaigns/automation_tab.php) -->
<?php if ($charisCanConfigure): ?>
    <section id="panel-automation" data-tab-panel="automation" class="ui-tab-panel" hidden>
<?php
    require_once __DIR__ . '/../../includes/campaigns/automation_tab.php';
    try {
        echo automation_tab_html($pdo, 'charis');
    } catch (Throwable $e) {
        error_log('[automation] Charis tab failed: ' . $e->getMessage());
        echo ui_banner('danger', ['title' => 'Automation did not load', 'text' => 'Reload the page to try again.']);
    }
?>
    </section>
<?php endif; ?>
    <!-- Guide ------------------------------------------------------------ -->
    <section id="panel-howto" data-tab-panel="howto" class="ui-tab-panel" hidden>
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
            <nav class="ui-card h-fit lg:sticky lg:top-4" aria-label="Guide sections">
                <ul class="ui-list">
<?php foreach ($charis_guide_toc as $h): ?>
                    <li><a class="ui-list__item text-sm" href="#<?= ui_e($h[1]) ?>"><?= ui_e(strip_tags($h[2])) ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>
            <article class="ui-card ui-card__body ui-prose max-w-none">
                <?= $charis_guide_html ?: '<p>The guide could not be loaded.</p>' ?>
            </article>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../../includes/charis_modals.php'; ?>
<?php require_once '../../includes/footer.php'; ?>
