<?php
// /modules/library/index.php
session_start();

// We need the DB connection here specifically to fetch Open Graph data for WhatsApp embeds before the page renders.
require_once '../../includes/db.php'; 

$isLoggedIn = isset($_SESSION['user_id']);
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : null;

// Default Open Graph Meta Tags
$ogTitle = "Charis Library | Household of David";
$ogDesc = "Explore a collection of resources to build your faith, leadership, and purpose at HOD Lekki. 'Give instruction to a wise man, and he will be yet wiser.' - Proverbs 9:9";
$ogImage = "https://" . $_SERVER['HTTP_HOST'] . "/assets/images/hod_library_default.jpg"; 
$currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Dynamic Open Graph Meta Tags (If a specific book is shared)
if ($book_id) {
    try {
        $stmt = $pdo->prepare("SELECT title, author, description, cover_image_path FROM charis_books WHERE id = ?");
        $stmt->execute([$book_id]);
        $bookInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bookInfo) {
            $ogTitle = htmlspecialchars($bookInfo['title'] . " by " . $bookInfo['author']) . " | HOD Lekki";
            
            // Create the beautiful WhatsApp preview description
            $baseDesc = htmlspecialchars(substr($bookInfo['description'], 0, 90)) . "... ";
            $ogDesc = $baseDesc . "Grow in grace and knowledge! Explore this powerful resource from Household of David Lekki. 'Give instruction to a wise man, and he will be yet wiser.' - Prov 9:9";
            
            if (!empty($bookInfo['cover_image_path'])) {
                // Ensure the image path is an absolute URL for WhatsApp to read it
                $ogImage = "https://" . $_SERVER['HTTP_HOST'] . $bookInfo['cover_image_path'];
            }
        }
    } catch (Exception $e) {
        // Silently fail to defaults if DB error occurs during header rendering
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $ogTitle; ?></title>

    <meta property="og:title" content="<?php echo $ogTitle; ?>" />
    <meta property="og:description" content="<?php echo $ogDesc; ?>" />
    <meta property="og:image" content="<?php echo $ogImage; ?>" />
    <meta property="og:url" content="<?php echo $currentUrl; ?>" />
    <meta property="og:type" content="website" />
    <meta name="twitter:card" content="summary_large_image">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Montserrat:wght@600;800;900&display=swap" rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="https://www.youtube.com/iframe_api"></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], display: ['Montserrat', 'sans-serif'] },
                    colors: { hodBlue: '#0A0E17', hodRed: '#D11920' },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                    },
                    keyframes: {
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }
                    }
                }
            }
        }
    </script>

    <style>
        body { color: #1F2937; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #D11920; }

        .glass-panel {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.04);
        }

        .book-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .book-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15); }
        .book-cover-wrap { position: relative; overflow: hidden; padding-top: 140%; border-radius: 0.75rem; background: #f3f4f6; }
        .book-cover { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .book-card:hover .book-cover { transform: scale(1.05); }

        .theatre-overlay {
            position: fixed; inset: 0; background: #0A0E17; z-index: 99999;
            display: none; flex-direction: column; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .theatre-overlay.active { display: flex; opacity: 1; }
    </style>
</head>
<body class="bg-gray-50 antialiased min-h-screen relative overflow-x-hidden selection:bg-hodRed selection:text-white">

    <!-- AMBIENT BACKGROUND -->
    <div class="fixed inset-0 z-[-1] overflow-hidden bg-white">
        <div class="absolute inset-0 bg-gradient-to-br from-blue-50/40 via-white to-red-50/30"></div>
        <div class="absolute top-0 right-1/4 w-[500px] h-[500px] bg-hodRed/5 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 left-1/4 w-[400px] h-[400px] bg-hodBlue/5 rounded-full blur-[150px]"></div>
    </div>

    <!-- NAVIGATION -->
    <nav class="sticky top-0 z-40 bg-white/80 backdrop-blur-xl border-b border-gray-100 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 md:px-8 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-hodBlue rounded-xl flex items-center justify-center text-white shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                </div>
                <div>
                    <h1 class="text-base font-display font-black text-gray-900 tracking-tight leading-none">Charis Library</h1>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Resource Portal</p>
                </div>
            </div>
            <div id="navAuthArea">
                <?php if($isLoggedIn): ?>
                    <button onclick="toggleMyLibrary()" class="text-xs font-bold text-hodBlue bg-blue-50 hover:bg-blue-100 px-4 py-2.5 rounded-xl transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        My Library
                    </button>
                <?php else: ?>
                    <a href="/auth/login.php?redirect=/modules/library/index.php" class="text-xs font-bold text-white bg-hodBlue hover:bg-gray-900 px-5 py-2.5 rounded-xl shadow-lg shadow-hodBlue/20 transition-all flex items-center gap-2">
                        Sign In to Borrow
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="max-w-7xl mx-auto px-4 md:px-8 py-8 md:py-12 space-y-10">
        
        <!-- Hero Search & Filters -->
        <div class="glass-panel rounded-3xl p-6 md:p-10 text-center animate-fade-in-up relative overflow-hidden">
            <div class="max-w-2xl mx-auto relative z-10">
                <h2 class="text-3xl md:text-5xl font-display font-black text-gray-900 tracking-tight mb-4">Discover & Grow</h2>
                <p class="text-gray-500 text-sm md:text-base font-medium mb-8">Browse our collection of physical books, e-books, and audiobooks designed to build your faith, leadership, and purpose.</p>
                
                <div class="relative flex items-center bg-white rounded-2xl shadow-sm border border-gray-100 p-2">
                    <svg class="w-6 h-6 text-gray-400 ml-3 absolute pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input type="text" id="searchInput" placeholder="Search by title or author..." class="w-full pl-12 pr-4 py-3 bg-transparent font-bold text-gray-800 outline-none placeholder-gray-400">
                    <button onclick="triggerSearch()" class="bg-hodRed hover:bg-red-700 text-white px-6 py-3 rounded-xl font-bold transition-colors">Search</button>
                </div>

                <div class="flex flex-wrap justify-center gap-2 mt-6" id="categoryFilters">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- My Library Section -->
        <div id="myLibrarySection" class="hidden glass-panel rounded-3xl p-6 md:p-8 animate-fade-in-up border-l-4 border-l-hodBlue mb-8">
            <h3 class="text-xl font-display font-black text-gray-900 mb-6 border-b border-gray-100 pb-4">My Active Books</h3>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 divide-y lg:divide-y-0 lg:divide-x divide-gray-100">
                
                <!-- Physical Borrows & Waitlist -->
                <div class="lg:pr-8">
                    <h4 class="text-lg font-display font-black text-gray-800 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        Physical Borrows
                    </h4>
                    <div id="myActiveBooks" class="flex flex-col gap-4"></div>
                    
                    <div id="myWaitlist" class="mt-6 pt-6 border-t border-gray-100 hidden">
                        <h5 class="text-sm font-bold text-gray-500 uppercase tracking-widest mb-3">Waitlisted Items</h5>
                        <div id="waitlistItems" class="flex flex-wrap gap-2"></div>
                    </div>
                </div>

                <!-- Digital & Audio History -->
                <div class="pt-8 lg:pt-0 lg:pl-8" id="digitalHistoryContainer">
                    <h4 class="text-lg font-display font-black text-gray-800 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-hodRed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        Digital History
                    </h4>
                    <div id="myDigitalBooks" class="flex flex-col gap-4"></div>
                </div>

            </div>
        </div>

        <!-- Library Grid -->
        <div class="animate-fade-in-up" style="animation-delay: 0.1s;">
            <div id="libraryGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 md:gap-8">
                <!-- Populated by JS -->
            </div>
            <div id="emptyState" class="hidden text-center py-20">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900">No books found</h3>
                <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters.</p>
            </div>
        </div>
    </div>

    <!-- BOOK DETAILS MODAL -->
    <div id="bookModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col md:flex-row max-h-[90vh]">
            
            <!-- Left Side Cover -->
            <div class="md:w-2/5 bg-gray-100 relative shrink-0">
                <img id="modalCover" src="" class="w-full h-48 md:h-full object-cover">
                <div class="absolute top-4 left-4 flex flex-col gap-1.5" id="modalBadges"></div>
            </div>
            
            <!-- Right Side Details -->
            <div class="md:w-3/5 p-6 md:p-8 flex flex-col custom-scrollbar overflow-y-auto relative">
                <div class="flex justify-between items-start mb-2">
                    <div class="pr-8">
                        <h2 id="modalTitle" class="text-2xl font-display font-black text-gray-900 leading-tight"></h2>
                        <p id="modalAuthor" class="text-sm font-bold text-hodBlue mt-1"></p>
                    </div>
                    <button onclick="closeModal('bookModal')" class="bg-gray-100 hover:bg-red-50 text-gray-500 hover:text-red-500 p-2 rounded-full transition-colors shrink-0 absolute top-6 right-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <div class="flex items-center gap-4 mb-6 text-[11px] font-bold uppercase tracking-wider text-gray-500 border-b border-gray-100 pb-4">
                    <span id="modalCategory"></span>
                    <span id="modalReadTime" class="flex items-center gap-1"></span>
                </div>

                <div class="flex-1">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Synopsis</p>
                    <p id="modalDesc" class="text-sm text-gray-600 leading-relaxed"></p>
                </div>

                <!-- Custom Audio Player UI (Hidden by default) -->
                <div id="customAudioPlayerUI" class="hidden w-full bg-gray-900 rounded-2xl p-4 flex items-center gap-4 mt-6 shadow-xl">
                    <button id="audioPlayPauseBtn" onclick="toggleAudio()" class="w-12 h-12 bg-white rounded-full flex items-center justify-center text-gray-900 hover:scale-105 transition-transform shrink-0">
                        <svg id="iconPlay" class="w-5 h-5 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        <svg id="iconPause" class="w-5 h-5 hidden" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                    </button>
                    
                    <div class="flex-1">
                        <div class="flex justify-between text-[10px] font-bold text-gray-400 mb-1">
                            <span id="audioCurrentTime">0:00</span>
                            <span id="audioTotalTime">0:00</span>
                        </div>
                        <div class="w-full bg-gray-700 h-1.5 rounded-full relative cursor-pointer" onclick="seekAudio(event)" id="audioProgressBarWrapper">
                            <div id="audioProgressFill" class="absolute top-0 left-0 h-full bg-hodRed rounded-full w-0 transition-all duration-200"></div>
                        </div>
                    </div>
                    
                    <div id="ytplayer" class="hidden"></div>
                </div>

                <div id="modalActionArea" class="mt-4 pt-4 border-t border-gray-100 space-y-3">
                    <!-- Populated by JS based on book type and auth state -->
                </div>
            </div>
        </div>
    </div>

    <!-- THEATRE MODE (E-READER) -->
    <div id="theatreMode" class="theatre-overlay">
        <!-- ALWAYS VISIBLE CLOSE BUTTON -->
        <button onclick="closeTheatre()" class="absolute top-4 right-4 md:top-6 md:right-8 z-[100002] bg-black/60 hover:bg-red-600 text-white w-12 h-12 rounded-full flex items-center justify-center backdrop-blur-md border border-white/20 transition-all shadow-2xl">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>

        <!-- Top Toolbar (Auto-hiding, now just for Title/Author) -->
        <div class="absolute top-0 left-0 right-0 bg-black/80 backdrop-blur-md border-b border-white/10 p-5 flex justify-center items-center z-[100001] transform -translate-y-full transition-transform" id="theatreToolbar">
            <div class="text-center text-white pr-12">
                <h3 id="theatreTitle" class="font-black text-sm md:text-lg tracking-tight"></h3>
                <p id="theatreAuthor" class="text-[10px] text-gray-300 font-bold uppercase tracking-widest mt-1"></p>
            </div>
        </div>
        
        <!-- Reader Container -->
        <div id="readerContainer" class="w-full h-full flex items-center justify-center">
            <!-- iframe inserted here -->
        </div>

        <!-- Mouse movement detector for toolbar -->
        <div class="absolute top-0 left-0 right-0 h-32 z-[100000] pointer-events-none" id="theatreMouseZone"></div>
    </div>

    <!-- GLOBAL LOADER -->
    <div id="globalLoader" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-white/50 backdrop-blur-sm">
        <div class="bg-white p-4 rounded-2xl shadow-xl flex items-center gap-3 border border-gray-100">
            <svg class="animate-spin h-5 w-5 text-hodBlue" viewBox="0 0 24 24"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span class="font-bold text-sm text-gray-700">Processing...</span>
        </div>
    </div>

    <!-- GLOBAL AUTO-SCROLLER -->
    <button id="globalScroller" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" class="fixed bottom-8 right-8 z-[9000] bg-gray-900/80 hover:bg-black text-white p-3 rounded-full shadow-2xl backdrop-blur-md transition-all duration-300 transform translate-y-20 opacity-0 pointer-events-none group">
        <svg class="w-6 h-6 group-hover:-translate-y-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>
    </button>

    <script>
        const API_URL = '/api/library_api.php';
        let fullLibraryData = [];
        let userData = {};
        let isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        let currentFilter = 'All';
        let currentSearch = '';

        // Capture book_id from URL if someone clicked a shared WhatsApp link
        const urlParams = new URLSearchParams(window.location.search);
        const autoOpenBookId = urlParams.get('book_id');

        // ==========================================
        // UI HELPERS
        // ==========================================
        function showToast(msg, type = 'success') {
            const bg = type === 'success' ? 'linear-gradient(135deg, #10B981, #059669)' : (type === 'warning' ? 'linear-gradient(135deg, #F59E0B, #D97706)' : 'linear-gradient(135deg, #EF4444, #DC2626)');
            Toastify({ text: msg, duration: 3500, gravity: "top", position: "center", style: { background: bg, borderRadius: "10px", fontWeight: "bold", boxShadow: "0 10px 25px rgba(0,0,0,0.2)" } }).showToast();
        }

        function lockScreen() { $('#globalLoader').removeClass('hidden').addClass('flex'); }
        function unlockScreen() { $('#globalLoader').addClass('hidden').removeClass('flex'); }

        function openModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            setTimeout(() => { m.classList.remove('opacity-0'); m.children[0].classList.remove('scale-95'); }, 10);
        }
        function closeModal(id) {
            const m = document.getElementById(id);
            m.classList.add('opacity-0'); m.children[0].classList.add('scale-95');
            setTimeout(() => { m.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
            
            // If they close an auto-opened modal, cleanly remove the ID from the URL so they can refresh normally
            if(window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }

        // ==========================================
        // NATIVE WEB SHARE API
        // ==========================================
        function shareBook(id) {
            const book = fullLibraryData.find(b => b.id === id);
            if(!book) return;
            
            // Construct the clean URL for sharing
            const shareUrl = window.location.origin + window.location.pathname + '?book_id=' + id;
            const shareTitle = book.title + ' | HOD Library';
            const shareText = 'Grow in grace and knowledge! Explore this powerful resource from the Household of David Lekki.';

            if (navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareText,
                    url: shareUrl
                }).catch((error) => console.log('Error sharing', error));
            } else {
                navigator.clipboard.writeText(shareUrl).then(() => {
                    showToast('Link copied! Paste it in WhatsApp or anywhere else.', 'success');
                });
            }
        }

        // ==========================================
        // DATA FETCHING & RENDERING
        // ==========================================
        function loadLibrary() {
            lockScreen();
            $.getJSON(API_URL, { action: 'fetch_library', search: currentSearch, category: currentFilter === 'All' ? '' : currentFilter }, function(res) {
                unlockScreen();
                if(res.status === 'success') {
                    fullLibraryData = res.books;
                    userData = res.user_data || {};
                    isLoggedIn = res.is_logged_in;
                    
                    renderCategories(res.categories);
                    renderBooksGrid();
                    if(isLoggedIn) renderMyLibrary();

                    // If they landed here from WhatsApp, auto-open the requested book!
                    if (autoOpenBookId) {
                        setTimeout(() => openBookDetails(parseInt(autoOpenBookId)), 300);
                    }
                }
            });
        }

        function renderCategories(cats) {
            let html = `<button onclick="setFilter('All')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all ${currentFilter === 'All' ? 'bg-hodBlue text-white shadow-md' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}">All</button>`;
            cats.forEach(c => {
                const isActive = currentFilter === c;
                html += `<button onclick="setFilter('${c}')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all ${isActive ? 'bg-hodBlue text-white shadow-md' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}">${c}</button>`;
            });
            $('#categoryFilters').html(html);
        }

        function setFilter(cat) { currentFilter = cat; loadLibrary(); }
        function triggerSearch() { currentSearch = $('#searchInput').val(); loadLibrary(); }

        $('#searchInput').on('keypress', function(e) { if(e.which == 13) triggerSearch(); });

        function renderBooksGrid() {
            const grid = $('#libraryGrid');
            if(fullLibraryData.length === 0) {
                grid.addClass('hidden');
                $('#emptyState').removeClass('hidden');
                return;
            }
            grid.removeClass('hidden');
            $('#emptyState').addClass('hidden');

            let html = '';
            fullLibraryData.forEach(book => {
                const cover = book.cover_image_path || 'https://via.placeholder.com/300x450/e5e7eb/9ca3af?text=No+Cover';
                
                // MULTI-BADGE LOGIC
                let gridBadges = '';
                if(book.book_type === 'E-Book' || book.book_type === 'Both') gridBadges += '<span class="bg-hodRed text-white text-[9px] font-black uppercase px-2 py-1 rounded shadow-sm">E-Book</span>';
                if(book.audiobook_link) gridBadges += '<span class="bg-purple-500 text-white text-[9px] font-black uppercase px-2 py-1 rounded shadow-sm">Audio</span>';
                if(book.book_type === 'Physical' || book.book_type === 'Both') {
                    gridBadges += book.available_copies > 0 
                        ? '<span class="bg-green-500 text-white text-[9px] font-black uppercase px-2 py-1 rounded shadow-sm">Available</span>' 
                        : '<span class="bg-gray-800 text-white text-[9px] font-black uppercase px-2 py-1 rounded shadow-sm">Waitlist</span>';
                }

                html += `
                <div class="book-card cursor-pointer group" onclick="openBookDetails(${book.id})">
                    <div class="book-cover-wrap shadow-md mb-3">
                        <img src="${cover}" alt="${book.title}" class="book-cover">
                        <div class="absolute top-2 right-2 flex flex-col gap-1 items-end">${gridBadges}</div>
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <span class="bg-white/20 backdrop-blur-md border border-white/40 text-white text-xs font-bold px-4 py-2 rounded-xl">View Details</span>
                        </div>
                    </div>
                    <h3 class="font-bold text-gray-900 text-sm leading-tight truncate">${book.title}</h3>
                    <p class="text-[11px] font-medium text-gray-500 truncate mt-0.5">${book.author}</p>
                </div>`;
            });
            grid.html(html);
        }

        // ==========================================
        // MY LIBRARY / MEMBER CART (LOGGED IN ONLY)
        // ==========================================
        function toggleMyLibrary() {
            $('#myLibrarySection').slideToggle(300);
        }

        function renderMyLibrary() {
            // Safety check: Prevent errors if API returns malformed data
            if(!userData.active_borrows) return;

            // 1. Render Physical Active Borrows
            const container = $('#myActiveBooks');
            if(userData.active_borrows.length === 0) {
                container.html('<p class="text-sm text-gray-400 font-medium italic">You have no active physical books borrowed.</p>');
            } else {
                let html = '';
                userData.active_borrows.forEach(b => {
                    const statusColor = b.status === 'Overdue' ? 'text-red-500' : 'text-green-600';
                    const cover = b.cover_image_path || 'https://via.placeholder.com/300x450/e5e7eb/9ca3af?text=No+Cover';
                    html += `
                    <div class="flex gap-4 p-4 bg-white rounded-2xl border border-gray-100 shadow-sm transition-hover hover:shadow-md">
                        <img src="${cover}" class="w-16 h-24 object-cover rounded-lg shadow-sm shrink-0">
                        <div class="flex flex-col justify-center min-w-0 flex-1">
                            <h4 class="font-bold text-gray-900 text-sm truncate">${b.title}</h4>
                            <p class="text-[10px] text-gray-500 font-bold uppercase mt-1">Due: ${b.due_date}</p>
                            <div class="flex items-center gap-2 mt-2 flex-wrap">
                                <span class="text-[9px] font-black ${statusColor} uppercase tracking-wider bg-gray-50 px-2 py-1 rounded border border-gray-100">${b.status.replace('_', ' ')}</span>
                                ${b.status === 'Picked_Up' && b.extension_status === 'None' ? `<button onclick="requestExtension(${b.borrow_id})" class="text-[9px] bg-blue-50 text-blue-600 border border-blue-200 px-2 py-1 rounded font-bold hover:bg-blue-100 transition-colors shrink-0">Request Extension</button>` : ''}
                                ${b.extension_status === 'Pending' ? `<span class="text-[9px] bg-orange-50 text-orange-600 border border-orange-200 px-2 py-1 rounded font-bold shrink-0">Extension Pending</span>` : ''}
                            </div>
                        </div>
                    </div>`;
                });
                container.html(html);
            }

            // 2. Render Waitlist
            if(userData.waitlist && userData.waitlist.length > 0) {
                $('#myWaitlist').removeClass('hidden');
                let wHtml = '';
                userData.waitlist.forEach(w => {
                    const statusColor = w.status === 'Notified' ? 'bg-green-50 text-green-700 border-green-200 animate-pulse' : 'bg-orange-50 text-orange-700 border-orange-100';
                    wHtml += `
                        <div class="${statusColor} text-xs font-bold px-3 py-2 rounded-xl border flex flex-col gap-0.5">
                            <span class="truncate max-w-[200px]">${w.title}</span>
                            ${w.status === 'Notified' ? '<span class="text-[9px] uppercase tracking-wider">Reserved for 48H! Check Borrows</span>' : '<span class="text-[9px] uppercase tracking-wider">Waiting...</span>'}
                        </div>`;
                });
                $('#waitlistItems').html(wHtml);
            } else {
                $('#myWaitlist').addClass('hidden');
            }

            // 3. Render Digital & Audio History
            const digContainer = $('#myDigitalBooks');
            if(!userData.reading_progress || userData.reading_progress.length === 0) {
                digContainer.html('<p class="text-sm text-gray-400 font-medium italic">Your digital reading and listening history will appear here.</p>');
            } else {
                let dHtml = '';
                userData.reading_progress.forEach(p => {
                    const cover = p.cover_image_path || 'https://via.placeholder.com/300x450/e5e7eb/9ca3af?text=No+Cover';
                    dHtml += `
                    <div class="flex gap-4 p-4 bg-gray-50 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                        <img src="${cover}" class="w-16 h-24 object-cover rounded-lg shadow-sm shrink-0">
                        <div class="flex flex-col justify-center min-w-0 flex-1">
                            <h4 class="font-bold text-gray-900 text-sm truncate">${p.title}</h4>
                            <p class="text-[10px] text-gray-500 font-medium truncate mt-0.5">${p.author}</p>
                            <p class="text-[10px] text-hodRed font-bold uppercase mt-1">Saved at Page ${p.last_page_read}</p>
                            <div class="flex gap-2 mt-2">
                                ${p.ebook_file_path ? `<button onclick="openTheatre('${p.ebook_file_path}', ${p.book_id}, ${p.last_page_read}, '${p.title.replace(/'/g, "\\'")}', '${p.author.replace(/'/g, "\\'")}')" class="text-[9px] bg-hodBlue text-white px-3 py-1.5 rounded-lg font-bold hover:bg-gray-900 transition-colors shadow-sm">Resume Reading</button>` : ''}
                                ${p.audiobook_link ? `<button onclick="openBookDetails(${p.book_id})" class="text-[9px] bg-purple-50 text-purple-700 border border-purple-200 px-3 py-1.5 rounded-lg font-bold hover:bg-purple-100 transition-colors shadow-sm">Resume Audio</button>` : ''}
                            </div>
                        </div>
                    </div>`;
                });
                digContainer.html(dHtml);
            }
        }

        // ==========================================
        // BOOK MODAL & ACTIONS
        // ==========================================
        function handleAuthRedirect() {
            const returnUrl = encodeURIComponent(window.location.pathname + window.location.search);
            window.location.href = '/auth/login.php?redirect=' + returnUrl;
        }

        function openBookDetails(id) {
            const book = fullLibraryData.find(b => b.id === id);
            if(!book) return;

            $('#modalCover').attr('src', book.cover_image_path || 'https://via.placeholder.com/300x450/e5e7eb/9ca3af?text=No+Cover');
            $('#modalTitle').text(book.title);
            $('#modalAuthor').text(book.author);
            $('#modalCategory').text(book.category);
            $('#modalDesc').text(book.description || 'No synopsis available.');
            
            if(book.estimated_read_time) {
                $('#modalReadTime').html(`<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> ${book.estimated_read_time}`);
            } else {
                $('#modalReadTime').empty();
            }

            // High-res Badges inside modal
            let badges = '';
            if(book.book_type === 'E-Book' || book.book_type === 'Both') badges += '<span class="bg-hodRed text-white text-[10px] font-black uppercase px-2.5 py-1 rounded shadow-md w-fit">E-Book</span>';
            if(book.audiobook_link) badges += '<span class="bg-purple-500 text-white text-[10px] font-black uppercase px-2.5 py-1 rounded shadow-md w-fit">Audiobook</span>';
            if(book.book_type === 'Physical' || book.book_type === 'Both') {
                badges += book.available_copies > 0 
                    ? '<span class="bg-green-500 text-white text-[10px] font-black uppercase px-2.5 py-1 rounded shadow-md w-fit">Physical Copy</span>'
                    : '<span class="bg-gray-800 text-white text-[10px] font-black uppercase px-2.5 py-1 rounded shadow-md w-fit">Waitlist Only</span>';
            }
            $('#modalBadges').html(badges);

            // Action Area Logic
            let actions = '';
            
            // 1. ALWAYS SHOW THE SHARE BUTTON
            actions += `
                <button onclick="shareBook(${book.id})" class="w-full mb-4 bg-gray-50 hover:bg-gray-100 text-gray-800 px-6 py-3 rounded-xl font-bold transition-all flex justify-center items-center gap-2 border border-gray-200 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg> Share This Resource
                </button>`;

            // Hide audio player upon opening modal in case it was left open
            $('#customAudioPlayerUI').addClass('hidden');
            if (ytPlayerObj && ytPlayerObj.stopVideo) ytPlayerObj.stopVideo();

            // 2. E-BOOK OPTIONS
            if (book.book_type === 'E-Book' || book.book_type === 'Both') {
                if (book.ebook_file_path) {
                    const progObj = (userData.reading_progress || []).find(p => p.book_id === book.id);
                    const lastPage = progObj ? progObj.last_page_read : 1;
                    const btnText = lastPage > 1 ? `Resume Page ${lastPage}` : 'Read in Theatre';

                    actions += `
                    <div class="flex flex-col sm:flex-row gap-3 mb-3">
                        <button onclick="openTheatre('${book.ebook_file_path}', ${book.id}, ${lastPage}, '${book.title.replace(/'/g, "\\'")}', '${book.author.replace(/'/g, "\\'")}')" class="flex-1 bg-hodBlue hover:bg-gray-900 text-white px-4 py-3.5 rounded-xl font-bold shadow-md transition-all flex justify-center items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg> ${btnText}
                        </button>
                        <a href="${book.ebook_file_path}" download class="flex-1 bg-blue-50 hover:bg-blue-100 text-hodBlue px-4 py-3.5 rounded-xl font-bold border border-blue-200 transition-all flex justify-center items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Download PDF
                        </a>
                    </div>`;
                }
            }

            // 3. AUDIOBOOK OPTIONS (Outside E-book check!)
            if (book.audiobook_link) {
                actions += `
                <div class="mb-3">
                    <button onclick="initAudiobook('${book.audiobook_link}')" class="w-full bg-purple-50 hover:bg-purple-100 text-purple-700 px-4 py-3.5 rounded-xl font-bold border border-purple-200 transition-all flex justify-center items-center gap-2 shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path></svg> Listen to Audiobook
                    </button>
                </div>`;
            }

            // 4. PHYSICAL OPTIONS (Requires Login)
            if(book.book_type === 'Physical' || book.book_type === 'Both') {
                if(!isLoggedIn) {
                    actions += `<button onclick="handleAuthRedirect()" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all mt-2">Sign In to Borrow Physical Copy</button>`;
                } else {
                    const isBorrowedByMe = userData.active_borrows && userData.active_borrows.find(x => x.book_id == book.id);
                    const isWaitlisted = userData.waitlist && userData.waitlist.find(x => x.book_id == book.id);

                    if(isBorrowedByMe) {
                        actions += `<div class="w-full bg-green-50 text-green-700 border border-green-200 px-4 py-3 rounded-xl font-bold text-center text-sm mt-2">You currently have a physical copy checked out.</div>`;
                    } else if (isWaitlisted) {
                        actions += `<div class="w-full bg-orange-50 text-orange-700 border border-orange-200 px-4 py-3 rounded-xl font-bold text-center text-sm mt-2">You are on the waitlist for this book.</div>`;
                    } else if (book.available_copies > 0) {
                        actions += `<button onclick="borrowPhysicalBook(${book.id})" class="w-full bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-xl font-bold shadow-lg transition-all flex justify-center items-center gap-2 mt-2">Reserve Physical Copy</button>`;
                    } else {
                        // 0 Copies -> Waitlist + Nudge
                        actions += `
                            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 mt-2">
                                <p class="text-xs text-gray-500 font-bold mb-3 text-center">0 physical copies available right now.</p>
                                <div class="flex gap-2">
                                    <button onclick="joinWaitlist(${book.id})" class="flex-1 bg-gray-900 hover:bg-black text-white py-3 rounded-lg font-bold text-sm transition-all shadow-md">Join Waitlist</button>
                                    <button onclick="nudgeBorrowers(${book.id})" class="flex-1 bg-white hover:bg-gray-100 text-gray-700 border border-gray-300 py-3 rounded-lg font-bold text-sm transition-all shadow-sm">Nudge Borrowers</button>
                                </div>
                            </div>`;
                    }
                }
            }

            $('#modalActionArea').html(actions);
            openModal('bookModal');
        }

        // ==========================================
        // AJAX ACTIONS
        // ==========================================
        function handleApiCall(actionName, payload, successMsgOverride = null) {
            lockScreen();
            $.post(API_URL, { action: actionName, ...payload }, function(res) {
                unlockScreen();
                if(res.status === 'auth_required') return handleAuthRedirect();
                
                showToast(successMsgOverride || res.message, res.status);
                if(res.status === 'success') {
                    if(actionName !== 'nudge_borrowers' && actionName !== 'save_progress') {
                        closeModal('bookModal');
                        loadLibrary(); // Refresh data
                    }
                }
            }, 'json');
        }

        function borrowPhysicalBook(id) { handleApiCall('borrow_book', { book_id: id }); }
        function joinWaitlist(id) { handleApiCall('join_waitlist', { book_id: id }); }
        function nudgeBorrowers(id) { handleApiCall('nudge_borrowers', { book_id: id }); }
        function requestExtension(borrowId) { handleApiCall('request_extension', { borrow_id: borrowId }); }

        // ==========================================
        // AUDIOBOOK LOGIC
        // ==========================================
        let ytPlayerObj;
        let audioTimer;

        function initAudiobook(youtubeUrl) {
            const videoId = extractVideoID(youtubeUrl);
            if (!videoId) return showToast('Invalid Audiobook Link', 'error');

            $('#customAudioPlayerUI').removeClass('hidden');

            if (ytPlayerObj && ytPlayerObj.loadVideoById) {
                ytPlayerObj.loadVideoById(videoId);
                return;
            }

            ytPlayerObj = new YT.Player('ytplayer', {
                height: '0', 
                width: '0', 
                videoId: videoId,
                playerVars: { 'autoplay': 1, 'controls': 0 },
                events: {
                    'onReady': onPlayerReady,
                    'onStateChange': onPlayerStateChange
                }
            });
        }

        function onPlayerReady(event) { event.target.playVideo(); }

        function extractVideoID(url) {
            const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        }

        function toggleAudio() {
            if (!ytPlayerObj) return;
            const state = ytPlayerObj.getPlayerState();
            if (state === YT.PlayerState.PLAYING) {
                ytPlayerObj.pauseVideo();
            } else {
                ytPlayerObj.playVideo();
            }
        }

        function onPlayerStateChange(event) {
            if (event.data === YT.PlayerState.PLAYING) {
                $('#iconPlay').addClass('hidden'); 
                $('#iconPause').removeClass('hidden');
                audioTimer = setInterval(updateAudioUI, 500);
            } else {
                $('#iconPlay').removeClass('hidden'); 
                $('#iconPause').addClass('hidden');
                clearInterval(audioTimer);
            }
        }

        function updateAudioUI() {
            if (ytPlayerObj && ytPlayerObj.getDuration) {
                const current = ytPlayerObj.getCurrentTime();
                const total = ytPlayerObj.getDuration();
                $('#audioCurrentTime').text(formatTime(current));
                $('#audioTotalTime').text(formatTime(total));
                $('#audioProgressFill').css('width', `${(current / total) * 100}%`);
            }
        }

        function formatTime(time) {
            const min = Math.floor(time / 60);
            const sec = Math.floor(time % 60);
            return min + ':' + (sec < 10 ? '0' + sec : sec);
        }

        function seekAudio(e) {
            if (!ytPlayerObj || !ytPlayerObj.getDuration) return;
            const wrapper = $('#audioProgressBarWrapper');
            const clickX = e.pageX - wrapper.offset().left;
            const width = wrapper.width();
            const percent = clickX / width;
            const seekTime = percent * ytPlayerObj.getDuration();
            ytPlayerObj.seekTo(seekTime, true);
        }

        // ==========================================
        // THEATRE MODE (E-READER)
        // ==========================================
        let theatrePingInterval;
        let currentReadBookId = null;

        function openTheatre(url, bookId, startPage, title, author) {
            // 1. Detect if the user is on a Mobile Device
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            closeModal('bookModal');

            // CRITICAL FIX: Save startPage instead of hardcoding page 1
            if(isLoggedIn) $.post(API_URL, { action: 'save_progress', book_id: bookId, page: startPage });

            if (isMobile) {
                // MOBILE BEHAVIOR: Route to Native Tab
                // Bypasses the iframe download issue and leverages the phone's native PDF reader
                showToast('Opening native mobile reader...', 'success');
                window.open(url + '#page=' + startPage, '_blank');
            } else {
                // DESKTOP BEHAVIOR: Theatre Mode
                currentReadBookId = bookId;
                $('#theatreTitle').text(title);
                $('#theatreAuthor').text(author);
                $('#readerContainer').html(`<iframe src="${url}#page=${startPage}" class="w-full h-full bg-white md:max-w-5xl shadow-2xl border-x border-white/10" allowfullscreen></iframe>`);
                
                const overlay = document.getElementById('theatreMode');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';

                // Trigger Native Fullscreen (Desktop)
                if (overlay.requestFullscreen) {
                    overlay.requestFullscreen().catch(err => console.log("Fullscreen err:", err));
                } else if (overlay.webkitRequestFullscreen) { /* Safari */ overlay.webkitRequestFullscreen(); } 
                  else if (overlay.msRequestFullscreen) { /* IE11 */ overlay.msRequestFullscreen(); }

                // Start continuous progress saving (Fixed to ping current session without resetting to page 1)
                if(isLoggedIn) {
                    theatrePingInterval = setInterval(() => {
                        $.post(API_URL, { action: 'save_progress', book_id: bookId, page: startPage }); 
                    }, 30000);
                }

                // Toolbar hover logic
                $('#theatreMouseZone').on('mousemove', function(e) {
                    if(e.clientY < 100) $('#theatreToolbar').removeClass('-translate-y-full');
                    else $('#theatreToolbar').addClass('-translate-y-full');
                });
                setTimeout(() => $('#theatreToolbar').addClass('-translate-y-full'), 3000);
            }
        }

        function closeTheatre() {
            clearInterval(theatrePingInterval);
            $('#theatreMode').removeClass('active');
            $('#readerContainer').empty();
            document.body.style.overflow = '';
            $('#theatreMouseZone').off('mousemove');

            // EXIT NATIVE FULLSCREEN
            if (document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement) {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen(); /* Safari */
                else if (document.msExitFullscreen) document.msExitFullscreen(); /* IE11 */
            }
        }

        $(document).on('keydown', function(e) {
            if(e.key === 'Escape' && $('#theatreMode').hasClass('active')) closeTheatre();
        });

        // Global Auto-Scroller Trigger
        window.addEventListener('scroll', () => {
            const scroller = document.getElementById('globalScroller');
            if (window.scrollY > 400) scroller.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
            else scroller.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
        });

        // ==========================================
        // INIT
        // ==========================================
        $(document).ready(function() {
            loadLibrary();
        });
    </script>
</body>
</html>