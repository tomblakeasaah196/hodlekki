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
    <!-- Open Graph Tags for WhatsApp/Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $og_title ?>">
    <meta property="og:description" content="<?= $og_desc ?>">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:url" content="<?= $og_url ?>">
    
    <!-- Safely inject the auto-open script here -->
    <?= $autoOpenScript ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sermons | Household of David Lekki Centre</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="/assets/css/style.css">
    
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
        
        <div class="relative w-full max-w-6xl h-[95vh] sm:h-[85vh] bg-hodBlue/95 border border-white/10 sm:rounded-3xl shadow-2xl flex flex-col transform translate-y-full sm:scale-95 transition-transform duration-500 overflow-hidden">
            
            <div class="absolute top-4 right-4 z-50">
                <button onclick="closeSermon()" class="bg-black/50 text-white hover:bg-hodRed hover:text-white w-10 h-10 rounded-full flex items-center justify-center transition-all backdrop-blur-md border border-white/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="flex-1 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden custom-scrollbar">
                
                <div class="w-full lg:w-2/3 border-b lg:border-b-0 lg:border-r border-white/10 flex flex-col lg:overflow-y-auto custom-scrollbar">
                    
                    <div id="modalVideoContainer" class="w-full aspect-video bg-black hidden shrink-0 relative">
                        <div id="youtubePlayer" class="absolute inset-0 w-full h-full"></div>
                    </div>
                    
                    <div id="modalImageContainer" class="w-full h-64 sm:h-80 bg-gray-900 shrink-0 relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-t from-hodBlue to-transparent z-10"></div>
                        <img id="modalCover" src="" class="w-full h-full object-cover opacity-60 mix-blend-overlay">
                    </div>

                    <div id="modalDetails" class="p-6 md:p-8 -mt-20 relative z-20 shrink-0 transition-all duration-300">
                        <span id="modalType" class="text-white text-[10px] font-bold uppercase tracking-widest bg-hodRed px-3 py-1 rounded-full shadow-lg">Service Type</span>
                        <h2 id="modalTitle" class="text-3xl md:text-4xl font-heading text-white font-bold mt-4 leading-tight">Sermon Title</h2>
                        <p class="text-gray-300 mt-2 font-light tracking-wide text-sm md:text-base">Ministering: <span id="modalPreacher" class="font-bold text-white">Preacher</span> <span class="mx-2 opacity-50">•</span> <span id="modalDate">Date</span></p>
                        
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
                        <div class="bg-gray-900/99 backdrop-blur-3xl rounded-2xl p-6 shadow-inner border border-gray-700/90">
                            <div class="prose prose-invert max-w-none text-gray-100 font-light text-sm md:text-base leading-relaxed" id="modalNotes"></div>
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

    <script>
        const API_URL = '/api/public_sermons_api.php';
        let ytPlayer;
        let playTracked = false; 

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
                        const cover = s.cover_image_path || 'https://images.unsplash.com/photo-1438232992991-995b7058bbb3?w=800&q=80';
                        html += `
                        <div onclick="openSermon('${s.slug}')" class="glass-card rounded-3xl overflow-hidden cursor-pointer group flex flex-col h-[400px]">
                            <div class="h-48 relative overflow-hidden shrink-0">
                                <div class="absolute inset-0 bg-black/40 group-hover:bg-transparent transition-colors duration-500 z-10"></div>
                                <img src="${cover}" class="w-full h-full object-cover transform group-hover:scale-110 transition-transform duration-700">
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
            
            $('#modalType').text(s.service_type);
            $('#modalTitle').text(s.title);
            $('#modalPreacher').text(s.preacher);
            $('#modalDate').text(s.nice_date);
            
            const notes = s.full_notes ? s.full_notes.replace(/\n/g, '<br>') : '<p class="italic opacity-50">Detailed notes are not available.</p>';
            $('#modalNotes').html(notes);

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
            btn.prop('disabled', true).text('Submitting...');
            
            $.post(API_URL, $(this).serialize(), function(res) {
                btn.prop('disabled', false).text(origText);
                if(res.status === 'success') {
                    $('#commentForm')[0].reset();
                    Toastify({ text: res.message, style: { background: "#1D356A", color: "white" } }).showToast();
                    
                    $.getJSON(API_URL, { action: 'get_sermon', sermon_id: $('#commentSermonId').val() }, function(fresh) {
                        if(fresh.status === 'success') renderComments(fresh.comments);
                    });
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