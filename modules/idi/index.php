<?php
// /modules/idi/index.php
require_once '../../includes/header.php'; 

// 1. Check if logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// 2. Check Leadership Roles
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];
$has_access = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) {
            $has_access = true;
            break;
        }
    }
}

// 3. Check IDI Department Membership (If they aren't leadership)
if (!$has_access) {
    $deptStmt = $pdo->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = 1 AND is_active = 1");
    $deptStmt->execute([$_SESSION['user_id']]);
    if ($deptStmt->fetch()) {
        $has_access = true;
    }
}

// STRICT SECURITY CHECK
if (!$has_access) {
    echo "<div class='min-h-screen flex items-center justify-center bg-gray-50'><div class='bg-white p-8 rounded-3xl shadow-xl text-center max-w-md border border-red-100'><svg class='w-16 h-16 text-red-500 mx-auto mb-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path></svg><h2 class='text-2xl font-black text-gray-900 mb-2'>ACCESS DENIED</h2><p class='text-gray-500 mb-6'>This intelligence module is classified for Senior Leadership and IDI Personnel only.</p><a href='/index.php' class='bg-gray-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-black transition-all inline-block'>Return to Dashboard</a></div></div>";
    require_once '../../includes/footer.php';
    exit;
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>

<div class="max-w-7xl mx-auto space-y-6 pb-10 relative min-h-[80vh]">
    
    <div class="bg-gray-900 rounded-3xl p-6 md:p-8 shadow-2xl relative overflow-hidden flex flex-col xl:flex-row justify-between gap-6 z-10">
        <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/20 rounded-full blur-[80px] -mr-20 -mt-20 pointer-events-none"></div>
        
        <div>
            <h2 class="text-3xl font-display font-bold text-white tracking-tight flex items-center gap-3">
                <svg class="w-8 h-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                Data Insights (IDI)
            </h2>
            <p class="text-gray-400 text-sm mt-1">Church intelligence, growth analytics, and automated reporting.</p>
        </div>

        <div class="flex flex-col sm:flex-row items-center gap-4 relative z-10">
            <form id="globalFilterForm" class="flex items-center bg-gray-800 p-1.5 rounded-2xl border border-gray-700 w-full sm:w-auto shadow-inner">
                <input type="date" name="start_date" id="filterStart" class="bg-transparent text-white text-sm px-3 py-2 outline-none font-medium cursor-pointer [color-scheme:dark]" value="<?= date('Y-01-01') ?>" required>
                <span class="text-gray-500 font-bold px-2">to</span>
                <input type="date" name="end_date" id="filterEnd" class="bg-transparent text-white text-sm px-3 py-2 outline-none font-medium cursor-pointer [color-scheme:dark]" value="<?= date('Y-m-d') ?>" required>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-xl text-sm font-bold transition-colors ml-2 shadow-md">Sync</button>
            </form>

            <div class="flex items-center gap-3 w-full sm:w-auto">
                <button type="button" onclick="toggleGuideMode()" id="btnGuideToggle" class="bg-gray-800 border border-gray-700 text-gray-300 hover:text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Guide: OFF</span>
                </button>

                <button type="button" onclick="exportMasterExcel()" class="bg-green-600 hover:bg-green-500 text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2 shadow-lg shadow-green-900/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Export
                </button>
            </div>
        </div>
    </div>

    <div id="idiLoader" class="absolute inset-0 bg-gray-50/90 backdrop-blur-sm z-50 flex flex-col items-center justify-center rounded-3xl">
        <svg class="animate-spin h-12 w-12 text-blue-600 mb-4" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <p class="text-gray-900 font-bold text-lg tracking-tight">Aggregating Global Data...</p>
        <p class="text-gray-500 text-sm font-medium mt-1">Analyzing millions of data points across 19 tables.</p>
    </div>

    <div id="view-overview" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 opacity-0 transition-opacity duration-500">
        
        <div onclick="switchView('congregation')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-blue-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-blue-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Congregation Health</h3>
            <p class="text-sm text-gray-500">Demographics & Spiritual Funnel</p>
        </div>

        <div onclick="switchView('embrace')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-green-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-green-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-green-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Embrace (Retention)</h3>
            <p class="text-sm text-gray-500">First-Timers & Follow-up Funnel</p>
        </div>

        <div onclick="switchView('attendance')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-purple-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-purple-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-purple-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Event Trends</h3>
            <p class="text-sm text-gray-500">Service Averages & Turnout Rates</p>
        </div>

        <div onclick="switchView('operations')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-orange-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-orange-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Academy & Operations</h3>
            <p class="text-sm text-gray-500">Unit Health & Graduation Pipeline</p>
        </div>

        <div onclick="switchView('charis')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-pink-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-pink-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-pink-50 text-pink-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-pink-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Charis (Welfare)</h3>
            <p class="text-sm text-gray-500">Recovery Algorithms & Life Events</p>
        </div>

        <div onclick="switchView('pastoral')" class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-red-200 transition-all cursor-pointer group relative overflow-hidden">
            <div class="absolute right-0 top-0 bottom-0 w-2 bg-red-500"></div>
            <div class="flex justify-between items-start mb-4">
                <div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 flex items-center justify-center group-hover:scale-110 transition-transform"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg></div>
                <svg class="w-5 h-5 text-gray-300 group-hover:text-red-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Pastoral Desk</h3>
            <p class="text-sm text-gray-500">Congregational Feedback & Q&A</p>
        </div>
    </div>


    <div id="subviews-container" class="hidden">
        <button onclick="switchView('overview')" class="mb-4 text-sm font-bold text-gray-500 hover:text-gray-900 flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> Back to Overview
        </button>

        <div id="view-congregation" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> This section displays the overall structural health of the church. The Funnel shows how members progress spiritually, while the Status chart highlights those who are active versus inconsistent.</p>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">The Spiritual Funnel</h3>
                    <div class="relative h-64"><canvas id="chart-cong-funnel"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Attendance Status Distribution</h3>
                    <div class="relative h-64"><canvas id="chart-cong-status"></canvas></div>
                </div>
            </div>
        </div>

        <div id="view-attendance" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> Compares pre-registered expectations against actual door check-ins for closed events. Use this to measure the "flake rate" of your congregation for special events versus standard services.</p>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Registration vs. Actual Turnout</h3>
                <div class="relative h-80"><canvas id="chart-att-trends"></canvas></div>
            </div>
        </div>

        <div id="view-embrace" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> Evaluates the efficiency of the Embrace unit. It shows how many assigned first-timers were successfully contacted versus those marked unreachable.</p>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Follow-up Resolution Rates</h3>
                <div class="relative h-80"><canvas id="chart-embrace-status"></canvas></div>
            </div>
        </div>

        <div id="view-charis" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> The algorithm automatically scans the database to find missing members who recently checked in, declaring them "Recovered." The Life Events table lists upcoming celebrations for welfare outreach.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="bg-pink-50 border border-pink-100 p-6 rounded-3xl flex items-center justify-between shadow-sm">
                    <div><p class="text-xs font-bold text-pink-600 uppercase tracking-widest mb-1">Total At Risk (Unknown)</p><h3 id="charis-at-risk" class="text-4xl font-black text-pink-900">0</h3></div>
                    <div class="w-14 h-14 rounded-full bg-pink-200 text-pink-600 flex items-center justify-center"><svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                </div>
                <div class="bg-green-50 border border-green-100 p-6 rounded-3xl flex items-center justify-between shadow-sm relative overflow-hidden">
                    <div class="absolute right-0 top-0 bottom-0 w-16 bg-gradient-to-l from-green-200 to-transparent"></div>
                    <div class="relative z-10"><p class="text-xs font-bold text-green-600 uppercase tracking-widest mb-1">Auto-Detected Recovered</p><h3 id="charis-recovered" class="text-4xl font-black text-green-900">0</h3></div>
                    <div class="relative z-10 w-14 h-14 rounded-full bg-green-200 text-green-600 flex items-center justify-center"><svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Upcoming Birthdays (30 Days)</h3>
                <div class="overflow-x-auto custom-scrollbar max-h-[300px]">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 font-bold uppercase tracking-wider text-[10px] sticky top-0"><tr><th class="px-6 py-3">Member Name</th><th class="px-6 py-3">Date of Birth</th><th class="px-6 py-3">Phone</th></tr></thead>
                        <tbody id="charis-bday-body" class="divide-y divide-gray-50"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="view-operations" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> Highlights internal workforce distribution across departments. The Academy chart shows the throughput of your training school to ensure a steady supply of new workers.</p>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Department Staffing Levels</h3>
                    <div class="relative h-64"><canvas id="chart-ops-depts"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Academy Graduation Pipeline</h3>
                    <div class="relative h-64"><canvas id="chart-ops-academy"></canvas></div>
                </div>
            </div>
        </div>

        <div id="view-pastoral" class="sub-view hidden space-y-6 animate-fade-in-up">
            <div class="guide-box hidden bg-blue-50 border border-blue-100 p-4 rounded-2xl mb-4">
                <p class="text-sm text-blue-800 font-medium"><strong>Guide:</strong> Tracks the volume and resolution status of Q&A and Suggestions submitted by the congregation, allowing the pastoral team to measure their responsiveness.</p>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Feedback Resolution Status</h3>
                <div class="relative h-80"><canvas id="chart-pastoral-status"></canvas></div>
            </div>
        </div>

    </div>
</div>

<script>
    const API_URL = '/api/idi_api.php';
    let globalData = null;
    let activeCharts = {}; // Store chart instances to destroy them before redrawing
    let guideMode = false;

    // ==========================================
    // 1. DATA ENGINE (Heavy Initial Load)
    // ==========================================
    function fetchInsights() {
        $('#idiLoader').removeClass('hidden');
        $('#view-overview, #subviews-container').addClass('hidden').removeClass('opacity-100');
        
        const payload = $('#globalFilterForm').serialize() + '&action=fetch_all_insights';

        $.post(API_URL, payload, function(res) {
            if(res.status === 'success') {
                globalData = res;
                
                // Fade Out Loader, Fade In Grid
                setTimeout(() => {
                    $('#idiLoader').addClass('hidden');
                    switchView('overview'); // Ensure we start at overview on sync
                    $('#view-overview').removeClass('hidden').addClass('opacity-100');
                }, 500);

                updateCharisTextUI();
                renderAllCharts();
            } else {
                Toastify({ text: res.message, style: { background: "#EF4444" } }).showToast();
                $('#idiLoader').addClass('hidden');
            }
        }, 'json');
    }

    // ==========================================
    // 2. UI ROUTING & GUIDE MODE
    // ==========================================
    function switchView(viewId) {
        if(viewId === 'overview') {
            $('#subviews-container').addClass('hidden');
            $('#view-overview').removeClass('hidden').addClass('opacity-100');
        } else {
            $('#view-overview').addClass('hidden').removeClass('opacity-100');
            $('#subviews-container').removeClass('hidden');
            
            // Hide all subviews, show the target
            $('.sub-view').addClass('hidden');
            $(`#view-${viewId}`).removeClass('hidden');
        }
    }

    function toggleGuideMode() {
        guideMode = !guideMode;
        const btn = $('#btnGuideToggle');
        
        if (guideMode) {
            btn.addClass('bg-blue-600 text-white border-blue-500').removeClass('bg-gray-800 text-gray-300 border-gray-700').html(`<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Guide: ON`);
            $('.guide-box').removeClass('hidden').hide().slideDown(300);
        } else {
            btn.removeClass('bg-blue-600 text-white border-blue-500').addClass('bg-gray-800 text-gray-300 border-gray-700').html(`<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Guide: OFF`);
            $('.guide-box').slideUp(300);
        }
    }

    // ==========================================
    // 3. TEXT UI UPDATES (Charis specific)
    // ==========================================
    function updateCharisTextUI() {
        $('#charis-at-risk').text(globalData.charis.total_at_risk || 0);
        $('#charis-recovered').text(globalData.charis.auto_detected_back || 0);

        let bdayHtml = '';
        if(globalData.charis.upcoming_birthdays.length === 0) {
            bdayHtml = '<tr><td colspan="3" class="px-6 py-8 text-center text-gray-400 italic">No upcoming birthdays in the next 30 days.</td></tr>';
        } else {
            globalData.charis.upcoming_birthdays.forEach(b => {
                bdayHtml += `<tr class="border-b border-gray-50">
                    <td class="px-6 py-4 font-bold text-gray-900">${b.first_name} ${b.last_name}</td>
                    <td class="px-6 py-4 font-bold text-pink-600">${new Date(b.dob).toLocaleDateString('en-US', {month:'long', day:'numeric'})}</td>
                    <td class="px-6 py-4 text-gray-500 text-xs">${b.phone || 'N/A'}</td>
                </tr>`;
            });
        }
        $('#charis-bday-body').html(bdayHtml);
    }

    // ==========================================
    // 4. MASTER CHART.JS RENDER ENGINE
    // ==========================================
    const ChartColors = {
        blue: ['#3B82F6', '#60A5FA', '#93C5FD', '#BFDBFE'],
        green: ['#10B981', '#34D399', '#6EE7B7', '#A7F3D0'],
        purple: ['#8B5CF6', '#A78BFA', '#C4B5FD', '#DDD6FE'],
        red: ['#EF4444', '#F87171', '#FCA5A5', '#FECACA'],
        orange: ['#F97316', '#FB923C', '#FDBA74', '#FED7AA']
    };

    function initChart(canvasId, type, config) {
        if (activeCharts[canvasId]) activeCharts[canvasId].destroy(); // Prevent canvas overlay issues
        const ctx = document.getElementById(canvasId).getContext('2d');
        activeCharts[canvasId] = new Chart(ctx, { type: type, data: config.data, options: { responsive: true, maintainAspectRatio: false, ...config.options } });
    }

    function renderAllCharts() {
        if(!globalData) return;

        // --- 1. Congregation Funnel (Pie) ---
        const fLabels = globalData.congregation.funnel.map(f => f.spiritual_status.replace(/_/g, ' '));
        const fData = globalData.congregation.funnel.map(f => f.total);
        initChart('chart-cong-funnel', 'doughnut', {
            data: { labels: fLabels, datasets: [{ data: fData, backgroundColor: ChartColors.blue, borderWidth: 0 }] },
            options: { plugins: { legend: { position: 'right' } }, cutout: '60%' }
        });

        // --- 1B. Congregation Status (Bar) ---
        const sLabels = globalData.congregation.attendance_status.map(s => s.attendance_status.replace(/_/g, ' '));
        const sData = globalData.congregation.attendance_status.map(s => s.total);
        initChart('chart-cong-status', 'bar', {
            data: { labels: sLabels, datasets: [{ label: 'Members', data: sData, backgroundColor: '#3B82F6', borderRadius: 6 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4] } }, x: { grid: { display: false } } } }
        });

        // --- 2. Attendance Trends (Line/Bar Combo) ---
        const attLabels = globalData.attendance.event_trends.map(e => e.title);
        const regData = globalData.attendance.event_trends.map(e => e.pre_registered);
        const actData = globalData.attendance.event_trends.map(e => e.actually_attended);
        initChart('chart-att-trends', 'bar', {
            data: { 
                labels: attLabels, 
                datasets: [
                    { label: 'Pre-Registered', data: regData, backgroundColor: '#DDD6FE', borderRadius: 4 },
                    { label: 'Actual Turnout', data: actData, backgroundColor: '#8B5CF6', borderRadius: 4 }
                ] 
            },
            options: { scales: { y: { beginAtZero: true } }, interaction: { mode: 'index', intersect: false } }
        });

        // --- 3. Embrace Status (Bar) ---
        const eLabels = [...new Set(globalData.embrace.followup_stats.map(e => e.followup_method))];
        const ePending = eLabels.map(lbl => { const f = globalData.embrace.followup_stats.find(e => e.followup_method === lbl && e.status === 'Pending'); return f ? f.total : 0; });
        const eComp = eLabels.map(lbl => { const f = globalData.embrace.followup_stats.find(e => e.followup_method === lbl && e.status === 'Completed'); return f ? f.total : 0; });
        initChart('chart-embrace-status', 'bar', {
            data: { 
                labels: eLabels, 
                datasets: [
                    { label: 'Pending', data: ePending, backgroundColor: '#FCA5A5' },
                    { label: 'Completed', data: eComp, backgroundColor: '#10B981' }
                ] 
            },
            options: { scales: { x: { stacked: true }, y: { stacked: true } } }
        });

        // --- 5A. Operations Dept Health (Horizontal Bar) ---
        const dLabels = globalData.operations.department_health.map(d => d.name);
        const dData = globalData.operations.department_health.map(d => d.total_workers);
        initChart('chart-ops-depts', 'bar', {
            data: { labels: dLabels, datasets: [{ label: 'Active Workers', data: dData, backgroundColor: '#F97316', borderRadius: 4 }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false } } }
        });

        // --- 5B. Academy Pipeline (Doughnut) ---
        const aLabels = globalData.academy.pipeline.map(a => a.graduation_status);
        const aData = globalData.academy.pipeline.map(a => a.total);
        initChart('chart-ops-academy', 'pie', {
            data: { labels: aLabels, datasets: [{ data: aData, backgroundColor: ChartColors.orange, borderWidth: 0 }] },
            options: { plugins: { legend: { position: 'bottom' } } }
        });

        // --- 6. Pastoral Resolution (Bar) ---
        // Combine QA and Suggestions for a master overview
        const pPending = globalData.pastoral.qa_stats.filter(q => q.status === 'Pending').reduce((a,b)=>a+b.total, 0) + globalData.pastoral.suggestion_stats.filter(s => s.status === 'New').reduce((a,b)=>a+b.total, 0);
        const pResolved = globalData.pastoral.qa_stats.filter(q => q.status === 'Answered').reduce((a,b)=>a+b.total, 0) + globalData.pastoral.suggestion_stats.filter(s => s.status === 'Actioned').reduce((a,b)=>a+b.total, 0);
        initChart('chart-pastoral-status', 'bar', {
            data: { labels: ['Pending/New', 'Resolved/Actioned'], datasets: [{ label: 'Tickets', data: [pPending, pResolved], backgroundColor: ['#EF4444', '#10B981'], borderRadius: 6 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    // ==========================================
    // 5. THE EXCEL MASTER GENERATOR (SheetJS)
    // ==========================================
    function exportMasterExcel() {
        if(!globalData || !globalData.export_data.master_roster.length) {
            Toastify({ text: "No data available to export.", style: { background: "#F59E0B" } }).showToast();
            return;
        }

        const wb = XLSX.utils.book_new();
        
        // Sheet 1: Master Roster
        const wsRoster = XLSX.utils.json_to_sheet(globalData.export_data.master_roster);
        XLSX.utils.book_append_sheet(wb, wsRoster, "Congregation Roster");

        // Sheet 2: Attendance Trends
        const wsAtt = XLSX.utils.json_to_sheet(globalData.attendance.event_trends);
        XLSX.utils.book_append_sheet(wb, wsAtt, "Event Turnout");

        // Sheet 3: Charis Birthdays
        const wsBday = XLSX.utils.json_to_sheet(globalData.charis.upcoming_birthdays);
        XLSX.utils.book_append_sheet(wb, wsBday, "Upcoming Birthdays");

        // Generate filename with current date stamp
        const timestamp = new Date().toISOString().slice(0,10);
        XLSX.writeFile(wb, `HOD_DataInsights_Master_${timestamp}.xlsx`);
        Toastify({ text: "Master Excel Report Downloaded!", style: { background: "#10B981" } }).showToast();
    }

    // ==========================================
    // INITIALIZATION
    // ==========================================
    $(document).ready(function() {
        // Form submission triggers re-sync
        $('#globalFilterForm').on('submit', function(e) {
            e.preventDefault();
            fetchInsights();
        });

        // Initial Data Pull on Load
        fetchInsights();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>