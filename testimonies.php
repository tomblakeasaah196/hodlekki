<?php
// /testimonies.php
session_start();
$is_logged_in = isset($_SESSION['user_id']);
$user_name = $is_logged_in ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : '';
$user_email = $is_logged_in ? $_SESSION['email'] : '';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testimonies | Household of David Lekki Centre</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;800;900&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Montserrat', 'sans-serif'] },
                    colors: { hodBlue: '#0A0E17', hodRed: '#D11920', hodGold: '#EAB308' },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-10px)' } }
                    }
                }
            }
        }
    </script>
    
    <style>
        body { color: #1F2937; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D11920; }
        
        .masonry-grid { column-count: 1; column-gap: 2rem; }
        @media (min-width: 768px) { .masonry-grid { column-count: 2; } }
        @media (min-width: 1024px) { .masonry-grid { column-count: 3; } }
        .masonry-item { break-inside: avoid; margin-bottom: 2rem; }
        
        /* Warm Light Glass-morphism */
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.03);
        }

        .recording-pulse { box-shadow: 0 0 0 0 rgba(209, 25, 32, 0.4); animation: pulse-red 1.5s infinite; }
        @keyframes pulse-red {
            0% { transform: scale(0.98); box-shadow: 0 0 0 0 rgba(209, 25, 32, 0.4); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(209, 25, 32, 0); }
            100% { transform: scale(0.98); box-shadow: 0 0 0 0 rgba(209, 25, 32, 0); }
        }
    </style>
</head>
<body class="bg-gray-50 antialiased min-h-screen flex flex-col selection:bg-hodRed selection:text-white">

    <div class="fixed inset-0 z-[-1] overflow-hidden bg-white">
        
        <img src="https://images.unsplash.com/photo-1510590337019-5ef8d3d32116?q=80&w=2000&auto=format&fit=crop" 
             alt="Hands raised in praise and worship" 
             class="w-full h-full object-cover opacity-60">
        
        <div class="absolute inset-0 bg-gradient-to-b from-white/70 via-white/40 to-white/90"></div>
        
        <div class="absolute top-0 right-1/4 w-[600px] h-[600px] bg-hodRed/10 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 left-1/4 w-[500px] h-[500px] bg-hodBlue/10 rounded-full blur-[150px]"></div>
    </div>

    <header class="fixed top-0 w-full z-40 glass-panel border-b border-white/40 shadow-sm">
        <div class="max-w-7xl mx-auto px-6 h-24 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <img src="/assets/images/hod_logo.svg" alt="HOD Logo" class="h-12 drop-shadow-sm" onerror="this.src='https://placehold.co/150x50/D11920/FFF?text=HOD'">
                <div class="hidden sm:block border-l border-gray-300 pl-4">
                    <h1 class="font-heading text-hodBlue text-lg tracking-widest uppercase font-black">Household of David</h1>
                    <p class="text-hodRed text-[10px] tracking-[0.2em] uppercase font-bold">Lekki Centre</p>
                </div>
            </div>
            <a href="https://householdofdavid.org" target="_blank" class="text-xs font-bold text-gray-600 hover:text-hodRed transition-colors tracking-widest uppercase border border-gray-200 bg-white/50 px-6 py-2.5 rounded-full hover:bg-white hover:border-hodRed/30 hover:shadow-md">Visit Main Site</a>
        </div>
    </header>

    <main class="flex-1 max-w-7xl mx-auto w-full px-6 pt-40 pb-20 relative z-10">
        <div class="text-center animate-fade-in-up mb-20">
            <span class="inline-block px-4 py-1.5 mb-6 text-[10px] font-black tracking-[0.2em] uppercase text-hodRed bg-hodGold/10 border border-hodGold/20 rounded-full shadow-sm">Testimonies of the Goodness of God</span>
            <h1 class="text-5xl md:text-7xl font-heading font-black tracking-tight text-gray-900 mb-6 drop-shadow-sm">The Wall of Praise</h1>
            <p class="text-gray-600 max-w-2xl mx-auto text-base md:text-lg font-medium leading-relaxed">
                "They triumphed over him by the blood of the Lamb and by the word of their testimony."
                <span class="text-xs font-bold tracking-widest text-hodRed mt-3 block uppercase">— Revelation 12:11</span>
            </p>
            
            <div class="mt-10 flex justify-center">
                <button onclick="openModal('submitModal')" class="bg-hodRed hover:bg-hodBlue text-white font-black uppercase tracking-widest text-sm px-10 py-4 rounded-full transition-all duration-300 shadow-xl shadow-hodRed/20 hover:shadow-hodBlue/40 hover:-translate-y-1 flex items-center gap-3">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
    Share Your Miracle
</button>
            </div>
        </div>

        <div id="loadingState" class="flex flex-col items-center justify-center py-20 opacity-70">
            <svg class="animate-spin h-12 w-12 text-hodRed mb-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-xs font-bold tracking-[0.2em] uppercase text-gray-500">Loading Blessings...</p>
        </div>
        
        <div id="testimonyWall" class="masonry-grid hidden transition-all duration-700"></div>
    </main>

    <div id="submitModal" class="fixed inset-0 z-50 hidden flex flex-col justify-end sm:justify-center items-center p-0 sm:p-4 opacity-0 transition-opacity duration-500">
        <div class="absolute inset-0 bg-hodBlue/60 backdrop-blur-sm" onclick="closeModal('submitModal')"></div>
        
        <div class="relative w-full max-w-2xl bg-white border border-white shadow-2xl flex flex-col sm:rounded-3xl transform translate-y-full sm:scale-95 transition-transform duration-500 max-h-[95vh] sm:max-h-[90vh]">
            
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/80 sm:rounded-t-3xl shrink-0">
                <div>
                    <h3 class="font-heading font-black text-gray-900 text-xl tracking-tight">Testify of God's Goodness</h3>
                </div>
                <button onclick="closeModal('submitModal')" class="text-gray-400 hover:text-hodRed transition-colors bg-white shadow-sm w-8 h-8 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <form id="testimonyForm" class="flex flex-col flex-1 overflow-hidden">
                <input type="hidden" name="action" value="submit_testimony">
                <input type="hidden" name="time_opened" id="timeOpened">
                <input type="text" name="honeypot_contact" class="hidden" tabindex="-1" autocomplete="off">
                
                <div class="p-6 md:p-8 space-y-6 overflow-y-auto custom-scrollbar flex-1 bg-white">
                    
                    <div class="bg-amber-50/50 p-5 rounded-2xl border border-amber-100/50 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Your Name</label>
                                <input type="text" name="guest_name" value="<?= htmlspecialchars($user_name) ?>" placeholder="e.g. John Doe" class="w-full bg-white border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-hodRed focus:ring-2 focus:ring-hodGold/20 rounded-xl outline-none transition-all font-medium shadow-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Email (Optional)</label>
                                <input type="email" name="guest_email" value="<?= htmlspecialchars($user_email) ?>" placeholder="For pastoral follow-up" class="w-full bg-white border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-hodRed focus:ring-2 focus:ring-hodGold/20 rounded-xl outline-none transition-all font-medium shadow-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Privacy Level *</label>
                            <select name="privacy_level" class="w-full bg-white border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-hodRed focus:ring-2 focus:ring-hodGold/20 rounded-xl outline-none transition-all cursor-pointer font-medium shadow-sm">
                                <option value="Public">Public (Show my name to the world)</option>
                                <option value="Anonymous_To_All">Fully Anonymous (Hide my name completely)</option>
                                <option value="Pastoral_Only">Pastoral Only (Hide name from public)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Voice Note (Optional)</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="button" id="startRecordBtn" class="flex-1 bg-white border border-gray-200 text-gray-700 hover:border-hodRed hover:text-hodRed shadow-sm px-4 py-3.5 text-xs font-bold uppercase tracking-widest rounded-xl transition-all flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg> Record Audio
                            </button>
                            
                            <button type="button" id="stopRecordBtn" class="hidden flex-1 bg-hodRed text-white px-4 py-3.5 text-xs font-bold uppercase tracking-widest rounded-xl recording-pulse flex items-center justify-center gap-2">
                                <span class="w-3 h-3 bg-white rounded-sm"></span> Stop (<span id="recordTimer">00:00</span>)
                            </button>
                            
                            <div class="flex-1 relative group">
                                <input type="file" name="voice_note" id="audioUpload" accept="audio/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full h-full bg-gray-50 border border-gray-200 border-dashed group-hover:bg-gray-100 group-hover:border-hodGold rounded-xl transition-colors flex items-center justify-center text-xs font-bold uppercase tracking-widest text-gray-500">
                                    Upload File
                                </div>
                            </div>
                        </div>
                        
                        <div id="audioPreviewContainer" class="hidden mt-3 p-3 bg-gray-50 border border-gray-200 rounded-xl flex items-center gap-3 shadow-inner">
                            <audio id="audioPlayback" controls class="flex-1 h-8"></audio>
                            <button type="button" onclick="clearAudio()" class="text-xs text-red-500 font-bold hover:underline px-2">Clear</button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Written Testimony</label>
                        <textarea name="content_text" id="writtenText" rows="5" placeholder="What has the Lord done for you?" class="w-full bg-white border border-gray-200 px-5 py-4 text-sm text-gray-900 focus:border-hodRed focus:ring-2 focus:ring-hodGold/20 rounded-xl outline-none transition-all resize-none font-medium leading-relaxed shadow-sm"></textarea>
                    </div>

                    <label class="flex items-start gap-3 p-4 bg-blue-50/50 border border-blue-100 rounded-xl cursor-pointer hover:bg-blue-50 transition-colors">
                        <input type="checkbox" name="request_editing" value="1" class="mt-0.5 w-4 h-4 text-hodBlue border-gray-300 rounded focus:ring-hodBlue">
                        <div>
                            <span class="font-bold text-hodBlue text-sm block">Please review and format my testimony</span>
                            <span class="text-xs text-gray-500 mt-0.5 block font-medium">Our team will correct grammar and beautifully format your story before publishing.</span>
                        </div>
                    </label>
                </div>
                
                <div class="p-6 border-t border-gray-100 bg-gray-50 sm:rounded-b-3xl shrink-0">
                    <button type="submit" id="submitBtn" class="w-full bg-hodBlue text-white hover:bg-hodRed py-4 rounded-xl font-bold uppercase tracking-[0.1em] text-sm transition-all duration-300 shadow-lg hover:shadow-hodRed/30 flex justify-center items-center gap-2">
                        Submit to the Altar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_URL = '/api/testimony_public_api.php';
        let recordedBlob = null;
        let mediaRecorder;
        let audioChunks = [];
        let recordInterval;
        let seconds = 0;

        function openModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('hidden'); 
            $('body').css('overflow', 'hidden');
            $('#timeOpened').val(Date.now());
            requestAnimationFrame(() => { 
                modal.classList.remove('opacity-0'); 
                modal.children[1].classList.remove('translate-y-full', 'sm:scale-95'); 
            });
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.add('opacity-0'); 
            modal.children[1].classList.add('translate-y-full', 'sm:scale-95');
            setTimeout(() => { 
                modal.classList.add('hidden'); 
                $('body').css('overflow', '');
                if(id === 'submitModal') clearAudio(); 
            }, 500);
        }

        function showNotification(msg, type = 'success') {
            Toastify({ 
                text: msg, 
                gravity: "top", 
                position: "center", 
                duration: 4000, 
                style: { 
                    background: type === 'success' ? "#10B981" : "#D11920", 
                    borderRadius: "12px",
                    fontFamily: "Inter, sans-serif",
                    fontSize: "13px",
                    fontWeight: "600",
                    boxShadow: "0 10px 25px rgba(0,0,0,0.15)"
                } 
            }).showToast();
        }

        $('#startRecordBtn').click(async function() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.start();
                audioChunks = [];

                $(this).addClass('hidden'); 
                $('#stopRecordBtn').removeClass('hidden');
                
                seconds = 0;
                recordInterval = setInterval(() => {
                    seconds++;
                    const m = String(Math.floor(seconds / 60)).padStart(2, '0');
                    const s = String(seconds % 60).padStart(2, '0');
                    $('#recordTimer').text(`${m}:${s}`);
                }, 1000);

                mediaRecorder.addEventListener("dataavailable", event => { audioChunks.push(event.data); });
                mediaRecorder.addEventListener("stop", () => {
                    recordedBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const audioUrl = URL.createObjectURL(recordedBlob);
                    $('#audioPlayback').attr('src', audioUrl);
                    $('#audioPreviewContainer').removeClass('hidden').addClass('flex');
                    $('#startRecordBtn').removeClass('hidden'); 
                    $('#stopRecordBtn').addClass('hidden');
                    stream.getTracks().forEach(track => track.stop());
                });
            } catch (err) {
                showNotification("Microphone access denied or unavailable.", "error");
            }
        });

        $('#stopRecordBtn').click(function() {
            if(mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
                clearInterval(recordInterval);
                $('#recordTimer').text('00:00');
            }
        });

        $('#audioUpload').change(function(e) {
            if(this.files[0]) {
                recordedBlob = null; 
                $('#audioPlayback').attr('src', URL.createObjectURL(this.files[0]));
                $('#audioPreviewContainer').removeClass('hidden').addClass('flex');
            }
        });

        function clearAudio() {
            recordedBlob = null; 
            audioChunks = [];
            $('#audioUpload').val('');
            $('#audioPlayback').attr('src', '');
            $('#audioPreviewContainer').removeClass('flex').addClass('hidden');
        }

        function loadWall() {
            $.getJSON(API_URL, { action: 'fetch_wall' })
            .done(function(res) {
                $('#loadingState').addClass('hidden');
                const wall = $('#testimonyWall');
                
                if (res.status === 'success' && res.testimonies.length > 0) {
                    let html = '';
                    res.testimonies.forEach(t => {
                        
                        let audioHtml = t.voice_note_url ? `
                            <div class="mt-5 p-3 bg-amber-50/50 rounded-xl border border-amber-100">
                                <p class="text-[10px] font-black text-hodRed uppercase tracking-widest mb-2 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path></svg> Listen Audio
                                </p>
                                <audio controls src="${t.voice_note_url}" class="w-full h-8"></audio>
                            </div>` : '';
                        
                        html += `
                        <div class="masonry-item glass-panel p-6 sm:p-8 rounded-3xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
                            
                            <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-hodGold/10 to-transparent rounded-bl-full pointer-events-none group-hover:scale-110 transition-transform duration-500"></div>
                            
                            <div class="flex items-center gap-3 mb-5 relative z-10">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-hodBlue to-gray-800 text-hodRed flex items-center justify-center font-heading font-black shadow-md border-2 border-white">${t.avatar_initials}</div>
                                <div>
                                    <h4 class="font-bold text-gray-900 text-base leading-tight">${t.display_name}</h4>
                                    <p class="text-[10px] font-bold tracking-widest uppercase text-gray-400 mt-0.5">${t.nice_date}</p>
                                </div>
                            </div>
                            
                            <div class="text-gray-700 font-medium text-[15px] leading-relaxed relative z-10 space-y-3">
                                ${t.content_text ? t.content_text.replace(/\n/g, '<br>') : '<span class="italic text-gray-400">Audio testimony provided.</span>'}
                            </div>
                            
                            <div class="relative z-10">${audioHtml}</div>

                            <div class="mt-6 pt-5 border-t border-gray-100 flex items-center justify-between relative z-10">
                                <button onclick="hallelujah(${t.id})" class="flex items-center gap-2 text-[11px] font-bold text-gray-500 hover:text-hodRed uppercase tracking-widest transition-colors group/btn">
                                    <div class="w-8 h-8 rounded-full bg-gray-50 border border-gray-100 group-hover/btn:bg-red-50 flex items-center justify-center transition-all shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path></svg>
                                    </div>
                                    <span id="hal-count-${t.id}">${t.hallelujah_count}</span> Hallelujah
                                </button>
                                
                                <button onclick="toggleComments(${t.id})" class="text-[11px] font-bold text-gray-500 hover:text-hodBlue uppercase tracking-widest transition-colors bg-gray-50 px-3 py-1.5 rounded-full border border-gray-100">
                                    ${t.comment_count} Comments
                                </button>
                            </div>

                            <div id="comments-sec-${t.id}" class="hidden mt-5 bg-white/60 rounded-2xl p-4 border border-white relative z-10 shadow-sm">
                                <div id="comments-list-${t.id}" class="space-y-3 mb-4 max-h-48 overflow-y-auto custom-scrollbar pr-2"></div>
                                <div class="flex gap-2">
                                    <input type="text" id="comment-input-${t.id}" placeholder="Share an encouraging word..." class="flex-1 bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-hodRed focus:ring-1 focus:ring-hodGold shadow-sm font-medium">
                                    <button onclick="postComment(${t.id})" class="bg-hodBlue text-white hover:bg-hodRed px-5 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition-colors shadow-md">Post</button>
                                </div>
                            </div>
                        </div>`;
                    });
                    wall.html(html).removeClass('hidden');
                } else {
                    wall.html('<div class="col-span-full py-20 text-center"><p class="text-gray-500 font-bold uppercase tracking-widest bg-white/50 inline-block px-6 py-3 rounded-full border border-gray-200">Be the first to share a testimony of God\'s goodness!</p></div>').removeClass('hidden');
                }
            })
            .fail(function(jqXHR) {
                let errorMsg = "Database Connection Error";
                try { let err = JSON.parse(jqXHR.responseText); if(err.message) errorMsg = err.message; } catch(e) {}
                $('#loadingState').html(`<div class="bg-red-50 border border-red-200 text-red-700 p-6 rounded-2xl max-w-lg mx-auto text-center font-bold shadow-sm">${errorMsg}</div>`);
            });
        }

        function hallelujah(id) {
            $.post(API_URL, { action: 'click_hallelujah', testimony_id: id }, function(res) {
                if(res.status === 'success') {
                    const countEl = $(`#hal-count-${id}`);
                    countEl.text(res.new_count).addClass('text-hodRed scale-150 inline-block transition-transform duration-300');
                    setTimeout(() => countEl.removeClass('scale-150 text-hodRed'), 400);
                } else if(res.status === 'info') { showNotification(res.message, 'info'); }
            }, 'json');
        }

        function toggleComments(id) {
            const sec = $(`#comments-sec-${id}`);
            if(sec.hasClass('hidden')) {
                sec.removeClass('hidden');
                $(`#comments-list-${id}`).html('<p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest animate-pulse text-center py-2">Loading...</p>');
                
                $.getJSON(API_URL, { action: 'fetch_comments', testimony_id: id }, function(res) {
                    let cHtml = '';
                    if(res.comments.length === 0) cHtml = '<p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest text-center py-2">No comments yet.</p>';
                    res.comments.forEach(c => {
                        cHtml += `
                        <div class="bg-white p-3 rounded-xl border border-gray-100 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 mb-1 flex justify-between uppercase tracking-wider">
                                <span class="text-hodBlue">${c.display_name}</span><span>${c.nice_date}</span>
                            </p>
                            <p class="text-sm text-gray-700 font-medium leading-relaxed">${c.comment_text}</p>
                        </div>`;
                    });
                    $(`#comments-list-${id}`).html(cHtml);
                });
            } else { sec.addClass('hidden'); }
        }

        function postComment(id) {
            const txt = $(`#comment-input-${id}`).val();
            if(!txt.trim()) return;
            const btn = $(`#comments-sec-${id} button`); const orig = btn.text();
            btn.prop('disabled', true).text('...');

            $.post(API_URL, { action: 'post_comment', testimony_id: id, comment_text: txt, guest_name: $('input[name="guest_name"]').val() || 'Guest' }, function(res) {
                btn.prop('disabled', false).text(orig);
                if(res.status === 'success') { 
                    $(`#comment-input-${id}`).val(''); toggleComments(id); toggleComments(id); 
                    showNotification(res.message, 'success');
                } else { showNotification(res.message, 'error'); }
            }, 'json');
        }

        $('#testimonyForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#submitBtn'); const orig = btn.html();
            const txt = $('#writtenText').val().trim();
            const hasAudio = recordedBlob !== null || $('#audioUpload')[0].files.length > 0;
            
            if(!txt && !hasAudio) { showNotification("Please provide either written text or a voice note.", "error"); return; }
            
            btn.prop('disabled', true).html('<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Sending...');
            
            let formData = new FormData(this);
            if(recordedBlob) { formData.append('voice_note', recordedBlob, 'voicenote.webm'); }

            $.ajax({
                url: API_URL, type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html(orig);
                    if(res.status === 'success') { 
                        closeModal('submitModal'); showNotification(res.message, 'success'); setTimeout(loadWall, 800); 
                    } else { showNotification(res.message, 'error'); }
                },
                error: function() { btn.prop('disabled', false).html(orig); showNotification("Network Error.", "error"); }
            });
        });

        $(document).ready(() => { setTimeout(loadWall, 500); });
    </script>
</body>
</html>