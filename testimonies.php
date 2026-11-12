<?php
// /testimonies.php
// The Wall of Praise: approved testimonies with Hallelujahs and comments, and
// the form to share one (written, recorded on the phone, or an uploaded voice
// note), all through /api/testimony_public_api.php as before. Rebuilt on the
// public layout (strict CSP, self-hosted assets); script:
// assets/ui/js/pages/testimonies.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
session_start();

$is_logged_in = isset($_SESSION['user_id']);
$user_name = $is_logged_in ? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) : '';
$user_email = $is_logged_in ? (string) ($_SESSION['email'] ?? '') : '';

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';
ui_public_open([
    'title' => 'Testimonies',
    'mode' => 'strict',
    'indexable' => true,
    'scripts' => ['/assets/ui/js/pages/testimonies.js'],
    'actions' => ui_button('Main site', ['variant' => 'ghost', 'size' => 'sm', 'icon_end' => 'external-link', 'href' => 'https://householdofdavid.org',
        'attrs' => ['target' => '_blank', 'rel' => 'noopener']]),
]);
?>
<div class="mx-auto max-w-5xl px-4 py-8 sm:py-12">
    <header class="mx-auto mb-10 max-w-2xl space-y-4 text-center">
        <p class="text-xs font-semibold uppercase tracking-widest text-hodRed">Testimonies of the goodness of God</p>
        <h1 class="ui-h1">The Wall of Praise</h1>
        <blockquote class="ui-quote mx-auto">“They triumphed over him by the blood of the Lamb and by the word of their testimony.”
            <span class="mt-2 block text-xs font-semibold uppercase tracking-widest text-hodRed">Revelation 12:11</span></blockquote>
        <?= ui_button('Share Your Miracle', ['variant' => 'brand', 'size' => 'lg', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'tm.open']]) ?>
    </header>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3" id="tm-wall" aria-live="polite">
        <div class="ui-skeleton ui-skeleton--block h-48"></div>
        <div class="ui-skeleton ui-skeleton--block h-48"></div>
        <div class="ui-skeleton ui-skeleton--block h-48"></div>
    </div>
</div>

<?= ui_dialog_start('tm-submit', ['title' => 'Testify of God’s goodness', 'description' => 'Write it, record it, or both.', 'size' => 'lg']) ?>
    <form class="ui-form" id="tm-form" data-action="tm.submit" novalidate>
        <input type="hidden" name="action" value="submit_testimony">
        <input type="hidden" name="time_opened" id="tm-time">
        <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
            <label for="tm-hp">Leave this empty</label>
            <input type="text" name="honeypot_contact" id="tm-hp" tabindex="-1" autocomplete="off">
        </div>
        <div class="ui-form-grid">
            <?= ui_field('text', ['name' => 'guest_name', 'id' => 'tm-name', 'label' => 'Your name', 'value' => $user_name, 'placeholder' => 'e.g. John Doe', 'autocomplete' => 'name']) ?>
            <?= ui_field('email', ['name' => 'guest_email', 'id' => 'tm-email', 'label' => 'Email', 'optional' => true, 'value' => $user_email,
                'placeholder' => 'For pastoral follow-up', 'autocomplete' => 'email']) ?>
        </div>
        <?= ui_field('select', ['name' => 'privacy_level', 'id' => 'tm-privacy', 'label' => 'Who sees your name', 'required' => true,
            'options' => ['Public' => 'Everyone', 'Anonymous_To_All' => 'Nobody (fully anonymous)', 'Pastoral_Only' => 'Pastors only'],
            'info' => 'Everyone: your name shows on the wall. Pastors only: hidden on the wall, known to the pastors. Fully anonymous: hidden from all.']) ?>

        <fieldset class="ui-fieldset">
            <legend class="ui-legend">Voice note <span class="ui-label__opt">(optional)</span></legend>
            <div class="flex flex-wrap items-center gap-2">
                <?= ui_button('Record', ['variant' => 'secondary', 'icon' => 'mic-vocal', 'attrs' => ['id' => 'tm-rec', 'data-action' => 'tm.record']]) ?>
                <?= ui_button('Stop', ['variant' => 'danger', 'attrs' => ['id' => 'tm-stop', 'data-action' => 'tm.stop', 'hidden' => true]]) ?>
                <span class="font-mono text-sm tabular-nums text-ink-muted" id="tm-timer" hidden>00:00</span>
                <label class="ui-btn ui-btn--ghost cursor-pointer"><?= ui_icon('upload') ?><span>Upload file</span>
                    <input type="file" name="voice_note" id="tm-audio-file" class="sr-only" accept="audio/*" data-action-change="tm.audio-file"></label>
            </div>
            <div class="mt-3 flex items-center gap-2" id="tm-audio-preview" hidden>
                <audio id="tm-audio" class="w-full" controls></audio>
                <?= ui_button('Clear', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'tm.audio-clear']]) ?>
            </div>
        </fieldset>

        <?= ui_field('textarea', ['name' => 'content_text', 'id' => 'tm-text', 'label' => 'Your testimony', 'placeholder' => 'What has the Lord done for you?', 'attrs' => ['rows' => 5]]) ?>
        <label class="ui-check"><input type="checkbox" name="request_editing" value="1">
            <span>Please review and format my testimony<span class="ui-check__hint">Our team corrects the grammar and formats it before publishing.</span></span></label>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Share testimony', ['variant' => 'brand', 'type' => 'submit', 'attrs' => ['form' => 'tm-form', 'id' => 'tm-send']])) ?>
<?php
ui_public_close();
