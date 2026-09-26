<?php
// /modules/member_portal/index.php

require_once '../../includes/header.php'; 

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-5">
            <div class="w-16 h-16 bg-gradient-to-br from-hodBlue to-blue-900 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0 text-xl font-black" id="userInitials">
                --
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Welcome, <span id="portalFirstName">Member</span>!</h2>
                <div class="flex flex-wrap gap-2 mt-2" id="portalBadges">
                    <span class="bg-gray-100 text-gray-500 px-3 py-1 rounded-lg text-xs font-bold animate-pulse">Loading profile...</span>
                </div>
            </div>
        </div>
        
        <a href="http://hodlc.lpc.cm/sermons" target="_blank" class="relative z-10 bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-bold transition-all duration-300 shadow-md flex items-center gap-2 shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Catch Up on Sermons
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 animate-fade-in-up" style="animation-delay: 0.1s;">
        
        <div class="lg:col-span-2 space-y-8">
            
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50/30">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-green-100 text-green-600 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <h3 class="text-xl font-display font-bold text-gray-900">My Giving</h3>
                    </div>
                    <div class="flex gap-2 items-center w-full sm:w-auto">
                        <input type="month" id="filterMonth" class="px-3 py-2 border border-gray-200 rounded-lg text-sm font-bold text-gray-700 outline-none focus:border-hodBlue">
                        <button onclick="openModal('contributionModal')" class="bg-hodBlue hover:bg-blue-900 text-white px-4 py-2 rounded-lg text-sm font-bold transition-colors whitespace-nowrap">
                            + Add Giving
                        </button>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="bg-blue-50/50 border border-blue-100 rounded-2xl p-5 mb-6 relative overflow-hidden">
                        <div class="absolute top-0 right-0 opacity-10">
                            <svg class="w-32 h-32 -mt-4 -mr-4 text-hodBlue" fill="currentColor" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                        </div>
                        <h4 class="text-[10px] font-bold uppercase tracking-widest text-hodBlue mb-1">Total Given (Selected Period)</h4>
                        <p class="text-4xl font-black text-gray-900 mb-4 tracking-tight">₦<span id="portalTotalGiven">0.00</span></p>
                        
                        <div class="bg-white/60 p-4 rounded-xl border border-white relative z-10 backdrop-blur-sm">
                            <p id="financeMessage" class="text-sm text-gray-800 italic leading-relaxed font-medium">Loading pastoral message...</p>
                        </div>
                    </div>
                    
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Breakdown</h4>
                    <div id="financialBreakdown" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        </div>
                </div>
            </div>

            <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex items-center gap-3 bg-gray-50/30">
                    <div class="w-10 h-10 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    </div>
                    <h3 class="text-xl font-display font-bold text-gray-900">Recent Attendance</h3>
                </div>
                
                <div class="p-6">
                    <div class="bg-orange-50/50 border border-orange-100 rounded-2xl p-5 mb-6">
                        <p id="attendanceMessage" class="text-sm text-orange-900 italic leading-relaxed font-medium">Loading pastoral message...</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-gray-400 font-bold uppercase text-[10px] border-b border-gray-100">
                                <tr>
                                    <th class="pb-3">Event Date</th>
                                    <th class="pb-3">Service / Event Title</th>
                                    <th class="pb-3 text-right">My Status</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceHistory" class="divide-y divide-gray-50 text-gray-700">
                                <tr><td colspan="3" class="py-6 text-center">Loading history...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>

        <div class="space-y-8">
            
            <div class="bg-purple-50/50 rounded-3xl shadow-sm border border-purple-100/60 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Zoe Prayer Team</h3>
                </div>
                <p class="text-sm text-gray-600 mb-5 leading-relaxed">Need agreement in prayer? Our intercessors (Zoe) are standing by to pray with you.</p>
                <button onclick="openModal('prayerRequestModal')" class="w-full bg-purple-600 hover:bg-purple-800 text-white px-4 py-3 rounded-xl font-bold transition-all shadow-md">
                    Send Prayer Request
                </button>
            </div>
            
            <div class="bg-yellow-50/50 rounded-3xl shadow-sm border border-yellow-100/60 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-yellow-100 text-yellow-700 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Share a Testimony</h3>
                </div>
                <p class="text-sm text-gray-600 mb-5 leading-relaxed">Has God done something amazing? Share your miracle to encourage the brethren.</p>
                <div class="flex gap-2">
                    <button onclick="openModal('testimonySubmitModal')" class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-gray-900 px-4 py-3 rounded-xl font-bold transition-all shadow-md text-sm text-center">
                        Testify Now
                    </button>
                    <a href="/testimonies.php" target="_blank" class="bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 px-4 py-3 rounded-xl font-bold transition-all shadow-sm text-sm flex items-center justify-center">
                        Read Wall
                    </a>
                </div>
            </div>
            
            <div class="bg-blue-50/50 rounded-3xl shadow-sm border border-blue-100/60 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Charis Library</h3>
                </div>
                <p class="text-sm text-gray-600 mb-5 leading-relaxed">Grow in grace and knowledge. Access our collection of spiritual resources, e-books, and physical prints.</p>
                <a href="/modules/library/index.php" class="block w-full bg-hodBlue hover:bg-blue-900 text-white px-4 py-3 rounded-xl font-bold transition-all shadow-md text-center text-sm">
                    Open Library
                </a>
            </div>
            
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
                <div class="flex items-center gap-2 mb-6">
                    <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    <h3 class="text-lg font-bold text-gray-900">Church Broadcasts</h3>
                </div>
                <div id="announcementsList" class="space-y-4">
                    <p class="text-sm text-gray-400 text-center py-4">Loading announcements...</p>
                </div>
            </div>

            <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-6">
                <div class="flex items-center gap-2 mb-6">
                    <svg class="w-5 h-5 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <h3 class="text-lg font-bold text-gray-900">Upcoming Events</h3>
                </div>
                <div id="upcomingEventsList" class="space-y-3">
                    <p class="text-sm text-gray-400 text-center py-4">Loading events...</p>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="contributionModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-y-auto custom-scrollbar max-h-[90vh] transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Record Contribution</h3>
            <button onclick="closeModal('contributionModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="contributionForm" class="p-6 space-y-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_contribution">
            
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Amount (₦) *</label>
                <input type="number" step="0.01" name="amount" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-lg font-bold shadow-sm" placeholder="0.00">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Type *</label>
                    <select name="contribution_type" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm font-bold bg-gray-50 outline-none cursor-pointer">
                        <option value="Tithe">Tithe</option>
                        <option value="Offering">Offering</option>
                        <option value="First_Fruit">First Fruit</option>
                        <option value="Project">Project / Seed</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Date *</label>
                    <input type="date" name="contribution_date" required id="defaultToday" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm font-bold outline-none cursor-text">
                </div>
            </div>

            <div class="bg-gray-50 border border-gray-200 border-dashed rounded-xl p-4 text-center">
                <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Upload Receipt (Optional)</label>
                <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-hodBlue hover:file:bg-blue-100 cursor-pointer">
                <p class="text-[9px] text-gray-400 mt-2">JPG, PNG, or PDF. Max 2MB.</p>
            </div>

            <button type="submit" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center">
                Submit Record
            </button>
        </form>
    </div>
</div>

<div id="prayerRequestModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col">
        <div class="px-6 py-5 border-b border-purple-100 flex justify-between items-center bg-purple-50 shrink-0">
            <h3 class="text-lg font-bold text-purple-900">Submit Prayer Request</h3>
            <button onclick="closeModal('prayerRequestModal')" class="text-gray-400 hover:text-gray-900 bg-white p-1 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="prayerRequestForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="submit_prayer_request">
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Your Request *</label>
                <textarea name="request_text" rows="5" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-600 outline-none text-sm shadow-sm resize-none bg-gray-50 focus:bg-white transition-colors" placeholder="What would you like us to pray for?"></textarea>
                <p class="text-[10px] text-gray-400 mt-2 font-medium">Your request is secure and will be handled with strict spiritual confidentiality.</p>
            </div>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-800 text-white px-6 py-3.5 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center">
                Send to Zoe Team
            </button>
        </form>
    </div>
</div>

<div id="testimonySubmitModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh]">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-yellow-50 shrink-0">
            <h3 class="text-lg font-black text-gray-900">Testify of God's Goodness</h3>
            <button onclick="closeModal('testimonySubmitModal')" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="portalTestimonyForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="submit_testimony">
            
            <div class="p-6 space-y-6 overflow-y-auto custom-scrollbar flex-1 bg-white">
                
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Privacy Level *</label>
                    <select name="privacy_level" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-yellow-500 bg-white font-bold text-gray-900 cursor-pointer">
                        <option value="Public">Public (Show my name on the Wall)</option>
                        <option value="Anonymous_To_All">Fully Anonymous (Hide my name completely)</option>
                        <option value="Pastoral_Only">Pastoral Only (Hide name from public, but Pastors can see)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Voice Note (Optional)</label>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <button type="button" id="portalStartRecordBtn" class="flex-1 bg-red-50 text-red-600 border border-red-200 hover:bg-red-600 hover:text-white px-4 py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg> Record Audio
                        </button>
                        <button type="button" id="portalStopRecordBtn" class="hidden flex-1 bg-red-600 text-white px-4 py-3 rounded-xl font-bold flex items-center justify-center gap-2 animate-pulse">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6 6h12v12H6z"></path></svg> Stop (<span id="portalRecordTimer">00:00</span>)
                        </button>
                        <div class="flex-1 relative">
                            <input type="file" name="voice_note" id="portalAudioUpload" accept="audio/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                            <div class="w-full h-full bg-gray-50 border border-gray-200 border-dashed rounded-xl flex items-center justify-center text-sm font-bold text-gray-600 pointer-events-none">Or Upload Audio</div>
                        </div>
                    </div>
                    <div id="portalAudioPreviewContainer" class="hidden mt-3 p-3 bg-gray-50 rounded-xl border border-gray-200 flex items-center gap-3">
                        <audio id="portalAudioPlayback" controls class="flex-1 h-8"></audio>
                        <button type="button" onclick="clearPortalAudio()" class="text-xs text-red-500 font-bold hover:underline">Clear</button>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Written Testimony</label>
                    <textarea name="content_text" id="portalWrittenText" rows="5" placeholder="What has the Lord done for you?" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-yellow-500 bg-gray-50 focus:bg-white transition-colors resize-none font-medium text-gray-900"></textarea>
                </div>

                <label class="flex items-start gap-3 p-4 bg-yellow-50/50 border border-yellow-100 rounded-xl cursor-pointer hover:bg-yellow-50 transition">
                    <input type="checkbox" name="request_editing" value="1" class="w-5 h-5 mt-0.5 text-yellow-500 rounded border-gray-300 focus:ring-yellow-500 cursor-pointer">
                    <div>
                        <span class="font-bold text-gray-900 block text-sm">Please review and format my testimony</span>
                        <span class="text-xs text-gray-500 mt-0.5 block">Our team will correct grammar before publishing.</span>
                    </div>
                </label>
            </div>
            
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-gray-900 py-3.5 rounded-xl font-black transition-all shadow-md flex justify-center items-center">Submit to the Altar</button>
            </div>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '/api/member_portal_api.php';

    // ==========================================
    // UI CORE: MODALS & TOASTS (COMPLETELY OVERHAULED FOR UX)
    // ==========================================

    function lockScreenAction() {
    const blocker = document.getElementById('globalActionBlocker');
    blocker.classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // Lock scrolling
    setTimeout(() => blocker.classList.remove('opacity-0'), 10);
}

function unlockScreenAction() {
    const blocker = document.getElementById('globalActionBlocker');
    blocker.classList.add('opacity-0');
    setTimeout(() => {
        blocker.classList.add('hidden');
        // PATCH: Only restore background scrolling if NO modals are actively open
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }
    }, 300);
}

    // UI Helpers
    function openModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        
        // VITAL: Move the modal to the body to escape ANY relative parent containers
        document.body.appendChild(modal); 
        
        const inner = modal.children[0];
        modal.classList.remove('hidden');
        
        // VITAL: Lock background scrolling
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => { 
            modal.classList.remove('opacity-0'); 
            inner.classList.remove('scale-95'); 
        }, 10);
    }

    function closeModal(id) {
    const modal = document.getElementById(id);
    if(!modal) return;
    
    const inner = modal.children[0];
    modal.classList.add('opacity-0');
    inner.classList.add('scale-95');
    
    setTimeout(() => { 
        modal.classList.add('hidden'); 
        // PATCH: Apply the exact same safety check here
        if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
            document.body.style.overflow = ''; 
        }
        const form = modal.querySelector('form'); 
        if(form) form.reset(); 
    }, 300);
}

    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function getMonthDates(monthString) {
        // monthString format: "YYYY-MM"
        if(!monthString) return { start: '', end: '' };
        const [year, month] = monthString.split('-');
        const startDate = `${year}-${month}-01`;
        const lastDay = new Date(year, month, 0).getDate();
        const endDate = `${year}-${month}-${lastDay}`;
        return { start: startDate, end: endDate };
    }

    // Load Data
    function loadPortalData() {
        const monthVal = $('#filterMonth').val();
        const dates = getMonthDates(monthVal);
        
        $.ajax({
            url: API_URL,
            type: 'GET',
            data: { action: 'fetch_dashboard', start_date: dates.start, end_date: dates.end },
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    
                    // 1. Identity & Badges
                    const u = res.user_info;
                    $('#portalFirstName').text(u.first_name);
                    $('#userInitials').text(u.first_name.charAt(0) + u.last_name.charAt(0));
                    
                    let badgesHtml = `<span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider border border-blue-200">${u.spiritual_status.replace('_', ' ')}</span>`;
                    if(u.tribe_name) badgesHtml += `<span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider border border-purple-200">Tribe: ${u.tribe_name}</span>`;
                    if(u.departments) badgesHtml += `<span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider border border-orange-200">${u.departments}</span>`;
                    $('#portalBadges').html(badgesHtml);

                    // 2. Financials
                    $('#portalTotalGiven').text(formatCurrency(res.financials.total));
                    $('#financeMessage').html(res.financials.message.replace(/\n/g, '<br>'));
                    
                    let finHtml = '';
                    const types = ['Tithe', 'Offering', 'Project', 'First_Fruit', 'Other'];
                    types.forEach(type => {
                        const found = res.financials.breakdown.find(f => f.contribution_type === type);
                        const amt = found ? formatCurrency(found.total_amount) : '0.00';
                        finHtml += `
                        <div class="bg-gray-50 border border-gray-100 p-3 rounded-xl">
                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-wider">${type.replace('_', ' ')}</p>
                            <p class="text-sm font-bold text-gray-900 mt-1">₦${amt}</p>
                        </div>`;
                    });
                    $('#financialBreakdown').html(finHtml);

                    // 3. Attendance
                    $('#attendanceMessage').html(res.attendance.message.replace(/\n/g, '<br>'));
                    let attHtml = '';
                    if(res.attendance.history.length === 0) {
                        attHtml = '<tr><td colspan="3" class="py-4 text-center text-xs text-gray-400 font-medium">No recent attendance records found.</td></tr>';
                    } else {
                        res.attendance.history.forEach(a => {
                            const badge = a.status === 'Present' ? '<span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded font-bold uppercase">Present</span>' : '<span class="text-[10px] bg-red-100 text-red-600 px-2 py-1 rounded font-bold uppercase">Absent</span>';
                            attHtml += `
                            <tr class="border-b border-gray-50 last:border-0 hover:bg-gray-50/50 transition-colors">
                                <td class="py-3 text-xs font-medium text-gray-500">${a.event_date}</td>
                                <td class="py-3 text-sm font-bold text-gray-900">${a.title}</td>
                                <td class="py-3 text-right">${badge}</td>
                            </tr>`;
                        });
                    }
                    $('#attendanceHistory').html(attHtml);

                    // 4. Announcements
                    let annHtml = '';
                    if(res.announcements.length === 0) {
                        annHtml = '<p class="text-sm text-gray-400 text-center italic py-2">No active broadcasts.</p>';
                    } else {
                        res.announcements.forEach(a => {
                            annHtml += `
                            <div class="border-l-2 border-purple-400 pl-4 py-1">
                                <h4 class="text-sm font-bold text-gray-900">${a.title}</h4>
                                <p class="text-xs text-gray-600 mt-1 leading-relaxed">${a.content}</p>
                            </div>`;
                        });
                    }
                    $('#announcementsList').html(annHtml);

                    // 5. Upcoming Events
                    let evtHtml = '';
                    if(res.upcoming_events.length === 0) {
                        evtHtml = '<p class="text-sm text-gray-400 text-center italic py-2">No upcoming events.</p>';
                    } else {
                        res.upcoming_events.forEach(e => {
                            evtHtml += `
                            <div class="bg-red-50/30 border border-red-100 p-3 rounded-xl flex justify-between items-center">
                                <div>
                                    <p class="text-xs font-bold text-gray-900 line-clamp-1">${e.title}</p>
                                    <p class="text-[10px] text-red-500 font-bold uppercase tracking-wider mt-0.5">${e.event_category.replace('_', ' ')}</p>
                                </div>
                                <span class="text-[10px] font-bold text-gray-500 bg-white px-2 py-1 rounded shadow-sm border border-gray-100">${e.event_date.substring(0, 10)}</span>
                            </div>`;
                        });
                    }
                    $('#upcomingEventsList').html(evtHtml);
                }
            }
        });
    }

    $(document).ready(function() {
        // Set default month filter to current month
        const now = new Date();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const yyyy = now.getFullYear();
        $('#filterMonth').val(`${yyyy}-${mm}`);
        $('#defaultToday').val(now.toISOString().split('T')[0]);

        loadPortalData();

        // Reload data on month filter change
        $('#filterMonth').on('change', loadPortalData);

        // Handle Contribution Form Submit (With File Upload)
        $('#contributionForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            btn.prop('disabled', true).html(`<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...`);
            
            lockScreenAction(); // Lock the screen during processing
            
            const formData = new FormData(this);
            
            $.ajax({
                url: API_URL,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction(); // Unlock the screen
                    
                    Toastify({ 
                        text: res.message, 
                        duration: 3000, 
                        gravity: "top", 
                        position: "center", // Strictly centralized Toasts
                        style: { 
                            background: res.status === 'success' ? "#10B981" : "#EF4444", 
                            borderRadius: "10px", 
                            fontWeight: "bold",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
                        } 
                    }).showToast();
                    
                    if(res.status === 'success') {
                        closeModal('contributionModal');
                        loadPortalData();
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction(); // Unlock the screen on error
                    
                    Toastify({ 
                        text: "Server error occurred.", 
                        duration: 3000,
                        gravity: "top", 
                        position: "center", 
                        style: { 
                            background: "#EF4444",
                            borderRadius: "10px", 
                            fontWeight: "bold",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
                        } 
                    }).showToast();
                }
            });
        });
        
        // --- Portal Testimony Logic ---
        let portalRecordedBlob = null;
        let portalMediaRecorder;
        let portalAudioChunks = [];
        let portalRecordInterval;
        let portalSeconds = 0;

        $('#portalStartRecordBtn').click(async function() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                portalMediaRecorder = new MediaRecorder(stream);
                portalMediaRecorder.start();
                portalAudioChunks = [];

                $(this).addClass('hidden'); $('#portalStopRecordBtn').removeClass('hidden');
                
                portalSeconds = 0;
                portalRecordInterval = setInterval(() => {
                    portalSeconds++;
                    const m = String(Math.floor(portalSeconds / 60)).padStart(2, '0');
                    const s = String(portalSeconds % 60).padStart(2, '0');
                    $('#portalRecordTimer').text(`${m}:${s}`);
                }, 1000);

                portalMediaRecorder.addEventListener("dataavailable", event => { portalAudioChunks.push(event.data); });
                portalMediaRecorder.addEventListener("stop", () => {
                    portalRecordedBlob = new Blob(portalAudioChunks, { type: 'audio/webm' });
                    const audioUrl = URL.createObjectURL(portalRecordedBlob);
                    $('#portalAudioPlayback').attr('src', audioUrl);
                    $('#portalAudioPreviewContainer').removeClass('hidden').addClass('flex');
                    $('#portalStartRecordBtn').removeClass('hidden'); $('#portalStopRecordBtn').addClass('hidden');
                    stream.getTracks().forEach(track => track.stop());
                });
            } catch (err) {
                Toastify({ text: "Microphone access denied.", style: { background: "#EF4444" } }).showToast();
            }
        });

        $('#portalStopRecordBtn').click(function() {
            portalMediaRecorder.stop();
            clearInterval(portalRecordInterval);
            $('#portalRecordTimer').text('00:00');
        });

        $('#portalAudioUpload').change(function(e) {
            if(this.files[0]) {
                portalRecordedBlob = null;
                $('#portalAudioPlayback').attr('src', URL.createObjectURL(this.files[0]));
                $('#portalAudioPreviewContainer').removeClass('hidden').addClass('flex');
            }
        });

        window.clearPortalAudio = function() {
            portalRecordedBlob = null; portalAudioChunks = [];
            $('#portalAudioUpload').val(''); $('#portalAudioPlayback').attr('src', '');
            $('#portalAudioPreviewContainer').removeClass('flex').addClass('hidden');
        };

        // Submit Logic (Points to the Public API)
        $('#portalTestimonyForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const orig = btn.html();
            const txt = $('#portalWrittenText').val().trim();
            const hasAudio = portalRecordedBlob !== null || $('#portalAudioUpload')[0].files.length > 0;
            
            if(!txt && !hasAudio) { 
                Toastify({ text: "Please provide either written text or a voice note.", style: { background: "#EF4444" } }).showToast(); 
                return; 
            }
            
            btn.prop('disabled', true).html('Sending...');
            lockScreenAction(); 
            
            let formData = new FormData(this);
            if(portalRecordedBlob) { formData.append('voice_note', portalRecordedBlob, 'voicenote.webm'); }

            $.ajax({
                url: '/api/testimony_public_api.php', // Routes to our Public API
                type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction(); 
                    
                    Toastify({ 
                        text: res.message, duration: 4000, gravity: "top", position: "center",
                        style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.3)" } 
                    }).showToast();
                    
                    if(res.status === 'success') {
                        closeModal('testimonySubmitModal');
                        $('#portalTestimonyForm')[0].reset();
                        clearPortalAudio();
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(orig);
                    unlockScreenAction();
                    Toastify({ text: "Server error occurred.", style: { background: "#EF4444" } }).showToast();
                }
            });
        });
        
        // Handle Prayer Request Form Submit
        $('#prayerRequestForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            btn.prop('disabled', true).html(`<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Sending...`);
            
            lockScreenAction(); 
            
            $.ajax({
                url: API_URL,
                type: 'POST',
                data: new FormData(this),
                processData: false,
                contentType: false,
                success: function(res) {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction(); 
                    
                    Toastify({ 
                        text: res.message, 
                        duration: 4000, 
                        gravity: "top", 
                        position: "center",
                        style: { 
                            background: res.status === 'success' ? "#10B981" : "#EF4444", 
                            borderRadius: "10px", 
                            fontWeight: "bold",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
                        } 
                    }).showToast();
                    
                    if(res.status === 'success') {
                        closeModal('prayerRequestModal');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(origHtml);
                    unlockScreenAction();
                    Toastify({ text: "Server error occurred.", style: { background: "#EF4444", borderRadius: "10px", fontWeight: "bold" } }).showToast();
                }
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>