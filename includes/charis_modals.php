<?php
// /includes/charis_modals.php
// All modals for the Charis module (welfare, events, finance, library)
?>

<!-- Compact welfare overview modals (opened by the phone/tablet section cards). -->
<div id="birthdaysOverviewModal" role="dialog" aria-modal="true" aria-labelledby="birthdaysOverviewTitle" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-3 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90dvh] transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-5 sm:px-6 py-4 sm:py-5 border-b border-blue-100 flex items-center justify-between gap-4 bg-blue-50/60 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <span class="h-10 w-10 rounded-xl bg-white border border-blue-100 text-hodBlue flex items-center justify-center shadow-sm shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15.546c-.523 0-1.046.151-1.5.454a2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.704 2.704 0 01-3 0 2.704 2.704 0 00-3 0 2.701 2.701 0 00-1.5-.454M9 6v2m3-2v2m3-2v2M9 3h.01M12 3h.01M15 3h.01M21 21v-7a2 2 0 00-2-2H5a2 2 0 00-2 2v7h18zm-3-9v-2a2 2 0 00-2-2H8a2 2 0 00-2 2v2h12z"></path></svg>
                </span>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 id="birthdaysOverviewTitle" class="text-base sm:text-lg font-bold text-gray-900 truncate">Upcoming Birthdays</h3>
                        <span id="birthdaysModalCount" class="text-[9px] font-black text-blue-700 bg-white border border-blue-100 rounded-full px-2 py-1 shrink-0">—</span>
                    </div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500 mt-0.5">This month &amp; next</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('birthdaysOverviewModal')" aria-label="Close upcoming birthdays" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-4 sm:p-5 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/40">
            <ul id="birthdaysModalList" class="space-y-3">
                <li class="flex flex-col items-center justify-center py-12 text-gray-400"><svg class="animate-spin h-8 w-8 text-hodBlue mb-3" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-sm font-medium">Loading...</span></li>
            </ul>
        </div>
    </div>
</div>

<div id="lifeEventsOverviewModal" role="dialog" aria-modal="true" aria-labelledby="lifeEventsOverviewTitle" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-3 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90dvh] transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-5 sm:px-6 py-4 sm:py-5 border-b border-red-100 flex items-center justify-between gap-4 bg-red-50/60 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <span class="h-10 w-10 rounded-xl bg-white border border-red-100 text-hodRed flex items-center justify-center shadow-sm shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                </span>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 id="lifeEventsOverviewTitle" class="text-base sm:text-lg font-bold text-gray-900 truncate">Life Events</h3>
                        <span id="lifeEventsModalCount" class="text-[9px] font-black text-red-700 bg-white border border-red-100 rounded-full px-2 py-1 shrink-0">—</span>
                    </div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-red-500 mt-0.5">Weddings &amp; milestones</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('lifeEventsOverviewModal')" aria-label="Close life events" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-4 sm:p-5 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/40">
            <ul id="anniversariesModalList" class="space-y-3">
                <li class="flex flex-col items-center justify-center py-12 text-gray-400"><svg class="animate-spin h-8 w-8 text-hodRed mb-3" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-sm font-medium">Loading...</span></li>
            </ul>
        </div>
    </div>
</div>

<div id="welfareOverviewModal" role="dialog" aria-modal="true" aria-labelledby="welfareOverviewTitle" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-3 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[92dvh] transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-5 sm:px-6 pt-4 sm:pt-5 pb-3 border-b border-orange-100 bg-orange-50/60 shrink-0">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="h-10 w-10 rounded-xl bg-white border border-orange-100 text-orange-500 flex items-center justify-center shadow-sm shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 id="welfareOverviewTitle" class="text-base sm:text-lg font-bold text-gray-900 truncate">AWOL Monitoring</h3>
                            <span id="welfareModalCount" class="text-[9px] font-black text-orange-700 bg-white border border-orange-100 rounded-full px-2 py-1 shrink-0">—</span>
                        </div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-orange-500 mt-0.5">Absence alerts &amp; follow-up</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('welfareOverviewModal')" aria-label="Close AWOL Monitoring" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div role="tablist" aria-label="AWOL Monitoring views" class="mt-3 flex bg-orange-100/60 p-1 rounded-xl overflow-x-auto no-scrollbar gap-1">
                <button type="button" role="tab" aria-selected="true" data-welfare-tab="my_cases" onclick="toggleWelfareTab('my_cases')" class="welfare-tab-button px-3 sm:px-4 py-2 rounded-lg text-[10px] sm:text-xs font-bold bg-white text-orange-600 shadow-sm transition-all whitespace-nowrap flex-1">My Cases</button>
                <button type="button" role="tab" aria-selected="false" data-welfare-tab="awol_list" onclick="toggleWelfareTab('awol_list')" class="welfare-tab-button px-3 sm:px-4 py-2 rounded-lg text-[10px] sm:text-xs font-bold text-gray-500 hover:text-gray-900 transition-all whitespace-nowrap flex-1">AWOL List</button>
                <button type="button" role="tab" aria-selected="false" data-welfare-tab="manual_flags" onclick="toggleWelfareTab('manual_flags')" class="welfare-tab-button px-3 sm:px-4 py-2 rounded-lg text-[10px] sm:text-xs font-bold text-gray-500 hover:text-gray-900 transition-all whitespace-nowrap flex-1">Manual Flags</button>
                <button type="button" role="tab" aria-selected="false" data-welfare-tab="archive" onclick="toggleWelfareTab('archive')" class="welfare-tab-button px-3 sm:px-4 py-2 rounded-lg text-[10px] sm:text-xs font-bold text-gray-500 hover:text-gray-900 transition-all whitespace-nowrap flex-1">Archive</button>
            </div>
        </div>
        <div class="p-4 sm:p-5 flex-1 overflow-y-auto custom-scrollbar bg-gray-50/40">
            <div id="awolModalPillsContainer" class="hidden mb-3.5 p-3 sm:p-4 bg-white border border-orange-100 rounded-2xl shadow-sm">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="text-[10px] font-black uppercase tracking-wider text-orange-600">Active Church-wide Rule</span>
                    <span id="awolModalMatchingCount" class="text-[10px] font-bold text-gray-500">0 matching</span>
                </div>
                <div id="awolModalPillsList" class="flex flex-wrap gap-1.5"></div>
            </div>
            <ul id="welfareModalList" class="welfare-list space-y-3"></ul>
            <div id="awolModalEditorFooter" class="hidden pt-4 mt-4 border-t border-gray-200/80">
                <button type="button" onclick="openAwolConfigModal()" class="w-full bg-orange-600 hover:bg-orange-700 text-white py-3 rounded-xl font-bold text-xs shadow-md transition-all flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Change AWOL List
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Configurable AWOL Rule Setup / Editor Modal -->
<div id="awolConfigModal" role="dialog" aria-modal="true" aria-labelledby="awolConfigModalTitle" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[10000] flex items-center justify-center p-3 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl max-h-[92dvh] transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-5 sm:px-6 py-4 sm:py-5 border-b border-orange-100 flex items-center justify-between gap-4 bg-orange-50/60 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <span class="h-10 w-10 rounded-xl bg-white border border-orange-100 text-orange-600 flex items-center justify-center shadow-sm shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                </span>
                <div class="min-w-0">
                    <h3 id="awolConfigModalTitle" class="text-base sm:text-lg font-bold text-gray-900 truncate">Configure AWOL Monitoring Rule</h3>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-orange-500 mt-0.5">Church-wide absence rule</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('awolConfigModal')" aria-label="Close AWOL configuration" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <form id="awolConfigForm" class="p-5 sm:p-6 overflow-y-auto custom-scrollbar space-y-5 bg-white">
            <input type="hidden" name="action" value="save_awol_config">
            <input type="hidden" name="is_first_time" id="awolConfigIsFirstTime" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="awolCfgServicesMissed" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Services Missed (N) *</label>
                    <input type="number" name="services_missed" id="awolCfgServicesMissed" min="1" max="52" required value="3" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-lg font-black text-gray-900 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                    <p class="text-[11px] text-gray-400 font-medium mt-1">Minimum total services missed</p>
                </div>
                <div>
                    <label for="awolCfgPeriodWeeks" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Period of Focus (W weeks) *</label>
                    <div class="relative">
                        <input type="number" name="period_weeks" id="awolCfgPeriodWeeks" min="1" max="104" required value="4" class="w-full px-4 py-3 pr-16 border border-gray-200 rounded-xl text-lg font-black text-gray-900 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400 uppercase pointer-events-none">weeks</span>
                    </div>
                    <p class="text-[11px] text-gray-400 font-medium mt-1">Rolling calendar weeks in Lagos time</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Qualifying Service Types *</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5" id="awolServiceTypesGroup">
                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 hover:border-orange-200 transition-all has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="service_types[]" value="Sunday_Service" checked class="cfg-awol-service-type w-4 h-4 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Sunday Service</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 hover:border-orange-200 transition-all has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="service_types[]" value="Midweek_Service" checked class="cfg-awol-service-type w-4 h-4 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Thursday Midweek Service</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Spiritual Statuses to Monitor *</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" id="awolSpiritualStatusesGroup">
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="Member" checked class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Member</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="Worker" checked class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Worker</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="Pastor" checked class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Pastor</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="1st_Timer" class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">1st Timer</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="2nd_Timer" class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">2nd Timer</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="3rd_Timer" class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">3rd Timer</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="Visitor" class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Visitor</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer hover:bg-orange-50/50 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                        <input type="checkbox" name="spiritual_statuses[]" value="Non_Member" class="cfg-awol-status w-3.5 h-3.5 text-orange-600 rounded border-gray-300 focus:ring-orange-500">
                        <span class="text-xs font-bold text-gray-800">Non-Member</span>
                    </label>
                </div>
            </div>

            <div>
                <label for="awolConfigReason" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Reason for Rule Change (optional)</label>
                <input type="text" name="change_reason" id="awolConfigReason" placeholder="e.g. Adjusted focus period for end-of-year review" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-xs font-medium text-gray-900 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
            </div>

            <!-- Dynamic Live Rule Sentence Preview -->
            <div class="p-3.5 bg-orange-50 border border-orange-200 rounded-2xl">
                <p class="text-[10px] font-black uppercase tracking-wider text-orange-600 mb-1">Live Rule Preview</p>
                <p id="awolConfigSentencePreview" class="text-xs font-bold text-gray-800 leading-relaxed">
                    Flag members with spiritual status (Member, Worker, Pastor) who missed 3 or more qualifying Sunday Service(s) in the last 4 rolling calendar weeks.
                </p>
            </div>

            <!-- Configuration History -->
            <div class="pt-2">
                <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">Recent Rule History</p>
                <div id="awolConfigHistoryList" class="space-y-2 max-h-36 overflow-y-auto custom-scrollbar">
                    <p class="text-xs text-gray-400 italic">No previous rule changes recorded.</p>
                </div>
            </div>

            <button type="submit" id="awolConfigSubmitBtn" class="w-full bg-orange-600 hover:bg-orange-700 text-white py-3.5 rounded-xl font-black text-sm shadow-lg transition-all flex items-center justify-center gap-2">
                Save &amp; Apply AWOL List
            </button>
        </form>
    </div>
</div>

<div id="envisionRecapModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50 shrink-0 sticky top-0 z-50">
            <div><h3 class="text-lg font-bold text-gray-900">Celebrants Overview</h3><p class="text-xs text-gray-500 font-medium">Ready for high-res landscape export</p></div>
            <div class="flex gap-2 shrink-0">
                <button id="btnExportJPEG" onclick="exportRecapJPEG()" type="button" class="flex-1 sm:flex-none text-xs bg-hodBlue hover:bg-gray-900 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Export Media</button>
                <button onclick="closeModal('envisionRecapModal')" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
        </div>
        <div class="p-6"><div id="envisionRecapGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6"></div></div>
    </div>
</div>

<div id="assignWorkerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Assign Charis Worker</h3>
            <button onclick="closeModal('assignWorkerModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWorkerForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="assign_celebration">
            <input type="hidden" name="target_user_id" id="assignTargetId">
            <input type="hidden" name="event_type" id="assignEventType">
            <input type="hidden" name="event_date" id="assignEventDate">
            <div>
                <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Celebrating</p>
                <p id="assignTargetName" class="text-xl font-black text-gray-900 mt-1"></p>
                <p id="assignEventBadge" class="text-[10px] font-bold uppercase tracking-widest text-hodBlue mt-1"></p>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assign Charis Worker</label>
                <select name="worker_id" id="workerSelectDropdown" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold text-gray-700 cursor-pointer"></select>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Assign Now</button>
        </form>
    </div>
</div>

<div id="addLifeEventModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Log Life Event</h3>
            <button onclick="closeModal('addLifeEventModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="addLifeEventForm" class="p-8 space-y-5">
            <input type="hidden" name="action" value="add_life_event">
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Member *</label><select name="user_id" id="memberSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm"></select></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Event Type *</label>
                    <select name="event_type" id="eventTypeSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm cursor-pointer" onchange="document.getElementById('customEventDiv').classList.toggle('hidden', this.value!=='Other')">
                        <option value="Wedding_Anniversary">Wedding Anniversary</option>
                        <option value="Baby_Dedication">Baby Dedication</option>
                        <option value="Child_Birth">Child Birth</option>
                        <option value="Other">Other...</option>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Event Date *</label><input type="date" name="event_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm"></div>
            </div>
            <div id="customEventDiv" class="hidden"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Custom Event Name</label><input type="text" name="custom_event_type" placeholder="e.g., Graduation, Work Anniversary" class="w-full px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-blue-900 font-bold focus:ring-1 focus:ring-hodBlue outline-none"></div>
            <button type="submit" class="w-full bg-hodRed hover:bg-red-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Life Event</button>
        </form>
    </div>
</div>

<div id="manageWelfareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[85vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Welfare: <span id="welfareMemberName" class="text-orange-500"></span></h3>
            <button onclick="closeModal('manageWelfareModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex gap-3">
                <a id="btnWelfareCall" href="#" class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 py-3 rounded-xl flex justify-center items-center gap-2 font-bold transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg Call
                </a>
                <a id="btnWelfareWhatsApp" href="#" target="_blank" class="flex-1 bg-[#25D366]/10 hover:bg-[#25D366]/20 text-[#25D366] border border-[#25D366]/30 py-3 rounded-xl flex justify-center items-center gap-2 font-bold transition-colors">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 0C5.385 0 .002 5.385.002 12.032c0 2.128.555 4.2 1.613 6.027L0 24l6.104-1.603c1.764.957 3.738 1.464 5.925 1.464 6.645 0 12.028-5.385 12.028-12.032C24.057 5.385 18.676 0 12.031 0zm5.426 16.436c-.297-.15-1.764-.87-2.037-.97-.27-.1-.47-.15-.668.15-.2.298-.77 1-.944 1.203-.175.204-.35.23-.648.08-.297-.15-1.258-.464-2.395-1.485-.886-.795-1.484-1.776-1.66-2.075-.174-.298-.018-.46.13-.61.134-.135.297-.348.446-.522.15-.175.2-.298.3-.497.1-.2.05-.376-.025-.522-.075-.15-.668-1.613-.916-2.208-.242-.58-.488-.503-.668-.513-.174-.01-.375-.01-.574-.01-.2 0-.524.075-.798.375s-1.047 1.17-1.047 2.855c0 1.685 1.07 3.315 1.22 3.515.15.2 2.4 3.664 5.816 5.14.814.35 1.45.56 1.946.717.818.26 1.56.223 2.146.135.654-.1 2.037-.833 2.324-1.637.288-.804.288-1.493.2-1.637-.088-.144-.336-.23-.634-.38z"/></svg> WhatsApp
                </a>
            </div>
            <hr class="border-gray-100">
            <form id="manageWelfareForm" class="space-y-4">
                <input type="hidden" name="action" value="update_awol_status">
                <input type="hidden" name="user_id" id="welfareUserId">
                <input type="hidden" name="followup_id" id="welfareFollowupId">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Welfare Findings / Comments *</label>
                    <textarea name="comments" rows="3" required placeholder="E.g., Spoke to them — traveling for work but will return next week..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 outline-none text-sm resize-none"></textarea>
                </div>
                <button type="submit" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-3 rounded-xl font-bold transition-all">Save Note Only</button>
            </form>
            <hr class="border-gray-100">
            <form id="resolveWelfareForm" class="space-y-4">
                <input type="hidden" name="action" value="resolve_awol_case">
                <input type="hidden" name="user_id" id="resolveWelfareUserId">
                <input type="hidden" name="followup_id" id="resolveWelfareFollowupId" value="0">
                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                    <p class="text-xs font-black text-green-800 uppercase tracking-wider mb-2">✓ Resolve &amp; Remove from AWOL List</p>
                    <p class="text-xs text-green-700 mb-3">This permanently closes the case for this cycle. The member's name will leave the AWOL list.</p>
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Final Status</label>
                        <select name="attendance_status" class="w-full px-3 py-2.5 border border-green-200 rounded-lg text-sm font-bold bg-white outline-none cursor-pointer">
                            <option value="Active">Active (Re-engaged &amp; attending)</option>
                            <option value="Relocated">Relocated</option>
                            <option value="Attends_Another_Church">Attends Another Church</option>
                            <option value="Unknown">Unknown — cannot be reached</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Summary Note *</label>
                        <textarea name="comments" rows="2" required placeholder="Brief summary for the record..." class="w-full px-3 py-2.5 border border-green-200 rounded-lg text-sm outline-none focus:border-green-400 resize-none"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md">✓ Resolve &amp; Close Case</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="assignWelfareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Assign Welfare Case</h3>
            <button onclick="closeModal('assignWelfareModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWelfareForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="assign_welfare_case">
            <input type="hidden" name="target_user_id" id="assignWelTargetId">
            <input type="hidden" name="followup_id" id="assignWelFollowupId">
            <div><label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assign to Worker</label><select name="worker_id" id="welWorkerDropdown" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold cursor-pointer"></select></div>
            <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Assign Case</button>
        </form>
    </div>
</div>

<div id="awolReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <div><h3 class="text-lg font-bold text-gray-900">Generate AWOL Report</h3><p class="text-xs text-gray-400 mt-0.5">Downloads as a branded PDF for pastoral review.</p></div>
            <button onclick="closeModal('awolReportModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex bg-gray-100 p-1 rounded-xl gap-1">
                <button onclick="setReportMode('month')" id="rptModeMonth" class="flex-1 px-4 py-2 rounded-lg text-sm font-bold bg-white text-gray-900 shadow-sm transition-all">By Month</button>
                <button onclick="setReportMode('range')" id="rptModeRange" class="flex-1 px-4 py-2 rounded-lg text-sm font-bold text-gray-500 transition-all">Custom Range</button>
            </div>
            <div id="rptMonthFields" class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Month</label>
                    <select id="rptMonth" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($m==date('n')?'selected':'')?> ><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                    <select id="rptYear" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
                    </select>
                </div>
            </div>
            <div id="rptRangeFields" class="hidden grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">From Date</label><input type="date" id="rptDateStart" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">To Date</label><input type="date" id="rptDateEnd" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none"></div>
            </div>
            <button onclick="downloadAwolReport()" class="w-full bg-orange-600 hover:bg-orange-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download PDF Report
            </button>
        </div>
    </div>
</div>

<div id="manageCharisNotesModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b bg-gray-50 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Welfare Notes: <span id="charis_notes_target_name" class="text-hodBlue"></span></h3>
            <button onclick="closeModal('manageCharisNotesModal')"><svg class="w-6 h-6 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 space-y-4 custom-scrollbar bg-gray-50/50" id="existingCharisNotesContainer">
            </div>

        <div id="charisNoteComposer" class="p-6 border-t border-gray-100 bg-white shrink-0">
            <form id="saveCharisNoteForm" class="space-y-4">
                <input type="hidden" name="action" value="save_charis_note">
                <input type="hidden" name="target_user_id" id="note_target_user_id">
                <input type="hidden" name="note_id" id="edit_charis_note_id" value="">

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1" id="charisNoteInputLabel">Add New Note</label>
                    <textarea name="note_text" id="charis_note_text_input" required placeholder="Type welfare updates or findings here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl min-h-[100px] text-sm outline-none focus:border-hodBlue resize-none whitespace-pre-wrap"></textarea>
                </div>

                <div id="charisPastorOnlyWrap" class="bg-purple-50/50 p-4 rounded-xl border border-purple-100 hidden">
    <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" name="pastor_only" id="cb_charis_pastor_only" value="1" class="w-4 h-4 text-purple-600 focus:ring-purple-500 rounded">
        <span class="text-xs font-bold text-gray-700">Pastors only
            <span class="font-medium text-gray-400">— hide this note from everyone except pastors</span>
        </span>
    </label>
</div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="resetCharisNoteForm()" class="px-5 py-3 bg-gray-100 text-gray-600 hover:bg-gray-200 font-bold rounded-xl transition text-sm hidden" id="cancelEditCharisNoteBtn">Cancel Edit</button>
                    <button type="submit" class="flex-1 bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all" id="saveCharisNoteBtn">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="eventTasksModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-2 sm:p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-y-auto custom-scrollbar max-h-[92vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20">
            <div>
                <h3 class="text-lg font-bold text-gray-900" id="taskModalTitle">Event Tasks</h3>
                <p class="text-xs text-gray-400 mt-0.5" id="taskModalDate"></p>
            </div>
            <div class="flex items-center gap-2">
                <a id="btnDownloadEventReport" href="#" target="_blank" class="hidden text-xs bg-gray-800 text-white px-4 py-2 rounded-xl font-bold hover:bg-black transition-all flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> PDF Report
                </a>
                <button onclick="closeModal('eventTasksModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full border border-gray-200 bg-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
        </div>

        <div id="addTaskSection" class="hidden p-5 bg-blue-50/50 border-b border-blue-100">
            <p class="text-xs font-black text-gray-500 uppercase tracking-wider mb-3">Add New Task</p>
            <form id="addTaskForm" class="space-y-3">
                <input type="hidden" name="action" value="add_event_task">
                <input type="hidden" name="event_id" id="taskEventId">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><input type="text" name="task_title" required placeholder="Task title (e.g., Water and Food Purchase)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue bg-white"></div>
                    <div><input type="text" name="task_description" placeholder="Description (optional)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue bg-white"></div>
                    <div><select name="task_category" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-white outline-none cursor-pointer"><option value="Logistics">Logistics</option><option value="Supplies">Supplies</option></select></div>
                    <div><select name="assigned_worker_id" id="taskWorkerDropdown" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-white outline-none cursor-pointer"><option value="">Assign to member...</option></select></div>
                    <div><input type="number" step="0.01" min="0" name="estimated_budget" placeholder="Estimated Budget (₦)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue bg-white"></div>
                </div>
                <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-2.5 rounded-xl text-sm font-bold transition-all">Add Task & Notify Member</button>
            </form>
        </div>

        <div class="p-5 flex-1">
            <div id="tasksList" class="space-y-4">
                <div class="text-center py-10 text-gray-400">Loading tasks...</div>
            </div>
        </div>

        <div id="hodSignOffSection" class="hidden p-5 border-t border-gray-100 bg-gray-50 shrink-0">
            <div id="stampDisplay" class="mb-4 hidden"></div>
            <button id="btnHodStamp" onclick="applyHodStamp()" class="w-full bg-gray-900 hover:bg-black text-white py-3 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Sign Off Event (HOD Digital Stamp)
            </button>
            <button id="btnFinanceStamp" onclick="applyFinanceStamp()" class="hidden w-full mt-2 bg-blue-700 hover:bg-blue-900 text-white py-3 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Finance Director Countersign
            </button>
        </div>
    </div>
</div>

<div id="submitTaskReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[10000] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Submit Task Report</h3>
            <button onclick="closeModal('submitTaskReportModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="taskReportForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_event_task">
            <input type="hidden" name="task_id" id="reportTaskId">
            <input type="hidden" name="status" value="Completed">
            <p class="text-sm font-bold text-gray-700" id="reportTaskTitle"></p>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">What did you buy / do? *</label><textarea name="member_report" rows="3" required placeholder="E.g., Bought 4 cartons of water from LekkiMart, paid ₦12,000..." class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Actual Amount Spent (₦) *</label><input type="number" step="0.01" min="0" name="actual_spent" required placeholder="0.00" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Receipt Reference</label><input type="text" name="receipt_note" placeholder="E.g., Receipt #4412" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue"></div>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Submit & Mark Completed</button>
        </form>
    </div>
</div>

<div id="setBudgetModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Set Monthly Budget</h3>
            <button onclick="closeModal('setBudgetModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="setBudgetForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="set_monthly_budget">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Month</label>
                    <select name="budget_month" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($m==date('n')?'selected':'')?> ><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                    <select name="budget_year" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
                    </select>
                </div>
            </div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Budget Amount (₦) *</label><input type="number" step="0.01" min="0" name="amount" required placeholder="e.g., 150000.00" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-green-500"></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Notes</label><input type="text" name="notes" placeholder="Optional notes" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-green-500"></div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Budget</button>
        </form>
    </div>
</div>

<div id="addExpenseModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Log New Expense</h3>
            <button onclick="closeModal('addExpenseModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="addExpenseForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="log_expense">
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Item / Purpose *</label><input type="text" name="item_name" required class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue" placeholder="E.g., Sunday Service Water"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label>
                    <select name="category" required class="w-full px-3 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <option value="Food_Beverage">Food &amp; Beverage</option>
                        <option value="Pastoral_Care">Pastoral Care</option>
                        <option value="Stationery">Stationery</option>
                        <option value="Decor">Decor</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Amount Req. (₦) *</label><input type="number" step="0.01" name="amount_requested" required class="w-full px-3 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue" placeholder="0.00"></div>
            </div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Link to Event</label><select name="event_id" id="expenseEventSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer"><option value="">-- General / Non-Event Expense --</option></select></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Notes</label><textarea name="notes" rows="2" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Submit Expense</button>
        </form>
    </div>
</div>

<div id="addBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20"><h3 class="text-lg font-bold text-gray-900">Add New Book</h3><button onclick="closeModal('addBookModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full bg-white shadow-sm border border-gray-100"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="addBookForm" class="p-6 pb-8 space-y-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_new_book">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Book Title *</label><input type="text" name="title" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Author *</label><input type="text" name="author" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label><input list="libraryCategories" name="category" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"><datalist id="libraryCategories"></datalist></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Format *</label>
                    <select name="book_type" id="bookTypeSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue cursor-pointer" onchange="document.getElementById('physicalCopiesDiv').classList.toggle('hidden',this.value==='E-Book'); document.getElementById('ebookUploadDiv').classList.toggle('hidden',this.value==='Physical')">
                        <option value="Physical">Physical Only</option><option value="E-Book">E-Book Only</option><option value="Both">Both</option>
                    </select>
                </div>
                <div id="physicalCopiesDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Total Copies</label><input type="number" name="total_copies" min="0" value="1" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Front Cover</label><input type="file" name="cover_image" id="aiFrontCover" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Back Cover (for AI)</label><input type="file" name="back_cover" id="aiBackCover" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div class="col-span-2"><button type="button" onclick="triggerAIExtraction()" id="btnAIExtract" class="w-full bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-4 py-3 rounded-xl font-bold text-sm transition-all flex justify-center items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg> AI Extract from Cover</button></div>
                <div class="col-span-2 hidden" id="ebookUploadDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">E-Book PDF *</label><input type="file" name="ebook_file" id="ebookFileInput" accept="application/pdf" class="w-full px-4 py-2.5 bg-red-50 border border-red-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Audiobook Link</label><input type="url" name="audiobook_link" placeholder="https://..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Est. Read Time</label><input type="text" name="estimated_read_time" placeholder="e.g., 6 hours" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label><textarea name="description" rows="3" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Add to Library</button>
        </form>
    </div>
</div>

<div id="editBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20"><h3 class="text-lg font-bold text-gray-900">Edit Book</h3><button onclick="closeModal('editBookModal')" class="text-gray-400 hover:text-red-500 bg-white shadow-sm border border-gray-100 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="editBookForm" class="p-6 pb-8 space-y-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_book">
            <input type="hidden" name="book_id" id="editBookId">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Title *</label><input type="text" name="title" id="editBookTitle" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Author *</label><input type="text" name="author" id="editBookAuthor" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label><input list="libraryCategories" name="category" id="editBookCategory" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Format</label><select name="book_type" id="editBookTypeSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue cursor-pointer"><option value="Physical">Physical Only</option><option value="E-Book">E-Book Only</option><option value="Both">Both</option></select></div>
                <div id="editPhysicalCopiesDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Total Copies</label><input type="number" name="total_copies" id="editBookTotalCopies" min="0" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Audiobook Link</label><input type="url" name="audiobook_link" id="editAudiobookLink" placeholder="https://..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Read Time</label><input type="text" name="estimated_read_time" id="editReadTime" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label><textarea name="description" id="editBookDesc" rows="3" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Replace Cover Image</label><input type="file" name="cover_image" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold cursor-pointer"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Replace E-Book PDF</label><input type="file" name="ebook_file" accept="application/pdf" class="w-full px-4 py-2.5 bg-red-50 border border-red-200 rounded-xl text-xs font-bold cursor-pointer"></div>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Changes</button>
        </form>
    </div>
</div>

<div id="returnBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0"><h3 class="text-lg font-bold text-gray-900">Process Book Return</h3><button onclick="closeModal('returnBookModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="returnBookForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="process_book_return">
            <input type="hidden" name="borrow_id" id="returnBorrowId">
            <div class="text-center mb-2"><p class="text-sm text-gray-500">Receiving from:</p><p id="returnMemberName" class="text-lg font-black text-gray-900 mt-1"></p><p id="returnBookTitle" class="text-xs font-bold text-hodBlue mt-1"></p></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Book Condition</label>
                <select name="condition" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold cursor-pointer">
                    <option value="Good">Good Condition</option><option value="Damaged">Damaged</option><option value="Lost">Lost</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Confirm Return</button>
        </form>
    </div>
</div>

<div id="celebrationEditorModal" class="fixed inset-0 w-screen h-screen bg-gray-900/95 hidden z-[10001] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0"><h3 class="text-lg font-bold text-gray-900">Celebration Portrait Editor</h3><button onclick="closeModal('celebrationEditorModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <div class="p-6 space-y-4">
            <div class="relative bg-gray-100 rounded-xl overflow-hidden" style="height:300px;"><img id="cropperImage" src="" alt="Crop" style="display:block; max-width:100%;"></div>
            <div class="flex flex-wrap gap-2 justify-center">
                <button onclick="applyCanvasFilter('none')"         class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Original</button>
                <button onclick="applyCanvasFilter('grayscale')"    class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">B&W</button>
                <button onclick="applyCanvasFilter('sepia')"        class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Sepia</button>
                <button onclick="applyCanvasFilter('brightness')"   class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Brighten</button>
                <button onclick="applyCanvasFilter('contrast')"     class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Contrast</button>
            </div>
            <input type="hidden" id="editorTargetUserId">
            <div class="flex gap-3">
                <label class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2 cursor-pointer transition-all"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg> Upload New<input type="file" id="newPortraitUpload" accept="image/*" class="hidden" onchange="handlePortraitUpload(this)"></label>
                <button onclick="saveCelebrationImage()" class="flex-1 bg-hodRed hover:bg-red-700 text-white py-3 rounded-xl font-bold text-sm transition-all shadow-lg">Save Portrait</button>
            </div>
        </div>
    </div>
</div>