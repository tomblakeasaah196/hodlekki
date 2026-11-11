<?php
// /index.php (Main Entry Point)

// connect.hodlekki.org shows the public Connect page at its root; everything
// else on this site is the members' app behind the sign-in.
require_once __DIR__ . '/includes/site_url.php';
if (is_connect_host()) {
    require __DIR__ . '/connect.php';
    exit;
}

// The home dashboard. Every number comes from /api/dashboard_api.php, asked
// the way the module it summarises asks it (includes/dashboard_metrics.php);
// assets/ui/js/pages/dashboard.js draws the cards and the detail drawer.
$ui = [
    'title' => 'Dashboard',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/dashboard.js'],
];
require_once __DIR__ . '/includes/header.php';

$dashHour = (int) date('G');
$dashGreeting = $dashHour < 12 ? 'Good morning' : ($dashHour < 17 ? 'Good afternoon' : 'Good evening');
$dashName = trim((string) ($_SESSION['first_name'] ?? ''));
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => $dashGreeting . ($dashName !== '' ? ', ' . $dashName : ''),
        'description' => date('l, j F') . ' · how the household is doing, and what needs you today.',
        'actions' => ui_button('Connect QR code', ['icon' => 'qr-code', 'attrs' => ['data-action' => 'dash.qr']]),
    ]) ?>

    <div class="ui-stack">
        <section aria-labelledby="dash-kpis">
            <h2 class="sr-only" id="dash-kpis">Key numbers</h2>
            <div class="ui-grid ui-grid--stats" data-dash-cards aria-busy="true">
<?php for ($i = 0; $i < 4; $i++): ?>
                <div class="ui-card ui-stat" aria-hidden="true">
                    <span class="ui-skeleton ui-skeleton--text w-1/2"></span>
                    <span class="ui-skeleton ui-skeleton--title w-1/3"></span>
                    <span class="ui-skeleton ui-skeleton--text w-2/3"></span>
                </div>
<?php endfor; ?>
            </div>
            <div data-dash-error hidden></div>
        </section>

        <div class="ui-grid ui-grid--sidebar">
            <section class="ui-card" aria-labelledby="dash-notices">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h2 class="ui-card__title" id="dash-notices">Ministry notices</h2>
                        <p class="ui-card__description">Announcements for the coming services.</p>
                    </div>
                    <?= ui_button('Publish a notice', ['size' => 'sm', 'icon' => 'megaphone', 'href' => '/modules/announcements/index.php']) ?>
                </div>
                <div data-dash-notices>
                    <div class="ui-card__body" aria-hidden="true">
                        <span class="ui-skeleton ui-skeleton--title"></span>
                        <span class="ui-skeleton ui-skeleton--text"></span>
                        <span class="ui-skeleton ui-skeleton--text w-2/3"></span>
                    </div>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="dash-tools">
                <div class="ui-card__header ui-card__header--divided">
                    <h2 class="ui-card__title" id="dash-tools">Quick actions</h2>
                </div>
                <ul class="ui-list">
                    <li><a class="ui-list__item" href="/modules/events/index.php"><span class="ui-list__icon"><?= ui_icon('calendar-check') ?></span><span class="ui-list__main"><span class="ui-list__title">Attendance</span><span class="ui-list__meta">Services, check-ins and reports</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="/modules/embrace/index.php"><span class="ui-list__icon"><?= ui_icon('user-round-plus') ?></span><span class="ui-list__main"><span class="ui-list__title">First timers</span><span class="ui-list__meta">Welcome and follow-up</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="/modules/announcements/index.php"><span class="ui-list__icon"><?= ui_icon('megaphone') ?></span><span class="ui-list__main"><span class="ui-list__title">Notices</span><span class="ui-list__meta">Publish to the next services</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                    <li><a class="ui-list__item" href="/modules/pastoral/index.php"><span class="ui-list__icon"><?= ui_icon('messages-square') ?></span><span class="ui-list__main"><span class="ui-list__title">Pastoral desk</span><span class="ui-list__meta">Questions and impressions</span></span><span class="ui-list__end"><?= ui_icon('chevron-right') ?></span></a></li>
                </ul>
            </section>
        </div>
    </div>
</div>

<?= ui_dialog_start('dash-detail', ['title' => 'Details', 'size' => 'drawer']) ?>
    <div data-dash-detail aria-live="polite"></div>
<?= ui_dialog_end(
    ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . '<a class="ui-btn ui-btn--primary" data-dash-detail-link href="#" hidden><span></span>' . ui_icon('chevron-right') . '</a>'
) ?>

<?= ui_dialog_start('dash-qr', ['title' => 'Welcome home', 'description' => 'Scan to open the Connect page and join the Household of David family.', 'size' => 'sm']) ?>
    <div class="flex flex-col items-center gap-4 text-center">
        <div class="rounded-ui-lg border border-line bg-surface p-4" data-dash-qr aria-label="QR code for the Connect page" role="img">
            <span class="ui-skeleton block h-48 w-48"></span>
        </div>
        <p class="ui-meta">“For where two or three gather in my name, there am I with them.” — Matthew 18:20</p>
    </div>
<?= ui_dialog_end(
    ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Open the Connect page', ['variant' => 'primary', 'icon' => 'external-link', 'href' => connect_origin() . '/', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
) ?>

<?= ui_json_script('dash-config', ['connectUrl' => connect_origin() . '/']) ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
