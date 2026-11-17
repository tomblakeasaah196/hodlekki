<?php
// /modules/monitor/index.php
/**
 * System Health: the error and health monitor's screen (docs/monitor.md).
 *
 * Overview · Errors (each with "Copy for AI") · Health checks · Speed (real
 * users, phones and computers) · Uptime (the outside probe) · Settings (alert
 * emails, the morning summary, the live wall). Built on the shared UI
 * (docs/UI.md) in strict mode: no inline script, handler or style; behaviour
 * in assets/ui/js/pages/monitor.js; data from api/monitor_api.php.
 *
 * Access: monitor_user_can() — Super_Admin from the live roles table until
 * the permissions module defines can('monitor.view'). Changing settings needs
 * monitor.manage. The navigation entry (includes/ui/nav.php, key
 * system_health) appears once this file exists.
 *
 * Everything shown that came from an error is shown as text: a browser can
 * send anything, so the script never puts it into the page as HTML.
 */
$ui = [
    'title'   => 'System Health',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/monitor.js'],
    'offline' => false,   // live diagnostics: an old copy would mislead
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/monitor/bootstrap.php';

if (!monitor_user_can($pdo, 'monitor.view')) {
    echo ui_no_access('System Health is for the people who look after the site. If you need it, ask a Super Admin.');
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$ready = monitor_ready($pdo);
$canManage = monitor_user_can($pdo, 'monitor.manage');

$stateTone = ['ok' => 'success', 'warn' => 'warning', 'bad' => 'danger', 'unknown' => 'neutral'];
$stateWord = ['ok' => 'OK', 'warn' => 'Check', 'bad' => 'Problem', 'unknown' => 'Waiting'];

/** "3 min ago" for a Unix time. */
$ago = static fn (?int $ts): string => $ts ? monitor_ago($ts) : 'never';

$snap = $ready ? monitor_wall_snapshot($pdo) : null;
$checks = $ready ? monitor_checks_current($pdo) : [];
$notOk = array_values(array_filter($checks, fn ($c) => $c['state'] !== 'ok'));
$bad = array_values(array_filter($checks, fn ($c) => $c['state'] === 'bad'));
$wall = $ready ? monitor_wall_key($pdo) : null;
// The monitor's own cron job, read from its heartbeat file (no database).
$cronCheck = monitor_check_monitor_cron(monitor_beats()['monitor'] ?? null, time());
$cronLine = monitor_cron_jobs()['monitor']['line'];
$recipients = $ready && $canManage ? monitor_recipients_list($pdo) : [];
$digestHour = $ready ? (int) monitor_state_get($pdo, 'digest_hour', '8') : 8;

/** A 24-bar chart of counts, as SVG (heights are attributes, never style). */
$bars = static function (array $values, string $label): string {
    $max = max(1, ...array_map('intval', $values ?: [0]));
    $n = max(1, count($values));
    $w = 100 / $n;
    $svg = '<svg class="block w-full h-16 text-primary" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="' . ui_e($label) . '">';
    foreach (array_values($values) as $i => $v) {
        $h = $v > 0 ? max(2, 38 * ((int) $v / $max)) : 1;
        $svg .= '<rect x="' . round($i * $w + 0.4, 2) . '" y="' . round(40 - $h, 2) . '" width="' . round(max(0.6, $w - 0.8), 2)
            . '" height="' . round($h, 2) . '" rx="0.6" fill="currentColor"' . ($v > 0 ? '' : ' opacity="0.25"') . '><title>' . (int) $v . '</title></rect>';
    }

    return $svg . '</svg>';
};

$tabs = [
    'overview' => ['label' => 'Overview', 'icon' => 'layout-dashboard'],
    'errors'   => ['label' => 'Errors', 'icon' => 'circle-alert', 'count' => $snap['errors']['open'] ?? 0],
    'health'   => ['label' => 'Health', 'icon' => 'activity', 'count' => count($notOk) ?: null],
    'speed'    => ['label' => 'Speed', 'icon' => 'smartphone'],
    'uptime'   => ['label' => 'Uptime', 'icon' => 'globe'],
];
if ($canManage) {
    $tabs['settings'] = ['label' => 'Settings', 'icon' => 'settings'];
}
foreach ($tabs as $k => $t) {
    if (array_key_exists('count', $t) && empty($t['count'])) {
        unset($tabs[$k]['count']);
    }
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'System Health',
        'description' => 'Errors, health checks and real-user speed for ' . site_host() . ', in one place.',
        'actions' => $ready
            ? ui_button('Scan logs now', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'monitor.scan']])
              . ui_button('Export open errors', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => '/api/monitor_api.php?action=export'])
              . ($wall ? ui_button('Open live wall', ['variant' => 'primary', 'icon' => 'external-link', 'href' => '/modules/monitor/wall#k=' . $wall, 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) : '')
            : '',
    ]) ?>

<?php if (!$ready): ?>
    <?= ui_empty([
        'icon' => 'activity',
        'title' => 'Setting up',
        'text' => 'This page fills in after the next deploy.',
    ]) ?>
<?php else: ?>

    <?php if ($cronCheck['state'] !== 'ok'): ?>
        <?= ui_banner($cronCheck['state'] === 'bad' ? 'danger' : 'warning', [
            'title' => $cronCheck['state'] === 'bad' ? 'The monitor\'s cron job has stopped' : 'One step left: add the monitor\'s cron job',
            'text' => $cronCheck['detail'] . ' In cPanel → Cron Jobs, add this line (every minute):',
            'actions' => '<div class="ui-input-group w-full">'
                . '<label class="sr-only" for="monitor-cron-line">Cron line</label>'
                . '<input class="ui-input font-mono text-xs" id="monitor-cron-line" readonly value="' . ui_e($cronLine) . '">'
                . ui_button('Copy line', ['variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-action' => 'monitor.copy', 'data-copy-target' => 'monitor-cron-line']])
                . '</div>',
        ]) ?>
    <?php endif; ?>

    <?php if ($bad): ?>
        <?= ui_banner('danger', [
            'title' => count($bad) === 1 ? $bad[0]['label'] . ' needs attention' : count($bad) . ' things need attention',
            'text' => count($bad) === 1 ? $bad[0]['detail'] : implode(' · ', array_map(fn ($c) => $c['label'], $bad)),
            'actions' => ui_button('See checks', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'monitor.tab', 'data-tab-target' => 'health']]),
        ]) ?>
    <?php elseif ($checks && !$notOk): ?>
        <?= ui_banner('success', ['title' => 'Everything is working', 'text' => 'Every health check is green. Last checked ' . $ago(monitor_ts($checks[0]['checked_at'] ?? null)) . '.']) ?>
    <?php elseif (!$checks && $cronCheck['state'] === 'ok'): ?>
        <?= ui_banner('info', ['title' => 'Waiting for the first checks', 'text' => 'First results arrive within five minutes.', 'info' => 'The monitor\'s cron job runs the health checks every five minutes. "Run checks" on the Health tab runs them at once.']) ?>
    <?php endif; ?>

    <?= ui_tabs($tabs, 'overview', 'System Health sections') ?>

    <!-- ── Overview ─────────────────────────────────────────────── -->
    <section data-tab-panel="overview" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" class="ui-tab-panel ui-stack ui-stack--md">
        <div class="ui-grid ui-grid--stats">
            <?= ui_stat(['label' => 'Open problems', 'icon' => 'circle-alert', 'value' => number_format((int) $snap['errors']['open']), 'meta' => 'new or being looked at']) ?>
            <?= ui_stat(['label' => 'New in 24 hours', 'icon' => 'sparkles', 'value' => number_format((int) $snap['errors']['new_24h'])]) ?>
            <?= ui_stat(['label' => 'Errors this hour', 'icon' => 'flame', 'value' => number_format((int) $snap['errors']['last_hour'])]) ?>
            <?php $site = $snap['uptime']['site'] ?? null; ?>
            <?= ui_stat(['label' => 'Uptime, 7 days', 'icon' => 'globe', 'value' => $site && $site['pct_7d'] !== null ? $site['pct_7d'] . '%' : '—',
                'meta' => $site ? ($site['up_now'] ? 'up now' : 'DOWN at the last check') : 'no outside report yet']) ?>
        </div>

        <div class="ui-grid ui-grid--2">
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Errors in the last 24 hours</h2></div>
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <?= $bars($snap['errors']['spark_24h'], 'Errors per hour over the last 24 hours') ?>
                    <p class="ui-meta">Each bar is an hour, oldest on the left. <?= ui_info('Warnings, notices and slow pages are not counted here.', 'About this chart') ?></p>
                </div>
            </div>
            <div class="ui-card">
                <div class="ui-card__header"><h2 class="ui-card__title">Needs a look</h2></div>
                <?php if ($notOk): ?>
                    <ul class="ui-list">
                        <?php foreach (array_slice($notOk, 0, 6) as $c): ?>
                            <li class="ui-list__item">
                                <div class="ui-list__main">
                                    <p class="ui-list__title"><?= ui_e($c['label']) ?></p>
                                    <p class="ui-list__meta"><?= ui_e(mb_substr((string) $c['detail'], 0, 160)) ?></p>
                                </div>
                                <div class="ui-list__end"><?= ui_badge($stateWord[$c['state']] ?? $c['state'], $stateTone[$c['state']] ?? 'neutral', ['dot' => true]) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="ui-card__body"><?= ui_empty(['compact' => true, 'icon' => 'circle-check', 'title' => $checks ? 'Nothing to look at' : 'No checks yet', 'text' => $checks ? 'Every health check is green.' : 'The first checks run within five minutes of the cron starting.']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title">Latest open problems</h2>
                <?= ui_button('See all errors', ['variant' => 'ghost', 'size' => 'sm', 'icon_end' => 'arrow-right', 'attrs' => ['data-action' => 'monitor.tab', 'data-tab-target' => 'errors']]) ?>
            </div>
            <?php if (!empty($snap['latest'])): ?>
                <ul class="ui-list">
                    <?php foreach ($snap['latest'] as $g): ?>
                        <li class="ui-list__item">
                            <div class="ui-list__main">
                                <button type="button" class="ui-link ui-list__title text-left" data-action="monitor.open" data-id="<?= (int) $g['id'] ?>"><?= ui_e($g['title']) ?></button>
                                <p class="ui-list__meta"><?= ui_e(monitor_kind_label($g)) ?><?= $g['where'] ? ' · ' . ui_e($g['where']) : '' ?> · <?= (int) $g['count'] ?>× · last <?= ui_e($ago($g['last_seen'])) ?><?= $g['people_24h'] ? ' · ' . (int) $g['people_24h'] . ' ' . ($g['people_24h'] === 1 ? 'person' : 'people') . ' today' : '' ?></p>
                            </div>
                            <div class="ui-list__end">
                                <?= $g['back'] ? ui_badge('Came back', 'danger') : '' ?>
                                <?= ui_badge(ucfirst($g['level']), in_array($g['level'], ['fatal', 'error'], true) ? 'danger' : 'warning') ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ui-card__body"><?= ui_empty(['compact' => true, 'icon' => 'circle-check', 'title' => 'No open problems', 'text' => 'New errors appear here within a minute.']) ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($snap['deploy'])): ?>
            <p class="ui-meta">Running <?= ui_e($snap['deploy']['commit']) ?> “<?= ui_e($snap['deploy']['subject']) ?>”, deployed <?= ui_e($ago($snap['deploy']['at'] ?? null)) ?>.</p>
        <?php endif; ?>
    </section>

    <!-- ── Errors ───────────────────────────────────────────────── -->
    <section data-tab-panel="errors" id="panel-errors" role="tabpanel" aria-labelledby="tab-errors" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <form class="ui-toolbar" data-action="monitor.filter" role="search">
            <div class="ui-toolbar__grow ui-search">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="monitor-q">Search problems</label>
                <input class="ui-input" type="search" id="monitor-q" name="q" placeholder="Message, file or page" data-action-input="monitor.filter" autocomplete="off">
            </div>
            <div class="ui-toolbar__end">
                <label class="sr-only" for="monitor-kind">Kind</label>
                <select class="ui-select w-auto" id="monitor-kind" name="kind" data-action-change="monitor.filter">
                    <option value="">All kinds</option>
                    <option value="server">Server</option>
                    <option value="js">Browser</option>
                    <option value="slow">Slow pages</option>
                    <option value="csp">Content Security Policy</option>
                </select>
                <label class="sr-only" for="monitor-sort">Order</label>
                <select class="ui-select w-auto" id="monitor-sort" name="sort" data-action-change="monitor.filter">
                    <option value="last">Most recent</option>
                    <option value="count">Most frequent</option>
                    <option value="first">Newest problems</option>
                </select>
            </div>
        </form>
        <div class="ui-chips" role="group" aria-label="Status">
            <?php foreach (['open' => 'Open', 'new' => 'New', 'investigating' => 'Being looked at', 'fixed' => 'Fixed', 'ignored' => 'Ignored', 'all' => 'All'] as $k => $l): ?>
                <button type="button" class="ui-chip" data-action="monitor.status-filter" data-status="<?= ui_e($k) ?>" aria-pressed="<?= $k === 'open' ? 'true' : 'false' ?>"><?= ui_e($l) ?></button>
            <?php endforeach; ?>
            <label class="ui-check ml-auto"><input type="checkbox" class="ui-checkbox" id="monitor-quiet" data-action-change="monitor.filter"> Include notices and deprecations</label>
        </div>
        <div class="ui-card">
            <div id="monitor-errors" aria-live="polite" aria-busy="true">
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <div class="ui-skeleton ui-skeleton--text"></div><div class="ui-skeleton ui-skeleton--text"></div><div class="ui-skeleton ui-skeleton--text"></div>
                </div>
            </div>
            <div class="ui-card__footer ui-pagination" id="monitor-errors-pages" hidden>
                <?= ui_button('Previous', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left', 'attrs' => ['data-action' => 'monitor.page', 'data-dir' => '-1']]) ?>
                <span class="ui-pagination__pages ui-meta" id="monitor-errors-count"></span>
                <?= ui_button('Next', ['variant' => 'ghost', 'size' => 'sm', 'icon_end' => 'chevron-right', 'attrs' => ['data-action' => 'monitor.page', 'data-dir' => '1']]) ?>
            </div>
        </div>
    </section>

    <!-- ── Health ───────────────────────────────────────────────── -->
    <section data-tab-panel="health" id="panel-health" role="tabpanel" aria-labelledby="tab-health" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="ui-toolbar">
            <p class="ui-toolbar__grow ui-meta"><?= $checks ? 'Checked ' . ui_e($ago(monitor_ts($checks[0]['checked_at'] ?? null))) . '. The cron checks every five minutes; the slower ones (email sign-in, SMS wallet, disk, real-user speed) hourly.' : 'No checks yet.' ?></p>
            <?= ui_button('Run checks', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'monitor.checks']]) ?>
        </div>
        <?php
        $groups = [];
        foreach ($checks as $c) {
            $groups[$c['grp']][] = $c;
        }
        ?>
        <?php if (!$groups): ?>
            <?= ui_empty(['icon' => 'activity', 'title' => 'No checks yet', 'text' => '"Run checks" runs them once from here.']) ?>
        <?php endif; ?>
        <?php foreach ($groups as $grp => $list): ?>
            <div class="ui-card">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title"><?= ui_e($grp) ?></h2></div>
                <ul class="ui-list">
                    <?php foreach ($list as $c): ?>
                        <li class="ui-list__item">
                            <div class="ui-list__main">
                                <p class="ui-list__title"><?= ui_e($c['label']) ?></p>
                                <p class="ui-list__meta"><?= ui_e($c['detail']) ?></p>
                                <?php if ($c['fix'] && $c['state'] !== 'ok'): ?>
                                    <p class="ui-hint">What to do: <?= ui_e($c['fix']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="ui-list__end ui-stack ui-stack--sm items-end">
                                <?= ui_badge($stateWord[$c['state']] ?? $c['state'], $stateTone[$c['state']] ?? 'neutral', ['dot' => true]) ?>
                                <?php // When it was last measured; how long it has been in this state is the tooltip ?>
                                <span class="ui-meta" title="<?= ui_e(($stateWord[$c['state']] ?? $c['state']) . ' since ' . monitor_fmt(monitor_ts($c['since']), 'j M, H:i')) ?>">checked <?= ui_e($ago(monitor_ts($c['checked_at'] ?? null))) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- ── Speed ────────────────────────────────────────────────── -->
    <section data-tab-panel="speed" id="panel-speed" role="tabpanel" aria-labelledby="tab-speed" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="ui-toolbar">
            <div class="ui-segmented" role="group" aria-label="Device">
                <button type="button" class="ui-segmented__item" data-action="monitor.speed" data-device="phone" aria-pressed="true"><?= ui_icon('smartphone') ?> Phones</button>
                <button type="button" class="ui-segmented__item" data-action="monitor.speed" data-device="desktop" aria-pressed="false"><?= ui_icon('layout-dashboard') ?> Computers</button>
            </div>
            <div class="ui-segmented" role="group" aria-label="Period">
                <?php foreach ([1 => 'Today', 7 => '7 days', 28 => '28 days'] as $d => $l): ?>
                    <button type="button" class="ui-segmented__item" data-action="monitor.speed" data-days="<?= $d ?>" aria-pressed="<?= $d === 7 ? 'true' : 'false' ?>"><?= ui_e($l) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="monitor-speed" aria-live="polite">
            <div class="ui-grid ui-grid--stats"><div class="ui-card ui-skeleton ui-skeleton--block"></div><div class="ui-card ui-skeleton ui-skeleton--block"></div><div class="ui-card ui-skeleton ui-skeleton--block"></div><div class="ui-card ui-skeleton ui-skeleton--block"></div></div>
        </div>
        <p class="ui-meta">Measured in members' own browsers. <?= ui_info('As Google does (Core Web Vitals): the figure is what 75% of visits were at least as fast as. Good: loading under 2.5 s, response to a tap under 200 ms, layout shift under 0.10, first byte under 0.8 s.', 'About page speed') ?></p>
    </section>

    <!-- ── Uptime ───────────────────────────────────────────────── -->
    <section data-tab-panel="uptime" id="panel-uptime" role="tabpanel" aria-labelledby="tab-uptime" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div id="monitor-uptime" aria-live="polite">
            <div class="ui-card ui-skeleton ui-skeleton--block"></div>
        </div>
        <p class="ui-meta">Checked from outside, about every hour. <?= ui_info('From GitHub machines (the outside probe): both addresses, the certificates, the mail records and the origin lock. A server that is down cannot receive the report, so a gap in the bars is itself a warning.', 'About outside checks') ?></p>
    </section>

    <?php if ($canManage): ?>
    <!-- ── Settings ─────────────────────────────────────────────── -->
    <section data-tab-panel="settings" id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <div class="ui-grid ui-grid--2">
            <div class="ui-card">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h2 class="ui-card__title">Alert emails</h2>
                        <p class="ui-card__description">New errors, red checks and the morning summary. <?= ui_info('An address gets nothing until its owner clicks the confirmation link. Super Admins always get the bell and phone notifications.', 'About alerts') ?></p>
                    </div>
                </div>
                <ul class="ui-list" id="monitor-recipients">
                    <?php foreach ($recipients as $r): ?>
                        <?php if ($r['status'] === 'removed') { continue; } ?>
                        <li class="ui-list__item">
                            <div class="ui-list__icon"><?= ui_icon('mail') ?></div>
                            <div class="ui-list__main">
                                <p class="ui-list__title"><?= ui_e($r['email']) ?></p>
                                <p class="ui-list__meta"><?= $r['status'] === 'active'
                                    ? 'Confirmed ' . ui_e(substr((string) $r['confirmed_at'], 0, 10)) . ($r['last_sent_at'] ? ' · last alert ' . ui_e($ago(monitor_ts($r['last_sent_at']))) : '')
                                    : 'Waiting for the confirmation link to be clicked' ?></p>
                            </div>
                            <div class="ui-list__end">
                                <?= ui_badge($r['status'] === 'active' ? 'Active' : 'Pending', $r['status'] === 'active' ? 'success' : 'warning') ?>
                                <?= ui_button('Remove ' . $r['email'], ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true, 'attrs' => ['data-action' => 'monitor.recipient-remove', 'data-id' => (int) $r['id'], 'data-email' => $r['email']]]) ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!array_filter($recipients, fn ($r) => $r['status'] !== 'removed')): ?>
                    <div class="ui-card__body"><?= ui_empty(['compact' => true, 'icon' => 'mail', 'title' => 'No alert emails yet', 'text' => 'Add the address that should hear about problems first.']) ?></div>
                <?php endif; ?>
                <form class="ui-card__footer ui-form" data-action="monitor.recipient-add" data-ui-validate novalidate>
                    <?= ui_field('email', ['name' => 'email', 'label' => 'Add an address', 'required' => true, 'placeholder' => 'name@example.com', 'hint' => 'A confirmation link is sent to it first.']) ?>
                    <div class="ui-form-actions"><?= ui_button('Send confirmation link', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send']) ?></div>
                </form>
            </div>

            <div class="ui-stack ui-stack--md">
                <div class="ui-card">
                    <div class="ui-card__header"><h2 class="ui-card__title">Morning summary</h2></div>
                    <form class="ui-card__body ui-form" data-action="monitor.settings">
                        <div class="ui-field">
                            <label class="ui-label" for="monitor-digest-hour">Send it at (Lagos time)</label>
                            <select class="ui-select" id="monitor-digest-hour" name="digest_hour">
                                <?php for ($h = 5; $h <= 11; $h++): ?>
                                    <option value="<?= $h ?>"<?= $h === $digestHour ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                <?php endfor; ?>
                            </select>
                            <p class="ui-hint">Health checks not green, new and frequent problems, and yesterday's deploys.</p>
                        </div>
                        <div class="ui-form-actions"><?= ui_button('Save time', ['variant' => 'secondary', 'type' => 'submit']) ?></div>
                    </form>
                </div>

                <div class="ui-card">
                    <div class="ui-card__header"><h2 class="ui-card__title">Live wall</h2></div>
                    <div class="ui-card__body ui-stack ui-stack--sm">
                        <p class="ui-meta">A full-screen view for a TV. <?= ui_info('Health, errors as they happen, uptime and speed. It needs no sign-in; the link itself is the key, so share it only with the team.', 'About the live wall') ?></p>
                        <div class="ui-input-group">
                            <label class="sr-only" for="monitor-wall-link">Live wall link</label>
                            <input class="ui-input" id="monitor-wall-link" readonly value="<?= ui_e(site_url('/modules/monitor/wall#k=' . $wall)) ?>">
                            <?= ui_button('Copy link', ['variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-action' => 'monitor.copy-wall']]) ?>
                        </div>
                        <div><?= ui_button('New link', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'monitor.wall-rotate']]) ?></div>
                    </div>
                </div>

                <div class="ui-card">
                    <div class="ui-card__header"><h2 class="ui-card__title">Test the alerts</h2></div>
                    <div class="ui-card__body ui-stack ui-stack--sm">
                        <p class="ui-meta">To every confirmed address and Super Admin.</p>
                        <div><?= ui_button('Send test', ['variant' => 'secondary', 'icon' => 'bell-ring', 'attrs' => ['data-action' => 'monitor.test-alert']]) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php if ($ready): ?>
<?= ui_dialog_start('monitor-error', ['title' => 'Problem', 'size' => 'drawer']) ?>
    <div class="ui-stack ui-stack--md" id="monitor-error-body" aria-live="polite">
        <div class="ui-skeleton ui-skeleton--title"></div>
        <div class="ui-skeleton ui-skeleton--text"></div>
        <div class="ui-skeleton ui-skeleton--block"></div>
    </div>
<?= ui_dialog_end(
    ui_button('Copy for AI', ['variant' => 'primary', 'icon' => 'copy', 'attrs' => ['data-action' => 'monitor.copy-brief']])
    . ui_button('Download brief', ['variant' => 'secondary', 'icon' => 'download', 'attrs' => ['data-action' => 'monitor.download-brief']])
) ?>
<?= ui_json_script('monitor-boot', [
    'api'       => '/api/monitor_api.php',
    'canManage' => $canManage,
    'states'    => ['tone' => $stateTone, 'word' => $stateWord],
]) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
