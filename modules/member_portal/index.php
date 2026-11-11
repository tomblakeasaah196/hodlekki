<?php
// /modules/member_portal/index.php
// My Portal: a member's own giving, attendance, prayer requests and
// testimonies. Data comes from /api/member_portal_api.php (giving, prayer)
// and /api/testimony_public_api.php (testimonies, shared with the public wall).
$ui = [
    'title' => 'My Portal',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/member-portal.js'],
];
require_once '../../includes/header.php';

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo ui_no_access('Please sign in again to see your portal.');
    require_once '../../includes/footer.php';
    exit;
}

$portalFirst = trim((string) ($_SESSION['first_name'] ?? ''));
$portalName = trim($portalFirst . ' ' . ($_SESSION['last_name'] ?? ''));
$portalPhoto = !empty($_SESSION['picture_path']) ? (string) $_SESSION['picture_path'] : null;

// Giving periods: the last twelve months, then this year and last year.
// Each value is "start|end" for the API's start_date / end_date.
$portalPeriods = [];
$month = new DateTimeImmutable('first day of this month');
for ($i = 0; $i < 12; $i++) {
    $m = $month->modify("-{$i} months");
    $portalPeriods[$m->format('Y-m-01') . '|' . $m->format('Y-m-t')] = $i === 0 ? 'This month' : $m->format('F Y');
}
$year = (int) date('Y');
$portalPeriods["{$year}-01-01|" . date('Y-m-d')] = 'This year so far';
$portalPeriods[($year - 1) . '-01-01|' . ($year - 1) . '-12-31'] = 'Last year (' . ($year - 1) . ')';

$portalGivingTypes = ['Tithe' => 'Tithe', 'Offering' => 'Offering', 'First_Fruit' => 'First fruit', 'Project' => 'Project / seed', 'Other' => 'Other'];
?>
<div class="ui-page">
    <section class="ui-card mb-6" aria-labelledby="portal-welcome">
        <div class="ui-card__body flex flex-col gap-5 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <?= ui_avatar($portalName !== '' ? $portalName : 'Member', $portalPhoto, 'xl', ['class' => 'shrink-0']) ?>
                <div class="min-w-0">
                    <h1 class="ui-h1" id="portal-welcome">Welcome<?= $portalFirst !== '' ? ', ' . ui_e($portalFirst) : '' ?></h1>
                    <div class="mt-2 flex flex-wrap gap-2" data-portal-badges>
                        <span class="ui-skeleton h-[22px] w-20"></span>
                        <span class="ui-skeleton h-[22px] w-28"></span>
                    </div>
                </div>
            </div>
            <div class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
                <?= ui_button('Watch sermons', ['variant' => 'secondary', 'icon' => 'video', 'href' => '/sermons', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?>
                <?= ui_button('Record giving', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'portal-give']]) ?>
            </div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="ui-stack min-w-0 lg:col-span-2">
            <section class="ui-card" aria-labelledby="portal-giving-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h2 class="ui-card__title" id="portal-giving-title">My giving</h2>
                        <p class="ui-card__description">What you have recorded for the period you choose.</p>
                    </div>
                    <div>
                        <label class="sr-only" for="portal-period">Period</label>
                        <select class="ui-select ui-select--sm" id="portal-period" data-action-change="portal.period">
<?php foreach ($portalPeriods as $value => $label): ?>
                            <option value="<?= ui_e($value) ?>"><?= ui_e($label) ?></option>
<?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ui-card__body">
                    <p class="ui-meta">Total given</p>
                    <p class="ui-num mt-1 text-3xl font-semibold tracking-tight text-ink" data-portal-total aria-live="polite"><span class="ui-skeleton inline-block h-9 w-40"></span></p>

                    <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" data-portal-breakdown>
<?php foreach ($portalGivingTypes as $key => $label): ?>
                        <div class="ui-card ui-card--sunken px-3 py-2.5<?= $key === 'Other' ? ' col-span-2 sm:col-span-1' : '' ?>">
                            <dt class="ui-meta"><?= ui_e($key === 'Project' ? 'Project' : $label) ?></dt>
                            <dd class="ui-num mt-0.5 font-semibold text-ink" data-portal-type="<?= ui_e($key) ?>">—</dd>
                        </div>
<?php endforeach; ?>
                    </dl>

                    <blockquote class="ui-quote mt-6" data-portal-finance-message hidden></blockquote>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="portal-attendance-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h2 class="ui-card__title" id="portal-attendance-title">Recent attendance</h2>
                        <p class="ui-card__description">Your last five services and events.</p>
                    </div>
                </div>
                <div class="ui-card__body ui-card__body--flush">
                    <div class="ui-table-wrap border-0">
                        <table class="ui-table ui-table--stack">
                            <thead>
                                <tr><th scope="col">Date</th><th scope="col">Service or event</th><th scope="col" class="text-right">Status</th></tr>
                            </thead>
                            <tbody data-portal-attendance>
                                <tr><td colspan="3"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></td></tr>
                                <tr><td colspan="3"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ui-card__footer" data-portal-attendance-message hidden>
                    <blockquote class="ui-quote"></blockquote>
                </div>
            </section>
        </div>

        <div class="ui-stack min-w-0">
            <section class="ui-card" aria-labelledby="portal-prayer-title">
                <div class="ui-card__body">
                    <div class="mb-3 flex items-center gap-3">
                        <span class="ui-list__icon"><?= ui_icon('heart-handshake') ?></span>
                        <h2 class="ui-card__title" id="portal-prayer-title">Zoe Prayer Team</h2>
                    </div>
                    <p class="text-sm text-ink-muted">Need agreement in prayer? Our intercessors are standing by to pray with you.</p>
                    <div class="mt-4">
                        <?= ui_button('Send a prayer request', ['variant' => 'soft', 'block' => true, 'attrs' => ['data-dialog-open' => 'portal-prayer']]) ?>
                    </div>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="portal-testimony-title">
                <div class="ui-card__body">
                    <div class="mb-3 flex items-center gap-3">
                        <span class="ui-list__icon"><?= ui_icon('sparkles') ?></span>
                        <h2 class="ui-card__title" id="portal-testimony-title">Share a testimony</h2>
                    </div>
                    <p class="text-sm text-ink-muted">Has God done something amazing? Share it to encourage the brethren.</p>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <?= ui_button('Testify', ['variant' => 'soft', 'attrs' => ['data-dialog-open' => 'portal-testimony']]) ?>
                        <?= ui_button('Read the wall', ['variant' => 'ghost', 'icon_end' => 'external-link', 'href' => '/testimonies.php', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?>
                    </div>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="portal-more-title">
                <h2 class="sr-only" id="portal-more-title">More for you</h2>
                <ul class="ui-list">
                    <li><a class="ui-list__item" href="/modules/library/index.php"><span class="ui-list__icon"><?= ui_icon('book-open') ?></span><span class="ui-list__main"><span class="ui-list__title">Charis Library</span><span class="ui-list__meta">E-books, prints and study resources</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="/modules/profile/index.php"><span class="ui-list__icon"><?= ui_icon('circle-user-round') ?></span><span class="ui-list__main"><span class="ui-list__title">My profile</span><span class="ui-list__meta">Your details, photo and password</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                </ul>
            </section>

            <section class="ui-card" aria-labelledby="portal-broadcasts-title">
                <div class="ui-card__header ui-card__header--divided">
                    <h2 class="ui-card__title" id="portal-broadcasts-title">Church broadcasts</h2>
                </div>
                <div class="ui-card__body" data-portal-broadcasts>
                    <span class="ui-skeleton ui-skeleton--text w-3/4"></span>
                    <span class="ui-skeleton ui-skeleton--text mt-2 w-1/2"></span>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="portal-events-title">
                <div class="ui-card__header ui-card__header--divided">
                    <h2 class="ui-card__title" id="portal-events-title">Upcoming events</h2>
                </div>
                <ul class="ui-list" data-portal-events>
                    <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></li>
                </ul>
            </section>
        </div>
    </div>
</div>

<?= ui_dialog_start('portal-give', ['title' => 'Record your giving', 'description' => 'For giving you have already made. Add the receipt if you have one.']) ?>
    <form class="ui-form" id="portal-give-form" data-action="portal.give" data-ui-validate novalidate>
        <input type="hidden" name="action" value="submit_contribution">
        <?= ui_field('number', ['name' => 'amount', 'id' => 'portal-amount', 'label' => 'Amount (₦)', 'required' => true, 'inputmode' => 'decimal', 'attrs' => ['min' => '0.01', 'step' => '0.01', 'data-error' => 'Enter the amount you gave, in naira.']]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'contribution_type', 'id' => 'portal-type', 'label' => 'Type', 'required' => true, 'options' => $portalGivingTypes]) ?>
            <?= ui_field('date', ['name' => 'contribution_date', 'id' => 'portal-date', 'label' => 'Date', 'required' => true, 'value' => date('Y-m-d'), 'attrs' => ['max' => date('Y-m-d')]]) ?>
        </div>
        <?= ui_field('file', ['name' => 'receipt', 'id' => 'portal-receipt', 'label' => 'Receipt', 'optional' => true, 'hint' => 'JPG, PNG or PDF, up to 2 MB.', 'attrs' => ['accept' => '.jpg,.jpeg,.png,.pdf']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save record', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'portal-give-form']])
) ?>

<?= ui_dialog_start('portal-prayer', ['title' => 'Send a prayer request', 'description' => 'It goes to the Zoe intercessory team and is kept in confidence.']) ?>
    <form class="ui-form" id="portal-prayer-form" data-action="portal.prayer" data-ui-validate novalidate>
        <input type="hidden" name="action" value="submit_prayer_request">
        <?= ui_field('textarea', ['name' => 'request_text', 'id' => 'portal-prayer-text', 'label' => 'What would you like us to pray about?', 'required' => true, 'attrs' => ['rows' => '5']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send to the Zoe team', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'portal-prayer-form']])
) ?>

<?= ui_dialog_start('portal-testimony', ['title' => "Testify of God's goodness", 'description' => 'Write it, record a voice note, or both.', 'size' => 'lg']) ?>
    <form class="ui-form" id="portal-testimony-form" data-action="portal.testimony" novalidate>
        <input type="hidden" name="action" value="submit_testimony">
        <?= ui_field('select', ['name' => 'privacy_level', 'id' => 'portal-privacy', 'label' => 'Who may see your name', 'options' => [
            'Public' => 'Everyone (show my name)',
            'Anonymous_To_All' => 'No one (anonymous to all)',
            'Pastoral_Only' => 'Pastors only (anonymous on the wall)',
        ]]) ?>
        <?= ui_field('textarea', ['name' => 'content_text', 'id' => 'portal-testimony-text', 'label' => 'Your testimony','placeholder' => 'What has the Lord done for you?', 'attrs' => ['rows' => '5']]) ?>

        <fieldset class="ui-fieldset">
            <legend class="ui-label">Voice note</legend>
            <div class="flex flex-wrap gap-2">
                <?= ui_button('Record', ['variant' => 'secondary', 'icon' => 'mic-vocal', 'attrs' => ['data-action' => 'portal.record', 'data-portal-record' => true]]) ?>
                <?= ui_button('Stop', ['variant' => 'danger', 'attrs' => ['data-action' => 'portal.stop', 'data-portal-stop' => true, 'hidden' => true]]) ?>
                <label class="ui-btn ui-btn--ghost cursor-pointer" for="portal-audio-file"><?= ui_icon('upload') ?><span>Upload audio</span></label>
                <input class="sr-only" type="file" id="portal-audio-file" accept="audio/*" data-action-change="portal.audio-file">
            </div>
            <p class="ui-hint mt-2" data-portal-recording aria-live="polite" hidden><span class="ui-dot ui-dot--danger mr-1.5 animate-pulse"></span>Recording <span class="ui-num" data-portal-timer>00:00</span></p>
            <div class="mt-3 flex items-center gap-2" data-portal-preview hidden>
                <audio class="h-10 min-w-0 flex-1" controls data-portal-audio></audio>
                <?= ui_button('Remove', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'portal.clear-audio']]) ?>
            </div>
            <p class="ui-field-error" data-portal-testimony-error aria-live="polite"></p>
        </fieldset>

        <label class="ui-check">
            <input type="checkbox" name="request_editing" value="1">
            <span>Please tidy up my testimony<span class="ui-check__hint">Our team will correct grammar before it is published.</span></span>
        </label>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send testimony', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'portal-testimony-form']])
) ?>

<?php require_once '../../includes/footer.php'; ?>
