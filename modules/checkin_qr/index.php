<?php
/**
 * ============================================================================
 * CHECK-IN QR GENERATOR — MASTERPIECE V2
 * File: /modules/checkin_qr/index.php
 * ----------------------------------------------------------------------------
 * A flawless 4:5 portrait generator. 
 * - Mobile-first responsive app layout.
 * - Massive QR card, clean single-line branding at the bottom.
 * - 100% html2canvas safe (no clipping bugs).
 * ============================================================================
 */
session_start();
require_once '../../includes/db.php';
if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['checkin_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Check-in QR Studio</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
  /* Prevents pull-to-refresh on mobile */
  body { overscroll-behavior-y: none; }
  
  /* Select Dropdown Styling */
  select { 
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 0.75rem center; background-repeat: no-repeat; background-size: 1.5em 1.5em;
    -webkit-appearance: none; appearance: none;
  }
  select:focus { border-color: #e6c56a; outline: none; }

  /* 
   * THE MASTERPIECE POSTER 
   * Fixed at 1080x1350 for precise 4:5 scaling.
   * Completely safe for html2canvas rendering.
   */
  #poster { 
    width: 1080px; 
    height: 1350px; 
    position: relative; 
    overflow: hidden; 
    background: #050a14; 
    transform-origin: center center; 
    box-shadow: 0 25px 60px rgba(0,0,0,0.8);
  }
  
  #posterBg { position: absolute; inset: 0; background-size: cover; background-position: center; }
  
  /* Deeper overlay to guarantee text and QR code contrast */
  #posterOverlay { 
    position: absolute; inset: 0; 
    background: linear-gradient(180deg, rgba(7, 12, 36, 0.75) 0%, rgba(3, 6, 20, 0.95) 100%); 
  }

  .glass-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 48px;
    padding: 32px;
    box-shadow: inset 0 0 40px rgba(255,255,255,0.02), 0 30px 60px rgba(0,0,0,0.6);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
  }

  .inner-frame {
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 36px;
    padding: 60px 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .qr-plate { 
    background: #ffffff; 
    padding: 24px; 
    border-radius: 24px; 
    box-shadow: 0 25px 50px rgba(0,0,0,0.5); 
  }

  /* Button Animations */
  .btn-action { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
  .btn-action:active:not(:disabled) { transform: scale(0.97); }
  .btn-action:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
</head>
<body class="bg-[#0a0a0a] text-white font-['Inter',sans-serif] h-screen w-screen overflow-hidden flex flex-col">

<!-- NAVBAR (Fixed) -->
<nav class="h-16 shrink-0 bg-black border-b border-white/10 flex items-center justify-between px-5 md:px-8 z-50">
  <div class="flex items-center gap-3">
    <img src="/assets/images/hod_logo.svg" alt="HOD" class="h-8 object-contain" onerror="this.style.display='none'">
    <span class="font-['Montserrat'] font-bold tracking-widest text-sm text-white/90">STUDIO</span>
  </div>
  <a href="/modules/events/index.php" class="text-white/50 hover:text-white text-sm font-medium transition-colors">Close</a>
</nav>

<!-- MAIN APP CONTAINER -->
<main class="flex-1 flex flex-col md:flex-row overflow-hidden relative">

  <!-- PREVIEW AREA (Top on Mobile, Right on Desktop) -->
  <div class="flex-1 bg-[#030303] bg-[radial-gradient(ellipse_at_center,_var(--tw-gradient-stops))] from-[#1a1a1a] to-[#000] relative flex items-center justify-center p-6 md:p-12 overflow-hidden order-1 md:order-2" id="previewContainer">
    <div id="previewShell" class="text-white/40 font-medium text-center">
      Select an event to generate card.
    </div>
  </div>

  <!-- CONTROLS SIDEBAR / BOTTOM SHEET (Bottom on Mobile, Left on Desktop) -->
  <div class="w-full md:w-[420px] bg-[#0d0d0d] border-t md:border-t-0 md:border-r border-white/10 flex flex-col order-2 md:order-1 shrink-0 h-[45vh] md:h-full z-20 shadow-[0_-10px_40px_rgba(0,0,0,0.5)] md:shadow-none">
    <div class="p-6 md:p-8 flex-1 overflow-y-auto flex flex-col gap-6">
      
      <div>
        <h1 class="font-['Montserrat'] text-xl font-bold mb-1">Check-in Card</h1>
        <p class="text-white/50 text-xs leading-relaxed">High-resolution 4:5 digital & print asset.</p>
      </div>

      <div class="flex flex-col gap-2">
        <label for="eventSelect" class="text-[10px] font-bold text-white/40 uppercase tracking-widest">Select Event</label>
        <select id="eventSelect" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3.5 text-sm font-medium text-white shadow-inner cursor-pointer transition-colors">
          <option value="">Loading events...</option>
        </select>
      </div>

      <div class="mt-auto pt-6 flex flex-col gap-3">
        <label class="text-[10px] font-bold text-white/40 uppercase tracking-widest mb-1">Export 2160x2700</label>
        
        <button class="btn-action w-full bg-white text-black border-0 rounded-xl py-3.5 font-semibold text-sm flex items-center justify-center gap-2" id="btnPng" onclick="exportPoster('png')" disabled>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Download PNG
        </button>
        
        <button class="btn-action w-full bg-transparent text-white border border-white/20 hover:border-white/40 rounded-xl py-3.5 font-semibold text-sm" id="btnJpg" onclick="exportPoster('jpeg')" disabled>
          Download JPEG
        </button>
      </div>
      
    </div>
  </div>
</main>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
let currentEvent = null;

function esc(s){ return (s??'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function toast(msg, type='error'){
  const el=document.createElement('div');
  el.className = `fixed top-6 left-1/2 -translate-x-1/2 z-[99999] px-6 py-3 rounded-full font-medium text-sm shadow-2xl transition-all duration-300 transform scale-100 opacity-100 ${type === 'error' ? 'bg-red-500 text-white' : 'bg-emerald-500 text-white'}`;
  el.textContent = msg; 
  document.body.appendChild(el); 
  setTimeout(() => { el.classList.remove('scale-100', 'opacity-100'); el.classList.add('scale-95', 'opacity-0'); }, 2500);
  setTimeout(() => el.remove(), 2800);
}

function api(action, extra, cb){ 
  const d=Object.assign({action, _csrf:CSRF}, extra||{}); 
  return $.post('/api/checkin_qr_api.php', d, cb, 'json'); 
}

/* ---------- 1. Init & Load ---------- */
$(document).ready(function(){
  api('list_events', {}, function(res){
    if(res.status!=='success' || !res.data) {
      $('#eventSelect').html('<option value="">Error loading</option>');
      return;
    }
    let o='<option value="">-- Select an event --</option>';
    res.data.forEach(e=>{ o+=`<option value="${e.id}">${esc(e.title)}</option>`; });
    $('#eventSelect').html(o);
  });
});

$('#eventSelect').on('change', function(){
  const id=$(this).val();
  if(!id){ 
    currentEvent = null; 
    $('#previewShell').html('<div class="text-white/40 font-medium">Select an event to generate card.</div>'); 
    $('.btn-action').prop('disabled', true); 
    return; 
  }
  
  $('#previewShell').html('<div class="text-white font-medium animate-pulse">Rendering canvas...</div>');
  $('.btn-action').prop('disabled', true);
  
  api('get_event', {event_id: id}, function(res){
    if(res.status !== 'success'){ toast(res.message); return; }
    currentEvent = res.event;
    buildPoster();
  });
});

/* ---------- 2. Build Poster Layout ---------- */
function buildPoster(){
  const ev = currentEvent;
  const url = window.location.origin + '/checkin.php?event=' + ev.token;
  const banner = ev.banner_image_url ? `style="background-image:url('${esc(ev.banner_image_url)}');"` : '';
  
  const logoFallback = "this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiNmZmYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSIxMCIvPjxwYXRoIGQ9Ik0xMiA4djhNMCAwIi8+PC9zdmc+'";
  const logo = `<img src="/assets/images/hod_logo.svg" style="height:64px; object-fit:contain;" onerror="${logoFallback}">`;
  
  $('#previewShell').html(`
    <div id="poster">
      <div id="posterBg" ${banner}></div>
      <div id="posterOverlay"></div>
      
      <!-- Flex Container for Flawless Positioning -->
      <div style="position: absolute; inset: 0; display: flex; flex-direction: column; padding: 70px 80px; z-index: 10; box-sizing: border-box;">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; width: 100%;">
          ${logo}
          <div style="font-family: 'Montserrat', sans-serif; color: #ffffff; font-size: 19px; letter-spacing: 6px; font-weight: 700; text-transform: uppercase; padding-top: 18px;">EVENT REGISTRATION</div>
        </div>

        <div style="flex: 1;"></div>

        <!-- Massive Glass Card -->
        <div class="glass-card" style="width: 100%; max-width: 820px; margin: 0 auto;">
          <div class="inner-frame">
            <div style="width: 60px; height: 3px; background: #e6c56a; margin-bottom: 24px; border-radius: 99px;"></div>
            <div style="font-family: 'Montserrat', sans-serif; color: #ffffff; font-size: 26px; letter-spacing: 12px; font-weight: 800; text-transform: uppercase; margin-bottom: 50px;">SCAN TO CHECK IN</div>
            
            <div class="qr-plate" id="qrHost"></div>
            
            <div style="margin-top: 40px; font-family: 'Inter', monospace; color: rgba(255,255,255,0.45); font-size: 17px; letter-spacing: 1px; text-align: center;">
              ${esc(url)}
            </div>
          </div>
        </div>

        <div style="flex: 1;"></div>

        <!-- Event Title (Clean, Branding-Aligned, Single Line) -->
        <div style="text-align: center; margin-bottom: 30px; padding: 0 40px;">
           <h2 style="font-family: 'Inter', sans-serif; font-weight: 800; color: #ffffff; font-size: 38px; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 4px 15px rgba(0,0,0,0.6);">
              ${esc(ev.title)}
           </h2>
        </div>

        <!-- Footer Verse -->
        <div style="text-align: center;">
          <p style="color: rgba(255,255,255,0.55); font-size: 21px; font-style: italic; font-weight: 500; font-family: 'Inter', serif; margin: 0;">"The LORD bless thee, and keep thee..." — Numbers 6:24</p>
        </div>

      </div>
    </div>
  `);

  // Huge 420x420 QR Code
  const qrHost = document.getElementById('qrHost'); 
  qrHost.innerHTML = '';
  new QRCode(qrHost, { 
    text: url, 
    width: 420, 
    height: 420, 
    colorDark: '#030614', 
    colorLight: '#ffffff', 
    correctLevel: QRCode.CorrectLevel.H 
  });

  scalePreview();
  $('.btn-action').prop('disabled', false);
}

/* ---------- 3. Perfect WYSIWYG Scaling ---------- */
function scalePreview(){
  const poster = document.getElementById('poster');
  if(!poster) return;
  const container = document.getElementById('previewContainer');
  
  const padding = window.innerWidth < 768 ? 32 : 80;
  const availWidth = container.clientWidth - padding;
  const availHeight = container.clientHeight - padding;
  
  const scale = Math.min(availWidth / 1080, availHeight / 1350);
  
  poster.style.transform = `scale(${scale})`;
}
$(window).on('resize', scalePreview);


/* ---------- 4. Safe html2canvas Export ---------- */
async function exportPoster(kind){
  const poster = document.getElementById('poster');
  if(!poster){ toast('Please select an event first.'); return; }
  
  const btnId = kind === 'png' ? '#btnPng' : '#btnJpg';
  const originalHtml = $(btnId).html();
  
  $('.btn-action').prop('disabled', true);
  $(btnId).html('<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Rendering...');
  
  try { 
    await document.fonts.ready;
    await new Promise(r => setTimeout(r, 600)); // Paint guarantee
  } catch(e) {}
  
  try {
    const originalTransform = poster.style.transform;
    poster.style.transform = 'none'; 
    poster.style.transformOrigin = 'top left';
    
    // Renders 2160x2700 flawlessly
    const canvas = await html2canvas(poster, { 
      scale: 2, 
      useCORS: true, 
      allowTaint: true, 
      backgroundColor: '#050a14', 
      logging: false
    });
    
    poster.style.transform = originalTransform;
    poster.style.transformOrigin = 'center center';
    
    const slug = (currentEvent.title || 'event').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
    const link = document.createElement('a');
    link.download = kind === 'png' ? `checkin-${slug}.png` : `checkin-${slug}.jpg`;
    link.href = kind === 'png' ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.95);
    
    document.body.appendChild(link); 
    link.click(); 
    link.remove();
    
    toast('Card Downloaded Successfully!', 'success');
  } catch(e) { 
    console.error(e); 
    toast('Export failed. Verify banner image CORS.'); 
  } finally {
    $('.btn-action').prop('disabled', false);
    $(btnId).html(originalHtml);
  }
}
</script>
</body>
</html>