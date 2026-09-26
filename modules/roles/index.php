<?php
// /modules/roles/index.php
require_once '../../includes/header.php'; 

// STRICT SECURITY CHECK: Frontend Gatekeeper
if (!isset($_SESSION['user_id']) || $_SESSION['active_role'] !== 'Super_Admin') {
    echo "<div class='min-h-screen flex items-center justify-center bg-gray-50'><div class='bg-white p-8 rounded-3xl shadow-xl text-center max-w-md border border-red-100'><svg class='w-16 h-16 text-red-500 mx-auto mb-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path></svg><h2 class='text-2xl font-black text-gray-900 mb-2'>SECURITY CLEARANCE REQUIRED</h2><p class='text-gray-500 mb-6'>This module is classified for Super Administrators only.</p><a href='/index.php' class='bg-gray-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-black transition-all inline-block'>Return to Dashboard</a></div></div>";
    require_once '../../includes/footer.php';
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-gray-900 p-6 md:p-8 rounded-3xl shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-red-500/20 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0 animate-pulse-slow"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-blue-500/20 rounded-full blur-3xl -ml-20 -mb-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-red-700 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-white tracking-tight">Identity & Access Management</h2>
                <p class="text-gray-400 text-sm md:text-base mt-1 font-medium">Classified Module: Global roles, custom titles, and audit logs.</p>
            </div>
        </div>
        
        <div class="relative z-10 flex bg-gray-800 p-1.5 rounded-2xl border border-gray-700 w-full md:w-auto shadow-inner">
            <button onclick="switchTab('tab-roster')" id="btn-tab-roster" class="flex-1 md:flex-none px-6 py-2.5 rounded-xl text-sm font-bold transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] bg-white text-gray-900 shadow-sm">
                Master Roster
            </button>
            <button onclick="switchTab('tab-audit')" id="btn-tab-audit" class="flex-1 md:flex-none px-6 py-2.5 rounded-xl text-sm font-bold transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] text-gray-400 hover:text-white">
                Audit Trail
            </button>
        </div>
    </div>

    <div id="tab-roster" class="space-y-6 animate-fade-in-up">
        <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm flex items-center">
            <div class="relative w-full">
                <input type="text" id="searchRoster" placeholder="Search users by name, email, or role..." 
                    class="w-full pl-11 pr-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-gray-900 focus:border-transparent outline-none transition-all bg-gray-50 hover:bg-white focus:bg-white font-medium">
                <svg class="w-5 h-5 text-gray-400 absolute left-4 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[500px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">User Profile</th>
                            <th class="px-6 py-4">Congregation Status</th>
                            <th class="px-6 py-4">System Roles & Tags</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rosterTableBody" class="divide-y divide-gray-50">
                        <tr><td colspan="4" class="text-center py-20 text-gray-400 font-bold animate-pulse">Fetching Master Roster...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="tab-audit" class="hidden space-y-6 animate-fade-in-up">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="p-6 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">System Modification Logs</h3>
                <span class="text-xs font-bold text-gray-500 uppercase tracking-widest">Last 100 Actions</span>
            </div>
            <div class="overflow-x-auto custom-scrollbar min-h-[500px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-white text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100 sticky top-0">
                        <tr>
                            <th class="px-6 py-4">Timestamp</th>
                            <th class="px-6 py-4">Performed By</th>
                            <th class="px-6 py-4">Action Taken</th>
                            <th class="px-6 py-4">Target User</th>
                            <th class="px-6 py-4">Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditTableBody" class="divide-y divide-gray-50">
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="manageUserModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] flex flex-col max-h-[90vh]">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 shrink-0">
            <div>
                <h3 class="text-xl font-display font-bold text-gray-900">Manage System Access</h3>
                <p id="manageUserName" class="text-sm font-bold text-hodBlue mt-0.5"></p>
            </div>
            <button onclick="closeModal('manageUserModal')" class="text-gray-400 hover:text-gray-900 transition-colors bg-white hover:bg-gray-100 p-2 rounded-full shadow-sm"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>

        <div class="overflow-y-auto custom-scrollbar flex-1 p-6 space-y-8 bg-white">
            
            <div>
                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Current Active Roles
                </h4>
                <div id="activeRolesContainer" class="space-y-3">
                    </div>
            </div>

            <div class="bg-gray-50 rounded-2xl p-5 border border-gray-100 shadow-inner">
                <h4 class="text-xs font-bold text-gray-900 uppercase tracking-widest mb-4">Grant New Privilege</h4>
                <form id="assignRoleForm" class="space-y-4">
                    <input type="hidden" name="action" value="assign_role">
                    <input type="hidden" name="user_id" id="assignRoleUserId">
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        <select name="role_id" id="roleSelector" required class="flex-1 px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 bg-white font-bold text-sm cursor-pointer shadow-sm">
                            <option value="">-- Select Role --</option>
                            </select>
                        <label class="flex items-center justify-center gap-2 px-4 py-3 bg-white border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-50 transition-colors shadow-sm">
                            <input type="checkbox" name="is_primary" value="1" class="w-4 h-4 text-gray-900 rounded border-gray-300 focus:ring-gray-900">
                            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Set Primary</span>
                        </label>
                    </div>
                    <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all duration-300 flex justify-center items-center gap-2">
                        Grant Access Role
                    </button>
                </form>
            </div>

            <div class="border-t border-gray-100 pt-6">
                <h4 class="text-xs font-bold text-red-500 uppercase tracking-widest mb-3">Security Controls (Danger Zone)</h4>
                <div class="bg-red-50/50 rounded-2xl p-5 border border-red-100 flex items-center justify-between">
                    <div>
                        <p class="font-bold text-red-900">Account Freeze</p>
                        <p class="text-xs text-red-700/70 mt-1">Suspend all dashboard access immediately without deleting history.</p>
                    </div>
                    <button id="btnToggleFreeze" onclick="toggleFreeze()" class="px-5 py-2.5 rounded-xl text-xs font-bold transition-all shadow-sm border"></button>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="customTitleModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-blue-50/30">
            <h3 class="text-lg font-bold text-gray-900">Add Display Title</h3>
            <button onclick="closeModal('customTitleModal')" class="text-gray-400 hover:text-gray-900"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="customTitleForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="assign_custom_title">
            <input type="hidden" name="user_id" id="titleUserId">
            <input type="hidden" name="role_id" id="titleRoleId">
            
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Custom Title Tag</label>
                <input type="text" name="custom_title" required placeholder="e.g., Head of Media Operations" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-gray-50 text-sm font-bold">
                <p class="text-[10px] text-gray-400 mt-2">This tag will display under their primary role for organizational clarity.</p>
            </div>
            
            <button type="submit" class="w-full bg-hodBlue hover:bg-blue-900 text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all flex justify-center items-center">
                Save Tag
            </button>
        </form>
    </div>
</div>

<div id="blockAlertModal" class="fixed inset-0 bg-gray-900/90 backdrop-blur-md hidden z-[120] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 ease-out">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-90 transition-transform duration-300 ease-out text-center p-8 border-t-8 border-red-500">
        <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-red-100">
            <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <h3 class="text-2xl font-black text-gray-900 mb-2 tracking-tight">Action Blocked</h3>
        <p id="blockAlertMessage" class="text-gray-500 text-sm mb-8 leading-relaxed font-medium"></p>
        
        <div class="space-y-3">
            <a id="blockAlertLink" href="#" class="w-full block bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Resolve Issue</a>
            <button onclick="closeModal('blockAlertModal')" class="w-full block text-gray-500 font-bold text-sm py-2 hover:text-gray-900 transition-colors">Dismiss</button>
        </div>
    </div>
</div>

<script>
    const API_URL = '/api/roles_api.php';
    let globalUsers = [];
    let globalRoles = [];
    let currentUser = null;

    // ==========================================
    // UI CORE: TABS & PERFECT MODALS
    // ==========================================
    function switchTab(tabId) {
        $('#tab-roster, #tab-audit').addClass('hidden').removeClass('animate-fade-in-up');
        $('#btn-tab-roster, #btn-tab-audit').removeClass('bg-white text-gray-900 shadow-sm').addClass('text-gray-400 hover:text-white');
        
        $(`#${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        $(`#btn-${tabId}`).removeClass('text-gray-400 hover:text-white').addClass('bg-white text-gray-900 shadow-sm');
        
        if(tabId === 'tab-audit') loadAuditLogs();
    }

    function openModal(id) {
        $('body').css('overflow', 'hidden'); // Lock body scroll instantly
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        // Slight delay to allow display:block to apply before triggering transition
        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            m.children[0].classList.remove('scale-95', 'scale-90');
        });
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95'); // Fallback scale class
        m.children[0].classList.add('scale-90'); // Block alert scale class
        
        setTimeout(() => { 
            m.classList.add('hidden'); 
            const form = m.querySelector('form'); 
            if(form) form.reset(); 
            // Only unlock body scroll if NO modals are currently visible
            if ($('.fixed.inset-0:not(.hidden)').length === 0) {
                $('body').css('overflow', '');
            }
        }, 300);
    }

    // Centered Toastify Wrapper
    function showToast(msg, type = 'success') {
        Toastify({
            text: msg,
            gravity: "top",
            position: "center",
            style: { 
                background: type === 'success' ? "#10B981" : (type === 'warning' ? "#F59E0B" : "#EF4444"),
                borderRadius: "12px",
                fontWeight: "bold",
                boxShadow: "0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)"
            }
        }).showToast();
    }

    // Ajax Form Handler with Spinner Lock
    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            const spinner = `<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-current inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
            
            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Processing...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
                
                if(res.status === 'blocked') {
                    // Trigger Priority Block Alert
                    $('#blockAlertMessage').text(res.message);
                    $('#blockAlertLink').attr('href', res.action_link).text(res.action_text);
                    openModal('blockAlertModal');
                } else if(res.status === 'success') {
                    showToast(res.message, 'success');
                    if(successCallback) successCallback(res);
                } else {
                    showToast(res.message, 'error');
                }
            }, 'json');
        });
    }

    // ==========================================
    // TAB 1: MASTER ROSTER LOGIC
    // ==========================================
    function loadRoster() {
        $.post(API_URL, { action: 'fetch_master_roster' }, function(res) {
            if(res.status === 'success') {
                globalUsers = res.users;
                globalRoles = res.roles;

                // Populate Dropdown
                let roleOpts = '<option value="">-- Select Role --</option>';
                globalRoles.forEach(r => roleOpts += `<option value="${r.id}">${r.role_name.replace('_', ' ')}</option>`);
                $('#roleSelector').html(roleOpts);

                // Populate Table
                let html = '';
                globalUsers.forEach(u => {
                    const isFrozen = u.account_frozen == 1;
                    const rowClass = isFrozen ? 'opacity-60 bg-red-50/20' : 'hover:bg-gray-50/50';
                    
                    // Parse Role Data String
                    let rolePills = '';
                    if (u.role_data) {
                        const roles = u.role_data.split('|');
                        roles.forEach(r => {
                            const parts = r.split(':');
                            const rName = parts[0].replace('_', ' ');
                            const isPrim = parts[1] === '1';
                            const title = parts[2] ? parts[2] : null;
                            
                            const pillColor = isPrim ? 'bg-gray-900 text-white border-gray-900 shadow-sm' : 'bg-gray-100 text-gray-700 border-gray-200';
                            const star = isPrim ? '<svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>' : '';
                            
                            rolePills += `
                            <div class="inline-flex flex-col items-start mb-2 mr-2">
                                <span class="flex items-center gap-1.5 px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${pillColor}">
                                    ${star} ${rName}
                                </span>
                                ${title ? `<span class="text-[9px] font-bold text-hodBlue mt-0.5 ml-1 italic">${title}</span>` : ''}
                            </div>`;
                        });
                    } else {
                        rolePills = '<span class="text-xs text-gray-400 italic">No system access</span>';
                    }

                    // Congregation Status formatting
                    const attBadge = u.attendance_status === 'Relocated' || u.attendance_status === 'Attends_Another_Church' 
                        ? `<span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-[9px] font-bold uppercase border border-red-200">${u.attendance_status.replace(/_/g, ' ')}</span>`
                        : `<span class="text-xs text-gray-500">${u.attendance_status.replace(/_/g, ' ')}</span>`;

                    html += `
                    <tr class="border-b border-gray-50 transition-all ${rowClass} search-row" data-search="${u.first_name} ${u.last_name} ${u.email}">
                        <td class="px-6 py-4">
                            <p class="font-bold text-gray-900 text-base">${u.first_name} ${u.last_name}</p>
                            <p class="text-[10px] font-medium text-gray-500">${u.email || 'No email'} • ${u.phone || 'No phone'}</p>
                            ${isFrozen ? '<p class="text-[10px] font-black text-red-500 uppercase tracking-widest mt-1 flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Account Frozen</p>' : ''}
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-bold text-gray-700 text-xs uppercase mb-1">${u.spiritual_status.replace('_', ' ')}</p>
                            ${attBadge}
                        </td>
                        <td class="px-6 py-4 max-w-xs">${rolePills}</td>
                        <td class="px-6 py-4 text-right">
                            <button onclick="openManageModal(${u.id})" class="text-xs bg-white border border-gray-200 text-gray-900 px-4 py-2 rounded-xl font-bold shadow-sm hover:border-gray-900 transition-colors">Manage Access</button>
                        </td>
                    </tr>`;
                });
                $('#rosterTableBody').html(html);
            }
        }, 'json');
    }

    // Live Search
    $('#searchRoster').on('keyup', function() {
        const val = $(this).val().toLowerCase();
        $('.search-row').each(function() {
            $(this).toggle($(this).attr('data-search').toLowerCase().indexOf(val) > -1);
        });
    });

    // ==========================================
    // MANAGE USER LOGIC
    // ==========================================
    function openManageModal(id) {
        currentUser = globalUsers.find(u => u.id == id);
        if(!currentUser) return;

        $('#assignRoleUserId').val(id);
        $('#manageUserName').text(`${currentUser.first_name} ${currentUser.last_name}`);

        // Render Active Roles inside Modal
        let activeHtml = '';
        if (currentUser.role_data) {
            const roles = currentUser.role_data.split('|');
            roles.forEach(r => {
                const parts = r.split(':');
                const rName = parts[0].replace('_', ' ');
                const isPrim = parts[1] === '1';
                const title = parts[2] ? parts[2] : null;
                const rId = globalRoles.find(gr => gr.role_name === parts[0])?.id;

                activeHtml += `
                <div class="bg-white border border-gray-200 rounded-xl p-4 flex justify-between items-center shadow-sm">
                    <div>
                        <p class="font-bold text-gray-900 flex items-center gap-2">
                            ${isPrim ? '<svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>' : ''}
                            ${rName}
                        </p>
                        ${title ? `<p class="text-xs font-bold text-hodBlue mt-0.5">${title}</p>` : `<button onclick="openCustomTitleModal(${id}, ${rId})" class="text-[10px] text-gray-400 hover:text-blue-500 font-bold uppercase tracking-wider mt-1 underline decoration-gray-200 underline-offset-2">Add Display Tag</button>`}
                    </div>
                    <button onclick="revokeRole(${id}, ${rId})" class="bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg text-xs font-bold transition-colors border border-red-100">Revoke</button>
                </div>`;
            });
        } else {
            activeHtml = '<p class="text-xs text-gray-400 italic p-3 bg-gray-50 rounded-xl border border-gray-100">No active system roles.</p>';
        }
        $('#activeRolesContainer').html(activeHtml);

        // Render Freeze Button
        const isFrozen = currentUser.account_frozen == 1;
        const fBtn = $('#btnToggleFreeze');
        if (isFrozen) {
            fBtn.text('Unfreeze Account').removeClass('bg-red-600 text-white hover:bg-red-700 border-red-700').addClass('bg-white text-red-600 hover:bg-red-50 border-red-200');
        } else {
            fBtn.text('Freeze Account').addClass('bg-red-600 text-white hover:bg-red-700 border-red-700').removeClass('bg-white text-red-600 hover:bg-red-50 border-red-200');
        }

        openModal('manageUserModal');
    }

    function revokeRole(userId, roleId) {
        if(!confirm("Are you absolutely sure you want to revoke this security clearance?")) return;
        $.post(API_URL, { action: 'revoke_role', user_id: userId, role_id: roleId }, function(res) {
            if(res.status === 'success') {
                showToast(res.message, 'success');
                loadRoster();
                setTimeout(() => openManageModal(userId), 500); // Reload modal data invisibly
            }
        }, 'json');
    }

    function toggleFreeze() {
        const isCurrentlyFrozen = currentUser.account_frozen == 1;
        const newStatus = isCurrentlyFrozen ? 0 : 1;
        const actionTxt = newStatus ? "freeze" : "unfreeze";
        
        if(!confirm(`Are you sure you want to ${actionTxt} this account?`)) return;

        $.post(API_URL, { action: 'toggle_freeze', user_id: currentUser.id, freeze_status: newStatus }, function(res) {
            if(res.status === 'success') {
                showToast(res.message, 'success');
                closeModal('manageUserModal');
                loadRoster();
            }
        }, 'json');
    }

    function openCustomTitleModal(userId, roleId) {
        $('#titleUserId').val(userId);
        $('#titleRoleId').val(roleId);
        openModal('customTitleModal');
    }

    // ==========================================
    // TAB 2: AUDIT LOGS LOGIC
    // ==========================================
    function loadAuditLogs() {
        $('#auditTableBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading secure audit trail...</td></tr>');
        $.post(API_URL, { action: 'fetch_audit_logs' }, function(res) {
            if(res.status === 'success') {
                let html = '';
                if(res.logs.length === 0) {
                    html = '<tr><td colspan="5" class="text-center py-20 text-gray-400 italic">No system modifications recorded yet.</td></tr>';
                } else {
                    res.logs.forEach(l => {
                        const dateObj = new Date(l.created_at);
                        const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour:'2-digit', minute:'2-digit' });
                        
                        let badgeColor = 'bg-gray-100 text-gray-600';
                        if(l.action_type === 'Granted') badgeColor = 'bg-green-100 text-green-700 border-green-200 border';
                        if(l.action_type === 'Revoked') badgeColor = 'bg-red-100 text-red-700 border-red-200 border';
                        if(l.action_type === 'Frozen') badgeColor = 'bg-blue-100 text-blue-700 border-blue-200 border';
                        if(l.action_type === 'Title_Added') badgeColor = 'bg-purple-100 text-purple-700 border-purple-200 border';

                        html += `
                        <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors">
                            <td class="px-6 py-4 text-xs font-bold text-gray-500">${dateStr}</td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900">${l.admin_fname} ${l.admin_lname}</p>
                            </td>
                            <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider ${badgeColor}">${l.action_type.replace('_', ' ')}</span></td>
                            <td class="px-6 py-4 font-bold text-gray-700">${l.target_fname} ${l.target_lname}</td>
                            <td class="px-6 py-4 text-xs text-gray-500 font-medium">${l.action_details}</td>
                        </tr>`;
                    });
                }
                $('#auditTableBody').html(html);
            }
        }, 'json');
    }

    // ==========================================
    // INITIALIZATION
    // ==========================================
    $(document).ready(function() {
        loadRoster();

        // Forms Initialization with success callbacks
        handleAjaxForm('assignRoleForm', function(res) {
            loadRoster();
            const id = $('#assignRoleUserId').val();
            setTimeout(() => openManageModal(id), 500); // Refresh active roles view
        });

        handleAjaxForm('customTitleForm', function(res) {
            closeModal('customTitleModal');
            loadRoster();
            const id = $('#titleUserId').val();
            setTimeout(() => openManageModal(id), 500); 
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>