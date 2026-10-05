<?php
// /modules/charis/index.php
require_once '../../includes/header.php';
if (!isset($_SESSION['user_id'])) { echo "<script>window.location.href='/auth/login.php';</script>"; exit; }
?>
<script src="/assets/js/celebrants_export.js"></script>
<link  href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="max-w-7xl mx-auto space-y-8 pb-10">

<!-- ════════════════════════════════════════════════════════
     MASTER TAB NAV  (5 tabs)
════════════════════════════════════════════════════════ -->
<div class="overflow-x-auto no-scrollbar mt-4">
    <div class="flex bg-gray-100 p-1.5 rounded-2xl w-fit mx-auto border border-gray-200/60 shadow-inner gap-1 min-w-max">
        <button onclick="switchTab('welfare')"      id="tab-welfare"      class="tab-btn active-tab  px-5 py-2.5 rounded-xl text-sm font-bold transition-all whitespace-nowrap">Welfare</button>
        <button onclick="switchTab('events')"       id="tab-events"       class="tab-btn inactive-tab px-5 py-2.5 rounded-xl text-sm font-bold transition-all whitespace-nowrap">Event Planning</button>
        <button onclick="switchTab('finance')"      id="tab-finance"      class="tab-btn inactive-tab px-5 py-2.5 rounded-xl text-sm font-bold transition-all whitespace-nowrap">Finance</button>
        <button onclick="switchTab('library')"      id="tab-library"      class="tab-btn inactive-tab px-5 py-2.5 rounded-xl text-sm font-bold transition-all whitespace-nowrap">Library</button>
        <button onclick="switchTab('howto')"        id="tab-howto"        class="tab-btn inactive-tab px-5 py-2.5 rounded-xl text-sm font-bold transition-all whitespace-nowrap">How To Use</button>
    </div>
</div>
<style>
.active-tab   { background:#fff; color:#111827; box-shadow:0 1px 3px rgba(0,0,0,.12); border:1px solid rgba(229,231,235,.5); }
.inactive-tab { color:#6b7280; }
.inactive-tab:hover { color:#111827; }
</style>

<!-- ════════════════════════════════════════════════════════
     VIEW 1 — WELFARE
════════════════════════════════════════════════════════ -->
<div id="view-welfare" class="space-y-4 xl:space-y-8">

    <!-- Compact section launchers keep every welfare area visible at first glance on phones and tablets. -->
    <section class="xl:hidden" aria-label="Welfare sections">
        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            <button type="button" onclick="openModal('birthdaysOverviewModal')" aria-haspopup="dialog" class="group min-w-0 min-h-[116px] sm:min-h-[128px] rounded-2xl sm:rounded-3xl border border-blue-100 bg-gradient-to-br from-white to-blue-50 p-2.5 sm:p-4 text-center shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <span class="mx-auto flex h-10 w-10 sm:h-12 sm:w-12 items-center justify-center rounded-xl sm:rounded-2xl border border-blue-100 bg-white text-hodBlue shadow-sm transition-transform group-hover:scale-105">
                    <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15.546c-.523 0-1.046.151-1.5.454a2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.701 2.701 0 00-1.5-.454M9 6v2m3-2v2m3-2v2M9 3h.01M12 3h.01M15 3h.01M21 21v-7a2 2 0 00-2-2H5a2 2 0 00-2 2v7h18zm-3-9v-2a2 2 0 00-2-2H8a2 2 0 00-2 2v2h12z"></path></svg>
                </span>
                <span class="mt-2 block text-[10px] sm:text-xs font-black leading-tight text-gray-900">Upcoming Birthdays</span>
                <span id="birthdayCardCount" class="mt-1 block text-[9px] sm:text-[10px] font-bold text-blue-600">Loading…</span>
            </button>

            <button type="button" onclick="openModal('lifeEventsOverviewModal')" aria-haspopup="dialog" class="group min-w-0 min-h-[116px] sm:min-h-[128px] rounded-2xl sm:rounded-3xl border border-red-100 bg-gradient-to-br from-white to-red-50 p-2.5 sm:p-4 text-center shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">
                <span class="mx-auto flex h-10 w-10 sm:h-12 sm:w-12 items-center justify-center rounded-xl sm:rounded-2xl border border-red-100 bg-white text-hodRed shadow-sm transition-transform group-hover:scale-105">
                    <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                </span>
                <span class="mt-2 block text-[10px] sm:text-xs font-black leading-tight text-gray-900">Life Events</span>
                <span id="lifeEventsCardCount" class="mt-1 block text-[9px] sm:text-[10px] font-bold text-red-600">Loading…</span>
            </button>

            <button type="button" onclick="openModal('welfareOverviewModal')" aria-haspopup="dialog" class="group min-w-0 min-h-[116px] sm:min-h-[128px] rounded-2xl sm:rounded-3xl border border-orange-100 bg-gradient-to-br from-white to-orange-50 p-2.5 sm:p-4 text-center shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2">
                <span class="mx-auto flex h-10 w-10 sm:h-12 sm:w-12 items-center justify-center rounded-xl sm:rounded-2xl border border-orange-100 bg-white text-orange-500 shadow-sm transition-transform group-hover:scale-105">
                    <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </span>
                <span class="mt-2 block text-[10px] sm:text-xs font-black leading-tight text-gray-900">Welfare</span>
                <span id="welfareCardCount" class="mt-1 block text-[9px] sm:text-[10px] font-bold text-orange-600">Loading…</span>
            </button>
        </div>
    </section>

    <!-- Small, equal action tiles replace the former oversized title and button banner. -->
    <section class="rounded-2xl sm:rounded-3xl border border-gray-100 bg-white p-2 sm:p-3 shadow-sm" aria-label="Welfare actions">
        <div class="mx-auto grid max-w-2xl grid-cols-3 gap-1.5 sm:gap-3">
            <button type="button" onclick="openModal('awolReportModal')" class="group flex min-w-0 flex-col items-center justify-center rounded-xl sm:rounded-2xl px-1.5 py-2.5 sm:py-3 text-center transition-colors hover:bg-orange-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500">
                <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-orange-50 text-orange-600 transition-colors group-hover:bg-orange-100">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6m4 6V7m4 10v-3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </span>
                <span class="mt-1.5 text-[9px] sm:text-[10px] font-bold leading-tight text-gray-700">AWOL Report</span>
            </button>
            <button type="button" onclick="openModal('envisionRecapModal'); loadEnvisionRecap();" class="group flex min-w-0 flex-col items-center justify-center rounded-xl sm:rounded-2xl px-1.5 py-2.5 sm:py-3 text-center transition-colors hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition-colors group-hover:bg-indigo-100">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4m10-2v4m-2-2h4M6 17v4m-2-2h4m4-12l1.1 3.4a2 2 0 001.3 1.3L18 13l-3.6 1.2a2 2 0 00-1.3 1.3L12 19l-1.1-3.5a2 2 0 00-1.3-1.3L6 13l3.6-1.3a2 2 0 001.3-1.3L12 7z"></path></svg>
                </span>
                <span class="mt-1.5 text-[9px] sm:text-[10px] font-bold leading-tight text-gray-700">Monthly Recap</span>
            </button>
            <button type="button" onclick="openModal('addLifeEventModal')" class="group flex min-w-0 flex-col items-center justify-center rounded-xl sm:rounded-2xl px-1.5 py-2.5 sm:py-3 text-center transition-colors hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-red-50 text-hodRed transition-colors group-hover:bg-red-100">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-5 4v6m-3-3h6M5 13V7a2 2 0 012-2h10a2 2 0 012 2v6"></path></svg>
                </span>
                <span class="mt-1.5 text-[9px] sm:text-[10px] font-bold leading-tight text-gray-700">Log Life Event</span>
            </button>
        </div>
    </section>

    <div class="hidden xl:grid xl:grid-cols-3 gap-8">
        <!-- Birthdays -->
        <div class="relative bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden flex flex-col h-[550px] group">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-indigo-50/40 to-sky-100/60 transition-transform duration-700 group-hover:scale-105"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-white/95 via-white/90 to-white/95 backdrop-blur-[2px]"></div>
            <div class="relative z-10 p-6 border-b border-gray-100/80 bg-blue-50/30 flex items-center gap-4">
                <div class="h-12 w-12 bg-white shadow-sm border border-blue-100 text-hodBlue rounded-2xl flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15.546c-.523 0-1.046.151-1.5.454a2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.704 2.704 0 01-3 0 2.701 2.701 0 00-1.5-.454M9 6v2m3-2v2m3-2v2M9 3h.01M12 3h.01M15 3h.01M21 21v-7a2 2 0 00-2-2H5a2 2 0 00-2 2v7h18zm-3-9v-2a2 2 0 00-2-2H8a2 2 0 00-2 2v2h12z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-display font-bold text-gray-900">Upcoming Birthdays</h3>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500 mt-0.5">This Month & Next</p>
                </div>
            </div>
            <div class="relative z-10 p-4 flex-1 overflow-y-auto custom-scrollbar">
                <ul id="birthdaysList" class="space-y-3">
                    <li class="flex flex-col items-center justify-center py-10 text-gray-400"><svg class="animate-spin h-8 w-8 text-hodBlue mb-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-sm font-medium animate-pulse">Loading...</span></li>
                </ul>
            </div>
        </div>

        <!-- Life Events -->
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden flex flex-col h-[550px]">
            <div class="p-6 border-b border-gray-100/80 bg-gradient-to-r from-red-50/50 to-white flex items-center gap-4">
                <div class="h-12 w-12 bg-white shadow-sm border border-red-100 text-hodRed rounded-2xl flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-display font-bold text-gray-900">Life Events</h3>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-red-500 mt-0.5">Weddings & Milestones</p>
                </div>
            </div>
            <div class="p-4 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/30">
                <ul id="anniversariesList" class="space-y-3"><li class="flex flex-col items-center py-10 text-gray-400"><svg class="animate-spin h-8 w-8 text-hodRed mb-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg></li></ul>
            </div>
        </div>

        <!-- Urgent Welfare / AWOL -->
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden flex flex-col h-[550px]">
            <div class="p-6 border-b border-gray-100/80 bg-gradient-to-r from-orange-50/50 to-white flex flex-col gap-4 shrink-0">
                <div class="flex items-center gap-4">
                    <div class="h-12 w-12 bg-white shadow-sm border border-orange-100 text-orange-500 rounded-2xl flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-display font-bold text-gray-900">Urgent Welfare</h3>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-orange-500 mt-0.5">AWOL Alerts</p>
                    </div>
                </div>
                <div role="tablist" aria-label="Welfare views" class="flex bg-orange-50/50 p-1 rounded-xl border border-orange-100/50 overflow-x-auto no-scrollbar snap-x">
                    <button type="button" role="tab" aria-selected="true" data-welfare-tab="my_cases" onclick="toggleWelfareTab('my_cases')" id="tabWelMyCases" class="welfare-tab-button px-4 py-2 rounded-lg text-xs font-bold bg-white text-orange-600 shadow-sm transition-all whitespace-nowrap flex-1">My Cases</button>
                    <button type="button" role="tab" aria-selected="false" data-welfare-tab="master" onclick="toggleWelfareTab('master')" id="tabWelMaster" class="welfare-tab-button px-4 py-2 rounded-lg text-xs font-bold text-gray-500 hover:text-gray-900 transition-all whitespace-nowrap flex-1">Master List</button>
                    <button type="button" role="tab" aria-selected="false" data-welfare-tab="archive" onclick="toggleWelfareTab('archive')" id="tabWelArchive" class="welfare-tab-button px-4 py-2 rounded-lg text-xs font-bold text-gray-500 hover:text-gray-900 transition-all whitespace-nowrap flex-1">Archive</button>
                </div>
            </div>
            <div class="p-4 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/30">
                <ul id="welfareList" class="welfare-list space-y-3"></ul>
            </div>
        </div>
    </div>

</div><!-- /view-welfare -->

<!-- ════════════════════════════════════════════════════════
     VIEW 2 — EVENT PLANNING
════════════════════════════════════════════════════════ -->
<div id="view-events" class="hidden space-y-8">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Event Planning</h2>
                <p class="text-gray-500 text-sm mt-1">Assign tasks per member per event, track budgets, collect reports.</p>
            </div>
        </div>
    </div>

    <!-- Service Planner Pipeline -->
    <div class="bg-gradient-to-br from-gray-900 via-gray-800 to-black rounded-3xl shadow-2xl p-8 text-white relative overflow-hidden">
        <div class="absolute top-0 right-0 w-80 h-80 bg-blue-500/10 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none"></div>
        <div class="relative z-10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center border border-white/20 shrink-0">
                    <svg class="w-7 h-7 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
                <div>
                    <h3 class="text-2xl font-display font-black tracking-tight">Service Planner Pipeline</h3>
                    <p class="text-gray-400 text-sm mt-1">Bulk-generate Sunday Services for any month.</p>
                </div>
            </div>
            <form id="bulkServiceForm" class="flex flex-wrap items-center gap-3 bg-white/5 p-2.5 rounded-2xl border border-white/10 w-full lg:w-auto">
                <input type="hidden" name="action" value="bulk_create_services">
                <div class="relative flex-1 lg:flex-none">
                    <select name="month" required class="w-full bg-transparent border-none text-white text-sm font-bold outline-none cursor-pointer pl-4 pr-8 py-2 appearance-none">
                        <option value="" disabled selected class="text-gray-500">Select Month</option>
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" class="text-gray-900"><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"><svg class="w-4 h-4 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg></div>
                </div>
                <button type="submit" class="bg-white text-gray-900 hover:bg-blue-50 px-6 py-2.5 rounded-xl font-bold transition-all shadow-lg w-full lg:w-auto">Generate</button>
            </form>
        </div>
    </div>

    <!-- Event Task Pipeline Table -->
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
        <div class="p-6 md:p-8 border-b border-gray-100/80 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
            <div>
                <h3 class="text-xl font-display font-bold text-gray-900">Event Task Pipeline</h3>
                <p class="text-sm text-gray-500 mt-1">Assign individual tasks to members per event. Each person sees only their task.</p>
            </div>
            <div class="flex bg-gray-100 p-1 rounded-xl gap-1">
                <button onclick="toggleLogisticsTab('upcoming')" id="tabUpcoming" class="px-4 py-2 rounded-lg text-sm font-bold bg-white text-gray-900 shadow-sm transition-all">Upcoming</button>
                <button onclick="toggleLogisticsTab('past')"     id="tabPast"     class="px-4 py-2 rounded-lg text-sm font-bold text-gray-500 hover:text-gray-900 transition-all">Past</button>
            </div>
        </div>
        <div class="overflow-x-auto custom-scrollbar min-h-[300px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 pl-8">Event Date</th>
                        <th class="px-6 py-4">Event Title</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4 pr-8 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="logisticsTableBody" class="divide-y divide-gray-50">
                    <tr><td colspan="4" class="px-6 py-12 text-center text-gray-400">Loading events...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /view-events -->

<!-- ════════════════════════════════════════════════════════
     VIEW 3 — FINANCE
════════════════════════════════════════════════════════ -->
<div id="view-finance" class="hidden space-y-8">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-green-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Finance</h2>
                <p class="text-gray-500 text-sm mt-1">Monthly budget vs actuals, expense tracking, and financial reports.</p>
            </div>
        </div>
        <div class="relative z-10 flex flex-wrap gap-3">
            <button onclick="openModal('setBudgetModal')" class="bg-green-600 hover:bg-green-700 text-white px-5 py-3 rounded-xl font-bold transition-all shadow-lg flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                Set Budget
            </button>
            <button onclick="openModal('addExpenseModal')" class="bg-hodBlue hover:bg-gray-900 text-white px-5 py-3 rounded-xl font-bold transition-all shadow-lg flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Log Expense
            </button>
        </div>
    </div>

    <!-- Month Selector -->
    <div class="flex items-center gap-3 bg-white rounded-2xl px-5 py-3 shadow-sm border border-gray-100 w-fit">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Period:</span>
        <select id="financeMonthSel" class="text-sm font-bold outline-none bg-transparent cursor-pointer text-gray-800">
            <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($m==date('n')?'selected':'')?>><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
        </select>
        <select id="financeYearSel" class="text-sm font-bold outline-none bg-transparent cursor-pointer text-gray-800">
            <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
        </select>
        <button onclick="loadFinanceData()" class="bg-gray-900 text-white px-4 py-1.5 rounded-lg text-xs font-bold hover:bg-black transition-all">Load</button>
    </div>

    <!-- Budget vs Actuals Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Monthly Budget</p>
            <p id="finBudgetAmt" class="text-3xl font-black text-gray-900 mt-2">₦—</p>
            <p id="finBudgetNote" class="text-xs text-gray-400 mt-1"></p>
        </div>
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Actual Spent</p>
            <p id="finActualAmt" class="text-3xl font-black text-green-600 mt-2">₦—</p>
        </div>
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Variance</p>
            <p id="finVarianceAmt" class="text-3xl font-black mt-2">₦—</p>
            <p id="finVarianceLbl" class="text-xs text-gray-400 mt-1"></p>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
            <h3 class="text-base font-bold text-gray-900 mb-4">6-Month Budget vs Actual</h3>
            <canvas id="barChart" height="200"></canvas>
        </div>
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
            <h3 class="text-base font-bold text-gray-900 mb-4">Spending by Category</h3>
            <div class="flex items-center justify-center h-[200px]">
                <canvas id="pieChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Forecasting -->
    <div id="financialForecastingWidget" class="hidden">
        <h3 class="text-base font-bold text-gray-900 mb-4">3-Month Rolling Forecast</h3>
        <div id="forecastCards" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4"></div>
    </div>

    <!-- Expense Ledger -->
    <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-sm border border-gray-200/60 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-display font-bold text-gray-900">Expense Ledger</h3>
            <span id="ledgerMonthLabel" class="text-xs font-bold text-green-600 bg-green-50 border border-green-100 px-3 py-1.5 rounded-lg"></span>
        </div>
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-sm text-gray-600 min-w-[800px]">
                <thead class="bg-white text-gray-400 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 pl-8">Item / Purpose</th>
                        <th class="px-6 py-4">Category</th>
                        <th class="px-6 py-4 text-right">Requested (₦)</th>
                        <th class="px-6 py-4 text-right">Approved (₦)</th>
                        <th class="px-6 py-4 text-center pr-8">Status</th>
                    </tr>
                </thead>
                <tbody id="expenseLedgerBody" class="divide-y divide-gray-50">
                    <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400">Select a period and click Load.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /view-finance -->

<!-- ════════════════════════════════════════════════════════
     VIEW 4 — LIBRARY
════════════════════════════════════════════════════════ -->
<div id="view-library" class="hidden space-y-8">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Library Admin</h2>
                <p class="text-gray-500 text-sm mt-1">Manage catalog, process pickups, and oversee waitlists.</p>
            </div>
        </div>
        <button onclick="openModal('addBookModal')" class="relative z-10 bg-hodBlue hover:bg-gray-900 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-lg flex items-center gap-2 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg> Add New Book
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden flex flex-col h-[550px] lg:col-span-2">
            <div class="p-6 border-b border-gray-100/80 bg-gradient-to-r from-green-50/50 to-white flex items-center gap-3">
                <div class="w-10 h-10 bg-green-100 text-green-600 rounded-xl flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                <div><h3 class="text-lg font-display font-bold text-gray-900">Active & Reserved Borrows</h3><p class="text-[10px] font-bold text-green-600 uppercase tracking-widest mt-0.5">Needs Attention</p></div>
            </div>
            <div class="p-4 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/30"><div id="adminBorrowsList" class="space-y-3"><div class="flex flex-col items-center py-10 text-gray-400"><svg class="animate-spin h-8 w-8 text-green-500 mb-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg></div></div></div>
        </div>
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden flex flex-col h-[550px]">
            <div class="p-6 border-b border-gray-100/80 bg-gradient-to-r from-orange-50/50 to-white flex items-center gap-3">
                <div class="w-10 h-10 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                <div><h3 class="text-lg font-display font-bold text-gray-900">Waitlist</h3><p class="text-[10px] font-bold text-orange-600 uppercase tracking-widest mt-0.5">Pending Availability</p></div>
            </div>
            <div class="p-4 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/30"><div id="adminWaitlist" class="space-y-3"></div></div>
        </div>
    </div>
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
        <div class="p-6 border-b border-gray-100/80 bg-gray-50/30 flex justify-between items-center">
            <h3 class="text-lg font-display font-bold text-gray-900">Master Catalog</h3>
            <span class="text-xs font-bold text-gray-500 bg-white px-3 py-1.5 rounded-lg shadow-sm border border-gray-200" id="catalogCountBadge">0 Books</span>
        </div>
        <div class="overflow-x-auto custom-scrollbar max-h-[400px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-white text-gray-500 font-bold uppercase tracking-wider text-[10px] sticky top-0 border-b border-gray-100 shadow-sm z-10">
                    <tr>
                        <th class="px-6 py-4 pl-8">Book Title</th><th class="px-6 py-4">Author</th>
                        <th class="px-6 py-4">Format</th><th class="px-6 py-4 text-right">Physical Inventory</th>
                        <th class="px-6 py-4 pr-8 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminCatalogList" class="divide-y divide-gray-50"><tr><td colspan="5" class="px-6 py-12 text-center text-gray-400">Loading catalog...</td></tr></tbody>
            </table>
        </div>
    </div>

</div><!-- /view-library -->

<!-- ════════════════════════════════════════════════════════
     VIEW 5 — HOW TO USE
════════════════════════════════════════════════════════ -->
<div id="view-howto" class="hidden space-y-6">

    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-14 h-14 bg-purple-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900">How to Use the Charis Module</h2>
                <p class="text-gray-500 text-sm mt-1">A complete guide for all Charis team members and HODs.</p>
            </div>
        </div>

        <!-- Guide Sections -->
        <div class="space-y-6">

            <!-- WELFARE -->
            <details class="group bg-orange-50/50 border border-orange-100 rounded-2xl overflow-hidden" open>
                <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 bg-orange-500 text-white rounded-lg flex items-center justify-center text-sm font-black">1</span>
                        <span class="font-bold text-gray-900 text-base">Welfare Tab — AWOL Alerts &amp; Tracking</span>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </summary>
                <div class="px-5 pb-5 space-y-4 text-sm text-gray-700 leading-relaxed border-t border-orange-100">
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mt-4">
                        <p class="font-black text-red-700 text-xs uppercase tracking-wider mb-2">⚠ Why Names Stay on the AWOL List — Read This First</p>
                        <p>A member stays on the <strong>AWOL list</strong> as long as they have been absent from the last 3 consecutive Sunday services. Simply contacting them or writing a report <em>does not automatically remove them</em>. To remove a name, you must do one of two things:</p>
                        <ul class="list-disc pl-5 mt-2 space-y-1">
                            <li><strong>Click "Manage Case" → Choose a Status → Click "Resolve &amp; Close Case"</strong> — this marks the assignment Resolved and removes the name from the active list.</li>
                            <li><strong>Change their Attendance Status</strong> to <code class="bg-red-100 px-1 rounded">Relocated</code>, <code class="bg-red-100 px-1 rounded">Attends_Another_Church</code>, or <code class="bg-red-100 px-1 rounded">Unknown</code> — these tell the system this person is no longer expected to attend regularly.</li>
                        </ul>
                        <p class="mt-2 text-red-600 font-semibold">If you only save a note without resolving, they will remain on the list until they physically return to church or their case is resolved.</p>
                    </div>

                    <div class="space-y-3">
                        <p class="font-bold text-gray-900">Step-by-step: How to handle an AWOL case</p>
                        <div class="flex gap-3 items-start"><span class="bg-orange-100 text-orange-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span><p><strong>Check "My Cases" tab</strong> in the Urgent Welfare card. These are cases assigned directly to you by the HOD.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-orange-100 text-orange-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span><p><strong>Click "Manage Case"</strong>. Use the Call or WhatsApp buttons to contact the member directly from the modal.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-orange-100 text-orange-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span><p><strong>Fill in your findings</strong> — what did the member say? Are they coming back? Did they relocate?</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-orange-100 text-orange-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">4</span><p><strong>Select the correct Attendance Status</strong>: Active (re-engaged), Inconsistent, Unknown, Relocated, or Attends Another Church.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-orange-100 text-orange-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">5</span><p><strong>Click "Resolve &amp; Close Case"</strong>. The member's name will disappear from the AWOL list for this cycle.</p></div>
                    </div>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4">
                        <p class="font-bold text-blue-800 mb-1">Generating the AWOL Report</p>
                        <p>Click the orange <strong>"AWOL Report"</strong> button in the Welfare header. Choose a period — either a specific Month &amp; Year, or a custom date range. The report is sent directly to the pastor and includes all cases (resolved and pending), with member contact details clearly listed.</p>
                    </div>
                </div>
            </details>

            <!-- EVENT PLANNING -->
            <details class="group bg-blue-50/50 border border-blue-100 rounded-2xl overflow-hidden">
                <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 bg-hodBlue text-white rounded-lg flex items-center justify-center text-sm font-black">2</span>
                        <span class="font-bold text-gray-900 text-base">Event Planning Tab — Task-Based Logistics</span>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </summary>
                <div class="px-5 pb-5 space-y-4 text-sm text-gray-700 leading-relaxed border-t border-blue-100">
                    <div class="bg-white border border-blue-100 rounded-xl p-4 mt-4">
                        <p class="font-bold text-gray-900 mb-2">How tasks work</p>
                        <p>Instead of one general supply plan per event, you now create <strong>individual tasks per person</strong>. For example: for a conference, Jane is assigned "Water and Food Purchase" with an estimated budget of ₦15,000, and Ruth is assigned "Fruits" with ₦8,000. Each person handles and reports only their task.</p>
                    </div>
                    <div class="space-y-3">
                        <p class="font-bold text-gray-900">HOD — Command View (How to assign tasks)</p>
                        <div class="flex gap-3 items-start"><span class="bg-blue-100 text-blue-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span><p>Go to <strong>Event Planning</strong> tab → Find your event in the table → Click <strong>"Manage Tasks"</strong>.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-blue-100 text-blue-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span><p>Click <strong>"Add Task"</strong>. Give the task a clear title (e.g., "Water Purchase"), write a description, assign it to a member, and set an estimated budget.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-blue-100 text-blue-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span><p>The assigned member gets an <strong>in-app notification</strong> immediately. You can add as many tasks as needed for a single event.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-blue-100 text-blue-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">4</span><p>In the Task drawer, you see all tasks. Members see only theirs. You see everything (Command View).</p></div>
                    </div>
                    <div class="space-y-3">
                        <p class="font-bold text-gray-900">Member — How to report on your task</p>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span><p>Open <strong>Event Planning</strong> → Find the event → Click "Manage Tasks". You will see only the tasks assigned to you.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span><p>Once you receive funds, click <strong>"Mark Funds Received"</strong>. This confirms disbursement with a timestamp.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span><p>After purchasing, click <strong>"Submit Report"</strong>: enter what you bought, how much you actually spent, and your receipt reference. Then mark the task as <strong>Completed</strong>.</p></div>
                    </div>
                    <div class="bg-gray-800 text-white rounded-xl p-4">
                        <p class="font-bold mb-1">Generating the Event Completion Report (PDF with Digital Stamp)</p>
                        <p class="text-gray-300 text-xs leading-relaxed">Once all tasks are completed, the HOD clicks <strong>"Sign Off Event"</strong> in the task drawer. This applies your digital stamp. The Finance Director (Lola Bowale) then countersigns. Once both stamps are applied, you can download the fully sealed PDF report.</p>
                    </div>
                </div>
            </details>

            <!-- FINANCE -->
            <details class="group bg-green-50/50 border border-green-100 rounded-2xl overflow-hidden">
                <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 bg-green-600 text-white rounded-lg flex items-center justify-center text-sm font-black">3</span>
                        <span class="font-bold text-gray-900 text-base">Finance Tab — Budget, Actuals &amp; Reports</span>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </summary>
                <div class="px-5 pb-5 space-y-3 text-sm text-gray-700 leading-relaxed border-t border-green-100">
                    <div class="space-y-3 mt-4">
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span><p><strong>Set Monthly Budget:</strong> At the start of each month, the HOD clicks "Set Budget", enters the month, year, and approved budget amount. This becomes the baseline for Budget vs Actuals.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span><p><strong>Log Expenses:</strong> Click "Log Expense" anytime a purchase is made. Choose the category, enter the amount, and optionally link it to a specific event.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span><p><strong>Budget vs Actuals:</strong> The bar chart shows your set budget against actual spending across the last 6 months. The pie chart breaks down spending by category (Food &amp; Beverage, Pastoral Care, etc.).</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">4</span><p><strong>Forecasting:</strong> The forecast cards show projected spending based on the 3-month rolling average per category — useful for planning next month's budget request.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-green-100 text-green-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">5</span><p><strong>Change Period:</strong> Use the month/year dropdowns at the top of the Finance tab to view any historical month's data.</p></div>
                    </div>
                </div>
            </details>

            <!-- LIBRARY -->
            <details class="group bg-indigo-50/50 border border-indigo-100 rounded-2xl overflow-hidden">
                <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 bg-hodBlue text-white rounded-lg flex items-center justify-center text-sm font-black">4</span>
                        <span class="font-bold text-gray-900 text-base">Library Tab — Book Management</span>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </summary>
                <div class="px-5 pb-5 space-y-3 text-sm text-gray-700 leading-relaxed border-t border-indigo-100">
                    <div class="space-y-3 mt-4">
                        <div class="flex gap-3 items-start"><span class="bg-indigo-100 text-indigo-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span><p><strong>Add a Book:</strong> Click "Add New Book". Upload the front cover, then click "AI Extract" to auto-fill the title, author, and description from the cover image. Upload the e-book PDF if applicable.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-indigo-100 text-indigo-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span><p><strong>Active Borrows:</strong> Physical books that members have reserved or picked up appear here. Use "Confirm Pickup" when a member collects a book in person, and "Process Return" when they bring it back.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-indigo-100 text-indigo-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span><p><strong>Waitlist:</strong> When a physical book is fully borrowed, members can join the waitlist. They are notified automatically when a copy becomes available.</p></div>
                        <div class="flex gap-3 items-start"><span class="bg-indigo-100 text-indigo-700 font-black text-xs rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">4</span><p><strong>E-Books:</strong> Members access e-books through the Member Portal. The system tracks their reading progress automatically.</p></div>
                    </div>
                </div>
            </details>

        </div>
    </div>
</div><!-- /view-howto -->

</div><!-- /max-w-7xl -->
<?php include __DIR__ . '/../../includes/charis_modals.php'; ?>

<script>
// ═══════════════════════════════════════════════════════════
// CHARIS MODULE — MASTER JS
// ═══════════════════════════════════════════════════════════
const API_URL = '/api/charis_api.php';
let globalWorkers = [], globalMembers = [], globalEvents = {};
let barChartInstance = null, pieChartInstance = null;
let currentWelfareTab = 'my_cases';
let currentLogisticsType = 'upcoming';
let currentTaskEventId = null;

// ── Helpers ───────────────────────────────────────────────
function jsEscape(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
}
function htmlEscape(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;');
}
function fmtMoney(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2}); }

// ── Celebrant card helpers ────────────────────────────────
// Three kinds of celebrant share one grid: adult birthdays, wedding
// anniversaries, and Junior Church children (event_type 'JC_Birthday').
function celebLabel(t) {
    if (t === 'JC_Birthday') return 'Junior Church';
    return String(t || '').replace(/_/g, ' ');
}
function celebBadgeClass(t) {
    if (t === 'Birthday') return 'bg-amber-500';
    if (t === 'JC_Birthday') return 'bg-emerald-600';
    return 'bg-sky-600';
}
// Local initials placeholder. Children are often registered without a photo,
// and a remote placeholder host both breaks the grid and taints the export
// canvas, so the fallback is an inline SVG data URI instead.
function initialsAvatar(first, last) {
    const initials = htmlEscape(`${(first||'?').charAt(0)}${(last||'').charAt(0)}`.toUpperCase());
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="460" height="820" viewBox="0 0 460 820">`
        + `<rect width="460" height="820" fill="#111827"/>`
        + `<text x="230" y="410" text-anchor="middle" dominant-baseline="central" fill="#ffffff" `
        + `fill-opacity="0.35" font-family="Arial, Helvetica, sans-serif" font-size="200" font-weight="bold">${initials}</text>`
        + `</svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
function celebPortrait(v) {
    return v.display_picture || initialsAvatar(v.first_name, v.last_name);
}

// ── Screen lock ───────────────────────────────────────────
function lockScreenAction() {
    const b = document.getElementById('globalActionBlocker');
    if(b){ b.classList.remove('hidden'); document.body.style.overflow='hidden'; setTimeout(()=>b.classList.remove('opacity-0'),10); }
}
function unlockScreenAction() {
    const b = document.getElementById('globalActionBlocker');
    if(b){ b.classList.add('opacity-0'); setTimeout(()=>{ b.classList.add('hidden'); if(!document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length) document.body.style.overflow=''; },300); }
}

// ── Modals ────────────────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    if(!m) return;
    document.body.appendChild(m);
    m.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    setTimeout(() => { m.classList.remove('opacity-0'); m.querySelector('.transform')?.classList.remove('scale-95'); }, 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    if(!m) return;
    m.classList.add('opacity-0');
    m.querySelector('.transform')?.classList.add('scale-95');
    setTimeout(() => {
        m.classList.add('hidden');
        if (!document.querySelector('.backdrop-blur-md:not(.hidden)')) document.body.style.overflow = '';
    }, 300);
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type='success') {
    const bg = type==='success'?'#059669':(type==='error'?'#D11920':'#D97706');
    Toastify({ text: msg, duration: 4000, close: true, style: { background: bg, borderRadius:'10px', fontWeight:'bold', fontSize:'13px', fontFamily:'Inter, sans-serif' } }).showToast();
}

// ── Generic form handler ──────────────────────────────────
function handleAjaxForm(formId, cb) {
    $('#' + formId).on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]');
        const orig = btn.html(); btn.prop('disabled',true).html('Saving...');
        $.ajax({ url: API_URL, type:'POST', data: new FormData(this), contentType:false, processData:false, dataType:'json',
            success: function(res) { btn.prop('disabled',false).html(orig); showToast(res.message, res.status); if(res.status==='success') cb(res); },
            error:   function()    { btn.prop('disabled',false).html(orig); showToast('Connection error.','error'); }
        });
    });
}

// ═══════════════════════════════════════════════════════════
// TAB SWITCHING
// ═══════════════════════════════════════════════════════════
function switchTab(name) {
    ['welfare','events','finance','library','howto'].forEach(t => {
        document.getElementById('view-' + t).classList.toggle('hidden', t !== name);
        const btn = document.getElementById('tab-' + t);
        btn.classList.toggle('active-tab',   t === name);
        btn.classList.toggle('inactive-tab', t !== name);
    });
    if (name === 'library')  loadLibraryAdminData();
    if (name === 'finance')  loadFinanceData();
}

// ═══════════════════════════════════════════════════════════
// DASHBOARD DATA LOAD (Welfare tab)
// ═══════════════════════════════════════════════════════════
function loadDashboardData() {
    $.ajax({ url: API_URL, type:'GET', data:{action:'fetch_dashboard'}, dataType:'json',
        success: function(res) {
            if (res.status !== 'success') return;
            window.isCharisAdmin  = res.is_charis_admin;
            window.isPastor = res.is_pastor;
            window.loggedInUserId = res.current_user_id;
            globalWorkers = res.charis_workers || [];
            globalMembers = res.members || [];

            // Populate worker dropdowns
            const wOpts = '<option value="" disabled selected>Select worker...</option>' + globalWorkers.map(w=>`<option value="${w.id}">${htmlEscape(w.first_name)} ${htmlEscape(w.last_name)}</option>`).join('');
            $('#workerSelectDropdown, #welWorkerDropdown, #taskWorkerDropdown').html(wOpts);

            // Populate member dropdown
            const mOpts = '<option value="" disabled selected>Select member...</option>' + globalMembers.map(m=>`<option value="${m.id}">${htmlEscape(m.first_name)} ${htmlEscape(m.last_name)}</option>`).join('');
            $('#memberSelect').html(mOpts);

            // Event dropdowns
            const eOpts = '<option value="">-- General / Non-Event --</option>' + (res.upcoming_events||[]).map(e=>`<option value="${e.id}">${htmlEscape(e.title)}</option>`).join('');
            $('#expenseEventSelect').html(eOpts);

            globalEvents = { upcoming: res.upcoming_events||[], past: res.past_events||[] };

            renderBirthdays(res.birthdays || []);
            renderAnniversaries(res.anniversaries || []);
            window.welfareData = res.welfare_checks || [];
            const welfareCount = window.welfareData.length;
            $('#welfareCardCount').text(welfareCount + (welfareCount === 1 ? ' alert' : ' alerts'));
            $('#welfareModalCount').text(welfareCount);
            renderWelfareList();
            renderLogisticsTable(currentLogisticsType);
        }
    });
}

// ═══════════════════════════════════════════════════════════
// BIRTHDAYS
// ═══════════════════════════════════════════════════════════
function renderBirthdays(birthdays) {
    let html = '';
    if (!birthdays.length) { html = '<li class="text-center py-12 text-gray-400 text-sm">No upcoming birthdays.</li>'; }
    else {
        birthdays.forEach(b => {
            const avatar = b.picture_path ? `<img src="${htmlEscape(b.picture_path)}" class="w-full h-full object-cover">` : `${htmlEscape((b.first_name||'?').charAt(0))}${htmlEscape((b.last_name||'').charAt(0))}`;
            const dlIcon = b.picture_path ? `<a href="${htmlEscape(b.picture_path)}" download class="p-2 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 shrink-0"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg></a>` : '';
            html += `<li class="flex items-center justify-between p-4 bg-white border border-gray-100 rounded-2xl shadow-sm gap-3">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 text-hodBlue flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden">${avatar}</div>
                    <div class="truncate"><p class="text-sm font-bold text-gray-900 truncate">${htmlEscape(b.first_name)} ${htmlEscape(b.last_name)}</p><p class="text-[10px] font-bold text-hodBlue mt-0.5">${htmlEscape(b.formatted_date)} <span class="text-gray-400 font-medium">${htmlEscape(b.phone||'')}</span></p></div>
                </div>
                <div class="flex flex-col items-end gap-2 shrink-0">${dlIcon}${generateActionBlock(b,'Birthday')}</div>
            </li>`;
        });
    }
    $('#birthdaysList, #birthdaysModalList').html(html);
    $('#birthdayCardCount').text(birthdays.length + ' upcoming');
    $('#birthdaysModalCount').text(birthdays.length);
}

function renderAnniversaries(items) {
    let html = '';
    if (!items.length) { html = '<li class="text-center py-12 text-gray-400 text-sm">No upcoming life events.</li>'; }
    else {
        items.forEach(a => {
            const avatar = a.picture_path ? `<img src="${htmlEscape(a.picture_path)}" class="w-full h-full object-cover">` : `${htmlEscape((a.first_name||'?').charAt(0))}`;
            html += `<li class="flex items-center justify-between p-4 bg-white border border-gray-100 rounded-2xl shadow-sm gap-3">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="h-10 w-10 rounded-full bg-red-50 border border-red-100 text-hodRed flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden">${avatar}</div>
                    <div class="truncate"><p class="text-sm font-bold text-gray-900 truncate">${htmlEscape(a.first_name)} ${htmlEscape(a.last_name)}</p><p class="text-[10px] font-bold text-red-500 mt-0.5">${htmlEscape(a.formatted_date)} — ${htmlEscape((a.event_type||'').replace('_',' '))}</p></div>
                </div>
                <div class="shrink-0">${generateActionBlock(a, a.event_type)}</div>
            </li>`;
        });
    }
    $('#anniversariesList, #anniversariesModalList').html(html);
    $('#lifeEventsCardCount').text(items.length + (items.length === 1 ? ' event' : ' events'));
    $('#lifeEventsModalCount').text(items.length);
}

function generateActionBlock(item, type) {
    if (!window.isCharisAdmin) return '';
    if (item.assignment_id) {
        const statusMap = { Assigned:'bg-yellow-50 text-yellow-700 border-yellow-200', Flyer_Posted:'bg-blue-50 text-blue-700 border-blue-200', Completed:'bg-green-50 text-green-700 border-green-200' };
        const sc = statusMap[item.assignment_status] || 'bg-gray-50 text-gray-500 border-gray-200';
        if (item.assignment_status === 'Completed') return `<span class="text-[10px] border px-2 py-1 rounded-lg font-bold ${sc}">✓ Done</span>`;
        if (item.assignment_status === 'Flyer_Posted') return `<button onclick="updateAssignmentStatus(${item.assignment_id},'Completed',this)" class="text-[10px] border px-2 py-1 rounded-lg font-bold ${sc}">Mark Done</button>`;
        return `<button onclick="updateAssignmentStatus(${item.assignment_id},'Flyer_Posted',this)" class="text-[10px] border px-2 py-1 rounded-lg font-bold ${sc}">Flyer Posted</button>`;
    }
    return `<button onclick="triggerAssignModal('${jsEscape(item.target_user_id)}','${jsEscape(type)}','${jsEscape(item.event_date)}','${jsEscape(item.first_name)} ${jsEscape(item.last_name)}')" class="text-[10px] bg-gray-50 border border-gray-200 hover:border-hodBlue hover:text-hodBlue px-3 py-1.5 rounded-lg font-bold transition-all">Assign</button>`;
}

function triggerAssignModal(tid, type, date, name) {
    $('#assignTargetId').val(tid); $('#assignEventType').val(type); $('#assignEventDate').val(date);
    $('#assignTargetName').text(name); $('#assignEventBadge').text(type.replace(/_/g,' '));
    openModal('assignWorkerModal');
}
function updateAssignmentStatus(id, status, btn) {
    const orig = btn.innerText; btn.disabled = true; btn.innerText = '...';
    $.post(API_URL, { action:'update_assignment_status', assignment_id:id, status }, function(res) {
        btn.disabled=false; btn.innerText=orig; showToast(res.message, res.status);
        if(res.status==='success') loadDashboardData();
    }, 'json');
}

// ═══════════════════════════════════════════════════════════
// WELFARE LIST
// ═══════════════════════════════════════════════════════════
function toggleWelfareTab(tab) {
    currentWelfareTab = tab;
    document.querySelectorAll('[data-welfare-tab]').forEach(button => {
        const isActive = button.dataset.welfareTab === tab;
        button.className = 'welfare-tab-button px-3 sm:px-4 py-2 rounded-lg text-[10px] sm:text-xs font-bold transition-all whitespace-nowrap flex-1 '
            + (isActive ? 'bg-white text-orange-600 shadow-sm' : 'text-gray-500 hover:text-gray-900');
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (tab === 'archive') {
        loadWelfareArchive();
    } else {
        renderWelfareList();
    }
}

function renderWelfareList() {
    let data = window.welfareData || [];
    if (currentWelfareTab === 'my_cases') data = data.filter(w => w.worker_id == window.loggedInUserId && w.assignment_status === 'Assigned');
    let html = '';
    if (!data.length) { html = '<li class="text-center py-12 text-gray-400 text-sm">No alerts in this view.</li>'; }
    else {
        data.forEach(w => {
            const badge = w.alert_type==='AWOL' ? '<span class="text-[9px] bg-red-100 text-red-600 px-2 py-0.5 rounded font-bold border border-red-200 uppercase">3 Wks AWOL</span>' : '<span class="text-[9px] bg-orange-100 text-orange-600 px-2 py-0.5 rounded font-bold border border-orange-200 uppercase">Manual</span>';
            let action = '';
            if (currentWelfareTab === 'my_cases') {
                action = `<button onclick="openManageWelfareModal(${w.target_user_id},${w.followup_id||0},'${jsEscape(w.first_name)} ${jsEscape(w.last_name)}','${jsEscape(w.phone)}')" class="w-full text-xs font-bold text-white bg-hodBlue hover:bg-blue-900 px-4 py-2.5 rounded-xl transition-all mb-2">Manage Case</button>`;
            } else if (w.assignment_status==='Assigned') {
                action = `<div class="flex items-center justify-between bg-gray-50 border border-gray-200 px-3 py-2 rounded-xl mb-2"><span class="text-[10px] font-bold text-gray-500">Assigned: <span class="text-hodBlue">${htmlEscape(w.worker_fname)}</span></span>${window.isCharisAdmin?`<button onclick="openAssignWelfareModal(${w.target_user_id},${w.followup_id||0})" class="text-[10px] font-bold text-gray-400 hover:text-hodBlue underline">Reassign</button>`:''}</div>`;
            } else if (w.assignment_status==='Requested') {
                action = window.isCharisAdmin ? `<div class="flex gap-2 mb-2"><div class="flex-1 bg-blue-50 border border-blue-200 px-3 py-2 rounded-xl flex items-center justify-center"><span class="text-[10px] font-bold text-blue-700">Req: ${htmlEscape(w.worker_fname)}</span></div><button onclick="approveWelfareCase(${w.assignment_id})" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-xl text-[10px] font-bold">Approve</button></div>` : '<div class="bg-orange-50 border border-orange-200 px-3 py-2 rounded-xl text-center mb-2"><span class="text-[10px] font-bold text-orange-600">Pending HOD Approval</span></div>';
            } else {
                action = window.isCharisAdmin ? `<button onclick="openAssignWelfareModal(${w.target_user_id},${w.followup_id||0})" class="w-full text-[11px] font-bold text-gray-700 bg-gray-50 border border-gray-200 hover:border-hodBlue hover:text-hodBlue px-4 py-2.5 rounded-xl transition-all mb-2">Assign Case</button>` : `<button onclick="requestWelfareCase(${w.target_user_id},${w.followup_id||0})" class="w-full text-[11px] font-bold text-hodBlue bg-blue-50 border border-blue-200 hover:bg-blue-100 px-4 py-2.5 rounded-xl transition-all mb-2">Request to Handle</button>`;
            }

            // Zero-Trust Notes Button
            let safeNotesJson = encodeURIComponent(JSON.stringify(w.secure_notes||[]));
            let notesHtml = `<button onclick='prepManageCharisNotes(JSON.parse(decodeURIComponent("${safeNotesJson}")), ${w.target_user_id}, "${jsEscape(w.first_name)} ${jsEscape(w.last_name)}")' class="text-[10px] font-bold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-2 rounded-xl border border-blue-100 flex items-center justify-center gap-1 w-full transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> Manage Notes (${(w.secure_notes||[]).length})</button>`;

            html += `<li class="flex flex-col p-4 bg-white border border-orange-100/60 rounded-2xl shadow-sm gap-3 hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start gap-2">
                    <div class="truncate"><p class="text-sm font-bold text-gray-900 truncate">${htmlEscape(w.first_name)} ${htmlEscape(w.last_name)}</p><p class="text-[10px] font-bold text-orange-500 mt-0.5 truncate">${htmlEscape(w.phone||'No phone')} <span class="text-gray-400 font-medium">• ${htmlEscape(w.physical_address||'Unknown')}</span></p></div>
                    <div class="shrink-0">${badge}</div>
                </div>
                <div>${action} ${notesHtml}</div>
            </li>`;
        });
    }
    $('.welfare-list').html(html);
}

// ═══════════════════════════════════════════════════════════
// ZERO-TRUST CHARIS NOTES UI LOGIC
// ═══════════════════════════════════════════════════════════
function prepManageCharisNotes(secureNotes, targetUserId, memberName) {
    $('#note_target_user_id').val(targetUserId);
    $('#charis_notes_target_name').text(memberName);
    resetCharisNoteForm();

    // Show the Pastors-only toggle only for pastors.
    document.getElementById('charisPastorOnlyWrap').classList.toggle('hidden', !window.isPastor);

    let container = $('#existingCharisNotesContainer');
    container.empty();

    if (!secureNotes || secureNotes.length === 0) {
        container.html('<p class="text-center text-sm text-gray-400 italic py-6">No notes recorded yet.</p>');
    } else {
        secureNotes.forEach(n => {
            let vis = [];
            try { vis = JSON.parse(n.visible_to) || []; } catch (e) { vis = []; }
            const isPastorOnly = vis.includes('Pastors') && !vis.includes('All');
            const visBadge = isPastorOnly
                ? '<span class="text-[9px] bg-purple-100 text-purple-700 border border-purple-200 px-2 py-0.5 rounded font-bold uppercase">Pastors Only</span>'
                : '';

            let editBtn = '';
            if (n.author_id == window.loggedInUserId) {
                editBtn = `<button class="charis-edit-note text-[10px] font-bold text-hodBlue hover:underline mt-2"
                    data-note-id="${n.id}"
                    data-note-text="${encodeURIComponent(n.note_text)}"
                    data-vis="${encodeURIComponent(JSON.stringify(vis))}">Edit My Note</button>`;
            }

            container.append(`
                <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                    <div class="flex justify-between items-start mb-2 border-b border-gray-50 pb-2">
                        <div>
                            <span class="font-bold text-gray-900 text-sm">${htmlEscape(n.first_name)} ${htmlEscape(n.last_name)}</span>
                            <span class="text-[10px] text-gray-400 ml-2">${new Date(n.created_at).toLocaleString()}</span>
                        </div>
                        <div class="flex gap-1">${visBadge}</div>
                    </div>
                    <div class="text-sm text-gray-700 whitespace-pre-wrap">${htmlEscape(n.note_text)}</div>
                    ${editBtn}
                </div>
            `);
        });
    }
    openModal('manageCharisNotesModal');
}

function editSpecificCharisNote(noteId, text, visibleToArr) {
    $('#edit_charis_note_id').val(noteId);
    $('#charis_note_text_input').val(text);
    $('#charisNoteInputLabel').text('Edit Your Note');
    $('#saveCharisNoteBtn').text('Update Note');
    $('#cancelEditCharisNoteBtn').removeClass('hidden');
    const isPastorOnly = Array.isArray(visibleToArr) && visibleToArr.includes('Pastors') && !visibleToArr.includes('All');
    $('#cb_charis_pastor_only').prop('checked', isPastorOnly);
}

function resetCharisNoteForm() {
    $('#edit_charis_note_id').val('');
    $('#charis_note_text_input').val('');
    $('#charisNoteInputLabel').text('Add New Note');
    $('#saveCharisNoteBtn').text('Save Note');
    $('#cancelEditCharisNoteBtn').addClass('hidden');
    $('#cb_charis_pastor_only').prop('checked', false);
}

// Finally, add this inside your $(document).ready() block:
// handleAjaxForm('saveCharisNoteForm', ()=>{ closeModal('manageCharisNotesModal'); loadDashboardData(); toggleWelfareTab(currentWelfareTab); });

function loadWelfareArchive() {
    $('.welfare-list').html('<li class="text-center py-10 text-gray-400"><svg class="animate-spin h-8 w-8 mx-auto mb-3 text-orange-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Fetching archives...</li>');
    
    $.getJSON(API_URL, { action: 'fetch_welfare_archive' }, res => {
        if(res.status === 'success') {
            let html = '';
            if (res.data.length === 0) {
                html = '<li class="text-center py-12 text-gray-400 text-sm italic">No resolved archives found.</li>';
            } else {
                res.data.forEach(a => {
                    let formattedNotes = (a.secure_notes || []).map(n => `<div class="mb-2 bg-white p-2.5 rounded-lg border border-gray-100 shadow-sm"><span class="font-bold text-gray-800 text-[10px] uppercase tracking-wider">${htmlEscape(n.first_name)} ${htmlEscape(n.last_name)}:</span> <span class="text-gray-600 text-[11px] whitespace-pre-wrap ml-1">${htmlEscape(n.note_text)}</span></div>`).join('');
                    if(!formattedNotes) formattedNotes = '<span class="text-[10px] text-gray-400 italic">No accessible notes found.</span>';

                    let archiveDateDisplay = a.archive_date ? new Date(a.archive_date).toLocaleDateString() : 'Unknown Date';

                    html += `<li class="flex flex-col p-4 bg-gray-50/50 border border-gray-200 rounded-2xl shadow-sm gap-2">
                        <div class="flex justify-between items-start gap-2 border-b border-gray-200 pb-3">
                            <div class="truncate"><p class="text-sm font-bold text-gray-900 truncate">${htmlEscape(a.first_name)} ${htmlEscape(a.last_name)}</p><p class="text-[10px] text-gray-500 mt-0.5 truncate">${htmlEscape(a.phone||'')} • Resolved: ${archiveDateDisplay}</p></div>
                            <span class="text-[9px] bg-green-100 text-green-700 px-2 py-0.5 rounded font-bold border border-green-200 uppercase shrink-0">Resolved</span>
                        </div>
                        <div class="text-[10px] text-gray-500 mb-2">Worker Assigned: <span class="font-bold text-hodBlue">${htmlEscape(a.worker_fname||'Unassigned')}</span></div>
                        <div class="mt-1">${formattedNotes}</div>
                    </li>`;
                });
            }
            $('.welfare-list').html(html);
        } else {
            // Failsafe: Shows the backend error message instead of hanging
            $('.welfare-list').html(`<li class="text-center py-10 text-red-500 font-bold text-sm">Error: ${res.message}</li>`);
        }
    }).fail(() => {
        // Failsafe: Catches 500 fatal server crashes
        $('.welfare-list').html('<li class="text-center py-10 text-red-500 font-bold text-sm">Server Error. Check logs.</li>');
    });
}

function openManageWelfareModal(uid, fid, name, phone) {
    $('#welfareMemberName').text(name);
    $('#welfareUserId').val(uid); $('#welfareFollowupId').val(fid);
    $('#resolveWelfareUserId').val(uid);
    $('#btnWelfareCall').attr('href','tel:'+phone);
    const clean = phone.replace(/\D/g,'');
    $('#btnWelfareWhatsApp').attr('href','https://wa.me/234'+clean.replace(/^0/,''));
    openModal('manageWelfareModal');
}
function openAssignWelfareModal(tid, fid) {
    $('#assignWelTargetId').val(tid); $('#assignWelFollowupId').val(fid);
    const wOpts = '<option value="" disabled selected>Select worker...</option>' + globalWorkers.map(w=>`<option value="${w.id}">${htmlEscape(w.first_name)} ${htmlEscape(w.last_name)}</option>`).join('');
    $('#welWorkerDropdown').html(wOpts);
    openModal('assignWelfareModal');
}
function requestWelfareCase(tid, fid) {
    lockScreenAction();
    $.post(API_URL,{action:'request_welfare_case',target_user_id:tid,followup_id:fid},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadDashboardData();},'json');
}
function approveWelfareCase(id) {
    lockScreenAction();
    $.post(API_URL,{action:'approve_welfare_case',assignment_id:id},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadDashboardData();},'json');
}

// ═══════════════════════════════════════════════════════════
// AWOL REPORT
// ═══════════════════════════════════════════════════════════
let awolReportMode = 'month';
function setReportMode(mode) {
    awolReportMode = mode;
    document.getElementById('rptModeMonth').className = 'flex-1 px-4 py-2 rounded-lg text-sm font-bold ' + (mode==='month'?'bg-white text-gray-900 shadow-sm':'text-gray-500') + ' transition-all';
    document.getElementById('rptModeRange').className = 'flex-1 px-4 py-2 rounded-lg text-sm font-bold ' + (mode==='range'?'bg-white text-gray-900 shadow-sm':'text-gray-500') + ' transition-all';
    document.getElementById('rptMonthFields').classList.toggle('hidden', mode==='range');
    document.getElementById('rptRangeFields').classList.toggle('hidden', mode==='month');
}
function downloadAwolReport() {
    let url = '/includes/charis_awol_pdf.php?mode=' + awolReportMode;
    if (awolReportMode === 'month') {
        url += '&month=' + $('#rptMonth').val() + '&year=' + $('#rptYear').val();
    } else {
        const s = $('#rptDateStart').val(), e = $('#rptDateEnd').val();
        if (!s||!e) { showToast('Please select both start and end dates.','error'); return; }
        url += '&date_start=' + s + '&date_end=' + e;
    }
    window.open(url, '_blank');
    closeModal('awolReportModal');
}

// ═══════════════════════════════════════════════════════════
// EVENT LOGISTICS TABLE
// ═══════════════════════════════════════════════════════════
function toggleLogisticsTab(type) {
    currentLogisticsType = type;
    document.getElementById('tabUpcoming').className = 'px-4 py-2 rounded-lg text-sm font-bold ' + (type==='upcoming'?'bg-white text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900') + ' transition-all';
    document.getElementById('tabPast').className     = 'px-4 py-2 rounded-lg text-sm font-bold ' + (type==='past'?'bg-white text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900') + ' transition-all';
    renderLogisticsTable(type);
}
function renderLogisticsTable(type) {
    const events = (globalEvents[type] || []).filter(e => window.isCharisAdmin || true);
    const statusMap = {
        'No_Plan':      'text-gray-400 bg-gray-50 border-gray-200',
        'Planning':     'text-blue-600 bg-blue-50 border-blue-200',
        'Pending_Funds':'text-yellow-600 bg-yellow-50 border-yellow-200',
        'Approved':     'text-green-600 bg-green-50 border-green-200',
        'Completed':    'text-emerald-700 bg-emerald-50 border-emerald-200',
    };
    let html = '';
    if (!events.length) { html = `<tr><td colspan="4" class="px-6 py-12 text-center text-gray-400">No ${type} events.</td></tr>`; }
    else {
        events.forEach(e => {
            const sc = statusMap[e.logistics_status] || statusMap['No_Plan'];
            const lbl = (e.logistics_status||'No Plan').replace('_',' ');
            html += `<tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 pl-8 font-bold text-gray-900">${htmlEscape(e.nice_date)}</td>
                <td class="px-6 py-4 font-medium text-gray-700">${htmlEscape(e.title)}</td>
                <td class="px-6 py-4"><span class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${sc}">${htmlEscape(lbl)}</span></td>
                <td class="px-6 py-4 pr-8 text-right"><button onclick="openEventTasksModal(${e.id},'${jsEscape(e.title)}','${jsEscape(e.nice_date)}')" class="text-xs font-bold text-hodBlue hover:text-blue-900 border border-blue-200 px-4 py-2 rounded-xl hover:bg-blue-50 transition-all">Manage Tasks</button></td>
            </tr>`;
        });
    }
    $('#logisticsTableBody').html(html);
}

// ═══════════════════════════════════════════════════════════
// EVENT TASKS
// ═══════════════════════════════════════════════════════════
function openEventTasksModal(eventId, title, date) {
    currentTaskEventId = eventId;
    $('#taskEventId').val(eventId);
    $('#taskModalTitle').text(title);
    $('#taskModalDate').text(date);
    const pdfUrl = '/includes/charis_event_report_pdf.php?event_id=' + eventId;
    $('#btnDownloadEventReport').attr('href', pdfUrl).removeClass('hidden');
    openModal('eventTasksModal');
    loadEventTasks(eventId);
}
function loadEventTasks(eventId) {
    $('#tasksList').html('<div class="text-center py-10 text-gray-400"><svg class="animate-spin h-8 w-8 mx-auto mb-3 text-hodBlue" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Loading...</div>');
    $.getJSON(API_URL, { action:'get_event_tasks', event_id: eventId }, function(res) {
        if (res.status !== 'success') { showToast(res.message,'error'); return; }
        const isAdmin = res.is_admin;
        document.getElementById('addTaskSection').classList.toggle('hidden', !isAdmin);
        document.getElementById('hodSignOffSection').classList.toggle('hidden', !isAdmin);
        renderTasksList(res.tasks, isAdmin);
        renderStampBlock(res.stamp, isAdmin);
    });
}
function renderTasksList(tasks, isAdmin) {
    if (!tasks.length) {
        $('#tasksList').html('<div class="text-center py-12 text-gray-400 text-sm">' + (isAdmin ? 'No tasks yet. Add your first task above.' : 'No tasks assigned to you for this event.') + '</div>');
        return;
    }
    let html = '';
    tasks.forEach(t => {
        const statusClass = t.status==='Completed'?'text-green-600 bg-green-50 border-green-200':(t.status==='In_Progress'?'text-blue-600 bg-blue-50 border-blue-200':'text-yellow-600 bg-yellow-50 border-yellow-200');
        const fundsBadge = t.funds_disbursed ? `<span class="text-[10px] text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded font-bold">✓ Funds Received</span>` : '';
        let actions = '';
        if (t.status !== 'Completed') {
            if (!t.funds_disbursed) {
                actions += `<button onclick="markFundsReceived(${t.id})" class="text-xs font-bold text-blue-700 border border-blue-200 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-xl transition-all">Mark Funds Received</button>`;
            }
            actions += `<button onclick="openTaskReportModal(${t.id},'${jsEscape(t.task_title)}')" class="text-xs font-bold text-white bg-green-600 hover:bg-green-700 px-3 py-1.5 rounded-xl transition-all">Submit Report</button>`;
        }
        if (isAdmin) {
            actions += `<button onclick="deleteTask(${t.id})" class="text-xs font-bold text-red-400 hover:text-red-600 border border-red-100 hover:border-red-300 px-3 py-1.5 rounded-xl transition-all">Remove</button>`;
        }
        html += `<div class="bg-white border border-gray-100 rounded-2xl p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <p class="font-bold text-gray-900 text-sm">${htmlEscape(t.task_title)}</p>
                    ${t.task_description?`<p class="text-xs text-gray-500 mt-0.5">${htmlEscape(t.task_description)}</p>`:''}
                </div>
                <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${statusClass} shrink-0">${htmlEscape(t.status.replace('_',' '))}</span>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px] text-gray-500 mb-3">
                <div><span class="font-bold">Assigned:</span> ${htmlEscape((t.worker_first||'—') + ' ' + (t.worker_last||''))}</div>
                <div><span class="font-bold">Est. Budget:</span> ${fmtMoney(t.estimated_budget)}</div>
                ${t.actual_spent>0?`<div><span class="font-bold">Actual Spent:</span> ${fmtMoney(t.actual_spent)}</div>`:''}
                ${t.member_report?`<div class="col-span-2"><span class="font-bold">Report:</span> <em class="text-gray-600">"${htmlEscape(t.member_report)}"</em></div>`:''}
            </div>
            ${fundsBadge}
            <div class="flex flex-wrap gap-2 mt-3">${actions}</div>
        </div>`;
    });
    $('#tasksList').html(html);
}
function renderStampBlock(stamp, isAdmin) {
    if (!stamp) {
        if (isAdmin) { document.getElementById('btnHodStamp').classList.remove('hidden'); document.getElementById('btnFinanceStamp').classList.add('hidden'); }
        return;
    }
    const hodDone = !!stamp.hod_stamp_at;
    const finDone = !!stamp.finance_stamp_at;
    let stampHtml = '';
    if (hodDone && stamp.hod_stamp_data) {
        const s = stamp.hod_stamp_data;
        stampHtml += `<div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs"><p class="font-black text-blue-800 uppercase tracking-wider mb-1">✓ HOD Signed Off</p><p class="text-blue-700"><strong>${htmlEscape(s.name)}</strong> — ${htmlEscape(s.date_time)}</p><p class="font-mono text-blue-500 mt-0.5">VERIFY: ${htmlEscape(s.verify_code)}</p></div>`;
    }
    if (finDone && stamp.finance_stamp_data) {
        const s = stamp.finance_stamp_data;
        stampHtml += `<div class="mb-3 p-3 bg-green-50 border border-green-200 rounded-xl text-xs"><p class="font-black text-green-800 uppercase tracking-wider mb-1">✓ Finance Director Countersigned</p><p class="text-green-700"><strong>${htmlEscape(s.name)}</strong> — ${htmlEscape(s.date_time)}</p><p class="font-mono text-green-500 mt-0.5">VERIFY: ${htmlEscape(s.verify_code)}</p></div>`;
    }
    const display = document.getElementById('stampDisplay');
    display.innerHTML = stampHtml;
    display.classList.toggle('hidden', !stampHtml);
    if (isAdmin) {
        document.getElementById('btnHodStamp').classList.toggle('hidden', hodDone);
        document.getElementById('btnFinanceStamp').classList.toggle('hidden', !hodDone || finDone);
    }
}
function markFundsReceived(taskId) {
    if (!confirm('Confirm that funds have been received for this task?')) return;
    lockScreenAction();
    $.post(API_URL,{action:'update_event_task',task_id:taskId,mark_funds_received:1},function(res){
        unlockScreenAction(); showToast(res.message,res.status);
        if(res.status==='success') loadEventTasks(currentTaskEventId);
    },'json');
}
function openTaskReportModal(taskId, title) {
    $('#reportTaskId').val(taskId); $('#reportTaskTitle').text(title);
    openModal('submitTaskReportModal');
}
function deleteTask(taskId) {
    if (!confirm('Remove this task? This cannot be undone.')) return;
    lockScreenAction();
    $.post(API_URL,{action:'delete_event_task',task_id:taskId},function(res){
        unlockScreenAction(); showToast(res.message,res.status);
        if(res.status==='success') loadEventTasks(currentTaskEventId);
    },'json');
}
function applyHodStamp() {
    if (!currentTaskEventId) return;
    if (!confirm('Apply your HOD digital stamp to this event completion report?')) return;
    lockScreenAction();
    $.post(API_URL,{action:'stamp_event_completion',event_id:currentTaskEventId},function(res){
        unlockScreenAction(); showToast(res.message,res.status);
        if(res.status==='success') loadEventTasks(currentTaskEventId);
    },'json');
}
function applyFinanceStamp() {
    if (!currentTaskEventId) return;
    if (!confirm('Apply Finance Director countersignature to seal this report?')) return;
    lockScreenAction();
    $.post(API_URL,{action:'finance_stamp_event',event_id:currentTaskEventId},function(res){
        unlockScreenAction(); showToast(res.message,res.status);
        if(res.status==='success') loadEventTasks(currentTaskEventId);
    },'json');
}

// ═══════════════════════════════════════════════════════════
// FINANCE TAB
// ═══════════════════════════════════════════════════════════
function loadFinanceData() {
    const month = $('#financeMonthSel').val();
    const year  = $('#financeYearSel').val();
    const monthName = new Date(year, month-1, 1).toLocaleString('default',{month:'long'});
    $('#ledgerMonthLabel').text(monthName + ' ' + year);
    $.ajax({ url: API_URL, type:'POST', data:{action:'get_finance_data', month, year}, dataType:'json',
        success: function(res) {
            if (res.status !== 'success') { showToast(res.message,'error'); return; }
            const budget = res.monthly_budget ? parseFloat(res.monthly_budget.amount) : 0;
            const actual = parseFloat(res.total_actual || 0);
            const variance = budget - actual;
            $('#finBudgetAmt').text(budget ? fmtMoney(budget) : 'Not set');
            $('#finActualAmt').text(fmtMoney(actual));
            $('#finVarianceAmt').text(fmtMoney(Math.abs(variance))).removeClass('text-green-600 text-red-600').addClass(variance>=0?'text-green-600':'text-red-600');
            $('#finVarianceLbl').text(variance>=0?'Under budget':'Over budget');

            // Bar chart: 6-month trend
            buildBarChart(res.trend||[], res.budget_trend||[]);

            // Pie chart: category breakdown
            buildPieChart(res.categories||[]);

            // Forecast
            if (res.forecast && res.forecast.length) {
                let fc = '';
                res.forecast.forEach(f => { fc += `<div class="bg-white rounded-2xl p-5 border border-orange-100 shadow-sm flex items-center justify-between"><div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">${htmlEscape(f.category.replace('_',' '))}</p><h4 class="text-lg font-black text-gray-900 mt-1">${fmtMoney(f.projected)}</h4></div></div>`; });
                $('#forecastCards').html(fc);
                document.getElementById('financialForecastingWidget').classList.remove('hidden');
            }

            // Ledger
            let lHtml = '';
            if (!res.ledger.length) {
                lHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-400">No expenses for this period.</td></tr>';
            } else {
                res.ledger.forEach(ex => {
                    const sc = ex.status==='Completed'?'text-green-600 bg-green-50 border-green-200':(ex.status==='Declined'?'text-red-600 bg-red-50 border-red-200':'text-orange-600 bg-orange-50 border-orange-200');
                    lHtml += `<tr class="hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-0">
                        <td class="px-6 py-4 pl-8 font-bold text-gray-900">${htmlEscape(ex.item_name)}</td>
                        <td class="px-6 py-4 text-xs font-medium text-gray-500 uppercase tracking-wider">${htmlEscape(ex.category.replace('_',' '))}</td>
                        <td class="px-6 py-4 text-right font-black text-gray-900">${fmtMoney(ex.amount_requested)}</td>
                        <td class="px-6 py-4 text-right font-medium text-gray-600">${ex.amount_approved?fmtMoney(ex.amount_approved):'—'}</td>
                        <td class="px-6 py-4 pr-8 text-center"><span class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${sc}">${htmlEscape(ex.status.replace('_',' '))}</span></td>
                    </tr>`;
                });
            }
            $('#expenseLedgerBody').html(lHtml);
        }
    });
}
function buildBarChart(trend, budgetTrend) {
    if (barChartInstance) barChartInstance.destroy();
    const ctx = document.getElementById('barChart').getContext('2d');
    barChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: trend.map(t=>t.label),
            datasets: [
                { label:'Budget',        data: budgetTrend, backgroundColor:'rgba(14,165,233,0.25)', borderColor:'#0EA5E9', borderWidth:2, borderRadius:4 },
                { label:'Actual Spent',  data: trend.map(t=>t.total), backgroundColor:'rgba(209,25,32,0.75)', borderColor:'#D11920', borderWidth:0, borderRadius:4 }
            ]
        },
        options: { responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:v=>'₦'+Number(v).toLocaleString()},grid:{color:'rgba(0,0,0,.05)'}}} }
    });
}
function buildPieChart(cats) {
    if (pieChartInstance) pieChartInstance.destroy();
    if (!cats.length) return;
    const ctx = document.getElementById('pieChart').getContext('2d');
    const colors = ['#D11920','#0A0E17','#0EA5E9','#10B981','#F59E0B','#8B5CF6'];
    pieChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: cats.map(c=>c.category.replace('_',' ')),
            datasets: [{ data: cats.map(c=>c.total), backgroundColor: colors, hoverOffset:4 }]
        },
        options: { responsive:true, plugins:{legend:{position:'bottom'}} }
    });
}

// ═══════════════════════════════════════════════════════════
// ENVISION RECAP
// ═══════════════════════════════════════════════════════════
function loadEnvisionRecap() {
    lockScreenAction();
    $.post(API_URL,{action:'get_envision_recap',month:new Date().getMonth()+1},function(res){
        unlockScreenAction();
        if(res.status==='success'){ window.recapData=res.data; renderEnvisionRecap(res.data); }
    },'json');
}
function renderEnvisionRecap(data) {
    const grid = $('#envisionRecapGrid');
    if(!data.length){ grid.html('<div class="col-span-full text-center py-10 text-gray-400">No celebrations found.</div>'); return; }
    let html='';
    data.forEach(v=>{
        const img = celebPortrait(v);
        const badgeColor = celebBadgeClass(v.event_type);
        html+=`<div class="relative group rounded-3xl overflow-hidden shadow-lg bg-gray-900 border border-gray-100/10 aspect-[9/16] transition-transform duration-300 hover:-translate-y-2">
            <img src="${htmlEscape(img)}" alt="${htmlEscape(v.first_name)}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">
            <button onclick="prepImageEditor('${jsEscape(v.ref)}')" class="absolute top-4 right-4 z-20 bg-black/50 hover:bg-hodRed text-white p-2.5 rounded-full shadow-lg border border-white/20 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 13.5v3.75zM20.71 7.04a1.003 1.003 0 000-1.42l-2.34-2.34a1.003 1.003 0 00-1.42 0l-1.83 1.83 3.75 3.75 1.84-1.82z"></path></svg>
            </button>
            <div class="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/90 via-black/40 to-transparent pointer-events-none"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 z-10 flex flex-col justify-end">
                <div class="flex items-center gap-2 mb-2">
                    <span class="${badgeColor} text-white text-[9px] font-black uppercase tracking-wider px-2 py-1 rounded shadow-md">${htmlEscape(celebLabel(v.event_type))}</span>
                    <span class="bg-white/20 border border-white/30 text-white text-[9px] font-bold px-2 py-1 rounded shadow-md">${htmlEscape(v.event_date)}</span>
                </div>
                <h4 class="text-white font-black text-lg leading-tight drop-shadow-md">${htmlEscape(v.first_name)}<br>${htmlEscape(v.last_name)}</h4>
            </div>
        </div>`;
    });
    grid.html(html);
}

// ═══════════════════════════════════════════════════════════
// IMAGE EDITOR (Celebration portrait)
// ═══════════════════════════════════════════════════════════
let cropperInstance = null;
function prepImageEditor(ref) {
    $('#editorTargetUserId').val(ref);
    const member = (window.recapData||[]).find(m=>m.ref===ref);
    const img = document.getElementById('cropperImage');
    img.src = (member && member.display_picture) || '';
    if(cropperInstance){ cropperInstance.destroy(); cropperInstance=null; }
    openModal('celebrationEditorModal');
    setTimeout(()=>{ cropperInstance = new Cropper(img,{aspectRatio:9/16,viewMode:1}); },300);
}
function handlePortraitUpload(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('cropperImage');
        img.src = e.target.result;
        if(cropperInstance){ cropperInstance.destroy(); }
        cropperInstance = new Cropper(img,{aspectRatio:9/16,viewMode:1});
    };
    reader.readAsDataURL(input.files[0]);
}
function applyCanvasFilter(f) {
    const img = document.getElementById('cropperImage');
    img.style.filter = f==='grayscale'?'grayscale(100%)':f==='sepia'?'sepia(70%)':f==='brightness'?'brightness(1.3)':f==='contrast'?'contrast(1.4)':'none';
}
function saveCelebrationImage() {
    if (!cropperInstance) return;
    const canvas = cropperInstance.getCroppedCanvas({width:800,height:1422});
    const ref = $('#editorTargetUserId').val();
    const base64 = canvas.toDataURL('image/jpeg',0.88);
    lockScreenAction();
    $.post(API_URL,{action:'update_celebration_picture',ref:ref,image_base64:base64},function(res){
        unlockScreenAction(); showToast(res.message,res.status);
        if(res.status==='success'){ closeModal('celebrationEditorModal'); loadEnvisionRecap(); }
    },'json');
}
async function exportRecapJPEG() {
    if(!window.recapData||!window.recapData.length){showToast('No data to export.','warning');return;}
    const btn=document.getElementById('btnExportJPEG');
    const orig=btn.innerHTML; btn.disabled=true; lockScreenAction();

    // Rendered by /assets/js/celebrants_export.js on a plain 2D canvas rather
    // than by rasterising the DOM, so a phone and a laptop produce the exact
    // same 3840x2160 slide.
    const dateStr = new Date().toLocaleString('default',{month:'long',year:'numeric'});
    try {
        btn.innerHTML = 'Rendering...';
        const slides = await CelebrantsExport.renderSlides(window.recapData, {
            periodLabel: dateStr,
            filePrefix: `HOD_Celebrations_${dateStr.replace(/\s+/g,'_')}`,
            onProgress: (done,total) => { btn.innerHTML = `Rendering ${done}/${total}...`; }
        });
        btn.innerHTML = 'Saving...';
        const how = await CelebrantsExport.deliverSlides(slides, `HOD Celebrations — ${dateStr}`);
        unlockScreenAction(); btn.disabled=false; btn.innerHTML=orig;
        showToast(how==='shared'
            ? 'Slides ready to save or share.'
            : `Export complete — ${slides.length} slide${slides.length>1?'s':''} downloaded.`,'success');
    } catch(e){
        unlockScreenAction(); btn.disabled=false; btn.innerHTML=orig;
        showToast('Export failed: '+e.message,'error');
    }
}

// ═══════════════════════════════════════════════════════════
// AI BOOK EXTRACTION
// ═══════════════════════════════════════════════════════════
function triggerAIExtraction() {
    const front = $('#aiFrontCover')[0].files[0];
    if(!front){ showToast('Upload a Front Cover first.','warning'); return; }
    const fd = new FormData(); fd.append('action','extract_book_metadata'); fd.append('front_cover',front);
    if($('#aiBackCover')[0].files[0]) fd.append('back_cover',$('#aiBackCover')[0].files[0]);
    const btn=$('#btnAIExtract'); const orig=btn.html();
    btn.prop('disabled',true).html('Analyzing...');
    $.ajax({ url:API_URL, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
        success: function(res){
            btn.prop('disabled',false).html('✓ Extraction Complete');
            if(res.status==='success'){
                $('input[name="title"]').val(res.data.title||''); $('input[name="author"]').val(res.data.author||'');
                $('textarea[name="description"]').val(res.data.description||''); $('input[name="estimated_read_time"]').val(res.data.read_time||'');
                showToast('Metadata extracted successfully.','success');
            }
        },
        error: ()=>{ btn.prop('disabled',false).html(orig); showToast('AI extraction failed.','error'); }
    });
}

// ═══════════════════════════════════════════════════════════
// LIBRARY ADMIN
// ═══════════════════════════════════════════════════════════
function loadLibraryAdminData() {
    $.getJSON(API_URL,{action:'fetch_library_admin'},function(res){
        if(res.status!=='success') return;
        renderAdminBorrows(res.data.borrows||[]);
        renderAdminWaitlist(res.data.waitlist||[]);
        renderAdminCatalog(res.data.catalog||[]);
        const cats = [...new Set((res.data.catalog||[]).map(b=>b.category))].sort();
        $('#libraryCategories').html(cats.map(c=>`<option value="${htmlEscape(c)}">`).join(''));
        $('#catalogCountBadge').text((res.data.catalog||[]).length + ' Books');
    });
}
function renderAdminBorrows(borrows) {
    if(!borrows.length){ $('#adminBorrowsList').html('<div class="text-center py-10 text-gray-400 text-sm">No active borrows.</div>'); return; }
    let html='';
    borrows.forEach(b=>{
        const overdue = b.status==='Overdue'?'border-red-200 bg-red-50/30':'border-gray-100';
        const extBadge = b.extension_status==='Pending'?'<span class="text-[9px] bg-yellow-100 text-yellow-700 border border-yellow-200 px-2 py-0.5 rounded font-bold uppercase">Ext. Requested</span>':'';
        html+=`<div class="flex items-center justify-between p-3 bg-white border ${overdue} rounded-2xl shadow-sm gap-3">
            <div class="overflow-hidden flex-1">
                <p class="text-sm font-bold text-gray-900 truncate">${htmlEscape(b.member_name)}</p>
                <p class="text-[10px] text-gray-500 truncate">${htmlEscape(b.title)}</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Due: ${b.due_date||'—'} ${extBadge}</p>
            </div>
            <div class="flex flex-col gap-1.5 shrink-0">
                ${b.status==='Reserved'?`<button onclick="confirmBookPickup(${b.id})" class="text-[10px] font-bold bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700 transition-all">Confirm Pickup</button>`:''}
                ${b.status==='Picked_Up'||b.status==='Overdue'?`<button onclick="openReturnBookModal(${b.id},'${jsEscape(b.title)}','${jsEscape(b.member_name)}')" class="text-[10px] font-bold bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition-all">Process Return</button>`:''}
                ${b.extension_status==='Pending'?`<button onclick="approveExtension(${b.id})" class="text-[10px] font-bold bg-yellow-500 text-white px-3 py-1.5 rounded-lg hover:bg-yellow-600 transition-all">Approve Ext.</button>`:''}
                <button onclick="cancelReservation(${b.id})" class="text-[10px] font-bold text-red-400 border border-red-100 px-3 py-1.5 rounded-lg hover:bg-red-50 transition-all">Cancel</button>
            </div>
        </div>`;
    });
    $('#adminBorrowsList').html(html);
}
function renderAdminWaitlist(waitlist) {
    if(!waitlist.length){ $('#adminWaitlist').html('<div class="text-center py-10 text-gray-400 text-sm">No waitlists.</div>'); return; }
    let html='';
    waitlist.forEach(w=>{ html+=`<div class="p-3 bg-white border border-gray-100 rounded-2xl shadow-sm"><p class="text-sm font-bold text-gray-900">${htmlEscape(w.member_name)}</p><p class="text-[10px] text-gray-500">${htmlEscape(w.title)}</p><p class="text-[10px] text-orange-500 mt-0.5 uppercase font-bold">Waiting</p></div>`; });
    $('#adminWaitlist').html(html);
}
function renderAdminCatalog(catalog) {
    let html='';
    if(!catalog.length){ html='<tr><td colspan="5" class="px-6 py-12 text-center text-gray-400">No books.</td></tr>'; }
    else {
        catalog.forEach(b=>{
            const phys = b.book_type!=='E-Book'?`${b.available_copies}/${b.total_copies}`:'E-Book';
            html+=`<tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 pl-8 font-bold text-gray-900">${htmlEscape(b.title)}</td>
                <td class="px-6 py-4 text-sm text-gray-600">${htmlEscape(b.author)}</td>
                <td class="px-6 py-4"><span class="text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-100 px-2 py-0.5 rounded">${htmlEscape(b.book_type)}</span></td>
                <td class="px-6 py-4 text-right font-medium text-gray-600">${htmlEscape(phys)}</td>
                <td class="px-6 py-4 pr-8 text-right flex justify-end gap-2">
                    <button onclick="openEditBookModal(${b.id})" class="text-[10px] font-bold text-blue-600 border border-blue-100 px-3 py-1.5 rounded-lg hover:bg-blue-50 transition-all">Edit</button>
                    <button onclick="deleteBook(${b.id})" class="text-[10px] font-bold text-red-400 border border-red-100 px-3 py-1.5 rounded-lg hover:bg-red-50 transition-all">Delete</button>
                </td>
            </tr>`;
        });
    }
    $('#adminCatalogList').html(html);
}
function confirmBookPickup(id) { lockScreenAction(); $.post(API_URL,{action:'confirm_book_pickup',borrow_id:id},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadLibraryAdminData();},'json'); }
function cancelReservation(id) { if(!confirm('Cancel this reservation?'))return; lockScreenAction(); $.post(API_URL,{action:'cancel_reservation',borrow_id:id},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadLibraryAdminData();},'json'); }
function approveExtension(id) { lockScreenAction(); $.post(API_URL,{action:'approve_extension',borrow_id:id},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadLibraryAdminData();},'json'); }
function deleteBook(id) { if(!confirm('Permanently remove this book?'))return; lockScreenAction(); $.post(API_URL,{action:'delete_book',book_id:id},function(res){unlockScreenAction();showToast(res.message,res.status);if(res.status==='success')loadLibraryAdminData();},'json'); }
function openReturnBookModal(id, title, member) { $('#returnBorrowId').val(id); $('#returnBookTitle').text(title); $('#returnMemberName').text(member); openModal('returnBookModal'); }
function openEditBookModal(bookId) {
    lockScreenAction();
    $.getJSON(API_URL,{action:'fetch_library_admin'},function(res){
        unlockScreenAction();
        if(res.status!=='success') return;
        const b = (res.data.catalog||[]).find(x=>x.id==bookId);
        if(!b) return;
        $('#editBookId').val(b.id); $('#editBookTitle').val(b.title); $('#editBookAuthor').val(b.author);
        $('#editBookCategory').val(b.category); $('#editBookTypeSelect').val(b.book_type);
        $('#editBookTotalCopies').val(b.total_copies); $('#editAudiobookLink').val(b.audiobook_link||'');
        $('#editReadTime').val(b.estimated_read_time||''); $('#editBookDesc').val(b.description||'');
        document.getElementById('editPhysicalCopiesDiv').classList.toggle('hidden', b.book_type==='E-Book');
        openModal('editBookModal');
    });
}

// ═══════════════════════════════════════════════════════════
// FORM WIRES (on document ready)
// ═══════════════════════════════════════════════════════════
$(document).ready(function() {
    loadDashboardData();

    handleAjaxForm('assignWorkerForm',  ()=>{ closeModal('assignWorkerModal'); loadDashboardData(); });
    handleAjaxForm('addLifeEventForm',  ()=>{ closeModal('addLifeEventModal'); loadDashboardData(); });
    handleAjaxForm('assignWelfareForm', ()=>{ closeModal('assignWelfareModal'); loadDashboardData(); });
    handleAjaxForm('addExpenseForm',    ()=>{ closeModal('addExpenseModal'); loadFinanceData(); });
    handleAjaxForm('setBudgetForm',     ()=>{ closeModal('setBudgetModal'); loadFinanceData(); });
    handleAjaxForm('returnBookForm',    ()=>{ closeModal('returnBookModal'); loadLibraryAdminData(); });
    handleAjaxForm('addBookForm',       ()=>{ closeModal('addBookModal'); loadLibraryAdminData(); });
    handleAjaxForm('editBookForm',      ()=>{ closeModal('editBookModal'); loadLibraryAdminData(); });
    handleAjaxForm('addTaskForm',       (res)=>{ $('#addTaskForm')[0].reset(); loadEventTasks(currentTaskEventId); });
    handleAjaxForm('taskReportForm',    ()=>{ closeModal('submitTaskReportModal'); loadEventTasks(currentTaskEventId); });
    // Manage Charis Zero-Trust Notes (Save & Reload)
    handleAjaxForm('saveCharisNoteForm', ()=>{ 
        closeModal('manageCharisNotesModal'); 
        loadDashboardData(); 
        if (currentWelfareTab === 'archive') {
            loadWelfareArchive();
        } else {
            toggleWelfareTab(currentWelfareTab); 
        }
    });
    
    $(document).on('click', '.charis-edit-note', function () {
    const id   = $(this).data('note-id');
    const text = decodeURIComponent($(this).attr('data-note-text'));
    let vis = [];
    try { vis = JSON.parse(decodeURIComponent($(this).attr('data-vis'))); } catch (e) { vis = []; }
    editSpecificCharisNote(id, text, vis);
});

    // Manage Welfare — Save Note Only
    handleAjaxForm('manageWelfareForm', ()=>{ closeModal('manageWelfareModal'); loadDashboardData(); });

    // Manage Welfare — Resolve & Close
    handleAjaxForm('resolveWelfareForm', ()=>{ closeModal('manageWelfareModal'); loadDashboardData(); showToast('Case resolved. Member removed from AWOL list.','success'); });

    handleAjaxForm('bulkServiceForm', ()=>{ loadDashboardData(); });
});
</script>

