<?php
// /modules/reach/index.php
require_once '../../includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href = '/auth/login.php';</script>";
    exit;
}

require_once __DIR__ . '/../../includes/reach_helpers.php';
$reach_can_assign = false;
try {
    $reach_can_assign = reach_is_manager($pdo, (int) $_SESSION['user_id'], $_SESSION['active_role'] ?? '');
} catch (PDOException $e) {
    error_log('Reach manager check: ' . $e->getMessage());
}

// Minimal Markdown for how_to_use.md: headings, bold, italic, code,
// links, lists, blockquotes, paragraphs. Text is escaped before any tag
// is added, and links only allow http(s), relative and #anchors.
function reach_markdown(string $md): string {
    $inline = function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/`([^`]+)`/', '<code class="px-1.5 py-0.5 rounded bg-gray-100 text-[0.85em] text-gray-800">$1</code>', $t);
        $t = preg_replace('/\*\*(.+?)\*\*/', '<strong class="font-bold text-gray-900">$1</strong>', $t);
        $t = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/', '<em>$1</em>', $t);
        return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $safe = preg_match('#^(https?://|/|\#)#', html_entity_decode($m[2]));
            return $safe ? '<a href="' . $m[2] . '" class="text-emerald-700 font-semibold underline underline-offset-2">' . $m[1] . '</a>' : $m[1];
        }, $t);
    };
    $html = ''; $para = []; $list = null; $items = []; $start = 1;
    $flushPara = function () use (&$html, &$para) {
        if ($para) { $html .= '<p class="text-gray-600 leading-relaxed">' . implode(' ', $para) . '</p>'; $para = []; }
    };
    $flushList = function () use (&$html, &$list, &$items, &$start) {
        if ($list) {
            $cls = $list === 'ol' ? 'list-decimal' : 'list-disc';
            $html .= "<{$list}" . ($list === 'ol' && $start > 1 ? " start=\"{$start}\"" : '') . " class=\"{$cls} pl-6 space-y-1.5 text-gray-600 leading-relaxed marker:text-emerald-600\">" . implode('', array_map(fn($i) => "<li>{$i}</li>", $items)) . "</{$list}>";
            $list = null; $items = [];
        }
    };
    foreach (preg_split('/\R/', $md) as $line) {
        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
            $flushPara(); $flushList();
            $n = strlen($m[1]);
            $cls = [1 => 'text-2xl font-display font-bold text-gray-900', 2 => 'text-lg font-display font-bold text-gray-900 pt-4 border-t border-gray-100 scroll-mt-24', 3 => 'text-base font-bold text-gray-900'][$n];
            $id = $n === 2 ? ' id="guide-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($m[2])), '-') . '"' : '';
            $html .= "<h{$n}{$id} class=\"{$cls}\">" . $inline($m[2]) . "</h{$n}>";
        } elseif (preg_match('/^\s*(?:([-*])|(\d+)\.)\s+(.+)$/', $line, $m)) {
            $flushPara();
            $type = $m[1] !== '' ? 'ul' : 'ol';
            if ($list !== $type) { $flushList(); $list = $type; $start = (int) ($m[2] ?: 1); }
            $items[] = $inline($m[3]);
        } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara(); $flushList();
            $html .= '<blockquote class="border-l-4 border-emerald-500 bg-emerald-50/60 rounded-r-xl px-4 py-3 text-emerald-900 text-sm">' . $inline($m[1]) . '</blockquote>';
        } elseif (trim($line) === '') {
            $flushPara(); $flushList();
        } else {
            $flushList();
            $para[] = $inline(trim($line));
        }
    }
    $flushPara(); $flushList();
    return $html;
}
$reach_guide_html = reach_markdown((string) @file_get_contents(__DIR__ . '/how_to_use.md'));
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/', $reach_guide_html, $reach_guide_toc, PREG_SET_ORDER);
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">

    <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-emerald-50/80 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-emerald-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div class="md:max-w-[75%] lg:max-w-md">
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Reach &amp; Evangelism</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-medium">Run outreach campaigns, share public capture links, and follow up leads.</p>
            </div>
        </div>

        <div class="relative z-10 flex gap-3 w-full md:w-auto">
            <button onclick="openCampaignModal()" class="flex-1 md:flex-none bg-emerald-600 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-xl font-bold transition-all shadow-lg shadow-emerald-900/20 flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New Campaign
            </button>
        </div>
    </div>

    <div role="tablist" aria-label="Reach sections" id="reachTabs" class="flex bg-gray-100 p-1.5 rounded-2xl w-full md:max-w-2xl animate-fade-in-up overflow-x-auto" style="animation-delay: 0.1s;">
        <button type="button" role="tab" id="tabBtn-campaigns" aria-controls="view-campaigns" aria-selected="true" tabindex="0" onclick="switchTab('campaigns')" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 bg-white text-emerald-700 shadow-sm">Campaigns</button>
        <button type="button" role="tab" id="tabBtn-followup" aria-controls="view-followup" aria-selected="false" tabindex="-1" onclick="switchTab('followup')" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 text-gray-500 hover:text-gray-900">Follow-Up</button>
        <button type="button" role="tab" id="tabBtn-analytics" aria-controls="view-analytics" aria-selected="false" tabindex="-1" onclick="switchTab('analytics')" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 text-gray-500 hover:text-gray-900">Analytics</button>
        <button type="button" role="tab" id="tabBtn-howto" aria-controls="view-howto" aria-selected="false" tabindex="-1" onclick="switchTab('howto')" class="flex-1 min-w-[140px] py-2.5 rounded-xl text-sm font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 text-gray-500 hover:text-gray-900">How to Use</button>
    </div>

    <div id="view-campaigns" role="tabpanel" aria-labelledby="tabBtn-campaigns" tabindex="0" class="focus:outline-none animate-fade-in-up" style="animation-delay: 0.2s;">
        <div id="campaignsGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 min-h-[240px]">
            <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="bg-white rounded-3xl border border-gray-100 p-6 space-y-4 animate-pulse" aria-hidden="true">
                    <div class="h-3 bg-gray-100 rounded w-1/3"></div><div class="h-5 bg-gray-100 rounded w-2/3"></div>
                    <div class="grid grid-cols-3 gap-2"><div class="h-10 bg-gray-100 rounded-xl"></div><div class="h-10 bg-gray-100 rounded-xl"></div><div class="h-10 bg-gray-100 rounded-xl"></div></div>
                    <div class="h-8 bg-gray-100 rounded-xl"></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div id="view-followup" role="tabpanel" aria-labelledby="tabBtn-followup" tabindex="0" class="focus:outline-none hidden animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">
        <button type="button" id="overdueWidget" onclick="toggleOverdue()" class="hidden w-full text-left bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center gap-4 hover:bg-red-100 transition-all">
            <span class="w-10 h-10 rounded-xl bg-red-600 text-white flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="min-w-0">
                <span class="block font-bold text-red-800"><span id="overdueCount">0</span> leads assigned &gt;5 days ago with no follow-up.</span>
                <span id="overdueHint" class="block text-xs text-red-600 mt-0.5">Tap to show only these.</span>
            </span>
        </button>

        <div id="fuSubTabs" class="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Follow-up status"></div>

        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <input id="fuSearch" type="search" placeholder="Search name or phone" aria-label="Search name or phone" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
                <select id="fuCampaign" aria-label="Campaign" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none bg-white"><option value="">All campaigns</option></select>
                <select id="fuAssignee" aria-label="Assignee" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none bg-white">
                    <option value="">Anyone</option><option value="unassigned">Unassigned</option><option value="me">Assigned to me</option>
                </select>
                <input id="fuArea" type="text" placeholder="Area, e.g. Ajah" aria-label="Area" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium focus:border-emerald-500 outline-none">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div id="fuCategoryChips" class="flex flex-wrap gap-2"></div>
                <label class="sm:ml-auto inline-flex items-center gap-2 text-xs font-bold text-gray-600 cursor-pointer select-none">
                    <input type="checkbox" id="fuWilling" class="w-4 h-4 accent-red-600"> Willing to visit only
                </label>
            </div>
        </div>

        <div id="fuList" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 min-h-[200px]"></div>
        <div class="text-center"><button type="button" id="fuMore" onclick="loadLeads(false)" class="hidden bg-white border border-gray-200 hover:border-emerald-300 text-gray-700 px-6 py-2.5 rounded-xl font-bold text-sm">Load more</button></div>
    </div>

    <div id="view-analytics" role="tabpanel" aria-labelledby="tabBtn-analytics" tabindex="0" class="focus:outline-none hidden animate-fade-in-up space-y-5" style="animation-delay: 0.2s;">
        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-4 flex flex-col lg:flex-row lg:items-center gap-3">
            <div id="anPresets" class="flex flex-wrap gap-2" role="group" aria-label="Date range"></div>
            <div id="anCustom" class="hidden flex items-center gap-2">
                <input type="date" id="anFrom" aria-label="From date" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:border-emerald-500 outline-none">
                <span class="text-gray-400 text-sm">to</span>
                <input type="date" id="anTo" aria-label="To date" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:border-emerald-500 outline-none">
            </div>
            <div class="flex gap-2 lg:ml-auto">
                <button type="button" onclick="exportCsv()" class="flex-1 lg:flex-none bg-white border border-gray-200 hover:border-emerald-300 text-gray-700 px-4 py-2.5 rounded-xl font-bold text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Export CSV</button>
                <button type="button" id="anPdfBtn" onclick="generatePdf()" class="flex-1 lg:flex-none bg-emerald-600 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-emerald-900/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Generate PDF</button>
            </div>
        </div>
        <div id="anBody"></div>
    </div>

    <div id="view-howto" role="tabpanel" aria-labelledby="tabBtn-howto" tabindex="0" class="focus:outline-none hidden animate-fade-in-up" style="animation-delay: 0.2s;">
        <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-5 items-start">
            <nav aria-label="Guide sections" class="hidden lg:block sticky top-24 bg-white rounded-3xl border border-gray-100 shadow-sm p-4 space-y-1">
                <?php foreach ($reach_guide_toc as $h): ?>
                    <a href="#<?= htmlspecialchars($h[1]) ?>" class="block px-3 py-2 rounded-xl text-sm font-semibold text-gray-600 hover:bg-emerald-50 hover:text-emerald-800"><?= strip_tags($h[2]) ?></a>
                <?php endforeach; ?>
            </nav>
            <article class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 md:p-10 space-y-4 max-w-3xl">
                <?= $reach_guide_html ?: '<p class="text-gray-500">The guide could not be loaded.</p>' ?>
            </article>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- CAMPAIGN CREATE / EDIT MODAL                                     -->
<!-- ================================================================ -->
<div id="campaignModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[92vh] overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white rounded-t-3xl shrink-0">
            <h3 id="campaignModalTitle" class="text-lg font-display font-bold text-gray-900">New Reach Campaign</h3>
            <button onclick="closeModal('campaignModal')" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-gray-50/40">
            <form id="campaignForm" class="space-y-5">
                <input type="hidden" name="action" id="campaignActionField" value="create_campaign">
                <input type="hidden" name="id" id="campaignIdField" value="">

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Campaign Title *</label>
                    <input type="text" name="title" id="fldTitle" required placeholder="e.g., Ikate Street Evangelism"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Event Type</label>
                        <select name="campaign_type" id="fldType" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white cursor-pointer">
                            <option value="Saturday_Evangelism">Saturday Evangelism</option>
                            <option value="Crusade">Crusade</option>
                            <option value="Welfare_Outreach">Welfare Outreach</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Date</label>
                        <input type="date" name="campaign_date" id="fldDate" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Start Time</label>
                        <input type="time" name="start_time" id="fldStart" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">End Time</label>
                        <input type="time" name="end_time" id="fldEnd" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Location</label>
                    <input type="text" name="location" id="fldLocation" placeholder="e.g., Orphanage Home, Surulere"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white">
                </div>

                <div class="bg-white p-4 rounded-2xl border border-gray-100">
                    <div class="flex justify-between items-center mb-3">
                        <label class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Payload Tier</label>
                        <button type="button" onclick="toggleTierHelp()" class="w-6 h-6 rounded-full bg-emerald-50 text-emerald-700 font-bold text-xs flex items-center justify-center hover:bg-emerald-100" title="Which fields are shown in each tier?">i</button>
                    </div>
                    <div id="tierHelp" class="hidden mb-3 text-xs bg-emerald-50/70 border border-emerald-100 rounded-xl p-3 text-emerald-800 space-y-1">
                        <p><strong>Rapid:</strong> first name, phone, category, willing-for-visit — 10-second capture.</p>
                        <p><strong>Standard:</strong> adds address and prayer request.</p>
                        <p><strong>Rich:</strong> adds age band, marital status, language, best time to call, notes.</p>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="payload_tier" value="Rapid" class="sr-only peer">
                            <div class="rounded-xl border border-gray-200 py-3 text-center text-xs font-bold text-gray-700 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 transition-all">Rapid</div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="payload_tier" value="Standard" class="sr-only peer">
                            <div class="rounded-xl border border-gray-200 py-3 text-center text-xs font-bold text-gray-700 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 transition-all">Standard</div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="payload_tier" value="Rich" checked class="sr-only peer">
                            <div class="rounded-xl border border-gray-200 py-3 text-center text-xs font-bold text-gray-700 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 transition-all">Rich</div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Meta Description
                        <span class="text-gray-400 font-medium normal-case tracking-normal">(shows in WhatsApp / link previews)</span>
                    </label>
                    <textarea name="meta_description" id="fldMeta" rows="2" maxlength="300"
                              class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white resize-none"
                              oninput="updateMetaCount()"></textarea>
                    <p class="text-[10px] font-bold text-gray-400 mt-1"><span id="metaCount">0</span> / 160 chars recommended</p>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Share Scripture</label>
                    <select id="scriptureSelect" onchange="onScriptureSelect()" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white cursor-pointer">
                        <option value="">— Pick a scripture —</option>
                        <option>John 3:16 — For God so loved the world that he gave his one and only Son...</option>
                        <option>Romans 10:9 — If you declare with your mouth "Jesus is Lord"...</option>
                        <option>Acts 16:31 — Believe in the Lord Jesus, and you will be saved — you and your household.</option>
                        <option>2 Corinthians 5:17 — Therefore, if anyone is in Christ, the new creation has come.</option>
                        <option>Revelation 3:20 — Here I am! I stand at the door and knock...</option>
                        <option>Isaiah 1:18 — Though your sins are like scarlet, they shall be as white as snow.</option>
                        <option>Matthew 11:28 — Come to me, all you who are weary and burdened, and I will give you rest.</option>
                        <option>Ephesians 2:8-9 — For it is by grace you have been saved, through faith.</option>
                        <option value="__custom__">Custom scripture...</option>
                    </select>
                    <textarea name="share_scripture" id="fldScripture" rows="2" placeholder="Custom scripture text"
                              class="w-full mt-2 px-4 py-3 border border-gray-200 rounded-xl font-medium focus:border-emerald-500 outline-none bg-white resize-none"></textarea>
                </div>

                <div id="statusRow" class="hidden">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Status</label>
                    <select name="status" id="fldStatus" class="w-full px-4 py-3 border border-gray-200 rounded-xl font-bold focus:border-emerald-500 outline-none bg-white cursor-pointer">
                        <option value="Active">Active</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="p-6 border-t border-gray-100 bg-white shrink-0 flex gap-3">
            <button type="button" onclick="closeModal('campaignModal')" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-bold transition-all">Cancel</button>
            <button type="submit" form="campaignForm" class="flex-1 bg-emerald-600 hover:bg-emerald-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Save Campaign
            </button>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- SHARE MODAL                                                       -->
<!-- ================================================================ -->
<div id="shareModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-300 border border-gray-100 flex flex-col max-h-[92vh] overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-white rounded-t-3xl shrink-0">
            <h3 class="text-lg font-display font-bold text-gray-900">Share Public Link</h3>
            <button onclick="closeModal('shareModal')" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar bg-white space-y-5">
            <div>
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Campaign</p>
                <p id="shareTitle" class="text-lg font-display font-bold text-gray-900">—</p>
            </div>

            <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100 flex flex-col items-center gap-3">
                <img id="shareQr" src="" alt="QR" class="w-40 h-40 rounded-xl bg-white p-2 border border-gray-100">
                <div class="w-full flex items-center gap-2">
                    <input type="text" id="shareLink" readonly class="flex-1 min-w-0 px-3 py-2 border border-gray-200 rounded-lg font-mono text-xs bg-white">
                    <button onclick="copyShareLink()" class="shrink-0 bg-gray-900 hover:bg-black text-white px-3 py-2 rounded-lg font-bold text-xs">Copy</button>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">WhatsApp Preview</label>
                <textarea id="shareBlurb" rows="4" readonly class="w-full px-4 py-3 border border-gray-200 rounded-xl font-medium bg-gray-50 resize-none text-sm"></textarea>
            </div>

            <a id="shareWhatsapp" href="#" target="_blank" rel="noopener" class="w-full bg-[#25D366] hover:bg-[#128C7E] text-white px-6 py-3 rounded-xl font-bold transition-all flex justify-center items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.626.712.226 1.36.194 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12.01 2C6.487 2 2 6.487 2 12.01c0 1.76.458 3.483 1.328 5.001L2 22l5.116-1.325a10.02 10.02 0 004.895 1.256h.005c5.522 0 10.008-4.486 10.008-10.008 0-2.674-1.041-5.185-2.932-7.076A9.943 9.943 0 0012.01 2z"/></svg>
                Send via WhatsApp
            </a>
        </div>
    </div>
</div>

<div id="leadDrawer" class="fixed inset-0 z-[9998] hidden" role="dialog" aria-modal="true" aria-labelledby="drawerName">
    <div data-drawer-backdrop onclick="closeDrawer()" class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm opacity-0 transition-opacity duration-300"></div>
    <aside data-drawer-panel class="absolute bg-white shadow-2xl flex flex-col transition-transform duration-300 inset-x-0 bottom-0 max-h-[92vh] rounded-t-3xl translate-y-full md:inset-y-0 md:left-auto md:right-0 md:w-[500px] md:max-h-none md:rounded-none md:rounded-l-3xl md:translate-y-0 md:translate-x-full">
        <div class="flex items-start justify-between gap-3 p-6 border-b border-gray-100">
            <div class="min-w-0">
                <h3 id="drawerName" class="text-xl font-display font-bold text-gray-900 truncate">—</h3>
                <p id="drawerSub" class="text-sm text-gray-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="closeDrawer()" aria-label="Close" class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="drawerBody" class="flex-1 overflow-y-auto p-6 space-y-6"></div>
    </aside>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[10000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    const API_URL = '/api/reach_api.php';

    function switchTab(tabId) {
        $('#view-campaigns, #view-followup, #view-analytics, #view-howto').addClass('hidden');
        $('#reachTabs [role="tab"]')
            .removeClass('bg-white text-emerald-700 shadow-sm')
            .addClass('text-gray-500 hover:text-gray-900')
            .attr({ 'aria-selected': 'false', tabindex: '-1' });
        $(`#view-${tabId}`).removeClass('hidden');
        $(`#tabBtn-${tabId}`)
            .removeClass('text-gray-500 hover:text-gray-900')
            .addClass('bg-white text-emerald-700 shadow-sm')
            .attr({ 'aria-selected': 'true', tabindex: '0' });
        document.getElementById(`tabBtn-${tabId}`).scrollIntoView({ block: 'nearest', inline: 'nearest' });
        if (tabId === 'followup') initFollowUp();
        if (tabId === 'analytics') initAnalytics();
    }

    $(document).on('keydown', '#reachTabs [role="tab"]', function(e) {
        const tabs = $('#reachTabs [role="tab"]');
        const i = tabs.index(this);
        const next = { ArrowRight: (i + 1) % tabs.length, ArrowLeft: (i - 1 + tabs.length) % tabs.length, Home: 0, End: tabs.length - 1 }[e.key];
        if (next === undefined) return;
        e.preventDefault();
        tabs.eq(next).trigger('focus').trigger('click');
    });

    function lockScreenAction() {
        const b = document.getElementById('globalActionBlocker');
        b.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => b.classList.remove('opacity-0'), 10);
    }
    function unlockScreenAction() {
        const b = document.getElementById('globalActionBlocker');
        b.classList.add('opacity-0');
        setTimeout(() => {
            b.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
        }, 300);
    }
    function openModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        document.body.appendChild(m);
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            m.children[0].classList.remove('scale-95');
        });
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('opacity-0');
        m.children[0].classList.add('scale-95');
        setTimeout(() => {
            m.classList.add('hidden');
            if (document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
        }, 300);
    }
    function showToast(msg, type = 'success') {
        Toastify({
            text: msg, gravity: "top", position: "center", duration: 3000,
            style: {
                background: type === 'success' ? "#10B981" : "#EF4444",
                borderRadius: "10px", fontWeight: "bold",
                boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
            }
        }).showToast();
    }

    function toggleTierHelp() {
        $('#tierHelp').toggleClass('hidden');
    }
    function updateMetaCount() {
        const val = $('#fldMeta').val() || '';
        $('#metaCount').text(val.length);
    }
    function onScriptureSelect() {
        const val = $('#scriptureSelect').val();
        if (val === '__custom__') {
            $('#fldScripture').val('').focus();
        } else if (val) {
            $('#fldScripture').val(val);
        }
    }

    function openCampaignModal() {
        $('#campaignModalTitle').text('New Reach Campaign');
        $('#campaignActionField').val('create_campaign');
        $('#campaignIdField').val('');
        $('#campaignForm')[0].reset();
        $('input[name="payload_tier"][value="Rich"]').prop('checked', true);
        $('#statusRow').addClass('hidden');
        $('#fldScripture').val('');
        $('#scriptureSelect').val('');
        updateMetaCount();
        openModal('campaignModal');
    }

    function openEditCampaignModal(c) {
        $('#campaignModalTitle').text('Edit Campaign');
        $('#campaignActionField').val('edit_campaign');
        $('#campaignIdField').val(c.id);
        $('#fldTitle').val(c.title || '');
        $('#fldType').val(c.campaign_type || 'Saturday_Evangelism');
        $('#fldDate').val(c.campaign_date || '');
        $('#fldStart').val(c.start_time || '');
        $('#fldEnd').val(c.end_time || '');
        $('#fldLocation').val(c.location || '');
        $('#fldMeta').val(c.meta_description || '');
        $('#fldScripture').val(c.share_scripture || '');
        $('#scriptureSelect').val('');
        $(`input[name="payload_tier"][value="${c.payload_tier || 'Rich'}"]`).prop('checked', true);
        $('#statusRow').removeClass('hidden');
        $('#fldStatus').val(c.status || 'Active');
        updateMetaCount();
        openModal('campaignModal');
    }

    function statusPill(status) {
        const map = {
            'Active':    ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Active'],
            'Completed': ['bg-gray-100 text-gray-700 border-gray-200', 'Completed'],
            'Cancelled': ['bg-red-50 text-red-600 border-red-200', 'Cancelled']
        };
        const [cls, label] = map[status] || map['Active'];
        return `<span class="px-2.5 py-1 rounded-full border text-[10px] font-bold uppercase tracking-wider ${cls}">${label}</span>`;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderCampaigns(list) {
        if (!list.length) {
            $('#campaignsGrid').html(`
                <div class="col-span-full bg-white rounded-3xl border border-dashed border-gray-200 p-10 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 mx-auto mb-3 flex items-center justify-center"><svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    <h3 class="text-lg font-bold text-gray-900">No campaigns yet</h3>
                    <p class="text-sm text-gray-500 mt-1">Schedule your first outreach and share its capture link.</p>
                    <button type="button" onclick="openCampaignModal()" class="mt-5 bg-emerald-600 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm">New Campaign</button>
                </div>`);
            return;
        }
        const html = list.map(c => `
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 flex flex-col gap-4 hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">${escapeHtml((c.campaign_type||'').replace(/_/g,' '))}</p>
                        <h4 class="font-display text-lg font-bold text-gray-900 truncate">${escapeHtml(c.title)}</h4>
                        <p class="text-xs text-gray-500 font-medium mt-0.5 truncate">/reach.php?c=${escapeHtml(c.slug)}</p>
                    </div>
                    ${statusPill(c.status)}
                </div>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-gray-50 rounded-xl py-2">
                        <p class="text-[9px] font-bold text-gray-400 uppercase">Date</p>
                        <p class="text-xs font-bold text-gray-800 mt-0.5">${escapeHtml(c.nice_date || '—')}</p>
                    </div>
                    <div class="bg-emerald-50 rounded-xl py-2">
                        <p class="text-[9px] font-bold text-emerald-500 uppercase">Souls</p>
                        <p class="text-sm font-black text-emerald-800 mt-0.5">${escapeHtml(c.souls_count)}</p>
                    </div>
                    <div class="bg-blue-50 rounded-xl py-2">
                        <p class="text-[9px] font-bold text-blue-500 uppercase">Tier</p>
                        <p class="text-xs font-bold text-blue-800 mt-0.5">${escapeHtml(c.payload_tier)}</p>
                    </div>
                </div>
                <div class="flex gap-2 pt-1">
                    <button onclick='openShareModal(${c.id})' class="flex-1 bg-emerald-600 hover:bg-emerald-800 text-white px-3 py-2 rounded-xl font-bold text-xs transition-all flex justify-center items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12s-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                        Share
                    </button>
                    <button onclick='openEditCampaignModal(${JSON.stringify(c).replace(/'/g, "&#39;")})' class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-xl font-bold text-xs transition-all">Edit</button>
                    <button onclick="deleteCampaign(${c.id}, '${escapeHtml((c.title||'').replace(/'/g,'\\\'')) }')" class="bg-red-50 hover:bg-red-100 text-red-600 px-3 py-2 rounded-xl font-bold text-xs transition-all">Delete</button>
                </div>
            </div>`).join('');
        $('#campaignsGrid').html(html);
    }

    function loadCampaigns() {
        $.getJSON(API_URL, { action: 'fetch_campaigns' }, function(res) {
            if (res.status === 'success') {
                renderCampaigns(res.data || []);
            } else {
                showToast(res.message || 'Failed to load campaigns', 'error');
            }
        }).fail(() => showToast('Server error while loading campaigns', 'error'));
    }

    function deleteCampaign(id, title) {
        if (!confirm(`Delete campaign "${title}"?\n\nIf any souls were captured, it will be marked Cancelled instead.`)) return;
        lockScreenAction();
        $.post(API_URL, { action: 'delete_campaign', id: id }, function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if (res.status === 'success') loadCampaigns();
        }, 'json').fail(() => { unlockScreenAction(); showToast('Server error', 'error'); });
    }

    function openShareModal(id) {
        lockScreenAction();
        $.getJSON(API_URL, { action: 'fetch_campaign_share_bundle', id: id }, function(res) {
            unlockScreenAction();
            if (res.status !== 'success') {
                showToast(res.message || 'Could not load share bundle', 'error');
                return;
            }
            const d = res.data;
            $('#shareTitle').text(d.title);
            $('#shareLink').val(d.public_url);
            $('#shareBlurb').val(d.whatsapp_text);
            $('#shareWhatsapp').attr('href', d.whatsapp_link);
            $('#shareQr').attr('src', d.qr_data_url);
            openModal('shareModal');
        }).fail(() => { unlockScreenAction(); showToast('Server error', 'error'); });
    }

    function copyShareLink() {
        const el = document.getElementById('shareLink');
        el.select();
        el.setSelectionRange(0, 99999);
        try {
            navigator.clipboard.writeText(el.value);
            showToast('Link copied');
        } catch (e) {
            document.execCommand('copy');
            showToast('Link copied');
        }
    }

    $('#campaignForm').on('submit', function(e) {
        e.preventDefault();
        lockScreenAction();
        $.post(API_URL, $(this).serialize(), function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if (res.status === 'success') {
                closeModal('campaignModal');
                loadCampaigns();
            }
        }, 'json').fail(() => { unlockScreenAction(); showToast('Server error', 'error'); });
    });

    /* ============================ FOLLOW-UP TAB ============================ */
    const REACH_CAN_ASSIGN = <?= $reach_can_assign ? 'true' : 'false' ?>;
    const FU_TABS = [['not_spoken', 'Not Spoken To'], ['spoken', 'Spoken To'], ['cold', 'Cold'], ['converted', 'Converted'], ['all', 'All']];
    const FU_CATEGORIES = ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'];
    const CATEGORY_STYLE = {
        New_Convert: 'bg-emerald-100 text-emerald-800', Unsaved: 'bg-red-100 text-red-700', Saved: 'bg-blue-100 text-blue-800',
        Broken: 'bg-purple-100 text-purple-800', Dechurched: 'bg-amber-100 text-amber-800', Other: 'bg-gray-100 text-gray-700'
    };
    const OUTCOME_STYLE = {
        Reached: 'bg-emerald-100 text-emerald-800', No_Answer: 'bg-gray-100 text-gray-700', Wrong_Number: 'bg-amber-100 text-amber-800',
        Rescheduled: 'bg-blue-100 text-blue-800', Requested_No_Contact: 'bg-red-100 text-red-700', Declined: 'bg-red-100 text-red-700'
    };
    const CHANNEL_ICON = {
        Call: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
        WhatsApp: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        SMS: 'M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z',
        In_Person_Visit: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        Church_Service: 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z'
    };
    const fu = { ready: false, sub: 'not_spoken', category: '', overdue: false, page: 1, members: [], leadId: null };

    const label = s => String(s || '').replace(/_/g, ' ');
    const pill = (text, cls) => `<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold ${cls}">${escapeHtml(text)}</span>`;
    const icon = (d, cls = 'w-4 h-4') => `<svg class="${cls}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${d}"/></svg>`;

    function parseDate(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
    function relTime(s) {
        const d = parseDate(s);
        if (!d || isNaN(d)) return '';
        const mins = Math.round((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins}m ago`;
        if (mins < 1440) return `${Math.round(mins / 60)}h ago`;
        if (mins < 10080) return `${Math.round(mins / 1440)}d ago`;
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function initFollowUp() {
        if (fu.ready) return;
        fu.ready = true;
        $('#fuCategoryChips').html(['', ...FU_CATEGORIES].map(c =>
            `<button type="button" data-cat="${c}" onclick="pickFuCategory('${c}')" class="fu-cat px-3 py-1.5 rounded-full border text-xs font-bold transition-all">${c ? label(c) : 'All categories'}</button>`
        ).join(''));
        pickFuCategory('', false);
        $.post(API_URL, { action: 'fetch_campaigns' }, res => {
            if (res.status !== 'success') return;
            $('#fuCampaign').append(res.data.map(c => `<option value="${c.id}">${escapeHtml(c.title)}</option>`).join(''));
        }, 'json');
        $.post(API_URL, { action: 'list_reach_members' }, res => {
            if (res.status !== 'success') return;
            fu.members = res.data;
            $('#fuAssignee').append(res.data.map(m => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join(''));
        }, 'json');
        let t;
        $('#fuSearch, #fuArea').on('input', () => { clearTimeout(t); t = setTimeout(() => loadLeads(), 350); });
        $('#fuCampaign, #fuAssignee, #fuWilling').on('change', () => loadLeads());
        loadLeads();
    }

    function pickFuCategory(c, reload = true) {
        fu.category = c;
        $('.fu-cat').removeClass('bg-emerald-600 text-white border-emerald-600').addClass('bg-white text-gray-600 border-gray-200').attr('aria-pressed', 'false');
        $(`.fu-cat[data-cat="${c}"]`).removeClass('bg-white text-gray-600 border-gray-200').addClass('bg-emerald-600 text-white border-emerald-600').attr('aria-pressed', 'true');
        if (reload) loadLeads();
    }
    function pickSubTab(s) { fu.sub = s; loadLeads(); }
    function toggleOverdue() { fu.overdue = !fu.overdue; loadLeads(); }

    function renderSubTabs(counts) {
        $('#fuSubTabs').html(FU_TABS.map(([k, name]) => {
            const on = fu.sub === k;
            return `<button type="button" role="tab" aria-selected="${on}" onclick="pickSubTab('${k}')" class="shrink-0 px-4 py-2 rounded-xl text-sm font-bold transition-all ${on ? 'bg-emerald-600 text-white shadow-md shadow-emerald-900/20' : 'bg-white text-gray-600 border border-gray-200 hover:border-emerald-300'}">${name} <span class="ml-1 ${on ? 'text-emerald-100' : 'text-gray-400'}">${counts[k] ?? 0}</span></button>`;
        }).join(''));
    }

    function fuSkeleton() {
        return Array.from({ length: 3 }, () => `<div class="bg-white rounded-3xl border border-gray-100 p-5 space-y-3 animate-pulse"><div class="h-4 bg-gray-100 rounded w-2/3"></div><div class="h-3 bg-gray-100 rounded w-full"></div><div class="h-3 bg-gray-100 rounded w-1/2"></div></div>`).join('');
    }

    function loadLeads(reset = true) {
        fu.page = reset ? 1 : fu.page + 1;
        if (reset) $('#fuList').html(fuSkeleton());
        $.post(API_URL, {
            action: 'list_leads', sub_tab: fu.sub, page: fu.page, category: fu.category,
            campaign_id: $('#fuCampaign').val(), assignee: $('#fuAssignee').val(),
            area: $('#fuArea').val().trim(), search: $('#fuSearch').val().trim(),
            willing_only: $('#fuWilling').is(':checked') ? 1 : 0, overdue_only: fu.overdue ? 1 : 0
        }, res => {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            const d = res.data;
            renderSubTabs(d.counts);
            $('#overdueCount').text(d.overdue);
            $('#overdueHint').text(fu.overdue ? 'Showing only overdue leads — tap to show all.' : 'Tap to show only these.');
            $('#overdueWidget').toggleClass('hidden', d.overdue === 0 && !fu.overdue).toggleClass('ring-2 ring-red-400', fu.overdue);
            const html = d.leads.map(leadCard).join('');
            if (reset) {
                $('#fuList').html(html || `<div class="col-span-full bg-white rounded-3xl border border-gray-100 p-10 text-center"><div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 mx-auto mb-3 flex items-center justify-center">${icon('M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'w-7 h-7')}</div><p class="font-bold text-gray-900">No leads here</p><p class="text-sm text-gray-500 mt-1">Try another tab or clear the filters.</p></div>`);
            } else {
                $('#fuList').append(html);
            }
            $('#fuMore').toggleClass('hidden', !d.has_more);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function leadCard(l) {
        const name = `${l.first_name} ${l.last_name || ''}`.trim();
        const member = !!l.capturer_user_id;
        const added = `Added by ${l.capturer_name || 'unknown'} · ${l.campaign_title || 'No campaign'} · ${relTime(l.captured_at || l.created_at)}`;
        const last = l.last_outcome ? `Last: ${label(l.last_outcome)} · ${l.last_follower_name || '—'} · ${relTime(l.last_follow_up_date)}` : '';
        const assign = l.assigned_to
            ? `<span class="inline-flex items-center gap-1.5 text-xs font-bold text-gray-700 bg-gray-100 px-3 py-1.5 rounded-full">${icon('M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'w-3.5 h-3.5')}${escapeHtml(l.assignee_name)}</span>`
            : `<span class="text-xs font-bold text-gray-400">Unassigned</span>${l.pushed_to_embrace_at ? '' : `<button type="button" onclick="event.stopPropagation(); claimLead(${l.id})" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-full text-xs font-bold">Claim</button>`}`;
        return `<div role="button" tabindex="0" onclick="openLead(${l.id})" onkeydown="if(event.key==='Enter')openLead(${l.id})" class="relative overflow-hidden bg-white rounded-3xl border border-gray-100 shadow-sm p-5 space-y-3 cursor-pointer hover:shadow-md hover:border-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
            ${l.willing_for_visit == 1 ? '<span class="absolute top-0 right-0 bg-red-600 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-bl-xl">Willing to visit</span>' : ''}
            <div class="flex items-start justify-between gap-2 pr-16">
                <div class="min-w-0"><p class="font-bold text-gray-900 truncate">${escapeHtml(name)}</p><p class="text-sm text-gray-500">${escapeHtml(l.phone || 'No phone')}</p></div>
            </div>
            <div class="flex flex-wrap gap-1.5">${pill(label(l.category), CATEGORY_STYLE[l.category] || CATEGORY_STYLE.Other)}</div>
            <p class="text-[11px] font-semibold px-3 py-1.5 rounded-xl border ${member ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-yellow-50 text-yellow-800 border-yellow-200'}">${escapeHtml(added)}</p>
            ${last ? `<p class="text-[11px] font-semibold px-3 py-1.5 rounded-xl bg-gray-50 text-gray-600 border border-gray-200">${escapeHtml(last)}</p>` : ''}
            <div class="flex items-center justify-between gap-2 pt-1">${assign}</div>
        </div>`;
    }

    function claimLead(id) {
        $.post(API_URL, { action: 'self_claim', lead_id: id }, res => {
            showToast(res.message, res.status);
            if (res.status === 'success') { loadLeads(); if (fu.leadId === id) openLead(id); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function openLead(id) {
        fu.leadId = id;
        const dr = document.getElementById('leadDrawer');
        document.body.appendChild(dr);
        dr.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        $('#drawerName').text('Loading…'); $('#drawerSub').text('');
        $('#drawerBody').html(fuSkeleton());
        requestAnimationFrame(() => {
            dr.querySelector('[data-drawer-backdrop]').classList.remove('opacity-0');
            dr.querySelector('[data-drawer-panel]').classList.remove('translate-y-full', 'md:translate-x-full');
        });
        $.post(API_URL, { action: 'fetch_lead_detail', lead_id: id }, res => {
            if (res.status !== 'success') { showToast(res.message, 'error'); closeDrawer(); return; }
            renderDrawer(res.data);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function closeDrawer() {
        const dr = document.getElementById('leadDrawer');
        if (dr.classList.contains('hidden')) return;
        dr.querySelector('[data-drawer-backdrop]').classList.add('opacity-0');
        dr.querySelector('[data-drawer-panel]').classList.add('translate-y-full', 'md:translate-x-full');
        setTimeout(() => { dr.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
        fu.leadId = null;
    }

    function renderDrawer(d) {
        const l = d.lead;
        $('#drawerName').text(`${l.first_name} ${l.last_name || ''}`.trim());
        $('#drawerSub').text([l.campaign_title, label(l.status)].filter(Boolean).join(' · '));
        const field = (k, v) => v ? `<div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">${k}</p><p class="text-sm font-medium text-gray-800 whitespace-pre-line">${escapeHtml(v)}</p></div>` : '';
        const wa = (l.phone || '').replace(/\D/g, '').replace(/^0/, '234');
        const section = (title, body) => `<section class="space-y-3"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest">${title}</h4>${body}</section>`;

        const profile = `<div class="flex flex-wrap gap-1.5">${pill(label(l.category), CATEGORY_STYLE[l.category] || CATEGORY_STYLE.Other)}${l.willing_for_visit == 1 ? pill('Willing to visit', 'bg-red-600 text-white') : ''}</div>
            ${l.phone ? `<div class="flex gap-2"><a href="tel:${escapeHtml(l.phone)}" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-800 py-2.5 rounded-xl text-sm font-bold">Call ${escapeHtml(l.phone)}</a><a href="https://wa.me/${wa}" target="_blank" rel="noopener" class="bg-[#25D366] hover:bg-[#128C7E] text-white px-4 py-2.5 rounded-xl text-sm font-bold">WhatsApp</a></div>` : ''}
            <div class="grid grid-cols-2 gap-4">${field('Address', l.address)}${field('Age band', l.age_band)}${field('Marital status', l.marital_status)}${field('Language', l.language)}${field('Best time to call', l.best_time_to_call)}${field('Assigned to', l.assignee_name || 'Unassigned')}</div>
            ${field('Prayer request', l.prayer_request)}${field('Notes', l.notes)}`;

        let actions = '';
        if (!l.assigned_to && !l.pushed_to_embrace_at) {
            actions += `<button type="button" onclick="claimLead(${l.id})" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3 rounded-xl font-bold text-sm">Claim this lead</button>`;
        }
        if (d.can_assign) {
            const opts = fu.members.map(m => `<option value="${m.id}" ${+m.id === +l.assigned_to ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');
            actions += `<div class="space-y-2"><input type="search" placeholder="Search Reach members…" aria-label="Search Reach members" oninput="filterAssignees(this.value)" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:border-emerald-500 outline-none">
                <div class="flex gap-2"><select id="assignSelect" aria-label="Assign to" class="flex-1 min-w-0 px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none"><option value="">Choose a member…</option>${opts}</select>
                <button type="button" onclick="assignLead(${l.id}, ${l.assigned_to ? 'true' : 'false'})" class="shrink-0 bg-gray-900 hover:bg-black text-white px-4 rounded-xl text-sm font-bold">${l.assigned_to ? 'Reassign' : 'Assign'}</button></div></div>`;
        }
        if (l.pushed_to_embrace_at) {
            actions += `<button type="button" disabled class="w-full bg-emerald-50 text-emerald-700 border border-emerald-200 py-3 rounded-xl font-bold text-sm cursor-not-allowed">Pushed to Embrace · ${escapeHtml(relTime(l.pushed_to_embrace_at))}</button>`;
            if (d.can_assign) {
                actions += `<label class="flex items-center justify-between gap-3 text-sm font-semibold text-gray-700 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3 cursor-pointer">Share testimony in monthly report<input type="checkbox" ${+l.share_testimony_in_report ? 'checked' : ''} onchange="setTestimony(${l.id}, this.checked)" class="w-5 h-5 accent-emerald-600"></label>`;
            }
        } else if (d.can_push) {
            actions += `<button type="button" onclick="pushToEmbrace(${l.id})" class="w-full bg-red-600 hover:bg-red-700 text-white py-3 rounded-xl font-bold text-sm shadow-lg shadow-red-900/20">Push to Embrace as 1st Timer</button>`;
        }

        const channelOpts = Object.keys(CHANNEL_ICON).map(c => `<option value="${c}">${label(c)}</option>`).join('');
        const outcomeOpts = Object.keys(OUTCOME_STYLE).map(o => `<option value="${o}">${label(o)}</option>`).join('');
        const logForm = `<form id="fuLogForm" class="space-y-3 bg-gray-50 border border-gray-100 rounded-2xl p-4">
            <div class="grid grid-cols-2 gap-2">
                <select name="channel" required aria-label="Channel" class="px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">${channelOpts}</select>
                <select name="outcome" required aria-label="Outcome" class="px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none">${outcomeOpts}</select>
            </div>
            <textarea name="notes" rows="2" placeholder="What happened? e.g. Coming Sunday" aria-label="Notes" class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white resize-none focus:border-emerald-500 outline-none"></textarea>
            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider">Next touch (optional)<input type="date" name="next_touch_date" class="mt-1 w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:border-emerald-500 outline-none"></label>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 rounded-xl font-bold text-sm">Save follow-up</button>
            <input type="hidden" name="action" value="log_follow_up"><input type="hidden" name="lead_id" value="${l.id}">
        </form>`;

        const timeline = d.follow_ups.length ? `<ol class="relative border-l-2 border-gray-100 ml-3 space-y-5">${d.follow_ups.map(f => `
            <li class="ml-5"><span class="absolute -left-[15px] w-7 h-7 rounded-full bg-white border-2 border-emerald-200 text-emerald-600 flex items-center justify-center">${icon(CHANNEL_ICON[f.channel] || CHANNEL_ICON.Call, 'w-3.5 h-3.5')}</span>
                <div class="flex flex-wrap items-center gap-2">${pill(label(f.outcome), OUTCOME_STYLE[f.outcome] || '')}<span class="text-xs font-bold text-gray-700">${label(f.channel)}</span><span class="text-xs text-gray-400">${escapeHtml(f.follower_name || '—')} · ${escapeHtml(relTime(f.created_at))}</span></div>
                ${f.notes ? `<p class="text-sm text-gray-700 mt-1 whitespace-pre-line">${escapeHtml(f.notes)}</p>` : ''}
                ${f.next_touch_date ? `<p class="text-xs font-bold text-blue-700 mt-1">Next touch: ${escapeHtml(parseDate(f.next_touch_date).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }))}</p>` : ''}
            </li>`).join('')}</ol>` : '<p class="text-sm text-gray-400 italic">No follow-ups yet — be the first to reach out.</p>';

        const captures = `<ul class="space-y-2">${d.captures.map((c, i) => `<li class="flex items-center justify-between gap-2 text-sm"><span class="font-medium text-gray-800">${i === 0 ? 'Captured' : 'Re-captured'} by ${escapeHtml(c.name || 'unknown')} ${c.captured_by_user_id ? '' : pill('Guest', 'bg-yellow-100 text-yellow-800')}</span><span class="text-xs text-gray-400 shrink-0">${escapeHtml(relTime(c.captured_at))}</span></li>`).join('') || '<li class="text-sm text-gray-400 italic">No capture record.</li>'}</ul>`;
        const history = d.assignments.length ? section('Assignment history', `<ul class="space-y-1.5">${d.assignments.map(a => `<li class="text-xs text-gray-500"><span class="font-bold text-gray-700">${label(a.action)}</span> → ${escapeHtml(a.to_name || '—')} by ${escapeHtml(a.by_name || '—')} · ${escapeHtml(relTime(a.created_at))}</li>`).join('')}</ul>`) : '';

        $('#drawerBody').html(section('Profile', profile) + (actions ? section('Actions', `<div class="space-y-3">${actions}</div>`) : '')
            + section('Log follow-up', logForm) + section('Timeline', timeline) + section('Capture chain', captures) + history);

        $('#fuLogForm').on('submit', function(e) {
            e.preventDefault();
            const $btn = $(this).find('button[type=submit]').prop('disabled', true);
            $.post(API_URL, $(this).serialize(), res => {
                showToast(res.status === 'success' ? `Logged — lead is now ${label(res.data.lead_status)}` : res.message, res.status);
                if (res.status === 'success') { openLead(l.id); loadLeads(); }
            }, 'json').fail(() => showToast('Server error', 'error')).always(() => $btn.prop('disabled', false));
        });
    }

    function filterAssignees(q) {
        q = q.toLowerCase();
        $('#assignSelect option').each(function() { if (this.value) $(this).toggle(this.text.toLowerCase().includes(q)); });
    }

    function assignLead(id, isReassign) {
        const to = $('#assignSelect').val();
        if (!to) { showToast('Choose a member first', 'error'); return; }
        $.post(API_URL, { action: isReassign ? 'reassign' : 'assign_lead', lead_id: id, to_user_id: to }, res => {
            showToast(res.message, res.status);
            if (res.status === 'success') { openLead(id); loadLeads(); }
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function pushToEmbrace(id) {
        if (!confirm('Create a 1st Timer profile for this person and hand them to Embrace?')) return;
        lockScreenAction();
        $.post(API_URL, { action: 'push_to_embrace', lead_id: id }, res => {
            unlockScreenAction();
            showToast(res.message, res.status);
            if (res.status === 'success') { openLead(id); loadLeads(); }
        }, 'json').fail(() => { unlockScreenAction(); showToast('Server error', 'error'); });
    }

    function setTestimony(id, on) {
        $.post(API_URL, { action: 'set_testimony_flag', lead_id: id, share: on ? 1 : 0 }, res => showToast(res.message, res.status), 'json')
            .fail(() => showToast('Server error', 'error'));
    }

    /* ============================ ANALYTICS TAB ============================ */
    // Validated with the dataviz palette checker (light surface #ffffff).
    const CAT_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
    const FUNNEL_COLORS = ['#10b981', '#047857', '#064e3b'];
    const AREA_COLOR = '#047857';
    const an = { ready: false, preset: 'this_month', charts: {} };
    const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    function presetRange(p) {
        const now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (p) {
            case 'last_month': return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
            case 'last_3':     return [ymd(new Date(y, m - 2, 1)), ymd(new Date(y, m + 1, 0))];
            case 'ytd':        return [`${y}-01-01`, ymd(now)];
            case 'custom':     return [$('#anFrom').val(), $('#anTo').val()];
            default:           return [ymd(new Date(y, m, 1)), ymd(new Date(y, m + 1, 0))];
        }
    }

    function initAnalytics() {
        if (an.ready) return;
        an.ready = true;
        const presets = [['this_month', 'This Month'], ['last_month', 'Last Month'], ['last_3', 'Last 3 Months'], ['ytd', 'Year to Date'], ['custom', 'Custom']];
        $('#anPresets').html(presets.map(([k, l]) => `<button type="button" data-p="${k}" onclick="pickPreset('${k}')" class="an-preset px-3 py-2 rounded-xl border text-xs font-bold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">${l}</button>`).join(''));
        const [f, t] = presetRange('this_month');
        $('#anFrom').val(f); $('#anTo').val(t);
        $('#anFrom, #anTo').on('change', loadAnalytics);
        if (window.Chart) {
            Chart.defaults.font.family = 'Inter, sans-serif';
            Chart.defaults.color = '#52514e';
        }
        pickPreset('this_month');
    }

    function pickPreset(p) {
        an.preset = p;
        $('.an-preset').removeClass('bg-emerald-600 text-white border-emerald-600').addClass('bg-white text-gray-600 border-gray-200').attr('aria-pressed', 'false');
        $(`.an-preset[data-p="${p}"]`).removeClass('bg-white text-gray-600 border-gray-200').addClass('bg-emerald-600 text-white border-emerald-600').attr('aria-pressed', 'true');
        $('#anCustom').toggleClass('hidden', p !== 'custom');
        loadAnalytics();
    }

    function anCard(title, body, extra = '') {
        return `<section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 ${extra}"><h3 class="text-sm font-bold text-gray-900 mb-4">${title}</h3>${body}</section>`;
    }

    function loadAnalytics() {
        const [from, to] = presetRange(an.preset);
        if (!from || !to) return;
        Object.values(an.charts).forEach(c => c.destroy());
        an.charts = {};
        $('#anBody').html(`<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-pulse" aria-hidden="true">${'<div class="h-24 bg-white border border-gray-100 rounded-3xl"></div>'.repeat(4)}</div><div class="grid lg:grid-cols-2 gap-5 mt-5 animate-pulse" aria-hidden="true">${'<div class="h-72 bg-white border border-gray-100 rounded-3xl"></div>'.repeat(2)}</div>`);
        $.post(API_URL, { action: 'fetch_analytics', from_date: from, to_date: to }, res => {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            renderAnalytics(res.data);
        }, 'json').fail(() => showToast('Server error', 'error'));
    }

    function renderAnalytics(d) {
        const k = d.kpis;
        if (k.souls === 0 && !d.campaigns_table.length) {
            $('#anBody').html(`<div class="bg-white rounded-3xl border border-dashed border-gray-200 p-10 text-center"><div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 mx-auto mb-3 flex items-center justify-center">${icon('M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'w-7 h-7')}</div><p class="font-bold text-gray-900">No outreach in this period</p><p class="text-sm text-gray-500 mt-1">Pick another range, or plan a campaign to get started.</p><button type="button" onclick="switchTab('campaigns')" class="mt-5 bg-emerald-600 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm">Go to Campaigns</button></div>`);
            return;
        }
        const tile = (l, v) => `<div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5"><p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">${l}</p><p class="text-3xl font-display font-bold text-gray-900 mt-1">${v}</p></div>`;
        const totalCat = d.category_breakdown.reduce((a, c) => a + c.count, 0);
        const legend = `<table class="w-full text-sm">${d.category_breakdown.map((c, i) => `<tr class="border-b border-gray-50 last:border-0"><td class="py-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm mr-2 align-middle" style="background:${CAT_COLORS[i]}"></span>${label(c.category)}</td><td class="py-1.5 text-right font-bold text-gray-900">${c.count}</td><td class="py-1.5 text-right text-gray-400 w-14">${totalCat ? Math.round(c.count * 100 / totalCat) : 0}%</td></tr>`).join('')}</table>`;
        const th = t => `<th class="py-2 px-2 text-[10px] font-bold text-gray-500 uppercase tracking-wider text-left">${t}</th>`;
        const thn = t => `<th class="py-2 px-2 text-[10px] font-bold text-gray-500 uppercase tracking-wider text-right">${t}</th>`;
        const leaders = d.volunteer_leaderboard.length
            ? `<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr>${th('#')}${th('Volunteer')}${thn('Souls')}</tr></thead><tbody>${d.volunteer_leaderboard.map((v, i) => `<tr class="border-b border-gray-50"><td class="py-2 px-2 text-gray-400">${i + 1}</td><td class="py-2 px-2 font-semibold text-gray-800">${escapeHtml(v.name)} ${v.is_member ? pill('Member', 'bg-emerald-100 text-emerald-800') : pill('Guest', 'bg-yellow-100 text-yellow-800')}</td><td class="py-2 px-2 text-right font-bold">${v.souls}</td></tr>`).join('')}</tbody></table></div>`
            : '<p class="text-sm text-gray-400 italic">No captures in this period.</p>';
        const camps = d.campaigns_table.length
            ? `<div class="overflow-x-auto"><table class="w-full text-sm min-w-[520px]"><thead class="bg-gray-50"><tr>${th('Campaign')}${th('Date')}${thn('Souls')}${thn('Follow-up')}${thn('Conversion')}${th('Last activity')}</tr></thead><tbody>${d.campaigns_table.map(c => `<tr class="border-b border-gray-50"><td class="py-2 px-2 font-semibold text-gray-800">${escapeHtml(c.title)}</td><td class="py-2 px-2 text-gray-500">${c.date ? escapeHtml(parseDate(c.date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })) : '—'}</td><td class="py-2 px-2 text-right font-bold">${c.souls}</td><td class="py-2 px-2 text-right">${c.follow_up_rate}%</td><td class="py-2 px-2 text-right">${c.conversion_rate}%</td><td class="py-2 px-2 text-gray-500">${escapeHtml(relTime(c.last_activity) || '—')}</td></tr>`).join('')}</tbody></table></div>`
            : '<p class="text-sm text-gray-400 italic">No campaigns in this period.</p>';

        $('#anBody').html(`
            <div id="anPdfReady"></div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">${tile('Souls Captured', k.souls)}${tile('Campaigns Run', k.campaigns)}${tile('Follow-up Rate', k.follow_up_rate + '%')}${tile('Conversion Rate', k.conversion_rate + '%')}</div>
            ${k.overdue ? `<button type="button" onclick="switchTab('followup'); fu.overdue = true; loadLeads();" class="mt-4 w-full text-left flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-[#d03b3b] hover:bg-red-100">${icon('M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z')}${k.overdue} assigned lead(s) overdue for a first follow-up — view them</button>` : ''}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
                ${anCard('Follow-up funnel', '<div class="h-64"><canvas id="chFunnel" role="img" aria-label="Follow-up funnel: ' + d.funnel.map(f => f.stage + ' ' + f.count).join(', ') + '"></canvas></div>')}
                ${anCard('By category', `<div class="grid grid-cols-1 sm:grid-cols-[170px_1fr] gap-4 items-center"><div class="h-44"><canvas id="chCat" role="img" aria-label="Souls by category"></canvas></div>${legend}</div>`)}
            </div>
            ${anCard('Top areas', d.area_breakdown.length ? `<div style="height:${Math.max(140, d.area_breakdown.length * 28 + 30)}px"><canvas id="chArea" role="img" aria-label="Top areas by souls captured"></canvas></div>` : '<p class="text-sm text-gray-400 italic">No addresses recorded in this period.</p>', 'mt-5')}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">${anCard('Volunteer leaderboard', leaders)}${anCard('Campaigns', camps)}</div>`);

        if (!window.Chart) return;
        const grid = { color: '#eeeeea' };
        an.charts.funnel = new Chart(document.getElementById('chFunnel'), {
            type: 'bar',
            data: { labels: d.funnel.map(f => `${f.stage} (${f.count})`), datasets: [{ data: d.funnel.map(f => f.count), backgroundColor: FUNNEL_COLORS, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 72 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } }, x: { grid: { display: false } } } }
        });
        an.charts.cat = new Chart(document.getElementById('chCat'), {
            type: 'doughnut',
            data: { labels: d.category_breakdown.map(c => label(c.category)), datasets: [{ data: d.category_breakdown.map(c => c.count), backgroundColor: CAT_COLORS, borderColor: '#ffffff', borderWidth: 2, hoverOffset: 4 }] },
            options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false } } }
        });
        if (d.area_breakdown.length) {
            an.charts.area = new Chart(document.getElementById('chArea'), {
                type: 'bar',
                data: { labels: d.area_breakdown.map(a => a.area), datasets: [{ data: d.area_breakdown.map(a => a.count), backgroundColor: AREA_COLOR, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }] },
                options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } }, y: { grid: { display: false } } } }
            });
        }
    }

    function exportCsv() {
        const [from, to] = presetRange(an.preset);
        window.location.href = `${API_URL}?action=export_csv&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}`;
    }

    function generatePdf() {
        const [from, to] = presetRange(an.preset);
        const $btn = $('#anPdfBtn').prop('disabled', true).text('Generating…');
        $.post(API_URL, { action: 'generate_pdf', from_date: from, to_date: to }, res => {
            if (res.status !== 'success') { showToast(res.message, 'error'); return; }
            showToast('Report ready');
            $('#anPdfReady').html(`<div class="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3"><span class="text-sm font-bold text-emerald-800">Your Reach report is ready.</span><a href="${escapeHtml(res.data.url)}" target="_blank" rel="noopener" class="shrink-0 bg-emerald-600 hover:bg-emerald-800 text-white px-4 py-2 rounded-xl text-sm font-bold">Open PDF</a></div>`);
        }, 'json').fail(() => showToast('Server error', 'error')).always(() => $btn.prop('disabled', false).text('Generate PDF'));
    }

    $(document).on('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

    $(document).ready(function() {
        loadCampaigns();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
