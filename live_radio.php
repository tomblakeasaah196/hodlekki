<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Live Service | Household of David</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1e3a8a', hodRed: '#ef4444' },
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Montserrat', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        body { background-color: #070c24; color: white; overflow: hidden; }
        .glass-card {
            background: linear-gradient(145deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.01) 100%);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .pulse-ring { animation: pulse 2s infinite; }
        @keyframes pulse { 
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        /* Audio Visualizer Animation */
        .bar { width: 4px; background: #ef4444; border-radius: 4px; display: inline-block; margin: 0 2px; animation: eq 1.5s ease-in-out infinite alternate; transform-origin: bottom; }
        .bar:nth-child(1) { animation-delay: -0.2s; height: 10px; }
        .bar:nth-child(2) { animation-delay: -0.4s; height: 20px; }
        .bar:nth-child(3) { animation-delay: -0.1s; height: 15px; }
        .bar:nth-child(4) { animation-delay: -0.5s; height: 25px; }
        .bar:nth-child(5) { animation-delay: -0.3s; height: 12px; }
        .paused .bar { animation-play-state: paused; height: 4px !important; transition: height 0.3s; }
    </style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen p-6 relative">
    
    <!-- Background Blur Effect -->
    <div class="absolute inset-0 z-0">
        <div class="absolute top-0 left-0 w-64 h-64 bg-hodBlue rounded-full mix-blend-screen filter blur-[80px] opacity-30 animate-pulse"></div>
        <div class="absolute bottom-0 right-0 w-64 h-64 bg-hodRed rounded-full mix-blend-screen filter blur-[80px] opacity-20"></div>
    </div>

    <!-- Main Player Card -->
    <div class="glass-card w-full max-w-sm rounded-3xl p-8 relative z-10 flex flex-col items-center text-center shadow-2xl">
        
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-6">Household of David Lekki</p>
        
        <!-- Live Indicator Image -->
        <div class="relative w-32 h-32 mb-8">
            <div class="absolute inset-0 rounded-full border-2 border-hodRed/30 pulse-ring" id="liveRing"></div>
            <img src="/assets/images/hod_logo.svg" onerror="this.src='https://placehold.co/150x150/1e3a8a/FFF?text=HOD'" class="w-full h-full object-cover rounded-full bg-hodBlue p-4 border border-white/10 shadow-lg">
            <div class="absolute bottom-0 right-2 bg-hodRed text-white text-[9px] font-black uppercase px-2 py-0.5 rounded border border-black" id="statusBadge">LIVE</div>
        </div>

        <h1 class="text-2xl font-heading font-bold text-white mb-2">Sunday Service</h1>
        <p class="text-sm text-gray-400 mb-8">Audio Broadcast</p>

        <!-- Hidden Audio Element -->
        <audio id="radioPlayer" preload="none"></audio>

        <!-- Custom Controls -->
        <div class="w-full flex flex-col items-center">
            <!-- Visualizer -->
            <div class="h-8 flex items-end justify-center mb-6 paused" id="visualizer">
                <div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div>
            </div>

            <button id="playPauseBtn" class="w-16 h-16 bg-white text-hodBlue rounded-full flex items-center justify-center shadow-[0_0_20px_rgba(255,255,255,0.3)] hover:scale-105 transition-transform">
                <!-- Play Icon (Default) -->
                <svg id="iconPlay" class="w-8 h-8 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                <!-- Pause Icon -->
                <svg id="iconPause" class="w-8 h-8 hidden" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            </button>
        </div>
        
        <p class="text-[10px] text-gray-500 mt-8">You can minimize this window to keep listening.</p>
    </div>

    <script>
        $(document).ready(function() {
            const API_URL = '/api/public_sermons_api.php';
            const player = document.getElementById('radioPlayer');
            const btn = $('#playPauseBtn');
            const visualizer = $('#visualizer');
            const liveRing = $('#liveRing');
            const statusBadge = $('#statusBadge');

            let streamUrl = '';

            // Fetch URL
            $.getJSON(API_URL, { action: 'check_live_broadcast' }, function(res) {
                if(res.status === 'success' && res.stream_url) {
                    streamUrl = res.stream_url;
                    player.src = streamUrl;
                    if(res.is_live === 0) {
                        statusBadge.text('OFFLINE').removeClass('bg-hodRed').addClass('bg-gray-600');
                        liveRing.removeClass('pulse-ring border-hodRed/30').addClass('border-gray-600/30');
                        btn.prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
                    }
                }
            });

            btn.on('click', function() {
                if (!streamUrl) return;
                
                if (player.paused) {
                    // Force refresh cache so it catches the live edge, not buffered audio
                    player.src = streamUrl + '?t=' + new Date().getTime(); 
                    player.play();
                    $('#iconPlay').addClass('hidden');
                    $('#iconPause').removeClass('hidden');
                    visualizer.removeClass('paused');
                } else {
                    player.pause();
                    $('#iconPlay').removeClass('hidden');
                    $('#iconPause').addClass('hidden');
                    visualizer.addClass('paused');
                }
            });

            // Handle buffering/stalls automatically
            player.addEventListener('waiting', () => visualizer.addClass('paused'));
            player.addEventListener('playing', () => visualizer.removeClass('paused'));
        });
    </script>
</body>
</html>