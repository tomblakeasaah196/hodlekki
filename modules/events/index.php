<?php
// /modules/events/index.php
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<!-- QR rendering for the "I'm New Here" first-timer path (same lib as Check-in QR module) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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
        <div class="relative z-10 event-tabs-area">
            <div id="eventTabsShell" class="event-tabs-shell">
                <button type="button" id="eventTabsPrev" class="event-tabs-control" aria-label="Show previous Events Center sections" title="Show previous sections">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m15 18-6-6 6-6"/></svg>
                </button>
                <div id="eventSectionTabs" class="event-tabs custom-scrollbar" role="tablist" aria-label="Events Center sections">
                    <button type="button" onclick="switchSection('events', true)" id="btn-events" data-section="events" role="tab" aria-controls="section-events" aria-selected="true" aria-current="page" tabindex="0" class="event-tab event-tab-active shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all">Events</button>
                    <button type="button" onclick="switchSection('configure', true)" id="btn-configure" data-section="configure" role="tab" aria-controls="section-configure" aria-selected="false" tabindex="-1" class="event-tab shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Configure</button>
                    <button type="button" onclick="switchSection('registrations', true)" id="btn-registrations" data-section="registrations" role="tab" aria-controls="section-registrations" aria-selected="false" tabindex="-1" class="event-tab shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Registrations</button>
                    <button type="button" onclick="switchSection('attendance', true)" id="btn-attendance" data-section="attendance" role="tab" aria-controls="section-attendance" aria-selected="false" tabindex="-1" class="event-tab shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Attendance</button>
                    <button type="button" onclick="switchSection('analytics', true)" id="btn-analytics" data-section="analytics" role="tab" aria-controls="section-analytics" aria-selected="false" tabindex="-1" class="event-tab shrink-0 whitespace-nowrap px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900 flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        Analytics
                    </button>
                </div>
                <button type="button" id="eventTabsNext" class="event-tabs-control" aria-label="Show more Events Center sections" title="Show more sections">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
            <p id="eventTabsStatus" class="event-tabs-status" role="status" aria-live="polite">
                <span id="eventTabsInstruction" class="hidden">Swipe or use the arrows to see more</span>
                <span id="eventTabsDivider" class="hidden" aria-hidden="true">&middot;</span>
                <span>Viewing: <strong id="eventTabsCurrent">Events</strong></span>
            </p>
        </div>
    </div>

    <!-- ============================ SECTION: EVENTS ============================ -->
    <section id="section-events" role="tabpanel" aria-labelledby="btn-events" class="space-y-6 animate-fade-in-up">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
            <div class="relative w-full md:w-80 lg:w-96">
                <input type="text" id="searchEvents" placeholder="Search events..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue focus:border-transparent outline-none transition-all bg-gray-50">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>

            <div class="flex w-full md:w-auto items-center justify-end gap-2">
                <details id="eventCreateActions" data-event-action-menu class="relative flex-1 md:flex-none group">
                    <summary class="list-none cursor-pointer inline-flex h-11 w-full md:w-auto items-center justify-center gap-2 bg-hodBlue hover:bg-[#152750] text-white px-4 sm:px-5 rounded-xl font-bold shadow-md transition-all text-sm [&::-webkit-details-marker]:hidden" aria-haspopup="menu" title="Create an event">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        <span>Create Event</span>
                        <svg class="w-4 h-4 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="absolute right-0 z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-gray-100 bg-white p-2 shadow-2xl" role="menu" aria-label="Create event options">
                        <button type="button" onclick="closeEventActionMenus(); startNewEvent();" class="w-full flex items-start gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue transition-colors" role="menuitem">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-hodBlue">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </span>
                            <span>
                                <span class="block">One-off Event</span>
                                <span class="block text-xs font-semibold text-gray-500">Create a standalone event with its own setup.</span>
                            </span>
                        </button>
                        <button type="button" onclick="closeEventActionMenus(); openMonthlyServicesModal();" class="w-full flex items-start gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-gray-700 hover:bg-red-50 hover:text-red-700 transition-colors" role="menuitem" title="Create all Total Experience and Mercy Experience services for a month">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </span>
                            <span>
                                <span class="block">Monthly Series</span>
                                <span class="block text-xs font-semibold text-gray-500">Generate Sunday and Thursday services for a month.</span>
                            </span>
                        </button>
                    </div>
                </details>

                <details id="eventMoreActions" data-event-action-menu class="relative shrink-0 group">
                    <summary class="list-none cursor-pointer inline-flex h-11 w-11 items-center justify-center rounded-xl border border-gray-200 bg-white text-hodBlue hover:bg-blue-50 hover:border-blue-100 shadow-sm transition-all [&::-webkit-details-marker]:hidden" aria-label="More event actions" aria-haspopup="menu" title="More actions">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                    </summary>
                    <div class="absolute right-0 z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-gray-100 bg-white p-2 shadow-2xl" role="menu" aria-label="More event actions">
                        <div class="px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-gray-400">More actions</p>
                        </div>
                        <a href="/modules/event_qr/index.php" class="flex items-start gap-3 rounded-xl px-3 py-3 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue transition-colors" role="menuitem" title="Generate a branded event QR poster">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-hodBlue">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h4v4H7V4zm6 0h4v4h-4V4zM3 14h4v4H3v-4zm12 0h6v6h-6v-6zm-2 0v-2h-2v-2h4v4h-2zm-6 0H7v2H5v-4h2v2zm6 4h2v-2h2v4h-4v-2z"/></svg>
                            </span>
                            <span>
                                <span class="block">Branded QR Poster</span>
                                <span class="block text-xs font-semibold text-gray-500">Create a shareable event QR design.</span>
                            </span>
                        </a>
                        <a href="/modules/event_report/index.php" class="flex items-start gap-3 rounded-xl px-3 py-3 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue transition-colors" role="menuitem" title="Open Event Data & Engagement Report (SMS, registration, attendance, IDI)">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-hodBlue">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </span>
                            <span>
                                <span class="block">Event Report</span>
                                <span class="block text-xs font-semibold text-gray-500">Review registration, attendance and engagement.</span>
                            </span>
                        </a>
                        <div class="my-1 border-t border-gray-100"></div>
                        <a href="/modules/checkin_qr/index.php" class="flex items-start gap-3 rounded-xl px-3 py-3 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue transition-colors" role="menuitem" title="Generate the check-in QR code for this event">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-hodBlue">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h6v6H3V3zm12 0h6v6h-6V3zM3 15h6v6H3v-6zm12 0h2v2h-2v-2zm4 0h2v6h-6v-2h4v-4z"/></svg>
                            </span>
                            <span>
                                <span class="block">Check-in QR</span>
                                <span class="block text-xs font-semibold text-gray-500">Open the check-in QR generator.</span>
                            </span>
                        </a>
                        <a href="/modules/checkin_monitor/index.php" class="flex items-start gap-3 rounded-xl px-3 py-3 text-sm font-bold text-gray-700 hover:bg-blue-50 hover:text-hodBlue transition-colors" role="menuitem" title="View live check-in KPIs">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-hodBlue">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                            </span>
                            <span>
                                <span class="block">Check-in Monitor</span>
                                <span class="block text-xs font-semibold text-gray-500">Watch live check-in activity.</span>
                            </span>
                        </a>
                    </div>
                </details>
            </div>
        </div>
        <div id="eventsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5"></div>
    </section>

    <!-- ============================ SECTION: CONFIGURE ============================ -->
    <section id="section-configure" role="tabpanel" aria-labelledby="btn-configure" class="hidden space-y-6 animate-fade-in-up">
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
    <section id="section-registrations" role="tabpanel" aria-labelledby="btn-registrations" class="hidden space-y-6 animate-fade-in-up">
        
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
    <section id="section-attendance" role="tabpanel" aria-labelledby="btn-attendance" class="hidden space-y-6 animate-fade-in-up">
        <div class="bg-white p-5 md:p-6 rounded-3xl shadow-sm border border-gray-100 flex flex-col md:flex-row gap-4 justify-between items-center">
            <div class="w-full md:w-1/2">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Select Event to Track</label>
                <select id="attendanceEventSelect" class="w-full px-4 py-3.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none font-bold bg-gray-50 cursor-pointer"><option value="">-- Choose an active event --</option></select>
            </div>
            <div class="w-full md:w-1/3">
                <label for="rosterSearch" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Quick Search</label>
                <div class="relative">
                    <input type="text" id="rosterSearch" placeholder="Type a name..." autocomplete="off" role="combobox" aria-expanded="false" aria-controls="attSearchPopover" aria-autocomplete="list" disabled class="w-full pl-10 pr-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-hodBlue outline-none disabled:bg-gray-100 disabled:cursor-not-allowed">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <!-- Pending-only results, anchored to the search box: clock in
                         without scrolling to the roster lists. -->
                    <div id="attSearchPopover" class="hidden" role="region" aria-label="Quick search pending matches"></div>
                </div>
                <button type="button" id="attAddPersonLink" onclick="attSearchCtaAdd()" class="hidden mt-2.5 items-center gap-1.5 text-xs font-black text-hodBlue hover:text-hodRed transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 rounded-lg px-1 -ml-1 py-0.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                    Can't find? Add to congregation
                </button>
            </div>
        </div>
        
        <!-- Attendance KPIs -->
        <div id="attMobileKpiToggle" class="hidden md:hidden">
            <button type="button" onclick="showAttendanceKpisFromToggle()" class="w-full bg-white border border-blue-100 text-hodBlue rounded-2xl px-4 py-3 shadow-sm font-black text-sm flex items-center justify-between gap-3">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    View KPI Cards
                </span>
                <span id="attMobileKpiSummary" class="text-xs text-gray-400 font-bold">Hidden while searching</span>
            </button>
        </div>
        <div id="attKpiContainer" class="hidden grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 animate-fade-in-up">
            <button type="button" onclick="openAttKpiDetails('card1')" class="att-kpi-card bg-white p-6 rounded-3xl border border-gray-100 shadow-sm text-left transition-all hover:-translate-y-0.5 hover:shadow-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-100">
                <span id="attKpiTotalLabel" class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Total Checked In</span>
                <span id="attKpiTotal" class="block text-3xl font-black text-gray-900">0</span>
                <span class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-black text-hodBlue bg-blue-50 px-2.5 py-1 rounded-full">View details</span>
                <span id="attKpiTotalSub" class="block text-[11px] text-gray-400 mt-2">0 registered total</span>
            </button>
            <button type="button" onclick="openAttKpiDetails('card2')" class="att-kpi-card bg-white p-6 rounded-3xl border border-gray-100 shadow-sm flex flex-col justify-center relative overflow-hidden text-left transition-all hover:-translate-y-0.5 hover:shadow-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-100">
                <span id="attKpiTodayLabel" class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Checked In Today</span>
                <span id="attKpiToday" class="block text-3xl font-black text-gray-900">0</span>
                <span id="attKpiTrendBadge" class="flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500 w-fit mt-2"><span>--</span></span>
                <span class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-black text-hodBlue bg-blue-50 px-2.5 py-1 rounded-full">View details</span>
            </button>
            <button type="button" onclick="openAttKpiDetails('card3')" class="att-kpi-card bg-white p-6 rounded-3xl border border-gray-100 shadow-sm text-left transition-all hover:-translate-y-0.5 hover:shadow-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-100">
                <span id="attKpiMembersLabel" class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Members / Walk-ins</span>
                <span class="block text-2xl font-black text-gray-900"><span id="attKpiMembers" class="text-[#1D356A]">0</span> <span class="text-gray-300 text-lg">/</span> <span id="attKpiWalkins" class="text-[#D11920]">0</span></span>
                <span class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-black text-hodBlue bg-blue-50 px-2.5 py-1 rounded-full">View details</span>
                <span id="attKpiMembersSub" class="block text-[11px] text-gray-400 mt-2">members / walk-ins</span>
            </button>
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
    <section id="section-analytics" role="tabpanel" aria-labelledby="btn-analytics" class="hidden space-y-5 animate-fade-in-up">

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

<!-- Attendance KPI Details Modal -->
<div id="attKpiDetailsModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b border-gray-100 bg-blue-50/70 flex justify-between items-start gap-4 shrink-0">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-hodBlue mb-1">Attendance insight</p>
                <h3 id="attKpiDetailsTitle" class="text-xl md:text-2xl font-bold text-gray-900">KPI Details</h3>
                <p id="attKpiDetailsSub" class="text-sm text-gray-500 mt-1">Filtered attendance details for this event.</p>
            </div>
            <button type="button" onclick="closeModal('attKpiDetailsModal')" class="text-gray-400 hover:text-hodBlue transition-colors shrink-0" aria-label="Close">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="attKpiDetailsBody" class="p-5 md:p-6 overflow-y-auto custom-scrollbar space-y-4 bg-gray-50/50"></div>
    </div>
</div>

<!-- Verify Details Modal (Attendance tab) -->
<div id="attVerifyModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[92vh]">
        <div class="px-6 py-5 border-b border-gray-100 bg-blue-50/70 flex justify-between items-start gap-4 shrink-0">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-hodBlue mb-1">Verify details</p>
                <h3 id="attVerifyName" class="text-xl md:text-2xl font-bold text-gray-900 truncate">Loading…</h3>
                <div id="attVerifyBadges" class="flex flex-wrap items-center gap-2 mt-2"></div>
            </div>
            <button type="button" onclick="closeModal('attVerifyModal')" class="text-gray-400 hover:text-hodBlue transition-colors shrink-0" aria-label="Close">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="attVerifyBody" class="p-5 md:p-6 overflow-y-auto custom-scrollbar bg-gray-50/50 space-y-4"></div>
        <div id="attVerifyActions" class="px-5 md:px-6 py-4 border-t border-gray-100 bg-white shrink-0"></div>
    </div>
</div>

<!-- HOD-branded confirmation (replaces native confirm() for clock-out, etc.) -->
<div id="hodConfirmModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[10005] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="p-6 md:p-8 text-center">
            <div class="w-16 h-16 rounded-full bg-red-50 text-hodRed border border-red-100 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h3 id="hodConfirmTitle" class="text-xl font-black text-gray-900">Are you sure?</h3>
            <p id="hodConfirmMessage" class="text-sm text-gray-500 font-medium mt-2 leading-relaxed"></p>
            <p id="hodConfirmContext" class="hidden text-[10px] font-black uppercase tracking-widest text-gray-400 mt-3"></p>
        </div>
        <div class="px-6 pb-6 flex flex-col sm:flex-row-reverse gap-3">
            <button type="button" id="hodConfirmOk" class="w-full sm:w-auto bg-hodRed hover:bg-[#A3151A] text-white px-6 py-3.5 rounded-xl font-black text-sm shadow-lg shadow-red-900/10 transition-all">Confirm</button>
            <button type="button" id="hodConfirmCancel" class="w-full sm:w-auto bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 px-6 py-3.5 rounded-xl font-bold text-sm transition-colors">Cancel</button>
        </div>
    </div>
</div>

<!-- Add to Congregation Wizard (Attendance tab) -->
<div id="attAddWizardModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[92vh]">
        <div class="px-6 py-5 border-b border-gray-100 bg-red-50/60 flex justify-between items-start gap-4 shrink-0">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-hodRed mb-1">Can't find them?</p>
                <h3 id="attWizardTitle" class="text-xl md:text-2xl font-bold text-gray-900">Add to congregation</h3>
                <p id="attWizardSubtitle" class="text-sm text-gray-500 mt-1">Create their profile and clock them straight into this event.</p>
                <div id="attWizardDots" class="flex gap-2 mt-3" aria-hidden="true"></div>
            </div>
            <button type="button" onclick="attWizardClose()" class="text-gray-400 hover:text-hodRed transition-colors shrink-0" aria-label="Close">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="attWizardError" class="hidden px-6 pt-5 shrink-0" role="alert" aria-live="assertive">
            <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-2xl">
                <svg class="w-5 h-5 text-hodRed shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div class="flex-1 min-w-0">
                    <p class="text-[10px] font-black text-hodRed uppercase tracking-widest mb-1">Please check this</p>
                    <p id="attWizardErrorText" class="text-sm font-bold text-gray-900 leading-snug"></p>
                    <div id="attWizardErrorActions" class="hidden flex-wrap gap-2 mt-3"></div>
                </div>
                <button type="button" onclick="attWizardDismissError()" class="text-gray-400 hover:text-hodRed p-1 -m-1 transition-colors" aria-label="Dismiss">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        </div>
        <div id="attWizardBody" class="p-6 overflow-y-auto custom-scrollbar bg-white flex-1"></div>
        <div id="attWizardFooter" class="px-6 py-5 border-t border-gray-100 bg-gray-50/80 shrink-0 flex justify-between gap-3"></div>
    </div>
</div>

<!-- Monthly Series Modal -->
<div id="monthlyServicesModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-5 border-b border-gray-100 bg-red-50/60 flex justify-between items-start gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-red-600 mb-1">Monthly schedule</p>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900">Create Monthly Series</h3>
                <p class="text-sm text-gray-500 mt-1">Add every Sunday and Thursday service for the selected month in one click.</p>
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
                <button type="submit" id="monthlyServicesSubmit" class="w-full sm:w-auto px-5 py-3 rounded-xl font-bold text-white bg-red-600 hover:bg-red-700 shadow-md transition-all">Create Monthly Series</button>
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

    /* Events Center section navigator */
    .event-tabs-area{ width:100%; max-width:100%; min-width:0; }
    .event-tabs-shell{ display:flex; align-items:center; width:100%; max-width:100%; min-width:0; padding:6px; border:1px solid #F3F4F6; border-radius:16px; background:rgba(249,250,251,.82); }
    .event-tabs{ display:flex; flex:1 1 auto; gap:4px; min-width:0; overflow-x:auto; overflow-y:hidden; padding-bottom:3px; scroll-behavior:smooth; scroll-snap-type:x proximity; scrollbar-width:thin; scrollbar-color:#94A3B8 transparent; overscroll-behavior-x:contain; -webkit-overflow-scrolling:touch; }
    .event-tabs::-webkit-scrollbar{ width:auto; height:4px; }
    .event-tabs::-webkit-scrollbar-track{ background:transparent; }
    .event-tabs::-webkit-scrollbar-thumb{ background:#94A3B8; border-radius:999px; }
    .event-tab{ position:relative; isolation:isolate; overflow:hidden; scroll-snap-align:center; color:#6B7280; transition:transform .24s cubic-bezier(.22,1,.36,1), box-shadow .24s ease, background .24s ease, color .18s ease; }
    .event-tab:hover{ color:#111827; background:rgba(255,255,255,.75); transform:translateY(-1px); }
    .event-tab.event-tab-active{ color:#fff; background:linear-gradient(135deg, #EA2A31 0%, #D11920 58%, #B91C1C 100%); transform:translateY(-3px); box-shadow:0 9px 18px rgba(209,25,32,.28), 0 2px 4px rgba(127,29,29,.18); animation:eventTabLift .42s cubic-bezier(.22,1,.36,1) both; }
    .event-tab.event-tab-active::before{ content:''; position:absolute; z-index:0; pointer-events:none; top:1px; right:12px; left:12px; height:1px; border-radius:999px; background:rgba(255,255,255,.58); }
    .event-tab.event-tab-active::after{ content:''; position:absolute; z-index:0; pointer-events:none; inset:0; width:48%; background:linear-gradient(105deg, transparent, rgba(255,255,255,.28), transparent); transform:translateX(-180%) skewX(-18deg); animation:eventTabShine .68s .1s ease-out both; }
    .event-tab.event-tab-active:hover{ color:#fff; background:linear-gradient(135deg, #F23A40 0%, #D11920 58%, #B91C1C 100%); transform:translateY(-4px); }
    .event-tab:focus-visible, .event-tabs-control:focus-visible{ outline:3px solid rgba(209,25,32,.32); outline-offset:2px; }
    @keyframes eventTabLift{ 0%{ transform:translateY(1px) scale(.975); box-shadow:0 2px 5px rgba(209,25,32,.12); } 70%{ transform:translateY(-4px) scale(1.015); } 100%{ transform:translateY(-3px) scale(1); box-shadow:0 9px 18px rgba(209,25,32,.28), 0 2px 4px rgba(127,29,29,.18); } }
    @keyframes eventTabShine{ from{ transform:translateX(-180%) skewX(-18deg); } to{ transform:translateX(390%) skewX(-18deg); } }
    .event-tabs-control{ display:none; align-items:center; justify-content:center; flex:0 0 auto; width:30px; height:30px; margin:0 3px; border-radius:10px; color:#D11920; background:#fff; border:1px solid #E5E7EB; box-shadow:0 1px 2px rgba(0,0,0,.05); transition:opacity .15s, background .15s, color .15s; }
    .event-tabs-control:hover{ color:#fff; background:#D11920; }
    .event-tabs-shell.has-overflow .event-tabs-control{ display:inline-flex; }
    .event-tabs-shell.at-start #eventTabsPrev, .event-tabs-shell.at-end #eventTabsNext{ visibility:hidden; pointer-events:none; }
    .event-tabs-status{ display:flex; align-items:center; justify-content:flex-end; gap:6px; margin:7px 4px 0; color:#6B7280; font-size:10px; font-weight:700; letter-spacing:.01em; }
    .event-tabs-status strong{ color:#D11920; font-weight:900; }
    @media (min-width:768px){ .event-tabs-area{ width:min(100%, 610px); } }
    @media (prefers-reduced-motion:reduce){ .event-tabs{ scroll-behavior:auto; } .event-tab, .event-tab.event-tab-active{ animation:none; transition:none; } }

    /* Attendance tab — quick-search popover, KPI accordion, add-person wizard */
    #attSearchPopover{ position:absolute; top:calc(100% + 10px); left:0; right:0; z-index:70; background:#fff; border:1px solid #EBEEF3; border-radius:22px; box-shadow:0 26px 60px -14px rgba(10,14,23,.28), 0 4px 14px rgba(10,14,23,.06); max-height:min(58vh, 420px); display:flex; flex-direction:column; overflow:hidden; }
    @keyframes attPopIn{ from{ opacity:0; transform:translateY(-8px) scale(.985); } to{ opacity:1; transform:none; } }
    #attSearchPopover.att-pop-open{ animation:attPopIn .18s cubic-bezier(.22,1,.36,1) both; }
    .att-pop-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid #F3F4F6; transition:background .15s; }
    .att-pop-row:last-child{ border-bottom:0; }
    .att-pop-row:hover, .att-pop-row:focus-within{ background:#F8FAFC; }
    .att-pop-status{ display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#F1F5F9; border-radius:999px; padding:2px 8px; }

    .att-spin{ display:inline-block; width:13px; height:13px; border-radius:50%; border:2px solid #D1D5DB; border-top-color:#6B7280; animation:attSpin .7s linear infinite; flex-shrink:0; }
    .att-spin-light{ border-color:rgba(255,255,255,.4); border-top-color:#fff; }
    @keyframes attSpin{ to{ transform:rotate(360deg); } }

    /* KPI details accordion (multi-group cards) */
    .att-kpi-group-btn{ cursor:pointer; }
    .att-kpi-pill{ display:inline-flex; align-items:center; gap:5px; padding:5px 11px; border-radius:999px; background:#F3F4F6; color:#6B7280; font-size:10px; font-weight:900; letter-spacing:.07em; text-transform:uppercase; transition:background .18s, color .18s; }
    .att-kpi-group-btn:hover .att-kpi-pill, .att-kpi-group-btn[aria-expanded="true"] .att-kpi-pill{ background:#EEF2FF; color:#1D356A; }
    .att-kpi-chevron{ transition:transform .22s cubic-bezier(.22,1,.36,1); }
    .att-kpi-group-btn[aria-expanded="true"] .att-kpi-chevron{ transform:rotate(180deg); }
    @keyframes attKpiNudge{ 0%,100%{ transform:translateX(0); } 35%{ transform:translateX(3px); } 70%{ transform:translateX(-2px); } }
    .att-kpi-pill .att-kpi-chevron{ animation:attKpiNudge 1.5s ease-in-out 2; }
    @keyframes attGroupReveal{ from{ opacity:0; transform:translateY(-5px); } to{ opacity:1; transform:none; } }
    .att-group-reveal{ animation:attGroupReveal .22s ease both; }

    /* Add-to-congregation wizard */
    .att-choice-card{ display:flex; align-items:flex-start; gap:14px; width:100%; text-align:left; background:#fff; border:2px solid #EBEEF3; border-radius:20px; padding:16px 18px; cursor:pointer; transition:border-color .15s, transform .15s, box-shadow .15s; }
    .att-choice-card:hover{ border-color:#D11920; transform:translateY(-1px); box-shadow:0 12px 26px -14px rgba(209,25,32,.4); }
    .att-choice-card.att-choice-blue:hover{ border-color:#1D356A; box-shadow:0 12px 26px -14px rgba(29,53,106,.4); }
    .att-choice-card:focus-visible{ outline:3px solid rgba(209,25,32,.3); outline-offset:2px; }
    .att-field-error{ border-color:#D11920 !important; background-color:#FEF2F2 !important; }
    .att-field-msg{ margin-top:.35rem; font-size:.72rem; font-weight:700; color:#D11920; line-height:1.35; }
    @media (prefers-reduced-motion:reduce){
        #attSearchPopover.att-pop-open, .att-kpi-pill .att-kpi-chevron, .att-group-reveal{ animation:none; }
        .att-kpi-chevron, .att-kpi-pill{ transition:none; }
    }

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
    let currentAttendanceKpis = null;
    let currentAttendanceRoster = null;   // {eventId, loading, pending[], checkedIn[]} — feeds the Quick Search popover
    let attSearchPopoverOpen = false;
    let attVerifyPerson = null;           // last payload rendered in the Verify Details modal
    let attWizardState = null;            // Add-to-congregation wizard state
    let hodConfirmHandler = null;         // pending callback inside the branded confirm modal
    const CONG_API_URL = '/api/congregation_api.php';
    const ATTENDANCE_EVENT_STORAGE_KEY = 'events.attendance.selectedEventId';
    const EVENTS_ACTIVE_SECTION_STORAGE_KEY = 'events.activeSection';

    // ---------- Section switching ----------
    const EVENT_SECTIONS = ['events', 'configure', 'registrations', 'attendance', 'analytics'];
    const EVENT_SECTION_LABELS = {
        events: 'Events',
        configure: 'Configure',
        registrations: 'Registrations',
        attendance: 'Attendance',
        analytics: 'Analytics'
    };

    function updateEventTabsOverflow(){
        const tabs = document.getElementById('eventSectionTabs');
        const shell = document.getElementById('eventTabsShell');
        const prev = document.getElementById('eventTabsPrev');
        const next = document.getElementById('eventTabsNext');
        const instruction = document.getElementById('eventTabsInstruction');
        const divider = document.getElementById('eventTabsDivider');
        if(!tabs || !shell || !prev || !next) return;

        const hasOverflow = tabs.scrollWidth > tabs.clientWidth + 2;
        const maxScroll = Math.max(0, tabs.scrollWidth - tabs.clientWidth);
        const atStart = tabs.scrollLeft <= 2;
        const atEnd = tabs.scrollLeft >= maxScroll - 2;

        shell.classList.toggle('has-overflow', hasOverflow);
        shell.classList.toggle('at-start', atStart);
        shell.classList.toggle('at-end', atEnd);
        prev.disabled = !hasOverflow || atStart;
        next.disabled = !hasOverflow || atEnd;
        if(instruction) instruction.classList.toggle('hidden', !hasOverflow);
        if(divider) divider.classList.toggle('hidden', !hasOverflow);
    }

    function revealEventSectionTab(id, shouldFocus = false){
        const tab = document.getElementById('btn-' + id);
        const tabs = document.getElementById('eventSectionTabs');
        if(!tab || !tabs) return;

        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        requestAnimationFrame(() => {
            tab.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'nearest', inline: 'center' });
            if(shouldFocus){
                try { tab.focus({ preventScroll: true }); }
                catch(e) { tab.focus(); }
            }
            requestAnimationFrame(updateEventTabsOverflow);
        });
    }

    function initEventTabs(){
        const tabs = document.getElementById('eventSectionTabs');
        const prev = document.getElementById('eventTabsPrev');
        const next = document.getElementById('eventTabsNext');
        if(!tabs || !prev || !next) return;

        const scrollTabs = direction => {
            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            tabs.scrollBy({ left: direction * Math.max(180, tabs.clientWidth * .75), behavior: reduceMotion ? 'auto' : 'smooth' });
        };

        prev.addEventListener('click', () => scrollTabs(-1));
        next.addEventListener('click', () => scrollTabs(1));
        tabs.addEventListener('scroll', updateEventTabsOverflow, { passive: true });
        tabs.addEventListener('wheel', event => {
            if(Math.abs(event.deltaY) <= Math.abs(event.deltaX) || tabs.scrollWidth <= tabs.clientWidth) return;
            event.preventDefault();
            tabs.scrollLeft += event.deltaY;
        }, { passive: false });
        tabs.addEventListener('keydown', event => {
            const currentTab = event.target.closest('.event-tab');
            if(!currentTab) return;
            let index = EVENT_SECTIONS.indexOf(currentTab.dataset.section);
            if(event.key === 'ArrowRight') index = (index + 1) % EVENT_SECTIONS.length;
            else if(event.key === 'ArrowLeft') index = (index - 1 + EVENT_SECTIONS.length) % EVENT_SECTIONS.length;
            else if(event.key === 'Home') index = 0;
            else if(event.key === 'End') index = EVENT_SECTIONS.length - 1;
            else return;
            event.preventDefault();
            switchSection(EVENT_SECTIONS[index], true);
        });
        window.addEventListener('resize', updateEventTabsOverflow);
        if(window.ResizeObserver) new ResizeObserver(updateEventTabsOverflow).observe(tabs);
        updateEventTabsOverflow();
    }

    function switchSection(id, shouldFocus = false){
        if(!EVENT_SECTIONS.includes(id)) return;
        try { sessionStorage.setItem(EVENTS_ACTIVE_SECTION_STORAGE_KEY, id); } catch(e) {}
        updateEventsModuleUrl(id);
        EVENT_SECTIONS.forEach(s => {
            $('#section-' + s).addClass('hidden');
            $('#btn-' + s)
                .removeClass('event-tab-active bg-white text-hodBlue shadow-sm')
                .addClass('text-gray-500 hover:text-gray-900')
                .attr({ 'aria-selected': 'false', 'aria-current': null, tabindex: '-1' });
        });
        $('#section-' + id).removeClass('hidden').addClass('animate-fade-in-up');
        $('#btn-' + id)
            .removeClass('text-gray-500 hover:text-gray-900 bg-white text-hodBlue shadow-sm')
            .addClass('event-tab-active')
            .attr({ 'aria-selected': 'true', 'aria-current': 'page', tabindex: '0' });
        $('#eventTabsCurrent').text(EVENT_SECTION_LABELS[id]);
        revealEventSectionTab(id, shouldFocus);
        if(id === 'registrations') loadRegSelect();
        if(id === 'attendance') loadAttendanceSelect();
        if(id === 'analytics') initAnalytics();
    }

    // ---------- Helpers ----------
    function lockScreen(){ const b=$('#globalActionBlocker'); if(b.length){ b.removeClass('hidden').addClass('flex'); setTimeout(()=>b.removeClass('opacity-0'),10); } }
    function unlockScreen(){ const b=$('#globalActionBlocker'); if(b.length){ b.addClass('opacity-0'); setTimeout(()=>b.removeClass('flex').addClass('hidden'),300); } }
    function showToast(msg, type='success'){
        const bg = type === 'success' ? '#10B981' : type === 'warning' ? '#F59E0B' : type === 'info' ? '#3B82F6' : '#EF4444';
        Toastify({ text:msg, gravity:"top", position:"center", duration:3000, style:{ background: bg, borderRadius:"10px", fontWeight:"bold" } }).showToast();
    }
    function openModal(id){ const m=$('#'+id); if(!m) return; m.removeClass('hidden'); requestAnimationFrame(()=>{ m.removeClass('opacity-0'); m.children().first().removeClass('scale-95'); }); }
    function closeModal(id){ const m=$('#'+id); if(!m) return; m.addClass('opacity-0'); m.children().first().addClass('scale-95'); setTimeout(()=>m.addClass('hidden'),300); }
    const esc = s => (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function closeEventActionMenus(except = null){
        document.querySelectorAll('[data-event-action-menu]').forEach(menu => {
            if(menu !== except) menu.removeAttribute('open');
        });
    }

    function initEventActionMenus(){
        const menus = document.querySelectorAll('[data-event-action-menu]');
        menus.forEach(menu => {
            menu.addEventListener('toggle', () => {
                if(menu.open) closeEventActionMenus(menu);
            });
        });
        document.addEventListener('click', event => {
            if(!event.target.closest('[data-event-action-menu]')) closeEventActionMenus();
        });
        document.addEventListener('keydown', event => {
            if(event.key === 'Escape') closeEventActionMenus();
        });
    }

    function updateEventsModuleUrl(sectionId = null, attendanceEventId){
        if(!window.history || !window.URL) return;
        try {
            const url = new URL(window.location.href);
            if(sectionId) url.searchParams.set('section', sectionId);
            if(arguments.length > 1){
                if(attendanceEventId) url.searchParams.set('attendance_event', attendanceEventId);
                else url.searchParams.delete('attendance_event');
            }
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch(e) {}
    }

    function persistAttendanceSelection(id){
        try {
            if(id) sessionStorage.setItem(ATTENDANCE_EVENT_STORAGE_KEY, String(id));
            else sessionStorage.removeItem(ATTENDANCE_EVENT_STORAGE_KEY);
        } catch(e) {}
        updateEventsModuleUrl('attendance', id || '');
    }

    function restoreAttendanceSelection(){
        const select = $('#attendanceEventSelect');
        if(!select.length) return;
        let preferredId = '';
        let preferredSection = '';
        let urlEventId = '';
        let urlSection = '';
        try {
            const url = new URL(window.location.href);
            urlEventId = url.searchParams.get('attendance_event') || '';
            urlSection = url.searchParams.get('section') || '';
            preferredId = urlEventId;
            preferredSection = urlSection;
        } catch(e) {}
        try {
            preferredId = preferredId || sessionStorage.getItem(ATTENDANCE_EVENT_STORAGE_KEY) || '';
            preferredSection = preferredSection || sessionStorage.getItem(EVENTS_ACTIVE_SECTION_STORAGE_KEY) || '';
        } catch(e) {}

        if(!preferredId){
            if(preferredSection === 'attendance') switchSection('attendance');
            return;
        }

        const hasOption = select.find('option').filter(function(){ return this.value === String(preferredId); }).length > 0;
        if(!hasOption){
            try { sessionStorage.removeItem(ATTENDANCE_EVENT_STORAGE_KEY); } catch(e) {}
            return;
        }

        select.val(String(preferredId));
        const shouldOpenAttendance = preferredSection === 'attendance' || (!!urlEventId && !urlSection);
        if(!shouldOpenAttendance) return;
        switchSection('attendance');
    }

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
                html = `<div class="col-span-full bg-white rounded-3xl border border-gray-100 p-10 text-center text-gray-400">No events yet. Use <b>Create Event</b> to create one.</div>`;
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
            restoreAttendanceSelection();
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
                showToast(res.message || 'Could not create the monthly series.', 'error');
                return;
            }
            closeMonthlyServicesModal();
            showToast(res.message, 'success');
            loadEvents();
        }, 'json').fail(function(){
            btn.prop('disabled', false).text(original);
            unlockScreen();
            showToast('Server Error. The monthly series was not created.', 'error');
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
    function loadAttendanceSelect(){
        const select = $('#attendanceEventSelect');
        if(select.val() && $('#rosterContainer').hasClass('hidden')) select.trigger('change');
    }

    function attNum(value){ return Number(value || 0).toLocaleString(); }
    function attIsMobile(){ return window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : window.innerWidth < 768; }
    function attStatusLabel(status){ return (status || 'Unspecified').toString().replace(/_/g, ' '); }
    function attFormatTime(value){
        if(!value) return '—';
        const d = new Date(String(value).replace(' ', 'T'));
        if(Number.isNaN(d.getTime())) return value;
        return d.toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
    }

    function setAttendanceKpiSearchMode(active){
        const selected = !!$('#attendanceEventSelect').val();
        if(!selected){
            $('#attMobileKpiToggle').addClass('hidden');
            $('#attKpiContainer').addClass('hidden');
            return;
        }
        if(active && attIsMobile()){
            $('#attKpiContainer').addClass('hidden');
            $('#attMobileKpiToggle').removeClass('hidden');
        } else {
            $('#attMobileKpiToggle').addClass('hidden');
            $('#attKpiContainer').removeClass('hidden');
        }
    }

    function showAttendanceKpisFromToggle(){
        $('#rosterSearch').blur();
        setAttendanceKpiSearchMode(false);
        const el = document.getElementById('attKpiContainer');
        if(el){
            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            el.scrollIntoView({ block:'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
        }
    }

    function renderAttendanceKpis(k){
        currentAttendanceKpis = k;
        const mode = k.mode || 'workflow';
        if(mode === 'attendance'){
            const gender = k.gender || {};
            const mix = k.member_mix || {};
            $('#attKpiTotalLabel').text('Attendance');
            $('#attKpiTotal').text(attNum(k.total));
            $('#attKpiTotalSub').text('people marked present' + (k.registered ? ` · ${attNum(k.registered)} registered` : ''));

            $('#attKpiTodayLabel').text('Gender Split');
            $('#attKpiToday').html(`<span class="text-[#1D356A]">${attNum(gender.male)}</span> <span class="text-gray-300 text-2xl">/</span> <span class="text-[#D11920]">${attNum(gender.female)}</span>`);
            const unknown = Number(gender.unknown || 0);
            $('#attKpiTrendBadge')
                .attr('class', 'flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full w-fit mt-2 ' + (unknown ? 'bg-amber-100 text-amber-700' : 'bg-blue-50 text-hodBlue'))
                .html(unknown ? `${attNum(unknown)} unknown gender` : 'Male / Female');

            $('#attKpiMembersLabel').text('Members / 1st–3rd Timers');
            $('#attKpiMembers').text(attNum(mix.members));
            $('#attKpiWalkins').text(attNum(mix.timers));
            $('#attKpiMembersSub').text('church members / 1st, 2nd & 3rd timers');
            $('#attMobileKpiSummary').text(`${attNum(k.total)} present`);
            return;
        }

        $('#attKpiTotalLabel').text('Total Checked In');
        $('#attKpiTotal').text(attNum(k.total));
        $('#attKpiTotalSub').text(`${attNum(k.registered)} registered total`);
        $('#attKpiTodayLabel').text('Checked In Today');
        $('#attKpiToday').text(attNum(k.today));
        $('#attKpiMembersLabel').text('Members / Walk-ins');
        $('#attKpiMembers').text(attNum(k.members));
        $('#attKpiWalkins').text(attNum(k.walkins));
        $('#attKpiMembersSub').text('members / walk-ins');
        $('#attMobileKpiSummary').text(`${attNum(k.today || k.total)} checked in`);

        const g = Number(k.growth || 0);
        let trend = '<span>--</span>';
        let cls = 'bg-gray-100 text-gray-500';
        if(g > 0){ trend = '▲ +'+g+'%'; cls = 'bg-green-100 text-green-700'; }
        else if(g < 0){ trend = '▼ '+g+'%'; cls = 'bg-red-100 text-red-600'; }
        else { trend = 'Same as yesterday'; cls = 'bg-gray-100 text-gray-500'; }
        $('#attKpiTrendBadge').attr('class','flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full w-fit mt-2 '+cls).html(trend);
    }

    function attDetailRow(row){
        const name = row.name || `${row.first_name || ''} ${row.last_name || ''}`.trim() || 'Unnamed attendee';
        const phone = row.phone || 'No phone on file';
        const status = attStatusLabel(row.spiritual_status || row.status || 'Unspecified');
        const gender = row.gender || '—';
        const time = attFormatTime(row.marked_at || row.checked_in_at || row.check_in_time);
        return `<div class="bg-white border border-gray-100 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm">
            <div>
                <p class="font-black text-gray-900">${esc(name)}</p>
                <p class="text-xs text-gray-500 mt-1">${esc(phone)} · ${esc(status)} · ${esc(gender)}</p>
            </div>
            <span class="text-[11px] font-black uppercase tracking-wider text-hodBlue bg-blue-50 px-3 py-1.5 rounded-full w-fit">${esc(time)}</span>
        </div>`;
    }

    function attKpiGroupShell(gid, label, count, bodyHtml, expanded){
        // Accordion section for multi-group KPI cards: real <button> header with
        // aria-expanded/aria-controls so keyboard + screen reader users can
        // operate it naturally. Collapsed by default.
        return `<div class="bg-white/70 border border-gray-100 rounded-3xl overflow-hidden" id="${gid}">
            <button type="button" id="${gid}-btn" class="att-kpi-group-btn w-full px-5 py-4 bg-white flex items-center justify-between gap-3 text-left hover:bg-blue-50/40 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 transition-colors" aria-expanded="${expanded ? 'true' : 'false'}" aria-controls="${gid}-body">
                <span class="font-black text-gray-900 text-sm sm:text-base min-w-0 truncate">${esc(label)}</span>
                <span class="flex items-center gap-2.5 shrink-0">
                    <span class="text-xs font-black text-hodBlue bg-blue-50 px-3 py-1 rounded-full">${attNum(count)}</span>
                    <span class="att-kpi-pill">
                        <span class="att-kpi-pill-label">${expanded ? 'Collapse' : 'Expand'}</span>
                        <svg class="w-4 h-4 att-kpi-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m19 9-7 7-7-7"></path></svg>
                    </span>
                </span>
            </button>
            <div id="${gid}-body" role="region" aria-labelledby="${gid}-btn"${expanded ? '' : ' hidden'} class="p-4 space-y-3 bg-gray-50/40 border-t border-gray-100">
                ${bodyHtml}
            </div>
        </div>`;
    }

    function toggleAttKpiDetailGroup(gid){
        const body = document.getElementById(gid + '-body');
        const btn = document.getElementById(gid + '-btn');
        if(!body || !btn) return;
        const expand = body.hasAttribute('hidden');
        if(expand){
            body.removeAttribute('hidden');
            body.classList.add('att-group-reveal');
        } else {
            body.setAttribute('hidden', '');
            body.classList.remove('att-group-reveal');
        }
        btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
        const pillLabel = btn.querySelector('.att-kpi-pill-label');
        if(pillLabel) pillLabel.textContent = expand ? 'Collapse' : 'Expand';
    }

    function openAttKpiDetails(cardKey){
        if(!currentAttendanceKpis || !currentAttendanceKpis.details || !currentAttendanceKpis.details[cardKey]){
            showToast('Load an event first.', 'error');
            return;
        }
        const detail = currentAttendanceKpis.details[cardKey];
        $('#attKpiDetailsTitle').text(detail.title || 'KPI Details');
        $('#attKpiDetailsSub').text(detail.subtitle || 'Filtered attendance details for this event.');
        const groups = detail.groups || [];
        let html = '';

        if(groups.length > 1){
            // Multiple segments (e.g. Gender Split: Male / Female / Unknown):
            // collapse them so staff can jump straight to the group they need.
            html += `<div class="flex items-start gap-2.5 bg-blue-50/70 border border-blue-100 rounded-2xl px-4 py-3">
                <svg class="w-4 h-4 text-hodBlue mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <p class="text-xs font-bold text-hodBlue leading-relaxed">Sections are collapsed by default. Tap a section to expand.</p>
            </div>`;
            groups.forEach((group, i) => {
                const rows = group.rows || [];
                const bodyHtml = rows.length
                    ? rows.map(attDetailRow).join('')
                    : '<p class="text-center text-sm font-bold text-gray-400 py-6">No records in this segment.</p>';
                html += attKpiGroupShell(`attKpiGroup-${cardKey}-${i}`, group.label || 'Details', rows.length, bodyHtml, false);
            });
        } else {
            // Single segment: keep it simple and expanded as before.
            groups.forEach(group => {
                const rows = group.rows || [];
                html += `<div class="bg-white/70 border border-gray-100 rounded-3xl overflow-hidden">
                    <div class="px-5 py-4 bg-white border-b border-gray-100 flex items-center justify-between gap-3">
                        <h4 class="font-black text-gray-900">${esc(group.label || 'Details')}</h4>
                        <span class="text-xs font-black text-hodBlue bg-blue-50 px-3 py-1 rounded-full">${attNum(rows.length)}</span>
                    </div>
                    <div class="p-4 space-y-3">${rows.length ? rows.map(attDetailRow).join('') : '<p class="text-center text-sm font-bold text-gray-400 py-6">No records in this segment.</p>'}</div>
                </div>`;
            });
        }
        $('#attKpiDetailsBody').html(html || '<p class="text-center text-sm font-bold text-gray-400 py-10">No details available yet.</p>');
        openModal('attKpiDetailsModal');
    }

    function setAttAddPersonLink(visible){
        const link = $('#attAddPersonLink');
        if(visible) link.removeClass('hidden').addClass('inline-flex');
        else link.addClass('hidden').removeClass('inline-flex');
    }

    $('#attendanceEventSelect').on('change', function(){
        const id = $(this).val();
        currentAttendanceKpis = null;
        currentAttendanceRoster = null;
        closeAttSearchPopover();
        if(!id){
            persistAttendanceSelection('');
            $('#rosterContainer').addClass('hidden');
            $('#attKpiContainer').addClass('hidden');
            $('#attMobileKpiToggle').addClass('hidden');
            $('#rosterSearch').prop('disabled',true).val('');
            setAttAddPersonLink(false);
            return;
        }
        persistAttendanceSelection(id);
        $('#rosterSearch').prop('disabled',false);
        $('#rosterContainer').removeClass('hidden');
        setAttendanceKpiSearchMode(document.activeElement === document.getElementById('rosterSearch'));
        setAttAddPersonLink(true);
        loadRoster(id);
        loadAttKPIs(id);
    });

    function attClockTime(value){
        if(!value) return '—';
        const d = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    function renderRosterLists(){
        if(!currentAttendanceRoster || currentAttendanceRoster.loading) return;
        const id = currentAttendanceRoster.eventId;
        const pending = currentAttendanceRoster.pending || [];
        const checkedIn = currentAttendanceRoster.checkedIn || [];
        $('#pendingCount').text(pending.length);
        $('#checkedInCount').text(checkedIn.length);

        let p = '';
        pending.forEach(u => {
            const name = attPersonName(u);
            const search = `${name} ${u.phone || ''} ${u.spiritual_status || ''}`.toLowerCase();
            p += `<div class="roster-card bg-white p-4 rounded-2xl shadow-sm border border-gray-100" data-name="${esc(search)}">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 truncate">${esc(name)}</p>
                        <p class="text-[11px] text-gray-500 mt-0.5">${esc(attStatusLabel(u.spiritual_status))}${u.phone ? ' · ' + esc(u.phone) : ' · <span class="text-gray-300">No phone</span>'}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <button type="button" onclick="clockIn(event,${id},${u.id})" class="bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Clock In</button>
                        <button type="button" onclick="openVerifyDetails(${u.id})" class="bg-white text-gray-600 hover:text-hodBlue border border-gray-200 hover:border-blue-200 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Verify Details</button>
                    </div>
                </div>
            </div>`;
        });
        $('#pendingList').html(p || '<p class="text-center text-gray-400 py-4">All cleared!</p>');

        let c = '';
        checkedIn.forEach(u => {
            const name = attPersonName(u);
            const search = `${name} ${u.phone || ''} ${u.spiritual_status || ''}`.toLowerCase();
            const t = attClockTime(u.check_in_time);
            c += `<div class="roster-card bg-white p-4 rounded-2xl shadow-sm border border-gray-100" data-name="${esc(search)}">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 truncate">${esc(name)}</p>
                        <p class="text-[11px] text-gray-500 mt-0.5">${esc(attStatusLabel(u.spiritual_status))}${u.phone ? ' · ' + esc(u.phone) : ''}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-green-700 bg-green-50 border border-green-100 px-3 py-2 rounded-lg" title="Check-in time">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            ${t}
                        </span>
                        <button type="button" onclick="openVerifyDetails(${u.id})" class="bg-white text-gray-600 hover:text-hodBlue border border-gray-200 hover:border-blue-200 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Verify Details</button>
                        <button type="button" onclick="requestClockOut(event,${id},${u.id})" title="Undo clock-in" class="bg-gray-50 text-gray-500 hover:bg-red-600 hover:text-white border border-gray-100 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Clock Out</button>
                    </div>
                </div>
            </div>`;
        });
        $('#checkedInList').html(c || '<p class="text-center text-gray-400 py-4">Waiting…</p>');
        applyRosterSearch();
    }

    function loadRoster(id){
        currentAttendanceRoster = { eventId: id, loading: true, pending: [], checkedIn: [] };
        $('#pendingList').html('<div class="text-center p-4 text-gray-400">Loading…</div>');
        $('#checkedInList').html('<div class="text-center p-4 text-gray-400">Loading…</div>');
        $.post(API_URL, { action:'fetch_attendance_roster', event_id:id }, function(res){
            if(res.status !== 'success'){
                currentAttendanceRoster = { eventId: id, loading: false, pending: [], checkedIn: [] };
                $('#pendingList').html(`<p class="text-center text-red-400 py-4">${esc(res.message || 'Could not load the roster.')}</p>`);
                $('#checkedInList').html('<p class="text-center text-gray-400 py-4">—</p>');
                renderAttSearchPopover();
                return;
            }
            currentAttendanceRoster = { eventId: id, loading: false, pending: res.pending || [], checkedIn: res.checked_in || [] };
            renderRosterLists();
            renderAttSearchPopover();
        }, 'json').fail(function(){
            currentAttendanceRoster = { eventId: id, loading: false, pending: [], checkedIn: [] };
            $('#pendingList').html('<p class="text-center text-red-400 py-4">Could not load the roster. Please retry.</p>');
            $('#checkedInList').html('<p class="text-center text-gray-400 py-4">—</p>');
            renderAttSearchPopover();
        });
    }

    function loadAttKPIs(id){
        $.post(API_URL, { action:'attendance_kpis', event_id:id }, function(res){
            if(res.status !== 'success' || !res.kpis) return;
            renderAttendanceKpis(res.kpis);
            setAttendanceKpiSearchMode(document.activeElement === document.getElementById('rosterSearch'));
        },'json');
    }

    function attPersonName(p){
        return `${(p && p.first_name) || ''} ${(p && p.last_name) || ''}`.trim() || 'Unnamed';
    }

    function attFindRosterPerson(uid){
        if(!currentAttendanceRoster) return null;
        const all = (currentAttendanceRoster.pending || []).concat(currentAttendanceRoster.checkedIn || []);
        return all.find(p => Number(p.id) === Number(uid)) || null;
    }

    function attRefreshAll(){
        const id = $('#attendanceEventSelect').val();
        if(id){ loadRoster(id); loadAttKPIs(id); }
    }

    // Shared clock-in used by the roster cards, the search popover, the Verify
    // Details modal and the add-person wizard. Always refreshes roster + KPIs.
    function attClockInUser(uid, name){
        const eventId = $('#attendanceEventSelect').val();
        return new Promise(resolve => {
            if(!eventId){ showToast('Select an event first.', 'error'); resolve(null); return; }
            $.post(API_URL, { action:'mark_attendance', event_id:eventId, user_id:uid }, function(res){
                if(res.status === 'success') showToast(`${name} clocked in${res.clock_time ? ' at ' + res.clock_time : ''}.`);
                else if(res.status === 'warning') showToast(res.message, 'warning');
                else showToast(res.message || 'Could not clock in.', 'error');
                attRefreshAll();
                resolve(res);
            }, 'json').fail(function(){
                showToast('Server Error', 'error');
                attRefreshAll();
                resolve(null);
            });
        });
    }

    function attClockOutUser(uid, name){
        const eventId = $('#attendanceEventSelect').val();
        return new Promise(resolve => {
            $.post(API_URL, { action:'clock_out', event_id:eventId, user_id:uid }, function(res){
                if(res.status === 'success') showToast(`${name} moved back to pending.`);
                else if(res.status === 'warning') showToast(res.message, 'warning');
                else showToast(res.message || 'Could not clock out.', 'error');
                attRefreshAll();
                resolve(res);
            }, 'json').fail(function(){
                showToast('Server Error', 'error');
                attRefreshAll();
                resolve(null);
            });
        });
    }

    // ---- HOD-branded confirmation (no native confirm() anywhere in Attendance) ----
    function showHodConfirm(opts){
        $('#hodConfirmTitle').text(opts.title || 'Are you sure?');
        $('#hodConfirmMessage').text(opts.message || '');
        const ctx = $('#hodConfirmContext');
        if(opts.context){ ctx.text(opts.context).removeClass('hidden'); } else { ctx.addClass('hidden').text(''); }
        $('#hodConfirmOk').text(opts.confirmLabel || 'Confirm');
        $('#hodConfirmCancel').text(opts.cancelLabel || 'Cancel');
        hodConfirmHandler = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
        openModal('hodConfirmModal');
    }
    $('#hodConfirmOk').on('click', function(){
        const fn = hodConfirmHandler;
        hodConfirmHandler = null;
        closeModal('hodConfirmModal');
        if(fn) fn();
    });
    $('#hodConfirmCancel').on('click', function(){
        hodConfirmHandler = null;
        closeModal('hodConfirmModal');
    });

    function requestClockOut(ev, id, uid){
        const btn = ev && ev.currentTarget ? $(ev.currentTarget) : null;
        const person = attFindRosterPerson(uid);
        const name = person ? attPersonName(person) : 'this person';
        showHodConfirm({
            title: `Clock out ${name}?`,
            message: 'Their check-in for this event will be removed and they will move back to Pending Clock-In on the roster.',
            context: $('#attendanceEventSelect option:selected').text() || '',
            confirmLabel: 'Confirm Clock Out',
            onConfirm: function(){
                if(btn){ btn.prop('disabled', true).text('Clocking out…'); }
                attClockOutUser(uid, name);
            }
        });
    }

    function clockIn(ev, id, uid){
        const btn = ev ? ev.currentTarget : null;
        const person = attFindRosterPerson(uid);
        const name = person ? attPersonName(person) : 'Attendee';
        if(btn){
            $(btn).replaceWith('<span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-gray-400 bg-gray-50 border border-gray-100"><span class="att-spin"></span> Clocking in…</span>');
        }
        attClockInUser(uid, name);
    }

    function applyRosterSearch(){
        const v = ($('#rosterSearch').val() || '').toLowerCase();
        $('.roster-card').each(function(){ $(this).toggle(($(this).attr('data-name') || '').indexOf(v) > -1); });
    }

    // ---------- Quick Search popover (pending people only) ----------
    // The roster is already in memory (fetch_attendance_roster), so matching is
    // instant and staff can clock in several similar names without scrolling
    // between the KPI cards and the roster lists. Already-clocked-in people are
    // deliberately excluded: the popover is a pending queue.
    function openAttSearchPopover(){
        if(!$('#attendanceEventSelect').val()) return;
        attSearchPopoverOpen = true;
        renderAttSearchPopover();
    }

    function closeAttSearchPopover(){
        if(!attSearchPopoverOpen) return;
        attSearchPopoverOpen = false;
        $('#attSearchPopover').addClass('hidden').removeClass('att-pop-open');
        $('#rosterSearch').attr('aria-expanded', 'false');
    }

    function attSearchMatches(query){
        const q = (query || '').trim().toLowerCase();
        if(!q || !currentAttendanceRoster) return [];
        return (currentAttendanceRoster.pending || []).filter(u => {
            const hay = `${attPersonName(u)} ${u.phone || ''}`.toLowerCase();
            return hay.indexOf(q) > -1;
        });
    }

    function attPopRow(u){
        const name = attPersonName(u);
        return `<div class="att-pop-row" id="attPopRow-${u.id}">
            <div class="min-w-0 flex-1">
                <p class="font-black text-gray-900 truncate text-sm">${esc(name)}</p>
                <p class="text-[11px] text-gray-500 mt-1 flex items-center gap-2 flex-wrap">
                    <span class="att-pop-status">${esc(attStatusLabel(u.spiritual_status))}</span>
                    ${u.phone
                        ? `<span class="font-semibold">${esc(u.phone)}</span>`
                        : '<span class="text-gray-300 font-semibold">No phone on file</span>'}
                </p>
            </div>
            <div class="att-pop-actions flex flex-wrap items-center justify-end gap-2 shrink-0">
                <button type="button" onclick="attSearchClockIn(${u.id})" class="bg-red-50 text-red-600 hover:bg-red-600 hover:text-white px-4 py-2.5 rounded-xl text-xs font-black transition-colors">Clock In</button>
                <button type="button" onclick="openVerifyDetails(${u.id})" class="bg-gray-50 text-gray-500 hover:text-hodBlue border border-gray-100 px-3.5 py-2.5 rounded-xl text-xs font-bold transition-colors">Verify Details</button>
            </div>
        </div>`;
    }

    function attSearchClockIn(uid){
        const pending = (currentAttendanceRoster && currentAttendanceRoster.pending) || [];
        const person = pending.find(p => Number(p.id) === Number(uid));
        const name = person ? attPersonName(person) : 'Attendee';
        const row = $('#attPopRow-' + uid);
        if(row.length){
            row.find('.att-pop-actions').html('<span class="inline-flex items-center gap-2 text-xs font-black text-gray-400 px-1"><span class="att-spin"></span> Clocking in…</span>');
            row.css('opacity', '.65');
        }
        // The roster refresh re-renders the popover, which removes the person
        // from pending results once the server confirms.
        attClockInUser(uid, name);
    }

    function renderAttSearchPopover(){
        const el = $('#attSearchPopover');
        if(!attSearchPopoverOpen){ el.addClass('hidden'); return; }
        const query = $('#rosterSearch').val() || '';
        const q = query.trim().toLowerCase();
        let html = '';

        if(!currentAttendanceRoster || currentAttendanceRoster.loading){
            // During a refresh (e.g. right after a clock-in) keep the current
            // rows on screen — the clocked row already shows its loading state —
            // and only show the loader when there is nothing rendered yet.
            if(el.children().length){ el.removeClass('hidden'); return; }
            html = `<div class="px-5 py-8 text-center text-sm font-bold text-gray-400 flex flex-col items-center gap-3"><span class="att-spin"></span> Loading roster…</div>`;
        } else if(!q){
            const count = (currentAttendanceRoster.pending || []).length;
            html = `<div class="px-5 py-6 text-center">
                <p class="text-sm font-bold text-gray-500">Start typing to search people waiting to clock in.</p>
                <p class="text-[11px] text-gray-400 mt-1.5 font-semibold">${attNum(count)} pending · pending people only</p>
            </div>`;
        } else {
            const matches = attSearchMatches(query);
            if(!matches.length){
                html = `<div class="px-5 py-7 text-center">
                    <p class="text-sm font-black text-gray-800">No pending match for “${esc(query)}”</p>
                    <p class="text-[11px] text-gray-400 mt-1.5 font-semibold leading-relaxed">They may already be checked in, or not on this roster yet.</p>
                    <button type="button" onclick="attSearchCtaAdd()" class="mt-4 inline-flex items-center gap-1.5 bg-hodBlue hover:bg-[#152750] text-white text-xs font-black px-4 py-2.5 rounded-xl transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                        Can't find? Add to congregation
                    </button>
                </div>`;
            } else {
                html = `<div class="px-4 py-2.5 bg-gray-50/80 border-b border-gray-100 flex items-center justify-between shrink-0">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">${matches.length} pending match${matches.length === 1 ? '' : 'es'}</p>
                        <p class="text-[10px] font-bold text-gray-400">Pending only</p>
                    </div>
                    <div class="overflow-y-auto custom-scrollbar">${matches.map(attPopRow).join('')}</div>`;
            }
        }
        el.html(html).removeClass('hidden').addClass('att-pop-open');
        $('#rosterSearch').attr('aria-expanded', 'true');
    }

    function attSearchCtaAdd(){
        const q = $('#rosterSearch').val() || '';
        closeAttSearchPopover();
        openAttAddWizard(q);
    }

    let attKpiFocusTimer = null;
    $('#rosterSearch')
        .on('input keyup', applyRosterSearch)
        // The popover opens on real input only — never on keyup, so Escape
        // (handled on document keydown) cannot immediately re-open it.
        .on('input', function(){
            if(attSearchPopoverOpen || ($(this).val() || '').trim() !== '') renderAttSearchPopoverOpenState();
        })
        .on('focus', function(){
            clearTimeout(attKpiFocusTimer);
            setAttendanceKpiSearchMode(true);
            if(($(this).val() || '').trim() !== '') openAttSearchPopover();
        })
        .on('blur', function(){
            // KPI auto-collapse on mobile (PR #41) still applies; the popover
            // itself only closes on Escape or an outside click.
            clearTimeout(attKpiFocusTimer);
            attKpiFocusTimer = setTimeout(() => setAttendanceKpiSearchMode(false), 220);
        });
    function renderAttSearchPopoverOpenState(){
        if(!attSearchPopoverOpen) openAttSearchPopover();
        else renderAttSearchPopover();
    }
    // Escape closes the popover; a click/tap outside closes it. Focus is never
    // trapped — Tab moves through the popover rows and on down the page.
    $(document).on('keydown.attSearchPopover', function(e){
        if(e.key === 'Escape' && attSearchPopoverOpen) closeAttSearchPopover();
    });
    $(document).on('mousedown.attSearchPopover', function(e){
        if(!attSearchPopoverOpen) return;
        if($(e.target).closest('#attSearchPopover, #rosterSearch, #attAddPersonLink').length) return;
        closeAttSearchPopover();
    });
    $(window).on('resize', function(){ setAttendanceKpiSearchMode(document.activeElement === document.getElementById('rosterSearch')); });
    $('#searchClockedIn').on('keyup', function(){ const v=$(this).val().toLowerCase(); $('#clockedInTableBody tr').each(function(){ const t=$(this).text().toLowerCase(); $(this).toggle(t.indexOf(v)>-1); }); });

    // ---------- Verify Details modal ----------
    // Editable basics + attendance actions. Region / tribe / departments are shown
    // read-only because the Congregation APIs have no save path for them.
    const ATT_SPIRITUAL_OPTIONS = ['Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Member', 'Worker', 'Pastor', 'Non_Member'];
    const ATT_ATTENDANCE_OPTIONS = ['New', 'Active', 'Inconsistent', 'Unknown', 'Relocated', 'Attends_Another_Church'];

    function openVerifyDetails(uid){
        const eventId = $('#attendanceEventSelect').val();
        if(!eventId){ showToast('Select an event first.', 'error'); return; }
        attVerifyPerson = null;
        $('#attVerifyName').text('Loading…');
        $('#attVerifyBadges').html('');
        $('#attVerifyBody').html('<div class="flex flex-col items-center gap-3 py-10 text-gray-400"><span class="att-spin"></span><p class="text-sm font-bold">Loading details…</p></div>');
        $('#attVerifyActions').html('');
        openModal('attVerifyModal');
        attFetchPersonDetails(uid, function(ok){
            if(!ok) closeModal('attVerifyModal');
        });
    }

    function attFetchPersonDetails(uid, done){
        $.post(API_URL, { action:'fetch_person_details', event_id:$('#attendanceEventSelect').val(), user_id:uid }, function(res){
            if(res.status !== 'success' || !res.data){
                showToast(res.message || 'Could not load details.', 'error');
                if(done) done(false);
                return;
            }
            attVerifyPerson = res.data;
            renderVerifyDetails();
            if(done) done(true);
        }, 'json').fail(function(){
            showToast('Server Error', 'error');
            if(done) done(false);
        });
    }

    function attVerifyFieldHtml(id, label, controlHtml){
        return `<div>
            <label for="${id}" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">${label}</label>
            ${controlHtml}
        </div>`;
    }

    function attVerifyInput(id, type, value, extra){
        return `<input type="${type}" id="${id}" value="${esc(value || '')}" ${extra || ''} class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all">`;
    }

    function attVerifySelect(id, options, value, allowBlank, blankLabel){
        const opts = (allowBlank ? [`<option value="">${blankLabel || 'Not specified'}</option>`] : [])
            .concat(options.map(o => `<option value="${esc(o)}" ${o === value ? 'selected' : ''}>${esc(attStatusLabel(o))}</option>`));
        return `<select id="${id}" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all cursor-pointer">${opts.join('')}</select>`;
    }

    function renderVerifyDetails(){
        const p = attVerifyPerson;
        if(!p) return;
        $('#attVerifyName').text(attPersonName(p));

        let badges = `<span class="text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full bg-blue-50 text-hodBlue">${esc(attStatusLabel(p.spiritual_status))}</span>`;
        if(p.is_checked_in) badges += `<span class="text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full bg-green-100 text-green-700">Already Clocked In</span>`;
        $('#attVerifyBadges').html(badges);

        const churchContext = [
            p.region_name ? { label: 'Region', value: p.region_name } : null,
            p.tribe_name ? { label: 'Tribe', value: p.tribe_name } : null,
            p.departments ? { label: 'Departments', value: p.departments } : null
        ].filter(Boolean);

        let bodyHtml = '';
        if(churchContext.length){
            bodyHtml += `<div class="bg-white border border-gray-100 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2.5">Church context <span class="font-bold normal-case tracking-normal text-gray-300">(read-only — managed in Congregation Data)</span></p>
                <div class="flex flex-wrap gap-2">
                    ${churchContext.map(c => `<span class="text-[11px] font-bold bg-gray-50 border border-gray-100 text-gray-600 px-3 py-1.5 rounded-full"><span class="text-gray-400">${esc(c.label)}:</span> ${esc(c.value)}</span>`).join('')}
                </div>
            </div>`;
        }

        bodyHtml += `<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-white border border-gray-100 rounded-2xl p-4">
            ${attVerifyFieldHtml('attVfFirst', 'First name *', attVerifyInput('attVfFirst', 'text', p.first_name, 'maxlength="50" autocomplete="off"'))}
            ${attVerifyFieldHtml('attVfLast', 'Last name *', attVerifyInput('attVfLast', 'text', p.last_name, 'maxlength="50" autocomplete="off"'))}
            ${attVerifyFieldHtml('attVfPhone', 'Phone', attVerifyInput('attVfPhone', 'tel', p.phone, 'maxlength="25" inputmode="tel"'))}
            ${attVerifyFieldHtml('attVfGender', 'Gender', attVerifySelect('attVfGender', ['Male', 'Female'], p.gender, true, 'Not specified'))}
            ${attVerifyFieldHtml('attVfSpiritual', 'Spiritual status', attVerifySelect('attVfSpiritual', ATT_SPIRITUAL_OPTIONS, p.spiritual_status, false))}
            ${attVerifyFieldHtml('attVfAttendance', 'Attendance status', attVerifySelect('attVfAttendance', ATT_ATTENDANCE_OPTIONS, p.attendance_status, false))}
        </div>`;

        if(p.is_checked_in && p.check_in_time){
            const by = p.checked_in_by_first ? ` by ${esc(attPersonName({ first_name: p.checked_in_by_first, last_name: p.checked_in_by_last }))}` : '';
            bodyHtml += `<p class="text-[11px] text-gray-400 font-bold text-center">Clocked in ${esc(attFormatTime(p.check_in_time))}${by}</p>`;
        }

        $('#attVerifyBody').html(bodyHtml);
        renderVerifyActions();
    }

    function renderVerifyActions(){
        const p = attVerifyPerson;
        if(!p) return;
        const saveBtn = `<button type="button" id="attVfSaveBtn" onclick="attVerifySave()" class="w-full sm:w-auto bg-hodBlue hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold text-sm shadow-md transition-all">Save Details</button>`;
        let right = '';
        if(p.is_checked_in){
            right = `<div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                <span class="inline-flex items-center justify-center gap-1.5 text-[11px] font-black uppercase tracking-wider text-green-700 bg-green-50 border border-green-100 px-3 py-2.5 rounded-xl">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    Already Clocked In
                </span>
                <button type="button" onclick="attVerifyMaintain()" class="bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 px-4 py-3 rounded-xl font-bold text-sm transition-colors">Maintain Clock-In</button>
                <button type="button" onclick="attVerifyClockOut()" class="bg-white hover:bg-red-50 text-red-600 border border-red-100 px-4 py-3 rounded-xl font-bold text-sm transition-colors">Clock Out</button>
            </div>`;
        } else {
            right = `<button type="button" id="attVfClockInBtn" onclick="attVerifyClockIn()" class="w-full sm:w-auto bg-hodRed hover:bg-[#A3151A] text-white px-5 py-3 rounded-xl font-black text-sm shadow-md transition-all">Clock In</button>`;
        }
        $('#attVerifyActions').html(`<div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 w-full">${saveBtn}${right}</div>`);
    }

    function attVerifySave(){
        const p = attVerifyPerson;
        if(!p) return;
        const first = ($('#attVfFirst').val() || '').trim();
        const last = ($('#attVfLast').val() || '').trim();
        const bad = !first ? $('#attVfFirst') : (!last ? $('#attVfLast') : null);
        if(bad){ bad.addClass('att-field-error').focus(); showToast('First and last name are required.', 'error'); return; }
        $('#attVfFirst, #attVfLast').removeClass('att-field-error');

        const btn = $('#attVfSaveBtn');
        btn.prop('disabled', true).text('Saving…');
        $.post(API_URL, {
            action: 'update_person_details',
            user_id: p.id,
            first_name: first,
            last_name: last,
            phone: ($('#attVfPhone').val() || '').trim(),
            gender: $('#attVfGender').val() || '',
            spiritual_status: $('#attVfSpiritual').val() || '',
            attendance_status: $('#attVfAttendance').val() || ''
        }, function(res){
            btn.prop('disabled', false).text('Save Details');
            if(res.status !== 'success'){ showToast(res.message || 'Could not save.', 'error'); return; }
            showToast('Details saved.');
            p.first_name = first;
            p.last_name = last;
            p.phone = ($('#attVfPhone').val() || '').trim();
            p.gender = $('#attVfGender').val() || '';
            p.spiritual_status = $('#attVfSpiritual').val() || '';
            p.attendance_status = $('#attVfAttendance').val() || '';
            renderVerifyDetails();
            attRefreshAll();
        }, 'json').fail(function(){
            btn.prop('disabled', false).text('Save Details');
            showToast('Server Error', 'error');
        });
    }

    function attVerifyClockIn(){
        const p = attVerifyPerson;
        if(!p) return;
        const btn = $('#attVfClockInBtn');
        if(btn.length){
            btn.prop('disabled', true).html('<span class="inline-flex items-center gap-2"><span class="att-spin att-spin-light"></span> Clocking in…</span>');
        }
        attClockInUser(p.id, attPersonName(p)).then(function(){
            attFetchPersonDetails(p.id); // flip the modal into its checked-in state
        });
    }

    function attVerifyMaintain(){
        closeModal('attVerifyModal');
    }

    function attVerifyClockOut(){
        const p = attVerifyPerson;
        if(!p) return;
        showHodConfirm({
            title: `Clock out ${attPersonName(p)}?`,
            message: 'Their check-in for this event will be removed and they will move back to Pending Clock-In on the roster.',
            context: $('#attendanceEventSelect option:selected').text() || '',
            confirmLabel: 'Confirm Clock Out',
            onConfirm: function(){
                attClockOutUser(p.id, attPersonName(p)).then(function(){
                    attFetchPersonDetails(p.id); // flip the modal back to pending
                });
            }
        });
    }

    // ---------- "Can't find? Add to congregation" wizard ----------
    // Two paths from one quick Sunday-service flow:
    //   A. First Timer — QR of the public "I'm New Here" form, or a
    //      staff-assisted form with the same payload as the public card.
    //   B. Congregation Member — the full Congregation create-member payload.
    // Both paths clock the person into the selected event immediately after
    // the profile is created, without leaving the Attendance tab.
    function attWizardDefaults(){
        return {
            step: 'type',            // type -> mode -> basic -> profile -> review -> success
            type: null,              // 'first_timer' | 'member'
            mode: null,              // first_timer only: 'qr' | 'staff'
            duplicate: null,         // person found by the phone pre-check
            busy: false,
            data: {
                first_name: '', last_name: '', phone: '', gender: '',
                email: '', dob: '', marital_status: 'Single', wedding_anniversary: '',
                physical_address: '',
                invited_by: '', prayer_requests: '', wants_to_join: false, wants_visitation: false,
                spiritual_status: 'Member', attendance_status: 'New', comments: ''
            }
        };
    }

    function openAttAddWizard(prefillName){
        const eventId = $('#attendanceEventSelect').val();
        if(!eventId){
            showToast('Select an event first — they will be clocked into it automatically.', 'error');
            return;
        }
        attWizardState = attWizardDefaults();
        if(prefillName){
            const tokens = String(prefillName).trim().split(/\s+/).filter(Boolean);
            attWizardState.data.first_name = tokens[0] || '';
            attWizardState.data.last_name = tokens.slice(1).join(' ');
        }
        $('#attWizardError').addClass('hidden');
        attWizardRender();
        openModal('attAddWizardModal');
    }

    function attWizardClose(){
        closeModal('attAddWizardModal');
        attWizardState = null;
    }

    function attWizardError(message, existingUserId){
        $('#attWizardErrorText').text(message || 'Something went wrong. Please try again.');
        const actions = $('#attWizardErrorActions');
        if(existingUserId){
            actions.removeClass('hidden').addClass('flex').html(
                `<button type="button" onclick="attWizardClockInExisting(${Number(existingUserId) || 0})" class="bg-hodRed hover:bg-[#A3151A] text-white px-4 py-2 rounded-lg font-black uppercase tracking-wider text-[10px] shadow-sm transition-colors">Clock that profile in</button>`
            );
        } else {
            actions.addClass('hidden').removeClass('flex').html('');
        }
        $('#attWizardError').removeClass('hidden');
        $('#attWizardBody').scrollTop(0);
    }

    function attWizardDismissError(){
        $('#attWizardError').addClass('hidden');
        $('#attWizardErrorActions').addClass('hidden').removeClass('flex').html('');
    }

    function attWizardClockInExisting(uid){
        if(!uid) return;
        const btn = $('#attWizardErrorActions button');
        if(btn.length) btn.prop('disabled', true).text('Clocking in…');
        attClockInUser(uid, 'Existing profile').then(function(res){
            closeModal('attAddWizardModal');
            attWizardState = null;
        });
    }

    function attWizardGo(step){
        const w = attWizardState;
        if(!w) return;
        w.step = step;
        attWizardDismissError();
        attWizardRender();
        $('#attWizardBody').scrollTop(0);
    }

    function attWizardChooseType(type){
        const w = attWizardState;
        if(!w) return;
        w.type = type;
        attWizardGo(type === 'first_timer' ? 'mode' : 'basic');
    }

    function attWizardChooseMode(mode){
        const w = attWizardState;
        if(!w) return;
        w.mode = mode;
        attWizardGo(mode === 'qr' ? 'qr' : 'basic');
    }

    function attWizardRenderDots(){
        const w = attWizardState;
        const map = { type: 0, mode: 0, basic: 1, profile: 2, review: 3 };
        const idx = map[w.step];
        let html = '';
        if(idx !== undefined){
            for(let i = 0; i < 4; i++){
                html += `<div class="h-1.5 w-8 rounded-full ${i <= idx ? 'bg-hodRed' : 'bg-gray-200'} transition-all"></div>`;
            }
        }
        $('#attWizardDots').html(html);
    }

    // ---- field builders ----
    function attWizText(id, value, type, attrs){
        return `<input type="${type || 'text'}" id="${id}" value="${esc(value || '')}" ${attrs || ''} class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all">`;
    }
    function attWizSelect(id, options, value, allowBlank, blankLabel){
        const opts = (allowBlank ? [`<option value="">${blankLabel || 'Not specified'}</option>`] : [])
            .concat(options.map(o => `<option value="${esc(o)}" ${o === value ? 'selected' : ''}>${esc(attStatusLabel(o))}</option>`));
        return `<select id="${id}" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all cursor-pointer">${opts.join('')}</select>`;
    }
    function attWizField(id, label, control, required, hint){
        return `<div>
            <label for="${id}" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">${label}${required ? ' *' : ''}</label>
            ${control}
            ${hint ? `<p class="text-[10px] text-gray-400 font-semibold mt-1">${hint}</p>` : ''}
        </div>`;
    }
    function attWizSetError(inputId, message){
        const input = $('#' + inputId);
        if(!input.length) return;
        if(message){
            input.addClass('att-field-error').attr('aria-invalid', 'true');
            let msg = input.siblings('.att-field-msg');
            if(!msg.length) msg = $('<p class="att-field-msg"></p>').insertAfter(input);
            msg.text(message);
        } else {
            input.removeClass('att-field-error').removeAttr('aria-invalid');
            input.siblings('.att-field-msg').remove();
        }
    }

    function attWizardCollect(){
        const w = attWizardState;
        if(!w) return;
        const d = w.data;
        if(w.step === 'basic'){
            d.first_name = ($('#attWizFirst').val() || '').trim();
            d.last_name = ($('#attWizLast').val() || '').trim();
            d.phone = ($('#attWizPhone').val() || '').trim();
            d.gender = $('#attWizGender').val() || '';
        } else if(w.step === 'profile'){
            d.email = ($('#attWizEmail').val() || '').trim();
            d.dob = $('#attWizDob').val() || '';
            d.marital_status = $('#attWizMarital').val() || 'Single';
            d.wedding_anniversary = $('#attWizAnniversary').val() || '';
            d.physical_address = ($('#attWizAddress').val() || '').trim();
            if(w.type === 'first_timer'){
                d.invited_by = ($('#attWizInvitedBy').val() || '').trim();
                d.prayer_requests = ($('#attWizPrayer').val() || '').trim();
                d.wants_to_join = $('#attWizJoin').is(':checked');
                d.wants_visitation = $('#attWizVisitation').is(':checked');
            } else {
                d.spiritual_status = $('#attWizSpiritual').val() || 'Member';
                d.attendance_status = $('#attWizAttendance').val() || 'New';
                d.comments = ($('#attWizComments').val() || '').trim();
            }
        }
    }

    function attWizardValidateBasic(){
        const w = attWizardState;
        const d = w.data;
        let ok = true;
        if(!d.first_name){ attWizSetError('attWizFirst', 'First name is required.'); ok = false; } else attWizSetError('attWizFirst', null);
        if(!d.last_name){ attWizSetError('attWizLast', 'Last name is required.'); ok = false; } else attWizSetError('attWizLast', null);
        const digits = (d.phone.match(/\d/g) || []).join('');
        if(!d.phone){ attWizSetError('attWizPhone', 'Phone number is required — it prevents duplicate profiles.'); ok = false; }
        else if(digits.length < 9 || digits.length > 15){ attWizSetError('attWizPhone', 'Enter a valid phone number (9 to 15 digits).'); ok = false; }
        else attWizSetError('attWizPhone', null);
        return ok;
    }

    function attWizardNext(){
        const w = attWizardState;
        if(!w || w.busy) return;
        attWizardCollect();
        if(w.step === 'basic'){
            if(!attWizardValidateBasic()) return;
            // Duplicate-phone pre-check (both paths create a profile). If the
            // number already exists we surface the person so staff can clock
            // them in instead of creating a second profile.
            w.busy = true;
            const btn = $('#attWizNextBtn').prop('disabled', true).text('Checking…');
            $.post(API_URL, { action:'check_attendee_phone', phone:w.data.phone }, function(res){
                w.busy = false;
                btn.prop('disabled', false).text('Next');
                if(res.status === 'success' && res.found && res.person){
                    w.duplicate = res.person;
                    attWizardShowDuplicate();
                    return;
                }
                w.duplicate = null;
                attWizardGo('profile');
            }, 'json').fail(function(){
                w.busy = false;
                btn.prop('disabled', false).text('Next');
                attWizardGo('profile'); // never block the flow on a failed pre-check
            });
            return;
        }
        if(w.step === 'profile'){
            attWizardCollect();
            if(w.data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(w.data.email)){
                attWizSetError('attWizEmail', 'That email doesn\'t look right. Please check it, or leave it blank.');
                return;
            }
            attWizSetError('attWizEmail', null);
            attWizardGo('review');
            return;
        }
    }

    function attWizardBack(){
        const w = attWizardState;
        if(!w) return;
        attWizardCollect();
        if(w.step === 'review') attWizardGo('profile');
        else if(w.step === 'profile') attWizardGo('basic');
        else if(w.step === 'basic') attWizardGo(w.type === 'first_timer' ? 'mode' : 'type');
        else if(w.step === 'mode' || w.step === 'qr') attWizardGo('type');
    }

    function attWizardShowDuplicate(){
        const w = attWizardState;
        if(!w || !w.duplicate) return;
        const p = w.duplicate;
        const panel = $('#attWizDupPanel');
        panel.removeClass('hidden').html(`
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">This phone is already in the database</p>
                <p class="text-sm font-bold text-gray-900 mt-1.5">${esc(attPersonName(p))} <span class="text-gray-400 font-semibold">· ${esc(attStatusLabel(p.spiritual_status))}</span></p>
                <p class="text-xs text-gray-500 font-semibold mt-1 leading-relaxed">Change the phone number above to create a new profile, or clock the existing one into this event now.</p>
                <button type="button" onclick="attWizardClockInDuplicate()" class="mt-3 inline-flex items-center gap-1.5 bg-hodRed hover:bg-[#A3151A] text-white px-4 py-2.5 rounded-xl text-xs font-black transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Clock In ${esc(p.first_name || 'them')} (existing profile)
                </button>
            </div>`);
        panel[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function attWizardClockInDuplicate(){
        const w = attWizardState;
        if(!w || !w.duplicate) return;
        const p = w.duplicate;
        const btn = $('#attWizDupPanel button');
        if(btn.length) btn.prop('disabled', true).html('<span class="inline-flex items-center gap-2"><span class="att-spin att-spin-light"></span> Clocking in…</span>');
        attClockInUser(p.id, attPersonName(p)).then(function(res){
            attWizardRenderSuccess(attPersonName(p), res, true);
        });
    }

    function attWizardRender(){
        const w = attWizardState;
        if(!w) return;
        attWizardRenderDots();
        const body = $('#attWizardBody');
        const footer = $('#attWizardFooter');
        let footerHtml = '';
        const cancelBtn = `<button type="button" onclick="attWizardClose()" class="ml-auto bg-white hover:bg-gray-100 text-gray-500 border border-gray-200 px-5 py-3 rounded-xl font-bold text-sm transition-colors">Cancel</button>`;
        const backBtn = `<button type="button" onclick="attWizardBack()" class="bg-white hover:bg-gray-100 text-gray-700 border border-gray-200 px-5 py-3 rounded-xl font-bold text-sm transition-colors shadow-sm">Back</button>`;

        if(w.step === 'type'){
            $('#attWizardTitle').text('Add to congregation');
            $('#attWizardSubtitle').text('Create their profile and clock them straight into this event.');
            body.html(`<div class="space-y-4">
                <button type="button" onclick="attWizardChooseType('first_timer')" class="att-choice-card">
                    <span class="w-11 h-11 rounded-2xl bg-red-50 text-hodRed flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-black text-gray-900">First Timer</span>
                        <span class="block text-xs text-gray-500 font-semibold mt-1 leading-relaxed">A guest worshipping with us. Defaults to 1st Timer status and enters the Embrace follow-up flow.</span>
                    </span>
                    <svg class="w-5 h-5 text-gray-300 ml-auto shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m9 5 7 7-7 7"></path></svg>
                </button>
                <button type="button" onclick="attWizardChooseType('member')" class="att-choice-card att-choice-blue">
                    <span class="w-11 h-11 rounded-2xl bg-blue-50 text-hodBlue flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-black text-gray-900">Congregation Member</span>
                        <span class="block text-xs text-gray-500 font-semibold mt-1 leading-relaxed">A known member of the house. Full profile with their spiritual & attendance status.</span>
                    </span>
                    <svg class="w-5 h-5 text-gray-300 ml-auto shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m9 5 7 7-7 7"></path></svg>
                </button>
            </div>`);
            footerHtml = cancelBtn;
        }

        else if(w.step === 'mode'){
            $('#attWizardTitle').text('Adding a first timer');
            $('#attWizardSubtitle').text('How would you like to capture their details?');
            body.html(`<div class="space-y-4">
                <button type="button" onclick="attWizardChooseMode('qr')" class="att-choice-card">
                    <span class="w-11 h-11 rounded-2xl bg-gray-100 text-gray-700 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h6v6H3V3zm12 0h6v6h-6V3zM3 15h6v6H3v-6zm12 0h2v2h-2v-2zm4 0h2v6h-6v-2h4v-4zm-2 4h2v2h-2v-2zM13 15v-2h-2v-2h4v4h-2zm-6 0H7v2H5v-4h2v2z"></path></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-black text-gray-900">They can fill it themselves</span>
                        <span class="block text-xs text-gray-500 font-semibold mt-1 leading-relaxed">Show a QR code for the "I'm New Here" Connect form and let them complete it on their phone.</span>
                    </span>
                    <svg class="w-5 h-5 text-gray-300 ml-auto shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m9 5 7 7-7 7"></path></svg>
                </button>
                <button type="button" onclick="attWizardChooseMode('staff')" class="att-choice-card att-choice-blue">
                    <span class="w-11 h-11 rounded-2xl bg-blue-50 text-hodBlue flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-black text-gray-900">Staff-assisted</span>
                        <span class="block text-xs text-gray-500 font-semibold mt-1 leading-relaxed">Fill the Connect card with them now — same details as the public form.</span>
                    </span>
                    <svg class="w-5 h-5 text-gray-300 ml-auto shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m9 5 7 7-7 7"></path></svg>
                </button>
            </div>`);
            footerHtml = backBtn + cancelBtn;
        }

        else if(w.step === 'qr'){
            $('#attWizardTitle').text('I\'m New Here — QR');
            $('#attWizardSubtitle').text('Let them fill the Connect form on their own phone.');
            body.html(`<div class="text-center">
                <div id="attWizQrHost" class="mx-auto w-fit p-4 bg-white border-2 border-gray-100 rounded-3xl shadow-sm"></div>
                <p class="font-black text-gray-900 mt-5">Scan to open the "I'm New Here" form</p>
                <p class="text-xs text-gray-500 font-semibold mt-2 leading-relaxed max-w-md mx-auto">When they submit, their 1st Timer profile is created automatically. Come back here and search their name to clock them in. The form itself doesn't link to this event — attendance is clocked from this tab.</p>
                <div class="mt-4">
                    <input type="text" id="attWizQrUrl" readonly class="w-full max-w-sm mx-auto px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-500 text-center outline-none">
                </div>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <button type="button" onclick="attWizCopyUrl()" class="bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Copy link</button>
                    <button type="button" onclick="attWizOpenForm()" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-xs font-black transition-colors">Open form on this device</button>
                    <button type="button" onclick="attWizardChooseMode('staff')" class="bg-white hover:bg-red-50 text-hodRed border border-red-100 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Switch to staff-assisted</button>
                </div>
            </div>`);
            attWizardBuildQr();
            footerHtml = backBtn + cancelBtn;
        }

        else if(w.step === 'basic'){
            $('#attWizardTitle').text('Basic details');
            $('#attWizardSubtitle').text(w.type === 'member' ? 'Who are we adding to the congregation?' : 'Who is joining us today?');
            const d = w.data;
            body.html(`<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                ${attWizField('attWizFirst', 'First name', attWizText('attWizFirst', d.first_name, 'text', 'maxlength="50" autocomplete="off"'), true)}
                ${attWizField('attWizLast', 'Last name', attWizText('attWizLast', d.last_name, 'text', 'maxlength="50" autocomplete="off"'), true)}
                ${attWizField('attWizPhone', 'Phone', attWizText('attWizPhone', d.phone, 'tel', 'maxlength="25" inputmode="tel" autocomplete="off"'), true, 'Used to prevent duplicate profiles.')}
                ${attWizField('attWizGender', 'Gender', attWizSelect('attWizGender', ['Male', 'Female'], d.gender, true, 'Not specified'), false)}
            </div>
            <div id="attWizDupPanel" class="hidden mt-4"></div>`);
            if(w.duplicate) attWizardShowDuplicate();
            // Editing the phone invalidates the previous duplicate finding.
            $('#attWizPhone').on('input', function(){
                if(w.duplicate){ w.duplicate = null; $('#attWizDupPanel').addClass('hidden'); }
            });
            footerHtml = backBtn + `<button type="button" id="attWizNextBtn" onclick="attWizardNext()" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-black text-sm shadow-md transition-all">Next</button>`;
        }

        else if(w.step === 'profile'){
            const d = w.data;
            if(w.type === 'first_timer'){
                $('#attWizardTitle').text('A little more (optional)');
                $('#attWizardSubtitle').text('Same details as the public "I\'m New Here" card — skip anything you don\'t have.');
                body.html(`<div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        ${attWizField('attWizEmail', 'Personal email', attWizText('attWizEmail', d.email, 'email', 'maxlength="100" inputmode="email" autocomplete="off"'), false)}
                        ${attWizField('attWizDob', 'Date of birth', attWizText('attWizDob', d.dob, 'date', 'max="' + new Date().toISOString().slice(0, 10) + '"'), false)}
                        ${attWizField('attWizMarital', 'Marital status', attWizSelect('attWizMarital', ['Single', 'Married', 'Separated', 'Divorced'], d.marital_status, false), false)}
                        ${attWizField('attWizAddress', 'Where do they live?', attWizText('attWizAddress', d.physical_address, 'text', 'maxlength="255" autocomplete="off"'), false)}
                        ${attWizField('attWizInvitedBy', 'Who invited them?', attWizText('attWizInvitedBy', d.invited_by, 'text', 'maxlength="150" autocomplete="off"'), false)}
                    </div>
                    <div class="space-y-2.5 pt-1">
                        <label class="flex items-center gap-3.5 p-4 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-white hover:border-blue-200 transition-colors group">
                            <input type="checkbox" id="attWizJoin" ${d.wants_to_join ? 'checked' : ''} class="w-5 h-5 rounded border-gray-300 text-hodRed focus:ring-hodRed cursor-pointer">
                            <span class="font-bold text-gray-800 text-sm group-hover:text-hodBlue transition-colors">They are looking to make HOD their home church.</span>
                        </label>
                        <label class="flex items-center gap-3.5 p-4 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-white hover:border-blue-200 transition-colors group">
                            <input type="checkbox" id="attWizVisitation" ${d.wants_visitation ? 'checked' : ''} class="w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                            <span class="font-bold text-gray-800 text-sm group-hover:text-hodBlue transition-colors">They would like a Pastor or minister to call/visit.</span>
                        </label>
                    </div>
                    <div>
                        <label for="attWizPrayer" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">How can we pray for them?</label>
                        <textarea id="attWizPrayer" rows="3" maxlength="2000" placeholder="Optional — the Zoe Intercessory team is ready to agree…" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all resize-none">${esc(d.prayer_requests)}</textarea>
                    </div>
                </div>`);
            } else {
                $('#attWizardTitle').text('Church & profile details');
                $('#attWizardSubtitle').text('Their statuses in the house — same fields as Congregation Data.');
                body.html(`<div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        ${attWizField('attWizSpiritual', 'Spiritual status', attWizSelect('attWizSpiritual', ATT_SPIRITUAL_OPTIONS, d.spiritual_status, false), true)}
                        ${attWizField('attWizAttendance', 'Attendance status', attWizSelect('attWizAttendance', ATT_ATTENDANCE_OPTIONS, d.attendance_status, false), true)}
                        ${attWizField('attWizEmail', 'Personal email', attWizText('attWizEmail', d.email, 'email', 'maxlength="100" inputmode="email" autocomplete="off"'), false, 'A church email is auto-generated if left blank.')}
                        ${attWizField('attWizDob', 'Date of birth', attWizText('attWizDob', d.dob, 'date', 'max="' + new Date().toISOString().slice(0, 10) + '"'), false)}
                        ${attWizField('attWizMarital', 'Marital status', attWizSelect('attWizMarital', ['Single', 'Married', 'Separated', 'Divorced'], d.marital_status, false), false)}
                        <div id="attWizAnniversaryWrap" class="${d.marital_status === 'Married' ? '' : 'hidden'}">
                            ${attWizField('attWizAnniversary', 'Wedding anniversary', attWizText('attWizAnniversary', d.wedding_anniversary, 'date'), false)}
                        </div>
                        ${attWizField('attWizAddress', 'Physical address', attWizText('attWizAddress', d.physical_address, 'text', 'maxlength="255" autocomplete="off"'), false)}
                    </div>
                    <div>
                        <label for="attWizComments" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Pastoral comments / notes</label>
                        <textarea id="attWizComments" rows="2" maxlength="1000" placeholder="Optional welfare notes, family details, etc." class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl font-bold text-gray-900 text-sm outline-none focus:border-hodBlue focus:ring-2 focus:ring-blue-100 transition-all resize-none">${esc(d.comments)}</textarea>
                    </div>
                </div>`);
                $('#attWizMarital').on('change', function(){
                    $('#attWizAnniversaryWrap').toggleClass('hidden', $(this).val() !== 'Married');
                });
            }
            footerHtml = backBtn + `<button type="button" id="attWizNextBtn" onclick="attWizardNext()" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-black text-sm shadow-md transition-all">Next</button>`;
        }

        else if(w.step === 'review'){
            const d = w.data;
            $('#attWizardTitle').text('Review & add');
            $('#attWizardSubtitle').text('Double-check, then we\'ll clock them straight in.');
            const row = (label, value) => `<div class="flex justify-between gap-4 py-2 border-b border-gray-50">
                <span class="text-[11px] font-black uppercase tracking-wider text-gray-400 shrink-0 pt-0.5">${label}</span>
                <span class="text-sm font-bold text-gray-900 text-right min-w-0 break-words">${value ? esc(value) : '<span class="text-gray-300">Not provided</span>'}</span>
            </div>`;
            let rows = row('Name', `${d.first_name} ${d.last_name}`.trim()) + row('Phone', d.phone) + row('Gender', d.gender || '') + row('Email', d.email) + row('DOB', d.dob) + row('Marital', d.marital_status) + row('Address', d.physical_address);
            if(w.type === 'first_timer'){
                rows += row('Invited by', d.invited_by)
                    + row('Wants to join HOD', d.wants_to_join ? 'Yes' : '')
                    + row('Wants visitation', d.wants_visitation ? 'Yes' : '')
                    + row('Prayer request', d.prayer_requests ? 'Provided' : '');
            } else {
                rows += row('Spiritual status', attStatusLabel(d.spiritual_status)) + row('Attendance status', attStatusLabel(d.attendance_status)) + row('Notes', d.comments);
            }
            body.html(`<div class="space-y-4">
                <div class="bg-white border border-gray-100 rounded-2xl p-4 divide-y divide-gray-50">${rows}</div>
                <div class="bg-blue-50/70 border border-blue-100 rounded-2xl px-4 py-3.5 flex items-start gap-2.5">
                    <svg class="w-5 h-5 text-hodBlue mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p class="text-xs font-bold text-hodBlue leading-relaxed">On confirm, their profile is created and they are clocked into <span class="font-black">${esc($('#attendanceEventSelect option:selected').text() || 'the selected event')}</span> immediately.</p>
                </div>
            </div>`);
            footerHtml = backBtn + `<button type="button" id="attWizSubmitBtn" onclick="attWizardSubmit()" class="bg-hodRed hover:bg-[#A3151A] text-white px-6 py-3 rounded-xl font-black text-sm shadow-md transition-all">Add & Clock In</button>`;
        }

        footer.html(footerHtml).toggleClass('hidden', footerHtml === '');
    }

    function attWizardBuildQr(){
        // The public Connect form (/connect.php) takes no event context parameter —
        // it always creates a plain 1st Timer profile — so we link it plainly
        // rather than inventing a token the form does not support.
        const url = window.location.origin + '/connect.php';
        const host = document.getElementById('attWizQrHost');
        if(!host) return;
        host.innerHTML = '';
        $('#attWizQrUrl').val(url);
        if(typeof QRCode !== 'undefined'){
            new QRCode(host, { text: url, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.H });
        } else {
            host.innerHTML = `<p class="text-xs font-bold text-gray-500 break-all max-w-[190px] text-center leading-relaxed">${esc(url)}</p>`;
        }
    }

    function attWizCopyUrl(){
        const url = $('#attWizQrUrl').val() || (window.location.origin + '/connect.php');
        const fallback = () => {
            const input = document.getElementById('attWizQrUrl');
            if(input){ input.focus(); input.select(); try { document.execCommand('copy'); showToast('Link copied.'); } catch(e){ showToast('Select the link and copy it manually.', 'warning'); } }
        };
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(url).then(() => showToast('Link copied.')).catch(fallback);
        } else fallback();
    }

    function attWizOpenForm(){
        window.open((window.location.origin + '/connect.php'), '_blank', 'noopener');
    }

    function attWizardSubmit(){
        const w = attWizardState;
        if(!w || w.busy) return;
        w.busy = true;
        const btn = $('#attWizSubmitBtn');
        btn.prop('disabled', true).html('<span class="inline-flex items-center gap-2"><span class="att-spin att-spin-light"></span> Adding & clocking in…</span>');
        const d = w.data;
        const shared = {
            first_name: d.first_name, last_name: d.last_name, phone: d.phone,
            gender: d.gender, marital_status: d.marital_status, dob: d.dob,
            physical_address: d.physical_address
        };
        if(w.type === 'first_timer'){
            $.post(API_URL, Object.assign({
                action: 'create_first_timer',
                email: d.email,
                invited_by: d.invited_by,
                prayer_requests: d.prayer_requests,
                wants_to_join: d.wants_to_join ? '1' : '0',
                wants_visitation: d.wants_visitation ? '1' : '0'
            }, shared), attWizardCreated, 'json').fail(attWizardSubmitFailed);
        } else {
            // Reuses the Congregation module's create action (auto church email,
            // QR hash, IDI notification) — it now returns the new user_id.
            $.post(CONG_API_URL, Object.assign({
                action: 'create_member',
                email: '',
                real_email: d.email,
                wedding_anniversary: d.marital_status === 'Married' ? d.wedding_anniversary : '',
                spiritual_status: d.spiritual_status,
                attendance_status: d.attendance_status,
                comments: d.comments
            }, shared), attWizardCreated, 'json').fail(attWizardSubmitFailed);
        }
    }

    function attWizardSubmitFailed(){
        const w = attWizardState;
        if(!w) return;
        w.busy = false;
        const btn = $('#attWizSubmitBtn');
        if(btn.length) btn.prop('disabled', false).text('Add & Clock In');
        attWizardError('Server Error — could not create the profile. Please try again.');
    }

    function attWizardCreated(res){
        const w = attWizardState;
        if(!w) return;
        if(res.status !== 'success'){
            w.busy = false;
            const btn = $('#attWizSubmitBtn');
            if(btn.length) btn.prop('disabled', false).text('Add & Clock In');
            attWizardError(res.message || 'Could not create the profile.', res.existing_user_id);
            return;
        }
        const name = `${w.data.first_name} ${w.data.last_name}`.trim() || res.name || 'New profile';
        attClockInUser(res.user_id, name).then(function(clockRes){
            w.busy = false;
            attWizardRenderSuccess(name, clockRes, false);
        });
    }

    function attWizardRenderSuccess(name, clockRes, existing){
        const w = attWizardState;
        if(!w) return;
        w.step = 'success';
        attWizardRenderDots();
        let line1, line2;
        if(clockRes && clockRes.status === 'success'){
            line1 = existing ? 'Existing profile clocked into this event.' : 'Profile created and clocked in.';
            line2 = clockRes.clock_time ? `Clocked in at ${clockRes.clock_time} · they are in the Checked In list.` : 'They are in the Checked In list.';
        } else if(clockRes && clockRes.status === 'warning'){
            line1 = existing ? 'Existing profile kept.' : 'Profile created.';
            line2 = clockRes.message || 'They were already checked in.';
        } else {
            line1 = existing ? 'Existing profile found.' : 'Profile created.';
            line2 = 'The clock-in could not be confirmed — check the Checked In list before retrying.';
        }
        $('#attWizardTitle').text('They\'re in!');
        $('#attWizardSubtitle').text(existing ? 'Existing profile clocked in.' : 'Profile created & clocked in.');
        $('#attWizardBody').html(`<div class="text-center py-4">
            <div class="w-20 h-20 bg-green-50 text-green-600 border border-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h4 class="text-2xl font-black text-gray-900">${esc(name)} is in!</h4>
            <p class="text-sm text-gray-500 font-semibold mt-2">${esc(line1)}</p>
            <p class="text-xs text-gray-400 font-bold mt-1">${esc(line2)}</p>
            <div class="mt-6 flex flex-col sm:flex-row justify-center gap-3">
                <button type="button" onclick="openAttAddWizard('')" class="bg-white hover:bg-gray-50 text-gray-600 border border-gray-200 px-5 py-3 rounded-xl font-bold text-sm transition-colors">Add another</button>
                <button type="button" onclick="attWizardClose()" class="bg-hodBlue hover:bg-[#152750] text-white px-6 py-3 rounded-xl font-black text-sm shadow-md transition-all">Done</button>
            </div>
        </div>`);
        $('#attWizardFooter').addClass('hidden').html('');
        attRefreshAll();
    }

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
        initEventTabs();
        initEventActionMenus();
        loadEvents(); renderPreview();
    });
</script>
<?php require_once '../../includes/footer.php'; ?>