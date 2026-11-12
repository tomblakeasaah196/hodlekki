<?php
// /modules/sms_studio/index.php
// SMS Studio: compose, send, track and audit every SMS. On the foundation
// (strict CSP); the behaviour is assets/ui/js/pages/sms-studio.js and every
// request goes to /api/sms_api.php with the module's own token (_csrf),
// unchanged. All times are Lagos (WAT, UTC+1). Runbook: docs/sms_studio_runbook.md.
//
// Deep links: ?tab=history&phone=0803… · ?msg=<sms_log id> · ?campaign=<ids>
$ui = [
    'title' => 'SMS Studio',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/sms-studio.js'],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/sms_functions.php';
require_once __DIR__ . '/../../includes/assimilation_helpers.php';

// Server-side gate, the same as the SMS API (never rely on the sidebar alone).
if (!sms_user_can_send()) {
    echo ui_no_access('Ask a Super Admin if you need SMS Studio.');
    require_once '../../includes/footer.php';
    exit;
}
if (empty($_SESSION['sms_csrf'])) {
    $_SESSION['sms_csrf'] = bin2hex(random_bytes(32));
}
$smsStatuses = ['Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Member', 'Worker', 'Pastor', 'Non_Member'];
// Assimilation lists show who has been absent: Assimilation managers only.
$smsCanAssim = assim_is_manager($pdo, (int) $_SESSION['user_id']);
$smsDepts = $smsRegions = [];
if ($smsCanAssim) {
    $smsDepts = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $smsRegions = $pdo->query('SELECT id, name FROM regions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}
$smsLabel = static fn (string $s): string => str_replace('_', ' ', $s);
$smsChoice = static function (string $name, string $value, string $label, string $hint, bool $checked = false): string {
    return '<label class="ui-check rounded-ui-lg border border-line px-3 py-2.5">'
        . '<input type="radio" name="' . ui_e($name) . '" value="' . ui_e($value) . '"' . ($checked ? ' checked' : '') . ' data-action-change="sms.audience">'
        . '<span>' . ui_e($label) . '<span class="ui-check__hint">' . ui_e($hint) . '</span></span></label>';
};

echo ui_json_script('sms-config', [
    'csrf' => (string) $_SESSION['sms_csrf'],
    'superAdmin' => sms_user_is_super_admin(),
    'canAssim' => $smsCanAssim,
]);
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'SMS Studio',
        'description' => 'Compose, send and track every SMS.',
        'info' => 'Every time shown is Lagos time (WAT). Texts go through BulkSMS Nigeria; delivery reports arrive by webhook or by polling.',
        'back' => ['href' => '/modules/events/index.php', 'label' => 'Events'],
    ]) ?>

    <div class="mb-4 flex flex-wrap items-center gap-2" data-sms-health aria-live="polite">
        <span class="ui-badge">Checking SMS…</span>
    </div>

    <?= ui_tabs([
        'compose' => ['label' => 'Compose', 'icon' => 'pencil'],
        'campaigns' => ['label' => 'Campaigns', 'icon' => 'megaphone'],
        'history' => ['label' => 'History', 'icon' => 'history'],
        'blocked' => ['label' => 'Blocked', 'icon' => 'shield-alert'],
        'settings' => ['label' => 'Settings', 'icon' => 'settings'],
    ], 'compose', 'SMS Studio sections') ?>

    <!-- Compose ------------------------------------------------------------ -->
    <section id="panel-compose" data-tab-panel="compose" class="ui-tab-panel">
        <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="ui-stack">
                <section class="ui-card" aria-labelledby="sms-audience-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title" id="sms-audience-title">Audience</h2>
                        <span class="ui-badge ui-badge--primary" data-sms-selected-count>0 selected</span>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <div class="grid gap-2 xl:grid-cols-2" role="radiogroup" aria-label="Who to text">
                            <?= $smsChoice('audience', 'users', 'Congregation', 'Members and visitors.', true) ?>
                            <?= $smsChoice('audience', 'registrations', 'Registrations', 'Event registrants, one text per number.') ?>
                            <?= $smsChoice('audience', 'checkins', 'Check-ins', 'Event attendees, one text per number.') ?>
                            <?= $smsChoice('audience', 'everyone', 'Everyone', 'Every source, one text per number.') ?>
<?php if ($smsCanAssim): ?>
                            <?= $smsChoice('audience', 'assimilation', 'Assimilation list', 'People who have drifted away.') ?>
<?php endif; ?>
                        </div>

                        <div data-sms-filter="users">
                            <div class="ui-label-row mb-2"><span class="ui-label">Statuses</span><?= ui_info('None ticked means every status. Relocated people and numbers marked invalid are always left out.', 'About statuses') ?></div>
                            <div class="flex flex-wrap gap-x-4 gap-y-2">
<?php foreach ($smsStatuses as $s): ?>
                                <label class="ui-check"><input type="checkbox" name="statuses[]" value="<?= ui_e($s) ?>" data-action-change="sms.audience"><span><?= ui_e($smsLabel($s)) ?></span></label>
<?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ui-stack ui-stack--sm" data-sms-filter="registrations" hidden>
                            <div class="flex flex-wrap gap-x-5 gap-y-2" role="radiogroup" aria-label="Which registrations">
                                <label class="ui-check"><input type="radio" name="regscope" value="all" checked data-action-change="sms.audience"><span>All registrations</span></label>
                                <label class="ui-check"><input type="radio" name="regscope" value="event" data-action-change="sms.audience"><span>One event</span></label>
                            </div>
                            <?= ui_field('select', ['name' => 'reg_event', 'id' => 'sms-reg-event', 'label' => 'Event', 'options' => ['' => 'Choose an event'],
                                'class' => 'hidden', 'attrs' => ['data-sms-events' => 'reg', 'data-action-change' => 'sms.audience']]) ?>
                        </div>

                        <div data-sms-filter="checkins" hidden>
                            <?= ui_field('select', ['name' => 'checkin_event', 'id' => 'sms-checkin-event', 'label' => 'Event', 'options' => ['' => 'Every event'],
                                'attrs' => ['data-sms-events' => 'checkin', 'data-action-change' => 'sms.audience']]) ?>
                        </div>

<?php if ($smsCanAssim): ?>
                        <div data-sms-filter="assimilation" hidden>
                            <div class="flex flex-col gap-3 rounded-ui-lg border border-line bg-success-soft px-4 py-3 sm:flex-row sm:items-center">
                                <p class="min-w-0 flex-1 font-medium text-ink" data-sms-assim-summary>No list yet.</p>
                                <?= ui_button('Build list', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'sms.assim-open', 'data-sms-assim-build' => true]]) ?>
                            </div>
                        </div>
<?php endif; ?>

                        <div class="flex flex-wrap gap-2">
                            <div class="min-w-[12rem] flex-1">
                                <label class="sr-only" for="sms-search">Search name or phone</label>
                                <input class="ui-input" type="search" id="sms-search" placeholder="Search name or phone" autocomplete="off" spellcheck="false" data-action-input="sms.search">
                            </div>
                            <?= ui_button('Select shown', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.select-all']]) ?>
                            <?= ui_button('Clear', ['variant' => 'ghost', 'attrs' => ['data-action' => 'sms.clear']]) ?>
                        </div>
                        <p class="ui-meta min-h-[1.25rem]" data-sms-audience-info aria-live="polite"></p>
                        <ul class="ui-list h-72 overflow-y-auto rounded-ui-lg border border-line" data-sms-contacts aria-label="Contacts">
                            <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>
                        </ul>
                    </div>
                </section>

                <section class="ui-card" aria-labelledby="sms-message-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <div class="ui-title-row"><h2 class="ui-card__title" id="sms-message-title">Message</h2>
                            <?= ui_info('Fields like {{first_name}} are filled in for each person when it is sent. Curly quotes and long dashes are made plain so each text stays cheap.', 'About the message') ?></div>
                    </div>
                    <div class="ui-card__body ui-form">
                        <?= ui_field('text', ['name' => 'title', 'id' => 'sms-title', 'label' => 'Campaign title', 'optional' => true, 'autocomplete' => 'off', 'placeholder' => 'e.g. Sunday reminder', 'attrs' => ['maxlength' => 200]]) ?>

                        <div class="rounded-ui-lg border border-line bg-surface-sunken p-3">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="min-w-[10rem] flex-1">
                                    <label class="ui-label" for="sms-template">Saved templates</label>
                                    <select class="ui-select" id="sms-template" data-sms-templates><option value="">Choose a template</option></select>
                                </div>
                                <?= ui_button('Load', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.tpl-load']]) ?>
                                <?= ui_button('Delete', ['variant' => 'danger-ghost', 'attrs' => ['data-action' => 'sms.tpl-delete']]) ?>
                            </div>
                            <div class="mt-2 flex flex-wrap items-end gap-2">
                                <div class="min-w-[10rem] flex-1">
                                    <label class="ui-label" for="sms-template-name">New template name</label>
                                    <input class="ui-input" id="sms-template-name" maxlength="100" autocomplete="off">
                                </div>
                                <?= ui_button('Save template', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.tpl-save']]) ?>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Insert a field">
<?php foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'full_name' => 'Full name', 'guest_name' => 'Guest name', 'phone' => 'Phone', 'email' => 'Email', 'event_title' => 'Event title', 'blessing_ref' => 'Their verse'] as $tok => $lbl): ?>
                            <?= ui_button($lbl, ['variant' => 'soft', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'sms.token', 'data-token' => '{{' . $tok . '}}']]) ?>
<?php endforeach; ?>
                        </div>

                        <div class="ui-field">
                            <label class="ui-label" for="sms-body">Text<span class="ui-label__req" aria-hidden="true">*</span></label>
                            <textarea class="ui-textarea" id="sms-body" rows="5" placeholder="Dear {{first_name}}, …" data-action-input="sms.body"></textarea>
                            <div class="mt-1 flex flex-wrap justify-between gap-2">
                                <span class="ui-meta" data-sms-chars></span>
                                <span class="ui-meta" data-sms-units></span>
                            </div>
                        </div>
                        <div class="ui-stack ui-stack--sm" data-sms-warnings aria-live="polite"></div>

                        <div class="rounded-ui-lg border border-line bg-surface-sunken p-3">
                            <p class="ui-label">What <span data-sms-preview-who>a sample contact</span> gets</p>
                            <div class="mt-1 max-h-40 overflow-y-auto whitespace-pre-wrap rounded-ui border border-line bg-surface p-3 text-sm text-ink" data-sms-preview>Start typing to see it.</div>
                        </div>

                        <?= ui_field('tel', ['name' => 'test_phone', 'id' => 'sms-test-phone', 'label' => 'Test number', 'optional' => true, 'autocomplete' => 'off', 'inputmode' => 'tel',
                            'placeholder' => '0803 123 4567', 'info' => 'Empty: your own number on file. Any Nigerian format works.']) ?>
                        <p class="ui-meta -mt-2">Yours on file: <span data-sms-my-phone>—</span></p>

                        <div class="ui-form-actions">
                            <?= ui_button('Send test', ['variant' => 'secondary', 'icon' => 'send', 'attrs' => ['data-action' => 'sms.test']]) ?>
                            <?= ui_button('Send campaign', ['variant' => 'primary', 'icon' => 'megaphone', 'attrs' => ['data-action' => 'sms.send']]) ?>
                        </div>
                    </div>
                </section>
            </div>

            <section class="ui-card lg:sticky lg:top-20" aria-labelledby="sms-selected-title">
                <div class="ui-card__header ui-card__header--divided">
                    <h2 class="ui-card__title" id="sms-selected-title">Recipients</h2>
                    <span class="ui-meta" data-sms-selected-summary>0 recipients</span>
                </div>
                <div class="ui-card__body">
                    <ul class="ui-stack ui-stack--sm max-h-[60vh] overflow-y-auto" data-sms-selected aria-label="Selected recipients">
                        <li class="ui-meta text-center">Nobody selected yet.</li>
                    </ul>
                </div>
            </section>
        </div>
    </section>

    <!-- Campaigns ---------------------------------------------------------- -->
    <section id="panel-campaigns" data-tab-panel="campaigns" class="ui-tab-panel" hidden>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div class="ui-title-row"><h2 class="ui-h2">Campaigns</h2><?= ui_info('Every bulk send with its live progress. Open one to follow it or stop what has not gone out yet.', 'About campaigns') ?></div>
            <?= ui_button('Refresh', ['variant' => 'ghost', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'sms.campaigns-reload']]) ?>
        </div>
        <div class="ui-stack" data-sms-campaigns></div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <span class="ui-meta" data-sms-campaigns-info></span>
            <div class="flex gap-2" data-sms-campaigns-pages></div>
        </div>
    </section>

    <!-- History ------------------------------------------------------------ -->
    <section id="panel-history" data-tab-panel="history" class="ui-tab-panel" hidden>
        <div class="ui-card mb-4">
            <div class="ui-card__body ui-stack ui-stack--sm">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[14rem] flex-1">
                        <label class="ui-label" for="sms-history-search">Search</label>
                        <input class="ui-input" type="search" id="sms-history-search" placeholder="Phone, name, text or ID" autocomplete="off" spellcheck="false" data-action-input="sms.history-search">
                    </div>
                    <div><label class="ui-label" for="sms-history-from">From</label><input class="ui-input" type="date" id="sms-history-from" data-action-change="sms.history-dates"></div>
                    <div><label class="ui-label" for="sms-history-to">To</label><input class="ui-input" type="date" id="sms-history-to" data-action-change="sms.history-dates"></div>
                </div>
                <div class="flex flex-wrap gap-2" data-sms-history-chips role="group" aria-label="Status"></div>
                <div class="flex flex-wrap gap-2" data-sms-history-filters></div>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Recipient</th><th scope="col">Phone</th><th scope="col">Status</th><th scope="col">Sent</th><th scope="col">Delivered</th><th scope="col">Cost</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-sms-history><tr><td colspan="7"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr></tbody>
                </table>
            </div>
            <div class="ui-card__footer flex flex-wrap items-center justify-between gap-2">
                <span class="ui-meta" data-sms-history-info></span>
                <span class="flex items-center gap-2"><?= ui_info('Cost is charged when a text is handed to BulkSMS. Undelivered texts on the direct-refund route are refunded to the BulkSMS wallet, not shown here: the wallet balance is the true figure. Blocked and refused texts never reached BulkSMS and cost nothing.', 'About cost') ?>
                    <span class="flex gap-2" data-sms-history-pages></span></span>
            </div>
        </div>
    </section>

    <!-- Blocked (suppression) ---------------------------------------------- -->
    <section id="panel-blocked" data-tab-panel="blocked" class="ui-tab-panel" hidden>
        <section class="ui-card mx-auto max-w-3xl" aria-labelledby="sms-blocked-title">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h2 class="ui-card__title" id="sms-blocked-title">Blocked numbers</h2>
                    <?= ui_info('A number is blocked by itself after 3 carrier failures in a row, so nobody pays for it. A refusal by BulkSMS (an empty wallet, say) never blocks anyone. Release a number to text it again.', 'About blocked numbers') ?></div>
                <?= ui_button('Refresh', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'sms.blocked-reload']]) ?>
            </div>
            <div class="ui-card__body ui-stack">
                <form class="flex flex-wrap items-end gap-2" data-action="sms.block" novalidate>
                    <div class="min-w-[10rem] flex-1"><label class="ui-label" for="sms-block-phone">Phone</label><input class="ui-input" type="tel" id="sms-block-phone" name="phone" autocomplete="off" inputmode="tel" required></div>
                    <div class="min-w-[10rem] flex-1"><label class="ui-label" for="sms-block-reason">Reason</label><input class="ui-input" id="sms-block-reason" name="reason" maxlength="200" autocomplete="off" placeholder="e.g. asked to stop"></div>
                    <?= ui_button('Block', ['variant' => 'danger', 'type' => 'submit']) ?>
                </form>
                <ul class="ui-list rounded-ui-lg border border-line" data-sms-blocked aria-label="Blocked numbers">
                    <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>
                </ul>
            </div>
        </section>
    </section>

    <!-- Settings ----------------------------------------------------------- -->
    <section id="panel-settings" data-tab-panel="settings" class="ui-tab-panel" hidden>
        <div class="mx-auto max-w-2xl ui-stack">
            <section class="ui-card" aria-labelledby="sms-conn-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h2 class="ui-card__title" id="sms-conn-title">BulkSMS connection</h2>
                        <?= ui_info('Secrets are encrypted at rest. Your ERP password unlocks them for 10 minutes.', 'About the connection') ?></div>
                </div>
                <div class="ui-card__body">
                    <div data-sms-settings-locked class="ui-stack">
                        <dl class="ui-dl">
                            <dt>Base URL</dt><dd data-sms-mask="base_url">—</dd>
                            <dt>API token</dt><dd data-sms-mask="api_token">—</dd>
                            <dt>Sender ID</dt><dd data-sms-mask="sender_id">—</dd>
                            <dt>Gateway</dt><dd data-sms-mask="gateway">—</dd>
                            <dt>Delivery webhook</dt><dd data-sms-mask="webhook_url">—</dd>
                            <dt>Sender name in text</dt><dd data-sms-mask="sign_mode">—</dd>
                            <dt>Price per unit</dt><dd data-sms-mask="unit_price">—</dd>
                        </dl>
                        <form class="flex flex-wrap items-end gap-2" data-action="sms.unlock" novalidate>
                            <div class="min-w-[12rem] flex-1"><label class="ui-label" for="sms-unlock-pw">Your ERP password</label><input class="ui-input" type="password" id="sms-unlock-pw" name="password" autocomplete="current-password" required></div>
                            <?= ui_button('Unlock', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'lock-keyhole']) ?>
                        </form>
                    </div>

                    <form class="ui-form" data-sms-settings-unlocked data-action="sms.settings-save" autocomplete="off" novalidate hidden>
                        <?= ui_field('url', ['name' => 'base_url', 'id' => 'sms-base', 'label' => 'Base URL', 'attrs' => ['spellcheck' => 'false'],
                            'info' => 'Must be https. The sandbox (https://www.bulksmsnigeria.com/api/sandbox/v2) tests without sending real texts.']) ?>
                        <?= ui_field('text', ['name' => 'api_token', 'id' => 'sms-token', 'label' => 'API token', 'required' => true, 'attrs' => ['spellcheck' => 'false']]) ?>
                        <div class="ui-field">
                            <div class="ui-label-row"><label class="ui-label" for="sms-sender">Sender ID<span class="ui-label__req" aria-hidden="true">*</span></label>
                                <?= ui_info('3 to 11 characters, registered and approved at bulksmsnigeria.com. An unapproved sender ID is the most common reason every text fails.', 'About the sender ID') ?></div>
                            <div class="flex gap-2"><input class="ui-input flex-1" id="sms-sender" name="sender_id" maxlength="11" required spellcheck="false">
                                <?= ui_button('Fetch mine', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.senders']]) ?></div>
                            <div class="mt-2 flex flex-wrap gap-2" data-sms-senders hidden></div>
                        </div>
                        <?= ui_field('select', ['name' => 'gateway', 'id' => 'sms-gateway', 'label' => 'Gateway', 'options' => [
                            '' => 'Default (direct-refund)', 'direct-refund' => 'direct-refund', 'direct-corporate' => 'direct-corporate (reaches DND)', 'otp' => 'otp', 'dual-backup' => 'dual-backup',
                        ], 'info' => 'direct-refund refunds undelivered texts. direct-corporate reaches numbers on Do Not Disturb and costs more.']) ?>
                        <div class="ui-form-grid">
                            <?= ui_field('select', ['name' => 'sign_mode', 'id' => 'sms-sign', 'label' => 'Sender name in text', 'options' => [
                                '' => 'At the start (default)', 'prefix' => 'At the start', 'none' => 'Not written',
                            ], 'info' => 'Every text begins with the sender ID, e.g. "HODLEKKI: Dear Ada…", so people see who wrote even where a route hides it. It counts towards length and cost. On the BulkSMS dashboard set "Append Sender ID" to Don\'t Append, or the name appears twice.']) ?>
                            <?= ui_field('text', ['name' => 'unit_price', 'id' => 'sms-price', 'label' => 'Price per unit (₦)', 'inputmode' => 'decimal', 'placeholder' => '6.49',
                                'info' => 'Used to check the wallet before a campaign is queued. Take it from BulkSMS pricing for the gateway above.']) ?>
                        </div>
                        <div class="ui-field">
                            <div class="ui-label-row"><label class="ui-label" for="sms-webhook">Delivery webhook <span class="ui-label__opt">(optional)</span></label>
                                <?= ui_info('BulkSMS calls this with delivery reports, so History updates within seconds. Without it the background sender checks each text itself (slower). Paste the same URL in the BulkSMS dashboard.', 'About the webhook') ?></div>
                            <div class="flex gap-2"><input class="ui-input flex-1" type="url" id="sms-webhook" name="webhook_url" spellcheck="false">
                                <?= ui_button('Use this site', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.webhook']]) ?></div>
                        </div>
                        <div class="ui-form-actions sm:justify-start">
                            <?= ui_button('Save', ['variant' => 'primary', 'type' => 'submit']) ?>
                            <?= ui_button('Test connection', ['variant' => 'secondary', 'attrs' => ['data-action' => 'sms.test-connection']]) ?>
                            <?= ui_button('Lock', ['variant' => 'ghost', 'icon' => 'lock-keyhole', 'attrs' => ['data-action' => 'sms.lock']]) ?>
                        </div>
                    </form>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="sms-check-title">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="sms-check-title">Delivery checklist</h2></div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <ul class="ui-stack ui-stack--sm text-sm" data-sms-checklist></ul>
                    <div class="ui-label-row"><span class="ui-label">Background sender cron</span>
                        <?= ui_info('cPanel → Cron Jobs, every minute. Keep its output in a log file: a worker that fails every minute is invisible with >/dev/null. To check it: tail -n 30 /home/smartqaq/sms_worker.log and tail -n 50 /home/smartqaq/public_html/hodlekki/includes/error_log.', 'About the cron job') ?></div>
                    <pre class="overflow-x-auto rounded-ui-lg bg-ink p-3 text-xs text-surface">* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/sms_queue_worker.php >> /home/smartqaq/sms_worker.log 2>&1</pre>
                </div>
            </section>
        </div>
    </section>
</div>

<?= ui_dialog_start('sms-confirm', ['title' => 'Send this campaign?', 'size' => 'md']) ?>
    <div class="ui-stack ui-stack--sm" data-sms-confirm-body></div>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send it', ['variant' => 'primary', 'attrs' => ['data-action' => 'sms.confirm', 'data-sms-confirm-btn' => true]])
) ?>

<?= ui_dialog_start('sms-drawer', ['title' => 'Details', 'size' => 'drawer']) ?>
    <div class="mb-3" data-sms-drawer-back hidden><?= ui_button('Back', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left', 'attrs' => ['data-action' => 'sms.drawer-back']]) ?></div>
    <div class="ui-stack" data-sms-drawer-body></div>
<?= ui_dialog_end() ?>

<?php if ($smsCanAssim): ?>
<?= ui_dialog_start('sms-assim', ['title' => 'Build a list', 'size' => 'md']) ?>
    <p class="ui-meta mb-1"><span data-sms-aw-step>1/4</span> · <span data-sms-aw-title>How long away?</span></p>
    <div data-sms-aw="0" class="ui-stack ui-stack--sm" role="radiogroup" aria-label="How long away">
<?php foreach (['nos' => ['Missed a few Sundays', 'Under 1 service in a month'], 'slip' => ['Slipping away', 'Under 3 services in 2 months'], 'gone' => ['Gone a while', 'Under 5 services in 3 months'], 'custom' => ['Set my own', 'e.g. under 4 services in 4 months']] as $k => [$t, $h]): ?>
        <button type="button" class="ui-btn ui-btn--secondary ui-btn--block h-auto flex-col items-start py-2.5 text-left" role="radio" aria-checked="false" data-action="sms.aw-away" data-away="<?= ui_e($k) ?>"><span class="font-semibold"><?= ui_e($t) ?></span><span class="ui-meta"><?= ui_e($h) ?></span></button>
<?php endforeach; ?>
        <div class="flex flex-wrap items-center justify-center gap-2 rounded-ui-lg border border-line bg-surface-sunken p-3 text-sm" data-sms-aw-custom hidden>
            <span>Under</span>
            <?= ui_button('Fewer', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'minus', 'icon_only' => true, 'attrs' => ['data-action' => 'sms.aw-step', 'data-field' => 'max', 'data-delta' => '-1']]) ?>
            <span class="w-8 text-center text-lg font-semibold" data-sms-aw-max>4</span>
            <?= ui_button('More', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'plus', 'icon_only' => true, 'attrs' => ['data-action' => 'sms.aw-step', 'data-field' => 'max', 'data-delta' => '1']]) ?>
            <span>services in</span>
            <?= ui_button('Shorter', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'minus', 'icon_only' => true, 'attrs' => ['data-action' => 'sms.aw-step', 'data-field' => 'win', 'data-delta' => '-1']]) ?>
            <span class="w-8 text-center text-lg font-semibold" data-sms-aw-win>4</span>
            <?= ui_button('Longer', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'plus', 'icon_only' => true, 'attrs' => ['data-action' => 'sms.aw-step', 'data-field' => 'win', 'data-delta' => '1']]) ?>
            <label class="sr-only" for="sms-aw-unit">Window unit</label>
            <select class="ui-select w-auto" id="sms-aw-unit" data-action-change="sms.aw-unit"><option value="days">days</option><option value="weeks">weeks</option><option value="months" selected>months</option></select>
        </div>
    </div>
    <div data-sms-aw="1" class="ui-stack ui-stack--sm" hidden>
        <button type="button" class="ui-btn ui-btn--secondary ui-btn--block h-auto flex-col items-start py-2.5 text-left" aria-pressed="true" data-action="sms.aw-everyone"><span class="font-semibold">Everyone</span><span class="ui-meta">First timers to pastors</span></button>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
<?php foreach (ASSIM_SPIRITUAL_STATUSES as $s): ?>
            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" aria-pressed="false" data-action="sms.aw-status" data-status="<?= ui_e($s) ?>"><?= ui_e($smsLabel($s)) ?></button>
<?php endforeach; ?>
        </div>
    </div>
    <div data-sms-aw="2" class="ui-form" hidden>
        <p class="ui-meta">All optional.</p>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'aw_dept', 'id' => 'sms-aw-dept', 'label' => 'Department', 'options' => ['0' => 'Any department'] + array_column($smsDepts, 'name', 'id')]) ?>
            <?= ui_field('select', ['name' => 'aw_region', 'id' => 'sms-aw-region', 'label' => 'Region', 'options' => ['0' => 'Any region'] + array_column($smsRegions, 'name', 'id')]) ?>
            <?= ui_field('select', ['name' => 'aw_gender', 'id' => 'sms-aw-gender', 'label' => 'Gender', 'options' => ['' => 'Anyone', 'Male' => 'Male', 'Female' => 'Female']]) ?>
            <?= ui_field('select', ['name' => 'aw_age', 'id' => 'sms-aw-age', 'label' => 'Age', 'options' => ['' => 'Any age'] + array_combine(array_keys(ASSIM_AGE_BANDS), array_keys(ASSIM_AGE_BANDS))]) ?>
        </div>
        <label class="ui-check items-center"><input type="checkbox" class="ui-switch" id="sms-aw-ever" checked><span>Only people who came before</span></label>
    </div>
    <div data-sms-aw="3" class="ui-stack ui-stack--sm" hidden>
        <div class="rounded-ui-lg bg-success-soft p-5 text-center" aria-live="polite">
            <p class="text-4xl font-semibold text-ink" data-sms-aw-count>…</p>
            <p class="ui-meta mt-1">people we can text</p>
        </div>
        <p class="text-center font-medium text-ink" data-sms-aw-summary></p>
        <ul class="ui-meta ui-stack ui-stack--sm" data-sms-aw-breakdown></ul>
        <p class="ui-meta">Each text is noted on their Assimilation record. <?= ui_info('The person\'s last 2 texts are kept on their Assimilation record as a system note, so whoever calls them next knows what they were told.', 'About the note') ?></p>
    </div>
<?= ui_dialog_end(
    ui_button('Back', ['variant' => 'ghost', 'attrs' => ['data-action' => 'sms.aw-back', 'data-sms-aw-back' => true]])
    . ui_button('Continue', ['variant' => 'primary', 'attrs' => ['data-action' => 'sms.aw-next', 'data-sms-aw-next' => true]])
) ?>
<?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>
