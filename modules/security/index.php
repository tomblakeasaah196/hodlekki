<?php
// /modules/security/index.php
/**
 * Security Centre: accounts (suspend, revoke, reset a password, change the
 * sign-in email, sign out), live sessions, sign-in activity and the audit
 * trail. Built on the shared UI (docs/UI.md) in strict mode; behaviour in
 * assets/ui/js/pages/security.js, data from api/security_api.php.
 *
 * Access: Roles & permissions, module "security" (Super Admins, the Resident
 * Pastor). Changing an account needs Security: Edit; generating sign-in
 * emails for everyone needs Security: Manage. The guard rails (never your own
 * account, a pastor never acts on a Super Admin, the last Super Admin stays)
 * live in includes/security_helpers.php and are enforced by the API.
 */
$ui = [
    'title'   => 'Security Centre',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/security.js'],
    'offline' => false,   // account security: never an old copy
];
require_once '../../includes/header.php';
require_can('security');

$schemaReady = security_schema_ready($pdo);
$canEdit = can('security', 'edit');
$canManage = can('security', 'manage');
$viewerIsSuper = security_is_super_admin($pdo);
$domain = SECURITY_LOGIN_EMAIL_DOMAIN;
$filters = [
    'all' => 'All accounts', 'active' => 'Active', 'suspended' => 'Suspended', 'revoked' => 'Revoked',
    'locked' => 'Locked out', 'must_change' => 'Must change password', 'no_password' => 'No password set',
    'no_email' => 'No sign-in email', 'online' => 'Online now', 'privileged' => 'Has roles',
];
$tabs = [
    'accounts' => ['label' => 'Accounts', 'icon' => 'users'],
    'sessions' => ['label' => 'Sessions', 'icon' => 'smartphone'],
    'logins' => ['label' => 'Sign-ins', 'icon' => 'log-out'],
    'audit' => ['label' => 'Audit trail', 'icon' => 'history'],
];
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Security Centre',
        'description' => 'Accounts, sign-ins and the audit trail.',
        'info' => 'Suspend or revoke an account, reset a password, change a sign-in email and sign people out. '
            . 'Every action is kept in the audit trail. You cannot act on your own account here, and only a Super Admin acts on a Super Admin.',
        'actions' => $schemaReady && $canManage
            ? ui_button('Generate emails', ['icon' => 'sparkles', 'attrs' => ['data-action' => 'sec.generate']])
            : '',
    ]) ?>

<?php if (!$schemaReady): ?>
    <?= ui_banner('warning', [
        'title' => 'Security tables missing',
        'text' => 'Run php db/migrate.php on the server, or deploy.',
        'info' => 'Migration 20261005090000_security_core.sql adds the tables this screen reads. Until then there is nothing to show.',
    ]) ?>
<?php else: ?>
    <div class="ui-grid ui-grid--stats">
        <div data-stat="active"><?= ui_stat(['label' => 'Active', 'value' => '—', 'icon' => 'circle-check']) ?></div>
        <div data-stat="blocked"><?= ui_stat(['label' => 'Blocked', 'value' => '—', 'icon' => 'lock-keyhole']) ?></div>
        <div data-stat="sessions"><?= ui_stat(['label' => 'Online 24h', 'value' => '—', 'icon' => 'smartphone']) ?></div>
        <div data-stat="failures"><?= ui_stat(['label' => 'Failed today', 'value' => '—', 'icon' => 'triangle-alert']) ?></div>
    </div>

    <?= ui_tabs($tabs, 'accounts') ?>

    <section data-tab-panel="accounts" id="panel-accounts" role="tabpanel" aria-labelledby="tab-accounts" class="ui-tab-panel ui-stack">
        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="sec-account-search">Find an account</label>
                <input class="ui-input" type="search" id="sec-account-search" placeholder="Name, email or phone" data-action-input="sec.accounts.search" autocomplete="off">
            </div>
            <label class="sr-only" for="sec-account-filter">Show</label>
            <select class="ui-select" id="sec-account-filter" data-action-change="sec.accounts.search">
<?php foreach ($filters as $value => $label): ?>
                <option value="<?= ui_e($value) ?>"><?= ui_e($label) ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Member</th><th scope="col">Status</th><th scope="col">Roles</th><th scope="col">Last sign-in</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-sec-accounts><tr><td colspan="5"><div class="ui-loading"><span class="ui-spinner"></span><span>Loading accounts…</span></div></td></tr></tbody>
                </table>
            </div>
            <div class="ui-pagination">
                <span class="ui-meta" data-sec-range></span>
                <nav class="ui-pagination__pages" aria-label="Pages" data-sec-pager></nav>
            </div>
        </div>
    </section>

    <section data-tab-panel="sessions" id="panel-sessions" role="tabpanel" aria-labelledby="tab-sessions" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-toolbar">
            <p class="ui-meta ui-toolbar__grow">Browsers signed in over the last 7 days.</p>
            <?= ui_button('Refresh', ['variant' => 'ghost', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'sec.sessions.refresh']]) ?>
        </div>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Member</th><th scope="col">Device</th><th scope="col">IP</th><th scope="col">Signed in</th><th scope="col">Last active</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody data-sec-sessions></tbody>
                </table>
            </div>
        </div>
    </section>

    <section data-tab-panel="logins" id="panel-logins" role="tabpanel" aria-labelledby="tab-logins" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-toolbar">
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="sec-login-search">Find sign-ins</label>
                <input class="ui-input" type="search" id="sec-login-search" placeholder="Name, email or IP" data-action-input="sec.logins.search" autocomplete="off">
            </div>
            <label class="sr-only" for="sec-login-filter">Show</label>
            <select class="ui-select" id="sec-login-filter" data-action-change="sec.logins.search">
                <option value="all">All attempts</option>
                <option value="failed">Failures</option>
                <option value="success">Successes</option>
            </select>
        </div>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Result</th><th scope="col">Device</th><th scope="col">IP</th></tr></thead>
                    <tbody data-sec-logins></tbody>
                </table>
            </div>
        </div>
    </section>

    <section data-tab-panel="audit" id="panel-audit" role="tabpanel" aria-labelledby="tab-audit" class="ui-tab-panel ui-stack" hidden>
        <div class="ui-search">
            <?= ui_icon('search') ?>
            <label class="sr-only" for="sec-audit-search">Search the audit trail</label>
            <input class="ui-input" type="search" id="sec-audit-search" placeholder="Action, admin, member or detail" data-action-input="sec.audit.search" autocomplete="off">
        </div>
        <div class="ui-card ui-card__body--flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">By</th><th scope="col">Member</th><th scope="col">Details</th></tr></thead>
                    <tbody data-sec-audit></tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>
</div>

<?= ui_dialog_start('sec-manage-dialog', ['title' => 'Manage account', 'size' => 'lg']) ?>
    <div data-sec-loading class="ui-loading"><span class="ui-spinner"></span><span>Loading account…</span></div>
    <div data-sec-body class="ui-stack" hidden>
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow">
                <p class="ui-h3" data-sec-name></p>
                <p class="ui-meta" data-sec-email></p>
            </div>
            <span data-sec-status></span>
        </div>

        <div data-sec-blocked hidden>
            <?= ui_banner('warning', [
                'text' => 'You cannot act on this account here.',
                'info' => 'Your own account: change your password in My Profile → Security, and ask another admin to change your sign-in email. '
                    . 'A Super Admin\'s account: only another Super Admin acts on it.',
            ]) ?>
        </div>

        <div data-sec-actions class="ui-stack">
            <dl class="ui-dl">
                <dt>Last sign-in</dt><dd data-fact="last_login"></dd>
                <dt>Password set</dt><dd data-fact="password"></dd>
                <dt>Failed attempts</dt><dd data-fact="failures"></dd>
                <dt>Live devices</dt><dd data-fact="devices"></dd>
            </dl>

            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Roles</h3></div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <div data-sec-roles></div>
                    <div data-sec-frozen hidden>
                        <?= ui_banner('warning', [
                            'text' => 'Some roles are frozen by a revoke.',
                            'actions' => $viewerIsSuper ? ui_button('Restore roles', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'restore_roles']]) : '',
                            'info' => $viewerIsSuper ? 'Restoring gives back every role the revoke froze.' : 'Only a Super Admin restores frozen roles.',
                        ]) ?>
                    </div>
                </div>
            </section>

            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h3 class="ui-h3">Account access</h3>
                        <?= ui_info('Suspend: sign-in blocked and every session ended. Revoke: the same, and every role is frozen.', 'About account access') ?></div>
                </div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <p class="ui-meta" data-sec-reason-now hidden></p>
                    <?= ui_field('textarea', ['name' => 'reason', 'id' => 'sec-reason', 'label' => 'Reason',
                        'info' => 'Needed to suspend or revoke. It goes into the audit trail.', 'attrs' => ['rows' => 2, 'maxlength' => 255]]) ?>
                    <div class="ui-toolbar">
                        <?= ui_button('Reinstate', ['variant' => 'secondary', 'icon' => 'circle-check', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'status', 'data-value' => 'active']]) ?>
                        <?= ui_button('Suspend', ['variant' => 'secondary', 'icon' => 'lock-keyhole', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'status', 'data-value' => 'suspended']]) ?>
                        <?= ui_button('Revoke', ['variant' => 'danger', 'icon' => 'circle-x', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'status', 'data-value' => 'revoked']]) ?>
                    </div>
                </div>
            </section>

            <form class="ui-card" data-action="sec.reset" novalidate>
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h3 class="ui-h3">Reset password</h3>
                        <?= ui_info('Type the new password and read it to them privately. Every device they are signed in on is signed out.', 'About resetting a password') ?></div>
                </div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <div class="ui-form-grid">
                        <?= ui_field('text', ['name' => 'new_password', 'id' => 'sec-new-pw', 'label' => 'New password', 'autocomplete' => 'off']) ?>
                        <?= ui_field('text', ['name' => 'confirm_password', 'id' => 'sec-confirm-pw', 'label' => 'Confirm password', 'autocomplete' => 'off']) ?>
                    </div>
                    <label class="ui-check"><input type="checkbox" name="require_change" value="1" checked><span>Change it at next sign-in</span></label>
                </div>
                <div class="ui-card__footer ui-form-actions">
                    <?= ui_button('Suggest password', ['variant' => 'ghost', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'sec.suggest']]) ?>
                    <?= ui_button('Set password', ['variant' => 'primary', 'type' => 'submit']) ?>
                </div>
            </form>

            <form class="ui-card" data-action="sec.email" novalidate>
                <div class="ui-card__header ui-card__header--divided">
                    <div class="ui-title-row"><h3 class="ui-h3">Sign-in email</h3>
                        <?= ui_info('The address they type to sign in, not their personal email. Their password and personal email are not touched.', 'About the sign-in email') ?></div>
                </div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <p class="ui-meta" data-sec-email-now></p>
                    <div class="ui-form-grid">
                        <?= ui_field('text', ['name' => 'new_email', 'id' => 'sec-new-email', 'label' => 'New sign-in email', 'autocomplete' => 'off',
                            'hint' => '@' . $domain . ' is added if you leave it out.', 'attrs' => ['spellcheck' => 'false', 'autocapitalize' => 'none']]) ?>
                        <?= ui_field('text', ['name' => 'confirm_email', 'id' => 'sec-confirm-email', 'label' => 'Confirm email', 'autocomplete' => 'off',
                            'attrs' => ['spellcheck' => 'false', 'autocapitalize' => 'none']]) ?>
                    </div>
                    <label class="ui-check"><input type="checkbox" name="sign_out_everywhere" value="1"><span>Sign them out everywhere</span></label>
                </div>
                <div class="ui-card__footer ui-form-actions">
                    <?= ui_button('Save email', ['variant' => 'primary', 'type' => 'submit']) ?>
                </div>
            </form>

            <div class="ui-toolbar">
                <?= ui_button('Sign out everywhere', ['icon' => 'log-out', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'force_logout']]) ?>
                <?= ui_button('Clear lockout', ['icon' => 'lock-keyhole', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'unlock_account', 'data-sec-unlock' => '1']]) ?>
                <?= ui_button('Force change', ['icon' => 'refresh-cw', 'attrs' => ['data-action' => 'sec.act', 'data-act' => 'force_change', 'data-sec-force' => '1']]) ?>
            </div>

            <div class="ui-grid ui-grid--2">
                <section class="ui-card">
                    <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Devices</h3></div>
                    <div class="ui-list" data-sec-devices></div>
                </section>
                <section class="ui-card">
                    <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Recent sign-ins</h3></div>
                    <div class="ui-list" data-sec-attempts></div>
                </section>
            </div>
            <section class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h3 class="ui-h3">Security history</h3></div>
                <div class="ui-list" data-sec-history></div>
            </section>
        </div>
    </div>
<?= ui_dialog_end() ?>

<?= ui_json_script('security-boot', [
    'api' => '/api/security_api.php',
    'ready' => $schemaReady,
    'canEdit' => $canEdit,
    'me' => (int) $_SESSION['user_id'],
    'domain' => $domain,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
