<?php
// /modules/tribes/index.php

// 1. Pull in the centralized Header
require_once '../../includes/header.php'; 
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10">
            <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Tribes Ministry</h2>
            <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Manage house fellowships, duty rosters, and Rhema reports.</p>
        </div>
        
        <div id="adminActions" class="hidden relative z-10 shrink-0">
            <button onclick="openModal('createTribeModal')" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold transition-all duration-300 shadow-lg shadow-blue-900/20 hover:shadow-blue-900/40 hover:-translate-y-0.5 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Create New Tribe
            </button>
        </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative min-h-[500px] flex flex-col">
        
        <div id="loadingOverlay" class="absolute inset-0 bg-white/90 backdrop-blur-sm z-20 flex flex-col items-center justify-center">
            <svg class="animate-spin h-10 w-10 text-hodBlue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing tribe ecosystem...</p>
        </div>

        <div class="border-b border-gray-100/80 bg-gray-50/30 px-6 pt-2">
            <nav class="flex space-x-8 overflow-x-auto custom-scrollbar" aria-label="Tabs">
                <button id="tab-btn-global" onclick="switchTab('global')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-all duration-300">
                    Global Dashboard
                </button>
                <button id="tab-btn-mytribe" onclick="switchTab('mytribe')" class="whitespace-nowrap py-4 px-2 border-b-2 border-hodBlue font-bold text-sm text-hodBlue transition-all duration-300">
                    My Tribe
                </button>
                <button id="tab-btn-meetings" onclick="switchTab('meetings')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-all duration-300">
                    Meetings & Rhema
                </button>
                <button id="tab-btn-roster" onclick="switchTab('roster')" class="hidden whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-all duration-300">
                    Duty Roster
                </button>
            </nav>
        </div>

        <div class="p-6 md:p-8 flex-1">
            
            <div id="tab-content-global" class="hidden space-y-6 animate-fade-in-up">
                <h3 class="text-xl font-display font-bold text-gray-900">All Church Tribes</h3>
                <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-4">Tribe Name</th>
                                <th class="px-6 py-4">Captain</th>
                                <th class="px-6 py-4">Meeting Day</th>
                                <th class="px-6 py-4">Location</th>
                                <th class="px-6 py-4 text-center">Members</th>
                            </tr>
                        </thead>
                        <tbody id="globalTribesTable" class="divide-y divide-gray-50">
                            </tbody>
                    </table>
                </div>
            </div>

            <div id="tab-content-mytribe" class="space-y-8 animate-fade-in-up">
                
                <div id="noTribeState" class="hidden text-center py-16 px-4 border border-dashed border-gray-200 rounded-3xl bg-gray-50/50">
                    <div class="w-20 h-20 bg-white shadow-sm rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <h3 class="text-xl font-display font-bold text-gray-900">No Tribe Assigned</h3>
                    <p class="text-gray-500 mt-2 max-w-md mx-auto">You have not been assigned to a House Fellowship yet. Please contact administration.</p>
                </div>

                <div id="myTribeData" class="hidden space-y-8">
                    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-hodBlue to-[#2A4B8D] p-8 md:p-10 text-white shadow-lg shadow-blue-900/10">
                        <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full blur-3xl -mr-20 -mt-20"></div>
                        <div class="relative z-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
                            <div>
                                <p class="text-blue-200 text-xs font-bold uppercase tracking-widest mb-1">Your Community</p>
                                <h3 id="mt_name" class="text-3xl md:text-4xl font-display font-bold mb-4 text-white">Tribe Name</h3>
                                <div class="flex flex-col sm:flex-row gap-4 sm:gap-6">
                                    <span class="flex items-center gap-2 bg-black/20 backdrop-blur-md px-4 py-2 rounded-xl text-sm font-medium border border-white/10">
                                        <svg class="w-5 h-5 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg> 
                                        <span id="mt_day"></span>
                                    </span>
                                    <span class="flex items-center gap-2 bg-black/20 backdrop-blur-md px-4 py-2 rounded-xl text-sm font-medium border border-white/10">
                                        <svg class="w-5 h-5 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg> 
                                        <span id="mt_location" class="truncate max-w-[200px] md:max-w-full"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-6">
                            <h4 class="font-display font-bold text-gray-900 text-lg">Tribe Members</h4>
                            <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-xs font-bold">Directory</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" id="mt_members_grid">
                            </div>
                    </div>
                </div>
            </div>

            <div id="tab-content-meetings" class="hidden space-y-6 animate-fade-in-up">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
                    <div>
                        <h3 class="text-xl font-display font-bold text-gray-900">Upcoming Activities</h3>
                        <p class="text-sm text-gray-500">Track and report on your tribe's gatherings.</p>
                    </div>
                    <button onclick="openModal('createActivityModal')" class="bg-hodRed hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-red-500/20 hover:-translate-y-0.5 transition-all w-full sm:w-auto text-center">
                        Schedule Activity
                    </button>
                </div>
                <div class="grid gap-4" id="mt_activities_list">
                    </div>
            </div>

            <div id="tab-content-roster" class="hidden space-y-6 animate-fade-in-up">
                
                <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Weekly Duty Roster</h3>
                        <p class="text-sm text-gray-500">Assign tasks to tribe members for upcoming meetings.</p>
                    </div>
                    <div class="w-full sm:w-72">
                        <select id="rosterEventSelect" class="w-full border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent py-2.5 px-4 bg-white shadow-sm outline-none font-medium text-gray-700 cursor-pointer" onchange="loadRoster(this.value)">
                            <option value="">Select an upcoming meeting...</option>
                        </select>
                    </div>
                </div>

                <div id="rosterEmptyState" class="text-center py-16 px-4 border border-dashed border-gray-200 rounded-3xl bg-gray-50/50">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                    <p class="text-gray-500 font-medium">Please select a meeting from the dropdown above to view or assign duties.</p>
                </div>

                <div id="rosterDisplayArea" class="hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 lg:gap-8">
                        
                        <div class="lg:col-span-3 bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                            <h4 class="font-bold text-gray-900 mb-5 border-b border-gray-100 pb-3 flex items-center gap-2">
                                <svg class="w-5 h-5 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                                Assigned Duties
                            </h4>
                            <ul id="rosterList" class="space-y-3">
                                </ul>
                        </div>

                        <div class="lg:col-span-2 bg-gray-50/80 p-6 rounded-3xl border border-gray-100 shadow-inner">
                            <h4 class="font-bold text-gray-900 mb-5 flex items-center gap-2">
                                <svg class="w-5 h-5 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                Assign New Task
                            </h4>
                            <form id="assignRosterForm" class="space-y-4">
                                <input type="hidden" name="action" value="assign_roster">
                                <input type="hidden" name="event_id" id="assign_roster_event_id">
                                
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Task / Duty</label>
                                    <input type="text" name="task_name" placeholder="e.g., Opening Prayer, Worship" required 
                                        class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all shadow-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Assign To Member</label>
                                    <select name="assigned_user_id" id="rosterMemberSelect" required 
                                        class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all bg-white shadow-sm cursor-pointer">
                                        </select>
                                </div>
                                <button type="submit" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-4 py-3.5 rounded-xl text-sm font-bold shadow-md hover:-translate-y-0.5 transition-all mt-2">
                                    Assign Task
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="createTribeModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 border border-white/20">
        <div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-xl font-display font-bold text-gray-900">Create Tribe</h3>
            <button onclick="closeModal('createTribeModal')" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="createTribeForm" class="p-8 space-y-5">
            <input type="hidden" name="action" value="create_tribe">
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Tribe Name <span class="text-hodRed">*</span></label>
                <input type="text" name="name" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all shadow-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Meeting Location <span class="text-hodRed">*</span></label>
                <input type="text" name="location_address" required placeholder="Full address or area" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all shadow-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Meeting Day</label>
                <select name="meeting_day" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none bg-white transition-all shadow-sm cursor-pointer">
                    <option value="Friday">Friday</option>
                    <option value="Saturday">Saturday</option>
                    <option value="Sunday" selected>Sunday</option>
                </select>
            </div>
            <div class="pt-4 flex justify-end gap-3 mt-2">
                <button type="button" onclick="closeModal('createTribeModal')" class="px-5 py-2.5 text-gray-600 hover:bg-gray-100 rounded-xl font-bold transition-colors">Cancel</button>
                <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-2.5 rounded-xl font-bold transition-all shadow-md hover:-translate-y-0.5">Create Tribe</button>
            </div>
        </form>
    </div>
</div>

<div id="createActivityModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 border border-white/20">
        <div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-xl font-display font-bold text-gray-900">Schedule Activity</h3>
            <button onclick="closeModal('createActivityModal')" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="createActivityForm" class="p-8 space-y-5">
            <input type="hidden" name="action" value="create_activity">
            <input type="hidden" name="tribe_id" id="schedule_tribe_id">
            
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Title <span class="text-hodRed">*</span></label>
                <input type="text" name="title" required placeholder="e.g., Weekly Rhema Fellowship" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all shadow-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Category</label>
                    <select name="category" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none bg-white transition-all shadow-sm cursor-pointer">
                        <option value="Tribe_Meeting">Rhema / Meeting</option>
                        <option value="Evangelism">Outreach</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Date & Time <span class="text-hodRed">*</span></label>
                    <input type="datetime-local" name="event_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all shadow-sm">
                </div>
            </div>
            
            <div class="pt-4 flex justify-end gap-3 mt-2">
                <button type="button" onclick="closeModal('createActivityModal')" class="px-5 py-2.5 text-gray-600 hover:bg-gray-100 rounded-xl font-bold transition-colors">Cancel</button>
                <button type="submit" class="bg-hodRed hover:bg-red-700 text-white px-6 py-2.5 rounded-xl font-bold transition-all shadow-md hover:-translate-y-0.5">Schedule</button>
            </div>
        </form>
    </div>
</div>

<div id="rhemaReportModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 border border-white/20">
        <div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-xl font-display font-bold text-gray-900">Submit Report</h3>
            <button onclick="closeModal('rhemaReportModal')" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="rhemaReportForm" class="p-8 space-y-4">
            <input type="hidden" name="action" value="save_report">
            <input type="hidden" name="event_id" id="report_event_id">
            
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-4">
                <p class="text-sm font-bold text-hodBlue" id="report_event_title"></p>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Discussion Notes / Impact <span class="text-hodRed">*</span></label>
                <textarea name="report_notes" rows="6" required placeholder="Document the key points discussed, testimonies, or impact made..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none resize-none transition-all shadow-sm"></textarea>
            </div>
            
            <div class="pt-4 flex justify-end gap-3 mt-2 border-t border-gray-100 pt-6">
                <button type="button" onclick="closeModal('rhemaReportModal')" class="px-5 py-2.5 text-gray-600 hover:bg-gray-100 rounded-xl font-bold transition-colors">Cancel</button>
                <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-2.5 rounded-xl font-bold transition-all shadow-md hover:-translate-y-0.5">Save Report</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Absolute path fixed!
    const API_URL = '/api/tribes_api.php';
    let currentTribeMembers = [];

    // --- Tab Switching Logic (Upgraded with active styling) ---
    function switchTab(tabId) {
        $('[id^="tab-btn-"]').removeClass('border-hodBlue text-hodBlue').addClass('border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300');
        $(`#tab-btn-${tabId}`).removeClass('border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300').addClass('border-hodBlue text-hodBlue');
        
        $('[id^="tab-content-"]').addClass('hidden').removeClass('animate-fade-in-up');
        
        // Use a tiny timeout to re-trigger the CSS animation
        setTimeout(() => {
            $(`#tab-content-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        }, 10);
    }

    // --- Modal Controls ---
    function openModal(id) {
        const modal = document.getElementById(id);
        const inner = modal.children[0];
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); inner.classList.remove('scale-95'); }, 10);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        const inner = modal.children[0];
        modal.classList.add('opacity-0');
        inner.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); const form = modal.querySelector('form'); if(form) form.reset(); }, 300);
    }

    // Prepare Report Modal
    function prepReportModal(eventId, eventTitle) {
        $('#report_event_id').val(eventId);
        $('#report_event_title').text("For: " + eventTitle);
        openModal('rhemaReportModal');
    }

    // --- Data Loaders ---
    function loadDashboardData() {
        $('#loadingOverlay').removeClass('hidden');
        
        $.ajax({
            url: API_URL,
            type: 'GET',
            data: { action: 'fetch_dashboard' },
            dataType: 'json',
            success: function(res) {
                $('#loadingOverlay').addClass('hidden');
                
                if(res.status === 'success') {
                    
                    // 1. Render Global Data (If Admin/Director)
                    if (res.global_data !== null) {
                        $('#adminActions').removeClass('hidden');
                        $('#tab-btn-global').removeClass('hidden');
                        switchTab('global'); 
                        
                        let globalHtml = '';
                        res.global_data.forEach(t => {
                            globalHtml += `
                            <tr class="hover:bg-blue-50/30 transition-colors duration-200 border-b border-gray-50 last:border-0">
                                <td class="px-6 py-4 font-bold text-gray-900">${t.name}</td>
                                <td class="px-6 py-4 font-medium text-gray-700">${t.captain_name || '<span class="text-gray-400 italic font-normal">Unassigned</span>'}</td>
                                <td class="px-6 py-4 text-gray-600">${t.meeting_day}s</td>
                                <td class="px-6 py-4 text-xs text-gray-500 truncate max-w-[200px]" title="${t.location_address}">${t.location_address}</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center justify-center h-7 px-3 rounded-lg bg-blue-50 border border-blue-100 text-blue-700 font-bold text-xs">${t.member_count}</span>
                                </td>
                            </tr>`;
                        });
                        $('#globalTribesTable').html(globalHtml || '<tr><td colspan="5" class="text-center py-10 text-gray-500 font-medium">No tribes created yet.</td></tr>');
                    } else {
                        switchTab('mytribe');
                    }

                    // 2. Render My Tribe Data
                    if (res.my_tribe) {
                        const tribe = res.my_tribe;
                        $('#noTribeState').addClass('hidden');
                        $('#myTribeData').removeClass('hidden');
                        $('#tab-btn-meetings, #tab-btn-roster').removeClass('hidden');
                        
                        $('#schedule_tribe_id').val(tribe.id);
                        $('#mt_name').text(tribe.name);
                        $('#mt_day').text(tribe.meeting_day + "s");
                        $('#mt_location').text(tribe.location_address);

                        // Members Grid (Upgraded to Avatars)
                        let memHtml = '';
                        let memberSelectOpts = '<option value="">Select a member...</option>';
                        if(tribe.members && tribe.members.length > 0) {
                            tribe.members.forEach(m => {
                                memHtml += `
                                <div class="bg-white border border-gray-100 rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition-shadow">
                                    <div class="h-12 w-12 rounded-full bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 text-hodBlue flex items-center justify-center font-bold text-sm shrink-0 shadow-inner">
                                        ${m.first_name.charAt(0)}${m.last_name.charAt(0)}
                                    </div>
                                    <div class="overflow-hidden">
                                        <p class="text-sm font-bold text-gray-900 truncate">${m.first_name} ${m.last_name}</p>
                                        <p class="text-xs text-gray-500 mt-0.5">${m.phone}</p>
                                    </div>
                                </div>`;
                                memberSelectOpts += `<option value="${m.id}">${m.first_name} ${m.last_name}</option>`;
                            });
                        } else {
                            memHtml = '<div class="col-span-full py-8 text-center text-gray-500 text-sm font-medium border border-dashed rounded-2xl">No members assigned yet.</div>';
                        }
                        $('#mt_members_grid').html(memHtml);
                        $('#rosterMemberSelect').html(memberSelectOpts);

                        // Activities & Meetings List
                        let actHtml = '';
                        let eventSelectOpts = '<option value="">Select an upcoming meeting...</option>';
                        if(tribe.upcoming_events && tribe.upcoming_events.length > 0) {
                            tribe.upcoming_events.forEach(e => {
                                const dt = new Date(e.event_date).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                                const catLabel = e.event_category === 'Evangelism' ? 'Outreach' : 'Rhema Meeting';
                                
                                actHtml += `
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between bg-white border border-gray-100 p-5 rounded-2xl shadow-sm hover:shadow-md hover:border-blue-100 transition-all duration-300 gap-4 group">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider">${catLabel}</span>
                                        </div>
                                        <h4 class="font-bold text-gray-900 text-lg">${e.title}</h4>
                                        <p class="text-sm text-gray-500 mt-1 flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                            ${dt}
                                        </p>
                                    </div>
                                    <button onclick="prepReportModal(${e.id}, '${e.title.replace(/'/g, "\\'")}')" class="w-full sm:w-auto text-sm font-bold text-hodBlue bg-blue-50 hover:bg-hodBlue hover:text-white px-5 py-2.5 rounded-xl transition-all shadow-sm">
                                        Submit Report
                                    </button>
                                </div>`;
                                eventSelectOpts += `<option value="${e.id}">${e.title} (${new Date(e.event_date).toLocaleDateString()})</option>`;
                            });
                        } else {
                            actHtml = '<div class="py-8 text-center bg-gray-50/50 rounded-2xl border border-dashed border-gray-200 text-gray-500 text-sm font-medium">No upcoming activities scheduled.</div>';
                        }
                        $('#mt_activities_list').html(actHtml);
                        $('#rosterEventSelect').html(eventSelectOpts);

                    } else {
                        $('#noTribeState').removeClass('hidden');
                        $('#myTribeData, #tab-btn-meetings, #tab-btn-roster').addClass('hidden');
                    }
                }
            }
        });
    }

    // --- Roster Logic ---
    function loadRoster(eventId) {
        if(!eventId) {
            $('#rosterDisplayArea').addClass('hidden');
            $('#rosterEmptyState').removeClass('hidden');
            return;
        }

        $('#assign_roster_event_id').val(eventId);
        $('#rosterEmptyState').addClass('hidden');
        $('#rosterDisplayArea').removeClass('hidden');
        $('#rosterList').html('<li class="text-sm text-gray-400 font-medium py-4 flex items-center gap-2"><svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Loading roster...</li>');

        $.ajax({
            url: API_URL,
            type: 'GET',
            data: { action: 'fetch_roster', event_id: eventId },
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    let html = '';
                    if(res.data.length === 0) {
                        html = '<li class="text-sm text-gray-500 font-medium py-4 bg-gray-50 rounded-xl px-4 text-center border border-dashed border-gray-200">No duties assigned yet. Use the form to assign tasks.</li>';
                    } else {
                        res.data.forEach(r => {
                            html += `
                            <li class="flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-white border border-gray-100 rounded-xl shadow-sm hover:shadow-md transition-shadow gap-2">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-red-50 text-hodRed flex items-center justify-center shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                                    </div>
                                    <span class="text-sm font-bold text-gray-800">${r.task_name}</span>
                                </div>
                                <span class="text-xs font-bold bg-blue-50 text-hodBlue px-3 py-1.5 rounded-lg border border-blue-100 inline-block text-center">${r.assigned_name}</span>
                            </li>`;
                        });
                    }
                    $('#rosterList').html(html);
                }
            }
        });
    }

    // --- Global Form Handler ---
    $(document).ready(function() {
        loadDashboardData();

        function handleAjaxForm(formId, modalId, requiresRefresh = true) {
            $(`#${formId}`).on('submit', function(e) {
                e.preventDefault();
                const btn = $(this).find('button[type="submit"]');
                const originalText = btn.html();
                btn.prop('disabled', true).html(`
                    <svg class="animate-spin h-4 w-4 text-white inline mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    Saving...
                `);

                $.ajax({
                    url: API_URL,
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(res) {
                        btn.prop('disabled', false).html(originalText);
                        Toastify({
                            text: res.message,
                            duration: 3500,
                            gravity: "top",
                            position: "right",
                            style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "500", boxShadow: "0 10px 15px -3px rgba(0, 0, 0, 0.1)" }
                        }).showToast();
                        
                        if(res.status === 'success') {
                            if(modalId) closeModal(modalId);
                            else $(`#${formId}`)[0].reset(); // Reset inline form

                            if(requiresRefresh) loadDashboardData();
                            
                            // Specific refresh for Roster inline form
                            if(formId === 'assignRosterForm') {
                                loadRoster($('#assign_roster_event_id').val());
                            }
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(originalText);
                        Toastify({ text: "Network error occurred.", style: { background: "#EF4444", borderRadius: "10px", fontWeight: "500" } }).showToast();
                    }
                });
            });
        }

        // Bind Forms
        handleAjaxForm('createTribeForm', 'createTribeModal');
        handleAjaxForm('createActivityForm', 'createActivityModal');
        handleAjaxForm('rhemaReportForm', 'rhemaReportModal', false); 
        handleAjaxForm('assignRosterForm', null, false); 
    });
</script>

<?php 
// 2. Pull in the centralized Footer
require_once '../../includes/footer.php'; 
?>