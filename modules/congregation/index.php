<?php
// /modules/congregation/index.php
// Congregation: the church register (everyone, filterable and exportable),
// three health KPIs, a member's attendance history, and the member form.
// A row (or a person picked in Ctrl+K: #person=<id>) opens their details:
// Call, WhatsApp, SMS, Email, Edit, and who reached them lately
// (includes/congregation_contact.php). Email is Mail's own composer, mounted
// here only for people with a mailbox they may send from.
// Data comes from /api/congregation_api.php.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../includes/db.php';
require_once '../../includes/congregation_contact.php';
$congUid = (int) ($_SESSION['user_id'] ?? 0);
$congRights = $congUid > 0 ? cong_contact_rights($congUid) : ['email' => false, 'sms' => false, 'edit' => false];
$ui = [
    'title' => 'Congregation',
    'mode' => 'strict',
    'scripts' => array_merge($congRights['email'] ? ['/assets/ui/js/pages/mail-compose.js'] : [], ['/assets/ui/js/pages/congregation.js']),
    'styles' => $congRights['email'] ? ['/assets/css/mail.css'] : [],
    // The address search (Geoapify) runs in the browser.
    'csp' => ['connect-src' => ['https://api.geoapify.com']],
];
require_once '../../includes/header.php';
// Access: the 'congregation' row of the permission matrix (Roles & permissions).
require_can('congregation');

$congStatuses = ['Active' => 'Active', 'New' => 'New', 'Inconsistent' => 'Inconsistent', 'Unknown' => 'Unknown (AWOL)'];
$congGrowth = ['Visitor' => 'Visitor', '1st_Timer' => '1st timer', 'Member' => 'Member', 'Worker' => 'Worker', 'Pastor' => 'Pastor'];

/** A clickable KPI tile that opens the people behind it. */
function cong_kpi(string $key, string $label, string $hint, string $icon): string
{
    return '<button type="button" class="ui-card ui-stat" data-action="cong.kpi" data-kpi="' . ui_e($key) . '" data-title="' . ui_e($label) . '" aria-haspopup="dialog">'
        . '<span class="ui-stat__label">' . ui_icon($icon) . '<span>' . ui_e($label) . '</span></span>'
        . '<span class="ui-stat__value" data-cong-kpi="' . ui_e($key) . '"><span class="ui-skeleton inline-block h-7 w-12"></span></span>'
        . '<span class="ui-stat__meta">' . ui_e($hint) . '</span>'
        . '</button>';
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Congregation',
        'description' => 'The church register: people, growth and attendance health.',
        'actions' => ui_button('Add member', ['variant' => 'primary', 'icon' => 'user-round-plus', 'attrs' => ['data-action' => 'cong.add']]),
    ]) ?>

    <div class="ui-grid ui-grid--3 ui-grid--stats mb-6">
        <?= cong_kpi('active_non_members', 'Active non-members', 'Attending, not yet members', 'sprout') ?>
        <?= cong_kpi('consistent_workers', 'Consistent workers', 'The workforce', 'users-round') ?>
        <?= cong_kpi('consistent_members', 'Consistent members', 'The core base', 'heart-handshake') ?>
    </div>

    <section class="ui-card" aria-labelledby="cong-register-title">
        <div class="ui-card__header ui-card__header--divided">
            <div>
                <h2 class="ui-card__title" id="cong-register-title">Register</h2>
                <p class="ui-card__description" data-cong-count aria-live="polite">Loading…</p>
            </div>
            <?= ui_button('Export to Excel', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['data-action' => 'cong.export']]) ?>
        </div>
        <div class="ui-card__body border-b border-line">
            <div class="grid gap-3 md:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]">
                <div>
                    <label class="sr-only" for="cong-search">Search the register</label>
                    <input class="ui-input" type="search" id="cong-search" placeholder="Name, phone or who invited them" autocomplete="off" data-action-input="cong.filter">
                </div>
                <div>
                    <label class="sr-only" for="cong-tribe">Tribe</label>
                    <select class="ui-select" id="cong-tribe" data-action-change="cong.filter"><option value="">All tribes</option></select>
                </div>
                <div>
                    <label class="sr-only" for="cong-status">Attendance</label>
                    <select class="ui-select" id="cong-status" data-action-change="cong.filter">
                        <option value="">All attendance</option>
<?php foreach ($congStatuses as $v => $l): ?>
                        <option value="<?= ui_e($v) ?>"><?= ui_e($l) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="sr-only" for="cong-growth">Growth</label>
                    <select class="ui-select" id="cong-growth" data-action-change="cong.filter">
                        <option value="">All growth stages</option>
<?php foreach ($congGrowth as $v => $l): ?>
                        <option value="<?= ui_e($v) ?>"><?= ui_e($l) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="sr-only" for="cong-dept">Department</label>
                    <select class="ui-select" id="cong-dept" data-action-change="cong.filter"><option value="">All departments</option></select>
                </div>
            </div>
        </div>
        <div class="ui-table-wrap border-0">
            <table class="ui-table ui-table--stack">
                <thead>
                    <tr><th scope="col">Person</th><th scope="col">Contact</th><th scope="col">Status</th><th scope="col">Groups</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody data-cong-rows>
<?php for ($i = 0; $i < 6; $i++): ?>
                    <tr><td colspan="5"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
<?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="ui-card__footer justify-center" data-cong-more hidden>
            <?= ui_button('Show more', ['variant' => 'secondary', 'attrs' => ['data-action' => 'cong.more']]) ?>
        </div>
    </section>
</div>

<?= ui_dialog_start('cong-kpi', ['title' => 'People', 'description' => ' ', 'size' => 'drawer']) ?>
    <ul class="ui-list -mx-5" data-cong-kpi-list></ul>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('cong-attendance', ['title' => 'Attendance', 'description' => 'Attendance health', 'size' => 'drawer']) ?>
    <label class="ui-label" for="cong-att-period">Period</label>
    <select class="ui-select" id="cong-att-period" data-action-change="cong.att-period">
        <option value="3_months">Past 3 months</option>
        <option value="6_months">Past 6 months</option>
        <option value="1_year" selected>Past year</option>
        <option value="all_time">All time</option>
    </select>
    <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
        <div class="ui-card ui-card--sunken px-2 py-3"><dt class="ui-meta">Events</dt><dd class="ui-num mt-1 text-xl font-semibold text-ink" data-cong-att="total">—</dd></div>
        <div class="ui-card ui-card--sunken px-2 py-3"><dt class="ui-meta">Attended</dt><dd class="ui-num mt-1 text-xl font-semibold text-success" data-cong-att="present">—</dd></div>
        <div class="ui-card ui-card--sunken px-2 py-3"><dt class="ui-meta">Missed</dt><dd class="ui-num mt-1 text-xl font-semibold text-danger" data-cong-att="missed">—</dd></div>
    </dl>
    <ul class="ui-list -mx-5 mt-4" data-cong-att-list aria-live="polite"></ul>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('cong-person', ['title' => 'Person', 'size' => 'lg',
    'actions' => $congRights['edit'] ? ui_button('Edit', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil', 'attrs' => ['data-action' => 'cong.edit', 'data-cong-person-edit' => true, 'aria-haspopup' => 'dialog']]) : '']) ?>
    <div class="cong-person" data-cong-person>
        <div class="cong-person__top">
            <span data-cong-person-avatar></span>
            <div class="cong-person__tags" data-cong-person-tags></div>
        </div>
        <div class="cong-reach" role="group" aria-label="Reach out" data-cong-reach></div>
        <dl class="ui-dl" data-cong-person-facts></dl>
        <section aria-labelledby="cong-log-title">
            <h3 class="cong-person__subtitle" id="cong-log-title">Recent contact <?= ui_info('Calls and WhatsApp are logged when tapped. Texts and emails show whether they went.', 'About recent contact') ?></h3>
            <ul class="ui-list -mx-5" data-cong-person-log aria-live="polite"></ul>
        </section>
    </div>
<?= ui_dialog_end(
    '<div class="ui-dialog__footer-start">' . ui_button('Push to Charis', ['variant' => 'danger-ghost', 'attrs' => ['data-action' => 'cong.charis', 'data-cong-person-charis' => true, 'hidden' => true]]) . '</div>'
    . ui_button('Attendance', ['variant' => 'secondary', 'icon' => 'calendar-check', 'attrs' => ['data-action' => 'cong.attendance', 'data-cong-person-att' => true, 'aria-haspopup' => 'dialog']])
) ?>

<?= ui_dialog_start('cong-sms', ['title' => 'Send SMS', 'description' => ' ', 'size' => 'sm']) ?>
    <form class="ui-form" id="cong-sms-form" data-action="cong.sms-send" data-ui-validate novalidate>
        <?= ui_field('textarea', ['name' => 'message', 'id' => 'cong-sms-text', 'label' => 'Message', 'required' => true,
            'info' => 'Sent from SMS Studio with the church sender name in front. It shows in SMS History.',
            'attrs' => ['rows' => '5', 'maxlength' => (string) CONG_CONTACT_SMS_MAX]]) ?>
        <p class="ui-meta" data-cong-sms-count aria-live="polite">0 characters · 1 SMS</p>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send SMS', ['variant' => 'primary', 'icon' => 'send', 'type' => 'submit', 'attrs' => ['form' => 'cong-sms-form']])
) ?>

<?= ui_dialog_start('cong-wa', ['title' => 'WhatsApp message', 'description' => ' ', 'size' => 'sm']) ?>
    <?= ui_field('textarea', ['name' => 'wa_message', 'id' => 'cong-wa-text', 'label' => 'Message', 'optional' => true,
        'info' => 'WhatsApp opens on their chat with this message ready. Tap Send there.',
        'attrs' => ['rows' => '5']]) ?>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Open WhatsApp', ['variant' => 'primary', 'icon' => 'message-circle', 'href' => 'https://wa.me/', 'attrs' => ['target' => '_blank', 'rel' => 'noopener', 'data-cong-wa-open' => true]])
) ?>

<?= ui_dialog_start('cong-member', ['title' => 'Add member', 'description' => 'Create or update a record in the register.', 'size' => 'lg']) ?>
    <form class="ui-form" id="cong-member-form" data-action="cong.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="create_member">
        <input type="hidden" name="user_id" value="">

        <div class="flex items-center gap-4">
            <span class="ui-avatar ui-avatar--xl" data-cong-photo><?= ui_icon('user') ?></span>
            <div class="min-w-0 flex-1">
                <?= ui_field('file', ['name' => 'profile_pic', 'id' => 'cong-photo', 'label' => 'Photo', 'optional' => true, 'hint' => 'JPG or PNG, up to 2 MB.', 'attrs' => ['accept' => 'image/jpeg, image/png', 'data-action-change' => 'cong.photo']]) ?>
            </div>
        </div>

        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Identity</legend>
            <div class="ui-form-grid">
                <?= ui_field('text', ['name' => 'first_name', 'id' => 'cong-first', 'label' => 'First name', 'required' => true, 'autocomplete' => 'off']) ?>
                <?= ui_field('text', ['name' => 'last_name', 'id' => 'cong-last', 'label' => 'Last name', 'required' => true, 'autocomplete' => 'off', 'attrs' => ['data-action-change' => 'cong.spouse-sort']]) ?>
                <?= ui_field('select', ['name' => 'gender', 'id' => 'cong-gender', 'label' => 'Gender', 'options' => ['' => 'Not recorded', 'Male' => 'Male', 'Female' => 'Female'], 'attrs' => ['data-action-change' => 'cong.spouse-sort']]) ?>
                <?= ui_field('tel', ['name' => 'phone', 'id' => 'cong-phone', 'label' => 'Phone number', 'required' => true, 'inputmode' => 'tel', 'autocomplete' => 'off']) ?>
                <div class="ui-field">
                    <span class="ui-label">Church email</span>
                    <p class="ui-input flex items-center bg-surface-sunken text-ink-muted" data-cong-church-email>Created when you save</p>
                    <input type="hidden" name="email" value="">
                </div>
                <?= ui_field('email', ['name' => 'real_email', 'id' => 'cong-real-email', 'label' => 'Personal email', 'optional' => true, 'autocomplete' => 'off']) ?>
                <?= ui_field('date', ['name' => 'dob', 'id' => 'cong-dob', 'label' => 'Date of birth', 'optional' => true]) ?>
                <div class="ui-field ui-span-full">
                    <label class="ui-label" for="cong-address-search">Home address</label>
                    <div class="ui-geo" data-cong-address></div>
                    <p class="ui-hint">Start typing and choose the address from the list.</p>
                    <input type="hidden" name="physical_address" value="">
                    <input type="hidden" name="latitude" value="">
                    <input type="hidden" name="longitude" value="">
                </div>
            </div>
        </fieldset>

        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Church and attendance</legend>
            <div class="ui-form-grid">
                <?= ui_field('select', ['name' => 'spiritual_status', 'id' => 'cong-spiritual', 'label' => 'Growth stage', 'required' => true, 'options' => [
                    'Visitor' => 'Visitor', '1st_Timer' => '1st timer', '2nd_Timer' => '2nd timer', '3rd_Timer' => '3rd timer',
                    'Member' => 'Full member', 'Worker' => 'Worker', 'Pastor' => 'Pastor', 'Non_Member' => 'Non-member',
                ]]) ?>
                <?= ui_field('select', ['name' => 'attendance_status', 'id' => 'cong-attendance-status', 'label' => 'Attendance', 'required' => true, 'options' => [
                    'New' => 'New', 'Active' => 'Active', 'Inconsistent' => 'Inconsistent', 'Unknown' => 'Unknown (AWOL)',
                    'Relocated' => 'Relocated', 'Attends_Another_Church' => 'Attends another church',
                ], 'attrs' => ['data-action-change' => 'cong.conditional']]) ?>
                <div class="ui-span-full" data-cong-new-church hidden>
                    <?= ui_field('text', ['name' => 'new_church_name', 'id' => 'cong-new-church', 'label' => 'Church they attend now', 'optional' => true]) ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Welfare</legend>
            <div class="ui-form-grid">
                <?= ui_field('select', ['name' => 'marital_status', 'id' => 'cong-marital', 'label' => 'Marital status', 'options' => ['Single' => 'Single', 'Married' => 'Married', 'Separated' => 'Separated', 'Divorced' => 'Divorced'], 'attrs' => ['data-action-change' => 'cong.conditional']]) ?>
                <div data-cong-spouse hidden>
                    <?= ui_field('select', ['name' => 'spouse_id', 'id' => 'cong-spouse', 'label' => 'Spouse in church', 'optional' => true,
                        'options' => ['' => 'Not in church'],
                        'attrs' => ['data-ui-combobox' => true, 'data-action-change' => 'cong.spouse'],
                        'info' => 'Pick their spouse if they are in the register. Both records are linked: the spouse is marked Married and gets the same anniversary, and Charis shows one line for the couple.']) ?>
                </div>
                <div data-cong-anniversary hidden>
                    <?= ui_field('date', ['name' => 'wedding_anniversary', 'id' => 'cong-anniversary', 'label' => 'Wedding anniversary', 'optional' => true]) ?>
                </div>
                <?= ui_field('textarea', ['name' => 'comments', 'id' => 'cong-comments', 'label' => 'Pastoral notes', 'optional' => true, 'class' => 'ui-span-full', 'placeholder' => 'Welfare notes, family details…', 'attrs' => ['rows' => '3']]) ?>
            </div>
        </fieldset>
    </form>
<?= ui_dialog_end(
    '<div class="ui-dialog__footer-start">' . ui_button('Delete member', ['variant' => 'danger-ghost', 'icon' => 'trash-2', 'attrs' => ['data-action' => 'cong.delete', 'data-cong-delete' => true, 'hidden' => true]]) . '</div>'
    . ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'cong-member-form']])
) ?>

<?= ui_json_script('cong-config', ['geoapifyKey' => $_ENV['GEOAPIFY_API_KEY'] ?? '7a189b607e9e4c4cbedf6ada291b6bc1', 'rights' => $congRights]) ?>
<?php if ($congRights['email']): ?>
<?php $congMailbox = \HOD\Mail\MailAccess::accounts($congUid, 'send')[0]['id'] ?? null; ?>
<div data-mount-wrap="composer"><?= \HOD\Mail\Read\Partial::mount('composer', ['user_id' => $congUid, 'account_id' => $congMailbox, 'folder' => null, 'thread_id' => null, 'shared' => false]) ?></div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
