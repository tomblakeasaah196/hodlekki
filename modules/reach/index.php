<?php
// /modules/reach/index.php
require_once '../../includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-emerald-50/80 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-emerald-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div class="md:max-w-[75%] lg:max-w-sm">
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Reach & Evangelism</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Manage outreach campaigns, rapid soul capture, and follow-up leads.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <button onclick="openCampaignModal()" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                New Campaign
            </button>
            <button onclick="openSoulModal()" class="flex-1 md:flex-none bg-emerald-600 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-emerald-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Log New Soul
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Souls Won (YTD)</p><h3 id="statSouls" class="text-2xl font-black text-gray-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-emerald-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-emerald-500 uppercase tracking-widest mb-1">Campaigns</p><h3 id="statCampaigns" class="text-2xl font-black text-emerald-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-orange-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-orange-500 uppercase tracking-widest mb-1">Pending Follow-up</p><h3 id="statPending" class="text-2xl font-black text-orange-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center text-orange-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-red-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-red-500 uppercase tracking-widest mb-1">Hot Leads (Will Visit)</p><h3 id="statHotLeads" class="text-2xl font-black text-red-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-red-50 flex items-center justify-center text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path></svg></div>
        </div>
    </div>

    <div class="flex bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-md animate-fade-in-up" style="animation-delay: 0.2s;">
        <button onclick="switchTab('souls')" id="tabBtn-souls" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-emerald-700 shadow-sm">Captured Souls</button>
        <button onclick="switchTab('campaigns')" id="tabBtn-campaigns" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Campaigns</button>
    </div>

    <div id="view-souls" class="animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Evangelism Target</th>
                            <th class="px-6 py-4">Contact Info</th>
                            <th class="px-6 py-4">Spiritual Status</th>
                            <th class="px-6 py-4">Campaign Origin</th>
                            <th class="px-6 py-4 text-right">Follow-Up Action</th>
                        </tr>
                    </thead>
                    <tbody id="soulsList" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium"><svg class="animate-spin h-8 w-8 text-emerald-500 mx-auto mb-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Syncing outreach data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-campaigns" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Campaign Details</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4">Location</th>
                            <th class="px-6 py-4 text-center">Souls Reached</th>
                            <th class="px-6 py-4 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody id="campaignsList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<div id="soulModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white rounded-t-3xl shrink-0">
            <h3 class="text-xl font-display font-bold text-gray-900">Rapid Data Capture</h3>
            <button onclick="closeModal('soulModal')" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-gray-50/50">
            <form id="soulForm" class="space-y-6">
                <input type="hidden" name="action" value="save_soul">
                
                <div>
                    <label class="block text-[11px] font-bold text-emerald-800 uppercase tracking-wider mb-2">Campaign Origin</label>
                    <select name="campaign_id" id="inpCampaign" class="w-full px-4 py-3 border border-emerald-200 bg-emerald-50 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none font-bold text-emerald-900 shadow-sm cursor-pointer"></select>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">First Name *</label>
                            <input type="text" name="first_name" required placeholder="e.g., John" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Last Name</label>
                            <input type="text" name="last_name" placeholder="e.g., Doe" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-bold text-gray-900">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Phone Number</label>
                            <input type="tel" name="phone" placeholder="080..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Gender</label>
                            <select name="gender" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-bold text-gray-900 bg-white">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Home Address / Area</label>
                        <input type="text" name="address" placeholder="e.g., Lekki Phase 1" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-medium text-gray-900 text-sm">
                    </div>
                </div>

                <div class="bg-blue-50/50 p-5 rounded-2xl border border-blue-100 space-y-4">
                    <h4 class="text-xs font-bold text-blue-800 uppercase tracking-widest border-b border-blue-100 pb-2">Spiritual Status</h4>
                    
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <div class="relative flex items-center justify-center">
                            <input type="checkbox" name="is_churched" id="chkChurched" class="peer appearance-none w-6 h-6 border-2 border-blue-200 rounded-lg checked:bg-blue-600 checked:border-blue-600 transition-all">
                            <svg class="absolute w-4 h-4 text-white opacity-0 peer-checked:opacity-100 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <span class="text-sm font-bold text-gray-700 group-hover:text-blue-700 transition-colors">They are currently churched</span>
                    </label>

                    <div id="prevChurchDiv" class="hidden pl-9 transition-all">
                        <input type="text" name="previous_church" placeholder="Which church do they attend?" class="w-full px-4 py-2.5 border border-blue-200 rounded-xl focus:border-blue-500 outline-none font-medium text-gray-900 text-sm shadow-sm bg-white">
                    </div>

                    <label class="flex items-center gap-3 cursor-pointer group">
                        <div class="relative flex items-center justify-center">
                            <input type="checkbox" name="is_baptized" class="peer appearance-none w-6 h-6 border-2 border-blue-200 rounded-lg checked:bg-blue-600 checked:border-blue-600 transition-all">
                            <svg class="absolute w-4 h-4 text-white opacity-0 peer-checked:opacity-100 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <span class="text-sm font-bold text-gray-700 group-hover:text-blue-700 transition-colors">They are baptized</span>
                    </label>

                    <label class="flex items-center gap-3 cursor-pointer group">
                        <div class="relative flex items-center justify-center">
                            <input type="checkbox" name="wants_to_visit" checked class="peer appearance-none w-6 h-6 border-2 border-red-200 rounded-lg checked:bg-red-500 checked:border-red-500 transition-all">
                            <svg class="absolute w-4 h-4 text-white opacity-0 peer-checked:opacity-100 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <span class="text-sm font-bold text-red-600 group-hover:text-red-700 transition-colors">Willing to visit our church</span>
                    </label>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Prayer Requests / Notes</label>
                    <textarea name="prayer_requests" rows="2" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-emerald-500 outline-none font-medium text-gray-900 resize-none bg-white"></textarea>
                </div>
            </form>
        </div>
        <div class="p-6 border-t border-gray-100 bg-white shrink-0">
            <button type="submit" form="soulForm" class="w-full bg-emerald-600 hover:bg-emerald-800 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Secure Soul Record
            </button>
        </div>
    </div>
</div>

<div id="campaignModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Schedule Campaign</h3>
            <button onclick="closeModal('campaignModal')" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-white">
            <form id="campaignForm" class="space-y-5">
                <input type="hidden" name="action" value="save_campaign">
                
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Campaign Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Ikate Street Evangelism" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Event Type *</label>
                        <select name="campaign_type" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white cursor-pointer">
                            <option value="Saturday_Evangelism">Saturday Evangelism</option>
                            <option value="Crusade">Crusade</option>
                            <option value="Welfare_Outreach">Welfare Outreach</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Date *</label>
                        <input type="date" name="campaign_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none text-sm cursor-text">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Target Location</label>
                    <input type="text" name="location" placeholder="e.g., Orphanage Home, Surulere" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none">
                </div>
                
                <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all mt-2">Initialize Campaign</button>
            </form>
        </div>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '/api/reach_api.php';

    // ==========================================
    // UI CORE LOGIC (UPGRADED)
    // ==========================================
    function switchTab(tabId) {
        $('#view-souls, #view-campaigns').addClass('hidden').removeClass('animate-fade-in-up');
        $('#tabBtn-souls, #tabBtn-campaigns').removeClass('bg-white text-emerald-700 shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        
        $(`#view-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        $(`#tabBtn-${tabId}`).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-emerald-700 shadow-sm');
    }

    function lockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; 
        setTimeout(() => blocker.classList.remove('opacity-0'), 10);
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.add('opacity-0');
        setTimeout(() => {
            blocker.classList.add('hidden');
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
        }, 300);
    }

    function openModal(id) {
        const m = document.getElementById(id);
        if(!m) return;

        document.body.appendChild(m); // Escape parent containers
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Lock background scroll

        requestAnimationFrame(() => { 
            m.classList.remove('opacity-0'); 
            m.children[0].classList.remove('scale-95'); 
        });
    }

    function closeModal(id) {
    const m = document.getElementById(id);
    if(!m) return;

    m.classList.add('opacity-0'); 
    m.children[0].classList.add('scale-95'); 
    
    setTimeout(() => { 
        m.classList.add('hidden'); 
        
        // PATCH: Apply the same safety check used in unlockScreenAction
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }
        
        const form = m.querySelector('form'); 
        if(form) form.reset(); 
        $('#prevChurchDiv').addClass('hidden'); 
    }, 300);
}

    function showToast(msg, type = 'success') {
        Toastify({ 
            text: msg, 
            gravity: "top", 
            position: "center", 
            duration: 3000,
            style: { 
                background: type === 'success' ? "#10B981" : "#EF4444", 
                borderRadius: "10px", 
                fontWeight: "bold",
                boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
            } 
        }).showToast();
    }

    function handleAjaxForm(formId, successCallback) {
    $(`#${formId}`).on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const origHtml = btn.html(); 
        
        // PATCH: Re-introduce the inline spinner for polished UX
        const spinner = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        
        btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Processing...');
        lockScreenAction(); 
        
        $.post(API_URL, $(this).serialize(), function(res) {
            btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
            unlockScreenAction(); 
            
            showToast(res.message, res.status);
            if(res.status === 'success' && successCallback) successCallback(res);
        }, 'json').fail(function() {
            btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    });
}

    function openCampaignModal() { openModal('campaignModal'); }
    function openSoulModal() { openModal('soulModal'); }

    // Toggle Church Input
    $('#chkChurched').on('change', function() {
        if($(this).is(':checked')) {
            $('#prevChurchDiv').removeClass('hidden');
        } else {
            $('#prevChurchDiv').addClass('hidden');
            $('input[name="previous_church"]').val(''); // Clear it out
        }
    });

    // ==========================================
    // DATA RENDERING
    // ==========================================
    function loadDashboard() {
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                
                // 1. Stats
                $('#statSouls').text(res.stats.souls_ytd);
                $('#statCampaigns').text(res.stats.campaigns_ytd);
                $('#statPending').text(res.stats.pending_followups);
                $('#statHotLeads').text(res.stats.wants_to_visit);

                // 2. Render Souls List
                let sHtml = '';
                if(res.souls.length === 0) {
                    sHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium">No souls captured yet.</td></tr>';
                } else {
                    res.souls.forEach(s => {
                        // Spiritual Badges
                        let spBadges = '';
                        if(s.is_churched == 1) spBadges += `<span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase mr-1" title="${s.previous_church || 'Unknown Church'}">Churched</span>`;
                        if(s.is_baptized == 1) spBadges += `<span class="bg-blue-50 text-blue-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase mr-1">Baptized</span>`;
                        if(s.wants_to_visit == 1) spBadges += `<span class="bg-red-50 text-red-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase border border-red-100">Hot Lead</span>`;
                        
                        // Select Dropdown for inline status update
                        const selectHtml = `
                            <select onchange="updateFollowUp(${s.id}, this.value)" class="w-full sm:w-auto text-xs font-bold text-gray-700 border border-gray-200 bg-white rounded-lg px-3 py-1.5 focus:border-emerald-500 outline-none shadow-sm cursor-pointer hover:border-gray-300 transition-colors">
                                <option value="Pending_Followup" ${s.status === 'Pending_Followup' ? 'selected' : ''}>⏳ Pending Follow-Up</option>
                                <option value="Contacted" ${s.status === 'Contacted' ? 'selected' : ''}>📞 Contacted</option>
                                <option value="Visited_Church" ${s.status === 'Visited_Church' ? 'selected' : ''}>⛪ Visited Church</option>
                                <option value="Joined" ${s.status === 'Joined' ? 'selected' : ''}>✅ Officially Joined</option>
                                <option value="Lost" ${s.status === 'Lost' ? 'selected' : ''}>❌ Lost Lead</option>
                            </select>
                        `;

                        sHtml += `
                        <tr class="hover:bg-emerald-50/20 transition-colors border-b border-gray-50 last:border-0">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-400 font-bold text-xs shrink-0 border border-gray-200">${s.first_name.charAt(0)}</div>
                                    <div>
                                        <p class="font-bold text-gray-900">${s.first_name} ${s.last_name || ''}</p>
                                        <p class="text-[10px] text-gray-400 font-bold uppercase">${s.gender || 'Unknown'} • ${s.date_captured}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-xs font-bold text-gray-700">${s.phone || 'No phone'}</p>
                                <p class="text-[10px] text-gray-500 truncate max-w-[150px] mt-0.5" title="${s.address || ''}">${s.address || '-'}</p>
                            </td>
                            <td class="px-6 py-4">${spBadges || '-'}</td>
                            <td class="px-6 py-4">
                                <p class="text-xs font-bold text-emerald-700">${s.campaign_title || 'Direct Entry'}</p>
                                <p class="text-[9px] text-gray-400 font-bold uppercase mt-0.5">By: ${s.evangelist_fname || 'Unknown'}</p>
                            </td>
                            <td class="px-6 py-4 text-right">${selectHtml}</td>
                        </tr>`;
                    });
                }
                $('#soulsList').html(sHtml);

                // 3. Render Campaigns List
                let cHtml = '';
                if(res.campaigns.length === 0) {
                    cHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium">No campaigns scheduled yet.</td></tr>';
                } else {
                    res.campaigns.forEach(c => {
                        const statusBadge = c.status === 'Completed' 
                            ? '<span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-[10px] font-bold border border-gray-200 uppercase">Completed</span>' 
                            : '<span class="bg-emerald-50 text-emerald-700 px-3 py-1 rounded-lg text-[10px] font-bold border border-emerald-200 uppercase">Active</span>';
                            
                        cHtml += `
                        <tr class="hover:bg-emerald-50/20 transition-colors border-b border-gray-50 last:border-0">
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900">${c.title}</p>
                                <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider mt-0.5">${c.nice_date}</p>
                            </td>
                            <td class="px-6 py-4 text-xs font-bold text-gray-600">${c.campaign_type.replace('_', ' ')}</td>
                            <td class="px-6 py-4 text-xs font-medium text-gray-500">${c.location || '-'}</td>
                            <td class="px-6 py-4 text-center"><span class="bg-blue-50 text-blue-700 px-3 py-1 rounded-lg font-black text-xs border border-blue-100">${c.souls_won} Souls</span></td>
                            <td class="px-6 py-4 text-center">${statusBadge}</td>
                        </tr>`;
                    });
                }
                $('#campaignsList').html(cHtml);

                // 4. Populate Active Campaigns Dropdown in Soul Form
                let cOpts = '<option value="">No Specific Campaign (Direct Entry)</option>';
                res.active_campaigns.forEach(c => cOpts += `<option value="${c.id}">${c.title}</option>`);
                $('#inpCampaign').html(cOpts);
            }
        });
    }

    function updateFollowUp(soulId, newStatus) {
    lockScreenAction(); 
    
    $.post(API_URL, { action: 'update_soul_status', soul_id: soulId, status: newStatus }, function(res) {
        unlockScreenAction();
        
        if(res.status === 'success') {
            showToast(res.message, 'success');
            loadDashboard(); 
        } else {
            showToast(res.message, 'error');
            loadDashboard(); // PATCH: Force refresh to revert the dropdown if the server rejects it
        }
    }, 'json').fail(function() {
        unlockScreenAction();
        showToast('Server error occurred.', 'error');
        loadDashboard(); // PATCH: Force refresh to revert the dropdown on crash
    });
}

$(document).ready(function() {
    loadDashboard();
    handleAjaxForm('soulForm', function() { closeModal('soulModal'); loadDashboard(); });
    handleAjaxForm('campaignForm', function() { closeModal('campaignModal'); loadDashboard(); });

    // PATCH: Wire up the dead search bar
    $('#searchInput').on('keyup', function() {
        const val = $(this).val().toLowerCase();
        $('#soulsList tr').each(function() {
            if($(this).find('td').attr('colspan')) return; // Skip the "Syncing..." row
            $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
        });
    });
});

</script>

<?php require_once '../../includes/footer.php'; ?>