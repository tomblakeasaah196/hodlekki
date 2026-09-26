<?php
// /modules/testimonies/index.php
$currentModule = 'testimonies';
require_once '../../includes/header.php';

// Security Check - Restrict to Admin/Pastors
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['active_role'], ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'])) {
    echo "<script>window.location.href = '/index.php';</script>";
    exit;
}
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-yellow-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-yellow-500 rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Testimonies & Praise</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Review, format, and publish stories of God's goodness.</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100/60">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Stories</p>
            <h4 class="text-2xl font-black text-gray-900" id="statTotal">0</h4>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100/60">
            <p class="text-[10px] font-bold text-yellow-500 uppercase tracking-widest mb-1">Pending Edits</p>
            <h4 class="text-2xl font-black text-yellow-600" id="statPending">0</h4>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100/60">
            <p class="text-[10px] font-bold text-purple-500 uppercase tracking-widest mb-1">Voice Notes</p>
            <h4 class="text-2xl font-black text-purple-600" id="statAudio">0</h4>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100/60">
            <p class="text-[10px] font-bold text-green-500 uppercase tracking-widest mb-1">Live Sunday</p>
            <h4 class="text-2xl font-black text-green-600" id="statLive">0</h4>
        </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative min-h-[500px] animate-fade-in-up" style="animation-delay: 0.2s;">
        <div class="p-6 border-b border-gray-100 bg-gray-50/30 flex justify-between items-center">
            <h3 class="font-bold text-gray-900">Master Roster</h3>
            <span class="text-xs text-gray-500 italic">Sorted by newest</span>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-sm text-gray-600 min-w-[800px]">
                <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Date & Submitter</th>
                        <th class="px-6 py-4">Privacy & Status</th>
                        <th class="px-6 py-4 w-1/3">Preview</th>
                        <th class="px-6 py-4 text-center">Live Service</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="testimoniesTableBody" class="divide-y divide-gray-50">
                    <tr><td colspan="5" class="py-10 text-center text-gray-400">Loading testimonies...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="reviewModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-yellow-50 shrink-0">
            <div>
                <h3 class="text-lg font-bold text-yellow-900">Review & Format Testimony</h3>
                <p id="reviewMeta" class="text-xs font-medium text-yellow-700 mt-1"></p>
            </div>
            <button onclick="closeModal('reviewModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-white space-y-6">
            
            <div id="voiceNoteContainer" class="hidden bg-gray-50 p-4 rounded-2xl border border-gray-200">
                <p class="text-xs font-bold text-gray-600 uppercase mb-2">Attached Audio</p>
                <audio id="audioPlayer" controls class="w-full h-10"></audio>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Format Testimony Content</label>
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <div id="quillEditor" class="bg-white" style="min-h: 200px; max-h: 400px;"></div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Visibility Status</label>
                <select id="publishStatus" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-gray-900 outline-none focus:border-yellow-500 cursor-pointer">
                    <option value="Published">Published (Live on Digital Wall)</option>
                    <option value="Pending_Editing">Pending (Needs more editing)</option>
                    <option value="Hidden">Hidden (Archived)</option>
                </select>
            </div>
        </div>
        
        <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0 flex gap-4">
            <button onclick="saveTestimony()" id="saveBtn" class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white py-3.5 rounded-xl font-bold transition-all shadow-md">Save & Apply Status</button>
        </div>
    </div>
</div>

<script>
    const API_URL = '../../api/testimony_admin_api.php';
    let currentTestimonies = [];
    let currentEditingId = null;
    let quill;

    // --- Modal Logic ---
    function openModal(id) {
        const modal = document.getElementById(id); if(!modal) return;
        document.body.appendChild(modal); 
        modal.classList.remove('hidden'); document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => { modal.classList.remove('opacity-0'); modal.children[0].classList.remove('scale-95'); });
    }
    
    function closeModal(id) {
        const modal = document.getElementById(id); if(!modal) return;
        modal.classList.add('opacity-0'); modal.children[0].classList.add('scale-95');
        setTimeout(() => { 
            modal.classList.add('hidden'); document.body.style.overflow = '';
            if(id === 'reviewModal') { document.getElementById('audioPlayer').pause(); }
        }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({ 
            text: msg, gravity: "top", position: "center", duration: 3000,
            style: { background: type === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold" } 
        }).showToast();
    }

    // --- Init Quill Editor ---
    function initQuill() {
        if(!quill) {
            quill = new Quill('#quillEditor', {
                theme: 'snow',
                placeholder: 'Write or format the testimony here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['clean']
                    ]
                }
            });
        }
    }

    // --- Master Data Loader ---
    function loadAdminData() {
        $.post(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                // Populate Analytics
                $('#statTotal').text(res.analytics.total_testimonies || 0);
                $('#statPending').text(res.analytics.pending_edits || 0);
                $('#statAudio').text(res.analytics.total_voice_notes || 0);
                $('#statLive').text(res.analytics.selected_for_service || 0);

                currentTestimonies = res.testimonies;
                let html = '';

                res.testimonies.forEach(t => {
                    let d = new Date(t.created_at).toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
                    
                    // Privacy & Status Badges
                    let pBadge = t.privacy_level === 'Public' ? 'bg-green-50 text-green-700' : (t.privacy_level === 'Anonymous_To_All' ? 'bg-gray-100 text-gray-600' : 'bg-purple-50 text-purple-700');
                    let sBadge = t.status === 'Published' ? 'bg-blue-50 text-blue-700' : (t.status === 'Pending_Editing' ? 'bg-yellow-50 text-yellow-700' : 'bg-red-50 text-red-700');
                    
                    let reqEditBadge = t.request_editing == 1 ? `<span class="mt-1 inline-block text-[9px] bg-red-100 text-red-600 px-2 py-0.5 rounded font-black uppercase">Edit Requested</span>` : '';
                    let audioBadge = t.voice_note_url ? `<span class="text-[10px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded font-bold ml-2">🎤 Audio Attached</span>` : '';
                    
                    // Service Toggle
                    let liveBtnClass = t.is_selected_for_service == 1 ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-green-50 hover:text-green-600';
                    let liveBtnText = t.is_selected_for_service == 1 ? '✓ Selected' : 'Mark for Sunday';

                    // Plain text preview (strip HTML tags for the table view)
                    let cleanPreview = t.content_text ? t.content_text.replace(/<[^>]*>?/gm, '').substring(0, 100) + '...' : 'No text provided.';

                    html += `
                    <tr class="hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-0">
                        <td class="px-6 py-4 align-top">
                            <p class="font-bold text-gray-900">${t.display_name}</p>
                            <p class="text-xs text-gray-500 font-medium">${t.submitter_type} • ${d}</p>
                        </td>
                        <td class="px-6 py-4 align-top">
                            <p><span class="text-[10px] px-2 py-1 rounded font-bold uppercase tracking-wider ${pBadge}">${t.privacy_level.replace(/_/g, ' ')}</span></p>
                            <p class="mt-2"><span class="text-[10px] px-2 py-1 rounded font-bold uppercase tracking-wider ${sBadge}">${t.status.replace('_', ' ')}</span></p>
                            ${reqEditBadge}
                        </td>
                        <td class="px-6 py-4 align-top">
                            <p class="text-sm text-gray-700 leading-relaxed">${cleanPreview} ${audioBadge}</p>
                        </td>
                        <td class="px-6 py-4 text-center align-top">
                            <button onclick="toggleService(${t.id}, ${t.is_selected_for_service})" class="${liveBtnClass} text-xs font-bold px-3 py-1.5 rounded-lg transition-all shadow-sm">${liveBtnText}</button>
                        </td>
                        <td class="px-6 py-4 text-right align-top space-x-2">
                            <button onclick="openReview(${t.id})" class="text-xs bg-yellow-50 text-yellow-700 hover:bg-yellow-500 hover:text-white px-3 py-1.5 rounded-lg font-bold border border-yellow-100 transition-all">Review & Edit</button>
                            <button onclick="deleteRow(${t.id})" class="text-xs bg-red-50 text-red-600 hover:bg-red-500 hover:text-white px-3 py-1.5 rounded-lg font-bold border border-red-100 transition-all">Del</button>
                        </td>
                    </tr>`;
                });

                $('#testimoniesTableBody').html(html || '<tr><td colspan="5" class="py-10 text-center text-gray-400 font-medium">No testimonies submitted yet.</td></tr>');
            }
        }, 'json');
    }

    // --- Actions ---
    function openReview(id) {
        initQuill();
        const t = currentTestimonies.find(x => x.id === id);
        if(!t) return;
        
        currentEditingId = id;
        $('#reviewMeta').text(`Submitted by: ${t.display_name} | Privacy: ${t.privacy_level.replace(/_/g, ' ')}`);
        
        // Handle Audio
        if(t.voice_note_url) {
            $('#voiceNoteContainer').removeClass('hidden');
            $('#audioPlayer').attr('src', t.voice_note_url);
        } else {
            $('#voiceNoteContainer').addClass('hidden');
            $('#audioPlayer').attr('src', '');
        }

        // Handle Rich Text
        // If they requested editing and gave us raw text, Quill parses it. If it already has HTML, Quill renders it perfectly.
        quill.root.innerHTML = t.content_text || '';
        
        $('#publishStatus').val(t.status);
        openModal('reviewModal');
    }

    function saveTestimony() {
        const btn = $('#saveBtn'); btn.prop('disabled', true).text('Saving...');
        const richHTML = quill.root.innerHTML;
        const stat = $('#publishStatus').val();

        $.post(API_URL, { action: 'save_edited_testimony', testimony_id: currentEditingId, content_text: richHTML, status: stat }, function(res) {
            btn.prop('disabled', false).text('Save & Apply Status');
            showToast(res.message, res.status);
            if(res.status === 'success') { closeModal('reviewModal'); loadAdminData(); }
        }, 'json').fail(() => { btn.prop('disabled', false).text('Save & Apply Status'); showToast('Server error', 'error'); });
    }

    function toggleService(id, state) {
        $.post(API_URL, { action: 'toggle_service_selection', testimony_id: id, current_state: state }, function(res) {
            showToast(res.message, res.status);
            if(res.status === 'success') loadAdminData();
        }, 'json');
    }

    function deleteRow(id) {
        if(!confirm('Permanently delete this testimony?')) return;
        $.post(API_URL, { action: 'delete_testimony', testimony_id: id }, function(res) {
            showToast(res.message, res.status);
            if(res.status === 'success') loadAdminData();
        }, 'json');
    }

    // Init
    $(document).ready(function() {
        loadAdminData();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>