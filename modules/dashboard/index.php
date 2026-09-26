<?php
// /index.php (Main Entry Point)

// 1. Pull in the centralized Header
require_once 'includes/header.php'; 
?>

<div class="max-w-7xl mx-auto space-y-8 pb-10">
    
    <!-- HERO BANNER -->
    <div class="relative rounded-3xl p-8 sm:p-10 shadow-xl flex flex-col md:flex-row items-center justify-between gap-6 overflow-hidden bg-gradient-to-br from-hodBlue via-[#152750] to-[#0D1830] text-white animate-fade-in">
        
        <!-- FIX: Replaced GPU-crashing CSS blurs with lightweight Radial Gradients -->
        <div class="absolute top-0 right-0 w-[500px] h-[500px] rounded-full z-0 pointer-events-none" style="background: radial-gradient(circle, rgba(59,130,246,0.15) 0%, transparent 60%); transform: translate(30%, -30%);"></div>
        <div class="absolute bottom-0 left-0 w-[400px] h-[400px] rounded-full z-0 pointer-events-none" style="background: radial-gradient(circle, rgba(239,68,68,0.15) 0%, transparent 60%); transform: translate(-30%, 30%);"></div>
        
        <div class="relative z-10 w-full md:w-2/3">
            <h2 class="text-3xl md:text-4xl font-display font-bold text-white mb-3 tracking-tight">Welcome back, <?= $firstName ?? 'Leader' ?>!</h2>
            <p class="text-blue-100/90 text-sm md:text-base font-light leading-relaxed max-w-xl">
                The harvest is plenty, and your leadership makes a difference. Check your unit's health and upcoming service notices below.
            </p>
        </div>
        
        <!-- QR Button -->
        <div class="relative z-10 shrink-0 self-start md:self-center flex flex-col md:items-end gap-3 w-full md:w-auto mt-4 md:mt-0">
            <div class="inline-flex items-center gap-2 py-2 px-4 rounded-full text-xs font-bold bg-white/10 text-white border border-white/20 backdrop-blur-md shadow-lg self-start md:self-end">
                <span class="relative flex h-2.5 w-2.5">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                </span>
                LIVE SYSTEM
            </div>
            
            <button onclick="showVisitorQR()" class="group bg-yellow-500 hover:bg-yellow-400 text-gray-900 px-6 py-3.5 rounded-xl transition-all duration-300 flex items-center justify-center gap-3 shadow-lg shadow-yellow-500/30 hover:-translate-y-1 font-black w-full md:w-auto">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm14 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                <span>CONNECT QR</span>
            </button>
        </div>
    </div>

    <!-- Loading State -->
    <div id="loading-spinner" class="flex flex-col items-center justify-center py-20 gap-4">
        <svg class="animate-spin h-10 w-10 text-red-600" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <p class="text-gray-500 font-medium text-sm animate-pulse">Syncing church data...</p>
    </div>
    
    <!-- Metrics Grid -->
    <div id="metrics-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 hidden">
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Announcements -->
        <div class="lg:col-span-2 space-y-5">
            <h3 class="text-lg font-display font-bold text-gray-900 flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-red-50 text-red-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                </span>
                Live Ministry Notices
            </h3>
            <div id="announcements-container" class="space-y-4">
            </div>
        </div>

        <!-- Command Tools -->
        <div class="space-y-6">
            <div class="bg-white rounded-3xl shadow-lg border border-gray-100 p-7 relative overflow-hidden">
                
                <!-- FIX: Replaced blur-3xl with safe radial gradient -->
                <div class="absolute top-0 right-0 w-48 h-48 pointer-events-none" style="background: radial-gradient(circle, rgba(239,246,255,0.8) 0%, transparent 60%); transform: translate(20%, -20%);"></div>
                
                <h3 class="text-lg font-display font-bold text-gray-900 mb-6 relative z-10">Command Tools</h3>
                
                <div class="space-y-3 relative z-10">
                    <a href="/modules/events/index.php" class="group block bg-gray-50 hover:bg-[#152750] text-gray-700 hover:text-white px-5 py-4 rounded-xl transition-all duration-300 flex items-center justify-between border border-gray-100 hover:border-transparent">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <span class="text-sm font-bold">Attendance Tracking</span>
                        </div>
                        <svg class="w-4 h-4 transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    
                    <a href="/modules/embrace/index.php" class="group block bg-gray-50 hover:bg-[#152750] text-gray-700 hover:text-white px-5 py-4 rounded-xl transition-all duration-300 flex items-center justify-between border border-gray-100 hover:border-transparent">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                            <span class="text-sm font-bold">First Timer Retention</span>
                        </div>
                        <svg class="w-4 h-4 transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"></path></svg>
                    </a>

                    <a href="/modules/announcements/index.php" class="group block bg-gray-50 hover:bg-[#152750] text-gray-700 hover:text-white px-5 py-4 rounded-xl transition-all duration-300 flex items-center justify-between border border-gray-100 hover:border-transparent">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                            <span class="text-sm font-bold">Publish Notice</span>
                        </div>
                        <svg class="w-4 h-4 transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    
                    <div class="pt-3">
                        <a href="/modules/pastoral/index.php" class="group block bg-gradient-to-r from-red-600 to-red-700 text-white px-5 py-4 rounded-xl transition-all duration-300 flex items-center justify-between shadow-lg shadow-red-500/20 hover:shadow-red-500/40 hover:-translate-y-0.5 border border-red-600">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-red-100 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                                <span class="text-sm font-bold uppercase tracking-wide">Pastoral Desk</span>
                            </div>
                            <svg class="w-4 h-4 transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Visitor QR Code Modal -->
<div id="qrModal" class="fixed inset-0 w-screen h-screen bg-gray-900/90 backdrop-blur-sm hidden z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300 overscroll-contain">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col items-center p-8 text-center relative">
        
        <button onclick="closeQRModal()" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-2 rounded-full transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>

        <div class="w-16 h-16 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm14 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
        </div>
        
        <h3 class="text-2xl font-black text-gray-900 mb-1">Welcome Home</h3>
        <p class="text-sm font-medium text-gray-500 mb-6">Scan to connect with Household of David.</p>

        <div class="p-4 bg-gray-50 border-2 border-dashed border-gray-200 rounded-2xl mb-6">
            <img id="dynamicQRCode" src="" alt="Connect QR Code" class="w-48 h-48 object-contain">
        </div>

        <button onclick="copyConnectLink()" id="copyLinkBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-bold transition-colors w-full flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            Copy Connect Link
        </button>
    </div>
</div>

<script>
    // --- Visitor QR Logic ---
    let connectUrl = window.location.origin + '/connect.php';

    function showVisitorQR() {
        const modal = document.getElementById('qrModal');
        document.getElementById('dynamicQRCode').src = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(connectUrl)}`;
        
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => { 
            modal.classList.remove('opacity-0'); 
            modal.children[0].classList.remove('scale-95'); 
        });
    }

    function closeQRModal() {
        const modal = document.getElementById('qrModal');
        modal.classList.add('opacity-0'); 
        modal.children[0].classList.add('scale-95');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            document.body.style.overflow = ''; 
        }, 300);
    }

    function copyConnectLink() {
        navigator.clipboard.writeText(connectUrl).then(() => {
            const btn = document.getElementById('copyLinkBtn');
            const origHTML = btn.innerHTML;
            btn.innerHTML = '<svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Copied!';
            btn.classList.add('bg-green-50', 'text-green-700');
            setTimeout(() => {
                btn.innerHTML = origHTML;
                btn.classList.remove('bg-green-50', 'text-green-700');
            }, 2000);
        });
    }

    $(document).ready(function() {
        // Helper: Create Metric Card
        const createMetricCard = (title, value, iconPath, colorClass) => `
            <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100/60 flex items-center gap-5 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
                <div class="w-14 h-14 rounded-2xl ${colorClass} flex items-center justify-center shrink-0 shadow-inner group-hover:scale-110 transition-transform duration-300 relative z-10">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">${iconPath}</svg>
                </div>
                <div class="relative z-10">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">${title}</p>
                    <h3 class="text-3xl font-display font-bold text-gray-900 tracking-tight">${value}</h3>
                </div>
            </div>`;

        // Load Dashboard Data
        $.ajax({
            url: '/api/dashboard_api.php',
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                $('#loading-spinner').addClass('hidden');
                
                if(res.status === 'success') {
                    const grid = $('#metrics-grid').removeClass('hidden');
                    let mHtml = '';

                    // 1. Render Metrics based on Role
                    if (['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'].includes(res.role)) {
                        mHtml += createMetricCard('Workforce', res.metrics.total_workers, '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>', 'bg-blue-50 text-blue-600');
                        mHtml += createMetricCard('New Pipeline', res.metrics.total_visitors, '<path d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>', 'bg-green-50 text-green-600');
                        mHtml += createMetricCard('Pending Q&A', res.metrics.pending_qa, '<path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>', 'bg-red-50 text-red-600');
                        mHtml += createMetricCard('Last Sunday', res.metrics.last_sunday_attendance, '<path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>', 'bg-purple-50 text-purple-600');
                    } else if (res.role === 'Director') {
                        mHtml += createMetricCard('Team Workers', res.metrics.team_size, '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>', 'bg-blue-50 text-blue-600');
                        mHtml += createMetricCard('Units Managed', res.metrics.managed_units, '<path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>', 'bg-orange-50 text-orange-600');
                    } else {
                        mHtml += createMetricCard('Unread Alerts', res.metrics.unread_alerts, '<path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>', 'bg-red-50 text-red-600');
                    }
                    grid.html(mHtml);

                    // 2. Render Announcements
                    if (res.announcements && res.announcements.length > 0) {
                        let annHtml = '';
                        res.announcements.forEach(a => {
                            annHtml += `
                            <div class="p-6 rounded-2xl bg-white shadow-sm border border-gray-100 hover:shadow-md transition-all duration-300 relative pl-6 overflow-hidden group">
                                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-red-600 group-hover:bg-[#152750] transition-colors"></div>
                                <div class="flex justify-between items-start mb-2">
                                    <h4 class="font-bold text-gray-900 text-base">${a.title}</h4>
                                    <span class="text-[9px] font-black text-blue-500 uppercase tracking-widest bg-blue-50 px-2 py-1 rounded">${a.event_name} • ${a.date_label}</span>
                                </div>
                                <p class="text-sm text-gray-500 line-clamp-3 leading-relaxed">${a.content}</p>
                            </div>`;
                        });
                        $('#announcements-container').html(annHtml);
                    } else {
                        $('#announcements-container').html('<div class="p-10 text-center bg-white rounded-3xl border border-dashed border-gray-200"><p class="text-gray-400 font-medium">No active announcements for upcoming services.</p></div>');
                    }

                } else {
                    // FIX: Safe error state. No redirects here!
                    $('#metrics-grid').removeClass('hidden').html(
                        `<div class="col-span-full p-6 bg-red-50 text-red-700 rounded-xl border border-red-200 text-center shadow-sm">
                            <p class="font-bold mb-3">Session expired or connection lost.</p>
                            <a href="/auth/login.php" class="inline-block bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-bold transition-colors">Return to Login</a>
                        </div>`
                    );
                    $('#announcements-container').html('');
                }
            },
            error: function(xhr, status, error) {
                console.error("Dashboard API Error:", error);
                $('#loading-spinner').html('<div class="text-red-500 font-bold p-4 bg-red-50 rounded-xl border border-red-100 text-center">Failed to sync with command center. Please check your connection and refresh.</div>');
            }
        });
    });
</script>

<?php require_once 'includes/footer.php'; ?>