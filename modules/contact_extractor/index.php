<?php
/**
 * ============================================================================
 * CONTACT BATCH EXTRACTOR — Frontend page
 * File: /modules/contact_extractor/index.php
 * ----------------------------------------------------------------------------
 * Gathers all unique contacts (deduplicated) from users + event_registrations
 * + idi_mobilization, then lets you split them into N batches with a chosen
 * separator and copy any single batch.
 * ============================================================================
 */
session_start();
if (empty($_SESSION['ce_csrf'])) $_SESSION['ce_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['ce_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact Batch Extractor</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<style>
  body{ background:#f1f5f9; }
  .bg-hod{ background-color:#123b8c; }
  .bg-hod-dark{ background-color:#152750; }
  .custom-scrollbar::-webkit-scrollbar{ width:8px; height:8px; }
  .custom-scrollbar::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:8px; }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-5xl mx-auto p-4 md:p-6 space-y-6 pb-16">

  <!-- Top bar -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 md:p-5 flex flex-col md:flex-row gap-4 md:items-center justify-between">
    <div class="flex items-center gap-4">
      <a href="/modules/events/index.php" class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3.5 py-2.5 rounded-xl text-sm font-bold transition-colors">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Events
      </a>
      <div>
        <h1 class="text-xl md:text-2xl font-extrabold text-gray-900 tracking-tight">Contact Batch Extractor</h1>
        <p class="text-gray-500 text-xs font-medium mt-0.5">Gather all contacts (deduplicated) and split into copy-ready batches.</p>
      </div>
    </div>
    <button onclick="openModal()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-5 py-3 rounded-xl font-bold shadow-md transition-colors flex items-center justify-center gap-2">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
      Copy Contacts
    </button>
  </div>

  <!-- Sources summary -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
    <h3 class="text-lg font-bold text-gray-900 mb-3">Contact sources (deduplicated)</h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" id="sourceCards">
      <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4">
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Users</p>
        <p class="text-2xl font-black text-gray-900" id="cardUsers">—</p>
      </div>
      <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4">
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Event Registrations</p>
        <p class="text-2xl font-black text-gray-900" id="cardReg">—</p>
      </div>
      <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4">
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">IDI Mobilization</p>
        <p class="text-2xl font-black text-gray-900" id="cardMob">—</p>
      </div>
    </div>
    <p class="text-sm text-gray-500 mt-4">Total unique contacts: <span id="totalContacts" class="font-black text-[#123b8c]">—</span> (phones normalized to international format, duplicates removed)</p>
  </div>

  <!-- Preview of all contacts (read-only) -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-lg font-bold text-gray-900">All Contacts</h3>
      <button onclick="copyAll()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-xl text-xs font-bold transition-colors">Copy all</button>
    </div>
    <div id="allContactsBox" class="bg-gray-50 border border-gray-100 rounded-2xl p-4 text-sm text-gray-700 whitespace-pre-wrap max-h-72 overflow-y-auto custom-scrollbar">
      Load contacts to see the full list.
    </div>
  </div>

</div>

<!-- ==================== MODAL ==================== -->
<div id="ceModal" class="fixed inset-0 hidden z-[9999] items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden max-h-[90vh] flex flex-col">
    <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center shrink-0">
      <h3 class="text-xl font-bold text-gray-900">Batch Contacts</h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>

    <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar">
      <!-- Separator -->
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Separator</label>
        <div class="grid grid-cols-3 gap-2" id="sepOptions">
          <label class="flex items-center justify-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 cursor-pointer hover:border-[#123b8c]">
            <input type="radio" name="separator" value="semicolon" checked onchange="updateSeparator()" class="w-4 h-4 text-[#123b8c]"> <span class="text-sm font-bold">Semi-colon (;)</span>
          </label>
          <label class="flex items-center justify-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 cursor-pointer hover:border-[#123b8c]">
            <input type="radio" name="separator" value="comma" onchange="updateSeparator()" class="w-4 h-4 text-[#123b8c]"> <span class="text-sm font-bold">Comma (,)</span>
          </label>
          <label class="flex items-center justify-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 cursor-pointer hover:border-[#123b8c]">
            <input type="radio" name="separator" value="newline" onchange="updateSeparator()" class="w-4 h-4 text-[#123b8c]"> <span class="text-sm font-bold">New line</span>
          </label>
        </div>
      </div>

      <!-- Batch count -->
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Number of batches</label>
        <input type="number" id="batchCount" min="1" max="50" value="4" oninput="updateSeparator()" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#123b8c] outline-none font-bold text-lg">
        <p class="text-[11px] text-gray-400 mt-1">e.g. 4 splits all contacts into 4 roughly equal batches.</p>
      </div>

      <!-- Batches output -->
      <div id="batchesOutput" class="space-y-4">
        <div class="p-6 text-center text-gray-400 text-sm border border-dashed border-gray-200 rounded-2xl">
          Enter batch count, then click <b>Generate</b>.
        </div>
      </div>
    </div>

    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50 flex justify-end gap-2 shrink-0">
      <button onclick="closeModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2.5 rounded-xl font-bold transition-colors">Close</button>
      <button onclick="generateBatches()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-6 py-2.5 rounded-xl font-bold shadow-md transition-colors">Generate</button>
    </div>
  </div>
</div>

<script>
const API = '/api/contact_extractor_api.php';
const CSRF = <?= json_encode($CSRF) ?>;
let allContacts = [];   // array of normalized unique phones

function toast(msg, type='success'){ Toastify({ text:msg, gravity:"top", position:"center", duration:2500, style:{ background: type==='success'?'#10B981':'#EF4444', borderRadius:'10px', fontWeight:'bold' } }).showToast(); }
function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function api(action, extra, cb){ const d = Object.assign({ action, _csrf: CSRF }, extra||{}); return $.post(API, d, cb, 'json'); }

/* ---------- load contacts ---------- */
function loadContacts(){
  api('fetch_contacts', {}, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    allContacts = res.contacts || [];
    const b = res.source_breakdown || {};
    $('#cardUsers').text(b.users||0);
    $('#cardReg').text(b.registrations||0);
    $('#cardMob').text(b.mobilization||0);
    $('#totalContacts').text(allContacts.length);
    $('#allContactsBox').text(allContacts.length ? allContacts.join('\n') : 'No contacts found.');
  });
}

/* ---------- separator ---------- */
function getSeparator(){
  const v = $('input[name="separator"]:checked').val();
  return v === 'comma' ? ',' : (v === 'newline' ? '\n' : ';');
}

/* ---------- batch splitting ---------- */
function splitInto(contacts, n){
  n = Math.max(1, Math.min(n, contacts.length || 1));
  const batches = [];
  const size = Math.ceil(contacts.length / n);
  for(let i=0; i<contacts.length; i+=size){
    batches.push(contacts.slice(i, i+size));
  }
  return batches;
}

function generateBatches(){
  if(allContacts.length === 0){ toast('No contacts loaded yet.','error'); return; }
  const n = parseInt($('#batchCount').val(), 10) || 1;
  const sep = getSeparator();
  const batches = splitInto(allContacts, n);
  let h='';
  batches.forEach(function(batch, i){
    const text = batch.join(sep);
    h += `<div class="border border-gray-200 rounded-2xl overflow-hidden">
      <div class="flex items-center justify-between px-4 py-2.5 bg-gray-50 border-b border-gray-100">
        <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Batch ${i+1} <span class="text-gray-400">(${batch.length} contacts)</span></span>
        <button onclick="copyText(this, '${i}')" data-text="${esc(text)}" class="bg-[#123b8c] hover:bg-[#152750] text-white text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors">Copy batch</button>
      </div>
      <div class="p-3 bg-white text-xs text-gray-700 whitespace-pre-wrap max-h-40 overflow-y-auto custom-scrollbar">${esc(text)}</div>
    </div>`;
  });
  $('#batchesOutput').html(h);
}

function copyText(btn, idx){
  const text = $(btn).data('text');
  navigator.clipboard.writeText(text).then(function(){
    toast('Batch '+(parseInt(idx)+1)+' copied!');
    $(btn).text('✓ Copied');
    setTimeout(function(){ $(btn).text('Copy batch'); }, 1500);
  }).catch(function(){ 
    // fallback
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    toast('Batch '+(parseInt(idx)+1)+' copied!');
  });
}

function copyAll(){
  if(allContacts.length === 0){ toast('No contacts.','error'); return; }
  const text = allContacts.join(';');
  navigator.clipboard.writeText(text).then(function(){ toast('All '+allContacts.length+' contacts copied!'); });
}

/* ---------- modal ---------- */
function openModal(){
  $('#ceModal').removeClass('hidden').addClass('flex');
  generateBatches(); // auto-generate on open with default 4 batches
}
function closeModal(){
  $('#ceModal').addClass('hidden').removeClass('flex');
}
function updateSeparator(){
  // re-render batches with the new separator if already generated
  if($('#batchesOutput').find('[data-text]').length) generateBatches();
}

$(document).ready(function(){
  loadContacts();
});
</script>
</body>
</html>
