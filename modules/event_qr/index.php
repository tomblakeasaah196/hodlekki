<?php
/**
 * ============================================================================
 * EVENT QR POSTER — Design Studio
 * File: /modules/event_qr/index.php
 * ----------------------------------------------------------------------------
 * A 16:9 glassmorphic, fully branded registration QR poster generator.
 * Live preview + high-res PNG / JPEG export.
 *
 * Open with: /modules/event_qr/index.php?event_id=NNN
 * ============================================================================
 */
session_start();
if (empty($_SESSION['sms_csrf'])) $_SESSION['sms_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['sms_csrf'];
$event_id = (int)($_GET['event_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Branded Event QR Poster</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700;900&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Inter:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{ --hod:#123b8c; --hod-dark:#0a1025; --hod-red:#e11d48; --gold:#d4af37; }
  body{ background:#070b1c; }
  .font-serif-q{ font-family:'Playfair Display', serif; }
  .font-serif-it{ font-family:'Cormorant Garamond', serif; }
  .font-mono-q{ font-family:'Space Mono', monospace; }
  /* ---------- The poster (fixed 16:9 @ 1600x900) ---------- */
  #poster{
    width:1600px; height:900px; position:relative; overflow:hidden;
    background:#0a1025; transform-origin:top left;
    box-shadow:0 40px 120px rgba(0,0,0,.6);
    border-radius:0;
  }
  #posterBg, #posterBgBlur{ position:absolute; inset:0; background-size:cover; background-position:center; }
  #posterBg{ opacity:.35; }
  #posterBgBlur{ filter:blur(26px) saturate(1.3) brightness(.55); transform:scale(1.15); }
  #posterOverlay{ position:absolute; inset:0; background:
    radial-gradient(60% 60% at 15% 10%, rgba(18,59,140,.45), transparent 60%),
    radial-gradient(50% 50% at 85% 90%, rgba(225,29,72,.30), transparent 60%),
    linear-gradient(160deg, rgba(7,10,25,.82), rgba(7,10,25,.45) 45%, rgba(7,10,25,.88)); }
  /* glass panels */
  .glass{ background:linear-gradient(145deg, rgba(255,255,255,.16), rgba(255,255,255,.05));
          backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
          border:1px solid rgba(255,255,255,.22); box-shadow:0 20px 60px rgba(0,0,0,.35); }
  .glass-inner{ background:linear-gradient(145deg, rgba(255,255,255,.10), rgba(255,255,255,.02));
          backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
          border:1px solid rgba(255,255,255,.16); }
  .gold-grad{ background:linear-gradient(120deg,#f7e08a,#d4af37 40%,#9a7b1f); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .gold-line{ background:linear-gradient(90deg,#f7e08a,#d4af37 40%,#9a7b1f); }
  .text-sheen{ background:linear-gradient(90deg,#fff,#e8ecff,#fff); background-size:200% auto; -webkit-background-clip:text; background-clip:text; color:transparent; animation:sheen 6s linear infinite; }
  @keyframes sheen{ to{ background-position:200% center; } }
  /* html2canvas CANNOT render background-clip:text (gradient inside letters).
     Use these SOLID text colors so the exported PNG/JPEG is correct. */
  .title-solid{ color:#ffffff; text-shadow:0 2px 28px rgba(255,255,255,.28); }
  .gold-text{ color:#e6c56a; }
  /* QR white plate */
  .qr-plate{ background:#fff; padding:14px; border-radius:18px; box-shadow:0 18px 50px rgba(0,0,0,.45); }
  #qrBox canvas{ display:block; }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-6xl mx-auto p-4 md:p-6 space-y-6 pb-20">

  <!-- Top bar -->
  <div class="bg-white/5 border border-white/10 rounded-3xl p-4 md:p-5 flex flex-col md:flex-row gap-4 md:items-center justify-between">
    <div class="flex items-center gap-4">
      <a href="/modules/events/index.php" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 text-white px-3.5 py-2.5 rounded-xl text-sm font-bold transition-colors">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Events
      </a>
      <div>
        <h1 class="text-xl md:text-2xl font-extrabold text-white tracking-tight">Branded Event QR Poster</h1>
        <p class="text-white/50 text-xs font-medium mt-0.5">16:9 glassmorphic poster · download as PNG or JPEG</p>
      </div>
    </div>
    <div class="flex gap-2 flex-wrap">
      <button onclick="exportPoster('png')" class="bg-[#123b8c] hover:bg-[#1d4fb0] text-white px-5 py-2.5 rounded-xl font-bold shadow-lg transition-colors">Download PNG</button>
      <button onclick="exportPoster('jpeg')" class="bg-white hover:bg-white/90 text-[#0a1025] px-5 py-2.5 rounded-xl font-bold shadow-lg transition-colors">Download JPEG</button>
    </div>
  </div>

  <!-- Controls -->
  <div class="bg-white/5 border border-white/10 rounded-3xl p-4 md:p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
    <div>
      <label class="block text-white/60 text-[11px] font-bold uppercase tracking-wider mb-1.5">Event</label>
      <select id="eventSelect" class="w-full px-3 py-2.5 bg-[#0d1330] border border-white/15 text-white rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
        <option value="">-- Select an event --</option>
      </select>
    </div>
    <div>
      <label class="block text-white/60 text-[11px] font-bold uppercase tracking-wider mb-1.5">Brand / Church name</label>
      <input id="brandInput" value="HOD Lekki Center" class="w-full px-3 py-2.5 bg-[#0d1330] border border-white/15 text-white rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
    </div>
    <div>
      <label class="block text-white/60 text-[11px] font-bold uppercase tracking-wider mb-1.5">Event title (auto, editable)</label>
      <input id="titleInput" class="w-full px-3 py-2.5 bg-[#0d1330] border border-white/15 text-white rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
    </div>
    <div>
      <label class="block text-white/60 text-[11px] font-bold uppercase tracking-wider mb-1.5">Tagline / eyebrow</label>
      <input id="taglineInput" value="You are cordially invited" class="w-full px-3 py-2.5 bg-[#0d1330] border border-white/15 text-white rounded-xl text-sm outline-none focus:ring-2 focus:ring-[#123b8c]">
    </div>
  </div>

  <!-- Toggles -->
  <div class="flex flex-wrap gap-3">
    <label class="flex items-center gap-2 text-white/70 text-sm font-semibold bg-white/5 border border-white/10 rounded-xl px-4 py-2 cursor-pointer">
      <input type="checkbox" id="toggleDetails" checked class="w-4 h-4 rounded text-[#123b8c]"> Show details (date/time/location)
    </label>
    <label class="flex items-center gap-2 text-white/70 text-sm font-semibold bg-white/5 border border-white/10 rounded-xl px-4 py-2 cursor-pointer">
      <input type="checkbox" id="toggleBanner" checked class="w-4 h-4 rounded text-[#123b8c]"> Show banner background
    </label>
  </div>

  <!-- Preview -->
  <div class="rounded-3xl border border-white/10 p-3 bg-black/30">
    <div id="previewShell" class="overflow-hidden" style="border-radius:18px;">
      <!-- poster injected here, scaled to fit -->
    </div>
  </div>

</div>

<script>
const API = '/api/event_qr_api.php';
const CSRF = <?= json_encode($CSRF) ?>;
let currentEvent = null;
let qrInstance = null;
let initialEventId = <?= $event_id ?>;

/* ---------- helpers ---------- */
function toast(msg, type='success'){ Toastify({ text:msg, gravity:"top", position:"center", duration:3000, style:{ background: type==='success'?'#10B981':'#EF4444', borderRadius:'10px', fontWeight:'bold' } }).showToast(); }
function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function api(action, extra, cb){ const d = Object.assign({ action, _csrf: CSRF }, extra||{}); return $.post(API, d, cb, 'json'); }

/* ---------- event dropdown ---------- */
function loadEvents(selectId){
  api('list_events', {}, function(res){
    if(res.status!=='success'||!res.data) return;
    let o='<option value="">-- Select an event --</option>';
    res.data.forEach(e=>{ o+=`<option value="${e.id}">${esc(e.title)}</option>`; });
    $('#eventSelect').html(o);
    if(initialEventId){ $('#eventSelect').val(String(initialEventId)); loadEvent(initialEventId); }
  });
}
$('#eventSelect').on('change', function(){ const v=$(this).val(); if(v) loadEvent(v); else currentEvent=null; });

function loadEvent(id){
  api('get_event', { event_id:id }, function(res){
    if(res.status!=='success'){ toast(res.message,'error'); return; }
    currentEvent = res.event;
    $('#titleInput').val(currentEvent.title || '');
    if(currentEvent.banner_image_url) buildPoster();
    else { $('#posterBg').css('background','linear-gradient(160deg,#123b8c,#0a1025)'); buildPoster(); }
  });
}

/* ---------- build the poster DOM ---------- */
function buildPoster(){
  const ev = currentEvent;
  const brand = $('#brandInput').val().trim() || 'HODLC';
  const title = $('#titleInput').val().trim() || (ev?ev.title:'Your Event');
  const tagline = $('#taglineInput').val().trim();
  const showDetails = $('#toggleDetails').is(':checked');
  const showBanner = $('#toggleBanner').is(':checked');

  const url = ev && ev.registration_url ? ev.registration_url : 'https://hodlc.lpc.cm';
  const cat = ev && ev.category ? ev.category.replace(/_/g,' ') : 'Event';

  const html = `
  <div id="poster">
    ${showBanner && ev && ev.banner_image_url ? `
      <div id="posterBg" style="background-image:url('${esc(ev.banner_image_url)}')"></div>
      <div id="posterBgBlur" style="background-image:url('${esc(ev.banner_image_url)}')"></div>
    ` : `
      <div id="posterBg" style="background:linear-gradient(160deg,#123b8c 0%,#0a1025 70%)"></div>
      <div id="posterBgBlur" style="background:linear-gradient(160deg,#1d4fb0 0%,#0a1025 70%)"></div>
    `}
    <div id="posterOverlay"></div>

    <!-- Decorative glass blobs -->
    <div class="absolute w-[520px] h-[520px] rounded-full glass-inner" style="left:-120px; top:-120px; opacity:.6"></div>
    <div class="absolute w-[360px] h-[360px] rounded-full glass-inner" style="right:-80px; bottom:-80px; opacity:.5"></div>

    <!-- Top brand -->
    <div class="absolute" style="left:72px; top:58px; z-index:5;">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-2xl glass flex items-center justify-center font-serif-q font-black text-white text-2xl">${esc(brand.slice(0,1))}</div>
        <div>
          <div class="font-sans font-extrabold text-white text-lg tracking-[0.35em] uppercase">${esc(brand)}</div>
          <div class="font-sans text-white/55 text-[11px] tracking-[0.28em] uppercase">The Mountain of the Lord's House</div>
        </div>
      </div>
    </div>

    <!-- Top-right eyebrow -->
    <div class="absolute glass rounded-full px-5 py-2.5" style="right:64px; top:58px; z-index:5;">
      <span class="font-sans text-white/90 text-[12px] font-bold tracking-[0.3em] uppercase">Event Registration</span>
    </div>

    <!-- Left text block -->
    <div class="absolute" style="left:72px; top:190px; width:780px; z-index:5;">
      <div class="flex items-center gap-3 mb-4">
        <span class="inline-block w-10 h-[3px] gold-line rounded-full"></span>
        <span class="font-sans font-bold gold-text text-[15px] tracking-[0.4em] uppercase">${esc(tagline)}</span>
      </div>
      <div class="font-serif-q font-black title-solid" style="font-size:92px; line-height:0.98; letter-spacing:-1px;">
        ${esc(title)}
      </div>
      <div class="flex items-center gap-3 mt-5">
        <span class="font-serif-it italic text-white/70 text-2xl">${esc(cat)}</span>
        <span class="w-16 h-[2px] bg-white/25 rounded-full"></span>
        <span class="font-sans text-white/50 text-sm tracking-[0.25em] uppercase">${esc(ev && ev.id ? '#'+ev.id : '')}</span>
      </div>

      ${showDetails ? `
      <div class="mt-9 space-y-3" style="max-width:620px;">
        ${(ev && ev.date) ? `
        <div class="glass rounded-2xl px-5 py-3.5 flex items-center gap-4">
          <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center text-white/90">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          </div>
          <div>
            <div class="font-sans text-white/50 text-[10px] font-bold tracking-[0.25em] uppercase">Date</div>
            <div class="font-sans text-white text-base font-semibold">${esc(ev.date)}${ev.time?'  ·  '+esc(ev.time):''}</div>
          </div>
        </div>`:''}
        ${ev && ev.location ? `
        <div class="glass rounded-2xl px-5 py-3.5 flex items-center gap-4">
          <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center text-white/90">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          </div>
          <div>
            <div class="font-sans text-white/50 text-[10px] font-bold tracking-[0.25em] uppercase">Venue</div>
            <div class="font-sans text-white text-base font-semibold">${esc(ev.location)}</div>
          </div>
        </div>`:''}
      </div>`:''}
    </div>

    <!-- Right QR card -->
    <div class="absolute glass rounded-[28px] p-6" style="right:64px; top:190px; width:420px; z-index:6;">
      <div class="text-center mb-4">
        <div class="font-sans text-white/60 text-[11px] font-bold tracking-[0.35em] uppercase">Scan to register</div>
      </div>
      <div class="flex justify-center">
        <div class="qr-plate">
          <div id="qrBox"></div>
        </div>
      </div>
      <div class="mt-5 text-center">
        <div class="font-mono-q text-white/75 text-[13px] tracking-tight break-all">${esc(url)}</div>
      </div>
      <!-- corner accents -->
      <div class="absolute w-8 h-8 border-t-2 border-l-2 gold-line rounded-tl-lg" style="left:-1px;top:-1px"></div>
      <div class="absolute w-8 h-8 border-b-2 border-r-2 gold-line rounded-br-lg" style="right:-1px;bottom:-1px"></div>
    </div>

    <!-- Bottom footer -->
    <div class="absolute flex items-center justify-between" style="left:72px; right:64px; bottom:40px; z-index:5;">
      <div class="flex items-center gap-3">
        <span class="w-16 h-[2px] gold-line rounded-full"></span>
        <span class="font-sans text-white/55 text-[12px] tracking-[0.3em] uppercase">${esc(brand)}</span>
        <span class="font-sans text-white/30 text-[12px] tracking-[0.3em] uppercase">·</span>
        <span class="font-sans text-white/40 text-[12px] tracking-[0.2em] uppercase">hodlc.lpc.cm</span>
      </div>
      <div class="font-serif-it italic text-white/45 text-sm">We look forward to seeing you</div>
    </div>
  </div>`;

  $('#previewShell').html(html);
  buildQR(url);
  scalePreview();
}

function buildQR(url){
  const box = document.getElementById('qrBox');
  box.innerHTML = '';
  qrInstance = new QRCode(box, {
    text: url,
    width: 250,
    height: 250,
    colorDark: '#0a1025',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
  });
}

/* scale the 1600x900 poster to fit preview container */
function scalePreview(){
  const shell = $('#previewShell');
  const w = shell.width();
  const scale = w / 1600;
  const poster = $('#poster');
  poster.css('transform', `scale(${scale})`);
  shell.height(900 * scale);
  $('#previewShell').css('height', Math.ceil(900*scale)+'px');
}

/* ---------- export ---------- */
async function exportPoster(kind){
  const poster = document.getElementById('poster');
  if(!poster){ toast('Build a poster first.','error'); return; }
  toast('Rendering…','success');
  try{
    // temporarily reset transform so we capture full 1600x900, then restore
    const original = poster.style.transform;
    poster.style.transform = 'none';

    const canvas = await html2canvas(poster, {
      scale: 2,               // 3200x1800 crisp output
      useCORS: true,
      allowTaint: true,
      backgroundColor: '#0a1025',
      logging: false
    });
    poster.style.transform = original;

    const slug = (currentEvent? currentEvent.title : 'event').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
    const link = document.createElement('a');
    if(kind==='jpeg'){
      link.download = `event-qr-${slug}.jpg`;
      link.href = canvas.toDataURL('image/jpeg', 0.92);
    } else {
      link.download = `event-qr-${slug}.png`;
      link.href = canvas.toDataURL('image/png');
    }
    document.body.appendChild(link);
    link.click();
    link.remove();
    toast('Downloaded!');
  }catch(e){
    console.error(e);
    toast('Export failed. If the banner is on another domain, enable CORS.','error');
  }
}

$(window).on('resize', scalePreview);
$(document).ready(function(){
  $('#brandInput, #titleInput, #taglineInput').on('input', function(){ if(currentEvent) buildPoster(); });
  $('#toggleDetails, #toggleBanner').on('change', function(){ if(currentEvent) buildPoster(); });
  loadEvents();
});
</script>
</body>
</html>
