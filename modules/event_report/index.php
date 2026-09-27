<?php
session_start();
if (empty($_SESSION['report_csrf'])) $_SESSION['report_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['report_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Data &amp; Engagement Report</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<style>
  body{ background:#f1f5f9; font-family:'Inter',sans-serif; }
  .card{ background:#fff; border-radius:20px; border:1px solid #eef2f7; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  .kpi{ border-radius:18px; }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6 space-y-5 pb-16">

  <!-- Header -->
  <div class="card p-5 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-gray-900">Event Data &amp; Engagement Report</h1>
      <p class="text-gray-500 text-sm mt-0.5">Comprehensive report for any event — SMS reach, registration, attendance &amp; IDI mobilization.</p>
    </div>
    <div class="flex gap-2 items-center">
      <select id="eventSelect" class="px-4 py-2.5 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:ring-2 focus:ring-[#123b8c]"><option value="">-- Select event --</option></select>
      <button onclick="loadReport()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-colors">Load</button>
      <button onclick="generatePDF()" id="btnPDF" disabled class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-colors">⬇ Download PDF</button>
    </div>
  </div>

  <!-- Narrative summary -->
  <div id="narrativeCard" class="hidden card p-5">
    <h3 class="font-bold text-gray-900 mb-2">In summary</h3>
    <div id="narrativeIntro"></div>
  </div>

  <!-- KPI cards -->
  <div id="kpiRow" class="hidden grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
    <div class="card kpi p-4 bg-blue-50 border-blue-100"><p class="text-[10px] font-bold text-blue-600 uppercase">Congregation</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiCong">—</p></div>
    <div class="card kpi p-4 bg-emerald-50 border-emerald-100"><p class="text-[10px] font-bold text-emerald-600 uppercase">Registered</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiReg">—</p></div>
    <div class="card kpi p-4 bg-purple-50 border-purple-100"><p class="text-[10px] font-bold text-purple-600 uppercase">Attended (dedup)</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiAtt">—</p></div>
    <div class="card kpi p-4 bg-amber-50 border-amber-100"><p class="text-[10px] font-bold text-amber-600 uppercase">IDI List</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiIdi">—</p></div>
    <div class="card kpi p-4 bg-red-50 border-red-100"><p class="text-[10px] font-bold text-red-600 uppercase">SMS Total Cost</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiSmsCost">—</p></div>
    <div class="card kpi p-4 bg-gray-100"><p class="text-[10px] font-bold text-gray-500 uppercase">IDI Attended</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiIdiAtt">—</p></div>
  </div>

  <!-- Charts -->
  <div id="chartRow" class="hidden grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="card p-4">
      <p class="font-bold text-gray-900 text-sm mb-2">Attendance by Day</p>
      <div style="height:200px"><canvas id="chartAtt"></canvas></div>
    </div>
    <div class="card p-4">
      <p class="font-bold text-gray-900 text-sm mb-2">Registration Sources</p>
      <div style="height:200px"><canvas id="chartSrc"></canvas></div>
    </div>
  </div>

  <!-- SMS section -->
  <div id="smsSection" class="hidden card p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-bold text-gray-900">SMS Engagement</h3>
      <button onclick="openSmsModal()" class="text-xs font-bold text-[#123b8c] bg-blue-50 px-3 py-2 rounded-lg hover:bg-blue-100">+ Log BulkSMS Campaign</button>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" id="smsKpis"></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-[10px] uppercase font-bold border-b border-gray-100">
          <tr><th class="px-3 py-2">Campaign</th><th class="px-3 py-2">Sent</th><th class="px-3 py-2 text-right">Recipients</th><th class="px-3 py-2 text-right">Pages</th><th class="px-3 py-2 text-right">Cost</th><th class="px-3 py-2"></th></tr>
        </thead>
        <tbody id="smsTable" class="divide-y divide-gray-50"></tbody>
      </table>
    </div>
  </div>

  <!-- Detail rows -->
  <div id="detailRow" class="hidden grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">Registered before event</p><p class="text-2xl font-black text-gray-900" id="detRegBefore">—</p></div>
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">Registered on event days</p><p class="text-2xl font-black text-gray-900" id="detRegOnDay">—</p></div>
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">Attended from prior registration</p><p class="text-2xl font-black text-gray-900" id="detAttPrior">—</p></div>
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">Attendance: Members</p><p class="text-2xl font-black text-gray-900" id="detAttMem">—</p></div>
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">Attendance: Walk-ins</p><p class="text-2xl font-black text-gray-900" id="detAttWalk">—</p></div>
    <div class="card p-4"><p class="text-xs font-bold text-gray-500 uppercase">IDI contacted</p><p class="text-2xl font-black text-gray-900" id="detIdiContacted">—</p></div>
  </div>
</div>

<!-- SMS Log Modal -->
<div id="smsModal" class="fixed inset-0 hidden z-[9999] items-center justify-center bg-gray-900/70 p-4">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
      <h3 class="font-bold text-gray-900" id="smsModalTitle">Log BulkSMS Campaign</h3>
      <button onclick="closeSmsModal()" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="smsForm" class="p-6 space-y-3">
      <input type="hidden" name="id" id="smsId">
      <div><label class="text-xs font-bold text-gray-600 uppercase">Campaign Label *</label><input type="text" id="smsLabel" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]" placeholder="e.g. Day 1 Reminder"></div>
      <div><label class="text-xs font-bold text-gray-600 uppercase">Date</label><input type="date" id="smsDate" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
      <div><label class="text-xs font-bold text-gray-600 uppercase">Audience</label><input type="text" id="smsAud" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none" placeholder="Congregation / Registrations / IDI / All"></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-bold text-gray-600 uppercase">Recipients</label><input type="number" id="smsCount" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
        <div><label class="text-xs font-bold text-gray-600 uppercase">Characters</label><input type="number" id="smsChars" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-bold text-gray-600 uppercase">Pages</label><input type="number" id="smsPages" value="1" min="1" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
        <div><label class="text-xs font-bold text-gray-600 uppercase">Unit Cost (₦)</label><input type="number" id="smsCost" step="0.01" value="6.99" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
      </div>
      <div><label class="text-xs font-bold text-gray-600 uppercase">Notes</label><input type="text" id="smsNotes" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none"></div>
      <button class="w-full bg-[#123b8c] text-white py-3 rounded-xl font-bold hover:bg-[#152750]">Save Campaign</button>
    </form>
  </div>
</div>

<script>
const API = '/api/event_report_api.php';
const CSRF = <?= json_encode($CSRF) ?>;
let currentEventId = null;
let reportData = null;
let chartAtt = null, chartSrc = null;

function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(m,t='success'){ Toastify({text:m,gravity:"top",position:"center",duration:2500,style:{background:t==='success'?'#10B981':'#EF4444',borderRadius:'10px',fontWeight:'bold'}}).showToast(); }
function api(action, extra, cb){ const d=Object.assign({action,_csrf:CSRF},extra||{}); return $.post(API,d,cb,'json'); }

function loadEvents(){
  api('list_events', {}, function(res){
    if(res && res.status==='success' && Array.isArray(res.data)){
      let o='<option value="">-- Select event --</option>';
      if(res.data.length===0){ o+='<option value="" disabled>No events found</option>'; }
      else { res.data.forEach(e=>o+=`<option value="${e.id}">${esc(e.title)}</option>`); }
      $('#eventSelect').html(o);
    } else {
      // Backend returned an error — tell the user instead of failing silently.
      toast((res && res.message) ? res.message : 'Could not load events. Check the API.', 'error');
      $('#eventSelect').html('<option value="">-- Select event -- (failed to load, retry)</option>');
    }
  }).fail(function(xhr){
    // Network/500 error — this is the "empty dropdown" case.
    console.error('list_events failed:', xhr.status, xhr.responseText);
    toast('Could not reach the event report API (HTTP '+xhr.status+'). Check the server file /api/event_report_api.php.', 'error');
    $('#eventSelect').html('<option value="">-- Select event -- (API unreachable, retry)</option>');
  });
}
$('#eventSelect').on('change', function(){ if($(this).val()) loadReport(); });

function loadReport(){
  const id=$('#eventSelect').val(); if(!id) return;
  currentEventId = id;
  toast('Loading report…');
  api('report_data', {event_id:id}, function(res){
    if(!res || res.status!=='success'){
      toast((res && res.message) ? res.message : 'Report failed to load.', 'error');
      return;
    }
    reportData = res;
    renderReport(res);
    $('#btnPDF').prop('disabled', false);
    toast('Report loaded.');
  }).fail(function(xhr){
    // 500 / network error — never silent.
    console.error('report_data failed:', xhr.status, xhr.responseText);
    toast('Report API error (HTTP '+xhr.status+'). Check /api/event_report_api.php — see console for details.', 'error');
  });
}

function renderReport(res){
  const d = res.data;
  $('#kpiRow,#chartRow,#smsSection,#detailRow,#narrativeCard').removeClass('hidden');
  renderNarrative(res);
  $('#kpiCong').text(d.congregation);
  $('#kpiReg').text(d.registration_total);
  $('#kpiAtt').text(d.attendance_total);
  $('#kpiIdi').text(d.idi_total);
  $('#kpiSmsCost').text('₦'+d.sms.total_cost);
  $('#kpiIdiAtt').text(d.idi_attended);

  $('#detRegBefore').text(d.registered_before_event);
  $('#detRegOnDay').text(d.registered_on_event_days);
  $('#detAttPrior').text(d.attended_from_prior_registration);
  $('#detAttMem').text(d.attendance_members);
  $('#detAttWalk').text(d.attendance_walkins);
  $('#detIdiContacted').text(d.idi_contacted);

  // SMS
  $('#smsKpis').html(`
    <div class="bg-blue-50 rounded-xl p-3"><p class="text-[10px] font-bold text-blue-600 uppercase">Reach (unique contacts in DB)</p><p class="text-lg font-black text-gray-900">${d.sms.reach_total}</p></div>
    <div class="bg-emerald-50 rounded-xl p-3"><p class="text-[10px] font-bold text-emerald-600 uppercase">System sends logged</p><p class="text-lg font-black text-gray-900">${d.sms.system_count}</p></div>
    <div class="bg-amber-50 rounded-xl p-3"><p class="text-[10px] font-bold text-amber-600 uppercase">Manual campaigns</p><p class="text-lg font-black text-gray-900">${d.sms.manual.length}</p></div>
    <div class="bg-purple-50 rounded-xl p-3"><p class="text-[10px] font-bold text-purple-600 uppercase">Manual recipients</p><p class="text-lg font-black text-gray-900">${d.sms.manual_total_recipients}</p></div>`);
  renderSmsTable(d.sms.system_campaigns || [], d.sms.manual);

  // Charts
  renderCharts(res);
}

function renderSmsTable(sysCamp, manual){
  let h='';
  // System campaigns (sent via ERP SMS Studio), consolidated by title
  if(sysCamp && sysCamp.length){
    sysCamp.forEach(s=>{ h+=`<tr class="bg-blue-50/40">
      <td class="px-3 py-2 font-bold">${esc(s.title)} <span class="text-[9px] uppercase font-bold text-blue-600 bg-blue-100 px-1.5 py-0.5 rounded">System</span>
        <a href="/modules/sms_studio/index.php?campaign=${encodeURIComponent(s.campaign_ids||'')}" class="block text-[10px] font-semibold text-[#123b8c] hover:underline">${Number(s.delivered||0).toLocaleString()} delivered · open in SMS Studio →</a></td>
      <td class="px-3 py-2">${esc((s.last_sent||'').slice(0,10))}</td>
      <td class="px-3 py-2 text-right">${Number(s.uniq).toLocaleString()}</td>
      <td class="px-3 py-2 text-right">1</td>
      <td class="px-3 py-2 text-right font-bold">₦${Number(s.cost).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
      <td class="px-3 py-2"></td>
    </tr>`; });
  }
  // Manual / website BulkSMS campaigns
  if(manual.length){
    manual.forEach(m=>{ const rc=(m.effective_recipients!=null)?m.effective_recipients:m.recipient_count; const cst=(m.effective_cost!=null)?m.effective_cost:m.total_cost; const note=(m.is_manual)?'':(m.computed_as_of?' (as of '+esc(m.computed_as_of)+')':''); h+=`<tr class="hover:bg-gray-50">
    <td class="px-3 py-2 font-bold">${esc(m.campaign_label)}</td>
    <td class="px-3 py-2">${esc(m.sent_date)}</td>
    <td class="px-3 py-2 text-right">${Number(rc).toLocaleString()}${note}</td>
    <td class="px-3 py-2 text-right">${m.pages}</td>
    <td class="px-3 py-2 text-right font-bold">₦${Number(cst).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
    <td class="px-3 py-2 text-right"><button onclick="deleteSmsLog(${m.id})" class="text-red-500 text-xs font-bold hover:underline">Delete</button></td>
  </tr>`; });
  }
  if(!h) h='<tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">No SMS campaigns logged for this event.</td></tr>';
  $('#smsTable').html(h);
}

function renderCharts(res){
  const attLabels = res.data.attendance_by_day.map(d=>'Day '+d.day);
  const attVals = res.data.attendance_by_day.map(d=>d.count);
  if(chartAtt) chartAtt.destroy();
  chartAtt = new Chart(document.getElementById('chartAtt'), {type:'bar', data:{labels:attLabels,datasets:[{label:'Attendees',data:attVals,backgroundColor:'rgba(29,53,106,0.6)',borderRadius:6}]}, options:{responsive:true,maintainAspectRatio:false}});

  const src = res.data.registration_sources;
  const lbl = res.data.source_labels || {};
  const labels = Object.keys(src).map(k=>lbl[k]||k), vals = Object.values(src);
  if(chartSrc) chartSrc.destroy();
  chartSrc = new Chart(document.getElementById('chartSrc'), {type:'doughnut', data:{labels,datasets:[{data:vals,backgroundColor:['#1D356A','#3B82F6','#10B981','#8B5CF6','#F59E0B','#EF4444','#94A3B8','#D1D5DB']}]}, options:{responsive:true,maintainAspectRatio:false}});
}

function renderNarrative(res){
  const d=res.data, n=d.narrative||{};
  $('#narrativeIntro').html(`
    <p class="text-gray-600 text-sm leading-relaxed">On <strong>${esc(n.start_date)}</strong>${n.start_date!==n.end_date?` to <strong>${esc(n.end_date)}</strong>`:''}, House of David Lekki Centre hosted <strong>${esc(n.title)}</strong>. This report summarises how the event was promoted, who registered, who attended, and how our IDI follow-up network performed. The headline results: <strong>${d.attendance_total}</strong> attended, <strong>${d.registration_total}</strong> registered, and <strong>${d.sms.reach_total}</strong> unique contacts in the database available for SMS outreach.</p>
    <p class="text-gray-600 text-sm leading-relaxed mt-2">Attendance reached <strong>${d.attendance_total}</strong> unique people across ${res.day_count} day(s) &mdash; <strong>${n.member_pct}%</strong> of them existing members. Among registrants who said how they heard about the event, personal invitation was the most common channel. Full details follow in each section and in the downloadable PDF report.</p>`);
}

/* SMS log modal */
function openSmsModal(){ $('#smsModalTitle').text('Log BulkSMS Campaign'); $('#smsForm')[0].reset(); $('#smsId').val(''); $('#smsPages').val(1); $('#smsCost').val(6.99); $('#smsModal').removeClass('hidden').addClass('flex'); }
function closeSmsModal(){ $('#smsModal').addClass('hidden').removeClass('flex'); }
$('#smsForm').on('submit', function(e){
  e.preventDefault();
  api('save_sms_log', {event_id:currentEventId, campaign_label:$('#smsLabel').val(), sent_date:$('#smsDate').val(), audience_label:$('#smsAud').val(), recipient_count:$('#smsCount').val(), characters:$('#smsChars').val(), pages:$('#smsPages').val(), unit_cost:$('#smsCost').val(), notes:$('#smsNotes').val(), id:$('#smsId').val()}, function(res){
    toast(res.message,res.status); if(res.status==='success'){ closeSmsModal(); loadReport(); }
  });
});
function deleteSmsLog(id){ if(!confirm('Delete this SMS campaign?'))return; api('delete_sms_log',{id},function(res){ toast(res.message,res.status); if(res.status==='success') loadReport(); }); }

function generatePDF(){
  if(!currentEventId){ toast('Select an event first.','error'); return; }
  window.location.href = '/api/event_report_pdf.php?event_id='+currentEventId;
}

$(document).ready(function(){ loadEvents(); });
</script>
</body>
</html>
