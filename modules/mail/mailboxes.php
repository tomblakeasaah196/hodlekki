<?php
// /modules/mail/mailboxes.php
/**
 * Mailboxes: the mail admins' screen (docs/MAIL.md, "Mailboxes screen"). Create, bring in, issue, suspend,
 * reset, remove; who works from each shared mailbox; the quota; jobs waiting for the server; export requests;
 * health. Strict mode (docs/UI.md): behaviour in assets/ui/js/pages/mail-mailboxes.js, data from
 * api/mail_admin_api.php. Every decision: mail_can().
 */
$ui = [
    'title'   => 'Mailboxes',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/mail-mailboxes.js'],
    'offline' => false,
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\Admin\Addresses;
use HOD\Mail\Admin\Exports;
use HOD\Mail\Admin\Grants;
use HOD\Mail\Admin\Health;
use HOD\Mail\Admin\Jobs;
use HOD\Mail\Admin\Quota;
use HOD\Mail\Config;
use HOD\Mail\MailAccess;
use HOD\Mail\People;
use HOD\Mail\Store;

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!mail_ready()) {
    echo '<div class="ui-page">' . ui_empty(['icon' => 'mail', 'title' => 'Mail is being set up', 'text' => 'The mail tables arrive with the next update. Come back in a few minutes.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$isAdmin = mail_can('admin');
$approver = mail_can('export_admin') || mail_can('export_pastoral');
if (!$isAdmin && !$approver) {
    echo ui_no_access('Mailboxes are managed by the mail admins. If you need a mailbox, ask one of them.');
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$domain = Addresses::domain();
$quota = Quota::snapshot();
$accounts = $isAdmin ? Store::accounts("1 = 1 ORDER BY FIELD(kind, 'church', 'department', 'personal'), email") : [];
$openJobs = [];
foreach ($isAdmin ? Jobs::open() : [] as $j) {
    $openJobs[$j['account_id'] ?? 0][] = $j;
}
$exports = Exports::visibleTo($uid);
$inErp = array_map(fn ($a) => $a['email'], $accounts);
$outside = (array) Config::get('outside', []);
$notInErp = array_values(array_filter($quota['addresses'], fn ($e) => !in_array($e, $inErp, true)));
$departments = $isAdmin ? Grants::options()['departments'] : [];

$statusWord = ['pending' => ['Being created', 'info'], 'active' => ['Active', 'success'], 'needs_reset' => ['Needs a new password', 'warning'],
    'suspended' => ['Suspended', 'neutral'], 'leaving' => ['Holder left', 'warning'], 'failed' => ['Not on the server', 'danger']];
$kindWord = ['church' => 'Church', 'department' => 'Department', 'personal' => 'Personal'];
$tab = $isAdmin ? 'boxes' : 'exports';
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'Mailboxes',
        'description' => "The church's mailboxes on {$domain}: who works from them, the quota, and what is waiting for the server.",
        'back' => ['href' => '/modules/mail/index.php', 'label' => 'Mail'],
        'actions' => $isAdmin ? ui_button('New mailbox', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'mb-new']]) : '',
    ]) ?>

    <?php if ($isAdmin): ?>
    <div class="ui-grid ui-grid--stats">
        <div class="ui-card"><?= ui_stat(['label' => "Mailboxes on {$domain}", 'value' => $quota['domain_used'] . ' of ' . $quota['domain_quota']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Left', 'value' => (string) $quota['domain_left']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Kept for personal mailboxes', 'value' => (string) $quota['reserved_personal']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Hosting account (all sites)', 'value' => $quota['account_max'] ? $quota['account_used'] . ' of ' . $quota['account_max'] : 'Not read yet']) ?></div>
    </div>
    <?php endif; ?>

    <?= ui_tabs(array_filter([
        'boxes' => $isAdmin ? 'Mailboxes' : null,
        'exports' => 'Export requests' . ($exports ? ' (' . count($exports) . ')' : ''),
        'jobs' => $isAdmin ? 'Activity' : null,
        'health' => $isAdmin ? 'Health' : null,
    ]), $tab) ?>

    <?php if ($isAdmin): ?>
    <section data-tab-panel="boxes" class="ui-tab-panel ui-stack">
        <?php if ($quota['read_at'] === null): ?>
            <?= ui_banner('info', ['title' => 'Counting the mailboxes on the server', 'text' => 'The minute cron reads them from cPanel. Refresh this page in a minute.']) ?>
        <?php endif; ?>
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-h3">In the ERP</h2>
                <div class="ui-toolbar__end">
                    <?= ui_button('Issue a personal mailbox', ['variant' => 'secondary', 'icon' => 'user-round-plus', 'size' => 'sm', 'attrs' => ['data-dialog-open' => 'mb-personal']]) ?>
                </div>
            </div>
            <?php if (!$accounts): ?>
                <div class="ui-card__body"><?= ui_empty(['icon' => 'inbox', 'title' => 'No mailbox in the ERP yet', 'text' => 'Bring info@ and embrace@ in from the list below, or create a new mailbox.']) ?></div>
            <?php else: ?>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th>Address</th><th>Kind</th><th>Status</th><th class="is-num">Storage</th><th>Who works from it</th><th class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $a):
                        [$word, $tone] = $statusWord[$a['status']] ?? [$a['status'], 'neutral'];
                        $waiting = $openJobs[$a['id']] ?? [];
                        $readers = in_array($a['status'], MailAccess::OPEN_STATES, true) ? MailAccess::readers($a) : [];
                        $used = $a['disk_used_mb'] !== null ? $a['disk_used_mb'] . ' / ' . $a['disk_quota_mb'] . ' MB' : '— / ' . $a['disk_quota_mb'] . ' MB';
                        ?>
                        <tr>
                            <td data-label="Address"><span class="ui-table__primary"><?= ui_e($a['email']) ?></span>
                                <span class="ui-table__secondary"><?= ui_e($a['display_name']) ?><?= $a['owner_user_id'] ? ' · ' . ui_e(People::name($a['owner_user_id'])) : '' ?></span></td>
                            <td data-label="Kind"><?= ui_e($kindWord[$a['kind']]) ?><?= $a['is_pastoral'] ? ' ' . ui_badge('Pastoral', 'info') : '' ?></td>
                            <td data-label="Status"><?= ui_badge($word, $tone, ['dot' => true]) ?>
                                <?php foreach ($waiting as $j): ?><span class="ui-table__secondary"><?= ui_e($j['label']) ?>: waiting for the server…</span><?php endforeach; ?>
                                <?php if ($a['last_error'] && $a['status'] !== 'active'): ?><span class="ui-table__secondary"><?= ui_e($a['last_error']) ?></span><?php endif; ?></td>
                            <td data-label="Storage" class="is-num"><?= ui_e($used) ?></td>
                            <td data-label="Who works from it"><?= $a['kind'] === 'personal' ? 'Its holder only' : ui_e(count($readers) . ' ' . (count($readers) === 1 ? 'person' : 'people')) ?></td>
                            <td class="is-actions">
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-menu-trigger aria-controls="mb-menu-<?= (int) $a['id'] ?>" aria-label="Actions for <?= ui_e($a['email']) ?>"><?= ui_icon('ellipsis') ?></button>
                                <div class="ui-menu" id="mb-menu-<?= (int) $a['id'] ?>" hidden>
                                    <?php if ($a['kind'] !== 'personal'): ?><button type="button" class="ui-menu__item" data-action="mb.grants" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Who works from it</button><?php endif; ?>
                                    <?php if (in_array($a['status'], ['active', 'needs_reset', 'suspended'], true)): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="reset" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Reset password</button><?php endif; ?>
                                    <?php if (in_array($a['status'], ['active', 'needs_reset'], true)): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="suspend" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Suspend</button><?php endif; ?>
                                    <?php if ($a['status'] === 'suspended'): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="resume" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Resume</button><?php endif; ?>
                                    <button type="button" class="ui-menu__item" data-action="mb.quota" data-id="<?= (int) $a['id'] ?>" data-mb="<?= (int) $a['disk_quota_mb'] ?>">Storage limit</button>
                                    <button type="button" class="ui-menu__item" data-action="mb.autoreply" data-id="<?= (int) $a['id'] ?>">Automatic reply</button>
                                    <?php if ($a['status'] === 'leaving'): ?><button type="button" class="ui-menu__item" data-action="mb.export" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Ask to export its mail</button><?php endif; ?>
                                    <div class="ui-menu__sep"></div>
                                    <button type="button" class="ui-menu__item ui-menu__item--danger" data-action="mb.remove" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Remove mailbox</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">On the server, not in the ERP</h2></div>
            <div class="ui-card__body">
                <?php if (!$notInErp): ?>
                    <p class="text-ink-muted">Nothing else on <?= ui_e($domain) ?>.</p>
                <?php else: ?>
                <ul class="ui-list">
                    <?php foreach ($notInErp as $email):
                        $local = substr($email, 0, -strlen('@' . $domain)); ?>
                        <li class="ui-list__item">
                            <div class="ui-list__main"><span class="ui-list__title"><?= ui_e($email) ?></span>
                                <span class="ui-list__meta"><?= isset($outside[$local]) ? ui_e($outside[$local]) : 'Made in cPanel. Bringing it in sets a new password: phones or webmail using the old one stop.' ?></span></div>
                            <div class="ui-list__end"><?php if (!isset($outside[$local])): ?><?= ui_button('Bring in', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.bringin', 'data-email' => $email]]) ?><?php endif; ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section data-tab-panel="exports" class="ui-tab-panel ui-stack" <?= $tab === 'exports' ? '' : 'hidden' ?>>
        <?= ui_banner('info', ['title' => 'A departed holder\'s mail is read by nobody', 'text' => 'Unless two different people approve a request: one with the admin approval and one with the pastoral approval. The person who asked then downloads it for 7 days. Every step is recorded.']) ?>
        <?php if (!$exports): ?>
            <div class="ui-card"><div class="ui-card__body"><?= ui_empty(['icon' => 'lock-keyhole', 'title' => 'No export requests', 'text' => 'Requests appear here when a mail admin asks to export a departed holder\'s mailbox.']) ?></div></div>
        <?php else: ?>
        <div class="ui-card"><ul class="ui-list">
            <?php foreach ($exports as $r): ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($r['email']) ?> · <?= ui_badge(ucfirst($r['status']), $r['status'] === 'ready' ? 'success' : ($r['status'] === 'pending' ? 'warning' : 'neutral')) ?></span>
                    <span class="ui-list__meta">Asked by <?= ui_e($r['requested_by_name']) ?> on <?= ui_e(substr((string) $r['created_at'], 0, 16)) ?>: “<?= ui_e($r['reason']) ?>”</span>
                    <span class="ui-list__meta">Admin approval: <?= ui_e($r['admin_approved_by_name'] ?? 'waiting') ?> · Pastoral approval: <?= ui_e($r['pastor_approved_by_name'] ?? 'waiting') ?></span>
                </div>
                <div class="ui-list__end">
                    <?php if ($r['can_approve_admin']): ?><?= ui_button('Approve (admin)', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.approve', 'data-request' => $r['id'], 'data-side' => 'admin']]) ?><?php endif; ?>
                    <?php if ($r['can_approve_pastoral']): ?><?= ui_button('Approve (pastoral)', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.approve', 'data-request' => $r['id'], 'data-side' => 'pastoral']]) ?><?php endif; ?>
                    <?php if ($r['status'] === 'pending' && ($r['can_approve_admin'] || $r['can_approve_pastoral'])): ?><?= ui_button('Refuse', ['variant' => 'danger-ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.refuse', 'data-request' => $r['id']]]) ?><?php endif; ?>
                    <?php if ($r['can_download']): ?><?= ui_button('Download', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['data-action' => 'mb.download', 'data-request' => $r['id']]]) ?><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </section>

    <?php if ($isAdmin): ?>
    <section data-tab-panel="jobs" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-card"><ul class="ui-list">
            <?php foreach (Jobs::recent(30) as $j): ?>
            <li class="ui-list__item"><div class="ui-list__main">
                <span class="ui-list__title"><?= ui_e($j['label']) ?><?= $j['email'] ? ' · ' . ui_e($j['email']) : '' ?></span>
                <span class="ui-list__meta"><?= ui_e(ucfirst($j['status'])) ?> · <?= ui_e(substr((string) $j['created_at'], 0, 16)) ?><?= $j['requested_by'] ? ' · ' . ui_e(People::name($j['requested_by'])) : ' · automatic' ?><?= $j['error'] ? ' · ' . ui_e($j['error']) : '' ?></span>
            </div></li>
            <?php endforeach; ?>
        </ul></div>
    </section>

    <section data-tab-panel="health" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-card"><ul class="ui-list">
            <?php foreach (Health::checks() as $c): ?>
            <li class="ui-list__item"><div class="ui-list__main"><span class="ui-list__title"><?= ui_badge($c['ok'] ? 'OK' : 'Check', $c['ok'] ? 'success' : 'warning', ['dot' => true]) ?> <?= ui_e($c['label']) ?></span>
                <span class="ui-list__meta"><?= ui_e($c['detail']) ?></span></div></li>
            <?php endforeach; ?>
        </ul></div>
        <form class="ui-card ui-form" data-action="mb.reserved" novalidate>
            <div class="ui-card__body ui-form-grid">
                <?= ui_field('number', ['name' => 'reserved_personal', 'label' => 'Slots kept for personal mailboxes', 'value' => $quota['reserved_personal'],
                    'hint' => 'A new department or church mailbox is refused while it would use one of these.', 'attrs' => ['min' => 0, 'max' => $quota['domain_quota']]]) ?>
            </div>
            <div class="ui-card__footer"><?= ui_button('Save', ['variant' => 'primary', 'type' => 'submit']) ?></div>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<?= ui_dialog_start('mb-new', ['title' => 'New mailbox', 'description' => "A department or church mailbox on {$domain}. Its password is made by the server and never shown."]) ?>
<form class="ui-form" data-action="mb.create" novalidate>
    <?= ui_field('select', ['name' => 'kind', 'label' => 'Kind', 'options' => ['church' => 'Church-wide (info@, embrace@ …)', 'department' => 'A department\'s (media@, finance@ …)'], 'required' => true]) ?>
    <?= ui_field('text', ['name' => 'local_part', 'label' => 'Address', 'hint' => "Before @{$domain}: lower-case letters, digits, dots or hyphens.", 'required' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <?= ui_field('text', ['name' => 'display_name', 'label' => 'Name in the From line', 'placeholder' => 'HOD Lekki Media Team', 'required' => true]) ?>
    <?= ui_field('select', ['name' => 'department_id', 'label' => 'Department', 'options' => ['' => 'Not a department mailbox'] + array_column($departments, 'label', 'id'), 'optional' => true]) ?>
    <label class="ui-check"><input type="checkbox" name="is_pastoral" value="1"> Pastoral mailbox <span class="ui-check__hint">AI is never used on it.</span></label>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Create mailbox', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-personal', ['title' => 'Issue a personal mailbox', 'description' => "firstname.lastname@{$domain}, for someone whose role or seat allows a personal mailbox."]) ?>
<form class="ui-form" data-action="mb.personal" novalidate>
    <?= ui_field('search', ['name' => 'person_q', 'label' => 'Person', 'placeholder' => 'Type two letters of their name', 'attrs' => ['data-action-input' => 'mb.people', 'autocomplete' => 'off']]) ?>
    <div class="ui-list" data-mb-people role="listbox" aria-label="People found"></div>
    <input type="hidden" name="user_id" value="">
    <?= ui_field('text', ['name' => 'local_part', 'label' => 'Address', 'hint' => 'Suggested from their name; add a middle initial if it is taken.', 'required' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Issue mailbox', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-bringin', ['title' => 'Bring a mailbox into the ERP', 'description' => 'The server sets a new password; anything signed in with the old one stops.']) ?>
<form class="ui-form" data-action="mb.bringin.save" novalidate>
    <input type="hidden" name="email" value="">
    <p class="ui-hint" data-mb-bringin-email></p>
    <?= ui_field('select', ['name' => 'kind', 'label' => 'Kind', 'options' => ['church' => 'Church-wide', 'department' => 'A department\'s', 'personal' => 'Someone\'s personal mailbox'], 'required' => true]) ?>
    <?= ui_field('text', ['name' => 'display_name', 'label' => 'Name in the From line', 'required' => true]) ?>
    <?= ui_field('select', ['name' => 'department_id', 'label' => 'Department (department mailboxes)', 'options' => ['' => '—'] + array_column($departments, 'label', 'id'), 'optional' => true]) ?>
    <?= ui_field('number', ['name' => 'user_id', 'label' => 'Holder\'s member number (personal mailboxes)', 'optional' => true, 'hint' => 'The person\'s user id, from their profile.']) ?>
    <label class="ui-check"><input type="checkbox" name="is_pastoral" value="1"> Pastoral mailbox <span class="ui-check__hint">AI is never used on it.</span></label>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Bring in', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-grants', ['title' => 'Who works from this mailbox', 'size' => 'lg', 'description' => 'Roles, department seats, whole departments or named people, each at a level. Changes apply on the next page load.']) ?>
<div class="ui-stack" data-mb-grants></div>
<div class="ui-form-actions"><?= ui_button('Add a line', ['variant' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-action' => 'mb.grants.add']]) ?><?= ui_button('Save', ['variant' => 'primary', 'attrs' => ['data-action' => 'mb.grants.save']]) ?></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-autoreply', ['title' => 'Automatic reply', 'description' => 'Sent once a day to each sender while it is on.']) ?>
<form class="ui-form" data-action="mb.autoreply.save" novalidate>
    <input type="hidden" name="id" value="">
    <?= ui_field('text', ['name' => 'subject', 'label' => 'Subject', 'required' => true]) ?>
    <?= ui_field('textarea', ['name' => 'body', 'label' => 'Message', 'required' => true]) ?>
    <?= ui_field('number', ['name' => 'days', 'label' => 'For how many days', 'value' => 30, 'attrs' => ['min' => 1, 'max' => 90]]) ?>
    <div class="ui-form-actions"><?= ui_button('Turn it off', ['variant' => 'ghost', 'attrs' => ['data-action' => 'mb.autoreply.off']]) ?><?= ui_button('Turn it on', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>
<?php endif; ?>

<?= ui_json_script('mail-mailboxes', ['domain' => $domain, 'api' => '/api/mail_admin_api.php', 'export' => '/api/mail_export.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
