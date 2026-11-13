<?php
// /modules/our_sermons/index.php
// Our sermons, the members' library (OUR-SERMONS-PLAN.md): every published
// sermon, by list (newest preached first; search; preacher, series and
// service filters) or by calendar (a month grid by the day it was preached).
// The view is a toggle remembered on this device (cookie hod_os_view, set
// here when ?view= is chosen). Rendered on the server, so it reads well as an
// offline copy; our-sermons.js only submits the filters as they change.
// Access: the 'our_sermons' row of the permission matrix (everyone: View).
require_once __DIR__ . '/../../includes/our_sermons/bootstrap.php';

$view = (string) ($_GET['view'] ?? '');
if ($view === 'list' || $view === 'calendar') {
    if (!headers_sent()) {
        setcookie('hod_os_view', $view, ['expires' => time() + 31536000, 'path' => '/modules/our_sermons/', 'samesite' => 'Lax',
            'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    }
} else {
    $view = ($_COOKIE['hod_os_view'] ?? '') === 'calendar' ? 'calendar' : 'list';
}
$ui = [
    'title' => 'Our sermons',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/our-sermons.js'],
];
require_once '../../includes/header.php';
require_can('our_sermons', 'view');

$ready = our_sermons_ready($pdo);
$canManage = can('our_sermons', 'create', 'church');
$filters = [
    'q' => our_sermons_text($_GET['q'] ?? '', 80),
    'preacher' => our_sermons_text($_GET['preacher'] ?? '', 120),
    'series' => our_sermons_text($_GET['series'] ?? '', 120),
    'service' => isset(OUR_SERMONS_SERVICES[(string) ($_GET['service'] ?? '')]) ? (string) $_GET['service'] : '',
];
$filtered = implode('', $filters) !== '';
$today = our_sermons_now()->format('Y-m-d');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : substr($today, 0, 7);
$page = max(1, (int) ($_GET['page'] ?? 1));

/** The page's own address with some parameters changed (empty ones dropped). */
$link = static function (array $change) use ($filters, $view, $month): string {
    $q = array_filter(array_merge($filters, ['view' => $view, 'month' => $view === 'calendar' ? $month : ''], $change),
        static fn ($v) => $v !== '' && $v !== null);
    return '/modules/our_sermons/' . ($q !== [] ? '?' . http_build_query($q) : '');
};

/** One sermon as a card: artwork, title, preacher, date, service, length. */
$card = static function (array $s): string {
    $meta = array_filter([our_sermons_date_label($s['date_preached']), our_sermons_length_label($s['duration_seconds'])]);
    $art = $s['artwork_url']
        ? '<img class="os-card__art" src="' . ui_e($s['artwork_url']) . '" alt="" loading="lazy" decoding="async" width="96" height="96">'
        : '<span class="os-card__art os-card__art--none" aria-hidden="true">' . ui_icon('headphones') . '</span>';
    return '<a class="ui-card os-card" href="' . ui_e(our_sermons_url($s['id'])) . '">' . $art
        . '<span class="os-card__main"><span class="os-card__title">' . ui_e($s['title']) . '</span>'
        . ($s['preacher'] ? '<span class="os-card__line">' . ui_e($s['preacher']) . '</span>' : '')
        . '<span class="os-card__line ui-num">' . ui_e(implode(' · ', $meta)) . '</span>'
        . ($s['service'] ? '<span>' . ui_badge(our_sermons_service_label($s['service']), 'neutral') . '</span>' : '')
        . '</span></a>';
};
?>
<div class="ui-page">
<?php if (!$ready): ?>
    <?= ui_page_header(['title' => 'Our sermons']) ?>
    <?= ui_banner('info', ['title' => 'Almost ready', 'text' => 'Our sermons opens after the next update.']) ?>
<?php else:
    $options = our_sermons_filter_options($pdo);
    $actions = $canManage ? ui_button('Manage sermons', ['variant' => 'secondary', 'icon' => 'settings', 'href' => our_sermons_manage_url()]) : '';
?>
    <?= ui_page_header([
        'title' => 'Our sermons',
        'description' => 'Sermons preached at HOD Lekki.',
        'info' => 'Tap a sermon to listen. Envision adds each one after checking it; you are told when a new one is up.',
        'actions' => $actions,
    ]) ?>

    <form class="os-filters" method="get" action="/modules/our_sermons/" role="search" data-os-filters>
        <div class="ui-segmented" role="group" aria-label="View">
            <button type="submit" name="view" value="list" class="ui-segmented__item" aria-pressed="<?= $view === 'list' ? 'true' : 'false' ?>"><?= ui_icon('list') ?> List</button>
            <button type="submit" name="view" value="calendar" class="ui-segmented__item" aria-pressed="<?= $view === 'calendar' ? 'true' : 'false' ?>"><?= ui_icon('calendar') ?> Calendar</button>
        </div>
        <div class="ui-search os-filters__search">
            <?= ui_icon('search') ?>
            <input class="ui-input" type="search" name="q" value="<?= ui_e($filters['q']) ?>" placeholder="Search sermons" aria-label="Search sermons" maxlength="80">
        </div>
<?php if ($options['preachers'] !== []): ?>
        <select class="ui-select os-filters__select" name="preacher" aria-label="Preacher" data-action-change="os.filter">
            <option value="">All preachers</option>
<?php foreach ($options['preachers'] as $p): ?>
            <option value="<?= ui_e($p) ?>"<?= $filters['preacher'] === $p ? ' selected' : '' ?>><?= ui_e($p) ?></option>
<?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if ($options['series'] !== []): ?>
        <select class="ui-select os-filters__select" name="series" aria-label="Series" data-action-change="os.filter">
            <option value="">All series</option>
<?php foreach ($options['series'] as $p): ?>
            <option value="<?= ui_e($p) ?>"<?= $filters['series'] === $p ? ' selected' : '' ?>><?= ui_e($p) ?></option>
<?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if ($options['services'] !== []): ?>
        <select class="ui-select os-filters__select" name="service" aria-label="Service" data-action-change="os.filter">
            <option value="">All services</option>
<?php foreach ($options['services'] as $key): ?>
            <option value="<?= ui_e($key) ?>"<?= $filters['service'] === $key ? ' selected' : '' ?>><?= ui_e(our_sermons_service_label($key)) ?></option>
<?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if ($view === 'calendar'): ?>
        <input type="hidden" name="month" value="<?= ui_e($month) ?>">
<?php endif; ?>
        <?= ui_button('Search', ['variant' => 'secondary', 'type' => 'submit', 'icon' => 'search', 'class' => 'os-filters__go']) ?>
<?php if ($filtered): ?>
        <?= ui_button('Clear filters', ['variant' => 'ghost', 'href' => '/modules/our_sermons/?view=' . $view]) ?>
<?php endif; ?>
    </form>

<?php if ($view === 'list'):
        $result = our_sermons_search($pdo, $filters, $page);
        $pages = max(1, (int) ceil($result['total'] / OUR_SERMONS_PAGE_SIZE));
        if ($result['rows'] === []): ?>
    <div class="ui-card">
        <?= $filtered
            ? ui_empty(['icon' => 'search', 'title' => 'No sermons match', 'text' => 'Try another word or clear the filters.',
                'actions' => ui_button('Clear filters', ['variant' => 'primary', 'href' => '/modules/our_sermons/?view=list'])])
            : ui_empty(['icon' => 'headphones', 'title' => 'No sermons yet', 'text' => 'New sermons appear here once Envision publishes them.']) ?>
    </div>
<?php else: ?>
    <ul class="os-grid" aria-label="Sermons">
<?php foreach ($result['rows'] as $s): ?>
        <li><?= $card($s) ?></li>
<?php endforeach; ?>
    </ul>
<?php if ($pages > 1): ?>
    <nav class="ui-pagination os-pagination" aria-label="Pages">
        <?= ui_button('Newer', ['variant' => 'secondary', 'icon' => 'chevron-left', 'href' => $page > 1 ? $link(['page' => $page - 1]) : null, 'disabled' => $page <= 1]) ?>
        <span class="ui-meta ui-num">Page <?= $page ?> of <?= $pages ?></span>
        <?= ui_button('Older', ['variant' => 'secondary', 'icon_end' => 'chevron-right', 'href' => $page < $pages ? $link(['page' => $page + 1]) : null, 'disabled' => $page >= $pages]) ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
<?php else:
        $days = our_sermons_month($pdo, $month, $filters);
        $first = new DateTimeImmutable($month . '-01', new DateTimeZone(OUR_SERMONS_TZ));
        $prev = $first->modify('-1 month')->format('Y-m');
        $next = $first->modify('+1 month')->format('Y-m');
        $lead = (int) $first->format('w');            // Sunday first
        $count = (int) $first->format('t');
?>
    <section class="ui-card os-cal" aria-labelledby="os-cal-title">
        <div class="ui-card__header os-cal__head">
            <?= ui_button('Previous month', ['variant' => 'ghost', 'icon' => 'chevron-left', 'icon_only' => true, 'href' => $link(['month' => $prev, 'view' => 'calendar'])]) ?>
            <h2 class="ui-h3" id="os-cal-title"><?= ui_e($first->format('F Y')) ?></h2>
            <?= ui_button('Next month', ['variant' => 'ghost', 'icon' => 'chevron-right', 'icon_only' => true, 'href' => $link(['month' => $next, 'view' => 'calendar'])]) ?>
        </div>
        <div class="os-cal__grid" role="table" aria-labelledby="os-cal-title">
            <div class="os-cal__row" role="row">
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
                <span class="os-cal__dow" role="columnheader"><?= $wd ?></span>
<?php endforeach; ?>
            </div>
<?php
        $cells = array_merge(array_fill(0, $lead, null), range(1, $count));
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        foreach (array_chunk($cells, 7) as $week): ?>
            <div class="os-cal__row" role="row">
<?php foreach ($week as $d):
                if ($d === null): ?>
                <span class="os-cal__cell os-cal__cell--blank" role="cell"></span>
<?php continue; endif;
                $ymd = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                $here = $days[$ymd] ?? []; ?>
                <div class="os-cal__cell<?= $ymd === $today ? ' is-today' : '' ?><?= $here !== [] ? ' has-sermon' : '' ?>" role="cell"<?= $ymd === $today ? ' aria-current="date"' : '' ?>>
                    <span class="os-cal__day ui-num"><?= $d ?></span>
<?php foreach ($here as $s): ?>
                    <a class="os-cal__item" href="<?= ui_e(our_sermons_url($s['id'])) ?>" title="<?= ui_e($s['title']) ?>"><span class="os-cal__dot" aria-hidden="true"></span><span class="os-cal__label"><?= ui_e($s['title']) ?></span></a>
<?php endforeach; ?>
                </div>
<?php endforeach; ?>
            </div>
<?php endforeach; ?>
        </div>
    </section>
<?php if ($days === []): ?>
    <div class="ui-card mt-4">
        <?= ui_empty(['icon' => 'calendar', 'title' => 'Nothing this month', 'text' => 'Try the month before.', 'compact' => true,
            'actions' => ui_button('Previous month', ['variant' => 'secondary', 'icon' => 'chevron-left', 'href' => $link(['month' => $prev, 'view' => 'calendar'])])]) ?>
    </div>
<?php else: ?>
    <ul class="os-grid os-cal__list" aria-label="Sermons this month">
<?php foreach ($days as $list):
            foreach ($list as $s): ?>
        <li><?= $card($s) ?></li>
<?php endforeach;
        endforeach; ?>
    </ul>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
