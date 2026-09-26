<?php
// /modules/regions/index.php
require_once '../../includes/header.php'; 

// Security & RBAC Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}

$my_user_id = $_SESSION['user_id'];
$admin_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'];
$is_admin = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $admin_roles) || strpos($role['role_name'], 'IDI') !== false) {
            $is_admin = true;
            break;
        }
    }
}
?>

<!-- Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.2); }
</style>

<div class="max-w-[90rem] mx-auto space-y-6 pb-10">
    
    <!-- HEADER SECTION -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-purple-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Regional Hubs</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Community, Carpools, and localized church integration.</p>
            </div>
        </div>
        
        <?php if ($is_admin): ?>
        <div class="relative z-10 flex bg-gray-100 p-1 rounded-xl shrink-0 w-full md:w-auto">
            <button onclick="switchTab('hub')" id="tab-hub" class="flex-1 md:flex-none px-6 py-2.5 rounded-lg font-bold text-sm transition-all shadow-sm bg-white text-hodBlue">My Region</button>
            <button onclick="switchTab('admin')" id="tab-admin" class="flex-1 md:flex-none px-6 py-2.5 rounded-lg font-bold text-sm transition-all text-gray-500 hover:text-gray-900">Command Center</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================================= -->
    <!-- TAB 1: PUBLIC MEMBER HUB -->
    <!-- ================================================================================= -->
    <div id="viewHub" class="space-y-6 animate-fade-in-up">
        
        <!-- Unassigned Warning -->
        <div id="unassignedWarning" class="hidden bg-orange-50 border border-orange-200 rounded-3xl p-8 text-center shadow-sm">
            <div class="w-16 h-16 bg-orange-100 text-orange-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">You haven't been assigned to a Region yet.</h3>
            <p class="text-gray-600 text-sm max-w-md mx-auto mb-6">To join your local community hub, please ensure your physical address is updated in your profile, or wait for an Admin to place you.</p>
            <a href="/modules/profile/index.php" class="inline-block bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md">Update My Profile</a>
        </div>

        <div id="hubContent" class="hidden grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- LEFT COLUMN: Leadership & Quick Actions -->
            <div class="space-y-6">
                <!-- Region Info Card -->
                <div class="bg-gradient-to-br from-hodBlue to-[#152750] rounded-3xl p-6 text-white shadow-lg relative overflow-hidden">
                    <div class="absolute -right-10 -top-10 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                    <p class="text-[10px] font-bold text-blue-200 uppercase tracking-widest mb-1">Your Local Hub</p>
                    <h3 id="hubRegionName" class="text-2xl font-black font-display tracking-tight mb-4">Loading...</h3>
                    <div class="flex items-center gap-2 text-sm font-medium bg-white/10 w-fit px-3 py-1.5 rounded-lg border border-white/20">
                        <svg class="w-4 h-4 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span id="hubPopulation">0</span> active members
                    </div>
                </div>

                <!-- Leadership Roster -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Regional Leadership</h4>
                    <div class="space-y-4" id="hubLeadership">
                        <!-- Injected via JS -->
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="grid grid-cols-2 gap-3">
                    <button onclick="openModal('addPrayerModal')" class="bg-purple-50 hover:bg-purple-100 text-purple-700 p-4 rounded-2xl flex flex-col items-center justify-center gap-2 transition border border-purple-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                        <span class="text-xs font-bold">Post Prayer</span>
                    </button>
                    <button onclick="openModal('addCarpoolModal')" class="bg-green-50 hover:bg-green-100 text-green-700 p-4 rounded-2xl flex flex-col items-center justify-center gap-2 transition border border-green-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        <span class="text-xs font-bold">Need a Ride?</span>
                    </button>
                </div>
            </div>

            <!-- RIGHT COLUMN: Regional Stream (WhatsApp-Style Feed) -->
            <div class="lg:col-span-2 flex flex-col bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden h-[800px] max-h-[85vh]">
                
                <!-- Noticeboard Banner Header -->
                <div class="bg-gradient-to-r from-orange-50 to-white border-b border-orange-100 shrink-0">
                    <div class="px-5 py-3 flex items-center gap-2 border-b border-orange-100/50">
                        <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                        <h3 class="font-bold text-xs text-gray-900 uppercase tracking-widest">Noticeboard</h3>
                    </div>
                    <div class="p-3 flex gap-3 overflow-x-auto custom-scrollbar" id="hubNoticeboard">
                        <!-- Broadcasts injected here -->
                    </div>
                </div>

                <!-- Chat Feed Area -->
                <div class="flex-1 overflow-y-auto custom-scrollbar p-5 space-y-4 bg-gray-50/50" id="streamContainer">
                    <div class="text-center py-20">
                        <svg class="animate-spin h-8 w-8 text-hodBlue mx-auto mb-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <p class="text-xs font-bold text-gray-400">Loading Stream...</p>
                    </div>
                </div>

                <!-- Chat Input Area -->
                <form id="formStreamChat" class="p-4 bg-white border-t border-gray-100 shrink-0">
                    <input type="hidden" name="action" value="post_stream_message">
                    
                    <!-- Media Preview Box -->
                    <div id="mediaPreviewBox" class="hidden mb-3 relative inline-block">
                        <div class="p-2 border border-gray-200 rounded-xl bg-gray-50 text-xs font-bold text-gray-600 flex items-center gap-2">
                            <svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                            <span id="mediaFileName">File attached</span>
                            <button type="button" onclick="clearStreamMedia()" class="ml-2 text-red-500 hover:text-red-700 bg-red-50 rounded-full p-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                        </div>
                    </div>

                    <div class="flex items-end gap-2">
                        <label class="shrink-0 p-3 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-2xl cursor-pointer transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <input type="file" id="inpStreamMedia" name="media" accept="image/*,audio/*" class="hidden" onchange="previewStreamMedia(this)">
                        </label>

                        <div class="flex-1 bg-gray-50 border border-gray-200 rounded-2xl overflow-hidden focus-within:ring-2 focus-within:ring-hodBlue/20 transition-all">
                            <textarea name="content" id="inpStreamContent" rows="1" placeholder="Type a message to your region..." class="w-full bg-transparent px-4 py-3 text-sm font-medium text-gray-900 outline-none resize-none max-h-32 min-h-[44px]" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                        </div>

                        <button type="submit" id="btnSendStream" class="shrink-0 p-3 bg-hodBlue hover:bg-[#152750] text-white rounded-2xl transition shadow-md flex items-center justify-center">
                            <svg class="w-5 h-5 translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ================================================================================= -->
    <!-- TAB 2: ADMIN COMMAND CENTER (KANBAN) -->
    <!-- ================================================================================= -->
    <?php if ($is_admin): ?>
    <div id="viewAdmin" class="hidden space-y-6 animate-fade-in-up">
        
        <div class="flex flex-wrap justify-between items-center gap-4 bg-white p-5 rounded-3xl border border-gray-100 shadow-sm">
            <h3 class="font-bold text-gray-900">Regional Distribution</h3>
            <div class="flex gap-3 flex-wrap">
                <button onclick="exportRegionalData()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-2 shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Export .XLSX
                </button>
                <button onclick="openManageRegionModal()" class="bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                    New Region
                </button>
                <button onclick="openModal('broadcastModal'); fetchAdminBroadcasts();" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    Broadcast
                </button>
                <button onclick="runAutoAssign()" id="btnAutoAssign" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-sm font-bold shadow-md transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                    Auto-Assign (Jakande Pivot)
                </button>
            </div>
        </div>

        <!-- Kanban Board Container -->
        <div class="flex overflow-x-auto gap-6 pb-6 custom-scrollbar items-start min-h-[600px]" id="kanbanBoard">
            <div class="w-full text-center py-20 text-gray-400">Loading Command Center...</div>
        </div>

    </div>
    <?php endif; ?>

</div>

<!-- ================================================================================= -->
<!-- STRICT MODALS (Centered, Backdrop, Scroll-Locked, Click-to-Close) -->
<!-- ================================================================================= -->

<!-- Add Prayer Modal -->
<div id="addPrayerModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('addPrayerModal')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="shrink-0 px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-900">Share with your Region</h3>
            <button onclick="closeModal('addPrayerModal')" class="text-gray-400 hover:text-red-500 bg-white p-1 rounded-full shadow-sm transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-white">
            <form id="formPrayer" class="space-y-5">
                <input type="hidden" name="action" value="add_prayer">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">What's on your heart?</label>
                    <textarea name="prayer_content" required rows="4" placeholder="Type your prayer request or testimony..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm resize-none"></textarea>
                </div>
                <label class="flex items-center gap-3 p-4 border border-gray-100 rounded-xl cursor-pointer hover:bg-gray-50 transition">
                    <input type="checkbox" name="is_testimony" value="1" class="w-5 h-5 text-green-600 rounded border-gray-300 focus:ring-green-500">
                    <span class="font-bold text-sm text-gray-800">This is a Testimony! Praise God!</span>
                </label>
            </form>
        </div>
        <div class="shrink-0 px-6 py-5 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
            <button type="button" onclick="closeModal('addPrayerModal')" class="px-5 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-200 transition">Cancel</button>
            <button type="submit" form="formPrayer" class="flex-1 bg-hodBlue hover:bg-blue-800 text-white py-3 rounded-xl font-bold shadow-md transition-all">Post to Stream</button>
        </div>
    </div>
</div>

<!-- Add Carpool Modal -->
<div id="addCarpoolModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('addCarpoolModal')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="shrink-0 px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-900">Carpool & Care</h3>
            <button onclick="closeModal('addCarpoolModal')" class="text-gray-400 hover:text-red-500 bg-white p-1 rounded-full shadow-sm transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-white">
            <form id="formCarpool" class="space-y-5">
                <input type="hidden" name="action" value="add_carpool">
                <div class="grid grid-cols-2 gap-3">
                    <label class="border border-gray-200 rounded-xl p-3 flex items-center gap-2 cursor-pointer hover:bg-blue-50 focus-within:ring-2 focus-within:ring-hodBlue transition">
                        <input type="radio" name="type" value="Need_Ride" checked class="text-hodBlue w-4 h-4">
                        <span class="text-xs font-bold text-gray-700">Need a Ride</span>
                    </label>
                    <label class="border border-gray-200 rounded-xl p-3 flex items-center gap-2 cursor-pointer hover:bg-green-50 focus-within:ring-2 focus-within:ring-green-500 transition">
                        <input type="radio" name="type" value="Offering_Ride" class="text-green-600 w-4 h-4">
                        <span class="text-xs font-bold text-gray-700">Have Space</span>
                    </label>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Route Details *</label>
                    <input type="text" name="route_details" required placeholder="e.g., From Alpha Beach to Church" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Seats Available (If offering)</label>
                    <input type="number" name="seats_available" min="0" max="10" value="0" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm">
                </div>
            </form>
        </div>
        <div class="shrink-0 px-6 py-5 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
            <button type="button" onclick="closeModal('addCarpoolModal')" class="px-5 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-200 transition">Cancel</button>
            <button type="submit" form="formCarpool" class="flex-1 bg-hodBlue hover:bg-blue-800 text-white py-3 rounded-xl font-bold shadow-md transition-all">Post to Stream</button>
        </div>
    </div>
</div>

<!-- Noticeboard Full Message Modal -->
<div id="noticeModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('noticeModal')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden transform scale-95 transition-transform duration-300 max-h-[80vh] md:max-h-[90vh]">
        <div class="shrink-0 px-6 py-5 border-b border-orange-100 flex justify-between items-center bg-orange-50/50">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                Official Notice
            </h3>
            <button onclick="closeModal('noticeModal')" class="text-gray-400 hover:text-red-500 bg-white p-1 rounded-full shadow-sm transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-white">
            <span id="noticeModalDate" class="text-[10px] font-bold text-orange-500 uppercase tracking-wider mb-3 block"></span>
            <p id="noticeModalContent" class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed"></p>
        </div>
        <div class="shrink-0 px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end">
            <button type="button" onclick="closeModal('noticeModal')" class="px-6 py-2.5 bg-gray-200 rounded-xl font-bold text-gray-700 hover:bg-gray-300 transition-colors shadow-sm">Return</button>
        </div>
    </div>
</div>

<!-- Manage Region Modal (Admin) -->
<div id="manageRegionModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('manageRegionModal')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="shrink-0 px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 id="regionModalTitle" class="text-lg font-bold text-gray-900">Create New Region</h3>
            <button onclick="closeModal('manageRegionModal')" class="text-gray-400 hover:text-red-500 bg-white p-1 rounded-full shadow-sm transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-white">
            <form id="formManageRegion" class="space-y-5">
                <input type="hidden" name="action" value="admin_manage_region">
                <input type="hidden" name="region_id" id="inpRegionId">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Region Name *</label>
                    <input type="text" name="name" id="inpRegionName" required placeholder="e.g., Region 4 (Ajah Axis)" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Description / Covered Areas</label>
                    <textarea name="description" id="inpRegionDesc" rows="2" placeholder="e.g., Covers Ikota to Awoyaya..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm resize-none"></textarea>
                </div>
                
                <!-- Leadership Dropdowns -->
                <div class="bg-blue-50/50 p-4 rounded-2xl border border-blue-100 space-y-4">
                    <h4 class="text-[10px] font-bold text-hodBlue uppercase tracking-widest">Assign Leadership</h4>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Director in Charge</label>
                        <select name="director_id" id="selDirector" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-hodBlue bg-white"><option value="">Loading...</option></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Pastor in Charge</label>
                        <select name="pastor_id" id="selPastor" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-hodBlue bg-white"><option value="">Loading...</option></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Regional Head (HOD)</label>
                        <select name="head_id" id="selHead" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-hodBlue bg-white"><option value="">Loading...</option></select>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">UI Color Code</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="color_code" id="inpRegionColor" value="#1D356A" class="w-12 h-12 p-1 border border-gray-200 rounded-xl cursor-pointer">
                        <span class="text-xs text-gray-500">Used for Kanban headers</span>
                    </div>
                </div>
            </form>
        </div>
        <div class="shrink-0 px-6 py-5 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
            <button type="button" onclick="closeModal('manageRegionModal')" class="px-5 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-200 transition">Cancel</button>
            <button type="submit" form="formManageRegion" id="btnSaveRegion" class="flex-1 bg-hodBlue hover:bg-blue-800 text-white py-3 rounded-xl font-bold shadow-md transition-all">Save Region</button>
        </div>
    </div>
</div>

<!-- Admin Broadcast Modal -->
<div id="broadcastModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('broadcastModal')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[80vh] md:max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="shrink-0 px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-900">Regional Broadcasts</h3>
            <button onclick="closeModal('broadcastModal')" class="text-gray-400 hover:text-red-500 bg-white p-1 rounded-full shadow-sm transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <form id="formBroadcast" class="p-6 space-y-4 shrink-0 bg-white shadow-sm z-10 relative">
            <input type="hidden" name="action" value="admin_manage_broadcast">
            <input type="hidden" name="manage_action" id="broadcastManageAction" value="create">
            <input type="hidden" name="broadcast_id" id="broadcastId" value="">
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Target Region *</label>
                <select name="region_id" id="broadcastRegionSelect" onchange="fetchAdminBroadcasts()" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm bg-white font-bold cursor-pointer">
                    <option value="">Select Region...</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1.5">Message *</label>
                <textarea name="message" id="broadcastMessage" required rows="3" placeholder="Type notification message to push..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm resize-none"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="button" id="btnCancelEditBroadcast" onclick="resetBroadcastForm()" class="hidden px-4 py-3 rounded-xl font-bold text-gray-500 bg-gray-100 hover:bg-gray-200 transition-all">Cancel Edit</button>
                <button type="submit" id="btnSaveBroadcast" class="flex-1 bg-hodBlue hover:bg-blue-800 text-white py-3.5 rounded-xl font-bold shadow-md transition-all flex items-center justify-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg> Send Blast</button>
            </div>
        </form>
        
        <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-gray-50">
            <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-3">Noticeboard History</h4>
            <div id="adminBroadcastList" class="space-y-3">
                <p class="text-xs text-gray-400 italic text-center py-4">Select a region above to view history.</p>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Reassign Modal (Centered on all devices) -->
<div id="mobileReassignSheet" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain" onclick="if(event.target === this) closeModal('mobileReassignSheet')">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh] overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Manage Member</h3>
            <button onclick="closeModal('mobileReassignSheet')" class="text-gray-400 hover:text-red-500 bg-gray-100 rounded-full p-1 transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-6 overflow-y-auto space-y-3 custom-scrollbar" id="mobileReassignOptions">
            <!-- Buttons injected via JS -->
        </div>
    </div>
</div>

<!-- Global Blocker -->
<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[100000] hidden items-center justify-center bg-gray-900/80 backdrop-blur-md cursor-not-allowed transition-opacity duration-300 opacity-0 overscroll-contain">
    <div class="bg-white p-5 rounded-3xl shadow-2xl flex items-center gap-4 border border-gray-100">
        <div class="p-3 bg-blue-50 rounded-2xl">
            <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        </div>
        <span class="font-bold text-gray-700">Processing request...</span>
    </div>
</div>

<script>
    const API_URL = '/api/regions_api.php';
    const MY_USER_ID = <?= $_SESSION['user_id'] ?>;
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    let globalAdminRegions = [];
    let targetUserIdForMobile = null;
    let streamPollingInterval = null;

    // ==========================================
    // UI UTILS (Strict adherence to constraints)
    // ==========================================
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
            if(document.querySelectorAll('.backdrop-blur-md:not(.hidden), .backdrop-blur-sm:not(.hidden)').length === 0){
                document.body.style.overflow = ''; 
            }
        }, 300);
    }

    function openModal(id) {
        const m = document.getElementById(id);
        if(!m) return;

        // The Viewport Escape: Moves modal to the absolute root to prevent scroll-bleed
        document.body.appendChild(m); 
        
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Lock background scroll
        
        requestAnimationFrame(() => { 
            m.classList.remove('opacity-0'); 
            m.children[0].classList.remove('scale-95', 'translate-y-full'); 
        });
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if(!m) return;
        
        m.classList.add('opacity-0');
        const inner = m.children[0];
        inner.classList.add('scale-95'); // Unified scaling for all modals
        
        setTimeout(() => { 
            m.classList.add('hidden'); 
            const form = m.querySelector('form');
            if(form) form.reset();
            
            // Unlock body scroll only if no other fixed modals are open
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
        }, 300);
    }

    function switchTab(tab) {
        if (tab === 'hub') {
            $('#tab-hub').addClass('bg-white text-hodBlue shadow-sm').removeClass('text-gray-500 hover:text-gray-900');
            $('#tab-admin').removeClass('bg-white text-hodBlue shadow-sm').addClass('text-gray-500 hover:text-gray-900');
            $('#viewAdmin').addClass('hidden');
            $('#viewHub').removeClass('hidden');
            if (!streamPollingInterval) startStreamPolling();
        } else {
            $('#tab-admin').addClass('bg-white text-hodBlue shadow-sm').removeClass('text-gray-500 hover:text-gray-900');
            $('#tab-hub').removeClass('bg-white text-hodBlue shadow-sm').addClass('text-gray-500 hover:text-gray-900');
            $('#viewHub').addClass('hidden');
            $('#viewAdmin').removeClass('hidden');
            if ($('#kanbanBoard').children().length <= 1) loadAdminKanban();
            if (streamPollingInterval) { clearInterval(streamPollingInterval); streamPollingInterval = null; }
        }
    }

    function handleAjaxForm(formId, successCallback) {
        $(`#${formId}`).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origHtml = btn.html(); 
            btn.prop('disabled', true).html('Saving...');
            lockScreenAction(); 
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).html(origHtml);
                unlockScreenAction(); 
                Toastify({ text: res.message, duration: 3000, gravity: "top", position: "center", style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold" } }).showToast();
                if(res.status === 'success' && successCallback) successCallback();
            }, 'json');
        });
    }

    // Advanced FormData handler for the Stream Chat (handles files)
    $('#formStreamChat').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#btnSendStream');
        const origHtml = btn.html();
        btn.prop('disabled', true).html('<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>');
        
        $.ajax({
            url: API_URL,
            type: 'POST',
            data: new FormData(this),
            contentType: false, processData: false, dataType: 'json',
            success: function(res) {
                btn.prop('disabled', false).html(origHtml);
                if(res.status === 'success') {
                    $('#formStreamChat')[0].reset();
                    clearStreamMedia();
                    $('#inpStreamContent').css('height', ''); // reset auto-grow
                    loadStreamFeed();
                } else {
                    Toastify({ text: res.message, style: { background: "#EF4444", borderRadius: "10px" } }).showToast();
                }
            },
            error: function() {
                btn.prop('disabled', false).html(origHtml);
                Toastify({ text: "Server error.", style: { background: "#EF4444" } }).showToast();
            }
        });
    });

    function previewStreamMedia(input) {
        if (input.files && input.files[0]) {
            $('#mediaFileName').text(input.files[0].name);
            $('#mediaPreviewBox').removeClass('hidden');
        }
    }
    function clearStreamMedia() {
        $('#inpStreamMedia').val('');
        $('#mediaPreviewBox').addClass('hidden');
    }

    // ==========================================
    // TAB 1: MEMBER HUB & REGIONAL STREAM
    // ==========================================
    function loadMyRegion() {
        $.post(API_URL, { action: 'fetch_my_region_hub' }, function(res) {
            if (res.status === 'unassigned') {
                $('#unassignedWarning').removeClass('hidden');
                $('#hubContent').addClass('hidden');
                return;
            }

            if (res.status === 'success') {
                $('#unassignedWarning').addClass('hidden');
                $('#hubContent').removeClass('hidden');
                
                const r = res.data.region;
                $('#hubRegionName').text(r.name);
                $('#hubPopulation').text(res.data.population);
                
                // Build Leadership
                let leadHtml = '';
                const roles = [
                    { title: 'Director in Charge', fn: r.dir_first, ln: r.dir_last, pic: r.dir_pic, phone: r.dir_phone },
                    { title: 'Pastor in Charge', fn: r.pas_first, ln: r.pas_last, pic: r.pas_pic, phone: r.pas_phone },
                    { title: 'Regional Head', fn: r.head_first, ln: r.head_last, pic: r.head_pic, phone: r.head_phone }
                ];

                roles.forEach(role => {
                    if (role.fn) {
                        const avatar = role.pic ? `<img src="${role.pic}" class="w-10 h-10 rounded-full object-cover">` : `<div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center font-bold text-gray-500">${role.fn[0]}</div>`;
                        const callBtn = role.phone ? `<a href="tel:${role.phone}" class="text-blue-500 bg-blue-50 p-2 rounded-full hover:bg-blue-100 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg></a>` : '';
                        
                        leadHtml += `
                        <div class="flex items-center justify-between group">
                            <div class="flex items-center gap-3">
                                ${avatar}
                                <div>
                                    <p class="font-bold text-sm text-gray-900 group-hover:text-hodBlue transition">${role.fn} ${role.ln}</p>
                                    <p class="text-[10px] uppercase text-gray-500 tracking-wider">${role.title}</p>
                                </div>
                            </div>
                            ${callBtn}
                        </div>`;
                    }
                });
                $('#hubLeadership').html(leadHtml || '<p class="text-xs text-gray-400 italic">Leadership updating...</p>');

                loadNoticeboard();
                startStreamPolling();
            }
        }, 'json');
    }

    function loadNoticeboard() {
        $.post(API_URL, { action: 'fetch_broadcasts' }, function(res) {
            if (res.status === 'success') {
                let html = '';
                if (res.data.length === 0) {
                    html = '<div class="text-center text-gray-400 text-sm w-full italic">No announcements at this time.</div>';
                } else {
                    res.data.forEach(b => {
                        const date = new Date(b.created_at).toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
                        const safeMsg = encodeURIComponent(b.message);
                        
                        // Strip newlines for the preview snippet to prevent UI breakage
                        const snippet = b.message.replace(/\n/g, ' ');

                        // Changed to h-16, single line truncate, right-aligned arrow button
                        html += `
                        <div onclick="showFullNotice('${safeMsg}', '${date}')" class="min-w-[220px] max-w-[220px] h-16 bg-white border border-orange-100/50 rounded-xl p-3 shadow-sm shrink-0 flex flex-col justify-center relative overflow-hidden cursor-pointer hover:border-orange-300 transition-colors group">
                            <div class="absolute right-0 top-0 w-10 h-10 bg-orange-50 rounded-bl-full z-0 pointer-events-none transition-transform group-hover:scale-110"></div>
                            
                            <div class="flex justify-between items-center relative z-10 w-full">
                                <div class="flex flex-col overflow-hidden pr-3">
                                    <span class="text-[9px] font-bold text-orange-500 uppercase tracking-wider">${date}</span>
                                    <span class="text-xs text-gray-800 font-medium truncate w-full">${snippet}</span>
                                </div>
                                <div class="bg-orange-50 p-1.5 rounded-full text-orange-600 shrink-0 group-hover:bg-orange-100 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>
                        </div>`;
                    });
                }
                $('#hubNoticeboard').html(html);
            }
        }, 'json');
    }

    function showFullNotice(encodedMessage, date) {
        const message = decodeURIComponent(encodedMessage);
        $('#noticeModalDate').text(date);
        $('#noticeModalContent').text(message);
        openModal('noticeModal'); // Uses your existing modal animation logic
    }

    function loadStreamFeed() {
        $.post(API_URL, { action: 'fetch_stream' }, function(res) {
            if (res.status === 'success') {
                let html = '';
                if (res.data.length === 0) {
                    html = '<div class="text-center py-20 text-gray-400 text-sm font-medium">Say hello to your region! 👋</div>';
                } else {
                    res.data.forEach(m => {
                        const isMe = m.user_id == MY_USER_ID;
                        const time = new Date(m.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
                        const senderName = isMe ? 'You' : `${m.first_name} ${m.last_name}`;
                        const avatar = m.picture_path ? `<img src="${m.picture_path}" class="w-8 h-8 rounded-full object-cover shrink-0 mt-1">` : `<div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center font-bold text-gray-500 text-xs shrink-0 mt-1">${m.first_name ? m.first_name[0] : 'S'}</div>`;
                        
                        // Admin Moderation Delete Button
                        const deleteBtn = IS_ADMIN ? `<button onclick="deleteStreamMessage(${m.id})" class="text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition p-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>` : '';

                        // Render based on message_type
                        if (m.message_type === 'text' || m.message_type === 'image' || m.message_type === 'voice') {
                            
                            let mediaHtml = '';
                            if (m.message_type === 'image' && m.media_path) {
                                mediaHtml = `<img src="${m.media_path}" class="rounded-xl max-w-full max-h-64 object-cover mb-2 cursor-pointer hover:opacity-90" onclick="window.open('${m.media_path}', '_blank')">`;
                            } else if (m.message_type === 'voice' && m.media_path) {
                                mediaHtml = `<audio controls class="h-10 w-48 sm:w-64 mb-2 outline-none"><source src="${m.media_path}" type="audio/mpeg"></audio>`;
                            }

                            const textHtml = m.content ? `<p class="text-sm leading-relaxed">${m.content}</p>` : '';

                            if (isMe) {
                                html += `
                                <div class="flex justify-end gap-3 group">
                                    <div class="flex flex-col items-end">
                                        <div class="flex items-center gap-1 mb-1">
                                            ${deleteBtn}
                                            <span class="text-[10px] font-bold text-gray-400">${time}</span>
                                        </div>
                                        <div class="bg-hodBlue text-white p-3 rounded-2xl rounded-tr-sm max-w-[85%] shadow-sm">
                                            ${mediaHtml}
                                            ${textHtml}
                                        </div>
                                    </div>
                                </div>`;
                            } else {
                                html += `
                                <div class="flex justify-start gap-3 group">
                                    ${avatar}
                                    <div class="flex flex-col items-start">
                                        <div class="flex items-center gap-2 mb-1 pl-1">
                                            <span class="text-[10px] font-bold text-gray-700">${senderName}</span>
                                            <span class="text-[10px] font-bold text-gray-400">${time}</span>
                                            ${deleteBtn}
                                        </div>
                                        <div class="bg-white text-gray-800 p-3 rounded-2xl rounded-tl-sm max-w-[85%] shadow-sm border border-gray-100">
                                            ${mediaHtml}
                                            ${textHtml}
                                        </div>
                                    </div>
                                </div>`;
                            }

                        } 
                        // System Event Rendering (Cards)
                        else {
                            let icon, color, bg, title;
                            if (m.message_type === 'system_prayer') { icon = 'M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z'; color = 'text-purple-600'; bg = 'bg-purple-50'; title = 'Prayer Request'; }
                            else if (m.message_type === 'system_testimony') { icon = 'M5 13l4 4L19 7'; color = 'text-green-600'; bg = 'bg-green-50'; title = 'Testimony Shared'; }
                            else if (m.message_type === 'system_carpool') { icon = 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'; color = 'text-blue-600'; bg = 'bg-blue-50'; title = 'Carpool Alert'; }
                            else { icon = 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z'; color = 'text-orange-600'; bg = 'bg-orange-50'; title = 'Official Broadcast'; }

                            html += `
                            <div class="flex justify-center my-4 group relative">
                                <div class="bg-white border border-gray-200 rounded-2xl p-4 max-w-[90%] shadow-sm w-full md:w-[400px]">
                                    <div class="flex justify-between items-start mb-2">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 rounded-full ${bg} ${color} flex items-center justify-center shrink-0">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${icon}"></path></svg>
                                            </div>
                                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">${title} • ${senderName}</span>
                                        </div>
                                        ${deleteBtn}
                                    </div>
                                    <p class="text-sm font-bold text-gray-800 leading-snug">${m.content}</p>
                                </div>
                            </div>`;
                        }
                    });
                }
                
                const container = document.getElementById('streamContainer');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
                
                $('#streamContainer').html(html);
                
                // Auto-scroll logic: If they were already at the bottom, keep them there.
                if (isScrolledToBottom) {
                    container.scrollTop = container.scrollHeight;
                }
            }
        }, 'json');
    }

    function startStreamPolling() {
        loadStreamFeed(); // initial load
        streamPollingInterval = setInterval(loadStreamFeed, 10000); // Check every 10 secs
    }

    function deleteStreamMessage(id) {
        if(!confirm("Remove this message from the stream?")) return;
        $.post(API_URL, { action: 'admin_delete_stream_message', message_id: id }, function(res) {
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
            if(res.status === 'success') loadStreamFeed();
        }, 'json');
    }

    // ==========================================
    // TAB 2: ADMIN KANBAN LOGIC
    // ==========================================
    
    // Fetch Leaders for the Dropdowns in the Create/Edit Region Modal
    function populateLeadershipDropdowns(cb) {
        $.post(API_URL, {action: 'admin_fetch_leaders'}, function(res) {
            if(res.status === 'success') {
                let opts = '<option value="">None / Unassigned</option>';
                res.data.forEach(l => {
                    const cleanRole = l.role_name.replace('_', ' ');
                    opts += `<option value="${l.id}">${l.first_name} ${l.last_name} - [${cleanRole}]</option>`;
                });
                $('#selDirector, #selPastor, #selHead').html(opts);
                if (cb) cb();
            }
        }, 'json');
    }

    function openManageRegionModal(id = '', name = '', desc = '', color = '#1D356A') {
        populateLeadershipDropdowns(function() {
            $('#inpRegionId').val(id);
            $('#inpRegionName').val(name);
            $('#inpRegionDesc').val(desc);
            $('#inpRegionColor').val(color);
            $('#regionModalTitle').text(id ? 'Edit Region' : 'Create New Region');
            $('#btnSaveRegion').text(id ? 'Update Region' : 'Save Region');
            
            // Note: In a fully optimal system, we would map the exact leader IDs here for editing. 
            // For now, they must re-select.
            $('#selDirector, #selPastor, #selHead').val('');
            
            openModal('manageRegionModal');
        });
    }

    function loadAdminKanban() {
        $.post(API_URL, { action: 'admin_fetch_kanban' }, function(res) {
            if(res.status === 'success') {
                const b = res.data;
                let html = '';
                let broadcastOptions = '';
                globalAdminRegions = [];

                const renderCard = (u) => {
                    const avatar = u.picture_path ? `<img src="${u.picture_path}" class="w-8 h-8 rounded-full object-cover">` : `<div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center font-bold text-gray-500 text-xs">${u.first_name[0]}</div>`;
                    const clickAction = `onclick="openMobileReassign(${u.id}, ${u.region_id || 'null'}, ${u.is_muted_in_region || 0})"`;
                    
                    // Show a mute icon if muted
                    const mutedBadge = u.is_muted_in_region == 1 ? `<div class="absolute -top-2 -right-2 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center shadow-sm" title="Muted"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg></div>` : '';
                    
                    // Added data-search-name attribute for lightning fast client-side filtering
                    const searchName = `${u.first_name} ${u.last_name}`.toLowerCase();

                    return `
                    <div class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm cursor-grab hover:border-hodBlue transition relative group select-none unassigned-card" data-id="${u.id}" data-search-name="${searchName}" ${clickAction}>
                        ${mutedBadge}
                        <div class="flex items-center gap-3">
                            ${avatar}
                            <div class="overflow-hidden">
                                <p class="font-bold text-sm text-gray-900 truncate">${u.first_name} ${u.last_name}</p>
                                <p class="text-[10px] text-gray-500 truncate">${u.physical_address || 'No address mapped'}</p>
                            </div>
                        </div>
                    </div>`;
                };

                let unassignedCards = '';
                b.unassigned.forEach(u => unassignedCards += renderCard(u));
                
                // Added the sticky input search field inside the Unassigned column
                html += `
                <div class="flex-none w-80 bg-gray-50/80 rounded-2xl border border-gray-200 flex flex-col max-h-[700px] relative">
                    <div class="p-4 border-b border-gray-200 bg-gray-100 rounded-t-2xl shrink-0 flex justify-between items-center z-10">
                        <h4 class="font-bold text-gray-700">Unassigned</h4>
                        <span class="bg-white text-gray-600 text-xs font-bold px-2 py-0.5 rounded shadow-sm">${b.unassigned.length}</span>
                    </div>
                    <div class="p-3 bg-gray-50 border-b border-gray-200 shrink-0 sticky top-0 z-10">
                        <div class="relative">
                            <svg class="w-4 h-4 absolute left-3 top-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <input type="text" onkeyup="filterUnassigned(this.value)" placeholder="Type 2+ chars to filter..." class="w-full pl-9 pr-3 py-2 text-xs font-medium border border-gray-200 rounded-lg outline-none focus:border-hodBlue focus:ring-1 focus:ring-hodBlue transition bg-white shadow-sm">
                        </div>
                    </div>
                    <div class="p-3 flex-1 overflow-y-auto custom-scrollbar space-y-3 kanban-col relative" data-region-id="">
                        ${unassignedCards}
                    </div>
                </div>`;

                Object.values(b.regions).forEach(reg => {
                    const r = reg.details;
                    globalAdminRegions.push(r); 
                    broadcastOptions += `<option value="${r.id}">${r.name}</option>`;

                    let cards = '';
                    reg.users.forEach(u => cards += renderCard(u));

                    const color = r.color_code || '#1D356A';

                    html += `
                    <div class="flex-none w-80 bg-gray-50/80 rounded-2xl border border-gray-200 flex flex-col max-h-[700px]">
                        <div class="p-4 border-b border-gray-200 rounded-t-2xl shrink-0 flex justify-between items-center group" style="background-color: ${color}15;">
                            <div class="flex items-center gap-2">
                                <h4 class="font-bold text-gray-900" style="color: ${color};">${r.name}</h4>
                                <button onclick="openManageRegionModal(${r.id}, '${r.name.replace(/'/g, "\\'")}', '${(r.description || '').replace(/'/g, "\\'")}', '${color}')" class="opacity-0 group-hover:opacity-100 transition-opacity p-1 hover:bg-white/50 rounded text-gray-500 hover:text-gray-900">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                            </div>
                            <span class="bg-white text-gray-600 text-xs font-bold px-2 py-0.5 rounded shadow-sm">${reg.users.length}</span>
                        </div>
                        <div class="p-3 flex-1 overflow-y-auto custom-scrollbar space-y-3 kanban-col" data-region-id="${r.id}">
                            ${cards}
                        </div>
                    </div>`;
                });

                $('#kanbanBoard').html(html);
                $('#broadcastRegionSelect').html('<option value="">Select Target Region...</option>' + broadcastOptions);

                if (window.innerWidth >= 768) {
                    initSortable();
                }
            }
        }, 'json');
    }

    function initSortable() {
        const columns = document.querySelectorAll('.kanban-col');
        columns.forEach(col => {
            new Sortable(col, {
                group: 'regions', 
                animation: 150,
                ghostClass: 'opacity-50',
                onEnd: function (evt) {
                    const itemEl = evt.item;  
                    const toList = evt.to;    
                    
                    const userId = itemEl.getAttribute('data-id');
                    const newRegionId = toList.getAttribute('data-region-id');

                    if (evt.from === toList) return;

                    lockScreenAction();
                    $.post(API_URL, { action: 'admin_reassign_user', target_user_id: userId, new_region_id: newRegionId }, function(res) {
                        unlockScreenAction();
                        if(res.status !== 'success') {
                            Toastify({ text: "Failed to move. Reloading board.", style: { background: "#EF4444" } }).showToast();
                            loadAdminKanban(); 
                        } else {
                            const fromCountEl = evt.from.previousElementSibling.querySelector('span');
                            const toCountEl = toList.previousElementSibling.querySelector('span');
                            fromCountEl.innerText = parseInt(fromCountEl.innerText) - 1;
                            toCountEl.innerText = parseInt(toCountEl.innerText) + 1;
                        }
                    }, 'json');
                }
            });
        });
    }
    
    function filterUnassigned(query) {
        const queryLower = query.toLowerCase().trim();
        const cards = document.querySelectorAll('.kanban-col[data-region-id=""] .unassigned-card');
        
        // Only trigger the filter if the user has typed 2 or more characters.
        // If less than 2, reset and show everything.
        if (queryLower.length > 0 && queryLower.length < 2) {
            return; 
        }

        cards.forEach(card => {
            if (queryLower === '') {
                card.style.display = 'block'; // Reset
            } else {
                const name = card.getAttribute('data-search-name');
                if (name.includes(queryLower)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            }
        });
    }

    function exportRegionalData() {
        // Triggering standard browser download via location routing instead of AJAX 
        // to properly handle the binary application/vnd.openxmlformats stream
        window.location.href = API_URL + '?action=admin_export_excel';
    }

    // Mobile Reassign Sheet (Also acts as User Context Menu for Muting)
    function openMobileReassign(userId, currentRegionId, isMuted) {
        targetUserIdForMobile = userId;
        let html = '';
        
        // Mute/Unmute Toggle
        if (isMuted == 1) {
            html += `<button onclick="executeMuteUser(0)" class="w-full text-left p-4 rounded-xl border border-green-200 bg-green-50 font-bold text-green-700 hover:bg-green-100 transition mb-4">Restore Stream Privileges (Unmute)</button>`;
        } else {
            html += `<button onclick="executeMuteUser(1)" class="w-full text-left p-4 rounded-xl border border-red-200 bg-red-50 font-bold text-red-700 hover:bg-red-100 transition mb-4">Mute in Regional Stream</button>`;
        }

        if (currentRegionId) {
            html += `<button onclick="executeMobileReassign('')" class="w-full text-left p-4 rounded-xl border border-gray-200 font-bold text-gray-700 hover:bg-gray-50 transition">Move to Unassigned</button>`;
        }

        globalAdminRegions.forEach(r => {
            if (r.id != currentRegionId) {
                const color = r.color_code || '#1D356A';
                html += `<button onclick="executeMobileReassign(${r.id})" class="w-full text-left p-4 rounded-xl border border-gray-200 font-bold hover:bg-gray-50 transition mt-3" style="color: ${color}; border-left: 4px solid ${color};">Move to ${r.name}</button>`;
            }
        });

        $('#mobileReassignOptions').html(html);
        openModal('mobileReassignSheet');
    }

    function executeMobileReassign(newRegionId) {
        if (!targetUserIdForMobile) return;
        closeModal('mobileReassignSheet');
        lockScreenAction();
        
        $.post(API_URL, { action: 'admin_reassign_user', target_user_id: targetUserIdForMobile, new_region_id: newRegionId }, function(res) {
            unlockScreenAction();
            if(res.status === 'success') {
                loadAdminKanban(); 
            } else {
                Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
            }
        }, 'json');
    }

    function executeMuteUser(status) {
        if (!targetUserIdForMobile) return;
        closeModal('mobileReassignSheet');
        lockScreenAction();
        
        $.post(API_URL, { action: 'admin_mute_user', target_user_id: targetUserIdForMobile, mute_status: status }, function(res) {
            unlockScreenAction();
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444" } }).showToast();
            if(res.status === 'success') loadAdminKanban(); 
        }, 'json');
    }

    function runAutoAssign() {
        if(!confirm("Execute the Autonomous Auto-Assign algorithm? This will evaluate the Google Maps coordinates of all unassigned members and automatically place them in the correct region based on the Jakande Pivot logic.")) return;
        
        const btn = $('#btnAutoAssign');
        const orig = btn.html();
        btn.prop('disabled', true).html('<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Analyzing Maps...');
        
        lockScreenAction();
        $.post(API_URL, { action: 'admin_auto_assign' }, function(res) {
            unlockScreenAction();
            btn.prop('disabled', false).html(orig);
            Toastify({ text: res.message, duration: 4000, style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold" } }).showToast();
            if(res.status === 'success') loadAdminKanban();
        }, 'json');
    }

    // Admin Broadcast Functions
    function fetchAdminBroadcasts() {
        const regionId = $('#broadcastRegionSelect').val();
        if(!regionId) {
            $('#adminBroadcastList').html('<p class="text-xs text-gray-400 italic text-center py-4">Select a region above to view history.</p>');
            return;
        }

        $('#adminBroadcastList').html('<p class="text-xs text-center text-hodBlue py-4">Loading...</p>');

        $.post(API_URL, { action: 'fetch_broadcasts', region_id: regionId }, function(res) {
            if(res.status === 'success') {
                let html = '';
                if(res.data.length === 0) {
                    html = '<p class="text-xs text-gray-400 italic text-center py-4">No broadcasts for this region yet.</p>';
                } else {
                    res.data.forEach(b => {
                        const date = new Date(b.created_at).toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
                        html += `
                        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">${date}</span>
                                <div class="flex gap-2">
                                    <button type="button" onclick="editBroadcast(${b.id}, '${b.message.replace(/'/g, "\\'")}')" class="text-xs text-blue-500 hover:underline font-bold">Edit</button>
                                    <button type="button" onclick="deleteBroadcast(${b.id})" class="text-xs text-red-500 hover:underline font-bold">Delete</button>
                                </div>
                            </div>
                            <p class="text-xs text-gray-700 whitespace-pre-wrap">${b.message}</p>
                        </div>`;
                    });
                }
                $('#adminBroadcastList').html(html);
            }
        }, 'json');
    }

    function editBroadcast(id, message) {
        $('#broadcastId').val(id);
        $('#broadcastMessage').val(message);
        $('#broadcastManageAction').val('update');
        $('#btnSaveBroadcast').text('Update Broadcast');
        $('#btnCancelEditBroadcast').removeClass('hidden');
    }

    function resetBroadcastForm() {
        $('#broadcastId').val('');
        $('#broadcastMessage').val('');
        $('#broadcastManageAction').val('create');
        $('#btnSaveBroadcast').html('<svg class="w-5 h-5 inline-block -mt-1 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg> Send Blast');
        $('#btnCancelEditBroadcast').addClass('hidden');
    }

    function deleteBroadcast(id) {
        if(!confirm('Are you sure you want to permanently delete this broadcast?')) return;
        lockScreenAction();
        $.post(API_URL, { action: 'admin_manage_broadcast', manage_action: 'delete', broadcast_id: id }, function(res) {
            unlockScreenAction();
            Toastify({ text: res.message, style: { background: res.status === 'success' ? "#10B981" : "#EF4444", borderRadius: "10px", fontWeight: "bold" } }).showToast();
            if(res.status === 'success') fetchAdminBroadcasts();
        }, 'json');
    }

    // ==========================================
    // INITIALIZATION
    // ==========================================
    $(document).ready(function() {
        loadMyRegion();

        handleAjaxForm('formPrayer', function() {
            closeModal('addPrayerModal');
        });

        handleAjaxForm('formCarpool', function() {
            closeModal('addCarpoolModal');
        });

        handleAjaxForm('formManageRegion', function() {
            closeModal('manageRegionModal');
            loadAdminKanban(); 
        });

        handleAjaxForm('formBroadcast', function() {
            resetBroadcastForm();
            fetchAdminBroadcasts();
        });
    });

</script>

<?php require_once '../../includes/footer.php'; ?>