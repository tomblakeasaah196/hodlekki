<?php
// /modules/ui_guide/index.php
/**
 * The living design system: every shared component, rendered by the real
 * stylesheet and scripts, in the real shell. It is the reference the module
 * rebuilds are checked against (docs/UI.md is the written half), and the
 * page that proves the strict Content-Security-Policy: no inline script, no
 * inline handler, no style attribute anywhere on it.
 *
 * Super Admins only (ui_nav_can_access('ui_guide')). All names and numbers
 * on it are invented.
 */
$ui = [
    'title' => 'Design System',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/ui-guide.js'],
];
require_once __DIR__ . '/../../includes/header.php';

$uiGuideAllowed = ui_nav_can_access('ui_guide', ui_nav_context($pdo));
if (!$uiGuideAllowed) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty([
        'icon' => 'lock-keyhole',
        'title' => 'This page is for system administrators',
        'text' => 'It shows the building blocks of the app. Everything you need is in the menu.',
        'actions' => ui_button('Go to your portal', ['variant' => 'primary', 'href' => '/modules/member_portal/index.php']),
    ]) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$sprite = (string) @file_get_contents(dirname(__DIR__, 2) . '/assets/ui/icons.svg');
preg_match_all('/<symbol id="([a-z0-9-]+)"/', $sprite, $m);
$iconNames = $m[1] ?? [];

$people = [
    ['Adaeze Okonkwo', 'Choir · Soprano', 'Active', 'success', '0803 555 0142', 'Lekki Phase 1'],
    ['Babatunde Adeyemi', 'Ushering · HOD', 'Active', 'success', '0805 555 0187', 'Ajah'],
    ['Chiamaka Eze', '2nd Timer', 'Follow-up due', 'warning', '0812 555 0103', 'Chevron'],
    ['Emeka Nwosu', 'Media · Camera', 'On leave', 'neutral', '0907 555 0164', 'Ikota'],
    ['Folasade Bello', 'Junior Church', 'Not reached', 'danger', '—', 'Sangotedo'],
];
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'Design System',
        'description' => 'The shared components every screen is built from — the same colours, type, spacing and behaviour on every module. The written rules are in docs/UI.md.',
        'actions' => ui_button('Show a toast', ['icon' => 'bell', 'attrs' => ['data-action' => 'guide.toast', 'data-tone' => 'success']])
            . ui_button('Open a dialog', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'guide-dialog']]),
    ]) ?>

    <?= ui_tabs([
        'foundations' => ['label' => 'Foundations', 'icon' => 'palette'],
        'components' => ['label' => 'Components', 'icon' => 'layout-dashboard'],
        'forms' => ['label' => 'Forms', 'icon' => 'pencil'],
        'feedback' => ['label' => 'Feedback', 'icon' => 'message-circle'],
    ], 'foundations', 'Design system sections') ?>

    <!-- ================================================================ -->
    <section id="panel-foundations" data-tab-panel="foundations" class="ui-tab-panel ui-stack">
        <div class="ui-card">
            <div class="ui-card__header">
                <div>
                    <h2 class="ui-card__title">Colour</h2>
                    <p class="ui-card__description">Pages use the semantic names only. Navy carries the app; red is the accent and the alarm, used sparingly.</p>
                </div>
            </div>
            <div class="ui-card__body">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
<?php
$swatches = [
    ['bg-brand-navy', 'Brand navy', '--ui-brand-navy'],
    ['bg-primary', 'Primary', '--ui-primary'],
    ['bg-brand-red', 'Brand red', '--ui-brand-red'],
    ['bg-canvas', 'Canvas', '--ui-canvas'],
    ['bg-surface', 'Surface', '--ui-surface'],
    ['bg-surface-sunken', 'Sunken', '--ui-surface-sunken'],
    ['bg-primary-soft', 'Primary soft', '--ui-primary-soft'],
    ['bg-success-soft', 'Success soft', '--ui-success-soft'],
    ['bg-warning-soft', 'Warning soft', '--ui-warning-soft'],
    ['bg-danger-soft', 'Danger soft', '--ui-danger-soft'],
    ['bg-info-soft', 'Info soft', '--ui-info-soft'],
    ['bg-ink', 'Text', '--ui-text'],
];
foreach ($swatches as [$cls, $name, $token]): ?>
                    <div class="overflow-hidden rounded-ui border border-line">
                        <div class="h-16 <?= ui_e($cls) ?>"></div>
                        <div class="px-3 py-2">
                            <p class="text-sm font-medium text-ink"><?= ui_e($name) ?></p>
                            <p class="ui-meta"><code><?= ui_e($token) ?></code></p>
                        </div>
                    </div>
<?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="ui-grid ui-grid--2">
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Type</h2></div>
                <div class="ui-card__body ui-stack ui-stack--md">
                    <p class="ui-h1">Page title · Montserrat 700</p>
                    <p class="ui-h2">Section title · Inter 600, 17 px</p>
                    <p class="ui-h3">Card title · Inter 600, 15 px</p>
                    <p>Body text is Inter at 15 px with a line height of 1.55, so a paragraph of pastoral notes reads comfortably on a phone.</p>
                    <p class="ui-meta">Meta and captions · 13 px, never smaller than 12 px</p>
                    <p class="ui-stat__value">₦1,284,500</p>
                </div>
            </div>
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Shape and space</h2></div>
                <div class="ui-card__body ui-stack ui-stack--md">
                    <p class="text-sm text-ink-muted">Spacing moves in steps of 4 and 8 px. Corners: 6 (badges), 10 (controls), 14 (cards), 20 (dialogs).</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="h-12 w-12 rounded-ui-sm bg-primary-soft"></div>
                        <div class="h-12 w-12 rounded-ui bg-primary-soft"></div>
                        <div class="h-12 w-12 rounded-ui-lg bg-primary-soft"></div>
                        <div class="h-12 w-12 rounded-ui-xl bg-primary-soft"></div>
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="h-12 w-24 rounded-ui-lg border border-line bg-surface"></div>
                        <div class="h-12 w-24 rounded-ui-lg bg-surface shadow-ui-md"></div>
                        <div class="h-12 w-24 rounded-ui-lg bg-surface shadow-ui-lg"></div>
                    </div>
                    <p class="ui-meta">Surfaces are separated by hairline borders; shadows are for things that float.</p>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card__header">
                <div>
                    <h2 class="ui-card__title">Icons</h2>
                    <p class="ui-card__description"><?= count($iconNames) ?> icons from one cached sprite. <code>ui_icon('search')</code> in PHP, <code>HOD.icon('search')</code> in script.</p>
                </div>
            </div>
            <div class="ui-card__body">
                <ul class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-8">
<?php foreach ($iconNames as $iconName): ?>
                    <li class="flex flex-col items-center gap-2 rounded-ui px-2 py-3 text-ink-muted hover:bg-surface-hover">
                        <?= ui_icon($iconName, 'ui-icon--lg') ?>
                        <span class="max-w-full truncate text-xs text-ink-subtle"><?= ui_e($iconName) ?></span>
                    </li>
<?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <!-- ================================================================ -->
    <section id="panel-components" data-tab-panel="components" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-grid ui-grid--stats">
            <?= ui_stat(['label' => 'Sunday attendance', 'icon' => 'users', 'value' => '1,284', 'delta' => 6.2, 'delta_label' => 'vs last week']) ?>
            <?= ui_stat(['label' => 'First timers', 'icon' => 'user-round-plus', 'value' => '37', 'delta' => -4, 'delta_label' => 'vs last week']) ?>
            <?= ui_stat(['label' => 'Follow-ups due', 'icon' => 'phone', 'value' => '12', 'meta' => '3 overdue']) ?>
            <?= ui_stat(['label' => 'Offering', 'icon' => 'wallet', 'value' => '₦842k', 'delta' => 0, 'delta_label' => 'flat']) ?>
        </div>

        <div class="ui-card">
            <div class="ui-card__header"><h2 class="ui-card__title">Buttons</h2></div>
            <div class="ui-card__body ui-stack ui-stack--md">
                <div class="ui-btn-group">
                    <?= ui_button('Save member', ['variant' => 'primary']) ?>
                    <?= ui_button('Export', ['icon' => 'download']) ?>
                    <?= ui_button('Assign worker', ['variant' => 'soft', 'icon' => 'user-round-plus']) ?>
                    <?= ui_button('Cancel', ['variant' => 'ghost']) ?>
                    <?= ui_button('Delete member', ['variant' => 'danger', 'icon' => 'trash-2']) ?>
                    <?= ui_button('Remove', ['variant' => 'danger-ghost']) ?>
                    <?= ui_button('Give online', ['variant' => 'brand']) ?>
                </div>
                <div class="ui-btn-group">
                    <?= ui_button('Small', ['size' => 'sm']) ?>
                    <?= ui_button('Medium') ?>
                    <?= ui_button('Large', ['size' => 'lg', 'variant' => 'primary']) ?>
                    <?= ui_button('Edit', ['icon' => 'pencil', 'icon_only' => true]) ?>
                    <?= ui_button('More actions', ['icon' => 'ellipsis', 'icon_only' => true, 'variant' => 'ghost', 'attrs' => ['data-menu-trigger' => true, 'aria-controls' => 'guide-menu', 'aria-haspopup' => 'menu', 'aria-expanded' => 'false']]) ?>
                    <?= ui_button('Save changes', ['variant' => 'primary', 'attrs' => ['data-action' => 'guide.busy']]) ?>
                    <?= ui_button('Not allowed', ['disabled' => true]) ?>
                </div>
                <div class="ui-menu" id="guide-menu" hidden>
                    <p class="ui-menu__label">Member</p>
                    <button type="button" class="ui-menu__item"><?= ui_icon('pencil') ?><span>Edit details</span></button>
                    <button type="button" class="ui-menu__item"><?= ui_icon('copy') ?><span>Copy phone number</span></button>
                    <button type="button" class="ui-menu__item"><?= ui_icon('printer') ?><span>Print record</span></button>
                    <hr class="ui-menu__sep">
                    <button type="button" class="ui-menu__item ui-menu__item--danger" data-action="guide.confirm"><?= ui_icon('trash-2') ?><span>Delete member</span></button>
                </div>
            </div>
        </div>

        <div class="ui-grid ui-grid--2">
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Badges, chips, avatars</h2></div>
                <div class="ui-card__body ui-stack ui-stack--md">
                    <div class="flex flex-wrap gap-2">
                        <?= ui_badge('Member') ?>
                        <?= ui_badge('Worker', 'primary') ?>
                        <?= ui_badge('Present', 'success', ['dot' => true]) ?>
                        <?= ui_badge('Follow-up due', 'warning', ['dot' => true]) ?>
                        <?= ui_badge('Absent 3 Sundays', 'danger', ['dot' => true]) ?>
                        <?= ui_badge('New', 'info') ?>
                        <?= ui_badge('12', 'brand', ['round' => true]) ?>
                    </div>
                    <div class="ui-chips" role="group" aria-label="Filter by status">
                        <button type="button" class="ui-chip" aria-pressed="true">All <span class="ui-count">58</span></button>
                        <button type="button" class="ui-chip" aria-pressed="false">1st timers</button>
                        <button type="button" class="ui-chip" aria-pressed="false">2nd timers</button>
                        <button type="button" class="ui-chip" aria-pressed="false">Unassigned</button>
                    </div>
                    <div class="ui-segmented" role="group" aria-label="View">
                        <button type="button" class="ui-segmented__item" aria-pressed="true"><?= ui_icon('list-filter', 'ui-icon--sm') ?>List</button>
                        <button type="button" class="ui-segmented__item" aria-pressed="false"><?= ui_icon('calendar', 'ui-icon--sm') ?>Calendar</button>
                        <button type="button" class="ui-segmented__item" aria-pressed="false"><?= ui_icon('chart-column', 'ui-icon--sm') ?>Insights</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <?= ui_avatar('Adaeze Okonkwo', null, 'xs') ?>
                        <?= ui_avatar('Babatunde Adeyemi', null, 'sm') ?>
                        <?= ui_avatar('Chiamaka Eze') ?>
                        <?= ui_avatar('Emeka Nwosu', null, 'lg') ?>
                        <?= ui_avatar('Folasade Bello', null, 'xl') ?>
                    </div>
                </div>
            </div>
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Progress, steps, loading</h2></div>
                <div class="ui-card__body ui-stack ui-stack--md">
                    <div>
                        <div class="mb-2 flex justify-between text-sm"><span>Building fund</span><span class="ui-num text-ink-muted">64%</span></div>
                        <progress class="ui-progress" value="64" max="100">64%</progress>
                    </div>
                    <progress class="ui-progress ui-progress--success ui-progress--sm" value="90" max="100">90%</progress>
                    <ol class="ui-steps" aria-label="Add a first timer">
                        <li class="ui-step is-done"><span class="ui-step__label">Details</span></li>
                        <li class="ui-step" aria-current="step"><span class="ui-step__label">Address</span></li>
                        <li class="ui-step"><span class="ui-step__label">Follow-up</span></li>
                    </ol>
                    <div class="flex items-center gap-4 text-ink-subtle">
                        <span class="ui-spinner ui-spinner--sm"></span>
                        <span class="ui-spinner"></span>
                        <span class="ui-spinner ui-spinner--lg"></span>
                    </div>
                    <div aria-hidden="true">
                        <span class="ui-skeleton ui-skeleton--title"></span>
                        <span class="ui-skeleton ui-skeleton--text"></span>
                        <span class="ui-skeleton ui-skeleton--text w-2/3"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Table</h2>
                    <p class="ui-card__description">On a phone each row becomes a small card (resize the window).</p>
                </div>
                <div class="ui-btn-group">
                    <?= ui_button('Filter', ['icon' => 'sliders-horizontal', 'size' => 'sm']) ?>
                    <?= ui_button('Add member', ['icon' => 'plus', 'size' => 'sm', 'variant' => 'primary']) ?>
                </div>
            </div>
            <div class="ui-card__body ui-card__body--flush">
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table--stack">
                        <thead>
                            <tr><th scope="col">Name</th><th scope="col">Phone</th><th scope="col">Area</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                        </thead>
                        <tbody>
<?php foreach ($people as [$name, $role, $status, $tone, $phone, $area]): ?>
                            <tr>
                                <td class="is-head">
                                    <div class="flex items-center gap-3">
                                        <?= ui_avatar($name, null, 'sm') ?>
                                        <div class="min-w-0"><span class="ui-table__primary"><?= ui_e($name) ?></span><span class="ui-table__secondary"><?= ui_e($role) ?></span></div>
                                    </div>
                                </td>
                                <td data-label="Phone" class="ui-num is-nowrap"><?= ui_e($phone) ?></td>
                                <td data-label="Area"><?= ui_e($area) ?></td>
                                <td data-label="Status"><?= ui_badge($status, $tone, ['dot' => true]) ?></td>
                                <td class="is-actions"><?= ui_button('Open ' . $name, ['icon' => 'chevron-right', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ui-pagination">
                    <span>Showing 1–5 of 58</span>
                    <div class="ui-pagination__pages">
                        <?= ui_button('Previous page', ['icon' => 'chevron-left', 'icon_only' => true, 'size' => 'sm', 'disabled' => true]) ?>
                        <?= ui_button('Next page', ['icon' => 'chevron-right', 'icon_only' => true, 'size' => 'sm']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui-grid ui-grid--2">
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">List rows</h2></div>
                <ul class="ui-list">
                    <li><a class="ui-list__item" href="#tab=components"><span class="ui-list__icon"><?= ui_icon('calendar-check') ?></span><span class="ui-list__main"><span class="ui-list__title">Sunday Service — 1st service</span><span class="ui-list__meta">Sun 15 Nov · 7:30 am · Main auditorium</span></span><span class="ui-list__end"><?= ui_badge('Upcoming', 'info') ?><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="#tab=components"><span class="ui-list__icon"><?= ui_icon('flame') ?></span><span class="ui-list__main"><span class="ui-list__title">Midweek prayer</span><span class="ui-list__meta">Wed 18 Nov · 6:00 pm</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="#tab=components"><span class="ui-list__icon"><?= ui_icon('party-popper') ?></span><span class="ui-list__main"><span class="ui-list__title">Chara night</span><span class="ui-list__meta">Fri 27 Nov · 300 registered</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                </ul>
            </div>
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Details</h2></div>
                <div class="ui-card__body">
                    <dl class="ui-dl">
                        <dt>Full name</dt><dd>Adaeze Okonkwo</dd>
                        <dt>Department</dt><dd>River of Life · Soprano</dd>
                        <dt>Joined</dt><dd>12 March 2023</dd>
                        <dt>Address</dt><dd>14 Admiralty Way, Lekki Phase 1</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <?= ui_empty([
                'icon' => 'users',
                'title' => 'No first timers this Sunday yet',
                'text' => 'People added at the welcome desk appear here as they arrive. You can also add someone yourself.',
                'actions' => ui_button('Add a first timer', ['variant' => 'primary', 'icon' => 'plus']),
            ]) ?>
        </div>
    </section>

    <!-- ================================================================ -->
    <section id="panel-forms" data-tab-panel="forms" class="ui-tab-panel" hidden>
        <form class="ui-card" data-ui-validate data-action="guide.save" novalidate>
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Add a first timer</h2>
                    <p class="ui-card__description">Labels above every field, errors when you leave a field, cleared as soon as it is right.</p>
                </div>
            </div>
            <div class="ui-card__body ui-form">
                <div class="ui-form-grid">
                    <?= ui_field('text', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'autocomplete' => 'given-name']) ?>
                    <?= ui_field('text', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'autocomplete' => 'family-name']) ?>
                    <?= ui_field('tel', ['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'inputmode' => 'tel', 'placeholder' => '0803 123 4567', 'hint' => 'We send one welcome text, nothing else.', 'attrs' => ['pattern' => '[0-9 +]{10,16}']]) ?>
                    <?= ui_field('email', ['name' => 'email', 'label' => 'Email', 'optional' => true, 'autocomplete' => 'email']) ?>
                    <?= ui_field('select', ['name' => 'service', 'label' => 'Service attended', 'required' => true, 'options' => ['' => 'Choose a service', '1' => 'Sunday — 1st service', '2' => 'Sunday — 2nd service', '3' => 'Midweek']]) ?>
                    <div class="ui-field">
                        <label class="ui-label" for="guide-area">Area</label>
                        <select class="ui-select" id="guide-area" name="area" data-ui-combobox data-placeholder="Type to find an area" aria-describedby="guide-area-hint">
                            <option value="">Choose an area</option>
<?php foreach (['Ajah', 'Abraham Adesanya', 'Agungi', 'Badore', 'Chevron', 'Ikota', 'Ikate', 'Jakande', 'Lekki Phase 1', 'Oniru', 'Osapa London', 'Sangotedo', 'VGC'] as $area): ?>
                            <option value="<?= ui_e($area) ?>"><?= ui_e($area) ?></option>
<?php endforeach; ?>
                        </select>
                        <p class="ui-hint" id="guide-area-hint">Searchable: for long lists, add data-ui-combobox to the select.</p>
                    </div>
                    <?= ui_field('date', ['name' => 'visit', 'label' => 'Date of visit', 'value' => date('Y-m-d')]) ?>
                    <?= ui_field('textarea', ['name' => 'notes', 'label' => 'Prayer request', 'optional' => true, 'class' => 'ui-span-full']) ?>
                </div>
                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">How did they hear about us?</legend>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="ui-check"><input type="radio" name="heard" value="friend" checked><span>A friend or family</span></label>
                        <label class="ui-check"><input type="radio" name="heard" value="online"><span>Online</span></label>
                        <label class="ui-check"><input type="radio" name="heard" value="outreach"><span>Outreach</span></label>
                    </div>
                </fieldset>
                <label class="ui-check"><input type="checkbox" name="consent" value="1"><span>They agreed to a follow-up call<span class="ui-check__hint">From the Embrace team within a week.</span></span></label>
                <label class="ui-check items-center"><input type="checkbox" class="ui-switch" name="sms" value="1" checked><span>Send the welcome text</span></label>
            </div>
            <div class="ui-card__footer">
                <div class="ui-form-actions w-full sm:w-auto">
                    <?= ui_button('Cancel', ['variant' => 'ghost', 'type' => 'reset']) ?>
                    <?= ui_button('Save first timer', ['variant' => 'primary', 'type' => 'submit']) ?>
                </div>
            </div>
        </form>
    </section>

    <!-- ================================================================ -->
    <section id="panel-feedback" data-tab-panel="feedback" class="ui-tab-panel ui-stack" hidden>
        <?= ui_banner('info', ['title' => 'Sunday register opens at 6:30 am', 'text' => 'Ushers can start checking people in from the welcome desk.']) ?>
        <?= ui_banner('success', ['title' => 'Roster published', 'text' => 'Everyone on the duty roster has been sent their slots.']) ?>
        <?= ui_banner('warning', ['title' => '3 follow-ups are overdue', 'text' => 'Call them today, or hand them to another worker.', 'actions' => ui_button('See follow-ups', ['size' => 'sm'])]) ?>
        <?= ui_banner('danger', ['title' => 'SMS wallet is empty', 'text' => 'Texts are paused until the wallet is topped up. Nothing has been lost.']) ?>

        <div class="ui-card">
            <div class="ui-card__header"><h2 class="ui-card__title">Toasts, confirmations, dialogs</h2></div>
            <div class="ui-card__body ui-btn-group">
                <?= ui_button('Success toast', ['attrs' => ['data-action' => 'guide.toast', 'data-tone' => 'success']]) ?>
                <?= ui_button('Error toast', ['attrs' => ['data-action' => 'guide.toast', 'data-tone' => 'danger']]) ?>
                <?= ui_button('Toast with undo', ['attrs' => ['data-action' => 'guide.toast', 'data-tone' => 'undo']]) ?>
                <?= ui_button('Confirm a deletion', ['variant' => 'danger-ghost', 'attrs' => ['data-action' => 'guide.confirm']]) ?>
                <?= ui_button('Ask for a reason', ['attrs' => ['data-action' => 'guide.prompt']]) ?>
                <?= ui_button('Open a drawer', ['attrs' => ['data-dialog-open' => 'guide-drawer']]) ?>
            </div>
        </div>
    </section>
</div>

<?= ui_dialog_start('guide-dialog', ['title' => 'Assign a worker', 'description' => 'They will see Chiamaka in their follow-up list straight away.']) ?>
    <div class="ui-stack ui-stack--md">
        <?= ui_field('select', ['name' => 'worker', 'label' => 'Worker', 'options' => ['' => 'Choose a worker', '1' => 'Adaeze Okonkwo', '2' => 'Babatunde Adeyemi']]) ?>
        <?= ui_field('textarea', ['name' => 'note', 'label' => 'Note for the worker', 'optional' => true]) ?>
    </div>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) . ui_button('Assign worker', ['variant' => 'primary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?= ui_dialog_start('guide-drawer', ['title' => 'Chiamaka Eze', 'description' => '2nd Timer · added 1 Nov', 'size' => 'drawer']) ?>
    <dl class="ui-dl">
        <dt>Phone</dt><dd class="ui-num">0812 555 0103</dd>
        <dt>Area</dt><dd>Chevron</dd>
        <dt>Assigned to</dt><dd>Adaeze Okonkwo</dd>
        <dt>Last contact</dt><dd>Called on 5 Nov — will attend this Sunday</dd>
    </dl>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
