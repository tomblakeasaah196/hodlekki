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
use HOD\Mail\Admin\Uapi;
use HOD\Mail\Config;
use HOD\Mail\MailAccess;
use HOD\Mail\People;
use HOD\Mail\Store;

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!mail_ready()) {
    echo '<div class="ui-page">' . ui_empty(['icon' => 'mail', 'title' => 'Mail not ready', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$isAdmin = mail_can('admin');
$approver = mail_can('export_admin') || mail_can('export_pastoral');
if (!$isAdmin && !$approver) {
    echo ui_no_access('Ask a mail admin if you need a mailbox.');
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$domain = Addresses::domain();
$quota = Quota::snapshot();
$uapiStatus = Uapi::status();
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
        'description' => "Mailboxes on {$domain}.",
        'back' => ['href' => '/modules/mail/', 'label' => 'Mail'],
        'info' => 'Who works from each mailbox, the quota of 15, and the actions waiting for the server. The server makes each change within a minute.',
        'actions' => ($isAdmin ? ui_button('Add mailbox', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'mb-new']]) : ''),
    ]) ?>

    <?php if ($isAdmin): ?>
    <div class="ui-grid ui-grid--stats">
        <div class="ui-card"><?= ui_stat(['label' => "Mailboxes on {$domain}", 'value' => $quota['read_at'] === null ? 'Not read yet' : $quota['domain_used'] . ' of ' . $quota['domain_quota']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Left', 'value' => (string) $quota['domain_left']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Kept for personal mailboxes', 'value' => (string) $quota['reserved_personal']]) ?></div>
        <div class="ui-card"><?= ui_stat(['label' => 'Whole hosting account', 'value' => $quota['account_max'] ? $quota['account_used'] . ' of ' . $quota['account_max'] : 'Not read yet']) ?></div>
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
        <?php if (($uapiStatus['ok'] ?? null) === false): ?>
            <?= ui_banner('danger', ['title' => 'cPanel commands failing', 'text' => 'See the Health tab.', 'info' => (string) ($uapiStatus['error'] ?? '')]) ?>
        <?php elseif ($quota['read_at'] === null): ?>
            <?= ui_banner('info', ['title' => 'Counting mailboxes', 'text' => 'Refresh in a minute.']) ?>
        <?php endif; ?>
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-h3">In the ERP</h2>
                <div class="ui-toolbar__end">
                    <?= ui_button('Add personal mailbox', ['variant' => 'secondary', 'icon' => 'user-round-plus', 'size' => 'sm', 'attrs' => ['data-dialog-open' => 'mb-personal']]) ?>
                </div>
            </div>
            <?php if (!$accounts): ?>
                <div class="ui-card__body"><?= ui_empty(['icon' => 'inbox', 'title' => 'No mailboxes yet', 'text' => 'Bring info@ and embrace@ in below.']) ?></div>
            <?php else: ?>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th>Address</th><th>Kind</th><th>Status</th><th class="is-num">Storage</th><th>People</th><th class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
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
                            <td data-label="People"><?= $a['kind'] === 'personal' ? 'Its holder only' : ui_e(count($readers) . ' ' . (count($readers) === 1 ? 'person' : 'people')) ?></td>
                            <td class="is-actions">
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-menu-trigger aria-controls="mb-menu-<?= (int) $a['id'] ?>" aria-label="Actions for <?= ui_e($a['email']) ?>"><?= ui_icon('ellipsis') ?></button>
                                <div class="ui-menu" id="mb-menu-<?= (int) $a['id'] ?>" hidden>
                                    <?php if ($a['kind'] !== 'personal'): ?><button type="button" class="ui-menu__item" data-action="mb.grants" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Edit access</button><?php endif; ?>
                                    <?php if (in_array($a['status'], ['active', 'needs_reset', 'suspended'], true)): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="reset" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Reset password</button><?php endif; ?>
                                    <?php if (in_array($a['status'], ['active', 'needs_reset'], true)): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="suspend" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Suspend mailbox</button><?php endif; ?>
                                    <?php if ($a['status'] === 'suspended'): ?><button type="button" class="ui-menu__item" data-action="mb.act" data-what="resume" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Resume mailbox</button><?php endif; ?>
                                    <button type="button" class="ui-menu__item" data-action="mb.quota" data-id="<?= (int) $a['id'] ?>" data-mb="<?= (int) $a['disk_quota_mb'] ?>">Set storage</button>
                                    <button type="button" class="ui-menu__item" data-action="mb.autoreply" data-id="<?= (int) $a['id'] ?>">Set auto-reply</button>
                                    <?php if ($a['status'] === 'leaving'): ?><button type="button" class="ui-menu__item" data-action="mb.export" data-id="<?= (int) $a['id'] ?>" data-email="<?= ui_e($a['email']) ?>">Request export</button><?php endif; ?>
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
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Outside the ERP</h2><?= mail_info('Mailboxes made in cPanel. Bringing one in sets a new password, so phones, Outlook or webmail using the old one stop. Leave info@, embrace@ and tom.blake@ outside until reading and writing both work in the ERP. system@ and dmarc@ always stay outside.') ?></div>
            <div class="ui-card__body">
                <?php if (!$notInErp): ?>
                    <p class="text-ink-muted">Nothing else on <?= ui_e($domain) ?>.</p>
                <?php else: ?>
                <ul class="ui-list">
                    <?php foreach ($notInErp as $email):
                        $local = substr($email, 0, -strlen('@' . $domain)); ?>
                        <li class="ui-list__item">
                            <div class="ui-list__main"><span class="ui-list__title"><?= ui_e($email) ?></span>
                                <span class="ui-list__meta"><?= isset($outside[$local]) ? ui_e($outside[$local]) : 'Bringing it in sets a new password.' ?></span></div>
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
        <?= ui_banner('info', ['title' => 'Exports need two approvals', 'text' => 'From two different people.',
            'actions' => mail_info('A departed holder\'s mail is read by nobody unless one person gives the admin approval and another the pastoral approval. The person who asked then downloads it for 7 days. Every step is recorded.')]) ?>
        <?php if (!$exports): ?>
            <div class="ui-card"><div class="ui-card__body"><?= ui_empty(['icon' => 'lock-keyhole', 'title' => 'No export requests', 'text' => 'None waiting.']) ?></div></div>
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
                    <?php if ($r['can_approve_admin']): ?><?= ui_button('Give admin approval', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.approve', 'data-request' => $r['id'], 'data-side' => 'admin']]) ?><?php endif; ?>
                    <?php if ($r['can_approve_pastoral']): ?><?= ui_button('Give pastoral approval', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.approve', 'data-request' => $r['id'], 'data-side' => 'pastoral']]) ?><?php endif; ?>
                    <?php if ($r['status'] === 'pending' && ($r['can_approve_admin'] || $r['can_approve_pastoral'])): ?><?= ui_button('Refuse export', ['variant' => 'danger-ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.refuse', 'data-request' => $r['id']]]) ?><?php endif; ?>
                    <?php if ($r['can_download']): ?><?= ui_button('Download mail', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['data-action' => 'mb.download', 'data-request' => $r['id']]]) ?><?php endif; ?>
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
        <section class="ui-card" data-mb-ai>
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-h3">AI in Mail</h2>
                <?= mail_info('Summaries and draft replies with Gemini, on press; AI never sends. It stays off until the owner confirms the Gemini project is on a paid plan. Then switch it on per mailbox: any mailbox, personal and pastoral ones too. People also need "AI summaries and draft replies in Mail" in Roles & permissions.') ?>
            </div>
            <div class="ui-card__body ui-stack">
                <?php $aiPlan = HOD\Mail\Ai\Ai::plan(); ?>
                <div class="ui-toolbar">
                    <span><?= $aiPlan ? ui_badge('Paid plan confirmed', 'success') . ' ' . ui_e(HOD\Mail\People::firstName($aiPlan['by']) . ', ' . HOD\Mail\Read\Format::when($aiPlan['at'], true)) : ui_badge('Not confirmed', 'neutral') ?></span>
                    <?= HOD\Mail\Ai\Ai::key() === '' ? ui_badge('No GEMINI_API_KEY', 'warning') : '' ?>
                    <div class="ui-toolbar__end">
                        <?= $aiPlan
                            ? ui_button('Withdraw', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.aiplan', 'data-confirmed' => '0']])
                            : ui_button('Record paid plan', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.aiplan', 'data-confirmed' => '1']]) ?>
                    </div>
                </div>
                <ul class="ui-list">
                    <?php foreach ($accounts as $a): ?>
                    <li class="ui-list__item"><div class="ui-list__main"><span class="ui-list__title"><?= ui_e($a['email']) ?></span></div>
                        <div class="ui-list__end">
                            <?= $a['ai_enabled'] ? ui_badge('On', 'success') : ui_badge('Off', 'neutral') ?>
                            <?= $aiPlan ? ui_button($a['ai_enabled'] ? 'Turn off' : 'Turn on', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'mb.aibox', 'data-id' => $a['id'], 'data-on' => $a['ai_enabled'] ? '0' : '1']]) : '' ?>
                        </div></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <form class="ui-card ui-form" data-action="mb.reserved" novalidate>
            <div class="ui-card__body ui-form-grid">
                <?= ui_field('number', ['name' => 'reserved_personal', 'label' => 'Slots kept for personal mailboxes', 'value' => $quota['reserved_personal'],
                    'hint' => 'Other mailboxes cannot use them.', 'attrs' => ['min' => 0, 'max' => $quota['domain_quota']]]) ?>
            </div>
            <div class="ui-card__footer"><?= ui_button('Save slots', ['variant' => 'primary', 'type' => 'submit']) ?></div>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<?= ui_dialog_start('mb-new', ['title' => 'Add mailbox', 'description' => 'Church-wide or for a department.']) ?>
<form class="ui-form" data-action="mb.create" novalidate>
    <?= ui_field('select', ['name' => 'kind', 'label' => 'Kind', 'options' => ['church' => 'Church-wide (info@, embrace@ …)', 'department' => 'A department\'s (media@, finance@ …)'], 'required' => true]) ?>
    <?= ui_field('text', ['name' => 'local_part', 'label' => 'Address', 'hint' => "Before @{$domain}, lower case.", 'required' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <?= ui_field('text', ['name' => 'display_name', 'label' => 'Name in the From line', 'placeholder' => 'HOD Lekki Media Team', 'required' => true]) ?>
    <?= ui_field('select', ['name' => 'department_id', 'label' => 'Department', 'options' => ['' => 'Not a department mailbox'] + array_column($departments, 'label', 'id'), 'optional' => true]) ?>
    <label class="ui-check"><input type="checkbox" name="is_pastoral" value="1"> Pastoral mailbox</label>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Create mailbox', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-personal', ['title' => 'Add personal mailbox', 'description' => "firstname.lastname@{$domain}"]) ?>
<form class="ui-form" data-action="mb.personal" novalidate>
    <?= ui_field('search', ['name' => 'person_q', 'label' => 'Person', 'placeholder' => 'Type two letters of their name', 'attrs' => ['data-action-input' => 'mb.people', 'autocomplete' => 'off']]) ?>
    <div class="ui-list" data-mb-people role="listbox" aria-label="People found"></div>
    <input type="hidden" name="user_id" value="">
    <?= ui_field('text', ['name' => 'local_part', 'label' => 'Address', 'hint' => 'Suggested from their name.', 'required' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Issue mailbox', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-bringin', ['title' => 'Bring mailbox in', 'description' => 'This sets a new password.']) ?>
<form class="ui-form" data-action="mb.bringin.save" novalidate>
    <input type="hidden" name="email" value="">
    <p class="ui-hint"><span data-mb-bringin-email></span> <?= mail_info('Phones and Outlook lose this mailbox once its password changes. Leave info@, embrace@ and tom.blake@ outside until reading and writing both work in the ERP.') ?></p>
    <?= ui_field('select', ['name' => 'kind', 'label' => 'Kind', 'options' => ['church' => 'Church-wide', 'department' => 'A department\'s', 'personal' => 'Someone\'s personal mailbox'], 'required' => true]) ?>
    <?= ui_field('text', ['name' => 'display_name', 'label' => 'Name in the From line', 'required' => true]) ?>
    <?= ui_field('select', ['name' => 'department_id', 'label' => 'Department', 'options' => ['' => '—'] + array_column($departments, 'label', 'id'), 'optional' => true]) ?>
    <?= ui_field('number', ['name' => 'user_id', 'label' => 'Holder\'s user id', 'optional' => true, 'hint' => 'Personal mailboxes only.']) ?>
    <label class="ui-check"><input type="checkbox" name="is_pastoral" value="1"> Pastoral mailbox</label>
    <div class="ui-form-actions"><?= ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]) ?><?= ui_button('Bring in', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-grants', ['title' => 'Mailbox access', 'size' => 'lg', 'description' => 'Who reads and sends from it.']) ?>
<div class="ui-stack" data-mb-grants></div>
<div class="ui-form-actions"><?= ui_button('Add line', ['variant' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-action' => 'mb.grants.add']]) ?><?= ui_button('Save access', ['variant' => 'primary', 'attrs' => ['data-action' => 'mb.grants.save']]) ?></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('mb-autoreply', ['title' => 'Automatic reply', 'description' => 'Sent once a day to each sender.']) ?>
<form class="ui-form" data-action="mb.autoreply.save" novalidate>
    <input type="hidden" name="id" value="">
    <?= ui_field('text', ['name' => 'subject', 'label' => 'Subject', 'required' => true]) ?>
    <?= ui_field('textarea', ['name' => 'body', 'label' => 'Message', 'required' => true]) ?>
    <?= ui_field('number', ['name' => 'days', 'label' => 'For how many days', 'value' => 30, 'attrs' => ['min' => 1, 'max' => 90]]) ?>
    <div class="ui-form-actions"><?= ui_button('Turn off', ['variant' => 'ghost', 'attrs' => ['data-action' => 'mb.autoreply.off']]) ?><?= ui_button('Turn on', ['variant' => 'primary', 'type' => 'submit']) ?></div>
</form>
<?= ui_dialog_end() ?>
<?php endif; ?>

<?= ui_json_script('mail-mailboxes', ['domain' => $domain, 'api' => '/api/mail_admin_api.php', 'export' => '/api/mail_export.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
