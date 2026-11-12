<?php
// /verify.php
// Household of David Lekki Centre: public document verification. Anyone
// holding a printed or digital HOD document types its code; the API
// (api/public_verify_api.php, honeypot-protected) answers with what was
// stamped, by whom and when. Rebuilt on the public layout (strict CSP);
// script: assets/ui/js/pages/verify.js.
//
// Baseline security headers (nosniff, referrer, framing, permissions): this
// page does not load includes/db.php on every request, which is where every
// other page gets them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
session_start();

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

ui_public_open([
    'title' => 'Document Verification',
    'mode' => 'strict',
    'indexable' => true,
    'scripts' => ['/assets/ui/js/pages/verify.js'],
    'home' => '/',
]);
?>
<div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
    <div class="mb-8 text-center">
        <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-primary-soft text-primary"><?= ui_icon('shield-check', 'ui-icon--xl') ?></span>
        <div class="ui-title-row justify-center">
            <h1 class="ui-h1">Document Verification</h1>
            <?= ui_info('Every HOD Lekki document carries a verification code. We check it against our records and show who stamped it, and when.', 'About verification') ?>
        </div>
        <p class="mt-2 text-ink-muted">Enter the code printed on your document.</p>
    </div>

    <section id="verify-search" class="ui-card">
        <form class="ui-card__body ui-form" id="verify-form" data-action="verify.check" data-ui-validate novalidate>
            <input type="hidden" name="action" value="verify_document">
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="verify-hp">Leave this empty</label>
                <input type="text" name="honeypot" id="verify-hp" tabindex="-1" autocomplete="off">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="verify-code">Verification Code<span class="ui-label__req" aria-hidden="true">*</span></label>
                <input class="ui-input font-mono uppercase tracking-[0.15em] placeholder:font-sans placeholder:normal-case placeholder:tracking-normal" type="text"
                    id="verify-code" name="verify_code" required placeholder="e.g. VRF-A1B2-C3D4" autocapitalize="characters" autocomplete="off"
                    spellcheck="false" aria-describedby="verify-code-error" data-action-input="verify.upper">
                <p class="ui-field-error" id="verify-code-error" aria-live="polite"></p>
            </div>
            <?= ui_button('Verify Document', ['variant' => 'primary', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'icon_end' => 'arrow-right']) ?>
        </form>
        <div class="ui-card__footer flex flex-wrap justify-center gap-2">
            <span class="ui-chip"><?= ui_icon('lock-keyhole', 'ui-icon--sm') ?>SHA-256 Secured</span>
            <span class="ui-chip"><?= ui_icon('globe', 'ui-icon--sm') ?>HODLC System</span>
        </div>
    </section>

    <section id="verify-result" class="ui-card overflow-hidden" aria-live="polite" hidden>
        <div class="flex items-center justify-between gap-3 bg-success-soft px-5 py-3 text-success">
            <span class="flex items-center gap-2 font-semibold"><?= ui_icon('circle-check') ?>Valid &amp; Binding</span>
            <?= ui_icon('shield-check', 'ui-icon--lg') ?>
        </div>
        <div class="ui-card__body space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Document Type</p>
                <h2 class="ui-h2" id="res-type"></h2>
                <p class="font-mono text-sm text-ink-muted" id="res-ref"></p>
            </div>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Department</dt><dd class="font-medium text-ink" id="res-dept"></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Total Amount</dt><dd class="font-medium text-ink" id="res-amount"></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Digitally Validated By</dt><dd class="font-medium text-ink" id="res-by"></dd><dd class="text-sm text-ink-muted" id="res-role"></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Timestamp</dt><dd class="font-medium text-ink" id="res-time"></dd><dd class="text-sm text-ink-muted" id="res-level"></dd></div>
            </dl>
            <div class="rounded-ui bg-surface-sunken p-4">
                <p class="mb-1 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle"><?= ui_icon('shield-check', 'ui-icon--sm') ?>SHA-256 Cryptographic Hash</p>
                <p class="break-all font-mono text-xs text-ink" id="res-hash"></p>
            </div>
        </div>
        <div class="ui-card__footer">
            <?= ui_button('Verify Another Document', ['variant' => 'secondary', 'block' => true, 'attrs' => ['data-action' => 'verify.again']]) ?>
        </div>
    </section>

    <p class="mt-10 text-center text-xs text-ink-subtle">&copy; <?= date('Y') ?> Household of David Lekki Centre. All Rights Reserved.</p>
</div>
<?php
ui_public_close();
