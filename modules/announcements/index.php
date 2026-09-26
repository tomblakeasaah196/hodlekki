<?php
// /modules/announcements/index.php
require_once '../../includes/header.php'; 
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Service Announcements</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Draft, approve, and organize notices for specific church events.</p>
            </div>
        </div>
        
        <button onclick="openCreateModal()" class="relative z-10 bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all flex items-center gap-2 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            New Notice
        </button>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative animate-fade-in-up">
        <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
            <h3 class="text-lg font-bold text-gray-800 tracking-tight">Announcement Register</h3>
            <div class="relative w-full sm:w-72">
                <input type="text" id="searchInput" placeholder="Search title or event..." 
                    class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all bg-white shadow-sm">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Event Date</th>
                        <th class="px-6 py-4">Announcement Details</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="announcementsTableBody" class="divide-y divide-gray-50">
                    </tbody>
            </table>
        </div>
    </div>
</div>

<div id="announcementModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300">
        
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 id="modalTitle" class="text-xl font-bold text-gray-900">Create Notice</h3>
            <button onclick="closeModal('announcementModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="announcementForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save_announcement">
            <input type="hidden" name="id" id="formId">

            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Target Event *</label>
                <select name="target_event_id" id="eventSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:ring-2 focus:ring-hodBlue outline-none font-bold cursor-pointer transition-all">
                    <option value="">-- Select Event --</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Heading / Title *</label>
                <input type="text" name="title" id="formTitle" required placeholder="e.g. Special Communion Next Sunday" 
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none font-bold">
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Message Content *</label>
                <textarea name="content" id="formContent" required rows="6" placeholder="Type the full announcement detail here..." 
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none resize-none"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeModal('announcementModal')" class="px-6 py-3 font-bold text-gray-500 hover:bg-gray-100 rounded-xl transition-colors">Cancel</button>
                <button type="submit" id="submitBtn" class="bg-hodBlue text-white px-8 py-3 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all flex items-center gap-2">
                    <span>Save Announcement</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const API_URL = '/api/announcements_api.php';
    let currentAnnouncements = [];

    // ==========================================
    // UI CORE: MODALS & BUTTON LOCKS
    // ==========================================
    function openModal(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        setTimeout(() => { m.classList.remove('opacity-0'); m.children[0].classList.remove('scale-95'); }, 10);
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95');
        setTimeout(() => { m.classList.add('hidden'); $('#announcementForm')[0].reset(); $('#formId').val(''); }, 300);
    }

    function openCreateModal() {
        $('#modalTitle').text('Create Notice');
        openModal('announcementModal');
    }

    // ==========================================
    // DATA HANDLING
    // ==========================================
    function loadData() {
        // Fetch Events for Dropdown
        $.getJSON(API_URL, { action: 'fetch_events' }, res => {
            let opts = '<option value="">-- Select Event --</option>';
            res.data.forEach(e => {
                const date = new Date(e.event_date).toLocaleDateString();
                opts += `<option value="${e.id}">${e.title} (${date})</option>`;
            });
            $('#eventSelect').html(opts);
        });

        // Fetch Announcements
        $.getJSON(API_URL, { action: 'fetch_announcements' }, res => {
            currentAnnouncements = res.data;
            let html = '';
            if(res.data.length === 0) {
                html = `<tr><td colspan="4" class="px-6 py-16 text-center text-gray-400 italic">No announcements found.</td></tr>`;
            } else {
                res.data.forEach(a => {
                    const dateObj = new Date(a.event_date);
                    const statusColors = {
                        'Pending': 'bg-yellow-50 text-yellow-700 border-yellow-100',
                        'Approved': 'bg-green-50 text-green-700 border-green-100',
                        'Archived': 'bg-gray-50 text-gray-500 border-gray-100'
                    };

                    const approveBtn = (a.status === 'Pending') 
                        ? `<button onclick="updateStatus(${a.id}, 'Approved')" class="text-[10px] font-bold text-green-600 hover:text-green-800 uppercase tracking-wider">Approve</button>`
                        : '';

                    html += `
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="px-6 py-4">
                            <p class="font-bold text-gray-900">${dateObj.toLocaleDateString('en-US', {month: 'short', day: 'numeric'})}</p>
                            <p class="text-[10px] text-gray-400 font-medium">${a.event_name}</p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-bold text-gray-800">${a.title}</p>
                            <p class="text-xs text-gray-500 line-clamp-1 mt-1">${a.content}</p>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase border ${statusColors[a.status]}">${a.status}</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-3">
                                ${approveBtn}
                                <button onclick="editNotice(${a.id})" class="text-gray-400 hover:text-hodBlue"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>
                                <button onclick="deleteNotice(${a.id})" class="text-gray-300 hover:text-red-500"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                            </div>
                        </td>
                    </tr>`;
                });
            }
            $('#announcementsTableBody').html(html);
        });
    }

    function editNotice(id) {
        const a = currentAnnouncements.find(x => x.id == id);
        if(!a) return;
        $('#formId').val(a.id);
        $('#eventSelect').val(a.target_event_id);
        $('#formTitle').val(a.title);
        $('#formContent').val(a.content);
        $('#modalTitle').text('Edit Notice');
        openModal('announcementModal');
    }

    function updateStatus(id, status) {
        if(!confirm(`Mark this announcement as ${status}?`)) return;
        $.post(API_URL, { action: 'update_status', id: id, status: status }, res => {
            Toastify({ text: res.message, style: { background: "#10B981" } }).showToast();
            loadData();
        });
    }

    function deleteNotice(id) {
        if(!confirm("Are you sure? This cannot be undone.")) return;
        $.post(API_URL, { action: 'delete_announcement', id: id }, res => {
            Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
            loadData();
        });
    }

    // ==========================================
    // INITIALIZATION & FORM SUBMIT
    // ==========================================
    $(document).ready(function() {
        loadData();

        $('#announcementForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#submitBtn');
            const origText = btn.find('span').text();
            const spinner = `<svg class="animate-spin h-5 w-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner);

            $.post(API_URL, $(this).serialize(), res => {
                btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(`<span>${origText}</span>`);
                if(res.status === 'success') {
                    Toastify({ text: res.message, style: { background: "#10B981" } }).showToast();
                    closeModal('announcementModal');
                    loadData();
                } else {
                    Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
                }
            }, 'json');
        });

        // Search Logic
        $('#searchInput').on('keyup', function() {
            const val = $(this).val().toLowerCase();
            $('#announcementsTableBody tr').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>