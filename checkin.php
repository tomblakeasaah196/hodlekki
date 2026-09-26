<?php /** * ============================================================================ * ATTENDANCE CHECK-IN — Public page * File: /checkin.php * ---------------------------------------------------------------------------- * Warm, beautiful check-in flow. Open via QR: * /checkin.php?event=TOKEN * Flow: enter phone → confirm name (edit if misspelled) → mark present → blessing. * Walk-ins can register on the spot (auto-marked present). * ============================================================================ */ session_start(); if (empty($_SESSION['checkin_csrf'])) $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32)); $CSRF = $_SESSION['checkin_csrf']; $token = trim($_GET['event'] ?? ''); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome — Check In</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;600;700;800&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        :root {
            --hod-blue: #1D356A;
            /* church brand blue */
            --hod-blue-2: #3b82f6;
            --hod-red: #D11920;
            /* church brand red */
            --hod-red-2: #dc2626;
            --hod-navy: #0b1020;
            /* register page dark navy */
            --hod-navy-2: #070c24;
            --hod-gold: #d4af37;
        }

        body {
            margin: 0;
            background: var(--hod-navy);
            font-family: 'Inter', sans-serif;
        }

        .font-head {
            font-family: 'Montserrat', sans-serif;
        }

        /* church headings */
        .font-serif-it {
            font-family: 'Cormorant Garamond', serif;
        }
        .font-signature {
            font-family: 'Great Vibes', cursive;
            color: var(--hod-blue-2);
        }

        /* Glassmorphic shell (matches the register page) */
        .shell {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            filter: blur(6px) brightness(.45) saturate(1.1);
            transform: scale(1.08);
        }

        .overlay {
            position: absolute;
            inset: 0;
            background: radial-gradient(50% 45% at 20% 15%, rgba(30, 58, 138, .45), transparent 60%), radial-gradient(45% 45% at 85% 90%, rgba(209, 25, 32, .30), transparent 60%), linear-gradient(160deg, rgba(11, 16, 32, .88), rgba(11, 16, 32, .55) 45%, rgba(7, 12, 36, .92));
        }

        .glass {
            background: linear-gradient(145deg, rgba(255, 255, 255, .14), rgba(255, 255, 255, .04));
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            border: 1px solid rgba(255, 255, 255, .18);
            box-shadow: 0 40px 100px rgba(0, 0, 0, .55), inset 0 1px 0 rgba(255, 255, 255, .25);
            border-radius: 28px;
        }

        .hod-blue-text {
            color: var(--hod-blue-2);
        }

        .hod-gold-text {
            color: var(--hod-gold);
        }

        .glass-input {
            width: 100%;
            background: rgba(255, 255, 255, .07);
            border: 1.5px solid rgba(255, 255, 255, .18);
            border-radius: 14px;
            padding: .95rem 1.1rem;
            color: #fff;
            outline: none;
            font-size: 1.05rem;
            font-family: 'Inter', sans-serif;
        }

        .glass-input:focus {
            border-color: var(--hod-blue-2);
            background: rgba(255, 255, 255, .1);
        }

        .glass-input::placeholder {
            color: rgba(255, 255, 255, .45);
        }

        /* Primary CTA = church red gradient (matches register's red button) */
        .btn-primary {
            background: linear-gradient(120deg, var(--hod-red), var(--hod-red-2) 60%, #b91c1c);
            color: #fff;
            font-weight: 800;
            border-radius: 16px;
            padding: .95rem 1.5rem;
            box-shadow: 0 18px 40px -12px rgba(209, 25, 32, .6);
            font-family: 'Montserrat', sans-serif;
        }

        .btn-ghost {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .22);
            color: #fff;
            font-weight: 600;
            border-radius: 14px;
            padding: .85rem 1.25rem;
        }

        .hidden {
            display: none !important;
        }

        .animate-fade {
            animation: fadeIn .5s ease both;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(14px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 0 0 rgba(52, 211, 153, .6);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(52, 211, 153, .6);
            }

            70% {
                box-shadow: 0 0 0 12px rgba(52, 211, 153, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(52, 211, 153, 0);
            }
        }

        .hod-logo {
            height: 56px;
            width: auto;
            margin: 0 auto 6px;
        }
    </style>
</head>

<body>
    <div class="shell" id="shell">
        <div class="bg" id="bgImg"></div>
        <div class="overlay"></div>
        <div class="glass w-full max-w-md mx-4 p-7 md:p-9 relative z-10 animate-fade" id="card"> <!-- HOD Logo (church brand) -->
            <div class="text-center mb-3"> <img src="/assets/images/hod_logo.svg" alt="HOD Lekki Centre" class="hod-logo" onerror="this.style.display='none'"> </div> <!-- Event name (always at top) -->
            <div class="text-center mb-1" id="eventTop">
                <p class="text-[11px] font-bold uppercase tracking-[0.35em] text-white/50">You're invited to</p>
                <h1 class="font-head text-2xl md:text-3xl font-extrabold text-white leading-tight mt-1" id="eventTitle">Loading…</h1>
                <p class="text-white/55 text-xs mt-1 font-medium" id="eventMeta"></p>
                <div class="w-16 h-[2px] mx-auto my-4" style="background:linear-gradient(to right,transparent,var(--hod-blue-2),transparent)"></div>
            </div> <!-- STEP 1: Enter phone -->
            <div id="step-phone" class="animate-fade text-center">
                <div class="text-5xl mb-3">🙏</div>
                <h2 class="font-head text-2xl font-bold text-white">Welcome!</h2>
                <p class="text-white/65 text-sm mt-1 mb-6">We're so glad you're here. Enter the phone number you used to register to check in.</p>
                <div class="flex items-center gap-2 mb-1"> <input type="tel" id="phoneInput" class="glass-input text-center" placeholder="e.g. 09020868023" autocomplete="off" inputmode="numeric"> </div>
                <p class="text-[11px] text-white/40 mt-1 mb-4">We'll use this to find your registration.</p> <button class="btn-primary w-full" onclick="lookupPhone()">Find me</button>
                <div class="mt-4 text-center"> <button class="btn-ghost w-full text-sm" onclick="startVolunteer()" id="volunteerBtn">👋 IDI volunteer assistance (for someone without a phone)</button> </div>
            </div> <!-- STEP 1b: IDI volunteer assistance -->
            <div id="step-volunteer" class="hidden animate-fade text-center">
                <div class="text-5xl mb-3">🤝</div>
                <h2 class="font-head text-2xl font-bold text-white">IDI Volunteer Assistance</h2>
                <p class="text-white/65 text-sm mt-1 mb-5">Enter the person's phone number to look them up. If they're not registered, you can register them at once.</p>
                <div class="flex items-center gap-2 mb-1"> <input type="tel" id="volPhone" class="glass-input text-center" placeholder="e.g. 09020868023" autocomplete="off" inputmode="numeric"> </div>
                <p class="text-[11px] text-white/40 mt-1 mb-4">We'll use this to find their registration.</p> <button class="btn-primary w-full mb-2" onclick="volunteerLookup()">Find person</button> <button class="btn-ghost w-full text-sm" onclick="goVolRegister()">➕ Register someone new</button> <button class="btn-ghost w-full text-sm mt-2 border-none opacity-80" onclick="goPhone()">← Exit to standard check-in</button>
            </div> <!-- STEP 2: Confirm name -->
            <div id="step-confirm" class="hidden animate-fade text-center">
                <div class="text-5xl mb-3">✨</div>
                <h2 class="font-head text-2xl font-bold text-white">Welcome, <span id="confirmName" class="hod-blue-text">…</span>!</h2>
                <p class="text-white/65 text-sm mt-1 mb-6">Is this correct?</p>
                <div class="glass-input mb-3 font-semibold" id="confirmNameBox"></div> <button class="btn-ghost w-full mb-2 text-sm" onclick="editName()">✏️ Edit name</button> <button class="btn-primary w-full" onclick="markPresent()">I'm here — mark present</button> <!-- Back button allows volunteers to bail out without breaking flow --> <button class="btn-ghost w-full text-sm mt-3 border-none opacity-60 hidden" id="confirmBackBtn" onclick="startVolunteer()">← Back to search</button>
            </div> <!-- STEP 2b: Edit name -->
            <div id="step-edit" class="hidden animate-fade text-center">
                <h2 class="font-head text-2xl font-bold text-white mb-2">Let's fix that name</h2>
                <p class="text-white/65 text-sm mb-4">Enter the correct full name.</p> <input type="text" id="editNameInput" class="glass-input text-center mb-4" placeholder="Full name" autocomplete="off"> <button class="btn-primary w-full mb-2" onclick="saveEdit()">Save & mark present</button> <button class="btn-ghost w-full text-sm" onclick="goConfirm()">Cancel</button>
            </div> <!-- STEP 3: Register (walk-in) -->
            <div id="step-register" class="hidden animate-fade text-center">
                <div class="text-5xl mb-3">🎉</div>
                <h2 class="font-head text-2xl font-bold text-white">We'd love to welcome you!</h2>
                <p class="text-white/65 text-sm mt-1 mb-5">It looks like this number hasn't been registered yet. No problem — just provide the name to complete registration.</p> <input type="text" id="regName" class="glass-input text-center mb-3" placeholder="Full name" autocomplete="off"> <input type="tel" id="regPhone" class="glass-input text-center mb-4" placeholder="Phone number" autocomplete="off" inputmode="numeric"> 
                <!-- How did you hear about us -->
                <div class="mb-4">
                    <label class="block text-white/60 text-[11px] font-bold uppercase tracking-wider mb-1.5" style="text-align:left;">How did you hear about this event?</label>
                    <select id="regSource" class="glass-input text-left" style="appearance:auto;">
                        <option value="">-- Select --</option>
                        <option value="Self_Discovery">I'm a member of Household of David</option>
                        <option value="Social_Media">Social Media</option>
                        <option value="Broadcast">Broadcast</option>
                        <option value="Media">Media</option>
                        <option value="Invited_By">Someone invited me</option>
                        <option value="Flyer_Banner_Poster">Flyer / Banner / Poster</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <button class="btn-primary w-full" onclick="registerPresent()">Register & mark present</button> <!-- Back button allowing escape from register flow --> <button class="btn-ghost w-full text-sm mt-3 border-none opacity-60" onclick="retryPhoneFlow()">← Back to search</button>
            </div> <!-- STEP 4: Not found (Only shown for standard users) -->
            <div id="step-notfound" class="hidden animate-fade text-center">
                <div class="text-5xl mb-3">🔍</div>
                <h2 class="font-head text-2xl font-bold text-white">Hmm, we couldn't find that number</h2>
                <p class="text-white/65 text-sm mt-1 mb-6">The phone number isn't in our system for this event.</p> <button class="btn-ghost w-full mb-2" onclick="goPhone()">↩️ Retry another phone number</button> <button class="btn-primary w-full" onclick="goRegister()">Register now</button>
            </div> <!-- STEP 5: Success + personalized blessing -->
            <div id="step-success" class="hidden animate-fade text-center">
                <div class="pulse-dot mx-auto mb-4" style="width:18px;height:18px;"></div>
                <h2 class="font-head text-2xl font-bold text-white" id="successMsg">You're all set!</h2> <!-- The blessing keepsake card (this is what gets copied / screenshotted) -->
                <div id="blessingCard" class="mt-5 px-6 py-7 rounded-3xl text-left relative overflow-hidden" style="background:linear-gradient(160deg,rgba(255,255,255,.10),rgba(255,255,255,.03)); border:1px solid rgba(59,130,246,.4); box-shadow:0 30px 80px rgba(0,0,0,.4);">
                    <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full opacity-30" style="background:radial-gradient(circle,#3b82f6,transparent 70%);"></div>
                    <div class="relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-[0.35em] mb-1 hod-blue-text">A word just for you</p>
                        <p class="font-head text-white text-2xl font-bold mb-3" id="blessingDear"></p>
                        <div class="mb-4 pl-4 border-l-2" style="border-color:#3b82f6;">
                            <p class="font-serif-it italic text-white/95 text-lg leading-relaxed" id="blessingText"></p>
                            <p class="text-white/55 text-sm mt-2 font-semibold" id="blessingRef"></p>
                        </div>
                        <div class="pt-4 border-t" style="border-color:rgba(255,255,255,.12);">
                            <p class="text-[10px] font-bold uppercase tracking-[0.3em] mb-2 hod-blue-text">A prayer for you</p>
                            <p class="text-white/85 text-base leading-relaxed font-medium" id="prayerText"></p>
                        </div>

                        <!-- Exousia 2026 signature -->
                        <div class="text-right mt-4">
                            <p class="text-white/60 text-[13px] italic mb-1">With love and blessing,</p>
                            <p class="font-signature leading-none" style="color:#e6c56a; font-size:26px;">Exousia 2026</p>
                        </div>
                    </div>
                </div> <!-- User buttons -->
                <div class="flex gap-2 mt-5"> <button onclick="copyBlessing()" class="flex-1 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-3 rounded-xl text-sm font-bold transition-colors">📋 Copy message</button> <button onclick="saveBlessing()" class="flex-1 btn-primary px-4 py-3 rounded-xl text-sm font-bold">🖼️ Save as picture</button> </div> <!-- IDI VOLUNTEER CONTINUOUS LOOP UI (Revealed only for volunteers) -->
                <div id="volunteerLoopBtn" class="hidden mt-6 pt-5 border-t" style="border-color:rgba(255,255,255,.12);"> <button class="btn-ghost w-full border-dashed border-2 py-3 text-white/90" onclick="startVolunteer()"> <span class="text-lg mr-1">🔄</span> Check in next person </button> <button class="text-white/40 text-xs font-semibold mt-4 w-full uppercase tracking-wider" onclick="goPhone()">Exit volunteer mode</button> </div> <!-- Standard Footer Text -->
                <div id="standardSuccessFooter">
                    <p class="text-white/45 text-[11px] mt-4">Keep it as a reminder — it was written for you. 🙏</p>
                    <p class="text-white/50 text-xs mt-3">Thank you for joining us today.</p>
                </div>
            </div>
        </div>
    </div>
    <script>
const API = '/api/checkin_api.php';
const CSRF = <?= json_encode($CSRF) ?>;
const TOKEN = <?= json_encode($token) ?>;
let currentEvent = null;
let currentPerson = null;
let editApplied = false;
let isVolunteerMode = false;
let currentFirstName = '';
let currentBlessing = null;

function toast(msg, type) {
    type = type || 'error';
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:99999;background:' + (type === 'success' ? '#10B981' : '#EF4444') + ';color:#fff;padding:10px 18px;border-radius:12px;font-weight:600;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.3)';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function() { el.remove(); }, 3000);
}

function api(action, extra, cb) {
    const d = Object.assign({ action: action, _csrf: CSRF, token: TOKEN }, extra || {});
    $.post(API, d, cb, 'json');
}

function show(id) {
    ['phone', 'volunteer', 'confirm', 'edit', 'register', 'notfound', 'success'].forEach(function(s) {
        document.getElementById('step-' + s).classList.add('hidden');
    });
    document.getElementById('step-' + id).classList.remove('hidden');
}

function loadEvent() {
    api('get_event', {}, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        currentEvent = res.event;
        $('#eventTitle').text(res.event.title);
        const dayInfo = res.event.day_info || {};
        let meta = (res.event.nice_date || '') + (res.event.location ? ' · ' + res.event.location : '');
        if (dayInfo.day_label && dayInfo.in_window) meta = dayInfo.day_label + ' · ' + meta;
        $('#eventMeta').text(meta);
        if (res.event.banner_image_url) $('#bgImg').css('background-image', "url('" + res.event.banner_image_url + "')");
    });
}

function startVolunteer() {
    isVolunteerMode = true;
    $('#volPhone').val('');
    $('#confirmBackBtn').removeClass('hidden');
    show('volunteer');
}

function goPhone() {
    isVolunteerMode = false;
    $('#phoneInput').val('');
    $('#confirmBackBtn').addClass('hidden');
    show('phone');
}

function retryPhoneFlow() {
    if (isVolunteerMode) { startVolunteer(); } else { goPhone(); }
}

function lookupPhone() {
    const phone = $('#phoneInput').val().trim();
    if (!phone) { toast('Please enter a phone number'); return; }
    api('lookup', { phone: phone }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        if (res.already_checked_in) {
            retrieveMyBlessing(phone, res.person ? res.person.first_name : '');
            return;
        }
        if (res.found) {
            currentPerson = res.person;
            $('#confirmName').text(currentPerson.name);
            $('#confirmNameBox').text(currentPerson.name);
            editApplied = false;
            show('confirm');
        } else {
            show('notfound');
        }
    });
}

function editName() {
    $('#editNameInput').val(currentPerson ? currentPerson.name : '');
    show('edit');
}

function goConfirm() { show('confirm'); }

function saveEdit() {
    const newName = $('#editNameInput').val().trim();
    if (!newName) { toast('Name cannot be empty'); return; }
    api('edit_name', { source: currentPerson.source, id: currentPerson.id, name: newName }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        currentPerson.name = res.name;
        $('#confirmName').text(res.name);
        $('#confirmNameBox').text(res.name);
        editApplied = true;
        toast('Name updated successfully', 'success');
        markPresent();
    });
}

function markPresent() {
    api('mark_present', {
        source: currentPerson.source,
        id: currentPerson.id,
        name: currentPerson.name,
        phone: $('#phoneInput').val().trim() || $('#volPhone').val().trim(),
        name_edited: editApplied ? 1 : 0,
        is_volunteer: isVolunteerMode ? 1 : 0
    }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        showSuccess(res.message, res.blessing, res.first_name);
    });
}

function volunteerLookup() {
    const phone = $('#volPhone').val().trim();
    if (!phone) { toast('Please enter the person\'s phone number'); return; }
    $('#phoneInput').val(phone);
    api('lookup', { phone: phone }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        if (res.already_checked_in) {
            retrieveMyBlessing(phone, res.person ? res.person.first_name : '');
            return;
        }
        if (res.found) {
            currentPerson = res.person;
            $('#confirmName').text(currentPerson.name);
            $('#confirmNameBox').text(currentPerson.name);
            editApplied = false;
            show('confirm');
        } else {
            $('#regName').val('');
            $('#regPhone').val(phone);
            show('register');
            toast('Not registered yet — add them below.', 'success');
        }
    });
}

function goVolRegister() {
    $('#regName').val('');
    $('#regPhone').val($('#volPhone').val().trim() || '');
    show('register');
}

function goRegister() {
    $('#regName').val('');
    $('#regPhone').val($('#phoneInput').val().trim() || '');
    show('register');
}

function registerPresent() {
    const name = $('#regName').val().trim();
    const phone = $('#regPhone').val().trim() || $('#phoneInput').val().trim();
    if (!name) { toast('Please enter their full name'); return; }
    if (!phone) { toast('Please enter their phone number'); return; }
    api('register_and_present', {
        name: name,
        phone: phone,
        source: $('#regSource').val(),
        is_volunteer: isVolunteerMode ? 1 : 0
    }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        showSuccess(res.message, res.blessing, res.first_name);
    });
}

function showSuccess(msg, blessing, firstName) {
    currentFirstName = firstName || '';
    currentBlessing = blessing || null;
    $('#successMsg').text(msg);
    if (blessing) {
        $('#blessingDear').text('Dear ' + (firstName || 'friend') + ',');
        $('#blessingText').text(blessing.text);
        $('#blessingRef').text('— ' + blessing.ref);
        $('#prayerText').text(blessing.prayer.replace(/\{name\}/g, firstName || 'friend'));
    } else {
        $('#blessingDear').text('Dear friend,');
        $('#blessingText').text('The LORD bless thee, and keep thee: the LORD make his face shine upon thee, and be gracious unto thee, and give thee peace.');
        $('#blessingRef').text('— Numbers 6:24 (KJV)');
        $('#prayerText').text((firstName || 'friend') + ', may the LORD watch over you, shine His favour upon you, and fill you with His peace.');
    }
    if (isVolunteerMode) {
        $('#volunteerLoopBtn').removeClass('hidden');
        $('#standardSuccessFooter').addClass('hidden');
    } else {
        $('#volunteerLoopBtn').addClass('hidden');
        $('#standardSuccessFooter').removeClass('hidden');
    }
    show('success');
}

function retrieveMyBlessing(phone, fallbackName) {
    api('get_my_blessing', { phone: phone }, function(res) {
        if (res.status === 'success' && res.blessing) {
            showSuccess('Welcome back! Here is your word from the conference.', res.blessing, res.first_name || fallbackName);
        } else {
            showSuccess('Already checked in. Welcome back! 🙌', null, fallbackName);
        }
    });
}

function blessingText() {
    const dear = $('#blessingDear').text();
    const verse = $('#blessingText').text();
    const ref = $('#blessingRef').text();
    const prayer = $('#prayerText').text();
    const ev = $('#eventTitle').text();
    return ev + '\n\n' + dear + '\n\n"' + verse + '" ' + ref + '\n\nA prayer for you:\n' + prayer + '\n\n\nWith love and blessing,\nExousia 2026';
}

function copyBlessing() {
    const text = blessingText();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() { toast('Copied! Keep it close. 💛', 'success'); });
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        toast('Copied! Keep it close. 💛', 'success');
    }
}

async function saveBlessing(){ 
    const card = document.getElementById('blessingCard'); 
    if(!card){ 
        toast('Nothing to save yet.'); 
        return; 
    } 

    toast('Creating your picture…','success'); 

    try { 
        const canvas = await html2canvas(card, { scale:2, useCORS:true, backgroundColor:'#070b1c' }); 
        const slug = (currentFirstName||'blessing').toLowerCase().replace(/[^a-z0-9]+/g,'-'); 
        const fileName = 'blessing-'+slug+'.png'; 

        // Convert to Blob using a Promise to keep the async chain tight for iOS Safari
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const file = new File([blob], fileName, { type: 'image/png' });

        // Target only iOS for the Share Sheet (since Android lacks a direct "Save" button here)
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        if (isIOS && navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({
                    files: [file],
                    title: 'A word just for you',
                });
                toast('Action complete! 🎉','success');
            } catch (err) {
                // If they cancel the share sheet, AbortError is thrown. Ignore it.
                if (err.name !== 'AbortError') {
                    forceDownload(canvas, fileName);
                }
            }
        } else {
            // Android and Desktop will immediately download the file automatically
            forceDownload(canvas, fileName);
        }

    } catch(e) { 
        console.error(e); 
        toast('Could not save as picture — try Copy instead.'); 
    } 
}

function forceDownload(canvas, fileName) {
    const link = document.createElement('a'); 
    link.download = fileName; 
    link.href = canvas.toDataURL('image/png'); 
    document.body.appendChild(link); 
    link.click(); 
    link.remove(); 
    toast('Saved! Your picture is ready. 🎉','success'); 
}

$(document).ready(function() {
    if (!TOKEN) { toast('Missing event. Please scan a valid QR code.'); return; }
    loadEvent();
});
</script>
</body>

</html>
