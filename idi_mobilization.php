<?php
// /idi_mobilization.php
session_start();
$is_authenticated = isset($_SESSION['idi_mobilization_auth']) && $_SESSION['idi_mobilization_auth'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Exousia Mobilization | IDI Follow-up</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1e3a8a', hodRed: '#ef4444' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; }
        
        /* Glassmorphism Auth Screen */
        .auth-bg { background: linear-gradient(135deg, #1e3a8a 0%, #070c24 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .glass-card { background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.2); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); border-radius: 24px; padding: 2rem; width: 100%; max-w-sm; }
        
        /* Hide scrollbar for clean mobile look */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .pin-input { letter-spacing: 0.5em; font-size: 2rem; text-align: center; }
        .tab-btn { transition: all 0.2s ease; border-bottom: 2px solid transparent; }
        .tab-btn.active { border-bottom-color: #1e3a8a; color: #1e3a8a; font-weight: 700; }
        
        /* Smooth expanson for contact cards */
        .card-expand { display: none; }
        
        .pin-mask {
            -webkit-text-security: disc;
            text-security: disc; /* For future standard compatibility */
        }
    </style>
</head>
<body class="text-gray-800">

    <!-- ==================== AUTHENTICATION SCREEN ==================== -->
    <div id="authScreen" class="auth-bg <?php echo $is_authenticated ? 'hidden' : ''; ?>">
        <div class="glass-card text-center">
            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <h2 class="text-2xl font-extrabold text-white mb-2">Exousia Team</h2>
            <p class="text-white/70 text-sm mb-6">Enter the mobilization PIN to access the call list.</p>
            
            <form id="authForm" class="space-y-4">
                <!-- The Honeypot (Keeps desktop browsers at bay) -->
                <div style="opacity: 0; position: absolute; z-index: -1; width: 0; height: 0; overflow: hidden;" aria-hidden="true">
                    <input type="text" name="prevent_autofill_user" autocomplete="username" tabindex="-1">
                    <input type="password" name="prevent_autofill_pwd" autocomplete="current-password" tabindex="-1">
                </div>

                <!-- 
                  The Bulletproof PIN Input:
                  1. type="tel" stops mobile OS password managers.
                  2. .pin-mask (CSS) hides the characters.
                  3. autocomplete="new-random-string" breaks dictionary-based autofill.
                -->
                <input type="tel" 
                       id="pinInput" 
                       autocomplete="exousia-pin-nope"
                       data-lpignore="true" 
                       data-1p-ignore="true" 
                       data-form-type="other"
                       inputmode="numeric" 
                       pattern="[0-9]*" 
                       maxlength="4" 
                       placeholder="••••" 
                       required 
                       class="w-full bg-black/20 border border-white/20 rounded-xl py-3 text-white pin-input pin-mask focus:outline-none focus:border-white/50 focus:ring-1 focus:ring-white/50 transition-all">
                
                <button type="submit" id="btnAuth" class="w-full bg-white text-hodBlue font-bold py-3.5 rounded-xl hover:bg-gray-100 transition-colors shadow-lg">Access List</button>
            </form>
        </div>
    </div>

    <!-- ==================== MAIN DASHBOARD ==================== -->
    <div id="appScreen" class="min-h-screen flex flex-col <?php echo $is_authenticated ? '' : 'hidden'; ?>">
        
        <!-- Header -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
            <div class="px-4 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-lg font-extrabold text-gray-900 tracking-tight">Mobilization</h1>
                    <p class="text-[11px] font-semibold text-hodBlue uppercase tracking-wider">Exousia 2026</p>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Quick Add Button -->
                    <button onclick="openModal('quickAddModal')" class="bg-hodBlue p-2 rounded-full text-white shadow-md hover:bg-blue-900 transition flex items-center justify-center w-9 h-9">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    </button>
                    <!-- Manage Data Button -->
                    <button onclick="openModal('dataModal')" class="bg-gray-100 p-2 rounded-full text-gray-600 hover:bg-gray-200 transition flex items-center justify-center w-9 h-9">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </button>
                </div>
            </div>
            
            <!-- Search & Filter -->
            <div class="px-4 pb-3">
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search by name or phone..." class="w-full bg-gray-50 border border-gray-200 text-sm rounded-xl pl-10 pr-4 py-2.5 focus:outline-none focus:border-hodBlue transition-colors">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex px-4 gap-6 text-sm font-medium text-gray-500">
                <button onclick="switchTab('todo')" id="tab-todo" class="tab-btn active pb-3 w-1/2 text-center">To-Do (<span id="count-todo">0</span>)</button>
                <button onclick="switchTab('contacted')" id="tab-contacted" class="tab-btn pb-3 w-1/2 text-center">Contacted (<span id="count-contacted">0</span>)</button>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto p-4 no-scrollbar">
            
            <!-- Loading State -->
            <div id="loader" class="py-10 text-center text-gray-400 hidden">
                <svg class="animate-spin h-8 w-8 mx-auto text-hodBlue mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Fetching list...
            </div>

            <!-- Tab 1: To-Do -->
            <div id="view-todo" class="space-y-3 pb-20"></div>

            <!-- Tab 2: Contacted -->
            <div id="view-contacted" class="space-y-3 pb-20 hidden"></div>

        </main>
    </div>

    <!-- ==================== MANAGE DATA MODAL ==================== -->
    <div id="dataModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden items-end sm:items-center justify-center transition-opacity opacity-0">
        <div class="bg-white w-full sm:w-96 sm:rounded-2xl rounded-t-2xl p-6 transform translate-y-full sm:translate-y-0 transition-transform duration-300">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Manage Data</h3>
                <button onclick="closeModal('dataModal')" class="bg-gray-100 p-1.5 rounded-full text-gray-500 hover:text-red-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <div class="space-y-4">
                <!-- Export -->
                <form action="/api/mobilization_api.php" method="POST" target="_blank" class="w-full">
                    <input type="hidden" name="action" value="export_data">
                    <button type="submit" class="w-full flex items-center justify-between bg-green-50 text-green-700 px-4 py-3.5 rounded-xl font-bold border border-green-200 active:bg-green-100 transition">
                        <span class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            Download Full List (Excel)
                        </span>
                    </button>
                </form>

                <hr class="border-gray-100">

                <!-- Import -->
                <form id="importForm" class="w-full space-y-3">
                    <input type="hidden" name="action" value="import_data">
                    <div>
                        <div class="flex justify-between items-end mb-2">
                            <label class="block text-xs font-bold text-gray-500 uppercase">Upload Updated List</label>
                            <a href="/api/mobilization_api.php?action=download_template" target="_blank" class="text-[11px] font-bold text-hodBlue hover:underline">Download Template</a>
                        </div>
                        <input type="file" name="import_file" accept=".csv, .xls, .xlsx" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-hodBlue hover:file:bg-blue-100 border border-gray-200 rounded-xl p-1.5">
                    </div>
                    <button type="submit" id="btnImport" class="w-full bg-hodBlue text-white px-4 py-3 rounded-xl font-bold shadow-md hover:bg-blue-900 transition flex justify-center">Import / Update Database</button>
                    <p class="text-[10px] text-gray-400 text-center">Templates require columns: Name, Phone, Context.</p>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ==================== QUICK ADD MODAL ==================== -->
    <div id="quickAddModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden items-end sm:items-center justify-center transition-opacity opacity-0">
        <div class="bg-white w-full sm:w-96 sm:rounded-2xl rounded-t-2xl p-6 transform translate-y-full sm:translate-y-0 transition-transform duration-300">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Quick Add</h3>
                <button onclick="closeModal('quickAddModal')" class="bg-gray-100 p-1.5 rounded-full text-gray-500 hover:text-red-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <form id="quickAddForm" class="space-y-4">
                <input type="hidden" name="action" value="quick_add">
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Full Name *</label>
                    <input type="text" name="full_name" required placeholder="e.g., Sarah Johnson" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm focus:border-hodBlue outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Phone Number *</label>
                    <input type="tel" name="phone_number" required placeholder="e.g., 08012345678" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm focus:border-hodBlue outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Context / Background</label>
                    <textarea name="context" rows="2" placeholder="Where did we meet them?" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm focus:border-hodBlue outline-none resize-none"></textarea>
                </div>
                <button type="submit" id="btnQuickAdd" class="w-full bg-hodBlue text-white px-4 py-3.5 rounded-xl font-bold shadow-md hover:bg-blue-900 transition flex justify-center mt-2">
                    Add to List
                </button>
            </form>
        </div>
    </div>

    <!-- ==================== JAVASCRIPT ==================== -->
    <script>
        const API_URL = '/api/mobilization_api.php';
        let rawUncontacted = [];
        let rawContacted = [];

        // --- Helpers ---
        function toast(msg, type='success'){ 
            Toastify({ text:msg, gravity:"top", position:"center", duration:3000, style:{ background: type==='success'?"#10B981":"#EF4444", borderRadius:"10px", fontWeight:"bold", fontSize:"14px" } }).showToast(); 
        }
        function openModal(id) { 
            const m = $('#'+id); m.removeClass('hidden').addClass('flex'); 
            requestAnimationFrame(() => { m.removeClass('opacity-0'); m.children().removeClass('translate-y-full'); }); 
        }
        function closeModal(id) { 
            const m = $('#'+id); m.addClass('opacity-0'); m.children().addClass('translate-y-full'); 
            setTimeout(() => m.removeClass('flex').addClass('hidden'), 300); 
        }
        const esc = s => (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        // --- WhatsApp Number Formatter (Converts local 080... to 23480...) ---
        function formatWA(phone) {
            if(!phone) return '';
            let p = phone.replace(/\D/g, ''); // strip non-digits
            if (p.startsWith('0')) p = '234' + p.substring(1);
            return 'https://wa.me/' + p;
        }

        // --- Authentication ---
        $('#authForm').on('submit', function(e){
            e.preventDefault();
            const btn = $('#btnAuth'); const orig = btn.text();
            btn.prop('disabled', true).text('Verifying...');
            $.post(API_URL, { action: 'verify_pin', pin: $('#pinInput').val() }, function(res){
                btn.prop('disabled', false).text(orig);
                if(res.status === 'success'){
                    $('#authScreen').addClass('hidden');
                    $('#appScreen').removeClass('hidden');
                    loadContacts();
                } else {
                    toast(res.message, 'error');
                    $('#pinInput').val('').focus();
                }
            }, 'json').fail(()=> { btn.prop('disabled', false).text(orig); toast('Connection error', 'error'); });
        });

        // --- Fetch & Render ---
        function loadContacts() {
            $('#loader').removeClass('hidden');
            $('#view-todo, #view-contacted').empty();
            $.post(API_URL, { action: 'fetch_contacts' }, function(res){
                $('#loader').addClass('hidden');
                if(res.status === 'success') {
                    rawUncontacted = res.uncontacted || [];
                    rawContacted = res.contacted || [];
                    $('#count-todo').text(rawUncontacted.length);
                    $('#count-contacted').text(rawContacted.length);
                    renderList('todo', rawUncontacted);
                    renderList('contacted', rawContacted);
                } else if(res.status === 'auth_error') {
                    window.location.reload(); // Boot them back to PIN screen
                }
            }, 'json');
        }

        // Card Generator
        function renderList(targetId, dataList) {
            const container = $(`#view-${targetId}`);
            if (dataList.length === 0) {
                container.html(`<div class="text-center py-10 text-gray-400"><p>No records found in this list.</p></div>`);
                return;
            }
            
            let html = '';
            dataList.forEach(c => {
                const waLink = formatWA(c.phone_number);
                const isContacted = c.is_contacted == 1;
                const initial = c.full_name ? c.full_name.charAt(0).toUpperCase() : '?';
                
                // Construct the dynamic card
                html += `
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 contact-card" data-name="${esc(c.full_name).toLowerCase()}" data-phone="${esc(c.phone_number)}">
                    
                    <!-- Top Info Row -->
                    <div class="flex justify-between items-center mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full ${isContacted ? 'bg-green-100 text-green-700' : 'bg-red-50 text-red-600'} flex items-center justify-center font-bold text-lg shrink-0">
                                ${initial}
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 leading-tight">${esc(c.full_name)}</h4>
                                <p class="text-[11px] font-semibold text-gray-500 tracking-wide">${esc(c.phone_number)}</p>
                            </div>
                        </div>
                        
                        <!-- Quick Action Icons -->
                        <div class="flex gap-1.5 shrink-0">
                            <a href="tel:${esc(c.phone_number)}" class="w-10 h-10 rounded-full bg-blue-50 hover:bg-blue-100 text-blue-600 flex items-center justify-center transition" title="Direct Call">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            </a>
                            <a href="${waLink}" target="_blank" rel="noopener" class="w-10 h-10 rounded-full bg-emerald-50 hover:bg-emerald-100 text-emerald-600 flex items-center justify-center transition" title="WhatsApp Message">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Context Display -->
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-3 text-sm text-gray-700 mb-3">
                        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Context / Background</span>
                        <p class="leading-snug">${c.context ? esc(c.context) : '<span class="italic text-gray-400">No background data available.</span>'}</p>
                    </div>

                    ${isContacted ? `
                    <!-- Contacted Status View -->
                    <div class="bg-blue-50/50 border border-blue-100 rounded-xl p-3 text-sm">
                        <span class="text-[10px] font-bold text-blue-400 uppercase tracking-wider block mb-1">Follow-up Notes (By ${esc(c.contacted_by)})</span>
                        <p class="text-blue-900 font-medium">${esc(c.followup_notes)}</p>
                    </div>
                    <button onclick="toggleCard(this)" class="mt-2 w-full text-center text-xs font-bold text-gray-400 hover:text-gray-600 py-1">Edit Note ↓</button>
                    ` : `
                    <!-- Uncontacted Button -->
                    <button onclick="toggleCard(this)" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-sm transition">Log Follow-up Call</button>
                    `}

                    <!-- Expandable Form Area -->
                    <div class="card-expand mt-3 border-t border-gray-100 pt-3">
                        <form onsubmit="submitNote(event, ${c.id})" class="space-y-3">
                            <div>
                                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Call Notes</label>
                                <textarea name="followup_notes" required rows="2" placeholder="What was the outcome of the call?" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm focus:border-hodBlue outline-none resize-none">${isContacted ? esc(c.followup_notes) : ''}</textarea>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Your Name</label>
                                <input type="text" name="contacted_by" required placeholder="E.g. Bro. John" value="${isContacted ? esc(c.contacted_by) : ''}" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm focus:border-hodBlue outline-none">
                            </div>
                            <button type="submit" class="w-full bg-hodBlue text-white font-bold py-2.5 rounded-lg text-sm shadow hover:bg-blue-900 transition flex justify-center items-center">
                                Save Notes
                            </button>
                        </form>
                    </div>
                </div>`;
            });
            container.html(html);
        }

        // --- UI Interactions ---
        function switchTab(tabId) {
            $('.tab-btn').removeClass('active border-bottom-color text-hodBlue font-bold').addClass('text-gray-500');
            $('#tab-' + tabId).addClass('active border-bottom-color text-hodBlue font-bold').removeClass('text-gray-500');
            
            $('#view-todo, #view-contacted').addClass('hidden');
            $('#view-' + tabId).removeClass('hidden');
        }

        function toggleCard(btn) {
            const expander = $(btn).siblings('.card-expand');
            $('.card-expand').not(expander).slideUp(200); // Close others
            expander.slideToggle(200);
            if($(btn).text() === 'Log Follow-up Call') $(btn).hide(); // Hide button once opened on uncontacted tab
        }

        // --- Search/Filter ---
        $('#searchInput').on('keyup', function(){
            const v = $(this).val().toLowerCase();
            $('.contact-card').each(function(){
                const name = $(this).data('name');
                const phone = $(this).data('phone').toString();
                if(name.indexOf(v) > -1 || phone.indexOf(v) > -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // --- Submit Note ---
        window.submitNote = function(e, id) {
            e.preventDefault();
            const form = $(e.target);
            const btn = form.find('button[type="submit"]');
            const orig = btn.text();
            
            btn.prop('disabled', true).text('Saving...');
            
            const data = form.serialize() + `&action=update_note&id=${id}`;
            $.post(API_URL, data, function(res){
                if(res.status === 'success') {
                    toast(res.message, 'success');
                    // Reload data silently to refresh lists and move card to Tab 2
                    loadContacts();
                } else {
                    toast(res.message, 'error');
                    btn.prop('disabled', false).text(orig);
                }
            }, 'json').fail(()=> { btn.prop('disabled', false).text(orig); toast('Connection error', 'error'); });
        };

        // --- Import Form ---
        $('#importForm').on('submit', function(e){
            e.preventDefault();
            const btn = $('#btnImport'); const orig = btn.text();
            btn.prop('disabled', true).text('Processing...');
            
            const fd = new FormData(this);
            $.ajax({
                url: API_URL,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).text(orig);
                    if(res.status === 'success') {
                        toast(res.message, 'success');
                        closeModal('dataModal');
                        $('#importForm')[0].reset();
                        loadContacts();
                    } else {
                        toast(res.message, 'error');
                    }
                },
                error: function() { btn.prop('disabled', false).text(orig); toast('Upload failed', 'error'); }
            });
        });
        
        // --- Quick Add Form ---
        $('#quickAddForm').on('submit', function(e){
            e.preventDefault();
            const btn = $('#btnQuickAdd'); const orig = btn.text();
            btn.prop('disabled', true).text('Adding...');
            
            $.post(API_URL, $(this).serialize(), function(res){
                btn.prop('disabled', false).text(orig);
                if(res.status === 'success') {
                    toast(res.message, 'success');
                    closeModal('quickAddModal');
                    $('#quickAddForm')[0].reset();
                    loadContacts();
                } else {
                    toast(res.message, 'error');
                }
            }, 'json').fail(()=> { btn.prop('disabled', false).text(orig); toast('Failed to add', 'error'); });
        });

        // Initialize if already authenticated (page reload)
        $(document).ready(function(){
            if(<?php echo $is_authenticated ? 'true' : 'false'; ?>) {
                loadContacts();
            }
        });
    </script>
</body>
</html>