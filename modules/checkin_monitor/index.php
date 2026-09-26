<?php
/**
 * ============================================================================
 * CHECK-IN MONITOR — live KPIs + recent check-ins
 * File: /modules/checkin_monitor/index.php
 * ----------------------------------------------------------------------------
 * Leadership dashboard showing how many people have checked in, per day,
 * members vs walk-ins, name edits, and the most recent check-ins.
 * ============================================================================
 */
session_start();
if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['checkin_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check-in Monitor</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<style>
  body{ background:#f1f5f9; }
  .bg-hod{ background-color:#123b8c; }
  .custom-scrollbar::-webkit-scrollbar{ width:8px; height:8px; }
  .custom-scrollbar::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:8px; }
  .card{ background:#fff; border-radius:20px; border:1px solid #eef2f7; box-shadow:0 1px 3px rgba(0,0,0,.06); }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-6xl mx-auto p-4 md:p-6 space-y-5 pb-16">

  <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-gray-900">Check-in Monitor</h1>
      <p class="text-gray-500 text-sm mt-0.5">Live attendance for your event.</p>
    </div>
    <div class="flex gap-2">
      <select id="eventSelect" class="px-4 py-2.5 border border-gray-200 rounded-xl bg-white text-sm font-bold outline-none focus:ring-2 focus:ring-[#123b8c]">
        <option value="">-- Select event --</option>
      </select>
      <button onclick="loadStats()" class="bg-[#123b8c] hover:bg-[#152750] text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-colors">Refresh</button>
      <button onclick="exportExcel()" id="btnExport" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-bold transition-colors">Export (Excel)</button>
    </div>
  </div>

  <!-- KPI cards -->
  <div id="kpiRow" class="grid grid-cols-2 md:grid-cols-3 gap-4">
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total checked in</p>
      <p class="text-3xl font-black text-[#123b8c] mt-1" id="kpiTotal">—</p>
    </div>
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Checked in today</p>
      <p class="text-3xl font-black text-emerald-600 mt-1" id="kpiToday">—</p>
    </div>
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Members</p>
      <p class="text-3xl font-black text-gray-800 mt-1" id="kpiMembers">—</p>
    </div>
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Walk-ins</p>
      <p class="text-3xl font-black text-amber-600 mt-1" id="kpiWalkins">—</p>
    </div>
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Name edits</p>
      <p class="text-3xl font-black text-purple-600 mt-1" id="kpiEdits">—</p>
    </div>
    <div class="card p-5">
      <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Per day</p>
      <p class="text-sm font-bold text-gray-600 mt-1 leading-relaxed" id="kpiDays">—</p>
    </div>
  </div>

  <!-- Recent check-ins -->
  <div class="card overflow-hidden">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
      <h3 class="text-lg font-bold text-gray-900">Recent check-ins</h3>
      <span id="recentCount" class="text-xs font-bold text-gray-400"></span>
    </div>
    <div class="overflow-x-auto custom-scrollbar">
      <table class="w-full text-left text-sm text-gray-600">
        <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
          <tr>
            <th class="px-5 py-3">Name</th>
            <th class="px-5 py-3">Phone</th>
            <th class="px-5 py-3">Type</th>
            <th class="px-5 py-3">Source</th>
            <th class="px-5 py-3">Time</th>
          </tr>
        </thead>
        <tbody id="recentBody" class="divide-y divide-gray-50">
          <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">Select an event.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const API = '/api/checkin_qr_api.php';
const CSRF = <?= json_encode($CSRF) ?>;
function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg,t='success'){ Toastify({text:msg,gravity:"top",position:"center",duration:2500,style:{background:t==='success'?'#10B981':'#EF4444',borderRadius:'10px',fontWeight:'bold'}}).showToast(); }
function api(action, extra, cb){ const d=Object.assign({action,_csrf:CSRF},extra||{}); return $.post(API,d,cb,'json'); }

function loadEvents(){
  api('list_events', {}, function(res){
    if(res.status!=='success'||!res.data) return;
    let o='<option value="">-- Select event --</option>';
    res.data.forEach(e=>{ o+=`<option value="${e.id}">${esc(e.title)}</option>`; });
    $('#eventSelect').html(o);
  });
}

function loadStats(){
  const id=$('#eventSelect').val();
  if(!id){ return; }
  api('checkin_stats', {event_id:id}, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    const k=res.kpis;
    $('#kpiTotal').text(k.total);
    $('#kpiToday').text(k.today);
    $('#kpiMembers').text(k.members);
    $('#kpiWalkins').text(k.walkins);
    $('#kpiEdits').text(k.name_edits);
    $('#kpiDays').html(k.by_day.length ? k.by_day.map(d=>d.checkin_date+' : '+d.n).join('<br>') : '—');
    // recent
    const rows=res.recent||[];
    $('#recentCount').text(rows.length+' shown');
    let h='';
    if(rows.length===0){ h='<tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">No check-ins yet.</td></tr>'; }
    else {
      rows.forEach(r=>{
        const type = r.is_member==='1'?'Member':(r.is_walkin==='1'?'Walk-in':'Guest');
        const typeCls = r.is_member==='1'?'bg-blue-100 text-blue-700':(r.is_walkin==='1'?'bg-amber-100 text-amber-700':'bg-gray-100 text-gray-600');
        const time = r.checked_in_at ? new Date(r.checked_in_at.replace(' ','T')).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}) : '';
        h += `<tr class="hover:bg-blue-50/30">
          <td class="px-5 py-3 font-bold text-gray-900">${esc(r.full_name)}</td>
          <td class="px-5 py-3">${esc(r.phone)}</td>
          <td class="px-5 py-3"><span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${typeCls}">${type}</span></td>
          <td class="px-5 py-3 text-gray-500">${esc(r.source)}</td>
          <td class="px-5 py-3 text-gray-500">${time}</td>
        </tr>`;
      });
    }
    $('#recentBody').html(h);
  });
}

$('#eventSelect').on('change', loadStats);
function exportExcel(){
  const id=$('#eventSelect').val();
  if(!id){ toast('Select an event first.','error'); return; }
  window.location.href = '/api/export_checkin_excel.php?event_id=' + id;
}
$(document).ready(function(){ loadEvents(); });
</script>
</body>
</html>
