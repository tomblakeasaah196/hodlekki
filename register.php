<?php
// /register.php
// Public, unauthenticated event registration + discovery page.
// Anyone with the link (church member or open/visitor) can view event details and register.

require_once __DIR__ . '/includes/db.php'; // establishes $pdo and the session safely
$is_logged_in = isset($_SESSION['user_id']) ? 'true' : 'false';

// -----------------------------------------------------------------------------------
// Server-side meta lookup (for link previews / SEO).
// -----------------------------------------------------------------------------------
$token = $_GET['token'] ?? '';
$meta_title = 'Event Registration | HOD Lekki Centre';
$meta_description = 'You\'re invited! Tap to view event details and register.';
$meta_image = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$meta_url = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/register.php');

if (!empty($token)) {
    try {
        $metaStmt = $pdo->prepare("SELECT title, description, event_date, end_date, banner_image_url, ministers, ministers_image_url FROM events WHERE registration_token = ?");
        $metaStmt->execute([$token]);
        $metaEvent = $metaStmt->fetch(PDO::FETCH_ASSOC);

        if ($metaEvent) {
            $meta_title = $metaEvent['title'] . ' | HOD Lekki Centre';
            $niceDate = date('F j, Y', strtotime($metaEvent['event_date']));
            if (!empty($metaEvent['end_date']) && $metaEvent['end_date'] !== date('Y-m-d', strtotime($metaEvent['event_date']))) {
                $niceDate .= ' - ' . date('F j, Y', strtotime($metaEvent['end_date']));
            }
            $meta_description = !empty($metaEvent['description'])
                ? mb_strimwidth(trim(strip_tags($metaEvent['description'])), 0, 500, '...')
                : "You're invited! Join us on {$niceDate}. Tap to register.";
            if (!empty($metaEvent['banner_image_url'])) {
                $meta_image = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . $metaEvent['banner_image_url'];
            }
        }
    } catch (\Throwable $e) {
        error_log('register.php meta lookup error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($meta_description); ?>">

    <!-- Open Graph / Link Preview -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($meta_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($meta_description); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($meta_url); ?>">
    <meta property="og:site_name" content="CHURCH HOD Lekki">
    <?php if ($meta_image): ?><meta property="og:image" content="<?php echo htmlspecialchars($meta_image); ?>"><?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($meta_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($meta_description); ?>">
    <?php if ($meta_image): ?><meta name="twitter:image" content="<?php echo htmlspecialchars($meta_image); ?>"><?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1e3a8a', hodRed: '#ef4444' },
                    fontFamily: { display: ['Inter', 'sans-serif'], sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

        :root{
            --hod-blue:#1e3a8a;
            --hod-blue-2:#3b82f6;
            --hod-red:#ef4444;
            --hod-red-2:#dc2626;
        }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; }
        body{
            background:#070c24;
            color:#eef2ff;
            font-family:'Inter',sans-serif;
            min-height:100vh;
            overflow-x:hidden;
            -webkit-font-smoothing:antialiased;
        }

        /* ---------- Animated gradient mesh background ---------- */
        #bannerBg{ position:fixed; inset:0; z-index:0; overflow:hidden; background:#070c24; }
#bannerBg .bg-img{ position:absolute; inset:-6%; background-size:cover; background-position:center; filter:blur(30px) brightness(.55) saturate(1.15); transform:scale(1.1); }
#bannerBg::after{ content:''; position:absolute; inset:0; background:linear-gradient(160deg, rgba(7,12,36,.78), rgba(7,12,36,.55) 45%, rgba(7,12,36,.85)); }
.mesh-bg{ position:fixed; inset:-10%; z-index:1; overflow:hidden; }
        .mesh-bg::before{
            content:''; position:absolute; inset:0;
            background:
                radial-gradient(40% 40% at 20% 20%, rgba(30,58,138,.55), transparent 60%),
                radial-gradient(45% 45% at 80% 30%, rgba(239,68,68,.40), transparent 60%),
                radial-gradient(50% 50% at 50% 80%, rgba(59,130,246,.45), transparent 60%),
                radial-gradient(40% 40% at 85% 85%, rgba(147,51,234,.35), transparent 60%);
            filter:blur(40px);
            animation:meshShift 22s ease-in-out infinite alternate;
        }
        .blob{ position:absolute; border-radius:50%; filter:blur(70px); opacity:.55; mix-blend-mode:screen; will-change:transform; }
        .blob.b1{ width:46vw; height:46vw; background:radial-gradient(circle,#1e3a8a,transparent 70%); top:-10%; left:-8%; animation:drift1 26s ease-in-out infinite alternate; }
        .blob.b2{ width:42vw; height:42vw; background:radial-gradient(circle,#ef4444,transparent 70%); top:10%; right:-12%; animation:drift2 30s ease-in-out infinite alternate; }
        .blob.b3{ width:50vw; height:50vw; background:radial-gradient(circle,#3b82f6,transparent 70%); bottom:-20%; left:20%; animation:drift3 34s ease-in-out infinite alternate; }
        @keyframes meshShift{ 0%{transform:scale(1) translate(0,0)} 100%{transform:scale(1.15) translate(2%,-2%)} }
        @keyframes drift1{ 0%{transform:translate(0,0)} 100%{transform:translate(12vw,8vh)} }
        @keyframes drift2{ 0%{transform:translate(0,0)} 100%{transform:translate(-10vw,12vh)} }
        @keyframes drift3{ 0%{transform:translate(0,0)} 100%{transform:translate(8vw,-10vh)} }

        .grain{ position:fixed; inset:0; z-index:2; pointer-events:none; opacity:.05;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }

        /* ---------- Glass card ---------- */
        .glass{
            position:relative;
            overflow:hidden;
            background:linear-gradient(140deg, rgba(255,255,255,.12), rgba(255,255,255,.04));
            backdrop-filter:blur(26px) saturate(150%);
            -webkit-backdrop-filter:blur(26px) saturate(150%);
            border:1px solid rgba(255,255,255,.18);
            box-shadow:0 40px 90px -25px rgba(0,0,0,.7), inset 0 1px 0 rgba(255,255,255,.28);
            border-radius:30px;
            animation:cardFloat 7s ease-in-out infinite alternate;
        }
        @keyframes cardFloat{ 0%{transform:translateY(0)} 100%{transform:translateY(-8px)} }

        .gradient-text{
            background:linear-gradient(90deg,#ffffff,#fca5a5,#bfdbfe,#ffffff);
            background-size:220% auto;
            -webkit-background-clip:text; background-clip:text; color:transparent;
            animation:shine 5s linear infinite;
        }
        @keyframes shine{ to{ background-position:220% center; } }

        /* ---------- Top banner hero (crisp, full-bleed, NOT blurred) ---------- */
        .banner-hero{ position:relative; width:100%; aspect-ratio: 21 / 9; height: auto; overflow:hidden; background:#0b1020; }
        @media (min-width:768px){ .banner-hero{ aspect-ratio: auto; height: 230px; } }
        .banner-hero img{ width:100%; height:100%; object-fit:cover; object-position:center; display:block; transform:scale(1.03); animation:kenburns 26s ease-in-out infinite alternate; }
        .banner-hero::after{ content:''; position:absolute; inset:0; background:linear-gradient(to bottom, rgba(7,12,36,0) 45%, rgba(7,12,36,.55) 100%); pointer-events:none; }
        @keyframes kenburns{ 0%{transform:scale(1.03) translate(0,0)} 100%{transform:scale(1.08) translate(-1%,-1%)} }

        /* ---------- Inputs ---------- */
        .field-label{ display:block; font-size:.7rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:rgba(238,242,255,.65); margin-bottom:.5rem; transition:.2s; }
        .field:focus-within .field-label{ color:#fca5a5; transform:translateX(2px); }
        .glass-input{
            width:100%; background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.16);
            border-radius:14px; padding:.95rem 1.05rem; color:#fff; outline:none; font-size:.95rem; transition:.25s;
        }
        .glass-input::placeholder{ color:rgba(238,242,255,.35); }
        .glass-input:focus{ border-color:var(--hod-red); background:rgba(255,255,255,.1); box-shadow:0 0 0 4px rgba(239,68,68,.16), 0 0 34px rgba(239,68,68,.22); }
        select.glass-input{ cursor:pointer; }
        select.glass-input option{ color:#0b1020; }

        /* ---------- Option pills (radio / checkbox / days) ---------- */
        .opt-grid{ display:flex; flex-wrap:wrap; gap:.6rem; }
        .opt-pill{
            position:relative; display:flex; align-items:center; gap:.55rem;
            padding:.7rem 1rem; border:2px solid rgba(255,255,255,.18); border-radius:14px;
            cursor:pointer; transition:.2s; background:rgba(255,255,255,.05); user-select:none; flex:1 1 auto; min-width:120px;
        }
        .opt-pill:hover{ border-color:rgba(239,68,68,.55); }
        .opt-pill input{ position:absolute; opacity:0; width:0; height:0; }
        .opt-pill .check{ width:20px; height:20px; border-radius:8px; border:2px solid rgba(255,255,255,.35); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:.2s; }
        .opt-pill .check.round{ border-radius:50%; }
        .opt-pill .check svg{ width:13px; height:13px; opacity:0; transform:scale(.4); transition:.2s; color:#fff; }
        .opt-pill:has(input:checked){ border-color:var(--hod-red); background:rgba(239,68,68,.16); box-shadow:0 0 22px rgba(239,68,68,.25); }
        .opt-pill:has(input:checked) .check{ background:var(--hod-red); border-color:var(--hod-red); }
        .opt-pill:has(input:checked) .check svg{ opacity:1; transform:scale(1); }
        .opt-pill .txt{ font-size:.9rem; font-weight:600; }

        /* ---------- Step badges ---------- */
        .step-icon{ width:34px; height:34px; border-radius:50%; background:rgba(239,68,68,.15); color:#fca5a5; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; border:1px solid rgba(239,68,68,.35); }

        /* ---------- Ministers block ---------- */
        .ministers-card{ display:flex; gap:1rem; align-items:center; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.14); border-radius:18px; padding:1rem; }
        .ministers-card img{ width:84px; height:84px; border-radius:16px; object-fit:cover; border:2px solid rgba(239,68,68,.45); box-shadow:0 0 24px rgba(239,68,68,.25); flex-shrink:0; }

        /* ---------- CTA button ---------- */
        .cta{
            position:relative; overflow:hidden; width:100%;
            background:linear-gradient(120deg, var(--hod-red), var(--hod-red-2) 60%, #b91c1c);
            color:#fff; font-weight:800; font-size:1.05rem; padding:1.05rem; border-radius:16px; border:none; cursor:pointer;
            box-shadow:0 18px 40px -12px rgba(239,68,68,.7); transition:transform .2s, box-shadow .2s;
        }
        .cta:hover{ transform:translateY(-2px); box-shadow:0 24px 50px -12px rgba(239,68,68,.85); }
        .cta:active{ transform:translateY(0); }
        .cta::after{ content:''; position:absolute; top:0; left:-120%; width:60%; height:100%; background:linear-gradient(120deg, transparent, rgba(255,255,255,.45), transparent); transform:skewX(-20deg); animation:shimmer 3.2s infinite; }
        @keyframes shimmer{ 0%{left:-120%} 60%,100%{left:160%} }

        .reveal{ opacity:0; }
        .spinner{ width:20px; height:20px; border:3px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; }
        @keyframes spin{ to{ transform:rotate(360deg); } }

        /* ---------- Success check draw ---------- */
        .check-circle{ stroke:var(--hod-red); stroke-width:4; fill:none; stroke-dasharray:166; stroke-dashoffset:166; animation:drawCircle .6s cubic-bezier(.65,0,.45,1) forwards; }
        .check-mark{ stroke:var(--hod-red); stroke-width:5; fill:none; stroke-linecap:round; stroke-linejoin:round; stroke-dasharray:48; stroke-dashoffset:48; animation:drawCheck .35s .5s cubic-bezier(.65,0,.45,1) forwards; }
        @keyframes drawCircle{ to{ stroke-dashoffset:0; } }
        @keyframes drawCheck{ to{ stroke-dashoffset:0; } }

        @media (prefers-reduced-motion: reduce){
            *{ animation:none !important; transition:none !important; }
            .reveal{ opacity:1 !important; }
        }
        .custom-scrollbar::-webkit-scrollbar{ width:6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.25); border-radius:8px; }
        
        /* ---------- Rich Text Container ---------- */
        .rich-text p { margin-bottom: 1.25rem; }
        .rich-text p:last-child { margin-bottom: 0; }
        .rich-text ul { 
            list-style-type: disc; 
            padding-left: 1.25rem; 
            color: #ffcdb2; 
            margin-bottom: 1.25rem; 
        }
        .rich-text li { margin-bottom: 0.5rem; }
        .rich-text u { text-underline-offset: 3px; }

        /* ---------- Video Embed ---------- */
        .video-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            background-color: #0b1020;
            margin-top: 1.5rem;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.4);
            transition: all 0.3s ease;
        }
        .video-wrapper iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }
        /* ---------- Cinematic Countdown Overlay ---------- */
        .countdown-wrapper { 
            position: relative; 
            display: flex; 
            justify-content: center; 
            width: 100%; 
            margin-top: -24px; /* Pulls it up to overlap the banner */
            margin-bottom: 16px; 
            z-index: 20; 
        }
        .countdown-pill { 
            /* True glassmorphism background matching the main card */
            background: linear-gradient(140deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.04)); 
            backdrop-filter: blur(24px) saturate(150%); 
            -webkit-backdrop-filter: blur(24px) saturate(150%);
            
            /* Glass edge reflections and the red glow */
            border: 1px solid rgba(255, 255, 255, 0.25); 
            box-shadow: 0 10px 30px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.3); 
            
            border-radius: 9999px; 
            padding: 0.5rem 1.75rem; 
            display: inline-flex; 
            align-items: center; 
            gap: 1rem; 
        }
        .cd-group { display: flex; flex-direction: column; align-items: center; }
        .cd-val { 
            font-family: 'Courier New', Courier, monospace; 
            font-size: 1.35rem; 
            font-weight: 900; 
            color: #ffffff;
            /* Added a subtle shadow so the white text stays readable over bright parts of the image */
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.6); 
            letter-spacing: 0.05em; 
            line-height: 1; 
        }
        .cd-lbl { 
            font-size: 0.55rem; 
            font-weight: 800; 
            text-transform: uppercase; 
            color: rgba(255, 255, 255, 0.5); 
            letter-spacing: 0.15em; 
            margin-top: 0.2rem;
        }
        .cd-sep { 
            font-size: 1.2rem; 
            font-weight: 900; 
            color: rgba(239, 68, 68, 0.9); 
            animation: pulseOpacity 1s infinite; 
            line-height: 1; 
            padding-bottom: 0.4rem; 
        }
        @keyframes pulseOpacity { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
    </style>
</head>
<body class="relative min-h-screen flex flex-col">

    <div id="bannerBg"></div>
    <div class="mesh-bg"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>
    <div class="grain"></div>

    <main class="relative z-10 flex-grow flex items-center justify-center p-4 sm:p-6 lg:p-8">
        <div class="glass w-full max-w-2xl overflow-hidden relative">

            <!-- LOADING -->
            <div id="loadingState" class="p-16 flex flex-col items-center justify-center text-center">
                <div class="w-14 h-14 rounded-full border-4 border-white/20 border-t-hodRed animate-spin mb-5"></div>
                <p class="text-white/70 font-medium animate-pulse">Verifying secure link…</p>
            </div>

            <!-- ERROR -->
            <div id="errorState" class="hidden p-16 flex flex-col items-center justify-center text-center">
                <div class="w-16 h-16 bg-red-500/15 text-hodRed rounded-full flex items-center justify-center mb-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <h2 class="text-2xl font-extrabold text-white mb-2">Registration Unavailable</h2>
                <p id="errorMessage" class="text-white/65 mb-6">This link is invalid or the event has been closed.</p>
                <a href="/" class="bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-xl font-bold transition-colors border border-white/20">Return Home</a>
            </div>

            <!-- SUCCESS -->
            <div id="successState" class="hidden p-12 md:p-16 flex flex-col items-center justify-center text-center">
                <div class="w-24 h-24 mb-6">
                    <svg viewBox="0 0 52 52" class="w-full h-full">
                        <circle class="check-circle" cx="26" cy="26" r="24"/>
                        <path class="check-mark" d="M14 27l8 8 16-18"/>
                    </svg>
                </div>
                <h2 class="text-3xl font-extrabold gradient-text mb-2">You're Registered!</h2>
                <p class="text-white/70 mb-8 max-w-md">Your spot has been confirmed. We can't wait to fellowship with you. You can safely close this page.</p>
                
                <div class="flex flex-col sm:flex-row gap-4 w-full max-w-md justify-center">
                    <button onclick="shareEvent()" class="cta flex-1">Invite Others</button>
                    <!-- New Reset Button for Bulk Registrations -->
                    <button onclick="window.location.reload()" class="flex-1 bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-xl font-bold transition-colors border border-white/20">Register Another</button>
                </div>
            </div>

            <!-- FORM -->
            <div id="formContainer" class="hidden">
                <div id="bannerHero" class="banner-hero hidden">
                    <img id="bannerHeroImg" src="" alt="Event banner">
                </div>
                
                <div id="countdownAnchor"></div>

                <div class="px-6 py-7 md:px-10 md:py-8 pt-2 md:pt-4">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-white text-xs font-bold uppercase tracking-wider border border-white/20">
                            <svg class="w-4 h-4 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <span id="displayCategory">Event</span>
                        </div>
                        <button onclick="shareEvent()" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/10 border border-white/20 text-white/90 hover:border-hodRed hover:text-white text-xs font-bold transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                            Share
                        </button>
                    </div>

                    <h1 id="displayTitle" class="text-3xl md:text-[2.6rem] font-extrabold gradient-text tracking-tight leading-[1.1] mb-3"></h1>

                    <div class="flex flex-col gap-1.5 text-white/75 text-sm">
                        <p id="displayDate" class="flex items-center gap-2">
                            <svg class="w-4 h-4 shrink-0 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <span></span>
                        </p>
                        <p id="displayLocation" class="hidden flex items-center gap-2">
                            <svg class="w-4 h-4 shrink-0 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            <span></span>
                        </p>
                    </div>

                    <div id="displayDescription" class="hidden text-white/70 text-sm mt-4 leading-relaxed rich-text"></div>

                    <!-- Ministers -->
                    <div id="ministersWrap" class="hidden mt-6">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-1.5 h-5 rounded-full bg-hodRed"></span>
                            <h3 class="text-sm font-bold uppercase tracking-wider text-white/80">Ministering</h3>
                        </div>
                        <div id="ministersList" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
                    </div>
                </div>

                <form id="registrationForm" class="px-6 pb-8 md:px-10 md:pb-10 space-y-8">
                    <input type="hidden" name="action" value="submit_registration">
                    <input type="hidden" name="token" id="formToken">

                    <div id="guestFieldsSection" class="space-y-5 reveal">
                        <div class="flex items-center gap-3 border-b border-white/10 pb-2">
                            <div class="step-icon">1</div>
                            <h3 class="text-lg font-bold text-white">Your Information</h3>
                        <!-- PROXY TOGGLE -->
                            <label id="proxyToggleWrap" class="hidden flex items-center gap-2 cursor-pointer bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-lg border border-white/10 transition-colors">
                                <input type="checkbox" name="registering_someone_else" id="proxyToggle" value="1" class="w-4 h-4 rounded text-hodBlue border-gray-300 focus:ring-hodBlue focus:ring-offset-gray-900 bg-transparent">
                                <span class="text-xs font-bold text-white/80">Registering someone else?</span>
                            </label>
                        </div>
                        <p id="memberInfoNote" class="hidden text-xs text-white/50 -mt-1">Pulled from your member profile — update anything that's changed.</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="md:col-span-2 field">
                                <label class="field-label">Full Name *</label>
                                <input type="text" name="guest_name" id="guestNameInput" required placeholder="John Doe" class="glass-input">
                            </div>
                            <div class="field">
                                <label class="field-label">Phone Number *</label>
                                <input type="tel" name="guest_phone" id="guestPhoneInput" required placeholder="+234…" class="glass-input">
                            </div>
                            <div class="field">
                                <label class="field-label">Email Address</label>
                                <input type="email" name="guest_email" id="guestEmailInput" placeholder="Optional" class="glass-input">
                            </div>
                            <div class="field">
                                <label class="field-label">Gender *</label>
                                <select name="guest_gender" id="guestGenderInput" required class="glass-input">
                                    <option value="">-- Select Gender --</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="daysFieldsSection" class="hidden space-y-4 reveal">
                        <div class="flex items-center gap-3 border-b border-white/10 pb-2">
                            <div class="step-icon">2</div>
                            <h3 class="text-lg font-bold text-white">Which Day(s) Will You Attend?</h3>
                        </div>
                        <p class="text-xs text-white/50 -mt-1">This is a multi-day event. Select every day you plan to be there.</p>
                        <div id="dayCheckboxContainer" class="opt-grid"></div>
                    </div>

                    <div id="customFieldsSection" class="hidden space-y-6 reveal">
                        <div class="flex items-center gap-3 border-b border-white/10 pb-2">
                            <div class="step-icon">3</div>
                            <h3 class="text-lg font-bold text-white">Event Specifics</h3>
                        </div>
                        <div id="dynamicFieldsContainer" class="space-y-5"></div>
                    </div>

                    <button type="submit" id="submitBtn" class="cta reveal mt-2">Confirm Registration</button>
                </form>
            </div>

        </div>
    </main>

    <!-- Issue modal -->
    <div id="issueModal" class="hidden fixed inset-0 z-[100] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="glass max-w-md w-full p-6 md:p-7">
            <div class="w-12 h-12 bg-red-500/15 text-hodRed rounded-full flex items-center justify-center mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"></path></svg>
            </div>
            <h3 id="issueModalTitle" class="text-lg font-bold text-white mb-2">Please fix the following</h3>
            <ul id="issueModalList" class="text-sm text-white/75 space-y-2 list-disc list-inside mb-6"></ul>
            <button onclick="closeIssueModal()" class="cta">Got it</button>
        </div>
    </div>

    <script>
        const API_URL = '/api/registration_api.php';
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token');
        const isLoggedIn = <?php echo $is_logged_in; ?>;
        let currentEventInfo = null;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const esc = s => (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        
        function extractYouTubeID(url) {
            if (!url) return null;
            const regExp = /^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        }
        
        let countdownInterval;
        function initCountdown(dateString) {
            clearInterval(countdownInterval);
            
            // Standardize date for iOS/Safari compatibility
            const cleanDate = dateString.replace(/-/g, '/');
            const targetDate = new Date(cleanDate).getTime();
            
            if (isNaN(targetDate)) return;

            // Generate the initial HTML structure
            const html = `
                <div class="countdown-wrapper reveal">
                    <div class="countdown-pill">
                        <div class="cd-group"><span class="cd-val" id="cd-d">00</span><span class="cd-lbl">Days</span></div>
                        <span class="cd-sep">:</span>
                        <div class="cd-group"><span class="cd-val" id="cd-h">00</span><span class="cd-lbl">Hrs</span></div>
                        <span class="cd-sep">:</span>
                        <div class="cd-group"><span class="cd-val" id="cd-m">00</span><span class="cd-lbl">Min</span></div>
                        <span class="cd-sep">:</span>
                        <div class="cd-group"><span class="cd-val" id="cd-s">00</span><span class="cd-lbl">Sec</span></div>
                    </div>
                </div>
            `;
            $('#countdownAnchor').html(html);

            // Tick every second
            countdownInterval = setInterval(() => {
                const now = new Date().getTime();
                const distance = targetDate - now;

                if (distance < 0) {
                    clearInterval(countdownInterval);
                    $('#countdownAnchor').html(`
                        <div class="countdown-wrapper reveal">
                            <div class="countdown-pill">
                                <span class="cd-val text-hodRed tracking-widest">EVENT IS LIVE</span>
                            </div>
                        </div>
                    `);
                    return;
                }

                // Time calculations
                const d = Math.floor(distance / (1000 * 60 * 60 * 24));
                const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const s = Math.floor((distance % (1000 * 60)) / 1000);

                // Update DOM directly
                $('#cd-d').text(d.toString().padStart(2, '0'));
                $('#cd-h').text(h.toString().padStart(2, '0'));
                $('#cd-m').text(m.toString().padStart(2, '0'));
                $('#cd-s').text(s.toString().padStart(2, '0'));
            }, 1000);
        }

        function showError(msg){
            $('#loadingState, #formContainer').addClass('hidden');
            $('#errorMessage').text(msg);
            $('#errorState').removeClass('hidden');
            if(!reduceMotion) gsap.from('#errorState', {scale:.96, opacity:0, duration:.5, ease:'power3.out'});
        }

        function showIssueModal(messages, title){
            const list = Array.isArray(messages) ? messages : [messages];
            $('#issueModalTitle').text(title || 'Please fix the following');
            $('#issueModalList').html(list.map(m => `<li>${esc(m)}</li>`).join(''));
            $('#issueModal').removeClass('hidden');
            if(!reduceMotion) gsap.from('#issueModal .glass', {y:30, opacity:0, duration:.4, ease:'power3.out'});
        }
        function closeIssueModal(){ $('#issueModal').addClass('hidden'); }

        function validateForm(){
            const issues = [];
            if(!$('#guestNameInput').val().trim()) issues.push('Enter your full name.');
            if(!$('#guestPhoneInput').val().trim()) issues.push('Enter your phone number.');
            if(!$('#guestGenderInput').val()) issues.push('Select your gender.');

            if($('#daysFieldsSection').is(':visible') && $('input[name="selected_days[]"]:checked').length === 0){
                issues.push("Select at least one day you'll be attending.");
            }

            $('#dynamicFieldsContainer .field').each(function(){
                const req = $(this).find('[required]').length > 0;
                if(!req) return;
                const type = $(this).data('type');
                if(type === 'checkbox'){
                    if($(this).find('input[type="checkbox"]:checked').length === 0){
                        issues.push(`Please choose at least one option for "${$(this).data('label')}".`);
                    }
                } else if(type === 'radio'){
                    if($(this).find('input[type="radio"]:checked').length === 0){
                        issues.push(`Please select an option for "${$(this).data('label')}".`);
                    }
                } else {
                    const v = ($(this).find('input, select, textarea').first().val() || '').toString().trim();
                    if(!v) issues.push(`Please answer: "${$(this).data('label')}".`);
                }
            });
            return issues;
        }

        function renumberSteps(){
            let n = 0;
            $('#registrationForm > div:not(.hidden) .step-icon').each(function(){ n++; $(this).text(n); });
        }

        function shareEvent(){
            const url = window.location.href;
            const title = currentEventInfo ? currentEventInfo.title : 'Event Invitation';
            const text = currentEventInfo ? `You're invited: ${currentEventInfo.title}. Register here:` : 'You are invited! Register here:';
            if(navigator.share){
                navigator.share({ title, text, url }).catch(()=>{});
            } else {
                navigator.clipboard.writeText(url).then(()=>{
                    Toastify({ text:"Link copied! Share it with anyone.", duration:4000, style:{ background:"#10B981" } }).showToast();
                }).catch(()=>{
                    Toastify({ text:url, duration:6000, style:{ background:"#1e3a8a" } }).showToast();
                });
            }
        }

        function setBannerBg(url){
            const bg = document.getElementById('bannerBg');
            if(!bg || !url) return;
            bg.innerHTML = '<div class="bg-img" style="background-image:url(\'' + url + '\')"></div>';
        }
        function setBannerHero(url){
            const hero = document.getElementById('bannerHero');
            const img = document.getElementById('bannerHeroImg');
            if(!hero || !img || !url) return;
            img.src = url;
            hero.classList.remove('hidden');
        }

        function renderField(f){
            const required = f.is_required == 1 ? 'required' : '';
            const reqStar = f.is_required == 1 ? '<span class="text-hodRed">*</span>' : '';
            const name = `custom_fields[${f.id}]`;
            const ph = f.placeholder ? `placeholder="${esc(f.placeholder)}"` : '';
            let inner = '';

            if(f.field_type === 'textarea'){
                inner = `<textarea name="${name}" ${required} ${ph} class="glass-input min-h-[110px] resize-none"></textarea>`;
            } else if(f.field_type === 'select'){
                let opts = `<option value="">-- Select --</option>`;
                (f.field_options||[]).forEach(o => opts += `<option value="${esc(o)}">${esc(o)}</option>`);
                inner = `<select name="${name}" ${required} class="glass-input">${opts}</select>`;
            } else if(f.field_type === 'radio'){
                let pills = '';
                (f.field_options||[]).forEach(o => {
                    pills += `<label class="opt-pill"><input type="radio" name="${name}" value="${esc(o)}" ${required}><span class="check round"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg></span><span class="txt">${esc(o)}</span></label>`;
                });
                inner = `<div class="opt-grid">${pills}</div>`;
            } else if(f.field_type === 'checkbox'){
                let pills = '';
                (f.field_options||[]).forEach(o => {
                    pills += `<label class="opt-pill"><input type="checkbox" name="${name}[]" value="${esc(o)}"><span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg></span><span class="txt">${esc(o)}</span></label>`;
                });
                inner = `<div class="opt-grid">${pills}</div>`;
            } else {
                const t = (f.field_type==='email')?'email':(f.field_type==='number')?'number':(f.field_type==='date')?'date':'text';
                inner = `<input type="${t}" name="${name}" ${required} ${ph} class="glass-input">`;
            }

            return `<div class="field reveal" data-type="${f.field_type}" data-label="${esc(f.field_label)}">
                <label class="field-label">${esc(f.field_label)} ${reqStar}</label>${inner}
            </div>`;
        }

        $(document).ready(function(){
            if(!token){ showError("No registration token provided in the URL."); return; }
            $('#formToken').val(token);

            $.get(API_URL, { action:'fetch_form', token:token }, function(res){
                if(res.status !== 'success'){ showError(res.message); return; }

                const event = res.event_info;
                const eventDays = res.event_days || [];
                const customFields = res.custom_fields || [];
                const memberProfile = res.member_profile;
                currentEventInfo = event;

                if(event.allow_visitors == 0 && !isLoggedIn){
                    showError("This is a closed church event. Please log into your member account to register.");
                    $('#errorState a').text('Go to Login').attr('href','/login.php').removeClass('bg-white/10 text-white').addClass('bg-hodRed');
                    return;
                }

                // Header
                $('#displayTitle').text(event.title);
                
                // Trigger Cinematic Countdown
                if (event.event_date) {
                    initCountdown(event.event_date);
                }
                $('#displayCategory').text(event.event_category.replace('_',' '));
                const startObj = new Date(event.event_date);
                let dateLabel = startObj.toLocaleString('en-US', { weekday:'long', month:'long', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' });
                if(event.end_date && eventDays.length > 1){
                    const endObj = new Date(event.end_date + 'T00:00:00');
                    dateLabel = startObj.toLocaleString('en-US', { month:'long', day:'numeric', year:'numeric' }) + ' — ' + endObj.toLocaleString('en-US', { month:'long', day:'numeric', year:'numeric' });
                }
                $('#displayDate span').text(dateLabel);

                if(event.location){ $('#displayLocation span').text(event.location); $('#displayLocation').removeClass('hidden'); }
                
                // Inject Lazy-Loaded Branded YouTube Video & External Link
                $('.video-wrapper').remove(); 
                $('.external-reg-btn').remove(); // Clear previous if any
                
                let insertTarget = $('#displayLocation').parent();

                if (event.youtube_url) {
                    const ytID = extractYouTubeID(event.youtube_url);
                    if (ytID) {
                        // FIX: Swap maxresdefault to hqdefault to prevent the generic grey fallback
                        const thumbUrl = `https://img.youtube.com/vi/${ytID}/hqdefault.jpg`;
                        const embedUrl = `https://www.youtube-nocookie.com/embed/${ytID}?modestbranding=1&rel=0&iv_load_policy=3&autoplay=1`;
                        
                        const videoHTML = `
                            <div class="video-wrapper reveal group cursor-pointer bg-cover bg-center" id="ytWrapper" style="background-image: url('${thumbUrl}');">
                                <div class="absolute inset-0 bg-black/30 group-hover:bg-black/10 transition-colors duration-300"></div>
                                <button class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-16 h-16 rounded-full bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center shadow-2xl transition-transform duration-300 group-hover:scale-110 z-10">
                                    <svg class="w-8 h-8 text-white ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </button>
                            </div>`;
                            
                        insertTarget.after(videoHTML);
                        insertTarget = $('#ytWrapper'); // Move the target down for the next element
                        
                        $('#ytWrapper').one('click', function() {
                            $(this).html(`<iframe src="${embedUrl}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>`);
                            $(this).css('background-image', 'none'); 
                        });
                    }
                }

                // Inject External Registration Button (appears right under the video)
                if (event.external_registration_url) {
                    const extBtnHTML = `
                        <div class="mt-6 mb-2 reveal external-reg-btn">
                            <a href="${esc(event.external_registration_url)}" target="_blank" rel="noopener noreferrer" class="w-full relative overflow-hidden bg-white text-hodBlue hover:bg-gray-50 font-extrabold text-sm md:text-base py-4 px-6 rounded-2xl flex items-center justify-center gap-2 shadow-[0_0_30px_-10px_rgba(255,255,255,0.2)] transition-all border-2 border-white/40 hover:scale-[1.02]">
                                <svg class="w-5 h-5 text-hodRed shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                Continue to External Registration
                            </a>
                        </div>
                    `;
                    insertTarget.after(extBtnHTML);
                }

                if(event.description){ $('#displayDescription').html(event.description).removeClass('hidden'); }

                if(event.description){ $('#displayDescription').html(event.description).removeClass('hidden'); }
                if(event.banner_image_url){ setBannerBg(event.banner_image_url); setBannerHero(event.banner_image_url); }

                // Ministers (repeatable: photo + name per minister)
                if(Array.isArray(event.ministers) && event.ministers.length){
                    $('#ministersWrap').removeClass('hidden');
                    let mhtml = '';
                    event.ministers.forEach(m => {
                        const name = m.name ? esc(m.name) : '';
                        const img = m.image ? esc(m.image) : '';
                        const photo = img
                            ? `<img src="${img}" alt="${name}" class="w-full h-24 object-cover rounded-xl border border-white/15">`
                            : `<div class="w-full h-24 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-white/30 text-xs">No photo</div>`;
                        mhtml += `<div class="flex flex-col gap-2"><div class="rounded-xl overflow-hidden">${photo}</div><p class="text-white/85 text-sm font-semibold text-center leading-snug">${name}</p></div>`;
                    });
                    $('#ministersList').html(mhtml);
                } else if(event.ministers_image_url){
                    $('#ministersWrap').removeClass('hidden');
                    $('#ministersList').html(`<div class="flex flex-col gap-2"><div class="rounded-xl overflow-hidden"><img src="${esc(event.ministers_image_url)}" alt="Minister" class="w-full h-24 object-cover rounded-xl border border-white/15"></div><p class="text-white/85 text-sm font-semibold text-center leading-snug">Ministering</p></div>`);
                }

                // Prefill member & Setup Proxy Toggle
                let myProfileData = null;
                
                function fillMyData() {
                    if(!myProfileData) return;
                    $('#guestNameInput').val(`${myProfileData.first_name||''} ${myProfileData.last_name||''}`.trim());
                    if(myProfileData.phone) $('#guestPhoneInput').val(myProfileData.phone);
                    const memEmail = myProfileData.real_email || myProfileData.email;
                    if(memEmail) $('#guestEmailInput').val(memEmail);
                    if(myProfileData.gender) $('#guestGenderInput').val(myProfileData.gender);
                }

                if(isLoggedIn && memberProfile){
                    myProfileData = memberProfile;
                    fillMyData();
                    $('#memberInfoNote').removeClass('hidden');
                    $('#proxyToggleWrap').removeClass('hidden'); // Reveal the toggle to members
                }

                $('#proxyToggle').on('change', function(){
                    if($(this).is(':checked')){
                        // Clear fields for the guest
                        $('#guestNameInput, #guestPhoneInput, #guestEmailInput, #guestGenderInput').val('');
                        $('#memberInfoNote').addClass('hidden');
                        $('#guestFieldsSection h3').text("Guest's Information");
                    } else {
                        // Box unchecked: Restore logged-in member's original data
                        fillMyData();
                        $('#memberInfoNote').removeClass('hidden');
                        $('#guestFieldsSection h3').text("Your Information");
                    }
                });

                // Multi-day
                if(eventDays.length > 1){
                    let dayHtml = '';
                    eventDays.forEach(day => {
                        const dObj = new Date(day + 'T00:00:00');
                        const label = dObj.toLocaleString('en-US', { weekday:'short', month:'short', day:'numeric' });
                        dayHtml += `<label class="opt-pill"><input type="checkbox" name="selected_days[]" value="${day}"><span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg></span><span class="txt">${label}</span></label>`;
                    });
                    $('#dayCheckboxContainer').html(dayHtml);
                    $('#daysFieldsSection').removeClass('hidden');
                }

                // Dynamic custom fields
                if(customFields.length > 0){
                    $('#customFieldsSection').removeClass('hidden');
                    $('#dynamicFieldsContainer').html(customFields.map(renderField).join(''));
                }

                renumberSteps();

                $('#loadingState').addClass('hidden');
                $('#formContainer').removeClass('hidden');

                if(!reduceMotion && typeof gsap !== 'undefined'){
                    gsap.from('.glass', { y:40, opacity:0, scale:.98, duration:.9, ease:'power3.out' });
                    gsap.set('.reveal', { opacity:0, y:30 });
                    gsap.to('.reveal', { opacity:1, y:0, duration:.7, stagger:.08, delay:.25, ease:'power3.out' });
                } else {
                    $('.reveal').css('opacity',1);
                }
            }, 'json').fail(function(){
                showError("Could not connect to the server. Please check your internet connection.");
            });

            // Mouse parallax on mesh
            if(!reduceMotion && typeof gsap !== 'undefined'){
                $(document).on('mousemove', function(e){
                    const x = (e.clientX / window.innerWidth - .5);
                    const y = (e.clientY / window.innerHeight - .5);
                    gsap.to('.blob.b1', { x:x*40, y:y*40, duration:1.2, ease:'power2.out' });
                    gsap.to('.blob.b2', { x:x*-50, y:y*-50, duration:1.4, ease:'power2.out' });
                    gsap.to('.blob.b3', { x:x*30, y:y*-30, duration:1.6, ease:'power2.out' });
                });
            }

            // Submit
            $('#registrationForm').on('submit', function(e){
                e.preventDefault();
                const issues = validateForm();
                if(issues.length > 0){ showIssueModal(issues, 'Please fix the following'); return; }

                const btn = $('#submitBtn');
                const orig = btn.text();
                btn.prop('disabled', true).html('<span class="spinner"></span> Processing…');

                $.post(API_URL, $(this).serialize(), function(res){
                    btn.prop('disabled', false).text(orig);
                    if(res.status === 'success'){
                        $('#formContainer').addClass('hidden');
                        $('#successState').removeClass('hidden');
                        if(!reduceMotion && typeof gsap !== 'undefined'){
                            gsap.from('#successState', { scale:.94, opacity:0, duration:.6, ease:'power3.out' });
                        }
                        if(typeof confetti !== 'undefined'){
                            confetti({ particleCount:160, spread:90, origin:{ y:.4 }, colors:['#ef4444','#1e3a8a','#3b82f6','#fbbf24'] });
                            setTimeout(()=> confetti({ particleCount:90, spread:120, origin:{ y:.6 }, colors:['#ef4444','#3b82f6','#ffffff'] }), 250);
                        }
                        Toastify({ text:res.message, duration:5000, style:{ background:"#10B981" } }).showToast();
                    } else {
                        showIssueModal([res.message], res.status === 'warning' ? 'Heads up' : 'Something went wrong');
                    }
                }, 'json').fail(function(){
                    btn.prop('disabled', false).text(orig);
                    showIssueModal(['Could not reach the server. Please check your connection and try again.'], 'Connection Error');
                });
            });
        });
    </script>
</body>
</html>
