<?php
// /modules/assimilation/index.php
// Assimilation: find the people who have drifted (managers), follow them up
// (everyone on the team), the team, analytics with the monthly PDF, and the
// guide (modules/assimilation/how_to_use.md) plus the volunteer guide.
// Data comes from /api/assimilation_api.php. Scripts:
//   assets/ui/js/pages/assimilation.js       follow-up, the case drawer, team, settings
//   assets/ui/js/pages/assimilation-find.js  Find people, watchlists, the list builder, analytics
$ui = [
    'title' => 'Assimilation',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/assimilation.js', '/assets/ui/js/pages/assimilation-find.js'],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/assimilation_helpers.php';

// Access: managers by the 'assimilation' row of the permission matrix
// (manage); volunteers by the module's own team roster. The API checks the same.
$assim_user_id = (int) ($_SESSION['user_id'] ?? 0);
$assim_manager = can('assimilation', 'manage');
$assim_on_team = false;
try {
    $assim_on_team = assim_is_team_member($pdo, $assim_user_id);
} catch (PDOException $e) {
    error_log('Assimilation module gate: ' . $e->getMessage());
}
if (!$assim_manager && !$assim_on_team) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo ui_no_access('Ask an Assimilation leader to add you to the team.', 'Team members only');
    require_once '../../includes/footer.php';
    exit;
}

$assim_overdue_days = ASSIM_OVERDUE_DAYS;
try {
    $assim_overdue_days = assim_overdue_days($pdo);
} catch (PDOException $e) {
    error_log('Assimilation overdue days: ' . $e->getMessage());
}
$assim_guide_html = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/', $assim_guide_html, $assim_toc, PREG_SET_ORDER);
// The volunteer guide is shown here read-only; managers edit it in settings.
$assim_volunteer_guide_html = '';
try {
    $assim_volunteer_guide_html = reach_markdown(assim_volunteer_guide($pdo));
} catch (Throwable $e) {
    error_log('Assimilation volunteer guide: ' . $e->getMessage());
}

$assim_tabs = $assim_manager
    ? ['find' => ['label' => 'Find people', 'icon' => 'search'], 'followup' => ['label' => 'Follow-up', 'icon' => 'phone'],
        'team' => ['label' => 'Team', 'icon' => 'users-round'], 'analytics' => ['label' => 'Analytics', 'icon' => 'chart-column'],
        'howto' => ['label' => 'Guide', 'icon' => 'circle-help']]
    : ['followup' => ['label' => 'Follow-up', 'icon' => 'phone'], 'team' => ['label' => 'Team', 'icon' => 'users-round'],
        'howto' => ['label' => 'Guide', 'icon' => 'circle-help']];
$assim_first = (string) array_key_first($assim_tabs);
$assim_units = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'];

echo ui_json_script('assim-config', [
    'manager' => $assim_manager,
    'overdueDays' => (int) $assim_overdue_days,
]);

$headerActions = ui_button('Volunteer page', ['variant' => 'secondary', 'icon' => 'external-link', 'href' => '/assimilation.php',
        'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
    . ($assim_manager ? ui_button('Settings', ['variant' => 'ghost', 'icon' => 'settings', 'icon_only' => true, 'attrs' => ['data-action' => 'assim.settings']]) : '');
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Assimilation',
        'description' => 'Find who has drifted, and walk them home.',
        'info' => 'Managers find the people who have stopped coming and send a volunteer after them. Volunteers call, log what happened, and mark them home when they come back.',
        'actions' => $headerActions,
    ]) ?>

    <div class="mb-4" id="assim-celebration" hidden></div>

    <?= ui_tabs($assim_tabs, $assim_first, 'Assimilation sections') ?>

<?php if ($assim_manager): ?>
    <!-- Find people ------------------------------------------------------ -->
    <section id="panel-find" data-tab-panel="find" class="ui-tab-panel">
        <section class="ui-card mb-4 overflow-hidden" aria-labelledby="assim-hero-title">
            <div class="ui-card__body flex flex-col gap-4 sm:flex-row sm:items-center">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-ui-lg bg-primary-soft text-primary"><?= ui_icon('sprout', 'ui-icon--lg') ?></span>
                <div class="min-w-0 flex-1">
                    <h2 class="ui-h3" id="assim-hero-title">Build a watchlist</h2>
                    <p class="text-sm text-ink-muted">Choose who to watch, and who goes after them.</p>
                </div>
                <?= ui_button('Build a list', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'assim.builder']]) ?>
            </div>
        </section>

        <div class="mb-4 flex flex-wrap gap-2">
            <?= ui_button('Watchlists', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'clipboard-list', 'attrs' => ['data-action' => 'assim.watchlists']]) ?>
            <?= ui_button('Filters', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'sliders-horizontal', 'attrs' => ['data-action' => 'assim.filters', 'aria-expanded' => 'false', 'aria-controls' => 'assim-filters']]) ?>
            <?= ui_button('Download Excel', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['data-dialog-open' => 'assim-export']]) ?>
        </div>

        <section class="ui-card mb-4" id="assim-filters" aria-labelledby="assim-filters-title" hidden>
            <div class="ui-card__header">
                <div class="ui-title-row">
                    <h2 class="ui-card__title" id="assim-filters-title">Who has drifted?</h2>
                    <?= ui_info('Counts services attended in the window. Anyone below the number is on the list.', 'About the rule') ?>
                </div>
            </div>
            <div class="ui-card__body space-y-4">
                <div class="flex flex-wrap items-end gap-2 text-sm">
                    <span class="pb-2">Fewer than</span>
                    <div><label class="sr-only" for="assim-rule-max">Number of services</label><input class="ui-input w-20" type="number" id="assim-rule-max" min="1" max="200" value="3" inputmode="numeric" data-action-change="assim.find"></div>
                    <span class="pb-2">services in the past</span>
                    <div><label class="sr-only" for="assim-rule-window">Window length</label><input class="ui-input w-20" type="number" id="assim-rule-window" min="1" max="104" value="2" inputmode="numeric" data-action-change="assim.find"></div>
                    <div><label class="sr-only" for="assim-rule-unit">Window unit</label>
                        <select class="ui-select" id="assim-rule-unit" data-action-change="assim.find">
<?php foreach ($assim_units as $v => $l): ?>
                            <option value="<?= $v ?>"<?= $v === 'months' ? ' selected' : '' ?>><?= $l ?></option>
<?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ui-chips" role="group" aria-label="Common rules">
                    <button type="button" class="ui-chip" data-action="assim.preset" data-max="1" data-win="1" data-unit="months">Under 1 a month</button>
                    <button type="button" class="ui-chip" data-action="assim.preset" data-max="3" data-win="2" data-unit="months">Under 3 in 2 months</button>
                    <button type="button" class="ui-chip" data-action="assim.preset" data-max="5" data-win="3" data-unit="months">Under 5 in 3 months</button>
                </div>
                <div class="ui-form-grid ui-form-grid--3">
                    <?= ui_field('search', ['name' => 'search', 'id' => 'assim-f-search', 'label' => 'Name or phone', 'attrs' => ['data-action-input' => 'assim.find-search']]) ?>
                    <?= ui_field('select', ['name' => 'department', 'id' => 'assim-f-dept', 'label' => 'Department', 'options' => ['0' => 'Any department'], 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('select', ['name' => 'region', 'id' => 'assim-f-region', 'label' => 'Region', 'options' => ['0' => 'Any region'], 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('select', ['name' => 'case_state', 'id' => 'assim-f-case', 'label' => 'Follow-up',
                        'options' => ['' => 'Assigned or not', 'no_case' => 'Nobody on them yet', 'open_case' => 'Already followed up'], 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('select', ['name' => 'gender', 'id' => 'assim-f-gender', 'label' => 'Gender', 'options' => ['' => 'Any gender', 'Male' => 'Male', 'Female' => 'Female'], 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('select', ['name' => 'age', 'id' => 'assim-f-age', 'label' => 'Age', 'options' => ['' => 'Any age'], 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('date', ['name' => 'before', 'id' => 'assim-f-before', 'label' => 'Last seen before', 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                    <?= ui_field('date', ['name' => 'after', 'id' => 'assim-f-after', 'label' => 'Last seen after', 'attrs' => ['data-action-change' => 'assim.find']]) ?>
                </div>
                <div>
                    <p class="ui-label mb-2">Spiritual status</p>
                    <div class="ui-chips" id="assim-f-status" role="group" aria-label="Spiritual status"></div>
                </div>
                <label class="ui-check"><input type="checkbox" id="assim-f-ever" checked data-action-change="assim.find"><span>Only people who have attended before</span></label>
                <div class="flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center">
                    <p class="flex-1 text-sm text-ink-muted" id="assim-rule-summary" aria-live="polite">—</p>
                    <div class="flex gap-2">
                        <?= ui_button('Save as watchlist', ['variant' => 'secondary', 'attrs' => ['data-action' => 'assim.save-wl']]) ?>
                        <?= ui_button('Find people', ['variant' => 'primary', 'icon' => 'search', 'attrs' => ['data-action' => 'assim.find']]) ?>
                    </div>
                </div>
            </div>
        </section>

        <div class="ui-banner ui-banner--info sticky top-2 z-10 mb-4" id="assim-bulk" hidden>
            <div class="ui-banner__body flex flex-col gap-3 sm:flex-row sm:items-center">
                <p class="ui-banner__title flex-1"><span id="assim-sel-count">0</span> selected</p>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="assim-bulk-assignee">Assign to</label>
                    <select class="ui-select ui-select--sm" id="assim-bulk-assignee"><option value="0">Leave unclaimed (the pool)</option></select>
                    <?= ui_button('Assign', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'assim.bulk-assign']]) ?>
                    <?= ui_button('Clear', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'assim.clear-selection']]) ?>
                </div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-ink-muted" id="assim-find-total" aria-live="polite"></p>
            <div class="flex items-center gap-2">
                <label class="text-sm text-ink-muted" for="assim-find-sort">Sort</label>
                <select class="ui-select ui-select--sm" id="assim-find-sort" data-action-change="assim.find">
                    <option value="drift">Away the longest</option>
                    <option value="fewest">Fewest services</option>
                    <option value="recent">Most recently seen</option>
                    <option value="name">Name</option>
                </select>
            </div>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" id="assim-find-list" aria-live="polite"></div>
        <div class="mt-4 flex justify-center"><?= ui_button('Load more', ['variant' => 'secondary', 'attrs' => ['id' => 'assim-find-more', 'data-action' => 'assim.find-more', 'hidden' => true]]) ?></div>
    </section>
<?php endif; ?>

    <!-- Follow-up -------------------------------------------------------- -->
    <section id="panel-followup" data-tab-panel="followup" class="ui-tab-panel"<?= $assim_first === 'followup' ? '' : ' hidden' ?>>
        <button type="button" class="ui-banner ui-banner--warning mb-4 w-full text-left" id="assim-overdue" data-action="assim.overdue" aria-pressed="false" hidden>
            <span class="ui-banner__body">
                <span class="ui-banner__title"><span id="assim-overdue-count">0</span> overdue</span>
                <span class="ui-banner__text" id="assim-overdue-hint">Assigned over <?= (int) $assim_overdue_days ?> days ago, nobody has called. Tap to show only these.</span>
            </span>
        </button>
        <div class="ui-chips mb-4 overflow-x-auto" id="assim-stages" role="group" aria-label="Follow-up stage"></div>
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
            <?= ui_field('search', ['name' => 'search', 'id' => 'assim-c-search', 'label' => 'Name or phone', 'class' => 'col-span-2 md:col-span-1', 'attrs' => ['data-action-input' => 'assim.cases-search']]) ?>
            <?= ui_field('select', ['name' => 'assignee', 'id' => 'assim-c-assignee', 'label' => 'Volunteer',
                'options' => ['' => 'Anyone', 'unassigned' => 'Unclaimed', 'me' => 'Mine'], 'attrs' => ['data-action-change' => 'assim.cases']]) ?>
            <?= ui_field('select', ['name' => 'watchlist', 'id' => 'assim-c-watchlist', 'label' => 'Watchlist', 'options' => ['0' => 'Any watchlist'], 'attrs' => ['data-action-change' => 'assim.cases']]) ?>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" id="assim-cases" aria-live="polite"></div>
        <div class="mt-4 flex justify-center"><?= ui_button('Load more', ['variant' => 'secondary', 'attrs' => ['id' => 'assim-cases-more', 'data-action' => 'assim.cases-more', 'hidden' => true]]) ?></div>
    </section>

    <!-- Team ------------------------------------------------------------- -->
    <section id="panel-team" data-tab-panel="team" class="ui-tab-panel" hidden>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="ui-title-row">
                <h2 class="ui-h3">The team</h2>
                <?= ui_info('Anyone in the congregation can be added, not only workers.', 'About the team') ?>
            </div>
<?php if ($assim_manager): ?>
            <?= ui_button('Add volunteers', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'user-round-plus', 'attrs' => ['data-action' => 'assim.picker']]) ?>
<?php endif; ?>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" id="assim-team" aria-live="polite"></div>
    </section>

<?php if ($assim_manager): ?>
    <!-- Analytics -------------------------------------------------------- -->
    <section id="panel-analytics" data-tab-panel="analytics" class="ui-tab-panel" hidden>
        <div class="ui-toolbar mb-4">
            <div class="ui-chips" id="assim-an-presets" role="group" aria-label="Date range">
<?php foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'last_3' => 'Last 3 months', 'ytd' => 'This year', 'custom' => 'Custom'] as $k => $l): ?>
                <button type="button" class="ui-chip" data-action="assim.an-preset" data-preset="<?= $k ?>" aria-pressed="<?= $k === 'this_month' ? 'true' : 'false' ?>"><?= $l ?></button>
<?php endforeach; ?>
            </div>
            <div class="ui-toolbar__end">
                <?= ui_button('Monthly PDF report', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'file-down', 'attrs' => ['id' => 'assim-an-pdf', 'data-action' => 'assim.an-pdf']]) ?>
            </div>
        </div>
        <div class="mb-4 flex flex-wrap items-end gap-2" id="assim-an-custom" hidden>
            <div><label class="ui-label" for="assim-an-from">From</label><input class="ui-input" type="date" id="assim-an-from" data-action-change="assim.an-load"></div>
            <div><label class="ui-label" for="assim-an-to">To</label><input class="ui-input" type="date" id="assim-an-to" data-action-change="assim.an-load"></div>
        </div>
        <div id="assim-an-pdf-ready" class="mb-4" aria-live="polite"></div>
        <div id="assim-an" aria-live="polite"></div>
    </section>
<?php endif; ?>

    <?php /* Mount point for the Automation tab (campaigns PR 4, api/automation_api.php):
       add an 'automation' entry to $assim_tabs (managers) and its
       <section id="panel-automation" data-tab-panel="automation"> here. */ ?>
    <!-- Guide ------------------------------------------------------------ -->
    <section id="panel-howto" data-tab-panel="howto" class="ui-tab-panel" hidden>
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
            <nav class="ui-card h-fit lg:sticky lg:top-4" aria-label="Guide sections">
                <ul class="ui-list">
<?php foreach ($assim_toc as $h): ?>
                    <li><a class="ui-list__item text-sm" href="#<?= ui_e($h[1]) ?>"><?= ui_e(strip_tags($h[2])) ?></a></li>
<?php endforeach; ?>
                    <li><a class="ui-list__item text-sm" href="#volunteer-guide">Following up with love</a></li>
                </ul>
            </nav>
            <div class="min-w-0 space-y-6">
                <article class="ui-card ui-card__body ui-prose max-w-none">
                    <?= $assim_guide_html ?: '<p>The guide could not be loaded.</p>' ?>
                </article>
<?php if ($assim_volunteer_guide_html !== ''): ?>
                <article class="ui-card ui-card__body ui-prose max-w-none scroll-mt-24" id="volunteer-guide">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <div class="ui-title-row">
                            <h2 class="ui-h2">Following up with love</h2>
                            <?= ui_info('The volunteer guide, shown to every volunteer on the public page.', 'About the volunteer guide') ?>
                        </div>
<?php if ($assim_manager): ?>
                        <?= ui_button('Edit guide', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil', 'attrs' => ['data-action' => 'assim.settings', 'data-focus' => 'guide']]) ?>
<?php endif; ?>
                    </div>
                    <?= $assim_volunteer_guide_html ?>
                </article>
<?php endif; ?>
            </div>
        </div>
    </section>
</div>

<!-- The case drawer (everyone) ------------------------------------------ -->
<?= ui_dialog_start('assim-case', ['title' => 'Follow-up', 'description' => ' ', 'size' => 'drawer']) ?>
    <div id="assim-case-body" class="space-y-6"></div>
<?= ui_dialog_end() ?>

<?php if ($assim_manager): ?>
<?= ui_dialog_start('assim-picker', ['title' => 'Add volunteers', 'description' => 'Anyone in the congregation can join the team.', 'size' => 'lg']) ?>
    <label class="sr-only" for="assim-picker-search">Search the congregation</label>
    <input class="ui-input mb-3" type="search" id="assim-picker-search" placeholder="Name or phone" autocomplete="off" data-action-input="assim.picker-search">
    <div class="space-y-1" id="assim-picker-list" aria-live="polite"></div>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add', ['variant' => 'primary', 'icon' => 'user-round-plus', 'attrs' => ['id' => 'assim-picker-add', 'data-action' => 'assim.picker-add']])) ?>

<?= ui_dialog_start('assim-watchlists', ['title' => 'Watchlists', 'description' => 'Saved rules that tell the team who newly drifted in.', 'size' => 'lg']) ?>
    <div class="space-y-3" id="assim-wl-list" aria-live="polite"></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('assim-save-wl', ['title' => 'Save as watchlist', 'description' => ' ']) ?>
    <form class="ui-form" id="assim-save-wl-form" data-action="assim.save-wl-submit" data-ui-validate novalidate>
        <?= ui_field('text', ['name' => 'name', 'id' => 'assim-wl-name', 'label' => 'Name', 'required' => true, 'placeholder' => 'Workers, under 3 in 2 months', 'attrs' => ['maxlength' => 120]]) ?>
        <label class="ui-check"><input type="checkbox" id="assim-wl-notify" checked><span>Daily digest to the team</span></label>
        <label class="ui-check"><input type="checkbox" id="assim-wl-open"><span>Open to every volunteer<span class="ui-check__hint">Whoever picks someone first takes them.</span></span></label>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save watchlist', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'assim-save-wl-form']])) ?>

<?= ui_dialog_start('assim-settings', ['title' => 'Settings', 'description' => 'How Assimilation works for the whole team.', 'size' => 'lg']) ?>
    <form class="ui-form" id="assim-settings-form" data-action="assim.settings-save" data-ui-validate novalidate>
        <?= ui_field('number', ['name' => 'overdue_days', 'id' => 'assim-set-overdue', 'label' => 'Overdue after (days)', 'required' => true, 'inputmode' => 'numeric',
            'info' => 'Counted from when a volunteer was given the person, with no contact logged since.', 'attrs' => ['min' => 1, 'max' => 90]]) ?>
        <label class="ui-check"><input type="checkbox" id="assim-set-claim" name="allow_self_claim" value="1">
            <span>Volunteers may claim people<span class="ui-check__hint">When off, only managers hand people out.</span></span></label>
        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Default watchlist rule</legend>
            <div class="flex flex-wrap items-end gap-2 text-sm">
                <span class="pb-2">Fewer than</span>
                <div><label class="sr-only" for="assim-set-max">Default number of services</label><input class="ui-input w-20" type="number" id="assim-set-max" name="max_services" min="1" max="200" inputmode="numeric"></div>
                <span class="pb-2">in</span>
                <div><label class="sr-only" for="assim-set-window">Default window length</label><input class="ui-input w-20" type="number" id="assim-set-window" name="window_value" min="1" max="104" inputmode="numeric"></div>
                <div><label class="sr-only" for="assim-set-unit">Default window unit</label>
                    <select class="ui-select" id="assim-set-unit" name="window_unit">
<?php foreach ($assim_units as $v => $l): ?>
                        <option value="<?= $v ?>"><?= $l ?></option>
<?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="ui-check mt-3"><input type="checkbox" id="assim-set-ever" name="ever_attended" value="1"><span>Only people who have attended before</span></label>
        </fieldset>
        <div>
            <div class="mb-1 flex items-center justify-between gap-2">
                <div class="ui-label-row">
                    <label class="ui-label" for="assim-set-guide">Volunteer guide</label>
                    <?= ui_info('Shown on the volunteer page and here. Use ## for headings, - for lists, > for scripture, **bold**.', 'About the volunteer guide') ?>
                </div>
                <?= ui_button('Restore default', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'assim.guide-restore']]) ?>
            </div>
            <textarea class="ui-textarea font-mono text-sm" id="assim-set-guide" name="guide" rows="12"></textarea>
        </div>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save settings', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'assim-settings-form']])) ?>

<?= ui_dialog_start('assim-export', ['title' => 'Download Excel', 'description' => 'The people and filters shown in Find people.', 'size' => 'sm']) ?>
    <div class="space-y-2">
        <button type="button" class="ui-card ui-card--interactive block w-full p-4 text-left" data-action="assim.export" data-report="general">
            <span class="block font-semibold text-ink">General register</span>
            <span class="block text-sm text-ink-muted">Name, +234 phone, gender and last service.</span>
        </button>
        <button type="button" class="ui-card ui-card--interactive block w-full p-4 text-left" data-action="assim.export" data-report="detailed">
            <span class="block font-semibold text-ink">Full report</span>
            <span class="block text-sm text-ink-muted">Adds status, departments, region, attendance and follow-up.</span>
        </button>
    </div>
<?= ui_dialog_end() ?>

<!-- The list builder: six short steps ------------------------------------ -->
<?= ui_dialog_start('assim-builder', ['title' => 'Build a watchlist', 'description' => 'Step 1 of 6', 'size' => 'lg']) ?>
    <div class="mb-5 flex items-center gap-1.5" id="assim-bw-dots" aria-hidden="true"></div>

    <section data-wstep="0" class="space-y-4 text-center">
        <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-primary-soft text-primary"><?= ui_icon('sprout', 'ui-icon--xl') ?></span>
        <h3 class="ui-h2" tabindex="-1">Let’s build a watchlist</h3>
        <p class="text-ink-muted">You choose who shows up, and who goes after them.</p>
        <div class="ui-chips justify-center">
            <span class="ui-chip"><?= ui_icon('search', 'ui-icon--sm') ?>We keep watch</span>
            <span class="ui-chip"><?= ui_icon('smartphone', 'ui-icon--sm') ?>Volunteers act</span>
            <span class="ui-chip"><?= ui_icon('house', 'ui-icon--sm') ?>People come home</span>
        </div>
    </section>

    <section data-wstep="1" class="space-y-4" hidden>
        <h3 class="ui-h3" tabindex="-1">How long have they been away?</h3>
        <div class="grid gap-2" role="radiogroup" aria-label="How long away">
<?php foreach ([
    'nos' => ['Missed a few Sundays', 'Not seen in about a month', 'calendar'],
    'slip' => ['Slipping away', 'Barely seen in 2 months', 'trending-down'],
    'gone' => ['Gone a while', 'Almost nothing in 3 months', 'clock'],
    'custom' => ['Set my own', 'Choose the exact numbers', 'sliders-horizontal'],
] as $k => [$t, $m, $ic]): ?>
            <button type="button" class="ui-card ui-card--interactive flex w-full items-center gap-3 p-3 text-left" role="radio" aria-checked="false" data-action="assim.bw-away" data-away="<?= $k ?>">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-ui bg-surface-sunken text-primary"><?= ui_icon($ic) ?></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold text-ink"><?= ui_e($t) ?></span><span class="block text-sm text-ink-muted"><?= ui_e($m) ?></span></span>
            </button>
<?php endforeach; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2 rounded-ui bg-surface-sunken p-3 text-sm" id="assim-bw-custom" hidden>
            <span>Fewer than</span>
            <?= ui_button('Fewer services', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'minus', 'icon_only' => true, 'attrs' => ['data-action' => 'assim.bw-step', 'data-field' => 'max', 'data-delta' => '-1']]) ?>
            <span class="w-8 text-center font-semibold tabular-nums" id="assim-bw-max">3</span>
            <?= ui_button('More services', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus', 'icon_only' => true, 'attrs' => ['data-action' => 'assim.bw-step', 'data-field' => 'max', 'data-delta' => '1']]) ?>
            <span>services in</span>
            <?= ui_button('Shorter window', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'minus', 'icon_only' => true, 'attrs' => ['data-action' => 'assim.bw-step', 'data-field' => 'win', 'data-delta' => '-1']]) ?>
            <span class="w-8 text-center font-semibold tabular-nums" id="assim-bw-win">2</span>
            <?= ui_button('Longer window', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus', 'icon_only' => true, 'attrs' => ['data-action' => 'assim.bw-step', 'data-field' => 'win', 'data-delta' => '1']]) ?>
            <label class="sr-only" for="assim-bw-unit">Window unit</label>
            <select class="ui-select ui-select--sm w-auto" id="assim-bw-unit" data-action-change="assim.bw-unit">
<?php foreach ($assim_units as $v => $l): ?>
                <option value="<?= $v ?>"<?= $v === 'months' ? ' selected' : '' ?>><?= $l ?></option>
<?php endforeach; ?>
            </select>
        </div>
    </section>

    <section data-wstep="2" class="space-y-4" hidden>
        <h3 class="ui-h3" tabindex="-1">Who should we watch?</h3>
        <p class="text-sm text-ink-muted">Everyone, or tap to narrow it.</p>
        <button type="button" class="ui-card ui-card--interactive flex w-full items-center gap-3 p-3 text-left" id="assim-bw-everyone" aria-pressed="true" data-action="assim.bw-everyone">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-ui bg-surface-sunken text-primary"><?= ui_icon('users-round') ?></span>
            <span class="min-w-0 flex-1"><span class="block font-semibold text-ink">Everyone in the house</span><span class="block text-sm text-ink-muted">First timers to pastors</span></span>
        </button>
        <div class="ui-chips" id="assim-bw-who" role="group" aria-label="Spiritual status"></div>
    </section>

    <section data-wstep="3" class="space-y-4" hidden>
        <h3 class="ui-h3" tabindex="-1">Fine-tune, if you like</h3>
        <p class="text-sm text-ink-muted">All optional. Skip ahead if it feels right.</p>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'bw_dept', 'id' => 'assim-bw-dept', 'label' => 'Department', 'options' => ['0' => 'Every department'], 'attrs' => ['data-action-change' => 'assim.bw-changed']]) ?>
            <?= ui_field('select', ['name' => 'bw_region', 'id' => 'assim-bw-region', 'label' => 'Region', 'options' => ['0' => 'Every region'], 'attrs' => ['data-action-change' => 'assim.bw-changed']]) ?>
        </div>
        <div><p class="ui-label mb-2">Gender</p><div class="ui-chips" id="assim-bw-gender" role="group" aria-label="Gender"></div></div>
        <div><p class="ui-label mb-2">Age</p><div class="ui-chips" id="assim-bw-age" role="group" aria-label="Age band"></div></div>
        <label class="ui-check"><input type="checkbox" id="assim-bw-ever" checked data-action-change="assim.bw-changed"><span>Only people who have attended before</span></label>
    </section>

    <section data-wstep="4" class="space-y-4" hidden>
        <h3 class="ui-h3" tabindex="-1">Who goes after them?</h3>
        <div class="grid gap-2" role="radiogroup" aria-label="Who works this list">
            <button type="button" class="ui-card ui-card--interactive flex w-full items-start gap-3 p-3 text-left" role="radio" aria-checked="true" data-action="assim.bw-mode" data-mode="open">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-ui bg-surface-sunken text-primary"><?= ui_icon('users') ?></span>
                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2 font-semibold text-ink">Open to every volunteer <span class="ui-badge ui-badge--success">Recommended</span></span>
                    <span class="block text-sm text-ink-muted">On every volunteer’s phone. First to pick takes them.</span></span>
            </button>
            <button type="button" class="ui-card ui-card--interactive flex w-full items-start gap-3 p-3 text-left" role="radio" aria-checked="false" data-action="assim.bw-mode" data-mode="managed">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-ui bg-surface-sunken text-primary"><?= ui_icon('shield-check') ?></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold text-ink">Managers assign</span>
                    <span class="block text-sm text-ink-muted">A private list. You hand each person out.</span></span>
            </button>
        </div>
        <label class="ui-check"><input type="checkbox" id="assim-bw-digest" checked data-action-change="assim.bw-digest"><span id="assim-bw-digest-label">Nudge the team when someone drifts in</span></label>
        <div class="ui-banner ui-banner--info" id="assim-bw-claim-note" hidden>
            <div class="ui-banner__body"><p class="ui-banner__text">Launching an open list lets volunteers pick people themselves.</p></div>
        </div>
    </section>

    <section data-wstep="5" class="space-y-4" hidden>
        <h3 class="ui-h3" tabindex="-1">Name it. Launch it.</h3>
        <div class="rounded-ui-lg bg-surface-sunken p-4 text-center" aria-live="polite">
            <p class="font-display text-4xl font-bold tabular-nums text-ink" id="assim-bw-count">…</p>
            <p class="text-sm text-ink-muted" id="assim-bw-count-sub">Counting…</p>
        </div>
        <?= ui_field('text', ['name' => 'bw_name', 'id' => 'assim-bw-name', 'label' => 'List name', 'placeholder' => 'Slipping away · Workers', 'attrs' => ['maxlength' => 120, 'data-action-input' => 'assim.bw-name']]) ?>
        <div class="ui-chips" id="assim-bw-recap"></div>
        <p class="ui-hint">People who come back drop off by themselves.</p>
    </section>
<?= ui_dialog_end(
    '<div class="ui-dialog__footer-start">' . ui_button('Back', ['variant' => 'ghost', 'icon' => 'arrow-left', 'attrs' => ['id' => 'assim-bw-back', 'data-action' => 'assim.bw-back']]) . '</div>'
    . '<div class="relative" id="assim-bw-export-wrap" hidden>'
    . ui_button('Download Excel', ['variant' => 'secondary', 'icon' => 'download', 'icon_end' => 'chevron-down',
        'attrs' => ['id' => 'assim-bw-export-btn', 'data-action' => 'assim.bw-export-menu', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false', 'aria-controls' => 'bwExportMenu']])
    . '<div class="ui-menu absolute bottom-full right-0 mb-2 w-72" id="bwExportMenu" role="menu" aria-labelledby="assim-bw-export-btn" hidden>'
    . '<p class="ui-menu__label">Everyone this list matches</p>'
    . '<button type="button" class="ui-menu__item" role="menuitem" data-action="assim.bw-export" data-report="general">General register</button>'
    . '<button type="button" class="ui-menu__item" role="menuitem" data-action="assim.bw-export" data-report="detailed">Full report</button>'
    . '</div></div>'
    . ui_button('Let’s go', ['variant' => 'primary', 'icon_end' => 'arrow-right', 'attrs' => ['id' => 'assim-bw-next', 'data-action' => 'assim.bw-next']])
) ?>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
