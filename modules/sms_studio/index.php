<?php
/**
 * SMS STUDIO — Frontend page
 * File: /modules/sms_studio/index.php
 *
 * A full-page workspace (NOT in the sidebar) that is opened from a button on
 * the Events page. It has a prominent Back button. Self-contained: it loads
 * its own jQuery / Tailwind / Toastify so it can render without the global
 * sidebar layout.
 */
session_start();
if (empty($_SESSION['sms_csrf'])) $_SESSION['sms_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['sms_csrf'];
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMS Studio</title>
<script>const CSRF = <?= json_encode($CSRF) ?>;</script>
<?php if (!$IS_HTTPS): ?>
<script>
  (function(){ if(location.protocol==='https:') return;
    // Warn once (non-blocking) if the app is not served over HTTPS.
    window.addEventListener('DOMContentLoaded', function(){
      var w=document.createElement('div'); w.style.cssText='position:fixed;top:0;left:0;right:0;z-index:99999;background:#fef3c7;color:#92400e;text-align:center;padding:6px 12px;font-size:12px;font-weight:600;font-family:sans-serif';
      w.textContent='⚠ Security: SMS secrets are revealed over HTTP. Serve this app over HTTPS.';
      document.body.appendChild(w);
    });
  })();
</script>
<?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<style>
  :root{ --hod:#123b8c; --hod-dark:#152750; --hod-red:#e11d48; }
  body{ background:#f1f5f9; }
  .hod{ color:#123b8c; } .bg-hod{ background-color:#123b8c; } .bg-hod-dark{ background-color:#152750; }
  .bg-hod-red{ background-color:#e11d48; }
  .custom-scrollbar::-webkit-scrollbar{ width:8px; height:8px; }
  .custom-scrollbar::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:8px; }
  .custom-scrollbar::-webkit-scrollbar-track{ background:transparent; }
  .merge-chip{ cursor:pointer; user-select:none; }
  .merge-chip:hover{ background:#dbeafe; }
  .tab-active{ background:#ffffff; color:#123b8c; box-shadow:0 1px 3px rgba(0,0,0,.12); }
</style>
</head>
<body class="min-h-screen">
<div id="app" class="max-w-[1400px] mx-auto p-4 md:p-6 space-y-5 pb-16">

  <!-- ==================== TOP BAR ==================== -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 md:p-5 flex flex-col md:flex-row gap-4 md:items-center justify-between sticky top-0 z-40">
    <div class="flex items-center gap-4">
      <a href="/modules/events/index.php" class="shrink-0 inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3.5 py-2.5 rounded-xl text-sm font-bold transition-colors">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to Events
      </a>
      <div>
        <h2 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
          <svg class="w-7 h-7 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2H7a2 2 0 01-2-2v-3M5 8a3 3 0 016 0m0 0h8m-8 0V4a1 1 0 10-2 0m10 0v2a2 2 0 01-2 2"/></svg>
          SMS Studio
        </h2>
        <p class="text-gray-500 text-xs font-medium mt-0.5">Compose, test & send bulk SMS to your congregation and event registrants.</p>
      </div>
    </div>
    <div class="flex bg-gray-100 p-1.5 rounded-2xl border border-gray-100 w-full md:w-auto">
      <button onclick="switchTab('compose')" id="tab-compose" class="tab-btn flex-1 md:flex-none px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Compose</button>
      <button onclick="switchTab('history')" id="tab-history" class="tab-btn flex-1 md:flex-none px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">History</button>
      <button onclick="switchTab('suppression')" id="tab-suppression" class="tab-btn flex-1 md:flex-none px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Suppression</button>
      <button onclick="switchTab('settings')" id="tab-settings" class="tab-btn flex-1 md:flex-none px-5 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Settings</button>
    </div>
  </div>

  <!-- ==================== COMPOSE ==================== -->
  <section id="sec-compose" class="hidden grid grid-cols-1 lg:grid-cols-2 gap-5 items-start animate-fade-in-up">

    <!-- LEFT: AUDIENCE + MESSAGE -->
    <div class="space-y-5">
      <!-- Audience -->
      <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Audience
          </h3>
          <span id="selectedCount" class="text-xs font-bold bg-blue-50 text-[#123b8c] px-3 py-1.5 rounded-full">0 selected</span>
        </div>

        <div class="space-y-2 mb-4">
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-[#123b8c]/40">
            <input type="radio" name="audience" value="users" checked onchange="onAudienceChange()" class="w-4 h-4 text-[#123b8c] focus:ring-[#123b8c]">
            <div><p class="font-bold text-gray-800 text-sm">Congregation Contacts (Users)</p><p class="text-xs text-gray-500">Registered members/visitors from the users table.</p></div>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-[#123b8c]/40">
            <input type="radio" name="audience" value="registrations" onchange="onAudienceChange()" class="w-4 h-4 text-[#123b8c] focus:ring-[#123b8c]">
            <div><p class="font-bold text-gray-800 text-sm">Registered Contacts (Registrations)</p><p class="text-xs text-gray-500">Event registrants, deduplicated by phone.</p></div>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-[#123b8c]/40">
            <input type="radio" name="audience" value="checkins" onchange="onAudienceChange()" class="w-4 h-4 text-[#123b8c] focus:ring-[#123b8c]">
            <div><p class="font-bold text-gray-800 text-sm">Check-ins (Attendees)</p><p class="text-xs text-gray-500">Everyone who checked in to an event, deduplicated by phone.</p></div>
          </label>
        </div>

        <!-- Users filters -->
        <div id="userFilters" class="mb-4">
          <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Filter by status (blank = all)</p>
          <div id="statusChips" class="flex flex-wrap gap-2">
            <?php $statuses=['Visitor','1st_Timer','2nd_Timer','3rd_Timer','Member','Worker','Pastor','Non_Member'];
                  foreach($statuses as $s): ?>
            <label class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5 text-xs font-semibold cursor-pointer">
              <input type="checkbox" class="status-chip w-3.5 h-3.5 rounded text-[#123b8c]" value="<?= htmlspecialchars($s) ?>" onchange="onAudienceChange()">
              <?= htmlspecialchars($s) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Registration filters -->
        <div id="regFilters" class="hidden mb-4 space-y-3">
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer">
            <input type="radio" name="regscope" value="all" checked onchange="onAudienceChange()" class="w-4 h-4 text-[#123b8c]">
            <span class="font-bold text-gray-800 text-sm">All registered contacts (deduplicated)</span>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer">
            <input type="radio" name="regscope" value="event" onchange="onAudienceChange()" class="w-4 h-4 text-[#123b8c]">
            <span class="font-bold text-gray-800 text-sm">Only for a specific event</span>
          </label>
          <select id="regEventSelect" class="hidden w-full px-4 py-3 border border-gray-200 rounded-xl bg-white focus:ring-2 focus:ring-[#123b8c] outline-none font-bold">
            <option value="">-- Choose an event --</option>
          </select>
        </div>

        <!-- Check-ins filters -->
        <div id="checkinFilters" class="hidden mb-4 space-y-2">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Check-ins for which event?</label>
          <select id="checkinEventSelect" onchange="fetchRecipients()" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white focus:ring-2 focus:ring-[#123b8c] outline-none font-bold">
            <option value="">-- Choose an event --</option>
          </select>
        </div>

        <!-- Search + actions -->
        <div class="flex gap-2 mb-3">
          <div class="relative flex-1">
            <input id="contactSearch" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" type="text" placeholder="Search name or phone…" oninput="debouncedFetch()" class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <button onclick="selectAllShown()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Select shown</button>
          <button onclick="clearSelection()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Clear</button>
        </div>

        <div id="contactList" class="h-72 overflow-y-auto custom-scrollbar border border-gray-100 rounded-2xl divide-y divide-gray-50 bg-white">
          <div class="p-6 text-center text-gray-400 text-sm">Loading contacts…</div>
        </div>
      </div>

      <!-- Message builder -->
      <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2 mb-1">
          <svg class="w-5 h-5 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h13a2 2 0 012 2v10a2 2 0 01-2 2H9l-4 4V17H5a2 2 0 01-2-2V5z"/></svg>
          Compose Message
        </h3>
        <p class="text-xs text-gray-500 mb-3">Click a field to insert a merge token. It is replaced with each recipient's data when sent.</p>

        <div class="mb-3">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Campaign title (for your records)</label>
          <input type="text" id="campaignTitle" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="e.g. Sunday Service Reminder" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
        </div>

        <!-- Message Templates -->
        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 mb-3">
          <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Message Templates
          </p>
          <div class="flex flex-wrap gap-2 items-center">
            <select id="templateSelect" class="flex-1 min-w-[160px] px-3 py-2.5 border border-gray-200 rounded-xl bg-white text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
              <option value="">-- Saved templates --</option>
            </select>
            <button onclick="applyTemplate()" class="bg-white border-2 border-[#123b8c] text-[#123b8c] hover:bg-[#123b8c] hover:text-white px-3 py-2 rounded-lg text-xs font-bold">Load</button>
            <button onclick="deleteTemplate()" class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-lg text-xs font-bold">Delete</button>
          </div>
          <div class="flex flex-wrap gap-2 mt-2 items-center">
            <input type="text" id="templateName" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Template name e.g. Sunday Reminder" class="flex-1 min-w-[160px] px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
            <button onclick="saveTemplate()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-4 py-2.5 rounded-lg text-xs font-bold">Save current as template</button>
          </div>
        </div>

        <div id="mergeButtons" class="flex flex-wrap gap-2 mb-3">
          <button type="button" onclick="insertField('{{first_name}}')" class="merge-chip bg-blue-50 hover:bg-blue-100 text-[#123b8c] text-xs font-bold px-3 py-2 rounded-lg">+ First name</button>
          <button type="button" onclick="insertField('{{last_name}}')" class="merge-chip bg-blue-50 hover:bg-blue-100 text-[#123b8c] text-xs font-bold px-3 py-2 rounded-lg">+ Last name</button>
          <button type="button" onclick="insertField('{{guest_name}}')" class="merge-chip bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-bold px-3 py-2 rounded-lg">+ Guest name</button>
          <button type="button" onclick="insertField('{{phone}}')" class="merge-chip bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-3 py-2 rounded-lg">+ Phone</button>
          <button type="button" onclick="insertField('{{email}}')" class="merge-chip bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-3 py-2 rounded-lg">+ Email</button>
          <button type="button" onclick="insertField('{{event_title}}')" class="merge-chip bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold px-3 py-2 rounded-lg">+ Event title</button>
          <button type="button" onclick="insertField('{{blessing_ref}}')" class="merge-chip bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-bold px-3 py-2 rounded-lg">+ Their verse (blessing)</button>
        </div>

        <textarea id="messageTemplate" rows="5" placeholder="Dear {{first_name}},&#10;We would love to have you at our upcoming event. See you soon!" class="w-full p-4 border border-gray-200 rounded-2xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm leading-relaxed"></textarea>
        <div class="flex justify-between items-center mt-1.5 mb-3">
          <span id="charCount" class="text-[11px] font-bold text-gray-400"></span>
          <span id="smsCount" class="text-[11px] font-bold text-gray-400"></span>
        </div>

        <!-- Live preview -->
        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 mb-4">
          <div class="flex items-center gap-2 mb-2">
            <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
            <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Preview (using first selected contact)</p>
          </div>
          <div id="previewBox" class="bg-white border border-gray-200 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-wrap max-h-40 overflow-y-auto custom-scrollbar">
            Select contacts to see a live preview.
          </div>
        </div>

        <div class="mb-2">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Send test to this number (optional — overrides "to me")</label>
          <input type="tel" id="testPhone" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="e.g. 09020868023 / +2349020868023" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
          <p class="text-[11px] text-gray-400 mt-1">Any Nigerian format works — it's normalized to 234XXXXXXXXXX before sending.</p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
          <button onclick="sendTest()" class="flex-1 bg-white border-2 border-[#123b8c] text-[#123b8c] hover:bg-[#123b8c] hover:text-white px-5 py-3 rounded-xl font-bold transition-colors">
            <svg class="w-5 h-5 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2H7a2 2 0 01-2-2v-3M5 8a3 3 0 016 0m0 0h8m-8 0V4a1 1 0 10-2 0m10 0v2a2 2 0 01-2 2"/></svg>
            Send Test (to me)
          </button>
          <button onclick="sendCampaign()" class="flex-1 bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold shadow-md transition-colors">
            <svg class="w-5 h-5 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            Send Campaign
          </button>
        </div>
      </div>
    </div>

    <!-- RIGHT: SELECTED RECIPIENTS -->
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6 lg:sticky lg:top-28 h-fit">
      <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2 mb-1">
        <svg class="w-5 h-5 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
        Selected Recipients
      </h3>
      <p id="selSummary" class="text-xs text-gray-500 mb-3">0 recipients</p>
      <div id="selectedList" class="space-y-2 max-h-[60vh] overflow-y-auto custom-scrollbar">
        <div class="p-6 text-center text-gray-400 text-sm">No contacts selected yet.</div>
      </div>
    </div>
  </section>

  <!-- ==================== HISTORY ==================== -->
  <section id="sec-history" class="hidden space-y-4 animate-fade-in-up">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 md:p-5 flex flex-col sm:flex-row gap-3 justify-between items-center">
      <h3 class="text-lg font-bold text-gray-900">Send History</h3>
      <div class="relative w-full sm:w-80">
        <input id="historySearch" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Search phone, name, or message…" class="w-full pl-9 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      </div>
    </div>
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto custom-scrollbar">
        <table class="w-full text-left text-sm text-gray-600">
          <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
            <tr>
              <th class="px-5 py-3">Recipient</th>
              <th class="px-5 py-3">Phone</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Message ID</th>
              <th class="px-5 py-3">Cost</th>
              <th class="px-5 py-3">Sent</th>
              <th class="px-5 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody id="historyBody" class="divide-y divide-gray-50"></tbody>
        </table>
      </div>
      <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
        <span id="historyInfo" class="text-xs font-bold text-gray-500 uppercase tracking-widest"></span>
        <div id="historyPages" class="flex gap-2"></div>
      </div>
      <div class="px-5 py-3 bg-amber-50/60 border-t border-amber-100 text-[11px] text-amber-800 leading-relaxed">
        Cost is charged at submission. Undelivered messages on the <b>direct-refund</b> route are credited
        back to your BulkSMS wallet and are <b>not</b> reflected here — your wallet balance is the
        authoritative figure.
      </div>
    </div>
  </section>

  <!-- ==================== SETTINGS ==================== -->
  <section id="sec-settings" class="hidden max-w-2xl mx-auto animate-fade-in-up">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-7">
      <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2 mb-1">
        <svg class="w-5 h-5 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        BulkSMS Connection
      </h3>
      <p class="text-xs text-gray-500 mb-4">Secrets are encrypted at rest. Enter your ERP login password to view or edit them.</p>

      <div id="settingsLocked">
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 mb-4 space-y-1.5">
          <p class="text-sm"><span class="font-bold text-gray-600">Base URL:</span> <span id="maskBase" class="text-gray-400">—</span></p>
          <p class="text-sm"><span class="font-bold text-gray-600">API Token:</span> <span id="maskToken" class="text-gray-400">—</span></p>
          <p class="text-sm"><span class="font-bold text-gray-600">Sender ID:</span> <span id="maskSender" class="text-gray-400">—</span></p>
          <p class="text-sm"><span class="font-bold text-gray-600">Gateway:</span> <span id="maskGateway" class="text-gray-400">—</span></p>
        </div>
        <div class="flex gap-2">
          <input id="unlockPwd" type="password" autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Enter your ERP password to unlock…" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#123b8c]">
          <button onclick="unlockSettings()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold transition-colors">Unlock</button>
        </div>
      </div>

      <div id="settingsUnlocked" class="hidden">
        <form id="settingsForm" class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Base URL</label>
            <input type="text" name="base_url" id="sBase" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="https://www.bulksmsnigeria.com/api/v2" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
            <p class="text-[11px] text-gray-400 mt-1">Use the sandbox URL (https://www.bulksmsnigeria.com/api/sandbox/v2) to test without sending real SMS.</p>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">API Token *</label>
            <input type="text" name="api_token" id="sToken" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Your BulkSMS API token" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Sender ID *</label>
            <div class="flex gap-2">
              <input type="text" name="sender_id" id="sSender" required maxlength="11" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Max 11 characters" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
              <button type="button" onclick="fetchSenderIds()" class="bg-purple-50 hover:bg-purple-100 text-purple-700 px-4 py-3 rounded-xl text-xs font-bold transition-colors">Fetch mine</button>
            </div>
            <div id="senderIdsBox" class="hidden mt-2 bg-purple-50 border border-purple-200 rounded-xl p-3">
              <p class="text-[11px] font-bold text-purple-700 mb-1.5">Your approved sender ID(s):</p>
              <div id="senderIdsList" class="flex flex-wrap gap-2"></div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">Must be registered & approved at bulksmsnigeria.com/app/sender-ids. Click <b>Fetch mine</b> to load your exact approved value.</p>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Gateway (optional)</label>
            <select name="gateway" id="sGateway" autocomplete="off" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none bg-white text-sm">
              <option value="">Default</option>
              <option value="direct-refund">direct-refund</option>
              <option value="direct-corporate">direct-corporate</option>
              <option value="otp">otp</option>
              <option value="dual-backup">dual-backup</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Delivery Webhook URL (recommended)</label>
            <input type="url" name="webhook_url" id="sWebhook" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="https://your-domain.com/api/sms_webhook.php" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none text-sm">
            <p class="text-[11px] text-gray-400 mt-1">BulkSMS POSTs delivery updates here so History shows real delivered/failed. Point it to your copy of <code>api/sms_webhook.php</code>.</p>
          </div>
          <div class="flex flex-col sm:flex-row gap-2 pt-1">
            <button type="submit" class="flex-1 bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold transition-colors">Save & Encrypt</button>
            <button type="button" onclick="testConnection()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-xl font-bold transition-colors">Test Connection</button>
            <button type="button" onclick="lockSettings()" class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 px-5 py-3 rounded-xl font-bold transition-colors">Lock</button>
          </div>
        </form>
      </div>
    </div>
  <!-- ==================== SUPPRESSION ==================== -->
  <section id="sec-suppression" class="hidden max-w-3xl mx-auto space-y-4 animate-fade-in-up">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
          <svg class="w-5 h-5 text-[#123b8c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
          Suppressed Numbers
        </h3>
        <button onclick="loadSuppression()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-xs font-bold transition-colors">Refresh</button>
      </div>
      <p class="text-xs text-gray-500 mb-4">Numbers that have repeatedly failed delivery are auto-suppressed so you stop paying for them. You can release a number to allow sending again.</p>

      <div class="flex gap-2 mb-4">
        <input type="tel" id="suppressPhone" autocomplete="off" placeholder="Phone to suppress (e.g. 0902...) " class="flex-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
        <button onclick="addSuppression()" class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Suppress</button>
      </div>

      <div id="suppressionList" class="divide-y divide-gray-50 border border-gray-100 rounded-2xl">
        <div class="p-6 text-center text-gray-400 text-sm">Loading…</div>
      </div>
    </div>
  </section>

</div>

<!-- Global progress overlay for sends -->
<div id="sendProgress" class="fixed inset-0 hidden z-[9999] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm">
  <div class="bg-white p-6 rounded-3xl shadow-2xl w-full max-w-sm">
    <p class="font-bold text-gray-800 text-sm mb-3 flex items-center gap-2">
      <svg class="animate-spin h-5 w-5 text-[#123b8c]" viewBox="0 0 24 24" fill="none"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
      Sending campaign…
    </p>
    <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
      <div id="progressBar" class="h-full bg-[#123b8c] transition-all" style="width:0%"></div>
    </div>
    <p id="progressText" class="text-xs text-gray-500 mt-2 font-bold"></p>
  </div>
</div>

<style>
  .animate-fade-in-up{ animation:fadeInUp .5s ease both; }
  @keyframes fadeInUp{ from{opacity:0; transform:translateY(14px);} to{opacity:1; transform:none;} }
</style>

<script>
/* ================================================================
   STATE
================================================================ */
const API = '/api/sms_api.php';
let recipients = [];          // all fetched contacts for current filter
let selected = new Map();     // phone -> recipient object
let fetchTimer = null;
let histPage = 1;
// auto-poll of delivery statuses (History tab only, for not-yet-delivered rows)
let histPollTimer = null;
let pendingIds = [];          // ids of rows still awaiting a final status

/* ================================================================
   HELPERS
================================================================ */
function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg,type='success'){ Toastify({ text:msg, gravity:"top", position:"center", duration:3000, style:{ background: type==='success'?'#10B981':'#EF4444', borderRadius:'10px', fontWeight:'bold' } }).showToast(); }
function api(action, extra, cb){ const d = Object.assign({ action, _csrf: CSRF }, extra||{}); return $.post(API, d, cb, 'json'); }
function debouncedFetch(){ clearTimeout(fetchTimer); fetchTimer=setTimeout(fetchRecipients, 400); }
function currentAudience(){ return $('input[name="audience"]:checked').val(); }
function currentStatuses(){ return $('.status-chip:checked').map(function(){return this.value;}).get(); }

/* ================================================================
   TABS
================================================================ */
function switchTab(tab){
  ['compose','history','suppression','settings'].forEach(t=>{
    $('#sec-'+t).addClass('hidden');
    $('#tab-'+t).removeClass('tab-active text-gray-500').addClass('text-gray-500');
  });
  $('#sec-'+tab).removeClass('hidden');
  $('#tab-'+tab).removeClass('text-gray-500').addClass('tab-active text-gray-500');
  if(tab==='compose'){ fetchRecipients(); loadTemplates(); }
  if(tab==='history'){ loadHistory(1); startDeliveryPoll(); }
  if(tab==='suppression') loadSuppression();
  if(tab==='settings') loadSettingsStatus();
  if(tab!=='history') stopDeliveryPoll();
}

/* ================================================================
   DELIVERY AUTO-POLL (History tab only, non-delivered rows)
================================================================ */
function startDeliveryPoll(){
  if(histPollTimer) clearInterval(histPollTimer);
  histPollTimer = setInterval(pollPendingDeliveries, 15000); // every 15s
}
function stopDeliveryPoll(){
  if(histPollTimer){ clearInterval(histPollTimer); histPollTimer = null; }
}
function pollPendingDeliveries(){
  // quiet re-check of any rows not yet delivered/failed
  if(pendingIds.length === 0) return;
  const ids = pendingIds.slice();
  ids.forEach(function(id){ checkDelivery(id, true); });
}

/* ================================================================
   AUDIENCE
================================================================ */
function onAudienceChange(){
  const aud = currentAudience();
  $('#userFilters').toggleClass('hidden', aud!=='users');
  $('#regFilters').toggleClass('hidden', aud!=='registrations');
  $('#checkinFilters').toggleClass('hidden', aud!=='checkins');
  $('#regEventSelect').toggleClass('hidden', !($('input[name="regscope"]:checked').val()==='event'));
  fetchRecipients();
}
function onRegScopeChange(){
  $('#regEventSelect').toggleClass('hidden', !($('input[name="regscope"]:checked').val()==='event'));
  fetchRecipients();
}
$(document).on('change','input[name="regscope"]', onRegScopeChange);

function fetchRecipients(){
  if($('#sec-compose').hasClass('hidden')) return;
  const aud = currentAudience();
  const data = { action:'fetch_recipients', audience:aud, search:$('#contactSearch').val()||'' };
  if(aud==='users'){ data.statuses = currentStatuses(); }
  else if(aud==='checkins'){
    data.event_id = $('#checkinEventSelect').val();
  }
  else {
    const scope = $('input[name="regscope"]:checked').val();
    if(scope==='event') data.event_id = $('#regEventSelect').val();
  }
  $('#contactList').html('<div class="p-6 text-center text-gray-400 text-sm">Loading…</div>');
  $.post(API, Object.assign({ _csrf: CSRF }, data), function(res){
    if(res.status!=='success'){ $('#contactList').html('<div class="p-6 text-center text-red-400 text-sm">'+esc(res.message)+'</div>'); return; }
    recipients = res.data;
    renderContactList();
    renderSelected();
  },'json');
}

function renderContactList(){
  if(recipients.length===0){
    $('#contactList').html('<div class="p-6 text-center text-gray-400 text-sm">No contacts found. Adjust filters or search.</div>');
    return;
  }
  let html='';
  recipients.forEach((r,i)=>{
    const checked = selected.has(r.phone);
    html += `<label class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-blue-50/40">
      <input type="checkbox" class="contact-cb w-4 h-4 rounded text-[#123b8c]" data-i="${i}" ${checked?'checked':''} onchange="toggleSelect(${i})">
      <div class="flex-1 min-w-0">
        <p class="font-bold text-gray-800 text-sm truncate">${esc(r.name||'Unnamed')}</p>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">${esc(r.phone||'')} · ${r.source==='checkin'?'Check-in · '+esc(r.blessing_ref||'no verse'):(r.source==='user'?'Congregation':esc(r.event_title||'Registration'))}</p>
      </div>
      <span class="text-[10px] font-bold ${selected?'text-[#123b8c]':'text-gray-300'}">${selected?'✓':'+ add'}</span>
    </label>`;
  });
  $('#contactList').html(html);
}

function toggleSelect(i){
  const r = recipients[i];
  if(selected.has(r.phone)) selected.delete(r.phone); else selected.set(r.phone, r);
  renderContactList();
  renderSelected();
  updatePreview();
}

function selectAllShown(){ recipients.forEach(r=>selected.set(r.phone,r)); renderContactList(); renderSelected(); updatePreview(); }
function clearSelection(){ selected.clear(); renderContactList(); renderSelected(); updatePreview(); }
function removeSelected(phone){ selected.delete(phone); renderContactList(); renderSelected(); updatePreview(); }

function renderSelected(){
  const arr=[...selected.values()];
  $('#selectedCount').text(arr.length + ' selected');
  $('#selSummary').text(arr.length + ' recipient' + (arr.length===1?'':'s'));
  if(arr.length===0){ $('#selectedList').html('<div class="p-6 text-center text-gray-400 text-sm">No contacts selected yet.</div>'); return; }
  let html='';
  arr.forEach(r=>{
    html += `<div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-3 py-2">
      <div class="flex-1 min-w-0">
        <p class="font-bold text-gray-800 text-sm truncate">${esc(r.name||'Unnamed')}</p>
        <p class="text-[10px] text-gray-400 font-bold">${esc(r.phone)}</p>
      </div>
      <button onclick="removeSelected('${esc(r.phone)}')" class="text-red-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>`;
  });
  $('#selectedList').html(html);
}

/* ================================================================
   MESSAGE BUILDER
================================================================ */
function insertField(token){
  const ta = $('#messageTemplate')[0];
  const start = ta.selectionStart || ta.value.length;
  const end = ta.selectionEnd || ta.value.length;
  ta.value = ta.value.slice(0,start) + token + ta.value.slice(end);
  ta.focus(); ta.selectionStart = ta.selectionEnd = start + token.length;
  updateCount(); updatePreview();
}
const GSM7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡"
           + "ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM7_EXT = "^{}\\[~]|€";

function smsPages(text){
  let len = 0, unicode = false;
  for (const ch of text) {
    if (GSM7.includes(ch))          len += 1;
    else if (GSM7_EXT.includes(ch)) len += 2;
    else { unicode = true; break; }
  }
  if (unicode) {
    const u = [...text].length;
    return { enc:'Unicode', chars:u, pages: Math.max(1, Math.ceil(u/70)) };
  }
  return { enc:'GSM-7', chars:len, pages: Math.max(1, Math.ceil(len/160)) };
}

function updateCount(){
  const v = $('#messageTemplate').val();
  const p = smsPages(v);
  $('#charCount').text(v.length + ' chars · ' + p.enc);
  $('#smsCount').text(p.pages + ' SMS unit(s)' + (p.enc==='Unicode' ? ' ⚠ 3× cost' : ''));
}
$('#messageTemplate').on('input', function(){ updateCount(); updatePreview(); });

function updatePreview(){
  const tpl = $('#messageTemplate').val();
  const first = [...selected.values()][0] || { first_name:'Ada', last_name:'Example', guest_name:'Guest Sample', phone:'2348000000000', email:'guest@example.com', event_title:'Sunday Service', blessing_ref:'Numbers 6:24' };
  // {{guest_name}} = first name only (up to the first space), matching the backend
  let fullGuest = (first.guest_name||'').trim();
  if(fullGuest==='' && first.first_name) fullGuest = (first.first_name+' '+(first.last_name||'')).trim();
  else if(fullGuest==='') fullGuest = (first.name||'').trim();
  const guestFirstName = fullGuest ? fullGuest.split(' ')[0] : '';
  const map = {
    '{{first_name}}': first.first_name||'', '{{last_name}}': first.last_name||'',
    '{{guest_name}}': guestFirstName, '{{name}}': fullGuest || first.name||'',
    '{{phone}}': first.phone||'', '{{email}}': first.email||'', '{{event_title}}': first.event_title||'',
    '{{blessing_ref}}': first.blessing_ref||''
  };
  const preview = (tpl||'').replace(/{{[a-z_]+}}/g, m => map[m] ?? m);
  $('#previewBox').text(preview || 'Start typing a message to see the live preview.');
}

/* ================================================================
   MESSAGE TEMPLATES (save / load / delete)
================================================================ */
function loadTemplates(){
  api('list_templates', {}, function(res){
    if(res.status!=='success'||!res.data) return;
    let o='<option value="">-- Saved templates --</option>';
    res.data.forEach(t=>{ o+=`<option value="${t.id}">${esc(t.name)}</option>`; });
    $('#templateSelect').html(o);
  });
}
function applyTemplate(){
  const id = $('#templateSelect').val();
  if(!id){ toast('Choose a template to load.','error'); return; }
  api('get_template', { id }, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    $('#messageTemplate').val(res.template.body_template||'');
    updateCount(); updatePreview();
    toast('Template loaded.');
  });
}
function saveTemplate(){
  const name = $('#templateName').val().trim();
  const body = $('#messageTemplate').val();
  if(!name){ toast('Give the template a name.','error'); return; }
  if(!body.trim()){ toast('Write a message first.','error'); return; }
  api('save_template', { name, body_template: body }, function(res){
    toast(res.message,res.status);
    if(res.status==='success'){ $('#templateName').val(''); loadTemplates(); }
  });
}
function deleteTemplate(){
  const id = $('#templateSelect').val();
  if(!id){ toast('Choose a template to delete.','error'); return; }
  if(!confirm('Delete this template?')) return;
  api('delete_template', { id }, function(res){
    toast(res.message,res.status);
    if(res.status==='success') loadTemplates();
  });
}

/* ================================================================
   SEND
================================================================ */
function sendTest(){
  const tpl = $('#messageTemplate').val();
  if(!tpl.trim()){ toast('Write a message first.','error'); return; }
  const customPhone = $('#testPhone').val().trim();
  // cost warning — a test send is a REAL, billed SMS
  if(!confirm('This will send a REAL SMS and deduct from your BulkSMS balance (~₦5-7 per message).\n\nSend the test anyway?')) return;
  lockSend();
  api('send_test', { template:tpl, audience:currentAudience(), test_phone: customPhone }, function(res){
    unlockSend(); toast(res.message, res.status);
  });
}

function sendCampaign(){
  const arr=[...selected.values()];
  if(arr.length===0){ toast('Select at least one recipient.','error'); return; }
  const tpl = $('#messageTemplate').val();
  if(!tpl.trim()){ toast('Write a message first.','error'); return; }
  if(!confirm('Send this SMS to '+arr.length+' recipient(s)? This will deduct from your BulkSMS balance.')) return;

  const CHUNK = 10;  // small batches so each AJAX request stays under the server timeout (~15-25s per batch)
  let idx = 0, sent=0, failed=0;
  $('#sendProgress').removeClass('hidden').addClass('flex');

  function next(){
    const batch = arr.slice(idx, idx+CHUNK);
    if(batch.length===0){
      $('#progressBar').css('width','100%'); $('#progressText').text('Done.');
      setTimeout(()=>{ $('#sendProgress').addClass('hidden').removeClass('flex'); toast('Done: '+sent+' sent, '+failed+' failed.'); loadHistory(1); }, 600);
      return;
    }
    const pct = Math.round(idx/arr.length*100);
    $('#progressBar').css('width', pct+'%');
    $('#progressText').text(sent+' sent · '+failed+' failed · batch '+Math.ceil(idx/CHUNK)+' of '+Math.ceil(arr.length/CHUNK));

    api('send_campaign', {
      template:tpl, audience:currentAudience(),
      recipients: JSON.stringify(batch),
      title: $('#campaignTitle').val().trim() || 'Bulk SMS - '+new Date().toLocaleDateString(),
      event_id: currentAudience()==='checkins' ? $('#checkinEventSelect').val() : ($('input[name="regscope"]:checked').val()==='event' ? $('#regEventSelect').val() : '')
    }, function(res){
      if(res.status==='success'){
        const m = res.message.match(/(\d+) sent/);
        sent += m ? parseInt(m[1]) : batch.length;
        const f = res.message.match(/(\d+) failed/);
        failed += f ? parseInt(f[1]) : 0;
      } else {
        failed += batch.length;
        toast(res.message,'error');
      }
      idx += batch.length;
      next();
    }).fail(function(){ failed += batch.length; idx += batch.length; next(); });
  }
  next();
}

/* ================================================================
   HISTORY
================================================================ */
function loadHistory(page){
  histPage = page;
  api('get_history', { page, search:$('#historySearch').val()||'' }, function(res){
    if(res.status!=='success') return;
    const rows = res.data||[];
    // Track rows that still await a final status (for the auto-poll).
    // Only poll recent (< 24h) rows, capped at 10, to stay under the 120 req/min limit.
    pendingIds = rows.filter(function(l){
      if (['delivered','failed','blocked','unknown'].indexOf(l.status) !== -1) return false;
      const ageHrs = (Date.now() - new Date(l.created_at.replace(' ','T')).getTime()) / 3600000;
      return ageHrs < 24;
    }).map(function(l){ return l.id; }).slice(0, 10);
    let html='';
    if(rows.length===0){
      html='<tr><td colspan="7" class="px-5 py-10 text-center text-gray-400 font-medium">No SMS sends yet.</td></tr>';
    } else {
      rows.forEach(l=>{
        const statusMap = {
          delivered:{label:'Delivered', cls:'bg-green-100 text-green-700'},
          sent:{label:'Submitted — awaiting confirmation', cls:'bg-amber-100 text-amber-800'},
          pending:{label:'Awaiting delivery report', cls:'bg-amber-100 text-amber-800'},
          queued:{label:'Queued', cls:'bg-amber-100 text-amber-700'},
          failed:{label:'Failed', cls:'bg-red-100 text-red-600'},
          blocked:{label:'Blocked (not sent, ₦0)', cls:'bg-gray-200 text-gray-700'},
          unknown:{label:'No report received', cls:'bg-gray-100 text-gray-500'}
        };
        const s = statusMap[l.status] || {label:l.status, cls:'bg-gray-100 text-gray-600'};
        // sub-note = raw BulkSMS status text (now stored in error_message)
        const note = (l.status==='pending' || l.status==='sent' || l.status==='failed')
          && l.error_message && l.error_message !== '' ? l.error_message : (l.api_code||'');
        html += `<tr class="hover:bg-blue-50/30 transition-colors">
          <td class="px-5 py-3"><p class="font-bold text-gray-900">${esc(l.recipient_name||'—')}</p><p class="text-[10px] text-gray-400">${esc(l.campaign_title||'Test/Manual')}</p></td>
          <td class="px-5 py-3 text-sm">${esc(l.recipient_phone)}</td>
          <td class="px-5 py-3"><span class="text-[10px] font-bold uppercase px-2.5 py-1 rounded-full ${s.cls}">${esc(s.label)}</span>${note?`<p class="text-[10px] text-gray-400 mt-0.5 italic">${esc(note)}</p>`:''}</td>
          <td class="px-5 py-3 text-xs text-gray-500 truncate max-w-[160px]">${esc(l.message_id||'—')}</td>
          <td class="px-5 py-3 text-sm">${l.cost!=null?'₦'+l.cost:'—'}</td>
          <td class="px-5 py-3 text-xs text-gray-500">${esc(l.created_at||'')}</td>
          <td class="px-5 py-3 text-right">
            <button onclick="checkDelivery(${l.id})" class="bg-amber-50 hover:bg-amber-100 text-amber-700 text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors">⟳ Refresh</button>
            <button onclick="resendOne(${l.id})" class="bg-blue-50 hover:bg-blue-100 text-[#123b8c] text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors ml-1">↻ Resend</button>
          </td>
        </tr>`;
      });
    }
    $('#historyBody').html(html);
    $('#historyInfo').text('Page '+res.page+' of '+res.pages+' · '+res.total+' record(s)');

    let p='';
    if(res.page>1) p+=`<button onclick="loadHistory(${res.page-1})" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600 hover:bg-gray-50">Prev</button>`;
    if(res.page<res.pages) p+=`<button onclick="loadHistory(${res.page+1})" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600 hover:bg-gray-50">Next</button>`;
    $('#historyPages').html(p);
  },'json');
}
let histTimer;
$('#historySearch').on('input', function(){ clearTimeout(histTimer); histTimer=setTimeout(()=>loadHistory(1),400); });

function checkDelivery(id, quiet){
  // quiet = background auto-poll (no spinner, no toast); manual = show spinner + toast
  if(!quiet) lockSend();
  api('check_delivery', { id }, function(res){
    if(!quiet){ unlockSend(); toast(res.message,res.status); }
    if(res.status==='success' && res.delivery_status && res.delivery_status !== 'pending'){
      // reached a final status — drop from the poll list and refresh
      pendingIds = pendingIds.filter(function(x){ return x !== id; });
      loadHistory(histPage);
    }
  });
}
function resendOne(id){
  if(!confirm('Resend this message? This will send a REAL SMS and deduct from your BulkSMS balance (~₦5-7 per message).')) return;
  lockSend();
  api('resend_recipient', { id }, function(res){
    unlockSend(); toast(res.message,res.status); if(res.status==='success') loadHistory(histPage);
  });
}

/* ================================================================
   SUPPRESSION
================================================================ */
function loadSuppression(){
  api('list_suppression', {}, function(res){
    if(res.status!=='success') return;
    const rows = res.data||[];
    if(rows.length===0){
      $('#suppressionList').html('<div class="p-6 text-center text-gray-400 text-sm">No suppressed numbers. 🎉</div>');
      return;
    }
    let h='';
    rows.forEach(function(s){
      const active = !s.released_at;
      h += `<div class="flex items-center justify-between px-4 py-3 gap-3">
        <div class="flex-1 min-w-0">
          <p class="font-bold text-gray-800 text-sm">${esc(s.phone)} <span class="${active?'bg-red-100 text-red-700':'bg-gray-100 text-gray-500'} text-[10px] font-bold uppercase px-2 py-0.5 rounded-full">${active?'Active':'Released'}</span></p>
          <p class="text-[11px] text-gray-500 truncate">${esc(s.reason||'')}</p>
          <p class="text-[10px] text-gray-400">${s.consecutive_failures} consecutive failures · last ${esc(s.last_failed_at||'')}</p>
        </div>
        ${active?`<button onclick="releaseSuppression(${s.id})" class="bg-blue-50 hover:bg-blue-100 text-[#123b8c] text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors">Release</button>`:''}
      </div>`;
    });
    $('#suppressionList').html(h);
  });
}
function releaseSuppression(id){
  if(!confirm('Release this number so it can receive SMS again?')) return;
  api('release_suppression', { id }, function(res){
    toast(res.message,res.status); loadSuppression();
  });
}
function addSuppression(){
  const phone = $('#suppressPhone').val().trim();
  if(!phone){ toast('Enter a phone number.','error'); return; }
  if(!confirm('Suppress this number? It will not receive SMS until released.')) return;
  api('add_suppression', { phone }, function(res){
    toast(res.message,res.status); $('#suppressPhone').val(''); loadSuppression();
  });
}

/* ================================================================
   SETTINGS
================================================================ */
function loadSettingsStatus(){
  api('settings_status', {}, function(res){
    if(res.status!=='success') return;
    $('#maskBase').text(res.masked.base_url||'—');
    $('#maskToken').text(res.masked.api_token||'—');
    $('#maskSender').text(res.masked.sender_id||'—');
    $('#maskGateway').text(res.masked.gateway||'—');
    $('#settingsLocked').removeClass('hidden');
    $('#settingsUnlocked').addClass('hidden');
  });
}
function unlockSettings(){
  const pwd = $('#unlockPwd').val();
  if(!pwd){ toast('Enter your password.','error'); return; }
  api('unlock_settings', { password:pwd }, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    const s = res.settings||{};
    $('#sBase').val(s.base_url||'https://www.bulksmsnigeria.com/api/v2');
    $('#sToken').val(s.api_token||'');
    $('#sSender').val(s.sender_id||'');
    $('#sGateway').val(s.gateway||'');
    $('#sWebhook').val(s.webhook_url||'');
    $('#unlockPwd').val('');
    $('#settingsLocked').addClass('hidden');
    $('#settingsUnlocked').removeClass('hidden');
    toast('Unlocked. Secrets are now visible only for this session.');
  });
}
function lockSettings(){
  api('lock_settings', {}, function(){
    toast('Settings locked.'); loadSettingsStatus();
  });
}
function testConnection(){
  api('test_connection', {}, function(res){
    toast(res.status==='success' ? ('Connection OK. Balance: ₦'+res.balance) : res.message, res.status);
  });
}
function fetchSenderIds(){
  api('list_sender_ids', {}, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    const ids = res.data || [];
    if(ids.length===0){ toast('No approved sender IDs found on your account.','error'); $('#senderIdsBox').addClass('hidden'); return; }
    let h='';
    ids.forEach(function(s){
      const name = s.sender_id || s.name || (typeof s === 'string' ? s : JSON.stringify(s));
      const status = s.status || '';
      h += `<button type="button" onclick="$('#sSender').val('${esc(name)}')" class="bg-white border border-purple-300 text-purple-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-purple-100" title="Click to use this sender ID">${esc(name)} ${status?`(${esc(status)})`:''}</button>`;
    });
    $('#senderIdsList').html(h);
    $('#senderIdsBox').removeClass('hidden');
    toast('Sender IDs loaded. Click one to use it.');
  });
}
$('#settingsForm').on('submit', function(e){
  e.preventDefault();
  api('save_settings', {
    base_url:$('#sBase').val(), api_token:$('#sToken').val(),
    sender_id:$('#sSender').val(), gateway:$('#sGateway').val(),
    webhook_url:$('#sWebhook').val()
  }, function(res){
    toast(res.message,res.status);
    if(res.status==='success') loadSettingsStatus();
  });
});

/* ================================================================
   INIT
================================================================ */
$(document).ready(function(){
  // load events dropdown
  api('fetch_events', {}, function(res){
    if(res.status==='success' && res.data){
      let o='<option value="">-- Choose an event --</option>';
      res.data.forEach(e=>{ o+=`<option value="${e.id}">${esc(e.title)}</option>`; });
      $('#regEventSelect').html(o);
      $('#checkinEventSelect').html(o);
    }
  });
  switchTab('compose');
  updateCount(); updatePreview();
});

/* send overlay helpers */
function lockSend(){ $('#sendProgress').removeClass('hidden').addClass('flex'); $('#progressBar').css('width','0%'); $('#progressText').text('Working…'); }
function unlockSend(){ $('#sendProgress').addClass('hidden').removeClass('flex'); }
</script>
</body>
</html>
