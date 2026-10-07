<?php
// /modules/departments/index.php
require_once '../../includes/header.php';
require_once '../../includes/department_helpers.php';

// Leadership only. Rank roles always get in; a Director / HOD / Sub-Unit Head
// needs a live seat in the current ministry year, which is exactly what the
// module's remove/relieve actions take away.
$dept_role_ok = in_array($_SESSION['active_role'] ?? '', ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true);
$dept_seat_ok = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $r) {
        if (($r['role_name'] ?? '') === 'Super_Admin') {
            $dept_role_ok = true;
        }
    }
}
if (!$dept_seat_ok && isset($pdo)) {
    $seatStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM user_departments
          WHERE user_id = ? AND is_active = 1
            AND role_in_dept IN ('Director', 'HOD', 'Sub_Unit_Head')"
    );
    $seatStmt->execute([$_SESSION['user_id']]);
    $dept_seat_ok = ((int) $seatStmt->fetchColumn()) > 0;
}
if (!$dept_role_ok && !$dept_seat_ok) {
    ?>
    <div class="max-w-2xl mx-auto mt-10 bg-white border border-gray-100 rounded-3xl p-8 shadow-sm text-center animate-fade-in-up">
        <div class="w-14 h-14 mx-auto bg-amber-50 text-amber-500 rounded-2xl flex items-center justify-center mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        </div>
        <h2 class="text-2xl font-display font-bold text-gray-900">Department management is restricted</h2>
        <p class="text-gray-500 mt-3 text-sm leading-relaxed">
            This module is for the Pastor in Charge, Directors and HODs of the current ministry year.
            If you have just been relieved of a department role, access is removed automatically —
            the same role can be given back at any time by the church leadership.
        </p>
        <a href="/modules/dashboard/index.php" class="inline-block mt-6 bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all">Back to Dashboard</a>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

// The schema probe only ever runs on this page, never in the shared header.
$dept_schema_ok = dept_schema_ready($pdo);
if (!$dept_schema_ok) {
    ?>
    <div class="max-w-2xl mx-auto mt-10 bg-white border border-amber-200 rounded-3xl p-8 shadow-sm animate-fade-in-up">
        <h2 class="text-2xl font-display font-bold text-gray-900">One migration is outstanding</h2>
        <p class="text-gray-600 mt-3 text-sm leading-relaxed"><?= htmlspecialchars(dept_schema_message()) ?></p>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10 relative">

    <!-- ===================== HEADER / TOOLSTRIP ===================== -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Church Departments</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Ministry units, leadership, and Primary / Secondary rosters.</p>
            </div>
        </div>

        <div class="relative z-10 flex flex-wrap items-center gap-3">
            <!-- Ministry year switcher -->
            <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <select id="yearSelect" class="bg-transparent text-sm font-bold text-gray-800 outline-none cursor-pointer max-w-[240px]"></select>
            </div>

            <button id="btnManageYears" onclick="openYearsModal()" class="hidden bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-3 rounded-xl font-bold shadow-sm transition-all">Years</button>
            <button id="btnRollover" onclick="openRolloverWizard()" class="hidden bg-white hover:bg-gray-50 text-hodBlue border border-blue-200 px-4 py-3 rounded-xl font-bold shadow-sm transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                Start Ministry Year
            </button>
            <button onclick="openDownloadModal()" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-3 rounded-xl font-bold shadow-sm transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download
            </button>
            <button id="btnNewDept" onclick="openCreateModal()" class="hidden bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New Department
            </button>
        </div>
    </div>

    <!-- Archived-year banner -->
    <div id="archiveBanner" class="hidden bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3 animate-fade-in-up">
        <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86l-8.02 13.9A1.8 1.8 0 003.79 20h16.42a1.8 1.8 0 001.52-2.24l-8.02-13.9a1.8 1.8 0 00-3.02 0z"></path></svg>
        <div class="text-sm">
            <p class="font-bold text-amber-800" id="archiveBannerTitle">Viewing an archived ministry year</p>
            <p class="text-amber-700 mt-0.5">Past years are kept for reference and downloads. Switch to the current year to make changes.</p>
        </div>
        <button onclick="jumpToLiveYear()" class="ml-auto shrink-0 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">Go to current year</button>
    </div>

    <!-- ===================== DASHBOARD VIEW ===================== -->
    <div id="view-dashboard" class="animate-fade-in-up">
        <div id="loadingOverlay" class="flex flex-col items-center justify-center py-20">
            <svg class="animate-spin h-10 w-10 text-hodBlue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing organizational structure...</p>
        </div>

        <div id="statStrip" class="hidden grid grid-cols-2 md:grid-cols-4 gap-4 mb-6"></div>
        <div id="departmentsGrid" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
    </div>

    <!-- ===================== DEPARTMENT DETAILS VIEW ===================== -->
    <div id="view-details" class="hidden animate-fade-in-up space-y-6">
        <div class="flex items-center justify-between gap-4 mb-2">
            <button onclick="showDashboard()" class="text-gray-500 hover:text-hodBlue flex items-center gap-1 font-bold transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back to Departments
            </button>
            <button onclick="openDownloadModal(currentDeptId)" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-2.5 rounded-xl font-bold shadow-sm transition-all flex items-center gap-2 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download
            </button>
        </div>

        <div class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100 shadow-sm">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b border-gray-100 pb-6 gap-4">
                <div>
                    <h2 id="detailDeptName" class="text-3xl font-display font-bold text-gray-900"></h2>
                    <p id="detailDeptDesc" class="text-gray-600 mt-2 max-w-2xl"></p>
                    <div class="flex flex-wrap gap-2 mt-3" id="detailDeptBadges"></div>
                </div>
                <div class="flex items-center gap-2" id="detailActions">
                    <button id="btnEditDept" onclick="editDepartment()" class="bg-white text-gray-700 hover:bg-gray-50 px-5 py-2.5 rounded-xl font-bold transition-all border border-gray-200 shadow-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        Edit Settings
                    </button>
                    <button id="btnAssign" onclick="openAssignModal()" class="bg-green-50 text-green-700 hover:bg-green-600 hover:text-white px-5 py-2.5 rounded-xl font-bold transition-all border border-green-200 shadow-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                        Add Member
                    </button>
                </div>
            </div>

            <!-- Leadership seats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8" id="seatGrid"></div>

            <h3 class="text-xl font-bold text-gray-900 mb-4">Sub-Units</h3>
            <div id="subUnitsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8"></div>

            <!-- Primary members -->
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-hodRed"></span> Primary Members
                    <span id="primaryCount" class="text-xs font-bold bg-red-50 text-hodRed px-2.5 py-1 rounded-full">0</span>
                </h3>
            </div>
            <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm mb-8">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Member</th>
                            <th class="px-6 py-4">Role</th>
                            <th class="px-6 py-4">Contact</th>
                            <th class="px-6 py-4">Joined</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="primaryTableBody" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>

            <!-- Secondary members -->
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span> Secondary Members
                    <span id="secondaryCount" class="text-xs font-bold bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full">0</span>
                </h3>
                <p class="text-[11px] text-gray-400 font-medium hidden md:block">Secondary members are people who serve occasionally — a bench that can be moved up in one click.</p>
            </div>
            <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Member</th>
                            <th class="px-6 py-4">Role</th>
                            <th class="px-6 py-4">Contact</th>
                            <th class="px-6 py-4">Joined</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="secondaryTableBody" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===================== CREATE / EDIT DEPARTMENT MODAL ===================== -->
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
                <input type="hidden" name="ministry_year_id" id="formYearId">

                <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-5">
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-3">1. Core Details</h4>

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
                    <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest border-b border-gray-50 pb-3">2. Hierarchy &amp; Leadership</h4>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Parent Department</label>
                        <select name="parent_id" id="formParentId" class="w-full px-4 py-3.5 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 transition-all bg-white text-gray-900 font-medium cursor-pointer appearance-none">
                            <option value="">-- Set as Master Department --</option>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1.5 font-medium">Select a parent only if this is a sub-unit (e.g., Audio under Envision).</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                        <div class="bg-blue-50/30 p-4 rounded-xl border border-blue-50" id="wrapAssocPastor">
                            <label class="block text-xs font-bold text-blue-800 uppercase mb-2 tracking-wider">Pastor in Charge</label>
                            <div class="dept-picker" data-picker="assoc_pastor_id" data-placeholder="Search for a pastor..."></div>
                        </div>
                        <div class="bg-purple-50/30 p-4 rounded-xl border border-purple-50">
                            <label class="block text-xs font-bold text-purple-800 uppercase mb-2 tracking-wider">Director in Charge</label>
                            <div class="dept-picker" data-picker="director_id" data-placeholder="Search for a director..."></div>
                        </div>
                        <div class="md:col-span-2 bg-yellow-50/30 p-4 rounded-xl border border-yellow-50">
                            <label class="block text-xs font-bold text-yellow-800 uppercase mb-2 tracking-wider">Head of Department (HOD)</label>
                            <div class="dept-picker" data-picker="hod_id" data-placeholder="Search for an HOD..."></div>
                        </div>
                    </div>

                    <p class="text-[11px] text-gray-500 bg-gray-50 border border-gray-100 rounded-xl p-3 leading-relaxed">
                        <span class="font-bold text-gray-700">Mid-term changes:</span> picking somebody new relieves the outgoing leader of the seat and takes them off the department roster. They are told by notification, and can be added back as a member at any time.
                    </p>
                </div>
            </form>
        </div>

        <div class="flex-shrink-0 p-5 md:p-6 border-t border-gray-100 bg-white flex justify-end gap-3 z-10">
            <button type="button" onclick="closeModal('createDeptModal')" class="px-6 py-3.5 rounded-xl font-bold text-gray-500 hover:bg-gray-100 hover:text-gray-800 transition-colors hidden sm:block">Cancel</button>
            <button type="submit" form="deptForm" class="bg-hodBlue hover:bg-[#152750] text-white px-8 py-3.5 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all w-full sm:w-auto flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Save &amp; Propagate Roles
            </button>
        </div>
    </div>
</div>

<!-- ===================== ADD MEMBER MODAL ===================== -->
<div id="assignWorkerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg flex flex-col max-h-[85vh] overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-green-50 shrink-0">
            <div>
                <h3 class="text-xl font-bold text-green-800">Add Member</h3>
                <p class="text-xs text-green-700/80 mt-1" id="assignDeptLabel">Choose a person and how they serve</p>
            </div>
            <button onclick="closeModal('assignWorkerModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWorkerForm" class="p-6 space-y-5 overflow-y-auto custom-scrollbar flex-1">
            <input type="hidden" name="action" value="assign_worker">
            <input type="hidden" name="department_id" id="assignDeptId">

            <div id="assignPickerWrap" class="dept-picker" data-picker="user_id" data-placeholder="Type a name to search..."></div>
            <p class="text-[11px] text-gray-500 bg-gray-50 border border-gray-100 rounded-xl p-3 leading-relaxed" id="assignPoolHint"></p>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Membership</label>
                    <select name="membership_type" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                        <option value="Primary">Primary</option>
                        <option value="Secondary">Secondary</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Role in Unit</label>
                    <select name="role_in_dept" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                        <option value="Worker">Worker</option>
                        <option value="Member">Member</option>
                        <option value="Sub_Unit_Head">Sub-Unit Head</option>
                    </select>
                </div>
            </div>

            <p class="text-[11px] text-gray-500 bg-gray-50 border border-gray-100 rounded-xl p-3 leading-relaxed">
                People already serving in this department this ministry year are hidden from the search. Someone removed earlier can simply be added back here.
            </p>

            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all">Add to Department</button>
        </form>
    </div>
</div>

<!-- ===================== LEADERSHIP SEAT MODAL ===================== -->
<div id="seatModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-blue-50">
            <div>
                <h3 class="text-lg font-bold text-hodBlue" id="seatModalTitle">Change Seat</h3>
                <p class="text-xs text-gray-500 mt-1">Applies immediately to the current ministry year</p>
            </div>
            <button onclick="closeModal('seatModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <input type="hidden" id="seatRole">
            <div id="seatCurrent" class="text-sm text-gray-600 bg-gray-50 border border-gray-100 rounded-xl p-3"></div>
            <div class="dept-picker" data-picker="seat_user_id" data-placeholder="Search for a person..."></div>
            <label class="flex items-center gap-2 text-sm text-gray-600 font-medium">
                <input type="checkbox" id="seatVacate" class="rounded border-gray-300 text-hodRed focus:ring-hodRed">
                Relieve the current leader and leave the seat vacant
            </label>
        </div>
        <div class="p-6 border-t border-gray-100 bg-white flex justify-end gap-3">
            <button onclick="closeModal('seatModal')" class="px-5 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-100 transition-colors">Cancel</button>
            <button onclick="saveSeat()" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all">Apply Change</button>
        </div>
    </div>
</div>

<!-- ===================== DOWNLOAD MODAL ===================== -->
<div id="downloadModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg flex flex-col max-h-[88vh] overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-hodBlue shrink-0">
            <div>
                <h3 class="text-xl font-bold text-white">Download Department Lists</h3>
                <p class="text-xs text-blue-100 mt-1" id="downloadYearLabel">Ministry Year</p>
            </div>
            <button onclick="closeModal('downloadModal')" class="text-blue-200 hover:text-white transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar flex-1">
            <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 text-sm text-gray-600" id="downloadSummary">Loading…</div>

            <div class="grid grid-cols-1 gap-3">
                <button onclick="downloadImage()" class="text-left border-2 border-gray-100 hover:border-hodRed rounded-2xl p-5 transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-red-50 text-hodRed flex items-center justify-center group-hover:bg-hodRed group-hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z"></path></svg>
                        </div>
                        <div>
                            <p class="font-bold text-gray-900">A4 image (JPEG)</p>
                            <p class="text-xs text-gray-500 mt-0.5">One beautiful A4 page: logo, ministry year, every department with its leaders, Primary and Secondary members.</p>
                        </div>
                    </div>
                </button>

                <button onclick="downloadFile('xlsx')" class="text-left border-2 border-gray-100 hover:border-green-500 rounded-2xl p-5 transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-green-50 text-green-600 flex items-center justify-center group-hover:bg-green-600 group-hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                        </div>
                        <div>
                            <p class="font-bold text-gray-900">Excel workbook (.xlsx)</p>
                            <p class="text-xs text-gray-500 mt-0.5">A summary sheet, one sheet per department, and an “All Workers” sheet — every member with phone and email.</p>
                        </div>
                    </div>
                </button>

                <button onclick="downloadFile('csv')" class="text-left border-2 border-gray-100 hover:border-gray-400 rounded-2xl p-5 transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-gray-100 text-gray-600 flex items-center justify-center group-hover:bg-gray-700 group-hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <div>
                            <p class="font-bold text-gray-900">Plain CSV (names only)</p>
                            <p class="text-xs text-gray-500 mt-0.5">A flat list of every worker in every department. Opens in anything.</p>
                        </div>
                    </div>
                </button>
            </div>

            <p class="text-[11px] text-gray-400 leading-relaxed">
                Removed members never appear in a download. The A4 image is rendered at 300 dpi (2480 × 3508 px) and always fits on one page — type shrinks automatically for very large rosters.
            </p>
        </div>
    </div>
</div>

<!-- ===================== MINISTRY YEARS MODAL ===================== -->
<div id="yearsModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl flex flex-col max-h-[88vh] overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <div>
                <h3 class="text-xl font-bold text-gray-900">Ministry Years</h3>
                <p class="text-xs text-gray-500 mt-1">Rosters, downloads and reports are all tied to a ministry year.</p>
            </div>
            <button onclick="closeModal('yearsModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 overflow-y-auto custom-scrollbar flex-1 space-y-6">
            <div id="yearsList" class="space-y-2"></div>
            <div class="border-t border-gray-100 pt-5">
                <h4 class="text-xs font-bold text-hodBlue uppercase tracking-widest mb-4">Add or edit a ministry year</h4>
                <form id="yearForm" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <input type="hidden" name="action" value="save_ministry_year">
                    <input type="hidden" name="id" id="yearFormId">
                    <div class="md:col-span-1">
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Label</label>
                        <input type="text" name="label" id="yearFormLabel" placeholder="2027" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Starts</label>
                        <input type="date" name="start_date" id="yearFormStart" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Ends</label>
                        <input type="date" name="end_date" id="yearFormEnd" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-medium">
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 text-xs font-bold text-gray-600">
                            <input type="checkbox" name="is_active" value="1" id="yearFormActive" class="rounded border-gray-300 text-hodBlue focus:ring-hodBlue">
                            Live
                        </label>
                        <button type="submit" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-lg font-bold text-sm shadow-sm transition-all">Save</button>
                    </div>
                </form>
                <p class="text-[11px] text-gray-400 mt-3">A new year can be created here quietly, or built properly with the <span class="font-bold text-gray-500">Start Ministry Year</span> worksheet.</p>
            </div>
        </div>
    </div>
</div>

<!-- ===================== ROLLOVER WORKSHEET MODAL ===================== -->
<div id="rolloverModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9998] flex items-center justify-center p-2 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-6xl flex flex-col h-[94vh] overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-5 md:p-6 border-b border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
            <div>
                <h3 class="text-xl md:text-2xl font-display font-bold text-gray-900">Start a New Ministry Year</h3>
                <p class="text-xs text-gray-500 mt-1" id="rolloverFromLabel">Reshuffle the roster, then pick the period.</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="closeModal('rolloverModal')" class="text-gray-400 hover:text-red-500 p-2 rounded-full transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
        </div>

        <!-- Period + presets -->
        <div class="px-5 md:px-6 py-4 bg-gray-50 border-b border-gray-100 grid grid-cols-1 md:grid-cols-4 gap-3 items-end shrink-0">
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Ministry Year label</label>
                <input type="text" id="roLabel" placeholder="2027" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-bold bg-white">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Period starts</label>
                <input type="date" id="roStart" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-medium bg-white">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Period ends</label>
                <input type="date" id="roEnd" class="w-full px-3 py-2.5 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-sm font-medium bg-white">
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="applyPreset('carry')" class="text-xs font-bold px-3 py-2.5 rounded-lg bg-hodBlue text-white hover:bg-[#152750] transition-colors">Carry everyone over</button>
                <button type="button" onclick="applyPreset('members')" class="text-xs font-bold px-3 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:bg-gray-100 transition-colors">Members only</button>
                <button type="button" onclick="applyPreset('empty')" class="text-xs font-bold px-3 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:bg-gray-100 transition-colors">Start empty</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto custom-scrollbar p-5 md:p-6 space-y-4" id="rolloverBody">
            <div class="py-16 text-center text-gray-400 text-sm">Loading the current roster…</div>
        </div>

        <div class="px-5 md:px-6 py-4 border-t border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-3 shrink-0 bg-white">
            <div class="text-sm text-gray-600" id="rolloverSummary"></div>
            <div class="flex items-center gap-3">
                <button onclick="closeModal('rolloverModal')" class="px-5 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-100 transition-colors">Cancel</button>
                <button onclick="commitRollover()" id="btnCommitRollover" class="bg-hodRed hover:bg-[#a81419] text-white px-7 py-3 rounded-xl font-bold shadow-lg shadow-red-900/20 transition-all">Create year &amp; go live</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== GLOBAL ACTION BLOCKER ===================== -->
<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm" id="blockerText">Processing...</span>
    </div>
</div>

<script src="../../assets/js/department_export.js"></script>
<script>
    const API_URL = '../../api/department_api.php';
    const EXPORT_URL = '../../api/department_export_excel.php';

    let state = {
        yearId: null,
        year: null,
        years: [],
        activeYearId: null,
        permissions: {},
        departments: [],
        allDepts: [],
        people: { pastors: [], directors: [], workers: [] },
        currentDeptId: null,
        currentDept: null,
        deptActiveUserIds: [],
        seatCache: {},
        rollover: null,
        liveHeadcount: 0
    };

    // ============================================================
    // Generic UI helpers (same behaviour as the rest of the platform)
    // ============================================================
    function lockScreenAction(text) {
        const blocker = document.getElementById('globalActionBlocker');
        document.getElementById('blockerText').textContent = text || 'Processing...';
        blocker.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => blocker.classList.remove('opacity-0'), 10);
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.add('opacity-0');
        setTimeout(() => {
            blocker.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
        }, 300);
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        document.body.appendChild(modal);
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            modal.classList.remove('opacity-0');
            modal.children[0].classList.remove('scale-95');
        });
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('opacity-0');
        modal.children[0].classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
            const form = modal.querySelector('form');
            if (form && id !== 'yearForm') {
                form.reset();
                modal.querySelectorAll('.dept-picker').forEach(p => pickerReset(p));
            }
        }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({
            text: msg,
            gravity: 'top',
            position: 'center',
            duration: type === 'error' ? 6000 : 4500,
            style: {
                background: type === 'success' ? '#10B981' : (type === 'warning' ? '#F59E0B' : '#EF4444'),
                borderRadius: '10px',
                fontWeight: 'bold',
                maxWidth: '520px',
                boxShadow: '0 10px 25px rgba(0,0,0,0.3)'
            }
        }).showToast();
    }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fullName(p) { return esc((p.first_name || '') + ' ' + (p.last_name || '')); }

    function roleLabel(r) {
        return ({ Assoc_Pastor: 'Pastor in Charge', Director: 'Director in Charge', HOD: 'Head of Department',
                  Sub_Unit_Head: 'Sub-Unit Head', Worker: 'Worker', Member: 'Member' })[r] || String(r).replace(/_/g, ' ');
    }

    function fmtDate(d) {
        if (!d) return '—';
        const dt = new Date(String(d).replace(' ', 'T'));
        return isNaN(dt) ? '—' : dt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // ============================================================
    // In-house searchable picker (replaces Select2 — one dependency less,
    // and it works inside modals that are re-parented to <body>).
    // ============================================================
    const pickers = new Map();

    function pickerInit(root, options, placeholder, onChange) {
        if (!root) return null;
        const key = root.dataset.picker;
        const existing = pickers.get(key);
        if (existing && existing.root === root) {
            existing.options = options;          // pool can change (seat modal, modality)
            return existing;
        }
        root.innerHTML = `
            <div class="relative">
                <input type="text" class="picker-input w-full px-4 py-3 rounded-xl border-2 border-gray-100 outline-none focus:border-hodBlue focus:ring-4 focus:ring-blue-50 transition-all bg-white text-gray-900 font-semibold placeholder-gray-300 text-sm" placeholder="${esc(placeholder || 'Search...')}" autocomplete="off">
                <input type="hidden" name="${esc(key)}" class="picker-hidden">
                <button type="button" class="picker-clear absolute right-3 top-1/2 -translate-y-1/2 text-gray-300 hover:text-red-500 hidden" title="Clear">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
                <div class="picker-list hidden absolute z-[10001] left-0 right-0 mt-1 max-h-64 overflow-y-auto custom-scrollbar bg-white border border-gray-200 rounded-xl shadow-2xl"></div>
            </div>`;

        const input = root.querySelector('.picker-input');
        const hidden = root.querySelector('.picker-hidden');
        const list = root.querySelector('.picker-list');
        const clear = root.querySelector('.picker-clear');

        const render = (query) => {
            const q = String(query || '').toLowerCase().trim();
            const matches = options.filter(o => !q || o.label.toLowerCase().includes(q) || String(o.meta || '').toLowerCase().includes(q)).slice(0, 60);
            list.innerHTML = matches.length
                ? matches.map(o => `<button type="button" data-value="${esc(o.value)}" class="w-full text-left px-4 py-2.5 hover:bg-blue-50 transition-colors text-sm font-semibold text-gray-800 border-b border-gray-50 last:border-0">${esc(o.label)}${o.meta ? `<span class="block text-[10px] font-medium text-gray-400 mt-0.5">${esc(o.meta)}</span>` : ''}</button>`).join('')
                : '<p class="px-4 py-3 text-xs text-gray-400 italic">No match. Check the spelling, or the person may already be on this roster.</p>';
            list.querySelectorAll('button[data-value]').forEach(b => {
                b.addEventListener('click', () => {
                    const opt = options.find(o => String(o.value) === b.dataset.value);
                    hidden.value = b.dataset.value;
                    input.value = opt ? opt.label : '';
                    list.classList.add('hidden');
                    clear.classList.remove('hidden');
                    if (onChange) onChange(hidden.value, opt);
                });
            });
        };

        input.addEventListener('focus', () => { render(input.value); list.classList.remove('hidden'); });
        input.addEventListener('input', () => { hidden.value = ''; clear.classList.toggle('hidden', !input.value); render(input.value); list.classList.remove('hidden'); });
        clear.addEventListener('click', () => pickerReset(root));
        document.addEventListener('click', (e) => { if (!root.contains(e.target)) list.classList.add('hidden'); });

        pickers.set(key, { root, input, hidden, list, clear, options });
        return pickers.get(key);
    }

    function pickerSet(key, value, label) {
        const p = pickers.get(key);
        if (!p) return;
        p.hidden.value = value || '';
        p.input.value = label || '';
        p.clear.classList.toggle('hidden', !value);
    }

    function pickerReset(root) {
        const p = pickers.get(root.dataset ? root.dataset.picker : root);
        if (!p) return;
        p.hidden.value = '';
        p.input.value = '';
        p.clear.classList.add('hidden');
        p.list.classList.add('hidden');
    }

    function pickerOptionsFrom(people, extraMeta) {
        return people.map(p => ({
            value: String(p.id),
            label: (p.first_name + ' ' + p.last_name).trim(),
            meta: extraMeta ? extraMeta(p) : (p.gender ? p.gender : '')
        }));
    }

    // ============================================================
    // Dashboard
    // ============================================================
    function loadDashboard(yearId, done) {
        $('#loadingOverlay').removeClass('hidden');
        $('#departmentsGrid').addClass('hidden');
        $('#statStrip').addClass('hidden');

        const payload = { action: 'fetch_dashboard' };
        if (yearId) payload.ministry_year_id = yearId;

        $.getJSON(API_URL, payload, function (res) {
            $('#loadingOverlay').addClass('hidden');
            if (res.status !== 'success') {
                showToast(res.message, 'error');
                return;
            }

            state.yearId = res.year.id;
            state.year = res.year;
            state.years = res.ministry_years;
            state.activeYearId = res.active_year_id;
            state.permissions = res.permissions || {};
            state.allDepts = res.all_depts;
            state.liveHeadcount = (res.permissions && res.permissions.live_headcount) || 0;
            state.people = { pastors: res.pastors, directors: res.directors, workers: res.all_workers };

            renderYearSwitcher();
            renderStatStrip(res.master_departments);
            renderDepartmentCards(res.master_departments);
            applyPermissions();

            // Keep the create/edit selectors stocked.
            const pw = $('#formParentId');
            let opts = '<option value="">-- Set as Master Department --</option>';
            res.all_depts.forEach(d => { opts += `<option value="${d.id}">${esc(d.name)}</option>`; });
            pw.html(opts);

            pickerInit($('[data-picker="assoc_pastor_id"]').get(0), pickerOptionsFrom(res.pastors), 'Search for a pastor…');
            pickerInit($('[data-picker="director_id"]').get(0), pickerOptionsFrom(res.directors), 'Search for a director…');
            pickerInit($('[data-picker="hod_id"]').get(0), pickerOptionsFrom(res.all_workers, p => 'Worker · ' + (p.gender || '')), 'Search for a person…');
            if (typeof done === 'function') done(res);
        }).fail(function () {
            $('#loadingOverlay').addClass('hidden');
            showToast('Server connection error.', 'error');
        });
    }

    function renderYearSwitcher() {
        let html = '';
        state.years.forEach(y => {
            const badge = Number(y.is_active) === 1 ? '  •  live' : '  •  archived';
            html += `<option value="${y.id}" ${String(y.id) === String(state.yearId) ? 'selected' : ''}>${esc('Ministry Year ' + y.label)}${badge} (${y.people})</option>`;
        });
        $('#yearSelect').html(html).off('change').on('change', function () {
            state.currentDeptId = null;
            $('#view-details').addClass('hidden');
            $('#view-dashboard').removeClass('hidden');
            loadDashboard($(this).val());
        });
    }

    function renderStatStrip(depts) {
        let people = 0, primary = 0, secondary = 0;
        depts.forEach(d => { primary += d.primary_count; secondary += d.secondary_count; });
        const stats = [
            ['Departments', depts.length, 'Units & arms', 'text-hodBlue bg-blue-50'],
            ['Primary members', primary, 'Core commitment', 'text-hodRed bg-red-50'],
            ['Secondary members', secondary, 'Serving occasionally', 'text-gray-700 bg-gray-100'],
            ['Total placements', primary + secondary, 'People: ' + (state.liveHeadcount || 0) + ' serving now', 'text-purple-700 bg-purple-50']
        ];
        $('#statStrip').html(stats.map(([label, value, sub, cls]) => `
            <div class="bg-white border border-gray-100 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">${esc(label)}</p>
                    <span class="w-8 h-8 rounded-lg ${cls} flex items-center justify-center text-xs font-bold">${value}</span>
                </div>
                <p class="text-3xl font-display font-bold text-gray-900 mt-2">${value}</p>
                <p class="text-[11px] text-gray-400 mt-1">${esc(sub)}</p>
            </div>`).join('')).removeClass('hidden');
    }

    function renderDepartmentCards(depts) {
        if (!depts.length) {
            $('#departmentsGrid').html('<div class="col-span-full py-14 text-center text-gray-500 bg-white rounded-3xl border border-dashed border-gray-200">No departments configured yet.</div>').removeClass('hidden');
            return;
        }

        const seatLine = (d) => {
            const parts = [];
            if (d.leaders.Assoc_Pastor) parts.push('Pastor: ' + d.leaders.Assoc_Pastor.first_name + ' ' + d.leaders.Assoc_Pastor.last_name);
            if (d.leaders.Director) parts.push('Director: ' + d.leaders.Director.first_name + ' ' + d.leaders.Director.last_name);
            if (d.leaders.HOD) parts.push('HOD: ' + d.leaders.HOD.first_name + ' ' + d.leaders.HOD.last_name);
            return parts.length ? parts.join('  ·  ') : 'No leadership appointed yet';
        };

        const html = depts.map(d => `
            <div onclick="viewDepartment(${d.id})" class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow cursor-pointer relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-2 h-full ${d.primary_count ? 'bg-hodBlue' : 'bg-gray-200'} opacity-20 group-hover:opacity-100 transition-opacity"></div>
                <div class="flex justify-between items-start mb-4">
                    <div class="pr-3">
                        <h3 class="text-xl font-bold text-gray-900">${esc(d.name)}</h3>
                        <p class="text-xs text-gray-500 mt-1 uppercase tracking-wider font-bold">${esc(String(d.type).replace(/_/g, ' '))}</p>
                    </div>
                    ${d.target_demographic !== 'All' ? `<span class="bg-red-50 text-red-600 px-2 py-1 rounded text-[10px] font-bold uppercase whitespace-nowrap">${esc(String(d.target_demographic).replace(/_/g, ' '))}</span>` : ''}
                </div>
                <p class="text-sm text-gray-600 line-clamp-2 mb-4 h-10">${esc(d.description || 'No description provided.')}</p>
                <p class="text-[11px] font-semibold text-gray-500 mb-4 truncate" title="${esc(seatLine(d))}">${esc(seatLine(d))}</p>
                <div class="flex flex-wrap items-center gap-3 text-xs font-bold border-t border-gray-50 pt-4">
                    <span class="flex items-center gap-1 text-hodRed bg-red-50 px-2.5 py-1 rounded-lg">${d.primary_count} Primary</span>
                    <span class="flex items-center gap-1 text-gray-600 bg-gray-100 px-2.5 py-1 rounded-lg">${d.secondary_count} Secondary</span>
                    ${(d.sub_units && d.sub_units.length) ? `<span class="flex items-center gap-1 text-purple-600 bg-purple-50 px-2.5 py-1 rounded-lg">${d.sub_units.length} Sub-unit${d.sub_units.length > 1 ? 's' : ''}</span>` : ''}
                </div>
            </div>`).join('');

        $('#departmentsGrid').html(html).removeClass('hidden');
    }

    function applyPermissions() {
        const p = state.permissions || {};
        const archived = !!(state.year && state.year.archived);

        $('#btnNewDept').toggleClass('hidden', !p.can_manage_all || archived);
        $('#btnRollover').toggleClass('hidden', !p.can_rollover);
        $('#btnManageYears').toggleClass('hidden', !p.can_edit_year);
        $('#archiveBanner').toggleClass('hidden', !archived);
        if (archived) {
            $('#archiveBannerTitle').text(('Viewing Ministry Year ' + state.year.label + ' (archived) — read-only'));
        }
    }

    function jumpToLiveYear() {
        state.currentDeptId = null;
        $('#view-details').addClass('hidden');
        $('#view-dashboard').removeClass('hidden');
        loadDashboard(state.activeYearId);
    }

    function showDashboard() {
        state.currentDeptId = null;
        $('#view-details').addClass('hidden').removeClass('animate-fade-in-up');
        $('#view-dashboard').removeClass('hidden').addClass('animate-fade-in-up');
        loadDashboard(state.yearId);
    }

    // ============================================================
    // Department details
    // ============================================================
    function viewDepartment(id, yearId) {
        state.currentDeptId = id;
        const useYear = yearId || state.yearId;
        lockScreenAction('Loading roster...');

        $.post(API_URL, { action: 'fetch_department_details', department_id: id, ministry_year_id: useYear })
            .done(function (res) {
                unlockScreenAction();
                if (res.status !== 'success') {
                    showToast(res.message, 'error');
                    return;
                }
                renderDepartmentDetail(res);
            })
            .fail(function () {
                unlockScreenAction();
                showToast('Server connection error.', 'error');
            });
    }

    function renderDepartmentDetail(res) {
        const info = res.info;
        state.currentDept = info;
        state.deptActiveUserIds = res.active_user_ids || [];

        $('#view-dashboard').addClass('hidden').removeClass('animate-fade-in-up');
        $('#view-details').removeClass('hidden').addClass('animate-fade-in-up');

        $('#detailDeptName').text(info.name);
        $('#detailDeptDesc').text(info.description || 'No description provided.');
        $('#detailDeptBadges').html(`
            <span class="bg-blue-50 text-hodBlue px-3 py-1 rounded-full text-xs font-bold uppercase">${esc(String(info.type).replace(/_/g, ' '))}</span>
            <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-full text-xs font-bold uppercase border border-gray-200">${esc(String(info.target_demographic).replace(/_/g, ' '))}</span>
            <span class="bg-white text-gray-500 px-3 py-1 rounded-full text-xs font-bold uppercase border border-gray-200">${esc(res.year.heading)}</span>
            ${res.year.archived ? '<span class="bg-amber-50 text-amber-700 px-3 py-1 rounded-full text-xs font-bold uppercase border border-amber-200">Archived — read only</span>' : ''}
        `);

        // Leadership seats, each with a one-click change.
        const seats = [
            ['Assoc_Pastor', 'Pastor in Charge', info.ap_fname, 'blue'],
            ['Director', 'Director in Charge', info.dir_fname, 'purple'],
            ['HOD', 'Head of Department', info.hod_fname, 'yellow']
        ];
        $('#seatGrid').html(seats.map(([role, label, fname, tone]) => {
            const id = role === 'Assoc_Pastor' ? info.ap_id : (role === 'Director' ? info.dir_id : info.hod_id);
            const name = fname ? `${fname} ${role === 'Assoc_Pastor' ? info.ap_lname : (role === 'Director' ? info.dir_lname : info.hod_lname)}` : 'Vacant';
            const canChange = res.permissions.can_manage && !(role === 'Assoc_Pastor' && !res.permissions.is_rank);
            return `
            <div class="bg-${tone}-50/50 border border-${tone}-100 rounded-2xl p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">${esc(label)}</p>
                        <p class="font-bold text-gray-900 text-lg mt-1">${esc(name)}</p>
                    </div>
                    ${canChange ? `<button onclick="openSeatModal('${role}')" class="text-[11px] font-bold bg-white border border-gray-200 text-gray-600 hover:text-hodBlue hover:border-blue-200 px-3 py-1.5 rounded-lg shadow-sm transition-colors whitespace-nowrap">Change</button>` : ''}
                </div>
            </div>`;
        }).join(''));

        // Sub-units
        $('#subUnitsGrid').html(res.sub_units.length ? res.sub_units.map(s => `
            <div class="flex justify-between items-center p-4 bg-gray-50 rounded-2xl border border-gray-100">
                <div>
                    <h4 class="font-bold text-gray-900">${esc(s.name)}</h4>
                    <p class="text-xs text-gray-500">${s.active_members} active</p>
                </div>
                <button onclick="event.stopPropagation(); viewDepartment(${s.id})" class="text-xs bg-white border border-gray-200 text-hodBlue font-bold px-3 py-1.5 rounded-lg shadow-sm">Manage</button>
            </div>`).join('') : '<div class="col-span-full text-sm text-gray-500 italic">No sub-units defined.</div>');

        // Roster tables. The dashboard card rolls sub-units into the department
        // total, so say how much of it sits in the sub-units before the tables.
        const subCounts = res.sub_unit_counts || { primary: 0, secondary: 0 };
        const note = (n) => n ? ` <span class="font-medium normal-case tracking-normal text-gray-400">+ ${n} in sub-units</span>` : '';
        $('#primaryCount').html(res.primary.length + note(subCounts.primary));
        $('#secondaryCount').html(res.secondary.length + note(subCounts.secondary));
        $('#primaryTableBody').html(rosterRows(res.primary, 'primary', res.permissions.can_manage));
        $('#secondaryTableBody').html(rosterRows(res.secondary, 'secondary', res.permissions.can_manage));

        const editable = res.permissions.can_manage;
        $('#btnEditDept').toggleClass('hidden', !editable);
        $('#btnAssign').toggleClass('hidden', !editable);
        $('#assignDeptLabel').text('Adding to: ' + info.name + '  ·  ' + res.year.heading);
    }

    function rosterRows(list, membership, canManage) {
        if (!list.length) {
            return `<tr><td colspan="5" class="text-center py-8 text-gray-400 text-sm italic">Nobody here yet for this ministry year.</td></tr>`;
        }
        return list.map(r => {
            const other = membership === 'primary' ? 'Secondary' : 'Primary';
            const otherTone = membership === 'primary' ? 'gray' : 'red';
            const head = r.role_in_dept === 'Sub_Unit_Head';
            return `
            <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors">
                <td class="px-6 py-4">
                    <div class="font-bold text-gray-900">${esc(r.first_name)} ${esc(r.last_name)}</div>
                    <span class="text-[10px] text-gray-400 font-medium">${esc(r.gender || '—')}</span>
                </td>
                <td class="px-6 py-4">
                    ${head
                        ? '<span class="text-[10px] font-bold bg-red-50 text-hodRed px-2 py-1 rounded">Sub-Unit Head</span>'
                        : '<span class="text-[10px] font-bold text-gray-500">Member</span>'}
                </td>
                <td class="px-6 py-4 text-xs text-gray-500">${esc(r.phone || '—')}</td>
                <td class="px-6 py-4 text-xs text-gray-500">${fmtDate(r.joined_at)}</td>
                <td class="px-6 py-4 text-right whitespace-nowrap">
                    ${canManage ? `
                        <button onclick="switchMembership(${r.record_id}, '${other}')" class="text-[11px] bg-${otherTone}-50 text-${otherTone === 'red' ? 'hodRed' : 'gray-600'} hover:bg-${otherTone === 'red' ? 'hodRed' : 'gray-600'} hover:text-white px-3 py-1.5 rounded font-bold transition-colors border border-${otherTone === 'red' ? 'red-100' : 'gray-200'}">Move to ${other}</button>
                        <button onclick="removeWorker(${r.record_id})" class="text-[11px] text-gray-400 hover:text-red-600 px-2 py-1.5 font-bold transition-colors">Remove</button>
                    ` : '<span class="text-[10px] text-gray-300 font-bold uppercase">Read only</span>'}
                </td>
            </tr>`;
        }).join('');
    }

    // ============================================================
    // Membership moves, removals, seat changes
    // ============================================================
    function switchMembership(recordId, target) {
        lockScreenAction('Moving to ' + target + '...');
        $.post(API_URL, { action: 'switch_membership', record_id: recordId, membership_type: target })
            .done(function (res) {
                unlockScreenAction();
                showToast(res.message, res.status);
                if (res.status === 'success') {
                    viewDepartment(state.currentDeptId, state.yearId);
                }
            })
            .fail(function () { unlockScreenAction(); showToast('Server connection error.', 'error'); });
    }

    function removeWorker(recordId) {
        if (!confirm('Remove this person from the department?\n\nThey disappear from the roster and from downloads. Their record is kept, and you can add them back at any time.')) return;
        lockScreenAction('Removing...');
        $.post(API_URL, { action: 'remove_worker', record_id: recordId })
            .done(function (res) {
                unlockScreenAction();
                showToast(res.message, res.status);
                if (res.status === 'success') {
                    viewDepartment(state.currentDeptId, state.yearId);
                }
            })
            .fail(function () { unlockScreenAction(); showToast('Server connection error.', 'error'); });
    }

    function openSeatModal(role) {
        const info = state.currentDept;
        if (!info) return;
        const map = {
            Assoc_Pastor: { id: info.ap_id, name: info.ap_fname ? info.ap_fname + ' ' + info.ap_lname : null },
            Director: { id: info.dir_id, name: info.dir_fname ? info.dir_fname + ' ' + info.dir_lname : null },
            HOD: { id: info.hod_id, name: info.hod_fname ? info.hod_fname + ' ' + info.hod_lname : null }
        };
        const current = map[role];
        $('#seatRole').val(role);
        $('#seatModalTitle').text('Change ' + roleLabel(role));
        $('#seatCurrent').html(current.name
            ? `Currently: <span class="font-bold text-gray-900">${esc(current.name)}</span><br><span class="text-xs text-gray-500">They will be relieved of the role and taken off the department roster — you can add them back as a member afterwards.</span>`
            : 'This seat is currently vacant.');

        const pool = role === 'Assoc_Pastor' ? state.people.pastors
                   : (role === 'Director' ? state.people.directors.concat(state.people.pastors) : state.people.workers);
        pickerInit($('[data-picker="seat_user_id"]').get(0), pickerOptionsFrom(pool, p => p.gender || ''), 'Search for a person…');
        pickerReset('seat_user_id');
        $('#seatVacate').prop('checked', false);
        openModal('seatModal');
    }

    function saveSeat() {
        const role = $('#seatRole').val();
        const vacate = $('#seatVacate').is(':checked');
        const userId = vacate ? '' : ($('[data-picker="seat_user_id"] .picker-hidden').val() || '');

        if (!vacate && !userId) {
            showToast('Pick the person who should take the seat, or tick the vacant box.', 'warning');
            return;
        }

        closeModal('seatModal');
        lockScreenAction('Applying leadership change...');
        $.post(API_URL, { action: 'set_leader', department_id: state.currentDeptId, role: role, user_id: userId })
            .done(function (res) {
                unlockScreenAction();
                showToast(res.message, res.status);
                if (res.status === 'success') {
                    viewDepartment(state.currentDeptId, state.yearId);
                }
            })
            .fail(function () { unlockScreenAction(); showToast('Server connection error.', 'error'); });
    }

    // ============================================================
    // Create / edit department
    // ============================================================
    function openCreateModal() {
        $('#deptForm')[0].reset();
        ['assoc_pastor_id', 'director_id', 'hod_id'].forEach(k => pickerReset(k));
        $('#formDeptId').val('');
        $('#formYearId').val(state.activeYearId);
        $('#createDeptModalTitle').text('Create New Department');
        openModal('createDeptModal');
    }

    function editDepartment() {
        const info = state.currentDept;
        if (!info) return;
        $('#formDeptId').val(info.id);
        $('#formYearId').val(state.yearId);
        $('#deptForm input[name="name"]').val(info.name);
        $('#deptForm textarea[name="description"]').val(info.description || '');
        $('#deptForm select[name="type"]').val(info.type);
        $('#deptForm select[name="target_demographic"]').val(info.target_demographic);
        $('#formParentId').val(info.parent_id || '');

        const label = (id, fname, lname) => id ? `${fname || ''} ${lname || ''}`.trim() : '';
        pickerSet('assoc_pastor_id', info.ap_id || '', label(info.ap_id, info.ap_fname, info.ap_lname));
        pickerSet('director_id', info.dir_id || '', label(info.dir_id, info.dir_fname, info.dir_lname));
        pickerSet('hod_id', info.hod_id || '', label(info.hod_id, info.hod_fname, info.hod_lname));

        $('#wrapAssocPastor').toggleClass('hidden', !(state.permissions.can_manage_all || state.permissions.is_rank));
        $('#createDeptModalTitle').text('Edit Department Settings');
        openModal('createDeptModal');
    }

    function openAssignModal() {
        const hidden = state.deptActiveUserIds.map(String);
        const pool = state.people.workers.filter(p => !hidden.includes(String(p.id)));
        pickerInit($('#assignPickerWrap').get(0), pickerOptionsFrom(pool, p => p.gender || ''), 'Type a name to search…');
        pickerReset('user_id');
        $('#assignPoolHint').text(hidden.length
            ? hidden.length + ' person(s) already on this roster are hidden from the list. Anyone removed earlier can be added straight back here.'
            : 'Every worker and member is available.');
        openModal('assignWorkerModal');
    }

    // ============================================================
    // Downloads
    // ============================================================
    function openDownloadModal(deptId) {
        $('#downloadYearLabel').text((state.year ? state.year.heading + '  ·  ' + state.year.period : ''));
        $('#downloadSummary').html('Preparing the roster…');
        openModal('downloadModal');

        $.post(API_URL, { action: 'fetch_export_dataset', ministry_year_id: state.yearId })
            .done(function (res) {
                if (res.status !== 'success') {
                    $('#downloadSummary').html(`<span class="text-red-600 font-semibold">${esc(res.message)}</span>`);
                    return;
                }
                state.exportData = res.data;
                const t = res.data.totals;
                const scope = deptId
                    ? 'The A4 image always shows every department, as the year-end sheet.'
                    : '';
                $('#downloadSummary').html(`
                    <p class="font-bold text-gray-800">${esc(res.data.year.heading)} <span class="font-medium text-gray-500">· ${esc(res.data.year.period)}</span></p>
                    <p class="mt-1">${t.departments} departments · <span class="font-bold text-hodRed">${t.primary} primary</span> · <span class="font-bold text-gray-600">${t.secondary} secondary</span> · ${t.people} people in total.</p>
                    ${scope ? `<p class="mt-1 text-[11px] text-gray-400">${esc(scope)}</p>` : ''}`);
            })
            .fail(function () {
                $('#downloadSummary').html('<span class="text-red-600 font-semibold">Could not load the roster. Please try again.</span>');
            });
    }

    function downloadFile(format) {
        window.location = `${EXPORT_URL}?format=${format}&ministry_year_id=${state.yearId}`;
        showToast('Your ' + (format === 'csv' ? 'CSV' : 'Excel') + ' download has started.', 'success');
    }

    function downloadImage() {
        if (!state.exportData) {
            showToast('The roster is still loading — one moment.', 'warning');
            return;
        }
        closeModal('downloadModal');
        lockScreenAction('Drawing the A4 sheet...');

        window.HODDepartmentExport.render(state.exportData)
            .then(function (result) {
                unlockScreenAction();
                return window.HODDepartmentExport.download(result.canvas, result.filename).then(() => result);
            })
            .then(function (result) {
                const note = result.overflows
                    ? 'Very large roster — the sheet is squeezed to stay on one page.'
                    : `${result.nameCount} names on one A4 page (${result.densityLabel.toLowerCase()} layout).`;
                showToast('Downloaded ' + result.filename + '. ' + note, result.overflows ? 'warning' : 'success');
            })
            .catch(function (err) {
                unlockScreenAction();
                console.error(err);
                showToast('Could not draw the image: ' + err.message, 'error');
            });
    }

    // ============================================================
    // Ministry years
    // ============================================================
    function openYearsModal() {
        renderYearsList();
        $('#yearFormId').val('');
        $('#yearForm')[0].reset();
        $('#yearFormActive').prop('checked', false);
        openModal('yearsModal');
    }

    function renderYearsList() {
        $('#yearsList').html(state.years.map(y => `
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border border-gray-100 rounded-2xl p-4 ${Number(y.is_active) === 1 ? 'bg-blue-50/40 border-blue-100' : 'bg-white'}">
                <div>
                    <p class="font-bold text-gray-900">Ministry Year ${esc(y.label)}
                        ${Number(y.is_active) === 1 ? '<span class="ml-2 text-[10px] font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded-full uppercase">Live</span>' : '<span class="ml-2 text-[10px] font-bold bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full uppercase">Archived</span>'}
                    </p>
                    <p class="text-xs text-gray-500 mt-1">${esc(y.period)} · ${y.people} people on roster</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="editYear(${y.id})" class="text-xs font-bold bg-white border border-gray-200 px-3 py-2 rounded-lg text-gray-600 hover:border-hodBlue hover:text-hodBlue transition-colors">Edit</button>
                    ${Number(y.is_active) !== 1 ? `<button onclick="activateYear(${y.id})" class="text-xs font-bold bg-white border border-gray-200 px-3 py-2 rounded-lg text-gray-600 hover:border-green-500 hover:text-green-600 transition-colors">Make live</button>` : ''}
                </div>
            </div>`).join('') || '<p class="text-sm text-gray-400 italic">No ministry years yet.</p>');
    }

    function editYear(id) {
        const y = state.years.find(x => String(x.id) === String(id));
        if (!y) return;
        $('#yearFormId').val(y.id);
        $('#yearFormLabel').val(y.label);
        $('#yearFormStart').val(y.start_date);
        $('#yearFormEnd').val(y.end_date);
        $('#yearFormActive').prop('checked', Number(y.is_active) === 1);
    }

    function activateYear(id) {
        if (!confirm('Make this ministry year the live one?\n\nThe year that is live today will be archived (read-only). Rosters stay attached to their own year.')) return;
        const y = state.years.find(x => String(x.id) === String(id));
        lockScreenAction('Switching the live year...');
        $.post(API_URL, { action: 'save_ministry_year', id: y.id, label: y.label, start_date: y.start_date, end_date: y.end_date, is_active: 1 })
            .done(function (res) {
                unlockScreenAction();
                showToast(res.message, res.status);
                if (res.status === 'success') { closeModal('yearsModal'); loadDashboard(); }
            })
            .fail(function () { unlockScreenAction(); showToast('Server connection error.', 'error'); });
    }

    // ============================================================
    // Ministry-year rollover worksheet
    // ============================================================
    function openRolloverWizard() {
        state.rollover = null;
        $('#rolloverBody').html('<div class="py-16 text-center text-gray-400 text-sm">Loading the current roster…</div>');
        $('#rolloverSummary').text('');
        openModal('rolloverModal');

        $.post(API_URL, { action: 'fetch_rollover_worksheet' })
            .done(function (res) {
                if (res.status !== 'success') {
                    showToast(res.message, 'error');
                    closeModal('rolloverModal');
                    return;
                }
                state.rollover = {
                    from: res.from_year,
                    departments: res.departments,
                    people: res.people,
                    candidates: res.candidates,
                    worksheet: {}
                };
                $('#roLabel').val(res.suggested.label);
                $('#roStart').val(res.suggested.start_date);
                $('#roEnd').val(res.suggested.end_date);
                $('#rolloverFromLabel').text(`Reshuffling from ${res.from_year.heading} (${res.from_year.period}). Pick the new period, arrange each department, then go live.`);
                applyPreset('members');
            })
            .fail(function () {
                showToast('Server connection error.', 'error');
                closeModal('rolloverModal');
            });
    }

    function applyPreset(mode) {
        if (!state.rollover) return;
        const { departments, people, candidates } = state.rollover;
        const byId = {};
        candidates.forEach(c => { byId[c.id] = c; });

        const ws = {};
        departments.forEach(d => {
            const entry = { seats: { Assoc_Pastor: '', Director: '', HOD: '' }, members: [], subUnits: {} };

            if (mode !== 'empty') {
                Object.keys(entry.seats).forEach(role => {
                    const seat = d.leaders ? d.leaders[role] : null;
                    entry.seats[role] = seat ? String(seat.user_id) : '';
                });
            }
            if (mode === 'members') {
                // Keep the seats empty for the new year: leadership is re-appointed.
                entry.seats = { Assoc_Pastor: '', Director: '', HOD: '' };
            }

            if (mode !== 'empty') {
                Object.keys(people).forEach(userId => {
                    (people[userId] || []).forEach(row => {
                        if (String(row.department_id) !== String(d.id)) return;
                        if (['Assoc_Pastor', 'Director', 'HOD'].includes(row.role_in_dept)) return;
                        entry.members.push({ user_id: String(userId), membership: row.membership_type });
                    });
                });
                entry.members.sort((a, b) => nameOf(byId[a.user_id]).localeCompare(nameOf(byId[b.user_id])));
            }
            ws[d.id] = entry;
        });

        state.rollover.worksheet = ws;
        renderWorksheet();
    }

    function nameOf(c) {
        return c ? ((c.first_name || '') + ' ' + (c.last_name || '')).trim() : 'Unknown';
    }

    function renderWorksheet() {
        const { departments, worksheet, candidates } = state.rollover;
        const candidateOptions = candidates.map(c => ({
            value: String(c.id),
            label: nameOf(c),
            meta: (c.spiritual_status || '') + (c.gender ? ' · ' + c.gender : '')
        }));

        const blocks = departments.filter(d => !d.parent_id).map(d => {
            const entry = worksheet[d.id];
            const subs = departments.filter(s => String(s.parent_id) === String(d.id));
            return `
            <div class="border border-gray-100 rounded-2xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-gray-100">
                    <div>
                        <p class="font-bold text-gray-900">${esc(d.name)}</p>
                        <p class="text-[11px] text-gray-500">${esc(String(d.type).replace(/_/g, ' '))}${subs.length ? ' · ' + subs.length + ' sub-unit(s)' : ''}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold text-gray-500">${countMembers(entry)} placed</span>
                        <button type="button" onclick="wsClearDept(${d.id})" class="text-[11px] font-bold bg-white border border-gray-200 px-2.5 py-1.5 rounded-lg text-gray-500 hover:text-red-600 transition-colors">Clear</button>
                    </div>
                </div>
                <div class="p-4 space-y-4">
                    ${seatPickers(d.id, entry, candidateOptions, 'main')}
                    ${memberChips(d.id, entry, candidateOptions, 'main')}
                    ${subs.map(s => subUnitBlock(s, worksheet[s.id], candidateOptions)).join('')}
                </div>
            </div>`;
        }).join('');

        $('#rolloverBody').html(blocks || '<p class="text-sm text-gray-400 italic">No departments.</p>');
        wireWorksheetPickers(candidateOptions);
        updateRolloverSummary();
    }

    function subUnitBlock(sub, entry, candidateOptions) {
        return `
        <div class="border border-gray-100 rounded-xl bg-gray-50/40 p-3 space-y-3">
            <p class="text-sm font-bold text-gray-700">↳ ${esc(sub.name)} <span class="text-[10px] font-medium text-gray-400 uppercase">sub-unit</span></p>
            ${seatPickers(sub.id, entry, candidateOptions, 'sub')}
            ${memberChips(sub.id, entry, candidateOptions, 'sub')}
        </div>`;
    }

    function seatPickers(deptId, entry, candidateOptions, scope) {
        return `
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            ${[['Assoc_Pastor', 'Pastor in Charge'], ['Director', 'Director in Charge'], ['HOD', 'Head of Department']].map(([role, label]) => `
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">${esc(label)}</label>
                    <select data-seat="${role}" data-dept="${deptId}" class="ws-seat w-full px-3 py-2 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-xs font-semibold bg-white">
                        <option value="">— Vacant —</option>
                        ${candidateOptions.map(o => `<option value="${esc(o.value)}" ${String(entry.seats[role]) === String(o.value) ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}
                    </select>
                </div>`).join('')}
        </div>`;
    }

    function memberChips(deptId, entry, candidateOptions, scope) {
        const used = entry.members.map(m => String(m.user_id));
        return `
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-[10px] font-bold text-gray-500 uppercase">Members (${entry.members.length})</label>
                <div class="flex items-center gap-2">
                    <div class="dept-picker w-52 ws-add-picker" data-picker="wsadd_${deptId}" data-placeholder="+ Add a person…"></div>
                    <select class="ws-add-type px-2 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold bg-white">
                        <option value="Primary">Primary</option>
                        <option value="Secondary">Secondary</option>
                    </select>
                    <button type="button" class="ws-add-btn text-[11px] font-bold bg-hodBlue hover:bg-[#152750] text-white px-3 py-2 rounded-lg transition-colors" data-dept="${deptId}">Add</button>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 ${entry.members.length ? '' : 'text-xs text-gray-400 italic'}">
                ${entry.members.map(m => {
                    const c = state.rollover.candidates.find(x => String(x.id) === String(m.user_id));
                    const isPrimary = m.membership !== 'Secondary';
                    return `
                    <span class="inline-flex items-center gap-1.5 bg-white border ${isPrimary ? 'border-red-100' : 'border-gray-200'} rounded-full pl-2.5 pr-1 py-1 text-xs font-semibold text-gray-700">
                        <button type="button" onclick="wsToggleMembership('${deptId}', '${m.user_id}')" class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded-full ${isPrimary ? 'bg-red-50 text-hodRed' : 'bg-gray-100 text-gray-500'}" title="Click to switch Primary / Secondary">${isPrimary ? 'Primary' : 'Secondary'}</button>
                        ${esc(nameOf(c))}
                        <button type="button" onclick="wsRemoveMember('${deptId}', '${m.user_id}')" class="text-gray-300 hover:text-red-600 px-1" title="Take out">×</button>
                    </span>`;
                }).join('')}
            </div>
            <input type="hidden" class="ws-dept-id" value="${deptId}">
        </div>`;
    }

    function countMembers(entry) {
        return entry.members.length + Object.values(entry.seats).filter(Boolean).length;
    }

    function wsEntry(deptId) {
        return state.rollover.worksheet[deptId] || (state.rollover.worksheet[deptId] = { seats: { Assoc_Pastor: '', Director: '', HOD: '' }, members: [] });
    }

    function wsClearDept(deptId) {
        state.rollover.worksheet[deptId] = { seats: { Assoc_Pastor: '', Director: '', HOD: '' }, members: [] };
        renderWorksheet();
    }

    function wsToggleMembership(deptId, userId) {
        const entry = wsEntry(deptId);
        entry.members.forEach(m => {
            if (String(m.user_id) === String(userId)) {
                m.membership = m.membership === 'Secondary' ? 'Primary' : 'Secondary';
            }
        });
        renderWorksheet();
    }

    function wsRemoveMember(deptId, userId) {
        const entry = wsEntry(deptId);
        entry.members = entry.members.filter(m => String(m.user_id) !== String(userId));
        renderWorksheet();
    }

    function updateRolloverSummary() {
        const ws = state.rollover ? state.rollover.worksheet : {};
        let people = 0, primary = 0, secondary = 0, seats = 0;
        Object.values(ws).forEach(e => {
            people += e.members.length;
            e.members.forEach(m => { m.membership === 'Secondary' ? secondary++ : primary++; });
            seats += Object.values(e.seats).filter(Boolean).length;
        });
        $('#rolloverSummary').html(`<span class="font-bold text-gray-800">${people}</span> members placed (<span class="text-hodRed font-bold">${primary}</span> primary, <span class="font-bold">${secondary}</span> secondary) · <span class="font-bold text-gray-800">${seats}</span> leadership seats filled`);
    }

    // Worksheet events (delegated so re-renders stay wired).
    $(document).on('change', '.ws-seat', function () {
        const deptId = $(this).data('dept');
        const role = $(this).data('seat');
        wsEntry(deptId).seats[role] = $(this).val();
        updateRolloverSummary();
    });

    $(document).on('click', '.ws-add-btn', function () {
        const deptId = $(this).data('dept');
        const picker = pickers.get('wsadd_' + deptId);
        const type = $(this).siblings('.ws-add-type').val();
        const value = picker ? picker.hidden.value : '';
        if (!value) {
            showToast('Search for a person first, then press Add.', 'warning');
            return;
        }
        const entry = wsEntry(deptId);
        if (!entry.members.some(m => String(m.user_id) === String(value))) {
            entry.members.push({ user_id: String(value), membership: type });
        }
        renderWorksheet();
    });

    function wireWorksheetPickers(candidateOptions) {
        document.querySelectorAll('#rolloverBody .ws-add-picker').forEach(root => {
            pickerInit(root, candidateOptions, '+ Add a person…');
        });
    }

    function commitRollover() {
        if (!state.rollover) return;
        const label = $('#roLabel').val().trim();
        const start = $('#roStart').val();
        const end = $('#roEnd').val();
        if (!label || !start || !end) {
            showToast('Give the new ministry year a label and a period.', 'warning');
            return;
        }

        const assignments = [];
        Object.keys(state.rollover.worksheet).forEach(deptId => {
            const entry = state.rollover.worksheet[deptId];
            Object.keys(entry.seats).forEach(role => {
                if (entry.seats[role]) {
                    assignments.push({ user_id: entry.seats[role], department_id: deptId, role_in_dept: role, membership_type: 'Primary' });
                }
            });
            entry.members.forEach(m => {
                assignments.push({ user_id: m.user_id, department_id: deptId, role_in_dept: 'Member', membership_type: m.membership });
            });
        });

        if (!assignments.length && !confirm('No one is placed in the new year. Create it empty and fill the departments afterwards?')) {
            return;
        }
        if (!confirm(`Create Ministry Year ${label} (${start} → ${end}) and make it live?\n\nThe current year becomes archived and read-only.`)) {
            return;
        }

        lockScreenAction('Installing the new ministry year...');
        $.post(API_URL, {
            action: 'commit_rollover',
            label: label,
            start_date: start,
            end_date: end,
            assignments: JSON.stringify(assignments)
        })
            .done(function (res) {
                unlockScreenAction();
                if (res.status === 'success') {
                    closeModal('rolloverModal');
                    showToast(res.message, 'success');
                    loadDashboard(res.data.ministry_year_id);
                } else {
                    showToast(res.message, 'error');
                }
            })
            .fail(function () { unlockScreenAction(); showToast('Server connection error.', 'error'); });
    }

    // ============================================================
    // Forms
    // ============================================================
    function handleAjaxForm(formId) {
        document.getElementById(formId).addEventListener('submit', function (e) {
            e.preventDefault();
            const formEl = $(this);
            const btn = formEl.find('button[type="submit"]');
            const orig = btn.html();
            const modalId = formEl.closest('.fixed.inset-0').attr('id');
            const spinner = '<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

            btn.prop('disabled', true).css('opacity', 0.75).html(spinner + 'Processing...');
            lockScreenAction();

            $.post(API_URL, formEl.serialize())
                .done(function (res) {
                    btn.prop('disabled', false).css('opacity', 1).html(orig);
                    unlockScreenAction();
                    if (res.status === 'success' || res.status === 'warning') {
                        showToast(res.message, res.status);
                        if (modalId) closeModal(modalId);
                        // Deterministic refresh — this is the fix for "I have to
                        // reload the page before I can see the new member".
                        if (formId === 'deptForm') {
                            if (!$('#formDeptId').val()) {
                                loadDashboard(state.yearId);
                            } else {
                                viewDepartment(state.currentDeptId, state.yearId);
                            }
                        } else if (formId === 'assignWorkerForm') {
                            viewDepartment(state.currentDeptId, state.yearId);
                        } else if (formId === 'yearForm') {
                            loadDashboard(state.yearId, renderYearsList);
                        }
                    } else {
                        showToast(res.message, 'error');
                    }
                })
                .fail(function () {
                    btn.prop('disabled', false).css('opacity', 1).html(orig);
                    unlockScreenAction();
                    showToast('Server connection error.', 'error');
                });
        });
    }

    $(document).ready(function () {
        loadDashboard();
        handleAjaxForm('deptForm');
        handleAjaxForm('assignWorkerForm');
        handleAjaxForm('yearForm');
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
