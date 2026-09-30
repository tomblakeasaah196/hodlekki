<?php
// /includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: /auth/login.php");
    exit;
}

// Ensure the database connection is available for department checks.
// db.php also runs security_enforce_session(), which tears down the session if
// the account has been suspended, revoked or signed out by an administrator —
// so the guard above catches it on the very next request.
require_once __DIR__ . '/db.php';

// Re-check after the security gate: the session may have just been destroyed.
if (!isset($_SESSION['user_id'])) {
    header("Location: /auth/login.php");
    exit;
}

// An outstanding forced password change blocks every other authenticated page.
// /auth/change_password.php deliberately does NOT include this file, so there
// is no redirect loop.
if (!empty($_SESSION['must_change_password'])) {
    header("Location: /auth/change_password.php");
    exit;
}

$firstName = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$activeRole = htmlspecialchars($_SESSION['active_role'] ?? 'Member');
$profilePicPath = !empty($_SESSION['picture_path']) ? htmlspecialchars($_SESSION['picture_path']) : null;

$currentModule = basename(dirname($_SERVER['PHP_SELF']));
$isDashboard = ($_SERVER['PHP_SELF'] == '/index.php' || $currentModule == 'dashboard');
$moduleTitles = ['sms_studio' => 'SMS Studio'];
$moduleTitle = $moduleTitles[$currentModule] ?? ucfirst(str_replace('_', ' ', $currentModule));

// Helper function for active link styling
function getLinkStyle($isActive) {
    if ($isActive) {
        return "bg-gradient-to-r from-white/20 to-white/5 border-l-4 border-white text-white font-semibold shadow-lg translate-x-1";
    }
    return "text-blue-100/70 border-l-4 border-transparent hover:text-white hover:bg-white/10 hover:translate-x-1";
}

// ========================================================================
// NAVIGATION ACCESS CONTROL (RBAC & DEPARTMENT MATRIX)
// ========================================================================

// 1. Fetch user's active departments once to avoid overloading the database
$user_dept_ids = [];
if (isset($pdo)) {
    $deptStmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = ? AND is_active = 1");
    $deptStmt->execute([$_SESSION['user_id']]);
    $user_dept_ids = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
}

// 2. Check if user is a Super Admin
$is_super_admin = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if ($role['role_name'] === 'Super_Admin') {
            $is_super_admin = true;
            break;
        }
    }
}

// 3. Define the Core Logic function
function userHasNavAccess($allowed_roles = [], $allowed_dept_ids = []) {
    global $user_dept_ids, $is_super_admin;
    
    // Super Admins override all checks
    if ($is_super_admin) return true;
    
    // Check if user holds an allowed role
    if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (in_array($role['role_name'], $allowed_roles)) {
                return true;
            }
        }
    }
    
    // Check if user is in an allowed department
    if (!empty($allowed_dept_ids) && !empty($user_dept_ids)) {
        foreach ($allowed_dept_ids as $dept_id) {
            if (in_array($dept_id, $user_dept_ids)) {
                return true;
            }
        }
    }
    
    return false;
}

// Define specific role groupings based on your matrix
$pastors = ['Resident_Pastor', 'Assoc_Pastor'];
$pastors_directors = ['Resident_Pastor', 'Assoc_Pastor', 'Director'];
$sms_roles = ['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head']; // mirrors SMS_ALLOWED_ROLES in includes/sms_functions.php

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isDashboard ? 'Dashboard' : htmlspecialchars($moduleTitle) ?> | HOD Lekki Centre</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        hodBlue: '#1D356A',
                        hodRed: '#D11920',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Montserrat', 'sans-serif'],
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s ease-out forwards',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(15px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        }
                    }
                }
            }
        }
    </script>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="/assets/js/modal-manager.js" defer></script>
    <script src="/assets/js/tab-deeplink.js" defer></script>
    <script src="/assets/js/global-search.js" defer></script>

    <style>
        /* Custom scrollbar for sidebar */
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.4); }

        /*
         * Shared authenticated-dialog contract. modal-manager.js applies these
         * classes to fixed modal roots so every module gets viewport centring,
         * a non-interactive blurred background, scroll lock, and safe mobile
         * sizing without having to reimplement those behaviours.
         */
        .app-modal-overlay {
            position: fixed !important;
            inset: 0 !important;
            width: 100dvw !important;
            height: 100dvh !important;
            overflow: auto !important;
            overscroll-behavior: contain;
            background-color: rgba(15, 23, 42, 0.74) !important;
            -webkit-backdrop-filter: blur(10px) saturate(85%) !important;
            backdrop-filter: blur(10px) saturate(85%) !important;
        }
        .app-modal-overlay:not(.app-modal-preserve-layout) {
            align-items: center !important;
            justify-content: center !important;
            padding: clamp(0.75rem, 2.5vw, 2rem) !important;
        }
        .app-modal-overlay:not(.hidden):not([hidden]) { display: flex !important; }
        .app-modal-overlay:not(.app-modal-preserve-layout) > .app-modal-panel {
            max-width: calc(100dvw - clamp(1.5rem, 5vw, 4rem));
            max-height: calc(100dvh - clamp(1.5rem, 5vw, 4rem)) !important;
            margin: auto;
            overflow-y: auto !important;
            overscroll-behavior: contain;
            touch-action: pan-y;
        }
        .app-modal-overlay > .app-modal-panel:focus { outline: none; }
        html.app-modal-open,
        body.app-modal-open { overscroll-behavior: none; }
        main.app-modal-scroll-locked { overflow: hidden !important; }
        @media (max-width: 640px) {
            .app-modal-overlay:not(.app-modal-preserve-layout) { padding: 0.75rem !important; }
            .app-modal-overlay:not(.app-modal-preserve-layout) > .app-modal-panel {
                max-width: calc(100dvw - 1.5rem);
                max-height: calc(100dvh - 1.5rem) !important;
                border-radius: 1.25rem;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .app-modal-overlay,
            .app-modal-overlay > .app-modal-panel { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="bg-[#F8FAFC] font-sans text-gray-800 antialiased flex h-[100dvh] overflow-hidden selection:bg-hodRed selection:text-white">

    <div id="mobileOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 hidden transition-opacity duration-300 opacity-0 md:hidden"></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-hodBlue via-[#152750] to-[#0D1830] text-white flex flex-col shadow-2xl z-50 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out border-r border-white/10">
        
        <div class="h-20 flex items-center justify-center border-b border-white/10 relative overflow-hidden shrink-0">
            <div class="absolute inset-0 bg-white/5 backdrop-blur-md"></div>
            <img src="/assets/images/hod_logo.svg" alt="HOD Logo" class="h-10 relative z-10 brightness-0 invert drop-shadow-md" onerror="this.src='https://placehold.co/150x50?text=HOD+Logo'">
            <button id="closeSidebar" class="absolute right-4 md:hidden text-white/70 hover:text-white z-20">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <nav class="flex-1 px-3 py-6 space-y-1.5 overflow-y-auto custom-scrollbar">
            
    <p class="px-4 pb-2 text-[10px] font-bold text-blue-300/60 uppercase tracking-widest mt-2">Command Center</p>
    
    <?php if (userHasNavAccess($pastors_directors, [1])): ?>
    <a href="/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($isDashboard) ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
        <span class="font-medium text-sm">Dashboard</span>
    </a>
    <?php endif; ?>

    <a href="/modules/member_portal/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'member_portal') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        <span class="font-medium text-sm"><?= htmlspecialchars($_SESSION['first_name'] ?? 'Member') ?>'s Portal</span>
    </a>
    <a href="/modules/profile/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'profile') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
        <span class="font-medium text-sm">My Profile</span>
    </a>

    <!-- NEW REGIONS HUB LINK -->
    <a href="/modules/regions/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'regions') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        <span class="font-medium text-sm">Regional Hubs</span>
    </a>

    <?php if (userHasNavAccess($pastors, [1])): ?>
    <a href="/modules/idi/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'idi') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
        <span class="font-medium text-sm">Data Insights (IDI)</span>
    </a>
    <?php endif; ?>

    <p class="px-4 pt-5 pb-2 text-[10px] font-bold text-blue-300/60 uppercase tracking-widest">Growth & Retention</p>
    
    <?php if (userHasNavAccess($pastors, [1])): ?>
    <a href="/modules/congregation/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'congregation') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
        <span class="font-medium text-sm">Congregation</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 8])): // IDI and Reach
        $reach_badge = 0;
        try {
            require_once __DIR__ . '/reach_helpers.php';
            $reach_counts = reach_sidebar_counts($pdo, (int) ($_SESSION['user_id'] ?? 0));
            $reach_badge  = $reach_counts['unassigned_all'] + $reach_counts['my_overdue'];
        } catch (PDOException $e) {
            error_log('Reach sidebar badge: ' . $e->getMessage());
        }
    ?>
    <a href="/modules/reach/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'reach') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span class="font-medium text-sm">Reach (Evangelism)</span>
        <?php if ($reach_badge > 0): ?>
            <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center" title="Unassigned leads + your overdue follow-ups"><?= $reach_badge > 99 ? '99+' : (int) $reach_badge ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 2])): // IDI and Embrace ?>
    <a href="/modules/embrace/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'embrace') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
        <span class="font-medium text-sm">Embrace (First Timers)</span>
    </a>
    <?php endif; ?>

    <?php
    // Assimilation is not department-scoped: Super Admins, pastors, every
    // active Director/HOD, and anyone on the Assimilation team. The module
    // and its API gate on exactly the same rule — this only hides the link.
    $assim_nav = false;
    $assim_badge = 0;
    try {
        require_once __DIR__ . '/assimilation_helpers.php';
        $assim_uid     = (int) ($_SESSION['user_id'] ?? 0);
        $assim_manager = assim_is_manager($pdo, $assim_uid, $_SESSION['active_role'] ?? '');
        $assim_nav     = $assim_manager || assim_is_team_member($pdo, $assim_uid);
        if ($assim_nav) {
            $assim_counts = assim_sidebar_counts($pdo, $assim_uid);
            $assim_badge  = $assim_counts['my_overdue']
                + ($assim_manager ? $assim_counts['pool'] + $assim_counts['watchlist_untouched'] : 0);
        }
    } catch (Throwable $e) {
        error_log('Assimilation sidebar badge: ' . $e->getMessage());
    }
    ?>
    <?php if ($assim_nav): ?>
    <a href="/modules/assimilation/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'assimilation') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        <span class="font-medium text-sm">Assimilation</span>
        <?php if ($assim_badge > 0): ?>
            <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center" title="Your overdue follow-ups<?= $assim_manager ? ' + unclaimed people' : '' ?>"><?= $assim_badge > 99 ? '99+' : (int) $assim_badge ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 10])): // IDI and Charis ?>
    <a href="/modules/charis/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'charis') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
        <span class="font-medium text-sm">Charis (Welfare)</span>
    </a>
    <?php endif; ?>

    <a href="/modules/academy/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'academy') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 14l9-5-9-5-9 5 9 5z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"></path></svg>
        <span class="font-medium text-sm">HOD Academy</span>
    </a>
    
    <?php if (userHasNavAccess($pastors, [1, 3, 4, 7, 11])): // Wrapper for Specialized Units header ?>
    <p class="px-4 pt-5 pb-2 text-[10px] font-bold text-blue-300/60 uppercase tracking-widest">Specialized Units</p>
    
    <?php if (userHasNavAccess($pastors, [1, 11])): // IDI and Junior Church ?>
    <a href="/modules/junior_church/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'junior_church') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span class="font-medium text-sm">Junior Church</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 4])): // IDI and River of Life ?>
    <a href="/modules/river_of_life/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'river_of_life') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path></svg>
        <span class="font-medium text-sm">River of Life (Choir)</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 3])): // IDI and Envision ?>
    <a href="/modules/envision/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'envision') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
        <span class="font-medium text-sm">Envision (Media)</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 7])): // IDI and Zoe Intercessory ?>
    <a href="/modules/zoe/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'zoe') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
        <span class="font-medium text-sm">Zoe (Prayer)</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (userHasNavAccess(array_merge($pastors_directors, $sms_roles), [1, 3, 13])): // Wrapper for Core Operations header ?>
    <p class="px-4 pt-5 pb-2 text-[10px] font-bold text-blue-300/60 uppercase tracking-widest">Core Operations</p>
    
    <?php if (userHasNavAccess($pastors, [1])): // IDI ?>
    <a href="/modules/departments/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'departments') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        <span class="font-medium text-sm">Departments</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors, [1, 13])): // IDI and Camp David ?>
    <a href="/modules/tribes/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'tribes') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        <span class="font-medium text-sm">Tribes</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [1])): // IDI ?>
    <a href="/modules/events/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'events') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        <span class="font-medium text-sm">Events & Attendance</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($sms_roles, [])): // Same roles the SMS API accepts ?>
    <a href="/modules/sms_studio/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'sms_studio') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
        <span class="font-medium text-sm">SMS Studio</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [1, 3])): // IDI and Envision ?>
    <a href="/modules/assets/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'assets') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
        <span class="font-medium text-sm">Asset Management</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [1])): // IDI ?>
    <a href="/modules/pastoral/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'pastoral') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
        <span class="font-medium text-sm">Pastoral Desk</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [1])): // IDI ?>
    <a href="/modules/announcements/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'announcements') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
        <span class="font-medium text-sm">Announcements</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [1])): // IDI ?>
    <a href="/modules/testimonies/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'testimonies') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
        <span class="font-medium text-sm">Testimonies (Admin)</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (userHasNavAccess($pastors_directors, [])): // Super Admin, Pastors, Directors only ?>
    <p class="px-4 pt-5 pb-2 text-[10px] font-bold text-red-500/80 uppercase tracking-widest">Security & Stewardship</p>
    
    <a href="/modules/finance/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'finance') ?>">
        <svg class="w-5 h-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span class="font-bold text-sm text-red-700">Finance & Stewardship</span>
    </a>
    
    <?php if (userHasNavAccess(['Resident_Pastor','Assoc_Pastor','Director','HOD'], [])): ?>
    <a href="/modules/requisition/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'requisition') ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <span class="font-medium text-sm">Requisitions</span>
    </a>
    <?php endif; ?>

    <?php if ($is_super_admin): // Strict Super Admin Only ?>
    <a href="/modules/roles/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'roles') ?>">
        <svg class="w-5 h-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
        <span class="font-bold text-sm text-red-700">Role Management</span>
    </a>
    <?php endif; ?>

    <?php if (userHasNavAccess(['Resident_Pastor'], [])): // Super Admin + Resident Pastor ?>
    <a href="/modules/security/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 <?= getLinkStyle($currentModule == 'security') ?>">
        <svg class="w-5 h-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        <span class="font-bold text-sm text-red-700">Security Centre</span>
    </a>
    <?php endif; ?>
    
    <?php endif; ?>
</nav>
        
        <div class="p-4 border-t border-white/10 bg-black/10 shrink-0">
            <a href="/auth/logout.php" class="flex items-center justify-center gap-2 text-red-300 hover:bg-red-500/10 hover:text-white px-4 py-3 rounded-xl transition-all duration-300 text-sm font-semibold border border-transparent hover:border-red-500/30">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                Secure Sign Out
            </a>
        </div>
    </aside>

    <?php
    // ====================================================================
    // GLOBAL SEARCH INDEX (Ctrl/Cmd+K palette)
    // Mirrors the sidebar's RBAC gates exactly — every entry below is only
    // added when the equivalent sidebar link would have rendered. Tabs use
    // the shared #tab= deep-link convention handled by assets/js/tab-deeplink.js.
    // ====================================================================
    $gsItems = [];
    $gsAdd = static function ($id, $label, $url, $group, $icon, $keywords = [], $tabs = []) use (&$gsItems) {
        $gsItems[] = ['id' => $id, 'type' => 'module', 'label' => $label, 'url' => $url, 'group' => $group, 'icon' => $icon, 'keywords' => $keywords];
        foreach ($tabs as $tabId => $tabLabel) {
            $gsItems[] = [
                'id'       => $id . ':' . $tabId,
                'type'     => 'tab',
                'label'    => $label . ' › ' . $tabLabel,
                'url'      => $url . '#tab=' . rawurlencode($tabId),
                'group'    => $group,
                'icon'     => $icon,
                'keywords' => array_merge($keywords, array_map('strtolower', explode(' ', $tabLabel))),
            ];
        }
    };

    // --- Command Center ---
    if (userHasNavAccess($pastors_directors, [1])) {
        $gsAdd('dashboard', 'Dashboard', '/index.php', 'Command Center',
            'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
            ['home', 'workspace', 'overview', 'kpi']);
    }
    $gsAdd('member_portal', ($_SESSION['first_name'] ?? 'Member') . "'s Portal", '/modules/member_portal/index.php', 'Command Center',
        'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        ['member portal', 'my portal', 'home']);
    $gsAdd('profile', 'My Profile', '/modules/profile/index.php', 'Command Center',
        'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        ['account', 'settings', 'picture', 'password', 'details']);
    $gsAdd('regions', 'Regional Hubs', '/modules/regions/index.php', 'Command Center',
        'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z',
        ['regions', 'hubs', 'locations', 'broadcast'],
        ['hub' => 'My Region']);
    if (userHasNavAccess($pastors, [1])) {
        $gsAdd('idi', 'Data Insights (IDI)', '/modules/idi/index.php', 'Command Center',
            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
            ['idi', 'analytics', 'insights', 'data', 'reports', 'statistics']);
    }

    // --- Growth & Retention ---
    if (userHasNavAccess($pastors, [1])) {
        $gsAdd('congregation', 'Congregation', '/modules/congregation/index.php', 'Growth & Retention',
            'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
            ['members', 'people', 'database', 'roster', 'directory']);
    }
    if (userHasNavAccess($pastors, [1, 8])) {
        $gsAdd('reach', 'Reach (Evangelism)', '/modules/reach/index.php', 'Growth & Retention',
            'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            ['evangelism', 'outreach', 'souls', 'leads', 'campaigns'],
            ['campaigns' => 'Campaigns', 'followup' => 'Follow-Up', 'analytics' => 'Analytics', 'howto' => 'How to Use']);
    }
    if (userHasNavAccess($pastors, [1, 2])) {
        $gsAdd('embrace', 'Embrace (First Timers)', '/modules/embrace/index.php', 'Growth & Retention',
            'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
            ['first timers', 'visitors', 'welcome', 'guests']);
    }
    if (!empty($assim_nav)) {
        $gsAdd('assimilation', 'Assimilation', '/modules/assimilation/index.php', 'Growth & Retention',
            'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            ['new converts', 'follow up', 'retention', 'integration'],
            !empty($assim_manager)
                ? ['find' => 'Find People', 'followup' => 'Follow-Up', 'team' => 'Team', 'analytics' => 'Analytics', 'howto' => 'How to Use']
                : ['followup' => 'Follow-Up', 'team' => 'Team', 'howto' => 'How to Use']);
    }
    if (userHasNavAccess($pastors, [1, 10])) {
        $gsAdd('charis', 'Charis (Welfare)', '/modules/charis/index.php', 'Growth & Retention',
            'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
            ['welfare', 'care', 'benevolence', 'support'],
            ['welfare' => 'Welfare', 'events' => 'Event Planning', 'finance' => 'Finance', 'library' => 'Library', 'howto' => 'How To Use']);
    }
    $gsAdd('academy', 'HOD Academy', '/modules/academy/index.php', 'Growth & Retention',
        'M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z',
        ['school', 'lms', 'courses', 'classes', 'study', 'exams', 'learning'],
        ['student-dash' => 'My Noticeboard', 'student-study' => 'Study & Assignments', 'student-exam' => 'Examination Portal', 'student-result' => 'My Result Slip']);

    // --- Specialized Units ---
    if (userHasNavAccess($pastors, [1, 11])) {
        $gsAdd('junior_church', 'Junior Church', '/modules/junior_church/index.php', 'Specialized Units',
            'M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            ['kids', 'children', 'sunday school'],
            ['services' => 'Service Tracker', 'roster' => 'Children Roster', 'curriculum' => 'Curriculums']);
    }
    if (userHasNavAccess($pastors, [1, 4])) {
        $gsAdd('river_of_life', 'River of Life (Choir)', '/modules/river_of_life/index.php', 'Specialized Units',
            'M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3',
            ['choir', 'music', 'worship', 'songs'],
            ['kanban' => 'Roster Kanban', 'rehearsals' => 'Rehearsals', 'attendance' => 'Attendance']);
    }
    if (userHasNavAccess($pastors, [1, 3])) {
        $gsAdd('envision', 'Envision (Media)', '/modules/envision/index.php', 'Specialized Units',
            'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
            ['media', 'video', 'radio', 'sermons', 'broadcast'],
            ['roster' => 'Crew Roster', 'archive' => 'Sermon Archive', 'comments' => 'Moderation', 'config' => 'Broadcast Config']);
    }
    if (userHasNavAccess($pastors, [1, 7])) {
        $gsAdd('zoe', 'Zoe (Prayer)', '/modules/zoe/index.php', 'Specialized Units',
            'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
            ['prayer', 'intercessory', 'intercession'],
            ['requests' => 'Church Requests', 'sessions' => 'Prayer Sessions & Campaigns', 'roster' => 'Zoe Roster', 'war_room' => 'The War Room']);
    }

    // --- Core Operations ---
    if (userHasNavAccess($pastors, [1])) {
        $gsAdd('departments', 'Departments', '/modules/departments/index.php', 'Core Operations',
            'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
            ['units', 'teams', 'workforce']);
    }
    if (userHasNavAccess($pastors, [1, 13])) {
        $gsAdd('tribes', 'Tribes', '/modules/tribes/index.php', 'Core Operations',
            'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            ['camp david', 'cells', 'groups'],
            ['global' => 'Global Dashboard', 'mytribe' => 'My Tribe', 'meetings' => 'Meetings & Rhema', 'roster' => 'Duty Roster']);
    }
    if (userHasNavAccess($pastors_directors, [1])) {
        $gsAdd('events', 'Events & Attendance', '/modules/events/index.php', 'Core Operations',
            'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            ['calendar', 'services', 'check-in', 'checkin', 'attendance']);
    }
    if (userHasNavAccess($sms_roles, [])) {
        $gsAdd('sms_studio', 'SMS Studio', '/modules/sms_studio/index.php', 'Core Operations',
            'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
            ['text', 'messages', 'bulk sms', 'broadcast'],
            ['campaigns' => 'Campaigns', 'history' => 'History']);
    }
    if (userHasNavAccess($pastors_directors, [1, 3])) {
        $gsAdd('assets', 'Asset Management', '/modules/assets/index.php', 'Core Operations',
            'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            ['inventory', 'equipment', 'gear'],
            ['inventory' => 'Master Inventory', 'logs' => 'Activity Logs']);
    }
    if (userHasNavAccess($pastors_directors, [1])) {
        $gsAdd('pastoral', 'Pastoral Desk', '/modules/pastoral/index.php', 'Core Operations',
            'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
            ['questions', 'feedback'],
            ['impressions' => 'Impressions', 'qa' => 'Pastoral Q&A', 'suggestions' => 'Suggestion Box']);
        $gsAdd('announcements', 'Announcements', '/modules/announcements/index.php', 'Core Operations',
            'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z',
            ['news', 'notices', 'bulletin']);
        $gsAdd('testimonies', 'Testimonies (Admin)', '/modules/testimonies/index.php', 'Core Operations',
            'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
            ['stories', 'praise reports']);
    }

    // --- Security & Stewardship ---
    if (userHasNavAccess($pastors_directors, [])) {
        $gsAdd('finance', 'Finance & Stewardship', '/modules/finance/index.php', 'Security & Stewardship',
            'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            ['money', 'giving', 'tithe', 'offering', 'ledger'],
            ['dashboard' => 'IDI Overview', 'transactions' => 'Master Ledger', 'config' => 'System Configuration']);
        if (userHasNavAccess(['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'], [])) {
            $gsAdd('requisition', 'Requisitions', '/modules/requisition/index.php', 'Security & Stewardship',
                'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                ['purchase', 'spend', 'approvals', 'expenses'],
                ['requisitions' => 'Requisitions', 'analytics' => 'Analytics']);
        }
        if ($is_super_admin) {
            $gsAdd('roles', 'Role Management', '/modules/roles/index.php', 'Security & Stewardship',
                'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                ['permissions', 'rbac', 'security', 'access'],
                ['tab-roster' => 'Master Roster', 'tab-audit' => 'Audit Trail']);
        }
        // Mirrors the sidebar Security Centre link: Super Admin + Resident Pastor.
        if (userHasNavAccess(['Resident_Pastor'], [])) {
            $gsAdd('security', 'Security Centre', '/modules/security/index.php', 'Security & Stewardship',
                'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                ['suspend', 'revoke', 'reset password', 'sign-in email', 'lockout', 'kill sessions', 'login attempts', 'audit'],
                ['accounts' => 'Accounts', 'sessions' => 'Live Sessions', 'logins' => 'Login Activity', 'audit' => 'Audit Trail']);
        }
    }

    // --- Quick actions ---
    $gsItems[] = [
        'id' => 'action:logout', 'type' => 'action', 'label' => 'Secure Sign Out',
        'url' => '/auth/logout.php', 'group' => 'Actions',
        'icon' => 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
        'keywords' => ['logout', 'log out', 'sign out', 'exit'],
    ];
    ?>

    <div class="flex-1 flex flex-col h-[100dvh] overflow-hidden relative bg-transparent">
        
        <header class="bg-white/80 backdrop-blur-xl border-b border-gray-200/60 sticky top-0 z-30 shadow-sm">
            <div class="flex items-center justify-between px-4 sm:px-6 h-20">
                
                <div class="flex items-center gap-4">
                    <button id="mobileMenuBtn" class="md:hidden text-gray-600 hover:text-hodBlue hover:bg-blue-50 p-2 rounded-lg transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-display font-bold text-gray-900 tracking-tight hidden sm:block">
                        <?= $isDashboard ? 'Workspace' : htmlspecialchars($moduleTitle) ?>
                    </h1>
                </div>
                
                <div class="flex items-center gap-3 sm:gap-5">
                    
                    <!-- Global search trigger: pill with shortcut hint on desktop, icon on mobile -->
                    <button type="button" data-gs-open class="hidden md:flex items-center gap-2.5 w-56 lg:w-64 px-3.5 py-2 rounded-xl border border-gray-200 bg-gray-50/80 text-gray-400 hover:text-gray-600 hover:border-gray-300 hover:bg-white transition-all text-sm focus:outline-none focus:ring-2 focus:ring-blue-100" aria-label="Search navigation (Ctrl+K)">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <span class="flex-1 text-left font-medium">Search…</span>
                        <kbd data-gs-kbd class="text-[10px] font-bold text-gray-400 bg-white border border-gray-200 rounded-md px-1.5 py-0.5 shadow-sm tracking-wide">Ctrl K</kbd>
                    </button>
                    <button type="button" data-gs-open class="md:hidden text-gray-400 hover:text-hodBlue p-2 rounded-xl hover:bg-blue-50 transition-all focus:outline-none focus:ring-2 focus:ring-blue-100" aria-label="Search navigation">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </button>

                    <div class="relative" id="notificationDropdownContainer">
                        <button onclick="toggleNotifications()" class="text-gray-400 hover:text-hodBlue relative p-2 rounded-xl hover:bg-blue-50 transition-all focus:outline-none focus:ring-2 focus:ring-blue-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                            <!-- Lively animated pulse for unread state -->
                            <span id="notifBadge" class="absolute top-1.5 right-1.5 w-2.5 h-2.5 bg-hodRed ring-2 ring-white rounded-full animate-pulse hidden"></span>
                        </button>

                        <!-- Mobile-first positioning: Fixed full width on mobile, absolute dropdown on sm+ -->
                        <div id="notificationPanel" class="fixed left-4 right-4 top-20 sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-3 sm:w-[400px] bg-white rounded-3xl shadow-[0_20px_60px_-15px_rgba(0,0,0,0.3)] border border-gray-100 hidden opacity-0 transform scale-95 origin-top sm:origin-top-right transition-all duration-300 z-50 overflow-hidden flex flex-col max-h-[80vh] sm:max-h-[85vh]">
                            
                            <!-- Vibrant gradient header -->
                            <div class="p-4 sm:p-5 flex justify-between items-center bg-gradient-to-r from-hodBlue to-blue-600 relative overflow-hidden shrink-0">
                                <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
                                <h3 class="font-display font-bold text-white flex items-center gap-2 relative z-10 text-base sm:text-lg tracking-tight">
                                    Notifications 
                                    <span id="notifCountText" class="bg-white/20 text-white border border-white/20 py-0.5 px-2.5 rounded-full text-[10px] font-bold hidden backdrop-blur-md shadow-sm">0 New</span>
                                </h3>
                                <button onclick="markAllNotificationsRead()" class="text-[10px] font-bold text-blue-100 hover:text-white uppercase tracking-wider relative z-10 transition-colors drop-shadow-sm">Mark all read</button>
                            </div>
                            
                            <div id="notificationList" class="flex-1 overflow-y-auto custom-scrollbar divide-y divide-gray-50 bg-white overscroll-contain">
                                <div class="p-10 text-center text-gray-400">
                                    <svg class="animate-spin h-8 w-8 mx-auto text-blue-500 mb-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Syncing Data...</p>
                                </div>
                            </div>
                            
                            <!-- Mobile bottom safe-area padding -->
                            <div class="h-2 bg-gray-50 w-full shrink-0 sm:hidden"></div>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-3 pl-3 sm:pl-5 border-l border-gray-200 ml-2">
                    <div class="text-right hidden sm:block">
                        <p class="text-sm font-bold text-gray-900 leading-tight"><?= $firstName ?></p>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold"><?= str_replace('_', ' ', $activeRole) ?></p>
                    </div>
                    <a href="/modules/profile/index.php" class="h-9 w-9 sm:h-10 sm:w-10 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 flex items-center justify-center text-hodBlue font-bold shadow-sm cursor-pointer hover:shadow-md transition-shadow overflow-hidden shrink-0 block">
                        <?php if ($profilePicPath): ?>
                            <img src="<?= $profilePicPath ?>" alt="Profile" class="w-full h-full object-cover nav-avatar-img">
                        <?php else: ?>
                            <?= substr($firstName, 0, 1) ?>
                        <?php endif; ?>
                    </a>
                </div>

                </div>
            </div>
        </header>

        <!-- ============================================================ -->
        <!-- GLOBAL COMMAND PALETTE (Ctrl/Cmd+K) — logic in global-search.js -->
        <!-- data-modal-ignore keeps modal-manager.js from re-styling it.   -->
        <!-- ============================================================ -->
        <div id="gsOverlay" data-modal-ignore class="hidden opacity-0 fixed inset-0 z-[70] bg-slate-900/60 backdrop-blur-sm transition-opacity duration-200" role="dialog" aria-modal="true" aria-label="Search navigation">
            <div class="h-full w-full flex items-start justify-center px-3 sm:px-4 pt-[8vh] sm:pt-[13vh] pb-8">
                <div id="gsPanel" class="w-full max-w-xl bg-white rounded-2xl shadow-[0_25px_80px_-15px_rgba(0,0,0,0.5)] border border-gray-200/70 overflow-hidden transform scale-95 -translate-y-2 transition-all duration-200 flex flex-col max-h-full">
                    <div class="flex items-center gap-3 px-4 sm:px-5 border-b border-gray-100 shrink-0">
                        <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input id="gsInput" type="text" autocomplete="off" spellcheck="false" placeholder="Search modules, tabs, people, events…" class="flex-1 py-4 text-[15px] font-medium text-gray-900 placeholder-gray-400 bg-transparent outline-none border-0 focus:ring-0" aria-label="Search">
                        <svg id="gsSpinner" class="hidden animate-spin w-4 h-4 text-hodBlue shrink-0" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <kbd class="hidden sm:block text-[10px] font-bold text-gray-400 bg-gray-50 border border-gray-200 rounded-md px-1.5 py-0.5 shrink-0">Esc</kbd>
                    </div>
                    <div id="gsResults" class="flex-1 overflow-y-auto custom-scrollbar overscroll-contain pb-2 min-h-[120px] max-h-[55vh]" role="listbox" aria-label="Search results"></div>
                    <div class="hidden sm:flex items-center gap-4 px-5 py-2.5 border-t border-gray-100 bg-gray-50/70 text-[10px] font-bold text-gray-400 uppercase tracking-wider shrink-0">
                        <span class="flex items-center gap-1.5"><kbd class="bg-white border border-gray-200 rounded px-1 py-0.5 normal-case">↑↓</kbd> Navigate</span>
                        <span class="flex items-center gap-1.5"><kbd class="bg-white border border-gray-200 rounded px-1 py-0.5 normal-case">↵</kbd> Open</span>
                        <span class="flex items-center gap-1.5"><kbd class="bg-white border border-gray-200 rounded px-1 py-0.5 normal-case">Esc</kbd> Close</span>
                        <span class="ml-auto normal-case tracking-normal font-semibold">HOD Lekki · Quick Nav</span>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // RBAC-filtered navigation index for the command palette (built server-side).
            window.HOD_SEARCH_INDEX = <?= json_encode($gsItems, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        </script>

        <script>
            let isNotifOpen = false;

            function toggleNotifications() {
                const panel = document.getElementById('notificationPanel');
                isNotifOpen = !isNotifOpen;
                
                if (isNotifOpen) {
                    panel.classList.remove('hidden');
                    setTimeout(() => {
                        panel.classList.remove('opacity-0', 'scale-95');
                    }, 10);
                    fetchNotifications(); // Refresh on open
                } else {
                    panel.classList.add('opacity-0', 'scale-95');
                    setTimeout(() => { panel.classList.add('hidden'); }, 300);
                }
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                const container = document.getElementById('notificationDropdownContainer');
                if (isNotifOpen && !container.contains(e.target)) {
                    toggleNotifications();
                }
            });

            function fetchNotifications() {
                $.post('/api/notifications_api.php', { action: 'fetch' }, function(res) {
                    if (res.status === 'success') {
                        const count = res.count;
                        
                        if (count > 0) {
                            $('#notifBadge').removeClass('hidden');
                            $('#notifCountText').text(`${count} New`).removeClass('hidden');
                        } else {
                            $('#notifBadge').addClass('hidden');
                            $('#notifCountText').addClass('hidden');
                        }

                        let html = '';
                        if (count === 0) {
                            html = `
                            <div class="p-12 text-center text-gray-400 flex flex-col items-center justify-center">
                                <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-4">
                                    <svg class="w-8 h-8 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                                </div>
                                <p class="text-sm font-bold text-gray-600">You're all caught up!</p>
                                <p class="text-[10px] font-medium mt-1">No new activities requiring your attention.</p>
                            </div>`;
                        } else {
                            res.data.forEach(n => {
                            // If it has a link, redirect. If not, expand inline.
                            const linkAction = n.link_url 
                                ? `onclick="readAndRedirect(${n.id}, '${n.link_url}')"` 
                                : `onclick="expandNotification(this, ${n.id})"`;
                                
                            const actionHint = n.link_url 
                                ? `<p class="text-[9px] font-bold text-hodRed mt-2 opacity-0 group-hover:opacity-100 transition-opacity">Click to view details</p>`
                                : `<p class="text-[9px] font-bold text-blue-500 mt-2 opacity-0 group-hover:opacity-100 transition-opacity expand-text">Click to expand</p>`;
                            
                            html += `
                            <div class="p-4 sm:p-5 hover:bg-gradient-to-r hover:from-blue-50/80 hover:to-transparent transition-all cursor-pointer group border-l-4 border-transparent hover:border-blue-500 relative overflow-hidden" ${linkAction}>
                                <div class="absolute inset-0 bg-gradient-to-r from-blue-500/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"></div>
                                <div class="relative z-10">
                                    <div class="flex justify-between items-start mb-1.5 gap-3">
                                        <h4 class="text-sm font-bold text-gray-900 group-hover:text-hodBlue transition-colors leading-tight">${n.title}</h4>
                                        <span class="text-[10px] font-bold text-gray-500 whitespace-nowrap shrink-0 bg-gray-100/80 px-2 py-0.5 rounded-md border border-gray-200/50">${n.time_ago}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 line-clamp-2 leading-relaxed group-hover:text-gray-700 transition-colors notif-message">${n.message}</p>
                                    ${actionHint}
                                </div>
                            </div>`;
                        });
                        }
                        $('#notificationList').html(html);
                    }
                }, 'json');
            }
            
            function expandNotification(element, id) {
                const msg = element.querySelector('.notif-message');
                const hint = element.querySelector('.expand-text');
                const isClamped = msg.classList.contains('line-clamp-2');
                
                if (isClamped) {
                    // Expand the text vertically
                    msg.classList.remove('line-clamp-2');
                    if (hint) hint.innerText = "Show less";
                    element.classList.add('bg-blue-50/30'); // Keep highlighted while expanded
                    
                    // Silently mark as read in the background (only run this once per click)
                    if (!element.dataset.read) {
                        element.dataset.read = 'true';
                        $.post('/api/notifications_api.php', { action: 'mark_read', id: id }, function(res) {
                            if (res.status === 'success') {
                                // Manually decrement badge count locally so the UI doesn't jump
                                let currentCountText = $('#notifCountText').text();
                                let currentCount = parseInt(currentCountText) || 0;
                                
                                if (currentCount > 1) {
                                    $('#notifCountText').text(`${currentCount - 1} New`);
                                } else {
                                    $('#notifBadge').addClass('hidden');
                                    $('#notifCountText').addClass('hidden');
                                }
                            }
                        });
                    }
                } else {
                    // Collapse back to normal
                    msg.classList.add('line-clamp-2');
                    if (hint) hint.innerText = "Click to expand";
                    element.classList.remove('bg-blue-50/30');
                }
            }

            function readAndRedirect(id, url) {
                $.post('/api/notifications_api.php', { action: 'mark_read', id: id }, function() {
                    window.location.href = url; // Redirect after marking read
                });
            }

            function markAsRead(id) {
                $.post('/api/notifications_api.php', { action: 'mark_read', id: id }, function(res) {
                    if(res.status === 'success') {
                        fetchNotifications(); // Refresh list
                        
                        Toastify({
                            text: "Marked as read", 
                            duration: 2000, 
                            gravity: "top", 
                            position: "center", 
                            style: { 
                                background: "#3B82F6", 
                                color: "#ffffff", 
                                borderRadius: "10px", 
                                fontWeight: "bold",
                                boxShadow: "0 4px 12px rgba(59, 130, 246, 0.25)" 
                            }
                        }).showToast();
                    }
                });
            }

            function markAllNotificationsRead() {
                const btn = document.querySelector('button[onclick="markAllNotificationsRead()"]');
                const origText = btn ? btn.innerText : 'Mark all read';
                if(btn) btn.innerText = 'Clearing...';

                $.post('/api/notifications_api.php', { action: 'mark_all_read' }, function(res) {
                    if(btn) btn.innerText = origText;
                    
                    if(res.status === 'success') {
                        fetchNotifications();
                        
                        // Fire the success toast
                        Toastify({
                            text: "All notifications cleared", 
                            duration: 3000, 
                            gravity: "top", 
                            position: "center", 
                            style: { 
                                background: "#10B981", 
                                color: "#ffffff", 
                                borderRadius: "10px", 
                                fontWeight: "bold", 
                                boxShadow: "0 10px 25px rgba(16, 185, 129, 0.3)" 
                            }
                        }).showToast();
                        
                        // Auto-close the panel for a seamless UX since the list is now empty
                        if (isNotifOpen) {
                            toggleNotifications();
                        }
                    }
                });
            }

            // Auto-fetch notifications on load
            $(document).ready(function() {
                fetchNotifications();

                // Auto-scroll sidebar to lock the active module in view
                const activeLink = document.querySelector('aside#sidebar nav a.border-white');
                if (activeLink) {
                    // Smoothly scrolls the sidebar so the active link is dead center
                    activeLink.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        </script>

        <!-- Keep this scroll container untransformed: transformed ancestors break viewport-fixed dialogs. -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10">