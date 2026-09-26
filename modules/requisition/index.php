<?php
// /modules/requisition/index.php  — v2 (all bugs corrected)
$currentModule = 'requisition';
require_once '../../includes/header.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// PHP-side role flags (fast, session-only — no DB)
$s_roles        = array_column($_SESSION['roles'] ?? [], 'role_name');
$php_pastor     = in_array('Resident_Pastor', $s_roles) || in_array('Super_Admin', $s_roles);
$php_director   = in_array('Director', $s_roles) || $php_pastor;
$php_can_submit = $php_director || in_array('HOD', $s_roles) || in_array('Assoc_Pastor', $s_roles);

// FIX 4 — safe integer cast
$my_id_php = (int)($_SESSION['user_id'] ?? 0);

// Year range for month selector: 2 years back → 1 year forward
$year_current = (int)date('Y');
$year_options = '';
for ($y = $year_current + 1; $y >= $year_current - 2; $y--) {
    $sel = ($y === $year_current) ? 'selected' : '';
    $year_options .= "<option value=\"{$y}\" {$sel}>{$y}</option>";
}
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<div class="max-w-7xl mx-auto space-y-4 pb-20 md:pb-10">

  <!-- MODULE HEADER -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-5 sm:p-7 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
    <div class="absolute top-0 right-0 w-48 h-48 bg-hodBlue/5 rounded-full blur-3xl -mr-16 -mt-16 pointer-events-none"></div>
    <div class="flex items-center gap-4 relative z-10">
      <div class="w-12 h-12 sm:w-14 sm:h-14 bg-hodBlue rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
        <svg class="w-6 h-6 sm:w-7 sm:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div>
        <h2 class="text-xl sm:text-2xl font-display font-bold text-gray-900 tracking-tight">Requisitions</h2>
        <p class="text-gray-500 text-xs sm:text-sm mt-0.5">Departmental expense requests &amp; approval pipeline</p>
      </div>
    </div>
    <div class="relative z-10 flex gap-2 w-full sm:w-auto">
      <?php if ($php_can_submit): ?>
      <button onclick="openSubmitModal()" class="flex-1 sm:flex-none bg-hodBlue hover:bg-blue-900 text-white px-5 py-3 rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2 text-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Requisition
      </button>
      <?php endif; ?>
      <?php if ($php_pastor): ?>
      <button onclick="openSignatureVaultModal()" title="Signature Vault" class="bg-gray-100 hover:bg-gray-200 text-gray-600 p-3 rounded-xl transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- MAIN CARD -->
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative flex flex-col min-h-[70vh]">

    <!-- Loading overlay — shows error state too -->
    <div id="loadingOverlay" class="absolute inset-0 bg-white/97 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
      <svg id="loadingSpinner" class="animate-spin h-9 w-9 text-hodBlue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
      </svg>
      <p id="loadingText" class="text-gray-400 text-sm font-medium animate-pulse">Loading requisition pipeline...</p>
      <div id="loadingError" class="hidden text-center px-6">
        <svg class="w-12 h-12 mx-auto text-red-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <p class="text-gray-600 font-bold mb-1">Could not load module data</p>
        <p class="text-gray-400 text-xs mb-4">Check your connection or contact your administrator.</p>
        <button onclick="location.reload()" class="bg-hodBlue text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-blue-900 transition-colors">Retry</button>
      </div>
    </div>

    <!-- TAB BAR — FIX 2: content divs use .tab-panel, not [id^="tab-"] -->
    <!-- FIX 13: active chip baked into HTML directly — no JS init flash -->
    <div class="border-b border-gray-100 bg-gray-50/40 px-4 sm:px-6 pt-2 shrink-0 overflow-x-auto custom-scrollbar">
      <nav class="flex gap-1 min-w-max" aria-label="Requisition Tabs">
        <button onclick="switchTab('requisitions')" id="tab-btn-requisitions"
          class="tab-btn border-hodBlue text-hodBlue whitespace-nowrap py-3.5 px-4 border-b-2 font-bold text-xs sm:text-sm transition-all flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          Requisitions
          <span id="badge-req" class="hidden bg-amber-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full min-w-[18px] text-center">0</span>
        </button>

        <?php if ($php_pastor): ?>
        <button onclick="switchTab('approval')" id="tab-btn-approval"
          class="tab-btn border-transparent text-gray-500 hover:text-gray-700 whitespace-nowrap py-3.5 px-4 border-b-2 font-bold text-xs sm:text-sm transition-all flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Pastoral Approval
          <span id="badge-approval" class="hidden bg-hodRed text-white text-[9px] font-black px-1.5 py-0.5 rounded-full min-w-[18px] text-center">0</span>
        </button>
        <?php endif; ?>

        <!-- FIX 1: single id, no duplicate id="finance-tab-btn" -->
        <button onclick="switchTab('finance')" id="tab-btn-finance"
          class="tab-btn border-transparent text-gray-500 hover:text-gray-700 whitespace-nowrap py-3.5 px-4 border-b-2 font-bold text-xs sm:text-sm transition-all items-center gap-2 hidden">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
          Finance &amp; Batches
          <span id="badge-finance" class="hidden bg-green-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full min-w-[18px] text-center">0</span>
        </button>

        <button onclick="switchTab('analytics')" id="tab-btn-analytics"
          class="tab-btn border-transparent text-gray-500 hover:text-gray-700 whitespace-nowrap py-3.5 px-4 border-b-2 font-bold text-xs sm:text-sm transition-all flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          Analytics
        </button>
      </nav>
    </div>

    <!-- ═══ TAB 1: REQUISITIONS ═══ -->
    <!-- FIX 2: class="tab-panel" on every content div -->
    <div id="tab-requisitions" class="tab-panel flex-1 p-4 sm:p-6 space-y-6 animate-fade-in-up">

      <!-- Director validation queue -->
      <?php if ($php_director): ?>
      <div id="directorQueueSection" class="hidden">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-display font-bold text-gray-900 flex items-center gap-2 text-sm">
            <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse inline-block"></span>
            Awaiting Your Director Validation
          </h3>
          <span id="dirQueueCount" class="text-xs text-gray-400 font-bold"></span>
        </div>
        <div id="directorQueueList" class="space-y-3"></div>
      </div>
      <?php endif; ?>

      <!-- FIX 13: active states baked in with Tailwind directly -->
      <!-- FIX 3: onclick passes this → setFilter(status, this) -->
      <div>
        <div class="flex gap-2 flex-wrap mb-4">
          <button onclick="setFilter('all',this)" data-filter-value="all"
            class="filter-chip bg-hodBlue text-white border-hodBlue px-3 py-1.5 rounded-full text-xs font-bold transition-all border">All</button>
          <button onclick="setFilter('Pending_Director',this)" data-filter-value="Pending_Director"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Pending Director</button>
          <button onclick="setFilter('Pending_Pastor',this)" data-filter-value="Pending_Pastor"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Pending Pastor</button>
          <button onclick="setFilter('Revision_Required',this)" data-filter-value="Revision_Required"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Revision Needed</button>
          <button onclick="setFilter('Approved',this)" data-filter-value="Approved"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Approved</button>
          <button onclick="setFilter('Disbursed',this)" data-filter-value="Disbursed"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Disbursed</button>
          <button onclick="setFilter('Rejected',this)" data-filter-value="Rejected"
            class="filter-chip bg-white text-gray-600 border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold transition-all border">Rejected</button>
        </div>
        <div id="reqListContainer" class="space-y-3">
          <div class="text-center py-16 text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <p class="text-sm font-medium">No requisitions yet</p>
          </div>
        </div>
        <div class="mt-4 text-center hidden" id="loadMoreBtn">
          <button onclick="loadMoreReqs()" class="text-sm font-bold text-hodBlue hover:underline px-4 py-2">Load more...</button>
        </div>
      </div>
    </div><!-- end tab-requisitions -->

    <!-- ═══ TAB 2: APPROVAL ═══ -->
    <?php if ($php_pastor): ?>
    <div id="tab-approval" class="tab-panel hidden flex-1 p-4 sm:p-6 space-y-5 animate-fade-in-up">
      <!-- FIX 3: pass this -->
      <div class="flex items-center gap-3 flex-wrap">
        <button onclick="setApprovalFilter('all',this)"
          class="approval-filter bg-hodBlue text-white border-hodBlue px-4 py-2 rounded-xl text-xs font-bold border transition-all">All Pending</button>
        <button onclick="setApprovalFilter('pending',this)"
          class="approval-filter bg-white text-gray-600 border-gray-200 px-4 py-2 rounded-xl text-xs font-bold border transition-all">Awaiting Review</button>
        <button onclick="setApprovalFilter('revision',this)"
          class="approval-filter bg-white text-gray-600 border-gray-200 px-4 py-2 rounded-xl text-xs font-bold border transition-all">Revision Submitted</button>
      </div>
      <div id="approvalQueueList" class="space-y-4">
        <div class="text-center py-12 text-gray-400 text-sm">Select a filter above to load the queue.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══ TAB 3: FINANCE ═══ -->
    <div id="tab-finance" class="tab-panel hidden flex-1 p-4 sm:p-6 animate-fade-in-up">
      <div class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Available -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-display font-bold text-gray-900 text-sm flex items-center gap-2">
                <span class="w-2 h-2 bg-green-500 rounded-full"></span> Approved — Ready to Batch
              </h3>
              <span id="availableCount" class="text-xs text-gray-400 font-bold"></span>
            </div>
            <div id="availableReqList" class="space-y-2 max-h-96 overflow-y-auto custom-scrollbar"></div>
          </div>
          <!-- Draft batch builder -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-display font-bold text-gray-900 text-sm flex items-center gap-2">
                <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Current Batch
              </h3>
              <button onclick="openCreateBatchModal()" id="createBatchBtn" class="text-xs font-bold text-hodBlue hover:underline">+ Create Batch</button>
            </div>
            <div id="draftBatchContainer">
              <div class="border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center text-gray-400">
                <p class="text-xs font-medium">No active batch. Create one to begin grouping requisitions.</p>
              </div>
            </div>
          </div>
        </div>
        <!-- Batch history -->
        <div>
          <h3 class="font-display font-bold text-gray-900 text-sm mb-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Batch History
          </h3>
          <div id="batchHistoryList" class="space-y-2"></div>
        </div>
      </div>
    </div>

    <!-- ═══ TAB 4: ANALYTICS ═══ -->
    <div id="tab-analytics" class="tab-panel hidden flex-1 p-4 sm:p-6 space-y-6 animate-fade-in-up">
      <!-- FIX 3: pass this to setPeriod -->
      <div class="flex flex-wrap gap-2 items-center">
        <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
          <button onclick="setPeriod('month',this)"   class="period-btn text-gray-600 px-3 py-2 rounded-lg text-xs font-bold transition-all">Month</button>
          <button onclick="setPeriod('quarter',this)" class="period-btn bg-white text-hodBlue shadow-sm px-3 py-2 rounded-lg text-xs font-bold transition-all">Quarter</button>
          <button onclick="setPeriod('year',this)"    class="period-btn text-gray-600 px-3 py-2 rounded-lg text-xs font-bold transition-all">Year</button>
          <button onclick="setPeriod('custom',this)"  class="period-btn text-gray-600 px-3 py-2 rounded-lg text-xs font-bold transition-all">Custom</button>
        </div>
        <div id="customDateRange" class="hidden flex gap-2 items-center">
          <input type="date" id="analyticsStart" class="text-xs border border-gray-200 rounded-lg px-2 py-2 outline-none focus:border-hodBlue">
          <span class="text-gray-400 text-xs">to</span>
          <input type="date" id="analyticsEnd" class="text-xs border border-gray-200 rounded-lg px-2 py-2 outline-none focus:border-hodBlue">
          <button onclick="loadAnalytics()" class="text-xs font-bold text-hodBlue hover:underline">Apply</button>
        </div>
        <select id="analyticsDeptFilter" onchange="loadAnalytics()" class="text-xs border border-gray-200 rounded-xl px-3 py-2 outline-none focus:border-hodBlue bg-white font-bold">
          <option value="">All Departments</option>
        </select>
        <span id="analyticsPeriodLabel" class="text-xs text-gray-400 font-medium"></span>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-blue-50 rounded-2xl p-4 border border-blue-100"><p class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Total Requests</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiTotal">—</p></div>
        <div class="bg-green-50 rounded-2xl p-4 border border-green-100"><p class="text-[10px] font-bold text-green-600 uppercase tracking-wider">Total Amount</p><p class="text-xl font-black text-gray-900 mt-1" id="kpiAmount">—</p></div>
        <div class="bg-purple-50 rounded-2xl p-4 border border-purple-100"><p class="text-[10px] font-bold text-purple-600 uppercase tracking-wider">Avg Cycle</p><p class="text-2xl font-black text-gray-900 mt-1" id="kpiCycle">—</p><p class="text-[10px] text-gray-400">days to approval</p></div>
        <div class="bg-amber-50 rounded-2xl p-4 border border-amber-100"><p class="text-[10px] font-bold text-amber-600 uppercase tracking-wider">Disbursed</p><p class="text-xl font-black text-gray-900 mt-1" id="kpiDisbursed">—</p></div>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
          <div class="bg-gray-50 px-4 py-3 border-b border-gray-100"><h3 class="font-bold text-gray-900 text-sm">Status Breakdown</h3></div>
          <div class="p-4 flex items-center justify-center" style="height:220px"><canvas id="statusDonutChart"></canvas></div>
        </div>
        <div class="border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
          <div class="bg-gray-50 px-4 py-3 border-b border-gray-100"><h3 class="font-bold text-gray-900 text-sm">Monthly Trend (12 months)</h3></div>
          <div class="p-4" style="height:220px"><canvas id="trendBarChart"></canvas></div>
        </div>
      </div>
      <div id="deptComparisonSection" class="hidden border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
        <div class="bg-gray-50 px-4 py-3 border-b border-gray-100"><h3 class="font-bold text-gray-900 text-sm">Department Spend Comparison</h3></div>
        <div class="p-4" style="min-height:180px"><canvas id="deptBarChart"></canvas></div>
      </div>
      <div class="border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
        <div class="bg-gray-50 px-4 py-3 border-b border-gray-100"><h3 class="font-bold text-gray-900 text-sm">Top 10 Expense Lines</h3></div>
        <div class="p-4 space-y-2" id="topItemsList"><p class="text-xs text-gray-400 text-center py-4">Select a period to view data.</p></div>
      </div>
      <div id="batchHistoryAnalyticsSection" class="hidden border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
        <div class="bg-gray-50 px-4 py-3 border-b border-gray-100"><h3 class="font-bold text-gray-900 text-sm">Batch Disbursement History</h3></div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs text-left">
            <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider border-b border-gray-100">
              <tr><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Items</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3">Disbursed</th></tr>
            </thead>
            <tbody id="batchHistoryTableBody" class="divide-y divide-gray-50"></tbody>
          </table>
        </div>
      </div>
    </div><!-- end tab-analytics -->

  </div><!-- end main card -->
</div><!-- end max-w -->


<!-- ══════════════════════════════════════════════════════════
     MODAL: SUBMIT NEW REQUISITION
══════════════════════════════════════════════════════════ -->
<div id="submitModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-2xl rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[95vh] sm:max-h-[88vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-3xl shrink-0">
      <div>
        <h3 class="font-display font-bold text-gray-900">New Requisition</h3>
        <p class="text-xs text-gray-400 mt-0.5">All fields marked * are required.</p>
      </div>
      <button onclick="closeModal('submitModal')" class="text-gray-400 hover:text-red-500 p-1 transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div id="windowWarning" class="hidden mx-5 mt-4 bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 font-medium flex items-start gap-2 shrink-0">
      <svg class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      <span id="windowWarningText"></span>
    </div>
    <!-- FIX 8: no form.reset() — manual clear in openSubmitModal -->
    <form id="submitForm" enctype="multipart/form-data" class="flex flex-col flex-1 overflow-hidden">
      <input type="hidden" name="action" value="submit_requisition">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="flex-1 overflow-y-auto custom-scrollbar p-5 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Request Type *</label>
            <select name="type" id="reqType" onchange="toggleReqType(this.value)" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-bold text-sm">
              <option value="Monthly">Monthly Requisition</option>
              <option value="Occasional">Occasional / Event-Based</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Department *</label>
            <select name="department_id" id="reqDept" required class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-bold text-sm">
              <option value="">Select department...</option>
            </select>
            <p id="reqDeptEmpty" class="hidden text-xs text-amber-600 mt-1 font-medium">No departments found for your role. Contact your administrator.</p>
          </div>
        </div>
        <!-- Monthly period fields -->
        <div id="monthlyFields" class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Month *</label>
            <select name="period_month" id="reqMonth" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm font-bold">
              <?php
              $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
              foreach ($months as $i => $m) {
                  $sel = ((int)date('n') === $i + 1) ? 'selected' : '';
                  echo "<option value=\"".($i+1)."\" $sel>$m</option>";
              }
              ?>
            </select>
          </div>
          <div>
            <!-- FIX 12: year range current-2 → current+1 -->
            <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Year *</label>
            <select name="period_year" id="reqYear" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm font-bold">
              <?= $year_options ?>
            </select>
          </div>
        </div>
        <!-- Occasional fields -->
        <div id="occasionalFields" class="hidden">
          <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Event / Purpose *</label>
          <input type="text" name="event_label" id="reqEventLabel" placeholder="e.g. Easter Convention, Youth Workshop..." class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm">
        </div>
        <!-- Line items -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-bold text-gray-600 uppercase">Expense Lines *</label>
            <button type="button" onclick="addLineItem()" class="text-xs font-bold text-hodBlue hover:underline flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
              Add Line
            </button>
          </div>
          <div class="hidden sm:grid grid-cols-12 gap-1 mb-1 px-1 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
            <div class="col-span-4">Description</div><div class="col-span-2">Qty</div><div class="col-span-2">Unit ₦</div><div class="col-span-2">Category</div><div class="col-span-1 text-right">Total</div><div class="col-span-1"></div>
          </div>
          <div id="lineItemsContainer" class="space-y-2"></div>
          <div class="mt-3 flex justify-end items-center gap-3 border-t border-gray-100 pt-3">
            <span class="text-xs font-bold text-gray-500 uppercase">Grand Total:</span>
            <span id="grandTotal" class="text-xl font-black text-hodBlue">₦0.00</span>
          </div>
        </div>
        <!-- Note -->
        <div>
          <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Submission Note <span class="text-gray-400 font-normal normal-case">(optional)</span></label>
          <textarea id="reqNote" name="submission_note" rows="2" placeholder="Any context for the Director or Pastor..." class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm resize-none"></textarea>
        </div>
        <!-- Attachments -->
        <div>
          <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Supporting Documents <span class="text-gray-400 font-normal normal-case">(PDF/JPG/PNG — max 5MB each)</span></label>
          <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center cursor-pointer hover:border-hodBlue transition-colors relative">
            <svg class="w-8 h-8 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
            <p class="text-xs text-gray-400">Tap to upload proforma invoices or proof of cost</p>
            <input type="file" id="attachmentsInput" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewAttachments(this)">
          </div>
          <div id="attachmentPreviews" class="mt-2 space-y-1"></div>
        </div>
      </div>
      <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0 rounded-b-3xl">
        <button type="submit" id="submitBtn" class="w-full bg-hodBlue hover:bg-blue-900 text-white py-4 rounded-xl font-bold transition-all shadow-md text-sm">Submit Requisition</button>
      </div>
    </form>
  </div>
</div>

<!-- DRAWER: REQUISITION DETAIL -->
<div id="detailDrawer" class="fixed inset-0 z-[9998] hidden flex justify-end bg-gray-900/60 backdrop-blur-sm opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-xl h-full flex flex-col shadow-2xl transform translate-x-full transition-transform duration-300" id="detailDrawerPanel">
    <div class="p-5 border-b border-gray-100 flex items-start justify-between bg-gray-50 shrink-0">
      <div>
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Requisition Detail</p>
        <h3 id="drawerRefNumber" class="font-display font-bold text-gray-900 text-lg mt-0.5">—</h3>
        <div id="drawerStatusBadge" class="mt-1"></div>
      </div>
      <button onclick="closeDetailDrawer()" class="text-gray-400 hover:text-red-500 p-1 mt-1 transition-colors shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="detailDrawerContent" class="flex-1 overflow-y-auto custom-scrollbar p-5 space-y-5"></div>
    <div id="detailDrawerActions" class="p-4 border-t border-gray-100 bg-gray-50 shrink-0 flex flex-wrap gap-2"></div>
  </div>
</div>

<!-- MODAL: DIRECTOR REJECTION -->
<div id="dirRejectModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-md rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[80vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center">
      <h3 class="font-bold text-gray-900">Reject Requisition</h3>
      <button onclick="closeModal('dirRejectModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto space-y-4">
      <p class="text-sm text-gray-500">State clearly why you are rejecting this requisition. The submitter will be notified.</p>
      <input type="hidden" id="dirRejectReqId">
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Rejection Reason *</label>
        <textarea id="dirRejectReason" rows="4" placeholder="Explain your reason..." class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-red-400 text-sm resize-none"></textarea>
      </div>
    </div>
    <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0">
      <button onclick="submitDirectorRejection()" class="w-full bg-red-600 hover:bg-red-700 text-white py-3.5 rounded-xl font-bold transition-all">Reject &amp; Notify Submitter</button>
    </div>
  </div>
</div>

<!-- MODAL: LINE REJECTION (PASTOR) -->
<div id="lineRejectModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-lg rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[90vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center">
      <div><h3 class="font-bold text-gray-900">Flag Lines for Revision</h3><p class="text-xs text-gray-400 mt-0.5">Director stamp is preserved. Only flagged lines return to submitter.</p></div>
      <button onclick="closeModal('lineRejectModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto custom-scrollbar space-y-4">
      <input type="hidden" id="lineRejectReqId">
      <div id="lineRejectItemsContainer" class="space-y-3"></div>
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Overall Notes <span class="font-normal text-gray-400">(optional)</span></label>
        <textarea id="lineRejectNotes" rows="2" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-amber-400 text-sm resize-none"></textarea>
      </div>
    </div>
    <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0">
      <button onclick="submitLineRejections()" class="w-full bg-amber-500 hover:bg-amber-600 text-white py-3.5 rounded-xl font-bold transition-all">Send for Revision</button>
    </div>
  </div>
</div>

<!-- MODAL: FULL REJECTION (PASTOR) -->
<div id="fullRejectModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-md rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[80vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center bg-red-50">
      <h3 class="font-bold text-red-700">Fully Reject Requisition</h3>
      <button onclick="closeModal('fullRejectModal')" class="text-red-400 hover:text-red-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto space-y-4">
      <div class="bg-red-50 border border-red-100 rounded-xl p-3 text-xs text-red-700">⚠ This permanently rejects the requisition. The submitter must resubmit from scratch.</div>
      <input type="hidden" id="fullRejectReqId">
      <textarea id="fullRejectReason" rows="4" placeholder="State clearly why this entire requisition is rejected..." class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-red-400 text-sm resize-none"></textarea>
    </div>
    <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0">
      <button onclick="submitFullRejection()" class="w-full bg-red-600 hover:bg-red-700 text-white py-3.5 rounded-xl font-bold transition-all">Fully Reject — Notify Submitter</button>
    </div>
  </div>
</div>

<!-- MODAL: REVISION EDIT -->
<div id="revisionModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-xl rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[90vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center">
      <div><h3 class="font-bold text-gray-900">Revise Rejected Lines</h3><p class="text-xs text-gray-400 mt-0.5">Only lines flagged by the Pastor can be edited.</p></div>
      <button onclick="closeModal('revisionModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto custom-scrollbar space-y-4">
      <input type="hidden" id="revisionReqId">
      <div id="revisionItemsContainer" class="space-y-4"></div>
    </div>
    <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0">
      <button onclick="submitRevision()" class="w-full bg-hodBlue hover:bg-blue-900 text-white py-3.5 rounded-xl font-bold transition-all">Submit Revision — Notify Pastor</button>
    </div>
  </div>
</div>

<!-- MODAL: PASTORAL APPROVAL -->
<div id="approveModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-lg rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[90vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center bg-gray-50">
      <div><h3 class="font-bold text-gray-900">Pastoral Approval</h3><p class="text-xs text-gray-400 mt-0.5">Your signature is legally binding on this document.</p></div>
      <button onclick="closeModal('approveModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto custom-scrollbar space-y-4">
      <input type="hidden" id="approveReqId">
      <input type="hidden" id="approveSignatureB64">
      <div class="border border-gray-200 rounded-2xl overflow-hidden">
        <div class="bg-gray-50 px-4 py-3 border-b border-gray-100 flex items-center justify-between">
          <p class="text-xs font-bold text-gray-600 uppercase tracking-wider">Resident Pastor Signature</p>
          <div class="flex gap-2">
            <button onclick="openSignaturePad('approve')" class="text-xs font-bold text-hodBlue bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors">Draw</button>
            <button id="importSigBtn" onclick="openPinModal()" class="text-xs font-bold text-green-600 bg-green-50 px-3 py-1.5 rounded-lg hover:bg-green-100 transition-colors hidden">Import Stored</button>
          </div>
        </div>
        <div class="p-4 min-h-[80px] flex items-center justify-center">
          <div id="sigPreviewContainer" class="hidden text-center w-full">
            <img id="sigPreviewImg" src="" alt="Signature" class="max-h-24 mx-auto border border-gray-100 rounded-lg bg-white p-2">
            <button onclick="clearApproveSignature()" class="mt-2 text-xs text-gray-400 hover:text-red-500 transition-colors">Clear &amp; redo</button>
          </div>
          <div id="sigPlaceholder" class="text-center text-gray-400">
            <svg class="w-10 h-10 mx-auto text-gray-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            <p class="text-xs font-medium">Draw or import your stored signature</p>
          </div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
        <textarea id="approveNotes" rows="2" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm resize-none"></textarea>
      </div>
    </div>
    <div class="p-5 border-t border-gray-100 bg-gray-50 shrink-0">
      <button onclick="submitPastoralApproval()" class="w-full bg-green-600 hover:bg-green-700 text-white py-4 rounded-xl font-bold transition-all shadow-md">Confirm Approval with Signature</button>
    </div>
  </div>
</div>

<!-- FULL-SCREEN: SIGNATURE PAD -->
<!-- FIX 5 & 7: scroll release on cancel; appended to body end in JS -->
<div id="signaturePadScreen" class="fixed inset-0 z-[99999] hidden bg-white flex flex-col opacity-0 transition-opacity duration-300">
  <div class="flex items-center justify-between p-4 border-b border-gray-100 bg-white shrink-0" style="padding-top: max(1rem, env(safe-area-inset-top))">
    <div>
      <p class="font-display font-bold text-gray-900">Sign Here</p>
      <p class="text-xs text-gray-400">Use your finger or stylus. Sign naturally.</p>
    </div>
    <div class="flex items-center gap-2">
      <button onclick="clearSignatureCanvas()" class="text-xs font-bold text-gray-500 bg-gray-100 px-4 py-2.5 rounded-xl hover:bg-gray-200 transition-colors">Clear</button>
      <button onclick="cancelSignaturePad()" class="text-xs font-bold text-red-500 bg-red-50 px-4 py-2.5 rounded-xl hover:bg-red-100 transition-colors">Cancel</button>
    </div>
  </div>
  <div class="flex-1 relative bg-gray-50/50 overflow-hidden">
    <canvas id="signatureCanvas" class="absolute inset-0 touch-none cursor-crosshair" style="width:100%;height:100%"></canvas>
    <div id="signatureHint" class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
      <div class="border-b-2 border-gray-300 border-dashed w-3/4 mb-4"></div>
      <p class="text-gray-300 text-sm font-light">Sign above the line</p>
    </div>
  </div>
  <div class="p-4 bg-white border-t border-gray-100 shrink-0" style="padding-bottom: max(1rem, env(safe-area-inset-bottom))">
    <button onclick="confirmSignaturePad()" class="w-full bg-hodBlue text-white py-4 rounded-xl font-bold text-base shadow-lg hover:bg-blue-900 transition-colors">✓ Confirm Signature</button>
  </div>
</div>

<!-- MODAL: SIGNATURE VAULT -->
<div id="vaultModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-md rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col max-h-[90vh] transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 shrink-0 flex justify-between items-center bg-gray-50">
      <div>
        <h3 class="font-bold text-gray-900 flex items-center gap-2">
          <svg class="w-4 h-4 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
          Signature Vault
        </h3>
        <p class="text-xs text-gray-400 mt-0.5">Store your signature for quick import on approvals</p>
      </div>
      <button onclick="closeModal('vaultModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 flex-1 overflow-y-auto custom-scrollbar space-y-4">
      <div id="vaultStatus" class="rounded-xl p-4 text-sm border bg-gray-50 border-gray-200 text-gray-600">Checking vault status...</div>
      <div id="vaultStoreSection">
        <p class="text-xs font-bold text-gray-600 uppercase mb-3">Store / Update Signature</p>
        <div class="border border-gray-200 rounded-xl overflow-hidden mb-3">
          <div class="bg-gray-50 px-4 py-2 border-b border-gray-100 flex items-center justify-between">
            <p class="text-xs font-bold text-gray-500">Signature Preview</p>
            <button onclick="openSignaturePad('vault')" class="text-xs font-bold text-hodBlue hover:underline">Draw</button>
          </div>
          <div class="p-4 text-center min-h-[64px] flex items-center justify-center">
            <div id="vaultSigPreview" class="hidden w-full"><img id="vaultSigImg" src="" alt="Vault signature" class="max-h-16 mx-auto border border-gray-100 rounded-lg bg-white p-1"></div>
            <div id="vaultSigPlaceholder" class="text-gray-300 text-xs">Draw your signature to store it</div>
          </div>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Set 4-Digit PIN *</label>
          <!-- FIX 10: autocomplete="new-password" prevents browser autofill -->
          <input type="password" id="vaultPin" inputmode="numeric" maxlength="4" pattern="\d{4}"
            autocomplete="new-password"
            placeholder="Enter 4 digits"
            class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-xl font-bold text-center tracking-[0.5em]">
          <p class="text-[10px] text-gray-400 mt-1">Required each time you import your stored signature</p>
        </div>
        <button onclick="saveSignatureVault()" class="w-full mt-3 bg-hodBlue text-white py-3.5 rounded-xl font-bold hover:bg-blue-900 transition-all">Store Signature Securely</button>
      </div>
      <div id="vaultDeleteSection" class="hidden border-t border-gray-100 pt-4">
        <p class="text-xs text-gray-400 mb-2 text-center">To delete your stored signature, enter your PIN:</p>
        <div class="flex gap-2">
          <!-- FIX 10: autocomplete="off" -->
          <input type="password" id="vaultDeletePin" inputmode="numeric" maxlength="4"
            autocomplete="off"
            placeholder="PIN"
            class="flex-1 px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-red-400 text-center font-bold tracking-[0.4em]">
          <button onclick="deleteSignatureVault()" class="text-xs font-bold text-red-600 bg-red-50 px-4 rounded-xl hover:bg-red-100 transition-colors border border-red-100">Delete</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: PIN ENTRY -->
<div id="pinModal" class="fixed inset-0 z-[99998] hidden flex items-end sm:items-center justify-center bg-gray-900/60 backdrop-blur-sm p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-xs rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 text-center">
      <div class="w-12 h-12 bg-hodBlue/10 rounded-full flex items-center justify-center mx-auto mb-2">
        <svg class="w-6 h-6 text-hodBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
      </div>
      <h3 class="font-bold text-gray-900">Enter Your PIN</h3>
      <p class="text-xs text-gray-400 mt-1">4-digit PIN to import your stored signature</p>
    </div>
    <div class="p-6 space-y-4">
      <!-- FIX 10: autocomplete="off", cleared AFTER visible in JS -->
      <input type="password" id="pinInput" inputmode="numeric" maxlength="4"
        autocomplete="off"
        placeholder="••••"
        class="w-full px-4 py-4 rounded-xl border-2 border-gray-200 outline-none focus:border-hodBlue text-3xl font-black text-center tracking-[0.6em] transition-colors">
      <p id="pinError" class="text-xs text-red-500 text-center hidden font-medium"></p>
      <button onclick="verifyAndImportSignature()" class="w-full bg-hodBlue text-white py-3.5 rounded-xl font-bold hover:bg-blue-900 transition-all">Import Signature</button>
      <button onclick="closeModal('pinModal')" class="w-full text-sm text-gray-400 hover:text-gray-600 transition-colors py-1">Cancel</button>
    </div>
  </div>
</div>

<!-- MODAL: CREATE BATCH -->
<div id="createBatchModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-md rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 flex justify-between items-center shrink-0">
      <h3 class="font-bold text-gray-900">Create Finance Batch</h3>
      <button onclick="closeModal('createBatchModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 space-y-4">
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Batch Label <span class="font-normal text-gray-400">(optional)</span></label>
        <input type="text" id="batchLabel" placeholder="e.g. May Week 3 Disbursement" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm">
      </div>
      <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-xs text-blue-700">A batch reference is auto-generated from the current ISO week. Add requisitions to it after creation.</div>
      <button onclick="submitCreateBatch()" class="w-full bg-hodBlue text-white py-3.5 rounded-xl font-bold hover:bg-blue-900 transition-all">Create Batch</button>
    </div>
  </div>
</div>

<!-- MODAL: DISBURSE BATCH -->
<div id="disburseModal" class="fixed inset-0 z-[9999] hidden flex items-end sm:items-center justify-center bg-gray-900/80 backdrop-blur-md p-0 sm:p-4 opacity-0 transition-opacity duration-300">
  <div class="bg-white w-full sm:max-w-md rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col transform translate-y-8 sm:scale-95 transition-all duration-300">
    <div class="p-5 border-b border-gray-100 flex justify-between items-center shrink-0">
      <h3 class="font-bold text-gray-900">Mark Batch as Disbursed</h3>
      <button onclick="closeModal('disburseModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-5 space-y-4">
      <input type="hidden" id="disburseBatchId">
      <p class="text-sm text-gray-500">This auto-posts all requisitions as Expense entries in the Finance Ledger.</p>
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Debit Ledger Account *</label>
        <select id="disburseAccount" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm font-bold"><option value="">Select account...</option></select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase mb-1.5">Virtual Fund / Wallet *</label>
        <select id="disburseFund" class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm font-bold"><option value="">Select fund...</option></select>
      </div>
      <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-xs text-amber-700 font-medium">Confirm that funds have been physically disbursed to HQ before proceeding.</div>
      <button onclick="submitDisburse()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3.5 rounded-xl font-bold transition-all">Confirm Disbursement &amp; Post to Ledger</button>
    </div>
  </div>
</div>

<!-- Global action blocker -->
<div id="globalBlocker" class="fixed inset-0 z-[999999] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed opacity-0 transition-opacity duration-300">
  <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
    <svg class="animate-spin h-6 w-6 text-hodBlue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
    <span class="font-bold text-gray-700 text-sm">Processing...</span>
  </div>
</div>


<script>
// ═══════════════════════════════════════════════════════
// CONSTANTS & GLOBALS
// ═══════════════════════════════════════════════════════
const API_URL    = '../../api/requisition_api.php';
const CSRF_TOKEN = '<?= $csrf ?>';
const PHP_PASTOR = <?= json_encode($php_pastor) ?>;
const PHP_DIR    = <?= json_encode($php_director) ?>;
// FIX 4 — integer cast with fallback
const myId = <?= $my_id_php ?>;

let userFlags          = {};
let formDepts          = [];
let formCategories     = [];
let formWindowWarning  = null;    // store warning for re-display on modal open
let lineItemCounter    = 0;
let currentFilter      = 'all';
let currentReqPage     = 1;
let currentDraftBatch  = null;
let currentEditReqId   = null;
let sigCtx = null, sigCanvas = null, sigDrawing = false, sigLastX = 0, sigLastY = 0;
let sigTarget          = 'approve'; // 'approve' | 'vault'
let chartStatus = null, chartTrend = null, chartDept = null;
let currentAnalyticsPeriod = 'quarter';

// ═══════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════
function showToast(msg, type = 'success') {
    Toastify({
        text: msg, gravity: 'top', position: 'center', duration: 3500,
        style: {
            background: type === 'success' ? '#10B981' : type === 'warning' ? '#F59E0B' : '#EF4444',
            borderRadius: '10px', fontWeight: 'bold', boxShadow: '0 10px 25px rgba(0,0,0,0.2)'
        }
    }).showToast();
}

function lock()   {
    const b = document.getElementById('globalBlocker');
    b.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    setTimeout(() => b.classList.remove('opacity-0'), 10);
}
function unlock() {
    const b = document.getElementById('globalBlocker');
    b.classList.add('opacity-0');
    setTimeout(() => {
        b.classList.add('hidden');
        if (!document.querySelectorAll('.modal-open').length) document.body.style.overflow = '';
    }, 300);
}

function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    document.body.appendChild(m);   // move to body-end for reliable z-index stacking
    m.classList.remove('hidden');
    m.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        m.classList.remove('opacity-0');
        const panel = m.children[0];
        if (panel) panel.classList.remove('translate-y-8', 'scale-95', 'translate-y-full');
    });
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('opacity-0');
    const panel = m.children[0];
    if (panel) panel.classList.add('translate-y-8', 'scale-95');
    m.classList.remove('modal-open');
    setTimeout(() => {
        m.classList.add('hidden');
        if (!document.querySelectorAll('.modal-open').length) document.body.style.overflow = '';
    }, 300);
}

function fmtCurrency(v) { return '₦' + parseFloat(v || 0).toLocaleString('en-NG', { minimumFractionDigits: 2 }); }
function fmtDate(ds) { if (!ds) return '—'; return new Date(ds).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}); }
function fmtDateTime(ds) { if (!ds) return '—'; return new Date(ds).toLocaleString('en-GB', {day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function timeAgo(ds) {
    const diff = Math.floor((Date.now() - new Date(ds)) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

const STATUS_CONFIG = {
    'Draft':            { label:'Draft',             cls:'bg-gray-100 text-gray-600',     dot:'bg-gray-400' },
    'Pending_Director': { label:'Pending Director',  cls:'bg-amber-100 text-amber-700',   dot:'bg-amber-500 animate-pulse' },
    'Pending_Pastor':   { label:'Pending Pastor',    cls:'bg-blue-100 text-blue-700',     dot:'bg-blue-500 animate-pulse' },
    'Revision_Required':{ label:'Revision Needed',   cls:'bg-orange-100 text-orange-700', dot:'bg-orange-500 animate-pulse' },
    'Approved':         { label:'Approved',          cls:'bg-green-100 text-green-700',   dot:'bg-green-500' },
    'Batched':          { label:'Batched',           cls:'bg-violet-100 text-violet-700', dot:'bg-violet-500' },
    'Disbursed':        { label:'Disbursed ✓',       cls:'bg-emerald-100 text-emerald-700',dot:'bg-emerald-500' },
    'Rejected':         { label:'Rejected',          cls:'bg-red-100 text-red-700',       dot:'bg-red-500' },
    'Cancelled':        { label:'Cancelled',         cls:'bg-slate-100 text-slate-500',   dot:'bg-slate-400' },
};
function statusBadge(status) {
    const c = STATUS_CONFIG[status] || { label: status, cls:'bg-gray-100 text-gray-600', dot:'bg-gray-400' };
    return `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider ${c.cls}">
        <span class="w-1.5 h-1.5 rounded-full ${c.dot} inline-block"></span>${c.label}</span>`;
}

// ═══════════════════════════════════════════════════════
// FIX 2: TAB MANAGEMENT  — uses .tab-panel, not [id^="tab-"]
// ═══════════════════════════════════════════════════════
function switchTab(id) {
    // Style all tab buttons as inactive
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('border-hodBlue', 'text-hodBlue');
        b.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700');
    });
    // Hide all content panels (only .tab-panel, never tab buttons)
    document.querySelectorAll('.tab-panel').forEach(t => t.classList.add('hidden'));

    // Activate selected tab button
    const btn = document.getElementById(`tab-btn-${id}`);
    if (btn) {
        btn.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700');
        btn.classList.add('border-hodBlue', 'text-hodBlue');
    }
    // Show selected panel
    const panel = document.getElementById(`tab-${id}`);
    if (panel) panel.classList.remove('hidden');

    // Lazy-load data
    if (id === 'requisitions') { loadMyRequisitions(); if (PHP_DIR) loadDirectorQueue(); }
    if (id === 'approval')     { loadApprovalQueue('all'); }
    if (id === 'finance')      { loadFinanceQueue(); }
    if (id === 'analytics')    { loadAnalytics(); }
}

// ═══════════════════════════════════════════════════════
// INITIAL DATA LOAD
// ═══════════════════════════════════════════════════════
function loadFormData() {
    $.post(API_URL, { action: 'fetch_form_data' }, function(res) {
        if (res.status !== 'success') {
            showLoadError();
            return;
        }

        userFlags      = res.flags || {};
        formDepts      = res.my_departments || [];
        formCategories = res.expense_categories || [];
        formWindowWarning = res.window_warning || null;

        // Populate department dropdown
        let dOpts = '<option value="">Select department...</option>';
        formDepts.forEach(d => { dOpts += `<option value="${d.id}">${d.name}</option>`; });
        document.getElementById('reqDept').innerHTML = dOpts;

        // Show warning if no departments
        if (!formDepts.length) {
            document.getElementById('reqDeptEmpty').classList.remove('hidden');
        }

        // Show Finance tab for finance directors, pastors, super admins
        if (userFlags.is_finance_director || userFlags.is_super_admin || userFlags.is_resident_pastor) {
            const fb = document.getElementById('tab-btn-finance');
            if (fb) { fb.classList.remove('hidden'); fb.classList.add('flex'); }
        }

        // Show import signature button if vault exists
        if (res.has_stored_signature) {
            document.getElementById('importSigBtn')?.classList.remove('hidden');
        }

        // Hide loading overlay
        document.getElementById('loadingOverlay').classList.add('hidden');

        // Load initial tab
        switchTab('requisitions');
        loadBadgeCounts();

    }, 'json').fail(function() {
        showLoadError();
    });
}

function showLoadError() {
    document.getElementById('loadingSpinner').classList.add('hidden');
    document.getElementById('loadingText').classList.add('hidden');
    document.getElementById('loadingError').classList.remove('hidden');
    document.getElementById('loadingOverlay').classList.remove('hidden');
}

function loadBadgeCounts() {
    if (PHP_DIR) {
        $.post(API_URL, { action: 'fetch_director_queue' }, function(res) {
            if (res.status === 'success' && res.queue.length) {
                const b = document.getElementById('badge-req');
                if (b) { b.textContent = res.queue.length; b.classList.remove('hidden'); }
            }
        }, 'json');
    }
    if (PHP_PASTOR) {
        $.post(API_URL, { action: 'fetch_approval_queue', filter: 'all' }, function(res) {
            if (res.status === 'success' && res.queue.length) {
                const b = document.getElementById('badge-approval');
                if (b) { b.textContent = res.queue.length; b.classList.remove('hidden'); }
            }
        }, 'json');
    }
    $.post(API_URL, { action: 'fetch_finance_queue' }, function(res) {
        if (res.status === 'success' && res.available_requisitions?.length) {
            const b = document.getElementById('badge-finance');
            if (b) { b.textContent = res.available_requisitions.length; b.classList.remove('hidden'); }
        }
    }, 'json');
}

// ═══════════════════════════════════════════════════════
// FIX 3: FILTER — receives el from onclick, no implicit event
// ═══════════════════════════════════════════════════════
function setFilter(status, el) {
    currentFilter = status;
    currentReqPage = 1;
    // Deactivate all chips
    document.querySelectorAll('.filter-chip').forEach(c => {
        c.classList.remove('bg-hodBlue', 'text-white', 'border-hodBlue');
        c.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
    });
    // Activate clicked chip
    if (el) {
        el.classList.add('bg-hodBlue', 'text-white', 'border-hodBlue');
        el.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
    }
    loadMyRequisitions();
}

function loadMoreReqs() { currentReqPage++; loadMyRequisitions(true); }

function loadMyRequisitions(append = false) {
    const container = document.getElementById('reqListContainer');
    if (!append) {
        container.innerHTML = '<div class="text-center py-8"><svg class="animate-spin h-6 w-6 mx-auto text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></div>';
    }
    $.post(API_URL, { action: 'fetch_my_requisitions', filter_status: currentFilter, page: currentReqPage, limit: 20 }, function(res) {
        if (res.status !== 'success') { showToast('Could not load requisitions', 'error'); return; }
        const reqs = res.requisitions || [];
        let html = '';
        if (!reqs.length && !append) {
            html = `<div class="text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p class="text-sm font-medium">No requisitions match this filter</p></div>`;
        } else {
            reqs.forEach(r => { html += renderReqCard(r); });
        }
        if (append) container.insertAdjacentHTML('beforeend', html);
        else container.innerHTML = html;
        document.getElementById('loadMoreBtn').classList.toggle('hidden', reqs.length < 20);
    }, 'json');
}

function renderReqCard(r) {
    const period = r.type === 'Monthly'
        ? new Date(r.period_year, r.period_month - 1).toLocaleDateString('en-GB',{month:'long',year:'numeric'})
        : (r.event_label || '');
    const amt   = fmtCurrency(r.total_amount);
    const stamp = r.has_director_stamp ? `<span class="text-[10px] text-blue-600 font-bold">✓ Stamped</span>` : '';
    // FIX 14: ownership check correctly declared and used below in actions
    const isOwner = parseInt(r.submitted_by) === myId;

    return `
    <div class="border border-gray-100 rounded-2xl p-4 hover:shadow-md transition-all bg-white cursor-pointer" onclick="openDetailDrawer(${r.id})">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2 flex-wrap">
            <p class="font-display font-bold text-gray-900 text-sm">${r.ref_number}</p>
            ${statusBadge(r.status)}
          </div>
          <p class="text-xs text-gray-500 mt-1 truncate">${r.department_name} — ${period}</p>
          <p class="text-xs text-gray-400 mt-0.5">${r.item_count} line${r.item_count != 1 ? 's' : ''} ${r.rejected_item_count > 0 ? `<span class="text-orange-500 font-bold">• ${r.rejected_item_count} flagged</span>` : ''} ${stamp}</p>
        </div>
        <div class="text-right shrink-0">
          <p class="font-black text-gray-900 text-base">${amt}</p>
          <p class="text-[10px] text-gray-400">${timeAgo(r.updated_at)}</p>
        </div>
      </div>
      ${r.status === 'Revision_Required' ? `
      <div class="mt-3 pt-3 border-t border-orange-100 flex gap-2" onclick="event.stopPropagation()">
        <button onclick="openRevisionModal(${r.id})" class="flex-1 bg-orange-50 text-orange-700 text-xs font-bold py-2.5 rounded-xl hover:bg-orange-100 transition-colors border border-orange-100">
          ✏️ Revise Flagged Lines
        </button>
      </div>` : ''}
      ${(r.status === 'Pending_Director' && PHP_DIR) ? `
      <div class="mt-3 pt-3 border-t border-amber-100 flex gap-2" onclick="event.stopPropagation()">
        <button onclick="openDetailDrawer(${r.id})" class="flex-1 bg-amber-50 text-amber-700 text-xs font-bold py-2.5 rounded-xl hover:bg-amber-100 transition-colors border border-amber-100">
          Validate as Director
        </button>
        <button onclick="openDirRejectModal(${r.id})" class="bg-red-50 text-red-600 text-xs font-bold px-3 py-2.5 rounded-xl hover:bg-red-100 transition-colors border border-red-100">Reject</button>
      </div>` : ''}
    </div>`;
}

// ═══════════════════════════════════════════════════════
// DIRECTOR QUEUE
// ═══════════════════════════════════════════════════════
function loadDirectorQueue() {
    $.post(API_URL, { action: 'fetch_director_queue' }, function(res) {
        const section = document.getElementById('directorQueueSection');
        if (res.status !== 'success' || !res.queue.length) { section?.classList.add('hidden'); return; }
        section?.classList.remove('hidden');
        document.getElementById('dirQueueCount').textContent = res.queue.length + ' pending';
        document.getElementById('directorQueueList').innerHTML = res.queue.map(r => {
            const period = r.type === 'Monthly'
                ? new Date(r.period_year, r.period_month - 1).toLocaleDateString('en-GB',{month:'long',year:'numeric'})
                : (r.event_label || '');
            return `
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-center justify-between gap-3">
              <div class="min-w-0 flex-1">
                <p class="font-bold text-sm text-gray-900">${r.ref_number} <span class="text-xs font-normal text-gray-500">— ${r.department_name}</span></p>
                <p class="text-xs text-gray-500 mt-0.5">${period} • ${r.item_count} items • ${fmtCurrency(r.total_amount)}</p>
                <p class="text-xs text-gray-400 mt-0.5">by ${r.submitted_by_name} • ${timeAgo(r.created_at)}</p>
              </div>
              <div class="flex flex-col gap-1.5 shrink-0">
                <button onclick="openDetailDrawer(${r.id})" class="text-xs font-bold text-white bg-hodBlue px-3 py-2 rounded-lg hover:bg-blue-900 transition-colors">Review</button>
                <button onclick="openDirRejectModal(${r.id})" class="text-xs font-bold text-red-600 bg-red-50 px-3 py-2 rounded-lg hover:bg-red-100 transition-colors">Reject</button>
              </div>
            </div>`;
        }).join('');
    }, 'json');
}

// ═══════════════════════════════════════════════════════
// DETAIL DRAWER
// ═══════════════════════════════════════════════════════
function openDetailDrawer(reqId) {
    currentEditReqId = reqId;
    const drawer  = document.getElementById('detailDrawer');
    const panel   = document.getElementById('detailDrawerPanel');
    const content = document.getElementById('detailDrawerContent');
    const actions = document.getElementById('detailDrawerActions');

    content.innerHTML = '<div class="text-center py-12"><svg class="animate-spin h-7 w-7 mx-auto text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></div>';
    actions.innerHTML = '';

    drawer.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => { drawer.classList.remove('opacity-0'); panel.classList.remove('translate-x-full'); });

    $.post(API_URL, { action: 'fetch_requisition_detail', req_id: reqId }, function(res) {
        if (res.status !== 'success') { content.innerHTML = '<p class="text-red-500 text-sm text-center py-8">Failed to load details.</p>'; return; }
        const h = res.header, items = res.items, stamp = res.director_stamp;

        document.getElementById('drawerRefNumber').textContent = h.ref_number;
        document.getElementById('drawerStatusBadge').innerHTML = statusBadge(h.status);

        const period = h.type === 'Monthly'
            ? new Date(h.period_year, h.period_month-1).toLocaleDateString('en-GB',{month:'long',year:'numeric'})
            : (h.event_label || '');

        const itemsHtml = items.map(i => `
            <tr class="border-b border-gray-50 last:border-0 ${i.status==='Rejected'?'bg-red-50/50':''}">
                <td class="py-2 pr-3">
                    <p class="text-xs font-bold text-gray-900">${i.item_description}</p>
                    ${i.status==='Rejected'?`<p class="text-[10px] text-red-600 font-medium mt-0.5">⚠ ${i.rejection_reason}</p>`:''}
                    ${i.category_name?`<p class="text-[10px] text-gray-400">${i.category_name}</p>`:''}
                </td>
                <td class="py-2 text-xs text-gray-500 text-right whitespace-nowrap">${i.quantity} × ${fmtCurrency(i.unit_cost)}</td>
                <td class="py-2 text-xs font-bold text-gray-900 text-right whitespace-nowrap">${fmtCurrency(i.total_amount)}</td>
            </tr>`).join('');

        const attachHtml = (res.attachments?.length) ? `
            <div class="border border-gray-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-gray-500 uppercase mb-2">Supporting Documents</p>
                ${res.attachments.map(a=>`<a href="${a.file_path}" target="_blank" class="flex items-center gap-2 text-xs text-hodBlue hover:underline py-0.5">📄 ${a.file_name}</a>`).join('')}
            </div>` : '';

        const pastorHtml = (res.pastor_actions?.length) ? res.pastor_actions.map(a=>`
            <div class="border border-gray-100 rounded-xl p-3 text-xs">
                <div class="flex justify-between items-center">
                    <p class="font-bold ${a.action==='Approved'?'text-green-600':a.action==='Rejected'?'text-red-600':'text-amber-600'}">
                        ${a.action==='Approved'?'✓ Approved':a.action==='Rejected'?'✗ Rejected':'⚠ Revision'} by ${a.pastor_name}
                    </p>
                    <p class="text-gray-400">${fmtDate(a.acted_at)}</p>
                </div>
                ${a.notes?`<p class="text-gray-500 mt-1">${a.notes}</p>`:''}
                ${a.has_signature?'<p class="text-green-600 mt-1 font-medium">✓ Physical signature on record</p>':''}
            </div>`).join('') : '';

        const batchHtml = res.batch_info ? `
            <div class="bg-violet-50 border border-violet-100 rounded-xl p-3 text-xs">
                <p class="font-bold text-violet-700">Batch: ${res.batch_info.batch_ref}</p>
                <p class="text-violet-500">${res.batch_info.batch_status} • Disbursed: ${fmtDate(res.batch_info.disbursed_at)}</p>
            </div>` : '';

        content.innerHTML = `
        <div class="grid grid-cols-2 gap-3 text-xs">
            <div class="bg-gray-50 rounded-xl p-3"><p class="text-gray-400 font-bold uppercase">Department</p><p class="font-bold text-gray-900 mt-0.5">${h.department_name}</p></div>
            <div class="bg-gray-50 rounded-xl p-3"><p class="text-gray-400 font-bold uppercase">Period</p><p class="font-bold text-gray-900 mt-0.5">${period}</p></div>
            <div class="bg-gray-50 rounded-xl p-3"><p class="text-gray-400 font-bold uppercase">Submitted By</p><p class="font-bold text-gray-900 mt-0.5">${h.submitted_by_name}</p></div>
            <div class="bg-gray-50 rounded-xl p-3"><p class="text-gray-400 font-bold uppercase">Submitted</p><p class="font-bold text-gray-900 mt-0.5">${fmtDate(h.created_at)}</p></div>
        </div>
        ${h.submission_note?`<div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-xs text-blue-800"><strong>Note:</strong> ${h.submission_note}</div>`:''}
        <div class="border border-gray-100 rounded-2xl overflow-hidden">
            <div class="bg-gray-50 px-4 py-3 border-b border-gray-100 flex justify-between items-center">
                <p class="text-xs font-bold text-gray-600 uppercase">Expense Lines</p>
                <p class="text-xs font-bold text-gray-900">${fmtCurrency(h.total_amount)}</p>
            </div>
            <div class="p-3"><table class="w-full"><tbody>${itemsHtml}</tbody></table></div>
        </div>
        ${attachHtml}
        ${stamp ? renderStamp(stamp.stamp_data, stamp.is_auto) : ''}
        ${pastorHtml}
        ${batchHtml}`;

        buildDrawerActions(h, actions);
    }, 'json');
}

// FIX 9: ownership check for cancel button
function buildDrawerActions(h, container) {
    let html = '';
    const status = h.status;
    const isOwner = parseInt(h.submitted_by) === myId;

    if (status === 'Pending_Director' && PHP_DIR) {
        html += `<button onclick="validateDirector(${h.id})" class="flex-1 bg-hodBlue text-white text-sm font-bold py-3 rounded-xl hover:bg-blue-900 transition-colors">✓ Validate &amp; Stamp</button>`;
        html += `<button onclick="openDirRejectModal(${h.id})" class="bg-red-50 text-red-600 text-sm font-bold px-4 py-3 rounded-xl hover:bg-red-100 transition-colors border border-red-100">Reject</button>`;
    }
    if (['Pending_Pastor','Revision_Required'].includes(status) && PHP_PASTOR) {
        html += `<button onclick="openApproveModal(${h.id})" class="flex-1 bg-green-600 text-white text-sm font-bold py-3 rounded-xl hover:bg-green-700 transition-colors">✓ Approve</button>`;
        html += `<button onclick="openLineRejectModal(${h.id})" class="bg-amber-50 text-amber-700 text-sm font-bold px-3 py-3 rounded-xl hover:bg-amber-100 transition-colors border border-amber-100">Flag Lines</button>`;
        html += `<button onclick="openFullRejectModal(${h.id})" class="bg-red-50 text-red-600 text-sm font-bold px-3 py-3 rounded-xl hover:bg-red-100 transition-colors border border-red-100">Reject All</button>`;
    }
    // FIX 9: Only show cancel to the owner, and only in early stages
    if (['Draft','Pending_Director'].includes(status) && isOwner) {
        html += `<button onclick="cancelRequisition(${h.id})" class="w-full text-xs text-gray-400 font-bold py-2 hover:text-red-500 transition-colors">Cancel Requisition</button>`;
    }
    if (status === 'Revision_Required') {
        html += `<button onclick="openRevisionModal(${h.id})" class="w-full bg-orange-50 text-orange-700 text-sm font-bold py-3 rounded-xl hover:bg-orange-100 transition-colors border border-orange-100">✏️ Revise &amp; Resubmit</button>`;
    }
    if (['Approved','Batched','Disbursed'].includes(status)) {
        html += `<a href="../../includes/requisition_pdf.php?req_id=${h.id}&csrf=${CSRF_TOKEN}" target="_blank" class="flex-1 flex items-center justify-center gap-2 bg-gray-100 text-gray-700 text-sm font-bold py-3 rounded-xl hover:bg-gray-200 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Export PDF</a>`;
    }
    container.innerHTML = html || '<p class="text-xs text-gray-400 text-center w-full py-1">No actions available.</p>';
}

function closeDetailDrawer() {
    const drawer = document.getElementById('detailDrawer');
    const panel  = document.getElementById('detailDrawerPanel');
    drawer.classList.add('opacity-0');
    panel.classList.add('translate-x-full');
    setTimeout(() => {
        drawer.classList.add('hidden');
        if (!document.querySelectorAll('.modal-open').length) document.body.style.overflow = '';
    }, 300);
}

function renderStamp(s, isAuto = false) {
    if (!s) return '';
    return `
    <div class="border-2 border-blue-700 rounded-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-hodBlue to-blue-700 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-white shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <div>
                    <p class="text-white font-black text-xs uppercase tracking-wider">DIGITALLY VALIDATED</p>
                    <p class="text-blue-200 text-[10px]">${isAuto ? 'Auto-validated — Director Submission' : 'Director Digital Stamp'}</p>
                </div>
            </div>
            <span class="bg-green-400 text-green-900 text-[9px] font-black px-2 py-1 rounded-full uppercase tracking-wider">✓ VALID</span>
        </div>
        <div class="bg-gradient-to-br from-blue-50/80 to-white p-4 space-y-3 text-xs">
            <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">Validated By</p><p class="font-bold text-gray-900">${s.name}</p></div>
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">Role</p><p class="font-bold text-gray-900 break-words">${s.role}</p></div>
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">Action</p><p class="font-bold text-blue-700">${s.action}</p></div>
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">Date &amp; Time</p><p class="font-bold text-gray-900">${s.date_time}</p></div>
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">IP Address</p><p class="font-mono text-gray-600 text-[10px]">${s.ip_address}</p></div>
                <div><p class="text-gray-400 text-[10px] font-bold uppercase">Amount</p><p class="font-bold text-gray-900">${s.total_validated}</p></div>
            </div>
            <div class="bg-blue-950 text-white rounded-xl p-3 font-mono text-[9px] leading-relaxed">
                <p class="text-blue-400 uppercase font-bold tracking-widest mb-1.5">Cryptographic Verification</p>
                <p class="break-all text-blue-100"><span class="text-blue-400">SHA256: </span>${s.hash}</p>
                <p class="mt-1"><span class="text-blue-400">VERIFY: </span><span class="text-yellow-300 font-black text-xs">${s.verify_code}</span></p>
                <p class="mt-1"><span class="text-blue-400">STATUS: </span><span class="text-green-400 font-black">${s.status}</span></p>
                ${s.device?`<p class="mt-1 text-blue-600 truncate"><span class="text-blue-400">DEVICE: </span>${s.device}</p>`:''}
            </div>
        </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════
// DIRECTOR ACTIONS
// ═══════════════════════════════════════════════════════
function validateDirector(reqId) {
    if (!confirm('Apply your Director digital stamp? This forwards the requisition to the Resident Pastor.')) return;
    lock();
    $.post(API_URL, { action: 'validate_director', req_id: reqId, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeDetailDrawer(); loadMyRequisitions(); loadDirectorQueue(); loadBadgeCounts(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function openDirRejectModal(reqId) {
    document.getElementById('dirRejectReqId').value = reqId;
    document.getElementById('dirRejectReason').value = '';
    openModal('dirRejectModal');
}

function submitDirectorRejection() {
    const reqId  = document.getElementById('dirRejectReqId').value;
    const reason = document.getElementById('dirRejectReason').value.trim();
    if (!reason) { showToast('Please state a rejection reason.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'reject_director', req_id: reqId, rejection_reason: reason, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('dirRejectModal'); closeDetailDrawer(); loadMyRequisitions(); loadDirectorQueue(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// FIX 3: APPROVAL FILTER — receives el
// ═══════════════════════════════════════════════════════
function setApprovalFilter(f, el) {
    document.querySelectorAll('.approval-filter').forEach(b => {
        b.classList.remove('bg-hodBlue','text-white','border-hodBlue');
        b.classList.add('bg-white','text-gray-600','border-gray-200');
    });
    if (el) {
        el.classList.add('bg-hodBlue','text-white','border-hodBlue');
        el.classList.remove('bg-white','text-gray-600','border-gray-200');
    }
    loadApprovalQueue(f);
}

function loadApprovalQueue(filter = 'all') {
    const container = document.getElementById('approvalQueueList');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-8 text-gray-400 text-sm">Loading...</div>';
    $.post(API_URL, { action: 'fetch_approval_queue', filter }, function(res) {
        if (res.status !== 'success') { container.innerHTML = '<p class="text-red-400 text-sm text-center py-8">Failed to load queue.</p>'; return; }
        if (!res.queue.length) { container.innerHTML = '<div class="text-center py-12 text-gray-400"><p class="text-sm font-medium">Queue is clear ✓</p></div>'; return; }
        container.innerHTML = res.queue.map(r => {
            const period = r.type === 'Monthly'
                ? new Date(r.period_year, r.period_month-1).toLocaleDateString('en-GB',{month:'long',year:'numeric'})
                : (r.event_label || '');
            const isRevision = r.status === 'Revision_Required';
            const dirInfo = r.director_auto ? 'Auto-validated (Director Submission)' : (r.director_name ? `Validated by ${r.director_name}` : '—');
            return `
            <div class="border ${isRevision?'border-orange-200 bg-orange-50/30':'border-gray-100 bg-white'} rounded-2xl p-4 space-y-3 hover:shadow-md transition-all">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-display font-bold text-gray-900 text-sm">${r.ref_number}</p>
                            ${statusBadge(r.status)}
                        </div>
                        <p class="text-xs text-gray-600 mt-1 font-medium">${r.department_name} — ${period}</p>
                        <p class="text-xs text-gray-400 mt-0.5">${r.item_count} items ${r.rejected_item_count>0?`<span class="text-orange-500 font-bold">• ${r.rejected_item_count} need revision</span>`:''}</p>
                        <p class="text-[10px] text-blue-600 mt-0.5 font-medium">🔏 ${dirInfo}</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-black text-lg text-gray-900">${fmtCurrency(r.total_amount)}</p>
                        <p class="text-[10px] text-gray-400">${timeAgo(r.director_validated_at || r.created_at)}</p>
                    </div>
                </div>
                ${r.submission_note?`<p class="text-xs text-gray-500 bg-gray-50 rounded-xl p-3 border border-gray-100">${r.submission_note}</p>`:''}
                <div class="flex gap-2 pt-1">
                    <button onclick="openDetailDrawer(${r.id})" class="flex-1 bg-gray-100 text-gray-700 text-xs font-bold py-2.5 rounded-xl hover:bg-gray-200 transition-colors">View &amp; Stamp</button>
                    <button onclick="openApproveModal(${r.id})" class="flex-1 bg-green-600 text-white text-xs font-bold py-2.5 rounded-xl hover:bg-green-700 transition-colors">✓ Approve</button>
                    <button onclick="openLineRejectModal(${r.id})" title="Flag specific lines" class="bg-amber-50 text-amber-700 text-xs font-bold px-3 py-2.5 rounded-xl hover:bg-amber-100 transition-colors border border-amber-100">⚑</button>
                    <button onclick="openFullRejectModal(${r.id})" title="Fully reject" class="bg-red-50 text-red-600 text-xs font-bold px-3 py-2.5 rounded-xl hover:bg-red-100 transition-colors border border-red-100">✗</button>
                </div>
            </div>`;
        }).join('');
    }, 'json');
}

// ═══════════════════════════════════════════════════════
// APPROVE MODAL
// ═══════════════════════════════════════════════════════
function openApproveModal(reqId) {
    document.getElementById('approveReqId').value = reqId;
    document.getElementById('approveSignatureB64').value = '';
    document.getElementById('approveNotes').value = '';
    clearApproveSignature();
    openModal('approveModal');
    $.post(API_URL, { action: 'check_signature_vault' }, function(res) {
        document.getElementById('importSigBtn')?.classList.toggle('hidden', !res.has_signature);
    }, 'json');
}

// ═══════════════════════════════════════════════════════
// FIX 6 & 7: SIGNATURE PAD — appends to body, delays canvas init
// ═══════════════════════════════════════════════════════
function openSignaturePad(target) {
    sigTarget = target || 'approve';
    const screen = document.getElementById('signaturePadScreen');
    document.body.appendChild(screen);  // FIX 6: move to end of body so it's above all other modals
    screen.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        screen.classList.remove('opacity-0');
        // FIX 7: delay canvas init until browser has completed layout
        setTimeout(initSignatureCanvas, 80);
    });
    document.getElementById('signatureHint').style.display = 'flex';
}

// FIX 7: initSignatureCanvas with dimension guard
function initSignatureCanvas() {
    sigCanvas = document.getElementById('signatureCanvas');
    if (!sigCanvas) return;

    const w = sigCanvas.offsetWidth;
    const h = sigCanvas.offsetHeight;

    // Guard: if dimensions are 0 the browser hasn't laid out yet — retry
    if (w === 0 || h === 0) {
        setTimeout(initSignatureCanvas, 50);
        return;
    }

    sigCanvas.width  = w;
    sigCanvas.height = h;
    sigCtx = sigCanvas.getContext('2d');
    sigCtx.strokeStyle = '#1D356A';
    sigCtx.lineWidth   = 2.8;
    sigCtx.lineCap     = 'round';
    sigCtx.lineJoin    = 'round';
    sigCtx.clearRect(0, 0, w, h);
    sigDrawing = false;

    // Remove any previously attached listeners before re-attaching
    const fresh = sigCanvas.cloneNode(true);
    sigCanvas.parentNode.replaceChild(fresh, sigCanvas);
    sigCanvas = fresh;
    sigCtx = sigCanvas.getContext('2d');
    sigCtx.strokeStyle = '#1D356A';
    sigCtx.lineWidth   = 2.8;
    sigCtx.lineCap     = 'round';
    sigCtx.lineJoin    = 'round';

    function getPos(e) {
        const rect = sigCanvas.getBoundingClientRect();
        const src  = e.touches ? e.touches[0] : e;
        return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }
    function onStart(e) {
        e.preventDefault();
        sigDrawing = true;
        const p = getPos(e); sigLastX = p.x; sigLastY = p.y;
        document.getElementById('signatureHint').style.display = 'none';
    }
    function onDraw(e) {
        e.preventDefault();
        if (!sigDrawing) return;
        const p = getPos(e);
        sigCtx.beginPath(); sigCtx.moveTo(sigLastX, sigLastY);
        sigCtx.lineTo(p.x, p.y); sigCtx.stroke();
        sigLastX = p.x; sigLastY = p.y;
    }
    function onStop() { sigDrawing = false; }

    sigCanvas.addEventListener('touchstart', onStart, { passive: false });
    sigCanvas.addEventListener('touchmove',  onDraw,  { passive: false });
    sigCanvas.addEventListener('touchend',   onStop);
    sigCanvas.addEventListener('mousedown',  onStart);
    sigCanvas.addEventListener('mousemove',  onDraw);
    sigCanvas.addEventListener('mouseup',    onStop);
}

function clearSignatureCanvas() {
    if (sigCtx && sigCanvas) {
        sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
        document.getElementById('signatureHint').style.display = 'flex';
    }
}

// FIX 5: cancelSignaturePad restores scroll lock
function cancelSignaturePad() {
    const screen = document.getElementById('signaturePadScreen');
    screen.classList.add('opacity-0');
    setTimeout(() => {
        screen.classList.add('hidden');
        // FIX 5: restore scroll only if no other modals are open
        if (!document.querySelectorAll('.modal-open').length) {
            document.body.style.overflow = '';
        }
    }, 300);
}

function confirmSignaturePad() {
    if (!sigCanvas) return;
    const blank = !sigCtx.getImageData(0, 0, sigCanvas.width, sigCanvas.height).data.some(v => v !== 0);
    if (blank) { showToast('Please draw your signature first.', 'error'); return; }
    const b64 = sigCanvas.toDataURL('image/png');
    cancelSignaturePad();
    if (sigTarget === 'approve') {
        document.getElementById('approveSignatureB64').value = b64;
        showSignaturePreview(b64);
    } else if (sigTarget === 'vault') {
        document.getElementById('vaultSigImg').src = b64;
        document.getElementById('vaultSigPreview').classList.remove('hidden');
        document.getElementById('vaultSigPlaceholder').classList.add('hidden');
        // Store in vault PIN input's dataset so saveSignatureVault can access
        document.getElementById('vaultPin').dataset.pendingSig = b64;
    }
}

function showSignaturePreview(b64) {
    document.getElementById('sigPreviewImg').src = b64;
    document.getElementById('sigPreviewContainer').classList.remove('hidden');
    document.getElementById('sigPlaceholder').classList.add('hidden');
}

function clearApproveSignature() {
    document.getElementById('approveSignatureB64').value = '';
    document.getElementById('sigPreviewContainer')?.classList.add('hidden');
    document.getElementById('sigPlaceholder')?.classList.remove('hidden');
}

// FIX 10: PIN modal — clear AFTER visible to defeat autofill
function openPinModal() {
    document.getElementById('pinError').classList.add('hidden');
    openModal('pinModal');
    setTimeout(() => {
        const inp = document.getElementById('pinInput');
        inp.value = '';
        inp.focus();
    }, 150);
}

function verifyAndImportSignature() {
    const pin = document.getElementById('pinInput').value.trim();
    if (!/^\d{4}$/.test(pin)) {
        document.getElementById('pinError').textContent = 'PIN must be exactly 4 digits.';
        document.getElementById('pinError').classList.remove('hidden');
        return;
    }
    lock();
    $.post(API_URL, { action: 'verify_pin_get_signature', pin, csrf_token: CSRF_TOKEN }, function(res) {
        unlock();
        if (res.status === 'success') {
            closeModal('pinModal');
            document.getElementById('approveSignatureB64').value = res.signature_base64;
            showSignaturePreview(res.signature_base64);
            showToast('Signature imported.', 'success');
        } else {
            document.getElementById('pinError').textContent = res.message;
            document.getElementById('pinError').classList.remove('hidden');
        }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function submitPastoralApproval() {
    const reqId = document.getElementById('approveReqId').value;
    const sig   = document.getElementById('approveSignatureB64').value;
    const notes = document.getElementById('approveNotes').value;
    if (!sig) { showToast('A signature is required to approve.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'pastor_approve', req_id: reqId, signature_base64: sig, notes, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('approveModal'); closeDetailDrawer(); loadApprovalQueue('all'); loadBadgeCounts(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// LINE REJECTION
// ═══════════════════════════════════════════════════════
function openLineRejectModal(reqId) {
    document.getElementById('lineRejectReqId').value = reqId;
    document.getElementById('lineRejectNotes').value = '';
    const container = document.getElementById('lineRejectItemsContainer');
    container.innerHTML = '<p class="text-xs text-gray-400">Loading items...</p>';
    openModal('lineRejectModal');
    $.post(API_URL, { action: 'fetch_requisition_detail', req_id: reqId }, function(res) {
        if (res.status !== 'success') { container.innerHTML = '<p class="text-red-400 text-xs">Failed to load.</p>'; return; }
        const eligible = res.items.filter(i => i.status !== 'Rejected');
        if (!eligible.length) { container.innerHTML = '<p class="text-xs text-gray-400 text-center py-4">No eligible lines to flag.</p>'; return; }
        container.innerHTML = eligible.map(i => `
        <div class="border border-gray-100 rounded-xl p-3 space-y-2">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" class="mt-0.5 line-reject-chk w-4 h-4 accent-amber-500" data-item-id="${i.id}" onchange="toggleLineReasonField(this)">
                <div>
                    <p class="text-xs font-bold text-gray-900">${i.item_description}</p>
                    <p class="text-[10px] text-gray-400">${fmtCurrency(i.total_amount)}</p>
                </div>
            </label>
            <div id="reason-field-${i.id}" class="hidden pl-7">
                <input type="text" class="line-reject-reason w-full px-3 py-2 rounded-lg border border-gray-200 outline-none focus:border-amber-400 text-xs" data-item-id="${i.id}" placeholder="Reason for flagging this line...">
            </div>
        </div>`).join('');
    }, 'json');
}

function toggleLineReasonField(chk) {
    const rf = document.getElementById(`reason-field-${chk.dataset.itemId}`);
    if (rf) rf.classList.toggle('hidden', !chk.checked);
}

function submitLineRejections() {
    const reqId  = document.getElementById('lineRejectReqId').value;
    const notes  = document.getElementById('lineRejectNotes').value;
    const checks = document.querySelectorAll('.line-reject-chk:checked');
    if (!checks.length) { showToast('Select at least one line to flag.', 'error'); return; }
    const rejections = [];
    for (const c of checks) {
        const id     = c.dataset.itemId;
        const reason = document.querySelector(`.line-reject-reason[data-item-id="${id}"]`)?.value.trim();
        if (!reason) { showToast('Each flagged line needs a stated reason.', 'error'); return; }
        rejections.push({ item_id: id, reason });
    }
    lock();
    $.post(API_URL, { action: 'pastor_reject_lines', req_id: reqId, rejections: JSON.stringify(rejections), notes, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('lineRejectModal'); closeDetailDrawer(); loadApprovalQueue('all'); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// FULL REJECTION
// ═══════════════════════════════════════════════════════
function openFullRejectModal(reqId) {
    document.getElementById('fullRejectReqId').value = reqId;
    document.getElementById('fullRejectReason').value = '';
    openModal('fullRejectModal');
}
function submitFullRejection() {
    const reqId  = document.getElementById('fullRejectReqId').value;
    const reason = document.getElementById('fullRejectReason').value.trim();
    if (!reason) { showToast('Rejection reason is required.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'pastor_reject_full', req_id: reqId, notes: reason, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('fullRejectModal'); closeDetailDrawer(); loadApprovalQueue('all'); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// REVISION EDIT
// ═══════════════════════════════════════════════════════
function openRevisionModal(reqId) {
    document.getElementById('revisionReqId').value = reqId;
    const container = document.getElementById('revisionItemsContainer');
    container.innerHTML = '<p class="text-xs text-gray-400">Loading flagged items...</p>';
    openModal('revisionModal');
    $.post(API_URL, { action: 'fetch_requisition_detail', req_id: reqId }, function(res) {
        if (res.status !== 'success') { container.innerHTML = '<p class="text-red-400 text-xs">Failed to load.</p>'; return; }
        const flagged = res.items.filter(i => i.status === 'Rejected');
        if (!flagged.length) { container.innerHTML = '<p class="text-green-600 text-xs text-center py-4">No flagged lines found.</p>'; return; }
        container.innerHTML = flagged.map(i => `
        <div class="border border-orange-200 bg-orange-50/30 rounded-xl p-4 space-y-3">
            <input type="hidden" class="rev-item-id" value="${i.id}">
            <p class="text-xs font-bold text-gray-900">${i.item_description}</p>
            <p class="text-[10px] text-orange-600 font-medium">⚠ ${i.rejection_reason}</p>
            <div class="grid grid-cols-3 gap-2">
                <div class="col-span-3">
                    <label class="text-[10px] font-bold text-gray-500 uppercase">Description *</label>
                    <input type="text" class="rev-desc w-full px-3 py-2 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-xs mt-1" value="${i.item_description}">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-gray-500 uppercase">Qty</label>
                    <input type="number" step="0.01" min="0.01" class="rev-qty w-full px-3 py-2 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-xs mt-1 font-bold" value="${i.quantity}" oninput="recalcRevRow(this)">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-gray-500 uppercase">Unit Cost ₦</label>
                    <input type="number" step="0.01" min="0.01" class="rev-cost w-full px-3 py-2 rounded-lg border border-gray-200 outline-none focus:border-hodBlue text-xs mt-1 font-bold" value="${i.unit_cost}" oninput="recalcRevRow(this)">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-gray-500 uppercase">Total</label>
                    <p class="rev-total text-xs font-black text-gray-900 mt-2 px-1">${fmtCurrency(i.total_amount)}</p>
                </div>
            </div>
        </div>`).join('');
    }, 'json');
}

function recalcRevRow(el) {
    const row  = el.closest('.border');
    const qty  = parseFloat(row.querySelector('.rev-qty')?.value || 0);
    const cost = parseFloat(row.querySelector('.rev-cost')?.value || 0);
    const tot  = row.querySelector('.rev-total');
    if (tot) tot.textContent = fmtCurrency(qty * cost);
}

function submitRevision() {
    const reqId   = document.getElementById('revisionReqId').value;
    const rows    = document.querySelectorAll('#revisionItemsContainer > div.border');
    const revised = [];
    for (const row of rows) {
        const itemId = row.querySelector('.rev-item-id')?.value;
        const desc   = row.querySelector('.rev-desc')?.value.trim();
        const qty    = parseFloat(row.querySelector('.rev-qty')?.value || 0);
        const cost   = parseFloat(row.querySelector('.rev-cost')?.value || 0);
        if (!desc || cost <= 0) { showToast('All revised lines need a description and positive cost.', 'error'); return; }
        revised.push({ item_id: itemId, item_description: desc, quantity: qty, unit_cost: cost });
    }
    lock();
    $.post(API_URL, { action: 'resubmit_revised', req_id: reqId, revised_items: JSON.stringify(revised), csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('revisionModal'); closeDetailDrawer(); loadMyRequisitions(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// CANCEL
// ═══════════════════════════════════════════════════════
function cancelRequisition(reqId) {
    if (!confirm('Cancel this requisition? This cannot be undone.')) return;
    lock();
    $.post(API_URL, { action: 'cancel_requisition', req_id: reqId, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeDetailDrawer(); loadMyRequisitions(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// TAB 3: FINANCE
// ═══════════════════════════════════════════════════════
function loadFinanceQueue() {
    $.post(API_URL, { action: 'fetch_finance_queue' }, function(res) {
        if (res.status !== 'success') return;
        const avail   = res.available_requisitions || [];
        const drafts  = res.draft_items || [];
        const batches = res.batches || [];
        const draftBatch = batches.find(b => b.status === 'Draft') || null;
        currentDraftBatch = draftBatch;

        // Available list
        const availContainer = document.getElementById('availableReqList');
        document.getElementById('availableCount').textContent = avail.length + ' ready';
        availContainer.innerHTML = !avail.length
            ? '<div class="text-center py-8 text-xs text-gray-400">No approved requisitions waiting to be batched.</div>'
            : avail.map(r => {
                const period = r.type === 'Monthly'
                    ? new Date(r.period_year, r.period_month-1).toLocaleDateString('en-GB',{month:'short',year:'numeric'})
                    : (r.event_label || '');
                const inDraft = drafts.some(d => d.requisition_id == r.id);
                return `
                <div class="border ${inDraft?'border-violet-200 bg-violet-50/30':'border-gray-100 bg-white'} rounded-xl p-3 flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-gray-900 truncate">${r.ref_number}</p>
                        <p class="text-[10px] text-gray-500">${r.department_name} • ${period}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <p class="text-xs font-black text-gray-900">${fmtCurrency(r.total_amount)}</p>
                        ${draftBatch
                            ? (inDraft
                                ? `<button onclick="toggleBatchItem(${draftBatch.id},${r.id},'remove')" class="text-[10px] font-bold text-violet-700 bg-violet-100 px-2 py-1.5 rounded-lg hover:bg-violet-200">Remove</button>`
                                : `<button onclick="toggleBatchItem(${draftBatch.id},${r.id},'add')" class="text-[10px] font-bold text-white bg-hodBlue px-2 py-1.5 rounded-lg hover:bg-blue-900">+ Add</button>`)
                            : '<span class="text-[10px] text-gray-300">No batch open</span>'}
                    </div>
                </div>`;
            }).join('');

        // Draft batch
        const draftContainer = document.getElementById('draftBatchContainer');
        const createBtn      = document.getElementById('createBatchBtn');
        if (draftBatch) {
            createBtn.classList.add('hidden');
            const draftItemList = drafts.filter(d => d.batch_id == draftBatch.id);
            draftContainer.innerHTML = `
            <div class="border-2 border-violet-200 rounded-2xl overflow-hidden">
                <div class="bg-violet-50 px-4 py-3 flex items-center justify-between border-b border-violet-100">
                    <div><p class="text-xs font-black text-violet-700">${draftBatch.batch_ref}</p><p class="text-[10px] text-violet-500">${draftBatch.batch_label||''}</p></div>
                    <p class="font-black text-sm text-gray-900">${fmtCurrency(draftBatch.total_amount)}</p>
                </div>
                <div class="p-3 space-y-1.5 max-h-48 overflow-y-auto custom-scrollbar">
                    ${draftItemList.length ? draftItemList.map(i=>`
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-gray-700">${i.ref_number}</span>
                        <span class="text-gray-500">${i.dept_name}</span>
                        <span class="font-bold text-gray-900">${fmtCurrency(i.amount_in_batch)}</span>
                    </div>`).join('') : '<p class="text-xs text-gray-400 text-center py-2">Add requisitions from the left.</p>'}
                </div>
                <div class="p-3 border-t border-violet-100 flex gap-2">
                    <button onclick="sealBatch(${draftBatch.id})" ${!draftItemList.length?'disabled':''} class="flex-1 bg-hodBlue text-white text-xs font-bold py-2.5 rounded-xl hover:bg-blue-900 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">🔏 Seal &amp; Export PDF</button>
                </div>
            </div>`;
        } else {
            createBtn.classList.remove('hidden');
            draftContainer.innerHTML = '<div class="border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center text-gray-400"><p class="text-xs font-medium">No active batch. Create one to begin.</p></div>';
        }

        // Batch history
        const histContainer = document.getElementById('batchHistoryList');
        const completed = batches.filter(b => b.status !== 'Draft');
        histContainer.innerHTML = !completed.length
            ? '<p class="text-xs text-gray-400 text-center py-4">No completed batches yet.</p>'
            : completed.map(b => {
                const stCls = b.status==='Disbursed'?'bg-emerald-100 text-emerald-700':'bg-blue-100 text-blue-700';
                return `
                <div class="border border-gray-100 rounded-2xl p-4 flex items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="font-bold text-sm text-gray-900">${b.batch_ref}</p>
                            <span class="text-[10px] font-black px-2 py-0.5 rounded-full uppercase ${stCls}">${b.status}</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5">${b.item_count} requisitions • ${fmtDate(b.created_at)}</p>
                        ${b.disbursed_at?`<p class="text-[10px] text-emerald-600 font-medium">Disbursed ${fmtDate(b.disbursed_at)}</p>`:''}
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-black text-sm text-gray-900">${fmtCurrency(b.total_amount)}</p>
                        ${b.status==='Exported'?`<button onclick="openDisburseModal(${b.id})" class="text-[10px] font-bold text-emerald-600 mt-1 hover:underline block">Mark Disbursed</button>`:''}
                        <a href="../../includes/requisition_pdf.php?batch_id=${b.id}&csrf=${CSRF_TOKEN}" target="_blank" class="block text-[10px] text-gray-400 hover:text-hodBlue mt-0.5">Export PDF</a>
                    </div>
                </div>`;
            }).join('');
    }, 'json');
}

function openCreateBatchModal() { document.getElementById('batchLabel').value = ''; openModal('createBatchModal'); }

function submitCreateBatch() {
    const label = document.getElementById('batchLabel').value;
    lock();
    $.post(API_URL, { action: 'create_batch', batch_label: label, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('createBatchModal'); loadFinanceQueue(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function toggleBatchItem(batchId, reqId, toggle) {
    lock();
    $.post(API_URL, { action: 'toggle_batch_item', batch_id: batchId, req_id: reqId, toggle, csrf_token: CSRF_TOKEN }, function(res) {
        unlock();
        if (res.status !== 'success') { showToast(res.message, 'error'); return; }
        loadFinanceQueue();
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function sealBatch(batchId) {
    if (!confirm('Seal this batch with your Finance Director digital stamp? This locks the batch.')) return;
    lock();
    $.post(API_URL, { action: 'seal_batch', batch_id: batchId, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { loadFinanceQueue(); loadBadgeCounts(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function openDisburseModal(batchId) {
    document.getElementById('disburseBatchId').value = batchId;
    document.getElementById('disburseAccount').innerHTML = '<option value="">Loading...</option>';
    document.getElementById('disburseFund').innerHTML = '<option value="">Loading...</option>';
    openModal('disburseModal');
    $.post(API_URL, { action: 'fetch_accounts_funds' }, function(res) {
        if (res.status !== 'success') return;
        document.getElementById('disburseAccount').innerHTML = '<option value="">Select account...</option>' +
            res.accounts.map(a=>`<option value="${a.id}">${a.account_name} (${a.account_type}) — ${fmtCurrency(a.current_balance)}</option>`).join('');
        document.getElementById('disburseFund').innerHTML = '<option value="">Select fund...</option>' +
            res.funds.map(f=>`<option value="${f.id}">${f.fund_name}</option>`).join('');
    }, 'json');
}

function submitDisburse() {
    const batchId   = document.getElementById('disburseBatchId').value;
    const accountId = document.getElementById('disburseAccount').value;
    const fundId    = document.getElementById('disburseFund').value;
    if (!accountId || !fundId) { showToast('Please select both account and fund.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'disburse_batch', batch_id: batchId, account_id: accountId, fund_id: fundId, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('disburseModal'); loadFinanceQueue(); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// FIX 3: PERIOD — receives el
// ═══════════════════════════════════════════════════════
function setPeriod(p, el) {
    currentAnalyticsPeriod = p;
    document.querySelectorAll('.period-btn').forEach(b => {
        b.classList.remove('bg-white','text-hodBlue','shadow-sm');
        b.classList.add('text-gray-600');
    });
    if (el) { el.classList.add('bg-white','text-hodBlue','shadow-sm'); el.classList.remove('text-gray-600'); }
    document.getElementById('customDateRange').classList.toggle('hidden', p !== 'custom');
    if (p !== 'custom') loadAnalytics();
}

function loadAnalytics() {
    const dept  = document.getElementById('analyticsDeptFilter')?.value || '';
    const start = document.getElementById('analyticsStart')?.value || '';
    const end   = document.getElementById('analyticsEnd')?.value || '';
    $.post(API_URL, { action: 'fetch_analytics', period: currentAnalyticsPeriod, department_id: dept, start_date: start, end_date: end }, function(res) {
        if (res.status !== 'success') return;
        // Populate dept filter once
        const df = document.getElementById('analyticsDeptFilter');
        if (df && df.options.length <= 1 && res.all_departments) {
            res.all_departments.forEach(d => df.add(new Option(d.name, d.id)));
        }
        document.getElementById('analyticsPeriodLabel').textContent =
            new Date(res.period.start).toLocaleDateString('en-GB',{month:'short',day:'numeric'}) + ' – ' +
            new Date(res.period.end).toLocaleDateString('en-GB',{month:'short',day:'numeric',year:'numeric'});

        let totalReqs=0, totalAmt=0, disbAmt=0;
        (res.status_breakdown||[]).forEach(s => {
            totalReqs += parseInt(s.cnt);
            totalAmt  += parseFloat(s.total||0);
            if (s.status==='Disbursed') disbAmt += parseFloat(s.total||0);
        });
        document.getElementById('kpiTotal').textContent    = totalReqs;
        document.getElementById('kpiAmount').textContent   = fmtCurrency(totalAmt);
        document.getElementById('kpiDisbursed').textContent = fmtCurrency(disbAmt);
        document.getElementById('kpiCycle').textContent    = res.cycle_days > 0 ? res.cycle_days : '—';

        buildDonut(res.status_breakdown);
        buildTrend(res.monthly_trend);
        if (res.dept_comparison?.length) { document.getElementById('deptComparisonSection').classList.remove('hidden'); buildDeptChart(res.dept_comparison); }

        const topContainer = document.getElementById('topItemsList');
        topContainer.innerHTML = (res.top_items?.length)
            ? (() => {
                const max = parseFloat(res.top_items[0].total_requested);
                return res.top_items.map((item,i) => {
                    const pct = max > 0 ? (parseFloat(item.total_requested)/max*100) : 0;
                    return `<div class="space-y-1">
                        <div class="flex justify-between items-center">
                            <p class="text-xs font-bold text-gray-700 truncate flex-1 pr-3">${i+1}. ${item.item_description}</p>
                            <p class="text-xs font-black text-gray-900 shrink-0">${fmtCurrency(item.total_requested)}</p>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-hodBlue rounded-full" style="width:${pct}%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400">${item.frequency}× submitted${item.category_name?' • '+item.category_name:''}</p>
                    </div>`;
                }).join('');
              })()
            : '<p class="text-xs text-gray-400 text-center py-4">No data for this period.</p>';

        if (res.can_see_all && res.batch_history?.length) {
            document.getElementById('batchHistoryAnalyticsSection').classList.remove('hidden');
            document.getElementById('batchHistoryTableBody').innerHTML = res.batch_history.map(b=>`
            <tr class="hover:bg-gray-50"><td class="px-4 py-3 font-bold">${b.batch_ref}</td>
            <td class="px-4 py-3 font-bold ${b.status==='Disbursed'?'text-emerald-600':'text-blue-600'}">${b.status}</td>
            <td class="px-4 py-3">${b.req_count}</td>
            <td class="px-4 py-3 text-right font-bold">${fmtCurrency(b.total_amount)}</td>
            <td class="px-4 py-3">${fmtDate(b.disbursed_at)||'—'}</td></tr>`).join('');
        }
    }, 'json');
}

function buildDonut(data) {
    const ctx = document.getElementById('statusDonutChart');
    if (!ctx || !data) return;
    if (chartStatus) chartStatus.destroy();
    const colors = {'Approved':'#10B981','Disbursed':'#059669','Pending_Pastor':'#3B82F6','Pending_Director':'#F59E0B','Revision_Required':'#F97316','Rejected':'#EF4444','Cancelled':'#94A3B8','Batched':'#8B5CF6','Draft':'#9CA3AF'};
    chartStatus = new Chart(ctx, { type:'doughnut', data: {
        labels: data.map(d => STATUS_CONFIG[d.status]?.label||d.status),
        datasets:[{data:data.map(d=>d.cnt), backgroundColor:data.map(d=>colors[d.status]||'#CBD5E1'), borderWidth:2, borderColor:'#fff'}]
    }, options:{responsive:true,maintainAspectRatio:false, plugins:{legend:{position:'right',labels:{font:{size:10,weight:'bold'},padding:8,boxWidth:10}}}, cutout:'65%'}});
}

function buildTrend(data) {
    const ctx = document.getElementById('trendBarChart');
    if (!ctx || !data) return;
    if (chartTrend) chartTrend.destroy();
    chartTrend = new Chart(ctx, { type:'bar', data:{
        labels: data.map(d=>d.month_label),
        datasets:[
            {label:'Total',data:data.map(d=>parseFloat(d.total_amount)),backgroundColor:'rgba(29,53,106,0.12)',borderColor:'#1D356A',borderWidth:1.5,borderRadius:4},
            {label:'Approved',data:data.map(d=>parseFloat(d.approved_amount)),backgroundColor:'rgba(16,185,129,0.2)',borderColor:'#10B981',borderWidth:1.5,borderRadius:4}
        ]
    }, options:{responsive:true,maintainAspectRatio:false, plugins:{legend:{labels:{font:{size:9,weight:'bold'},boxWidth:10,padding:8}}},
        scales:{x:{ticks:{font:{size:9}},grid:{display:false}}, y:{ticks:{font:{size:9},callback:v=>'₦'+(v/1000).toFixed(0)+'k'},grid:{color:'#F3F4F6'}}}}});
}

function buildDeptChart(data) {
    const ctx = document.getElementById('deptBarChart');
    if (!ctx || !data) return;
    if (chartDept) chartDept.destroy();
    ctx.parentElement.style.height = Math.max(160, data.length*44)+'px';
    chartDept = new Chart(ctx, { type:'bar', data:{
        labels: data.map(d=>d.dept_name),
        datasets:[
            {label:'Requested',data:data.map(d=>parseFloat(d.total_amount)),backgroundColor:'rgba(29,53,106,0.12)',borderColor:'#1D356A',borderWidth:1.5,borderRadius:4},
            {label:'Approved',data:data.map(d=>parseFloat(d.approved_amount)),backgroundColor:'rgba(16,185,129,0.2)',borderColor:'#10B981',borderWidth:1.5,borderRadius:4}
        ]
    }, options:{indexAxis:'y',responsive:true,maintainAspectRatio:false, plugins:{legend:{labels:{font:{size:9,weight:'bold'},boxWidth:10,padding:8}}},
        scales:{x:{ticks:{font:{size:9},callback:v=>'₦'+(v/1000).toFixed(0)+'k'},grid:{color:'#F3F4F6'}}, y:{ticks:{font:{size:9,weight:'bold'}},grid:{display:false}}}}});
}

// ═══════════════════════════════════════════════════════
// SIGNATURE VAULT
// ═══════════════════════════════════════════════════════
function openSignatureVaultModal() {
    document.getElementById('vaultPin').value = '';
    document.getElementById('vaultPin').dataset.pendingSig = '';
    document.getElementById('vaultDeletePin').value = '';
    document.getElementById('vaultSigPreview').classList.add('hidden');
    document.getElementById('vaultSigPlaceholder').classList.remove('hidden');
    openModal('vaultModal');
    $.post(API_URL, { action: 'check_signature_vault' }, function(res) {
        const statusDiv = document.getElementById('vaultStatus');
        const delSec    = document.getElementById('vaultDeleteSection');
        if (res.has_signature) {
            statusDiv.className = 'rounded-xl p-4 text-sm border bg-green-50 border-green-200 text-green-800';
            statusDiv.innerHTML = `✓ Signature stored since ${fmtDate(res.created_at)}. Last used: ${res.last_used_at?fmtDate(res.last_used_at):'Never'}.`;
            delSec.classList.remove('hidden');
        } else {
            statusDiv.className = 'rounded-xl p-4 text-sm border bg-gray-50 border-gray-200 text-gray-600';
            statusDiv.innerHTML = 'No signature stored yet. Draw and save your signature below.';
            delSec.classList.add('hidden');
        }
    }, 'json');
}

function saveSignatureVault() {
    const sig = document.getElementById('vaultPin').dataset.pendingSig || '';
    const pin = document.getElementById('vaultPin').value.trim();
    if (!sig) { showToast('Please draw your signature first.', 'error'); return; }
    if (!/^\d{4}$/.test(pin)) { showToast('PIN must be exactly 4 digits.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'save_signature_vault', signature_base64: sig, pin, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('vaultModal'); document.getElementById('importSigBtn')?.classList.remove('hidden'); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

function deleteSignatureVault() {
    const pin = document.getElementById('vaultDeletePin').value.trim();
    if (!pin) { showToast('Enter your PIN to delete.', 'error'); return; }
    lock();
    $.post(API_URL, { action: 'delete_signature_vault', pin, csrf_token: CSRF_TOKEN }, function(res) {
        unlock(); showToast(res.message, res.status);
        if (res.status === 'success') { closeModal('vaultModal'); document.getElementById('importSigBtn')?.classList.add('hidden'); }
    }, 'json').fail(() => { unlock(); showToast('Network error', 'error'); });
}

// ═══════════════════════════════════════════════════════
// FIX 8: SUBMIT FORM — manual field reset (no form.reset())
// ═══════════════════════════════════════════════════════
function openSubmitModal() {
    // Manual reset — avoids form.reset() triggering change events
    lineItemCounter = 0;
    document.getElementById('lineItemsContainer').innerHTML = '';
    document.getElementById('grandTotal').textContent = '₦0.00';
    document.getElementById('attachmentPreviews').innerHTML = '';
    document.getElementById('attachmentsInput').value = '';
    document.getElementById('reqType').value = 'Monthly';
    document.getElementById('reqDept').value = '';
    document.getElementById('reqMonth').value = String(new Date().getMonth() + 1);
    document.getElementById('reqYear').value  = String(new Date().getFullYear());
    document.getElementById('reqEventLabel').value = '';
    document.getElementById('reqNote').value = '';
    document.getElementById('monthlyFields').classList.remove('hidden');
    document.getElementById('occasionalFields').classList.add('hidden');
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').textContent = 'Submit Requisition';

    // Re-show window warning if applicable
    const ww = document.getElementById('windowWarning');
    if (formWindowWarning) {
        document.getElementById('windowWarningText').textContent = formWindowWarning;
        ww.classList.remove('hidden');
    } else {
        ww.classList.add('hidden');
    }

    addLineItem();
    openModal('submitModal');
}

function toggleReqType(type) {
    document.getElementById('monthlyFields').classList.toggle('hidden', type === 'Occasional');
    document.getElementById('occasionalFields').classList.toggle('hidden', type === 'Monthly');
}

function addLineItem() {
    const id = ++lineItemCounter;
    let catOpts = '<option value="">—</option>';
    formCategories.forEach(c => { catOpts += `<option value="${c.id}">${c.category_name}</option>`; });
    const row = document.createElement('div');
    row.id = `line-row-${id}`;
    row.className = 'grid grid-cols-12 gap-1.5 items-start';
    row.innerHTML = `
        <div class="col-span-12 sm:col-span-4">
            <input type="text" placeholder="Item description *" class="li-desc w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-xs" oninput="updateLineTotal(${id})">
        </div>
        <div class="col-span-3 sm:col-span-2">
            <input type="number" step="0.01" min="0.01" value="1" class="li-qty w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-xs font-bold" oninput="updateLineTotal(${id})" placeholder="Qty">
        </div>
        <div class="col-span-4 sm:col-span-2">
            <input type="number" step="1" min="1" class="li-cost w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-xs font-bold" oninput="updateLineTotal(${id})" placeholder="Unit cost">
        </div>
        <div class="col-span-5 sm:col-span-2">
            <select class="li-cat w-full px-2 py-2.5 rounded-xl border border-gray-200 outline-none bg-white text-xs">${catOpts}</select>
        </div>
        <div class="col-span-8 sm:col-span-1 flex items-center">
            <p id="line-total-${id}" class="text-[10px] font-black text-gray-600 px-1">₦0</p>
        </div>
        <div class="col-span-4 sm:col-span-1 flex items-center justify-end">
            <button type="button" onclick="removeLineItem(${id})" class="text-gray-300 hover:text-red-500 transition-colors p-1.5 rounded-lg hover:bg-red-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>`;
    document.getElementById('lineItemsContainer').appendChild(row);
}

function removeLineItem(id) {
    document.getElementById(`line-row-${id}`)?.remove();
    recalcGrandTotal();
}

function updateLineTotal(id) {
    const row  = document.getElementById(`line-row-${id}`);
    if (!row) return;
    const qty  = parseFloat(row.querySelector('.li-qty')?.value  || 0);
    const cost = parseFloat(row.querySelector('.li-cost')?.value || 0);
    const el   = document.getElementById(`line-total-${id}`);
    if (el) el.textContent = fmtCurrency(qty * cost);
    recalcGrandTotal();
}

function recalcGrandTotal() {
    let total = 0;
    document.querySelectorAll('[id^="line-total-"]').forEach(el => {
        total += parseFloat(el.textContent.replace(/[₦,]/g,'')) || 0;
    });
    document.getElementById('grandTotal').textContent = fmtCurrency(total);
}

function previewAttachments(input) {
    const container = document.getElementById('attachmentPreviews');
    container.innerHTML = '';
    Array.from(input.files).forEach(f => {
        const icon = f.name.split('.').pop().toLowerCase() === 'pdf' ? '📄' : '🖼';
        container.insertAdjacentHTML('beforeend',
            `<div class="flex items-center gap-2 text-xs text-gray-600 bg-gray-50 rounded-lg px-3 py-2">
                <span>${icon}</span><span class="truncate flex-1">${f.name}</span>
                <span class="text-gray-400 shrink-0">${(f.size/1024).toFixed(0)}KB</span>
            </div>`);
    });
}

// Submit form via AJAX
$('#submitForm').on('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.textContent = 'Submitting...';
    btn.disabled = true;

    // Build items array from line rows
    const rows = document.querySelectorAll('#lineItemsContainer > div[id^="line-row-"]');
    const items = [];
    let valid = true;
    rows.forEach(row => {
        const desc = row.querySelector('.li-desc')?.value.trim();
        const qty  = parseFloat(row.querySelector('.li-qty')?.value  || 0);
        const cost = parseFloat(row.querySelector('.li-cost')?.value || 0);
        const cat  = row.querySelector('.li-cat')?.value || '';
        if (!desc || cost <= 0) { valid = false; return; }
        items.push({ item_description: desc, quantity: qty, unit_cost: cost, finance_category_id: cat });
    });

    if (!valid || !items.length) {
        showToast('All line items need a description and valid cost.', 'error');
        btn.textContent = 'Submit Requisition'; btn.disabled = false;
        return;
    }

    const fd = new FormData(this);
    fd.set('items', JSON.stringify(items));
    fd.set('csrf_token', CSRF_TOKEN);

    $.ajax({
        url: API_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
        success: function(res) {
            btn.textContent = 'Submit Requisition'; btn.disabled = false;
            showToast(res.message, res.status);
            if (res.status === 'success') { closeModal('submitModal'); loadMyRequisitions(); if (PHP_DIR) loadDirectorQueue(); loadBadgeCounts(); }
        },
        error: function() { btn.textContent = 'Submit Requisition'; btn.disabled = false; showToast('Network error', 'error'); }
    });
});

// Close drawer on backdrop click
document.getElementById('detailDrawer').addEventListener('click', function(e) {
    if (e.target === this) closeDetailDrawer();
});

// ═══════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════
$(document).ready(function() {
    loadFormData();
});
</script>

<style>
/* Tab button states */
.tab-btn { border-color: transparent; color: #6B7280; }
.tab-btn.border-hodBlue { border-color: #1D356A; color: #1D356A; }
/* Filter chips */
.filter-chip.bg-hodBlue { background:#1D356A; color:#fff; border-color:#1D356A; }
/* Safe area for signature pad */
@supports (padding-top: env(safe-area-inset-top)) {
    #signaturePadScreen .safe-top    { padding-top: env(safe-area-inset-top); }
    #signaturePadScreen .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
}
/* Prevent text selection on canvas */
#signatureCanvas { -webkit-user-select: none; user-select: none; }
/* Analytics period active */
.period-btn.bg-white { background: white; color: #1D356A; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
/* Approval filter active */
.approval-filter.bg-hodBlue { background:#1D356A; color:#fff; border-color:#1D356A; }
</style>

<?php require_once '../../includes/footer.php'; ?>