<?php
// /modules/reach/index.php
// Reach & Evangelism: outreach campaigns with public capture links, the
// follow-up queue for everyone met, analytics with the monthly PDF, and the
// how-to guide (modules/reach/how_to_use.md). All data goes through
// /api/reach_api.php; the page script is assets/ui/js/pages/reach.js.
$ui = [
    'title' => 'Reach & Evangelism',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/reach.js'],
];
require_once '../../includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo ui_no_access('Please sign in again to open Reach.');
    require_once '../../includes/footer.php';
    exit;
}

require_once __DIR__ . '/../../includes/reach_helpers.php';

$reach_can_assign = false;
try {
    $reach_can_assign = reach_is_manager($pdo, (int) $_SESSION['user_id'], $_SESSION['active_role'] ?? '');
} catch (PDOException $e) {
    error_log('Reach manager check: ' . $e->getMessage());
}

$reach_types = [];
$reach_default_image = REACH_FALLBACK_CAMPAIGN_IMAGE;
try {
    $reach_types = array_map(fn($t) => ['code' => $t['code'], 'label' => $t['label']], reach_campaign_types($pdo));
    $reach_default_image = reach_default_campaign_image($pdo);
} catch (PDOException $e) {
    error_log('Reach campaign settings: ' . $e->getMessage());
}
$reach_types = $reach_types ?: [['code' => 'Other', 'label' => 'Other']];
$reach_guide_html = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/', $reach_guide_html, $reach_guide_toc, PREG_SET_ORDER);

$reach_scriptures = [
    'John 3:16 — For God so loved the world that he gave his one and only Son...',
    'Romans 10:9 — If you declare with your mouth "Jesus is Lord"...',
    'Acts 16:31 — Believe in the Lord Jesus, and you will be saved — you and your household.',
    '2 Corinthians 5:17 — Therefore, if anyone is in Christ, the new creation has come.',
    'Revelation 3:20 — Here I am! I stand at the door and knock...',
    'Isaiah 1:18 — Though your sins are like scarlet, they shall be as white as snow.',
    'Matthew 11:28 — Come to me, all you who are weary and burdened, and I will give you rest.',
    'Ephesians 2:8-9 — For it is by grace you have been saved, through faith.',
];

$headerActions = ($reach_can_assign
        ? ui_button('Campaign settings', ['variant' => 'ghost', 'icon' => 'settings', 'icon_only' => true, 'attrs' => ['data-action' => 'reach.settings']])
        : '')
    . ui_button('New campaign', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'reach.new']]);
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Reach & Evangelism',
        'description' => 'Outreach campaigns, capture links and follow-up.',
        'actions' => $headerActions,
    ]) ?>

    <?= ui_tabs([
        'campaigns' => ['label' => 'Campaigns', 'icon' => 'megaphone'],
        'followup' => ['label' => 'Follow-up', 'icon' => 'users'],
        'analytics' => ['label' => 'Analytics', 'icon' => 'chart-column'],
        'howto' => ['label' => 'How to use', 'icon' => 'circle-help'],
    ], 'campaigns', 'Reach sections') ?>

    <section id="panel-campaigns" data-tab-panel="campaigns" class="ui-tab-panel">
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3" data-reach-campaigns aria-live="polite">
<?php for ($i = 0; $i < 3; $i++): ?>
            <div class="ui-card overflow-hidden" aria-hidden="true">
                <span class="ui-skeleton block h-36 rounded-none"></span>
                <div class="ui-card__body"><span class="ui-skeleton ui-skeleton--title w-2/3"></span><span class="ui-skeleton ui-skeleton--text mt-3 w-1/2"></span></div>
            </div>
<?php endfor; ?>
        </div>
    </section>

    <section id="panel-followup" data-tab-panel="followup" class="ui-tab-panel" hidden>
        <button type="button" class="ui-banner ui-banner--warning mb-4 w-full text-left" data-action="reach.overdue" data-reach-overdue hidden>
            <?= ui_icon('clock') ?>
            <span class="ui-banner__body">
                <span class="ui-banner__title"><span class="ui-num" data-reach-overdue-count>0</span> leads assigned more than <?= (int) reach_overdue_days($pdo) ?> days ago with no follow-up</span>
                <span class="ui-banner__text" data-reach-overdue-hint>Tap to show only these.</span>
            </span>
        </button>

        <div class="ui-chips mb-4" role="group" aria-label="Follow-up status" data-reach-subtabs></div>

        <div class="ui-card mb-4">
            <div class="ui-card__body ui-stack ui-stack--sm">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div><label class="sr-only" for="reach-fu-search">Search name or phone</label><input class="ui-input" type="search" id="reach-fu-search" placeholder="Search name or phone" autocomplete="off" data-action-input="reach.fu-filter"></div>
                    <div><label class="sr-only" for="reach-fu-campaign">Campaign</label><select class="ui-select" id="reach-fu-campaign" data-action-change="reach.fu-filter"><option value="">All campaigns</option></select></div>
                    <div><label class="sr-only" for="reach-fu-assignee">Assignee</label><select class="ui-select" id="reach-fu-assignee" data-action-change="reach.fu-filter"><option value="">Anyone</option><option value="unassigned">Unassigned</option><option value="me">Assigned to me</option></select></div>
                    <div><label class="sr-only" for="reach-fu-area">Area</label><input class="ui-input" type="text" id="reach-fu-area" placeholder="Area, e.g. Ajah" autocomplete="off" data-action-input="reach.fu-filter"></div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="ui-chips" role="group" aria-label="Category" data-reach-categories></div>
                    <label class="ui-check"><input type="checkbox" id="reach-fu-willing" data-action-change="reach.fu-filter"><span>Willing to visit only</span></label>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-reach-leads aria-live="polite"></div>
        <div class="mt-5 flex justify-center" data-reach-more hidden>
            <?= ui_button('Load more', ['variant' => 'secondary', 'attrs' => ['data-action' => 'reach.more']]) ?>
        </div>
    </section>

    <section id="panel-analytics" data-tab-panel="analytics" class="ui-tab-panel" hidden>
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="ui-chips" role="group" aria-label="Date range" data-reach-presets></div>
            <div class="flex flex-wrap gap-2">
                <?= ui_button('Export CSV', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['data-action' => 'reach.csv']]) ?>
                <?= ui_button('Generate PDF', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'file-down', 'attrs' => ['data-action' => 'reach.pdf']]) ?>
            </div>
        </div>
        <div class="mb-5 flex flex-wrap items-end gap-3" data-reach-custom hidden>
            <div><label class="ui-label" for="reach-an-from">From</label><input class="ui-input" type="date" id="reach-an-from" data-action-change="reach.an-load"></div>
            <div><label class="ui-label" for="reach-an-to">To</label><input class="ui-input" type="date" id="reach-an-to" data-action-change="reach.an-load"></div>
        </div>
        <div data-reach-pdf></div>
        <div data-reach-analytics aria-live="polite"></div>
    </section>

    <section id="panel-howto" data-tab-panel="howto" class="ui-tab-panel" hidden>
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
            <nav class="ui-card h-fit lg:sticky lg:top-4" aria-label="Guide sections">
                <ul class="ui-list">
<?php foreach ($reach_guide_toc as $h): ?>
                    <li><a class="ui-list__item text-sm" href="#<?= ui_e($h[1]) ?>"><?= ui_e(strip_tags($h[2])) ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>
            <article class="ui-card ui-card__body ui-prose max-w-none">
                <?= $reach_guide_html ?: '<p>The guide could not be loaded.</p>' ?>
            </article>
        </div>
    </section>
</div>

<?= ui_dialog_start('reach-campaign', ['title' => 'New campaign', 'description' => 'Plan an outreach and get its public capture link.', 'size' => 'lg']) ?>
    <form class="ui-form" id="reach-campaign-form" data-action="reach.save-campaign" data-ui-validate novalidate>
        <input type="hidden" name="action" value="create_campaign">
        <input type="hidden" name="id" value="">
        <?= ui_field('text', ['name' => 'title', 'id' => 'reach-title', 'label' => 'Campaign title', 'required' => true, 'placeholder' => 'e.g. Ikate Street Evangelism']) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'campaign_type', 'id' => 'reach-type', 'label' => 'Event type', 'options' => []]) ?>
            <?= ui_field('text', ['name' => 'location', 'id' => 'reach-location', 'label' => 'Location', 'optional' => true, 'placeholder' => 'e.g. Orphanage home, Surulere']) ?>
        </div>
        <div class="ui-form-grid ui-form-grid--3">
            <?= ui_field('date', ['name' => 'campaign_date', 'id' => 'reach-date', 'label' => 'Date']) ?>
            <?= ui_field('time', ['name' => 'start_time', 'id' => 'reach-start', 'label' => 'Starts', 'attrs' => ['data-action-change' => 'reach.start-change']]) ?>
            <?= ui_field('time', ['name' => 'end_time', 'id' => 'reach-end', 'label' => 'Ends', 'attrs' => ['data-action-change' => 'reach.end-change']]) ?>
        </div>
        <div class="ui-chips -mt-2" role="group" aria-label="Duration">
<?php foreach ([30 => '30 min', 60 => '1 hr', 90 => '1½ hrs', 120 => '2 hrs'] as $mins => $dur_label): ?>
            <button type="button" class="ui-chip" data-action="reach.duration" data-min="<?= $mins ?>" aria-pressed="false"><?= ui_e($dur_label) ?></button>
<?php endforeach; ?>
        </div>

        <div class="ui-field">
            <span class="ui-label">Flyer <span class="ui-label__opt">(optional)</span></span>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <img class="h-28 w-full flex-none rounded-ui border border-line bg-surface-sunken object-cover sm:w-48" alt="Flyer preview" data-reach-flyer-preview>
                <div class="min-w-0 flex-1">
                    <label class="sr-only" for="reach-flyer">Flyer image</label>
                    <input class="ui-input" type="file" id="reach-flyer" name="flyer" accept="image/jpeg,image/png,image/webp" data-action-change="reach.flyer">
                    <p class="ui-hint">JPG, PNG or WebP, up to 5 MB. <?= ui_info('The flyer is the link preview and the capture-page background. No flyer? The default image is used.', 'About the flyer') ?></p>
                    <label class="ui-check mt-2" data-reach-remove-flyer hidden><input type="checkbox" name="remove_flyer" value="1" data-action-change="reach.remove-flyer"><span>Remove the current flyer</span></label>
                </div>
            </div>
        </div>

        <fieldset class="ui-fieldset">
            <legend class="ui-label">Form length</legend>
            <div class="grid grid-cols-3 gap-2">
<?php foreach (['Rapid' => 'Quick', 'Standard' => 'Standard', 'Rich' => 'Detailed'] as $tier => $tierLabel): ?>
                <label class="ui-check justify-center rounded-ui border border-line p-3"><input type="radio" name="payload_tier" value="<?= $tier ?>"<?= $tier === 'Rich' ? ' checked' : '' ?>><span><?= ui_e($tierLabel) ?></span></label>
<?php endforeach; ?>
            </div>
            <details class="mt-2 text-sm text-ink-muted">
                <summary class="cursor-pointer text-ink">What does each form ask?</summary>
                <ul class="mt-2 space-y-1">
                    <li><strong class="text-ink">Quick:</strong> name, phone, where they are spiritually, open to a visit. About 10 seconds.</li>
                    <li><strong class="text-ink">Standard:</strong> adds area and prayer request.</li>
                    <li><strong class="text-ink">Detailed:</strong> adds age group, marital status, language, best time to call and notes. Every extra question is optional.</li>
                </ul>
            </details>
        </fieldset>

        <div class="ui-card ui-card--sunken p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-ink-muted">AI can write the description and scripture.</p>
                <div class="flex gap-2">
                    <?= ui_button('Another option', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'reach.ai-next', 'data-reach-ai-next' => true, 'hidden' => true]]) ?>
                    <?= ui_button('Write with AI', ['variant' => 'soft', 'size' => 'sm', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'reach.ai', 'data-reach-ai' => true]]) ?>
                </div>
            </div>
        </div>

        <div class="ui-field">
            <label class="ui-label" for="reach-meta">Link description <span class="ui-label__opt">(shows in WhatsApp previews)</span></label>
            <textarea class="ui-textarea" id="reach-meta" name="meta_description" rows="2" maxlength="300" data-action-input="reach.meta-count" aria-describedby="reach-meta-hint"></textarea>
            <p class="ui-hint" id="reach-meta-hint"><span class="ui-num" data-reach-meta-count>0</span> / 160 characters recommended</p>
        </div>

        <div class="ui-field">
            <label class="ui-label" for="reach-scripture-pick">Scripture to share</label>
            <select class="ui-select" id="reach-scripture-pick" data-action-change="reach.scripture">
                <option value="">Pick a scripture</option>
<?php foreach ($reach_scriptures as $s): ?>
                <option><?= ui_e($s) ?></option>
<?php endforeach; ?>
                <option value="__custom__">Write my own…</option>
            </select>
            <label class="sr-only" for="reach-scripture">Scripture text</label>
            <textarea class="ui-textarea mt-2" id="reach-scripture" name="share_scripture" rows="2" placeholder="Scripture text"></textarea>
        </div>

        <div data-reach-status hidden>
            <?= ui_field('select', ['name' => 'status', 'id' => 'reach-status', 'label' => 'Status', 'options' => ['Active' => 'Active', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled']]) ?>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save campaign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'reach-campaign-form']])
) ?>

<?= ui_dialog_start('reach-share', ['title' => 'Share the capture link', 'description' => ' ']) ?>
    <div class="flex flex-col items-center gap-4">
        <div class="h-48 w-48 rounded-ui border border-line bg-white p-3 text-ink" data-reach-qr role="img" aria-label="QR code for the capture link"></div>
        <div class="flex w-full gap-2">
            <label class="sr-only" for="reach-share-link">Capture link</label>
            <input class="ui-input min-w-0 flex-1" type="text" id="reach-share-link" readonly>
            <?= ui_button('Copy', ['variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-action' => 'reach.copy']]) ?>
        </div>
        <div class="ui-field w-full">
            <label class="ui-label" for="reach-share-text">WhatsApp message</label>
            <textarea class="ui-textarea" id="reach-share-text" rows="4" readonly></textarea>
        </div>
    </div>
<?= ui_dialog_end(
    ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . '<a class="ui-btn ui-btn--primary" href="#" target="_blank" rel="noopener" data-reach-whatsapp>' . ui_icon('message-circle') . '<span>Send on WhatsApp</span></a>'
) ?>

<?php if ($reach_can_assign): ?>
<?= ui_dialog_start('reach-settings', ['title' => 'Campaign settings', 'description' => 'Event types, the default image, follow-up timing and the volunteers\' guide.', 'size' => 'lg']) ?>
    <div class="ui-stack">
        <section>
            <h3 class="ui-h3">Event types</h3>
            <p class="ui-meta mt-0.5">Removing one keeps it on past campaigns.</p>
            <div class="mt-3 flex flex-wrap gap-2" data-reach-types></div>
            <form class="mt-3 flex gap-2" data-action="reach.add-type" novalidate>
                <label class="sr-only" for="reach-new-type">New event type</label>
                <input class="ui-input min-w-0 flex-1" id="reach-new-type" name="label" maxlength="60" placeholder="e.g. Market storm" autocomplete="off">
                <?= ui_button('Add', ['variant' => 'secondary', 'type' => 'submit', 'icon' => 'plus']) ?>
            </form>
        </section>
        <hr class="ui-divider">
        <section>
            <h3 class="ui-h3">Default campaign image</h3>
            <p class="ui-meta mt-0.5">For campaigns without a flyer. Best at 1200 × 630.</p>
            <img class="mt-3 h-32 w-full max-w-sm rounded-ui border border-line object-cover" alt="Default campaign image" data-reach-default-image>
            <div class="mt-3 flex flex-wrap gap-2">
                <label class="ui-btn ui-btn--secondary cursor-pointer" for="reach-default-upload"><?= ui_icon('upload') ?><span>Upload new image</span></label>
                <input class="sr-only" type="file" id="reach-default-upload" accept="image/jpeg,image/png,image/webp" data-action-change="reach.default-upload">
                <?= ui_button('Use church photo', ['variant' => 'ghost', 'attrs' => ['data-action' => 'reach.default-reset']]) ?>
            </div>
        </section>
        <hr class="ui-divider">
        <form class="ui-form" id="reach-settings-form" data-action="reach.save-settings" data-ui-validate novalidate>
            <input type="hidden" name="action" value="save_reach_settings">
            <h3 class="ui-h3">Follow-up</h3>
            <?= ui_field('number', ['name' => 'overdue_days', 'id' => 'reach-overdue-days', 'label' => 'Mark a lead overdue after (days)', 'required' => true, 'inputmode' => 'numeric', 'info' => 'Counted from when the lead was assigned without any follow-up. A daily alert goes to the whole Reach team.', 'attrs' => ['min' => '1', 'max' => '60']]) ?>
            <div class="flex items-center justify-between gap-2">
                <h3 class="ui-h3">Evangelism guide</h3>
                <?= ui_button('Restore default', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'reach.restore-guide']]) ?>
            </div>
            <?= ui_field('textarea', ['name' => 'guide', 'id' => 'reach-guide', 'label' => 'Guide text', 'info' => 'Shown to volunteers on every campaign page. Use ## for headings, - for lists, **bold**, *italic*.', 'attrs' => ['rows' => '10']]) ?>
            <div class="flex justify-end"><?= ui_button('Save settings', ['variant' => 'primary', 'type' => 'submit']) ?></div>
        </form>
    </div>
<?= ui_dialog_end() ?>
<?php endif; ?>

<?= ui_dialog_start('reach-lead', ['title' => 'Lead', 'description' => ' ', 'size' => 'drawer']) ?>
    <div class="ui-stack" data-reach-lead aria-live="polite"></div>
<?= ui_dialog_end() ?>

<?= ui_json_script('reach-config', [
    'types' => $reach_types,
    'defaultImage' => $reach_default_image,
    'canAssign' => $reach_can_assign,
]) ?>

<?php require_once '../../includes/footer.php'; ?>
