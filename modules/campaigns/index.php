<?php
// /modules/campaigns/index.php
// Email Campaigns: one email to many people, written in the block editor, sent
// now or at a set time, with an optional text for the people email cannot
// reach, and a report. On the foundation (strict CSP); the behaviour is
// assets/ui/js/pages/campaigns.js and every request goes to
// /api/campaigns_api.php. Logic: includes/campaigns/campaigns.php; access:
// includes/campaigns/campaign_actions.php. Guide: docs/EMAIL.md, "Campaigns".
//
//   index.php            the list, who email reaches, settings (managers)
//   index.php?id=<id>    one campaign: details, the email, send, the report
$ui = [
    'title' => 'Email Campaigns',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/email-editor.js', '/assets/ui/js/pages/campaigns.js'],
    'csp' => ['frame-src' => ["'self'"]],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/campaigns/campaign_actions.php';

// Access: Email Campaigns, view (Roles & permissions); each campaign is checked again by the API.
require_can('campaigns', 'view');
if (!campaigns_module_ready($pdo)) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty(['icon' => 'mail', 'title' => 'Email not ready yet',
        'text' => 'Try again after the next update.']) . '</div>';
    require_once '../../includes/footer.php';
    exit;
}

$me = (int) $_SESSION['user_id'];
$sender = campaign_sender();
$canStart = $sender['church'] || $sender['depts'] !== [];
$canManage = can('campaigns', 'manage');
$deptNames = [];
foreach ($pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $deptNames[(int) $d['id']] = (string) $d['name'];
}
$fromOptions = $sender['church'] ? ['' => 'Whole church'] : [];
foreach ($deptNames as $id => $name) {
    if ($sender['church'] || in_array($id, $sender['depts'], true)) {
        $fromOptions[(string) $id] = $name;
    }
}
$statusBadge = static function (string $s): string {
    [$label, $tone] = [
        'draft' => ['Draft', 'neutral'], 'scheduled' => ['Scheduled', 'info'], 'queued' => ['Starting', 'info'],
        'sending' => ['Sending', 'primary'], 'sent' => ['Sent', 'success'], 'cancelled' => ['Stopped', 'warning'],
        'failed' => ['Failed', 'danger'],
    ][$s] ?? [$s, 'neutral'];
    return ui_badge($label, $tone);
};
$when = static fn (?string $at): string => $at ? date('D j M, H:i', strtotime($at)) : '';

$id = (int) ($_GET['id'] ?? 0);
$campaign = $id > 0 ? campaign_get($pdo, $id) : null;
if ($id > 0 && ($campaign === null || !campaign_may('view', $campaign, $me))) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty(['icon' => 'mail', 'title' => 'Campaign not found',
        'text' => 'It was deleted, or it is not open to you.',
        'actions' => ui_button('All campaigns', ['variant' => 'primary', 'href' => '/modules/campaigns/'])]) . '</div>';
    require_once '../../includes/footer.php';
    exit;
}

echo ui_json_script('campaigns-config', [
    'id' => $campaign ? (int) $campaign['id'] : null,
    'canStart' => $canStart,
    'from' => $fromOptions,
    'church' => $sender['church'],
]);

// ---------------------------------------------------------------------------
// One campaign
// ---------------------------------------------------------------------------
if ($campaign !== null) {
    $opts = campaign_audience_options($pdo, $sender['church'], $sender['depts']);
    $check = static fn (string $name, string $value, string $label, string $action = 'cam.changed'): string =>
        '<label class="ui-check"><input type="checkbox" name="' . ui_e($name) . '" value="' . ui_e($value) . '" data-action-change="' . $action . '"><span>' . ui_e($label) . '</span></label>';
    $group = static function (string $kind, string $legend, array $items, string $name) use ($check): string {
        $boxes = '';
        foreach ($items as $o) {
            $boxes .= $check($name, (string) $o['value'], (string) $o['label']);
        }
        return '<fieldset class="ui-fieldset mt-3" data-cam-pick="' . ui_e($kind) . '" hidden><legend class="ui-label">' . ui_e($legend) . '</legend>'
            . '<div class="grid gap-2 sm:grid-cols-2">' . ($boxes !== '' ? $boxes : '<p class="ui-meta">None to choose from.</p>') . '</div></fieldset>';
    };
    $kinds = '';
    foreach ($opts['kinds'] as $k) {
        $kinds .= '<label class="ui-check rounded-ui-lg border border-line px-3 py-2"><input type="radio" name="kind" value="' . ui_e($k['value'])
            . '" data-action-change="cam.kind"><span>' . ui_e($k['label']) . '</span></label>';
    }
    ?>
<div class="ui-page" data-campaign>
    <?= ui_page_header([
        'title' => 'Email campaign',
        'info' => 'Write the email, choose who gets it, send a test to yourself, then send it now or set a time. People email cannot reach can get a text instead.',
        'back' => ['href' => '/modules/campaigns/', 'label' => 'Campaigns'],
    ]) ?>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <h2 class="ui-h2 mr-1" data-cam-name></h2>
        <span data-cam-status></span>
        <span class="ui-meta" data-cam-when></span>
    </div>
    <div data-cam-banner></div>

    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="ui-stack">
            <section class="ui-card" aria-labelledby="cam-details-title">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-card__title" id="cam-details-title">Details</h3></div>
                <form class="ui-card__body" data-cam-form data-action="cam.save" novalidate>
                    <fieldset class="ui-stack" data-cam-fields>
                        <?= ui_field('text', ['name' => 'name', 'id' => 'cam-name', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 120]]) ?>
                        <?= ui_field('select', ['name' => 'department_id', 'id' => 'cam-from', 'label' => 'From', 'options' => $fromOptions,
                            'info' => 'A department campaign goes to that department\'s people, and replies go to its mailbox when it has one.',
                            'attrs' => ['data-action-change' => 'cam.from']]) ?>
                        <fieldset class="ui-fieldset">
                            <legend class="ui-label">Who gets it</legend>
                            <div class="grid gap-2 sm:grid-cols-3"><?= $kinds ?></div>
                            <?= $group('statuses', 'Statuses', $opts['statuses'], 'statuses') ?>
                            <?= $group('departments', 'Departments', $opts['departments'], 'departments') ?>
                            <?= $group('regions', 'Regional hubs', $opts['regions'], 'regions') ?>
                        </fieldset>
                        <?= ui_field('email', ['name' => 'reply_to', 'id' => 'cam-reply', 'label' => 'Replies go to', 'optional' => true,
                            'info' => 'Leave empty to use the department\'s mailbox, or no reply address at all.', 'attrs' => ['maxlength' => 190]]) ?>
                        <div class="ui-stack ui-stack--sm">
                            <label class="ui-check"><input type="checkbox" name="sms_fallback" value="1" data-action-change="cam.sms"><span>Text the rest</span></label>
                            <div data-cam-sms hidden>
                                <?= ui_field('textarea', ['name' => 'sms_text', 'id' => 'cam-sms-text', 'label' => 'Text',
                                    'info' => 'Sent through SMS Studio to people with no email we may use. {{first_name}} works here too.',
                                    'attrs' => ['rows' => 3, 'maxlength' => 480, 'data-action-input' => 'cam.sms-count']]) ?>
                                <p class="ui-meta" data-cam-sms-count>0 characters</p>
                            </div>
                            <label class="ui-check"><input type="checkbox" name="track_opens" value="1"><span>Count opens</span></label>
                        </div>
                    </fieldset>
                    <div class="mt-4 flex flex-wrap items-center gap-2" data-cam-save-row>
                        <?= ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check']) ?>
                        <span class="ui-meta" data-cam-saved aria-live="polite"></span>
                    </div>
                </form>
            </section>

            <section class="ui-card" aria-labelledby="cam-email-title">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-card__title" id="cam-email-title">Email</h3></div>
                <div class="ui-card__body">
                    <p class="font-medium" data-cam-subject></p>
                    <p class="ui-meta" data-cam-preheader></p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?= ui_button('Edit email', ['variant' => 'secondary', 'icon' => 'pencil', 'attrs' => ['data-action' => 'cam.edit', 'data-cam-edit' => true]]) ?>
                        <?= ui_button('Preview', ['variant' => 'ghost', 'icon' => 'eye', 'attrs' => ['data-action' => 'cam.preview']]) ?>
                    </div>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="cam-report-title" data-cam-report hidden>
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-card__title" id="cam-report-title">Report</h3>
                    <?= ui_button('Refresh', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'cam.report']]) ?></div>
                <div class="ui-card__body ui-stack">
                    <div class="grid gap-3 sm:grid-cols-3" data-cam-tiles></div>
                    <div data-cam-links></div>
                    <div>
                        <?= ui_field('search', ['name' => 'filter', 'id' => 'cam-filter', 'label' => 'Find a person', 'attrs' => ['data-action-input' => 'cam.filter']]) ?>
                        <div data-cam-people></div>
                    </div>
                </div>
            </section>
        </div>

        <aside class="ui-stack">
            <section class="ui-card" aria-labelledby="cam-send-title">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-card__title" id="cam-send-title">Send</h3>
                    <?= ui_info('Church news: everyone gets an unsubscribe link, people who unsubscribed are skipped, and email goes out between the sending hours set in Settings.') ?></div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <p data-cam-count aria-live="polite">Counting…</p>
                    <ul class="ui-meta list-disc pl-5" data-cam-reasons></ul>
                    <ul class="text-sm text-danger list-disc pl-5" data-cam-problems></ul>
                    <div class="flex flex-wrap gap-2" data-cam-actions>
                        <?= ui_button('Send test', ['variant' => 'secondary', 'icon' => 'send', 'attrs' => ['data-action' => 'cam.test', 'data-cam-can' => 'send']]) ?>
                        <?= ui_button('Send', ['variant' => 'primary', 'icon' => 'megaphone', 'attrs' => ['data-action' => 'cam.send-open', 'data-cam-can' => 'send']]) ?>
                        <?= ui_button('Back to draft', ['variant' => 'secondary', 'attrs' => ['data-action' => 'cam.unschedule', 'data-cam-can' => 'unschedule']]) ?>
                        <?= ui_button('Stop', ['variant' => 'danger', 'icon' => 'circle-x', 'attrs' => ['data-action' => 'cam.cancel', 'data-cam-can' => 'cancel']]) ?>
                    </div>
                    <div class="flex flex-wrap gap-2 border-t border-line pt-3">
                        <?= ui_button('Copy', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'copy', 'attrs' => ['data-action' => 'cam.copy', 'data-cam-can' => 'copy']]) ?>
                        <?= ui_button('Delete', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'trash-2', 'attrs' => ['data-action' => 'cam.delete', 'data-cam-can' => 'delete']]) ?>
                    </div>
                </div>
            </section>
        </aside>
    </div>

    <?= ui_dialog_start('cam-send', ['title' => 'Send campaign', 'size' => 'sm']) ?>
        <p data-cam-send-line></p>
        <fieldset class="ui-fieldset mt-4"><legend class="sr-only">When</legend><div class="ui-stack ui-stack--sm">
            <label class="ui-check"><input type="radio" name="cam-when" value="now" checked data-action-change="cam.when"><span>Now</span></label>
            <label class="ui-check"><input type="radio" name="cam-when" value="later" data-action-change="cam.when"><span>Later</span></label>
        </div></fieldset>
        <div class="mt-3" data-cam-later hidden>
            <?= ui_field('datetime-local', ['name' => 'at', 'id' => 'cam-at', 'label' => 'Date and time']) ?>
        </div>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Send', ['variant' => 'primary', 'attrs' => ['data-action' => 'cam.send', 'data-cam-send-btn' => true]])
    ) ?>

    <?= ui_dialog_start('cam-editor', ['title' => 'Edit email', 'size' => 'xl']) ?>
        <div data-cam-editor-root></div>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Save email', ['variant' => 'primary', 'attrs' => ['data-action' => 'cam.save-email']])
    ) ?>

    <?= ui_dialog_start('cam-preview', ['title' => 'Preview', 'size' => 'lg']) ?>
        <div class="mb-3 flex justify-end">
            <div class="ui-segmented" role="group" aria-label="Width">
                <button type="button" class="ui-segmented__item" data-action="cam.width" data-width="375" aria-pressed="false"><?= ui_icon('smartphone', 'ui-icon--sm') ?>Phone</button>
                <button type="button" class="ui-segmented__item" data-action="cam.width" data-width="640" aria-pressed="true"><?= ui_icon('layout-dashboard', 'ui-icon--sm') ?>Desktop</button>
            </div>
        </div>
        <div class="overflow-hidden rounded-ui-lg border border-line bg-surface-sunken">
            <iframe class="mx-auto block h-[70vh] w-full max-w-[640px] bg-white" title="Email preview" sandbox="" referrerpolicy="no-referrer" data-cam-frame></iframe>
        </div>
    <?= ui_dialog_end(ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])) ?>
</div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------------
$rows = campaign_list($pdo, perm_scope('campaigns', 'view'), $me);
$cov = email_coverage($pdo);
$tabs = ['list' => ['label' => 'Campaigns', 'icon' => 'megaphone'], 'reach' => ['label' => 'Reach', 'icon' => 'users']];
if ($canManage) {
    $tabs['settings'] = ['label' => 'Settings', 'icon' => 'settings'];
}
?>
<div class="ui-page ui-page--wide" data-campaigns>
    <?= ui_page_header([
        'title' => 'Email Campaigns',
        'description' => 'One email to many people.',
        'info' => 'Write it in the email editor, choose who gets it, test it on yourself, then send it now or at a set time. Everyone gets an unsubscribe link; people email cannot reach can get a text instead.',
        'actions' => $canStart ? ui_button('New campaign', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'cam-new']]) : '',
    ]) ?>

    <?= ui_tabs($tabs, 'list', 'Campaign sections') ?>

    <section id="panel-list" data-tab-panel="list" class="ui-tab-panel">
        <?php if ($rows === []): ?>
            <?= ui_empty(['icon' => 'megaphone', 'title' => 'No campaigns yet', 'text' => 'Start one to email your people.',
                'actions' => $canStart ? ui_button('New campaign', ['variant' => 'primary', 'attrs' => ['data-dialog-open' => 'cam-new']]) : '']) ?>
        <?php else: ?>
            <div class="ui-table-wrap"><table class="ui-table">
                <thead><tr><th scope="col">Name</th><th scope="col">From</th><th scope="col">Status</th><th scope="col">When</th><th scope="col" class="text-right">Reached</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a class="ui-link font-medium" href="/modules/campaigns/?id=<?= (int) $r['id'] ?>"><?= ui_e($r['name']) ?></a>
                            <span class="block ui-meta"><?= ui_e($r['subject']) ?></span></td>
                        <td><?= ui_e($r['department_id'] ? ($deptNames[$r['department_id']] ?? 'Department') : 'Whole church') ?></td>
                        <td><?= $statusBadge((string) $r['status']) ?></td>
                        <td class="whitespace-nowrap"><?= ui_e($when($r['queued_at'] ?? $r['scheduled_at'] ?? $r['updated_at'] ?? $r['created_at'])) ?></td>
                        <td class="text-right tabular-nums"><?= $r['total'] ? ui_e($r['emailed'] . ' email · ' . $r['texted'] . ' text') : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section id="panel-reach" data-tab-panel="reach" class="ui-tab-panel" hidden>
        <div class="grid gap-5 md:grid-cols-2">
        <?php foreach (['members' => 'Members', 'first_timers' => 'First timers'] as $key => $label): $c = $cov[$key]; ?>
            <section class="ui-card" aria-labelledby="cam-cov-<?= $key ?>">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="cam-cov-<?= $key ?>"><?= ui_e($label) ?></h2>
                    <span class="ui-badge"><?= (int) $c['total'] ?> people</span></div>
                <div class="ui-card__body">
                    <dl class="grid grid-cols-2 gap-3">
                        <?php foreach (['news' => 'By email', 'sms_only' => 'By text only', 'unreachable' => 'Not reached', 'unsubscribed' => 'Unsubscribed', 'pending' => 'Not confirmed yet'] as $k => $l): ?>
                            <div><dt class="ui-meta"><?= ui_e($l) ?></dt><dd class="text-lg font-semibold tabular-nums"><?= (int) $c[$k] ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </section>
        <?php endforeach; ?>
        </div>
    </section>

    <?php if ($canManage): $s = campaign_settings_view($pdo); ?>
    <section id="panel-settings" data-tab-panel="settings" class="ui-tab-panel" hidden>
        <form class="ui-card" data-action="cam.settings" novalidate aria-labelledby="cam-settings-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="cam-settings-title">Email settings</h2></div>
            <div class="ui-card__body ui-stack">
                <?= ui_field('number', ['name' => 'hourly_cap', 'id' => 'cam-cap', 'label' => 'Emails per hour', 'value' => $s['hourly_cap'], 'required' => true,
                    'info' => 'The host\'s limit for hodlekki.org (cPanel → Email Deliverability). People\'s own mail and urgent mail always keep 40% of it.'
                        . ($s['learned_cap'] !== '' ? ' The server last said its limit is ' . $s['learned_cap'] . '.' : ''),
                    'attrs' => ['min' => 10, 'max' => 10000]]) ?>
                <div class="grid gap-3 sm:grid-cols-2">
                    <?= ui_field('time', ['name' => 'window_start', 'id' => 'cam-start', 'label' => 'Send from', 'value' => $s['window_start'], 'required' => true]) ?>
                    <?= ui_field('time', ['name' => 'window_end', 'id' => 'cam-end', 'label' => 'Until', 'value' => $s['window_end'], 'required' => true]) ?>
                </div>
                <?= ui_field('text', ['name' => 'postal_line', 'id' => 'cam-postal', 'label' => 'Postal address', 'value' => $s['postal_line'], 'required' => true,
                    'info' => 'Shown at the foot of every church email, as bulk-mail rules ask.', 'attrs' => ['maxlength' => 200]]) ?>
                <label class="ui-check"><input type="checkbox" name="paused" value="1"<?= $s['paused'] === '1' ? ' checked' : '' ?>><span>Pause all email<span class="ui-check__hint">Nothing leaves until you untick it.</span></span></label>
                <p class="ui-meta">This hour: <?= (int) $s['budget']['sent'] ?> of <?= (int) $s['budget']['usable'] ?> sent<?= $s['budget']['refused'] ? ' · the host refused more' : '' ?>.
                    <?= $s['bounces_last'] !== '' ? 'Bounces: ' . ui_e($s['bounces_last']) : '' ?></p>
                <div><?= ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check']) ?></div>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($canStart): ?>
    <?= ui_dialog_start('cam-new', ['title' => 'New campaign', 'size' => 'sm']) ?>
        <form class="ui-stack" id="cam-new-form" data-action="cam.create" novalidate>
            <?= ui_field('text', ['name' => 'name', 'id' => 'cam-new-name', 'label' => 'Name', 'required' => true, 'placeholder' => 'Harvest Sunday', 'attrs' => ['maxlength' => 120]]) ?>
            <?= ui_field('select', ['name' => 'department_id', 'id' => 'cam-new-from', 'label' => 'From', 'options' => $fromOptions]) ?>
        </form>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Start', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'cam-new-form']])
    ) ?>
    <?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
