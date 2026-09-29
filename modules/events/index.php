<?php
// /modules/events/index.php
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<div class="max-w-7xl mx-auto space-y-6 pb-12">

    <!-- Header -->
    <div class="bg-white p-4 md:p-6 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-center gap-6">
        <div class="absolute top-0 right-0 w-64 loadEventIntoConfigure(h-64 bg-blue-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-12 h-12 md:w-14 md:h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-6 h-6 md:w-7 md:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">Events Center</h2>
                <p class="text-gray-500 text-xs md:text-sm mt-1 font-medium">Build, publish & track exceptional events.</p>
            </div>
        </div>
        <div class="relative z-10 flex overflow-x-auto custom-scrollbar bg-gray-50/80 p-1.5 rounded-2xl border border-gray-100 w-full md:w-auto">
            <button onclick="switchSection('events')" id="btn-events" class="shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-hodBlue shadow-sm">Events</button>
            <button onclick="switchSection('configure')" id="btn-configure" class="shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Configure</button>
            <button onclick="switchSection('registrations')" id="btn-registrations" class="shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Registrations</button>
            <button onclick="switchSection('attendance')" id="btn-attendance" class="shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Attendance</button>
            <button onclick="switchSection('analytics')" id="btn-analytics" class="shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900 flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Analytics
            </button>
        </div>
    </div>

    <!-- ============================ SECTION: EVENTS ============================ -->
    <section id="section-events" class="space-y-6 animate-fade-in-up">
        <div class="flex flex-col sm:flex-row sm:flex-wrap justify-between items-stretch sm:items-center gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
            <div class="relative w-full sm:w-80">
                <input type="text" id="searchEvents" placeholder="Search events..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all bg-gray-50">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <button onclick="startNewEvent()" class="bg-hodBlue hover:bg-[#152750] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center justify-center gap-2 text-sm shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New Event
            </button>
            <button onclick="openMonthlyServicesModal()" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center justify-center gap-2 text-sm shrink-0" title="Create all Total Experience and Mercy Experience services for a month">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                Monthly
            </button>
            <a href="/modules/event_qr/index.php"
               class="inline-flex items-center gap-2 bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all shrink-0"
               title="Generate a branded event QR poster">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h4v4H7V4zm6 0h4v4h-4V4zM3 14h4v4H3v-4zm12 0h6v6h-6v-6zm-2 0v-2h-2v-2h4v4h-2zm-6 0H7v2H5v-4h2v2zm6 4h2v-2h2v4h-4v-2z"/></svg>
                Branded QR
            </a>
            <a href="/modules/event_report/index.php"
               class="inline-flex items-center gap-2 bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all shrink-0"
               title="Open Event Data & Engagement Report (SMS, registration, attendance, IDI)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Event Report
            </a>
            <details class="relative shrink-0 group">
                <summary class="list-none cursor-pointer inline-flex w-full items-center justify-center gap-2 bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all text-sm [&::-webkit-details-marker]:hidden">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4m11-5a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Check-in
                    <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-xl border border-gray-100 bg-white p-1.5 shadow-xl">
                    <a href="/modules/checkin_qr/index.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue" title="Generate the check-in QR code for this event">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h6v6H3V3zm12 0h6v6h-6V3zM3 15h6v6H3v-6zm12 0h2v2h-2v-2zm4 0h2v6h-6v-2h4v-4z"/></svg>
                        Check-in QR
                    </a>
                    <a href="/modules/checkin_monitor/index.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue" title="View live check-in KPIs">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                        Check-in Monitor
                    </a>
                </div>
            </details>
        </div>
        <div id="eventsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5"></div>
    </section>

    <!-- ============================ SECTION: CONFIGURE ============================ -->
    <section id="section-configure" class="hidden space-y-6 animate-fade-in-up">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            <!-- Builder -->
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-7 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900">Configure Event</h3>
                    <span id="cfgModeBadge" class="text-[10px] font-black uppercase tracking-widest bg-blue-50 text-hodBlue px-2.5 py-1 rounded">New</span>
                </div>

                <form id="eventForm" class="space-y-4" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="cfgAction" value="create_event">
                    <input type="hidden" name="event_id" id="cfgEventId" value="">
                    <input type="hidden" name="is_custom_period" id="cfgCustomPeriodFlag" value="0">

                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Event Title *</label>
                        <input type="text" name="title" id="cfg_title" required placeholder="e.g., EXOUSIA 2026" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Category *</label>
                            <select name="event_category" id="cfg_category" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none cursor-pointer bg-white">
                                <option value="Sunday_Service">Sunday Service</option>
                                <option value="Midweek_Service">Midweek Service</option>
                                <option value="Tribe_Meeting">Tribe Meeting</option>
                                <option value="Department_Meeting">Department Meeting</option>
                                <option value="Evangelism">Evangelism</option>
                                <option value="Retreat">Retreat</option>
                                <option value="Academy_Class">Academy Class</option>
                                <option value="Conference">Conference</option>
                                <option value="Prayer_Vigil">Prayer Vigil</option>
                            </select>
                        </div>
                        <div>
                            <label id="cfgDateLabel" class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Date & Time *</label>
                            <input type="datetime-local" name="event_date" id="cfg_date" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none">
                        </div>
                    </div>

                    <label class="flex items-center gap-3 cursor-pointer group bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
                        <input type="checkbox" id="cfgCustomPeriod" class="w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                        <div>
                            <p class="font-bold text-gray-800 text-sm">Custom Period (Multi-Day Event)</p>
                            <p class="text-xs text-gray-500">Registrants pick which day(s) they'll attend.</p>
                        </div>
                    </label>
                    <div id="cfgEndDateWrap" class="hidden">
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">End Date *</label>
                        <input type="date" name="end_date" id="cfg_end_date" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Location</label>
                        <input type="text" name="location" id="cfg_location" placeholder="e.g., Main Auditorium, Lekki" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">YouTube Video Link</label>
                        <input type="url" name="youtube_url" id="cfg_youtube_url" placeholder="e.g., https://youtu.be/..." class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none">
                        <p class="text-[11px] text-gray-400 mt-1.5">Paste any YouTube URL. We will automatically format it for a seamless registration experience.</p>
                    </div>
                    
                    <!-- NEW FIELD: External Registration -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">External Registration URL</label>
                        <input type="url" name="external_registration_url" id="cfg_external_registration_url" placeholder="e.g., https://householdofdavid.org/conferences" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none">
                        <p class="text-[11px] text-gray-400 mt-1.5">Optional: If provided, a prominent button to register on this external site will appear below the video.</p>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Description</label>
                        <div id="cfgDescriptionEditor"></div>
                        <textarea name="description" id="cfg_description" class="hidden"></textarea>
                        <p class="text-[11px] text-gray-400 mt-1.5">Use the toolbar to make text <b>bold</b> and press <b>Enter</b> for a new line.</p>
                    </div>

                    <!-- Banner -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Banner Image</label>
                        <input type="file" name="banner_image" id="cfg_banner" accept="image/png,image/jpeg,image/webp" class="w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-hodBlue hover:file:bg-blue-100 border border-gray-200 rounded-xl cursor-pointer">
                        <img id="bannerPreview" class="hidden mt-3 w-full h-32 object-cover rounded-xl border border-gray-200">
                    </div>

                    <!-- Ministers (repeatable: photo + name per minister) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-gray-600 uppercase">Ministers / Speakers</label>
                            <button type="button" onclick="addMinister()" class="text-xs font-bold text-hodBlue hover:underline">+ Add Minister</button>
                        </div>
                        <p class="text-[11px] text-gray-400 mb-2">Add each minister with their photo and name. Click the photo to upload. Add as many as you need.</p>
                        <input type="hidden" name="ministers_json" id="cfg_ministers_json" value="[]">
                        <div id="ministersList" class="space-y-3"></div>
                    </div>

                    <div class="bg-blue-50/40 p-4 rounded-2xl border border-blue-100 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="requires_registration" id="cfg_requires" value="1" class="mt-1 w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                            <div><p class="font-bold text-gray-800 text-sm">Generate Registration Link</p><p class="text-xs text-gray-500">Creates a public shareable URL.</p></div>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="allow_visitors" id="cfg_visitors" value="1" class="mt-1 w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                            <div><p class="font-bold text-gray-800 text-sm">Open to Everyone (Public)</p><p class="text-xs text-gray-500">Allow visitors & non-members to register.</p></div>
                        </label>
                    </div>

                    <button type="submit" id="cfgSubmitBtn" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all flex justify-center items-center gap-2">Create Event</button>
                </form>

                <!-- Lifecycle (edit only) -->
                <div id="lifecycleBox" class="hidden mt-5 pt-5 border-t border-gray-100 space-y-4">
                    <div id="registrationLinkBox" class="hidden bg-gray-50 p-4 rounded-2xl border border-gray-200">
                        <p class="text-xs font-bold text-gray-700 mb-2 uppercase tracking-widest">Public Registration Link</p>
                        <div class="flex flex-wrap gap-2">
                        <input type="text" id="regTokenInput" readonly class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white text-gray-600 truncate mb-1 sm:mb-0">
                        <button type="button" onclick="copyRegLink()" class="flex-1 sm:flex-none bg-hodBlue text-white px-4 py-2 rounded-lg hover:bg-[#152750] text-xs font-bold transition-colors" title="Copy Link">Copy</button>
                        <button type="button" onclick="shareWhatsApp()" class="flex-1 sm:flex-none bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 text-xs font-bold transition-colors flex items-center justify-center gap-1" title="Share on WhatsApp">WhatsApp</button>
                        <button type="button" onclick="shareRegLink()" class="flex-1 sm:flex-none bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200 text-xs font-bold transition-colors" title="System Share">System Share</button>
                    </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5">Event Report & Notes</label>
                        <textarea id="eventReportNotes" class="w-full p-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none text-sm resize-none" rows="3" placeholder="Highlights, issues, remarks..."></textarea>
                        <button onclick="saveReport()" id="btnSaveReport" class="mt-2 w-full bg-gray-900 hover:bg-black text-white py-2.5 rounded-xl font-bold transition-all">Save Report</button>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="closeEventAction()" id="btnCloseEventAction" class="flex-1 bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2.5 rounded-xl font-bold border border-red-200 transition-colors">Officially Close</button>
                        <?php if (isset($_SESSION['roles'])) { $sa=false; foreach($_SESSION['roles'] as $r){ if($r['role_name']==='Super_Admin'){$sa=true;break;} } if($sa): ?>
                        <button onclick="unlockEventAction()" id="btnUnlockEventAction" class="hidden flex-1 bg-green-50 text-green-700 hover:bg-green-600 hover:text-white px-4 py-2.5 rounded-xl font-bold border border-green-200 transition-colors">Unlock</button>
                        <?php endif; } ?>
                    </div>
                    <span id="cfgClosedBadge" class="hidden block text-center text-[10px] font-black uppercase tracking-widest bg-red-100 text-red-700 py-1 rounded">Closed</span>
                </div>
            </div>

            <!-- Live Preview -->
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-gray-600">Live Preview — Public Form</h3>
                </div>
                <div id="previewShell" class="preview-shell">
                    <div id="pvBannerBg" class="preview-banner-bg"></div>
                    <div class="mesh-bg"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>
                    <div class="preview-inner">
                        <div class="glass w-full">
                            <div id="pvBanner" class="hidden banner-hero"><img id="pvBannerImg" src=""></div>
                            <div class="p-5">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/10 text-white text-[10px] font-bold uppercase tracking-wider border border-white/20" id="pvCategory">Event</span>
                                </div>
                                <h1 id="pvTitle" class="text-2xl font-extrabold gradient-text mb-2">Your Event Title</h1>
                                <p id="pvDate" class="text-white/70 text-sm flex items-center gap-2 mb-1"><svg class="w-4 h-4 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg><span>Select a date…</span></p>
                                <p id="pvLocation" class="hidden text-white/70 text-sm flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg><span></span></p>
                                <p id="pvDescription" class="hidden text-white/65 text-sm"></p>
                                <div id="pvMinisters" class="hidden mt-4">
                                    <div class="flex items-center gap-2 mb-2"><span class="w-1.5 h-4 rounded-full bg-hodRed"></span><h3 class="text-[11px] font-bold uppercase tracking-wider text-white/70">Ministering</h3></div>
                                    <div id="pvMinistersList" class="grid grid-cols-3 gap-2"></div>
                                </div>
                                <div id="pvDays" class="hidden mt-4"><p class="text-xs font-bold uppercase tracking-wider text-white/60 mb-2">Which day(s)?</p><div id="pvDaysList" class="opt-grid"></div></div>
                                <div id="pvFields" class="space-y-4 mt-4"></div>
                                <div id="pvButton" class="cta mt-5">Confirm Registration</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Field Builder -->
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-7">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    Registration Fields
                </h3>
                <button onclick="openFieldModal()" class="bg-purple-100 hover:bg-purple-200 text-purple-700 font-bold text-xs px-4 py-2.5 rounded-lg transition-colors">+ Add Question</button>
            </div>
            <p class="text-xs text-gray-500 mb-4">These appear on the public form in order. Drag the <span class="font-bold">↑↓</span> to reorder; the live preview updates instantly.</p>
            <div id="fieldsList" class="space-y-2"><p class="text-sm text-gray-400 italic">No custom fields yet. Add one above.</p></div>
        </div>
    </section>

    <!-- ============================ SECTION: REGISTRATIONS ============================ -->
    <section id="section-registrations" class="hidden space-y-6 animate-fade-in-up">
        
        <!-- Top Control Bar -->
        <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100 flex flex-col sm:flex-row gap-4 justify-between items-center">
            <div class="w-full sm:w-1/2">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Select Event</label>
                <select id="regEventSelect" class="w-full px-4 py-3.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none font-bold bg-gray-50 cursor-pointer"><option value="">-- Choose an event --</option></select>
            </div>
            <a id="btnExportRegistrants" href="#" target="_blank" rel="noopener" class="hidden inline-flex items-center gap-2 bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 px-4 py-3 rounded-xl text-xs font-bold transition-colors">Export (Excel)</a>
        </div>

        <!-- KPI Cards Container -->
        <div id="regKpiContainer" class="hidden grid grid-cols-1 md:grid-cols-3 gap-6 animate-fade-in-up">
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Total Registered</p>
                    <h3 id="kpiTotal" class="text-3xl font-black text-gray-900">0</h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-hodBlue">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm flex flex-col justify-center relative overflow-hidden">
                <div class="flex items-center justify-between z-10">
                    <div>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Registered Today</p>
                        <h3 id="kpiToday" class="text-3xl font-black text-gray-900">0</h3>
                    </div>
                    <div id="kpiTrendBadge" class="flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                        <span>--</span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Registered Yesterday</p>
                    <h3 id="kpiYesterday" class="text-3xl font-black text-gray-900">0</h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center text-gray-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
            </div>
        </div>

        <!-- Search & Table Area -->
        <div id="regTableContainer" class="hidden bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden animate-fade-in-up" style="animation-delay: 0.2s;">
            <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-4">
                <h3 class="text-lg font-bold text-gray-900">Attendee List</h3>
                <div class="w-full sm:w-80 relative">
                    <input type="text" id="regSearch" placeholder="Search name, email, or phone..." class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4 w-16 text-center">S/N</th>
                            <th class="px-6 py-4">Attendee Name</th>
                            <th class="px-6 py-4">Contact Info</th>
                            <th class="px-6 py-4">Attending</th>
                            <th class="px-6 py-4">Member Match</th>
                            <th class="px-6 py-4 text-right">Registration Date</th>
                        </tr>
                    </thead>
                    <tbody id="registrationsList" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-4">
                <span id="pageInfo" class="text-xs font-bold text-gray-500 uppercase tracking-widest">Showing 0 records</span>
                <div class="flex gap-2" id="paginationControls"></div>
            </div>
        </div>
    </section>

    <!-- ============================ SECTION: ATTENDANCE ============================ -->
    <section id="section-attendance" class="hidden space-y-6 animate-fade-in-up">
        <div class="bg-white p-5 md:p-6 rounded-3xl shadow-sm border border-gray-100 flex flex-col md:flex-row gap-4 justify-between items-center">
            <div class="w-full md:w-1/2">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Select Event to Track</label>
                <select id="attendanceEventSelect" class="w-full px-4 py-3.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none font-bold bg-gray-50 cursor-pointer"><option value="">-- Choose an active event --</option></select>
            </div>
            <div class="w-full md:w-1/3 relative">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Quick Search</label>
                <input type="text" id="rosterSearch" placeholder="Type a name..." disabled class="w-full pl-10 pr-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue outline-none disabled:bg-gray-100 disabled:cursor-not-allowed">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>
        
                <!-- Attendance KPIs -->
        <div id="attKpiContainer" class="hidden grid grid-cols-1 md:grid-cols-3 gap-6 animate-fade-in-up">
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Total Checked In</p>
                <h3 id="attKpiTotal" class="text-3xl font-black text-gray-900">0</h3>
                <p class="text-[11px] text-gray-400 mt-1" id="attKpiTotalSub">0 registered total</p>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm flex flex-col justify-center relative overflow-hidden">
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Checked In Today</p>
                <h3 id="attKpiToday" class="text-3xl font-black text-gray-900">0</h3>
                <div id="attKpiTrendBadge" class="flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500 w-fit mt-2"><span>--</span></div>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Members / Walk-ins</p>
                <h3 class="text-2xl font-black text-gray-900"><span id="attKpiMembers" class="text-[#1D356A]">0</span> <span class="text-gray-300 text-lg">/</span> <span id="attKpiWalkins" class="text-[#D11920]">0</span></h3>
                <p class="text-[11px] text-gray-400 mt-1">members / walk-ins</p>
            </div>
        </div>
        <div id="rosterContainer" class="hidden grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            <div class="bg-white border border-gray-100 rounded-3xl shadow-sm overflow-hidden flex flex-col h-[600px]">
                <div class="p-5 border-b border-gray-100 bg-red-50/30 flex justify-between items-center">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse"></span> Pending Clock-In</h3>
                    <span id="pendingCount" class="bg-red-100 text-red-700 text-xs font-bold px-3 py-1 rounded-full">0</span>
                </div>
                <div id="pendingList" class="p-4 space-y-3 overflow-y-auto custom-scrollbar flex-1 bg-gray-50/30"></div>
            </div>
            <div class="bg-white border border-gray-100 rounded-3xl shadow-sm overflow-hidden flex flex-col h-[600px]">
                <div class="p-5 border-b border-gray-100 bg-green-50/30 flex justify-between items-center">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span> Checked In</h3>
                    <span id="checkedInCount" class="bg-green-100 text-green-700 text-xs font-bold px-3 py-1 rounded-full">0</span>
                </div>
                <div id="checkedInList" class="p-4 space-y-3 overflow-y-auto custom-scrollbar flex-1 bg-gray-50/30"></div>
            </div>
        </div>
    </section>

    <!-- ============================ SECTION: ANALYTICS ============================ -->
    <section id="section-analytics" class="hidden space-y-5 animate-fade-in-up">

        <!-- Sub-tabs: Midweek | Sunday (each fully independent) -->
        <div class="bg-white p-4 md:p-5 rounded-3xl shadow-sm border border-gray-100/60">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex bg-gray-50/80 p-1.5 rounded-2xl border border-gray-100 w-full md:w-auto">
                    <button onclick="anSwitchCategory('Midweek_Service')" id="anCatBtn-Midweek_Service" class="an-cat-btn flex-1 md:flex-none whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-hodBlue shadow-sm">Midweek Service</button>
                    <button onclick="anSwitchCategory('Sunday_Service')" id="anCatBtn-Sunday_Service" class="an-cat-btn flex-1 md:flex-none whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Sunday Service</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button onclick="openCompareModal()" class="inline-flex items-center gap-1.5 bg-gray-900 hover:bg-black text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-all shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v12m0 0l4-4m-4 4l-4-4"/></svg>
                        Compare Periods
                    </button>
                    <button onclick="anExportCsv()" class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-all shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                        Export CSV
                    </button>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-3 mt-4 pt-4 border-t border-gray-100">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Period</label>
                    <select id="anPeriodPreset" onchange="anOnPresetChange()" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none focus:ring-2 focus:ring-hodBlue cursor-pointer">
                        <option value="8">Last 8 services</option>
                        <option value="3m">Last 3 months</option>
                        <option value="6m">Last 6 months</option>
                        <option value="12m" selected>Last 12 months</option>
                        <option value="ytd">This year (YTD)</option>
                        <option value="all">All time</option>
                        <option value="custom">Custom range…</option>
                    </select>
                </div>
                <div id="anCustomRangeWrap" class="hidden flex-1 flex items-end gap-2 min-w-[280px]">
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">From</label>
                        <input type="date" id="anCustomStart" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
                    </div>
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">To</label>
                        <input type="date" id="anCustomEnd" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
                    </div>
                    <button onclick="anApplyCustomRange()" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-xs font-bold shrink-0">Go</button>
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Chart grouping</label>
                    <select id="anGranularity" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 outline-none focus:ring-2 focus:ring-hodBlue cursor-pointer">
                        <option value="month">Monthly</option>
                        <option value="week">Weekly</option>
                        <option value="service">Per service</option>
                    </select>
                </div>
            </div>
            <p id="anRangeSummary" class="text-[11px] text-gray-400 font-semibold mt-3"></p>
        </div>

        <!-- Loading / Empty states -->
        <div id="anLoading" class="hidden bg-white rounded-3xl border border-gray-100 p-16 text-center">
            <div class="inline-block w-8 h-8 border-4 border-hodBlue/20 border-t-hodBlue rounded-full animate-spin"></div>
            <p class="text-gray-400 text-sm font-bold mt-4">Crunching the numbers…</p>
        </div>
        <div id="anEmpty" class="hidden bg-white rounded-3xl border border-gray-100 p-16 text-center text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            <p class="font-bold text-gray-500">No services found in this period</p>
            <p class="text-xs text-gray-400 mt-1">Try widening the date range above.</p>
        </div>
        <div id="anErrorBox" class="hidden bg-red-50 border border-red-100 text-red-700 rounded-3xl p-6 text-sm font-bold"></div>

        <div id="anContent" class="hidden space-y-5">

            <!-- KPI Cards -->
            <div id="anKpiGrid" class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4"></div>

            <!-- Main Attendance Trend -->
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 md:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
                    <div class="flex items-center gap-1.5">
                        <h3 class="font-bold text-gray-900">Attendance Trend</h3>
                        <button type="button" onclick="anInfo('Attendance Trend','Total number of people marked Present for each period. Click any point on the line to see every service inside that period. The dashed line is the average across the whole chart.')" class="an-info-btn">ⓘ</button>
                    </div>
                    <span class="text-[11px] font-bold text-gray-400" id="anTrendCaption"></span>
                </div>
                <div class="h-64 md:h-80 mt-3"><canvas id="anTrendChart"></canvas></div>
            </div>

            <!-- Gender + Spiritual Status -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 md:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <div class="flex items-center gap-1.5">
                            <h3 class="font-bold text-gray-900">Gender Split</h3>
                            <button type="button" onclick="anInfo('Gender Split','Male vs Female count of everyone marked Present in the selected period, based on each attendee\'s profile in the congregation database.')" class="an-info-btn">ⓘ</button>
                        </div>
                        <div class="flex bg-gray-50 rounded-lg p-1 border border-gray-100">
                            <button onclick="anSetGenderView('totals')" id="anGenderViewBtn-totals" class="an-toggle-btn active">Totals</button>
                            <button onclick="anSetGenderView('trend')" id="anGenderViewBtn-trend" class="an-toggle-btn">Trend</button>
                        </div>
                    </div>
                    <div class="h-56 md:h-64"><canvas id="anGenderChart"></canvas></div>
                </div>
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 md:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <div class="flex items-center gap-1.5">
                            <h3 class="font-bold text-gray-900">Who Attended</h3>
                            <button type="button" onclick="anInfo('Who Attended','Breaks attendance down by each person\'s status on their profile: Member, Worker, Pastor, 1st/2nd/3rd Timer, Visitor or Non-Member. This is the same status used across the rest of the church database.')" class="an-info-btn">ⓘ</button>
                        </div>
                        <div class="flex bg-gray-50 rounded-lg p-1 border border-gray-100">
                            <button onclick="anSetStatusView('totals')" id="anStatusViewBtn-totals" class="an-toggle-btn active">Totals</button>
                            <button onclick="anSetStatusView('trend')" id="anStatusViewBtn-trend" class="an-toggle-btn">Trend</button>
                        </div>
                    </div>
                    <div class="h-56 md:h-64"><canvas id="anStatusChart"></canvas></div>
                </div>
            </div>

            <!-- Region leaderboard -->
            <div id="anRegionCard" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 md:p-6">
                <div class="flex items-center gap-1.5 mb-3">
                    <h3 class="font-bold text-gray-900">Region Leaderboard</h3>
                    <button type="button" onclick="anInfo('Region Leaderboard','Total attendance in the selected period, grouped by each attendee\'s home Region. \'Unassigned\' means the person has a profile but no Region set yet.')" class="an-info-btn">ⓘ</button>
                </div>
                <div class="h-64 md:h-72"><canvas id="anRegionChart"></canvas></div>
            </div>

            <!-- Services table -->
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-4 md:p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-1.5">
                        <h3 class="font-bold text-gray-900">Services</h3>
                        <button type="button" onclick="anInfo('Services table','Every individual service inside the selected period. \'vs previous\' compares each service to the one right before it (same service type), even if that one falls outside the selected range. Tap the ⓘ on any row to open the full breakdown.')" class="an-info-btn">ⓘ</button>
                    </div>
                    <div class="relative w-full sm:w-72">
                        <input type="text" id="anTableSearch" placeholder="Search by title or date…" oninput="anRenderTable()" class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue bg-gray-50">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>
                <div class="overflow-x-auto hidden md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                            <tr>
                                <th class="px-5 py-3 cursor-pointer select-none" onclick="anSort('event_date')">Date <span class="an-sort-ind" data-col="event_date"></span></th>
                                <th class="px-5 py-3">Title</th>
                                <th class="px-5 py-3 cursor-pointer select-none" onclick="anSort('total')">Attendance <span class="an-sort-ind" data-col="total"></span></th>
                                <th class="px-5 py-3">Male / Female</th>
                                <th class="px-5 py-3 cursor-pointer select-none" onclick="anSort('first_timers')">1st Timers <span class="an-sort-ind" data-col="first_timers"></span></th>
                                <th class="px-5 py-3">Workers</th>
                                <th class="px-5 py-3 cursor-pointer select-none" onclick="anSort('vs_previous')">vs Previous <span class="an-sort-ind" data-col="vs_previous"></span></th>
                                <th class="px-5 py-3 text-right">Details</th>
                            </tr>
                        </thead>
                        <tbody id="anTableBody" class="divide-y divide-gray-50 text-gray-700"></tbody>
                    </table>
                </div>
                <div id="anCardList" class="md:hidden divide-y divide-gray-50"></div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-3">
                    <span id="anPageInfo" class="text-[11px] font-bold text-gray-500 uppercase tracking-widest">Showing 0</span>
                    <div class="flex gap-2" id="anPaginationControls"></div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Info popover modal (generic, reused everywhere for "ⓘ" triggers) -->
<div id="anInfoModal" class="fixed inset-0 w-screen h-screen bg-gray-900/70 backdrop-blur-sm hidden z-[10050] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
            <h3 id="anInfoTitle" class="text-sm font-bold text-gray-900">Info</h3>
            <button onclick="closeModal('anInfoModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div id="anInfoBody" class="p-5 text-sm text-gray-600 leading-relaxed"></div>
    </div>
</div>

<!-- Event Detail Modal -->
<div id="anEventModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9998] flex items-center justify-center p-2 sm:p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[94vh]">
        <div class="px-5 md:px-6 py-4 border-b border-gray-100 bg-gray-50/60 flex justify-between items-start gap-4 shrink-0">
            <div id="anEventModalHeader" class="min-w-0"></div>
            <button onclick="closeModal('anEventModal')" class="text-gray-400 hover:text-red-500 bg-white p-1.5 rounded-full shadow-sm shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div id="anEventModalBody" class="p-5 md:p-6 overflow-y-auto custom-scrollbar space-y-5"></div>
    </div>
</div>

<!-- Period Detail Modal -->
<div id="anPeriodModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9997] flex items-center justify-center p-2 sm:p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        <div class="px-5 md:px-6 py-4 border-b border-gray-100 bg-blue-50/60 flex justify-between items-center shrink-0">
            <div id="anPeriodModalHeader"></div>
            <button onclick="closeModal('anPeriodModal')" class="text-blue-400 hover:text-blue-700 bg-white p-1.5 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div id="anPeriodModalBody" class="p-5 md:p-6 overflow-y-auto custom-scrollbar space-y-3"></div>
    </div>
</div>

<!-- Compare Periods Modal -->
<div id="anCompareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9996] flex items-center justify-center p-2 sm:p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[94vh]">
        <div class="px-5 md:px-6 py-4 border-b border-gray-100 bg-gray-900 flex justify-between items-center shrink-0">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-gray-400">Analytics</p>
                <h3 class="text-lg font-bold text-white">Compare Periods — <span id="anCompareCatLabel"></span></h3>
            </div>
            <button onclick="closeModal('anCompareModal')" class="text-gray-400 hover:text-white bg-white/10 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-5 md:p-6 overflow-y-auto custom-scrollbar space-y-5">
            <div class="flex flex-wrap gap-2" id="anComparePresets"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-blue-50/50 border border-blue-100 rounded-2xl p-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-hodBlue mb-2">Period A</p>
                    <div class="flex gap-2">
                        <input type="date" id="anCompA_start" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none">
                        <input type="date" id="anCompA_end" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none">
                    </div>
                </div>
                <div class="bg-red-50/50 border border-red-100 rounded-2xl p-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-hodRed mb-2">Period B</p>
                    <div class="flex gap-2">
                        <input type="date" id="anCompB_start" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none">
                        <input type="date" id="anCompB_end" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none">
                    </div>
                </div>
            </div>
            <button onclick="anRunCompare()" class="w-full bg-hodBlue hover:bg-[#152750] text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all">Run Comparison</button>
            <div id="anCompareResults" class="hidden space-y-5"></div>
        </div>
    </div>
</div>

<!-- Field Modal -->
<div id="fieldModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 id="fieldModalTitle" class="text-xl font-bold text-gray-900">Add Question</h3>
            <button onclick="closeFieldModal()" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="fieldForm" class="p-6 space-y-4">
            <input type="hidden" name="field_id" id="fm_field_id">
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Question *</label>
                <input type="text" name="field_label" id="fm_label" required placeholder="e.g., Which day?" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-400 outline-none font-bold">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Type</label>
                    <select name="field_type" id="fm_type" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-400 outline-none cursor-pointer bg-white">
                        <option value="text">Short Answer</option>
                        <option value="textarea">Paragraph</option>
                        <option value="email">Email</option>
                        <option value="number">Number</option>
                        <option value="date">Date</option>
                        <option value="select">Dropdown</option>
                        <option value="radio">Single Choice</option>
                        <option value="checkbox">Multiple Choice</option>
                    </select>
                </div>
                <label class="flex items-end gap-2 pb-3 cursor-pointer">
                    <input type="checkbox" name="is_required" id="fm_required" value="1" class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm font-bold text-gray-700">Required</span>
                </label>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Placeholder (optional)</label>
                <input type="text" name="placeholder" id="fm_placeholder" placeholder="Hint text inside the field" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-400 outline-none">
            </div>
            <div id="fmOptionsWrap" class="hidden">
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Options (comma separated)</label>
                <input type="text" name="field_options" id="fm_options" placeholder="Day 1, Day 2, Day 3" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-purple-400 outline-none">
            </div>
            <button type="submit" id="fmSubmit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-md transition-all">Save Question</button>
        </form>
    </div>
</div>

<!-- Clocked-in Modal -->
<div id="clockedInModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[10000] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-gray-100 bg-blue-50 flex justify-between items-center shrink-0">
            <h3 class="text-lg font-bold text-blue-900">Detailed Attendance Log</h3>
            <button onclick="closeModal('clockedInModal')" class="text-blue-400 hover:text-blue-700 bg-white p-1.5 rounded-full shadow-sm"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="p-4 border-b border-gray-100 bg-gray-50 shrink-0"><input type="text" id="searchClockedIn" placeholder="Search attendee or admin..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:border-blue-400"></div>
        <div class="flex-1 overflow-y-auto p-0 bg-white">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] sticky top-0 border-b border-gray-100 z-10"><tr><th class="px-6 py-3">Attendee</th><th class="px-6 py-3">Time In</th><th class="px-6 py-3">Logged By</th></tr></thead>
                <tbody id="clockedInTableBody" class="divide-y divide-gray-50"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Monthly Services Modal -->
<div id="monthlyServicesModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-5 border-b border-gray-100 bg-red-50/60 flex justify-between items-start gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-red-600 mb-1">Monthly schedule</p>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900">Create Monthly Services</h3>
                <p class="text-sm text-gray-500 mt-1">Add every Sunday and Thursday service in one click.</p>
            </div>
            <button type="button" onclick="closeMonthlyServicesModal()" class="text-gray-400 hover:text-red-500 transition-colors shrink-0" aria-label="Close">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form id="monthlyServicesForm" class="p-6 space-y-5">
            <div>
                <label for="monthlyServicesMonth" class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Month *</label>
                <input type="month" id="monthlyServicesMonth" name="month" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-hodBlue outline-none font-bold">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="rounded-2xl bg-blue-50 border border-blue-100 p-4">
                    <p class="text-[10px] font-black uppercase tracking-wider text-hodBlue">Sundays</p>
                    <p class="font-bold text-gray-900 mt-1">Total Experience · 9:30 AM</p>
                    <p class="text-xs text-gray-500 mt-1">The last Sunday is marked as Thanksgiving Service.</p>
                </div>
                <div class="rounded-2xl bg-purple-50 border border-purple-100 p-4">
                    <p class="text-[10px] font-black uppercase tracking-wider text-purple-700">Thursdays</p>
                    <p class="font-bold text-gray-900 mt-1">Mercy Experience · 6:30 PM</p>
                    <p class="text-xs text-gray-500 mt-1">All services use the standard Hebron location.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between gap-3">
                    <p class="text-xs font-bold text-gray-700 uppercase tracking-wider">Events to be created</p>
                    <span id="monthlyServicesCount" class="text-xs font-black text-hodBlue bg-blue-100 px-2.5 py-1 rounded-full">0 services</span>
                </div>
                <div id="monthlyServicesPreview" class="max-h-52 overflow-y-auto divide-y divide-gray-100"></div>
            </div>
            <p class="text-xs text-gray-500"><span class="font-bold text-gray-700">Location:</span> Hebron, Kon-X Building, Beside Scapular Plaza, Agungi, Lekki</p>
            <p class="text-xs text-gray-500 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2">Registration links are turned off for these services. If a date already exists, it will be skipped instead of duplicated.</p>

            <div class="flex flex-col-reverse sm:flex-row gap-3 sm:justify-end pt-1">
                <button type="button" onclick="closeMonthlyServicesModal()" class="w-full sm:w-auto px-5 py-3 rounded-xl font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">Cancel</button>
                <button type="submit" id="monthlyServicesSubmit" class="w-full sm:w-auto px-5 py-3 rounded-xl font-bold text-white bg-red-600 hover:bg-red-700 shadow-md transition-all">Create Monthly Services</button>
            </div>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[100000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3"><svg class="animate-spin h-6 w-6 text-hodBlue" viewBox="0 0 24 24" fill="none"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="font-bold text-gray-700 text-sm">Processing...</span></div>
</div>

<style>
    @keyframes fadeInUp{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }
    .animate-fade-in-up{ animation:fadeInUp .5s ease both; }

    /* Analytics tab */
    .an-info-btn{ display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:9999px; background:#EEF2FF; color:#1D356A; font-size:11px; font-weight:900; line-height:1; border:none; cursor:pointer; flex-shrink:0; }
    .an-info-btn:hover{ background:#1D356A; color:#fff; }
    .an-toggle-btn{ padding:.35rem .7rem; font-size:11px; font-weight:800; border-radius:8px; color:#6B7280; cursor:pointer; transition:all .15s; }
    .an-toggle-btn.active{ background:#fff; color:#1D356A; box-shadow:0 1px 2px rgba(0,0,0,.08); }
    .an-sort-ind{ font-size:9px; color:#9CA3AF; }
    .an-kpi-card{ background:#fff; border:1px solid #F3F4F6; border-radius:20px; padding:16px; position:relative; box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .an-kpi-card .an-kpi-label{ font-size:10px; font-weight:800; color:#6B7280; text-transform:uppercase; letter-spacing:.06em; }
    .an-kpi-card .an-kpi-value{ font-size:22px; font-weight:900; color:#111827; margin-top:4px; line-height:1.1; }
    .an-kpi-card .an-kpi-sub{ font-size:11px; color:#9CA3AF; margin-top:4px; font-weight:600; }
    .an-badge{ display:inline-flex; align-items:center; gap:2px; font-size:10px; font-weight:800; padding:2px 7px; border-radius:9999px; }
    .an-badge.up{ background:#DCFCE7; color:#15803D; }
    .an-badge.down{ background:#FEE2E2; color:#B91C1C; }
    .an-badge.flat{ background:#F3F4F6; color:#6B7280; }
    .an-clickable{ cursor:pointer; transition:transform .15s; }
    .an-clickable:hover{ transform:translateY(-1px); }

    /* Quill rich-text editor (Description) */
    #cfgDescriptionEditor{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden; }
    #cfgDescriptionEditor .ql-toolbar.ql-snow{ border:0; border-bottom:1px solid #e5e7eb; border-top-left-radius:14px; border-top-right-radius:14px; background:#f8fafc; }
    #cfgDescriptionEditor .ql-container.ql-snow{ border:0; border-bottom-left-radius:14px; border-bottom-right-radius:14px; font-family:inherit; font-size:.95rem; }
    #cfgDescriptionEditor .ql-editor{ min-height:120px; }
    #cfgDescriptionEditor .ql-editor.ql-blank::before{ color:#9ca3af; font-style:normal; }

    /* Glass / animation styles for the live preview */
    .preview-shell{ position:relative; border-radius:26px; overflow:hidden; background:#070c24; padding:18px; min-height:480px; border:1px solid rgba(255,255,255,.1); }
    .preview-shell .mesh-bg{ position:absolute; inset:-10%; z-index:1; overflow:hidden; }
    .preview-shell .preview-banner-bg{ position:absolute; inset:0; z-index:0; overflow:hidden; }
    .preview-shell .preview-banner-bg .bg-img{ position:absolute; inset:-6%; background-size:cover; background-position:center; filter:blur(30px) brightness(.55) saturate(1.15); transform:scale(1.1); }
    .preview-shell .preview-banner-bg::after{ content:''; position:absolute; inset:0; background:linear-gradient(160deg, rgba(7,12,36,.78), rgba(7,12,36,.55) 45%, rgba(7,12,36,.85)); }
    .preview-shell .mesh-bg::before{ content:''; position:absolute; inset:0; background:radial-gradient(40% 40% at 20% 20%, rgba(30,58,138,.55), transparent 60%), radial-gradient(45% 45% at 80% 30%, rgba(239,68,68,.4), transparent 60%), radial-gradient(50% 50% at 50% 80%, rgba(59,130,246,.45), transparent 60%); filter:blur(40px); animation:meshShift 22s ease-in-out infinite alternate; }
    .preview-shell .blob{ position:absolute; border-radius:50%; filter:blur(70px); opacity:.55; mix-blend-mode:screen; }
    .preview-shell .blob.b1{ width:46%; height:46%; background:radial-gradient(circle,#1e3a8a,transparent 70%); top:-10%; left:-8%; animation:drift1 26s ease-in-out infinite alternate; }
    .preview-shell .blob.b2{ width:42%; height:42%; background:radial-gradient(circle,#ef4444,transparent 70%); top:10%; right:-12%; animation:drift2 30s ease-in-out infinite alternate; }
    .preview-shell .blob.b3{ width:50%; height:50%; background:radial-gradient(circle,#3b82f6,transparent 70%); bottom:-20%; left:20%; animation:drift3 34s ease-in-out infinite alternate; }
    .preview-inner{ position:relative; z-index:2; display:flex; justify-content:center; }
    .preview-shell .glass{ background:linear-gradient(140deg, rgba(255,255,255,.12), rgba(255,255,255,.04)); backdrop-filter:blur(26px) saturate(150%); -webkit-backdrop-filter:blur(26px) saturate(150%); border:1px solid rgba(255,255,255,.18); box-shadow:0 40px 90px -25px rgba(0,0,0,.7), inset 0 1px 0 rgba(255,255,255,.28); border-radius:28px; width:100%; overflow:hidden; }
    .gradient-text{ background:linear-gradient(90deg,#fff,#fca5a5,#bfdbfe,#fff); background-size:220% auto; -webkit-background-clip:text; background-clip:text; color:transparent; animation:shine 5s linear infinite; }
    @keyframes shine{ to{ background-position:220% center; } }
    .preview-shell .banner-hero{ position:relative; width:100%; height:150px; overflow:hidden; background:#0b1020; border-radius:0; border:0; margin:0; }
    .preview-shell .banner-hero img{ width:100%; height:100%; object-fit:cover; object-position:center; display:block; transform:scale(1.03); animation:kenburns 26s ease-in-out infinite alternate; }
    .preview-shell .banner-hero::after{ content:''; position:absolute; inset:0; background:linear-gradient(to bottom, rgba(7,12,36,0) 45%, rgba(7,12,36,.55) 100%); pointer-events:none; }
    @keyframes kenburns{ 0%{transform:scale(1.08) translate(0,0)} 100%{transform:scale(1.18) translate(-2%,-2%)} }
    .preview-shell .min-tile{ display:flex; flex-direction:column; gap:.3rem; }
    .preview-shell .min-tile .thumb{ width:100%; height:44px; border-radius:10px; overflow:hidden; border:1px solid rgba(239,68,68,.35); background:rgba(255,255,255,.06); }
    .preview-shell .min-tile .thumb img{ width:100%; height:100%; object-fit:cover; }
    .preview-shell .min-tile .nm{ font-size:10px; color:rgba(255,255,255,.82); text-align:center; line-height:1.1; font-weight:600; }
    .preview-shell .glass-input{ width:100%; background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.16); border-radius:14px; padding:.8rem 1rem; color:#fff; outline:none; font-size:.9rem; }
    .preview-shell .field-label{ display:block; font-size:.65rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:rgba(238,242,255,.65); margin-bottom:.4rem; }
    .preview-shell .opt-grid{ display:flex; flex-wrap:wrap; gap:.5rem; }
    .preview-shell .opt-pill{ position:relative; display:flex; align-items:center; gap:.5rem; padding:.6rem .9rem; border:2px solid rgba(255,255,255,.18); border-radius:12px; cursor:default; background:rgba(255,255,255,.05); font-size:.82rem; font-weight:600; color:#fff; }
    .preview-shell .opt-pill .check{ width:18px; height:18px; border-radius:7px; border:2px solid rgba(255,255,255,.35); display:flex; align-items:center; justify-content:center; }
    .preview-shell .opt-pill .check.round{ border-radius:50%; }
    .preview-shell .opt-pill .check svg{ width:11px; height:11px; color:#fff; }
    .preview-shell .cta{ position:relative; overflow:hidden; width:100%; background:linear-gradient(120deg, #ef4444, #dc2626 60%, #b91c1c); color:#fff; font-weight:800; padding:1rem; border-radius:16px; text-align:center; box-shadow:0 18px 40px -12px rgba(239,68,68,.7); }
    @keyframes meshShift{ 0%{transform:scale(1) translate(0,0)} 100%{transform:scale(1.15) translate(2%,-2%)} }
    @keyframes drift1{ 0%{transform:translate(0,0)} 100%{transform:translate(12%,8%)} }
    @keyframes drift2{ 0%{transform:translate(0,0)} 100%{transform:translate(-10%,12%)} }
    @keyframes drift3{ 0%{transform:translate(0,0)} 100%{transform:translate(8%,-10%)} }
</style>

<script>
    const API_URL = '/api/events_api.php';
    const EXPORT_URL = '/api/export_event_excel.php';
    let globalEvents = [];
    let currentEditEventId = null;     // null => creating new
    let workingFields = [];            // mirror of custom fields for preview
    let workingMinisters = [];         // [{name, imageUrl, file}] repeatable ministers
    let currentRegistrants = [];
    let descQuill = null;              // Quill instance for the Description editor

    // ---------- Section switching ----------
    function switchSection(id){
        ['events','configure','registrations','attendance','analytics'].forEach(s => {
            $('#section-'+s).addClass('hidden');
            $('#btn-'+s).removeClass('bg-white text-hodBlue shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        });
        $('#section-'+id).removeClass('hidden').addClass('animate-fade-in-up');
        $('#btn-'+id).removeClass('text-gray-500 hover:text-gray-900').addClass('bg-white text-hodBlue shadow-sm');
        if(id === 'registrations') loadRegSelect();
        if(id === 'attendance') loadAttendanceSelect();
        if(id === 'analytics') initAnalytics();
    }

    // ---------- Helpers ----------
    function lockScreen(){ const b=$('#globalActionBlocker'); if(b.length){ b.removeClass('hidden').addClass('flex'); setTimeout(()=>b.removeClass('opacity-0'),10); } }
    function unlockScreen(){ const b=$('#globalActionBlocker'); if(b.length){ b.addClass('opacity-0'); setTimeout(()=>b.removeClass('flex').addClass('hidden'),300); } }
    function showToast(msg, type='success'){ Toastify({ text:msg, gravity:"top", position:"center", duration:3000, style:{ background: type==='success'?"#10B981":"#EF4444", borderRadius:"10px", fontWeight:"bold" } }).showToast(); }
    function openModal(id){ const m=$('#'+id); if(!m) return; m.removeClass('hidden'); requestAnimationFrame(()=>{ m.removeClass('opacity-0'); m.children().first().removeClass('scale-95'); }); }
    function closeModal(id){ const m=$('#'+id); if(!m) return; m.addClass('opacity-0'); m.children().first().addClass('scale-95'); setTimeout(()=>m.addClass('hidden'),300); }
    const esc = s => (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // Strip everything except basic formatting for the live preview
    function sanitizeRich(html){
        const d = document.createElement('div'); d.innerHTML = html || '';
        const allowed = ['B','STRONG','I','EM','U','BR','P','UL','OL','LI'];
        d.querySelectorAll('*').forEach(el => {
            [...el.attributes].forEach(a => el.removeAttribute(a.name));
            if(!allowed.includes(el.tagName)){ el.replaceWith(...el.childNodes); }
        });
        return d.innerHTML;
    }

    // ---------- Events grid ----------
    function loadEvents(){
        $.post(API_URL, { action:'fetch_events' }, function(res){
            if(res.status !== 'success') return;
            globalEvents = res.data;
            let html = '';
            if(res.data.length === 0){
                html = `<div class="col-span-full bg-white rounded-3xl border border-gray-100 p-10 text-center text-gray-400">No events yet. Click <b>New Event</b> to create one.</div>`;
            } else {
                res.data.forEach(e => {
                    const d = new Date(e.event_date);
                    let dStr = d.toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' });
                    const so = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
                    if(e.end_date && e.end_date !== so){ dStr += ' – ' + new Date(e.end_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
                    const closed = e.is_closed == 1;
                    const banner = e.banner_image_url ? `<img src="${e.banner_image_url}" class="w-full h-32 object-cover">` : `<div class="w-full h-32 bg-gradient-to-br from-hodBlue to-blue-400 flex items-center justify-center text-white/80 text-3xl font-black">${(e.title||'?').charAt(0)}</div>`;
                    html += `<div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden group hover:shadow-lg transition-all">
                        <div class="relative">${banner}<span class="absolute top-3 right-3 text-[10px] font-bold uppercase px-2 py-1 rounded ${closed?'bg-red-100 text-red-700':'bg-green-100 text-green-700'}">${closed?'Closed':'Active'}</span></div>
                        <div class="p-5">
                            <p class="text-[10px] font-bold text-hodBlue uppercase tracking-wider">${esc(e.event_category.replace('_',' '))}</p>
                            <h4 class="font-bold text-gray-900 text-lg leading-tight mt-1">${esc(e.title)}</h4>
                            <p class="text-sm text-gray-500 mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>${dStr}</p>
                            <div class="flex gap-3 mt-3 text-xs text-gray-500">
                                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span>${e.total_attendance||0} attended</span>
                                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-purple-500"></span>${e.total_registered||0} registered</span>
                            </div>
                            <button onclick="loadEventIntoConfigure(${e.id})" class="mt-4 w-full ${closed?'bg-gray-100 text-gray-600':'bg-hodBlue text-white'} py-2.5 rounded-xl font-bold text-sm hover:opacity-90 transition">${closed?'View Archive':'Manage'}</button>
                        </div>
                    </div>`;
                });
            }
            $('#eventsGrid').html(html);
            // populate selects
            let regOpts = '<option value="">-- Choose an event --</option>';
            let attOpts = '<option value="">-- Choose an active event --</option>';
            res.data.forEach(e => {
                const d2 = new Date(e.event_date);
                let d2Str = d2.toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' });
                const so2 = `${d2.getFullYear()}-${String(d2.getMonth()+1).padStart(2,'0')}-${String(d2.getDate()).padStart(2,'0')}`;
                if(e.end_date && e.end_date !== so2) d2Str += ' – ' + new Date(e.end_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
                regOpts += `<option value="${e.id}">${esc(e.title)}</option>`;
                if(e.is_closed == 0) attOpts += `<option value="${e.id}">${esc(e.title)} (${d2Str})</option>`;
            });
            $('#regEventSelect').html(regOpts);
            $('#attendanceEventSelect').html(attOpts);
        }, 'json');
    }

    function startNewEvent(){
        currentEditEventId = null;
        workingFields = [];
        $('#cfgAction').val('create_event');
        $('#cfgEventId').val('');
        $('#cfgModeBadge').text('New').removeClass('bg-green-50 text-green-700').addClass('bg-blue-50 text-hodBlue');
        $('#eventForm')[0].reset();
        if(descQuill) descQuill.setText('');
        $('#cfg_description').val('');
        $('#cfg_youtube_url').val('');
        $('#cfg_external_registration_url').val('');
        $('#cfgCustomPeriod').prop('checked', false); $('#cfgEndDateWrap').addClass('hidden'); $('#cfgDateLabel').text('Date & Time *'); $('#cfgCustomPeriodFlag').val('0');
        $('#bannerPreview').addClass('hidden').attr('src','');
        workingMinisters = []; renderMinistersEditor();
        $('#lifecycleBox').addClass('hidden');
        $('#cfgSubmitBtn').text('Create Event');
        renderFieldsList(); renderPreview();
        switchSection('configure');
    }

    function loadEventIntoConfigure(id){
        const e = globalEvents.find(x => x.id == id);
        if(!e) return;
        currentEditEventId = id;
        workingFields = [];
        $('#cfgAction').val('update_event');
        $('#cfgEventId').val(id);
        $('#cfgModeBadge').text('Editing').removeClass('bg-blue-50 text-hodBlue').addClass('bg-green-50 text-green-700');
        $('#cfg_title').val(e.title);
        $('#cfg_category').val(e.event_category);
        $('#cfg_date').val(e.event_date);
        $('#cfg_location').val(e.location || '');
        $('#cfg_youtube_url').val(e.youtube_url || ''); 
        $('#cfg_external_registration_url').val(e.external_registration_url || '');
        $('#cfg_description').val(e.description || '');
        if(descQuill){ descQuill.clipboard.dangerouslyPasteHTML(e.description || ''); }
        workingMinisters = parseMinistersClient(e.ministers, e.ministers_image_url);
        renderMinistersEditor();
        $('#cfg_requires').prop('checked', e.requires_registration == 1);
        $('#cfg_visitors').prop('checked', e.allow_visitors == 1);
        $('#cfgSubmitBtn').text('Save Changes');
        // custom period
        const so = `${new Date(e.event_date).getFullYear()}-${String(new Date(e.event_date).getMonth()+1).padStart(2,'0')}-${String(new Date(e.event_date).getDate()).padStart(2,'0')}`;
        if(e.end_date && e.end_date !== so){ $('#cfgCustomPeriod').prop('checked', true); $('#cfgEndDateWrap').removeClass('hidden'); $('#cfg_end_date').val(e.end_date); $('#cfgDateLabel').text('Start Date & Time *'); $('#cfgCustomPeriodFlag').val('1'); }
        else { $('#cfgCustomPeriod').prop('checked', false); $('#cfgEndDateWrap').addClass('hidden'); $('#cfgDateLabel').text('Date & Time *'); $('#cfgCustomPeriodFlag').val('0'); }
        // images
        if(e.banner_image_url){ $('#bannerPreview').attr('src', e.banner_image_url).removeClass('hidden'); } else $('#bannerPreview').addClass('hidden').attr('src','');
        // lifecycle
        $('#lifecycleBox').removeClass('hidden');
        if(e.requires_registration == 1 && e.registration_token){ $('#regTokenInput').val(window.location.origin + '/register.php?token=' + e.registration_token); $('#registrationLinkBox').removeClass('hidden'); }
        else $('#registrationLinkBox').addClass('hidden');
        $('#btnExportRegistrants').attr('href', `${EXPORT_URL}?event_id=${id}&type=registrants`).removeClass('hidden');
        $('#eventReportNotes').val(e.report_notes || '');
        if(e.is_closed == 1){ $('#cfgClosedBadge').removeClass('hidden'); $('#btnCloseEventAction').addClass('hidden'); $('#btnUnlockEventAction').removeClass('hidden'); }
        else { $('#cfgClosedBadge').addClass('hidden'); $('#btnCloseEventAction').removeClass('hidden'); $('#btnUnlockEventAction').addClass('hidden'); }
        // load custom fields
        $.post(API_URL, { action:'fetch_manage_data', event_id:id }, function(r){
            if(r.status === 'success'){ workingFields = r.fields || []; renderFieldsList(); renderPreview(); }
        }, 'json');
        renderPreview();
        switchSection('configure');
    }

    // ---------- Monthly service generator ----------
    function currentMonthValue(){
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    }

    // Keep the preview in the browser, while the API recalculates the dates before inserting.
    function monthlyServiceSchedule(monthValue){
        const match = /^([0-9]{4})-(0[1-9]|1[0-2])$/.exec(monthValue || '');
        if(!match) return [];
        const year = Number(match[1]);
        const monthIndex = Number(match[2]) - 1;
        const lastDay = new Date(year, monthIndex + 1, 0).getDate();
        const lastSunday = lastDay - new Date(year, monthIndex, lastDay).getDay();
        const schedule = [];

        for(let day = 1; day <= lastDay; day++){
            const date = new Date(year, monthIndex, day);
            const weekday = date.getDay();
            let hour = null;
            let minute = 0;
            let title = '';
            let category = '';
            if(weekday === 0){
                hour = 9; minute = 30; category = 'Sunday_Service';
                title = day === lastSunday ? 'Total Experience - Thanksgiving Service' : 'Total Experience';
            } else if(weekday === 4){
                hour = 18; minute = 30; category = 'Midweek_Service'; title = 'Mercy Experience';
            }
            if(hour === null) continue;
            const datePart = `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const timePart = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00`;
            schedule.push({
                date: datePart,
                eventDate: `${datePart} ${timePart}`,
                title,
                category,
                label: date.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric', year:'numeric' }),
                timeLabel: new Date(year, monthIndex, day, hour, minute).toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' })
            });
        }
        return schedule;
    }

    function renderMonthlyServicesPreview(){
        const schedule = monthlyServiceSchedule($('#monthlyServicesMonth').val());
        $('#monthlyServicesCount').text(`${schedule.length} service${schedule.length === 1 ? '' : 's'}`);
        if(!schedule.length){
            $('#monthlyServicesPreview').html('<p class="p-5 text-sm text-gray-400 text-center">Choose a valid month to see the schedule.</p>');
            return;
        }
        $('#monthlyServicesPreview').html(schedule.map(service => {
            const thanksgiving = service.title.indexOf('Thanksgiving') !== -1;
            return `<div class="px-4 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0"><p class="font-bold text-gray-800 text-sm truncate">${esc(service.title)}</p><p class="text-xs text-gray-500">${esc(service.label)}</p></div>
                <div class="shrink-0 text-right"><p class="text-xs font-black ${thanksgiving ? 'text-red-600' : 'text-hodBlue'}">${esc(service.timeLabel)}</p><p class="text-[10px] text-gray-400 uppercase tracking-wider">${thanksgiving ? 'Thanksgiving' : (service.category === 'Sunday_Service' ? 'Sunday Service' : 'Midweek Service')}</p></div>
            </div>`;
        }).join(''));
    }

    function openMonthlyServicesModal(){
        if(!$('#monthlyServicesMonth').val()) $('#monthlyServicesMonth').val(currentMonthValue());
        renderMonthlyServicesPreview();
        openModal('monthlyServicesModal');
    }

    function closeMonthlyServicesModal(){ closeModal('monthlyServicesModal'); }

    $('#monthlyServicesMonth').on('change input', renderMonthlyServicesPreview);
    $('#monthlyServicesForm').on('submit', function(e){
        e.preventDefault();
        const month = $('#monthlyServicesMonth').val();
        const schedule = monthlyServiceSchedule(month);
        if(!schedule.length){ showToast('Choose a valid month first.', 'error'); return; }

        const btn = $('#monthlyServicesSubmit');
        const original = btn.text();
        btn.prop('disabled', true).html('<span class="spinner"></span> Creating…');
        lockScreen();
        $.post(API_URL, { action:'create_monthly_services', month:month }, function(res){
            btn.prop('disabled', false).text(original);
            unlockScreen();
            if(res.status !== 'success'){
                showToast(res.message || 'Could not create the monthly services.', 'error');
                return;
            }
            closeMonthlyServicesModal();
            showToast(res.message, 'success');
            loadEvents();
        }, 'json').fail(function(){
            btn.prop('disabled', false).text(original);
            unlockScreen();
            showToast('Server Error. The monthly services were not created.', 'error');
        });
    });

    // ---------- Configure form submit (create / update) ----------
    $('#cfgCustomPeriod').on('change', function(){
        const on = $(this).is(':checked');
        $('#cfgCustomPeriodFlag').val(on ? '1' : '0');
        $('#cfgEndDateWrap').toggleClass('hidden', !on);
        $('#cfg_end_date').prop('required', on);
        $('#cfgDateLabel').text(on ? 'Start Date & Time *' : 'Date & Time *');
        renderPreview();
    });
    $('#eventForm').on('submit', function(e){
        e.preventDefault();
        const btn = $('#cfgSubmitBtn'); const orig = btn.text();
        syncMinistersHidden();
        const fd = new FormData(this);
        workingMinisters.forEach(m => { if(m.file) fd.append('minister_image[]', m.file, m.file.name); });
        btn.prop('disabled', true).html('<span class="spinner"></span> Saving…'); lockScreen();
        $.ajax({ url:API_URL, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
            success:function(res){
                btn.prop('disabled', false).html(orig); unlockScreen();
                showToast(res.message, res.status);
                if(res.status === 'success'){
                    // Immediately show the registration link if a token is returned
                    if (res.token) {
                        $('#regTokenInput').val(window.location.origin + '/register.php?token=' + res.token);
                        $('#registrationLinkBox').removeClass('hidden');
                    } else {
                        $('#registrationLinkBox').addClass('hidden');
                    }

                    if(!currentEditEventId && res.event_id){
                        // persist in-memory draft fields
                        currentEditEventId = res.event_id;
                        $('#cfgEventId').val(res.event_id); $('#cfgAction').val('update_event');
                        $('#cfgModeBadge').text('Editing').removeClass('bg-blue-50 text-hodBlue').addClass('bg-green-50 text-green-700');
                        $('#lifecycleBox').removeClass('hidden');
                        const token = res.token;
                        if(token){ $('#regTokenInput').val(window.location.origin + '/register.php?token=' + token); $('#registrationLinkBox').removeClass('hidden'); $('#btnExportRegistrants').attr('href', `${EXPORT_URL}?event_id=${res.event_id}&type=registrants`).removeClass('hidden'); }
                        if(workingFields.length){
                            let i = 0;
                            const pushNext = () => {
                                if(i >= workingFields.length){ loadEvents(); return; }
                                const f = workingFields[i++];
                                const d = { action:'add_custom_field', event_id:res.event_id, field_label:f.field_label, field_type:f.field_type, is_required:f.is_required, placeholder:f.placeholder||'' };
                                if(['select','radio','checkbox'].includes(f.field_type) && f.field_options) d.field_options = f.field_options.join(',');
                                $.post(API_URL, d, pushNext, 'json');
                            };
                            pushNext();
                        } else loadEvents();
                    } else loadEvents();
                }
            },
            error:function(){ btn.prop('disabled', false).html(orig); unlockScreen(); showToast('Server Error','error'); }
        });
    });

    // live preview triggers
    ['#cfg_title','#cfg_category','#cfg_date','#cfg_end_date','#cfg_location','#cfg_description'].forEach(s => $(s).on('input change', renderPreview));

    // image previews
    $('#cfg_banner').on('change', function(){ const f=this.files[0]; if(f){ const r=new FileReader(); r.onload=e=>{ $('#bannerPreview').attr('src',e.target.result).removeClass('hidden'); renderPreview(); }; r.readAsDataURL(f); } });

    // ---------- Live Preview render ----------
    function renderPreview(){
        const title = $('#cfg_title').val() || 'Your Event Title';
        const cat = ($('#cfg_category').val()||'Event').replace('_',' ');
        $('#pvTitle').text(title);
        $('#pvCategory').text(cat);
        const dateVal = $('#cfg_date').val();
        if(dateVal){ const d=new Date(dateVal); $('#pvDate span').text(d.toLocaleString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})); }
        else $('#pvDate span').text('Select a date…');
        const loc = $('#cfg_location').val();
        if(loc){ $('#pvLocation').removeClass('hidden').find('span').text(loc); } else $('#pvLocation').addClass('hidden');
        const desc = $('#cfg_description').val() || '';
        if(desc.replace(/<[^>]*>/g,'').trim() !== ''){ $('#pvDescription').removeClass('hidden').html(sanitizeRich(desc)); } else $('#pvDescription').addClass('hidden');
        // banner (two-layer: blurred bg + crisp top hero)
        const bp = $('#bannerPreview').attr('src');
        if(bp){
            $('#pvBanner').removeClass('hidden').find('img').attr('src', bp);
            $('#pvBannerBg').html('<div class="bg-img" style="background-image:url(\'' + bp + '\')"></div>').removeClass('hidden');
        } else {
            $('#pvBanner').addClass('hidden');
            $('#pvBannerBg').addClass('hidden').html('');
        }
        // ministers (repeatable)
        if(workingMinisters.length){
            $('#pvMinisters').removeClass('hidden');
            let mh = '';
            workingMinisters.forEach(m => {
                const thumb = m.imageUrl ? `<div class="thumb"><img src="${esc(m.imageUrl)}"></div>` : `<div class="thumb" style="display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:18px;">+</div>`;
                mh += `<div class="min-tile">${thumb}<div class="nm">${esc(m.name||'')}</div></div>`;
            });
            $('#pvMinistersList').html(mh);
        } else $('#pvMinisters').addClass('hidden');
        // days
        const so = dateVal ? dateVal.slice(0,10) : '';
        const eo = $('#cfgCustomPeriod').is(':checked') ? $('#cfg_end_date').val() : '';
        if(eo && eo > so){
            let days=''; const s=new Date(so+'T00:00:00'), en=new Date(eo+'T00:00:00');
            for(let c=s.getTime(); c<=en.getTime(); c= new Date(c).setDate(new Date(c).getDate()+1)){
                const dd=new Date(c); const lab=dd.toLocaleString('en-US',{weekday:'short',month:'short',day:'numeric'});
                days += `<span class="opt-pill"><span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg></span><span class="txt">${lab}</span></span>`;
                c = dd.getTime();
            }
            $('#pvDays').removeClass('hidden').find('#pvDaysList').html(days);
        } else $('#pvDays').addClass('hidden');
        // custom fields
        $('#pvFields').html(workingFields.map(previewFieldHtml).join(''));
    }

    // ---------- Ministers (repeatable: photo + name) ----------
    function renderMinistersEditor(){
        const wrap = $('#ministersList');
        if(!wrap.length) return;
        if(workingMinisters.length === 0){
            wrap.html('<p class="text-xs text-gray-400 italic">No ministers added yet. Click “+ Add Minister”.</p>');
            return;
        }
        let html = '';
        workingMinisters.forEach((m, i) => {
            const thumb = m.imageUrl
                ? `<img src="${esc(m.imageUrl)}" class="w-14 h-14 rounded-xl object-cover border-2 border-red-200">`
                : `<div class="w-14 h-14 rounded-xl bg-red-50 flex items-center justify-center text-red-300 text-2xl font-black cursor-pointer">+</div>`;
            html += `<div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl p-3">
                <label class="cursor-pointer shrink-0 relative" title="Click to upload photo">
                    ${thumb}
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="min-img-input hidden" data-index="${i}">
                </label>
                <input type="text" placeholder="Minister / Speaker name" value="${esc(m.name)}" class="min-name-input flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-hodBlue outline-none" data-index="${i}">
                <button type="button" onclick="removeMinister(${i})" class="text-red-500 hover:bg-red-50 rounded-lg p-2 transition-colors" title="Remove">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
            </div>`;
        });
        wrap.html(html);
        wrap.find('.min-name-input').on('input', function(){
            const i = $(this).data('index');
            workingMinisters[i].name = $(this).val();
            renderPreview();
        });
        wrap.find('.min-img-input').on('change', function(){
            const i = $(this).data('index');
            const f = this.files[0];
            if(!f) return;
            const r = new FileReader();
            r.onload = e => {
                workingMinisters[i].imageUrl = e.target.result;
                workingMinisters[i].file = f;
                renderMinistersEditor();
                renderPreview();
            };
            r.readAsDataURL(f);
        });
    }
    function addMinister(){
        workingMinisters.push({ name:'', imageUrl:'', file:null });
        renderMinistersEditor();
        renderPreview();
    }
    function removeMinister(i){
        workingMinisters.splice(i, 1);
        renderMinistersEditor();
        renderPreview();
    }
    function syncMinistersHidden(){
        $('#cfg_ministers_json').val(JSON.stringify(workingMinisters.map(m => ({
            name: m.name,
            image: m.file ? '' : (m.imageUrl || '')
        }))));
    }
    function parseMinistersClient(raw, legacyImg){
        if(!raw) return [];
        try {
            const arr = JSON.parse(raw);
            if(Array.isArray(arr)){
                return arr.map(m => ({ name:(m && m.name)||'', imageUrl:(m && m.image)||'', file:null })).filter(m => m.name || m.imageUrl);
            }
        } catch(e){}
        const text = (raw||'').toString().replace(/<[^>]*>/g,'').trim();
        if(!text) return [];
        return [{ name:text, imageUrl: legacyImg||'', file:null }];
    }

    function previewFieldHtml(f){
        const req = f.is_required == 1 ? '<span class="text-hodRed">*</span>' : '';
        let inner = '';
        if(f.field_type === 'textarea') inner = `<div class="glass-input" style="min-height:48px"></div>`;
        else if(f.field_type === 'select'){ let o=(f.field_options||[]).map(x=>`<div class="glass-input">${esc(x)} ▾</div>`).join(''); inner=o; }
        else if(f.field_type === 'radio' || f.field_type === 'checkbox'){ let p=(f.field_options||[]).map(x=>`<span class="opt-pill"><span class="check ${f.field_type==='radio'?'round':''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg></span><span class="txt">${esc(x)}</span></span>`).join(''); inner=`<div class="opt-grid">${p}</div>`; }
        else inner = `<div class="glass-input">${esc(f.placeholder||'')}</div>`;
        return `<div><label class="field-label">${esc(f.field_label)} ${req}</label>${inner}</div>`;
    }

    // ---------- Field builder ----------
    function renderFieldsList(){
        if(workingFields.length === 0){ $('#fieldsList').html('<p class="text-sm text-gray-400 italic">No custom fields yet. Add one above.</p>'); return; }
        let html = '';
        workingFields.forEach((f, i) => {
            html += `<div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">
                <div class="flex flex-col gap-1">
                    <button onclick="moveField(${i},-1)" ${i===0?'disabled':''} class="text-gray-400 hover:text-hodBlue disabled:opacity-30 text-xs">↑</button>
                    <button onclick="moveField(${i},1)" ${i===workingFields.length-1?'disabled':''} class="text-gray-400 hover:text-hodBlue disabled:opacity-30 text-xs">↓</button>
                </div>
                <div class="flex-1">
                    <p class="font-bold text-gray-800 text-sm">${esc(f.field_label)} ${f.is_required==1?'<span class="text-red-500">*</span>':''}</p>
                    <p class="text-[11px] text-purple-500 uppercase font-bold">${f.field_type}</p>
                </div>
                <button onclick="openFieldModal(${i})" class="text-xs font-bold text-hodBlue hover:underline">Edit</button>
                <button onclick="deleteField(${i})" class="text-xs font-bold text-red-500 hover:underline">Delete</button>
            </div>`;
        });
        $('#fieldsList').html(html);
    }

    function openFieldModal(index){
        $('#fmOptionsWrap').addClass('hidden');
        if(index === undefined || index === null){
            $('#fieldModalTitle').text('Add Question');
            $('#fm_field_id').val(''); $('#fm_label').val(''); $('#fm_type').val('text'); $('#fm_required').prop('checked',false); $('#fm_placeholder').val(''); $('#fm_options').val('');
        } else {
            const f = workingFields[index];
            $('#fieldModalTitle').text('Edit Question');
            $('#fm_field_id').val(index); $('#fm_label').val(f.field_label); $('#fm_type').val(f.field_type); $('#fm_required').prop('checked', f.is_required==1); $('#fm_placeholder').val(f.placeholder||'');
            $('#fm_options').val((f.field_options||[]).join(', '));
            if(['select','radio','checkbox'].includes(f.field_type)) $('#fmOptionsWrap').removeClass('hidden');
        }
        openModal('fieldModal');
    }
    function closeFieldModal(){ closeModal('fieldModal'); }

    $('#fm_type').on('change', function(){ $('#fmOptionsWrap').toggleClass('hidden', !['select','radio','checkbox'].includes($(this).val())); });

    $('#fieldForm').on('submit', function(e){
        e.preventDefault();
        const idx = $('#fm_field_id').val();
        const label = $('#fm_label').val().trim();
        const type = $('#fm_type').val();
        const required = $('#fm_required').is(':checked') ? 1 : 0;
        const placeholder = $('#fm_placeholder').val().trim();
        const options = $('#fm_options').val().trim();
        if(!label){ showToast('Question is required','error'); return; }
        if(['select','radio','checkbox'].includes(type) && !options){ showToast('Add at least one option','error'); return; }
        const field = { field_label:label, field_type:type, is_required:required, placeholder:placeholder, field_options: options ? options.split(',').map(s=>s.trim()).filter(Boolean) : null };
        const isEdit = idx !== '';
        if(isEdit){
            const i = parseInt(idx);
            const existing = workingFields[i];
            workingFields[i] = Object.assign({}, existing, field);
            if(currentEditEventId){ persistField('update', workingFields[i].id, field); }
        } else {
            workingFields.push(field);
            if(currentEditEventId){ persistField('add', null, field); }
        }
        renderFieldsList(); renderPreview(); closeFieldModal();
    });

    function persistField(mode, fieldId, field){
        if(mode === 'add'){
            const d = { action:'add_custom_field', event_id:currentEditEventId, field_label:field.field_label, field_type:field.field_type, is_required:field.is_required, placeholder:field.placeholder||'' };
            if(field.field_options) d.field_options = field.field_options.join(',');
            $.post(API_URL, d, function(r){ showToast(r.message, r.status); if(r.status==='success'){ /* refresh workingFields ids */ $.post(API_URL,{action:'fetch_manage_data',event_id:currentEditEventId},function(rr){ if(rr.status==='success'){ workingFields=rr.fields||[]; renderFieldsList(); renderPreview(); } },'json'); } }, 'json');
        } else if(mode === 'update'){
            const d = { action:'update_custom_field', field_id:fieldId, field_label:field.field_label, field_type:field.field_type, is_required:field.is_required, placeholder:field.placeholder||'' };
            if(field.field_options) d.field_options = field.field_options.join(',');
            $.post(API_URL, d, function(r){ showToast(r.message, r.status); }, 'json');
        }
    }

    function moveField(i, dir){
        const j = i + dir;
        if(j < 0 || j >= workingFields.length) return;
        const tmp = workingFields[i]; workingFields[i] = workingFields[j]; workingFields[j] = tmp;
        if(currentEditEventId){
            const ordered = workingFields.map(f => f.id).join(',');
            $.post(API_URL, { action:'reorder_custom_fields', event_id:currentEditEventId, ordered_ids:ordered }, function(){}, 'json');
        }
        renderFieldsList(); renderPreview();
    }

    function deleteField(i){
        const f = workingFields[i];
        if(f.id && currentEditEventId){ $.post(API_URL, { action:'delete_custom_field', field_id:f.id }, function(r){ showToast(r.message, r.status); }, 'json'); }
        workingFields.splice(i, 1);
        renderFieldsList(); renderPreview();
    }

    // ---------- Registration link ----------
    function copyRegLink(){ const l=$('#regTokenInput')[0]; l.select(); document.execCommand('copy'); showToast('Link Copied!','success'); }
    function shareRegLink(){ const link=$('#regTokenInput').val(); const e=globalEvents.find(x=>x.id==currentEditEventId); const title=e?e.title:'Event'; if(navigator.share){ navigator.share({title,text:`You're invited: ${title}`,url:link}).catch(()=>{}); } else copyRegLink(); }
    
    function shareWhatsApp(){
        const link = $('#regTokenInput').val();
        if(!link) return;
        
        const e = globalEvents.find(x => x.id == currentEditEventId);
        const title = e ? e.title : 'Event';
        
        // Contextually structured message payload
        const textPayload = `You're invited to join us for "${title}".\n\nClick the link below to review details and secure your registration:\n${link}`;
        const encodedText = encodeURIComponent(textPayload);
        
        // Dispatches natively to API universal link handler (handles app fallback vs web browser automatically)
        window.open(`https://api.whatsapp.com/send?text=${encodedText}`, '_blank', 'noopener,noreferrer');
    }

    // ---------- Report / Close / Unlock ----------
    function saveReport(){ const id=currentEditEventId; if(!id) return; lockScreen(); $.post(API_URL, { action:'save_report', event_id:id, report_notes:$('#eventReportNotes').val() }, function(r){ unlockScreen(); showToast(r.message, r.status); },'json').fail(()=>{ unlockScreen(); showToast('Server Error','error'); }); }
    function closeEventAction(){ if(!confirm('Close this event? Attendance & registration will be locked.')) return; lockScreen(); $.post(API_URL,{action:'close_event',event_id:currentEditEventId},function(r){ unlockScreen(); showToast(r.message,r.status); if(r.status==='success'){ loadEventIntoConfigure(currentEditEventId); loadEvents(); } },'json').fail(()=>{ unlockScreen(); showToast('Server Error','error'); }); }
    function unlockEventAction(){ if(!confirm('SUPER ADMIN: Unlock this event?')) return; lockScreen(); $.post(API_URL,{action:'unlock_event',event_id:currentEditEventId},function(r){ unlockScreen(); showToast(r.message,r.status); if(r.status==='success'){ loadEventIntoConfigure(currentEditEventId); loadEvents(); } },'json').fail(()=>{ unlockScreen(); showToast('Server Error','error'); }); }

    // ---------- Registrations ----------
    let currentRegPage = 1;
    let currentRegSearch = '';
    let regSearchTimeout;
    let currentRegEventId = null;

    function loadRegSelect(){ /* already populated in loadEvents */ }
    
    $('#regEventSelect').on('change', function(){
        currentRegEventId = $(this).val(); 
        if(!currentRegEventId){ 
            $('#regKpiContainer, #regTableContainer').addClass('hidden'); 
            $('#btnExportRegistrants').addClass('hidden');
            return; 
        }
        $('#regKpiContainer, #regTableContainer').removeClass('hidden');
        $('#btnExportRegistrants').attr('href', `${EXPORT_URL}?event_id=${currentRegEventId}&type=registrants`).removeClass('hidden');
        $('#regSearch').val('');
        currentRegSearch = '';
        fetchRegistrations(1);
    });

    $('#regSearch').on('keyup', function() {
        clearTimeout(regSearchTimeout);
        currentRegSearch = $(this).val();
        regSearchTimeout = setTimeout(() => fetchRegistrations(1), 400); 
    });

    function fetchRegistrations(page = 1) {
        if(!currentRegEventId) return;
        currentRegPage = page;
        $('#registrationsList').html('<tr><td colspan="6" class="px-6 py-12 text-center text-gray-400 font-medium"><svg class="animate-spin h-6 w-6 text-gray-400 mx-auto mb-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Fetching records...</td></tr>');

        $.post(API_URL, { 
            action: 'fetch_registrations', 
            event_id: currentRegEventId, 
            page: currentRegPage, 
            search: currentRegSearch 
        }, function(res) {
            if(res.status === 'success') {
                // 1. Update KPIs
                $('#kpiTotal').text(res.kpis.total.toLocaleString());
                $('#kpiToday').text(res.kpis.today.toLocaleString());
                $('#kpiYesterday').text(res.kpis.yesterday.toLocaleString());

                const g = res.kpis.growth;
                let trendHtml = '';
                if (g > 0) {
                    trendHtml = `<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg> +${g}%`;
                    $('#kpiTrendBadge').removeClass('bg-gray-100 text-gray-500 bg-red-100 text-red-600').addClass('bg-green-100 text-green-700');
                } else if (g < 0) {
                    trendHtml = `<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg> ${g}%`;
                    $('#kpiTrendBadge').removeClass('bg-gray-100 text-gray-500 bg-green-100 text-green-700').addClass('bg-red-100 text-red-600');
                } else {
                    trendHtml = `Same as yesterday`;
                    $('#kpiTrendBadge').removeClass('bg-green-100 text-green-700 bg-red-100 text-red-600').addClass('bg-gray-100 text-gray-500');
                }
                $('#kpiTrendBadge').html(trendHtml);

                // 2. Render Table
                let html = '';
                if(res.data.length === 0) {
                    html = '<tr><td colspan="6" class="px-6 py-12 text-center text-gray-400 font-medium">No registrations found matching your search.</td></tr>';
                } else {
                    res.data.forEach((reg, index) => {
                        const serialNumber = res.pagination.offset + index + 1;
                        
                        // Extract specific Data (Merging new structure with your old data formats)
                        const name = reg.user_id ? `${reg.first_name} ${reg.last_name}` : (reg.guest_name || reg.full_name || 'N/A');
                        const email = reg.email || 'N/A';
                        const phone = reg.user_id ? (reg.member_phone||'-') : (reg.guest_phone || reg.phone_number || '-');
                        
                        let attending='-'; 
                        if(reg.attendance_days && reg.attendance_days.length) {
                            try {
                                let days = typeof reg.attendance_days === 'string' ? JSON.parse(reg.attendance_days) : reg.attendance_days;
                                attending = days.map(d=>new Date(d+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'})).join(', ');
                            } catch(e){ attending = reg.attendance_days; }
                        }
                        
                        // Match Logic
                        let match = '<span class="text-gray-300">-</span>';
                        if(!reg.user_id && reg.match_status==='pending' && reg.matched_first_name){ match = `<div class="min-w-[200px]"><p class="text-[11px] font-bold text-amber-700">${parseFloat(reg.match_confidence).toFixed(0)}% - ${esc(reg.matched_first_name)} ${esc(reg.matched_last_name)}</p><div class="flex gap-1.5 mt-1"><button onclick="confirmMatch(${reg.id},'confirm')" class="bg-amber-600 text-white text-[10px] font-bold px-2 py-1 rounded">Confirm</button><button onclick="confirmMatch(${reg.id},'reject')" class="bg-white border border-amber-300 text-amber-700 text-[10px] font-bold px-2 py-1 rounded">Not a Match</button></div></div>`; }
                        else if(reg.match_status==='confirmed') match = '<span class="text-[11px] text-green-600 font-bold">✓ Linked</span>';
                        else if(reg.match_status==='rejected') match = '<span class="text-[11px] text-gray-400">Dismissed</span>';

                        const regAt = (reg.nice_date || reg.registered_at) ? (reg.nice_date || new Date(reg.registered_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})) : '-';
                        
                        html += `
                        <tr class="hover:bg-blue-50/30 transition-colors border-b border-gray-50">
                            <td class="px-6 py-4 text-center font-bold text-gray-400">${serialNumber}</td>
                            <td class="px-6 py-4 font-bold text-gray-900">${esc(name)}</td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-gray-600">${esc(email)}</p>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">${esc(phone)}</p>
                            </td>
                            <td class="px-6 py-4 text-gray-600 text-sm">${esc(attending)}</td>
                            <td class="px-6 py-4">${match}</td>
                            <td class="px-6 py-4 text-right text-xs font-bold text-gray-500">${regAt}</td>
                        </tr>`;
                    });
                }
                $('#registrationsList').html(html);

                // 3. Render Pagination
                const p = res.pagination;
                const startRecord = p.total_records === 0 ? 0 : p.offset + 1;
                const endRecord = Math.min(p.offset + res.data.length, p.total_records);
                
                $('#pageInfo').text(`Showing ${startRecord} to ${endRecord} of ${p.total_records.toLocaleString()}`);

                let pageHtml = '';
                if(p.current_page > 1) {
                    pageHtml += `<button onclick="fetchRegistrations(${p.current_page - 1})" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600 hover:bg-gray-50 hover:text-hodBlue transition-colors shadow-sm">Previous</button>`;
                }
                if(p.current_page < p.total_pages) {
                    pageHtml += `<button onclick="fetchRegistrations(${p.current_page + 1})" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600 hover:bg-gray-50 hover:text-hodBlue transition-colors shadow-sm">Next 50</button>`;
                }
                $('#paginationControls').html(pageHtml);
            } else {
                showToast(res.message, 'error');
            }
        }, 'json').fail(()=>{ showToast('Server Error fetching registrations', 'error'); });
    }

    function confirmMatch(id, decision){ 
        lockScreen(); 
        $.post(API_URL,{action:'confirm_match',registration_id:id,decision:decision},function(r){ 
            unlockScreen(); 
            showToast(r.message,r.status); 
            if(r.status==='success') fetchRegistrations(currentRegPage); // Reload current page
        },'json').fail(()=>{ unlockScreen(); showToast('Server Error','error'); }); 
    }

    // ---------- Attendance ----------
    function loadAttendanceSelect(){ /* populated in loadEvents */ }
        $('#attendanceEventSelect').on('change', function(){
        const id = $(this).val();
        if(!id){
            $('#rosterContainer').addClass('hidden');
            $('#attKpiContainer').addClass('hidden');
            $('#rosterSearch').prop('disabled',true).val('');
            return;
        }
        $('#rosterSearch').prop('disabled',false);
        $('#rosterContainer').removeClass('hidden');
        $('#attKpiContainer').removeClass('hidden');
        loadRoster(id);
        loadAttKPIs(id);
    });
    function loadRoster(id){
        $('#pendingList').html('<div class="text-center p-4 text-gray-400">Loading…</div>');
        $.post(API_URL, { action:'fetch_attendance_roster', event_id:id }, function(res){
            if(res.status !== 'success') return;
            $('#pendingCount').text(res.pending.length); $('#checkedInCount').text(res.checked_in.length);
            let p = ''; res.pending.forEach(u => { p += `<div class="roster-card flex justify-between items-center bg-white p-4 rounded-2xl shadow-sm border border-gray-100" data-name="${(u.first_name+' '+u.last_name).toLowerCase()}"><div><p class="font-bold text-gray-900">${esc(u.first_name)} ${esc(u.last_name)}</p><p class="text-[10px] text-gray-500">${esc(u.spiritual_status)}</p></div><button onclick="clockIn(event,${id},${u.id})" class="bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2 rounded-xl text-xs font-bold transition-colors">Clock In</button></div>`; });
            $('#pendingList').html(p || '<p class="text-center text-gray-400 py-4">All cleared!</p>');
            let c = ''; res.checked_in.forEach(u => { const t=new Date(u.check_in_time).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); c += `<div class="roster-card flex justify-between items-center bg-white p-4 rounded-2xl shadow-sm border border-gray-100" data-name="${(u.first_name+' '+u.last_name).toLowerCase()}"><div><p class="font-bold text-gray-900">${esc(u.first_name)} ${esc(u.last_name)}</p><p class="text-[10px] text-gray-500">${esc(u.spiritual_status)}</p></div><div class="flex items-center gap-2"><span class="text-xs font-bold text-green-600 bg-green-50 px-3 py-1.5 rounded-lg">${t}</span><button onclick="clockOut(event,${id},${u.id})" title="Undo clock-in" class="bg-gray-50 text-gray-500 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg text-xs font-bold transition-colors">Clock Out</button></div></div>`; });
            $('#checkedInList').html(c || '<p class="text-center text-gray-400 py-4">Waiting…</p>');
        }, 'json');
    }
    
        function loadAttKPIs(id){
        $.post(API_URL, { action:'attendance_kpis', event_id:id }, function(res){
            if(res.status!=='success' || !res.kpis) return;
            const k = res.kpis;
            $('#attKpiTotal').text(k.total.toLocaleString());
            $('#attKpiToday').text(k.today.toLocaleString());
            $('#attKpiMembers').text(k.members);
            $('#attKpiWalkins').text(k.walkins);
            $('#attKpiTotalSub').text(k.registered.toLocaleString()+' registered total');

            // trend badge
            const g = k.growth;
            let trend = '<span>--</span>';
            let cls = 'bg-gray-100 text-gray-500';
            if(g>0){ trend = '▲ +'+g+'%'; cls = 'bg-green-100 text-green-700'; }
            else if(g<0){ trend = '▼ '+g+'%'; cls = 'bg-red-100 text-red-600'; }
            else { trend = 'Same as yesterday'; cls = 'bg-gray-100 text-gray-500'; }
            $('#attKpiTrendBadge').attr('class','flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full w-fit mt-2 '+cls).html(trend);
        },'json');
    }
    function clockIn(ev, id, uid){
        const btn = ev ? ev.currentTarget : null;
        if(btn){ $(btn).replaceWith(`<span class="px-4 py-2 rounded-xl text-xs font-bold text-gray-400 border border-gray-100">Logging…</span>`); }
        $.post(API_URL,{action:'mark_attendance',event_id:id,user_id:uid},function(res){
            if(res.status==='error') showToast(res.message,'error');
            loadRoster(id);
        },'json').fail(()=>{ showToast('Server Error','error'); loadRoster(id); });
    }

    function clockOut(ev, id, uid){
        if(!confirm('Clock this person out? They will move back to Pending Clock-In.')) return;
        const btn = ev ? ev.currentTarget : null;
        if(btn){ $(btn).prop('disabled', true).text('…'); }
        $.post(API_URL,{action:'clock_out',event_id:id,user_id:uid},function(res){
            if(res.status==='error') showToast(res.message,'error');
            loadRoster(id);
        },'json').fail(()=>{ showToast('Server Error','error'); loadRoster(id); });
    }

    $('#rosterSearch').on('keyup', function(){ const v=$(this).val().toLowerCase(); $('.roster-card').each(function(){ $(this).toggle($(this).attr('data-name').indexOf(v)>-1); }); });
    $('#searchClockedIn').on('keyup', function(){ const v=$(this).val().toLowerCase(); $('#clockedInTableBody tr').each(function(){ const t=$(this).text().toLowerCase(); $(this).toggle(t.indexOf(v)>-1); }); });

    // ---------- Search ----------
    $('#searchEvents').on('keyup', function(){ const v=$(this).val().toLowerCase(); $('#eventsGrid > div').each(function(){ const card=$(this); if(card.find('h4').length) card.toggle(card.text().toLowerCase().indexOf(v)>-1); }); });

    // ================================================================================
    // ANALYTICS TAB — Sunday Service & Midweek Service attendance analytics
    // Data source: the `attendance` roster (+ `checkins` as a defensive union) via
    // /api/event_analytics_api.php. NEVER touches event_registrations.
    // ================================================================================
    const ANALYTICS_API = '/api/event_analytics_api.php';
    let anInitialized = false;
    let anCategory = 'Midweek_Service';
    let anPeriodState = {
        Midweek_Service: { preset: '12m', start: null, end: null },
        Sunday_Service:  { preset: '12m', start: null, end: null }
    };
    let anCurrentOverview = null;
    let anGenderView = 'totals', anStatusView = 'totals';
    let anSort_ = { col: 'event_date', dir: 'desc' };
    let anPage = 1, anPerPage = 15;
    let anChartTrend = null, anChartGender = null, anChartStatus = null, anChartRegion = null;
    let anEvtCharts = { spark: null, status: null, region: null, pace: null };
    let anEventRoster = [];

    function anParseDate(d){ if(!d) return new Date(); return new Date(String(d).replace(' ','T')); }
    function anFmtDateShort(d){ return anParseDate(d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
    function anFmtDateFull(d){ return anParseDate(d).toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'}); }
    function anFmtNum(n){ return (n===null||n===undefined?0:n).toLocaleString(); }
    function anShortTitle(t){ return t && t.length>30 ? t.slice(0,28)+'…' : (t||''); }
    function anPctChange(from,to){ if(!from) return null; return Math.round((to-from)/from*1000)/10; }
    function anBadgeHtml(pct){
        if(pct===null||pct===undefined) return '<span class="an-badge flat">—</span>';
        if(pct>0) return `<span class="an-badge up">▲ +${pct}%</span>`;
        if(pct<0) return `<span class="an-badge down">▼ ${pct}%</span>`;
        return '<span class="an-badge flat">No change</span>';
    }
    function anVsPreviousHtml(e){ return anBadgeHtml(e.vs_previous===undefined?null:e.vs_previous); }

    function anInfo(title, body){ $('#anInfoTitle').text(title); $('#anInfoBody').text(body); openModal('anInfoModal'); }

    // ---------- Init / tab switching ----------
    function initAnalytics(){
        if(anInitialized) return;
        anInitialized = true;
        $('#anGranularity').on('change', function(){ $(this).data('userset', true); anLoadOverview(); });
        anSwitchCategory('Midweek_Service');
    }

    function anSwitchCategory(cat){
        anCategory = cat;
        $('.an-cat-btn').removeClass('bg-white text-hodBlue shadow-sm').addClass('text-gray-500 hover:text-gray-900');
        $('#anCatBtn-'+cat).addClass('bg-white text-hodBlue shadow-sm').removeClass('text-gray-500 hover:text-gray-900');
        $('#anPeriodPreset').val(anPeriodState[cat].preset);
        if(anPeriodState[cat].start) $('#anCustomStart').val(anPeriodState[cat].start);
        if(anPeriodState[cat].end) $('#anCustomEnd').val(anPeriodState[cat].end);
        anOnPresetChange(false);
        anLoadOverview();
    }

    function anOnPresetChange(reload){
        const v = $('#anPeriodPreset').val();
        anPeriodState[anCategory].preset = v;
        if(v === 'custom'){
            $('#anCustomRangeWrap').removeClass('hidden');
            if(!$('#anCustomStart').val()){
                const e = new Date(), s = new Date(); s.setMonth(s.getMonth()-12);
                $('#anCustomStart').val(s.toISOString().slice(0,10));
                $('#anCustomEnd').val(e.toISOString().slice(0,10));
            }
            return; // wait for the explicit "Go" click
        }
        $('#anCustomRangeWrap').addClass('hidden');
        if(reload !== false) anLoadOverview();
    }

    function anApplyCustomRange(){
        const s = $('#anCustomStart').val(), e = $('#anCustomEnd').val();
        if(!s || !e){ showToast('Pick both a start and end date.','error'); return; }
        anPeriodState[anCategory].start = s; anPeriodState[anCategory].end = e;
        anLoadOverview();
    }

    function anComputeRange(){
        const st = anPeriodState[anCategory];
        const today = new Date();
        const fmt = d => d.toISOString().slice(0,10);
        let start = null, end = fmt(today), limit = 0;
        switch(st.preset){
            case '8': limit = 8; break;
            case '3m': { const s=new Date(); s.setMonth(s.getMonth()-3); start=fmt(s); break; }
            case '6m': { const s=new Date(); s.setMonth(s.getMonth()-6); start=fmt(s); break; }
            case 'ytd': start = today.getFullYear()+'-01-01'; break;
            case 'all': start = '2000-01-01'; break;
            case 'custom': start = st.start; end = st.end; break;
            case '12m':
            default: { const s=new Date(); s.setMonth(s.getMonth()-12); start=fmt(s); }
        }
        if(!start && !limit){ const s=new Date(); s.setMonth(s.getMonth()-12); start=fmt(s); }
        return { start, end, limit };
    }

    function anDefaultGranularity(range){
        if($('#anGranularity').data('userset')) return $('#anGranularity').val();
        if(range.limit === 8){ $('#anGranularity').val('service'); return 'service'; }
        const days = range.start ? (new Date(range.end) - new Date(range.start)) / 86400000 : 400;
        const g = days <= 120 ? 'week' : 'month';
        $('#anGranularity').val(g);
        return g;
    }

    function anLoadOverview(){
        const range = anComputeRange();
        const granularity = anDefaultGranularity(range);
        $('#anContent,#anEmpty,#anErrorBox').addClass('hidden');
        $('#anLoading').removeClass('hidden');

        const payload = { action:'overview', category: anCategory, granularity };
        if(range.limit) payload.limit = range.limit; else { payload.start_date = range.start; payload.end_date = range.end; }

        $.post(ANALYTICS_API, payload, function(res){
            $('#anLoading').addClass('hidden');
            if(res.status !== 'success'){ $('#anErrorBox').removeClass('hidden').text(res.message || 'Something went wrong.'); return; }
            anCurrentOverview = res;
            if(!res.events.length){ $('#anEmpty').removeClass('hidden'); return; }
            $('#anContent').removeClass('hidden');
            const rs = res.range;
            $('#anRangeSummary').text(`Showing ${res.events.length} service${res.events.length===1?'':'s'}, ${anFmtDateShort(rs.start)} – ${anFmtDateShort(rs.end)}.`);
            anRenderKpis(res);
            anRenderTrend(res);
            anRenderGender(res);
            anRenderStatus(res);
            anRenderRegion(res);
            anPage = 1; anSort_ = { col:'event_date', dir:'desc' };
            $('#anTableSearch').val('');
            anRenderTable();
        }, 'json').fail(function(){ $('#anLoading').addClass('hidden'); $('#anErrorBox').removeClass('hidden').text('Server error while loading analytics.'); });
    }

    // ---------- KPI cards ----------
    function anKpiCard(label, value, sub, badge, clickId){
        const clickAttr = clickId ? `onclick="anOpenEvent(${clickId})"` : '';
        const clickCls = clickId ? 'an-clickable' : '';
        return `<div class="an-kpi-card ${clickCls}" ${clickAttr}>
            <span class="an-kpi-label">${label}</span>
            <div class="an-kpi-value">${value}</div>
            <div class="an-kpi-sub flex items-center gap-1.5 flex-wrap">${sub||''} ${badge||''}</div>
        </div>`;
    }
    function anRenderKpis(o){
        const k = o.kpis;
        const html = [
            anKpiCard('Services Held', anFmtNum(k.services_count), o.category_label),
            anKpiCard('Total Attendance', anFmtNum(k.total_attendance), `${k.services_count} service${k.services_count===1?'':'s'}`, anBadgeHtml(k.growth_pct)),
            anKpiCard('Avg / Service', anFmtNum(k.avg_attendance), k.baseline_avg!==null ? `baseline ${anFmtNum(k.baseline_avg)}` : 'no baseline yet', anBadgeHtml(k.avg_vs_baseline)),
            anKpiCard('Peak Service', anFmtNum(k.peak.total), `${esc(anShortTitle(k.peak.title))} · ${anFmtDateShort(k.peak.date)}`, null, k.peak.id),
            anKpiCard('Lowest Service', anFmtNum(k.lowest.total), `${esc(anShortTitle(k.lowest.title))} · ${anFmtDateShort(k.lowest.date)}`, null, k.lowest.id),
            anKpiCard('First-Timers Reached', anFmtNum(k.first_timers_total), `${k.first_timer_rate}% of attendance`),
        ];
        $('#anKpiGrid').html(html.join(''));
    }

    // ---------- Main trend chart ----------
    function anRenderTrend(o){
        const t = o.trend;
        const labels = t.map(b => b.label);
        const totals = t.map(b => b.total);
        const avg = totals.length ? (totals.reduce((a,b)=>a+b,0) / totals.length) : 0;
        const avgLine = totals.map(() => Math.round(avg*10)/10);
        $('#anTrendCaption').text(`${t.length} point${t.length===1?'':'s'} · ${o.range.granularity==='service'?'per service':o.range.granularity}`);

        if(anChartTrend) anChartTrend.destroy();
        anChartTrend = new Chart(document.getElementById('anTrendChart').getContext('2d'), {
            type: 'line',
            data: { labels, datasets: [
                { label:'Attendance', data: totals, borderColor:'#1D356A', backgroundColor:'rgba(29,53,106,0.08)', fill:true, tension:.35, pointRadius:4, pointHoverRadius:6, pointBackgroundColor:'#1D356A' },
                { label:'Average', data: avgLine, borderColor:'#D1D5DB', borderDash:[6,6], borderWidth:1.5, pointRadius:0, fill:false, tension:0 }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display:true, position:'top', labels:{ boxWidth:10, font:{size:11, weight:'bold'} } } },
                scales: { y: { beginAtZero:true, ticks:{ precision:0 } } },
                onClick: (evt, elements) => {
                    if(!elements.length) return;
                    const bucket = t[elements[0].index];
                    if(o.range.granularity === 'service') anOpenEvent(bucket.event_ids[0]);
                    else anOpenPeriod(bucket);
                }
            }
        });
    }

    // ---------- Gender chart ----------
    function anSetGenderView(v){
        anGenderView = v;
        $('#anGenderViewBtn-totals,#anGenderViewBtn-trend').removeClass('active');
        $('#anGenderViewBtn-'+v).addClass('active');
        if(anCurrentOverview) anRenderGender(anCurrentOverview);
    }
    function anRenderGender(o){
        if(anChartGender) anChartGender.destroy();
        const ctx = document.getElementById('anGenderChart').getContext('2d');
        if(anGenderView === 'totals'){
            const g = o.gender_totals;
            const labels = ['Male','Female'], data = [g.Male, g.Female], colors = ['#1D356A','#D11920'];
            if(g.Unknown > 0){ labels.push('Not on file'); data.push(g.Unknown); colors.push('#9CA3AF'); }
            anChartGender = new Chart(ctx, { type:'doughnut', data:{ labels, datasets:[{ data, backgroundColor:colors, borderWidth:0 }] },
                options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{ position:'bottom', labels:{boxWidth:10,font:{size:11,weight:'bold'}} } } } });
        } else {
            const labels = o.trend.map(b => b.label);
            anChartGender = new Chart(ctx, { type:'bar', data:{ labels, datasets:[
                { label:'Male', data:o.trend.map(b=>b.male), backgroundColor:'#1D356A', borderRadius:4 },
                { label:'Female', data:o.trend.map(b=>b.female), backgroundColor:'#D11920', borderRadius:4 }
            ]}, options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{stacked:true}, y:{stacked:true,beginAtZero:true,ticks:{precision:0}} }, plugins:{ legend:{position:'bottom',labels:{boxWidth:10,font:{size:11,weight:'bold'}}} } } });
        }
    }

    // ---------- Spiritual status chart ----------
    function anSetStatusView(v){
        anStatusView = v;
        $('#anStatusViewBtn-totals,#anStatusViewBtn-trend').removeClass('active');
        $('#anStatusViewBtn-'+v).addClass('active');
        if(anCurrentOverview) anRenderStatus(anCurrentOverview);
    }
    function anRenderStatus(o){
        if(anChartStatus) anChartStatus.destroy();
        const ctx = document.getElementById('anStatusChart').getContext('2d');
        const keys = Object.keys(o.status_totals).filter(k => o.status_totals[k] > 0);
        if(!keys.length){ return; }
        if(anStatusView === 'totals'){
            anChartStatus = new Chart(ctx, { type:'doughnut', data:{ labels: keys.map(k=>o.status_labels[k]), datasets:[{ data: keys.map(k=>o.status_totals[k]), backgroundColor: keys.map(k=>o.status_colors[k]), borderWidth:0 }] },
                options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{position:'bottom', labels:{boxWidth:9,font:{size:10,weight:'bold'}}} } } });
        } else {
            const labels = o.trend.map(b => b.label);
            const datasets = keys.map(k => ({ label:o.status_labels[k], data:o.trend.map(b=>b.status[k]||0), backgroundColor:o.status_colors[k] }));
            anChartStatus = new Chart(ctx, { type:'bar', data:{ labels, datasets }, options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{stacked:true}, y:{stacked:true,beginAtZero:true,ticks:{precision:0}} }, plugins:{ legend:{position:'bottom',labels:{boxWidth:9,font:{size:10,weight:'bold'}}} } } });
        }
    }

    // ---------- Region leaderboard ----------
    function anRenderRegion(o){
        const entries = Object.entries(o.region_totals || {}).slice(0, 10);
        if(!entries.length){ $('#anRegionCard').addClass('hidden'); return; }
        $('#anRegionCard').removeClass('hidden');
        if(anChartRegion) anChartRegion.destroy();
        anChartRegion = new Chart(document.getElementById('anRegionChart').getContext('2d'), {
            type: 'bar',
            data: { labels: entries.map(e=>e[0]), datasets:[{ data: entries.map(e=>e[1]), backgroundColor:'#1D356A', borderRadius:6 }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ beginAtZero:true, ticks:{precision:0} } } }
        });
    }

    // ---------- Services table (client-side search/sort/paginate) ----------
    function anSort(col){
        if(anSort_.col === col) anSort_.dir = anSort_.dir === 'asc' ? 'desc' : 'asc';
        else { anSort_.col = col; anSort_.dir = 'desc'; }
        anPage = 1; anRenderTable();
    }
    function anGoPage(p){ anPage = p; anRenderTable(); }
    function anRenderTable(){
        if(!anCurrentOverview) return;
        const q = ($('#anTableSearch').val() || '').toLowerCase();
        let rows = anCurrentOverview.events.filter(e => !q || e.title.toLowerCase().includes(q) || anFmtDateShort(e.event_date).toLowerCase().includes(q));
        rows = rows.slice().sort((a,b) => {
            let av = a[anSort_.col], bv = b[anSort_.col];
            if(anSort_.col === 'event_date'){ av = anParseDate(a.event_date).getTime(); bv = anParseDate(b.event_date).getTime(); }
            if(anSort_.col === 'vs_previous'){ av = (av===null||av===undefined) ? -Infinity : av; bv = (bv===null||bv===undefined) ? -Infinity : bv; }
            if(av < bv) return anSort_.dir === 'asc' ? -1 : 1;
            if(av > bv) return anSort_.dir === 'asc' ? 1 : -1;
            return 0;
        });

        $('.an-sort-ind').text('');
        $('.an-sort-ind[data-col="'+anSort_.col+'"]').text(anSort_.dir === 'asc' ? '▲' : '▼');

        const total = rows.length;
        const totalPages = Math.max(1, Math.ceil(total / anPerPage));
        if(anPage > totalPages) anPage = totalPages;
        const start = (anPage - 1) * anPerPage;
        const pageRows = rows.slice(start, start + anPerPage);

        let html = '';
        pageRows.forEach(e => {
            html += `<tr class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-bold text-gray-800 whitespace-nowrap">${anFmtDateShort(e.event_date)}</td>
                <td class="px-5 py-3 text-gray-600 max-w-[220px] truncate" title="${esc(e.title)}">${esc(e.title)}</td>
                <td class="px-5 py-3 font-black text-gray-900">${anFmtNum(e.total)}</td>
                <td class="px-5 py-3 text-gray-500"><span class="text-hodBlue font-bold">${e.male}</span> / <span class="text-hodRed font-bold">${e.female}</span></td>
                <td class="px-5 py-3">${anFmtNum(e.first_timers)}</td>
                <td class="px-5 py-3">${anFmtNum(e.workers)}</td>
                <td class="px-5 py-3">${anVsPreviousHtml(e)}</td>
                <td class="px-5 py-3 text-right"><button onclick="anOpenEvent(${e.id})" class="an-info-btn" style="width:26px;height:26px;font-size:13px;">ⓘ</button></td>
            </tr>`;
        });
        $('#anTableBody').html(html || `<tr><td colspan="8" class="px-5 py-10 text-center text-gray-400">No matching services.</td></tr>`);

        let cards = '';
        pageRows.forEach(e => {
            cards += `<div class="p-4 flex items-center justify-between gap-3 an-clickable" onclick="anOpenEvent(${e.id})">
                <div class="min-w-0">
                    <p class="text-xs font-bold text-gray-400">${anFmtDateShort(e.event_date)}</p>
                    <p class="font-bold text-gray-900 truncate">${esc(e.title)}</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">${e.male}M / ${e.female}F · ${e.first_timers} 1st-timers</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-lg font-black text-gray-900">${anFmtNum(e.total)}</p>
                    ${anVsPreviousHtml(e)}
                </div>
            </div>`;
        });
        $('#anCardList').html(cards || `<div class="p-8 text-center text-gray-400 text-sm">No matching services.</div>`);

        $('#anPageInfo').text(`Showing ${total===0?0:start+1}-${Math.min(start+anPerPage,total)} of ${total}`);
        let pag = '';
        for(let p=1; p<=totalPages; p++){
            if(totalPages > 7 && Math.abs(p-anPage) > 2 && p !== 1 && p !== totalPages){ if(p===2 || p===totalPages-1) pag += '<span class="px-2 text-gray-300">…</span>'; continue; }
            pag += `<button onclick="anGoPage(${p})" class="w-8 h-8 rounded-lg text-xs font-bold ${p===anPage?'bg-hodBlue text-white':'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'}">${p}</button>`;
        }
        $('#anPaginationControls').html(pag);
    }

    // ---------- Period Detail Modal (click a point on the trend chart) ----------
    function anOpenPeriod(bucket){
        const events = anCurrentOverview.events.filter(e => bucket.event_ids.includes(e.id));
        const total = events.reduce((s,e)=>s+e.total,0);
        const avg = events.length ? Math.round(total/events.length*10)/10 : 0;
        $('#anPeriodModalHeader').html(`<div>
            <p class="text-[10px] font-black uppercase tracking-widest text-hodBlue">${esc(anCurrentOverview.category_label)}</p>
            <h3 class="text-lg font-bold text-gray-900">${esc(bucket.label)}</h3>
            <p class="text-xs text-gray-400 mt-0.5">${events.length} service${events.length===1?'':'s'} · ${anFmtNum(total)} total attendance · avg ${avg}</p>
        </div>`);
        let rows = '';
        events.forEach(e => {
            rows += `<button onclick="closeModal('anPeriodModal'); anOpenEvent(${e.id});" class="w-full text-left flex items-center justify-between gap-3 p-3.5 rounded-2xl border border-gray-100 hover:border-hodBlue/30 hover:bg-blue-50/30 transition-all">
                <div class="min-w-0">
                    <p class="font-bold text-gray-900 truncate">${esc(e.title)}</p>
                    <p class="text-xs text-gray-400">${anFmtDateShort(e.event_date)}${e.location ? ' · '+esc(e.location) : ''}</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-lg font-black text-gray-900">${anFmtNum(e.total)}</p>
                    ${anVsPreviousHtml(e)}
                </div>
            </button>`;
        });
        $('#anPeriodModalBody').html(rows);
        openModal('anPeriodModal');
    }

    // ---------- Event Detail Modal ----------
    function anOpenEvent(id){
        if(!id) return;
        openModal('anEventModal');
        $('#anEventModalHeader').html('<p class="text-gray-400 text-sm">Loading…</p>');
        $('#anEventModalBody').html('<div class="py-16 text-center"><div class="inline-block w-7 h-7 border-4 border-hodBlue/20 border-t-hodBlue rounded-full animate-spin"></div></div>');
        $.post(ANALYTICS_API, { action:'event_detail', event_id:id }, function(res){
            if(res.status !== 'success'){ $('#anEventModalHeader').html(''); $('#anEventModalBody').html(`<p class="text-red-500 text-sm font-bold">${esc(res.message||'Failed to load.')}</p>`); return; }
            anRenderEventModal(res);
        }, 'json').fail(() => { $('#anEventModalBody').html('<p class="text-red-500 text-sm font-bold">Server error.</p>'); });
    }

    let anFullReportNote = '';
    function anExpandReportNote(btn){
        const target = document.getElementById('anReportNoteText');
        if(target) target.textContent = anFullReportNote;
        if(btn) btn.remove();
    }
    function anReportNotesHtml(notes){
        const plain = String(notes).replace(/<[^>]*>/g, '');
        if(!plain.trim()) return '';
        const isLong = plain.length > 160;
        anFullReportNote = plain;
        const shortText = isLong ? plain.slice(0,160) + '…' : plain;
        let html = `<div class="border border-gray-100 rounded-2xl p-4 bg-amber-50/40">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">Report Notes</p>
            <p class="text-sm text-gray-700 leading-relaxed" id="anReportNoteText">${esc(shortText)}</p>`;
        if(isLong){
            html += `<button type="button" class="text-xs font-bold text-hodBlue mt-1.5 hover:underline" onclick="anExpandReportNote(this)">Read full note</button>`;
        }
        html += `</div>`;
        return html;
    }

    function anToggleRoster(){
        $('#anRosterPanel').toggleClass('hidden');
        $('#anRosterChevron').toggleClass('rotate-180');
    }
    function anRenderRoster(){
        const q = ($('#anRosterSearchInput').val() || '').toLowerCase();
        const statusMap = { '1st_Timer':'1st Timer','2nd_Timer':'2nd Timer','3rd_Timer':'3rd Timer','Non_Member':'Non-Member','Not_On_File':'Not on file' };
        const rows = anEventRoster.filter(r => !q || ((r.first_name||'')+' '+(r.last_name||'')).toLowerCase().includes(q) || (r.phone||'').includes(q));
        let html = '';
        rows.forEach(r => {
            const statusLabel = statusMap[r.spiritual_status] || (r.spiritual_status || 'Unspecified');
            html += `<div class="p-3 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-bold text-gray-800 text-sm truncate">${esc(((r.first_name||'')+' '+(r.last_name||'')).trim())}</p>
                    <p class="text-[11px] text-gray-400">${esc(r.phone||'—')}${r.region_name ? ' · '+esc(r.region_name) : ''}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    ${r.gender ? `<span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${r.gender==='Male'?'bg-blue-50 text-hodBlue':'bg-red-50 text-hodRed'}">${r.gender}</span>` : ''}
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">${esc(statusLabel)}</span>
                </div>
            </div>`;
        });
        $('#anRosterList').html(html || '<div class="p-6 text-center text-gray-400 text-xs">No matches.</div>');
    }

    function anRenderEventModal(res){
        const e = res.event, s = res.stats;
        const catCls = e.category === 'Sunday_Service' ? 'bg-blue-50 text-hodBlue' : 'bg-red-50 text-hodRed';
        $('#anEventModalHeader').html(`<div class="min-w-0">
            <span class="inline-block text-[10px] font-black uppercase tracking-widest px-2 py-1 rounded ${catCls}">${esc(e.category_label)}</span>
            <h3 class="text-lg md:text-xl font-bold text-gray-900 mt-1.5 truncate">${esc(e.title)}</h3>
            <p class="text-xs text-gray-500 mt-1">${anFmtDateFull(e.event_date)}${e.location ? ' · '+esc(e.location) : ''}</p>
        </div>`);

        const ministersHtml = (e.ministers && e.ministers.length) ? `<div class="flex flex-wrap gap-2">${e.ministers.map(m => `<span class="text-xs font-bold bg-gray-50 border border-gray-100 px-2.5 py-1 rounded-full text-gray-600">${esc(m.name||'')}</span>`).join('')}</div>` : '';

        const prevHtml = res.previous
            ? `<div class="an-kpi-card"><span class="an-kpi-label">vs Previous Service</span><div class="an-kpi-value">${anFmtNum(res.previous.total)}</div><div class="an-kpi-sub">${anFmtDateShort(res.previous.date)} ${anBadgeHtml(res.previous.delta_pct)}</div></div>`
            : `<div class="an-kpi-card"><span class="an-kpi-label">vs Previous Service</span><div class="an-kpi-value text-gray-300">—</div><div class="an-kpi-sub">No earlier service on record</div></div>`;

        const baselineHtml = res.baseline_avg !== null
            ? `<div class="an-kpi-card"><span class="an-kpi-label">vs 12-Service Average</span><div class="an-kpi-value">${anFmtNum(res.baseline_avg)}</div><div class="an-kpi-sub">${anBadgeHtml(anPctChange(res.baseline_avg, s.total))}</div></div>`
            : '';

        const kpiGrid = `<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="an-kpi-card"><span class="an-kpi-label">Total Attendance</span><div class="an-kpi-value">${anFmtNum(s.total)}</div></div>
            <div class="an-kpi-card"><span class="an-kpi-label">Male / Female</span><div class="an-kpi-value text-lg"><span class="text-hodBlue">${s.male}</span> / <span class="text-hodRed">${s.female}</span></div></div>
            <div class="an-kpi-card"><span class="an-kpi-label">1st Timers</span><div class="an-kpi-value">${anFmtNum(s.first_timers)}</div></div>
            <div class="an-kpi-card"><span class="an-kpi-label">Workers</span><div class="an-kpi-value">${anFmtNum(s.workers)}</div></div>
            ${prevHtml}
            ${baselineHtml}
        </div>`;

        const hasRegionData = Object.keys(s.region||{}).length > 0;

        $('#anEventModalBody').html(`
            ${ministersHtml}
            ${kpiGrid}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="border border-gray-100 rounded-2xl p-4">
                    <div class="flex items-center gap-1.5 mb-2"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Last 8 Occurrences</p><button type="button" onclick="anInfo('Last 8 Occurrences','A quick sparkline of the last 8 occurrences of this service (same category), with this specific service highlighted in red.')" class="an-info-btn">ⓘ</button></div>
                    <div class="h-40"><canvas id="anEvtSparkChart"></canvas></div>
                </div>
                <div class="border border-gray-100 rounded-2xl p-4">
                    <div class="flex items-center gap-1.5 mb-2"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Check-in Pace</p><button type="button" onclick="anInfo('Check-in Pace','How attendance built up hour by hour, based on the exact time each person was marked Present.')" class="an-info-btn">ⓘ</button></div>
                    <div class="h-40">${res.pace.has_data ? '<canvas id="anEvtPaceChart"></canvas>' : '<div class="h-full flex items-center justify-center text-xs text-gray-400 text-center px-4">No timestamped check-in data for this service.</div>'}</div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="border border-gray-100 rounded-2xl p-4">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Who Attended</p>
                    <div class="h-48"><canvas id="anEvtStatusChart"></canvas></div>
                </div>
                <div class="border border-gray-100 rounded-2xl p-4">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">By Region</p>
                    <div class="h-48">${hasRegionData ? '<canvas id="anEvtRegionChart"></canvas>' : '<div class="h-full flex items-center justify-center text-xs text-gray-400">No region data available.</div>'}</div>
                </div>
            </div>
            ${res.event.report_notes ? anReportNotesHtml(res.event.report_notes) : ''}
            <div>
                <button type="button" onclick="anToggleRoster()" id="anRosterToggleBtn" class="w-full flex items-center justify-between bg-gray-50 hover:bg-gray-100 rounded-2xl px-4 py-3 border border-gray-100 transition-all">
                    <span class="text-sm font-bold text-gray-700">View Attendee Roster (${res.roster.length})</span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" id="anRosterChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="anRosterPanel" class="hidden mt-3">
                    <input type="text" id="anRosterSearchInput" placeholder="Search roster…" oninput="anRenderRoster()" class="w-full mb-3 px-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
                    <div class="max-h-72 overflow-y-auto custom-scrollbar border border-gray-100 rounded-xl divide-y divide-gray-50" id="anRosterList"></div>
                </div>
            </div>
        `);

        anEventRoster = res.roster;
        anRenderRoster();

        if(anEvtCharts.spark) anEvtCharts.spark.destroy();
        anEvtCharts.spark = new Chart(document.getElementById('anEvtSparkChart').getContext('2d'), {
            type: 'line',
            data: { labels: res.sparkline.map(p => anFmtDateShort(p.date)), datasets: [{ data: res.sparkline.map(p => p.total), borderColor:'#1D356A', backgroundColor:'rgba(29,53,106,.08)', fill:true, tension:.3, pointRadius:3, pointBackgroundColor: res.sparkline.map(p => p.id===e.id ? '#D11920' : '#1D356A') }] },
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true, ticks:{precision:0}} } }
        });

        if(res.pace.has_data){
            if(anEvtCharts.pace) anEvtCharts.pace.destroy();
            anEvtCharts.pace = new Chart(document.getElementById('anEvtPaceChart').getContext('2d'), {
                type: 'bar',
                data: { labels: res.pace.hours.map(h => h.label), datasets: [{ data: res.pace.hours.map(h => h.count), backgroundColor:'#D11920', borderRadius:4 }] },
                options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{precision:0}} } }
            });
        }

        if(anEvtCharts.status) anEvtCharts.status.destroy();
        const skeys = Object.keys(s.status).filter(k => s.status[k] > 0);
        anEvtCharts.status = new Chart(document.getElementById('anEvtStatusChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: skeys.map(k => res.status_labels[k]), datasets: [{ data: skeys.map(k => s.status[k]), backgroundColor: skeys.map(k => res.status_colors[k]), borderWidth:0 }] },
            options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{position:'bottom', labels:{boxWidth:9,font:{size:10,weight:'bold'}}} } }
        });

        if(hasRegionData){
            if(anEvtCharts.region) anEvtCharts.region.destroy();
            const rentries = Object.entries(s.region).slice(0,8);
            anEvtCharts.region = new Chart(document.getElementById('anEvtRegionChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: rentries.map(x=>x[0]), datasets:[{ data: rentries.map(x=>x[1]), backgroundColor:['#1D356A','#D11920','#2563EB','#F97316','#10B981','#7C3AED','#F59E0B','#06B6D4'], borderWidth:0 }] },
                options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{position:'bottom', labels:{boxWidth:9,font:{size:10,weight:'bold'}}} } }
            });
        }
    }

    // ---------- Compare Periods Modal ----------
    function openCompareModal(){
        $('#anCompareCatLabel').text(anCategory === 'Sunday_Service' ? 'Sunday Service' : 'Midweek Service');
        $('#anCompareResults').addClass('hidden').html('');
        anRenderComparePresets();
        openModal('anCompareModal');
    }
    function anFmtISO(d){ return d.toISOString().slice(0,10); }
    function anSetCompareInputs(aS,aE,bS,bE){ $('#anCompA_start').val(aS); $('#anCompA_end').val(aE); $('#anCompB_start').val(bS); $('#anCompB_end').val(bE); }
    function anPresetMonths(){
        const now = new Date();
        anSetCompareInputs(anFmtISO(new Date(now.getFullYear(), now.getMonth(), 1)), anFmtISO(now),
            anFmtISO(new Date(now.getFullYear(), now.getMonth()-1, 1)), anFmtISO(new Date(now.getFullYear(), now.getMonth(), 0)));
    }
    function anPresetQuarters(){
        const now = new Date(); const q = Math.floor(now.getMonth()/3);
        anSetCompareInputs(anFmtISO(new Date(now.getFullYear(), q*3, 1)), anFmtISO(now),
            anFmtISO(new Date(now.getFullYear(), (q-1)*3, 1)), anFmtISO(new Date(now.getFullYear(), q*3, 0)));
    }
    function anPresetYears(){
        const now = new Date();
        anSetCompareInputs(now.getFullYear()+'-01-01', anFmtISO(now), (now.getFullYear()-1)+'-01-01', (now.getFullYear()-1)+'-12-31');
    }
    function anPresetLast4(){
        if(!anCurrentOverview || !anCurrentOverview.events.length){ showToast('Load some data on this tab first.','error'); return; }
        const all = anCurrentOverview.events.slice().sort((a,b) => anParseDate(a.event_date) - anParseDate(b.event_date));
        const last4 = all.slice(-4), prev4 = all.slice(-8,-4);
        if(!last4.length){ showToast('Not enough services loaded yet.','error'); return; }
        const aS = last4[0].event_date.slice(0,10), aE = last4[last4.length-1].event_date.slice(0,10);
        const bS = prev4.length ? prev4[0].event_date.slice(0,10) : aS;
        const bE = prev4.length ? prev4[prev4.length-1].event_date.slice(0,10) : aS;
        anSetCompareInputs(aS,aE,bS,bE);
    }
    function anRenderComparePresets(){
        window._anComparePresets = [
            { label:'This month vs last month', fn: anPresetMonths },
            { label:'This quarter vs last quarter', fn: anPresetQuarters },
            { label:'This year vs last year', fn: anPresetYears },
            { label:'Last 4 vs previous 4 services', fn: anPresetLast4 },
        ];
        $('#anComparePresets').html(window._anComparePresets.map((p,i) => `<button type="button" onclick="anApplyComparePreset(${i})" class="text-xs font-bold bg-gray-100 hover:bg-gray-200 text-gray-700 px-3.5 py-2 rounded-xl transition-colors">${p.label}</button>`).join(''));
    }
    function anApplyComparePreset(i){ window._anComparePresets[i].fn(); }

    function anRunCompare(){
        const a_start = $('#anCompA_start').val(), a_end = $('#anCompA_end').val(), b_start = $('#anCompB_start').val(), b_end = $('#anCompB_end').val();
        if(!a_start || !a_end || !b_start || !b_end){ showToast('Please fill in both period ranges.','error'); return; }
        $.post(ANALYTICS_API, { action:'compare', category:anCategory, a_start, a_end, b_start, b_end }, function(res){
            if(res.status !== 'success'){ showToast(res.message || 'Comparison failed.','error'); return; }
            anRenderCompareResults(res);
        }, 'json').fail(() => showToast('Server error while comparing.','error'));
    }

    function anRenderCompareResults(res){
        const A = res.a, B = res.b, D = res.deltas;
        const row = (label, av, bv, d) => `<tr class="border-b border-gray-50">
            <td class="py-2.5 text-xs font-bold text-gray-500">${label}</td>
            <td class="py-2.5 text-sm font-black text-hodBlue text-right pr-4">${av}</td>
            <td class="py-2.5 text-sm font-black text-hodRed text-right pr-4">${bv}</td>
            <td class="py-2.5 text-right">${anBadgeHtml(d)}</td>
        </tr>`;
        $('#anCompareResults').removeClass('hidden').html(`
            <div class="grid grid-cols-2 gap-3 text-center">
                <div class="bg-blue-50/50 rounded-2xl p-3"><p class="text-[10px] font-bold text-hodBlue uppercase">Period A</p><p class="text-xs text-gray-500 mt-0.5">${anFmtDateShort(A.label_start)} – ${anFmtDateShort(A.label_end)}</p></div>
                <div class="bg-red-50/50 rounded-2xl p-3"><p class="text-[10px] font-bold text-hodRed uppercase">Period B</p><p class="text-xs text-gray-500 mt-0.5">${anFmtDateShort(B.label_start)} – ${anFmtDateShort(B.label_end)}</p></div>
            </div>
            <table class="w-full mt-2">
                <thead><tr class="text-left text-[10px] font-black text-gray-400 uppercase border-b border-gray-100"><th class="pb-2">Metric</th><th class="pb-2 text-right pr-4">A</th><th class="pb-2 text-right pr-4">B</th><th class="pb-2 text-right">A → B</th></tr></thead>
                <tbody>
                    ${row('Services Held', A.services_count, B.services_count, D.services_count)}
                    ${row('Total Attendance', anFmtNum(A.total_attendance), anFmtNum(B.total_attendance), D.total_attendance)}
                    ${row('Avg / Service', A.avg_attendance, B.avg_attendance, D.avg_attendance)}
                    ${row('First-Timers', A.first_timers, B.first_timers, D.first_timers)}
                    ${row('Workers Present', A.workers, B.workers, D.workers)}
                </tbody>
            </table>
            <div class="h-56"><canvas id="anCompareChart"></canvas></div>
        `);
        if(window._anCompareChart) window._anCompareChart.destroy();
        window._anCompareChart = new Chart(document.getElementById('anCompareChart').getContext('2d'), {
            type: 'bar',
            data: { labels: ['Total Attendance','Avg / Service','First-Timers','Workers'],
                datasets: [
                    { label:'Period A', data:[A.total_attendance, A.avg_attendance, A.first_timers, A.workers], backgroundColor:'#1D356A', borderRadius:6 },
                    { label:'Period B', data:[B.total_attendance, B.avg_attendance, B.first_timers, B.workers], backgroundColor:'#D11920', borderRadius:6 },
                ] },
            options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'bottom', labels:{boxWidth:10,font:{size:11,weight:'bold'}}} }, scales:{ y:{beginAtZero:true, ticks:{precision:0}} } }
        });
    }

    // ---------- CSV export (client-side, from whatever is currently loaded) ----------
    function anExportCsv(){
        if(!anCurrentOverview || !anCurrentOverview.events.length){ showToast('Nothing to export yet.','error'); return; }
        const headers = ['Date','Title','Total Attendance','Male','Female','1st Timers','Workers','vs Previous %'];
        const lines = [headers.join(',')];
        anCurrentOverview.events.forEach(e => {
            lines.push([anFmtDateShort(e.event_date), '"'+String(e.title).replace(/"/g,'""')+'"', e.total, e.male, e.female, e.first_timers, e.workers, (e.vs_previous===null||e.vs_previous===undefined?'':e.vs_previous)].join(','));
        });
        const blob = new Blob([lines.join('\n')], { type:'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = `${anCurrentOverview.category_label.replace(/\s+/g,'_')}_analytics_${anCurrentOverview.range.start}_to_${anCurrentOverview.range.end}.csv`;
        document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    }

    $(document).ready(function(){
        if(typeof Quill !== 'undefined'){
            descQuill = new Quill('#cfgDescriptionEditor', {
                theme: 'snow',
                placeholder: 'Tell people what this event is about...',
                modules: { toolbar: [['bold','italic','underline'], ['bullet','list']] }
            });
            descQuill.on('text-change', function(){
                $('#cfg_description').val(descQuill.root.innerHTML);
                renderPreview();
            });
        }
        loadEvents(); renderPreview();
    });
</script>
<?php require_once '../../includes/footer.php'; ?>