<?php
// /modules/security/index.php
require_once '../../includes/header.php';

// STRICT SECURITY CHECK: Super Admin or Resident Pastor only.
// Checked against the live roles table, not $_SESSION['active_role'].
if (!isset($_SESSION['user_id']) || !security_is_admin($pdo)) {
    echo "<div class='min-h-screen flex items-center justify-center bg-gray-50'><div class='bg-white p-8 rounded-3xl shadow-xl text-center max-w-md border border-red-100'><svg class='w-16 h-16 text-red-500 mx-auto mb-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path></svg><h2 class='text-2xl font-black text-gray-900 mb-2'>SECURITY CLEARANCE REQUIRED</h2><p class='text-gray-500 mb-6'>The Security Centre is restricted to Super Admins and the Resident Pastor.</p><a href='/index.php' class='bg-gray-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-black transition-all inline-block'>Return to Dashboard</a></div></div>";
    require_once '../../includes/footer.php';
    exit;
}

$schemaReady  = security_schema_ready($pdo);
$viewerIsSuper = security_is_super_admin($pdo);
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">

    <!-- ============================ HERO ============================ -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 bg-gray-900 p-6 md:p-8 rounded-3xl shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-red-500/20 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-blue-500/20 rounded-full blur-3xl -ml-20 -mb-20 pointer-events-none z-0"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-red-700 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-white tracking-tight">Security Centre</h2>
                <p class="text-gray-400 text-sm md:text-base mt-1 font-medium">
                    Revoke accounts, reset passwords, change sign-in emails, kill live sessions and read the audit trail.
                </p>
            </div>
        </div>

        <div class="relative z-10 grid grid-cols-2 sm:grid-cols-4 gap-2 w-full lg:w-auto">
            <div class="bg-white/5 border border-white/10 rounded-2xl px-4 py-3 text-center">
                <p id="statActive" class="text-xl font-black text-emerald-400">—</p>
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">Active</p>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl px-4 py-3 text-center">
                <p id="statBlocked" class="text-xl font-black text-red-400">—</p>
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">Blocked</p>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl px-4 py-3 text-center">
                <p id="statSessions" class="text-xl font-black text-blue-400">—</p>
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">Online 24h</p>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl px-4 py-3 text-center">
                <p id="statFailures" class="text-xl font-black text-amber-400">—</p>
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">Fails Today</p>
            </div>
        </div>
    </div>

    <?php if (!$schemaReady): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-5 text-sm font-semibold">
        <strong class="block mb-1">Security tables are not installed yet.</strong>
        Run <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono text-xs">php db/migrate.php</code> on the server
        (or trigger a deploy) to apply <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono text-xs">20261005090000_security_core.sql</code>.
        Until then this screen has nothing to read.
    </div>
    <?php endif; ?>

    <!-- ============================ TABS ============================ -->
    <div class="bg-white p-1.5 rounded-2xl border border-gray-100 shadow-sm flex flex-wrap gap-1">
        <button data-tab="accounts" class="sec-tab flex-1 min-w-[130px] px-4 py-2.5 rounded-xl text-sm font-bold transition-all bg-gray-900 text-white shadow-sm">Accounts</button>
        <button data-tab="sessions" class="sec-tab flex-1 min-w-[130px] px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Live Sessions</button>
        <button data-tab="logins"   class="sec-tab flex-1 min-w-[130px] px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Login Activity</button>
        <button data-tab="audit"    class="sec-tab flex-1 min-w-[130px] px-4 py-2.5 rounded-xl text-sm font-bold transition-all text-gray-500 hover:text-gray-900">Audit Trail</button>
    </div>

    <!-- ========================= ACCOUNTS TAB ======================== -->
    <div id="pane-accounts" class="sec-pane space-y-4">
        <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm flex flex-col lg:flex-row gap-3">
            <div class="relative flex-1">
                <input type="text" id="accountSearch" placeholder="Search by name, email or phone..."
                    class="w-full pl-11 pr-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-gray-900 focus:border-transparent outline-none transition-all bg-gray-50 focus:bg-white font-medium">
                <svg class="w-5 h-5 text-gray-400 absolute left-4 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <select id="accountFilter" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 focus:bg-white outline-none cursor-pointer">
                    <option value="all">All accounts</option>
                    <option value="active">Active only</option>
                    <option value="suspended">Suspended</option>
                    <option value="revoked">Revoked</option>
                    <option value="locked">Locked out</option>
                    <option value="must_change">Must change password</option>
                    <option value="no_password">No password set</option>
                    <option value="no_email">No sign-in email</option>
                    <option value="online">Online now</option>
                    <option value="privileged">Has system roles</option>
                </select>
                <button type="button" id="btnGenerateEmails" title="Create a unique @hodlc.com sign-in email for every account that has none"
                    class="flex-1 sm:flex-none bg-gray-900 hover:bg-black text-white text-[11px] font-black uppercase tracking-widest px-4 py-3 rounded-xl transition-all flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
                    Generate missing emails
                </button>
            </div>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[420px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Member</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Clearance</th>
                            <th class="px-6 py-4">Last Sign-in</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="accountsBody" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading accounts…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3 bg-gray-50/50 border-t border-gray-100 text-[11px] font-bold text-gray-400 uppercase tracking-widest flex flex-col sm:flex-row items-center justify-between gap-3">
                <span id="accountsRange">0 account(s) shown</span>
                <div id="accountsPager" class="flex items-center gap-1"></div>
            </div>
        </div>
    </div>

    <!-- ========================= SESSIONS TAB ======================== -->
    <div id="pane-sessions" class="sec-pane hidden space-y-4">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="p-6 border-b border-gray-100 bg-gray-50/50 flex flex-wrap justify-between items-center gap-3">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Live Sessions</h3>
                    <p class="text-xs text-gray-500 font-medium mt-0.5">Every browser signed in over the last 7 days.</p>
                </div>
                <button id="btnRefreshSessions" class="text-xs font-bold text-gray-500 hover:text-gray-900 uppercase tracking-widest">Refresh</button>
            </div>
            <div class="overflow-x-auto custom-scrollbar min-h-[420px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-white text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Member</th>
                            <th class="px-6 py-4">Device</th>
                            <th class="px-6 py-4">IP</th>
                            <th class="px-6 py-4">Signed in</th>
                            <th class="px-6 py-4">Last active</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="sessionsBody" class="divide-y divide-gray-50">
                        <tr><td colspan="6" class="text-center py-20 text-gray-400 font-bold">Open this tab to load sessions.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================== LOGINS TAB ========================= -->
    <div id="pane-logins" class="sec-pane hidden space-y-4">
        <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm flex flex-col md:flex-row gap-3">
            <input type="text" id="loginSearch" placeholder="Search by name, email or IP address..."
                class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:bg-white outline-none font-medium">
            <select id="loginFilter" class="px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold bg-gray-50 focus:bg-white outline-none cursor-pointer">
                <option value="all">All attempts</option>
                <option value="failed">Failures only</option>
                <option value="success">Successes only</option>
            </select>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[420px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">When</th>
                            <th class="px-6 py-4">Who</th>
                            <th class="px-6 py-4">Result</th>
                            <th class="px-6 py-4">Device</th>
                            <th class="px-6 py-4">IP</th>
                        </tr>
                    </thead>
                    <tbody id="loginsBody" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">Open this tab to load login activity.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================== AUDIT TAB ========================== -->
    <div id="pane-audit" class="sec-pane hidden space-y-4">
        <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
            <input type="text" id="auditSearch" placeholder="Search the audit trail (action, admin, member, details)..."
                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:bg-white outline-none font-medium">
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar min-h-[420px]">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">When</th>
                            <th class="px-6 py-4">Action</th>
                            <th class="px-6 py-4">Performed by</th>
                            <th class="px-6 py-4">Target</th>
                            <th class="px-6 py-4">Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditBody" class="divide-y divide-gray-50">
                        <tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">Open this tab to load the audit trail.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MANAGE ACCOUNT MODAL ====================== -->
<div id="securityManageModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">

        <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/60 flex justify-between items-start gap-4 shrink-0">
            <div class="flex items-center gap-4 min-w-0">
                <img id="mgAvatar" src="/assets/images/default-avatar.png" alt=""
                     class="w-12 h-12 rounded-2xl object-cover border border-gray-200 bg-white shrink-0">
                <div class="min-w-0">
                    <h3 id="mgName" class="text-lg font-bold text-gray-900 truncate">Member</h3>
                    <p id="mgEmail" class="text-xs text-gray-500 font-medium truncate"></p>
                </div>
                <span id="mgStatusBadge" class="shrink-0 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-gray-100 text-gray-500">—</span>
            </div>
            <button type="button" data-close-manage class="text-gray-400 hover:text-gray-900 bg-white p-1.5 rounded-full shadow-sm shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div id="mgBody" class="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-6">
            <p class="text-center text-gray-400 font-bold py-16 animate-pulse">Loading account…</p>
        </div>
    </div>
</div>

<script>
const SEC_API = '/api/security_api.php';
const VIEWER_IS_SUPER = <?= $viewerIsSuper ? 'true' : 'false' ?>;
const SCHEMA_READY = <?= $schemaReady ? 'true' : 'false' ?>;
const SEC_EMAIL_DOMAIN = <?= json_encode(SECURITY_LOGIN_EMAIL_DOMAIN) ?>;

let manageUserId = null;
let loadedTabs = {};

/* ----------------------------------------------------------- helpers */

// Escapes for BOTH text and attribute contexts (quotes included) — several of
// these values are interpolated straight into src="" / title="" attributes.
function esc(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function when(raw) {
    if (!raw) return '—';
    const d = new Date(String(raw).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(raw);
    return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true });
}

function ago(raw) {
    if (!raw) return '—';
    const d = new Date(String(raw).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(raw);
    const secs = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    if (secs < 60) return 'just now';
    if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
    if (secs < 2592000) return Math.floor(secs / 86400) + 'd ago';
    return when(raw);
}

function toast(msg, type) {
    Toastify({
        text: msg,
        gravity: 'top',
        position: 'center',
        duration: 6000,
        style: {
            background: type === 'success' ? '#10B981' : (type === 'warning' ? '#F59E0B' : '#EF4444'),
            borderRadius: '12px',
            fontWeight: 'bold',
            maxWidth: '520px'
        }
    }).showToast();
}

function statusBadge(status, lockedUntil) {
    const locked = lockedUntil && new Date(String(lockedUntil).replace(' ', 'T')) > new Date();
    if (locked) return '<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">Locked</span>';
    if (status === 'suspended') return '<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-orange-50 text-orange-700 border border-orange-200">Suspended</span>';
    if (status === 'revoked')   return '<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-red-50 text-red-700 border border-red-200">Revoked</span>';
    return '<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Active</span>';
}

function post(payload, done) {
    $.post(SEC_API, payload, function(res) {
        if (res.status !== 'success') {
            toast(res.message || 'That did not work.', 'error');
        }
        if (done) done(res);
    }, 'json').fail(function() {
        toast('Network error talking to the security API.', 'error');
        if (done) done({ status: 'error' });
    });
}

/* -------------------------------------------------------------- tabs */

function switchTab(name) {
    $('.sec-tab').removeClass('bg-gray-900 text-white shadow-sm').addClass('text-gray-500 hover:text-gray-900');
    $(`.sec-tab[data-tab="${name}"]`).addClass('bg-gray-900 text-white shadow-sm').removeClass('text-gray-500 hover:text-gray-900');
    $('.sec-pane').addClass('hidden');
    $('#pane-' + name).removeClass('hidden');

    if (loadedTabs[name]) return;
    loadedTabs[name] = true;

    if (name === 'sessions') loadSessions();
    if (name === 'logins')   loadLogins();
    if (name === 'audit')    loadAudit();
}

/* ---------------------------------------------------------- overview */

function loadOverview() {
    post({ action: 'fetch_overview' }, function(res) {
        if (res.status !== 'success') return;
        const d = res.data;
        $('#statActive').text(d.active_accounts);
        $('#statBlocked').text(d.suspended_accounts + d.revoked_accounts);
        $('#statSessions').text(d.live_sessions);
        $('#statFailures').text(d.failures_today);
    });
}

/* ---------------------------------------------------------- accounts */

let accountsPage = 1;

function accountsPagerHtml(pg) {
    // Compact numbered pager: first, last, and a window around the current page.
    if (!pg || pg.pages <= 1) return '';

    const current = pg.page;
    const want = new Set([1, pg.pages, current - 1, current, current + 1]);
    const items = [...want].filter(n => n >= 1 && n <= pg.pages).sort((a, b) => a - b);

    const btn = (n, label, opts = {}) => `
        <button type="button" data-page="${n}"
            class="min-w-[30px] h-8 px-2 rounded-lg text-[11px] font-black transition-all ${n === current ? 'bg-gray-900 text-white' : 'bg-white text-gray-500 border border-gray-200 hover:border-gray-900 hover:text-gray-900'} ${opts.disabled ? 'opacity-40 pointer-events-none' : ''}"
            ${opts.disabled ? 'disabled' : ''}>${label ?? n}</button>`;

    let html = btn(Math.max(1, current - 1), '‹', { disabled: current <= 1 });

    let prev = 0;
    items.forEach(n => {
        if (n - prev > 1) html += '<span class="px-1 text-gray-300 font-black">…</span>';
        html += btn(n);
        prev = n;
    });

    html += btn(Math.min(pg.pages, current + 1), '›', { disabled: current >= pg.pages });
    return html;
}

function loadAccounts() {
    const payload = {
        action: 'fetch_accounts',
        search: $('#accountSearch').val() || '',
        filter: $('#accountFilter').val() || 'all',
        page: accountsPage
    };

    $('#accountsBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading accounts…</td></tr>');

    post(payload, function(res) {
        if (res.status !== 'success') {
            $('#accountsBody').html(`<tr><td colspan="5" class="text-center py-20 text-red-500 font-bold">${esc(res.message || 'Could not load accounts.')}</td></tr>`);
            $('#accountsRange').text('Could not load accounts.');
            $('#accountsPager').html('');
            return;
        }

        const rows = res.data || [];
        const pg = res.pagination || null;

        if (pg) {
            accountsPage = pg.page; // the server clamps out-of-range pages
            $('#accountsRange').text(
                pg.total === 0
                    ? 'No accounts match — nothing beyond this point'
                    : `Showing ${pg.from.toLocaleString()}–${pg.to.toLocaleString()} of ${pg.total.toLocaleString()} account(s)`
                      + ` · page ${pg.page} of ${pg.pages} · 100 per page · problems first`
            );
            $('#accountsPager').html(accountsPagerHtml(pg));
        } else {
            $('#accountsRange').text(`${rows.length} account(s) shown`);
            $('#accountsPager').html('');
        }

        if (!rows.length) {
            $('#accountsBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">No accounts match that search.</td></tr>');
            return;
        }

        $('#accountsBody').html(rows.map(function(u) {
            const name = `${u.first_name || ''} ${u.last_name || ''}`.trim() || 'Unnamed';
            const active = (u.active_roles || '').split(',').filter(Boolean);
            const frozen = (u.frozen_roles || '').split(',').filter(Boolean);

            let clearance = active.length
                ? active.map(r => `<span class="inline-block text-[9px] font-bold uppercase bg-blue-50 text-blue-700 border border-blue-100 px-2 py-0.5 rounded-full mr-1 mb-1">${esc(r.replace(/_/g, ' '))}</span>`).join('')
                : '<span class="text-[11px] text-gray-400 font-semibold">Member</span>';
            if (frozen.length) {
                clearance += frozen.map(r => `<span class="inline-block text-[9px] font-bold uppercase bg-gray-100 text-gray-400 border border-gray-200 px-2 py-0.5 rounded-full mr-1 mb-1 line-through">${esc(r.replace(/_/g, ' '))}</span>`).join('');
            }

            const flags = [];
            if (Number(u.must_change_password) === 1) flags.push('<span class="text-[9px] font-bold uppercase bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full">Reset pending</span>');
            if (Number(u.no_password) === 1)          flags.push('<span class="text-[9px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200 px-2 py-0.5 rounded-full">No password</span>');
            if (Number(u.live_sessions) > 0)          flags.push(`<span class="text-[9px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full">${u.live_sessions} online</span>`);

            return `
                <tr class="hover:bg-gray-50/70 transition-colors">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="${esc(u.picture_path || '/assets/images/default-avatar.png')}" alt=""
                                 onerror="this.src='/assets/images/default-avatar.png'"
                                 class="w-9 h-9 rounded-xl object-cover bg-gray-100 border border-gray-200 shrink-0">
                            <div class="min-w-0">
                                <p class="font-bold text-gray-900 truncate">${esc(name)}</p>
                                ${u.email
                                    ? `<p class="text-[11px] text-gray-400 font-medium truncate">${esc(u.email)}</p>`
                                    : '<p class="text-[11px] text-amber-600 font-bold truncate">no sign-in email</p>'}
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        ${statusBadge(u.account_status, u.locked_until)}
                        <div class="flex flex-wrap gap-1 mt-1.5">${flags.join('')}</div>
                    </td>
                    <td class="px-6 py-4"><div class="flex flex-wrap max-w-[220px]">${clearance}</div></td>
                    <td class="px-6 py-4">
                        <p class="text-xs font-bold text-gray-700">${u.last_login_at ? ago(u.last_login_at) : 'Never'}</p>
                        <p class="text-[10px] text-gray-400 font-medium">${esc(u.last_login_ip || '')}</p>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <button type="button" data-manage="${u.id}"
                            class="bg-gray-900 hover:bg-black text-white text-[11px] font-bold uppercase tracking-wider px-4 py-2 rounded-xl transition-all">
                            Manage
                        </button>
                    </td>
                </tr>`;
        }).join(''));
    });
}

/* ---------------------------------------------------------- sessions */

function loadSessions() {
    $('#sessionsBody').html('<tr><td colspan="6" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading sessions…</td></tr>');

    post({ action: 'fetch_sessions' }, function(res) {
        if (res.status !== 'success') return;
        const rows = res.data || [];

        if (!rows.length) {
            $('#sessionsBody').html('<tr><td colspan="6" class="text-center py-20 text-gray-400 font-bold">Nobody has been signed in for the last 7 days.</td></tr>');
            return;
        }

        $('#sessionsBody').html(rows.map(s => `
            <tr class="hover:bg-gray-50/70 transition-colors">
                <td class="px-6 py-4">
                    <p class="font-bold text-gray-900">${esc(`${s.first_name || ''} ${s.last_name || ''}`.trim() || 'Unknown')}</p>
                    <p class="text-[11px] text-gray-400 font-medium">${esc(s.email || '')}</p>
                </td>
                <td class="px-6 py-4 text-xs font-semibold text-gray-700">${esc(s.device)}</td>
                <td class="px-6 py-4 text-xs font-mono text-gray-500">${esc(s.ip_address || '—')}</td>
                <td class="px-6 py-4 text-xs text-gray-500">${when(s.created_at)}</td>
                <td class="px-6 py-4 text-xs font-bold text-gray-700">${ago(s.last_seen_at)}</td>
                <td class="px-6 py-4 text-right">
                    <button type="button" data-revoke-session="${s.id}"
                        class="border border-gray-200 hover:border-red-300 hover:text-red-600 text-gray-600 text-[11px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-lg transition-all">
                        Sign out
                    </button>
                </td>
            </tr>`).join(''));
    });
}

/* ------------------------------------------------------ login history */

function loadLogins() {
    $('#loginsBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading login activity…</td></tr>');

    post({
        action: 'fetch_login_history',
        filter: $('#loginFilter').val() || 'all',
        search: $('#loginSearch').val() || ''
    }, function(res) {
        if (res.status !== 'success') return;
        const rows = res.data || [];

        if (!rows.length) {
            $('#loginsBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">No login activity recorded yet.</td></tr>');
            return;
        }

        $('#loginsBody').html(rows.map(function(a) {
            const ok = Number(a.was_successful) === 1;
            const who = `${a.first_name || ''} ${a.last_name || ''}`.trim();
            const reason = (a.failure_reason || '').replace(/_/g, ' ');

            return `
                <tr class="hover:bg-gray-50/70 transition-colors">
                    <td class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap">${when(a.created_at)}</td>
                    <td class="px-6 py-4">
                        <p class="font-bold text-gray-900 text-xs">${esc(who || a.email || 'Unknown')}</p>
                        <p class="text-[10px] text-gray-400 font-medium">${esc(a.email || '')}${a.context && a.context !== 'login' ? ' · ' + esc(a.context.replace(/_/g, ' ')) : ''}</p>
                    </td>
                    <td class="px-6 py-4">
                        ${ok
                            ? '<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Success</span>'
                            : `<span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full bg-red-50 text-red-700 border border-red-200">${esc(reason || 'Failed')}</span>`}
                    </td>
                    <td class="px-6 py-4 text-xs text-gray-600">${esc(a.device)}</td>
                    <td class="px-6 py-4 text-xs font-mono text-gray-500">${esc(a.ip_address || '—')}</td>
                </tr>`;
        }).join(''));
    });
}

/* ----------------------------------------------------------- audit */

function loadAudit() {
    $('#auditBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold animate-pulse">Loading audit trail…</td></tr>');

    post({ action: 'fetch_audit_log', search: $('#auditSearch').val() || '' }, function(res) {
        if (res.status !== 'success') return;
        const rows = res.data || [];

        if (!rows.length) {
            $('#auditBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">Nothing logged yet.</td></tr>');
            return;
        }

        $('#auditBody').html(rows.map(function(l) {
            const target = `${l.target_first || ''} ${l.target_last || ''}`.trim();
            const danger = /revok|suspend|lock|failed/i.test(l.action);

            return `
                <tr class="hover:bg-gray-50/70 transition-colors">
                    <td class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap">${when(l.created_at)}</td>
                    <td class="px-6 py-4">
                        <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full ${danger ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'}">
                            ${esc((l.action || '').replace(/_/g, ' '))}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-xs font-bold text-gray-800">${esc(l.actor_label || 'System')}</td>
                    <td class="px-6 py-4 text-xs font-semibold text-gray-700">${esc(target || '—')}</td>
                    <td class="px-6 py-4 text-xs text-gray-500 max-w-md">${esc(l.details || '')}
                        <span class="block text-[10px] text-gray-300 font-mono mt-0.5">${esc(l.ip_address || '')}</span>
                    </td>
                </tr>`;
        }).join(''));
    });
}

/* -------------------------------------------------------- manage modal */

function openManage(userId) {
    manageUserId = userId;
    const modal = document.getElementById('securityManageModal');
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        modal.children[0].classList.remove('scale-95');
    });
    $('#mgBody').html('<p class="text-center text-gray-400 font-bold py-16 animate-pulse">Loading account…</p>');
    loadManage();
}

function closeManage() {
    const modal = document.getElementById('securityManageModal');
    modal.classList.add('opacity-0');
    modal.children[0].classList.add('scale-95');
    setTimeout(() => modal.classList.add('hidden'), 300);
    manageUserId = null;
}

function loadManage() {
    post({ action: 'fetch_user_detail', user_id: manageUserId }, function(res) {
        if (res.status !== 'success') {
            $('#mgBody').html(`<p class="text-center text-red-500 font-bold py-16">${esc(res.message || 'Could not load this account.')}</p>`);
            return;
        }
        renderManage(res.data);
    });
}

function renderManage(d) {
    const u = d.user;
    const name = `${u.first_name || ''} ${u.last_name || ''}`.trim();

    $('#mgName').text(name || 'Member');
    $('#mgEmail').text(u.email || '');
    $('#mgAvatar').attr('src', u.picture_path || '/assets/images/default-avatar.png');
    $('#mgStatusBadge').replaceWith($(statusBadge(u.account_status, u.locked_until)).attr('id', 'mgStatusBadge'));

    const locked = u.locked_until && new Date(String(u.locked_until).replace(' ', 'T')) > new Date();
    const frozenRoles = (d.roles || []).filter(r => Number(r.is_frozen) === 1);
    const activeRoles = (d.roles || []).filter(r => Number(r.is_frozen) !== 1);

    if (!d.can_act) {
        $('#mgBody').html(`
            <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-5 text-sm font-semibold">
                You cannot run security actions on this account from here.
                ${u.id == <?= (int) $_SESSION['user_id'] ?> ? 'To change your own password use <a class="underline" href="/modules/profile/index.php#security">My Profile → Security</a>. To change your own sign-in email, ask another admin to do it for you.' : 'It belongs to a Super Admin and you are not one.'}
            </div>`);
        return;
    }

    const facts = `
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-gray-50 border border-gray-100 rounded-2xl p-3">
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Last sign-in</p>
                <p class="text-xs font-bold text-gray-900 mt-1">${u.last_login_at ? ago(u.last_login_at) : 'Never'}</p>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-2xl p-3">
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Password set</p>
                <p class="text-xs font-bold text-gray-900 mt-1">${Number(u.no_password) === 1 ? 'Never set' : (u.password_changed_at ? ago(u.password_changed_at) : 'Unknown')}</p>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-2xl p-3">
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Failed attempts</p>
                <p class="text-xs font-bold ${Number(u.failed_login_count) > 0 ? 'text-amber-600' : 'text-gray-900'} mt-1">${Number(u.failed_login_count) || 0}${locked ? ' · locked' : ''}</p>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-2xl p-3">
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Live devices</p>
                <p class="text-xs font-bold text-gray-900 mt-1">${(d.sessions || []).length}</p>
            </div>
        </div>`;

    const rolesBlock = `
        <div class="bg-white border border-gray-100 rounded-2xl p-4">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">System clearance</p>
            <div class="flex flex-wrap gap-1.5">
                ${activeRoles.length
                    ? activeRoles.map(r => `<span class="text-[10px] font-bold uppercase bg-blue-50 text-blue-700 border border-blue-100 px-2.5 py-1 rounded-full">${esc(r.role_name.replace(/_/g, ' '))}</span>`).join('')
                    : '<span class="text-xs text-gray-400 font-semibold">No active system roles (ordinary member).</span>'}
                ${frozenRoles.map(r => `<span class="text-[10px] font-bold uppercase bg-gray-100 text-gray-400 border border-gray-200 px-2.5 py-1 rounded-full line-through">${esc(r.role_name.replace(/_/g, ' '))}</span>`).join('')}
            </div>
            ${frozenRoles.length ? `
                <div class="mt-3 flex items-center justify-between gap-3 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
                    <p class="text-[11px] font-semibold text-amber-900">${frozenRoles.length} role(s) frozen by a revoke.</p>
                    ${VIEWER_IS_SUPER
                        ? '<button type="button" data-act="restore_roles" class="text-[10px] font-black uppercase tracking-widest bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg transition-all">Restore roles</button>'
                        : '<span class="text-[10px] font-bold text-amber-700 uppercase">Super Admin only</span>'}
                </div>` : ''}
        </div>`;

    const statusBlock = `
        <div class="border border-gray-100 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100">
                <p class="text-xs font-black text-gray-900 uppercase tracking-widest">Account access</p>
                <p class="text-[11px] text-gray-500 font-medium mt-0.5">
                    Suspend = login blocked, sessions killed. Revoke = the same, plus every system role is frozen.
                </p>
            </div>
            <div class="p-4 space-y-3">
                ${u.status_reason ? `<div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 text-[11px] text-gray-600 font-medium">Current reason: ${esc(u.status_reason)}</div>` : ''}
                <textarea id="mgReason" rows="2" placeholder="Reason (required for suspend / revoke — goes into the audit trail)"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-xs font-medium bg-gray-50 focus:bg-white outline-none resize-none"></textarea>
                <div class="flex flex-wrap gap-2">
                    <button type="button" data-act="status" data-value="active"
                        class="flex-1 min-w-[120px] bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-black uppercase tracking-widest px-4 py-2.5 rounded-xl transition-all ${u.account_status === 'active' ? 'opacity-40 pointer-events-none' : ''}">
                        Reinstate
                    </button>
                    <button type="button" data-act="status" data-value="suspended"
                        class="flex-1 min-w-[120px] bg-orange-500 hover:bg-orange-600 text-white text-[11px] font-black uppercase tracking-widest px-4 py-2.5 rounded-xl transition-all ${u.account_status === 'suspended' ? 'opacity-40 pointer-events-none' : ''}">
                        Suspend
                    </button>
                    <button type="button" data-act="status" data-value="revoked"
                        class="flex-1 min-w-[120px] bg-red-600 hover:bg-red-700 text-white text-[11px] font-black uppercase tracking-widest px-4 py-2.5 rounded-xl transition-all ${u.account_status === 'revoked' ? 'opacity-40 pointer-events-none' : ''}">
                        Revoke
                    </button>
                </div>
            </div>
        </div>`;

    const resetBlock = `
        <div class="border border-gray-100 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100">
                <p class="text-xs font-black text-gray-900 uppercase tracking-widest">Reset password</p>
                <p class="text-[11px] text-gray-500 font-medium mt-0.5">
                    Type the new password and read it to the member privately. Every live session is signed out.
                </p>
            </div>
            <div class="p-4 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <input type="text" id="mgNewPw" autocomplete="off" placeholder="New password"
                        class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-xs font-bold bg-gray-50 focus:bg-white outline-none">
                    <input type="text" id="mgConfirmPw" autocomplete="off" placeholder="Confirm new password"
                        class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-xs font-bold bg-gray-50 focus:bg-white outline-none">
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="mgSuggest" class="text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:border-gray-900 text-gray-600 hover:text-gray-900 px-3 py-2 rounded-lg transition-all">
                        Suggest strong password
                    </button>
                    <label class="flex items-center gap-2 text-[11px] font-semibold text-gray-600 cursor-pointer select-none">
                        <input type="checkbox" id="mgRequireChange" checked class="w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                        Require them to change it at next sign-in
                    </label>
                </div>
                <button type="button" data-act="reset_password"
                    class="w-full bg-gray-900 hover:bg-black text-white text-[11px] font-black uppercase tracking-widest px-4 py-3 rounded-xl transition-all">
                    Set new password
                </button>
            </div>
        </div>`;

    const suggestedLogin = (u.first_name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    const emailInput = (id, label) => `
        <div>
            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">${label}</label>
            <div class="flex items-center border border-gray-200 rounded-xl bg-gray-50 focus-within:bg-white focus-within:ring-2 focus-within:ring-gray-900 focus-within:border-transparent transition-all overflow-hidden">
                <input type="text" id="${id}" autocomplete="off" spellcheck="false" placeholder="e.g. ${esc(suggestedLogin || 'grace')}"
                    class="flex-1 min-w-0 px-3 py-2.5 text-xs font-bold bg-transparent outline-none">
                <span class="shrink-0 px-3 py-2.5 text-xs font-bold text-gray-400 select-none border-l border-gray-200 bg-gray-50/80">@${esc(SEC_EMAIL_DOMAIN)}</span>
            </div>
        </div>`;

    const hasLoginEmail = !!(u.email || '').trim();
    const emailBlock = `
        <div class="border border-gray-100 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100">
                <p class="text-xs font-black text-gray-900 uppercase tracking-widest">${hasLoginEmail ? 'Change sign-in email' : 'Set sign-in email'}</p>
                <p class="text-[11px] text-gray-500 font-medium mt-0.5">
                    The email they type on the sign-in screen — not their personal email. ${hasLoginEmail ? 'For members who find their system-generated address too long or hard to remember.' : 'This member was created without one — set it here so they can sign in.'}
                </p>
            </div>
            <div class="p-4 space-y-3">
                <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 text-[11px] font-medium text-gray-600">
                    <span class="text-gray-400 uppercase tracking-widest text-[9px] font-bold block">Current sign-in email</span>
                    ${hasLoginEmail
                        ? `<span class="font-bold text-gray-900 break-all">${esc(u.email)}</span>`
                        : '<span class="font-bold text-amber-600">None set — they cannot sign in until you give them one.</span>'}
                    ${u.real_email ? `<span class="block text-gray-400 mt-1">Personal email (untouched here): ${esc(u.real_email)}</span>` : ''}
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    ${emailInput('mgNewEmail', 'New sign-in email')}
                    ${emailInput('mgConfirmEmail', 'Confirm new email')}
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-[11px] font-semibold text-gray-600 cursor-pointer select-none">
                        <input type="checkbox" id="mgEmailSignout" class="w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue cursor-pointer">
                        Sign them out of every device now
                    </label>
                    <span class="text-[10px] text-gray-400 font-medium">Their password and personal email are not touched.</span>
                </div>
                <button type="button" data-act="change_email"
                    class="w-full bg-gray-900 hover:bg-black text-white text-[11px] font-black uppercase tracking-widest px-4 py-3 rounded-xl transition-all">
                    ${hasLoginEmail ? 'Change sign-in email' : 'Set sign-in email'}
                </button>
            </div>
        </div>`;

    const toolsBlock = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <button type="button" data-act="force_logout"
                class="border border-gray-200 hover:border-gray-900 text-gray-700 hover:text-gray-900 text-[11px] font-black uppercase tracking-widest px-3 py-3 rounded-xl transition-all">
                Sign out everywhere
            </button>
            <button type="button" data-act="unlock_account"
                class="border border-gray-200 ${locked ? 'hover:border-amber-500 hover:text-amber-600' : 'opacity-40 pointer-events-none'} text-gray-700 text-[11px] font-black uppercase tracking-widest px-3 py-3 rounded-xl transition-all">
                Clear lockout
            </button>
            <button type="button" data-act="force_change" data-value="${Number(u.must_change_password) === 1 ? '0' : '1'}"
                class="border border-gray-200 hover:border-hodBlue hover:text-hodBlue text-gray-700 text-[11px] font-black uppercase tracking-widest px-3 py-3 rounded-xl transition-all">
                ${Number(u.must_change_password) === 1 ? 'Cancel forced change' : 'Force change at next login'}
            </button>
        </div>`;

    const sessionsBlock = `
        <div class="border border-gray-100 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100 flex items-center justify-between">
                <p class="text-xs font-black text-gray-900 uppercase tracking-widest">Signed-in devices</p>
                <span class="text-[10px] font-bold text-gray-400">${(d.sessions || []).length}</span>
            </div>
            <div class="divide-y divide-gray-50 max-h-48 overflow-y-auto custom-scrollbar">
                ${(d.sessions || []).length
                    ? d.sessions.map(s => `
                        <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-gray-900 truncate">${esc(s.device)}</p>
                                <p class="text-[10px] text-gray-400 font-medium">${esc(s.ip_address || '—')} · ${ago(s.last_seen_at)}</p>
                            </div>
                            <button type="button" data-revoke-session="${s.id}"
                                class="shrink-0 text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-red-600 transition-colors">Kill</button>
                        </div>`).join('')
                    : '<p class="px-4 py-5 text-xs text-gray-400 font-semibold text-center">No live devices.</p>'}
            </div>
        </div>`;

    const historyBlock = `
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="border border-gray-100 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100">
                    <p class="text-xs font-black text-gray-900 uppercase tracking-widest">Recent sign-in attempts</p>
                </div>
                <div class="divide-y divide-gray-50 max-h-56 overflow-y-auto custom-scrollbar">
                    ${(d.attempts || []).length
                        ? d.attempts.map(a => `
                            <div class="px-4 py-2 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-bold ${Number(a.was_successful) === 1 ? 'text-emerald-700' : 'text-red-600'}">
                                        ${Number(a.was_successful) === 1 ? 'Success' : esc((a.failure_reason || 'failed').replace(/_/g, ' '))}
                                    </p>
                                    <p class="text-[10px] text-gray-400 font-medium truncate">${esc(a.ip_address || '')} · ${esc(a.device)}</p>
                                </div>
                                <span class="text-[10px] text-gray-400 font-semibold shrink-0">${ago(a.created_at)}</span>
                            </div>`).join('')
                        : '<p class="px-4 py-5 text-xs text-gray-400 font-semibold text-center">No attempts recorded.</p>'}
                </div>
            </div>

            <div class="border border-gray-100 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 bg-gray-50/70 border-b border-gray-100">
                    <p class="text-xs font-black text-gray-900 uppercase tracking-widest">Security history</p>
                </div>
                <div class="divide-y divide-gray-50 max-h-56 overflow-y-auto custom-scrollbar">
                    ${(d.audit || []).length
                        ? d.audit.map(l => `
                            <div class="px-4 py-2">
                                <p class="text-[11px] font-bold text-gray-800">${esc((l.action || '').replace(/_/g, ' '))}</p>
                                <p class="text-[10px] text-gray-400 font-medium">${esc(l.actor_label || 'System')} · ${ago(l.created_at)}</p>
                            </div>`).join('')
                        : '<p class="px-4 py-5 text-xs text-gray-400 font-semibold text-center">Nothing logged yet.</p>'}
                </div>
            </div>
        </div>`;

    $('#mgBody').html(facts + rolesBlock + statusBlock + resetBlock + emailBlock + toolsBlock + sessionsBlock + historyBlock);
}

/* ------------------------------------------------------------ actions */

function afterWrite(res) {
    if (res.status === 'success') {
        toast(res.message, 'success');
        loadOverview();
        loadAccounts();
        if (manageUserId) loadManage();
        if (loadedTabs.sessions) loadSessions();
        if (loadedTabs.audit) loadAudit();
    }
}

$(document).on('click', '[data-manage]', function() {
    openManage($(this).data('manage'));
});

$(document).on('click', '[data-close-manage]', closeManage);

$('#securityManageModal').on('click', function(e) {
    if (e.target === this) closeManage();
});

$(document).on('click', '#mgSuggest', function() {
    post({ action: 'suggest_password' }, function(res) {
        if (res.status !== 'success') return;
        $('#mgNewPw').val(res.data.password);
        $('#mgConfirmPw').val(res.data.password);
        toast('Strong password generated — copy it before you save.', 'success');
    });
});

$(document).on('click', '[data-act]', function() {
    const btn = $(this);
    const act = btn.data('act');
    const value = String(btn.data('value') || '');
    const uid = manageUserId;
    if (!uid) return;

    let payload = null;
    let confirmMsg = null;

    if (act === 'status') {
        const reason = ($('#mgReason').val() || '').trim();
        if (value !== 'active' && reason === '') {
            toast('Please write a reason first — it goes into the audit trail.', 'warning');
            $('#mgReason').focus();
            return;
        }
        confirmMsg = value === 'revoked'
            ? 'REVOKE this account? They will be signed out of every device, blocked from logging in, and every system role will be frozen.'
            : (value === 'suspended'
                ? 'Suspend this account? They will be signed out and blocked from logging in until reinstated.'
                : 'Reinstate this account so they can sign in again?');
        payload = { action: 'set_status', user_id: uid, account_status: value, reason: reason };

    } else if (act === 'reset_password') {
        const pw = $('#mgNewPw').val() || '';
        const cf = $('#mgConfirmPw').val() || '';
        if (pw === '' || cf === '') { toast('Type the new password twice.', 'warning'); return; }
        if (pw !== cf) { toast('The two passwords do not match.', 'warning'); return; }
        confirmMsg = 'Set this new password now? Every device they are signed in on will be signed out.';
        payload = {
            action: 'reset_password',
            user_id: uid,
            new_password: pw,
            confirm_password: cf,
            require_change: $('#mgRequireChange').is(':checked') ? 1 : 0,
            reason: ($('#mgReason').val() || '').trim()
        };

    } else if (act === 'change_email') {
        // The inputs hold the part before the @ — the fixed suffix in the UI
        // shows it — but a pasted full address is accepted too (the server
        // normalises both the same way).
        const withDomain = v => {
            v = (v || '').trim().toLowerCase();
            return v === '' ? v : (v.includes('@') ? v : v + '@' + SEC_EMAIL_DOMAIN);
        };
        const em = ($('#mgNewEmail').val() || '').trim();
        const cf = ($('#mgConfirmEmail').val() || '').trim();
        if (em === '' || cf === '') { toast('Type the new sign-in email twice.', 'warning'); return; }
        if (withDomain(em) !== withDomain(cf)) { toast('The two email addresses do not match.', 'warning'); return; }
        confirmMsg = `Change their sign-in email to ${withDomain(em)}? The old one will stop working immediately — their password stays the same.`;
        payload = {
            action: 'change_email',
            user_id: uid,
            new_email: em,
            confirm_email: cf,
            sign_out_everywhere: $('#mgEmailSignout').is(':checked') ? 1 : 0,
            reason: ($('#mgReason').val() || '').trim()
        };

    } else if (act === 'force_logout') {
        confirmMsg = 'Sign this member out of every device right now?';
        payload = { action: 'force_logout', user_id: uid };

    } else if (act === 'unlock_account') {
        payload = { action: 'unlock_account', user_id: uid };

    } else if (act === 'force_change') {
        payload = { action: 'set_force_change', user_id: uid, force: value };

    } else if (act === 'restore_roles') {
        confirmMsg = 'Restore all frozen system roles for this member?';
        payload = { action: 'restore_roles', user_id: uid };
    }

    if (!payload) return;
    if (confirmMsg && !confirm(confirmMsg)) return;

    btn.prop('disabled', true).addClass('opacity-50');
    post(payload, function(res) {
        btn.prop('disabled', false).removeClass('opacity-50');
        afterWrite(res);
    });
});

$(document).on('click', '[data-revoke-session]', function() {
    if (!confirm('Sign this device out?')) return;
    const btn = $(this);
    btn.prop('disabled', true).addClass('opacity-50');
    post({ action: 'revoke_session', session_id: btn.data('revoke-session') }, function(res) {
        btn.prop('disabled', false).removeClass('opacity-50');
        afterWrite(res);
    });
});

/* ----------------------------------------------------------- wiring */

let searchTimer = null;
function debounce(fn) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(fn, 350);
}

$('.sec-tab').on('click', function() { switchTab($(this).data('tab')); });
$('#accountSearch').on('input', function() { accountsPage = 1; debounce(loadAccounts); });
$('#accountFilter').on('change', function() { accountsPage = 1; loadAccounts(); });

$('#accountsPager').on('click', '[data-page]', function() {
    const page = parseInt($(this).data('page'), 10);
    if (!page || page === accountsPage) return;
    accountsPage = page;
    loadAccounts();
    // Jump back to the top of the roster so the new page is visible.
    $('#pane-accounts')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
});

$('#btnGenerateEmails').on('click', function() {
    if (!confirm('Generate a unique @' + SEC_EMAIL_DOMAIN + ' sign-in email for EVERY account that has none, all at once?\n\n'
        + 'Members keep their password and personal email. Each one gets an in-app notification with their new address.')) return;
    const btn = $(this);
    btn.prop('disabled', true).addClass('opacity-50');
    post({ action: 'generate_missing_emails' }, function(res) {
        btn.prop('disabled', false).removeClass('opacity-50');
        if (res.status !== 'success') return;
        toast(res.message, 'success');
        loadOverview();
        accountsPage = 1;
        loadAccounts();
        if (loadedTabs.audit) loadAudit();
    });
});

$('#btnRefreshSessions').on('click', loadSessions);
$('#loginSearch').on('input', function() { debounce(loadLogins); });
$('#loginFilter').on('change', loadLogins);
$('#auditSearch').on('input', function() { debounce(loadAudit); });

$(document).on('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('securityManageModal').classList.contains('hidden')) {
        closeManage();
    }
});

$(document).ready(function() {
    if (!SCHEMA_READY) {
        $('#accountsBody').html('<tr><td colspan="5" class="text-center py-20 text-gray-400 font-bold">Waiting for the security migration to run.</td></tr>');
        return;
    }
    loadedTabs.accounts = true;
    loadOverview();
    loadAccounts();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
