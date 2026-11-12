<?php
// /reach.php
// Public capture entry point. Volunteers open /reach.php?c=<slug> in the
// field (usually shared via WhatsApp) and record leads with two taps.
// No auth guard — access is scoped by the campaign slug and the
// volunteer identity check in Step 1 (api/reach_public_api.php re-checks it).
// Rebuilt on the public layout (strict CSP, self-hosted assets); script:
// assets/ui/js/pages/reach-capture.js.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reach_helpers.php';

$slug = trim($_GET['c'] ?? '');
$campaign = null;
$campaign_fields = [];

if ($slug !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, slug, title, campaign_type, campaign_date, start_time, end_time,
                   location, meta_description, share_scripture, flyer_path, payload_tier, status
              FROM reach_campaigns
             WHERE slug = ? LIMIT 1
        ");
        $stmt->execute([$slug]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($campaign) {
            $fStmt = $pdo->prepare("SELECT field_name FROM reach_campaign_fields WHERE campaign_id = ?");
            $fStmt->execute([$campaign['id']]);
            $campaign_fields = $fStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        error_log('reach.php lookup failed: ' . $e->getMessage());
        $campaign = null;
    }
}

$show_field = function (string $name) use ($campaign_fields): bool {
    return in_array($name, $campaign_fields, true);
};

// OG defaults survive even when the slug is bad; social scrapers still
// get a usable card. WhatsApp ignores SVG previews, hence the PNG.
require_once __DIR__ . '/includes/site_url.php';
require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';
$host = site_host();
$guide_html = $slug !== '' ? reach_markdown(reach_evangelism_guide($pdo)) : '';

$og_title = 'Reach — Household of David Lekki Centre';
$og_desc = 'Every soul counts. Meet people, log the encounter, watch heaven celebrate.';
$og_image = 'https://' . $host . '/assets/images/logo_hod.png';
$og_url = 'https://' . $host . '/reach.php' . ($slug !== '' ? '?c=' . rawurlencode($slug) : '');
$date_label = '';
$time_label = '';
$flyer = '';

if ($campaign) {
    $og_title = $campaign['title'] . ' — HOD Lekki';
    $flyer = preg_match('#^/(?!/)#', (string) $campaign['flyer_path']) ? (string) $campaign['flyer_path'] : '';
    $og_image = 'https://' . $host . ($flyer ?: reach_default_campaign_image($pdo));
    if (!empty($campaign['meta_description'])) {
        $og_desc = $campaign['meta_description'];
    }
    if (!empty($campaign['campaign_date'])) {
        $date_label = date('D, j M Y', strtotime($campaign['campaign_date']));
    }
    if (!empty($campaign['start_time'])) {
        $time_label = date('g:i A', strtotime($campaign['start_time']));
        if (!empty($campaign['end_time'])) {
            $time_label .= ' – ' . date('g:i A', strtotime($campaign['end_time']));
        }
    }
}

$head = '';
foreach ([
    'og:type' => 'website', 'og:title' => $og_title, 'og:description' => $og_desc,
    'og:image' => $og_image, 'og:url' => $og_url,
] as $prop => $value) {
    $head .= '    <meta property="' . ui_e($prop) . '" content="' . ui_e($value) . '">' . "\n";
}
foreach ([
    'twitter:card' => $campaign ? 'summary_large_image' : 'summary', 'twitter:title' => $og_title,
    'twitter:description' => $og_desc, 'twitter:image' => $og_image,
] as $name => $value) {
    $head .= '    <meta name="' . ui_e($name) . '" content="' . ui_e($value) . '">' . "\n";
}
$head .= '    <meta name="description" content="' . ui_e($og_desc) . '">' . "\n";

ui_public_open([
    'title' => $campaign ? (string) $campaign['title'] : 'Reach',
    'mode' => 'strict',
    'indexable' => true,
    'head' => $head,
    'scripts' => $campaign ? ['/assets/ui/js/pages/reach-capture.js'] : [],
]);
?>
<div class="mx-auto max-w-lg space-y-4 px-4 py-6 sm:py-10">
<?php if (!$campaign): ?>
    <section class="ui-card">
        <?= ui_empty([
            'icon' => 'megaphone',
            'title' => 'This link isn’t active',
            'text' => 'Ask your Reach leader for the current capture link.',
        ]) ?>
    </section>
<?php else: ?>
    <?= ui_json_script('reach-config', ['campaignId' => (int) $campaign['id']]) ?>

    <section class="ui-card overflow-hidden" aria-labelledby="reach-title">
<?php if ($flyer !== ''): ?>
        <img src="<?= ui_e($flyer) ?>" alt="Campaign flyer" class="max-h-80 w-full object-cover">
<?php endif; ?>
        <div class="ui-card__body space-y-3">
            <p class="text-xs font-semibold uppercase tracking-widest text-hodRed"><?= ui_e(str_replace('_', ' ', (string) $campaign['campaign_type'])) ?></p>
            <h1 class="ui-h1" id="reach-title"><?= ui_e($campaign['title']) ?></h1>
<?php if ($date_label || $time_label || !empty($campaign['location'])): ?>
            <div class="ui-chips flex-wrap">
<?php if ($date_label): ?>
                <span class="ui-chip"><?= ui_icon('calendar', 'ui-icon--sm') ?><?= ui_e($date_label) ?></span>
<?php endif; ?>
<?php if ($time_label): ?>
                <span class="ui-chip"><?= ui_icon('clock', 'ui-icon--sm') ?><?= ui_e($time_label) ?></span>
<?php endif; ?>
<?php if (!empty($campaign['location'])): ?>
                <span class="ui-chip"><?= ui_icon('map-pin', 'ui-icon--sm') ?><?= ui_e($campaign['location']) ?></span>
<?php endif; ?>
            </div>
<?php endif; ?>
<?php if (!empty($campaign['share_scripture'])): ?>
            <blockquote class="ui-quote">&ldquo;<?= ui_e($campaign['share_scripture']) ?>&rdquo;</blockquote>
<?php endif; ?>
        </div>
    </section>

    <section class="ui-card" id="reach-volunteer" aria-label="Who is capturing" hidden>
        <div class="ui-card__body flex items-center gap-3">
            <span class="ui-avatar ui-avatar--c1" aria-hidden="true"><span id="reach-volunteer-initial">?</span></span>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-ink-subtle">Capturing as</p>
                <p class="truncate font-semibold text-ink" id="reach-volunteer-name">—</p>
            </div>
            <div class="text-center">
                <p class="font-display text-xl font-bold tabular-nums text-ink" id="reach-count">0</p>
                <p class="text-xs text-ink-subtle">Saved</p>
            </div>
            <?= ui_button('Switch', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'reach.switch']]) ?>
        </div>
    </section>

    <section class="ui-card" id="reach-step1" aria-labelledby="reach-step1-title">
        <div class="ui-card__body space-y-4">
            <div class="flex items-start gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white">1</span>
                <div>
                    <div class="ui-title-row">
                        <h2 class="ui-h3" id="reach-step1-title">Who’s capturing today?</h2>
                        <?= ui_info('Every soul you log is credited to you.', 'Why we ask') ?>
                    </div>
                    <p class="text-sm text-ink-muted">Enter your registered phone number.</p>
                </div>
            </div>
            <form class="flex gap-2" id="reach-vol-form" data-action="reach.lookup" novalidate>
                <label class="sr-only" for="reach-vol-phone">Your phone number</label>
                <input class="ui-input flex-1" type="tel" id="reach-vol-phone" inputmode="tel" autocomplete="tel" placeholder="e.g. 0803 344 5566">
                <?= ui_button('Continue', ['variant' => 'primary', 'type' => 'submit']) ?>
            </form>
            <div class="ui-banner ui-banner--success" id="reach-vol-match" hidden>
                <?= ui_icon('circle-check') ?>
                <div class="ui-banner__body">
                    <p class="ui-banner__title">Welcome back</p>
                    <p class="ui-banner__text" id="reach-vol-match-name">—</p>
                    <div class="ui-banner__actions">
                        <?= ui_button('Yes, that’s me', ['variant' => 'primary', 'size' => 'sm', 'attrs' => ['data-action' => 'reach.confirm']]) ?>
                        <?= ui_button('Not me', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'reach.reject']]) ?>
                    </div>
                </div>
            </div>
            <div id="reach-vol-nomatch" hidden>
                <?= ui_banner('warning', ['title' => 'We couldn’t find that number.', 'text' => 'Check it, or ask your Reach leader to add you.']) ?>
            </div>
        </div>
    </section>

    <section class="ui-card" id="reach-step2" aria-labelledby="reach-step2-title" hidden>
        <div class="ui-card__body space-y-4">
            <div class="flex items-start gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-hodBlue font-display text-sm font-bold text-white">2</span>
                <div class="min-w-0 flex-1">
                    <h2 class="ui-h3" id="reach-step2-title">Log a soul</h2>
                    <p class="text-sm text-ink-muted">Saving clears the form for the next person.</p>
                </div>
                <?= ui_button('Speak instead', ['variant' => 'soft', 'icon' => 'mic-vocal', 'icon_only' => true, 'attrs' => ['data-action' => 'reach.voice']]) ?>
            </div>

            <form class="ui-form" id="reach-lead-form" data-action="reach.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="submit_lead">
                <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                <input type="hidden" name="category" id="reach-category" value="Other">
                <div class="ui-form-grid">
                    <?= ui_field('text', ['name' => 'first_name', 'id' => 'reach-first', 'label' => 'First name', 'required' => true, 'autocomplete' => 'off', 'attrs' => ['autocapitalize' => 'words']]) ?>
                    <?= ui_field('text', ['name' => 'last_name', 'id' => 'reach-last', 'label' => 'Last name', 'optional' => true, 'autocomplete' => 'off', 'attrs' => ['autocapitalize' => 'words']]) ?>
                </div>
                <?= ui_field('tel', ['name' => 'phone', 'id' => 'reach-phone', 'label' => 'Phone', 'optional' => true, 'autocomplete' => 'off', 'inputmode' => 'tel', 'placeholder' => '080…',
                    'attrs' => ['data-action-change' => 'reach.dup']]) ?>
                <div class="ui-banner ui-banner--info" id="reach-dup" aria-live="polite" hidden></div>

                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">Where are they spiritually?</legend>
                    <p class="ui-hint mb-2">Tap all that apply.</p>
                    <div class="ui-chips" id="reach-categories" role="group" aria-label="Spiritual state"></div>
                </fieldset>

                <label class="ui-check"><input type="checkbox" name="willing_for_visit" value="1">
                    <span>Open to a home visit?<span class="ui-check__hint">Someone from our church family visits within 7 days.</span></span></label>
                <label class="ui-check"><input type="checkbox" name="will_attend_church" value="1">
                    <span>Will come to church?<span class="ui-check__hint">They said they’ll visit on Sunday.</span></span></label>

<?php if ($campaign_fields): ?>
                <details class="rounded-ui border border-line">
                    <summary class="cursor-pointer px-3 py-2 text-sm font-semibold">More details <span class="font-normal text-ink-subtle">· optional</span></summary>
                    <div class="ui-form space-y-3 px-3 pb-3">
<?php if ($show_field('address')): ?>
                        <?= ui_field('text', ['name' => 'address', 'id' => 'reach-address', 'label' => 'Address or area', 'autocomplete' => 'off']) ?>
<?php endif; ?>
<?php if ($show_field('prayer_request')): ?>
                        <?= ui_field('textarea', ['name' => 'prayer_request', 'id' => 'reach-prayer', 'label' => 'Prayer request', 'attrs' => ['rows' => 2]]) ?>
<?php endif; ?>
<?php if ($show_field('age_band')): ?>
                        <?= ui_field('select', ['name' => 'age_band', 'id' => 'reach-age', 'label' => 'Age group',
                            'options' => ['' => 'Prefer not to say', 'Under 18' => 'Under 18', '18-25' => '18-25', '26-35' => '26-35', '36-50' => '36-50', '51-65' => '51-65', '65+' => '65+']]) ?>
<?php endif; ?>
<?php if ($show_field('marital_status')): ?>
                        <?= ui_field('select', ['name' => 'marital_status', 'id' => 'reach-marital', 'label' => 'Marital status',
                            'options' => ['' => 'Prefer not to say', 'Single' => 'Single', 'Married' => 'Married', 'Widowed' => 'Widowed', 'Divorced' => 'Divorced']]) ?>
<?php endif; ?>
<?php if ($show_field('language')): ?>
                        <?= ui_field('text', ['name' => 'language', 'id' => 'reach-language', 'label' => 'Language', 'autocomplete' => 'off']) ?>
<?php endif; ?>
<?php if ($show_field('best_time_to_call')): ?>
                        <?= ui_field('text', ['name' => 'best_time_to_call', 'id' => 'reach-calltime', 'label' => 'Best time to call', 'autocomplete' => 'off', 'placeholder' => 'Weekday evenings']) ?>
<?php endif; ?>
<?php if ($show_field('notes')): ?>
                        <?= ui_field('textarea', ['name' => 'notes', 'id' => 'reach-notes', 'label' => 'Notes', 'attrs' => ['rows' => 2]]) ?>
<?php endif; ?>
                    </div>
                </details>
<?php endif; ?>

                <?= ui_button('Save & next person', ['variant' => 'brand', 'type' => 'submit', 'block' => true, 'size' => 'lg', 'attrs' => ['id' => 'reach-save']]) ?>
            </form>

            <div class="border-t border-line pt-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Just saved</p>
                <div class="ui-chips" id="reach-recent"><span class="text-sm text-ink-subtle">Nobody yet.</span></div>
            </div>
        </div>
    </section>

<?php if ($guide_html !== ''): ?>
    <details class="ui-card group">
        <summary class="ui-card__body flex cursor-pointer items-center gap-3">
            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-ink">Tips for sharing your faith</span>
                <span class="block text-sm text-ink-muted">Prayer, the Gospel in brief, invitations and scriptures.</span>
            </span>
            <?= ui_icon('chevron-down', 'transition-transform group-open:rotate-180') ?>
        </summary>
        <div class="ui-card__body ui-prose max-w-none border-t border-line"><?= $guide_html ?></div>
    </details>
<?php endif; ?>

    <p class="pt-4 text-center text-xs text-ink-subtle">&copy; <?= date('Y') ?> Household of David · Reach</p>

    <?= ui_dialog_start('reach-voice-dialog', ['title' => 'Voice capture', 'description' => 'Say a name, a number and where they are spiritually.', 'size' => 'sm']) ?>
        <p class="text-sm italic text-ink-muted">“Chinedu Okafor, 0803 344 5566, dechurched, wants a visit.”</p>
        <div class="my-5 flex flex-col items-center gap-2">
            <button type="button" class="grid h-16 w-16 place-items-center rounded-full bg-hodBlue text-white shadow-ui-lg" id="reach-voice-rec" data-action="reach.voice-rec" aria-pressed="false" aria-label="Start or stop recording">
                <?= ui_icon('mic-vocal', 'ui-icon--lg') ?>
            </button>
            <p class="text-sm text-ink-muted" id="reach-voice-status" aria-live="polite">Tap to speak</p>
        </div>
        <?= ui_field('textarea', ['name' => 'transcript', 'id' => 'reach-voice-text', 'label' => 'Transcript', 'placeholder' => 'Your words appear here', 'attrs' => ['rows' => 3]]) ?>
    <?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Prefill form', ['variant' => 'primary', 'attrs' => ['id' => 'reach-voice-apply', 'data-action' => 'reach.voice-apply']])) ?>
<?php endif; ?>
</div>
<?php
ui_public_close();
