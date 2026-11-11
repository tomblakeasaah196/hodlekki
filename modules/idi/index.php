<?php
// /modules/idi/index.php
$ui = [
    'title' => 'Data Insights',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/idi.js'],
];
require_once '../../includes/header.php';

// 1. Check if logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// 2. Check Leadership Roles
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];
$has_access = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) {
            $has_access = true;
            break;
        }
    }
}

// 3. Check IDI Department Membership (If they aren't leadership)
if (!$has_access) {
    $deptStmt = $pdo->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = 1 AND is_active = 1");
    $deptStmt->execute([$_SESSION['user_id']]);
    if ($deptStmt->fetch()) {
        $has_access = true;
    }
}

// STRICT SECURITY CHECK
if (!$has_access) {
    echo ui_no_access('Data Insights is for the pastors and the IDI team.');
    require_once '../../includes/footer.php';
    exit;
}

/** One chart card: title, what it shows, and a canvas the script draws into. */
function idi_chart_card(string $id, string $title, string $about, string $height = 'h-72'): string
{
    $heights = ['h-64' => 'h-64', 'h-72' => 'h-72', 'h-80' => 'h-80'];

    return '<section class="ui-card">'
        . '<div class="ui-card__header"><div><h3 class="ui-card__title">' . ui_e($title) . '</h3>'
        . '<p class="ui-card__description">' . ui_e($about) . '</p></div></div>'
        . '<div class="ui-card__body"><div class="relative ' . ($heights[$height] ?? 'h-72') . '" data-idi-chart-wrap>'
        . '<canvas id="' . ui_e($id) . '" role="img" aria-label="' . ui_e($title) . '"></canvas></div></div>'
        . '</section>';
}
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'Data Insights',
        'description' => 'How the church is growing and keeping its people, for the period you choose.',
        'actions' => ui_button('Export to Excel', ['icon' => 'download', 'attrs' => ['data-action' => 'idi.export']]),
    ]) ?>

    <form class="ui-card mb-6" data-action="idi.sync" data-ui-validate novalidate>
        <div class="ui-card__body flex flex-wrap items-end gap-3">
            <div class="ui-field min-w-[150px] flex-1 sm:flex-none">
                <label class="ui-label" for="idi-start">From</label>
                <input class="ui-input" type="date" id="idi-start" name="start_date" value="<?= ui_e(date('Y-01-01')) ?>" required>
            </div>
            <div class="ui-field min-w-[150px] flex-1 sm:flex-none">
                <label class="ui-label" for="idi-end">To</label>
                <input class="ui-input" type="date" id="idi-end" name="end_date" value="<?= ui_e(date('Y-m-d')) ?>" required>
            </div>
            <?= ui_button('Update', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'refresh-cw']) ?>
            <p class="ui-meta w-full sm:ml-auto sm:w-auto" data-idi-range aria-live="polite"></p>
        </div>
    </form>

    <div class="ui-grid ui-grid--stats mb-6" data-idi-kpis aria-busy="true">
<?php foreach (['People on the ladder', 'Serving in a department', 'Not reached (Unknown)', 'Back after being away'] as $label): ?>
        <div class="ui-card ui-stat" aria-hidden="true">
            <span class="ui-stat__label"><?= ui_e($label) ?></span>
            <span class="ui-skeleton ui-skeleton--title w-1/3"></span>
        </div>
<?php endforeach; ?>
    </div>

    <?= ui_tabs([
        'congregation' => ['label' => 'Congregation', 'icon' => 'users'],
        'attendance' => ['label' => 'Events', 'icon' => 'calendar-check'],
        'embrace' => ['label' => 'Embrace', 'icon' => 'user-round-plus'],
        'operations' => ['label' => 'Units & Academy', 'icon' => 'building-2'],
        'charis' => ['label' => 'Charis', 'icon' => 'heart-handshake'],
        'pastoral' => ['label' => 'Pastoral', 'icon' => 'messages-square'],
    ], 'congregation', 'Insight areas') ?>

    <div data-idi-error hidden class="mb-6"></div>

    <section id="panel-congregation" data-tab-panel="congregation" class="ui-tab-panel">
        <div class="ui-grid ui-grid--2">
            <?= idi_chart_card('chart-cong-funnel', 'Spiritual journey', 'How many people stand at each step, from first timer to worker.') ?>
            <?= idi_chart_card('chart-cong-status', 'Attendance status', 'Who is coming regularly, who has slowed down, and who has not been reached.') ?>
        </div>
    </section>

    <section id="panel-attendance" data-tab-panel="attendance" class="ui-tab-panel" hidden>
        <?= idi_chart_card('chart-att-trends', 'Registered and actually present', 'For events that have closed: how many registered against how many came, to plan for the next one.', 'h-80') ?>
    </section>

    <section id="panel-embrace" data-tab-panel="embrace" class="ui-tab-panel" hidden>
        <?= idi_chart_card('chart-embrace-status', 'First-timer follow-ups', 'By method: follow-ups still pending against those completed.', 'h-80') ?>
    </section>

    <section id="panel-operations" data-tab-panel="operations" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--2">
            <?= idi_chart_card('chart-ops-depts', 'Workers per department', 'Active workers on each unit\'s roster this ministry year.') ?>
            <?= idi_chart_card('chart-ops-academy', 'Academy pipeline', 'Students at each stage, so the units always have new workers coming.') ?>
        </div>
    </section>

    <section id="panel-charis" data-tab-panel="charis" class="ui-tab-panel" hidden>
        <div class="ui-stack ui-stack--md">
            <section class="ui-card" aria-labelledby="idi-bdays">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h3 class="ui-card__title" id="idi-bdays">Birthdays in the next 30 days</h3>
                        <p class="ui-card__description">For welfare calls and messages.</p>
                    </div>
                </div>
                <div class="ui-card__body ui-card__body--flush" data-idi-bdays>
                    <div class="ui-loading"><span class="ui-spinner"></span><span>Loading…</span></div>
                </div>
            </section>
        </div>
    </section>

    <section id="panel-pastoral" data-tab-panel="pastoral" class="ui-tab-panel" hidden>
        <?= idi_chart_card('chart-pastoral-status', 'Questions and suggestions', 'From the congregation: still waiting for an answer, against answered or acted on.', 'h-80') ?>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
