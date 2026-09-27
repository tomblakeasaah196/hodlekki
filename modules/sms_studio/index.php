<?php
// /modules/sms_studio/index.php
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/sms_functions.php';
/**
 * SMS STUDIO — compose, send, track and audit every SMS.
 * Runs inside the standard ERP shell (sidebar + header) and is also opened from
 * the Events page. All dates and times shown are Africa/Lagos (WAT, UTC+1).
 *
 * Deep links: ?tab=history&phone=0803…  ·  ?msg=<sms_log id>  ·  ?campaign=<ids>
 */

// Server-side gate — mirrors the SMS API (never rely on the sidebar alone).
if (!sms_user_can_send()) {
    echo '<div class="max-w-xl mx-auto bg-white p-10 rounded-3xl border border-gray-100 shadow-sm text-center">'
        . '<h2 class="text-xl font-display font-bold text-gray-900">SMS Studio</h2>'
        . '<p class="text-gray-500 mt-2">SMS Studio is for pastors, Directors, HODs and Sub-Unit Heads. '
        . 'Ask a Super Admin if you need access.</p></div>';
    require_once '../../includes/footer.php';
    exit;
}
if (empty($_SESSION['sms_csrf'])) $_SESSION['sms_csrf'] = bin2hex(random_bytes(32));
$SMS_CSRF = $_SESSION['sms_csrf'];
$SMS_STATUSES = ['Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Member', 'Worker', 'Pastor', 'Non_Member'];
?>
<style>
  .sms-scroll::-webkit-scrollbar{ width:8px; height:8px; }
  .sms-scroll::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:8px; }
  .sms-scroll::-webkit-scrollbar-track{ background:transparent; }
  .sms-tab-active{ background:#ffffff; color:#1D356A; box-shadow:0 1px 3px rgba(0,0,0,.12); }
  .sms-chip-on{ background:#1D356A !important; color:#fff !important; border-color:#1D356A !important; }
  #smsDrawer{ transition: transform .25s ease; }
  .sms-timeline li:last-child .sms-rail{ display:none; }
</style>

<div class="max-w-[1400px] mx-auto space-y-5 pb-16">

  <!-- ==================== TOP BAR ==================== -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 p-4 md:p-5 flex flex-col lg:flex-row gap-4 lg:items-center justify-between">
    <div class="flex items-center gap-4">
      <a href="/modules/events/index.php" class="shrink-0 inline-flex items-center gap-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2.5 rounded-xl text-xs font-bold transition-colors" title="Back to Events">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Events
      </a>
      <div class="w-12 h-12 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      </div>
      <div>
        <h2 class="text-2xl font-display font-bold text-gray-900 tracking-tight">SMS Studio</h2>
        <p class="text-gray-500 text-xs font-medium mt-0.5">Compose, send and follow every message to delivery. All times are WAT.</p>
      </div>
    </div>
    <div class="flex overflow-x-auto sms-scroll bg-gray-50/80 p-1.5 rounded-2xl border border-gray-100 w-full lg:w-auto">
      <button data-tab="compose" class="sms-tab shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Compose</button>
      <button data-tab="campaigns" class="sms-tab shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Campaigns</button>
      <button data-tab="history" class="sms-tab shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">History</button>
      <button data-tab="suppression" class="sms-tab shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Suppression</button>
      <button data-tab="settings" class="sms-tab shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500">Settings</button>
    </div>
  </div>

  <!-- ==================== HEALTH BAR ==================== -->
  <div id="healthBar" class="flex flex-wrap gap-2 items-center text-[11px] font-bold">
    <span class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">Checking SMS system…</span>
  </div>

  <!-- ==================== COMPOSE ==================== -->
  <section id="sec-compose" class="sms-sec hidden grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

    <div class="space-y-5">
      <!-- Audience -->
      <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-gray-900">Audience</h3>
          <span id="selectedCount" class="text-xs font-bold bg-blue-50 text-hodBlue px-3 py-1.5 rounded-full">0 selected</span>
        </div>

        <div class="space-y-2 mb-4">
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-hodBlue/40">
            <input type="radio" name="audience" value="users" checked class="w-4 h-4 text-hodBlue">
            <div><p class="font-bold text-gray-800 text-sm">Congregation Contacts (Users)</p><p class="text-xs text-gray-500">Registered members/visitors. Relocated people and numbers marked invalid are left out.</p></div>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-hodBlue/40">
            <input type="radio" name="audience" value="registrations" class="w-4 h-4 text-hodBlue">
            <div><p class="font-bold text-gray-800 text-sm">Registered Contacts (Registrations)</p><p class="text-xs text-gray-500">Event registrants, one message per phone number.</p></div>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-hodBlue/40">
            <input type="radio" name="audience" value="checkins" class="w-4 h-4 text-hodBlue">
            <div><p class="font-bold text-gray-800 text-sm">Check-ins (Attendees)</p><p class="text-xs text-gray-500">Everyone who checked in to an event, one message per phone number.</p></div>
          </label>
        </div>

        <div id="userFilters" class="mb-4">
          <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Filter by status (blank = all)</p>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($SMS_STATUSES as $s): ?>
            <label class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5 text-xs font-semibold cursor-pointer">
              <input type="checkbox" class="status-chip w-3.5 h-3.5 rounded text-hodBlue" value="<?= htmlspecialchars($s) ?>">
              <?= htmlspecialchars(str_replace('_', ' ', $s)) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="regFilters" class="hidden mb-4 space-y-3">
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer">
            <input type="radio" name="regscope" value="all" checked class="w-4 h-4 text-hodBlue">
            <span class="font-bold text-gray-800 text-sm">All registered contacts (deduplicated)</span>
          </label>
          <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 cursor-pointer">
            <input type="radio" name="regscope" value="event" class="w-4 h-4 text-hodBlue">
            <span class="font-bold text-gray-800 text-sm">Only for a specific event</span>
          </label>
          <select id="regEventSelect" class="event-select hidden w-full px-4 py-3 border border-gray-200 rounded-xl bg-white focus:ring-2 focus:ring-hodBlue outline-none font-bold text-sm">
            <option value="">-- Choose an event --</option>
          </select>
        </div>

        <div id="checkinFilters" class="hidden mb-4 space-y-2">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Check-ins for which event? (blank = every event)</label>
          <select id="checkinEventSelect" class="event-select w-full px-4 py-3 border border-gray-200 rounded-xl bg-white focus:ring-2 focus:ring-hodBlue outline-none font-bold text-sm">
            <option value="">-- Every event --</option>
          </select>
        </div>

        <div class="flex gap-2 mb-2">
          <div class="relative flex-1">
            <input id="contactSearch" autocomplete="off" spellcheck="false" type="text" placeholder="Search name or phone…" class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <button id="btnSelectAll" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Select shown</button>
          <button id="btnClearSel" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Clear</button>
        </div>
        <p id="audienceInfo" class="text-[11px] text-gray-500 mb-2 min-h-[1rem]"></p>

        <div id="contactList" class="h-72 overflow-y-auto sms-scroll border border-gray-100 rounded-2xl divide-y divide-gray-50 bg-white">
          <div class="p-6 text-center text-gray-400 text-sm">Loading contacts…</div>
        </div>
      </div>

      <!-- Message builder -->
      <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Compose Message</h3>
        <p class="text-xs text-gray-500 mb-3">Click a field to insert a merge token. It is replaced with each recipient's details when sent.</p>

        <div class="mb-3">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Campaign title (for your records)</label>
          <input type="text" id="campaignTitle" autocomplete="off" placeholder="e.g. Sunday Service Reminder" maxlength="200" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
        </div>

        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 mb-3">
          <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Message Templates</p>
          <div class="flex flex-wrap gap-2 items-center">
            <select id="templateSelect" class="flex-1 min-w-[160px] px-3 py-2.5 border border-gray-200 rounded-xl bg-white text-sm outline-none focus:ring-2 focus:ring-hodBlue">
              <option value="">-- Saved templates --</option>
            </select>
            <button id="btnLoadTpl" class="bg-white border-2 border-hodBlue text-hodBlue hover:bg-hodBlue hover:text-white px-3 py-2 rounded-lg text-xs font-bold">Load</button>
            <button id="btnDelTpl" class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-lg text-xs font-bold">Delete</button>
          </div>
          <div class="flex flex-wrap gap-2 mt-2 items-center">
            <input type="text" id="templateName" autocomplete="off" maxlength="100" placeholder="Template name e.g. Sunday Reminder" class="flex-1 min-w-[160px] px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
            <button id="btnSaveTpl" class="bg-hodBlue hover:bg-[#152750] text-white px-4 py-2.5 rounded-lg text-xs font-bold">Save current as template</button>
          </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-3">
          <button type="button" data-token="{{first_name}}" class="merge-chip bg-blue-50 hover:bg-blue-100 text-hodBlue text-xs font-bold px-3 py-2 rounded-lg">+ First name</button>
          <button type="button" data-token="{{last_name}}" class="merge-chip bg-blue-50 hover:bg-blue-100 text-hodBlue text-xs font-bold px-3 py-2 rounded-lg">+ Last name</button>
          <button type="button" data-token="{{full_name}}" class="merge-chip bg-blue-50 hover:bg-blue-100 text-hodBlue text-xs font-bold px-3 py-2 rounded-lg">+ Full name</button>
          <button type="button" data-token="{{guest_name}}" class="merge-chip bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-bold px-3 py-2 rounded-lg">+ Guest name</button>
          <button type="button" data-token="{{phone}}" class="merge-chip bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-3 py-2 rounded-lg">+ Phone</button>
          <button type="button" data-token="{{email}}" class="merge-chip bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-3 py-2 rounded-lg">+ Email</button>
          <button type="button" data-token="{{event_title}}" class="merge-chip bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold px-3 py-2 rounded-lg">+ Event title</button>
          <button type="button" data-token="{{blessing_ref}}" class="merge-chip bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-bold px-3 py-2 rounded-lg">+ Their verse (blessing)</button>
        </div>

        <textarea id="messageTemplate" rows="5" placeholder="Dear {{first_name}},&#10;We would love to have you at our upcoming event. See you soon!" class="w-full p-4 border border-gray-200 rounded-2xl focus:ring-2 focus:ring-hodBlue outline-none text-sm leading-relaxed"></textarea>
        <div class="flex flex-wrap justify-between items-center gap-2 mt-1.5 mb-2">
          <span id="charCount" class="text-[11px] font-bold text-gray-400"></span>
          <span id="smsCount" class="text-[11px] font-bold text-gray-400"></span>
        </div>
        <div id="composeWarnings" class="space-y-1.5 mb-3"></div>

        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 mb-4">
          <div class="flex items-center gap-2 mb-2">
            <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
            <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Preview — exactly what <span id="previewWho">a sample contact</span> will receive</p>
          </div>
          <div id="previewBox" class="bg-white border border-gray-200 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-wrap max-h-40 overflow-y-auto sms-scroll">Start typing a message to see the live preview.</div>
        </div>

        <div class="mb-2">
          <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Send test to this number (optional — overrides "to me")</label>
          <input type="tel" id="testPhone" autocomplete="off" placeholder="e.g. 0803 123 4567 or +234 803 123 4567" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
          <p class="text-[11px] text-gray-400 mt-1">Any Nigerian format works — it's normalized to 234XXXXXXXXXX before sending. Your number on file: <b id="myPhone">—</b></p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
          <button id="btnSendTest" class="flex-1 bg-white border-2 border-hodBlue text-hodBlue hover:bg-hodBlue hover:text-white px-5 py-3 rounded-xl font-bold transition-colors">Send Test</button>
          <button id="btnSendCampaign" class="flex-1 bg-hodBlue hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold shadow-md transition-colors">Send Campaign</button>
        </div>
      </div>
    </div>

    <!-- RIGHT: SELECTED RECIPIENTS -->
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6 lg:sticky lg:top-4 h-fit">
      <h3 class="text-lg font-bold text-gray-900 mb-1">Selected Recipients</h3>
      <p id="selSummary" class="text-xs text-gray-500 mb-3">0 recipients</p>
      <div id="selectedList" class="space-y-2 max-h-[60vh] overflow-y-auto sms-scroll">
        <div class="p-6 text-center text-gray-400 text-sm">No contacts selected yet.</div>
      </div>
    </div>
  </section>

  <!-- ==================== CAMPAIGNS ==================== -->
  <section id="sec-campaigns" class="sms-sec hidden space-y-4">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 md:p-5 flex flex-col sm:flex-row gap-3 justify-between items-center">
      <div>
        <h3 class="text-lg font-bold text-gray-900">Campaigns</h3>
        <p class="text-xs text-gray-500">Every bulk send with live progress. Click one to follow it or stop what has not gone out yet.</p>
      </div>
      <button id="btnReloadCampaigns" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-xs font-bold">Refresh</button>
    </div>
    <div id="campaignList" class="space-y-3"></div>
    <div class="flex justify-between items-center">
      <span id="campaignInfo" class="text-xs font-bold text-gray-500 uppercase tracking-widest"></span>
      <div id="campaignPages" class="flex gap-2"></div>
    </div>
  </section>

  <!-- ==================== HISTORY ==================== -->
  <section id="sec-history" class="sms-sec hidden space-y-4">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 md:p-5 space-y-3">
      <div class="flex flex-col md:flex-row gap-3 justify-between md:items-center">
        <div>
          <h3 class="text-lg font-bold text-gray-900">Send History</h3>
          <p class="text-xs text-gray-500">Click any row for the full story of that message — every status change with its exact date and time.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
          <div class="relative w-full sm:w-72">
            <input id="historySearch" autocomplete="off" spellcheck="false" placeholder="Search phone, name, message or ID…" class="w-full pl-9 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <input type="date" id="histFrom" class="px-3 py-2 border border-gray-200 rounded-xl text-xs" title="From date">
          <input type="date" id="histTo" class="px-3 py-2 border border-gray-200 rounded-xl text-xs" title="To date">
        </div>
      </div>
      <div id="histStatusChips" class="flex flex-wrap gap-2"></div>
      <div id="histActiveFilters" class="flex flex-wrap gap-2"></div>
    </div>
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto sms-scroll">
        <table class="w-full text-left text-sm text-gray-600">
          <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
            <tr>
              <th class="px-5 py-3">Recipient</th>
              <th class="px-5 py-3">Phone</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Sent (WAT)</th>
              <th class="px-5 py-3">Delivered / final (WAT)</th>
              <th class="px-5 py-3">Cost</th>
              <th class="px-4 py-3 text-right"></th>
            </tr>
          </thead>
          <tbody id="historyBody" class="divide-y divide-gray-50"></tbody>
        </table>
      </div>
      <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex flex-wrap gap-2 justify-between items-center">
        <span id="historyInfo" class="text-xs font-bold text-gray-500 uppercase tracking-widest"></span>
        <div id="historyPages" class="flex gap-2"></div>
      </div>
      <div class="px-5 py-3 bg-amber-50/60 border-t border-amber-100 text-[11px] text-amber-800 leading-relaxed">
        Cost is charged at submission. Undelivered messages on the <b>direct-refund</b> route are credited
        back to your BulkSMS wallet and are <b>not</b> reflected here — your wallet balance is the
        authoritative figure. Blocked and refused messages never reached BulkSMS and cost ₦0.
      </div>
    </div>
  </section>

  <!-- ==================== SUPPRESSION ==================== -->
  <section id="sec-suppression" class="sms-sec hidden max-w-3xl mx-auto space-y-4">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-lg font-bold text-gray-900">Suppressed Numbers</h3>
        <button id="btnReloadSupp" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-xs font-bold transition-colors">Refresh</button>
      </div>
      <p class="text-xs text-gray-500 mb-4">A number is suppressed automatically after 3 delivery failures in a row reported by the carrier, so you stop paying for it. Refusals by BulkSMS (e.g. an empty wallet) never suppress anyone. Release a number to allow sending again.</p>
      <div class="flex flex-col sm:flex-row gap-2 mb-4">
        <input type="tel" id="suppressPhone" autocomplete="off" placeholder="Phone to suppress (e.g. 0803…)" class="flex-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
        <input type="text" id="suppressReason" autocomplete="off" maxlength="200" placeholder="Reason (e.g. asked to stop)" class="flex-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-hodBlue">
        <button id="btnAddSupp" class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2.5 rounded-xl text-xs font-bold transition-colors">Suppress</button>
      </div>
      <div id="suppressionList" class="divide-y divide-gray-50 border border-gray-100 rounded-2xl">
        <div class="p-6 text-center text-gray-400 text-sm">Loading…</div>
      </div>
    </div>
  </section>

  <!-- ==================== SETTINGS ==================== -->
  <section id="sec-settings" class="sms-sec hidden max-w-2xl mx-auto space-y-4">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-7">
      <h3 class="text-lg font-bold text-gray-900 mb-1">BulkSMS Connection</h3>
      <p class="text-xs text-gray-500 mb-4">Secrets are encrypted at rest. Enter your ERP login password to view or edit them.</p>

      <div id="settingsLocked">
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 mb-4 space-y-1.5 text-sm">
          <p><span class="font-bold text-gray-600">Base URL:</span> <span id="maskBase" class="text-gray-500">—</span></p>
          <p><span class="font-bold text-gray-600">API Token:</span> <span id="maskToken" class="text-gray-500">—</span></p>
          <p><span class="font-bold text-gray-600">Sender ID:</span> <span id="maskSender" class="text-gray-500">—</span></p>
          <p><span class="font-bold text-gray-600">Gateway:</span> <span id="maskGateway" class="text-gray-500">—</span></p>
          <p><span class="font-bold text-gray-600">Delivery webhook:</span> <span id="maskWebhook" class="text-gray-500">—</span></p>
        </div>
        <div class="flex gap-2">
          <input id="unlockPwd" type="password" autocomplete="current-password" placeholder="Enter your ERP password to unlock…" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-hodBlue">
          <button id="btnUnlock" class="bg-hodBlue hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold transition-colors">Unlock</button>
        </div>
      </div>

      <div id="settingsUnlocked" class="hidden">
        <form id="settingsForm" class="space-y-4" autocomplete="off">
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Base URL</label>
            <input type="text" id="sBase" spellcheck="false" placeholder="https://www.bulksmsnigeria.com/api/v2" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
            <p class="text-[11px] text-gray-400 mt-1">Must be https. Use the sandbox URL (https://www.bulksmsnigeria.com/api/sandbox/v2) to test without sending real SMS.</p>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">API Token *</label>
            <input type="text" id="sToken" required spellcheck="false" placeholder="Your BulkSMS API token" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Sender ID *</label>
            <div class="flex gap-2">
              <input type="text" id="sSender" required maxlength="11" spellcheck="false" placeholder="3–11 characters" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
              <button type="button" id="btnFetchSenders" class="bg-purple-50 hover:bg-purple-100 text-purple-700 px-4 py-3 rounded-xl text-xs font-bold transition-colors">Fetch mine</button>
            </div>
            <div id="senderIdsBox" class="hidden mt-2 bg-purple-50 border border-purple-200 rounded-xl p-3">
              <p class="text-[11px] font-bold text-purple-700 mb-1.5">Your sender ID(s) — only an <b>approved</b> one will deliver:</p>
              <div id="senderIdsList" class="flex flex-wrap gap-2"></div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">Must be registered & approved at bulksmsnigeria.com. An unapproved sender ID is the most common reason every message fails.</p>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Gateway (optional)</label>
            <select id="sGateway" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none bg-white text-sm">
              <option value="">Default (direct-refund — refunds undelivered messages)</option>
              <option value="direct-refund">direct-refund</option>
              <option value="direct-corporate">direct-corporate (reaches DND numbers)</option>
              <option value="otp">otp</option>
              <option value="dual-backup">dual-backup</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Delivery Webhook URL (recommended)</label>
            <div class="flex gap-2">
              <input type="url" id="sWebhook" spellcheck="false" placeholder="https://your-domain/api/sms_webhook.php?token=…" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-hodBlue outline-none text-sm">
              <button type="button" id="btnUseWebhook" class="bg-blue-50 hover:bg-blue-100 text-hodBlue px-3 py-3 rounded-xl text-xs font-bold transition-colors">Use this site's webhook URL</button>
            </div>
            <p id="webhookHelp" class="text-[11px] text-gray-400 mt-1">BulkSMS calls this with delivery reports so History updates within seconds. Without it, the background sender checks each message itself (slower). Paste the same URL as the webhook in your BulkSMS dashboard too.</p>
          </div>
          <div class="flex flex-col sm:flex-row gap-2 pt-1">
            <button type="submit" class="flex-1 bg-hodBlue hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold transition-colors">Save & Encrypt</button>
            <button type="button" id="btnTestConn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-xl font-bold transition-colors">Test Connection</button>
            <button type="button" id="btnLock" class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 px-5 py-3 rounded-xl font-bold transition-colors">Lock</button>
          </div>
        </form>
      </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-7">
      <h3 class="text-lg font-bold text-gray-900 mb-3">Delivery setup checklist</h3>
      <ul id="setupChecklist" class="space-y-2 text-sm"></ul>
      <p class="text-[11px] text-gray-500 mt-3">Background sender cron (cPanel → Cron Jobs, every minute):</p>
      <pre class="bg-gray-900 text-green-300 text-[11px] rounded-xl p-3 mt-1 overflow-x-auto sms-scroll">* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/sms_queue_worker.php >/dev/null 2>&1</pre>
    </div>
  </section>
</div>

<!-- ==================== CONFIRM SEND MODAL ==================== -->
<div id="confirmModal" class="fixed inset-0 hidden z-[9998] items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="p-5 border-b border-gray-100 flex justify-between items-center">
      <h3 class="font-bold text-gray-900">Send this campaign?</h3>
      <button data-close="confirmModal" class="text-gray-400 hover:text-red-500 text-xl leading-none">&times;</button>
    </div>
    <div id="confirmBody" class="p-5 space-y-3 text-sm max-h-[60vh] overflow-y-auto sms-scroll"></div>
    <div class="p-5 bg-gray-50 flex gap-2 justify-end">
      <button data-close="confirmModal" class="px-4 py-2.5 rounded-xl text-sm font-bold bg-white border border-gray-200 text-gray-700">Cancel</button>
      <button id="btnConfirmSend" class="px-5 py-2.5 rounded-xl text-sm font-bold bg-hodBlue text-white hover:bg-[#152750]">Yes, send it</button>
    </div>
  </div>
</div>

<!-- ==================== DETAIL DRAWER (message / person / campaign) ==================== -->
<div id="drawerOverlay" class="fixed inset-0 bg-gray-900/40 backdrop-blur-[1px] z-[9990] hidden"></div>
<aside id="smsDrawer" class="fixed inset-y-0 right-0 w-full sm:w-[560px] max-w-full bg-white shadow-2xl z-[9991] translate-x-full flex flex-col">
  <div class="p-4 border-b border-gray-100 flex items-center gap-2 shrink-0">
    <button id="drawerBack" class="hidden text-gray-500 hover:text-hodBlue bg-gray-100 rounded-lg px-2.5 py-1.5 text-xs font-bold">&larr; Back</button>
    <h3 id="drawerTitle" class="font-bold text-gray-900 flex-1 truncate">Details</h3>
    <button id="drawerClose" class="text-gray-400 hover:text-red-500 text-2xl leading-none px-2">&times;</button>
  </div>
  <div id="drawerBody" class="flex-1 overflow-y-auto sms-scroll p-5 space-y-5"></div>
</aside>

<!-- Busy overlay (short actions) -->
<div id="busyOverlay" class="fixed inset-0 hidden z-[9999] items-center justify-center bg-gray-900/30 backdrop-blur-sm">
  <div class="bg-white px-6 py-5 rounded-2xl shadow-2xl flex items-center gap-3 text-sm font-bold text-gray-700">
    <svg class="animate-spin h-5 w-5 text-hodBlue" viewBox="0 0 24 24" fill="none"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
    <span id="busyText">Working…</span>
  </div>
</div>

<script>
/* ================================================================
   STATE
================================================================ */
const CSRF = <?= json_encode($SMS_CSRF) ?>;
const API = '/api/sms_api.php';
let recipients = [];          // contacts for the current filter
let selected = new Map();     // phone -> recipient
let fetchTimer = null, histTimer = null, histPollTimer = null, healthTimer = null, campTimer = null;
let histPage = 1, histStatus = '', histCampaign = null, histPhone = '', campPage = 1;
let lastHealth = null, driving = false, composeToken = newToken(), currentTab = 'compose';
let drawerStack = [];         // [{kind, arg}] for the Back button

const ST = {
  queued:   {label:'Sending…',                    cls:'bg-sky-100 text-sky-700',        dot:'bg-sky-500'},
  sent:     {label:'Submitted · awaiting report',  cls:'bg-amber-100 text-amber-800',    dot:'bg-amber-500'},
  pending:  {label:'In transit (carrier)',         cls:'bg-amber-100 text-amber-800',    dot:'bg-amber-500'},
  delivered:{label:'Delivered',                    cls:'bg-emerald-100 text-emerald-700',dot:'bg-emerald-500'},
  failed:   {label:'Failed',                       cls:'bg-red-100 text-red-700',        dot:'bg-red-500'},
  blocked:  {label:'Blocked · not sent (₦0)',      cls:'bg-gray-200 text-gray-700',      dot:'bg-gray-500'},
  unknown:  {label:'Outcome unknown',              cls:'bg-purple-100 text-purple-700',  dot:'bg-purple-500'},
};
const SRC = { studio:'SMS Studio (a user)', worker:'Background sender', bulksms:'BulkSMS reply', webhook:'Delivery report · webhook', poll:'Delivery report · check', system:'System' };
const KNOWN_TOKENS = ['first_name','last_name','full_name','guest_name','name','phone','email','event_title','blessing_ref'];

/* ================================================================
   HELPERS
================================================================ */
function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg,type='success'){ Toastify({ text:String(msg||''), gravity:"top", position:"center", duration: type==='success'?3000:6000, style:{ background: type==='success'?'#10B981':(type==='info'?'#1D356A':'#EF4444'), borderRadius:'10px', fontWeight:'bold' } }).showToast(); }
function api(action, extra, cb){ return $.post(API, Object.assign({ action, _csrf: CSRF }, extra||{}), cb, 'json'); }
function newToken(){ return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
function naira(v){ return v==null || v==='' ? '—' : '₦' + Number(v).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }
function tLabel(t){ return t && t.label ? t.label : '—'; }
function ago(t){
  if(!t || !t.iso) return '';
  const s = Math.round((Date.now() - new Date(t.iso).getTime())/1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s/60) + ' min ago';
  if (s < 86400) return Math.floor(s/3600) + ' h ago';
  return Math.floor(s/86400) + ' d ago';
}
function secsLabel(s){ if(s==null) return 'never'; if(s<60) return s+'s ago'; if(s<3600) return Math.floor(s/60)+' min ago'; if(s<86400) return Math.floor(s/3600)+' h ago'; return Math.floor(s/86400)+' days ago'; }
function stChip(status, messageId){
  const s = ST[status] || {label:status||'?', cls:'bg-gray-100 text-gray-600'};
  const label = (status==='failed' && messageId===null) ? 'Refused · not sent (₦0)' : s.label;
  return `<span class="inline-block text-[10px] font-bold uppercase px-2.5 py-1 rounded-full whitespace-nowrap ${s.cls}">${esc(label)}</span>`;
}
function busy(on, text){ $('#busyText').text(text||'Working…'); $('#busyOverlay').toggleClass('hidden', !on).toggleClass('flex', !!on); }
function openModal(id){ $('#'+id).removeClass('hidden').addClass('flex'); }
function closeModal(id){ $('#'+id).addClass('hidden').removeClass('flex'); }
$(document).on('click','[data-close]', function(){ closeModal($(this).data('close')); });

// Any failed call to the SMS API (HTTP error / non-JSON) used to fail silently.
$(document).ajaxError(function(e, xhr, settings){
  if (!settings.url || settings.url.indexOf(API) !== 0 || xhr.statusText === 'abort') return;
  busy(false);
  const txt = (xhr.responseText||'').replace(/<[^>]+>/g,' ').trim().slice(0,160);
  toast('SMS Studio request failed (HTTP '+xhr.status+'). '+(txt||'Check your connection and try again.'), 'error');
});

/* Same clean-up and counting the server does (includes/sms_functions.php) */
const GSM_CLEAN = {'‘':"'",'’':"'",'‚':"'",'‛':"'",'′':"'",'´':"'",
  '“':'"','”':'"','„':'"','‟':'"','″':'"',
  '‐':'-','‑':'-','‒':'-','–':'-','—':'-','―':'-','−':'-',
  '…':'...','•':'-',' ':' ',' ':' ',' ':' ',' ':' ',' ':' ',
  '​':'','﻿':'','­':''};
function gsmClean(t){ return (t||'').replace(/\r\n/g,'\n').replace(/[‘’‚‛′´“”„‟″‐-―−…•     ​﻿­]/g, c => GSM_CLEAN[c] ?? c); }
const GSM7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM7_EXT = "^{}\\[~]|€";
function smsPages(text){
  let len = 0; const bad = new Set();
  for (const ch of text) {
    if (GSM7.includes(ch)) len += 1;
    else if (GSM7_EXT.includes(ch)) len += 2;
    else bad.add(ch);
  }
  if (bad.size) { const u = text.length; return { enc:'Unicode', chars:u, pages: u<=70 ? 1 : Math.ceil(u/67), bad:[...bad] }; }
  return { enc:'GSM-7', chars:len, pages: len<=160 ? 1 : Math.ceil(len/153), bad:[] };
}
function cleanName(s){ return (s||'').toString().replace(/\s+/g,' ').trim(); }
function render(tpl, d){
  const first = cleanName(d.first_name), last = cleanName(d.last_name);
  let full = cleanName(d.guest_name);
  if (full==='' && first!=='') full = (first+' '+last).trim(); else if (full==='') full = cleanName(d.name);
  const map = { first_name:first, last_name:last, full_name:(first+' '+last).trim(), guest_name: full ? full.split(' ')[0] : '',
    name: full || cleanName(d.name), phone:(d.phone||'').trim(), email:(d.email||'').trim(), event_title:(d.event_title||'').trim(), blessing_ref:(d.blessing_ref||'').trim() };
  return gsmClean((tpl||'').replace(/{{([a-z_]+)}}/g, (m,k) => (k in map) ? map[k] : m)).trim();
}
const SAMPLE = { first_name:'Ada', last_name:'Example', name:'Ada Example', phone:'2348031234567', email:'ada@example.com', event_title:'Sunday Service', blessing_ref:'Numbers 6:24' };

/* ================================================================
   TABS
================================================================ */
function switchTab(tab){
  currentTab = tab;
  $('.sms-sec').addClass('hidden');
  $('.sms-tab').removeClass('sms-tab-active');
  $('#sec-'+tab).removeClass('hidden');
  $('.sms-tab[data-tab="'+tab+'"]').addClass('sms-tab-active');
  if (tab==='compose'){ if(!recipients.length) fetchRecipients(); loadTemplates(); }
  if (tab==='campaigns') loadCampaigns(1);
  if (tab==='history'){ loadHistory(1); startHistPoll(); } else stopHistPoll();
  if (tab==='suppression') loadSuppression();
  if (tab==='settings'){ loadSettingsStatus(); renderChecklist(); }
}
$(document).on('click','.sms-tab', function(){ switchTab($(this).data('tab')); });

/* ================================================================
   HEALTH BAR — is the whole pipeline able to deliver right now?
================================================================ */
function loadHealth(){
  api('health', {}, function(res){
    if (res.status!=='success') return;
    lastHealth = res.health; lastHealth.my_phone = res.my_phone;
    $('#myPhone').text(res.my_phone || 'none on your profile');
    renderHealth();
    if (currentTab==='settings') renderChecklist();
  });
}
function pill(cls, html, title){ return `<span class="px-3 py-1.5 rounded-full ${cls}" ${title?`title="${esc(title)}"`:''}>${html}</span>`; }
function renderHealth(){
  const h = lastHealth; if(!h) return;
  let out = '';
  if (!h.vault_ok) out += pill('bg-red-100 text-red-700', '✕ SMS_VAULT_KEY missing in .env — SMS Studio cannot read its settings');
  else if (h.decrypt_failed && h.decrypt_failed.length) out += pill('bg-red-100 text-red-700', '✕ Saved BulkSMS details cannot be decrypted (vault key changed) — re-enter them in Settings');
  else if (!h.configured) out += pill('bg-amber-100 text-amber-800', '! BulkSMS not connected — open Settings');
  else out += pill('bg-emerald-100 text-emerald-700', '✓ BulkSMS connected');
  if (h.vault_ok) {
    if (h.schema_ready === false) out += pill('bg-amber-100 text-amber-800', '! Database update pending — run db/migrate.php');
    const age = h.cron_age_sec;
    if (age == null) out += pill('bg-amber-100 text-amber-800', '! Background sender has never run — add the cron job (see Settings)', 'Until it runs, campaigns are sent while this page is open.');
    else if (age > 180) out += pill('bg-red-100 text-red-700', '✕ Background sender stopped · last run ' + secsLabel(age), 'Campaigns are sent while this page is open until the cron job is fixed.');
    else out += pill('bg-emerald-100 text-emerald-700', '✓ Background sender running · ' + secsLabel(age));
    if (h.queued > 0) {
      out += pill('bg-sky-100 text-sky-700', `${h.queued} message(s) waiting to send` + (h.oldest_queued_at_t ? ' · since ' + esc(h.oldest_queued_at_t.label) : ''));
      if (age == null || age > 180) out += `<button id="btnSendQueuedNow" class="px-3 py-1.5 rounded-full bg-hodBlue text-white hover:bg-[#152750]">Send queued now</button>`;
    }
    if (h.webhook_secret_set && h.webhook_url_set) out += pill('bg-emerald-50 text-emerald-700', '✓ Delivery webhook on' + (h.last_webhook_at_t ? ' · last report ' + ago(h.last_webhook_at_t) : ' · no report received yet'));
    else out += pill('bg-gray-100 text-gray-600', 'Delivery reports by polling (webhook not set up)');
  }
  $('#healthBar').html(out);
}
$(document).on('click','#btnSendQueuedNow', function(){ driveQueue(true); });

/* Send from this page while cron is down (one run at a time, serialised) */
function driveQueue(loud){
  if (driving) return;
  driving = true;
  if (loud) toast('Sending queued messages from this page… keep it open.', 'info');
  api('process_queue', {}, function(res){
    driving = false;
    if (res.status==='success' && loud) toast(res.message, 'info');
    loadHealth();
    if (res.status==='success' && res.result && res.result.remaining > 0 && !res.result.locked && loud) driveQueue(true);
  }).fail(function(){ driving = false; });
}

/* ================================================================
   AUDIENCE
================================================================ */
function currentAudience(){ return $('input[name="audience"]:checked').val(); }
function currentStatuses(){ return $('.status-chip:checked').map(function(){return this.value;}).get(); }
function currentEventId(){
  const aud = currentAudience();
  if (aud==='checkins') return $('#checkinEventSelect').val();
  if (aud==='registrations' && $('input[name="regscope"]:checked').val()==='event') return $('#regEventSelect').val();
  return '';
}
function onAudienceChange(){
  const aud = currentAudience();
  $('#userFilters').toggleClass('hidden', aud!=='users');
  $('#regFilters').toggleClass('hidden', aud!=='registrations');
  $('#checkinFilters').toggleClass('hidden', aud!=='checkins');
  $('#regEventSelect').toggleClass('hidden', $('input[name="regscope"]:checked').val()!=='event');
  fetchRecipients();
}
$(document).on('change','input[name="audience"], input[name="regscope"], .status-chip, .event-select', onAudienceChange);
$('#contactSearch').on('input', function(){ clearTimeout(fetchTimer); fetchTimer = setTimeout(fetchRecipients, 400); });

let recipXhr = null;
function fetchRecipients(){
  const aud = currentAudience();
  const data = { audience:aud, search:$('#contactSearch').val()||'', event_id: currentEventId() };
  if (aud==='users') data.statuses = currentStatuses();
  $('#contactList').html('<div class="p-6 text-center text-gray-400 text-sm">Loading…</div>');
  if (recipXhr) recipXhr.abort();
  recipXhr = api('fetch_recipients', data, function(res){
    if (res.status!=='success'){ $('#contactList').html('<div class="p-6 text-center text-red-500 text-sm">'+esc(res.message)+'</div>'); return; }
    recipients = res.data || [];
    let info = `${res.count} contact(s) with a valid Nigerian mobile number.`;
    if (res.invalid_count) info += ` ${res.invalid_count} invalid number(s) left out.`;
    if (res.shared_phones) info += ` ${res.shared_phones} shared number(s) merged into one message each.`;
    if (res.truncated) info += ` Showing the first ${res.cap} — narrow the filter to reach the rest.`;
    $('#audienceInfo').html(res.truncated ? `<span class="text-amber-700 font-bold">${esc(info)}</span>` : esc(info));
    renderContactList();
  });
}
function renderContactList(){
  if (!recipients.length){ $('#contactList').html('<div class="p-6 text-center text-gray-400 text-sm">No contacts found. Adjust filters or search.</div>'); return; }
  let html = '';
  recipients.forEach((r,i)=>{
    const on = selected.has(r.phone);
    const sub = r.source==='checkin' ? 'Check-in · ' + (r.blessing_ref||'no verse') : (r.source==='user' ? (r.status_label ? r.status_label.replace(/_/g,' ') : 'Congregation') : (r.event_title||'Registration'));
    html += `<label class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-blue-50/40" data-row="${i}">
      <input type="checkbox" class="contact-cb w-4 h-4 rounded text-hodBlue" data-i="${i}" ${on?'checked':''}>
      <div class="flex-1 min-w-0">
        <p class="font-bold text-gray-800 text-sm truncate">${esc(r.name||'Unnamed')}</p>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">${esc(r.phone)} · ${esc(sub)}</p>
      </div>
      <span class="sel-mark text-[10px] font-bold ${on?'text-hodBlue':'text-gray-300'}">${on?'✓ added':'+ add'}</span>
    </label>`;
  });
  $('#contactList').html(html);
}
function markRow(i){
  const on = selected.has(recipients[i].phone);
  const $row = $('#contactList [data-row="'+i+'"]');
  $row.find('.contact-cb').prop('checked', on);
  $row.find('.sel-mark').text(on?'✓ added':'+ add').toggleClass('text-hodBlue', on).toggleClass('text-gray-300', !on);
}
$(document).on('change','.contact-cb', function(){
  const i = +$(this).data('i'), r = recipients[i];
  if (selected.has(r.phone)) selected.delete(r.phone); else selected.set(r.phone, r);
  markRow(i); afterSelectionChange();
});
$('#btnSelectAll').on('click', function(){ recipients.forEach(r=>selected.set(r.phone,r)); renderContactList(); afterSelectionChange(); });
$('#btnClearSel').on('click', function(){ selected.clear(); renderContactList(); afterSelectionChange(); });
$(document).on('click','.sel-remove', function(){
  selected.delete(String($(this).data('phone')));
  renderContactList(); afterSelectionChange();
});
function afterSelectionChange(){ renderSelected(); scheduleAnalyse(); }
function renderSelected(){
  const arr = [...selected.values()];
  $('#selectedCount').text(arr.length + ' selected');
  $('#selSummary').text(arr.length + ' recipient' + (arr.length===1?'':'s'));
  if (!arr.length){ $('#selectedList').html('<div class="p-6 text-center text-gray-400 text-sm">No contacts selected yet.</div>'); return; }
  let html = '';
  arr.slice(0, 200).forEach(r=>{
    html += `<div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-3 py-2">
      <div class="flex-1 min-w-0"><p class="font-bold text-gray-800 text-sm truncate">${esc(r.name||'Unnamed')}</p><p class="text-[10px] text-gray-400 font-bold">${esc(r.phone)}</p></div>
      <button class="sel-remove text-red-400 hover:text-red-600 text-lg leading-none" data-phone="${esc(r.phone)}" title="Remove">&times;</button>
    </div>`;
  });
  if (arr.length > 200) html += `<p class="text-xs text-gray-500 text-center py-2">…and ${arr.length-200} more</p>`;
  $('#selectedList').html(html);
}

/* ================================================================
   MESSAGE BUILDER — live preview, cost and merge warnings
================================================================ */
$(document).on('click','.merge-chip', function(){
  const token = $(this).data('token'), ta = $('#messageTemplate')[0];
  const start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;   // 0 is a valid position
  const end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
  ta.value = ta.value.slice(0,start) + token + ta.value.slice(end);
  ta.focus(); ta.selectionStart = ta.selectionEnd = start + token.length;
  scheduleAnalyse();
});
$('#messageTemplate').on('input', scheduleAnalyse);
let analyseTimer = null;
function scheduleAnalyse(){ clearTimeout(analyseTimer); analyseTimer = setTimeout(analyse, 150); }

function analyse(){
  const tpl = $('#messageTemplate').val() || '';
  const arr = [...selected.values()];
  const first = arr[0] || SAMPLE;
  const preview = render(tpl, first);
  $('#previewWho').text(arr[0] ? (arr[0].name || arr[0].phone) : 'a sample contact');
  $('#previewBox').text(preview || 'Start typing a message to see the live preview.');

  const p = smsPages(gsmClean(tpl));
  $('#charCount').text(tpl.length + ' characters in the template · ' + p.enc);
  // Cost over the real, merged messages (names and verses change the length)
  let units = 0, maxPages = 0;
  const missing = {};
  const used = [...new Set((tpl.match(/{{([a-z_]+)}}/g)||[]).map(t=>t.slice(2,-2)))];
  arr.forEach(r=>{
    const pg = smsPages(render(tpl, r)).pages; units += pg; if (pg>maxPages) maxPages = pg;
    used.forEach(k=>{ const v = k==='full_name' ? (r.first_name||'') : (k==='guest_name' ? (r.guest_name||r.first_name||r.name||'') : (r[k]||'')); if (!String(v).trim()) missing[k] = (missing[k]||0)+1; });
  });
  if (arr.length) $('#smsCount').text(`${units} SMS unit(s) for ${arr.length} recipient(s)` + (maxPages>1 ? ` · longest is ${maxPages} pages` : ''));
  else $('#smsCount').text(p.pages + ' SMS unit(s) per recipient');

  const warn = [];
  const unknown = used.filter(k=>!KNOWN_TOKENS.includes(k));
  if (unknown.length) warn.push(['red', `Unknown merge field(s) ${unknown.map(k=>'{{'+k+'}}').join(', ')} will be sent literally. Check the spelling.`]);
  if (/{{|}}/.test(tpl.replace(/{{[a-z_]+}}/g,''))) warn.push(['red', 'Broken merge field — braces that do not form {{field}}.']);
  Object.keys(missing).forEach(k=>{ if (KNOWN_TOKENS.includes(k)) warn.push(['amber', `${missing[k]} of ${arr.length} selected recipient(s) have no ${k.replace('_',' ')} — {{${k}}} will be blank for them.`]); });
  const raw = smsPages(tpl);
  if (raw.enc==='Unicode') {
    const fixable = raw.bad.filter(c => c in GSM_CLEAN), stuck = raw.bad.filter(c => !(c in GSM_CLEAN));
    if (stuck.length) warn.push(['amber', `These characters force Unicode (70 characters per SMS instead of 160, so more units): ${stuck.map(c=>'“'+esc(c)+'”').join(' ')}`]);
    else if (fixable.length) warn.push(['blue', `Curly quotes / long dashes (${fixable.map(c=>'“'+esc(c)+'”').join(' ')}) are converted to plain characters automatically so the message stays cheap. <button id="btnFixChars" class="underline font-bold">Convert them in the box now</button>`]);
  }
  if (maxPages > 3) warn.push(['amber', `Some messages run to ${maxPages} pages — every page is billed. Consider shortening.`]);
  $('#composeWarnings').html(warn.map(w=>{
    const cls = w[0]==='red' ? 'bg-red-50 text-red-700 border-red-100' : (w[0]==='amber' ? 'bg-amber-50 text-amber-800 border-amber-100' : 'bg-blue-50 text-hodBlue border-blue-100');
    return `<div class="text-[11px] font-semibold border rounded-lg px-3 py-2 ${cls}">${w[1]}</div>`;
  }).join(''));
  return { units, maxPages, warn, unknown };
}
$(document).on('click','#btnFixChars', function(){ const ta=$('#messageTemplate'); ta.val(gsmClean(ta.val())); scheduleAnalyse(); });

/* ================================================================
   TEMPLATES
================================================================ */
function loadTemplates(){
  api('list_templates', {}, function(res){
    if (res.status!=='success' || !res.data) return;
    let o = '<option value="">-- Saved templates --</option>';
    res.data.forEach(t=>{ o += `<option value="${+t.id}">${esc(t.name)}</option>`; });
    $('#templateSelect').html(o);
  });
}
$('#btnLoadTpl').on('click', function(){
  const id = $('#templateSelect').val();
  if (!id){ toast('Choose a template to load.','error'); return; }
  api('get_template', { id }, function(res){
    if (res.status!=='success'){ toast(res.message,'error'); return; }
    $('#messageTemplate').val(res.template.body_template||''); scheduleAnalyse(); toast('Template loaded.');
  });
});
$('#btnSaveTpl').on('click', function(){
  const name = $('#templateName').val().trim(), body = $('#messageTemplate').val();
  if (!name){ toast('Give the template a name.','error'); return; }
  if (!body.trim()){ toast('Write a message first.','error'); return; }
  api('save_template', { name, body_template: body }, function(res){ toast(res.message,res.status); if(res.status==='success'){ $('#templateName').val(''); loadTemplates(); } });
});
$('#btnDelTpl').on('click', function(){
  const id = $('#templateSelect').val();
  if (!id){ toast('Choose a template to delete.','error'); return; }
  if (!confirm('Delete this template?')) return;
  api('delete_template', { id }, function(res){ toast(res.message,res.status); if(res.status==='success') loadTemplates(); });
});

/* ================================================================
   SEND
================================================================ */
$('#btnSendTest').on('click', function(){
  const tpl = $('#messageTemplate').val();
  if (!tpl.trim()){ toast('Write a message first.','error'); return; }
  const a = analyse();
  if (a.unknown.length && !confirm('The message has an unknown merge field that will be sent literally. Send the test anyway?')) return;
  const phone = $('#testPhone').val().trim();
  if (!confirm('This sends a REAL SMS to ' + (phone || 'your own number') + ' and is billed (about ₦5–7 per page). Send the test?')) return;
  busy(true, 'Sending test…');
  api('send_test', { template:tpl, audience:currentAudience(), event_id: currentEventId(), test_phone: phone }, function(res){
    busy(false); toast(res.message, res.status);
    if (res.log_id) setTimeout(()=>openMessage(res.log_id), 400);
  });
});

$('#btnSendCampaign').on('click', function(){
  const arr = [...selected.values()];
  if (!arr.length){ toast('Select at least one recipient.','error'); return; }
  const tpl = $('#messageTemplate').val();
  if (!tpl.trim()){ toast('Write a message first.','error'); return; }
  const a = analyse();
  const h = lastHealth || {};
  const cronOk = h.cron_age_sec != null && h.cron_age_sec <= 180;
  let body = `<div class="grid grid-cols-2 gap-3">
      <div class="bg-blue-50 rounded-xl p-3"><p class="text-[10px] font-bold text-hodBlue uppercase">Recipients</p><p class="text-2xl font-black text-gray-900">${arr.length}</p></div>
      <div class="bg-amber-50 rounded-xl p-3"><p class="text-[10px] font-bold text-amber-700 uppercase">SMS units (billed)</p><p class="text-2xl font-black text-gray-900">${a.units}</p><p class="text-[10px] text-amber-700">≈ ₦${(a.units*6).toLocaleString()} at ~₦6/unit</p></div>
    </div>
    <div><p class="text-[11px] font-bold text-gray-500 uppercase mb-1">What ${esc(arr[0].name||arr[0].phone)} will receive</p>
      <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 whitespace-pre-wrap text-gray-800">${esc(render(tpl, arr[0]))}</div></div>`;
  if (a.warn.length) body += a.warn.map(w=>`<div class="text-[11px] font-semibold bg-amber-50 text-amber-800 border border-amber-100 rounded-lg px-3 py-2">${w[1].replace(/<button[\s\S]*<\/button>/,'')}</div>`).join('');
  if (!cronOk) body += `<div class="text-[11px] font-semibold bg-red-50 text-red-700 border border-red-100 rounded-lg px-3 py-2">The background sender is not running, so messages will be sent from this page — keep it open until the campaign shows 100%.</div>`;
  $('#confirmBody').html(body);
  openModal('confirmModal');
});

$('#btnConfirmSend').on('click', function(){
  const arr = [...selected.values()];
  const tpl = $('#messageTemplate').val();
  const $b = $(this).prop('disabled', true).text('Queuing…');
  api('send_campaign', {
    template: tpl, audience: currentAudience(), recipients: JSON.stringify(arr),
    title: $('#campaignTitle').val().trim(), event_id: currentEventId(), client_token: composeToken,
    filters: JSON.stringify({ audience: currentAudience(), statuses: currentStatuses(), event_id: currentEventId(), search: $('#contactSearch').val()||'' })
  }, function(res){
    $b.prop('disabled', false).text('Yes, send it');
    if (res.status !== 'success'){ toast(res.message, 'error'); return; }
    closeModal('confirmModal');
    toast(res.message);
    composeToken = newToken();            // next campaign gets a fresh token
    loadHealth();
    openCampaign(String(res.campaign_id));
  }).fail(function(){ $b.prop('disabled', false).text('Yes, send it'); });
});

/* ================================================================
   CAMPAIGNS
================================================================ */
function progressBar(c){
  const total = Math.max(1, c.total), log = c.log || {};
  const seg = (n, cls) => n>0 ? `<div class="${cls} h-full" style="width:${(n/total*100).toFixed(2)}%"></div>` : '';
  return `<div class="h-2.5 bg-gray-100 rounded-full overflow-hidden flex">
    ${seg(log.delivered||0,'bg-emerald-500')}${seg((log.sent||0)+(log.pending||0)+(log.queued||0),'bg-amber-400')}${seg(log.failed||0,'bg-red-500')}${seg(log.blocked||0,'bg-gray-400')}${seg(log.unknown||0,'bg-purple-400')}
  </div>`;
}
function countsLine(log){
  const parts = [['delivered','Delivered','text-emerald-700'],['awaiting','Awaiting report','text-amber-700'],['failed','Failed','text-red-600'],['blocked','Blocked','text-gray-600'],['unknown','Unknown','text-purple-700']];
  const v = { delivered: log.delivered||0, awaiting:(log.sent||0)+(log.pending||0)+(log.queued||0), failed: log.failed||0, blocked: log.blocked||0, unknown: log.unknown||0 };
  return parts.filter(p=>v[p[0]]>0).map(p=>`<span class="${p[2]} font-bold">${v[p[0]]} ${p[1]}</span>`).join(' · ') || '<span class="text-gray-400">Nothing sent yet</span>';
}
const STATE = { queued:['Queued','bg-sky-100 text-sky-700'], sending:['Sending','bg-amber-100 text-amber-800'], sent:['Finished','bg-emerald-100 text-emerald-700'], cancelled:['Cancelled','bg-gray-200 text-gray-700'] };
function loadCampaigns(page){
  campPage = page;
  api('list_campaigns', { page }, function(res){
    if (res.status!=='success') return;
    if (!res.data.length){ $('#campaignList').html('<div class="bg-white rounded-3xl border border-gray-100 p-10 text-center text-gray-400">No campaigns yet.</div>'); }
    else $('#campaignList').html(res.data.map(c=>{
      const st = STATE[c.state] || [c.state,'bg-gray-100 text-gray-600'];
      const left = (c.queue.queued||0)+(c.queue.sending||0);
      return `<div class="camp-row bg-white rounded-2xl border border-gray-100 shadow-sm p-4 hover:border-hodBlue/40 cursor-pointer" data-ids="${esc(c.ids)}">
        <div class="flex flex-wrap justify-between gap-2 items-start mb-2">
          <div class="min-w-0">
            <p class="font-bold text-gray-900 truncate">${esc(c.title)} <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${st[1]}">${st[0]}</span>${c.parts>1?` <span class="text-[10px] text-gray-400">(${c.parts} batches)</span>`:''}</p>
            <p class="text-[11px] text-gray-500">${esc(tLabel(c.created))}${c.created_by_name?' · by '+esc(c.created_by_name):''} · ${esc((c.audience||'').replace('_',' '))}${c.event_title?' · '+esc(c.event_title):''}</p>
          </div>
          <div class="text-right"><p class="text-sm font-black text-gray-900">${c.total} recipients</p><p class="text-[11px] text-gray-500">${naira(c.cost)}${left?` · ${left} still to send`:''}</p></div>
        </div>
        ${progressBar(c)}
        <p class="text-[11px] mt-2">${countsLine(c.log)}</p>
        <p class="text-[11px] text-gray-400 mt-1 truncate">“${esc(c.body||'')}”</p>
      </div>`;
    }).join(''));
    $('#campaignInfo').text('Page '+res.page+' of '+res.pages+' · '+res.total+' campaign(s)');
    let p = '';
    if (res.page>1) p += `<button class="camp-page px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600" data-p="${res.page-1}">Prev</button>`;
    if (res.page<res.pages) p += `<button class="camp-page px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600" data-p="${res.page+1}">Next</button>`;
    $('#campaignPages').html(p);
  });
}
$(document).on('click','.camp-page', function(){ loadCampaigns(+$(this).data('p')); });
$(document).on('click','.camp-row', function(){ openCampaign(String($(this).data('ids'))); });
$('#btnReloadCampaigns').on('click', ()=>loadCampaigns(campPage));

function openCampaign(ids){ drawerOpen('campaign', ids, true); }
function renderCampaign(ids){
  api('campaign_status', { ids }, function(res){
    if (!drawerIs('campaign', ids)) return;
    if (res.status!=='success'){ $('#drawerBody').html('<p class="text-red-500">'+esc(res.message)+'</p>'); return; }
    const c = res.campaign, done = c.total - c.remaining, pct = c.total ? Math.round(done/c.total*100) : 100;
    $('#drawerTitle').text(c.title);
    let html = `<div class="space-y-2">
        <div class="flex justify-between text-sm font-bold"><span>${done} of ${c.total} handed to BulkSMS</span><span>${pct}%</span></div>
        ${progressBar(c)}
        <p class="text-[12px]">${countsLine(c.log)}</p>
      </div>`;
    if (c.remaining > 0 && !c.cancelled) {
      html += res.cron_ok
        ? `<div class="text-[11px] bg-emerald-50 text-emerald-700 border border-emerald-100 rounded-lg px-3 py-2 font-semibold">Sending in the background (about one message per second). You can close this.</div>`
        : `<div class="text-[11px] bg-red-50 text-red-700 border border-red-100 rounded-lg px-3 py-2 font-semibold">The background sender is not running (last run ${secsLabel(res.cron_age_sec)}). Sending from this page now — keep it open until 100%.</div>`;
    }
    html += `<div class="grid grid-cols-2 gap-3 text-sm">
        ${kv('Created', tLabel(c.created))}${kv('Created by', c.created_by_name||'—')}
        ${kv('First message sent', tLabel(c.first_sent))}${kv('Last message sent', tLabel(c.last_sent))}
        ${kv('Audience', (c.audience||'').replace('_',' '))}${kv('Event', c.event_title||'—')}
        ${kv('Cost at submission', naira(c.cost))}${kv('Still to send', c.remaining)}
      </div>
      <div><p class="text-[11px] font-bold text-gray-500 uppercase mb-1">Message template</p><div class="bg-gray-50 border border-gray-200 rounded-xl p-3 whitespace-pre-wrap text-sm">${esc(c.body)}</div></div>
      <div class="flex flex-wrap gap-2">
        <button class="drawer-hist px-4 py-2.5 rounded-xl text-xs font-bold bg-hodBlue text-white" data-ids="${esc(c.ids)}">See every recipient in History</button>
        ${c.remaining>0 && !c.cancelled ? `<button class="drawer-cancel px-4 py-2.5 rounded-xl text-xs font-bold bg-red-50 text-red-600 hover:bg-red-100" data-ids="${esc(c.ids)}">Stop — cancel unsent messages</button>` : ''}
        ${c.cancelled ? '<span class="px-3 py-2 rounded-xl text-xs font-bold bg-gray-100 text-gray-600">Cancelled</span>' : ''}
      </div>`;
    $('#drawerBody').html(html);
    clearTimeout(campTimer);
    if (c.remaining > 0 && !c.cancelled && !res.cron_ok) driveQueue(false);
    campTimer = setTimeout(()=>{ if (drawerIs('campaign', ids)) renderCampaign(ids); }, c.remaining > 0 ? 4000 : 20000);
  });
}
$(document).on('click','.drawer-hist', function(){
  const ids = String($(this).data('ids'));
  drawerClose(); histCampaign = { ids, title: $('#drawerTitle').text() }; histPhone = ''; histStatus = '';
  switchTab('history');
});
$(document).on('click','.drawer-cancel', function(){
  if (!confirm('Stop this campaign? Messages already handed to BulkSMS cannot be recalled; everything not yet sent will be cancelled.')) return;
  const ids = String($(this).data('ids'));
  api('cancel_campaign', { ids }, function(res){ toast(res.message, res.status); renderCampaign(ids); loadHealth(); });
});
function kv(k, v, raw){ return `<div class="bg-gray-50 rounded-xl px-3 py-2"><p class="text-[10px] font-bold text-gray-400 uppercase">${esc(k)}</p><p class="text-gray-800 font-semibold break-words">${raw ? v : esc(v)}</p></div>`; }

/* ================================================================
   HISTORY
================================================================ */
function loadHistory(page, quiet){
  histPage = page;
  const data = { page, search:$('#historySearch').val()||'', status: histStatus, date_from: $('#histFrom').val(), date_to: $('#histTo').val(), phone: histPhone };
  if (histCampaign) data.campaign_ids = histCampaign.ids;
  api('get_history', data, function(res){
    if (res.status!=='success'){ if(!quiet) toast(res.message,'error'); return; }
    renderHistChips(res.counts);
    const rows = res.data || [];
    let html = '';
    if (!rows.length) html = '<tr><td colspan="7" class="px-5 py-10 text-center text-gray-400 font-medium">No messages match.</td></tr>';
    rows.forEach(l=>{
      const note = l.error_message && ['failed','blocked','unknown','pending'].includes(l.status) ? l.error_message : '';
      html += `<tr class="hist-row hover:bg-blue-50/40 cursor-pointer transition-colors" data-id="${+l.id}" data-status="${esc(l.status)}">
        <td class="px-5 py-3 min-w-[170px]"><p class="font-bold text-gray-900">${esc(l.recipient_name||'—')}</p><p class="text-[10px] text-gray-400">${esc(l.campaign_title||'Test / manual')}${l.sent_by?' · by '+esc(l.sent_by):''}</p></td>
        <td class="px-5 py-3 text-sm"><button class="person-link text-hodBlue font-semibold hover:underline" data-phone="${esc(l.recipient_phone)}">${esc(l.recipient_phone)}</button></td>
        <td class="px-5 py-3 max-w-[240px]">${stChip(l.status, l.message_id)}${note?`<p class="text-[10px] text-gray-500 mt-1 line-clamp-2">${esc(note)}</p>`:''}</td>
        <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap" title="${esc(tLabel(l.created))}">${esc(l.created ? l.created.short : '—')}</td>
        <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap" title="${esc(tLabel(l.final))}">${l.final ? esc(l.final.short) : '<span class="text-gray-400">—</span>'}</td>
        <td class="px-5 py-3 text-sm whitespace-nowrap">${naira(l.cost)}</td>
        <td class="px-4 py-3 text-right whitespace-nowrap"><button class="hist-open bg-blue-50 hover:bg-blue-100 text-hodBlue text-[11px] font-bold px-3 py-1.5 rounded-lg">Details</button></td>
      </tr>`;
    });
    $('#historyBody').html(html);
    $('#historyInfo').text('Page '+res.page+' of '+res.pages+' · '+res.total+' message(s) · '+naira(res.spend)+' at submission');
    let p = '';
    if (res.page>1) p += `<button class="hist-page px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600" data-p="${res.page-1}">Prev</button>`;
    if (res.page<res.pages) p += `<button class="hist-page px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-600" data-p="${res.page+1}">Next</button>`;
    $('#historyPages').html(p);
    renderHistFilters();
  });
}
function renderHistChips(c){
  const chips = [['','All',c.all],['delivered','Delivered',c.delivered],['awaiting','Awaiting report',c.awaiting],['failed','Failed',c.failed],['blocked','Blocked',c.blocked],['unknown','Unknown',c.unknown]];
  $('#histStatusChips').html(chips.map(ch=>`<button class="hist-chip px-3 py-1.5 rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-600 ${histStatus===ch[0]?'sms-chip-on':''}" data-s="${ch[0]}">${ch[1]} <span class="opacity-70">${ch[2]}</span></button>`).join(''));
}
function renderHistFilters(){
  let f = '';
  if (histCampaign) f += `<span class="px-3 py-1.5 rounded-full bg-blue-50 text-hodBlue text-xs font-bold">Campaign: ${esc(histCampaign.title||'#'+histCampaign.ids)} <button class="clear-filter ml-1" data-f="campaign">&times;</button></span>`;
  if (histPhone) f += `<span class="px-3 py-1.5 rounded-full bg-blue-50 text-hodBlue text-xs font-bold">Number: ${esc(histPhone)} <button class="clear-filter ml-1" data-f="phone">&times;</button></span>`;
  $('#histActiveFilters').html(f);
}
$(document).on('click','.hist-chip', function(){ histStatus = String($(this).data('s')); loadHistory(1); });
$(document).on('click','.clear-filter', function(){ if ($(this).data('f')==='campaign') histCampaign = null; else histPhone = ''; loadHistory(1); });
$(document).on('click','.hist-page', function(){ loadHistory(+$(this).data('p')); });
$('#historySearch').on('input', function(){ clearTimeout(histTimer); histTimer = setTimeout(()=>loadHistory(1), 400); });
$('#histFrom, #histTo').on('change', ()=>loadHistory(1));
$(document).on('click','.hist-row', function(e){ if ($(e.target).closest('.person-link').length) return; openMessage(+$(this).data('id')); });
$(document).on('click','.person-link', function(e){ e.stopPropagation(); openPerson(String($(this).data('phone'))); });

// Quiet background refresh: one batched delivery check for the visible rows awaiting a report.
function startHistPoll(){ stopHistPoll(); histPollTimer = setInterval(pollHistory, 30000); }
function stopHistPoll(){ if (histPollTimer){ clearInterval(histPollTimer); histPollTimer = null; } }
function pollHistory(){
  if (currentTab!=='history' || document.hidden) return;
  const ids = $('#historyBody .hist-row').filter(function(){ return ['sent','pending'].includes($(this).data('status')); }).map(function(){ return $(this).data('id'); }).get().slice(0,20);
  const reload = ()=>loadHistory(histPage, true);
  if (!ids.length) return reload();
  api('refresh_statuses', { ids: ids.join(',') }, reload);
}

/* ================================================================
   DRAWER — message, person and campaign views with a Back stack
================================================================ */
function drawerOpen(kind, arg, reset){
  if (reset) drawerStack = [];
  drawerStack.push({kind, arg});
  $('#drawerBack').toggleClass('hidden', drawerStack.length < 2);
  $('#drawerOverlay').removeClass('hidden');
  $('#smsDrawer').removeClass('translate-x-full');
  $('#drawerBody').html('<p class="text-gray-400 text-sm">Loading…</p>');
  if (kind==='message') renderMessage(arg);
  else if (kind==='person') renderPerson(arg);
  else renderCampaign(arg);
}
function drawerIs(kind, arg){ const top = drawerStack[drawerStack.length-1]; return top && top.kind===kind && String(top.arg)===String(arg); }
function drawerClose(){ drawerStack = []; clearTimeout(campTimer); $('#smsDrawer').addClass('translate-x-full'); $('#drawerOverlay').addClass('hidden'); }
$('#drawerClose, #drawerOverlay').on('click', drawerClose);
$(document).on('keydown', function(e){ if (e.key==='Escape') drawerClose(); });
$('#drawerBack').on('click', function(){ drawerStack.pop(); const prev = drawerStack.pop(); if (prev) drawerOpen(prev.kind, prev.arg); });
function openMessage(id){ drawerOpen('message', id, !$('#smsDrawer').is(':visible') || $('#smsDrawer').hasClass('translate-x-full')); }
function openPerson(phone){ drawerOpen('person', phone, !$('#smsDrawer').is(':visible') || $('#smsDrawer').hasClass('translate-x-full')); }

function renderMessage(id){
  api('get_message', { id }, function(res){
    if (!drawerIs('message', id)) return;
    if (res.status!=='success'){ $('#drawerBody').html('<p class="text-red-500">'+esc(res.message)+'</p>'); return; }
    const m = res.message;
    $('#drawerTitle').text('Message #' + m.id + ' · ' + (m.recipient_name || m.recipient_phone));
    // Timeline
    let tl = '<ol class="sms-timeline">';
    m.timeline.forEach(e=>{
      const s = ST[e.to] || {label:e.to, dot:'bg-gray-400'};
      const note = e.from !== null && e.from === e.to;
      tl += `<li class="relative pl-7 pb-4">
        <span class="sms-rail absolute left-[7px] top-4 bottom-0 w-0.5 bg-gray-200"></span>
        <span class="absolute left-0 top-1 w-4 h-4 rounded-full ring-4 ring-white ${note?'bg-gray-300':s.dot}"></span>
        <p class="text-sm font-bold text-gray-900">${note ? 'Note' : esc(s.label)}${e.synthetic?' <span class="text-[10px] font-semibold text-gray-400">(reconstructed)</span>':''}</p>
        <p class="text-[12px] font-semibold text-hodBlue">${esc(tLabel(e.at))}</p>
        <p class="text-[11px] text-gray-500">${esc(SRC[e.source]||e.source)}${e.by?' · '+esc(e.by):''}${e.raw?' · carrier/API said: <b>'+esc(e.raw)+'</b>':''}${e.code?' · code '+esc(e.code):''}</p>
        ${e.provider_time?`<p class="text-[11px] text-gray-500">Time reported by BulkSMS/carrier: <b>${esc(e.provider_time)}</b></p>`:''}
        ${e.detail?`<p class="text-[11px] text-gray-600 mt-0.5">${esc(e.detail)}</p>`:''}
      </li>`;
    });
    tl += '</ol>';
    const n = m.number || {}, sp = n.suppression;
    const byS = Object.entries(n.by_status||{}).map(([k,v])=>`${v} ${(ST[k]||{label:k}).label.split(' ·')[0].toLowerCase()}`).join(', ');
    let html = `
      <div class="flex flex-wrap items-center gap-2">${stChip(m.status, m.message_id)}
        <button class="person-link text-sm font-bold text-hodBlue hover:underline" data-phone="${esc(m.recipient_phone)}">${esc(m.recipient_phone)}</button>
        <span class="text-xs text-gray-500">${esc(m.recipient_name||'')}</span></div>
      ${m.error_message && m.status!=='delivered' ? `<div class="text-[12px] bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-gray-700">${esc(m.error_message)}${m.api_code_text?`<br><span class="text-gray-500">${esc(m.api_code)}: ${esc(m.api_code_text)}</span>`:''}</div>` : ''}
      <div><p class="text-[11px] font-bold text-gray-500 uppercase mb-1">Message sent</p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 whitespace-pre-wrap text-sm text-gray-800">${esc(m.body||'')}</div>
        <p class="text-[11px] text-gray-400 mt-1">${m.segments.chars} characters · ${esc(m.segments.encoding)} · ${m.segments.pages} SMS page(s)</p></div>
      <div><p class="text-[11px] font-bold text-gray-500 uppercase mb-2">Status movements (WAT)</p>${tl}</div>
      <div class="grid grid-cols-2 gap-2 text-sm">
        ${kv('First recorded', tLabel(m.created))}${kv('Last change', tLabel(m.updated))}
        ${kv('Campaign', m.campaign ? m.campaign.title : 'Test / manual send')}${kv('Sent by', m.sent_by || (m.campaign ? 'Background sender' : '—'))}
        ${kv('BulkSMS message ID', m.message_id || 'none (never reached BulkSMS)')}${kv('Sender ID · route', (m.sender_id||'—') + ' · ' + (m.gateway||'—'))}
        ${kv('Cost at submission', naira(m.cost))}${kv('Last delivery check', tLabel(m.dlr_checked))}
      </div>
      <div class="bg-white border border-gray-100 rounded-2xl p-4 space-y-2">
        <p class="text-[11px] font-bold text-gray-500 uppercase">Who is this?</p>
        ${m.person ? `<p class="text-sm"><b>${esc(m.person.name||'—')}</b> · ${esc(m.person.kind)}</p>
          <p class="text-[12px] text-gray-600">${esc(m.person.status||'')}${m.person.email?' · '+esc(m.person.email):''}${m.person.phone_on_file?' · on file as '+esc(m.person.phone_on_file):''}</p>` : '<p class="text-sm text-gray-500">No linked record (test or manual send).</p>'}
        <p class="text-[12px] text-gray-600">This number has received <b>${n.total||0}</b> message(s)${byS?' ('+esc(byS)+')':''} · ${naira(n.cost)} in total.</p>
        ${sp && !sp.released_at ? `<p class="text-[12px] font-bold text-red-600">Suppressed since ${esc(tLabel(sp.suppressed))}: ${esc(sp.reason||'')}</p>` : ''}
        <button class="person-link text-xs font-bold text-hodBlue hover:underline" data-phone="${esc(m.recipient_phone)}">See every message to this number →</button>
      </div>
      <div class="flex flex-wrap gap-2">
        ${m.message_id && ['sent','pending','unknown','failed'].includes(m.status) ? `<button class="msg-check px-4 py-2.5 rounded-xl text-xs font-bold bg-amber-50 text-amber-700 hover:bg-amber-100" data-id="${m.id}">⟳ Check delivery now</button>` : ''}
        ${m.can_resend ? `<button class="msg-resend px-4 py-2.5 rounded-xl text-xs font-bold bg-blue-50 text-hodBlue hover:bg-blue-100" data-id="${m.id}" data-status="${esc(m.status)}">↻ Resend</button>` : ''}
        ${m.campaign ? `<button class="drawer-camp px-4 py-2.5 rounded-xl text-xs font-bold bg-gray-100 text-gray-700 hover:bg-gray-200" data-id="${m.campaign.id}">Open campaign</button>` : ''}
      </div>`;
    if (m.webhooks && m.webhooks.length) {
      html += `<details class="bg-gray-50 rounded-xl p-3"><summary class="text-xs font-bold text-gray-600 cursor-pointer">Delivery reports received from BulkSMS (${m.webhooks.length})</summary>
        <div class="mt-2 space-y-2">${m.webhooks.map(w=>`<div class="text-[11px] bg-white rounded-lg p-2 border border-gray-100"><b>${esc(tLabel(w.at))}</b> · said “${esc(w.parsed_status||'')}” → ${esc(w.applied_status||'—')} · from ${esc(w.remote_ip||'?')}<pre class="whitespace-pre-wrap break-all text-gray-500 mt-1">${esc(w.raw_body||'')}</pre></div>`).join('')}</div></details>`;
    }
    if (m.raw_response) html += `<details class="bg-gray-50 rounded-xl p-3"><summary class="text-xs font-bold text-gray-600 cursor-pointer">BulkSMS reply to the send request</summary><pre class="mt-2 text-[11px] whitespace-pre-wrap break-all text-gray-600">${esc(m.raw_response)}</pre></details>`;
    $('#drawerBody').html(html);
  });
}
$(document).on('click','.drawer-camp', function(){ drawerOpen('campaign', String($(this).data('id'))); });
$(document).on('click','.msg-check', function(){
  const id = +$(this).data('id'); const $b = $(this).prop('disabled', true).text('Checking…');
  api('check_delivery', { id }, function(res){ toast(res.message, res.status); $b.prop('disabled', false); renderMessage(id); if (currentTab==='history') loadHistory(histPage, true); });
});
$(document).on('click','.msg-resend', function(){ resendOne(+$(this).data('id'), String($(this).data('status')), false); });
function resendOne(id, status, confirmed){
  if (!confirmed) {
    const q = status==='delivered' ? 'This message was already DELIVERED. Send it to the same person again (billed)?' : 'Resend this message? It is a REAL, billed SMS.';
    if (!confirm(q)) return;
  }
  busy(true, 'Resending…');
  api('resend_recipient', { id, confirm_unknown: confirmed ? 1 : '' }, function(res){
    busy(false);
    if (res.status==='confirm'){ if (confirm(res.message)) resendOne(id, status, true); return; }
    toast(res.message, res.status);
    if (res.log_id) drawerOpen('message', res.log_id);
    if (currentTab==='history') loadHistory(histPage, true);
  });
}

function renderPerson(phone){
  api('get_person', { phone }, function(res){
    if (!drawerIs('person', phone)) return;
    if (res.status!=='success'){ $('#drawerBody').html('<p class="text-red-500">'+esc(res.message)+'</p>'); return; }
    const p = res.person, sp = p.suppression;
    $('#drawerTitle').text((p.names[0] || 'Number') + ' · ' + p.phone);
    const cnt = Object.entries(p.counts).map(([k,v])=>`<span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase ${(ST[k]||{cls:'bg-gray-100'}).cls}">${v} ${esc((ST[k]||{label:k}).label.split(' ·')[0])}</span>`).join(' ');
    let html = `
      <div class="grid grid-cols-2 gap-2 text-sm">
        ${kv('Phone', p.phone)}${kv('Messages', p.total + (p.total>=200?' (latest 200)':''))}
        ${kv('First message', tLabel(p.first))}${kv('Latest message', tLabel(p.last))}
        ${kv('Spent at submission', naira(p.cost))}${kv('Names used', p.names.join(', ') || '—')}
      </div>
      <div class="flex flex-wrap gap-1.5">${cnt}</div>
      ${p.people.length ? `<div class="bg-white border border-gray-100 rounded-2xl p-3"><p class="text-[11px] font-bold text-gray-500 uppercase mb-1">On file in the church database</p>${p.people.map(x=>`<p class="text-sm"><b>${esc(x.name)}</b> · ${esc((x.status||'').replace(/_/g,' '))}${x.email?' · '+esc(x.email):''}</p>`).join('')}</div>` : ''}
      ${sp ? `<div class="rounded-xl px-3 py-2 text-[12px] font-semibold ${sp.released_at?'bg-gray-50 text-gray-600':'bg-red-50 text-red-700'}">${sp.released_at ? 'Was suppressed; released '+esc(tLabel(sp.released)) : 'Suppressed since '+esc(tLabel(sp.suppressed))+' — '+esc(sp.reason||'')}</div>` : ''}
      <div class="flex gap-2">
        <button class="person-hist px-4 py-2 rounded-xl text-xs font-bold bg-hodBlue text-white" data-phone="${esc(p.phone)}">Filter History to this number</button>
        ${!sp || sp.released_at ? `<button class="person-supp px-4 py-2 rounded-xl text-xs font-bold bg-red-50 text-red-600" data-phone="${esc(p.phone)}">Suppress this number</button>` : ''}
      </div>
      <div><p class="text-[11px] font-bold text-gray-500 uppercase mb-2">Every message (newest first) — click one for its full timeline</p>
        <div class="space-y-2">${p.messages.map(x=>`<div class="person-msg border border-gray-100 rounded-xl p-3 hover:border-hodBlue/40 cursor-pointer" data-id="${+x.id}">
            <div class="flex justify-between gap-2 items-start">${stChip(x.status, x.message_id)}<span class="text-[11px] text-gray-500 text-right">Sent ${esc(tLabel(x.created))}${x.final?'<br>Final '+esc(tLabel(x.final)):''}</span></div>
            <p class="text-[12px] text-gray-800 mt-1 line-clamp-2">${esc(x.body||'')}</p>
            <p class="text-[10px] text-gray-400 mt-1">${esc(x.campaign_title||'Test / manual')}${x.sent_by?' · by '+esc(x.sent_by):''} · ${naira(x.cost)}</p>
          </div>`).join('') || '<p class="text-sm text-gray-400">No messages.</p>'}</div></div>`;
    $('#drawerBody').html(html);
  });
}
$(document).on('click','.person-msg', function(){ drawerOpen('message', +$(this).data('id')); });
$(document).on('click','.person-hist', function(){ histPhone = String($(this).data('phone')); histCampaign = null; histStatus=''; drawerClose(); switchTab('history'); });
$(document).on('click','.person-supp', function(){
  const phone = String($(this).data('phone'));
  const reason = prompt('Suppress ' + phone + '? They will not receive SMS until released.\n\nReason:', 'Asked to stop');
  if (reason === null) return;
  api('add_suppression', { phone, reason }, function(res){ toast(res.message, res.status); renderPerson(phone); });
});

/* ================================================================
   SUPPRESSION
================================================================ */
function loadSuppression(){
  api('list_suppression', {}, function(res){
    if (res.status!=='success'){ $('#suppressionList').html('<div class="p-6 text-center text-red-500 text-sm">'+esc(res.message)+'</div>'); return; }
    const rows = res.data || [];
    if (!rows.length){ $('#suppressionList').html('<div class="p-6 text-center text-gray-400 text-sm">No suppressed numbers.</div>'); return; }
    $('#suppressionList').html(rows.map(s=>{
      const active = !s.released_at;
      return `<div class="flex items-center justify-between px-4 py-3 gap-3">
        <div class="flex-1 min-w-0">
          <p class="font-bold text-gray-800 text-sm"><button class="person-link hover:underline" data-phone="${esc(s.phone)}">${esc(s.phone)}</button>
            <span class="${active?'bg-red-100 text-red-700':'bg-gray-100 text-gray-500'} text-[10px] font-bold uppercase px-2 py-0.5 rounded-full">${active?'Active':'Released'}</span></p>
          <p class="text-[11px] text-gray-600 truncate">${esc(s.reason||'')}${s.note?' · '+esc(s.note):''}</p>
          <p class="text-[10px] text-gray-400">Suppressed ${esc(tLabel(s.suppressed))}${+s.consecutive_failures?` · ${+s.consecutive_failures} failures in a row, last ${esc(tLabel(s.last_failed))}`:''}${s.released?` · released ${esc(tLabel(s.released))}${s.released_by?' by '+esc(s.released_by):''}`:''}</p>
        </div>
        ${active?`<button class="supp-release bg-blue-50 hover:bg-blue-100 text-hodBlue text-[11px] font-bold px-3 py-1.5 rounded-lg" data-id="${+s.id}">Release</button>`:''}
      </div>`;
    }).join(''));
  });
}
$('#btnReloadSupp').on('click', loadSuppression);
$(document).on('click','.supp-release', function(){
  if (!confirm('Release this number so it can receive SMS again?')) return;
  api('release_suppression', { id: +$(this).data('id') }, function(res){ toast(res.message,res.status); loadSuppression(); });
});
$('#btnAddSupp').on('click', function(){
  const phone = $('#suppressPhone').val().trim();
  if (!phone){ toast('Enter a phone number.','error'); return; }
  if (!confirm('Suppress this number? It will not receive SMS until released.')) return;
  api('add_suppression', { phone, reason: $('#suppressReason').val().trim() }, function(res){
    toast(res.message,res.status); if (res.status==='success'){ $('#suppressPhone,#suppressReason').val(''); } loadSuppression();
  });
});

/* ================================================================
   SETTINGS
================================================================ */
let webhookSuggestion = '';
function loadSettingsStatus(){
  api('settings_status', {}, function(res){
    if (res.status!=='success') return;
    const m = res.masked || {};
    $('#maskBase').text(m.base_url||'—'); $('#maskToken').text(m.api_token||'not set'); $('#maskSender').text(m.sender_id||'not set');
    $('#maskGateway').text(m.gateway||'—'); $('#maskWebhook').text(m.webhook_url||'—');
    $('#settingsLocked').removeClass('hidden'); $('#settingsUnlocked').addClass('hidden');
  });
}
function renderChecklist(){
  const h = lastHealth || {};
  const item = (ok, text, fix) => `<li class="flex gap-2"><span class="${ok?'text-emerald-600':'text-red-500'} font-black">${ok?'✓':'✕'}</span><span>${text}${!ok && fix ? `<br><span class="text-[11px] text-gray-500">${fix}</span>` : ''}</span></li>`;
  $('#setupChecklist').html(
    item(h.vault_ok, 'SMS_VAULT_KEY is set in .env', 'Add a 64-hex-character SMS_VAULT_KEY to .env on the server.') +
    item(h.configured && !(h.decrypt_failed||[]).length, 'BulkSMS token and sender ID saved', 'Unlock above and save the API token and an approved sender ID.') +
    item(h.schema_ready, 'Database is up to date', 'Run php db/migrate.php (the deploy does this automatically).') +
    item(h.cron_age_sec != null && h.cron_age_sec <= 180, 'Background sender cron is running' + (h.cron_age_sec!=null ? ' (last run ' + secsLabel(h.cron_age_sec) + ')' : ''), 'Add the cron line below in cPanel → Cron Jobs.') +
    item(h.webhook_secret_set, 'SMS_WEBHOOK_SECRET is set in .env (optional)', 'Add SMS_WEBHOOK_SECRET to .env to receive instant delivery reports.') +
    item(h.webhook_url_set, 'Delivery webhook URL saved (optional)', 'Unlock above and click "Use this site\'s webhook URL". Without it the sender polls BulkSMS instead.')
  );
}
$('#btnUnlock').on('click', unlockSettings);
$('#unlockPwd').on('keydown', function(e){ if (e.key==='Enter') unlockSettings(); });
function unlockSettings(){
  const pwd = $('#unlockPwd').val();
  if (!pwd){ toast('Enter your password.','error'); return; }
  api('unlock_settings', { password:pwd }, function(res){
    if (res.status!=='success'){ toast(res.message,'error'); return; }
    const s = res.settings || {};
    $('#sBase').val(s.base_url || 'https://www.bulksmsnigeria.com/api/v2'); $('#sToken').val(s.api_token||''); $('#sSender').val(s.sender_id||'');
    $('#sGateway').val(s.gateway||''); $('#sWebhook').val(s.webhook_url||'');
    webhookSuggestion = res.webhook_suggestion || '';
    $('#btnUseWebhook').toggleClass('opacity-50', !webhookSuggestion);
    $('#unlockPwd').val(''); $('#settingsLocked').addClass('hidden'); $('#settingsUnlocked').removeClass('hidden');
    toast('Unlocked for 10 minutes.');
  });
}
$('#btnUseWebhook').on('click', function(){
  if (!webhookSuggestion){ toast('Set SMS_WEBHOOK_SECRET in the server .env first, then unlock again.','error'); return; }
  $('#sWebhook').val(webhookSuggestion); toast('Webhook URL filled in. Save, then paste the same URL in your BulkSMS dashboard.', 'info');
});
$('#btnLock').on('click', function(){ api('lock_settings', {}, function(){ toast('Settings locked.'); loadSettingsStatus(); }); });
$('#btnTestConn').on('click', function(){ busy(true, 'Contacting BulkSMS…'); api('test_connection', {}, function(res){ busy(false); toast(res.message, res.status); }); });
$('#btnFetchSenders').on('click', function(){
  api('list_sender_ids', {}, function(res){
    if (res.status!=='success'){ toast(res.message,'error'); return; }
    const ids = res.data || [];
    if (!ids.length){ toast('No sender IDs found on your BulkSMS account.','error'); $('#senderIdsBox').addClass('hidden'); return; }
    $('#senderIdsList').html(ids.map(s=>{
      const name = typeof s === 'string' ? s : (s.sender_id || s.name || '');
      const status = typeof s === 'object' ? (s.status || '') : '';
      // data-attribute, not inline JS: a sender ID containing a quote used to break out of the onclick handler
      return `<button type="button" class="pick-sender bg-white border border-purple-300 text-purple-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-purple-100" data-v="${esc(name)}">${esc(name)} ${status?`(${esc(status)})`:''}</button>`;
    }).join(''));
    $('#senderIdsBox').removeClass('hidden');
  });
});
$(document).on('click','.pick-sender', function(){ $('#sSender').val(String($(this).data('v'))); });
$('#settingsForm').on('submit', function(e){
  e.preventDefault();
  api('save_settings', { base_url:$('#sBase').val(), api_token:$('#sToken').val(), sender_id:$('#sSender').val(), gateway:$('#sGateway').val(), webhook_url:$('#sWebhook').val() }, function(res){
    toast(res.message,res.status);
    if (res.status==='success'){ loadSettingsStatus(); loadHealth(); }
  });
});

/* ================================================================
   INIT
================================================================ */
$(function(){
  // <main> is animated with a transform and z-0, which traps position:fixed children
  // under the app header. Lift the drawer and modals out to <body>.
  $('#drawerOverlay, #smsDrawer, #confirmModal, #busyOverlay').appendTo('body');
  api('fetch_events', {}, function(res){
    if (res.status!=='success' || !res.data) return;
    const opts = res.data.map(e=>`<option value="${+e.id}">${esc(e.title)}${e.date_label?' · '+esc(e.date_label):''}</option>`).join('');
    $('#regEventSelect').html('<option value="">-- Choose an event --</option>' + opts);
    $('#checkinEventSelect').html('<option value="">-- Every event --</option>' + opts);
  });
  loadHealth();
  healthTimer = setInterval(()=>{ if (!document.hidden) loadHealth(); }, 30000);

  const q = new URLSearchParams(location.search);
  if (q.get('phone')) { histPhone = q.get('phone'); switchTab('history'); }
  else if (q.get('campaign')) { switchTab('campaigns'); openCampaign(q.get('campaign')); }
  else switchTab(['compose','campaigns','history','suppression','settings'].includes(q.get('tab')) ? q.get('tab') : 'compose');
  if (q.get('msg')) openMessage(+q.get('msg'));
  analyse();
});
</script>
<?php require_once '../../includes/footer.php'; ?>
