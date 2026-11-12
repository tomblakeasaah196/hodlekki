<?php
// /parent_portal.php
// Junior Church parent portal. A parent signs in with their phone and one of
// their children's dates of birth (or is already signed in to the app) and
// sees each child's Sundays: attendance, the lesson, the activity sheet and
// the video. All data comes from /api/parent_portal_api.php as before.
// Rebuilt on the public layout (strict CSP; YouTube and PDFs open in a viewer
// dialog, hence frame-src). Script: assets/ui/js/pages/parent-portal.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
session_start();

// Check if parent is already authenticated via main portal or parent gateway
$isAuthenticated = isset($_SESSION['user_id']) || isset($_SESSION['parent_portal_auth_id']);

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';
ui_public_open([
    'title' => 'Parent Portal',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/parent-portal.js'],
    'csp' => [
        'frame-src' => ["'self'", 'https://www.youtube-nocookie.com', 'https://www.youtube.com'],
        'img-src' => ['https://img.youtube.com'],
    ],
]);
echo ui_json_script('pp-config', ['authenticated' => $isAuthenticated]);
?>
<div class="mx-auto max-w-3xl px-4 py-6 sm:py-10">

    <section class="mx-auto max-w-md" id="pp-gate"<?= $isAuthenticated ? ' hidden' : '' ?> aria-labelledby="pp-gate-title">
        <div class="ui-card">
            <div class="ui-card__body space-y-5">
                <div class="text-center">
                    <span class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-full bg-primary-soft text-primary"><?= ui_icon('baby', 'ui-icon--xl') ?></span>
                    <h1 class="ui-h2" id="pp-gate-title">Parent Portal</h1>
                    <p class="mt-1 text-ink-muted">Your child’s Junior Church attendance and lessons.</p>
                </div>
                <form class="ui-form" id="pp-gate-form" data-action="pp.gate" data-ui-validate novalidate>
                    <input type="hidden" name="action" value="authenticate_parent">
                    <?= ui_field('tel', ['name' => 'phone', 'id' => 'pp-phone', 'label' => 'Your phone number', 'required' => true,
                        'inputmode' => 'tel', 'autocomplete' => 'tel', 'placeholder' => 'Your registered phone']) ?>
                    <?= ui_field('date', ['name' => 'child_dob', 'id' => 'pp-dob', 'label' => 'Child’s date of birth', 'required' => true,
                        'info' => 'For security, enter any one of your children’s dates of birth.']) ?>
                    <?= ui_button('Verify & Enter', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'size' => 'lg']) ?>
                </form>
                <div class="space-y-1 border-t border-line pt-4 text-center text-sm">
                    <p class="text-ink-muted">Already have a church account? <a class="ui-link" href="/auth/login">Sign in</a></p>
                    <p><a class="ui-link text-ink-muted" href="/connect">Back to Connect</a></p>
                </div>
            </div>
        </div>
    </section>

    <section class="space-y-5" id="pp-app"<?= $isAuthenticated ? '' : ' hidden' ?> aria-labelledby="pp-name">
        <div class="ui-card overflow-hidden">
            <div class="ui-card__body flex items-start justify-between gap-3 bg-hodBlue text-white">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-widest text-white/70">Junior Church · Parent Portal</p>
                    <h1 class="mt-1 font-display text-2xl font-bold" id="pp-name">Welcome back</h1>
                    <p class="mt-1 text-sm text-white/80">Attendance, lessons and take-home resources.</p>
                </div>
                <button type="button" class="ui-btn ui-btn--sm shrink-0 bg-white/10 text-white hover:bg-white/20" data-action="pp.logout"><?= ui_icon('log-out') ?><span>Sign out</span></button>
            </div>
        </div>

        <div id="pp-children-wrap" hidden>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Your children</p>
            <div class="flex gap-2 overflow-x-auto pb-1" id="pp-children" role="group" aria-label="Your children"></div>
        </div>

        <div class="ui-grid ui-grid--3 ui-grid--stats" id="pp-stats" hidden>
            <div class="ui-card ui-stat"><span class="ui-stat__label"><span>Services</span></span><span class="ui-stat__value" id="pp-total">0</span></div>
            <div class="ui-card ui-stat"><span class="ui-stat__label"><span>Present</span></span><span class="ui-stat__value text-success" id="pp-present">0</span></div>
            <div class="ui-card ui-stat"><span class="ui-stat__label"><span>Absent</span></span><span class="ui-stat__value text-danger" id="pp-absent">0</span></div>
        </div>

        <section class="ui-card" id="pp-timeline-card" aria-labelledby="pp-timeline-title" hidden>
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title" id="pp-timeline-title">Recent Sundays</h2>
                <?= ui_button('Update photo', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'image', 'attrs' => ['data-action' => 'pp.photo']]) ?>
            </div>
            <ol class="ui-list" id="pp-timeline" aria-live="polite"></ol>
        </section>

        <section class="ui-card" id="pp-empty" hidden>
            <?= ui_empty(['icon' => 'users-round', 'title' => 'No children found', 'text' => 'Ask the Junior Church team to link your children.']) ?>
        </section>

        <p class="pt-4 text-center text-xs text-ink-subtle">Household of David · Junior Church Parent Portal · <a class="ui-link" href="/connect">Back to Connect</a></p>
    </section>
</div>

<?= ui_dialog_start('pp-photo-dialog', ['title' => 'Update photo', 'description' => 'Choose a clear photo of your child.', 'size' => 'sm']) ?>
    <div class="space-y-4 text-center">
        <img id="pp-photo-preview" class="mx-auto h-40 w-40 rounded-full object-cover" alt="Your child’s photo" src="data:," hidden>
        <span class="mx-auto grid h-40 w-40 place-items-center rounded-full bg-surface-sunken text-ink-subtle" id="pp-photo-empty"><?= ui_icon('image', 'ui-icon--xl') ?></span>
        <?= ui_field('file', ['name' => 'photo', 'id' => 'pp-photo-file', 'label' => 'New photo', 'attrs' => ['accept' => 'image/*', 'data-action-change' => 'pp.photo-file']]) ?>
    </div>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save photo', ['variant' => 'primary', 'attrs' => ['id' => 'pp-photo-save', 'data-action' => 'pp.photo-save', 'hidden' => true]])) ?>

<?= ui_dialog_start('pp-crop-dialog', ['title' => 'Crop the photo', 'description' => 'Drag to frame the face.', 'size' => 'lg']) ?>
    <div class="mx-auto max-h-[60vh] max-w-md overflow-hidden rounded-ui bg-surface-sunken">
        <img id="pp-crop-img" class="block max-w-full" src="data:," alt="The photo being cropped">
    </div>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Apply crop', ['variant' => 'primary', 'attrs' => ['data-action' => 'pp.crop-apply']])) ?>

<?= ui_dialog_start('pp-viewer', ['title' => 'Lesson', 'size' => 'xl']) ?>
    <div id="pp-viewer-body" class="flex justify-center"></div>
<?= ui_dialog_end() ?>
<?php
ui_public_close();
