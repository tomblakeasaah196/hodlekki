<?php
// /modules/embrace/index.php
require_once '../../includes/header.php'; 
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<link rel="stylesheet" href="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/styles/minimal.css">
<script src="https://unpkg.com/@geoapify/geocoder-autocomplete@1.5.0/dist/index.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<style>
    /* Custom Geoapify styling to match your rounded-xl Tailwind forms */
    .geoapify-autocomplete-input {
        width: 100%;
        padding: 0.75rem 1rem !important; /* matches px-4 py-3 */
        background-color: transparent !important;
        border: none !important;
        color: #111827 !important; /* text-gray-900 */
        font-size: 0.875rem !important; /* text-sm */
        outline: none !important;
    }
    .geoapify-autocomplete-items {
        border-radius: 0.75rem;
        overflow: hidden;
        margin-top: 4px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        position: absolute;
        z-index: 9999;
    }
</style>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    <script>
        // Store current user ID to handle worker-specific views
        const CURRENT_USER_ID = <?php echo $_SESSION['user_id'] ?? 0; ?>;
    </script>

    <div class="flex gap-4 mb-2 overflow-x-auto custom-scrollbar pb-2">
        <button id="tab_all" onclick="filterPipeline('all')" class="px-6 py-2 rounded-xl text-sm font-bold bg-gray-900 text-white shadow-md transition-all shrink-0">All First Timers</button>
        <button id="tab_mine" onclick="filterPipeline('mine')" class="px-6 py-2 rounded-xl text-sm font-bold bg-white text-gray-500 hover:text-gray-900 border border-gray-200 shadow-sm transition-all flex items-center gap-2 shrink-0">
            My Assignments <span id="myTasksCount" class="bg-hodRed text-white text-[10px] px-2 py-0.5 rounded-full hidden">0</span>
        </button>
        <button id="tab_insights" onclick="filterPipeline('insights')" class="px-6 py-2 rounded-xl text-sm font-bold bg-white text-blue-600 hover:text-blue-800 border border-blue-200 hover:bg-blue-50 shadow-sm transition-all flex items-center gap-2 shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
            Growth Insights
        </button>
        <button id="tab_archive" onclick="filterPipeline('archive')" class="px-6 py-2 rounded-xl text-sm font-bold bg-white text-gray-500 hover:text-gray-900 border border-gray-200 shadow-sm transition-all flex items-center gap-2 shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
            Archive
        </button>
    </div>
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-red-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodRed rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Embrace Center</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Follow-up, spiritual growth, and first-timer integration.</p>
            </div>
        </div>
        
        <div class="relative z-10 flex items-center gap-3 shrink-0 flex-wrap">
            <button onclick="openModal('exportReportModal')" class="bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 px-5 py-3 rounded-xl font-bold shadow-sm transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Export List
            </button>
            <button onclick="prepCreateModal()" class="bg-hodRed hover:bg-red-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg shadow-red-900/20 transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New First Timer
            </button>
        </div>
    </div>

    <div id="pipelineContainer" class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative animate-fade-in-up">
        <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
            <h3 class="text-lg font-bold text-gray-800 tracking-tight">First-Timers Pipeline</h3>
            <div class="relative w-full sm:w-72">
                <input type="text" id="searchInput" placeholder="Search names or phone..." 
                    class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodRed focus:border-transparent outline-none transition-all bg-white shadow-sm">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Profile Info</th>
                        <th class="px-6 py-4">Date Joined</th>
                        <th class="px-6 py-4">Spiritual Markers</th>
                        <th class="px-6 py-4">Follow-up Status</th>
                        <th class="px-6 py-4">Contact</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="visitorsTableBody" class="divide-y divide-gray-50">
                    <tr>
                        <td colspan="6" class="px-6 py-20 text-center">
                            <svg class="animate-spin h-8 w-8 text-hodRed mx-auto mb-4" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <p class="text-gray-500 font-medium animate-pulse">Syncing timer pipeline...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="insightsContainer" class="hidden space-y-6 animate-fade-in-up">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 flex items-center gap-4">
                <div class="w-12 h-12 bg-gray-900 rounded-2xl flex items-center justify-center text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
                <div><p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Total First Timers</p><h3 id="metric_total" class="text-2xl font-bold text-gray-900">0</h3></div>
            </div>
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 flex items-center gap-4">
                <div class="w-12 h-12 bg-green-500 rounded-2xl flex items-center justify-center text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                <div><p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Followed Up</p><h3 id="metric_followed" class="text-2xl font-bold text-gray-900">0%</h3></div>
            </div>
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-500 rounded-2xl flex items-center justify-center text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg></div>
                <div><p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Ready to Join</p><h3 id="metric_join" class="text-2xl font-bold text-gray-900">0</h3></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                <h4 class="text-sm font-bold text-gray-800 mb-4 tracking-tight">Growth Trend (Over Time)</h4>
                <div id="chartTrend" class="w-full h-72"></div>
            </div>
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                <h4 class="text-sm font-bold text-gray-800 mb-4 tracking-tight">Acquisition Channels</h4>
                <div id="chartSource" class="w-full h-72 flex justify-center"></div>
            </div>
        </div>
    </div>
    
    <div id="archiveContainer" class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative hidden animate-fade-in-up">
        <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
            <h3 class="text-lg font-bold text-gray-800 tracking-tight">Congregation Archives</h3>
        </div>
        <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Date Integrated</th>
                        <th class="px-6 py-4">First Timer</th>
                        <th class="px-6 py-4">Assigned Worker</th>
                        <th class="px-6 py-4 w-1/2">Follow-up Notes History</th>
                    </tr>
                </thead>
                <tbody id="archiveTableBody" class="divide-y divide-gray-50">
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="addVisitorModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[80vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300 border border-white/20">
        
        <div class="flex-shrink-0 p-6 border-b border-gray-100 flex justify-between items-center bg-white z-10">
            <div>
                <h3 id="visitorModalTitle" class="text-xl md:text-2xl font-display font-bold text-gray-900 tracking-tight">New First Timer Record</h3>
                <p id="visitorModalSubtitle" class="text-xs text-gray-500 mt-1 font-medium italic">Welcome them home with excellence</p>
            </div>
            <button onclick="closeModal('addVisitorModal')" class="text-gray-400 hover:text-red-500 p-2 rounded-full transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 md:p-8 custom-scrollbar bg-gray-50/50">
            <form id="addVisitorForm" class="space-y-8" enctype="multipart/form-data">
                <input type="hidden" name="action" id="visitor_form_action" value="add_visitor">
                <input type="hidden" name="visitor_id" id="edit_visitor_id" value="">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="md:col-span-1 flex flex-col items-center gap-4 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                        <div id="picPreview" class="w-24 h-24 rounded-2xl bg-gray-50 border-2 border-dashed border-gray-200 flex items-center justify-center overflow-hidden">
                            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <label class="cursor-pointer text-[10px] font-bold text-hodRed uppercase tracking-wider hover:text-red-700 transition-colors">
                            Upload Photo
                            <input type="file" name="profile_pic" accept="image/*" class="hidden" onchange="previewProfileImage(this)">
                        </label>
                    </div>

                    <div class="md:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">First Name *</label>
                            <input type="text" name="first_name" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodRed focus:ring-4 focus:ring-red-50 outline-none text-sm font-bold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Last Name *</label>
                            <input type="text" name="last_name" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodRed focus:ring-4 focus:ring-red-50 outline-none text-sm font-bold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Phone *</label>
                            <input type="tel" name="phone" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodRed outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Email</label>
                            <input type="email" name="email" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodRed outline-none text-sm">
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Gender</label>
                        <select name="gender" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white outline-none text-sm cursor-pointer">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Marital Status</label>
                        <select name="marital_status" id="maritalSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white outline-none text-sm cursor-pointer">
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Separated">Separated</option>
                            <option value="Divorced">Divorced</option>
                        </select>
                    </div>
                    <div id="annivDiv" class="hidden">
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Wedding Anniversary</label>
                        <input type="date" name="wedding_anniversary" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none text-sm">
                    </div>
                    
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">How did they hear about us? *</label>
                        <select name="invitation_source" id="invitation_source" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white outline-none text-sm cursor-pointer">
                            <option value="Self_Discovery">Self Discovery (Walk-in / Map)</option>
                            <option value="Church_Member">Invited by a Church Member</option>
                            <option value="Social_Media">Social Media (Instagram/Facebook)</option>
                            <option value="Flyer_Billboard">Flyer / Billboard</option>
                            <option value="Other">Other (Special Event / HQ)</option>
                        </select>
                    </div>
                    <div id="invitedByDiv" class="hidden">
                        <label id="invitedByLabel" class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Specify Details</label>
                        <input type="text" name="invited_by" id="invited_by_input" placeholder="Enter details..." class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none text-sm">
                    </div>

                    <div class="md:col-span-3 relative z-50">
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Physical Address (Type to search)</label>
                        <div id="autocomplete-container" class="w-full bg-white border border-gray-200 rounded-xl focus-within:border-hodRed transition-all shadow-sm"></div>
                        <input type="hidden" name="physical_address" id="embraceAddress">
                        <input type="hidden" name="latitude" id="embraceLat">
                        <input type="hidden" name="longitude" id="embraceLng">
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-6">
                    <h4 class="text-xs font-bold text-hodRed uppercase tracking-widest">Spiritual & Welfare Needs</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <label class="flex items-center gap-3 p-4 border border-gray-100 rounded-2xl hover:bg-green-50/50 transition cursor-pointer group">
                            <input type="checkbox" name="is_born_again" class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <span class="text-sm font-bold text-gray-700 group-hover:text-green-700">Born Again?</span>
                        </label>
                        <label class="flex items-center gap-3 p-4 border border-gray-100 rounded-2xl hover:bg-blue-50/50 transition cursor-pointer group">
                            <input type="checkbox" name="wants_to_join" class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-bold text-gray-700 group-hover:text-blue-700">Wants to Join?</span>
                        </label>
                        <div class="p-2">
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Visitation Preference</label>
                            <select name="visitation_preference" id="pref_visitation" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white outline-none text-sm cursor-pointer">
                                <option value="None">Does not want visitation</option>
                                <option value="In-Person">Physical / In-Person</option>
                                <option value="Virtual">Virtual (Zoom/Video Call)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-2">Specific Prayer Requests</label>
                        <textarea name="prayer_requests" rows="3" placeholder="What are we standing with them for?" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodRed outline-none text-sm resize-none"></textarea>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex-shrink-0 p-6 border-t border-gray-100 bg-white flex justify-end gap-3 z-10">
            <button type="button" onclick="closeModal('addVisitorModal')" class="px-6 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-100 transition-colors">Cancel</button>
            <button type="submit" form="addVisitorForm" id="submitBtn" class="bg-hodRed hover:bg-red-700 text-white px-10 py-3.5 rounded-xl font-bold shadow-lg shadow-red-900/20 transition-all flex items-center justify-center gap-2">
                <span id="visitorSubmitText">Save Record</span>
            </button>
        </div>
    </div>
</div>

<div id="manageNotesModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b bg-gray-50 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Follow-up Notes: <span id="notes_visitor_name" class="text-hodRed"></span></h3>
            <button onclick="closeModal('manageNotesModal')"><svg class="w-6 h-6 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 space-y-4 custom-scrollbar bg-gray-50/50" id="existingNotesContainer">
            </div>

        <div class="p-6 border-t border-gray-100 bg-white shrink-0">
            <form id="saveNoteForm" class="space-y-4">
                <input type="hidden" name="action" value="save_note">
                <input type="hidden" name="followup_id" id="note_followup_id">
                <input type="hidden" name="note_id" id="edit_note_id" value="">

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1" id="noteInputLabel">Add New Note</label>
                    <textarea name="note_text" id="note_text_input" required placeholder="Type forensic details here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl min-h-[100px] text-sm outline-none focus:border-hodRed resize-none whitespace-pre-wrap"></textarea>
                </div>

                <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-3">Note Visibility:</label>
    <div class="flex flex-wrap gap-4">
        <label class="flex items-center gap-2 cursor-pointer group">
            <input type="checkbox" name="vis_all" id="cb_vis_all" value="1" checked class="w-4 h-4 text-hodRed focus:ring-hodRed rounded">
            <span class="text-xs font-bold text-gray-700">Public (Everyone can see)</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer group">
            <input type="checkbox" name="vis_pastor" id="cb_vis_pastor" value="1" class="w-4 h-4 text-hodRed focus:ring-hodRed rounded">
            <span class="text-xs font-bold text-gray-700">Pastors Only</span>
        </label>
        
        <input type="hidden" name="vis_director" value="">
        <input type="hidden" name="vis_worker" value="">
    </div>
</div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="resetNoteForm()" class="px-5 py-3 bg-gray-100 text-gray-600 hover:bg-gray-200 font-bold rounded-xl transition text-sm hidden" id="cancelEditNoteBtn">Cancel Edit</button>
                    <button type="submit" class="flex-1 bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all" id="saveNoteBtn">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-hodRed" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<div id="assignFollowupModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 overflow-hidden">
        <div class="px-6 py-5 border-b bg-gray-50 flex justify-between items-center">
            <h3 class="font-bold text-gray-900">Assign Embrace Worker</h3>
            <button onclick="closeModal('assignFollowupModal')"><svg class="w-5 h-5 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignFollowupForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="assign_followup">
            <input type="hidden" name="visitor_id" id="assign_visitor_id">
            <p class="text-sm font-medium text-gray-500">First Timer: <span id="assign_visitor_name" class="font-bold text-hodRed"></span></p>
            
            <select name="worker_id" id="embraceWorkerSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-hodRed">
                <option value="">Loading Embrace Team...</option>
            </select>
            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Confirm Assignment</button>
        </form>
    </div>
</div>

<div id="logFollowupModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b flex justify-between items-center bg-yellow-50 shrink-0">
            <h3 class="font-bold text-yellow-900">Log Call Result</h3>
            <button onclick="closeModal('logFollowupModal')"><svg class="w-6 h-6 text-yellow-400 hover:text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="logFollowupForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="log_followup">
            <input type="hidden" name="followup_id" id="log_followup_id">
            <input type="hidden" name="visitor_id" id="log_visitor_id">
            
            <p class="text-sm font-medium text-gray-500">Speaking with: <span id="log_visitor_name" class="font-bold text-hodRed"></span></p>
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Call Outcome *</label>
                <select name="status" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-hodRed cursor-pointer">
                    <option value="Completed">Successfully Reached & Discussed</option>
                    <option value="Unreachable">Unreachable / No Response</option>
                    <option value="In_Progress">Spoke Briefly / Needs 2nd Call</option>
                </select>
            </div>
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Conversation Notes *</label>
                <textarea name="followup_notes" required placeholder="What did they enjoy? Any prayer requests? Highlight key points..." class="w-full px-4 py-3 border border-gray-200 rounded-xl h-24 resize-none text-sm outline-none focus:border-hodRed"></textarea>
            </div>

            <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 space-y-3">
                <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-widest border-b border-gray-200 pb-2">Spiritual Updates Confirmed on Call</h4>
                <label class="flex items-center gap-3 cursor-pointer group"><input type="checkbox" name="is_born_again" id="log_ba" class="w-4 h-4 text-hodRed focus:ring-hodRed rounded"><span class="text-xs font-bold text-gray-700 group-hover:text-hodRed transition">Received Christ / Born Again</span></label>
                <label class="flex items-center gap-3 cursor-pointer group"><input type="checkbox" name="wants_to_join" id="log_join" class="w-4 h-4 text-hodRed focus:ring-hodRed rounded"><span class="text-xs font-bold text-gray-700 group-hover:text-hodRed transition">Confirmed wanting to join Workforce/Tribe</span></label>
                
                <div class="pt-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Visitation Preference</label>
                    <select name="visitation_preference" id="log_visit" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white focus:border-hodRed outline-none text-sm cursor-pointer">
                        <option value="None">Does not want visitation</option>
                        <option value="In-Person">Physical / In-Person</option>
                        <option value="Virtual">Virtual (Zoom/Video Call)</option>
                    </select>
                </div>
            </div>

            <label class="flex items-center gap-3 bg-red-50 border border-red-100 p-3 rounded-xl cursor-pointer group">
                <input type="checkbox" name="needs_pastor" class="w-4 h-4 text-red-600 focus:ring-red-500 rounded">
                <span class="text-xs font-bold text-red-800">Flag for Pastoral Attention</span>
            </label>
            
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Result</button>
        </form>
    </div>
</div>

<div id="viewReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-6 py-5 border-b flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="font-bold text-gray-900">Follow-up Report</h3>
            <button onclick="closeModal('viewReportModal')"><svg class="w-6 h-6 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-sm">
                <p class="text-gray-500 font-medium mb-1">Assigned Worker:</p>
                <p id="view_report_worker" class="font-bold text-blue-900 text-base"></p>
            </div>
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Final Outcome</label>
                <p id="view_report_status" class="font-bold text-gray-800 bg-gray-50 px-4 py-3 rounded-xl border border-gray-100"></p>
            </div>
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Worker's Notes</label>
                <div id="view_report_notes" class="w-full px-4 py-3 border border-gray-200 rounded-xl min-h-[120px] bg-white text-sm text-gray-700 whitespace-pre-wrap"></div>
            </div>
            
            <button onclick="closeModal('viewReportModal')" class="w-full bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Close Report</button>
        </div>
    </div>
</div>

<div id="promoteMemberModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-6 py-5 border-b flex justify-between items-center bg-green-50 shrink-0">
            <h3 class="font-bold text-green-900 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Push to Congregation
            </h3>
            <button onclick="closeModal('promoteMemberModal')"><svg class="w-6 h-6 text-green-400 hover:text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="promoteMemberForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="promote_member">
            <input type="hidden" name="visitor_id" id="promote_visitor_id">
            
            <p class="text-sm text-gray-500">Integrating <span id="promote_visitor_name" class="font-bold text-gray-900"></span> into the master database. Please assign their starting statuses:</p>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Spiritual Status *</label>
                    <select name="spiritual_status" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-green-500 cursor-pointer">
                        <option value="Member">Member</option>
                        <option value="Worker">Worker</option>
                        <option value="Pastor">Pastor</option>
                        <option value="Non_Member">Regular Attendee (Non-Member)</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Attendance Status *</label>
                    <select name="attendance_status" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-green-500 cursor-pointer">
                        <option value="New">New (Still Integrating)</option>
                        <option value="Active">Active / Consistent</option>
                        <option value="Inconsistent">Inconsistent</option>
                    </select>
                </div>
            </div>
            
            <div class="pt-2 flex gap-3">
                <button type="button" onclick="closeModal('promoteMemberModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-600 hover:bg-gray-200 font-bold rounded-xl transition">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-3 bg-green-600 hover:bg-green-700 text-white font-bold rounded-xl shadow-lg transition">Confirm Push</button>
            </div>
        </form>
    </div>
</div>

<div id="exportReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col">
        <div class="px-6 py-5 border-b bg-gray-50 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900 flex items-center gap-2">
                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Export Pipeline Report
            </h3>
            <button onclick="closeModal('exportReportModal')"><svg class="w-6 h-6 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="p-6 space-y-6">
            <p class="text-sm font-medium text-gray-500 leading-relaxed">Select a date range below to pull the list of first-timers. You can export a quick JPEG summary or a detailed Excel (.xlsx) spreadsheet.</p>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Start Date *</label>
                    <input type="date" id="exportStartDate" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-hodRed focus:ring-4 focus:ring-red-50 transition-all" value="<?php echo date('Y-m-d', strtotime('-1 week')); ?>">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">End Date *</label>
                    <input type="date" id="exportEndDate" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:border-hodRed focus:ring-4 focus:ring-red-50 transition-all" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            
            <div class="pt-2 flex flex-col sm:flex-row gap-3">
                <button onclick="generateReport()" class="flex-1 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 py-3.5 rounded-xl font-bold shadow-sm transition-all flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    JPEG Summary
                </button>
                
                <button onclick="exportExcel()" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-green-900/20 transition-all flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Excel (.xlsx) List
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const API_URL = '/api/embrace_api.php';
    let globalVisitorsData = []; 
    let embraceAddressWidget; 
    let trendChart, sourceChart; // Added for Insights module

    // ==========================================
    // UI CORE: MODALS & BUTTON LOCKING 
    // ==========================================
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
        document.body.appendChild(m); 
        const inner = m.children[0];
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; 
        setTimeout(() => { 
            m.classList.remove('opacity-0'); 
            inner.classList.remove('scale-95'); 
        }, 10);
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if(!m) return;
        const inner = m.children[0];
        m.classList.add('opacity-0');
        inner.classList.add('scale-95');
        setTimeout(() => { 
            m.classList.add('hidden'); 
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
            const f = m.querySelector('form');
            if(f) f.reset();
            $('#picPreview').html('<svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>');
        }, 300);
    }

    function previewProfileImage(input) {
        if (input.files && input.files[0]) {
            let reader = new FileReader();
            reader.onload = e => $('#picPreview').html(`<img src="${e.target.result}" class="w-full h-full object-cover">`);
            reader.readAsDataURL(input.files[0]);
        }
    }

    // ==========================================
    // CREATE / EDIT MODAL PREPARATION
    // ==========================================
    function prepCreateModal() {
        document.getElementById('addVisitorForm').reset();
        $('#picPreview').html('<svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>');
        $('#annivDiv').addClass('hidden');
        $('#invitedByDiv').addClass('hidden');
        if(embraceAddressWidget) embraceAddressWidget.setValue('');

        $('#visitor_form_action').val('add_visitor');
        $('#edit_visitor_id').val('');
        $('#visitorModalTitle').text('New First Timer Record');
        $('#visitorModalSubtitle').text('Welcome them home with excellence');
        $('#visitorSubmitText').text('Save Record');

        openModal('addVisitorModal');
    }

    function prepEditModal(id) {
        const v = globalVisitorsData.find(visitor => visitor.id == id);
        if(!v) return;

        $('#visitor_form_action').val('update_visitor');
        $('#edit_visitor_id').val(v.id);
        $('#visitorModalTitle').text('Edit Record: ' + v.first_name);
        $('#visitorSubmitText').text('Update Record');

        const f = $('#addVisitorForm');
        f.find('[name="first_name"]').val(v.first_name);
        f.find('[name="last_name"]').val(v.last_name);
        f.find('[name="phone"]').val(v.phone);
        f.find('[name="email"]').val(v.email);
        f.find('[name="invited_by"]').val(v.invited_by);
        
        $('#embraceAddress').val(v.physical_address);
        if (v.physical_address && embraceAddressWidget) embraceAddressWidget.setValue(v.physical_address);
        
        f.find('[name="prayer_requests"]').val(v.prayer_requests);
        f.find('[name="gender"]').val(v.gender || 'Male');
        f.find('[name="marital_status"]').val(v.marital_status || 'Single').trigger('change');
        if(v.wedding_anniversary) f.find('[name="wedding_anniversary"]').val(v.wedding_anniversary);

        f.find('[name="is_born_again"]').prop('checked', v.is_born_again == 1);
        f.find('[name="wants_to_join"]').prop('checked', v.wants_to_join == 1);
        f.find('[name="visitation_preference"]').val(v.visitation_preference || 'None');
        
        // Ensure Source dropdown populates and triggers the UI display correctly
        f.find('[name="invitation_source"]').val(v.invitation_source || 'Self_Discovery').trigger('change');

        if(v.picture_path) {
            $('#picPreview').html(`<img src="${v.picture_path}" class="w-full h-full object-cover">`);
        } else {
            $('#picPreview').html('<svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>');
        }

        openModal('addVisitorModal');
    }

    // ==========================================
    // EXPORT REPORT LOGIC
    // ==========================================
    function exportExcel() {
        const start = document.getElementById('exportStartDate').value;
        const end = document.getElementById('exportEndDate').value;
        
        if (!start || !end) return Toastify({text: "Both Start and End dates are required", style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}}).showToast();
        if (start > end) return Toastify({text: "Start Date cannot be greater than End Date", style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}}).showToast();

        window.location.href = `${API_URL}?action=export_excel&start_date=${start}&end_date=${end}`;
        
        Toastify({
            text: "Excel file generated and downloading...", 
            duration: 3000,
            style: {background: "#10B981", borderRadius: "10px", fontWeight: "bold"}
        }).showToast();
        
        closeModal('exportReportModal');
    }

    function generateReport() {
        const start = document.getElementById('exportStartDate').value;
        const end = document.getElementById('exportEndDate').value;
        
        if (!start || !end) return Toastify({text: "Both Start and End dates are required", style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}}).showToast();
        if (start > end) return Toastify({text: "Start Date cannot be greater than End Date", style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}}).showToast();

        const filtered = globalVisitorsData.filter(v => {
            if(!v.created_at) return false;
            const vDate = v.created_at.split(' ')[0]; 
            return vDate >= start && vDate <= end;
        });

        if (filtered.length === 0) {
            return Toastify({
                text: "No first timers found in the selected date range.", 
                style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}
            }).showToast();
        }

        lockScreenAction();

        const container = document.createElement('div');
        container.style.position = 'fixed';
        container.style.left = '-9999px';
        container.style.top = '0';
        container.style.width = '800px'; 
        container.style.backgroundColor = '#ffffff';
        container.style.padding = '60px';
        container.style.fontFamily = 'system-ui, -apple-system, sans-serif';
        container.style.color = '#1f2937';
        container.style.zIndex = '-1000';

        let listHtml = '';
        filtered.forEach((v, idx) => {
            let phone = v.phone || 'No phone recorded';
            let address = v.physical_address || 'Address not provided';
            let formattedDateAdded = new Date(v.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            
            listHtml += `
                <div style="padding: 16px 0; border-bottom: 1px solid #f3f4f6; line-height: 1.5;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <span style="font-weight: 700; font-size: 18px; color: #111827;">${idx + 1}. ${v.first_name} ${v.last_name}</span>
                            <span style="color: #4b5563; font-size: 16px; margin-left: 8px;">(${phone})</span>
                        </div>
                        <span style="font-size: 13px; color: #9ca3af; font-weight: 600;">Joined: ${formattedDateAdded}</span>
                    </div>
                    <div style="color: #6b7280; font-size: 14px; margin-top: 4px;">Address: ${address}</div>
                </div>
            `;
        });

        const niceStart = new Date(start).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const niceEnd = new Date(end).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const rangeDisplay = start === end ? niceStart : `${niceStart} - ${niceEnd}`;

        container.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #ef4444; padding-bottom: 24px; margin-bottom: 32px;">
                <div>
                    <img src="../../assets/images/hod_logo.svg" style="height: 60px; width: auto;" onerror="this.style.display='none'">
                </div>
                <div style="text-align: right;">
                    <h1 style="margin: 0; font-size: 28px; font-weight: 800; color: #111827; letter-spacing: -0.5px;">FIRST TIMERS PIPELINE</h1>
                    <p style="margin: 8px 0 0 0; font-size: 16px; color: #6b7280; font-weight: 500;">${rangeDisplay}</p>
                </div>
            </div>
            <div>
                ${listHtml}
            </div>
            <div style="margin-top: 60px; text-align: center; font-size: 12px; color: #9ca3af; font-style: italic;">
                Generated securely by Embrace Center on ${new Date().toLocaleString()}
            </div>
        `;

        document.body.appendChild(container);

        html2canvas(container, { scale: 2, useCORS: true }).then(canvas => {
            const link = document.createElement('a');
            link.download = `First_Timers_Summary_${start}_to_${end}.jpg`;
            link.href = canvas.toDataURL('image/jpeg', 0.9);
            link.click();
            
            document.body.removeChild(container);
            unlockScreenAction();
            closeModal('exportReportModal');
            
            Toastify({
                text: "JPEG Summary generated successfully", 
                style: {background: "#10B981", borderRadius: "10px", fontWeight: "bold"}
            }).showToast();
        }).catch(err => {
            document.body.removeChild(container);
            unlockScreenAction();
            Toastify({
                text: "Error generating JPEG. Try again.", 
                style: {background: "#EF4444", borderRadius: "10px", fontWeight: "bold"}
            }).showToast();
        });
    }

    // ==========================================
    // DATA HANDLING & PIPELINE FILTERS
    // ==========================================
    let currentFilter = 'all';

    function filterPipeline(type) {
        currentFilter = type;
        
        // Reset all tabs
        $('#tab_all, #tab_mine').removeClass('bg-gray-900 text-white').addClass('bg-white text-gray-500 border border-gray-200');
        $('#tab_insights').removeClass('bg-blue-50 text-blue-800 border-blue-200').addClass('bg-white text-blue-600 border border-blue-200');

        if(type === 'insights') {
            $('#tab_insights').removeClass('bg-white text-blue-600 border border-blue-200').addClass('bg-blue-50 text-blue-800 border-blue-200');
            $('#pipelineContainer').addClass('hidden');
            $('#insightsContainer').removeClass('hidden');
            renderInsights(); 
        } else {
            if(type === 'all') $('#tab_all').removeClass('bg-white text-gray-500 border border-gray-200').addClass('bg-gray-900 text-white');
            if(type === 'mine') $('#tab_mine').removeClass('bg-white text-gray-500 border border-gray-200').addClass('bg-gray-900 text-white');
            
            $('#insightsContainer').addClass('hidden');
            $('#pipelineContainer').removeClass('hidden');
            renderPipelineTable();
        }
    }

    function renderInsights() {
        const total = globalVisitorsData.length;
        let followedUp = 0;
        let wantsToJoin = 0;
        
        const sourceCounts = {
            'Church_Member': 0, 'Social_Media': 0, 'Flyer_Billboard': 0, 'Self_Discovery': 0, 'Other': 0
        };
        
        const trendDataObj = {};

        globalVisitorsData.forEach(v => {
            if(v.followup_status === 'Completed') followedUp++;
            if(v.wants_to_join == 1) wantsToJoin++;
            
            const src = v.invitation_source || 'Self_Discovery';
            if(sourceCounts[src] !== undefined) sourceCounts[src]++;
            
            // Safety Check added here to prevent JS crashing on old records
            if(v.created_at) {
                const dateStr = v.created_at.split(' ')[0];
                trendDataObj[dateStr] = (trendDataObj[dateStr] || 0) + 1;
            }
        });

        const followUpRate = total > 0 ? Math.round((followedUp / total) * 100) : 0;
        $('#metric_total').text(total);
        $('#metric_followed').text(followUpRate + '%');
        $('#metric_join').text(wantsToJoin);

        const sourceLabels = ['Members', 'Social Media', 'Flyers', 'Walk-in', 'Other/Event'];
        const sourceSeries = [sourceCounts['Church_Member'], sourceCounts['Social_Media'], sourceCounts['Flyer_Billboard'], sourceCounts['Self_Discovery'], sourceCounts['Other']];
        
        const sourceOptions = {
            series: sourceSeries,
            labels: sourceLabels,
            chart: { type: 'donut', height: 280, fontFamily: 'inherit' },
            colors: ['#3B82F6', '#EC4899', '#F59E0B', '#10B981', '#6B7280'],
            plotOptions: { pie: { donut: { size: '65%' } } },
            dataLabels: { enabled: false },
            legend: { position: 'bottom' }
        };

        if(sourceChart) sourceChart.destroy();
        sourceChart = new ApexCharts(document.querySelector("#chartSource"), sourceOptions);
        sourceChart.render();

        const sortedDates = Object.keys(trendDataObj).sort();
        const trendSeries = sortedDates.map(date => trendDataObj[date]);

        const trendOptions = {
            series: [{ name: "First Timers", data: trendSeries }],
            chart: { type: 'area', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
            colors: ['#EF4444'], // hodRed
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 3 },
            xaxis: { categories: sortedDates, type: 'datetime' },
            yaxis: { labels: { formatter: (val) => { return Math.round(val) } } }
        };

        if(trendChart) trendChart.destroy();
        trendChart = new ApexCharts(document.querySelector("#chartTrend"), trendOptions);
        trendChart.render();
    }

    function renderPipelineTable() {
        let html = '';
        let myCount = 0;
        
        const filteredData = globalVisitorsData.filter(v => {
            if (v.assigned_worker_id == CURRENT_USER_ID && (v.followup_status === 'Pending' || v.followup_status === 'In_Progress')) {
                myCount++;
            }
            if(currentFilter === 'mine') return v.assigned_worker_id == CURRENT_USER_ID;
            return true;
        });

        if(myCount > 0) {
            $('#myTasksCount').text(myCount).removeClass('hidden');
        } else {
            $('#myTasksCount').addClass('hidden');
        }

        if(filteredData.length === 0) {
            html = `<tr><td colspan="6" class="px-6 py-16 text-center text-gray-400 italic">No first timers found for this view.</td></tr>`;
        } else {
            filteredData.forEach(v => {
                const avatar = v.picture_path ? `<img src="${v.picture_path}" class="w-10 h-10 rounded-xl object-cover shadow-sm">` : `<div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center font-bold text-gray-400 border border-gray-200">${v.first_name[0]}</div>`;
                
                let markers = '';
                if(v.is_born_again == 1) markers += `<span class="bg-green-50 text-green-700 px-2 py-0.5 rounded-md border border-green-100 text-[9px] font-bold">BA</span> `;
                if(v.wants_to_join == 1) markers += `<span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md border border-blue-100 text-[9px] font-bold">JOIN</span> `;
                if(v.visitation_preference === 'In-Person' || v.visitation_preference === 'Virtual') markers += `<span class="bg-red-50 text-red-700 px-2 py-0.5 rounded-md border border-red-100 text-[9px] font-bold">${v.visitation_preference.toUpperCase()}</span> `;

                // Assignment Tracker
                let assignedBadge = `<div class="mt-1 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-400 animate-pulse"></span><span class="text-[10px] text-gray-400 font-bold tracking-wide uppercase">Unassigned</span></div>`;
                if(v.worker_fname) {
                    assignedBadge = `<div class="mt-1 flex items-center gap-1.5"><svg class="w-3 h-3 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg><span class="text-[10px] text-gray-600 font-bold uppercase tracking-wide">Assigned: ${v.worker_fname} ${v.worker_lname}</span></div>`;
                }

                // Smart Action Buttons
                let actionBtn = '';
                if (!v.followup_id || v.followup_status === 'Unassigned') {
                    actionBtn = `<button onclick="prepAssignModal(${v.id}, '${v.first_name} ${v.last_name}')" class="w-full bg-white text-gray-700 border border-gray-200 px-3 py-2 rounded-lg text-xs font-bold hover:border-hodRed hover:text-hodRed transition shadow-sm">Assign Follow-up</button>`;
                } else if (v.followup_status === 'Completed') {
                    actionBtn = `<button onclick="prepPromoteModal(${v.id}, '${v.first_name} ${v.last_name}')" class="w-full bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg text-xs font-bold hover:bg-green-100 transition shadow-sm mb-2">Push to Congregation</button>`;
                    actionBtn += `<button onclick="viewReport('${v.worker_fname} ${v.worker_lname}', '${v.followup_notes ? v.followup_notes.replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '') : 'No notes'}')" class="w-full bg-gray-50 text-gray-600 border border-gray-200 px-3 py-2 rounded-lg text-xs font-bold hover:bg-gray-100 transition shadow-sm">Read Report</button>`;
                } else {
                    if (v.assigned_worker_id == CURRENT_USER_ID) {
                        actionBtn = `<button onclick="prepLogModal(${v.followup_id}, ${v.id}, '${v.first_name} ${v.last_name}', ${v.is_born_again}, ${v.wants_to_join}, '${v.visitation_preference}')" class="w-full bg-yellow-50 text-yellow-700 border border-yellow-200 px-3 py-2 rounded-lg text-xs font-bold hover:bg-yellow-100 transition shadow-sm">Log Result</button>`;
                    } else {
                        actionBtn = `<div class="w-full bg-gray-50 text-gray-400 border border-gray-100 px-3 py-2 rounded-lg text-[10px] font-bold text-center tracking-wide uppercase cursor-not-allowed">Awaiting ${v.worker_fname}</div>`;
                    }
                }

                let cleanPhone = v.phone ? v.phone.replace(/\D/g, '') : '';
                if(cleanPhone.startsWith('0')) cleanPhone = '234' + cleanPhone.substring(1);
                
                const editLink = `<button onclick="prepEditModal(${v.id})" class="text-gray-400 hover:text-blue-600 p-1.5 rounded-lg transition" title="Edit Record"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>`;
                const waLink = v.phone ? `<a href="https://wa.me/${cleanPhone}" target="_blank" class="text-[#25D366] hover:bg-[#25D366]/10 p-1.5 rounded-lg transition" title="WhatsApp"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 0C5.385 0 .002 5.385.002 12.032c0 2.128.555 4.2 1.613 6.027L0 24l6.104-1.603c1.764.957 3.738 1.464 5.925 1.464 6.645 0 12.028-5.385 12.028-12.032C24.057 5.385 18.676 0 12.031 0zm0 21.84c-1.785 0-3.535-.48-5.064-1.385l-.364-.214-3.763.987.998-3.666-.236-.376C2.658 15.65 2.13 13.882 2.13 12.032 2.13 6.564 6.564 2.13 12.031 2.13c5.466 0 9.897 4.434 9.897 9.902 0 5.468-4.43 9.808-9.897 9.808zm5.426-7.404c-.297-.15-1.764-.87-2.037-.97-.27-.1-.47-.15-.668.15-.2.298-.77 1-.944 1.203-.175.204-.35.23-.648.08-.297-.15-1.258-.464-2.395-1.485-.886-.795-1.484-1.776-1.66-2.075-.174-.298-.018-.46.13-.61.134-.135.297-.348.446-.522.15-.175.2-.298.3-.497.1-.2.05-.376-.025-.522-.075-.15-.668-1.613-.916-2.208-.242-.58-.488-.503-.668-.513-.174-.01-.375-.01-.574-.01-.2 0-.524.075-.798.375-.274.3-.1047 1.17-.1047 2.855 0 1.685 1.07 3.315 1.22 3.515.15.2 2.4 3.664 5.816 5.14.814.35 1.45.56 1.946.717.818.26 1.56.223 2.146.135.654-.1 2.037-.833 2.324-1.637.288-.804.288-1.493.2-1.637-.088-.144-.336-.23-.634-.38z"/></svg></a>` : '';
                const callLink = v.phone ? `<a href="tel:${v.phone}" class="text-blue-500 hover:bg-blue-50 p-1.5 rounded-lg transition" title="Call"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg></a>` : '';

                let statusColor = 'text-gray-400';
                if(v.followup_status === 'Pending' || v.followup_status === 'In_Progress') statusColor = 'text-orange-500';
                if(v.followup_status === 'Completed') statusColor = 'text-green-500';
                
                const dateAdded = new Date(v.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                
                let notesHtml = `<button onclick='prepManageNotes(${JSON.stringify(v.secure_notes).replace(/'/g, "&#39;")}, ${v.followup_id}, "${v.first_name} ${v.last_name}")' class="text-xs font-bold text-blue-600 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-100 flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> Manage Notes (${v.secure_notes ? v.secure_notes.length : 0})</button>`;
                
                if (!v.followup_id || v.followup_status === 'Unassigned') {
                    notesHtml = `<span class="text-[10px] text-gray-400 italic">Assign to enable notes</span>`;
                }

                // Append the Notes column to your output string:
                html += `
                <tr class="hover:bg-gray-50/50 transition">
                    <td class="px-6 py-4 flex items-center gap-4">
                        ${avatar}
                        <div>
                            <p class="font-bold text-gray-900">${v.first_name} ${v.last_name}</p>
                            <p class="text-[10px] text-gray-400 uppercase tracking-wider">${v.spiritual_status ? v.spiritual_status.replace('_', ' ') : 'Member'}</p>
                            ${assignedBadge}
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap"><span class="text-xs font-bold text-gray-600 bg-gray-100 px-2.5 py-1 rounded-md border border-gray-200">${dateAdded}</span></td>
                    <td class="px-6 py-4">${markers || '<span class="text-[10px] text-gray-300">Pending Evaluation</span>'}</td>
                    <td class="px-6 py-4"><span class="text-[10px] uppercase tracking-wider font-bold ${statusColor}">${v.followup_status || 'Unassigned'}</span></td>
                    <td class="px-6 py-4">${notesHtml}</td> <td class="px-6 py-4"><p class="text-xs font-bold text-gray-700">${v.phone || '-'}</p></td>
                    <td class="px-6 py-4">
                        <div class="flex items-center justify-end gap-3">
                            <div class="flex border-r border-gray-200 pr-3 mr-1">${editLink}${callLink}${waLink}</div>
                            <div class="w-36">${actionBtn}</div>
                        </div>
                    </td>
                </tr>`;
            });
        }
        $('#visitorsTableBody').html(html);
    }
    
    function prepManageNotes(secureNotes, followupId, visitorName) {
        $('#note_followup_id').val(followupId);
        $('#notes_visitor_name').text(visitorName);
        resetNoteForm();
        
        let container = $('#existingNotesContainer');
        container.empty();

        if(!secureNotes || secureNotes.length === 0) {
            container.html(`<div class="text-center py-10 text-gray-400 text-sm font-medium italic">No notes recorded yet.</div>`);
        } else {
            secureNotes.forEach(n => {
                let visTags = JSON.parse(n.visible_to).map(t => `<span class="bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded text-[9px] uppercase">${t.replace('_', ' ')}</span>`).join(' ');
                
                let editBtn = '';
                if(n.author_id == CURRENT_USER_ID) {
                    editBtn = `<button onclick='editSpecificNote(${n.id}, ${JSON.stringify(n.note_text).replace(/'/g, "&#39;")}, ${n.visible_to})' class="text-[10px] text-blue-600 hover:underline font-bold mt-2 inline-block">Edit My Note</button>`;
                }

                container.append(`
                    <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                        <div class="flex justify-between items-start mb-2 border-b border-gray-50 pb-2">
                            <div>
                                <span class="font-bold text-gray-900 text-sm">${n.first_name} ${n.last_name}</span>
                                <span class="text-[10px] text-gray-400 ml-2">${new Date(n.created_at).toLocaleString()}</span>
                            </div>
                            <div class="flex gap-1">${visTags}</div>
                        </div>
                        <div class="text-sm text-gray-700 whitespace-pre-wrap">${n.note_text}</div>
                        ${editBtn}
                    </div>
                `);
            });
        }
        openModal('manageNotesModal');
    }

    function editSpecificNote(noteId, text, visibleToArr) {
        $('#edit_note_id').val(noteId);
        $('#note_text_input').val(text);
        $('#noteInputLabel').text('Edit Your Note');
        $('#saveNoteBtn').text('Update Note');
        $('#cancelEditNoteBtn').removeClass('hidden');

        $('#saveNoteForm input[type="checkbox"]').prop('checked', false);
        if(visibleToArr.includes('All')) $('#cb_vis_all').prop('checked', true);
        if(visibleToArr.includes('Pastors')) $('input[name="vis_pastor"]').prop('checked', true);
        if(visibleToArr.includes('Directors')) $('input[name="vis_director"]').prop('checked', true);
        if(visibleToArr.includes('Assigned_Worker')) $('input[name="vis_worker"]').prop('checked', true);
    }

    function resetNoteForm() {
        $('#edit_note_id').val('');
        $('#note_text_input').val('');
        $('#noteInputLabel').text('Add New Note');
        $('#saveNoteBtn').text('Save Note');
        $('#cancelEditNoteBtn').addClass('hidden');
        $('#saveNoteForm input[type="checkbox"]').prop('checked', false);
        $('#cb_vis_all').prop('checked', true);
    }

    // Toggle Checkbox logic: If specific roles are checked, uncheck "All", and vice versa
    $('#cb_vis_all').on('change', function() {
        if(this.checked) {
            $('input[name="vis_pastor"], input[name="vis_director"], input[name="vis_worker"]').prop('checked', false);
        }
    });
    $('input[name="vis_pastor"], input[name="vis_director"], input[name="vis_worker"]').on('change', function() {
        if(this.checked) $('#cb_vis_all').prop('checked', false);
    });

    // Make sure you bind the form
    $(document).ready(function() {
        handleAjaxSubmit('saveNoteForm', 'manageNotesModal');
        
        // Ensure reload refetches both endpoints if needed
        $('#saveNoteForm').on('submit', function() {
            setTimeout(() => { loadTimerPipeline(); loadArchive(); }, 500);
        });
    });

    // Update filterPipeline to handle Archive tab
    function filterPipeline(type) {
        currentFilter = type;
        
        $('#tab_all, #tab_mine, #tab_archive').removeClass('bg-gray-900 text-white').addClass('bg-white text-gray-500 border border-gray-200');
        $('#tab_insights').removeClass('bg-blue-50 text-blue-800 border-blue-200').addClass('bg-white text-blue-600 border border-blue-200');

        $('#pipelineContainer, #insightsContainer, #archiveContainer').addClass('hidden');

        if(type === 'insights') {
            $('#tab_insights').removeClass('bg-white text-blue-600 border border-blue-200').addClass('bg-blue-50 text-blue-800 border-blue-200');
            $('#insightsContainer').removeClass('hidden');
            renderInsights(); 
        } else if (type === 'archive') {
            $('#tab_archive').removeClass('bg-white text-gray-500 border border-gray-200').addClass('bg-gray-900 text-white');
            $('#archiveContainer').removeClass('hidden');
            loadArchive();
        } else {
            if(type === 'all') $('#tab_all').removeClass('bg-white text-gray-500 border border-gray-200').addClass('bg-gray-900 text-white');
            if(type === 'mine') $('#tab_mine').removeClass('bg-white text-gray-500 border border-gray-200').addClass('bg-gray-900 text-white');
            $('#pipelineContainer').removeClass('hidden');
            renderPipelineTable();
        }
    }

    function loadArchive() {
        $('#archiveTableBody').html(`<tr><td colspan="4" class="px-6 py-10 text-center"><p class="text-gray-500 font-medium animate-pulse">Fetching archives...</p></td></tr>`);
        
        $.getJSON(API_URL, { action: 'fetch_archive' }, res => {
            if(res.status === 'success') {
                let html = '';
                if (res.data.length === 0) {
                    html = `<tr><td colspan="4" class="px-6 py-16 text-center text-gray-400 italic">No archived records found.</td></tr>`;
                } else {
                    res.data.forEach(a => {
                        let formattedNotes = (a.secure_notes || []).map(n => `<div class="mb-2 bg-gray-50 p-3 rounded-xl border border-gray-100"><span class="font-bold text-gray-800 text-xs">${n.first_name}:</span> <span class="text-gray-600 text-sm whitespace-pre-wrap">${n.note_text}</span></div>`).join('');
                        if(!formattedNotes) formattedNotes = '<span class="text-xs text-gray-400 italic">No accessible notes found.</span>';

                        let archiveDateDisplay = a.archive_date ? new Date(a.archive_date).toLocaleDateString() : 'Unknown Date';

                        html += `
                        <tr class="hover:bg-gray-50/50 transition border-b border-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap"><span class="text-xs font-bold text-gray-600">${archiveDateDisplay}</span></td>
                            <td class="px-6 py-4"><p class="font-bold text-gray-900">${a.first_name} ${a.last_name}</p><p class="text-[10px] text-gray-400">${a.phone || ''}</p></td>
                            <td class="px-6 py-4"><span class="text-xs font-bold text-blue-600">${a.worker_fname ? a.worker_fname + ' ' + a.worker_lname : 'Unassigned'}</span></td>
                            <td class="px-6 py-4">${formattedNotes}</td>
                        </tr>`;
                    });
                }
                $('#archiveTableBody').html(html);
            } else {
                $('#archiveTableBody').html(`<tr><td colspan="4" class="px-6 py-10 text-center text-red-500 font-bold">Error: ${res.message}</td></tr>`);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            $('#archiveTableBody').html(`<tr><td colspan="4" class="px-6 py-10 text-center"><p class="text-red-500 font-bold mb-1">Server Error (500)</p><p class="text-xs text-gray-500">Check the backend logs for database issues.</p></td></tr>`);
            console.error("Archive Fetch Error:", textStatus, errorThrown);
        });
    }

    function loadTimerPipeline() {
        $.getJSON(API_URL, { action: 'fetch_visitors' }, res => {
            if(res.status === 'success') {
                globalVisitorsData = res.data;
                renderPipelineTable();
            }
        });
    }

    function viewReport(workerName, notes) {
        $('#view_report_worker').text(workerName);
        $('#view_report_status').text("Completed");
        $('#view_report_notes').text(notes);
        openModal('viewReportModal');
    }

    function prepLogModal(followupId, visitorId, name, isBa, wantsJoin, visitPref) {
        $('#log_followup_id').val(followupId);
        $('#log_visitor_id').val(visitorId);
        $('#log_visitor_name').text(name);
        
        $('#log_ba').prop('checked', isBa == 1);
        $('#log_join').prop('checked', wantsJoin == 1);
        $('#logFollowupForm').find('[name="visitation_preference"]').val(visitPref || 'None');

        openModal('logFollowupModal');
    }

    function prepAssignModal(id, name) {
        $('#assign_visitor_id').val(id);
        $('#assign_visitor_name').text(name);
        openModal('assignFollowupModal');
    }
    
    function prepPromoteModal(id, name) {
        $('#promote_visitor_id').val(id);
        $('#promote_visitor_name').text(name);
        openModal('promoteMemberModal');
    }

    // ==========================================
    // FORM SUBMISSIONS & GEOAPIFY
    // ==========================================
    function handleAjaxSubmit(formId, modalId) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();

            // Restricted email domain validation (Maintained constraint)
            if (formId === 'addVisitorForm') {
                const emailVal = $(this).find('[name="email"]').val();
                if (emailVal && emailVal.toLowerCase().endsWith('@hodlc.com')) {
                    Toastify({ 
                        text: "Please enter a personal email (like @gmail.com). The @hodlc.com domain is reserved for staff.", 
                        duration: 4000, 
                        gravity: "top", 
                        position: "center", 
                        style: { background: "#F59E0B", color: "#fff", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
                    }).showToast();
                    return false;
                }
            }

            const btn = $(this).find('button[type="submit"]');
            const origText = btn.html();
            const spinner = `<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Processing...');
            lockScreenAction(); 

            let formData = (formId === 'addVisitorForm') ? new FormData(this) : $(this).serialize();

            $.ajax({
                url: API_URL,
                type: 'POST',
                data: formData,
                contentType: (formId === 'addVisitorForm') ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
                processData: (formId === 'addVisitorForm') ? false : true,
                success: res => {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origText);
                    unlockScreenAction(); 
                    
                    Toastify({ 
                        text: res.message, 
                        duration: 3000, 
                        gravity: "top", 
                        position: "center", 
                        style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
                    }).showToast();
                    
                    if(res.status === 'success') {
                        closeModal(modalId);
                        if (typeof loadTimerPipeline === 'function') loadTimerPipeline();
                    }
                },
                error: function() {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origText);
                    unlockScreenAction();
                    
                    Toastify({ 
                        text: "Server error occurred.", 
                        duration: 3000, gravity: "top", position: "center", 
                        style: { background: "#EF4444", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
                    }).showToast();
                }
            });
        });
    }
    
    function initEmbraceAutocomplete() {
        const container = document.getElementById("autocomplete-container");
        if (!container) return;

        embraceAddressWidget = new autocomplete.GeocoderAutocomplete(
            container, 
            "7a189b607e9e4c4cbedf6ada291b6bc1", 
            { 
                placeholder: "Search location...",
                filter: { countrycode: ['ng'] } 
            }
        );

        embraceAddressWidget.on('select', (location) => {
            if (location) {
                document.getElementById('embraceLat').value = location.properties.lat;
                document.getElementById('embraceLng').value = location.properties.lon;
                document.getElementById('embraceAddress').value = location.properties.formatted;
            } else {
                document.getElementById('embraceLat').value = '';
                document.getElementById('embraceLng').value = '';
                document.getElementById('embraceAddress').value = '';
            }
        });

        container.addEventListener('keydown', function(e) { 
            if (e.key === 'Enter') e.preventDefault(); 
        });
    }

    $(document).ready(function() {
        // Smart Form Interaction for Invitation Source
        $('#invitation_source').on('change', function() {
            const val = $(this).val();
            const div = $('#invitedByDiv');
            const label = $('#invitedByLabel');
            const input = $('#invited_by_input');

            if(val === 'Church_Member') { 
                div.removeClass('hidden'); label.text('Member Name'); input.attr('placeholder', 'e.g. Bro. Gospel'); 
            }
            else if(val === 'Social_Media') { 
                div.removeClass('hidden'); label.text('Platform / Page'); input.attr('placeholder', 'e.g. Instagram, Facebook'); 
            }
            else if(val === 'Other') { 
                div.removeClass('hidden'); label.text('Please Specify Event/Location'); input.attr('placeholder', 'e.g. Aizagada Conference, HQ'); 
            }
            else { 
                div.addClass('hidden'); input.val('');
            }
        });

        loadTimerPipeline();
        initEmbraceAutocomplete();
        
        $.getJSON(API_URL, { action: 'fetch_workers' }, res => {
            if(res.status === 'success') {
                let opts = '<option value="" disabled selected>Select Team Member...</option>';
                res.data.forEach(w => opts += `<option value="${w.id}">${w.first_name} ${w.last_name}</option>`);
                $('#embraceWorkerSelect').html(opts);
            }
        });

        handleAjaxSubmit('addVisitorForm', 'addVisitorModal');
        handleAjaxSubmit('logFollowupForm', 'logFollowupModal');
        handleAjaxSubmit('assignFollowupForm', 'assignFollowupModal'); 
        handleAjaxSubmit('promoteMemberForm', 'promoteMemberModal');   

        $('#maritalSelect').on('change', function() {
            if($(this).val() === 'Married') $('#annivDiv').removeClass('hidden');
            else $('#annivDiv').addClass('hidden');
        });

        $('#searchInput').on('keyup', function() {
            const val = $(this).val().toLowerCase();
            $('#visitorsTableBody tr').each(function() {
                if($(this).find('td').attr('colspan')) return; 
                $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>