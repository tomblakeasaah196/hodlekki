<?php
// /modules/pastoral/index.php
require_once '../../includes/header.php'; 

// Determine roles for UI logic
$active_role = $_SESSION['active_role'] ?? 'Member';
$is_leadership = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director']);
$is_super_admin = ($active_role === 'Super_Admin');
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Pastoral Desk</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Direct communication channel for feedback, questions, and insights.</p>
            </div>
        </div>
        
        <div class="relative z-10 flex gap-2">
            <button onclick="openSubmitModal()" class="bg-hodRed hover:bg-red-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg shadow-red-900/20 transition-all flex items-center gap-2 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Submit New Entry
            </button>
        </div>
    </div>

    <div class="flex items-center gap-2 bg-gray-100/50 p-1.5 rounded-2xl w-fit border border-gray-200/50">
        <button onclick="switchTab('qa')" id="tab-qa" class="tab-btn px-6 py-2.5 rounded-xl font-bold text-sm transition-all">Pastoral Q&A</button>
        <button onclick="switchTab('suggestions')" id="tab-suggestions" class="tab-btn px-6 py-2.5 rounded-xl font-bold text-sm transition-all">Suggestion Box</button>
        <button onclick="switchTab('impressions')" id="tab-impressions" class="tab-btn px-6 py-2.5 rounded-xl font-bold text-sm transition-all">Impressions</button>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative">
        <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Submitted By</th>
                        <th class="px-6 py-4 w-1/2">Content</th>
                        <th class="px-6 py-4">Status</th>
                        <?php if($is_leadership): ?>
                        <th class="px-6 py-4">Priority</th>
                        <?php endif; ?>
                        <th class="px-6 py-4 text-right">Date</th>
                    </tr>
                </thead>
                <tbody id="pastoralTableBody" class="divide-y divide-gray-50">
                    <tr>
                        <td colspan="5" class="px-6 py-20 text-center">
                            <svg class="animate-spin h-8 w-8 text-hodBlue mx-auto mb-4" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <p class="text-gray-500 font-medium animate-pulse">Syncing pastoral records...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="submitEntryModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-900">New Submission</h3>
            <button onclick="closeModal('submitEntryModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="entryForm" class="p-6 space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Category</label>
                <select name="type" id="typeSelector" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold outline-none focus:ring-2 focus:ring-hodBlue transition-all">
                    <option value="qa">Pastoral Q&A</option>
                    <option value="suggestion">Digital Suggestion</option>
                    <option value="impression">Periodical Impression</option>
                </select>
            </div>

            <div id="impressionFields" class="hidden space-y-4 bg-blue-50/50 p-4 rounded-2xl border border-blue-100">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-blue-800 uppercase mb-1">Period Type</label>
                        <select name="period_type" class="w-full px-3 py-2 border-white border rounded-lg text-sm outline-none">
                            <option value="Quarterly">Quarterly</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-blue-800 uppercase mb-1">Label (e.g. Q1 2026)</label>
                        <input type="text" name="period_label" placeholder="Q1 2026" class="w-full px-3 py-2 border-white border rounded-lg text-sm outline-none">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Your Message *</label>
                <textarea name="content" required rows="5" placeholder="Type your thoughts, question or suggestion here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-hodBlue transition-all resize-none"></textarea>
            </div>

            <div id="anonToggle" class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100">
                <input type="checkbox" name="is_anonymous" id="is_anonymous" class="w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue">
                <label for="is_anonymous" class="text-sm font-bold text-gray-700 cursor-pointer">Submit Anonymously</label>
            </div>

            <button type="submit" id="submitBtn" class="w-full bg-hodBlue text-white py-4 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-2">
                <span>Confirm Submission</span>
            </button>
        </form>
    </div>
</div>

<script>
    const API_URL = '/api/pastoral_api.php';
    let activeTab = 'qa';
    const isLeadership = <?= $is_leadership ? 'true' : 'false' ?>;
    const isSuperAdmin = <?= $is_super_admin ? 'true' : 'false' ?>;

    // UI Logic
    function openModal(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        setTimeout(() => { m.classList.remove('opacity-0'); m.children[0].classList.remove('scale-95'); }, 10);
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95');
        setTimeout(() => { m.classList.add('hidden'); $('#entryForm')[0].reset(); $('#impressionFields').addClass('hidden'); }, 300);
    }

    function openSubmitModal() {
        openModal('submitEntryModal');
    }

    // Tab Logic
    function switchTab(tab) {
        activeTab = tab;
        $('.tab-btn').removeClass('bg-white shadow-sm text-hodBlue border border-gray-200').addClass('text-gray-500 hover:bg-gray-200/50');
        $(`#tab-${tab}`).addClass('bg-white shadow-sm text-hodBlue border border-gray-200').removeClass('text-gray-500 hover:bg-gray-200/50');
        loadData();
    }

    // Data Loading
    function loadData() {
        $.getJSON(API_URL, { action: 'fetch_tab_data', tab: activeTab }, res => {
            if(res.status === 'success') {
                let html = '';
                if(res.data.length === 0) {
                    html = `<tr><td colspan="5" class="px-6 py-20 text-center text-gray-400 italic">No records found for this section.</td></tr>`;
                } else {
                    res.data.forEach(row => {
                        const isAnon = (row.is_anonymous == 1);
                        const submitter = isAnon ? '<span class="text-gray-400 font-medium italic">Anonymous</span>' : `<p class="font-bold text-gray-900">${row.first_name} ${row.last_name}</p><p class="text-[10px] text-gray-400">${row.phone}</p>`;
                        
                        // Priority Management (Super Admin Only)
                        let priorityHtml = `<span class="font-bold text-gray-400">${row.priority}</span>`;
                        if(isSuperAdmin) {
                            priorityHtml = `
                                <select onchange="updateRecord(${row.id}, 'priority', this.value)" class="text-[10px] font-bold border rounded p-1 outline-none bg-white">
                                    <option value="1" ${row.priority == 1 ? 'selected' : ''}>Normal</option>
                                    <option value="5" ${row.priority == 5 ? 'selected' : ''}>Medium</option>
                                    <option value="10" ${row.priority == 10 ? 'selected' : ''}>High</option>
                                </select>`;
                        }

                        // Status Management (Leadership)
                        const statusOptions = {
                            'qa': ['Pending', 'Answered', 'Archived'],
                            'suggestions': ['New', 'Reviewed', 'Actioned'],
                            'impressions': ['New', 'Reviewed', 'Archived']
                        };
                        let statusHtml = `<span class="px-2 py-1 rounded-lg text-[10px] font-black uppercase bg-gray-100 border border-gray-200">${row.status}</span>`;
                        if(isLeadership) {
                            statusHtml = `<select onchange="updateRecord(${row.id}, 'status', this.value)" class="text-[10px] font-bold border-none bg-blue-50 text-blue-700 rounded-lg px-2 py-1 cursor-pointer">`;
                            statusOptions[activeTab].forEach(s => {
                                statusHtml += `<option value="${s}" ${row.status == s ? 'selected' : ''}>${s}</option>`;
                            });
                            statusHtml += `</select>`;
                        }

                        html += `
                        <tr class="hover:bg-gray-50/50 transition">
                            <td class="px-6 py-4">${submitter}</td>
                            <td class="px-6 py-4">
                                <p class="text-gray-700 leading-relaxed font-medium">${row.question || row.content}</p>
                                ${row.period_label ? `<span class="text-[9px] font-black text-blue-500 uppercase tracking-widest mt-1 block">${row.period_label} (${row.period_type})</span>` : ''}
                            </td>
                            <td class="px-6 py-4">${statusHtml}</td>
                            ${isLeadership ? `<td class="px-6 py-4">${priorityHtml}</td>` : ''}
                            <td class="px-6 py-4 text-right text-[10px] text-gray-400 font-bold uppercase">${new Date(row.submitted_at).toLocaleDateString()}</td>
                        </tr>`;
                    });
                }
                $('#pastoralTableBody').html(html);
            }
        });
    }

    // Management Actions
    function updateRecord(id, field, value) {
        $.post(API_URL, { action: 'update_entry', tab: activeTab, id: id, [field]: value }, res => {
            if(res.status === 'success') {
                Toastify({ text: res.message, style: { background: "#10B981" } }).showToast();
                loadData();
            } else {
                Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
            }
        }, 'json');
    }

    // Initialization
    $(document).ready(function() {
        switchTab('qa');

        // Toggle Fields for Impressions
        $('#typeSelector').on('change', function() {
            if($(this).val() === 'impression') {
                $('#impressionFields').removeClass('hidden');
                $('#anonToggle').addClass('hidden'); // Impressions are usually named for accountability
            } else {
                $('#impressionFields').addClass('hidden');
                $('#anonToggle').removeClass('hidden');
            }
        });

        // Main Form Submission with Button Locking
        $('#entryForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#submitBtn');
            const origHtml = btn.html();
            const spinner = `<svg class="animate-spin h-5 w-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner);

            $.post(API_URL, { action: 'submit_entry', ...$(this).serializeArray().reduce((obj, item) => ({...obj, [item.name]: item.value}), {}) }, res => {
                btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
                if(res.status === 'success') {
                    Toastify({ text: res.message, style: { background: "#10B981" } }).showToast();
                    closeModal('submitEntryModal');
                    loadData();
                } else {
                    Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
                }
            }, 'json');
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>