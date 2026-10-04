<?php
// /modules/special_events/index.php
require_once '../../includes/header.php';

// header.php already bounces an unauthenticated visitor; the module's own
// gate decides who may see the Studio at all (guide §6.2).
require_once __DIR__ . '/../../includes/special_events/bootstrap.php';

$se_user_id = (int) ($_SESSION['user_id'] ?? 0);
$se_access  = se_module_access($pdo, $se_user_id, se_session_roles());

if ($se_access === 'none') {
    ?>
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-10 text-center max-w-xl mx-auto">
        <div class="w-14 h-14 rounded-2xl bg-blue-50 text-hodBlue flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </div>
        <h1 class="text-xl font-display font-bold text-gray-900">Special Events</h1>
        <p class="text-gray-500 mt-2">
            This module belongs to Envision. Ask an Envision HOD or Director to add
            you to the department, or to the crew of a specific event.
        </p>
        <a href="/index.php" class="inline-flex items-center gap-2 mt-6 px-5 py-2.5 rounded-xl bg-hodBlue text-white font-semibold hover:bg-blue-900 transition-colors">
            Back to the dashboard
        </a>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

// Which tabs exist depends on which migrations have run (§9.4), so a tab
// whose tables are missing is never rendered — and neither is one whose UI
// belongs to a later PR.
$se_tabs = [];
foreach (SE_STUDIO_TABS as $se_tabId => $se_tab) {
    if (!in_array($se_tabId, SE_STUDIO_TABS_READY, true)) {
        continue;
    }
    if ($se_tab['requires'] !== null && !se_table_exists($pdo, $se_tab['requires'])) {
        continue;
    }
    $se_tabs[$se_tabId] = $se_tab['label'];
}

$se_boot = [
    'csrf'        => se_csrf_token(),
    'user_id'     => $se_user_id,
    'access'      => $se_access,
    'is_manager'  => $se_access === 'manager',
    'can_create'  => in_array($se_access, ['manager', 'studio_member'], true),
    'tabs'        => $se_tabs,
    'crew_roles'  => SE_CREW_ROLES,
    'asset_roles' => array_keys(SE_ASSET_ROLES),
    'font_pairs'  => SE_FONT_PAIRS,
    'how_heard'   => SE_HOW_HEARD,
    'form_field_types' => SE_FORM_FIELD_TYPES,
    'ai'          => ['available' => se_ai_available(), 'reason' => se_ai_unavailable_reason()],
    'defaults'    => [
        'primary'   => se_setting_get($pdo, 'default_brand_primary', '#1D356A'),
        'secondary' => se_setting_get($pdo, 'default_brand_secondary', '#D11920'),
    ],
    'limits'      => [
        'min_teams'  => SE_MIN_TEAMS,
        'max_teams'  => SE_MAX_TEAMS,
        'max_days'   => SE_MAX_EVENT_DAYS,
        'max_fields' => SE_MAX_FORM_FIELDS,
        'slug_max'   => SE_SLUG_MAX_LENGTH,
    ],
    'open_event'  => isset($_GET['event']) ? (int) $_GET['event'] : null,
    'version'     => SE_MODULE_VERSION,
];
?>

<div class="max-w-7xl mx-auto">
    <div id="se-studio" data-tab="overview">
        <!-- Replaced by @se/studio/main.js. This is what shows while the
             module loads, and what remains if JavaScript fails. -->
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-10">
            <div class="animate-pulse space-y-4">
                <div class="h-8 bg-gray-100 rounded-xl w-1/3"></div>
                <div class="h-4 bg-gray-100 rounded-lg w-1/2"></div>
                <div class="h-40 bg-gray-50 rounded-2xl"></div>
            </div>
            <noscript>
                <p class="text-gray-600 mt-6 text-center">
                    The Special Events Studio needs JavaScript. Please enable it, or
                    try a different browser.
                </p>
            </noscript>
        </div>
    </div>
</div>

<script type="application/json" id="se-studio-boot"><?= se_json_for_html($se_boot) ?></script>

<script type="importmap">
<?= se_json_for_html(se_import_map(false)) ?>
</script>

<script src="/assets/se/vendor/chartjs-4.5.0/chart.umd.min.js"></script>
<script type="module" src="/assets/se/js/studio/main.js"></script>

<?php require_once '../../includes/footer.php'; ?>
