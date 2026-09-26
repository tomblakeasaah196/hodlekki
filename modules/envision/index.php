<?php
// /modules/envision/index.php
require_once '../../includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">
    
    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-cyan-50/80 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-cyan-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            </div>
            <div class="md:max-w-[75%] lg:max-w-sm">
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Envision Media</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Manage sermon archives, media duties, audio uploads, and public broadcasts.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <button onclick="openTeamModal()" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:text-cyan-700 hover:border-cyan-300 hover:bg-cyan-50 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                Add Team Member
            </button>
            <button onclick="openSermonModal()" class="flex-1 md:flex-none bg-cyan-600 hover:bg-cyan-800 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-cyan-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Archive Sermon
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Total Archived</p><h3 id="statTotal" class="text-2xl font-black text-gray-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-cyan-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-cyan-500 uppercase tracking-widest mb-1">Published Live</p><h3 id="statPublished" class="text-2xl font-black text-cyan-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-cyan-50 flex items-center justify-center text-cyan-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-purple-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-purple-500 uppercase tracking-widest mb-1">Audio Downloads</p><h3 id="statDownloads" class="text-2xl font-black text-purple-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-purple-50 flex items-center justify-center text-purple-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg></div>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-orange-100 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-orange-500 uppercase tracking-widest mb-1">Pending Comments</p><h3 id="statComments" class="text-2xl font-black text-orange-900">0</h3></div>
            <div class="w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center text-orange-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg></div>
        </div>
    </div>

    <div class="flex flex-wrap bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-2xl animate-fade-in-up" style="animation-delay: 0.2s;">
        <button onclick="switchTab('archive')" id="tabBtn-archive" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-cyan-700 shadow-sm">Sermon Archive</button>
        <button onclick="switchTab('roster')" id="tabBtn-roster" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Crew Roster</button>
        <button onclick="switchTab('comments'); loadComments(1);" id="tabBtn-comments" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Moderation</button>
        <button onclick="switchTab('config'); loadRadioConfig();" id="tabBtn-config" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Broadcast Config</button>
    </div>

    <div id="view-archive" class="animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[400px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Sermon Details</th>
                            <th class="px-6 py-4">Media Availability</th>
                            <th class="px-6 py-4 text-center">Crew Logged</th>
                            <th class="px-6 py-4 text-center">Public Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sermonsList" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium">Syncing sermon archive...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-roster" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="rosterGrid"></div>
    </div>
    
    <div id="view-comments" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Live Reflections</h3>
                    <p class="text-xs text-gray-500 mt-1">Search for sensitive keywords or names to moderate.</p>
                </div>
                <div class="w-full max-w-xs relative">
                    <input type="text" id="commentSearch" placeholder="Filter by word (e.g., scam, spam...)" class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-100 transition-all">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3.5 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Author & Date</th>
                            <th class="px-6 py-4 w-1/2">Comment / Reflection</th>
                            <th class="px-6 py-4">Sermon</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="commentsList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>

            <div class="mt-6 flex justify-between items-center border-t border-gray-100 pt-4">
                <span id="commentMeta" class="text-xs font-bold text-gray-500 uppercase tracking-widest">Showing 0 Records</span>
                <div class="flex gap-2" id="commentPagination"></div>
            </div>
        </div>
    </div>
        <div id="view-config" class="hidden animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h3 class="text-xl font-display font-bold text-gray-900">Live Audio Broadcast</h3>
                    <p class="text-sm text-gray-500 mt-1">Manage the Caster.fm stream integration for the public sermons - Managed by Envision.</p>
                </div>
                <button onclick="openModal('guideModal')" class="bg-cyan-50 text-cyan-700 hover:bg-cyan-100 px-4 py-2 rounded-xl text-sm font-bold transition-colors flex items-center gap-2 border border-cyan-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Read Setup Guide
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Config Form -->
                <div class="bg-gray-50 p-6 rounded-2xl border border-gray-100">
                    <h4 class="text-sm font-bold text-gray-900 mb-4 uppercase tracking-wider">Stream Settings</h4>
                    <form id="radioConfigForm" class="space-y-4">
                        <input type="hidden" name="action" value="save_radio_config">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Caster.fm Stream URL *</label>
                            <input type="url" name="zeno_stream_url" id="inpZenoUrl" required placeholder="http://shoutcast.caster.fm:PORT/listen.mp3" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-medium text-gray-900 bg-white shadow-sm">
                            <p class="text-[10px] text-gray-400 mt-1.5">Paste your public Caster.fm stream link here.</p>
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="flex-1 bg-gray-900 hover:bg-black text-white px-4 py-3 rounded-xl font-bold shadow-md transition-all">Save URL</button>
                            <button type="button" onclick="testRadioConnection()" id="btnTestRadio" class="bg-white border border-gray-200 text-gray-700 hover:text-cyan-700 hover:border-cyan-300 px-4 py-3 rounded-xl font-bold transition-all shadow-sm">Test Connection</button>
                        </div>
                    </form>
                </div>

                <!-- Master Toggle -->
                <div class="bg-cyan-50/40 p-6 rounded-2xl border border-cyan-100 flex flex-col justify-center items-center text-center">
                    <h4 class="text-sm font-bold text-cyan-900 mb-2 uppercase tracking-wider">Master Broadcast Switch</h4>
                    <p class="text-xs text-cyan-700 mb-6 max-w-xs">Turn this ON to display the live popup player on the Sermons page.</p>
                    
                    <button onclick="toggleMasterRadio()" id="btnMasterRadio" class="relative w-32 h-14 rounded-full transition-colors duration-300 focus:outline-none bg-gray-300 flex items-center p-1 shadow-inner">
                        <div id="radioToggleKnob" class="bg-white w-12 h-12 rounded-full shadow-md transform transition-transform duration-300 translate-x-0 flex items-center justify-center">
                            <svg class="w-6 h-6 text-gray-400" id="radioToggleIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" clip-rule="evenodd"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
                        </div>
                    </button>
                    <span id="radioStatusText" class="mt-4 font-black text-gray-500 uppercase tracking-widest text-sm">OFFLINE</span>
                </div>
            </div>
        </div>
    </div>

</div>

<div id="sermonModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white rounded-t-3xl shrink-0">
            <h3 id="sermonModalTitle" class="text-xl font-display font-bold text-gray-900">Archive Sermon & Media</h3>
            <button onclick="closeModal('sermonModal')" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-gray-50/50">
            <form id="sermonForm" class="space-y-6" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_sermon">
                <input type="hidden" name="sermon_id" id="inpSermonId">
                <input type="hidden" name="existing_cover" id="inpExistingCover">
                <input type="hidden" name="existing_audio" id="inpExistingAudio">

                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm space-y-4">
                    <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-widest border-b border-gray-100 pb-2">Core Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Sermon Title *</label>
                            <input type="text" name="title" id="inpTitle" required placeholder="e.g., The Power of Vision" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Preacher *</label>
                            <input type="text" name="preacher" id="inpPreacher" required placeholder="e.g., Pastor Ebele Uzo-Peters" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-bold text-gray-900">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Date Preached *</label>
                            <input type="date" name="date_preached" id="inpDate" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-bold text-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Service Type</label>
                            <select name="service_type" id="inpType" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-bold text-gray-900 bg-white">
                                <option value="Total Experience">Total Experience (Sunday)</option>
                                <option value="Mercy Experience">Mercy Experience (Thursday)</option>
                                <option value="Special Program">Special Program / Conference</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-cyan-50/30 p-5 rounded-2xl border border-cyan-100 space-y-4">
                    <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-widest border-b border-cyan-100 pb-2">Media Assets</h4>
                    
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">YouTube Link (Video)</label>
                        <input type="url" name="youtube_link" id="inpYoutube" placeholder="https://youtube.com/watch?v=..." class="w-full px-4 py-3 border border-cyan-200 bg-white rounded-xl focus:border-cyan-500 outline-none font-medium text-gray-900 text-sm shadow-sm">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-xl border border-cyan-200 shadow-sm">
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Cover Graphic (Image)</label>
                            <input type="file" name="cover_image" accept="image/*" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all cursor-pointer">
                            <p id="existingCoverMsg" class="text-[10px] text-green-600 font-bold mt-2 hidden">✓ Cover already uploaded</p>
                        </div>
                        <div class="bg-white p-4 rounded-xl border border-cyan-200 shadow-sm">
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Raw Audio (.mp3, .wav)</label>
                            <input type="file" name="audio_file" accept="audio/*" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all cursor-pointer">
                            <p id="existingAudioMsg" class="text-[10px] text-green-600 font-bold mt-2 hidden">✓ Audio already uploaded</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm space-y-4">
                    <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-widest border-b border-gray-100 pb-2">Sermon Notes</h4>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Short Summary (Excerpt)</label>
                        <textarea name="summary" id="inpSummary" rows="2" placeholder="A brief 1-2 sentence description..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-medium text-gray-900 resize-none"></textarea>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Full Sermon Notes</label>
                        <textarea name="full_notes" id="inpNotes" rows="5" placeholder="Detailed notes, scriptures, and breakdown..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-cyan-500 outline-none font-medium text-gray-900 resize-none"></textarea>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="bg-white border-t border-gray-100 shrink-0">
            <div id="uploadProgressContainer" class="hidden px-6 pt-4 pb-2">
                <div class="flex justify-between items-center mb-2">
                    <span id="progressStatus" class="text-[10px] font-bold text-cyan-700 uppercase tracking-widest">Uploading Media...</span>
                    <span id="progressPercent" class="text-[10px] font-bold text-cyan-700">0%</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden border border-gray-200 shadow-inner">
                    <div id="progressBar" class="bg-gradient-to-r from-cyan-400 to-cyan-600 h-full w-0 transition-all duration-300 shadow-[0_0_10px_rgba(6,182,212,0.5)]"></div>
                </div>
            </div>
            
            <div class="p-6">
                <button type="submit" form="sermonForm" id="btnSaveSermon" class="w-full bg-cyan-600 hover:bg-cyan-800 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    <span>Upload & Archive Sermon</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div id="dutyModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Log Service Duty</h3>
            <button onclick="closeModal('dutyModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar">
            <p id="dutySermonTitle" class="text-sm font-black text-cyan-800 text-center mb-4"></p>
            
            <form id="dutyForm" class="space-y-5">
                <input type="hidden" name="action" value="assign_duty">
                <input type="hidden" name="sermon_id" id="dutySermonId">
                
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Duty Role *</label>
                    <select name="duty_role" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-cyan-500 outline-none bg-white">
                        <option value="Videography">Videography / Camera</option>
                        <option value="Photography">Photography</option>
                        <option value="Audio_Engineering">Audio / Sound Engineering</option>
                        <option value="Projection">Projection / Screen</option>
                        <option value="Lighting">Lighting</option>
                        <option value="Note_Taking">Service Note Taker</option>
                        <option value="Direction">Media Director</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Assign Team Member *</label>
                    <select name="user_id" id="inpDutyUser" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-cyan-500 outline-none bg-white"></select>
                </div>
                <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all mt-2">Log Duty Roster</button>
            </form>
            
            <div class="mt-6 border-t border-gray-100 pt-4">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Currently Logged Crew</h4>
                <ul id="loggedCrewList" class="space-y-2 text-sm max-h-32 overflow-y-auto custom-scrollbar"></ul>
            </div>
        </div>
    </div>
</div>

<div id="teamModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh] overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Add to Envision Roster</h3>
            <button onclick="closeModal('teamModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar">
            <form id="teamForm" class="space-y-5">
                <input type="hidden" name="action" value="assign_member">
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Available Member (In Envision Dept)</label>
                    <select name="user_id" id="inpTeamUser" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-cyan-500 outline-none bg-white"></select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Primary Specialization</label>
                    <select name="role_category" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-cyan-500 outline-none bg-white">
                        <option value="Videography">Videography</option>
                        <option value="Photography">Photography</option>
                        <option value="Audio_Engineering">Audio Engineering</option>
                        <option value="Projection">Projection</option>
                        <option value="Lighting">Lighting</option>
                        <option value="Social_Media">Social Media / Broadcast</option>
                    </select>
                </div>
                <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-800 text-white px-6 py-4 rounded-xl font-bold shadow-md transition-all mt-2">Add to Specialized Roster</button>
            </form>
        </div>
    </div>
</div>

<div id="reassignModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Change Role</h3>
            <button onclick="closeModal('reassignModal')" class="text-gray-400 hover:text-red-500 p-1.5 rounded-full transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="p-6">
            <p class="text-sm text-gray-500 mb-4 text-center">Reassigning <span id="reassignNameDisplay" class="font-bold text-gray-900"></span></p>
            <form id="reassignForm" class="space-y-5">
                <input type="hidden" name="action" value="assign_member">
                <input type="hidden" name="user_id" id="inpReassignUserId">
                
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">New Specialization</label>
                    <select name="role_category" id="inpReassignRole" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-cyan-500 outline-none bg-white">
                        <option value="Videography">Videography</option>
                        <option value="Photography">Photography</option>
                        <option value="Audio_Engineering">Audio Engineering</option>
                        <option value="Projection">Projection</option>
                        <option value="Lighting">Lighting</option>
                        <option value="Social_Media">Social Media / Broadcast</option>
                    </select>
                </div>
                <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-800 text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all mt-2">Update Role</button>
            </form>
        </div>
    </div>
</div>

<div id="guideModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[90vh] overflow-hidden">
        
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Zeno.fm + OBS Setup Guide
            </h3>
            <button onclick="closeModal('guideModal')" class="text-gray-400 hover:text-red-500 bg-white p-1.5 rounded-full shadow-sm transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-6 md:p-8 custom-scrollbar space-y-8 text-sm text-gray-600">
            
            <div class="bg-cyan-50/50 p-4 rounded-xl border border-cyan-100 text-cyan-800">
                <p class="font-bold mb-1">Welcome to the HOD Audio Broadcast System!</p>
                <p class="text-xs">Follow these steps exactly to ensure a stable, copyright-safe, and free audio stream for the congregation.</p>
            </div>

            <!-- Step 1 -->
            <div>
                <h4 class="font-bold text-gray-900 text-base mb-2 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs">1</span> 
                    Create & Configure Caster.fm
                </h4>
                <ul class="list-disc list-inside space-y-1.5 ml-8 text-gray-500">
                    <li>Go to <a href="https://caster.fm" target="_blank" class="text-cyan-600 font-bold hover:underline">Caster.fm</a> and sign up for the <strong>Free Plan</strong> (No credit card required).</li>
                    <li>Log into your new control panel.</li>
                    <li>Click <strong>Start Server</strong> to turn your radio station online.</li>
                </ul>
            </div>

            <!-- Step 2 -->
            <div>
                <h4 class="font-bold text-gray-900 text-base mb-2 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs">2</span> 
                    Link OBS Studio
                </h4>
                <ul class="list-disc list-inside space-y-1.5 ml-8 text-gray-500 mb-3">
                    <li>In the Caster.fm control panel, look for your <strong>Broadcast Settings</strong> (Server IP, Port, Mount, and Password).</li>
                    <li>Open <strong>OBS Studio</strong>, go to <strong>Settings -> Stream</strong>, and set Service to <strong>Custom...</strong>.</li>
                    <li>In the <strong>Server URL</strong> box, type: <code class="bg-gray-100 px-1 rounded text-pink-600">icecast://[SERVER_IP]:[PORT]</code></li>
                    <li>In the <strong>Stream Key</strong> box, type: <code class="bg-gray-100 px-1 rounded text-pink-600">source:[PASSWORD]/[MOUNT]</code></li>
                </ul>
                <div class="ml-8 bg-orange-50 border-l-2 border-orange-400 p-3 rounded-r-lg text-xs text-orange-800">
                    <strong>Crucial Audio Setting:</strong> In OBS, go to <strong>Settings -> Output</strong>. Change the Output Mode to Advanced. Under the Audio tab, ensure the Audio Bitrate is set to <strong>96</strong>. This matches the free tier limit and guarantees a stable stream!
                </div>
            </div>

            <!-- Step 3 -->
            <div>
                <h4 class="font-bold text-gray-900 text-base mb-2 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs">3</span> 
                    Update the Envision Dashboard
                </h4>
                <ul class="list-disc list-inside space-y-1.5 ml-8 text-gray-500">
                    <li>In your Caster.fm control panel, find your public <strong>Listen Link</strong> (it usually ends in <code class="bg-gray-100 px-1 rounded text-pink-600">/listen.mp3</code>).</li>
                    <li>Paste that exact URL into the "Caster.fm Stream URL" box on this Envision Dashboard and click <strong>Save URL</strong>.</li>
                </ul>
            </div>

            <!-- Step 4 -->
            <div>
                <h4 class="font-bold text-gray-900 text-base mb-2 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs">4</span> 
                    Going Live on Sunday!
                </h4>
                <ul class="list-disc list-inside space-y-1.5 ml-8 text-gray-500">
                    <li>In OBS, click <strong>Start Streaming</strong>.</li>
                    <li>Wait 10 seconds. On the Envision Dashboard, click <strong>Test Connection</strong> to verify the audio is reaching the server.</li>
                    <li>If successful, click the <strong>Master Broadcast Switch</strong> to turn it ON.</li>
                    <li>The Live popup player will instantly appear on the public Sermons page for the congregation!</li>
                    <li>When the service ends, click <strong>Stop Streaming</strong> in OBS, and flip the Master Switch back to <strong>OFF</strong>.</li>
                </ul>
            </div>

        </div>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-cyan-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '/api/envision_api.php';
    let globalSermons = [];

    // ==========================================
    // UI CORE LOGIC (UPGRADED)
    // ==========================================
    function switchTab(tabId) {
        $('#view-archive, #view-roster, #view-comments, #view-config').addClass('hidden').removeClass('animate-fade-in-up');
        $('#tabBtn-archive, #tabBtn-roster, #tabBtn-comments, #tabBtn-config').removeClass('bg-white text-cyan-700 shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        
        $(`#view-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up');
        $(`#tabBtn-${tabId}`).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-cyan-700 shadow-sm');
    }

    function lockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; 
        setTimeout(() => blocker.classList.remove('opacity-0'), 10);
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        blocker.classList.add('opacity-0');
        setTimeout(() => {
            blocker.classList.add('hidden');
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
        }, 300);
    }

    function openModal(id) {
        const m = document.getElementById(id);
        if(!m) return;

        document.body.appendChild(m); // Viewport Escape
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Scroll lock

        requestAnimationFrame(() => { 
            m.classList.remove('opacity-0'); 
            m.children[0].classList.remove('scale-95'); 
        });
    }

    function closeModal(id) {
    const m = document.getElementById(id);
    if(!m) return;

    m.classList.add('opacity-0'); 
    m.children[0].classList.add('scale-95'); 
    
    setTimeout(() => { 
        m.classList.add('hidden'); 
        
        // PATCH: Ensure we only unlock the scroll if no other modals/blockers are open
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }
        
        const form = m.querySelector('form'); 
        if(form) form.reset(); 
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

    function handleAjaxForm(formId, successCallback) {
    $(`#${formId}`).on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const origHtml = btn.html(); 
        
        // PATCH: Restore the inline spinner for polished UX
        const spinner = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        
        btn.prop('disabled', true).addClass('opacity-75 cursor-not-allowed').html(spinner + 'Processing...');
        lockScreenAction(); 
        
        $.post(API_URL, $(this).serialize(), function(res) {
            btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
            unlockScreenAction(); 
            
            showToast(res.message, res.status);
            if(res.status === 'success' && successCallback) successCallback(res);
        }, 'json').fail(function(){
            btn.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed').html(origHtml);
            unlockScreenAction();
            showToast("Server error", "error");
        });
    });
}

    // Specialized MULTIPART Form Handler for Sermons (Files) - STRICTLY PRESERVED
    $('#sermonForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#btnSaveSermon');
        const origHtml = btn.html();
        const progContainer = $('#uploadProgressContainer');
        const progBar = $('#progressBar');
        const progText = $('#progressPercent');

        // 1. Show Progress Bar & Lock Button & Screen
        progContainer.removeClass('hidden');
        progBar.css('width', '0%');
        progText.text('0%');
        btn.prop('disabled', true).addClass('opacity-50 cursor-not-allowed').html('Uploading Assets...');
        lockScreenAction();

        const formData = new FormData(this);

        $.ajax({
            url: API_URL + '?action=save_sermon', 
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = Math.round((evt.loaded / evt.total) * 100);
                        progBar.css('width', percentComplete + '%');
                        progText.text(percentComplete + '%');
                        if(percentComplete === 100) {
                            $('#progressStatus').text('Processing on Server...');
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(res) {
                if(typeof res === 'string') {
                    try { res = JSON.parse(res); } 
                    catch(e) { 
                        showToast('Server returned invalid data. File might be too large.', 'error');
                        resetUploadUI();
                        return;
                    }
                }

                if(res.status === 'success') {
                    progBar.removeClass('from-cyan-400 to-cyan-600').addClass('bg-green-500');
                    $('#progressStatus').text('Successfully Archived!').addClass('text-green-600');
                    btn.html('✓ Success').removeClass('bg-cyan-600').addClass('bg-green-600');
                    
                    showToast(res.message, 'success');
                    setTimeout(() => {
                        // PATCH: closeModal now safely handles the scroll unlock.
                        // Removing unlockScreenAction() here prevents the timer collision.
                        closeModal('sermonModal');
                        progContainer.addClass('hidden');
                        btn.html(origHtml).removeClass('bg-green-600').addClass('bg-cyan-600').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
                        
                        document.getElementById('globalActionBlocker').classList.add('hidden', 'opacity-0'); // Manually clear the blocker overlay immediately without the timeout
                        
                        loadDashboard();
                    }, 1500);
                } else {
                    showToast(res.message, 'error');
                    resetUploadUI();
                }
            },
            
            error: function(jqXHR, textStatus, errorThrown) {
                // Catch specific Server-Level upload limits (like Nginx 413)
                if(jqXHR.status === 413) {
                    showToast('Upload rejected by server (413 Payload Too Large). Check Nginx/Apache settings.', 'error');
                } else {
                    showToast('Server error: ' + (errorThrown || 'Upload failed'), 'error');
                }
                resetUploadUI();
            }
        });

        function resetUploadUI() {
            progContainer.addClass('hidden');
            btn.prop('disabled', false).removeClass('opacity-50 cursor-not-allowed').html(origHtml);
            unlockScreenAction();
        }
    });

    function openTeamModal() { openModal('teamModal'); }
    
    function openReassignModal(userId, name, currentRole) {
        document.getElementById('inpReassignUserId').value = userId;
        document.getElementById('reassignNameDisplay').textContent = name;
        document.getElementById('inpReassignRole').value = currentRole;
        openModal('reassignModal');
    }

    function openSermonModal(id = null) {
        $('#sermonForm')[0].reset();
        $('#inpSermonId, #inpExistingCover, #inpExistingAudio').val('');
        $('#existingCoverMsg, #existingAudioMsg').addClass('hidden');

        if(id) {
            const s = globalSermons.find(x => x.id == id);
            if(s) {
                $('#sermonModalTitle').text('Edit Sermon Data');
                $('#inpSermonId').val(s.id);
                $('#inpTitle').val(s.title);
                $('#inpPreacher').val(s.preacher);
                $('#inpDate').val(s.date_preached);
                $('#inpType').val(s.service_type);
                $('#inpYoutube').val(s.youtube_link);
                $('#inpSummary').val(s.summary);
                $('#inpNotes').val(s.full_notes);
                
                if(s.cover_image_path) { $('#inpExistingCover').val(s.cover_image_path); $('#existingCoverMsg').removeClass('hidden'); }
                if(s.audio_file_path) { $('#inpExistingAudio').val(s.audio_file_path); $('#existingAudioMsg').removeClass('hidden'); }
            }
        } else {
            $('#sermonModalTitle').text('Archive Sermon & Media');
        }
        openModal('sermonModal');
    }

    function openDutyModal(sermonId, title) {
        $('#dutyForm')[0].reset();
        $('#dutySermonId').val(sermonId);
        $('#dutySermonTitle').text(title);
        $('#loggedCrewList').html('<li class="text-xs text-gray-400">Loading crew...</li>');
        openModal('dutyModal');

        // Fetch logged crew for this sermon
        $.post(API_URL, { action: 'fetch_duties', sermon_id: sermonId }, function(res) {
            if(res.status === 'success') {
                let html = '';
                if(res.duties.length === 0) html = '<li class="text-xs text-gray-400">No crew logged yet.</li>';
                else {
                    res.duties.forEach(d => {
                        html += `
                        <li class="flex justify-between items-center bg-white p-2 rounded border border-gray-100">
                            <span class="font-bold text-gray-800">${d.first_name} ${d.last_name}</span>
                            <span class="text-[9px] bg-cyan-50 text-cyan-700 px-2 py-0.5 rounded font-black uppercase tracking-wider">${d.duty_role.replace('_', ' ')}</span>
                        </li>`;
                    });
                }
                $('#loggedCrewList').html(html);
            }
        }, 'json');
    }

    function togglePublish(sermonId, isCurrentlyPublished) {
    // PATCH: Grab the clicked button to apply a local loading state
    const btn = window.event ? window.event.currentTarget : null;
    if (btn) {
        btn.innerHTML = `<svg class="animate-spin h-3 w-3 text-gray-500 mx-auto" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        btn.classList.add('opacity-50', 'pointer-events-none');
    }

    lockScreenAction(); 
    const newStatus = isCurrentlyPublished ? 0 : 1;
    
    $.post(API_URL, { action: 'toggle_publish', sermon_id: sermonId, is_published: newStatus }, function(res) {
        unlockScreenAction();
        showToast(res.message, res.status);
        
        if(res.status === 'success') {
            loadDashboard(); 
        } else if (btn) {
            // If it fails, refresh anyway to restore the original toggle state
            loadDashboard(); 
        }
    }, 'json').fail(function(){
        unlockScreenAction();
        showToast("Server error", "error");
        if (btn) loadDashboard(); // Restore state on crash
    });
}

// ==========================================
    // RADIO BROADCAST LOGIC
    // ==========================================
    let isRadioLive = 0;

    function loadRadioConfig() {
        $.getJSON(API_URL, { action: 'fetch_radio_config' }, function(res) {
            if(res.status === 'success') {
                $('#inpZenoUrl').val(res.zeno_stream_url);
                isRadioLive = res.is_live_audio_on;
                updateRadioUI();
            }
        });
    }

    function updateRadioUI() {
        const btn = $('#btnMasterRadio');
        const knob = $('#radioToggleKnob');
        const icon = $('#radioToggleIcon');
        const text = $('#radioStatusText');

        if (isRadioLive === 1) {
            btn.removeClass('bg-gray-300').addClass('bg-green-500');
            knob.removeClass('translate-x-0').addClass('translate-x-16');
            icon.removeClass('text-gray-400').addClass('text-green-500');
            icon.html('<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.485a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path>');
            text.removeClass('text-gray-500').addClass('text-green-600').text('BROADCAST IS LIVE');
        } else {
            btn.removeClass('bg-green-500').addClass('bg-gray-300');
            knob.removeClass('translate-x-16').addClass('translate-x-0');
            icon.removeClass('text-green-500').addClass('text-gray-400');
            icon.html('<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" clip-rule="evenodd"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path>');
            text.removeClass('text-green-600').addClass('text-gray-500').text('OFFLINE');
        }
    }

    function toggleMasterRadio() {
        lockScreenAction();
        const newState = isRadioLive === 1 ? 0 : 1;
        $.post(API_URL, { action: 'toggle_radio_live', is_live: newState }, function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if(res.status === 'success') {
                isRadioLive = newState;
                updateRadioUI();
            }
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server error toggling broadcast.", "error");
        });
    }

    function testRadioConnection() {
        const btn = $('#btnTestRadio');
        const orig = btn.text();
        btn.prop('disabled', true).text('Pinging server...');
        
        $.getJSON(API_URL, { action: 'test_radio_connection' }, function(res) {
            btn.prop('disabled', false).text(orig);
            showToast(res.message, res.status);
        }).fail(function() {
            btn.prop('disabled', false).text(orig);
            showToast("Server connection error.", "error");
        });
    }
    
    // --- Comment Moderation Logic ---
    let currentCommentPage = 1;
    let commentSearchTerm = '';

    // Trigger search when typing (with small delay to prevent lag)
    let searchTimeout;
    $('#commentSearch').on('keyup', function() {
        clearTimeout(searchTimeout);
        commentSearchTerm = $(this).val();
        searchTimeout = setTimeout(() => loadComments(1), 400); 
    });

    function loadComments(page = 1) {
        currentCommentPage = page;
        $('#commentsList').html('<tr><td colspan="4" class="px-6 py-8 text-center text-gray-400">Loading reflections...</td></tr>');
        
        $.post(API_URL, { action: 'fetch_comments', page: page, search: commentSearchTerm }, function(res) {
            if(res.status === 'success') {
                let html = '';
                if(res.comments.length === 0) {
                    html = '<tr><td colspan="4" class="px-6 py-8 text-center text-gray-400 font-medium">No comments found matching your criteria.</td></tr>';
                } else {
                    res.comments.forEach(c => {
                        // Highlight search term if it exists
                        let text = c.comment_text;
                        if(commentSearchTerm) {
                            const regex = new RegExp(`(${commentSearchTerm})`, "gi");
                            text = text.replace(regex, "<mark class='bg-yellow-200 text-yellow-900 font-bold px-1 rounded'>$1</mark>");
                        }

                        html += `
                        <tr class="hover:bg-red-50/10 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900">${c.author_name}</p>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">${c.nice_date}</p>
                            </td>
                            <td class="px-6 py-4 text-gray-700 leading-relaxed">${text}</td>
                            <td class="px-6 py-4 text-xs font-bold text-cyan-700">${c.sermon_title || 'Unknown Sermon'}</td>
                            <td class="px-6 py-4 text-right">
                                <button onclick="deleteComment(${c.id})" class="text-xs bg-red-50 text-red-600 hover:bg-red-500 hover:text-white border border-red-100 px-3 py-1.5 rounded-lg font-bold transition-colors">Delete</button>
                            </td>
                        </tr>`;
                    });
                }
                $('#commentsList').html(html);
                $('#commentMeta').text(`Showing Page ${res.current_page} of ${res.total_pages || 1} (${res.total_records} Total)`);

                // Build Pagination Buttons
                let pageHtml = '';
                if(res.current_page > 1) pageHtml += `<button onclick="loadComments(${res.current_page - 1})" class="px-3 py-1 bg-white border border-gray-200 rounded text-xs font-bold text-gray-600 hover:bg-gray-50">Prev</button>`;
                if(res.current_page < res.total_pages) pageHtml += `<button onclick="loadComments(${res.current_page + 1})" class="px-3 py-1 bg-white border border-gray-200 rounded text-xs font-bold text-gray-600 hover:bg-gray-50">Next 50</button>`;
                $('#commentPagination').html(pageHtml);
            }
        }, 'json');
    }

    function deleteComment(id) {
        if(confirm('Are you sure you want to permanently delete this comment?')) {
            lockScreenAction();
            $.post(API_URL, { action: 'delete_comment', comment_id: id }, function(res) {
                unlockScreenAction();
                showToast(res.message, res.status);
                if(res.status === 'success') loadComments(currentCommentPage);
            }, 'json').fail(function(){
                unlockScreenAction();
                showToast("Server error", "error");
            });
        }
    }
    
    // ==========================================
    // DATA RENDERING
    // ==========================================
    function loadDashboard() {
        $.getJSON(API_URL, { action: 'fetch_dashboard' }, function(res) {
            if(res.status === 'success') {
                globalSermons = res.sermons;

                // 1. Stats
                $('#statTotal').text(res.stats.total_sermons);
                $('#statPublished').text(res.stats.published_sermons);
                $('#statDownloads').text(res.stats.total_downloads);
                $('#statComments').text(res.stats.pending_comments);

                // 2. Render Sermons List
                let sHtml = '';
                if(res.sermons.length === 0) {
                    sHtml = '<tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 font-medium">No sermons archived yet.</td></tr>';
                } else {
                    res.sermons.forEach(s => {
                        
                        // Media Icons
                        let mediaIcons = '';
                        if(s.audio_file_path) mediaIcons += `<span class="bg-purple-50 text-purple-600 px-2 py-1 rounded text-[10px] font-bold uppercase mr-1 border border-purple-100" title="Audio Uploaded">Audio</span>`;
                        if(s.youtube_link) mediaIcons += `<span class="bg-red-50 text-red-600 px-2 py-1 rounded text-[10px] font-bold uppercase mr-1 border border-red-100" title="Video Embedded">Video</span>`;
                        if(s.cover_image_path) mediaIcons += `<span class="bg-gray-50 text-gray-600 px-2 py-1 rounded text-[10px] font-bold uppercase border border-gray-200" title="Cover Uploaded">Cover</span>`;
                        if(!mediaIcons) mediaIcons = '<span class="text-xs text-gray-400 italic">No media attached</span>';

                        // Publish Toggle
                        const isPub = s.is_published == 1;
                        const pubClass = isPub ? 'bg-green-500 justify-end' : 'bg-gray-200 justify-start';
                        const pubToggle = `
                            <button onclick="togglePublish(${s.id}, ${isPub})" class="w-12 h-6 rounded-full flex items-center p-1 transition-colors duration-300 focus:outline-none ${isPub ? 'bg-green-500' : 'bg-gray-200'}">
                                <div class="bg-white w-4 h-4 rounded-full shadow-sm transform transition-transform duration-300 ${isPub ? 'translate-x-6' : 'translate-x-0'}"></div>
                            </button>
                            <span class="text-[10px] font-bold ${isPub ? 'text-green-600' : 'text-gray-400'} uppercase mt-1 block">${isPub ? 'Live' : 'Hidden'}</span>
                        `;

                        sHtml += `
                        <tr class="hover:bg-cyan-50/20 transition-colors border-b border-gray-50 last:border-0">
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900 text-base">${s.title}</p>
                                <p class="text-[11px] font-bold text-cyan-700 uppercase tracking-wider mt-0.5">${s.preacher} • ${s.nice_date}</p>
                            </td>
                            <td class="px-6 py-4">${mediaIcons}</td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="openDutyModal(${s.id}, '${s.title.replace(/'/g, "\\'")}')" class="bg-white border border-gray-200 hover:border-cyan-400 hover:text-cyan-700 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-sm">
                                    ${s.media_crew_count > 0 ? s.media_crew_count + ' Logged' : '+ Log Crew'}
                                </button>
                            </td>
                            <td class="px-6 py-4 text-center flex flex-col items-center justify-center">${pubToggle}</td>
                            <td class="px-6 py-4 text-right">
                                <button onclick="openSermonModal(${s.id})" class="text-xs bg-cyan-50 text-cyan-700 hover:bg-cyan-600 hover:text-white border border-cyan-200 px-4 py-2 rounded-lg font-bold shadow-sm transition-colors">Edit Sermon</button>
                            </td>
                        </tr>`;
                    });
                }
                $('#sermonsList').html(sHtml);

                // 3. Render Roster Grid
                let rHtml = '';
                if(res.roster.length === 0) {
                    rHtml = '<div class="col-span-full py-12 text-center text-gray-400">No media crew assigned to specialized roles.</div>';
                } else {
                    res.roster.forEach(r => {
                        const pic = r.picture_path ? `<img src="${r.picture_path}" class="w-16 h-16 rounded-full object-cover border-2 border-white shadow-md">` : `<div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center font-bold text-gray-400 text-xl border-2 border-white shadow-md">${r.first_name.charAt(0)}</div>`;
                        rHtml += `
                        <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                            <div class="flex items-center gap-4">
                                ${pic}
                                <div>
                                    <p class="font-bold text-gray-900">${r.first_name} ${r.last_name}</p>
                                    <p class="text-[10px] font-black text-cyan-600 uppercase tracking-widest mt-0.5 bg-cyan-50 inline-block px-2 py-0.5 rounded border border-cyan-100">${r.role_category.replace('_', ' ')}</p>
                                </div>
                            </div>
                            <button onclick="openReassignModal(${r.user_id}, '${r.first_name.replace(/'/g, "\\'")} ${r.last_name.replace(/'/g, "\\'")}', '${r.role_category}')" class="w-8 h-8 rounded-full bg-gray-50 text-gray-400 hover:bg-cyan-50 hover:text-cyan-600 flex items-center justify-center transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            </button>
                        </div>`;
                    });
                }
                $('#rosterGrid').html(rHtml);

                // 4. Populate Dropdowns
                let uOpts = '<option value="">Select available member...</option>';
                res.available_members.forEach(u => uOpts += `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`);
                $('#inpTeamUser').html(uOpts);

                let allRosterOpts = '<option value="">Select team member...</option>';
                res.roster.forEach(r => allRosterOpts += `<option value="${r.user_id}">${r.first_name} ${r.last_name} (${r.role_category.replace('_', ' ')})</option>`);
                $('#inpDutyUser').html(allRosterOpts);
            }
        });
    }

    $(document).ready(function() {
        loadDashboard();
        handleAjaxForm('teamForm', function() { closeModal('teamModal'); loadDashboard(); });
        handleAjaxForm('reassignForm', function() { closeModal('reassignModal'); loadDashboard(); });
        handleAjaxForm('dutyForm', function() { loadDashboard(); openDutyModal($('#dutySermonId').val(), $('#dutySermonTitle').text()); });
        handleAjaxForm('radioConfigForm');
    });
</script>

<?php require_once '../../includes/footer.php'; ?>