<?php
// /modules/river_of_life/index.php
require_once '../../includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-purple-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-purple-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path></svg>
            </div>
            <div class="md:max-w-[75%] lg:max-w-sm">
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">River of Life</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Manage choir sections, band technicalities, and rigorous rehearsal schedules.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <button onclick="openRehearsalModal()" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:text-purple-700 hover:border-purple-300 hover:bg-purple-50 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                Schedule Rehearsal
            </button>
            <button onclick="openAssignModal()" class="flex-1 md:flex-none bg-purple-600 hover:bg-purple-800 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-purple-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Assign Member
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Total Team</p><h3 id="statTotal" class="text-2xl font-black text-gray-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-pink-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-pink-500 uppercase tracking-widest mb-1">Vocalists</p><h3 id="statVocals" class="text-2xl font-black text-pink-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-pink-50 flex items-center justify-center text-pink-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-indigo-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-indigo-500 uppercase tracking-widest mb-1">Band</p><h3 id="statBand" class="text-2xl font-black text-indigo-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-600 uppercase tracking-widest mb-1">Tech Team</p><h3 id="statTech" class="text-2xl font-black text-gray-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg></div>
        </div>
    </div>

    <div class="flex bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-md animate-fade-in-up" style="animation-delay: 0.2s;">
        <button onclick="switchTab('kanban')" id="tabBtn-kanban" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-purple-700 shadow-sm">Roster Kanban</button>
        <button onclick="switchTab('rehearsals')" id="tabBtn-rehearsals" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Rehearsals</button>
    </div>

    <div id="view-kanban" class="animate-fade-in-up space-y-8" style="animation-delay: 0.3s;">
        <div class="py-20 text-center text-gray-400"><svg class="animate-spin h-8 w-8 text-purple-600 mx-auto mb-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Syncing structure...</div>
    </div>

    <div id="view-rehearsals" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Rehearsal Schedule</th>
                            <th class="px-6 py-4">Focus / Target Song</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-center">Turnout</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rehearsalsList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-attendance" class="hidden animate-fade-in-up">
        <div class="mb-4 flex justify-between items-center">
            <button onclick="switchTab('rehearsals')" class="text-sm font-bold text-gray-500 hover:text-purple-700 flex items-center gap-2 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> Back to Rehearsals
            </button>
            <div class="bg-white px-4 py-1.5 rounded-xl border border-gray-200 shadow-sm flex items-center gap-4">
                <div class="text-center"><span class="block text-[9px] font-bold text-gray-400 uppercase">Present</span><span class="text-lg font-black text-green-600" id="attCountPresent">0</span></div>
                <div class="w-px h-6 bg-gray-200"></div>
                <div class="text-center"><span class="block text-[9px] font-bold text-gray-400 uppercase">Absent</span><span class="text-lg font-black text-red-600" id="attCountAbsent">0</span></div>
                <div class="w-px h-6 bg-gray-200"></div>
                <div class="text-center"><span class="block text-[9px] font-bold text-gray-400 uppercase">Excused</span><span class="text-lg font-black text-yellow-600" id="attCountExcused">0</span></div>
            </div>
        </div>
        
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-6 md:p-8 border-b border-gray-100 bg-gradient-to-r from-purple-50/50 to-white flex flex-col justify-center">
                <h3 class="text-2xl font-display font-bold text-gray-900" id="attSvcTopic">Loading Topic...</h3>
                <p class="text-sm font-bold text-purple-700 mt-1" id="attSvcDate">Loading Date...</p>
            </div>
            <div class="p-6 md:p-8">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6" id="attendanceGrid">
                    </div>
            </div>
        </div>
    </div>

</div>

<div id="assignModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Assign Member to Section</h3>
            <button onclick="closeModal('assignModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1.5 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="assignForm" class="overflow-y-auto flex-1 p-6 space-y-5 custom-scrollbar bg-white">
            <input type="hidden" name="action" value="assign_member">
            
            <div class="bg-purple-50/50 rounded-xl p-4 border border-purple-100 flex gap-3 items-start">
                <svg class="w-5 h-5 text-purple-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <p class="text-xs text-purple-800 leading-relaxed font-medium">Only members officially added to Department 4 (River of Life) by the Admin will appear in this list.</p>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Available Member *</label>
                <select name="user_id" id="inpAssignUser" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-500 outline-none font-bold text-gray-900 bg-white shadow-sm"></select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Role Category *</label>
                <select name="role_category" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-500 outline-none font-bold text-gray-900 bg-white shadow-sm">
                    <option value="Vocalist">Vocalist</option>
                    <option value="Band_Instrumentalist">Band & Instrumentalist</option>
                    <option value="Technical">Technical & Sound</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Specific Section / Instrument *</label>
                <input type="text" name="section_name" required placeholder="e.g., Soprano, Drums, Audio Mixing" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-500 outline-none font-bold text-gray-900 shadow-sm">
            </div>
            
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all mt-2 shrink-0">Add to Roster</button>
        </form>
    </div>
</div>

<div id="addSongModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md p-6 transform scale-95 transition-transform duration-300 flex flex-col">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Add Song to Event Setlist</h3>
        
        <form id="addSongForm" class="space-y-4">
            <input type="hidden" name="action" value="upload_audio_key">
            <input type="hidden" name="event_id" id="setlistEventId" value="">
            <input type="hidden" name="song_id" id="setlistSongId" value="1"> <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Performance Key (e.g., Eb)</label>
                <input type="text" name="performance_key" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-500 outline-none font-bold">
            </div>

            <div class="bg-purple-50 p-4 rounded-xl border border-purple-100 text-center space-y-3">
                <p class="text-xs font-bold text-purple-800 uppercase tracking-widest">Record Starting Pitch (Max 20s)</p>
                
                <button type="button" id="btnRecordAudio" class="bg-purple-600 text-white w-14 h-14 rounded-full flex items-center justify-center mx-auto shadow-lg hover:bg-purple-800 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                </button>
                <p id="recordingStatus" class="text-xs text-purple-600 font-bold hidden animate-pulse">Recording... Click to Stop</p>
                
                <audio id="audioPreview" controls class="w-full h-8 hidden mt-2"></audio>
            </div>

            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeModal('addSongModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-600 rounded-xl font-bold">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-3 bg-gray-900 text-white rounded-xl font-bold">Save to Setlist</button>
            </div>
        </form>
    </div>
</div>

<div id="rehearsalModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Schedule Rehearsal</h3>
            <button onclick="closeModal('rehearsalModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1.5 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="rehearsalForm" class="overflow-y-auto flex-1 p-6 space-y-5 custom-scrollbar bg-white">
            <input type="hidden" name="action" value="schedule_rehearsal">
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Rehearsal Date & Time *</label>
                <input type="datetime-local" name="rehearsal_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-purple-500 outline-none cursor-text">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Focus Topic / Target Songs *</label>
                <input type="text" name="focus_topic" required placeholder="e.g., Sunday Service Prep: 'Way Maker'" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-purple-500 outline-none">
            </div>
            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all shrink-0">Initialize Tracker</button>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '/api/rol_api.php';

    // ==========================================
    // UI CORE LOGIC (UPGRADED)
    // ==========================================
    function switchTab(tabId) {
        $('#view-kanban, #view-rehearsals, #view-attendance').addClass('hidden').removeClass('animate-fade-in-up');
        $('#tabBtn-kanban, #tabBtn-rehearsals').removeClass('bg-white text-purple-700 shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        
        $(`#view-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        if(tabId !== 'attendance') {
            $(`#tabBtn-${tabId}`).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-purple-700 shadow-sm');
        }
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

        document.body.appendChild(m); // Viewport escape
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Scroll lock

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
        
        // PATCH: Apply the global safety check before unlocking the scroll
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }
        
        const form = m.querySelector('form'); 
        if(form) form.reset(); 
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
        
        // PATCH: Bring back the inline SVG spinner
        const spinner = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        
        btn.prop('disabled', true).html(spinner + 'Processing...');
        lockScreenAction();
        
        $.post(API_URL, $(this).serialize(), function(res) {
            btn.prop('disabled', false).html(origHtml);
            unlockScreenAction();
            
            showToast(res.message, res.status);
            if(res.status === 'success' && successCallback) successCallback(res);
        }, 'json').fail(function() {
            btn.prop('disabled', false).html(origHtml);
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    });
}

    function openAssignModal() { openModal('assignModal'); }
    function openRehearsalModal() { openModal('rehearsalModal'); }

    // ==========================================
    // DATA RENDERING
    // ==========================================
    const categoryMeta = {
        'Vocalist': { title: 'Vocalists', color: 'pink', bg: 'bg-pink-50', text: 'text-pink-600', border: 'border-pink-200' },
        'Band_Instrumentalist': { title: 'Band & Instruments', color: 'indigo', bg: 'bg-indigo-50', text: 'text-indigo-600', border: 'border-indigo-200' },
        'Technical': { title: 'Technical Team', color: 'gray', bg: 'bg-gray-100', text: 'text-gray-700', border: 'border-gray-200' }
    };

    function loadDashboard() {
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                
                // 1. Stats
                $('#statTotal').text(res.stats.total_members);
                $('#statVocals').text(res.stats.vocalists);
                $('#statBand').text(res.stats.band);
                $('#statTech').text(res.stats.tech);

                // 2. Render Kanban Boards
                let kanbanHtml = '';
                const grp = res.grouped_roster;
                
                ['Vocalist', 'Band_Instrumentalist', 'Technical'].forEach(cat => {
                    const meta = categoryMeta[cat];
                    const sections = grp[cat];
                    
                    kanbanHtml += `
                    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between ${meta.bg}">
                            <h3 class="text-lg font-bold ${meta.text}">${meta.title}</h3>
                        </div>
                        <div class="p-6 overflow-x-auto custom-scrollbar flex gap-6 items-start min-h-[200px]">`;
                    
                    if(Object.keys(sections).length === 0) {
                        kanbanHtml += `<div class="w-full text-center py-8 text-gray-400 text-sm font-medium">No members assigned to this category yet.</div>`;
                    } else {
                        // Render each Section as a Kanban Column
                        for (const [sectionName, members] of Object.entries(sections)) {
                            kanbanHtml += `
                            <div class="w-72 shrink-0 bg-gray-50 rounded-2xl p-4 border border-gray-100 flex flex-col max-h-[500px]">
                                <div class="flex justify-between items-center mb-3">
                                    <h4 class="font-bold text-gray-900 text-sm uppercase tracking-wider">${sectionName}</h4>
                                    <span class="bg-white text-gray-500 text-xs font-black px-2 py-0.5 rounded shadow-sm border border-gray-100">${members.length}</span>
                                </div>
                                <div class="overflow-y-auto custom-scrollbar space-y-3 pr-1">`;
                                
                            members.forEach(m => {
                                const pic = m.picture_path ? `<img src="${m.picture_path}" class="w-10 h-10 rounded-full object-cover shadow-sm">` : `<div class="w-10 h-10 rounded-full bg-white flex items-center justify-center font-bold text-gray-400 text-sm shadow-sm border border-gray-100">${m.first_name.charAt(0)}${m.last_name.charAt(0)}</div>`;
                                kanbanHtml += `
                                <div class="bg-white p-3 rounded-xl shadow-sm border border-gray-100 hover:border-purple-200 transition-colors group relative">
                                    <div class="flex items-center gap-3">
                                        ${pic}
                                        <div class="truncate">
                                            <p class="font-bold text-gray-900 text-sm truncate">${m.first_name} ${m.last_name}</p>
                                            <p class="text-[10px] text-gray-500 truncate">${m.phone || 'No Phone'} • ${m.gender}</p>
                                        </div>
                                    </div>
                                    <button onclick="removeMember(${m.roster_id})" class="absolute top-3 right-3 text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all" title="Remove from Roster">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </div>`;
                            });

                            kanbanHtml += `</div></div>`;
                        }
                    }
                    kanbanHtml += `</div></div>`;
                });
                
                $('#view-kanban').html(kanbanHtml);

                // 3. Render Rehearsals
                let rHtml = '';
                if(res.rehearsals.length === 0) rHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No rehearsals scheduled.</td></tr>';
                else {
                    res.rehearsals.forEach(r => {
                        const statusBadge = r.status === 'Completed' 
                            ? '<span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-xs font-bold border border-gray-200">Completed</span>' 
                            : '<span class="bg-green-50 text-green-700 px-3 py-1 rounded-lg text-xs font-bold border border-green-200">Scheduled</span>';
                            
                        rHtml += `
                        <tr class="hover:bg-purple-50/30 transition-colors border-b border-gray-50 last:border-0">
                            <td class="px-6 py-4 font-bold text-purple-700 whitespace-nowrap">${r.nice_date}</td>
                            <td class="px-6 py-4 font-bold text-gray-900">${r.focus_topic}</td>
                            <td class="px-6 py-4 text-center">${statusBadge}</td>
                            <td class="px-6 py-4 text-center"><span class="bg-blue-50 text-blue-700 px-3 py-1 rounded-lg font-bold text-xs border border-blue-100">${r.present_count} / ${r.total_expected} Present</span></td>
                            <td class="px-6 py-4 text-right">
                                <button onclick="viewAttendance(${r.id}, '${r.nice_date}', '${r.focus_topic.replace(/'/g, "\\'")}')" class="text-xs bg-purple-600 hover:bg-purple-800 text-white px-4 py-2 rounded-lg font-bold shadow-sm transition-colors">Take Roll-Call</button>
                            </td>
                        </tr>`;
                    });
                }
                $('#rehearsalsList').html(rHtml);

                // 4. Populate Assignment Dropdown
                let uOpts = '<option value="">Select available member...</option>';
                if(res.available_members.length === 0) {
                    uOpts = '<option value="" disabled>No available members found in Dept 4</option>';
                } else {
                    res.available_members.forEach(u => uOpts += `<option value="${u.id}">${u.first_name} ${u.last_name} (${u.gender})</option>`);
                }
                $('#inpAssignUser').html(uOpts);
            }
        });
    }

    // ==========================================
    // ACTION HELPERS
    // ==========================================
    function removeMember(rosterId) {
        if(!confirm('Are you sure you want to remove this member from their section?')) return;
        lockScreenAction();
        $.post(API_URL, { action: 'remove_member', roster_id: rosterId }, function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if(res.status === 'success') loadDashboard();
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    // ==========================================
    // ATTENDANCE ENGINE (3-State)
    // ==========================================
    function viewAttendance(rehearsalId, dateNice, topic) {
        $('#attSvcDate').text(dateNice);
        $('#attSvcTopic').text(topic);
        $('#attendanceGrid').html('<div class="col-span-full py-10 text-center text-gray-500">Loading roster...</div>');
        
        switchTab('attendance'); // Clear buttons and switch view

        $.post(API_URL, { action: 'fetch_attendance', rehearsal_id: rehearsalId }, function(res) {
            if(res.status === 'success') {
                let aHtml = '';
                let counts = { Present: 0, Absent: 0, Excused: 0 };
                
                res.attendance.forEach(c => {
                    counts[c.status]++;
                    
                    const pic = c.picture_path ? `<img src="${c.picture_path}" class="w-10 h-10 rounded-full object-cover">` : `<div class="w-10 h-10 rounded-full bg-white flex items-center justify-center font-bold text-gray-400 text-sm border border-gray-200">${c.first_name.charAt(0)}</div>`;
                    
                    const btnP = c.status === 'Present' ? 'bg-green-500 text-white border-green-600 shadow-inner' : 'bg-white text-gray-400 border-gray-200 hover:bg-gray-50';
                    const btnA = c.status === 'Absent' ? 'bg-red-500 text-white border-red-600 shadow-inner' : 'bg-white text-gray-400 border-gray-200 hover:bg-gray-50';
                    const btnE = c.status === 'Excused' ? 'bg-yellow-500 text-white border-yellow-600 shadow-inner' : 'bg-white text-gray-400 border-gray-200 hover:bg-gray-50';

                    aHtml += `
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between bg-gray-50 p-4 rounded-2xl border border-gray-100 gap-4">
                        <div class="flex items-center gap-3">
                            ${pic}
                            <div>
                                <p class="font-bold text-sm text-gray-900">${c.first_name} ${c.last_name}</p>
                                <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">${c.section_name}</p>
                            </div>
                        </div>
                        <div class="flex items-center bg-gray-200/50 p-1 rounded-xl w-full sm:w-auto">
                            <button onclick="markAttendance(${rehearsalId}, ${c.user_id}, 'Present')" class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg text-xs font-bold transition-all border ${btnP}">P</button>
                            <button onclick="markAttendance(${rehearsalId}, ${c.user_id}, 'Absent')" class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg text-xs font-bold transition-all border mx-1 ${btnA}">A</button>
                            <button onclick="markAttendance(${rehearsalId}, ${c.user_id}, 'Excused')" class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg text-xs font-bold transition-all border ${btnE}">E</button>
                        </div>
                    </div>`;
                });
                $('#attendanceGrid').html(aHtml);
                $('#attCountPresent').text(counts.Present);
                $('#attCountAbsent').text(counts.Absent);
                $('#attCountExcused').text(counts.Excused);
            }
        }, 'json');
    }

    function markAttendance(rehearsalId, userId, newStatus) {
    // PATCH: Grab the clicked button and its parent container to apply a local lock, NOT a global screen lock.
    const btn = window.event ? window.event.currentTarget : null;
    const btnContainer = btn ? btn.parentElement : null;
    
    if (btnContainer) {
        btnContainer.style.opacity = '0.5';
        btnContainer.style.pointerEvents = 'none';
    }

    // Removed lockScreenAction() so the user can rapidly click through the list
    
    $.post(API_URL, { action: 'mark_attendance', rehearsal_id: rehearsalId, user_id: userId, status: newStatus }, function(res){
        if(res.status === 'success') {
            // Optimistic UI happens on reload of viewAttendance to ensure accurate counts
            viewAttendance(rehearsalId, $('#attSvcDate').text(), $('#attSvcTopic').text());
        } else {
            showToast(res.message, 'error');
            if (btnContainer) {
                btnContainer.style.opacity = '1';
                btnContainer.style.pointerEvents = 'auto';
            }
        }
    }, 'json').fail(function() {
        showToast("Server Error", "error");
        if (btnContainer) {
            btnContainer.style.opacity = '1';
            btnContainer.style.pointerEvents = 'auto';
        }
    });
}

    $(document).ready(function() {
        loadDashboard();
        handleAjaxForm('assignForm', function() { closeModal('assignModal'); loadDashboard(); });
        handleAjaxForm('rehearsalForm', function() { closeModal('rehearsalModal'); loadDashboard(); });
    });
    
    // ==========================================
// AUDIO RECORDER ENGINE
// ==========================================
let mediaRecorder;
let audioChunks = [];
let audioBlob = null;
let isRecording = false;

$('#btnRecordAudio').on('click', async function() {
    const statusText = $('#recordingStatus');
    const audioPreview = $('#audioPreview');

    if (!isRecording) {
        // 1. START RECORDING
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            
            mediaRecorder.ondataavailable = event => {
                if (event.data.size > 0) audioChunks.push(event.data);
            };

            mediaRecorder.onstop = () => {
                // Bundle chunks into an audio file
                audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                audioChunks = []; // reset
                
                // Show playback preview
                const audioUrl = URL.createObjectURL(audioBlob);
                audioPreview.attr('src', audioUrl).removeClass('hidden');
                
                // Reset UI
                $(this).removeClass('bg-red-500 animate-pulse').addClass('bg-purple-600');
                statusText.text('Recording Saved!').removeClass('animate-pulse');
            };

            // Start logic
            audioChunks = [];
            mediaRecorder.start();
            isRecording = true;
            
            // UI Update
            $(this).removeClass('bg-purple-600').addClass('bg-red-500 animate-pulse');
            statusText.text('Recording... Click to Stop').removeClass('hidden').addClass('animate-pulse');
            audioPreview.addClass('hidden');

            // Auto-stop after 20 seconds
            setTimeout(() => {
                if (mediaRecorder.state === 'recording') mediaRecorder.stop();
                isRecording = false;
            }, 20000);

        } catch (err) {
            alert('Microphone access denied or unavailable.');
            console.error(err);
        }
    } else {
        // 2. STOP RECORDING (Manual Stop)
        if (mediaRecorder.state === 'recording') mediaRecorder.stop();
        isRecording = false;
    }
});

// ==========================================
// FORM SUBMISSION (With Audio File attached)
// ==========================================
$('#addSongForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).text('Uploading...');

    let formData = new FormData(this);
    
    // Attach the recorded audio blob to the form submission
    if (audioBlob) {
        formData.append('audio_data', audioBlob, 'key_record.webm');
    }

    $.ajax({
        url: API_URL,
        type: 'POST',
        data: formData,
        processData: false, // Required for FormData
        contentType: false, // Required for FormData
        success: function(res) {
            btn.prop('disabled', false).text('Save to Setlist');
            if (res.status === 'success') {
                closeModal('addSongModal');
                showToast(res.message, 'success');
                // loadDashboard(); or refresh setlist view
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function() {
            btn.prop('disabled', false).text('Save to Setlist');
            showToast('Upload failed', 'error');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>