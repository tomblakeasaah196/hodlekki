<?php
// /assimilation.php
// Public entry point for Assimilation volunteers. Opened on a phone, usually
// from a WhatsApp link. No auth guard and no session: access is scoped by the
// volunteer's own phone number, re-checked by the API on every single action
// (see api/assimilation_public_api.php). Rebuilt on the public layout (strict
// CSP, self-hosted assets); script: assets/ui/js/pages/assim-volunteer.js.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/assimilation_helpers.php';

$guide_html = '';
$self_claim = true;
try {
    $guide_html = reach_markdown(assim_volunteer_guide($pdo));
    $self_claim = assim_allow_self_claim($pdo);
} catch (Throwable $e) {
    error_log('assimilation.php setup: ' . $e->getMessage());
}

require_once __DIR__ . '/includes/site_url.php';
require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';
$host = site_host();
$og_title = 'Assimilation — Household of David Lekki Centre';
$og_desc = 'Going after the one. Call the people who have drifted, and walk them home.';

$head = '';
foreach ([
    'og:type' => 'website', 'og:title' => $og_title, 'og:description' => $og_desc,
    'og:image' => 'https://' . $host . '/assets/images/logo_hod.png', 'og:url' => 'https://' . $host . '/assimilation.php',
] as $prop => $value) {
    $head .= '    <meta property="' . ui_e($prop) . '" content="' . ui_e($value) . '">' . "\n";
}
$head .= '    <meta name="twitter:card" content="summary">' . "\n";

ui_public_open([
    'title' => 'Assimilation',
    'mode' => 'strict',
    'head' => $head,
    'scripts' => ['/assets/ui/js/pages/assim-volunteer.js'],
]);
?>
<div class="mx-auto max-w-lg space-y-4 px-4 py-6 sm:py-10">
    <section class="ui-card" id="av-step1" aria-labelledby="av-step1-title">
        <div class="ui-card__body space-y-4">
            <div class="flex items-start gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white">1</span>
                <div>
                    <div class="ui-title-row">
                        <h1 class="ui-h2" id="av-step1-title">Going after the one</h1>
                        <?= ui_info('Only the Assimilation team can open this page.', 'Who this page is for') ?>
                    </div>
                    <p class="text-sm text-ink-muted">Enter the phone number the church has for you.</p>
                </div>
            </div>
            <form class="flex gap-2" data-action="av.lookup" novalidate>
                <label class="sr-only" for="av-phone">Your phone number</label>
                <input class="ui-input flex-1" type="tel" id="av-phone" inputmode="tel" autocomplete="tel" placeholder="e.g. 0803 344 5566">
                <?= ui_button('Continue', ['variant' => 'primary', 'type' => 'submit']) ?>
            </form>
            <div class="ui-banner ui-banner--success" id="av-match" hidden>
                <?= ui_icon('circle-check') ?>
                <div class="ui-banner__body">
                    <p class="ui-banner__title">Welcome back</p>
                    <p class="ui-banner__text"><span class="font-semibold" id="av-match-name">—</span>, you’re an Assimilation volunteer.</p>
                    <div class="ui-banner__actions">
                        <?= ui_button('Yes, that’s me', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'av.confirm']]) ?>
                        <?= ui_button('Not me', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'av.reject']]) ?>
                    </div>
                </div>
            </div>
            <div id="av-nomatch" hidden>
                <?= ui_banner('warning', ['title' => 'Not on the team yet', 'text' => 'Check it, or ask your Assimilation leader to add you.']) ?>
            </div>
        </div>
    </section>

    <div class="space-y-4" id="av-step2" hidden>
        <section class="ui-card" aria-label="Signed in volunteer">
            <div class="ui-card__body flex items-center gap-3">
                <span class="ui-avatar ui-avatar--c1" id="av-avatar" aria-hidden="true"><span id="av-initial">?</span></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-ink-subtle">Signed in as</p>
                    <p class="truncate font-semibold text-ink" id="av-name">—</p>
                </div>
                <div class="text-center">
                    <p class="font-display text-xl font-bold tabular-nums text-ink" id="av-today">0</p>
                    <p class="text-xs text-ink-subtle">Today</p>
                </div>
                <?= ui_button('Switch', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'av.switch']]) ?>
            </div>
        </section>

        <div class="ui-banner ui-banner--success" id="av-home" hidden>
            <?= ui_icon('house') ?>
            <div class="ui-banner__body"><p class="ui-banner__text"><span class="font-semibold" id="av-home-count">0</span> <span id="av-home-word">people you called have come home.</span></p></div>
        </div>

        <section class="space-y-3" aria-labelledby="av-mine-title">
            <div class="flex items-start gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white">2</span>
                <div>
                    <h2 class="ui-h3" id="av-mine-title">Your people to call</h2>
                    <p class="text-sm text-ink-muted">Pray for them by name, then call. Most urgent first.</p>
                </div>
            </div>
            <div class="space-y-3" id="av-mine" aria-live="polite"></div>
        </section>

<?php if ($self_claim): ?>
        <section class="space-y-3" id="av-pool-section" aria-labelledby="av-pool-title" hidden>
            <div>
                <h2 class="ui-h3" id="av-pool-title">Nobody is calling these yet</h2>
                <p class="text-sm text-ink-muted">Pick up anyone you can carry this week.</p>
            </div>
            <div class="space-y-3" id="av-pool"></div>
        </section>
<?php endif; ?>
    </div>

<?php if ($guide_html !== ''): ?>
    <details class="ui-card group" id="guideBlock">
        <summary class="ui-card__body flex cursor-pointer items-center gap-3">
            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-ink">How to follow up with love</span>
                <span class="block text-sm text-ink-muted">Your heart, the call, hard moments, staying filled.</span>
            </span>
            <?= ui_icon('chevron-down', 'transition-transform group-open:rotate-180') ?>
        </summary>
        <div class="ui-card__body ui-prose max-w-none border-t border-line"><?= $guide_html ?></div>
    </details>
<?php endif; ?>

    <p class="pt-4 text-center text-xs text-ink-subtle">&copy; <?= date('Y') ?> Household of David · Assimilation</p>
</div>

<?= ui_dialog_start('av-log', ['title' => 'Follow-up', 'description' => ' ', 'size' => 'drawer']) ?>
    <div id="av-log-body" class="space-y-5"></div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('av-celebrate', ['title' => 'They came home', 'size' => 'sm']) ?>
    <div class="space-y-3 text-center">
        <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-success-soft text-success"><?= ui_icon('house', 'ui-icon--xl') ?></span>
        <p class="text-ink-muted" id="av-celebrate-body"></p>
    </div>
<?= ui_dialog_end(ui_button('Amen', ['variant' => 'primary', 'block' => true, 'attrs' => ['data-dialog-close' => true]])) ?>
<?php
ui_public_close();
