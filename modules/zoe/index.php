<?php
// /modules/zoe/index.php
$currentModule = 'zoe';
require_once '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-purple-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-purple-600 rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Zoe Intercessory Unit</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light" id="zoeSubtitle">The spiritual powerhouse of Household of David.</p>
            </div>
        </div>
    </div>

    <div id="zoePublicView" class="hidden bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative p-8 md:p-12 text-center max-w-3xl mx-auto animate-fade-in-up">
        <div class="w-20 h-20 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
        </div>
        <h2 class="text-3xl font-display font-bold text-gray-900 mb-4">Submit a Prayer Request</h2>
        <p class="text-gray-600 mb-8">The Zoe Intercessory team is standing by to agree with you in prayer. Your request is secure and will be handled with strict spiritual confidentiality.</p>
        
        <form id="publicPrayerForm" class="space-y-6 text-left">
            <input type="hidden" name="action" value="submit_prayer_request">
            <textarea name="request_text" rows="5" required placeholder="Type your prayer request here..." class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-purple-600 resize-none bg-gray-50 focus:bg-white transition-all"></textarea>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white px-6 py-4 rounded-xl font-bold transition-all shadow-lg text-lg flex justify-center items-center gap-2">Send to Zoe Team</button>
        </form>
    </div>

    <div id="zoeAdminView" class="hidden bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative min-h-[600px] flex flex-col">
        
        <div class="border-b border-gray-100/80 bg-gray-50/30 px-6 pt-2">
            <nav class="flex space-x-8 overflow-x-auto custom-scrollbar" aria-label="Tabs">
                <button onclick="switchTab('sessions')" id="tab-btn-sessions" class="whitespace-nowrap py-4 px-2 border-b-2 border-purple-600 font-bold text-sm text-purple-600 transition-all">Prayer Sessions & Campaigns</button>
                <button onclick="switchTab('war_room')" id="tab-btn-war_room" class="whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">The War Room</button>
                <button onclick="switchTab('requests')" id="tab-btn-requests" class="whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Church Requests</button>
                <button onclick="switchTab('roster')" id="tab-btn-roster" class="whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Zoe Roster</button>
            </nav>
        </div>

        <div id="tab-content-sessions" class="p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Upcoming Sessions</h3>
                <div class="space-x-2 admin-only-btn hidden">
                    <button onclick="openModal('campaignModal')" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-xl text-sm font-bold shadow-sm">New Campaign</button>
                    <button onclick="openModal('sessionModal')" class="bg-purple-600 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-sm">Schedule Session</button>
                </div>
            </div>

            <div id="sessionListView" class="space-y-4"></div>

            <div id="sessionDetailView" class="hidden bg-gray-50 p-6 rounded-3xl border border-gray-200">
                <button onclick="closeSessionDetails()" class="text-purple-600 font-bold text-sm mb-4 flex items-center gap-1 hover:underline"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back to Sessions</button>
                
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <div>
                        <h2 id="detailSessionTitle" class="text-2xl font-black text-gray-900"></h2>
                        <p id="detailSessionMeta" class="text-gray-500 text-sm mt-1"></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openModal('attendanceModal')" class="admin-only-btn hidden bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-xl text-sm font-bold shadow-sm">Mark Roll Call</button>
                        <button onclick="openModal('topicModal')" class="admin-only-btn hidden bg-purple-600 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-sm">Assign Topic</button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-sm text-gray-600 min-w-[600px]">
                            <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase text-[10px] tracking-wider border-b border-gray-100">
                                <tr><th class="px-6 py-4">Time Slot</th><th class="px-6 py-4">Topic / Prayer Points</th><th class="px-6 py-4">Assigned Leader</th><th class="px-6 py-4 text-right">Action</th></tr>
                            </thead>
                            <tbody id="topicsTableBody" class="divide-y divide-gray-50"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <div id="tab-content-war_room" class="hidden p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-4 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar" id="warRoomFeed"></div>
                <div class="bg-gray-50 p-6 rounded-3xl border border-gray-200 h-fit">
                    <h3 class="font-bold text-gray-900 mb-4 border-b border-gray-200 pb-2">Drop a Prompting</h3>
                    <form id="warRoomForm" class="space-y-4">
                        <input type="hidden" name="action" value="post_war_room">
                        <select name="post_type" class="w-full px-4 py-2 rounded-xl border border-gray-200 outline-none bg-white font-bold text-purple-700">
                            <option value="General_Note">General Note</option>
                            <option value="Scripture">Scripture Received</option>
                            <option value="Vision">Prophetic Vision</option>
                            <option value="Word_of_Knowledge">Word of Knowledge</option>
                        </select>
                        <textarea name="message" rows="4" required placeholder="What is the Spirit laying on your heart?" class="w-full px-4 py-2 rounded-xl border border-gray-200 outline-none focus:border-purple-600 resize-none bg-white"></textarea>
                        <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-4 py-3 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Post to War Room</button>
                    </form>
                </div>
            </div>
        </div>

        <div id="tab-content-requests" class="hidden p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr><th class="px-6 py-4">Submitted By</th><th class="px-6 py-4 w-1/2">Prayer Request</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Action</th></tr>
                    </thead>
                    <tbody id="requestsTableBody" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>

        <div id="tab-content-roster" class="hidden p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Active Intercessors</h3>
                <button onclick="openModal('volunteerModal')" class="admin-only-btn hidden bg-purple-600 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-sm">Add Volunteer</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="rosterGrid"></div>
        </div>

    </div>
</div>

<div id="campaignModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="font-bold text-gray-900">Create Prayer Campaign</h3>
            <button onclick="closeModal('campaignModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="campaignForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_campaign">
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Campaign Title *</label><input type="text" name="title" required placeholder="e.g., 21 Days of Fasting" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900"></div>
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Theme / Description</label><textarea name="theme_description" rows="3" placeholder="Campaign focus..." class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none resize-none focus:border-purple-600 bg-gray-50 focus:bg-white font-medium text-gray-900"></textarea></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Start Date *</label><input type="date" name="start_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-text"></div>
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">End Date *</label><input type="date" name="end_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-text"></div>
                </div>
            </div>
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Save Campaign</button>
            </div>
        </form>
    </div>
</div>

<div id="sessionModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-xl font-bold text-gray-900">Schedule Prayer Session</h3>
            <button onclick="closeModal('sessionModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="sessionForm" enctype="multipart/form-data" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_session">
            
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Session Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Friday Vigil" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900">
                </div>
                
                <div class="grid grid-cols-3 gap-4">
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Date *</label><input type="date" name="session_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 text-sm cursor-text"></div>
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Start Time *</label><input type="time" name="start_time" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-text"></div>
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">End Time *</label><input type="time" name="end_time" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-text"></div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Type *</label>
                        <select name="meeting_type" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-pointer">
                            <option value="In-Person">In-Person</option>
                            <option value="Virtual">Virtual Link</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Location or Meeting Link *</label>
                        <input type="text" name="location_or_link" required placeholder="Address or Zoom URL" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900">
                    </div>
                </div>

                <div class="border border-dashed border-gray-300 rounded-xl p-4 bg-gray-50">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Upload Prayer Bulletin (PDF) - Optional</label>
                    <input type="file" name="bulletin_pdf" accept=".pdf" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 cursor-pointer">
                </div>
            </div>

            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Schedule Session</button>
            </div>
        </form>
    </div>
</div>

<div id="topicModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="font-bold text-gray-900">Assign Prayer Topic</h3>
            <button onclick="closeModal('topicModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="topicForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_topic">
            <input type="hidden" name="session_id" id="topicSessionId">
            
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Topic Title *</label><input type="text" name="topic_title" required placeholder="e.g., Divine Protection" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900"></div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Prayer Points (Paste from WhatsApp) *</label>
                    <textarea name="prayer_points" rows="4" required placeholder="Paste the full prayer requests here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none resize-none focus:border-purple-600 bg-gray-50 focus:bg-white font-medium text-gray-900"></textarea>
                </div>
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Supporting Bible Reference</label><input type="text" name="bible_reference" placeholder="e.g., Psalm 91:1-4" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-purple-700"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Allocated Shift</label><input type="text" name="allocated_time" placeholder="12:00am - 1:00am" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900"></div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Assign Member</label>
                        <select name="assigned_user_id" id="topicAssignSelect" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-pointer"></select>
                    </div>
                </div>
            </div>
            
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Lock In Topic & Assign</button>
            </div>
        </form>
    </div>
</div>

<div id="readerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/95 backdrop-blur-xl hidden z-[100000] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b border-purple-100 flex justify-between items-center bg-purple-50 shrink-0">
            <h3 id="readerTitle" class="text-xl md:text-2xl font-black text-purple-900">Prayer Focus</h3>
            <button onclick="closeModal('readerModal')" class="text-gray-400 hover:text-gray-900 bg-white p-2 rounded-full shadow-sm transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 md:p-10 overflow-y-auto custom-scrollbar flex-1 bg-white">
            <div id="readerBible" class="mb-6"></div>
            <div id="readerPoints" class="text-lg md:text-xl text-gray-800 leading-relaxed font-medium whitespace-pre-wrap"></div>
        </div>
    </div>
</div>

<div id="attendanceModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="font-bold text-gray-900">Manual Roll Call</h3>
            <button onclick="closeModal('attendanceModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 overflow-y-auto flex-1 bg-white custom-scrollbar">
            <div id="attendanceListContainer" class="space-y-2"></div>
        </div>
        <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
            <button onclick="submitAttendance()" class="w-full bg-gray-900 hover:bg-black text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Save Official Attendance</button>
        </div>
    </div>
</div>

<div id="volunteerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="font-bold text-gray-900">Add Prayer Volunteer</h3>
            <button onclick="closeModal('volunteerModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="volunteerForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="add_to_roster">
            <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Select Church Member</label>
                    <select name="user_id" id="volunteerSelect" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-gray-900 cursor-pointer"></select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Role Type</label>
                    <select name="roster_type" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-purple-600 bg-gray-50 focus:bg-white font-bold text-purple-700 cursor-pointer">
                        <option value="Volunteer">Volunteer</option>
                        <option value="Official_Worker">Official Worker</option>
                    </select>
                </div>
            </div>
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center">Add to Roster</button>
            </div>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[100000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '../../api/zoe_api.php';
    const DEPT_API_URL = '../../api/department_api.php'; // Needed to fetch all users for the Volunteer dropdown
    let isAdmin = false;
    let currentSessionId = null;
    let currentTopics = [];

    // --- UI Controls (UPGRADED) ---
    function switchTab(tabId) {
        $('[id^="tab-btn-"]').removeClass('border-purple-600 text-purple-600').addClass('border-transparent text-gray-500 hover:text-gray-700');
        $(`#tab-btn-${tabId}`).removeClass('border-transparent text-gray-500 hover:text-gray-700').addClass('border-purple-600 text-purple-600');
        $('[id^="tab-content-"]').addClass('hidden').removeClass('animate-fade-in-up');
        setTimeout(() => { $(`#tab-content-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up'); }, 10);
    }

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
                // Only restore body scroll if NO modals are actively open
                if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                    document.body.style.overflow = ''; 
                }
            }, 300);
        }
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
            // Unlock scroll only if no other fixed modals are visible
            if ($('.fixed.inset-0:not(.hidden):not(#globalActionBlocker)').length === 0) {
                document.body.style.overflow = '';
            }
            const f = modal.querySelector('form'); 
            if(f) f.reset(); 
        }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({ 
            text: msg, 
            gravity: "top", 
            position: "center", 
            duration: 3000,
            style: { 
                background: type === 'success' ? "#10B981" : "#EF4444", 
                borderRadius: "10px", 
                fontWeight: "bold",
                boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
            } 
        }).showToast();
    }

    // --- Master Loader ---
    function loadZoeData() {
        $.post(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                $('#zoeAdminView').removeClass('hidden');
                isAdmin = res.is_admin;
                if(isAdmin) $('.admin-only-btn').removeClass('hidden');

                // 1. Sessions List
                let sHtml = '';
                res.sessions.forEach(s => {
                    let d = new Date(s.session_date).toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'short'});
                    let badge = s.meeting_type === 'Virtual' ? 'bg-blue-50 text-blue-600' : 'bg-orange-50 text-orange-600';
                    sHtml += `
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center p-5 bg-gray-50 rounded-2xl border border-gray-100 gap-4">
                        <div>
                            <h4 class="font-bold text-gray-900">${s.title}</h4>
                            <p class="text-sm text-gray-500 font-medium">${d} • ${s.start_time} - ${s.end_time} <span class="ml-2 px-2 py-0.5 rounded text-[10px] uppercase font-bold ${badge}">${s.meeting_type}</span></p>
                        </div>
                        <button onclick="openSessionDetails(${s.id})" class="bg-white border border-gray-200 text-purple-600 hover:bg-purple-50 px-4 py-2 rounded-xl text-sm font-bold shadow-sm transition">View Topics & Details</button>
                    </div>`;
                });
                $('#sessionListView').html(sHtml || '<p class="text-gray-500">No upcoming sessions.</p>');

                // 2. War Room Feed
                let wHtml = '';
                res.war_room.forEach(w => {
                    let wBadge = '';
                    if(w.post_type==='Vision') wBadge = 'bg-purple-100 text-purple-700';
                    else if(w.post_type==='Scripture') wBadge = 'bg-blue-100 text-blue-700';
                    else if(w.post_type==='Word_of_Knowledge') wBadge = 'bg-orange-100 text-orange-700';
                    else wBadge = 'bg-gray-200 text-gray-700';

                    wHtml += `
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                        <div class="flex justify-between items-center mb-2"><span class="font-bold text-gray-900 text-sm">${w.first_name} ${w.last_name}</span><span class="text-[10px] px-2 py-1 rounded font-bold uppercase tracking-wider ${wBadge}">${w.post_type.replace(/_/g, ' ')}</span></div>
                        <p class="text-gray-700 italic">"${w.message}"</p>
                    </div>`;
                });
                $('#warRoomFeed').html(wHtml || '<p class="text-gray-500">The War Room is quiet.</p>');

                // 3. Church Requests
                let rHtml = '';
                res.requests.forEach(r => {
                    let statClass = r.status === 'Pending' ? 'bg-yellow-50 text-yellow-600' : (r.status === 'Active_Prayer' ? 'bg-purple-50 text-purple-600' : 'bg-green-50 text-green-600');
                    let act = isAdmin && r.status !== 'Answered_Testimony' ? `<select onchange="updateRequestStatus(${r.id}, this.value)" class="text-xs font-bold border border-gray-200 rounded-lg px-2 py-1 outline-none bg-white cursor-pointer"><option value="Pending" ${r.status==='Pending'?'selected':''}>Pending</option><option value="Active_Prayer" ${r.status==='Active_Prayer'?'selected':''}>Active Prayer</option><option value="Answered_Testimony">Mark as Testimony</option></select>` : '';
                    
                    rHtml += `<tr><td class="px-6 py-4 font-bold text-gray-900">${r.first_name} ${r.last_name}<br><span class="text-xs font-normal text-gray-500">${r.phone}</span></td><td class="px-6 py-4 text-gray-700 font-medium">"${r.request_text}"</td><td class="px-6 py-4"><span class="text-[10px] px-2 py-1 rounded font-bold uppercase ${statClass}">${r.status.replace(/_/g, ' ')}</span></td><td class="px-6 py-4 text-right">${act}</td></tr>`;
                });
                $('#requestsTableBody').html(rHtml || '<tr><td colspan="4" class="text-center py-6 text-gray-400">No prayer requests pending.</td></tr>');

                // 4. Roster
                let rosHtml = ''; let rosOpts = '<option value="">Select Zoe Member...</option>';
                res.roster.forEach(r => {
                    let rBadge = r.roster_type === 'Official_Worker' ? 'bg-purple-50 text-purple-700 border border-purple-100' : 'bg-gray-100 text-gray-600 border border-gray-200';
                    let remBtn = isAdmin && r.roster_type === 'Volunteer' ? `<button onclick="removeVolunteer(${r.id})" class="text-xs font-bold text-red-500 hover:underline mt-2">Remove Volunteer</button>` : '';
                    
                    rosOpts += `<option value="${r.user_id}">${r.first_name} ${r.last_name}</option>`;
                    rosHtml += `<div class="p-5 border border-gray-100 bg-white rounded-2xl flex justify-between items-center shadow-sm"><div><h4 class="font-bold text-gray-900">${r.first_name} ${r.last_name}</h4><span class="text-[10px] px-2 py-0.5 rounded uppercase font-bold ${rBadge} mt-1 inline-block">${r.roster_type.replace('_', ' ')}</span></div>${remBtn}</div>`;
                });
                $('#rosterGrid').html(rosHtml); $('#topicAssignSelect').html(rosOpts);

            } else if (res.message && res.message.includes('Access Denied')) {
                // FALLBACK: Non-Member Public View
                $('#zoePublicView').removeClass('hidden');
                $('#zoeSubtitle').text('Submit a prayer request securely.');
            }
        }, 'json');

        // Fetch All Users for Volunteer Dropdown (Uses Dept API generic fetch)
        if(isAdmin) {
            $.post(DEPT_API_URL, {action: 'fetch_dashboard'}, function(dRes) {
                if(dRes.status === 'success') {
                    let uOpts = '<option value="">Select Member...</option>';
                    dRes.users.forEach(u => uOpts += `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`);
                    $('#volunteerSelect').html(uOpts);
                }
            }, 'json');
        }
    }

    // --- Session Detail & Topics ---
    function openSessionDetails(id) {
        currentSessionId = id;
        $('#topicSessionId').val(id);
        
        $.post(API_URL, { action: 'fetch_session_details', session_id: id }, function(res) {
            if(res.status === 'success') {
                $('#sessionListView').addClass('hidden');
                $('#sessionDetailView').removeClass('hidden').addClass('animate-fade-in-up');
                
                $('#detailSessionTitle').text(res.session.title);
                
                // Format the PDF link
                let pdfLnk = res.session.bulletin_pdf_url ? ` • <a href="${res.session.bulletin_pdf_url}" target="_blank" class="text-purple-600 font-bold hover:underline">Download PDF</a>` : '';
                
                // Format the Location/Google Meet Link to be clickable if it's a URL or marked as Virtual
                let loc = res.session.location_or_link;
                let locDisplay = loc;
                
                if (res.session.meeting_type === 'Virtual' || loc.startsWith('http')) {
                    // Auto-prepend https:// if the user omitted it
                    let href = loc.startsWith('http') ? loc : `https://${loc}`;
                    locDisplay = `<a href="${href}" target="_blank" class="text-purple-600 hover:underline font-bold break-all">${loc}</a>`;
                }
                
                $('#detailSessionMeta').html(`${new Date(res.session.session_date).toLocaleDateString()} | <span class="font-bold text-gray-700">${res.session.meeting_type}</span>: ${locDisplay} ${pdfLnk}`);
                // Topics Table
                let tHtml = '';
                currentTopics = res.topics; // This helps saves to memory for the Reader Modal

                res.topics.forEach(t => {
                    let asn = t.assigned_user_id ? `${t.first_name} ${t.last_name}` : '<span class="text-gray-400 italic">Unassigned</span>';
                    let del = isAdmin ? `<button onclick="deleteTopic(${t.id})" class="text-red-600 font-bold text-xs bg-red-50 border border-red-100 px-3 py-1.5 rounded-lg hover:bg-red-600 hover:text-white transition-colors">Delete</button>` : '';
                    
                    // The new Read Button
                    let readBtn = `<button onclick="openPrayerReader(${t.id})" class="text-sm bg-purple-100 text-purple-700 hover:bg-purple-600 hover:text-white px-4 py-2 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 mt-3"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg> Read Prayer Points</button>`;
                    
                    tHtml += `<tr>
                        <td class="px-6 py-4 font-bold text-purple-600 whitespace-nowrap align-top">${t.allocated_time || '-'}</td>
                        <td class="px-6 py-4 align-top">
                            <p class="font-bold text-gray-900 text-base">${t.topic_title}</p>
                            ${readBtn}
                        </td>
                        <td class="px-6 py-4 font-bold text-gray-700 whitespace-nowrap align-top">${asn}</td>
                        <td class="px-6 py-4 text-right align-top">${del}</td>
                    </tr>`;
                });
                // Inject the generated rows into the table, with a fallback for empty states
                $('#topicsTableBody').html(tHtml || '<tr><td colspan="4" class="text-center py-6 text-gray-400 font-medium">No prayer topics assigned for this session yet.</td></tr>');

                // Build Attendance Form
                let attHtml = '';
                res.attendance_list.forEach(a => {
                    let chk = a.current_status === 'Present' ? 'checked' : '';
                    attHtml += `<label class="flex justify-between items-center p-4 bg-gray-50 border border-gray-100 rounded-xl cursor-pointer hover:bg-white hover:border-purple-200 transition-colors"><span class="font-bold text-gray-900 text-sm">${a.first_name} ${a.last_name}</span><input type="checkbox" class="att-checkbox w-5 h-5 text-purple-600 rounded border-gray-300 focus:ring-purple-500 cursor-pointer" data-uid="${a.user_id}" ${chk}></label>`;
                });
                $('#attendanceListContainer').html(attHtml || '<p class="text-center text-gray-400 italic text-sm py-4">No roster members found.</p>');
            }
        }, 'json');
    }

    function closeSessionDetails() {
        currentSessionId = null;
        $('#sessionDetailView').addClass('hidden');
        $('#sessionListView').removeClass('hidden');
        loadZoeData();
    }
    
    function openPrayerReader(topicId) {
        const topic = currentTopics.find(t => t.id === topicId);
        if (!topic) return;

        $('#readerTitle').text(topic.topic_title);
        
        // Render Bible Verse if it exists
        if (topic.bible_reference) {
            $('#readerBible').html(`<span class="inline-flex items-center gap-1.5 bg-purple-100 text-purple-800 px-4 py-2 rounded-xl text-sm font-bold border border-purple-200"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg> ${topic.bible_reference}</span>`);
        } else {
            $('#readerBible').html('');
        }

        // Render Prayer Points safely formatting line breaks
        $('#readerPoints').html(topic.prayer_points.replace(/\n/g, '<br>'));
        
        openModal('readerModal');
    }

    // --- Dynamic Actions ---
    function deleteTopic(id) {
        if(!confirm('Delete this prayer topic?')) return;
        lockScreenAction();
        $.post(API_URL, {action: 'delete_topic', topic_id: id}, function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if(res.status === 'success') openSessionDetails(currentSessionId);
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    function submitAttendance() {
        const btn = $('#attendanceModal button'); 
        btn.prop('disabled', true).text('Saving...');
        lockScreenAction();
        
        let data = {};
        $('.att-checkbox').each(function() { data[$(this).data('uid')] = $(this).is(':checked') ? 'Present' : 'Absent'; });
        
        $.post(API_URL, { action: 'mark_attendance', session_id: currentSessionId, attendance_data: JSON.stringify(data) }, function(res) {
            btn.prop('disabled', false).text('Save Official Attendance');
            unlockScreenAction();
            
            showToast(res.message, res.status);
            if(res.status === 'success') closeModal('attendanceModal');
        }, 'json').fail(function() {
            btn.prop('disabled', false).text('Save Official Attendance');
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    function updateRequestStatus(id, stat) {
        lockScreenAction();
        $.post(API_URL, {action: 'update_request_status', request_id: id, status: stat}, function(res){
            unlockScreenAction();
            showToast(res.message, res.status);
            loadZoeData();
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    function removeVolunteer(id) {
        if(!confirm('Remove this volunteer from the Zoe roster?')) return;
        lockScreenAction();
        $.post(API_URL, {action: 'remove_from_roster', roster_id: id}, function(res){
            unlockScreenAction();
            showToast(res.message, res.status);
            loadZoeData();
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    // --- Master Init ---
    $(document).ready(function() {
        loadZoeData();

        function bindAjaxForm(formId, modalId = null) {
            $(`#${formId}`).on('submit', function(e) {
                e.preventDefault();
                const btn = $(this).find('button[type="submit"]');
                const orig = btn.html(); 
                
                btn.prop('disabled', true).html('Processing...');
                lockScreenAction(); // Apply screen lock for form submits
                
                $.ajax({
                    url: API_URL, 
                    type: 'POST', 
                    data: new FormData(this), 
                    contentType: false, 
                    processData: false, 
                    dataType: 'json',
                    success: function(res) {
                        btn.prop('disabled', false).html(orig);
                        unlockScreenAction(); // Unlock screen
                        
                        showToast(res.message, res.status);
                        
                        if(res.status === 'success') { 
                            $(`#${formId}`)[0].reset(); 
                            if(modalId) closeModal(modalId); 
                            if(currentSessionId && formId === 'topicForm') openSessionDetails(currentSessionId);
                            else loadZoeData(); 
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(orig);
                        unlockScreenAction();
                        showToast("Server Connection Error", "error");
                    }
                });
            });
        }

        bindAjaxForm('publicPrayerForm');
        bindAjaxForm('warRoomForm');
        bindAjaxForm('sessionForm', 'sessionModal');
        bindAjaxForm('topicForm', 'topicModal');
        bindAjaxForm('volunteerForm', 'volunteerModal');
        bindAjaxForm('campaignForm', 'campaignModal');
    });
</script>

<?php require_once '../../includes/footer.php'; ?>