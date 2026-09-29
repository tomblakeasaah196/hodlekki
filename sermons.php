<?php
// 1. Bulletproof Database Connection
require_once __DIR__ . '/includes/db.php';

// 2. Set strict defaults FIRST
$og_title = "The Word Library | HOD Lekki Centre";
$og_desc = "Stream and download life-transforming messages.";
$og_image = "https://hodlc.lpc.cm/assets/images/hod_logo.svg";
$og_url = "https://hodlc.lpc.cm/sermons";
$autoOpenScript = "";

// 3. Safe Database Query
if (isset($_GET['sermon'])) {
    try {
        $stmt = $pdo->prepare("SELECT title, summary, cover_image_path, slug FROM sermons WHERE slug = ? AND is_published = 1 LIMIT 1");
        $stmt->execute([$_GET['sermon']]);
        $sharedSermon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sharedSermon) {
            // The (string) casting prevents PHP 8.3 Fatal TypeErrors if the database returns NULL
            $og_title = htmlspecialchars((string)$sharedSermon['title']) . " | HOD Lekki";
            $og_desc = htmlspecialchars((string)$sharedSermon['summary']);
            
            $cover = !empty($sharedSermon['cover_image_path']) ? $sharedSermon['cover_image_path'] : '/assets/images/hod_logo.svg';
            $og_image = "https://hodlc.lpc.cm" . $cover;
            
            $og_url = "https://hodlc.lpc.cm/sermons?sermon=" . $sharedSermon['slug'];
            
            // Store the script to safely inject it inside the <head> below
            $autoOpenScript = "<script>const autoOpenSlug = '" . $sharedSermon['slug'] . "';</script>";
        }
    } catch (Exception $e) {
        // If the DB query fails, fail silently. The page will load the defaults above instead of crashing.
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <!-- charset first so a long og:description can't push it past the 1024-byte sniff window -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Open Graph Tags for WhatsApp/Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $og_title ?>">
    <meta property="og:description" content="<?= $og_desc ?>">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:url" content="<?= $og_url ?>">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Safely inject the auto-open script here -->
    <?= $autoOpenScript ?>

    <title>Sermons | Household of David Lekki Centre</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/style.css">

    <!--
        Sermon modal styles live here, not in Tailwind classes: assets/css/style.css is a
        pre-built bundle that doesn't contain every utility this page used (e.g. bg-hodBlue/95,
        bg-gray-900/99), which is what left the modal panel and notes see-through.
    -->
    <style>
        :root { --sermon-panel-rgb: 15, 26, 51; }

        /* Modal panel: solid, so nothing from the page behind it bleeds through */
        .sermon-panel { background: rgb(var(--sermon-panel-rgb)); }

        /* Desktop: sermon (left) and conversation (right) scroll independently.
           The lg:overflow-* utilities in the markup aren't in the built style.css. */
        @media (min-width: 1024px) {
            .sermon-body { overflow: hidden; }
            .sermon-main { overflow-y: auto; }
        }

        /* Hero: show the artwork as-is, fade only the bottom edge into the panel */
        .sermon-hero-img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .sermon-hero-scrim {
            position: absolute; inset: 0; z-index: 10; pointer-events: none;
            background: linear-gradient(to top,
                rgb(var(--sermon-panel-rgb)) 0%,
                rgba(var(--sermon-panel-rgb), .78) 22%,
                rgba(var(--sermon-panel-rgb), 0) 55%);
        }
        #modalDetails .sermon-title-shadow { text-shadow: 0 2px 16px rgba(0, 0, 0, .55); }

        /* Sermon cards: light bottom scrim instead of a flat 40% black wash */
        .sermon-card-scrim {
            position: absolute; inset: 0; z-index: 10; pointer-events: none;
            background: linear-gradient(to top, rgba(0, 0, 0, .55) 0%, rgba(0, 0, 0, 0) 50%);
        }

        /* Full notes: opaque card, high-contrast text, real hanging bullets */
        .sermon-notes-card {
            background: #0a1120;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 1rem;
            padding: 1.5rem;
        }
        .sermon-notes {
            color: #e8ecf4; font-size: 1rem; line-height: 1.8; font-weight: 400;
            max-width: 70ch; overflow-wrap: anywhere;
        }
        .sermon-notes p { margin: 0 0 .85rem; }
        .sermon-notes .note-label { color: #fff; font-weight: 600; }
        .sermon-notes .note-sub { color: #fff; font-weight: 600; margin: 1.25rem 0 .5rem; }
        .sermon-notes .note-h {
            font-family: 'Montserrat', sans-serif; font-weight: 700; color: #fff;
            font-size: 1.125rem; line-height: 1.4;
            margin: 1.9rem 0 .7rem; padding-left: .75rem; border-left: 3px solid #D11920;
        }
        .sermon-notes .note-li { position: relative; padding-left: 1.4rem; margin: 0 0 .6rem; }
        .sermon-notes .note-li::before {
            content: ''; position: absolute; left: .25rem; top: .8em;
            width: .4rem; height: .4rem; border-radius: 50%; background: #D11920;
        }
        .sermon-notes > :first-child { margin-top: 0; }

        /* Audio: dark controls so the time readout isn't white-on-white */
        #modalAudio { color-scheme: dark; }

        /* Share buttons (modal row + card share sheet) */
        .share-row { display: flex; flex-wrap: wrap; gap: .5rem; }
        .share-btn {
            display: inline-flex; align-items: center; gap: .5rem;
            padding: .55rem .9rem; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .08); color: #fff; cursor: pointer;
            font: 600 .75rem/1 'Inter', sans-serif; letter-spacing: .02em; text-decoration: none;
            transition: background-color .2s, border-color .2s, transform .2s;
        }
        .share-btn:hover { transform: translateY(-1px); }
        .share-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .share-btn svg { width: 1rem; height: 1rem; flex: none; fill: currentColor; }
        .share-btn--wa { background: #25D366; border-color: #25D366; color: #06331a; }
        .share-btn--wa:hover { background: #3be07a; }
        .share-btn--fb:hover { background: #1877F2; border-color: #1877F2; }
        .share-btn--x:hover { background: #000; border-color: rgba(255, 255, 255, .5); }
        .share-btn--tg:hover { background: #229ED9; border-color: #229ED9; }
        .share-btn--mail:hover, .share-btn--copy:hover, .share-btn--more:hover {
            background: #D11920; border-color: #D11920;
        }

        /* Card share icon (top-right of the thumbnail) */
        .sermon-card-share {
            position: absolute; top: .75rem; right: .75rem; z-index: 20;
            width: 2.25rem; height: 2.25rem; border-radius: 999px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0, 0, 0, .55); border: 1px solid rgba(255, 255, 255, .25); color: #fff;
            transition: background-color .2s, border-color .2s;
        }
        .sermon-card-share:hover { background: #D11920; border-color: #D11920; }
        .sermon-card-share svg { width: 1rem; height: 1rem; }

        /* Share sheet opened from a card */
        .share-sheet {
            position: fixed; inset: 0; z-index: 70;
            display: flex; align-items: flex-end; justify-content: center;
        }
        .share-sheet[hidden] { display: none; }
        .share-sheet__backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .75); }
        .share-sheet__panel {
            position: relative; width: 100%; max-width: 30rem; padding: 1.5rem;
            background: rgb(var(--sermon-panel-rgb)); border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 1.5rem 1.5rem 0 0; box-shadow: 0 20px 60px rgba(0, 0, 0, .6);
        }
        @media (min-width: 640px) {
            .share-sheet { align-items: center; padding: 1.5rem; }
            .share-sheet__panel { border-radius: 1.5rem; }
        }
    </style>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="https://www.youtube.com/iframe_api"></script>
</head>
<body class="bg-hodBlue text-gray-200 antialiased overflow-x-hidden selection:bg-hodRed selection:text-white min-h-screen flex flex-col font-sans">

    <div class="fixed inset-0 z-[-1] overflow-hidden bg-black">
        <img src="https://images.unsplash.com/photo-1510590337019-5ef8d3d32116?ixlib=rb-4.0.3&auto=format&fit=crop&w=2000&q=80" 
             alt="Worship Hands" class="w-full h-full object-cover opacity-40 animate-slow-pan mix-blend-luminosity">
        <div class="absolute inset-0 bg-gradient-to-b from-hodBlue/80 via-hodBlue/60 to-black/95"></div>
    </div>

    <header class="fixed top-0 w-full z-40 glass-panel border-b-0 border-white/5">
        <div class="max-w-7xl mx-auto px-6 h-24 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <img src="/assets/images/hod_logo.svg" alt="HOD Logo" class="h-12 brightness-0 invert" onerror="this.src='https://placehold.co/150x50?text=HOD'">
                <div class="hidden sm:block border-l border-white/20 pl-4">
                    <h1 class="font-heading text-white text-lg tracking-widest uppercase font-bold">Household of David</h1>
                    <p class="text-hodRed text-[10px] tracking-[0.2em] uppercase font-bold">Lekki Centre</p>
                </div>
            </div>
            <a href="https://householdofdavid.org" target="_blank" class="text-sm font-semibold text-white hover:text-hodRed transition-colors tracking-wider uppercase border border-white/20 px-5 py-2 rounded-full hover:bg-white/10">Visit Main Site</a>
        </div>
    </header>

    <main class="flex-1 pt-32 pb-20">
        <div class="max-w-7xl mx-auto px-6">
            
            <div class="flex flex-col items-center text-center mt-10 md:mt-20 mb-24 animate-fade-in-up">
                <span class="px-4 py-1.5 rounded-full border border-hodRed/30 bg-hodRed/10 text-hodRed text-xs font-bold uppercase tracking-widest mb-6 backdrop-blur-sm">Welcome to the Sanctuary</span>
                
                <div class="min-h-[140px] md:min-h-[220px] flex items-center justify-center w-full max-w-5xl transition-all duration-500">
                    <div id="scriptureRotator" class="w-full transition-opacity duration-1000">
                        <h2 id="scriptureText" class="font-heading text-white font-bold leading-tight transition-all duration-500 text-3xl md:text-5xl lg:text-6xl" style="text-shadow: 0 4px 20px rgba(0,0,0,0.5);">"The entrance of thy words giveth light."</h2>
                        <p id="scriptureRef" class="text-hodRed font-sans font-bold tracking-widest uppercase mt-4 text-sm">- Psalm 119:130</p>
                    </div>
                </div>
            </div>

            <div class="animate-fade-in-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between mb-10 border-b border-white/10 pb-4">
                    <h2 class="text-3xl font-heading font-bold text-white">The Word Library</h2>
                    <div class="h-1 w-20 bg-hodRed rounded-full"></div>
                </div>

                <div id="sermonsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <div class="col-span-full py-20 text-center" id="loadingState">
                        <svg class="animate-spin h-10 w-10 text-hodRed mx-auto mb-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <p class="text-gray-400 font-heading tracking-widest animate-pulse">Loading Archives...</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Sticky Live Broadcast Launcher -->
    <div id="liveBroadcastBar" class="fixed bottom-0 left-0 w-full z-50 transform translate-y-full transition-transform duration-500">
        <div class="bg-gradient-to-r from-hodBlue via-[#152750] to-black border-t border-hodRed/30 shadow-[0_-10px_40px_rgba(0,0,0,0.5)]">
            <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="relative flex h-4 w-4">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-hodRed opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-4 w-4 bg-hodRed border-2 border-white"></span>
                    </div>
                    <div>
                        <p class="text-white font-bold text-sm md:text-base tracking-wide">Live Audio Broadcast</p>
                        <p class="text-gray-400 text-[10px] uppercase tracking-widest hidden sm:block">Household of David Lekki Service</p>
                    </div>
                </div>
                <button onclick="launchLivePlayer()" class="bg-hodRed hover:bg-red-700 text-white px-6 py-2.5 rounded-full text-xs font-bold uppercase tracking-widest shadow-lg shadow-red-900/50 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Listen Now
                </button>
            </div>
        </div>
    </div>
    
    <footer class="glass-panel border-t border-white/10 mt-auto">
        <div class="max-w-7xl mx-auto px-6 py-8 text-center flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-sm text-gray-400 font-light">&copy; <script>document.write(new Date().getFullYear())</script> Household of David Lekki Centre. All Rights Reserved.</p>
            <p class="text-xs text-gray-500 uppercase tracking-widest font-semibold">Jesus is Lord</p>
        </div>
    </footer>

    <div id="sermonModal" class="fixed inset-0 z-50 hidden flex flex-col justify-end sm:justify-center items-center p-0 sm:p-6 opacity-0 transition-opacity duration-500">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-xl" onclick="closeSermon()"></div>
        
        <div class="sermon-panel relative w-full max-w-6xl h-[95vh] sm:h-[85vh] border border-white/10 sm:rounded-3xl shadow-2xl flex flex-col transform translate-y-full sm:scale-95 transition-transform duration-500 overflow-hidden">
            
            <div class="absolute top-4 right-4 z-50">
                <button onclick="closeSermon()" class="bg-black/50 text-white hover:bg-hodRed hover:text-white w-10 h-10 rounded-full flex items-center justify-center transition-all backdrop-blur-md border border-white/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="sermon-body flex-1 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden custom-scrollbar">
                
                <div class="sermon-main w-full lg:w-2/3 border-b lg:border-b-0 lg:border-r border-white/10 flex flex-col lg:overflow-y-auto custom-scrollbar">
                    
                    <div id="modalVideoContainer" class="w-full aspect-video bg-black hidden shrink-0 relative">
                        <div id="youtubePlayer" class="absolute inset-0 w-full h-full"></div>
                    </div>
                    
                    <div id="modalImageContainer" class="w-full h-64 sm:h-80 bg-gray-900 shrink-0 relative overflow-hidden">
                        <img id="modalCover" src="" alt="" class="sermon-hero-img">
                        <div class="sermon-hero-scrim"></div>
                    </div>

                    <div id="modalDetails" class="p-6 md:p-8 -mt-20 relative z-20 shrink-0 transition-all duration-300">
                        <span id="modalType" class="text-white text-[10px] font-bold uppercase tracking-widest bg-hodRed px-3 py-1 rounded-full shadow-lg">Service Type</span>
                        <h2 id="modalTitle" class="sermon-title-shadow text-3xl md:text-4xl font-heading text-white font-bold mt-4 leading-tight">Sermon Title</h2>
                        <p class="text-gray-300 mt-2 font-light tracking-wide text-sm md:text-base">Ministering: <span id="modalPreacher" class="font-bold text-white">Preacher</span> <span class="mx-2 opacity-50">•</span> <span id="modalDate">Date</span></p>
                        
                        <div id="modalShare" class="mt-6 hidden">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Share this message</p>
                            <div id="modalShareButtons" class="share-row"></div>
                        </div>

                        <div id="modalAudioContainer" class="mt-8 bg-white/5 p-4 rounded-2xl border border-white/10 hidden backdrop-blur-md">
                            <div class="flex items-center justify-between mb-3 px-2">
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Listen Audio</p>
                                <button id="btnDownload" class="text-hodRed hover:text-white flex items-center gap-2 text-xs font-bold transition-colors uppercase tracking-wider">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Download
                                </button>
                            </div>
                            <audio id="modalAudio" controls preload="metadata"></audio>
                        </div>
                    </div>

                    <div class="px-6 pb-8 md:px-8 flex-1 w-full">
                        <div class="sermon-notes-card">
                            <div class="sermon-notes" id="modalNotes"></div>
                        </div>
                    </div>
                </div>

                <div class="w-full lg:w-1/3 bg-black/40 flex flex-col h-full lg:h-auto lg:max-h-full">
                    <div class="p-5 border-b border-white/10 shrink-0">
                        <h3 class="font-heading font-bold text-lg text-white">Join the Conversation</h3>
                    </div>

                    <div class="p-5 shrink-0 bg-white/5 border-b border-white/10">
                        <form id="commentForm" class="space-y-3">
                            <input type="hidden" name="action" value="submit_comment">
                            <input type="hidden" name="sermon_id" id="commentSermonId">
                            <input type="hidden" name="time_opened" id="timeOpened">
                            
                            <input type="text" name="honeypot_contact" class="hidden-trap" tabindex="-1" autocomplete="off" placeholder="Leave empty">
                            
                            <input type="text" name="author_name" placeholder="Your Name (Optional)" class="w-full bg-black/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white focus:border-hodRed outline-none transition-colors">
                            <textarea name="comment_text" required rows="2" placeholder="What did you learn? Drop a question..." class="w-full bg-black/50 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-hodRed outline-none transition-colors resize-none"></textarea>
                            
                            <div class="flex items-center justify-between">
                                <label class="flex items-center gap-2 cursor-pointer group text-xs text-gray-300">
                                    <input type="checkbox" name="is_question" class="w-4 h-4 rounded border-white/20 bg-black/50 checked:bg-hodRed checked:border-hodRed transition-colors">
                                    <span class="group-hover:text-white transition-colors">This is a Question</span>
                                </label>
                                <button type="submit" class="bg-white/10 hover:bg-hodRed text-white px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-widest transition-all duration-300 shadow-lg">Submit</button>
                            </div>
                        </form>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto custom-scrollbar space-y-3" id="modalCommentsList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Share sheet, opened from the share icon on a sermon card -->
    <div id="shareSheet" class="share-sheet" hidden role="dialog" aria-modal="true" aria-labelledby="shareSheetTitle">
        <div class="share-sheet__backdrop" onclick="closeShareSheet()"></div>
        <div class="share-sheet__panel">
            <div class="flex items-start justify-between gap-4 mb-1">
                <h3 id="shareSheetTitle" class="font-heading font-bold text-lg text-white">Share this message</h3>
                <button type="button" onclick="closeShareSheet()" aria-label="Close" class="bg-black/50 text-white hover:bg-hodRed w-8 h-8 rounded-full flex items-center justify-center transition-all border border-white/20 shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <p id="shareSheetSermon" class="text-sm text-gray-300 mb-5"></p>
            <div id="shareSheetButtons" class="share-row"></div>
        </div>
    </div>

    <script>
        const API_URL = '/api/public_sermons_api.php';
        let ytPlayer;
        let playTracked = false;
        let openSlug = null;        // slug of the sermon currently open in the modal
        const sermonCache = {};     // slug -> card data, so a card can be shared without opening it

        // --- Sharing ---
        // Brand glyphs from Simple Icons (CC0), 24x24 viewBox
        const svgIcon = d => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`;
        const SHARE_ICONS = {
            whatsapp: svgIcon('M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z'),
            facebook: svgIcon('M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'),
            x: svgIcon('M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z'),
            telegram: svgIcon('M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z'),
            // Generic glyphs (stroke style, not brand marks)
            share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>',
            mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
            link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 007.07 0l3-3a5 5 0 00-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 00-7.07 0l-3 3a5 5 0 007.07 7.07l1.5-1.5"/></svg>'
        };

        // Always share the clean /sermons?sermon=<slug> URL: it's what sermons.php builds its
        // og: tags from, so WhatsApp/Facebook show the cover image and summary.
        function sermonShareData(s) {
            const url = window.location.origin + '/sermons?sermon=' + encodeURIComponent(s.slug);
            const text = [s.title, [s.preacher, s.nice_date].filter(Boolean).join(' • ')].filter(Boolean).join('\n');
            return { url: url, title: s.title, text: text };
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
            return new Promise((resolve, reject) => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
                document.body.appendChild(ta);
                ta.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                ok ? resolve() : reject();
            });
        }

        function renderShareButtons(container, s) {
            const d = sermonShareData(s);
            const enc = encodeURIComponent;
            const links = [
                { cls: 'wa',   label: 'WhatsApp', icon: SHARE_ICONS.whatsapp, href: 'https://wa.me/?text=' + enc(d.text + '\n' + d.url) },
                { cls: 'fb',   label: 'Facebook', icon: SHARE_ICONS.facebook, href: 'https://www.facebook.com/sharer/sharer.php?u=' + enc(d.url) },
                { cls: 'x',    label: 'X',        icon: SHARE_ICONS.x,        href: 'https://twitter.com/intent/tweet?text=' + enc(d.text) + '&url=' + enc(d.url) },
                { cls: 'tg',   label: 'Telegram', icon: SHARE_ICONS.telegram, href: 'https://t.me/share/url?url=' + enc(d.url) + '&text=' + enc(d.text) },
                { cls: 'mail', label: 'Email',    icon: SHARE_ICONS.mail,     href: 'mailto:?subject=' + enc(d.title) + '&body=' + enc(d.text + '\n\n' + d.url) }
            ];

            const $c = $(container).empty();
            links.forEach(l => {
                $('<a>', { href: l.href, target: '_blank', rel: 'noopener noreferrer', 'class': 'share-btn share-btn--' + l.cls })
                    .html(l.icon + '<span>' + l.label + '</span>')
                    .appendTo($c);
            });

            $('<button>', { type: 'button', 'class': 'share-btn share-btn--copy' })
                .html(SHARE_ICONS.link + '<span>Copy link</span>')
                .on('click', function() {
                    copyToClipboard(d.url).then(
                        () => Toastify({ text: 'Link copied', style: { background: '#1D356A', color: 'white' } }).showToast(),
                        () => window.prompt('Copy this link:', d.url)
                    );
                })
                .appendTo($c);

            // Phones and some desktops expose the system share sheet (every installed app)
            if (navigator.share) {
                $('<button>', { type: 'button', 'class': 'share-btn share-btn--more' })
                    .html(SHARE_ICONS.share + '<span>More…</span>')
                    .on('click', function() {
                        navigator.share({ title: d.title, text: d.text, url: d.url }).catch(() => {}); // AbortError = user cancelled
                    })
                    .appendTo($c);
            }
        }

        function openShareSheet(slug) {
            const s = sermonCache[slug];
            if (!s) return;
            $('#shareSheetSermon').text(s.title);
            renderShareButtons('#shareSheetButtons', s);
            $('#shareSheet').prop('hidden', false);
        }

        function closeShareSheet() {
            $('#shareSheet').prop('hidden', true);
        }

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeShareSheet();
        });

        // --- Full notes: plain text from the admin textarea -> readable blocks ---
        // "1. Heading" -> heading, "• item" / "- item" -> hanging bullet, "Label: text" -> bold label.
        // Built with .text() so notes are never interpreted as HTML.
        function renderNotes(raw) {
            const $n = $('#modalNotes').empty();
            const bulletRe = /^(?:[•·▪◦]\s*|[-–*]\s+)/;

            String(raw || '').replace(/\r\n?/g, '\n').split('\n').forEach(line => {
                const t = line.trim();
                if (!t) return;

                if (t.length <= 90 && /^\d+[.)]\s+\S/.test(t)) {
                    $n.append($('<h4>').addClass('note-h').text(t));
                } else if (bulletRe.test(t)) {
                    $n.append($('<p>').addClass('note-li').text(t.replace(bulletRe, '')));
                } else {
                    const m = t.match(/^([A-Za-z]+(?: [A-Za-z]+){0,2}):\s*(.*)$/);
                    if (m && m[2] === '') {
                        $n.append($('<p>').addClass('note-sub').text(t));
                    } else if (m) {
                        $n.append($('<p>').append(
                            $('<span>').addClass('note-label').text(m[1] + ':'),
                            document.createTextNode(' ' + m[2])
                        ));
                    } else {
                        $n.append($('<p>').text(t));
                    }
                }
            });

            if (!$n.children().length) {
                $n.append($('<p>').addClass('italic opacity-50').text('Detailed notes are not available.'));
            }
        }

        // Init YouTube API - Patched for CORS and Origin security
        function onYouTubeIframeAPIReady() {
            ytPlayer = new YT.Player('youtubePlayer', {
                height: '100%', 
                width: '100%', 
                videoId: '', 
                playerVars: { 
                    'autoplay': 0, 
                    'rel': 0,
                    'enablejsapi': 1, // Explicitly enables the control API
                    'origin': window.location.origin // Whitelists our domain to stop the CORS error
                },
                events: { 'onStateChange': onPlayerStateChange }
            });
        }

        function onPlayerStateChange(event) {
            if (event.data == YT.PlayerState.PLAYING && !playTracked) trackEvent('play');
        }

        document.getElementById('modalAudio').addEventListener('play', function() {
            if(!playTracked) trackEvent('play');
        });

        function trackEvent(eventType) {
            const sermonId = $('#commentSermonId').val();
            if(!sermonId) return;
            $.post(API_URL, { action: 'track_analytics', type: eventType, sermon_id: sermonId });
            if(eventType === 'play') playTracked = true;
        }

        function loadSermons() {
            $.getJSON(API_URL, { action: 'fetch_sermons' })
            .done(function(res) {
                if(res.status === 'success') {
                    let html = '';
                    res.sermons.forEach(s => {
                        sermonCache[s.slug] = s;
                        const cover = s.cover_image_path || 'https://images.unsplash.com/photo-1438232992991-995b7058bbb3?w=800&q=80';
                        html += `
                        <div onclick="openSermon('${s.slug}')" class="glass-card rounded-3xl overflow-hidden cursor-pointer group flex flex-col h-[400px]">
                            <div class="h-48 relative overflow-hidden shrink-0">
                                <img src="${cover}" class="w-full h-full object-cover transform group-hover:scale-110 transition-transform duration-700">
                                <div class="sermon-card-scrim"></div>
                                <button type="button" onclick="event.stopPropagation(); openShareSheet('${s.slug}')" class="sermon-card-share" aria-label="Share this sermon" title="Share">${SHARE_ICONS.share}</button>
                                <div class="absolute bottom-3 left-3 z-20 bg-hodRed text-white text-[9px] font-bold uppercase tracking-widest px-3 py-1 rounded-full">${s.service_type}</div>
                            </div>
                            <div class="p-6 flex-1 flex flex-col">
                                <h3 class="text-xl font-heading text-white font-bold leading-tight group-hover:text-hodRed transition-colors line-clamp-2">${s.title}</h3>
                                <p class="text-xs text-gray-400 mt-2 uppercase tracking-widest font-semibold">${s.preacher} <span class="mx-1">•</span> ${s.nice_date}</p>
                                <p class="text-sm text-gray-400 mt-4 font-light line-clamp-3 leading-relaxed">${s.summary || 'Click to experience the full sermon.'}</p>
                                <div class="mt-auto pt-4 flex items-center justify-between border-t border-white/10 text-[10px] text-gray-500 font-bold uppercase tracking-wider">
                                    <span class="flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg> ${s.view_count || 0}</span>
                                    <span class="flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg> ${s.play_count || 0}</span>
                                </div>
                            </div>
                        </div>`;
                    });
                    $('#sermonsGrid').html(html || '<div class="col-span-full py-12 text-center text-gray-400">Archives updating...</div>');
                }
            })
            .fail(function(jqXHR) {
                // Now catches backend crashes
                let errorMsg = "Database Connection Error";
                try {
                    let err = JSON.parse(jqXHR.responseText);
                    if(err.message) errorMsg = err.message;
                } catch(e) {}
                $('#loadingState').html(`<div class="bg-red-900/50 border border-red-500 text-white p-4 rounded-xl max-w-lg mx-auto">${errorMsg}</div>`);
            });
        }

        function openSermon(slugOrId) {
    $('body').css('overflow', 'hidden');
    $('#modalTitle').text('Loading...');
    $('#modalCommentsList').empty();
    $('#modalNotes').empty();
    $('#modalShare').addClass('hidden');
    openSlug = null;
    $('#modalVideoContainer, #modalAudioContainer').addClass('hidden');
    $('#modalImageContainer').removeClass('hidden');
    
    const modal = document.getElementById('sermonModal');
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        modal.children[1].classList.remove('translate-y-full', 'sm:scale-95');
    });

    $('#timeOpened').val(Date.now());
    playTracked = false;

    // Send the slug to the backend API
    $.getJSON(API_URL, { action: 'get_sermon', slug: slugOrId }, function(res) {
        if(res.status === 'success') {
            const s = res.sermon;
            
            // Keep using s.id here so comments still save to the correct numeric ID
            $('#commentSermonId').val(s.id); 
            openSlug = s.slug;
            
            $('#modalType').text(s.service_type);
            $('#modalTitle').text(s.title);
            $('#modalPreacher').text(s.preacher);
            $('#modalDate').text(s.nice_date);
            
            renderNotes(s.full_notes);

            renderShareButtons('#modalShareButtons', s);
            $('#modalShare').removeClass('hidden');

            if(s.youtube_link) {
                $('#modalImageContainer').addClass('hidden');
                $('#modalVideoContainer').removeClass('hidden');
                $('#modalDetails').removeClass('-mt-20').addClass('pt-8'); 
                
                let ytId = extractYouTubeID(s.youtube_link);
                if(ytPlayer && ytId) ytPlayer.loadVideoById(ytId);
            } else {
                $('#modalImageContainer').removeClass('hidden');
                $('#modalVideoContainer').addClass('hidden');
                $('#modalDetails').addClass('-mt-20').removeClass('pt-8'); 
                
                $('#modalCover').attr('src', s.cover_image_path || 'https://placehold.co/800x400/111/444');
            }

            if(s.audio_file_path) {
                $('#modalAudioContainer').removeClass('hidden');
                $('#modalAudio').attr('src', s.audio_file_path);
                $('#btnDownload').off('click').on('click', function() {
                    const a = document.createElement('a');
                    a.href = s.audio_file_path;
                    a.download = `${s.title}.mp3`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    trackEvent('download');
                });
            }

            renderComments(res.comments);
            trackEvent('view');
            
            // Update the URL bar beautifully to show the shareable link
            window.history.pushState({}, '', '?sermon=' + slugOrId);
            
        } else {
            closeSermon();
            Toastify({ text: res.message, style: { background: "#D11920" } }).showToast();
        }
    });
}

        function extractYouTubeID(url) {
            const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        }

        function renderComments(comments) {
            let list = $('#modalCommentsList').empty();
            if(comments.length === 0) {
                list.append('<p class="text-xs text-gray-500 italic text-center py-4">Be the first to share a reflection.</p>');
                return;
            }
            
            comments.forEach(c => {
                const wrapper = $('<div>').addClass('bg-white/5 border border-white/5 p-4 rounded-xl');
                const header = $('<div>').addClass('flex items-center justify-between mb-2');
                
                const author = $('<h4>').addClass('text-sm font-bold text-gray-200').text(c.author_name);
                if(c.is_question == 1) author.append($('<span>').addClass('text-hodRed border border-hodRed/30 bg-hodRed/10 px-2 py-0.5 rounded text-[8px] uppercase tracking-widest ml-2').text('Question'));
                
                header.append(author);
                header.append($('<span>').addClass('text-[10px] text-gray-500').text(c.nice_date));
                
                const body = $('<p>').addClass('text-xs text-gray-400 leading-relaxed').text(c.comment_text);
                wrapper.append(header).append(body);
                list.append(wrapper);
            });
        }

        function closeSermon() {
    $('body').css('overflow', '');
    const modal = document.getElementById('sermonModal');
    if(ytPlayer && ytPlayer.stopVideo) ytPlayer.stopVideo(); 
    const audio = document.getElementById('modalAudio');
    if(audio) { audio.pause(); audio.currentTime = 0; }

    modal.classList.add('opacity-0');
    modal.children[1].classList.add('translate-y-full', 'sm:scale-95');
    
    // ADD THIS LINE: Clean the URL when closing the modal
    window.history.pushState({}, '', window.location.pathname);
    
    setTimeout(() => { modal.classList.add('hidden'); }, 500);
}

        $('#commentForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const origText = btn.text();
            const slug = openSlug;
            btn.prop('disabled', true).text('Submitting...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).text(origText);
                if(res.status === 'success') {
                    $('#commentForm')[0].reset();
                    Toastify({ text: res.message, style: { background: "#1D356A", color: "white" } }).showToast();
                    
                    // get_sermon looks sermons up by slug (not id). Skip if the visitor has since opened another one.
                    if(slug) {
                        $.getJSON(API_URL, { action: 'get_sermon', slug: slug }, function(fresh) {
                            if(fresh.status === 'success' && openSlug === slug) renderComments(fresh.comments);
                        });
                    }
                } else {
                    Toastify({ text: res.message, style: { background: "#D11920" } }).showToast();
                }
            }, 'json');
        });
        
        // --- Rotating Scriptures Logic ---
        const scriptures = [
            { text: "The entrance of thy words giveth light.", ref: "Psalm 119:130" },
            { text: "Your word is a lamp to my feet and a light to my path.", ref: "Psalm 119:105" },
            { text: "For the word of God is living and active, sharper than any two-edged sword.", ref: "Hebrews 4:12" },
            { text: "He sent out his word and healed them, and delivered them from their destruction.", ref: "Psalm 107:20" },
            { text: "So faith comes from hearing, and hearing through the word of Christ.", ref: "Romans 10:17" },
            { text: "All Scripture is breathed out by God and profitable for teaching, for reproof, for correction, and for training in righteousness.", ref: "2 Timothy 3:16" },
            { text: "Let the word of Christ dwell in you richly, teaching and admonishing one another in all wisdom.", ref: "Colossians 3:16" },
            { text: "But be doers of the word, and not hearers only, deceiving yourselves.", ref: "James 1:22" },
            { text: "For as the rain and the snow come down from heaven... so shall my word be that goes out from my mouth; it shall not return to me empty, but it shall accomplish that which I purpose.", ref: "Isaiah 55:10-11" },
            { text: "This Book of the Law shall not depart from your mouth, but you shall meditate on it day and night, so that you may be careful to do according to all that is written in it.", ref: "Joshua 1:8" },
            { text: "My son, pay attention to what I say; turn your ear to my words. Do not let them out of your sight, keep them within your heart; for they are life to those who find them.", ref: "Proverbs 4:20-22" },
            { text: "Heaven and earth will pass away, but my words will not pass away.", ref: "Matthew 24:35" },
            { text: "In the beginning was the Word, and the Word was with God, and the Word was God.", ref: "John 1:1" },
            { text: "And you will know the truth, and the truth will set you free.", ref: "John 8:32" },
            { text: "The law of the Lord is perfect, reviving the soul; the testimony of the Lord is sure, making wise the simple.", ref: "Psalm 19:7" },
            { text: "Your words were found, and I ate them, and your words became to me a joy and the delight of my heart.", ref: "Jeremiah 15:16" },
            { text: "I have hidden your word in my heart that I might not sin against you.", ref: "Psalm 119:11" },
            { text: "Every word of God proves true; he is a shield to those who take refuge in him.", ref: "Proverbs 30:5" },
            { text: "It is the Spirit who gives life; the flesh is no help at all. The words that I have spoken to you are spirit and life.", ref: "John 6:63" },
            { text: "The grass withers, the flower fades, but the word of our God will stand forever.", ref: "Isaiah 40:8" },
            { text: "Do not be conformed to this world, but be transformed by the renewal of your mind, that by testing you may discern what is the will of God.", ref: "Romans 12:2" },
            { text: "Now faith is the assurance of things hoped for, the conviction of things not seen.", ref: "Hebrews 11:1" },
            { text: "Since you have been born again, not of perishable seed but of imperishable, through the living and abiding word of God.", ref: "1 Peter 1:23" },
            { text: "Everyone then who hears these words of mine and does them will be like a wise man who built his house on the rock.", ref: "Matthew 7:24" },
            { text: "For the word of the Lord is upright, and all his work is done in faithfulness.", ref: "Psalm 33:4" },
            { text: "If you abide in me, and my words abide in you, ask whatever you wish, and it will be done for you.", ref: "John 15:7" },
            { text: "And take the helmet of salvation, and the sword of the Spirit, which is the word of God.", ref: "Ephesians 6:17" },
            { text: "The steadfast love of the Lord never ceases; his mercies never come to an end; they are new every morning.", ref: "Lamentations 3:22-23" },
            { text: "Commit your work to the Lord, and your plans will be established.", ref: "Proverbs 16:3" },
            { text: "Finally, brothers, whatever is true, whatever is honorable, whatever is just, whatever is pure... think about these things.", ref: "Philippians 4:8" }
        ];
        
        let scriptureIdx = 0;
        
        function rotateScriptures() {
            const rot = $('#scriptureRotator');
            const textEl = $('#scriptureText');
            
            rot.css('opacity', '0');
            
            setTimeout(() => {
                scriptureIdx = (scriptureIdx + 1) % scriptures.length;
                const current = scriptures[scriptureIdx];
                
                // Dynamic Font Sizing based on text length
                if (current.text.length > 130) {
                    textEl.attr('class', 'font-heading text-white font-bold leading-tight transition-all duration-500 text-2xl md:text-3xl lg:text-4xl');
                } else if (current.text.length > 70) {
                    textEl.attr('class', 'font-heading text-white font-bold leading-tight transition-all duration-500 text-3xl md:text-4xl lg:text-5xl');
                } else {
                    textEl.attr('class', 'font-heading text-white font-bold leading-tight transition-all duration-500 text-3xl md:text-5xl lg:text-6xl');
                }

                textEl.text(`"${current.text}"`);
                $('#scriptureRef').text(`- ${current.ref}`);
                
                rot.css('opacity', '1');
            }, 1000); 
        }
        
        function checkLiveRadio() {
            $.getJSON(API_URL, { action: 'check_live_broadcast' }, function(res) {
                if(res.status === 'success' && res.is_live === 1 && res.stream_url) {
                    $('#liveBroadcastBar').removeClass('translate-y-full'); // Slide up
                } else {
                    $('#liveBroadcastBar').addClass('translate-y-full'); // Slide down/hide
                }
            });
        }
        
        function launchLivePlayer() {
            // Opens the compact popup window to protect playback during navigation
            const width = 380;
            const height = 600;
            const left = (screen.width - width) / 2;
            const top = (screen.height - height) / 2;
            window.open('/live_radio.php', 'HODLiveRadio', `width=${width},height=${height},top=${top},left=${left},scrollbars=no,resizable=no,status=no,toolbar=no,menubar=no`);
        }
        
        // Rotates every 5 seconds to give users time to read the longer passages
        setInterval(rotateScriptures, 7000);
        // --- End Rotating Scriptures Logic ---

        $(document).ready(function() { 
    loadSermons(); 

    // Read the URL directly to see if a sermon was shared
    const urlParams = new URLSearchParams(window.location.search);
    const urlSlug = urlParams.get('sermon');

    // If a slug exists, wait half a second for the page to render, then open it
    if (urlSlug) {
        setTimeout(() => {
            openSermon(urlSlug);
        }, 500);
    }
});
        checkLiveRadio(); // Check immediately on page load
        setInterval(checkLiveRadio, 30000); // Silently ping the API every 30 seconds to catch when the broadcast goes live
    </script>
</body>
</html>