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
                    <button type="button" onclick="openWatchlists()" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Watchlists <span id="wlCount" class="ml-1 text-gray-400"></span></button>
                    <button type="button" onclick="openSaveWatchlist()" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Save as watchlist</button>
                    <button type="button" onclick="exportCsv()" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Export CSV</button>
                    <button type="button" onclick="runFind()" class="px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-900 text-white text-sm font-bold shadow-lg shadow-emerald-900/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Find people</button>
                </div>
            </div>
        </section>

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
    function exportCsv() {
        const form = $('<form method="post" target="_blank">').attr('action', API_URL);
        form.append($('<input type="hidden" name="action" value="export_csv">'));
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
            $('#wlList').html(`<p class="text-sm text-gray-500 text-center py-6">No watchlists yet. Build a rule above, then tap <strong>Save as watchlist</strong>.</p>`);
            return;
        }
        $('#wlList').html(list.map(w => `
            <div class="border ${w.is_active ? 'border-gray-100' : 'border-dashed border-gray-200 opacity-70'} rounded-2xl p-4 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900">${escapeHtml(w.name)} ${w.is_active ? '' : pill('Paused', 'bg-gray-100 text-gray-600')}</p>
                        <p class="text-xs text-gray-500 mt-0.5">${escapeHtml(w.summary)}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-display font-bold text-xl text-gray-900">${w.people}</p>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">people</p>
                    </div>
                </div>
                ${w.untouched ? `<p class="text-xs font-bold text-red-700 bg-red-50 border border-red-100 rounded-xl px-3 py-2">${w.untouched} of them have nobody calling yet</p>` : ''}
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick='loadWatchlist(${JSON.stringify(w.rule)})' class="min-h-[44px] px-4 rounded-xl bg-emerald-700 hover:bg-emerald-900 text-white text-xs font-bold">Open in Find people</button>
                    <button type="button" onclick="toggleWatchlist(${w.id}, ${w.is_active ? 0 : 1})" class="min-h-[44px] px-4 rounded-xl border border-gray-200 text-gray-700 text-xs font-bold hover:border-emerald-300">${w.is_active ? 'Pause digest' : 'Turn on'}</button>
                    <button type="button" onclick="deleteWatchlist(${w.id}, '${escapeHtml(w.name).replace(/'/g, "&#39;")}')" class="min-h-[44px] px-4 rounded-xl bg-red-50 hover:bg-red-100 text-red-700 text-xs font-bold ml-auto">Delete</button>
                </div>
            </div>`).join(''));
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
        openModal('saveWlModal');
        setTimeout(() => $('#wlName').trigger('focus'), 320);
    }
    $('#saveWlForm').on('submit', function(ev) {
        ev.preventDefault();
        $.post(API_URL, {
            action: 'save_watchlist', name: $('#wlName').val().trim(),
            notify: $('#wlNotify').is(':checked') ? 1 : 0, rule: JSON.stringify(currentRule())
        }, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success') { closeModal('saveWlModal'); refreshWatchlistCount(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
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
