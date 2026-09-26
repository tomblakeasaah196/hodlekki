<?php
// /modules/departments/index.php
require_once '../../includes/header.php'; 
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10 relative">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Church Departments</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Manage ministry units, leadership, and worker rosters.</p>
            </div>
        </div>
        
        <div class="relative z-10">
            <button onclick="openCreateModal()" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New Department
            </button>
        </div>
    </div>

    <div id="view-dashboard" class="animate-fade-in-up">
        <div id="loadingOverlay" class="flex flex-col items-center justify-center py-20">
            <svg class="animate-spin h-10 w-10 text-hodBlue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing organizational structure...</p>
        </div>
        
        <div id="departmentsGrid" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            </div>
    </div>

    <div id="view-details" class="hidden animate-fade-in-up space-y-6">
        <div class="flex items-center gap-4 mb-2">
            <button onclick="showDashboard()" class="text-gray-500 hover:text-hodBlue flex items-center gap-1 font-bold transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back to Departments
            </button>
        </div>

        <div class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100 shadow-sm">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b border-gray-100 pb-6 gap-4">
                <div>
                    <h2 id="detailDeptName" class="text-3xl font-display font-bold text-gray-900"></h2>
                    <p id="detailDeptDesc" class="text-gray-600 mt-2 max-w-2xl"></p>
                    <div class="flex flex-wrap gap-2 mt-3" id="detailDeptBadges"></div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="editDepartment()" class="bg-white text-gray-700 hover:bg-gray-50 px-5 py-2.5 rounded-xl font-bold transition-all border border-gray-200 shadow-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> Edit Settings
                    </button>
                    <button onclick="openModal('assignWorkerModal')" class="bg-green-50 text-green-700 hover:bg-green-600 hover:text-white px-5 py-2.5 rounded-xl font-bold transition-all border border-green-200 shadow-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg> Assign Worker
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-blue-50/50 p-5 rounded-2xl border border-blue-100">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Pastor in Charge</p>
                    <p id="detailAssocPastor" class="font-bold text-gray-900 text-lg">Unassigned</p>
                </div>
                <div class="bg-purple-50/50 p-5 rounded-2xl border border-purple-100">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Director in Charge</p>
                    <p id="detailDirector" class="font-bold text-gray-900 text-lg">Unassigned</p>
                </div>
                <div class="bg-yellow-50/50 p-5 rounded-2xl border border-yellow-100">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Head of Department (HOD)</p>
                    <p id="detailHOD" class="font-bold text-gray-900 text-lg">Unassigned</p>
                </div>
            </div>

            <h3 class="text-xl font-bold text-gray-900 mb-4">Nested Sub-Units</h3>
            <div id="subUnitsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8"></div>

            <h3 class="text-xl font-bold text-gray-900 mb-4 flex justify-between items-center">
                <span>Official Roster & Service History</span>
            </h3>
            <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Worker Name</th>
                            <th class="px-6 py-4">Role / Title</th>
                            <th class="px-6 py-4">Season</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4">Joined Date</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="rosterTableBody" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="createDeptModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        
        <div class="flex-shrink-0 p-5 md:p-6 border-b border-gray-100 flex justify-between items-center bg-white z-10">
            <div>
                <h3 id="createDeptModalTitle" class="text-xl md:text-2xl font-display font-bold text-gray-900 tracking-tight">Create Department</h3>
                <p class="text-xs text-gray-500 mt-1 font-medium">Configure ministry units and leadership structure</p>
            </div>
            <button onclick="closeModal('createDeptModal')" class="text-gray-400 hover:bg-red-50 hover:text-red-500 p-2 rounded-full transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-5 md:p-8 custom-scrollbar bg-gray-50/50">
            <form id="deptForm" class="space-y-6"> 
                <input type="hidden" name="action" value="save_department">
                <input type="hidden" name="department_id" id="formDeptId">
                
                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <div class="flex justify-between items-center border-b border-gray-50 pb-3">
                        <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest">1. Core Details</h4>
                        <div class="flex items-center gap-2">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Operational Season:</label>
                            <input type="number" name="season" id="formSeason" required value="<?php echo date('Y'); ?>"
                                   class="w-24 px-3 py-1.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-gray-900 font-bold text-sm text-center">
                        </div>
                    </div>
                    
                    <div> 
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Department Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Information & Data Insights" 
                               class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 transition-all text-gray-900 font-bold placeholder-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Description</label>
                        <textarea name="description" rows="2" placeholder="Briefly describe the unit's purpose..." class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 resize-none transition-all text-gray-900 font-medium placeholder-gray-300"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Department Type</label>
                            <select name="type" class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 transition-all bg-white text-gray-900 font-bold cursor-pointer appearance-none">
                                <option value="Service_Unit">Service Unit</option>
                                <option value="Ministry_Arm">Ministry Arm</option>
                                <option value="Focused_Group">Focused Group</option>
                                <option value="Administrative">Administrative / Office</option>
                                <option value="Welfare">Welfare</option>
                                <option value="Special_Task_Force">Special Task Force</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Demographic Rules</label>
                            <select name="target_demographic" class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition-all bg-white text-gray-900 font-bold cursor-pointer appearance-none">
                                <option value="All">All Members (Mixed)</option>
                                <option value="Male_Only">Male Only (e.g. Mighty Men)</option>
                                <option value="Female_Only">Female Only (e.g. Deborah's Court)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-3">2. Hierarchy & Leadership</h4>
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Parent Department</label>
                        <select name="parent_id" id="formParentId" class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 transition-all bg-white text-gray-900 font-medium cursor-pointer appearance-none">
                            <option value="">-- Set as Master Department --</option>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1.5 font-medium">Select a parent only if this is a sub-unit (e.g., Audio under Envision).</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                        <div class="bg-blue-50/30 p-4 rounded-xl border border-blue-50">
                            <label class="block text-xs font-bold text-blue-800 uppercase mb-2 tracking-wider">Pastor in Charge</label>
                            <select name="assoc_pastor_id" id="formAssocPastor" class="w-full px-4 py-3 rounded-xl border-2 border-white shadow-sm outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-100 transition-all bg-white text-gray-900 font-bold cursor-pointer"></select>
                        </div>
                        <div class="bg-purple-50/30 p-4 rounded-xl border border-purple-50">
                            <label class="block text-xs font-bold text-purple-800 uppercase mb-2 tracking-wider">Director in Charge</label>
                            <select name="director_id" id="formDirector" class="w-full px-4 py-3 rounded-xl border-2 border-white shadow-sm outline-none focus:border-purple-400 focus:ring-4 focus:ring-purple-100 transition-all bg-white text-gray-900 font-bold cursor-pointer"></select>
                        </div>
                        <div class="md:col-span-2 bg-yellow-50/30 p-4 rounded-xl border border-yellow-50">
                            <label class="block text-xs font-bold text-yellow-800 uppercase mb-2 tracking-wider">Head of Department (HOD)</label>
                            <select name="hod_id" id="formHOD" class="w-full px-4 py-3 rounded-xl border-2 border-white shadow-sm outline-none focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100 transition-all bg-white text-gray-900 font-bold cursor-pointer"></select>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex-shrink-0 p-5 md:p-6 border-t border-gray-100 bg-white flex justify-end gap-3 z-10">
            <button type="button" onclick="closeModal('createDeptModal')" class="px-6 py-3.5 rounded-xl font-bold text-gray-500 hover:bg-gray-100 hover:text-gray-800 transition-colors hidden sm:block">Cancel</button>
            <button type="submit" form="deptForm" class="bg-hodBlue hover:bg-[#152750] text-white px-8 py-3.5 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all w-full sm:w-auto flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Save & Propagate Roles
            </button>
        </div>
    </div>
</div>

<div id="assignWorkerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-green-50 shrink-0">
            <h3 class="text-xl font-bold text-green-800">Assign Worker</h3>
            <button onclick="closeModal('assignWorkerModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWorkerForm" class="p-6 space-y-5 overflow-y-auto custom-scrollbar flex-1">
            <input type="hidden" name="action" value="assign_worker">
            <input type="hidden" name="department_id" id="assignDeptId">
            
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Select Worker *</label>
                    <select name="user_id" id="assignUserSelect" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium"></select>
                </div>
            </div>
            
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Role in Unit *</label>
                    <select name="role_in_dept" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                        <option value="Worker">Standard Worker</option>
                        <option value="Sub_Unit_Head">Sub-Unit Head</option>
                    </select>
                </div>
                <div class="w-1/3">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Season *</label>
                    <input type="number" name="season" required value="<?php echo date('Y'); ?>" 
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                </div>
            </div>
            
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all mt-4">Officially Assign</button>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
    /* Make Select2 match your Tailwind UI perfectly */
    .select2-container .select2-selection--single { height: 46px !important; border-radius: 0.75rem !important; border: 1px solid #e5e7eb !important; padding: 8px 12px !important; outline: none !important; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px !important; right: 10px !important; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: #111827 !important; font-weight: 600 !important; line-height: 28px !important; }
</style>

<script>
    const API_URL = '../../api/department_api.php';
    let currentDeptId = null;
    let currentDeptData = null; // Used to cache the raw data for quick editing
    let globalUsers = [];

    // ==========================================
    // UI CORE LOGIC (UPGRADED)
    // ==========================================

    function lockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        if(blocker) {
            blocker.classList.remove('hidden');
            document.body.style.overflow = 'hidden'; 
            setTimeout(() => blocker.classList.remove('opacity-0'), 10);
        }
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        if(blocker) {
            blocker.classList.add('opacity-0');
            setTimeout(() => {
                blocker.classList.add('hidden');
                if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                    document.body.style.overflow = ''; 
                }
            }, 300);
        }
    }

    function showDashboard() {
        $('#view-details').addClass('hidden').removeClass('animate-fade-in-up');
        $('#view-dashboard').removeClass('hidden').addClass('animate-fade-in-up');
        loadDashboard();
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        
        document.body.appendChild(modal); // Viewport Escape
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Scroll lock

        requestAnimationFrame(() => { 
            modal.classList.remove('opacity-0'); 
            modal.children[0].classList.remove('scale-95'); 
        });
    }

    function closeModal(id) {
    const modal = document.getElementById(id);
    if(!modal) return;

    modal.classList.add('opacity-0'); 
    modal.children[0].classList.add('scale-95');

    setTimeout(() => { 
        modal.classList.add('hidden'); 
        
        // PATCH: Apply the global safety check before unlocking the scroll
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }

        const f = modal.querySelector('form'); 
        if(f) {
            f.reset(); 
            // Reset Select2 if it exists in the form
            const select2El = $(f).find('.select2-hidden-accessible');
            if (select2El.length) {
                select2El.val(null).trigger('change');
            }
        }
    }, 300);
}

    function showToast(msg, type = 'success') {
        Toastify({ 
            text: msg, 
            gravity: "top", 
            position: "center", 
            duration: 4000,
            style: { 
                background: type === 'success' ? "#10B981" : (type === 'warning' ? "#F59E0B" : "#EF4444"), 
                borderRadius: "10px", 
                fontWeight: "bold",
                boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
            } 
        }).showToast();
    }

    function openCreateModal() {
        $('#deptForm')[0].reset();
        $('#formDeptId').val(''); // Ensure we are creating, not updating
        $('#formSeason').val(new Date().getFullYear()); // Reset season to current year
        $('#createDeptModalTitle').text('Create New Department');
        openModal('createDeptModal');
    }

    function editDepartment() {
        if(!currentDeptData) return;
        
        // Populate the form with cached data
        $('#formDeptId').val(currentDeptData.id);
        $('input[name="name"]').val(currentDeptData.name);
        $('textarea[name="description"]').val(currentDeptData.description);
        $('select[name="type"]').val(currentDeptData.type);
        $('select[name="target_demographic"]').val(currentDeptData.target_demographic);
        $('#formParentId').val(currentDeptData.parent_id || '');
        $('#formAssocPastor').val(currentDeptData.assoc_pastor_id || '');
        $('#formDirector').val(currentDeptData.director_id || '');
        $('#formHOD').val(currentDeptData.hod_id || '');
        // Keep the season field defaulted to the current year so they are editing for the active season

        $('#createDeptModalTitle').text('Edit Department Settings');
        openModal('createDeptModal');
    }

    // Load Master Data
    function loadDashboard() {
        $('#loadingOverlay').removeClass('hidden');
        $('#departmentsGrid').addClass('hidden');
        
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            $('#loadingOverlay').addClass('hidden');
            if(res.status === 'success') {
                globalUsers = res.users;
                
                // Populate Dropdowns for Forms
                let pOpts = '<option value="">-- Master Department --</option>';
                res.all_depts.forEach(d => pOpts += `<option value="${d.id}">${d.name}</option>`);
                $('#formParentId').html(pOpts);
                
                let pastorOpts = '<option value="">-- Select Pastor --</option>';
                res.pastors.forEach(u => pastorOpts += `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`);
                $('#formAssocPastor').html(pastorOpts);
                
                let directorOpts = '<option value="">-- Select Director --</option>';
                res.directors.forEach(u => directorOpts += `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`);
                $('#formDirector').html(directorOpts);
                
                let workerOpts = '<option value="">-- Select Person --</option>';
                res.all_workers.forEach(u => workerOpts += `<option value="${u.id}">${u.first_name} ${u.last_name} (${u.gender})</option>`);
                $('#formHOD').html(workerOpts);
                $('#assignUserSelect').html(workerOpts);

                // PATCH: Initialize the Smart Autofill on the Assign Worker dropdown
                $('#assignUserSelect').select2({
                    placeholder: "Type 2 letters to search...",
                    minimumInputLength: 2,
                    width: '100%',
                    dropdownParent: $('#assignWorkerModal') // Ensures dropdown stays inside the modal even after appending to body
                });

                // Render Dashboard Cards
                let html = '';
                if(res.master_departments.length === 0) {
                    html = '<div class="col-span-full py-10 text-center text-gray-500">No departments configured yet.</div>';
                } else {
                    res.master_departments.forEach(d => {
                        let demoBadge = d.target_demographic !== 'All' 
                            ? `<span class="bg-red-50 text-red-600 px-2 py-1 rounded text-[10px] font-bold uppercase">${d.target_demographic.replace('_', ' ')}</span>` 
                            : '';
                        
                        html += `
                        <div onclick="viewDepartment(${d.id})" class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow cursor-pointer relative overflow-hidden group">
                            <div class="absolute top-0 right-0 w-2 h-full bg-hodBlue opacity-20 group-hover:opacity-100 transition-opacity"></div>
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900">${d.name}</h3>
                                    <p class="text-xs text-gray-500 mt-1 uppercase tracking-wider font-bold">${d.type.replace('_', ' ')}</p>
                                </div>
                                ${demoBadge}
                            </div>
                            <p class="text-sm text-gray-600 line-clamp-2 mb-6 h-10">${d.description || 'No description provided.'}</p>
                            
                            <div class="flex items-center gap-4 text-sm font-medium border-t border-gray-50 pt-4">
                                <div class="flex items-center gap-1 text-gray-600"><svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg> ${d.active_members} Active</div>
                                <div class="flex items-center gap-1 text-gray-600"><svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg> ${d.sub_unit_count} Units</div>
                            </div>
                        </div>`;
                    });
                }
                $('#departmentsGrid').html(html).removeClass('hidden');
            } else {
                showToast(res.message, "error");
            }
        });
    }

    function viewDepartment(id) {
    currentDeptId = id;
    $('#assignDeptId').val(id);
    
    // PATCH: Grab the clicked element for localized loading UX instead of freezing the screen
    const triggerEl = window.event ? window.event.currentTarget : null;
    const origHtml = triggerEl ? triggerEl.innerHTML : '';
    
    if (triggerEl && triggerEl.tagName === 'BUTTON') {
        triggerEl.innerHTML = `<svg class="animate-spin h-4 w-4 mx-auto text-hodBlue" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        triggerEl.classList.add('opacity-50', 'pointer-events-none');
    } else if (triggerEl) {
        triggerEl.classList.add('animate-pulse', 'pointer-events-none');
    }
    
    $.post(API_URL, { action: 'fetch_department_details', department_id: id }, function(res) {
        // Restore UI state
        if (triggerEl && triggerEl.tagName === 'BUTTON') {
            triggerEl.innerHTML = origHtml;
            triggerEl.classList.remove('opacity-50', 'pointer-events-none');
        } else if (triggerEl) {
            triggerEl.classList.remove('animate-pulse', 'pointer-events-none');
        }
        
        if(res.status === 'success') {
            $('#view-dashboard').addClass('hidden').removeClass('animate-fade-in-up');
            $('#view-details').removeClass('hidden').addClass('animate-fade-in-up');
            
            currentDeptData = res.info; // Cache data for the edit modal

            // Header Info
            $('#detailDeptName').text(res.info.name);
            $('#detailDeptDesc').text(res.info.description || 'No description.');
            $('#detailDeptBadges').html(`
                <span class="bg-blue-50 text-hodBlue px-3 py-1 rounded-full text-xs font-bold uppercase">${res.info.type.replace('_', ' ')}</span>
                <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-full text-xs font-bold uppercase border border-gray-200">${res.info.target_demographic.replace('_', ' ')}</span>
            `);

            // Leadership
            $('#detailAssocPastor').text(res.info.ap_fname ? `${res.info.ap_fname} ${res.info.ap_lname}` : 'Unassigned');
            $('#detailDirector').text(res.info.dir_fname ? `${res.info.dir_fname} ${res.info.dir_lname}` : 'Unassigned');
            $('#detailHOD').text(res.info.hod_fname ? `${res.info.hod_fname} ${res.info.hod_lname}` : 'Unassigned');

            // Sub Units
            let subHtml = '';
            if(res.sub_units.length === 0) {
                subHtml = '<div class="col-span-full text-sm text-gray-500 italic">No sub-units defined.</div>';
            } else {
                res.sub_units.forEach(s => {
                    subHtml += `
                    <div class="flex justify-between items-center p-4 bg-gray-50 rounded-2xl border border-gray-100">
                        <div><h4 class="font-bold text-gray-900">${s.name}</h4><p class="text-xs text-gray-500">${s.active_members} Members</p></div>
                        <button onclick="viewDepartment(${s.id})" class="text-xs bg-white border border-gray-200 text-hodBlue font-bold px-3 py-1.5 rounded-lg shadow-sm">Manage</button>
                    </div>`;
                });
            }
            $('#subUnitsGrid').html(subHtml);

            // Full Roster (Active & Historical)
            let rHtml = '';
            if(res.roster.length === 0) {
                rHtml = '<tr><td colspan="6" class="text-center py-8 text-gray-500">No workers assigned yet.</td></tr>';
            } else {
                res.roster.forEach(r => {
                    let isLdr = ['Assoc_Pastor', 'Director', 'HOD'].includes(r.role_in_dept);
                    let roleColor = isLdr ? 'text-purple-600 bg-purple-50' : 'text-gray-600 bg-gray-100';
                    let statusBadge = r.is_active == 1 
                        ? `<span class="text-green-600 font-bold text-xs"><span class="w-2 h-2 inline-block bg-green-500 rounded-full mr-1"></span>Active</span>` 
                        : `<span class="text-red-500 font-bold text-xs italic">Relieved: ${r.end_date}</span>`;
                    
                    let actBtn = (r.is_active == 1 && !isLdr) 
                        ? `<button onclick="removeWorker(${r.record_id})" class="text-xs bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded font-bold transition-colors border border-red-100">Remove</button>`
                        : `<span class="text-[10px] text-gray-400">Locked</span>`;

                    rHtml += `
                    <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors ${r.is_active == 0 ? 'opacity-60' : ''}">
                        <td class="px-6 py-4 font-bold text-gray-900">
                            <div>${r.first_name} ${r.last_name}</div>
                            <span class="text-[10px] text-gray-400 font-normal">${r.phone || 'No phone'} | ${r.gender}</span>
                        </td>
                        <td class="px-6 py-4"><span class="px-2 py-1 rounded text-xs font-bold ${roleColor}">${r.role_in_dept.replace('_', ' ')}</span></td>
                        <td class="px-6 py-4 text-xs font-bold text-gray-700">${r.season}</td>
                        <td class="px-6 py-4 text-center">${statusBadge}</td>
                        <td class="px-6 py-4 text-xs text-gray-500">${new Date(r.joined_at).toLocaleDateString()}</td>
                        <td class="px-6 py-4 text-right">${actBtn}</td>
                    </tr>`;
                });
            }
            $('#rosterTableBody').html(rHtml);
        } else {
            showToast(res.message, "error");
        }
    }).fail(function() {
        if (triggerEl && triggerEl.tagName === 'BUTTON') {
            triggerEl.innerHTML = origHtml;
            triggerEl.classList.remove('opacity-50', 'pointer-events-none');
        } else if (triggerEl) {
            triggerEl.classList.remove('animate-pulse', 'pointer-events-none');
        }
        showToast("Server Connection Error.", "error");
    });
}

    function removeWorker(recordId) {
    if(!confirm("Are you sure you want to remove this worker from the department? Their historical record will be kept.")) return;
    
    // PATCH: Grab the inline button to give immediate tactile feedback
    const btn = window.event ? window.event.currentTarget : null;
    if (btn) {
        btn.innerHTML = 'Removing...';
        btn.classList.add('opacity-50', 'pointer-events-none');
    }
    
    lockScreenAction();
    
    $.post(API_URL, { action: 'remove_worker', record_id: recordId }, function(res) {
        unlockScreenAction();
        showToast(res.message, res.status);
        
        if(res.status === 'success') {
            viewDepartment(currentDeptId); // Reload UI
        } else if (btn) {
            btn.innerHTML = 'Remove'; // Revert on failure
            btn.classList.remove('opacity-50', 'pointer-events-none');
        }
    }, 'json').fail(function() {
        unlockScreenAction();
        showToast("Server Error", "error");
        if (btn) {
            btn.innerHTML = 'Remove';
            btn.classList.remove('opacity-50', 'pointer-events-none');
        }
    });
}

    // Form Submissions
    $(document).ready(function() {
        loadDashboard();

        function handleAjaxForm(formId) {
            $(`#${formId}`).on('submit', function(e) {
                e.preventDefault();
                
                const formElement = $(this);
                const btn = formElement.find('button[type="submit"]');
                const origHtml = btn.html(); 
                
                // Dynamically grab the parent Modal ID so it actually closes the window!
                const modalId = formElement.closest('.fixed.inset-0').attr('id');
                
                const spinner = `<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
                
                btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed pointer-events-none').html(spinner + 'Processing...');
                lockScreenAction(); // Lock Screen
                
                $.post(API_URL, formElement.serialize(), function(res) {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed pointer-events-none').html(origHtml);
                    unlockScreenAction(); // Unlock Screen
                    
                    if (res.status === 'warning') {
                        showToast(res.message, "warning");
                        closeModal(modalId); 
                        viewDepartment(currentDeptId);
                    } 
                    else if(res.status === 'success') {
                        showToast(res.message, "success");
                        closeModal(modalId); 
                        
                        if (formId === 'deptForm') {
                            if ($('#formDeptId').val() === '') loadDashboard();
                            else viewDepartment(currentDeptId);
                        }
                        if (formId === 'assignWorkerForm') viewDepartment(currentDeptId);
                    } else {
                        showToast(res.message, "error");
                    }
                }, 'json').fail(function() {
                    btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed pointer-events-none').html(origHtml);
                    unlockScreenAction();
                    showToast("Server Connection Error.", "error");
                });
            });
        }

        handleAjaxForm('deptForm');
        handleAjaxForm('assignWorkerForm');
    });
</script>

<?php require_once '../../includes/footer.php'; ?>