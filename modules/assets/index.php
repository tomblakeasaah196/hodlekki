<?php
// /modules/assets/index.php
require_once '../../includes/header.php'; 

// Security Check (Frontend fallback)
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Asset Management</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Track church equipment, check-outs, maintenance, and inventory health.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <button onclick="openAssetModal()" class="w-full md:w-auto bg-indigo-600 hover:bg-indigo-800 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-lg shadow-indigo-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Register Asset
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Total Assets</p><h3 id="statTotal" class="text-2xl font-black text-gray-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-blue-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-blue-500 uppercase tracking-widest mb-1">Currently In Use</p><h3 id="statInUse" class="text-2xl font-black text-blue-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center text-blue-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-orange-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-orange-500 uppercase tracking-widest mb-1">In Maintenance</p><h3 id="statMaint" class="text-2xl font-black text-orange-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center text-orange-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-red-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-red-500 uppercase tracking-widest mb-1">Lost / Missing</p><h3 id="statLost" class="text-2xl font-black text-red-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-red-50 flex items-center justify-center text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div>
        </div>
    </div>

    <div class="flex bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-md animate-fade-in-up" style="animation-delay: 0.2s;">
        <button onclick="switchTab('inventory')" id="tabBtn-inventory" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-indigo-600 shadow-sm">Master Inventory</button>
        <button onclick="switchTab('logs')" id="tabBtn-logs" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Activity Logs</button>
    </div>

    <div id="view-inventory" class="animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Asset Details</th>
                            <th class="px-6 py-4">Department & Location</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-center">Condition</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryList" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium">Syncing inventory...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-logs" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Date & Time</th>
                            <th class="px-6 py-4">Asset</th>
                            <th class="px-6 py-4">Action Logged</th>
                            <th class="px-6 py-4">Assigned To</th>
                            <th class="px-6 py-4">Notes</th>
                        </tr>
                    </thead>
                    <tbody id="auditLogsList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<div id="assetModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg transform scale-95 transition-transform duration-300 my-auto flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center sticky top-0 bg-white z-10 shrink-0">
            <h3 id="assetModalTitle" class="text-xl font-bold text-gray-900">Register Asset</h3>
            <button onclick="closeModal('assetModal')" class="text-gray-400 hover:text-gray-900 bg-gray-50 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto p-6 flex-1 custom-scrollbar bg-gray-50/50">
            <form id="assetForm" class="space-y-5">
                <input type="hidden" name="action" value="save_asset">
                <input type="hidden" name="asset_id" id="inpAssetId">
                
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Asset Name / Model *</label>
                    <input type="text" name="name" id="inpName" required placeholder="e.g., Shure SM58 Microphone" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none font-bold text-gray-900 shadow-sm bg-white">
                </div>

                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Category *</label>
                        <select name="category" id="inpCategory" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none font-bold text-gray-900 bg-white shadow-sm">
                            <option value="Audio">Audio Equipment</option>
                            <option value="Video">Video & Camera</option>
                            <option value="Lighting">Lighting</option>
                            <option value="Instruments">Musical Instruments</option>
                            <option value="IT">IT & Computers</option>
                            <option value="Furniture">Furniture</option>
                            <option value="General">General / Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Quantity *</label>
                        <input type="number" name="quantity" id="inpQuantity" required min="1" value="1" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none font-bold text-gray-900 shadow-sm bg-white">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Managing Department</label>
                    <select name="managing_department_id" id="inpDept" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none font-bold text-gray-900 bg-white shadow-sm">
                        </select>
                </div>

                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Storage Location</label>
                        <input type="text" name="storage_location" id="inpLocation" placeholder="e.g., Media Booth" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 outline-none font-medium text-gray-900 shadow-sm bg-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Physical Condition</label>
                        <select name="condition_rating" id="inpCondition" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 outline-none font-bold text-gray-900 bg-white shadow-sm">
                            <option value="Excellent">Excellent (Like New)</option>
                            <option value="Good" selected>Good (Working)</option>
                            <option value="Fair">Fair (Wear & Tear)</option>
                            <option value="Poor">Poor (Needs Repair)</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="p-6 border-t border-gray-100 bg-white shrink-0">
            <button type="submit" form="assetForm" class="w-full bg-indigo-600 hover:bg-indigo-800 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Asset</button>
        </div>
    </div>
</div>

<div id="logActionModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 border border-gray-100">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl">
            <h3 class="text-lg font-bold text-gray-900">Log Asset Action</h3>
            <button onclick="closeModal('logActionModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="logForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="log_action">
            <input type="hidden" name="asset_id" id="logAssetId">
            
            <div class="mb-2 text-center">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Target Asset</p>
                <p id="logAssetName" class="text-lg font-black text-indigo-700 leading-tight mt-1"></p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="cursor-pointer">
                    <input type="radio" name="log_action" value="Check-Out" class="peer hidden" required>
                    <div class="p-3 text-center border-2 border-gray-100 rounded-xl peer-checked:border-blue-500 peer-checked:bg-blue-50 text-gray-500 peer-checked:text-blue-700 font-bold transition-all">Check-Out</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="log_action" value="Check-In" class="peer hidden" required>
                    <div class="p-3 text-center border-2 border-gray-100 rounded-xl peer-checked:border-green-500 peer-checked:bg-green-50 text-gray-500 peer-checked:text-green-700 font-bold transition-all">Check-In</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="log_action" value="Maintenance" class="peer hidden" required>
                    <div class="p-3 text-center border-2 border-gray-100 rounded-xl peer-checked:border-orange-500 peer-checked:bg-orange-50 text-gray-500 peer-checked:text-orange-700 font-bold transition-all">Maintenance</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="log_action" value="Lost" class="peer hidden" required>
                    <div class="p-3 text-center border-2 border-gray-100 rounded-xl peer-checked:border-red-500 peer-checked:bg-red-50 text-gray-500 peer-checked:text-red-700 font-bold transition-all">Lost/Missing</div>
                </label>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Assign To / Responsible User</label>
                <select name="user_id" id="logUserSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 outline-none font-bold text-gray-900 bg-gray-50"></select>
                <p class="text-[10px] text-gray-400 mt-1">Defaults to yourself if left blank.</p>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Notes</label>
                <textarea name="notes" rows="2" placeholder="e.g., Taking for Sunday service, Sent to repair shop..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-indigo-500 outline-none font-medium text-gray-900 resize-none"></textarea>
            </div>
            
            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all">Submit Log</button>
        </form>
    </div>
</div>

<script>
    const API_URL = '/api/assets_api.php';
    let globalAssets = [];

    // ==========================================
    // UI CORE LOGIC
    // ==========================================
    function switchTab(tabId) {
        $('#view-inventory, #view-logs').addClass('hidden').removeClass('animate-fade-in-up');
        $('#tabBtn-inventory, #tabBtn-logs').removeClass('bg-white text-indigo-600 shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        
        $(`#view-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        $(`#tabBtn-${tabId}`).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-indigo-600 shadow-sm');
    }

    function openModal(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        requestAnimationFrame(() => { m.classList.remove('opacity-0'); m.children[0].classList.remove('scale-95'); });
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0'); m.children[0].classList.add('scale-95'); 
        setTimeout(() => { m.classList.add('hidden'); const form = m.querySelector('form'); if(form) form.reset(); }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({ text: msg, gravity: "top", position: "center", style: { background: type === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold" } }).showToast();
    }

    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            btn.prop('disabled', true).html('Processing...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).html(origHtml);
                showToast(res.message, res.status);
                if(res.status === 'success' && successCallback) successCallback(res);
            }, 'json');
        });
    }

    // ==========================================
    // DATA RENDERING
    // ==========================================
    function getStatusBadge(status) {
        switch(status) {
            case 'Available': return '<span class="bg-green-50 text-green-700 px-3 py-1 rounded-lg text-xs font-bold border border-green-200">Available</span>';
            case 'In_Use': return '<span class="bg-blue-50 text-blue-700 px-3 py-1 rounded-lg text-xs font-bold border border-blue-200">In Use</span>';
            case 'Maintenance': return '<span class="bg-orange-50 text-orange-700 px-3 py-1 rounded-lg text-xs font-bold border border-orange-200">Maintenance</span>';
            case 'Lost': return '<span class="bg-red-50 text-red-700 px-3 py-1 rounded-lg text-xs font-bold border border-red-200">Lost/Missing</span>';
            default: return '<span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-xs font-bold">Retired</span>';
        }
    }

    function getConditionColor(cond) {
        switch(cond) {
            case 'Excellent': return 'text-green-500';
            case 'Good': return 'text-blue-500';
            case 'Fair': return 'text-orange-500';
            case 'Poor': return 'text-red-500';
            default: return 'text-gray-500';
        }
    }

    function loadDashboard() {
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                globalAssets = res.assets;

                // Stats
                $('#statTotal').text(res.stats.total);
                $('#statInUse').text(res.stats.in_use);
                $('#statMaint').text(res.stats.maintenance);
                $('#statLost').text(res.stats.lost);

                // Inventory Table
                let invHtml = '';
                if(res.assets.length === 0) invHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No assets registered in the database.</td></tr>';
                else {
                    res.assets.forEach(a => {
                        invHtml += `
                        <tr class="hover:bg-indigo-50/30 transition-colors border-b border-gray-50">
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900">${a.name}</p>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mt-0.5">${a.category} • QTY: ${a.quantity}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm font-bold text-indigo-700">${a.department_name || 'Unassigned Dept'}</p>
                                <p class="text-[10px] text-gray-500 font-medium mt-0.5">${a.storage_location || 'No Location specified'}</p>
                            </td>
                            <td class="px-6 py-4 text-center">${getStatusBadge(a.current_status)}</td>
                            <td class="px-6 py-4 text-center font-bold text-xs ${getConditionColor(a.condition_rating)}">${a.condition_rating}</td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <button onclick="openLogModal(${a.id}, '${a.name.replace(/'/g, "\\'")}')" class="text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm">Log Action</button>
                                <button onclick="openAssetModal(${a.id})" class="text-xs bg-white border border-gray-200 text-gray-600 hover:border-gray-400 px-3 py-1.5 rounded-lg font-bold shadow-sm transition-all">Edit</button>
                            </td>
                        </tr>`;
                    });
                }
                $('#inventoryList').html(invHtml);

                // Logs Table
                let logHtml = '';
                if(res.logs.length === 0) logHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No recent activity.</td></tr>';
                else {
                    res.logs.forEach(l => {
                        let actionColor = 'bg-gray-100 text-gray-700';
                        if(l.action === 'Check-Out') actionColor = 'bg-blue-50 text-blue-700 border border-blue-200';
                        if(l.action === 'Check-In') actionColor = 'bg-green-50 text-green-700 border border-green-200';
                        if(l.action === 'Maintenance') actionColor = 'bg-orange-50 text-orange-700 border border-orange-200';
                        if(l.action === 'Lost') actionColor = 'bg-red-50 text-red-700 border border-red-200';

                        logHtml += `
                        <tr class="hover:bg-gray-50/50 transition-colors border-b border-gray-50">
                            <td class="px-6 py-4 text-xs font-bold text-gray-500 whitespace-nowrap">${l.nice_date}</td>
                            <td class="px-6 py-4 font-bold text-gray-900">${l.asset_name}</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 rounded text-[10px] font-black uppercase tracking-wider ${actionColor}">${l.action.replace('-', ' ')}</span></td>
                            <td class="px-6 py-4 text-xs font-bold text-indigo-700">${l.first_name ? l.first_name + ' ' + l.last_name : 'System/Unknown'}</td>
                            <td class="px-6 py-4 text-xs text-gray-600 max-w-xs truncate" title="${l.notes || ''}">${l.notes || '-'}</td>
                        </tr>`;
                    });
                }
                $('#auditLogsList').html(logHtml);

                // Populate Dropdowns
                let dOpts = '<option value="">No specific department</option>';
                res.departments.forEach(d => dOpts += `<option value="${d.id}">${d.name}</option>`);
                $('#inpDept').html(dOpts);

                let uOpts = `<option value="${<?= $_SESSION['user_id'] ?>}">Assign to Myself</option>`;
                res.users.forEach(u => uOpts += `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`);
                $('#logUserSelect').html(uOpts);
            } else {
                showToast(res.message, 'error');
            }
        });
    }

    // ==========================================
    // MODAL OPENERS
    // ==========================================
    function openAssetModal(id = null) {
        $('#assetForm')[0].reset();
        $('#inpAssetId').val('');
        
        if(id) {
            const a = globalAssets.find(x => x.id == id);
            if(a) {
                $('#assetModalTitle').text('Edit Asset');
                $('#inpAssetId').val(a.id);
                $('#inpName').val(a.name);
                $('#inpCategory').val(a.category);
                $('#inpQuantity').val(a.quantity);
                $('#inpDept').val(a.managing_department_id);
                $('#inpLocation').val(a.storage_location);
                $('#inpCondition').val(a.condition_rating);
            }
        } else {
            $('#assetModalTitle').text('Register Asset');
        }
        openModal('assetModal');
    }

    function openLogModal(id, name) {
        $('#logForm')[0].reset();
        $('#logAssetId').val(id);
        $('#logAssetName').text(name);
        openModal('logActionModal');
    }

    $(document).ready(function() {
        loadDashboard();
        handleAjaxForm('assetForm', function() { closeModal('assetModal'); loadDashboard(); });
        handleAjaxForm('logForm', function() { closeModal('logActionModal'); loadDashboard(); });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>