<?php
// /idi_mobilization.php
// Exousia mobilisation: a PIN-gated call list for the IDI team (to-do and
// contacted, call / WhatsApp, follow-up notes, quick add, Excel export and
// import). Every call goes to /api/mobilization_api.php as before, which
// keeps the PIN session. Rebuilt on the public layout (strict CSP,
// self-hosted assets); script: assets/ui/js/pages/idi-mobilization.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
session_start();
$is_authenticated = isset($_SESSION['idi_mobilization_auth']) && $_SESSION['idi_mobilization_auth'] === true;

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';
ui_public_open([
    'title' => 'Exousia Mobilization',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/idi-mobilization.js'],
]);
echo ui_json_script('idi-config', ['authenticated' => $is_authenticated]);
?>
<div class="mx-auto max-w-2xl px-4 py-6 sm:py-10">

    <section class="mx-auto max-w-sm" id="idi-gate"<?= $is_authenticated ? ' hidden' : '' ?> aria-labelledby="idi-gate-title">
        <div class="ui-card">
            <div class="ui-card__body space-y-5 text-center">
                <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-primary-soft text-primary"><?= ui_icon('lock-keyhole', 'ui-icon--xl') ?></span>
                <div>
                    <h1 class="ui-h2" id="idi-gate-title">Exousia Team</h1>
                    <p class="mt-1 text-ink-muted">Enter the mobilization PIN to open the call list.</p>
                </div>
                <form class="space-y-4" id="idi-gate-form" data-action="idi.pin" novalidate>
                    <?php /* Decoys so password managers leave the PIN alone. */ ?>
                    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                        <input type="text" name="prevent_autofill_user" autocomplete="username" tabindex="-1">
                        <input type="password" name="prevent_autofill_pwd" autocomplete="current-password" tabindex="-1">
                    </div>
                    <label class="sr-only" for="idi-pin">PIN</label>
                    <input class="ui-input text-center font-mono text-2xl tracking-[0.5em] [-webkit-text-security:disc]" type="tel" id="idi-pin"
                        autocomplete="exousia-pin-nope" data-lpignore="true" data-1p-ignore="true" data-form-type="other"
                        inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="••••" required>
                    <?= ui_button('Access List', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
                </form>
            </div>
        </div>
    </section>

    <section class="space-y-4" id="idi-app"<?= $is_authenticated ? '' : ' hidden' ?> aria-labelledby="idi-title">
        <div class="ui-card">
            <div class="ui-card__body space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h1 class="ui-h2" id="idi-title">Mobilization</h1>
                        <p class="text-sm text-ink-muted">Exousia 2026</p>
                    </div>
                    <div class="flex gap-2">
                        <?= ui_button('Quick add', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'user-round-plus', 'icon_only' => true, 'attrs' => ['data-dialog-open' => 'idi-add']]) ?>
                        <?= ui_button('Manage data', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'sliders-horizontal', 'icon_only' => true, 'attrs' => ['data-dialog-open' => 'idi-data']]) ?>
                    </div>
                </div>
                <div>
                    <label class="sr-only" for="idi-search">Search by name or phone</label>
                    <input class="ui-input" type="search" id="idi-search" placeholder="Search by name or phone" autocomplete="off" data-action-input="idi.search">
                </div>
                <div class="ui-segmented w-full" role="group" aria-label="Which list">
                    <button type="button" class="ui-segmented__item flex-1" data-action="idi.tab" data-tab="todo" aria-pressed="true">To-do <span class="ui-count" id="idi-count-todo">0</span></button>
                    <button type="button" class="ui-segmented__item flex-1" data-action="idi.tab" data-tab="contacted" aria-pressed="false">Contacted <span class="ui-count" id="idi-count-contacted">0</span></button>
                </div>
            </div>
        </div>
        <div class="space-y-3" id="idi-todo" aria-live="polite"></div>
        <div class="space-y-3" id="idi-contacted" hidden></div>
    </section>
</div>

<?= ui_dialog_start('idi-data', ['title' => 'Manage data', 'description' => 'Download the list, or import an updated one.', 'size' => 'sm']) ?>
    <div class="space-y-5">
        <form action="/api/mobilization_api.php" method="POST" target="_blank">
            <input type="hidden" name="action" value="export_data">
            <?= ui_button('Download Excel', ['variant' => 'secondary', 'type' => 'submit', 'block' => true, 'icon' => 'download']) ?>
        </form>
        <form class="ui-form border-t border-line pt-5" id="idi-import-form" data-action="idi.import" data-ui-validate novalidate>
            <input type="hidden" name="action" value="import_data">
            <?= ui_field('file', ['name' => 'import_file', 'id' => 'idi-import-file', 'label' => 'Updated list', 'required' => true,
                'info' => 'A CSV or Excel file with the columns Name, Phone and Context.', 'attrs' => ['accept' => '.csv, .xls, .xlsx']]) ?>
            <a class="ui-link text-sm" href="/api/mobilization_api.php?action=download_template" target="_blank" rel="noopener">Download template</a>
            <?= ui_button('Import list', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'icon' => 'upload']) ?>
        </form>
    </div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('idi-add', ['title' => 'Quick add', 'description' => 'Someone new for the call list.', 'size' => 'sm']) ?>
    <form class="ui-form" id="idi-add-form" data-action="idi.add" data-ui-validate novalidate>
        <input type="hidden" name="action" value="quick_add">
        <?= ui_field('text', ['name' => 'full_name', 'id' => 'idi-add-name', 'label' => 'Full name', 'required' => true, 'placeholder' => 'e.g. Sarah Johnson']) ?>
        <?= ui_field('tel', ['name' => 'phone_number', 'id' => 'idi-add-phone', 'label' => 'Phone number', 'required' => true, 'inputmode' => 'tel', 'placeholder' => 'e.g. 08012345678']) ?>
        <?= ui_field('textarea', ['name' => 'context', 'id' => 'idi-add-context', 'label' => 'Background', 'optional' => true, 'placeholder' => 'Where did we meet them?', 'attrs' => ['rows' => 2]]) ?>
    </form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add to list', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'idi-add-form']])) ?>
<?php
ui_public_close();
