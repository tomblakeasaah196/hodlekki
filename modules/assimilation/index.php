<?php
// /modules/assimilation/index.php
require_once '../../includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}

require_once __DIR__ . '/../../includes/assimilation_helpers.php';

$assim_user_id  = (int) $_SESSION['user_id'];
$assim_manager  = false;
$assim_on_team  = false;
try {
    $assim_manager = assim_is_manager($pdo, $assim_user_id, $_SESSION['active_role'] ?? '');
    $assim_on_team = assim_is_team_member($pdo, $assim_user_id);
} catch (PDOException $e) {
    error_log('Assimilation module gate: ' . $e->getMessage());
}

// Server-side gate — the sidebar link is hidden too, but never rely on that.
if (!$assim_manager && !$assim_on_team) {
    echo '<div class="max-w-xl mx-auto bg-white p-10 rounded-3xl border border-gray-100 shadow-sm text-center">'
        . '<h2 class="text-xl font-display font-bold text-gray-900">Assimilation</h2>'
        . '<p class="text-gray-500 mt-2">This module is for pastors, department Directors and HODs, and the Assimilation team. '
        . 'Ask an Assimilation leader to add you to the team.</p></div>';
    require_once '../../includes/footer.php';
    exit;
}

$assim_overdue_days = ASSIM_OVERDUE_DAYS;
try {
    $assim_overdue_days = assim_overdue_days($pdo);
} catch (PDOException $e) {
    error_log('Assimilation overdue days: ' . $e->getMessage());
}

$assim_guide_html = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/', $assim_guide_html, $assim_toc, PREG_SET_ORDER);

// The volunteer guide is shown here read-only; managers edit it in settings.
$assim_volunteer_guide_html = '';
try {
    $assim_volunteer_guide_html = reach_markdown(assim_volunteer_guide($pdo));
} catch (Throwable $e) {
    error_log('Assimilation volunteer guide: ' . $e->getMessage());
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">

    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-emerald-50/80 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-emerald-700 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            </div>
            <div class="md:max-w-[75%] lg:max-w-lg">
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Assimilation</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Find the people who have drifted, send someone after them, and walk them home.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <?php if ($assim_manager): ?>
            <button type="button" onclick="openSettingsModal()" aria-label="Assimilation settings" title="Assimilation settings" class="shrink-0 w-11 h-11 rounded-xl bg-white border border-gray-200 hover:border-emerald-300 text-gray-600 hover:text-emerald-700 flex items-center justify-center transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
            <?php endif; ?>
            <a href="/assimilation.php" target="_blank" rel="noopener" class="flex-1 md:flex-none bg-emerald-700 hover:bg-emerald-900 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-emerald-900/20 flex justify-center items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                Volunteer page
            </a>
        </div>
    </div>

    <div id="celebrationBar" class="hidden"></div>

    <div role="tablist" aria-label="Assimilation sections" id="assimTabs" class="flex bg-gray-100 p-1.5 rounded-2xl w-full animate-fade-in-up overflow-x-auto" style="animation-delay: 0.1s;">
        <?php
        $assim_tabs = $assim_manager
            ? [['find', 'Find people'], ['followup', 'Follow-up'], ['team', 'Team'], ['analytics', 'Analytics'], ['howto', 'How to Use']]
            : [['followup', 'Follow-up'], ['team', 'Team'], ['howto', 'How to Use']];
        foreach ($assim_tabs as $i => [$key, $label]):
            $on = $i === 0;
        ?>
        <button type="button" role="tab" id="tabBtn-<?= $key ?>" aria-controls="view-<?= $key ?>" aria-selected="<?= $on ? 'true' : 'false' ?>" tabindex="<?= $on ? '0' : '-1' ?>" onclick="switchTab('<?= $key ?>')" class="flex-1 min-w-[130px] py-2.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 <?= $on ? 'bg-white text-emerald-800 shadow-sm' : 'text-gray-500 hover:text-gray-900' ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($assim_manager): ?>
    <!-- ================= TAB 1 — FIND PEOPLE ================= -->
    <div id="view-find" role="tabpanel" aria-labelledby="tabBtn-find" tabindex="0" class="focus:outline-none animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">

        <!-- ============ BUILD-A-LIST HERO ============ -->
        <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-800 via-emerald-700 to-emerald-900 text-white shadow-lg shadow-emerald-900/25">
            <div class="absolute -top-16 -right-16 w-64 h-64 bg-emerald-500/20 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
            <div class="absolute -bottom-20 -left-10 w-56 h-56 bg-amber-300/10 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>

            <div class="relative z-10 p-6 md:p-8 flex flex-col sm:flex-row items-start sm:items-center gap-6">
                <div class="shrink-0 mx-auto sm:mx-0" aria-hidden="true">
                    <svg class="w-24 h-24 md:w-28 md:h-28" viewBox="0 0 120 120" fill="none">
                        <circle cx="60" cy="60" r="54" fill="rgba(255,255,255,0.08)"/>
                        <circle cx="60" cy="60" r="42" fill="rgba(255,255,255,0.07)"/>
                        <circle cx="60" cy="60" r="30" fill="rgba(255,255,255,0.06)"/>
                        <path d="M48 66 60 55l12 11v12a2 2 0 0 1-2 2H50a2 2 0 0 1-2-2V66Z" fill="#FCD34D"/>
                        <path d="M44 67.5 60 53l16 14.5" stroke="#FDE68A" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="56" y="70" width="8" height="10" rx="1.5" fill="#B45309"/>
                        <circle cx="60" cy="62.5" r="2.2" fill="#B45309"/>
                        <circle cx="26" cy="88" r="5" fill="#FCA5A5"/>
                        <circle cx="94" cy="86" r="5" fill="#93C5FD"/>
                        <circle cx="100" cy="34" r="5" fill="#6EE7B7"/>
                        <path d="M31 86c8-2 14-6 17-12M89 83c-6-4-12-6-18-6M96 40c-5 6-12 10-20 12" stroke="rgba(255,255,255,0.55)" stroke-width="2" stroke-dasharray="3 4" stroke-linecap="round"/>
                        <circle cx="60" cy="60" r="52" stroke="rgba(255,255,255,0.25)" stroke-width="1.5" stroke-dasharray="4 6"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0 text-center sm:text-left">
                    <h3 class="font-display font-bold text-2xl md:text-3xl tracking-tight">Build an AWOL watchlist</h3>
                    <p class="text-emerald-100/90 text-sm md:text-base mt-1.5 font-medium max-w-md">A friendly 3-choice wizard. You say who shows up and who goes after them &mdash; we keep watch from there.</p>
                    <div class="flex flex-wrap justify-center sm:justify-start gap-x-4 gap-y-1.5 mt-3 text-[11px] font-bold uppercase tracking-wider text-emerald-200/80">
                        <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg> We keep watch</span>
                        <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg> Volunteers act</span>
                        <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> You hear first</span>
                    </div>
                </div>
                <button type="button" onclick="openBuilder()" class="w-full sm:w-auto shrink-0 min-h-[56px] bg-white text-emerald-800 hover:bg-emerald-50 active:scale-[0.98] px-7 py-4 rounded-2xl font-display font-bold text-lg shadow-xl shadow-emerald-950/30 transition-all flex items-center justify-center gap-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-emerald-800">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"/></svg>
                    Build a List
                </button>
            </div>
        </section>

        <!-- ============ TOOLBAR ============ -->
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openWatchlists()" class="min-h-[48px] px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                Watchlists <span id="wlCount" class="text-gray-400"></span>
            </button>
            <button type="button" id="advToggle" onclick="toggleAdvanced()" aria-expanded="false" aria-controls="advPanel" class="min-h-[48px] px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                Advanced filters
                <svg id="advChevron" class="w-4 h-4 text-gray-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <button type="button" onclick="openExportModal()" class="ml-auto min-h-[48px] px-4 py-2.5 rounded-xl border border-blue-200 bg-white text-sm font-bold text-blue-900 hover:border-red-300 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-800" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 3.5h5.5L19 9v10.5a1 1 0 01-1 1H6a1 1 0 01-1-1v-15a1 1 0 011-1H8zm5 0V9h6M8 13h8M8 16h8M8 10h1"/></svg>
                Download Excel
            </button>
        </div>

        <!-- ============ ADVANCED FILTERS (collapsed by default) ============ -->
        <div id="advPanel" class="hidden">
        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 md:p-6 space-y-5">
            <div class="flex flex-wrap items-end gap-x-3 gap-y-4">
                <h3 class="w-full text-sm font-bold text-gray-900">Who counts as drifted?</h3>
                <div class="flex flex-wrap items-center gap-2 text-gray-800 font-semibold">
                    <span>Fewer than</span>
                    <label for="ruleMax" class="sr-only">Number of services</label>
                    <input type="number" id="ruleMax" min="1" max="200" value="3" class="w-20 px-3 py-2.5 border border-gray-200 rounded-xl text-center font-bold focus:border-emerald-500 outline-none">
                    <span>services in the past</span>
                    <label for="ruleWindow" class="sr-only">Window length</label>
                    <input type="number" id="ruleWindow" min="1" max="104" value="2" class="w-20 px-3 py-2.5 border border-gray-200 rounded-xl text-center font-bold focus:border-emerald-500 outline-none">
                    <label for="ruleUnit" class="sr-only">Window unit</label>
                    <select id="ruleUnit" class="px-3 py-2.5 border border-gray-200 rounded-xl font-bold bg-white focus:border-emerald-500 outline-none">
                        <option value="days">days</option>
                        <option value="weeks">weeks</option>
                        <option value="months" selected>months</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-2 lg:ml-auto" role="group" aria-label="Common rules">
                    <button type="button" onclick="applyPreset(1,1,'months')" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-600 hover:border-emerald-300">&lt;1 in a month</button>
                    <button type="button" onclick="applyPreset(3,2,'months')" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-600 hover:border-emerald-300">&lt;3 in 2 months</button>
                    <button type="button" onclick="applyPreset(5,3,'months')" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-600 hover:border-emerald-300">&lt;5 in 3 months</button>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-4 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <input id="fSearch" type="search" placeholder="Search name or phone" aria-label="Search name or phone" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
                    <select id="fDept" aria-label="Department" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none"><option value="0">Any department</option></select>
                    <select id="fRegion" aria-label="Region" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none"><option value="0">Any region</option></select>
                    <select id="fCase" aria-label="Follow-up state" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none">
                        <option value="">Assigned or not</option>
                        <option value="no_case">Nobody on them yet</option>
                        <option value="open_case">Already being followed up</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <select id="fGender" aria-label="Gender" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none">
                        <option value="">Any gender</option><option>Male</option><option>Female</option>
                    </select>
                    <select id="fAge" aria-label="Age group" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none"><option value="">Any age</option></select>
                    <label class="flex flex-col text-[10px] font-bold text-gray-400 uppercase tracking-wider">Last attended before
                        <input type="date" id="fBefore" class="mt-1 w-full px-4 py-2 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
                    </label>
                    <label class="flex flex-col text-[10px] font-bold text-gray-400 uppercase tracking-wider">Last attended after
                        <input type="date" id="fAfter" class="mt-1 w-full px-4 py-2 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div id="fStatusChips" class="flex flex-wrap gap-2"></div>
                    <label class="sm:ml-auto inline-flex items-center gap-2 text-xs font-bold text-gray-600 cursor-pointer select-none min-h-[44px]">
                        <input type="checkbox" id="fEver" checked class="w-4 h-4 accent-emerald-700"> Only people who have attended before
                    </label>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-4 flex flex-wrap items-center gap-3">
                <p id="ruleSummary" class="text-sm text-gray-500 font-medium min-w-0 flex-1">&mdash;</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="openSaveWatchlist()" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Save as watchlist</button>
                    <button type="button" onclick="runFind()" class="px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-900 text-white text-sm font-bold shadow-lg shadow-emerald-900/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Find people</button>
                </div>
            </div>
        </section>
        </div>

        <div id="bulkBar" class="hidden sticky top-4 z-30 bg-gray-900 text-white rounded-2xl shadow-2xl px-5 py-3 flex flex-wrap items-center gap-3">
            <p class="font-bold text-sm"><span id="selCount">0</span> selected</p>
            <button type="button" onclick="clearSelection()" class="text-xs font-semibold text-white/70 hover:text-white underline underline-offset-4">Clear</button>
            <div class="flex flex-wrap items-center gap-2 ml-auto">
                <label for="bulkAssignee" class="sr-only">Assign to</label>
                <select id="bulkAssignee" class="px-3 py-2 rounded-xl text-sm font-semibold text-gray-900 bg-white outline-none">
                    <option value="0">Leave unclaimed (the pool)</option>
                </select>
                <button type="button" onclick="bulkAssign()" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-xl text-sm font-bold">Send someone after them</button>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <p id="findTotal" class="text-sm font-bold text-gray-700"></p>
            <div class="ml-auto flex items-center gap-2">
                <label for="findSort" class="text-xs font-bold text-gray-400 uppercase tracking-wider">Sort</label>
                <select id="findSort" onchange="runFind()" class="px-3 py-2 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none">
                    <option value="drift">Away the longest</option>
                    <option value="fewest">Fewest services</option>
                    <option value="recent">Most recently seen</option>
                    <option value="name">Name</option>
                </select>
            </div>
        </div>

        <div id="findList" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 min-h-[200px]"></div>
        <div class="text-center"><button type="button" id="findMore" onclick="runFind(false)" class="hidden bg-white border border-gray-200 hover:border-emerald-300 text-gray-700 px-6 py-2.5 rounded-xl font-bold text-sm">Load more</button></div>
    </div>
    <?php endif; ?>

    <!-- ================= TAB 2 — FOLLOW-UP ================= -->
    <div id="view-followup" role="tabpanel" aria-labelledby="tabBtn-followup" tabindex="0" class="focus:outline-none <?= $assim_manager ? 'hidden' : '' ?> animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">
        <button type="button" id="overdueWidget" onclick="toggleOverdue()" class="hidden w-full text-left bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center gap-4 hover:bg-red-100 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
            <span class="w-10 h-10 rounded-xl bg-red-600 text-white flex items-center justify-center shrink-0" aria-hidden="true">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="min-w-0">
                <span class="block font-bold text-red-800"><span id="overdueCount">0</span> assigned more than <?= (int) $assim_overdue_days ?> days ago with nobody calling yet.</span>
                <span id="overdueHint" class="block text-xs text-red-600 mt-0.5">Tap to show only these.</span>
            </span>
        </button>

        <div id="fuSubTabs" class="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Follow-up stage"></div>

        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <input id="cSearch" type="search" placeholder="Search name or phone" aria-label="Search name or phone" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
                <select id="cAssignee" aria-label="Volunteer" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none">
                    <option value="">Anyone</option><option value="unassigned">Unclaimed</option><option value="me">Mine</option>
                </select>
                <select id="cWatchlist" aria-label="Watchlist" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none"><option value="0">Any watchlist</option></select>
            </div>
        </div>

        <div id="caseList" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 min-h-[200px]"></div>
        <div class="text-center"><button type="button" id="caseMore" onclick="loadCases(false)" class="hidden bg-white border border-gray-200 hover:border-emerald-300 text-gray-700 px-6 py-2.5 rounded-xl font-bold text-sm">Load more</button></div>
    </div>

    <!-- ================= TAB 3 — TEAM ================= -->
    <div id="view-team" role="tabpanel" aria-labelledby="tabBtn-team" tabindex="0" class="focus:outline-none hidden animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-gray-900 text-lg">The Assimilation team</h3>
                <p class="text-sm text-gray-500">Anyone in the congregation can be added &mdash; not only workers.</p>
            </div>
            <?php if ($assim_manager): ?>
            <button type="button" onclick="openPicker()" class="ml-auto bg-emerald-700 hover:bg-emerald-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-emerald-900/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Add volunteers</button>
            <?php endif; ?>
        </div>
        <div id="teamGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 min-h-[160px]"></div>
    </div>

    <?php if ($assim_manager): ?>
    <!-- ================= TAB 4 — ANALYTICS ================= -->
    <div id="view-analytics" role="tabpanel" aria-labelledby="tabBtn-analytics" tabindex="0" class="focus:outline-none hidden animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">
        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 flex flex-col lg:flex-row lg:items-center gap-3">
            <div id="anPresets" class="flex flex-wrap gap-2" role="group" aria-label="Date range"></div>
            <div id="anCustom" class="hidden flex items-center gap-2">
                <input type="date" id="anFrom" aria-label="From date" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:border-emerald-500 outline-none">
                <span class="text-gray-400 text-sm">to</span>
                <input type="date" id="anTo" aria-label="To date" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:border-emerald-500 outline-none">
            </div>
            <button type="button" id="anPdfBtn" onclick="generatePdf()" class="lg:ml-auto bg-emerald-700 hover:bg-emerald-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-emerald-900/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Monthly PDF report</button>
        </div>
        <div id="anBody"></div>
    </div>
    <?php endif; ?>

    <!-- ================= TAB 5 — HOW TO USE ================= -->
    <div id="view-howto" role="tabpanel" aria-labelledby="tabBtn-howto" tabindex="0" class="focus:outline-none hidden animate-fade-in-up" style="animation-delay: 0.2s;">
        <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-5 items-start">
            <nav aria-label="Guide sections" class="hidden lg:block sticky top-24 bg-white rounded-3xl border border-gray-100 shadow-sm p-4 space-y-1">
                <?php foreach ($assim_toc as $h): ?>
                    <a href="#<?= htmlspecialchars($h[1]) ?>" class="block px-3 py-2 rounded-xl text-sm font-semibold text-gray-600 hover:bg-emerald-50 hover:text-emerald-800"><?= strip_tags($h[2]) ?></a>
                <?php endforeach; ?>
                <a href="#volunteer-guide" class="block px-3 py-2 rounded-xl text-sm font-semibold text-gray-600 hover:bg-emerald-50 hover:text-emerald-800">How to follow up with love</a>
            </nav>
            <div class="space-y-5 max-w-3xl">
                <article class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 md:p-10 space-y-4">
                    <?= $assim_guide_html ?: '<p class="text-gray-500">The guide could not be loaded.</p>' ?>
                </article>
                <?php if ($assim_volunteer_guide_html !== ''): ?>
                <article id="volunteer-guide" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 md:p-10 space-y-4 scroll-mt-24">
                    <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-gray-100">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-widest">The volunteer guide</p>
                            <h2 class="text-xl font-display font-bold text-gray-900 mt-1">How to follow up with love</h2>
                            <p class="text-sm text-gray-500 mt-1">Shown to every volunteer on the public page<?= $assim_manager ? '. Edit it under the settings gear.' : '.' ?></p>
                        </div>
                        <?php if ($assim_manager): ?>
                        <button type="button" onclick="openSettingsModal('guide')" class="shrink-0 px-4 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Edit this guide</button>
                        <?php endif; ?>
                    </div>
                    <div class="space-y-4"><?= $assim_volunteer_guide_html ?></div>
                </article>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- DRAWER                                                           -->
<!-- ================================================================ -->
<div id="caseDrawer" class="fixed inset-0 z-[9998] hidden" role="dialog" aria-modal="true" aria-labelledby="drawerName">
    <div data-drawer-backdrop onclick="closeDrawer()" class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm opacity-0 transition-opacity duration-300"></div>
    <aside data-drawer-panel class="absolute bg-white shadow-2xl flex flex-col transition-transform duration-300 inset-x-0 bottom-0 max-h-[92vh] rounded-t-3xl translate-y-full md:inset-y-0 md:left-auto md:right-0 md:w-[520px] md:max-h-none md:rounded-none md:rounded-l-3xl md:translate-y-0 md:translate-x-full">
        <div class="flex items-start justify-between gap-3 p-6 border-b border-gray-100 shrink-0">
            <div class="min-w-0">
                <h3 id="drawerName" class="text-xl font-display font-bold text-gray-900 truncate">&mdash;</h3>
                <p id="drawerSub" class="text-sm text-gray-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="closeDrawer()" aria-label="Close" class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="drawerBody" class="flex-1 overflow-y-auto p-6 space-y-6"></div>
    </aside>
</div>

<!-- ================================================================ -->
<!-- MODALS                                                           -->
<!-- ================================================================ -->
<?php if ($assim_manager): ?>
<div id="pickerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="pickerTitle">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 shrink-0 space-y-3">
            <div class="flex justify-between items-center">
                <h3 id="pickerTitle" class="text-lg font-display font-bold text-gray-900">Add volunteers</h3>
                <button type="button" onclick="closeModal('pickerModal')" aria-label="Close" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-xs text-gray-500">Anyone in the congregation can join the team.</p>
            <input id="pickerSearch" type="search" placeholder="Search name or phone" aria-label="Search the congregation" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
        </div>
        <div id="pickerList" class="overflow-y-auto flex-1 p-4 space-y-2"></div>
        <div class="p-4 border-t border-gray-100 shrink-0 flex gap-3">
            <button type="button" onclick="closeModal('pickerModal')" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-bold">Cancel</button>
            <button type="button" onclick="addPicked()" class="flex-1 bg-emerald-700 hover:bg-emerald-900 text-white px-6 py-3 rounded-xl font-bold">Add <span id="pickCount">0</span></button>
        </div>
    </div>
</div>

<div id="watchlistModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="wlTitle">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center shrink-0">
            <div>
                <h3 id="wlTitle" class="text-lg font-display font-bold text-gray-900">Watchlists</h3>
                <p class="text-xs text-gray-500 mt-0.5">A saved rule. Each morning the team is told who has newly drifted into it.</p>
            </div>
            <button type="button" onclick="closeModal('watchlistModal')" aria-label="Close" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="wlList" class="overflow-y-auto flex-1 p-6 space-y-3"></div>
    </div>
</div>

<div id="saveWlModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="saveWlTitle">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 id="saveWlTitle" class="text-lg font-display font-bold text-gray-900">Save as watchlist</h3>
            <button type="button" onclick="closeModal('saveWlModal')" aria-label="Close" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form id="saveWlForm" class="p-6 space-y-4">
            <div>
                <label for="wlName" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Name</label>
                <input type="text" id="wlName" maxlength="120" required placeholder="e.g. Workers — fewer than 3 in 2 months" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-semibold focus:border-emerald-500 outline-none">
            </div>
            <p id="wlRuleEcho" class="text-sm text-gray-500 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3"></p>
            <label class="flex items-center justify-between gap-3 text-sm font-semibold text-gray-700 cursor-pointer">
                Send the team a daily digest
                <input type="checkbox" id="wlNotify" checked class="w-5 h-5 accent-emerald-700">
            </label>
            <label class="flex items-center justify-between gap-3 text-sm font-semibold text-gray-700 cursor-pointer">
                <span>Open to every volunteer
                    <span class="block text-[11px] font-medium text-gray-400 mt-0.5">New matches land on their phones automatically. Whoever picks someone first takes them.</span>
                </span>
                <input type="checkbox" id="wlOpen" class="w-5 h-5 accent-emerald-700 shrink-0">
            </label>
            <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-900 text-white py-3 rounded-xl font-bold">Save watchlist</button>
        </form>
    </div>
</div>

<div id="settingsModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center shrink-0">
            <h3 id="settingsTitle" class="text-lg font-display font-bold text-gray-900">Assimilation settings</h3>
            <button type="button" onclick="closeModal('settingsModal')" aria-label="Close" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form id="settingsForm" class="overflow-y-auto p-6 space-y-7">
            <section class="space-y-2">
                <h4 class="font-bold text-gray-900">Overdue</h4>
                <label class="flex items-center justify-between gap-3 text-sm text-gray-700">Flag a person as overdue after
                    <span class="flex items-center gap-2"><input type="number" id="setOverdueDays" name="overdue_days" min="1" max="90" required class="w-20 px-3 py-2 border border-gray-200 rounded-xl text-sm font-bold text-center focus:border-emerald-500 outline-none"> days</span>
                </label>
                <p class="text-xs text-gray-500">Counted from when they were assigned to a volunteer with no contact logged since.</p>
            </section>
            <section class="space-y-2">
                <h4 class="font-bold text-gray-900">The unclaimed pool</h4>
                <label class="flex items-center justify-between gap-3 text-sm text-gray-700 cursor-pointer">Let volunteers claim people themselves
                    <input type="checkbox" id="setSelfClaim" name="allow_self_claim" value="1" class="w-5 h-5 accent-emerald-700">
                </label>
                <p class="text-xs text-gray-500">When this is off, only you can hand people to a volunteer.</p>
            </section>
            <section class="space-y-2">
                <h4 class="font-bold text-gray-900">Default rule for a new watchlist</h4>
                <div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-800">
                    <span>Fewer than</span>
                    <input type="number" id="setRuleMax" name="max_services" min="1" max="200" class="w-16 px-2 py-2 border border-gray-200 rounded-xl text-center font-bold focus:border-emerald-500 outline-none" aria-label="Default number of services">
                    <span>in</span>
                    <input type="number" id="setRuleWindow" name="window_value" min="1" max="104" class="w-16 px-2 py-2 border border-gray-200 rounded-xl text-center font-bold focus:border-emerald-500 outline-none" aria-label="Default window length">
                    <select id="setRuleUnit" name="window_unit" class="px-3 py-2 border border-gray-200 rounded-xl font-bold bg-white focus:border-emerald-500 outline-none" aria-label="Default window unit">
                        <option value="days">days</option><option value="weeks">weeks</option><option value="months">months</option>
                    </select>
                </div>
                <label class="flex items-center justify-between gap-3 text-sm text-gray-700 cursor-pointer pt-1">Only people who have attended before
                    <input type="checkbox" id="setRuleEver" name="ever_attended" value="1" class="w-5 h-5 accent-emerald-700">
                </label>
            </section>
            <section class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="font-bold text-gray-900">Volunteer guide</h4>
                    <button type="button" onclick="restoreGuide()" class="text-xs font-bold text-emerald-700 hover:text-emerald-900 min-h-[44px]">Restore default</button>
                </div>
                <p class="text-xs text-gray-500">&ldquo;How to follow up with love&rdquo; &mdash; shown to every volunteer on the public page and in How to Use. Use ## for headings, - for lists, &gt; for scripture, **bold**.</p>
                <textarea id="setGuide" name="guide" rows="14" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-xs font-mono focus:border-emerald-500 outline-none"></textarea>
            </section>
            <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-900 text-white py-3 rounded-xl font-bold">Save settings</button>
        </form>
    </div>
</div>

<!-- ================================================================ -->
<!-- BUILD-A-LIST WIZARD — full-screen sheet on mobile, card on desktop -->
<!-- ================================================================ -->
<style>
@keyframes bwIn { from { opacity: 0; transform: translateY(16px) scale(.99); } to { opacity: 1; transform: none; } }
.bw-step-on { animation: bwIn .32s ease both; }
@keyframes bwFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
.bw-float { animation: bwFloat 3.4s ease-in-out infinite; }
@keyframes bwPulse { 0%, 100% { opacity: .25; } 50% { opacity: 1; } }
.bw-count-dot { animation: bwPulse .9s ease-in-out infinite; }
.bw-choice { transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease, transform .12s ease; }
.bw-choice:active { transform: scale(.985); }
</style>
<div id="bwModal" class="fixed inset-0 w-screen h-screen bg-gray-900/85 backdrop-blur-md hidden z-[9999] flex items-stretch justify-center md:items-center md:p-6 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="bwHeading">
    <div class="bg-white w-full h-full min-h-[480px] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300 md:h-auto md:max-h-[92vh] md:max-w-2xl md:rounded-[2rem] md:shadow-2xl">

        <!-- header -->
        <div class="shrink-0 px-4 md:px-6 pt-4 pb-3 border-b border-gray-100 flex items-center gap-3">
            <button type="button" onclick="closeModal('bwModal')" aria-label="Close the builder" class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div id="bwDots" class="flex-1 flex justify-center items-center gap-1.5" role="tablist" aria-label="Builder progress"></div>
            <span id="bwStepNum" class="shrink-0 text-[11px] font-bold text-gray-400 uppercase tracking-wider w-10 text-right">1/6</span>
        </div>

        <!-- body -->
        <div id="bwBody" class="flex-1 overflow-y-auto overscroll-contain px-5 md:px-8 py-6">

            <!-- STEP 0 — intro -->
            <section data-wstep="0" class="bw-step space-y-6 text-center">
                <div class="bw-float mx-auto w-56 md:w-64" aria-hidden="true">
                    <svg viewBox="0 0 260 190" fill="none" class="w-full h-auto">
                        <ellipse cx="130" cy="96" rx="108" ry="80" fill="#ECFDF5"/>
                        <circle cx="130" cy="96" r="66" fill="#D1FAE5"/>
                        <path d="M104 104l26-22 26 22v30a4 4 0 01-4 4h-44a4 4 0 01-4-4v-30Z" fill="#047857"/>
                        <path d="M98 106l32-27 32 27" stroke="#065F46" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="123" y="114" width="14" height="24" rx="3" fill="#FCD34D"/>
                        <path d="M130 99c2-4 8-3.4 8 .6 0 3-4 5.4-8 8.4-4-3-8-5.4-8-8.4 0-4 6-4.6 8-.6Z" fill="#F87171"/>
                        <circle cx="34" cy="146" r="9" fill="#FCA5A5"/>
                        <circle cx="226" cy="140" r="9" fill="#93C5FD"/>
                        <circle cx="224" cy="50" r="9" fill="#C4B5FD"/>
                        <path d="M43 141c22-4 44-14 60-29" stroke="#10B981" stroke-width="2.5" stroke-dasharray="1 7" stroke-linecap="round"/>
                        <path d="M217 135c-20-2-40-9-57-20" stroke="#10B981" stroke-width="2.5" stroke-dasharray="1 7" stroke-linecap="round"/>
                        <path d="M215 46c-16 14-40 24-62 28" stroke="#10B981" stroke-width="2.5" stroke-dasharray="1 7" stroke-linecap="round"/>
                        <g transform="rotate(-6 58 58)">
                            <rect x="26" y="38" width="54" height="42" rx="9" fill="#ffffff" stroke="#A7F3D0" stroke-width="2"/>
                            <rect x="34" y="48" width="26" height="5" rx="2.5" fill="#A7F3D0"/>
                            <rect x="34" y="58" width="34" height="5" rx="2.5" fill="#D1FAE5"/>
                            <rect x="34" y="68" width="18" height="5" rx="2.5" fill="#D1FAE5"/>
                            <circle cx="68" cy="45" r="8" fill="#10B981"/>
                            <path d="m64.8 45 2.2 2.2 3.8-4.2" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </g>
                    </svg>
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-bold text-emerald-700 uppercase tracking-[0.2em]">The watchlist builder</p>
                    <h3 id="bwHeading" class="font-display font-bold text-2xl md:text-3xl text-gray-900 tracking-tight">Let&rsquo;s build an AWOL watchlist</h3>
                    <p class="text-gray-500 text-sm md:text-base max-w-sm mx-auto">You choose <strong class="text-gray-700">who shows up</strong> on the list and <strong class="text-gray-700">who goes after them</strong>. Three quick choices.</p>
                </div>
                <div class="grid grid-cols-3 gap-2 max-w-md mx-auto text-[11px] font-bold text-gray-500">
                    <span class="bg-gray-50 border border-gray-100 rounded-2xl px-2 py-3"><span class="text-lg block mb-1" aria-hidden="true">👀</span>We keep watch</span>
                    <span class="bg-gray-50 border border-gray-100 rounded-2xl px-2 py-3"><span class="text-lg block mb-1" aria-hidden="true">📲</span>Volunteers act</span>
                    <span class="bg-gray-50 border border-gray-100 rounded-2xl px-2 py-3"><span class="text-lg block mb-1" aria-hidden="true">🏠</span>People come home</span>
                </div>
            </section>

            <!-- STEP 1 — how long away -->
            <section data-wstep="1" class="bw-step hidden space-y-5">
                <div class="text-center space-y-1.5">
                    <h3 tabindex="-1" class="font-display font-bold text-xl md:text-2xl text-gray-900">How long have they been away?</h3>
                    <p class="text-sm text-gray-500">Pick the heart of the list.</p>
                </div>
                <div class="space-y-3 max-w-md mx-auto" role="radiogroup" aria-label="How long away">
                    <button type="button" data-away="nos" onclick="bwPickAway('nos')" role="radio" aria-checked="false" class="bw-away bw-choice w-full min-h-[64px] flex items-center gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span>
                        <span class="flex-1 min-w-0"><span class="block font-bold text-gray-900">Just missed a few Sundays</span><span class="block text-xs text-gray-500 mt-0.5">Not seen in about a month</span></span>
                        <span class="bw-check w-6 h-6 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                    <button type="button" data-away="slip" onclick="bwPickAway('slip')" role="radio" aria-checked="false" class="bw-away bw-choice w-full min-h-[64px] flex items-center gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-sky-100 text-sky-600 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.5 19a4.5 4.5 0 000-9 6 6 0 00-11.6 1.6A4 4 0 006 19h11.5z"/></svg></span>
                        <span class="flex-1 min-w-0"><span class="block font-bold text-gray-900">Slipping away</span><span class="block text-xs text-gray-500 mt-0.5">Barely seen in 2 months</span></span>
                        <span class="bw-check w-6 h-6 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                    <button type="button" data-away="gone" onclick="bwPickAway('gone')" role="radio" aria-checked="false" class="bw-away bw-choice w-full min-h-[64px] flex items-center gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.5 16a4.5 4.5 0 000-9 6 6 0 00-11.6 1.6A4 4 0 006 16h11.5zM8 21l.9-2m2.2 2 .9-2m2.2 2 .9-2"/></svg></span>
                        <span class="flex-1 min-w-0"><span class="block font-bold text-gray-900">Gone a while</span><span class="block text-xs text-gray-500 mt-0.5">Almost no check-ins in 3 months</span></span>
                        <span class="bw-check w-6 h-6 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                    <button type="button" data-away="custom" onclick="bwPickAway('custom')" role="radio" aria-checked="false" class="bw-away bw-choice w-full min-h-[64px] flex items-center gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-gray-100 text-gray-600 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 21v-7m0-4V3m8 18v-9m0-4V3m8 18v-5m0-4V3M1 14h6M9 8h6m2 8h6"/></svg></span>
                        <span class="flex-1 min-w-0"><span class="block font-bold text-gray-900">Set my own</span><span class="block text-xs text-gray-500 mt-0.5">Choose the exact numbers</span></span>
                        <span class="bw-check w-6 h-6 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                </div>
                <div id="bwCustomRow" class="hidden max-w-md mx-auto bg-gray-50 border border-gray-100 rounded-2xl p-4">
                    <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-3 text-sm font-semibold text-gray-700">
                        <span>Fewer than</span>
                        <span class="inline-flex items-center gap-1">
                            <button type="button" onclick="bwStepCustom('max', -1)" aria-label="Fewer services" class="w-10 h-10 rounded-xl bg-white border border-gray-200 font-bold text-gray-600 hover:border-emerald-300">&minus;</button>
                            <span id="bwCustomMax" class="w-10 text-center font-display font-bold text-lg text-emerald-800">3</span>
                            <button type="button" onclick="bwStepCustom('max', 1)" aria-label="More services" class="w-10 h-10 rounded-xl bg-white border border-gray-200 font-bold text-gray-600 hover:border-emerald-300">+</button>
                        </span>
                        <span>services in</span>
                        <span class="inline-flex items-center gap-1">
                            <button type="button" onclick="bwStepCustom('win', -1)" aria-label="Shorter window" class="w-10 h-10 rounded-xl bg-white border border-gray-200 font-bold text-gray-600 hover:border-emerald-300">&minus;</button>
                            <span id="bwCustomWin" class="w-10 text-center font-display font-bold text-lg text-emerald-800">2</span>
                            <button type="button" onclick="bwStepCustom('win', 1)" aria-label="Longer window" class="w-10 h-10 rounded-xl bg-white border border-gray-200 font-bold text-gray-600 hover:border-emerald-300">+</button>
                        </span>
                        <select id="bwCustomUnit" onchange="bwCustomUnitChanged()" aria-label="Window unit" class="px-3 py-2.5 border border-gray-200 rounded-xl font-bold bg-white focus:border-emerald-500 outline-none">
                            <option value="days">days</option><option value="weeks">weeks</option><option value="months" selected>months</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- STEP 2 — who -->
            <section data-wstep="2" class="bw-step hidden space-y-5">
                <div class="text-center space-y-1.5">
                    <h3 tabindex="-1" class="font-display font-bold text-xl md:text-2xl text-gray-900">Who should we keep watch on?</h3>
                    <p class="text-sm text-gray-500">Everyone below is in &mdash; or tap to narrow it.</p>
                </div>
                <div class="max-w-lg mx-auto space-y-3">
                    <button type="button" id="bwEveryone" onclick="bwPickEveryone()" aria-pressed="true" class="bw-choice w-full min-h-[56px] flex items-center gap-3 rounded-2xl border-2 border-gray-100 bg-white p-3.5 text-left hover:border-emerald-200">
                        <span class="w-11 h-11 shrink-0 rounded-2xl bg-emerald-100 flex items-center justify-center text-2xl" aria-hidden="true">⛪</span>
                        <span class="flex-1 min-w-0"><span class="block font-bold text-gray-900">Everyone in the house</span><span class="block text-xs text-gray-500 mt-0.5">First-timers to pastors &mdash; nobody filtered out</span></span>
                        <span class="bw-check w-6 h-6 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                    <div id="bwStatusWrap" class="grid grid-cols-2 min-[420px]:grid-cols-3 sm:grid-cols-4 gap-2"></div>
                </div>
            </section>

            <!-- STEP 3 — fine tune -->
            <section data-wstep="3" class="bw-step hidden space-y-5">
                <div class="text-center space-y-1.5">
                    <h3 tabindex="-1" class="font-display font-bold text-xl md:text-2xl text-gray-900">Fine-tune, if you like</h3>
                    <p class="text-sm text-gray-500">All optional &mdash; skip straight ahead if it already feels right.</p>
                </div>
                <div class="max-w-md mx-auto space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider">Department
                            <select id="bwDept" onchange="bwFtChanged()" class="mt-1.5 w-full min-h-[48px] px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none normal-case tracking-normal"></select>
                        </label>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider">Region
                            <select id="bwRegion" onchange="bwFtChanged()" class="mt-1.5 w-full min-h-[48px] px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:border-emerald-500 outline-none normal-case tracking-normal"></select>
                        </label>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Gender</p>
                        <div id="bwGenderWrap" class="flex gap-2" role="group" aria-label="Gender"></div>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Age</p>
                        <div id="bwAgeWrap" class="flex flex-wrap gap-2" role="group" aria-label="Age band"></div>
                    </div>
                    <label class="flex items-center justify-between gap-3 bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3.5 cursor-pointer">
                        <span class="text-sm font-semibold text-gray-700">Only people who have attended before</span>
                        <span class="relative inline-flex shrink-0">
                            <input type="checkbox" id="bwEver" checked onchange="bwFtChanged()" class="peer sr-only">
                            <span class="block w-11 h-6 rounded-full bg-gray-200 peer-checked:bg-emerald-600 transition-colors"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                </div>
            </section>

            <!-- STEP 4 — who works it -->
            <section data-wstep="4" class="bw-step hidden space-y-5">
                <div class="text-center space-y-1.5">
                    <h3 tabindex="-1" class="font-display font-bold text-xl md:text-2xl text-gray-900">Who goes after them?</h3>
                    <p class="text-sm text-gray-500">The big one &mdash; how the calling gets done.</p>
                </div>
                <div class="space-y-3 max-w-md mx-auto" role="radiogroup" aria-label="Who works this list">
                    <button type="button" id="bwModeOpen" onclick="bwPickMode('open')" role="radio" aria-checked="true" class="bw-choice w-full flex items-start gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-emerald-100 flex items-center justify-center text-2xl" aria-hidden="true">🙌</span>
                        <span class="flex-1 min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-gray-900">Open to every volunteer</span>
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-700 text-white rounded-full px-2 py-0.5">Recommended</span>
                            </span>
                            <span class="block text-xs text-gray-500 mt-1 leading-relaxed">Everyone on this list lands on every volunteer&rsquo;s phone. Whoever picks them first takes them &mdash; they leave the shared list &mdash; and any volunteer can leave notes.</span>
                        </span>
                        <span class="bw-check w-6 h-6 mt-1 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                    <button type="button" id="bwModeManaged" onclick="bwPickMode('managed')" role="radio" aria-checked="false" class="bw-choice w-full flex items-start gap-4 rounded-2xl border-2 border-gray-100 bg-white p-4 text-left hover:border-emerald-200">
                        <span class="w-12 h-12 shrink-0 rounded-2xl bg-hodBlue/10 flex items-center justify-center text-2xl" aria-hidden="true">🧭</span>
                        <span class="flex-1 min-w-0">
                            <span class="font-bold text-gray-900">Managers assign</span>
                            <span class="block text-xs text-gray-500 mt-1 leading-relaxed">Stay a private manager list. You hand each person to a specific volunteer, one by one.</span>
                        </span>
                        <span class="bw-check w-6 h-6 mt-1 shrink-0 rounded-full border-2 border-gray-200 flex items-center justify-center transition-colors" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="max-w-md mx-auto space-y-2">
                    <label class="flex items-center justify-between gap-3 bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3.5 cursor-pointer">
                        <span class="text-sm font-semibold text-gray-700"><span aria-hidden="true">🔔</span> <span id="bwDigestLbl">Nudge the whole team when someone new drifts in</span></span>
                        <span class="relative inline-flex shrink-0">
                            <input type="checkbox" id="bwDigest" checked onchange="bwDigestChanged()" class="peer sr-only">
                            <span class="block w-11 h-6 rounded-full bg-gray-200 peer-checked:bg-emerald-600 transition-colors"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                    <p id="bwSelfClaimNote" class="hidden text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-100 rounded-2xl px-4 py-3">💡 Team self-pick is off in settings right now. Launching an open list switches it on, so volunteers can pick people themselves.</p>
                </div>
            </section>

            <!-- STEP 5 — name & launch -->
            <section data-wstep="5" class="bw-step hidden space-y-5">
                <div class="text-center space-y-1.5">
                    <h3 tabindex="-1" class="font-display font-bold text-xl md:text-2xl text-gray-900">Name it. Launch it.</h3>
                    <p class="text-sm text-gray-500">One last look before we start watching.</p>
                </div>
                <div class="max-w-md mx-auto space-y-4">
                    <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 rounded-3xl p-6 text-center" aria-live="polite">
                        <p id="bwCount" class="font-display font-bold text-4xl text-emerald-800 leading-none">
                            <span class="bw-count-dot">•</span><span class="bw-count-dot" style="animation-delay:.15s">•</span><span class="bw-count-dot" style="animation-delay:.3s">•</span>
                        </p>
                        <p id="bwCountSub" class="text-xs font-bold text-emerald-700/80 uppercase tracking-wider mt-2">Counting quietly…</p>
                    </div>
                    <div>
                        <label for="bwName" class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">List name</label>
                        <input type="text" id="bwName" maxlength="120" oninput="bw.nameTouched = true" placeholder="e.g. Slipping away · Workers" class="mt-1.5 w-full min-h-[52px] px-4 py-3 border border-gray-200 rounded-2xl font-semibold focus:border-emerald-500 outline-none">
                    </div>
                    <div id="bwRecap" class="flex flex-wrap gap-2"></div>
                    <p class="text-xs text-gray-400 text-center">People who start attending again drop off the list by themselves.</p>
                </div>
            </section>
        </div>

        <!-- footer -->
        <div class="shrink-0 border-t border-gray-100 bg-white px-5 md:px-8 pt-3.5 flex gap-3" style="padding-bottom: max(0.875rem, env(safe-area-inset-bottom));">
            <button type="button" id="bwBack" onclick="bwShow(bw.step - 1)" class="invisible shrink-0 min-h-[52px] px-5 rounded-2xl border border-gray-200 bg-white font-bold text-gray-600 hover:border-emerald-300 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                Back
            </button>
            <button type="button" id="bwNext" onclick="bwForward()" class="flex-1 min-h-[52px] rounded-2xl bg-emerald-700 hover:bg-emerald-800 active:scale-[0.99] text-white font-bold text-base shadow-lg shadow-emerald-900/20 transition-all flex items-center justify-center gap-2 disabled:opacity-60 disabled:pointer-events-none">
                Let&rsquo;s go
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($assim_manager): ?>
<div id="exportModal" class="fixed inset-0 w-screen h-screen bg-gray-950/70 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="exportTitle">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="relative overflow-hidden bg-hodBlue px-6 py-6 text-white">
            <div class="absolute -right-10 -top-12 h-40 w-40 rounded-full bg-red-600/20 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-blue-200">Household of David · Lekki Centre</p>
                    <h3 id="exportTitle" class="mt-1 text-xl font-display font-bold">Choose your Excel report</h3>
                    <p class="mt-1 text-sm text-blue-100/80">Both options use the people and filters currently shown in Find people.</p>
                </div>
                <button type="button" onclick="closeModal('exportModal')" aria-label="Close" class="shrink-0 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="space-y-4 p-5 sm:p-6">
            <button type="button" onclick="exportExcel('general')" class="group w-full rounded-2xl border border-blue-100 bg-white p-4 text-left transition hover:border-blue-300 hover:bg-blue-50/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-700">
                <span class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-900 group-hover:bg-blue-100" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 4.75A1.75 1.75 0 016.75 3h7.5L19 7.75v11.5A1.75 1.75 0 0117.25 21h-10.5A1.75 1.75 0 015 19.25V4.75zM14 3v5h5M8 12h8M8 15.5h8"/></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold text-gray-900">General Assimilation Register</span>
                        <span class="mt-1 block text-sm leading-relaxed text-gray-500">Full name, clean +234 phone number, gender and last service attended. Made for a simple, shareable list.</span>
                    </span>
                    <svg class="mt-1 h-5 w-5 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-blue-800" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </button>
            <button type="button" onclick="exportExcel('detailed')" class="group w-full rounded-2xl border border-red-100 bg-white p-4 text-left transition hover:border-red-300 hover:bg-red-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">
                <span class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700 group-hover:bg-red-100" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5.75A1.75 1.75 0 015.75 4h12.5A1.75 1.75 0 0120 5.75v12.5A1.75 1.75 0 0118.25 20H5.75A1.75 1.75 0 014 18.25V5.75zM4 9h16M9 9v11m5-11v11M7 6.5h.01M10 6.5h.01"/></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold text-gray-900">Full Assimilation Report</span>
                        <span class="mt-1 block text-sm leading-relaxed text-gray-500">Everything in the general register, plus spiritual status, departments, region, attendance totals, case status and assigned volunteer.</span>
                    </span>
                    <svg class="mt-1 h-5 w-5 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-red-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </button>
            <p class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-xs leading-relaxed text-gray-500">The workbook uses Household of David colours and logo. Phone numbers are cleaned to +234; “2025” marks people with no last-service date in records that begin in 2026.</p>
            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button type="button" onclick="closeModal('exportModal')" class="min-h-[44px] rounded-xl px-5 font-bold text-gray-600 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-400">Cancel</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Working…</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    const API_URL = '/api/assimilation_api.php';
    const IS_MANAGER = <?= $assim_manager ? 'true' : 'false' ?>;
    const OVERDUE_DAYS = <?= (int) $assim_overdue_days ?>;

    let BOOT = null;
    const state = {
        find:  { page: 1, selected: new Set(), ready: false },
        cases: { sub: 'to_call', page: 1, overdue: false, ready: false, caseId: null },
        team:  { ready: false, rows: [], picked: new Set() },
        an:    { ready: false, preset: 'this_month', charts: {} }
    };

    /* ============================ SHARED UI ============================ */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    const icon = (d, cls = 'w-4 h-4') =>
        `<svg class="${cls}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${d}"/></svg>`;
    const pill = (text, cls) => `<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold ${cls}">${escapeHtml(text)}</span>`;

    function showToast(msg, type = 'success') {
        Toastify({
            text: msg, gravity: 'top', position: 'center', duration: 3200,
            style: {
                background: type === 'success' ? '#047857' : (type === 'info' ? '#1D356A' : '#D11920'),
                borderRadius: '12px', fontWeight: 'bold', boxShadow: '0 10px 25px rgba(0,0,0,0.3)'
            }
        }).showToast();
    }
    function lockScreen() {
        const b = document.getElementById('globalActionBlocker');
        b.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => b.classList.remove('opacity-0'), 10);
    }
    function unlockScreen() {
        const b = document.getElementById('globalActionBlocker');
        b.classList.add('opacity-0');
        setTimeout(() => {
            b.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) document.body.style.overflow = '';
        }, 300);
    }
    function openModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        document.body.appendChild(m);
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            m.children[0].classList.remove('scale-95');
        });
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95');
        setTimeout(() => {
            m.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) document.body.style.overflow = '';
        }, 300);
    }

    function switchTab(tabId) {
        $('[role="tabpanel"]').addClass('hidden');
        $('#assimTabs [role="tab"]')
            .removeClass('bg-white text-emerald-800 shadow-sm').addClass('text-gray-500 hover:text-gray-900')
            .attr({ 'aria-selected': 'false', tabindex: '-1' });
        $(`#view-${tabId}`).removeClass('hidden');
        $(`#tabBtn-${tabId}`)
            .removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-emerald-800 shadow-sm')
            .attr({ 'aria-selected': 'true', tabindex: '0' });
        const btn = document.getElementById(`tabBtn-${tabId}`);
        if (btn) btn.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        if (tabId === 'find') initFind();
        if (tabId === 'followup') initCases();
        if (tabId === 'team') initTeam();
        if (tabId === 'analytics') initAnalytics();
    }

    $(document).on('keydown', '#assimTabs [role="tab"]', function(ev) {
        const tabs = $('#assimTabs [role="tab"]');
        const i = tabs.index(this);
        const next = { ArrowRight: (i + 1) % tabs.length, ArrowLeft: (i - 1 + tabs.length) % tabs.length, Home: 0, End: tabs.length - 1 }[ev.key];
        if (next === undefined) return;
        ev.preventDefault();
        tabs.eq(next).trigger('focus').trigger('click');
    });

    /* ============================ FORMATTERS ============================ */
    const label = s => String(s || '').replace(/_/g, ' ');
    const STATUS_WORDS = {
        To_Call: 'To call', Reached: 'Reached', Promised: 'Promised to come', Returned_Home: 'Returned home',
        Unreachable: 'Could not reach them', Not_Interested: 'Not interested for now',
        Relocated: 'Has relocated', Attends_Elsewhere: 'Attends another church'
    };
    const STATUS_STYLE = {
        To_Call: 'bg-blue-100 text-blue-800', Reached: 'bg-amber-100 text-amber-800',
        Promised: 'bg-indigo-100 text-indigo-800', Returned_Home: 'bg-emerald-100 text-emerald-800',
        Unreachable: 'bg-gray-100 text-gray-700', Not_Interested: 'bg-gray-100 text-gray-700',
        Relocated: 'bg-gray-100 text-gray-700', Attends_Elsewhere: 'bg-gray-100 text-gray-700'
    };
    const CHANNEL_WORDS = { Call: 'Call', WhatsApp: 'WhatsApp', SMS: 'SMS', Visit: 'Visit', At_Church: 'At church' };
    const OUTCOME_WORDS = {
        Spoke_With_Them: 'We spoke', Promised_To_Come: 'Promised to come', No_Answer: 'No answer',
        Wrong_Number: 'Wrong number', Not_Interested: 'Not interested', Relocated: 'Has relocated',
        Attends_Elsewhere: 'Attends elsewhere', Asked_For_No_Contact: 'Asked for no contact'
    };
    const word = s => STATUS_WORDS[s] || label(s);

    function parseDate(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
    function relTime(s) {
        const d = parseDate(s);
        if (!d || isNaN(d)) return '';
        const mins = Math.round((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins}m ago`;
        if (mins < 1440) return `${Math.round(mins / 60)}h ago`;
        if (mins < 10080) return `${Math.round(mins / 1440)}d ago`;
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }
    function niceDate(s) {
        const d = parseDate(s);
        return d && !isNaN(d) ? d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }) : '';
    }
    const pad2 = n => String(n).padStart(2, '0');
    const ymd = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

    // Twelve thin bars, one per month; grey means they were not in the house.
    function sparkline(trend, cls = '') {
        if (!trend || !trend.length) return '';
        const max = Math.max(1, ...trend);
        return `<span class="inline-flex items-end gap-[2px] h-6 ${cls}" role="img" aria-label="Attendance by month over the last year: ${trend.join(', ')}">`
            + trend.map(v => `<span class="w-[4px] rounded-sm ${v === 0 ? 'bg-gray-200' : 'bg-emerald-700'}" style="height:${Math.max(3, Math.round(24 * v / max))}px"></span>`).join('')
            + '</span>';
    }

    function skeleton(n = 3) {
        return Array.from({ length: n }, () => `<div class="bg-white rounded-3xl border border-gray-100 p-5 space-y-3 animate-pulse" aria-hidden="true"><div class="h-4 bg-gray-100 rounded w-2/3"></div><div class="h-3 bg-gray-100 rounded w-full"></div><div class="h-3 bg-gray-100 rounded w-1/2"></div></div>`).join('');
    }
    function emptyState(title, body, action = '') {
        return `<div class="col-span-full bg-white rounded-3xl border border-dashed border-gray-200 p-10 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-700 mx-auto mb-3 flex items-center justify-center">${icon('M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'w-7 h-7')}</div>
            <p class="font-display font-bold text-gray-900">${title}</p>
            <p class="text-sm text-gray-500 mt-1">${body}</p>${action}</div>`;
    }

    /* ============================ BOOTSTRAP ============================ */
    function boot() {
        $.post(API_URL, { action: 'bootstrap' }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            BOOT = res.data;

            const teamOpts = BOOT.team.map(t => `<option value="${t.user_id}">${escapeHtml(t.name)}</option>`).join('');
            $('#bulkAssignee').append(teamOpts);
            $('#cAssignee').append(teamOpts);
            $('#fDept').append(BOOT.departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join(''));
            $('#fRegion').append(BOOT.regions.map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join(''));
            $('#fAge').append(BOOT.age_bands.map(a => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join(''));
            $('#fStatusChips').html(BOOT.spiritual_statuses.map(s =>
                `<button type="button" data-st="${s}" onclick="toggleStatus('${s}')" aria-pressed="false" class="st-chip min-h-[44px] px-3 rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-600 transition-all hover:border-emerald-300">${label(s)}</button>`
            ).join(''));

            if (BOOT.celebrations && BOOT.celebrations.length) {
                const names = BOOT.celebrations.map(c => escapeHtml(c.name)).join(', ');
                $('#celebrationBar').removeClass('hidden').html(`
                    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl px-5 py-4 flex items-center gap-4">
                        <span class="w-10 h-10 rounded-xl bg-emerald-700 text-white flex items-center justify-center shrink-0" aria-hidden="true">${icon('M5 13l4 4L19 7', 'w-5 h-5')}</span>
                        <p class="text-sm font-bold text-emerald-900">${names} came back to church. Their follow-up is now marked Returned home.</p>
                    </div>`);
            }

            if (IS_MANAGER) {
                applyRule(BOOT.default_rule);
                initFind();
            } else {
                initCases();
            }
        }, 'json').fail(() => showToast('Could not load Assimilation', 'error'));
    }

    /* ============================ TAB 1 — FIND ============================ */
    let statusPicks = new Set();

    function toggleStatus(s) {
        statusPicks.has(s) ? statusPicks.delete(s) : statusPicks.add(s);
        $('.st-chip').each(function() {
            const on = statusPicks.has(this.dataset.st);
            $(this).toggleClass('bg-emerald-700 text-white border-emerald-700', on)
                   .toggleClass('bg-white text-gray-600 border-gray-200', !on)
                   .attr('aria-pressed', on ? 'true' : 'false');
        });
        describeRule();
    }
    function applyPreset(max, win, unit) {
        $('#ruleMax').val(max); $('#ruleWindow').val(win); $('#ruleUnit').val(unit);
        runFind();
    }
    function applyRule(r) {
        $('#ruleMax').val(r.max_services); $('#ruleWindow').val(r.window_value); $('#ruleUnit').val(r.window_unit);
        $('#fEver').prop('checked', !!r.ever_attended);
        statusPicks = new Set(r.spiritual_status || []);
        toggleStatusRefresh();
    }
    function toggleStatusRefresh() {
        $('.st-chip').each(function() {
            const on = statusPicks.has(this.dataset.st);
            $(this).toggleClass('bg-emerald-700 text-white border-emerald-700', on)
                   .toggleClass('bg-white text-gray-600 border-gray-200', !on)
                   .attr('aria-pressed', on ? 'true' : 'false');
        });
        describeRule();
    }
    function currentRule() {
        return {
            max_services: +$('#ruleMax').val() || 3,
            window_value: +$('#ruleWindow').val() || 2,
            window_unit: $('#ruleUnit').val(),
            spiritual_status: [...statusPicks],
            department_id: +$('#fDept').val() || 0,
            region_id: +$('#fRegion').val() || 0,
            gender: $('#fGender').val() || '',
            age_band: $('#fAge').val() || '',
            ever_attended: $('#fEver').is(':checked') ? 1 : 0,
            last_attended_before: $('#fBefore').val() || null,
            last_attended_after: $('#fAfter').val() || null,
            case_state: $('#fCase').val() || '',
            search: $('#fSearch').val().trim()
        };
    }
    function describeRule() {
        const r = currentRule();
        const unit = r.window_unit.replace(/s$/, '') + (r.window_value === 1 ? '' : 's');
        let s = `Fewer than ${r.max_services} service${r.max_services === 1 ? '' : 's'} in the past ${r.window_value} ${unit}`;
        if (r.spiritual_status.length) s += ' · ' + r.spiritual_status.map(label).join(' / ');
        if (r.ever_attended) s += ' · has attended before';
        $('#ruleSummary').text(s);
    }

    function initFind() {
        if (state.find.ready) return;
        state.find.ready = true;
        let t;
        $('#fSearch').on('input', () => { clearTimeout(t); t = setTimeout(runFind, 400); });
        $('#ruleMax, #ruleWindow, #ruleUnit, #fDept, #fRegion, #fGender, #fAge, #fBefore, #fAfter, #fCase, #fEver').on('change', runFind);
        refreshWatchlistCount();
        runFind();
    }

    function runFind(reset = true) {
        describeRule();
        state.find.page = reset ? 1 : state.find.page + 1;
        if (reset) { $('#findList').html(skeleton()); clearSelection(); }
        $.post(API_URL, { action: 'find_people', rule: JSON.stringify(currentRule()), page: state.find.page, sort: $('#findSort').val() }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            const d = res.data;
            $('#findTotal').text(d.total === 1 ? '1 person matches' : `${d.total} people match`);
            const html = d.people.map(personCard).join('');
            if (reset) {
                $('#findList').html(html || emptyState('Nobody has drifted by this rule',
                    'That is good news. Try a wider window, or a different group.'));
            } else {
                $('#findList').append(html);
            }
            $('#findMore').toggleClass('hidden', !d.has_more);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function personCard(p) {
        const name = `${p.first_name} ${p.last_name || ''}`.trim();
        const dir = p.services_previous > p.services_in_window ? 'down' : (p.services_previous < p.services_in_window ? 'up' : 'flat');
        const arrow = { down: ['text-red-600', 'M19 14l-7 7m0 0l-7-7m7 7V3'], up: ['text-emerald-700', 'M5 10l7-7m0 0l7 7m-7-7v18'], flat: ['text-gray-400', 'M5 12h14'] }[dir];
        const on = state.find.selected.has(p.id);
        return `
        <div class="bg-white rounded-3xl border ${on ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-gray-100'} shadow-sm p-5 space-y-3 transition-all">
            <div class="flex items-start gap-3">
                <label class="shrink-0 mt-0.5 cursor-pointer">
                    <span class="sr-only">Select ${escapeHtml(name)}</span>
                    <input type="checkbox" ${on ? 'checked' : ''} onchange="toggleSelect(${p.id}, this.checked)" class="w-5 h-5 accent-emerald-700">
                </label>
                <div class="min-w-0 flex-1">
                    <p class="font-bold text-gray-900 truncate">${escapeHtml(name)}</p>
                    <p class="text-sm text-gray-500">${escapeHtml(p.phone || 'No phone')}</p>
                </div>
                ${pill(label(p.spiritual_status), 'bg-gray-100 text-gray-700')}
            </div>
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Last in church</p>
                    <p class="text-sm font-bold text-gray-900">${escapeHtml(p.since_words)}</p>
                    <p class="text-xs text-gray-500">${p.last_attended ? escapeHtml(niceDate(p.last_attended)) : 'no record'}</p>
                </div>
                ${sparkline(p.sparkline)}
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold ${arrow[0]}">
                ${icon(arrow[1], 'w-3.5 h-3.5')}
                <span>${p.services_in_window} in this window, was ${p.services_previous} before</span>
            </div>
            ${p.departments ? `<p class="text-[11px] font-semibold text-gray-600 bg-gray-50 border border-gray-100 rounded-xl px-3 py-1.5 truncate">${escapeHtml(p.departments)}</p>` : ''}
            ${p.case_id
                ? `<button type="button" onclick="openCase(${p.case_id})" class="w-full text-left text-[11px] font-bold px-3 py-2 rounded-xl ${STATUS_STYLE[p.case_status] || 'bg-gray-100 text-gray-700'}">${escapeHtml(word(p.case_status))}${p.assignee_name ? ' · ' + escapeHtml(p.assignee_name) : ' · unclaimed'}</button>`
                : '<p class="text-[11px] font-bold text-gray-400 px-3 py-2">Nobody is calling them yet</p>'}
        </div>`;
    }

    function toggleSelect(id, on) {
        on ? state.find.selected.add(id) : state.find.selected.delete(id);
        syncBulkBar();
    }
    function clearSelection() {
        state.find.selected.clear();
        $('#findList input[type=checkbox]').prop('checked', false);
        $('#findList > div').removeClass('border-emerald-400 ring-2 ring-emerald-100').addClass('border-gray-100');
        syncBulkBar();
    }
    function syncBulkBar() {
        $('#selCount').text(state.find.selected.size);
        $('#bulkBar').toggleClass('hidden', state.find.selected.size === 0);
    }
    function bulkAssign() {
        const ids = [...state.find.selected];
        if (!ids.length) return;
        const to = +$('#bulkAssignee').val();
        lockScreen();
        $.post(API_URL, { action: 'bulk_assign', user_ids: ids, to_user_id: to }, function(res) {
            unlockScreen();
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { clearSelection(); runFind(); }
        }, 'json').fail(() => { unlockScreen(); showToast('Server error', 'error'); });
    }
    function openExportModal() {
        openModal('exportModal');
    }
    function exportExcel(reportType) {
        if (!['general', 'detailed'].includes(reportType)) return;
        closeModal('exportModal');
        const form = $('<form method="post" target="_blank">').attr('action', API_URL);
        form.append($('<input type="hidden" name="action" value="export_excel">'));
        form.append($('<input type="hidden" name="report_type">').val(reportType));
        form.append($('<input type="hidden" name="rule">').val(JSON.stringify(currentRule())));
        form.appendTo('body').trigger('submit').remove();
    }

    /* ---------------------------- watchlists ---------------------------- */
    function refreshWatchlistCount() {
        $.post(API_URL, { action: 'list_watchlists' }, function(res) {
            if (res.status !== 'success') return;
            $('#wlCount').text(res.data.length ? `(${res.data.length})` : '');
            $('#cWatchlist').find('option:gt(0)').remove();
            $('#cWatchlist').append(res.data.map(w => `<option value="${w.id}">${escapeHtml(w.name)}</option>`).join(''));
            renderWatchlists(res.data);
        }, 'json');
    }
    function openWatchlists() {
        $('#wlList').html(skeleton(2));
        refreshWatchlistCount();
        openModal('watchlistModal');
    }
    function renderWatchlists(list) {
        if (!list.length) {
            $('#wlList').html(`<div class="text-center py-8 space-y-3">
                <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-700 mx-auto flex items-center justify-center">${icon('M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'w-7 h-7')}</div>
                <p class="text-sm text-gray-500">No watchlists yet.<br>Tap <strong class="text-gray-700">Build a List</strong> on the Find people tab — it takes under a minute.</p>
            </div>`);
            return;
        }
        $('#wlList').html(list.map(w => {
            const chips = [];
            if (w.is_open) {
                if (w.pool) chips.push(`<span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 text-amber-800 px-2.5 py-1 text-[10px] font-bold">📲 ${w.pool} waiting for a volunteer</span>`);
                if (w.claimed) chips.push(`<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-2.5 py-1 text-[10px] font-bold">🙋 ${w.claimed} being called</span>`);
                if (w.untouched) chips.push(`<span class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-gray-600 px-2.5 py-1 text-[10px] font-bold">${w.untouched} held back by past outcomes</span>`);
                if (!chips.length) chips.push(`<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-2.5 py-1 text-[10px] font-bold">Everyone on it is being carried 🎉</span>`);
            } else if (w.untouched) {
                chips.push(`<span class="text-xs font-bold text-red-700 bg-red-50 border border-red-100 rounded-xl px-3 py-2 block">${w.untouched} of them have nobody calling yet</span>`);
            }
            return `
            <div class="border ${w.is_active ? 'border-gray-100' : 'border-dashed border-gray-200 opacity-70'} rounded-2xl p-4 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900">${escapeHtml(w.name)}
                            ${w.is_open ? pill('🙌 Open', 'bg-emerald-100 text-emerald-800 ml-1') : ''}
                            ${w.is_active ? '' : pill('Paused', 'bg-gray-100 text-gray-600 ml-1')}
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5">${escapeHtml(w.summary)}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-display font-bold text-xl text-gray-900">${w.people}</p>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">people</p>
                    </div>
                </div>
                ${chips.length ? `<div class="flex flex-wrap gap-1.5">${chips.join('')}</div>` : ''}
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick='loadWatchlist(${JSON.stringify(w.rule)})' class="min-h-[44px] px-4 rounded-xl bg-emerald-700 hover:bg-emerald-900 text-white text-xs font-bold">Open in Find people</button>
                    <button type="button" onclick="toggleWatchlist(${w.id}, ${w.is_active ? 0 : 1})" class="min-h-[44px] px-4 rounded-xl border border-gray-200 text-gray-700 text-xs font-bold hover:border-emerald-300">${w.is_active ? 'Pause digest' : 'Turn on'}</button>
                    <button type="button" onclick="deleteWatchlist(${w.id}, '${escapeHtml(w.name).replace(/'/g, "&#39;")}')" class="min-h-[44px] px-4 rounded-xl bg-red-50 hover:bg-red-100 text-red-700 text-xs font-bold ml-auto">Delete</button>
                </div>
            </div>`;
        }).join(''));
    }
    function loadWatchlist(rule) {
        applyRule(rule);
        $('#fDept').val(rule.department_id || 0);
        $('#fRegion').val(rule.region_id || 0);
        $('#fGender').val(rule.gender || '');
        $('#fAge').val(rule.age_band || '');
        $('#fBefore').val(rule.last_attended_before || '');
        $('#fAfter').val(rule.last_attended_after || '');
        $('#fCase').val(rule.case_state || '');
        $('#fSearch').val(rule.search || '');
        closeModal('watchlistModal');
        switchTab('find');
        runFind();
    }
    function toggleWatchlist(id, on) {
        $.post(API_URL, { action: 'toggle_watchlist', id: id, is_active: on }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            refreshWatchlistCount();
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function deleteWatchlist(id, name) {
        if (!confirm(`Delete the watchlist "${name}"?\n\nFollow-ups already opened from it are kept.`)) return;
        $.post(API_URL, { action: 'delete_watchlist', id: id }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            refreshWatchlistCount();
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function openSaveWatchlist() {
        describeRule();
        $('#wlRuleEcho').text($('#ruleSummary').text());
        $('#wlName').val('');
        $('#wlOpen').prop('checked', false);
        openModal('saveWlModal');
        setTimeout(() => $('#wlName').trigger('focus'), 320);
    }
    $('#saveWlForm').on('submit', function(ev) {
        ev.preventDefault();
        $.post(API_URL, {
            action: 'save_watchlist', name: $('#wlName').val().trim(),
            notify: $('#wlNotify').is(':checked') ? 1 : 0,
            is_open: $('#wlOpen').is(':checked') ? 1 : 0,
            rule: JSON.stringify(currentRule())
        }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') {
                if (res.data && res.data.self_claim_turned_on && BOOT) BOOT.allow_self_claim = true;
                closeModal('saveWlModal');
                refreshWatchlistCount();
            }
        }, 'json').fail(() => showToast('Server error', 'error'));
    });

    /* ==================== ADVANCED PANEL TOGGLE ==================== */
    function toggleAdvanced() {
        const opening = $('#advPanel').hasClass('hidden');
        $('#advPanel').toggleClass('hidden');
        $('#advChevron').css('transform', opening ? 'rotate(180deg)' : '');
        $('#advToggle').attr('aria-expanded', opening ? 'true' : 'false');
        if (opening) runFind();
    }

    /* ==================== BUILD-A-LIST WIZARD ==================== */
    const BW_TOTAL = 6;
    const BW_AWAY = {
        nos:  { max: 1, win: 1, unit: 'months', label: 'Just missed a few Sundays' },
        slip: { max: 3, win: 2, unit: 'months', label: 'Slipping away' },
        gone: { max: 5, win: 3, unit: 'months', label: 'Gone a while' }
    };
    const BW_STATUSES = [
        ['1st_Timer', '1st timer', '👋', 'bg-amber-100'], ['2nd_Timer', '2nd timer', '🌱', 'bg-lime-100'],
        ['3rd_Timer', '3rd timer', '🌿', 'bg-green-100'], ['Visitor', 'Visitor', '🧳', 'bg-sky-100'],
        ['Non_Member', 'Not a member yet', '🙂', 'bg-gray-100'], ['Member', 'Member', '🏠', 'bg-emerald-100'],
        ['Worker', 'Worker', '🛠️', 'bg-orange-100'], ['Pastor', 'Pastor', '📖', 'bg-violet-100']
    ];
    let bw = null;

    function bwFresh() {
        return { step: 0, away: 'slip', custom: { max: 3, win: 2, unit: 'months' },
            who: new Set(), mode: 'open', digest: 1,
            nameTouched: false, busy: false, countSig: null };
    }

    function openBuilder() {
        if (!BOOT) { showToast('Still loading — one moment', 'error'); return; }
        bw = bwFresh();
        $('#bwDept').html('<option value="0">Every department</option>' + BOOT.departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join(''));
        $('#bwRegion').html('<option value="0">Every region</option>' + BOOT.regions.map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join(''));
        $('#bwStatusWrap').html(BW_STATUSES.map(([k, lbl, emoji, bg]) => `
            <button type="button" data-who="${k}" onclick="bwPickWho('${k}')" aria-pressed="false" class="bw-who bw-choice min-h-[64px] flex flex-col items-center justify-center gap-1 rounded-2xl border-2 border-gray-100 bg-white p-2.5 text-center hover:border-emerald-200">
                <span class="w-9 h-9 rounded-xl ${bg} flex items-center justify-center text-xl leading-none" aria-hidden="true">${emoji}</span>
                <span class="text-[11px] font-bold text-gray-700 leading-tight">${lbl}</span>
            </button>`).join(''));
        $('#bwGenderWrap').html([['', 'Everyone'], ['Male', 'Male'], ['Female', 'Female']].map(([v, lbl]) => `
            <button type="button" data-g="${v}" onclick="bwPickGender('${v}')" aria-pressed="${v === '' ? 'true' : 'false'}" class="bw-gender bw-choice flex-1 min-h-[48px] rounded-xl border-2 border-gray-100 bg-white text-sm font-bold text-gray-600 hover:border-emerald-200">${lbl}</button>`).join(''));
        $('#bwAgeWrap').html([['', 'Any age']].concat(BOOT.age_bands.map(a => [a, a])).map(([v, lbl]) => `
            <button type="button" data-age="${escapeHtml(v)}" onclick="bwPickAge('${escapeHtml(v)}')" aria-pressed="${v === '' ? 'true' : 'false'}" class="bw-age bw-choice min-h-[44px] px-3.5 rounded-full border-2 border-gray-100 bg-white text-xs font-bold text-gray-600 hover:border-emerald-200">${escapeHtml(lbl)}</button>`).join(''));
        $('#bwCustomMax').text(bw.custom.max);
        $('#bwCustomWin').text(bw.custom.win);
        $('#bwCustomUnit').val(bw.custom.unit);
        $('#bwEver').prop('checked', true);
        $('#bwDigest').prop('checked', true);
        $('#bwName').val('');
        bwSyncAll();
        openModal('bwModal');
        bwShow(0, true);
    }

    function bwRule() {
        const a = bw.away === 'custom' ? bw.custom : BW_AWAY[bw.away];
        return {
            max_services: a.max, window_value: a.win, window_unit: a.unit,
            spiritual_status: [...bw.who],
            department_id: +$('#bwDept').val() || 0,
            region_id: +$('#bwRegion').val() || 0,
            gender: bw.gender || '', age_band: bw.age || '',
            ever_attended: $('#bwEver').is(':checked') ? 1 : 0,
            last_attended_before: null, last_attended_after: null,
            case_state: '', search: ''
        };
    }

    function bwSuggest() {
        const a = bw.away === 'custom' ? 'Custom watch' : BW_AWAY[bw.away].label;
        const who = bw.who.size ? [...bw.who].map(label).join(' & ') : 'Everyone';
        return `${a} · ${who}`.slice(0, 120);
    }

    function bwShow(step, instant = false) {
        if (!bw) return;
        bw.step = Math.max(0, Math.min(BW_TOTAL - 1, step));
        $('.bw-step').each(function() {
            const on = +this.dataset.wstep === bw.step;
            $(this).toggleClass('hidden', !on).toggleClass('bw-step-on', on && !instant);
        });
        $('#bwBody').scrollTop(0);
        $('#bwStepNum').text(`${bw.step + 1}/${BW_TOTAL}`);
        bwDots();
        bwFooter();
        if (bw.step === BW_TOTAL - 1) {
            if (!bw.nameTouched) $('#bwName').val(bwSuggest());
            bwRecap();
            bwRefreshCount();
            setTimeout(() => $('#bwName').trigger('focus'), 350);
        }
    }

    function bwForward() {
        if (!bw || bw.busy) return;
        if (bw.step === BW_TOTAL - 1) { bwLaunch(); return; }
        bwShow(bw.step + 1);
    }

    function bwDots() {
        $('#bwDots').html(Array.from({ length: BW_TOTAL }, (_, i) => {
            const cls = i === bw.step ? 'w-6 bg-emerald-600' : (i < bw.step ? 'w-2 bg-emerald-300' : 'w-2 bg-gray-200');
            return `<button type="button" role="tab" data-i="${i}" aria-label="Step ${i + 1}" aria-selected="${i === bw.step}" ${i < bw.step ? '' : 'disabled'} class="bw-dot h-2 rounded-full transition-all duration-300 ${cls}"></button>`;
        }).join(''));
    }
    $(document).on('click', '.bw-dot', function() { if (bw && !bw.busy) bwShow(+this.dataset.i); });

    function bwFooter() {
        $('#bwBack').toggleClass('invisible', bw.step === 0).prop('disabled', bw.busy);
        const next = $('#bwNext').prop('disabled', bw.busy);
        const arrow = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>`;
        if (bw.step === BW_TOTAL - 1) {
            next.html(bw.busy
                ? `<svg class="animate-spin w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Launching…`
                : `🚀 Launch watchlist`);
        } else {
            next.html((bw.step === 0 ? 'Let&rsquo;s go ' : 'Continue ') + arrow);
        }
    }

    /* ---- step 1: how long away ---- */
    function bwPickAway(k) {
        bw.away = k; bw.countSig = null;
        $('.bw-away').each(function() { bwPaintChoice($(this), this.dataset.away === k); });
        $('#bwCustomRow').toggleClass('hidden', k !== 'custom');
    }
    function bwStepCustom(field, delta) {
        if (field === 'max') bw.custom.max = Math.max(1, Math.min(50, bw.custom.max + delta));
        if (field === 'win') bw.custom.win = Math.max(1, Math.min(104, bw.custom.win + delta));
        $('#bwCustomMax').text(bw.custom.max);
        $('#bwCustomWin').text(bw.custom.win);
        bw.countSig = null;
    }
    function bwCustomUnitChanged() { bw.custom.unit = $('#bwCustomUnit').val(); bw.countSig = null; }

    /* ---- step 2: who ---- */
    function bwPickEveryone() {
        bw.who.clear(); bw.countSig = null;
        bwSyncWho();
    }
    function bwPickWho(k) {
        bw.who.has(k) ? bw.who.delete(k) : bw.who.add(k);
        bw.countSig = null;
        bwSyncWho();
    }
    function bwSyncWho() {
        const everyone = bw.who.size === 0;
        bwPaintChoice($('#bwEveryone'), everyone);
        $('#bwEveryone').attr('aria-pressed', everyone ? 'true' : 'false');
        $('.bw-who').each(function() {
            const on = bw.who.has(this.dataset.who);
            bwPaintChoice($(this), on);
            $(this).attr('aria-pressed', on ? 'true' : 'false');
        });
    }

    /* ---- step 3: fine tune ---- */
    function bwPickGender(v) {
        bw.gender = v; bw.countSig = null;
        $('.bw-gender').each(function() { bwPaintChoice($(this), this.dataset.g === v); });
    }
    function bwPickAge(v) {
        bw.age = v; bw.countSig = null;
        $('.bw-age').each(function() { bwPaintChoice($(this), this.dataset.age === v); });
    }
    function bwFtChanged() { bw.countSig = null; }

    /* ---- step 4: who works it ---- */
    function bwPickMode(mode) {
        bw.mode = mode; bw.countSig = null;
        bwPaintChoice($('#bwModeOpen'), mode === 'open');
        bwPaintChoice($('#bwModeManaged'), mode === 'managed');
        $('#bwDigestLbl').text(mode === 'open'
            ? 'Nudge the whole team when someone new drifts in'
            : 'Nudge the managers when someone new drifts in');
        $('#bwSelfClaimNote').toggleClass('hidden', !(mode === 'open' && BOOT && !BOOT.allow_self_claim));
    }
    function bwDigestChanged() { bw.digest = $('#bwDigest').is(':checked') ? 1 : 0; bw.countSig = null; }

    /* ---- shared choice painting ---- */
    function bwPaintChoice($el, on) {
        $el.toggleClass('border-emerald-600 bg-emerald-50 shadow-sm', on)
           .toggleClass('border-gray-100 bg-white', !on);
        if ($el.attr('role') === 'radio') $el.attr('aria-checked', on ? 'true' : 'false');
        if ($el.attr('aria-pressed') !== undefined) $el.attr('aria-pressed', on ? 'true' : 'false');
        $el.find('.bw-check')
           .toggleClass('bg-emerald-600 border-emerald-600 text-white', on)
           .toggleClass('border-gray-200 text-transparent', !on)
           .html(on ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3.5" d="M5 13l4 4L19 7"/></svg>' : '');
    }
    function bwSyncAll() {
        bwPickAway(bw.away);
        bwSyncWho();
        bwPickGender('');
        bwPickAge('');
        bwPickMode(bw.mode);
        bw.digest = 1;
        $('#bwDigestLbl').text('Nudge the whole team when someone new drifts in');
    }

    /* ---- step 5: recap & count ---- */
    function bwRecap() {
        const a = bw.away === 'custom'
            ? `Under ${bw.custom.max} in ${bw.custom.win} ${bw.custom.unit}`
            : BW_AWAY[bw.away].label;
        const chips = [
            ['🕰️', a],
            ['👥', bw.who.size ? [...bw.who].map(label).join(', ') : 'Everyone'],
        ];
        const dept = BOOT.departments.find(d => +d.id === +$('#bwDept').val());
        if (dept) chips.push(['⛪', dept.name]);
        const region = BOOT.regions.find(r => +r.id === +$('#bwRegion').val());
        if (region) chips.push(['📍', region.name]);
        if (bw.gender) chips.push(['🧑', bw.gender]);
        if (bw.age) chips.push(['🎂', 'Aged ' + bw.age]);
        chips.push([bw.mode === 'open' ? '🙌' : '🧭', bw.mode === 'open' ? 'Open to every volunteer' : 'Managers assign']);
        chips.push(['🔔', bw.digest ? 'Daily nudge on' : 'Silent — no nudges']);
        $('#bwRecap').html(chips.map(([emoji, txt]) =>
            `<span class="inline-flex items-center gap-1.5 bg-white border border-gray-200 rounded-full px-3 py-1.5 text-[11px] font-bold text-gray-600"><span aria-hidden="true">${emoji}</span>${escapeHtml(txt)}</span>`).join(''));
    }

    function bwRefreshCount() {
        const sig = JSON.stringify(bwRule());
        if (bw.countSig === sig) return;
        bw.countSig = sig;
        $('#bwCount').html('<span class="bw-count-dot">•</span><span class="bw-count-dot" style="animation-delay:.15s">•</span><span class="bw-count-dot" style="animation-delay:.3s">•</span>');
        $('#bwCountSub').text('Counting quietly…');
        $.post(API_URL, { action: 'count_rule', rule: sig }, function(res) {
            if (!bw || bw.countSig !== sig) return;
            if (res.status !== 'success') { $('#bwCount').text('—'); $('#bwCountSub').text('Could not count right now'); return; }
            bwTweenCount(res.data.count);
        }, 'json').fail(function() {
            if (!bw || bw.countSig !== sig) return;
            $('#bwCount').text('—');
            $('#bwCountSub').text('Could not count right now');
        });
    }

    function bwTweenCount(target) {
        target = +target || 0;
        const el = document.getElementById('bwCount');
        const t0 = performance.now(), dur = Math.min(900, 300 + target * 2);
        (function tick(t) {
            const p = Math.min(1, (t - t0) / dur);
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toLocaleString();
            if (p < 1 && bw) requestAnimationFrame(tick);
        })(performance.now());
        $('#bwCountSub').text(target === 1 ? 'person matches right now' : 'people match right now');
    }

    /* ---- launch ---- */
    function bwLaunch() {
        if (bw.busy) return;
        const name = $('#bwName').val().trim();
        if (!name) {
            showToast('Give the list a short name first', 'error');
            $('#bwName').trigger('focus');
            return;
        }
        bw.busy = true; bwFooter();
        $.post(API_URL, {
            action: 'save_watchlist', name: name, rule: JSON.stringify(bwRule()),
            notify: bw.digest ? 1 : 0, is_open: bw.mode === 'open' ? 1 : 0
        }, function(res) {
            bw.busy = false; bwFooter();
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            if (res.data && res.data.self_claim_turned_on && BOOT) BOOT.allow_self_claim = true;
            closeModal('bwModal');
            confettiBurst();
            showToast(res.message, 'success');
            refreshWatchlistCount();
        }, 'json').fail(function() {
            bw.busy = false; bwFooter();
            showToast('Server error', 'error');
        });
    }

    /* ---- celebration ---- */
    function confettiBurst() {
        const colors = ['#059669', '#10B981', '#34D399', '#FBBF24', '#F59E0B', '#A7F3D0'];
        const host = document.createElement('div');
        host.className = 'fixed inset-0 z-[10001] pointer-events-none overflow-hidden';
        host.setAttribute('aria-hidden', 'true');
        document.body.appendChild(host);
        for (let i = 0; i < 42; i++) {
            const s = document.createElement('span');
            const size = 6 + Math.random() * 8;
            s.style.cssText = `position:absolute;top:-12px;left:${Math.random() * 100}%;width:${size}px;height:${size * (0.6 + Math.random())}px;background:${colors[i % colors.length]};border-radius:${Math.random() > 0.5 ? '50%' : '2px'};`;
            host.appendChild(s);
            const fall = window.innerHeight * 0.65 + Math.random() * window.innerHeight * 0.4;
            s.animate([
                { transform: 'translate3d(0,-12px,0) rotate(0deg)', opacity: 1 },
                { transform: `translate3d(${(Math.random() - 0.5) * 240}px,${fall}px,0) rotate(${360 + Math.random() * 540}deg)`, opacity: 0.85 }
            ], { duration: 1700 + Math.random() * 1300, easing: 'cubic-bezier(.16,.7,.4,1)', fill: 'forwards' });
        }
        setTimeout(() => host.remove(), 3300);
    }
    $(document).on('keydown', function(ev) {
        const m = document.getElementById('bwModal');
        if (ev.key === 'Escape' && m && !m.classList.contains('hidden')) closeModal('bwModal');
    });

    /* ============================ TAB 2 — FOLLOW-UP ============================ */
    const SUB_TABS = [['to_call', 'To call'], ['reached', 'Reached'], ['promised', 'Promised to come'],
                      ['returned_home', 'Returned home'], ['closed', 'Closed'], ['all', 'All']];

    function initCases() {
        if (state.cases.ready) return;
        state.cases.ready = true;
        let t;
        $('#cSearch').on('input', () => { clearTimeout(t); t = setTimeout(() => loadCases(), 400); });
        $('#cAssignee, #cWatchlist').on('change', () => loadCases());
        loadCases();
    }
    function pickSub(s) { state.cases.sub = s; loadCases(); }
    function toggleOverdue() { state.cases.overdue = !state.cases.overdue; loadCases(); }

    function renderSubTabs(counts) {
        $('#fuSubTabs').html(SUB_TABS.map(([k, name]) => {
            const on = state.cases.sub === k;
            return `<button type="button" role="tab" aria-selected="${on}" onclick="pickSub('${k}')" class="shrink-0 min-h-[44px] px-4 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 ${on ? 'bg-emerald-700 text-white shadow-md shadow-emerald-900/20' : 'bg-white text-gray-600 border border-gray-200 hover:border-emerald-300'}">${name} <span class="ml-1 ${on ? 'text-emerald-100' : 'text-gray-400'}">${counts[k] ?? 0}</span></button>`;
        }).join(''));
    }

    function loadCases(reset = true) {
        state.cases.page = reset ? 1 : state.cases.page + 1;
        if (reset) $('#caseList').html(skeleton());
        $.post(API_URL, {
            action: 'list_cases', sub_tab: state.cases.sub, page: state.cases.page,
            search: $('#cSearch').val().trim(), assignee: $('#cAssignee').val(),
            watchlist_id: $('#cWatchlist').val(), overdue_only: state.cases.overdue ? 1 : 0
        }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            const d = res.data;
            renderSubTabs(d.counts);
            $('#overdueCount').text(d.counts.overdue);
            $('#overdueHint').text(state.cases.overdue ? 'Showing only these — tap to show all.' : 'Tap to show only these.');
            $('#overdueWidget').toggleClass('hidden', d.counts.overdue === 0 && !state.cases.overdue)
                               .toggleClass('ring-2 ring-red-400', state.cases.overdue);
            const html = d.cases.map(caseCard).join('');
            if (reset) {
                $('#caseList').html(html || emptyState('Nothing in this stage',
                    IS_MANAGER ? 'Try another stage, or find people to follow up in the first tab.' : 'Nobody is waiting on you right now.'));
            } else {
                $('#caseList').append(html);
            }
            $('#caseMore').toggleClass('hidden', !d.has_more);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function caseCard(c) {
        const note = c.last_note ? String(c.last_note).split('\n')[0] : '';
        return `
        <div role="button" tabindex="0" onclick="openCase(${c.id})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openCase(${c.id});}"
             class="relative overflow-hidden bg-white rounded-3xl border border-gray-100 shadow-sm p-5 space-y-3 cursor-pointer hover:shadow-md hover:border-emerald-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 transition-all">
            ${c.is_overdue ? '<span class="absolute top-0 right-0 bg-red-600 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-bl-xl">Overdue</span>' : ''}
            ${c.returned_home_at ? '<span class="absolute top-0 right-0 bg-emerald-700 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-bl-xl">Home</span>' : ''}
            <div class="min-w-0 pr-16">
                <p class="font-bold text-gray-900 truncate">${escapeHtml(c.person_name)}</p>
                <p class="text-sm text-gray-500">${escapeHtml(c.phone || 'No phone')}</p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                ${pill(word(c.status), STATUS_STYLE[c.status] || 'bg-gray-100 text-gray-700')}
                ${pill(label(c.spiritual_status), 'bg-gray-100 text-gray-600')}
            </div>
            <p class="text-[11px] font-semibold text-gray-600 bg-gray-50 border border-gray-100 rounded-xl px-3 py-1.5">
                Last in church ${escapeHtml(c.since_words)} · ${c.touches} contact${c.touches === 1 ? '' : 's'}
            </p>
            ${note ? `<p class="text-[11px] text-gray-600 px-3 line-clamp-2">${escapeHtml(note)}</p>` : ''}
            <div class="flex items-center justify-between gap-2 pt-1">
                <span class="text-xs font-bold ${c.assignee_name ? 'text-gray-700' : 'text-gray-400'}">${c.assignee_name ? escapeHtml(c.assignee_name) : 'Unclaimed'}</span>
                ${c.next_touch_date ? `<span class="text-[11px] font-bold text-emerald-800">Next ${escapeHtml(niceDate(c.next_touch_date))}</span>` : ''}
            </div>
        </div>`;
    }

    /* ---------------------------- the drawer ---------------------------- */
    function openCase(id) {
        state.cases.caseId = id;
        const dr = document.getElementById('caseDrawer');
        document.body.appendChild(dr);
        dr.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        $('#drawerName').text('Loading…'); $('#drawerSub').text('');
        $('#drawerBody').html(skeleton(2));
        requestAnimationFrame(() => {
            dr.querySelector('[data-drawer-backdrop]').classList.remove('opacity-0');
            dr.querySelector('[data-drawer-panel]').classList.remove('translate-y-full', 'md:translate-x-full');
        });
        $.post(API_URL, { action: 'case_detail', case_id: id }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); closeDrawer(); return; }
            renderDrawer(res.data);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function closeDrawer() {
        const dr = document.getElementById('caseDrawer');
        if (dr.classList.contains('hidden')) return;
        dr.querySelector('[data-drawer-backdrop]').classList.add('opacity-0');
        dr.querySelector('[data-drawer-panel]').classList.add('translate-y-full', 'md:translate-x-full');
        setTimeout(() => { dr.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
        state.cases.caseId = null;
    }

    function renderDrawer(d) {
        const c = d.case, p = d.person;
        const name = `${p.first_name} ${p.last_name || ''}`.trim();
        $('#drawerName').text(name);
        $('#drawerSub').text([word(c.status), 'last in church ' + d.since_words].join(' · '));

        const section = (title, body) => `<section class="space-y-3"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest">${title}</h4>${body}</section>`;
        const field = (k, v) => v ? `<div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">${k}</p><p class="text-sm font-medium text-gray-800">${escapeHtml(v)}</p></div>` : '';
        const wa = (p.phone || '').replace(/\D/g, '').replace(/^0/, '234');

        const essentials = `
            <div class="flex flex-wrap gap-1.5">${pill(label(p.spiritual_status), 'bg-gray-100 text-gray-700')}${c.is_overdue ? pill('Overdue', 'bg-red-600 text-white') : ''}${c.returned_home_at ? pill('Returned home', 'bg-emerald-700 text-white') : ''}</div>
            ${p.phone ? `<div class="flex gap-2">
                <a href="tel:${escapeHtml(p.phone)}" class="flex-1 min-h-[44px] flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-xl text-sm font-bold">Call ${escapeHtml(p.phone)}</a>
                <a href="https://wa.me/${wa}" target="_blank" rel="noopener" class="min-h-[44px] flex items-center px-4 bg-[#25D366] hover:bg-[#128C7E] text-white rounded-xl text-sm font-bold">WhatsApp</a></div>` : ''}
            <div class="grid grid-cols-2 gap-4">
                ${field('Departments', p.departments)}${field('Region', p.region_name)}
                ${field('Last in church', d.last_attended ? `${niceDate(d.last_attended)} · ${d.since_words}` : 'No record at all')}
                ${field('Volunteer', c.assignee_name || 'Unclaimed')}
                ${field('Next touch', c.next_touch_date ? niceDate(c.next_touch_date) : '—')}
                ${field('Follow-up opened', `${relTime(c.opened_at)} · away ${d.opened_gap.replace(' ago', '')} then`)}
            </div>
            ${d.cross_links.length ? `<div class="space-y-1.5">${d.cross_links.map(l => `<a href="${l.url}" class="flex items-center gap-2 text-xs font-bold text-blue-800 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2 hover:bg-blue-100">${icon('M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5', 'w-3.5 h-3.5')}${escapeHtml(l.label)}</a>`).join('')}</div>` : ''}`;

        const maxBar = Math.max(1, ...d.months.map(m => m.count));
        const timeline = `
            <div class="flex items-end gap-1.5 h-24 bg-gray-50 border border-gray-100 rounded-2xl p-3" role="img" aria-label="Services attended each month for the last year: ${d.months.map(m => m.label + ' ' + m.count).join(', ')}">
                ${d.months.map(m => `<div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                    <div class="w-full rounded-t ${m.count ? 'bg-emerald-700' : 'bg-gray-200'}" style="height:${Math.max(3, Math.round(56 * m.count / maxBar))}px" title="${m.label}: ${m.count}"></div>
                    <span class="text-[8px] font-bold text-gray-400 truncate">${escapeHtml(m.label)}</span>
                </div>`).join('')}
            </div>
            ${d.recent_days.length
                ? `<p class="text-xs text-gray-500 leading-relaxed"><span class="font-bold text-gray-700">Last ${d.recent_days.length}:</span> ${d.recent_days.map(x => escapeHtml(niceDate(x))).join(' · ')}</p>`
                : '<p class="text-xs text-gray-400 italic">We have no attendance record for them at all.</p>'}`;

        const fu = d.follow_ups.length ? `<ol class="relative border-l-2 border-gray-100 ml-3 space-y-5">${d.follow_ups.map(f => `
            <li class="ml-5">
                <span class="absolute -left-[15px] w-7 h-7 rounded-full bg-white border-2 border-emerald-200 text-emerald-700 flex items-center justify-center" aria-hidden="true">${icon('M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z', 'w-3.5 h-3.5')}</span>
                <div class="flex flex-wrap items-center gap-2">
                    ${pill(OUTCOME_WORDS[f.outcome] || label(f.outcome), 'bg-gray-100 text-gray-800')}
                    <span class="text-xs font-bold text-gray-700">${escapeHtml(CHANNEL_WORDS[f.channel] || label(f.channel))}</span>
                    <span class="text-xs text-gray-400">${escapeHtml(f.logged_by_name || '—')} · ${escapeHtml(relTime(f.created_at))}</span>
                </div>
                ${f.notes_clean ? `<p class="text-sm text-gray-800 mt-1.5 whitespace-pre-line bg-emerald-50/60 border border-emerald-100 rounded-xl px-3 py-2">${escapeHtml(f.notes_clean)}</p>` : ''}
                ${f.notes ? `<details class="mt-1.5"><summary class="text-[11px] font-bold text-gray-400 cursor-pointer">${f.notes_clean ? 'Their own words' : 'Notes'}</summary><p class="text-sm text-gray-700 mt-1 whitespace-pre-line">${escapeHtml(f.notes)}</p></details>` : ''}
                ${f.prayer_points ? `<p class="text-xs text-indigo-800 bg-indigo-50 border border-indigo-100 rounded-xl px-3 py-2 mt-1.5"><span class="font-bold">Prayer:</span> ${escapeHtml(f.prayer_points)}</p>` : ''}
                ${f.next_touch_date ? `<p class="text-xs font-bold text-emerald-800 mt-1">Next touch: ${escapeHtml(niceDate(f.next_touch_date))}</p>` : ''}
            </li>`).join('')}</ol>` : '<p class="text-sm text-gray-400 italic">Nobody has reached out yet.</p>';

        const history = d.assignments.length
            ? section('Assignment history', `<ul class="space-y-1.5">${d.assignments.map(a => `<li class="text-xs text-gray-500"><span class="font-bold text-gray-700">${label(a.action)}</span>${a.to_name ? ' → ' + escapeHtml(a.to_name) : ''} by ${escapeHtml(a.by_name || '—')} · ${escapeHtml(relTime(a.created_at))}</li>`).join('')}</ul>`)
            : '';

        let actions = '';
        if (d.can_assign) {
            const opts = (BOOT ? BOOT.team : []).map(m => `<option value="${m.user_id}" ${+m.user_id === +c.assigned_to ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');
            actions += `<div class="flex gap-2">
                <label for="assignSelect" class="sr-only">Assign to</label>
                <select id="assignSelect" class="flex-1 min-w-0 px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none"><option value="">Choose a volunteer…</option>${opts}</select>
                <button type="button" onclick="assignCase(${c.id}, ${c.assigned_to ? 'true' : 'false'})" class="shrink-0 min-h-[44px] bg-gray-900 hover:bg-black text-white px-4 rounded-xl text-sm font-bold">${c.assigned_to ? 'Reassign' : 'Assign'}</button>
            </div>`;
            if (c.assigned_to) {
                actions += `<button type="button" onclick="unassignCase(${c.id})" class="w-full min-h-[44px] border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:border-emerald-300">Put back in the unclaimed pool</button>`;
            }
        }
        if (!c.assigned_to && !c.closed_at) {
            actions += `<button type="button" onclick="claimCase(${c.id})" class="w-full min-h-[44px] bg-emerald-700 hover:bg-emerald-900 text-white rounded-xl font-bold text-sm">I&rsquo;ll call them</button>`;
        }
        if (!c.returned_home_at) {
            actions += `<button type="button" onclick="markHome(${c.id})" class="w-full min-h-[44px] bg-emerald-50 hover:bg-emerald-100 text-emerald-900 border border-emerald-200 rounded-xl font-bold text-sm">They came back to church</button>`;
        }
        if (c.closed_at && d.can_assign) {
            actions += `<button type="button" onclick="reopenCase(${c.id})" class="w-full min-h-[44px] border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:border-emerald-300">Reopen this follow-up</button>`;
        }

        const logForm = c.closed_at ? `
            <div class="rounded-2xl bg-gray-50 border border-gray-100 p-4 text-sm text-gray-600">
                Closed ${escapeHtml(relTime(c.closed_at))}${c.outcome ? ' — ' + escapeHtml(c.outcome) : ''}.
                ${d.can_assign ? 'Reopen it above to log more.' : ''}
            </div>` : `
            <form id="logForm" class="space-y-3 bg-gray-50 border border-gray-100 rounded-2xl p-4">
                <div class="grid grid-cols-2 gap-2">
                    <select name="channel" required aria-label="How you reached out" class="px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">
                        ${d.channels.map(ch => `<option value="${ch}">${escapeHtml(CHANNEL_WORDS[ch] || label(ch))}</option>`).join('')}
                    </select>
                    <select name="outcome" required aria-label="How it went" class="px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">
                        ${d.outcomes.map(o => `<option value="${o}">${escapeHtml(OUTCOME_WORDS[o] || label(o))}</option>`).join('')}
                    </select>
                </div>
                <textarea name="notes" rows="3" placeholder="What did they say? What are they carrying?" aria-label="Notes" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white resize-none focus:border-emerald-500 outline-none"></textarea>
                <div class="flex justify-end">
                    <button type="button" onclick="cleanDrawerNotes()" id="drawerCleanBtn" class="min-h-[44px] px-3 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-xs font-bold">Clean up with AI</button>
                </div>
                <input type="hidden" name="notes_clean" id="drawerClean">
                <textarea name="prayer_points" rows="2" placeholder="Prayer points" aria-label="Prayer points" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white resize-none focus:border-emerald-500 outline-none"></textarea>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider">Next touch
                    <input type="date" name="next_touch_date" class="mt-1 w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">
                </label>
                <button type="submit" class="w-full min-h-[44px] bg-emerald-700 hover:bg-emerald-900 text-white rounded-xl font-bold text-sm">Save follow-up</button>
                <input type="hidden" name="action" value="log_follow_up"><input type="hidden" name="case_id" value="${c.id}">
            </form>`;

        let closeBlock = '';
        if (!c.closed_at) {
            closeBlock = section('Close this follow-up', `
                <div class="flex gap-2">
                    <label for="closeReason" class="sr-only">Reason for closing</label>
                    <select id="closeReason" class="flex-1 min-w-0 px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">
                        <option value="Unreachable">Could not reach them</option>
                        <option value="Not_Interested">Not interested for now</option>
                        <option value="Relocated">Has relocated</option>
                        <option value="Attends_Elsewhere">Attends another church</option>
                    </select>
                    <button type="button" onclick="closeCase(${c.id})" class="shrink-0 min-h-[44px] px-4 bg-white border border-gray-200 hover:border-red-300 text-gray-700 rounded-xl text-sm font-bold">Close</button>
                </div>`);
        }

        $('#drawerBody').html(
            section('Call essentials', essentials)
            + section('Attendance over the last year', timeline)
            + (actions ? section('Actions', `<div class="space-y-2">${actions}</div>`) : '')
            + section(c.closed_at ? 'This follow-up' : 'Log a follow-up', logForm)
            + section('What has happened', fu)
            + history
            + closeBlock
        );

        $('#logForm').on('submit', function(ev) {
            ev.preventDefault();
            const $btn = $(this).find('button[type=submit]').prop('disabled', true);
            $.post(API_URL, $(this).serialize(), function(res) {
                showToast(res.status === 'success' ? res.message : res.message, res.status === 'success' ? 'success' : 'error');
                if (res.status === 'success') { openCase(c.id); loadCases(); }
            }, 'json').fail(() => showToast('Server error', 'error')).always(() => $btn.prop('disabled', false));
        });
    }

    function cleanDrawerNotes() {
        const $form = $('#logForm');
        const notes = ($form.find('[name=notes]').val() || '').trim();
        if (!notes) { showToast('Write the notes first', 'error'); return; }
        const $b = $('#drawerCleanBtn').prop('disabled', true).text('Tidying…');
        $.post(API_URL, { action: 'clean_notes', notes: notes }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            $('#drawerClean').val(res.data.notes_clean);
            $b.after(`<p class="text-xs text-emerald-900 bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2 mt-2 whitespace-pre-line">${escapeHtml(res.data.notes_clean)}</p>`);
        }, 'json').fail(() => showToast('Server error', 'error'))
          .always(() => $b.prop('disabled', false).text('Clean up with AI'));
    }

    function assignCase(id, isReassign) {
        const to = $('#assignSelect').val();
        if (!to) { showToast('Choose a volunteer first', 'error'); return; }
        $.post(API_URL, { action: isReassign ? 'reassign' : 'assign_case', case_id: id, to_user_id: to }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function unassignCase(id) {
        $.post(API_URL, { action: 'unassign', case_id: id }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function claimCase(id) {
        $.post(API_URL, { action: 'self_claim', case_id: id }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function markHome(id) {
        const when = prompt('What date were they back in church? (YYYY-MM-DD)', ymd(new Date()));
        if (!when) return;
        $.post(API_URL, { action: 'mark_returned_home', case_id: id, came_on: when }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function reopenCase(id) {
        $.post(API_URL, { action: 'reopen_case', case_id: id }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function closeCase(id) {
        const status = $('#closeReason').val();
        if (!confirm('Close this follow-up? It stays in the record and can be reopened.')) return;
        $.post(API_URL, { action: 'close_case', case_id: id, status: status }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { openCase(id); loadCases(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    /* ============================ TAB 3 — TEAM ============================ */
    function initTeam() {
        if (state.team.ready) return;
        state.team.ready = true;
        loadTeam();
    }
    function loadTeam() {
        $('#teamGrid').html(skeleton());
        $.post(API_URL, { action: 'team_list' }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            state.team.rows = res.data;
            renderTeam();
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function renderTeam() {
        const rows = state.team.rows;
        if (!rows.length) {
            $('#teamGrid').html(emptyState('No volunteers yet',
                'Add the people who will make the calls. Anyone in the congregation can be added.',
                IS_MANAGER ? '<button type="button" onclick="openPicker()" class="mt-5 bg-emerald-700 hover:bg-emerald-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm">Add volunteers</button>' : ''));
            return;
        }
        $('#teamGrid').html(rows.map(t => `
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 space-y-4">
                <div class="flex items-center gap-3">
                    ${t.picture_path
                        ? `<img src="${escapeHtml(t.picture_path)}" alt="" class="w-12 h-12 rounded-full object-cover border border-gray-100">`
                        : `<span class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-800 font-display font-bold flex items-center justify-center" aria-hidden="true">${escapeHtml((t.name || '?').charAt(0))}</span>`}
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 truncate">${escapeHtml(t.name)}</p>
                        <p class="text-xs text-gray-500 truncate">${escapeHtml(t.phone || '')} · ${escapeHtml(label(t.spiritual_status))}</p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-gray-50 rounded-xl py-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Open</p><p class="text-sm font-black text-gray-800 mt-0.5">${t.open_cases}</p></div>
                    <div class="bg-blue-50 rounded-xl py-2"><p class="text-[9px] font-bold text-blue-500 uppercase">This month</p><p class="text-sm font-black text-blue-800 mt-0.5">${t.contacts_this_month}</p></div>
                    <div class="bg-emerald-50 rounded-xl py-2"><p class="text-[9px] font-bold text-emerald-600 uppercase">Home</p><p class="text-sm font-black text-emerald-800 mt-0.5">${t.returned_home}</p></div>
                </div>
                <p class="text-[11px] text-gray-500">Last active ${t.last_active ? escapeHtml(relTime(t.last_active)) : 'never'}</p>
                ${IS_MANAGER ? `<button type="button" onclick="removeVolunteer(${t.user_id}, '${escapeHtml(t.name).replace(/'/g, "&#39;")}')" class="w-full min-h-[44px] bg-red-50 hover:bg-red-100 text-red-700 rounded-xl text-xs font-bold">Remove from the team</button>` : ''}
            </div>`).join(''));
    }
    function removeVolunteer(id, name) {
        if (!confirm(`Remove ${name} from the Assimilation team?\n\nTheir follow-up history is kept.`)) return;
        $.post(API_URL, { action: 'team_remove', user_id: id }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { state.team.rows = res.data; renderTeam(); boot(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function openPicker() {
        state.team.picked = new Set();
        $('#pickCount').text('0');
        $('#pickerSearch').val('');
        searchPicker('');
        openModal('pickerModal');
        setTimeout(() => $('#pickerSearch').trigger('focus'), 320);
    }
    let pickerTimer;
    $('#pickerSearch').on('input', function() {
        clearTimeout(pickerTimer);
        const q = this.value;
        pickerTimer = setTimeout(() => searchPicker(q), 300);
    });
    function searchPicker(q) {
        $('#pickerList').html(skeleton(2));
        $.post(API_URL, { action: 'search_users', q: q }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            if (!res.data.length) {
                $('#pickerList').html('<p class="text-sm text-gray-500 text-center py-8">Nobody matched that search.</p>');
                return;
            }
            $('#pickerList').html(res.data.map(u => `
                <label class="flex items-center gap-3 p-3 rounded-2xl border border-gray-100 hover:border-emerald-200 cursor-pointer ${u.on_team ? 'opacity-60' : ''}">
                    <input type="checkbox" ${u.on_team ? 'disabled' : ''} ${state.team.picked.has(u.id) ? 'checked' : ''} onchange="pickUser(${u.id}, this.checked)" class="w-5 h-5 accent-emerald-700 shrink-0">
                    ${u.picture_path
                        ? `<img src="${escapeHtml(u.picture_path)}" alt="" class="w-10 h-10 rounded-full object-cover border border-gray-100 shrink-0">`
                        : `<span class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-800 font-display font-bold flex items-center justify-center shrink-0" aria-hidden="true">${escapeHtml((u.name || '?').charAt(0))}</span>`}
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold text-gray-900 text-sm truncate">${escapeHtml(u.name)} ${u.on_team ? pill('On the team', 'bg-emerald-100 text-emerald-800') : ''}</span>
                        <span class="block text-xs text-gray-500 truncate">${escapeHtml(u.phone || 'No phone')} · ${escapeHtml(label(u.spiritual_status))}${u.departments ? ' · ' + escapeHtml(u.departments) : ''}</span>
                        <span class="block text-[11px] text-gray-400">Last in church ${escapeHtml(u.since_words)}</span>
                    </span>
                </label>`).join(''));
        }, 'json').fail(() => showToast('Server error', 'error'));
    }
    function pickUser(id, on) {
        on ? state.team.picked.add(id) : state.team.picked.delete(id);
        $('#pickCount').text(state.team.picked.size);
    }
    function addPicked() {
        const ids = [...state.team.picked];
        if (!ids.length) { showToast('Pick at least one person', 'error'); return; }
        lockScreen();
        $.post(API_URL, { action: 'team_add', user_ids: ids }, function(res) {
            unlockScreen();
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { state.team.rows = res.data; renderTeam(); closeModal('pickerModal'); boot(); }
        }, 'json').fail(() => { unlockScreen(); showToast('Server error', 'error'); });
    }

    /* ============================ TAB 4 — ANALYTICS ============================ */
    // Emerald ordinal ramp for the funnel; two categorical hues for the
    // breakdowns. Both checked with the dataviz palette validator against a
    // light surface — the green carries a contrast WARN, so every bar is
    // directly labelled and the same numbers appear in a table beside it.
    const FUNNEL_COLORS = ['#10b981', '#059669', '#047857', '#064e3b'];
    const HOME_COLOR    = '#047857';
    const SERIES_COLORS = ['#2a78d6', '#1baf7a'];

    function presetRange(p) {
        const now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (p) {
            case 'last_month': return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
            case 'last_3':     return [ymd(new Date(y, m - 2, 1)), ymd(new Date(y, m + 1, 0))];
            case 'ytd':        return [`${y}-01-01`, ymd(now)];
            case 'custom':     return [$('#anFrom').val(), $('#anTo').val()];
            default:           return [ymd(new Date(y, m, 1)), ymd(new Date(y, m + 1, 0))];
        }
    }
    function initAnalytics() {
        if (state.an.ready) return;
        state.an.ready = true;
        const presets = [['this_month', 'This Month'], ['last_month', 'Last Month'], ['last_3', 'Last 3 Months'], ['ytd', 'Year to Date'], ['custom', 'Custom']];
        $('#anPresets').html(presets.map(([k, l]) => `<button type="button" data-p="${k}" onclick="pickPreset('${k}')" class="an-preset min-h-[44px] px-3 rounded-xl border text-xs font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">${l}</button>`).join(''));
        const [f, t] = presetRange('this_month');
        $('#anFrom').val(f); $('#anTo').val(t);
        $('#anFrom, #anTo').on('change', loadAnalytics);
        if (window.Chart) {
            Chart.defaults.font.family = 'Inter, sans-serif';
            Chart.defaults.color = '#52514e';
        }
        pickPreset('this_month');
    }
    function pickPreset(p) {
        state.an.preset = p;
        $('.an-preset').removeClass('bg-emerald-700 text-white border-emerald-700').addClass('bg-white text-gray-600 border-gray-200').attr('aria-pressed', 'false');
        $(`.an-preset[data-p="${p}"]`).removeClass('bg-white text-gray-600 border-gray-200').addClass('bg-emerald-700 text-white border-emerald-700').attr('aria-pressed', 'true');
        $('#anCustom').toggleClass('hidden', p !== 'custom');
        loadAnalytics();
    }
    function anCard(title, body, extra = '') {
        return `<section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 ${extra}"><h3 class="text-sm font-bold text-gray-900 mb-4">${title}</h3>${body}</section>`;
    }
    function loadAnalytics() {
        const [from, to] = presetRange(state.an.preset);
        if (!from || !to) return;
        Object.values(state.an.charts).forEach(c => c.destroy());
        state.an.charts = {};
        $('#anBody').html(`<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 animate-pulse" aria-hidden="true">${'<div class="h-24 bg-white border border-gray-100 rounded-3xl"></div>'.repeat(5)}</div><div class="grid lg:grid-cols-2 gap-5 mt-5 animate-pulse" aria-hidden="true">${'<div class="h-72 bg-white border border-gray-100 rounded-3xl"></div>'.repeat(2)}</div>`);
        $.post(API_URL, { action: 'fetch_analytics', from_date: from, to_date: to }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            renderAnalytics(res.data);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function renderAnalytics(d) {
        const k = d.kpis;
        const tile = (l, v, accent = 'text-gray-900') => `<div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5"><p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">${l}</p><p class="text-3xl font-display font-bold ${accent} mt-1">${v}</p></div>`;
        const th  = t => `<th class="py-2 px-2 text-[10px] font-bold text-gray-500 uppercase tracking-wider text-left">${t}</th>`;
        const thn = t => `<th class="py-2 px-2 text-[10px] font-bold text-gray-500 uppercase tracking-wider text-right">${t}</th>`;

        const breakdownTable = rows => rows.length
            ? `<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr>${th('')}${thn('Followed up')}${thn('Returned home')}</tr></thead><tbody>${rows.map(r => `<tr class="border-b border-gray-50 last:border-0"><td class="py-2 px-2 font-semibold text-gray-800">${escapeHtml(r.label)}</td><td class="py-2 px-2 text-right font-bold">${r.count}</td><td class="py-2 px-2 text-right font-bold text-emerald-800">${r.returned}</td></tr>`).join('')}</tbody></table></div>`
            : '<p class="text-sm text-gray-400 italic">Nothing in this period.</p>';

        const leaders = d.volunteer_leaderboard.length
            ? `<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr>${th('#')}${th('Volunteer')}${thn('Contacts')}${thn('Returned home')}</tr></thead><tbody>${d.volunteer_leaderboard.map((v, i) => `<tr class="border-b border-gray-50 last:border-0"><td class="py-2 px-2 text-gray-400">${i + 1}</td><td class="py-2 px-2 font-semibold text-gray-800">${escapeHtml(v.name)}</td><td class="py-2 px-2 text-right font-bold">${v.contacts}</td><td class="py-2 px-2 text-right font-bold text-emerald-800">${v.returned}</td></tr>`).join('')}</tbody></table></div>`
            : '<p class="text-sm text-gray-400 italic">No follow-up activity in this period.</p>';

        const watch = d.watchlists.length
            ? `<div class="overflow-x-auto"><table class="w-full text-sm min-w-[440px]"><thead class="bg-gray-50"><tr>${th('Watchlist')}${th('Rule')}${thn('People')}${thn('New')}</tr></thead><tbody>${d.watchlists.map(w => `<tr class="border-b border-gray-50 last:border-0"><td class="py-2 px-2 font-semibold text-gray-800">${escapeHtml(w.name)}${w.is_active ? '' : ' ' + pill('Paused', 'bg-gray-100 text-gray-600')}</td><td class="py-2 px-2 text-gray-500 text-xs">${escapeHtml(w.rule)}</td><td class="py-2 px-2 text-right font-bold">${w.people}</td><td class="py-2 px-2 text-right">${w.new_in_range}</td></tr>`).join('')}</tbody></table></div>`
            : '<p class="text-sm text-gray-400 italic">No watchlists saved yet.</p>';

        const legend = `<p class="flex items-center gap-4 text-xs font-semibold text-gray-600 -mt-2 mb-3">
            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" style="background:${SERIES_COLORS[0]}"></span>Followed up</span>
            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" style="background:${SERIES_COLORS[1]}"></span>Returned home</span></p>`;

        $('#anBody').html(`
            <div id="anPdfReady"></div>
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                ${tile('On a watchlist', k.drifted)}
                ${tile('Followed up', k.contacted)}
                ${tile('Returned home', k.returned_home, 'text-emerald-800')}
                ${tile('Return rate', k.return_rate + '%', 'text-emerald-800')}
                ${tile('Median days to 1st call', k.median_days === null ? '—' : k.median_days + 'd')}
            </div>
            ${k.overdue ? `<button type="button" onclick="switchTab('followup'); state.cases.overdue = true; loadCases();" class="mt-4 w-full text-left flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800 hover:bg-red-100">${icon('M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z')}${k.overdue} person(s) assigned more than ${OVERDUE_DAYS} days ago with no contact — view them</button>` : ''}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
                ${anCard('The journey home', '<div class="h-64"><canvas id="chFunnel" role="img" aria-label="Journey home: ' + d.funnel.map(f => f.stage + ' ' + f.count).join(', ') + '"></canvas></div>')}
                ${anCard('Returned home by month', '<div class="h-64"><canvas id="chTrend" role="img" aria-label="People who returned home each month: ' + d.returned_trend.map(t => t.label + ' ' + t.count).join(', ') + '"></canvas></div>')}
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
                ${anCard('By spiritual status', legend + (d.status_breakdown.length ? `<div style="height:${Math.max(150, d.status_breakdown.length * 42 + 30)}px"><canvas id="chStatus" role="img" aria-label="Follow-ups and returns by spiritual status"></canvas></div>` : '') + breakdownTable(d.status_breakdown))}
                ${anCard('By department', legend + (d.department_breakdown.length ? `<div style="height:${Math.max(150, d.department_breakdown.length * 42 + 30)}px"><canvas id="chDept" role="img" aria-label="Follow-ups and returns by department"></canvas></div>` : '') + breakdownTable(d.department_breakdown))}
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">${anCard('Volunteer leaderboard', leaders)}${anCard('Watchlists', watch)}</div>`);

        if (!window.Chart) return;
        const grid = { color: '#eeeeea' };
        state.an.charts.funnel = new Chart(document.getElementById('chFunnel'), {
            type: 'bar',
            data: { labels: d.funnel.map(f => `${f.stage} (${f.count})`), datasets: [{ data: d.funnel.map(f => f.count), backgroundColor: FUNNEL_COLORS, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 72 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } }, x: { grid: { display: false } } } }
        });
        state.an.charts.trend = new Chart(document.getElementById('chTrend'), {
            type: 'line',
            data: { labels: d.returned_trend.map(t => t.label), datasets: [{ data: d.returned_trend.map(t => t.count), borderColor: HOME_COLOR, backgroundColor: HOME_COLOR, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, tension: 0.25, fill: false }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } }, x: { grid: { display: false } } } }
        });
        const grouped = (canvasId, rows) => {
            if (!rows.length) return;
            state.an.charts[canvasId] = new Chart(document.getElementById(canvasId), {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [
                        { label: 'Followed up', data: rows.map(r => r.count), backgroundColor: SERIES_COLORS[0], borderRadius: 4, borderSkipped: 'start', maxBarThickness: 12 },
                        { label: 'Returned home', data: rows.map(r => r.returned), backgroundColor: SERIES_COLORS[1], borderRadius: 4, borderSkipped: 'start', maxBarThickness: 12 }
                    ]
                },
                options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } }, y: { grid: { display: false } } } }
            });
        };
        grouped('chStatus', d.status_breakdown);
        grouped('chDept', d.department_breakdown);
    }

    function generatePdf() {
        const [from, to] = presetRange(state.an.preset);
        const $btn = $('#anPdfBtn').prop('disabled', true).text('Generating…');
        $.post(API_URL, { action: 'generate_pdf', from_date: from, to_date: to }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            showToast('Report ready');
            $('#anPdfReady').html(`<div class="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3"><span class="text-sm font-bold text-emerald-900">Your Assimilation report is ready.</span><a href="${escapeHtml(res.data.url)}" target="_blank" rel="noopener" class="shrink-0 bg-emerald-700 hover:bg-emerald-900 text-white px-4 py-2 rounded-xl text-sm font-bold">Open PDF</a></div>`);
        }, 'json').fail(() => showToast('Server error', 'error'))
          .always(() => $btn.prop('disabled', false).text('Monthly PDF report'));
    }

    /* ============================ SETTINGS ============================ */
    function openSettingsModal(focus) {
        $.post(API_URL, { action: 'fetch_settings' }, function(res) {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            $('#setOverdueDays').val(res.data.overdue_days);
            $('#setSelfClaim').prop('checked', !!res.data.allow_self_claim);
            $('#setRuleMax').val(res.data.default_rule.max_services);
            $('#setRuleWindow').val(res.data.default_rule.window_value);
            $('#setRuleUnit').val(res.data.default_rule.window_unit);
            $('#setRuleEver').prop('checked', !!res.data.default_rule.ever_attended);
            $('#setGuide').val(res.data.guide);
            if (focus === 'guide') setTimeout(() => $('#setGuide').trigger('focus'), 340);
        }, 'json');
        openModal('settingsModal');
    }
    $('#settingsForm').on('submit', function(ev) {
        ev.preventDefault();
        $.post(API_URL, $(this).serialize() + '&action=save_settings', function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
        }, 'json').fail(() => showToast('Server error', 'error'));
    });
    function restoreGuide() {
        if (!confirm('Replace the guide with the built-in version?\n\nYour edits will be lost.')) return;
        $('#setGuide').val('');
        $.post(API_URL, $('#settingsForm').serialize() + '&action=save_settings', function(res) {
            showToast(res.status === 'success' ? 'Default guide restored — reload to see it.' : res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') openSettingsModal();
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    $(document).on('keydown', ev => { if (ev.key === 'Escape') closeDrawer(); });
    $(document).ready(boot);
</script>

<?php require_once '../../includes/footer.php'; ?>
