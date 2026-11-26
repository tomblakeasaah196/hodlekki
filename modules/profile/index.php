<?php
// /modules/profile/index.php
$ui = [
    'title' => 'My Profile',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/profile.js'],
    // The address search (Geoapify) runs in the browser.
    'csp' => ['connect-src' => ['https://api.geoapify.com']],
];
require_once '../../includes/header.php';

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo ui_no_access('Please sign in again to see your profile.');
    require_once '../../includes/footer.php';
    exit;
}

$profileName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// "Your email": the personal address, confirmed by a link, and church news
// (includes/campaigns/profile_card.php; its actions are in api/profile_api.php).
$emailCard = '';
try {
    require_once __DIR__ . '/../../includes/campaigns/profile_card.php';
    $emailCard = email_profile_card_html(email_profile_view($pdo, (int) $_SESSION['user_id']));
} catch (Throwable $e) {
    error_log('[email] Profile email card failed: ' . $e->getMessage());
}
$profilePhoto = !empty($_SESSION['picture_path']) ? (string) $_SESSION['picture_path'] : null;

// Passkeys (includes/passkeys.php): shown once the migration has run.
$passkeysOn = false;
try {
    require_once __DIR__ . '/../../includes/passkeys.php';
    $passkeysOn = passkeys_ready($pdo);
} catch (Throwable $e) {
    error_log('[passkey] profile check failed: ' . $e->getMessage());
}

/** A password input with a show/hide button. */
function profile_password_field(string $id, string $name, string $label, string $autocomplete, string $hint = ''): string
{
    return '<div class="ui-field">'
        . '<label class="ui-label" for="' . ui_e($id) . '">' . ui_e($label) . '</label>'
        . '<div class="relative">'
        . '<input class="ui-input pr-12" type="password" id="' . ui_e($id) . '" name="' . ui_e($name) . '" required autocomplete="' . ui_e($autocomplete) . '"'
        . ($hint !== '' ? ' aria-describedby="' . ui_e($id) . '-hint ' . ui_e($id) . '-error"' : ' aria-describedby="' . ui_e($id) . '-error"') . '>'
        . '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm absolute right-1 top-1/2 -translate-y-1/2" data-action="profile.toggle-pw" data-target="' . ui_e($id) . '" aria-label="Show password" aria-pressed="false">' . ui_icon('eye') . '</button>'
        . '</div>'
        . ($hint !== '' ? '<p class="ui-hint" id="' . ui_e($id) . '-hint">' . ui_e($hint) . '</p>' : '')
        . '<p class="ui-field-error" id="' . ui_e($id) . '-error" aria-live="polite"></p>'
        . '</div>';
}
?>
<div class="ui-page ui-page--narrow">
    <section class="ui-card mb-6" aria-labelledby="profile-name">
        <div class="ui-card__body flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
            <div class="relative shrink-0">
                <span class="ui-avatar ui-avatar--xl <?= ui_e(ui_avatar_tone($profileName)) ?>" data-profile-avatar>
<?php if ($profilePhoto): ?>
                    <img class="nav-avatar-img" src="<?= ui_e($profilePhoto) ?>" alt="" decoding="async">
<?php else: ?>
                    <span aria-hidden="true"><?= ui_e(ui_initials($profileName)) ?></span>
<?php endif; ?>
                </span>
                <span class="ui-spinner absolute inset-0 m-auto text-white" data-profile-photo-busy hidden></span>
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="ui-h1" id="profile-name" data-profile-name><?= ui_e($profileName !== '' ? $profileName : 'My profile') ?></h1>
                <div class="mt-2 flex flex-wrap justify-center gap-2 sm:justify-start" data-profile-badges>
                    <span class="ui-skeleton h-[22px] w-20"></span>
                    <span class="ui-skeleton h-[22px] w-24"></span>
                </div>
            </div>
            <div class="shrink-0">
                <label class="ui-btn ui-btn--secondary cursor-pointer" for="profile-photo-input">
                    <?= ui_icon('image') ?><span>Change photo</span>
                </label>
                <input class="sr-only" type="file" id="profile-photo-input" accept="image/png, image/jpeg" data-action-change="profile.pick-photo" data-target="profile">
            </div>
        </div>
    </section>

    <form class="ui-card mb-6" data-action="profile.save" data-ui-validate novalidate aria-labelledby="profile-details">
        <input type="hidden" name="action" value="update_profile">
        <div class="ui-card__header ui-card__header--divided">
            <div>
                <h2 class="ui-card__title" id="profile-details">Your details</h2>
                <p class="ui-card__description">What the church office and your unit leaders see.</p>
            </div>
        </div>
        <div class="ui-card__body ui-form">
            <fieldset class="ui-fieldset">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <legend class="ui-legend m-0 flex items-center gap-2"><?= ui_icon('lock-keyhole', 'ui-icon--sm text-ink-subtle') ?>Name and church ID</legend>
                    <?= ui_button('Request a correction', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-dialog-open' => 'profile-name-dialog']]) ?>
                </div>
                <div class="ui-form-grid">
                    <?= ui_field('text', ['name' => '', 'id' => 'profile-first', 'label' => 'First name', 'attrs' => ['readonly' => true, 'name' => null, 'data-profile' => 'first_name']]) ?>
                    <?= ui_field('text', ['name' => '', 'id' => 'profile-last', 'label' => 'Last name', 'attrs' => ['readonly' => true, 'name' => null, 'data-profile' => 'last_name']]) ?>
                    <?= ui_field('email', ['name' => '', 'id' => 'profile-church-email', 'label' => 'Church ID (your sign-in email)', 'class' => 'ui-span-full', 'attrs' => ['readonly' => true, 'name' => null, 'data-profile' => 'email']]) ?>
                </div>
            </fieldset>

            <hr class="ui-divider my-0">

            <div class="ui-form-grid">
                <?= ui_field('tel', ['name' => 'phone', 'id' => 'profile-phone', 'label' => 'Phone number', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'attrs' => ['data-profile' => 'phone']]) ?>
                <?= ui_field('date', ['name' => 'dob', 'id' => 'profile-dob', 'label' => 'Date of birth', 'attrs' => ['data-profile' => 'dob']]) ?>
                <?= ui_field('select', ['name' => 'marital_status', 'id' => 'profile-marital', 'label' => 'Marital status', 'options' => ['Single' => 'Single', 'Married' => 'Married', 'Separated' => 'Separated', 'Divorced' => 'Divorced'], 'attrs' => ['data-profile' => 'marital_status', 'data-action-change' => 'profile.marital']]) ?>

                <div class="ui-field ui-span-full">
                    <label class="ui-label" for="profile-address-search">Home address</label>
                    <div class="ui-geo" data-profile-address></div>
                    <p class="ui-hint">Start typing and choose your address from the list.</p>
                    <input type="hidden" name="physical_address" data-profile="physical_address">
                    <input type="hidden" name="latitude" data-profile="latitude">
                    <input type="hidden" name="longitude" data-profile="longitude">
                </div>

                <div class="ui-span-full rounded-ui-lg border border-line bg-surface-subtle p-4" data-profile-married hidden>
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                        <div class="flex-1">
                            <?= ui_field('date', ['name' => 'wedding_anniversary', 'id' => 'profile-anniversary', 'label' => 'Wedding anniversary', 'info' => 'The Charis team uses it to celebrate your day with you.', 'attrs' => ['data-profile' => 'wedding_anniversary']]) ?>
                        </div>
                        <div class="flex shrink-0 flex-col items-start gap-2 sm:items-center">
                            <span class="ui-label">Wedding photo</span>
                            <span class="ui-avatar ui-avatar--xl ui-avatar--square bg-surface-sunken text-ink-subtle" data-profile-wedding>
                                <?= ui_icon('image', 'ui-icon--lg') ?>
                            </span>
                            <label class="ui-btn ui-btn--sm cursor-pointer" for="profile-wedding-input"><?= ui_icon('upload') ?><span>Upload</span></label>
                            <input class="sr-only" type="file" id="profile-wedding-input" accept="image/png, image/jpeg" data-action-change="profile.pick-photo" data-target="wedding">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ui-card__footer">
            <div class="ui-form-actions w-full sm:w-auto">
                <?= ui_button('Save my details', ['variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </div>
    </form>

    <?= $emailCard ?>

    <section class="ui-card" id="security" aria-labelledby="profile-security">
        <div class="ui-card__header ui-card__header--divided">
            <div>
                <h2 class="ui-card__title" id="profile-security">Password and sign-in</h2>
                <p class="ui-card__description">Change your password and see where you are signed in.</p>
            </div>
        </div>
        <div class="ui-card__body">
            <div class="mb-5" data-profile-forced hidden>
                <?= ui_banner('warning', ['title' => 'Please set a new password', 'text' => 'Choose a new password to carry on.']) ?>
            </div>
            <div class="ui-stack">
                <form class="ui-form" data-action="profile.password" autocomplete="off" novalidate>
                    <input type="hidden" name="action" value="change_password">
                    <?= profile_password_field('profile-pw-current', 'current_password', 'Current password', 'current-password') ?>
                    <div class="ui-form-grid">
                        <?= profile_password_field('profile-pw-new', 'new_password', 'New password', 'new-password') ?>
                        <?= profile_password_field('profile-pw-confirm', 'confirm_password', 'Type it again', 'new-password') ?>
                    </div>

                    <div class="rounded-ui-lg border border-line bg-surface-subtle p-4">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-ink">Strength</span>
                            <span class="ui-meta" data-profile-pw-label aria-live="polite">Start typing</span>
                        </div>
                        <progress class="ui-progress ui-progress--sm" value="0" max="100" data-profile-pw-bar aria-label="Password strength">0%</progress>
                        <ul class="mt-3 grid gap-x-4 gap-y-1.5 sm:grid-cols-2" data-profile-pw-rules></ul>
                    </div>

                    <label class="ui-check">
                        <input type="checkbox" name="sign_out_others" value="1" checked>
                        <span>Sign out my other devices afterwards<span class="ui-check__hint">Recommended if someone else may have used your account.</span></span>
                    </label>

                    <div class="ui-form-actions sm:justify-start">
                        <?= ui_button('Change password', ['variant' => 'primary', 'type' => 'submit']) ?>
                    </div>
                </form>

<?php if ($passkeysOn): ?>
                <hr class="ui-divider my-0">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h3 class="ui-h3 flex items-center gap-1">Passkeys
                            <?= ui_info('Sign in with your fingerprint, face or screen lock instead of your password. Each device keeps its own; iCloud and Google can sync one to your other devices. Your password still works.', 'About passkeys') ?></h3>
                        <?= ui_button('Add passkey', ['size' => 'sm', 'icon' => 'fingerprint', 'attrs' => ['data-action' => 'passkey.add']]) ?>
                    </div>
                    <p class="ui-meta" data-passkey-empty>None yet. Add one on this device.</p>
                    <p class="ui-meta" data-passkey-unsupported hidden>This browser cannot use passkeys.</p>
                    <ul class="ui-list rounded-ui-lg border border-line" data-passkey-list hidden></ul>
                </div>
<?php endif; ?>

                <hr class="ui-divider my-0">
                <div class="grid gap-6 md:grid-cols-2">
                    <dl class="ui-dl ui-dl--stacked">
                        <dt>Last sign-in</dt>
                        <dd data-profile-last-login>—</dd>
                        <dt>Password last changed</dt>
                        <dd data-profile-pw-changed>—</dd>
                    </dl>
                    <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="ui-h3">Signed-in devices</h3>
                        <span class="ui-badge ui-badge--round" data-profile-device-count>0</span>
                    </div>
                    <ul class="ui-list rounded-ui-lg border border-line" data-profile-devices>
                        <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></li>
                    </ul>
                    <?= ui_button('Sign out others', ['variant' => 'danger-ghost', 'block' => true, 'icon' => 'log-out', 'class' => 'mt-3', 'attrs' => ['data-action' => 'profile.sign-out-others']]) ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?= ui_dialog_start('profile-name-dialog', ['title' => 'Request a name correction', 'description' => 'A pastor approves name changes.']) ?>
    <form class="ui-form" id="profile-name-form" data-action="profile.name-change" data-ui-validate novalidate>
        <input type="hidden" name="action" value="request_name_change">
        <?= ui_field('text', ['name' => 'correct_name', 'id' => 'profile-correct-name', 'label' => 'Your correct full name', 'required' => true, 'autocomplete' => 'name']) ?>
        <?= ui_field('textarea', ['name' => 'reason', 'id' => 'profile-name-reason', 'label' => 'Why it needs to change', 'optional' => true, 'hint' => 'For example: misspelled at registration, or a married name.']) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send request', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'profile-name-form']])
) ?>

<?= ui_dialog_start('profile-crop-dialog', ['title' => 'Position your photo', 'description' => 'Drag to move; pinch or scroll to zoom.', 'size' => 'lg']) ?>
    <div class="overflow-hidden rounded-ui-lg bg-surface-sunken">
        <img class="block max-h-[60vh] max-w-full" alt="" data-profile-crop-img hidden>
    </div>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save photo', ['variant' => 'primary', 'attrs' => ['data-action' => 'profile.save-photo']])
) ?>

<?php
// Passkeys: the options travel with the page, so "Add passkey" opens the
// phone's own prompt inside the tap (iPhone allows it only there).
try {
    if ($passkeysOn) {
        $passkeyUser = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $passkeyUser->execute([(int) $_SESSION['user_id']]);
        echo ui_json_script('passkey-profile-options',
            passkey_register_options($pdo, $passkeyUser->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int) $_SESSION['user_id']]));
    }
} catch (Throwable $e) {
    error_log('[passkey] profile options failed: ' . $e->getMessage());
}
?>
<?= ui_json_script('profile-config', [
    'geoapifyKey' => (string) ($_ENV['GEOAPIFY_API_KEY'] ?? '7a189b607e9e4c4cbedf6ada291b6bc1'),
]) ?>
<?php require_once '../../includes/footer.php'; ?>
