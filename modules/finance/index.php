<?php
// /modules/finance/index.php
$currentModule = 'finance';
require_once '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100/60 relative overflow-hidden animate-fade-in-up">
        <div class="absolute top-0 right-0 w-64 h-64 bg-green-50/60 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none z-0"></div>
        
        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-2xl md:text-3xl font-display font-bold text-gray-900 tracking-tight">Finance & Stewardship</h2>
                <p class="text-gray-500 text-sm md:text-base mt-1 font-light">Manage accounts, track funds, and analyze church financial health.</p>
            </div>
        </div>
        <div class="relative z-10">
            <button onclick="openConfigModal('transactionModal')" class="bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-bold transition-all shadow-md flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Log Transaction
            </button>
        </div>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100/60 overflow-hidden relative min-h-[600px] flex flex-col">
        
        <div id="loadingOverlay" class="absolute inset-0 bg-white/95 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
            <svg class="animate-spin h-10 w-10 text-green-600 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing financial ledgers...</p>
        </div>

        <div class="border-b border-gray-100/80 bg-gray-50/30 px-6 pt-2">
            <nav class="flex space-x-8 overflow-x-auto custom-scrollbar" aria-label="Tabs">
                <button onclick="switchTab('dashboard')" id="tab-btn-dashboard" class="whitespace-nowrap py-4 px-2 border-b-2 border-green-600 font-bold text-sm text-green-600 transition-all">IDI Overview</button>
                <button onclick="switchTab('transactions')" id="tab-btn-transactions" class="whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">Master Ledger</button>
                <button onclick="switchTab('config')" id="tab-btn-config" class="whitespace-nowrap py-4 px-2 border-b-2 border-transparent font-bold text-sm text-gray-500 hover:text-gray-700 transition-all">System Configuration</button>
            </nav>
        </div>

        <div id="tab-content-dashboard" class="p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            
            <h3 class="text-lg font-bold text-gray-900 mb-4">Current Month Analytics</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-green-50/50 p-6 rounded-3xl border border-green-100 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-green-600 uppercase tracking-widest mb-1">Total Income</p>
                        <h4 class="text-3xl font-black text-gray-900" id="statIncome">₦0.00</h4>
                    </div>
                    <div class="w-12 h-12 bg-green-200 text-green-700 rounded-full flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg></div>
                </div>
                <div class="bg-red-50/50 p-6 rounded-3xl border border-red-100 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-red-600 uppercase tracking-widest mb-1">Total Expenses</p>
                        <h4 class="text-3xl font-black text-gray-900" id="statExpense">₦0.00</h4>
                    </div>
                    <div class="w-12 h-12 bg-red-200 text-red-700 rounded-full flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg></div>
                </div>
                <div class="bg-blue-50/50 p-6 rounded-3xl border border-blue-100 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-blue-600 uppercase tracking-widest mb-1">Net Position</p>
                        <h4 class="text-3xl font-black text-gray-900" id="statNet">₦0.00</h4>
                    </div>
                    <div class="w-12 h-12 bg-blue-200 text-blue-700 rounded-full flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path></svg></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="border border-gray-100 rounded-3xl overflow-hidden shadow-sm">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Current Account Balances</h3></div>
                    <div class="p-6 space-y-4" id="accountBalancesList"></div>
                </div>
                <div class="border border-gray-100 rounded-3xl overflow-hidden shadow-sm">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Latest 5 Transactions</h3></div>
                    <div class="p-0">
                        <table class="w-full text-left text-sm text-gray-600">
                            <tbody id="recentTxMini" class="divide-y divide-gray-50"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-content-transactions" class="hidden p-6 md:p-8 flex-1 bg-white animate-fade-in-up">
            <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50/80 text-gray-500 font-bold uppercase tracking-wider text-[10px] border-b border-gray-100">
                        <tr>
                            <th class="px-4 py-4">Date</th>
                            <th class="px-4 py-4">Type</th>
                            <th class="px-4 py-4">Description & Cat</th>
                            <th class="px-4 py-4">Account & Fund</th>
                            <th class="px-4 py-4 text-right">Amount</th>
                            <th class="px-4 py-4 text-center">Receipt</th>
                            <th class="px-4 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="masterLedgerBody" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>
        </div>

        <div id="tab-content-config" class="hidden p-6 md:p-8 flex-1 bg-white animate-fade-in-up space-y-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <div class="border border-gray-100 rounded-3xl shadow-sm overflow-hidden flex flex-col">
                    <div class="bg-gray-50 p-5 border-b border-gray-100 flex justify-between items-center">
                        <h3 class="font-bold text-gray-900">Bank & Cash Accounts</h3>
                        <button onclick="openConfigModal('accountModal')" class="text-green-600 bg-green-50 p-2 rounded-lg hover:bg-green-100 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                    </div>
                    <div class="p-0 flex-1 overflow-y-auto max-h-96 custom-scrollbar">
                        <table class="w-full text-left text-sm"><tbody id="configAccountsTable" class="divide-y divide-gray-50"></tbody></table>
                    </div>
                </div>

                <div class="border border-gray-100 rounded-3xl shadow-sm overflow-hidden flex flex-col">
                    <div class="bg-gray-50 p-5 border-b border-gray-100 flex justify-between items-center">
                        <h3 class="font-bold text-gray-900">Virtual Wallets (Funds)</h3>
                        <button onclick="openConfigModal('fundModal')" class="text-green-600 bg-green-50 p-2 rounded-lg hover:bg-green-100 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                    </div>
                    <div class="p-0 flex-1 overflow-y-auto max-h-96 custom-scrollbar">
                        <table class="w-full text-left text-sm"><tbody id="configFundsTable" class="divide-y divide-gray-50"></tbody></table>
                    </div>
                </div>

                <div class="border border-gray-100 rounded-3xl shadow-sm overflow-hidden flex flex-col">
                    <div class="bg-gray-50 p-5 border-b border-gray-100 flex justify-between items-center">
                        <h3 class="font-bold text-gray-900">Dynamic Categories</h3>
                        <button onclick="openConfigModal('categoryModal')" class="text-green-600 bg-green-50 p-2 rounded-lg hover:bg-green-100 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                    </div>
                    <div class="p-0 flex-1 overflow-y-auto max-h-96 custom-scrollbar">
                        <table class="w-full text-left text-sm"><tbody id="configCategoriesTable" class="divide-y divide-gray-50"></tbody></table>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<div id="transactionModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50 shrink-0">
            <h3 class="text-xl font-bold text-gray-900">Process Transaction</h3>
            <button onclick="closeModal('transactionModal')" class="text-gray-400 hover:text-red-500 transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="transactionForm" enctype="multipart/form-data" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_transaction">
            
            <div class="p-6 md:p-8 space-y-6 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Transaction Type *</label>
                        <select name="transaction_type" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-green-600 bg-white font-bold" onchange="filterCategories(this.value)">
                            <option value="Income">Income (Money In)</option>
                            <option value="Expense">Expense (Money Out)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Amount (₦) *</label>
                        <input type="number" step="0.01" min="0" name="amount" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-green-600 bg-white font-black text-lg">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Ledger Account *</label>
                        <select name="account_id" id="txAccountSelect" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none bg-white"></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Virtual Wallet *</label>
                        <select name="fund_id" id="txFundSelect" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none bg-white"></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Category</label>
                        <select name="category_id" id="txCategorySelect" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none bg-white"></select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Description / Memo</label>
                    <input type="text" name="description" placeholder="What is this for?" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-green-600 bg-white">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Upload Receipt / Invoice (Optional)</label>
                    <input type="file" name="receipt_file" accept=".jpg,.png,.pdf" class="w-full px-4 py-2 rounded-xl border border-gray-200 outline-none bg-gray-50 cursor-pointer">
                </div>
            </div>

            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white px-6 py-4 rounded-xl font-bold transition-all shadow-md">Record to Master Ledger</button>
            </div>
        </form>
    </div>
</div>

<div id="accountModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Manage Account</h3>
            <button onclick="closeModal('accountModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="accountForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_account"><input type="hidden" name="id" id="accountId">
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Account Name</label><input type="text" name="account_name" id="accountName" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-green-600 font-bold text-gray-900"></div>
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Type</label><select name="account_type" id="accountType" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-white font-bold text-gray-900 cursor-pointer"><option value="Bank">Bank Account</option><option value="Cash">Petty Cash</option></select></div>
            </div>
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-3.5 rounded-xl font-bold transition-all shadow-md">Save Ledger</button>
            </div>
        </form>
    </div>
</div>

<div id="fundModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Manage Virtual Wallet</h3>
            <button onclick="closeModal('fundModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="fundForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_fund"><input type="hidden" name="id" id="fundId">
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Wallet Name</label><input type="text" name="fund_name" id="fundName" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-green-600 font-bold text-gray-900"></div>
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Description</label><input type="text" name="description" id="fundDesc" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-green-600 font-medium text-gray-900"></div>
            </div>
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-3.5 rounded-xl font-bold transition-all shadow-md">Save Wallet</button>
            </div>
        </form>
    </div>
</div>

<div id="categoryModal" class="fixed inset-0 w-screen h-screen bg-gray-900/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[80vh] md:max-h-[90vh]">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center shrink-0">
            <h3 class="font-bold text-gray-900">Manage Category</h3>
            <button onclick="closeModal('categoryModal')" class="text-gray-400 hover:text-red-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <form id="categoryForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="save_category"><input type="hidden" name="id" id="categoryId">
            <div class="p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 bg-white">
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Category Name</label><input type="text" name="category_name" id="categoryName" required class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:border-green-600 font-bold text-gray-900"></div>
                <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Flow Type</label><select name="type" id="categoryType" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none bg-white font-bold text-gray-900 cursor-pointer"><option value="Income">Income (Money In)</option><option value="Expense">Expense (Money Out)</option></select></div>
            </div>
            <div class="p-6 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-3.5 rounded-xl font-bold transition-all shadow-md">Save Category</button>
            </div>
        </form>
    </div>
</div>

<div id="globalActionBlocker" class="fixed inset-0 w-screen h-screen z-[100000] hidden items-center justify-center bg-gray-900/40 backdrop-blur-sm cursor-not-allowed transition-opacity duration-300 opacity-0">
    <div class="bg-white p-4 rounded-2xl shadow-2xl flex items-center gap-3">
        <svg class="animate-spin h-6 w-6 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <span class="font-bold text-gray-700 text-sm">Processing...</span>
    </div>
</div>

<script>
    const API_URL = '../../api/finance_api.php';
    let masterCategories = [];

    // --- UI Controls (UPGRADED) ---
    function switchTab(tabId) {
        $('[id^="tab-btn-"]').removeClass('border-green-600 text-green-600').addClass('border-transparent text-gray-500 hover:text-gray-700');
        $(`#tab-btn-${tabId}`).removeClass('border-transparent text-gray-500 hover:text-gray-700').addClass('border-green-600 text-green-600');
        $('[id^="tab-content-"]').addClass('hidden').removeClass('animate-fade-in-up');
        setTimeout(() => { $(`#tab-content-${tabId}`).removeClass('hidden').addClass('animate-fade-in-up'); }, 10);
    }

    function lockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        if(blocker) {
            blocker.classList.remove('hidden');
            document.body.style.overflow = 'hidden'; 
            setTimeout(() => blocker.classList.remove('opacity-0'), 10);
        }
    }

    function unlockScreenAction() {
        const blocker = document.getElementById('globalActionBlocker');
        if(blocker) {
            blocker.classList.add('opacity-0');
            setTimeout(() => {
                blocker.classList.add('hidden');
                // Only restore body scroll if NO modals are actively open
                if(document.querySelectorAll('.backdrop-blur-md:not(.hidden)').length === 0){
                    document.body.style.overflow = ''; 
                }
            }, 300);
        }
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;
        
        document.body.appendChild(modal); // Viewport Escape
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Lock background scroll

        requestAnimationFrame(() => { 
            modal.classList.remove('opacity-0'); 
            modal.children[0].classList.remove('scale-95'); 
        });
    }
    
    function closeModal(id) {
        const modal = document.getElementById(id);
        if(!modal) return;

        modal.classList.add('opacity-0'); 
        modal.children[0].classList.add('scale-95');

        setTimeout(() => { 
            modal.classList.add('hidden'); 
            // Unlock scroll only if no other fixed modals are visible
            if ($('.fixed.inset-0:not(.hidden):not(#globalActionBlocker)').length === 0) {
                document.body.style.overflow = '';
            }
            const f = modal.querySelector('form'); 
            if(f) {
                f.reset(); 
                f.querySelector('input[name="id"]')?.setAttribute('value', '');
            }
        }, 300);
    }

    function showToast(msg, type = 'success') {
        Toastify({ 
            text: msg, 
            gravity: "top", 
            position: "center", 
            duration: 3000,
            style: { 
                background: type === 'success' ? "#10B981" : "#EF4444", 
                borderRadius: "10px", 
                fontWeight: "bold",
                boxShadow: "0 10px 25px rgba(0,0,0,0.3)"
            } 
        }).showToast();
    }

    function openConfigModal(modalId, id='', name='', extra='') {
        openModal(modalId);
        if(id) {
            $(`#${modalId} input[name="id"]`).val(id);
            if(modalId === 'accountModal') { $('#accountName').val(name); $('#accountType').val(extra); }
            if(modalId === 'fundModal') { $('#fundName').val(name); $('#fundDesc').val(extra); }
            if(modalId === 'categoryModal') { $('#categoryName').val(name); $('#categoryType').val(extra); }
        }
    }

    function filterCategories(type) {
        let opts = '<option value="">Select Category...</option>';
        masterCategories.forEach(c => {
            if(c.type === type) opts += `<option value="${c.id}">${c.category_name}</option>`;
        });
        $('#txCategorySelect').html(opts);
    }

    function deleteRecord(action, id) {
        if(!confirm("Are you sure you want to delete this record? This action cannot be undone.")) return;
        
        lockScreenAction();
        $.post(API_URL, { action: action, [action === 'delete_transaction' ? 'transaction_id' : 'id']: id }, function(res) {
            unlockScreenAction();
            showToast(res.message, res.status);
            if(res.status === 'success') loadFinanceData();
        }, 'json').fail(function() {
            unlockScreenAction();
            showToast("Server Error", "error");
        });
    }

    // --- Master Data Loader ---
    function loadFinanceData() {
        $.post(API_URL, { action: 'fetch_dashboard' }, function(res) {
            $('#loadingOverlay').addClass('hidden');
            if(res.status === 'success') {
                
                // 1. Dashboard Analytics
                let inc = parseFloat(res.analytics.month_income);
                let exp = parseFloat(res.analytics.month_expense);
                let net = parseFloat(res.analytics.net_position);
                
                $('#statIncome').text('₦' + inc.toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#statExpense').text('₦' + exp.toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#statNet').text('₦' + net.toLocaleString(undefined, {minimumFractionDigits: 2})).removeClass('text-red-600').addClass(net < 0 ? 'text-red-600' : 'text-gray-900');

                // 2. Dropdowns & Config Tables
                masterCategories = res.categories;
                filterCategories('Income'); // Default

                let accOpts = ''; let accHtml = ''; let accDash = '';
                res.accounts.forEach(a => {
                    accOpts += `<option value="${a.id}">${a.account_name} (${a.account_type})</option>`;
                    let bal = parseFloat(a.current_balance).toLocaleString(undefined, {minimumFractionDigits: 2});
                    accHtml += `<tr><td class="px-4 py-3 font-bold">${a.account_name} <span class="text-[10px] bg-gray-100 px-2 py-0.5 rounded ml-2">${a.account_type}</span></td><td class="px-4 py-3 text-right">₦${bal}</td><td class="px-4 py-3 text-right"><button onclick="openConfigModal('accountModal', ${a.id}, '${a.account_name.replace(/'/g, "\\'")}', '${a.account_type}')" class="text-blue-500 mx-1 font-bold">Edit</button> <button onclick="deleteRecord('delete_account', ${a.id})" class="text-red-500 mx-1 font-bold">Del</button></td></tr>`;
                    accDash += `<div class="flex justify-between items-center py-2 border-b border-gray-50 last:border-0"><span class="text-gray-600">${a.account_name}</span><span class="font-bold text-gray-900">₦${bal}</span></div>`;
                });
                $('#txAccountSelect').html(accOpts); $('#configAccountsTable').html(accHtml); $('#accountBalancesList').html(accDash);

                let fundOpts = ''; let fundHtml = '';
                res.funds.forEach(f => {
                    fundOpts += `<option value="${f.id}">${f.fund_name}</option>`;
                    fundHtml += `<tr><td class="px-4 py-3 font-bold">${f.fund_name}</td><td class="px-4 py-3 text-xs text-gray-500">${f.description || ''}</td><td class="px-4 py-3 text-right"><button onclick="openConfigModal('fundModal', ${f.id}, '${f.fund_name.replace(/'/g, "\\'")}', '${(f.description || '').replace(/'/g, "\\'")}')" class="text-blue-500 mx-1 font-bold">Edit</button> <button onclick="deleteRecord('delete_fund', ${f.id})" class="text-red-500 mx-1 font-bold">Del</button></td></tr>`;
                });
                $('#txFundSelect').html(fundOpts); $('#configFundsTable').html(fundHtml);

                let catHtml = '';
                res.categories.forEach(c => {
                    let cBadge = c.type === 'Income' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700';
                    catHtml += `<tr><td class="px-4 py-3 font-bold">${c.category_name}</td><td class="px-4 py-3"><span class="text-[10px] px-2 py-1 rounded font-bold uppercase ${cBadge}">${c.type}</span></td><td class="px-4 py-3 text-right"><button onclick="openConfigModal('categoryModal', ${c.id}, '${c.category_name.replace(/'/g, "\\'")}', '${c.type}')" class="text-blue-500 mx-1 font-bold">Edit</button> <button onclick="deleteRecord('delete_category', ${c.id})" class="text-red-500 mx-1 font-bold">Del</button></td></tr>`;
                });
                $('#configCategoriesTable').html(catHtml);

                // 3. Transactions LEDGER
                let txHtml = ''; let miniHtml = '';
                if(res.transactions.length === 0) txHtml = '<tr><td colspan="7" class="text-center py-10 text-gray-500">No transactions recorded yet.</td></tr>';
                
                res.transactions.forEach((tx, index) => {
                    let d = new Date(tx.transaction_date).toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
                    let isInc = tx.transaction_type === 'Income';
                    let tBadge = isInc ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50';
                    let sign = isInc ? '+' : '-';
                    let amt = parseFloat(tx.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
                    let rec = tx.receipt_url ? `<a href="${tx.receipt_url}" target="_blank" class="text-blue-500 hover:text-blue-700 hover:underline flex justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg></a>` : '-';
                    
                    txHtml += `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3 text-xs font-bold text-gray-500">${d}</td>
                        <td class="px-4 py-3"><span class="px-2 py-1 rounded text-[10px] font-black uppercase tracking-wider ${tBadge}">${tx.transaction_type}</span></td>
                        <td class="px-4 py-3"><p class="font-bold text-gray-900">${tx.description || 'No description'}</p><p class="text-xs text-gray-400">${tx.category_name || 'Uncategorized'}</p></td>
                        <td class="px-4 py-3"><p class="font-bold text-gray-700">${tx.account_name}</p><p class="text-[10px] text-hodBlue uppercase tracking-wider">${tx.fund_name}</p></td>
                        <td class="px-4 py-3 text-right font-black text-lg ${isInc ? 'text-green-600' : 'text-gray-900'}">${sign}₦${amt}</td>
                        <td class="px-4 py-3 text-center">${rec}</td>
                        <td class="px-4 py-3 text-right"><button onclick="deleteRecord('delete_transaction', ${tx.id})" class="text-xs text-red-500 hover:text-red-700 font-bold bg-red-50 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors border border-red-100 shadow-sm">Revoke</button></td>
                    </tr>`;

                    if(index < 5) {
                        miniHtml += `<tr><td class="px-4 py-3"><span class="font-bold text-gray-900 block">${tx.description || tx.category_name}</span><span class="text-[10px] text-gray-500">${d} • ${tx.account_name}</span></td><td class="px-4 py-3 text-right font-black ${isInc ? 'text-green-600' : 'text-gray-900'}">${sign}₦${amt}</td></tr>`;
                    }
                });
                
                $('#masterLedgerBody').html(txHtml); $('#recentTxMini').html(miniHtml);
            }
        }, 'json');
    }

    $(document).ready(function() {
        loadFinanceData();

        // AJAX Form Binder (Handles regular inputs AND physical file uploads via FormData)
        function bindAjaxForm(formId, modalId) {
            $(`#${formId}`).on('submit', function(e) {
                e.preventDefault();
                const btn = $(this).find('button[type="submit"]');
                const orig = btn.html(); 
                
                btn.prop('disabled', true).html('Processing...');
                lockScreenAction(); // Prevent double clicks
                
                let formData = new FormData(this);
                $.ajax({
                    url: API_URL, 
                    type: 'POST', 
                    data: formData, 
                    contentType: false, 
                    processData: false, 
                    dataType: 'json',
                    success: function(res) {
                        btn.prop('disabled', false).html(orig);
                        unlockScreenAction();
                        
                        showToast(res.message, res.status);
                        if(res.status === 'success') { 
                            closeModal(modalId); 
                            loadFinanceData(); 
                        }
                    },
                    error: function() { 
                        btn.prop('disabled', false).html(orig); 
                        unlockScreenAction();
                        showToast("Server Error", "error"); 
                    }
                });
            });
        }

        bindAjaxForm('transactionForm', 'transactionModal');
        bindAjaxForm('accountForm', 'accountModal');
        bindAjaxForm('fundForm', 'fundModal');
        bindAjaxForm('categoryForm', 'categoryModal');
    });
</script>

<?php require_once '../../includes/footer.php'; ?>