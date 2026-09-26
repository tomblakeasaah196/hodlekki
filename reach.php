<?php
// /reach.php
// Public capture entry point. Volunteers open /reach.php?c=<slug> in the
// field (usually shared via WhatsApp) and record leads with two taps.
// No auth guard — access is scoped by the campaign slug and the
// volunteer identity check in Step 1.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reach_helpers.php';

$slug = trim($_GET['c'] ?? '');
$campaign = null;
$campaign_fields = [];

if ($slug !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, slug, title, campaign_type, campaign_date, start_time, end_time,
                   location, meta_description, share_scripture, flyer_path, payload_tier, status
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

$e = fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// OG defaults survive even when the slug is bad; social scrapers still
// get a usable card. WhatsApp ignores SVG previews, hence the PNG.
$host     = $_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm';
$bg_image = reach_default_campaign_image($pdo);
$guide_html = $slug !== '' ? reach_markdown(reach_evangelism_guide($pdo)) : '';
$og_title = 'Reach — Household of David Lekki Centre';
$og_desc  = 'Every soul counts. Meet people, log the encounter, watch heaven celebrate.';
$og_image = 'https://' . $host . '/assets/images/logo_hod.png';
$og_url   = 'https://' . $host . '/reach.php' . ($slug !== '' ? '?c=' . rawurlencode($slug) : '');

$date_label = '';
$time_label = '';
if ($campaign) {
    $og_title = $campaign['title'] . ' — HOD Lekki';
    $bg_image = $campaign['flyer_path'] ?: $bg_image;
    $og_image = 'https://' . $host . $bg_image;
    if (!empty($campaign['meta_description'])) {
        $og_desc = $campaign['meta_description'];
    }
    if (!empty($campaign['campaign_date'])) {
        $date_label = date('D, j M Y', strtotime($campaign['campaign_date']));
    }
    if (!empty($campaign['start_time'])) {
        $time_label = date('g:i A', strtotime($campaign['start_time']));
        if (!empty($campaign['end_time'])) {
            $time_label .= ' – ' . date('g:i A', strtotime($campaign['end_time']));
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1D356A">
    <title><?= $e($og_title) ?></title>
    <link rel="icon" type="image/png" href="/assets/images/logo_hod.png">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $e($og_title) ?>">
    <meta property="og:description" content="<?= $e($og_desc) ?>">
    <meta property="og:image" content="<?= $e($og_image) ?>">
    <meta property="og:url" content="<?= $e($og_url) ?>">
    <meta name="twitter:card" content="<?= $campaign ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= $e($og_title) ?>">
    <meta name="twitter:description" content="<?= $e($og_desc) ?>">
    <meta name="twitter:image" content="<?= $e($og_image) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { hodBlue: '#1D356A', hodRed: '#D11920', hodInk: '#0A0E17' },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Montserrat', 'sans-serif']
                    },
                    animation: {
                        'fade-in': 'fadeIn 1s ease-out both',
                        'slide-up': 'slideUp 0.7s ease-out both',
                        'pulse-slow': 'pulse 6s cubic-bezier(0.4, 0, 0.6, 1) infinite'
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { opacity: '0', transform: 'translateY(24px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }
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
            .panel       { @apply bg-white/90 backdrop-blur-xl border border-white/60 shadow-2xl shadow-black/40 rounded-3xl; }
            .step-badge  { @apply shrink-0 w-8 h-8 rounded-full bg-hodBlue text-white font-display font-bold text-sm flex items-center justify-center shadow-md shadow-hodBlue/40; }
            .field-label { @apply block text-[11px] font-semibold text-gray-600 uppercase tracking-wider mb-1.5; }
            .field       { @apply w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-800 text-base shadow-sm outline-none transition-all placeholder:text-gray-400 hover:bg-white focus:bg-white focus:ring-2 focus:ring-hodBlue focus:border-transparent; }
            .btn-primary { @apply w-full bg-hodRed hover:bg-red-700 text-white font-semibold py-4 px-4 rounded-xl shadow-lg shadow-red-500/30 hover:shadow-red-500/50 hover:-translate-y-0.5 transition-all flex justify-center items-center gap-2 disabled:opacity-60 disabled:hover:translate-y-0; }
            .chip        { @apply min-h-[44px] px-3 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 transition-all hover:border-hodBlue/40; }
            .chip.active { @apply bg-hodBlue text-white border-hodBlue shadow-md shadow-hodBlue/30; }
            .meta-chip   { @apply inline-flex items-center gap-1.5 bg-white/10 border border-white/15 text-white/90 px-3 py-1.5 rounded-full text-xs font-medium; }
        }
    </style>
    <style>
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="bg-hodInk font-sans antialiased text-gray-800 overflow-x-hidden">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?= $e($bg_image) ?>" alt="" class="w-full h-full object-cover object-[center_30%] opacity-60">
        <div class="absolute inset-0 bg-hodBlue/50 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-hodBlue/60 to-hodInk/95"></div>
    </div>
    <div class="fixed -top-24 -left-24 w-80 h-80 bg-blue-500/30 rounded-full blur-[100px] animate-pulse-slow z-0" aria-hidden="true"></div>
    <div class="fixed -bottom-24 -right-24 w-80 h-80 bg-hodRed/25 rounded-full blur-[100px] animate-pulse-slow z-0" style="animation-delay: 3s;" aria-hidden="true"></div>

<?php if (!$campaign): ?>

    <main class="relative z-10 min-h-screen flex items-center justify-center px-4 py-10">
        <div class="glass rounded-3xl p-8 w-full max-w-sm text-center text-white animate-slide-up">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-white shadow-lg shadow-black/20 flex items-center justify-center p-2">
                <img src="/assets/images/hod_logo.svg" alt="Household of David" class="w-full h-full object-contain">
            </div>
            <div class="flex items-center justify-center gap-3 mt-6">
                <div class="h-px w-8 bg-hodRed"></div>
                <p class="text-[11px] font-semibold tracking-widest text-blue-200 uppercase">Reach</p>
                <div class="h-px w-8 bg-hodRed"></div>
            </div>
            <h1 class="font-display font-bold text-2xl mt-3 tracking-tight">This link isn&#39;t active</h1>
            <p class="text-sm text-blue-100/80 mt-3 leading-relaxed">We couldn&#39;t find this Reach campaign. Please ask your Reach leader for the current capture link.</p>
        </div>
    </main>

<?php else: ?>

    <main class="relative z-10 min-h-screen max-w-lg mx-auto px-4 pt-6 pb-10 sm:pt-10 space-y-4">

        <header class="flex items-center gap-3 animate-fade-in">
            <div class="shrink-0 w-12 h-12 rounded-2xl bg-white shadow-lg shadow-black/20 flex items-center justify-center p-1.5">
                <img src="/assets/images/hod_logo.svg" alt="Household of David" class="w-full h-full object-contain">
            </div>
            <div class="min-w-0">
                <span class="inline-block bg-hodRed text-white text-[10px] font-display font-bold tracking-[0.2em] uppercase px-2 py-0.5 rounded-md">Reach</span>
                <p class="text-sm font-medium text-white/90 truncate mt-1">Household of David · Lekki Centre</p>
            </div>
        </header>

        <section class="glass rounded-3xl p-6 text-white animate-slide-up">
            <?php if (!empty($campaign['flyer_path'])): ?>
                <img src="<?= $e($campaign['flyer_path']) ?>" alt="Campaign flyer" class="w-full max-h-96 object-cover object-top rounded-2xl mb-5 border border-white/20 shadow-lg">
            <?php endif; ?>
            <div class="flex items-center gap-3 mb-3">
                <div class="h-px w-8 bg-hodRed"></div>
                <p class="text-[11px] font-semibold tracking-widest text-blue-200 uppercase"><?= $e(str_replace('_', ' ', $campaign['campaign_type'])) ?></p>
            </div>
            <h1 class="font-display font-extrabold text-2xl sm:text-3xl leading-tight tracking-tight"><?= $e($campaign['title']) ?></h1>

            <?php if ($date_label || $time_label || !empty($campaign['location'])): ?>
                <div class="flex flex-wrap gap-2 mt-4">
                    <?php if ($date_label): ?>
                        <span class="meta-chip">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <?= $e($date_label) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($time_label): ?>
                        <span class="meta-chip">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= $e($time_label) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($campaign['location'])): ?>
                        <span class="meta-chip max-w-full">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="truncate"><?= $e($campaign['location']) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($campaign['share_scripture'])): ?>
                <blockquote class="mt-5 pt-5 border-t border-white/10 font-display italic text-white/85 text-sm leading-relaxed">
                    &ldquo;<?= $e($campaign['share_scripture']) ?>&rdquo;
                </blockquote>
            <?php endif; ?>
        </section>

        <div id="volunteerBanner" class="hidden glass rounded-2xl px-4 py-3 flex items-center justify-between gap-3 text-white">
            <div class="flex items-center gap-3 min-w-0">
                <div id="volunteerInitial" class="shrink-0 w-10 h-10 rounded-full bg-hodRed flex items-center justify-center font-display font-bold shadow-md shadow-red-500/30">?</div>
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-blue-200">Capturing as</p>
                    <p id="volunteerName" class="text-sm font-semibold truncate">—</p>
                </div>
            </div>
            <div class="flex items-center gap-4 shrink-0">
                <div class="text-right">
                    <p id="sessionCount" class="font-display font-extrabold text-2xl leading-none">0</p>
                    <p class="text-[10px] uppercase tracking-widest text-blue-200 mt-0.5">Saved</p>
                </div>
                <button type="button" onclick="resetVolunteer()" class="text-xs font-semibold text-white/80 hover:text-white underline underline-offset-4 decoration-white/30">Switch</button>
            </div>
        </div>

        <!-- ===================== STEP 1 — Identity ===================== -->
        <section id="step1" class="panel p-6 space-y-5 animate-slide-up" style="animation-delay: 0.1s;">
            <div class="flex items-start gap-3">
                <span class="step-badge">1</span>
                <div>
                    <h2 class="font-display font-bold text-gray-900 text-lg leading-tight">Who&#39;s capturing today?</h2>
                    <p class="text-sm text-gray-500 mt-1">Enter the phone number you&#39;re registered with, so every soul you log is credited to you.</p>
                </div>
            </div>
            <form id="volForm" class="flex gap-2">
                <label for="volPhoneInput" class="sr-only">Your phone number</label>
                <input type="tel" id="volPhoneInput" inputmode="tel" autocomplete="tel" placeholder="e.g. 0803 344 5566" class="field flex-1 min-w-0 font-semibold">
                <button type="submit" class="shrink-0 bg-hodBlue hover:bg-hodBlue/90 text-white px-5 rounded-xl font-semibold text-sm shadow-lg shadow-hodBlue/30 transition-all">Continue</button>
            </form>
            <div id="volMatchCard" class="hidden rounded-2xl border border-hodBlue/15 bg-hodBlue/5 p-4 space-y-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-hodBlue/70">Welcome back</p>
                    <p id="volMatchName" class="font-display font-bold text-hodBlue text-lg truncate">—</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="confirmVolunteer()" class="bg-hodRed hover:bg-red-700 text-white py-3 rounded-xl font-semibold text-sm shadow-md shadow-red-500/30 transition-all">Yes, that&#39;s me</button>
                    <button type="button" onclick="rejectVolunteer()" class="bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 py-3 rounded-xl font-semibold text-sm transition-all">Not me</button>
                </div>
            </div>
            <div id="volNoMatchCard" class="hidden rounded-2xl border border-hodRed/20 bg-hodRed/5 p-4 text-sm">
                <p class="font-semibold text-hodRed">We couldn&#39;t find that number.</p>
                <p class="text-gray-600 mt-1">Check it and try again, or ask your Reach leader to add you to the family database.</p>
            </div>
        </section>

        <!-- ===================== STEP 2 — Rapid entry ===================== -->
        <section id="step2" class="hidden panel p-6 space-y-6">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="step-badge">2</span>
                    <div>
                        <h2 class="font-display font-bold text-gray-900 text-lg leading-tight">Log a soul</h2>
                        <p class="text-sm text-gray-500 mt-1">Saving clears the form for the next person.</p>
                    </div>
                </div>
                <button type="button" onclick="toggleVoice()" aria-label="Fill the form by voice" title="Voice capture" class="shrink-0 w-12 h-12 rounded-2xl bg-hodBlue/10 hover:bg-hodBlue/15 text-hodBlue flex items-center justify-center transition-all">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 11a7 7 0 01-14 0m7 7v3m0 0h-4m4 0h4m-4-7a3 3 0 01-3-3V6a3 3 0 116 0v5a3 3 0 01-3 3z"/></svg>
                </button>
            </div>

            <form id="leadForm" class="space-y-5">
                <input type="hidden" name="action" value="submit_lead">
                <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                <input type="hidden" name="category" id="fldCategory" value="Other">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="fldFirstName" class="field-label">First name <span class="text-hodRed">*</span></label>
                        <input type="text" name="first_name" id="fldFirstName" required autocomplete="off" autocapitalize="words" class="field font-semibold">
                    </div>
                    <div>
                        <label for="fldLastName" class="field-label">Last name</label>
                        <input type="text" name="last_name" id="fldLastName" autocomplete="off" autocapitalize="words" class="field">
                    </div>
                </div>

                <div>
                    <label for="fldPhone" class="field-label">Phone</label>
                    <input type="tel" name="phone" id="fldPhone" inputmode="tel" autocomplete="off" onblur="checkDup()" placeholder="080..." class="field font-semibold">
                    <div id="dupWarning" class="hidden mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 leading-relaxed"></div>
                </div>

                <div>
                    <p class="field-label">Where are they spiritually? <span class="normal-case tracking-normal font-medium text-gray-400">· tap all that apply</span></p>
                    <div id="categoryChips" class="grid grid-cols-2 min-[400px]:grid-cols-3 gap-2"></div>
                </div>

                <label for="fldVisit" class="flex items-center justify-between gap-4 rounded-2xl border border-hodRed/20 bg-hodRed/5 px-4 py-4 cursor-pointer">
                    <span>
                        <span class="block font-semibold text-hodRed">Open to a home visit?</span>
                        <span class="block text-xs text-gray-600 mt-0.5">We go to them — someone from our church family will visit within 7 days.</span>
                    </span>
                    <span class="relative shrink-0">
                        <input type="checkbox" id="fldVisit" name="willing_for_visit" value="1" class="peer sr-only">
                        <span class="block w-12 h-7 rounded-full bg-gray-300 transition-colors peer-checked:bg-hodRed peer-focus-visible:ring-2 peer-focus-visible:ring-hodBlue peer-focus-visible:ring-offset-2"></span>
                        <span class="absolute top-1 left-1 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <label for="fldChurch" class="flex items-center justify-between gap-4 rounded-2xl border border-hodBlue/20 bg-hodBlue/5 px-4 py-4 cursor-pointer">
                    <span>
                        <span class="block font-semibold text-hodBlue">Will come to church?</span>
                        <span class="block text-xs text-gray-600 mt-0.5">They come to us — they said they'll visit Household of David on Sunday.</span>
                    </span>
                    <span class="relative shrink-0">
                        <input type="checkbox" id="fldChurch" name="will_attend_church" value="1" class="peer sr-only">
                        <span class="block w-12 h-7 rounded-full bg-gray-300 transition-colors peer-checked:bg-hodBlue peer-focus-visible:ring-2 peer-focus-visible:ring-hodBlue peer-focus-visible:ring-offset-2"></span>
                        <span class="absolute top-1 left-1 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <?php if ($campaign_fields): ?>
                    <div class="pt-5 border-t border-gray-200/70 space-y-4">
                        <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">More details · optional</p>

                        <?php if ($show_field('address')): ?>
                            <div>
                                <label for="fldAddress" class="field-label">Address / Area</label>
                                <input type="text" name="address" id="fldAddress" autocomplete="off" class="field">
                            </div>
                        <?php endif; ?>

                        <?php if ($show_field('prayer_request')): ?>
                            <div>
                                <label for="fldPrayer" class="field-label">Prayer request</label>
                                <textarea name="prayer_request" id="fldPrayer" rows="2" class="field resize-none"></textarea>
                            </div>
                        <?php endif; ?>

                        <?php if ($show_field('age_band') || $show_field('marital_status')): ?>
                            <div class="grid grid-cols-2 gap-3">
                                <?php if ($show_field('age_band')): ?>
                                    <div>
                                        <label for="fldAge" class="field-label">Age group</label>
                                        <select name="age_band" id="fldAge" class="field">
                                            <option value="">Prefer not to say</option>
                                            <option>Under 18</option><option>18-25</option><option>26-35</option>
                                            <option>36-50</option><option>51-65</option><option>65+</option>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_field('marital_status')): ?>
                                    <div>
                                        <label for="fldMarital" class="field-label">Marital status</label>
                                        <select name="marital_status" id="fldMarital" class="field">
                                            <option value="">Prefer not to say</option>
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
                                        <label for="fldLanguage" class="field-label">Language</label>
                                        <input type="text" name="language" id="fldLanguage" autocomplete="off" class="field">
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_field('best_time_to_call')): ?>
                                    <div>
                                        <label for="fldCallTime" class="field-label">Best time to call</label>
                                        <input type="text" name="best_time_to_call" id="fldCallTime" autocomplete="off" placeholder="Weekday evenings" class="field">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($show_field('notes')): ?>
                            <div>
                                <label for="fldNotes" class="field-label">Notes</label>
                                <textarea name="notes" id="fldNotes" rows="2" class="field resize-none"></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <button type="submit" id="saveBtn" class="btn-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Save &amp; next person</span>
                </button>
            </form>

            <div class="pt-5 border-t border-gray-200/70">
                <p class="field-label">Just saved</p>
                <div id="recentChips" class="flex flex-wrap gap-2 min-h-[36px]">
                    <span class="text-xs text-gray-400 italic">Nobody yet.</span>
                </div>
            </div>
        </section>

        <?php if ($guide_html !== ''): ?>
        <details class="panel p-6 group">
            <summary class="flex items-center justify-between gap-3 cursor-pointer list-none">
                <span>
                    <span class="block font-display font-bold text-gray-900 text-lg">Tips for sharing your faith</span>
                    <span class="block text-sm text-gray-500 mt-0.5">Prayer, the Gospel in brief, inviting people to church, scriptures and books.</span>
                </span>
                <span class="shrink-0 w-9 h-9 rounded-full bg-hodBlue/10 text-hodBlue flex items-center justify-center transition-transform group-open:rotate-180" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </span>
            </summary>
            <div class="mt-5 space-y-4 text-sm"><?= $guide_html ?></div>
        </details>
        <?php endif; ?>

        <p class="text-center text-xs text-white/50 pt-2">&copy; <?= date('Y') ?> Household of David · Reach</p>
    </main>

    <!-- ===================== VOICE MODAL ===================== -->
    <div id="voiceModal" role="dialog" aria-modal="true" aria-labelledby="voiceTitle" class="fixed inset-0 bg-hodInk/70 backdrop-blur-md hidden z-50 flex items-end sm:items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="panel w-full max-w-sm p-6 space-y-4 translate-y-4 transition-transform duration-300">
            <div class="flex justify-between items-center">
                <h3 id="voiceTitle" class="text-lg font-display font-bold text-gray-900">Voice capture</h3>
                <button type="button" onclick="closeVoice()" aria-label="Close" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:text-hodRed hover:bg-hodRed/5 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-500">Say something like<br><em class="text-gray-700">&ldquo;Chinedu Okafor, 0803 344 5566, dechurched, wants a visit.&rdquo;</em></p>
            <div class="text-center py-3">
                <button type="button" id="voiceRecordBtn" onclick="toggleVoiceRecord()" aria-label="Start or stop recording" class="w-20 h-20 rounded-full bg-hodBlue hover:bg-hodBlue/90 text-white flex items-center justify-center mx-auto shadow-xl shadow-hodBlue/40 transition-all">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-14 0m7 7v3m0 0h-4m4 0h4m-4-7a3 3 0 01-3-3V6a3 3 0 116 0v5a3 3 0 01-3 3z"/></svg>
                </button>
                <p id="voiceStatus" class="text-xs font-semibold text-gray-500 mt-3 uppercase tracking-widest">Tap to speak</p>
            </div>
            <label for="voiceTranscript" class="sr-only">Transcript</label>
            <textarea id="voiceTranscript" rows="3" placeholder="Transcript will appear here..." class="field text-sm resize-none"></textarea>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" onclick="closeVoice()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 py-3 rounded-xl font-semibold text-sm transition-all">Cancel</button>
                <button type="button" onclick="applyVoice()" class="bg-hodRed hover:bg-red-700 text-white py-3 rounded-xl font-semibold text-sm shadow-md shadow-red-500/30 transition-all">Prefill form</button>
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
const TOAST_BG = { success: '#1D356A', warn: '#B45309', error: '#D11920' };

let volunteer = null;   // { phone, name }
let recentSaves = [];   // [{name, category}]
let recognition = null;
let recognizing = false;
let personCount = 0;

function toast(msg, type = 'success') {
    Toastify({
        text: msg, gravity: 'top', position: 'center', duration: 2600,
        style: {
            background: TOAST_BG[type] || TOAST_BG.error,
            borderRadius: '14px', fontWeight: '600', fontFamily: 'Inter, sans-serif',
            boxShadow: '0 12px 30px rgba(10,14,23,0.35)'
        }
    }).showToast();
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

let selectedCats = new Set();

function renderCategoryChips() {
    $('#categoryChips').html(CATEGORIES.map(c =>
        `<button type="button" data-v="${c.v}" onclick="pickCategory('${c.v}')" aria-pressed="false" class="chip">${c.label}</button>`
    ).join(''));
    setCategories([]);
}
// Multi-select with the obvious contradictions removed: "Other" stands
// alone, and "Unsaved" can't sit with "Saved" or "New Convert".
function pickCategory(v) {
    if (selectedCats.has(v)) {
        selectedCats.delete(v);
    } else if (v === 'Other') {
        selectedCats = new Set(['Other']);
    } else {
        selectedCats.delete('Other');
        if (v === 'Unsaved') { selectedCats.delete('Saved'); selectedCats.delete('New_Convert'); }
        if (v === 'Saved' || v === 'New_Convert') selectedCats.delete('Unsaved');
        selectedCats.add(v);
    }
    setCategories([...selectedCats]);
}
function setCategories(list) {
    selectedCats = new Set(list);
    const vals = CATEGORIES.map(c => c.v).filter(v => selectedCats.has(v));
    $('#fldCategory').val(vals.join(',') || 'Other');
    $('#categoryChips button').each(function() {
        const on = selectedCats.has(this.dataset.v);
        $(this).toggleClass('active', on).attr('aria-pressed', on ? 'true' : 'false');
    });
}

function saveVolunteerLocal(v) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(v)); } catch (e) {}
}
function loadVolunteerLocal() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; }
}
function clearVolunteerLocal() {
    try { localStorage.removeItem(LS_KEY); } catch (e) {}
}

function activateVolunteer(v) {
    volunteer = v;
    const name = (v.name || '').trim();
    $('#volunteerName').text(name || v.phone);
    $('#volunteerInitial').text((name || '?').charAt(0).toUpperCase());
    $('#volunteerBanner').removeClass('hidden');
    $('#step1').addClass('hidden');
    $('#step2').removeClass('hidden');
    $('#fldFirstName').trigger('focus');
}

function resetVolunteer() {
    volunteer = null;
    clearVolunteerLocal();
    $('#volunteerBanner').addClass('hidden');
    $('#step2').addClass('hidden');
    $('#step1').removeClass('hidden');
    $('#volMatchCard, #volNoMatchCard').addClass('hidden');
    $('#volPhoneInput').val('').trigger('focus');
}

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

function checkDup() {
    const phone = $('#fldPhone').val().trim();
    $('#dupWarning').addClass('hidden').text('');
    if (!volunteer || phone.replace(/\D/g, '').length < 9) return;

    $.post(PUBLIC_API, {
        action: 'check_existing_lead', phone: phone,
        campaign_id: CAMPAIGN_ID, volunteer_phone: volunteer.phone
    }, function(res) {
        const parts = [];
        if (res.is_existing_member && res.member_name) {
            parts.push(`<strong>${escapeHtml(res.member_name)}</strong> is already part of our church family.`);
        }
        if (res.duplicate) {
            let line = 'Already captured in this campaign';
            if (res.prior_capturer_name) line += ` by <strong>${escapeHtml(res.prior_capturer_name)}</strong>`;
            if (res.prior_capture_date) line += ` on ${escapeHtml(res.prior_capture_date.substring(0, 10))}`;
            parts.push(line + '. Saving will merge onto the existing record.');
        }
        if (parts.length) {
            $('#dupWarning').html(parts.join('<br>')).removeClass('hidden');
        }
    }, 'json');
}

function rememberRecent(name, category) {
    recentSaves.unshift({ name: name, category: category });
    recentSaves = recentSaves.slice(0, 3);
    $('#recentChips').html(recentSaves.map(r => `
        <span class="inline-flex items-center gap-1.5 bg-hodBlue/5 border border-hodBlue/15 text-hodBlue px-3 py-1.5 rounded-full text-xs font-semibold">
            ${escapeHtml(r.name)}
            <span class="text-[9px] text-hodRed uppercase tracking-widest">${escapeHtml(r.category.replace(/_/g, ' ').replace(/,/g, ' · '))}</span>
        </span>`).join(''));
}

$('#volForm').on('submit', function(e) {
    e.preventDefault();
    lookupVolunteer();
});

$('#leadForm').on('submit', function(e) {
    e.preventDefault();
    if (!volunteer) { toast('Please sign in first', 'error'); return; }
    const form = this;
    const $btn = $('#saveBtn');
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);

    const name = ($('#fldFirstName').val().trim() + ' ' + $('#fldLastName').val().trim()).trim();
    const category = $('#fldCategory').val() || 'Other';
    const data = $(form).serialize() + '&volunteer_phone=' + encodeURIComponent(volunteer.phone);

    $.post(PUBLIC_API, data, function(res) {
        if (res.status === 'success') {
            personCount++;
            $('#sessionCount').text(personCount);
            rememberRecent(name || 'Someone', category);
            toast(res.data.merged ? 'Saved — merged with an earlier capture' : `Soul #${personCount} saved`);
            form.reset();
            $('#dupWarning').addClass('hidden');
            setCategories([]);
            $('#fldFirstName').trigger('focus');
        } else {
            toast(res.message || 'Could not save', 'error');
        }
    }, 'json')
        .fail(() => toast('Network problem — not saved. Try again.', 'error'))
        .always(() => $btn.prop('disabled', false));
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
        const prev = $('#voiceTranscript').val();
        $('#voiceTranscript').val(prev ? prev + ' ' + t : t);
    };
    r.onend = () => {
        recognizing = false;
        $('#voiceStatus').text('Tap to speak');
        $('#voiceRecordBtn').removeClass('bg-hodRed hover:bg-red-700 animate-pulse').addClass('bg-hodBlue hover:bg-hodBlue/90');
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
        modal.children[0].classList.remove('translate-y-4');
    });
}

function closeVoice() {
    if (recognizing && recognition) { try { recognition.stop(); } catch (e) {} }
    const modal = document.getElementById('voiceModal');
    modal.classList.add('opacity-0');
    modal.children[0].classList.add('translate-y-4');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }, 300);
}

function toggleVoiceRecord() {
    if (!recognition) {
        toast('Voice isn’t supported on this browser — type instead', 'warn');
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
        $('#voiceRecordBtn').removeClass('bg-hodBlue hover:bg-hodBlue/90').addClass('bg-hodRed hover:bg-red-700 animate-pulse');
    } catch (e) {
        toast('Microphone is blocked', 'error');
    }
}

function applyVoice() {
    const transcript = ($('#voiceTranscript').val() || '').trim();
    if (!transcript) { toast('Say something first', 'warn'); return; }
    $('#voiceStatus').text('Thinking...');

    $.post(PUBLIC_API, {
        action: 'extract_from_voice', transcript: transcript, volunteer_phone: volunteer.phone
    }, function(res) {
        if (res.status !== 'success') {
            $('#voiceStatus').text('Tap to speak');
            toast(res.message || 'Could not understand that', 'error');
            return;
        }
        const d = res.data;
        if (d.first_name) $('#fldFirstName').val(d.first_name);
        if (d.last_name)  $('#fldLastName').val(d.last_name);
        if (d.phone)      $('#fldPhone').val(d.phone);
        if (d.category && d.category !== 'Other') setCategories([d.category]);
        $('#fldVisit').prop('checked', !!d.willing_for_visit);
        if (d.will_attend_church) $('#fldChurch').prop('checked', true);
        if (d.notes && $('#fldNotes').length) $('#fldNotes').val(d.notes);
        closeVoice();
        toast('Prefilled — check it, then save');
        checkDup();
    }, 'json').fail(() => {
        $('#voiceStatus').text('Tap to speak');
        toast('Network problem — please try again', 'error');
    });
}

$(document).on('keydown', function(e) {
    if (e.key === 'Escape' && !$('#voiceModal').hasClass('hidden')) closeVoice();
});

$(document).ready(function() {
    renderCategoryChips();
    const cached = loadVolunteerLocal();
    if (cached && cached.phone) {
        // Re-verify so a stale cache (member removed, number changed)
        // falls back to Step 1 instead of failing on first save.
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

<?php endif; ?>

</body>
</html>
