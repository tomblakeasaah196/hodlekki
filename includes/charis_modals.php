<?php
// /includes/charis_modals.php
// All modals for the Charis module (welfare, events, finance, library)
?>

<div id="envisionRecapModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50 shrink-0 sticky top-0 z-50">
            <div><h3 class="text-lg font-bold text-gray-900">Celebrants Overview</h3><p class="text-xs text-gray-500 font-medium">Ready for high-res landscape export</p></div>
            <div class="flex gap-2 shrink-0">
                <button id="btnExportJPEG" onclick="exportRecapJPEG()" type="button" class="flex-1 sm:flex-none text-xs bg-hodBlue hover:bg-gray-900 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Export Media</button>
                <button onclick="closeModal('envisionRecapModal')" class="text-gray-400 hover:text-red-500 bg-white border border-gray-200 p-2.5 rounded-xl shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
        </div>
        <div class="p-6"><div id="envisionRecapGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6"></div></div>
    </div>
</div>

<div id="assignWorkerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Assign Charis Worker</h3>
            <button onclick="closeModal('assignWorkerModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWorkerForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="assign_celebration">
            <input type="hidden" name="target_user_id" id="assignTargetId">
            <input type="hidden" name="event_type" id="assignEventType">
            <input type="hidden" name="event_date" id="assignEventDate">
            <div>
                <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Celebrating</p>
                <p id="assignTargetName" class="text-xl font-black text-gray-900 mt-1"></p>
                <p id="assignEventBadge" class="text-[10px] font-bold uppercase tracking-widest text-hodBlue mt-1"></p>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assign Charis Worker</label>
                <select name="worker_id" id="workerSelectDropdown" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold text-gray-700 cursor-pointer"></select>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Assign Now</button>
        </form>
    </div>
</div>

<div id="addLifeEventModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Log Life Event</h3>
            <button onclick="closeModal('addLifeEventModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="addLifeEventForm" class="p-8 space-y-5">
            <input type="hidden" name="action" value="add_life_event">
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Member *</label><select name="user_id" id="memberSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm"></select></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Event Type *</label>
                    <select name="event_type" id="eventTypeSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm cursor-pointer" onchange="document.getElementById('customEventDiv').classList.toggle('hidden', this.value!=='Other')">
                        <option value="Wedding_Anniversary">Wedding Anniversary</option>
                        <option value="Baby_Dedication">Baby Dedication</option>
                        <option value="Child_Birth">Child Birth</option>
                        <option value="Other">Other...</option>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Event Date *</label><input type="date" name="event_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold shadow-sm"></div>
            </div>
            <div id="customEventDiv" class="hidden"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Custom Event Name</label><input type="text" name="custom_event_type" placeholder="e.g., Graduation, Work Anniversary" class="w-full px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-blue-900 font-bold focus:ring-1 focus:ring-hodBlue outline-none"></div>
            <button type="submit" class="w-full bg-hodRed hover:bg-red-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Life Event</button>
        </form>
    </div>
</div>

<div id="manageWelfareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[85vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Welfare: <span id="welfareMemberName" class="text-orange-500"></span></h3>
            <button onclick="closeModal('manageWelfareModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex gap-3">
                <a id="btnWelfareCall" href="#" class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 py-3 rounded-xl flex justify-center items-center gap-2 font-bold transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg Call
                </a>
                <a id="btnWelfareWhatsApp" href="#" target="_blank" class="flex-1 bg-[#25D366]/10 hover:bg-[#25D366]/20 text-[#25D366] border border-[#25D366]/30 py-3 rounded-xl flex justify-center items-center gap-2 font-bold transition-colors">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 0C5.385 0 .002 5.385.002 12.032c0 2.128.555 4.2 1.613 6.027L0 24l6.104-1.603c1.764.957 3.738 1.464 5.925 1.464 6.645 0 12.028-5.385 12.028-12.032C24.057 5.385 18.676 0 12.031 0zm5.426 16.436c-.297-.15-1.764-.87-2.037-.97-.27-.1-.47-.15-.668.15-.2.298-.77 1-.944 1.203-.175.204-.35.23-.648.08-.297-.15-1.258-.464-2.395-1.485-.886-.795-1.484-1.776-1.66-2.075-.174-.298-.018-.46.13-.61.134-.135.297-.348.446-.522.15-.175.2-.298.3-.497.1-.2.05-.376-.025-.522-.075-.15-.668-1.613-.916-2.208-.242-.58-.488-.503-.668-.513-.174-.01-.375-.01-.574-.01-.2 0-.524.075-.798.375s-1.047 1.17-1.047 2.855c0 1.685 1.07 3.315 1.22 3.515.15.2 2.4 3.664 5.816 5.14.814.35 1.45.56 1.946.717.818.26 1.56.223 2.146.135.654-.1 2.037-.833 2.324-1.637.288-.804.288-1.493.2-1.637-.088-.144-.336-.23-.634-.38z"/></svg> WhatsApp
                </a>
            </div>
            <hr class="border-gray-100">
            <form id="manageWelfareForm" class="space-y-4">
                <input type="hidden" name="action" value="update_awol_status">
                <input type="hidden" name="user_id" id="welfareUserId">
                <input type="hidden" name="followup_id" id="welfareFollowupId">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Welfare Findings / Comments *</label>
                    <textarea name="comments" rows="3" required placeholder="E.g., Spoke to them — traveling for work but will return next week..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 outline-none text-sm resize-none"></textarea>
                </div>
                <button type="submit" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-3 rounded-xl font-bold transition-all">Save Note Only</button>
            </form>
            <hr class="border-gray-100">
            <form id="resolveWelfareForm" class="space-y-4">
                <input type="hidden" name="action" value="resolve_awol_case">
                <input type="hidden" name="user_id" id="resolveWelfareUserId">
                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                    <p class="text-xs font-black text-green-800 uppercase tracking-wider mb-2">✓ Resolve &amp; Remove from AWOL List</p>
                    <p class="text-xs text-green-700 mb-3">This permanently closes the case for this cycle. The member's name will leave the AWOL list.</p>
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Final Status</label>
                        <select name="attendance_status" class="w-full px-3 py-2.5 border border-green-200 rounded-lg text-sm font-bold bg-white outline-none cursor-pointer">
                            <option value="Active">Active (Re-engaged &amp; attending)</option>
                            <option value="Relocated">Relocated</option>
                            <option value="Attends_Another_Church">Attends Another Church</option>
                            <option value="Unknown">Unknown — cannot be reached</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Summary Note *</label>
                        <textarea name="comments" rows="2" required placeholder="Brief summary for the record..." class="w-full px-3 py-2.5 border border-green-200 rounded-lg text-sm outline-none focus:border-green-400 resize-none"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md">✓ Resolve &amp; Close Case</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="assignWelfareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Assign Welfare Case</h3>
            <button onclick="closeModal('assignWelfareModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="assignWelfareForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="assign_welfare_case">
            <input type="hidden" name="target_user_id" id="assignWelTargetId">
            <input type="hidden" name="followup_id" id="assignWelFollowupId">
            <div><label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assign to Worker</label><select name="worker_id" id="welWorkerDropdown" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold cursor-pointer"></select></div>
            <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Assign Case</button>
        </form>
    </div>
</div>

<div id="awolReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <div><h3 class="text-lg font-bold text-gray-900">Generate AWOL Report</h3><p class="text-xs text-gray-400 mt-0.5">Downloads as a branded PDF for pastoral review.</p></div>
            <button onclick="closeModal('awolReportModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex bg-gray-100 p-1 rounded-xl gap-1">
                <button onclick="setReportMode('month')" id="rptModeMonth" class="flex-1 px-4 py-2 rounded-lg text-sm font-bold bg-white text-gray-900 shadow-sm transition-all">By Month</button>
                <button onclick="setReportMode('range')" id="rptModeRange" class="flex-1 px-4 py-2 rounded-lg text-sm font-bold text-gray-500 transition-all">Custom Range</button>
            </div>
            <div id="rptMonthFields" class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Month</label>
                    <select id="rptMonth" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($m==date('n')?'selected':'')?> ><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                    <select id="rptYear" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
                    </select>
                </div>
            </div>
            <div id="rptRangeFields" class="hidden grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">From Date</label><input type="date" id="rptDateStart" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">To Date</label><input type="date" id="rptDateEnd" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none"></div>
            </div>
            <button onclick="downloadAwolReport()" class="w-full bg-orange-600 hover:bg-orange-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download PDF Report
            </button>
        </div>
    </div>
</div>

<div id="manageCharisNotesModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b bg-gray-50 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Welfare Notes: <span id="charis_notes_target_name" class="text-hodBlue"></span></h3>
            <button onclick="closeModal('manageCharisNotesModal')"><svg class="w-6 h-6 text-gray-400 hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 space-y-4 custom-scrollbar bg-gray-50/50" id="existingCharisNotesContainer">
            </div>

        <div class="p-6 border-t border-gray-100 bg-white shrink-0">
            <form id="saveCharisNoteForm" class="space-y-4">
                <input type="hidden" name="action" value="save_charis_note">
                <input type="hidden" name="target_user_id" id="note_target_user_id">
                <input type="hidden" name="note_id" id="edit_charis_note_id" value="">

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1" id="charisNoteInputLabel">Add New Note</label>
                    <textarea name="note_text" id="charis_note_text_input" required placeholder="Type welfare updates or findings here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl min-h-[100px] text-sm outline-none focus:border-hodBlue resize-none whitespace-pre-wrap"></textarea>
                </div>

                <div id="charisPastorOnlyWrap" class="bg-purple-50/50 p-4 rounded-xl border border-purple-100 hidden">
    <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" name="pastor_only" id="cb_charis_pastor_only" value="1" class="w-4 h-4 text-purple-600 focus:ring-purple-500 rounded">
        <span class="text-xs font-bold text-gray-700">Pastors only
            <span class="font-medium text-gray-400">— hide this note from everyone except pastors</span>
        </span>
    </label>
</div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="resetCharisNoteForm()" class="px-5 py-3 bg-gray-100 text-gray-600 hover:bg-gray-200 font-bold rounded-xl transition text-sm hidden" id="cancelEditCharisNoteBtn">Cancel Edit</button>
                    <button type="submit" class="flex-1 bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold shadow-lg transition-all" id="saveCharisNoteBtn">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="eventTasksModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-2 sm:p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-y-auto custom-scrollbar max-h-[92vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20">
            <div>
                <h3 class="text-lg font-bold text-gray-900" id="taskModalTitle">Event Tasks</h3>
                <p class="text-xs text-gray-400 mt-0.5" id="taskModalDate"></p>
            </div>
            <div class="flex items-center gap-2">
                <a id="btnDownloadEventReport" href="#" target="_blank" class="hidden text-xs bg-gray-800 text-white px-4 py-2 rounded-xl font-bold hover:bg-black transition-all flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> PDF Report
                </a>
                <button onclick="closeModal('eventTasksModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full border border-gray-200 bg-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
        </div>

        <div id="addTaskSection" class="hidden p-5 bg-blue-50/50 border-b border-blue-100">
            <p class="text-xs font-black text-gray-500 uppercase tracking-wider mb-3">Add New Task</p>
            <form id="addTaskForm" class="space-y-3">
                <input type="hidden" name="action" value="add_event_task">
                <input type="hidden" name="event_id" id="taskEventId">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><input type="text" name="task_title" required placeholder="Task title (e.g., Water and Food Purchase)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue bg-white"></div>
                    <div><input type="text" name="task_description" placeholder="Description (optional)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue bg-white"></div>
                    <div><select name="task_category" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-white outline-none cursor-pointer"><option value="Logistics">Logistics</option><option value="Supplies">Supplies</option></select></div>
                    <div><select name="assigned_worker_id" id="taskWorkerDropdown" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-white outline-none cursor-pointer"><option value="">Assign to member...</option></select></div>
                    <div><input type="number" step="0.01" min="0" name="estimated_budget" placeholder="Estimated Budget (₦)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue bg-white"></div>
                </div>
                <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-2.5 rounded-xl text-sm font-bold transition-all">Add Task & Notify Member</button>
            </form>
        </div>

        <div class="p-5 flex-1">
            <div id="tasksList" class="space-y-4">
                <div class="text-center py-10 text-gray-400">Loading tasks...</div>
            </div>
        </div>

        <div id="hodSignOffSection" class="hidden p-5 border-t border-gray-100 bg-gray-50 shrink-0">
            <div id="stampDisplay" class="mb-4 hidden"></div>
            <button id="btnHodStamp" onclick="applyHodStamp()" class="w-full bg-gray-900 hover:bg-black text-white py-3 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Sign Off Event (HOD Digital Stamp)
            </button>
            <button id="btnFinanceStamp" onclick="applyFinanceStamp()" class="hidden w-full mt-2 bg-blue-700 hover:bg-blue-900 text-white py-3 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Finance Director Countersign
            </button>
        </div>
    </div>
</div>

<div id="submitTaskReportModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[10000] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Submit Task Report</h3>
            <button onclick="closeModal('submitTaskReportModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="taskReportForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_event_task">
            <input type="hidden" name="task_id" id="reportTaskId">
            <input type="hidden" name="status" value="Completed">
            <p class="text-sm font-bold text-gray-700" id="reportTaskTitle"></p>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">What did you buy / do? *</label><textarea name="member_report" rows="3" required placeholder="E.g., Bought 4 cartons of water from LekkiMart, paid ₦12,000..." class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Actual Amount Spent (₦) *</label><input type="number" step="0.01" min="0" name="actual_spent" required placeholder="0.00" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Receipt Reference</label><input type="text" name="receipt_note" placeholder="E.g., Receipt #4412" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue"></div>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Submit & Mark Completed</button>
        </form>
    </div>
</div>

<div id="setBudgetModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Set Monthly Budget</h3>
            <button onclick="closeModal('setBudgetModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="setBudgetForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="set_monthly_budget">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Month</label>
                    <select name="budget_month" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($m==date('n')?'selected':'')?> ><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                    <select name="budget_year" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
                    </select>
                </div>
            </div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Budget Amount (₦) *</label><input type="number" step="0.01" min="0" name="amount" required placeholder="e.g., 150000.00" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-green-500"></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Notes</label><input type="text" name="notes" placeholder="Optional notes" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-green-500"></div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Budget</button>
        </form>
    </div>
</div>

<div id="addExpenseModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Log New Expense</h3>
            <button onclick="closeModal('addExpenseModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="addExpenseForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="log_expense">
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Item / Purpose *</label><input type="text" name="item_name" required class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue" placeholder="E.g., Sunday Service Water"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label>
                    <select name="category" required class="w-full px-3 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <option value="Food_Beverage">Food &amp; Beverage</option>
                        <option value="Pastoral_Care">Pastoral Care</option>
                        <option value="Stationery">Stationery</option>
                        <option value="Decor">Decor</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Amount Req. (₦) *</label><input type="number" step="0.01" name="amount_requested" required class="w-full px-3 py-3 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-hodBlue" placeholder="0.00"></div>
            </div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Link to Event</label><select name="event_id" id="expenseEventSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none cursor-pointer"><option value="">-- General / Non-Event Expense --</option></select></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Notes</label><textarea name="notes" rows="2" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Submit Expense</button>
        </form>
    </div>
</div>

<div id="addBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20"><h3 class="text-lg font-bold text-gray-900">Add New Book</h3><button onclick="closeModal('addBookModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full bg-white shadow-sm border border-gray-100"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="addBookForm" class="p-6 pb-8 space-y-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_new_book">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Book Title *</label><input type="text" name="title" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Author *</label><input type="text" name="author" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label><input list="libraryCategories" name="category" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"><datalist id="libraryCategories"></datalist></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Format *</label>
                    <select name="book_type" id="bookTypeSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue cursor-pointer" onchange="document.getElementById('physicalCopiesDiv').classList.toggle('hidden',this.value==='E-Book'); document.getElementById('ebookUploadDiv').classList.toggle('hidden',this.value==='Physical')">
                        <option value="Physical">Physical Only</option><option value="E-Book">E-Book Only</option><option value="Both">Both</option>
                    </select>
                </div>
                <div id="physicalCopiesDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Total Copies</label><input type="number" name="total_copies" min="0" value="1" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Front Cover</label><input type="file" name="cover_image" id="aiFrontCover" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Back Cover (for AI)</label><input type="file" name="back_cover" id="aiBackCover" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div class="col-span-2"><button type="button" onclick="triggerAIExtraction()" id="btnAIExtract" class="w-full bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-4 py-3 rounded-xl font-bold text-sm transition-all flex justify-center items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg> AI Extract from Cover</button></div>
                <div class="col-span-2 hidden" id="ebookUploadDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">E-Book PDF *</label><input type="file" name="ebook_file" id="ebookFileInput" accept="application/pdf" class="w-full px-4 py-2.5 bg-red-50 border border-red-200 rounded-xl text-xs font-bold text-gray-700 cursor-pointer"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Audiobook Link</label><input type="url" name="audiobook_link" placeholder="https://..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Est. Read Time</label><input type="text" name="estimated_read_time" placeholder="e.g., 6 hours" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label><textarea name="description" rows="3" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Add to Library</button>
        </form>
    </div>
</div>

<div id="editBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-y-auto custom-scrollbar max-h-[80vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0 sticky top-0 z-20"><h3 class="text-lg font-bold text-gray-900">Edit Book</h3><button onclick="closeModal('editBookModal')" class="text-gray-400 hover:text-red-500 bg-white shadow-sm border border-gray-100 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="editBookForm" class="p-6 pb-8 space-y-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_book">
            <input type="hidden" name="book_id" id="editBookId">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Title *</label><input type="text" name="title" id="editBookTitle" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Author *</label><input type="text" name="author" id="editBookAuthor" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Category *</label><input list="libraryCategories" name="category" id="editBookCategory" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Format</label><select name="book_type" id="editBookTypeSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue cursor-pointer"><option value="Physical">Physical Only</option><option value="E-Book">E-Book Only</option><option value="Both">Both</option></select></div>
                <div id="editPhysicalCopiesDiv"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Total Copies</label><input type="number" name="total_copies" id="editBookTotalCopies" min="0" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Audiobook Link</label><input type="url" name="audiobook_link" id="editAudiobookLink" placeholder="https://..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Read Time</label><input type="text" name="estimated_read_time" id="editReadTime" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label><textarea name="description" id="editBookDesc" rows="3" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold text-sm outline-none focus:border-hodBlue resize-none"></textarea></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Replace Cover Image</label><input type="file" name="cover_image" accept="image/*" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold cursor-pointer"></div>
                <div class="col-span-2"><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Replace E-Book PDF</label><input type="file" name="ebook_file" accept="application/pdf" class="w-full px-4 py-2.5 bg-red-50 border border-red-200 rounded-xl text-xs font-bold cursor-pointer"></div>
            </div>
            <button type="submit" class="w-full bg-hodBlue hover:bg-gray-900 text-white py-3.5 rounded-xl font-bold shadow-lg transition-all">Save Changes</button>
        </form>
    </div>
</div>

<div id="returnBookModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0"><h3 class="text-lg font-bold text-gray-900">Process Book Return</h3><button onclick="closeModal('returnBookModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <form id="returnBookForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="process_book_return">
            <input type="hidden" name="borrow_id" id="returnBorrowId">
            <div class="text-center mb-2"><p class="text-sm text-gray-500">Receiving from:</p><p id="returnMemberName" class="text-lg font-black text-gray-900 mt-1"></p><p id="returnBookTitle" class="text-xs font-bold text-hodBlue mt-1"></p></div>
            <div><label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Book Condition</label>
                <select name="condition" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-gray-50 font-bold cursor-pointer">
                    <option value="Good">Good Condition</option><option value="Damaged">Damaged</option><option value="Lost">Lost</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all">Confirm Return</button>
        </form>
    </div>
</div>

<div id="celebrationEditorModal" class="fixed inset-0 w-screen h-screen bg-gray-900/95 hidden z-[10001] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0"><h3 class="text-lg font-bold text-gray-900">Celebration Portrait Editor</h3><button onclick="closeModal('celebrationEditorModal')" class="text-gray-400 hover:text-red-500 p-1 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>
        <div class="p-6 space-y-4">
            <div class="relative bg-gray-100 rounded-xl overflow-hidden" style="height:300px;"><img id="cropperImage" src="" alt="Crop" style="display:block; max-width:100%;"></div>
            <div class="flex flex-wrap gap-2 justify-center">
                <button onclick="applyCanvasFilter('none')"         class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Original</button>
                <button onclick="applyCanvasFilter('grayscale')"    class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">B&W</button>
                <button onclick="applyCanvasFilter('sepia')"        class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Sepia</button>
                <button onclick="applyCanvasFilter('brightness')"   class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Brighten</button>
                <button onclick="applyCanvasFilter('contrast')"     class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-bold transition-all">Contrast</button>
            </div>
            <input type="hidden" id="editorTargetUserId">
            <div class="flex gap-3">
                <label class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2 cursor-pointer transition-all"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg> Upload New<input type="file" id="newPortraitUpload" accept="image/*" class="hidden" onchange="handlePortraitUpload(this)"></label>
                <button onclick="saveCelebrationImage()" class="flex-1 bg-hodRed hover:bg-red-700 text-white py-3 rounded-xl font-bold text-sm transition-all shadow-lg">Save Portrait</button>
            </div>
        </div>
    </div>
</div>