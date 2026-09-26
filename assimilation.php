<?php
// /assimilation.php
// Public entry point for Assimilation volunteers. Opened on a phone, usually
// from a WhatsApp link. No auth guard and no session: access is scoped by the
// volunteer's own phone number, re-checked by the API on every single action
// (see api/assimilation_public_api.php).

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/assimilation_helpers.php';

$guide_html  = '';
$self_claim  = true;
try {
    $guide_html = reach_markdown(assim_volunteer_guide($pdo));
    $self_claim = assim_allow_self_claim($pdo);
} catch (Throwable $e) {
    error_log('assimilation.php setup: ' . $e->getMessage());
}

$e = fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$host     = $_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm';
$bg_image = '/assets/images/hod_lekki.jpeg';
$og_title = 'Assimilation — Household of David Lekki Centre';
$og_desc  = 'Going after the one. Call the people who have drifted, and walk them home.';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1D356A">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($og_title) ?></title>
    <link rel="icon" type="image/png" href="/assets/images/logo_hod.png">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $e($og_title) ?>">
    <meta property="og:description" content="<?= $e($og_desc) ?>">
    <meta property="og:image" content="https://<?= $e($host) ?>/assets/images/logo_hod.png">
    <meta property="og:url" content="https://<?= $e($host) ?>/assimilation.php">
    <meta name="twitter:card" content="summary">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1D356A', hodRed: '#D11920', hodInk: '#0A0E17', hodHome: '#047857' },
                    fontFamily: { sans: ['Inter', 'sans-serif'], display: ['Montserrat', 'sans-serif'] },
                    animation: {
                        'fade-in': 'fadeIn 0.8s ease-out both',
                        'slide-up': 'slideUp 0.6s ease-out both',
                        'pulse-slow': 'pulse 6s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'rise': 'rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) both'
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        rise:   { '0%': { opacity: '0', transform: 'scale(0.94)' }, '100%': { opacity: '1', transform: 'scale(1)' } }
                    }
                }
            }
        };
    </script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <style type="text/tailwindcss">
        @layer components {
            .glass       { @apply bg-white/10 backdrop-blur-xl border border-white/20 shadow-2xl shadow-black/30; }
            .panel       { @apply bg-white/95 backdrop-blur-xl border border-white/60 shadow-2xl shadow-black/40 rounded-3xl; }
            .step-badge  { @apply shrink-0 w-8 h-8 rounded-full bg-hodBlue text-white font-display font-bold text-sm flex items-center justify-center shadow-md shadow-hodBlue/40; }
            .field-label { @apply block text-[11px] font-semibold text-gray-600 uppercase tracking-wider mb-1.5; }
            .field       { @apply w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-800 text-base shadow-sm outline-none transition-all placeholder:text-gray-400 hover:bg-white focus:bg-white focus:ring-2 focus:ring-hodBlue focus:border-transparent; }
            .btn-primary { @apply w-full min-h-[52px] bg-hodRed hover:bg-red-700 text-white font-semibold py-4 px-4 rounded-xl shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all flex justify-center items-center gap-2 disabled:opacity-60; }
            .chip        { @apply min-h-[44px] px-3.5 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 transition-all hover:border-hodBlue/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-hodBlue; }
            .chip.active { @apply bg-hodBlue text-white border-hodBlue shadow-md shadow-hodBlue/30; }
            .pill        { @apply min-h-[44px] px-3 rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-600 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-hodBlue; }
            .pill.active { @apply bg-hodHome text-white border-hodHome; }
            .tap-btn     { @apply min-h-[44px] flex-1 flex items-center justify-center gap-1.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-hodBlue; }
        }
    </style>
    <style>
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
        }
        .spark { display: flex; align-items: flex-end; gap: 2px; height: 22px; }
        .spark i { display: block; width: 4px; border-radius: 2px; background: #047857; opacity: 0.85; }
        .spark i.zero { background: #d1d5db; }
    </style>
</head>
<body class="bg-hodInk font-sans antialiased text-gray-800 overflow-x-hidden">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?= $e($bg_image) ?>" alt="" class="w-full h-full object-cover object-[center_30%] opacity-50">
        <div class="absolute inset-0 bg-hodBlue/50 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-hodBlue/70 to-hodInk/95"></div>
    </div>
    <div class="fixed -top-24 -left-24 w-80 h-80 bg-blue-500/25 rounded-full blur-[100px] animate-pulse-slow z-0" aria-hidden="true"></div>
    <div class="fixed -bottom-24 -right-24 w-80 h-80 bg-emerald-500/20 rounded-full blur-[100px] animate-pulse-slow z-0" style="animation-delay: 3s;" aria-hidden="true"></div>

    <main class="relative z-10 min-h-screen max-w-lg mx-auto px-4 pt-6 pb-24 sm:pt-10 space-y-4">

        <header class="flex items-center gap-3 animate-fade-in">
            <div class="shrink-0 w-12 h-12 rounded-2xl bg-white shadow-lg shadow-black/20 flex items-center justify-center p-1.5">
                <img src="/assets/images/hod_logo.svg" alt="Household of David" class="w-full h-full object-contain">
            </div>
            <div class="min-w-0">
                <span class="inline-block bg-hodRed text-white text-[10px] font-display font-bold tracking-[0.2em] uppercase px-2 py-0.5 rounded-md">Assimilation</span>
                <p class="text-sm font-medium text-white/90 truncate mt-1">Household of David &middot; Lekki Centre</p>
            </div>
        </header>

        <!-- ===================== STEP 1 — Who are you? ===================== -->
        <section id="step1" class="panel p-6 space-y-5 animate-slide-up">
            <div class="flex items-start gap-3">
                <span class="step-badge">1</span>
                <div>
                    <h1 class="font-display font-bold text-gray-900 text-xl leading-tight">Going after the one</h1>
                    <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">Enter the phone number the church has for you. Only the Assimilation team can open this page.</p>
                </div>
            </div>
            <form id="volForm" class="flex gap-2">
                <label for="volPhoneInput" class="sr-only">Your phone number</label>
                <input type="tel" id="volPhoneInput" inputmode="tel" autocomplete="tel" placeholder="e.g. 0803 344 5566" class="field flex-1 min-w-0 font-semibold">
                <button type="submit" class="shrink-0 min-h-[52px] bg-hodBlue hover:bg-hodBlue/90 text-white px-5 rounded-xl font-semibold text-sm shadow-lg shadow-hodBlue/30 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-hodBlue">Continue</button>
            </form>
            <div id="volMatchCard" class="hidden rounded-2xl border border-hodBlue/15 bg-hodBlue/5 p-4 space-y-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-hodBlue/70">Welcome back</p>
                    <p id="volMatchName" class="font-display font-bold text-hodBlue text-lg truncate">&mdash;</p>
                    <p class="text-xs text-gray-600 mt-0.5">You&rsquo;re an Assimilation volunteer.</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="confirmVolunteer()" class="min-h-[48px] bg-hodRed hover:bg-red-700 text-white rounded-xl font-semibold text-sm shadow-md shadow-red-500/30 transition-all">Yes, that&rsquo;s me</button>
                    <button type="button" onclick="rejectVolunteer()" class="min-h-[48px] bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-xl font-semibold text-sm transition-all">Not me</button>
                </div>
            </div>
            <div id="volNoMatchCard" class="hidden rounded-2xl border border-hodRed/20 bg-hodRed/5 p-4 text-sm">
                <p class="font-semibold text-hodRed">We couldn&rsquo;t find that number on the team.</p>
                <p class="text-gray-600 mt-1">Check it and try again, or ask your Assimilation leader to add you to the team.</p>
            </div>
        </section>

        <!-- ===================== STEP 2 — Your people ===================== -->
        <div id="step2" class="hidden space-y-4">

            <div id="volunteerBanner" class="glass rounded-2xl px-4 py-3 flex items-center justify-between gap-3 text-white">
                <div class="flex items-center gap-3 min-w-0">
                    <div id="volunteerInitial" class="shrink-0 w-10 h-10 rounded-full bg-hodRed flex items-center justify-center font-display font-bold shadow-md shadow-red-500/30">?</div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-blue-200">Signed in as</p>
                        <p id="volunteerName" class="text-sm font-semibold truncate">&mdash;</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 shrink-0">
                    <div class="text-right">
                        <p id="doneToday" class="font-display font-extrabold text-2xl leading-none">0</p>
                        <p class="text-[10px] uppercase tracking-widest text-blue-200 mt-0.5">Today</p>
                    </div>
                    <button type="button" onclick="signOut()" class="text-xs font-semibold text-white/80 hover:text-white underline underline-offset-4 decoration-white/30 min-h-[44px]">Switch</button>
                </div>
            </div>

            <div id="homeBanner" class="hidden glass rounded-2xl px-4 py-3 text-white flex items-center gap-3">
                <span class="shrink-0 w-9 h-9 rounded-full bg-hodHome flex items-center justify-center" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </span>
                <p class="text-sm"><span id="homeCount" class="font-display font-bold">0</span> <span id="homeWord">people you called have come home.</span></p>
            </div>

            <section class="panel p-5 sm:p-6 space-y-4">
                <div class="flex items-start gap-3">
                    <span class="step-badge">2</span>
                    <div class="min-w-0">
                        <h2 class="font-display font-bold text-gray-900 text-lg leading-tight">Your people to call</h2>
                        <p class="text-sm text-gray-500 mt-1">Pray for them by name, then call. Most urgent first.</p>
                    </div>
                </div>
                <div id="myList" class="space-y-3" aria-live="polite"></div>
            </section>

            <?php if ($self_claim): ?>
            <section id="poolSection" class="hidden panel p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="font-display font-bold text-gray-900 text-lg leading-tight">Nobody is calling these yet</h2>
                    <p class="text-sm text-gray-500 mt-1">Pick up anyone you can carry this week.</p>
                </div>
                <div id="poolList" class="space-y-3"></div>
            </section>
            <?php endif; ?>
        </div>

        <?php if ($guide_html !== ''): ?>
        <details class="panel p-5 sm:p-6 group" id="guideBlock">
            <summary class="flex items-center justify-between gap-3 cursor-pointer list-none min-h-[44px]">
                <span class="min-w-0">
                    <span class="block font-display font-bold text-gray-900 text-lg">How to follow up with love</span>
                    <span class="block text-sm text-gray-500 mt-0.5">Prepare your heart, the heart of the call, what to say in hard situations, and how to keep yourself filled.</span>
                </span>
                <span class="shrink-0 w-9 h-9 rounded-full bg-hodBlue/10 text-hodBlue flex items-center justify-center transition-transform group-open:rotate-180" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </span>
            </summary>
            <div class="mt-5 space-y-4 text-sm"><?= $guide_html ?></div>
        </details>
        <?php endif; ?>

        <p class="text-center text-xs text-white/50 pt-2">&copy; <?= date('Y') ?> Household of David &middot; Assimilation</p>
    </main>

    <!-- ===================== LOGGING SHEET ===================== -->
    <div id="logSheet" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="sheetName">
        <div data-sheet-backdrop onclick="closeSheet()" class="absolute inset-0 bg-hodInk/70 backdrop-blur-md opacity-0 transition-opacity duration-300"></div>
        <div data-sheet-panel class="absolute inset-x-0 bottom-0 max-h-[94vh] bg-white rounded-t-3xl shadow-2xl flex flex-col translate-y-full transition-transform duration-300 sm:inset-x-auto sm:right-0 sm:top-0 sm:bottom-0 sm:w-[460px] sm:max-h-none sm:rounded-none sm:rounded-l-3xl sm:translate-y-0 sm:translate-x-full">
            <div class="shrink-0 px-5 pt-3 pb-4 border-b border-gray-100">
                <div class="w-10 h-1 rounded-full bg-gray-200 mx-auto mb-3 sm:hidden" aria-hidden="true"></div>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 id="sheetName" class="font-display font-bold text-gray-900 text-lg truncate">&mdash;</h2>
                        <p id="sheetSub" class="text-xs text-gray-500 mt-0.5"></p>
                    </div>
                    <button type="button" onclick="closeSheet()" aria-label="Close" class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-gray-400 hover:text-hodRed hover:bg-hodRed/5 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div id="sheetBody" class="flex-1 overflow-y-auto px-5 py-5 space-y-5"></div>
        </div>
    </div>

    <div id="celebrate" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-6 bg-hodInk/70 backdrop-blur-md" role="status">
        <div class="panel p-8 text-center max-w-xs animate-rise">
            <div class="w-16 h-16 mx-auto rounded-full bg-hodHome flex items-center justify-center text-white shadow-lg shadow-emerald-900/30">
                <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 class="font-display font-bold text-xl text-gray-900 mt-4" id="celebrateTitle">They came home</h2>
            <p class="text-sm text-gray-600 mt-2 leading-relaxed" id="celebrateBody"></p>
            <button type="button" onclick="closeCelebrate()" class="mt-6 w-full min-h-[48px] bg-hodBlue hover:bg-hodBlue/90 text-white rounded-xl font-semibold text-sm">Amen</button>
        </div>
    </div>

<script>
const PUBLIC_API = '/api/assimilation_public_api.php';
const LS_KEY = 'assimilation_volunteer_v1';
const TOAST_BG = { success: '#047857', info: '#1D356A', warn: '#B45309', error: '#D11920' };

const CHANNEL_LABEL = { Call: 'Call', WhatsApp: 'WhatsApp', SMS: 'SMS', Visit: 'Visit', At_Church: 'At church' };
const OUTCOME_LABEL = {
    Spoke_With_Them: 'We spoke', Promised_To_Come: 'Promised to come', No_Answer: 'No answer',
    Wrong_Number: 'Wrong number', Not_Interested: 'Not interested', Relocated: 'Has relocated',
    Attends_Elsewhere: 'Attends elsewhere', Asked_For_No_Contact: 'Asked for no contact'
};
// The four that end a follow-up, so the volunteer is warned before saving.
const CLOSING_OUTCOMES = ['Not_Interested', 'Relocated', 'Attends_Elsewhere', 'Asked_For_No_Contact'];

let volunteer = null;      // { phone, name }
let people = { mine: [], pool: [] };
let sheet = { caseId: null, channel: 'Call', outcome: '', next: '', saving: false };
let recognition = null;
let recognizing = false;

function toast(msg, type = 'success') {
    Toastify({
        text: msg, gravity: 'top', position: 'center', duration: 3200,
        style: {
            background: TOAST_BG[type] || TOAST_BG.error, borderRadius: '14px',
            fontWeight: '600', fontFamily: 'Inter, sans-serif', boxShadow: '0 12px 30px rgba(10,14,23,0.35)'
        }
    }).showToast();
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

const icon = (d, cls = 'w-4 h-4') =>
    `<svg class="${cls}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${d}"/></svg>`;
const ICON_PHONE = 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z';
const ICON_CHAT  = 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z';

/* ------------------------------ storage ------------------------------ */
function saveVolunteerLocal(v) { try { localStorage.setItem(LS_KEY, JSON.stringify(v)); } catch (e) {} }
function loadVolunteerLocal() { try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; } }
function clearVolunteerLocal() { try { localStorage.removeItem(LS_KEY); } catch (e) {} }

/* ------------------------------ step 1 ------------------------------ */
function lookupVolunteer() {
    const phone = $('#volPhoneInput').val().trim();
    $('#volMatchCard, #volNoMatchCard').addClass('hidden');
    if (phone.replace(/\D/g, '').length < 9) {
        toast('Enter your full phone number', 'warn');
        return;
    }
    $.post(PUBLIC_API, { action: 'check_volunteer', phone: phone }, function(res) {
        if (res.exists) {
            $('#volMatchName').text(res.name || phone);
            $('#volMatchCard').removeClass('hidden').data('payload', { phone: phone, name: res.name || '' });
        } else {
            $('#volNoMatchCard').removeClass('hidden');
        }
    }, 'json').fail(() => toast('Network problem — please try again', 'error'));
}

function confirmVolunteer() {
    const payload = $('#volMatchCard').data('payload');
    if (!payload) return;
    saveVolunteerLocal(payload);
    activateVolunteer(payload);
}
function rejectVolunteer() {
    $('#volMatchCard').addClass('hidden');
    $('#volPhoneInput').val('').trigger('focus');
}
function activateVolunteer(v) {
    volunteer = v;
    const name = (v.name || '').trim();
    $('#volunteerName').text(name || v.phone);
    $('#volunteerInitial').text((name || '?').charAt(0).toUpperCase());
    $('#step1').addClass('hidden');
    $('#step2').removeClass('hidden');
    loadPeople();
}
function signOut() {
    volunteer = null;
    clearVolunteerLocal();
    $('#step2').addClass('hidden');
    $('#step1').removeClass('hidden');
    $('#volMatchCard, #volNoMatchCard').addClass('hidden');
    $('#volPhoneInput').val('').trigger('focus');
}

/* ------------------------------ the list ------------------------------ */
function listSkeleton(n = 3) {
    return Array.from({ length: n }, () => `
        <div class="rounded-2xl border border-gray-100 bg-gray-50/60 p-4 space-y-3 animate-pulse" aria-hidden="true">
            <div class="h-4 bg-gray-200 rounded w-1/2"></div>
            <div class="h-3 bg-gray-200 rounded w-3/4"></div>
            <div class="flex gap-2"><div class="h-11 bg-gray-200 rounded-xl flex-1"></div><div class="h-11 bg-gray-200 rounded-xl flex-1"></div></div>
        </div>`).join('');
}

function sparkline(trend) {
    if (!trend || !trend.length) return '';
    const max = Math.max(1, ...trend);
    return `<span class="spark" role="img" aria-label="Attendance over the last 12 months: ${trend.join(', ')}">`
        + trend.map(v => `<i class="${v === 0 ? 'zero' : ''}" style="height:${Math.max(3, Math.round(22 * v / max))}px"></i>`).join('')
        + '</span>';
}

function dueLabel(c) {
    if (c.is_overdue) return ['Overdue', 'bg-hodRed text-white'];
    if (c.next_touch && c.next_touch <= todayStr()) return ['Due today', 'bg-amber-500 text-white'];
    if (!c.touches) return ['Not called yet', 'bg-hodBlue text-white'];
    return null;
}

function personCard(c, inPool) {
    const due = inPool ? null : dueLabel(c);
    const note = c.last_note && c.last_note.note ? String(c.last_note.note).split('\n')[0] : '';
    const meta = [c.spiritual_status, c.departments].filter(Boolean).join(' · ');
    return `
    <article class="rounded-2xl border border-gray-100 bg-white shadow-sm p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-gray-900 truncate">${escapeHtml(c.name)}</h3>
                <p class="text-xs text-gray-500 mt-0.5 truncate">${escapeHtml(meta || 'Household of David')}</p>
            </div>
            ${due ? `<span class="shrink-0 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full ${due[1]}">${due[0]}</span>` : ''}
        </div>
        <div class="flex flex-wrap gap-1.5">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-hodBlue/5 border border-hodBlue/15 text-hodBlue px-2.5 py-1 text-[11px] font-semibold">
                ${icon('M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'w-3.5 h-3.5')}
                ${c.last_attended ? 'Last in church ' + escapeHtml(niceDate(c.last_attended)) : 'No attendance on record'}
            </span>
            ${c.prior && c.prior.total
                ? `<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-800 px-2.5 py-1 text-[11px] font-semibold">${icon(ICON_PHONE, 'w-3.5 h-3.5')}Reached out ${c.prior.total}&times; &middot; last ${escapeHtml(c.prior.when || '')} by ${escapeHtml(c.prior.by || 'a volunteer')} (${escapeHtml(c.prior.outcome || '')})</span>`
                : `<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-2.5 py-1 text-[11px] font-semibold">${icon(ICON_PHONE, 'w-3.5 h-3.5')}Nobody has reached out yet</span>`}
        </div>
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Last in church</p>
                <p class="text-sm font-semibold text-gray-800">${escapeHtml(c.since_words)}</p>
            </div>
            ${sparkline(c.trend)}
        </div>
        ${note ? `<p class="text-xs text-gray-600 bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 leading-relaxed line-clamp-3">${escapeHtml(note)}</p>` : ''}
        ${c.next_touch && !inPool ? `<p class="text-xs font-semibold text-hodBlue">Next touch: ${escapeHtml(niceDate(c.next_touch))}</p>` : ''}
        ${inPool ? `
            <button type="button" onclick="claim(${c.case_id})" class="w-full min-h-[48px] bg-hodHome hover:bg-emerald-800 text-white rounded-xl font-bold text-sm transition-all">I&rsquo;ll call ${escapeHtml(c.first_name)}</button>`
        : `
            <div class="flex gap-2">
                <a href="tel:${escapeHtml(c.phone || '')}" class="tap-btn bg-hodBlue hover:bg-hodBlue/90 text-white">${icon(ICON_PHONE)} Call</a>
                <a href="https://wa.me/${escapeHtml(c.whatsapp || '')}" target="_blank" rel="noopener" class="tap-btn bg-[#25D366] hover:bg-[#128C7E] text-white">${icon(ICON_CHAT)} WhatsApp</a>
                <button type="button" onclick="openSheet(${c.case_id})" class="tap-btn bg-gray-100 hover:bg-gray-200 text-gray-800">Log</button>
            </div>`}
    </article>`;
}

function renderPeople() {
    const empty = `
        <div class="rounded-2xl border border-dashed border-gray-200 p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-hodHome mx-auto mb-3 flex items-center justify-center">${icon('M5 13l4 4L19 7', 'w-7 h-7')}</div>
            <p class="font-display font-bold text-gray-900">Nobody waiting on you</p>
            <p class="text-sm text-gray-500 mt-1">You are all caught up. Keep praying for the ones you have called.</p>
        </div>`;
    $('#myList').html(people.mine.length ? people.mine.map(c => personCard(c, false)).join('') : empty);
    if (people.pool.length) {
        $('#poolSection').removeClass('hidden');
        $('#poolList').html(people.pool.map(c => personCard(c, true)).join(''));
    } else {
        $('#poolSection').addClass('hidden');
    }
}

function loadPeople(showSkeleton = true) {
    if (!volunteer) return;
    if (showSkeleton) $('#myList').html(listSkeleton());
    $.post(PUBLIC_API, { action: 'my_people', volunteer_phone: volunteer.phone }, function(res) {
        if (res.status !== 'success') {
            toast(res.message || 'Could not load your list', 'error');
            if (/sign in/i.test(res.message || '')) signOut();
            return;
        }
        people = { mine: res.data.mine, pool: res.data.pool };
        $('#doneToday').text(res.data.done_today);
        const home = res.data.brought_home;
        $('#homeBanner').toggleClass('hidden', home === 0);
        $('#homeCount').text(home);
        $('#homeWord').text(home === 1 ? 'person you called has come home.' : 'people you called have come home.');
        renderPeople();
    }, 'json').fail(() => {
        $('#myList').html(`<div class="rounded-2xl border border-hodRed/20 bg-hodRed/5 p-5 text-center">
            <p class="text-sm font-semibold text-hodRed">We couldn&rsquo;t reach the server.</p>
            <button type="button" onclick="loadPeople()" class="mt-3 min-h-[44px] px-5 bg-hodBlue text-white rounded-xl font-semibold text-sm">Try again</button>
        </div>`);
    });
}

function claim(caseId) {
    $.post(PUBLIC_API, { action: 'claim_case', volunteer_phone: volunteer.phone, case_id: caseId }, function(res) {
        toast(res.message, res.status === 'success' ? 'success' : 'error');
        if (res.status === 'success') loadPeople(false);
    }, 'json').fail(() => toast('Network problem — please try again', 'error'));
}

/* ------------------------------ dates ------------------------------ */
const pad2 = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const todayStr = () => ymd(new Date());
function niceDate(s) {
    if (!s) return '';
    const d = new Date(String(s) + 'T00:00:00');
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}
function nextSunday() {
    const d = new Date();
    d.setDate(d.getDate() + ((7 - d.getDay()) % 7 || 7));
    return ymd(d);
}
function inDays(n) {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return ymd(d);
}

/* ------------------------------ the sheet ------------------------------ */
function currentCase() {
    return people.mine.find(c => c.case_id === sheet.caseId) || people.pool.find(c => c.case_id === sheet.caseId);
}

function openSheet(caseId) {
    const c = people.mine.find(x => x.case_id === caseId);
    if (!c) return;
    sheet = { caseId: caseId, channel: 'Call', outcome: '', next: '', saving: false };

    $('#sheetName').text(c.name);
    $('#sheetSub').text(`${c.status_words} · last in church ${c.since_words}`);
    $('#sheetBody').html(sheetForm(c));
    syncChips();

    const el = document.getElementById('logSheet');
    document.body.appendChild(el);
    el.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        el.querySelector('[data-sheet-backdrop]').classList.remove('opacity-0');
        el.querySelector('[data-sheet-panel]').classList.remove('translate-y-full', 'sm:translate-x-full');
    });
}

function closeSheet() {
    const el = document.getElementById('logSheet');
    if (el.classList.contains('hidden')) return;
    if (recognizing && recognition) { try { recognition.stop(); } catch (e) {} }
    el.querySelector('[data-sheet-backdrop]').classList.add('opacity-0');
    el.querySelector('[data-sheet-panel]').classList.add('translate-y-full', 'sm:translate-x-full');
    setTimeout(() => { el.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
    sheet.caseId = null;
}

function sheetForm(c) {
    const chanChips = Object.keys(CHANNEL_LABEL).map(k =>
        `<button type="button" role="radio" aria-checked="false" data-chan="${k}" onclick="pickChannel('${k}')" class="chip chan-chip">${CHANNEL_LABEL[k]}</button>`).join('');
    const outChips = Object.keys(OUTCOME_LABEL).map(k =>
        `<button type="button" role="radio" aria-checked="false" data-out="${k}" onclick="pickOutcome('${k}')" class="chip out-chip">${OUTCOME_LABEL[k]}</button>`).join('');
    // On a Saturday "Tomorrow" and "Next Sunday" are the same day; keep the
    // first label rather than lighting up two pills for one date.
    const seen = new Set();
    const quick = [['Tomorrow', inDays(1)], ['In 3 days', inDays(3)], ['Next Sunday', nextSunday()], ['In a week', inDays(7)]]
        .filter(([, v]) => !seen.has(v) && seen.add(v))
        .map(([l, v]) => `<button type="button" data-next="${v}" onclick="pickNext('${v}')" class="pill next-pill">${l}</button>`).join('');

    return `
    <section class="rounded-2xl bg-hodBlue/5 border border-hodBlue/10 p-4 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-wider text-hodBlue/70">Before you call</p>
                <p class="text-sm text-gray-700 mt-0.5">Pray for ${escapeHtml(c.first_name)} by name. Lead with love, not attendance.</p>
            </div>
            ${sparkline(c.trend)}
        </div>
        <button type="button" id="lineBtn" onclick="suggestLine()" class="w-full min-h-[44px] bg-white border border-hodBlue/20 hover:border-hodBlue/40 text-hodBlue rounded-xl font-semibold text-xs transition-all">Suggest a gentle opening line</button>
        <p id="lineOut" class="hidden text-sm text-gray-800 bg-white border border-hodBlue/10 rounded-xl px-3 py-2.5 italic leading-relaxed"></p>
        <div class="flex gap-2 pt-1">
            <a href="tel:${escapeHtml(c.phone || '')}" class="tap-btn bg-hodBlue hover:bg-hodBlue/90 text-white">${icon(ICON_PHONE)} Call ${escapeHtml(c.phone || '')}</a>
            <a href="https://wa.me/${escapeHtml(c.whatsapp || '')}" target="_blank" rel="noopener" class="tap-btn bg-[#25D366] hover:bg-[#128C7E] text-white px-4 flex-none">${icon(ICON_CHAT)}</a>
        </div>
    </section>

    <div>
        <p class="field-label" id="chanLabel">How did you reach out?</p>
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-labelledby="chanLabel">${chanChips}</div>
    </div>

    <div>
        <p class="field-label" id="outLabel">How did it go? <span class="text-hodRed">*</span></p>
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-labelledby="outLabel">${outChips}</div>
        <p id="closingWarn" class="hidden mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">Saving this closes the follow-up and tells your leaders why. Bless them before you drop.</p>
    </div>

    <div>
        <div class="flex items-center justify-between gap-2 mb-1.5">
            <label for="fldNotes" class="field-label mb-0">Notes</label>
            <div class="flex gap-1.5">
                <button type="button" id="micBtn" onclick="toggleMic()" aria-label="Dictate the notes" class="min-h-[44px] w-11 rounded-xl bg-hodBlue/10 hover:bg-hodBlue/15 text-hodBlue flex items-center justify-center transition-all">
                    ${icon('M19 11a7 7 0 01-14 0m7 7v3m0 0h-4m4 0h4m-4-7a3 3 0 01-3-3V6a3 3 0 116 0v5a3 3 0 01-3 3z', 'w-5 h-5')}
                </button>
                <button type="button" id="cleanBtn" onclick="cleanNotes()" class="min-h-[44px] px-3 rounded-xl bg-hodBlue/10 hover:bg-hodBlue/15 text-hodBlue text-xs font-bold transition-all">Clean up with AI</button>
            </div>
        </div>
        <textarea id="fldNotes" rows="4" placeholder="What did they say? What are they carrying?" class="field resize-none text-base"></textarea>
        <p id="micHint" class="hidden text-xs font-semibold text-hodRed mt-1.5">Listening… tap the mic again to stop.</p>
        <div id="cleanBox" class="hidden mt-2 rounded-xl border border-hodHome/25 bg-emerald-50/70 p-3 space-y-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hodHome">Tidied up — check it, then keep or discard</p>
            <p id="cleanText" class="text-sm text-gray-800 whitespace-pre-line leading-relaxed"></p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" onclick="keepClean()" class="min-h-[44px] bg-hodHome hover:bg-emerald-800 text-white rounded-xl font-bold text-xs">Use this</button>
                <button type="button" onclick="discardClean()" class="min-h-[44px] bg-white border border-gray-200 text-gray-600 rounded-xl font-bold text-xs">Keep mine</button>
            </div>
        </div>
    </div>

    <div>
        <label for="fldPrayer" class="field-label">Prayer points</label>
        <textarea id="fldPrayer" rows="2" placeholder="What can the team stand with them for?" class="field resize-none text-base"></textarea>
    </div>

    <div>
        <p class="field-label" id="nextLabel">When will you check on them again?</p>
        <div class="flex flex-wrap gap-2" role="group" aria-labelledby="nextLabel">${quick}</div>
        <input type="date" id="fldNext" min="${todayStr()}" onchange="pickNext(this.value)" class="field mt-2 text-base" aria-label="Next touch date">
    </div>

    <button type="button" id="saveBtn" onclick="saveFollowUp()" class="btn-primary">
        ${icon('M5 13l4 4L19 7', 'w-5 h-5')}<span>Save follow-up</span>
    </button>
    <p class="text-center text-xs text-gray-400 pb-2">Only your leaders and this team can see what you write.</p>`;
}

function pickChannel(v) { sheet.channel = v; syncChips(); }
function pickOutcome(v) { sheet.outcome = v; syncChips(); }
function pickNext(v) { sheet.next = v; $('#fldNext').val(v); syncChips(); }

function syncChips() {
    $('.chan-chip').each(function() {
        const on = this.dataset.chan === sheet.channel;
        $(this).toggleClass('active', on).attr('aria-checked', on ? 'true' : 'false');
    });
    $('.out-chip').each(function() {
        const on = this.dataset.out === sheet.outcome;
        $(this).toggleClass('active', on).attr('aria-checked', on ? 'true' : 'false');
    });
    $('.next-pill').each(function() {
        $(this).toggleClass('active', this.dataset.next === sheet.next);
    });
    $('#closingWarn').toggleClass('hidden', !CLOSING_OUTCOMES.includes(sheet.outcome));
}

/* ------------------------------ AI + voice ------------------------------ */
function setupSpeech() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return null;
    const r = new SR();
    r.lang = 'en-NG';
    r.interimResults = false;
    r.continuous = true;
    r.onresult = (evt) => {
        let text = '';
        for (let i = evt.resultIndex; i < evt.results.length; i++) {
            if (evt.results[i].isFinal) text += evt.results[i][0].transcript;
        }
        if (!text) return;
        const prev = $('#fldNotes').val();
        $('#fldNotes').val(prev ? prev.replace(/\s*$/, '') + ' ' + text.trim() : text.trim());
    };
    r.onend = () => { recognizing = false; micUi(false); };
    r.onerror = () => { recognizing = false; micUi(false); toast('Microphone problem — type instead', 'warn'); };
    return r;
}
function micUi(on) {
    $('#micHint').toggleClass('hidden', !on);
    $('#micBtn').toggleClass('bg-hodRed text-white animate-pulse', on).toggleClass('bg-hodBlue/10 text-hodBlue', !on);
}
function toggleMic() {
    if (!recognition) recognition = setupSpeech();
    if (!recognition) { toast('Voice isn’t supported on this browser — type instead', 'warn'); return; }
    if (recognizing) { try { recognition.stop(); } catch (e) {} return; }
    try {
        recognition.start();
        recognizing = true;
        micUi(true);
    } catch (e) {
        toast('Microphone is blocked', 'error');
    }
}

function cleanNotes() {
    const notes = ($('#fldNotes').val() || '').trim();
    if (!notes) { toast('Write or dictate the notes first', 'warn'); $('#fldNotes').trigger('focus'); return; }
    const $b = $('#cleanBtn').prop('disabled', true).text('Tidying…');
    $.post(PUBLIC_API, { action: 'clean_notes', volunteer_phone: volunteer.phone, notes: notes }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'warn'); return; }
        $('#cleanText').text(res.data.notes_clean);
        $('#cleanBox').removeClass('hidden').data('raw', notes).data('clean', res.data.notes_clean);
    }, 'json').fail(() => toast('Network problem — your notes are safe', 'error'))
      .always(() => $b.prop('disabled', false).text('Clean up with AI'));
}
function keepClean() {
    // Both versions are stored: the volunteer's own words stay the record.
    $('#cleanBox').addClass('hidden').data('keep', true);
    toast('Tidied version will be saved alongside your own words', 'info');
}
function discardClean() {
    $('#cleanBox').addClass('hidden').removeData('clean').data('keep', false);
}

function suggestLine() {
    const $b = $('#lineBtn').prop('disabled', true).text('Thinking…');
    $.post(PUBLIC_API, { action: 'opening_line', volunteer_phone: volunteer.phone, case_id: sheet.caseId }, function(res) {
        if (res.status !== 'success') { toast(res.message, 'warn'); return; }
        $('#lineOut').text('“' + res.data.line + '”').removeClass('hidden');
    }, 'json').fail(() => toast('Network problem — please try again', 'error'))
      .always(() => $b.prop('disabled', false).text('Suggest another opening line'));
}

/* ------------------------------ saving ------------------------------ */
function saveFollowUp() {
    if (sheet.saving) return;
    if (!sheet.outcome) { toast('Tell us how it went first', 'warn'); return; }
    const c = currentCase();
    sheet.saving = true;
    const $btn = $('#saveBtn').prop('disabled', true);
    $btn.find('span').text('Saving…');

    const payload = {
        action: 'log_follow_up',
        volunteer_phone: volunteer.phone,
        case_id: sheet.caseId,
        channel: sheet.channel,
        outcome: sheet.outcome,
        notes: ($('#fldNotes').val() || '').trim(),
        notes_clean: $('#cleanBox').data('keep') ? ($('#cleanBox').data('clean') || '') : '',
        prayer_points: ($('#fldPrayer').val() || '').trim(),
        next_touch_date: sheet.next || ''
    };

    $.post(PUBLIC_API, payload, function(res) {
        if (res.status !== 'success') { toast(res.message, 'error'); return; }
        closeSheet();
        if (res.data && res.data.returned_home) {
            celebrate(c ? c.first_name : 'They');
        } else if (res.data && res.data.closed) {
            toast('Saved — ' + (res.data.status_words || '').toLowerCase() + '. Thank you for closing it kindly.', 'info');
        } else {
            toast('Saved. Thank you for going after them.');
        }
        loadPeople(false);
    }, 'json').fail(function() {
        // Offline-tolerant: nothing is lost, the sheet stays open with the
        // notes still in it, and one tap tries again.
        toast('No connection — nothing was lost. Tap Save again when you have signal.', 'error');
    }).always(function() {
        sheet.saving = false;
        $btn.prop('disabled', false).find('span').text('Save follow-up');
    });
}

function celebrate(name) {
    $('#celebrateTitle').text(escapeHtml(name) + ' came home');
    $('#celebrateBody').text('They have been back in the house since you reached out. Your leaders have been told. Welcome them well this Sunday.');
    $('#celebrate').removeClass('hidden');
    document.body.style.overflow = 'hidden';
}
function closeCelebrate() {
    $('#celebrate').addClass('hidden');
    document.body.style.overflow = '';
}

/* ------------------------------ wiring ------------------------------ */
$('#volForm').on('submit', function(ev) { ev.preventDefault(); lookupVolunteer(); });

$(document).on('keydown', function(ev) {
    if (ev.key !== 'Escape') return;
    if (!$('#celebrate').hasClass('hidden')) { closeCelebrate(); return; }
    if (!$('#logSheet').hasClass('hidden')) closeSheet();
});

$(document).ready(function() {
    const cached = loadVolunteerLocal();
    if (cached && cached.phone) {
        // Re-verify on every load, so a volunteer who has been taken off the
        // team drops back to step 1 instead of failing on first save.
        $.post(PUBLIC_API, { action: 'check_volunteer', phone: cached.phone }, function(res) {
            if (res.exists) {
                activateVolunteer({ phone: cached.phone, name: res.name || '' });
            } else {
                clearVolunteerLocal();
            }
        }, 'json');
    }
});
</script>
</body>
</html>
