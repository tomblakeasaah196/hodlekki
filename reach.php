<?php
// /reach.php
// Public capture entry point. Volunteers open /reach.php?c=<slug> in the
// field (usually shared via WhatsApp) and record leads with two taps.
// No auth guard — access is scoped by the campaign slug and the
// volunteer identity check in Step 1.

require_once __DIR__ . '/includes/db.php';

$slug = trim($_GET['c'] ?? '');
$campaign = null;
$campaign_fields = [];

if ($slug !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, slug, title, campaign_type, campaign_date, start_time, end_time,
                   location, meta_description, share_scripture, payload_tier, status
              FROM reach_campaigns
             WHERE slug = ? LIMIT 1
        ");
        $stmt->execute([$slug]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($campaign) {
            $fStmt = $pdo->prepare("SELECT field_name FROM reach_campaign_fields WHERE campaign_id = ?");
            $fStmt->execute([$campaign['id']]);
            $campaign_fields = $fStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        error_log('reach.php lookup failed: ' . $e->getMessage());
        $campaign = null;
    }
}

$show_field = function(string $name) use ($campaign_fields): bool {
    return in_array($name, $campaign_fields, true);
};

// OG defaults survive even when the slug is bad; social scrapers still
// get a usable card.
$og_title = 'Reach — Household of David Lekki Centre';
$og_desc  = 'Every soul counts. Meet people, log the encounter, watch heaven celebrate.';
$og_image = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm') . '/assets/images/hod_logo.svg';
$og_url   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm') . '/reach.php' . ($slug !== '' ? '?c=' . rawurlencode($slug) : '');

if ($campaign) {
    $og_title = htmlspecialchars((string)$campaign['title']) . ' — HOD Lekki';
    if (!empty($campaign['meta_description'])) {
        $og_desc = htmlspecialchars((string)$campaign['meta_description']);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $og_title ?></title>

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $og_title ?>">
    <meta property="og:description" content="<?= $og_desc ?>">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:url" content="<?= $og_url ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $og_title ?>">
    <meta name="twitter:description" content="<?= $og_desc ?>">
    <meta name="twitter:image" content="<?= $og_image ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1D356A', hodRed: '#D11920' },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Montserrat', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <style>
        body { background: linear-gradient(180deg, #F1FDF6 0%, #FFFFFF 40%); }
        .chip { transition: all 0.15s ease; }
        .chip.active { background:#059669; color:#fff; border-color:#059669; }
    </style>
</head>
<body class="font-sans text-gray-800 min-h-screen">

<?php if (!$campaign): ?>
    <!-- Slug bad / missing — show a friendly card and stop. -->
    <div class="max-w-md mx-auto px-4 py-16">
        <div class="bg-white rounded-3xl shadow-lg border border-gray-100 p-8 text-center">
            <div class="w-16 h-16 rounded-2xl bg-red-50 mx-auto mb-4 flex items-center justify-center text-hodRed">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-xl font-display font-bold text-gray-900">Campaign not found</h1>
            <p class="text-sm text-gray-500 mt-2">This capture link is invalid or has expired. Please contact your Reach leader for the right link.</p>
            <a href="/" class="inline-block mt-6 text-emerald-700 font-bold text-sm hover:text-emerald-900">Back to home</a>
        </div>
    </div>
<?php else: ?>

<div class="max-w-lg mx-auto px-4 py-6 sm:py-10 space-y-5">

    <div class="flex items-center gap-3">
        <img src="/assets/images/hod_logo.svg" alt="HOD" class="h-10" onerror="this.style.display='none'">
        <div>
            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Reach</p>
            <p class="text-xs text-gray-500 font-medium">Household of David Lekki Centre</p>
        </div>
    </div>

    <!-- Volunteer identity banner (populated by JS once we know who they are) -->
    <div id="volunteerBanner" class="hidden bg-emerald-50 border border-emerald-200 rounded-2xl px-4 py-3 flex justify-between items-center gap-3">
        <div class="min-w-0">
            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Signed in as</p>
            <p id="volunteerName" class="text-sm font-bold text-emerald-900 truncate">—</p>
        </div>
        <button onclick="resetVolunteer()" class="shrink-0 text-[11px] font-bold text-emerald-700 hover:text-emerald-900 underline">Not you? Switch</button>
    </div>

    <!-- Campaign header card -->
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 space-y-3">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest"><?= htmlspecialchars(str_replace('_', ' ', $campaign['campaign_type'])) ?></p>
                <h1 class="text-lg font-display font-bold text-gray-900 leading-tight"><?= htmlspecialchars($campaign['title']) ?></h1>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-[11px] font-bold text-gray-500">
            <?php if (!empty($campaign['campaign_date'])): ?>
                <span class="bg-gray-100 px-2.5 py-1 rounded-full"><?= htmlspecialchars(date('D, M j Y', strtotime($campaign['campaign_date']))) ?></span>
            <?php endif; ?>
            <?php if (!empty($campaign['location'])): ?>
                <span class="bg-gray-100 px-2.5 py-1 rounded-full truncate max-w-full"><?= htmlspecialchars($campaign['location']) ?></span>
            <?php endif; ?>
            <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-full">Tier: <?= htmlspecialchars($campaign['payload_tier']) ?></span>
        </div>
    </div>

    <!-- ===================== STEP 1 — Identity ===================== -->
    <div id="step1" class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 space-y-4">
        <div>
            <h2 class="font-display font-bold text-gray-900 text-lg">Step 1 — Who are you?</h2>
            <p class="text-xs text-gray-500 mt-1">Your phone number lets us tag every capture to your name.</p>
        </div>
        <div class="flex gap-2">
            <input type="tel" id="volPhoneInput" placeholder="e.g. 08033445566" class="flex-1 min-w-0 px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white">
            <button onclick="lookupVolunteer()" class="shrink-0 bg-emerald-600 hover:bg-emerald-800 text-white px-4 py-3 rounded-xl font-bold text-sm">Continue</button>
        </div>
        <div id="volMatchCard" class="hidden bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex justify-between items-center gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Match found</p>
                <p id="volMatchName" class="text-base font-display font-bold text-emerald-900 truncate">—</p>
            </div>
            <div class="flex gap-2 shrink-0">
                <button onclick="confirmVolunteer()" class="bg-emerald-600 hover:bg-emerald-800 text-white px-4 py-2 rounded-xl font-bold text-xs">Yes, that&#39;s me</button>
                <button onclick="rejectVolunteer()" class="bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl font-bold text-xs">Not me</button>
            </div>
        </div>
        <div id="volNoMatchCard" class="hidden bg-red-50 border border-red-200 rounded-2xl p-4 text-sm text-red-800">
            <p class="font-bold">Your number isn&#39;t in our family database yet.</p>
            <p class="mt-1 text-red-700">Please contact your leader to be added first — capture is disabled until then.</p>
        </div>
    </div>

    <!-- ===================== STEP 2 — Rapid entry ===================== -->
    <div id="step2" class="hidden bg-white rounded-3xl shadow-sm border border-gray-100 p-5 space-y-5 relative">

        <div class="flex justify-between items-start gap-3">
            <div>
                <h2 class="font-display font-bold text-gray-900 text-lg">Step 2 — Log a person</h2>
                <p class="text-xs text-gray-500 mt-1">Save clears the form and starts the next capture instantly.</p>
            </div>
            <button onclick="toggleVoice()" id="voiceBtn" class="shrink-0 w-11 h-11 rounded-full bg-emerald-100 hover:bg-emerald-200 text-emerald-700 flex items-center justify-center transition-all" title="Voice capture">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-14 0m7 7v3m0 0h-4m4 0h4m-4-7a3 3 0 01-3-3V6a3 3 0 116 0v5a3 3 0 01-3 3z"/>
                </svg>
            </button>
        </div>

        <form id="leadForm" class="space-y-4">
            <input type="hidden" name="action" value="submit_lead">
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <input type="hidden" name="volunteer_phone" id="volPhoneHidden" value="">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">First Name *</label>
                    <input type="text" name="first_name" id="fldFirstName" required class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Last Name</label>
                    <input type="text" name="last_name" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Phone *</label>
                <input type="tel" name="phone" id="fldPhone" onblur="checkDup()" placeholder="080..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white">
                <div id="dupWarning" class="hidden mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800"></div>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Category</label>
                <div id="categoryChips" class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <!-- populated by JS so we can toggle "active" state on tap -->
                </div>
                <input type="hidden" name="category" id="fldCategory" value="Other">
            </div>

            <label class="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-2xl px-4 py-4 cursor-pointer">
                <div>
                    <p class="font-bold text-red-700">Willing for a home visit?</p>
                    <p class="text-[11px] text-red-600 mt-0.5">Hot leads get a pastor visit within 7 days.</p>
                </div>
                <input type="checkbox" name="willing_for_visit" value="1" class="w-6 h-6 accent-red-600">
            </label>

            <?php if ($show_field('address')): ?>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Address / Area</label>
                    <input type="text" name="address" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                </div>
            <?php endif; ?>

            <?php if ($show_field('prayer_request')): ?>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Prayer Request</label>
                    <textarea name="prayer_request" rows="2" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white resize-none"></textarea>
                </div>
            <?php endif; ?>

            <?php if ($show_field('age_band') || $show_field('marital_status')): ?>
                <div class="grid grid-cols-2 gap-3">
                    <?php if ($show_field('age_band')): ?>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Age Band</label>
                            <select name="age_band" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                                <option value="">—</option>
                                <option>Under 18</option><option>18-25</option><option>26-35</option>
                                <option>36-50</option><option>51-65</option><option>65+</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($show_field('marital_status')): ?>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Marital Status</label>
                            <select name="marital_status" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                                <option value="">—</option>
                                <option>Single</option><option>Married</option><option>Widowed</option><option>Divorced</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_field('language') || $show_field('best_time_to_call')): ?>
                <div class="grid grid-cols-2 gap-3">
                    <?php if ($show_field('language')): ?>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Preferred Language</label>
                            <input type="text" name="language" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                        </div>
                    <?php endif; ?>
                    <?php if ($show_field('best_time_to_call')): ?>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Best Time to Call</label>
                            <input type="text" name="best_time_to_call" placeholder="e.g. Weekday evenings" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_field('notes')): ?>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white resize-none"></textarea>
                </div>
            <?php endif; ?>

            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-800 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save &amp; Continue
            </button>
        </form>

        <div class="pt-3 border-t border-gray-100">
            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Last 3 saves</p>
            <div id="recentChips" class="flex flex-wrap gap-2 min-h-[36px]">
                <span class="text-[11px] text-gray-400 italic">None yet</span>
            </div>
        </div>
    </div>

    <p class="text-center text-[11px] text-gray-400 pt-2 pb-8">&copy; Household of David Lekki Centre — Reach</p>
</div>

<!-- ===================== VOICE MODAL ===================== -->
<div id="voiceModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-300 p-6 space-y-4">
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-display font-bold text-gray-900">Voice Capture</h3>
            <button onclick="closeVoice()" class="text-gray-400 hover:text-red-500">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="text-xs text-gray-500">Say something like<br><em>"Chinedu Okafor, 08033445566, dechurched, wants a visit."</em></p>
        <div class="text-center py-4">
            <button id="voiceRecordBtn" onclick="toggleVoiceRecord()" class="w-20 h-20 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white flex items-center justify-center mx-auto shadow-lg transition-all">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-14 0m7 7v3m0 0h-4m4 0h4m-4-7a3 3 0 01-3-3V6a3 3 0 116 0v5a3 3 0 01-3 3z"/></svg>
            </button>
            <p id="voiceStatus" class="text-xs font-bold text-gray-500 mt-3 uppercase tracking-widest">Tap to speak</p>
        </div>
        <textarea id="voiceTranscript" rows="3" placeholder="Transcript will appear here..." class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium bg-gray-50 text-sm resize-none"></textarea>
        <div class="flex gap-2">
            <button onclick="closeVoice()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-3 rounded-xl font-bold text-sm">Cancel</button>
            <button onclick="applyVoice()" class="flex-1 bg-emerald-600 hover:bg-emerald-800 text-white px-4 py-3 rounded-xl font-bold text-sm">Prefill Form</button>
        </div>
    </div>
</div>

<script>
const PUBLIC_API = '/api/reach_public_api.php';
const CAMPAIGN_ID = <?= (int) $campaign['id'] ?>;
const LS_KEY = 'reach_volunteer_v1';
const CATEGORIES = [
    { v: 'New_Convert', label: 'New Convert' },
    { v: 'Unsaved',     label: 'Unsaved' },
    { v: 'Saved',       label: 'Saved' },
    { v: 'Broken',      label: 'Broken' },
    { v: 'Dechurched',  label: 'Dechurched' },
    { v: 'Other',       label: 'Other' }
];

let volunteer = null;   // { phone, first_name, last_name, user_id }
let recentSaves = [];   // [{name, category}]
let recognition = null;
let recognizing = false;
let personCount = 0;

function toast(msg, type = 'success') {
    Toastify({
        text: msg, gravity: 'top', position: 'center', duration: 2400,
        style: {
            background: type === 'success' ? '#10B981' : (type === 'warn' ? '#F59E0B' : '#EF4444'),
            borderRadius: '10px', fontWeight: 'bold',
            boxShadow: '0 10px 25px rgba(0,0,0,0.2)'
        }
    }).showToast();
}

function renderCategoryChips() {
    const html = CATEGORIES.map(c => `
        <button type="button" data-v="${c.v}" onclick="pickCategory('${c.v}')"
                class="chip min-h-[44px] px-3 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-700 hover:border-emerald-400">
            ${c.label}
        </button>`).join('');
    $('#categoryChips').html(html);
    pickCategory('Other');
}
function pickCategory(v) {
    $('#fldCategory').val(v);
    $('#categoryChips button').removeClass('active');
    $(`#categoryChips button[data-v="${v}"]`).addClass('active');
}

function saveVolunteerLocal(v) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(v)); } catch (e) {}
}
function loadVolunteerLocal() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; }
}

function activateVolunteer(v) {
    volunteer = v;
    $('#volPhoneHidden').val(v.phone);
    const full = [v.first_name, v.last_name].filter(Boolean).join(' ').trim() || v.phone;
    $('#volunteerName').text(full);
    $('#volunteerBanner').removeClass('hidden');
    $('#step1').addClass('hidden');
    $('#step2').removeClass('hidden');
    $('#fldFirstName').focus();
}

function resetVolunteer() {
    volunteer = null;
    try { localStorage.removeItem(LS_KEY); } catch (e) {}
    $('#volunteerBanner').addClass('hidden');
    $('#step2').addClass('hidden');
    $('#step1').removeClass('hidden');
    $('#volPhoneInput').val('').focus();
    $('#volMatchCard, #volNoMatchCard').addClass('hidden');
}

function lookupVolunteer() {
    const phone = $('#volPhoneInput').val().trim();
    if (!phone) { toast('Enter your phone first', 'warn'); return; }
    $('#volMatchCard, #volNoMatchCard').addClass('hidden');

    $.post(PUBLIC_API, { action: 'check_volunteer', phone: phone }, function(res) {
        if (res.exists) {
            const full = [res.first_name, res.last_name].filter(Boolean).join(' ').trim();
            $('#volMatchName').text(full || phone);
            $('#volMatchCard').removeClass('hidden').data('payload', {
                phone: phone,
                first_name: res.first_name || '',
                last_name: res.last_name || '',
                user_id: res.user_id || null
            });
        } else {
            $('#volNoMatchCard').removeClass('hidden');
        }
    }, 'json').fail(() => toast('Server error, try again', 'error'));
}

function confirmVolunteer() {
    const payload = $('#volMatchCard').data('payload');
    if (!payload) return;
    saveVolunteerLocal(payload);
    activateVolunteer(payload);
}
function rejectVolunteer() {
    $('#volMatchCard').addClass('hidden');
    $('#volPhoneInput').val('').focus();
}

function checkDup() {
    const phone = $('#fldPhone').val().trim();
    $('#dupWarning').addClass('hidden').text('');
    if (!phone || phone.replace(/\D/g, '').length < 6) return;

    $.post(PUBLIC_API, { action: 'check_existing_lead', phone: phone, campaign_id: CAMPAIGN_ID }, function(res) {
        const parts = [];
        if (res.is_existing_member && res.member_name) {
            parts.push(`<strong>${escapeHtml(res.member_name)}</strong> is already in our family database.`);
        }
        if (res.duplicate) {
            let line = 'Already captured in this campaign';
            if (res.prior_capturer_name) line += ` by <strong>${escapeHtml(res.prior_capturer_name)}</strong>`;
            if (res.prior_capture_date) line += ` on ${escapeHtml(res.prior_capture_date.substring(0, 10))}`;
            parts.push(line + '. Submitting will merge onto the existing lead.');
        }
        if (parts.length) {
            $('#dupWarning').html(parts.join('<br>')).removeClass('hidden');
        }
    }, 'json');
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function rememberRecent(name, category) {
    recentSaves.unshift({ name: name, category: category });
    recentSaves = recentSaves.slice(0, 3);
    if (!recentSaves.length) {
        $('#recentChips').html('<span class="text-[11px] text-gray-400 italic">None yet</span>');
        return;
    }
    $('#recentChips').html(recentSaves.map((r, i) => `
        <span class="inline-flex items-center gap-1 bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-1.5 rounded-full text-[11px] font-bold">
            ${escapeHtml(r.name)}
            <span class="text-[9px] text-emerald-600 uppercase tracking-widest">${escapeHtml(r.category.replace(/_/g, ' '))}</span>
        </span>`).join(''));
}

$('#leadForm').on('submit', function(e) {
    e.preventDefault();
    if (!volunteer) { toast('Please sign in first', 'error'); return; }

    const data = $(this).serialize() + '&volunteer_phone=' + encodeURIComponent(volunteer.phone);
    $.post(PUBLIC_API, data, function(res) {
        if (res.status === 'success') {
            personCount++;
            const first = $('#fldFirstName').val().trim() || 'Someone';
            const last  = $('#fldLastName') ? '' : ($('input[name="last_name"]').val() || '').trim();
            const full  = (first + ' ' + last).trim();
            rememberRecent(full, $('#fldCategory').val() || 'Other');
            toast('Person #' + personCount + ' saved — ' + (res.data.merged ? 'merged' : 'new lead'));

            document.getElementById('leadForm').reset();
            $('#volPhoneHidden').val(volunteer.phone);
            $('#dupWarning').addClass('hidden');
            pickCategory('Other');
            $('#fldFirstName').focus();
        } else {
            toast(res.message || 'Save failed', 'error');
        }
    }, 'json').fail(() => toast('Server error', 'error'));
});

/* ------------------------------- Voice ------------------------------- */
function setupSpeech() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return null;
    const r = new SR();
    r.lang = 'en-NG';
    r.interimResults = false;
    r.maxAlternatives = 1;
    r.onresult = (evt) => {
        const t = evt.results[0][0].transcript;
        $('#voiceTranscript').val((prev => (prev ? (prev + ' ' + t) : t))($('#voiceTranscript').val()));
    };
    r.onend = () => {
        recognizing = false;
        $('#voiceStatus').text('Tap to speak');
        $('#voiceRecordBtn').removeClass('bg-red-600 hover:bg-red-700').addClass('bg-emerald-600 hover:bg-emerald-700');
    };
    r.onerror = () => {
        recognizing = false;
        $('#voiceStatus').text('Mic error — try again');
    };
    return r;
}

function toggleVoice() {
    if (!volunteer) { toast('Please sign in first', 'warn'); return; }
    if (!recognition) recognition = setupSpeech();
    $('#voiceTranscript').val('');
    const modal = document.getElementById('voiceModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        modal.children[0].classList.remove('scale-95');
    });
}

function closeVoice() {
    if (recognizing && recognition) { try { recognition.stop(); } catch (e) {} }
    const modal = document.getElementById('voiceModal');
    modal.classList.add('opacity-0');
    modal.children[0].classList.add('scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }, 300);
}

function toggleVoiceRecord() {
    if (!recognition) {
        toast('Voice not supported on this browser', 'warn');
        return;
    }
    if (recognizing) {
        try { recognition.stop(); } catch (e) {}
        return;
    }
    try {
        recognition.start();
        recognizing = true;
        $('#voiceStatus').text('Listening...');
        $('#voiceRecordBtn').removeClass('bg-emerald-600 hover:bg-emerald-700').addClass('bg-red-600 hover:bg-red-700');
    } catch (e) {
        toast('Mic blocked', 'error');
    }
}

function applyVoice() {
    const transcript = ($('#voiceTranscript').val() || '').trim();
    if (!transcript) { toast('Say something first', 'warn'); return; }
    $('#voiceStatus').text('Thinking...');

    $.post(PUBLIC_API, { action: 'extract_from_voice', transcript: transcript }, function(res) {
        if (res.status !== 'success') {
            toast(res.message || 'Extraction failed', 'error');
            return;
        }
        const d = res.data;
        if (d.first_name) $('#fldFirstName').val(d.first_name);
        if (d.last_name)  $('input[name="last_name"]').val(d.last_name);
        if (d.phone)      $('#fldPhone').val(d.phone);
        if (d.category)   pickCategory(d.category);
        $('input[name="willing_for_visit"]').prop('checked', !!d.willing_for_visit);
        if (d.notes && $('textarea[name="notes"]').length) {
            $('textarea[name="notes"]').val(d.notes);
        }
        closeVoice();
        toast('Prefilled — review then Save');
        checkDup();
    }, 'json').fail(() => toast('Server error', 'error'));
}

$(document).ready(function() {
    renderCategoryChips();
    const cached = loadVolunteerLocal();
    if (cached && cached.phone) {
        // Trust the cache for the UI, but re-verify against the server so
        // we catch stale localStorage entries where the user no longer
        // exists in the family database.
        $.post(PUBLIC_API, { action: 'check_volunteer', phone: cached.phone }, function(res) {
            if (res.exists) {
                activateVolunteer({
                    phone: cached.phone,
                    first_name: res.first_name || cached.first_name || '',
                    last_name: res.last_name || cached.last_name || '',
                    user_id: res.user_id || cached.user_id || null
                });
            }
        }, 'json');
    }
});
</script>

<?php endif; ?>

</body>
</html>
