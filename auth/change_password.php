<?php
// /auth/change_password.php
//
// Standalone password-change screen. Two jobs:
//   1. The landing page when users.must_change_password = 1 (e.g. straight
//      after an admin reset). includes/header.php bounces every authenticated
//      page here until the password is changed, so this file deliberately does
//      NOT include header.php — that would be a redirect loop.
//   2. A direct link for anyone who just wants to change their password
//      without going through My Profile.
// Rebuilt on the foundation (strict CSP, standalone layout); the form posts to
// /api/profile_api.php (change_password, signs other devices out) as before.
// Script: assets/ui/js/pages/change-password.js.
require_once __DIR__ . '/../includes/db.php';
if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$forced = false;
try {
    if (security_schema_ready($pdo)) {
        $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $forced = (int) $stmt->fetchColumn() === 1;
    }
} catch (Throwable $e) {
    error_log('change_password.php status check failed: ' . $e->getMessage());
}

$firstName = (string) ($_SESSION['first_name'] ?? 'there');
// Where a forced change sends the member afterwards: the page they were
// opening when they signed in (auth_api.php leaves it in place for this),
// otherwise the dashboard.
$afterForced = auth_safe_next($_SESSION['post_login_next'] ?? null) ?? '/';
$minLength = SECURITY_MIN_PASSWORD_LENGTH;
$rules = implode(' · ', security_password_rules());

require_once __DIR__ . '/../includes/ui/standalone.php';
ui_standalone_open($forced ? 'Set a new password' : 'Change password', [
    'scripts' => ['/assets/ui/js/pages/change-password.js'],
]);
echo ui_json_script('pw-config', [
    'forced' => $forced,
    'after' => $forced ? $afterForced : '/modules/profile/#security',
]);
?>
    <div class="flex items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-ui bg-primary-soft text-primary"><?= ui_icon('lock-keyhole', 'ui-icon--lg') ?></span>
        <div class="min-w-0">
            <h1 class="ui-h2"><?= $forced ? 'Set a new password' : 'Change your password' ?></h1>
<?php if ($forced): ?>
            <div class="ui-title-row mt-1">
                <p class="text-ink-muted">Hi <?= ui_e($firstName) ?>, choose a new password to continue.</p>
                <?= ui_info('An administrator has asked you to choose a new password before you continue. You cannot skip this step.', 'Why am I here?') ?>
            </div>
<?php else: ?>
            <p class="mt-1 text-ink-muted">Hi <?= ui_e($firstName) ?>, enter your current password and pick a new one.</p>
<?php endif; ?>
        </div>
    </div>

    <form class="ui-form" id="pw-form" data-action="pw.save" data-ui-validate novalidate autocomplete="off">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="sign_out_others" value="1">
        <?php /* The profile API requires the session CSRF token (includes/csrf.php). */ ?>
        <?= ui_csrf_field() ?>
        <?= ui_field('password', ['name' => 'current_password', 'id' => 'pw-current', 'label' => 'Current Password', 'required' => true,
            'autocomplete' => 'current-password', 'placeholder' => $forced ? 'The temporary password you were given' : '••••••••']) ?>
        <?= ui_field('password', ['name' => 'new_password', 'id' => 'pw-new', 'label' => 'New Password', 'required' => true,
            'autocomplete' => 'new-password', 'placeholder' => 'At least ' . (int) $minLength . ' characters', 'info' => 'Your new password needs: ' . $rules . '.']) ?>
        <?= ui_field('password', ['name' => 'confirm_password', 'id' => 'pw-confirm', 'label' => 'Confirm New Password', 'required' => true,
            'autocomplete' => 'new-password', 'placeholder' => 'Type it once more']) ?>
        <label class="ui-check"><input type="checkbox" id="pw-show" data-action-change="pw.show"><span>Show passwords</span></label>
        <?= ui_button('Save New Password', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'attrs' => ['id' => 'pw-submit']]) ?>
        <div class="flex items-center justify-between gap-2 text-sm">
<?php if (!$forced): ?>
            <a class="ui-link" href="/modules/profile/#security">Back to My Profile</a>
<?php else: ?>
            <span class="text-ink-subtle">You cannot skip this step.</span>
<?php endif; ?>
            <a class="ui-link text-ink-muted" href="/auth/logout">Sign out</a>
        </div>
    </form>
<?php
ui_standalone_close();
